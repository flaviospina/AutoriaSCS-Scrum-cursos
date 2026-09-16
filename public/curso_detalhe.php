<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/entregas_repo.php';
require_once __DIR__ . '/../app/status_repo.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';
require_once __DIR__ . '/../app/apontamento_repo.php';
require_once __DIR__ . '/../app/checklist_repo.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); echo "Curso não encontrado."; exit; }

// Permissão: professor (responsável ou coautor) só vê o próprio
$ehProfessor = curso_eh_professor($curso, (int)$u['id_user']);
if (!is_staff() && !$ehProfessor) {
  http_response_code(403); echo "Acesso negado."; exit;
}

$check = checklist_get($id);

// histórico
$hst = db()->prepare("SELECT h.*, u.nome AS user_nome FROM tb_curso_status_history h JOIN tb_users u ON u.id_user=h.id_user WHERE h.id_curso=? ORDER BY h.created_at DESC");
$hst->execute([$id]);
$history = $hst->fetchAll();

// arquivos na ordem oficial de entrega (módulo > sequência das categorias),
// a mesma ordem em que a MB Estúdios baixa o conteúdo
$files = entregas_arquivos_ordenados($id);

$ehMB = ($u['role'] === 'MB'); // MB baixa tudo de uma vez pelo ZIP, sem downloads avulsos

// links externos (Google Drive / vídeos MB)
$lk = db()->prepare("SELECT l.*, u.nome AS user_nome FROM tb_curso_links l JOIN tb_users u ON u.id_user=l.id_user WHERE l.id_curso=? ORDER BY l.created_at DESC");
$lk->execute([$id]);
$links = $lk->fetchAll();

$podeGerirLinks = perm('revisa_cursos') || $ehProfessor;

// apontamentos (V11: arquivo relacionado, status e pendências)
$apont = apont_lista($id);
$apontPendentes = apont_pendentes_curso($id);

$erro = null; $ok = null;
if (($_GET['ok'] ?? '') === 'edit') $ok = "Curso atualizado com sucesso.";
if (($_GET['ok'] ?? '') === 'upload') $ok = "Arquivo enviado com sucesso.";
if (($_GET['ok'] ?? '') === 'dispensa') $ok = "Categoria registrada como \"sem material\".";
if (($_GET['ok'] ?? '') === 'slide_ok') $ok = "Slide aprovado. O envio do vídeo deste módulo foi liberado.";
if (($_GET['ok'] ?? '') === 'slide_rev') $ok = "Aprovação do slide revogada. O envio do vídeo deste módulo voltou a ficar bloqueado.";
if (($_GET['ok'] ?? '') === 'reativa') $ok = "Registro \"sem material\" desfeito. A categoria voltou a aceitar envio.";
if (($_GET['ok'] ?? '') === 'delarq') $ok = "Arquivo excluído.";
if (($_GET['err'] ?? '') !== '') {
  $mapErr = [
    'slide'     => "O envio deste vídeo ficará disponível após a aprovação dos slides correspondentes.",
    'categoria' => "Categoria inválida para o módulo selecionado.",
    'size'      => "Arquivo acima do limite de 25MB.",
    'mime'      => "Tipo de arquivo não permitido.",
    'semarquivos' => "Este curso ainda não possui arquivos para baixar.",
  ];
  $erro = $mapErr[$_GET['err']] ?? "Falha no upload (" . htmlspecialchars($_GET['err']) . "). Verifique tamanho (máx. 25MB) e tipo do arquivo.";
}

