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
    'base_url' => 'https://cecapescs.com.br/autoriascs/ciclo_acompa/public',
    'nome'     => 'AutoriaSCS • Gestão de Cursos',
  ],
  'n8n' => [
    'webhook_url' => '', // ex.: https://SEU_N8N/webhook/curso-event (vazio = desativado)
    'token'       => '',
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
