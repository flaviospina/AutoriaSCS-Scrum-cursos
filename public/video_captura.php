<?php
/** Entrega a captura de frame anexada a uma marcação (imagem PNG/JPEG). */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/video_repo.php';

require_login();
$u = auth_user();

$idMarc = (int)($_GET['id'] ?? 0);
$st = db()->prepare("
  SELECT m.captura, vv.id_video
  FROM tb_video_marcacoes m
  JOIN tb_video_versoes vv ON vv.id_versao = m.id_versao
  WHERE m.id_marcacao = ?
");
$st->execute([$idMarc]);
$m = $st->fetch();
if (!$m || empty($m['captura'])) { http_response_code(404); exit("Captura não encontrada."); }

$video = video_get((int)$m['id_video']);
if (!$video) { http_response_code(404); exit("Vídeo não encontrado."); }
if (!is_staff() && (int)$video['id_professor'] !== (int)$u['id_user']) {
  http_response_code(403); exit("Sem permissão.");
}

$path = video_storage_dir((int)$video['id_curso']) . '/capturas/' . $m['captura'];
if (!is_file($path)) { http_response_code(404); exit("Arquivo ausente."); }

$mime = str_ends_with($m['captura'], '.jpg') ? 'image/jpeg' : 'image/png';
header("Content-Type: {$mime}");
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
