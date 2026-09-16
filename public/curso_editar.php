<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/escolas_repo.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); echo "Curso não encontrado."; exit; }

// professor (responsável ou coautor) só edita o próprio curso; TI/ADMIN editam qualquer um; MB não edita
$podeEditar =
  (curso_eh_professor($curso, (int)$u['id_user']) && perm('propoe_cursos')) ||
  perm('revisa_cursos');

if (!$podeEditar) { http_response_code(403); echo "Acesso negado."; exit; }

// campos de gestão (unidade, prioridade, previsões e carga horária) só para TI/ADMIN
$ehGestao = perm('revisa_cursos');
$escolas = escolas_ativas();
if (!empty($curso['unidade_escolar']) && !in_array($curso['unidade_escolar'], $escolas, true)) {
  $escolas[] = $curso['unidade_escolar']; // valor atual (legado/inativa) continua selecionável
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    $dados = [
      'nome_curso' => trim($_POST['nome_curso'] ?? ''),
      'publico_alvo' => trim($_POST['publico_alvo'] ?? ''),
      'nivel_ensino' => trim($_POST['nivel_ensino'] ?? ''),
      'descricao_breve' => trim($_POST['descricao_breve'] ?? ''),
      // campos de gestão: o backend ignora/recusa para quem não é TI/ADMIN
      'carga_horaria' => $_POST['carga_horaria'] ?? $curso['carga_horaria'],
      'unidade_escolar' => trim($_POST['unidade_escolar'] ?? ($curso['unidade_escolar'] ?? '')),
      'prioridade' => $_POST['prioridade'] ?? $curso['prioridade'],
      'data_prevista_inicio' => $_POST['data_prevista_inicio'] ?? $curso['data_prevista_inicio'],
      'data_prevista_entrega_final' => $_POST['data_prevista_entrega_final'] ?? $curso['data_prevista_entrega_final'],
    ];
    if ($dados['nome_curso'] === '' || $dados['publico_alvo'] === '') {
      throw new Exception("Nome do curso e público-alvo são obrigatórios.");
    }
    curso_update($id, $dados, $u);
    header("Location: curso_detalhe.php?id={$id}&ok=edit");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
  $curso = curso_get($id);
}

include __DIR__ . '/_layout_top.php';
$pb = prioridade_badge($curso['prioridade'] ?? 'MEDIA');
function data_br(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
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
        <?php if ($ehGestao): ?>
          <select class="form-select" name="carga_horaria" required>
            <?php foreach (carga_horaria_opcoes() as $ch): ?>
              <option value="<?= $ch ?>" <?= (int)$curso['carga_horaria']===$ch?'selected':'' ?>><?= htmlspecialchars(carga_horaria_label($ch)) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($curso['projeto_aprovado_em'])): ?>
            <div class="form-text">Projeto aprovado — a alteração da carga horária oficial é registrada na auditoria.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="campo-ro"><?= (int)$curso['carga_horaria'] ?> horas</div>
          <div class="form-text">Definida pela equipe de TI<?= !empty($curso['projeto_aprovado_em']) ? ' na aprovação do projeto' : '' ?>.</div>
        <?php endif; ?>
      </div>

      <div class="col-12 col-md-5">
        <label class="form-label">Público-alvo</label>
        <input class="form-control" name="publico_alvo" required maxlength="200" value="<?= htmlspecialchars($curso['publico_alvo']) ?>">
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Nível de ensino</label>
        <select class="form-select" name="nivel_ensino">
          <option value="">Selecione...</option>
          <?php foreach (niveis_opcoes_para($curso['nivel_ensino'] ?? null) as $n): ?>
            <option value="<?= htmlspecialchars($n) ?>" <?= ($curso['nivel_ensino'] ?? '')===$n?'selected':'' ?>><?= htmlspecialchars($n) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-5">
        <label class="form-label">Unidade escolar</label>
        <?php if ($ehGestao): ?>
          <select class="form-select" name="unidade_escolar">
            <option value="">Selecione...</option>
            <?php foreach ($escolas as $e): ?>
              <option value="<?= htmlspecialchars($e) ?>" <?= ($curso['unidade_escolar'] ?? '')===$e?'selected':'' ?>><?= htmlspecialchars($e) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <div class="campo-ro"><?= htmlspecialchars($curso['unidade_escolar'] ?: '—') ?></div>
        <?php endif; ?>
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label">Prioridade</label>
        <?php if ($ehGestao): ?>
          <select class="form-select" name="prioridade">
            <?php foreach (prioridades() as $p): ?>
              <option value="<?= $p ?>" <?= ($curso['prioridade'] ?? 'MEDIA')===$p?'selected':'' ?>><?= prioridade_badge($p)['label'] ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <div class="campo-ro"><span class="badge rounded-pill" style="<?= $pb['style'] ?>"><?= $pb['label'] ?></span></div>
        <?php endif; ?>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Prev. início</label>
        <?php if ($ehGestao): ?>
          <input class="form-control" type="date" name="data_prevista_inicio" value="<?= htmlspecialchars($curso['data_prevista_inicio'] ?? '') ?>">
        <?php else: ?>
          <div class="campo-ro"><?= data_br($curso['data_prevista_inicio'] ?? null) ?></div>
        <?php endif; ?>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Prev. entrega</label>
        <?php if ($ehGestao): ?>
          <input class="form-control" type="date" name="data_prevista_entrega_final" value="<?= htmlspecialchars($curso['data_prevista_entrega_final'] ?? '') ?>">
        <?php else: ?>
          <div class="campo-ro"><?= data_br($curso['data_prevista_entrega_final'] ?? null) ?></div>
        <?php endif; ?>
      </div>

      <?php if (!$ehGestao): ?>
        <div class="col-12">
          <div class="form-text">
            Unidade escolar, prioridade, previsões e carga horária são definidas pela equipe de TI/ADMIN.
            Se precisar de ajuste, solicite pelo e-mail ti.cecape@scseduca.com.br.
          </div>
        </div>
      <?php endif; ?>

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
