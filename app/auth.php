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
  $ag = agir_como();
  if ($ag) { $t = $ag['user']; $t['agindo_por'] = (int)$u['id_user']; return $t; } // V20: o admin atua COMO o usuário
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
  if (!$u || !$como || $como === $u['role'] || agir_como()) return null;
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
  auth_sessao_valida();
}

/* ======================================================================
 * V20 — Administração total (etapa 2): "Agir como", sessões, bloqueio de login
 * ====================================================================== */

/** As colunas da V20 existem em tb_users? (tolerante à migração ausente) */
function users_v20(): bool {
  static $ok = null;
  if ($ok === null) {
    try { $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_users' AND COLUMN_NAME='senha_trocar'"); $st->execute(); $ok = (int)$st->fetchColumn() > 0; }
    catch (Throwable $e) { $ok = false; }
  }
  return $ok;
}

/** Dados do "Agir como" ativo (['user'=>..., 'desde'=>..., 'motivo'=>...]) ou null. Só vale para ADMIN real. */
function agir_como(): ?array {
  $u = $_SESSION['user'] ?? null; $ag = $_SESSION['agir_como'] ?? null;
  if (!$u || !$ag || empty($ag['user']['id_user'])) return null;
  if (!perfil_flag($u['role'], 'admin_total')) return null;
  return $ag;
}

/** Inicia o "Agir como": o ADMIN passa a executar as ações como o usuário escolhido (tudo auditado). */
function agir_como_iniciar(int $idUser, string $motivo): array {
  require_once __DIR__ . '/audit.php';
  $u = $_SESSION['user'] ?? null;
  if (!$u || !perfil_flag($u['role'], 'admin_total')) { http_response_code(403); throw new Exception('Somente administradores podem agir como outro usuário.'); }
  if (mb_strlen(trim($motivo)) < 10) throw new Exception('Informe o motivo (mínimo de 10 caracteres). Ele fica registrado na auditoria.');
  if ($idUser === (int)$u['id_user']) throw new Exception('Você já é este usuário.');
  $st = db()->prepare("SELECT id_user, nome, email, role, ativo" . (users_v20() ? ", excluido_em" : "") . " FROM tb_users WHERE id_user=?"); $st->execute([$idUser]); $t = $st->fetch();
  if (!$t) throw new Exception('Usuário não encontrado.');
  if (!$t['ativo'] || !empty($t['excluido_em'])) throw new Exception('Usuário inativo ou excluído.');
  if (perfil_flag($t['role'], 'admin_total')) throw new Exception('Não é possível agir como outro administrador.');
  unset($t['excluido_em']);
  unset($_SESSION['visao_como']);
  $_SESSION['agir_como'] = ['user' => $t, 'desde' => date('Y-m-d H:i:s'), 'motivo' => trim($motivo)];
  audit_log('agir_como_inicio', 'user', $idUser, null, ['acao' => 'agir_como_inicio', 'como' => $t['nome'] . ' (' . $t['role'] . ')', 'motivo' => trim($motivo)], (int)$u['id_user']);
  return $t;
}

function agir_como_encerrar(): void {
  require_once __DIR__ . '/audit.php';
  $ag = $_SESSION['agir_como'] ?? null; $u = $_SESSION['user'] ?? null;
  unset($_SESSION['agir_como']);
  if ($ag && $u) audit_log('agir_como_fim', 'user', (int)$ag['user']['id_user'], null, ['acao' => 'agir_como_fim', 'como' => $ag['user']['nome'], 'desde' => $ag['desde']], (int)$u['id_user']);
}

/**
 * Confere, no máximo a cada 60 s, se a sessão continua válida: usuário ativo, não excluído,
 * sessões não encerradas pelo admin depois do login. Também aplica a troca de senha obrigatória.
 */
function auth_sessao_valida(): void {
  $u = $_SESSION['user'] ?? null; if (!$u || PHP_SAPI === 'cli') return;
  if (empty($_SESSION['login_em'])) $_SESSION['login_em'] = date('Y-m-d H:i:s');
  $agora = time();
  if (($_SESSION['sessao_check'] ?? 0) > $agora - 60) { auth_exigir_troca_senha(); return; }
  $_SESSION['sessao_check'] = $agora;
  try {
    $st = db()->prepare("SELECT ativo" . (users_v20() ? ", excluido_em, sessoes_desde, senha_trocar" : "") . " FROM tb_users WHERE id_user=?");
    $st->execute([(int)$u['id_user']]); $r = $st->fetch();
    $invalida = !$r || !$r['ativo'] || !empty($r['excluido_em'])
      || (!empty($r['sessoes_desde']) && strtotime($r['sessoes_desde']) > strtotime($_SESSION['login_em']));
    if ($invalida) {
      $_SESSION = []; session_destroy();
      header('Location: ' . (str_contains($_SERVER['PHP_SELF'] ?? '', '/admin/') ? '../' : '') . 'login.php?msg=sessao'); exit;
    }
    $_SESSION['senha_trocar'] = !empty($r['senha_trocar']) ? 1 : 0;
  } catch (Throwable $e) { /* banco indisponível: não derruba a sessão */ }
  auth_exigir_troca_senha();
}

/** Usuário marcado para trocar a senha: só acessa Meu Perfil até trocar (não vale durante o "Agir como"). */
function auth_exigir_troca_senha(): void {
  if (empty($_SESSION['senha_trocar']) || agir_como()) return;
  $pag = basename($_SERVER['PHP_SELF'] ?? '');
  if (in_array($pag, ['perfil.php', 'logout.php', 'prefs_ui.php', 'login.php'], true)) return;
  header('Location: ' . (str_contains($_SERVER['PHP_SELF'] ?? '', '/admin/') ? '../' : '') . 'perfil.php?trocar=1'); exit;
}

/** Mensagem do último erro de login (bloqueio), para a tela de login. */
function auth_login_erro(?string $set = null): ?string { static $m = null; if ($set !== null) $m = $set; return $m; }

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
  if (agir_como()) return false; // durante o "Agir como" o admin atua só com os poderes do usuário
  return $u ? perfil_flag($u['role'], 'admin_total') : false;
}

