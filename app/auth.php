<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/perfis_repo.php';

function auth_user() {
  return $_SESSION['user'] ?? null;
}

function require_login() {
  if (!auth_user()) {
    header("Location: login.php");
    exit;
  }
}

/** O usuário logado possui a permissão do seu perfil? (admin_total concede todas) */
function perm(string $flag): bool {
  $u = auth_user();
  if (!$u) return false;
  return perfil_flag($u['role'], $flag);
}

function require_perm(string $flag): void {
  if (!perm($flag)) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
  }
}

/** Compatibilidade: aceita códigos de perfil; admin_total sempre passa. */
function require_role(array $roles) {
  $u = auth_user();
  if ($u && perfil_flag($u['role'], 'admin_total')) return;
  if (!$u || !in_array($u['role'], $roles, true)) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
  }
}

function is_admin(): bool {
  return perm('admin_total');
}

/** Perfis de gestão: enxergam todos os cursos, o Kanban completo e os relatórios. */
function is_staff(): bool {
  return perm('ve_todos_cursos');
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
