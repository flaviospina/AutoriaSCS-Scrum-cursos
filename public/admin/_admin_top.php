<?php
// Cabeçalho comum das páginas Admin: exige login + perfil ADMIN.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/status_repo.php';
require_once __DIR__ . '/../../app/csrf.php';

require_login();
if (!is_admin()) {
  http_response_code(403);
  exit('Acesso restrito ao perfil ADMIN.');
}

$LP = '../'; // prefixo de links do layout (estamos em /admin)
