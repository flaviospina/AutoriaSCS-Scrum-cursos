<?php
/**
 * Categorias de entrega de material (tb_categorias, V10) — cadastro administrável.
 *
 * escopo GERAL  = módulo Geral (0); escopo MODULO = Módulos 1..8 (lista única).
 * O vínculo com os arquivos é pelo NOME (tb_curso_files.categoria e
 * tb_curso_dispensas.categoria): renomear atualiza os registros vinculados;
 * inativar apenas retira a categoria dos novos envios.
 * Enquanto upgrade_v10.sql não for executado, a lista padrão do código é usada.
 */
require_once __DIR__ . '/db.php';

const CATEGORIA_ESCOPOS = ['GERAL' => 'Geral (módulo 0)', 'MODULO' => 'Módulos 1 a 8'];

/** Lista padrão (fallback antes da migração V10) — idêntica ao fluxo V7. */
function categorias_padrao(): array {
  $def = [
    'GERAL' => [
      ['Apresentação do(s) Formador(es)', 1], ['Apresentação do Curso', 1], ['Objetivos', 1],
      ['Atividade Avaliativa Geral', 0], ['Referência Bibliográfica', 1],
    ],
    'MODULO' => [
      ['Apresentação do Módulo', 1], ['Slide', 1], ['Vídeo', 1], ['Anexo', 1],
      ['Texto Complementar', 0], ['Atividade Avaliativa', 0],
    ],
  ];
  $rows = [];
  foreach ($def as $escopo => $lista) {
    foreach ($lista as $i => [$nome, $obr]) {
      $rows[] = ['id_categoria' => 0, 'escopo' => $escopo, 'nome' => $nome,
                 'obrigatoria' => $obr, 'ordem' => $i + 1, 'ativo' => 1];
    }
  }
  return $rows;
}

/** Todas as categorias (cache por requisição). $todos=true inclui inativas. */
function categorias_lista(bool $todos = false, ?string $escopo = null): array {
  static $cache = null;
  if ($cache === null) {
    try {
      $cache = db()->query("SELECT * FROM tb_categorias ORDER BY escopo, ordem, nome")->fetchAll();
    } catch (Throwable $e) {
      $cache = categorias_padrao(); // tabela ainda não migrada (upgrade_v10.sql)
    }
  }
  return array_values(array_filter($cache, function ($c) use ($todos, $escopo) {
    if (!$todos && !(int)$c['ativo']) return false;
    if ($escopo !== null && $c['escopo'] !== $escopo) return false;
    return true;
  }));
}

/** Escopo correspondente ao módulo do curso. */
function categoria_escopo_modulo(int $modulo): string {
  return $modulo === 0 ? 'GERAL' : 'MODULO';
}

function categoria_get(int $id): ?array {
  $st = db()->prepare("SELECT * FROM tb_categorias WHERE id_categoria=?");
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

/**
 * Uso da categoria: arquivos enviados e dispensas ("sem material") nos módulos
 * do escopo. Retorna ['arquivos' => n, 'dispensas' => n, 'cursos' => n].
 */
function categoria_em_uso(string $escopo, string $nome): array {
  $condMod = $escopo === 'GERAL' ? 'modulo = 0' : 'modulo > 0';
  $f = db()->prepare("SELECT COUNT(*) n, COUNT(DISTINCT id_curso) c FROM tb_curso_files WHERE categoria=? AND $condMod");
  $f->execute([$nome]);
  $rf = $f->fetch();
  try {
    $d = db()->prepare("SELECT COUNT(*) n, COUNT(DISTINCT id_curso) c FROM tb_curso_dispensas WHERE categoria=? AND $condMod");
    $d->execute([$nome]);
    $rd = $d->fetch();
  } catch (Throwable $e) {
    $rd = ['n' => 0, 'c' => 0]; // upgrade_v7.sql ainda não executado
  }
  return [
    'arquivos'  => (int)$rf['n'],
    'dispensas' => (int)$rd['n'],
    'cursos'    => (int)$rf['c'] + (int)$rd['c'],
  ];
}
