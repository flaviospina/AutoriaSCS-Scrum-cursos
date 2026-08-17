<?php
/**
 * Cron: processa a fila de e-mails (tb_notificacoes).
 *
 * Agendar no cPanel a CADA 5 MINUTOS:
 *   php /home/USUARIO/caminho/cron/cron_notificacoes.php SUA_CHAVE
 * ou via URL (hospedagens sem PHP CLI):
 *   https://.../cron/cron_notificacoes.php?chave=SUA_CHAVE
 *
 * A chave é definida em app/config.local.php => ['cron']['chave'].
 */
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/notify.php';

$cfg = require __DIR__ . '/../app/config.php';
$chaveEsperada = $cfg['cron']['chave'] ?? '';

$chaveRecebida = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['chave'] ?? '');
if ($chaveEsperada === '' || !hash_equals($chaveEsperada, $chaveRecebida)) {
  http_response_code(403);
  exit("Chave de cron inválida.\n");
}

[$ok, $err] = notify_send_pending(25);
echo "Notificações: {$ok} enviada(s), {$err} com erro.\n";
