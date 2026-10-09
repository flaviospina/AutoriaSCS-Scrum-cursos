<?php
/** Download dos arquivos de uma análise de slides (PDF/PPTX no padrão ou original). */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/audit.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/slides_repo.php';

require_login();
$u = auth_user();
$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); exit('Curso não encontrado.'); }
if (!is_staff() && !curso_eh_professor($curso, (int)$u['id_user'])) { http_response_code(403); exit('Sem permissão.'); }

$token = $_GET['t'] ?? ''; $f = $_GET['f'] ?? 'pdf';
$path = slides_arquivo($id, $token, $f);
if (!$path) { http_response_code(404); exit('Arquivo não encontrado.'); }
$reg = slides_registro($id, $token);
$base = preg_replace('/[\\\\\/:*?"<>|]+/', '-', "Slides - Modulo {$reg['modulo']} - " . mb_substr($curso['nome_curso'], 0, 60));
$nomes = ['pdf' => "$base.pdf", 'pptx' => "$base.pptx", 'original' => 'original-' . $reg['original']];
$mimes = ['pdf' => 'application/pdf', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'original' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
audit_log('slides_padrao_baixado', 'curso', $id, null, ['token' => $token, 'arquivo' => $f]);
session_write_close();
header('Content-Type: ' . $mimes[$f]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($f === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $nomes[$f] . '"');
readfile($path);
exit;
