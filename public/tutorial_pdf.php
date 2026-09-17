<?php
/** Download do tutorial em PDF (storage/tutoriais/pdf/{perfil}.pdf), respeitando o nível de acesso. */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/audit.php';
require_once __DIR__ . '/../app/tutorial_repo.php';

require_login();
$u = auth_user();

$p = trim($_GET['p'] ?? '');
if (!in_array($p, TUTORIAL_PERFIS, true) || !tutorial_pode_ver($u, $p)) {
  http_response_code(403); exit("Acesso negado: este tutorial não pertence ao seu nível de acesso.");
}
$path = tutorial_dir_pdf() . '/' . $p . '.pdf';
if (!is_file($path)) {
  http_response_code(404);
  exit("PDF ainda não gerado para este tutorial. Use Imprimir → \"Salvar como PDF\" ou avise a TI (ti.cecape@scseduca.com.br).");
}
audit_log('tutorial_pdf_baixado', 'sistema', null, null, ['tutorial' => $p]);
session_write_close();

$nomes = ['professor' => 'Tutorial-Professor-Formador', 'ti' => 'Tutorial-Equipe-TI', 'mb' => 'Tutorial-MB-Estudios', 'admin' => 'Tutorial-Administrador'];
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $nomes[$p] . '-AutoriaSCS.pdf"');
readfile($path);
exit;
