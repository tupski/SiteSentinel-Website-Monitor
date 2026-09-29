# Changelog

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
