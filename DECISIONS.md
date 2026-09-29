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
