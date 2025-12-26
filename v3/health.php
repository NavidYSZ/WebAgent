<?php

declare(strict_types=1);

header('Content-Type: application/json');

function parse_disabled_functions(): array
{
    $raw = (string) ini_get('disable_functions');
    if ($raw === '') {
        return [];
    }
    $parts = array_map('trim', explode(',', $raw));
    return array_values(array_filter($parts, static fn ($item) => $item !== ''));
}

function is_disabled(string $name, array $disabled): bool
{
    return in_array($name, $disabled, true);
}

$disabled = parse_disabled_functions();
$open_basedir = (string) ini_get('open_basedir');

$report = [
    'php_version' => PHP_VERSION,
    'open_basedir' => $open_basedir,
    'extensions' => [
        'curl' => extension_loaded('curl'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    ],
    'env' => [
        'openai_key_present' => getenv('OPENAI_API_KEY') ? true : false,
    ],
    'functions' => [
        'proc_open' => [
            'available' => function_exists('proc_open'),
            'disabled' => is_disabled('proc_open', $disabled),
            'can_run' => false,
            'stdout' => '',
            'stderr' => '',
        ],
        'exec' => [
            'available' => function_exists('exec'),
            'disabled' => is_disabled('exec', $disabled),
        ],
        'shell_exec' => [
            'available' => function_exists('shell_exec'),
            'disabled' => is_disabled('shell_exec', $disabled),
        ],
    ],
];

if ($report['functions']['proc_open']['available'] && !$report['functions']['proc_open']['disabled']) {
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open('echo agentops', $descriptor, $pipes);
    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $report['functions']['proc_open']['can_run'] = ($exit === 0);
        $report['functions']['proc_open']['stdout'] = trim((string) $stdout);
        $report['functions']['proc_open']['stderr'] = trim((string) $stderr);
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
