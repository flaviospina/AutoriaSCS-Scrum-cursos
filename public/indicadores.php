<?php
/**
 * Painel de indicadores gerenciais (V14): KPIs agrupados, gráficos e, em cada
 * indicador, o link para o relatório correspondente (relatorios.php?r=...).
 * Acesso: equipe de gestão (ve_todos_cursos) — TI, MB e ADMIN.
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/indicadores_repo.php';

require_login();
$u = auth_user();
if (!is_staff()) { http_response_code(403); exit('Acesso restrito à equipe de gestão (TI/MB/ADMIN).'); }

$grupos = ind_todos();
$graf   = ind_graficos();
$totalInd = 0; foreach ($grupos as $g) $totalInd += count($g['itens']);

include __DIR__ . '/_layout_top.php';
?>
<style>
  .kpi { border-left: 4px solid var(--kpi-cor, var(--autoria-teal, #0aa5c8)); }
  .kpi .valor { font-size: 1.45rem; font-weight: 700; line-height: 1.1; color: var(--kpi-cor, inherit); }
  .kpi .titulo { font-size: .82rem; }
  .kpi .desc { font-size: .72rem; opacity: .75; }
  .kpi .link-rel { font-size: .75rem; white-space: nowrap; }
  .grupo-titulo { display:flex; align-items:center; gap:.5rem; margin: 1.4rem 0 .6rem; }
  .grupo-titulo .badge { font-weight: 500; }
  .grafico-box { position: relative; height: 240px; }
  @media print { .no-print, nav, footer, .visao-banner { display:none !important; } .card { break-inside: avoid; } body { background:#fff !important; } }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
  <div>
    <h1 class="h4 mb-0">Indicadores gerenciais</h1>
    <div class="text-muted small"><?= $totalInd ?> indicadores em <?= count($grupos) ?> grupos • dados em tempo real • gerado em <?= date('d/m/Y H:i') ?></div>
  </div>
  <div class="d-flex gap-2 no-print">
    <a class="btn btn-outline-primary" href="relatorios.php">📄 Relatórios</a>
    <button class="btn btn-outline-secondary" onclick="window.print()">🖨 Imprimir</button>
    <a class="btn btn-outline-secondary" href="dashboard.php">Voltar</a>
  </div>
</div>

<!-- Navegação entre grupos -->
<div class="d-flex flex-wrap gap-1 mb-2 no-print">
  <?php foreach ($grupos as $k => $g): ?>
    <a class="btn btn-sm btn-outline-secondary" href="#g-<?= $k ?>"><?= $g['icone'] ?> <?= htmlspecialchars($g['titulo']) ?></a>
  <?php endforeach; ?>
</div>

<!-- Gráficos -->
<div class="row g-3 mb-2">
  <div class="col-12 col-xl-6">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Produção mensal (12 meses)</h2>
        <a class="small" href="relatorios.php?r=producao_mensal">Ver relatório →</a>
      </div>
      <div class="grafico-box"><canvas id="gMensal"></canvas></div>
    </div></div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Cursos por etapa do Kanban</h2>
        <a class="small" href="relatorios.php?r=por_etapa">Ver relatório →</a>
      </div>
      <div class="grafico-box"><canvas id="gEtapas"></canvas></div>
    </div></div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Apontamentos por status</h2>
        <a class="small" href="relatorios.php?r=apontamentos_resumo">Relatório →</a>
      </div>
      <div class="grafico-box"><canvas id="gApont"></canvas></div>
    </div></div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Apontamentos por tipo</h2>
        <a class="small" href="relatorios.php?r=apontamentos_resumo">Relatório →</a>
      </div>
      <div class="grafico-box"><canvas id="gApontTipo"></canvas></div>
    </div></div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Cursos por carga horária</h2>
        <a class="small" href="relatorios.php?r=distribuicao&dim=carga_horaria">Relatório →</a>
      </div>
      <div class="grafico-box"><canvas id="gCarga"></canvas></div>
    </div></div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Entregas por categoria</h2>
        <a class="small" href="relatorios.php?r=entregas">Relatório →</a>
      </div>
      <div class="grafico-box"><canvas id="gEntregas"></canvas></div>
    </div></div>
  </div>
</div>

<!-- Grupos de indicadores -->
<?php foreach ($grupos as $k => $g): ?>
  <div class="grupo-titulo" id="g-<?= $k ?>">
    <h2 class="h5 mb-0"><?= $g['icone'] ?> <?= htmlspecialchars($g['titulo']) ?></h2>
    <span class="badge bg-secondary"><?= count($g['itens']) ?></span>
  </div>
  <div class="row g-2">
    <?php foreach ($g['itens'] as $chave => $it): [$titulo, $valor, $rel, $desc] = $it; $cor = $it[4] ?? null; ?>
      <div class="col-12 col-sm-6 col-lg-4 col-xxl-3">
        <div class="card shadow-sm h-100 kpi" <?= $cor ? 'style="--kpi-cor:' . htmlspecialchars($cor) . '"' : '' ?>>
          <div class="card-body py-2 px-3 d-flex flex-column">
            <div class="titulo text-muted"><?= htmlspecialchars($titulo) ?></div>
            <div class="valor my-1"><?= htmlspecialchars((string)$valor) ?></div>
            <div class="desc mb-1"><?= htmlspecialchars($desc) ?></div>
            <div class="mt-auto"><a class="link-rel no-print" href="relatorios.php?r=<?= $rel ?>" title="Abrir o relatório deste indicador">📄 Ver relatório →</a></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  var G = <?= json_encode($graf, JSON_UNESCAPED_UNICODE) ?>;
  var cols = <?= json_encode($grupos['fluxo']['por_coluna'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
  var fg = getComputedStyle(document.body).color || '#ccc';
  Chart.defaults.color = fg; Chart.defaults.borderColor = 'rgba(128,128,128,.25)'; Chart.defaults.font.family = 'Comfortaa, sans-serif';
  var paleta = ['#0aa5c8','#198754','#fd7e14','#dc3545','#6f42c1','#20c997','#ffc107','#0d6efd','#adb5bd','#e83e8c','#6610f2','#17a2b8'];
  function pie(id, obj, tipo) {
    var el = document.getElementById(id); if (!el) return;
    var labels = Object.keys(obj), data = labels.map(function (k) { return obj[k]; });
    if (!labels.length) { el.parentNode.innerHTML = '<div class="text-muted small">Sem dados ainda.</div>'; return; }
    new Chart(el, { type: tipo || 'doughnut', data: { labels: labels, datasets: [{ data: data, backgroundColor: paleta }] },
      options: { maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } } } } });
  }
  var m = document.getElementById('gMensal');
  if (m) new Chart(m, { type: 'bar', data: { labels: G.mensal.labels, datasets: [
      { label: 'Propostos', data: G.mensal.propostos, backgroundColor: '#0aa5c8' },
      { label: 'Publicados', data: G.mensal.publicados, backgroundColor: '#198754' } ] },
    options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
  var e = document.getElementById('gEtapas');
  if (e) new Chart(e, { type: 'bar', data: { labels: cols.map(function (c) { return c.nome; }), datasets: [{ label: 'Cursos', data: cols.map(function (c) { return c.n; }), backgroundColor: cols.map(function (c) { return c.cor || '#0aa5c8'; }) }] },
    options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } } });
  pie('gApont', G.apont_status); pie('gApontTipo', G.apont_tipo); pie('gCarga', G.carga, 'pie'); pie('gEntregas', G.entregas_cat);
})();
</script>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
