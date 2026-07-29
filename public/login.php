<?php
session_start();
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

<div class="row justify-content-center">
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <div class="text-center mb-3">
          <span class="brand-logo" style="width:56px;height:56px;font-size:1.6rem;background:var(--autoria-teal-light);">A</span>
          <h1 class="h4 mt-3 mb-0">AutoriaSCS</h1>
          <div class="text-muted small">Gestão da Produção de Cursos • CECAPE</div>
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

          <button class="btn btn-primary w-100">Entrar</button>
        </form>

        <hr class="my-4">
        <div class="small text-muted text-center">
          Se não consegue acessar, contate a equipe de TI:<br>ti.cecape@scseduca.com.br
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
