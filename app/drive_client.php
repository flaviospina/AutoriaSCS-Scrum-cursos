<?php
/**
 * Cliente mínimo da Google Drive API (V12) — sem bibliotecas externas.
 *
 * Autenticação por CONTA DE SERVIÇO (JWT RS256 assinado com openssl → token
 * OAuth2). A MB Estúdios compartilha a pasta dos vídeos com o e-mail da
 * conta de serviço (como Leitor) e o sistema passa a:
 *   - validar o link no cadastro (nome, tamanho, tipo, duração);
 *   - transmitir o vídeo ao navegador em trechos (Range) pelo video_stream.php,
 *     sem copiar o arquivo para o servidor.
 *
 * Configuração (config.local.php):
 *   'drive' => ['key_file' => __DIR__ . '/keys/drive.json', 'chunk_mb' => 8]
 * Sem a chave, o sistema funciona em modo de contingência (iframe do Drive).
 */
require_once __DIR__ . '/db.php';

class DriveException extends Exception {}

function drive_config(): array {
  static $cfg = null;
  if ($cfg === null) {
    $all = require __DIR__ . '/config.php';
    $cfg = array_merge([
      'key_file'  => __DIR__ . '/keys/drive.json',
      'chunk_mb'  => 8,
      'api_base'  => 'https://www.googleapis.com/drive/v3',
      'token_url' => 'https://oauth2.googleapis.com/token',
      'scope'     => 'https://www.googleapis.com/auth/drive.readonly',
    ], $all['drive'] ?? []);
  }
  return $cfg;
}

/** Conteúdo da chave JSON da conta de serviço (ou null quando não configurada). */
function drive_chave(): ?array {
  static $chave = false;
  if ($chave === false) {
    $chave = null;
    $f = drive_config()['key_file'] ?? '';
    if ($f && is_file($f) && is_readable($f)) {
      $j = json_decode((string)file_get_contents($f), true);
      if (is_array($j) && !empty($j['client_email']) && !empty($j['private_key'])) $chave = $j;
    }
  }
  return $chave;
}

/** A integração está configurada (chave válida presente)? */
function drive_configurado(): bool {
  return drive_chave() !== null && function_exists('curl_init') && function_exists('openssl_sign');
}

/** E-mail da conta de serviço — é com ele que a MB compartilha a pasta. */
function drive_service_email(): ?string {
  return drive_chave()['client_email'] ?? null;
}

/** Extrai o ID do arquivo de qualquer formato de link do Drive (ou o próprio ID). */
function drive_extrair_id(string $url): ?string {
  $url = trim($url);
  if ($url === '') return null;
  $padroes = [
    '#/file/d/([A-Za-z0-9_-]{10,})#',
    '#[?&]id=([A-Za-z0-9_-]{10,})#',
    '#/d/([A-Za-z0-9_-]{10,})#',
  ];
  foreach ($padroes as $p) if (preg_match($p, $url, $m)) return $m[1];
  if (preg_match('#^[A-Za-z0-9_-]{20,}$#', $url)) return $url; // ID puro
  return null;
}

function drive_link_visualizar(string $id): string { return "https://drive.google.com/file/d/{$id}/view"; }
function drive_link_preview(string $id): string   { return "https://drive.google.com/file/d/{$id}/preview"; }

function drive_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/**
 * Token de acesso OAuth2 (cache em storage/cache/drive_token.json, ~55 min).
 */
