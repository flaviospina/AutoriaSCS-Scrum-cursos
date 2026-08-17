<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/status_repo.php';
require_once __DIR__ . '/n8n_client.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notify.php';

function curso_create(int $id_prof, array $d): int {
  $inicial = status_inicial();

  $st = db()->prepare("
    INSERT INTO tb_cursos
      (id_professor, nome_curso, carga_horaria, publico_alvo, nivel_ensino, unidade_escolar,
       prioridade, data_prevista_inicio, data_prevista_entrega_final, descricao_breve, status_atual)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
  ");
  $st->execute([
    $id_prof,
    $d['nome_curso'],
    $d['carga_horaria'],
    $d['publico_alvo'],
    $d['nivel_ensino'] ?: null,
    $d['unidade_escolar'] ?: null,
    in_array($d['prioridade'] ?? 'MEDIA', prioridades(), true) ? $d['prioridade'] : 'MEDIA',
    $d['data_prevista_inicio'] ?: null,
    $d['data_prevista_entrega_final'] ?: null,
    $d['descricao_breve'] ?: null,
    $inicial,
  ]);
  $id = (int)db()->lastInsertId();

  db()->prepare("INSERT INTO tb_curso_checklist (id_curso) VALUES (?)")->execute([$id]);

  db()->prepare("
    INSERT INTO tb_curso_status_history (id_curso, status_de, status_para, id_user, observacao)
    VALUES (?, '', ?, ?, 'Criação do curso')
  ")->execute([$id, $inicial, $id_prof]);

  audit_log('curso_criado', 'curso', $id, null, [
    'nome_curso' => $d['nome_curso'],
    'carga_horaria' => $d['carga_horaria'],
    'status_inicial' => $inicial,
  ]);

  // notificações: confirmação ao formador + aviso à TI
  $curso = curso_get($id);
  if ($curso) {
    $link = app_base_url() . '/curso_detalhe.php?id=' . $id;
    $corpo = "<p>Curso: <b>" . htmlspecialchars($curso['nome_curso']) . "</b><br>"
           . "Carga horária: " . htmlspecialchars($curso['carga_horaria']) . "h<br>"
           . "Status: <b>" . htmlspecialchars($inicial) . "</b></p>";
    notify_queue($curso['professor_email'], $curso['professor_nome'],
      "[AutoriaSCS] Curso proposto: {$curso['nome_curso']}",
      mail_template('Sua proposta de curso foi registrada', $corpo, $link, 'Acompanhar meu curso'));
    notify_role('TI', "[AutoriaSCS] Novo curso no backlog: {$curso['nome_curso']}",
      mail_template('Novo curso proposto por ' . $curso['professor_nome'], $corpo, $link, 'Ver curso'));
  }

  n8n_emit_event('curso_created', ['id_curso' => $id, 'id_professor' => $id_prof]);
  return $id;
}

function curso_update(int $id_curso, array $d): void {
  $antes = curso_get($id_curso) ?: [];
  $st = db()->prepare("
    UPDATE tb_cursos SET
      nome_curso=?, carga_horaria=?, publico_alvo=?, nivel_ensino=?, unidade_escolar=?,
      prioridade=?, data_prevista_inicio=?, data_prevista_entrega_final=?, descricao_breve=?
    WHERE id_curso=?
  ");
  $st->execute([
    $d['nome_curso'],
    $d['carga_horaria'],
    $d['publico_alvo'],
    $d['nivel_ensino'] ?: null,
    $d['unidade_escolar'] ?: null,
    in_array($d['prioridade'] ?? 'MEDIA', prioridades(), true) ? $d['prioridade'] : 'MEDIA',
    $d['data_prevista_inicio'] ?: null,
    $d['data_prevista_entrega_final'] ?: null,
    $d['descricao_breve'] ?: null,
    $id_curso,
  ]);

  $depois = curso_get($id_curso) ?: [];
  $campos = ['nome_curso','carga_horaria','publico_alvo','nivel_ensino','unidade_escolar',
             'prioridade','data_prevista_inicio','data_prevista_entrega_final','descricao_breve'];
  [$da, $dd] = audit_diff(
    array_intersect_key($antes, array_flip($campos)),
    array_intersect_key($depois, array_flip($campos))
  );
  if ($dd) audit_log('curso_editado', 'curso', $id_curso, $da, $dd);
}

function curso_get(int $id_curso): ?array {
  $st = db()->prepare("
    SELECT c.*, u.nome AS professor_nome, u.email AS professor_email
    FROM tb_cursos c
    JOIN tb_users u ON u.id_user = c.id_professor
    WHERE c.id_curso=?
  ");
  $st->execute([$id_curso]);
  $c = $st->fetch();
  return $c ?: null;
}

function checklist_get(int $id_curso): array {
  $st = db()->prepare("SELECT * FROM tb_curso_checklist WHERE id_curso=?");
  $st->execute([$id_curso]);
  return $st->fetch() ?: [];
}

function curso_transition(int $id_curso, array $user, string $to, ?string $obs = null): void {
  $c = curso_get($id_curso);
  if (!$c) throw new Exception("Curso não encontrado.");

  $from = $c['status_atual'];
  if (!can_transition($user['role'], $from, $to)) {
    throw new Exception("Transição inválida: {$from} → {$to} para o perfil {$user['role']}.");
  }

  // professor só mexe no próprio curso
  if ($user['role'] === 'PROFESSOR' && (int)$c['id_professor'] !== (int)$user['id_user']) {
    throw new Exception("Sem permissão.");
  }

  db()->beginTransaction();
  try {
    db()->prepare("UPDATE tb_cursos SET status_atual=? WHERE id_curso=?")->execute([$to, $id_curso]);

    db()->prepare("
      INSERT INTO tb_curso_status_history (id_curso, status_de, status_para, id_user, observacao)
      VALUES (?,?,?,?,?)
    ")->execute([$id_curso, $from, $to, $user['id_user'], $obs]);

    // calcular data de publicação ao validar
    if ($to === 'Validado') {
      // regra: 1º dia útil do mês subsequente à inserção
      $base = new DateTime($c['inserted_at'] ?: 'now');
      $base->modify('first day of next month');
      $dow = (int)$base->format('N'); // 6 sáb, 7 dom
      if ($dow === 6) $base->modify('+2 days');
      if ($dow === 7) $base->modify('+1 day');

      db()->prepare("UPDATE tb_cursos SET publication_due_date=?, validated_at=NOW() WHERE id_curso=?")
        ->execute([$base->format('Y-m-d'), $id_curso]);
    }

    if ($to === 'Inserido') {
      db()->prepare("UPDATE tb_cursos SET inserted_at=NOW() WHERE id_curso=?")->execute([$id_curso]);
    }

    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }

  audit_log('curso_status', 'curso', $id_curso,
    ['status' => $from], ['status' => $to, 'observacao' => $obs]);

  // e-mails conforme matriz de eventos (curso recarregado p/ pegar publication_due_date)
  $cursoAtualizado = curso_get($id_curso) ?: $c;
  notify_event_status($cursoAtualizado, $from, $to, $user);

  n8n_emit_event('status_changed', [
    'id_curso' => $id_curso,
    'from' => $from,
    'to' => $to,
    'by' => ['id_user' => $user['id_user'], 'role' => $user['role'], 'email' => $user['email']],
  ]);
}
