<?php
/**
 * Parâmetros do sistema (V13/V20) — tabela tb_config (chave/valor), ajustáveis pelo ADMIN
 * em Admin → Parâmetros. Tolerante à migração ausente: sem a tabela, valem os padrões.
 */
require_once __DIR__ . '/db.php';

const CFG_PADROES = [
  'coautor_espera_seg'     => '20',  // contagem regressiva antes do envio agrupado dos e-mails de coautores
  'upload_max_mb'          => '25',  // limite de cada arquivo enviado em "Arquivos do Curso"
  'dias_curso_parado'      => '30',  // dias sem alteração para o curso contar como "parado"
  'lixeira_retencao_dias'  => '90',  // dias na lixeira até o expurgo automático (0 = nunca apagar sozinho)
  'login_max_tentativas'   => '5',   // tentativas erradas seguidas até bloquear o login (0 = não bloquear)
  'login_bloqueio_min'     => '15',  // minutos de bloqueio
  'intervencoes_email'     => '1',   // 1 = resumo semanal das intervenções administrativas por e-mail aos admins
  'ti_email'               => '',    // vazio = usa o config(.local).php
  'from_email'             => '',    // vazio = usa o config(.local).php
  'from_name'              => '',    // vazio = usa o config(.local).php
  'email_rodape'           => '',    // texto extra no rodapé de todos os e-mails
];

/**
 * Definição dos parâmetros editáveis em tela: chave => [rótulo, descrição, tipo, min, max, grupo]
 * tipo: int | bool | email | text
 */
function cfg_param_defs(): array {
  return [
    'upload_max_mb'         => ['Limite de upload por arquivo (MB)', 'Tamanho máximo de cada arquivo em "Arquivos do Curso". O PHP do servidor também impõe o seu próprio limite (veja abaixo).', 'int', 1, 2048, 'Cursos e materiais'],
    'dias_curso_parado'     => ['Dias para considerar um curso "parado"', 'Sem nenhuma alteração por esse número de dias, o curso entra no indicador e no relatório de cursos parados.', 'int', 1, 365, 'Cursos e materiais'],
    'lixeira_retencao_dias' => ['Retenção da lixeira (dias)', 'Cursos na lixeira são apagados definitivamente (com backup dos arquivos) depois desse prazo, ao abrir a Lixeira. 0 = nunca apagar automaticamente.', 'int', 0, 3650, 'Cursos e materiais'],
    'coautor_espera_seg'    => ['Espera para o e-mail de coautores (segundos)', 'Contagem regressiva após a última inclusão de coautor antes de enviar o e-mail agrupado.', 'int', 5, 600, 'E-mails'],
    'ti_email'              => ['E-mail da TI (destinatário dos avisos)', 'Caixa que recebe os avisos de movimentação e apontamentos. Em branco: usa o valor do config.local.php.', 'email', 0, 160, 'E-mails'],
    'from_email'            => ['Remetente (e-mail)', 'Endereço "De:" dos e-mails do sistema. Em branco: usa o config.local.php. Precisa ser um e-mail autorizado no servidor de envio.', 'email', 0, 160, 'E-mails'],
    'from_name'             => ['Remetente (nome)', 'Nome exibido no "De:". Em branco: usa o config.local.php.', 'text', 0, 80, 'E-mails'],
    'email_rodape'          => ['Texto extra no rodapé dos e-mails', 'Aparece abaixo da mensagem padrão em todos os e-mails (ex.: telefone, horário de atendimento).', 'text', 0, 255, 'E-mails'],
    'intervencoes_email'    => ['Resumo semanal das intervenções administrativas', 'Envia aos administradores, uma vez por semana, a lista das ações administrativas realizadas.', 'bool', 0, 1, 'E-mails'],
    'login_max_tentativas'  => ['Tentativas de login até bloquear', 'Erros de senha seguidos que bloqueiam o acesso do usuário temporariamente. 0 = não bloquear.', 'int', 0, 50, 'Segurança'],
    'login_bloqueio_min'    => ['Duração do bloqueio de login (minutos)', 'Tempo que o usuário fica bloqueado após exceder as tentativas. O ADMIN pode desbloquear antes, em Usuários.', 'int', 1, 1440, 'Segurança'],
  ];
}

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

/** Inteiro dentro de limites (valor inválido → padrão). */
function cfg_int(string $chave, int $min, int $max): int {
  $v = cfg_get($chave); $p = (int)(CFG_PADROES[$chave] ?? 0);
  if ($v === null || $v === '' || !is_numeric($v)) return $p;
  return max($min, min($max, (int)$v));
}

/** Segundos de espera após a última inclusão de coautor (ADMIN: 5 a 600 s; padrão 20). */
function coautor_espera_seg(): int { return cfg_int('coautor_espera_seg', 5, 600) ?: 20; }
function upload_max_mb(): int { return cfg_int('upload_max_mb', 1, 2048) ?: 25; }
function dias_curso_parado(): int { return cfg_int('dias_curso_parado', 1, 365) ?: 30; }
function lixeira_retencao_dias(): int { return cfg_int('lixeira_retencao_dias', 0, 3650); }
function login_max_tentativas(): int { return cfg_int('login_max_tentativas', 0, 50); }
function login_bloqueio_min(): int { return cfg_int('login_bloqueio_min', 1, 1440) ?: 15; }
