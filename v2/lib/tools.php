<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $is_abs = str_starts_with($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    $normalized = implode('/', $parts);
    return $is_abs ? '/' . $normalized : $normalized;
}

function resolve_repo_root(?string $repo_hint): string
{
    $workspace_root = realpath(WORKSPACES_DIR);
    if ($workspace_root === false) {
        throw new RuntimeException('WORKSPACES_DIR not found');
    }

    if ($repo_hint && $repo_hint !== '') {
        $candidate = $repo_hint;
        if (!str_starts_with($candidate, '/') && preg_match('/^[A-Za-z]:[\\\/]/', $candidate) !== 1) {
            $candidate = $workspace_root . '/' . $candidate;
        }

        $candidate = realpath($candidate);
        if ($candidate === false || !is_dir($candidate)) {
            throw new RuntimeException('Repo path not found');
        }

        $workspace_root_norm = rtrim($workspace_root, '/');
        $candidate_norm = rtrim($candidate, '/');
        if ($candidate_norm !== $workspace_root_norm
            && !str_starts_with($candidate_norm . '/', $workspace_root_norm . '/')) {
            throw new RuntimeException('Repo path outside workspace');
        }

        return $candidate;
    }

    $repos = list_workspace_repos();
    if (count($repos) === 1) {
        return $repos[0]['path'];
    }

    throw new RuntimeException('No repo selected');
}

function safe_repo_path(string $repo_root, string $relative): string
{
    if ($relative === '') {
        return $repo_root;
    }
    if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:[\\\/]/', $relative) === 1) {
        throw new RuntimeException('Absolute paths are not allowed');
    }

    $joined = rtrim($repo_root, '/') . '/' . $relative;
    $normalized = normalize_path($joined);
    $root_norm = normalize_path($repo_root);

    if ($normalized !== $root_norm && !str_starts_with($normalized . '/', $root_norm . '/')) {
        throw new RuntimeException('Path escapes repo root');
    }

    return $normalized;
}

function list_tree(string $repo_root, string $path = '', int $depth = 2, int $max_entries = 200): array
{
    $path = safe_repo_path($repo_root, $path === '' ? '.' : $path);
    $ignore = ['.git', 'node_modules', 'vendor', '.venv', '__pycache__'];

    $results = [];
    $base_len = strlen(rtrim($repo_root, '/') . '/');

    $iter = function (string $dir, int $level) use (&$iter, &$results, $depth, $max_entries, $ignore, $base_len) {
        if ($level > $depth || count($results) >= $max_entries) {
            return;
        }
        $entries = scandir($dir);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (in_array($entry, $ignore, true)) {
                continue;
            }
            $full = $dir . '/' . $entry;
            $rel = substr($full, $base_len);
            if (is_dir($full)) {
                $results[] = $rel . '/';
                $iter($full, $level + 1);
            } else {
                $results[] = $rel;
            }
            if (count($results) >= $max_entries) {
                return;
            }
        }
    };

    if (is_dir($path)) {
        $iter($path, 1);
    }

    sort($results);
    return $results;
}

function read_file_tool(string $repo_root, string $path, int $max_bytes = 200000): array
{
    $target = safe_repo_path($repo_root, $path);
    if (!file_exists($target) || !is_file($target)) {
        return ['error' => 'file not found'];
    }
    $content = file_get_contents($target);
    if ($content === false) {
        return ['error' => 'failed to read file'];
    }
    if (strlen($content) > $max_bytes) {
        $content = substr($content, 0, $max_bytes);
    }
    return ['path' => $path, 'content' => $content];
}

function write_file_tool(string $repo_root, string $path, string $content): array
{
    $target = safe_repo_path($repo_root, $path);
    ensure_dir(dirname($target));
    $bytes = file_put_contents($target, $content);
    if ($bytes === false) {
        return ['error' => 'failed to write file'];
    }
    return ['path' => $path, 'bytes' => $bytes];
}

function mkdir_tool(string $repo_root, string $path): array
{
    $target = safe_repo_path($repo_root, $path);
    ensure_dir($target);
    return ['path' => $path, 'created' => true];
}

function apply_patch_tool(string $repo_root, string $patch): array
{
    $result = apply_unified_patch($repo_root, $patch);
    return $result;
}

