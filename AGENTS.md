# Fabricate Forge — Project Context

Forge (Vue 2.6 + PHP) costing/fabrication app at `/var/www/html/fabricate_forge`.

## Session Start — MANDATORY GATE (do IN ORDER before ANY task)

1. **Read `CONTEXT.md` → "Pricing & structure directives"** — D1–D5 + structural truth rule. These are THE SPEC for any cost/structure work; engine code is not.
2. **Read `.progress`** — live session tracker (done / in-flight / next). Update it when you finish or hand over work; don't let it go stale.
3. **Orient:** read `MAP.md` (structure) and `LOGIC.md` (function dataflow map) as needed for the task.
   - If those docs are **missing or stale** (architecture changed since their last update), **RUN THE `codebase-map` SKILL** to (re)generate them BEFORE doing anything else. Never work from guesses.
   - Full protocol: [ONBOARDING.md](/root/.pi/agent/okf-memory/projects/ONBOARDING.md)

## Project skills (ALWAYS consult before cost/weld/assemblies work)

| Skill | When |
|-------|------|
| forge skill (base) | Check forge components BEFORE raw HTML/CSS/JS; theme via `--forge-*` tokens. |
| codebase-map | MAP.md / CONTEXT.md / DESIGN.md owner; domain glossary + ADRs. |
| dev-loop | TDD build + two-axis review + debug loop. |

## Guard Rails

1. **NEVER create/run database migrations** or migration scripts — schema is app-managed.
2. **NEVER execute DROP TABLE, DROP DATABASE, DELETE FROM, or TRUNCATE** SQL against the app DB.

## Doc ownership (single source each)

| Doc | Owns |
|-----|------|
| CONTEXT.md | **User directives (D1–D5) + structural truth rule** · domain glossary · ADRs |
| MAP.md | Structure: boot chain, component tree, who-calls-what, DB tables |
| LOGIC.md | Behavior: per-file functions, reads→writes dataflow, failure modes |
| DESIGN.md | Design tokens |
| .progress | Session tracker: done ✓ / in-flight / next action |
