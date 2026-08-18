<?php
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/escolas_repo.php';

$erro = null; $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    // cadastro em lote: uma escola por linha da textarea
    if ($action === 'importar') {
      $texto = trim($_POST['escolas'] ?? '');
      if ($texto === '') throw new Exception("Cole a lista de escolas (uma por linha).");

      $linhas = preg_split('/\r\n|\r|\n/', $texto);
      $inseridas = 0; $repetidas = 0; $vistas = [];

      db()->beginTransaction();
      try {
        $st = db()->prepare("INSERT IGNORE INTO tb_escolas (nome, ativo) VALUES (?, 1)");
        foreach ($linhas as $linha) {
          $nome = trim(preg_replace('/\s+/', ' ', $linha));
          if ($nome === '' || mb_strlen($nome) < 3) continue;
          $nome = mb_substr($nome, 0, 150);
          $chave = mb_strtolower($nome);
          if (isset($vistas[$chave])) { $repetidas++; continue; } // repetida na própria lista
          $vistas[$chave] = true;

          $st->execute([$nome]);
          $st->rowCount() > 0 ? $inseridas++ : $repetidas++;
        }
        db()->commit();
      } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
      }

      audit_log('escolas_importadas', 'escola', null, null,
        ['inseridas' => $inseridas, 'ja_existentes' => $repetidas]);
      $ok = "{$inseridas} escola(s) cadastrada(s)." .
            ($repetidas ? " {$repetidas} já existiam ou estavam repetidas e foram ignoradas." : "");
    }

    if ($action === 'toggle') {
      $ide = (int)($_POST['id_escola'] ?? 0);
      $st = db()->prepare("SELECT * FROM tb_escolas WHERE id_escola=?");
      $st->execute([$ide]);
      $e = $st->fetch();
      if (!$e) throw new Exception("Escola não encontrada.");
      $novo = $e['ativo'] ? 0 : 1;
      db()->prepare("UPDATE tb_escolas SET ativo=? WHERE id_escola=?")->execute([$novo, $ide]);
      audit_log($novo ? 'escola_reativada' : 'escola_desativada', 'escola', $ide, null, ['nome' => $e['nome']]);
      $ok = "Escola \"{$e['nome']}\" " . ($novo ? "reativada." : "desativada.");
    }

    if ($action === 'renomear') {
      $ide = (int)($_POST['id_escola'] ?? 0);
      $nome = trim(preg_replace('/\s+/', ' ', $_POST['nome'] ?? ''));
      if (mb_strlen($nome) < 3) throw new Exception("Informe o nome da escola.");

      $st = db()->prepare("SELECT * FROM tb_escolas WHERE id_escola=?");
      $st->execute([$ide]);
      $e = $st->fetch();
      if (!$e) throw new Exception("Escola não encontrada.");

      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_escolas WHERE nome=? AND id_escola<>?");
      $dup->execute([$nome, $ide]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe uma escola com esse nome.");

      db()->beginTransaction();
      try {
        db()->prepare("UPDATE tb_escolas SET nome=? WHERE id_escola=?")->execute([$nome, $ide]);
        // mantém os cursos existentes apontando para o novo nome
        db()->prepare("UPDATE tb_cursos SET unidade_escolar=? WHERE unidade_escolar=?")
          ->execute([$nome, $e['nome']]);
        db()->commit();
      } catch (Throwable $ex) {
        db()->rollBack();
        throw $ex;
      }
      audit_log('escola_renomeada', 'escola', $ide, ['nome' => $e['nome']], ['nome' => $nome]);
      $ok = "Escola renomeada (cursos vinculados foram atualizados).";
    }

    if ($action === 'excluir') {
      $ide = (int)($_POST['id_escola'] ?? 0);
      $st = db()->prepare("SELECT * FROM tb_escolas WHERE id_escola=?");
      $st->execute([$ide]);
      $e = $st->fetch();
      if (!$e) throw new Exception("Escola não encontrada.");

      $uso = db()->prepare("SELECT COUNT(*) n FROM tb_cursos WHERE unidade_escolar=?");
      $uso->execute([$e['nome']]);
      if ((int)$uso->fetch()['n'] > 0) {
        throw new Exception("Não é possível excluir: há cursos vinculados a esta escola. Desative-a em vez de excluir.");
      }

      db()->prepare("DELETE FROM tb_escolas WHERE id_escola=?")->execute([$ide]);
      audit_log('escola_excluida', 'escola', $ide, ['nome' => $e['nome']], null);
      $ok = "Escola \"{$e['nome']}\" excluída.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$fQ = trim($_GET['q'] ?? '');
$params = []; $where = '';
if ($fQ !== '') { $where = "WHERE e.nome LIKE ?"; $params[] = "%{$fQ}%"; }

$st = db()->prepare("
  SELECT e.*, (SELECT COUNT(*) FROM tb_cursos c WHERE c.unidade_escolar = e.nome) AS n_cursos
  FROM tb_escolas e
  $where
  ORDER BY e.nome
");
$st->execute($params);
$escolas = $st->fetchAll();

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Escolas (Unidades Escolares)</h1>
    <div class="text-muted small">
      Alimentam o autocompletar do campo "Unidade escolar" nos cursos e no filtro do dashboard.
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <!-- Cadastro em lote -->
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Cadastrar escolas em lote</h2>
        <form method="post"
              data-confirm="Cadastrar todas as escolas da lista de uma vez?<br>Nomes já existentes serão ignorados."
              data-confirm-title="Cadastrar escolas" data-confirm-btn="Sim, cadastrar">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="importar">
          <label class="form-label">Uma escola por linha</label>
          <textarea class="form-control" name="escolas" rows="12" required
                    placeholder="EMEF Prof. Exemplo Um&#10;EMEF Profa. Exemplo Dois&#10;EMEI Exemplo Três&#10;CECAPE"></textarea>
          <div class="form-text">
            Linhas vazias e nomes repetidos são ignorados automaticamente. Todas entram no banco de uma só vez.
          </div>
          <button class="btn btn-primary mt-3">Cadastrar todas</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Lista -->
  <div class="col-12 col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <h2 class="h6 mb-0">Escolas cadastradas (<?= count($escolas) ?>)</h2>
          <form class="d-flex gap-2">
            <input class="form-control form-control-sm" name="q" list="dlEscolasTodas"
                   value="<?= htmlspecialchars($fQ) ?>" placeholder="Buscar escola..."
                   autocomplete="off">
            <?= datalist_html('dlEscolasTodas', escolas_todas_nomes()) ?>
            <button class="btn btn-sm btn-outline-primary">Buscar</button>
          </form>
        </div>

        <?php if (!$escolas): ?>
          <div class="text-muted small">Nenhuma escola cadastrada ainda. Use o formulário ao lado.</div>
        <?php else: ?>
          <div class="table-responsive" style="max-height: 60vh; overflow:auto">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Escola</th>
                  <th title="Cursos vinculados">Cursos</th>
                  <th>Situação</th>
                  <th class="text-end">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($escolas as $e): ?>
                  <tr class="<?= !$e['ativo'] ? 'opacity-75' : '' ?>">
                    <td>
                      <form method="post" class="d-flex gap-2 align-items-center"
                            data-confirm="Renomear esta escola?<br>Os cursos vinculados serão atualizados."
                            data-confirm-title="Renomear escola" data-confirm-btn="Sim, renomear">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="renomear">
                        <input type="hidden" name="id_escola" value="<?= (int)$e['id_escola'] ?>">
                        <input class="form-control form-control-sm" name="nome"
                               value="<?= htmlspecialchars($e['nome']) ?>" required minlength="3" maxlength="150">
                        <button class="btn btn-sm btn-outline-primary py-0">Salvar</button>
                      </form>
                    </td>
                    <td><span class="badge bg-secondary rounded-pill"><?= (int)$e['n_cursos'] ?></span></td>
                    <td>
                      <?= $e['ativo']
                          ? '<span class="badge bg-success">Ativa</span>'
                          : '<span class="badge bg-warning">Inativa</span>' ?>
                    </td>
                    <td class="text-end text-nowrap">
                      <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id_escola" value="<?= (int)$e['id_escola'] ?>">
                        <button class="btn btn-sm btn-outline-warning py-0"><?= $e['ativo'] ? 'Desativar' : 'Reativar' ?></button>
                      </form>
                      <form method="post" class="d-inline"
                            data-confirm="Excluir a escola <b><?= htmlspecialchars($e['nome']) ?></b>?"
                            data-confirm-title="Excluir escola" data-confirm-type="danger" data-confirm-btn="Sim, excluir">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id_escola" value="<?= (int)$e['id_escola'] ?>">
                        <button class="btn btn-sm btn-outline-danger py-0"
                                <?= (int)$e['n_cursos'] > 0 ? 'disabled title="Há cursos vinculados"' : '' ?>>Excluir</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="small text-muted mt-2">
          Escolas <b>inativas</b> saem do autocompletar, mas os cursos antigos continuam exibindo o nome.
          A exclusão só é permitida sem cursos vinculados.
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
