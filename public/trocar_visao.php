<?php
/**
 * Alternar visualização (ADMIN): ver o sistema como outro perfil.
 * Validação no backend (somente admin real), auditado; qualquer outro perfil recebe 403.
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit("Método não permitido.");
}
csrf_check();

if (!is_admin_real()) {
  http_response_code(403);
  exit("Acesso negado: a alternância de visualização é exclusiva do perfil ADMIN.");
}

try {
  visao_alternar(trim($_POST['como'] ?? ''));
} catch (Throwable $e) {
  exit(htmlspecialchars($e->getMessage()));
}

$volta = trim($_POST['voltar'] ?? '');
// só aceita caminhos internos (evita redirecionamento aberto)
if ($volta === '' || !preg_match('#^[a-z_]+\.php(\?[^\s]*)?$#i', $volta)) $volta = 'dashboard.php';
header("Location: {$volta}");
exit;
