# Changelog

## [2026-10-04] — Icon-only theme switcher dropdown (ADR-033)

### Changed (UI — no architecture change)

- **[`theme-switcher.blade.php`](resources/views/components/theme-switcher.blade.php)** is now an
  icon-only dropdown. The collapsed trigger renders ONLY the active theme's icon (sun = Light,
  moon = Dark, monitor = System) and carries `aria-label`, `aria-haspopup="menu"` and
  `aria-expanded`; no theme name is rendered outside the opened panel. The open panel has a
  "Theme" header and three `role="menuitemradio"` options (icon + name), the active one marked
  with `aria-checked="true"` AND a check glyph (not colour alone). Uses the repo's existing
  inline-SVG icon mechanism (Heroicons-style outline, `stroke="currentColor"`); no icon library
  added.
- **`themeMenu` Alpine component** added to [`resources/js/app.js`](resources/js/app.js)
  (registered before `Alpine.start()`, same JS-defined pattern as `modal`): roving-focus menu with
  focus trap, opens on Enter/Space/ArrowDown, arrow-key navigation, Enter/Space select, Escape +
  outside-click close with focus returned to the trigger. Delegates all state to the existing
  global `theme` store — the `theme` localStorage key and cookie convention are unchanged, and
  system mode still follows `prefers-color-scheme` live.
- **Anti-FOUC unchanged:** [`theme-bootstrap.blade.php`](resources/views/partials/theme-bootstrap.blade.php)
  already resolves `system` via `matchMedia` in the inline blocking `<head>` snippet for all three
  modes, so no change was required.

### Tests

- Added [`ThemeSwitcherTest`](tests/Feature/ThemeSwitcherTest.php): closed trigger is icon-only
  (empty rendered text) with correct ARIA wiring; no theme words render outside the dropdown panel;
  all three choices present; active option exposes `aria-checked` + check glyph.

## [2026-10-04] — Fix Alpine console errors in the reusable modal (ADR-034)

### Fixed (bug — no architecture change)

- **`x-modal` broke Alpine with `Invalid or unexpected token` / `Illegal invocation`.** The
  component's inline `x-data="{…}"` object embedded a focusable-element CSS selector containing
  double quotes (`[tabindex]:not([tabindex="-1"])`). Because `x-data` is delimited with double
  quotes in Blade/HTML, the inner quotes terminated the attribute early, so Alpine received a
  syntactically broken expression and the object never initialised. The subsequent `x-show="open"`
  bindings then resolved the bare identifier `open` in global scope — where `window.open` is a
  function — producing `Illegal invocation` on the bulk-delete-websites modal.
- **Fix:** the modal component is now defined once in
  [`resources/js/app.js`](resources/js/app.js) as `Alpine.data('modal', …)` (registered before
  `Alpine.start()`, alongside the existing `theme`/`pushOptin` definitions). The focus selector now
  lives in a plain JS string with no HTML-attribute quoting conflict, and the previously mangled
  `focus="==" 'function'` / `nodes.length===0` fragments are restored to their intended logic
  (`typeof this.previousFocus.focus === 'function'`, `if (nodes.length === 0) return;`,
  shift-tab wrap handling). [`resources/views/components/modal.blade.php`](resources/views/components/modal.blade.php)
  now uses `x-data="modal"`.
- **Unchanged:** all accessibility attributes (`role="dialog"`, `aria-modal="true"`,
  `aria-labelledby`), `x-show`, `x-transition`, `x-cloak`, `x-ref="panel"`, backdrop click-to-close,
  Escape-to-close and the Tab focus trap behave exactly as before. [`modal-form.blade.php`](resources/views/components/modal-form.blade.php)
  delegates to `x-modal` and needed no change; no other view uses the embedded-quote selector
  pattern. No new dependency, no forbidden tech introduced.

### Tests

- Extended [`SharedComponentsTest`](tests/Feature/Ui/SharedComponentsTest.php) with a regression
  guard asserting the rendered modal contains `x-data="modal"`, preserves the focus-trap/a11y
  wiring, and does NOT embed the focusable-element selector or inline component body.

## [2026-10-04] — Verification pass: full-suite + anti-regression audit

### Docs (status-labelling reconciliation — no code change)

- Corrected stale `planned, not implemented` / `Future` labels for shipped Phase 11 work (AGENTS.md
  §14/§17). `NOTIFICATIONS.md` §7.3 Browser Push is now labelled `Implemented` (§7 header notes
  WhatsApp/Webhook remain `Future`); `ARCHITECTURE.md` §10 notification-flow node and §14 header now
  read implemented; `PLAN.md` Phase 11 header/AC-25..AC-31 and `DECISIONS.md` ADR-031..ADR-034 status
  lines now read implemented. No behaviour, schema, or route changed.
