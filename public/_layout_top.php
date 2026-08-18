<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
$u = auth_user();

// prefixo relativo para páginas dentro de /admin
$LP = $LP ?? '';
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
  <style>
    :root {
      --autoria-teal: #058285;
      --autoria-teal-dark: #046b6d;
      --autoria-teal-light: #e6f3f3;
    }
    body { font-family: 'Comfortaa', system-ui, -apple-system, sans-serif; }

    /* identidade institucional AutoriaSCS / CECAPE */
    .navbar-autoria { background: linear-gradient(90deg, var(--autoria-teal) 0%, var(--autoria-teal-dark) 100%); }
    .btn-primary, .bg-primary { background-color: var(--autoria-teal) !important; border-color: var(--autoria-teal) !important; }
    .btn-primary:hover { background-color: var(--autoria-teal-dark) !important; border-color: var(--autoria-teal-dark) !important; }
    .btn-outline-primary { color: var(--autoria-teal) !important; border-color: var(--autoria-teal) !important; }
    .btn-outline-primary:hover, .btn-outline-primary.active { background-color: var(--autoria-teal) !important; color: #fff !important; }
    .text-primary { color: var(--autoria-teal) !important; }
    a { color: var(--autoria-teal); }
    .page-link { color: var(--autoria-teal); }
    .form-check-input:checked { background-color: var(--autoria-teal); border-color: var(--autoria-teal); }

    .brand-logo {
      display:inline-flex; align-items:center; justify-content:center;
      width:38px; height:38px; border-radius:10px;
      background:#fff; color:var(--autoria-teal);
      font-weight:700; font-size:1.05rem; letter-spacing:-1px;
    }

    .kanban-wrap { overflow-x:auto; padding-bottom:.5rem; }
    .kanban-board { display:flex; gap:1rem; align-items:flex-start; min-height:70vh; }
    .kanban-col { width:340px; flex:0 0 340px; border-radius:14px; border:1px solid rgba(0,0,0,.08); background:#fff; }
    .kanban-col-header { border-top-left-radius:14px; border-top-right-radius:14px; }
    .kanban-col-body { max-height:70vh; overflow:auto; padding:.75rem; background:rgba(0,0,0,.02); border-bottom-left-radius:14px; border-bottom-right-radius:14px; }
    .kanban-card { border-radius:14px; border:1px solid rgba(0,0,0,.08); background:#fff; box-shadow:0 1px 8px rgba(0,0,0,.06); }
    .kanban-card:hover { box-shadow:0 3px 16px rgba(0,0,0,.10); }
    .chip { display:inline-flex; align-items:center; gap:.35rem; padding:.15rem .55rem; border-radius:999px; font-size:.75rem; border:1px solid rgba(0,0,0,.10); background:rgba(255,255,255,.75); }
    .dropzone { outline:2px dashed var(--autoria-teal); outline-offset:-6px; }
    .dragging { opacity:.55; }
    .wip-exceeded { color:#dc3545; font-weight:700; }
  </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark navbar-autoria">
  <div class="container">
    <a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="<?= $LP ?>dashboard.php">
      <span class="brand-logo">A</span>
      <span>AutoriaSCS <span class="fw-normal opacity-75">• Gestão de Cursos</span></span>
    </a>

    <?php if ($u): ?>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="navMain">
        <ul class="navbar-nav me-auto">
          <li class="nav-item"><a class="nav-link" href="<?= $LP ?>dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= $LP ?>modelos.php">Modelos</a></li>
          <?php if (is_staff()): ?>
            <li class="nav-item"><a class="nav-link" href="<?= $LP ?>relatorios.php">Relatórios</a></li>
          <?php endif; ?>
          <?php if (is_admin()): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Admin</a>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="<?= $LP ?>admin/index.php">Visão geral</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/kanban.php">Colunas do Kanban</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/status.php">Status</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/transicoes.php">Transições por perfil</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/usuarios.php">Usuários</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/auditoria.php">Auditoria</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/notificacoes.php">Notificações</a></li>
              </ul>
            </li>
          <?php endif; ?>
        </ul>

        <div class="d-flex gap-2 align-items-center">
          <span class="badge bg-warning text-dark">Perfil: <?= htmlspecialchars($u['role']) ?></span>
          <a class="nav-link text-white small d-none d-md-inline" href="<?= $LP ?>perfil.php"
             title="Meu Perfil">Olá, <?= htmlspecialchars($u['nome']) ?></a>
          <a class="btn btn-outline-light btn-sm" href="<?= $LP ?>logout.php">Sair</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</nav>

<main class="container my-4">
