<?php
require_once __DIR__ . '/_admin_top.php';

$erro = null; $ok = null;

function status_em_uso(int $id_status): int {
  $s = db()->prepare("SELECT nome FROM tb_status WHERE id_status=?");
  $s->execute([$id_status]);
  $nome = $s->fetch()['nome'] ?? '';
  if ($nome === '') return 0;
  $n = db()->prepare("SELECT COUNT(*) n FROM tb_cursos WHERE status_atual=?");
  $n->execute([$nome]);
  return (int)$n->fetch()['n'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $nome = trim($_POST['nome'] ?? '');
      $idc  = (int)($_POST['id_coluna'] ?? 0);
      $cor  = $_POST['cor'] ?? '#6c757d';
      if ($nome === '') throw new Exception("Informe o nome do status.");
      if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) $cor = '#6c757d';

      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_status WHERE nome=?");
      $dup->execute([$nome]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe um status com esse nome.");

      $max = (int)db()->query("SELECT COALESCE(MAX(ordem),0) m FROM tb_status")->fetch()['m'];
      db()->prepare("INSERT INTO tb_status (nome, id_coluna, cor, ordem, is_inicial, is_final, ativo) VALUES (?,?,?,?,0,0,1)")
        ->execute([$nome, $idc, $cor, $max + 1]);
      audit_log('status_criado', 'status', (int)db()->lastInsertId(), null,
        ['nome' => $nome, 'id_coluna' => $idc, 'cor' => $cor]);
      $ok = "Status \"{$nome}\" criado. Configure as transições em Transições por perfil.";
    }

    if ($action === 'update') {
      $ids  = (int)($_POST['id_status'] ?? 0);
      $nome = trim($_POST['nome'] ?? '');
      $idc  = (int)($_POST['id_coluna'] ?? 0);
      $cor  = $_POST['cor'] ?? '#6c757d';
      $ativo = isset($_POST['ativo']) ? 1 : 0;
      $isFinal = isset($_POST['is_final']) ? 1 : 0;
      if ($nome === '') throw new Exception("Informe o nome do status.");
      if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) $cor = '#6c757d';

      $atual = db()->prepare("SELECT nome FROM tb_status WHERE id_status=?");
      $atual->execute([$ids]);
      $nomeAntigo = $atual->fetch()['nome'] ?? null;
      if ($nomeAntigo === null) throw new Exception("Status não encontrado.");

      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_status WHERE nome=? AND id_status<>?");
      $dup->execute([$nome, $ids]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe outro status com esse nome.");

      db()->beginTransaction();
      try {
        db()->prepare("UPDATE tb_status SET nome=?, id_coluna=?, cor=?, ativo=?, is_final=? WHERE id_status=?")
          ->execute([$nome, $idc, $cor, $ativo, $isFinal, $ids]);

        // renomear propaga para cursos e histórico (integridade dos dados)
        if ($nome !== $nomeAntigo) {
          db()->prepare("UPDATE tb_cursos SET status_atual=? WHERE status_atual=?")->execute([$nome, $nomeAntigo]);
          db()->prepare("UPDATE tb_curso_status_history SET status_de=? WHERE status_de=?")->execute([$nome, $nomeAntigo]);
          db()->prepare("UPDATE tb_curso_status_history SET status_para=? WHERE status_para=?")->execute([$nome, $nomeAntigo]);
        }
        db()->commit();
      } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
      }
      audit_log('status_editado', 'status', $ids,
        ['nome' => $nomeAntigo],
        ['nome' => $nome, 'id_coluna' => $idc, 'cor' => $cor, 'ativo' => $ativo, 'is_final' => $isFinal]);
      $ok = "Status atualizado." . ($nome !== $nomeAntigo ? " Cursos e histórico foram renomeados automaticamente." : "");
    }

    if ($action === 'set_inicial') {
      $ids = (int)($_POST['id_status'] ?? 0);
      db()->beginTransaction();
      try {
        db()->exec("UPDATE tb_status SET is_inicial=0");
        db()->prepare("UPDATE tb_status SET is_inicial=1, ativo=1 WHERE id_status=?")->execute([$ids]);
        db()->commit();
      } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
      }
      audit_log('status_inicial_definido', 'status', $ids);
      $ok = "Status inicial definido.";
    }

    if ($action === 'move') {
      $ids = (int)($_POST['id_status'] ?? 0);
      $dir = $_POST['dir'] === 'up' ? 'up' : 'down';
      $sts = db()->query("SELECT id_status, ordem FROM tb_status ORDER BY ordem, id_status")->fetchAll();
      foreach ($sts as $i => $s) {
        if ((int)$s['id_status'] === $ids) {
          $j = $dir === 'up' ? $i - 1 : $i + 1;
          if ($j >= 0 && $j < count($sts)) {
            $o1 = $sts[$i]['ordem']; $o2 = $sts[$j]['ordem'];
            if ($o1 == $o2) { $o2 = $o1 + ($dir === 'up' ? -1 : 1); }
            db()->prepare("UPDATE tb_status SET ordem=? WHERE id_status=?")->execute([$o2, $sts[$i]['id_status']]);
            db()->prepare("UPDATE tb_status SET ordem=? WHERE id_status=?")->execute([$o1, $sts[$j]['id_status']]);
          }
          break;
        }
      }
      $ok = "Ordem atualizada.";
    }

    if ($action === 'delete') {
      $ids = (int)($_POST['id_status'] ?? 0);
      $uso = status_em_uso($ids);
      if ($uso > 0) {
        throw new Exception("Não é possível excluir: {$uso} curso(s) estão neste status. Mova os cursos ou desative o status.");
      }
      db()->beginTransaction();
      try {
        db()->prepare("DELETE FROM tb_status_transicoes WHERE id_status_de=? OR id_status_para=?")->execute([$ids, $ids]);
        db()->prepare("DELETE FROM tb_status WHERE id_status=?")->execute([$ids]);
        db()->commit();
      } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
      }
      audit_log('status_excluido', 'status', $ids);
      $ok = "Status excluído (transições associadas também foram removidas).";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$colunas = db()->query("SELECT * FROM tb_kanban_colunas ORDER BY ordem, id_coluna")->fetchAll();
