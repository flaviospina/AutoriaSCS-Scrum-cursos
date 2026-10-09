<?php
/**
 * V13 — Envio agrupado dos e-mails de coautores (chamado via fetch pela página
 * do curso ao fim da contagem regressiva de 20 s).
 * Resposta JSON: {enviado:bool, nomes:[], restante:seg, responsavel:string}
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/curso_repo.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
$u = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['erro' => 'Método inválido']); exit; }
try { csrf_check(); } catch (Throwable $e) { http_response_code(403); echo json_encode(['erro' => 'Sessão expirada. Recarregue a página.']); exit; }

$id = (int)($_POST['id_curso'] ?? 0);
$curso = $id ? curso_get($id) : null;
if (!$curso) { http_response_code(404); echo json_encode(['erro' => 'Curso não encontrado']); exit; }
if (!curso_pode_gerir_professores($curso, $u)) { http_response_code(403); echo json_encode(['erro' => 'Sem permissão']); exit; }

$r = curso_coautores_notificar($id, $u, false);
$r['responsavel'] = $curso['professor_nome'];
echo json_encode($r, JSON_UNESCAPED_UNICODE);
