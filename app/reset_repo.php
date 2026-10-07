<?php
/**
 * Zerar dados (V13) — rotina de limpeza da base para a entrada em produção.
 *
 * O QUE É APAGADO (sempre): cursos e tudo o que depende deles — entregas,
 * dispensas, vídeos (versões, marcações, respostas), apontamentos (histórico e
 * manifestações), checklists respondidos, coautores, links e histórico de status;
 * além dos arquivos físicos em storage/cursos e storage/videos.
 *
 * O QUE É PRESERVADO (sempre): colunas do Kanban, status, transições, perfis,
 * escolas, níveis de ensino, categorias de entrega, itens de checklist e os
 * usuários administradores.
 *
 * OPCIONAL (marcado pelo admin): fila de notificações, auditoria, biblioteca de
 * modelos e usuários não administradores.
 *
 * SEGURANÇA: antes de apagar, gera uma cópia dos dados (storage/backups/
 * reset-AAAAMMDD-HHMMSS.sql) e MOVE as pastas de arquivos para
 * storage/backups/reset-.../arquivos (nada é destruído de imediato; o admin
 * apaga a pasta pelo cPanel quando tiver certeza).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/perfis_repo.php';

/** Tabelas de dados dos cursos, na ordem de limpeza (filhas antes das mães). */
const RESET_TABELAS_CURSOS = [
  'tb_curso_links', 'tb_curso_professores', 'tb_curso_checklist_respostas',
  'tb_apontamento_manifestacoes', 'tb_apontamento_historico', 'tb_curso_apontamentos',
  'tb_video_respostas', 'tb_video_marcacoes', 'tb_video_versoes', 'tb_videos',
  'tb_curso_dispensas', 'tb_curso_files', 'tb_curso_status_history', 'tb_curso_checklist',
  'tb_cursos',
];

const RESET_FRASE = 'ZERAR DADOS';

function reset_tabela_existe(string $t): bool {
  $st = db()->prepare("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $st->execute([$t]);
  return (int)$st->fetch()['n'] > 0;
}

function reset_count(string $t, string $where = ''): int {
  if (!reset_tabela_existe($t)) return 0;
  return (int)db()->query("SELECT COUNT(*) n FROM `$t` $where")->fetch()['n'];
}

function reset_dir_storage(): string { return realpath(__DIR__ . '/../storage'); }
function reset_dir_backups(): string {
  $d = reset_dir_storage() . '/backups';
  if (!is_dir($d)) mkdir($d, 0755, true);
  return $d;
}

/** Tamanho total (bytes) e quantidade de arquivos de uma pasta. */
function reset_tamanho_pasta(string $dir): array {
  $bytes = 0; $n = 0;
  if (!is_dir($dir)) return [0, 0];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) { if ($f->isFile() && $f->getFilename() !== '.gitkeep') { $bytes += $f->getSize(); $n++; } }
  return [$bytes, $n];
}

function reset_fmt_bytes(int $b): string {
  if ($b >= 1073741824) return number_format($b / 1073741824, 2, ',', '.') . ' GB';
  if ($b >= 1048576)    return number_format($b / 1048576, 1, ',', '.') . ' MB';
  if ($b >= 1024)       return number_format($b / 1024, 0, ',', '.') . ' KB';
  return $b . ' B';
}

/** Códigos de perfil com admin_total (usuários preservados). */
function reset_perfis_admin(): array {
  $out = [];
  foreach (perfis_all(false) as $cod => $p) if (!empty($p['admin_total'])) $out[] = $cod;
  return $out ?: ['ADMIN'];
}

