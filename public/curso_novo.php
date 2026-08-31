<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/escolas_repo.php';

require_login();
$u = auth_user();
require_perm('propoe_cursos');

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    $id = curso_create((int)$u['id_user'], [
      'nome_curso' => trim($_POST['nome_curso'] ?? ''),
      'carga_horaria' => $_POST['carga_horaria'] ?? '10',
      'publico_alvo' => trim($_POST['publico_alvo'] ?? ''),
      'nivel_ensino' => trim($_POST['nivel_ensino'] ?? ''),
      'unidade_escolar' => trim($_POST['unidade_escolar'] ?? ''),
      'prioridade' => $_POST['prioridade'] ?? 'MEDIA',
      'data_prevista_inicio' => $_POST['data_prevista_inicio'] ?? null,
      'data_prevista_entrega_final' => $_POST['data_prevista_entrega_final'] ?? null,
      'descricao_breve' => trim($_POST['descricao_breve'] ?? ''),
    ]);
    header("Location: curso_detalhe.php?id={$id}");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Propor Novo Curso</h1>
    <div class="text-muted small">Backlog geral • ao salvar, o status será <b><?= htmlspecialchars(status_inicial()) ?></b>.</div>
  </div>
  <a class="btn btn-outline-secondary" href="dashboard.php">Voltar</a>
</div>

<?php if ($erro): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post" class="row g-3" data-confirm="Confirmar a proposta do novo curso?" data-confirm-title="Propor curso" data-confirm-btn="Sim, propor">
      <?= csrf_field() ?>
      <div class="col-12">
        <label class="form-label">Nome do curso</label>
        <input class="form-control" name="nome_curso" required maxlength="200">
        <div class="form-text">Deve ser objetivo e descrever claramente o conteúdo abordado (Guia 01, seção 2).</div>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Carga horária sugerida</label>
        <select class="form-select" name="carga_horaria" required>
          <option value="10">10 horas (1-2 módulos, ~40 min de vídeo)</option>
          <option value="20">20 horas (3-4 módulos, ~80 min de vídeo)</option>
          <option value="30">30 horas (5-6 módulos, ~120 min de vídeo)</option>
          <option value="40">40 horas (7-8 módulos, ~160 min de vídeo)</option>
        </select>
        <div class="form-text">A carga horária oficial é definida pela equipe de TI ao aprovar o projeto.</div>
      </div>

      <div class="col-12 col-md-5">
        <label class="form-label">Público-alvo</label>
        <input class="form-control" name="publico_alvo" required maxlength="200">
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Nível de ensino</label>
        <select class="form-select" name="nivel_ensino">
          <option value="">Selecione...</option>
          <?php foreach (niveis_ensino() as $n): ?>
            <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-5">
        <label class="form-label">Unidade escolar</label>
        <input class="form-control" name="unidade_escolar" maxlength="120" list="dlEscolas"
               placeholder="Digite ou escolha na lista" autocomplete="off">
        <?= datalist_html('dlEscolas', escolas_ativas()) ?>
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label">Prioridade</label>
        <select class="form-select" name="prioridade">
          <?php foreach (prioridades() as $p): ?>
            <option value="<?= $p ?>" <?= $p==='MEDIA'?'selected':'' ?>><?= prioridade_badge($p)['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Prev. início</label>
        <input class="form-control" type="date" name="data_prevista_inicio">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Prev. entrega</label>
        <input class="form-control" type="date" name="data_prevista_entrega_final">
      </div>

      <div class="col-12">
        <label class="form-label">Breve descrição</label>
        <textarea class="form-control" name="descricao_breve" rows="4" placeholder="Objetivo geral (inicie com verbo no infinitivo: Capacitar, Desenvolver, Compreender...)"></textarea>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-success">Salvar (<?= htmlspecialchars(status_inicial()) ?>)</button>
        <a class="btn btn-outline-secondary" href="dashboard.php">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