function drive_token(): string {
  $chave = drive_chave();
  if (!$chave) throw new DriveException("Integração com o Google Drive não configurada (chave da conta de serviço ausente).");

  $cacheDir = realpath(__DIR__ . '/../storage') . '/cache';
  if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
  $cacheFile = $cacheDir . '/drive_token.json';
  if (is_file($cacheFile)) {
    $c = json_decode((string)file_get_contents($cacheFile), true);
    if (!empty($c['token']) && !empty($c['exp']) && $c['exp'] > time() + 60 && ($c['email'] ?? '') === $chave['client_email']) {
      return $c['token'];
    }
  }

  $cfg = drive_config();
  $agora = time();
  $header = drive_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
  $claims = drive_b64url(json_encode([
    'iss' => $chave['client_email'], 'scope' => $cfg['scope'],
    'aud' => $cfg['token_url'], 'iat' => $agora, 'exp' => $agora + 3600,
  ]));
  $assinatura = '';
  if (!openssl_sign("{$header}.{$claims}", $assinatura, $chave['private_key'], OPENSSL_ALGO_SHA256)) {
    throw new DriveException("Falha ao assinar o JWT com a chave da conta de serviço (chave inválida?).");
  }
  $jwt = "{$header}.{$claims}." . drive_b64url($assinatura);

  $ch = curl_init($cfg['token_url']);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($resp === false) throw new DriveException("Não foi possível contatar o Google (token): {$err}");
  $j = json_decode($resp, true);
  if ($code !== 200 || empty($j['access_token'])) {
    throw new DriveException("Google recusou a credencial da conta de serviço (" . ($j['error_description'] ?? $j['error'] ?? "HTTP {$code}") . ").");
  }
  @file_put_contents($cacheFile, json_encode(['token' => $j['access_token'], 'exp' => $agora + (int)($j['expires_in'] ?? 3600), 'email' => $chave['client_email']]));
  @chmod($cacheFile, 0600);
  return $j['access_token'];
}

/** GET JSON na API. Lança DriveException com mensagem amigável. */
function drive_api_get(string $path, array $query = []): array {
  $cfg = drive_config();
  $query['supportsAllDrives'] = 'true';
  $url = rtrim($cfg['api_base'], '/') . '/' . ltrim($path, '/') . '?' . http_build_query($query);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . drive_token()],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
  ]);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($resp === false) throw new DriveException("Não foi possível contatar o Google Drive: {$err}");
  $j = json_decode($resp, true) ?: [];
  if ($code === 404) throw new DriveException("Arquivo não encontrado no Google Drive ou sem acesso. Compartilhe a pasta/arquivo (como Leitor) com: " . drive_service_email());
  if ($code === 403) throw new DriveException("Acesso negado pelo Google Drive. Compartilhe a pasta/arquivo (como Leitor) com: " . drive_service_email());
  if ($code !== 200) throw new DriveException("Google Drive respondeu HTTP {$code}: " . ($j['error']['message'] ?? 'erro desconhecido'));
  return $j;
}

/** Metadados do arquivo: id, name, mimeType, size, duração (ms). */
function drive_file_meta(string $id): array {
  $j = drive_api_get("files/" . rawurlencode($id), ['fields' => 'id,name,mimeType,size,videoMediaMetadata,md5Checksum']);
  return [
    'id'       => $j['id'] ?? $id,
    'name'     => $j['name'] ?? $id,
    'mime'     => $j['mimeType'] ?? 'application/octet-stream',
    'size'     => (int)($j['size'] ?? 0),
    'duracao_ms' => (int)($j['videoMediaMetadata']['durationMillis'] ?? 0),
    'largura'  => (int)($j['videoMediaMetadata']['width'] ?? 0),
    'altura'   => (int)($j['videoMediaMetadata']['height'] ?? 0),
  ];
}

/** Teste da credencial (Diagnóstico): identidade da conta de serviço na API. */
function drive_testar(): array {
  $j = drive_api_get('about', ['fields' => 'user']);
  return ['email' => $j['user']['emailAddress'] ?? drive_service_email(), 'ok' => true];
}

/**
 * Transmite o trecho [start,end] do arquivo diretamente para a saída (echo),
 * sem carregar em memória. Retorna o HTTP do Google (206/200 = ok).
 */
function drive_stream_range(string $id, int $start, int $end): int {
  $cfg = drive_config();
  $url = rtrim($cfg['api_base'], '/') . '/files/' . rawurlencode($id) . '?alt=media&supportsAllDrives=true';
  $ch = curl_init($url);
  $status = 0;
  curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . drive_token(), "Range: bytes={$start}-{$end}"],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_BUFFERSIZE => 256 * 1024,
    CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$status) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
      return strlen($h);
    },
    CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$status) {
      if ($status >= 400) return strlen($data); // descarta corpo de erro
      echo $data; flush();
      return connection_status() === CONNECTION_NORMAL ? strlen($data) : -1;
    },
  ]);
  curl_exec($ch);
  curl_close($ch);
  return $status;
}
