<?php
/**
 * Detalhe do apontamento: dados, ações (professor: concordo / objeção / reenviar;
 * TI/ADMIN: alterar status) e linha do tempo completa.
 */
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
$ap = apont_get($id);
if (!$ap) { http_response_code(404); echo "Apontamento não encontrado."; exit; }

$curso = curso_get((int)$ap['id_curso']);
$ehProfessor = $curso && curso_eh_professor($curso, (int)$u['id_user']);
if (!is_staff() && !$ehProfessor) { http_response_code(403); echo "Acesso negado."; exit; }

$erro = null; $ok = null;
$okMap = [
  'status'   => 'Status do apontamento atualizado. O(a) professor(a) e a TI foram avisados por e-mail.',
  'concordo' => 'Concordância registrada. O apontamento está "Em correção pelo professor".',
  'objecao'  => 'Objeção registrada e enviada à equipe TI/Qualidade.',
  'reenvio'  => 'Correção reenviada para análise da TI.',
];
if (isset($okMap[$_GET['ok'] ?? ''])) $ok = $okMap[$_GET['ok']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'status') {
      apont_mudar_status($id, $_POST['status'] ?? '', $u, $_POST['observacao'] ?? null);
      header("Location: apontamento_detalhe.php?id={$id}&ok=status"); exit;
    }
    if ($action === 'concordo') {
      apont_concordar($id, $u);
      header("Location: apontamento_detalhe.php?id={$id}&ok=concordo"); exit;
    }
    if ($action === 'objecao') {
      apont_objetar($id, $u, $_POST['justificativa'] ?? '');
      header("Location: apontamento_detalhe.php?id={$id}&ok=objecao"); exit;
    }
    if ($action === 'reenviar') {
      apont_reenviar($id, $u, $_POST['observacao'] ?? null);
      header("Location: apontamento_detalhe.php?id={$id}&ok=reenvio"); exit;
    }
    throw new Exception("Ação inválida.");
  } catch (Throwable $e) {
    $erro = $e->getMessage();
    $ap = apont_get($id) ?: $ap;
  }
}

