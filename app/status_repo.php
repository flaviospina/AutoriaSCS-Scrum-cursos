<?php
/**
 * Repositório do fluxo Kanban dinâmico.
 *
 * Colunas, status e transições vivem no banco (tb_kanban_colunas, tb_status,
 * tb_status_transicoes) e são administráveis pela área Admin. As funções
 * can_transition() e status_badge_style() substituem os antigos arquivos
 * status_rules.php / status_helper.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/perfis_repo.php';

/** Colunas ativas do Kanban, em ordem, cada uma com a lista de status ativos. */
function kanban_columns(bool $onlyActive = true): array {
  static $cache = null;
  if ($onlyActive && $cache !== null) return $cache;

  $whereCol = $onlyActive ? "WHERE ativo=1" : "";
  $cols = db()->query("SELECT * FROM tb_kanban_colunas $whereCol ORDER BY ordem, id_coluna")->fetchAll();

  $whereSt = $onlyActive ? "WHERE ativo=1" : "";
  $sts = db()->query("SELECT * FROM tb_status $whereSt ORDER BY ordem, id_status")->fetchAll();

  $byCol = [];
  foreach ($sts as $s) $byCol[(int)$s['id_coluna']][] = $s;

  foreach ($cols as &$c) {
    $c['statuses'] = $byCol[(int)$c['id_coluna']] ?? [];
  }
  unset($c);

  if ($onlyActive) $cache = $cols;
  return $cols;
}

/** Lista simples de status ativos (na ordem das colunas + ordem interna). */
function statuses_list(): array {
  $out = [];
  foreach (kanban_columns() as $c) {
    foreach ($c['statuses'] as $s) $out[] = $s;
  }
  return $out;
}

/** Nomes dos status ativos. */
function status_names(): array {
  return array_map(fn($s) => $s['nome'], statuses_list());
}

function status_by_name(string $nome): ?array {
  foreach (statuses_list() as $s) {
    if ($s['nome'] === $nome) return $s;
  }
  // pode estar inativo, mas ainda referenciado por cursos antigos
  $st = db()->prepare("SELECT * FROM tb_status WHERE nome=? LIMIT 1");
  $st->execute([$nome]);
  return $st->fetch() ?: null;
}

/** Status inicial (usado ao criar curso). */
function status_inicial(): string {
  foreach (statuses_list() as $s) {
    if (!empty($s['is_inicial'])) return $s['nome'];
  }
  $first = statuses_list()[0] ?? null;
  return $first ? $first['nome'] : 'Curso Proposto';
}

/**
 * Verifica se o perfil pode mover um curso de $from para $to.
 * Perfis com admin_total podem realizar qualquer transição entre status ativos.
 */
function can_transition(string $role, string $from, string $to): bool {
  if ($from === $to) return false;

  $sFrom = status_by_name($from);
  $sTo   = status_by_name($to);
  if (!$sFrom || !$sTo || empty($sTo['ativo'])) return false;

  if (perfil_flag($role, 'admin_total')) return true;

  $st = db()->prepare("
    SELECT COUNT(*) AS n FROM tb_status_transicoes
    WHERE role=? AND id_status_de=? AND id_status_para=?
  ");
  $st->execute([$role, (int)$sFrom['id_status'], (int)$sTo['id_status']]);
  return (int)($st->fetch()['n'] ?? 0) > 0;
}

/** Transições possíveis a partir de um status, para um perfil. */
function possible_transitions(string $role, string $from): array {
  if (perfil_flag($role, 'admin_total')) {
    return array_values(array_filter(status_names(), fn($n) => $n !== $from));
  }
  $sFrom = status_by_name($from);
  if (!$sFrom) return [];
  $st = db()->prepare("
    SELECT s.nome
    FROM tb_status_transicoes t
    JOIN tb_status s ON s.id_status = t.id_status_para AND s.ativo=1
    WHERE t.role=? AND t.id_status_de=?
    ORDER BY s.ordem
  ");
  $st->execute([$role, (int)$sFrom['id_status']]);
  return array_map(fn($r) => $r['nome'], $st->fetchAll());
}

/** Cor de texto (preto/branco) com contraste adequado sobre um fundo hex. */
function contrast_color(string $hex): string {
  $hex = ltrim($hex, '#');
  if (strlen($hex) !== 6) return '#000000';
  $r = hexdec(substr($hex, 0, 2));
  $g = hexdec(substr($hex, 2, 2));
  $b = hexdec(substr($hex, 4, 2));
  $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
  return $lum > 0.6 ? '#000000' : '#ffffff';
}

/** Atributo style para o badge de um status (cor configurável no Admin). */
function status_badge_style(string $nome): string {
  $s = status_by_name($nome);
  $cor = $s['cor'] ?? '#6c757d';
  return 'background-color:' . htmlspecialchars($cor) . ';color:' . contrast_color($cor) . ';';
}

/** Rótulo/estilo de prioridade do curso. */
function prioridade_badge(string $p): array {
  $map = [
    'BAIXA'   => ['Baixa', '#adb5bd'],
    'MEDIA'   => ['Média', '#0dcaf0'],
    'ALTA'    => ['Alta', '#fd7e14'],
    'URGENTE' => ['Urgente', '#dc3545'],
  ];
  [$label, $cor] = $map[$p] ?? ['Média', '#0dcaf0'];
  return ['label' => $label, 'style' => "background-color:$cor;color:" . contrast_color($cor)];
}

/** Situação de prazo do curso: ok | proximo | atrasado | null (sem data). */
function prazo_flag(?string $dataEntrega, string $statusAtual): ?string {
  if (!$dataEntrega) return null;
  $s = status_by_name($statusAtual);
  if ($s && !empty($s['is_final'])) return null; // concluído não gera alerta
  $hoje = new DateTime('today');
  $prazo = new DateTime($dataEntrega);
  if ($prazo < $hoje) return 'atrasado';
  if ((int)$hoje->diff($prazo)->days <= 7) return 'proximo';
  return 'ok';
}

/** Níveis de ensino padronizados (Guia 01, seção 7.1-b). */
function niveis_ensino(): array {
  return [
    'Educação Infantil',
    'Ensino Fundamental - Anos Iniciais',
    'Ensino Fundamental - Anos Finais',
    'Ensino Médio',
    'Formação Transversal / Complementar',
  ];
}

function prioridades(): array {
  return ['BAIXA', 'MEDIA', 'ALTA', 'URGENTE'];
}
