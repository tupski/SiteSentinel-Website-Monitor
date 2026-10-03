# Changelog

## [2026-10-03] — Phase 9: Security Hardening

### Added (Phase 9 — Security Hardening)
- `app/Http/Middleware/EnforceSessionTimeouts.php` — enforces the SECURITY.md §2.5 idle (default 30 min) and absolute (default 8 h) admin session timeouts; wired into the `/admin` group via the `session.timeouts` alias. Logs out + invalidates the session on expiry.
- Retention pruning (`AC-19`): `Check`, `Snapshot`, `NotificationLog`, `Incident`, `NotificationCooldown` now implement `Prunable`/`MassPrunable` with the frozen windows (checks 30d, snapshots 14d, notification_logs 90d, incidents 365d, cooldowns by `expires_at`). `model:prune` was previously scheduled but a no-op because no model was prunable. `Snapshot::pruning()` deletes the on-disk HTML artefact with the row.
- `TRUSTED_PROXIES` env + `trustProxies()` wiring (opt-in, never `*`): the app runs behind Nginx, so IP-keyed throttling would otherwise collapse all clients into one bucket. Default trusts nothing (fail-safe, unspoofable `X-Forwarded-For`).
- Security regression suite `tests/Feature/Security/*` — **82 tests** organized by threat model: `SsrfRegressionTest` (blocked destination never reaches the transport, redirect revalidation, connection-destination pinning), `CsrfSessionTest`, `ThrottlingTest`, `SecretsRedactionTest`, `AuthorizationIdorTest`, `RetentionPruningTest`, `SessionTimeoutTest`, `ProductionConfigTest`, `QueueExternalServiceTest`, `LoggingBoundariesTest`, plus a shared `SecurityTestCase`.

### Fixed (Phase 9 — audit-driven)
- **SSRF (High):** `SsrfGuard` now rejects the whole DNS answer if *any* resolved address is denied (SECURITY.md §5.5), closing the mixed public/private-answer / rebinding gap; it previously selected the first public IP and ignored a private one in the same answer. `SsrfUrlValidator` already behaved correctly, so the two layers had diverged.
- **SSRF (High):** both validators now reject non-canonical numeric IPv4 hosts (`127.1`, `2130706433`, `0x7f000001`, `0177.0.0.1`) before resolution (SECURITY.md §5.4).
- **Secrets (High):** `NotificationChannel` now hides `secret_ref` from array/JSON serialization (`$hidden`), preventing the decrypted channel secret from leaking through a naive `toArray()`/`toJson()` in a controller or response.
- **Logging (High):** `MessageRedactor` now redacts JSON-quoted secret pairs (`"token":"…"`), `Authorization` schemes, and Telegram bot tokens embedded in URLs (`api.telegram.org/bot<digits>:<token>/…`), which previously leaked.
- **Status page (Medium):** `StatusPageController::json` now fails closed with `404` (not `403`) when password mode has no usable hash, so it no longer advertises the page's existence; this matches the HTML path and SECURITY.md §3.5.
- **CSRF (High if shipped):** removed the blanket `validateCsrfTokens(except: ['/*'])` exemption from `bootstrap/app.php`. Laravel already skips token validation under `APP_ENV=testing`; the wildcard was redundant in tests and a real production bypass risk.

### Verified (Phase 9 close)
- Full suite **413 tests, 408 passed, 5 failed** — the 5 failures are the pre-existing SQLite `no such table: sessions` environment failures (identical set to the Phase 8 baseline: `BaseLayoutTest`, `ExampleTest`, 3× `HealthEndpointTest`); **zero new regressions**, +84 net tests.
- `vendor/bin/pint` clean (25 files touched); `npm run build` succeeds; all migrations `Ran`.
- `SECURITY.md` §12.1 added: the mandatory Phase 9 disposition matrix (29 entries: FIXED / VERIFIED ACCEPTABLE / DEFERRED / BLOCKER / NOT VERIFIED), SSRF adversarial summary, trusted-proxy requirement, encryption/key-management assumptions, and production-readiness limitations that cannot be verified locally.

### Known limitations (documented, not code claims)
- **BLOCKER (production):** Redis auth is not enabled in `docker-compose.yml` (§12.1 L19) — must be set before production.
- **DEFERRED / operational:** egress firewall for residual SSRF TOCTOU (L2), HSTS/CSP/full secure-header set (L17), non-root containers (L21), least-privilege DB grants (L20), dependency scanning (L26), backup/restore drill (L27), decompression-ratio cap (L5), global exception-handler scrub (L11).

