<?php
/**
 * Apontamentos TI/Qualidade (V11): arquivo relacionado, status com fluxo,
 * histórico (linha do tempo), manifestação formal do professor
 * (concordância / objeção) e notificações centralizadas.
 *
 * Regras de permissão ficam AQUI (backend): a interface apenas reflete.
 * Apontamentos nunca são apagados.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/curso_repo.php';

/** Status do apontamento (item 15) — únicos valores válidos no sistema. */
const APONT_STATUS = [
  'PENDENTE_ANALISE'    => ['label' => 'Pendente de análise',        'cor' => '#fbbf24', 'pendente' => true],
  'CORRECAO_SOLICITADA' => ['label' => 'Correção solicitada',        'cor' => '#f97316', 'pendente' => true],
  'EM_CORRECAO'         => ['label' => 'Em correção pelo professor', 'cor' => '#38bdf8', 'pendente' => true],
  'REENVIADO_ANALISE'   => ['label' => 'Reenviado para análise',     'cor' => '#a78bfa', 'pendente' => true],
  'APROVADO'            => ['label' => 'Aprovado',                   'cor' => '#34d399', 'pendente' => false],
  'CONCLUIDO'           => ['label' => 'Concluído',                  'cor' => '#22c55e', 'pendente' => false],
];

const APONT_TIPOS = ['TECNICO' => 'Técnico', 'PEDAGOGICO' => 'Pedagógico', 'ABNT' => 'ABNT', 'OUTRO' => 'Outro'];

/** Status que a equipe TI/ADMIN pode definir diretamente. */
const APONT_STATUS_TI = ['PENDENTE_ANALISE', 'CORRECAO_SOLICITADA', 'APROVADO', 'CONCLUIDO'];

/**
 * Saudação dos e-mails de apontamento enviados ao professor (item 21).
 * {NOME} é substituído pelo nome real. Ajuste o texto aqui, em um único lugar.
 */
const APONT_EMAIL_SAUDACAO = 'Olá, {NOME}, você indicou um apontamento';

function apont_status_pendentes(): array {
  return array_keys(array_filter(APONT_STATUS, fn($s) => $s['pendente']));
}

function apont_status_label(string $s): string { return APONT_STATUS[$s]['label'] ?? $s; }

function apont_status_badge(string $s): string {
  $cor = APONT_STATUS[$s]['cor'] ?? '#6c757d';
  $txt = (function_exists('contrast_color') ? contrast_color($cor) : '#000');
  return '<span class="badge badge-apont rounded-pill" style="background-color:' . $cor . ';color:' . $txt . '">'
       . htmlspecialchars(apont_status_label($s)) . '</span>';
}

function apont_tipo_label(string $t): string { return APONT_TIPOS[$t] ?? $t; }

