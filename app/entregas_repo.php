<?php
/**
 * Entrega de materiais do curso, por módulo (V11 — ordem livre).
 *
 * O módulo "Geral" (0) e os Módulos 1..8 têm listas próprias de categorias
 * (Admin → Categorias de entrega). O formador envia na ordem que preferir;
 * porém TODAS as categorias OBRIGATÓRIAS precisam estar entregues antes de o
 * curso avançar para um status que "exige entregas" (Admin → Status).
 * Categorias opcionais podem ser registradas como "sem material"
 * (tb_curso_dispensas). O envio do VÍDEO de um módulo só é liberado após a
 * aprovação (TI) do SLIDE do mesmo módulo.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/categorias_repo.php';
require_once __DIR__ . '/audit.php';

/** Exceção de regra de negócio com a lista de pendências (HTTP 422). */
class EntregasPendentesException extends Exception {
  public array $pendencias = [];
  public function __construct(string $msg, array $pendencias) { parent::__construct($msg, 422); $this->pendencias = $pendencias; }
}

/**
 * Categorias ATIVAS do módulo, na ordem de entrega (cadastro Admin → Categorias).
 * $incluirInativas=true serve para ordenar/exibir arquivos antigos cuja
 * categoria foi inativada/excluída.
 */
function entregas_categorias(int $modulo, bool $incluirInativas = false): array {
  $out = [];
  foreach (categorias_lista($incluirInativas, categoria_escopo_modulo($modulo)) as $c) {
    $out[] = ['nome' => $c['nome'], 'obrigatoria' => (bool)$c['obrigatoria'], 'ativo' => (bool)$c['ativo'],
              'tipo_especial' => $c['tipo_especial'] ?? 'NENHUM'];
  }
  return $out;
}

/** Nome(s) da(s) categoria(s) com papel especial (SLIDE / VIDEO) no escopo do módulo. */
function entregas_categorias_por_tipo(int $modulo, string $tipo): array {
  return array_values(array_map(fn($c) => $c['nome'],
    array_filter(entregas_categorias($modulo, true), fn($c) => ($c['tipo_especial'] ?? 'NENHUM') === $tipo)));
}

/** O slide do módulo já foi aprovado pela TI? (libera o envio do vídeo — item 12) */
function entregas_slide_aprovado(int $idCurso, int $modulo): bool {
  $slides = entregas_categorias_por_tipo($modulo, 'SLIDE');
  if (!$slides) return true; // sem categoria de slide cadastrada: nada a liberar
  try {
    $in = implode(',', array_fill(0, count($slides), '?'));
    $st = db()->prepare("SELECT COUNT(*) n FROM tb_curso_files WHERE id_curso=? AND modulo=? AND aprovado=1 AND categoria IN ($in)");
    $st->execute(array_merge([$idCurso, $modulo], $slides));
    return (int)$st->fetch()['n'] > 0;
  } catch (Throwable $e) {
    return true; // upgrade_v11.sql ainda não executado: sem bloqueio
  }
}

/**
 * Estado das categorias de um módulo. Cada categoria recebe:
 *   feita        — já tem arquivo enviado
 *   dispensada   — registrada como "sem material" (só opcionais)
 *   pendente     — obrigatória e ainda sem arquivo
 *   habilitada   — pode receber upload agora (ordem livre; vídeo depende do slide)
 *   bloqueio     — motivo textual quando não habilitada (ex.: aguardando slide)
 *   atual        — sugestão: primeira não concluída (apenas orientação visual)
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

  $slideOk = ($modulo > 0) ? entregas_slide_aprovado($idCurso, $modulo) : true;
  $temAtual = false;
  foreach ($cats as &$c) {
    $c['feita']      = in_array($c['nome'], $enviadas, true);
    $c['dispensada'] = !$c['feita'] && in_array($c['nome'], $dispensadas, true);
    $c['pendente']   = $c['obrigatoria'] && !$c['feita'];
    $c['bloqueio']   = null;
    $c['habilitada'] = true;
    if (($c['tipo_especial'] ?? 'NENHUM') === 'VIDEO' && !$slideOk) {
      $c['habilitada'] = false;
      $c['bloqueio']   = 'O envio deste vídeo ficará disponível após a aprovação dos slides correspondentes.';
    }
    $concluida = $c['feita'] || $c['dispensada'];
    $c['atual'] = !$temAtual && !$concluida && $c['habilitada'];
    if ($c['atual']) $temAtual = true;
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
 * Módulos cuja entrega é exigida: Geral (0) + os módulos mínimos da carga
 * horária (10h→1, 20h→3, 30h→5, 40h→7, conforme Guia 01: 1-2, 3-4, 5-6, 7-8)
 * + qualquer módulo em que o formador já tenha iniciado a entrega.
 */