- Audit result: full suite 565 passed / 3479 assertions; Pint clean (211 files);
  `migrate:fresh --seed` + second `db:seed` idempotent; `npm run build` OK with the Tailwind v4
  `dark` variant present. All nine §15 invariants proven by existing tests; no regression found.

## [2026-10-04] — Browser Push notifications (Plan S5, ADR-032)

### Added (implementation — Web Push)

- **`push_subscriptions` table** (DATABASE.md §3.23). Additive migration with `user_id`/`website_id`
  nullable FKs (cascade), `endpoint`/`p256dh`/`auth` stored encrypted, a deterministic
  `endpoint_hash` (SHA-256) backing `uq_push_subscriptions_endpoint_hash`, and
  `idx_push_subscriptions_enabled`. `notification_channels.type` validated enum extended with
  `browser_push` (MySQL `ALTER … MODIFY`; SQLite dev/test rebuild via `change()`).
  ([`0001_11_04_000000_create_push_subscriptions_table.php`](database/migrations/0001_11_04_000000_create_push_subscriptions_table.php),
  [`0001_11_05_000000_add_browser_push_to_notification_channels_type.php`](database/migrations/0001_11_05_000000_add_browser_push_to_notification_channels_type.php))
- **`PushSubscription` model** — encrypted casts on `endpoint`/`p256dh`/`auth`, all three in `$hidden`
  (never serialized to a browser). ([`app/Models/PushSubscription.php`](app/Models/PushSubscription.php))
- **`WebPushProvider`** implementing the SAME [`NotificationProvider`](app/Contracts/NotificationProvider.php)
  contract as Email/Telegram; registered in
  [`NotificationProviderRegistry`](app/Services/Notifications/NotificationProviderRegistry.php) as
  `browser_push`. The `IncidentEngine` and `NotificationDispatcher` are unchanged — no push special-case.
  Payloads are redacted through `MessageRedactor`; expired subscriptions (404/410) are disabled; a
  failing push returns a `DeliveryResult` and never fails a monitoring job. Dependency:
  `minishlink/web-push ^11.0` (PHP 8.4-compatible). ([`app/Services/Notifications/Channels/WebPushProvider.php`](app/Services/Notifications/Channels/WebPushProvider.php))
