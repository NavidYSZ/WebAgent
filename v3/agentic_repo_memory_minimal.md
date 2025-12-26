# Minimal Agentic-Repo Memory (6 Dateien)
Hinweis (v3): Das Orchestrator/Module-Owner Modell und Task/Result Packs sind in
`agentic_orchestrator_module_owner_architecture.md` beschrieben und gelten
zusätzlich zu diesem Minimal-Setup.
Ziel: Egal wie oft der Chat-/Kontext „resettet“ wird – eine neue LLM-Instanz kann **nur über Repo-Dateien** nahtlos weiterarbeiten.

---

## Ordnerstruktur (genau 6 Dateien)
```
/docs/project.md
/docs/architecture.md
/contracts/api.yaml
/contracts/db.sql
/contracts/invariants.yaml
/tasks/ACTIVE.md
```

---

## 1) `/docs/project.md` (North Star + Regeln + Agent-Protokoll)
> **Kurz halten** (idealerweise 1–2 Seiten). Das ist die globale SoT.

```md
# Project: <NAME>

## Goal
- <1–2 Sätze: Was ist der Nutzen / was soll am Ende funktionieren?>

## Non-Goals
- <Was explizit nicht gebaut wird / out of scope?>

## Tech Constraints
- Stack: <z.B. PHP 8.2, MySQL, Vanilla JS, …>
- No-go: <z.B. kein Node, keine externen Services, etc.>
- Deployment: <z.B. Docker/NGINX, shared hosting, etc.>

## Conventions
- Coding style: <PSR-12 / eslint / etc.>
- Error handling: <Regeln>
- Logging: <Regeln>
- Naming: <Regeln>

## Agent Rules (Systemverhalten)
Chat-Kontext ist flüchtig. **Repo-Dateien sind das Gedächtnis.**

### Hard Rules
1) Verlasse dich nicht auf Chat-History. Schreibe langlebiges Wissen ins Repo.
2) Vor Änderungen immer lesen:
   - `/docs/project.md`, `/docs/architecture.md`, `/contracts/*`, `/tasks/ACTIVE.md`
3) „Contractual surfaces“ (müssen stabil sein):
   - API-Endpunkte, DB-Schema, Events/Queues, CLI-Commands, Modul-Entrypoints
4) Jede nicht-triviale Entscheidung → dokumentiere kurz (unten in ACTIVE.md „Key Decisions“).

### Work Protocol (immer)
A) **Start:** `/tasks/ACTIVE.md` aktualisieren (Goal + Plan + Scope)
B) **Implement:** nur im Scope arbeiten; Scope-Änderung vorher in ACTIVE.md festhalten
C) **Verify:** Commands aus ACTIVE.md ausführen und Ergebnisse eintragen
D) **Write-back Pflicht:**
   - Interface/Schema/Event geändert? → `/contracts/*` aktualisieren
   - Neue Invariante/Regel? → `/contracts/invariants.yaml` aktualisieren

## Verification Defaults
- Unit tests: <Command oder "none">
- Lint: <Command oder "none">
- Smoke: <Command oder curl-Beispiele>

## Handoff Definition
Eine neue Instanz muss weiterarbeiten können, indem sie nur:
`/docs/*`, `/contracts/*`, `/tasks/ACTIVE.md` liest.
Wenn etwas unklar wäre → es gehört in ACTIVE.md oder Contracts.
```

---

## 2) `/docs/architecture.md` (C4-lite + Module + Ownership)
> Fokus auf **Module & Grenzen**, nicht auf jede interne Funktion.

```md
# Architecture (C4-lite)

## System Overview
- Components:
  - <Frontend / Backend / Worker / DB / External APIs>
- Data flow (high level):
  - <Client> -> <API> -> <DB> -> <…>

## Modules (Bounded Contexts)
### Module: <MODULE_A>
- Responsibility: <1 Satz>
- Owned paths:
  - `<path1>`
  - `<path2>`
- Public surfaces (contracts):
  - API: `<endpoint(s)>`
  - DB: `<tables>`
  - Events: `<event names>`
- Dependencies:
  - calls: <MODULE_B>
  - reads: <DB table>

### Module: <MODULE_B>
- ...

## Cross-cutting Concerns
- Auth: <Kurz>
- Validation: <Kurz>
- Caching: <Kurz>
- Observability: <Kurz>

## Integration Points
- External services:
  - <Service> (purpose, auth method)
- Webhooks:
  - <name> (direction, payload contract)
```

---

## 3) `/contracts/api.yaml` (API-Contract – klein aber hart)
> Wenn du kein OpenAPI willst: trotzdem YAML mit **Inputs/Outputs**.

```yaml
version: 1
base_url: /api

endpoints:
  - id: get_user
    method: GET
    path: /users/{id}
    auth: required
    request:
      path_params:
        id: { type: string, example: "123" }
      query: []
      body: null
    response:
      200:
        content_type: application/json
        schema:
          type: object
          required: [id, name]
          properties:
            id:   { type: string }
            name: { type: string }
      404:
        content_type: application/json
        schema:
          type: object
          required: [error]
          properties:
            error: { type: string }
notes:
  - "Breaking changes require updating this file + verifying consumers."
```

---

## 4) `/contracts/db.sql` (DB-Schema – Wahrheit für Datenform)
> Minimal: nur Tabellen/Views/Constraints, keine Daten.

```sql
-- Schema Contract (authoritative)
-- Breaking change = column removed/renamed/type changed without migration plan.

CREATE TABLE users (
  id VARCHAR(64) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add further tables here
```

---

## 5) `/contracts/invariants.yaml` (Nicht brechen! Regeln & Invarianten)
> Das ist der beste Drift-Killer. Kurz, präzise.

```yaml
version: 1
invariants:
  - id: auth_required_for_api
    rule: "All /api endpoints require auth unless explicitly listed as public."
    exceptions:
      - "GET /api/health"
  - id: user_id_stability
    rule: "users.id is stable and never changes after creation."
  - id: response_envelope
    rule: "All error responses return JSON with { error: string }."
  - id: timezone
    rule: "All timestamps are stored in UTC in DB; UI converts to local."
```

---

## 6) `/tasks/ACTIVE.md` (Arbeitsgedächtnis + Handoff)
> **Das wichtigste File** für Fortsetzen nach Kontextverlust.

```md
# ACTIVE TASK

## Current Goal
- <1–2 Sätze: Was wird gerade gebaut/fixed?>

## Scope
- In scope:
  - <Pfad/Modul/Endpoints>
- Out of scope (do not touch):
  - <Pfad/Modul>

## Done
- [x] <Item>
- [x] <Item>

## In Progress
- [ ] <Item>

## Next Steps (ordered)
1) [ ] <Step>
2) [ ] <Step>
3) [ ] <Step>

## Key Decisions
- <Kurzsatz> (why) — if it affects interfaces: update /contracts/*
- <Kurzsatz>

## Contract Impacts
- API: none | changed: <endpoint id> (breaking: yes/no)
- DB: none | changed: <table/column> (breaking: yes/no)
- Invariants: none | changed: <invariant id>

## How to Verify
```bash
# commands (exact)
<command 1>
<command 2>
```

## Verification Results
- <date/time>: `<command>` => PASS/FAIL (+ short note)

## Known Risks / Open Questions (max 5)
- <Risk or question>
```

---

# VS Code / Codex: Systemprompt (Copy-Paste)
> Du kannst das als **Codex system prompt** benutzen. Es verweist auf die 6 Dateien als SoT.

```text
You are an agentic coding assistant in a repo. Chat context is NOT reliable long-term.
Repository docs/contracts are the Single Source of Truth (SoT). Externalize durable knowledge.

Read before coding:
- /docs/project.md
- /docs/architecture.md
- /contracts/api.yaml
- /contracts/db.sql
- /contracts/invariants.yaml
- /tasks/ACTIVE.md

Hard rules:
1) Do not rely on prior chat history. Persist important info in repo files.
2) Only public surfaces are contractual: API/DB/events/CLI/module entrypoints.
3) Update /tasks/ACTIVE.md at start and end of every work session.
4) If you change any interface or schema, update /contracts/* accordingly.
5) Verification is mandatory: record exact commands + results in ACTIVE.md.
Completion: A new instance must continue by reading only these repo files.
```

---

# Praktischer Ablauf (kurz)
1) Orchestrator schreibt Goal/Scope/Verify in `tasks/ACTIVE.md`
2) Module-Owner implementiert + führt Verify aus
3) Module-Owner schreibt Ergebnisse + Änderungen an Contracts in Repo
4) Nächste Instanz liest nur die 6 Dateien und macht weiter
