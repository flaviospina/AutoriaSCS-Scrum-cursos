<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
$u = auth_user();

// prefixo relativo para páginas dentro de /admin
$LP = $LP ?? '';

// logos do cabeçalho (config app.logos): CECAPE • AutoriaSCS • MB
$cfgApp = (require __DIR__ . '/../app/config.php')['app'] ?? [];
$LOGOS = $cfgApp['logos'] ?? ['https://cecapescs.com.br/logos/logo-autoriascs.png'];

// página atual (para marcar o link ativo do menu)
$PAGINA = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AutoriaSCS • Gestão de Cursos</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= $LP ?>assets/autoria-dark.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark navbar-autoria py-2">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2 me-2" href="<?= $LP ?>dashboard.php">
      <span class="brand-logos">
        <?php foreach ($LOGOS as $i => $logo): ?>
          <?php if ($i > 0): ?><span class="brand-divider"></span><?php endif; ?>
          <img class="brand-img" src="<?= htmlspecialchars($logo) ?>" alt="Logo"
               onerror="this.previousElementSibling && (this.previousElementSibling.style.display='none'); this.style.display='none';">
        <?php endforeach; ?>
      </span>
      <span class="brand-title d-none d-xl-inline">Gestão de Cursos</span>
    </a>

    <?php if ($u): ?>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="navMain">
        <ul class="navbar-nav mx-auto">
          <li class="nav-item"><a class="nav-link <?= $PAGINA==='dashboard.php'?'active':'' ?>" href="<?= $LP ?>dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link <?= $PAGINA==='modelos.php'?'active':'' ?>" href="<?= $LP ?>modelos.php">Modelos</a></li>
          <?php if (is_staff()): ?>
            <li class="nav-item"><a class="nav-link <?= $PAGINA==='relatorios.php'?'active':'' ?>" href="<?= $LP ?>relatorios.php">Relatórios</a></li>
          <?php endif; ?>
          <?php if (is_admin()): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle <?= $LP !== '' ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">Admin</a>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="<?= $LP ?>admin/index.php">Visão geral</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/kanban.php">Colunas do Kanban</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/status.php">Status</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/transicoes.php">Transições por perfil</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/usuarios.php">Usuários</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/perfis.php">Perfis de acesso</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/auditoria.php">Auditoria</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/notificacoes.php">Notificações</a></li>
              </ul>
            </li>
          <?php endif; ?>
        </ul>

        <div class="d-flex gap-2 align-items-center">
          <span class="badge bg-warning"><?= htmlspecialchars($u['role']) ?></span>
          <a class="user-badge d-none d-md-inline-flex text-decoration-none" href="<?= $LP ?>perfil.php"
             title="Meu Perfil">👤 <?= htmlspecialchars($u['nome']) ?></a>
          <a class="btn btn-outline-light btn-sm" href="<?= $LP ?>logout.php">Sair</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</nav>

<main class="container my-4">
