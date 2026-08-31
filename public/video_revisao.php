<?php
/**
 * Revisão do vídeo: player integrado, marcações no timecode (TI),
 * respostas do formador, controle de status, versões e aprovação final.
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

$idVideo = (int)($_GET['id'] ?? 0);
$video = video_get($idVideo);
if (!$video) { http_response_code(404); echo "Vídeo não encontrado."; exit; }

$idCurso = (int)$video['id_curso'];
$ehDono = (int)$video['id_professor'] === (int)$u['id_user'];
if (!is_staff() && !$ehDono) { http_response_code(403); echo "Acesso negado."; exit; }

// A análise do vídeo é feita pelo(a) formador(a); a MB produz e reenvia as versões.
$ehAnalista   = $ehDono || is_admin();
$ehProdutor   = perm('recebe_email_insercao') || is_admin();

$erro = null; $ok = null;
$okMap = [
  'marcado'   => 'Apontamento registrado. A MB Estúdios foi avisada por e-mail.',
  'respondido'=> 'Resposta registrada.',
  'status'    => 'Status do apontamento atualizado.',
  'versao'    => 'Nova versão disponibilizada. O(a) formador(a) foi avisado(a) por e-mail.',
  'aprovado'  => 'Vídeo aprovado! A MB Estúdios e a TI foram avisadas por e-mail. 🎉',
  'excluido'  => 'Apontamento excluído.',
];
if (isset($okMap[$_GET['ok'] ?? ''])) $ok = $okMap[$_GET['ok']];

$versoes = video_versoes($idVideo);
$versaoAtualId = $versoes ? (int)$versoes[0]['id_versao'] : 0;

/* ---------------- ações ---------------- */

