<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/status_repo.php';
require_once __DIR__ . '/n8n_client.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/escolas_repo.php';
require_once __DIR__ . '/entregas_repo.php';

function curso_create(int $id_prof, array $d, array $coautores = []): int {
  $inicial = status_inicial();

  // valores de cadastro: nível ativo e escola ativa (ou em branco)
  if (!nivel_valido_para_curso($d['nivel_ensino'] ?? '')) {
    throw new Exception("Nível de ensino inválido. Escolha um nível da lista.");
  }
  $escolas = escolas_ativas();
  if (($d['unidade_escolar'] ?? '') !== '' && $escolas && !in_array($d['unidade_escolar'], $escolas, true)) {
    throw new Exception("Unidade escolar inválida. Escolha uma escola da lista.");
  }

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

  // professores do curso (V11): responsável + coautores escolhidos na proposta
  if (curso_professores_disponivel()) {
    db()->prepare("INSERT IGNORE INTO tb_curso_professores (id_curso, id_usuario, tipo) VALUES (?,?,'RESPONSAVEL')")->execute([$id, $id_prof]);
    foreach (array_unique(array_map('intval', $coautores)) as $idCo) {
      if ($idCo === $id_prof || !formador_valido($idCo)) continue;
      db()->prepare("INSERT IGNORE INTO tb_curso_professores (id_curso, id_usuario, tipo) VALUES (?,?,'COAUTOR')")->execute([$id, $idCo]);
      audit_log('coautor_adicionado', 'curso', $id, null, ['id_usuario' => $idCo]);
    }
  }

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
           . "Carga horária: " . htmlspecialchars($curso['carga_horaria']) . " horas<br>"
           . "Status: <b>" . htmlspecialchars($inicial) . "</b></p>";
    notify_queue($curso['professor_email'], $curso['professor_nome'],
      "[AutoriaSCS] Curso proposto: {$curso['nome_curso']}",
      mail_template('Sua proposta de curso foi registrada', $corpo, $link, 'Acompanhar meu curso'));
    notify_flag('recebe_email_revisao', "[AutoriaSCS] Novo curso no backlog: {$curso['nome_curso']}",
      mail_template('Novo curso proposto por ' . $curso['professor_nome'], $corpo, $link, 'Ver curso'));
  }

  n8n_emit_event('curso_created', ['id_curso' => $id, 'id_professor' => $id_prof]);
  return $id;
}

/**
 * Campos do curso que só a equipe de revisão (TI) / ADMIN alteram (item 5).
 * Para o formador eles são somente-leitura — e o backend ignora/recusa o envio.
 */
const CURSO_CAMPOS_GESTAO = ['unidade_escolar', 'prioridade', 'data_prevista_inicio',
                             'data_prevista_entrega_final', 'carga_horaria'];

/**
 * Atualiza os dados do curso. $user é quem está editando: as regras de
 * permissão são aplicadas aqui (backend), não apenas na tela:
 *  - formador (sem revisa_cursos): não altera os campos de gestão; tentativa de
 *    alterar carga horária/prioridade → 403;
 *  - carga horária após "Projeto Aprovado": somente TI/ADMIN (auditado antes/depois).
 */
