<?php
require_once __DIR__ . '/_admin_top.php';
require_once __DIR__ . '/../../app/perfis_repo.php';

$erro = null; $ok = null;
$u = auth_user();

$FLAGS_LABELS = [
  'admin_total'           => ['Administrador total', 'Acesso completo a tudo, incluindo a área Admin (ignora as demais permissões).'],
  've_todos_cursos'       => ['Vê todos os cursos', 'Enxerga todos os cursos, o Kanban completo, relatórios e exportações.'],
  'propoe_cursos'         => ['Propõe cursos', 'Propõe e edita os próprios cursos (perfil de formador).'],
  'move_kanban'           => ['Move o Kanban', 'Arrasta cards entre colunas no quadro Kanban.'],
  'revisa_cursos'         => ['Revisa cursos', 'Cria apontamentos, recusa com relatório e edita qualquer curso.'],
  'gerencia_modelos'      => ['Gerencia a Biblioteca', 'Publica, desativa e exclui modelos da Biblioteca.'],
  'recebe_email_revisao'  => ['Recebe e-mails de revisão', 'Recebe os avisos da equipe TI (cursos aguardando análise, resumos).'],
  'recebe_email_insercao' => ['Recebe e-mails de inserção', 'Recebe os avisos de inserção na plataforma (MB Estúdios).'],
];

