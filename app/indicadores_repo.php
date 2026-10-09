<?php
/**
 * Indicadores gerenciais e relatórios (V14).
 *
 *  - IND_GRUPOS / ind_todos(): catálogo dos indicadores (KPI) agrupados, cada um
 *    com o relatório correspondente (chave usada em relatorios.php?r=...).
 *  - REL_CATALOGO / rel_gerar(): relatórios tabulares com filtros, usados pela
 *    página de relatórios (HTML/CSV/impressão).
 *
 * Todas as consultas usam prepared statements; filtros aceitos:
 *   de, ate (período de criação do curso ou do registro), status, id_professor,
 *   unidade_escolar, nivel_ensino, prioridade, carga_horaria, tipo, dim, janela.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/status_repo.php';

/* ============================================================
 * Utilitários
 * ============================================================ */
function ind_n(string $sql, array $p = []): int {
  try { $st = db()->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
  catch (Throwable $e) { return 0; }
}
function ind_rows(string $sql, array $p = []): array {
  try { $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll(); }
  catch (Throwable $e) { return []; }
}
function ind_val(string $sql, array $p = []) {
  try { $st = db()->prepare($sql); $st->execute($p); $v = $st->fetchColumn(); return $v === false ? null : $v; }
  catch (Throwable $e) { return null; }
}
function ind_tabela(string $t): bool {
  static $c = [];
  if (!isset($c[$t])) { try { db()->query("SELECT 1 FROM `$t` LIMIT 1"); $c[$t] = true; } catch (Throwable $e) { $c[$t] = false; } }
  return $c[$t];
}
function ind_fmt_bytes(int $b): string {
  if ($b >= 1073741824) return number_format($b / 1073741824, 2, ',', '.') . ' GB';
  if ($b >= 1048576)    return number_format($b / 1048576, 1, ',', '.') . ' MB';
  if ($b >= 1024)       return number_format($b / 1024, 0, ',', '.') . ' KB';
  return $b . ' B';
}
function ind_dias(?float $d): string { return $d === null ? '—' : number_format($d, 1, ',', '.') . ' d'; }
function ind_pct(int $parte, int $total): string { return $total ? round($parte * 100 / $total) . '%' : '—'; }

/** Nomes dos status finais (concluído/publicado). */
function ind_status_finais(): array {
  static $f = null;
  if ($f === null) $f = array_column(ind_rows("SELECT nome FROM tb_status WHERE is_final=1"), 'nome');
  return $f ?: ['Publicado'];
}
/** Trecho SQL "status final" para o alias c (tb_cursos). */
function ind_sql_final(string $alias = 'c'): string {
  return "EXISTS (SELECT 1 FROM tb_status sf WHERE sf.nome = {$alias}.status_atual AND sf.is_final=1)";
}

/**
 * Filtros comuns de cursos (alias c). Retorna [where, params, descrição].
 * Período (de/ate) aplicado a c.created_at.
 */
function ind_filtro_cursos(array $f, string $alias = 'c'): array {
  $w = []; $p = []; $desc = [];
  if (!empty($f['de']))  { $w[] = "{$alias}.created_at >= ?"; $p[] = $f['de'] . ' 00:00:00'; $desc[] = 'de ' . date('d/m/Y', strtotime($f['de'])); }
  if (!empty($f['ate'])) { $w[] = "{$alias}.created_at <= ?"; $p[] = $f['ate'] . ' 23:59:59'; $desc[] = 'até ' . date('d/m/Y', strtotime($f['ate'])); }
  if (!empty($f['status']))          { $w[] = "{$alias}.status_atual = ?";    $p[] = $f['status'];          $desc[] = 'status ' . $f['status']; }
  if (!empty($f['id_professor']))    { $w[] = "{$alias}.id_professor = ?";    $p[] = (int)$f['id_professor']; $desc[] = 'formador #' . (int)$f['id_professor']; }
  if (!empty($f['unidade_escolar'])) { $w[] = "{$alias}.unidade_escolar = ?"; $p[] = $f['unidade_escolar']; $desc[] = 'escola ' . $f['unidade_escolar']; }
  if (!empty($f['nivel_ensino']))    { $w[] = "{$alias}.nivel_ensino = ?";    $p[] = $f['nivel_ensino'];    $desc[] = 'nível ' . $f['nivel_ensino']; }
  if (!empty($f['prioridade']))      { $w[] = "{$alias}.prioridade = ?";      $p[] = $f['prioridade'];      $desc[] = 'prioridade ' . $f['prioridade']; }
  if (!empty($f['carga_horaria']))   { $w[] = "{$alias}.carga_horaria = ?";   $p[] = (int)$f['carga_horaria']; $desc[] = $f['carga_horaria'] . ' h'; }
  if (($f['situacao'] ?? '') === 'andamento') { $w[] = 'NOT ' . ind_sql_final($alias); $desc[] = 'em andamento'; }
  if (($f['situacao'] ?? '') === 'concluidos') { $w[] = ind_sql_final($alias); $desc[] = 'concluídos'; }
  return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p, $desc];
}

/** Período genérico para tabelas com created_at (alias informado). */
function ind_filtro_periodo(array $f, string $col): array {
  $w = []; $p = [];
  if (!empty($f['de']))  { $w[] = "$col >= ?"; $p[] = $f['de'] . ' 00:00:00'; }
  if (!empty($f['ate'])) { $w[] = "$col <= ?"; $p[] = $f['ate'] . ' 23:59:59'; }
  return [$w, $p];
}

/* ============================================================
 * Tempo por etapa (calculado em PHP a partir do histórico; sem window functions)
 *   retorna ['por_status' => [status => ['media_dias','n']], 'por_curso' => [id => [...]]]
 * ============================================================ */