- **VAPID config** via `config/sentinel.php` `push` + env (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`,
  `VAPID_SUBJECT`); `.env.example` updated. The private key is never logged and never placed in a
  payload; only the public key is exposed to the opt-in JS.
- **Service worker** [`public/sw.js`](public/sw.js) handling `push` + `notificationclick`; opt-in /
  unsubscribe flow in [`resources/js/app.js`](resources/js/app.js) (register SW, request permission,
  `pushManager.subscribe` with the VAPID public key, POST/DELETE the subscription).
- **UI** on the Notification page: an opt-in toggle + modal-gated **Send test push** using the S1
  components (`x-form.field`, `x-modal`). ([`resources/views/admin/notifications/channels/_push.blade.php`](resources/views/admin/notifications/channels/_push.blade.php))
- **Routes** (auth + admin + session timeouts + CSRF): `POST /admin/push/subscribe`,
  `DELETE /admin/push/unsubscribe`, `POST /admin/push/test` (throttled 30/min).
  ([`app/Http/Controllers/Admin/PushSubscriptionController.php`](app/Http/Controllers/Admin/PushSubscriptionController.php))

### Tests

- New `WebPushProviderTest` (registry resolution, ok/failure classification, expired-disablement, VAPID
  gating, no-secret-in-payload, at-rest encryption via an injected transport — no real network) and
  `PushSubscriptionLifecycleTest` (auth + validation + no-secret-echo on subscribe/unsubscribe).
- Extended `ProviderContractTest` (browser_push resolves), `SuppressionCooldownTest` (cooldown applies
  to push), `QueueFailureIsolationTest` (failing push never fails a check), `SecretsRedactionTest`
  (redactor scrubs push material + VAPID private key; subscription secrets encrypted/hidden), and
  `CsrfSessionTest` (push routes are state-changing).

## [2026-10-04] — Multiple status pages (Plan S4, ADR-031)

### Added (implementation — multi-page status)

- **`status_pages` table + `websites.status_page_id` FK.** Additive, data-preserving migration:
  the legacy singleton `status_page_settings` row is migrated into one **default** page; the legacy
  table is retained for one release (DATABASE.md §3.18, §3.22).
  ([`0001_11_01_000000_create_status_pages_table.php`](database/migrations/0001_11_01_000000_create_status_pages_table.php),
  [`0001_11_02_000000_add_status_page_id_to_websites_table.php`](database/migrations/0001_11_02_000000_add_status_page_id_to_websites_table.php),
  [`0001_11_03_000000_migrate_status_page_settings_to_status_pages.php`](database/migrations/0001_11_03_000000_migrate_status_page_settings_to_status_pages.php))
- **`StatusPage` model** + `Website::statusPage()` / `effectiveStatusPage()` resolver; `StatusPageSetting`
  marked `@deprecated`. [Idempotent `StatusPageSeeder`](database/seeders/StatusPageSeeder.php) upserts
  the default page by `slug`.
- **Per-page public routing**: `GET /status/{slug}`, `GET /status/{slug}.json`,
  `POST /status/{slug}/unlock`, `POST /status/{slug}/logout`; legacy `GET /status` (and `/status.json`)
  `302`-redirect to the default page, or `404` when none exists.
- **Per-page isolation invariants**: `StatusProjector` projects a SINGLE page's websites (NULL
  assignment belongs to the default page only); `StatusPageCache` keys include page id + slug with a
  global epoch bump for invalidation; unlock session key is `status_unlock.{page_id}`.
- **Admin CRUD** at `/admin/status-pages` (`index`/`create`/`store`/`edit`/`update`/`destroy`) behind
  auth+admin; delete is refused for the default/last page and releases websites via `ON DELETE SET
  NULL`. Website assignment from the page form; a `status_page` select can also be added on the
  website form. Views use the S1 components (`x-form.field` help, `x-modal` delete confirm,
  `x-per-page` index, password eye toggle). Legacy `admin.status-settings` now edits the default page.

### Tests

- StatusPage suite migrated to the multi-page model and extended: per-page isolation, per-page unlock
  session isolation, per-page cache keys, legacy `/status` redirect, slug routing, admin CRUD
  auth/IDOR + delete safety, and RedactionRegressionTest across all visibility modes for each page.

## [2026-10-04] — Notification settings simplification + test-before-save (Plan S3)

### Changed (implementation — notification admin UI)

- **Page renamed “Channels” → “Notification”.** The notification index `<title>`, `<h1>`, and the
  admin top-nav label now read **Notification**; route names/URIs are unchanged. The delivery log
  stays a separate top-nav entry. ([`channels/index.blade.php`](resources/views/admin/notifications/channels/index.blade.php),
  [`admin-layout.blade.php`](resources/views/components/admin-layout.blade.php))
- **Type-conditional add/edit form.** The channel form is now Alpine-driven: selecting `email` or
  `telegram` reveals only that type's settings, and inactive inputs are `disabled` so an irrelevant
  field is never submitted. All fields use the S1 `x-form.field` component, so required/optional is
  explicit via the label asterisk and the `?` help tooltip/modal.
  ([`channels/form.blade.php`](resources/views/admin/notifications/channels/form.blade.php))
- **Type-aware, fail-closed validation.** Config rules moved into a shared
  [`ValidatesChannelConfig`](app/Http/Requests/Concerns/ValidatesChannelConfig.php) trait used by
  store/update/test. Email-only fields are `prohibited_if:type,telegram` and Telegram-only fields
  are `prohibited_if:type,email`, so a tampered submission carrying the wrong type's fields is
  rejected server-side (and required fields remain `required_if` the matching type).
  ([`StoreNotificationChannelRequest`](app/Http/Requests/StoreNotificationChannelRequest.php),
  [`UpdateNotificationChannelRequest`](app/Http/Requests/UpdateNotificationChannelRequest.php))

### Added (implementation)

- **Test-before-save.** `POST /admin/notifications/test` (`admin.notifications.test`) accepts an
  **unsaved** channel config, validates it type-aware, and sends a `channel.test` payload through
  the **provider registry** — identical escaping/truncation/transport as real sends — **without
  persisting a channel**. Bound by `throttle:30,1`, CSRF-protected, admin+auth only (fixed `test`
  path segment so it can never be read as a `{channel}` id). Returns Turbo-friendly JSON when the
  request expects JSON, otherwise back with a flash; provider errors are classified and
  secret-redacted. A `notification_logs` row records the attempt (`channel_id` null). “Send test”
  button added to the create/edit form alongside the existing per-row test-send.
  ([`NotificationChannelController::test`](app/Http/Controllers/Admin/NotificationChannelController.php),
  [`TestNotificationChannelRequest`](app/Http/Requests/TestNotificationChannelRequest.php),
  [`NotificationProviderRegistry::classFor`](app/Services/Notifications/NotificationProviderRegistry.php))
- **Changelog + tests.** [`NotificationChannelUiTest`](tests/Feature/Notifications/NotificationChannelUiTest.php)
  (15 tests): heading reads “Notification”; conditional fields render; store rejects wrong-type /
  missing required fields; test action dispatches via a registry-bound provider spy, never persists,
  leaks no secret, is rate-limited, and enforces auth + admin. `CsrfSessionTest` pins the new route
  as state-changing. No migration required (channel type set unchanged; browser push is S5).

## [2026-10-04] — Websites table actions + bulk operations (Plan S2)

### Added (implementation — `ADR-034`, PRD `FR-103`/`FR-107`)

- **Manual "run check".** Row action on the websites index plus `POST /admin/websites/{website}/check`
  ([`WebsiteController::runCheck`](app/Http/Controllers/Admin/WebsiteController.php)). The action
  **only dispatches** [`RunWebsiteCheck`](app/Jobs/RunWebsiteCheck.php) to the monitoring queue —
  no probe runs in the request path (AGENTS.md §9); overlapping checks stay protected by the job's
  per-website lock. Rate-limited (`FR-24`, 30/min). Flash confirmation; job internals are not
  exposed. Audited as `website.check_queued`.
- **Icon-only row actions.** Edit / Disable-Enable / Delete (plus Run check) are inline-SVG icon
  buttons with `aria-label` + `title` (ADR-034 / PRD `FR-107`).
- **Bulk actions + selection.** Row checkboxes and a select-all header checkbox (with indeterminate
  state) drive an Alpine bulk bar (Enable, Disable, Delete) that appears when ≥1 row is selected.
  Destructive bulk delete is modal-gated. New routes `POST /admin/websites/bulk/enable|disable|delete`
  (fixed `bulk` path segment so it can never be read as a `{website}` id), validated by
  [`BulkWebsiteActionRequest`](app/Http/Requests/BulkWebsiteActionRequest.php) (bounded id array,
  integer members). Bulk delete soft-deletes `websites`, so no FK is orphaned and append-only
  incident history is preserved (DATABASE.md §7).
- **`x-modal-form`.** Confirmation variant of `x-modal` that wraps its body in a real
  POST/PUT/DELETE form, so a delete can never submit without the modal being open.

### Tests (implementation — `tests/Feature/Admin/WebsiteActionsTest.php`)

- Run-check enqueues `RunWebsiteCheck` exactly once (`Queue::fake`) and performs **no** HTTP probe
  (`Http::assertNothingSent()`).
- Bulk enable/disable affect only selected rows; idempotent; safe on empty/unknown/soft-deleted
  selections; bulk delete removes only selected rows and keeps incident history.
- Icon actions carry accessible labels and delete is modal-gated; unauthenticated + non-admin + IDOR
  selections are rejected. `CsrfSessionTest` now pins the four new routes as state-changing.

### Docs

- `SECURITY.md` §9.1 — `website.check_queued` recorded as a supplementary audit event.
- `DECISIONS.md` `ADR-034` — amendment documenting `x-modal-form`, icon actions, and the bulk shape.
- No migration required (soft delete + existing columns only). `PLAN.md` Phase 11 already owns
  `FR-103`/`FR-107` and the icon-button convention; no phase change needed.

---

## [2026-10-04] — Shared UI primitives, theme, per-page selector, small form UX (S1)

### Added (implementation — Phase 11 foundations, `ADR-033`, `ADR-034`)

- **Theme Light / Dark / System (`ADR-033`).** Tailwind v4 class-based dark variant
  (`@custom-variant dark` in [`resources/css/app.css`](resources/css/app.css)) plus semantic surface
  tokens and baseline dark adaption for existing admin surfaces. `x-theme-switcher` three-state
  control added to the admin header and login/unlock pages. An Alpine `theme` store in
  [`resources/js/app.js`](resources/js/app.js) persists the preference to **localStorage AND a
  cookie** and follows `prefers-color-scheme` for "System". A **no-FOUC inline bootstrap** partial
  ([`resources/views/partials/theme-bootstrap.blade.php`](resources/views/partials/theme-bootstrap.blade.php))
  is included in the `<head>` of `admin-layout`, `app-layout`, `layouts/app`, and the status unlock page.
- **Shared UI primitives (`ADR-034`).** `x-form.field` (label + hover tooltip + click help modal +
  hint + error, accessible `aria-describedby` and focusable help button, built-in password eye
  toggle), `x-modal` (Alpine, focus-trap, Escape/backdrop close), `x-per-page` (10/20/50/100/All,
  preserves the query string).
- **Server-side per-page helper.** [`app/Support/PerPage.php`](app/Support/PerPage.php) validates
  `per_page` against the whitelist `10,20,50,100,all` (default `20`), never trusting raw input;
  "All" resolves to an unpaginated row count. Wired into the incidents, delivery-log, and websites
  index listings (websites index is now paginated).
- **Canonical expected-status catalogue.** [`app/Support/HttpStatusCodes.php`](app/Support/HttpStatusCodes.php)
  is the authoritative allowed set; the websites form renders a `<select>` and the
  [`WebsiteRequest`](app/Http/Requests/WebsiteRequest.php) rule is tightened to `in:` that set. The
  `websites.expected_status` column is unchanged.
- **Field help on every field** in login, status unlock, website form, and status-settings form, with
  hint text and longer help (validation constraints, security notes, expected values).
- **Modal-gated delete** on the websites index (replaces native `confirm()`).

### Tests (implementation — `tests/Feature/Ui/`)

- `PerPageHelperTest` — whitelist accepts/rejects and defaults; `PerPageListingTest` — valid/invalid
  per_page, "All", and filter preservation across the three listings; `ExpectedStatusTest` — every
  allowed code accepted, disallowed rejected, select rendered; `SharedComponentsTest` — components
  render, field surfaces validation errors, password eye toggle, theme bootstrap present.

### Notes

- No migration: `websites.expected_status` semantics unchanged.
- No forbidden technology introduced (Alpine only for local UI state). `/admin` remains behind
  auth+admin; no registration route added.
- Planning docs (`DECISIONS.md` `ADR-033`/`ADR-034`, `PRD.md`, `ARCHITECTURE.md`) were already updated
  ahead of this work; no deviation required.

---

## [2026-10-04] — Documentation-first design: Multiple Status Pages, Browser Push, Theme, Shared UI

### Added (documentation — Phase 11 design, `ADR-031`–`ADR-034`)

> **Documentation only.** These entries record the **design and upcoming feature set**; no
> application code exists for them. They are the contract the implementing agent builds against.

- **`DECISIONS.md`** — added `ADR-031` (multiple status pages via a multi-row `status_pages` model),
  `ADR-032` (Browser Push notifications via a `WebPushProvider`), `ADR-033` (Light/Dark/System theme),
  and `ADR-034` (shared UI primitives).
- **`DATABASE.md`** — added `status_pages` (§3.22) and `push_subscriptions` (§3.23); added
  `websites.status_page_id` (nullable FK, `ON DELETE SET NULL`); extended
  `notification_channels.type` to `ENUM('email','telegram','browser_push')`; added an additive,
  data-preserving migration step migrating the `status_page_settings` singleton into one default
  `status_pages` row.
- **`PRD.md`** — added `FR-103`–`FR-109` (manual check trigger, theme, Browser Push, per-page
  selector, modal-gated delete, multiple status pages, field help) and acceptance criteria
  `AC-25`–`AC-31`; noted Browser Push in the notification channels table and §13.1 flow diagram.
- **`STATUS-PAGE.md`** — added §1.4 (multiple status pages), per-page visibility (§2.0), per-page
  unlock (§3.2), per-page redaction (§4), per-page cache (§9.2), admin CRUD routes (§11.4), and
  per-page tests (§12.6). Public labels unchanged.
- **`NOTIFICATIONS.md`** — added §7.3 Browser Push (payload, VAPID, subscription lifecycle,
  suppression/cooldown unchanged, delivery logging redacted) and extended the extension guarantee
  (§2.6).
- **`ARCHITECTURE.md`** — added Browser Push + status-pages-admin components (§3), per-page status
  flow (§10.4), and planned Phase 11 components/flows (§15) with GitHub-renderable Mermaid; extended
  the notification flow diagram.
- **`SECURITY.md`** — added VAPID/push-subscription secret classes (§4.1), a VAPID custody rule
  including CSRF for registration (§4.2), new routes in §3.4, and per-page unlock/isolation (§3.5).
- **`PLAN.md`** — added Phase 11 (Post-Release Feature Set) without renumbering Phases 0–10; added
  traceability rows and backlog entries; per-page/theme/push acceptance criteria.
- **`AGENTS.md`** — extended the notification-channel rule to include Browser Push and the
  multi-page status clause. **`README.md` is intentionally not touched** (separate task).

### Notes

- Schema changes are **additive only**; no destructive migration is specified.
- Existing single-status-page behaviour is preserved and migrated into the default page.

---

## [2026-10-04] — Phase 10: Acceptance Evidence (AC-20…AC-23) + audit-event reconciliation

### Added (Phase 10 — acceptance evidence)
- **AC-21 closed (GAP → PASS).** `app/Services/Health/SystemHealth.php` is the single readiness probe
  (database / Redis / queue worker) consumed by both `HealthController` (`/health`) and the admin
  dashboard. `AdminDashboardController` injects it and `resources/views/admin/dashboard.blade.php`
  renders a **System health** widget (`system-health-heading`) showing each component's ready/
  unavailable state and queue depth, so a stalled pipeline is visible to Admin. Evidence:
  `tests/Feature/Admin/DashboardHealthTest.php` (4 tests: components present, healthy state,
  **degraded state when a component is down**, and no-secret-leak).
- **AC-22 closed (GAP → PASS, was only partially covered).** `tests/Feature/Notifications/IncidentCreationIsolationTest.php`
  (3 tests) proves a permanently failing channel **never prevents incident creation** and that its
  failed deliveries and disabled channels are **visible to Admin** on the dashboard.
- **AC-23 closed (NOT VERIFIED → PASS, locally verifiable).** `tests/Feature/Security/NoTelemetryTest.php`
  (6 tests) asserts no telemetry/analytics/update runtime package (composer + package.json), no
  telemetry env vars, no telemetry routes, a local no-op broadcast driver, and that `/health` makes
  no outbound call.
- `tests/Feature/Security/ReferenceWorkloadInvariantsTest.php` (5 tests) pins the config invariants
  AC-20/NFR-02 depends on (reference interval, ≥3× per-job headroom, ≥3× concurrency headroom,
  non-overlapping due-check dispatch, active-only fan-out).

### Fixed (Phase 10 — audit-event reconciliation)
- **`retention.pruned` was documented (SECURITY.md §9.1) but never emitted.** Added
  `app/Services/Audit/PruningAuditRecorder.php`, registered in `AppServiceProvider::boot()` on Laravel's
  `ModelPruningStarting` / `ModelsPruned` / `ModelPruningFinished` events. One `retention.pruned`
  audit row is now written per `model:prune` run with per-model counts in `metadata.models` +
  `metadata.total`; no secret and no monitored content is recorded. Evidence:
  `tests/Feature/Security/RetentionAuditTest.php` (5 tests incl. best-effort failure isolation and a
  no-leak assertion).
- **Audit event-name drift (code vs docs) reconciled.** SECURITY.md §9.1 was authoritative (AGENTS.md
  §1: docs are the source of truth for names) and the code used non-canonical snake_case names. Added
  `app/Services/Audit/AuditEvent.php` (canonical constants) and moved every emitting call site to it:
  `auth.login`→`auth.login.success`, `auth.login_failed`→`auth.login.failure`,
  `auth.login_throttled`→`auth.login.throttled`, `auth.password_reset_requested`→
  `auth.password.reset.requested`, `auth.password_reset_completed`→`auth.password.reset.completed`,
  `notification.channel_tested`→`channel.tested`, and added `channel.secret.updated` on secret rotation.
  Evidence: `tests/Feature/Security/AuditEventCatalogueTest.php` (7 tests, incl. a code↔doc drift guard
  that reads SECURITY.md §9.1).

### Changed (Phase 10 — docs)
- `SECURITY.md` §9.3: records that the §9.1 event strings are canonical, that they are emitted verbatim
  by `App\Services\Audit\AuditEvent`, that a drift guard enforces agreement, and that `retention.pruned`
  is emitted by the pruning path with redacted counts. §12 checklist row for `audit_logs` marked
  verified for Phase 10.

### Verified (Phase 10)
- Full suite **470 passed / 0 failed (2860 assertions)** — baseline 440 + 30 new tests, **zero
  regressions**; no real outbound network calls (HTTP faked; clock pinned with `travelTo`).
- `php vendor/bin/pint --test` clean (16 changed files).

### Not verified (deployment-only — exact procedure recorded)
- **AC-20** "The full stack runs within the `NFR-03` envelope (2 vCPU / 4 GB / 40 GB) at the reference
  workload, with the queue draining before the next batch is due." — **NOT VERIFIED (deployment-only).**
  The resource envelope and the measured queue-drain time require a real host under the reference
  workload (50 enabled websites, 5-minute interval); they cannot be produced by the local suite. The
  locally verifiable preconditions are pinned by `ReferenceWorkloadInvariantsTest`. Verification
  procedure: on a 2 vCPU / 4 GB / 40 GB box, seed 50 enabled websites at a 300s interval, run a queue
  worker fleet sized to `sentinel.monitoring.max_concurrent_checks`, and confirm over ≥ 1 hour that
  `jobs` drains to 0 well within each 300s window (≥3× headroom per `NFR-02`) with app+MySQL+Redis
  resident memory < 4 GB and disk < 40 GB.

## [2026-10-04] — Phase 10: Production Deployment Configuration (Redis auth blocker)

### Added (Phase 10 — deployment)
- `docker-compose.prod.yml` — production **override** layered on `docker-compose.yml` (single source of topology; no duplicate service definitions). Adds: fail-closed Redis auth from `REDIS_PASSWORD`, non-root + hardened containers (`init`, `cap_drop: [ALL]`, `no-new-privileges`, read-only root fs, tmpfs for mutable paths), explicit `expose:`-only internal services (MySQL/Redis never host-published), per-service healthchecks for nginx/app/scheduler/worker, graceful `stop_grace_period`, and a one-shot `migrate` service (profile `migrate`) — the app never auto-migrates on boot.
- `docker/nginx/prod.conf` — production Nginx server block (static security headers, `server_tokens off`, PHP path-info guard, envsubst template for `NGINX_SERVER_NAME`).
- `docker/app/zz-prod.conf` — production php-fpm pool override (`user = www-data`, `/ping` health endpoint, `process_control_timeout` for graceful drain, `expose_php off`).
- `docker/app/entrypoint.sh` — app entrypoint: prepares storage/framework permissions on a fresh volume and materialises `.env` from the injected environment; never migrates, never prints secrets.

### Changed (Phase 10 — deployment)
- `docker-compose.yml` — base file now wires the entrypoint, `php-fpm --nodaemonize`, `queue:work --max-time=3600`, and an optional (dev-mode) Redis `--requirepass` driven by `REDIS_PASSWORD`; MySQL/Redis still publish no host ports.
- `docker/app/Dockerfile` — copies the prod fpm pool config + entrypoint, pre-creates the storage tree owned by `www-data`, sets `USER www-data`.
- `.env.example` — production defaults (`APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `APP_URL`/`NGINX_SERVER_NAME` placeholders); added `REDIS_PASSWORD` (required, placeholder only), `REDIS_DB`/`REDIS_CACHE_DB`; DB/root/Redis passwords are explicit `CHANGE_ME_*` placeholders — no real secrets.

