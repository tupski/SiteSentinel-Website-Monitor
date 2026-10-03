# ARCHITECTURE.md — SiteSentinel — Website Monitoring & Security Alerts

> **Specification only.** Nothing described in this document has been implemented.
> Every statement is future/conditional ("the worker will…", "Phase 4 implements…").
> No application code, no migrations, and no Laravel scaffold exist at the time of writing.

---

## 1. Purpose & Scope

This document specifies the **system architecture** of SiteSentinel: components, data flow,
monitoring/detection/incident/notification planes, security architecture, and deployment topology.

It is **not** a product-requirements document and **not** a schema document.

### 1.1 Relationship to sibling documents

| Document | Authority | Scope |
| --- | --- | --- |
| [`PRD.md`](PRD.md) | **Authoritative** for all product-level requirements | Requirements `FR-*`, severity, lifecycle, retention, canonical spec §22 |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) (this file) | Derived | Components, flows, diagrams, deployment |
| [`DATABASE.md`](DATABASE.md) | Derived | Tables, columns, indexes, retention mechanics |
| [`DECISIONS.md`](DECISIONS.md) | Derived | ADR rationale for architecture and data choices |

If this document contradicts [`PRD.md`](PRD.md) on a product-level requirement,
[`PRD.md`](PRD.md) wins and this document must be fixed.

### 1.2 Canonical spec (identical to [`PRD.md`](PRD.md) §22)

```text
NAME          SiteSentinel — Website Monitoring & Security Alerts
STACK         Laravel 13, PHP 8.4+, MySQL 8, Redis, Hotwired Turbo, Tailwind CSS,
              Alpine.js (only where necessary), Laravel Scheduler, Laravel Queue (Redis),
              Laravel HTTP Client, Nginx, Docker Compose
FORBIDDEN     React, Vue, Next.js, Inertia, Livewire, SQLite, PostgreSQL, any SPA framework
ARCH STYLE    Server-rendered Laravel + Hotwired Turbo; clear separation between
              monitoring plane (queue workers) and web/UI plane (HTTP)
ROLES         Admin (MVP) — extensible
ROUTES        / = login, /admin = admin area, /status = status page
CORE CONCEPT  External Website Monitoring + Basic Website Compromise Detection + Incident Alerting
KEY RULE      HTTP 200 != healthy. Availability and Security/Content Health are SEPARATE,
              orthogonal dimensions. Availability: UP|DOWN. Security: OK|INFO|SUSPECT|INCIDENT.
SEVERITY      INFO | WARNING | CRITICAL
SCORING       Correlation guard: CRITICAL security escalation requires >= 2 independent signal
              categories. Thresholds INFO >= 1, WARNING >= 8, CRITICAL >= 15.
LIFECYCLE     DETECTED -> ACKNOWLEDGED -> RESOLVED  (no other states; resolved is terminal;
              recurrence opens a NEW incident; auto-resolution requires sustained recovery,
              logged as resolution_mode = auto)
CHANNELS MVP  Email, Telegram        FUTURE: WhatsApp, Webhook
STATUS VIS    Private | Public | Password Protected
RETENTION     checks 30d default (config 30/60/90) | incidents 365d | notification logs 90d |
              snapshots 14d
SNAPSHOTS     HTML snapshot + response headers = MVP. Screenshot = Phase 2/Future, NOT MVP.
SSRF          Layered on EVERY hop: scheme allowlist (http/https only), block localhost/
              loopback/private/link-local/unique-local + cloud metadata endpoints (169.254.169.254),
              DNS re-resolved on EVERY redirect hop, redirect validation, hop cap
```

---

## 2. System Architecture Overview

SiteSentinel separates a **web/UI plane** (HTTP request/response, served by Nginx + PHP-FPM)
from a **monitoring plane** (long-running queue workers). The web plane never performs an
external probe inline; it only reads state and enqueues work.

