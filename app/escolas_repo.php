<?php
/**
 * Escolas (unidades escolares) e listas auxiliares para campos com
 * autocompletar (datalist): filtros do dashboard e formulários de curso.
 */
require_once __DIR__ . '/db.php';

/** Nomes das escolas ativas, em ordem alfabética. */
function escolas_ativas(): array {
  try {
    return array_map(
      fn($r) => $r['nome'],
      db()->query("SELECT nome FROM tb_escolas WHERE ativo=1 ORDER BY nome")->fetchAll()
    );
  } catch (Throwable $e) {
    return []; // tabela ainda não migrada (upgrade_v6.sql)
  }
}

/** Nomes dos formadores ativos (perfis com propoe_cursos). */
function formadores_nomes(): array {
  try {
    return array_map(
      fn($r) => $r['nome'],
      db()->query("
        SELECT u.nome
        FROM tb_users u
        JOIN tb_perfis p ON p.codigo = u.role
        WHERE u.ativo=1 AND p.propoe_cursos=1 AND p.admin_total=0
        ORDER BY u.nome
      ")->fetchAll()
    );
  } catch (Throwable $e) {
    try {
      return array_map(
        fn($r) => $r['nome'],
        db()->query("SELECT nome FROM tb_users WHERE ativo=1 AND role='PROFESSOR' ORDER BY nome")->fetchAll()
      );
    } catch (Throwable $e2) {
      return [];
    }
  }
}

/** Nomes de todas as escolas (ativas e inativas) — para buscas administrativas. */
function escolas_todas_nomes(): array {
  try {
    return array_map(
      fn($r) => $r['nome'],
      db()->query("SELECT nome FROM tb_escolas ORDER BY nome")->fetchAll()
    );
  } catch (Throwable $e) {
    return []; // tabela ainda não migrada (upgrade_v6.sql)
  }
}

/** Nomes de todos os usuários ativos — para filtros administrativos (usuários/auditoria). */
function usuarios_nomes(): array {
  try {
    return array_map(
      fn($r) => $r['nome'],
      db()->query("SELECT nome FROM tb_users WHERE ativo=1 ORDER BY nome")->fetchAll()
    );
  } catch (Throwable $e) {
    return [];
  }
}

/** Renderiza um <datalist> com as opções informadas. */
function datalist_html(string $id, array $opcoes): string {
  $html = '<datalist id="' . htmlspecialchars($id) . '">';
  foreach ($opcoes as $o) {
    $html .= '<option value="' . htmlspecialchars($o) . '"></option>';
  }
  return $html . '</datalist>';
}
