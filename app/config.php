<?php
/**
 * Configuração da aplicação.
 *
 * IMPORTANTE: não commitar credenciais reais.
 * Crie um arquivo app/config.local.php (ignorado pelo git) devolvendo o
 * mesmo array com os dados reais do ambiente de produção/homologação.
 */

$config = [
  'db' => [
    'host'    => 'localhost',
    'name'    => 'NOME_DO_BANCO',
    'user'    => 'USUARIO_DO_BANCO',
    'pass'    => 'SENHA_DO_BANCO',
    'charset' => 'utf8mb4',
  ],
  'app' => [
    'base_url' => 'https://cecapescs.com.br/autoriascs/scrum/public',
    'nome'     => 'AutoriaSCS • Gestão de Cursos',
    // duração da sessão de login (horas); cada clique renova o prazo
    'sessao_horas' => 8,
  ],
  'n8n' => [
    'webhook_url' => '', // ex.: https://SEU_N8N/webhook/curso-event (vazio = desativado)
    'token'       => '',
  ],
  'mail' => [
    // method: 'mail' (nativo do cPanel), 'smtp' (autenticado) ou 'disabled'
    'method'      => 'mail',
    'from_email'  => 'no-reply.cecape@scseduca.com.br',
    'from_name'   => 'AutoriaSCS - CECAPE',
    // caixa institucional da equipe TI & AutoriaSCS — recebe aviso de toda
    // movimentação de status feita pelos perfis PROFESSOR e MB
    'ti_email'    => 'ti.cecape@scseduca.com.br',
    // usados apenas quando method = 'smtp'
    'smtp_host'   => 'mail.scseduca.com.br',
    'smtp_port'   => 587,
    'smtp_user'   => '',
    'smtp_pass'   => '',
    'smtp_secure' => 'tls', // 'ssl' (465) | 'tls' (587) | 'none'
  ],
  'cron' => [
    // chave exigida pelos scripts de cron quando chamados via URL
    'chave' => 'troque-esta-chave-cron',
  ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
  $override = require $local;
  if (is_array($override)) {
    $config = array_replace_recursive($config, $override);
  }
}

return $config;
