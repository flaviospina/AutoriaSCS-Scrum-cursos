<?php
/**
 * V19 — Filmagens (Estúdio MB)
 *
 * Lê as reservas de estúdio do Sistema de Reservas (banco separado, no mesmo
 * servidor MariaDB), guarda uma cópia em tb_filmagens, liga cada reserva ao
 * curso do AutoriaSCS e permite que a MB registre a filmagem realizada.
 * Com isso medimos: reserva → filmagem e filmagem → entrega do vídeo.
 *
 * A conexão com o banco de reservas é SOMENTE LEITURA (config 'reservas').
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_repo.php';
require_once __DIR__ . '/audit.php';

const FILMAGEM_SITUACOES = [
  'PENDENTE'      => ['label' => 'Aguardando filmagem', 'badge' => 'bg-warning text-dark'],
  'REALIZADA'     => ['label' => 'Filmagem realizada',  'badge' => 'bg-success'],
  'NAO_REALIZADA' => ['label' => 'Não realizada',       'badge' => 'bg-secondary'],
];

/* ----------------------------------------------------------------
 * Conexão com o Sistema de Reservas
 * ---------------------------------------------------------------- */
function reservas_cfg(): array {
  $cfg = require __DIR__ . '/config.php';
  return $cfg['reservas'] ?? [];
}
function reservas_configurado(): bool { $r = reservas_cfg(); return !empty($r['name']); }

/** PDO do banco de reservas, ou null (não configurado / indisponível). O motivo fica em reservas_erro(). */
function reservas_db(): ?PDO {
  static $pdo = null, $tentou = false;
  if ($tentou) return $pdo;
  $tentou = true;
  $r = reservas_cfg();
  if (empty($r['name'])) { reservas_erro('Integração não configurada: informe o banco do Sistema de Reservas em config.local.php (bloco \'reservas\').'); return null; }
  try {
    $pdo = new PDO("mysql:host={$r['host']};dbname={$r['name']};charset=" . ($r['charset'] ?? 'utf8mb4'), $r['user'], $r['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5,
    ]);
  } catch (PDOException $e) {
    error_log('Reservas MB: ' . $e->getMessage());
    reservas_erro('Não foi possível conectar ao banco do Sistema de Reservas (' . $e->getMessage() . ').');
    $pdo = null;
  }
  return $pdo;
}
function reservas_erro(?string $set = null): ?string { static $msg = null; if ($set !== null) $msg = $set; return $msg; }

function filmagens_tabela(): bool {
  static $ok = null;
  if ($ok === null) { $st = db()->prepare("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_filmagens'"); $st->execute(); $ok = (int)$st->fetch()['n'] > 0; }
  return $ok;
}

/** Lê as reservas na origem (tolerante a colunas que não existam). */
function reservas_ler(): array {
  $pdo = reservas_db(); if (!$pdo) return [];
  $cols = array_column($pdo->query("SHOW COLUMNS FROM reservas")->fetchAll(), 'Field');
  foreach (['id', 'data'] as $c) if (!in_array($c, $cols, true)) throw new Exception("A tabela reservas não tem a coluna '$c'.");
  $sel = ['r.id', 'r.data'];
  // as demais colunas variam conforme a versão do Sistema de Reservas: o que não existir vem como NULL
  foreach (['usuario_id', 'sala', 'hora', 'curso', 'ambiente', 'estado', 'motivo', 'created_at', 'gravacao_concluida', 'hora_proposta'] as $c) $sel[] = in_array($c, $cols, true) ? "r.$c" : "NULL AS $c";
  $temUsers = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios'")->fetchColumn() > 0;
  $join = $temUsers ? "LEFT JOIN usuarios u ON u.id = r.usuario_id" : '';
  $sel[] = $temUsers ? 'u.nome AS professor' : 'NULL AS professor';
  $sel[] = $temUsers ? 'u.email AS email_prof' : 'NULL AS email_prof';
  return $pdo->query("SELECT " . implode(', ', $sel) . " FROM reservas r $join ORDER BY r.id")->fetchAll();
}

/* ----------------------------------------------------------------
 * Sincronização (copia/atualiza as reservas em tb_filmagens)
 * ---------------------------------------------------------------- */
function filmagens_normalizar(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false) $s = $t;
  $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
  return trim(preg_replace('/\s+/', ' ', $s));
}

