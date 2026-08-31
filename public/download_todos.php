<?php
/**
 * Baixa todos os arquivos do curso em um único ZIP, na ordem oficial de
 * entrega (módulo Geral, Módulos 1..8; dentro de cada módulo, a sequência
 * das categorias). As pastas e arquivos recebem prefixos numéricos para a
 * MB Estúdios enxergar a ordem correta ao extrair.
 */
require_once __DIR__ . '/../app/session.php';
session_boot();
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/curso_repo.php';
require_once __DIR__ . '/../app/entregas_repo.php';
require_once __DIR__ . '/../app/audit.php';

require_login();
$u = auth_user();

$id = (int)($_GET['id'] ?? 0);
$curso = curso_get($id);
if (!$curso) { http_response_code(404); exit("Curso não encontrado."); }

// mesma regra de visualização do curso: equipe ou o(a) próprio(a) formador(a)
if (!is_staff() && (int)$curso['id_professor'] !== (int)$u['id_user']) {
  http_response_code(403); exit("Sem permissão.");
}

if (!class_exists('ZipArchive')) {
  http_response_code(500);
  exit("Extensão ZIP indisponível no servidor. Contate a TI (ti.cecape@scseduca.com.br).");
}

$files = entregas_arquivos_ordenados($id);
if (!$files) {
  header("Location: curso_detalhe.php?id={$id}&err=semarquivos");
  exit;
}

$base = realpath(__DIR__ . '/../storage');

// nome seguro para pastas/arquivos dentro do ZIP
function zip_safe(string $s): string {
  $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $s);
  return trim($s) !== '' ? trim($s) : 'arquivo';
}

$tmpZip = tempnam(sys_get_temp_dir(), 'zipcurso');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
  http_response_code(500); exit("Falha ao montar o ZIP.");
}

$usados = [];
$incluidos = 0;
foreach ($files as $f) {
  $path = $base . "/cursos/{$id}/{$f['stored_name']}";
  if (!file_exists($path)) continue; // ausente no storage: pula sem quebrar o pacote

  $mod = (int)$f['modulo'];
  $pasta = $mod === 0 ? '00 - Geral' : sprintf('%02d - Módulo %d', $mod, $mod);
  $ordem = entregas_ordem_categoria($mod, $f['categoria']);
  $entrada = sprintf(
    '%s/%02d - %s - %s',
    $pasta,
    $ordem + 1,
    zip_safe($f['categoria']),
    zip_safe($f['original_name'])
  );

  // evita colisão quando a mesma categoria tem arquivos com o mesmo nome
  $final = $entrada; $n = 2;
  while (isset($usados[$final])) {
    $ext  = pathinfo($entrada, PATHINFO_EXTENSION);
    $stem = $ext !== '' ? substr($entrada, 0, -strlen($ext) - 1) : $entrada;
    $final = $stem . " ({$n})" . ($ext !== '' ? ".{$ext}" : '');
    $n++;
  }
  $usados[$final] = true;

  $zip->addFile($path, $final);
  $incluidos++;
}
$zip->close();

if ($incluidos === 0) {
  @unlink($tmpZip);
  header("Location: curso_detalhe.php?id={$id}&err=semarquivos");
  exit;
}

audit_log('arquivos_baixados_zip', 'curso', $id, null, ['arquivos' => $incluidos]);

// nome do pacote = identificação oficial "Nome do curso - Formador(a) - Carga horária"
$nomeZip = zip_safe(curso_identificacao($curso)) . '.zip';
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($tmpZip));
header('Content-Disposition: attachment; filename="' . $nomeZip . '"');
readfile($tmpZip);
@unlink($tmpZip);
exit;