## [2026-10-03]

### Added (Phase 8 — Public Status Page)
- Migrations `0001_08_01_000000_create_status_page_settings_table` (verbatim `DATABASE.md` §3.18: `visibility_mode` ENUM default `Private`, `password_hash`, `slug` unique `uq_status_page_settings_slug`, `branding` JSON, timestamps; MySQL `CHECK` on the enum) and `0001_08_02_000000_add_status_visibility_to_websites_table` (additive `websites.is_visible_on_status` default `0`, `websites.status_alias`, index `idx_websites_visible_status`).
- Model `App\Models\StatusPageSetting` — singleton via `firstOrCreate(['id' => 1])`, mode predicates (`isPrivate`/`isPublic`/`isPasswordProtected`), `password_hash` hidden.
- Services `App\Services\StatusPage\*`: `StatusProjector` (single redaction chokepoint; reads `websites` snapshot columns + open incident severity only), `PublicStatusDTO` (allowlist `banner`/`services[]`/`updatedDayBucket`), `VisibilityGate` (mode decision + `v1:{updated_at}` unlock-stamp check), `StatusPageUnlockService` (`Hash::check`, three session keys, throttle clear, audit), `StatusPageCache` (DTO-only cache, `bust()`).
- Middleware `EnsureStatusVisibility` (single HTML+JSON gate; `noindex` + cache-control) and `ThrottleStatusUnlock` (5 attempts / 10 min per IP+session, `429` + `Retry-After`).
- Controllers `StatusPageController` (`show`, `json`, `unlock`, `logout`) and admin `StatusPageSettingController`; Form Request `UpdateStatusPageSettingsRequest` (fail-closed: `confirm_public` for Public, password required for Password Protected).
- Routes `GET /status`, `GET /status.json`, `POST /status/unlock`, `POST /status/logout`, admin `status-settings` edit/update; views `status/show`, `status/unlock`, `status/_history`, `admin/status-settings/form`; admin nav entry; `public/robots.txt` disallows `/status` + `/admin`; `config/sentinel.php` `status_page` block (`ttl_floor` 60, `stale_multiplier` 2, `stale_floor` 300, `unlock_max` 5, `unlock_window` 10, `history_enabled` false, `band_normal` 800, `band_slow` 2500, `cache_prefix`).
- Cache-bust hooks post-commit in `IncidentEngine`, `IncidentStateMachine::apply()`, and the admin settings save (failure-isolated `try/catch`+`report`; never break the write).
- Feature test suite `tests/Feature/StatusPage/*` — **94 tests**, all passing — covering the STATUS-PAGE §12 acceptance criteria: redaction regression with 17 canaries across every mode/unlock state, visibility gate, password unlock/throttle/rotation, derivation allowlist, staleness, projection cache, HTTP surface, no-SSRF, and enumeration resistance. All assert observable response bodies only; no live network.
- Docs: `ADR-029` (frozen Phase 8 choices: publish columns, stale floor 300 s, throttle 5/10 min, rotation via `updated_at`, history default off, slug storage-only, bands, count hidden, `status.json` included, singleton first-or-create); `STATUS-PAGE.md` implementation notes + §13 ops troubleshooting; `ARCHITECTURE.md` §10 component/cache/no-probe tables; `DATABASE.md` §3.18 singleton + hot path; `SECURITY.md` §3.5 route/throttle/session/cache/noindex; `PLAN.md` AC-8-01..08 DoD evidence.

### Fixed (Phase 8 — test-driven, ADR-028)
- Empty published set now derives `banner = Unknown` instead of fabricating `Operational` (`StatusProjector::banner()`; STATUS-PAGE §6.3 "No fabrication").
- Failed unlock now raises `ValidationException` (uniform `422` for JSON clients, redirect-back-with-errors for the form) instead of a bare `back()->withErrors(...)` `302`, so no service data is ever rendered on a failed unlock (STATUS-PAGE §3.4 / §12.1).

### Verified (Phase 8 close)
- Full suite **329 tests, 324 passed, 5 pre-existing `sessions` env failures** — identical to the pre-Phase-8 baseline (235 total / 5 failures at Phase 7 close); zero new failures, zero regressions. Pint clean (160 files); `npm run build` succeeds; all migrations `Ran`.

## [2026-09-30]

