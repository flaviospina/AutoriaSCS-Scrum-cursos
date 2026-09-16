<?php
/**
 * Diagnóstico geral do sistema (ADMIN) — confere se a última entrega foi
 * realmente aplicada no servidor: arquivos (versão/assinatura), banco de
 * dados (migrações) e cache do PHP (OPcache).
 *
 * Referência: commit 36ccfd7 (Bloco A — níveis de ensino, categorias,
 * rótulo Apontamento e regras do Propor Curso).
 */
require_once __DIR__ . '/_admin_top.php';

$ROOT = realpath(__DIR__ . '/../..');
$ok = null; $erro = null;

// ------------------------------------------------------------------
// Ações: limpar OPcache (o servidor pode servir código antigo por horas)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if (($_POST['action'] ?? '') === 'opcache_reset') {
    if (function_exists('opcache_reset') && @opcache_reset()) {
      audit_log('opcache_limpo', 'sistema', null, null, null);
      $ok = "OPcache limpo. Recarregue as páginas (Ctrl+F5).";
    } else {
      $erro = "OPcache não está disponível/ativo neste servidor — nada a limpar.";
    }
  }
}

// ------------------------------------------------------------------
// 1) Arquivos da entrega: caminho, existência, data, assinatura (md5) e
//    um "marcador" — trecho de código que só existe na versão nova.
// ------------------------------------------------------------------
$arquivos = [
  ['app/niveis_repo.php',            '43fd6beb60e20f12c7c8ce58a355fd3b', 'function niveis_lista('],
  ['app/categorias_repo.php',        'f91e222e7d7945d3a2d0f93294b67aee', 'function categorias_lista('],
  ['app/status_repo.php',            '2ca8a21929b36614d5a0642bedade037', 'return niveis_nomes('],
  ['app/entregas_repo.php',          'bd6a22f450c2e5b0fefd6b5755f570f3', 'categorias_lista('],
  ['app/curso_repo.php',             '52486ae545afe69e2d95e29ff07c4261', 'nivel_valido_para_curso('],
  ['public/admin/niveis.php',        '5e20090d9ba7112fa7255efa3c7c6f2c', 'Níveis de Ensino'],
  ['public/admin/categorias.php',    'd238f6d7543f770396a7f51cfff60073', 'Categorias de Entrega'],
  ['public/admin/index.php',         '2e31b835237cb21c0a09d206f490c8a8', "'niveis.php'"],
  ['public/_layout_top.php',         '8bc5d1b2c6a92ded83b1aaa723e2b4b0', 'admin/categorias.php'],
  ['public/curso_novo.php',          '7fbeb83fe9af0c58f21e6956d7a98087', '$podePrioridade'],
  ['public/curso_editar.php',        'cae54f0e2913754a97222840a20dfa7d', 'niveis_opcoes_para('],
  ['public/curso_detalhe.php',       '091623451ef721241c665c0695009041', '<th>Apontamento</th>'],
  ['public/apontamentos.php',        'aceb26a58a566be51d7173b8fca244bc', '<th>Apontamento</th>'],
  ['public/dashboard.php',           'a0be7caeb0e9f4b7c49649670fadc13f', 'niveis_ensino(true)'],
  ['public/assets/autoria-dark.css', '23208af7de865e7457649a0c58d8bce2', 'file-selector-button'],
  ['database/upgrade_v10.sql',       '47519a5fcc046333f168f8904fe4c30d', 'tb_niveis_ensino'],
];

$resArq = []; $arqOk = 0;
foreach ($arquivos as [$rel, $md5Esp, $marcador]) {
  $abs = $ROOT . '/' . $rel;
  $r = ['arquivo' => $rel, 'existe' => is_file($abs), 'data' => null, 'md5' => null,
        'md5_ok' => false, 'marcador' => false, 'tamanho' => null];
  if ($r['existe']) {
    $r['data']    = date('d/m/Y H:i:s', filemtime($abs));
    $r['tamanho'] = filesize($abs);
    $r['md5']     = md5_file($abs);
    $r['md5_ok']  = ($r['md5'] === $md5Esp);
    $r['marcador'] = strpos((string)file_get_contents($abs), $marcador) !== false;
  }
  if ($r['existe'] && $r['marcador']) $arqOk++;
  $resArq[] = $r;
}

