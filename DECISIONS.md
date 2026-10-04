# DECISIONS.md — SiteSentinel — Architecture Decision Records

> **Specification only.** Nothing described in this document has been implemented.
> Decisions are recorded for the future implementing agent. Table and column names referenced here
> are the frozen names defined in [`DATABASE.md`](DATABASE.md); component names are defined in
> [`ARCHITECTURE.md`](ARCHITECTURE.md). Product requirements defer to [`PRD.md`](PRD.md).

---

## ADR-001: Application framework = Laravel 13 (server-rendered)

**Status** — Accepted

**Context** — SiteSentinel needs a batteries-included PHP framework providing HTTP routing,
scheduler, queue, mail, and HTTP client in one coherent stack, deployable on a single small VPS.

**Decision** — The application will be built on **Laravel 13** with PHP 8.4+, using the
server-rendered Blade pipeline.

**Alternatives considered** — Symfony full-stack (more assembly required for scheduler/queue/mail),
a bespoke PHP application (unacceptable delivery risk), Node.js server frameworks (contradicts the
fixed canonical stack).

**Reason** — Laravel 13 supplies Scheduler, Queue, HTTP Client, Mail, and Eloquent out of the box,
which map directly onto the canonical spec and minimise the number of moving parts a small team
must maintain.

**Consequences** — *Positive:* fast delivery, idiomatic patterns, strong ecosystem.
*Negative:* framework-version coupling; Laravel 13 requires PHP 8.4+, constraining older hosting.

**Related** — [`PRD.md`](PRD.md) §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §2, §12.

---

## ADR-002: No SPA / no Inertia / no Livewire — Hotwired Turbo + Alpine

**Status** — Accepted

**Context** — The UI must be dynamic-feeling but the canonical spec forbids SPA frameworks and
HTML-over-the-wire alternatives that replace the server-rendered flow.

**Decision** — The UI will use **server-rendered Blade + Hotwired Turbo**, with **Tailwind CSS**
and **Alpine.js only where necessary**.

**Alternatives considered** — React (rejected — forbidden), Vue (rejected — forbidden), Next.js
(rejected — forbidden), Inertia (rejected — forbidden), Livewire (rejected — forbidden).

**Reason** — Turbo preserves the server-rendered model while giving SPA-like navigation, keeping
the mental model simple and the deployment free of a JS build-time SPA toolchain.

**Consequences** — *Positive:* fewer moving parts, faster first paint, no separate API contract.
*Negative:* highly interactive widgets need Alpine sprinkles; complex client state is deliberately
avoided.

**Related** — [`PRD.md`](PRD.md) §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §2, §3.

---

## ADR-003: Database = MySQL 8

**Status** — Accepted

**Context** — A relational store is needed for administrative entities and high-volume telemetry.

**Decision** — **MySQL 8** (InnoDB, utf8mb4) will be the only relational database.

**Alternatives considered** — SQLite (rejected — forbidden by canonical spec, unsuitable for
concurrent workers), PostgreSQL (rejected — forbidden by canonical spec), MongoDB (rejected — no
relational integrity for incident/state modelling).

**Reason** — MySQL 8 provides JSON columns, window functions, and mature operational tooling,
matching the canonical spec exactly.

**Consequences** — *Positive:* well-understood operations, JSON support for payload columns.
*Negative:* no native partial unique indexes, so the open-incident constraint is application-enforced
backed by `idx_incidents_website_status`.

**Related** — [`DATABASE.md`](DATABASE.md) §1, §3.10, §7.

---

## ADR-004: Redis for queue + cache

**Status** — Accepted

**Context** — The monitoring plane needs a fast queue and lock store separate from the web plane.

**Decision** — **Redis** will back the Laravel Queue and the cache/lock layer.

**Alternatives considered** — Database queue driver (slower, adds DB load under high check volume),
file cache (unsuitable for cross-container locks), SQS (adds a cloud dependency contradicting the
self-hosted single-VPS model).

**Reason** — Redis gives atomic locks (needed to prevent double-dispatch) and sub-millisecond queue
operations, both critical for the monitoring plane.

**Consequences** — *Positive:* fast dispatch, native locking, simple Compose service.
*Negative:* one more stateful service to back up; sessions were therefore put in MySQL to survive
Redis restarts.

**Related** — [`ARCHITECTURE.md`](ARCHITECTURE.md) §5, §12, §13; [`DATABASE.md`](DATABASE.md) §3.3.

---

## ADR-005: Scheduler-driven dispatch + queue workers, never in-request monitoring

**Status** — Accepted

**Context** — Probing external sites is slow and unreliable; doing it inside an HTTP request would
couple user latency to third-party availability.

**Decision** — Laravel Scheduler will select due websites and enqueue jobs onto the `monitoring`
queue; **queue workers** will perform all probing. The web plane will never probe inline.

**Alternatives considered** — Probing in the request cycle (rejected — blocks the UI), system
cron + shell scripts (rejected — loses Laravel retry/queue semantics), external uptime SaaS
(rejected — contradicts self-hosted core concept).

**Reason** — This is the architectural expression of the canonical rule that the monitoring plane
is separate from the web/UI plane.

**Consequences** — *Positive:* UI stays fast; probes retry independently; workers scale horizontally.
*Negative:* results are eventually consistent — the UI shows last-known state, not live state.

**Related** — [`ARCHITECTURE.md`](ARCHITECTURE.md) §2, §5, §12.

---

## ADR-006: External HTTP probing as the monitoring approach (not agent/plugin based)

**Status** — Accepted

**Context** — Monitoring could be agent-based (install something on the target) or external
(probe from outside).

**Decision** — Monitoring will be **external HTTP probing** — fetching the target URL over the
network from SiteSentinel's own infrastructure.

**Alternatives considered** — Agent/plugin installed on the target host (rejected — requires
target cooperation, contradicts "external"), ICMP-only (rejected — no content/security signal),
browser-based synthetic monitoring at MVP (deferred — heavyweight; screenshot is Phase 2).

**Reason** — External probing matches the core concept "External Website Monitoring + Basic Website
Compromise Detection" and needs zero cooperation from monitored sites.

**Consequences** — *Positive:* works on any site; detects defacement as the user sees it.
*Negative:* cannot see server internals; false positives from CDN/geo variability must be handled
by the baseline and scoring model.

**Related** — [`PRD.md`](PRD.md) §2, §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §5.

---

## ADR-007: Two-dimensional status model (Availability vs Security)

**Status** — Accepted

**Context** — A site can return HTTP 200 while serving injected or altered content. A single
"up/down" state cannot express this. This is the central product decision.

**Decision** — Status will be modelled as **two orthogonal dimensions**:
Availability `UP|DOWN`, and Security `OK|INFO|SUSPECT|INCIDENT`.

**Alternatives considered** — Single combined health score (rejected — loses information and
conflates reachability with integrity), three-state up/degraded/down (rejected — still one axis),
security as a sub-flag of down (rejected — a compromised-but-reachable site would read as "up").

