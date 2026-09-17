<?php
/**
 * Diagnóstico geral do sistema (ADMIN) — confere se a última entrega foi
 * realmente aplicada no servidor: arquivos (versão/assinatura), banco de
 * dados (migrações) e cache do PHP (OPcache).
 *
 * Referência: entrega V11 (PROMPT MESTRE — Blocos A a G).
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
  ['app/apontamento_repo.php', 'e6279f082fb09ec02a4f0f8356caa1c4', 'function apont_criar('],
  ['app/auth.php', '5ae623857bd0fd85a0ea58a9ab565e3c', 'function visao_alternar('],
  ['app/categorias_repo.php', '054ff959a0ed0f9a63b3ca531c609993', 'excluida_em'],
  ['app/checklist_repo.php', '9374cd1d2654f5ad94e6abeb183ee0dd', 'function checklist_salvar('],
  ['app/curso_repo.php', 'f693bdb1c8fae80a41079bba38b31799', 'function curso_coautor_adicionar('],
  ['app/entregas_repo.php', '315bf7b9103b57d9329a700612d89322', 'function entregas_pendentes('],
  ['app/niveis_repo.php', '43fd6beb60e20f12c7c8ce58a355fd3b', 'function niveis_lista('],
  ['app/notify.php', '393456e1a3431d3e3341f9c9a5488f2e', 'function notify_apontamento_evento('],
  ['app/status_repo.php', '2ca8a21929b36614d5a0642bedade037', 'return niveis_nomes('],
  ['public/_layout_bottom.php', 'e94ab6de52815f317b9fff6d944666db', 'data-etapa-regra'],
  ['public/_layout_top.php', '7b238350264477122412868e51580c91', 'trocar_visao.php'],
  ['public/admin/categorias.php', '1eb567da87c6f042cabccaa0a91e8bee', 'tipo_especial'],
  ['public/admin/checklists.php', '6d7b0418eec23acdfcd19507b5b91d5f', 'Checklists'],
  ['public/admin/index.php', 'b9f524c69fb7918fb20bb98880eaa128', 'checklists.php'],
  ['public/admin/niveis.php', '5e20090d9ba7112fa7255efa3c7c6f2c', 'Níveis de Ensino'],
  ['public/admin/status.php', '290038baceefe4923c186b183cc91b69', 'exige_entregas'],
  ['public/apontamento_detalhe.php', '0c15146082ab673dd72298ad3011a83d', 'Registrar objeção'],
  ['public/apontamentos.php', '9f7f92e00377bdbbf4238fc005b06f93', '<th>Arquivo</th>'],
  ['public/assets/autoria-dark.css', '2c2e6c9ee50ca75b70a626d0145c97f4', 'aviso-apontamentos'],
  ['public/curso_detalhe.php', 'c9742f2d55be336746cf8b76a044939c', 'avisaPendencias'],
  ['public/curso_editar.php', '48f0e68583051e05fc920090dad53282', 'campo-ro'],
  ['public/curso_novo.php', '6f76063474dcb6b0279fdb1dcf9b632e', 'coautores'],
  ['public/curso_videos.php', 'a843b233c19bbbd193b09a3c4250d2d4', 'video_receber_versao_form('],
  ['public/dashboard.php', 'be88da4a8dc845c41a359957043e7488', 'apont_pendentes_por_curso('],
  ['public/download.php', '28b48691153a77cb34b65b4b1e163806', 'curso_eh_professor('],
  ['public/download_todos.php', '223f918db4a6c3472740defbce48719b', 'curso_eh_professor('],
  ['public/move_status.php', '65d36720706a15aab61c03f63e96ddc9', 'EntregasPendentesException'],
  ['public/trocar_visao.php', 'edfe1f047eb980e24117950c6c267536', 'visao_alternar('],
  ['public/upload.php', '09a46da585ddf03174d97ffd0692af47', 'curso_eh_professor('],
  ['public/video_captura.php', 'e70e1c568e0e5ac9b14d886bc78be4c5', 'curso_eh_professor('],
  ['public/video_revisao.php', 'e9ee4581a0d688b4df77df8acac98158', 'playerPreview'],
  ['public/video_stream.php', '29169e537e92f129b226ca691f089ed8', 'drive_stream_range('],
  ['database/upgrade_v10.sql', '47519a5fcc046333f168f8904fe4c30d', 'tb_niveis_ensino'],
  ['database/upgrade_v11.sql', '323e6271d2ee3d01e7b775da0584dc96', 'tb_apontamento_historico'],
  ['app/drive_client.php', 'de5e11ab3acd385045b876d0fa4971fd', 'function drive_stream_range('],
  ['app/video_repo.php', 'b7248248d3d941d1d24d4fda6c07acd0', 'function video_versao_de_link('],
  ['app/config.php', '571c5f48c5262a8f70ab039e5d4df221', 'key_file'],
  ['public/_video_fonte.php', '04ae234b07180fcfca17519e65022ec4', 'fonteDrive'],
  ['database/upgrade_v12.sql', 'a437828caf6c94dd88bd6165f5890878', 'drive_file_id'],
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
  ['V11', 'Checklists, apontamentos (status/histórico), coautores, slide/vídeo, soft delete',
          diag_tabela('tb_checklist_itens') && diag_tabela('tb_apontamento_historico') && diag_tabela('tb_curso_professores')
          && diag_coluna('tb_curso_apontamentos', 'status') && diag_coluna('tb_curso_files', 'aprovado') && diag_coluna('tb_status', 'exige_entregas')],
  ['V12', 'Vídeos por link do Google Drive (origem da versão)', diag_coluna('tb_video_versoes', 'origem')],
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
  'visao_alternar'     => function_exists('visao_alternar'),
];
require_once __DIR__ . '/../../app/apontamento_repo.php';
require_once __DIR__ . '/../../app/checklist_repo.php';
require_once __DIR__ . '/../../app/drive_client.php';
// Google Drive (V12): chave da conta de serviço e teste de acesso
$driveInfo = ['configurado' => drive_configurado(), 'email' => drive_service_email(), 'key_file' => drive_config()['key_file'] ?? '', 'teste' => null, 'erro' => null];
if ($driveInfo['configurado']) {
  try { $driveInfo['teste'] = drive_testar(); } catch (Throwable $e) { $driveInfo['erro'] = $e->getMessage(); }
}
$funcNovas['apont_criar'] = function_exists('apont_criar');
$funcNovas['checklist_salvar'] = function_exists('checklist_salvar');
$funcNovas['entregas_pendentes'] = function_exists('entregas_pendentes');
require_once __DIR__ . '/../../app/entregas_repo.php';
$funcNovas['categorias_lista'] = function_exists('categorias_lista');
$niveisCarregados = function_exists('niveis_ensino') ? niveis_ensino() : [];
$catsModulo = function_exists('entregas_categorias') ? array_column(entregas_categorias(1), 'nome') : [];

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Diagnóstico do Sistema</h1>
    <div class="text-muted small">Confere se a última entrega (V11 — PROMPT MESTRE, Blocos A a G) está aplicada neste servidor.</div>
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
      <div class="small text-muted">Migrações V10 + V11 (banco)</div>
      <div class="h4 mb-0 <?= ($migracoes[7][2] && $migracoes[8][2]) ? 'text-success' : 'text-danger' ?>"><?= ($migracoes[7][2] && $migracoes[8][2]) ? 'Aplicadas' : 'NÃO aplicadas' ?></div>
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

<!-- Google Drive -->
<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 mb-2">Google Drive (vídeos por link — V12)</h2>
  <?php if (!$driveInfo['configurado']): ?>
    <div class="alert alert-warning small mb-2">
      <b>Conta de serviço não configurada</b> — os links do Drive funcionam em <b>modo de contingência</b> (player do Google em iframe,
      sem "agora" e sem captura de frame). Para o player completo: salve a chave JSON em
      <code><?= htmlspecialchars($driveInfo['key_file']) ?></code> (fora de <code>public/</code>) e ative a Drive API no projeto Google Cloud.
    </div>
  <?php elseif ($driveInfo['erro']): ?>
    <div class="alert alert-danger small mb-2"><b>Chave presente, mas o Google recusou:</b> <?= htmlspecialchars($driveInfo['erro']) ?></div>
  <?php else: ?>
    <div class="alert alert-success small mb-2">✅ Integração ativa. Conta de serviço: <code><?= htmlspecialchars($driveInfo['teste']['email'] ?? $driveInfo['email']) ?></code></div>
  <?php endif; ?>
  <div class="small text-muted">
    E-mail para a MB compartilhar a pasta dos vídeos (como Leitor):
    <code><?= htmlspecialchars($driveInfo['email'] ?? '(será exibido após configurar a chave)') ?></code>
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
