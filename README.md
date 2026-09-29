# SiteSentinel — Website Monitoring & Security Alerts

SiteSentinel is a self-hosted, server-rendered web application that continuously monitors external websites for **Availability** (reachability, response codes, response time) and **Security / Content Health** (content defacement, injected spam/keywords, unauthorized redirects, SSL validity).

## Core Documentation

The repository specifications are documented in detail:

- [`PRD.md`](PRD.md) — Product requirements and acceptance criteria
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — Component architecture, planes, and queue flows
- [`DATABASE.md`](DATABASE.md) — Authoritative schema, table/column names, indexes, and retention
- [`DETECTION-RULES.md`](DETECTION-RULES.md) — Detection rule catalogue and scoring engine
- [`SECURITY.md`](SECURITY.md) — SSRF prevention pipeline and security hardening
- [`NOTIFICATIONS.md`](NOTIFICATIONS.md) — Dispatcher, templates, deduplication, and providers
- [`STATUS-PAGE.md`](STATUS-PAGE.md) — Redacted public status page specification
- [`PLAN.md`](PLAN.md) — 11-phase implementation roadmap
- [`DECISIONS.md`](DECISIONS.md) — Architecture Decision Records (ADRs)
- [`AGENTS.md`](AGENTS.md) — Coding agent rulebook and invariants

## Project Status

- **Current Phase**: Phase 0 Complete (`Repository & Architecture Bootstrap`).
- **Next Phase**: Phase 1 (`Application Foundation` — Laravel 13 framework scaffold on PHP 8.4+).

## Tech Stack

- **Backend**: Laravel 13 (PHP 8.4+)
- **Database**: MySQL 8 (InnoDB, utf8mb4)
- **Cache & Queue**: Redis (phpredis)
- **Frontend**: Server-rendered Blade + Hotwired Turbo + Tailwind CSS (Alpine.js only where necessary)
- **Web Server**: Nginx + PHP-FPM
- **Orchestration**: Docker Compose
