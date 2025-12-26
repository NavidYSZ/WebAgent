<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function create_job(string $title, string $request, array $meta = []): array
{
    $db = db();
    $now = now_iso();
    $payload = [
        'title' => $title,
        'request' => $request,
        'meta' => $meta,
    ];

    $stmt = $db->prepare(
        'INSERT INTO jobs (title, request_text, payload, status, created_at, last_log_at)
         VALUES (:title, :request_text, :payload, :status, :created_at, :last_log_at)'
    );
    $stmt->execute([
        ':title' => $title,
        ':request_text' => $request,
        ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ':status' => 'queued',
        ':created_at' => $now,
        ':last_log_at' => $now,
    ]);

    $job_id = (int) $db->lastInsertId();
    append_log($job_id, 'job queued');

    return get_job($job_id);
}

function get_job(int $job_id): ?array
{
    $db = db();
    $stmt = $db->prepare('SELECT * FROM jobs WHERE id = :id');
    $stmt->execute([':id' => $job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job ?: null;
}

function list_jobs(int $limit = 50): array
{
    $db = db();
    $stmt = $db->prepare('SELECT * FROM jobs ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_step(int $step_id): ?array
{
    $db = db();
    $stmt = $db->prepare('SELECT * FROM job_steps WHERE id = :id');
    $stmt->execute([':id' => $step_id]);
    $step = $stmt->fetch(PDO::FETCH_ASSOC);
    return $step ?: null;
}

function list_steps(int $job_id): array
{
    $db = db();
    $stmt = $db->prepare('SELECT * FROM job_steps WHERE job_id = :job_id ORDER BY step_index ASC');
    $stmt->execute([':job_id' => $job_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function create_steps(int $job_id, array $steps): int
{
    $db = db();
    $now = now_iso();
    $count = 0;

    $stmt = $db->prepare(
        'INSERT INTO job_steps (
            job_id,
            step_index,
            goal,
            acceptance_criteria,
            scope,
            constraints,
            file_allowlist,
            command_allowlist,
            status,
            created_at
        ) VALUES (
            :job_id,
            :step_index,
            :goal,
            :acceptance_criteria,
            :scope,
            :constraints,
            :file_allowlist,
            :command_allowlist,
            :status,
            :created_at
        )'
    );

    foreach (array_values($steps) as $index => $step) {
        $goal = trim((string) ($step['goal'] ?? ''));
        if ($goal === '') {
            continue;
        }
        $criteria = $step['acceptance_criteria'] ?? [];
        if (!is_array($criteria) || count($criteria) === 0) {
            $criteria = ['Changes applied', 'No errors'];
        }
        $scope = trim((string) ($step['scope'] ?? 'repo'));
        if ($scope === '') {
            $scope = 'repo';
        }
        $constraints = normalize_string_list($step['constraints'] ?? []);
        $file_allowlist = normalize_string_list($step['file_allowlist'] ?? []);
        $command_allowlist = normalize_string_list($step['command_allowlist'] ?? []);
        $stmt->execute([
            ':job_id' => $job_id,
            ':step_index' => $index + 1,
            ':goal' => $goal,
            ':acceptance_criteria' => json_encode(array_values($criteria), JSON_UNESCAPED_SLASHES),
            ':scope' => $scope,
            ':constraints' => json_encode($constraints, JSON_UNESCAPED_SLASHES),
            ':file_allowlist' => json_encode($file_allowlist, JSON_UNESCAPED_SLASHES),
            ':command_allowlist' => json_encode($command_allowlist, JSON_UNESCAPED_SLASHES),
            ':status' => 'queued',
            ':created_at' => $now,
        ]);
        $count++;
    }

    return $count;
}

function claim_next_step(int $job_id): ?array
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE;');

    $stmt = $db->prepare(
        'SELECT * FROM job_steps
         WHERE job_id = :job_id AND status = :status
         ORDER BY step_index ASC
         LIMIT 1'
    );
    $stmt->execute([
        ':job_id' => $job_id,
        ':status' => 'queued',
    ]);
    $step = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$step) {
        $db->exec('COMMIT;');
        return null;
    }

    $now = now_iso();
    $update = $db->prepare(
        'UPDATE job_steps
         SET status = :status, started_at = :started_at
         WHERE id = :id AND status = :current_status'
    );
    $update->execute([
        ':status' => 'running',
        ':started_at' => $now,
        ':id' => $step['id'],
        ':current_status' => 'queued',
    ]);

    $db->prepare(
        'UPDATE jobs
         SET status = :status, started_at = COALESCE(started_at, :started_at)
         WHERE id = :job_id AND status = :current_status'
    )->execute([
        ':status' => 'running',
        ':started_at' => $now,
        ':job_id' => $job_id,
        ':current_status' => 'queued',
    ]);

    $db->exec('COMMIT;');

    return get_step((int) $step['id']);
}

function mark_step_status(int $step_id, string $status, ?string $error_text = null, ?string $report_path = null): void
{
    $db = db();
    $payload = [
        ':status' => $status,
        ':finished_at' => in_array($status, ['done', 'failed'], true) ? now_iso() : null,
        ':error_text' => $error_text,
        ':report_path' => $report_path,
        ':id' => $step_id,
    ];

    $db->prepare(
        'UPDATE job_steps
         SET status = :status, finished_at = :finished_at, error_text = :error_text, report_path = :report_path
         WHERE id = :id'
    )->execute($payload);
}

function list_subtasks(int $step_id): array
{
    $db = db();
    $stmt = $db->prepare('SELECT * FROM job_subtasks WHERE step_id = :step_id ORDER BY subtask_index ASC');
    $stmt->execute([':step_id' => $step_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function list_subtasks_for_job(int $job_id): array
{
    $db = db();
    $stmt = $db->prepare('SELECT id, step_id FROM job_subtasks WHERE job_id = :job_id');
    $stmt->execute([':job_id' => $job_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function create_subtasks(int $job_id, int $step_id, array $subtasks): int
{
    $db = db();
    $now = now_iso();
    $count = 0;

    $stmt = $db->prepare(
        'INSERT INTO job_subtasks (
            job_id,
            step_id,
            subtask_index,
            title,
            instruction,
            acceptance_criteria,
            scope,
            constraints,
            file_allowlist,
            command_allowlist,
            status,
            created_at
        ) VALUES (
            :job_id,
            :step_id,
            :subtask_index,
            :title,
            :instruction,
            :acceptance_criteria,
            :scope,
            :constraints,
            :file_allowlist,
            :command_allowlist,
            :status,
            :created_at
        )'
    );

    foreach (array_values($subtasks) as $index => $subtask) {
        $title = trim((string) ($subtask['title'] ?? ''));
        $instruction = trim((string) ($subtask['instruction'] ?? ''));
        if ($title === '' || $instruction === '') {
            continue;
        }
        $criteria = $subtask['acceptance_criteria'] ?? [];
        if (!is_array($criteria) || count($criteria) === 0) {
            $criteria = ['Changes applied', 'No errors'];
        }
        $scope = trim((string) ($subtask['scope'] ?? 'repo'));
        if ($scope === '') {
            $scope = 'repo';
        }
        $constraints = normalize_string_list($subtask['constraints'] ?? []);
        $file_allowlist = normalize_string_list($subtask['file_allowlist'] ?? []);
        $command_allowlist = normalize_string_list($subtask['command_allowlist'] ?? []);
        $stmt->execute([
            ':job_id' => $job_id,
            ':step_id' => $step_id,
            ':subtask_index' => $index + 1,
            ':title' => $title,
            ':instruction' => $instruction,
            ':acceptance_criteria' => json_encode(array_values($criteria), JSON_UNESCAPED_SLASHES),
            ':scope' => $scope,
            ':constraints' => json_encode($constraints, JSON_UNESCAPED_SLASHES),
            ':file_allowlist' => json_encode($file_allowlist, JSON_UNESCAPED_SLASHES),
            ':command_allowlist' => json_encode($command_allowlist, JSON_UNESCAPED_SLASHES),
            ':status' => 'queued',
            ':created_at' => $now,
        ]);
        $count++;
    }

    return $count;
}

function claim_next_subtask(int $step_id): ?array
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE;');

    $stmt = $db->prepare(
        'SELECT * FROM job_subtasks
         WHERE step_id = :step_id AND status = :status
         ORDER BY subtask_index ASC
         LIMIT 1'
    );
    $stmt->execute([
        ':step_id' => $step_id,
        ':status' => 'queued',
    ]);
    $subtask = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$subtask) {
        $db->exec('COMMIT;');
        return null;
    }

    $now = now_iso();
    $update = $db->prepare(
        'UPDATE job_subtasks
         SET status = :status, started_at = :started_at
         WHERE id = :id AND status = :current_status'
    );
    $update->execute([
        ':status' => 'running',
        ':started_at' => $now,
        ':id' => $subtask['id'],
        ':current_status' => 'queued',
    ]);

    $db->exec('COMMIT;');

    return $subtask;
}

function mark_subtask_status(
    int $subtask_id,
    string $status,
    ?string $error_text = null,
    ?string $report_path = null
): void {
    $db = db();
    $payload = [
        ':status' => $status,
        ':finished_at' => in_array($status, ['done', 'failed'], true) ? now_iso() : null,
        ':error_text' => $error_text,
        ':report_path' => $report_path,
        ':id' => $subtask_id,
    ];

    $db->prepare(
        'UPDATE job_subtasks
         SET status = :status, finished_at = :finished_at, error_text = :error_text, report_path = :report_path
         WHERE id = :id'
    )->execute($payload);
}

function delete_job(int $job_id): bool
{
    $job = get_job($job_id);
    if (!$job) {
        return false;
    }

    $steps = list_steps($job_id);
    foreach ($steps as $step) {
        $step_id = (int) ($step['id'] ?? 0);
        if ($step_id > 0) {
            $step_path = step_report_path($job_id, $step_id);
            if (file_exists($step_path)) {
                @unlink($step_path);
            }
            $step_log = step_log_path($job_id, $step_id);
            if (file_exists($step_log)) {
                @unlink($step_log);
            }
        }
    }

    $subtasks = list_subtasks_for_job($job_id);
    foreach ($subtasks as $subtask) {
        $sub_id = (int) ($subtask['id'] ?? 0);
        $step_id = (int) ($subtask['step_id'] ?? 0);
        if ($sub_id > 0 && $step_id > 0) {
            $sub_report = subtask_report_path($job_id, $step_id, $sub_id);
            if (file_exists($sub_report)) {
                @unlink($sub_report);
            }
            $sub_log = subtask_log_path($job_id, $step_id, $sub_id);
            if (file_exists($sub_log)) {
                @unlink($sub_log);
            }
        }
    }

    $db = db();
    $db->prepare('DELETE FROM job_subtasks WHERE job_id = :job_id')->execute([':job_id' => $job_id]);
    $db->prepare('DELETE FROM job_steps WHERE job_id = :job_id')->execute([':job_id' => $job_id]);
    $stmt = $db->prepare('DELETE FROM jobs WHERE id = :id');
    $stmt->execute([':id' => $job_id]);

    $log_file = log_path($job_id);
    if (file_exists($log_file)) {
        @unlink($log_file);
    }

    $report_file = report_path($job_id);
    if (file_exists($report_file)) {
        @unlink($report_file);
    }

    $plan_file = plan_path($job_id);
    if (file_exists($plan_file)) {
        @unlink($plan_file);
    }

    $arch_file = architecture_path($job_id);
    if (file_exists($arch_file)) {
        @unlink($arch_file);
    }

    return true;
}

function claim_next_job(string $worker_id): ?array
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE;');

    $stmt = $db->prepare('SELECT * FROM jobs WHERE status = :status ORDER BY id ASC LIMIT 1');
    $stmt->execute([':status' => 'queued']);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $db->exec('COMMIT;');
        return null;
    }

    $now = now_iso();
    $update = $db->prepare(
        'UPDATE jobs
         SET status = :status, started_at = :started_at, last_log_at = :last_log_at
         WHERE id = :id AND status = :current_status'
    );
    $update->execute([
        ':status' => 'running',
        ':started_at' => $now,
        ':last_log_at' => $now,
        ':id' => $job['id'],
        ':current_status' => 'queued',
    ]);

    $db->exec('COMMIT;');

    append_log((int) $job['id'], 'worker ' . $worker_id . ' claimed job');
    return get_job((int) $job['id']);
}

function mark_job_status(int $job_id, string $status, ?string $error_text = null): void
{
    $db = db();
    $payload = [
        ':status' => $status,
        ':finished_at' => in_array($status, ['done', 'failed'], true) ? now_iso() : null,
        ':error_text' => $error_text,
        ':id' => $job_id,
    ];

    $db->prepare(
        'UPDATE jobs
         SET status = :status, finished_at = :finished_at, error_text = :error_text
         WHERE id = :id'
    )->execute($payload);
}

function update_last_log(int $job_id): void
{
    $db = db();
    $db->prepare('UPDATE jobs SET last_log_at = :last_log_at WHERE id = :id')
        ->execute([
            ':last_log_at' => now_iso(),
            ':id' => $job_id,
        ]);
}

function append_log(int $job_id, string $message): void
{
    $line = '[' . gmdate('H:i:s') . '] ' . $message . "\n";
    $path = log_path($job_id);
    $handle = fopen($path, 'ab');
    if ($handle === false) {
        return;
    }

    flock($handle, LOCK_EX);
    fwrite($handle, $line);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    trim_log($path);
    update_last_log($job_id);
}

function append_step_log(int $job_id, int $step_id, string $message): void
{
    $line = '[' . gmdate('H:i:s') . '] ' . $message . "\n";
    $path = step_log_path($job_id, $step_id);
    $handle = fopen($path, 'ab');
    if ($handle === false) {
        return;
    }

    flock($handle, LOCK_EX);
    fwrite($handle, $line);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    trim_log($path);
    update_last_log($job_id);
}

function append_subtask_log(int $job_id, int $step_id, int $subtask_id, string $message): void
{
    $line = '[' . gmdate('H:i:s') . '] ' . $message . "\n";
    $path = subtask_log_path($job_id, $step_id, $subtask_id);
    $handle = fopen($path, 'ab');
    if ($handle === false) {
        return;
    }

    flock($handle, LOCK_EX);
    fwrite($handle, $line);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    trim_log($path);
    update_last_log($job_id);
}

function trim_log(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $size = filesize($path);
    if ($size === false || $size <= MAX_LOG_BYTES) {
        return;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return;
    }

    fseek($handle, -MAX_LOG_BYTES, SEEK_END);
    $data = fread($handle, MAX_LOG_BYTES);
    fclose($handle);

    if ($data !== false) {
        file_put_contents($path, $data, LOCK_EX);
    }
}

function read_log_chunk(int $job_id, int $offset): array
{
    $path = log_path($job_id);
    if (!file_exists($path)) {
        return ['content' => '', 'offset' => 0, 'size' => 0];
    }

    $size = filesize($path);
    if ($size === false) {
        return ['content' => '', 'offset' => 0, 'size' => 0];
    }

    if ($offset > $size) {
        $offset = $size;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return ['content' => '', 'offset' => $offset, 'size' => $size];
    }

    fseek($handle, $offset);
    $content = stream_get_contents($handle);
    $new_offset = ftell($handle);
    fclose($handle);

    return [
        'content' => $content ?: '',
        'offset' => $new_offset ?: $offset,
        'size' => $size,
    ];
}

function read_step_log_chunk(int $job_id, int $step_id, int $offset): array
{
    $path = step_log_path($job_id, $step_id);
    if (!file_exists($path)) {
        return ['content' => '', 'offset' => 0, 'size' => 0];
    }

    $size = filesize($path);
    if ($size === false) {
        return ['content' => '', 'offset' => 0, 'size' => 0];
    }

    if ($offset > $size) {
        $offset = $size;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return ['content' => '', 'offset' => $offset, 'size' => $size];
    }

    fseek($handle, $offset);
    $content = stream_get_contents($handle);
    $new_offset = ftell($handle);
    fclose($handle);

    return [
        'content' => $content ?: '',
        'offset' => $new_offset ?: $offset,
        'size' => $size,
    ];
}

function read_subtask_log_chunk(int $job_id, int $step_id, int $subtask_id, int $offset): array
{
    $path = subtask_log_path($job_id, $step_id, $subtask_id);
    if (!file_exists($path)) {
        return ['content' => '', 'offset' => 0, 'size' => 0];
    }

    $size = filesize($path);
    if ($size === false) {
        return ['content' => '', 'offset' => 0, 'size' => 0];
    }

    if ($offset > $size) {
        $offset = $size;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return ['content' => '', 'offset' => $offset, 'size' => $size];
    }

    fseek($handle, $offset);
    $content = stream_get_contents($handle);
    $new_offset = ftell($handle);
    fclose($handle);

    return [
        'content' => $content ?: '',
        'offset' => $new_offset ?: $offset,
        'size' => $size,
    ];
}

function write_report(int $job_id, array $report): void
{
    $report['job_id'] = $job_id;
    $report['generated_at'] = now_iso();
    file_put_contents(
        report_path($job_id),
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function write_architecture(int $job_id, array $architecture): void
{
    $architecture['job_id'] = $job_id;
    $architecture['generated_at'] = now_iso();
    file_put_contents(
        architecture_path($job_id),
        json_encode($architecture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function write_step_report(int $job_id, int $step_id, array $report): void
{
    $report['job_id'] = $job_id;
    $report['step_id'] = $step_id;
    $report['generated_at'] = now_iso();
    file_put_contents(
        step_report_path($job_id, $step_id),
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function write_subtask_report(int $job_id, int $step_id, int $subtask_id, array $report): void
{
    $report['job_id'] = $job_id;
    $report['step_id'] = $step_id;
    $report['subtask_id'] = $subtask_id;
    $report['generated_at'] = now_iso();
    file_put_contents(
        subtask_report_path($job_id, $step_id, $subtask_id),
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function write_plan(int $job_id, array $plan): void
{
    $plan['job_id'] = $job_id;
    $plan['generated_at'] = now_iso();
    file_put_contents(
        plan_path($job_id),
        json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function read_json_file(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function read_log_contents(string $path): string
{
    if (!file_exists($path)) {
        return '';
    }

    $content = file_get_contents($path);
    return $content === false ? '' : $content;
}

function build_job_report_bundle(int $job_id): ?array
{
    $job = get_job($job_id);
    if (!$job) {
        return null;
    }

    $payload = json_decode((string) ($job['payload'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $plan = read_json_file(plan_path($job_id));
    $architecture = read_json_file(architecture_path($job_id));
    $report = read_json_file(report_path($job_id));

    $plan_steps = [];
    if (is_array($plan) && isset($plan['steps']) && is_array($plan['steps'])) {
        foreach ($plan['steps'] as $index => $step) {
            if (is_array($step)) {
                $plan_steps[$index + 1] = $step;
            }
        }
    }

    $steps = list_steps($job_id);
    $step_bundles = [];
    foreach ($steps as $step) {
        $step_id = (int) ($step['id'] ?? 0);
        $criteria = json_decode((string) ($step['acceptance_criteria'] ?? '[]'), true);
        if (!is_array($criteria)) {
            $criteria = [];
        }
        $constraints = decode_json_list($step['constraints'] ?? '');
        $file_allowlist = decode_json_list($step['file_allowlist'] ?? '');
        $command_allowlist = decode_json_list($step['command_allowlist'] ?? '');
        $scope = 'repo';
        if (isset($plan_steps[(int) ($step['step_index'] ?? 0)]['scope'])) {
            $scope = (string) $plan_steps[(int) ($step['step_index'] ?? 0)]['scope'];
        }
        if (!empty($step['scope'])) {
            $scope = (string) $step['scope'];
        }

        $step_report = $step_id > 0 ? read_json_file(step_report_path($job_id, $step_id)) : null;
        $step_log = $step_id > 0 ? read_log_contents(step_log_path($job_id, $step_id)) : '';

        $subtasks = $step_id > 0 ? list_subtasks($step_id) : [];
        $subtask_bundles = [];
        foreach ($subtasks as $subtask) {
            $sub_id = (int) ($subtask['id'] ?? 0);
            $sub_criteria = json_decode((string) ($subtask['acceptance_criteria'] ?? '[]'), true);
            if (!is_array($sub_criteria)) {
                $sub_criteria = [];
            }
            $sub_constraints = decode_json_list($subtask['constraints'] ?? '');
            $sub_file_allowlist = decode_json_list($subtask['file_allowlist'] ?? '');
            $sub_command_allowlist = decode_json_list($subtask['command_allowlist'] ?? '');
            $subtask_bundles[] = [
                'subtask' => $subtask,
                'acceptance_criteria' => $sub_criteria,
                'scope' => !empty($subtask['scope']) ? (string) $subtask['scope'] : $scope,
                'constraints' => $sub_constraints,
                'file_allowlist' => $sub_file_allowlist,
                'command_allowlist' => $sub_command_allowlist,
                'report' => ($sub_id > 0 && $step_id > 0)
                    ? read_json_file(subtask_report_path($job_id, $step_id, $sub_id))
                    : null,
                'log' => ($sub_id > 0 && $step_id > 0)
                    ? read_log_contents(subtask_log_path($job_id, $step_id, $sub_id))
                    : '',
            ];
        }

        $step_bundles[] = [
            'step' => $step,
            'scope' => $scope,
            'acceptance_criteria' => $criteria,
            'constraints' => $constraints,
            'file_allowlist' => $file_allowlist,
            'command_allowlist' => $command_allowlist,
            'report' => $step_report,
            'log' => $step_log,
            'subtasks' => $subtask_bundles,
        ];
    }

    return [
        'job' => $job,
        'payload' => $payload,
        'plan' => $plan,
        'architecture' => $architecture,
        'report' => $report,
        'logs' => [
            'job' => read_log_contents(log_path($job_id)),
        ],
        'steps' => $step_bundles,
    ];
}
