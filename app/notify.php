<?php
/**
 * Notificações por e-mail — fila em tb_notificacoes processada por cron.
 *
 * As páginas apenas ENFILEIRAM (rápido, nunca travam esperando SMTP);
 * o envio real acontece em cron/cron_notificacoes.php.
 *
 * Configuração em config.php/config.local.php, seção 'mail':
 *   method: 'mail' (nativo do cPanel), 'smtp' ou 'disabled'
 */
require_once __DIR__ . '/db.php';

function notify_config(): array {
  static $cfg = null;
  if ($cfg === null) $cfg = require __DIR__ . '/config.php';
  return $cfg;
}

function app_base_url(): string {
  return rtrim(notify_config()['app']['base_url'] ?? '', '/');
}

/** Caixa institucional da equipe TI & AutoriaSCS. */
function ti_email(): string {
  return notify_config()['mail']['ti_email'] ?? 'ti.cecape@scseduca.com.br';
}

/**
 * Enfileira um e-mail respeitando a preferência do destinatário
 * (IMEDIATO envia no próximo ciclo do cron; DIARIO agrupa para as 07h;
 * DESATIVADO descarta).
 */
function notify_queue(string $email, string $nome, string $assunto, string $html): void {
  try {
    $pref = 'IMEDIATO';
    $st = db()->prepare("SELECT notif_pref FROM tb_users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row && !empty($row['notif_pref'])) $pref = $row['notif_pref'];

    if ($pref === 'DESATIVADO') return;

    $sendAfter = null;
    if ($pref === 'DIARIO') {
      $d = new DateTime('today 07:00');
      if ($d <= new DateTime()) $d->modify('+1 day');
      $sendAfter = $d->format('Y-m-d H:i:s');
    }

    db()->prepare("
      INSERT INTO tb_notificacoes (destinatario_email, destinatario_nome, assunto, corpo_html, status, send_after)
      VALUES (?,?,?,?, 'PENDENTE', ?)
    ")->execute([$email, $nome, $assunto, $html, $sendAfter]);

    // ENVIO IMEDIATO (em tempo de execução): a fila fica como retaguarda —
    // se falhar aqui, o registro permanece PENDENTE/ERRO e o cron reprocessa.
    if ($sendAfter === null) {
      notify_send_one((int)db()->lastInsertId());
    }
  } catch (Throwable $e) {
    error_log('Falha ao enfileirar notificação: ' . $e->getMessage());
  }
}

/** Envia uma notificação específica da fila. Retorna true se enviou. */
function notify_send_one(int $idNotificacao): bool {
  try {
    $cfg = notify_config()['mail'] ?? [];
    if (($cfg['method'] ?? 'disabled') === 'disabled') return false;

    $st = db()->prepare("
      SELECT * FROM tb_notificacoes
      WHERE id_notificacao=? AND status IN ('PENDENTE','ERRO') AND tentativas < 3
        AND (send_after IS NULL OR send_after <= NOW())
      LIMIT 1
    ");
    $st->execute([$idNotificacao]);
    $n = $st->fetch();
    if (!$n) return false;

    $res = mailer_send($n['destinatario_email'], $n['destinatario_nome'], $n['assunto'], $n['corpo_html']);
    if ($res === true) {
      db()->prepare("UPDATE tb_notificacoes SET status='ENVIADO', sent_at=NOW(), erro_msg=NULL WHERE id_notificacao=?")
        ->execute([$idNotificacao]);
      return true;
    }
    db()->prepare("UPDATE tb_notificacoes SET status='ERRO', tentativas=tentativas+1, erro_msg=? WHERE id_notificacao=?")
      ->execute([mb_substr((string)$res, 0, 500), $idNotificacao]);
    return false;
  } catch (Throwable $e) {
    error_log('Falha no envio imediato da notificação #' . $idNotificacao . ': ' . $e->getMessage());
    return false;
  }
}

/** Enfileira para todos os usuários ativos de um perfil. */
function notify_role(string $role, string $assunto, string $html): void {
  try {
    $st = db()->prepare("SELECT nome, email FROM tb_users WHERE role=? AND ativo=1");
    $st->execute([$role]);
    foreach ($st->fetchAll() as $u) {
      notify_queue($u['email'], $u['nome'], $assunto, $html);
    }
  } catch (Throwable $e) {
    error_log('Falha ao enfileirar por perfil: ' . $e->getMessage());
  }
}

/** Template HTML institucional AutoriaSCS. */
function mail_template(string $titulo, string $corpoHtml, ?string $linkUrl = null, ?string $linkLabel = null): string {
  $btn = '';
  if ($linkUrl) {
    $label = htmlspecialchars($linkLabel ?: 'Abrir no sistema');
    $url = htmlspecialchars($linkUrl);
    $btn = "<p style='margin:24px 0'><a href='{$url}' style='background:#058285;color:#ffffff;
            padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:bold'>{$label}</a></p>";
  }
  $t = htmlspecialchars($titulo);
  return "<!doctype html><html><body style='margin:0;background:#f4f6f6;font-family:Comfortaa,Verdana,Arial,sans-serif'>
    <div style='max-width:620px;margin:0 auto;padding:24px 12px'>
      <div style='background:#058285;color:#fff;border-radius:12px 12px 0 0;padding:18px 24px'>
        <div style='font-size:18px;font-weight:bold'>AutoriaSCS • Gestão de Cursos</div>
        <div style='font-size:12px;opacity:.85'>CECAPE — São Caetano do Sul</div>
      </div>
      <div style='background:#ffffff;border:1px solid #e2e6e6;border-top:0;border-radius:0 0 12px 12px;padding:24px'>
        <h2 style='color:#058285;font-size:17px;margin:0 0 14px'>{$t}</h2>
        <div style='color:#222;font-size:14px;line-height:1.6'>{$corpoHtml}</div>
        {$btn}
        <hr style='border:0;border-top:1px solid #e2e6e6;margin:22px 0 12px'>
        <div style='color:#889;font-size:11px'>
          Mensagem automática do sistema de acompanhamento de cursos.
          Ajuste suas preferências de e-mail em Meu Perfil.
          Suporte: ti.cecape@scseduca.com.br
        </div>
      </div>
    </div></body></html>";
}

/**
 * Roteia as notificações de mudança de status (matriz da proposta).
 * Regra geral: o formador é sempre avisado quando outra pessoa move o curso dele;
 * TI e MB recebem os eventos que exigem ação deles.
 */
function notify_event_status(array $curso, string $from, string $to, array $byUser): void {
  $link = app_base_url() . '/curso_detalhe.php?id=' . (int)$curso['id_curso'];
  $nomeCurso = $curso['nome_curso'];
  $profNome  = $curso['professor_nome'];
  $profEmail = $curso['professor_email'];

  $base = "<p>Curso: <b>" . htmlspecialchars($nomeCurso) . "</b><br>"
        . "Formador(a): " . htmlspecialchars($profNome) . "<br>"
        . "Status: <b>" . htmlspecialchars($from) . "</b> → <b>" . htmlspecialchars($to) . "</b><br>"
        . "Por: " . htmlspecialchars($byUser['nome'] ?? $byUser['email'] ?? '-') . "</p>";

  // Caixa institucional TI: TODA movimentação feita por PROFESSOR ou MB
  $roleAtor = $byUser['role'] ?? '';
  if (in_array($roleAtor, ['PROFESSOR', 'MB'], true)) {
    $origem = $roleAtor === 'MB' ? 'MB Estúdios' : 'formador(a)';
    notify_queue(ti_email(), 'Equipe TI & AutoriaSCS',
      "[AutoriaSCS] {$nomeCurso}: {$from} → {$to}",
      mail_template("Movimentação de status pelo(a) {$origem}", $base, $link, 'Ver curso'));
  }

  // TI: cursos aguardando revisão
  if (in_array($to, ['Pronto para Análise', 'Pronto para Nova Análise'], true)) {
    notify_role('TI', "[AutoriaSCS] Curso aguardando revisão: {$nomeCurso}",
      mail_template('Curso aguardando revisão da equipe TI', $base, $link, 'Revisar curso'));
  }

  // MB: curso liberado para inserção
  if ($to === 'Enviado para Inserção') {
    notify_role('MB', "[AutoriaSCS] Novo curso para inserção: {$nomeCurso}",
      mail_template('Curso aprovado e liberado para inserção na plataforma', $base, $link, 'Ver curso'));
  }

  // TI: acompanhamento das etapas finais
  if (in_array($to, ['Inserido', 'Validado', 'Publicado'], true)) {
    notify_role('TI', "[AutoriaSCS] {$nomeCurso}: {$to}",
      mail_template("Curso movido para \"{$to}\"", $base, $link, 'Acompanhar'));
  }

  // Formador: sempre que outra pessoa mover o curso dele + mensagens específicas
  $atorEhFormador = isset($byUser['id_user']) && (int)$byUser['id_user'] === (int)$curso['id_professor'];
  if (!$atorEhFormador || in_array($to, ['Inserido'], true)) {
    $extra = '';
    if ($to === 'Recusado - Ajustes Necessários') {
      // inclui apontamentos pendentes no e-mail
      try {
        $ap = db()->prepare("SELECT tipo, conteudo FROM tb_curso_apontamentos WHERE id_curso=? AND resolvido=0 ORDER BY created_at DESC LIMIT 15");
        $ap->execute([(int)$curso['id_curso']]);
        $itens = $ap->fetchAll();
        if ($itens) {
          $extra .= "<p><b>Apontamentos a corrigir:</b></p><ul>";
          foreach ($itens as $i) {
            $extra .= "<li><b>" . htmlspecialchars($i['tipo']) . ":</b> " . htmlspecialchars($i['conteudo']) . "</li>";
          }
          $extra .= "</ul>";
        }
      } catch (Throwable $e) { /* lista é opcional */ }
      $titulo = 'Seu curso precisa de ajustes';
    } elseif ($to === 'Aprovado') {
      $titulo = 'Parabéns! Seu curso foi aprovado pela equipe TI';
    } elseif ($to === 'Inserido') {
      $titulo = 'Seu curso foi inserido na plataforma — faça a conferência e validação';
      $extra = "<p>Acesse a plataforma, confira os conteúdos, links, vídeos e avaliações e
                registre a validação no sistema (Guia 01, seção 5).</p>";
    } elseif ($to === 'Publicado') {
      $titulo = 'Seu curso foi publicado na Plataforma AutoriaSCS! 🎉';
      if (!empty($curso['publication_due_date'])) {
        $extra = "<p>Data prevista de publicação: <b>" . htmlspecialchars($curso['publication_due_date']) . "</b></p>";
      }
    } else {
      $titulo = "Atualização no seu curso: {$to}";
    }

    notify_queue($profEmail, $profNome, "[AutoriaSCS] {$nomeCurso}: {$to}",
      mail_template($titulo, $base . $extra, $link, 'Abrir meu curso'));
  }
}

/** Notifica o formador sobre novo apontamento avulso. */
function notify_apontamento(array $curso, string $tipo, string $conteudo): void {
  $link = app_base_url() . '/apontamentos.php?id=' . (int)$curso['id_curso'];
  $html = mail_template(
    'Novo apontamento no seu curso',
    "<p>Curso: <b>" . htmlspecialchars($curso['nome_curso']) . "</b></p>
     <p><b>" . htmlspecialchars($tipo) . ":</b> " . htmlspecialchars($conteudo) . "</p>",
    $link, 'Ver apontamentos'
  );
  notify_queue($curso['professor_email'], $curso['professor_nome'],
    "[AutoriaSCS] Novo apontamento: {$curso['nome_curso']}", $html);
}

// ---------------------------------------------------------------------------
// Envio (usado pelo cron)
// ---------------------------------------------------------------------------

/** Processa a fila. Retorna [enviados, erros]. */
function notify_send_pending(int $limit = 25): array {
  $cfg = notify_config()['mail'] ?? [];
  $method = $cfg['method'] ?? 'disabled';
  if ($method === 'disabled') return [0, 0];

  $rows = db()->query("
    SELECT id_notificacao FROM tb_notificacoes
    WHERE status IN ('PENDENTE','ERRO') AND tentativas < 3
      AND (send_after IS NULL OR send_after <= NOW())
    ORDER BY id_notificacao
    LIMIT " . (int)$limit
  )->fetchAll();

  $ok = 0; $err = 0;
  foreach ($rows as $n) {
    notify_send_one((int)$n['id_notificacao']) ? $ok++ : $err++;
  }
  return [$ok, $err];
}

/** Envia um e-mail (método 'mail' nativo ou 'smtp'). Retorna true ou mensagem de erro. */
function mailer_send(string $toEmail, string $toName, string $subject, string $html) {
  $cfg = notify_config()['mail'] ?? [];
  $method = $cfg['method'] ?? 'disabled';
  $fromEmail = $cfg['from_email'] ?? 'no-reply.cecape@scseduca.com.br';
  $fromName  = $cfg['from_name'] ?? 'AutoriaSCS - CECAPE';

  if ($method === 'disabled') return 'Envio de e-mail desativado na configuração.';

  if ($method === 'mail') {
    $headers = "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n"
             . "Reply-To: {$fromEmail}\r\n";
    $subj = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $sent = @mail($toEmail, $subj, $html, $headers);
    return $sent ? true : 'Função mail() retornou falso — verifique o serviço de e-mail da hospedagem.';
  }

  if ($method === 'smtp') {
    return smtp_send($cfg, $fromEmail, $fromName, $toEmail, $toName, $subject, $html);
  }

  return "Método de envio desconhecido: {$method}";
}

/** Cliente SMTP mínimo (SSL porta 465 ou STARTTLS porta 587). Retorna true ou erro. */
function smtp_send(array $cfg, string $fromEmail, string $fromName,
                   string $toEmail, string $toName, string $subject, string $html) {
  $host   = $cfg['smtp_host'] ?? 'localhost';
  $port   = (int)($cfg['smtp_port'] ?? 587);
  $user   = $cfg['smtp_user'] ?? '';
  $pass   = $cfg['smtp_pass'] ?? '';
  $secure = $cfg['smtp_secure'] ?? 'tls'; // 'ssl' (465) | 'tls' (587) | 'none'

  $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
  $fp = @stream_socket_client($remote, $errno, $errstr, 15);
  if (!$fp) return "SMTP: não conectou em {$remote} ({$errstr})";
  stream_set_timeout($fp, 15);

  $read = function () use ($fp) {
    $data = '';
    while ($line = fgets($fp, 515)) {
      $data .= $line;
      if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
  };
  $cmd = function (string $c, array $expect) use ($fp, $read) {
    fwrite($fp, $c . "\r\n");
    $r = $read();
    $code = (int)substr($r, 0, 3);
    if (!in_array($code, $expect, true)) throw new Exception("SMTP \"{$c}\": {$r}");
    return $r;
  };

  try {
    $read(); // banner
    $cmd('EHLO autoriascs', [250]);

    if ($secure === 'tls') {
      $cmd('STARTTLS', [220]);
      if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new Exception('SMTP: falha ao iniciar TLS');
      }
      $cmd('EHLO autoriascs', [250]);
    }

    if ($user !== '') {
      $cmd('AUTH LOGIN', [334]);
      $cmd(base64_encode($user), [334]);
      $cmd(base64_encode($pass), [235]);
    }

    $cmd("MAIL FROM:<{$fromEmail}>", [250]);
    $cmd("RCPT TO:<{$toEmail}>", [250, 251]);
    $cmd('DATA', [354]);

    $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n"
             . "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Date: " . date('r') . "\r\n";
    $body = preg_replace('/^\./m', '..', $html);
    $cmd($headers . "\r\n" . $body . "\r\n.", [250]);
    $cmd('QUIT', [221]);
    fclose($fp);
    return true;
  } catch (Throwable $e) {
    @fclose($fp);
    return $e->getMessage();
  }
}
