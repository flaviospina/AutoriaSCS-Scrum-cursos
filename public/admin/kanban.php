<?php
require_once __DIR__ . '/_admin_top.php';

$erro = null; $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $nome = trim($_POST['nome'] ?? '');
      $cor  = $_POST['cor'] ?? '#e9ecef';
      $wip  = ($_POST['wip_limit'] ?? '') !== '' ? max(1, (int)$_POST['wip_limit']) : null;
      if ($nome === '') throw new Exception("Informe o nome da coluna.");
      if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) $cor = '#e9ecef';

      $max = (int)db()->query("SELECT COALESCE(MAX(ordem),0) m FROM tb_kanban_colunas")->fetch()['m'];
      db()->prepare("INSERT INTO tb_kanban_colunas (nome, cor, ordem, wip_limit, ativo) VALUES (?,?,?,?,1)")
        ->execute([$nome, $cor, $max + 1, $wip]);
      audit_log('kanban_coluna_criada', 'kanban_coluna', (int)db()->lastInsertId(), null,
        ['nome' => $nome, 'cor' => $cor, 'wip_limit' => $wip]);
      $ok = "Coluna \"{$nome}\" criada.";
    }

    if ($action === 'update') {
      $idc  = (int)($_POST['id_coluna'] ?? 0);
      $nome = trim($_POST['nome'] ?? '');
      $cor  = $_POST['cor'] ?? '#e9ecef';
      $wip  = ($_POST['wip_limit'] ?? '') !== '' ? max(1, (int)$_POST['wip_limit']) : null;
      $ativo = isset($_POST['ativo']) ? 1 : 0;
      if ($nome === '') throw new Exception("Informe o nome da coluna.");
      if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) $cor = '#e9ecef';

      $stA = db()->prepare("SELECT nome, cor, wip_limit, ativo FROM tb_kanban_colunas WHERE id_coluna=?");
      $stA->execute([$idc]);
      $antes = $stA->fetch() ?: null;

      db()->prepare("UPDATE tb_kanban_colunas SET nome=?, cor=?, wip_limit=?, ativo=? WHERE id_coluna=?")
        ->execute([$nome, $cor, $wip, $ativo, $idc]);
      audit_log('kanban_coluna_editada', 'kanban_coluna', $idc, $antes,
        ['nome' => $nome, 'cor' => $cor, 'wip_limit' => $wip, 'ativo' => $ativo]);
      $ok = "Coluna atualizada.";
    }

    if ($action === 'move') {
      $idc = (int)($_POST['id_coluna'] ?? 0);
      $dir = $_POST['dir'] === 'up' ? 'up' : 'down';

      $cols = db()->query("SELECT id_coluna, ordem FROM tb_kanban_colunas ORDER BY ordem, id_coluna")->fetchAll();
      foreach ($cols as $i => $c) {
        if ((int)$c['id_coluna'] === $idc) {
          $j = $dir === 'up' ? $i - 1 : $i + 1;
          if ($j >= 0 && $j < count($cols)) {
            $o1 = $cols[$i]['ordem']; $o2 = $cols[$j]['ordem'];
            if ($o1 == $o2) { $o2 = $o1 + ($dir === 'up' ? -1 : 1); }
            db()->prepare("UPDATE tb_kanban_colunas SET ordem=? WHERE id_coluna=?")->execute([$o2, $cols[$i]['id_coluna']]);
            db()->prepare("UPDATE tb_kanban_colunas SET ordem=? WHERE id_coluna=?")->execute([$o1, $cols[$j]['id_coluna']]);
          }
          break;
        }
      }
      $ok = "Ordem atualizada.";
    }

    if ($action === 'delete') {
      $idc = (int)($_POST['id_coluna'] ?? 0);
      $n = db()->prepare("SELECT COUNT(*) n FROM tb_status WHERE id_coluna=?");
      $n->execute([$idc]);
      if ((int)$n->fetch()['n'] > 0) {
        throw new Exception("Não é possível excluir: a coluna possui status vinculados. Mova ou exclua os status antes.");
      }
      db()->prepare("DELETE FROM tb_kanban_colunas WHERE id_coluna=?")->execute([$idc]);
      audit_log('kanban_coluna_excluida', 'kanban_coluna', $idc);
      $ok = "Coluna excluída.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$colunas = db()->query("
  SELECT k.*, (SELECT COUNT(*) FROM tb_status s WHERE s.id_coluna = k.id_coluna) AS n_status
  FROM tb_kanban_colunas k
  ORDER BY k.ordem, k.id_coluna
")->fetchAll();

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Colunas do Kanban</h1>
    <div class="text-muted small">Adicione, renomeie, reordene, defina cores e limite WIP. A ordem aqui é a ordem no quadro.</div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-3">Nova coluna</h2>
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-12 col-md-4">
        <label class="form-label small">Nome</label>
        <input class="form-control" name="nome" required maxlength="80" placeholder="Ex.: Homologação">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Cor do cabeçalho</label>
        <input class="form-control form-control-color w-100" type="color" name="cor" value="#e6f3f3">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Limite WIP (opcional)</label>
        <input class="form-control" type="number" name="wip_limit" min="1" placeholder="Sem limite">
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-primary w-100">Adicionar</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:70px">Ordem</th>
            <th>Coluna</th>
            <th>Cor</th>
            <th>WIP</th>
            <th>Status vinculados</th>
            <th>Ativa</th>
            <th class="text-end">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($colunas as $i => $c): ?>
            <tr>
              <td>
                <div class="btn-group-vertical btn-group-sm">
                  <form method="post" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="id_coluna" value="<?= (int)$c['id_coluna'] ?>">
                    <input type="hidden" name="dir" value="up">
                    <button class="btn btn-outline-secondary btn-sm" <?= $i===0?'disabled':'' ?>>▲</button>
                  </form>
                  <form method="post" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="id_coluna" value="<?= (int)$c['id_coluna'] ?>">
                    <input type="hidden" name="dir" value="down">
                    <button class="btn btn-outline-secondary btn-sm" <?= $i===count($colunas)-1?'disabled':'' ?>>▼</button>
                  </form>
                </div>
              </td>

              <td colspan="5">
                <form method="post" class="row g-2 align-items-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id_coluna" value="<?= (int)$c['id_coluna'] ?>">

                  <div class="col-12 col-md-4">
                    <input class="form-control form-control-sm" name="nome" value="<?= htmlspecialchars($c['nome']) ?>" required maxlength="80">
                  </div>
                  <div class="col-4 col-md-2">
                    <input class="form-control form-control-color form-control-sm w-100" type="color" name="cor" value="<?= htmlspecialchars($c['cor']) ?>">
                  </div>
                  <div class="col-4 col-md-2">
                    <input class="form-control form-control-sm" type="number" name="wip_limit" min="1"
                           value="<?= $c['wip_limit'] !== null ? (int)$c['wip_limit'] : '' ?>" placeholder="WIP">
                  </div>
                  <div class="col-2 col-md-1 text-center">
                    <span class="badge bg-secondary rounded-pill"><?= (int)$c['n_status'] ?></span>
                  </div>
                  <div class="col-2 col-md-1">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" name="ativo" <?= $c['ativo'] ? 'checked' : '' ?>>
                    </div>
                  </div>
                  <div class="col-12 col-md-2 text-end">
                    <button class="btn btn-sm btn-outline-primary">Salvar</button>
                  </div>
                </form>
              </td>

              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('Excluir a coluna <?= htmlspecialchars($c['nome']) ?>?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id_coluna" value="<?= (int)$c['id_coluna'] ?>">
                  <button class="btn btn-sm btn-outline-danger" <?= (int)$c['n_status'] > 0 ? 'disabled title="Possui status vinculados"' : '' ?>>Excluir</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="small text-muted mt-2">
  Colunas inativas não aparecem no quadro, mas mantêm seus status e histórico.
  O número em "Status vinculados" mostra quantos status pertencem à coluna — gerencie-os em <a href="status.php">Status</a>.
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
