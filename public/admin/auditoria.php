<?php
require_once __DIR__ . '/_admin_top.php';

// ---- filtros ----
$fUser  = trim($_GET['usuario'] ?? '');
$fAcao  = trim($_GET['acao'] ?? '');
$fEnt   = trim($_GET['entidade'] ?? '');
$fDe    = trim($_GET['de'] ?? '');
$fAte   = trim($_GET['ate'] ?? '');
$export = ($_GET['export'] ?? '') === 'csv';

$params = []; $where = [];
if ($fUser !== '') { $where[] = "(u.nome LIKE ? OR u.email LIKE ?)"; $params[] = "%$fUser%"; $params[] = "%$fUser%"; }
if ($fAcao !== '') { $where[] = "a.acao = ?"; $params[] = $fAcao; }
if ($fEnt !== '')  { $where[] = "a.entidade = ?"; $params[] = $fEnt; }
if ($fDe !== '')   { $where[] = "a.created_at >= ?"; $params[] = $fDe . " 00:00:00"; }
if ($fAte !== '')  { $where[] = "a.created_at <= ?"; $params[] = $fAte . " 23:59:59"; }
$sqlWhere = $where ? "WHERE " . implode(" AND ", $where) : "";

$acoes = db()->query("SELECT DISTINCT acao FROM tb_audit_log ORDER BY acao")->fetchAll();
$entidades = db()->query("SELECT DISTINCT entidade FROM tb_audit_log ORDER BY entidade")->fetchAll();

// ---- exportação CSV ----
if ($export) {
  $st = db()->prepare("
    SELECT a.created_at, COALESCE(u.nome,'(sem usuário)') usuario, COALESCE(u.email,'') email,
           a.acao, a.entidade, a.id_entidade, a.dados_antes, a.dados_depois, a.ip
    FROM tb_audit_log a
    LEFT JOIN tb_users u ON u.id_user = a.id_user
    $sqlWhere
    ORDER BY a.id_audit DESC
    LIMIT 20000
  ");
  $st->execute($params);

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="auditoria_' . date('Y-m-d_His') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF"); // BOM p/ Excel
  fputcsv($out, ['Data/hora','Usuário','E-mail','Ação','Entidade','ID','Antes','Depois','IP'], ';');
  while ($r = $st->fetch()) {
    fputcsv($out, [$r['created_at'],$r['usuario'],$r['email'],$r['acao'],$r['entidade'],
                   $r['id_entidade'],$r['dados_antes'],$r['dados_depois'],$r['ip']], ';');
  }
  fclose($out);
  exit;
}

// ---- listagem paginada ----
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$stC = db()->prepare("SELECT COUNT(*) n FROM tb_audit_log a LEFT JOIN tb_users u ON u.id_user=a.id_user $sqlWhere");
$stC->execute($params);
$total = (int)$stC->fetch()['n'];
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$st = db()->prepare("
  SELECT a.*, u.nome AS user_nome, u.email AS user_email
  FROM tb_audit_log a
  LEFT JOIN tb_users u ON u.id_user = a.id_user
  $sqlWhere
  ORDER BY a.id_audit DESC
  LIMIT $perPage OFFSET $offset
");
$st->execute($params);
$logs = $st->fetchAll();

function fmt_json(?string $j): string {
  if (!$j) return '';
  $arr = json_decode($j, true);
  if (!is_array($arr)) return htmlspecialchars($j);
  $parts = [];
  foreach ($arr as $k => $v) {
    $parts[] = "<b>" . htmlspecialchars($k) . ":</b> " . htmlspecialchars(is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
  }
  return implode('<br>', $parts);
}

function qsa(array $extra = []): string { return http_build_query(array_merge($_GET, $extra)); }

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Auditoria</h1>
    <div class="text-muted small">Registro de todas as ações do sistema: quem fez, o quê, quando e de onde.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-success" href="?<?= qsa(['export'=>'csv']) ?>">Exportar CSV</a>
    <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2">
      <div class="col-12 col-md-3">
        <label class="form-label small">Usuário (nome/e-mail)</label>
        <input class="form-control form-control-sm" name="usuario" value="<?= htmlspecialchars($fUser) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Ação</label>
        <select class="form-select form-select-sm" name="acao">
          <option value="">Todas</option>
          <?php foreach ($acoes as $a): ?>
            <option value="<?= htmlspecialchars($a['acao']) ?>" <?= $fAcao===$a['acao']?'selected':'' ?>><?= htmlspecialchars($a['acao']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Entidade</label>
        <select class="form-select form-select-sm" name="entidade">
          <option value="">Todas</option>
          <?php foreach ($entidades as $e): ?>
            <option value="<?= htmlspecialchars($e['entidade']) ?>" <?= $fEnt===$e['entidade']?'selected':'' ?>><?= htmlspecialchars($e['entidade']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">De</label>
        <input class="form-control form-control-sm" type="date" name="de" value="<?= htmlspecialchars($fDe) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Até</label>
        <input class="form-control form-control-sm" type="date" name="ate" value="<?= htmlspecialchars($fAte) ?>">
      </div>
      <div class="col-12 col-md-1 d-flex align-items-end">
        <button class="btn btn-primary btn-sm w-100">Filtrar</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Data/hora</th>
            <th>Usuário</th>
            <th>Ação</th>
            <th>Entidade</th>
            <th>Detalhes (antes → depois)</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$logs): ?>
            <tr><td colspan="6" class="text-center text-muted p-4">Nenhum registro encontrado.</td></tr>
          <?php else: ?>
            <?php foreach ($logs as $l): ?>
              <tr>
                <td class="text-nowrap small"><?= htmlspecialchars($l['created_at']) ?></td>
                <td class="small">
                  <?= htmlspecialchars($l['user_nome'] ?? '(sem usuário)') ?><br>
                  <span class="text-muted"><?= htmlspecialchars($l['user_email'] ?? '') ?></span>
                </td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($l['acao']) ?></span></td>
                <td class="small text-nowrap">
                  <?= htmlspecialchars($l['entidade']) ?><?= $l['id_entidade'] !== null ? ' #' . (int)$l['id_entidade'] : '' ?>
                </td>
                <td class="small">
                  <?php $antes = fmt_json($l['dados_antes']); $depois = fmt_json($l['dados_depois']); ?>
                  <?php if ($antes): ?><div class="text-muted"><?= $antes ?></div><?php endif; ?>
                  <?php if ($antes && $depois): ?><div class="text-center text-muted">↓</div><?php endif; ?>
                  <?php if ($depois): ?><div><?= $depois ?></div><?php endif; ?>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($l['ip'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center p-3">
      <div class="small text-muted">Total: <?= $total ?> • Página <?= $page ?> de <?= $totalPages ?></div>
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= qsa(['page'=>$page-1]) ?>">Anterior</a></li>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= qsa(['page'=>$page+1]) ?>">Próxima</a></li>
      </ul>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
