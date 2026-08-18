<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/status_repo.php';

require_login();
$u = auth_user();

$view = $_GET['view'] ?? (is_staff() ? 'kanban' : 'table'); // table|kanban

// ---- filtros (Guia 01, seção 7.2: unidade escolar, formador, etapa, prioridade) ----
$status  = trim($_GET['status'] ?? '');
$prof    = trim($_GET['prof'] ?? '');
$q       = trim($_GET['q'] ?? '');
$fPrio   = trim($_GET['prioridade'] ?? '');
$fNivel  = trim($_GET['nivel'] ?? '');
$fUnid   = trim($_GET['unidade'] ?? '');

// ---- ordenação (tabela) ----
$sort = $_GET['sort'] ?? 'updated_at';
$dir  = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$allowedSort = [
  'id_curso'       => 'c.id_curso',
  'nome_curso'     => 'c.nome_curso',
  'status_atual'   => 'c.status_atual',
  'carga_horaria'  => 'c.carga_horaria',
  'prioridade'     => 'c.prioridade',
  'professor_nome' => 'u.nome',
  'updated_at'     => 'c.updated_at',
];
$sortSql = $allowedSort[$sort] ?? 'c.updated_at';

$statuses = status_names();
$columns  = kanban_columns();

// monta WHERE
$params = [];
$where  = [];

if (!is_staff()) {
  $where[]  = "c.id_professor = ?";
  $params[] = $u['id_user'];
} else {
  if ($status !== '') { $where[] = "c.status_atual = ?"; $params[] = $status; }
  if ($prof !== '')   { $where[] = "u.nome LIKE ?";      $params[] = "%{$prof}%"; }
  if ($fUnid !== '')  { $where[] = "c.unidade_escolar LIKE ?"; $params[] = "%{$fUnid}%"; }
}

if ($fPrio !== '' && in_array($fPrio, prioridades(), true)) {
  $where[] = "c.prioridade = ?"; $params[] = $fPrio;
}
if ($fNivel !== '') {
  $where[] = "c.nivel_ensino = ?"; $params[] = $fNivel;
}
if ($q !== '') {
  $where[]  = "(c.nome_curso LIKE ? OR c.publico_alvo LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}

$sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// ------------------------------------------------------------------
// TABELA: paginação
// ------------------------------------------------------------------
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['perPage'] ?? 20);
$perPage = min(50, max(10, $perPage));

