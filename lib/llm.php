<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function openrouter_request(array $payload): array
{
    $api_key = getenv('OPENROUTER_API_KEY');
    if (!$api_key) {
        throw new RuntimeException('OPENROUTER_API_KEY is not set');
    }

    $base_url = getenv('OPENROUTER_BASE_URL') ?: 'https://openrouter.ai/api/v1';
    $url = rtrim($base_url, '/') . '/chat/completions';

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize curl');
    }

    $headers = [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ];

    $referer = getenv('OPENROUTER_REFERER');
    if ($referer) {
        $headers[] = 'HTTP-Referer: ' . $referer;
    }

    $title = getenv('OPENROUTER_TITLE');
    if ($title) {
        $headers[] = 'X-Title: ' . $title;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('OpenRouter request failed: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid OpenRouter response');
    }

    if ($status >= 400) {
        $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
        throw new RuntimeException('OpenRouter error: ' . $message);
    }

    return $decoded;
}

function call_openrouter_chat(array $messages, array $tools = [], string $model_override = ''): array
{
    $model = $model_override !== '' ? $model_override : (getenv('OPENROUTER_MODEL') ?: 'deepseek/deepseek-v3.2');

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.2,
        'max_tokens' => 1200,
    ];

    if ($tools) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = 'auto';
    }

    return openrouter_request($payload);
}

function extract_json(string $content): array
{
    $content = trim($content);
    if ($content === '') {
        return [];
    }

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) {
        return [];
    }

    $slice = substr($content, $start, $end - $start + 1);
    $decoded = json_decode($slice, true);
    return is_array($decoded) ? $decoded : [];
}
