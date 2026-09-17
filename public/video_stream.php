<?php
/**
 * Entrega o vídeo de uma versão com suporte a HTTP Range — necessário para o
 * player permitir avançar/retroceder (seek) e para a marcação de pontos exatos.
 *
 * Origem UPLOAD: arquivo em storage/videos (como sempre).
 * Origem DRIVE (V12): o trecho pedido é buscado na Google Drive API e
 * repassado ao navegador em pedaços limitados (chunk_mb), sem gravar em disco —
 * cada requisição PHP dura poucos segundos, compatível com hospedagem compartilhada.
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/video_repo.php';

require_login();
$u = auth_user();

$idVersao = (int)($_GET['id'] ?? 0);
$versao = video_versao_get($idVersao);
if (!$versao) { http_response_code(404); exit("Versão não encontrada."); }

$video = video_get((int)$versao['id_video']);
if (!$video) { http_response_code(404); exit("Vídeo não encontrado."); }

// mesma regra de visualização do curso
if (!is_staff() && !curso_eh_professor(['id_curso' => (int)$video['id_curso'], 'id_professor' => (int)$video['id_professor']], (int)$u['id_user'])) {
  http_response_code(403); exit("Sem permissão.");
}

session_write_close(); // libera a sessão durante a transmissão
@set_time_limit(0);
while (ob_get_level() > 0) ob_end_clean();

$ehDrive = video_versao_eh_drive($versao);

if ($ehDrive) {
  if (!drive_configurado()) { http_response_code(503); exit("Integração com o Google Drive não configurada."); }
  $size = (int)$versao['file_size'];
  if ($size <= 0) { // tamanho desconhecido (cadastro feito sem a API): consulta e grava
    try {
      $meta = drive_file_meta($versao['drive_file_id']);
      $size = (int)$meta['size'];
      db()->prepare("UPDATE tb_video_versoes SET file_size=?, mime_type=?, original_name=? WHERE id_versao=?")
        ->execute([$size, $meta['mime'], $meta['name'], $idVersao]);
      $versao['mime_type'] = $meta['mime'];
    } catch (Throwable $e) {
      http_response_code(502); exit("Google Drive: " . $e->getMessage());
    }
  }
  $chunk = max(1, (int)(drive_config()['chunk_mb'] ?? 8)) * 1024 * 1024;
} else {
  $path = video_storage_dir((int)$video['id_curso']) . '/' . $versao['stored_name'];
  if (!is_file($path)) { http_response_code(404); exit("Arquivo ausente no storage."); }
  $size = filesize($path);
  $chunk = 0; // local: entrega o intervalo pedido inteiro
}

$mime = $versao['mime_type'] ?: 'video/mp4';
$start = 0;
$end = $size - 1;
$parcial = false;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
  if ($m[1] !== '') $start = (int)$m[1];
  if ($m[2] !== '') $end = (int)$m[2];
  if ($m[1] === '' && $m[2] !== '') { // sufixo: últimos N bytes
    $start = max(0, $size - (int)$m[2]);
    $end = $size - 1;
  }
  if ($start > $end || $start >= $size) {
    header('HTTP/1.1 416 Range Not Satisfiable');
    header("Content-Range: bytes */{$size}");
    exit;
  }
  $end = min($end, $size - 1);
  $parcial = true;
}
// Drive: limita o trecho por requisição (o navegador pede o restante em seguida)
if ($chunk > 0 && ($end - $start + 1) > $chunk) {
  $end = $start + $chunk - 1;
  $parcial = true;
}

if ($parcial) {
  header('HTTP/1.1 206 Partial Content');
  header("Content-Range: bytes {$start}-{$end}/{$size}");
} else {
  header('HTTP/1.1 200 OK');
}
header("Content-Type: {$mime}");
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . basename($versao['original_name'] ?: 'video.mp4') . '"');

if ($ehDrive) {
  $status = drive_stream_range($versao['drive_file_id'], $start, $end);
  if ($status >= 400) error_log("video_stream: Google Drive HTTP {$status} (versão {$idVersao})");
  exit;
}

// local: envia em blocos para não estourar memória
$fp = fopen($path, 'rb');
fseek($fp, $start);
$restante = $end - $start + 1;
while ($restante > 0 && !feof($fp) && connection_status() === CONNECTION_NORMAL) {
  $bloco = fread($fp, min(1024 * 512, $restante));
  echo $bloco;
  flush();
  $restante -= strlen($bloco);
}
fclose($fp);
exit;