/** Um apontamento com curso, autor e arquivo relacionado. */
function apont_get(int $id): ?array {
  $st = db()->prepare("
    SELECT a.*, u.nome AS user_nome,
           f.original_name AS arquivo_nome, f.modulo AS arquivo_modulo, f.categoria AS arquivo_categoria,
           c.nome_curso, c.id_professor, c.status_atual AS curso_status,
           p.nome AS professor_nome, p.email AS professor_email
    FROM tb_curso_apontamentos a
    JOIN tb_users u ON u.id_user = a.id_user
    JOIN tb_cursos c ON c.id_curso = a.id_curso
    JOIN tb_users p ON p.id_user = c.id_professor
    LEFT JOIN tb_curso_files f ON f.id_file = a.id_file
    WHERE a.id_apontamento = ?
  ");
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

/** Apontamentos de um curso (mais recentes primeiro). */
function apont_lista(int $idCurso): array {
  $st = db()->prepare("
    SELECT a.*, u.nome AS user_nome,
           f.original_name AS arquivo_nome, f.modulo AS arquivo_modulo, f.categoria AS arquivo_categoria
    FROM tb_curso_apontamentos a
    JOIN tb_users u ON u.id_user = a.id_user
    LEFT JOIN tb_curso_files f ON f.id_file = a.id_file
    WHERE a.id_curso = ?
    ORDER BY a.created_at DESC, a.id_apontamento DESC
  ");
  $st->execute([$idCurso]);
  return $st->fetchAll();
}

/** Quantidade de apontamentos pendentes (status ativos) do curso. */
function apont_pendentes_curso(int $idCurso): int {
  return apont_pendentes_por_curso([$idCurso])[$idCurso] ?? 0;
}

/** Mapa id_curso => pendentes, para listas/dashboard. */
function apont_pendentes_por_curso(array $ids): array {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) return [];
  $in = implode(',', array_fill(0, count($ids), '?'));
  $out = [];
  try {
    $pend = apont_status_pendentes();
    $inS = implode(',', array_fill(0, count($pend), '?'));
    $st = db()->prepare("SELECT id_curso, COUNT(*) n FROM tb_curso_apontamentos
                         WHERE id_curso IN ($in) AND status IN ($inS) GROUP BY id_curso");
    $st->execute(array_merge($ids, $pend));
  } catch (Throwable $e) { // upgrade_v11.sql ainda não executado
    $st = db()->prepare("SELECT id_curso, COUNT(*) n FROM tb_curso_apontamentos
                         WHERE id_curso IN ($in) AND resolvido=0 GROUP BY id_curso");
    $st->execute($ids);
  }
  foreach ($st->fetchAll() as $r) $out[(int)$r['id_curso']] = (int)$r['n'];
  return $out;
}

/** Arquivos do curso para o campo "Arquivo/Material relacionado". */
function apont_arquivos_opcoes(int $idCurso): array {
  $st = db()->prepare("SELECT id_file, original_name, modulo, categoria FROM tb_curso_files WHERE id_curso=? ORDER BY modulo, categoria, original_name");
  $st->execute([$idCurso]);
  return $st->fetchAll();
}

function apont_arquivo_rotulo(?array $a): string {
  if (!$a || empty($a['arquivo_nome'])) return '';
  $mod = ((int)($a['arquivo_modulo'] ?? 0)) ? 'Módulo ' . (int)$a['arquivo_modulo'] : 'Geral';
  return $a['arquivo_nome'] . ' (' . $mod . ' • ' . ($a['arquivo_categoria'] ?? '') . ')';
}

/* ------------------------------------------------------------------
 * Permissões (backend)
 * ------------------------------------------------------------------ */

/** Pode criar/alterar status: equipe de revisão (TI) ou ADMIN. */
function apont_pode_gerir(array $user): bool {
  return !empty($user['role']) && perfil_flag($user['role'], 'revisa_cursos');
}

/** Pode responder (concordar/objetar/reenviar): professor do curso (responsável ou coautor) ou ADMIN. */
function apont_pode_responder(array $ap, array $user): bool {
  if (empty($user['id_user'])) return false;
  if (!empty($user['role']) && perfil_flag($user['role'], 'admin_total')) return true;
  $curso = ['id_curso' => (int)$ap['id_curso'], 'id_professor' => (int)$ap['id_professor']];
  return curso_eh_professor($curso, (int)$user['id_user']);
}

/** Ações disponíveis para o usuário neste apontamento (reflete as regras do backend). */
function apont_acoes(array $ap, array $user): array {
  $acoes = ['status_ti' => [], 'concordo' => false, 'objecao' => false, 'reenviar' => false];
  $s = $ap['status'];
  if (apont_pode_gerir($user)) {
    $acoes['status_ti'] = array_values(array_filter(APONT_STATUS_TI, fn($x) => $x !== $s));
  }
  if (apont_pode_responder($ap, $user)) {
    $acoes['concordo'] = in_array($s, ['PENDENTE_ANALISE', 'CORRECAO_SOLICITADA'], true);
    $acoes['objecao']  = in_array($s, ['PENDENTE_ANALISE', 'CORRECAO_SOLICITADA', 'EM_CORRECAO'], true);
    $acoes['reenviar'] = $s === 'EM_CORRECAO';
  }
  return $acoes;
}

/* ------------------------------------------------------------------
 * Operações (todas em transação, auditadas, com histórico e e-mail)
 * ------------------------------------------------------------------ */

function apont_hist_insert(int $idAp, ?string $de, string $para, ?int $idUser, ?string $obs): void {
  db()->prepare("INSERT INTO tb_apontamento_historico (id_apontamento, status_de, status_para, id_user, observacao) VALUES (?,?,?,?,?)")
    ->execute([$idAp, $de, $para, $idUser, $obs]);
}

/** Cria um apontamento (TI/ADMIN). Retorna o id. */
function apont_criar(array $curso, array $user, string $tipo, string $conteudo, ?int $idFile = null, bool $notificar = true): int {
  if (!apont_pode_gerir($user)) { http_response_code(403); throw new Exception("Sem permissão para registrar apontamentos."); }
  $conteudo = trim($conteudo);
  if ($conteudo === '') { http_response_code(422); throw new Exception("O texto do apontamento é obrigatório."); }
  if (!isset(APONT_TIPOS[$tipo])) $tipo = 'OUTRO';
  if ($idFile) {
    $chk = db()->prepare("SELECT COUNT(*) n FROM tb_curso_files WHERE id_file=? AND id_curso=?");
    $chk->execute([$idFile, (int)$curso['id_curso']]);
    if (!(int)$chk->fetch()['n']) { http_response_code(422); throw new Exception("Arquivo relacionado inválido para este curso."); }
  } else {
    $idFile = null;
  }

  db()->beginTransaction();
  try {
    db()->prepare("INSERT INTO tb_curso_apontamentos (id_curso, id_user, id_file, tipo, conteudo, resolvido, status) VALUES (?,?,?,?,?,0,'PENDENTE_ANALISE')")
      ->execute([(int)$curso['id_curso'], (int)$user['id_user'], $idFile, $tipo, $conteudo]);
    $id = (int)db()->lastInsertId();
    apont_hist_insert($id, null, 'PENDENTE_ANALISE', (int)$user['id_user'], 'Apontamento registrado');
    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }
  audit_log('apontamento_criado', 'apontamento', $id, null,
    ['id_curso' => (int)$curso['id_curso'], 'tipo' => $tipo, 'id_file' => $idFile, 'conteudo' => $conteudo]);

  if ($notificar) {
    $ap = apont_get($id);
    if ($ap) notify_apontamento_evento($ap, 'criado', $user, null, 'PENDENTE_ANALISE', null);
  }
  return $id;
}

/** Sincroniza a coluna antiga "resolvido" (compatibilidade com telas/relatórios antigos). */
function apont_set_status_db(int $id, string $status): void {
  $resolvido = APONT_STATUS[$status]['pendente'] ? 0 : 1;
  db()->prepare("UPDATE tb_curso_apontamentos SET status=?, resolvido=? WHERE id_apontamento=?")
    ->execute([$status, $resolvido, $id]);
}

/** TI/ADMIN altera o status (item 16/17). */
function apont_mudar_status(int $id, string $novo, array $user, ?string $obs = null): void {
  $ap = apont_get($id);
  if (!$ap) { http_response_code(404); throw new Exception("Apontamento não encontrado."); }
  if (!apont_pode_gerir($user)) { http_response_code(403); throw new Exception("Sem permissão para alterar o status do apontamento."); }
  if (!isset(APONT_STATUS[$novo]) || !in_array($novo, APONT_STATUS_TI, true)) { http_response_code(422); throw new Exception("Status inválido."); }
  if ($novo === $ap['status']) { http_response_code(422); throw new Exception("O apontamento já está em \"" . apont_status_label($novo) . "\"."); }
  $obs = trim((string)$obs) ?: null;

  db()->beginTransaction();
  try {
    apont_set_status_db($id, $novo);
    apont_hist_insert($id, $ap['status'], $novo, (int)$user['id_user'], $obs);
    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }
  audit_log('apontamento_status', 'apontamento', $id, ['status' => $ap['status']], ['status' => $novo, 'observacao' => $obs]);
  notify_apontamento_evento(apont_get($id) ?: $ap, 'status', $user, $ap['status'], $novo, $obs);
}

/** Professor: "Concordo com o apontamento" (item 19). */
function apont_concordar(int $id, array $user): void {
  $ap = apont_get($id);
  if (!$ap) { http_response_code(404); throw new Exception("Apontamento não encontrado."); }
  if (!apont_pode_responder($ap, $user)) { http_response_code(403); throw new Exception("Somente o(a) professor(a) do curso responde ao apontamento."); }
  if (!in_array($ap['status'], ['PENDENTE_ANALISE', 'CORRECAO_SOLICITADA'], true)) {
    http_response_code(422); throw new Exception("Este apontamento não está aguardando sua análise (situação: " . apont_status_label($ap['status']) . ").");
  }
  $novo = 'EM_CORRECAO';
  db()->beginTransaction();
  try {
    db()->prepare("INSERT INTO tb_apontamento_manifestacoes (id_apontamento, id_user, tipo, justificativa) VALUES (?,?,'CONCORDO',NULL)")
      ->execute([$id, (int)$user['id_user']]);
    apont_set_status_db($id, $novo);
    apont_hist_insert($id, $ap['status'], $novo, (int)$user['id_user'], 'Professor(a) concordou com o apontamento');
    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }
  audit_log('apontamento_concordancia', 'apontamento', $id, ['status' => $ap['status']], ['status' => $novo]);
  notify_apontamento_evento(apont_get($id) ?: $ap, 'concordo', $user, $ap['status'], $novo, null);
}

/** Professor: "Não concordo / Registrar objeção" — justificativa obrigatória; status não muda. */
function apont_objetar(int $id, array $user, string $justificativa): void {
  $ap = apont_get($id);
  if (!$ap) { http_response_code(404); throw new Exception("Apontamento não encontrado."); }
  if (!apont_pode_responder($ap, $user)) { http_response_code(403); throw new Exception("Somente o(a) professor(a) do curso responde ao apontamento."); }
  $justificativa = trim($justificativa);
  if (mb_strlen($justificativa) < 5) { http_response_code(422); throw new Exception("Informe a justificativa da objeção."); }
  if (!in_array($ap['status'], ['PENDENTE_ANALISE', 'CORRECAO_SOLICITADA', 'EM_CORRECAO'], true)) {
    http_response_code(422); throw new Exception("Este apontamento não aceita objeção na situação atual (" . apont_status_label($ap['status']) . ").");
  }
  db()->beginTransaction();
  try {
    db()->prepare("INSERT INTO tb_apontamento_manifestacoes (id_apontamento, id_user, tipo, justificativa) VALUES (?,?,'OBJECAO',?)")
      ->execute([$id, (int)$user['id_user'], $justificativa]);
    apont_hist_insert($id, $ap['status'], $ap['status'], (int)$user['id_user'], 'Objeção registrada: ' . $justificativa);
    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }
  audit_log('apontamento_objecao', 'apontamento', $id, null, ['justificativa' => $justificativa]);
  notify_apontamento_evento($ap, 'objecao', $user, $ap['status'], $ap['status'], $justificativa);
}

/** Professor: correção concluída → "Reenviado para análise". */
function apont_reenviar(int $id, array $user, ?string $obs = null): void {
  $ap = apont_get($id);
  if (!$ap) { http_response_code(404); throw new Exception("Apontamento não encontrado."); }
  if (!apont_pode_responder($ap, $user)) { http_response_code(403); throw new Exception("Somente o(a) professor(a) do curso reenvia o apontamento."); }
  if ($ap['status'] !== 'EM_CORRECAO') { http_response_code(422); throw new Exception("Só é possível reenviar apontamentos em correção."); }
  $obs = trim((string)$obs) ?: null;
  $novo = 'REENVIADO_ANALISE';
  db()->beginTransaction();
  try {
    apont_set_status_db($id, $novo);
    apont_hist_insert($id, $ap['status'], $novo, (int)$user['id_user'], $obs ?: 'Correção concluída pelo(a) professor(a)');
    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }
  audit_log('apontamento_reenviado', 'apontamento', $id, ['status' => $ap['status']], ['status' => $novo, 'observacao' => $obs]);
  notify_apontamento_evento(apont_get($id) ?: $ap, 'reenvio', $user, $ap['status'], $novo, $obs);
}

/** Linha do tempo: histórico de status + manifestações, em ordem cronológica. */
function apont_timeline(int $id): array {
  $h = db()->prepare("SELECT h.*, u.nome AS user_nome FROM tb_apontamento_historico h LEFT JOIN tb_users u ON u.id_user=h.id_user WHERE h.id_apontamento=? ORDER BY h.created_at, h.id_historico");
  $h->execute([$id]);
  $itens = [];
  foreach ($h->fetchAll() as $r) {
    if ($r['status_de'] === null) $acao = 'Apontamento criado';
    elseif ($r['status_de'] === $r['status_para']) $acao = 'Objeção do(a) professor(a)';
    else $acao = 'Status: ' . apont_status_label($r['status_de']) . ' → ' . apont_status_label($r['status_para']);
    $itens[] = ['data' => $r['created_at'], 'usuario' => $r['user_nome'] ?? '—', 'acao' => $acao,
                'observacao' => $r['observacao'], 'status' => $r['status_para'], 'tipo' => 'historico'];
  }
  // concordâncias/objeções já entram pelo histórico (com a justificativa na observação);
  // o detalhe completo das manifestações fica em apont_manifestacoes().
  return $itens;
}

/** Manifestações (concordância/objeção) — visíveis à TI/Admin e ao professor. */
function apont_manifestacoes(int $id): array {
  $m = db()->prepare("SELECT m.*, u.nome AS user_nome FROM tb_apontamento_manifestacoes m JOIN tb_users u ON u.id_user=m.id_user WHERE m.id_apontamento=? ORDER BY m.created_at DESC");
  $m->execute([$id]);
  return $m->fetchAll();
}
