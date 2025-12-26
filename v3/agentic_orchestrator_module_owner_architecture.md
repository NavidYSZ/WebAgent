# Agentic Coding Informationsarchitektur (Orchestrator + Module-Owner)
Ziel: Projekte bleiben **konsistent und fortsetzbar**, auch wenn der LLM-Kontext resettet/zusammengefasst wird.  
Prinzip: **Chat ist flüchtig – Repo-Artefakte sind das Gedächtnis (Single Source of Truth, SoT).**

---

## 1) Kernprinzipien
- **Externalisiere alles Dauerhafte**: Entscheidungen, Schnittstellen, Invarianten, aktuelle To-dos → ins Repo.
- **Nur Public Surfaces sind „vertraglich“**: API, DB, Events, CLI, Modul-Entrypoints (nicht jede Helper-Funktion).
- **Ownership statt Hierarchie**: 1 globaler Orchestrator + 1 Owner je Modul. Flach, klar, wenig Koordinationsoverhead.
- **Write-back Pflicht**: Jede relevante Änderung am Systembild muss zurück in SoT/Contracts.
- **Verification Gate**: Kein „fertig“ ohne reproduzierbare Checks + Ergebnis-Log.

---

## 2) Rollenmodell (optimal flach)
### A) Architect / Orchestrator (global)
**Aufgaben**
- Zerlegt Work in Tasks (klein genug, dass ein Modul-Owner sie sauber erledigen kann).
- Definiert Scope, Acceptance Criteria, Verification Commands.
- Weist Tasks einem Modul-Owner zu.
- Entscheidet bei Cross-Module-Konflikten & Breaking Changes.

**Artefakte, die er schreibt/ändert**
- `/tasks/ACTIVE.md` (aktueller Stand, Prioritäten, Next Steps)
- `tasks/<id>_taskpack.md` (Task Pack pro Task)
- optional: `/docs/architecture.md` (wenn Module/Boundaries ändern)

### B) Module-Owner Agent (pro Modul)
**Aufgaben**
- Implementiert Änderungen **nur in owned paths**.
- Hält das Modul konsistent mit Contracts & Invarianten.
- Meldet Contract-Impact (breaking/non-breaking) und erforderliche Migrationen.
- Liefert Result Pack (inkl. Verify-Result).

**Artefakte, die er schreibt/ändert**
- Code im Modul
- `/contracts/*` (wenn Interface/Schema/Invarianten betroffen)
- `tasks/<id>_result.md` (Result Pack)
- optional: `/docs/architecture.md` (wenn Modulverantwortung oder Dependency ändert)

### C) Verifier (kann Orchestrator oder separater Agent sein)
**Aufgaben**
- Prüft, dass Acceptance Criteria erfüllt sind.
- Führt Tests/Checks aus oder verifiziert Logs.
- Checkt Contract-Konsistenz (z.B. API.yaml vs Controller) und Breaking Change Hinweise.

**Artefakte**
- Update in `tasks/<id>_result.md` oder `/tasks/ACTIVE.md` (Verification Results)

> Optional (ab größerem Projekt): „Doc Steward“ – pflegt ADRs/Runbooks. Nicht nötig fürs MVP.

---

## 3) Repo-Struktur (SoT + Contracts + Task-Memory)
### Minimal-SoT (empfohlen als Start)
```
/docs/project.md
/docs/architecture.md
/contracts/api.yaml
/contracts/db.sql
/contracts/invariants.yaml
/tasks/ACTIVE.md
```

### Ergänzungen, wenn du 20% mehr Reife willst (optional)
```
/docs/adr/           # Architecture Decision Records (kurz: warum + tradeoff)
/tasks/<id>_taskpack.md
/tasks/<id>_result.md
/context/map.json    # Modul -> owned paths, tags, entrypoints (für Retrieval)
```

---