```mermaid
flowchart TD
    subgraph Edge
        Nginx[Nginx reverse proxy]
    end

    subgraph WebPlane[Web / UI plane]
        WebApp[Laravel 13 web app]
        AdminUI[Admin UI - /admin]
        StatusPage[Status page - /status]
        Login[Login - /]
    end

    subgraph Data
        MySQL[(MySQL 8)]
        Redis[(Redis - queue + cache)]
    end

    subgraph Scheduler
        Sched[Laravel Scheduler]
    end

    subgraph MonitoringPlane[Monitoring plane - queue workers]
        Queue[Queue: monitoring]
        Probe[HTTP Fetcher / Probe client]
        Guard[Security guard - SSRF]
        Parser[Parser / Extractor]
        Baseline[Baseline builder]
        Detect[Detector / rule engine]
        Score[Scoring correlator]
        Incident[Incident engine]
    end

    subgraph NotificationPlane[Notification plane - queue workers]
        NQueue[Queue: notifications]
        Dispatcher[Notification dispatcher]
        Email[Email provider]
        Telegram[Telegram provider]
    end

    subgraph MaintenancePlane[Maintenance plane]
        MQueue[Queue: maintenance]
        Pruner[Retention pruner]
    end

    Nginx --> WebApp
    WebApp --> AdminUI
    WebApp --> StatusPage
    WebApp --> Login
    WebApp --> MySQL
    WebApp --> Redis

    Sched --> Redis
    Redis --> Queue
    Queue --> Probe
    Guard --> Probe
    Probe --> Parser
    Parser --> Baseline
    Baseline --> Detect
    Detect --> Score
    Score --> Incident
    Incident --> MySQL
    Incident --> NQueue
    NQueue --> Dispatcher
    Dispatcher --> Email
    Dispatcher --> Telegram
    Dispatcher --> MySQL

    Sched --> MQueue
    MQueue --> Pruner
    Pruner --> MySQL
    Pruner --> Redis
```

Naming legend: the monitoring plane's `Queue: monitoring`, notification plane's
`Queue: notifications`, and maintenance plane's `Queue: maintenance` are named Laravel queues
on the same Redis connection (see §12 Scheduler/worker architecture).

---

## 3. Component Inventory

All components are proposed to live under `app/` in the following namespaces. Names are
suggestions; the responsibility boundary is the binding part.

