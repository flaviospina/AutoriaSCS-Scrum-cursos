<?php
/**
 * Bootstrap de sessão — substitui o session_start() espalhado pelas páginas.
 *
 * - Validade configurável (padrão 8h) em vez dos 24 min padrão do PHP;
 * - Renovação deslizante: cada requisição renova o prazo do cookie;
 * - Arquivos de sessão em storage/sessions (pasta própria do sistema,
 *   imune à limpeza agressiva do /tmp em hospedagem compartilhada);
 * - Cookies HttpOnly + SameSite Lax + modo estrito.
 *
 * Ajuste a duração em config(.local).php: 'app' => ['sessao_horas' => 8]
 */

function session_boot(): void {
  if (session_status() !== PHP_SESSION_NONE) return; // já iniciada

  $cfg = require __DIR__ . '/config.php';
  $horas = (int)($cfg['app']['sessao_horas'] ?? 8);
  if ($horas < 1) $horas = 8;
  $lifetime = $horas * 3600;

  // pasta de sessões própria (protegida por storage/.htaccess)
  $dir = __DIR__ . '/../storage/sessions';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  if (is_dir($dir) && is_writable($dir)) {
    session_save_path($dir);
  }

  ini_set('session.gc_maxlifetime', (string)$lifetime);
  ini_set('session.use_strict_mode', '1');

  session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();

  // renovação deslizante: estende o prazo do cookie a cada requisição
  if (PHP_SAPI !== 'cli' && isset($_COOKIE[session_name()]) && !headers_sent()) {
    setcookie(session_name(), session_id(), [
      'expires'  => time() + $lifetime,
      'path'     => '/',
      'secure'   => !empty($_SERVER['HTTPS']),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}