/** Similaridade (0..100) entre dois nomes já normalizados. */
function filmagens_similaridade(string $a, string $b): float {
  if ($a === '' || $b === '') return 0;
  if ($a === $b) return 100;
  if (str_contains($a, $b) || str_contains($b, $a)) return 92; // um contém o outro (reserva costuma vir com o nome cortado)
  similar_text($a, $b, $pct);
  // compara também só o começo (os nomes longos divergem no final)
  $n = min(strlen($a), strlen($b), 40); similar_text(substr($a, 0, $n), substr($b, 0, $n), $pctIni);
  return max($pct, $pctIni * 0.95);
}

/**
 * Tenta descobrir o curso do AutoriaSCS a partir do nome do curso e do e-mail do professor da reserva.
 * Regras: nome muito parecido (≥ 90%) → liga; professor identificado pelo e-mail e nome razoavelmente parecido
 * (≥ 60%) entre os cursos dele → liga; professor com um único curso e nome em branco → liga.
 */
function filmagem_autovincular(?string $cursoNome, ?string $email): ?int {
  $alvo = filmagens_normalizar((string)$cursoNome);
  $email = mb_strtolower(trim((string)$email));
  $idUser = null;
  if ($email !== '') { $st = db()->prepare("SELECT id_user FROM tb_users WHERE LOWER(email)=? LIMIT 1"); $st->execute([$email]); $idUser = ($r = $st->fetch()) ? (int)$r['id_user'] : null; }
  $cursos = db()->query("SELECT id_curso, nome_curso, id_professor FROM tb_cursos" . (function_exists('admin_v18') && admin_v18() ? " WHERE situacao <> 'EXCLUIDO'" : ''))->fetchAll();
  $coaut = [];
  if ($idUser) { try { $st = db()->prepare("SELECT id_curso FROM tb_curso_professores WHERE id_usuario=?"); $st->execute([$idUser]); $coaut = array_map('intval', array_column($st->fetchAll(), 'id_curso')); } catch (Throwable $e) {} }
  $doProf = fn(array $c) => $idUser && ((int)$c['id_professor'] === $idUser || in_array((int)$c['id_curso'], $coaut, true));

  $melhor = null; $melhorPct = 0; $melhorProf = null; $melhorProfPct = 0; $nProf = 0; $unicoProf = null;
  foreach ($cursos as $c) {
    $pct = filmagens_similaridade($alvo, filmagens_normalizar($c['nome_curso']));
    $ehProf = $doProf($c);
    if ($ehProf) { $nProf++; $unicoProf = (int)$c['id_curso']; }
    if ($pct > $melhorPct || ($pct === $melhorPct && $ehProf)) { $melhorPct = $pct; $melhor = $c; }
    if ($ehProf && $pct > $melhorProfPct) { $melhorProfPct = $pct; $melhorProf = $c; }
  }
  if ($melhorProf && $melhorProfPct >= 60) return (int)$melhorProf['id_curso'];
  if ($melhor && $melhorPct >= 90) return (int)$melhor['id_curso'];
  if ($alvo === '' && $nProf === 1) return $unicoProf;
  return null;
}

/**
 * Sincroniza as reservas. Sem $forcar, roda no máximo a cada 10 minutos.
 * Retorna ['ok'=>bool, 'novas'=>n, 'atualizadas'=>n, 'removidas'=>n, 'erro'=>?string, 'pulou'=>bool]
 */
