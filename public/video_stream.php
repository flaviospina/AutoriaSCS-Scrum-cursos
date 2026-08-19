<?php
/**
 * Entrega o arquivo de vídeo de uma versão com suporte a HTTP Range —
 * necessário para o player permitir avançar/retroceder (seek) e para a
 * marcação de pontos exatos na revisão.
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
if (!is_staff() && (int)$video['id_professor'] !== (int)$u['id_user']) {
  http_response_code(403); exit("Sem permissão.");
}

$path = video_storage_dir((int)$video['id_curso']) . '/' . $versao['stored_name'];
if (!is_file($path)) { http_response_code(404); exit("Arquivo ausente no storage."); }

$size = filesize($path);
$mime = $versao['mime_type'] ?: 'video/mp4';

$start = 0;
$end = $size - 1;

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
  header('HTTP/1.1 206 Partial Content');
  header("Content-Range: bytes {$start}-{$end}/{$size}");
} else {
  header('HTTP/1.1 200 OK');
}

header("Content-Type: {$mime}");
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . basename($versao['original_name']) . '"');

// envia em blocos para não estourar memória
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
