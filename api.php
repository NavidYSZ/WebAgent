<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/worker_runtime.php';

$action = $_GET['action'] ?? '';

function normalize_workorder_row(array $row): array
{
    $row['acceptance_criteria'] = decode_json_list($row['acceptance_criteria'] ?? '');
    $row['constraints'] = decode_json_list($row['constraints'] ?? '');
    $row['file_allowlist'] = decode_json_list($row['file_allowlist'] ?? '');
    $row['command_allowlist'] = decode_json_list($row['command_allowlist'] ?? '');
    return $row;
}

try {
    switch ($action) {
        case 'jobs':
            $limit = (int) ($_GET['limit'] ?? 50);
            $limit = max(1, min(200, $limit));
            json_response(['jobs' => list_jobs($limit)]);
            break;

        case 'repos':
            json_response(['repos' => list_workspace_repos()]);
            break;

        case 'settings':
            $settings = read_settings();
            $default_model = getenv('OPENROUTER_MODEL') ?: 'deepseek/deepseek-v3.2';
            $effective = [];
            foreach (($settings['models'] ?? []) as $role => $model) {
                $effective[$role] = $model !== '' ? $model : $default_model;
            }
            json_response([
                'settings' => $settings,
                'defaults' => ['model' => $default_model],
                'effective' => $effective,
            ]);
            break;

        case 'update_settings':
            $input = array_merge($_POST, read_json_body());
            $models = $input['models'] ?? null;
            if (!is_array($models)) {
                json_response(['error' => 'models is required'], 422);
                break;
            }
            $settings = ['models' => normalize_role_models($models)];
            write_settings($settings);
            $default_model = getenv('OPENROUTER_MODEL') ?: 'deepseek/deepseek-v3.2';
            $effective = [];
            foreach (($settings['models'] ?? []) as $role => $model) {
                $effective[$role] = $model !== '' ? $model : $default_model;
            }
            json_response([
                'settings' => $settings,
                'defaults' => ['model' => $default_model],
                'effective' => $effective,
            ]);
            break;

        case 'steps':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            if ($job_id <= 0) {
                json_response(['error' => 'job_id is required'], 422);
                break;
            }
            $steps = array_map('normalize_workorder_row', list_steps($job_id));
            json_response(['steps' => $steps]);
            break;

        case 'subtasks':
            $step_id = (int) ($_GET['step_id'] ?? 0);
            if ($step_id <= 0) {
                json_response(['error' => 'step_id is required'], 422);
                break;
            }
            $subtasks = array_map('normalize_workorder_row', list_subtasks($step_id));
            json_response(['subtasks' => $subtasks]);
            break;

        case 'check_status':
            $path = check_file_path();
            $exists = file_exists($path);
            $enabled = false;
            if ($exists) {
                $content = trim((string) file_get_contents($path));
                $enabled = strtolower($content) === 'true';
            }
            json_response([
                'enabled' => $enabled,
                'exists' => $exists,
            ]);
            break;

        case 'toggle_check':
            $input = array_merge($_POST, read_json_body());
            $enabled = $input['enabled'] ?? $input['state'] ?? null;
            if ($enabled === null) {
                json_response(['error' => 'enabled is required'], 422);
                break;
            }

            if (is_string($enabled)) {
                $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } else {
                $enabled = (bool) $enabled;
            }

            if ($enabled === null) {
                json_response(['error' => 'enabled must be true or false'], 422);
                break;
            }

            $path = check_file_path();
            ensure_dir(dirname($path));
            file_put_contents($path, $enabled ? "true\n" : "false\n");
            json_response(['enabled' => $enabled]);
            break;

        case 'create_job':
            $input = array_merge($_POST, read_json_body());
            $title = trim((string) ($input['title'] ?? 'Untitled Job'));
            $request = trim((string) ($input['request'] ?? ''));
            $repo_url = trim((string) ($input['repo_url'] ?? ''));
            $repo_name = trim((string) ($input['repo_name'] ?? ''));
            $run_tests = $input['run_tests'] ?? null;

            if ($request === '') {
                json_response(['error' => 'request is required'], 422);
                break;
            }

            if ($repo_url === '__new__') {
                $repo_url = '';
            }

            if ($repo_url === '' && $repo_name !== '') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $repo_name);
                $safe = trim((string) $safe, '-');
                if ($safe === '' || $safe === '.' || $safe === '..') {
                    json_response(['error' => 'invalid repo name'], 422);
                    break;
                }
                $path = WORKSPACES_DIR . '/' . $safe;
                if (file_exists($path)) {
                    json_response(['error' => 'repo already exists'], 409);
                    break;
                }
                ensure_dir($path);
                $repo_url = $path;
            }

            $meta = [];
            if ($repo_url !== '') {
                $meta['repo_url'] = $repo_url;
            }
            if ($run_tests !== null) {
                if (is_string($run_tests)) {
                    $parsed = filter_var($run_tests, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($parsed !== null) {
                        $meta['run_tests'] = $parsed;
                    }
                } else {
                    $meta['run_tests'] = (bool) $run_tests;
                }
            }

            $job = create_job($title, $request, $meta);
            json_response(['job' => $job]);

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $delay_ms = (int) (getenv('AGENTOPS_WEB_SIM_DELAY_MS') ?: 350);
            run_worker_once('web-trigger', $delay_ms, check_file_path());
            break;

        case 'job':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            if ($job_id <= 0) {
                json_response(['error' => 'job_id is required'], 422);
                break;
            }
            $job = get_job($job_id);
            if (!$job) {
                json_response(['error' => 'job not found'], 404);
                break;
            }
            json_response(['job' => $job]);
            break;

        case 'delete_job':
            $input = array_merge($_POST, read_json_body());
            $job_id = (int) ($input['job_id'] ?? $_GET['job_id'] ?? 0);
            if ($job_id <= 0) {
                json_response(['error' => 'job_id is required'], 422);
                break;
            }
            if (!delete_job($job_id)) {
                json_response(['error' => 'job not found'], 404);
                break;
            }
            json_response(['deleted' => true]);
            break;

        case 'log':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            $offset = (int) ($_GET['offset'] ?? 0);
            if ($job_id <= 0) {
                json_response(['error' => 'job_id is required'], 422);
                break;
            }
            $chunk = read_log_chunk($job_id, max(0, $offset));
            json_response($chunk);
            break;

        case 'step_log':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            $step_id = (int) ($_GET['step_id'] ?? 0);
            $offset = (int) ($_GET['offset'] ?? 0);
            if ($job_id <= 0 || $step_id <= 0) {
                json_response(['error' => 'job_id and step_id are required'], 422);
                break;
            }
            $chunk = read_step_log_chunk($job_id, $step_id, max(0, $offset));
            json_response($chunk);
            break;

        case 'subtask_log':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            $step_id = (int) ($_GET['step_id'] ?? 0);
            $subtask_id = (int) ($_GET['subtask_id'] ?? 0);
            $offset = (int) ($_GET['offset'] ?? 0);
            if ($job_id <= 0 || $step_id <= 0 || $subtask_id <= 0) {
                json_response(['error' => 'job_id, step_id, subtask_id are required'], 422);
                break;
            }
            $chunk = read_subtask_log_chunk($job_id, $step_id, $subtask_id, max(0, $offset));
            json_response($chunk);
            break;

        case 'report':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            if ($job_id <= 0) {
                json_response(['error' => 'job_id is required'], 422);
                break;
            }
            $path = report_path($job_id);
            if (!file_exists($path)) {
                json_response(['error' => 'report not found'], 404);
                break;
            }
            json_response(['report' => json_decode(file_get_contents($path), true)]);
            break;

        case 'download_report':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            if ($job_id <= 0) {
                json_response(['error' => 'job_id is required'], 422);
                break;
            }
            $bundle = build_job_report_bundle($job_id);
            if (!$bundle) {
                json_response(['error' => 'job not found'], 404);
                break;
            }
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="job_' . $job_id . '_report.json"');
            echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            break;

        case 'step_report':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            $step_id = (int) ($_GET['step_id'] ?? 0);
            if ($job_id <= 0 || $step_id <= 0) {
                json_response(['error' => 'job_id and step_id are required'], 422);
                break;
            }
            $path = step_report_path($job_id, $step_id);
            if (!file_exists($path)) {
                json_response(['error' => 'report not found'], 404);
                break;
            }
            json_response(['report' => json_decode(file_get_contents($path), true)]);
            break;

        case 'subtask_report':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            $step_id = (int) ($_GET['step_id'] ?? 0);
            $subtask_id = (int) ($_GET['subtask_id'] ?? 0);
            if ($job_id <= 0 || $step_id <= 0 || $subtask_id <= 0) {
                json_response(['error' => 'job_id, step_id, subtask_id are required'], 422);
                break;
            }
            $path = subtask_report_path($job_id, $step_id, $subtask_id);
            if (!file_exists($path)) {
                json_response(['error' => 'report not found'], 404);
                break;
            }
            json_response(['report' => json_decode(file_get_contents($path), true)]);
            break;

        case 'plan':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            if ($job_id <= 0) {
                json_response(['error' => 'job_id is required'], 422);
                break;
            }
            $path = plan_path($job_id);
            if (!file_exists($path)) {
                json_response(['error' => 'plan not found'], 404);
                break;
            }
            json_response(['plan' => json_decode(file_get_contents($path), true)]);
            break;

        case 'architecture':
            $job_id = (int) ($_GET['job_id'] ?? 0);
            if ($job_id <= 0) {
                json_response(['error' => 'job_id is required'], 422);
                break;
            }
            $path = architecture_path($job_id);
            if (!file_exists($path)) {
                json_response(['error' => 'architecture not found'], 404);
                break;
            }
            json_response(['architecture' => json_decode(file_get_contents($path), true)]);
            break;

        case 'worker_status':
            $heartbeat = read_heartbeat();
            if (!$heartbeat || empty($heartbeat['ts'])) {
                json_response(['status' => 'offline']);
                break;
            }
            $last_seen = strtotime($heartbeat['ts']);
            $age = $last_seen ? (time() - $last_seen) : 9999;
            $status = $age <= 5 ? 'online' : 'offline';
            json_response([
                'status' => $status,
                'worker_id' => $heartbeat['worker_id'] ?? 'unknown',
                'last_seen' => $heartbeat['ts'],
            ]);
            break;

        default:
            json_response(['error' => 'unknown action'], 400);
            break;
    }
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