// Atualiza checklist (V11: por perfil; o backend só aceita itens do checklist autorizado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_checklist') {
  csrf_check();
  try {
    if (checklists_disponivel()) {
      $codigo = $_POST['checklist'] ?? '';
      if (!in_array($codigo, ['PROFESSOR', 'TI'], true)) throw new Exception("Checklist inválido.");
      $marcados = array_keys(array_filter((array)($_POST['item'] ?? []), fn($v) => (string)$v === '1'));
      checklist_salvar($id, $codigo, $marcados, $u, $curso);
    } else {
      // banco ainda sem a V11: comportamento anterior (tabela tb_curso_checklist)
      if (!perm('revisa_cursos') && !$ehProfessor) throw new Exception("Sem permissão.");
      $fields = [
        'modulos_definidos','estrutura_introducao','planejamento_videos','planejamento_textos_apoio',
        'planejamento_avaliacoes','referencias_abnt',
        'videos_produzidos','textos_escritos','avaliacoes_criadas','revisao_interna_professor',
        'criterios_atendidos','material_enviado'
      ];
      $sets = []; $vals = [];
      foreach ($fields as $f) { $sets[] = "{$f}=?"; $vals[] = isset($_POST[$f]) ? 1 : 0; }
      $vals[] = $id;
      $antesCheck = $check;
      db()->prepare("UPDATE tb_curso_checklist SET ".implode(',', $sets)." WHERE id_curso=?")->execute($vals);
      $check = checklist_get($id);
      [$da, $dd] = audit_diff(array_intersect_key($antesCheck, array_flip($fields)), array_intersect_key($check, array_flip($fields)));
      if ($dd) audit_log('checklist_atualizado', 'curso', $id, $da, $dd);
    }
    $ok = "Checklist atualizado com sucesso.";
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// RECUSAR COM RELATÓRIO (TI/ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recusar_relatorio') {
  csrf_check();
  try {
    if (!perm('revisa_cursos')) throw new Exception("Sem permissão.");

    $itens = $_POST['itens'] ?? [];
    if (!is_array($itens) || count($itens) === 0) {
      throw new Exception("Informe ao menos 1 apontamento.");
    }

    $criados = 0;
    foreach ($itens as $i) {
      $conteudo = trim($i['conteudo'] ?? '');
      if ($conteudo === '') continue;
      $idFile = ($i['id_file'] ?? '') !== '' ? (int)$i['id_file'] : null;
      // e-mail individual desativado: o e-mail da recusa já lista todos os apontamentos
      apont_criar($curso, $u, $i['tipo'] ?? 'OUTRO', $conteudo, $idFile, false);
      $criados++;
    }
    if ($criados === 0) throw new Exception("Informe ao menos 1 apontamento.");

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

    $podeRemover = perm('revisa_cursos') || (int)$l['id_user'] === (int)$u['id_user'];
    if (!$podeRemover) throw new Exception("Sem permissão para remover este link.");

    db()->prepare("DELETE FROM tb_curso_links WHERE id_link=?")->execute([$idl]);
    audit_log('link_removido', 'curso', $id, ['titulo' => $l['titulo'], 'url' => $l['url']], null);

    header("Location: curso_detalhe.php?id={$id}");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Registrar categoria opcional como "sem material" (fluxo ordenado de entregas)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dispensar') {
  csrf_check();
  try {
    if (!perm('revisa_cursos') && !$ehProfessor) {
      throw new Exception("Sem permissão.");
    }
    $mod = (int)($_POST['modulo'] ?? -1);
    $cat = trim($_POST['categoria'] ?? '');
    if ($mod < 0 || $mod > 8) throw new Exception("Módulo inválido.");

    $alvo = null;
    foreach (entregas_estado($id, $mod) as $c) {
      if ($c['nome'] === $cat) { $alvo = $c; break; }
    }
    if (!$alvo) throw new Exception("Categoria inválida para o módulo.");
    if ($alvo['obrigatoria']) throw new Exception("Esta categoria é obrigatória — o envio do arquivo não pode ser dispensado.");
    if ($alvo['feita']) throw new Exception("Esta categoria já possui arquivo enviado.");
    if ($alvo['dispensada']) throw new Exception("Esta categoria já está registrada como sem material.");

    db()->prepare("INSERT INTO tb_curso_dispensas (id_curso, modulo, categoria, id_user) VALUES (?,?,?,?)")
      ->execute([$id, $mod, $cat, $u['id_user']]);
    audit_log('entrega_sem_material', 'curso', $id, null, ['modulo' => $mod, 'categoria' => $cat]);

    header("Location: curso_detalhe.php?id={$id}&ok=dispensa");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Desfazer o registro "sem material" (o material foi criado depois)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reativar') {
  csrf_check();
  try {
    if (!perm('revisa_cursos') && !$ehProfessor) {
      throw new Exception("Sem permissão.");
    }
    $mod = (int)($_POST['modulo'] ?? -1);
    $cat = trim($_POST['categoria'] ?? '');
    db()->prepare("DELETE FROM tb_curso_dispensas WHERE id_curso=? AND modulo=? AND categoria=?")
      ->execute([$id, $mod, $cat]);
    audit_log('entrega_sem_material_desfeita', 'curso', $id, ['modulo' => $mod, 'categoria' => $cat], null);

    header("Location: curso_detalhe.php?id={$id}&ok=reativa");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Excluir arquivo enviado (somente ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del_arquivo') {
  csrf_check();
  try {
    if (!is_admin()) throw new Exception("Somente administradores podem excluir arquivos.");
    $idf = (int)($_POST['id_file'] ?? 0);
    $stf = db()->prepare("SELECT * FROM tb_curso_files WHERE id_file=? AND id_curso=?");
    $stf->execute([$idf, $id]);
    $f = $stf->fetch();
    if (!$f) throw new Exception("Arquivo não encontrado.");

    db()->prepare("DELETE FROM tb_curso_files WHERE id_file=?")->execute([$idf]);
    $fsPath = realpath(__DIR__ . '/../storage') . "/cursos/{$id}/{$f['stored_name']}";
    if (is_file($fsPath)) @unlink($fsPath);

    audit_log('arquivo_excluido', 'curso', $id, [
      'arquivo' => $f['original_name'], 'categoria' => $f['categoria'], 'modulo' => (int)$f['modulo'],
    ], null);

    header("Location: curso_detalhe.php?id={$id}&ok=delarq");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Aprovar / revogar aprovação de slide (TI/ADMIN) — libera o vídeo do módulo (item 12)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['aprovar_slide', 'revogar_slide'], true)) {
  csrf_check();
  try {
    $aprovar = ($_POST['action'] === 'aprovar_slide');
    $f = arquivo_aprovar((int)($_POST['id_file'] ?? 0), $u, $aprovar);
    if ((int)$f['id_curso'] !== $id) throw new Exception("Arquivo não pertence a este curso.");
    header("Location: curso_detalhe.php?id={$id}&ok=" . ($aprovar ? 'slide_ok' : 'slide_rev'));
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// Transição de status
$pendenciasBloqueio = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transition') {
  csrf_check();
  try {
    $to = trim($_POST['to'] ?? '');
    $obs = trim($_POST['obs'] ?? '');
    $dataPub = trim($_POST['data_publicacao'] ?? '') ?: null;
    $carga = ($_POST['carga_horaria'] ?? '') !== '' ? (int)$_POST['carga_horaria'] : null;
    curso_transition($id, $u, $to, $obs ?: null, $dataPub, $carga);
    header("Location: curso_detalhe.php?id={$id}");
    exit;
  } catch (EntregasPendentesException $e) {
    $pendenciasBloqueio = array_column($e->pendencias, 'rotulo'); // SweetAlert com a lista
    $erro = $e->getMessage();
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}
$curso = curso_get($id) ?: $curso;

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
    <?php if (!empty($curso['projeto_aprovado_em'])): ?>
      <div class="curso-identificacao" title="Identificação oficial — use este nome na pasta do curso">
        📁 <?= htmlspecialchars(curso_identificacao($curso)) ?>
      </div>
    <?php endif; ?>
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
    <?php if (perm('revisa_cursos') || $ehProfessor): ?>
      <a class="btn btn-outline-primary" href="curso_editar.php?id=<?= (int)$id ?>">Editar</a>
    <?php endif; ?>
    <a class="btn <?= $apontPendentes ? 'btn-danger' : 'btn-outline-primary' ?>" href="apontamentos.php?id=<?= (int)$id ?>">
      Apontamentos<?= $apontPendentes ? ' <span class="badge bg-light text-dark">' . $apontPendentes . '</span>' : '' ?>
    </a>
    <a class="btn btn-outline-info" href="curso_videos.php?id=<?= (int)$id ?>">🎬 Vídeos</a>
  </div>
</div>

<?php if ($pendenciasBloqueio): ?>
  <div class="alert alert-danger">
    <b>Não é possível avançar para a próxima etapa.</b> Documentos obrigatórios pendentes:
    <ul class="mb-0"><?php foreach ($pendenciasBloqueio as $pr): ?><li><?= htmlspecialchars($pr) ?></li><?php endforeach; ?></ul>
  </div>
  <script>document.addEventListener('DOMContentLoaded', function () { if (window.avisaPendencias) window.avisaPendencias(<?= json_encode($pendenciasBloqueio, JSON_UNESCAPED_UNICODE) ?>); });</script>
<?php elseif ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if ($apontPendentes > 0 && $ehProfessor && !apont_pode_gerir($u)): ?>
  <div class="aviso-apontamentos mb-3" role="alert">
    <span>⚠️ ATENÇÃO: existem <b><?= $apontPendentes ?></b> apontamento(s) de TI/Qualidade aguardando sua análise.</span>
    <a class="btn btn-warning btn-sm" href="apontamentos.php?id=<?= (int)$id ?>">Ver apontamentos e responder</a>
  </div>
<?php elseif ($apontPendentes > 0): ?>
  <div class="alert alert-warning mb-3">
    Este curso possui <b><?= $apontPendentes ?></b> apontamento(s) pendente(s) de TI/Qualidade.
    <a href="apontamentos.php?id=<?= (int)$id ?>">Ver apontamentos</a>
  </div>
<?php endif; ?>

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
          <form method="post" class="d-flex flex-column gap-2"
                data-confirm="Confirmar a alteração de status do curso?"
                data-confirm-title="Atualizar status" data-confirm-btn="Sim, atualizar">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="transition">
            <div class="row g-2">
              <div class="col-12 col-md-5">
                <select class="form-select" name="to" id="transTo" required>
                  <?php foreach ($possible as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-7">
                <input class="form-control" name="obs" placeholder="Observação (opcional)">
              </div>
              <div class="col-12 d-none" id="transDataPubWrap">
                <label class="form-label small fw-semibold">Data de entrada na plataforma (obrigatória)</label>
                <input class="form-control" type="date" name="data_publicacao" id="transDataPub"
                       value="<?= htmlspecialchars($curso['publication_due_date'] ?? '') ?>">
                <div class="form-text">Essa data será comunicada ao formador e à MB Estúdios.</div>
              </div>
              <div class="col-12 d-none" id="transCargaWrap">
                <label class="form-label small fw-semibold">Carga horária do curso (obrigatória)</label>
                <select class="form-select" name="carga_horaria" id="transCarga">
                  <?php foreach (carga_horaria_opcoes() as $h): ?>
                    <option value="<?= $h ?>" <?= (int)$curso['carga_horaria'] === $h ? 'selected' : '' ?>>
                      <?= htmlspecialchars(carga_horaria_label($h)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">
                  A carga horária definida aqui é a oficial e compõe a identificação do curso
                  (<i>nome do curso - formador(a) - carga horária</i>), comunicada por e-mail à MB Estúdios e à TI.
                </div>
              </div>
            </div>
            <button class="btn btn-primary">Atualizar Status</button>
          </form>
          <script>
            (function () {
              // Bloqueio de avanço com documentos obrigatórios pendentes (itens 9/10):
              // a mesma regra existe no backend (curso_transition); aqui só antecipamos o aviso.
              var PENDENCIAS = <?= json_encode(array_column(entregas_pendentes($id, (int)$curso['carga_horaria']), 'rotulo'), JSON_UNESCAPED_UNICODE) ?>;
              var EXIGE = <?= json_encode(array_values(array_map(fn($s) => $s['nome'], array_filter(statuses_list(), fn($s) => !empty($s['exige_entregas'])))), JSON_UNESCAPED_UNICODE) ?>;
              var formT = document.getElementById('transTo') ? document.getElementById('transTo').form : null;
              function esc(s) { return String(s).replace(/[&<>"']/g, function (m) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; }); }
              window.avisaPendencias = function (lista) {
                var html = '<p>Os seguintes documentos obrigatórios ainda não foram enviados:</p><ul style="text-align:left">' +
                           lista.map(function (p) { return '<li>' + esc(p) + '</li>'; }).join('') + '</ul>';
                if (typeof Swal !== 'undefined') {
                  Swal.fire({ icon: 'error', title: 'Não é possível avançar para a próxima etapa.', html: html,
                              confirmButtonText: 'OK', confirmButtonColor: '#058285', background: '#0f2044', color: '#e8edf5' });
                } else { alert('Não é possível avançar. Pendências: ' + lista.join('; ')); }
              };
              if (formT) {
                formT.addEventListener('submit', function (e) {
                  var alvo = document.getElementById('transTo').value;
                  if (EXIGE.indexOf(alvo) !== -1 && PENDENCIAS.length) {
                    e.preventDefault(); e.stopImmediatePropagation();
                    window.avisaPendencias(PENDENCIAS);
                  }
                });
              }
              var sel = document.getElementById('transTo');
              var wrap = document.getElementById('transDataPubWrap');
              var inp = document.getElementById('transDataPub');
              var wrapC = document.getElementById('transCargaWrap');
              var selC = document.getElementById('transCarga');
              if (!sel || !wrap) return;
              function toggleCampos() {
                var precisaData = sel.value === 'Pronto para Publicação';
                wrap.classList.toggle('d-none', !precisaData);
                inp.required = precisaData;

                var precisaCarga = sel.value === 'Projeto Aprovado';
                if (wrapC) {
                  wrapC.classList.toggle('d-none', !precisaCarga);
                  selC.required = precisaCarga;
                }
              }
              sel.addEventListener('change', toggleCampos);
              toggleCampos();
            })();
          </script>
        <?php endif; ?>
        <?php if (perm('revisa_cursos') && $curso['status_atual'] === 'Em Revisão'): ?>
          <button class="btn btn-outline-danger mt-2" data-bs-toggle="modal" data-bs-target="#modalRecusa">
            Recusar com relatório
          </button>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Checklist(s) por perfil -->
  <div class="col-12 col-lg-6">
    <?php if (checklists_disponivel()): $visiveis = checklists_visiveis($u, $curso); $listasChk = checklists_all(); ?>
      <?php if (!$visiveis): ?>
        <div class="card shadow-sm"><div class="card-body">
          <h2 class="h6 mb-2">Checklist</h2>
          <div class="text-muted small">Seu perfil não possui checklist neste curso.</div>
        </div></div>
      <?php endif; ?>
      <?php foreach ($visiveis as $codigo): $itensChk = checklist_itens($codigo); $respChk = checklist_respostas($id, $codigo); $prog = checklist_progresso($id, $codigo); $podeChk = checklist_pode_editar($codigo, $u, $curso); ?>
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="h6 mb-0"><?= htmlspecialchars($listasChk[$codigo]['nome'] ?? $codigo) ?></h2>
              <span class="badge <?= $prog['total'] && $prog['marcados'] === $prog['total'] ? 'bg-success' : 'bg-secondary' ?> rounded-pill"><?= $prog['marcados'] ?>/<?= $prog['total'] ?></span>
            </div>
            <form method="post" data-confirm="Salvar as alterações do checklist?" data-confirm-title="Salvar checklist" data-confirm-btn="Sim, salvar">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_checklist">
              <input type="hidden" name="checklist" value="<?= htmlspecialchars($codigo) ?>">
              <?php $grupoAtual = null; foreach ($itensChk as $it): ?>
                <?php if ($it['grupo'] !== $grupoAtual): if ($grupoAtual !== null) echo '</div><hr class="my-3">'; $grupoAtual = $it['grupo']; ?>
                  <div class="mb-2 fw-semibold"><?= htmlspecialchars($it['grupo'] ?: 'Itens') ?></div>
                  <div class="row g-2">
                <?php endif; ?>
                <div class="col-12 col-md-6">
                  <div class="form-check">
                    <input type="hidden" name="item[<?= (int)$it['id_item'] ?>]" value="0">
                    <input class="form-check-input" type="checkbox" id="chk_<?= $codigo ?>_<?= (int)$it['id_item'] ?>"
                           name="item[<?= (int)$it['id_item'] ?>]" value="1" <?= !empty($respChk[(int)$it['id_item']]) ? 'checked' : '' ?> <?= $podeChk ? '' : 'disabled' ?>>
                    <label class="form-check-label" for="chk_<?= $codigo ?>_<?= (int)$it['id_item'] ?>"><?= htmlspecialchars($it['descricao']) ?></label>
                  </div>
                </div>
              <?php endforeach; if ($grupoAtual !== null) echo '</div>'; ?>
              <?php if (!$itensChk): ?><div class="text-muted small">Nenhum item cadastrado (Admin → Checklists).</div><?php endif; ?>
              <?php if ($podeChk && $itensChk): ?>
                <div class="mt-3 d-flex gap-2"><button class="btn btn-success">Salvar Checklist</button></div>
              <?php endif; ?>
              <div class="text-muted small mt-2">Dica: use o checklist como critério de passagem de sprint.</div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Checklist (Planejamento, Produção e Entrega)</h2>

        <form method="post"
              data-confirm="Salvar as alterações do checklist?"
              data-confirm-title="Salvar checklist" data-confirm-btn="Sim, salvar">
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
    <?php endif; ?>
  </div>

  <!-- Apontamentos (resumo) -->
  <div class="col-12">
    <div class="card shadow-sm <?= $apontPendentes ? 'card-apontamentos-pendentes' : '' ?>">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
          <h2 class="h6 mb-0">Apontamentos (TI/Qualidade)
            <?php if ($apontPendentes): ?><span class="badge bg-danger rounded-pill ms-1"><?= $apontPendentes ?> pendente(s)</span><?php endif; ?>
          </h2>
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
                  <th>Arquivo</th>
                  <th>Apontamento</th>
                  <th>Por</th>
                  <th>Status</th>
                  <th class="text-end">Ação</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($apont, 0, 5) as $a): $aPend = APONT_STATUS[$a['status']]['pendente'] ?? false; ?>
                  <tr>
                    <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars(apont_tipo_label($a['tipo'])) ?></span></td>
                    <td class="small"><?= $a['arquivo_nome'] ? htmlspecialchars($a['arquivo_nome']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= htmlspecialchars(mb_strimwidth($a['conteudo'], 0, 120, '...')) ?></td>
                    <td><?= htmlspecialchars($a['user_nome']) ?></td>
                    <td><?= apont_status_badge($a['status']) ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm <?= $aPend && $ehProfessor && !apont_pode_gerir($u) ? 'btn-warning' : 'btn-outline-primary' ?>"
                         href="apontamento_detalhe.php?id=<?= (int)$a['id_apontamento'] ?>">Visualizar</a>
                    </td>
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
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
          <h2 class="h6 mb-0">Arquivos do Curso</h2>
          <?php if (($ehMB || is_admin()) && $files): ?>
            <a class="btn btn-sm btn-success" href="download_todos.php?id=<?= (int)$id ?>">
              ⬇ Baixar todos (ZIP, na ordem de entrega)
            </a>
          <?php endif; ?>
        </div>
        <hr class="my-3">

        <?php if (!$ehMB): // perfil MB apenas baixa os materiais — não envia ?>
        <div class="alert alert-info small">
          Envie os materiais <b>na ordem que preferir</b>. Todas as categorias <b>obrigatórias</b>
          precisam estar entregues antes de o curso avançar para análise da TI; as opcionais
          podem ser registradas como <b>sem material</b>. O <b>vídeo</b> de cada módulo só é liberado
          após a <b>aprovação do slide</b> do módulo pela TI.
        </div>

        <form class="row g-2" method="post" action="upload.php" enctype="multipart/form-data" id="formUpload">
          <?= csrf_field() ?>
          <input type="hidden" name="id_curso" value="<?= (int)$id ?>">
          <div class="col-6 col-md-2">
            <label class="form-label small">Módulo</label>
            <select class="form-select" name="modulo" id="upModulo" required>
              <option value="" selected disabled>Selecione o módulo</option>
              <option value="0">Geral</option>
              <?php for ($mo = 1; $mo <= 8; $mo++): ?>
                <option value="<?= $mo ?>">Módulo <?= $mo ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small">Categoria</label>
            <select class="form-select" name="categoria" id="upCategoria" required disabled>
              <option value="" selected disabled>Selecione o módulo primeiro</option>
            </select>
          </div>
          <div class="col-12 col-md-5">
            <label class="form-label small">Arquivo</label>
            <input class="form-control" type="file" name="arquivo" id="upArquivo" required>
            <div class="form-text">Limite 25MB. Tipos comuns: PDF, DOCX, PPTX, MP4, ZIP.</div>
          </div>
          <div class="col-12 col-md-2 d-flex align-items-end">
            <button class="btn btn-primary w-100" id="upBtn">Enviar</button>
          </div>
          <div class="col-12 d-none small text-warning" id="upAvisoVideo"></div>
          <div class="col-12 d-none" id="upSemMaterialWrap">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="upSemMaterial">
              <label class="form-check-label small" for="upSemMaterial">
                Não possuo este material — registrar como <b>sem material</b> e liberar a próxima categoria.
              </label>
            </div>
          </div>
        </form>

        <div id="upFluxo" class="mt-3 d-none">
          <div class="small fw-semibold mb-1" id="upFluxoTitulo">Ordem de entrega</div>
          <ol class="small mb-0" id="upFluxoLista" style="line-height:2"></ol>
        </div>

        <script>
        (function () {
          var ENTREGAS = <?= json_encode(entregas_estado_completo($id), JSON_UNESCAPED_UNICODE) ?>;
          var CURSO_ID = <?= (int)$id ?>;
          var form   = document.getElementById('formUpload');
          var selMod = document.getElementById('upModulo');
          var selCat = document.getElementById('upCategoria');
          var inpArq = document.getElementById('upArquivo');
          var chkWrap = document.getElementById('upSemMaterialWrap');
          var chk    = document.getElementById('upSemMaterial');
          var btn    = document.getElementById('upBtn');
          var fluxo  = document.getElementById('upFluxo');
          var fluxoTitulo = document.getElementById('upFluxoTitulo');
          var fluxoLista  = document.getElementById('upFluxoLista');
          if (!form) return;

          function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (m) {
              return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
            });
          }

          function catInfo(mod, nome) {
            var cats = ENTREGAS[mod] || [];
            for (var i = 0; i < cats.length; i++) if (cats[i].nome === nome) return cats[i];
            return null;
          }

          function renderCategorias() {
            var mod = selMod.value;
            var cats = ENTREGAS[mod] || [];
            selCat.innerHTML = '';
            var ph = new Option('Selecione a categoria', '');
            ph.disabled = true;
            selCat.add(ph);

            var atual = '';
            cats.forEach(function (c) {
              var rotulo = c.nome;
              if (c.feita) rotulo += ' — ✓ enviado';
              else if (c.dispensada) rotulo += ' — sem material';
              else if (!c.obrigatoria) rotulo += ' (opcional)';
              else rotulo += ' (obrigatória)';
              if (!c.habilitada) rotulo += ' — aguardando aprovação do slide';
              var o = new Option(rotulo, c.nome);
              o.disabled = !c.habilitada;
              selCat.add(o);
              if (c.atual) atual = c.nome;
            });

            selCat.disabled = false;
            if (atual) selCat.value = atual; else ph.selected = true;
            renderFluxo(mod);
            atualizaSemMaterial();
          }

          var avisoVideo = document.getElementById('upAvisoVideo');
          function avisaBloqueio() {
            var c = catInfo(selMod.value, selCat.value);
            var msg = c && c.bloqueio ? c.bloqueio : '';
            if (avisoVideo) { avisoVideo.textContent = msg; avisoVideo.classList.toggle('d-none', !msg); }
            btn.disabled = !!msg;
          }

          function renderFluxo(mod) {
            var cats = ENTREGAS[mod] || [];
            fluxoTitulo.textContent = 'Ordem de entrega — ' + (mod === '0' ? 'Geral' : 'Módulo ' + mod);
            fluxoLista.innerHTML = '';
            cats.forEach(function (c, idx) {
              var li = document.createElement('li');
              var badge;
              if (c.feita)            badge = '<span class="badge bg-success">Enviado</span>';
              else if (c.dispensada)  badge = '<span class="badge bg-secondary">Sem material</span>';
              else if (!c.habilitada) badge = '<span class="badge bg-dark border" title="' + escapeHtml(c.bloqueio || '') + '">Aguardando aprovação do slide</span>';
              else if (c.obrigatoria) badge = '<span class="badge bg-danger">Pendente (obrigatória)</span>';
              else                    badge = '<span class="badge bg-info text-dark">Opcional</span>';
              li.innerHTML = escapeHtml(c.nome) +
                (c.obrigatoria ? '' : ' <span class="text-muted">(opcional)</span>') + ' ' + badge;
              if (c.dispensada) {
                var bt = document.createElement('button');
                bt.type = 'button';
                bt.className = 'btn btn-outline-secondary btn-sm py-0 ms-2';
                bt.textContent = 'Desfazer';
                bt.addEventListener('click', function () { postAcao('reativar', mod, c.nome); });
                li.appendChild(bt);
              }
              fluxoLista.appendChild(li);
            });
            fluxo.classList.remove('d-none');
          }

          function atualizaSemMaterial() {
            var c = catInfo(selMod.value, selCat.value);
            var mostra = !!(c && !c.obrigatoria && !c.feita && !c.dispensada);
            chkWrap.classList.toggle('d-none', !mostra);
            if (!mostra) chk.checked = false;
            aplicaModo();
          }

          function aplicaModo() {
            avisaBloqueio();
            var sem = chk.checked;
            inpArq.disabled = sem;
            inpArq.required = !sem;
            btn.textContent = sem ? 'Registrar sem material' : 'Enviar';
            btn.classList.toggle('btn-primary', !sem);
            btn.classList.toggle('btn-outline-info', sem);
            if (!sem) avisaBloqueio();
          }

          function postAcao(acao, mod, cat) {
            var f = document.createElement('form');
            f.method = 'post';
            f.action = 'curso_detalhe.php?id=' + CURSO_ID;
            [['action', acao], ['modulo', mod], ['categoria', cat],
             ['csrf_token', form.querySelector('[name=csrf_token]').value]
            ].forEach(function (par) {
              var i = document.createElement('input');
              i.type = 'hidden'; i.name = par[0]; i.value = par[1];
              f.appendChild(i);
            });
            document.body.appendChild(f);
            f.submit();
          }

          form.addEventListener('submit', function (e) {
            if (!chk.checked) return; // upload normal segue para upload.php
            e.preventDefault();
            var cat = selCat.value;
            var mod = selMod.value;
            if (!cat || mod === '') return;
            var confirmar = function () { postAcao('dispensar', mod, cat); };
            if (typeof Swal !== 'undefined') {
              Swal.fire({
                title: 'Registrar sem material',
                html: 'Confirmar que <b>' + escapeHtml(cat) + '</b> não possui material?',
                icon: 'question', showCancelButton: true,
                confirmButtonText: 'Sim, registrar', cancelButtonText: 'Cancelar',
                confirmButtonColor: '#06b6d4', cancelButtonColor: '#374151',
                background: '#0f2044', color: '#e8edf5', reverseButtons: true
              }).then(function (r) { if (r.isConfirmed) confirmar(); });
            } else if (window.confirm('Registrar "' + cat + '" como sem material?')) {
              confirmar();
            }
          });

          selMod.addEventListener('change', renderCategorias);
          selCat.addEventListener('change', atualizaSemMaterial);
          chk.addEventListener('change', aplicaModo);
        })();
        </script>
        <?php endif; // fim do bloco de upload (oculto para MB) ?>

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
                    <td>
                      <span class="badge bg-info text-dark"><?= htmlspecialchars($f['categoria']) ?></span>
                      <?php if (!empty($f['aprovado'])): ?>
                        <span class="badge bg-success" title="Aprovado por <?= htmlspecialchars($f['aprovado_por_nome'] ?? 'TI') ?> em <?= htmlspecialchars($f['aprovado_em'] ?? '') ?>">✔ Slide aprovado</span>
                      <?php elseif (arquivo_eh_slide($f)): ?>
                        <span class="badge bg-warning text-dark">Aguardando aprovação</span>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($f['original_name']) ?></td>
                    <td class="text-nowrap"><?= number_format($f['file_size']/1024/1024, 2, ',', '.') ?> MB</td>
                    <td class="text-end text-nowrap">
                      <?php if (!$ehMB): ?>
                        <a class="btn btn-sm btn-outline-primary" href="download.php?id=<?= (int)$f['id_file'] ?>">Download</a>
                      <?php endif; ?>
                      <?php if (perm('revisa_cursos') && arquivo_eh_slide($f)): ?>
                        <form method="post" class="d-inline"
                              data-confirm="<?= !empty($f['aprovado']) ? 'Revogar a aprovação deste slide?<br>O envio do vídeo do módulo voltará a ficar bloqueado.' : 'Aprovar este slide?<br>O envio do <b>vídeo</b> deste módulo será liberado para o formador.' ?>"
                              data-confirm-title="<?= !empty($f['aprovado']) ? 'Revogar aprovação' : 'Aprovar slide' ?>" data-confirm-btn="<?= !empty($f['aprovado']) ? 'Sim, revogar' : 'Sim, aprovar' ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="<?= !empty($f['aprovado']) ? 'revogar_slide' : 'aprovar_slide' ?>">
                          <input type="hidden" name="id_file" value="<?= (int)$f['id_file'] ?>">
                          <button class="btn btn-sm <?= !empty($f['aprovado']) ? 'btn-outline-warning' : 'btn-success' ?>"><?= !empty($f['aprovado']) ? 'Revogar' : '✔ Aprovar slide' ?></button>
                        </form>
                      <?php endif; ?>
                      <?php if (is_admin()): ?>
                        <form method="post" class="d-inline"
                              data-confirm="Excluir o arquivo <b><?= htmlspecialchars($f['original_name']) ?></b> (<?= ((int)$f['modulo']) ? 'Módulo '.(int)$f['modulo'] : 'Geral' ?> • <?= htmlspecialchars($f['categoria']) ?>)?<br>A sequência de entregas do módulo será recalculada."
                              data-confirm-title="Excluir arquivo" data-confirm-type="danger" data-confirm-btn="Sim, excluir">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="del_arquivo">
                          <input type="hidden" name="id_file" value="<?= (int)$f['id_file'] ?>">
                          <button class="btn btn-sm btn-outline-danger">Excluir</button>
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
                      <?php if (perm('revisa_cursos') || (int)$l['id_user'] === (int)$u['id_user']): ?>
                        <form method="post" class="d-inline"
                              data-confirm="Remover o link <b><?= htmlspecialchars($l['titulo']) ?></b>?"
                              data-confirm-title="Remover link" data-confirm-type="danger" data-confirm-btn="Sim, remover">
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

        <?php if (perm('revisa_cursos') && $curso['status_atual'] === 'Em Revisão'): ?>
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
                        <label class="form-label small">Arquivo/Material relacionado (opcional)</label>
                        <select class="form-select form-select-sm" name="itens[<?= $k ?>][id_file]">
                          <option value="">Nenhum — geral</option>
                          <?php foreach ($files as $fo): ?>
                            <option value="<?= (int)$fo['id_file'] ?>"><?= ((int)$fo['modulo']) ? 'Módulo ' . (int)$fo['modulo'] : 'Geral' ?> • <?= htmlspecialchars($fo['categoria']) ?> — <?= htmlspecialchars($fo['original_name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-12">
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