### Added (Phase 6 — Incident Management)
- Incident engine (`App\Services\Incidents\IncidentEngine`) reconciling the incident ledger after every persisted check: availability threshold cross opens an incident at `WARNING`, escalating to `CRITICAL` after `sentinel.incidents.availability_critical_after_failures` (default 6) consecutive failures (FR-53, PRD §11.3); security score crossing the `SUSPECT`/`INCIDENT` bands opens a `WARNING`/`CRITICAL` incident with full rule attribution (FR-54, FR-49); `OK`/`INFO` never opens an incident.
- Dedupe/merge (FR-60, AC-13): repeated detections of an open condition append `evidence_appended` events and update score/attributions in place; one open incident per `website_id + type`, re-checked inside a transaction so concurrent workers cannot double-create.
- Incident lifecycle state machine (`App\Services\Incidents\IncidentStateMachine`, PRD §12.1): `DETECTED -> ACKNOWLEDGED -> RESOLVED` only; `RESOLVED` is terminal; illegal transitions throw; replayed transitions are idempotent (NFR-09); every transition appends an immutable `incident_events` row with actor + timestamp (FR-57).
- Acknowledgement records actor + timestamp and never resolves (FR-58, AC-10); manual resolution stamps `resolution_mode = manual`, automatic resolution on sustained recovery stamps `resolution_mode = auto` with no acting user (FR-59, AC-11); recovery threshold configurable via `sentinel.incidents.recovery_consecutive_checks` (default 2, clock-controlled tests).
- Recurrence after resolution opens a NEW incident (AC-12).
- `incidents` + `incident_events` migrations matching `DATABASE.md` §3.10/§3.11 (canonical enums, `dedupe_key` index, FKs, `incident_events` cascade); `snapshots.incident_id` now carries a real FK with `ON DELETE SET NULL` per `DATABASE.md` §7.
- `RunWebsiteCheck` runs incident reconciliation after check persistence as a failure-isolated side effect (AGENTS.md §9) and links the captured evidence snapshot to the open incident (`snapshots.incident_id`).
- Admin incident UI (PLAN.md Phase 6): filterable list (state/severity/type, FR-62), detail page with rule attribution (AC-14) or failure classification, immutable timeline, acknowledge/resolve actions, evidence snapshot list; nav entry added.
- Dashboard delivered: counters total/operational/warning/incident (AC-6-07), per-website availability and security areas kept strictly separate (AGENTS.md §15.2), open-incident list, and an interleaved check + incident timeline (FR-61).
- Phase 6 feature tests: incident lifecycle (threshold, transient suppression, dedupe, escalation, sustained-recovery auto-resolution, recurrence), state machine semantics (acknowledge≠resolve, terminal RESOLVED, manual vs auto distinction, idempotent replay), HTTP authorization (unauth redirect, non-admin 403 on every method), rule-attribution rendering, filters, dashboard counters.

## [2026-09-30]

### Fixed (Phase 5 final verification)
- `RULE-SSL-004` now emits its canonical graduated tiers (DETECTION-RULES 8.2): `<= 7` days tier 7 (CRITICAL-eligible), `<= 14` tier 14 (WARNING band), `<= 30` tier 30 (INFO band), silent beyond 30 days. The tier is carried in the signal evidence; scoring remains weight x confidence and the correlation guard still applies at the CRITICAL-eligible tier.
- `RULE-SEO-004` now compares script sources against the baseline (canonical 8.8 `host(script.src) not in baseline_domains`): a script host already absorbed into `website_baselines.external_domains` no longer fires; off-baseline hosts fire with `new_script_src` evidence. Baseline evidence reuses the existing canonical column; no schema change.
- `checks.triggered_rules` (FR-49) is retained and documented in `DATABASE.md` 3.6 per AGENTS.md 8 ("every schema change requires a migration + a doc update + a test"); the attribution test proves the stored map reproduces the stored score.

## [2026-09-30]

