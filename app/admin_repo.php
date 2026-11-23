<?php
/**
 * Administração total (V18, etapa 1) — ações exclusivas do ADMIN real (sem "Ver como"):
 *   • situação do curso: pausar, suspender, cancelar, arquivar, retomar, excluir (lixeira), restaurar,
 *     excluir definitivamente (arquivos movidos para storage/backups);
 *   • mover forçado (ignora transições, documentos pendentes e datas obrigatórias);
 *   • trocar responsável; editar datas do curso;
 *   • excluir apontamento, vídeo e versão de vídeo;
 *   • excluir usuário (anonimizado) com transferência dos cursos.
 * Regra: toda ação exige MOTIVO (mín. 10 caracteres) e é auditada com a ação 'acao_admin'.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/curso_repo.php';
require_once __DIR__ . '/config_repo.php'; // V20: parâmetros do sistema

const CURSO_SITUACOES = [
  'ATIVO'     => ['label' => 'Ativo',      'badge' => 'bg-success',           'desc' => 'Em andamento normal no fluxo.'],
  'PAUSADO'   => ['label' => 'Pausado',    'badge' => 'bg-warning text-dark', 'desc' => 'Fora do fluxo temporariamente; sem alertas de prazo; professores e MB não alteram.'],
  'SUSPENSO'  => ['label' => 'Suspenso',   'badge' => 'bg-danger',            'desc' => 'Bloqueado por decisão administrativa até liberação.'],
  'CANCELADO' => ['label' => 'Cancelado',  'badge' => 'bg-dark border',       'desc' => 'Encerrado sem publicação; histórico preservado.'],
  'ARQUIVADO' => ['label' => 'Arquivado',  'badge' => 'bg-secondary',         'desc' => 'Fora das listas do dia a dia; continua nos relatórios.'],
  'EXCLUIDO'  => ['label' => 'Na lixeira', 'badge' => 'bg-danger',            'desc' => 'Excluído; pode ser restaurado ou apagado definitivamente.'],
];

/** As colunas da V18 existem? (cache por requisição) */
function admin_v18(): bool {
  static $ok = null;
  if ($ok === null) { try { db()->query("SELECT situacao FROM tb_cursos LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; } }
  return $ok;
}

/** Garante ADMIN real (não vale "Ver como"). */
function admin_exigir(array $user): void {
  if (!is_admin_real()) { http_response_code(403); throw new Exception('Ação exclusiva do administrador.'); }
  if (!admin_v18()) throw new Exception('Execute database/upgrade_v18.sql para habilitar as ações administrativas.');
}

function admin_motivo(string $m): string {
  $m = trim(preg_replace('/\s+/u', ' ', $m));
  if (mb_strlen($m) < 10) throw new Exception('Informe o motivo da ação (mínimo de 10 caracteres). Ele fica registrado na auditoria.');
  return mb_substr($m, 0, 500);
}

function admin_audit(string $oque, string $entidade, ?int $id, ?array $antes, array $depois, string $motivo, array $user): void {
  $depois['acao'] = $oque; $depois['motivo'] = $motivo;
  audit_log('acao_admin', $entidade, $id, $antes, $depois, (int)$user['id_user']);
}

function curso_situacao(array $curso): string { return $curso['situacao'] ?? 'ATIVO'; }
function curso_situacao_badge(array $curso): string {
  $s = curso_situacao($curso); $d = CURSO_SITUACOES[$s] ?? CURSO_SITUACOES['ATIVO'];
  return '<span class="badge ' . $d['badge'] . '" title="' . htmlspecialchars($d['desc']) . '">' . $d['label'] . '</span>';
}

/**
 * Curso fora de ATIVO: professores e MB não fazem alterações; o admin sempre pode.
 * Retorna a mensagem de bloqueio ou null.
 */
function curso_bloqueio(array $curso, array $user): ?string {
  $s = curso_situacao($curso);
  if ($s === 'ATIVO' || is_admin_real()) return null;
  return 'Este curso está ' . mb_strtolower(CURSO_SITUACOES[$s]['label']) . ' pelo administrador' . (!empty($curso['situacao_motivo']) ? ' (' . $curso['situacao_motivo'] . ')' : '') . '. Nenhuma alteração é permitida até a liberação.';
}

/** Muda a situação do curso (pausar, suspender, cancelar, arquivar, retomar, lixeira, restaurar). */
function curso_situacao_alterar(int $idCurso, string $nova, string $motivo, array $user): void {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  if (!isset(CURSO_SITUACOES[$nova])) throw new Exception('Situação inválida.');
  $c = curso_get($idCurso); if (!$c) throw new Exception('Curso não encontrado.');
  $atual = curso_situacao($c);
  if ($atual === $nova) throw new Exception('O curso já está nesta situação.');
  $statusAnterior = $c['status_anterior'] ?? null;
  if ($atual === 'ATIVO' && $nova !== 'ATIVO') $statusAnterior = $c['status_atual'];
  db()->prepare("UPDATE tb_cursos SET situacao=?, situacao_motivo=?, situacao_em=NOW(), situacao_por=?, status_anterior=? WHERE id_curso=?")
      ->execute([$nova, $motivo, (int)$user['id_user'], $statusAnterior, $idCurso]);
  db()->prepare("INSERT INTO tb_curso_status_history (id_curso, status_de, status_para, id_user, observacao) VALUES (?,?,?,?,?)")
      ->execute([$idCurso, $c['status_atual'], $c['status_atual'], (int)$user['id_user'], 'Situação: ' . CURSO_SITUACOES[$atual]['label'] . ' → ' . CURSO_SITUACOES[$nova]['label'] . '. Motivo: ' . $motivo]);
  admin_audit('curso_situacao', 'curso', $idCurso, ['situacao' => $atual], ['situacao' => $nova, 'curso' => $c['nome_curso']], $motivo, $user);
  // avisa os professores do curso (e a TI) quando sai de ATIVO ou volta
  try {
    require_once __DIR__ . '/notify.php';
    $link = app_base_url() . '/curso_detalhe.php?id=' . $idCurso;
    $corpo = "<p>O curso <b>" . htmlspecialchars($c['nome_curso']) . "</b> passou para a situação <b>" . CURSO_SITUACOES[$nova]['label'] . "</b> por decisão do administrador.</p><p><b>Motivo:</b> " . htmlspecialchars($motivo) . "</p>";
    if ($nova !== 'EXCLUIDO') foreach (notify_professores_curso($c) as $p) notify_queue($p['email'], $p['nome'], "[AutoriaSCS] Curso " . mb_strtolower(CURSO_SITUACOES[$nova]['label']) . ": {$c['nome_curso']}", mail_template('Situação do curso alterada', $corpo, $link, 'Abrir o curso'), true);
  } catch (Throwable $e) { /* e-mail é acessório */ }
}

/** Exclusão definitiva: só para cursos na lixeira. Arquivos vão para storage/backups/curso-<id>-<data>/. */
function curso_excluir_definitivo(int $idCurso, string $motivo, array $user): array {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  $c = curso_get($idCurso); if (!$c) throw new Exception('Curso não encontrado.');
  if (curso_situacao($c) !== 'EXCLUIDO') throw new Exception('Para apagar definitivamente, o curso precisa estar na lixeira.');
  $base = realpath(__DIR__ . '/../storage');
  $bk = "$base/backups/curso-{$idCurso}-" . date('Ymd-His');
  @mkdir($bk, 0755, true);
  $movidos = [];
  foreach (['cursos', 'videos'] as $p) { $d = "$base/$p/$idCurso"; if (is_dir($d) && @rename($d, "$bk/$p")) $movidos[] = $p; }
  // snapshot dos dados do curso (JSON) junto do backup
  $snap = ['curso' => $c];
  foreach (['tb_curso_files', 'tb_curso_apontamentos', 'tb_curso_status_history', 'tb_videos', 'tb_curso_professores', 'tb_curso_links', 'tb_curso_dispensas'] as $t) {
    try { $st = db()->prepare("SELECT * FROM `$t` WHERE id_curso=?"); $st->execute([$idCurso]); $snap[$t] = $st->fetchAll(); } catch (Throwable $e) {}
  }
  @file_put_contents("$bk/dados.json", json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  db()->prepare("DELETE FROM tb_cursos WHERE id_curso=?")->execute([$idCurso]); // FKs em cascata
  admin_audit('curso_excluido_definitivo', 'curso', $idCurso, ['curso' => $c['nome_curso'], 'professor' => $c['professor_nome']], ['backup' => $bk, 'pastas_movidas' => $movidos], $motivo, $user);
  return ['backup' => $bk, 'movidos' => $movidos];
}

/** Mover forçado: qualquer status, ignorando documentos pendentes e datas obrigatórias. */
function curso_mover_forcado(int $idCurso, string $to, string $motivo, array $user): void {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  $c = curso_get($idCurso); if (!$c) throw new Exception('Curso não encontrado.');
  // datas/carga exigidas por alguns status: usa as já registradas (ou hoje) para não bloquear o admin
  $dataPub = $c['publication_due_date'] ?: date('Y-m-d');
  $carga = (int)$c['carga_horaria'] ?: 10;
  curso_transition($idCurso, $user, $to, 'Movimentação administrativa (forçada). Motivo: ' . $motivo, $dataPub, $carga, true);
  admin_audit('curso_mover_forcado', 'curso', $idCurso, ['status' => $c['status_atual']], ['status' => $to, 'curso' => $c['nome_curso']], $motivo, $user);
}

/** Troca o responsável; o anterior vira coautor (mantém acesso). */
function curso_trocar_responsavel(int $idCurso, int $novoProf, string $motivo, array $user, bool $anteriorViraCoautor = true): void {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  $c = curso_get($idCurso); if (!$c) throw new Exception('Curso não encontrado.');
  $st = db()->prepare("SELECT id_user, nome, email FROM tb_users WHERE id_user=? AND ativo=1"); $st->execute([$novoProf]); $novo = $st->fetch();
  if (!$novo) throw new Exception('Novo responsável inválido ou inativo.');
  if ((int)$c['id_professor'] === $novoProf) throw new Exception('Este usuário já é o responsável.');
  $antigo = (int)$c['id_professor'];
  db()->beginTransaction();
  try {
    db()->prepare("UPDATE tb_cursos SET id_professor=? WHERE id_curso=?")->execute([$novoProf, $idCurso]);
    if (curso_professores_disponivel()) {
      db()->prepare("DELETE FROM tb_curso_professores WHERE id_curso=? AND id_usuario IN (?,?)")->execute([$idCurso, $antigo, $novoProf]);
      db()->prepare("INSERT INTO tb_curso_professores (id_curso, id_usuario, tipo) VALUES (?,?,'RESPONSAVEL')")->execute([$idCurso, $novoProf]);
      if ($anteriorViraCoautor) db()->prepare("INSERT IGNORE INTO tb_curso_professores (id_curso, id_usuario, tipo) VALUES (?,?,'COAUTOR')")->execute([$idCurso, $antigo]);
    }
    db()->commit();
  } catch (Throwable $e) { db()->rollBack(); throw $e; }
  admin_audit('curso_trocar_responsavel', 'curso', $idCurso, ['id_professor' => $antigo, 'nome' => $c['professor_nome']], ['id_professor' => $novoProf, 'nome' => $novo['nome'], 'curso' => $c['nome_curso']], $motivo, $user);
  try {
    require_once __DIR__ . '/notify.php';
    $link = app_base_url() . '/curso_detalhe.php?id=' . $idCurso;
    $corpo = "<p>O administrador definiu <b>" . htmlspecialchars($novo['nome']) . "</b> como novo(a) responsável pelo curso <b>" . htmlspecialchars($c['nome_curso']) . "</b>" . ($anteriorViraCoautor ? ", e <b>" . htmlspecialchars($c['professor_nome']) . "</b> passa a coautor(a)." : ".") . "</p><p><b>Motivo:</b> " . htmlspecialchars($motivo) . "</p>";
    notify_queue($novo['email'], $novo['nome'], "[AutoriaSCS] Você é o(a) novo(a) responsável: {$c['nome_curso']}", mail_template('Responsável pelo curso alterado', $corpo, $link, 'Abrir o curso'), true);
    notify_queue($c['professor_email'], $c['professor_nome'], "[AutoriaSCS] Responsável alterado: {$c['nome_curso']}", mail_template('Responsável pelo curso alterado', $corpo, $link, 'Abrir o curso'), true);
  } catch (Throwable $e) {}
}

/** Edita datas do curso (apenas as informadas). */
function curso_editar_datas(int $idCurso, array $datas, string $motivo, array $user): void {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  $c = curso_get($idCurso); if (!$c) throw new Exception('Curso não encontrado.');
  $campos = ['data_prevista_inicio' => 'date', 'data_prevista_entrega_final' => 'date', 'publication_due_date' => 'date', 'projeto_aprovado_em' => 'datetime', 'validated_at' => 'datetime', 'inserted_at' => 'datetime'];
  $set = []; $vals = []; $antes = []; $depois = [];
  foreach ($campos as $k => $tipo) {
    if (!array_key_exists($k, $datas)) continue;
    $v = trim((string)$datas[$k]);
    if ($v === '') { $set[] = "$k=NULL"; $antes[$k] = $c[$k]; $depois[$k] = null; continue; }
    $fmt = $tipo === 'date' ? 'Y-m-d' : 'Y-m-d H:i';
    $dt = DateTime::createFromFormat($fmt, $v) ?: DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt) throw new Exception("Data inválida em {$k}.");
    $set[] = "$k=?"; $vals[] = $dt->format($tipo === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s'); $antes[$k] = $c[$k]; $depois[$k] = end($vals);
  }
  if (!$set) throw new Exception('Nenhuma data informada.');
  $vals[] = $idCurso;
  db()->prepare("UPDATE tb_cursos SET " . implode(', ', $set) . " WHERE id_curso=?")->execute($vals);
  admin_audit('curso_editar_datas', 'curso', $idCurso, $antes, $depois + ['curso' => $c['nome_curso']], $motivo, $user);
}

/** Exclui um apontamento (histórico e manifestações em cascata). */
function apontamento_excluir(int $idAp, string $motivo, array $user): int {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  $st = db()->prepare("SELECT * FROM tb_curso_apontamentos WHERE id_apontamento=?"); $st->execute([$idAp]); $a = $st->fetch();
  if (!$a) throw new Exception('Apontamento não encontrado.');
  db()->prepare("DELETE FROM tb_curso_apontamentos WHERE id_apontamento=?")->execute([$idAp]);
  admin_audit('apontamento_excluido', 'curso', (int)$a['id_curso'], ['apontamento' => $a], [], $motivo, $user);
  return (int)$a['id_curso'];
}

/** Exclui um vídeo com todas as versões, marcações e arquivos. */
function video_excluir(int $idVideo, string $motivo, array $user): int {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  require_once __DIR__ . '/video_repo.php';
  $v = video_get($idVideo); if (!$v) throw new Exception('Vídeo não encontrado.');
  $dir = video_storage_dir((int)$v['id_curso']);
  foreach (video_versoes($idVideo) as $ver) { if (!empty($ver['stored_name'])) @unlink($dir . '/' . $ver['stored_name']); foreach (video_marcacoes((int)$ver['id_versao']) as $m) if (!empty($m['captura'])) @unlink($dir . '/capturas/' . $m['captura']); }
  db()->prepare("DELETE FROM tb_videos WHERE id_video=?")->execute([$idVideo]);
  admin_audit('video_excluido', 'curso', (int)$v['id_curso'], ['video' => $v['titulo'], 'modulo' => (int)$v['modulo']], [], $motivo, $user);
  return (int)$v['id_curso'];
}

/** Exclui uma versão de vídeo (arquivo, marcações); recalcula o status do vídeo. */
function video_versao_excluir(int $idVersao, string $motivo, array $user): int {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  require_once __DIR__ . '/video_repo.php';
  $ver = video_versao_get($idVersao); if (!$ver) throw new Exception('Versão não encontrada.');
  $v = video_get((int)$ver['id_video']);
  $dir = video_storage_dir((int)$v['id_curso']);
  if (!empty($ver['stored_name'])) @unlink($dir . '/' . $ver['stored_name']);
  foreach (video_marcacoes($idVersao) as $m) if (!empty($m['captura'])) @unlink($dir . '/capturas/' . $m['captura']);
  db()->prepare("DELETE FROM tb_video_versoes WHERE id_versao=?")->execute([$idVersao]);
  try { video_atualiza_status((int)$ver['id_video']); } catch (Throwable $e) {}
  admin_audit('video_versao_excluida', 'curso', (int)$v['id_curso'], ['video' => $v['titulo'], 'versao' => (int)$ver['numero']], [], $motivo, $user);
  return (int)$ver['id_video'];
}

/** Transfere todos os cursos (responsável) de um usuário para outro; coautorias são mantidas. */
function usuario_transferir_cursos(int $de, int $para, string $motivo, array $user): int {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  if ($de === $para) throw new Exception('Origem e destino são o mesmo usuário.');
  $st = db()->prepare("SELECT id_curso FROM tb_cursos WHERE id_professor=?"); $st->execute([$de]);
  $n = 0;
  foreach ($st->fetchAll() as $r) { curso_trocar_responsavel((int)$r['id_curso'], $para, $motivo, $user, false); $n++; }
  admin_audit('usuario_transferir_cursos', 'user', $de, ['cursos' => $n], ['para' => $para], $motivo, $user);
  return $n;
}

/**
 * Exclui (anonimiza) um usuário: nome/e-mail substituídos, inativo, senha inválida, coautorias removidas.
 * Cursos como responsável precisam ser transferidos ($transferirPara) antes.
 */
function usuario_excluir(int $idUser, string $motivo, array $user, ?int $transferirPara = null): void {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  if ($idUser === (int)$user['id_user']) throw new Exception('Você não pode excluir a si mesmo.');
  $st = db()->prepare("SELECT * FROM tb_users WHERE id_user=?"); $st->execute([$idUser]); $alvo = $st->fetch();
  if (!$alvo) throw new Exception('Usuário não encontrado.');
  if (!empty($alvo['excluido_em'])) throw new Exception('Usuário já excluído.');
  if (perfil_flag($alvo['role'], 'admin_total')) {
    $n = (int)db()->query("SELECT COUNT(*) FROM tb_users u JOIN tb_perfis p ON p.codigo=u.role WHERE p.admin_total=1 AND u.ativo=1 AND u.excluido_em IS NULL")->fetchColumn();
    if ($n <= 1) throw new Exception('Não é possível excluir o último administrador ativo.');
  }
  $st = db()->prepare("SELECT COUNT(*) FROM tb_cursos WHERE id_professor=?"); $st->execute([$idUser]); $nCursos = (int)$st->fetchColumn();
  if ($nCursos > 0) {
    if (!$transferirPara) throw new Exception("Este usuário é responsável por {$nCursos} curso(s). Escolha para quem transferir antes de excluir.");
    usuario_transferir_cursos($idUser, $transferirPara, $motivo, $user);
  }
  if (curso_professores_disponivel()) db()->prepare("DELETE FROM tb_curso_professores WHERE id_usuario=? AND tipo='COAUTOR'")->execute([$idUser]);
  db()->prepare("UPDATE tb_users SET nome=?, email=?, senha_hash=?, ativo=0, excluido_em=NOW() WHERE id_user=?")
      ->execute(["Usuário removido #{$idUser}", "removido-{$idUser}@invalido.local", password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $idUser]);
  // encerra sessões abertas do usuário (sessões em arquivo)
  foreach (glob(realpath(__DIR__ . '/../storage') . '/sessions/sess_*') ?: [] as $s) { $d = @file_get_contents($s); if ($d && preg_match('/"id_user";(?:i|s):\d*:?"?' . $idUser . '"?;/', $d)) @unlink($s); }
  admin_audit('usuario_excluido', 'user', $idUser, ['nome' => $alvo['nome'], 'email' => $alvo['email'], 'role' => $alvo['role']], ['cursos_transferidos_para' => $transferirPara], $motivo, $user);
}

/** Cursos na lixeira (para Admin → Lixeira). */
function cursos_lixeira(): array {
  if (!admin_v18()) return [];
  return db()->query("SELECT c.id_curso, c.nome_curso, c.status_atual, c.situacao_motivo, c.situacao_em, u.nome AS professor_nome, a.nome AS por_nome,
      (SELECT COUNT(*) FROM tb_curso_files f WHERE f.id_curso=c.id_curso) arquivos
    FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor LEFT JOIN tb_users a ON a.id_user=c.situacao_por
    WHERE c.situacao='EXCLUIDO' ORDER BY c.situacao_em DESC")->fetchAll();
}

/** Cursos fora de ATIVO (pausados, suspensos, cancelados, arquivados) para a visão do admin. */
function cursos_por_situacao(string $situacao): array {
  if (!admin_v18()) return [];
  $st = db()->prepare("SELECT c.id_curso, c.nome_curso, c.status_atual, c.situacao, c.situacao_motivo, c.situacao_em, u.nome AS professor_nome FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor WHERE c.situacao=? ORDER BY c.situacao_em DESC");
  $st->execute([$situacao]); return $st->fetchAll();
}

/* ======================================================================
 * V20 — Administração total (etapas 2 e 3)
 * ====================================================================== */

/** Encerra todas as sessões abertas do usuário (ele precisa entrar de novo). */
function usuario_sessoes_encerrar(int $idUser, string $motivo, array $user): void {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  if (!users_v20()) throw new Exception('Rode database/upgrade_v20.sql para habilitar esta função.');
  $st = db()->prepare("SELECT nome FROM tb_users WHERE id_user=?"); $st->execute([$idUser]); $alvo = $st->fetch();
  if (!$alvo) throw new Exception('Usuário não encontrado.');
  db()->prepare("UPDATE tb_users SET sessoes_desde=NOW() WHERE id_user=?")->execute([$idUser]);
  foreach (glob(realpath(__DIR__ . '/../storage') . '/sessions/sess_*') ?: [] as $s) { $d = @file_get_contents($s); if ($d && preg_match('/"id_user";(?:i|s):\d*:?"?' . $idUser . '"?;/', $d)) @unlink($s); }
  admin_audit('usuario_sessoes_encerradas', 'user', $idUser, null, ['nome' => $alvo['nome']], $motivo, $user);
}

/** Exige a troca de senha no próximo acesso do usuário. */
function usuario_forcar_senha(int $idUser, string $motivo, array $user): void {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  if (!users_v20()) throw new Exception('Rode database/upgrade_v20.sql para habilitar esta função.');
  $st = db()->prepare("SELECT nome FROM tb_users WHERE id_user=?"); $st->execute([$idUser]); $alvo = $st->fetch();
  if (!$alvo) throw new Exception('Usuário não encontrado.');
  db()->prepare("UPDATE tb_users SET senha_trocar=1 WHERE id_user=?")->execute([$idUser]);
  admin_audit('usuario_troca_senha_exigida', 'user', $idUser, null, ['nome' => $alvo['nome']], $motivo, $user);
}

/** Desbloqueia o login do usuário (zera tentativas e bloqueio). */
function usuario_desbloquear(int $idUser, string $motivo, array $user): void {
  admin_exigir($user); $motivo = admin_motivo($motivo);
  if (!users_v20()) throw new Exception('Rode database/upgrade_v20.sql para habilitar esta função.');
  $st = db()->prepare("SELECT nome, bloqueado_ate FROM tb_users WHERE id_user=?"); $st->execute([$idUser]); $alvo = $st->fetch();
  if (!$alvo) throw new Exception('Usuário não encontrado.');
  db()->prepare("UPDATE tb_users SET login_falhas=0, bloqueado_ate=NULL WHERE id_user=?")->execute([$idUser]);
  admin_audit('usuario_desbloqueado', 'user', $idUser, ['bloqueado_ate' => $alvo['bloqueado_ate']], ['nome' => $alvo['nome']], $motivo, $user);
}

/* ---------------- Lixeira: expurgo automático ---------------- */

/** Apaga definitivamente os cursos que passaram do prazo de retenção da lixeira (0 = nunca). Retorna os nomes apagados. */
function lixeira_expurgar(array $user): array {
  if (!admin_v18()) return [];
  $dias = lixeira_retencao_dias(); if ($dias <= 0) return [];
  $st = db()->prepare("SELECT id_curso, nome_curso FROM tb_cursos WHERE situacao='EXCLUIDO' AND situacao_em IS NOT NULL AND situacao_em < NOW() - INTERVAL ? DAY");
  $st->execute([$dias]); $apagados = [];
  foreach ($st->fetchAll() as $c) {
    try { curso_excluir_definitivo((int)$c['id_curso'], "Expurgo automático da lixeira: mais de {$dias} dias (parâmetro do sistema).", $user); $apagados[] = $c['nome_curso']; }
    catch (Throwable $e) { error_log('Expurgo da lixeira: ' . $e->getMessage()); }
  }
  return $apagados;
}

/* ---------------- Migrações pelo Diagnóstico ---------------- */

/** Divide um arquivo .sql em comandos, respeitando aspas e comentários. */
function sql_split(string $sql): array {
  $cmds = []; $atual = ''; $n = strlen($sql); $q = null; $i = 0;
  while ($i < $n) {
    $ch = $sql[$i]; $dois = substr($sql, $i, 2);
    if ($q === null) {
      if ($dois === '--' || $ch === '#') { while ($i < $n && $sql[$i] !== "\n") $i++; continue; }      // comentário de linha
      if ($dois === '/*') { $f = strpos($sql, '*/', $i + 2); $i = $f === false ? $n : $f + 2; continue; } // comentário de bloco
      if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; $atual .= $ch; $i++; continue; }
      if ($ch === ';') { $t = trim($atual); if ($t !== '' && stripos($t, 'DELIMITER') !== 0) $cmds[] = $t; $atual = ''; $i++; continue; }
      $atual .= $ch; $i++; continue;
    }
    // dentro de aspas
    if ($ch === '\\' && $i + 1 < $n) { $atual .= $ch . $sql[$i + 1]; $i += 2; continue; }
    if ($ch === $q) { if ($i + 1 < $n && $sql[$i + 1] === $q) { $atual .= $ch . $ch; $i += 2; continue; } $q = null; }
    $atual .= $ch; $i++;
  }
  $t = trim($atual); if ($t !== '') $cmds[] = $t;
  return $cmds;
}

/** Arquivos de migração disponíveis: 'V20' => caminho. */
function migracoes_arquivos(): array {
  $out = [];
  foreach (glob(realpath(__DIR__ . '/../database') . '/upgrade_v*.sql') ?: [] as $f) { if (preg_match('/upgrade_v(\d+)\.sql$/', $f, $m)) $out['V' . $m[1]] = $f; }
  return $out;
}

/**
 * Executa uma migração (database/upgrade_vNN.sql) no banco do sistema. Somente ADMIN real.
 * Retorna ['comandos'=>n, 'ok'=>n, 'erros'=>[...], 'log'=>[[cmd, 'ok'|erro]]].
 */
function migracao_aplicar(string $versao, array $user): array {
  admin_exigir($user);
  $arqs = migracoes_arquivos(); $versao = strtoupper($versao);
  if (!isset($arqs[$versao])) throw new Exception("Migração {$versao} não encontrada em database/.");
  $sql = file_get_contents($arqs[$versao]); if ($sql === false) throw new Exception('Não foi possível ler o arquivo.');
  $cmds = sql_split($sql); $log = []; $ok = 0; $erros = [];
  $db = db();
  foreach ($cmds as $c) {
    if (preg_match('/^\s*(USE\s|SET\s+NAMES)/i', $c)) { $log[] = [$c, 'ignorado']; continue; }
    try { $st = $db->query($c); if ($st instanceof PDOStatement) $st->closeCursor(); $ok++; $log[] = [$c, 'ok']; } // closeCursor: um SELECT no script não pode travar as consultas seguintes
    catch (Throwable $e) { $erros[] = $e->getMessage(); $log[] = [$c, 'ERRO: ' . $e->getMessage()]; }
  }
  audit_log('migracao_aplicada', 'sistema', null, null, ['acao' => 'migracao_aplicada', 'versao' => $versao, 'comandos' => count($cmds), 'ok' => $ok, 'erros' => count($erros), 'motivo' => 'Aplicada pelo Diagnóstico do sistema'], (int)$user['id_user']);
  return ['comandos' => count($cmds), 'ok' => $ok, 'erros' => $erros, 'log' => $log];
}

/* ---------------- Relatório / resumo semanal das intervenções ---------------- */

const INTERVENCOES_ACOES = ['acao_admin', 'agir_como_inicio', 'agir_como_fim', 'migracao_aplicada', 'parametros_alterados'];

/** Intervenções administrativas (auditoria) no período. */
function intervencoes_listar(?string $de, ?string $ate, ?string $tipo = null, int $limite = 3000): array {
  $w = ["a.acao IN ('" . implode("','", INTERVENCOES_ACOES) . "')"]; $p = [];
  if ($de) { $w[] = "a.created_at >= ?"; $p[] = $de . ' 00:00:00'; }
  if ($ate) { $w[] = "a.created_at <= ?"; $p[] = $ate . ' 23:59:59'; }
  if ($tipo) { $w[] = "a.dados_depois LIKE ?"; $p[] = '%"acao":"' . $tipo . '"%'; }
  $st = db()->prepare("SELECT a.*, u.nome quem FROM tb_audit_log a LEFT JOIN tb_users u ON u.id_user=a.id_user WHERE " . implode(' AND ', $w) . " ORDER BY a.id_audit DESC LIMIT " . (int)$limite);
  $st->execute($p); $out = [];
  foreach ($st->fetchAll() as $r) {
    $d = json_decode((string)$r['dados_depois'], true) ?: [];
    $r['tipo'] = $d['acao'] ?? $r['acao']; $r['motivo'] = $d['motivo'] ?? ''; $r['alvo'] = $d['curso'] ?? $d['nome'] ?? $d['como'] ?? $d['versao'] ?? '';
    $r['agindo_como'] = isset($d['_agindo_como']) ? $d['_agindo_como']['nome'] : null;
    $r['detalhes'] = $d; $out[] = $r;
  }
  return $out;
}

/** Resumo semanal por e-mail aos administradores (no máximo 1 por semana; disparado ao abrir o Dashboard). */
function intervencoes_email_semanal(): void {
  if (cfg_get('intervencoes_email') !== '1') return;
  $ultimo = cfg_get('intervencoes_email_em', '');
  if ($ultimo && strtotime($ultimo) > time() - 7 * 86400) return;
  cfg_set('intervencoes_email_em', date('Y-m-d H:i:s')); // marca antes para não disparar em paralelo
  $de = date('Y-m-d', strtotime('-7 days'));
  $itens = intervencoes_listar($de, null, null, 500);
  if (!$itens) return;
  require_once __DIR__ . '/notify.php';
  $rows = '';
  foreach ($itens as $it) $rows .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;white-space:nowrap">' . date('d/m H:i', strtotime($it['created_at'])) . '</td><td style="padding:4px 8px;border-bottom:1px solid #eee">' . htmlspecialchars($it['tipo']) . '</td><td style="padding:4px 8px;border-bottom:1px solid #eee">' . htmlspecialchars((string)$it['alvo']) . '</td><td style="padding:4px 8px;border-bottom:1px solid #eee">' . htmlspecialchars((string)$it['quem']) . ($it['agindo_como'] ? ' <i>(agindo como ' . htmlspecialchars($it['agindo_como']) . ')</i>' : '') . '</td><td style="padding:4px 8px;border-bottom:1px solid #eee">' . htmlspecialchars(mb_strimwidth((string)$it['motivo'], 0, 90, '…')) . '</td></tr>';
  $html = mail_template('Intervenções administrativas — últimos 7 dias', '<p>' . count($itens) . ' ação(ões) administrativa(s) desde ' . date('d/m/Y', strtotime($de)) . ':</p><table style="border-collapse:collapse;font-size:12px;width:100%"><tr><th align="left" style="padding:4px 8px">Quando</th><th align="left" style="padding:4px 8px">Ação</th><th align="left" style="padding:4px 8px">Alvo</th><th align="left" style="padding:4px 8px">Quem</th><th align="left" style="padding:4px 8px">Motivo</th></tr>' . $rows . '</table>', app_base_url() . '/relatorios.php?r=intervencoes', 'Ver o relatório completo');
  foreach (db()->query("SELECT u.nome, u.email FROM tb_users u JOIN tb_perfis p ON p.codigo=u.role WHERE p.admin_total=1 AND u.ativo=1" . (users_v20() ? " AND u.excluido_em IS NULL" : ""))->fetchAll() as $adm) {
    notify_queue($adm['email'], $adm['nome'], '[AutoriaSCS] Resumo semanal das intervenções administrativas', $html, true);
  }
}
