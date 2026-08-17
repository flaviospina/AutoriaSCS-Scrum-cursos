<?php
/**
 * ⚠️ PÁGINA TEMPORÁRIA DE DIAGNÓSTICO DE E-MAIL ⚠️
 *
 * Testa passo a passo: configuração carregada, fila no banco, DNS,
 * conexão SMTP, TLS, autenticação e envio real de um e-mail de teste.
 * APAGUE este arquivo após resolver o problema.
 *
 * Acesso: diagnostico_email.php?chave=cecape-diagnostico-2026
 */

const CHAVE_DIAG = 'cecape-diagnostico-2026';

if (($_GET['chave'] ?? '') !== CHAVE_DIAG) {
  http_response_code(403);
  exit('Acesso negado. Informe a chave na URL: diagnostico_email.php?chave=...');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(90);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/notify.php';

$cfgAll = require __DIR__ . '/../app/config.php';
$cfg = $cfgAll['mail'] ?? [];

function ok($msg)   { echo "<div class='item ok'>✅ {$msg}</div>"; }
function falha($msg){ echo "<div class='item err'>❌ {$msg}</div>"; }
function aviso($msg){ echo "<div class='item warn'>⚠️ {$msg}</div>"; }
function info($msg) { echo "<div class='item info'>ℹ️ {$msg}</div>"; }
function h($s) { return htmlspecialchars((string)$s); }
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Diagnóstico de E-mail — AutoriaSCS (TEMPORÁRIO)</title>
  <style>
    body { font-family: Verdana, Arial, sans-serif; background:#f4f6f6; margin:0; padding:20px; font-size:14px; }
    .box { max-width:880px; margin:0 auto; background:#fff; border:1px solid #ddd; border-radius:10px; padding:24px; }
    h1 { color:#058285; font-size:20px; } h2 { color:#058285; font-size:16px; margin-top:28px; border-bottom:2px solid #e6f3f3; padding-bottom:6px; }
    .item { padding:8px 12px; margin:6px 0; border-radius:6px; }
    .ok   { background:#e7f6ec; border:1px solid #b7e2c5; }
    .err  { background:#fdeaea; border:1px solid #f5b5b5; }
    .warn { background:#fff6e0; border:1px solid #f0dd9a; }
    .info { background:#eef4fb; border:1px solid #c4d8f0; }
    .alerta { background:#fdeaea; border:2px solid #dc3545; padding:12px 16px; border-radius:8px; font-weight:bold; }
    pre { background:#f2f4f4; border:1px solid #ddd; padding:10px; border-radius:6px; overflow-x:auto; font-size:12px; white-space:pre-wrap; }
    input[type=email] { padding:8px; border:1px solid #ccc; border-radius:6px; width:300px; }
    button { background:#058285; color:#fff; border:0; padding:9px 20px; border-radius:6px; font-weight:bold; cursor:pointer; }
  </style>
</head>
<body>
<div class="box">
  <div class="alerta">⚠️ Página temporária de diagnóstico — APAGUE o arquivo public/diagnostico_email.php após o uso.</div>

  <h1>Diagnóstico de E-mail — AutoriaSCS</h1>

  <h2>1. Configuração carregada</h2>
<?php
  $localFile = __DIR__ . '/../app/config.local.php';
  if (is_file($localFile)) {
    ok("app/config.local.php existe e está sendo lido.");
    $override = require $localFile;
    if (!is_array($override)) {
      falha("config.local.php NÃO devolve um array — falta a linha <code>return</code> no final! Nesse caso TODAS as configurações locais são ignoradas.");
    } elseif (!isset($override['mail'])) {
      aviso("config.local.php não tem a seção <code>'mail'</code> — o sistema está usando os valores padrão do config.php.");
    } else {
      ok("Seção 'mail' encontrada no config.local.php.");
    }
  } else {
    falha("app/config.local.php NÃO existe — usando somente os padrões do config.php.");
  }

  $method = $cfg['method'] ?? '(não definido)';
  info("Método de envio: <b>" . h($method) . "</b>");
  info("Remetente: <b>" . h($cfg['from_email'] ?? '-') . "</b> (" . h($cfg['from_name'] ?? '-') . ")");
  if ($method === 'smtp') {
    info("SMTP: <b>" . h($cfg['smtp_host'] ?? '-') . ":" . h($cfg['smtp_port'] ?? '-') . "</b> • segurança: <b>" . h($cfg['smtp_secure'] ?? '-') . "</b>");
    info("Usuário SMTP: <b>" . h($cfg['smtp_user'] ?? '(vazio)') . "</b> • senha: <b>" .
         (($cfg['smtp_pass'] ?? '') !== '' ? str_repeat('•', 8) . " (" . strlen($cfg['smtp_pass']) . " caracteres)" : "(VAZIA!)") . "</b>");
    $senha = $cfg['smtp_pass'] ?? '';
    if (($cfg['smtp_host'] ?? '') === 'smtp.gmail.com') {
      if (strlen(str_replace(' ', '', $senha)) === 16 && strlen($senha) >= 16) {
        ok("A senha tem o formato de uma Senha de App do Google (16 caracteres).");
      } else {
        falha("A senha NÃO parece ser uma <b>Senha de App</b> do Google (teria 16 letras). O Gmail <u>não aceita a senha normal da conta</u> via SMTP — gere uma senha de app em <b>myaccount.google.com/apppasswords</b> (exige verificação em 2 etapas ativa) e use-a aqui.");
      }
    }
    if (preg_match('/^\s|\s$/', $senha)) aviso("A senha tem espaço no início/fim — verifique se não copiou espaço extra.");
  } elseif ($method === 'disabled') {
    falha("O envio está DESATIVADO (method = 'disabled'). Altere para 'mail' ou 'smtp'.");
  }

  echo "<h2>2. Ambiente PHP</h2>";
  info("PHP " . PHP_VERSION . " • SAPI: " . PHP_SAPI);
  extension_loaded('openssl') ? ok("Extensão OpenSSL disponível (necessária para TLS).")
                              : falha("Extensão OpenSSL AUSENTE — TLS/SSL não funcionará.");
  function_exists('mail') ? ok("Função mail() disponível.") : falha("Função mail() indisponível.");
  function_exists('stream_socket_client') ? ok("stream_socket_client() disponível (SMTP).")
                                          : falha("stream_socket_client() BLOQUEADA pela hospedagem — método 'smtp' não funcionará.");

  echo "<h2>3. Banco e fila de notificações</h2>";
  try {
    $pdo = db();
    ok("Conexão com o banco OK.");
    try {
      $tot = $pdo->query("SELECT status, COUNT(*) n FROM tb_notificacoes GROUP BY status")->fetchAll();
      if (!$tot) {
        aviso("A tabela tb_notificacoes existe mas está VAZIA — nenhuma notificação foi enfileirada ainda. Faça uma ação que gere e-mail (ex.: mover um curso de status) e recarregue.");
      } else {
        foreach ($tot as $t) info("Fila: <b>" . h($t['status']) . "</b> = " . (int)$t['n']);
      }
      $ultimos = $pdo->query("SELECT id_notificacao, destinatario_email, assunto, status, tentativas, erro_msg, created_at, sent_at
                              FROM tb_notificacoes ORDER BY id_notificacao DESC LIMIT 5")->fetchAll();
      if ($ultimos) {
        echo "<pre>";
        foreach ($ultimos as $n) {
          echo "#{$n['id_notificacao']} [{$n['status']}] {$n['created_at']} → " . h($n['destinatario_email'])
             . "\n   " . h($n['assunto'])
             . ($n['erro_msg'] ? "\n   ERRO: " . h($n['erro_msg']) : "")
             . ($n['sent_at'] ? "\n   enviado: {$n['sent_at']}" : "") . "\n";
        }
        echo "</pre>";
      }
      $enviadaRecente = $pdo->query("SELECT MAX(sent_at) m FROM tb_notificacoes WHERE status='ENVIADO'")->fetch()['m'] ?? null;
      $pendAntiga = $pdo->query("SELECT COUNT(*) n FROM tb_notificacoes WHERE status='PENDENTE' AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (send_after IS NULL OR send_after <= NOW())")->fetch()['n'] ?? 0;
      if ((int)$pendAntiga > 0 && !$enviadaRecente) {
        aviso("Há <b>{$pendAntiga}</b> notificação(ões) pendentes há mais de 15 minutos e nenhuma foi enviada — o <b>CRON provavelmente não está configurado/rodando</b>. Configure no cPanel: a cada 5 min → <code>cron/cron_notificacoes.php SUA_CHAVE</code>. (O teste abaixo envia direto, sem depender do cron.)");
      }
    } catch (Throwable $e) {
      falha("Tabela tb_notificacoes não existe — rode o database/upgrade_v3.sql no phpMyAdmin. (" . h($e->getMessage()) . ")");
    }
  } catch (Throwable $e) {
    falha("Sem conexão com o banco: " . h($e->getMessage()));
  }

  if ($method === 'smtp') {
    echo "<h2>4. Conexão com o servidor SMTP</h2>";
    $host = $cfg['smtp_host'] ?? '';
    $port = (int)($cfg['smtp_port'] ?? 587);
    $secure = $cfg['smtp_secure'] ?? 'tls';

    $ipv = gethostbyname($host);
    ($ipv !== $host) ? ok("DNS OK: {$host} → {$ipv}")
                     : falha("DNS falhou para <b>" . h($host) . "</b> — host errado ou hospedagem sem saída de rede.");

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $t0 = microtime(true);
    $fp = @stream_socket_client($remote, $errno, $errstr, 12);
    if (!$fp) {
      falha("Não conectou em <b>" . h($remote) . "</b>: [{$errno}] " . h($errstr) .
            " — a HostGator costuma <b>bloquear portas SMTP de saída</b> em planos compartilhados. Alternativas: usar method 'mail', testar porta 465 com smtp_secure 'ssl', ou pedir liberação da porta ao suporte da hospedagem.");
    } else {
      $dt = round((microtime(true) - $t0) * 1000);
      ok("Conexão TCP estabelecida com {$remote} em {$dt}ms.");
      stream_set_timeout($fp, 10);
      $banner = fgets($fp, 512);
      $banner ? info("Banner do servidor: <code>" . h(trim($banner)) . "</code>")
              : aviso("Servidor não enviou banner.");
      fclose($fp);

      // handshake completo com autenticação (sem enviar e-mail): usa RSET após login
      echo "<h2>5. Autenticação SMTP (login de verdade, sem enviar e-mail)</h2>";
      $log = [];
      $res = (function () use ($cfg, &$log) {
        $host = $cfg['smtp_host']; $port = (int)$cfg['smtp_port'];
        $secure = $cfg['smtp_secure'] ?? 'tls';
        $user = $cfg['smtp_user'] ?? ''; $pass = $cfg['smtp_pass'] ?? '';
        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $en, $es, 12);
        if (!$fp) return "reconexão falhou: {$es}";
        stream_set_timeout($fp, 12);
        $read = function () use ($fp) {
          $d = '';
          while ($l = fgets($fp, 515)) { $d .= $l; if (isset($l[3]) && $l[3] === ' ') break; }
          return $d;
        };
        $cmd = function ($c, $exp, $mask = false) use ($fp, $read, &$log) {
          fwrite($fp, $c . "\r\n");
          $r = $read();
          $log[] = "> " . ($mask ? '(oculto)' : $c) . "\n< " . trim($r);
          $code = (int)substr($r, 0, 3);
          if (!in_array($code, $exp, true)) throw new Exception(trim($r));
          return $r;
        };
        try {
          $log[] = "< " . trim($read());
          $cmd('EHLO diagnostico-autoriascs', [250]);
          if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
              throw new Exception('Falha ao ativar TLS');
            }
            $log[] = "* TLS ativado";
            $cmd('EHLO diagnostico-autoriascs', [250]);
          }
          if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334], true);
            $cmd(base64_encode($pass), [235], true);
            $log[] = "* AUTENTICADO com sucesso";
          } else {
            $log[] = "* Sem usuário configurado — pulando autenticação";
          }
          $cmd('QUIT', [221]);
          fclose($fp);
          return true;
        } catch (Throwable $e) {
          @fclose($fp);
          return $e->getMessage();
        }
      })();

      echo "<pre>" . h(implode("\n", $log)) . "</pre>";
      if ($res === true) {
        ok("<b>Autenticação SMTP OK!</b> Servidor aceitou usuário e senha.");
      } else {
        $msg = h($res);
        falha("Autenticação/handshake falhou: <code>{$msg}</code>");
        if (stripos($res, '535') !== false || stripos($res, 'auth') !== false || stripos($res, 'credentials') !== false) {
          falha("Código 535 / credenciais recusadas = <b>usuário ou senha rejeitados</b>. No Gmail/Workspace a senha normal da conta NÃO funciona: gere uma <b>Senha de App</b> (myaccount.google.com/apppasswords, requer verificação em 2 etapas) e cole os 16 caracteres em smtp_pass.");
        }
      }
    }
  }

  // Envio de teste real
  echo "<h2>" . ($method === 'smtp' ? '6' : '4') . ". Envio de e-mail de teste</h2>";
  if (($_POST['acao'] ?? '') === 'teste' && filter_var($_POST['destino'] ?? '', FILTER_VALIDATE_EMAIL)) {
    $destino = $_POST['destino'];
    $t0 = microtime(true);
    $r = mailer_send($destino, 'Teste Diagnóstico', '[AutoriaSCS] Teste de e-mail ' . date('H:i:s'),
      mail_template('Teste de envio', '<p>Se você recebeu esta mensagem, o envio de e-mails do sistema está funcionando. 🎉</p><p>Método: <b>' . h($method) . '</b> — ' . date('d/m/Y H:i:s') . '</p>'));
    $dt = round((microtime(true) - $t0) * 1000);
    if ($r === true) {
      ok("Envio executado SEM ERRO em {$dt}ms para <b>" . h($destino) . "</b>. Verifique a caixa de entrada <u>e o spam</u>. Se não chegar em 5 min, o problema é entregabilidade (SPF/DKIM do domínio) — nesse caso use SMTP autenticado do Gmail.");
    } else {
      falha("Falha no envio ({$dt}ms): <code>" . h($r) . "</code>");
    }
  }
?>
  <form method="post">
    <input type="hidden" name="acao" value="teste">
    <input type="email" name="destino" placeholder="seu-email@scseduca.com.br" required>
    <button>Enviar e-mail de teste agora</button>
  </form>
  <p style="color:#889;font-size:12px">O teste usa exatamente a mesma rotina do sistema (mailer_send), sem passar pela fila/cron.</p>

  <h2>Checklist de causas mais comuns</h2>
  <div class="item info">
    1️⃣ Senha normal do Gmail em vez de <b>Senha de App</b> (erro 535) — causa nº 1;<br>
    2️⃣ <code>config.local.php</code> sem <code>return</code> no final (config ignorada silenciosamente);<br>
    3️⃣ Cron não configurado no cPanel (fila cresce como PENDENTE e nada sai);<br>
    4️⃣ HostGator bloqueando porta 587/465 de saída (conexão SMTP falha);<br>
    5️⃣ E-mails "ENVIADO" que não chegam = SPF/DKIM — usar SMTP do Gmail resolve;<br>
    6️⃣ Alias como remetente/usuário — use sempre uma conta real.
  </div>
</div>
</body>
</html>
