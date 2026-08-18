<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';

if (auth_user()) {
  header("Location: dashboard.php");
  exit;
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $email = trim($_POST['email'] ?? '');
  $senha = $_POST['senha'] ?? '';

  if (login_attempt($email, $senha)) {
    header("Location: dashboard.php");
    exit;
  } else {
    $erro = "E-mail ou senha inválidos.";
  }
}

include __DIR__ . '/_layout_top.php';
?>

<div class="login-bg-overlay"></div>

<div class="row justify-content-center align-items-center" style="min-height:70vh">
  <div class="col-12 col-md-7 col-lg-5 col-xl-4">
    <div class="login-card p-4 p-md-5">

      <div class="login-logo-row">
        <?php foreach ($LOGOS as $i => $logo): ?>
          <?php if ($i > 0): ?><span class="login-logo-divider"></span><?php endif; ?>
          <img class="login-logo" src="<?= htmlspecialchars($logo) ?>" alt="Logo"
               onerror="this.previousElementSibling && (this.previousElementSibling.style.display='none'); this.style.display='none';">
        <?php endforeach; ?>
      </div>

      <h1 class="h4 text-center mb-1">Gestão de Cursos</h1>
      <div class="text-center text-muted small mb-4">
        Plataforma AutoriaSCS • Acompanhamento Scrum/Kanban • CECAPE
      </div>

      <?php if ($erro): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">E-mail</label>
          <input class="form-control" type="email" name="email" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Senha</label>
          <input class="form-control" type="password" name="senha" required>
        </div>

        <button class="btn btn-primary w-100 py-2 mt-2">Entrar</button>
      </form>

      <hr class="my-4">
      <div class="small text-muted text-center">
        Se não consegue acessar, contate a equipe de TI:<br>ti.cecape@scseduca.com.br
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
