# AGENTS.md — SiteSentinel — Coding Agent Rulebook

> **Primary reader: the coding agent named `Hermes`.**
> This is a strict, actionable rulebook — not prose. Follow it literally.
> It is derived from [`PRD.md`](PRD.md), [`ARCHITECTURE.md`](ARCHITECTURE.md), [`DATABASE.md`](DATABASE.md),
> [`DETECTION-RULES.md`](DETECTION-RULES.md), [`SECURITY.md`](SECURITY.md), [`NOTIFICATIONS.md`](NOTIFICATIONS.md),
> [`STATUS-PAGE.md`](STATUS-PAGE.md), [`PLAN.md`](PLAN.md), and [`DECISIONS.md`](DECISIONS.md).

---

## 1. Read This First — Mandatory Onboarding Order

Read these documents **in this exact order** before writing any code. Do not skip. Do not reorder.

| # | Document | What it answers |
| --- | --- | --- |
| 1 | [`PRD.md`](PRD.md) | What must be true. Product requirements `FR-*`, `NFR-*`, acceptance criteria `AC-*`, MVP boundary, severity/lifecycle/retention. **Authoritative for product requirements.** |
| 2 | [`ARCHITECTURE.md`](ARCHITECTURE.md) | How the system is shaped: components, flows, planes, deployment, queues. |
| 3 | [`DATABASE.md`](DATABASE.md) | The exact frozen table and column names. **Authoritative for names.** Never invent a table/column. |
| 4 | [`DETECTION-RULES.md`](DETECTION-RULES.md) | The `RULE-xx` catalogue, categories, weights, thresholds, scoring arithmetic. **Authoritative for rules.** |
| 5 | [`SECURITY.md`](SECURITY.md) | The SSRF pipeline, resource limits, auth, secrets, hardening. **Authoritative for the *how* of security.** |
| 6 | [`NOTIFICATIONS.md`](NOTIFICATIONS.md) | Dispatcher, providers, event catalogue, suppression/cooldown, delivery logging. |
| 7 | [`STATUS-PAGE.md`](STATUS-PAGE.md) | Visibility modes, redaction boundary, public status labels. |
| 8 | [`PLAN.md`](PLAN.md) | The phase roadmap. Find the **owning phase** for any work before you start. |
| 9 | [`DECISIONS.md`](DECISIONS.md) | ADR rationale. Understand *why* before proposing change. |
| 10 | [`CHANGELOG.md`](CHANGELOG.md) | What has been recorded as changed. Update it in every change. |

---

## 2. Project Purpose

SiteSentinel — **Website Monitoring & Security Alerts** — is a self-hosted, server-rendered web application that continuously monitors **external websites** for two independent things: **Availability** (is the site reachable and responding as expected?) and **Security / Content Health** (is the site serving what it should, or has something been injected, altered, or redirected?). When either dimension degrades it raises an **incident**, alerts the Admin through **Email** and **Telegram**, and exposes a redacted public **status page**. Core concept: **External Website Monitoring + Basic Website Compromise Detection + Incident Alerting**. It is *not* a WAF, not an IDS, and a clean report means "no configured rule fired", not "the site is secure".

---

## 3. Architecture Summary

- **Two planes.** The **web/UI plane** (HTTP request/response via Nginx + PHP-FPM) only reads state and enqueues work. The **monitoring plane** (long-running queue workers) performs all outbound probing. The web plane **never** performs an external probe inline.
- **Pipeline.** `Scheduler` -> `Queue: monitoring` -> `Probe` -> `Parser` -> `Baseline` -> `Detect` -> `Score` -> `Incident` -> `Queue: notifications` -> `Dispatcher` -> providers. A separate `Queue: maintenance` runs the retention pruner.
- **Central invariant — the two-dimensional status model.** Availability (`UP|DOWN`) and Security (`OK|INFO|SUSPECT|INCIDENT`) are **separate, orthogonal, independently queryable** values. `HTTP 200 != healthy`. This invariant underpins the whole product and must not be collapsed into a single column.

---

## 4. THE PRIME DIRECTIVE — Inspect Before You Change

> Before modifying code, inspect the relevant existing implementation and documentation. Do not assume that the repository matches the original plan.

### 4.1 Required pre-change checklist

