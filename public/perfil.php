<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();

$erro = null; $ok = null;

$st = db()->prepare("SELECT * FROM tb_users WHERE id_user=?");
$st->execute([(int)$u['id_user']]);
$me = $st->fetch();
if (!$me) { http_response_code(404); exit("Usuário não encontrado."); }

// preferência de notificações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pref') {
  csrf_check();
  try {
    $pref = $_POST['notif_pref'] ?? 'IMEDIATO';
    if (!in_array($pref, ['IMEDIATO','DIARIO','DESATIVADO'], true)) $pref = 'IMEDIATO';
    db()->prepare("UPDATE tb_users SET notif_pref=? WHERE id_user=?")->execute([$pref, (int)$u['id_user']]);
    audit_log('preferencia_notificacao', 'user', (int)$u['id_user'],
      ['notif_pref' => $me['notif_pref'] ?? 'IMEDIATO'], ['notif_pref' => $pref]);
    $me['notif_pref'] = $pref;
    $ok = "Preferência de e-mail atualizada.";
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// troca da própria senha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'senha') {
  csrf_check();
  try {
    $atual = $_POST['senha_atual'] ?? '';
    $nova  = $_POST['senha_nova'] ?? '';
    $conf  = $_POST['senha_conf'] ?? '';

    if (!password_verify($atual, $me['senha_hash'])) throw new Exception("Senha atual incorreta.");
    if (strlen($nova) < 6) throw new Exception("A nova senha deve ter pelo menos 6 caracteres.");
    if ($nova !== $conf) throw new Exception("A confirmação não confere com a nova senha.");

    db()->prepare("UPDATE tb_users SET senha_hash=? WHERE id_user=?")
      ->execute([password_hash($nova, PASSWORD_DEFAULT), (int)$u['id_user']]);
    audit_log('senha_alterada_pelo_usuario', 'user', (int)$u['id_user']);
    $ok = "Senha alterada com sucesso.";
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Meu Perfil</h1>
    <div class="text-muted small"><?= htmlspecialchars($me['nome']) ?> • <?= htmlspecialchars($me['email']) ?> • Perfil <?= htmlspecialchars($me['role']) ?></div>
  </div>
  <a class="btn btn-outline-secondary" href="dashboard.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Notificações por e-mail</h2>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="pref">
          <?php
            $prefs = [
              'IMEDIATO'   => ['Imediato', 'Receber cada aviso assim que o evento acontecer.'],
              'DIARIO'     => ['Resumo diário', 'Agrupar os avisos e receber uma vez ao dia (07h).'],
              'DESATIVADO' => ['Desativado', 'Não receber e-mails do sistema (não recomendado).'],
            ];
            $atualPref = $me['notif_pref'] ?? 'IMEDIATO';
          ?>
          <?php foreach ($prefs as $k => [$label, $desc]): ?>
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="notif_pref" id="pref_<?= $k ?>"
                     value="<?= $k ?>" <?= $atualPref === $k ? 'checked' : '' ?>>
              <label class="form-check-label" for="pref_<?= $k ?>">
                <b><?= $label ?></b><br><span class="small text-muted"><?= $desc ?></span>
              </label>
            </div>
          <?php endforeach; ?>
          <button class="btn btn-primary btn-sm mt-2">Salvar preferência</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Alterar minha senha</h2>
        <form method="post" class="row g-2"
              data-confirm="Confirmar a alteração da sua senha de acesso?"
              data-confirm-title="Alterar senha" data-confirm-btn="Sim, alterar">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="senha">
          <div class="col-12">
            <label class="form-label small">Senha atual</label>
            <input class="form-control" type="password" name="senha_atual" required>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Nova senha</label>
            <input class="form-control" type="password" name="senha_nova" required minlength="6">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Confirmar nova senha</label>
            <input class="form-control" type="password" name="senha_conf" required minlength="6">
          </div>
          <div class="col-12">
            <button class="btn btn-primary btn-sm mt-1">Alterar senha</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
