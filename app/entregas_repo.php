<?php
/**
 * Fluxo ordenado de entrega de materiais do curso, por módulo.
 *
 * O módulo "Geral" (0) e os Módulos 1..8 têm listas próprias de categorias,
 * entregues obrigatoriamente NA ORDEM definida (é a ordem em que a MB
 * Estúdios baixa o conteúdo para subir na plataforma): uma categoria só é
 * liberada quando a anterior foi concluída — arquivo enviado ou, nas
 * categorias opcionais, registrada como "sem material" (tb_curso_dispensas).
 */
require_once __DIR__ . '/db.php';

/** Categorias do módulo, na ordem de entrega. */
function entregas_categorias(int $modulo): array {
  if ($modulo === 0) { // módulo Geral
    return [
      ['nome' => 'Apresentação do(s) Formador(es)', 'obrigatoria' => true],
      ['nome' => 'Apresentação do Curso',           'obrigatoria' => true],
      ['nome' => 'Objetivos',                       'obrigatoria' => true],
      ['nome' => 'Atividade Avaliativa Geral',      'obrigatoria' => false],
      ['nome' => 'Referência Bibliográfica',        'obrigatoria' => true],
    ];
  }
  return [ // Módulos 1..8
    ['nome' => 'Apresentação do Módulo', 'obrigatoria' => true],
    ['nome' => 'Slide',                  'obrigatoria' => true],
    ['nome' => 'Vídeo',                  'obrigatoria' => true],
    ['nome' => 'Anexo',                  'obrigatoria' => true],
    ['nome' => 'Texto Complementar',     'obrigatoria' => false],
    ['nome' => 'Atividade Avaliativa',   'obrigatoria' => false],
  ];
}

/**
 * Estado do fluxo de um módulo. Cada categoria recebe:
 *   feita       — já tem arquivo enviado
 *   dispensada  — registrada como "sem material"
 *   atual       — é a próxima entrega da sequência
 *   habilitada  — pode receber upload agora (concluídas continuam aceitando
 *                 arquivos adicionais; futuras ficam bloqueadas)
 */
function entregas_estado(int $idCurso, int $modulo): array {
  $cats = entregas_categorias($modulo);

  $st = db()->prepare("SELECT DISTINCT categoria FROM tb_curso_files WHERE id_curso=? AND modulo=?");
  $st->execute([$idCurso, $modulo]);
  $enviadas = array_column($st->fetchAll(), 'categoria');

  try {
    $sd = db()->prepare("SELECT categoria FROM tb_curso_dispensas WHERE id_curso=? AND modulo=?");
    $sd->execute([$idCurso, $modulo]);
    $dispensadas = array_column($sd->fetchAll(), 'categoria');
  } catch (Throwable $e) {
    $dispensadas = []; // upgrade_v7.sql ainda não executado
  }

  $liberada = true; // a primeira categoria começa liberada
  foreach ($cats as &$c) {
    $c['feita']      = in_array($c['nome'], $enviadas, true);
    $c['dispensada'] = !$c['feita'] && in_array($c['nome'], $dispensadas, true);
    $concluida       = $c['feita'] || $c['dispensada'];
    $c['atual']      = $liberada && !$concluida;
    $c['habilitada'] = $c['feita'] || $c['atual'];
    if (!$concluida) $liberada = false;
  }
  unset($c);
  return $cats;
}

/** Estado de todos os módulos (0..8) — alimenta o JavaScript da página. */
function entregas_estado_completo(int $idCurso): array {
  $out = [];
  for ($m = 0; $m <= 8; $m++) $out[$m] = entregas_estado($idCurso, $m);
  return $out;
}

/**
 * Posição da categoria na sequência do módulo (0 = primeira).
 * Categorias legadas/desconhecidas vão para o fim (99).
 */
function entregas_ordem_categoria(int $modulo, string $categoria): int {
  foreach (entregas_categorias($modulo) as $i => $c) {
    if ($c['nome'] === $categoria) return $i;
  }
  return 99;
}

/**
 * Arquivos do curso na ordem oficial de entrega — módulo (Geral, 1..8) e,
 * dentro dele, a sequência das categorias. É a ordem em que a MB Estúdios
 * baixa o conteúdo para subir na plataforma.
 */
function entregas_arquivos_ordenados(int $idCurso): array {
  $st = db()->prepare("SELECT * FROM tb_curso_files WHERE id_curso=? ORDER BY created_at");
  $st->execute([$idCurso]);
  $files = $st->fetchAll();
  usort($files, function ($a, $b) {
    $cmp = (int)$a['modulo'] <=> (int)$b['modulo'];
    if ($cmp !== 0) return $cmp;
    $cmp = entregas_ordem_categoria((int)$a['modulo'], $a['categoria'])
       <=> entregas_ordem_categoria((int)$b['modulo'], $b['categoria']);
    if ($cmp !== 0) return $cmp;
    return strcmp($a['created_at'], $b['created_at']);
  });
  return $files;
}

/**
 * Valida se a categoria pode receber upload agora.
 * Retorna 'ok', 'ordem' (fora da sequência) ou 'categoria' (nome inválido).
 */
function entrega_pode_receber(int $idCurso, int $modulo, string $categoria): string {
  foreach (entregas_estado($idCurso, $modulo) as $c) {
    if ($c['nome'] === $categoria) return $c['habilitada'] ? 'ok' : 'ordem';
  }
  return 'categoria';
}
