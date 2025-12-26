<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/worker_runtime.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "This endpoint is CLI-only. Run: php Web/v3/worker.php\n";
    exit;
}

$once = in_array('--once', $argv, true);
if (!$once) {
    echo "Worker loop disabled on shared hosting. Use: php Web/v3/worker.php --once\n";
    exit(0);
}

$worker_id = getenv('AGENTOPS_WEB_WORKER_ID') ?: ('worker-' . substr(md5((string) getmypid()), 0, 6));
$poll_ms = (int) (getenv('AGENTOPS_WEB_POLL_MS') ?: 700);
$delay_ms = (int) (getenv('AGENTOPS_WEB_SIM_DELAY_MS') ?: 350);
$check_path = check_file_path();

run_worker_loop($worker_id, $poll_ms, $delay_ms, $check_path, true);