$acoes = apont_acoes($ap, $u);
$timeline = apont_timeline($id);
$manif = apont_manifestacoes($id);
$pend = APONT_STATUS[$ap['status']]['pendente'] ?? false;

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Apontamento #<?= (int)$id ?></h1>
    <div class="text-muted small">Curso: <b><?= htmlspecialchars($ap['nome_curso']) ?></b></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="apontamentos.php?id=<?= (int)$ap['id_curso'] ?>">Voltar à lista</a>
    <a class="btn btn-outline-primary" href="curso_detalhe.php?id=<?= (int)$ap['id_curso'] ?>">Ver curso</a>
  </div>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if ($pend && $ehProfessor && !$acoes['status_ti']): ?>
  <div class="aviso-apontamentos mb-3">
    <span>⚠️ Este apontamento aguarda sua ação: <b><?= htmlspecialchars(apont_status_label($ap['status'])) ?></b>.</span>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card shadow-sm <?= $pend ? 'card-apontamentos-pendentes' : '' ?>">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h6 mb-0">Apontamento</h2>
          <?= apont_status_badge($ap['status']) ?>
        </div>
        <div class="row g-2 small">
          <div class="col-12"><b>Curso:</b> <?= htmlspecialchars($ap['nome_curso']) ?></div>
          <div class="col-12"><b>Arquivo/Material relacionado:</b>
            <?php if ($ap['arquivo_nome']): ?>
              <?= htmlspecialchars(apont_arquivo_rotulo($ap)) ?>
              <?php if (!perm('recebe_email_insercao') || is_admin()): ?>
                <a class="ms-1" href="download.php?id=<?= (int)$ap['id_file'] ?>">baixar</a>
              <?php endif; ?>
            <?php else: ?><span class="text-muted">nenhum (apontamento geral)</span><?php endif; ?>
          </div>
          <div class="col-6"><b>Tipo:</b> <span class="badge bg-info text-dark"><?= htmlspecialchars(apont_tipo_label($ap['tipo'])) ?></span></div>
          <div class="col-6"><b>Registrado por:</b> <?= htmlspecialchars($ap['user_nome']) ?></div>
          <div class="col-6"><b>Data:</b> <?= date('d/m/Y H:i', strtotime($ap['created_at'])) ?></div>
          <div class="col-6"><b>Última atualização:</b> <?= date('d/m/Y H:i', strtotime($ap['updated_at'] ?? $ap['created_at'])) ?></div>
        </div>
        <hr class="my-3">
        <div class="fw-semibold small mb-1">Descrição</div>
        <div class="marc-desc"><?= nl2br(htmlspecialchars($ap['conteudo'])) ?></div>

        <?php if ($acoes['concordo'] || $acoes['objecao'] || $acoes['reenviar']): ?>
          <hr class="my-3">
          <div class="fw-semibold small mb-2">Resposta do(a) professor(a)</div>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($acoes['concordo']): ?>
              <form method="post" data-confirm="Confirmar que você <b>concorda</b> com o apontamento?<br>O status passará para <b>Em correção pelo professor</b>."
                    data-confirm-title="Concordo com o apontamento" data-confirm-btn="Sim, concordo">
                <?= csrf_field() ?><input type="hidden" name="action" value="concordo">
                <button class="btn btn-success">✔ Concordo com o apontamento</button>
              </form>
            <?php endif; ?>
            <?php if ($acoes['objecao']): ?>
              <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalObjecao">
                ✖ Não concordo / Registrar objeção
              </button>
            <?php endif; ?>
            <?php if ($acoes['reenviar']): ?>
              <form method="post" class="d-flex flex-wrap gap-2 align-items-center"
                    data-confirm="Reenviar este apontamento para análise da TI?<br>Confirme que a correção foi concluída."
                    data-confirm-title="Reenviar para análise" data-confirm-btn="Sim, reenviar">
                <?= csrf_field() ?><input type="hidden" name="action" value="reenviar">
                <input class="form-control form-control-sm" name="observacao" maxlength="500" placeholder="O que foi corrigido (opcional)" style="min-width:260px">
                <button class="btn btn-primary">↩ Correção concluída — reenviar para análise</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($acoes['status_ti']): ?>
          <hr class="my-3">
          <div class="fw-semibold small mb-2">Equipe TI/Qualidade — alterar status</div>
          <form method="post" class="row g-2"
                data-confirm="Alterar o status do apontamento?<br>O(a) professor(a) e a TI receberão e-mail com os detalhes."
                data-confirm-title="Alterar status" data-confirm-btn="Sim, alterar">
            <?= csrf_field() ?><input type="hidden" name="action" value="status">
            <div class="col-12 col-md-4">
              <select class="form-select" name="status" required>
                <?php foreach ($acoes['status_ti'] as $s): ?>
                  <option value="<?= $s ?>"><?= htmlspecialchars(apont_status_label($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <input class="form-control" name="observacao" maxlength="500" placeholder="Observação (vai no e-mail e no histórico)">
            </div>
            <div class="col-12 col-md-2">
              <button class="btn btn-primary w-100">Aplicar</button>
            </div>
          </form>
          <div class="form-text">
            <b>Correção solicitada</b> = mantém a exigência após objeção • <b>Aprovado</b> = correção aceita •
            <b>Concluído</b> = encerra o apontamento (retira o aviso de pendência sem apagar o histórico).
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($manif): ?>
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h2 class="h6 mb-3">Manifestações do(a) professor(a)</h2>
          <?php foreach ($manif as $m): ?>
            <div class="border rounded p-2 mb-2 small">
              <div class="d-flex justify-content-between flex-wrap gap-2">
                <span><?= $m['tipo'] === 'CONCORDO' ? '<span class="badge bg-success">Concordou</span>' : '<span class="badge bg-danger">Objeção</span>' ?>
                  <b><?= htmlspecialchars($m['user_nome']) ?></b></span>
                <span class="text-muted"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></span>
              </div>
              <?php if ($m['justificativa']): ?><div class="mt-1"><?= nl2br(htmlspecialchars($m['justificativa'])) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Histórico (linha do tempo)</h2>
        <ul class="timeline">
          <?php foreach ($timeline as $t): ?>
            <li>
              <div class="tl-data"><?= date('d/m/Y H:i', strtotime($t['data'])) ?> • <?= htmlspecialchars($t['usuario']) ?></div>
              <div class="fw-semibold small"><?= htmlspecialchars($t['acao']) ?></div>
              <?php if ($t['observacao']): ?><div class="small text-muted"><?= nl2br(htmlspecialchars($t['observacao'])) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php if ($acoes['objecao']): ?>
<div class="modal fade" id="modalObjecao" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" id="formObjecao">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="objecao">
      <div class="modal-header">
        <h5 class="modal-title">Não concordo / Registrar objeção</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning small">
          A objeção fica registrada permanentemente no histórico e é enviada à equipe TI/Qualidade, que responderá alterando o status.
        </div>
        <label class="form-label">Justificativa / Objeção <span class="text-danger">*</span></label>
        <textarea class="form-control" name="justificativa" rows="4" required minlength="5" placeholder="Explique por que não concorda com o apontamento..."></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger">Registrar objeção</button>
      </div>
    </form>
  </div>
</div>
<script>
  // não permite envio vazio (além do required do navegador e da validação no servidor)
  document.getElementById('formObjecao').addEventListener('submit', function (e) {
    var t = this.querySelector('[name=justificativa]');
    if (t.value.trim().length < 5) {
      e.preventDefault();
      if (typeof Swal !== 'undefined') Swal.fire({ icon: 'warning', title: 'Justificativa obrigatória', text: 'Descreva a objeção antes de enviar.', background: '#0f2044', color: '#e8edf5', confirmButtonColor: '#06b6d4' });
      t.focus();
    }
  });
</script>
<?php endif; ?>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
