<?php
/**
 * Relatórios gerenciais (V14): catálogo de relatórios por grupo, filtros,
 * tabela, exportação CSV e impressão. Cada indicador da página de
 * indicadores aponta para o relatório correspondente (?r=chave&filtros).
 * Acesso: equipe de gestão (ve_todos_cursos) — TI, MB e ADMIN.
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/audit.php';
require_once __DIR__ . '/../app/indicadores_repo.php';

require_login();
$u = auth_user();
if (!is_staff()) { http_response_code(403); exit('Acesso restrito à equipe de gestão (TI/MB/ADMIN).'); }

$cat = rel_catalogo();
$r = trim($_GET['r'] ?? '');
$f = [];
foreach (['de', 'ate', 'status', 'id_professor', 'unidade_escolar', 'nivel_ensino', 'prioridade', 'carga_horaria', 'situacao', 'janela', 'tipo', 'dim', 'filtro', 'status_apont'] as $k) {
  $v = trim((string)($_GET[$k] ?? ''));
  if ($v !== '') $f[$k] = $v;
}
foreach (['de', 'ate'] as $k) if (isset($f[$k]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) unset($f[$k]);

$rel = $r !== '' ? rel_gerar($r, $f) : null;
if ($r !== '' && !$rel) { http_response_code(404); exit('Relatório não encontrado.'); }

// ---- exportação CSV (Excel pt-BR: ; e UTF-8 com BOM) ----
if ($rel && ($_GET['csv'] ?? '') === '1') {
  audit_log('relatorio_exportado', 'sistema', null, null, ['relatorio' => $r, 'filtros' => $f, 'linhas' => count($rel['linhas'])]);
  session_write_close();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="relatorio-' . $r . '-' . date('Ymd-Hi') . '.csv"');
  $o = fopen('php://output', 'w');
  fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, [$rel['titulo'] . ' — gerado em ' . date('d/m/Y H:i') . ($rel['filtros_desc'] ? ' — filtros: ' . implode(', ', $rel['filtros_desc']) : '')], ';');
  fputcsv($o, $rel['colunas'], ';');
  foreach ($rel['linhas'] as $l) fputcsv($o, array_map(fn($v) => (string)$v, $l), ';');
  fputcsv($o, [], ';');
  foreach ($rel['resumo'] as $k => $v) fputcsv($o, [$k, $v], ';');
  fclose($o);
  exit;
}

$opc = rel_opcoes_filtros();
$filtrosRel = $rel ? ($cat[$r][3] ?? []) : [];
$grupos = ['producao' => '📚 Produção', 'fluxo' => '🔁 Fluxo e tempos', 'prazos' => '⏰ Prazos', 'pessoas' => '👥 Pessoas', 'qualidade' => '🧐 Qualidade', 'materiais' => '📁 Materiais', 'videos' => '🎬 Vídeos', 'checklists' => '✅ Checklists', 'sistema' => '⚙️ Sistema'];

function rel_qs(array $extra = []): string { return http_build_query(array_merge($_GET, $extra)); }

include __DIR__ . '/_layout_top.php';
?>
<style>
  .rel-menu a { display:block; padding:.3rem .6rem; border-radius:6px; font-size:.86rem; text-decoration:none; }
  .rel-menu a:hover { background: rgba(128,128,128,.15); }
  .rel-menu a.ativo { background: var(--autoria-teal, #0aa5c8); color:#fff; }
  .rel-menu .grupo { font-size:.72rem; letter-spacing:.06em; text-transform:uppercase; opacity:.7; margin:.7rem .6rem .2rem; }
  .resumo-chip { display:inline-block; padding:.2rem .6rem; border-radius:999px; background: rgba(128,128,128,.15); font-size:.8rem; margin:0 .3rem .3rem 0; }
  .so-print { display:none; }
  @media print {
    .no-print, nav, footer, .visao-banner, .rel-menu-col { display:none !important; }
    .so-print { display:block; }
    .rel-col { width:100% !important; flex: 0 0 100% !important; max-width:100% !important; }
    body { background:#fff !important; color:#000 !important; }
    .card { border:1px solid #999 !important; box-shadow:none !important; }
    table { font-size: 10pt; }
    a[href]::after { content: "" !important; }
  }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h1 class="h4 mb-0">Relatórios gerenciais</h1>
    <div class="text-muted small"><?= count($cat) ?> relatórios disponíveis • filtros, exportação CSV (Excel) e impressão</div>
  </div>
  <div class="d-flex gap-2 no-print">
    <a class="btn btn-outline-primary" href="indicadores.php">📊 Indicadores</a>
    <a class="btn btn-outline-secondary" href="dashboard.php">Voltar</a>
  </div>
</div>

<div class="row g-3">
  <!-- catálogo -->
  <div class="col-12 col-lg-3 rel-menu-col no-print">
    <div class="card shadow-sm"><div class="card-body p-2 rel-menu">
      <?php foreach ($grupos as $gk => $gt): $itens = array_filter($cat, fn($c) => $c[2] === $gk); if (!$itens) continue; ?>
        <div class="grupo"><?= $gt ?></div>
        <?php foreach ($itens as $k => $c): ?>
          <a class="<?= $k === $r ? 'ativo' : '' ?>" href="relatorios.php?r=<?= $k ?>" title="<?= htmlspecialchars($c[1]) ?>"><?= htmlspecialchars($c[0]) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div></div>
  </div>

  <div class="col-12 col-lg-9 rel-col">
    <?php if (!$rel): ?>
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h6">Escolha um relatório no menu ao lado</h2>
        <p class="small text-muted mb-3">Cada relatório aceita filtros (período, status, formador, escola, nível etc.), pode ser exportado para CSV (abre no Excel) e impresso. Na página de <a href="indicadores.php">Indicadores</a>, cada número tem um link direto para o relatório que o detalha.</p>
        <div class="row g-2">
          <?php foreach ($cat as $k => $c): ?>
            <div class="col-12 col-md-6">
              <a class="text-decoration-none" href="relatorios.php?r=<?= $k ?>">
                <div class="border rounded p-2 h-100">
                  <div class="fw-semibold small"><?= $grupos[$c[2]] ?? '' ?> · <?= htmlspecialchars($c[0]) ?></div>
                  <div class="small text-muted"><?= htmlspecialchars($c[1]) ?></div>
                </div>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div></div>
    <?php else: ?>
      <!-- filtros -->
      <?php if ($filtrosRel): ?>
      <div class="card shadow-sm mb-3 no-print"><div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
          <input type="hidden" name="r" value="<?= htmlspecialchars($r) ?>">
          <?php if (isset($f['dim'])): ?><input type="hidden" name="dim" value="<?= htmlspecialchars($f['dim']) ?>"><?php endif; ?>
          <?php if (in_array('periodo', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">De</label><input type="date" class="form-control form-control-sm" name="de" value="<?= htmlspecialchars($f['de'] ?? '') ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Até</label><input type="date" class="form-control form-control-sm" name="ate" value="<?= htmlspecialchars($f['ate'] ?? '') ?>"></div>
          <?php endif; ?>
          <?php if (in_array('status', $filtrosRel, true)): ?>
            <div class="col-12 col-md-3"><label class="form-label small mb-0">Status</label>
              <select class="form-select form-select-sm" name="status"><option value="">Todos</option>
                <?php foreach ($opc['status'] as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= ($f['status'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('formador', $filtrosRel, true)): ?>
            <div class="col-12 col-md-3"><label class="form-label small mb-0">Formador(a) responsável</label>
              <select class="form-select form-select-sm" name="id_professor"><option value="">Todos</option>
                <?php foreach ($opc['formadores'] as $p): ?><option value="<?= (int)$p['id_user'] ?>" <?= (int)($f['id_professor'] ?? 0) === (int)$p['id_user'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nome']) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('escola', $filtrosRel, true)): ?>
            <div class="col-12 col-md-3"><label class="form-label small mb-0">Unidade escolar</label>
              <select class="form-select form-select-sm" name="unidade_escolar"><option value="">Todas</option>
                <?php foreach ($opc['escolas'] as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= ($f['unidade_escolar'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('nivel', $filtrosRel, true)): ?>
            <div class="col-12 col-md-2"><label class="form-label small mb-0">Nível</label>
              <select class="form-select form-select-sm" name="nivel_ensino"><option value="">Todos</option>
                <?php foreach ($opc['niveis'] as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= ($f['nivel_ensino'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('prioridade', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Prioridade</label>
              <select class="form-select form-select-sm" name="prioridade"><option value="">Todas</option>
                <?php foreach (prioridades() as $s): ?><option value="<?= $s ?>" <?= ($f['prioridade'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('carga', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Carga horária</label>
              <select class="form-select form-select-sm" name="carga_horaria"><option value="">Todas</option>
                <?php foreach ($opc['cargas'] as $s): ?><option value="<?= (int)$s ?>" <?= (int)($f['carga_horaria'] ?? 0) === (int)$s ? 'selected' : '' ?>><?= (int)$s ?> h</option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('situacao', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Situação</label>
              <select class="form-select form-select-sm" name="situacao"><option value="">Todas</option>
                <option value="andamento" <?= ($f['situacao'] ?? '') === 'andamento' ? 'selected' : '' ?>>Em andamento</option>
                <option value="concluidos" <?= ($f['situacao'] ?? '') === 'concluidos' ? 'selected' : '' ?>>Concluídos</option>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('janela', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Janela (dias)</label><input type="number" min="0" max="3650" class="form-control form-control-sm" name="janela" value="<?= htmlspecialchars($f['janela'] ?? ($r === 'parados' ? '30' : '7')) ?>"></div>
          <?php endif; ?>
          <?php if (in_array('dim', $filtrosRel, true)): ?>
            <div class="col-12 col-md-3"><label class="form-label small mb-0">Agrupar por</label>
              <select class="form-select form-select-sm" name="dim">
                <?php foreach (['unidade_escolar' => 'Unidade escolar', 'nivel_ensino' => 'Nível de ensino', 'carga_horaria' => 'Carga horária', 'prioridade' => 'Prioridade', 'publico_alvo' => 'Público-alvo', 'status_atual' => 'Status'] as $k => $lab): ?>
                  <option value="<?= $k ?>" <?= ($f['dim'] ?? 'unidade_escolar') === $k ? 'selected' : '' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('tipo_apont', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Tipo</label>
              <select class="form-select form-select-sm" name="tipo"><option value="">Todos</option>
                <?php foreach (['TECNICO', 'PEDAGOGICO', 'ABNT', 'OUTRO'] as $s): ?><option value="<?= $s ?>" <?= ($f['tipo'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('situacao_apont', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Situação</label>
              <select class="form-select form-select-sm" name="situacao"><option value="">Todos</option>
                <option value="abertos" <?= ($f['situacao'] ?? '') === 'abertos' ? 'selected' : '' ?>>Em aberto</option>
                <option value="concluidos" <?= ($f['situacao'] ?? '') === 'concluidos' ? 'selected' : '' ?>>Concluídos</option>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('situacao_entrega', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Aprovação</label>
              <select class="form-select form-select-sm" name="situacao"><option value="">Todas</option>
                <option value="aprovados" <?= ($f['situacao'] ?? '') === 'aprovados' ? 'selected' : '' ?>>Aprovados</option>
                <option value="pendentes" <?= ($f['situacao'] ?? '') === 'pendentes' ? 'selected' : '' ?>>Pendentes</option>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('origem', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Origem</label>
              <select class="form-select form-select-sm" name="tipo"><option value="">Todas</option>
                <option value="UPLOAD" <?= ($f['tipo'] ?? '') === 'UPLOAD' ? 'selected' : '' ?>>Arquivo</option>
                <option value="DRIVE" <?= ($f['tipo'] ?? '') === 'DRIVE' ? 'selected' : '' ?>>Link do Drive</option>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('situacao_video', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Status do vídeo</label>
              <select class="form-select form-select-sm" name="situacao"><option value="">Todos</option>
                <?php foreach (array_column(ind_rows("SELECT DISTINCT status FROM tb_videos ORDER BY 1"), 'status') as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= ($f['situacao'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('situacao_marc', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Situação</label>
              <select class="form-select form-select-sm" name="situacao"><option value="">Todas</option>
                <option value="abertas" <?= ($f['situacao'] ?? '') === 'abertas' ? 'selected' : '' ?>>Pendentes</option>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('situacao_notif', $filtrosRel, true)): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Status</label>
              <select class="form-select form-select-sm" name="situacao"><option value="">Todos</option>
                <?php foreach (['PENDENTE', 'ENVIADO', 'ERRO'] as $s): ?><option value="<?= $s ?>" <?= ($f['situacao'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('tipo_acao', $filtrosRel, true)): ?>
            <div class="col-12 col-md-3"><label class="form-label small mb-0">Ação</label>
              <select class="form-select form-select-sm" name="tipo"><option value="">Todas</option>
                <?php foreach ($opc['acoes'] as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= ($f['tipo'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <?php if (in_array('filtro_usuarios', $filtrosRel, true)): ?>
            <div class="col-12 col-md-3"><label class="form-label small mb-0">Mostrar</label>
              <select class="form-select form-select-sm" name="filtro"><option value="">Todos os usuários</option>
                <option value="sem_curso" <?= ($f['filtro'] ?? '') === 'sem_curso' ? 'selected' : '' ?>>Formadores sem curso</option>
                <option value="acesso_30d" <?= ($f['filtro'] ?? '') === 'acesso_30d' ? 'selected' : '' ?>>Com acesso nos últimos 30 dias</option>
              </select></div>
          <?php endif; ?>
          <div class="col-12 col-md-auto d-flex gap-2">
            <button class="btn btn-sm btn-primary">Aplicar filtros</button>
            <a class="btn btn-sm btn-outline-secondary" href="relatorios.php?r=<?= $r ?><?= isset($f['dim']) ? '&dim=' . htmlspecialchars($f['dim']) : '' ?>">Limpar</a>
          </div>
        </form>
      </div></div>
      <?php endif; ?>

      <div class="card shadow-sm"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
          <div>
            <h2 class="h5 mb-0"><?= htmlspecialchars($rel['titulo']) ?></h2>
            <div class="small text-muted"><?= htmlspecialchars($rel['descricao']) ?></div>
            <?php if ($rel['filtros_desc']): ?><div class="small">Filtros: <?= htmlspecialchars(implode(' • ', $rel['filtros_desc'])) ?></div><?php endif; ?>
            <div class="small so-print">Gerado em <?= date('d/m/Y H:i') ?> por <?= htmlspecialchars($u['nome']) ?> • AutoriaSCS / CECAPE</div>
          </div>
          <div class="d-flex gap-2 no-print">
            <a class="btn btn-sm btn-outline-success" href="relatorios.php?<?= rel_qs(['csv' => '1']) ?>">⬇ Exportar CSV (Excel)</a>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">🖨 Imprimir</button>
          </div>
        </div>

        <div class="mb-2">
          <?php foreach ($rel['resumo'] as $k => $v): ?><span class="resumo-chip"><?= htmlspecialchars((string)$k) ?>: <b><?= htmlspecialchars((string)$v) ?></b></span><?php endforeach; ?>
        </div>

        <?php if (!$rel['linhas']): ?>
          <div class="text-muted small">Nenhum registro para os filtros informados.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0" id="tabelaRel">
              <thead class="table-light"><tr>
                <?php foreach ($rel['colunas'] as $c): ?><th class="text-nowrap"><?= htmlspecialchars($c) ?></th><?php endforeach; ?>
                <?php if ($rel['links']): ?><th class="no-print"></th><?php endif; ?>
              </tr></thead>
              <tbody>
                <?php foreach ($rel['linhas'] as $i => $l): ?>
                  <tr>
                    <?php foreach ($l as $v): ?><td><?= htmlspecialchars((string)$v) ?></td><?php endforeach; ?>
                    <?php if ($rel['links']): ?><td class="no-print text-end"><?php if (!empty($rel['links'][$i])): ?><a class="btn btn-sm btn-outline-primary py-0" href="<?= htmlspecialchars($rel['links'][$i]) ?>">Abrir</a><?php endif; ?></td><?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2"><?= count($rel['linhas']) ?> linha(s).</div>
        <?php endif; ?>
      </div></div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