function curso_update(int $id_curso, array $d, ?array $user = null): void {
  $antes = curso_get($id_curso) ?: [];
  if (!$antes) throw new Exception("Curso não encontrado.");
  $user = $user ?? (function_exists('auth_user') ? auth_user() : null) ?? [];
  $ehGestao = !empty($user['role']) && perfil_flag($user['role'], 'revisa_cursos');

  if (!nivel_valido_para_curso($d['nivel_ensino'] ?? '', $antes['nivel_ensino'] ?? null)) {
    throw new Exception("Nível de ensino inválido. Escolha um nível da lista.");
  }

  if (!$ehGestao) {
    // tentativa explícita de mudar campos protegidos → bloqueio (não apenas ignorar)
    $tentouCarga = isset($d['carga_horaria']) && (int)$d['carga_horaria'] !== (int)$antes['carga_horaria'];
    $tentouPrio  = isset($d['prioridade']) && $d['prioridade'] !== '' && $d['prioridade'] !== $antes['prioridade'];
    if ($tentouCarga || $tentouPrio) {
      http_response_code(403);
      audit_log('curso_edicao_negada', 'curso', $id_curso,
        ['carga_horaria' => $antes['carga_horaria'], 'prioridade' => $antes['prioridade']],
        ['carga_horaria' => $d['carga_horaria'] ?? null, 'prioridade' => $d['prioridade'] ?? null]);
      throw new Exception("Sem permissão: carga horária e prioridade são definidas pela equipe de TI/ADMIN.");
    }
    // demais campos de gestão: mantém os valores atuais
    foreach (CURSO_CAMPOS_GESTAO as $k) $d[$k] = $antes[$k];
  } else {
    // TI/ADMIN: escola precisa existir no cadastro (ou manter a atual)
    $escolas = escolas_ativas();
    $unid = $d['unidade_escolar'] ?? '';
    if ($unid !== '' && $unid !== ($antes['unidade_escolar'] ?? '') && $escolas && !in_array($unid, $escolas, true)) {
      throw new Exception("Unidade escolar inválida. Escolha uma escola da lista.");
    }
    if (!in_array((int)$d['carga_horaria'], carga_horaria_opcoes(), true)) {
      throw new Exception("Carga horária inválida (10, 20, 30 ou 40 horas).");
    }
  }

  $st = db()->prepare("
    UPDATE tb_cursos SET
      nome_curso=?, carga_horaria=?, publico_alvo=?, nivel_ensino=?, unidade_escolar=?,
      prioridade=?, data_prevista_inicio=?, data_prevista_entrega_final=?, descricao_breve=?
    WHERE id_curso=?
  ");
  $st->execute([
    $d['nome_curso'],
    (int)$d['carga_horaria'],
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

  // auditorias específicas (item 27): carga horária e prioridade, com antes/depois
  if (isset($dd['carga_horaria'])) {
    audit_log('carga_horaria_alterada', 'curso', $id_curso,
      ['carga_horaria' => $da['carga_horaria'], 'projeto_aprovado' => !empty($antes['projeto_aprovado_em'])],
      ['carga_horaria' => $dd['carga_horaria']]);
  }
  if (isset($dd['prioridade'])) {
    audit_log('prioridade_alterada', 'curso', $id_curso,
      ['prioridade' => $da['prioridade']], ['prioridade' => $dd['prioridade']]);
  }
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

/** Usuário ativo com perfil que propõe cursos (formador)? */
function formador_valido(int $idUser): bool {
  $st = db()->prepare("SELECT role FROM tb_users WHERE id_user=? AND ativo=1");
  $st->execute([$idUser]);
  $r = $st->fetch();
  return $r && perfil_flag($r['role'], 'propoe_cursos') && !perfil_flag($r['role'], 'admin_total');
}

/** Formadores ativos (id + nome) para seleção de coautores. */
function formadores_usuarios(): array {
  try {
    return db()->query("
      SELECT u.id_user, u.nome, u.email FROM tb_users u
      JOIN tb_perfis p ON p.codigo = u.role
      WHERE u.ativo=1 AND p.propoe_cursos=1 AND p.admin_total=0
      ORDER BY u.nome")->fetchAll();
  } catch (Throwable $e) {
    return db()->query("SELECT id_user, nome, email FROM tb_users WHERE ativo=1 AND role='PROFESSOR' ORDER BY nome")->fetchAll();
  }
}

/** Pode gerenciar coautores: responsável do curso ou equipe de revisão/ADMIN. */
function curso_pode_gerir_professores(array $curso, array $user): bool {
  if (empty($user['id_user'])) return false;
  if (!empty($user['role']) && perfil_flag($user['role'], 'revisa_cursos')) return true;
  return (int)$curso['id_professor'] === (int)$user['id_user'];
}

/** Adiciona um coautor (item 22/23). Regras no backend; auditado. */
function curso_coautor_adicionar(array $curso, int $idUser, array $user): void {
  if (!curso_pode_gerir_professores($curso, $user)) { http_response_code(403); throw new Exception("Sem permissão para incluir professores neste curso."); }
  if (!curso_professores_disponivel()) throw new Exception("Execute database/upgrade_v11.sql para habilitar coautores.");
  if ($idUser === (int)$curso['id_professor']) { http_response_code(422); throw new Exception("Este usuário já é o professor responsável."); }
  if (!formador_valido($idUser)) { http_response_code(422); throw new Exception("Selecione um(a) formador(a) ativo(a) cadastrado(a) no sistema."); }
  $st = db()->prepare("INSERT IGNORE INTO tb_curso_professores (id_curso, id_usuario, tipo) VALUES (?,?,'COAUTOR')");
  $st->execute([(int)$curso['id_curso'], $idUser]);
  if ($st->rowCount() === 0) { http_response_code(422); throw new Exception("Este(a) professor(a) já participa do curso."); }
  audit_log('coautor_adicionado', 'curso', (int)$curso['id_curso'], null, ['id_usuario' => $idUser]);
}

/** Remove um coautor (o responsável nunca é removido por aqui). */
function curso_coautor_remover(array $curso, int $idUser, array $user): void {
  if (!curso_pode_gerir_professores($curso, $user)) { http_response_code(403); throw new Exception("Sem permissão para remover professores deste curso."); }
  if ($idUser === (int)$curso['id_professor']) { http_response_code(422); throw new Exception("O professor responsável não pode ser removido."); }
  $st = db()->prepare("DELETE FROM tb_curso_professores WHERE id_curso=? AND id_usuario=? AND tipo='COAUTOR'");
  $st->execute([(int)$curso['id_curso'], $idUser]);
  if ($st->rowCount() === 0) { http_response_code(404); throw new Exception("Coautor não encontrado neste curso."); }
  audit_log('coautor_removido', 'curso', (int)$curso['id_curso'], ['id_usuario' => $idUser], null);
}

/** A tabela de professores do curso (V11) já existe? (cache por requisição) */
function curso_professores_disponivel(): bool {
  static $ok = null;
  if ($ok === null) {
    try { db()->query("SELECT 1 FROM tb_curso_professores LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
  }
  return $ok;
}

/**
 * Professores do curso (V11): responsável (tb_cursos.id_professor) + coautores
 * (tb_curso_professores). Antes da migração V11 devolve só o responsável.
 */
function curso_professores(int $idCurso): array {
  try {
    $st = db()->prepare("
      SELECT cp.id_usuario AS id_user, cp.tipo, u.nome, u.email
      FROM tb_curso_professores cp
      JOIN tb_users u ON u.id_user = cp.id_usuario
      WHERE cp.id_curso = ?
      ORDER BY FIELD(cp.tipo,'RESPONSAVEL','COAUTOR'), u.nome
    ");
    $st->execute([$idCurso]);
    $rows = $st->fetchAll();
    if ($rows) return $rows;
  } catch (Throwable $e) { /* upgrade_v11.sql ainda não executado */ }
  $c = curso_get($idCurso);
  return $c ? [['id_user' => (int)$c['id_professor'], 'tipo' => 'RESPONSAVEL',
                'nome' => $c['professor_nome'], 'email' => $c['professor_email']]] : [];
}

/** O usuário é professor (responsável ou coautor) do curso? */
function curso_eh_professor(array $curso, int $idUser): bool {
  if ((int)$curso['id_professor'] === $idUser) return true;
  foreach (curso_professores((int)$curso['id_curso']) as $p) {
    if ((int)$p['id_user'] === $idUser) return true;
  }
  return false;
}

/** "Nome A", "Nome A e Nome B" ou "Nome A, Nome B e Nome C". */
function curso_formadores_nomes(array $curso): string {
  $nomes = array_map(fn($p) => trim($p['nome']), curso_professores((int)$curso['id_curso']));
  $nomes = array_values(array_filter($nomes));
  if (!$nomes) return trim($curso['professor_nome'] ?? '');
  if (count($nomes) === 1) return $nomes[0];
  $ultimo = array_pop($nomes);
  return implode(', ', $nomes) . ' e ' . $ultimo;
}

/**
 * Identificação oficial do curso, usada em e-mails, no cabeçalho da página e
 * no nome da pasta/pacote entregue à MB:
 *   "Nome do curso - Nome dos formadores - Carga Horária do curso"
 */
function curso_identificacao(array $curso): string {
  $partes = [
    trim($curso['nome_curso'] ?? ''),
    isset($curso['id_curso']) ? curso_formadores_nomes($curso) : trim($curso['professor_nome'] ?? ''),
    ((int)($curso['carga_horaria'] ?? 0)) . ' horas',
  ];
  return implode(' - ', array_filter($partes, fn($p) => $p !== '' && $p !== ' horas'));
}

function checklist_get(int $id_curso): array {
  $st = db()->prepare("SELECT * FROM tb_curso_checklist WHERE id_curso=?");
  $st->execute([$id_curso]);
  return $st->fetch() ?: [];
}

function curso_transition(int $id_curso, array $user, string $to, ?string $obs = null,
                          ?string $dataPublicacao = null, ?int $cargaHoraria = null): void {
  $c = curso_get($id_curso);
  if (!$c) throw new Exception("Curso não encontrado.");

  $from = $c['status_atual'];
  if (!can_transition($user['role'], $from, $to)) {
    throw new Exception("Transição inválida: {$from} → {$to} para o perfil {$user['role']}.");
  }

  // ao liberar para publicação, a data de entrada na plataforma é obrigatória
  if ($to === 'Pronto para Publicação') {
    $d = DateTime::createFromFormat('Y-m-d', (string)$dataPublicacao);
    if (!$d || $d->format('Y-m-d') !== $dataPublicacao) {
      throw new Exception("Informe a data em que o curso entrará na plataforma.");
    }
  }

  // ao aprovar o projeto, a TI define oficialmente a carga horária do curso
  if ($to === 'Projeto Aprovado') {
    if (!in_array($cargaHoraria, carga_horaria_opcoes(), true)) {
      throw new Exception("Informe a carga horária do curso (10, 20, 30 ou 40 horas).");
    }
  }

  // quem não enxerga todos os cursos (formador/coautor) só mexe no próprio
  if (!perfil_flag($user['role'], 've_todos_cursos') &&
      !curso_eh_professor($c, (int)$user['id_user'])) {
    http_response_code(403);
    throw new Exception("Sem permissão.");
  }

  // documentos obrigatórios (itens 9-11): status que "exige entregas" só recebe
  // o curso com todas as categorias obrigatórias entregues
  $sTo = status_by_name($to);
  if ($sTo && !empty($sTo['exige_entregas'])) {
    $pend = entregas_pendentes($id_curso, (int)$c['carga_horaria']);
    if ($pend) {
      audit_log('transicao_bloqueada_entregas', 'curso', $id_curso, ['status' => $from],
        ['tentativa' => $to, 'pendencias' => array_column($pend, 'rotulo')]);
      notify_entregas_pendentes($c, $to, $pend, $user);
      http_response_code(422);
      throw new EntregasPendentesException(
        "Não é possível avançar para a próxima etapa. Os seguintes documentos obrigatórios ainda não foram enviados: "
        . implode('; ', array_column($pend, 'rotulo')) . '.', $pend);
    }
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

    // grava a data oficial de publicação informada pela TI
    if ($to === 'Pronto para Publicação') {
      db()->prepare("UPDATE tb_cursos SET publication_due_date=? WHERE id_curso=?")
        ->execute([$dataPublicacao, $id_curso]);
    }

    // grava a carga horária oficial definida pela TI ao aprovar o projeto
    if ($to === 'Projeto Aprovado') {
      db()->prepare("UPDATE tb_cursos SET carga_horaria=?, projeto_aprovado_em=NOW() WHERE id_curso=?")
        ->execute([$cargaHoraria, $id_curso]);
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