### Security (Phase 10 — Redis auth BLOCKER resolution)
- **Phase 9 BLOCKER L19 (Redis had no auth) is RESOLVED at the configuration level.** Redis in `docker-compose.prod.yml` starts with `--requirepass "$REDIS_PASSWORD"`, `${REDIS_PASSWORD:?…}` fail-closed (stack refuses to start without it), internal network only (`expose:`), and an auth-aware healthcheck (`redis-cli -a … ping`). The app consumes the same secret via `config/database.php`/`config/queue.php`/`config/cache.php`, so queue locks (`Cache::lock` in `RunWebsiteCheck`, `TelegramProvider`), the Redis queue driver, and `/health` `checkRedis()` all work with auth enabled. Credential generation/rotation/recovery documented in `SECURITY.md` §11.1. **Runtime verification is a deploy-time action** (Docker unavailable in this environment).
- `SECURITY.md` — §11 table row updated; new §11.1 (Redis auth control + rotation/recovery); disposition rows L19/L21/L22 and §12.1.5 updated; pre-release checklist Deployment rows updated.

## [2026-10-04] — Phase 10: Retention & Pruning Verification

### Fixed (Phase 10 — retention, root-caused against the documented contract)
- **Snapshots ignored their own `expires_at` (High):** `Snapshot::prunable()` aged only by `created_at`, so the column `DATABASE.md` §7/§4 designates as part of the snapshot retention model ("14d (also honors `expires_at`)") was never consulted. Consequence: a snapshot carrying a shortened `expires_at` (or an extended one) was pruned purely by wall-clock `created_at`, contradicting the frozen contract and `ExpiresSnapshot`-style expectations. `Snapshot::prunable()` now prunes by `expires_at <= now()` (set at capture time by `SnapshotWriter` to `captured_at + retention.snapshots_days`) with a `created_at` age fallback **only** for NULL-`expires_at` rows (so a legacy/manually inserted row can never become immortal). `tests/Feature/Security/SnapshotTtlPruningTest.php` locks the TTL semantics.
- **Open-incident evidence could be pruned while the incident was still open (High, `FR-95`/PRD §16.3):** snapshot pruning was unconditional. The fix adds an `incident_id IS NULL OR incident.status = 'RESOLVED'` guard to `Snapshot::prunable()`, so evidence an **open** incident depends on survives until the incident is resolved, while evidence tied to a resolved incident is released on its normal TTL. Registered (present) but non-existent protection before this change; now enforced and regression-tested.