## 4) „Welche Daten gehören wohin?“ (harte Trennung)
### Persistentes Wissen (muss ins Repo)
- **Was ist das Ziel / welche Constraints?** → `/docs/project.md`
- **Wie ist das System modularisiert?** → `/docs/architecture.md`
- **Welche Interfaces sind garantiert?** → `/contracts/api.yaml`
- **Welche Datenform ist garantiert?** → `/contracts/db.sql`
- **Was darf nie brechen?** → `/contracts/invariants.yaml`
- **Was ist gerade los + was kommt als nächstes?** → `/tasks/ACTIVE.md`

### Flüchtiges Wissen (darf im Chat bleiben)
- Zwischenüberlegungen, Debug-Gefummel, „Ich probiere X mal“
- Tool-Logs in voller Länge (nur Ergebnis/Essenz in ACTIVE.md)

**Regel:** Wenn eine neue Instanz es sonst nicht wissen kann → es muss ins Repo.

---

## 5) Datenschnittstellen (Task Pack / Result Pack)
### 5.1 Task Pack (Orchestrator → Module-Owner)
**Datei:** `tasks/<id>_taskpack.md`

**Inhalt (max. 1 Seite)**
- Goal (1–2 Sätze)
- Scope: In-scope / Out-of-scope (Do-not-touch)
- Relevant Contracts: Links auf `/contracts/*`
- Acceptance Criteria (3–7 bullets, messbar)
- Verification Commands (exakt)
- Dependencies (andere Module / Versionen / Endpunkte)
- „Contract Change Policy“: breaking? erlaubt? wie versionieren/migrieren?

**Template**
```md
# Task Pack <id>: <title>

## Goal
- ...

## Scope
- In scope:
  - ...
- Out of scope:
  - ...

## Relevant Contracts
- API: /contracts/api.yaml (endpoints: ...)
- DB:  /contracts/db.sql (tables: ...)
- Invariants: /contracts/invariants.yaml (ids: ...)

## Acceptance Criteria
- [ ] ...
- [ ] ...

## Verification
```bash
<command 1>
<command 2>
```

## Dependencies / Notes
- ...
```

---

### 5.2 Result Pack (Module-Owner → Orchestrator)
**Datei:** `tasks/<id>_result.md`

**Inhalt (kurz, aber vollständig)**
- Summary (max 5 bullets)
- Changed Files (Liste)
- Contract Impact (none / changed + breaking yes/no)
- Verification Results (Commands + PASS/FAIL)
- Risks/Open Questions (max 3)
- Follow-ups (wenn nötig)

**Template**
```md
# Result Pack <id>

## Summary
- ...

## Changed Files
- ...

## Contract Impact
- API: none | changed: <endpoint id> (breaking: yes/no)
- DB:  none | changed: <table/column> (breaking: yes/no)
- Invariants: none | changed: <invariant id>

## Verification Results
- `<command>` => PASS/FAIL (short note)

## Risks / Open Questions
- ...
```

---

## 6) Ablauf „wann wer was speichert“ (Workflow)
### Step 0: Initial Setup (einmalig)
**Orchestrator**
1) Erstellt die 6 Minimaldateien.
2) Schreibt in `/docs/architecture.md` die Module + owned paths.
3) Legt in `/contracts/*` die initialen Contracts an (auch wenn klein).

### Step 1: Neuer Task entsteht
**Orchestrator**
1) Schreibt/aktualisiert `/tasks/ACTIVE.md`:
   - Current Goal, Scope, Next Steps, Verify Commands
2) Erstellt `tasks/<id>_taskpack.md`
3) Weist einen Module-Owner zu (explizit in Task Pack oder ACTIVE.md)

### Step 2: Umsetzung im Modul
**Module-Owner**
1) Liest: `/docs/*`, `/contracts/*`, `/tasks/ACTIVE.md`, `tasks/<id>_taskpack.md`
2) Implementiert nur im owned scope.
3) Wenn Interface/Schema betroffen:
   - **Zuerst** Contract ändern (oder parallel), damit klar ist, was „Wahrheit“ ist.
   - Breaking? → in Result Pack markieren + Migration/Kompatibilität nennen.