1. **Read the relevant doc.** Identify the owning document for the area you are changing (e.g. detection -> [`DETECTION-RULES.md`](DETECTION-RULES.md); schema -> [`DATABASE.md`](DATABASE.md)).
2. **Read the relevant existing code.** Inspect the actual implementation, not your memory of the plan.
3. **Check [`PLAN.md`](PLAN.md) for the owning phase.** Confirm the work belongs to the phase you are in and does not belong to a later or earlier phase.
4. **Check for prior partial implementations.** The repo may already contain unfinished work; do not duplicate or overwrite it blindly.
5. **Run existing tests first.** Establish a green baseline before you change anything, so you can detect regressions.

---

## 5. THE SECOND DIRECTIVE — No Silent Deviation

> Do not silently change architecture or requirements. If implementation requires deviation from documented architecture, update the relevant documentation and explain the reason.

### 5.1 Which document to update for which change

| Kind of change | Owning document to update |
| --- | --- |
| Product requirement / scope / MVP boundary | [`PRD.md`](PRD.md) |
| Component, flow, plane, queue, deployment | [`ARCHITECTURE.md`](ARCHITECTURE.md) |
| Table / column / index / FK / retention mechanics | [`DATABASE.md`](DATABASE.md) |
| Rule id, category, weight, pattern, threshold | [`DETECTION-RULES.md`](DETECTION-RULES.md) |
| Provider, payload, cooldown/suppression, delivery log | [`NOTIFICATIONS.md`](NOTIFICATIONS.md) |
| Visibility mode, redaction boundary, public label | [`STATUS-PAGE.md`](STATUS-PAGE.md) |
| SSRF pipeline, limits, auth, secrets, hardening | [`SECURITY.md`](SECURITY.md) |
| Phase scope, ordering, acceptance criteria | [`PLAN.md`](PLAN.md) |

### 5.2 Always record the deviation

- Add a **new ADR or an amendment** to [`DECISIONS.md`](DECISIONS.md) explaining what changed and why.
- Note the deviation in [`CHANGELOG.md`](CHANGELOG.md) in the same change.
- A deviation with no doc update and no ADR is a defect.

---

## 6. Hard Technology Constraints

**Mandatory stack:** Laravel 13, PHP 8.4+, MySQL 8, Redis, Hotwired Turbo, Tailwind CSS, Alpine.js (only where necessary), Laravel Scheduler, Laravel Queue (Redis), Laravel HTTP Client, Nginx, Docker Compose.

**FORBIDDEN — must not appear anywhere in the codebase or dependency tree:**

- React, Vue, Next.js, Inertia, Livewire — and any other SPA framework.
- SQLite or PostgreSQL as the application database. MySQL 8 only.

Adding any forbidden technology requires a **superseding ADR** and is otherwise treated as a **regression**. The UI is server-rendered Blade + Turbo; there is no SPA toolchain.

---

## 7. Coding Conventions

- **Standard:** PSR-12 enforced by Laravel Pint.
- **PHP:** 8.4+ features allowed and encouraged — typed properties, enums, `readonly`, constructor promotion, `declare(strict_types=1)`.
- **Naming:** models singular `PascalCase`; tables `snake_case` **plural**; services in `app/Services/<Domain>/`; jobs named verb-first (e.g. `RunWebsiteCheck`); DTOs as `readonly` classes.
- **Configuration over hardcoding:** all tunables live in `config/sentinel.php` (and documented config), never inline.
- **No business logic in controllers or Blade.** Controllers orchestrate; Blade presents.
- **Validation:** always via Form Requests, never ad hoc in controllers.
- **Partial updates:** API resources / Turbo responses, not bespoke payloads.
- **UI:** Blade components for reusable UI.
- **Alpine.js** only for local interaction state — never for data fetching or business logic.
- **Turbo Frames/Streams are preferred over ad-hoc AJAX.** Do not hand-roll AJAX when a Frame/Stream is the idiomatic answer.

---

## 8. Database Rules

- **MySQL 8 only**, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.
- **Additive migrations only.** Never edit a migration that has shipped. New change = new migration.
- Migrations **must match [`DATABASE.md`](DATABASE.md)**. If they must diverge, update [`DATABASE.md`](DATABASE.md) **in the same change**.
- **Explicit indexes** for hot queries; follow the `idx_`/`uq_`/`fk_` naming from [`DATABASE.md`](DATABASE.md).
- **FK + cascade policy** exactly per [`DATABASE.md`](DATABASE.md). Soft deletes only where the doc specifies; high-volume telemetry (`checks`, `snapshots`, `notification_logs`) uses hard deletes via retention.
- **JSON columns only where [`DATABASE.md`](DATABASE.md) specifies** (`redirect_chain`, extraction lists, metadata).
- **No raw SQL** unless justified; if used, it must be parameterised — never string-interpolated.
- **Seeders must be idempotent** (safe to re-run; upsert by natural key).
- Every schema change requires **a migration + a doc update + a test**.