$stCount = db()->prepare("
  SELECT COUNT(*) AS total
  FROM tb_cursos c
  JOIN tb_users u ON u.id_user = c.id_professor
  $sqlWhere
");
$stCount->execute($params);
$total = (int)($stCount->fetch()['total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));

if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$st = db()->prepare("
  SELECT c.id_curso, c.nome_curso, c.carga_horaria, c.publico_alvo, c.status_atual, c.prioridade,
         c.nivel_ensino, c.unidade_escolar,
         c.data_prevista_inicio, c.data_prevista_entrega_final, c.updated_at,
         u.nome AS professor_nome
  FROM tb_cursos c
  JOIN tb_users u ON u.id_user = c.id_professor
  $sqlWhere
  ORDER BY $sortSql $dir
  LIMIT $perPage OFFSET $offset
");
$st->execute($params);
$cursos = $st->fetchAll();

// ------------------------------------------------------------------
// KANBAN (TI/MB/ADMIN): cards por status + pendências
// ------------------------------------------------------------------
$kanbanData = [];
$canDrag = perm('move_kanban');

if ($view === 'kanban' && is_staff()) {

  $stK = db()->prepare("
    SELECT c.id_curso, c.nome_curso, c.carga_horaria, c.publico_alvo, c.status_atual, c.prioridade,
           c.data_prevista_entrega_final, c.updated_at,
           u.nome AS professor_nome
    FROM tb_cursos c
    JOIN tb_users u ON u.id_user = c.id_professor
    $sqlWhere
    ORDER BY FIELD(c.prioridade,'URGENTE','ALTA','MEDIA','BAIXA'), c.updated_at DESC
    LIMIT 400
  ");
  $stK->execute($params);
  $rows = $stK->fetchAll();

  $ids = array_map(fn($r) => (int)$r['id_curso'], $rows);

  // pendências (apontamentos não resolvidos) por curso
  $pendByCurso = [];
  if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stp = db()->prepare("
      SELECT id_curso, COUNT(*) AS pend
      FROM tb_curso_apontamentos
      WHERE resolvido=0 AND id_curso IN ($in)
      GROUP BY id_curso
    ");
    $stp->execute($ids);
    foreach ($stp->fetchAll() as $p) {
      $pendByCurso[(int)$p['id_curso']] = (int)$p['pend'];
    }
  }

  foreach ($rows as &$r) {
    $r['pend'] = $pendByCurso[(int)$r['id_curso']] ?? 0;
  }
  unset($r);

  $byStatus = [];
  foreach ($rows as $r) {
    $byStatus[$r['status_atual']][] = $r;
  }

  foreach ($columns as $col) {
    $items = [];
    foreach ($col['statuses'] as $s) {
      foreach (($byStatus[$s['nome']] ?? []) as $it) $items[] = $it;
    }
    $kanbanData[$col['nome']] = $items;
  }
}

function buildQuery(array $extra = []): string {
  $q = array_merge($_GET, $extra);
  return http_build_query($q);
}

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Dashboard</h1>
    <div class="text-muted small">
      <?= (!is_staff()) ? "Seus cursos" : "Visão de gestão (Kanban + Tabela)" ?>
    </div>
  </div>

  <div class="d-flex gap-2">
    <?php if (!is_staff()): ?>
      <a class="btn btn-success" href="curso_novo.php">+ Propor Novo Curso</a>
    <?php else: ?>
      <a class="btn btn-outline-primary <?= $view==='kanban'?'active':'' ?>" href="?<?= buildQuery(['view'=>'kanban','page'=>1]) ?>">Kanban</a>
      <a class="btn btn-outline-primary <?= $view==='table'?'active':'' ?>" href="?<?= buildQuery(['view'=>'table','page'=>1]) ?>">Tabela</a>
      <a class="btn btn-outline-success" href="export_cursos.php" title="Exportar todos os cursos em CSV">CSV</a>
    <?php endif; ?>
  </div>
</div>

<?php if (is_staff()): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <div class="col-12 col-md-3">
          <label class="form-label small">Status</label>
          <select class="form-select form-select-sm" name="status">
            <option value="">Todos</option>
            <?php foreach ($statuses as $s): ?>
              <option value="<?= htmlspecialchars($s) ?>" <?= $status===$s ? 'selected':'' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label small">Formador(a)</label>
          <input class="form-control form-control-sm" name="prof" value="<?= htmlspecialchars($prof) ?>" placeholder="Nome do professor">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small">Prioridade</label>
          <select class="form-select form-select-sm" name="prioridade">
            <option value="">Todas</option>
            <?php foreach (prioridades() as $p): ?>
              <option value="<?= $p ?>" <?= $fPrio===$p ? 'selected':'' ?>><?= prioridade_badge($p)['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small">Nível de ensino</label>
          <select class="form-select form-select-sm" name="nivel">
            <option value="">Todos</option>
            <?php foreach (niveis_ensino() as $n): ?>
              <option value="<?= htmlspecialchars($n) ?>" <?= $fNivel===$n ? 'selected':'' ?>><?= htmlspecialchars($n) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-2">
          <label class="form-label small">Unidade escolar</label>
          <input class="form-control form-control-sm" name="unidade" value="<?= htmlspecialchars($fUnid) ?>" placeholder="Unidade">
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label small">Busca</label>
          <input class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Curso ou público-alvo...">
        </div>

        <div class="col-12 col-md-3 d-flex align-items-end gap-2">
          <button class="btn btn-primary btn-sm w-100">Filtrar</button>
          <a class="btn btn-outline-secondary btn-sm" href="dashboard.php?view=<?= htmlspecialchars($view) ?>">Limpar</a>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($view === 'kanban' && is_staff()): ?>

<div class="card shadow-sm mb-3">
  <div class="card-body d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div>
      <div class="fw-semibold">Visão Kanban</div>
      <div class="small text-muted">
        <?= $canDrag ? 'Arraste cards entre colunas para mover status (com confirmação).' : 'Visualização — movimentações são feitas pelo perfil TI/ADMIN.' ?>
        <?php if (is_admin()): ?>• <a href="admin/kanban.php">Configurar colunas</a><?php endif; ?>
      </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <input id="kanbanSearch" class="form-control" style="width:280px" placeholder="Buscar (curso/professor)">
      <span class="badge bg-secondary">Limite 400</span>
    </div>
  </div>
</div>

<div class="kanban-wrap">
  <div class="kanban-board">
    <?php foreach ($columns as $col):
      $items = $kanbanData[$col['nome']] ?? [];
      $wip = $col['wip_limit'] !== null ? (int)$col['wip_limit'] : null;
      $headerStyle = 'background-color:' . htmlspecialchars($col['cor']) . ';color:' . contrast_color($col['cor']) . ';';
      $statusListJson = json_encode(array_map(fn($s) => $s['nome'], $col['statuses']));
    ?>
      <div class="kanban-col">
        <div class="kanban-col-header p-3" style="<?= $headerStyle ?>">
          <div class="d-flex justify-content-between align-items-center">
            <strong><?= htmlspecialchars($col['nome']) ?></strong>
            <span class="badge bg-secondary <?= ($wip !== null && count($items) > $wip) ? 'wip-exceeded' : '' ?>">
              <?= count($items) ?><?= $wip !== null ? ' / ' . $wip : '' ?>
            </span>
          </div>
          <div class="small mt-1" style="opacity:.75">
            <?= htmlspecialchars(implode(' • ', array_map(fn($s) => $s['nome'], $col['statuses']))) ?>
          </div>
        </div>

        <div class="kanban-col-body" data-statuslist="<?= htmlspecialchars($statusListJson) ?>">
          <?php if ($wip !== null && count($items) > $wip): ?>
            <div class="alert alert-danger py-1 px-2 small mb-2">Limite WIP excedido (<?= count($items) ?>/<?= $wip ?>)</div>
          <?php endif; ?>

          <?php if (!$items): ?>
            <div class="text-muted small">Sem itens</div>
          <?php else: ?>
            <?php foreach ($items as $c): $pb = prioridade_badge($c['prioridade'] ?? 'MEDIA'); $pf = prazo_flag($c['data_prevista_entrega_final'], $c['status_atual']); ?>
              <div class="kanban-card p-3 mb-2"
                   draggable="<?= $canDrag ? 'true' : 'false' ?>"
                   data-id="<?= (int)$c['id_curso'] ?>"
                   data-status="<?= htmlspecialchars($c['status_atual']) ?>"
                   data-title="<?= htmlspecialchars($c['nome_curso']) ?>"
                   data-prof="<?= htmlspecialchars($c['professor_nome']) ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div class="fw-semibold" style="line-height:1.2;">
                    <?= htmlspecialchars($c['nome_curso']) ?>
                  </div>
                  <span class="chip"><b><?= htmlspecialchars($c['carga_horaria']) ?> horas</b></span>
                </div>

                <div class="small text-muted mt-1"><?= htmlspecialchars($c['professor_nome']) ?></div>

                <div class="d-flex flex-wrap gap-2 mt-2">
                  <span class="badge rounded-pill" style="<?= status_badge_style($c['status_atual']) ?>">
                    <?= htmlspecialchars($c['status_atual']) ?>
                  </span>
                  <span class="badge rounded-pill" style="<?= $pb['style'] ?>"><?= $pb['label'] ?></span>

                  <?php if ($pf === 'atrasado'): ?>
                    <span class="chip border-danger text-danger">⚠ Atrasado</span>
                  <?php elseif ($pf === 'proximo'): ?>
                    <span class="chip border-warning" style="color:#b8860b">Prazo próximo</span>
                  <?php endif; ?>

                  <?php if (!empty($c['pend'])): ?>
                    <span class="chip border-danger text-danger">Pendências: <b><?= (int)$c['pend'] ?></b></span>
                  <?php endif; ?>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                  <small class="text-muted"><?= htmlspecialchars($c['updated_at']) ?></small>
                  <a class="btn btn-sm btn-outline-primary" href="curso_detalhe.php?id=<?= (int)$c['id_curso'] ?>">Abrir</a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modal confirmação de movimentação -->
<div class="modal fade" id="moveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="moveForm">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar movimentação</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="small text-muted mb-2">Você está prestes a alterar o status do curso:</div>
        <div class="fw-semibold" id="mmTitle"></div>
        <hr>
        <div class="small mb-2"><b>De:</b> <span id="mmFrom"></span></div>
        <div class="mb-2">
          <label class="form-label small"><b>Para o status:</b></label>
          <select class="form-select" name="to" id="mmToSel" required></select>
        </div>
        <div class="mt-3 d-none" id="mmDataPubWrap">
          <label class="form-label small fw-semibold">Data de entrada na plataforma (obrigatória)</label>
          <input class="form-control" type="date" name="data_publicacao" id="mmDataPub">
          <div class="form-text">Comunicada ao formador e à MB Estúdios.</div>
        </div>
        <div class="mt-3">
          <label class="form-label small">Observação (opcional)</label>
          <input class="form-control" name="obs" id="mmObs" placeholder="Ex.: Movido após envio do autor.">
        </div>
        <input type="hidden" name="id_curso" id="mmId">
        <input type="hidden" name="csrf_token" value="<?php require_once __DIR__ . '/../app/csrf.php'; echo htmlspecialchars(csrf_token()); ?>">
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Confirmar</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Busca rápida no Kanban
  const search = document.getElementById('kanbanSearch');
  if (search) {
    search.addEventListener('input', () => {
      const q = (search.value || '').toLowerCase().trim();
      document.querySelectorAll('.kanban-card').forEach(card => {
        const title = (card.dataset.title || '').toLowerCase();
        const prof  = (card.dataset.prof || '').toLowerCase();
        card.style.display = (!q || title.includes(q) || prof.includes(q)) ? '' : 'none';
      });
    });
  }

  // Drag & Drop (TI/ADMIN)
  const canDrag = <?= json_encode($canDrag) ?>;
  let dragged = null;

  if (canDrag) {
    document.querySelectorAll('.kanban-card[draggable="true"]').forEach(card => {
      card.addEventListener('dragstart', (e) => {
        dragged = card;
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
      });
      card.addEventListener('dragend', () => {
        if (dragged) dragged.classList.remove('dragging');
        dragged = null;
        document.querySelectorAll('.kanban-col-body').forEach(z => z.classList.remove('dropzone'));
      });
    });

    document.querySelectorAll('.kanban-col-body').forEach(col => {
      col.addEventListener('dragover', (e) => {
        e.preventDefault();
        col.classList.add('dropzone');
      });

      col.addEventListener('dragleave', () => col.classList.remove('dropzone'));

      col.addEventListener('drop', (e) => {
        e.preventDefault();
        col.classList.remove('dropzone');
        if (!dragged) return;

        let list = [];
        try { list = JSON.parse(col.dataset.statuslist || '[]'); } catch(err) {}
        if (!list.length) return;

        const sel = document.getElementById('mmToSel');
        sel.innerHTML = '';
        list.forEach(s => {
          const o = document.createElement('option');
          o.value = s; o.textContent = s;
          sel.appendChild(o);
        });

        // data de publicação obrigatória ao mover para "Pronto para Publicação"
        const dataWrap = document.getElementById('mmDataPubWrap');
        const dataInp = document.getElementById('mmDataPub');
        const toggleDataPub = () => {
          const precisa = sel.value === 'Pronto para Publicação';
          dataWrap.classList.toggle('d-none', !precisa);
          dataInp.required = precisa;
          if (!precisa) dataInp.value = '';
        };
        sel.onchange = toggleDataPub;
        toggleDataPub();

        const mm = new bootstrap.Modal(document.getElementById('moveModal'));
        document.getElementById('mmTitle').textContent = dragged.dataset.title;
        document.getElementById('mmFrom').textContent  = dragged.dataset.status;
        document.getElementById('mmId').value          = dragged.dataset.id;
        document.getElementById('mmObs').value         = '';
        mm.show();
      });
    });

    const form = document.getElementById('moveForm');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);

      const res = await fetch('move_status.php', { method:'POST', body: fd });
      let data = {};
      try { data = await res.json(); } catch(err) {}

      if (!res.ok || !data.ok) {
        if (typeof Swal !== 'undefined') {
          Swal.fire({ icon: 'error', title: 'Não foi possível mover', text: data.error || 'Falha ao mover status.', confirmButtonColor: '#058285' });
        } else {
          alert(data.error || 'Falha ao mover status.');
        }
        return;
      }
      location.reload();
    });
  }
</script>

<?php else: ?>

  <!-- TABELA (com paginação e ordenação) -->
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-striped mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <?php
                $headers = [
                  'id_curso'       => '#',
                  'nome_curso'     => 'Curso',
                  'carga_horaria'  => 'Carga Horária',
                  'prioridade'     => 'Prioridade',
                  'status_atual'   => 'Status',
                  'professor_nome' => 'Formador(a)',
                  'updated_at'     => 'Atualizado',
                ];

                foreach ($headers as $k => $label):
                  if (!is_staff() && $k === 'professor_nome') continue;

                  $newDir = ($sort === $k && $dir === 'asc') ? 'desc' : 'asc';
              ?>
                <th class="text-nowrap">
                  <a class="text-decoration-none" href="?<?= buildQuery(['sort'=>$k,'dir'=>$newDir,'page'=>1]) ?>">
                    <?= htmlspecialchars($label) ?>
                    <?php if ($sort === $k): ?><?= ($dir === 'asc') ? '▲' : '▼' ?><?php endif; ?>
                  </a>
                </th>
              <?php endforeach; ?>
              <th class="text-nowrap text-end">Ações</th>
            </tr>
          </thead>

          <tbody>
            <?php if (empty($cursos)): ?>
              <tr>
                <td colspan="8" class="text-center p-4 text-muted">
                  Nenhum curso encontrado.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($cursos as $c): $pb = prioridade_badge($c['prioridade'] ?? 'MEDIA'); $pf = prazo_flag($c['data_prevista_entrega_final'], $c['status_atual']); ?>
                <tr>
                  <td><?= (int)$c['id_curso'] ?></td>

                  <td class="fw-semibold">
                    <?= htmlspecialchars($c['nome_curso']) ?>
                    <?php if ($pf === 'atrasado'): ?>
                      <span class="badge bg-danger ms-1">Atrasado</span>
                    <?php elseif ($pf === 'proximo'): ?>
                      <span class="badge bg-warning text-dark ms-1">Prazo próximo</span>
                    <?php endif; ?>
                    <br>
                    <small class="text-muted">
                      Público: <?= htmlspecialchars($c['publico_alvo']) ?>
                      <?php if (!empty($c['nivel_ensino'])): ?> • <?= htmlspecialchars($c['nivel_ensino']) ?><?php endif; ?>
                    </small>
                  </td>

                  <td class="text-nowrap"><?= htmlspecialchars($c['carga_horaria']) ?> horas</td>

                  <td class="text-nowrap">
                    <span class="badge rounded-pill" style="<?= $pb['style'] ?>"><?= $pb['label'] ?></span>
                  </td>

                  <td class="text-nowrap">
                    <span class="badge" style="<?= status_badge_style($c['status_atual']) ?>">
                      <?= htmlspecialchars($c['status_atual']) ?>
                    </span>
                  </td>

                  <?php if ($u['role'] !== 'PROFESSOR'): ?>
                    <td><?= htmlspecialchars($c['professor_nome']) ?></td>
                  <?php endif; ?>

                  <td class="text-nowrap"><?= htmlspecialchars($c['updated_at']) ?></td>

                  <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-primary"
                       href="curso_detalhe.php?id=<?= (int)$c['id_curso'] ?>">
                      Abrir
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginação -->
      <div class="d-flex flex-wrap justify-content-between align-items-center p-3">
        <div class="small text-muted">
          Total: <?= (int)$total ?> • Página <?= (int)$page ?> de <?= (int)$totalPages ?>
        </div>

        <div class="d-flex gap-2 align-items-center">
          <form class="d-flex gap-2" method="get">
            <?php foreach ($_GET as $k => $v): if (in_array($k, ['perPage','page'], true) || is_array($v)) continue; ?>
              <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
            <?php endforeach; ?>

            <select class="form-select form-select-sm" name="perPage" onchange="this.form.submit()">
              <?php foreach ([10,20,30,50] as $n): ?>
                <option value="<?= $n ?>" <?= ((int)$perPage === $n) ? 'selected' : '' ?>><?= $n ?>/página</option>
              <?php endforeach; ?>
            </select>
          </form>

          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= buildQuery(['page'=>$page-1]) ?>">Anterior</a>
              </li>

              <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= buildQuery(['page'=>$page+1]) ?>">Próxima</a>
              </li>
            </ul>
          </nav>
        </div>
      </div>

    </div>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
