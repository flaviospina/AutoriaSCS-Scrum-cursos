<?php
/**
 * Perfis dinâmicos (tb_perfis) — permissões por perfil, administráveis pelo Admin.
 *
 * Fallback: se a tabela ainda não existir (banco não migrado para a V4),
 * os 4 perfis clássicos funcionam com as permissões equivalentes.
 */
require_once __DIR__ . '/db.php';

const PERFIL_FLAGS = [
  'admin_total', 've_todos_cursos', 'propoe_cursos', 'move_kanban',
  'revisa_cursos', 'gerencia_modelos', 'recebe_email_revisao', 'recebe_email_insercao',
];

/** Todos os perfis (por padrão só ativos), indexados por código. */
function perfis_all(bool $onlyActive = true): array {
  static $cacheAtivos = null, $cacheTodos = null;
  if ($onlyActive && $cacheAtivos !== null) return $cacheAtivos;
  if (!$onlyActive && $cacheTodos !== null) return $cacheTodos;

  $out = [];
  try {
    $where = $onlyActive ? "WHERE ativo=1" : "";
    foreach (db()->query("SELECT * FROM tb_perfis $where ORDER BY is_sistema DESC, codigo") as $p) {
      $out[$p['codigo']] = $p;
    }
  } catch (Throwable $e) {
    // banco não migrado: perfis clássicos equivalentes
    $legacy = [
      'PROFESSOR' => ['propoe_cursos' => 1],
      'TI'    => ['ve_todos_cursos' => 1, 'move_kanban' => 1, 'revisa_cursos' => 1,
                  'gerencia_modelos' => 1, 'recebe_email_revisao' => 1],
      'MB'    => ['ve_todos_cursos' => 1, 'recebe_email_insercao' => 1],
      'ADMIN' => array_fill_keys(PERFIL_FLAGS, 1),
    ];
    foreach ($legacy as $cod => $flags) {
      $row = array_fill_keys(PERFIL_FLAGS, 0);
      $row = array_merge($row, $flags);
      $row['codigo'] = $cod;
      $row['nome'] = $cod;
      $row['is_sistema'] = 1;
      $row['ativo'] = 1;
      $out[$cod] = $row;
    }
  }

  if ($onlyActive) $cacheAtivos = $out; else $cacheTodos = $out;
  return $out;
}

/** Dados/permissões de um perfil pelo código (inclui inativos, para usuários antigos). */
function perfil_get(string $codigo): ?array {
  $ativos = perfis_all();
  if (isset($ativos[$codigo])) return $ativos[$codigo];
  $todos = perfis_all(false);
  return $todos[$codigo] ?? null;
}

/** Um perfil possui a permissão? (admin_total concede todas) */
function perfil_flag(string $codigo, string $flag): bool {
  $p = perfil_get($codigo);
  if (!$p) return false;
  if (!empty($p['admin_total'])) return true;
  return !empty($p[$flag]);
}
