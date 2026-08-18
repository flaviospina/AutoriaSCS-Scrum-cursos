<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/status_repo.php';

require_login();
$u = auth_user();
if (!is_staff()) { http_response_code(403); exit('Acesso restrito à equipe de gestão (TI/MB/ADMIN).'); }

$columns = kanban_columns();

// cursos por status
$porStatus = [];
foreach (db()->query("SELECT status_atual, COUNT(*) n FROM tb_cursos GROUP BY status_atual")->fetchAll() as $r) {
  $porStatus[$r['status_atual']] = (int)$r['n'];
}
$totalCursos = array_sum($porStatus);

// cursos por coluna do kanban
$porColuna = [];
foreach ($columns as $c) {
  $n = 0;
  foreach ($c['statuses'] as $s) $n += $porStatus[$s['nome']] ?? 0;
  $porColuna[$c['nome']] = ['n' => $n, 'cor' => $c['cor']];
}

// por formador
$porProf = db()->query("
  SELECT u.nome, COUNT(*) n,
         SUM(CASE WHEN s.is_final=1 THEN 1 ELSE 0 END) AS concluidos
  FROM tb_cursos c
  JOIN tb_users u ON u.id_user=c.id_professor
  LEFT JOIN tb_status s ON s.nome = c.status_atual
  GROUP BY u.id_user, u.nome
  ORDER BY n DESC
  LIMIT 20
")->fetchAll();

// por nível de ensino
$porNivel = db()->query("
  SELECT COALESCE(NULLIF(nivel_ensino,''),'(não informado)') nivel, COUNT(*) n
  FROM tb_cursos GROUP BY nivel ORDER BY n DESC
")->fetchAll();

// por prioridade
$porPrio = [];
foreach (db()->query("SELECT prioridade, COUNT(*) n FROM tb_cursos GROUP BY prioridade")->fetchAll() as $r) {
  $porPrio[$r['prioridade'] ?: 'MEDIA'] = (int)$r['n'];
}

// atrasados / prazo próximo (status não-final com data de entrega)
$prazos = db()->query("
  SELECT c.id_curso, c.nome_curso, c.data_prevista_entrega_final, c.status_atual, c.prioridade, u.nome professor_nome
  FROM tb_cursos c
  JOIN tb_users u ON u.id_user=c.id_professor
  LEFT JOIN tb_status s ON s.nome = c.status_atual
  WHERE c.data_prevista_entrega_final IS NOT NULL
    AND COALESCE(s.is_final,0)=0
    AND c.data_prevista_entrega_final <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  ORDER BY c.data_prevista_entrega_final
  LIMIT 30
")->fetchAll();

// pendências abertas
$pendencias = db()->query("
  SELECT c.id_curso, c.nome_curso, COUNT(*) n
  FROM tb_curso_apontamentos a
  JOIN tb_cursos c ON c.id_curso=a.id_curso
  WHERE a.resolvido=0
  GROUP BY c.id_curso, c.nome_curso
  ORDER BY n DESC
  LIMIT 15
")->fetchAll();

// lead time médio (criação -> status final) em dias
$leadRow = db()->query("
  SELECT AVG(DATEDIFF(h.created_at, c.created_at)) media_dias, COUNT(DISTINCT c.id_curso) n
  FROM tb_cursos c
  JOIN tb_curso_status_history h ON h.id_curso=c.id_curso
  JOIN tb_status s ON s.nome = h.status_para AND s.is_final=1
")->fetch();
$leadMedio = $leadRow && $leadRow['media_dias'] !== null ? round((float)$leadRow['media_dias'], 1) : null;

// movimentações nos últimos 30 dias
$mov30 = (int)db()->query("
  SELECT COUNT(*) n FROM tb_curso_status_history WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch()['n'];

$concluidos = 0;
foreach ($columns as $c) {
  foreach ($c['statuses'] as $s) {
    if (!empty($s['is_final'])) $concluidos += $porStatus[$s['nome']] ?? 0;
  }
}

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Relatórios e Indicadores</h1>
    <div class="text-muted small">Painel de acompanhamento da produção de cursos (Guia 01, seção 7.2).</div>
  </div>
  <a class="btn btn-outline-secondary" href="dashboard.php">Voltar</a>
</div>

<!-- Cartões-resumo -->
<div class="row g-3 mb-3">
  <?php
    $atrasadosN = 0;
    foreach ($prazos as $p) { if ($p['data_prevista_entrega_final'] < date('Y-m-d')) $atrasadosN++; }
    $tiles = [
      ['Cursos cadastrados', $totalCursos, 'var(--autoria-teal)'],
      ['Concluídos / publicados', $concluidos, '#198754'],
      ['Com prazo crítico (7 dias)', count($prazos), '#fd7e14'],
      ['Atrasados', $atrasadosN, '#dc3545'],
      ['Movimentações (30 dias)', $mov30, '#6c757d'],
      ['Lead time médio', $leadMedio !== null ? $leadMedio . ' dias' : '—', '#0dcaf0'],
    ];
  ?>
  <?php foreach ($tiles as [$t, $v, $cor]): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card shadow-sm h-100">
        <div class="card-body py-3">
          <div class="fs-4 fw-bold" style="color:<?= $cor ?>"><?= $v ?></div>
          <div class="small text-muted"><?= $t ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <!-- Funil por coluna -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Cursos por etapa do fluxo</h2>
        <?php foreach ($porColuna as $nome => $d): $pct = $totalCursos ? round($d['n'] * 100 / $totalCursos) : 0; ?>
          <div class="d-flex justify-content-between small mb-1">
            <span><?= htmlspecialchars($nome) ?></span>
            <span class="text-muted"><?= $d['n'] ?> (<?= $pct ?>%)</span>
          </div>
          <div class="progress mb-3" style="height:10px">
            <div class="progress-bar" style="width:<?= max($pct, $d['n'] > 0 ? 3 : 0) ?>%;background-color:<?= htmlspecialchars($d['cor']) ?>"></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Por formador -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Cursos por formador(a)</h2>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle mb-0">
            <thead class="table-light"><tr><th>Formador(a)</th><th>Total</th><th>Concluídos</th></tr></thead>
            <tbody>
              <?php foreach ($porProf as $p): ?>
                <tr>
                  <td><?= htmlspecialchars($p['nome']) ?></td>
                  <td><?= (int)$p['n'] ?></td>
                  <td><?= (int)$p['concluidos'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Por nível / prioridade -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Por nível de ensino</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-3">
            <tbody>
              <?php foreach ($porNivel as $n): ?>
                <tr><td><?= htmlspecialchars($n['nivel']) ?></td><td class="text-end"><b><?= (int)$n['n'] ?></b></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <h2 class="h6 mb-2">Por prioridade</h2>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach (prioridades() as $p): $pb = prioridade_badge($p); ?>
            <span class="badge rounded-pill" style="<?= $pb['style'] ?>"><?= $pb['label'] ?>: <?= $porPrio[$p] ?? 0 ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Pendências -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Cursos com pendências abertas (apontamentos)</h2>
        <?php if (!$pendencias): ?>
          <div class="text-muted small">Nenhuma pendência aberta. 🎉</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light"><tr><th>Curso</th><th>Pendências</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($pendencias as $p): ?>
                  <tr>
                    <td><?= htmlspecialchars($p['nome_curso']) ?></td>
                    <td><span class="badge bg-danger rounded-pill"><?= (int)$p['n'] ?></span></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="apontamentos.php?id=<?= (int)$p['id_curso'] ?>">Abrir</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Prazos críticos -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Prazos críticos (vencidos ou nos próximos 7 dias)</h2>
        <?php if (!$prazos): ?>
          <div class="text-muted small">Nenhum curso com prazo crítico.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr><th>Entrega prevista</th><th>Curso</th><th>Formador(a)</th><th>Status</th><th>Prioridade</th><th>Situação</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($prazos as $p): $pb = prioridade_badge($p['prioridade'] ?? 'MEDIA'); $venc = $p['data_prevista_entrega_final'] < date('Y-m-d'); ?>
                  <tr>
                    <td class="text-nowrap"><?= htmlspecialchars($p['data_prevista_entrega_final']) ?></td>
                    <td><?= htmlspecialchars($p['nome_curso']) ?></td>
                    <td><?= htmlspecialchars($p['professor_nome']) ?></td>
                    <td><span class="badge" style="<?= status_badge_style($p['status_atual']) ?>"><?= htmlspecialchars($p['status_atual']) ?></span></td>
                    <td><span class="badge rounded-pill" style="<?= $pb['style'] ?>"><?= $pb['label'] ?></span></td>
                    <td><?= $venc ? '<span class="badge bg-danger">Atrasado</span>' : '<span class="badge bg-warning text-dark">Vence em breve</span>' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="curso_detalhe.php?id=<?= (int)$p['id_curso'] ?>">Abrir</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
