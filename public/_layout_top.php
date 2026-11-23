<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/modulos_repo.php'; // V16: cores dos módulos (modulo_badge) em todas as páginas
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
  <?php $cssV = @filemtime(__DIR__ . '/assets/autoria-dark.css') ?: 1; ?>
  <link href="<?= $LP ?>assets/autoria-dark.css?v=<?= $cssV ?>" rel="stylesheet">
  <style><?= modulos_css() ?></style>
  <?php // V17 — acessibilidade: preferências do usuário (servidor) + painel; o JS aplica as classes antes da página aparecer
    $a11yPrefs = null;
    if ($u) { require_once __DIR__ . '/../app/config_repo.php'; $a11yJson = cfg_get('ui_prefs_' . (int)($_SESSION['user']['id_user'] ?? 0), ''); if ($a11yJson) $a11yPrefs = json_decode($a11yJson, true); }
    $a11yV = @filemtime(__DIR__ . '/assets/acessibilidade.js') ?: 1; ?>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <link href="<?= $LP ?>assets/acessibilidade.css?v=<?= $a11yV ?>" rel="stylesheet">
  <script>window.A11Y_PREFS = <?= json_encode($a11yPrefs ?: null) ?>; window.A11Y_URL = <?= json_encode($LP . 'prefs_ui.php') ?>;</script>
  <script src="<?= $LP ?>assets/acessibilidade.js?v=<?= $a11yV ?>"></script>
</head>
<body class="bg-light">
<a class="a11y-skip" href="#conteudo">Ir para o conteúdo</a>

<nav class="navbar navbar-expand-lg navbar-dark navbar-autoria py-2">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2 me-2" href="<?= $LP ?>dashboard.php">
      <span class="brand-logos">
        <?php foreach ($LOGOS as $logo): ?>
          <span class="logo-chip">
            <img class="brand-img" src="<?= htmlspecialchars($logo) ?>" alt="Logo"
                 onerror="this.closest('.logo-chip').style.display='none';">
          </span>
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
            <li class="nav-item"><a class="nav-link <?= $PAGINA==='indicadores.php'?'active':'' ?>" href="<?= $LP ?>indicadores.php">Indicadores</a></li>
            <li class="nav-item"><a class="nav-link <?= $PAGINA==='relatorios.php'?'active':'' ?>" href="<?= $LP ?>relatorios.php">Relatórios</a></li>
            <li class="nav-item"><a class="nav-link <?= $PAGINA==='filmagens.php'?'active':'' ?>" href="<?= $LP ?>filmagens.php" title="Reservas de estúdio e filmagens realizadas (MB)">Filmagens</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="<?= $LP ?>tutorial.php" target="_blank" rel="noopener" title="Tutorial do seu nível de acesso (abre em nova aba)">Ajuda</a></li>
          <li class="nav-item"><a class="nav-link text-nowrap" href="?a11y=reset" data-a11y-abrir="1" title="Opções de acessibilidade (Alt+A). Se o painel não abrir, o clique restaura o padrão." onclick="if(window.a11yAlternar){window.a11yAlternar(true);return false;}">&#9855; Acessibilidade</a></li>
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
                <li><a class="dropdown-item" href="<?= $LP ?>admin/escolas.php">Escolas</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/niveis.php">Níveis de ensino</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/categorias.php">Categorias de entrega</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/modulos.php">Cores dos módulos</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/checklists.php">Checklists</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/auditoria.php">Auditoria</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/notificacoes.php">Notificações</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/diagnostico_sistema.php">Diagnóstico do sistema</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/lixeira.php">Lixeira e situação dos cursos</a></li>
                <li><a class="dropdown-item" href="<?= $LP ?>admin/parametros.php">Parâmetros do sistema</a></li>
                <li><a class="dropdown-item text-danger" href="<?= $LP ?>admin/zerar_dados.php">Zerar dados</a></li>
              </ul>
            </li>
          <?php endif; ?>
        </ul>

        <div class="d-flex gap-2 align-items-center">
          <?php if (is_admin_real() && !agir_como()): $visaoAtual = visao_alternada(); ?>
            <form method="post" action="<?= $LP ?>trocar_visao.php" class="d-flex align-items-center gap-1" title="Alternar visualização (somente ADMIN)">
              <?= csrf_field() ?>
              <input type="hidden" name="voltar" value="dashboard.php">
              <select class="form-select form-select-sm visao-select" name="como" onchange="this.form.submit()">
                <option value="" <?= $visaoAtual === null ? 'selected' : '' ?>>Ver como: ADMIN (meu perfil)</option>
                <?php foreach (perfis_all() as $cod => $p): if (!empty($p['admin_total'])) continue; ?>
                  <option value="<?= htmlspecialchars($cod) ?>" <?= $visaoAtual === $cod ? 'selected' : '' ?>>
                    Ver como: <?= htmlspecialchars($cod) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
          <span class="badge bg-warning"><?= htmlspecialchars($u['role']) ?></span>
          <a class="user-badge d-none d-md-inline-flex text-decoration-none" href="<?= $LP ?>perfil.php"
             title="Meu Perfil">👤 <?= htmlspecialchars($u['nome']) ?></a>
          <a class="btn btn-outline-light btn-sm" href="<?= $LP ?>logout.php">Sair</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</nav>

<?php if ($u && ($agBanner = agir_como())): ?>
  <div class="visao-banner" style="background:#7f1d1d;color:#fff;border-bottom:3px solid #ef4444">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span>🎭 <b>AGINDO COMO <?= htmlspecialchars($agBanner['user']['nome']) ?> (<?= htmlspecialchars($agBanner['user']['role']) ?>)</b> desde <?= date('H:i', strtotime($agBanner['desde'])) ?>.
        Tudo o que você fizer será registrado como <b><?= htmlspecialchars(auth_user_real()['nome']) ?></b> agindo em nome deste usuário.</span>
      <form method="post" action="<?= $LP ?>agir_como.php" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="encerrar">
        <button class="btn btn-sm btn-light">Encerrar e voltar a ser ADMIN</button>
      </form>
    </div>
  </div>
<?php elseif ($u && is_admin_real() && visao_alternada() !== null): ?>
  <div class="visao-banner">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span>👁 <b>Modo de visualização:</b> você está vendo o sistema como
        <b><?= htmlspecialchars(visao_alternada()) ?></b>. As permissões exibidas são as desse perfil.</span>
      <form method="post" action="<?= $LP ?>trocar_visao.php" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="como" value="">
        <input type="hidden" name="voltar" value="dashboard.php">
        <button class="btn btn-sm btn-warning">Voltar ao meu perfil (ADMIN)</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<main class="container my-4" id="conteudo" tabindex="-1">
