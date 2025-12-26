<?php

declare(strict_types=1);

require_once __DIR__ . '/agent.php';

function worker_allowed(string $check_path): bool
{
    if (!file_exists($check_path)) {
        return false;
    }

    $content = trim((string) file_get_contents($check_path));
    return strtolower($content) === 'true';
}

function run_worker_once(string $worker_id, int $delay_ms, string $check_path): void
{
    write_heartbeat($worker_id);
    if (!worker_allowed($check_path)) {
        return;
    }

    $job = claim_next_job($worker_id);
    if (!$job) {
        return;
    }

    try {
        $report = process_job_ai($job, $check_path);
        $final_status = ($report['status'] ?? '') === 'failed' ? 'failed' : 'done';
        $error_text = $final_status === 'failed' ? (string) ($report['summary'] ?? 'Job failed') : null;
        mark_job_status((int) $job['id'], $final_status, $error_text);
        append_log((int) $job['id'], $final_status === 'failed' ? 'job failed' : 'job done');
        write_report((int) $job['id'], $report);
    } catch (Throwable $e) {
        mark_job_status((int) $job['id'], 'failed', $e->getMessage());
        append_log((int) $job['id'], 'job failed: ' . $e->getMessage());
        write_report((int) $job['id'], [
            'status' => 'failed',
            'summary' => $e->getMessage(),
            'changed_files' => [],
            'commands_run' => [],
            'checks' => [
                'install' => 'not_run',
                'lint' => 'not_run',
                'test' => 'not_run',
                'build' => 'not_run',
            ],
            'pr' => [
                'created' => false,
                'url' => '',
                'branch' => '',
            ],
            'risks' => [],
        ]);
    }
}

function run_worker_loop(
    string $worker_id,
    int $poll_ms,
    int $delay_ms,
    string $check_path,
    bool $once
): void {
    while (true) {
        write_heartbeat($worker_id);
        if (!worker_allowed($check_path)) {
            return;
        }
        $job = claim_next_job($worker_id);

        if ($job) {
            try {
                $report = process_job_ai($job, $check_path);
                $final_status = ($report['status'] ?? '') === 'failed' ? 'failed' : 'done';
                $error_text = $final_status === 'failed' ? (string) ($report['summary'] ?? 'Job failed') : null;
                mark_job_status((int) $job['id'], $final_status, $error_text);
                append_log((int) $job['id'], $final_status === 'failed' ? 'job failed' : 'job done');
                write_report((int) $job['id'], $report);
            } catch (Throwable $e) {
                mark_job_status((int) $job['id'], 'failed', $e->getMessage());
                append_log((int) $job['id'], 'job failed: ' . $e->getMessage());
                write_report((int) $job['id'], [
                    'status' => 'failed',
                    'summary' => $e->getMessage(),
                    'changed_files' => [],
                    'commands_run' => [],
                    'checks' => [
                        'install' => 'not_run',
                        'lint' => 'not_run',
                        'test' => 'not_run',
                        'build' => 'not_run',
                    ],
                    'pr' => [
                        'created' => false,
                        'url' => '',
                        'branch' => '',
                    ],
                    'risks' => [],
                ]);
            }
        } else {
            if ($poll_ms > 0) {
                usleep($poll_ms * 1000);
            }
        }

        if ($once) {
            return;
        }
    }
}