### Fixed (Phase 5 remediation)
- `WebsiteBaseline` now persists `response_size_bytes` and `external_domains` (mass-assignment defect) so `RULE-CNT-003` and baseline link comparison can function.
- External-domain comparison now reads the canonical `website_baselines.external_domains` column via `BaselineComparator` with deterministic host normalisation (`www` stripping, lower-casing, URL→host), instead of a never-written `keyword_counts['_external_domains']` key.
- `RULE-KW-005` (hidden/obfuscated keywords) can now fire: the undefined `$suspicious` reference was replaced by the canonical `check_extractions.suspicious_patterns.hidden_keywords` / `obfuscated_inline` evidence path.
- Tiered content extraction (`ContentExtractor`) now extracts tier-1, tier-2 and tier-3 vocabulary plus hidden text, hidden anchors, doorway, third-party script and obfuscation evidence, feeding `RULE-KW-001..005`, `RULE-CNT-005`, `RULE-LNK-004` and `RULE-SEO-001/004`.
- Implemented `RULE-LNK-004` (CSS-hidden anchor to a new off-domain target), an MVP rule that had no runtime path.
- Canonical decay implemented per `DETECTION-RULES.md` §6.4: per-signal carry-forward at `×0.5`/`×0.25`/`×0.125` for the three prior checks, and carried signals now vote in the correlation guard category set.
- `threshold_override` (`FR-46`, §6.9) is now applied when deriving the reported security state.
- `RunWebsiteCheck` is idempotent on `check_key`: a replay of the same logical check returns without inserting, instead of raising a unique-constraint violation.
- Snapshot capture moved outside the check transaction and made failure-tolerant; `snapshots.html_path` now stores a disk-relative path instead of an absolute filesystem path.
- `RULE-SSL-001` seeded as canonical `WARNING` (escalating to `CRITICAL` only when expiry is the confirmed cause) instead of unconditional `CRITICAL`.
- `DetectionRuleSeeder` regenerated from the canonical §6.7 table: 36 rules with canonical categories, severities, weights and confidence multipliers; `RULE-SEO-002`/`RULE-SEO-003` ship disabled as documented Future rules.
- `config/sentinel.php` now defines the canonical `detection_keywords` (tier-1/2/3 + density floor + ignored keywords) and `links` (ignored domains, suspicious TLDs) sections the engine reads.
- `checks.triggered_rules` added to persist per-rule attribution (documented in `DATABASE.md` §3.6).
- Added fixture corpus `tests/Fixtures/detection/*` and fixture-driven tests covering per-rule positive/negative/boundary/suppression/guard cases, tier-3 containment, decay arithmetic, domain comparison, snapshot persistence/failure isolation and job-level idempotency.

## [2026-09-29]

### Added (Phase 5)
- Detection engine (`App\Services\Detection\RuleEngine`) implementing the two-dimensional availability/security classification, weighted scoring, and correlation guard from `DETECTION-RULES.md`.
- `detection_rules` registry migration and model, seeded with all 36 canonical `RULE-xx` entries via `DetectionRuleSeeder`.
- Per-website rule overrides (`website_rule_settings`) respected for `enabled`, `weight_override`, and `ignored_keywords`.
- Snapshot persistence (`snapshots` migration/model + `SnapshotWriter`) capturing HTML body and headers when a check reaches `SUSPECT`/`INCIDENT`.
- Baseline-relative comparison: content hash drift, title change, structural size change, and external-domain delta against `website_baselines`.
- Content/keyword/link/SEO rule evaluation gated by `monitor_content`/`monitor_security`; availability rules always run; content rules skipped when `availability_state = DOWN` with no body.
- Correlation guard enforced: a single category cannot produce `INCIDENT`; `>= 2` independent categories required.
- Integration of detection into `RunWebsiteCheck`: persists `security_state`, `score`, and capture snapshots for elevated states.
- Extraction fields added to `ProbeResult`/`Probe` (keywords, external domains, suspicious patterns) and baseline columns `response_size_bytes`/`external_domains`.
- Fixture-driven unit tests for clean content, hash drift, guard capping, cross-category incident, down-check content short-circuit, and ignored-keyword suppression.

## [2026-09-29]

### Fixed (Phase 4 remediation)
- Runtime SSRF guard now validates and selects a safe destination IP for every hop.
- HTTP probe pins the validated IP using `CURLOPT_RESOLVE` so the actual connection cannot be redirected by a later DNS rebinding attack.
- Removed separate `stream_socket_client` SSL inspection that bypassed SSRF validation; SSL metadata is captured from the same pinned connection.
- Added `checks.resolved_ip` migration/model/persistence.
- Implemented per-website Redis-backed lock in `RunWebsiteCheck` to prevent overlapping checks.
- Implemented deterministic `check_key` based on website and minute.
- Implemented first-success baseline creation in `website_baselines`.
- Fixed scheduler to use `next_check_at` for due-website dispatch.
- Enforced response body size limit by rejecting bodies exceeding `max_response_body_bytes`.
- Expanded SSRF/probe tests: DNS rebinding, mixed IPs, redirect to private/blocked port, redirect limit, oversized response, DNS failure.
- Fixed test-suite regressions related to CSRF/session driver and rate-limiter state leakage.

