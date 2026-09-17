<?php
/**
 * Parcial: escolha da fonte do vídeo — arquivo (até 512MB) ou link do Google
 * Drive (V12). Usado em curso_videos.php (v1) e video_revisao.php (nova versão).
 * Espera $fonteSufixo (string) para IDs únicos quando houver mais de um formulário.
 */
$fs = $fonteSufixo ?? '';
$driveOk = function_exists('drive_configurado') && drive_configurado();
$svcEmail = $driveOk ? drive_service_email() : null;
?>
<div class="mb-2">
  <label class="form-label small d-block">Como disponibilizar o vídeo?</label>
  <div class="btn-group btn-group-sm w-100" role="group">
    <input type="radio" class="btn-check" name="fonte" id="fonteDrive<?= $fs ?>" value="drive" checked>
    <label class="btn btn-outline-primary" for="fonteDrive<?= $fs ?>">🔗 Link do Google Drive</label>
    <input type="radio" class="btn-check" name="fonte" id="fonteArquivo<?= $fs ?>" value="arquivo">
    <label class="btn btn-outline-primary" for="fonteArquivo<?= $fs ?>">⬆ Enviar arquivo (até 512MB)</label>
  </div>
</div>
<div class="mb-2 fonte-drive<?= $fs ?>">
  <label class="form-label small">Link do vídeo no Google Drive</label>
  <input class="form-control form-control-sm" name="drive_url" id="driveUrl<?= $fs ?>" maxlength="500"
         placeholder="https://drive.google.com/file/d/.../view">
  <div class="form-text">
    <?php if ($driveOk): ?>
      A pasta (ou o arquivo) precisa estar compartilhada, como <b>Leitor</b>, com
      <code><?= htmlspecialchars($svcEmail) ?></code>. O sistema confere o acesso na hora e não copia o arquivo para o servidor.
    <?php else: ?>
      Compartilhe o arquivo com quem for analisar (ou "qualquer pessoa com o link" — Leitor). O vídeo abrirá no player do Google
      dentro do sistema; os apontamentos serão feitos informando o minuto/segundo.
    <?php endif; ?>
  </div>
</div>
<div class="mb-2 fonte-arquivo<?= $fs ?> d-none">
  <label class="form-label small">Arquivo de vídeo</label>
  <input class="form-control form-control-sm" type="file" name="arquivo" id="arquivo<?= $fs ?>" accept="video/mp4,video/webm,video/quicktime">
  <div class="form-text">Formato recomendado: MP4 (H.264). Limite: 512MB — acima disso, use o link do Google Drive.</div>
</div>
<script>
(function () {
  var rD = document.getElementById('fonteDrive<?= $fs ?>'), rA = document.getElementById('fonteArquivo<?= $fs ?>');
  var bD = document.querySelector('.fonte-drive<?= $fs ?>'), bA = document.querySelector('.fonte-arquivo<?= $fs ?>');
  var iD = document.getElementById('driveUrl<?= $fs ?>'), iA = document.getElementById('arquivo<?= $fs ?>');
  function aplica() {
    var drive = rD.checked;
    bD.classList.toggle('d-none', !drive); bA.classList.toggle('d-none', drive);
    iD.required = drive; iA.required = !drive;
    if (drive) iA.value = ''; else iD.value = '';
  }
  rD.addEventListener('change', aplica); rA.addEventListener('change', aplica); aplica();
})();
</script>
