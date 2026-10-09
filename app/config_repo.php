<?php
/**
 * Parâmetros do sistema (V13) — tabela tb_config (chave/valor), ajustáveis pelo ADMIN.
 * Tolerante à migração ausente: sem a tabela, valem os padrões.
 */
require_once __DIR__ . '/db.php';

const CFG_PADROES = [
  'coautor_espera_seg' => '20',   // contagem regressiva antes do envio agrupado dos e-mails de coautores
];

function cfg_tabela_ok(): bool {
  static $ok = null;
  if ($ok === null) {
    try { db()->query("SELECT 1 FROM tb_config LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; }
  }
  return $ok;
}

function cfg_get(string $chave, ?string $padrao = null): ?string {
  $padrao = $padrao ?? (CFG_PADROES[$chave] ?? null);
  if (!cfg_tabela_ok()) return $padrao;
  $st = db()->prepare("SELECT valor FROM tb_config WHERE chave=?");
  $st->execute([$chave]);
  $v = $st->fetchColumn();
  return $v === false ? $padrao : (string)$v;
}

function cfg_set(string $chave, string $valor): void {
  if (!cfg_tabela_ok()) throw new Exception("Execute database/upgrade_v13.sql para habilitar os parâmetros do sistema.");
  db()->prepare("INSERT INTO tb_config (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), updated_at=CURRENT_TIMESTAMP")
      ->execute([$chave, $valor]);
}

/** Segundos de espera após a última inclusão de coautor (ADMIN: 5 a 600 s; padrão 20). */
function coautor_espera_seg(): int {
  $v = (int)cfg_get('coautor_espera_seg');
  return max(5, min(600, $v ?: 20));
}