$statusList = db()->query("
  SELECT s.*, k.nome AS coluna_nome,
         (SELECT COUNT(*) FROM tb_cursos c WHERE c.status_atual = s.nome) AS em_uso
  FROM tb_status s
  LEFT JOIN tb_kanban_colunas k ON k.id_coluna = s.id_coluna
  ORDER BY s.ordem, s.id_status
")->fetchAll();

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Status do fluxo</h1>
    <div class="text-muted small">Cada status pertence a uma coluna do Kanban. A cor define o badge exibido nos cards e tabelas.</div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-3">Novo status</h2>
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-12 col-md-4">
        <label class="form-label small">Nome</label>
        <input class="form-control" name="nome" required maxlength="80" placeholder="Ex.: Em Homologação">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small">Coluna do Kanban</label>
        <select class="form-select" name="id_coluna" required>
          <?php foreach ($colunas as $c): ?>
            <option value="<?= (int)$c['id_coluna'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Cor do badge</label>
        <input class="form-control form-control-color w-100" type="color" name="cor" value="#058285">
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
            <th>Status</th>
            <th>Coluna</th>
            <th>Cor</th>
            <th title="Cursos atualmente neste status">Em uso</th>
            <th>Inicial</th>
            <th>Final</th>
            <th>Ativo</th>
            <th class="text-end">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($statusList as $i => $s): ?>
            <tr>
              <td>
                <div class="btn-group-vertical btn-group-sm">
                  <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="id_status" value="<?= (int)$s['id_status'] ?>">
                    <input type="hidden" name="dir" value="up">
                    <button class="btn btn-outline-secondary btn-sm" <?= $i===0?'disabled':'' ?>>▲</button>
                  </form>
                  <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="id_status" value="<?= (int)$s['id_status'] ?>">
                    <input type="hidden" name="dir" value="down">
                    <button class="btn btn-outline-secondary btn-sm" <?= $i===count($statusList)-1?'disabled':'' ?>>▼</button>
                  </form>
                </div>
              </td>

              <td colspan="7">
                <form method="post" class="row g-2 align-items-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id_status" value="<?= (int)$s['id_status'] ?>">

                  <div class="col-12 col-md-3">
                    <input class="form-control form-control-sm" name="nome" value="<?= htmlspecialchars($s['nome']) ?>" required maxlength="80">
                    <span class="badge mt-1" style="background-color:<?= htmlspecialchars($s['cor']) ?>;color:<?= contrast_color($s['cor']) ?>">
                      <?= htmlspecialchars($s['nome']) ?>
                    </span>
                  </div>
                  <div class="col-6 col-md-2">
                    <select class="form-select form-select-sm" name="id_coluna">
                      <?php foreach ($colunas as $c): ?>
                        <option value="<?= (int)$c['id_coluna'] ?>" <?= (int)$s['id_coluna']===(int)$c['id_coluna']?'selected':'' ?>>
                          <?= htmlspecialchars($c['nome']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-3 col-md-1">
                    <input class="form-control form-control-color form-control-sm w-100" type="color" name="cor" value="<?= htmlspecialchars($s['cor']) ?>">
                  </div>
                  <div class="col-3 col-md-1 text-center">
                    <span class="badge <?= $s['em_uso'] > 0 ? 'bg-warning text-dark' : 'bg-secondary' ?> rounded-pill"><?= (int)$s['em_uso'] ?></span>
                  </div>
                  <div class="col-3 col-md-1 text-center">
                    <?php if ($s['is_inicial']): ?>
                      <span class="badge bg-success">Inicial</span>
                    <?php endif; ?>
                  </div>
                  <div class="col-3 col-md-1">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_final" <?= $s['is_final'] ? 'checked' : '' ?> title="Status final (não gera alerta de prazo)">
                    </div>
                  </div>
                  <div class="col-3 col-md-1">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" name="ativo" <?= $s['ativo'] ? 'checked' : '' ?>>
                    </div>
                  </div>
                  <div class="col-12 col-md-2 text-end">
                    <button class="btn btn-sm btn-outline-primary">Salvar</button>
                  </div>
                </form>
              </td>

              <td class="text-end">
                <?php if (!$s['is_inicial']): ?>
                  <form method="post" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_inicial">
                    <input type="hidden" name="id_status" value="<?= (int)$s['id_status'] ?>">
                    <button class="btn btn-sm btn-outline-success" title="Definir como status inicial dos novos cursos">Tornar inicial</button>
                  </form>
                <?php endif; ?>
                <form method="post" class="d-inline"
                      data-confirm="Excluir o status <b><?= htmlspecialchars($s['nome']) ?></b>?<br>As transições associadas também serão removidas."
                      data-confirm-title="Excluir status" data-confirm-type="danger" data-confirm-btn="Sim, excluir">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id_status" value="<?= (int)$s['id_status'] ?>">
                  <button class="btn btn-sm btn-outline-danger" <?= (int)$s['em_uso'] > 0 || $s['is_inicial'] ? 'disabled title="Em uso ou inicial"' : '' ?>>Excluir</button>
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
  <b>Inicial</b>: status atribuído aos cursos recém-propostos (apenas um).
  <b>Final</b>: encerra o fluxo (ex.: Publicado) e não gera alertas de prazo.
  Renomear um status atualiza automaticamente os cursos e o histórico.
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
