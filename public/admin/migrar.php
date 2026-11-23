<?php
/**
 * V20 — Aplicar migração do banco pelo Diagnóstico (sem phpMyAdmin).
 * Somente ADMIN real; executa database/upgrade_vNN.sql comando a comando e mostra o resultado.
 */
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/curso_repo.php';
require_once __DIR__ . '/../../app/admin_repo.php';

if (!is_admin_real()) { http_response_code(403); exit('Acesso restrito ao administrador real.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: diagnostico_sistema.php'); exit; }
csrf_check();

$versao = strtoupper(trim((string)($_POST['versao'] ?? '')));
$erro = null; $res = null;
try { $res = migracao_aplicar($versao, auth_user_real()); } catch (Throwable $e) { $erro = $e->getMessage(); }

include __DIR__ . '/../_layout_top.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div><h1 class="h4 mb-0">Migração <?= htmlspecialchars($versao) ?></h1><div class="text-muted small">Executada diretamente no banco do sistema pelo Diagnóstico.</div></div>
  <a class="btn btn-outline-secondary" href="diagnostico_sistema.php">Voltar ao Diagnóstico</a>
</div>
<?php if ($erro): ?>
  <div class="alert alert-danger alert-fixo"><?= htmlspecialchars($erro) ?></div>
<?php else: ?>
  <div class="alert <?= $res['erros'] ? 'alert-warning' : 'alert-success' ?> alert-fixo">
    <b><?= $res['erros'] ? 'Concluída com erros' : 'Migração aplicada' ?>.</b> <?= (int)$res['ok'] ?> comando(s) executado(s) de <?= (int)$res['comandos'] ?><?= $res['erros'] ? ', ' . count($res['erros']) . ' com erro' : '' ?>.
    Confira a linha "<?= htmlspecialchars($versao) ?>" no Diagnóstico.
  </div>
  <div class="card shadow-sm"><div class="card-body">
    <h2 class="h6">Registro da execução</h2>
    <div class="table-responsive"><table class="table table-sm small align-top">
      <thead><tr><th>#</th><th>Comando</th><th>Resultado</th></tr></thead>
      <tbody>
      <?php foreach ($res['log'] as $i => [$cmd, $r]): ?>
        <tr class="<?= str_starts_with($r, 'ERRO') ? 'table-danger' : '' ?>"><td><?= $i + 1 ?></td><td><code style="white-space:pre-wrap"><?= htmlspecialchars(mb_strimwidth($cmd, 0, 400, '…')) ?></code></td><td><?= htmlspecialchars($r) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div></div>
<?php endif; ?>
<?php include __DIR__ . '/../_layout_bottom.php'; ?>