| Component | Responsibility | Inputs | Outputs | Dependencies | Suggested location |
| --- | --- | --- | --- | --- | --- |
| Web/UI plane | Serve login, admin area, status page; read state; enqueue work | HTTP requests, session, CSRF token | HTML/Turbo responses, queued jobs | Laravel HTTP kernel, MySQL, Redis, Nginx | `app/Http/Controllers`, `app/Http/Middleware` |
| Scheduler | Emit cadence ticks; dispatch due-work and maintenance jobs | Crontab tick, `websites.next_check_at` | Queued jobs, lock acquisition | Laravel Scheduler, Redis, MySQL | `app/Console/Kernel.php`, `app/Jobs` |
| Queue workers | Execute long-running monitoring/notification/maintenance work off the request path | Queued jobs from Redis | Persisted rows, further queued jobs, provider calls | Laravel Queue (Redis), all monitoring-plane services | `app/Jobs`, queue worker processes |
| HTTP Fetcher / Probe client | Perform bounded external HTTP request; follow redirects; collect response | Target `websites` row, probe options | Raw response, redirect chain, timing, TLS metadata | Laravel HTTP Client, `app/Services/Monitoring` | `app/Services/Monitoring/HttpFetcher.php` |
| Security guard (SSRF) | Enforce scheme allowlist, block private/link-local/metadata, revalidate DNS per hop | URL, resolved IPs, hop context | allow/deny decision, hop audit | DNS resolver, ip parsing | `app/Services/Monitoring/UrlGuard.php` |
| Parser / Extractor | Normalize body; extract title, final URL, keywords, external domains, suspicious patterns | Raw response body/headers | Extraction payload | DOM/html parser, `app/Services/Detection` | `app/Services/Detection/Extractor.php` |
| Baseline builder | Derive/refresh the comparison baseline from a stable observation | Extraction payload, prior baseline row | `website_baselines` row | MySQL, hashing service | `app/Services/Monitoring/BaselineBuilder.php` |
| Detector / rule engine | Evaluate each enabled `detection_rules` row against check + baseline | `checks` row, `check_extractions` row, `website_baselines` row, `website_rule_settings` | per-rule signals with category/weight | MySQL, rule registry | `app/Services/Detection/RuleEngine.php` |
| Scoring correlator | Aggregate signals, apply thresholds + correlation guard | per-rule signals | score, security state, incident candidate | config thresholds, rule categories | `app/Services/Detection/ScoringCorrelator.php` |
| Incident engine | Dedupe/merge against open incident; create/transition; emit timeline events | incident candidate, open `incidents` row | `incidents`, `incident_events`, snapshot trigger | MySQL, incident state machine | `app/Services/Incidents/IncidentEngine.php` |
| Notification dispatcher + providers | Gate (dedupe/cooldown), select channels, deliver, log | incident, channel set, cooldown state | provider call, `notification_logs` row | queue, Mail, HTTP client | `app/Services/Notifications/NotificationDispatcher.php`, `app/Services/Notifications/Channels/*`, contract `app/Contracts/NotificationProvider.php` |
| Retention pruner | Delete rows past their retention window | retention config, current time | deleted rows, run metrics | MySQL, Redis | `app/Services/Maintenance/RetentionPruner.php` |
| Status page | Render public/password-safe aggregate status | `status_page_settings`, aggregated `checks`/`incidents` | HTML, redacted public projection | MySQL, visibility gate | `app/Http/Controllers/StatusPageController.php`, `app/Services/StatusPage` |
| Admin dashboard | CRUD websites/channels/rules/settings; acknowledge/resolve incidents | authenticated admin session | HTML/Turbo responses | MySQL, auth gate, policy layer | `app/Http/Controllers/Admin/*`, `app/Policies` |

---

## 4. Data Flow — Full Check Cycle

```mermaid
sequenceDiagram
    autonumber
    participant Sched as Laravel Scheduler
    participant Redis as Redis queue
    participant Worker as Monitoring worker
    participant Guard as UrlGuard
    participant Fetcher as HttpFetcher
    participant Target as External website
    participant Parser as Extractor
    participant Detect as RuleEngine
    participant Score as ScoringCorrelator
    participant Inc as IncidentEngine
    participant DB as MySQL
    participant NQ as Notifications queue
    participant Disp as Dispatcher
    participant Prov as Email / Telegram

    Sched->>DB: select due websites where next_check_at <= now
    Sched->>Redis: acquire per-website lock
    Sched->>Redis: push RunCheck job to monitoring
    Redis->>Worker: deliver RunCheck
    Worker->>Guard: validate scheme + host resolution
    Guard-->>Worker: allow + pinned IPs (revalidated per hop)
    Worker->>Fetcher: GET with timeout + redirect cap
    Fetcher->>Target: request hop 1
    Target-->>Fetcher: response hop 1
    Fetcher->>Guard: validate redirect target + re-resolve DNS
    Guard-->>Fetcher: allow / deny hop
    Fetcher-->>Worker: final response + redirect chain
    Worker->>Parser: extract title, keywords, domains
    Parser-->>Worker: extraction payload
    Worker->>DB: persist checks + check_extractions
    Worker->>Detect: evaluate enabled rules
    Detect-->>Score: per-rule signals
    Score-->>Inc: score + security state + candidate
    Inc->>DB: dedupe/merge against open incidents
    Inc->>DB: write incidents + incident_events + snapshots
    Inc->>NQ: push SendIncidentNotification
    NQ->>Disp: deliver
    Disp->>Prov: send to selected channels
    Disp->>DB: write notification_logs
```

---

## 5. Monitoring Flow

Cadence -> **due-website selection with locking** -> one job per website -> bounded fetch ->
extraction -> persist. Locking prevents double-dispatch if the scheduler tick overlaps with a
slow run or a second scheduler replica exists.

