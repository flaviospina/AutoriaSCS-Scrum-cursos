<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/status_repo.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); echo "Curso não encontrado."; exit; }

// Permissão: professor só vê o próprio
if ($u['role'] === 'PROFESSOR' && (int)$curso['id_professor'] !== (int)$u['id_user']) {
  http_response_code(403); echo "Acesso negado."; exit;
}

$check = checklist_get($id);

// histórico
$hst = db()->prepare("SELECT h.*, u.nome AS user_nome FROM tb_curso_status_history h JOIN tb_users u ON u.id_user=h.id_user WHERE h.id_curso=? ORDER BY h.created_at DESC");
$hst->execute([$id]);
$history = $hst->fetchAll();

$fs = db()->prepare("SELECT * FROM tb_curso_files WHERE id_curso=? ORDER BY modulo, created_at DESC");
$fs->execute([$id]);
$files = $fs->fetchAll();

// links externos (Google Drive / vídeos MB)
$lk = db()->prepare("SELECT l.*, u.nome AS user_nome FROM tb_curso_links l JOIN tb_users u ON u.id_user=l.id_user WHERE l.id_curso=? ORDER BY l.created_at DESC");
$lk->execute([$id]);
$links = $lk->fetchAll();

$podeGerirLinks = in_array($u['role'], ['TI','ADMIN'], true) ||
  ($u['role'] === 'PROFESSOR' && (int)$curso['id_professor'] === (int)$u['id_user']);

// apontamentos
$ap = db()->prepare("SELECT a.*, u.nome AS user_nome FROM tb_curso_apontamentos a JOIN tb_users u ON u.id_user=a.id_user WHERE a.id_curso=? ORDER BY a.created_at DESC");
$ap->execute([$id]);
$apont = $ap->fetchAll();

$erro = null; $ok = null;
if (($_GET['ok'] ?? '') === 'edit') $ok = "Curso atualizado com sucesso.";
if (($_GET['ok'] ?? '') === 'upload') $ok = "Arquivo enviado com sucesso.";
if (($_GET['err'] ?? '') !== '') $erro = "Falha no upload (" . htmlspecialchars($_GET['err']) . "). Verifique tamanho (máx. 25MB) e tipo do arquivo.";

