<?php
require_once __DIR__ . '/_admin_top.php';

$nCols   = (int)db()->query("SELECT COUNT(*) n FROM tb_kanban_colunas WHERE ativo=1")->fetch()['n'];
$nStatus = (int)db()->query("SELECT COUNT(*) n FROM tb_status WHERE ativo=1")->fetch()['n'];
$nTrans  = (int)db()->query("SELECT COUNT(*) n FROM tb_status_transicoes")->fetch()['n'];
$nUsers  = (int)db()->query("SELECT COUNT(*) n FROM tb_users WHERE ativo=1")->fetch()['n'];
$nCursos = (int)db()->query("SELECT COUNT(*) n FROM tb_cursos")->fetch()['n'];

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Administração</h1>
    <div class="text-muted small">Configuração do fluxo Kanban, perfis e usuários da plataforma.</div>
  </div>
  <a class="btn btn-outline-secondary" href="../dashboard.php">Voltar ao Dashboard</a>
</div>

<div class="row g-3">
  <?php
    $cards = [
      ['Colunas do Kanban', $nCols, 'Adicionar, renomear, reordenar, colorir e definir limite WIP das colunas.', 'kanban.php'],
      ['Status', $nStatus, 'Criar, editar, mover entre colunas, definir cores, status inicial e finais.', 'status.php'],
      ['Transições por perfil', $nTrans, 'Definir quais movimentações cada perfil (PROFESSOR, TI, MB) pode realizar.', 'transicoes.php'],
      ['Usuários', $nUsers, 'Cadastrar formadores, equipe TI, MB Estúdios e administradores.', 'usuarios.php'],
    ];
  ?>
  <?php foreach ($cards as [$titulo, $n, $desc, $link]): ?>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card shadow-sm h-100">
        <div class="card-body d-flex flex-column">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0"><?= $titulo ?></h2>
            <span class="badge bg-primary rounded-pill"><?= $n ?></span>
          </div>
          <p class="small text-muted flex-grow-1"><?= $desc ?></p>
          <a class="btn btn-outline-primary btn-sm" href="<?= $link ?>">Gerenciar</a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="col-12">
    <div class="alert alert-info small mb-0">
      <b><?= $nCursos ?></b> curso(s) cadastrados na plataforma.
      As alterações do fluxo (colunas/status/transições) valem imediatamente para o Kanban de todos os perfis.
      Status em uso por cursos não podem ser excluídos — desative-os ou renomeie-os (a renomeação atualiza os cursos automaticamente).
    </div>
  </div>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
