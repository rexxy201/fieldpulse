<?php
/**
 * AI helper — thin wrapper around OpenAI's Chat Completions API.
 *
 * Every caller in this app treats AI as an optional assist, never a hard
 * dependency: if the API key isn't set, the request fails, or the response
 * doesn't parse, functions here return null and the calling feature falls
 * back to its normal manual behavior. AI must never be able to block a
 * ticket, installation, or any other core workflow.
 */

/** Whether the AI assistant is configured and turned on. */
function aiEnabled(): bool {
    $cfg = getAppConfig();
    return !empty($cfg['openaiApiKey']) && ($cfg['aiAssistantEnabled'] ?? '1') !== '0';
}

/**
 * Calls OpenAI's Chat Completions API and returns the assistant's raw text
 * reply, or null on any failure (missing key, network error, bad response).
 * Pass $jsonMode = true to request a JSON object response (recommended for
 * anything the caller needs to parse programmatically).
 */
function aiChat(string $systemPrompt, string $userPrompt, bool $jsonMode = false, float $temperature = 0.3): ?string {
    if (!aiEnabled()) return null;
    $cfg   = getAppConfig();
    $key   = $cfg['openaiApiKey'] ?? '';
    $model = $cfg['openaiModel'] ?? 'gpt-4o-mini';

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'temperature' => $temperature,
        'max_tokens'  => 800,
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    try {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('AI request failed: ' . $curlErr);
            return null;
        }
        if ($httpCode !== 200) {
            error_log("AI request returned HTTP {$httpCode}: " . substr($response, 0, 500));
            return null;
        }
        $data = json_decode($response, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        return is_string($text) ? trim($text) : null;
    } catch (\Throwable $e) {
        error_log('AI request exception: ' . $e->getMessage());
        return null;
    }
}

/** Same as aiChat() with JSON mode, but also decodes the result. Returns null on any failure. */
function aiChatJson(string $systemPrompt, string $userPrompt, float $temperature = 0.2): ?array {
    $raw = aiChat($systemPrompt, $userPrompt, true, $temperature);
    if ($raw === null) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}