## [2026-09-29]

### Added (Phase 4)
- Secure HTTP probe foundation (`App\Services\Monitor\Probe`).
- Runtime SSRF guard (`App\Services\Security\SsrfGuard`) validating DNS-resolved IPs before connecting and on every redirect hop.
- Queue job `RunWebsiteCheck` and scheduler dispatch for due websites.
- Migrations for `checks`, `website_baselines`, and `check_extractions` per `DATABASE.md`.
- Models `Check`, `WebsiteBaseline`, `CheckExtraction`.
- Console command `sentinel:check-website` for manual single-website checks.
- Unit tests covering SSRF, DNS rebinding, IPv4/IPv6 private/loopback, redirect-to-private blocking, and probe response handling.

All notable changes to **SiteSentinel — Website Monitoring & Security Alerts** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## Categories

| Category | Meaning |
| --- | --- |
| **Added** | New features, files, or documentation. |
| **Changed** | Changes to existing behaviour or content. |
| **Deprecated** | Features/content still present but slated for removal. |
| **Removed** | Features/content that have been removed. |
| **Fixed** | Bug fixes. |
| **Security** | Vulnerability mitigations, hardening, or secret-handling changes. |

---

## [0.1.0] - 2026-09-28

### Added

Documentation / bootstrap phase. This release establishes the complete specification set for SiteSentinel. **No application code was written in this release.**

- `PRD.md` — Product Requirements Document; the authoritative source of truth for product-level requirements (`FR-*`, `NFR-*`, `AC-*`), the MVP boundary, severity/lifecycle/retention rules, and the canonical spec.
- `ARCHITECTURE.md` — System architecture: component inventory, data flows, the monitoring plane vs. web plane separation, queues, SSRF architecture, and the Docker Compose deployment topology.
- `DATABASE.md` — The frozen, authoritative table and column names, ER diagram, indexes, FK/cascade policy, and the high-level migration ordering.
- `DETECTION-RULES.md` — The exhaustive `RULE-xx` catalogue with categories, weights, patterns, thresholds, scoring arithmetic, correlation guard, and per-rule test requirements.
- `NOTIFICATIONS.md` — The notification dispatcher, provider contracts, MVP channels (Email + Telegram), event catalogue, templates, deduplication/cooldown mechanics, retry/backoff, and delivery-log shape.
- `STATUS-PAGE.md` — The public status page specification: visibility modes, the redaction boundary, public status derivation labels, caching, and the mandatory no-leak acceptance tests.
- `SECURITY.md` — The security specification: SSRF defence-in-depth pipeline, resource/rate limits, authentication, secret management, audit logging, deployment hardening, and the hardening checklist.
- `PLAN.md` — The implementation roadmap: Phases 0–10 with scope, dependencies, affected files, database changes, acceptance criteria, tests, security considerations, definition of done, and risks; plus cross-phase traceability and the post-MVP backlog.
- `AGENTS.md` — The coding-agent rulebook: mandatory onboarding order, the Prime Directive (inspect before you change), the Second Directive (no silent deviation), hard technology constraints, coding/database/monitoring/SSRF/security/notification/testing/documentation rules, anti-regression invariants, and escalation guidance.
- `CHANGELOG.md` — This changelog; the project's change history.
- `DECISIONS.md` — Architecture Decision Records (`ADR-001` … `ADR-020`) recording the rationale for the framework, UI, schema, detection, notification, status-page, and security choices.

### Notes

> **Status: documentation only.**
>
> - **No application code has been written yet.**
> - The repository currently contains **documentation only**.
> - **SiteSentinel is not yet functional.**
> - The monitoring engine, detection engine, incident system, notifications, and status page exist as **specifications only**.
> - Any statement in the documentation set describing behaviour is a **requirement or specification**, not a claim that it has been implemented.

Nothing in this release should be interpreted as implemented functionality. All implementation work is future work, sequenced by [`PLAN.md`](PLAN.md).

---

## [Unreleased]

### Added — Phase 7 (Notification delivery)

