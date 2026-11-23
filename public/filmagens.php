<?php
/**
 * V19 — Filmagens (Estúdio MB)
 * Reservas de estúdio lidas do Sistema de Reservas + registro da filmagem pela MB.
 * Acesso: equipe (TI/MB/ADMIN). Professores veem as próprias reservas na página do curso.
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/filmagens_repo.php';
require_once __DIR__ . '/../app/csrf.php';

require_login();
$u = auth_user();
if (!is_staff()) { http_response_code(403); exit('Acesso restrito à equipe (TI/MB/ADMIN).'); }

$erro = null; $ok = null;
$podeRegistrar = filmagem_pode_registrar();
$podeVincular  = filmagem_pode_vincular();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    $qs = $_POST['qs'] ?? '';
    switch ($_POST['action'] ?? '') {
      case 'sync':
        if (!$podeVincular) throw new Exception('Somente TI/ADMIN sincroniza.');
        $r = filmagens_sincronizar(true);
        if (!$r['ok']) throw new Exception($r['erro']);
        $ok = "Reservas sincronizadas: {$r['novas']} nova(s), {$r['atualizadas']} atualizada(s)" . ($r['removidas'] ? ", {$r['removidas']} removida(s) na origem" : '') . '.';
        break;
      case 'registrar':
        filmagem_registrar((int)$_POST['id_filmagem'], (string)$_POST['situacao'], $_POST['filmada_em'] ?? null, $_POST['obs'] ?? null, auth_user_real());
        $ok = 'Filmagem registrada.';
        break;
      case 'vincular':
        filmagem_vincular((int)$_POST['id_filmagem'], (int)($_POST['id_curso'] ?? 0) ?: null, ($_POST['modulo'] ?? '') === '' ? null : (int)$_POST['modulo'], $u);
        $ok = 'Reserva vinculada ao curso.';
        break;
      case 'avulsa':
        filmagem_avulsa((int)($_POST['id_curso'] ?? 0), ($_POST['modulo'] ?? '') === '' ? null : (int)$_POST['modulo'], (string)($_POST['filmada_em'] ?? ''), $_POST['hora'] ?? null, $_POST['sala'] ?? null, $_POST['obs'] ?? null, auth_user_real());
        $ok = 'Filmagem avulsa registrada.';
        break;
      default: throw new Exception('Ação inválida.');
    }
    header('Location: filmagens.php?' . ($qs ? $qs . '&' : '') . 'ok=' . urlencode($ok)); exit;
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}
if (!empty($_GET['ok'])) $ok = mb_substr((string)$_GET['ok'], 0, 200);

// sincronização automática (no máximo a cada 10 min)
$sync = filmagens_tabela() ? filmagens_sincronizar(false) : ['ok' => false, 'erro' => 'Tabela tb_filmagens ausente: rode database/upgrade_v19.sql no phpMyAdmin.'];

$f = [];
foreach (['de', 'ate', 'sala', 'estado', 'filmagem', 'q', 'id_professor'] as $k) { $v = trim((string)($_GET[$k] ?? '')); if ($v !== '') $f[$k] = $v; }
if (!empty($_GET['sem_curso'])) $f['sem_curso'] = 1;
foreach (['de', 'ate'] as $k) if (isset($f[$k]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) unset($f[$k]);
$qs = http_build_query(array_intersect_key($_GET, array_flip(['de', 'ate', 'sala', 'estado', 'filmagem', 'q', 'id_professor', 'sem_curso'])));

$linhas = filmagens_listar($f);
$met = filmagens_metricas($f);
$cursosOpc = db()->query("SELECT c.id_curso, c.nome_curso, u.nome prof FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor" . (function_exists('admin_v18') && admin_v18() ? " WHERE c.situacao <> 'EXCLUIDO'" : '') . " ORDER BY c.nome_curso")->fetchAll();

// CSV
if (($_GET['csv'] ?? '') === '1') {
  header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="filmagens_' . date('Ymd') . '.csv"');
  echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w');
  fputcsv($o, ['Reserva', 'Data', 'Hora', 'Sala', 'Professor', 'Curso (reserva)', 'Curso (AutoriaSCS)', 'Estado da reserva', 'Filmagem', 'Filmada em', 'Dias reserva→filmagem', 'Vídeo entregue em', 'Dias filmagem→vídeo', 'Observação'], ';');
  foreach ($linhas as $l) fputcsv($o, [$l['id_reserva'] ?: 'avulsa', $l['data_reserva'], $l['hora_reserva'] ? substr($l['hora_reserva'], 0, 5) : '', $l['sala'], $l['professor_nome'], $l['curso_nome'], $l['nome_curso'], $l['estado_reserva'], FILMAGEM_SITUACOES[$l['filmagem']]['label'], $l['filmada_em'], $l['dias_reserva_filmagem'], $l['video_em'] ? substr($l['video_em'], 0, 10) : '', $l['dias_filmagem_video'], $l['filmagem_obs']], ';');
  fclose($o); exit;
}

function fdias($d): string { return $d === null ? '—' : (int)$d . ' d'; }
function fmed($d): string { return $d === null ? '—' : number_format($d, 1, ',', '.') . ' d'; }
$hoje = date('Y-m-d');
include __DIR__ . '/_layout_top.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">🎥 Filmagens (Estúdio MB)</h1>
    <div class="text-muted small">Reservas de estúdio do Sistema de Reservas e registro da filmagem realizada pela MB. Serve para medir o tempo da reserva até a filmagem e da filmagem até a entrega do vídeo.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-secondary btn-sm" href="relatorios.php?r=filmagens_atraso">📊 Relatório de atraso</a>
    <a class="btn btn-outline-success btn-sm" href="filmagens.php?<?= htmlspecialchars($qs) ?>&csv=1">CSV</a>
    <?php if ($podeVincular): ?>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><input type="hidden" name="qs" value="<?= htmlspecialchars($qs) ?>"><button class="btn btn-outline-primary btn-sm">🔄 Sincronizar reservas</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
<?php if (!$sync['ok'] && empty($sync['pulou'])): ?>
  <div class="alert alert-warning alert-fixo"><b>Integração com o Sistema de Reservas indisponível.</b> <?= htmlspecialchars((string)$sync['erro']) ?><br><span class="small text-muted">As reservas já copiadas continuam visíveis; os registros de filmagem funcionam normalmente.</span></div>
<?php endif; ?>

<div class="row g-2 mb-3">
  <?php foreach ([
    ['Reservas no filtro', $met['reservas'], ''],
    ['Aguardando filmagem (data já passou)', $met['aguardando'], $met['aguardando'] ? 'text-warning' : ''],
    ['Filmagens realizadas', $met['realizadas'], 'text-success'],
    ['Sem vínculo com curso', $met['sem_curso'], $met['sem_curso'] ? 'text-warning' : ''],
    ['Média reserva → filmagem', fmed($met['media_reserva_filmagem']), ''],
    ['Média filmagem → vídeo entregue', fmed($met['media_filmagem_video']), ''],
    ['Vídeos pendentes após filmagem', $met['videos_pendentes'] . ($met['pendentes_7d'] ? ' (' . $met['pendentes_7d'] . ' há +7 d)' : ''), $met['pendentes_7d'] ? 'text-danger' : ''],
    ['Maior atraso filmagem → vídeo', fdias($met['max_filmagem_video']), ''],
  ] as [$t, $v, $cls]): ?>
    <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body py-2"><div class="small text-muted"><?= $t ?></div><div class="h5 mb-0 <?= $cls ?>"><?= $v ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<div class="card shadow-sm mb-3"><div class="card-body">
  <form class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">De (data da reserva)</label><input type="date" class="form-control form-control-sm" name="de" value="<?= htmlspecialchars($f['de'] ?? '') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Até</label><input type="date" class="form-control form-control-sm" name="ate" value="<?= htmlspecialchars($f['ate'] ?? '') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Sala</label><select class="form-select form-select-sm" name="sala"><option value="">Todas</option><?php foreach (filmagens_salas() as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= ($f['sala'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Reserva</label><select class="form-select form-select-sm" name="estado"><option value="">Todas</option><?php foreach (['Pendente', 'Aceita', 'Recusada'] as $s): ?><option value="<?= $s ?>" <?= ($f['estado'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Filmagem</label><select class="form-select form-select-sm" name="filmagem"><option value="">Todas</option><?php foreach (FILMAGEM_SITUACOES as $k => $d): ?><option value="<?= $k ?>" <?= ($f['filmagem'] ?? '') === $k ? 'selected' : '' ?>><?= $d['label'] ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Busca (curso/professor)</label><input class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($f['q'] ?? '') ?>"></div>
    <div class="col-12 d-flex gap-2 align-items-center">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="sem_curso" value="1" id="semCurso" <?= !empty($f['sem_curso']) ? 'checked' : '' ?>><label class="form-check-label small" for="semCurso">Só reservas sem curso vinculado</label></div>
      <button class="btn btn-primary btn-sm">Filtrar</button><a class="btn btn-outline-secondary btn-sm" href="filmagens.php">Limpar</a>
    </div>
  </form>
</div></div>

<?php if ($podeRegistrar): ?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 mb-2">➕ Filmagem avulsa (sem reserva no sistema)</h2>
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="action" value="avulsa"><input type="hidden" name="qs" value="<?= htmlspecialchars($qs) ?>">
    <div class="col-12 col-md-4"><label class="form-label small mb-0">Curso</label><select class="form-select form-select-sm" name="id_curso" required><option value="">Selecione…</option><?php foreach ($cursosOpc as $c): ?><option value="<?= (int)$c['id_curso'] ?>"><?= htmlspecialchars($c['nome_curso'] . ' — ' . $c['prof']) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-1"><label class="form-label small mb-0">Módulo</label><select class="form-select form-select-sm" name="modulo"><option value="">—</option><?php for ($i = 1; $i <= 8; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select></div>
    <div class="col-4 col-md-2"><label class="form-label small mb-0">Filmada em</label><input type="date" class="form-control form-control-sm" name="filmada_em" max="<?= $hoje ?>" required></div>
    <div class="col-4 col-md-1"><label class="form-label small mb-0">Hora</label><input type="time" class="form-control form-control-sm" name="hora"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Sala</label><input class="form-control form-control-sm" name="sala" maxlength="150"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Observação</label><input class="form-control form-control-sm" name="obs" maxlength="500"></div>
    <div class="col-12"><button class="btn btn-success btn-sm">Registrar filmagem avulsa</button></div>
  </form>
</div></div>
<?php endif; ?>

<div class="card shadow-sm"><div class="card-body">
  <?php if (!$linhas): ?>
    <p class="text-muted mb-0">Nenhuma reserva encontrada<?= $f ? ' com esses filtros' : '' ?>.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Data / hora</th><th>Sala</th><th>Professor(a)</th><th>Curso (reserva)</th><th>Curso no AutoriaSCS</th><th>Reserva</th><th>Filmagem</th><th>Vídeo entregue</th><th class="text-end">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($linhas as $l): $id = (int)$l['id_filmagem']; $atrasada = $l['filmagem'] === 'PENDENTE' && $l['estado_reserva'] !== 'Recusada' && $l['data_reserva'] < $hoje; ?>
        <tr class="<?= $atrasada ? 'table-warning' : '' ?>">
          <td class="text-nowrap"><?= date('d/m/Y', strtotime($l['data_reserva'])) ?><?= $l['hora_reserva'] ? ' ' . substr($l['hora_reserva'], 0, 5) : '' ?><?php if (!$l['id_reserva']): ?><br><span class="badge bg-info text-dark">avulsa</span><?php endif; ?><?php if ($l['removida_na_origem']): ?><br><span class="badge bg-secondary" title="A reserva foi apagada no Sistema de Reservas">apagada na origem</span><?php endif; ?></td>
          <td class="small"><?= htmlspecialchars((string)$l['sala']) ?></td>
          <td class="small"><?= htmlspecialchars((string)$l['professor_nome']) ?><?php if ($l['professor_email']): ?><br><span class="text-muted"><?= htmlspecialchars($l['professor_email']) ?></span><?php endif; ?></td>
          <td class="small"><?= htmlspecialchars((string)$l['curso_nome']) ?></td>
          <td class="small">
            <?php if ($l['id_curso']): ?>
              <a href="curso_detalhe.php?id=<?= (int)$l['id_curso'] ?>"><?= htmlspecialchars($l['nome_curso']) ?></a><?= $l['modulo'] ? ' <span class="badge bg-light text-dark">Mód. ' . (int)$l['modulo'] . '</span>' : '' ?><?= $l['vinculo_manual'] ? ' <span class="text-muted" title="Vínculo feito manualmente pela TI">✎</span>' : '' ?>
            <?php else: ?><span class="text-warning">sem vínculo</span><?php endif; ?>
            <?php if ($podeVincular): ?>
              <details class="mt-1"><summary class="small text-muted" style="cursor:pointer">vincular</summary>
                <form method="post" class="d-flex gap-1 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="vincular"><input type="hidden" name="id_filmagem" value="<?= $id ?>"><input type="hidden" name="qs" value="<?= htmlspecialchars($qs) ?>">
                  <select class="form-select form-select-sm" name="id_curso" style="max-width:260px"><option value="">(nenhum)</option><?php foreach ($cursosOpc as $c): ?><option value="<?= (int)$c['id_curso'] ?>" <?= (int)$l['id_curso'] === (int)$c['id_curso'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome_curso']) ?></option><?php endforeach; ?></select>
                  <select class="form-select form-select-sm" name="modulo" style="max-width:80px"><option value="">Mód.</option><?php for ($i = 1; $i <= 8; $i++): ?><option value="<?= $i ?>" <?= (int)$l['modulo'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
                  <button class="btn btn-sm btn-outline-primary">OK</button></form>
              </details>
            <?php endif; ?>
          </td>
          <td><?= reserva_badge($l['estado_reserva']) ?><?php if ($l['estado_reserva'] === 'Recusada' && $l['motivo_reserva']): ?><div class="small text-muted"><?= htmlspecialchars(mb_strimwidth($l['motivo_reserva'], 0, 60, '…')) ?></div><?php endif; ?></td>
          <td><?= $l['filmagem'] === 'PENDENTE' && $l['estado_reserva'] === 'Recusada' ? '<span class="text-muted">—</span>' : filmagem_badge($l['filmagem']) ?>
            <?php if ($l['filmagem'] === 'REALIZADA'): ?><div class="small"><?= date('d/m/Y', strtotime($l['filmada_em'])) ?> <span class="text-muted">(<?= fdias($l['dias_reserva_filmagem']) ?> após a reserva)</span></div><?php endif; ?>
            <?php if ($l['filmagem_obs']): ?><div class="small text-muted"><?= htmlspecialchars($l['filmagem_obs']) ?></div><?php endif; ?>
            <?php if ($l['registrado_nome']): ?><div class="small text-muted">por <?= htmlspecialchars($l['registrado_nome']) ?></div><?php endif; ?>
          </td>
          <td class="small">
            <?php if ($l['filmagem'] === 'REALIZADA' && $l['id_curso']): ?>
              <?php if ($l['video_em']): ?><span class="text-success">✔ <?= date('d/m/Y', strtotime($l['video_em'])) ?></span><br><span class="text-muted"><?= fdias($l['dias_filmagem_video']) ?> após a filmagem</span>
              <?php else: $dp = (int)(new DateTime('today'))->diff(new DateTime($l['filmada_em']))->days; ?><span class="<?= $dp > 7 ? 'text-danger' : 'text-warning' ?>">pendente há <?= $dp ?> d</span><?php endif; ?>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($podeRegistrar && !($l['filmagem'] === 'PENDENTE' && $l['estado_reserva'] === 'Recusada')): ?>
              <button class="btn btn-sm <?= $l['filmagem'] === 'PENDENTE' ? 'btn-success' : 'btn-outline-secondary' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#reg<?= $id ?>"><?= $l['filmagem'] === 'PENDENTE' ? 'Registrar' : 'Alterar' ?></button>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($podeRegistrar): ?>
        <tr class="collapse" id="reg<?= $id ?>"><td colspan="9" class="bg-body-tertiary">
          <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="action" value="registrar"><input type="hidden" name="id_filmagem" value="<?= $id ?>"><input type="hidden" name="qs" value="<?= htmlspecialchars($qs) ?>">
            <div class="col-6 col-md-3"><label class="form-label small mb-0">Situação</label><select class="form-select form-select-sm" name="situacao"><?php foreach (FILMAGEM_SITUACOES as $k => $d): ?><option value="<?= $k ?>" <?= $l['filmagem'] === $k || ($l['filmagem'] === 'PENDENTE' && $k === 'REALIZADA') ? 'selected' : '' ?>><?= $d['label'] ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0">Filmada em</label><input type="date" class="form-control form-control-sm" name="filmada_em" max="<?= $hoje ?>" value="<?= htmlspecialchars($l['filmada_em'] ?: $l['data_reserva']) ?>"></div>
            <div class="col-12 col-md-5"><label class="form-label small mb-0">Observação (obrigatória se não realizada)</label><input class="form-control form-control-sm" name="obs" maxlength="500" value="<?= htmlspecialchars((string)$l['filmagem_obs']) ?>"></div>
            <div class="col-12 col-md-2"><button class="btn btn-primary btn-sm w-100">Salvar</button></div>
          </form>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="small text-muted">Linhas em amarelo: reserva com data já passada e filmagem ainda não registrada. "Vídeo entregue" considera a primeira versão de vídeo enviada no AutoriaSCS para o curso (e módulo, se vinculado) após a data da filmagem.</div>
  <?php endif; ?>
</div></div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