4) Führt Verification Commands aus.
5) Schreibt `tasks/<id>_result.md` + aktualisiert `/tasks/ACTIVE.md` (Done/Next)

### Step 3: Verifikation & Merge
**Verifier (oder Orchestrator)**
1) Prüft Result Pack (Contracts, Tests, Scope).
2) Ggf. fordert Nachbesserung an.
3) Markiert Task als Done in `/tasks/ACTIVE.md`.

---

## 7) Cross-Module Changes (wichtigster Stabilitätshebel)
Cross-Module ist der Hauptgrund, warum Systeme driften. Deshalb ein klarer Handshake:

### Regel
- **Kein Modul ändert ein Contract, das andere Module konsumieren**, ohne dass:
  1) Contract-Impact dokumentiert ist (breaking/non-breaking),
  2) Migration/Kompatibilität beschrieben ist,
  3) Verifier/Orchestrator den Change akzeptiert.

### Ablauf
1) Orchestrator erstellt Task Pack mit „Contract change allowed: yes/no“
2) Owner A (producing module) macht Contract-Änderung + Result Pack
3) Orchestrator erstellt Folge-Tasks für Owner B/C (consumers) zur Anpassung
4) Integration Gate: Tests/Smoke laufen global

**Tipp:** Wenn möglich, nutze „Additive changes“ zuerst (neue Felder/Endpunkte), entferne alte später.

---

## 8) Handoff nach Context-Reset (neue Instanz)
Neue Instanz (egal ob Orchestrator oder Owner) macht immer:
1) `/docs/project.md`
2) `/docs/architecture.md`
3) `/contracts/api.yaml`, `/contracts/db.sql`, `/contracts/invariants.yaml`
4) `/tasks/ACTIVE.md`
5) ggf. das aktuelle `tasks/<id>_taskpack.md` / `<id>_result.md`

Wenn danach noch etwas unklar ist → es ist ein Doku-Fehler: in ACTIVE.md/Contracts nachtragen.

---

## 9) Systemprompt (VS Code / Codex) für dieses Modell
Copy-Paste (kurz, aber hart):

```text
You are an agentic coding assistant in a repo. Chat context is not reliable.
Repository docs/contracts/tasks are the Single Source of Truth (SoT).

Always read first:
- /docs/project.md
- /docs/architecture.md
- /contracts/api.yaml
- /contracts/db.sql
- /contracts/invariants.yaml
- /tasks/ACTIVE.md
- tasks/<current>_taskpack.md (if exists)

Rules:
1) Persist durable knowledge in repo files, not chat.
2) Only public surfaces are contractual: API/DB/events/CLI/module entrypoints.
3) Update /tasks/ACTIVE.md at start and end of work.
4) If you change an interface/schema/invariant, update /contracts/*.
5) Verification is mandatory: record exact commands + results in Result Pack or ACTIVE.md.
Completion: A new instance must continue by reading only repo artifacts.
```

---

## 10) Quick-Start Checkliste (MVP)
- [ ] 6 Dateien angelegt
- [ ] Module + owned paths in `architecture.md`
- [ ] Ein Task Pack + ein Result Pack erfolgreich durchgezogen
- [ ] Mindestens 1 Verification Command, reproduzierbar dokumentiert
- [ ] Einmal bewusst Context reset simuliert: neue Instanz kann weiter

---

## Optional: Upgrade-Pfade (wenn du merkst, es reicht nicht)
- **/docs/adr/** hinzufügen, wenn du oft „warum war das so?“ vergisst.
- **/context/map.json** hinzufügen, wenn Retrieval/Dateiauswahl zu langsam/unsicher ist.
- **Contract Versioning** (z.B. `api.v1.yaml`, `api.v2.yaml`), wenn Breaking Changes häufiger werden.
