<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/apontamento_repo.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); echo "Curso não encontrado."; exit; }

// professor (responsável/coautor) só vê o próprio curso
$ehProfessor = curso_eh_professor($curso, (int)$u['id_user']);
if (!is_staff() && !$ehProfessor) {
  http_response_code(403); echo "Acesso negado."; exit;
}

$erro = null; $ok = null;
$isTI = apont_pode_gerir($u);
if (($_GET['ok'] ?? '') === 'criado') $ok = "Apontamento registrado. O(a) professor(a) e a TI foram avisados por e-mail.";

// criar apontamento (TI/ADMIN) — regra no repositório (backend)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  csrf_check();
  try {
    $idFile = ($_POST['id_file'] ?? '') !== '' ? (int)$_POST['id_file'] : null;
    apont_criar($curso, $u, $_POST['tipo'] ?? 'OUTRO', $_POST['conteudo'] ?? '', $idFile);
    header("Location: apontamentos.php?id={$id}&ok=criado");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$apont = apont_lista($id);
$pendentes = apont_pendentes_curso($id);
$arquivos = $isTI ? apont_arquivos_opcoes($id) : [];

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Apontamentos (TI/Qualidade)</h1>
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
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if ($pendentes > 0 && $ehProfessor && !$isTI): ?>
  <div class="aviso-apontamentos mb-3">
    <span>⚠️ ATENÇÃO: existem <b><?= $pendentes ?></b> apontamento(s) de TI/Qualidade aguardando sua análise.
      Abra cada um em <b>Visualizar</b> para concordar, registrar objeção ou reenviar a correção.</span>
  </div>
<?php endif; ?>

<?php if ($isTI): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <h2 class="h6 mb-3">Registrar novo apontamento</h2>
      <form method="post" class="row g-2"
            data-confirm="Registrar este apontamento? O(a) professor(a) e a TI serão notificados por e-mail."
            data-confirm-title="Novo apontamento" data-confirm-btn="Sim, registrar">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="col-12 col-md-3">
          <label class="form-label small">Tipo</label>
          <select class="form-select" name="tipo">
            <?php foreach (APONT_TIPOS as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $k === 'OUTRO' ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-9">
          <label class="form-label small">Arquivo/Material relacionado <span class="text-muted">(opcional)</span></label>
          <select class="form-select" name="id_file">
            <option value="">Nenhum — apontamento geral do curso</option>
            <?php foreach ($arquivos as $f): ?>
              <option value="<?= (int)$f['id_file'] ?>">
                <?= ((int)$f['modulo']) ? 'Módulo ' . (int)$f['modulo'] : 'Geral' ?> • <?= htmlspecialchars($f['categoria']) ?> — <?= htmlspecialchars($f['original_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label small">Apontamento</label>
          <textarea class="form-control" name="conteudo" rows="3" required placeholder="Descreva o ajuste necessário..."></textarea>
        </div>

        <div class="col-12">
          <button class="btn btn-primary">Salvar apontamento</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card shadow-sm <?= $pendentes > 0 ? 'card-apontamentos-pendentes' : '' ?>">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h6 mb-0">Lista de apontamentos</h2>
      <?php if ($pendentes > 0): ?>
        <span class="badge bg-danger rounded-pill"><?= $pendentes ?> pendente(s)</span>
      <?php else: ?>
        <span class="badge bg-success rounded-pill">Sem pendências</span>
      <?php endif; ?>
    </div>

    <?php if (!$apont): ?>
      <div class="text-muted small">Nenhum apontamento.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Data</th>
              <th>Tipo</th>
              <th>Arquivo</th>
              <th>Apontamento</th>
              <th>Por</th>
              <th>Status</th>
              <th class="text-end">Ação</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($apont as $a): $pend = APONT_STATUS[$a['status']]['pendente'] ?? false; ?>
              <tr class="<?= $pend ? 'table-warning-soft' : '' ?>">
                <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
                <td><span class="badge bg-info text-dark"><?= htmlspecialchars(apont_tipo_label($a['tipo'])) ?></span></td>
                <td class="small"><?= $a['arquivo_nome'] ? htmlspecialchars($a['arquivo_nome']) : '<span class="text-muted">—</span>' ?></td>
                <td><?= nl2br(htmlspecialchars(mb_strimwidth($a['conteudo'], 0, 160, '...'))) ?></td>
                <td><?= htmlspecialchars($a['user_nome']) ?></td>
                <td><?= apont_status_badge($a['status']) ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm <?= $pend && $ehProfessor && !$isTI ? 'btn-warning' : 'btn-outline-primary' ?>"
                     href="apontamento_detalhe.php?id=<?= (int)$a['id_apontamento'] ?>">Visualizar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