function filmagens_sincronizar(bool $forcar = false): array {
  $out = ['ok' => false, 'novas' => 0, 'atualizadas' => 0, 'removidas' => 0, 'erro' => null, 'pulou' => false];
  if (!filmagens_tabela()) { $out['erro'] = 'Tabela tb_filmagens ausente: rode database/upgrade_v19.sql.'; return $out; }
  if (!reservas_configurado()) { $out['erro'] = 'Integração com o Sistema de Reservas não configurada (config.local.php, bloco \'reservas\').'; return $out; }
  $ultimo = cfg_get('filmagens_sync_em', '');
  if (!$forcar && $ultimo && strtotime($ultimo) > time() - 600) { $out['ok'] = true; $out['pulou'] = true; return $out; }
  try { $rows = reservas_ler(); } catch (Throwable $e) { $out['erro'] = 'Falha ao ler as reservas: ' . $e->getMessage(); error_log('Reservas MB: ' . $e->getMessage()); return $out; }
  if (!reservas_db()) { $out['erro'] = reservas_erro(); return $out; }

  $db = db();
  $existentes = [];
  foreach ($db->query("SELECT id_filmagem, id_reserva, id_curso, vinculo_manual, registrado_por FROM tb_filmagens WHERE id_reserva IS NOT NULL") as $e) $existentes[(int)$e['id_reserva']] = $e;
  $agora = date('Y-m-d H:i:s'); $vistos = [];
  $ins = $db->prepare("INSERT INTO tb_filmagens (id_reserva, id_curso, professor_nome, professor_email, curso_nome, sala, ambiente, data_reserva, hora_reserva, estado_reserva, motivo_reserva, reserva_criada_em, sincronizado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $upd = $db->prepare("UPDATE tb_filmagens SET professor_nome=?, professor_email=?, curso_nome=?, sala=?, ambiente=?, data_reserva=?, hora_reserva=?, estado_reserva=?, motivo_reserva=?, reserva_criada_em=?, removida_na_origem=0, sincronizado_em=? WHERE id_reserva=?");
  $updCurso = $db->prepare("UPDATE tb_filmagens SET id_curso=? WHERE id_filmagem=? AND id_curso IS NULL AND vinculo_manual=0");
  // marcação "Gravação concluída" feita pela MB no Sistema de Reservas: vale enquanto a MB não registrar nada aqui
  $updGrav = $db->prepare("UPDATE tb_filmagens SET filmagem=?, filmada_em=?, filmagem_obs=? WHERE id_filmagem=? AND registrado_por IS NULL");
  foreach ($rows as $r) {
    $idRes = (int)$r['id']; $vistos[] = $idRes;
    if (!$r['data'] || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$r['data'])) continue;
    $estado = in_array($r['estado'] ?? '', ['Pendente', 'Aceita', 'Recusada', 'Horario Proposto'], true) ? $r['estado'] : 'Pendente';
    $data = substr((string)$r['data'], 0, 10);
    $motivo = (string)($r['motivo'] ?? '');
    if ($estado === 'Horario Proposto' && !empty($r['hora_proposta'])) $motivo = trim('Horário proposto pela MB: ' . substr($r['hora_proposta'], 0, 5) . ($motivo !== '' ? ' — ' . $motivo : ''));
    $vals = [$r['professor'] ?: null, $r['email_prof'] ?: null, $r['curso'] ?: null, $r['sala'] ?: null, $r['ambiente'] ?: null, $data, $r['hora'] ?: null, $estado, $motivo ?: null, $r['created_at'] ?: null, $agora];
    // gravação informada pela MB no Sistema de Reservas (NULL = não informado)
    $grav = $r['gravacao_concluida'];
    $gravSit = $grav === null || $grav === '' ? 'PENDENTE' : ((int)$grav === 1 ? 'REALIZADA' : 'NAO_REALIZADA');
    $gravData = $gravSit === 'REALIZADA' ? $data : null;
    $gravObs = $gravSit === 'PENDENTE' ? null : ($gravSit === 'REALIZADA' ? 'Gravação concluída (informado pela MB no Sistema de Reservas)' : 'Gravação não concluída (informado pela MB no Sistema de Reservas)');
    if (isset($existentes[$idRes])) {
      $ex = $existentes[$idRes];
      $upd->execute(array_merge($vals, [$idRes])); $out['atualizadas']++;
      if ($ex['registrado_por'] === null) $updGrav->execute([$gravSit, $gravData, $gravObs, (int)$ex['id_filmagem']]);
      if ($ex['id_curso'] === null && !(int)$ex['vinculo_manual']) { $idc = filmagem_autovincular($r['curso'], $r['email_prof']); if ($idc) $updCurso->execute([$idc, (int)$ex['id_filmagem']]); }
    } else {
      $idc = filmagem_autovincular($r['curso'], $r['email_prof']);
      $ins->execute(array_merge([$idRes, $idc], $vals)); $out['novas']++;
      if ($gravSit !== 'PENDENTE') $updGrav->execute([$gravSit, $gravData, $gravObs, (int)$db->lastInsertId()]);
    }
  }
  // reservas apagadas na origem: marcamos, não excluímos (o registro da filmagem é nosso)
  $ids = array_keys($existentes); $sumiram = array_diff($ids, $vistos);
  if ($sumiram) { $db->prepare("UPDATE tb_filmagens SET removida_na_origem=1 WHERE id_reserva IN (" . implode(',', array_map('intval', $sumiram)) . ")")->execute(); $out['removidas'] = count($sumiram); }
  cfg_set('filmagens_sync_em', $agora);
  $out['ok'] = true;
  return $out;
}