### Added (Phase 10 — retention boundary & relationship tests)
- `tests/Feature/Security/RetentionBoundaryTest.php` — **12 tests (49 assertions)**: frozen clock (`Carbon::setTestNow`), per-type **before / at / after** boundary assertions for `checks` (30d), `snapshots` (14d), `notification_logs` (90d), `incidents` (365d), `notification_cooldowns` (`expires_at`); FK behaviour under pruning (`checks`→`check_extractions` cascade, `incidents`→`incident_events` cascade, `snapshots.incident_id`/`notification_logs.incident_id` `ON DELETE SET NULL`); on-disk snapshot HTML artefact deletion; append-only incident history outliving its pruned snapshots; idempotent re-run; and a guard that high-volume telemetry stays `MassPrunable` (bulk delete, never `->get()->each()`).
- `tests/Feature/Security/SnapshotTtlPruningTest.php` — **5 tests (13 assertions)**: snapshot `expires_at` is honoured (past TTL pruned even when `created_at` is recent; future TTL survives even when `created_at` is old), NULL-TTL fallback, open-incident evidence preserved until resolution, settled-incident evidence released on TTL.
- `RetentionPruningTest::test_retention_pruning_is_scheduled_daily` — asserts `Schedule::command('model:prune')` is registered on the daily cron expression (`0 0 * * *`) via `routes/console.php`.

