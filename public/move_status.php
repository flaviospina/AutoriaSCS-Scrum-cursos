<?php
require_once __DIR__ . '/../app/session.php';
session_boot();
header('Content-Type: application/json');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/csrf.php';

require_login();
$u = auth_user();

if (!perm('move_kanban')) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Apenas TI/ADMIN podem mover no Kanban.']);
  exit;
}

$sent = $_POST['csrf_token'] ?? '';
if (!$sent || !hash_equals(csrf_token(), $sent)) {
  http_response_code(419);
  echo json_encode(['ok'=>false,'error'=>'Sessão expirada. Recarregue a página.']);
  exit;
}

$id  = (int)($_POST['id_curso'] ?? 0);
$to  = trim($_POST['to'] ?? '');
$obs = trim($_POST['obs'] ?? '');
$dataPub = trim($_POST['data_publicacao'] ?? '') ?: null;

try {
  $carga = ($_POST['carga_horaria'] ?? '') !== '' ? (int)$_POST['carga_horaria'] : null;
  curso_transition($id, $u, $to, $obs ?: 'Movimentação via Kanban', $dataPub, $carga);
  echo json_encode(['ok'=>true]);
} catch (EntregasPendentesException $e) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'pendencias'=>array_column($e->pendencias, 'rotulo')]);
} catch (Throwable $e) {
  http_response_code(http_response_code() >= 400 ? http_response_code() : 400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
