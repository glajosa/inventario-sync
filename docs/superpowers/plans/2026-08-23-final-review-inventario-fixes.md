# Inventario final-review fixes implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the final-review correctness and image-security findings without changing the approved commercial call-result contract.

**Architecture:** Keep `lib/llamada-protocolo.php` as the shared commercial domain module and extend its existing protocol calculation with the panel's exact RECONTACTAR cutoff. Keep all trust-boundary checks in `lib/llamada-resultado-service.php`, before writes, and make durable checkpoints advance only after exact Bitrix success. Harden both the Docker build context and Apache as independent defenses while retaining the in-image PHP suite.

**Tech Stack:** PHP 8.2, PDO SQLite, Bitrix24 REST wrapper, Apache 2.4, Docker, plain PHP integration tests.

**Spec:** `C:/Users/Pauta 01/Documents/Codex/2026-08-17/o-procesos-que-podr-amos-acortar-2/work/bitrix-sim-bridge/.worktrees/v1-resultados-llamada/docs/superpowers/specs/2026-08-20-resultados-llamada-v1-design.md`

## Global Constraints

- Preserve all `origin/main` business changes already merged into HEAD `8c3933d693d2b00583ee0883aece11de4925156a`.
- Do not push, deploy, or touch live Bitrix data.
- Do not reassign contacts or deal owners.
- `No contestó` and `Sí contestó` never change stage; only `No le interesa` may change to the configured stage.
- Validate every request context before the first write.
- Retain `tests/` in the Docker image while denying it over HTTP.

---

### Task 1: RECONTACTAR progression parity

**Files:**
- Modify: `lib/llamada-protocolo.php`
- Modify: `lib/llamada-resultado-service.php`
- Modify: `llamada_nativo.php`
- Test: `tests/test-llamada-protocolo.php`
- Test: `tests/test-llamada-resultado-service.php`

**Interfaces:**
- Consumes: Bitrix deal field `UF_CRM_1781115254387`, stage `C28:PREPARATION`, activity `CREATED`, stage-history `CREATED_TIME`.
- Produces: `llamada_calcular_protocolo(array $actividades, ?int $excluirId, ?string $reingreso = null): array` and a helper that resolves the latest real reentry using the exact panel filter.

- [ ] Add literal fixtures proving three old unanswered calls plus a real reentry schedule the mobile `no_answer` result at +1 day, while the same history without a real reentry keeps the previous +99-day behavior.
- [ ] Run the focused PHP tests and verify they fail because pre-reentry calls are still counted.
- [ ] Move the stage/field identifiers into `llamada_config()`, make the panel consume those shared values, and extend the existing PHP protocol function with the panel's exact lexical `<` cutoff and real-reentry counter guard.
- [ ] Fetch only the latest RECONTACTAR stage history through the existing Bitrix callable and pass its timestamp into the shared protocol calculation.
- [ ] Run the focused tests and preserve all old no-reentry fixtures.

### Task 2: Strict durable Bitrix write success

**Files:**
- Modify: `lib/llamada-resultado-service.php`
- Test: `tests/test-llamada-resultado-service.php`
- Test: `tests/test-resultado-endpoint.php`

**Interfaces:**
- Consumes: `llamada_bx_result()` wrapper output.
- Produces: a write helper that accepts only the literal boolean `true` for `crm.activity.update` and `crm.deal.update`.

- [ ] Add table-driven failing tests for `false`, missing `result`, and unexpected non-boolean results from `crm.activity.update`; assert retryable checkpoint, no comment, no stage write, and retry resumes without duplicated completed effects.
- [ ] Add equivalent partial-failure tests for `crm.deal.update`; assert the activity/comment checkpoints remain durable and only the failed stage write repeats.
- [ ] Verify the focused tests fail because the current generic result helper accepts `false` and unexpected values.
- [ ] Route both update methods through a strict-true helper and checkpoint failures before propagating them.
- [ ] Run focused service and endpoint tests until green.