function ind_tempos_etapas(array $f = []): array {
  [$where, $p] = ind_filtro_cursos($f, 'c');
  $rows = ind_rows("
    SELECT h.id_curso, h.status_para, h.created_at, c.nome_curso, c.status_atual, c.created_at AS curso_em
    FROM tb_curso_status_history h JOIN tb_cursos c ON c.id_curso = h.id_curso
    $where ORDER BY h.id_curso, h.created_at, h.id_history", $p);
  $agora = time();
  $porStatus = []; $porCurso = []; $ant = null;
  foreach ($rows as $r) {
    if ($ant && $ant['id_curso'] === $r['id_curso']) {
      $seg = strtotime($r['created_at']) - strtotime($ant['created_at']);
      $porStatus[$ant['status_para']]['seg'][] = $seg;
      $porCurso[$r['id_curso']]['etapas'][$ant['status_para']] = ($porCurso[$r['id_curso']]['etapas'][$ant['status_para']] ?? 0) + $seg;
    }
    $porCurso[$r['id_curso']]['nome'] = $r['nome_curso'];
    $porCurso[$r['id_curso']]['status_atual'] = $r['status_atual'];
    $ant = $r;
    // último registro: tempo até agora (status atual), se não for final
    $porCurso[$r['id_curso']]['ultimo'] = $r;
  }
  $finais = ind_status_finais();
  foreach ($porCurso as $id => &$c) {
    $u = $c['ultimo'];
    if (!in_array($u['status_para'], $finais, true)) {
      $seg = $agora - strtotime($u['created_at']);
      $porStatus[$u['status_para']]['seg'][] = $seg;
      $c['etapas'][$u['status_para']] = ($c['etapas'][$u['status_para']] ?? 0) + $seg;
    }
    unset($c['ultimo']);
  }
  unset($c);
  $out = [];
  foreach ($porStatus as $s => $d) {
    $n = count($d['seg']);
    $out[$s] = ['media_dias' => $n ? array_sum($d['seg']) / $n / 86400 : 0, 'max_dias' => $n ? max($d['seg']) / 86400 : 0, 'n' => $n];
  }
  return ['por_status' => $out, 'por_curso' => $porCurso];
}

/* ============================================================
 * CATÁLOGO DE INDICADORES (KPIs) — por grupo
 *   cada item: chave => [titulo, valor(callable), relatorio, descricao, cor]
 * ============================================================ */
function ind_todos(): array {
  $hoje = date('Y-m-d');
  $final = ind_sql_final('c');
  $g = [];

  // ---------- 1. Produção de cursos ----------
  $total = ind_n("SELECT COUNT(*) FROM tb_cursos c");
  $concl = ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE $final");
  $g['producao'] = ['titulo' => 'Produção de cursos', 'icone' => '📚', 'itens' => [
    'total_cursos'   => ['Cursos cadastrados', $total, 'cursos', 'Todos os cursos registrados no sistema.'],
    'em_andamento'   => ['Em andamento', $total - $concl, 'cursos&situacao=andamento', 'Cursos que ainda não chegaram a um status final.'],
    'concluidos'     => ['Publicados / concluídos', $concl, 'cursos&situacao=concluidos', 'Cursos em status final.', '#198754'],
    'taxa_conclusao' => ['Taxa de conclusão', ind_pct($concl, $total), 'cursos&situacao=concluidos', 'Concluídos ÷ cadastrados.'],
    'novos_30d'      => ['Propostos nos últimos 30 dias', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE c.created_at >= NOW() - INTERVAL 30 DAY"), 'cursos&de=' . date('Y-m-d', strtotime('-30 days')), 'Novas propostas no período.'],
    'publicados_30d' => ['Publicados nos últimos 30 dias', ind_n("SELECT COUNT(DISTINCT h.id_curso) FROM tb_curso_status_history h JOIN tb_status s ON s.nome=h.status_para AND s.is_final=1 WHERE h.created_at >= NOW() - INTERVAL 30 DAY"), 'producao_mensal', 'Cursos que atingiram status final no período.', '#198754'],
    'mov_30d'        => ['Movimentações (30 dias)', ind_n("SELECT COUNT(*) FROM tb_curso_status_history WHERE created_at >= NOW() - INTERVAL 30 DAY"), 'movimentacoes', 'Mudanças de status registradas.'],
    'media_mensal'   => ['Média de propostas / mês (12 m)', number_format(ind_n("SELECT COUNT(*) FROM tb_cursos WHERE created_at >= NOW() - INTERVAL 12 MONTH") / 12, 1, ',', '.'), 'producao_mensal', 'Propostas dos últimos 12 meses ÷ 12.'],
    'horas_total'    => ['Carga horária total (h)', ind_n("SELECT COALESCE(SUM(carga_horaria),0) FROM tb_cursos"), 'distribuicao&dim=carga_horaria', 'Soma das cargas horárias dos cursos.'],
    'horas_publ'     => ['Carga horária publicada (h)', ind_n("SELECT COALESCE(SUM(c.carga_horaria),0) FROM tb_cursos c WHERE $final"), 'distribuicao&dim=carga_horaria&situacao=concluidos', 'Soma das cargas horárias dos cursos concluídos.', '#198754'],
  ]];

  // ---------- 2. Fluxo (Kanban) ----------
  $cols = kanban_columns();
  $porStatus = [];
  foreach (ind_rows("SELECT status_atual, COUNT(*) n FROM tb_cursos GROUP BY status_atual") as $r) $porStatus[$r['status_atual']] = (int)$r['n'];
  $wipExc = 0; $porColuna = [];
  foreach ($cols as $c) {
    $n = 0; foreach ($c['statuses'] as $s) $n += $porStatus[$s['nome']] ?? 0;
    $porColuna[] = ['nome' => $c['nome'], 'n' => $n, 'cor' => $c['cor'], 'wip' => (int)($c['wip_limit'] ?? 0)];
    if (!empty($c['wip_limit']) && $n > (int)$c['wip_limit']) $wipExc++;
  }
  $tempos = ind_tempos_etapas();
  $gargalo = null; $gargaloDias = 0;
  foreach ($tempos['por_status'] as $s => $d) { if (!in_array($s, ind_status_finais(), true) && $d['media_dias'] > $gargaloDias) { $gargalo = $s; $gargaloDias = $d['media_dias']; } }
  $lead = ind_val("SELECT AVG(DATEDIFF(h.created_at, c.created_at)) FROM tb_cursos c JOIN tb_curso_status_history h ON h.id_curso=c.id_curso JOIN tb_status s ON s.nome=h.status_para AND s.is_final=1");
  $g['fluxo'] = ['titulo' => 'Fluxo Kanban e tempos', 'icone' => '🔁', 'itens' => [
    'por_etapa'      => ['Cursos por etapa', count(array_filter($porColuna, fn($c) => $c['n'] > 0)) . ' etapas ativas', 'por_etapa', 'Distribuição dos cursos pelas colunas do Kanban.'],
    'em_revisao'     => ['Aguardando a TI (análise/revisão)', ind_n("SELECT COUNT(*) FROM tb_cursos WHERE status_atual IN ('Pronto para Análise','Em Revisão','Pronto para Nova Análise')"), 'cursos&status=Em Revisão', 'Cursos nos status de análise/revisão.', '#fd7e14'],
    'em_insercao'    => ['Com a MB (inserção)', ind_n("SELECT COUNT(*) FROM tb_cursos WHERE status_atual IN ('Enviado para Inserção','Em Inserção','Inserido')"), 'cursos&status=Em Inserção', 'Cursos nos status de inserção.'],
    'lead_time'      => ['Lead time médio (proposta → publicação)', ind_dias($lead === null ? null : (float)$lead), 'lead_time', 'Dias entre a proposta e o status final.', '#0dcaf0'],
    'gargalo'        => ['Etapa mais demorada (média)', $gargalo ? $gargalo . ' · ' . ind_dias($gargaloDias) : '—', 'tempo_etapas', 'Status com maior permanência média.', '#fd7e14'],
    'wip_excedido'   => ['Colunas acima do limite WIP', $wipExc, 'por_etapa', 'Colunas com mais cursos que o limite configurado.', $wipExc ? '#dc3545' : '#198754'],
    'parados_30d'    => ['Parados há mais de 30 dias', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE NOT $final AND c.updated_at < NOW() - INTERVAL 30 DAY"), 'parados', 'Em andamento, sem nenhuma alteração há 30 dias.', '#dc3545'],
  ]];
  $g['fluxo']['por_coluna'] = $porColuna;

  // ---------- 3. Prazos ----------
  $g['prazos'] = ['titulo' => 'Prazos', 'icone' => '⏰', 'itens' => [
    'atrasados'      => ['Entrega final atrasada', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE NOT $final AND c.data_prevista_entrega_final < ?", [$hoje]), 'prazos&janela=0', 'Data prevista de entrega vencida.', '#dc3545'],
    'vencem_7d'      => ['Vencem em 7 dias', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE NOT $final AND c.data_prevista_entrega_final BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)", [$hoje, $hoje]), 'prazos&janela=7', 'Entrega prevista nos próximos 7 dias.', '#fd7e14'],
    'vencem_30d'     => ['Vencem em 30 dias', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE NOT $final AND c.data_prevista_entrega_final BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)", [$hoje, $hoje]), 'prazos&janela=30', 'Entrega prevista nos próximos 30 dias.'],
    'sem_prazo'      => ['Em andamento sem data de entrega', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE NOT $final AND c.data_prevista_entrega_final IS NULL"), 'cursos&situacao=andamento', 'Cursos sem previsão de entrega informada.'],
    'publ_agendada'  => ['Publicações agendadas', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE NOT $final AND c.publication_due_date >= ?", [$hoje]), 'publicacao', 'Data de publicação definida pela TI, ainda futura.'],
    'publ_atrasada'  => ['Publicações em atraso', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE NOT $final AND c.publication_due_date < ?", [$hoje]), 'publicacao&janela=0', 'Data de publicação vencida sem status final.', '#dc3545'],
    'pontualidade'   => ['Concluídos dentro do prazo', (function () use ($final) {
        $t = ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE $final AND c.data_prevista_entrega_final IS NOT NULL");
        $ok = ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE $final AND c.data_prevista_entrega_final IS NOT NULL AND c.updated_at <= c.data_prevista_entrega_final + INTERVAL 1 DAY");
        return ind_pct($ok, $t); })(), 'lead_time', 'Concluídos até a data prevista ÷ concluídos com data.', '#198754'],
  ]];

  // ---------- 4. Pessoas ----------
  $g['pessoas'] = ['titulo' => 'Formadores e equipe', 'icone' => '👥', 'itens' => [
    'formadores_ativos' => ['Formadores com curso', ind_n("SELECT COUNT(DISTINCT id_professor) FROM tb_cursos"), 'por_formador', 'Responsáveis por pelo menos um curso.'],
    'formadores_sem'    => ['Formadores sem curso', ind_n("SELECT COUNT(*) FROM tb_users u JOIN tb_perfis p ON p.codigo=u.role WHERE u.ativo=1 AND p.propoe_cursos=1 AND p.admin_total=0 AND p.revisa_cursos=0 AND NOT EXISTS (SELECT 1 FROM tb_cursos c WHERE c.id_professor=u.id_user)" . (ind_tabela('tb_curso_professores') ? " AND NOT EXISTS (SELECT 1 FROM tb_curso_professores cp WHERE cp.id_usuario=u.id_user)" : '')), 'usuarios&filtro=sem_curso', 'Formadores ativos que ainda não propuseram nem participam de cursos.', '#fd7e14'],
    'media_por_formador'=> ['Média de cursos por formador', (function () { $f = ind_n("SELECT COUNT(DISTINCT id_professor) FROM tb_cursos"); $t = ind_n("SELECT COUNT(*) FROM tb_cursos"); return $f ? number_format($t / $f, 1, ',', '.') : '—'; })(), 'por_formador', 'Cursos ÷ formadores com curso.'],
    'coautorias'        => ['Cursos com coautores', ind_tabela('tb_curso_professores') ? ind_n("SELECT COUNT(DISTINCT id_curso) FROM tb_curso_professores WHERE tipo='COAUTOR'") : 0, 'coautorias', 'Cursos com mais de um professor.'],
    'usuarios_ativos'   => ['Usuários ativos', ind_n("SELECT COUNT(*) FROM tb_users WHERE ativo=1"), 'usuarios', 'Contas ativas por perfil.'],
    'logins_30d'        => ['Usuários que acessaram (30 dias)', ind_n("SELECT COUNT(DISTINCT id_user) FROM tb_audit_log WHERE acao='login_ok' AND created_at >= NOW() - INTERVAL 30 DAY"), 'usuarios&filtro=acesso_30d', 'Logins distintos no período.'],
    'escolas'           => ['Unidades escolares com curso', ind_n("SELECT COUNT(DISTINCT unidade_escolar) FROM tb_cursos WHERE unidade_escolar<>''"), 'distribuicao&dim=unidade_escolar', 'Escolas informadas nos cursos.'],
    'niveis'            => ['Níveis de ensino atendidos', ind_n("SELECT COUNT(DISTINCT nivel_ensino) FROM tb_cursos WHERE nivel_ensino<>''"), 'distribuicao&dim=nivel_ensino', 'Níveis informados nos cursos.'],
  ]];

  // ---------- 5. Qualidade (apontamentos) ----------
  $apT = ind_n("SELECT COUNT(*) FROM tb_curso_apontamentos");
  $apAb = ind_n("SELECT COUNT(*) FROM tb_curso_apontamentos WHERE resolvido=0");
  $resol = ind_val("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at))/24 FROM tb_curso_apontamentos WHERE resolvido=1");
  $manC = ind_tabela('tb_apontamento_manifestacoes') ? ind_n("SELECT COUNT(*) FROM tb_apontamento_manifestacoes WHERE tipo='CONCORDO'") : 0;
  $manO = ind_tabela('tb_apontamento_manifestacoes') ? ind_n("SELECT COUNT(*) FROM tb_apontamento_manifestacoes WHERE tipo='OBJECAO'") : 0;
  $g['qualidade'] = ['titulo' => 'Qualidade (apontamentos da revisão)', 'icone' => '🧐', 'itens' => [
    'apont_total'     => ['Apontamentos registrados', $apT, 'apontamentos', 'Todos os apontamentos da TI/Qualidade.'],
    'apont_abertos'   => ['Apontamentos em aberto', $apAb, 'apontamentos&situacao=abertos', 'Ainda não concluídos.', $apAb ? '#dc3545' : '#198754'],
    'apont_resolvidos'=> ['Taxa de resolução', ind_pct($apT - $apAb, $apT), 'apontamentos_resumo', 'Concluídos ÷ registrados.', '#198754'],
    'apont_tempo'     => ['Tempo médio de resolução', ind_dias($resol === null ? null : (float)$resol), 'apontamentos_resumo', 'Dias entre o registro e a conclusão.'],
    'apont_por_curso' => ['Média de apontamentos por curso revisado', (function () use ($apT) { $c = ind_n("SELECT COUNT(DISTINCT id_curso) FROM tb_curso_apontamentos"); return $c ? number_format($apT / $c, 1, ',', '.') : '—'; })(), 'apontamentos_resumo', 'Apontamentos ÷ cursos com apontamento.'],
    'cursos_com_pend' => ['Cursos com pendências', ind_n("SELECT COUNT(DISTINCT id_curso) FROM tb_curso_apontamentos WHERE resolvido=0"), 'apontamentos&situacao=abertos', 'Cursos com pelo menos um apontamento aberto.', '#fd7e14'],
    'objecoes'        => ['Objeções dos formadores', $manO . ($manC + $manO ? ' (' . ind_pct($manO, $manC + $manO) . ')' : ''), 'manifestacoes', 'Manifestações "não concordo" ÷ total de manifestações.'],
    'recusas'         => ['Recusas na revisão', ind_n("SELECT COUNT(*) FROM tb_curso_status_history WHERE status_para LIKE 'Recusado%'"), 'movimentacoes&status=Recusado - Ajustes Necessários', 'Cursos devolvidos com ajustes (histórico).', '#fd7e14'],
  ]];

  // ---------- 6. Materiais (entregas) ----------
  $entT = ind_n("SELECT COUNT(*) FROM tb_curso_files");
  $entA = ind_n("SELECT COUNT(*) FROM tb_curso_files WHERE aprovado=1");
  $g['materiais'] = ['titulo' => 'Materiais entregues', 'icone' => '📁', 'itens' => [
    'entregas_total'  => ['Arquivos entregues', $entT, 'entregas', 'Materiais enviados pelos formadores.'],
    'entregas_aprov'  => ['Aprovados pela TI', $entA . ' (' . ind_pct($entA, $entT) . ')', 'entregas&situacao=aprovados', 'Entregas aprovadas ÷ entregues.', '#198754'],
    'entregas_pend'   => ['Aguardando aprovação', $entT - $entA, 'entregas&situacao=pendentes', 'Entregas ainda não aprovadas.', '#fd7e14'],
    'entregas_30d'    => ['Entregues nos últimos 30 dias', ind_n("SELECT COUNT(*) FROM tb_curso_files WHERE created_at >= NOW() - INTERVAL 30 DAY"), 'entregas&de=' . date('Y-m-d', strtotime('-30 days')), 'Arquivos enviados no período.'],
    'volume'          => ['Volume armazenado', ind_fmt_bytes(ind_n("SELECT COALESCE(SUM(file_size),0) FROM tb_curso_files")), 'entregas', 'Soma do tamanho dos arquivos.'],
    'dispensas'       => ['Itens "sem material"', ind_n("SELECT COUNT(*) FROM tb_curso_dispensas"), 'dispensas', 'Declarações de que o item não possui material.'],
    'sem_entrega'     => ['Cursos em andamento sem entrega', ind_n("SELECT COUNT(*) FROM tb_cursos c WHERE NOT $final AND NOT EXISTS (SELECT 1 FROM tb_curso_files f WHERE f.id_curso=c.id_curso)"), 'cursos&situacao=andamento', 'Cursos sem nenhum arquivo enviado.', '#fd7e14'],
    'links'           => ['Links externos cadastrados', ind_n("SELECT COUNT(*) FROM tb_curso_links"), 'links', 'Links (Drive/vídeos/outros) nos cursos.'],
  ]];

  // ---------- 7. Vídeos ----------
  $vT = ind_n("SELECT COUNT(*) FROM tb_videos"); $vV = ind_n("SELECT COUNT(*) FROM tb_video_versoes");
  $g['videos'] = ['titulo' => 'Vídeos (MB Estúdios)', 'icone' => '🎬', 'itens' => [
    'videos_total'    => ['Vídeos cadastrados', $vT, 'videos', 'Vídeos por curso/módulo.'],
    'versoes_total'   => ['Versões enviadas', $vV, 'videos', 'Todas as versões (arquivo ou Drive).'],
    'versoes_media'   => ['Média de versões por vídeo', $vT ? number_format($vV / $vT, 1, ',', '.') : '—', 'videos', 'Retrabalho: versões ÷ vídeos.'],
    'videos_drive'    => ['Versões por link do Drive', ind_n("SELECT COUNT(*) FROM tb_video_versoes WHERE origem='DRIVE'"), 'videos&tipo=DRIVE', 'Entregues por link (V12).'],
    'videos_aprov'    => ['Vídeos aprovados', ind_n("SELECT COUNT(*) FROM tb_videos WHERE status='APROVADO'"), 'videos&situacao=APROVADO', 'Vídeos com status aprovado.', '#198754'],
    'marc_total'      => ['Marcações de ajuste', ind_n("SELECT COUNT(*) FROM tb_video_marcacoes"), 'marcacoes', 'Pontos marcados pelo formador/TI.'],
    'marc_abertas'    => ['Marcações pendentes', ind_n("SELECT COUNT(*) FROM tb_video_marcacoes WHERE status IN ('PENDENTE','EM_CORRECAO')"), 'marcacoes&situacao=abertas', 'Ainda não corrigidas/aprovadas.', '#fd7e14'],
    'volume_videos'   => ['Volume de vídeos armazenado', ind_fmt_bytes(ind_n("SELECT COALESCE(SUM(file_size),0) FROM tb_video_versoes WHERE origem<>'DRIVE'")), 'videos&tipo=UPLOAD', 'Somente arquivos enviados (Drive não ocupa espaço).'],
  ]];

  // ---------- 8. Checklists ----------
  if (ind_tabela('tb_curso_checklist_respostas')) {
    $itensProf = ind_n("SELECT COUNT(*) FROM tb_checklist_itens i JOIN tb_checklists ck ON ck.id_checklist=i.id_checklist WHERE i.ativo=1");
    $g['checklists'] = ['titulo' => 'Checklists', 'icone' => '✅', 'itens' => [
      'chk_itens'     => ['Itens ativos de checklist', $itensProf, 'checklists', 'Itens configurados (todas as listas).'],
      'chk_marcados'  => ['Itens marcados (todos os cursos)', ind_n("SELECT COUNT(*) FROM tb_curso_checklist_respostas WHERE marcado=1"), 'checklists', 'Respostas positivas registradas.'],
      'chk_completos' => ['Cursos com checklist completo', (function () use ($itensProf) {
          if (!$itensProf) return '—';
          return ind_n("SELECT COUNT(*) FROM (SELECT id_curso, COUNT(*) n FROM tb_curso_checklist_respostas r JOIN tb_checklist_itens i ON i.id_item=r.id_item AND i.ativo=1 WHERE r.marcado=1 GROUP BY id_curso HAVING n >= ?) x", [$itensProf]); })(), 'checklists', 'Cursos com todos os itens marcados.', '#198754'],
    ]];
  }

  // ---------- 9. Sistema ----------
  $g['sistema'] = ['titulo' => 'Comunicação e sistema', 'icone' => '⚙️', 'itens' => [
    'notif_30d'      => ['E-mails enviados (30 dias)', ind_n("SELECT COUNT(*) FROM tb_notificacoes WHERE status='ENVIADO' AND created_at >= NOW() - INTERVAL 30 DAY"), 'notificacoes', 'Notificações entregues no período.'],
    'notif_erro'     => ['E-mails com erro', ind_n("SELECT COUNT(*) FROM tb_notificacoes WHERE status='ERRO'"), 'notificacoes&situacao=ERRO', 'Falhas de envio na fila.', ind_n("SELECT COUNT(*) FROM tb_notificacoes WHERE status='ERRO'") ? '#dc3545' : '#198754'],
    'notif_pend'     => ['E-mails pendentes', ind_n("SELECT COUNT(*) FROM tb_notificacoes WHERE status='PENDENTE'"), 'notificacoes&situacao=PENDENTE', 'Aguardando o cron.'],
    'acoes_30d'      => ['Ações registradas (30 dias)', ind_n("SELECT COUNT(*) FROM tb_audit_log WHERE created_at >= NOW() - INTERVAL 30 DAY"), 'auditoria_resumo', 'Registros de auditoria no período.'],
    'downloads_30d'  => ['Downloads de materiais (30 dias)', ind_n("SELECT COUNT(*) FROM tb_audit_log WHERE acao IN ('arquivo_baixado','pacote_baixado','download_todos') AND created_at >= NOW() - INTERVAL 30 DAY"), 'auditoria_resumo&tipo=arquivo_baixado', 'Arquivos/pacotes baixados.'],
    'modelos'        => ['Modelos vigentes na biblioteca', ind_n("SELECT COUNT(*) FROM tb_modelos WHERE ativo=1 AND vigente=1"), 'modelos', 'Templates oficiais em vigor.'],
    'modelos_down'   => ['Downloads de modelos (30 dias)', ind_n("SELECT COUNT(*) FROM tb_audit_log WHERE acao='modelo_baixado' AND created_at >= NOW() - INTERVAL 30 DAY"), 'auditoria_resumo&tipo=modelo_baixado', 'Uso da biblioteca de modelos.'],
  ]];

  return $g;
}

/** Séries para os gráficos da página de indicadores. */
function ind_graficos(): array {
  $meses = []; $prop = []; $publ = [];
  for ($i = 11; $i >= 0; $i--) { $m = date('Y-m', strtotime("-$i months")); $meses[$m] = date('m/y', strtotime($m . '-01')); $prop[$m] = 0; $publ[$m] = 0; }
  foreach (ind_rows("SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) n FROM tb_cursos WHERE created_at >= DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY m") as $r) if (isset($prop[$r['m']])) $prop[$r['m']] = (int)$r['n'];
  foreach (ind_rows("SELECT DATE_FORMAT(h.created_at,'%Y-%m') m, COUNT(DISTINCT h.id_curso) n FROM tb_curso_status_history h JOIN tb_status s ON s.nome=h.status_para AND s.is_final=1 WHERE h.created_at >= DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY m") as $r) if (isset($publ[$r['m']])) $publ[$r['m']] = (int)$r['n'];
  $apStatus = []; foreach (ind_rows("SELECT COALESCE(status,'PENDENTE_ANALISE') s, COUNT(*) n FROM tb_curso_apontamentos GROUP BY s") as $r) $apStatus[$r['s']] = (int)$r['n'];
  $apTipo = []; foreach (ind_rows("SELECT tipo, COUNT(*) n FROM tb_curso_apontamentos GROUP BY tipo") as $r) $apTipo[$r['tipo']] = (int)$r['n'];
  $entCat = []; foreach (ind_rows("SELECT categoria, COUNT(*) n FROM tb_curso_files GROUP BY categoria ORDER BY n DESC LIMIT 12") as $r) $entCat[$r['categoria']] = (int)$r['n'];
  $prio = []; foreach (ind_rows("SELECT prioridade, COUNT(*) n FROM tb_cursos GROUP BY prioridade") as $r) $prio[$r['prioridade']] = (int)$r['n'];
  $carga = []; foreach (ind_rows("SELECT carga_horaria, COUNT(*) n FROM tb_cursos GROUP BY carga_horaria ORDER BY carga_horaria") as $r) $carga[$r['carga_horaria'] . ' h'] = (int)$r['n'];
  $marc = []; foreach (ind_rows("SELECT categoria, COUNT(*) n FROM tb_video_marcacoes GROUP BY categoria") as $r) $marc[$r['categoria']] = (int)$r['n'];
  return [
    'mensal'  => ['labels' => array_values($meses), 'propostos' => array_values($prop), 'publicados' => array_values($publ)],
    'apont_status' => $apStatus, 'apont_tipo' => $apTipo, 'entregas_cat' => $entCat, 'prioridade' => $prio, 'carga' => $carga, 'marcacoes' => $marc,
  ];
}

/* ============================================================
 * CATÁLOGO DE RELATÓRIOS
 *   chave => [titulo, descricao, grupo, filtros (lista), gerador(callable $f) => [colunas, linhas, resumo]]
 *   Filtros possíveis: periodo, status, formador, escola, nivel, prioridade, carga, situacao, janela, tipo, dim
 * ============================================================ */
function rel_catalogo(): array {
  $FC = ['periodo', 'status', 'formador', 'escola', 'nivel', 'prioridade', 'carga', 'situacao'];
  return [
    'cursos'             => ['Cursos (lista geral)', 'Todos os cursos com status, formador, escola, nível, prazos e carga horária.', 'producao', $FC],
    'por_etapa'          => ['Cursos por etapa do Kanban', 'Quantidade por coluna e por status, com limite WIP e cursos de cada etapa.', 'fluxo', []],
    'producao_mensal'    => ['Produção mensal', 'Cursos propostos e publicados por mês (últimos 24 meses).', 'producao', []],
    'movimentacoes'      => ['Movimentações de status', 'Histórico de mudanças de status: quem moveu, de onde, para onde e quando.', 'fluxo', ['periodo', 'status', 'formador']],
    'tempo_etapas'       => ['Tempo médio por etapa', 'Permanência média e máxima (dias) em cada status.', 'fluxo', ['periodo', 'formador']],
    'lead_time'          => ['Lead time por curso', 'Dias da proposta até a publicação, com pontualidade em relação à data prevista.', 'fluxo', ['periodo', 'formador', 'escola']],
    'parados'            => ['Cursos parados', 'Em andamento, sem alteração há mais de N dias.', 'fluxo', ['janela', 'formador']],
    'prazos'             => ['Prazos de entrega', 'Cursos atrasados ou com entrega prevista dentro da janela informada.', 'prazos', ['janela', 'formador', 'escola']],
    'publicacao'         => ['Agenda de publicação', 'Datas de publicação definidas pela TI (futuras e em atraso).', 'prazos', ['janela', 'formador']],
    'por_formador'       => ['Produção por formador(a)', 'Cursos, concluídos, em andamento, atrasados e apontamentos abertos por responsável.', 'pessoas', ['periodo']],
    'coautorias'         => ['Coautorias', 'Cursos com mais de um professor e a participação de cada um.', 'pessoas', ['periodo', 'formador']],
    'usuarios'           => ['Usuários por perfil', 'Contas, perfis, último acesso e cursos de cada usuário.', 'pessoas', ['filtro_usuarios']],
    'distribuicao'       => ['Distribuição de cursos', 'Cursos por escola, nível, carga horária, prioridade ou público-alvo.', 'pessoas', ['dim', 'situacao', 'periodo']],
    'apontamentos'       => ['Apontamentos (lista)', 'Apontamentos da revisão com tipo, status, curso, autor e datas.', 'qualidade', ['periodo', 'tipo_apont', 'situacao_apont', 'formador']],
    'apontamentos_resumo'=> ['Apontamentos (resumo)', 'Por status, por tipo e por revisor, com tempo médio de resolução.', 'qualidade', ['periodo']],
    'manifestacoes'      => ['Manifestações dos formadores', 'Concordâncias e objeções registradas nos apontamentos.', 'qualidade', ['periodo', 'formador']],
    'entregas'           => ['Entregas de materiais', 'Arquivos enviados por curso, categoria e módulo, com aprovação.', 'materiais', ['periodo', 'situacao_entrega', 'formador']],
    'dispensas'          => ['Itens sem material', 'Declarações "não possuo este material" por curso.', 'materiais', ['periodo', 'formador']],
    'links'              => ['Links externos', 'Links cadastrados nos cursos (Drive, vídeos, outros).', 'materiais', ['periodo']],
    'videos'             => ['Vídeos e versões', 'Versões enviadas por vídeo, origem (arquivo/Drive), tamanho e status.', 'videos', ['periodo', 'origem', 'situacao_video']],
    'marcacoes'          => ['Marcações nos vídeos', 'Pontos de ajuste marcados, por categoria e status.', 'videos', ['periodo', 'situacao_marc']],
    'checklists'         => ['Checklists por curso', 'Percentual de itens marcados por curso e lista.', 'checklists', ['formador']],
    'notificacoes'       => ['E-mails enviados', 'Fila de notificações por status, destinatário e assunto.', 'sistema', ['periodo', 'situacao_notif']],
    'auditoria_resumo'   => ['Auditoria (resumo)', 'Ações registradas por tipo e por usuário no período.', 'sistema', ['periodo', 'tipo_acao']],
    'modelos'            => ['Biblioteca de modelos', 'Modelos cadastrados, vigência e downloads.', 'sistema', []],
  ];
}

/** Gera o relatório: retorna ['titulo','colunas'=>[], 'linhas'=>[[...]], 'resumo'=>[...], 'filtros_desc'=>[]] */
function rel_gerar(string $r, array $f): ?array {
  $cat = rel_catalogo();
  if (!isset($cat[$r])) return null;
  [$titulo, $descricao] = $cat[$r];
  $final = ind_sql_final('c');
  $hoje = date('Y-m-d');
  $out = ['chave' => $r, 'titulo' => $titulo, 'descricao' => $descricao, 'colunas' => [], 'linhas' => [], 'resumo' => [], 'filtros_desc' => [], 'links' => []];
  [$where, $p, $desc] = ind_filtro_cursos($f, 'c');
  $out['filtros_desc'] = $desc;
  $janela = isset($f['janela']) && $f['janela'] !== '' ? max(0, (int)$f['janela']) : null;

  switch ($r) {
    case 'cursos':
      $out['colunas'] = ['#', 'Curso', 'Formador(a)', 'Status', 'Escola', 'Nível', 'Carga', 'Prioridade', 'Proposto em', 'Entrega prevista', 'Publicação'];
      foreach (ind_rows("SELECT c.*, u.nome prof FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor $where ORDER BY c.created_at DESC", $p) as $c) {
        $out['linhas'][] = [$c['id_curso'], $c['nome_curso'], $c['prof'], $c['status_atual'], $c['unidade_escolar'], $c['nivel_ensino'], $c['carga_horaria'] . ' h', $c['prioridade'], date('d/m/Y', strtotime($c['created_at'])), $c['data_prevista_entrega_final'] ? date('d/m/Y', strtotime($c['data_prevista_entrega_final'])) : '', $c['publication_due_date'] ? date('d/m/Y', strtotime($c['publication_due_date'])) : ''];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$c['id_curso'];
      }
      $out['resumo'] = ['Cursos' => count($out['linhas']), 'Carga horária total' => array_sum(array_map(fn($l) => (int)$l[6], $out['linhas'])) . ' h'];
      break;

    case 'por_etapa':
      $out['colunas'] = ['Coluna', 'Status', 'Cursos', 'Limite WIP', 'Situação', 'Cursos (nomes)'];
      $porStatus = [];
      foreach (ind_rows("SELECT status_atual, GROUP_CONCAT(nome_curso ORDER BY nome_curso SEPARATOR ' • ') nomes, COUNT(*) n FROM tb_cursos GROUP BY status_atual") as $x) $porStatus[$x['status_atual']] = $x;
      foreach (kanban_columns() as $col) {
        $nCol = 0; $linhasCol = [];
        foreach ($col['statuses'] as $s) { $n = (int)($porStatus[$s['nome']]['n'] ?? 0); $nCol += $n; $linhasCol[] = [$col['nome'], $s['nome'], $n, '', '', $porStatus[$s['nome']]['nomes'] ?? '']; }
        $wip = (int)($col['wip_limit'] ?? 0);
        $out['linhas'][] = [$col['nome'], '(total da coluna)', $nCol, $wip ?: '—', $wip && $nCol > $wip ? 'ACIMA DO LIMITE' : 'ok', ''];
        foreach ($linhasCol as $l) $out['linhas'][] = $l;
      }
      $out['resumo'] = ['Cursos' => ind_n("SELECT COUNT(*) FROM tb_cursos")];
      break;

    case 'producao_mensal':
      $out['colunas'] = ['Mês', 'Propostos', 'Publicados', 'Movimentações', 'Apontamentos', 'Entregas'];
      $m = [];
      for ($i = 23; $i >= 0; $i--) $m[date('Y-m', strtotime("-$i months"))] = [0, 0, 0, 0, 0];
      $q = [["SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) n FROM tb_cursos GROUP BY m", 0],
            ["SELECT DATE_FORMAT(h.created_at,'%Y-%m') m, COUNT(DISTINCT h.id_curso) n FROM tb_curso_status_history h JOIN tb_status s ON s.nome=h.status_para AND s.is_final=1 GROUP BY m", 1],
            ["SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) n FROM tb_curso_status_history GROUP BY m", 2],
            ["SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) n FROM tb_curso_apontamentos GROUP BY m", 3],
            ["SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) n FROM tb_curso_files GROUP BY m", 4]];
      foreach ($q as [$sql, $idx]) foreach (ind_rows($sql) as $x) if (isset($m[$x['m']])) $m[$x['m']][$idx] = (int)$x['n'];
      foreach ($m as $k => $v) $out['linhas'][] = array_merge([date('m/Y', strtotime($k . '-01'))], $v);
      $out['resumo'] = ['Propostos (24 m)' => array_sum(array_column($m, 0)), 'Publicados (24 m)' => array_sum(array_column($m, 1))];
      break;

    case 'movimentacoes':
      $out['colunas'] = ['Data/hora', 'Curso', 'De', 'Para', 'Por', 'Observação'];
      [$wp, $pp] = ind_filtro_periodo($f, 'h.created_at');
      $w2 = $wp; $p2 = $pp;
      if (!empty($f['status'])) { $w2[] = "h.status_para = ?"; $p2[] = $f['status']; $out['filtros_desc'][] = 'para ' . $f['status']; }
      if (!empty($f['id_professor'])) { $w2[] = "c.id_professor = ?"; $p2[] = (int)$f['id_professor']; }
      $sw = $w2 ? 'WHERE ' . implode(' AND ', $w2) : '';
      foreach (ind_rows("SELECT h.*, c.nome_curso, u.nome quem FROM tb_curso_status_history h JOIN tb_cursos c ON c.id_curso=h.id_curso LEFT JOIN tb_users u ON u.id_user=h.id_user $sw ORDER BY h.created_at DESC LIMIT 2000", $p2) as $h) {
        $out['linhas'][] = [date('d/m/Y H:i', strtotime($h['created_at'])), $h['nome_curso'], $h['status_de'], $h['status_para'], $h['quem'], $h['observacao']];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$h['id_curso'];
      }
      $out['resumo'] = ['Movimentações' => count($out['linhas'])];
      break;

    case 'tempo_etapas':
      $out['colunas'] = ['Status', 'Coluna', 'Permanência média', 'Permanência máxima', 'Passagens'];
      $t = ind_tempos_etapas($f);
      $colDe = []; foreach (kanban_columns() as $col) foreach ($col['statuses'] as $s) $colDe[$s['nome']] = $col['nome'];
      $ordem = array_keys($colDe);
      uksort($t['por_status'], fn($a, $b) => (array_search($a, $ordem) ?: 999) <=> (array_search($b, $ordem) ?: 999));
      foreach ($t['por_status'] as $s => $d) $out['linhas'][] = [$s, $colDe[$s] ?? '', ind_dias($d['media_dias']), ind_dias($d['max_dias']), $d['n']];
      $out['resumo'] = ['Cursos considerados' => count($t['por_curso'])];
      break;

    case 'lead_time':
      $out['colunas'] = ['Curso', 'Formador(a)', 'Proposto em', 'Publicado em', 'Lead time', 'Entrega prevista', 'Pontualidade'];
      $w2 = $where ? $where . ' AND ' : 'WHERE '; $w2 .= "s.is_final=1";
      $soma = 0; $ok = 0; $comData = 0;
      foreach (ind_rows("SELECT c.id_curso, c.nome_curso, c.created_at, c.data_prevista_entrega_final, u.nome prof, MIN(h.created_at) publicado_em FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor JOIN tb_curso_status_history h ON h.id_curso=c.id_curso JOIN tb_status s ON s.nome=h.status_para $w2 GROUP BY c.id_curso ORDER BY publicado_em DESC", $p) as $c) {
        $dias = (strtotime($c['publicado_em']) - strtotime($c['created_at'])) / 86400; $soma += $dias;
        $pont = '—';
        if ($c['data_prevista_entrega_final']) { $comData++; $noPrazo = substr($c['publicado_em'], 0, 10) <= $c['data_prevista_entrega_final']; if ($noPrazo) $ok++; $pont = $noPrazo ? 'No prazo' : 'Atrasado'; }
        $out['linhas'][] = [$c['nome_curso'], $c['prof'], date('d/m/Y', strtotime($c['created_at'])), date('d/m/Y', strtotime($c['publicado_em'])), ind_dias($dias), $c['data_prevista_entrega_final'] ? date('d/m/Y', strtotime($c['data_prevista_entrega_final'])) : '', $pont];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$c['id_curso'];
      }
      $n = count($out['linhas']);
      $out['resumo'] = ['Cursos publicados' => $n, 'Lead time médio' => $n ? ind_dias($soma / $n) : '—', 'Pontualidade' => ind_pct($ok, $comData)];
      break;

    case 'parados':
      $d = $janela ?? 30; $out['titulo'] .= " (sem alteração há mais de {$d} dias)";
      $out['colunas'] = ['Curso', 'Formador(a)', 'Status', 'Última alteração', 'Dias parado', 'Entrega prevista'];
      $w2 = ($where ? $where . ' AND ' : 'WHERE ') . "NOT $final AND c.updated_at < NOW() - INTERVAL ? DAY"; $p2 = array_merge($p, [$d]);
      foreach (ind_rows("SELECT c.*, u.nome prof, DATEDIFF(NOW(), c.updated_at) dias FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor $w2 ORDER BY c.updated_at", $p2) as $c) {
        $out['linhas'][] = [$c['nome_curso'], $c['prof'], $c['status_atual'], date('d/m/Y', strtotime($c['updated_at'])), $c['dias'], $c['data_prevista_entrega_final'] ? date('d/m/Y', strtotime($c['data_prevista_entrega_final'])) : ''];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$c['id_curso'];
      }
      $out['resumo'] = ['Cursos parados' => count($out['linhas'])];
      break;

    case 'prazos':
      $d = $janela ?? 7; $out['titulo'] .= $d > 0 ? " (vencidos e próximos {$d} dias)" : ' (vencidos)';
      $out['colunas'] = ['Entrega prevista', 'Curso', 'Formador(a)', 'Status', 'Prioridade', 'Situação', 'Dias'];
      $w2 = ($where ? $where . ' AND ' : 'WHERE ') . "NOT $final AND c.data_prevista_entrega_final IS NOT NULL AND c.data_prevista_entrega_final <= DATE_ADD(?, INTERVAL ? DAY)"; $p2 = array_merge($p, [$hoje, $d]);
      $atr = 0;
      foreach (ind_rows("SELECT c.*, u.nome prof, DATEDIFF(c.data_prevista_entrega_final, ?) dias FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor $w2 ORDER BY c.data_prevista_entrega_final", array_merge([$hoje], $p2)) as $c) {
        $venc = $c['dias'] < 0; if ($venc) $atr++;
        $out['linhas'][] = [date('d/m/Y', strtotime($c['data_prevista_entrega_final'])), $c['nome_curso'], $c['prof'], $c['status_atual'], $c['prioridade'], $venc ? 'ATRASADO' : 'Vence em breve', $venc ? abs((int)$c['dias']) . ' de atraso' : 'faltam ' . (int)$c['dias']];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$c['id_curso'];
      }
      $out['resumo'] = ['Atrasados' => $atr, 'A vencer' => count($out['linhas']) - $atr];
      break;

    case 'publicacao':
      $out['colunas'] = ['Publicação prevista', 'Curso', 'Formador(a)', 'Status', 'Situação'];
      $w2 = ($where ? $where . ' AND ' : 'WHERE ') . "NOT $final AND c.publication_due_date IS NOT NULL";
      if ($janela === 0) { $w2 .= " AND c.publication_due_date < ?"; $p[] = $hoje; }
      $atr = 0;
      foreach (ind_rows("SELECT c.*, u.nome prof FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor $w2 ORDER BY c.publication_due_date", $p) as $c) {
        $venc = $c['publication_due_date'] < $hoje; if ($venc) $atr++;
        $out['linhas'][] = [date('d/m/Y', strtotime($c['publication_due_date'])), $c['nome_curso'], $c['prof'], $c['status_atual'], $venc ? 'EM ATRASO' : 'Agendada'];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$c['id_curso'];
      }
      $out['resumo'] = ['Agendadas' => count($out['linhas']) - $atr, 'Em atraso' => $atr];
      break;

    case 'por_formador':
      $out['colunas'] = ['Formador(a)', 'E-mail', 'Cursos', 'Concluídos', 'Em andamento', 'Atrasados', 'Apontamentos abertos', 'Coautorias', 'Carga horária'];
      $coSql = ind_tabela('tb_curso_professores') ? "(SELECT COUNT(*) FROM tb_curso_professores cp WHERE cp.id_usuario=u.id_user AND cp.tipo='COAUTOR')" : '0';
      foreach (ind_rows("SELECT u.id_user, u.nome, u.email,
          COUNT(c.id_curso) n, SUM(CASE WHEN $final THEN 1 ELSE 0 END) concl,
          SUM(CASE WHEN NOT $final AND c.data_prevista_entrega_final < ? THEN 1 ELSE 0 END) atr,
          (SELECT COUNT(*) FROM tb_curso_apontamentos a JOIN tb_cursos c2 ON c2.id_curso=a.id_curso WHERE c2.id_professor=u.id_user AND a.resolvido=0) ap,
          $coSql co, COALESCE(SUM(c.carga_horaria),0) horas
        FROM tb_users u JOIN tb_cursos c ON c.id_professor=u.id_user " . ($where ? str_replace('WHERE', 'AND', $where) : '') . "
        GROUP BY u.id_user ORDER BY n DESC, u.nome", array_merge([$hoje], $p)) as $x) {
        $out['linhas'][] = [$x['nome'], $x['email'], $x['n'], $x['concl'], $x['n'] - $x['concl'], $x['atr'], $x['ap'], $x['co'], $x['horas'] . ' h'];
        $out['links'][] = 'relatorios.php?r=cursos&id_professor=' . (int)$x['id_user'];
      }
      $out['resumo'] = ['Formadores' => count($out['linhas'])];
      break;

    case 'coautorias':
      $out['colunas'] = ['Curso', 'Responsável', 'Coautores', 'Qtde', 'Status'];
      if (!ind_tabela('tb_curso_professores')) break;
      $w2 = ($where ? $where . ' AND ' : 'WHERE ') . "EXISTS (SELECT 1 FROM tb_curso_professores cp WHERE cp.id_curso=c.id_curso AND cp.tipo='COAUTOR')";
      foreach (ind_rows("SELECT c.id_curso, c.nome_curso, c.status_atual, u.nome prof,
          (SELECT GROUP_CONCAT(u2.nome ORDER BY u2.nome SEPARATOR ', ') FROM tb_curso_professores cp JOIN tb_users u2 ON u2.id_user=cp.id_usuario WHERE cp.id_curso=c.id_curso AND cp.tipo='COAUTOR') cos,
          (SELECT COUNT(*) FROM tb_curso_professores cp WHERE cp.id_curso=c.id_curso AND cp.tipo='COAUTOR') n
        FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor $w2 ORDER BY n DESC, c.nome_curso", $p) as $c) {
        $out['linhas'][] = [$c['nome_curso'], $c['prof'], $c['cos'], $c['n'], $c['status_atual']];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$c['id_curso'];
      }
      $out['resumo'] = ['Cursos com coautores' => count($out['linhas'])];
      break;

    case 'usuarios':
      $out['colunas'] = ['Nome', 'E-mail', 'Perfil', 'Ativo', 'Cadastro', 'Último acesso', 'Cursos (resp.)', 'Coautorias', 'E-mails'];
      $filtro = $f['filtro'] ?? '';
      $coSql = ind_tabela('tb_curso_professores') ? "(SELECT COUNT(*) FROM tb_curso_professores cp WHERE cp.id_usuario=u.id_user AND cp.tipo='COAUTOR')" : '0';
      $rows = ind_rows("SELECT u.*, p.nome perfil,
          (SELECT MAX(created_at) FROM tb_audit_log a WHERE a.id_user=u.id_user AND a.acao='login_ok') ultimo,
          (SELECT COUNT(*) FROM tb_cursos c WHERE c.id_professor=u.id_user) n, $coSql co,
          (p.propoe_cursos=1 AND p.admin_total=0 AND p.revisa_cursos=0) formador
        FROM tb_users u LEFT JOIN tb_perfis p ON p.codigo=u.role ORDER BY p.nome, u.nome");
      foreach ($rows as $x) {
        if ($filtro === 'sem_curso' && !($x['formador'] && $x['ativo'] && (int)$x['n'] === 0 && (int)$x['co'] === 0)) continue;
        if ($filtro === 'acesso_30d' && (!$x['ultimo'] || strtotime($x['ultimo']) < strtotime('-30 days'))) continue;
        $out['linhas'][] = [$x['nome'], $x['email'], $x['perfil'] ?: $x['role'], $x['ativo'] ? 'sim' : 'não', date('d/m/Y', strtotime($x['created_at'])), $x['ultimo'] ? date('d/m/Y H:i', strtotime($x['ultimo'])) : 'nunca', $x['n'], $x['co'], $x['notif_pref']];
      }
      if ($filtro === 'sem_curso') $out['titulo'] .= ' — formadores sem curso';
      if ($filtro === 'acesso_30d') $out['titulo'] .= ' — com acesso nos últimos 30 dias';
      $porPerfil = []; foreach ($out['linhas'] as $l) $porPerfil[$l[2]] = ($porPerfil[$l[2]] ?? 0) + 1;
      $out['resumo'] = array_merge(['Usuários' => count($out['linhas'])], $porPerfil);
      break;

    case 'distribuicao':
      $dims = ['unidade_escolar' => 'Unidade escolar', 'nivel_ensino' => 'Nível de ensino', 'carga_horaria' => 'Carga horária', 'prioridade' => 'Prioridade', 'publico_alvo' => 'Público-alvo', 'status_atual' => 'Status'];
      $dim = isset($dims[$f['dim'] ?? '']) ? $f['dim'] : 'unidade_escolar';
      $out['titulo'] .= ' por ' . mb_strtolower($dims[$dim]);
      $out['colunas'] = [$dims[$dim], 'Cursos', '%', 'Concluídos', 'Em andamento', 'Carga horária'];
      $rows = ind_rows("SELECT COALESCE(NULLIF(c.$dim,''),'(não informado)') k, COUNT(*) n, SUM(CASE WHEN $final THEN 1 ELSE 0 END) concl, COALESCE(SUM(c.carga_horaria),0) h FROM tb_cursos c $where GROUP BY k ORDER BY n DESC", $p);
      $tot = array_sum(array_column($rows, 'n'));
      foreach ($rows as $x) {
        $out['linhas'][] = [$dim === 'carga_horaria' ? $x['k'] . ' h' : $x['k'], $x['n'], ind_pct((int)$x['n'], $tot), $x['concl'], $x['n'] - $x['concl'], $x['h'] . ' h'];
        $out['links'][] = 'relatorios.php?r=cursos&' . $dim . '=' . urlencode($x['k']);
      }
      $out['resumo'] = ['Cursos' => $tot, 'Categorias' => count($rows)];
      break;

    case 'apontamentos':
      $out['colunas'] = ['Data', 'Curso', 'Tipo', 'Status', 'Apontamento', 'Registrado por', 'Arquivo', 'Atualizado'];
      [$wp, $pp] = ind_filtro_periodo($f, 'a.created_at');
      if (!empty($f['tipo'])) { $wp[] = "a.tipo = ?"; $pp[] = $f['tipo']; $out['filtros_desc'][] = 'tipo ' . $f['tipo']; }
      if (($f['situacao'] ?? '') === 'abertos') { $wp[] = "a.resolvido = 0"; $out['filtros_desc'][] = 'em aberto'; }
      if (($f['situacao'] ?? '') === 'concluidos') { $wp[] = "a.resolvido = 1"; $out['filtros_desc'][] = 'concluídos'; }
      if (!empty($f['status_apont'])) { $wp[] = "a.status = ?"; $pp[] = $f['status_apont']; }
      if (!empty($f['id_professor'])) { $wp[] = "c.id_professor = ?"; $pp[] = (int)$f['id_professor']; }
      $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      foreach (ind_rows("SELECT a.*, c.nome_curso, u.nome quem, fl.original_name arq FROM tb_curso_apontamentos a JOIN tb_cursos c ON c.id_curso=a.id_curso LEFT JOIN tb_users u ON u.id_user=a.id_user LEFT JOIN tb_curso_files fl ON fl.id_file=a.id_file $sw ORDER BY a.created_at DESC LIMIT 2000", $pp) as $a) {
        $out['linhas'][] = [date('d/m/Y', strtotime($a['created_at'])), $a['nome_curso'], $a['tipo'], $a['status'] ?: ($a['resolvido'] ? 'CONCLUIDO' : 'PENDENTE_ANALISE'), mb_strimwidth(strip_tags($a['conteudo']), 0, 160, '…'), $a['quem'], $a['arq'], date('d/m/Y', strtotime($a['updated_at'] ?: $a['created_at']))];
        $out['links'][] = 'apontamentos.php?id=' . (int)$a['id_curso'];
      }
      $out['resumo'] = ['Apontamentos' => count($out['linhas'])];
      break;

    case 'apontamentos_resumo':
      $out['colunas'] = ['Agrupamento', 'Valor', 'Apontamentos', '%', 'Concluídos', 'Tempo médio de resolução'];
      [$wp, $pp] = ind_filtro_periodo($f, 'a.created_at'); $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      $tot = ind_n("SELECT COUNT(*) FROM tb_curso_apontamentos a $sw", $pp);
      foreach ([['Status', "COALESCE(a.status,'PENDENTE_ANALISE')"], ['Tipo', 'a.tipo'], ['Revisor(a)', "COALESCE(u.nome,'(sem usuário)')"], ['Curso', 'c.nome_curso']] as [$lab, $expr]) {
        foreach (ind_rows("SELECT $expr k, COUNT(*) n, SUM(a.resolvido) concl, AVG(CASE WHEN a.resolvido=1 THEN TIMESTAMPDIFF(HOUR,a.created_at,a.updated_at)/24 END) dias FROM tb_curso_apontamentos a JOIN tb_cursos c ON c.id_curso=a.id_curso LEFT JOIN tb_users u ON u.id_user=a.id_user $sw GROUP BY k ORDER BY n DESC LIMIT 30", $pp) as $x)
          $out['linhas'][] = [$lab, $x['k'], $x['n'], ind_pct((int)$x['n'], $tot), (int)$x['concl'], ind_dias($x['dias'] === null ? null : (float)$x['dias'])];
      }
      $out['resumo'] = ['Apontamentos' => $tot];
      break;

    case 'manifestacoes':
      $out['colunas'] = ['Data', 'Curso', 'Apontamento', 'Formador(a)', 'Manifestação', 'Justificativa'];
      if (!ind_tabela('tb_apontamento_manifestacoes')) break;
      [$wp, $pp] = ind_filtro_periodo($f, 'm.created_at');
      if (!empty($f['id_professor'])) { $wp[] = "c.id_professor = ?"; $pp[] = (int)$f['id_professor']; }
      $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      $c1 = 0; $c2 = 0;
      foreach (ind_rows("SELECT m.*, c.nome_curso, c.id_curso, u.nome quem, a.conteudo FROM tb_apontamento_manifestacoes m JOIN tb_curso_apontamentos a ON a.id_apontamento=m.id_apontamento JOIN tb_cursos c ON c.id_curso=a.id_curso LEFT JOIN tb_users u ON u.id_user=m.id_user $sw ORDER BY m.created_at DESC LIMIT 2000", $pp) as $m) {
        $m['tipo'] === 'CONCORDO' ? $c1++ : $c2++;
        $out['linhas'][] = [date('d/m/Y H:i', strtotime($m['created_at'])), $m['nome_curso'], mb_strimwidth(strip_tags($m['conteudo']), 0, 100, '…'), $m['quem'], $m['tipo'] === 'CONCORDO' ? 'Concordo' : 'Não concordo', $m['justificativa']];
        $out['links'][] = 'apontamentos.php?id=' . (int)$m['id_curso'];
      }
      $out['resumo'] = ['Concordo' => $c1, 'Não concordo' => $c2];
      break;

    case 'entregas':
      $out['colunas'] = ['Data', 'Curso', 'Formador(a)', 'Módulo', 'Categoria', 'Arquivo', 'Tamanho', 'Aprovado', 'Aprovado em'];
      [$wp, $pp] = ind_filtro_periodo($f, 'fl.created_at');
      if (($f['situacao'] ?? '') === 'aprovados') $wp[] = "fl.aprovado = 1";
      if (($f['situacao'] ?? '') === 'pendentes') $wp[] = "fl.aprovado = 0";
      if (!empty($f['id_professor'])) { $wp[] = "c.id_professor = ?"; $pp[] = (int)$f['id_professor']; }
      $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      $bytes = 0;
      foreach (ind_rows("SELECT fl.*, c.nome_curso, u.nome prof FROM tb_curso_files fl JOIN tb_cursos c ON c.id_curso=fl.id_curso JOIN tb_users u ON u.id_user=c.id_professor $sw ORDER BY fl.created_at DESC LIMIT 3000", $pp) as $x) {
        $bytes += (int)$x['file_size'];
        $out['linhas'][] = [date('d/m/Y', strtotime($x['created_at'])), $x['nome_curso'], $x['prof'], $x['modulo'] === null || $x['modulo'] === '' || (int)$x['modulo'] === 0 ? 'Geral' : 'Módulo ' . $x['modulo'], $x['categoria'], $x['original_name'], ind_fmt_bytes((int)$x['file_size']), $x['aprovado'] ? 'sim' : 'não', $x['aprovado_em'] ? date('d/m/Y', strtotime($x['aprovado_em'])) : ''];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$x['id_curso'];
      }
      $out['resumo'] = ['Arquivos' => count($out['linhas']), 'Volume' => ind_fmt_bytes($bytes)];
      break;

    case 'dispensas':
      $out['colunas'] = ['Data', 'Curso', 'Módulo', 'Categoria', 'Declarado por'];
      [$wp, $pp] = ind_filtro_periodo($f, 'd.created_at');
      if (!empty($f['id_professor'])) { $wp[] = "c.id_professor = ?"; $pp[] = (int)$f['id_professor']; }
      $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      foreach (ind_rows("SELECT d.*, c.nome_curso, u.nome quem FROM tb_curso_dispensas d JOIN tb_cursos c ON c.id_curso=d.id_curso LEFT JOIN tb_users u ON u.id_user=d.id_user $sw ORDER BY d.created_at DESC LIMIT 3000", $pp) as $x) {
        $out['linhas'][] = [date('d/m/Y', strtotime($x['created_at'])), $x['nome_curso'], (int)$x['modulo'] === 0 ? 'Geral' : 'Módulo ' . $x['modulo'], $x['categoria'], $x['quem']];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$x['id_curso'];
      }
      $out['resumo'] = ['Itens sem material' => count($out['linhas'])];
      break;

    case 'links':
      $out['colunas'] = ['Data', 'Curso', 'Tipo', 'Título', 'URL', 'Cadastrado por'];
      [$wp, $pp] = ind_filtro_periodo($f, 'l.created_at'); $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      foreach (ind_rows("SELECT l.*, c.nome_curso, u.nome quem FROM tb_curso_links l JOIN tb_cursos c ON c.id_curso=l.id_curso LEFT JOIN tb_users u ON u.id_user=l.id_user $sw ORDER BY l.created_at DESC LIMIT 3000", $pp) as $x) {
        $out['linhas'][] = [date('d/m/Y', strtotime($x['created_at'])), $x['nome_curso'], $x['tipo'], $x['titulo'], $x['url'], $x['quem']];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$x['id_curso'];
      }
      $out['resumo'] = ['Links' => count($out['linhas'])];
      break;

    case 'videos':
      $out['colunas'] = ['Data', 'Curso', 'Módulo', 'Vídeo', 'Versão', 'Origem', 'Arquivo / Drive', 'Tamanho', 'Enviado por', 'Status do vídeo', 'Marcações'];
      [$wp, $pp] = ind_filtro_periodo($f, 'vv.created_at');
      if (!empty($f['tipo'])) { $wp[] = "vv.origem = ?"; $pp[] = $f['tipo']; $out['filtros_desc'][] = 'origem ' . $f['tipo']; }
      if (!empty($f['situacao'])) { $wp[] = "v.status = ?"; $pp[] = $f['situacao']; $out['filtros_desc'][] = 'status ' . $f['situacao']; }
      $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      foreach (ind_rows("SELECT vv.*, v.titulo, v.modulo, v.status vst, c.nome_curso, c.id_curso, u.nome quem, (SELECT COUNT(*) FROM tb_video_marcacoes m WHERE m.id_versao=vv.id_versao) marc FROM tb_video_versoes vv JOIN tb_videos v ON v.id_video=vv.id_video JOIN tb_cursos c ON c.id_curso=v.id_curso LEFT JOIN tb_users u ON u.id_user=vv.id_user $sw ORDER BY vv.created_at DESC LIMIT 3000", $pp) as $x) {
        $out['linhas'][] = [date('d/m/Y', strtotime($x['created_at'])), $x['nome_curso'], (int)$x['modulo'] === 0 ? 'Geral' : 'Módulo ' . $x['modulo'], $x['titulo'], 'v' . $x['numero'], $x['origem'] ?: 'UPLOAD', ($x['origem'] ?? '') === 'DRIVE' ? $x['drive_url'] : $x['original_name'], ($x['origem'] ?? '') === 'DRIVE' ? '—' : ind_fmt_bytes((int)$x['file_size']), $x['quem'], $x['vst'], $x['marc']];
        $out['links'][] = 'video_revisao.php?id=' . (int)$x['id_video'];
      }
      $out['resumo'] = ['Versões' => count($out['linhas'])];
      break;

    case 'marcacoes':
      $out['colunas'] = ['Data', 'Curso', 'Vídeo', 'Versão', 'Tempo', 'Categoria', 'Status', 'Descrição', 'Marcado por'];
      [$wp, $pp] = ind_filtro_periodo($f, 'm.created_at');
      if (($f['situacao'] ?? '') === 'abertas') $wp[] = "m.status IN ('PENDENTE','EM_CORRECAO')";
      $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      $porCat = [];
      foreach (ind_rows("SELECT m.*, vv.numero, v.titulo, v.id_video, c.nome_curso, u.nome quem FROM tb_video_marcacoes m JOIN tb_video_versoes vv ON vv.id_versao=m.id_versao JOIN tb_videos v ON v.id_video=vv.id_video JOIN tb_cursos c ON c.id_curso=v.id_curso LEFT JOIN tb_users u ON u.id_user=m.id_user $sw ORDER BY m.created_at DESC LIMIT 3000", $pp) as $x) {
        $porCat[$x['categoria']] = ($porCat[$x['categoria']] ?? 0) + 1;
        $out['linhas'][] = [date('d/m/Y', strtotime($x['created_at'])), $x['nome_curso'], $x['titulo'], 'v' . $x['numero'], gmdate('H:i:s', (int)$x['tempo_seg']), $x['categoria'], $x['status'], mb_strimwidth((string)$x['descricao'], 0, 120, '…'), $x['quem']];
        $out['links'][] = 'video_revisao.php?id=' . (int)$x['id_video'];
      }
      $out['resumo'] = array_merge(['Marcações' => count($out['linhas'])], $porCat);
      break;

    case 'checklists':
      $out['colunas'] = ['Curso', 'Formador(a)', 'Status', 'Checklist', 'Itens', 'Marcados', '%'];
      if (!ind_tabela('tb_curso_checklist_respostas')) break;
      $w2 = $where ? str_replace('WHERE', 'AND', $where) : '';
      foreach (ind_rows("SELECT c.id_curso, c.nome_curso, c.status_atual, u.nome prof, ck.nome lista, COUNT(i.id_item) itens,
          SUM(CASE WHEN r.marcado=1 THEN 1 ELSE 0 END) marc
        FROM tb_cursos c JOIN tb_users u ON u.id_user=c.id_professor
        CROSS JOIN tb_checklists ck JOIN tb_checklist_itens i ON i.id_checklist=ck.id_checklist AND i.ativo=1
        LEFT JOIN tb_curso_checklist_respostas r ON r.id_curso=c.id_curso AND r.id_item=i.id_item
        WHERE 1=1 $w2 GROUP BY c.id_curso, ck.id_checklist ORDER BY c.nome_curso, ck.nome", $p) as $x) {
        $out['linhas'][] = [$x['nome_curso'], $x['prof'], $x['status_atual'], $x['lista'], $x['itens'], $x['marc'], ind_pct((int)$x['marc'], (int)$x['itens'])];
        $out['links'][] = 'curso_detalhe.php?id=' . (int)$x['id_curso'];
      }
      $out['resumo'] = ['Linhas' => count($out['linhas'])];
      break;

    case 'notificacoes':
      $out['colunas'] = ['Criado em', 'Destinatário', 'Assunto', 'Status', 'Tentativas', 'Enviado em', 'Erro'];
      [$wp, $pp] = ind_filtro_periodo($f, 'n.created_at');
      if (!empty($f['situacao'])) { $wp[] = "n.status = ?"; $pp[] = $f['situacao']; $out['filtros_desc'][] = 'status ' . $f['situacao']; }
      $sw = $wp ? 'WHERE ' . implode(' AND ', $wp) : '';
      $porSt = [];
      foreach (ind_rows("SELECT n.id_notificacao, n.destinatario_email, n.destinatario_nome, n.assunto, n.status, n.tentativas, n.sent_at, n.erro_msg, n.created_at FROM tb_notificacoes n $sw ORDER BY n.id_notificacao DESC LIMIT 3000", $pp) as $x) {
        $porSt[$x['status']] = ($porSt[$x['status']] ?? 0) + 1;
        $out['linhas'][] = [date('d/m/Y H:i', strtotime($x['created_at'])), $x['destinatario_nome'] . ' <' . $x['destinatario_email'] . '>', $x['assunto'], $x['status'], $x['tentativas'], $x['sent_at'] ? date('d/m/Y H:i', strtotime($x['sent_at'])) : '', $x['erro_msg']];
      }
      $out['resumo'] = array_merge(['E-mails' => count($out['linhas'])], $porSt);
      break;

    case 'auditoria_resumo':
      $out['colunas'] = ['Agrupamento', 'Valor', 'Registros', 'Último registro'];
      [$wp, $pp] = ind_filtro_periodo($f, 'a.created_at');
      if (!$wp) { $wp[] = "a.created_at >= NOW() - INTERVAL 30 DAY"; $out['filtros_desc'][] = 'últimos 30 dias'; }
      if (!empty($f['tipo'])) { $wp[] = "a.acao = ?"; $pp[] = $f['tipo']; $out['filtros_desc'][] = 'ação ' . $f['tipo']; }
      $sw = 'WHERE ' . implode(' AND ', $wp);
      foreach ([['Ação', 'a.acao'], ['Usuário', "COALESCE(u.nome,'(sistema)')"], ['Entidade', 'a.entidade']] as [$lab, $expr]) {
        foreach (ind_rows("SELECT $expr k, COUNT(*) n, MAX(a.created_at) ult FROM tb_audit_log a LEFT JOIN tb_users u ON u.id_user=a.id_user $sw GROUP BY k ORDER BY n DESC LIMIT 60", $pp) as $x)
          $out['linhas'][] = [$lab, $x['k'], $x['n'], date('d/m/Y H:i', strtotime($x['ult']))];
      }
      $out['resumo'] = ['Registros' => ind_n("SELECT COUNT(*) FROM tb_audit_log a $sw", $pp)];
      break;

    case 'modelos':
      $out['colunas'] = ['Título', 'Categoria', 'Versão', 'Vigente', 'Ativo', 'Arquivo', 'Tamanho', 'Publicado por', 'Publicado em', 'Downloads'];
      foreach (ind_rows("SELECT m.*, u.nome quem, (SELECT COUNT(*) FROM tb_audit_log a WHERE a.acao='modelo_baixado' AND a.entidade='modelo' AND a.id_entidade=m.id_modelo) down FROM tb_modelos m LEFT JOIN tb_users u ON u.id_user=m.id_user ORDER BY m.categoria, m.vigente DESC, m.created_at DESC") as $x) {
        $out['linhas'][] = [$x['titulo'], $x['categoria'], $x['versao'], $x['vigente'] ? 'sim' : 'não', $x['ativo'] ? 'sim' : 'não', $x['original_name'], ind_fmt_bytes((int)$x['file_size']), $x['quem'], date('d/m/Y', strtotime($x['created_at'])), $x['down']];
      }
      $out['resumo'] = ['Modelos' => count($out['linhas']), 'Vigentes' => count(array_filter($out['linhas'], fn($l) => $l[3] === 'sim'))];
      break;
  }
  return $out;
}

/** Opções para os filtros da página de relatórios. */
function rel_opcoes_filtros(): array {
  return [
    'status'    => array_column(ind_rows("SELECT nome FROM tb_status WHERE ativo=1 ORDER BY ordem"), 'nome'),
    'formadores'=> ind_rows("SELECT DISTINCT u.id_user, u.nome FROM tb_users u JOIN tb_cursos c ON c.id_professor=u.id_user ORDER BY u.nome"),
    'escolas'   => array_column(ind_rows("SELECT DISTINCT unidade_escolar FROM tb_cursos WHERE unidade_escolar<>'' ORDER BY 1"), 'unidade_escolar'),
    'niveis'    => array_column(ind_rows("SELECT DISTINCT nivel_ensino FROM tb_cursos WHERE nivel_ensino<>'' ORDER BY 1"), 'nivel_ensino'),
    'cargas'    => array_column(ind_rows("SELECT DISTINCT carga_horaria FROM tb_cursos ORDER BY 1"), 'carga_horaria'),
    'acoes'     => array_column(ind_rows("SELECT DISTINCT acao FROM tb_audit_log ORDER BY 1"), 'acao'),
  ];
}
