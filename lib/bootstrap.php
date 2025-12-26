<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

define('APP_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));

function resolve_path(string $path, string $base): string
{
    if ($path === '') {
        return $base;
    }

    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return $path;
    }

    return rtrim($base, '/\\') . '/' . $path;
}

function normalize_string_list($value): array
{
    $items = [];
    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value)) {
        $parts = preg_split('/[\n,]+/', $value);
        $items = is_array($parts) ? $parts : [$value];
    }

    $normalized = [];
    foreach ($items as $item) {
        $text = trim((string) $item);
        if ($text === '') {
            continue;
        }
        $normalized[] = $text;
    }

    return array_values(array_unique($normalized));
}

function decode_json_list(?string $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return [];
    }

    return normalize_string_list($decoded);
}

function detect_php_binary(): string
{
    $env = getenv('AGENTOPS_WEB_PHP_BIN');
    if ($env !== false && trim($env) !== '') {
        return trim($env);
    }

    if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' && file_exists(PHP_BINARY)) {
        return PHP_BINARY;
    }

    if (defined('PHP_BINDIR') && is_string(PHP_BINDIR) && PHP_BINDIR !== '') {
        $candidate = rtrim(PHP_BINDIR, '/\\') . '/php';
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return 'php';
}

function php_binary(): string
{
    static $binary = null;
    if ($binary === null) {
        $binary = detect_php_binary();
    }
    return $binary;
}

function check_file_path(): string
{
    $check_env = getenv('AGENTOPS_WEB_CHECK_FILE');
    $path = ($check_env !== false && $check_env !== '') ? $check_env : 'check.txt';
    return resolve_path($path, APP_ROOT);
}

$data_dir = getenv('AGENTOPS_WEB_DATA_DIR') ?: 'data';
$log_dir = getenv('AGENTOPS_WEB_LOG_DIR') ?: 'logs';
$report_dir = getenv('AGENTOPS_WEB_REPORT_DIR') ?: 'reports';
$db_path = getenv('AGENTOPS_WEB_DB') ?: '';
$workspaces_dir = getenv('AGENTOPS_WEB_WORKSPACES_DIR') ?: 'workspaces';

define('DATA_DIR', resolve_path($data_dir, APP_ROOT));
define('LOG_DIR', resolve_path($log_dir, APP_ROOT));
define('REPORT_DIR', resolve_path($report_dir, APP_ROOT));
define('DB_PATH', $db_path !== '' ? resolve_path($db_path, APP_ROOT) : DATA_DIR . '/agentops.sqlite');
define('WORKSPACES_DIR', resolve_path($workspaces_dir, APP_ROOT));

define('HEARTBEAT_PATH', DATA_DIR . '/worker_heartbeat.json');
define('SETTINGS_PATH', DATA_DIR . '/settings.json');

define('MAX_LOG_BYTES', 1024 * 1024);

function list_workspace_repos(): array
{
    $root = WORKSPACES_DIR;
    if (!is_dir($root)) {
        return [];
    }

    $entries = scandir($root);
    if (!is_array($entries)) {
        return [];
    }

    $repos = [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue;
        }
        $path = $root . '/' . $name;
        if (!is_dir($path)) {
            continue;
        }
        $repos[] = [
            'name' => $name,
            'path' => $path,
        ];
    }

    usort($repos, static fn ($a, $b) => strcmp($a['name'], $b['name']));
    return $repos;
}

function ensure_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    ensure_dir(DATA_DIR);
    ensure_dir(LOG_DIR);
    ensure_dir(REPORT_DIR);

    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = WAL;');
    $db->exec('PRAGMA foreign_keys = ON;');

    $db->exec(
        'CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            request_text TEXT NOT NULL,
            payload TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            started_at TEXT,
            finished_at TEXT,
            last_log_at TEXT,
            error_text TEXT
        );'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS job_steps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            step_index INTEGER NOT NULL,
            goal TEXT NOT NULL,
            acceptance_criteria TEXT NOT NULL,
            scope TEXT,
            constraints TEXT,
            file_allowlist TEXT,
            command_allowlist TEXT,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            started_at TEXT,
            finished_at TEXT,
            report_path TEXT,
            error_text TEXT,
            FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
        );'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS job_subtasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            step_id INTEGER NOT NULL,
            subtask_index INTEGER NOT NULL,
            title TEXT NOT NULL,
            instruction TEXT NOT NULL,
            acceptance_criteria TEXT NOT NULL,
            scope TEXT,
            constraints TEXT,
            file_allowlist TEXT,
            command_allowlist TEXT,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            started_at TEXT,
            finished_at TEXT,
            report_path TEXT,
            error_text TEXT,
            FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE,
            FOREIGN KEY(step_id) REFERENCES job_steps(id) ON DELETE CASCADE
        );'
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at);');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_job_steps_status ON job_steps(job_id, status, step_index);');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_job_subtasks_status ON job_subtasks(step_id, status, subtask_index);');
    ensure_workorder_columns($db);

    return $db;
}