function entregas_modulos_exigidos(int $idCurso, int $cargaHoraria): array {
  $min = [10 => 1, 20 => 3, 30 => 5, 40 => 7][$cargaHoraria] ?? 1;
  $mods = range(0, $min);
  $st = db()->prepare("SELECT DISTINCT modulo FROM tb_curso_files WHERE id_curso=? AND modulo>0");
  $st->execute([$idCurso]);
  foreach ($st->fetchAll() as $r) $mods[] = (int)$r['modulo'];
  try {
    $sd = db()->prepare("SELECT DISTINCT modulo FROM tb_curso_dispensas WHERE id_curso=? AND modulo>0");
    $sd->execute([$idCurso]);
    foreach ($sd->fetchAll() as $r) $mods[] = (int)$r['modulo'];
  } catch (Throwable $e) {}
  $mods = array_values(array_unique(array_filter($mods, fn($m) => $m >= 0 && $m <= 8)));
  sort($mods);
  return $mods;
}

/**
 * Documentos OBRIGATÓRIOS ainda não entregues (itens 9/10).
 * Retorna lista de ['modulo' => n, 'categoria' => nome, 'rotulo' => 'Módulo 2 — Slide'].
 */
function entregas_pendentes(int $idCurso, int $cargaHoraria): array {
  $out = [];
  foreach (entregas_modulos_exigidos($idCurso, $cargaHoraria) as $m) {
    foreach (entregas_estado($idCurso, $m) as $c) {
      if ($c['pendente']) {
        $out[] = ['modulo' => $m, 'categoria' => $c['nome'],
                  'rotulo' => ($m === 0 ? 'Geral' : "Módulo {$m}") . ' — ' . $c['nome']];
      }
    }
  }
  return $out;
}

/**
 * Posição da categoria na sequência do módulo (0 = primeira).
 * Categorias legadas/desconhecidas vão para o fim (99).
 */
function entregas_ordem_categoria(int $modulo, string $categoria): int {
  foreach (entregas_categorias($modulo, true) as $i => $c) {
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
  $st = db()->prepare("SELECT f.*, ua.nome AS aprovado_por_nome FROM tb_curso_files f LEFT JOIN tb_users ua ON ua.id_user = f.aprovado_por WHERE f.id_curso=? ORDER BY f.created_at");
  try {
    $st->execute([$idCurso]);
  } catch (Throwable $e) { // upgrade_v11.sql ainda não executado (sem coluna aprovado_por)
    $st = db()->prepare("SELECT * FROM tb_curso_files WHERE id_curso=? ORDER BY created_at");
    $st->execute([$idCurso]);
  }
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
 * Retorna 'ok', 'slide' (vídeo aguardando aprovação do slide) ou 'categoria' (nome inválido/inativa).
 */
function entrega_pode_receber(int $idCurso, int $modulo, string $categoria): string {
  foreach (entregas_estado($idCurso, $modulo) as $c) {
    if ($c['nome'] === $categoria) return $c['habilitada'] ? 'ok' : 'slide';
  }
  return 'categoria';
}

/** O arquivo é de uma categoria com papel de SLIDE (pode ser aprovado pela TI)? */
function arquivo_eh_slide(array $f): bool {
  return in_array($f['categoria'], entregas_categorias_por_tipo((int)$f['modulo'], 'SLIDE'), true);
}

/** TI/ADMIN aprova (ou revoga a aprovação de) um slide — libera o vídeo do módulo. */
function arquivo_aprovar(int $idFile, array $user, bool $aprovar): array {
  if (empty($user['role']) || !perfil_flag($user['role'], 'revisa_cursos')) {
    http_response_code(403); throw new Exception("Somente a equipe de TI/ADMIN aprova slides.");
  }
  $st = db()->prepare("SELECT * FROM tb_curso_files WHERE id_file=?");
  $st->execute([$idFile]);
  $f = $st->fetch();
  if (!$f) { http_response_code(404); throw new Exception("Arquivo não encontrado."); }
  if (!arquivo_eh_slide($f)) { http_response_code(422); throw new Exception("Apenas arquivos da categoria de slides podem ser aprovados."); }
  if ((bool)$f['aprovado'] === $aprovar) { http_response_code(422); throw new Exception($aprovar ? "Este slide já está aprovado." : "Este slide não está aprovado."); }

  db()->prepare("UPDATE tb_curso_files SET aprovado=?, aprovado_por=?, aprovado_em=? WHERE id_file=?")
    ->execute([$aprovar ? 1 : 0, $aprovar ? (int)$user['id_user'] : null, $aprovar ? date('Y-m-d H:i:s') : null, $idFile]);
  audit_log($aprovar ? 'slide_aprovado' : 'slide_aprovacao_revogada', 'curso', (int)$f['id_curso'],
    ['id_file' => $idFile, 'aprovado' => (int)$f['aprovado']],
    ['id_file' => $idFile, 'aprovado' => $aprovar ? 1 : 0, 'arquivo' => $f['original_name'], 'modulo' => (int)$f['modulo']]);
  return $f;
}