```mermaid
flowchart LR
    Tick[Scheduler tick] --> Due[Select websites is_active AND next_check_at <= now]
    Due --> Lock{Acquire lock key website:id}
    Lock -- denied --> Skip[Skip - already in flight]
    Lock -- granted --> Dispatch[Push RunCheck to monitoring]
    Dispatch --> Fetch[Bounded fetch with timeout]
    Fetch --> Extract[Extract + persist check]
    Extract --> Next[Set last_checked_at and next_check_at]
    Next --> Release[Release lock]
    Skip --> Release
```

Details:

- **Due selection** — `SELECT id FROM websites WHERE is_active = 1 AND next_check_at <= UTC_NOW() AND locked_at IS NULL OR locked_at < stale_threshold`.
- **Locking** — a Redis lock keyed per website plus `websites.locked_at`/`websites.lock_token` as a durable fallback for cross-replica coordination. Locks auto-expire to avoid permanent starvation after a crashed worker.
- **Job per website** — one enqueued job per due website so retries and timeouts stay isolated.
- **Bounded fetch** — connect/response timeouts, redirect hop cap, max body bytes.
- **Persist** — one `checks` row + one `check_extractions` row per cycle.

### 5.1 Overlapping checks & idempotency

Each job carries a check identity derived from `website_id` + scheduled slot. If a job is retried
after a partial failure, the engine will upsert by that identity rather than insert a duplicate.
Sustained overlap is avoided by the lock; transient overlap is tolerated by idempotent upsert.

---

## 6. Detection Flow

```mermaid
flowchart TD
    Check[checks row + check_extractions row] --> Load[Load enabled rules + per-site overrides]
    Load --> Eval[Evaluate each rule against baseline]
    Eval --> Signal[Per-rule signal: category, severity, weight]
    Signal --> Corr[Correlator: sum weighted score]
    Corr --> Guard{Critical requires >=2 categories?}
    Guard -- no --> Cap[Cap at WARNING]
    Guard -- yes --> Allow[CRITICAL permitted]
    Corr --> Threshold{Threshold band}
    Threshold -- score >= 1 --> Info[INFO]
    Threshold -- score >= 8 --> Warn[WARNING]
    Threshold -- score >= 15 --> Crit[CRITICAL - if guard passed]
    Cap --> Classify[Classify security state]
    Allow --> Classify
    Info --> Classify
    Warn --> Classify
    Crit --> Classify
    Classify --> State[Security: OK / INFO / SUSPECT / INCIDENT]
    State --> Candidate[Incident candidate if state >= SUSPECT]
```

Rules:

- **Independent signal categories** are the correlation unit; the guard counts *distinct
  categories*, not distinct rules.
- Thresholds are fixed: `INFO >= 1`, `WARNING >= 8`, `CRITICAL >= 15`.
- Availability is evaluated separately and yields `UP|DOWN`; it is orthogonal to the security state.

---

## 7. Incident Flow

```mermaid
stateDiagram-v2
    [*] --> DETECTED
    DETECTED --> ACKNOWLEDGED: admin acknowledges
    ACKNOWLEDGED --> RESOLVED: admin resolves
    DETECTED --> RESOLVED: admin resolves
    RESOLVED --> [*]
```

```mermaid
flowchart TD
    Cand[Incident candidate] --> Find{Open incident for website + dedupe key?}
    Find -- yes --> Merge[Update existing: score, severity, triggered_rules, timeline event]
    Find -- no --> Create[Create incident DETECTED + timeline event]
    Merge --> Notify[Enqueue notification]
    Create --> Notify
    Recover[Sustained recovery detected] --> Auto{Open incident and recovery sustained?}
    Auto -- yes --> Resolve[RESOLVED with resolution_mode=auto + timeline event]
    Auto -- no --> Hold[Hold]
    Resolve --> Notify
```

