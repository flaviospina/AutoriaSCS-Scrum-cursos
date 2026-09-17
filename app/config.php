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
    // logos exibidas no cabeçalho e no login, na ordem (esquerda -> direita).
    // A do meio é a da Plataforma AutoriaSCS; ajuste as outras duas se o
    // nome do arquivo for diferente (uma logo inexistente é ocultada sozinha).
    'logos' => [
      'https://cecapescs.com.br/logos/logo-cecape-new.png',
      'https://cecapescs.com.br/logos/logo-autoriascs.png',
      'https://cecapescs.com.br/logos/logo-seeduc.png',
    ],
  ],
  // Vídeos por link do Google Drive (V12): conta de serviço do Google Cloud.
  // Crie a chave JSON em console.cloud.google.com (Drive API ativada) e salve
  // FORA de public/ (ex.: app/keys/drive.json). A MB compartilha a pasta dos
  // vídeos com o e-mail da conta de serviço (client_email), como Leitor.
  // Sem a chave, os links do Drive funcionam em modo de contingência (iframe).
  'drive' => [
    'key_file' => __DIR__ . '/keys/drive.json',
    'chunk_mb' => 8, // tamanho máximo de cada trecho transmitido ao navegador
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