**Reason** — The canonical rule states **HTTP 200 != healthy** and that availability and
security/content health are separate, orthogonal dimensions.

**Consequences** — *Positive:* a defaced but reachable site reads as UP + INCIDENT, which is the
product's entire value. *Negative:* every UI surface, status page projection, and incident model
must carry both dimensions consistently.

**Related** — [`PRD.md`](PRD.md) §10, §11, §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §6;
[`DATABASE.md`](DATABASE.md) §3.4, §3.6.

---

## ADR-008: Rule-based detection with weighted correlation scoring (not ML, not naive keyword matching)

**Status** — Accepted

**Context** — Compromise detection must be explainable and deployable on a small VPS without
training data.

**Decision** — Detection will use a **rule registry** (`detection_rules`) producing per-rule signals
with a category, severity, and weight, aggregated by a **scoring correlator** into a total score.
Thresholds are fixed: `INFO >= 1`, `WARNING >= 8`, `CRITICAL >= 15`.

**Alternatives considered** — Machine learning (rejected — no labelled data, unexplainable,
operationally heavy), naive keyword matching with binary alarm (rejected — the canonical spec
explicitly forbids equating a keyword with a compromise), third-party scanner integration
(deferred — out of MVP scope).

**Reason** — Weighted rules are explainable to the admin ("these rules, these categories, this
score"), tuneable per website via `website_rule_settings`, and cheap to run.

**Consequences** — *Positive:* transparent, tuneable, no training pipeline. *Negative:* rule
maintenance burden; weights need calibration as false-positive feedback arrives.

**Related** — [`PRD.md`](PRD.md) §11, §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §6;
[`DATABASE.md`](DATABASE.md) §3.8, §3.9.

---

## ADR-009: Correlation guard — no CRITICAL security incident from a single signal

**Status** — Accepted

**Context** — Any single heuristic can misfire; escalating to CRITICAL on one signal would cause
alert fatigue and erode trust.

**Decision** — A **CRITICAL security escalation requires >= 2 independent signal categories**.
With only one category, the score will be capped below CRITICAL regardless of total weight.

**Alternatives considered** — Score-only classification (rejected — one heavy rule could trigger
CRITICAL), single-rule whitelist exceptions (rejected — reintroduces the same risk), manual admin
confirmation for every critical (rejected — defeats automation).

**Reason** — The canonical spec fixes the correlation guard at >= 2 independent categories; this
is the concrete expression of "never assert compromise from a single signal".

**Consequences** — *Positive:* dramatically fewer false criticals. *Negative:* a genuine
single-signal compromise may surface as WARNING first, delaying escalation until a second category
fires.

**Related** — [`PRD.md`](PRD.md) §11.4, §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §6.

---

## ADR-010: Provider-independent notification dispatcher

**Status** — Accepted

**Context** — Delivery mechanisms will change (new channels, different providers) and incident
logic must not be coupled to any one of them.

**Decision** — Notifications will go through a **dispatcher** that receives incident events and
fans out to channel implementations behind a common interface, with dedupe/cooldown gating and
delivery logging.

**Alternatives considered** — Calling Mail/Telegram APIs directly from the incident engine
(rejected — couples core logic to providers), a third-party notification SaaS (rejected — external
dependency and data egress), hardcoding a single channel (rejected — blocks Future channels).

**Reason** — Isolating delivery lets Email/Telegram ship at MVP and WhatsApp/Webhook be added later
without touching the incident engine.

**Consequences** — *Positive:* new channels are additive; retries and logging are centralised.
*Negative:* an extra abstraction layer and a fan-out queue step.

**Related** — [`ARCHITECTURE.md`](ARCHITECTURE.md) §3, §8; [`DATABASE.md`](DATABASE.md) §3.13–3.16.

---

## ADR-011: MVP notification channels = Email + Telegram

**Status** — Accepted

**Context** — The MVP needs at least two channels with different transports.

**Decision** — MVP channels will be **Email** and **Telegram**. **WhatsApp** and **Webhook** are
Future and must not be built now.

**Alternatives considered** — Email only (rejected — no instant push), SMS (rejected — cost and
provider complexity), Slack/Discord (considered — can be expressed later as a Webhook channel),
WhatsApp at MVP (rejected — Future per spec), Webhook at MVP (rejected — Future per spec).

**Reason** — Email is universal; Telegram gives fast push; both are cheap and simple. The channel
`type` enum is intentionally extensible for the Future set.

**Consequences** — *Positive:* two independent transports at launch; secrets stay in
`secret_ref` encrypted fields. *Negative:* Telegram requires a bot token and chat setup per admin.

**Related** — [`PRD.md`](PRD.md) §22; [`DATABASE.md`](DATABASE.md) §3.13, §3.15.

---

## ADR-012: Incident lifecycle DETECTED/ACKNOWLEDGED/RESOLVED with terminal resolve + new incident on recurrence

**Status** — Accepted

**Context** — Incidents need an unambiguous state model that preserves history for audit.

**Decision** — Lifecycle will be exactly `DETECTED -> ACKNOWLEDGED -> RESOLVED`. `RESOLVED` is
**terminal**. Recurrence opens a **new** `incidents` row. Auto-resolution will require **sustained
recovery** and be recorded with `resolution_mode = auto`.

**Alternatives considered** — Reopening resolved incidents (rejected — destroys the audit trail),
adding a MONITORING/INVESTIGATING state (rejected — the canonical lifecycle forbids extra states),
closing on first successful check (rejected — flapping would spam close/reopen cycles).

**Reason** — A terminal resolved state plus new-incident-on-recurrence gives clean, append-only
incident history, which matches the canonical lifecycle.

**Consequences** — *Positive:* immutable history; unambiguous metrics. *Negative:* a relapse
creates a new incident, so "recurring problem" analytics must group by `website_id` + `dedupe_key`.

**Related** — [`PRD.md`](PRD.md) §12.1, §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §7;
[`DATABASE.md`](DATABASE.md) §3.10, §3.11.

---

## ADR-013: Status page visibility model + public information redaction

**Status** — Accepted

**Context** — The status page may be exposed publicly, but security findings are sensitive and
could tell an attacker what was detected.

**Decision** — Visibility will be `Private | Public | Password Protected` via
`status_page_settings.visibility_mode`. All modes will render through a **public-safe projection**
that strips security detail, rule names, scores, technical metadata, and snapshot artifacts.

**Alternatives considered** — Always public (rejected — leaks security posture), always private
(rejected — the product needs a shareable status view), exposing security state on the public page
(rejected — canonical rule forbids exposing security detail).

**Reason** — Availability can be public without risk; compromise detail cannot. Redaction at the
projection layer enforces this once rather than per-view.

**Consequences** — *Positive:* safe sharing; one redaction chokepoint. *Negative:* public users get
a coarser view; redaction logic must be regression-tested to avoid accidental leakage.

**Related** — [`PRD.md`](PRD.md) §14.4, §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §10;
[`DATABASE.md`](DATABASE.md) §3.18.

---

## ADR-014: SSRF defense-in-depth with per-hop DNS revalidation

**Status** — Accepted

**Context** — SiteSentinel fetches URLs that an admin supplies. Without guards, it becomes an SSRF
proxy into the internal network and cloud metadata endpoints.

**Decision** — Every hop will pass a layered guard: scheme allowlist (`http`/`https` only); block
localhost/loopback/private/link-local/unique-local ranges and cloud metadata endpoints including
`169.254.169.254`; **DNS re-resolved on every redirect hop**; per-hop redirect target validation;
and a hop cap.

**Alternatives considered** — Validating only the initial URL (rejected — redirects bypass it),
disabling redirects entirely (rejected — redirects are themselves a monitored signal), a forward
proxy with network-level egress filtering (considered later — defense in depth, but not a
substitute for application-layer checks), single-resolution then reuse (rejected — TOCTOU/rebinding
window).

**Reason** — The canonical spec mandates layered SSRF protection including DNS re-resolution on
**every** redirect hop; revalidating per hop closes the DNS-rebinding window between validation and
connection.

**Consequences** — *Positive:* blocks the classic rebinding and redirect-bypass attacks.
*Negative:* extra DNS lookups per hop (latency) and a stricter blocklist that can reject legitimate
targets hosted on private ranges.

**Related** — [`PRD.md`](PRD.md) §15.4, §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §11.

---

## ADR-015: Snapshot storage strategy (filesystem) and screenshot deferral

**Status** — Accepted

**Context** — Incidents need evidence. HTML and response headers are MVP; screenshots are not.

**Decision** — HTML snapshots will be stored on the **filesystem**, with the path recorded in
`snapshots.html_path`; response headers, final URL, keywords, external links, and redirect chain
will be stored as JSON columns in `snapshots`. Screenshots are **Phase 2/Future**.

**Alternatives considered** — HTML stored as a DB BLOB (rejected — bloats MySQL, slows
backup/pruning), object storage like S3 (deferred — adds an external dependency at MVP),
screenshots at MVP via headless browser (rejected — explicitly Future, and heavy on a small VPS).

**Reason** — Filesystem storage keeps MySQL lean and lets retention delete files directly while
still keeping structured evidence queryable in the row.

**Consequences** — *Positive:* small database, cheap pruning, easy to serve evidence. *Negative:*
two backup targets (DB + snapshot volume); orphaned files possible if the file delete and row
delete diverge, so pruning will delete file first, then row.

**Related** — [`PRD.md`](PRD.md) §22; [`DATABASE.md`](DATABASE.md) §3.12, §4;
[`ARCHITECTURE.md`](ARCHITECTURE.md) §12.

---

## ADR-016: Data retention windows and pruning strategy

**Status** — Accepted

**Context** — Telemetry grows unbounded and would eventually overwhelm a small VPS.

**Decision** — Retention will be fixed at **checks 30d** (configurable 30/60/90),
**incidents 365d**, **notification logs 90d**, **snapshots 14d**. A scheduled maintenance job will
delete in bounded batches using `created_at`/`expires_at` indexes. **No partitioning at MVP.**

**Alternatives considered** — Keep everything (rejected — unbounded growth), aggressive 7-day
checks retention (rejected — loses incident correlation context), partitioning by month
(rejected — premature at MVP scale), archival to cold storage (deferred — Future).

**Reason** — These windows match the canonical spec exactly and the growth estimate shows `checks`
staying under ~1M rows at 30d for ≤100 sites, so batch deletes are sufficient.

**Consequences** — *Positive:* predictable storage, cheap queries. *Negative:* evidence older than
the window is gone; admins cannot investigate very old incidents at full fidelity.

**Related** — [`PRD.md`](PRD.md) §22; [`DATABASE.md`](DATABASE.md) §4, §6;
[`ARCHITECTURE.md`](ARCHITECTURE.md) §12.

---

## ADR-017: Docker Compose single-VPS deployment topologies

**Status** — Accepted

**Context** — The system must be self-hosted and simple to run.

**Decision** — Deployment will be **Docker Compose** with services `nginx`, `app` (php-fpm),
`worker`, `scheduler`, `mysql`, `redis`; only Nginx exposed; persistent volumes for MySQL, Redis,
and snapshots.

**Alternatives considered** — Kubernetes (rejected — overkill for a small VPS), bare-metal
systemd + manual PHP install (rejected — drift and harder onboarding), a managed PaaS (rejected —
contradicts self-hosted model and adds cost), a single all-in-one container (rejected — the
scheduler and workers must be separable processes).

**Reason** — Compose gives reproducible single-host deployment while keeping the scheduler/worker
out of the web request path.

**Consequences** — *Positive:* one-command bring-up, easy local parity. *Negative:* single-host
failure domain; scaling requires manual worker replicas; MySQL and Redis compete for RAM on a small
VPS.

**Related** — [`ARCHITECTURE.md`](ARCHITECTURE.md) §13.

---

## ADR-018: Authentication — session-based admin-only, no public registration

**Status** — Accepted

**Context** — Only trusted operators should reach the admin area; there is no end-user population.

**Decision** — Authentication will be **session-based**, admin-only, with **no public registration**;
admins are provisioned out of band. Login will be throttled, sessions regenerated on login, CSRF
enforced on state-changing requests, passwords stored with a modern one-way hash, and all admin
routes placed behind an admin gate. Sessions will be stored in the `sessions` table.

**Alternatives considered** — Public registration with roles (rejected — no self-serve users at
MVP), API tokens / `personal_access_tokens` (rejected — no API surface at MVP, hence N/A),
OAuth/social login (rejected — adds external dependency), Redis session driver (considered —
rejected so sessions survive Redis restarts and remain auditable in MySQL).

**Reason** — Session auth matches the server-rendered architecture and the Admin-only role in the
canonical spec, with the least attack surface.

**Consequences** — *Positive:* small surface, auditable sessions. *Negative:* admin provisioning
is manual; database sessions add a write per request.

**Related** — [`PRD.md`](PRD.md) §22; [`ARCHITECTURE.md`](ARCHITECTURE.md) §9;
[`DATABASE.md`](DATABASE.md) §3.1–3.3.

---

## ADR-019: Content fingerprinting approach (algorithm + normalization)

**Status** — Accepted

**Context** — Content-change detection needs a stable fingerprint that ignores benign churn
(nonces, CSRF tokens, timestamps, cache-busters) yet catches injections.

**Decision** — The fingerprint will use **SHA-256** over a **normalized** body: HTML parsed and
serialized deterministically, volatile tokens stripped (CSRF/nonce/session ids, timestamps,
random query strings, cache-buster comments), whitespace collapsed, and dynamic fragments
normalized. The algorithm will be recorded in `website_baselines.hash_algorithm` so it can evolve.
Stored in `checks.content_hash` and `website_baselines.content_hash` as `CHAR(64)`.

**Alternatives considered** — Raw-body MD5 (rejected — weak and defeats normalization), raw-body
SHA-256 (rejected — every dynamic page would look changed), simhash/MinHash near-duplicate
detection (deferred — useful for fuzzy change scoring, Phase 2), diffing full bodies (rejected —
storage cost), ignoring content entirely (rejected — defeats compromise detection).

**Reason** — Normalization plus a strong hash gives a stable exact-change signal that rules and
baselines can compare, while the volatility strip list keeps false positives manageable.

**Consequences** — *Positive:* idempotent comparisons, small storage. *Negative:* normalization
rules must be maintained; a missed volatile pattern causes repeated false "changed" signals, while
over-aggressive stripping could mask small injected changes.

**Related** — [`ARCHITECTURE.md`](ARCHITECTURE.md) §3, §6; [`DATABASE.md`](DATABASE.md) §3.5, §3.6.

---

## ADR-020: Baseline model — immutable baseline versions

**Status** — Accepted

**Context** — Change detection needs a reference state, and admins must be able to trust and audit
what "normal" was at any point in time.

**Decision** — `website_baselines` rows will be **immutable, versioned** snapshots; exactly one per
website is marked `is_active = 1`, referenced by `websites.current_baseline_id`. Rebasing will
insert a **new** version and flip the active pointer rather than mutating the existing row.

**Alternatives considered** — A single mutable baseline row per website (rejected — no history, so
an admin cannot tell what changed or roll back), no baseline and compare only to the previous check
(rejected — one-off noise would be misread as change), storing the baseline only in
`websites` columns (rejected — no versioning, wide and messy), fingerprint-only baselines with no
metadata (rejected — loses title/SSL/link context needed by rules).

**Reason** — Immutability makes drift auditable, allows safe rebasing, and gives the detector a
stable comparison target with full metadata context.

**Consequences** — *Positive:* full baseline history, safe rebase, deterministic detection.
*Negative:* extra rows per website over time and a pointer to keep consistent
(`websites.current_baseline_id`); old versions are retained indefinitely and may need a Future
cleanup policy.

**Related** — [`ARCHITECTURE.md`](ARCHITECTURE.md) §3, §6; [`DATABASE.md`](DATABASE.md) §3.4, §3.5, §4.

---

## ADR-021: Phase 0 bootstrap — scaffold deferred to Phase 1

**Status** — Accepted

**Context** — [`PLAN.md`](PLAN.md) Phase 0 requires a decision: run `composer create-project`
Laravel 13 immediately, or establish only the repository foundation (container topology,
`config/sentinel.php`, coding standards, CI skeleton) and defer the framework scaffold to Phase 1.
The repository was docs-only at Phase 0 start.

**Decision** — **Defer the Laravel scaffold to Phase 1.** Phase 0 delivers only
`config/sentinel.php` (keys/values, no logic), `docker-compose.yml` plus `docker/` build files,
`.env.example`, `pint.json`, `.editorconfig`, and the CI skeleton. No vendor code, no framework
bootstrap, no migrations. Phase 1 runs `composer create-project` for Laravel 13 on PHP 8.4+ and
wires the framework onto this foundation.

**Alternatives considered** — Scaffolding now (rejected for Phase 0: it pulls the full vendor tree
and framework defaults into a phase whose scope is explicitly "no application business code",
blurring the phase boundary and making AC-0 verification harder); committing a scaffold without
`vendor/` but with framework files (rejected — creates a half-state where CI must already understand
Laravel before Phase 1 exists); a manual micro-framework bootstrap (rejected — contradicts ADR-001).

**Reason** — Keeping Phase 0 vendor-free gives a clean, reviewable foundation commit, keeps CI green
without an application ("no tests yet" is explicit, not a silent pass), and lets Phase 1 begin from
a known-good `composer create-project` baseline rather than a hand-assembled hybrid.

**Consequences** — *Positive:* sharp phase boundary; no framework lock-in baked in before the
topology is reviewed; CI runs trivially on a docs-plus-config repo. *Negative:* `config/sentinel.php`
cannot be integration-tested through a booted framework until Phase 1 (Phase 0 covers it with a
plain-PHP load check in CI); the compose topology is validated by inspection + `docker compose
config` rather than a live bring-up.

**Related** — [`PLAN.md`](PLAN.md) Phase 0, Phase 1; ADR-001; [`ARCHITECTURE.md`](ARCHITECTURE.md) §13.

---

## ADR-022: Local development environment — native PHP, SQLite, Predis

**Status** — Accepted

**Context** — Docker is not installed on the development machine, and no local MySQL server is
available. Phase 1 requires a runnable application and executable verification
([`PLAN.md`](PLAN.md) Phase 1 tests). Redis is available as a local native binary.

**Decision** — Local development and testing run on **native PHP (8.4) + SQLite
(`database/database.sqlite`) + Redis via the pure-PHP `predis` client**. The canonical production
stack is **unchanged: MySQL 8 (InnoDB, utf8mb4) + Redis + Docker Compose**
(ADR-003, ADR-004, ADR-017, [`ARCHITECTURE.md`](ARCHITECTURE.md) §13). `docker-compose.yml`,
`docker/`, and the MySQL default in `.env.example` remain authoritative for production. CI runs
tests on SQLite with the database queue driver because CI hosts have no MySQL/Redis services;
this is a CI environment substitution, not a stack change.

**Alternatives considered** — Installing Docker/MySQL locally (rejected — explicitly out of scope
for this machine), switching the canonical database to SQLite (rejected — contradicts the canonical
spec and `ADR-003`), using `phpredis` locally (rejected — the extension is absent; `predis` is
pure-PHP and swappable via `REDIS_CLIENT`).

**Reason** — The application and migrations stay portable (Laravel schema builder targets both
engines), verification is executable everywhere, and the production architecture is not weakened.

**Consequences** — *Positive:* every Phase 1 acceptance criterion is verifiable locally; no
container dependency. *Negative:* MySQL-specific schema behaviour (e.g. `ENUM` columns stored as
`VARCHAR`, collation) is **not** exercised locally and must be verified against a real MySQL 8
instance before production — tracked as an explicit environment limitation in phase reports; CI
cannot verify the Redis-backed queue path.

**Related** — [`PLAN.md`](PLAN.md) Phase 1; ADR-003, ADR-004, ADR-017; [`DATABASE.md`](DATABASE.md) §8.

---

## ADR-023: Admin provisioning via install command; no registration, no default-password seeder

**Status** — Accepted (Phase 2)

**Context** — [`PLAN.md`](PLAN.md) Phase 2 requires admin account provisioning while `SECURITY.md` §2.3
and `PRD.md` §15.3 mandate that no public registration route exists, and the phase's risk notes flag
"seeding an Admin with a known default password" as a security defect. A decision was needed on how the
first admin account comes into existence.

**Decision** — Admins are provisioned **out of band** via `php artisan sentinel:install-admin`
(interactive hidden password prompt or explicit `--password` for scripted installs; minimum length from
`config/sentinel.auth.min_password_length`; refuses duplicate emails; writes an `auth.admin_provisioned`
row to `audit_logs`). `DatabaseSeeder` seeds **nothing**. There is no `register` route, controller, or
view. The Laravel test suite uses `UserFactory` exclusively — factories are test tooling, not a runtime
provisioning path.

**Consequences** — *Positive:* no enumeration surface, no default credentials can ever leak, provisioning
is audited by construction. *Negative:* first-run deployment requires one manual artisan command (documented
in the release runbook, Phase 10); scripted environments must pass `--password` through a secret store, not
the command line history.

**Related** — [`PLAN.md`](PLAN.md) Phase 2; [`SECURITY.md`](SECURITY.md) §2.3, §2.4; [`PRD.md`](PRD.md) §15.3; ADR-018.

---

## ADR-024: Login lockout implemented as a dedicated RateLimiter lock marker

**Status** — Accepted (Phase 2)

**Context** — `SECURITY.md` §2.4 distinguishes a soft limit (5 failures / 15 min → HTTP 429) from a
lockout (10 failures / 30 min → identity locked for exactly 15 minutes). A naive implementation reuses
the failure counter's decay window as the lock duration, which would release the lock after 30 minutes
(the window) instead of 15 (the specified duration), or couple the lock to counter arithmetic.

**Decision** — `LoginController` keeps three `RateLimiter` keys per throttle key (`email|ip`): `:soft`
(failure counter, 15-min decay), `:lockout` (failure counter, 30-min decay), and `:locked` (a lock marker
hit once with the configured `lockout_duration_minutes` decay). The marker is armed when the `:lockout`
counter crosses `sentinel.auth.lockout_threshold`; while the marker exists all attempts get 429 with
`availableIn` reporting the true remaining lock time. Successful login clears all three keys
(§2.4 "Reset"). All values come from `config/sentinel.php` `auth` — no magic numbers in the controller.

**Consequences** — *Positive:* lock semantics match the spec exactly and are tunable without code changes.
*Negative:* one extra cache key per throttle key; the counters keep counting during a lock (harmless —
attempts are rejected before credential verification).

**Related** — [`PLAN.md`](PLAN.md) Phase 2; [`SECURITY.md`](SECURITY.md) §2.4; ADR-022 (cache store: Redis in
production, `array`/database in local tests).

---

## ADR-025: Permanent notification failures fail-then-throw so the worker dead-letters

**Status** — Accepted (Phase 7, test-driven fix in Stage D step 4)

**Context** — [`NOTIFICATIONS.md`](NOTIFICATIONS.md) §12.3 requires exhausted/permanent delivery attempts to
land in `failed_jobs` and stay visible. `App\Jobs\SendNotification` called `$this->fail()` and returned
normally on permanent (`retryable = false`) results. A returned-normally job is deleted by the worker as
succeeded, so the `failed` log row existed but `failed_jobs` stayed empty — permanent provider failures
vanished from the queue's dead-letter record (`QueueFailureIsolationTest::test_failed_jobs_visible` failed).

**Decision** — The permanent branch calls `$this->fail($error)` and then throws the same error, so the
worker records the dead-letter in `failed_jobs` via its `JobFailed` listener. No retry semantics change:
retryable results still `release()` with backoff, and per-channel isolation is untouched.

**Consequences** — *Positive:* dead-letters are observable in `failed_jobs` as specified; direct `handle()`
callers (tests, sync queue) now see the throw and must catch it. *Negative:* none — the throw carries the
same redacted error already persisted to `notification_logs`.

**Related** — [`PLAN.md`](PLAN.md) Phase 7; [`NOTIFICATIONS.md`](NOTIFICATIONS.md) §12; ADR-010.

---

## ADR-026: 429 error page renders static copy (no `$message` variable)

**Status** — Accepted (Phase 7, test-driven fix in Stage D step 4)

**Context** — `resources/views/errors/429.blade.php` interpolated `{{ $message }}`, a variable the
throttle-exception renderer never provides. Every throttled response therefore blew up into a 500
`ViewException`, masking the 429 contract (`AuthRedactionTest::test_test_send_throttled` observed
`302 × 10` then `500, 500` instead of 429s).

**Decision** — The 429 page renders static copy ("You have made too many requests…"). No dynamic
exception data is echoed on error pages (consistent with the Phase 1 no-leak contract).

**Consequences** — *Positive:* throttled clients get a proper 429 page. *Negative:* none.

**Related** — [`PLAN.md`](PLAN.md) Phase 7; `ErrorPageTest` (AC-1-06).

---

## ADR-027: Phase 7 notification implementation amendments

**Status** — Accepted (Phase 7 Stage E)

**Context** — [`PLAN.md`](PLAN.md) Phase 7 lists provider paths as `app/Services/Notifications/Providers/*` while [`ARCHITECTURE.md`](ARCHITECTURE.md) §3 lists `Dispatcher.php` + `Channels/*`. Code ships contract `app/Contracts/NotificationProvider.php`, dispatcher `app/Services/Notifications/NotificationDispatcher.php`, providers `app/Services/Notifications/Channels/*`, plus `NotificationProviderRegistry`, `NotificationIntents`, `MessageRedactor`, `CircuitBreaker`, jobs `DispatchIncidentNotifications` / `SendNotification`.

**Decision**

- Open-q3 closed as binding: absence of `website_notification_channel` rows means all enabled global channels (`DATABASE.md` §3.14, `NOTIFICATIONS.md` §13.1, `NotificationDispatcher::resolveChannels()`).
- Path ratification: PLAN wins over old ARCHITECTURE names. Canonical paths are `app/Contracts/NotificationProvider.php`, `app/Services/Notifications/NotificationDispatcher.php`, `app/Services/Notifications/Channels/*`. ARCHITECTURE §3/§8 updated in same change.
- Templates live in `resources/views/emails/` (HTML+text Blade pair), not `resources/views/mail/`.
- Severity gate lives in `notification_channels.config.min_severity` (WARNING default, WARNING/CRITICAL allowed), checked before cooldown/dedupe.
- No `notification_state` column: delivery state derived from `notification_logs` queries per incident/channel.
- No `latency_ms` column: `DeliveryResult::$latency_ms` is runtime-only per `NOTIFICATIONS.md` §11.1 note.

**Consequences** — *Positive:* single canonical path set; absence rule unambiguous. *Negative:* none.

**Related** — [`PLAN.md`](PLAN.md) Phase 7; [`DATABASE.md`](DATABASE.md) §§3.13–3.16; [`NOTIFICATIONS.md`](NOTIFICATIONS.md) §§2–4, 9, 11–13; ADR-010.

---

## ADR-028: Phase 8 status-page test-driven amendments (empty projection + unlock error shape)

**Status** — Accepted (Phase 8 Stage C tests)

**Context** — Authoring the mandatory redaction/derivation/unlock regression tests
(STATUS-PAGE.md §12) surfaced two observable behaviours that did not match the
spec's own acceptance criteria and could not be asserted without a change:

1. **Empty projection banner.** `StatusProjector::banner()` seeded its running
   worst-label at `Operational`, so a page with **no published services** returned
   `banner = "Operational"`. STATUS-PAGE.md §6.3 ("No fabrication") states the
   projection MUST NOT invent a label not derivable from the inputs; an empty set
   has no derivable status.
2. **Wrong-password error shape.** `StatusPageController::unlock()` returned
   `back()->withErrors(...)`, which yields a **302 redirect** for JSON clients.
   STATUS-PAGE.md §3.4 / §12.1(3) require a wrong password to be rejected
   uniformly with **no data ever rendered**; a JSON client should receive a
   deterministic error status (422), matching the existing missing/empty-password
   path already produced by `$request->validate(...)`.

**Decision**

- `StatusProjector::banner()` returns `Unknown` when the published-service set is
  empty (never `Operational`). No other ranking behaviour changes.
- `StatusPageController::unlock()` throws `ValidationException::withMessages(['password' => 'Incorrect password.'])`
  instead of `back()->withErrors(...)`. This preserves the browser contract
  (302 redirect back with form errors) **and** gives JSON clients a uniform 422 —
  the same shape as a missing/empty password — so no service data is ever
  rendered on a failed unlock.

**Alternatives considered** — Leaving the empty banner as `Operational` (rejected —
violates §6.3 and would mislead visitors); returning a bespoke `403` for JSON
unlock failures (rejected — inconsistent with the validation error path and the
required uniform error shape).

**Consequences** — *Positive:* the spec's own acceptance criteria are now directly
assertable; empty and failed-unlock responses are non-fabricating and uniform.
*Negative:* none observable — both are fail-closed, data-free responses.

**Related** — [`STATUS-PAGE.md`](STATUS-PAGE.md) §3.4, §6.3, §12.1, §12.2;
ADR-013.

---

## ADR-029: Phase 8 status-page implementation — concrete choices frozen from the spec's open numerics

**Status** — Accepted (Phase 8 implementation)

**Context** — [`STATUS-PAGE.md`](STATUS-PAGE.md) fixed the *behaviour* of the status page but left
several numeric and storage choices open ("recommended", "not fixed at MVP", "if implemented"). The
Phase 8 implementation had to choose concrete values. This ADR records them so the code and the spec
cannot drift silently; each choice stays within the existing redaction boundary
([`PRD.md`](PRD.md) §14.4, ADR-013) and adds no product feature.

**Decision** — the following are frozen:

| Area | Choice | Rationale |
| --- | --- | --- |
| **Publish columns** | `websites.is_visible_on_status` (bool, default `0`) + `websites.status_alias` (nullable `VARCHAR(255)`) + `idx_websites_visible_status`, rather than a join table | One-to-one display mapping; keeps the projector a single indexed query (`FR-83`) |
| **Stale floor** | `max(stale_multiplier(2) × check_interval_seconds, stale_floor(300 s))` | Bounded multiple of cadence with an absolute floor so a very short interval cannot mark a site stale instantly; missing `last_checked_at` is always stale (§6.4) |
| **Unlock throttle** | 5 attempts / 10 minutes, keyed `status-unlock:{ip}:{sessionId}`; `429` + `Retry-After`; clear on success | Per-IP + per-session limits password guessing without a global lockout (§3.4) |
| **Rotation mechanism** | Unlock stamp = `v1:{status_page_settings.updated_at}` compared with `hash_equals`; any settings save advances `updated_at` and revokes all unlocks | No extra version column; a password/settings change revokes access immediately (§3.2, §3.5) |
| **History** | Default **off**; gated by `sentinel.status_page.history_enabled`; **not persisted** (no schema column) | Frozen §3.18 schema has no history column; MVP ships the spec's optional history off (§7.2) |
| **Slug** | **Storage-only** — validated/persisted, not used for routing, not rendered | Custom path is `FR-84` Future; routing stays `/status` |
| **Response bands** | `<= 800 ms` → `normal`; `<= 2500 ms` → `slow`; `> 2500 ms` → `slow`; omitted when no timing | Coarse band only, never the exact ms figure (§4.3) |
| **Service count** | **Not published** anywhere in HTML or JSON | The count is reconnaissance (§10.2) |
| **`status.json`** | **Included** — same redacted DTO as HTML | Cheap machine-readable surface with no extra data |
| **Singleton resolution** | `firstOrCreate(['id' => 1])` | No seeder dependency; the table always has its logical row (DATABASE §3.18) |
| **Cache** | DTO-only, key `status:projection:v1:{sha1(mode)}:{updated_at}`, TTL `max(60, shortest published interval)`; busted post-commit | Redaction runs once; key carries no row identifier (§9.1, §9.2) |

**Alternatives considered** — A separate `status_page_websites` join table (rejected: heavier for a
pure display mapping); a `history_enabled` column on `status_page_settings` (rejected: the frozen
§3.18 schema is authoritative and additive-only, and history is optional); a persisted password
version column (rejected: `updated_at` already provides a monotonic stamp with no schema change); a
separate `critical` band (rejected: the spec's two-band example is `normal`/`slow`).

**Consequences** — *Positive:* the spec's open numerics are now concrete, testable, and documented;
the redaction boundary and no-probe/no-notify posture are unchanged. *Negative:* the admin history
toggle is cosmetic until a schema change is approved (noted inline in STATUS-PAGE §7.2); a manual SQL
edit that does not touch `updated_at` will not revoke unlocks (documented in STATUS-PAGE §13.1).

**Related** — [`STATUS-PAGE.md`](STATUS-PAGE.md) §3.2, §3.4, §4.3, §5.2, §6.4, §6.6, §7.2, §8.5,
§9.2, §10.2, §11.1, §13; [`DATABASE.md`](DATABASE.md) §3.18, §3.4, §5; [`SECURITY.md`](SECURITY.md)
§3.5; ADR-013, ADR-028; [`PLAN.md`](PLAN.md) Phase 8.

---

## ADR-030: Phase 9 security-hardening implementation choices

**Status** — Accepted (Phase 9)

**Context** — The Phase 9 audit surfaced several places where the implementation diverged from
[`SECURITY.md`](SECURITY.md) or left a documented control unimplemented. Remediating them required
concrete choices that this ADR freezes so code and spec cannot drift silently.

**Decision**

| Area | Choice | Rationale |
| --- | --- | --- |
| **Mixed DNS answers** | `SsrfGuard` rejects the whole answer if *any* resolved IP is denied, matching `SsrfUrlValidator` | SECURITY.md §5.5 requires rejection on any denied address; the two layers had diverged |
| **Non-canonical numeric hosts** | Reject `127.1`, `2130706433`, `0x7f000001`, `0177.0.0.1` before resolution | SECURITY.md §5.4; prevents alternate-spelling bypass of literal-IP classification |
| **Session timeouts** | `EnforceSessionTimeouts` middleware on `/admin`, anchors `auth.login_at` / `auth.last_seen_at` in the session, values from `config/sentinel.php` | Implements SECURITY.md §2.5 idle/absolute windows without new columns; signed Carbon diff computed anchor→now |
| **Retention** | `Prunable`/`MassPrunable` on `Check`, `Snapshot`, `NotificationLog`, `Incident`, `NotificationCooldown`; prune by `created_at` (cooldowns by `expires_at`) | AC-19; `model:prune` was scheduled but inert. Mass-prunable for high-volume tables (DATABASE.md §4); `Snapshot::pruning()` removes the file artefact |
| **Trusted proxy** | Opt-in `TRUSTED_PROXIES` env, never `*`; default trusts nothing | SECURITY.md §3.4/§7; behind Nginx, IP-keyed throttling collapses without it, but a wildcard would let a direct client spoof `X-Forwarded-For` |
| **CSRF in tests** | Remove the blanket `except: ['/*']`; rely on Laravel's built-in `runningUnitTests()` bypass | A wildcard exemption is a production bypass if it ever ships; it was redundant in tests |
| **Secret serialization** | `NotificationChannel::$hidden = ['secret_ref']` | SECURITY.md §4.2 rule 4; prevents decrypted secret leaking via `toArray()`/`toJson()` |
| **Status JSON fail-closed** | `json()` aborts `404` (not `403`) when password mode lacks a hash | SECURITY.md §3.5; `403` advertises the page's existence |

**Consequences** — *Positive:* the SSRF layers agree, documented session/retention controls are now
enforced and tested, and secret/serialization leaks are closed. *Negative:* `MassPrunable` deletes
in bulk without per-model events (acceptable for high-volume telemetry); the trusted-proxy default
means operators MUST set `TRUSTED_PROXIES` for per-client throttling to be meaningful (documented in
SECURITY.md §12.1.3).

**Related** — [`SECURITY.md`](SECURITY.md) §2.5, §3.5, §4.2, §5.4, §5.5, §7, §12.1; `PLAN.md` Phase 9.

---

## ADR-031: Multiple Status Pages (multi-row `status_pages`)

**Status** — Accepted (implemented; owning phase [`PLAN.md`](PLAN.md) Phase 11)

**Context** — The Phase 8 status page is a singleton: [`DATABASE.md`](DATABASE.md) §3.18
`status_page_settings` holds a single logical row (id = 1), the public route is `GET /status`,
and per-page concepts such as visibility mode, password, and unlock session are global. Operators
need more than one status page — one per audience/tenant/brand — each independently
configured, each with its own visibility mode and redaction boundary.

**Decision** — Replace the singleton with a multi-row model:

- New table **`status_pages`** with columns `id`, `name`, `slug` (UNIQUE), `is_default`,
  `visibility_mode`, `password_hash` (NULL), `created_by` (FK `users`), `created_at`/`updated_at`.
- New nullable FK **`websites.status_page_id`** -> `status_pages.id`, `ON DELETE SET NULL`. A
  website with `status_page_id IS NULL` **falls back to the default page** (`is_default = 1`).
- The existing single-row `status_page_settings` is **migrated into one default `status_pages`
  row** — data-preserving and additive; no row is destroyed.
- Public URL becomes **`/status/{slug}`**. Legacy **`/status`** responds `302` redirect to the
  default page's slug (`/status/{default-slug}`).
- Unlock session keying becomes **per-page**: `status_unlock.{page_id}` (replacing the global key).
- `StatusPageCache` key includes the **page id**.
- Admin CRUD at **`/admin/status-pages`** (`index`/`create`/`store`/`edit`/`update`/`destroy`)
  behind **auth + admin**.
- The **redaction boundary is unchanged per page** — each page redacts on the same boundary
  ([`STATUS-PAGE.md`](STATUS-PAGE.md) §4), independently of any other page.

**Alternatives considered** — Extending `status_page_settings` with extra rows (rejected — the
singleton assumption is baked into `StatusPageSetting::singleton()` and the cache/unlock keys);
a per-website slug column (rejected — pages are audiences, not websites, and many websites share
a page).

**Consequences** — *Positive:* one deployment serves several audiences; per-page visibility and
password; additive migration preserves existing config. *Negative:* one more FK on the hot
`websites` table; `StatusPageSetting::singleton()` callers must resolve the default or the
website's assigned page.

**Related** — [`DATABASE.md`](DATABASE.md) §3.18/§3.22; [`STATUS-PAGE.md`](STATUS-PAGE.md);
[`ARCHITECTURE.md`](ARCHITECTURE.md) §10; [`SECURITY.md`](SECURITY.md) §3.5; ADR-013, ADR-029.

---

## ADR-032: Browser Push notifications

**Status** — Accepted (implemented; owning phase [`PLAN.md`](PLAN.md) Phase 11)

**Context** — Alerting currently ships Email + Telegram providers behind the provider-independent
dispatcher (`ADR-010`, `ADR-011`). A third delivery channel — **browser push** — is wanted so an
Admin receives incident alerts in the browser without an installed native app. Two constraints
apply: the incident engine must stay provider-agnostic (never special-case a provider), and no
secret may ever be logged (`SECURITY.md` §4).

**Decision**

- New table **`push_subscriptions`** with columns `id`, `user_id` (FK `users`, nullable),
  `website_id` (FK `websites`, nullable), `endpoint` (TEXT, UNIQUE hash), `p256dh`, `auth`,
  `user_agent` (NULL), `enabled`, `created_at`/`updated_at`.
- **VAPID keys** come from `config/sentinel.php` + env (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`,
  `VAPID_SUBJECT`). The **private key is never logged**.
- New **`WebPushProvider`** implements the **same provider contract** as `EmailProvider` /
  `TelegramProvider` (`app/Contracts/NotificationProvider.php`) and is registered in
  `NotificationProviderRegistry`. The `IncidentEngine` is **not modified** and does not special-case
  Web Push.
- **Optional** Composer dependency `minishlink/web-push`; note the **PHP 8.4 constraint** when
  selecting a compatible release.
- A **service worker** at `public/` handles the `push` event and `notificationclick`, plus opt-in JS
  in `resources/js/app.js`.
- Subscription lifecycle endpoints: **`POST /admin/push/subscribe`** (auth + admin + CSRF) to
  register, **`DELETE`** to unsubscribe.
- Payloads are **redacted through `MessageRedactor`** before send; dedup/cooldown via the existing
  `NotificationDispatcher` is **unchanged**.

**Alternatives considered** — A vendor push service (rejected — external dependency and data
egress); a WebSocket/SSE channel (rejected — no offline delivery, not a notification channel).

**Consequences** — *Positive:* a third channel without touching incident logic; reuses suppression,
cooldown, and delivery logging. *Negative:* optional browser-support variance; VAPID key custody is
a new secret class (see [`SECURITY.md`](SECURITY.md) §4).

**Related** — [`NOTIFICATIONS.md`](NOTIFICATIONS.md) §7.3; [`DATABASE.md`](DATABASE.md) §3.23;
[`SECURITY.md`](SECURITY.md) §4; ADR-010, ADR-011.

---

## ADR-033: Theme Light / Dark / System

**Status** — Accepted (implemented; owning phase [`PLAN.md`](PLAN.md) Phase 11)

**Context** — The admin UI is server-rendered Blade + Tailwind. Operators want a light/dark
experience, with a "follow the OS" default, and without the flash-of-unstyled/wrong theme (FOUC)
that a late-bound class swap causes.

**Decision**

- Tailwind v4 **class-based dark variant** via `@custom-variant dark`.
- Persistence via **`localStorage` + cookie** (the cookie lets the server render the correct
  `<html>` attributes on first paint).
- **No-FOUC inline bootstrap script** in the Blade `<head>` that applies the theme before first
  paint.
- **`data-theme` / `.dark`** are applied to `<html>`.
- A **three-state control** — Light / Dark / System — where **System follows
  `prefers-color-scheme`**.
- Design tokens are defined in **`resources/css/app.css`**.

**Alternatives considered** — `prefers-color-scheme` only (rejected — no user override);
server-side-only persistence (rejected — round-trip before paint, FOUC).

**Consequences** — *Positive:* no FOUC, honours OS preference by default, explicit user control.
*Negative:* theme state lives in two stores (localStorage + cookie) that must stay in sync.

**Related** — [`ARCHITECTURE.md`](ARCHITECTURE.md) §14.2; ADR-002 (Tailwind + Alpine, no SPA).

**Amendment (Phase 3d — legacy bridge removed)** — The Phase-2 migration shipped a temporary
**LEGACY BRIDGE** in `resources/css/app.css`: `html.dark` descendant overrides that re-mapped a
handful of light-only Tailwind utilities (`bg-white`, `bg-slate-50`, `text-slate-*`,
`border-slate-*`, `divide-slate-200`) onto dark values so unmigrated views stayed coherent. Phases
3a–3c moved every admin/auth/status/error surface onto the semantic token utilities, so the bridge
was **deleted** (together with the now-orphaned `--color-surface-dark` raw token). The class-based
dark variant (`@custom-variant dark`), the `:root` / `.dark` token scopes, the `@theme inline`
mapping and the `html.dark body` base rule are unchanged; dark mode is now driven solely by the
flip-aware tokens. The removal is guarded by
[`tests/Feature/Ui/NoLegacyDarkBridgeTest.php`](tests/Feature/Ui/NoLegacyDarkBridgeTest.php).

---

## ADR-034: Shared UI primitives

**Status** — Accepted (implemented; owning phase [`PLAN.md`](PLAN.md) Phase 11)

**Context** — The admin UI repeated ad-hoc markup for form fields, confirmation dialogs, and
pagination/page-size controls. Duplication caused inconsistent accessibility and inconsistent
behaviour (e.g. delete confirmations that were not always modal-gated).

**Decision** — Introduce reusable Blade components:

- **`x-form.field`** — label + help icon with **hover tooltip AND click modal** + error + hint.
- **`x-modal`** — Alpine-based; used by delete confirm, help, and bulk actions.
- **`x-per-page` selector** — values **10 / 20 / 50 / 100 / All**, preserving the query string; a
  shared **controller-side validated per-page whitelist helper** governs accepted values.
- **Icon-button convention** for row actions, each with an accessible **`aria-label`**; **delete is
  always gated by a confirmation modal**.

**Alternatives considered** — Per-view bespoke markup (rejected — the source of the inconsistency);
a JS component library (rejected — forbidden SPA territory, ADR-002).

**Consequences** — *Positive:* consistent accessibility and confirmation behaviour; one place to
change field/modal/page-size semantics. *Negative:* Blade component indirection for simple fields.

**Amendment (Plan S2 — websites table actions + bulk operations)** — `x-modal-form` extends `x-modal`
so a confirmation body is wrapped in a real POST/PUT/DELETE form; the websites index now uses it for
both the per-row and the bulk delete confirmations (delete can never submit without the modal open).
Row actions are icon-only with `aria-label` + `title`. Bulk actions use fixed-path RESTish routes
`POST /admin/websites/bulk/{enable,disable,delete}` (the literal `bulk` segment guarantees it can
never be read as a `{website}` id), validated by `BulkWebsiteActionRequest` (bounded id array,
integer members). Bulk delete soft-deletes `websites` and relies on retention for telemetry, so no FK
is orphaned and append-only incident history is preserved. The manual run-check route
`POST /admin/websites/{website}/check` dispatches [`RunWebsiteCheck`](app/Jobs/RunWebsiteCheck.php)
only — never an inline probe (AGENTS.md §9).

**Related** — [`ARCHITECTURE.md`](ARCHITECTURE.md) §14.3; ADR-002; [`PRD.md`](PRD.md) §7.

---

## Open Questions / Assumptions

### Open questions

1. **Session storage** — Database `sessions` is selected (ADR-018); if the team prefers the Redis
   session driver, the `sessions` table becomes unused. Not yet ratified.
2. **`audit_logs` retention** — Not specified by the canonical retention set (which covers checks,
   incidents, notification logs, snapshots). Assumed retained indefinitely at MVP.
3. **`notification_channels` per-website scoping default** — Assumed: absence of
   `website_notification_channel` rows means "use all enabled global channels". Needs confirmation.
4. **Baseline rebase trigger** — Which event promotes a new baseline version (admin action vs
   automatic after sustained stability) is not yet decided.
5. **`password_reset_tokens`** — Included on the assumption that admin password recovery is wanted;
   drop if admins are provisioned out of band only.
6. **Failed-check snapshot policy** — Whether snapshots are captured on availability failures or
   only on security findings is unresolved.
7. **Per-host outbound rate limit values** — The probe-layer rate limit per target host has no
   agreed numeric value.
8. **`settings` vs `status_page_settings` boundary** — Whether status page config should remain a
   separate table or move into `settings` is a simplification that could be revisited.

### Assumptions

- Admin is the only MVP role; the `users.role` enum is extensible.
- All timestamps are UTC; no per-tenant timezone handling at MVP.
- Single-tenant, single-instance deployment; no multi-tenancy.
- The snapshot storage volume is backed up alongside the MySQL volume.
- Rule weights and thresholds start at canonical defaults and are tuned from real false-positive
  feedback after launch.
- No API is exposed at MVP, hence no `personal_access_tokens` table.
- Screenshots and WhatsApp/Webhook channels are explicitly Future and must not be built now.