### Verified (Phase 10 — retention)
- Canonical retention values match docs exactly: `checks` 30d (configurable 30/60/90, `retention.checks_allowed_days`), `incidents` 365d, `notification_logs` 90d, `snapshots` 14d (`config/sentinel.php` ↔ `PRD.md` §16.1 ↔ `DATABASE.md` §4). No documented-vs-actual mismatch remains.
- Retention suite **24 tests (81 assertions)**; full suite **440 tests, 440 passed (2523 assertions), 0 failed**. `php vendor/bin/pint` clean on changed files.

## [2026-10-03] — Phase 10: Detection Rule Coverage & Data-Flow Verification

### Fixed (Phase 10 — detection data-flow, root-caused from the real pipeline)
- **RULE-RED-005 / RULE-RED-003 (High):** `Probe` persisted each `checks.redirect_chain` hop as `{url, status, is_redirect}` with **no destination**, so the canonical hop shape required by `DETECTION-RULES.md` §8.3 (status + from + to) was never collected. `RULE-RED-005` (HTTPS→HTTP downgrade) could therefore **never fire** on a real check, and `RULE-RED-003` (redirect to a suspicious/external target) could not read the hop target either. `Probe` now records `to_url` per hop (the resolved `Location`; `null` on the final non-redirect hop). No schema change (`redirect_chain` is JSON).
- **RULE-RED-003 (Medium):** `RuleEngine::evaluateRedirect` derived the expected domain only from `websites.expected_final_domain` or the baseline `final_url`. On a first check with no explicit override and no baseline, `expected` was empty, so a redirect to an external/suspicious host was never compared. It now falls back to the monitored website host **only when the check redirected to a different registrable domain** (a genuine hijack), so `RULE-RED-003` can fire without suppressing the documented `RULE-RED-001` signal for same-host hops such as an `https → http` downgrade.