/** Panorama do que existe hoje, para a tela de confirmação. */
function reset_contagens(): array {
  $adm = reset_perfis_admin();
  $in  = implode(',', array_map(fn($c) => db()->quote($c), $adm));
  [$bCursos, $nCursos] = reset_tamanho_pasta(reset_dir_storage() . '/cursos');
  [$bVideos, $nVideos] = reset_tamanho_pasta(reset_dir_storage() . '/videos');
  [$bMod, $nMod]       = reset_tamanho_pasta(reset_dir_storage() . '/modelos');
  return [
    'cursos'        => reset_count('tb_cursos'),
    'entregas'      => reset_count('tb_curso_files'),
    'videos'        => reset_count('tb_video_versoes'),
    'apontamentos'  => reset_count('tb_curso_apontamentos'),
    'historico'     => reset_count('tb_curso_status_history'),
    'notificacoes'  => reset_count('tb_notificacoes'),
    'auditoria'     => reset_count('tb_audit_log'),
    'modelos'       => reset_count('tb_modelos'),
    'usuarios_adm'  => reset_count('tb_users', "WHERE role IN ($in)"),
    'usuarios_out'  => reset_count('tb_users', "WHERE role NOT IN ($in)"),
    'perfis_admin'  => $adm,
    'arquivos'      => ['cursos' => [$bCursos, $nCursos], 'videos' => [$bVideos, $nVideos], 'modelos' => [$bMod, $nMod]],
    'config'        => [
      'Colunas do Kanban'     => reset_count('tb_kanban_colunas'),
      'Status'                => reset_count('tb_status'),
      'Transições'            => reset_count('tb_status_transicoes'),
      'Perfis'                => reset_count('tb_perfis'),
      'Escolas'               => reset_count('tb_escolas'),
      'Níveis de ensino'      => reset_count('tb_niveis_ensino'),
      'Categorias de entrega' => reset_count('tb_categorias'),
      'Itens de checklist'    => reset_count('tb_checklist_itens'),
    ],
  ];
}

/** Grava INSERTs de uma tabela no arquivo de backup (em lotes, sem carregar tudo na memória). */
function reset_dump_tabela($fh, string $t): int {
  if (!reset_tabela_existe($t)) return 0;
  $total = 0;
  fwrite($fh, "\n-- ---- $t ----\n");
  $st = db()->query("SELECT * FROM `$t`");
  $lote = [];
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    if ($total === 0) {
      $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($row)));
      fwrite($fh, "-- INSERT INTO `$t` ($cols) VALUES ...\n");
    }
    $vals = array_map(fn($v) => $v === null ? 'NULL' : db()->quote((string)$v), array_values($row));
    $lote[] = '(' . implode(', ', $vals) . ')';
    $total++;
    if (count($lote) >= 200) {
      fwrite($fh, "INSERT INTO `$t` VALUES\n" . implode(",\n", $lote) . ";\n");
      $lote = [];
    }
  }
  if ($lote) fwrite($fh, "INSERT INTO `$t` VALUES\n" . implode(",\n", $lote) . ";\n");
  return $total;
}

/**
 * Executa a limpeza. $opcoes: notificacoes, auditoria, modelos, usuarios (bool).
 * Retorna relatório: backup, tabelas zeradas (com contagens), pastas movidas.
 */
