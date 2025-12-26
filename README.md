# AgentOps Web (PHP)

Minimal PHP web interface with a lightweight job queue, worker loop, and live logs.

## Goal
Provide a small, reliable control room that queues AI coding jobs, monitors
progress, and stores evidence (logs, plans, reports) for review.

## High-level architecture
- UI dashboard in `index.php` calling a JSON API in `api.php`.
- Worker loop in `worker.php` runs an AI pipeline and writes logs/reports.
- SQLite stores jobs/steps/subtasks metadata; filesystem stores logs/reports.
- OpenRouter LLM integration with safe tool calls (read/write/patch/run).

## Requirements
- PHP 8.1+ with SQLite (PDO)
- PHP curl extension for OpenRouter calls

## Run locally
1) Start the worker:

```bash
php Web/worker.php
```

2) Start a PHP dev server:

```bash
php -S 127.0.0.1:8080 -t Web
```

3) Open the UI:

```
http://127.0.0.1:8080/index.php
```

## Environment options
- `AGENTOPS_WEB_DATA_DIR`: Override data dir (default `Web/data`)
- `AGENTOPS_WEB_LOG_DIR`: Override log dir (default `Web/logs`)
- `AGENTOPS_WEB_REPORT_DIR`: Override report dir (default `Web/reports`)
- `AGENTOPS_WEB_DB`: Override SQLite path (default `Web/data/agentops.sqlite`)
- `AGENTOPS_WEB_CHECK_FILE`: Worker safety gate file (default `Web/check.txt`)
- `AGENTOPS_WEB_WORKSPACES_DIR`: Workspace root to scan for repos (default `Web/workspaces`)
- `AGENTOPS_WEB_ALLOWED_CMDS`: Comma-separated allowlist for `run_command`
- `AGENTOPS_WEB_TEST_CMD`: Override test command (e.g. `npm test`, `python -m pytest`)
- `AGENTOPS_WEB_MAX_FIX_LOOPS`: Max auto-fix attempts after failing tests (default 2)
- `OPENROUTER_API_KEY`: OpenRouter API key (required for AI)
- `OPENROUTER_MODEL`: OpenRouter model (default `deepseek/deepseek-v3.2`)
- `OPENROUTER_BASE_URL`: Override OpenRouter base URL (optional)
- `OPENROUTER_REFERER`: Optional OpenRouter referer header
- `OPENROUTER_TITLE`: Optional OpenRouter title header
- `AGENTOPS_WEB_WORKER_ID`: Set worker id shown in the UI
- `AGENTOPS_WEB_POLL_MS`: Worker polling interval in ms (default 700)
- `AGENTOPS_WEB_SIM_DELAY_MS`: Simulated step delay in ms (default 350)
- `AGENTOPS_WEB_PHP_BIN`: Override detected PHP binary for run_command (optional)

## Notes
- Jobs are processed immediately by the running worker loop (no cron needed).
- Logs stream in the UI by polling `Web/api.php`.
- Relative paths in env vars resolve against the `Web` directory.
- The UI includes a toggle to set `check.txt` to `true` or `false`.
- The UI includes a toggle to enable tests + auto-fix loops.
- Jobs can be deleted from the UI using the `x` button.
- Repo dropdown scans the workspace directory for folders.
- Default repo dropdown action creates a new repo folder from the provided name.
- AI execution uses OpenRouter and tool calls to inspect/edit files in the selected repo.
- Plan output is stored as `Web/reports/job_<id>_plan.json`.
- The UI shows plan and report JSON for the active job.
- Each plan step produces a report at `Web/reports/job_<id>_step_<step_id>.json`.
- Step logs are stored at `Web/logs/job_<job_id>_step_<step_id>.log` and shown in the UI.
- Architecture output is stored at `Web/reports/job_<id>_architecture.json`.
- Each subtask produces a report at `Web/reports/job_<id>_step_<step_id>_task_<subtask_id>.json`.
- Subtask logs are stored at `Web/logs/job_<job_id>_step_<step_id>_task_<subtask_id>.log`.
- Pipeline: architect -> orchestrator -> dept-head -> worker (sequential execution).
- The UI shows steps and subtasks with their logs and reports.
