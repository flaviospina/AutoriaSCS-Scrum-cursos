<?php

function csrf_token(): string {
  require_once __DIR__ . '/session.php';
  session_boot();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_field(): string {
  return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_check(): void {
  $sent = $_POST['csrf_token'] ?? '';
  if (!$sent || !hash_equals(csrf_token(), $sent)) {
    http_response_code(419);
    exit('Sessão expirada ou requisição inválida. Recarregue a página e tente novamente.');
  }
}