### Task 3: Bind selectedPhone to Bitrix context

**Files:**
- Modify: `lib/llamada-resultado-service.php`
- Test: `tests/test-llamada-resultado-service.php`
- Test: `tests/test-resultado-endpoint.php`

**Interfaces:**
- Consumes: normalized request `selectedPhone`, current activity `COMMUNICATIONS`, and the primary contact's complete `PHONE` list.
- Produces: pre-write validation that accepts a normalized exact match in either authoritative source and throws `LlamadaForbidden('selected phone mismatch')` otherwise.

- [ ] Make fake Bitrix activity/contact responses mirror real phone arrays.
- [ ] Add failing fixtures for an arbitrary unmatched number, formatted exact activity match, and a match in the second phone of a multi-phone primary contact.
- [ ] Verify mismatch fails before every write and before activity-history side effects.
- [ ] Implement one normalization-based matcher over all activity communications and contact phones; do not assume the first phone.
- [ ] Run focused tests and the byte-for-byte bridge contract fixture.

### Task 4: Unicode comment boundary

**Files:**
- Modify: `lib/llamada-resultado-service.php`
- Modify: `README.md`
- Test: `tests/test-llamada-resultado-service.php`
- Test: `tests/test-resultado-endpoint.php`

**Interfaces:**
- Consumes: JSON string `comment`.
- Produces: valid UTF-8, ECMAScript-compatible edge trimming, and a hard maximum of 2,000 Unicode code points before reads/writes.

- [ ] Add failing tests for 2,000 emoji accepted, 2,001 emoji rejected, invalid UTF-8 rejected at the service boundary, and Unicode edge whitespace trimmed like the bridge.
- [ ] Verify overlimit and invalid input cause zero Bitrix calls.
- [ ] Add dependency-free PCRE UTF-8 validation/counting and Unicode trim helpers; reject rather than truncate overlimit direct requests.
- [ ] Document the server rule and bridge alignment in `README.md`.
- [ ] Run focused service/endpoint tests.

### Task 5: Docker and Apache source disclosure defense

**Files:**
- Create: `.dockerignore`
- Modify: `apache-tests-deny.conf`
- Modify: `Dockerfile`

**Interfaces:**
- Consumes: repository Docker build context.
- Produces: image with required PHP/tests/assets only, no `.git`, credentials, scratch files, or source backups; Apache denies dotfiles and backup suffixes independently.

- [ ] Record the baseline evidence: `/.git/HEAD`, `/.git/config`, and source backups currently return HTTP 200 while `/tests/run.php` returns 403.
- [ ] Add `.dockerignore` patterns for VCS metadata, credentials/env files, logs, editor/scratch files, reports, and known backup suffixes, deliberately leaving `tests/` included.
- [ ] Add Apache deny rules for dotfiles/VCS paths and backup/scratch suffixes, without blocking required PHP routes.
- [ ] Add a build-time cleanup/assertion so future ignored-pattern regressions fail closed.
- [ ] Build a clean image, inspect its contents, run the in-image suite, and verify security URLs are never 200.

### Task 6: Full verification, commit, and bridge report

**Files:**
- Create outside this repository: `bitrix-sim-bridge/.worktrees/v1-resultados-llamada/.superpowers/sdd/final-fix-inventario-report.md` (ignored coordination report)

**Interfaces:**
- Consumes: final working tree.
- Produces: one local commit and an evidence report; no push/deploy.

- [ ] Run all PHP tests in Docker, every PHP lint, `apache2ctl -t`, the HTTP Apache suite, contract fixture, and image-content/security checks.
- [ ] Review `git diff --check`, `git diff`, and `git status`; verify only scoped files changed.
- [ ] Write the ignored bridge report with root causes, behavioral changes, commands, results, and the commit hash when available.
- [ ] Commit locally and append the final commit hash to the ignored report without changing the committed Inventario tree.
