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

- **Current Phase**: Phase 1 Complete (`Application Foundation` — Laravel 13 scaffold, queue/scheduler, Turbo + Tailwind, health endpoint).
- **Next Phase**: Phase 2 (`Authentication & Admin Shell`).

## Tech Stack

- **Backend**: Laravel 13 (PHP 8.4+)
- **Database**: MySQL 8 (InnoDB, utf8mb4) — canonical production. Local development uses SQLite as a temporary convenience (ADR-022).
- **Cache & Queue**: Redis (`predis` client; swappable via `REDIS_CLIENT`)
- **Frontend**: Server-rendered Blade + Hotwired Turbo + Tailwind CSS (Alpine.js only where necessary)
- **Web Server**: Nginx + PHP-FPM
- **Orchestration**: Docker Compose

## Local Bootstrap (native PHP, no Docker)

```bash
composer install
cp .env.example .env            # then set DB_CONNECTION=sqlite for local dev
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install && npm run build
php artisan serve
```

Health endpoint: `GET /health` — reports database, Redis, and queue status.

> **Canonical production database is MySQL 8.** SQLite is a local-development convenience only
> (see `DECISIONS.md` ADR-022). Do not deploy with SQLite.
