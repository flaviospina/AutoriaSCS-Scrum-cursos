<?php
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/notify.php';

$erro = null; $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'reenviar') {
      $idn = (int)($_POST['id_notificacao'] ?? 0);
      db()->prepare("UPDATE tb_notificacoes SET status='PENDENTE', tentativas=0, erro_msg=NULL WHERE id_notificacao=?")
        ->execute([$idn]);
      $ok = "Notificação recolocada na fila.";
    }
    if ($action === 'processar') {
      [$okN, $errN] = notify_send_pending(25);
      $ok = "Processamento manual: {$okN} enviada(s), {$errN} com erro.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$fStatus = trim($_GET['status'] ?? '');
$params = []; $where = [];
if ($fStatus !== '' && in_array($fStatus, ['PENDENTE','ENVIADO','ERRO'], true)) {
  $where[] = "status=?"; $params[] = $fStatus;
}
$sqlWhere = $where ? "WHERE " . implode(" AND ", $where) : "";

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$stC = db()->prepare("SELECT COUNT(*) n FROM tb_notificacoes $sqlWhere");
$stC->execute($params);
$total = (int)$stC->fetch()['n'];
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$st = db()->prepare("SELECT * FROM tb_notificacoes $sqlWhere ORDER BY id_notificacao DESC LIMIT $perPage OFFSET $offset");
$st->execute($params);
$notifs = $st->fetchAll();

$resumo = [];
foreach (db()->query("SELECT status, COUNT(*) n FROM tb_notificacoes GROUP BY status")->fetchAll() as $r) {
  $resumo[$r['status']] = (int)$r['n'];
}

$cfgMail = (require __DIR__ . '/../../app/config.php')['mail'] ?? [];

function qsn(array $extra = []): string { return http_build_query(array_merge($_GET, $extra)); }

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Fila de Notificações (e-mail)</h1>
    <div class="text-muted small">
      Método de envio: <b><?= htmlspecialchars($cfgMail['method'] ?? 'disabled') ?></b> •
      Remetente: <?= htmlspecialchars($cfgMail['from_email'] ?? '-') ?> •
      Processada pelo cron a cada 5 min.
    </div>
  </div>
  <div class="d-flex gap-2">
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="action" value="processar">
      <button class="btn btn-outline-success">Processar fila agora</button>
    </form>
    <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
  </div>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="d-flex gap-2 mb-3">
  <a class="btn btn-sm <?= $fStatus===''?'btn-primary':'btn-outline-primary' ?>" href="notificacoes.php">Todas (<?= $total = array_sum($resumo) ?>)</a>
  <a class="btn btn-sm <?= $fStatus==='PENDENTE'?'btn-primary':'btn-outline-primary' ?>" href="?status=PENDENTE">Pendentes (<?= $resumo['PENDENTE'] ?? 0 ?>)</a>
  <a class="btn btn-sm <?= $fStatus==='ENVIADO'?'btn-primary':'btn-outline-primary' ?>" href="?status=ENVIADO">Enviadas (<?= $resumo['ENVIADO'] ?? 0 ?>)</a>
  <a class="btn btn-sm <?= $fStatus==='ERRO'?'btn-primary':'btn-outline-primary' ?>" href="?status=ERRO">Com erro (<?= $resumo['ERRO'] ?? 0 ?>)</a>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Criada</th>
            <th>Destinatário</th>
            <th>Assunto</th>
            <th>Status</th>
            <th>Enviada</th>
            <th class="text-end">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$notifs): ?>
            <tr><td colspan="7" class="text-center text-muted p-4">Nenhuma notificação.</td></tr>
          <?php else: ?>
            <?php foreach ($notifs as $n): ?>
              <tr>
                <td><?= (int)$n['id_notificacao'] ?></td>
                <td class="small text-nowrap"><?= htmlspecialchars($n['created_at']) ?></td>
                <td class="small">
                  <?= htmlspecialchars($n['destinatario_nome']) ?><br>
                  <span class="text-muted"><?= htmlspecialchars($n['destinatario_email']) ?></span>
                </td>
                <td class="small"><?= htmlspecialchars($n['assunto']) ?></td>
                <td>
                  <?php if ($n['status'] === 'ENVIADO'): ?>
                    <span class="badge bg-success">Enviado</span>
                  <?php elseif ($n['status'] === 'ERRO'): ?>
                    <span class="badge bg-danger" title="<?= htmlspecialchars($n['erro_msg'] ?? '') ?>">
                      Erro (<?= (int)$n['tentativas'] ?>x)
                    </span>
                    <?php if (!empty($n['erro_msg'])): ?>
                      <div class="small text-danger"><?= htmlspecialchars(mb_strimwidth($n['erro_msg'], 0, 80, '...')) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge bg-warning text-dark">Pendente</span>
                    <?php if (!empty($n['send_after'])): ?>
                      <div class="small text-muted">agendada: <?= htmlspecialchars($n['send_after']) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="small text-nowrap"><?= htmlspecialchars($n['sent_at'] ?? '-') ?></td>
                <td class="text-end">
                  <?php if ($n['status'] !== 'ENVIADO'): ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="reenviar">
                      <input type="hidden" name="id_notificacao" value="<?= (int)$n['id_notificacao'] ?>">
                      <button class="btn btn-sm btn-outline-primary py-0">Reenfileirar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center p-3">
      <div class="small text-muted">Página <?= $page ?> de <?= $totalPages ?></div>
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= qsn(['page'=>$page-1]) ?>">Anterior</a></li>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= qsn(['page'=>$page+1]) ?>">Próxima</a></li>
      </ul>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
