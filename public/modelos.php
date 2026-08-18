<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();
$gerencia = in_array($u['role'], ['TI', 'ADMIN'], true);

$CATS = [
  'TEMPLATE_SLIDES' => 'Template de Slides (Comfortaa)',
  'ESTRUTURA_CURSO' => 'Documento Estrutura do Curso',
  'GUIA'            => 'Guias e Orientações',
  'MINIBIO'         => 'Modelo de MiniBio',
  'AVALIACAO'       => 'Modelos de Avaliação',
  'OUTROS'          => 'Outros Materiais',
];

$erro = null; $ok = null;
if (($_GET['ok'] ?? '') === 'pub') $ok = "Modelo publicado com sucesso.";

// ---- publicar novo modelo (TI/ADMIN) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publicar' && $gerencia) {
  csrf_check();
  try {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'OUTROS';
    $versao = trim($_POST['versao'] ?? '1.0');
    if ($titulo === '') throw new Exception("Informe o título do modelo.");
    if (!isset($CATS[$categoria])) $categoria = 'OUTROS';

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
      throw new Exception("Selecione um arquivo válido.");
    }
    $maxSize = 50 * 1024 * 1024; // 50MB na Biblioteca
    if ($_FILES['arquivo']['size'] > $maxSize) throw new Exception("Arquivo acima de 50MB.");

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['arquivo']['tmp_name']) ?: 'application/octet-stream';
    $allowedMime = [
      'application/pdf',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'application/vnd.ms-powerpoint',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/zip',
      'video/mp4',
      'audio/mpeg',
      'image/jpeg',
      'image/png',
    ];
    if (!in_array($mime, $allowedMime, true)) throw new Exception("Tipo de arquivo não permitido ({$mime}).");

    $base = realpath(__DIR__ . '/../storage');
    if (!$base) throw new Exception("Storage não encontrado.");
    $dir = $base . '/modelos';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $original = $_FILES['arquivo']['name'];
    $ext = pathinfo($original, PATHINFO_EXTENSION);
    $stored = bin2hex(random_bytes(16)) . ($ext ? ".{$ext}" : "");
    if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $dir . '/' . $stored)) {
      throw new Exception("Falha ao gravar o arquivo.");
    }

    $vigente = isset($_POST['vigente']) ? 1 : 0;
    db()->beginTransaction();
    try {
      if ($vigente) {
        // apenas uma versão vigente por título+categoria
        db()->prepare("UPDATE tb_modelos SET vigente=0 WHERE categoria=? AND titulo=?")
          ->execute([$categoria, $titulo]);
      }
      db()->prepare("
        INSERT INTO tb_modelos (titulo, descricao, categoria, versao, vigente, original_name, stored_name, mime_type, file_size, id_user, ativo)
        VALUES (?,?,?,?,?,?,?,?,?,?,1)
      ")->execute([$titulo, $descricao ?: null, $categoria, $versao, $vigente,
                   $original, $stored, $mime, (int)$_FILES['arquivo']['size'], (int)$u['id_user']]);
      db()->commit();
    } catch (Throwable $e) {
      db()->rollBack();
      throw $e;
    }

    audit_log('modelo_publicado', 'modelo', (int)db()->lastInsertId(), null,
      ['titulo' => $titulo, 'categoria' => $categoria, 'versao' => $versao, 'vigente' => $vigente]);

    header("Location: modelos.php?ok=pub");
    exit;
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// ---- ativar/desativar/vigente (TI/ADMIN) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle' && $gerencia) {
  csrf_check();
  try {
    $idm = (int)($_POST['id_modelo'] ?? 0);
    $campo = $_POST['campo'] ?? '';
    if (!in_array($campo, ['ativo', 'vigente'], true)) throw new Exception("Campo inválido.");

    $st = db()->prepare("SELECT * FROM tb_modelos WHERE id_modelo=?");
    $st->execute([$idm]);
    $m = $st->fetch();
    if (!$m) throw new Exception("Modelo não encontrado.");

    $novo = $m[$campo] ? 0 : 1;
    db()->beginTransaction();
    try {
      if ($campo === 'vigente' && $novo === 1) {
        db()->prepare("UPDATE tb_modelos SET vigente=0 WHERE categoria=? AND titulo=?")
          ->execute([$m['categoria'], $m['titulo']]);
      }
      db()->prepare("UPDATE tb_modelos SET {$campo}=? WHERE id_modelo=?")->execute([$novo, $idm]);
      db()->commit();
    } catch (Throwable $e) {
      db()->rollBack();
      throw $e;
    }
    audit_log('modelo_' . $campo . '_' . ($novo ? 'on' : 'off'), 'modelo', $idm, null, ['titulo' => $m['titulo']]);
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// ---- excluir modelo definitivamente (TI/ADMIN) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excluir' && $gerencia) {
  csrf_check();
  try {
    $idm = (int)($_POST['id_modelo'] ?? 0);

    $st = db()->prepare("SELECT * FROM tb_modelos WHERE id_modelo=?");
    $st->execute([$idm]);
    $m = $st->fetch();
    if (!$m) throw new Exception("Modelo não encontrado.");

    db()->prepare("DELETE FROM tb_modelos WHERE id_modelo=?")->execute([$idm]);

    // remove o arquivo físico do storage
    $base = realpath(__DIR__ . '/../storage');
    if ($base) {
      $path = $base . '/modelos/' . $m['stored_name'];
      if (is_file($path)) @unlink($path);
    }

    audit_log('modelo_excluido', 'modelo', $idm,
      ['titulo' => $m['titulo'], 'versao' => $m['versao'], 'arquivo' => $m['original_name']], null);

    $ok = "Modelo \"{$m['titulo']}\" (v{$m['versao']}) excluído definitivamente.";
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

// ---- listagem ----
$sqlAtivo = $gerencia ? "" : "WHERE m.ativo=1";
$modelos = db()->query("
  SELECT m.*, u.nome AS user_nome
  FROM tb_modelos m
  JOIN tb_users u ON u.id_user = m.id_user
  $sqlAtivo
  ORDER BY m.categoria, m.titulo, m.vigente DESC, m.created_at DESC
")->fetchAll();

$porCat = [];
foreach ($modelos as $m) $porCat[$m['categoria']][] = $m;

include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Biblioteca de Modelos</h1>
    <div class="text-muted small">
      Templates e materiais oficiais da Plataforma AutoriaSCS. Use sempre a <b>versão vigente</b>.
    </div>
  </div>
  <a class="btn btn-outline-secondary" href="dashboard.php">Voltar</a>
</div>

<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php if ($gerencia): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <h2 class="h6 mb-3">Publicar novo modelo (TI/ADMIN)</h2>
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="publicar">
        <div class="col-12 col-md-4">
          <label class="form-label small">Título</label>
          <input class="form-control form-control-sm" name="titulo" required maxlength="150"
                 placeholder="Ex.: Template Oficial de Slides 2026">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Categoria</label>
          <select class="form-select form-select-sm" name="categoria">
            <?php foreach ($CATS as $k => $v): ?>
              <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-3 col-md-1">
          <label class="form-label small">Versão</label>
          <input class="form-control form-control-sm" name="versao" value="1.0" maxlength="20">
        </div>
        <div class="col-3 col-md-1 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="vigente" id="vigente" checked>
            <label class="form-check-label small" for="vigente">Vigente</label>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label small">Arquivo (máx. 50MB)</label>
          <input class="form-control form-control-sm" type="file" name="arquivo" required>
        </div>
        <div class="col-12 col-md-9">
          <label class="form-label small">Descrição / orientações de uso</label>
          <input class="form-control form-control-sm" name="descricao" maxlength="255"
                 placeholder="Ex.: Fonte Comfortaa, cores oficiais — não alterar o layout.">
        </div>
        <div class="col-12 col-md-3 d-flex align-items-end">
          <button class="btn btn-primary btn-sm w-100">Publicar</button>
        </div>
      </form>
      <div class="form-text mt-1">
        Ao marcar "Vigente", versões anteriores com o mesmo título deixam de ser vigentes automaticamente.
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (!$porCat): ?>
  <div class="alert alert-info">Nenhum modelo publicado ainda.</div>
<?php endif; ?>

<?php foreach ($CATS as $catKey => $catLabel): if (empty($porCat[$catKey])) continue; ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><?= htmlspecialchars($catLabel) ?></div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Modelo</th>
              <th>Versão</th>
              <th>Publicado</th>
              <th>Tamanho</th>
              <?php if ($gerencia): ?><th>Situação</th><?php endif; ?>
              <th class="text-end">Ação</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($porCat[$catKey] as $m): ?>
              <tr class="<?= !$m['ativo'] ? 'table-secondary' : '' ?>">
                <td>
                  <b><?= htmlspecialchars($m['titulo']) ?></b>
                  <?php if ($m['vigente']): ?><span class="badge bg-success ms-1">Vigente</span><?php endif; ?>
                  <?php if (!$m['ativo']): ?><span class="badge bg-secondary ms-1">Inativo</span><?php endif; ?>
                  <?php if (!empty($m['descricao'])): ?>
                    <div class="small text-muted"><?= htmlspecialchars($m['descricao']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap"><?= htmlspecialchars($m['versao']) ?></td>
                <td class="small text-nowrap">
                  <?= htmlspecialchars($m['created_at']) ?><br>
                  <span class="text-muted">por <?= htmlspecialchars($m['user_nome']) ?></span>
                </td>
                <td class="text-nowrap"><?= number_format($m['file_size']/1024/1024, 2, ',', '.') ?> MB</td>

                <?php if ($gerencia): ?>
                  <td class="text-nowrap">
                    <form method="post" class="d-inline"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id_modelo" value="<?= (int)$m['id_modelo'] ?>">
                      <input type="hidden" name="campo" value="vigente">
                      <button class="btn btn-sm btn-outline-success py-0" title="Alternar vigente"><?= $m['vigente'] ? '★' : '☆' ?></button>
                    </form>
                    <form method="post" class="d-inline"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id_modelo" value="<?= (int)$m['id_modelo'] ?>">
                      <input type="hidden" name="campo" value="ativo">
                      <button class="btn btn-sm btn-outline-secondary py-0"><?= $m['ativo'] ? 'Desativar' : 'Reativar' ?></button>
                    </form>
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('EXCLUIR DEFINITIVAMENTE o modelo &quot;<?= htmlspecialchars($m['titulo']) ?>&quot; (v<?= htmlspecialchars($m['versao']) ?>)?\n\nO arquivo será apagado do servidor e a ação não pode ser desfeita.');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="excluir">
                      <input type="hidden" name="id_modelo" value="<?= (int)$m['id_modelo'] ?>">
                      <button class="btn btn-sm btn-outline-danger py-0">Excluir</button>
                    </form>
                  </td>
                <?php endif; ?>

                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="download_modelo.php?id=<?= (int)$m['id_modelo'] ?>">Baixar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
