<?php
/**
 * Cores dos módulos (V16): o ADMIN define uma cor para cada módulo (1 a 8) e
 * para "Geral"; as cores valem em todo o sistema (badges de arquivos, vídeos,
 * apontamentos, slides, relatórios). Guardadas em tb_config (chave modulos_cores, JSON).
 */
require_once __DIR__ . '/config_repo.php';

const MODULOS_MAX = 8;
const MODULOS_CORES_PADRAO = [
  0 => '#6c757d', // Geral
  1 => '#0d6efd', 2 => '#6610f2', 3 => '#d63384', 4 => '#dc3545',
  5 => '#fd7e14', 6 => '#198754', 7 => '#0dcaf0', 8 => '#795548',
];

/** Cores atuais: [0 => '#rrggbb' (Geral), 1..8]. */
function modulos_cores(): array {
  static $cores = null;
  if ($cores !== null) return $cores;
  $cores = MODULOS_CORES_PADRAO;
  $json = cfg_get('modulos_cores', '');
  if ($json) {
    $arr = json_decode($json, true);
    if (is_array($arr)) foreach ($arr as $k => $v) { $k = (int)$k; if ($k >= 0 && $k <= MODULOS_MAX && preg_match('/^#[0-9a-f]{6}$/i', (string)$v)) $cores[$k] = strtolower($v); }
  }
  return $cores;
}

function modulos_cores_salvar(array $cores): void {
  $out = [];
  foreach ($cores as $k => $v) { $k = (int)$k; if ($k < 0 || $k > MODULOS_MAX) continue; if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$v)) throw new Exception("Cor inválida para o módulo {$k}."); $out[$k] = strtolower($v); }
  cfg_set('modulos_cores', json_encode($out));
}

function modulo_cor(int $m): string { return modulos_cores()[$m] ?? MODULOS_CORES_PADRAO[0]; }

/** Texto preto ou branco conforme o contraste com a cor de fundo. */
function modulo_contraste(string $hex): string {
  $hex = ltrim($hex, '#');
  if (strlen($hex) !== 6) return '#ffffff';
  [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
  return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#111111' : '#ffffff';
}

function modulo_nome(int $m): string { return $m > 0 ? "Módulo {$m}" : 'Geral'; }

/** Style inline do badge do módulo (cor de fundo + texto contrastante). */
function modulo_badge_style(int $m): string {
  $c = modulo_cor($m);
  return 'background-color:' . $c . ';color:' . modulo_contraste($c) . ';';
}

/** HTML do badge "Módulo N" / "Geral" com a cor configurada. */
function modulo_badge(int $m, string $classeExtra = ''): string {
  return '<span class="badge ' . $classeExtra . '" style="' . modulo_badge_style($m) . '" data-modulo="' . $m . '">' . htmlspecialchars(modulo_nome($m)) . '</span>';
}

/** CSS com uma classe por módulo (.mod-0 … .mod-8) para uso em JS/listas. */
function modulos_css(): string {
  $css = '';
  foreach (modulos_cores() as $m => $c) $css .= ".mod-{$m}{background-color:{$c} !important;color:" . modulo_contraste($c) . " !important;} ";
  return $css;
}
