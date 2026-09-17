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
require_once __DIR__ . '/perfis_repo.php';

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

/** Enfileira para todos os usuários ativos de um perfil (código exato). */
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

/**
 * Enfileira para os usuários ativos cujo PERFIL possui a permissão indicada
 * (ex.: recebe_email_revisao, recebe_email_insercao) — cobre perfis
 * personalizados criados na área Admin.
 */
function notify_flag(string $flag, string $assunto, string $html): void {
  $codigos = [];
  foreach (perfis_all() as $cod => $p) {
    if (!empty($p['admin_total'])) continue; // admins não recebem e-mail operacional em massa
    if (!empty($p[$flag])) $codigos[] = $cod;
  }
  if (!$codigos) return;

  try {
    $in = implode(',', array_fill(0, count($codigos), '?'));
    $st = db()->prepare("SELECT nome, email FROM tb_users WHERE ativo=1 AND role IN ($in)");
    $st->execute($codigos);
    foreach ($st->fetchAll() as $u) {
      notify_queue($u['email'], $u['nome'], $assunto, $html);
    }
  } catch (Throwable $e) {
    error_log('Falha ao enfileirar por permissão: ' . $e->getMessage());
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
  // logos institucionais (config app.logos) — faixa branca acima do cabeçalho
  $logos = notify_config()['app']['logos'] ?? [];
  $faixaLogos = '';
  if ($logos) {
    $imgs = '';
    foreach ($logos as $lg) {
      $imgs .= "<img src='" . htmlspecialchars($lg) . "' alt='' height='44' style='height:44px;max-width:150px;margin:0 12px;vertical-align:middle;border:0'>";
    }
    $faixaLogos = "<div style='background:#ffffff;border:1px solid #e2e6e6;border-bottom:0;border-radius:12px 12px 0 0;padding:14px 12px;text-align:center'>{$imgs}</div>";
  }
  $raioTopo = $logos ? '0' : '12px 12px 0 0';
  return "<!doctype html><html><body style='margin:0;background:#f4f6f6;font-family:Comfortaa,Verdana,Arial,sans-serif'>
    <div style='max-width:620px;margin:0 auto;padding:24px 12px'>
      {$faixaLogos}
      <div style='background:#058285;color:#fff;border-radius:{$raioTopo};padding:18px 24px'>
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
        . "Formador(es): " . htmlspecialchars(function_exists('curso_formadores_nomes') ? curso_formadores_nomes($curso) : $profNome) . "<br>"
        . "Status: <b>" . htmlspecialchars($from) . "</b> → <b>" . htmlspecialchars($to) . "</b><br>"
        . "Por: " . htmlspecialchars($byUser['nome'] ?? $byUser['email'] ?? '-') . "</p>";

  // Caixa institucional TI: TODA movimentação feita por quem não é da equipe
  // de revisão/administração (formadores, MB e perfis personalizados equivalentes)
  $roleAtor = $byUser['role'] ?? '';
  $atorEhEquipe = perfil_flag($roleAtor, 'revisa_cursos') || perfil_flag($roleAtor, 'admin_total');
  if ($roleAtor !== '' && !$atorEhEquipe) {
    $perfilAtor = perfil_get($roleAtor);
    $origem = $perfilAtor['nome'] ?? $roleAtor;
    notify_queue(ti_email(), 'Equipe TI & AutoriaSCS',
      "[AutoriaSCS] {$nomeCurso}: {$from} → {$to}",
      mail_template("Movimentação de status por {$origem}", $base, $link, 'Ver curso'));
  }

  // Projeto aprovado pela TI: MB e equipe de revisão recebem a identificação
  // oficial do curso (nome - formador(es) - carga horária)
  if ($to === 'Projeto Aprovado') {
    // curso_repo.php define curso_identificacao(); o fallback cobre chamadas isoladas
    $ident = function_exists('curso_identificacao')
      ? curso_identificacao($curso)
      : "{$nomeCurso} - {$profNome} - " . (int)$curso['carga_horaria'] . ' horas';
    $corpo = "<p>O projeto de curso proposto pelo(a) formador(a)
              <b>" . htmlspecialchars($profNome) . "</b> foi <b>aprovado pela equipe de TI</b>.</p>
              <p>Carga horária definida: <b>" . (int)$curso['carga_horaria'] . " horas</b>.</p>
              <p>Identificação oficial do curso (usar como nome da pasta):<br>
              <b style='font-size:15px'>" . htmlspecialchars($ident) . "</b></p>";
    $assunto = "[AutoriaSCS] Projeto aprovado: {$ident}";
    notify_flag('recebe_email_insercao', $assunto,
      mail_template('Projeto de curso aprovado pela TI', $corpo, $link, 'Ver curso'));
    notify_flag('recebe_email_revisao', $assunto,
      mail_template('Projeto de curso aprovado pela TI', $corpo, $link, 'Ver curso'));
    notify_queue(ti_email(), 'Equipe TI & AutoriaSCS', $assunto,
      mail_template('Projeto de curso aprovado pela TI', $corpo, $link, 'Ver curso'));
  }

  // Equipe de revisão: cursos aguardando análise
  if (in_array($to, ['Pronto para Análise', 'Pronto para Nova Análise'], true)) {
    notify_flag('recebe_email_revisao', "[AutoriaSCS] Curso aguardando revisão: {$nomeCurso}",
      mail_template('Curso aguardando revisão da equipe TI', $base, $link, 'Revisar curso'));
  }

  // Inserção (MB): curso liberado para inserção
  if ($to === 'Enviado para Inserção') {
    notify_flag('recebe_email_insercao', "[AutoriaSCS] Novo curso para inserção: {$nomeCurso}",
      mail_template('Curso aprovado e liberado para inserção na plataforma', $base, $link, 'Ver curso'));
  }

  // Publicação (MB): TI liberou o curso com a data de entrada na plataforma
  if ($to === 'Pronto para Publicação') {
    $dataPub = !empty($curso['publication_due_date'])
      ? "<p>Data para entrada na plataforma: <b>" . htmlspecialchars($curso['publication_due_date']) . "</b></p>"
      : "";
    notify_flag('recebe_email_insercao', "[AutoriaSCS] Curso pronto para publicação: {$nomeCurso}",
      mail_template('Curso liberado pela TI — realizar a publicação na plataforma',
        $base . $dataPub, $link, 'Ver curso'));
  }

  // Equipe de revisão: acompanhamento das etapas finais
  if (in_array($to, ['Inserido', 'Validado', 'Publicado'], true)) {
    notify_flag('recebe_email_revisao', "[AutoriaSCS] {$nomeCurso}: {$to}",
      mail_template("Curso movido para \"{$to}\"", $base, $link, 'Acompanhar'));
  }

  // Formador: sempre que outra pessoa mover o curso dele + mensagens específicas
  $atorEhFormador = isset($byUser['id_user']) && function_exists('curso_eh_professor')
    ? curso_eh_professor($curso, (int)$byUser['id_user'])
    : (isset($byUser['id_user']) && (int)$byUser['id_user'] === (int)$curso['id_professor']);
  if (!$atorEhFormador || in_array($to, ['Inserido'], true)) {
    $extra = '';
    if ($to === 'Recusado - Ajustes Necessários') {
      // inclui apontamentos pendentes no e-mail
      try {
        $ap = db()->prepare("SELECT tipo, conteudo FROM tb_curso_apontamentos WHERE id_curso=? AND resolvido=0 ORDER BY created_at DESC LIMIT 15");
        $ap->execute([(int)$curso['id_curso']]); // resolvido=0 equivale aos status pendentes (sincronizado)
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
    } elseif ($to === 'Pronto para Publicação') {
      $titulo = 'Seu curso está pronto para publicação!';
      if (!empty($curso['publication_due_date'])) {
        $extra = "<p>Data prevista de entrada na plataforma: <b>"
               . htmlspecialchars($curso['publication_due_date']) . "</b></p>";
      }
    } elseif ($to === 'Publicado') {
      $titulo = 'Seu curso foi publicado na Plataforma AutoriaSCS! 🎉';
      if (!empty($curso['publication_due_date'])) {
        $extra = "<p>Data de publicação: <b>" . htmlspecialchars($curso['publication_due_date']) . "</b></p>";
      }
    } else {
      $titulo = "Atualização no seu curso: {$to}";
    }

    $html = mail_template($titulo, $base . $extra, $link, 'Abrir meu curso');
    foreach (notify_professores_curso($curso) as $p) {
      notify_queue($p['email'], $p['nome'], "[AutoriaSCS] {$nomeCurso}: {$to}", $html);
    }
  }
}

/** Destinatários "professor(es) do curso": responsável + coautores (V11) ou só o responsável. */
function notify_professores_curso(array $curso): array {
  $profs = (function_exists('curso_professores') && !empty($curso['id_curso'])) ? curso_professores((int)$curso['id_curso']) : [];
  if (!$profs && !empty($curso['professor_email'])) {
    $profs = [['nome' => $curso['professor_nome'] ?? '', 'email' => $curso['professor_email']]];
  }
  return $profs;
}

/** Intervalo mínimo (minutos) entre e-mails de documentos pendentes do mesmo curso (item 11). */
const ENTREGAS_EMAIL_COOLDOWN_MIN = 360;

/**
 * E-mail de tentativa de avanço com documentos obrigatórios pendentes (item 11):
 * professor(es) do curso + caixa institucional da TI, com cooldown por curso
 * (tb_cursos.aviso_pendencias_em) para não disparar dezenas de e-mails.
 */
function notify_entregas_pendentes(array $curso, string $etapa, array $pendencias, array $byUser): void {
  $idCurso = (int)$curso['id_curso'];
  try {
    $st = db()->prepare("SELECT aviso_pendencias_em FROM tb_cursos WHERE id_curso=?");
    $st->execute([$idCurso]);
    $ultimo = $st->fetch()['aviso_pendencias_em'] ?? null;
    if ($ultimo && (time() - strtotime($ultimo)) < ENTREGAS_EMAIL_COOLDOWN_MIN * 60) return; // cooldown
    db()->prepare("UPDATE tb_cursos SET aviso_pendencias_em=NOW() WHERE id_curso=?")->execute([$idCurso]);
  } catch (Throwable $e) {
    // coluna ainda não migrada: envia mesmo assim
  }

  $agora = new DateTime();
  $link = app_base_url() . '/curso_detalhe.php?id=' . $idCurso;
  $lista = '<ul>' . implode('', array_map(fn($p) => '<li>' . htmlspecialchars($p['rotulo']) . '</li>', $pendencias)) . '</ul>';
  $corpo = "<p>Houve uma tentativa de avançar o curso para a etapa <b>" . htmlspecialchars($etapa) . "</b>,
            mas ainda existem documentos <b>obrigatórios</b> não enviados.</p>
            <table style='border-collapse:collapse;font-size:14px'>
              <tr><td style='padding:3px 10px 3px 0;color:#667'>Curso</td><td><b>" . htmlspecialchars($curso['nome_curso']) . "</b></td></tr>
              <tr><td style='padding:3px 10px 3px 0;color:#667'>Professor(a)</td><td>" . htmlspecialchars($curso['professor_nome']) . "</td></tr>
              <tr><td style='padding:3px 10px 3px 0;color:#667'>Etapa pretendida</td><td>" . htmlspecialchars($etapa) . "</td></tr>
              <tr><td style='padding:3px 10px 3px 0;color:#667'>Tentativa por</td><td>" . htmlspecialchars($byUser['nome'] ?? '-') . "</td></tr>
              <tr><td style='padding:3px 10px 3px 0;color:#667'>Data / horário</td><td>" . $agora->format('d/m/Y') . " às " . $agora->format('H:i') . "</td></tr>
            </table>
            <p><b>Documentos obrigatórios pendentes:</b></p>{$lista}";
  $assunto = "[AutoriaSCS] Documentos pendentes: {$curso['nome_curso']}";
  $html = mail_template('Documentos obrigatórios pendentes', $corpo, $link, 'Enviar os materiais');

  $profs = function_exists('curso_professores') ? curso_professores($idCurso) : [];
  if (!$profs) $profs = [['nome' => $curso['professor_nome'], 'email' => $curso['professor_email']]];
  foreach ($profs as $p) notify_queue($p['email'], $p['nome'], $assunto, $html);
  notify_queue(ti_email(), 'Equipe TI & AutoriaSCS', $assunto, $html);
}

/**
 * ÚNICO ponto de envio dos e-mails de apontamento (itens 17, 21 e 26).
 * Destinatários: todos os professores do curso (responsável + coautores) e a
 * caixa institucional da TI. $evento: criado | status | concordo | objecao | reenvio.
 * $ap deve vir de apont_get() (traz curso, arquivo e autor).
 */
function notify_apontamento_evento(array $ap, string $evento, array $byUser,
                                   ?string $statusDe, ?string $statusPara, ?string $obs): void {
  $idCurso = (int)$ap['id_curso'];
  $link = app_base_url() . '/apontamento_detalhe.php?id=' . (int)$ap['id_apontamento'];
  $arquivo = function_exists('apont_arquivo_rotulo') ? apont_arquivo_rotulo($ap) : ($ap['arquivo_nome'] ?? '');
  $lbl = fn($s) => $s ? (function_exists('apont_status_label') ? apont_status_label($s) : $s) : '—';
  $agora = new DateTime();

  $titulos = [
    'criado'   => 'Novo apontamento TI/Qualidade no seu curso',
    'status'   => 'Apontamento atualizado: ' . $lbl($statusPara),
    'concordo' => 'Professor(a) concordou com o apontamento',
    'objecao'  => 'Professor(a) registrou objeção ao apontamento',
    'reenvio'  => 'Apontamento reenviado para análise',
  ];
  $titulo = $titulos[$evento] ?? 'Atualização em apontamento';

  $detalhes = "<table style='border-collapse:collapse;font-size:14px'>"
    . "<tr><td style='padding:3px 10px 3px 0;color:#667'>Curso</td><td><b>" . htmlspecialchars($ap['nome_curso']) . "</b></td></tr>"
    . "<tr><td style='padding:3px 10px 3px 0;color:#667'>Professor(a)</td><td>" . htmlspecialchars($ap['professor_nome']) . "</td></tr>"
    . "<tr><td style='padding:3px 10px 3px 0;color:#667'>Arquivo relacionado</td><td>" . ($arquivo !== '' ? htmlspecialchars($arquivo) : '<i>nenhum (apontamento geral)</i>') . "</td></tr>"
    . "<tr><td style='padding:3px 10px 3px 0;color:#667'>Tipo</td><td>" . htmlspecialchars(function_exists('apont_tipo_label') ? apont_tipo_label($ap['tipo']) : $ap['tipo']) . "</td></tr>"
    . "<tr><td style='padding:3px 10px 3px 0;color:#667'>Status anterior</td><td>" . htmlspecialchars($lbl($statusDe)) . "</td></tr>"
    . "<tr><td style='padding:3px 10px 3px 0;color:#667'>Novo status</td><td><b>" . htmlspecialchars($lbl($statusPara)) . "</b></td></tr>"
    . "<tr><td style='padding:3px 10px 3px 0;color:#667'>Data / horário</td><td>" . $agora->format('d/m/Y') . " às " . $agora->format('H:i') . "</td></tr>"
    . "<tr><td style='padding:3px 10px 3px 0;color:#667'>Responsável pela alteração</td><td>" . htmlspecialchars($byUser['nome'] ?? $byUser['email'] ?? '-') . "</td></tr>"
    . "</table>"
    . "<p style='margin-top:12px'><b>Descrição do apontamento:</b><br>" . nl2br(htmlspecialchars($ap['conteudo'])) . "</p>"
    . ($obs !== null && trim($obs) !== '' ? "<p><b>Observação:</b><br>" . nl2br(htmlspecialchars($obs)) . "</p>" : '');

  $saudacaoTpl = defined('APONT_EMAIL_SAUDACAO') ? APONT_EMAIL_SAUDACAO : 'Olá, {NOME}, você indicou um apontamento';
  $assunto = "[AutoriaSCS] Apontamento — {$ap['nome_curso']}: " . $lbl($statusPara);

  // professores do curso
  $profs = function_exists('curso_professores') ? curso_professores($idCurso) : [];
  if (!$profs) $profs = [['nome' => $ap['professor_nome'], 'email' => $ap['professor_email']]];
  foreach ($profs as $p) {
    $saud = str_replace('{NOME}', htmlspecialchars($p['nome']), htmlspecialchars($saudacaoTpl));
    $corpo = "<p>{$saud} no curso <b>" . htmlspecialchars($ap['nome_curso']) . "</b>. Situação atual: <b>" . htmlspecialchars($lbl($statusPara)) . "</b>.</p>" . $detalhes;
    notify_queue($p['email'], $p['nome'], $assunto, mail_template($titulo, $corpo, $link, 'Abrir o apontamento'));
  }
  // caixa institucional da TI
  $corpoTI = "<p>Olá, Equipe TI &amp; AutoriaSCS. Registro de apontamento do curso <b>" . htmlspecialchars($ap['nome_curso']) . "</b>.</p>" . $detalhes;
  notify_queue(ti_email(), 'Equipe TI & AutoriaSCS', $assunto, mail_template($titulo, $corpoTI, $link, 'Abrir o apontamento'));
}

/** Compatibilidade (chamadas antigas): registra pelo fluxo novo. */
function notify_apontamento(array $curso, string $tipo, string $conteudo): void {
  $ap = [
    'id_apontamento' => 0, 'id_curso' => (int)$curso['id_curso'], 'nome_curso' => $curso['nome_curso'],
    'professor_nome' => $curso['professor_nome'], 'professor_email' => $curso['professor_email'],
    'tipo' => $tipo, 'conteudo' => $conteudo, 'arquivo_nome' => '',
  ];
  notify_apontamento_evento($ap, 'criado', auth_user() ?? [], null, 'PENDENTE_ANALISE', null);
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

/* ============================================================
 * Revisão de vídeos — notificações automáticas
 *
 * Fluxo: a MB Estúdios publica o vídeo na plataforma → o(a) formador(a)
 * analisa e marca os pontos com problema → a MB corrige e reenvia →
 * o(a) formador(a) aprova.
 * ============================================================ */

/** E-mail ao formador quando a MB disponibiliza um vídeo (v1 ou nova versão). */
function notify_video_disponivel(array $video, int $numero, ?string $obsVersao = null): void {
  $link = app_base_url() . '/video_revisao.php?id=' . (int)$video['id_video'];
  $mod = ((int)$video['modulo']) ? 'Módulo ' . (int)$video['modulo'] : 'Geral';

  $descricao = trim((string)($video['descricao'] ?? ''));
  $blocoDesc = $descricao !== ''
    ? "<p><b>Descrição do vídeo:</b><br>" . nl2br(htmlspecialchars($descricao)) . "</p>"
    : '';
  $rotuloObs = $numero > 1 ? 'O que mudou nesta versão' : 'Observação da MB Estúdios';
  $blocoObs = $obsVersao
    ? "<p><b>{$rotuloObs}:</b><br>" . nl2br(htmlspecialchars($obsVersao)) . "</p>"
    : '';
  $titulo = $numero > 1
    ? "Nova versão de vídeo disponível para análise (v{$numero})"
    : 'Vídeo disponível para sua análise';

  $html = mail_template(
    $titulo,
    "<p>A <b>MB Estúdios</b> disponibilizou " . ($numero > 1 ? "a <b>versão {$numero}</b> do" : "o") . " vídeo
     <b>" . htmlspecialchars($video['titulo']) . "</b> ({$mod}) do curso
     <b>" . htmlspecialchars($video['nome_curso']) . "</b>.</p>
     {$blocoDesc}{$blocoObs}
     <p>O vídeo já está <b>liberado na plataforma</b>. Assista pelo sistema e, se encontrar algum
     problema, marque o ponto exato (minuto/segundo/frame) com a orientação de correção.</p>",
    $link, 'Assistir e analisar o vídeo'
  );
  foreach (notify_professores_curso($video) as $p) notify_queue($p['email'], $p['nome'], $titulo, $html);
}

/** E-mail à MB quando o formador registra apontamentos no vídeo. */
function notify_video_marcacao(array $video, int $novas): void {
  $link = app_base_url() . '/video_revisao.php?id=' . (int)$video['id_video'];
  $txt = $novas === 1 ? 'um novo apontamento' : "{$novas} novos apontamentos";
  $assunto = 'Vídeo com apontamentos do(a) formador(a)';
  $html = mail_template(
    'Apontamentos registrados em vídeo',
    "<p>O(a) formador(a) <b>" . htmlspecialchars($video['professor_nome']) . "</b> registrou
     <b>{$txt}</b> no vídeo \"<b>" . htmlspecialchars($video['titulo']) . "</b>\" do curso
     \"<b>" . htmlspecialchars($video['nome_curso']) . "</b>\".</p>
     <p>Cada apontamento indica o momento exato do vídeo, o problema identificado e a orientação
     de correção. Após ajustar, reenvie a nova versão pelo próprio sistema.</p>",
    $link, 'Ver os apontamentos'
  );
  notify_flag('recebe_email_insercao', $assunto, $html);
  notify_queue(ti_email(), 'Equipe TI & AutoriaSCS', $assunto, $html);
}

/** E-mail à MB e à TI quando o formador aprova o vídeo. */
function notify_video_aprovado(array $video): void {
  $link = app_base_url() . '/video_revisao.php?id=' . (int)$video['id_video'];
  $assunto = 'Vídeo aprovado pelo(a) formador(a)';
  $html = mail_template(
    'Vídeo aprovado! 🎉',
    "<p>O vídeo \"<b>" . htmlspecialchars($video['titulo']) . "</b>\" do curso
     \"<b>" . htmlspecialchars($video['nome_curso']) . "</b>\" foi <b>aprovado</b> pelo(a) formador(a)
     " . htmlspecialchars($video['professor_nome']) . ".</p>
     <p>Todas as correções foram concluídas — nenhuma ação adicional é necessária neste vídeo.</p>",
    $link, 'Ver o vídeo'
  );
  notify_flag('recebe_email_insercao', $assunto, $html);
  notify_queue(ti_email(), 'Equipe TI & AutoriaSCS', $assunto, $html);
}

/** E-mail à outra parte quando alguém responde a um apontamento. */
function notify_video_resposta(array $video, string $autor, bool $paraFormador): void {
  $link = app_base_url() . '/video_revisao.php?id=' . (int)$video['id_video'];
  $assunto = 'Resposta em apontamento de vídeo';
  $html = mail_template(
    'Resposta em apontamento de vídeo',
    "<p><b>" . htmlspecialchars($autor) . "</b> respondeu a um apontamento do vídeo
     \"<b>" . htmlspecialchars($video['titulo']) . "</b>\" do curso
     \"<b>" . htmlspecialchars($video['nome_curso']) . "</b>\".</p>",
    $link, 'Ver a conversa'
  );
  if ($paraFormador) {
    foreach (notify_professores_curso($video) as $p) notify_queue($p['email'], $p['nome'], $assunto, $html);
  } else {
    notify_flag('recebe_email_insercao', $assunto, $html);
    notify_queue(ti_email(), 'Equipe TI & AutoriaSCS', $assunto, $html);
  }
}
