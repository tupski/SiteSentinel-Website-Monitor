# Changelog

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

- _(nothing yet)_

### Changed

- _(nothing yet)_

### Fixed

- _(nothing yet)_

### Security

- _(nothing yet)_

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