- Migrations `0001_07_01..04`: `notification_channels` (type email/telegram, `config` JSON, `secret_ref` encrypted, soft deletes), `website_notification_channel` pivot (`uq_website_notification_channel`), `notification_logs` (queued/sent/failed/suppressed, `dedupe_key`, no `updated_at`), `notification_cooldowns` (`cooldown_key` unique, `window_started_at`/`expires_at`/`suppressed_count`) — all match `DATABASE.md` §§3.13–3.16, additive only.
- Models `NotificationChannel` (encrypted `secret_ref` cast), `NotificationLog` (`UPDATED_AT=null`), `NotificationCooldown`; relations `Website::notificationChannels()` / `Incident::notificationLogs()`; absence rule (no pivot rows = all enabled globals) enforced in `NotificationDispatcher::resolveChannels()`.
- Provider contract `app/Contracts/NotificationProvider.php` (`NotificationProvider` + `NotificationPayload` + `DeliveryResult`); dispatcher `app/Services/Notifications/NotificationDispatcher.php` (severity → cooldown → dedupe gates, per-channel isolation, unknown-type fail-closed, bypass for opened/escalated/resolved); registry `NotificationProviderRegistry`; redactor `MessageRedactor`; circuit breaker `CircuitBreaker`.
- Jobs `DispatchIncidentNotifications` + `SendNotification` on `notifications` queue (`tries=3`, backoff 60/300/900s, 429 `retry_after` honored, permanent fail-then-throw to `failed_jobs`); intents `NotificationIntents` (after-commit enqueue only, `snapshotOpen`/`enqueueFromDiff` for opened/escalated/auto-resolved); hooks in `RunWebsiteCheck` (diff after incident reconcile, failure-isolated) and `IncidentController` acknowledge/resolve.
- Providers `Channels/EmailProvider.php` (SMTP via Laravel mailer, HTML+text Blade `emails/incident-*`, 4xx retryable vs 5xx permanent) and `Channels/TelegramProvider.php` (Bot API, HTML escape + plain fallback, 4096 cap preserving `admin_url`, per-chat Redis lock, 429/5xx/timeout retryable vs 401/403/400 permanent, token never logged).
- Admin UI routes `admin/notifications*` + `admin/notification-logs` (CRUD, test-send throttled `10,1`, delivery-log filters, per-website channel checkboxes with absence-rule copy, incident delivery-history section); Form Requests for channel + `channel_ids` validation; `secret_ref` never prefilled.
- Baseline comparison: no `notification_state` column (state derived from `notification_logs` queries); no `latency_ms` column (`DeliveryResult::$latency_ms` runtime-only); severity gate lives in `notification_channels.config.min_severity` (WARNING default); templates live in `resources/views/emails/` (not `resources/views/mail/`); `config/sentinel.php` adds only `notifications.default_cooldown_minutes` (retry keys fixed in code).

### Added — Phase 7 (Notification tests, Stage D step 4)

- `tests/Feature/Notifications/*` — 8 behavior-level suites, 49 tests: provider contract (registry resolution, ok/retryable/permanent routing, unknown-type fail-closed, bounded retries), incident-event intents (opened/escalated/manual+auto resolved/acknowledged, cooldown dedupe, after-commit dispatch with rollback suppression, idempotent replay), Email provider (recipient resolution, subject format, HTML+text bodies, 4xx vs 5xx classification, secret redaction), Telegram provider (request shape, HTML escaping, 4096 truncation preserving `admin_url`, 429 `retry_after`, timeout retryable, 401/403/400 permanent, no token in logs), suppression/cooldown (first allowed, 15 min suppression + `suppressed_count`, escalation/recovery bypass, new-incident key, exact clock boundary), idempotency/concurrency (double-job single send, per-channel isolation, identity key), queue failure isolation (monitoring never fails, bounded tries, `failed_jobs` visibility, partial success, no infinite loop), auth/redaction (guest/non-admin blocked on all routes/methods, secrets scrubbed, test-send throttled). All suites assert no-secrets + no-evidence (no keywords/domains/redirects/rule-ids/snapshots/bodies) in payloads and logs.

### Fixed — Phase 7 (test-driven, Stage D step 4)

- `App\Jobs\SendNotification` permanent-failure branch now rethrows after `$this->fail()`: `fail()` alone returns normally, so the worker deleted the job as succeeded and nothing ever reached `failed_jobs` (NOTIFICATIONS.md §12.3). The `failed_jobs_visible` test proves the dead-letter now lands in `failed_jobs`.
- `resources/views/errors/429.blade.php` no longer references an undefined `$message` variable (throttled responses previously blew up into a 500 `ViewException`); the page now renders static copy. Surfaced by the `test_send_throttled` test.

### Added

