<?php
function n8n_emit_event(string $eventType, array $payload): void {
  $cfg = require __DIR__ . '/config.php';
  $url = $cfg['n8n']['webhook_url'] ?? '';
  $token = $cfg['n8n']['token'] ?? '';

  if ($url === '' || str_contains($url, 'SEU_N8N')) return; // integração desativada

  $body = json_encode([
    'token' => $token,
    'event_type' => $eventType,
    'payload' => $payload,
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
  ]);
  curl_exec($ch);
  curl_close($ch);
}
