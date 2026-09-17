<?php
/** Entrega as imagens dos tutoriais (storage/tutoriais/img) somente a usuários logados, conforme o perfil. */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/tutorial_repo.php';

require_login();
$u = auth_user();

$f = basename(trim($_GET['f'] ?? ''));
if (!tutorial_imagem_permitida($u, $f)) { http_response_code(403); exit; }
$path = tutorial_dir_img() . '/' . $f;
if (!is_file($path)) { http_response_code(404); exit; }

session_write_close();
header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