Lifecycle is exactly `DETECTED -> ACKNOWLEDGED -> RESOLVED`. `RESOLVED` is terminal. Recurrence
opens a **new** incident (new `incidents` row) rather than reopening the old one. Auto-resolution
requires **sustained recovery** and is logged with `resolution_mode = auto`.

---

## 8. Notification Flow

```mermaid
flowchart TD
    Inc[Incident event] --> Dispatch[Dispatcher receives incident]
    Dispatch --> Dedupe{Cooldown / dedupe gate}
    Dedupe -- suppressed --> LogSkip[Log suppressed - no send]
    Dedupe -- pass --> Select[Select channels for website + severity]
    Select --> Fanout[Fan out per channel]
    Fanout --> EmailP[Email provider]
    Fanout --> TelegramP[Telegram provider]
    EmailP --> Log[notification_logs row]
    TelegramP --> Log
    LogSkip --> Log
```

- **Dedupe / cooldown gate** — suppresses repeat notifications for the same incident within the
  cooldown window, keyed by incident + channel + event kind.
- **Channel selection** — global channels plus optional per-website scoping via
  `website_notification_channel`. Absence of pivot rows means all enabled globals.
- **Providers** — MVP supports Email and Telegram; WhatsApp and Webhook are Future and must not
  be built now.
- **Delivery log** — every attempt writes a `notification_logs` row (sent, failed, or suppressed).
- **Implemented paths (Phase 7, PLAN wins over old names)** — contract
  `app/Contracts/NotificationProvider.php`, dispatcher
  `app/Services/Notifications/NotificationDispatcher.php`, providers
  `app/Services/Notifications/Channels/*`, intents/jobs `app/Services/Notifications/NotificationIntents.php`
  plus `app/Jobs/DispatchIncidentNotifications.php` and `app/Jobs/SendNotification.php`.

---

## 9. Authentication Flow

```mermaid
flowchart TD
    Login[GET /] --> Form[Login form]
    Form --> Post[POST credentials]
    Post --> Throttle{Throttle limit exceeded?}
    Throttle -- yes --> Lock[429 - back off]
    Throttle -- no --> Verify{Password hash verifies?}
    Verify -- no --> Fail[Increment throttle, generic error]
    Verify -- yes --> Session[Regenerate session id]
    Session --> CSRF[CSRF token bound to session]
    CSRF --> Gate{Admin role?}
    Gate -- no --> Deny[403]
    Gate -- yes --> Admin[Admin area /admin]
```

- Session-based authentication; **no public registration** — an admin is provisioned out of band.
- Login is throttled per IP + identity.
- Passwords stored with a modern one-way hash (see `DECISIONS.md` ADR-018).
- All admin routes sit behind an admin-only gate; `/` is login, `/admin` is the admin area.

---

## 10. Status Page Flow

```mermaid
flowchart TD
    Req[GET /status] --> Vis{status_page_settings.visibility_mode}
    Vis -- Private --> Auth{Authenticated admin?}
    Auth -- no --> NotFound[404 / not available]
    Auth -- yes --> Agg
    Vis -- Public --> Agg[Aggregate availability per website]
    Vis -- Password Protected --> Gate{Password verified?}
    Gate -- no --> Prompt[Password prompt]
    Gate -- yes --> Agg
    Agg --> Project[Public-safe projection]
    Project --> Redact[Strip security detail, technical metadata, snapshots]
    Redact --> Render[Render availability/status only]
```

The public projection exposes availability and coarse status only. Security detail, technical
metadata, rule names, scores, and snapshot artifacts are stripped (see `DECISIONS.md` ADR-013).

---

## 11. SSRF Security Architecture

Layered defense applied on **every hop**, including each redirect.

```mermaid
flowchart TD
    URL[Candidate URL] --> Scheme{Scheme is http/https?}
    Scheme -- no --> Reject[Reject]
    Scheme -- yes --> Parse[Parse host]
    Parse --> DNS[Resolve DNS]
    DNS --> Block{Resolved IP in blocklist?}
    Block -- yes --> Reject
    Block -- no --> Meta{Cloud metadata endpoint?}
    Meta -- yes --> Reject
    Meta -- no --> Pin[Pin resolution for this hop]
    Pin --> Fetch[Fetch hop]
    Fetch --> Redirect{Redirect returned?}
    Redirect -- yes --> Hop{Under hop cap?}
    Hop -- no --> Reject
    Hop -- yes --> Scheme
    Redirect -- no --> Done[Complete]
```

