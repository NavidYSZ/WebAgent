<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function openai_request(array $payload): array
{
    $api_key = getenv('OPENAI_API_KEY');
    if (!$api_key) {
        throw new RuntimeException('OPENAI_API_KEY is not set');
    }

    $base_url = getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1';
    $url = rtrim($base_url, '/') . '/responses';

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize curl');
    }

    $headers = [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ];

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
        throw new RuntimeException('OpenAI request failed: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid OpenAI response');
    }

    if ($status >= 400) {
        $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
        throw new RuntimeException('OpenAI error: ' . $message);
    }

    return $decoded;
}

function normalize_tools_for_responses(array $tools): array
{
    $normalized = [];
    foreach ($tools as $tool) {
        if (!is_array($tool)) {
            continue;
        }
        $type = (string) ($tool['type'] ?? '');
        if ($type !== 'function') {
            $normalized[] = $tool;
            continue;
        }
        if (isset($tool['function']) && is_array($tool['function'])) {
            $fn = $tool['function'];
            $normalized[] = [
                'type' => 'function',
                'name' => (string) ($fn['name'] ?? ''),
                'description' => (string) ($fn['description'] ?? ''),
                'parameters' => $fn['parameters'] ?? [],
            ];
        } else {
            $normalized[] = $tool;
        }
    }
    return $normalized;
}

function extract_response_text($content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }
    $parts = [];
    foreach ($content as $item) {
        if (is_string($item)) {
            $parts[] = $item;
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        $type = (string) ($item['type'] ?? '');
        if ($type === 'output_text' || $type === 'text') {
            $text = (string) ($item['text'] ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
        }
    }
    return implode('', $parts);
}

function extract_response_message(array $response): array
{
    $content = '';
    if (!empty($response['output_text']) && is_string($response['output_text'])) {
        $content = $response['output_text'];
    }

    $tool_calls = [];
    $output = $response['output'] ?? [];
    if (is_array($output)) {
        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            if ($type === 'message') {
                $content = trim($content . "\n" . extract_response_text($item['content'] ?? ''));
                continue;
            }
            if ($type !== 'tool_call' && $type !== 'function_call') {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            $arguments = $item['arguments'] ?? '';
            if ($name === '' && isset($item['function']) && is_array($item['function'])) {
                $name = (string) ($item['function']['name'] ?? '');
                $arguments = $item['function']['arguments'] ?? $arguments;
            }
            if ($name === '') {
                continue;
            }
            if (!is_string($arguments)) {
                $arguments = json_encode($arguments, JSON_UNESCAPED_SLASHES);
            }
            $tool_calls[] = [
                'id' => (string) ($item['id'] ?? $item['call_id'] ?? ''),
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => (string) $arguments,
                ],
            ];
        }
    }

    return [
        'content' => $content,
        'tool_calls' => $tool_calls,
    ];
}

function call_openai_chat(array $messages, array $tools = [], string $model_override = ''): array
{
    $model = $model_override !== '' ? $model_override : (getenv('OPENAI_MODEL') ?: 'gpt-5.1-codex-max');

    $payload = [
        'model' => $model,
        'input' => $messages,
        'max_output_tokens' => 15000,
    ];

    if ($tools) {
        $payload['tools'] = normalize_tools_for_responses($tools);
        $payload['tool_choice'] = 'auto';
    }

    $response = openai_request($payload);
    if (isset($response['choices'])) {
        return $response;
    }

    $message = extract_response_message($response);
    return [
        'choices' => [
            [
                'message' => $message,
            ],
        ],
        'response_id' => $response['id'] ?? '',
    ];
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
