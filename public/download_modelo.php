<?php
session_start();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM tb_modelos WHERE id_modelo=?");
$st->execute([$id]);
$m = $st->fetch();
if (!$m) { http_response_code(404); exit("Modelo não encontrado."); }

// usuários comuns só baixam modelos ativos
if (!$m['ativo'] && !in_array($u['role'], ['TI','ADMIN'], true)) {
  http_response_code(403); exit("Modelo desativado.");
}

$base = realpath(__DIR__ . '/../storage');
$path = $base . "/modelos/{$m['stored_name']}";
if (!file_exists($path)) { http_response_code(404); exit("Arquivo ausente no storage."); }

audit_log('modelo_baixado', 'modelo', $id, null, ['titulo' => $m['titulo'], 'versao' => $m['versao']]);

header("Content-Type: {$m['mime_type']}");
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="'.basename($m['original_name']).'"');
readfile($path);
exit;