function perfil_flags_from_post(): array {
  $out = [];
  foreach (PERFIL_FLAGS as $f) $out[$f] = isset($_POST[$f]) ? 1 : 0;
  return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
      $nome   = trim($_POST['nome'] ?? '');
      $desc   = trim($_POST['descricao'] ?? '');
      if (!preg_match('/^[A-Z0-9_]{2,30}$/', $codigo)) {
        throw new Exception("Código inválido: use 2 a 30 caracteres (letras maiúsculas, números e _). Ex.: COORDENADOR");
      }
      if ($nome === '') throw new Exception("Informe o nome do perfil.");

      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_perfis WHERE codigo=?");
      $dup->execute([$codigo]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe um perfil com o código {$codigo}.");

      $flags = perfil_flags_from_post();
      $cols = implode(',', array_keys($flags));
      $ph   = implode(',', array_fill(0, count($flags), '?'));
      db()->prepare("INSERT INTO tb_perfis (codigo, nome, descricao, {$cols}, is_sistema, ativo)
                     VALUES (?,?,?,{$ph},0,1)")
        ->execute(array_merge([$codigo, $nome, $desc ?: null], array_values($flags)));

      audit_log('perfil_criado', 'perfil', (int)db()->lastInsertId(), null,
        array_merge(['codigo' => $codigo, 'nome' => $nome], $flags));
      $ok = "Perfil \"{$codigo}\" criado. Configure as transições dele em Transições por perfil.";
    }

    if ($action === 'update') {
      $idp  = (int)($_POST['id_perfil'] ?? 0);
      $nome = trim($_POST['nome'] ?? '');
      $desc = trim($_POST['descricao'] ?? '');
      $ativo = isset($_POST['ativo']) ? 1 : 0;
      if ($nome === '') throw new Exception("Informe o nome do perfil.");

      $stA = db()->prepare("SELECT * FROM tb_perfis WHERE id_perfil=?");
      $stA->execute([$idp]);
      $antes = $stA->fetch();
      if (!$antes) throw new Exception("Perfil não encontrado.");

      $flags = perfil_flags_from_post();

      // salvaguardas do perfil ADMIN e do próprio acesso
      if ($antes['codigo'] === 'ADMIN') {
        $flags['admin_total'] = 1;
        $ativo = 1;
      }
      if ($antes['codigo'] === $u['role'] && !$flags['admin_total']) {
        throw new Exception("Você não pode remover o acesso administrador do seu próprio perfil.");
      }

      $sets = ['nome=?', 'descricao=?', 'ativo=?'];
      $vals = [$nome, $desc ?: null, $ativo];
      foreach ($flags as $f => $v) { $sets[] = "{$f}=?"; $vals[] = $v; }
      $vals[] = $idp;
      db()->prepare("UPDATE tb_perfis SET " . implode(',', $sets) . " WHERE id_perfil=?")->execute($vals);

      audit_log('perfil_editado', 'perfil', $idp,
        array_intersect_key($antes, array_flip(array_merge(['nome','ativo'], PERFIL_FLAGS))),
        array_merge(['nome' => $nome, 'ativo' => $ativo], $flags));
      $ok = "Perfil \"{$antes['codigo']}\" atualizado.";
    }

    if ($action === 'delete') {
      $idp = (int)($_POST['id_perfil'] ?? 0);
      $stA = db()->prepare("SELECT * FROM tb_perfis WHERE id_perfil=?");
      $stA->execute([$idp]);
      $p = $stA->fetch();
      if (!$p) throw new Exception("Perfil não encontrado.");
      if ($p['is_sistema']) throw new Exception("Perfis do sistema (PROFESSOR, TI, MB, ADMIN) não podem ser excluídos.");

      $nu = db()->prepare("SELECT COUNT(*) n FROM tb_users WHERE role=?");
      $nu->execute([$p['codigo']]);
      if ((int)$nu->fetch()['n'] > 0) {
        throw new Exception("Não é possível excluir: existem usuários com este perfil. Mova-os para outro perfil antes.");
      }
      $nt = db()->prepare("SELECT COUNT(*) n FROM tb_status_transicoes WHERE role=?");
      $nt->execute([$p['codigo']]);
      if ((int)$nt->fetch()['n'] > 0) {
        throw new Exception("Não é possível excluir: existem transições do Kanban para este perfil. Remova-as em Transições por perfil.");
      }

      db()->prepare("DELETE FROM tb_perfis WHERE id_perfil=?")->execute([$idp]);
      audit_log('perfil_excluido', 'perfil', $idp, ['codigo' => $p['codigo'], 'nome' => $p['nome']], null);
      $ok = "Perfil \"{$p['codigo']}\" excluído.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$perfis = db()->query("
  SELECT p.*,
         (SELECT COUNT(*) FROM tb_users us WHERE us.role = p.codigo) AS n_users,
         (SELECT COUNT(*) FROM tb_status_transicoes t WHERE t.role = p.codigo) AS n_trans
  FROM tb_perfis p
  ORDER BY p.is_sistema DESC, p.codigo
")->fetchAll();

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Perfis de Acesso</h1>
    <div class="text-muted small">
      Crie perfis personalizados (ex.: COORDENADOR) e defina o que cada um pode fazer.
      Os perfis do sistema não podem ser excluídos.
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<!-- Novo perfil -->
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-3">Novo perfil</h2>
    <form method="post" class="row g-2"
          data-confirm="Criar este novo perfil de acesso?" data-confirm-title="Novo perfil" data-confirm-btn="Sim, criar">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-6 col-md-3">
        <label class="form-label small">Código (único, maiúsculas)</label>
        <input class="form-control form-control-sm" name="codigo" required maxlength="30"
               placeholder="Ex.: COORDENADOR" style="text-transform:uppercase">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label small">Nome de exibição</label>
        <input class="form-control form-control-sm" name="nome" required maxlength="60" placeholder="Ex.: Coordenador(a) Pedagógico(a)">
      </div>
      <div class="col-12 col-md-5">
        <label class="form-label small">Descrição</label>
        <input class="form-control form-control-sm" name="descricao" maxlength="255">
      </div>

      <div class="col-12">
        <div class="row g-2 mt-1">
          <?php foreach ($FLAGS_LABELS as $flag => [$label, $desc]): ?>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="<?= $flag ?>" id="novo_<?= $flag ?>">
                <label class="form-check-label" for="novo_<?= $flag ?>" title="<?= htmlspecialchars($desc) ?>">
                  <?= htmlspecialchars($label) ?>
                </label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-12">
        <button class="btn btn-primary btn-sm">Criar perfil</button>
      </div>
    </form>
  </div>
</div>

<!-- Perfis existentes -->
<?php foreach ($perfis as $p): ?>
  <div class="card shadow-sm mb-3 <?= !$p['ativo'] ? 'opacity-75' : '' ?>">
    <div class="card-body">
      <form method="post"
            data-confirm="Salvar as alterações do perfil <b><?= htmlspecialchars($p['codigo']) ?></b>?<br>As permissões passam a valer imediatamente para todos os usuários deste perfil."
            data-confirm-title="Salvar perfil" data-confirm-btn="Sim, salvar">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id_perfil" value="<?= (int)$p['id_perfil'] ?>">

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
          <div>
            <span class="badge bg-primary fs-6"><?= htmlspecialchars($p['codigo']) ?></span>
            <?php if ($p['is_sistema']): ?><span class="badge bg-secondary">Sistema</span><?php endif; ?>
            <?php if (!$p['ativo']): ?><span class="badge bg-warning text-dark">Inativo</span><?php endif; ?>
            <span class="badge bg-light text-dark border" title="Usuários com este perfil">
              <?= (int)$p['n_users'] ?> usuário(s)
            </span>
            <span class="badge bg-light text-dark border" title="Transições do Kanban deste perfil">
              <?= (int)$p['n_trans'] ?> transição(ões)
            </span>
          </div>

          <div class="d-flex gap-2">
            <div class="form-check form-switch mt-1" title="Perfil ativo (usuários de perfis inativos não conseguem entrar em novas sessões)">
              <input class="form-check-input" type="checkbox" name="ativo"
                     <?= $p['ativo'] ? 'checked' : '' ?> <?= $p['codigo'] === 'ADMIN' ? 'disabled' : '' ?>>
              <label class="form-check-label small">Ativo</label>
            </div>
            <button class="btn btn-sm btn-outline-primary">Salvar</button>
          </div>
        </div>

        <div class="row g-2 mb-2">
          <div class="col-12 col-md-4">
            <label class="form-label small">Nome de exibição</label>
            <input class="form-control form-control-sm" name="nome" required maxlength="60"
                   value="<?= htmlspecialchars($p['nome']) ?>">
          </div>
          <div class="col-12 col-md-8">
            <label class="form-label small">Descrição</label>
            <input class="form-control form-control-sm" name="descricao" maxlength="255"
                   value="<?= htmlspecialchars($p['descricao'] ?? '') ?>">
          </div>
        </div>

        <div class="row g-2">
          <?php foreach ($FLAGS_LABELS as $flag => [$label, $desc]): ?>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="<?= $flag ?>"
                       id="p<?= (int)$p['id_perfil'] ?>_<?= $flag ?>"
                       <?= !empty($p[$flag]) ? 'checked' : '' ?>
                       <?= ($p['codigo'] === 'ADMIN' && $flag === 'admin_total') ? 'disabled' : '' ?>>
                <label class="form-check-label small" for="p<?= (int)$p['id_perfil'] ?>_<?= $flag ?>"
                       title="<?= htmlspecialchars($desc) ?>">
                  <?= htmlspecialchars($label) ?>
                </label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </form>

      <?php if (!$p['is_sistema']): ?>
        <form method="post" class="mt-2"
              data-confirm="Excluir o perfil <b><?= htmlspecialchars($p['codigo']) ?></b>?<br>Só é possível excluir perfis sem usuários e sem transições."
              data-confirm-title="Excluir perfil" data-confirm-type="danger" data-confirm-btn="Sim, excluir">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id_perfil" value="<?= (int)$p['id_perfil'] ?>">
          <button class="btn btn-sm btn-outline-danger"
                  <?= ((int)$p['n_users'] > 0 || (int)$p['n_trans'] > 0) ? 'disabled title="Possui usuários ou transições vinculados"' : '' ?>>
            Excluir perfil
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<div class="small text-muted">
  <b>Administrador total</b> ignora as demais permissões (acesso completo).
  Alterações valem imediatamente. O perfil ADMIN é protegido: sempre ativo e sempre administrador.
  Após criar um perfil que participa do fluxo, configure as movimentações dele em
  <a href="transicoes.php">Transições por perfil</a>.
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
