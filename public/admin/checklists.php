<?php
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/checklist_repo.php';

$erro = null; $ok = null;

if (!checklists_disponivel()) {
  include __DIR__ . '/../_layout_top.php';
  echo '<div class="alert alert-warning">As tabelas de checklist (V11) ainda não existem. Execute <code>database/upgrade_v11.sql</code> no phpMyAdmin.</div>';
  include __DIR__ . '/../_layout_bottom.php';
  exit;
}

$listas = checklists_all();

function chk_item_get(int $id): ?array {
  $st = db()->prepare("SELECT i.*, c.codigo FROM tb_checklist_itens i JOIN tb_checklists c ON c.id_checklist=i.id_checklist WHERE i.id_item=?");
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $codigo = $_POST['codigo'] ?? '';
      if (!isset($listas[$codigo])) throw new Exception("Checklist inválido.");
      $desc = trim(preg_replace('/\s+/', ' ', $_POST['descricao'] ?? ''));
      $grupo = trim(preg_replace('/\s+/', ' ', $_POST['grupo'] ?? ''));
      if (mb_strlen($desc) < 3) throw new Exception("Informe a descrição do item.");
      $idc = (int)$listas[$codigo]['id_checklist'];
      $mx = db()->prepare("SELECT COALESCE(MAX(ordem),0) m FROM tb_checklist_itens WHERE id_checklist=?");
      $mx->execute([$idc]);
      $ordem = (int)$mx->fetch()['m'] + 1;
      db()->prepare("INSERT INTO tb_checklist_itens (id_checklist, chave, grupo, descricao, obrigatorio, ordem, ativo) VALUES (?,NULL,?,?,?,?,1)")
        ->execute([$idc, mb_substr($grupo, 0, 80), mb_substr($desc, 0, 200), !empty($_POST['obrigatorio']) ? 1 : 0, $ordem]);
      audit_log('checklist_item_criado', 'checklist_item', (int)db()->lastInsertId(), null, ['checklist' => $codigo, 'grupo' => $grupo, 'descricao' => $desc]);
      $ok = "Item adicionado ao {$listas[$codigo]['nome']}.";
    }

    if ($action === 'update') {
      $i = chk_item_get((int)($_POST['id_item'] ?? 0));
      if (!$i) throw new Exception("Item não encontrado.");
      $desc = trim(preg_replace('/\s+/', ' ', $_POST['descricao'] ?? ''));
      $grupo = trim(preg_replace('/\s+/', ' ', $_POST['grupo'] ?? ''));
      if (mb_strlen($desc) < 3) throw new Exception("Informe a descrição do item.");
      db()->prepare("UPDATE tb_checklist_itens SET descricao=?, grupo=?, obrigatorio=? WHERE id_item=?")
        ->execute([mb_substr($desc, 0, 200), mb_substr($grupo, 0, 80), !empty($_POST['obrigatorio']) ? 1 : 0, (int)$i['id_item']]);
      audit_log('checklist_item_editado', 'checklist_item', (int)$i['id_item'],
        ['descricao' => $i['descricao'], 'grupo' => $i['grupo'], 'obrigatorio' => (int)$i['obrigatorio']],
        ['descricao' => $desc, 'grupo' => $grupo, 'obrigatorio' => !empty($_POST['obrigatorio']) ? 1 : 0]);
      $ok = "Item atualizado.";
    }

    if ($action === 'toggle') {
      $i = chk_item_get((int)($_POST['id_item'] ?? 0));
      if (!$i) throw new Exception("Item não encontrado.");
      $novo = $i['ativo'] ? 0 : 1;
      db()->prepare("UPDATE tb_checklist_itens SET ativo=? WHERE id_item=?")->execute([$novo, (int)$i['id_item']]);
      audit_log($novo ? 'checklist_item_reativado' : 'checklist_item_inativado', 'checklist_item', (int)$i['id_item'], ['ativo' => (int)$i['ativo']], ['ativo' => $novo, 'descricao' => $i['descricao']]);
      $ok = "Item " . ($novo ? "reativado." : "inativado (as respostas já dadas são preservadas).");
    }

    if ($action === 'mover') {
      $i = chk_item_get((int)($_POST['id_item'] ?? 0));
      if (!$i) throw new Exception("Item não encontrado.");
      $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
      $st = db()->prepare("SELECT id_item FROM tb_checklist_itens WHERE id_checklist=? ORDER BY ordem, id_item");
      $st->execute([(int)$i['id_checklist']]);
      $ids = array_map('intval', array_column($st->fetchAll(), 'id_item'));
      $pos = array_search((int)$i['id_item'], $ids, true);
      $alvo = $pos + $dir;
      if ($pos !== false && isset($ids[$alvo])) {
        [$ids[$pos], $ids[$alvo]] = [$ids[$alvo], $ids[$pos]];
        db()->beginTransaction();
        try {
          $up = db()->prepare("UPDATE tb_checklist_itens SET ordem=? WHERE id_item=?");
          foreach ($ids as $k => $idI) $up->execute([$k + 1, $idI]);
          db()->commit();
        } catch (Throwable $ex) { db()->rollBack(); throw $ex; }
      }
      $ok = "Ordem atualizada.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Checklists</h1>
    <div class="text-muted small">
      Dois modelos: <b>Checklist do Professor</b> (visível ao formador) e <b>Checklist TI/Admin</b> (critérios internos).
      O sistema entrega a cada perfil somente os itens do seu checklist.
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <?php foreach ($listas as $codigo => $lista): $itens = checklist_itens($codigo, true); $tot = count($itens); ?>
    <div class="col-12 col-xl-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h2 class="h6 mb-1"><?= htmlspecialchars($lista['nome']) ?></h2>
          <div class="small text-muted mb-3">Perfil: <b><?= htmlspecialchars($lista['perfil_destino'] === 'TI' ? 'TI / ADMIN' : 'PROFESSOR') ?></b> • <?= $tot ?> item(ns)</div>

          <form method="post" class="row g-2 mb-3" data-confirm="Adicionar este item ao checklist?" data-confirm-title="Novo item" data-confirm-btn="Sim, adicionar">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="codigo" value="<?= htmlspecialchars($codigo) ?>">
            <div class="col-12 col-md-4"><input class="form-control form-control-sm" name="grupo" maxlength="80" placeholder="Grupo (ex.: Planejamento)"></div>
            <div class="col-12 col-md-6"><input class="form-control form-control-sm" name="descricao" maxlength="200" required placeholder="Descrição do item"></div>
            <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Adicionar</button></div>
          </form>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light"><tr><th style="width:80px">Ordem</th><th>Grupo / Item</th><th>Situação</th><th class="text-end">Ações</th></tr></thead>
              <tbody>
                <?php foreach ($itens as $i => $it): ?>
                  <tr class="<?= !$it['ativo'] ? 'opacity-75' : '' ?>">
                    <td class="text-nowrap">
                      <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="mover">
                        <input type="hidden" name="id_item" value="<?= (int)$it['id_item'] ?>">
                        <button class="btn btn-sm btn-outline-secondary py-0" name="dir" value="up" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
                        <button class="btn btn-sm btn-outline-secondary py-0" name="dir" value="down" <?= $i === $tot - 1 ? 'disabled' : '' ?>>▼</button>
                      </form>
                    </td>
                    <td>
                      <form method="post" class="row g-1 align-items-center" data-confirm="Salvar as alterações deste item?" data-confirm-title="Editar item" data-confirm-btn="Sim, salvar">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id_item" value="<?= (int)$it['id_item'] ?>">
                        <div class="col-4"><input class="form-control form-control-sm" name="grupo" value="<?= htmlspecialchars($it['grupo']) ?>" maxlength="80" placeholder="Grupo"></div>
                        <div class="col-6"><input class="form-control form-control-sm" name="descricao" value="<?= htmlspecialchars($it['descricao']) ?>" maxlength="200" required></div>
                        <div class="col-2"><button class="btn btn-sm btn-outline-primary py-0 w-100">Salvar</button></div>
                      </form>
                    </td>
                    <td><?= $it['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-warning text-dark">Inativo</span>' ?></td>
                    <td class="text-end text-nowrap">
                      <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id_item" value="<?= (int)$it['id_item'] ?>">
                        <button class="btn btn-sm btn-outline-warning py-0"><?= $it['ativo'] ? 'Inativar' : 'Reativar' ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">Itens não são excluídos: <b>inative</b> para retirá-los da tela mantendo as respostas já registradas.</div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
