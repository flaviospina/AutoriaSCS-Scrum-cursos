<?php
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/categorias_repo.php';

$erro = null; $ok = null;

// a página depende da migração V10
try {
  db()->query("SELECT 1 FROM tb_categorias LIMIT 1");
} catch (Throwable $e) {
  include __DIR__ . '/../_layout_top.php';
  echo '<div class="alert alert-warning">A tabela <b>tb_categorias</b> ainda não existe. Execute <code>database/upgrade_v10.sql</code> no phpMyAdmin.</div>';
  include __DIR__ . '/../_layout_bottom.php';
  exit;
}

function categoria_nome_limpo(string $s): string {
  return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, 60);
}

/** Renumera a ordem de um escopo (1..n) mantendo a sequência atual. */
function categorias_renumerar(string $escopo, ?array $ids = null): void {
  if ($ids === null) {
    $st = db()->prepare("SELECT id_categoria FROM tb_categorias WHERE escopo=? ORDER BY ordem, nome");
    $st->execute([$escopo]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id_categoria'));
  }
  $up = db()->prepare("UPDATE tb_categorias SET ordem=? WHERE id_categoria=?");
  foreach ($ids as $i => $id) $up->execute([$i + 1, $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $escopo = $_POST['escopo'] ?? '';
      $nome   = categoria_nome_limpo($_POST['nome'] ?? '');
      $obr    = !empty($_POST['obrigatoria']) ? 1 : 0;
      $tipoEsp = in_array($_POST['tipo_especial'] ?? 'NENHUM', ['NENHUM','SLIDE','VIDEO'], true) ? $_POST['tipo_especial'] : 'NENHUM';
      if (!isset(CATEGORIA_ESCOPOS[$escopo])) throw new Exception("Escopo inválido.");
      if (mb_strlen($nome) < 3) throw new Exception("Informe o nome da categoria (mínimo 3 caracteres).");
      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_categorias WHERE escopo=? AND nome=?");
      $dup->execute([$escopo, $nome]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe uma categoria com esse nome neste escopo.");

      $mx = db()->prepare("SELECT COALESCE(MAX(ordem),0) m FROM tb_categorias WHERE escopo=?");
      $mx->execute([$escopo]);
      $ordem = (int)$mx->fetch()['m'] + 1;
      try {
        db()->prepare("INSERT INTO tb_categorias (escopo, nome, obrigatoria, tipo_especial, ordem, ativo) VALUES (?,?,?,?,?,1)")
          ->execute([$escopo, $nome, $obr, $tipoEsp, $ordem]);
      } catch (Throwable $e) { // upgrade_v11.sql ainda não executado (sem tipo_especial)
        db()->prepare("INSERT INTO tb_categorias (escopo, nome, obrigatoria, ordem, ativo) VALUES (?,?,?,?,1)")
          ->execute([$escopo, $nome, $obr, $ordem]);
      }
      audit_log('categoria_criada', 'categoria', (int)db()->lastInsertId(), null,
        ['escopo' => $escopo, 'nome' => $nome, 'obrigatoria' => $obr, 'tipo_especial' => $tipoEsp, 'ordem' => $ordem]);
      $ok = "Categoria \"{$nome}\" cadastrada em " . CATEGORIA_ESCOPOS[$escopo] . ".";
    }

    if ($action === 'renomear') {
      $idc  = (int)($_POST['id_categoria'] ?? 0);
      $nome = categoria_nome_limpo($_POST['nome'] ?? '');
      if (mb_strlen($nome) < 3) throw new Exception("Informe o nome da categoria.");
      $c = categoria_get($idc);
      if (!$c) throw new Exception("Categoria não encontrada.");
      if ($nome === $c['nome']) { $ok = "Nenhuma alteração no nome."; }
      else {
        $dup = db()->prepare("SELECT COUNT(*) n FROM tb_categorias WHERE escopo=? AND nome=? AND id_categoria<>?");
        $dup->execute([$c['escopo'], $nome, $idc]);
        if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe uma categoria com esse nome neste escopo.");

        $condMod = $c['escopo'] === 'GERAL' ? 'modulo = 0' : 'modulo > 0';
        db()->beginTransaction();
        try {
          db()->prepare("UPDATE tb_categorias SET nome=? WHERE id_categoria=?")->execute([$nome, $idc]);
          // arquivos e dispensas vinculados acompanham o novo nome (vínculo é pelo nome)
          $f = db()->prepare("UPDATE tb_curso_files SET categoria=? WHERE categoria=? AND $condMod");
          $f->execute([$nome, $c['nome']]);
          $nf = $f->rowCount();
          $nd = 0;
          try {
            $d = db()->prepare("UPDATE tb_curso_dispensas SET categoria=? WHERE categoria=? AND $condMod");
            $d->execute([$nome, $c['nome']]);
            $nd = $d->rowCount();
          } catch (Throwable $e) { /* upgrade_v7.sql ainda não executado */ }
          db()->commit();
        } catch (Throwable $ex) {
          db()->rollBack();
          throw $ex;
        }
        audit_log('categoria_renomeada', 'categoria', $idc, ['nome' => $c['nome']],
          ['nome' => $nome, 'arquivos_atualizados' => $nf, 'dispensas_atualizadas' => $nd]);
        $ok = "Categoria renomeada ({$nf} arquivo(s) e {$nd} registro(s) de \"sem material\" atualizados).";
      }
    }

    if ($action === 'obrigatoria') {
      $idc = (int)($_POST['id_categoria'] ?? 0);
      $c = categoria_get($idc);
      if (!$c) throw new Exception("Categoria não encontrada.");
      $novo = $c['obrigatoria'] ? 0 : 1;
      db()->prepare("UPDATE tb_categorias SET obrigatoria=? WHERE id_categoria=?")->execute([$novo, $idc]);
      audit_log('categoria_obrigatoriedade', 'categoria', $idc, ['obrigatoria' => (int)$c['obrigatoria']], ['obrigatoria' => $novo, 'nome' => $c['nome']]);
      $ok = "Categoria \"{$c['nome']}\" agora é " . ($novo ? "obrigatória." : "opcional (pode ser dispensada pelo formador).");
    }

    if ($action === 'tipo_especial') {
      $idc = (int)($_POST['id_categoria'] ?? 0);
      $c = categoria_get($idc);
      if (!$c) throw new Exception("Categoria não encontrada.");
      $novo = in_array($_POST['tipo_especial'] ?? '', ['NENHUM','SLIDE','VIDEO'], true) ? $_POST['tipo_especial'] : 'NENHUM';
      db()->prepare("UPDATE tb_categorias SET tipo_especial=? WHERE id_categoria=?")->execute([$novo, $idc]);
      audit_log('categoria_tipo_especial', 'categoria', $idc, ['tipo_especial' => $c['tipo_especial'] ?? 'NENHUM'], ['tipo_especial' => $novo, 'nome' => $c['nome']]);
      $ok = "Papel da categoria \"{$c['nome']}\" atualizado.";
    }

    if ($action === 'toggle') {
      $idc = (int)($_POST['id_categoria'] ?? 0);
      $c = categoria_get($idc);
      if (!$c) throw new Exception("Categoria não encontrada.");
      $novo = $c['ativo'] ? 0 : 1;
      db()->prepare("UPDATE tb_categorias SET ativo=? WHERE id_categoria=?")->execute([$novo, $idc]);
      audit_log($novo ? 'categoria_reativada' : 'categoria_inativada', 'categoria', $idc, ['ativo' => (int)$c['ativo']], ['ativo' => $novo, 'nome' => $c['nome']]);
      $ok = "Categoria \"{$c['nome']}\" " . ($novo ? "reativada." : "inativada (arquivos já enviados continuam listados).");
    }

    if ($action === 'mover') {
      $idc = (int)($_POST['id_categoria'] ?? 0);
      $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
      $c = categoria_get($idc);
      if (!$c) throw new Exception("Categoria não encontrada.");
      $st = db()->prepare("SELECT id_categoria FROM tb_categorias WHERE escopo=? ORDER BY ordem, nome");
      $st->execute([$c['escopo']]);
      $ids = array_map('intval', array_column($st->fetchAll(), 'id_categoria'));
      $pos = array_search($idc, $ids, true);
      $alvo = $pos + $dir;
      if ($pos !== false && isset($ids[$alvo])) {
        [$ids[$pos], $ids[$alvo]] = [$ids[$alvo], $ids[$pos]];
        db()->beginTransaction();
        try { categorias_renumerar($c['escopo'], $ids); db()->commit(); }
        catch (Throwable $ex) { db()->rollBack(); throw $ex; }
        audit_log('categoria_reordenada', 'categoria', $idc, ['posicao' => $pos + 1], ['posicao' => $alvo + 1, 'escopo' => $c['escopo']]);
      }
      $ok = "Ordem de entrega atualizada.";
    }

    if ($action === 'excluir') {
      // Exclusão protegida (item 6): regra 1 = etapa concluída por algum curso → bloqueia;
      // regra 2 = possui dados mas não concluída → só após dupla confirmação; soft delete.
      $idc = (int)($_POST['id_categoria'] ?? 0);
      $c = categoria_get($idc);
      if (!$c) { http_response_code(404); throw new Exception("Categoria não encontrada."); }
      if (!array_key_exists('excluida_em', $c)) throw new Exception("Execute database/upgrade_v11.sql antes de excluir etapas.");
      if (!empty($c['excluida_em'])) { http_response_code(422); throw new Exception("Esta categoria já está excluída."); }
      $uso = categoria_em_uso($c['escopo'], $c['nome']);
      if ($uso['arquivos'] > 0) {
        http_response_code(422);
        audit_log('categoria_exclusao_bloqueada', 'categoria', $idc, null, ['nome' => $c['nome'], 'arquivos' => $uso['arquivos'], 'cursos' => $uso['cursos']]);
        throw new Exception("Não é possível excluir esta etapa: ela já foi concluída por um ou mais cursos ({$uso['arquivos']} arquivo(s)) e faz parte do histórico do sistema.");
      }
      $condMod = $c['escopo'] === 'GERAL' ? 'modulo = 0' : 'modulo > 0';
      db()->beginTransaction();
      try {
        $ids = [];
        try {
          $sd = db()->prepare("SELECT id_dispensa FROM tb_curso_dispensas WHERE categoria=? AND $condMod");
          $sd->execute([$c['nome']]);
          $ids = array_map('intval', array_column($sd->fetchAll(), 'id_dispensa'));
        } catch (Throwable $e) {}
        db()->prepare("UPDATE tb_categorias SET ativo=0, excluida_em=NOW() WHERE id_categoria=?")->execute([$idc]);
        db()->commit();
      } catch (Throwable $ex) { db()->rollBack(); throw $ex; }
      audit_log('categoria_excluida', 'categoria', $idc,
        ['escopo' => $c['escopo'], 'nome' => $c['nome'], 'ativo' => (int)$c['ativo']],
        ['excluida_em' => date('Y-m-d H:i:s'), 'dispensas_vinculadas' => $ids, 'soft_delete' => true]);
      $ok = "Categoria \"{$c['nome']}\" excluída (registro preservado; pode ser restaurado abaixo).";
    }

    if ($action === 'restaurar') {
      $idc = (int)($_POST['id_categoria'] ?? 0);
      $c = categoria_get($idc);
      if (!$c) { http_response_code(404); throw new Exception("Categoria não encontrada."); }
      db()->prepare("UPDATE tb_categorias SET ativo=1, excluida_em=NULL WHERE id_categoria=?")->execute([$idc]);
      audit_log('categoria_restaurada', 'categoria', $idc, ['excluida_em' => $c['excluida_em'] ?? null], ['nome' => $c['nome']]);
      $ok = "Categoria \"{$c['nome']}\" restaurada.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$todas = db()->query("SELECT * FROM tb_categorias ORDER BY escopo, ordem, nome")->fetchAll();
$porEscopo = ['GERAL' => [], 'MODULO' => []];
$excluidas = [];
foreach ($todas as $c) {
  if (!empty($c['excluida_em'])) { $excluidas[] = $c; continue; }
  $c['uso'] = categoria_em_uso($c['escopo'], $c['nome']);
  $porEscopo[$c['escopo']][] = $c;
}

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Categorias de Entrega de Material</h1>
    <div class="text-muted small">
      Sequência de entrega dos materiais por módulo (é a ordem em que a MB Estúdios baixa o conteúdo).
      Categorias <b>obrigatórias</b> exigem arquivo; as <b>opcionais</b> podem ser dispensadas pelo formador.
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-xl-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Nova categoria</h2>
        <form method="post" data-confirm="Cadastrar esta categoria de entrega?" data-confirm-title="Nova categoria" data-confirm-btn="Sim, cadastrar">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <label class="form-label">Escopo</label>
          <select class="form-select" name="escopo" required>
            <?php foreach (CATEGORIA_ESCOPOS as $k => $lbl): ?>
              <option value="<?= $k ?>"><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label mt-3">Nome</label>
          <input class="form-control" name="nome" required minlength="3" maxlength="60" placeholder="Ex.: Roteiro do vídeo">
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" name="obrigatoria" value="1" id="chkObr" checked>
            <label class="form-check-label" for="chkObr">Obrigatória (exige arquivo)</label>
          </div>
          <label class="form-label mt-3">Papel especial</label>
          <select class="form-select" name="tipo_especial">
            <option value="NENHUM">Nenhum</option>
            <option value="SLIDE">Slide (a aprovação pela TI libera o vídeo do módulo)</option>
            <option value="VIDEO">Vídeo (só aceita envio após slide aprovado)</option>
          </select>
          <div class="form-text">Entra ativa, no fim da sequência do escopo. Use as setas para reordenar.</div>
          <button class="btn btn-primary mt-3">Cadastrar</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-9">
    <?php foreach (CATEGORIA_ESCOPOS as $escopo => $lbl): $lista = $porEscopo[$escopo]; $tot = count($lista); ?>
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= $lbl ?> <span class="badge bg-primary rounded-pill"><?= $tot ?></span></h2>
          <?php if (!$lista): ?>
            <div class="text-muted small">Nenhuma categoria neste escopo.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th style="width:90px">Ordem</th>
                    <th>Categoria</th>
                    <th>Tipo</th>
                    <th title="Slide libera o vídeo; Vídeo depende do slide aprovado">Papel</th>
                    <th title="Arquivos enviados / registros sem material">Uso</th>
                    <th>Situação</th>
                    <th class="text-end">Ações</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($lista as $i => $c): $emUso = $c['uso']['arquivos'] + $c['uso']['dispensas']; ?>
                    <tr class="<?= !$c['ativo'] ? 'opacity-75' : '' ?>">
                      <td class="text-nowrap">
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                          <input type="hidden" name="action" value="mover">
                          <input type="hidden" name="id_categoria" value="<?= (int)$c['id_categoria'] ?>">
                          <button class="btn btn-sm btn-outline-secondary py-0" name="dir" value="up" title="Subir" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
                          <button class="btn btn-sm btn-outline-secondary py-0" name="dir" value="down" title="Descer" <?= $i === $tot - 1 ? 'disabled' : '' ?>>▼</button>
                        </form>
                      </td>
                      <td>
                        <form method="post" class="d-flex gap-2 align-items-center"
                              data-confirm="Renomear esta categoria?<br>Os arquivos já enviados serão atualizados para o novo nome."
                              data-confirm-title="Renomear categoria" data-confirm-btn="Sim, renomear">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="renomear">
                          <input type="hidden" name="id_categoria" value="<?= (int)$c['id_categoria'] ?>">
                          <input class="form-control form-control-sm" name="nome"
                                 value="<?= htmlspecialchars($c['nome']) ?>" required minlength="3" maxlength="60">
                          <button class="btn btn-sm btn-outline-primary py-0">Salvar</button>
                        </form>
                      </td>
                      <td>
                        <form method="post" class="d-inline"
                              data-confirm="<?= $c['obrigatoria'] ? 'Tornar esta categoria <b>opcional</b>? O formador poderá dispensá-la.' : 'Tornar esta categoria <b>obrigatória</b>? Passará a exigir arquivo.' ?>"
                              data-confirm-title="Obrigatoriedade" data-confirm-btn="Sim, alterar">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="obrigatoria">
                          <input type="hidden" name="id_categoria" value="<?= (int)$c['id_categoria'] ?>">
                          <button class="btn btn-sm py-0 <?= $c['obrigatoria'] ? 'btn-danger' : 'btn-outline-secondary' ?>" title="Clique para alternar">
                            <?= $c['obrigatoria'] ? 'Obrigatória' : 'Opcional' ?>
                          </button>
                        </form>
                      </td>
                      <td>
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                          <input type="hidden" name="action" value="tipo_especial">
                          <input type="hidden" name="id_categoria" value="<?= (int)$c['id_categoria'] ?>">
                          <select class="form-select form-select-sm" name="tipo_especial" onchange="this.form.submit()" title="Papel especial">
                            <?php foreach (['NENHUM' => '—', 'SLIDE' => 'Slide', 'VIDEO' => 'Vídeo'] as $k => $lbl): ?>
                              <option value="<?= $k ?>" <?= ($c['tipo_especial'] ?? 'NENHUM') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                          </select>
                        </form>
                      </td>
                      <td class="text-nowrap small">
                        <span class="badge bg-secondary rounded-pill" title="Arquivos"><?= $c['uso']['arquivos'] ?></span>
                        <span class="badge bg-dark rounded-pill" title="Sem material"><?= $c['uso']['dispensas'] ?></span>
                      </td>
                      <td>
                        <?= $c['ativo']
                            ? '<span class="badge bg-success">Ativa</span>'
                            : '<span class="badge bg-warning text-dark">Inativa</span>' ?>
                      </td>
                      <td class="text-end text-nowrap">
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="id_categoria" value="<?= (int)$c['id_categoria'] ?>">
                          <button class="btn btn-sm btn-outline-warning py-0"><?= $c['ativo'] ? 'Inativar' : 'Reativar' ?></button>
                        </form>
                        <?php $regra = $c['uso']['arquivos'] > 0 ? '1' : ($c['uso']['dispensas'] > 0 ? '2' : '0'); ?>
                        <form method="post" class="d-inline"
                              data-etapa-regra="<?= $regra ?>" data-etapa-nome="<?= htmlspecialchars($c['nome']) ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="excluir">
                          <input type="hidden" name="id_categoria" value="<?= (int)$c['id_categoria'] ?>">
                          <button class="btn btn-sm btn-outline-danger py-0" title="<?= $regra === '1' ? 'Etapa concluída por cursos: exclusão bloqueada' : 'Excluir (soft delete)' ?>">Excluir</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($excluidas): ?>
      <div class="card shadow-sm mb-3 opacity-75">
        <div class="card-body">
          <h2 class="h6 mb-2">Categorias excluídas (<?= count($excluidas) ?>)</h2>
          <?php foreach ($excluidas as $c): ?>
            <form method="post" class="d-flex flex-wrap align-items-center gap-2 small mb-1"
                  data-confirm="Restaurar a categoria <b><?= htmlspecialchars($c['nome']) ?></b>?" data-confirm-title="Restaurar categoria" data-confirm-btn="Sim, restaurar">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="restaurar">
              <input type="hidden" name="id_categoria" value="<?= (int)$c['id_categoria'] ?>">
              <span><?= htmlspecialchars(CATEGORIA_ESCOPOS[$c['escopo']] ?? $c['escopo']) ?> • <b><?= htmlspecialchars($c['nome']) ?></b> — excluída em <?= htmlspecialchars($c['excluida_em']) ?></span>
              <button class="btn btn-sm btn-outline-success py-0">Restaurar</button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="small text-muted">
      Categorias <b>inativas</b> saem do formulário de envio, mas os arquivos já enviados continuam listados no curso.
      <b>Exclusão</b>: bloqueada quando algum curso já concluiu a etapa (tem arquivo); com dados vinculados exige dupla confirmação;
      o registro é preservado (soft delete) e pode ser restaurado.
    </div>
  </div>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