### Added (Phase 10 — detection data-flow & severity-consistency tests)
- `tests/Feature/Detection/RedirectAndProbeDataFlowTest.php` — **9 tests (96 assertions)**: drives the real `RunWebsiteCheck` + `Probe` pipeline with `Http::fake` (no live network) and a `travelTo` clock. Proves the `Probe` hop-shape fix (each persisted hop carries `to_url`), regression coverage that `RULE-RED-005`, `RULE-RED-003`, `RULE-RED-002`, `RULE-RED-001`, `RULE-AV-003/004/005` and `RULE-CNT-001` fire from **persisted** check data (not hand-built fixtures), that a connection failure persists a NULL `http_status` (never fabricated), and that severity expectations are consistent across the rule registry (`detection_rules.severity` ↔ `DETECTION-RULES.md` §8) and the incident engine (`SUSPECT`→`WARNING`, `INCIDENT`→`CRITICAL`).
- Updated `tests/Fixtures/detection/per_rule.json` case `red_006_ssrf_redirect` to assert the now-correct co-fire of `RULE-RED-002` (blocked hop lands on a different host) and its recomputed score (39).

### Verified (Phase 10 — detection coverage)
- **Rule coverage:** all **36 registered rules** verified — **33 fire** with real persisted inputs and fixture coverage, **3 are documented non-firing at MVP** (`RULE-SSL-005` weight 0 / visibility-only; `RULE-SEO-002` and `RULE-SEO-003` Future per `DETECTION-RULES.md` §8.8, shipped disabled). No other rule is registered-but-dead.
- Detection suite **72 tests (798 assertions)**; full suite **422 tests, 422 passed (2459 assertions), 0 failed**. `php vendor/bin/pint --test` clean on all changed files.