function ensure_workorder_columns(PDO $db): void
{
    $columns = [
        'job_steps' => [
            'scope' => 'TEXT',
            'constraints' => 'TEXT',
            'file_allowlist' => 'TEXT',
            'command_allowlist' => 'TEXT',
        ],
        'job_subtasks' => [
            'scope' => 'TEXT',
            'constraints' => 'TEXT',
            'file_allowlist' => 'TEXT',
            'command_allowlist' => 'TEXT',
        ],
    ];

    foreach ($columns as $table => $defs) {
        $existing = [];
        $info = $db->query('PRAGMA table_info(' . $table . ')');
        if ($info) {
            $rows = $info->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (!empty($row['name'])) {
                    $existing[$row['name']] = true;
                }
            }
        }

        foreach ($defs as $name => $type) {
            if (isset($existing[$name])) {
                continue;
            }
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $type);
        }
    }
}

function log_path(int $job_id): string
{
    return LOG_DIR . '/job_' . $job_id . '.log';
}

function step_log_path(int $job_id, int $step_id): string
{
    return LOG_DIR . '/job_' . $job_id . '_step_' . $step_id . '.log';
}

function subtask_log_path(int $job_id, int $step_id, int $subtask_id): string
{
    return LOG_DIR . '/job_' . $job_id . '_step_' . $step_id . '_task_' . $subtask_id . '.log';
}

function report_path(int $job_id): string
{
    return REPORT_DIR . '/job_' . $job_id . '.json';
}

function plan_path(int $job_id): string
{
    return REPORT_DIR . '/job_' . $job_id . '_plan.json';
}

function architecture_path(int $job_id): string
{
    return REPORT_DIR . '/job_' . $job_id . '_architecture.json';
}

function step_report_path(int $job_id, int $step_id): string
{
    return REPORT_DIR . '/job_' . $job_id . '_step_' . $step_id . '.json';
}

function subtask_report_path(int $job_id, int $step_id, int $subtask_id): string
{
    return REPORT_DIR . '/job_' . $job_id . '_step_' . $step_id . '_task_' . $subtask_id . '.json';
}

function now_iso(): string
{
    return gmdate('c');
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_heartbeat(string $worker_id): void
{
    $payload = [
        'worker_id' => $worker_id,
        'ts' => now_iso(),
    ];
    file_put_contents(HEARTBEAT_PATH, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function read_heartbeat(): ?array
{
    if (!file_exists(HEARTBEAT_PATH)) {
        return null;
    }

    $payload = json_decode(file_get_contents(HEARTBEAT_PATH), true);
    return is_array($payload) ? $payload : null;
}

function default_role_models(): array
{
    return [
        'architect' => '',
        'orchestrator' => '',
        'dept_head' => '',
        'worker' => '',
        'test_fix' => '',
    ];
}

function normalize_role_models(array $models): array
{
    $normalized = [];
    foreach (default_role_models() as $role => $value) {
        $model = $models[$role] ?? '';
        $normalized[$role] = is_string($model) ? trim($model) : '';
    }
    return $normalized;
}

function read_settings(): array
{
    if (!file_exists(SETTINGS_PATH)) {
        return ['models' => default_role_models()];
    }

    $raw = file_get_contents(SETTINGS_PATH);
    $decoded = $raw ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return ['models' => default_role_models()];
    }

    $decoded['models'] = normalize_role_models($decoded['models'] ?? []);
    return $decoded;
}

function write_settings(array $settings): void
{
    ensure_dir(DATA_DIR);
    $settings['models'] = normalize_role_models($settings['models'] ?? []);
    file_put_contents(
        SETTINGS_PATH,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}
