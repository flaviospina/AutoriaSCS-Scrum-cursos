<?php
require_once __DIR__ . '/_admin_top.php';

$erro = null; $ok = null;
require_once __DIR__ . '/../../app/perfis_repo.php';
require_once __DIR__ . '/../../app/escolas_repo.php';
$perfis = perfis_all();               // perfis ativos (dinâmicos, tela Admin > Perfis)
$roles = array_keys($perfis);
$u = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $nome  = trim($_POST['nome'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $role  = $_POST['role'] ?? 'PROFESSOR';
      $senha = $_POST['senha'] ?? '';

      if ($nome === '' || $email === '') throw new Exception("Nome e e-mail são obrigatórios.");
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("E-mail inválido.");
      if (!in_array($role, $roles, true)) throw new Exception("Perfil inválido.");
      if (strlen($senha) < 6) throw new Exception("A senha deve ter pelo menos 6 caracteres.");

      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_users WHERE email=?");
      $dup->execute([$email]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe usuário com este e-mail.");

      db()->prepare("INSERT INTO tb_users (nome, email, senha_hash, role, ativo) VALUES (?,?,?,?,1)")
        ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $role]);
      audit_log('usuario_criado', 'user', (int)db()->lastInsertId(), null,
        ['nome' => $nome, 'email' => $email, 'role' => $role]);
      $ok = "Usuário \"{$nome}\" criado.";
    }

    if ($action === 'update') {
      $idu   = (int)($_POST['id_user'] ?? 0);
      $nome  = trim($_POST['nome'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $role  = $_POST['role'] ?? 'PROFESSOR';
      $ativo = isset($_POST['ativo']) ? 1 : 0;

      if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Dados inválidos.");
      if (!in_array($role, $roles, true)) throw new Exception("Perfil inválido.");

      // não permitir remover o próprio acesso de administrador
      if ($idu === (int)$u['id_user'] && (!perfil_flag($role, 'admin_total') || !$ativo)) {
        throw new Exception("Você não pode remover seu próprio acesso de administrador.");
      }

      $dup = db()->prepare("SELECT COUNT(*) n FROM tb_users WHERE email=? AND id_user<>?");
      $dup->execute([$email, $idu]);
      if ((int)$dup->fetch()['n'] > 0) throw new Exception("Já existe outro usuário com este e-mail.");

      $stA = db()->prepare("SELECT nome, email, role, ativo FROM tb_users WHERE id_user=?");
      $stA->execute([$idu]);
      $antes = $stA->fetch() ?: null;

      db()->prepare("UPDATE tb_users SET nome=?, email=?, role=?, ativo=? WHERE id_user=?")
        ->execute([$nome, $email, $role, $ativo, $idu]);
      audit_log('usuario_editado', 'user', $idu, $antes,
        ['nome' => $nome, 'email' => $email, 'role' => $role, 'ativo' => $ativo]);
      $ok = "Usuário atualizado.";
    }

    if ($action === 'reset_senha') {
      $idu   = (int)($_POST['id_user'] ?? 0);
      $senha = $_POST['senha'] ?? '';
      if (strlen($senha) < 6) throw new Exception("A nova senha deve ter pelo menos 6 caracteres.");
      db()->prepare("UPDATE tb_users SET senha_hash=? WHERE id_user=?")
        ->execute([password_hash($senha, PASSWORD_DEFAULT), $idu]);
      audit_log('usuario_senha_redefinida', 'user', $idu);
      $ok = "Senha redefinida.";
    }

    // V20: ações administrativas sobre o usuário (motivo obrigatório, auditadas)
    if (in_array($action, ['transferir', 'encerrar_sessoes', 'forcar_senha', 'desbloquear'], true)) {
      require_once __DIR__ . '/../../app/curso_repo.php';
      require_once __DIR__ . '/../../app/admin_repo.php';
      $idu = (int)($_POST['id_user'] ?? 0); $motivo = (string)($_POST['motivo'] ?? ''); $real = auth_user_real();
      switch ($action) {
        case 'transferir': $n = usuario_transferir_cursos($idu, (int)($_POST['transferir_para'] ?? 0), $motivo, $real); $ok = "{$n} curso(s) transferido(s)."; break;
        case 'encerrar_sessoes': usuario_sessoes_encerrar($idu, $motivo, $real); $ok = 'Sessões do usuário encerradas: ele precisará entrar novamente.'; break;
        case 'forcar_senha': usuario_forcar_senha($idu, $motivo, $real); $ok = 'O usuário terá de definir uma nova senha no próximo acesso.'; break;
        case 'desbloquear': usuario_desbloquear($idu, $motivo, $real); $ok = 'Login desbloqueado.'; break;
      }
    }

    // V18: exclusão (anonimizada) com transferência dos cursos; sessões do usuário são encerradas
    if ($action === 'excluir') {
      require_once __DIR__ . '/../../app/curso_repo.php';
      $idu = (int)($_POST['id_user'] ?? 0);
      $para = (int)($_POST['transferir_para'] ?? 0) ?: null;
      usuario_excluir($idu, (string)($_POST['motivo'] ?? ''), auth_user_real(), $para);
      $ok = "Usuário excluído. Os dados pessoais foram anonimizados e o histórico de ações foi preservado.";
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

if (!empty($_GET['ok'])) $ok = mb_substr((string)$_GET['ok'], 0, 200);
$v20 = users_v20();
$fRole = trim($_GET['role'] ?? '');
$fQ    = trim($_GET['q'] ?? '');
$params = []; $where = [];
if ($fRole !== '' && in_array($fRole, $roles, true)) { $where[] = "role=?"; $params[] = $fRole; }
if ($fQ !== '') { $where[] = "(nome LIKE ? OR email LIKE ?)"; $params[] = "%$fQ%"; $params[] = "%$fQ%"; }
$sqlWhere = $where ? "WHERE " . implode(" AND ", $where) : "";

$st = db()->prepare("
  SELECT us.*, (SELECT COUNT(*) FROM tb_cursos c WHERE c.id_professor = us.id_user) AS n_cursos
  FROM tb_users us $sqlWhere ORDER BY us.nome
");
$st->execute($params);
$usuarios = $st->fetchAll();

include __DIR__ . '/../_layout_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Usuários</h1>
    <div class="text-muted small">Formadores (PROFESSOR), equipe TI, MB Estúdios e administradores.</div>
  </div>
  <a class="btn btn-outline-secondary" href="index.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-3">Novo usuário</h2>
    <form method="post" class="row g-2 align-items-end"
          data-confirm="Criar este novo usuário?" data-confirm-title="Novo usuário" data-confirm-btn="Sim, criar">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-12 col-md-3">
        <label class="form-label small">Nome</label>
        <input class="form-control" name="nome" required maxlength="120">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small">E-mail</label>
        <input class="form-control" type="email" name="email" required maxlength="160">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Perfil</label>
        <select class="form-select" name="role">
          <?php foreach ($roles as $r): ?>
            <option value="<?= $r ?>"><?= $r ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small">Senha inicial</label>
        <input class="form-control" type="text" name="senha" required minlength="6" placeholder="mín. 6 caracteres">
      </div>
      <div class="col-12 col-md-2">
        <button class="btn btn-primary w-100">Adicionar</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2">
      <div class="col-6 col-md-3">
        <select class="form-select form-select-sm" name="role">
          <option value="">Todos os perfis</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= $r ?>" <?= $fRole===$r?'selected':'' ?>><?= $r ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-4">
        <input class="form-control form-control-sm" name="q" list="dlUsuarios"
               value="<?= htmlspecialchars($fQ) ?>" placeholder="Nome ou e-mail..."
               autocomplete="off">
        <?= datalist_html('dlUsuarios', usuarios_nomes()) ?>
      </div>
      <div class="col-12 col-md-2 d-flex gap-2">
        <button class="btn btn-primary btn-sm">Filtrar</button>
        <a class="btn btn-outline-secondary btn-sm" href="usuarios.php">Limpar</a>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Usuário</th>
            <th>Perfil</th>
            <th title="Cursos como formador">Cursos</th>
            <th>Ativo</th>
            <th class="text-end">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($usuarios as $us): ?>
            <tr>
              <td colspan="4">
                <form method="post" class="row g-2 align-items-center"
                      data-confirm="Salvar as alterações do usuário <b><?= htmlspecialchars($us['nome']) ?></b>?"
                      data-confirm-title="Salvar usuário" data-confirm-btn="Sim, salvar">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">

                  <div class="col-12 col-md-3">
                    <input class="form-control form-control-sm" name="nome" value="<?= htmlspecialchars($us['nome']) ?>" required>
                  </div>
                  <div class="col-12 col-md-3">
                    <input class="form-control form-control-sm" type="email" name="email" value="<?= htmlspecialchars($us['email']) ?>" required>
                  </div>
                  <div class="col-4 col-md-2">
                    <select class="form-select form-select-sm" name="role">
                      <?php foreach ($roles as $r): ?>
                        <option value="<?= $r ?>" <?= $us['role']===$r?'selected':'' ?>><?= $r ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-2 col-md-1 text-center">
                    <span class="badge bg-secondary rounded-pill"><?= (int)$us['n_cursos'] ?></span>
                    <?php if ($v20 && !empty($us['bloqueado_ate']) && strtotime($us['bloqueado_ate']) > time()): ?><br><span class="badge bg-danger" title="Login bloqueado até <?= htmlspecialchars($us['bloqueado_ate']) ?>">🔒 bloqueado</span><?php endif; ?>
                    <?php if ($v20 && !empty($us['senha_trocar'])): ?><br><span class="badge bg-warning text-dark" title="Deve trocar a senha no próximo acesso">🔑 trocar senha</span><?php endif; ?>
                  </div>
                  <div class="col-3 col-md-1">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" name="ativo" <?= $us['ativo'] ? 'checked' : '' ?>>
                    </div>
                  </div>
                  <div class="col-12 col-md-2 text-end">
                    <button class="btn btn-sm btn-outline-primary">Salvar</button>
                  </div>
                </form>
              </td>

              <td class="text-end text-nowrap">
                <?php if (!empty($us['excluido_em'])): ?>
                  <span class="badge bg-danger" title="Excluído em <?= htmlspecialchars($us['excluido_em']) ?>">Excluído</span>
                <?php else: ?>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#senha<?= (int)$us['id_user'] ?>">Redefinir senha</button>
                <?php if (is_admin_real() && (int)$us['id_user'] !== (int)$u['id_user']): ?>
                  <div class="btn-group">
                    <button class="btn btn-sm btn-outline-info dropdown-toggle" data-bs-toggle="dropdown">Mais</button>
                    <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:280px">
                      <?php if (!perfil_flag($us['role'], 'admin_total') && $us['ativo']): ?>
                        <li><form method="post" action="../agir_como.php" data-confirm="Agir como <b><?= htmlspecialchars($us['nome']) ?></b>?<br><small>Você passa a executar o sistema com as permissões deste usuário; cada ação fica registrada como você agindo em nome dele. Uma faixa vermelha fica visível até encerrar.</small>" data-confirm-title="Agir como" data-confirm-type="danger" data-confirm-btn="Sim, agir como" data-motivo="1">
                          <?= csrf_field() ?><input type="hidden" name="action" value="iniciar"><input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">
                          <button class="dropdown-item">🎭 Agir como este usuário</button></form></li>
                      <?php endif; ?>
                      <?php if ($v20): ?>
                        <li><form method="post" data-confirm="Encerrar todas as sessões abertas de <b><?= htmlspecialchars($us['nome']) ?></b>? Ele será desconectado agora." data-confirm-title="Encerrar sessões" data-confirm-type="danger" data-confirm-btn="Sim, encerrar" data-motivo="1">
                          <?= csrf_field() ?><input type="hidden" name="action" value="encerrar_sessoes"><input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">
                          <button class="dropdown-item">⛔ Encerrar sessões abertas</button></form></li>
                        <li><form method="post" data-confirm="Exigir que <b><?= htmlspecialchars($us['nome']) ?></b> troque a senha no próximo acesso?" data-confirm-title="Exigir troca de senha" data-confirm-btn="Sim, exigir" data-motivo="1">
                          <?= csrf_field() ?><input type="hidden" name="action" value="forcar_senha"><input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">
                          <button class="dropdown-item">🔑 Exigir troca de senha</button></form></li>
                        <?php if (!empty($us['bloqueado_ate']) && strtotime($us['bloqueado_ate']) > time()): ?>
                          <li><form method="post" data-confirm="Desbloquear o login de <b><?= htmlspecialchars($us['nome']) ?></b> agora?" data-confirm-title="Desbloquear" data-confirm-btn="Sim, desbloquear" data-motivo="1">
                            <?= csrf_field() ?><input type="hidden" name="action" value="desbloquear"><input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">
                            <button class="dropdown-item">🔓 Desbloquear login</button></form></li>
                        <?php endif; ?>
                      <?php else: ?>
                        <li><span class="dropdown-item-text small text-muted">Sessões, troca de senha e bloqueio exigem a migração V20 (Diagnóstico).</span></li>
                      <?php endif; ?>
                      <?php if ((int)$us['n_cursos'] > 0): ?>
                        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#transf<?= (int)$us['id_user'] ?>">🔁 Transferir os <?= (int)$us['n_cursos'] ?> curso(s) para outro formador</button></li>
                      <?php endif; ?>
                    </ul>
                  </div>
                  <?php if ((int)$us['n_cursos'] > 0): ?>
                  <div class="modal fade" id="transf<?= (int)$us['id_user'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog"><form class="modal-content text-start" method="post">
                      <?= csrf_field() ?><input type="hidden" name="action" value="transferir"><input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">
                      <div class="modal-header"><h5 class="modal-title">Transferir cursos — <?= htmlspecialchars($us['nome']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                      <div class="modal-body">
                        <p class="small">Os <b><?= (int)$us['n_cursos'] ?></b> curso(s) em que este usuário é responsável passam para o(a) formador(a) escolhido(a). O usuário continua ativo. Os envolvidos recebem e-mail.</p>
                        <label class="form-label small">Novo(a) responsável</label>
                        <select class="form-select form-select-sm mb-2" name="transferir_para" required><option value="">Selecione</option>
                          <?php foreach ($usuarios as $o): if ((int)$o['id_user'] === (int)$us['id_user'] || !$o['ativo'] || !empty($o['excluido_em'])) continue; ?><option value="<?= (int)$o['id_user'] ?>"><?= htmlspecialchars($o['nome']) ?> (<?= htmlspecialchars($o['role']) ?>)</option><?php endforeach; ?>
                        </select>
                        <label class="form-label small">Motivo (obrigatório, fica na auditoria)</label>
                        <textarea class="form-control form-control-sm" name="motivo" rows="2" required minlength="10"></textarea>
                      </div>
                      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Transferir</button></div>
                    </form></div>
                  </div>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#excluir<?= (int)$us['id_user'] ?>">Excluir</button>
                  <div class="modal fade" id="excluir<?= (int)$us['id_user'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                      <form class="modal-content text-start" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">
                        <div class="modal-header">
                          <h5 class="modal-title">Excluir usuário — <?= htmlspecialchars($us['nome']) ?></h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                          <p class="small">O usuário deixa de acessar o sistema; nome e e-mail são anonimizados (LGPD) e o histórico de ações é preservado na auditoria. As coautorias são removidas.</p>
                          <?php if ((int)$us['n_cursos'] > 0): ?>
                            <label class="form-label small">Este usuário é responsável por <b><?= (int)$us['n_cursos'] ?></b> curso(s). Transferir para:</label>
                            <select class="form-select form-select-sm mb-2" name="transferir_para" required>
                              <option value="">Selecione o(a) novo(a) responsável</option>
                              <?php foreach ($usuarios as $o): if ((int)$o['id_user'] === (int)$us['id_user'] || !$o['ativo'] || !empty($o['excluido_em'])) continue; ?>
                                <option value="<?= (int)$o['id_user'] ?>"><?= htmlspecialchars($o['nome']) ?> (<?= htmlspecialchars($o['role']) ?>)</option>
                              <?php endforeach; ?>
                            </select>
                          <?php endif; ?>
                          <label class="form-label small">Motivo (obrigatório, fica na auditoria)</label>
                          <textarea class="form-control form-control-sm" name="motivo" rows="2" required minlength="10"></textarea>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                          <button class="btn btn-danger">Excluir usuário</button>
                        </div>
                      </form>
                    </div>
                  </div>
                <?php endif; ?>

                <div class="modal fade" id="senha<?= (int)$us['id_user'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                    <form class="modal-content text-start" method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="reset_senha">
                      <input type="hidden" name="id_user" value="<?= (int)$us['id_user'] ?>">
                      <div class="modal-header">
                        <h5 class="modal-title">Redefinir senha — <?= htmlspecialchars($us['nome']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <div class="modal-body">
                        <label class="form-label small">Nova senha</label>
                        <input class="form-control" type="text" name="senha" required minlength="6">
                        <div class="form-text">Comunique a nova senha ao usuário por canal seguro e oriente a troca no primeiro acesso.</div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-warning">Redefinir</button>
                      </div>
                    </form>
                  </div>
                </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../_layout_bottom.php'; ?>
