<?php
require_once __DIR__ . '/_admin_top.php';

$erro = null; $ok = null;
$rolesFluxo = ['PROFESSOR', 'TI', 'MB']; // ADMIN não precisa: pode tudo

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $role = $_POST['role'] ?? '';
      $de   = (int)($_POST['id_status_de'] ?? 0);
      $para = (int)($_POST['id_status_para'] ?? 0);
      if (!in_array($role, $rolesFluxo, true)) throw new Exception("Perfil inválido.");
      if ($de === $para) throw new Exception("Origem e destino não podem ser iguais.");

      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_status_transicoes WHERE role=? AND id_status_de=? AND id_status_para=?");
      $dup->execute([$role, $de, $para]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Essa transição já existe para o perfil.");

      db()->prepare("INSERT INTO tb_status_transicoes (role, id_status_de, id_status_para) VALUES (?,?,?)")
        ->execute([$role, $de, $para]);
      audit_log('transicao_criada', 'transicao', (int)db()->lastInsertId(), null,
        ['role' => $role, 'de' => $de, 'para' => $para]);
      $ok = "Transição criada.";
    }

    if ($action === 'delete') {
      $idt = (int)($_POST['id_transicao'] ?? 0);
      db()->prepare("DELETE FROM tb_status_transicoes WHERE id_transicao=?")->execute([$idt]);
      audit_log('transicao_removida', 'transicao', $idt);
      $ok = "Transição removida.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$statusOpts = db()->query("SELECT id_status, nome, ativo FROM tb_status ORDER BY ordem, id_status")->fetchAll();

$trans = db()->query("
  SELECT t.*, sd.nome AS de_nome, sp.nome AS para_nome
  FROM tb_status_transicoes t
  JOIN tb_status sd ON sd.id_status = t.id_status_de
  JOIN tb_status sp ON sp.id_status = t.id_status_para
  ORDER BY FIELD(t.role,'PROFESSOR','TI','MB'), sd.ordem, sp.ordem
")->fetchAll();

$byRole = [];
foreach ($trans as $t) $byRole[$t['role']][] = $t;

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Transições por perfil</h1>
    <div class="text-muted small">
      Defina quais movimentações de status cada perfil pode realizar. O perfil ADMIN pode realizar qualquer transição.
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-3">Nova transição</h2>
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-12 col-md-2">
        <label class="form-label small">Perfil</label>
        <select class="form-select" name="role" required>
          <?php foreach ($rolesFluxo as $r): ?>
            <option value="<?= $r ?>"><?= $r ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small">De (status de origem)</label>
        <select class="form-select" name="id_status_de" required>
          <?php foreach ($statusOpts as $s): ?>
            <option value="<?= (int)$s['id_status'] ?>"><?= htmlspecialchars($s['nome']) ?><?= $s['ativo'] ? '' : ' (inativo)' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small">Para (status de destino)</label>
        <select class="form-select" name="id_status_para" required>
          <?php foreach ($statusOpts as $s): ?>
            <option value="<?= (int)$s['id_status'] ?>"><?= htmlspecialchars($s['nome']) ?><?= $s['ativo'] ? '' : ' (inativo)' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-primary w-100">Adicionar</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3">
  <?php foreach ($rolesFluxo as $r): ?>
    <div class="col-12 col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-white fw-semibold"><?= $r ?>
          <span class="badge bg-secondary rounded-pill ms-1"><?= count($byRole[$r] ?? []) ?></span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($byRole[$r])): ?>
            <div class="p-3 text-muted small">Nenhuma transição cadastrada.</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($byRole[$r] as $t): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2 small">
                  <span>
                    <span class="badge" style="<?= status_badge_style($t['de_nome']) ?>"><?= htmlspecialchars($t['de_nome']) ?></span>
                    →
                    <span class="badge" style="<?= status_badge_style($t['para_nome']) ?>"><?= htmlspecialchars($t['para_nome']) ?></span>
                  </span>
                  <form method="post" onsubmit="return confirm('Remover esta transição?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id_transicao" value="<?= (int)$t['id_transicao'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0">×</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
