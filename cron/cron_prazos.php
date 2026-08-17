<?php
/**
 * Cron diário (sugestão: 07h): alertas de prazo + resumo semanal às segundas.
 *
 * Agendar no cPanel 1x AO DIA:
 *   php /home/USUARIO/caminho/cron/cron_prazos.php SUA_CHAVE
 * ou via URL: https://.../cron/cron_prazos.php?chave=SUA_CHAVE
 *
 * - Formador: e-mail por curso atrasado ou vencendo em até 7 dias;
 * - TI: resumo diário consolidado dos prazos críticos;
 * - Segunda-feira: resumo semanal (formador: seus cursos; TI: painel geral; MB: fila de inserção).
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

$base = app_base_url();
$enq = 0;

// ---------------------------------------------------------------------------
// 1) Prazos críticos (vencidos ou <= 7 dias, status não-final)
// ---------------------------------------------------------------------------
$criticos = db()->query("
  SELECT c.id_curso, c.nome_curso, c.data_prevista_entrega_final, c.status_atual,
         u.nome prof_nome, u.email prof_email
  FROM tb_cursos c
  JOIN tb_users u ON u.id_user = c.id_professor
  LEFT JOIN tb_status s ON s.nome = c.status_atual
  WHERE c.data_prevista_entrega_final IS NOT NULL
    AND COALESCE(s.is_final,0) = 0
    AND c.data_prevista_entrega_final <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  ORDER BY c.data_prevista_entrega_final
")->fetchAll();

$hoje = date('Y-m-d');
$linhasTI = '';

foreach ($criticos as $c) {
  $vencido = $c['data_prevista_entrega_final'] < $hoje;
  $situ = $vencido ? 'ATRASADO' : 'vence em breve';
  $link = $base . '/curso_detalhe.php?id=' . (int)$c['id_curso'];

  $corpo = "<p>Curso: <b>" . htmlspecialchars($c['nome_curso']) . "</b><br>"
         . "Status atual: " . htmlspecialchars($c['status_atual']) . "<br>"
         . "Entrega prevista: <b>" . htmlspecialchars($c['data_prevista_entrega_final']) . "</b> ({$situ})</p>"
         . ($vencido
            ? "<p>O prazo está vencido. Atualize o andamento no sistema ou fale com a equipe de TI.</p>"
            : "<p>Faltam poucos dias para a entrega prevista. Verifique se o material será concluído no prazo.</p>");

  notify_queue($c['prof_email'], $c['prof_nome'],
    "[AutoriaSCS] " . ($vencido ? "Prazo VENCIDO" : "Prazo próximo") . ": {$c['nome_curso']}",
    mail_template($vencido ? '⚠ Prazo vencido' : 'Prazo de entrega se aproximando', $corpo, $link, 'Abrir curso'));
  $enq++;

  $linhasTI .= "<li><b>" . htmlspecialchars($c['nome_curso']) . "</b> — "
             . htmlspecialchars($c['prof_nome']) . " — "
             . htmlspecialchars($c['data_prevista_entrega_final']) . " ({$situ})</li>";
}

if ($linhasTI !== '') {
  notify_role('TI', "[AutoriaSCS] Resumo diário: " . count($criticos) . " curso(s) com prazo crítico",
    mail_template('Prazos críticos de hoje', "<ul>{$linhasTI}</ul>", $base . '/relatorios.php', 'Ver relatórios'));
  $enq++;
}

// ---------------------------------------------------------------------------
// 2) Resumo semanal (segunda-feira)
// ---------------------------------------------------------------------------
if ((int)date('N') === 1) {

  // Formadores: situação dos seus cursos ativos
  $profs = db()->query("
    SELECT u.id_user, u.nome, u.email
    FROM tb_users u
    WHERE u.role='PROFESSOR' AND u.ativo=1
      AND EXISTS (
        SELECT 1 FROM tb_cursos c
        LEFT JOIN tb_status s ON s.nome=c.status_atual
        WHERE c.id_professor=u.id_user AND COALESCE(s.is_final,0)=0
      )
  ")->fetchAll();

  foreach ($profs as $p) {
    $cs = db()->prepare("
      SELECT nome_curso, status_atual, data_prevista_entrega_final
      FROM tb_cursos c
      LEFT JOIN tb_status s ON s.nome=c.status_atual
      WHERE id_professor=? AND COALESCE(s.is_final,0)=0
      ORDER BY updated_at DESC
    ");
    $cs->execute([(int)$p['id_user']]);
    $itens = '';
    foreach ($cs->fetchAll() as $c) {
      $itens .= "<li><b>" . htmlspecialchars($c['nome_curso']) . "</b> — "
              . htmlspecialchars($c['status_atual'])
              . ($c['data_prevista_entrega_final'] ? " — entrega: " . htmlspecialchars($c['data_prevista_entrega_final']) : "")
              . "</li>";
    }
    if ($itens === '') continue;
    notify_queue($p['email'], $p['nome'], "[AutoriaSCS] Resumo semanal dos seus cursos",
      mail_template('Como estão seus cursos nesta semana', "<ul>{$itens}</ul>", $base . '/dashboard.php', 'Abrir o sistema'));
    $enq++;
  }

  // TI: painel geral
  $porStatus = db()->query("
    SELECT status_atual, COUNT(*) n FROM tb_cursos GROUP BY status_atual ORDER BY n DESC
  ")->fetchAll();
  $itens = '';
  foreach ($porStatus as $r) {
    $itens .= "<li>" . htmlspecialchars($r['status_atual']) . ": <b>" . (int)$r['n'] . "</b></li>";
  }
  $pend = (int)db()->query("SELECT COUNT(*) n FROM tb_curso_apontamentos WHERE resolvido=0")->fetch()['n'];
  notify_role('TI', "[AutoriaSCS] Resumo semanal do painel",
    mail_template('Painel geral da produção de cursos',
      "<ul>{$itens}</ul><p>Apontamentos pendentes: <b>{$pend}</b></p>",
      $base . '/relatorios.php', 'Ver relatórios'));
  $enq++;

  // MB: fila de inserção
  $fila = db()->query("
    SELECT nome_curso FROM tb_cursos
    WHERE status_atual IN ('Enviado para Inserção','Em Inserção')
    ORDER BY updated_at
  ")->fetchAll();
  if ($fila) {
    $itens = '';
    foreach ($fila as $c) $itens .= "<li>" . htmlspecialchars($c['nome_curso']) . "</li>";
    notify_role('MB', "[AutoriaSCS] Resumo semanal: " . count($fila) . " curso(s) na fila de inserção",
      mail_template('Cursos aguardando inserção na plataforma', "<ul>{$itens}</ul>", $base . '/dashboard.php', 'Abrir o sistema'));
    $enq++;
  }
}

// dispara um lote imediatamente após enfileirar
[$ok, $err] = notify_send_pending(50);
echo "Prazos: {$enq} notificação(ões) enfileirada(s); envio: {$ok} ok, {$err} erro(s).\n";
