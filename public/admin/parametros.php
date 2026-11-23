<?php
/**
 * V20 — Admin → Parâmetros do sistema (tb_config), editáveis em tela.
 * Chave do Drive, senha de SMTP e dados do banco continuam em arquivo, por segurança.
 */
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/config_repo.php';
require_once __DIR__ . '/../../app/admin_repo.php';

if (!is_admin_real()) { http_response_code(403); exit('Acesso restrito ao administrador real (encerre o "Ver como" / "Agir como").'); }

$erro = null; $ok = null;
$defs = cfg_param_defs();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    if (!cfg_tabela_ok()) throw new Exception('Tabela tb_config ausente: aplique a migração V13/V20 no Diagnóstico.');
    $antes = []; $depois = [];
    foreach ($defs as $k => [$rot, $desc, $tipo, $min, $max]) {
      $v = trim((string)($_POST[$k] ?? ''));
      if ($tipo === 'bool') $v = isset($_POST[$k]) ? '1' : '0';
      elseif ($tipo === 'int') { if ($v === '' || !preg_match('/^-?\d+$/', $v)) throw new Exception("\"{$rot}\": informe um número inteiro."); $v = (string)max($min, min($max, (int)$v)); }
      elseif ($tipo === 'email') { if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new Exception("\"{$rot}\": e-mail inválido."); }
      else { $v = mb_substr($v, 0, $max ?: 255); }
      $atual = (string)cfg_get($k, '');
      if ($atual !== $v) { $antes[$k] = $atual; $depois[$k] = $v; }
    }
    foreach ($depois as $k => $v) cfg_set($k, $v); // só grava depois de validar tudo
    if ($depois) { audit_log('parametros_alterados', 'sistema', null, $antes, $depois + ['acao' => 'parametros_alterados', 'motivo' => 'Ajuste em Admin → Parâmetros'], (int)auth_user_real()['id_user']); $ok = 'Parâmetros salvos: ' . implode(', ', array_keys($depois)) . '.'; }
    else $ok = 'Nenhuma alteração.';
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

$grupos = [];
foreach ($defs as $k => $d) $grupos[$d[5]][$k] = $d;
$phpUpload = ini_get('upload_max_filesize'); $phpPost = ini_get('post_max_size');
include __DIR__ . '/../_layout_top.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h1 class="h4 mb-0">⚙️ Parâmetros do sistema</h1>
    <div class="text-muted small">Ajustes que antes só mudavam por arquivo. Toda alteração fica na auditoria. Dados do banco, chave do Drive e senha de SMTP continuam em <code>config.local.php</code>.</div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
<?php if (!cfg_tabela_ok()): ?><div class="alert alert-warning alert-fixo">A tabela <code>tb_config</code> não existe. Aplique a migração no <a href="diagnostico_sistema.php">Diagnóstico</a>.</div><?php endif; ?>

<form method="post" data-confirm="Salvar os parâmetros do sistema? As alterações valem imediatamente para todos os usuários." data-confirm-title="Parâmetros" data-confirm-btn="Sim, salvar">
  <?= csrf_field() ?>
  <div class="row g-3">
    <?php foreach ($grupos as $g => $itens): ?>
      <div class="col-12 col-lg-6">
        <div class="card shadow-sm h-100"><div class="card-body">
          <h2 class="h6 mb-3"><?= htmlspecialchars($g) ?></h2>
          <?php foreach ($itens as $k => [$rot, $desc, $tipo, $min, $max]): $v = (string)cfg_get($k, ''); ?>
            <div class="mb-3">
              <?php if ($tipo === 'bool'): ?>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="<?= $k ?>" id="p_<?= $k ?>" value="1" <?= $v === '1' ? 'checked' : '' ?>><label class="form-check-label" for="p_<?= $k ?>"><?= htmlspecialchars($rot) ?></label></div>
              <?php else: ?>
                <label class="form-label small mb-0" for="p_<?= $k ?>"><?= htmlspecialchars($rot) ?></label>
                <input class="form-control form-control-sm" id="p_<?= $k ?>" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>" <?= $tipo === 'int' ? 'type="number" min="' . $min . '" max="' . $max . '" style="max-width:160px"' : ($tipo === 'email' ? 'type="email"' : 'type="text"') ?> <?= $tipo !== 'int' && $max ? 'maxlength="' . $max . '"' : '' ?> placeholder="<?= $tipo === 'email' || $k === 'from_name' ? 'em branco = valor do config.local.php' : '' ?>">
              <?php endif; ?>
              <div class="form-text"><?= htmlspecialchars($desc) ?><?php if ($k === 'upload_max_mb'): ?> Limites do PHP neste servidor: upload_max_filesize = <b><?= htmlspecialchars($phpUpload) ?></b>, post_max_size = <b><?= htmlspecialchars($phpPost) ?></b> (o menor deles vale).<?php endif; ?></div>
            </div>
          <?php endforeach; ?>
          <?php if ($g === 'Cursos e materiais'): ?><div class="small text-muted">Cores dos módulos: <a href="modulos.php">Admin → Módulos</a>. Fluxo e colunas: <a href="status.php">Status</a> e <a href="kanban.php">Kanban</a>.</div><?php endif; ?>
        </div></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary" <?= cfg_tabela_ok() ? '' : 'disabled' ?>>Salvar parâmetros</button>
    <a class="btn btn-outline-secondary" href="../relatorios.php?r=intervencoes">Ver intervenções administrativas</a>
  </div>
</form>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
