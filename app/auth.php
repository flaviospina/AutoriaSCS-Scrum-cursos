<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/perfis_repo.php';

/**
 * Usuário REAL da sessão (quem fez login), sem a alternância de visualização.
 * Use para decisões de segurança que não podem depender do perfil "visto".
 */
function auth_user_real() {
  return $_SESSION['user'] ?? null;
}

/**
 * Usuário EFETIVO: o real, exceto quando um ADMIN ativou "Alternar visualização"
 * (V11, item 3) — nesse caso o perfil (role) passa a ser o escolhido, e todas as
 * permissões (perm/is_admin/is_staff) passam a refletir esse perfil. A troca só é
 * honrada se o usuário real tiver admin_total: outro perfil jamais se eleva.
 */
function auth_user() {
  $u = $_SESSION['user'] ?? null;
  if (!$u) return null;
  $como = visao_alternada();
  if ($como !== null) {
    $u['role_real'] = $u['role'];
    $u['role'] = $como;
  }
  return $u;
}

/** Perfil "visto" pelo Admin, ou null quando não há alternância ativa/válida. */
function visao_alternada(): ?string {
  $u = $_SESSION['user'] ?? null;
  $como = $_SESSION['visao_como'] ?? null;
  if (!$u || !$como || $como === $u['role']) return null;
  if (!perfil_flag($u['role'], 'admin_total')) return null; // só ADMIN real
  if (!perfil_get($como)) return null;
  return $como;
}

/**
 * Ativa/desfaz a alternância de visualização. Somente ADMIN real; validado no
 * backend e registrado na auditoria. $codigo vazio/null = voltar ao perfil real.
 */
function visao_alternar(?string $codigo): void {
  require_once __DIR__ . '/audit.php';
  $u = auth_user_real();
  if (!$u || !perfil_flag($u['role'], 'admin_total')) {
    http_response_code(403);
    throw new Exception("Somente administradores podem alternar a visualização.");
  }
  $antes = $_SESSION['visao_como'] ?? null;
  if ($codigo === null || $codigo === '' || $codigo === $u['role']) {
    unset($_SESSION['visao_como']);
    audit_log('visao_alternada', 'user', (int)$u['id_user'], ['visao' => $antes], ['visao' => null]);
    return;
  }
  $p = perfil_get($codigo);
  if (!$p || empty($p['ativo'])) {
    http_response_code(422);
    throw new Exception("Perfil inválido para visualização.");
  }
  $_SESSION['visao_como'] = $codigo;
  audit_log('visao_alternada', 'user', (int)$u['id_user'], ['visao' => $antes], ['visao' => $codigo]);
}

function require_login() {
  if (!auth_user()) {
    header("Location: login.php");
    exit;
  }
}

/** O usuário logado possui a permissão do seu perfil (efetivo)? (admin_total concede todas) */
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

/** Verdadeiro quando quem fez login é ADMIN, mesmo vendo o sistema como outro perfil. */
function is_admin_real(): bool {
  $u = auth_user_real();
  return $u ? perfil_flag($u['role'], 'admin_total') : false;
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
  unset($_SESSION['visao_como']);
  audit_log('login_ok', 'user', (int)$u['id_user']);
  return true;
}
