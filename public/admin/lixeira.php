<?php
/** Admin → Lixeira e situação dos cursos (V18): pausados, suspensos, cancelados, arquivados e na lixeira. */
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/curso_repo.php';
require_once __DIR__ . '/../../app/admin_repo.php';

if (!is_admin_real()) { http_response_code(403); exit('Ação exclusiva do administrador (desative "Ver como").'); }
$uReal = auth_user_real();
$erro = null; $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    $idc = (int)($_POST['id_curso'] ?? 0); $motivo = (string)($_POST['motivo'] ?? '');
    switch ($_POST['action'] ?? '') {
      case 'restaurar':  curso_situacao_alterar($idc, 'ATIVO', $motivo, $uReal); $ok = 'Curso restaurado e ativo novamente.'; break;
      case 'situacao':   curso_situacao_alterar($idc, (string)($_POST['situacao'] ?? ''), $motivo, $uReal); $ok = 'Situação do curso alterada.'; break;
      case 'definitivo': $r = curso_excluir_definitivo($idc, $motivo, $uReal); $ok = 'Curso apagado definitivamente. Arquivos movidos para ' . str_replace(realpath(__DIR__ . '/../../'), '', $r['backup']) . '.'; break;
      default: throw new Exception('Ação inválida.');
    }
    header('Location: lixeira.php?ok=' . urlencode($ok)); exit; // evita reenvio ao atualizar a página
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}
if (!empty($_GET['ok'])) $ok = mb_substr((string)$_GET['ok'], 0, 300);

$lixeira = cursos_lixeira();
$grupos = [];
foreach (['PAUSADO', 'SUSPENSO', 'CANCELADO', 'ARQUIVADO'] as $s) $grupos[$s] = cursos_por_situacao($s);

include __DIR__ . '/../_layout_top.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h1 class="h4 mb-0">Lixeira e situação dos cursos</h1>
    <div class="text-muted small">Ações administrativas com motivo obrigatório e registro na auditoria. As ações por curso ficam na página do curso (cartão "Administração do curso").</div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>
<?php if (!admin_v18()): ?><div class="alert alert-warning">Execute <code>database/upgrade_v18.sql</code> no phpMyAdmin para habilitar a situação dos cursos e a lixeira.</div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="card shadow-sm border-danger mb-3"><div class="card-body">
  <h2 class="h6">🗑 Lixeira (<?= count($lixeira) ?>)</h2>
  <p class="small text-muted">Cursos excluídos ficam aqui até serem restaurados ou apagados definitivamente. Ao apagar definitivamente, os arquivos são movidos para <code>storage/backups/</code> e um resumo dos dados é guardado junto.</p>
  <?php if (!$lixeira): ?><div class="text-muted small">A lixeira está vazia.</div><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Curso</th><th>Formador(a)</th><th>Status</th><th>Excluído em</th><th>Por</th><th>Motivo</th><th>Arquivos</th><th class="text-end">Ações</th></tr></thead>
    <tbody>
      <?php foreach ($lixeira as $c): ?>
        <tr>
          <td><a href="../curso_detalhe.php?id=<?= (int)$c['id_curso'] ?>"><?= htmlspecialchars($c['nome_curso']) ?></a></td>
          <td><?= htmlspecialchars($c['professor_nome']) ?></td>
          <td><span class="badge" style="<?= status_badge_style($c['status_atual']) ?>"><?= htmlspecialchars($c['status_atual']) ?></span></td>
          <td class="text-nowrap"><?= $c['situacao_em'] ? date('d/m/Y H:i', strtotime($c['situacao_em'])) : '' ?></td>
          <td><?= htmlspecialchars($c['por_nome'] ?? '') ?></td>
          <td class="small"><?= htmlspecialchars($c['situacao_motivo'] ?? '') ?></td>
          <td><?= (int)$c['arquivos'] ?></td>
          <td class="text-end text-nowrap">
            <form method="post" class="d-inline" data-confirm="Restaurar o curso <b><?= htmlspecialchars($c['nome_curso']) ?></b>? Ele volta para o fluxo normal." data-confirm-title="Restaurar curso" data-confirm-btn="Sim, restaurar" data-motivo="1">
              <?= csrf_field() ?><input type="hidden" name="action" value="restaurar"><input type="hidden" name="id_curso" value="<?= (int)$c['id_curso'] ?>">
              <button class="btn btn-sm btn-outline-success">Restaurar</button>
            </form>
            <form method="post" class="d-inline" data-confirm="<b>Apagar definitivamente</b> o curso <b><?= htmlspecialchars($c['nome_curso']) ?></b>?<br>Entregas, vídeos, apontamentos e histórico serão removidos do banco. Os arquivos vão para storage/backups." data-confirm-title="Exclusão definitiva" data-confirm-type="danger" data-confirm-btn="Apagar definitivamente" data-motivo="1">
              <?= csrf_field() ?><input type="hidden" name="action" value="definitivo"><input type="hidden" name="id_curso" value="<?= (int)$c['id_curso'] ?>">
              <button class="btn btn-sm btn-danger">Apagar definitivamente</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>

<?php foreach ($grupos as $sit => $lista): $d = CURSO_SITUACOES[$sit]; ?>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <h2 class="h6"><span class="badge <?= $d['badge'] ?>"><?= $d['label'] ?></span> <span class="text-muted small"><?= htmlspecialchars($d['desc']) ?></span> <span class="badge bg-secondary"><?= count($lista) ?></span></h2>
    <?php if (!$lista): ?><div class="text-muted small">Nenhum curso.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Curso</th><th>Formador(a)</th><th>Status</th><th>Desde</th><th>Motivo</th><th class="text-end">Ações</th></tr></thead>
      <tbody>
        <?php foreach ($lista as $c): ?>
          <tr>
            <td><a href="../curso_detalhe.php?id=<?= (int)$c['id_curso'] ?>"><?= htmlspecialchars($c['nome_curso']) ?></a></td>
            <td><?= htmlspecialchars($c['professor_nome']) ?></td>
            <td><span class="badge" style="<?= status_badge_style($c['status_atual']) ?>"><?= htmlspecialchars($c['status_atual']) ?></span></td>
            <td class="text-nowrap"><?= $c['situacao_em'] ? date('d/m/Y', strtotime($c['situacao_em'])) : '' ?></td>
            <td class="small"><?= htmlspecialchars($c['situacao_motivo'] ?? '') ?></td>
            <td class="text-end text-nowrap">
              <form method="post" class="d-inline" data-confirm="Retomar o curso <b><?= htmlspecialchars($c['nome_curso']) ?></b> (volta a ATIVO)?" data-confirm-title="Retomar curso" data-confirm-btn="Sim, retomar" data-motivo="1">
                <?= csrf_field() ?><input type="hidden" name="action" value="situacao"><input type="hidden" name="situacao" value="ATIVO"><input type="hidden" name="id_curso" value="<?= (int)$c['id_curso'] ?>">
                <button class="btn btn-sm btn-outline-success">Retomar</button>
              </form>
              <form method="post" class="d-inline" data-confirm="Enviar o curso <b><?= htmlspecialchars($c['nome_curso']) ?></b> para a lixeira?" data-confirm-title="Excluir curso" data-confirm-type="danger" data-confirm-btn="Sim, excluir" data-motivo="1">
                <?= csrf_field() ?><input type="hidden" name="action" value="situacao"><input type="hidden" name="situacao" value="EXCLUIDO"><input type="hidden" name="id_curso" value="<?= (int)$c['id_curso'] ?>">
                <button class="btn btn-sm btn-outline-danger">Excluir</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div></div>
<?php endforeach; ?>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
