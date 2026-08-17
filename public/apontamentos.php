<?php
session_start();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';
require_once __DIR__ . '/../app/notify.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); echo "Curso não encontrado."; exit; }

// professor só vê o próprio
if ($u['role'] === 'PROFESSOR' && (int)$curso['id_professor'] !== (int)$u['id_user']) {
  http_response_code(403); echo "Acesso negado."; exit;
}

$erro = null;
$isTI = in_array($u['role'], ['TI','ADMIN'], true);

// criar apontamento (TI/ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  csrf_check();
  try {
    require_role(['TI']);
    $tipo = $_POST['tipo'] ?? 'OUTRO';
    $conteudo = trim($_POST['conteudo'] ?? '');
    if ($conteudo === '') throw new Exception("Conteúdo é obrigatório.");
    if (!in_array($tipo, ['TECNICO','PEDAGOGICO','ABNT','OUTRO'], true)) $tipo = 'OUTRO';

    db()->prepare("INSERT INTO tb_curso_apontamentos (id_curso, id_user, tipo, conteudo) VALUES (?,?,?,?)")
      ->execute([$id, $u['id_user'], $tipo, $conteudo]);

    audit_log('apontamento_criado', 'curso', $id, null, ['tipo' => $tipo, 'conteudo' => $conteudo]);
    notify_apontamento($curso, $tipo, $conteudo);

    header("Location: apontamentos.php?id={$id}");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// resolver / reabrir (TI/ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
  csrf_check();
  try {
    require_role(['TI']);
    $id_ap = (int)($_POST['id_apontamento'] ?? 0);
    $val = (int)($_POST['resolvido'] ?? 0);

    db()->prepare("UPDATE tb_curso_apontamentos SET resolvido=? WHERE id_apontamento=? AND id_curso=?")
      ->execute([$val, $id_ap, $id]);

    audit_log($val ? 'apontamento_resolvido' : 'apontamento_reaberto', 'apontamento', $id_ap,
      null, ['id_curso' => $id]);

    header("Location: apontamentos.php?id={$id}");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$ap = db()->prepare("SELECT a.*, u.nome AS user_nome FROM tb_curso_apontamentos a JOIN tb_users u ON u.id_user=a.id_user WHERE a.id_curso=? ORDER BY a.created_at DESC");
$ap->execute([$id]);
$apont = $ap->fetchAll();

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Apontamentos</h1>
    <div class="text-muted small">
      Curso: <b><?= htmlspecialchars($curso['nome_curso']) ?></b> • Status atual:
      <span class="badge bg-secondary"><?= htmlspecialchars($curso['status_atual']) ?></span>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="curso_detalhe.php?id=<?= (int)$id ?>">Voltar</a>
  </div>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if ($isTI): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <h2 class="h6 mb-3">Registrar novo apontamento</h2>
      <form method="post" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="col-12 col-md-3">
          <label class="form-label small">Tipo</label>
          <select class="form-select" name="tipo">
            <option value="TECNICO">Técnico</option>
            <option value="PEDAGOGICO">Pedagógico</option>
            <option value="ABNT">ABNT</option>
            <option value="OUTRO" selected>Outro</option>
          </select>
        </div>

        <div class="col-12 col-md-9">
          <label class="form-label small">Conteúdo</label>
          <textarea class="form-control" name="conteudo" rows="2" required></textarea>
        </div>

        <div class="col-12">
          <button class="btn btn-primary">Salvar apontamento</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <h2 class="h6 mb-3">Lista de apontamentos</h2>

    <?php if (!$apont): ?>
      <div class="text-muted small">Nenhum apontamento.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Data</th>
              <th>Tipo</th>
              <th>Conteúdo</th>
              <th>Por</th>
              <th>Status</th>
              <?php if ($isTI): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($apont as $a): ?>
              <tr>
                <td class="text-nowrap"><?= htmlspecialchars($a['created_at']) ?></td>
                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($a['tipo']) ?></span></td>
                <td><?= nl2br(htmlspecialchars($a['conteudo'])) ?></td>
                <td><?= htmlspecialchars($a['user_nome']) ?></td>
                <td>
                  <?= $a['resolvido']
                      ? '<span class="badge bg-success">Resolvido</span>'
                      : '<span class="badge bg-warning text-dark">Pendente</span>' ?>
                </td>

                <?php if ($isTI): ?>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id_apontamento" value="<?= (int)$a['id_apontamento'] ?>">
                      <input type="hidden" name="resolvido" value="<?= $a['resolvido'] ? 0 : 1 ?>">
                      <button class="btn btn-sm <?= $a['resolvido'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                        <?= $a['resolvido'] ? 'Reabrir' : 'Marcar resolvido' ?>
                      </button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