/* ----------------------------------------------------------------
 * Consultas
 * ---------------------------------------------------------------- */
function filmagem_badge(string $s): string { $d = FILMAGEM_SITUACOES[$s] ?? FILMAGEM_SITUACOES['PENDENTE']; return '<span class="badge ' . $d['badge'] . '">' . $d['label'] . '</span>'; }
function reserva_badge(string $e): string { $m = ['Aceita' => 'bg-success', 'Recusada' => 'bg-danger', 'Pendente' => 'bg-warning text-dark', 'Horario Proposto' => 'bg-info text-dark']; return '<span class="badge ' . ($m[$e] ?? 'bg-secondary') . '">' . htmlspecialchars($e) . '</span>'; }

function filmagem_get(int $id): ?array {
  $st = db()->prepare("SELECT f.*, c.nome_curso, c.id_professor, u.nome registrado_nome FROM tb_filmagens f LEFT JOIN tb_cursos c ON c.id_curso=f.id_curso LEFT JOIN tb_users u ON u.id_user=f.registrado_por WHERE f.id_filmagem=?");
  $st->execute([$id]); return $st->fetch() ?: null;
}

/** SQL da primeira entrega de vídeo após a filmagem (versão 1 de qualquer vídeo do curso; do módulo, se informado). */
function filmagens_sql_video(): string {
  return "(SELECT MIN(vv.created_at) FROM tb_video_versoes vv JOIN tb_videos v ON v.id_video=vv.id_video WHERE v.id_curso=f.id_curso AND (f.modulo IS NULL OR v.modulo=f.modulo) AND f.filmada_em IS NOT NULL AND vv.created_at >= f.filmada_em)";
}