// TI registra uma marcação (somente na versão mais recente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_marcacao') {
  csrf_check();
  try {
    if (!$ehAnalista) throw new Exception("Somente o(a) formador(a) do curso registra apontamentos no vídeo.");
    if (!$versaoAtualId) throw new Exception("Nenhuma versão enviada ainda.");

    $tempo = (float)str_replace(',', '.', $_POST['tempo_seg'] ?? '-1');
    if ($tempo < 0) throw new Exception("Tempo inválido.");
    $frame = ($_POST['frame'] ?? '') !== '' ? max(0, (int)$_POST['frame']) : null;
    $categoria = $_POST['categoria'] ?? 'CONTEUDO';
    if (!isset(VIDEO_CATEGORIAS[$categoria])) $categoria = 'CONTEUDO';
    $descricao = trim($_POST['descricao'] ?? '');
    if ($descricao === '') throw new Exception("Descreva o problema e a orientação de correção.");

    // captura opcional do frame (enviada pelo player como imagem base64)
    $captura = null;
    $b64 = $_POST['captura_b64'] ?? '';
    if ($b64 !== '' && preg_match('#^data:image/(png|jpeg);base64,#', $b64, $mm)) {
      $bin = base64_decode(substr($b64, strpos($b64, ',') + 1), true);
      if ($bin !== false && strlen($bin) > 100 && strlen($bin) < 8 * 1024 * 1024) {
        $dirCap = video_storage_dir($idCurso) . '/capturas';
        if (!is_dir($dirCap)) mkdir($dirCap, 0755, true);
        $captura = bin2hex(random_bytes(16)) . ($mm[1] === 'jpeg' ? '.jpg' : '.png');
        file_put_contents($dirCap . '/' . $captura, $bin);
      }
    }

    db()->prepare("
      INSERT INTO tb_video_marcacoes (id_versao, id_user, tempo_seg, frame, categoria, descricao, captura)
      VALUES (?,?,?,?,?,?,?)
    ")->execute([$versaoAtualId, $u['id_user'], $tempo, $frame, $categoria, $descricao, $captura]);

    video_atualiza_status($idVideo);
    audit_log('video_marcacao_criada', 'curso', $idCurso, null, [
      'video' => $video['titulo'], 'tempo' => video_fmt_tempo($tempo),
      'frame' => $frame, 'categoria' => $categoria, 'descricao' => $descricao,
    ]);
    notify_video_marcacao($video, 1);

    header("Location: video_revisao.php?id={$idVideo}&ok=marcado");
    exit;
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

// Resposta (formador ou equipe) em uma marcação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'responder') {
  csrf_check();
  try {
    $idMarc = (int)($_POST['id_marcacao'] ?? 0);
    $conteudo = trim($_POST['conteudo'] ?? '');
    if ($conteudo === '') throw new Exception("Escreva a resposta.");
    $chk = db()->prepare("
      SELECT m.id_marcacao FROM tb_video_marcacoes m
      JOIN tb_video_versoes vv ON vv.id_versao = m.id_versao
      WHERE m.id_marcacao=? AND vv.id_video=?
    ");
    $chk->execute([$idMarc, $idVideo]);
    if (!$chk->fetch()) throw new Exception("Apontamento não encontrado.");

    db()->prepare("INSERT INTO tb_video_respostas (id_marcacao, id_user, conteudo) VALUES (?,?,?)")
      ->execute([$idMarc, $u['id_user'], $conteudo]);
    audit_log('video_marcacao_respondida', 'curso', $idCurso, null, ['video' => $video['titulo'], 'marcacao' => $idMarc]);
    // avisa a outra parte da conversa
    notify_video_resposta($video, $u['nome'], !$ehDono);

    header("Location: video_revisao.php?id={$idVideo}&ok=respondido#marc-{$idMarc}");
    exit;
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

// Mudança de status de uma marcação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'status_marcacao') {
  csrf_check();
  try {
    $idMarc = (int)($_POST['id_marcacao'] ?? 0);
    $novo = $_POST['novo_status'] ?? '';
    if (!isset(VIDEO_MARC_STATUS[$novo])) throw new Exception("Status inválido.");

    // a MB marca o andamento da correção; a aprovação do apontamento é do(a) formador(a)
    $permitidosProdutor = ['EM_CORRECAO', 'CORRIGIDO'];
    if (!$ehAnalista && (!$ehProdutor || !in_array($novo, $permitidosProdutor, true))) {
      throw new Exception("Sem permissão para definir este status.");
    }

    $chk = db()->prepare("
      SELECT m.status FROM tb_video_marcacoes m
      JOIN tb_video_versoes vv ON vv.id_versao = m.id_versao
      WHERE m.id_marcacao=? AND vv.id_video=?
    ");
    $chk->execute([$idMarc, $idVideo]);
    $antes = $chk->fetch();
    if (!$antes) throw new Exception("Apontamento não encontrado.");

    db()->prepare("UPDATE tb_video_marcacoes SET status=? WHERE id_marcacao=?")->execute([$novo, $idMarc]);
    video_atualiza_status($idVideo);
    audit_log('video_marcacao_status', 'curso', $idCurso,
      ['status' => $antes['status']], ['status' => $novo, 'marcacao' => $idMarc]);

    header("Location: video_revisao.php?id={$idVideo}&ok=status#marc-{$idMarc}");
    exit;
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

// Formador reenvia nova versão do vídeo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'nova_versao') {
  csrf_check();
  try {
    if (!$ehProdutor) throw new Exception("Somente a MB Estúdios disponibiliza novas versões do vídeo.");
    $obs = trim($_POST['observacao'] ?? '') ?: null;
    $arq = video_receber_upload($idCurso, $_FILES['arquivo'] ?? null);

    $numero = $versoes ? ((int)$versoes[0]['numero'] + 1) : 1;
    db()->prepare("
      INSERT INTO tb_video_versoes (id_video, numero, id_user, original_name, stored_name, mime_type, file_size, observacao)
      VALUES (?,?,?,?,?,?,?,?)
    ")->execute([$idVideo, $numero, $u['id_user'], $arq['original'], $arq['stored'], $arq['mime'], $arq['size'], $obs]);

    // nova versão reabre a análise
    db()->prepare("UPDATE tb_videos SET status='EM_ANALISE' WHERE id_video=?")->execute([$idVideo]);
    audit_log('video_nova_versao', 'curso', $idCurso, null,
      ['video' => $video['titulo'], 'versao' => $numero, 'arquivo' => $arq['original'], 'observacao' => $obs]);
    notify_video_disponivel($video, $numero, $obs);

    header("Location: video_revisao.php?id={$idVideo}&ok=versao");
    exit;
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

// TI aprova o vídeo (todas as marcações da versão atual resolvidas)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'aprovar_video') {
  csrf_check();
  try {
    if (!$ehAnalista) throw new Exception("Somente o(a) formador(a) do curso aprova o vídeo.");
    if (!$versaoAtualId) throw new Exception("Nenhuma versão enviada ainda.");
    $sm = db()->prepare("SELECT COUNT(*) n FROM tb_video_marcacoes WHERE id_versao=? AND status IN ('PENDENTE','EM_CORRECAO')");
    $sm->execute([$versaoAtualId]);
    if ((int)$sm->fetch()['n'] > 0) {
      throw new Exception("Há apontamentos pendentes ou em correção na versão atual. Conclua-os antes de aprovar.");
    }
    db()->prepare("UPDATE tb_videos SET status='APROVADO' WHERE id_video=?")->execute([$idVideo]);
    // marca os apontamentos restantes da versão atual como aprovados
    db()->prepare("UPDATE tb_video_marcacoes SET status='APROVADO' WHERE id_versao=? AND status='CORRIGIDO'")
      ->execute([$versaoAtualId]);
    audit_log('video_aprovado', 'curso', $idCurso, null, ['video' => $video['titulo']]);
    notify_video_aprovado($video);

    header("Location: video_revisao.php?id={$idVideo}&ok=aprovado");
    exit;
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

// TI exclui uma marcação própria criada por engano (sem respostas)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excluir_marcacao') {
  csrf_check();
  try {
    $idMarc = (int)($_POST['id_marcacao'] ?? 0);
    $st = db()->prepare("
      SELECT m.*, (SELECT COUNT(*) FROM tb_video_respostas r WHERE r.id_marcacao=m.id_marcacao) n_resp
      FROM tb_video_marcacoes m
      JOIN tb_video_versoes vv ON vv.id_versao = m.id_versao
      WHERE m.id_marcacao=? AND vv.id_video=?
    ");
    $st->execute([$idMarc, $idVideo]);
    $m = $st->fetch();
    if (!$m) throw new Exception("Apontamento não encontrado.");
    $podeExcluir = is_admin() || ($ehAnalista && (int)$m['id_user'] === (int)$u['id_user'] && (int)$m['n_resp'] === 0);
    if (!$podeExcluir) throw new Exception("Apontamentos com respostas só podem ser excluídos pelo admin.");

    db()->prepare("DELETE FROM tb_video_marcacoes WHERE id_marcacao=?")->execute([$idMarc]);
    if (!empty($m['captura'])) @unlink(video_storage_dir($idCurso) . '/capturas/' . $m['captura']);
    video_atualiza_status($idVideo);
    audit_log('video_marcacao_excluida', 'curso', $idCurso,
      ['marcacao' => $idMarc, 'descricao' => $m['descricao']], null);

    header("Location: video_revisao.php?id={$idVideo}&ok=excluido");
    exit;
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

/* ---------------- dados da tela ---------------- */

$versoes = video_versoes($idVideo);           // recarrega após ações
$versaoAtualId = $versoes ? (int)$versoes[0]['id_versao'] : 0;

$idVersaoVer = (int)($_GET['v'] ?? 0) ?: $versaoAtualId;
$versaoVer = null;
foreach ($versoes as $vv) if ((int)$vv['id_versao'] === $idVersaoVer) { $versaoVer = $vv; break; }
if (!$versaoVer && $versoes) { $versaoVer = $versoes[0]; $idVersaoVer = (int)$versaoVer['id_versao']; }

$vendoAtual = $idVersaoVer === $versaoAtualId;
$marcacoes = $idVersaoVer ? video_marcacoes($idVersaoVer) : [];
$stv = VIDEO_STATUS[$video['status']] ?? VIDEO_STATUS['EM_ANALISE'];
$abertas = 0;
foreach ($marcacoes as $m) if (in_array($m['status'], ['PENDENTE','EM_CORRECAO'], true)) $abertas++;

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">🎬 <?= htmlspecialchars($video['titulo']) ?></h1>
    <div class="text-muted small">
      <?= ((int)$video['modulo']) ? 'Módulo ' . (int)$video['modulo'] : 'Geral' ?> •
      Curso: <b><?= htmlspecialchars($video['nome_curso']) ?></b> •
      Formador(a): <b><?= htmlspecialchars($video['professor_nome']) ?></b> •
      <span class="badge" style="background:<?= $stv['cor'] ?>;color:#08131f"><?= $stv['label'] ?></span>
    </div>
    <?php if (!empty($video['descricao'])): ?>
      <div class="video-descricao">
        <span class="video-descricao-titulo">Descrição enviada pela MB Estúdios</span>
        <?= nl2br(htmlspecialchars($video['descricao'])) ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="curso_videos.php?id=<?= (int)$idCurso ?>">Vídeos do curso</a>
    <?php if ($ehAnalista && $vendoAtual && $video['status'] !== 'APROVADO'): ?>
      <form method="post" class="d-inline"
            data-confirm="Aprovar este vídeo? O(a) formador(a) será avisado(a) por e-mail."
            data-confirm-title="Aprovação final" data-confirm-btn="Sim, aprovar">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="aprovar_video">
        <button class="btn btn-success" <?= $abertas > 0 ? 'disabled title="Há apontamentos em aberto"' : '' ?>>
          ✓ Aprovar vídeo
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if (!$versoes): ?>
  <div class="alert alert-warning">Nenhuma versão enviada ainda.</div>
<?php else: ?>

<div class="row g-3">
  <!-- player + registro de marcação -->
  <div class="col-12 col-xl-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0">
            Versão v<?= (int)$versaoVer['numero'] ?>
            <?= $vendoAtual ? '<span class="badge bg-info text-dark">atual</span>' : '<span class="badge bg-secondary">antiga</span>' ?>
          </h2>
          <?php if (count($versoes) > 1): ?>
            <form method="get" class="d-flex align-items-center gap-2">
              <input type="hidden" name="id" value="<?= (int)$idVideo ?>">
              <label class="small text-muted">Comparar versão:</label>
              <select class="form-select form-select-sm" name="v" onchange="this.form.submit()" style="width:auto">
                <?php foreach ($versoes as $vv): ?>
                  <option value="<?= (int)$vv['id_versao'] ?>" <?= (int)$vv['id_versao'] === $idVersaoVer ? 'selected' : '' ?>>
                    v<?= (int)$vv['numero'] ?> — <?= htmlspecialchars(substr($vv['created_at'], 0, 16)) ?>
                    (<?= (int)$vv['n_marcacoes'] ?> apont.)
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
        </div>

        <video id="player" controls preload="metadata" crossorigin="use-credentials"
               style="width:100%;max-height:56vh;background:#000;border-radius:10px"
               src="video_stream.php?id=<?= (int)$idVersaoVer ?>"></video>

        <div class="small text-muted mt-1 d-flex flex-wrap gap-3">
          <span>Arquivo: <?= htmlspecialchars($versaoVer['original_name']) ?>
            (<?= number_format($versaoVer['file_size']/1024/1024, 1, ',', '.') ?> MB)</span>
          <span>Enviado por <?= htmlspecialchars($versaoVer['user_nome']) ?> em <?= htmlspecialchars($versaoVer['created_at']) ?></span>
          <?php if ($versaoVer['observacao']): ?><span>Obs.: <?= htmlspecialchars($versaoVer['observacao']) ?></span><?php endif; ?>
        </div>

        <?php if ($ehAnalista && $vendoAtual): ?>
          <hr class="my-3">
          <h3 class="h6 mb-2">Registrar apontamento neste ponto</h3>
          <form method="post" id="formMarcacao">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_marcacao">
            <input type="hidden" name="captura_b64" id="capturaB64">
            <div class="row g-2">
              <div class="col-6 col-md-3">
                <label class="form-label small">Tempo (mm:ss.mmm)</label>
                <div class="input-group input-group-sm">
                  <input class="form-control" name="tempo_txt" id="tempoTxt" placeholder="0:00.000" required>
                  <button class="btn btn-outline-primary" type="button" id="btnTempoAtual"
                          title="Usar o ponto atual do player">◉ agora</button>
                </div>
                <input type="hidden" name="tempo_seg" id="tempoSeg">
              </div>
              <div class="col-3 col-md-2">
                <label class="form-label small">FPS</label>
                <input class="form-control form-control-sm" id="fps" type="number" value="30" min="1" max="120">
              </div>
              <div class="col-3 col-md-2">
                <label class="form-label small">Frame</label>
                <input class="form-control form-control-sm" name="frame" id="frame" type="number" min="0">
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label small">Classificação</label>
                <select class="form-select form-select-sm" name="categoria">
                  <?php foreach (VIDEO_CATEGORIAS as $cod => $label): ?>
                    <option value="<?= $cod ?>"><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small">Problema identificado e orientação para correção</label>
                <textarea class="form-control form-control-sm" name="descricao" rows="2" required
                          placeholder="Ex.: Áudio com ruído a partir deste ponto — regravar a narração do trecho."></textarea>
              </div>
              <div class="col-12 d-flex flex-wrap gap-3 align-items-center">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="chkCaptura" checked>
                  <label class="form-check-label small" for="chkCaptura">Anexar captura do frame atual</label>
                </div>
                <button class="btn btn-primary btn-sm">Registrar apontamento</button>
              </div>
            </div>
          </form>
        <?php elseif ($ehAnalista && !$vendoAtual): ?>
          <div class="alert alert-warning small mt-3 mb-0">
            Você está vendo uma versão antiga — apontamentos só podem ser registrados na versão atual.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($ehProdutor && $video['status'] !== 'APROVADO'): ?>
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h3 class="h6 mb-2">Disponibilizar nova versão corrigida</h3>
          <form method="post" enctype="multipart/form-data" class="row g-2"
                data-confirm="Disponibilizar a nova versão? O(a) formador(a) será avisado(a) e a análise recomeça sobre ela."
                data-confirm-title="Nova versão" data-confirm-btn="Sim, disponibilizar">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="nova_versao">
            <div class="col-12 col-md-5">
              <input class="form-control form-control-sm" type="file" name="arquivo"
                     accept="video/mp4,video/webm,video/quicktime" required>
            </div>
            <div class="col-12 col-md-5">
              <input class="form-control form-control-sm" name="observacao" maxlength="500"
                     placeholder="O que foi corrigido nesta versão? (opcional)">
            </div>
            <div class="col-12 col-md-2">
              <button class="btn btn-outline-primary btn-sm w-100">Enviar v<?= (int)$versoes[0]['numero'] + 1 ?></button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- histórico de versões -->
    <div class="card shadow-sm mt-3">
      <div class="card-body">
        <h3 class="h6 mb-2">Histórico de versões</h3>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th>Versão</th><th>Enviada em</th><th>Por</th><th>Apontamentos</th><th>Observação</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($versoes as $vv): ?>
                <tr>
                  <td><b>v<?= (int)$vv['numero'] ?></b> <?= (int)$vv['id_versao'] === $versaoAtualId ? '<span class="badge bg-info text-dark">atual</span>' : '' ?></td>
                  <td class="text-nowrap small"><?= htmlspecialchars($vv['created_at']) ?></td>
                  <td class="small"><?= htmlspecialchars($vv['user_nome']) ?></td>
                  <td class="small"><?= (int)$vv['n_marcacoes'] ?> (<?= (int)$vv['n_abertas'] ?> em aberto)</td>
                  <td class="small"><?= htmlspecialchars($vv['observacao'] ?? '-') ?></td>
                  <td class="text-end">
                    <?php if ((int)$vv['id_versao'] !== $idVersaoVer): ?>
                      <a class="btn btn-sm btn-outline-secondary py-0"
                         href="video_revisao.php?id=<?= (int)$idVideo ?>&v=<?= (int)$vv['id_versao'] ?>">Ver</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- lista de marcações -->
  <div class="col-12 col-xl-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-2">
          Apontamentos da v<?= (int)$versaoVer['numero'] ?>
          (<?= count($marcacoes) ?><?= $abertas ? " • {$abertas} em aberto" : '' ?>)
        </h2>

        <?php if (!$marcacoes): ?>
          <div class="text-muted small">
            Nenhum apontamento nesta versão<?= $ehAnalista && $vendoAtual ? ' — assista ao vídeo e use "Registrar apontamento".' : '.' ?>
          </div>
        <?php endif; ?>

        <?php foreach ($marcacoes as $m):
          $sm = VIDEO_MARC_STATUS[$m['status']];
        ?>
          <div class="marc-card marc-status-<?= strtolower($m['status']) ?>" id="marc-<?= (int)$m['id_marcacao'] ?>">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
              <button type="button" class="btn btn-sm btn-outline-info marc-seek" data-t="<?= (float)$m['tempo_seg'] ?>">
                ▶ <?= video_fmt_tempo((float)$m['tempo_seg']) ?>
              </button>
              <?php if ($m['frame'] !== null): ?><span class="small text-muted">frame <?= (int)$m['frame'] ?></span><?php endif; ?>
              <span class="marc-tag marc-tag-categoria"><?= VIDEO_CATEGORIAS[$m['categoria']] ?? $m['categoria'] ?></span>
              <span class="marc-tag marc-tag-status" style="--marc-cor:<?= $sm['cor'] ?>"><?= $sm['label'] ?></span>
            </div>
            <div class="marc-desc"><?= nl2br(htmlspecialchars($m['descricao'])) ?></div>
            <div class="small text-muted mt-1">Por <?= htmlspecialchars($m['user_nome']) ?> em <?= htmlspecialchars($m['created_at']) ?></div>

            <?php if ($m['captura']): ?>
              <a href="video_captura.php?id=<?= (int)$m['id_marcacao'] ?>" target="_blank">
                <img src="video_captura.php?id=<?= (int)$m['id_marcacao'] ?>" alt="Captura do frame"
                     style="max-width:220px;border-radius:6px;margin-top:6px" loading="lazy">
              </a>
            <?php endif; ?>

            <?php foreach ($m['respostas'] as $r): ?>
              <div class="border-start ps-2 mt-2 small">
                <b><?= htmlspecialchars($r['user_nome']) ?></b>
                <span class="text-muted"><?= htmlspecialchars($r['created_at']) ?></span><br>
                <?= nl2br(htmlspecialchars($r['conteudo'])) ?>
              </div>
            <?php endforeach; ?>

            <div class="d-flex flex-wrap gap-1 mt-2 align-items-center marc-acoes">
              <!-- resposta -->
              <form method="post" class="d-flex gap-1 flex-grow-1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="responder">
                <input type="hidden" name="id_marcacao" value="<?= (int)$m['id_marcacao'] ?>">
                <input class="form-control form-control-sm" name="conteudo" placeholder="Responder..." required>
                <button class="btn btn-sm btn-outline-primary">Enviar</button>
              </form>

              <!-- status -->
              <?php
                $opcoes = [];
                if ($ehAnalista) $opcoes = ['PENDENTE','EM_CORRECAO','CORRIGIDO','APROVADO'];
                elseif ($ehProdutor) $opcoes = ['EM_CORRECAO','CORRIGIDO'];
                $opcoes = array_values(array_diff($opcoes, [$m['status']]));
              ?>
              <?php if ($opcoes): ?>
                <form method="post" class="d-flex gap-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="status_marcacao">
                  <input type="hidden" name="id_marcacao" value="<?= (int)$m['id_marcacao'] ?>">
                  <select class="form-select form-select-sm" name="novo_status" style="width:auto">
                    <?php foreach ($opcoes as $o): ?>
                      <option value="<?= $o ?>"><?= VIDEO_MARC_STATUS[$o]['label'] ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-success" title="Aplicar status">OK</button>
                </form>
              <?php endif; ?>

              <?php if (is_admin() || ($ehAnalista && (int)$m['id_user'] === (int)$u['id_user'] && !$m['respostas'])): ?>
                <form method="post" class="d-inline"
                      data-confirm="Excluir este apontamento?" data-confirm-title="Excluir apontamento"
                      data-confirm-type="danger" data-confirm-btn="Sim, excluir">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="excluir_marcacao">
                  <input type="hidden" name="id_marcacao" value="<?= (int)$m['id_marcacao'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0">✕</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var player = document.getElementById('player');
  if (!player) return;

  // clicar no tempo de um apontamento leva o player ao ponto exato
  document.querySelectorAll('.marc-seek').forEach(function (b) {
    b.addEventListener('click', function () {
      player.currentTime = parseFloat(b.dataset.t) || 0;
      player.play();
      player.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  var form = document.getElementById('formMarcacao');
  if (!form) return;
  var tempoTxt = document.getElementById('tempoTxt');
  var tempoSeg = document.getElementById('tempoSeg');
  var fps = document.getElementById('fps');
  var frame = document.getElementById('frame');
  var chkCap = document.getElementById('chkCaptura');
  var capB64 = document.getElementById('capturaB64');

  function fmt(t) {
    var m = Math.floor(t / 60), s = t - m * 60;
    return m + ':' + (s < 10 ? '0' : '') + s.toFixed(3);
  }
  function parseTempo(txt) {
    txt = (txt || '').trim().replace(',', '.');
    if (txt === '') return NaN;
    var partes = txt.split(':').map(parseFloat);
    if (partes.some(isNaN)) return NaN;
    var t = 0;
    partes.forEach(function (p) { t = t * 60 + p; });
    return t;
  }
  function syncFrame() {
    var t = parseTempo(tempoTxt.value);
    if (!isNaN(t)) frame.value = Math.round(t * (parseFloat(fps.value) || 30));
  }

  document.getElementById('btnTempoAtual').addEventListener('click', function () {
    player.pause();
    tempoTxt.value = fmt(player.currentTime);
    syncFrame();
  });
  tempoTxt.addEventListener('change', syncFrame);
  fps.addEventListener('change', syncFrame);

  form.addEventListener('submit', function (e) {
    var t = parseTempo(tempoTxt.value);
    if (isNaN(t) || t < 0) {
      e.preventDefault();
      alert('Tempo inválido. Use o formato mm:ss.mmm ou clique em "◉ agora".');
      return;
    }
    tempoSeg.value = t.toFixed(3);
    if (chkCap.checked) {
      try {
        // captura o frame exibido no player (mesma origem — permitido)
        if (Math.abs(player.currentTime - t) > 0.5) player.currentTime = t;
        var c = document.createElement('canvas');
        c.width = player.videoWidth; c.height = player.videoHeight;
        c.getContext('2d').drawImage(player, 0, 0, c.width, c.height);
        capB64.value = c.toDataURL('image/jpeg', 0.85);
      } catch (err) {
        capB64.value = ''; // sem captura, o apontamento segue normalmente
      }
    } else {
      capB64.value = '';
    }
  });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
