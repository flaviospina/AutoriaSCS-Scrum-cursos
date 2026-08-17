<?php
/**
 * Auditoria — registra toda ação de escrita, logins e downloads em tb_audit_log.
 *
 * Uso: audit_log('curso_editado', 'curso', $id, $antes, $depois);
 * O usuário é obtido da sessão; para eventos sem sessão (login falho) passe $idUser explícito.
 */
require_once __DIR__ . '/db.php';

function audit_log(string $acao, string $entidade, ?int $idEntidade = null,
                   ?array $antes = null, ?array $depois = null, ?int $idUser = null): void {
  try {
    if ($idUser === null && isset($_SESSION['user']['id_user'])) {
      $idUser = (int)$_SESSION['user']['id_user'];
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    db()->prepare("
      INSERT INTO tb_audit_log (id_user, acao, entidade, id_entidade, dados_antes, dados_depois, ip)
      VALUES (?,?,?,?,?,?,?)
    ")->execute([
      $idUser,
      $acao,
      $entidade,
      $idEntidade,
      $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
      $depois !== null ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
      $ip,
    ]);
  } catch (Throwable $e) {
    // auditoria nunca pode derrubar a operação principal
    error_log('Falha ao gravar auditoria: ' . $e->getMessage());
  }
}

/** Diferença entre dois arrays associativos (apenas campos alterados). */
function audit_diff(array $antes, array $depois): array {
  $a = []; $d = [];
  foreach ($depois as $k => $v) {
    $old = $antes[$k] ?? null;
    if ((string)$old !== (string)$v) {
      $a[$k] = $old;
      $d[$k] = $v;
    }
  }
  return [$a, $d];
}
