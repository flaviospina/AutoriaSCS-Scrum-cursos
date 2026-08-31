<?php
/**
 * Vídeos do curso — lista os vídeos em revisão por módulo e permite ao
 * formador (ou ao admin) enviar um novo vídeo para análise da TI.
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/video_repo.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';
require_once __DIR__ . '/../app/notify.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); echo "Curso não encontrado."; exit; }

if (!is_staff() && (int)$curso['id_professor'] !== (int)$u['id_user']) {
  http_response_code(403); echo "Acesso negado."; exit;
}

$ehDono = (int)$curso['id_professor'] === (int)$u['id_user'];
// A MB Estúdios produz e disponibiliza os vídeos; o(a) formador(a) analisa.
$podeEnviar = perm('recebe_email_insercao') || is_admin();

$erro = null; $ok = null;
if (($_GET['ok'] ?? '') === 'criado') $ok = "Vídeo disponibilizado. O(a) formador(a) foi avisado(a) por e-mail com a descrição do vídeo.";

// Enviar um novo vídeo (cria o vídeo + versão 1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'criar_video') {
  csrf_check();
  try {
    if (!$podeEnviar) throw new Exception("Somente a MB Estúdios disponibiliza vídeos para análise.");
    $titulo    = trim($_POST['titulo'] ?? '');
    $modulo    = (int)($_POST['modulo'] ?? -1);
    $descricao = trim($_POST['descricao'] ?? '');
    $obs       = trim($_POST['observacao'] ?? '') ?: null;
    if ($titulo === '') throw new Exception("Informe o título do vídeo.");
    if ($descricao === '') throw new Exception("Descreva o vídeo — a descrição vai no e-mail enviado ao(à) formador(a).");
    if ($modulo < 0 || $modulo > 8) throw new Exception("Módulo inválido.");

    $arq = video_receber_upload($id, $_FILES['arquivo'] ?? null);

    db()->beginTransaction();
    try {
      db()->prepare("INSERT INTO tb_videos (id_curso, modulo, titulo, descricao) VALUES (?,?,?,?)")
        ->execute([$id, $modulo, $titulo, $descricao]);
      $idVideo = (int)db()->lastInsertId();
      db()->prepare("
        INSERT INTO tb_video_versoes (id_video, numero, id_user, original_name, stored_name, mime_type, file_size, observacao)
        VALUES (?,?,?,?,?,?,?,?)
      ")->execute([$idVideo, 1, $u['id_user'], $arq['original'], $arq['stored'], $arq['mime'], $arq['size'], $obs]);
      db()->commit();
    } catch (Throwable $e) {
      db()->rollBack();
      throw $e;
    }

    audit_log('video_disponibilizado', 'curso', $id, null,
      ['video' => $titulo, 'modulo' => $modulo, 'arquivo' => $arq['original'], 'descricao' => $descricao]);
    $video = video_get($idVideo);
    notify_video_disponivel($video, 1, $obs);

    header("Location: curso_videos.php?id={$id}&ok=criado");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$videos = videos_do_curso($id);

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">🎬 Revisão de Vídeos</h1>
    <div class="text-muted small">
      Curso: <b><?= htmlspecialchars($curso['nome_curso']) ?></b> •
      Formador(a): <b><?= htmlspecialchars($curso['professor_nome']) ?></b>
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="curso_detalhe.php?id=<?= (int)$id ?>">Voltar ao curso</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <?php if ($podeEnviar): ?>
    <div class="col-12 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3">Disponibilizar vídeo para análise</h2>
          <form method="post" enctype="multipart/form-data"
                data-confirm="Disponibilizar o vídeo? O(a) formador(a) receberá o e-mail com a descrição e o link."
                data-confirm-title="Disponibilizar vídeo" data-confirm-btn="Sim, disponibilizar">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="criar_video">
            <div class="mb-2">
              <label class="form-label small">Título do vídeo</label>
              <input class="form-control form-control-sm" name="titulo" required maxlength="180"
                     placeholder="Ex.: Videoaula 1 - Introdução">
            </div>
            <div class="mb-2">
              <label class="form-label small">Módulo</label>
              <select class="form-select form-select-sm" name="modulo" required>
                <option value="0">Geral</option>
                <?php for ($mo = 1; $mo <= 8; $mo++): ?>
                  <option value="<?= $mo ?>">Módulo <?= $mo ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small">Descrição do vídeo <span class="text-danger">*</span></label>
              <textarea class="form-control form-control-sm" name="descricao" rows="3" required
                        placeholder="Descreva exatamente o conteúdo do vídeo — este texto vai no e-mail do(a) formador(a)."></textarea>
              <div class="form-text">Ex.: "Videoaula 1 do Módulo 2 — gravação em estúdio, com vinheta e legendas."</div>
            </div>
            <div class="mb-2">
              <label class="form-label small">Arquivo de vídeo</label>
              <input class="form-control form-control-sm" type="file" name="arquivo" accept="video/mp4,video/webm,video/quicktime" required>
              <div class="form-text">Formato recomendado: MP4 (H.264). Limite: 512MB.</div>
            </div>
            <div class="mb-3">
              <label class="form-label small">Observação (opcional)</label>
              <input class="form-control form-control-sm" name="observacao" maxlength="500"
                     placeholder="Ex.: Áudio regravado no minuto 3">
            </div>
            <button class="btn btn-primary btn-sm w-100">Disponibilizar para análise</button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="col-12 <?= $podeEnviar ? 'col-lg-8' : '' ?>">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Vídeos deste curso (<?= count($videos) ?>)</h2>

        <?php if (!$videos): ?>
          <div class="text-muted small">
            Nenhum vídeo disponibilizado ainda.
            <?= $podeEnviar ? 'Use o formulário ao lado para publicar o primeiro.' : 'Assim que a MB Estúdios publicar um vídeo, você receberá um e-mail e ele aparecerá aqui.' ?>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Módulo</th>
                  <th>Vídeo</th>
                  <th>Situação</th>
                  <th title="Versões enviadas">Versões</th>
                  <th title="Apontamentos pendentes ou em correção na versão atual">Em aberto</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($videos as $v): $stv = VIDEO_STATUS[$v['status']] ?? VIDEO_STATUS['EM_ANALISE']; ?>
                  <tr>
                    <td>
                      <span class="badge <?= ((int)$v['modulo']) ? 'bg-primary' : 'bg-secondary' ?>">
                        <?= ((int)$v['modulo']) ? 'Módulo ' . (int)$v['modulo'] : 'Geral' ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($v['titulo']) ?></td>
                    <td><span class="badge" style="background:<?= $stv['cor'] ?>;color:#08131f"><?= $stv['label'] ?></span></td>
                    <td>v<?= (int)$v['n_versoes'] ?></td>
                    <td>
                      <?php if ((int)$v['n_abertas'] > 0): ?>
                        <span class="badge bg-warning text-dark"><?= (int)$v['n_abertas'] ?></span>
                      <?php else: ?>
                        <span class="text-muted small">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="video_revisao.php?id=<?= (int)$v['id_video'] ?>">
                        Abrir revisão
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="alert alert-info small mt-3 mb-0">
          <b>Como funciona:</b> a <b>MB Estúdios</b> disponibiliza o vídeo (o(a) formador(a) recebe e-mail
          com a descrição e o link) → o(a) <b>formador(a)</b> assiste no sistema e marca o ponto exato de
          cada problema → a MB corrige e reenvia a nova versão → o(a) formador(a) aprova.
          Todo o histórico fica registrado aqui, sem e-mails paralelos.
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
