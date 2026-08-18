<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/csrf.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); echo "Curso não encontrado."; exit; }

// professor só edita o próprio curso; TI/ADMIN editam qualquer um; MB não edita
$podeEditar =
  ($u['role'] === 'PROFESSOR' && (int)$curso['id_professor'] === (int)$u['id_user']) ||
  in_array($u['role'], ['TI', 'ADMIN'], true);

if (!$podeEditar) { http_response_code(403); echo "Acesso negado."; exit; }

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    curso_update($id, [
      'nome_curso' => trim($_POST['nome_curso'] ?? ''),
      'carga_horaria' => $_POST['carga_horaria'] ?? $curso['carga_horaria'],
      'publico_alvo' => trim($_POST['publico_alvo'] ?? ''),
      'nivel_ensino' => trim($_POST['nivel_ensino'] ?? ''),
      'unidade_escolar' => trim($_POST['unidade_escolar'] ?? ''),
      'prioridade' => $_POST['prioridade'] ?? 'MEDIA',
      'data_prevista_inicio' => $_POST['data_prevista_inicio'] ?? null,
      'data_prevista_entrega_final' => $_POST['data_prevista_entrega_final'] ?? null,
      'descricao_breve' => trim($_POST['descricao_breve'] ?? ''),
    ]);
    header("Location: curso_detalhe.php?id={$id}&ok=edit");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
  $curso = curso_get($id);
}

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Editar Curso</h1>
    <div class="text-muted small">#<?= (int)$id ?> • <?= htmlspecialchars($curso['nome_curso']) ?></div>
  </div>
  <a class="btn btn-outline-secondary" href="curso_detalhe.php?id=<?= (int)$id ?>">Voltar</a>
</div>

<?php if ($erro): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post" class="row g-3"
          data-confirm="Salvar as alterações do curso <b><?= htmlspecialchars($curso['nome_curso']) ?></b>?"
          data-confirm-title="Salvar alterações" data-confirm-btn="Sim, salvar">
      <?= csrf_field() ?>
      <div class="col-12">
        <label class="form-label">Nome do curso</label>
        <input class="form-control" name="nome_curso" required maxlength="200" value="<?= htmlspecialchars($curso['nome_curso']) ?>">
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Carga horária</label>
        <select class="form-select" name="carga_horaria" required>
          <?php foreach ([10,20,30,40] as $ch): ?>
            <option value="<?= $ch ?>" <?= (int)$curso['carga_horaria']===$ch?'selected':'' ?>><?= $ch ?> horas</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-5">
        <label class="form-label">Público-alvo</label>
        <input class="form-control" name="publico_alvo" required maxlength="200" value="<?= htmlspecialchars($curso['publico_alvo']) ?>">
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Nível de ensino</label>
        <select class="form-select" name="nivel_ensino">
          <option value="">Selecione...</option>
          <?php foreach (niveis_ensino() as $n): ?>
            <option value="<?= htmlspecialchars($n) ?>" <?= ($curso['nivel_ensino'] ?? '')===$n?'selected':'' ?>><?= htmlspecialchars($n) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-5">
        <label class="form-label">Unidade escolar</label>
        <input class="form-control" name="unidade_escolar" maxlength="120" value="<?= htmlspecialchars($curso['unidade_escolar'] ?? '') ?>">
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label">Prioridade</label>
        <select class="form-select" name="prioridade">
          <?php foreach (prioridades() as $p): ?>
            <option value="<?= $p ?>" <?= ($curso['prioridade'] ?? 'MEDIA')===$p?'selected':'' ?>><?= prioridade_badge($p)['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Prev. início</label>
        <input class="form-control" type="date" name="data_prevista_inicio" value="<?= htmlspecialchars($curso['data_prevista_inicio'] ?? '') ?>">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Prev. entrega</label>
        <input class="form-control" type="date" name="data_prevista_entrega_final" value="<?= htmlspecialchars($curso['data_prevista_entrega_final'] ?? '') ?>">
      </div>

      <div class="col-12">
        <label class="form-label">Breve descrição</label>
        <textarea class="form-control" name="descricao_breve" rows="4"><?= htmlspecialchars($curso['descricao_breve'] ?? '') ?></textarea>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-success">Salvar alterações</button>
        <a class="btn btn-outline-secondary" href="curso_detalhe.php?id=<?= (int)$id ?>">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
