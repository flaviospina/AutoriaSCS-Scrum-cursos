<?php
/** Admin → Cores dos módulos (V16): uma cor para cada módulo (1 a 8) e para "Geral"; valem em todo o sistema. */
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/modulos_repo.php';

$erro = null; $ok = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    if (($_POST['action'] ?? '') === 'salvar') {
      $antes = modulos_cores();
      $novas = [];
      for ($m = 0; $m <= MODULOS_MAX; $m++) $novas[$m] = trim($_POST['cor'][$m] ?? '');
      modulos_cores_salvar($novas);
      audit_log('modulos_cores_alteradas', 'sistema', null, $antes, $novas);
      $ok = 'Cores dos módulos salvas. Elas já valem em todas as páginas.';
      header('Location: modulos.php?ok=1'); exit;
    }
    if (($_POST['action'] ?? '') === 'padrao') {
      $antes = modulos_cores();
      modulos_cores_salvar(MODULOS_CORES_PADRAO);
      audit_log('modulos_cores_alteradas', 'sistema', null, $antes, MODULOS_CORES_PADRAO);
      header('Location: modulos.php?ok=2'); exit;
    }
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}
if (($_GET['ok'] ?? '') === '1') $ok = 'Cores dos módulos salvas. Elas já valem em todas as páginas.';
if (($_GET['ok'] ?? '') === '2') $ok = 'Cores padrão restauradas.';
$cores = modulos_cores();

include __DIR__ . '/../_layout_top.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h1 class="h4 mb-0">Cores dos módulos</h1>
    <div class="text-muted small">Cada módulo do curso recebe uma cor, usada nos selos "Módulo N" em arquivos, vídeos, apontamentos, slides e relatórios.</div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card shadow-sm"><div class="card-body">
      <form method="post" id="formCores">
        <?= csrf_field() ?><input type="hidden" name="action" value="salvar">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-3">
            <thead class="table-light"><tr><th>Módulo</th><th style="width:140px">Cor</th><th>Código</th><th>Como aparece</th></tr></thead>
            <tbody>
              <?php for ($m = 0; $m <= MODULOS_MAX; $m++): ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars(modulo_nome($m)) ?></td>
                  <td><input type="color" class="form-control form-control-color" name="cor[<?= $m ?>]" id="cor<?= $m ?>" value="<?= htmlspecialchars($cores[$m]) ?>" title="Escolher a cor"></td>
                  <td><code id="hex<?= $m ?>"><?= htmlspecialchars($cores[$m]) ?></code></td>
                  <td><span class="badge" id="prev<?= $m ?>" style="<?= modulo_badge_style($m) ?>"><?= htmlspecialchars(modulo_nome($m)) ?></span></td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary">Salvar cores</button>
          <button class="btn btn-outline-secondary" type="submit" form="formPadrao">Restaurar padrão</button>
        </div>
      </form>
      <form method="post" id="formPadrao" data-confirm="Restaurar as cores padrão de todos os módulos?" data-confirm-title="Restaurar padrão" data-confirm-btn="Sim, restaurar"><?= csrf_field() ?><input type="hidden" name="action" value="padrao"></form>
    </div></div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm"><div class="card-body small">
      <h2 class="h6">Dicas</h2>
      <ul class="mb-0">
        <li>Escolha cores bem diferentes entre módulos vizinhos (1 e 2, 2 e 3...), para facilitar a leitura nas listas.</li>
        <li>O texto do selo fica preto ou branco automaticamente, conforme o contraste com a cor escolhida.</li>
        <li>"Geral" é usado para os materiais do curso que não pertencem a um módulo (apresentação, objetivos, referências).</li>
        <li>A mudança vale na hora para todos os usuários e fica registrada na auditoria.</li>
      </ul>
    </div></div>
  </div>
</div>
<script>
(function () {
  function contraste(hex) { var r = parseInt(hex.substr(1,2),16), g = parseInt(hex.substr(3,2),16), b = parseInt(hex.substr(5,2),16); return (0.299*r + 0.587*g + 0.114*b) > 150 ? '#111111' : '#ffffff'; }
  for (var m = 0; m <= <?= MODULOS_MAX ?>; m++) (function (m) {
    var inp = document.getElementById('cor' + m); if (!inp) return;
    inp.addEventListener('input', function () { var p = document.getElementById('prev' + m); p.style.backgroundColor = inp.value; p.style.color = contraste(inp.value); document.getElementById('hex' + m).textContent = inp.value; });
  })(m);
})();
</script>
<?php include __DIR__ . '/../_layout_bottom.php'; ?>