- Phase 0 — Repository & Architecture Bootstrap ([`PLAN.md`](PLAN.md)):
  - `config/sentinel.php` — configuration skeleton exposing retention (`checks` 30/60/90 default 30, `incidents` 365, `notification_logs` 90, `snapshots` 14), check defaults (interval 5 min, timeout 10 s), probe limits (connect 5 s, redirects cap 5, body 2 MB), scoring thresholds (`INFO` >= 1, `WARNING` >= 8, `CRITICAL` >= 15, correlation guard 2), notification cooldown (15 min), and auth throttle values — sourced from `PRD.md`, `SECURITY.md`, `NOTIFICATIONS.md`, `DETECTION-RULES.md`. Keys/values only, no logic (AC-0-02).
  - `docker-compose.yml` — services `nginx`, `app` (php-fpm), `scheduler`, `worker`, `mysql`, `redis`; persistent volumes for MySQL, Redis, and app storage; only Nginx exposed to the host; scheduler/worker out of the web request path per `ARCHITECTURE.md` §13 (AC-0-03).
  - `docker/app/Dockerfile` (PHP 8.4-fpm-alpine with pdo_mysql, redis, intl, mbstring, bcmath, pcntl) and `docker/nginx/default.conf`.
  - `.github/workflows/ci.yml` — CI skeleton with lint and test stages, explicit about the absence of an application test suite rather than silently passing (AC-0-04).
  - `pint.json` (Laravel Pint, PSR-12 base, `declare(strict_types=1)`) and `.editorconfig` (AC-0-05).
  - `.env.example` — documented placeholder keys only, no secret values (AC-0-06).
  - `README.md` — developer bootstrap pointer and project status.
  - `.gitignore` — Laravel/Vendor/IDE/OS baseline including `storage/app/snapshots/`.
- `DECISIONS.md` ADR-021 — Phase 0 bootstrap decision: Laravel scaffold deferred to Phase 1 (AC-0-01).
- `CONSISTENCY-AUDIT.md` — documentation-set consistency audit (17 findings fixed, 4 verified-no-issue).

### Added — Phase 1 (Application Foundation)

- Laravel 13.33 application scaffold on PHP 8.4 (framework tables, bootstrap, providers) per `PLAN.md` Phase 1 and ADR-021.
- `users` migration aligned to the frozen schema in `DATABASE.md` §3.1 (`role` default `admin`, `is_active` default 1, `last_login_at`); `password_reset_tokens`/`sessions` per §3.2–3.3; framework tables `jobs`, `failed_jobs`, `cache`, `cache_locks` per §8 order (AC-1-01).
- Redis wired as queue + cache driver (`predis` client); scheduler registered and visible via `schedule:list` (AC-1-02, AC-1-03).
- Hotwired Turbo + Tailwind CSS 4 + Alpine.js wired through Vite; base layout `resources/views/layouts/app.blade.php` with CSRF meta; production asset build verified via `npm run build` (AC-1-04).
- `/health` readiness endpoint (`app/Http/Controllers/HealthController.php`) reporting database, Redis, and queue status; secret-leak regression test included (AC-1-05).
- Safe 404/500 error pages; test asserts no stack traces or framework paths leak with `APP_DEBUG=false` (AC-1-06).
- Test suite (PHPUnit 12, 17 tests): base migrations vs frozen schema, queue round-trip through a real `queue:work` worker, health-endpoint contract, scheduler wiring, sentinel config defaults, error-page safety, asset manifest.
- `.github/workflows/ci.yml` expanded to Laravel reality: lint (Pint), test (SQLite + database queue in CI), and asset-build jobs.
- `DECISIONS.md` ADR-022 — local development environment: native PHP/SQLite/Predis, canonical production unchanged (MySQL 8 + Redis + Docker Compose).

### Changed — Phase 1

- `.env.example` — real runtime keys with SQLite local-development guidance; MySQL stays the documented default; `REDIS_CLIENT=predis`.
- `config/database.php` — default Redis client `phpredis` → `predis` (pure-PHP, no extension requirement; swappable via `REDIS_CLIENT`).
- `.gitignore` — ignore local SQLite database files.
- `README.md` — updated project status and local bootstrap instructions.

### Added — Phase 2 (Authentication & Admin Shell)

