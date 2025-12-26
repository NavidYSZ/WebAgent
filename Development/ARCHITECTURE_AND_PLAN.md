# AgentOps Web - Architecture and Implementation Plan

This document captures the project goal, current architecture, and a practical
implementation roadmap so other LLMs can continue without hidden context.

## Goal
Provide a small, reliable web control room that lets a human or orchestrator
queue AI coding jobs, observe progress, and collect evidence (logs, reports,
plans). The system must stay safe, auditable, and easy to run locally for
small to medium projects.

## Current System Summary
- UI: Single-page dashboard in `index.php` with job creation, repo selection,
  live logs, plan/report, steps and subtasks.
- API: `api.php` exposes JSON endpoints for jobs, logs, reports, repos, and
  worker status.
- Worker: `worker.php` runs a loop that claims queued jobs and executes the
  AI pipeline.
- Data: SQLite database for jobs/steps/subtasks; filesystem for logs/reports.
- LLM: OpenRouter integration with tool calls (list/read/write/apply_patch/run).
- Pipeline: architect -> orchestrator -> dept-head -> worker (sequential).

## Architecture (High Level)
1) UI -> API -> Queue
2) Worker claims job -> AI pipeline generates plan -> steps -> subtasks
3) Worker executes subtasks using tool calls in a repo workspace
4) Logs and reports are written to filesystem; SQLite stores metadata
5) Optional tests are run after step execution

### Main Components
- `index.php`: Frontend UI and polling logic.
- `api.php`: JSON API and request handling.
- `lib/worker_runtime.php`: Worker loop and job lifecycle.
- `lib/agent.php`: LLM prompts, planning, tool dispatch, pipeline orchestration.
- `lib/tools.php`: Safe repo access and command allowlist.
- `lib/queue.php`: SQLite persistence and log/report management.
- `lib/llm.php`: OpenRouter client.

## Data Model (SQLite)
- jobs: basic job metadata + status + timestamps
- job_steps: step goals, acceptance criteria, status, report path
- job_subtasks: subtask details, acceptance criteria, status, report path

## Artifacts and Paths
- Logs:
  - `logs/job_<id>.log`
  - `logs/job_<id>_step_<step_id>.log`
  - `logs/job_<id>_step_<step_id>_task_<subtask_id>.log`
- Reports:
  - `reports/job_<id>.json`
  - `reports/job_<id>_plan.json`
  - `reports/job_<id>_architecture.json`
  - `reports/job_<id>_step_<step_id>.json`
  - `reports/job_<id>_step_<step_id>_task_<subtask_id>.json`

## Safety and Constraints
- Gate file `check.txt` must be `true` to allow work.
- Repo paths are locked to `workspaces/`.
- Command execution uses a strict allowlist.
- Logs are trimmed to a max size.

## Configuration (Key Env Vars)
- `OPENROUTER_API_KEY`, `OPENROUTER_MODEL`
- `AGENTOPS_WEB_WORKSPACES_DIR`
- `AGENTOPS_WEB_DATA_DIR`, `AGENTOPS_WEB_LOG_DIR`, `AGENTOPS_WEB_REPORT_DIR`
- `AGENTOPS_WEB_ALLOWED_CMDS`
- `AGENTOPS_WEB_TEST_CMD`, `AGENTOPS_WEB_MAX_FIX_LOOPS`
- `AGENTOPS_WEB_POLL_MS`, `AGENTOPS_WEB_SIM_DELAY_MS`

## Implementation Roadmap (Prioritized)

### Phase 1 - Quality and Safety Gates (High Impact)
- Add WorkOrder metadata to jobs/steps/subtasks:
  - scope, constraints, file allowlist, command allowlist, acceptance criteria
- Enforce allowlists in tool dispatch (read/write/apply_patch/run_command).
- Promote tests from optional to a true gate when enabled.
- Add a basic contract validation hook (JSON schema/OpenAPI) per module.

### Phase 2 - Evidence and Auditability
- Store diff, test output, and digest per subtask in a stable artifact folder.
- Show evidence in UI (link to diff/log/digest).
- Add a clear "evidence ready" state for steps/subtasks.

### Phase 3 - Source of Truth Docs
- Add repo docs to reduce context loss:
  - `docs/module-registry.yaml`
  - `docs/modules/<module>.md`
  - `docs/contracts/*`
  - `docs/decisions/ADR-*.md`
- Update pipeline prompts to include these docs when available.

### Phase 4 - Optional Git Integration
- Run each job on a branch.
- Save diffs and allow PR creation later.
- Add cleanup for stale branches.

## Notes for Other LLMs
- Avoid large, risky edits. Keep changes small and visible.
- Never write outside the selected repo.
- Ensure any new enforcement still preserves backward compatibility.
- The goal is reliable, testable output with traceable evidence.