/** Lista com filtros: de, ate (data da reserva), sala, estado (reserva), filmagem, id_curso, sem_curso, q (texto), id_professor. */
function filmagens_listar(array $f = [], int $limite = 2000): array {
  if (!filmagens_tabela()) return [];
  $w = []; $p = [];
  if (!empty($f['de']))  { $w[] = "f.data_reserva >= ?"; $p[] = $f['de']; }
  if (!empty($f['ate'])) { $w[] = "f.data_reserva <= ?"; $p[] = $f['ate']; }
  if (!empty($f['sala'])) { $w[] = "f.sala = ?"; $p[] = $f['sala']; }
  if (!empty($f['estado'])) { $w[] = "f.estado_reserva = ?"; $p[] = $f['estado']; }
  if (!empty($f['filmagem']) && isset(FILMAGEM_SITUACOES[$f['filmagem']])) { $w[] = "f.filmagem = ?"; $p[] = $f['filmagem']; }
  if (!empty($f['id_curso'])) { $w[] = "f.id_curso = ?"; $p[] = (int)$f['id_curso']; }
  if (!empty($f['sem_curso'])) $w[] = "f.id_curso IS NULL";
  if (!empty($f['id_professor'])) { $w[] = "(c.id_professor = ? OR LOWER(f.professor_email) = (SELECT LOWER(email) FROM tb_users WHERE id_user=?))"; $p[] = (int)$f['id_professor']; $p[] = (int)$f['id_professor']; }
  if (!empty($f['q'])) { $w[] = "(f.curso_nome LIKE ? OR f.professor_nome LIKE ? OR c.nome_curso LIKE ?)"; $p[] = "%{$f['q']}%"; $p[] = "%{$f['q']}%"; $p[] = "%{$f['q']}%"; }
  if (empty($f['com_removidas'])) $w[] = "f.removida_na_origem = 0";
  $sw = $w ? 'WHERE ' . implode(' AND ', $w) : '';
  $vid = filmagens_sql_video();
  $st = db()->prepare("SELECT f.*, c.nome_curso, c.status_atual, u.nome registrado_nome, $vid video_em,
      DATEDIFF(f.filmada_em, f.data_reserva) dias_reserva_filmagem,
      DATEDIFF($vid, f.filmada_em) dias_filmagem_video
    FROM tb_filmagens f LEFT JOIN tb_cursos c ON c.id_curso=f.id_curso LEFT JOIN tb_users u ON u.id_user=f.registrado_por
    $sw ORDER BY f.data_reserva DESC, f.hora_reserva DESC LIMIT " . (int)$limite);
  $st->execute($p);
  return $st->fetchAll();
}
function filmagens_do_curso(int $idCurso): array { return filmagens_listar(['id_curso' => $idCurso, 'com_removidas' => 1], 200); }
function filmagens_salas(): array { if (!filmagens_tabela()) return []; return array_column(db()->query("SELECT DISTINCT sala FROM tb_filmagens WHERE sala IS NOT NULL AND sala<>'' ORDER BY sala")->fetchAll(), 'sala'); }

/* ----------------------------------------------------------------
 * Ações
 * ---------------------------------------------------------------- */
/** Só a MB registra a filmagem (decisão do CECAPE). O ADMIN real pode corrigir, e a correção fica na auditoria. */
function filmagem_pode_registrar(): bool {
  $u = auth_user(); if (!$u) return false;
  if (($u['role'] ?? '') === 'MB') return true; // inclui o ADMIN "vendo como MB"
  return is_admin_real();                        // correção pelo ADMIN real (fica na auditoria com o perfil)
}
function filmagem_pode_vincular(): bool { return auth_user() && (perm('revisa_cursos') || is_admin()); }

function filmagem_registrar(int $id, string $situacao, ?string $data, ?string $obs, array $user): array {
  if (!filmagem_pode_registrar()) throw new Exception('Somente a MB Estúdios registra a filmagem.');
  $f = filmagem_get($id); if (!$f) throw new Exception('Reserva não encontrada.');
  if (!isset(FILMAGEM_SITUACOES[$situacao])) throw new Exception('Situação inválida.');
  $data = trim((string)$data); $obs = mb_substr(trim((string)$obs), 0, 500);
  if ($situacao === 'REALIZADA') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) throw new Exception('Informe a data em que a filmagem foi realizada.');
    if ($data > date('Y-m-d')) throw new Exception('A data da filmagem não pode ser futura.');
  } else $data = null;
  if ($situacao === 'NAO_REALIZADA' && $obs === '') throw new Exception('Informe o motivo de a filmagem não ter sido realizada.');
  db()->prepare("UPDATE tb_filmagens SET filmagem=?, filmada_em=?, filmagem_obs=?, registrado_por=?, registrado_em=NOW() WHERE id_filmagem=?")
    ->execute([$situacao, $data, $obs ?: null, (int)$user['id_user'], $id]);
  audit_log('filmagem_registrada', 'curso', $f['id_curso'] ? (int)$f['id_curso'] : null,
    ['filmagem' => $f['filmagem'], 'filmada_em' => $f['filmada_em']],
    ['id_filmagem' => $id, 'reserva' => $f['id_reserva'], 'filmagem' => $situacao, 'filmada_em' => $data, 'obs' => $obs, 'curso' => $f['nome_curso'] ?? $f['curso_nome'], 'por' => $user['role']], (int)$user['id_user']);
  return filmagem_get($id);
}

function filmagem_vincular(int $id, ?int $idCurso, ?int $modulo, array $user): void {
  if (!filmagem_pode_vincular()) throw new Exception('Somente TI/ADMIN vincula a reserva a um curso.');
  $f = filmagem_get($id); if (!$f) throw new Exception('Reserva não encontrada.');
  if ($idCurso) { $st = db()->prepare("SELECT id_curso FROM tb_cursos WHERE id_curso=?"); $st->execute([$idCurso]); if (!$st->fetch()) throw new Exception('Curso não encontrado.'); }
  if ($modulo !== null && ($modulo < 0 || $modulo > 8)) throw new Exception('Módulo inválido.');
  db()->prepare("UPDATE tb_filmagens SET id_curso=?, modulo=?, vinculo_manual=1 WHERE id_filmagem=?")->execute([$idCurso ?: null, $modulo, $id]);
  audit_log('filmagem_vinculada', 'curso', $idCurso ?: null, ['id_curso' => $f['id_curso']], ['id_filmagem' => $id, 'id_curso' => $idCurso, 'modulo' => $modulo], (int)$user['id_user']);
}

