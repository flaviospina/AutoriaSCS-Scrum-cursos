<?php
/**
 * V20 — "Agir como" (ADMIN): executar o sistema como outro usuário, com motivo e auditoria.
 * action=iniciar (id_user, motivo) | action=encerrar
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
csrf_check();

$real = auth_user_real();
if (!$real || !perfil_flag($real['role'], 'admin_total')) { http_response_code(403); exit('Acesso negado: exclusivo do perfil ADMIN.'); }

try {
  if (($_POST['action'] ?? '') === 'encerrar') { agir_como_encerrar(); header('Location: admin/usuarios.php?ok=' . urlencode('Modo "Agir como" encerrado. Você voltou a ser ADMIN.')); exit; }
  if (agir_como()) throw new Exception('Encerre o "Agir como" atual antes de iniciar outro.');
  agir_como_iniciar((int)($_POST['id_user'] ?? 0), (string)($_POST['motivo'] ?? ''));
  header('Location: dashboard.php'); exit;
} catch (Throwable $e) {
  http_response_code(422);
  exit(htmlspecialchars($e->getMessage()) . ' <a href="admin/usuarios.php">Voltar</a>');
}