---

## 9. Monitoring Safety Rules

- **Never perform outbound monitoring requests inside a web/HTTP request.** Queue only.
- **Always respect the per-website lock** to prevent overlapping checks.
- **Never exceed the documented request limits** — timeouts, response size caps, redirect hop caps, decompression caps ([`SECURITY.md`](SECURITY.md) §6).
- **Always cap** redirects, response size, and decompression.
- **Always record failures** (timeout, DNS, connection, policy) as check results — never throw a check away.
- **Never let a monitoring failure break the web UI.** A failing check must not impair other checks or the admin plane (`NFR-07`).

---

## 10. SSRF Rules (Non-Negotiable)

1. **Scheme allowlist** — only `http` and `https`.
2. **Block** loopback, private, link-local, and cloud-metadata addresses (e.g. `169.254.169.254`).
3. **Resolve DNS and validate before connecting** — validate the resolved IP, not just the hostname.
4. **Re-validate on EVERY redirect hop.**
5. **No automatic redirect following** — handle redirects manually, hop by hop.
6. **Enforce the hop cap.**
7. **Block redirects into private space.**
8. **Block internal hostnames.**
9. **Never trust the initial URL alone.**
10. **Never fetch a URL supplied by an unauthenticated user.**
11. **Any new outbound-fetch feature MUST go through the shared SSRF guard** — never a raw HTTP client call.

**Bypassing the guard is a critical defect.** A blocked redirect is recorded as a **policy failure**, not a website outage.

---

## 11. Security Rules

- **Never log or expose secrets.**
- **Encrypted casts** for third-party secrets (e.g. Telegram bot token, `notification_channels.secret_ref`).
- **Hashed status-page password** (`status_page_settings.password_hash`) — never reversible.
- **Escape ALL monitored / untrusted content** in the UI.
- **Never render captured HTML as HTML** (snapshots are evidence, not markup).
- **CSRF on all state-changing routes.**
- **Throttle auth and status-page unlock.**
- **Keep `/admin` behind auth + admin** — every method, every route.
- **`APP_DEBUG=false` in production.**
- **Never add a public registration route.**

---

## 12. Notification Rules

- **Keep the incident engine provider-agnostic.** Providers are added only via the provider contract ([`DECISIONS.md`](DECISIONS.md) ADR-010).
- **Add providers only via the provider contract** — never special-case a provider inside the incident engine. **Browser Push (`WebPushProvider`, Phase 11, `ADR-032`) is a provider, not a special case**: it implements the same contract and is registered in `NotificationProviderRegistry`.
- **Never bypass suppression/cooldown** ([`NOTIFICATIONS.md`](NOTIFICATIONS.md) §9) — this applies unchanged to Browser Push.
- **Never let notification failure fail a monitoring job** — nor block incident creation.
- **Always log delivery attempts**, including failures, redacted. Never log push subscription material (`push_subscriptions.endpoint`/`.p256dh`/`.auth`) or the VAPID private key.

---

## 13. Testing Requirements

- **Every detection rule needs a fixture-driven unit test.** Fixtures must be synthetic/sanitized and declare which `check_extractions`/`website_baselines` columns they populate ([`DETECTION-RULES.md`](DETECTION-RULES.md) §11.5).
- **Every phase needs feature tests for its acceptance criteria.**
- **The SSRF guard needs adversarial tests** that **fail if a private IP or metadata endpoint is reachable**.
- **The redaction boundary needs a regression test** asserting no sensitive field is present in the public status page response — across all three visibility modes; this test is a **release blocker**.
- **Tests must not perform real outbound network calls** — fake the HTTP client.
- **Clock-dependent logic** (cooldowns, expiry, retention, recovery thresholds) must be tested with a **controllable clock**.
- **Minimum coverage expectation:** every acceptance criterion has at least one test, every rule has a fixture test, and every security-critical path (SSRF, redaction, auth guard) has an adversarial test. Coverage percentage alone is never sufficient — the mandatory categories above are required.
- **A bug fix requires a regression test.**

---

## 14. Documentation Rules

