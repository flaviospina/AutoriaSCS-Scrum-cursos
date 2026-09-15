<?php
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/niveis_repo.php';

$erro = null; $ok = null;

// a página depende da migração V10
try {
  db()->query("SELECT 1 FROM tb_niveis_ensino LIMIT 1");
} catch (Throwable $e) {
  include __DIR__ . '/../_layout_top.php';
  echo '<div class="alert alert-warning">A tabela <b>tb_niveis_ensino</b> ainda não existe. Execute <code>database/upgrade_v10.sql</code> no phpMyAdmin.</div>';
  include __DIR__ . '/../_layout_bottom.php';
  exit;
}

function nivel_nome_limpo(string $s): string {
  return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, 60);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $nome = nivel_nome_limpo($_POST['nome'] ?? '');
      if (mb_strlen($nome) < 3) throw new Exception("Informe o nome do nível de ensino (mínimo 3 caracteres).");
      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_niveis_ensino WHERE nome=?");
      $dup->execute([$nome]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe um nível de ensino com esse nome.");

      $max = (int)db()->query("SELECT COALESCE(MAX(ordem),0) m FROM tb_niveis_ensino")->fetch()['m'];
      db()->prepare("INSERT INTO tb_niveis_ensino (nome, ordem, ativo) VALUES (?,?,1)")->execute([$nome, $max + 1]);
      audit_log('nivel_criado', 'nivel_ensino', (int)db()->lastInsertId(), null, ['nome' => $nome, 'ordem' => $max + 1]);
      $ok = "Nível \"{$nome}\" cadastrado.";
    }

    if ($action === 'renomear') {
      $idn = (int)($_POST['id_nivel'] ?? 0);
      $nome = nivel_nome_limpo($_POST['nome'] ?? '');
      if (mb_strlen($nome) < 3) throw new Exception("Informe o nome do nível de ensino.");
      $n = nivel_get($idn);
      if (!$n) throw new Exception("Nível não encontrado.");
      if ($nome === $n['nome']) { $ok = "Nenhuma alteração no nome."; }
      else {
        $dup = db()->prepare("SELECT COUNT(*) n FROM tb_niveis_ensino WHERE nome=? AND id_nivel<>?");
        $dup->execute([$nome, $idn]);
        if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe um nível de ensino com esse nome.");

        db()->beginTransaction();
        try {
          db()->prepare("UPDATE tb_niveis_ensino SET nome=? WHERE id_nivel=?")->execute([$nome, $idn]);
          // cursos vinculados acompanham o novo nome (vínculo é pelo nome)
          $up = db()->prepare("UPDATE tb_cursos SET nivel_ensino=? WHERE nivel_ensino=?");
          $up->execute([$nome, $n['nome']]);
          $cursos = $up->rowCount();
          db()->commit();
        } catch (Throwable $ex) {
          db()->rollBack();
          throw $ex;
        }
        audit_log('nivel_renomeado', 'nivel_ensino', $idn, ['nome' => $n['nome']], ['nome' => $nome, 'cursos_atualizados' => $cursos]);
        $ok = "Nível renomeado ({$cursos} curso(s) atualizado(s)).";
      }
    }

    if ($action === 'toggle') {
      $idn = (int)($_POST['id_nivel'] ?? 0);
      $n = nivel_get($idn);
      if (!$n) throw new Exception("Nível não encontrado.");
      $novo = $n['ativo'] ? 0 : 1;
      db()->prepare("UPDATE tb_niveis_ensino SET ativo=? WHERE id_nivel=?")->execute([$novo, $idn]);
      audit_log($novo ? 'nivel_reativado' : 'nivel_inativado', 'nivel_ensino', $idn, ['ativo' => (int)$n['ativo']], ['ativo' => $novo, 'nome' => $n['nome']]);
      $ok = "Nível \"{$n['nome']}\" " . ($novo ? "reativado." : "inativado (cursos existentes mantêm o valor).");
    }

    if ($action === 'mover') {
      $idn = (int)($_POST['id_nivel'] ?? 0);
      $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
      $lista = db()->query("SELECT id_nivel, ordem FROM tb_niveis_ensino ORDER BY ordem, nome")->fetchAll();
      $pos = null;
      foreach ($lista as $i => $r) if ((int)$r['id_nivel'] === $idn) { $pos = $i; break; }
      if ($pos === null) throw new Exception("Nível não encontrado.");
      $alvo = $pos + $dir;
      if (isset($lista[$alvo])) {
        db()->beginTransaction();
        try {
          // renumera toda a lista para garantir ordens únicas e sequenciais
          [$lista[$pos], $lista[$alvo]] = [$lista[$alvo], $lista[$pos]];
          $st = db()->prepare("UPDATE tb_niveis_ensino SET ordem=? WHERE id_nivel=?");
          foreach ($lista as $i => $r) $st->execute([$i + 1, (int)$r['id_nivel']]);
          db()->commit();
        } catch (Throwable $ex) {
          db()->rollBack();
          throw $ex;
        }
        audit_log('nivel_reordenado', 'nivel_ensino', $idn, ['posicao' => $pos + 1], ['posicao' => $alvo + 1]);
      }
      $ok = "Ordem atualizada.";
    }

    if ($action === 'excluir') {
      $idn = (int)($_POST['id_nivel'] ?? 0);
      $n = nivel_get($idn);
      if (!$n) throw new Exception("Nível não encontrado.");
      $uso = nivel_em_uso($n['nome']);
      if ($uso > 0) {
        http_response_code(422);
        throw new Exception("Não é possível excluir: {$uso} curso(s) usam o nível \"{$n['nome']}\". Inative-o em vez de excluir.");
      }
      db()->prepare("DELETE FROM tb_niveis_ensino WHERE id_nivel=?")->execute([$idn]);
      audit_log('nivel_excluido', 'nivel_ensino', $idn, ['nome' => $n['nome']], null);
      $ok = "Nível \"{$n['nome']}\" excluído.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$niveis = db()->query("
  SELECT n.*, (SELECT COUNT(*) FROM tb_cursos c WHERE c.nivel_ensino = n.nome) AS n_cursos
  FROM tb_niveis_ensino n
  ORDER BY n.ordem, n.nome
")->fetchAll();
$total = count($niveis);

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Níveis de Ensino</h1>
    <div class="text-muted small">
      Opções do campo "Nível de ensino" ao propor/editar cursos e no filtro do dashboard.
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Novo nível de ensino</h2>
        <form method="post" data-confirm="Cadastrar este nível de ensino?" data-confirm-title="Novo nível" data-confirm-btn="Sim, cadastrar">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <label class="form-label">Nome</label>
          <input class="form-control" name="nome" required minlength="3" maxlength="60" placeholder="Ex.: Ensino Fundamental - Médio">
          <div class="form-text">Entra ativo, no fim da lista. Use as setas para reordenar.</div>
          <button class="btn btn-primary mt-3">Cadastrar</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Níveis cadastrados (<?= $total ?>)</h2>
        <?php if (!$niveis): ?>
          <div class="text-muted small">Nenhum nível cadastrado.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:90px">Ordem</th>
                  <th>Nível</th>
                  <th title="Cursos vinculados">Cursos</th>
                  <th>Situação</th>
                  <th class="text-end">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($niveis as $i => $n): ?>
                  <tr class="<?= !$n['ativo'] ? 'opacity-75' : '' ?>">
                    <td class="text-nowrap">
                      <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="mover">
                        <input type="hidden" name="id_nivel" value="<?= (int)$n['id_nivel'] ?>">
                        <button class="btn btn-sm btn-outline-secondary py-0" name="dir" value="up" title="Subir" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
                        <button class="btn btn-sm btn-outline-secondary py-0" name="dir" value="down" title="Descer" <?= $i === $total - 1 ? 'disabled' : '' ?>>▼</button>
                      </form>
                    </td>
                    <td>
                      <form method="post" class="d-flex gap-2 align-items-center"
                            data-confirm="Renomear este nível?<br>Os cursos vinculados serão atualizados."
                            data-confirm-title="Renomear nível" data-confirm-btn="Sim, renomear">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="renomear">
                        <input type="hidden" name="id_nivel" value="<?= (int)$n['id_nivel'] ?>">
                        <input class="form-control form-control-sm" name="nome"
                               value="<?= htmlspecialchars($n['nome']) ?>" required minlength="3" maxlength="60">
                        <button class="btn btn-sm btn-outline-primary py-0">Salvar</button>
                      </form>
                    </td>
                    <td><span class="badge bg-secondary rounded-pill"><?= (int)$n['n_cursos'] ?></span></td>
                    <td>
                      <?= $n['ativo']
                          ? '<span class="badge bg-success">Ativo</span>'
                          : '<span class="badge bg-warning text-dark">Inativo</span>' ?>
                    </td>
                    <td class="text-end text-nowrap">
                      <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id_nivel" value="<?= (int)$n['id_nivel'] ?>">
                        <button class="btn btn-sm btn-outline-warning py-0"><?= $n['ativo'] ? 'Inativar' : 'Reativar' ?></button>
                      </form>
                      <form method="post" class="d-inline"
                            data-confirm="Excluir o nível <b><?= htmlspecialchars($n['nome']) ?></b>?<br>Esta ação não pode ser desfeita."
                            data-confirm-title="Excluir nível" data-confirm-type="danger" data-confirm-btn="Sim, excluir">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id_nivel" value="<?= (int)$n['id_nivel'] ?>">
                        <button class="btn btn-sm btn-outline-danger py-0"
                                <?= (int)$n['n_cursos'] > 0 ? 'disabled title="Em uso por cursos — inative em vez de excluir"' : '' ?>>Excluir</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="small text-muted mt-2">
          Níveis <b>inativos</b> saem dos formulários de curso, mas os cursos antigos continuam exibindo o valor.
          A exclusão definitiva só é permitida quando nenhum curso usa o nível.
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
