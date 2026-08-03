<?php
/**
 * ⚠️ PÁGINA TEMPORÁRIA DE RECUPERAÇÃO DE ACESSO ⚠️
 *
 * Lista todos os usuários e permite redefinir senhas SEM estar logado.
 * Use apenas para recuperar o acesso inicial e APAGUE ESTE ARQUIVO em seguida.
 *
 * Proteção mínima: exige a chave abaixo na URL:
 *   recuperar_acesso.php?chave=cecape-recupera-2026
 */

const CHAVE_RECUPERACAO = 'cecape-recupera-2026';

if (($_GET['chave'] ?? '') !== CHAVE_RECUPERACAO) {
  http_response_code(403);
  exit('Acesso negado. Informe a chave de recuperação na URL: recuperar_acesso.php?chave=...');
}

require_once __DIR__ . '/../app/db.php';

$msg = null; $erro = null;

// diagnóstico: tabela existe?
$tabelaOk = true;
$usuarios = [];
try {
  $usuarios = db()->query("SELECT id_user, nome, email, role, ativo, created_at FROM tb_users ORDER BY id_user")->fetchAll();
} catch (Throwable $e) {
  $tabelaOk = false;
  $erro = "A tabela tb_users não existe ou está inacessível: " . $e->getMessage()
        . " — importe o arquivo database/schema.sql no phpMyAdmin.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tabelaOk) {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'reset') {
      $idu   = (int)($_POST['id_user'] ?? 0);
      $senha = $_POST['senha'] ?? '';
      if (strlen($senha) < 6) throw new Exception("A senha deve ter pelo menos 6 caracteres.");
      $st = db()->prepare("UPDATE tb_users SET senha_hash=?, ativo=1 WHERE id_user=?");
      $st->execute([password_hash($senha, PASSWORD_DEFAULT), $idu]);
      $msg = "Senha do usuário #{$idu} redefinida (e usuário reativado).";
    }

    if ($action === 'criar_admin') {
      $email = trim($_POST['email'] ?? 'admin@scseduca.com.br');
      $senha = $_POST['senha'] ?? '';
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("E-mail inválido.");
      if (strlen($senha) < 6) throw new Exception("A senha deve ter pelo menos 6 caracteres.");

      $dup = db()->prepare("SELECT id_user FROM tb_users WHERE email=?");
      $dup->execute([$email]);
      $ex = $dup->fetch();

      if ($ex) {
        db()->prepare("UPDATE tb_users SET senha_hash=?, role='ADMIN', ativo=1 WHERE id_user=?")
          ->execute([password_hash($senha, PASSWORD_DEFAULT), (int)$ex['id_user']]);
        $msg = "Usuário {$email} já existia: senha redefinida, perfil ADMIN e ativado.";
      } else {
        db()->prepare("INSERT INTO tb_users (nome, email, senha_hash, role, ativo) VALUES (?,?,?,?,1)")
          ->execute(['Administrador CECAPE', $email, password_hash($senha, PASSWORD_DEFAULT), 'ADMIN']);
        $msg = "Usuário ADMIN {$email} criado com sucesso.";
      }
    }

    $usuarios = db()->query("SELECT id_user, nome, email, role, ativo, created_at FROM tb_users ORDER BY id_user")->fetchAll();
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recuperação de Acesso — AutoriaSCS (TEMPORÁRIO)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4" style="max-width:900px">

  <div class="alert alert-danger">
    <b>⚠️ Página temporária de recuperação.</b> Após recuperar o acesso,
    <b>APAGUE o arquivo <code>public/recuperar_acesso.php</code></b> do servidor.
    Qualquer pessoa com a chave da URL consegue redefinir senhas por aqui.
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="alert alert-warning"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

  <?php if ($tabelaOk): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h1 class="h5 mb-3">Usuários cadastrados (<?= count($usuarios) ?>)</h1>

        <?php if (!$usuarios): ?>
          <div class="alert alert-info">
            Nenhum usuário cadastrado — a importação do <code>schema.sql</code> provavelmente não
            incluiu o seed. Crie o administrador no formulário abaixo.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead class="table-light">
                <tr><th>#</th><th>Nome</th><th>E-mail (login)</th><th>Perfil</th><th>Ativo</th><th>Redefinir senha</th></tr>
              </thead>
              <tbody>
                <?php foreach ($usuarios as $us): ?>
                  <tr>
                    <td><?= (int)$us['id_user'] ?></td>
                    <td><?= htmlspecialchars($us['nome']) ?></td>
                    <td><code><?= htmlspecialchars($us['email']) ?></code></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($us['role']) ?></span></td>
                    <td><?= $us['ativo'] ? '✅' : '❌ inativo' ?></td>
                    <td>
                      <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">
                        <input class="form-control form-control-sm" type="text" name="senha"
                               placeholder="nova senha (mín. 6)" required minlength="6" style="max-width:200px">
                        <button class="btn btn-sm btn-warning text-nowrap">Redefinir</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Criar / restaurar usuário ADMIN</h2>
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="action" value="criar_admin">
          <div class="col-12 col-md-5">
            <label class="form-label small">E-mail</label>
            <input class="form-control" type="email" name="email" value="admin@scseduca.com.br" required>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small">Senha</label>
            <input class="form-control" type="text" name="senha" required minlength="6" placeholder="mín. 6 caracteres">
          </div>
          <div class="col-12 col-md-3">
            <button class="btn btn-primary w-100">Criar / Restaurar</button>
          </div>
        </form>
        <div class="form-text mt-2">
          Se o e-mail já existir, a senha é redefinida e o usuário vira ADMIN ativo.
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="text-muted small mt-3">
    Depois de acessar: entre em <a href="login.php">login.php</a> e apague este arquivo.
  </div>
</div>
</body>
</html>