// ------------------------------------------------------------------
// 2) Banco de dados: migrações V2..V10 (tabelas/colunas esperadas)
// ------------------------------------------------------------------
function diag_tabela(string $t): bool {
  $st = db()->prepare("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->execute([$t]);
  return (int)$st->fetch()['n'] > 0;
}
function diag_coluna(string $t, string $c): bool {
  $st = db()->prepare("SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$t, $c]);
  return (int)$st->fetch()['n'] > 0;
}
function diag_count(string $sql): string {
  try { return (string)db()->query($sql)->fetch()['n']; } catch (Throwable $e) { return '—'; }
}
$migracoes = [
  ['V2',  'Fluxo dinâmico (Kanban)',        diag_tabela('tb_status') && diag_tabela('tb_status_transicoes')],
  ['V3',  'Auditoria / e-mails / modelos',  diag_tabela('tb_audit_log') && diag_tabela('tb_notificacoes')],
  ['V4',  'Perfis dinâmicos',               diag_tabela('tb_perfis')],
  ['V6',  'Escolas',                        diag_tabela('tb_escolas')],
  ['V7',  'Entregas "sem material"',        diag_tabela('tb_curso_dispensas')],
  ['V8',  'Revisão de vídeos',              diag_tabela('tb_videos') && diag_tabela('tb_video_marcacoes')],
  ['V9',  'Projeto aprovado + descrição do vídeo', diag_coluna('tb_cursos', 'projeto_aprovado_em') && diag_coluna('tb_videos', 'descricao')],
  ['V10', 'Níveis de ensino + categorias',  diag_tabela('tb_niveis_ensino') && diag_tabela('tb_categorias')],
];
$dbInfo = db()->query("SELECT DATABASE() db, VERSION() v, @@character_set_database cs, USER() u")->fetch();
$nNiveis = diag_count("SELECT COUNT(*) n FROM tb_niveis_ensino");
$nCateg  = diag_count("SELECT COUNT(*) n FROM tb_categorias");
$temFundMedio = '—';
try {
  $st = db()->query("SELECT COUNT(*) n FROM tb_niveis_ensino WHERE nome='Ensino Fundamental - Médio'");
  $temFundMedio = (int)$st->fetch()['n'] > 0 ? 'sim' : 'não';
} catch (Throwable $e) {}

// ------------------------------------------------------------------
// 3) Ambiente PHP / OPcache
// ------------------------------------------------------------------
$opc = null;
if (function_exists('opcache_get_status')) {
  $s = @opcache_get_status(false);
  if (is_array($s)) $opc = $s;
}
$opcAtivo = $opc ? (bool)($opc['opcache_enabled'] ?? false) : false;
$revalidate = ini_get('opcache.revalidate_freq');
$validateTs = ini_get('opcache.validate_timestamps');

// funções da versão nova realmente carregadas nesta execução?
$funcNovas = [
  'niveis_lista'       => function_exists('niveis_lista'),
  'niveis_nomes'       => function_exists('niveis_nomes'),
];
require_once __DIR__ . '/../../app/entregas_repo.php';
$funcNovas['categorias_lista'] = function_exists('categorias_lista');
$niveisCarregados = function_exists('niveis_ensino') ? niveis_ensino() : [];
$catsModulo = function_exists('entregas_categorias') ? array_column(entregas_categorias(1), 'nome') : [];

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Diagnóstico do Sistema</h1>
    <div class="text-muted small">Confere se a última entrega (Bloco A — commit <code>36ccfd7</code>) está aplicada neste servidor.</div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<!-- Resumo -->
<div class="row g-3 mb-3">
  <div class="col-12 col-md-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Arquivos da entrega</div>
      <div class="h4 mb-0 <?= $arqOk === count($arquivos) ? 'text-success' : 'text-danger' ?>"><?= $arqOk ?> / <?= count($arquivos) ?></div>
      <div class="small text-muted">com a versão nova instalada</div>
    </div></div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Migração V10 (banco)</div>
      <div class="h4 mb-0 <?= end($migracoes)[2] ? 'text-success' : 'text-danger' ?>"><?= end($migracoes)[2] ? 'Aplicada' : 'NÃO aplicada' ?></div>
      <div class="small text-muted">níveis: <?= $nNiveis ?> • categorias: <?= $nCateg ?> • "Ensino Fundamental - Médio": <?= $temFundMedio ?></div>
    </div></div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">OPcache (cache de código PHP)</div>
      <div class="h4 mb-0"><?= $opcAtivo ? 'Ativo' : 'Inativo' ?></div>
      <?php if ($opcAtivo): ?>
        <form method="post" class="mt-1"><?= csrf_field() ?>
          <input type="hidden" name="action" value="opcache_reset">
          <button class="btn btn-sm btn-warning">Limpar cache do PHP agora</button>
        </form>
      <?php else: ?>
        <div class="small text-muted">sem cache de código — arquivos novos valem na hora</div>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<!-- Onde o sistema está rodando -->
<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 mb-2">Onde este sistema está rodando</h2>
  <div class="row small">
    <div class="col-12 col-lg-6">
      <div><b>Pasta raiz do sistema:</b> <code><?= htmlspecialchars($ROOT) ?></code></div>
      <div><b>Este arquivo:</b> <code><?= htmlspecialchars(__FILE__) ?></code></div>
      <div><b>URL acessada:</b> <code><?= htmlspecialchars(($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '')) ?></code></div>
      <div><b>PHP:</b> <?= PHP_VERSION ?> (<?= PHP_SAPI ?>)</div>
    </div>
    <div class="col-12 col-lg-6">
      <div><b>Banco:</b> <code><?= htmlspecialchars($dbInfo['db']) ?></code> • MySQL/MariaDB <?= htmlspecialchars($dbInfo['v']) ?> • charset <?= htmlspecialchars($dbInfo['cs']) ?></div>
      <div><b>Usuário do banco:</b> <code><?= htmlspecialchars($dbInfo['u']) ?></code></div>
      <?php if ($opcAtivo): ?>
        <div><b>OPcache:</b> validate_timestamps=<?= htmlspecialchars((string)$validateTs) ?>, revalidate_freq=<?= htmlspecialchars((string)$revalidate) ?>s
          <?= ($validateTs === '0' || (int)$revalidate > 60) ? '<span class="badge bg-warning text-dark">pode servir código antigo — use "Limpar cache"</span>' : '' ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="alert alert-info small mt-2 mb-0">
    Se a <b>pasta raiz</b> acima for diferente da pasta onde você enviou os arquivos pelo cPanel/FTP, os arquivos foram
    enviados para outro lugar (ex.: uma cópia antiga do sistema). O caminho correto é a pasta que contém <code>app/</code>, <code>public/</code> e <code>database/</code>.
  </div>
</div></div>

<!-- Arquivos -->
<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 mb-2">Arquivos da entrega</h2>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light"><tr>
        <th>Arquivo</th><th>Existe</th><th>Data no servidor</th><th>Tamanho</th><th>Versão nova?</th><th>Assinatura idêntica?</th>
      </tr></thead>
      <tbody>
      <?php foreach ($resArq as $r): ?>
        <tr class="<?= !$r['existe'] || !$r['marcador'] ? 'table-danger' : '' ?>">
          <td><code><?= htmlspecialchars($r['arquivo']) ?></code></td>
          <td><?= $r['existe'] ? '✅' : '❌ <b>não enviado</b>' ?></td>
          <td class="text-nowrap"><?= $r['data'] ?? '—' ?></td>
          <td class="text-nowrap"><?= $r['tamanho'] !== null ? number_format($r['tamanho']) . ' B' : '—' ?></td>
          <td><?= !$r['existe'] ? '—' : ($r['marcador'] ? '✅ sim' : '❌ <b>versão antiga</b>') ?></td>
          <td><?= !$r['existe'] ? '—' : ($r['md5_ok'] ? '✅' : '<span class="text-warning">≠ (conteúdo difere do commit)</span>') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="small text-muted mt-2">
    <b>Versão nova?</b> procura um trecho de código que só existe na versão entregue.
    <b>Assinatura</b> compara o MD5 com o do commit — pode divergir se o editor/FTP alterou quebras de linha (não é problema se "Versão nova" estiver ✅).
  </div>
</div></div>

<!-- Código efetivamente carregado -->
<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 mb-2">Código realmente em execução (nesta requisição)</h2>
  <div class="row small">
    <div class="col-12 col-lg-4">
      <?php foreach ($funcNovas as $f => $tem): ?>
        <div><code><?= $f ?>()</code> <?= $tem ? '✅ carregada' : '❌ <b>não existe</b> (arquivo antigo ou cache)' ?></div>
      <?php endforeach; ?>
    </div>
    <div class="col-12 col-lg-4">
      <div><b>Níveis de ensino que o sistema está usando:</b></div>
      <ul class="mb-0"><?php foreach ($niveisCarregados as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul>
    </div>
    <div class="col-12 col-lg-4">
      <div><b>Categorias do Módulo 1 (ordem de entrega):</b></div>
      <ol class="mb-0"><?php foreach ($catsModulo as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ol>
    </div>
  </div>
  <div class="small text-muted mt-2">
    Se "Ensino Fundamental - Médio" não aparecer na lista mesmo com a V10 aplicada, o PHP está executando arquivos antigos (cache ou pasta errada).
  </div>
</div></div>

<!-- Banco -->
<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 mb-2">Migrações do banco de dados</h2>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Versão</th><th>O que cria</th><th>Situação</th></tr></thead>
      <tbody>
      <?php foreach ($migracoes as [$v, $desc, $okm]): ?>
        <tr class="<?= $okm ? '' : 'table-danger' ?>">
          <td><b><?= $v ?></b></td><td><?= $desc ?></td>
          <td><?= $okm ? '✅ aplicada' : '❌ <b>não aplicada</b> — execute <code>database/upgrade_' . strtolower($v) . '.sql</code>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div></div>

<div class="alert alert-secondary small">
  <b>Como interpretar:</b>
  ❌ em "Existe" → o arquivo não chegou ao servidor (ou foi para outra pasta) •
  ❌ em "Versão nova?" → o upload não substituiu o arquivo (confira se o cPanel perguntou "sobrescrever?") •
  tudo ✅ nos arquivos mas a tela não muda → limpe o OPcache acima e o cache do navegador (Ctrl+F5) •
  V10 ❌ → rode <code>database/upgrade_v10.sql</code> no phpMyAdmin (o sistema segue funcionando com as listas antigas até lá).
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
