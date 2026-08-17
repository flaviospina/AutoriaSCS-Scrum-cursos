<?php
session_start();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();

$id_file = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM tb_curso_files WHERE id_file=?");
$st->execute([$id_file]);
$f = $st->fetch();
if (!$f) { http_response_code(404); exit("Arquivo não encontrado."); }

$curso = curso_get((int)$f['id_curso']);
if (!$curso) { http_response_code(404); exit("Curso não encontrado."); }

// permissão
if ($u['role'] === 'PROFESSOR' && (int)$curso['id_professor'] !== (int)$u['id_user']) {
  http_response_code(403); exit("Sem permissão.");
}

$base = realpath(__DIR__ . '/../storage');
$path = $base . "/cursos/{$f['id_curso']}/{$f['stored_name']}";

if (!file_exists($path)) { http_response_code(404); exit("Arquivo ausente no storage."); }

audit_log('arquivo_baixado', 'curso', (int)$f['id_curso'], null, ['arquivo' => $f['original_name']]);

header("Content-Type: {$f['mime_type']}");
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="'.basename($f['original_name']).'"');
readfile($path);
exit;