function login_attempt(string $email, string $senha): bool {
  require_once __DIR__ . '/audit.php';

  require_once __DIR__ . '/config_repo.php';
  $v20 = users_v20();
  $st = db()->prepare("SELECT id_user, nome, email, senha_hash, role, ativo" . ($v20 ? ", excluido_em, senha_trocar, login_falhas, bloqueado_ate" : "") . " FROM tb_users WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $u = $st->fetch();
  // V20: bloqueio temporário após N tentativas erradas
  if ($u && $v20 && !empty($u['bloqueado_ate']) && strtotime($u['bloqueado_ate']) > time()) {
    auth_login_erro('Acesso bloqueado por excesso de tentativas. Tente novamente às ' . date('H:i', strtotime($u['bloqueado_ate'])) . ' ou peça o desbloqueio ao administrador.');
    audit_log('login_bloqueado', 'user', (int)$u['id_user'], null, ['email_informado' => $email, 'bloqueado_ate' => $u['bloqueado_ate']], (int)$u['id_user']);
    return false;
  }
  if (!$u || !$u['ativo'] || !empty($u['excluido_em']) || !password_verify($senha, $u['senha_hash'])) {
    audit_log('login_falhou', 'user', $u ? (int)$u['id_user'] : null, null,
      ['email_informado' => $email], $u ? (int)$u['id_user'] : null);
    if ($u && $v20 && $u['ativo'] && empty($u['excluido_em'])) {
      $max = login_max_tentativas(); $falhas = (int)$u['login_falhas'] + 1;
      if ($max > 0 && $falhas >= $max) {
        $ate = date('Y-m-d H:i:s', time() + login_bloqueio_min() * 60);
        db()->prepare("UPDATE tb_users SET login_falhas=0, bloqueado_ate=? WHERE id_user=?")->execute([$ate, (int)$u['id_user']]);
        audit_log('login_bloqueado', 'user', (int)$u['id_user'], null, ['tentativas' => $falhas, 'bloqueado_ate' => $ate], (int)$u['id_user']);
        auth_login_erro('Acesso bloqueado por ' . login_bloqueio_min() . ' minutos após ' . $falhas . ' tentativas erradas. Se precisar antes, peça o desbloqueio ao administrador.');
      } else {
        db()->prepare("UPDATE tb_users SET login_falhas=? WHERE id_user=?")->execute([$falhas, (int)$u['id_user']]);
      }
    }
    return false;
  }

  if ($v20) db()->prepare("UPDATE tb_users SET login_falhas=0, bloqueado_ate=NULL WHERE id_user=?")->execute([(int)$u['id_user']]);
  $senhaTrocar = $v20 && !empty($u['senha_trocar']);
  unset($u['senha_hash'], $u['excluido_em'], $u['senha_trocar'], $u['login_falhas'], $u['bloqueado_ate']);
  session_regenerate_id(true);
  $_SESSION['user'] = $u;
  $_SESSION['login_em'] = date('Y-m-d H:i:s');
  $_SESSION['sessao_check'] = time();
  $_SESSION['senha_trocar'] = $senhaTrocar ? 1 : 0;
  unset($_SESSION['visao_como'], $_SESSION['agir_como']);
  audit_log('login_ok', 'user', (int)$u['id_user']);
  return true;
}