- **Scheme allowlist** — only `http` and `https`.
- **Blocklist** — localhost/loopback, private RFC1918, link-local, unique-local, and cloud
  metadata endpoints such as `169.254.169.254`.
- **DNS rebinding mitigation** — DNS is re-resolved on **every** redirect hop and the resolved
  address is validated before the connection; the resolution used for validation is the resolution
  used for the request for that hop, preventing a TOCTOU swap between validate and connect.
- **Redirect validation + hop cap** — each redirect target re-enters the pipeline at the scheme step.

---

## 12. Scheduler / Worker Architecture

- **Scheduler strategy** — a single Laravel Scheduler process drives cadence. It will own due
  selection, lock acquisition, and maintenance dispatch. It never blocks on a probe.
- **Queue strategy** — Laravel Queue on Redis with named queues: `monitoring`, `notifications`,
  `maintenance`, plus `default` for miscellaneous work.
- **Retry strategy** — bounded retries with backoff per queue; retries are idempotent because check
  identity derives from website + scheduled slot.
- **Timeout strategy** — per-job timeout shorter than the queue visibility/retry window so a hung
  job cannot block a worker indefinitely.
- **Concurrency** — monitoring workers scale horizontally; `monitoring` concurrency is capped to
  limit outbound fan-out and protect target sites.
- **Rate limiting** — per-host outbound rate limiting in the probe layer, plus login throttling in
  the web plane.
- **Failure handling** — terminal failures are recorded on the check row (`error_type`,
  `error_message`) and fed to the detector as an availability signal; failed jobs land in
  `failed_jobs`.
- **Idempotency & overlapping checks** — Redis locks plus durable `websites.locked_at`/`lock_token`
  prevent double-dispatch; upsert by check identity prevents duplicate rows.
- **Plane isolation** — scheduler and workers run as separate processes/containers from the web
  plane, so slow probes never consume web request slots.

---

## 13. Deployment Architecture

```mermaid
flowchart TD
    subgraph Compose[Docker Compose - single small VPS]
        subgraph Net[Internal network]
            NginxC[nginx]
            AppC[app - php-fpm]
            WorkerC[worker - monitoring/notifications/maintenance]
            SchedC[scheduler]
            MySQLC[(mysql - volume)]
            RedisC[(redis - volume)]
        end
    end
    Internet[Internet clients] --> NginxC
    NginxC --> AppC
    AppC --> MySQLC
    AppC --> RedisC
    SchedC --> MySQLC
    SchedC --> RedisC
    WorkerC --> MySQLC
    WorkerC --> RedisC
    WorkerC --> Ext[External websites]
```

- **Services** — `nginx`, `app` (php-fpm), `worker`, `scheduler`, `mysql`, `redis`.
- **Volumes** — persistent volumes for MySQL data, Redis append-only data, and application logs.
- **Networks** — a single internal network; only Nginx is exposed to the host.
- **Env config** — DB/Redis credentials, `APP_KEY`, mail credentials, Telegram bot token and chat
  id, retention overrides. Secrets come from environment variables, never from committed files.
- **Sizing guidance** — a single small VPS is sufficient at MVP scale; MySQL and Redis run on the
  same host. Horizontal scaling, if ever needed, is achieved by adding worker replicas first.
- **Separation** — the scheduler and worker containers stay out of the web request path; Nginx
  only talks to `app`.

---

## 14. Diagram Conventions

- All diagrams are **Mermaid** and must render on GitHub.
- Node labels avoid double quotes and parentheses inside square brackets to prevent parse errors.
- Table and column names shown in diagrams are the frozen names defined in [`DATABASE.md`](DATABASE.md).