- **The doc set is the source of truth.**
- **Update the owning doc in the same change as the code.**
- **Keep [`CHANGELOG.md`](CHANGELOG.md) updated** in every change.
- **New decisions go to [`DECISIONS.md`](DECISIONS.md)** as a new ADR or amendment.
- **Keep terminology consistent:** *website*, *check / check result*, *incident*, *severity*, *status page*. Do not invent synonyms.
- **Keep Mermaid diagrams valid** and GitHub-renderable — avoid double quotes and parentheses inside square-bracket node labels; use the frozen table/column names in diagrams.

---

## 15. Anti-Regression Expectations

These invariants **MUST NOT regress**. Assert them in tests where practical.

1. **HTTP 200 is not health.** A `200` may still be `Security: INCIDENT`.
2. **The two dimensions stay separate** — Availability and Security are never merged into one field.
3. **The correlation guard stays enforced** — `CRITICAL` security escalation requires `>= 2` independent categories.
4. **`/admin` stays protected** — unauthenticated access is rejected for every method.
5. **The redaction boundary holds** — no security detail, keyword, domain, redirect target, rule id, or snapshot on the public status page. With multiple status pages (Phase 11, `ADR-031`) the boundary, visibility gate, and unlock session (`status_unlock.{page_id}`) hold **per page**; no page exposes another page's websites.
6. **The SSRF guard cannot be bypassed** — no raw outbound client call outside the guard.
7. **Monitoring stays out of the request path** — no outbound probe inside a web/HTTP request.
8. **Retention pruning stays enabled** — `checks` 30d (cfg 30/60/90), `notification_logs` 90d, `snapshots` 14d, `incidents` 365d.
9. **Incidents are append-only history** — `RESOLVED` is terminal; recurrence creates a **new** incident.

---

## 16. How to Approach a Change

1. **Locate the owning phase in [`PLAN.md`](PLAN.md).** Confirm the change belongs there.
2. **Read the relevant docs** (per §1 ordering, focused on the area).
3. **Read the relevant code** — the actual implementation.
4. **Write/adjust tests first where practical.**
5. **Implement minimally** — smallest change that satisfies the requirement.
6. **Run tests.** Green baseline maintained or improved.
7. **Update docs** (owning document, per §5.1).
8. **Update [`CHANGELOG.md`](CHANGELOG.md).**
9. **Summarise what changed and why.**

---

## 17. Definition of Done for Any Change

- [ ] The owning phase in [`PLAN.md`](PLAN.md) is satisfied / advanced.
- [ ] Acceptance criteria for the affected area are met.
- [ ] New/updated tests pass; no regression to previously passing tests.
- [ ] SSRF guard, redaction boundary, and `/admin` guard still hold.
- [ ] Owning doc updated if anything deviated.
- [ ] [`DECISIONS.md`](DECISIONS.md) updated if an architectural decision changed.
- [ ] [`CHANGELOG.md`](CHANGELOG.md) updated.
- [ ] No forbidden technology introduced.
- [ ] No implementation claim made anywhere that is not actually implemented.

---

## 18. Escalation / Uncertainty

### 18.1 When documents conflict

- **Do not guess.**
- **Raise it** — surface the conflict explicitly rather than choosing silently.
- **Prefer the more specific document** for the specific question (e.g. [`DETECTION-RULES.md`](DETECTION-RULES.md) governs a rule weight; [`DATABASE.md`](DATABASE.md) governs a column name).
- **Record the conflict** in [`DECISIONS.md`](DECISIONS.md) and note the resolution.

### 18.2 Precedence order for conflicts

1. [`PRD.md`](PRD.md) — product-level requirements (`what must be true`).
2. [`DATABASE.md`](DATABASE.md) — frozen table/column names.
3. [`DETECTION-RULES.md`](DETECTION-RULES.md) — rule ids, weights, patterns, thresholds.
4. [`SECURITY.md`](SECURITY.md) — the *how* of security (governs over [`PRD.md`](PRD.md) §15 only on the *how*, not the *what*).
5. [`NOTIFICATIONS.md`](NOTIFICATIONS.md) / [`STATUS-PAGE.md`](STATUS-PAGE.md) — delivery and presentation mechanics.
6. [`ARCHITECTURE.md`](ARCHITECTURE.md) — components and flows.
7. [`PLAN.md`](PLAN.md) — phase scheduling and ordering.
8. [`DECISIONS.md`](DECISIONS.md) — rationale for the above.
9. [`CHANGELOG.md`](CHANGELOG.md) — history.

If any higher-precedence document is contradicted by a lower one, the lower document must be fixed.

---

*End of `AGENTS.md` — rulebook for the SiteSentinel coding agent.*
