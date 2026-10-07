<?php
/**
 * Admin → Zerar dados (V13): limpa a base para a entrada em produção.
 * Exige: perfil ADMIN real (sem "Ver como"), senha do administrador e a frase
 * de confirmação. Gera backup (SQL + pastas movidas) antes de apagar.
 */
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/reset_repo.php';

if (!is_admin_real() || visao_alternada() !== null) {
  http_response_code(403);
  exit('Esta rotina só pode ser executada pelo ADMIN com o seu próprio perfil (desative "Ver como").');
}
$uReal = auth_user_real();

$erro = null; $relatorio = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    if (($_POST['action'] ?? '') !== 'zerar') throw new Exception('Ação inválida.');

    // 1) senha do administrador
    $st = db()->prepare("SELECT senha_hash FROM tb_users WHERE id_user=? AND ativo=1");
    $st->execute([(int)$uReal['id_user']]);
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify((string)($_POST['senha'] ?? ''), $hash)) {
      audit_log('base_zerar_negado', 'sistema', null, null, ['motivo' => 'senha incorreta']);
      throw new Exception('Senha incorreta. Nada foi apagado.');
    }
    // 2) frase de confirmação
    if (trim($_POST['frase'] ?? '') !== RESET_FRASE) {
      throw new Exception('A frase de confirmação deve ser exatamente "' . RESET_FRASE . '". Nada foi apagado.');
    }
    // 3) ciência do backup
    if (empty($_POST['ciente'])) throw new Exception('Marque a caixa confirmando que está ciente. Nada foi apagado.');

    $opcoes = [
      'notificacoes' => !empty($_POST['op_notificacoes']),
      'auditoria'    => !empty($_POST['op_auditoria']),
      'modelos'      => !empty($_POST['op_modelos']),
      'usuarios'     => !empty($_POST['op_usuarios']),
    ];
    set_time_limit(600);
    $relatorio = reset_executar($opcoes, (int)$uReal['id_user']);
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$c = reset_contagens();
$backups = reset_backups_existentes();
include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h1 class="h4 mb-0">Zerar dados</h1>
    <div class="text-muted small">Limpeza da base para a entrada em produção. Configurações e administradores são preservados.</div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?>
  <div class="alert alert-danger"><b>Não executado.</b> <?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<?php if ($relatorio): ?>
  <div class="alert alert-success">
    <h2 class="h5">✅ Base zerada com sucesso</h2>
    <p class="mb-2">Backup gerado em <code><?= htmlspecialchars(str_replace(realpath(__DIR__ . '/../../'), '', $relatorio['backup_sql'])) ?></code>.
      Os arquivos dos cursos foram <b>movidos</b> para a pasta do backup (nada foi destruído). Quando tiver certeza, apague a pasta
      <code>storage/backups/<?= htmlspecialchars(basename($relatorio['backup_dir'])) ?></code> pelo cPanel para liberar espaço.</p>
    <div class="table-responsive">
      <table class="table table-sm table-bordered bg-white mb-2">
        <thead><tr><th>Tabela zerada</th><th class="text-end">Registros no backup</th></tr></thead>
        <tbody>
          <?php foreach ($relatorio['tabelas'] as $t => $n): ?>
            <tr><td><code><?= htmlspecialchars($t) ?></code></td><td class="text-end"><?= (int)$n ?></td></tr>
          <?php endforeach; ?>
          <?php if ($relatorio['usuarios_removidos']): ?>
            <tr><td>Usuários não administradores removidos</td><td class="text-end"><?= (int)$relatorio['usuarios_removidos'] ?></td></tr>
          <?php endif; ?>
          <?php foreach ($relatorio['pastas'] as $p => [$bytes, $n, $obs]): ?>
            <tr><td>Pasta <code>storage/<?= htmlspecialchars($p) ?></code> — <?= htmlspecialchars($obs) ?></td>
                <td class="text-end"><?= (int)$n ?> arquivo(s), <?= reset_fmt_bytes((int)$bytes) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="small">Os demais usuários foram desconectados. A ação ficou registrada na <a href="auditoria.php">Auditoria</a>.</div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6">O que existe hoje</h2>
        <table class="table table-sm mb-3">
          <tbody>
            <tr><td>Cursos</td><td class="text-end fw-bold"><?= $c['cursos'] ?></td></tr>
            <tr><td>Entregas (arquivos de materiais)</td><td class="text-end fw-bold"><?= $c['entregas'] ?></td></tr>
            <tr><td>Versões de vídeo</td><td class="text-end fw-bold"><?= $c['videos'] ?></td></tr>
            <tr><td>Apontamentos</td><td class="text-end fw-bold"><?= $c['apontamentos'] ?></td></tr>
            <tr><td>Movimentações de status</td><td class="text-end fw-bold"><?= $c['historico'] ?></td></tr>
            <tr><td>Arquivos em <code>storage/cursos</code></td><td class="text-end"><?= $c['arquivos']['cursos'][1] ?> (<?= reset_fmt_bytes($c['arquivos']['cursos'][0]) ?>)</td></tr>
            <tr><td>Arquivos em <code>storage/videos</code></td><td class="text-end"><?= $c['arquivos']['videos'][1] ?> (<?= reset_fmt_bytes($c['arquivos']['videos'][0]) ?>)</td></tr>
            <tr class="table-light"><td>Fila de notificações</td><td class="text-end"><?= $c['notificacoes'] ?></td></tr>
            <tr class="table-light"><td>Registros de auditoria</td><td class="text-end"><?= $c['auditoria'] ?></td></tr>
            <tr class="table-light"><td>Modelos da biblioteca</td><td class="text-end"><?= $c['modelos'] ?> (<?= reset_fmt_bytes($c['arquivos']['modelos'][0]) ?>)</td></tr>
            <tr class="table-light"><td>Usuários não administradores</td><td class="text-end"><?= $c['usuarios_out'] ?></td></tr>
            <tr class="table-success"><td>Administradores (preservados)</td><td class="text-end fw-bold"><?= $c['usuarios_adm'] ?></td></tr>
          </tbody>
        </table>
        <h2 class="h6">Preservado sempre</h2>
        <ul class="small mb-0">
          <?php foreach ($c['config'] as $nome => $n): ?>
            <li><?= htmlspecialchars($nome) ?>: <b><?= $n ?></b></li>
          <?php endforeach; ?>
          <li>Usuários com perfil <?= htmlspecialchars(implode(', ', $c['perfis_admin'])) ?></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card shadow-sm border-danger h-100">
      <div class="card-body">
        <h2 class="h6 text-danger">⚠ Executar a limpeza</h2>
        <p class="small text-muted">Sempre apaga: todos os cursos e seus materiais, vídeos, apontamentos, checklists respondidos, coautores e histórico, além dos arquivos em <code>storage/cursos</code> e <code>storage/videos</code>. Antes de apagar, o sistema gera um backup em <code>storage/backups/</code> e move as pastas de arquivos para lá.</p>

        <form method="post" id="formZerar" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="zerar">

          <div class="fw-semibold small mb-1">Também apagar (opcional):</div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="op_notificacoes" id="opN" checked>
            <label class="form-check-label" for="opN">Fila de notificações (e-mails pendentes e enviados dos testes)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="op_auditoria" id="opA" checked>
            <label class="form-check-label" for="opA">Auditoria (registros dos testes; a própria limpeza fica registrada)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="op_usuarios" id="opU">
            <label class="form-check-label" for="opU">Usuários não administradores (<?= $c['usuarios_out'] ?>) — <b>desmarque</b> se os professores, a TI e a MB já estão cadastrados com as contas definitivas</label></div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="op_modelos" id="opM">
            <label class="form-check-label" for="opM">Biblioteca de modelos (<?= $c['modelos'] ?>) — normalmente <b>não</b>: são os templates oficiais</label></div>

          <div class="row g-2 mb-2">
            <div class="col-12 col-md-6">
              <label class="form-label small mb-0" for="senha">Sua senha de administrador</label>
              <input type="password" class="form-control" name="senha" id="senha" required autocomplete="current-password">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label small mb-0" for="frase">Digite <code><?= RESET_FRASE ?></code> para confirmar</label>
              <input type="text" class="form-control" name="frase" id="frase" required autocomplete="off" placeholder="<?= RESET_FRASE ?>">
            </div>
          </div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="ciente" id="ciente" required>
            <label class="form-check-label small" for="ciente">Estou ciente de que os dados serão apagados do sistema e que o backup fica em <code>storage/backups/</code> até eu removê-lo.</label></div>

          <button type="submit" class="btn btn-danger" id="btnZerar">🗑 Zerar dados agora</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($backups): ?>
  <div class="card shadow-sm mt-3">
    <div class="card-body">
      <h2 class="h6">Backups existentes em <code>storage/backups/</code></h2>
      <p class="small text-muted mb-2">Ocupam espaço na hospedagem. Baixe pelo cPanel o que quiser guardar e apague a pasta quando não precisar mais.</p>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Pasta</th><th>Gerado em</th><th class="text-end">Arquivos</th><th class="text-end">Tamanho</th></tr></thead>
          <tbody>
            <?php foreach ($backups as $b): ?>
              <tr><td><code><?= htmlspecialchars($b['pasta']) ?></code></td><td><?= $b['quando'] ?></td>
                  <td class="text-end"><?= $b['arquivos'] ?></td><td class="text-end"><?= reset_fmt_bytes($b['bytes']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
document.getElementById('formZerar').addEventListener('submit', function (ev) {
  if (this.dataset.ok === '1') return;
  ev.preventDefault();
  const f = this;
  const extras = ['opN','opA','opU','opM'].filter(id => document.getElementById(id).checked)
    .map(id => document.querySelector('label[for="' + id + '"]').textContent.split('—')[0].split('(')[0].trim());
  Swal.fire({
    icon: 'warning',
    title: 'Zerar todos os dados?',
    html: '<div class="text-start small">Serão apagados <b>todos os cursos</b>, materiais, vídeos e apontamentos.' +
          (extras.length ? '<br>Também: <b>' + extras.join(', ') + '</b>.' : '') +
          '<br><br>Um backup será gerado em <code>storage/backups/</code> antes de apagar.</div>',
    showCancelButton: true, confirmButtonText: 'Sim, zerar agora', cancelButtonText: 'Cancelar',
    confirmButtonColor: '#dc3545', focusCancel: true
  }).then(r => {
    if (!r.isConfirmed) return;
    f.dataset.ok = '1';
    document.getElementById('btnZerar').disabled = true;
    document.getElementById('btnZerar').textContent = 'Zerando… aguarde';
    f.submit();
  });
});
</script>
<?php include __DIR__ . '/../_layout_bottom.php'; ?>
