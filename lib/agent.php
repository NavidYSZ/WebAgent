<?php

declare(strict_types=1);

require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/tools.php';

function tool_definitions(): array
{
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_files',
                'description' => 'List files and directories in the repo.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'depth' => ['type' => 'integer'],
                        'max_entries' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'read_file',
                'description' => 'Read a file from the repo.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['path'],
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'max_bytes' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'write_file',
                'description' => 'Write a file in the repo.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['path', 'content'],
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'mkdir',
                'description' => 'Create a directory in the repo.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['path'],
                    'properties' => [
                        'path' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'apply_patch',
                'description' => 'Apply a unified diff patch to the repo.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['patch'],
                    'properties' => [
                        'patch' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'run_command',
                'description' => 'Run an allowed shell command inside the repo.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['command'],
                    'properties' => [
                        'command' => ['type' => 'string'],
                        'cwd' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];
}

function plan_prompt(string $request, string $repo_summary, string $architecture_summary): array
{
    $system = <<<SYS
You are the orchestrator. Output only valid JSON matching this schema:
{
  "steps": [
    {
      "scope": "repo",
      "goal": "string",
      "acceptance_criteria": ["string", "string"],
      "constraints": ["string"],
      "file_allowlist": ["string"],
      "command_allowlist": ["string"]
    }
  ]
}
Rules: create as many steps as needed, keep steps small and logically separated, 2-6 acceptance criteria each.
These steps will be handed to a dept-head for subtasking; keep steps higher-level but still actionable.
If a field is not needed, return an empty array for it.
SYS;

    $user = "Request:\n" . $request
        . "\n\nArchitecture summary:\n" . $architecture_summary
        . "\n\nRepo summary:\n" . $repo_summary;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function architect_prompt(string $request, string $repo_summary): array
{
    $system = <<<SYS
You are the architect. Produce a concise architecture plan as JSON only.
Schema:
{
  "overview": "string",
  "components": ["string"],
  "data_flows": ["string"],
  "constraints": ["string"],
  "decisions": ["string"]
}
SYS;
    $user = "Request:\n" . $request . "\n\nRepo summary:\n" . $repo_summary;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function dept_head_prompt(
    string $request,
    array $step,
    string $architecture_summary,
    string $repo_summary
): array {
    $system = <<<SYS
You are the dept-head. Split the step into 1-4 worker subtasks.
Output JSON only:
{
  "subtasks": [
    {
      "title": "string",
      "instruction": "string",
      "acceptance_criteria": ["string"],
      "constraints": ["string"],
      "file_allowlist": ["string"],
      "command_allowlist": ["string"]
    }
  ]
}
Rules: subtasks must be small and actionable. Keep instructions specific.
If a field is not needed, return an empty array for it.
SYS;

    $criteria = $step['acceptance_criteria'] ?? [];
    $criteria_text = is_array($criteria) ? implode("\n- ", $criteria) : '';
    $constraints = $step['constraints'] ?? [];
    $constraints_text = is_array($constraints) ? implode("\n- ", $constraints) : '';
    $file_allowlist = $step['file_allowlist'] ?? [];
    $file_allowlist_text = is_array($file_allowlist) ? implode("\n- ", $file_allowlist) : '';
    $command_allowlist = $step['command_allowlist'] ?? [];
    $command_allowlist_text = is_array($command_allowlist) ? implode("\n- ", $command_allowlist) : '';
    $user = "Request:\n" . $request
        . "\n\nArchitecture summary:\n" . $architecture_summary
        . "\n\nStep goal:\n" . ($step['goal'] ?? '')
        . "\n\nStep scope:\n" . ($step['scope'] ?? 'repo')
        . "\n\nAcceptance criteria:\n- " . $criteria_text
        . "\n\nConstraints:\n- " . $constraints_text
        . "\n\nFile allowlist:\n- " . $file_allowlist_text
        . "\n\nCommand allowlist:\n- " . $command_allowlist_text
        . "\n\nRepo summary:\n" . $repo_summary;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function worker_prompt(
    string $request,
    array $step,
    array $subtask,
    string $repo_summary,
    string $architecture_summary,
    bool $tests_enabled,
    string $test_command
): array {
    $system = <<<SYS
You are a coding agent operating on a local repo. Use the provided tools to inspect and modify files.
- Stay within the repo.
- Prefer small, safe edits.
- Use apply_patch with unified diff when editing existing files.
- If you need to change files, you must call tools (write_file/apply_patch). Do not describe changes without using tools.
- When finished, output JSON only with keys: status, summary, changed_files, commands_run, checks, risks.
- checks must be an object with keys install, lint, test, build.
SYS;
    if ($tests_enabled) {
        $system .= "\n- Tests are enabled. You may add or update tests. The system will run tests"
            . ($test_command !== '' ? " using: " . $test_command : "")
            . ".";
    }

    $goal = $step['goal'] ?? 'Implement the request.';
    $criteria = $step['acceptance_criteria'] ?? [];
    $subtask_title = $subtask['title'] ?? 'Subtask';
    $subtask_instruction = $subtask['instruction'] ?? '';
    $subtask_criteria = $subtask['acceptance_criteria'] ?? [];
    $constraints = $subtask['constraints'] ?? $step['constraints'] ?? [];
    $file_allowlist = $subtask['file_allowlist'] ?? $step['file_allowlist'] ?? [];
    $command_allowlist = $subtask['command_allowlist'] ?? $step['command_allowlist'] ?? [];
    $criteria_text = is_array($criteria) ? implode("\n- ", $criteria) : '';
    $subtask_criteria_text = is_array($subtask_criteria) ? implode("\n- ", $subtask_criteria) : '';
    $constraints_text = is_array($constraints) ? implode("\n- ", $constraints) : '';
    $file_allowlist_text = is_array($file_allowlist) ? implode("\n- ", $file_allowlist) : '';
    $command_allowlist_text = is_array($command_allowlist) ? implode("\n- ", $command_allowlist) : '';

    $tests_note = $tests_enabled ? ("\n\nTests enabled: yes\nTest command: " . ($test_command !== '' ? $test_command : 'auto')) : '';
    $user = "Request:\n" . $request
        . "\n\nArchitecture summary:\n" . $architecture_summary
        . "\n\nStep goal:\n" . $goal
        . "\n\nStep scope:\n" . ($step['scope'] ?? 'repo')
        . "\n\nSubtask:\n" . $subtask_title
        . "\n\nInstruction:\n" . $subtask_instruction
        . "\n\nStep acceptance criteria:\n- " . $criteria_text
        . "\n\nSubtask acceptance criteria:\n- " . $subtask_criteria_text
        . "\n\nConstraints:\n- " . $constraints_text
        . "\n\nFile allowlist:\n- " . $file_allowlist_text
        . "\n\nCommand allowlist:\n- " . $command_allowlist_text
        . "\n\nRepo summary:\n" . $repo_summary . $tests_note;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function build_repo_summary(string $repo_root): string
{
    $entries = list_tree($repo_root, '.', 3, 200);
    if (!$entries) {
        return "(empty repo)";
    }
    return implode("\n", $entries);
}

function truncate_text(string $text, int $max_bytes): string
{
    if (strlen($text) <= $max_bytes) {
        return $text;
    }
    return substr($text, 0, $max_bytes) . "\n...[truncated]";
}

function detect_test_command(string $repo_root): string
{
    $env = getenv('AGENTOPS_WEB_TEST_CMD');
    if ($env !== false && trim($env) !== '') {
        return trim($env);
    }

    if (file_exists($repo_root . '/package.json')) {
        return 'npm test';
    }
    if (file_exists($repo_root . '/pyproject.toml') || file_exists($repo_root . '/requirements.txt')) {
        return 'python -m pytest';
    }
    if (file_exists($repo_root . '/composer.json')) {
        if (file_exists($repo_root . '/vendor/bin/phpunit') || file_exists($repo_root . '/phpunit.xml')
            || file_exists($repo_root . '/phpunit.xml.dist')) {
            return 'php vendor/bin/phpunit';
        }
        return 'composer test';
    }

    return '';
}

function summarize_architecture(array $architecture): string
{
    $json = json_encode($architecture, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '';
    }
    return truncate_text($json, 2000);
}

function validate_subtasks(array $payload, array $step): array
{
    $subtasks = $payload['subtasks'] ?? [];
    if (!is_array($subtasks) || count($subtasks) === 0) {
        return [[
            'title' => 'Implement step',
            'instruction' => (string) ($step['goal'] ?? 'Implement the step.'),
            'acceptance_criteria' => $step['acceptance_criteria'] ?? ['Changes applied', 'No errors'],
            'scope' => $step['scope'] ?? 'repo',
            'constraints' => $step['constraints'] ?? [],
            'file_allowlist' => $step['file_allowlist'] ?? [],
            'command_allowlist' => $step['command_allowlist'] ?? [],
        ]];
    }

    $valid = [];
    foreach ($subtasks as $subtask) {
        if (!is_array($subtask)) {
            continue;
        }
        $title = trim((string) ($subtask['title'] ?? ''));
        $instruction = trim((string) ($subtask['instruction'] ?? ''));
        if ($title === '' || $instruction === '') {
            continue;
        }
        $criteria = $subtask['acceptance_criteria'] ?? [];
        if (!is_array($criteria) || count($criteria) === 0) {
            $criteria = ['Changes applied', 'No errors'];
        }
        $constraints = normalize_string_list($subtask['constraints'] ?? ($step['constraints'] ?? []));
        $file_allowlist = normalize_string_list($subtask['file_allowlist'] ?? ($step['file_allowlist'] ?? []));
        $command_allowlist = normalize_string_list($subtask['command_allowlist'] ?? ($step['command_allowlist'] ?? []));
        $scope = trim((string) ($subtask['scope'] ?? ($step['scope'] ?? 'repo')));
        if ($scope === '') {
            $scope = 'repo';
        }
        $valid[] = [
            'title' => $title,
            'instruction' => $instruction,
            'acceptance_criteria' => array_values($criteria),
            'scope' => $scope,
            'constraints' => $constraints,
            'file_allowlist' => $file_allowlist,
            'command_allowlist' => $command_allowlist,
        ];
    }

    if (!$valid) {
        return [[
            'title' => 'Implement step',
            'instruction' => (string) ($step['goal'] ?? 'Implement the step.'),
            'acceptance_criteria' => $step['acceptance_criteria'] ?? ['Changes applied', 'No errors'],
            'scope' => $step['scope'] ?? 'repo',
            'constraints' => $step['constraints'] ?? [],
            'file_allowlist' => $step['file_allowlist'] ?? [],
            'command_allowlist' => $step['command_allowlist'] ?? [],
        ]];
    }

    return $valid;
}

function test_fix_prompt(string $request, string $repo_summary, string $test_command, string $test_output): array
{
    $system = <<<SYS
You are a coding agent fixing failing tests. Use the provided tools to inspect and modify files.
- Stay within the repo.
- Prefer small, safe edits.
- Do not re-run tests; the system will run them.
- When finished, output JSON only with keys: status, summary, changed_files, commands_run, checks, risks.
SYS;

    $user = "Request:\n" . $request
        . "\n\nTest command:\n" . $test_command
        . "\n\nTest output:\n" . $test_output
        . "\n\nRepo summary:\n" . $repo_summary;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function validate_plan(array $plan): array
{
    if (!isset($plan['steps']) || !is_array($plan['steps']) || count($plan['steps']) === 0) {
        return [];
    }

    $steps = [];
    foreach ($plan['steps'] as $step) {
        if (!is_array($step)) {
            continue;
        }
        $goal = trim((string) ($step['goal'] ?? ''));
        if ($goal === '') {
            continue;
        }
        $criteria = $step['acceptance_criteria'] ?? [];
        if (!is_array($criteria) || count($criteria) < 2) {
            $criteria = ['Changes applied', 'No errors'];
        }
        $constraints = normalize_string_list($step['constraints'] ?? []);
        $file_allowlist = normalize_string_list($step['file_allowlist'] ?? []);
        $command_allowlist = normalize_string_list($step['command_allowlist'] ?? []);
        $scope = trim((string) ($step['scope'] ?? 'repo'));
        if ($scope === '') {
            $scope = 'repo';
        }
        $steps[] = [
            'scope' => $scope,
            'goal' => $goal,
            'acceptance_criteria' => array_values($criteria),
            'constraints' => $constraints,
            'file_allowlist' => $file_allowlist,
            'command_allowlist' => $command_allowlist,
        ];
    }

    return $steps;
}

function normalize_relative_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^\\./#', '', $path);
    $path = ltrim($path, '/');
    $path = normalize_path($path);
    $path = ltrim($path, '/');
    return trim($path, '/');
}

function normalize_path_allowlist(array $allowlist): array
{
    $normalized = [];
    foreach ($allowlist as $item) {
        $text = trim((string) $item);
        if ($text === '') {
            continue;
        }
        $path = normalize_relative_path($text);
        if ($path === '' || $path === '.') {
            continue;
        }
        $normalized[$path] = true;
    }
    return array_keys($normalized);
}

function is_path_allowed(string $path, array $allowlist): bool
{
    if (!$allowlist) {
        return true;
    }
    $normalized = normalize_relative_path($path);
    if ($normalized === '' || $normalized === '.') {
        return false;
    }
    foreach ($allowlist as $allowed) {
        if ($normalized === $allowed) {
            return true;
        }
        if (str_starts_with($normalized, $allowed . '/')) {
            return true;
        }
    }
    return false;
}

function normalize_command_allowlist(array $allowlist): array
{
    $normalized = [];
    foreach ($allowlist as $item) {
        $text = trim((string) $item);
        if ($text === '') {
            continue;
        }
        $normalized[$text] = true;
    }
    return array_keys($normalized);
}

function is_command_allowed(string $command, array $allowlist): bool
{
    if (!$allowlist) {
        return true;
    }
    $first = strtok(trim($command), " \t");
    if ($first === false || $first === '') {
        return false;
    }
    return in_array($first, $allowlist, true);
}

function extract_patch_files(string $patch): array
{
    $lines = preg_split('/\r\n|\r|\n/', $patch);
    if (!is_array($lines)) {
        return [];
    }

    $files = [];
    $i = 0;
    while ($i < count($lines)) {
        $line = $lines[$i];
        if (!str_starts_with($line, '--- ')) {
            $i++;
            continue;
        }
        $old_path = trim(substr($line, 4));
        $i++;
        if ($i >= count($lines) || !str_starts_with($lines[$i], '+++ ')) {
            break;
        }
        $new_path = trim(substr($lines[$i], 4));
        $i++;

        $target_path = $new_path === '/dev/null' ? $old_path : $new_path;
        $target_path = preg_replace('/\t.*$/', '', (string) $target_path);
        $target_path = preg_replace('/^a\\//', '', (string) $target_path);
        $target_path = preg_replace('/^b\\//', '', (string) $target_path);
        $target_path = trim((string) $target_path);
        if ($target_path === '' || $target_path === '/dev/null') {
            continue;
        }
        $files[$target_path] = true;
    }

    return array_keys($files);
}

function dispatch_tool(
    string $repo_root,
    string $name,
    array $args,
    array &$changed_files,
    array &$commands_run,
    ?callable $logger = null,
    array $file_allowlist = [],
    array $command_allowlist = []
): array {
    switch ($name) {
        case 'list_files':
            $path = (string) ($args['path'] ?? '.');
            $depth = (int) ($args['depth'] ?? 2);
            $max_entries = (int) ($args['max_entries'] ?? 200);
            if (!is_path_allowed($path, $file_allowlist)) {
                return ['error' => 'path not allowed by file allowlist'];
            }
            $entries = list_tree($repo_root, $path, $depth, $max_entries);
            if ($logger) {
                $logger('list_files: path=' . $path . ' depth=' . $depth . ' count=' . count($entries));
            }
            return ['entries' => $entries];
        case 'read_file':
            $path = (string) ($args['path'] ?? '');
            $max_bytes = (int) ($args['max_bytes'] ?? 200000);
            if (!is_path_allowed($path, $file_allowlist)) {
                return ['error' => 'path not allowed by file allowlist'];
            }
            $result = read_file_tool($repo_root, $path, $max_bytes);
            if ($logger) {
                if (!empty($result['error'])) {
                    $logger('read_file error: ' . $result['error'] . ' path=' . $path);
                } else {
                    $logger('read_file: path=' . $path . ' bytes=' . strlen((string) ($result['content'] ?? '')));
                }
            }
            return $result;
        case 'write_file':
            $path = (string) ($args['path'] ?? '');
            if (!is_path_allowed($path, $file_allowlist)) {
                return ['error' => 'path not allowed by file allowlist'];
            }
            $result = write_file_tool($repo_root, $path, (string) ($args['content'] ?? ''));
            if (empty($result['error'])) {
                $changed_files[$path] = true;
            }
            if ($logger) {
                if (!empty($result['error'])) {
                    $logger('write_file error: ' . $result['error'] . ' path=' . $path);
                } else {
                    $logger('write_file: path=' . $path . ' bytes=' . (int) ($result['bytes'] ?? 0));
                }
            }
            return $result;
        case 'mkdir':
            $path = (string) ($args['path'] ?? '');
            if (!is_path_allowed($path, $file_allowlist)) {
                return ['error' => 'path not allowed by file allowlist'];
            }
            $result = mkdir_tool($repo_root, $path);
            if ($logger) {
                $logger('mkdir: path=' . $path);
            }
            return $result;
        case 'apply_patch':
            $patch = (string) ($args['patch'] ?? '');
            $patch_files = extract_patch_files($patch);
            if (!$patch_files) {
                return ['error' => 'patch contains no files'];
            }
            foreach ($patch_files as $file) {
                if (!is_path_allowed($file, $file_allowlist)) {
                    return ['error' => 'path not allowed by file allowlist'];
                }
            }
            $result = apply_patch_tool($repo_root, $patch);
            if (!empty($result['files'])) {
                foreach ($result['files'] as $file) {
                    $changed_files[$file] = true;
                }
            }
            if ($logger) {
                if (!empty($result['error'])) {
                    $logger('apply_patch error: ' . $result['error']);
                } else {
                    $logger('apply_patch: files=' . implode(', ', (array) ($result['files'] ?? [])));
                }
            }
            return $result;
        case 'run_command':
            if (!is_command_allowed((string) ($args['command'] ?? ''), $command_allowlist)) {
                return ['error' => 'command not allowed by command allowlist'];
            }
            $result = run_command_tool(
                $repo_root,
                (string) ($args['command'] ?? ''),
                (string) ($args['cwd'] ?? '')
            );
            if (empty($result['error'])) {
                $commands_run[] = $result['command'] . ' (exit ' . $result['exit_code'] . ')';
            }
            if ($logger) {
                if (!empty($result['error'])) {
                    $logger('run_command error: ' . $result['error']);
                } else {
                    $logger('run_command: exit=' . (int) ($result['exit_code'] ?? 0) . ' cmd='
                        . truncate_text((string) ($result['command'] ?? ''), 200));
                }
            }
            return $result;
        default:
            return ['error' => 'unknown tool'];
    }
}

function run_llm_with_tools(
    array $messages,
    string $repo_root,
    array &$changed_files,
    array &$commands_run,
    callable $gate_check,
    ?callable $logger = null,
    array $file_allowlist = [],
    array $command_allowlist = [],
    string $model_override = ''
): array {
    $tools = tool_definitions();
    $max_iters = 10;
    $no_tool_attempts = 0;
    $file_allowlist = normalize_path_allowlist($file_allowlist);
    $command_allowlist = normalize_command_allowlist($command_allowlist);

    for ($i = 0; $i < $max_iters; $i++) {
        $gate_check();
        $response = call_openrouter_chat($messages, $tools, $model_override);
        $choice = $response['choices'][0]['message'] ?? [];
        $tool_calls = $choice['tool_calls'] ?? [];
        $content = (string) ($choice['content'] ?? '');

        if (!$tool_calls) {
            if ($logger) {
                $snippet = $content !== '' ? truncate_text($content, 500) : '(empty)';
                $logger('assistant: no tool calls, response=' . $snippet);
            }
            $no_tool_attempts++;
            if ($no_tool_attempts <= 2) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $content,
                ];
                $messages[] = [
                    'role' => 'user',
                    'content' => 'You must use tools to make changes. Respond with tool calls only.',
                ];
                if ($logger) {
                    $logger('assistant: retrying with tool-only instruction');
                }
                continue;
            }
            return ['content' => $content, 'messages' => $messages];
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => $tool_calls,
        ];

        foreach ($tool_calls as $call) {
            $tool_name = $call['function']['name'] ?? '';
            $args_json = $call['function']['arguments'] ?? '{}';
            $args = json_decode($args_json, true);
            if (!is_array($args)) {
                $args = [];
            }
            if ($logger) {
                $logger('tool call: ' . $tool_name . ' args=' . truncate_text(json_encode($args), 200));
            }
            $result = dispatch_tool(
                $repo_root,
                $tool_name,
                $args,
                $changed_files,
                $commands_run,
                $logger,
                $file_allowlist,
                $command_allowlist
            );
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $call['id'] ?? '',
                'content' => json_encode($result, JSON_UNESCAPED_SLASHES),
            ];
        }
    }

    return ['content' => '', 'messages' => $messages];
}

function process_job_ai(array $job, string $check_path): array
{
    $job_id = (int) $job['id'];
    $payload = json_decode($job['payload'], true) ?: [];
    $request = (string) ($payload['request'] ?? $job['request_text']);
    $meta = $payload['meta'] ?? [];

    if (!file_exists($check_path)) {
        throw new RuntimeException('Gate disabled');
    }
    $gate_content = trim((string) file_get_contents($check_path));
    if (strtolower($gate_content) !== 'true') {
        throw new RuntimeException('Gate disabled');
    }

    $repo_root = resolve_repo_root($meta['repo_url'] ?? '');
    append_log($job_id, 'worker: repo root ' . $repo_root);

    $repo_summary = build_repo_summary($repo_root);
    $tests_enabled = !empty($meta['run_tests']);
    $test_command = $tests_enabled ? detect_test_command($repo_root) : '';
    $settings = read_settings();
    $role_models = normalize_role_models($settings['models'] ?? []);
    $default_model = getenv('OPENROUTER_MODEL') ?: 'deepseek/deepseek-v3.2';
    $models = [
        'architect' => $role_models['architect'] !== '' ? $role_models['architect'] : $default_model,
        'orchestrator' => $role_models['orchestrator'] !== '' ? $role_models['orchestrator'] : $default_model,
        'dept_head' => $role_models['dept_head'] !== '' ? $role_models['dept_head'] : $default_model,
        'worker' => $role_models['worker'] !== '' ? $role_models['worker'] : $default_model,
        'test_fix' => $role_models['test_fix'] !== '' ? $role_models['test_fix'] : $default_model,
    ];

    $steps = list_steps($job_id);
    $architecture = null;
    $architecture_summary = '';
    $arch_path = architecture_path($job_id);

    if (!$steps || !file_exists($arch_path)) {
        append_log($job_id, 'architect: generating architecture');
        $arch_messages = architect_prompt($request, $repo_summary);
        $arch_response = call_openrouter_chat($arch_messages, [], $models['architect']);
        $arch_content = (string) ($arch_response['choices'][0]['message']['content'] ?? '');
        $architecture = extract_json($arch_content);
        if (!$architecture) {
            $architecture = [
                'overview' => 'Minimal architecture.',
                'components' => [],
                'data_flows' => [],
                'constraints' => [],
                'decisions' => [],
            ];
        }
        write_architecture($job_id, $architecture);
    }

    if (file_exists($arch_path)) {
        $decoded = json_decode((string) file_get_contents($arch_path), true);
        if (is_array($decoded)) {
            $architecture = $decoded;
        }
    }
    if (is_array($architecture)) {
        $architecture_summary = summarize_architecture($architecture);
    }

    if (!$steps) {
        $plan_messages = plan_prompt($request, $repo_summary, $architecture_summary);
        append_log($job_id, 'orchestrator: generating plan');
        $plan_response = call_openrouter_chat($plan_messages, [], $models['orchestrator']);
        $plan_content = (string) ($plan_response['choices'][0]['message']['content'] ?? '');
        $plan = extract_json($plan_content);
        $plan_steps = validate_plan($plan);
        if (!$plan_steps) {
            $plan_steps = [[
                'scope' => 'repo',
                'goal' => 'Implement the request.',
                'acceptance_criteria' => ['Requested changes are applied', 'No errors from tools'],
            ]];
        }
        write_plan($job_id, ['steps' => $plan_steps]);
        $created = create_steps($job_id, $plan_steps);
        append_log($job_id, 'orchestrator: created ' . $created . ' step(s)');
        $steps = list_steps($job_id);
    }

    $changed_files = [];
    $commands_run = [];
    $all_risks = [];
    $final_summary = '';
    $job_failed = false;

    $gate_check = function () use ($check_path) {
        if (!file_exists($check_path)) {
            throw new RuntimeException('Gate disabled');
        }
        $content = trim((string) file_get_contents($check_path));
        if (strtolower($content) !== 'true') {
            throw new RuntimeException('Gate disabled');
        }
    };

    while (true) {
        $step_row = claim_next_step($job_id);
        if (!$step_row) {
            break;
        }
        $step_id = (int) $step_row['id'];
        $criteria = json_decode((string) ($step_row['acceptance_criteria'] ?? '[]'), true);
        if (!is_array($criteria)) {
            $criteria = [];
        }
        $step_constraints = decode_json_list($step_row['constraints'] ?? '');
        $step_file_allowlist = decode_json_list($step_row['file_allowlist'] ?? '');
        $step_command_allowlist = decode_json_list($step_row['command_allowlist'] ?? '');
        $step_scope = trim((string) ($step_row['scope'] ?? 'repo'));
        if ($step_scope === '') {
            $step_scope = 'repo';
        }
        $step = [
            'goal' => (string) ($step_row['goal'] ?? ''),
            'acceptance_criteria' => $criteria,
            'scope' => $step_scope,
            'constraints' => $step_constraints,
            'file_allowlist' => $step_file_allowlist,
            'command_allowlist' => $step_command_allowlist,
        ];
        $step_logger = static function (string $line) use ($job_id, $step_id) {
            append_log($job_id, $line);
            append_step_log($job_id, $step_id, $line);
        };
        $step_logger('worker: step ' . $step_row['step_index'] . ' (id ' . $step_id . ') - ' . $step['goal']);

        $subtasks = list_subtasks($step_id);
        if (!$subtasks) {
            $step_logger('dept-head: generating subtasks');
            $dept_messages = dept_head_prompt($request, $step, $architecture_summary, $repo_summary);
            $dept_response = call_openrouter_chat($dept_messages, [], $models['dept_head']);
            $dept_content = (string) ($dept_response['choices'][0]['message']['content'] ?? '');
            $dept_payload = extract_json($dept_content);
            $subtask_defs = validate_subtasks($dept_payload, $step);
            $created_subtasks = create_subtasks($job_id, $step_id, $subtask_defs);
            $step_logger('dept-head: created ' . $created_subtasks . ' subtask(s)');
            $subtasks = list_subtasks($step_id);
        }

        $step_changed = [];
        $step_commands = [];
        $step_failed = false;
        $step_summary = '';
        $step_risks = [];
        $subtask_summaries = [];

        while (true) {
            $subtask_row = claim_next_subtask($step_id);
            if (!$subtask_row) {
                break;
            }
            $sub_id = (int) $subtask_row['id'];
            $sub_criteria = json_decode((string) ($subtask_row['acceptance_criteria'] ?? '[]'), true);
            if (!is_array($sub_criteria)) {
                $sub_criteria = [];
            }
            $sub_constraints = decode_json_list($subtask_row['constraints'] ?? '');
            $sub_file_allowlist = decode_json_list($subtask_row['file_allowlist'] ?? '');
            $sub_command_allowlist = decode_json_list($subtask_row['command_allowlist'] ?? '');
            $sub_scope = trim((string) ($subtask_row['scope'] ?? ''));
            if ($sub_scope === '') {
                $sub_scope = $step['scope'] ?? 'repo';
            }
            $subtask = [
                'title' => (string) ($subtask_row['title'] ?? ''),
                'instruction' => (string) ($subtask_row['instruction'] ?? ''),
                'acceptance_criteria' => $sub_criteria,
                'scope' => $sub_scope,
                'constraints' => $sub_constraints ?: ($step['constraints'] ?? []),
                'file_allowlist' => $sub_file_allowlist ?: ($step['file_allowlist'] ?? []),
                'command_allowlist' => $sub_command_allowlist ?: ($step['command_allowlist'] ?? []),
            ];

            $subtask_logger = static function (string $line) use ($job_id, $step_id, $sub_id) {
                append_log($job_id, $line);
                append_step_log($job_id, $step_id, $line);
                append_subtask_log($job_id, $step_id, $sub_id, $line);
            };
            $subtask_logger('worker: subtask ' . $subtask_row['subtask_index'] . ' (id ' . $sub_id . ') - ' . $subtask['title']);

            $sub_changed = [];
            $sub_commands = [];
            $messages = worker_prompt(
                $request,
                $step,
                $subtask,
                $repo_summary,
                $architecture_summary,
                $tests_enabled,
                $test_command
            );
            $effective_file_allowlist = $subtask['file_allowlist'] ?? [];
            $effective_command_allowlist = $subtask['command_allowlist'] ?? [];
            $result = run_llm_with_tools(
                $messages,
                $repo_root,
                $sub_changed,
                $sub_commands,
                $gate_check,
                $subtask_logger,
                $effective_file_allowlist,
                $effective_command_allowlist,
                $models['worker']
            );
            $final = extract_json($result['content']);
            $sub_status = (!empty($final['status']) && $final['status'] === 'failed') ? 'failed' : 'success';
            $sub_summary = '';
            if (!empty($final['summary']) && is_string($final['summary'])) {
                $sub_summary = trim($final['summary']);
                $final_summary = $sub_summary;
            }
            if (!empty($final['risks']) && is_array($final['risks'])) {
                $step_risks = array_merge($step_risks, $final['risks']);
            }

            foreach (array_keys($sub_changed) as $file) {
                $step_changed[$file] = true;
            }
            $step_commands = array_merge($step_commands, $sub_commands);

            $subtask_report = [
                'status' => $sub_status,
                'summary' => $sub_summary !== '' ? $sub_summary : 'Subtask completed.',
                'title' => $subtask['title'],
                'instruction' => $subtask['instruction'],
                'acceptance_criteria' => $subtask['acceptance_criteria'],
                'scope' => $subtask['scope'] ?? 'repo',
                'constraints' => $subtask['constraints'] ?? [],
                'file_allowlist' => $subtask['file_allowlist'] ?? [],
                'command_allowlist' => $subtask['command_allowlist'] ?? [],
                'changed_files' => array_values(array_keys($sub_changed)),
                'commands_run' => $sub_commands,
                'checks' => [
                    'install' => 'not_run',
                    'lint' => 'not_run',
                    'test' => 'not_run',
                    'build' => 'not_run',
                ],
                'risks' => !empty($final['risks']) && is_array($final['risks']) ? $final['risks'] : [],
            ];
            write_subtask_report($job_id, $step_id, $sub_id, $subtask_report);
            $sub_report_path = subtask_report_path($job_id, $step_id, $sub_id);
            mark_subtask_status(
                $sub_id,
                $sub_status === 'failed' ? 'failed' : 'done',
                $sub_status === 'failed' ? ($sub_summary !== '' ? $sub_summary : 'Subtask failed') : null,
                $sub_report_path
            );

            $subtask_summaries[] = [
                'id' => $sub_id,
                'status' => $sub_status,
                'title' => $subtask['title'],
                'summary' => $sub_summary,
            ];
            $subtask_logger('worker: subtask ' . $subtask_row['subtask_index'] . ' changed files +' . count($sub_changed));

            if ($sub_status === 'failed') {
                $step_failed = true;
                break;
            }
        }

        if ($step_failed) {
            $job_failed = true;
        }

        if ($step_failed && $step_summary === '') {
            $step_summary = 'Subtask failed.';
        }
        if (!$step_failed && $step_summary === '') {
            $step_summary = 'Step completed.';
        }

        $all_risks = array_merge($all_risks, $step_risks);
        $step_report = [
            'status' => $step_failed ? 'failed' : 'success',
            'summary' => $step_summary,
            'goal' => $step['goal'],
            'acceptance_criteria' => $step['acceptance_criteria'],
            'scope' => $step['scope'] ?? 'repo',
            'constraints' => $step['constraints'] ?? [],
            'file_allowlist' => $step['file_allowlist'] ?? [],
            'command_allowlist' => $step['command_allowlist'] ?? [],
            'changed_files' => array_values(array_keys($step_changed)),
            'commands_run' => $step_commands,
            'checks' => [
                'install' => 'not_run',
                'lint' => 'not_run',
                'test' => 'not_run',
                'build' => 'not_run',
            ],
            'risks' => array_values(array_unique($step_risks)),
            'subtasks' => $subtask_summaries,
        ];
        write_step_report($job_id, $step_id, $step_report);
        $step_report_path = step_report_path($job_id, $step_id);
        mark_step_status(
            $step_id,
            $step_failed ? 'failed' : 'done',
            $step_failed ? $step_summary : null,
            $step_report_path
        );

        $step_logger('worker: step ' . $step_row['step_index'] . ' changed files +' . count($step_changed));

        foreach (array_keys($step_changed) as $file) {
            $changed_files[$file] = true;
        }
        $commands_run = array_merge($commands_run, $step_commands);

        if ($step_failed) {
            break;
        }
    }

    $test_status = 'not_run';
    $report_status = $job_failed ? 'failed' : 'success';
    if ($tests_enabled && !$job_failed) {
        if ($test_command === '') {
            $test_status = 'not_configured';
        } else {
            $max_loops = (int) (getenv('AGENTOPS_WEB_MAX_FIX_LOOPS') ?: 2);
            if ($max_loops < 0) {
                $max_loops = 0;
            }
            $attempt = 0;
            while (true) {
                $gate_check();
                append_log($job_id, 'tests: running ' . $test_command);
                $result = run_command_tool($repo_root, $test_command, '');
                if (empty($result['error'])) {
                    $commands_run[] = $result['command'] . ' (exit ' . $result['exit_code'] . ')';
                }
                if (!empty($result['error'])) {
                    $test_status = 'error: ' . $result['error'];
                    $report_status = 'failed';
                    break;
                }
                if ((int) $result['exit_code'] === 0) {
                    $test_status = 'passed';
                    $report_status = 'success';
                    break;
                }

                $report_status = 'failed';
                $test_status = 'failed (exit ' . (int) $result['exit_code'] . ')';
                $output = trim((string) ($result['stdout'] ?? '') . "\n" . (string) ($result['stderr'] ?? ''));
                $snippet = truncate_text($output, 4000);
                append_log($job_id, 'tests: failed (exit ' . (int) $result['exit_code'] . ')');

                if ($attempt >= $max_loops) {
                    append_log($job_id, 'tests: max fix loops reached');
                    break;
                }

                $attempt++;
                append_log($job_id, 'tests: fix loop ' . $attempt);
                $fix_messages = test_fix_prompt($request, $repo_summary, $test_command, $snippet);
                $fix_result = run_llm_with_tools(
                    $fix_messages,
                    $repo_root,
                    $changed_files,
                    $commands_run,
                    $gate_check,
                    static function (string $line) use ($job_id) {
                        append_log($job_id, $line);
                    },
                    [],
                    [],
                    $models['test_fix']
                );
                $fix_final = extract_json($fix_result['content']);
                if (!empty($fix_final['summary']) && is_string($fix_final['summary'])) {
                    $final_summary = trim($fix_final['summary']);
                }
                if (!empty($fix_final['risks']) && is_array($fix_final['risks'])) {
                    $all_risks = array_merge($all_risks, $fix_final['risks']);
                }
            }
        }
    }

    if ($report_status === 'failed' && $final_summary === '') {
        $final_summary = $job_failed ? 'Step failed.' : 'Tests failed.';
    }

    $report = [
        'status' => $report_status,
        'summary' => $final_summary !== '' ? $final_summary : ('Completed ' . count($steps) . ' step(s).'),
        'changed_files' => array_values(array_keys($changed_files)),
        'commands_run' => $commands_run,
        'checks' => [
            'install' => 'not_run',
            'lint' => 'not_run',
            'test' => $test_status,
            'build' => 'not_run',
        ],
        'pr' => [
            'created' => false,
            'url' => '',
            'branch' => '',
        ],
        'risks' => array_values(array_unique($all_risks)),
    ];

    return $report;
}
