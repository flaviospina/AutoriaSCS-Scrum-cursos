<?php
/**
 * Slides no padrão AutoriaSCS (V15): o(a) professor(a) envia a apresentação do
 * módulo SEM formatação (PPTX); o sistema analisa (laudo), e — se aprovada —
 * gera o PDF e o PPTX no padrão oficial e permite registrá-los como entrega
 * "Slide" do módulo. Acesso: professores do curso (responsável/coautores) e
 * equipe de gestão; o perfil MB não envia.
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/entregas_repo.php';
require_once __DIR__ . '/../app/slides_repo.php';

require_login();
$u = auth_user();
$id = (int)($_GET['id'] ?? $_POST['id_curso'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); exit('Curso não encontrado.'); }
if (!is_staff() && !curso_eh_professor($curso, (int)$u['id_user'])) { http_response_code(403); exit('Sem permissão.'); }
$podeEnviar = !perm('recebe_email_insercao') || is_admin();

$erro = null; $ok = null; $reg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    if (!$podeEnviar) throw new Exception('O perfil MB não envia apresentações.');
    $action = $_POST['action'] ?? '';
    if ($action === 'analisar') {
      $modulo = (int)($_POST['modulo'] ?? 0);
      if ($modulo < 1 || $modulo > 8) throw new Exception('Informe o módulo (1 a 8).');
      $nomeModulo = trim(preg_replace('/\s+/u', ' ', $_POST['nome_modulo'] ?? ''));
      if (mb_strlen($nomeModulo) < 3) throw new Exception('Informe o nome do módulo (vai na capa: "Módulo N: nome").');
      $nomeModulo = mb_substr($nomeModulo, 0, 120);
      if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) throw new Exception('Envie o arquivo .pptx da apresentação.');
      if ($_FILES['arquivo']['size'] > 60 * 1024 * 1024) throw new Exception('Arquivo acima de 60 MB. Reduza as imagens.');
      $ext = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
      if ($ext !== 'pptx') throw new Exception('Envie a apresentação em formato .pptx (PowerPoint ou "Baixar como PPTX" no Google Slides).');
      set_time_limit(300);
      $reg = slides_processar($id, $curso, $modulo, $nomeModulo, $_FILES['arquivo']['tmp_name'], $_FILES['arquivo']['name'], $u);
      audit_log('slides_analisados', 'curso', $id, null, ['token' => $reg['token'], 'modulo' => $modulo, 'aprovado' => $reg['aprovado'], 'erros' => $reg['resumo']['erros'] ?? null, 'avisos' => $reg['resumo']['avisos'] ?? null]);
      header("Location: slides_padrao.php?id={$id}&t={$reg['token']}"); exit;
    }
    if ($action === 'registrar') {
      $token = $_POST['token'] ?? '';
      $idFile = slides_registrar_entrega($id, $token, $u);
      audit_log('slides_padrao_registrado', 'curso', $id, null, ['token' => $token, 'id_file' => $idFile]);
      header("Location: slides_padrao.php?id={$id}&t={$token}&ok=registrado"); exit;
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$token = $_GET['t'] ?? '';
if ($token !== '') $reg = slides_registro($id, $token);
if (($_GET['ok'] ?? '') === 'registrado') $ok = 'PDF registrado como entrega "Slide" do módulo. Ele já aparece na página do curso para a aprovação da TI.';
$historico = slides_registros($id);

$NIVEL = ['ERRO' => ['❌', 'danger', 'Impede a conversão'], 'AVISO' => ['⚠️', 'warning', 'Recomendação'], 'INFO' => ['ℹ️', 'secondary', 'Informação']];
include __DIR__ . '/_layout_top.php';
?>
<style>
  .laudo-item { border-left: 4px solid var(--bs-secondary); padding: .35rem .7rem; margin-bottom: .35rem; border-radius: 4px; background: rgba(128,128,128,.08); }
  .laudo-item.ERRO { border-left-color: #dc3545; } .laudo-item.AVISO { border-left-color: #ffc107; } .laudo-item.INFO { border-left-color: #6c757d; }
  .regras li { margin-bottom: .2rem; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h1 class="h4 mb-0">Slides no padrão AutoriaSCS</h1>
    <div class="text-muted small"><b><?= htmlspecialchars($curso['nome_curso']) ?></b> • <?= htmlspecialchars($curso['professor_nome']) ?> • <?= (int)$curso['carga_horaria'] ?> h</div>
  </div>
  <a class="btn btn-outline-secondary" href="curso_detalhe.php?id=<?= $id ?>">Voltar ao curso</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h2 class="h6">1. Envie a apresentação do módulo (sem formatação)</h2>
      <p class="small text-muted">Monte os slides em qualquer programa, só com o conteúdo: um <b>título curto</b> por slide (primeira linha), o texto ou os tópicos, e, se houver, <b>uma imagem com a legenda</b>
        ("Figura 1 – Título. Fonte: AUTOR, 2024."). Não se preocupe com fonte, cor ou posição: o sistema aplica o padrão. Salve como <b>.pptx</b>.</p>
      <?php if ($podeEnviar): ?>
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="analisar"><input type="hidden" name="id_curso" value="<?= $id ?>">
        <div class="col-4"><label class="form-label small mb-0" for="modulo">Módulo</label>
          <select class="form-select form-select-sm" name="modulo" id="modulo" required>
            <?php for ($m = 1; $m <= 8; $m++): ?><option value="<?= $m ?>" <?= (int)($_POST['modulo'] ?? 1) === $m ? 'selected' : '' ?>>Módulo <?= $m ?></option><?php endfor; ?>
          </select></div>
        <div class="col-8"><label class="form-label small mb-0" for="nome_modulo">Nome do módulo (vai na capa)</label>
          <input type="text" class="form-control form-control-sm" name="nome_modulo" id="nome_modulo" maxlength="120" required placeholder="Ex.: Fundamentos e Estrutura Padrão do Curso" value="<?= htmlspecialchars($_POST['nome_modulo'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label small mb-0" for="arquivo">Arquivo .pptx</label>
          <input type="file" class="form-control form-control-sm" name="arquivo" id="arquivo" accept=".pptx" required></div>
        <div class="col-12"><button class="btn btn-primary btn-sm">🔎 Analisar a apresentação</button></div>
      </form>
      <?php else: ?><div class="alert alert-secondary small mb-0">O perfil MB não envia apresentações.</div><?php endif; ?>

      <hr>
      <h2 class="h6">O que é verificado</h2>
      <ul class="small regras mb-0">
        <li><b>Bloqueia:</b> vídeo ou áudio embutido, link para vídeo, slide sem título, mais de <?= SLIDES_MAX_PAL_ERRO ?> palavras no slide, texto que não cabe no espaço livre (16 pt, depois 14 pt), imagem fora de JPG/PNG, imagem acima de 2 MB, imagem sem legenda, mais de <?= SLIDES_MAX_SLIDES ?> slides.</li>
        <li><b>Avisa:</b> mais de <?= SLIDES_MAX_PAL_AVISO ?> palavras, menos de <?= SLIDES_MIN_SLIDES ?> slides, animações e transições (removidas), tabela convertida em texto, imagem com menos de <?= SLIDES_IMG_MIN_PX ?> px, legenda sem fonte, mais de uma imagem no slide.</li>
        <li><b>Aplica:</b> fundos e logos do modelo oficial, Comfortaa, título em negrito #058285 (18–20 pt), texto em #000000 (16 ou 14 pt), legenda em 10 pt, capa com nome do curso e módulo, slides de abertura e encerramento.</li>
      </ul>
    </div></div>
  </div>

  <div class="col-12 col-lg-7">
    <?php if ($reg): $r = $reg; ?>
      <div class="card shadow-sm mb-3 <?= $r['aprovado'] ? 'border-success' : 'border-danger' ?>"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h2 class="h6 mb-1">2. Laudo da análise — <?= $r['aprovado'] ? '<span class="text-success">✅ APROVADA, apta para conversão</span>' : '<span class="text-danger">❌ REPROVADA</span>' ?></h2>
            <div class="small text-muted">Arquivo <b><?= htmlspecialchars($r['original']) ?></b> • Módulo <?= (int)$r['modulo'] ?>: <?= htmlspecialchars($r['nome_modulo']) ?> • enviado em <?= date('d/m/Y H:i', strtotime($r['quando'])) ?> por <?= htmlspecialchars($r['por']) ?></div>
          </div>
          <div class="small">
            <span class="badge bg-secondary"><?= (int)($r['resumo']['slides'] ?? 0) ?> slides</span>
            <span class="badge bg-secondary"><?= (int)($r['resumo']['palavras'] ?? 0) ?> palavras</span>
            <span class="badge bg-secondary"><?= (int)($r['resumo']['imagens'] ?? 0) ?> imagens</span>
            <span class="badge bg-danger"><?= (int)($r['resumo']['erros'] ?? 0) ?> erros</span>
            <span class="badge bg-warning text-dark"><?= (int)($r['resumo']['avisos'] ?? 0) ?> avisos</span>
          </div>
        </div>
        <div class="mt-2">
          <?php if (!$r['laudo']): ?><div class="small text-muted">Nenhuma observação. 🎉</div><?php endif; ?>
          <?php foreach ($r['laudo'] as $l): [$ic, , $lab] = $NIVEL[$l['nivel']] ?? ['•', 'secondary', '']; ?>
            <div class="laudo-item <?= $l['nivel'] ?> small"><?= $ic ?> <?= $l['slide'] ? '<b>Slide ' . (int)$l['slide'] . ':</b> ' : '' ?><?= htmlspecialchars($l['msg']) ?> <span class="text-muted">(<?= $lab ?>)</span></div>
          <?php endforeach; ?>
        </div>
        <?php if ($r['aprovado']): ?>
          <hr>
          <h2 class="h6">3. Arquivos no padrão</h2>
          <?php if ($r['erro_geracao']): ?>
            <div class="alert alert-danger small">Falha ao gerar os arquivos: <?= htmlspecialchars($r['erro_geracao']) ?></div>
          <?php else: ?>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <a class="btn btn-success btn-sm" href="slides_padrao_download.php?id=<?= $id ?>&t=<?= $r['token'] ?>&f=pdf">⬇ PDF no padrão (para a plataforma)</a>
              <a class="btn btn-outline-primary btn-sm" href="slides_padrao_download.php?id=<?= $id ?>&t=<?= $r['token'] ?>&f=pptx">⬇ PPTX editável no padrão</a>
              <a class="btn btn-outline-secondary btn-sm" href="slides_padrao_download.php?id=<?= $id ?>&t=<?= $r['token'] ?>&f=original">original enviado</a>
            </div>
            <div class="small text-muted mt-2">Confira o PDF. Se quiser ajustar algo, corrija a apresentação e envie de novo; ou edite o PPTX no padrão e envie o resultado pela entrega normal de Slide.</div>
            <?php if (!empty($r['registrado'])): ?>
              <div class="alert alert-success small mt-2 mb-0">Registrado como entrega em <?= date('d/m/Y H:i', strtotime($r['registrado']['quando'])) ?>: <b><?= htmlspecialchars($r['registrado']['nome']) ?></b>. <a href="curso_detalhe.php?id=<?= $id ?>#entregas">Ver no curso</a>.</div>
            <?php elseif ($podeEnviar): ?>
              <form method="post" class="mt-2" data-confirm="Registrar o PDF no padrão como a entrega <b>Slide do Módulo <?= (int)$r['modulo'] ?></b>? A TI será responsável pela aprovação." data-confirm-title="Registrar entrega" data-confirm-btn="Sim, registrar">
                <?= csrf_field() ?><input type="hidden" name="action" value="registrar"><input type="hidden" name="id_curso" value="<?= $id ?>"><input type="hidden" name="token" value="<?= $r['token'] ?>">
                <button class="btn btn-primary btn-sm">📎 Registrar o PDF como entrega "Slide" do Módulo <?= (int)$r['modulo'] ?></button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          <div class="small mt-2">Corrija os itens marcados como <b>Impede a conversão</b> e envie a apresentação novamente.</div>
        <?php endif; ?>
      </div></div>
    <?php else: ?>
      <div class="card shadow-sm mb-3"><div class="card-body small text-muted">Envie a apresentação ao lado. O laudo aparece aqui, item a item, e, se estiver tudo certo, os arquivos no padrão ficam disponíveis para baixar e registrar como entrega.</div></div>
    <?php endif; ?>

    <?php if ($historico): ?>
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h6">Análises anteriores deste curso</h2>
        <div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Quando</th><th>Módulo</th><th>Arquivo</th><th>Resultado</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($historico as $h): ?>
              <tr class="<?= ($reg['token'] ?? '') === $h['token'] ? 'table-active' : '' ?>">
                <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($h['quando'])) ?></td>
                <td><?= (int)$h['modulo'] ?></td>
                <td class="small"><?= htmlspecialchars($h['original']) ?></td>
                <td><?= $h['aprovado'] ? '<span class="badge bg-success">aprovada</span>' : '<span class="badge bg-danger">reprovada</span>' ?> <?= !empty($h['registrado']) ? '<span class="badge bg-primary">entrega registrada</span>' : '' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary py-0" href="slides_padrao.php?id=<?= $id ?>&t=<?= $h['token'] ?>">Abrir</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div></div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