// Atualiza checklist (professor dono ou TI/ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_checklist') {
  csrf_check();
  try {
    require_role(['PROFESSOR','TI']);

    $fields = [
      'modulos_definidos','estrutura_introducao','planejamento_videos','planejamento_textos_apoio',
      'planejamento_avaliacoes','referencias_abnt',
      'videos_produzidos','textos_escritos','avaliacoes_criadas','revisao_interna_professor',
      'criterios_atendidos','material_enviado'
    ];

    $sets = [];
    $vals = [];
    foreach ($fields as $f) {
      $sets[] = "{$f}=?";
      $vals[] = isset($_POST[$f]) ? 1 : 0;
    }
    $vals[] = $id;

    $antesCheck = $check;
    db()->prepare("UPDATE tb_curso_checklist SET ".implode(',', $sets)." WHERE id_curso=?")->execute($vals);
    $check = checklist_get($id);
    [$da, $dd] = audit_diff(
      array_intersect_key($antesCheck, array_flip($fields)),
      array_intersect_key($check, array_flip($fields))
    );
    if ($dd) audit_log('checklist_atualizado', 'curso', $id, $da, $dd);
    $ok = "Checklist atualizado com sucesso.";
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// RECUSAR COM RELATÓRIO (TI/ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recusar_relatorio') {
  csrf_check();
  try {
    require_role(['TI']);

    $itens = $_POST['itens'] ?? [];
    if (!is_array($itens) || count($itens) === 0) {
      throw new Exception("Informe ao menos 1 apontamento.");
    }

    db()->beginTransaction();
    try {
      foreach ($itens as $i) {
        $tipo = $i['tipo'] ?? 'OUTRO';
        $conteudo = trim($i['conteudo'] ?? '');
        if ($conteudo === '') continue;

        if (!in_array($tipo, ['TECNICO','PEDAGOGICO','ABNT','OUTRO'], true)) $tipo = 'OUTRO';

        db()->prepare("INSERT INTO tb_curso_apontamentos (id_curso, id_user, tipo, conteudo) VALUES (?,?,?,?)")
          ->execute([$id, $u['id_user'], $tipo, $conteudo]);
        audit_log('apontamento_criado', 'curso', $id, null, ['tipo' => $tipo, 'conteudo' => $conteudo]);
      }

      db()->commit();
    } catch (Throwable $e) {
      db()->rollBack();
      throw $e;
    }

    curso_transition($id, $u, 'Recusado - Ajustes Necessários', 'Recusa com relatório (TI)');

    header("Location: curso_detalhe.php?id={$id}");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Adicionar link externo (Drive / vídeo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_link') {
  csrf_check();
  try {
    if (!$podeGerirLinks) throw new Exception("Sem permissão para adicionar links.");
    $titulo = trim($_POST['link_titulo'] ?? '');
    $url = trim($_POST['link_url'] ?? '');
    $tipo = $_POST['link_tipo'] ?? 'DRIVE';
    if (!in_array($tipo, ['DRIVE','VIDEO','OUTRO'], true)) $tipo = 'OUTRO';
    if ($titulo === '') throw new Exception("Informe o título do link.");
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
      throw new Exception("URL inválida (use http:// ou https://).");
    }

    db()->prepare("INSERT INTO tb_curso_links (id_curso, id_user, titulo, url, tipo) VALUES (?,?,?,?,?)")
      ->execute([$id, $u['id_user'], $titulo, $url, $tipo]);
    audit_log('link_adicionado', 'curso', $id, null, ['titulo' => $titulo, 'url' => $url, 'tipo' => $tipo]);

    header("Location: curso_detalhe.php?id={$id}");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Remover link externo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del_link') {
  csrf_check();
  try {
    $idl = (int)($_POST['id_link'] ?? 0);
    $stl = db()->prepare("SELECT * FROM tb_curso_links WHERE id_link=? AND id_curso=?");
    $stl->execute([$idl, $id]);
    $l = $stl->fetch();
    if (!$l) throw new Exception("Link não encontrado.");

    $podeRemover = in_array($u['role'], ['TI','ADMIN'], true) || (int)$l['id_user'] === (int)$u['id_user'];
    if (!$podeRemover) throw new Exception("Sem permissão para remover este link.");

    db()->prepare("DELETE FROM tb_curso_links WHERE id_link=?")->execute([$idl]);
    audit_log('link_removido', 'curso', $id, ['titulo' => $l['titulo'], 'url' => $l['url']], null);

    header("Location: curso_detalhe.php?id={$id}");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Transição de status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transition') {
  csrf_check();
  try {
    $to = trim($_POST['to'] ?? '');
    $obs = trim($_POST['obs'] ?? '');
    curso_transition($id, $u, $to, $obs ?: null);
    header("Location: curso_detalhe.php?id={$id}");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

include __DIR__ . '/_layout_top.php';

function chk($check, $k) { return !empty($check[$k]); }

// opções de transição conforme perfil + status atual (dinâmico, via banco)
$possible = possible_transitions($u['role'], $curso['status_atual']);

$pb = prioridade_badge($curso['prioridade'] ?? 'MEDIA');
$pf = prazo_flag($curso['data_prevista_entrega_final'], $curso['status_atual']);
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0"><?= htmlspecialchars($curso['nome_curso']) ?></h1>
    <div class="text-muted small">
      Formador(a): <b><?= htmlspecialchars($curso['professor_nome']) ?></b> •
      Carga horária: <b><?= htmlspecialchars($curso['carga_horaria']) ?> horas</b> •
      Status: <span class="badge rounded-pill" style="<?= status_badge_style($curso['status_atual']) ?>">
                <?= htmlspecialchars($curso['status_atual']) ?>
              </span>
      <span class="badge rounded-pill" style="<?= $pb['style'] ?>">Prioridade: <?= $pb['label'] ?></span>
      <?php if ($pf === 'atrasado'): ?>
        <span class="badge bg-danger">Atrasado</span>
      <?php elseif ($pf === 'proximo'): ?>
        <span class="badge bg-warning text-dark">Prazo próximo</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="dashboard.php">Voltar</a>
    <?php if ($u['role'] !== 'MB'): ?>
      <a class="btn btn-outline-primary" href="curso_editar.php?id=<?= (int)$id ?>">Editar</a>
    <?php endif; ?>
    <a class="btn btn-outline-primary" href="apontamentos.php?id=<?= (int)$id ?>">Apontamentos</a>
  </div>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <!-- Dados do Backlog -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Backlog do Curso</h2>

        <div class="row g-2 small">
          <div class="col-12"><b>Público-alvo:</b> <?= htmlspecialchars($curso['publico_alvo']) ?></div>
          <div class="col-6"><b>Nível de ensino:</b> <?= htmlspecialchars($curso['nivel_ensino'] ?? '-') ?></div>
          <div class="col-6"><b>Unidade escolar:</b> <?= htmlspecialchars($curso['unidade_escolar'] ?? '-') ?></div>
          <div class="col-6"><b>Prev. início:</b> <?= $curso['data_prevista_inicio'] ?: '-' ?></div>
          <div class="col-6"><b>Prev. entrega:</b> <?= $curso['data_prevista_entrega_final'] ?: '-' ?></div>
          <?php if (!empty($curso['publication_due_date'])): ?>
            <div class="col-12"><b>Publicação prevista:</b> <?= htmlspecialchars($curso['publication_due_date']) ?></div>
          <?php endif; ?>
          <div class="col-12"><b>Descrição:</b><br><?= nl2br(htmlspecialchars($curso['descricao_breve'] ?? '-')) ?></div>
        </div>

        <hr class="my-3">

        <h3 class="h6 mb-2">Ações de Status</h3>
        <?php if (!$possible): ?>
          <div class="text-muted small">Nenhuma transição disponível para seu perfil.</div>
        <?php else: ?>
          <form method="post" class="d-flex flex-column gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="transition">
            <div class="row g-2">
              <div class="col-12 col-md-5">
                <select class="form-select" name="to" required>
                  <?php foreach ($possible as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-7">
                <input class="form-control" name="obs" placeholder="Observação (opcional)">
              </div>
            </div>
            <button class="btn btn-primary">Atualizar Status</button>
          </form>
        <?php endif; ?>
        <?php if (in_array($u['role'], ['TI','ADMIN'], true) && $curso['status_atual'] === 'Em Revisão'): ?>
          <button class="btn btn-outline-danger mt-2" data-bs-toggle="modal" data-bs-target="#modalRecusa">
            Recusar com relatório
          </button>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Checklist -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Checklist (Planejamento, Produção e Entrega)</h2>

        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_checklist">

          <div class="mb-2 fw-semibold">Planejamento</div>
          <div class="row g-2">
            <?php
              $planej = [
                'modulos_definidos' => 'Definição de módulos',
                'estrutura_introducao' => 'Estrutura da introdução',
                'planejamento_videos' => 'Planejamento dos vídeos (MB Estúdios)',
                'planejamento_textos_apoio' => 'Planejamento textos de apoio',
                'planejamento_avaliacoes' => 'Planejamento avaliações (+5 p/ randomização)',
                'referencias_abnt' => 'Referências ABNT NBR 6023/2018',
              ];
              foreach ($planej as $k => $label):
            ?>
              <div class="col-12 col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="<?= $k ?>" name="<?= $k ?>" <?= chk($check,$k) ? 'checked':'' ?>>
                  <label class="form-check-label" for="<?= $k ?>"><?= htmlspecialchars($label) ?></label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <hr class="my-3">

          <div class="mb-2 fw-semibold">Produção</div>
          <div class="row g-2">
            <?php
              $prod = [
                'videos_produzidos' => 'Vídeos produzidos',
                'textos_escritos' => 'Textos escritos',
                'avaliacoes_criadas' => 'Avaliações criadas',
                'revisao_interna_professor' => 'Revisão interna (formador)',
              ];
              foreach ($prod as $k => $label):
            ?>
              <div class="col-12 col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="<?= $k ?>" name="<?= $k ?>" <?= chk($check,$k) ? 'checked':'' ?>>
                  <label class="form-check-label" for="<?= $k ?>"><?= htmlspecialchars($label) ?></label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <hr class="my-3">

          <div class="mb-2 fw-semibold">Entrega / Validação</div>
          <div class="row g-2">
            <?php
              $ent = [
                'criterios_atendidos' => 'Critérios atendidos (autor)',
                'material_enviado' => 'Material enviado oficialmente',
              ];
              foreach ($ent as $k => $label):
            ?>
              <div class="col-12 col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="<?= $k ?>" name="<?= $k ?>" <?= chk($check,$k) ? 'checked':'' ?>>
                  <label class="form-check-label" for="<?= $k ?>"><?= htmlspecialchars($label) ?></label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-success">Salvar Checklist</button>
          </div>

          <div class="text-muted small mt-2">
            Dica: use o checklist como critério de passagem de sprint.
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Apontamentos (resumo) -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0">Apontamentos (TI/Qualidade)</h2>
          <a class="btn btn-sm btn-outline-primary" href="apontamentos.php?id=<?= (int)$id ?>">Gerenciar</a>
        </div>

        <hr class="my-3">

        <?php if (!$apont): ?>
          <div class="text-muted small">Nenhum apontamento registrado.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>Data</th>
                  <th>Tipo</th>
                  <th>Conteúdo</th>
                  <th>Por</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($apont, 0, 5) as $a): ?>
                  <tr>
                    <td class="text-nowrap"><?= htmlspecialchars($a['created_at']) ?></td>
                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($a['tipo']) ?></span></td>
                    <td><?= htmlspecialchars(mb_strimwidth($a['conteudo'], 0, 120, '...')) ?></td>
                    <td><?= htmlspecialchars($a['user_nome']) ?></td>
                    <td><?= $a['resolvido'] ? '<span class="badge bg-success">Resolvido</span>' : '<span class="badge bg-warning text-dark">Pendente</span>' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (count($apont) > 5): ?>
            <div class="small text-muted">Mostrando 5 de <?= count($apont) ?>. Clique em “Gerenciar”.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Arquivos -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0">Arquivos do Curso</h2>
        </div>
        <hr class="my-3">

        <form class="row g-2" method="post" action="upload.php" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="id_curso" value="<?= (int)$id ?>">
          <div class="col-6 col-md-3">
            <label class="form-label small">Categoria</label>
            <select class="form-select" name="categoria">
              <option value="PLANEJAMENTO">Planejamento</option>
              <option value="PRODUCAO">Produção</option>
              <option value="ENTREGA">Entrega</option>
              <option value="OUTROS" selected>Outros</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small">Módulo</label>
            <select class="form-select" name="modulo">
              <option value="0">Geral</option>
              <?php for ($mo = 1; $mo <= 8; $mo++): ?>
                <option value="<?= $mo ?>">Módulo <?= $mo ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-12 col-md-5">
            <label class="form-label small">Arquivo</label>
            <input class="form-control" type="file" name="arquivo" required>
            <div class="form-text">Limite 25MB. Tipos comuns: PDF, DOCX, PPTX, MP4, ZIP.</div>
          </div>
          <div class="col-12 col-md-2 d-flex align-items-end">
            <button class="btn btn-primary w-100">Enviar</button>
          </div>
        </form>

        <hr class="my-3">

        <?php if (!$files): ?>
          <div class="text-muted small">Nenhum arquivo enviado.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>Data</th>
                  <th>Módulo</th>
                  <th>Categoria</th>
                  <th>Nome</th>
                  <th>Tamanho</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($files as $f): ?>
                  <tr>
                    <td class="text-nowrap"><?= htmlspecialchars($f['created_at']) ?></td>
                    <td>
                      <span class="badge <?= ((int)($f['modulo'] ?? 0)) ? 'bg-primary' : 'bg-secondary' ?>">
                        <?= ((int)($f['modulo'] ?? 0)) ? 'Módulo ' . (int)$f['modulo'] : 'Geral' ?>
                      </span>
                    </td>
                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($f['categoria']) ?></span></td>
                    <td><?= htmlspecialchars($f['original_name']) ?></td>
                    <td class="text-nowrap"><?= number_format($f['file_size']/1024/1024, 2, ',', '.') ?> MB</td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="download.php?id=<?= (int)$f['id_file'] ?>">Download</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Links externos (Google Drive / vídeos) -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-0">Links Externos (Google Drive / Vídeos)</h2>
        <div class="small text-muted mb-3">
          Para mídia pesada (vídeos brutos, materiais acima de 25MB) use o Google Drive e registre o link aqui.
        </div>

        <?php if ($podeGerirLinks): ?>
          <form method="post" class="row g-2 mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_link">
            <div class="col-12 col-md-4">
              <label class="form-label small">Título</label>
              <input class="form-control form-control-sm" name="link_titulo" required maxlength="150"
                     placeholder="Ex.: Vídeos brutos - Módulo 2">
            </div>
            <div class="col-12 col-md-5">
              <label class="form-label small">URL</label>
              <input class="form-control form-control-sm" type="url" name="link_url" required
                     placeholder="https://drive.google.com/...">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small">Tipo</label>
              <select class="form-select form-select-sm" name="link_tipo">
                <option value="DRIVE">Google Drive</option>
                <option value="VIDEO">Vídeo</option>
                <option value="OUTRO">Outro</option>
              </select>
            </div>
            <div class="col-6 col-md-1 d-flex align-items-end">
              <button class="btn btn-primary btn-sm w-100">Adicionar</button>
            </div>
          </form>
        <?php endif; ?>

        <?php if (!$links): ?>
          <div class="text-muted small">Nenhum link cadastrado.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr><th>Data</th><th>Tipo</th><th>Título</th><th>Por</th><th class="text-end">Ações</th></tr>
              </thead>
              <tbody>
                <?php foreach ($links as $l): ?>
                  <tr>
                    <td class="text-nowrap small"><?= htmlspecialchars($l['created_at']) ?></td>
                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($l['tipo']) ?></span></td>
                    <td>
                      <a href="<?= htmlspecialchars($l['url']) ?>" target="_blank" rel="noopener noreferrer">
                        <?= htmlspecialchars($l['titulo']) ?> ↗
                      </a>
                    </td>
                    <td class="small"><?= htmlspecialchars($l['user_nome']) ?></td>
                    <td class="text-end">
                      <?php if (in_array($u['role'], ['TI','ADMIN'], true) || (int)$l['id_user'] === (int)$u['id_user']): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Remover este link?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="del_link">
                          <input type="hidden" name="id_link" value="<?= (int)$l['id_link'] ?>">
                          <button class="btn btn-sm btn-outline-danger py-0">Remover</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Histórico -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Histórico de Status</h2>

        <?php if (!$history): ?>
          <div class="text-muted small">Sem histórico.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead class="table-light">
                <tr>
                  <th>Data</th>
                  <th>Usuário</th>
                  <th>De</th>
                  <th>Para</th>
                  <th>Observação</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td class="text-nowrap"><?= htmlspecialchars($h['created_at']) ?></td>
                    <td><?= htmlspecialchars($h['user_nome']) ?></td>
                    <td><?= htmlspecialchars($h['status_de']) ?></td>
                    <td><span class="badge" style="<?= status_badge_style($h['status_para']) ?>"><?= htmlspecialchars($h['status_para']) ?></span></td>
                    <td><?= htmlspecialchars($h['observacao'] ?? '-') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if (in_array($u['role'], ['TI','ADMIN'], true) && $curso['status_atual'] === 'Em Revisão'): ?>
        <div class="modal fade" id="modalRecusa" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg">
            <form class="modal-content" method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="recusar_relatorio">
              <div class="modal-header">
                <h5 class="modal-title">Recusar com relatório (TI)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>

              <div class="modal-body">
                <div class="alert alert-warning small">
                  Isso irá: (1) registrar os apontamentos e (2) alterar o status para <b>Recusado - Ajustes Necessários</b>.
                </div>

                <?php for ($k=0; $k<5; $k++): ?>
                  <div class="border rounded p-2 mb-2">
                    <div class="row g-2">
                      <div class="col-12 col-md-3">
                        <label class="form-label small">Tipo</label>
                        <select class="form-select form-select-sm" name="itens[<?= $k ?>][tipo]">
                          <option value="TECNICO">Técnico</option>
                          <option value="PEDAGOGICO">Pedagógico</option>
                          <option value="ABNT">ABNT</option>
                          <option value="OUTRO" selected>Outro</option>
                        </select>
                      </div>
                      <div class="col-12 col-md-9">
                        <label class="form-label small">Apontamento</label>
                        <textarea class="form-control form-control-sm" name="itens[<?= $k ?>][conteudo]" rows="2" placeholder="Descreva o ajuste necessário..."></textarea>
                      </div>
                    </div>
                  </div>
                <?php endfor; ?>

                <div class="small text-muted">
                  Preencha os campos que desejar (linhas vazias serão ignoradas).
                </div>
              </div>

              <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-danger">Confirmar Recusa</button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