function apply_unified_patch(string $repo_root, string $patch): array
{
    $lines = preg_split('/\r\n|\r|\n/', $patch);
    if (!is_array($lines)) {
        return ['error' => 'invalid patch'];
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
            return ['error' => 'invalid patch header'];
        }
        $new_path = trim(substr($lines[$i], 4));
        $i++;

        $target_path = $new_path;
        if ($target_path === '/dev/null') {
            $target_path = $old_path;
        }
        $target_path = preg_replace('/\t.*$/', '', $target_path);
        $target_path = preg_replace('/^a\//', '', $target_path);
        $target_path = preg_replace('/^b\//', '', $target_path);

        $is_delete = $new_path === '/dev/null';
        $is_create = $old_path === '/dev/null';

        $file_rel = $target_path;
        $file_abs = safe_repo_path($repo_root, $file_rel);
        $file_lines = [];
        $ends_with_newline = false;

        if (!$is_create && file_exists($file_abs)) {
            $content = file_get_contents($file_abs);
            if ($content === false) {
                return ['error' => 'failed to read file ' . $file_rel];
            }
            $ends_with_newline = str_ends_with($content, "\n");
            $file_lines = preg_split('/\r\n|\r|\n/', $content);
        }

        $output = [];
        $ptr = 0;

        while ($i < count($lines) && str_starts_with($lines[$i], '@@')) {
            $hunk_header = $lines[$i];
            if (!preg_match('/@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $hunk_header, $matches)) {
                return ['error' => 'invalid hunk header'];
            }
            $old_start = (int) $matches[1];
            $i++;

            $target_index = $old_start - 1;
            while ($ptr < $target_index && $ptr < count($file_lines)) {
                $output[] = $file_lines[$ptr];
                $ptr++;
            }

            while ($i < count($lines)) {
                $hunk_line = $lines[$i];
                if ($hunk_line === '' || $hunk_line[0] === ' ' || $hunk_line[0] === '-' || $hunk_line[0] === '+') {
                    if ($hunk_line === '') {
                        $prefix = ' ';
                        $text = '';
                    } else {
                        $prefix = $hunk_line[0];
                        $text = substr($hunk_line, 1);
                    }

                    if ($prefix === ' ') {
                        if (!isset($file_lines[$ptr]) || $file_lines[$ptr] !== $text) {
                            return ['error' => 'hunk context mismatch'];
                        }
                        $output[] = $text;
                        $ptr++;
                    } elseif ($prefix === '-') {
                        if (!isset($file_lines[$ptr]) || $file_lines[$ptr] !== $text) {
                            return ['error' => 'hunk removal mismatch'];
                        }
                        $ptr++;
                    } elseif ($prefix === '+') {
                        $output[] = $text;
                    }
                    $i++;
                    continue;
                }
                break;
            }
        }

        while ($ptr < count($file_lines)) {
            $output[] = $file_lines[$ptr];
            $ptr++;
        }

        if ($is_delete) {
            if (file_exists($file_abs)) {
                @unlink($file_abs);
            }
        } else {
            ensure_dir(dirname($file_abs));
            $final = implode("\n", $output);
            if ($ends_with_newline) {
                $final .= "\n";
            }
            file_put_contents($file_abs, $final);
        }

        $files[] = $file_rel;
    }

    return ['applied' => true, 'files' => $files];
}

function run_command_tool(string $repo_root, string $command, string $cwd = ''): array
{
    $allowed = getenv('AGENTOPS_WEB_ALLOWED_CMDS');
    $allowlist = $allowed ? array_map('trim', explode(',', $allowed)) : [
        'ls', 'rg', 'cat', 'sed', 'head', 'tail', 'wc', 'stat', 'php', 'composer',
        'npm', 'pnpm', 'yarn', 'pytest', 'python', 'python3', 'pip', 'pip3', 'make',
    ];

    $command = trim($command);
    if ($command === '') {
        return ['error' => 'command is empty'];
    }

    if (preg_match('/[;&|`<>]/', $command) === 1) {
        return ['error' => 'command contains forbidden characters'];
    }

    $first = strtok($command, " \t");
    if ($first === false || $first === '') {
        return ['error' => 'command is invalid'];
    }

    if (str_contains($first, '/') || str_contains($first, '\\') || str_starts_with($first, '.')) {
        return ['error' => 'command not allowed'];
    }

    if (!in_array($first, $allowlist, true)) {
        return ['error' => 'command not allowed'];
    }

    $resolved_command = $command;
    if ($first === 'php') {
        $php_bin = php_binary();
        if ($php_bin !== '' && $php_bin !== 'php') {
            $suffix = substr($command, strlen($first));
            $resolved_command = escapeshellarg($php_bin) . $suffix;
        }
    }

    $workdir = $repo_root;
    if ($cwd !== '') {
        $workdir = safe_repo_path($repo_root, $cwd);
    }

    $descriptor_spec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($resolved_command, $descriptor_spec, $pipes, $workdir);
    if (!is_resource($process)) {
        return ['error' => 'failed to start command'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    return [
        'command' => $resolved_command,
        'exit_code' => $exit_code,
        'stdout' => $stdout ?: '',
        'stderr' => $stderr ?: '',
    ];
}