/** Filmagem sem reserva no sistema (ex.: gravação combinada diretamente). Só a MB. */
function filmagem_avulsa(int $idCurso, ?int $modulo, string $data, ?string $hora, ?string $sala, ?string $obs, array $user): int {
  if (!filmagem_pode_registrar()) throw new Exception('Somente a MB Estúdios registra filmagens.');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > date('Y-m-d')) throw new Exception('Informe a data da filmagem (não pode ser futura).');
  $st = db()->prepare("SELECT c.*, u.nome prof, u.email prof_email FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor WHERE c.id_curso=?"); $st->execute([$idCurso]); $c = $st->fetch();
  if (!$c) throw new Exception('Curso não encontrado.');
  $hora = preg_match('/^\d{2}:\d{2}/', (string)$hora) ? substr($hora, 0, 5) . ':00' : null;
  db()->prepare("INSERT INTO tb_filmagens (id_reserva, id_curso, modulo, vinculo_manual, professor_nome, professor_email, curso_nome, sala, ambiente, data_reserva, hora_reserva, estado_reserva, filmagem, filmada_em, filmagem_obs, registrado_por, registrado_em) VALUES (NULL,?,?,1,?,?,?,?,'Avulsa (sem reserva)',?,?,'Aceita','REALIZADA',?,?,?,NOW())")
    ->execute([$idCurso, $modulo, $c['prof'], $c['prof_email'], $c['nome_curso'], $sala ?: null, $data, $hora, $data, mb_substr(trim((string)$obs), 0, 500) ?: null, (int)$user['id_user']]);
  $id = (int)db()->lastInsertId();
  audit_log('filmagem_registrada', 'curso', $idCurso, null, ['id_filmagem' => $id, 'avulsa' => true, 'filmada_em' => $data, 'curso' => $c['nome_curso']], (int)$user['id_user']);
  return $id;
}

/* ----------------------------------------------------------------
 * Métricas (atraso da MB)
 * ---------------------------------------------------------------- */
function filmagens_metricas(array $f = []): array {
  $m = ['reservas' => 0, 'aceitas' => 0, 'aguardando' => 0, 'realizadas' => 0, 'nao_realizadas' => 0, 'sem_curso' => 0,
        'media_reserva_filmagem' => null, 'media_filmagem_video' => null, 'max_filmagem_video' => null, 'videos_pendentes' => 0, 'pendentes_7d' => 0, 'media_pendente_dias' => null];
  if (!filmagens_tabela()) return $m;
  $linhas = filmagens_listar($f, 5000);
  $somaRF = 0; $nRF = 0; $somaFV = 0; $nFV = 0; $somaPend = 0;
  $hoje = new DateTime('today');
  foreach ($linhas as $l) {
    $m['reservas']++;
    if ($l['estado_reserva'] === 'Aceita') $m['aceitas']++;
    if ($l['id_curso'] === null) $m['sem_curso']++;
    if ($l['filmagem'] === 'PENDENTE' && $l['estado_reserva'] !== 'Recusada' && $l['data_reserva'] <= $hoje->format('Y-m-d')) $m['aguardando']++;
    if ($l['filmagem'] === 'NAO_REALIZADA') $m['nao_realizadas']++;
    if ($l['filmagem'] === 'REALIZADA') {
      $m['realizadas']++;
      if ($l['dias_reserva_filmagem'] !== null) { $somaRF += (int)$l['dias_reserva_filmagem']; $nRF++; }
      if ($l['id_curso'] !== null) {
        if ($l['video_em'] !== null) { $d = (int)$l['dias_filmagem_video']; $somaFV += $d; $nFV++; $m['max_filmagem_video'] = max((int)$m['max_filmagem_video'], $d); }
        else { $m['videos_pendentes']++; $dp = (int)$hoje->diff(new DateTime($l['filmada_em']))->days; $somaPend += $dp; if ($dp > 7) $m['pendentes_7d']++; }
      }
    }
  }
  if ($nRF) $m['media_reserva_filmagem'] = $somaRF / $nRF;
  if ($nFV) $m['media_filmagem_video'] = $somaFV / $nFV;
  if ($m['videos_pendentes']) $m['media_pendente_dias'] = $somaPend / $m['videos_pendentes'];
  return $m;
}