- Session login at `/` (`app/Http/Controllers/Auth/LoginController.php`) with the `SECURITY.md` §2.4 throttle model — soft limit 5 failures/15 min and lockout after 10 failures/30 min locking the identity for 15 min, keyed on `email` + source IP, HTTP 429 on throttled attempts, generic failure messaging for enumeration resistance, counters cleared on success, session ID regenerated on login (fixation defence), `last_login_at` stamped (AC-2-01).
- `/admin` authorization scaffold (`app/Http/Middleware/EnsureAdmin.php`): authenticated → `is_active` → `role = 'admin'`, enforced for every HTTP method; disabled accounts get their session invalidated and a 403 (AC-2-02).
- No public registration route anywhere — asserted by test; admin accounts are provisioned out of band via `php artisan sentinel:install-admin` (hidden password prompt, ≥ 12 chars from `config/sentinel.auth.min_password_length`, refuses duplicates, writes an `auth.admin_provisioned` audit row). `DatabaseSeeder` seeds nothing — no default-password admin (AC-2-03).
- Logout (`FR-07`): destroys the server-side `sessions` row, invalidates the store entry, regenerates the CSRF token, audited (AC-2-04).
- Password reset flow (`app/Http/Controllers/Auth/PasswordResetController.php`, `App\Mail\PasswordResetMail` plain-text mailable): 30-minute single-use tokens hashed at rest in `password_reset_tokens`, identical response for known/unknown emails, all other sessions invalidated on completion, audited request/failed/completed events (AC-2-05).
- Audit foundation: `audit_logs` table per `DATABASE.md` §3.19 (indexes `idx_audit_logs_user_id`, `idx_audit_logs_event_created_at`, `idx_audit_logs_created_at`; `created_at` only — rows are immutable), `App\Models\AuditLog`, and `App\Services\Audit\AuditLogger` as the single write path (best-effort: an audit failure is reported and never breaks the action being audited). Auth events audited with actor + timestamp + IP (AC-2-06).
- Admin shell: `resources/views/components/admin-layout.blade.php` (header nav + logout form) and `resources/views/admin/dashboard.blade.php` placeholder with Availability and Security & Content Health as two structurally separate sections (`aria-labelledby` anchors), Turbo-friendly (CSRF meta + Vite assets) (AC-2-07).

### Changed — Phase 2

- `routes/web.php` — `/` is now the login page (guest group), `/password-reset*` guest routes, `POST /logout` authed, `/admin` group under `auth` + `admin` middleware aliases; `/health` contract unchanged.
- `bootstrap/app.php` — registered the `admin` middleware alias and guest/user redirect targets.
- `app/Models/User.php` — `Fillable`/`Hidden` PHP attributes (`role`/`is_active` deliberately not mass-assignable), `isActive()`/`isAdmin()` helpers, `last_login_at`/`is_active` casts.
- `database/factories/UserFactory.php` — `role`/`is_active` defaults + `inactive()` state; `database/seeders/DatabaseSeeder.php` — no longer seeds a user.
- `config/sentinel.php` — `auth` section expanded to the full `SECURITY.md` §2.4 model (soft-limit window, lockout threshold/window/duration, min password length); `.env.example` — corresponding `SENTINEL_*` auth keys documented.
- `phpunit.xml` — suite unchanged (`array` session driver); the session-row contract is exercised per-test by switching to the real `database` driver.

### Planned

Implementation proceeds through the phases defined in [`PLAN.md`](PLAN.md):

- Phase 0 — Repository & Architecture Bootstrap
- Phase 1 — Application Foundation
- Phase 2 — Authentication & Admin Shell
- Phase 3 — Website Management
- Phase 4 — Monitoring Engine
- Phase 5 — Detection Engine
- Phase 6 — Incident Management
- Phase 7 — Notifications
- Phase 8 — Public Status Page
- Phase 9 — Security Hardening
- Phase 10 — Testing & Production Readiness

> **`[Unreleased]` becomes `1.0.0`.** The `[Unreleased]` section accumulates the changes from each phase merge and will be promoted to **`1.0.0`** when the first complete MVP is released.

---

## Versioning Policy

- This project uses **Semantic Versioning** (`MAJOR.MINOR.PATCH`).
- **`1.0.0` is reserved for the first complete MVP release** — the point at which all [`PRD.md`](PRD.md) acceptance criteria are met and [`PLAN.md`](PLAN.md) Phases 0–10 are complete.
- Versions below `1.0.0` (currently `0.1.0`) represent the documentation/bootstrap phase.
- **Each phase merge should append changelog entries** to the `[Unreleased]` section under the appropriate category, and move them under a new version heading when a release is tagged.
- Documentation-only changes are recorded under **Added**/**Changed**; code changes follow the normal categories.

---

*End of `CHANGELOG.md`.*
