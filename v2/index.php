<?php

declare(strict_types=1);

?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>AgentOps Web</title>
    <script>
      window.tailwind = {
        config: {
          theme: {
            extend: {
              fontFamily: {
                display: ['"Space Grotesk"', 'ui-sans-serif', 'system-ui'],
              },
            },
          },
        },
      };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <style>
      body {
        font-family: "Space Grotesk", ui-sans-serif, system-ui;
        background:
          radial-gradient(900px 520px at 15% 10%, rgba(255, 205, 148, 0.55), transparent 60%),
          radial-gradient(820px 520px at 90% 0%, rgba(152, 216, 202, 0.45), transparent 60%),
          linear-gradient(180deg, #f8f5ef 0%, #efe7d8 100%);
      }
      .card {
        background: rgba(255, 255, 255, 0.86);
        border: 1px solid rgba(148, 163, 184, 0.3);
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
      }
      .float-in {
        animation: floatIn 0.6s ease both;
      }
      @keyframes floatIn {
        from {
          opacity: 0;
          transform: translateY(14px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }
    </style>
  </head>
  <body class="min-h-screen text-slate-900">
    <div class="max-w-6xl mx-auto px-6 py-8">
      <header class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.3em] text-slate-500">AgentOps</p>
          <h1 class="text-3xl md:text-4xl font-semibold">Web Control Room</h1>
          <p class="text-slate-600">Create a job. A PHP worker picks it up immediately.</p>
        </div>
        <div class="flex flex-col items-start gap-2 md:items-end">
          <div class="flex items-center gap-3">
            <span id="workerStatus" class="px-3 py-1 rounded-full text-xs font-semibold bg-slate-200 text-slate-600">
              worker offline
            </span>
            <span id="workerMeta" class="text-xs text-slate-500"></span>
          </div>
          <div class="flex items-center gap-2">
            <span id="checkStatus" class="px-3 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700">
              gate off
            </span>
            <button
              id="toggleCheck"
              class="text-xs font-semibold text-slate-600 hover:text-slate-900"
              type="button"
            >
              toggle gate
            </button>
            <span id="checkStatusMsg" class="text-xs text-slate-500"></span>
          </div>
          <div class="flex items-center gap-2">
            <span id="debugStatus" class="px-3 py-1 rounded-full text-xs font-semibold bg-slate-200 text-slate-600">
              debug off
            </span>
            <button
              id="toggleDebug"
              class="text-xs font-semibold text-slate-600 hover:text-slate-900"
              type="button"
            >
              toggle debug
            </button>
            <span id="debugStatusMsg" class="text-xs text-slate-500"></span>
          </div>
          <div class="flex items-center gap-2">
            <button
              id="openSettings"
              class="text-xs font-semibold text-slate-600 hover:text-slate-900"
              type="button"
            >
              Settings
            </button>
          </div>
        </div>
      </header>

      <div class="grid gap-6 lg:grid-cols-3 mt-6">
        <section class="card rounded-3xl p-6 float-in" style="animation-delay: 0.05s;">
          <h2 class="text-sm uppercase tracking-[0.25em] text-slate-500">New job</h2>
          <form id="jobForm" class="mt-5 space-y-4">
            <div>
              <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Title</label>
              <input
                id="jobTitle"
                class="mt-2 w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300"
                placeholder="e.g. Fix auth bug"
              />
            </div>
            <div>
              <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Neues Repo (Name)</label>
              <input
                id="jobRepoName"
                class="mt-2 w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300"
                placeholder="z. B. my-new-repo"
              />
            </div>
            <div>
              <div class="flex items-center justify-between">
                <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Repo (workspace)</label>
                <button
                  id="refreshRepos"
                  type="button"
                  class="text-xs font-semibold text-slate-600 hover:text-slate-900"
                >
                  Refresh
                </button>
              </div>
              <select
                id="jobRepo"
                class="mt-2 w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300"
              >
                <option value="">Loading repos...</option>
              </select>
            </div>
            <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white/80 px-4 py-3">
              <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Tests & Fix Loop</p>
                <p class="text-xs text-slate-500">Run tests and retry on failures.</p>
              </div>
              <label class="relative inline-flex cursor-pointer items-center">
                <input id="runTests" type="checkbox" class="peer sr-only" />
                <div
                  class="h-6 w-11 rounded-full bg-slate-200 transition peer-checked:bg-emerald-500"
                ></div>
                <span
                  class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow-sm transition peer-checked:translate-x-5"
                ></span>
              </label>
            </div>
            <div>
              <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Request</label>
              <textarea
                id="jobRequest"
                class="mt-2 w-full min-h-[140px] rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300"
                placeholder="Describe the task."
              ></textarea>
            </div>
            <button
              type="submit"
              class="w-full rounded-full bg-amber-500 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-amber-500/30 transition hover:-translate-y-0.5"
            >
              Queue job
            </button>
            <p id="formStatus" class="text-xs text-slate-500"></p>
          </form>
        </section>

        <section class="card rounded-3xl p-6 float-in lg:col-span-2" style="animation-delay: 0.12s;">
          <div class="flex flex-col gap-6">
            <div>
              <div class="flex items-center justify-between">
                <h2 class="text-sm uppercase tracking-[0.25em] text-slate-500">Jobs</h2>
                <button
                  id="refreshJobs"
                  class="text-xs font-semibold text-slate-600 hover:text-slate-900"
                >
                  Refresh
                </button>
              </div>
              <div id="jobsList" class="mt-4 space-y-2"></div>
            </div>

            <div class="border-t border-slate-200 pt-6">
              <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                  <h2 class="text-sm uppercase tracking-[0.25em] text-slate-500">Live log</h2>
                  <p id="activeJobLabel" class="text-xs text-slate-500">Select a job to view logs.</p>
                </div>
                <div class="flex items-center gap-2 text-xs text-slate-500">
                  <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                  Auto follow
                </div>
              </div>
              <pre id="logOutput" class="mt-4 max-h-[360px] overflow-auto rounded-2xl bg-slate-900 p-4 text-xs text-slate-100"></pre>
            </div>

            <div class="border-t border-slate-200 pt-6">
              <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                  <h2 class="text-sm uppercase tracking-[0.25em] text-slate-500">Plan & Report</h2>
                  <p class="text-xs text-slate-500">Latest JSON outputs for the active job.</p>
                </div>
                <div class="flex items-center gap-3">
                  <button
                    id="refreshPlan"
                    class="text-xs font-semibold text-slate-600 hover:text-slate-900"
                    type="button"
                  >
                    Refresh plan
                  </button>
                  <button
                    id="refreshReport"
                    class="text-xs font-semibold text-slate-600 hover:text-slate-900"
                    type="button"
                  >
                    Refresh report
                  </button>
                </div>
              </div>
              <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Plan</p>
                  <pre id="planOutput" class="mt-2 max-h-[300px] overflow-auto rounded-2xl bg-slate-900 p-4 text-xs text-slate-100"></pre>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Report</p>
                  <pre id="reportOutput" class="mt-2 max-h-[300px] overflow-auto rounded-2xl bg-slate-900 p-4 text-xs text-slate-100"></pre>
                </div>
              </div>
            </div>

            <div class="border-t border-slate-200 pt-6">
              <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                  <h2 class="text-sm uppercase tracking-[0.25em] text-slate-500">Steps</h2>
                  <p id="activeStepLabel" class="text-xs text-slate-500">Select a step to view its log.</p>
                </div>
                <div class="flex items-center gap-3">
                  <button
                    id="refreshSteps"
                    class="text-xs font-semibold text-slate-600 hover:text-slate-900"
                    type="button"
                  >
                    Refresh steps
                  </button>
                  <button
                    id="refreshStepReport"
                    class="text-xs font-semibold text-slate-600 hover:text-slate-900"
                    type="button"
                  >
                    Refresh step report
                  </button>
                </div>
              </div>
              <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Step list</p>
                  <div id="stepsList" class="mt-2 space-y-2"></div>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Step log</p>
                  <pre id="stepLogOutput" class="mt-2 max-h-[300px] overflow-auto rounded-2xl bg-slate-900 p-4 text-xs text-slate-100"></pre>
                </div>
              </div>
              <div class="mt-4">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Step report</p>
                <pre id="stepReportOutput" class="mt-2 max-h-[260px] overflow-auto rounded-2xl bg-slate-900 p-4 text-xs text-slate-100"></pre>
              </div>
              <div class="mt-6 border-t border-slate-200 pt-4">
                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                  <div>
                    <h3 class="text-xs uppercase tracking-[0.25em] text-slate-500">Subtasks</h3>
                    <p id="activeSubtaskLabel" class="text-xs text-slate-500">Select a subtask to view its log.</p>
                  </div>
                  <div class="flex items-center gap-3">
                    <button
                      id="refreshSubtasks"
                      class="text-xs font-semibold text-slate-600 hover:text-slate-900"
                      type="button"
                    >
                      Refresh subtasks
                    </button>
                    <button
                      id="refreshSubtaskReport"
                      class="text-xs font-semibold text-slate-600 hover:text-slate-900"
                      type="button"
                    >
                      Refresh subtask report
                    </button>
                  </div>
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                  <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Subtask list</p>
                    <div id="subtasksList" class="mt-2 space-y-2"></div>
                  </div>
                  <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Subtask log</p>
                    <pre id="subtaskLogOutput" class="mt-2 max-h-[260px] overflow-auto rounded-2xl bg-slate-900 p-4 text-xs text-slate-100"></pre>
                  </div>
                </div>
                <div class="mt-4">
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Subtask report</p>
                  <pre id="subtaskReportOutput" class="mt-2 max-h-[220px] overflow-auto rounded-2xl bg-slate-900 p-4 text-xs text-slate-100"></pre>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>

    <div
      id="settingsModal"
      class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 px-4"
      role="dialog"
      aria-modal="true"
    >
      <div class="card w-full max-w-xl rounded-3xl p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Settings</p>
            <h2 class="text-2xl font-semibold">Model selection</h2>
          </div>
          <button
            id="closeSettings"
            type="button"
            class="text-xs font-semibold text-slate-600 hover:text-slate-900"
          >
            Close
          </button>
        </div>
        <p class="mt-2 text-xs text-slate-500">
          Default model:
          <span id="settingsDefaultModel" class="font-semibold text-slate-700"></span>
        </p>
        <p id="settingsLockedNote" class="mt-1 text-xs text-amber-700"></p>
        <form id="settingsForm" class="mt-5 space-y-4">
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Architect</label>
            <input
              id="settingsModelArchitect"
              class="mt-2 w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-300"
              placeholder="gpt-5.1-codex-max (locked)"
            />
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Orchestrator</label>
            <input
              id="settingsModelOrchestrator"
              class="mt-2 w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-300"
              placeholder="gpt-5.1-codex-max (locked)"
            />
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Dept-head</label>
            <input
              id="settingsModelDeptHead"
              class="mt-2 w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-300"
              placeholder="gpt-5.1-codex-max (locked)"
            />
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Worker</label>
            <input
              id="settingsModelWorker"
              class="mt-2 w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-300"
              placeholder="gpt-5.1-codex-max (locked)"
            />
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Test fix</label>
            <input
              id="settingsModelTestFix"
              class="mt-2 w-full rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-300"
              placeholder="gpt-5.1-codex-max (locked)"
            />
          </div>
          <div class="flex items-center gap-3 pt-2">
            <button
              id="settingsSave"
              type="submit"
              class="rounded-full bg-emerald-500 px-4 py-2 text-xs font-semibold text-white shadow-lg shadow-emerald-500/30 transition hover:-translate-y-0.5"
            >
              Save settings
            </button>
            <button
              id="cancelSettings"
              type="button"
              class="text-xs font-semibold text-slate-600 hover:text-slate-900"
            >
              Cancel
            </button>
            <span id="settingsStatus" class="text-xs text-slate-500"></span>
          </div>
        </form>
      </div>
    </div>

    <script>
      const jobsList = document.getElementById('jobsList');
      const logOutput = document.getElementById('logOutput');
      const activeJobLabel = document.getElementById('activeJobLabel');
      const formStatus = document.getElementById('formStatus');
      const workerStatus = document.getElementById('workerStatus');
      const workerMeta = document.getElementById('workerMeta');
      const checkStatus = document.getElementById('checkStatus');
      const toggleCheck = document.getElementById('toggleCheck');
      const checkStatusMsg = document.getElementById('checkStatusMsg');
      const debugStatus = document.getElementById('debugStatus');
      const toggleDebug = document.getElementById('toggleDebug');
      const debugStatusMsg = document.getElementById('debugStatusMsg');
      const planOutput = document.getElementById('planOutput');
      const reportOutput = document.getElementById('reportOutput');
      const refreshPlanBtn = document.getElementById('refreshPlan');
      const refreshReportBtn = document.getElementById('refreshReport');
      const stepsList = document.getElementById('stepsList');
      const stepLogOutput = document.getElementById('stepLogOutput');
      const stepReportOutput = document.getElementById('stepReportOutput');
      const refreshStepsBtn = document.getElementById('refreshSteps');
      const refreshStepReportBtn = document.getElementById('refreshStepReport');
      const activeStepLabel = document.getElementById('activeStepLabel');
      const subtasksList = document.getElementById('subtasksList');
      const subtaskLogOutput = document.getElementById('subtaskLogOutput');
      const subtaskReportOutput = document.getElementById('subtaskReportOutput');
      const refreshSubtasksBtn = document.getElementById('refreshSubtasks');
      const refreshSubtaskReportBtn = document.getElementById('refreshSubtaskReport');
      const activeSubtaskLabel = document.getElementById('activeSubtaskLabel');
      const jobForm = document.getElementById('jobForm');
      const refreshJobsBtn = document.getElementById('refreshJobs');
      const repoSelect = document.getElementById('jobRepo');
      const repoNameInput = document.getElementById('jobRepoName');
      const refreshReposBtn = document.getElementById('refreshRepos');
      const runTestsToggle = document.getElementById('runTests');
      const settingsButton = document.getElementById('openSettings');
      const settingsModal = document.getElementById('settingsModal');
      const closeSettingsBtn = document.getElementById('closeSettings');
      const cancelSettingsBtn = document.getElementById('cancelSettings');
      const settingsForm = document.getElementById('settingsForm');
      const settingsStatus = document.getElementById('settingsStatus');
      const settingsDefaultModel = document.getElementById('settingsDefaultModel');
      const settingsLockedNote = document.getElementById('settingsLockedNote');
      const settingsSaveButton = document.getElementById('settingsSave');
      const settingsInputs = {
        architect: document.getElementById('settingsModelArchitect'),
        orchestrator: document.getElementById('settingsModelOrchestrator'),
        dept_head: document.getElementById('settingsModelDeptHead'),
        worker: document.getElementById('settingsModelWorker'),
        test_fix: document.getElementById('settingsModelTestFix'),
      };

      let activeJobId = null;
      let logOffset = 0;
      let checkEnabled = null;
      let debugEnabled = null;
      let pollingEnabled = false;
      let reposLoaded = false;
      let activeStepId = null;
      let stepLogOffset = 0;
      let activeSubtaskId = null;
      let subtaskLogOffset = 0;

      const statusStyles = {
        queued: 'bg-amber-100 text-amber-700',
        running: 'bg-sky-100 text-sky-700',
        done: 'bg-emerald-100 text-emerald-700',
        failed: 'bg-rose-100 text-rose-700',
      };

      async function fetchJson(url, options = {}) {
        const res = await fetch(url, options);
        return res.json();
      }

      function setSettingsOpen(open) {
        if (!settingsModal) {
          return;
        }
        settingsModal.classList.toggle('hidden', !open);
        settingsModal.classList.toggle('flex', open);
        if (!open && settingsStatus) {
          settingsStatus.textContent = '';
        }
      }

      async function loadSettings() {
        if (!settingsForm) {
          return;
        }
        const data = await fetchJson('api.php?action=settings');
        const models = (data.settings && data.settings.models) ? data.settings.models : {};
        const defaultModel = data.defaults ? data.defaults.model : '';
        const locked = Boolean(data.locked || (data.settings && data.settings.locked));
        if (settingsDefaultModel) {
          settingsDefaultModel.textContent = defaultModel || 'unset';
        }
        if (settingsLockedNote) {
          settingsLockedNote.textContent = locked ? 'Model selection is locked in v2.' : '';
        }
        Object.keys(settingsInputs).forEach((key) => {
          if (settingsInputs[key]) {
            settingsInputs[key].value = models[key] || '';
            settingsInputs[key].disabled = locked;
          }
        });
        if (settingsSaveButton) {
          settingsSaveButton.disabled = locked;
          settingsSaveButton.classList.toggle('opacity-60', locked);
          settingsSaveButton.classList.toggle('cursor-not-allowed', locked);
        }
      }

      async function saveSettings(event) {
        if (event) {
          event.preventDefault();
        }
        if (!settingsForm) {
          return;
        }
        if (settingsStatus) {
          settingsStatus.textContent = 'Saving...';
        }
        const payload = { models: {} };
        Object.keys(settingsInputs).forEach((key) => {
          const input = settingsInputs[key];
          payload.models[key] = input ? input.value.trim() : '';
        });
        const data = await fetchJson('api.php?action=update_settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        if (data.error) {
          if (settingsStatus) {
            settingsStatus.textContent = data.error;
          }
          return;
        }
        if (settingsStatus) {
          settingsStatus.textContent = 'Saved.';
          setTimeout(() => {
            if (settingsStatus.textContent === 'Saved.') {
              settingsStatus.textContent = '';
            }
          }, 1200);
        }
      }

      function renderJobs(jobs) {
        jobsList.innerHTML = '';
        if (!jobs.length) {
          jobsList.innerHTML = '<p class="text-sm text-slate-500">No jobs yet.</p>';
          return;
        }

        jobs.forEach((job) => {
          const card = document.createElement('div');
          const statusClass = statusStyles[job.status] || 'bg-slate-200 text-slate-600';
          card.className =
            'w-full rounded-2xl border border-slate-200 bg-white/70 px-4 py-3 text-left transition hover:-translate-y-0.5 hover:bg-white';
          card.setAttribute('role', 'button');
          card.tabIndex = 0;
          card.innerHTML = `
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-slate-900">#${job.id} ${job.title}</p>
                <p class="text-xs text-slate-500">${job.created_at}</p>
              </div>
              <div class="flex items-center gap-2">
                <span class="px-3 py-1 rounded-full text-xs font-semibold ${statusClass}">${job.status}</span>
                <button
                  type="button"
                  data-download="1"
                  title="Download report"
                  class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 hover:border-emerald-300 hover:text-emerald-600"
                >
                  <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"></path>
                    <path d="M14 2v5h5"></path>
                  </svg>
                </button>
                <button
                  type="button"
                  data-delete="1"
                  title="Delete job"
                  class="h-8 w-8 rounded-full border border-slate-200 text-xs font-semibold text-slate-500 hover:border-rose-300 hover:text-rose-600"
                >x</button>
              </div>
            </div>
          `;
          card.addEventListener('click', () => selectJob(job.id, job.title));
          const deleteBtn = card.querySelector('[data-delete]');
          if (deleteBtn) {
            deleteBtn.addEventListener('click', (event) => {
              event.stopPropagation();
              deleteJob(job.id);
            });
          }
          const downloadBtn = card.querySelector('[data-download]');
          if (downloadBtn) {
            downloadBtn.addEventListener('click', (event) => {
              event.stopPropagation();
              downloadReport(job.id);
            });
          }
          jobsList.appendChild(card);
        });
      }

      function renderRepos(repos) {
        repoSelect.innerHTML = '';
        const createOption = document.createElement('option');
        createOption.value = '__new__';
        createOption.textContent = 'Neues Repo Erstellen';
        repoSelect.appendChild(createOption);
        if (!repos.length) {
          return;
        }
        repos.forEach((repo) => {
          const option = document.createElement('option');
          option.value = repo.path;
          option.textContent = repo.name;
          repoSelect.appendChild(option);
        });
        repoSelect.value = '__new__';
      }

      async function refreshJobs() {
        if (!pollingEnabled) {
          return;
        }
        const data = await fetchJson('api.php?action=jobs');
        renderJobs(data.jobs || []);
      }

      async function refreshRepos() {
        if (!pollingEnabled) {
          return;
        }
        const data = await fetchJson('api.php?action=repos');
        renderRepos(data.repos || []);
        reposLoaded = true;
      }

      function selectJob(jobId, title) {
        activeJobId = jobId;
        logOffset = 0;
        logOutput.textContent = '';
        planOutput.textContent = '';
        reportOutput.textContent = '';
        stepsList.innerHTML = '';
        stepLogOutput.textContent = '';
        stepReportOutput.textContent = '';
        activeStepLabel.textContent = 'Select a step to view its log.';
        activeStepId = null;
        stepLogOffset = 0;
        subtasksList.innerHTML = '';
        subtaskLogOutput.textContent = '';
        subtaskReportOutput.textContent = '';
        activeSubtaskLabel.textContent = 'Select a subtask to view its log.';
        activeSubtaskId = null;
        subtaskLogOffset = 0;
        activeJobLabel.textContent = `Active job: #${jobId} ${title}`;
        fetchLog(true);
        fetchPlan();
        fetchReport();
        refreshSteps();
      }

      async function deleteJob(jobId) {
        const data = await fetchJson('api.php?action=delete_job', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ job_id: jobId }),
        });
        if (data.error) {
          alert(data.error);
          return;
        }
        if (activeJobId === jobId) {
          activeJobId = null;
          logOffset = 0;
          logOutput.textContent = '';
          activeJobLabel.textContent = 'Select a job to view logs.';
        }
        refreshJobs();
      }

      function downloadReport(jobId) {
        const url = `api.php?action=download_report&job_id=${encodeURIComponent(jobId)}`;
        window.location = url;
      }

      async function fetchLog(force) {
        if (!activeJobId) {
          return;
        }
        if (!pollingEnabled) {
          return;
        }
        const data = await fetchJson(`api.php?action=log&job_id=${activeJobId}&offset=${logOffset}`);
        if (force && data.content) {
          logOutput.textContent = data.content;
        } else if (data.content) {
          logOutput.textContent += data.content;
        }
        if (data.offset !== undefined) {
          logOffset = data.offset;
        }
        logOutput.scrollTop = logOutput.scrollHeight;
      }

      async function fetchStepLog(force) {
        if (!activeJobId || !activeStepId || !pollingEnabled) {
          return;
        }
        const data = await fetchJson(
          `api.php?action=step_log&job_id=${activeJobId}&step_id=${activeStepId}&offset=${stepLogOffset}`
        );
        if (force && data.content) {
          stepLogOutput.textContent = data.content;
        } else if (data.content) {
          stepLogOutput.textContent += data.content;
        }
        if (data.offset !== undefined) {
          stepLogOffset = data.offset;
        }
        stepLogOutput.scrollTop = stepLogOutput.scrollHeight;
      }

      async function fetchSubtaskLog(force) {
        if (!activeJobId || !activeStepId || !activeSubtaskId || !pollingEnabled) {
          return;
        }
        const data = await fetchJson(
          `api.php?action=subtask_log&job_id=${activeJobId}&step_id=${activeStepId}&subtask_id=${activeSubtaskId}&offset=${subtaskLogOffset}`
        );
        if (force && data.content) {
          subtaskLogOutput.textContent = data.content;
        } else if (data.content) {
          subtaskLogOutput.textContent += data.content;
        }
        if (data.offset !== undefined) {
          subtaskLogOffset = data.offset;
        }
        subtaskLogOutput.scrollTop = subtaskLogOutput.scrollHeight;
      }

      async function refreshWorkerStatus() {
        if (!pollingEnabled) {
          return;
        }
        const data = await fetchJson('api.php?action=worker_status');
        if (data.status === 'online') {
          workerStatus.textContent = 'worker online';
          workerStatus.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700';
          workerMeta.textContent = `${data.worker_id} | ${data.last_seen}`;
        } else {
          workerStatus.textContent = 'worker offline';
          workerStatus.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-slate-200 text-slate-600';
          workerMeta.textContent = '';
        }
      }

      function renderSteps(steps) {
        stepsList.innerHTML = '';
        if (!steps.length) {
          stepsList.innerHTML = '<p class=\"text-sm text-slate-500\">No steps yet.</p>';
          return;
        }
        steps.forEach((step) => {
          const statusClass = statusStyles[step.status] || 'bg-slate-200 text-slate-600';
          const card = document.createElement('button');
          card.className =
            'w-full rounded-2xl border border-slate-200 bg-white/70 px-4 py-3 text-left transition hover:-translate-y-0.5 hover:bg-white';
          const errorText = step.error_text ? ` — ${step.error_text}` : '';
          card.innerHTML = `
            <div class=\"flex items-center justify-between gap-3\">
              <div>
                <p class=\"text-sm font-semibold text-slate-900\">Step ${step.step_index}: ${step.goal}</p>
                <p class=\"text-xs text-slate-500\">${step.status}${errorText}</p>
              </div>
              <span class=\"px-3 py-1 rounded-full text-xs font-semibold ${statusClass}\">${step.status}</span>
            </div>
          `;
          card.addEventListener('click', () => selectStep(step));
          stepsList.appendChild(card);
        });
      }

      function renderSubtasks(subtasks) {
        subtasksList.innerHTML = '';
        if (!subtasks.length) {
          subtasksList.innerHTML = '<p class="text-sm text-slate-500">No subtasks yet.</p>';
          return;
        }
        subtasks.forEach((subtask) => {
          const statusClass = statusStyles[subtask.status] || 'bg-slate-200 text-slate-600';
          const card = document.createElement('button');
          card.className =
            'w-full rounded-2xl border border-slate-200 bg-white/70 px-4 py-3 text-left transition hover:-translate-y-0.5 hover:bg-white';
          const errorText = subtask.error_text ? ` — ${subtask.error_text}` : '';
          card.innerHTML = `
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-slate-900">Subtask ${subtask.subtask_index}: ${subtask.title}</p>
                <p class="text-xs text-slate-500">${subtask.status}${errorText}</p>
              </div>
              <span class="px-3 py-1 rounded-full text-xs font-semibold ${statusClass}">${subtask.status}</span>
            </div>
          `;
          card.addEventListener('click', () => selectSubtask(subtask));
          subtasksList.appendChild(card);
        });
      }

      async function refreshSteps() {
        if (!pollingEnabled || !activeJobId) {
          return;
        }
        const data = await fetchJson(`api.php?action=steps&job_id=${activeJobId}`);
        renderSteps(data.steps || []);
      }

      async function refreshSubtasks() {
        if (!pollingEnabled || !activeStepId) {
          return;
        }
        const data = await fetchJson(`api.php?action=subtasks&step_id=${activeStepId}`);
        renderSubtasks(data.subtasks || []);
      }

      function selectStep(step) {
        activeStepId = step.id;
        stepLogOffset = 0;
        stepLogOutput.textContent = '';
        stepReportOutput.textContent = '';
        activeStepLabel.textContent = `Active step: ${step.step_index} (${step.status})`;
        fetchStepLog(true);
        fetchStepReport();
        activeSubtaskId = null;
        subtaskLogOffset = 0;
        subtaskLogOutput.textContent = '';
        subtaskReportOutput.textContent = '';
        activeSubtaskLabel.textContent = 'Select a subtask to view its log.';
        refreshSubtasks();
      }

      function selectSubtask(subtask) {
        activeSubtaskId = subtask.id;
        subtaskLogOffset = 0;
        subtaskLogOutput.textContent = '';
        subtaskReportOutput.textContent = '';
        activeSubtaskLabel.textContent = `Active subtask: ${subtask.subtask_index} (${subtask.status})`;
        fetchSubtaskLog(true);
        fetchSubtaskReport();
      }

      async function fetchStepReport() {
        if (!activeJobId || !activeStepId || !pollingEnabled) {
          return;
        }
        const data = await fetchJson(
          `api.php?action=step_report&job_id=${activeJobId}&step_id=${activeStepId}`
        );
        if (data.report) {
          stepReportOutput.textContent = JSON.stringify(data.report, null, 2);
        } else if (data.error) {
          stepReportOutput.textContent = data.error;
        }
      }

      async function fetchSubtaskReport() {
        if (!activeJobId || !activeStepId || !activeSubtaskId || !pollingEnabled) {
          return;
        }
        const data = await fetchJson(
          `api.php?action=subtask_report&job_id=${activeJobId}&step_id=${activeStepId}&subtask_id=${activeSubtaskId}`
        );
        if (data.report) {
          subtaskReportOutput.textContent = JSON.stringify(data.report, null, 2);
        } else if (data.error) {
          subtaskReportOutput.textContent = data.error;
        }
      }

      async function fetchPlan() {
        if (!activeJobId || !pollingEnabled) {
          return;
        }
        const data = await fetchJson(`api.php?action=plan&job_id=${activeJobId}`);
        if (data.plan) {
          planOutput.textContent = JSON.stringify(data.plan, null, 2);
        } else if (data.error) {
          planOutput.textContent = data.error;
        }
      }

      async function fetchReport() {
        if (!activeJobId || !pollingEnabled) {
          return;
        }
        const data = await fetchJson(`api.php?action=report&job_id=${activeJobId}`);
        if (data.report) {
          reportOutput.textContent = JSON.stringify(data.report, null, 2);
        } else if (data.error) {
          reportOutput.textContent = data.error;
        }
      }

      function setPollingEnabled(enabled) {
        pollingEnabled = enabled;
        if (!jobForm || !refreshJobsBtn) {
          return;
        }
        const inputs = jobForm.querySelectorAll('input, textarea, button, select');
        inputs.forEach((el) => {
          el.disabled = !enabled;
          el.classList.toggle('opacity-60', !enabled);
          el.classList.toggle('cursor-not-allowed', !enabled);
        });
        refreshJobsBtn.disabled = !enabled;
        refreshJobsBtn.classList.toggle('opacity-60', !enabled);
        refreshJobsBtn.classList.toggle('cursor-not-allowed', !enabled);
        if (refreshReposBtn) {
          refreshReposBtn.disabled = !enabled;
          refreshReposBtn.classList.toggle('opacity-60', !enabled);
          refreshReposBtn.classList.toggle('cursor-not-allowed', !enabled);
        }
        if (refreshPlanBtn) {
          refreshPlanBtn.disabled = !enabled;
          refreshPlanBtn.classList.toggle('opacity-60', !enabled);
          refreshPlanBtn.classList.toggle('cursor-not-allowed', !enabled);
        }
        if (refreshReportBtn) {
          refreshReportBtn.disabled = !enabled;
          refreshReportBtn.classList.toggle('opacity-60', !enabled);
          refreshReportBtn.classList.toggle('cursor-not-allowed', !enabled);
        }
        if (refreshStepsBtn) {
          refreshStepsBtn.disabled = !enabled;
          refreshStepsBtn.classList.toggle('opacity-60', !enabled);
          refreshStepsBtn.classList.toggle('cursor-not-allowed', !enabled);
        }
        if (refreshStepReportBtn) {
          refreshStepReportBtn.disabled = !enabled;
          refreshStepReportBtn.classList.toggle('opacity-60', !enabled);
          refreshStepReportBtn.classList.toggle('cursor-not-allowed', !enabled);
        }
        if (refreshSubtasksBtn) {
          refreshSubtasksBtn.disabled = !enabled;
          refreshSubtasksBtn.classList.toggle('opacity-60', !enabled);
          refreshSubtasksBtn.classList.toggle('cursor-not-allowed', !enabled);
        }
        if (refreshSubtaskReportBtn) {
          refreshSubtaskReportBtn.disabled = !enabled;
          refreshSubtaskReportBtn.classList.toggle('opacity-60', !enabled);
          refreshSubtaskReportBtn.classList.toggle('cursor-not-allowed', !enabled);
        }
        if (enabled && repoSelect) {
          repoSelect.dispatchEvent(new Event('change'));
        }
      }

      function setCheckStatus(enabled, exists) {
        checkEnabled = enabled;
        setPollingEnabled(enabled);
        if (enabled && !reposLoaded) {
          refreshRepos();
        }
        if (enabled) {
          checkStatus.textContent = 'gate on';
          checkStatus.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700';
          checkStatusMsg.textContent = '';
        } else {
          checkStatus.textContent = 'gate off';
          checkStatus.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700';
          checkStatusMsg.textContent = 'polling paused';
        }
        if (exists === false) {
          checkStatusMsg.textContent = 'check.txt missing';
        }
      }

      function setDebugStatus(enabled) {
        debugEnabled = enabled;
        if (enabled) {
          debugStatus.textContent = 'debug on';
          debugStatus.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700';
          debugStatusMsg.textContent = '';
        } else {
          debugStatus.textContent = 'debug off';
          debugStatus.className = 'px-3 py-1 rounded-full text-xs font-semibold bg-slate-200 text-slate-600';
          debugStatusMsg.textContent = '';
        }
      }

      async function refreshCheckStatus() {
        const data = await fetchJson('api.php?action=check_status');
        if (typeof data.enabled === 'boolean') {
          checkStatusMsg.textContent = '';
          setCheckStatus(data.enabled, data.exists);
        }
      }

      async function refreshDebugStatus() {
        const data = await fetchJson('api.php?action=debug_status');
        if (typeof data.enabled === 'boolean') {
          setDebugStatus(data.enabled);
        }
      }

      document.getElementById('jobForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        formStatus.textContent = 'Queueing job...';
        const creatingRepo = repoSelect && repoSelect.value === '__new__';
        const repoName = repoNameInput ? repoNameInput.value.trim() : '';
        if (creatingRepo && !repoName) {
          formStatus.textContent = 'Repo name is required.';
          return;
        }
        const payload = {
          title: document.getElementById('jobTitle').value.trim() || 'Untitled Job',
          repo_url: creatingRepo ? '' : (repoSelect ? repoSelect.value.trim() : ''),
          repo_name: creatingRepo ? repoName : '',
          run_tests: runTestsToggle ? runTestsToggle.checked : false,
          request: document.getElementById('jobRequest').value.trim(),
        };
        const data = await fetchJson('api.php?action=create_job', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });

        if (data.error) {
          formStatus.textContent = data.error;
          return;
        }

        formStatus.textContent = 'Job queued.';
        document.getElementById('jobRequest').value = '';
        refreshJobs();
        if (data.job) {
          selectJob(data.job.id, data.job.title);
        }
      });

      document.getElementById('refreshJobs').addEventListener('click', refreshJobs);
      if (refreshReposBtn) {
        refreshReposBtn.addEventListener('click', refreshRepos);
      }
      if (repoSelect && repoNameInput) {
        repoSelect.addEventListener('change', () => {
          if (repoSelect.value === '__new__') {
            repoNameInput.disabled = false;
            repoNameInput.classList.remove('opacity-60', 'cursor-not-allowed');
          } else {
            repoNameInput.disabled = true;
            repoNameInput.value = '';
            repoNameInput.classList.add('opacity-60', 'cursor-not-allowed');
          }
        });
        repoSelect.dispatchEvent(new Event('change'));
      }
      if (refreshPlanBtn) {
        refreshPlanBtn.addEventListener('click', fetchPlan);
      }
      if (refreshReportBtn) {
        refreshReportBtn.addEventListener('click', fetchReport);
      }
      if (refreshStepsBtn) {
        refreshStepsBtn.addEventListener('click', refreshSteps);
      }
      if (refreshStepReportBtn) {
        refreshStepReportBtn.addEventListener('click', fetchStepReport);
      }
      if (refreshSubtasksBtn) {
        refreshSubtasksBtn.addEventListener('click', refreshSubtasks);
      }
      if (refreshSubtaskReportBtn) {
        refreshSubtaskReportBtn.addEventListener('click', fetchSubtaskReport);
      }
      toggleCheck.addEventListener('click', async () => {
        if (checkEnabled === null) {
          return;
        }
        toggleCheck.disabled = true;
        checkStatusMsg.textContent = 'updating...';
        const data = await fetchJson('api.php?action=toggle_check', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ enabled: !checkEnabled }),
        });
        toggleCheck.disabled = false;
        if (data.error) {
          checkStatusMsg.textContent = data.error;
          return;
        }
        setCheckStatus(!!data.enabled);
        checkStatusMsg.textContent = 'updated';
        setTimeout(() => {
          checkStatusMsg.textContent = '';
        }, 1200);
      });
      if (toggleDebug) {
        toggleDebug.addEventListener('click', async () => {
          if (debugEnabled === null) {
            return;
          }
          toggleDebug.disabled = true;
          debugStatusMsg.textContent = 'updating...';
          const data = await fetchJson('api.php?action=toggle_debug', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled: !debugEnabled }),
          });
          toggleDebug.disabled = false;
          if (data.error) {
            debugStatusMsg.textContent = data.error;
            return;
          }
          setDebugStatus(!!data.enabled);
          debugStatusMsg.textContent = 'updated';
          setTimeout(() => {
            debugStatusMsg.textContent = '';
          }, 1200);
        });
      }
      if (settingsButton) {
        settingsButton.addEventListener('click', async () => {
          setSettingsOpen(true);
          await loadSettings();
        });
      }
      if (closeSettingsBtn) {
        closeSettingsBtn.addEventListener('click', () => setSettingsOpen(false));
      }
      if (cancelSettingsBtn) {
        cancelSettingsBtn.addEventListener('click', () => setSettingsOpen(false));
      }
      if (settingsModal) {
        settingsModal.addEventListener('click', (event) => {
          if (event.target === settingsModal) {
            setSettingsOpen(false);
          }
        });
      }
      if (settingsForm) {
        settingsForm.addEventListener('submit', saveSettings);
      }

      async function bootstrap() {
        await refreshCheckStatus();
        await refreshDebugStatus();
        if (pollingEnabled) {
          refreshJobs();
          refreshWorkerStatus();
          refreshRepos();
        }
      }

      bootstrap();
      setInterval(refreshJobs, 4000);
      setInterval(() => fetchLog(false), 1200);
      setInterval(refreshWorkerStatus, 3000);
      setInterval(refreshCheckStatus, 3000);
      setInterval(refreshDebugStatus, 5000);
      setInterval(fetchPlan, 5000);
      setInterval(fetchReport, 5000);
      setInterval(refreshSteps, 4000);
      setInterval(() => fetchStepLog(false), 1200);
      setInterval(fetchStepReport, 5000);
      setInterval(refreshSubtasks, 4000);
      setInterval(() => fetchSubtaskLog(false), 1200);
      setInterval(fetchSubtaskReport, 5000);
    </script>
  </body>
</html>
