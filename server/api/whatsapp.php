<?php
declare(strict_types=1);

/**
 * WhatsApp send helper — posts to the Core Team / community group via the
 * self-hosted WAHA gateway (NOWEB engine) running on Coolify.
 *
 * Config (env, set in Coolify for the edv-server app):
 *   EDV_WAHA_URL      default https://r5en1qnjtuhxp3uqorom5lb9.hetzner.innerflect.tech
 *   EDV_WAHA_SESSION  default "default"
 *   EDV_WAHA_GROUP    default the Core Team group chatId (120363407545665402@g.us)
 *   EDV_WAHA_API_KEY  REQUIRED — without it, sending is disabled (returns ok=false).
 *
 * The same gateway powers the n8n "EDV — Campaign Watchdog" workflow.
 */

function edv_waha_config(): array
{
    $env = static function (string $name, string $default): string {
        $raw = getenv($name);
        return ($raw === false || trim((string) $raw) === '') ? $default : trim((string) $raw);
    };

    return [
        'url'     => rtrim($env('EDV_WAHA_URL', 'https://r5en1qnjtuhxp3uqorom5lb9.hetzner.innerflect.tech'), '/'),
        'session' => $env('EDV_WAHA_SESSION', 'default'),
        'group'   => $env('EDV_WAHA_GROUP', '120363407545665402@g.us'),
        'api_key' => $env('EDV_WAHA_API_KEY', ''),
    ];
}

function edv_waha_enabled(): bool
{
    $cfg = edv_waha_config();
    return $cfg['api_key'] !== '';
}

/**
 * Send a text message to a WhatsApp chat (defaults to the configured group).
 *
 * @return array{ok: bool, error?: string, status?: int}
 */
function edv_waha_send_text(string $text, ?string $chatId = null): array
{
    $cfg = edv_waha_config();
    if ($cfg['api_key'] === '') {
        return ['ok' => false, 'error' => 'EDV_WAHA_API_KEY não configurada (env do edv-server no Coolify).'];
    }
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'Mensagem vazia.'];
    }

    $payload = json_encode([
        'session' => $cfg['session'],
        'chatId'  => $chatId ?: $cfg['group'],
        'text'    => $text,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($cfg['url'] . '/api/sendText');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Api-Key: ' . $cfg['api_key'],
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status === 0) {
        return ['ok' => false, 'error' => 'WAHA inacessível' . ($curlErr !== '' ? ': ' . $curlErr : ''), 'status' => $status];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'WAHA HTTP ' . $status, 'status' => $status];
    }
    return ['ok' => true, 'status' => $status];
}
