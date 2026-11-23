<?php
/** Salva as preferências de acessibilidade/aparência do usuário (V17) em tb_config (chave ui_prefs_<id>). */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/config_repo.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
$u = auth_user_real();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
try { csrf_check(); } catch (Throwable $e) { http_response_code(403); echo '{"ok":false,"erro":"csrf"}'; exit; }

$p = json_decode((string)($_POST['prefs'] ?? ''), true);
if (!is_array($p)) { http_response_code(422); echo '{"ok":false,"erro":"prefs"}'; exit; }
// só as chaves conhecidas, com validação
$out = [
  'tema'        => in_array($p['tema'] ?? 'escuro', ['escuro', 'claro', 'contraste'], true) ? $p['tema'] : 'escuro',
  'bg'          => preg_match('/^#[0-9a-f]{6}$/i', (string)($p['bg'] ?? '')) ? strtolower($p['bg']) : '',
  'fonte'       => max(0, min(3, (int)($p['fonte'] ?? 0))),
  'dislexia'    => !empty($p['dislexia']),
  'espacamento' => !empty($p['espacamento']),
  'links'       => !empty($p['links']),
  'semAnimacao' => !empty($p['semAnimacao']),
  'cursor'      => !empty($p['cursor']),
  'libras'      => !empty($p['libras']),
];
try { cfg_set('ui_prefs_' . (int)$u['id_user'], json_encode($out)); echo json_encode(['ok' => true]); }
catch (Throwable $e) { echo json_encode(['ok' => false, 'erro' => 'Preferências guardadas só neste navegador (tabela tb_config ausente).']); }
