<?php
/**
 * Checklists por perfil (V11, item 8): "Checklist do Professor" e
 * "Checklist TI/Admin", com itens administráveis (tb_checklists,
 * tb_checklist_itens) e respostas por curso (tb_curso_checklist_respostas).
 *
 * O backend decide quais checklists cada usuário pode ver/editar; a tela
 * recebe somente os itens autorizados (não é ocultação por CSS/JS).
 * A tabela antiga tb_curso_checklist continua sendo espelhada para o
 * checklist do professor (compatibilidade com relatórios antigos).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/curso_repo.php';

/** As tabelas da V11 existem? (cache por requisição) */
function checklists_disponivel(): bool {
  static $ok = null;
  if ($ok === null) {
    try { db()->query("SELECT 1 FROM tb_checklist_itens LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
  }
  return $ok;
}

function checklists_all(): array {
  $out = [];
  foreach (db()->query("SELECT * FROM tb_checklists ORDER BY id_checklist") as $c) $out[$c['codigo']] = $c;
  return $out;
}

/**
 * Códigos de checklist que o usuário pode VER neste curso (matriz do item 24):
 *  PROFESSOR (do curso) → PROFESSOR • TI → TI • ADMIN → ambos • demais → nenhum.
 */
function checklists_visiveis(array $user, array $curso): array {
  if (empty($user['role'])) return [];
  if (perfil_flag($user['role'], 'admin_total')) return ['PROFESSOR', 'TI'];
  $out = [];
  if (perfil_flag($user['role'], 'revisa_cursos')) $out[] = 'TI';
  elseif (perfil_flag($user['role'], 'propoe_cursos') && curso_eh_professor($curso, (int)$user['id_user'])) $out[] = 'PROFESSOR';
  return $out;
}

/** Pode EDITAR o checklist? (mesma regra da visualização; MB nunca edita) */
function checklist_pode_editar(string $codigo, array $user, array $curso): bool {
  return in_array($codigo, checklists_visiveis($user, $curso), true);
}

/** Chave de comparação de grupo: sem acentos/caixa/espaços duplicados ("Revisão Pedagógica" = "revisao pedagogica"). */
function checklist_grupo_chave(string $grupo): string {
  $g = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $grupo)));
  $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $g);
  return $t !== false && $t !== '' ? preg_replace('/[^a-z0-9 ]/', '', $t) : $g;
}

/**
 * Renumera a ordem dos itens de um checklist para que os itens do mesmo grupo
 * fiquem contíguos (grupos na ordem em que aparecem; itens na ordem atual).
 * Também unifica a grafia do grupo pela primeira ocorrência.
 */
function checklist_reordenar_por_grupo(int $idChecklist): void {
  $st = db()->prepare("SELECT id_item, grupo FROM tb_checklist_itens WHERE id_checklist=? ORDER BY ordem, id_item");
  $st->execute([$idChecklist]);
  $grupos = []; // chave => ['nome' => grafia, 'itens' => [ids]]
  foreach ($st->fetchAll() as $r) {
    $k = checklist_grupo_chave((string)$r['grupo']);
    if (!isset($grupos[$k])) $grupos[$k] = ['nome' => trim(preg_replace('/\s+/u', ' ', (string)$r['grupo'])), 'itens' => []];
    $grupos[$k]['itens'][] = (int)$r['id_item'];
  }
  $up = db()->prepare("UPDATE tb_checklist_itens SET ordem=?, grupo=? WHERE id_item=?");
  $n = 1;
  foreach ($grupos as $g) foreach ($g['itens'] as $id) $up->execute([$n++, $g['nome'], $id]);
}

