<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();
csrf_check();

$id_curso = (int)($_POST['id_curso'] ?? 0);
$curso = curso_get($id_curso);
if (!$curso) { http_response_code(404); exit("Curso não encontrado."); }

// permissão
if (!is_staff() && (int)$curso['id_professor'] !== (int)$u['id_user']) {
  http_response_code(403); exit("Sem permissão.");
}

$categoria = $_POST['categoria'] ?? 'OUTROS';
$allowedCat = ['PLANEJAMENTO','PRODUCAO','ENTREGA','OUTROS'];
if (!in_array($categoria, $allowedCat, true)) $categoria = 'OUTROS';

// módulo do curso a que o arquivo pertence (0 = geral)
$modulo = (int)($_POST['modulo'] ?? 0);
if ($modulo < 0 || $modulo > 8) $modulo = 0;

if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
  header("Location: curso_detalhe.php?id={$id_curso}&err=upload");
  exit;
}

// validações
$maxSize = 25 * 1024 * 1024; // 25MB
if ($_FILES['arquivo']['size'] > $maxSize) {
  header("Location: curso_detalhe.php?id={$id_curso}&err=size");
  exit;
}

$original = $_FILES['arquivo']['name'];
$tmp = $_FILES['arquivo']['tmp_name'];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmp) ?: 'application/octet-stream';

$allowedMime = [
  'application/pdf',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'application/vnd.ms-powerpoint',
  'application/zip',
  'video/mp4',
  'audio/mpeg',
  'image/jpeg',
  'image/png'
];

if (!in_array($mime, $allowedMime, true)) {
  header("Location: curso_detalhe.php?id={$id_curso}&err=mime");
  exit;
}

// storage
$base = realpath(__DIR__ . '/../storage');
if (!$base) { http_response_code(500); exit("Storage não encontrado."); }

$dir = $base . "/cursos/{$id_curso}";
if (!is_dir($dir)) mkdir($dir, 0755, true);

$ext = pathinfo($original, PATHINFO_EXTENSION);
$stored = bin2hex(random_bytes(16)) . ($ext ? ".{$ext}" : "");

$dest = $dir . "/" . $stored;
if (!move_uploaded_file($tmp, $dest)) {
  header("Location: curso_detalhe.php?id={$id_curso}&err=move");
  exit;
}

db()->prepare("
  INSERT INTO tb_curso_files (id_curso, id_user, original_name, stored_name, mime_type, file_size, categoria, modulo)
  VALUES (?,?,?,?,?,?,?,?)
")->execute([
  $id_curso,
  $u['id_user'],
  $original,
  $stored,
  $mime,
  (int)$_FILES['arquivo']['size'],
  $categoria,
  $modulo
]);

audit_log('arquivo_enviado', 'curso', $id_curso, null, [
  'arquivo' => $original,
  'categoria' => $categoria,
  'modulo' => $modulo,
  'tamanho' => (int)$_FILES['arquivo']['size'],
]);

header("Location: curso_detalhe.php?id={$id_curso}&ok=upload");
exit;
