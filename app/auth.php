<?php
require_once __DIR__ . '/db.php';

function auth_user() {
  return $_SESSION['user'] ?? null;
}

function require_login() {
  if (!auth_user()) {
    header("Location: login.php");
    exit;
  }
}

function require_role(array $roles) {
  $u = auth_user();
  // ADMIN tem acesso a tudo
  if ($u && $u['role'] === 'ADMIN') return;
  if (!$u || !in_array($u['role'], $roles, true)) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
  }
}

function is_admin(): bool {
  $u = auth_user();
  return $u && $u['role'] === 'ADMIN';
}

/** Perfis de gestão que enxergam todos os cursos e o Kanban completo. */
function is_staff(): bool {
  $u = auth_user();
  return $u && in_array($u['role'], ['TI', 'MB', 'ADMIN'], true);
}

function login_attempt(string $email, string $senha): bool {
  require_once __DIR__ . '/audit.php';

  $st = db()->prepare("SELECT id_user, nome, email, senha_hash, role, ativo FROM tb_users WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $u = $st->fetch();
  if (!$u || !$u['ativo'] || !password_verify($senha, $u['senha_hash'])) {
    audit_log('login_falhou', 'user', $u ? (int)$u['id_user'] : null, null,
      ['email_informado' => $email], $u ? (int)$u['id_user'] : null);
    return false;
  }

  unset($u['senha_hash']);
  session_regenerate_id(true);
  $_SESSION['user'] = $u;
  audit_log('login_ok', 'user', (int)$u['id_user']);
  return true;
}