/** Itens ativos do checklist, em ordem, com os grupos contíguos (mesmo grupo = mesmo bloco). */
function checklist_itens(string $codigo, bool $incluirInativos = false): array {
  $st = db()->prepare("
    SELECT i.* FROM tb_checklist_itens i
    JOIN tb_checklists c ON c.id_checklist = i.id_checklist
    WHERE c.codigo = ? " . ($incluirInativos ? '' : 'AND i.ativo = 1') . "
    ORDER BY i.ordem, i.id_item
  ");
  $st->execute([$codigo]);
  $rows = $st->fetchAll();
  // agrupa por grupo normalizado, preservando a ordem de aparição dos grupos e dos itens
  $grupos = [];
  foreach ($rows as $r) {
    $k = checklist_grupo_chave((string)$r['grupo']);
    if (!isset($grupos[$k])) $grupos[$k] = ['nome' => trim(preg_replace('/\s+/u', ' ', (string)$r['grupo'])), 'itens' => []];
    $r['grupo'] = $grupos[$k]['nome'];
    $grupos[$k]['itens'][] = $r;
  }
  $out = [];
  foreach ($grupos as $g) foreach ($g['itens'] as $r) $out[] = $r;
  return $out;
}

/** Respostas do curso para um checklist: id_item => marcado(0/1). */
function checklist_respostas(int $idCurso, string $codigo): array {
  $st = db()->prepare("
    SELECT r.id_item, r.marcado
    FROM tb_curso_checklist_respostas r
    JOIN tb_checklist_itens i ON i.id_item = r.id_item
    JOIN tb_checklists c ON c.id_checklist = i.id_checklist
    WHERE r.id_curso = ? AND c.codigo = ?
  ");
  $st->execute([$idCurso, $codigo]);
  $out = [];
  foreach ($st->fetchAll() as $r) $out[(int)$r['id_item']] = (int)$r['marcado'];
  return $out;
}

/**
 * Salva as marcações (somente itens do checklist autorizado). IDs de outro
 * checklist são recusados (422). Transação + auditoria (antes/depois).
 */
function checklist_salvar(int $idCurso, string $codigo, array $marcados, array $user, array $curso): array {
  if (!checklist_pode_editar($codigo, $user, $curso)) {
    http_response_code(403); throw new Exception("Sem permissão para editar este checklist.");
  }
  $itens = checklist_itens($codigo);
  $validos = array_map(fn($i) => (int)$i['id_item'], $itens);
  $marcados = array_map('intval', $marcados);
  foreach ($marcados as $m) {
    if (!in_array($m, $validos, true)) { http_response_code(422); throw new Exception("Item de checklist inválido para este perfil."); }
  }
  $antes = checklist_respostas($idCurso, $codigo);

  db()->beginTransaction();
  try {
    $up = db()->prepare("INSERT INTO tb_curso_checklist_respostas (id_curso, id_item, marcado, id_user) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE marcado=VALUES(marcado), id_user=VALUES(id_user)");
    $depois = [];
    foreach ($itens as $i) {
      $v = in_array((int)$i['id_item'], $marcados, true) ? 1 : 0;
      $up->execute([$idCurso, (int)$i['id_item'], $v, (int)$user['id_user']]);
      $depois[(int)$i['id_item']] = $v;
    }
    // espelho na tabela antiga (checklist do professor): mantém relatórios/telas antigas
    if ($codigo === 'PROFESSOR') {
      $sets = []; $vals = [];
      foreach ($itens as $i) {
        if (!empty($i['chave']) && preg_match('/^[a-z_]+$/', $i['chave'])) { $sets[] = "{$i['chave']}=?"; $vals[] = $depois[(int)$i['id_item']]; }
      }
      if ($sets) {
        db()->prepare("INSERT IGNORE INTO tb_curso_checklist (id_curso) VALUES (?)")->execute([$idCurso]);
        $vals[] = $idCurso;
        try { db()->prepare("UPDATE tb_curso_checklist SET " . implode(',', $sets) . " WHERE id_curso=?")->execute($vals); }
        catch (Throwable $e) { /* coluna antiga inexistente para item novo: ignora */ }
      }
    }
    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }

  $labels = [];
  foreach ($itens as $i) $labels[(int)$i['id_item']] = $i['descricao'];
  $da = []; $dd = [];
  foreach ($depois as $idItem => $v) {
    if ((int)($antes[$idItem] ?? 0) !== $v) { $da[$labels[$idItem]] = (int)($antes[$idItem] ?? 0); $dd[$labels[$idItem]] = $v; }
  }
  if ($dd) audit_log('checklist_atualizado', 'curso', $idCurso, ['checklist' => $codigo] + $da, ['checklist' => $codigo] + $dd);
  return $depois;
}

/** Resumo (marcados/total) de um checklist do curso. */
function checklist_progresso(int $idCurso, string $codigo): array {
  $itens = checklist_itens($codigo);
  $resp = checklist_respostas($idCurso, $codigo);
  $n = 0;
  foreach ($itens as $i) if (!empty($resp[(int)$i['id_item']])) $n++;
  return ['marcados' => $n, 'total' => count($itens)];
}
