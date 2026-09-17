<?php
/**
 * Tutoriais do sistema (V12) — servidos com controle de acesso:
 *   ADMIN vê o índice e todos os tutoriais; os demais perfis veem SOMENTE o
 *   tutorial do seu nível de acesso (tentativa de abrir outro → 403).
 * O conteúdo fica fora de public/ (app/tutoriais/*.html); as imagens em
 * storage/tutoriais/img são entregues por tutorial_img.php (com login).
 * Botões: Imprimir (window.print + CSS de impressão) e Exportar PDF
 * (tutorial_pdf.php — PDF pré-gerado em storage/tutoriais/pdf).
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/tutorial_repo.php';

require_login();
$u = auth_user();

$p = trim($_GET['p'] ?? '');
$meu = tutorial_perfil_usuario($u);

if ($p === '') {
  if (!tutorial_ve_todos($u)) { header("Location: tutorial.php?p={$meu}"); exit; }
  $p = 'index';
}
if (!tutorial_pode_ver($u, $p)) {
  http_response_code(403);
  exit("Acesso negado: este tutorial não pertence ao seu nível de acesso.");
}

$html = tutorial_html($p, $u);
if ($html === null) { http_response_code(404); exit("Tutorial não encontrado."); }

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
echo $html;