## [2026-10-03] — Phase 10: Testing & Production Readiness (test harness)

### Fixed (Phase 10 — pre-existing suite failures, root-caused not suppressed)
- **Test harness (Medium):** removed the blanket `config(['session.driver' => 'database'])` override in `tests/TestCase.php`. It contradicted `phpunit.xml`'s `SESSION_DRIVER=array`, was redundant for `RefreshDatabase` tests (which already opt into the database driver per-test, e.g. `LogoutAndResetTest`, `SessionTimeoutTest`), and broke the three HTTP classes that do **not** migrate the in-memory SQLite DB (`BaseLayoutTest`, `ExampleTest`, `HealthEndpointTest`): their first request triggered a session read against the non-existent `sessions` table → `SQLSTATE[HY000]: General error: 1 no such table: sessions` → HTTP 500. Category (c): incorrect session-driver assumption in the shared test harness — **not** an application defect and **not** a missing migration. The `sessions` table **is** defined by `0001_01_01_000000_create_users_table.php` (lines 36–43), and `SESSION_DRIVER=database` remains the production driver (`.env.example`). No new migration was needed: production already gets the table from the users migration; only the test harness forced `database` before any migration had run. This restores the documented architecture (SQLite for tests / MySQL 8 for production) and makes `phpunit.xml` the single source of truth for the suite-wide driver. No test was skipped, deleted, or weakened; no acceptance criteria changed.

### Verified (Phase 10 close — test harness)
- Full suite **413 tests, 413 passed (2363 assertions), 0 failed** — all 5 previously failing `sessions` cases resolved (count was 408 passed / 5 failed). `vendor/bin/pint --test tests/TestCase.php` clean. `php artisan migrate:fresh --env=testing` runs all 24 migrations cleanly from scratch.

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
