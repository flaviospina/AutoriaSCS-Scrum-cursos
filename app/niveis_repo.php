<?php
/**
 * Níveis de ensino (tb_niveis_ensino, V10) — cadastro administrável.
 *
 * O vínculo com os cursos é pelo NOME (tb_cursos.nivel_ensino), como nos
 * status do Kanban: renomear um nível atualiza os cursos; inativar apenas
 * esconde o nível dos formulários (cursos antigos continuam exibindo o valor).
 * Enquanto upgrade_v10.sql não for executado, a lista padrão do código é usada.
 */
require_once __DIR__ . '/db.php';

/** Lista padrão (usada apenas como fallback antes da migração V10). */
function niveis_ensino_padrao(): array {
  return [
    'Educação Infantil',
    'Ensino Fundamental - Anos Iniciais',
    'Ensino Fundamental - Anos Finais',
    'Ensino Fundamental - Médio',
    'Ensino Médio',
    'Formação Transversal / Complementar',
  ];
}

/** Registros completos, em ordem de exibição. $todos=true inclui inativos. */
function niveis_lista(bool $todos = false): array {
  static $cache = [];
  $k = $todos ? 1 : 0;
  if (isset($cache[$k])) return $cache[$k];
  try {
    $where = $todos ? '' : 'WHERE ativo=1';
    $rows = db()->query("SELECT * FROM tb_niveis_ensino $where ORDER BY ordem, nome")->fetchAll();
  } catch (Throwable $e) {
    // tabela ainda não migrada (upgrade_v10.sql): comportamento anterior
    $rows = [];
    foreach (niveis_ensino_padrao() as $i => $n) {
      $rows[] = ['id_nivel' => 0, 'nome' => $n, 'ordem' => $i + 1, 'ativo' => 1];
    }
  }
  return $cache[$k] = $rows;
}

/** Apenas os nomes, em ordem. $todos=true inclui inativos (filtros/relatórios). */
function niveis_nomes(bool $todos = false): array {
  return array_map(fn($r) => $r['nome'], niveis_lista($todos));
}

/**
 * Opções para o <select> de um curso: níveis ativos + o valor atual do curso
 * (mesmo que inativo ou legado), para o valor gravado nunca "sumir" da tela.
 */
function niveis_opcoes_para(?string $atual): array {
  $nomes = niveis_nomes(false);
  if ($atual !== null && $atual !== '' && !in_array($atual, $nomes, true)) $nomes[] = $atual;
  return $nomes;
}

function nivel_get(int $id): ?array {
  $st = db()->prepare("SELECT * FROM tb_niveis_ensino WHERE id_nivel=?");
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

/** Quantidade de cursos que usam o nível (pelo nome). */
function nivel_em_uso(string $nome): int {
  $st = db()->prepare("SELECT COUNT(*) n FROM tb_cursos WHERE nivel_ensino=?");
  $st->execute([$nome]);
  return (int)$st->fetch()['n'];
}

/** Nome válido para gravar em um curso: vazio, um nível ativo ou o valor já gravado. */
function nivel_valido_para_curso(string $nome, ?string $atual = null): bool {
  if ($nome === '') return true;
  if ($atual !== null && $nome === $atual) return true;
  return in_array($nome, niveis_nomes(false), true);
}