function reset_executar(array $opcoes, int $idAdmin): array {
  $ts     = date('Ymd-His');
  $dirBk  = reset_dir_backups() . "/reset-$ts";
  if (!is_dir($dirBk)) mkdir($dirBk, 0755, true);
  $sqlBk  = "$dirBk/dados.sql";
  $rel    = ['backup_sql' => $sqlBk, 'backup_dir' => $dirBk, 'tabelas' => [], 'pastas' => [], 'usuarios_removidos' => 0];

  $adm = reset_perfis_admin();
  $in  = implode(',', array_map(fn($c) => db()->quote($c), $adm));

  // 1) tabelas a zerar
  $tabelas = RESET_TABELAS_CURSOS;
  if (!empty($opcoes['notificacoes'])) $tabelas[] = 'tb_notificacoes';
  if (!empty($opcoes['modelos']))      $tabelas[] = 'tb_modelos';
  if (!empty($opcoes['auditoria']))    $tabelas[] = 'tb_audit_log';

  // 2) backup em SQL (dados das tabelas que serão zeradas + usuários, se forem removidos)
  $fh = fopen($sqlBk, 'w');
  if (!$fh) throw new RuntimeException("Não foi possível criar o backup em $sqlBk. Verifique a permissão de escrita em storage/backups.");
  fwrite($fh, "-- AutoriaSCS • backup automático antes de ZERAR DADOS\n-- Gerado em " . date('d/m/Y H:i:s') . " pelo usuário #$idAdmin\n-- Restauração: importe este arquivo no phpMyAdmin (as tabelas devem existir).\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
  foreach (array_reverse($tabelas) as $t) $rel['tabelas'][$t] = reset_dump_tabela($fh, $t);
  if (!empty($opcoes['usuarios'])) {
    fwrite($fh, "\n-- ---- tb_users (não administradores) ----\n");
    $st = db()->query("SELECT * FROM tb_users WHERE role NOT IN ($in)");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $vals = array_map(fn($v) => $v === null ? 'NULL' : db()->quote((string)$v), array_values($row));
      fwrite($fh, "INSERT INTO `tb_users` VALUES (" . implode(', ', $vals) . ");\n");
    }
  }
  fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
  fclose($fh);

  // 3) limpeza das tabelas (TRUNCATE reinicia a numeração dos IDs; a auditoria
  //    mantém a numeração para que o backup possa ser reimportado sem conflito)
  $proxAudit = !empty($opcoes['auditoria']) && reset_tabela_existe('tb_audit_log')
    ? (int)db()->query("SELECT COALESCE(MAX(id_audit),0)+1 FROM tb_audit_log")->fetchColumn() : 0;
  db()->exec("SET FOREIGN_KEY_CHECKS=0");
  try {
    foreach ($tabelas as $t) {
      if (!reset_tabela_existe($t)) continue;
      db()->exec("TRUNCATE TABLE `$t`");
    }
    if ($proxAudit > 1) db()->exec("ALTER TABLE tb_audit_log AUTO_INCREMENT = $proxAudit");
    // usuários não administradores (modelos remanescentes passam para o admin que executou)
    if (!empty($opcoes['usuarios'])) {
      if (reset_tabela_existe('tb_modelos') && empty($opcoes['modelos'])) {
        db()->prepare("UPDATE tb_modelos SET id_user=? WHERE id_user NOT IN (SELECT id_user FROM tb_users WHERE role IN ($in))")->execute([$idAdmin]);
      }
      $st = db()->prepare("DELETE FROM tb_users WHERE role NOT IN ($in) AND id_user <> ?");
      $st->execute([$idAdmin]);
      $rel['usuarios_removidos'] = $st->rowCount();
    }
  } finally {
    db()->exec("SET FOREIGN_KEY_CHECKS=1");
  }

  // 4) arquivos físicos: mover (não apagar) para a pasta do backup
  $mover = ['cursos', 'videos'];
  if (!empty($opcoes['modelos'])) $mover[] = 'modelos';
  $base = reset_dir_storage();
  foreach ($mover as $p) {
    $orig = "$base/$p";
    if (!is_dir($orig)) continue;
    [$bytes, $n] = reset_tamanho_pasta($orig);
    if ($n === 0) { $rel['pastas'][$p] = [0, 0, 'já estava vazia']; continue; }
    $dest = "$dirBk/arquivos/$p";
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    if (@rename($orig, $dest)) {
      mkdir($orig, 0755, true);
      @touch("$orig/.gitkeep");
      $rel['pastas'][$p] = [$bytes, $n, 'movida para o backup'];
    } else {
      $rel['pastas'][$p] = [$bytes, $n, 'NÃO foi possível mover (apague manualmente pelo cPanel)'];
    }
  }
  // cache do token do Drive pode ficar; sessões de outros usuários são encerradas
  foreach (glob("$base/sessions/sess_*") ?: [] as $s) { if (basename($s) !== 'sess_' . session_id()) @unlink($s); }

  audit_log('base_zerada', 'sistema', null, null, [
    'opcoes' => array_keys(array_filter($opcoes)),
    'tabelas' => $rel['tabelas'],
    'usuarios_removidos' => $rel['usuarios_removidos'],
    'backup' => $sqlBk,
  ], $idAdmin);
  return $rel;
}

/** Backups já existentes (para a tela e para o admin saber o que pode apagar). */
function reset_backups_existentes(): array {
  $out = [];
  foreach (glob(reset_dir_backups() . '/reset-*', GLOB_ONLYDIR) ?: [] as $d) {
    [$bytes, $n] = reset_tamanho_pasta($d);
    $out[] = ['pasta' => basename($d), 'bytes' => $bytes, 'arquivos' => $n, 'quando' => date('d/m/Y H:i', filemtime($d))];
  }
  usort($out, fn($a, $b) => strcmp($b['pasta'], $a['pasta']));
  return $out;
}
