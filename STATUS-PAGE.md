# STATUS-PAGE.md — SiteSentinel — Website Monitoring & Security Alerts

> **Status: implemented (Phase 8).** The public status page described here now exists in
> application code — `app/Http/Controllers/StatusPageController.php`,
> `app/Services/StatusPage/*`, migrations `0001_08_01_*`/`0001_08_02_*`, and the
> `tests/Feature/StatusPage/*` suite (94 tests). This document remains the authoritative
> specification for **presentation**; where the implementation makes a previously-open
> numeric or storage choice concrete (throttle limits, staleness floor, response bands,
> slug storage, history default), that choice is recorded inline below and in
> [`DECISIONS.md`](DECISIONS.md) `ADR-029`. Statements still phrased as recommendations
> remain recommendations; where implementation is narrower than the recommendation, the
> narrower actual behaviour is stated explicitly.

> **Authority.** [`PRD.md`](PRD.md) §14 fixes the status page's visibility modes and the
> public/private boundary; this document is the detailed source of truth for **presentation** —
> layout, caching, visibility-mode implementation, and the enforcement mechanism for the §14.4
> public-exposure prohibition (`PRD.md` §21 Cross-Document Map). Table and column names used here are
> frozen in [`DATABASE.md`](DATABASE.md). The route and flow come from [`ARCHITECTURE.md`](ARCHITECTURE.md)
> §10. Secret and password handling defer to [`SECURITY.md`](SECURITY.md) §4. Rationale is recorded
> in [`DECISIONS.md`](DECISIONS.md) `ADR-013`.

---

## 1. Purpose & Scope

### 1.1 What this document is

`STATUS-PAGE.md` is the exhaustive specification of the SiteSentinel public status page. At Phase 8
it is served at **`/status`**; from Phase 11 (designed, §1.4) it is served per page at
**`/status/{slug}`** with `302` redirect from the legacy **`/status`**. Its three visibility modes
(now per page), the password-protected unlock flow, the **redaction boundary** that separates admin
data from public data, the public service-status model and its derivation, incident presentation,
layout and caching, abuse/privacy considerations, admin controls, and the acceptance tests that
prove no sensitive data ever reaches the public response.

### 1.2 Relationship to sibling documents

| Document | Authority over the status page | What it owns |
| --- | --- | --- |
| [`PRD.md`](PRD.md) §14 | **Authoritative** | Visibility modes (`FR-78`), what may be shown publicly (`FR-79`, `FR-82`), what is admin-only (`FR-80`), the public-exposure prohibition (`§14.4`), visibility toggle + per-website inclusion (`FR-83`). |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) §10 | Derived | The `GET /status/{slug}` flow and its branch on `status_pages.visibility_mode`. |
| [`DATABASE.md`](DATABASE.md) | **Authoritative for names** | `status_pages`, `status_page_settings`, `websites.status_page_id` / `.status_availability` / `.status_security`, `incidents`, `checks` columns. |
| [`SECURITY.md`](SECURITY.md) §4 | **Authoritative for secrets** | `status_pages.password_hash` is hashed (never reversible); no secrets in output. |
| [`DECISIONS.md`](DECISIONS.md) | Derived | Rationale: `ADR-013` (visibility model + public information redaction), `ADR-031` (multiple status pages). |
| [`NOTIFICATIONS.md`](NOTIFICATIONS.md) | Sibling | Notification payloads are admin-facing and MUST NOT be reused for public rendering. |
| **`STATUS-PAGE.md`** (this file) | **Authoritative for presentation** | Layout, caching, visibility-mode implementation, redaction enforcement. |

If this document contradicts [`PRD.md`](PRD.md) on a product-level requirement, [`PRD.md`](PRD.md)
wins and this document must be fixed.

### 1.4 Multiple status pages (Phase 11 — designed, not yet implemented)

> **Planned, additive extension.** [`DECISIONS.md`](DECISIONS.md) `ADR-031` converts the singleton
> status page into a **multi-row** model. Everything in this document remains authoritative for
> **presentation**; this subsection records what changes for multiple pages. Until Phase 11 is
> implemented, the singleton behaviour of §2–§13 is what exists.

- Pages are stored in **`status_pages`** ([`DATABASE.md`](DATABASE.md) §3.22): `id`, `name`,
  `slug` (UNIQUE), `is_default`, `visibility_mode`, `password_hash` (NULL), `created_by`
  (FK `users`), timestamps.
- **Visibility mode is per page** — each page carries its own `visibility_mode`
  (`Private` / `Public` / `Password Protected`); the singleton column
  `status_page_settings.visibility_mode` becomes the default page's column.
- A website is assigned to a page via **`websites.status_page_id`** (nullable, `ON DELETE SET
  NULL`). A website with `status_page_id IS NULL` **falls back to the default page**
  (`status_pages.is_default = 1`).
- The **public URL becomes `/status/{slug}`**; the legacy **`/status`** responds `302` redirect to
  the default page's slug.
- The **unlock session is keyed per page**: `status_unlock.{page_id}` (§3.2). Unlocking one page
  does not unlock another.
- `StatusPageCache` is **keyed per page** — the page id is part of the cache key (§9.2).
- The **redaction boundary (§4) is unchanged and enforced per page**, independently for every page.
- **Public labels (§5.2) are unchanged** — the derivation table, staleness rules, and the §14.4
  prohibition apply identically to every page.
- Admin CRUD lives at **`/admin/status-pages`** (`index`/`create`/`store`/`edit`/`update`/
  `destroy`) behind **auth + admin** (§11.4).

### 1.3 The two hard rules

1. **`HTTP 200 != healthy`** and the two dimensions (Availability, Security) are orthogonal
   ([`PRD.md`](PRD.md) §10.5). The public page MUST respect this — a security incident does not change
   the public availability label ([`PRD.md`](PRD.md) §14.4).
2. **Suspicious keywords, malicious domains, and redirect targets are NEVER public**
   ([`PRD.md`](PRD.md) §14.4, §22 rule 8). This is a hard prohibition, enforced at a single
   projection chokepoint (§4).

---

## 2. Visibility Modes

`status_page_settings.visibility_mode` is an enum
`ENUM('Private','Public','Password Protected')` defaulting to **`Private`**
([`DATABASE.md`](DATABASE.md) §3.18; `PRD.md` §14.1).

The routing flow comes from [`ARCHITECTURE.md`](ARCHITECTURE.md) §10:

```mermaid
flowchart TD
    Req[GET /status] --> Vis{status_page_settings.visibility_mode}
    Vis -- Private --> Auth{Authenticated admin?}
    Auth -- no --> NotFound[404 - not available]
    Auth -- yes --> Agg[Aggregate public-safe projection]
    Vis -- Public --> Agg
    Vis -- Password Protected --> Unlock{Session unlocked?}
    Unlock -- no --> Prompt[Password form - no data rendered]
    Unlock -- yes --> Agg
    Agg --> Render[Render public projection]
```

### 2.0 Per-page visibility (Phase 11, `ADR-031`)

> **Planned, additive.** Visibility mode becomes a **per-page** property: each `status_pages` row
> carries its own `visibility_mode` ([`DATABASE.md`](DATABASE.md) §3.22). A website resolves to the
> page via `websites.status_page_id`, falling back to the default page when NULL. The mode
> semantics below (Private / Public / Password Protected) are unchanged; only their scope moves from
> "the singleton" to "the addressed page".

### 2.1 Mode-by-mode behaviour

| Aspect | `Private` | `Public` | `Password Protected` |
| --- | --- | --- | --- |
| **Who can access** | Authenticated admin only | Anyone with the URL | Anyone with the URL, after unlocking |
| **Auth mechanism** | Admin session (§ [`SECURITY.md`](SECURITY.md) §2) | None | Shared status-page password (§3) |
| **What the visitor sees** | The public projection (same as Public) | Public projection | Public projection |
| **Session persistence** | Standard admin session | None | A status-page unlock flag in the visitor's session |
| **Cookie behaviour** | Secure/`HttpOnly`/`SameSite` admin session cookie (`NFR-11`) | No auth cookie; at most a functional cookie | Secure session cookie carrying the unlock flag |
| **Failure behaviour** | Unauthenticated → `404`/not available (do not reveal the page exists) | n/a | Wrong password → throttled (§3.4); no data ever rendered before unlock |

### 2.2 Comparison summary

- **`Private`** — the new-install default ([`PRD.md`](PRD.md) §14.1). The page is not reachable
  without an admin session; unauthenticated visitors get a **`404`/not-available** response rather
  than a "login required" page, so the existence of a status page is not advertised.
- **`Public`** — no authentication; renders the public projection only (`FR-82`).
- **`Password Protected`** — no username; a shared password unlocks a session, and **no data is
  rendered before the password is verified** (`FR-81`).

### 2.3 Recommended default and justification

> **Default = `Private`.**

- A new installation must **never leak** by accident. If `Public` were the default, simply installing
  SiteSentinel would publish a list of monitored websites (and their availability) to anyone who
  guessed the URL — an information disclosure with zero deliberate action by the operator.
- `Private` makes exposure an **explicit, deliberate admin decision** (§11), matching the "explicit
  action with confirmation" rule and `ADR-013`'s rejection of "always public" as leaking security
  posture.
- This matches both the canonical default and [`PRD.md`](PRD.md) §14.1 ("Default for a new
  installation").

---

## 3. Password Protected Mode

The frozen visibility-mode label is **`Password Protected`** (with a space), matching the canonical
spec, [`PRD.md`](PRD.md) §14.1, and the `status_page_settings.visibility_mode` enum
([`DATABASE.md`](DATABASE.md) §3.18). This document uses that exact label throughout.

### 3.1 Password storage

- The status-page password is stored **hashed**, never plaintext, in
  `status_page_settings.password_hash` (`VARCHAR(255)`, nullable) ([`DATABASE.md`](DATABASE.md) §3.18;
  `FR-81`; [`SECURITY.md`](SECURITY.md) §4 checklist: "`status_page_settings.password_hash` is hashed,
  never encrypted-reversible").
- It uses the same modern one-way hash as admin credentials (`Hash` facade; Argon2id/bcrypt, see
  [`SECURITY.md`](SECURITY.md) §2 and [`DECISIONS.md`](DECISIONS.md) `ADR-018`). It is **hash-checked**,
  not decrypted.
- `password_hash` MUST NEVER be rendered, logged, or included in any payload or cache entry.

### 3.2 Session-based unlock

- A correct password sets an **unlock flag in the visitor's session**; subsequent requests in that
  session skip the password form.
- The unlock is scoped to the **status page**, not to `/admin`. It grants no admin capability.
- Session cookies use `Secure`/`HttpOnly`/`SameSite` flags (`NFR-11`).
- **Per page (Phase 11, `ADR-031`).** With multiple pages, the unlock is keyed **per page** as
  `status_unlock.{page_id}`; a successful unlock of one page MUST NOT unlock any other page, and
  each page's `password_hash` and `updated_at` stamp are compared independently. The legacy
  singleton key applies to the default page only.

**Implemented session-flag shape (Phase 8, `ADR-029`).** `App\Services\StatusPage\VisibilityGate`
and `StatusPageUnlockService` write three session keys on a successful unlock:

| Session key | Value | Purpose |
| --- | --- | --- |
| `status_page.unlocked_at` | ISO-8601 UTC timestamp | Presence marks the session as unlocked |
| `status_page.settings_updated_at` | `v1:{settings.updated_at ISO-8601}` version stamp | Compared with `hash_equals` against the current stamp |
| `status_page.version` | `1` | Schema/format version of the flag |

`VisibilityGate::isUnlocked()` requires `status_page.unlocked_at` to be present **and** the stored
stamp to equal the current `versionStamp()` (`'v1:'.updated_at`). `StatusPageUnlockService::lock()`
(and the `POST /status/logout` route) forgets all three keys. Because the stamp is derived from
`status_page_settings.updated_at`, changing the password — or any other settings field — advances
`updated_at` and immediately revokes every existing unlock (see §3.5).

### 3.3 No username required

- The status page is **password-only** — there is no username field and no account. This is a shared
  secret pattern, deliberately not an authentication system.
- The password is independent from admin credentials (§3.5).

### 3.4 Throttling and lockout

- Password attempts MUST be **rate-limited** (per IP and per session), consistent with the monitor's
  throttling posture ([`SECURITY.md`](SECURITY.md) §2.4, which specifies application-level
  throttling and lockout).
- Repeated failures MUST **back off** (`429` with a retry hint), and MUST NOT reveal whether a
  password was "close".
- Failed attempts SHOULD be auditable (`audit_logs`, [`DATABASE.md`](DATABASE.md) §3.19) so an
  operator can see someone probing the status page.

**Implemented numerics (Phase 8, `ADR-029`).** The concrete parameters are now fixed in
`config/sentinel.php` (`status_page`) and enforced by `App\Http\Middleware\ThrottleStatusUnlock`:

| Parameter | Value | Notes |
| --- | --- | --- |
| `unlock_max` | **5 attempts** | per throttle window |
| `unlock_window` | **10 minutes** | window length (decay floor 60 s) |
| Throttle key | `status-unlock:{ip}:{sessionId}` | **per source IP + per session** — the composite key |
| Over-limit response | `429` + `Retry-After` header | `Retry-After` = seconds until the key decays |
| Reset on success | `RateLimiter::clear()` | a correct password clears the counter for that key |

Failed attempts are audited as `status_page.unlock.failed` with a reason
(`no_password_configured` when fail-closed on a missing hash, `bad_password` on a hash-check miss).

This throttle is separate from the admin login lockout in [`SECURITY.md`](SECURITY.md) §2.4
(`FR-06`). The behaviour (throttle + backoff + audit) is fixed here; the numeric parameters above
are the Phase 8 implementation and are ratified in [`SECURITY.md`](SECURITY.md) §3.4.

### 3.5 Independence from admin credentials

- The status-page password is a **separate secret** from admin passwords and is stored in a different
  column (`status_page_settings.password_hash` vs `users.password`). Changing one MUST NOT affect the
  other.
- Rotating the status-page password immediately invalidates the unlock for **new** sessions; existing
  unlocked sessions SHOULD be invalidated by bumping a stored password version/`updated_at` check so
  that changing the password actually revokes access.

### 3.6 Reset flow via admin

- Only an **authenticated admin** can set, change, or clear `status_page_settings.password_hash`
  through `/admin` status-page settings (§11).
- There is **no self-service reset** and no "forgot password" for the status page — an admin
  re-establishes the shared password.
- Enabling `Password Protected` without setting a password MUST be prevented (fail closed) — the
  settings UI MUST require a password whenever the mode is `Password Protected`.

---

## 4. The Public Information Model — The Redaction Boundary

> **This is the most important section of this document.**

All three visibility modes render through a **single public-safe projection**
([`DECISIONS.md`](DECISIONS.md) `ADR-013`: "All modes will render through a public-safe projection
that strips security detail, rule names, scores, technical metadata, and snapshot artifacts"). Even
`Private` mode renders the public projection, so promotion to `Public` cannot accidentally expose
more than was tested.

> **Per page (Phase 11, `ADR-031`).** The redaction boundary is enforced **independently per page** —
> each `status_pages` projection runs the same §4.1 field-level boundary and the same §4.2
> never-public prohibition. Adding pages MUST NOT weaken the boundary for any page, and no page may
> expose another page's websites.

### 4.1 Field-level boundary

| Data field | Admin view (`/admin`) | Public / status-page view (`/status`) |
| --- | --- | --- |
| Website display name | Real `websites.name` | Display name **or** admin-chosen alias (§11) |
| Website URL | `websites.url` | Not shown (or the alias only) |
| Coarse availability status | `status_availability` (`UP`/`DOWN`) | Derived public label (§5) |
| Coarse response-time band | Full timing | Optional coarse band only (e.g. "normal"/"slow") |
| Security state (`status_security`) | `OK`/`INFO`/`SUSPECT`/`INCIDENT` | Never shown as a security value; folded into a coarse label (§5) |
| Incident severity | `INFO`/`WARNING`/`CRITICAL` | Never shown |
| Incident existence / type | Full detail | A coarse `Incident` label **only** where §5 permits; no type detail |
| **Suspicious keywords** (e.g. `casino`, `maxwin`) | Shown with attribution | **NEVER public** |
| **Suspicious / malicious external domains** | Shown | **NEVER public** |
| **Redirect targets and full redirect chains** | Shown (JSON) | **NEVER public** |
| **Triggered rule IDs, rule names, raw scores, thresholds** | Shown (`FR-49`) | **NEVER public** |
| **Content fingerprints / hashes, baseline comparisons** | Shown | **NEVER public** |
| **Raw HTML, response headers, snapshots** | Shown (snapshot artifacts) | **NEVER public** |
| **Server IPs, internal paths, error stack traces, DB/queue identifiers** | Operational detail | **NEVER public** |
| Incident start time | Exact | Coarse bucket only (§7) |
| Internal resolution notes | `incidents.resolution_notes` | **NEVER public** |
| Notification config / delivery logs / failure states | Shown in `/admin` | **NEVER public** |

This table is the operational form of [`PRD.md`](PRD.md) §14.2, §14.3, and §14.4.

### 4.2 What is explicitly NEVER public

The public page MUST NOT render, embed, or leak — **in HTML, JSON, comments, or asset names** — any
of ([`PRD.md`](PRD.md) §14.4):

- detected keywords or keyword lists (including ignored keywords),
- outbound or injected domains,
- redirect targets or redirect chains,
- rule identifiers, weights, or risk scores,
- snapshots or excerpts of page content,
- the existence or severity of a security incident.

Plus, from [`PRD.md`](PRD.md) §14.3: response headers, resolved IPs, TLS details, content hashes,
notification configuration, delivery logs, and failure states.

> The "in asset names" clause is easy to miss: a CSS class, a cache key rendered into a URL, or an
> `id` attribute derived from a rule id would leak the same fact the visible text hides. The
> projection MUST produce opaque, non-derived identifiers (§10.2).

### 4.3 What IS public

- The **website/service display name** — the admin chooses whether to expose the real `websites.name`
  or an **alias** (§11). The implemented display value is `status_alias` when set, else `name`.
- A **coarse status label** drawn from the fixed set in §5.
- An **optional coarse response-time band** (never an exact millisecond figure that could fingerprint
  infrastructure). **Implemented bands (Phase 8, `ADR-029`):** derived from the latest check's
  `duration_ms` — `<= 800 ms` → `normal`, `<= 2500 ms` → `slow`, `> 2500 ms` → `slow`
  (`band_normal = 800`, `band_slow = 2500`; config `sentinel.status_page`, overridable via
  `SENTINEL_STATUS_BAND_NORMAL` / `SENTINEL_STATUS_BAND_SLOW`). The band is omitted when there is no
  timing. The exact millisecond value is never emitted.
- A coarse **incident** indication **only** where §5 permits it.

### 4.4 Worked before/after example

**Scenario** — the [`PRD.md`](PRD.md) §10.6 canonical case: `contoh-toko.example` returns HTTP `200`
throughout, but content no longer matches its baseline; the detection engine raises a `CRITICAL`
security incident with correlated signals.

**Admin incident detail (`/admin`) — internal, full fidelity:**

```text
Incident #4821 — Contoh Toko (contoh-toko.example)
Type        : security
Severity    : CRITICAL
State       : DETECTED
Availability: UP          Security: INCIDENT
Detected    : 2026-09-01 03:15 UTC
Score       : 22 (threshold CRITICAL = 15, guard = 2 categories)
Rules fired : RULE-KW-005 (weight 5), RULE-LNK-001 (weight 6),
              RULE-RED-001 (weight 5), RULE-CNT-001 (weight 1)
Keywords    : "casino", "maxwin"
Domains     : cdn.partner-betting.example
Redirects   : contoh-toko.example -> gate.example -> partner-betting.example
Snapshot    : snapshots/4821.html (14d retention)
Resolved by : (open)
Resolution  : (none)
```

**Public rendering (`/status`) — same incident, redacted:**

```text
Contoh Toko (alias)        Status: Incident
Updated 2026-09-01 (day-level)

A technical issue is being investigated. No further detail is published.
```

The public view shows only a coarse label plus a day-level timestamp. **No** keyword, domain, redirect
target, rule id, score, threshold, snapshot reference, or incident severity appears. This is the
`PRD` §14.4 requirement rendered concretely.

---

## 5. Service Status Model

### 5.1 Two internal dimensions, one public label

Internally, each website carries two orthogonal dimensions
([`PRD.md`](PRD.md) §10.3–10.5; [`DATABASE.md`](DATABASE.md) §3.4):

- `websites.status_availability` — `UP` | `DOWN` (the stored enum is exactly `UP`/`DOWN`
  per [`PRD.md`](PRD.md) §10.3).
- `websites.status_security` — `OK` | `INFO` | `SUSPECT` | `INCIDENT`.

The public label is a **derived, coarse** projection of these — it is **not** a security value and
must not be invertible back to one.

### 5.2 Public labels

Recommended public labels: `Operational`, `Degraded`, `Partial Outage`, `Major Outage`, `Incident`,
and `Under Maintenance` (if/when applicable).

**Implemented label set (Phase 8, `ADR-029`).** `StatusProjector` emits exactly these six labels:
`Operational`, `Unknown`, `Degraded`, `Incident`, `Partial Outage`, `Major Outage`. There is **no
`Under Maintenance` label** at MVP (no admin maintenance-marking feature exists); that row of §5.3
is therefore not implemented. The banner-worst ordering used for aggregation is
`Major Outage > Partial Outage > Incident > Degraded > Unknown > Operational` (§6.6), and an empty
published set derives `Unknown` (never `Operational`, §6.3).

### 5.3 Derivation table (internal → public)

| Internal availability | Internal security | Active incident severity | Public label |
| --- | --- | --- | --- |
| `UP` | `OK` / `INFO` | none | **`Operational`** |
| `UP` | `SUSPECT` | `WARNING` | **`Degraded`** (ambiguous by design — see §5.4) |
| `UP` | `INCIDENT` | `CRITICAL` | **`Incident`** (coarse; no security detail) |
| `DOWN` | any | `WARNING` | **`Partial Outage`** |
| `DOWN` | any | `CRITICAL` | **`Major Outage`** |
| stale / no recent successful check | any | any | **`Unknown`** / `Stale` (see §6.4) |
| admin-marked maintenance (if implemented) | any | n/a | **`Under Maintenance`** |

Notes:

- **`SUSPECT` maps to an ambiguous public label (`Degraded`), not a security label.** `SUSPECT` means
  the weighted score crossed the **`WARNING`** threshold ([`PRD.md`](PRD.md) §10.4) — a *plausible*
  problem, not a confirmed compromise. Publishing "security issue" on unconfirmed data would
  (a) alarm the client before triage, and (b) tell an attacker exactly which of their artifacts were
  detected ([`PRD.md`](PRD.md) §14.4 rationale). `Degraded` gives the visitor a truthful "something
  looks off" signal without confirming a compromise. This is configurable by Admin only within the
  redaction rules (§11).
- **A security incident with `UP` availability does not change the availability label to an outage.**
  It may surface as the coarse `Incident` label (or, if the admin chooses, no outward change at all),
  matching [`PRD.md`](PRD.md) §14.4: "the status page may show **no outward change in availability
  status** while the admin area correctly reports `HTTP: UP` + `Security: INCIDENT`. That divergence is
  intentional and correct."
- `INFO` NEVER creates an incident ([`PRD.md`](PRD.md) §11.3), so it never lifts a label above
  `Operational`.

**Implemented derivation (Phase 8, `ADR-029`).** `StatusProjector::deriveLabel()` applies, in order:
(1) stale → `Unknown`; (2) `status_availability = 'DOWN'` → `Major Outage` when the worst open
severity is `CRITICAL`, else `Partial Outage`; (3) `status_security = 'INCIDENT'` **or** worst open
severity `CRITICAL` → `Incident`; (4) `status_security = 'SUSPECT'` **or** worst open severity
`WARNING` → `Degraded`; (5) otherwise `Operational`. `INFO` never lifts a label. Open incidents are
`incidents` rows with `status IN ('DETECTED','ACKNOWLEDGED')`, reduced to the worst severity per
website. This matches the §5.3 table.

### 5.4 Why `SUSPECT` is deliberately ambiguous

- **Do not alert attackers** — a public "compromised" flag is a roadmap of which artifacts were
  detected.
- **Do not alarm clients with unconfirmed data** — "degraded" is recoverable phrasing; "hacked" is
  not, and may be wrong.
- The admin can always upgrade the wording via a **custom status label/message** within the redaction
  rules (§11) when a confirmed incident must be communicated externally.

### 5.5 Examples (matching `PRD.md` §14.2 / task brief)

| Website | Internal | Public shows |
| --- | --- | --- |
| Client A | `UP` + `OK`, no incident | **`Client A — Operational`** |
| Client C | `UP` + `SUSPECT`, `WARNING` incident | **`Client C — Degraded`** |
| Client D | `UP` + `INCIDENT`, `CRITICAL` incident | **`Client D — Incident`** |
| Client E | `DOWN`, `CRITICAL` incident | **`Client E — Major Outage`** |
| Client F | last check stale | **`Client F — Unknown`** |

---

## 6. Status Calculation

### 6.1 Inputs

| Input | Source |
| --- | --- |
| Current availability state | `websites.status_availability` (`UP`/`DOWN`) |
| Current security state | `websites.status_security` (`OK`/`INFO`/`SUSPECT`/`INCIDENT`) |
| Active incident severity | `incidents` rows with `status IN ('DETECTED','ACKNOWLEDGED')` for the website |
| Freshness of last check | `websites.last_checked_at`, `websites.check_interval_seconds` |
| Coarse response-time band | optional, derived from recent `checks` timing |

The aggregation reads the **snapshot columns** on `websites` (`status_availability`,
`status_security`, `last_checked_at`) — [`DATABASE.md`](DATABASE.md) §5 lists "Status page aggregation"
as a hot path served by `idx_checks_website_started_at` + the `websites.status_availability` snapshot.

### 6.2 Aggregation rule — checks → website status

1. A website's status is **not** computed from a single check. The probe maintains the durable
   snapshot on `websites` (`status_availability`, `status_security`) using the flapping thresholds
   ([`NOTIFICATIONS.md`](NOTIFICATIONS.md) §9.3; `websites.consecutive_failures` /
   `consecutive_successes`).
2. The public projection reads that snapshot plus any **open** incident severity, and applies the
   §5.3 derivation.
3. Aggregation across websites yields the page's **overall banner** (§8).

### 6.3 No fabrication

- The projection MUST NOT invent a label not derivable from the inputs. If the inputs are stale or
  missing, the label is `Unknown`/`Stale` (§6.4) — never `Operational`.

### 6.4 Staleness / freshness

- The system knows each website's `check_interval_seconds` and `last_checked_at`.
- **Stale threshold** — if `last_checked_at` is older than a bounded multiple of the expected
  interval, the website is presented as **`Unknown`** rather than its last known label.
- **Implemented threshold (Phase 8, `ADR-029`).** `StatusProjector::isStale()` uses
  `max(stale_multiplier × check_interval_seconds, stale_floor)` with
  `stale_multiplier = 2` and **`stale_floor = 300 s`** (config `sentinel.status_page`, overridable via
  `SENTINEL_STATUS_STALE_MULTIPLIER` / `SENTINEL_STATUS_STALE_FLOOR`). A missing
  `last_checked_at` is always stale. The comparison is UTC-normalised; the label is exactly
  **`Unknown`** (not `Stale`).
- **Hard rule:** *a website with no recent successful check MUST NOT be shown as `Operational`.*
  Showing a healthy label off the back of a stale snapshot would actively mislead the visitor and is
  the exact failure mode `HTTP 200 != healthy` warns against, transposed to the page.
- A website with `is_active = 0` is not monitored; it is excluded from the page rather than
  shown as `Operational` (`StatusProjector` filters `is_active = true` **and**
  `is_visible_on_status = true`).

### 6.5 Calculation flowchart

```mermaid
flowchart TD
    Start[For each published website] --> Active{is_active and published?}
    Active -- no --> Exclude[Exclude from page]
    Active -- yes --> Fresh{last_checked_at within stale threshold?}
    Fresh -- no --> Unknown[Label: Unknown / Stale]
    Fresh -- yes --> Avail{status_availability}
    Avail -- UP --> Sec{status_security and open incident severity}
    Avail -- DOWN --> Sev{open incident severity}
    Sec -- OK or INFO, no incident --> Op[Label: Operational]
    Sec -- SUSPECT, WARNING --> Deg[Label: Degraded - ambiguous]
    Sec -- INCIDENT, CRITICAL --> Inc[Label: Incident - coarse]
    Sev -- WARNING --> Part[Label: Partial Outage]
    Sev -- CRITICAL --> Major[Label: Major Outage]
    Unknown --> Overall[Aggregate overall banner]
    Op --> Overall
    Deg --> Overall
    Inc --> Overall
    Part --> Overall
    Major --> Overall
```

### 6.6 Overall banner aggregation

- The page-level banner is the **worst** coarse label across published websites, in order of severity:
  `Major Outage` > `Partial Outage` > `Incident` > `Degraded` > `Unknown` > `Operational`.
- **Implemented ordering (Phase 8, `ADR-029`).** `StatusProjector::banner()` ranks exactly
  `Major Outage`(5) > `Partial Outage`(4) > `Incident`(3) > `Degraded`(2) > `Unknown`(1) >
  `Operational`(0). An **empty published set derives `Unknown`** — never `Operational` (§6.3).
  `Unknown` sites are **not** surfaced as a separate list message; they participate in the banner
  ranking and appear as their own row label (`Unknown`), so a stale-only page shows an `Unknown`
  banner rather than a false `Operational`.
- The banner MUST NOT name the cause (no rule ids, no domains) — only the coarse worst case.

---

## 7. Incident Presentation

### 7.1 Active incidents

- An active incident appears publicly as a **coarse label + start time + last-updated**, with **no
  technical detail** (`FR-79`, `FR-80`; [`PRD.md`](PRD.md) §14.2–14.3).
- Recommended shape:

  ```text
  <Display name / alias>   <coarse label>   since <coarse time bucket>
  ```

- The start time is published at a **coarse granularity** only (§7.3). Exact timestamps are admin-only
  because they can fingerprint detection cadence.

### 7.2 History / timeline

- A public-safe timeline MAY show previous incidents as coarse summaries (day-level, label-only),
  if the admin enables history.
- **Implemented default: history is OFF (Phase 8, `ADR-029`).** The history block is gated by
  `config('sentinel.status_page.history_enabled')` (`SENTINEL_STATUS_HISTORY_ENABLED`), default
  **`false`**; the admin form exposes an "Enable day-level history (default off)" checkbox, but the
  frozen `status_page_settings` schema (§3.18) has no history column, so the toggle is **not
  persisted** — history renders only when the deployment config enables it. When enabled it renders
  the current per-service `displayName` + `publicLabel` + `dayBucket` rows only (`status._history`).
- The public timeline MUST NOT include: incident type, severity, rule attribution, evidence,
  resolution notes, or any field from the §4.1 admin column.
- The per-website **admin** timeline (`FR-61`) — which interleaves checks, incident events, and
  notification dispatches — is NEVER reused for public rendering.

### 7.3 Coarse time buckets

> **Recommendation: publish only day-level (or coarser) time buckets publicly.**

- Exact incident start/end times reveal the detection cadence and can help an attacker time their
  activity between checks.
- Day-level buckets convey "this happened recently" to a client without enabling that reverse
  engineering.
- The exact timestamps remain available in `/admin`.

### 7.4 Resolution notes

- `incidents.resolution_notes` and the internal `incident_events` notes are **never public**
  ([`PRD.md`](PRD.md) §14.3). Resolution on the public page is expressed only as the label returning
  to `Operational` (or entering the history list).

---

## 8. Page Content & Layout

### 8.1 Structure

| Region | Content | Redaction notes |
| --- | --- | --- |
| **Header** | Page title / logo / optional custom message | Branding from `status_page_settings.branding` (JSON) |
| **Overall banner** | Worst coarse label (§6.6) | No cause named |
| **Website rows** | One row per published website: display name/alias + coarse label + (optional) coarse response band | No URL/host by default; no security value |
| **Incident section** | Active incidents as coarse label + time bucket | No type/severity/evidence |
| **Last-updated** | A single page-level "updated at" timestamp | Coarse; shows freshness of the projection |
| **Footer** | Optional custom footer text | MUST NOT leak version/internal paths |

### 8.2 Auto-refresh

> **Recommendation: Turbo-based refresh with a poll interval tied to the check cadence.**

- Use **Hotwired Turbo** (the approved architecture — `ADR-002`, `FR-89`) to refresh the page/partial
  on a timer, so the page stays current without a full SPA.
- Recommended poll interval: **the shortest published `check_interval_seconds`** (floor ~60s) — there
  is no point refreshing faster than data can change, and a faster poll wastes load on the only
  high-traffic public endpoint (§9.3).
- **The page MUST work without JavaScript.** Turbo is progressive enhancement: the server-rendered
  HTML is complete and correct on first paint; the poll merely re-fetches it. This is required by the
  server-rendered, no-SPA architecture and is an accessibility baseline.

**Implemented behaviour (Phase 8, `ADR-029`).** The delivered `resources/views/status/show.blade.php`
is a plain server-rendered Blade document that loads the Vite CSS/JS bundle; it does **not** ship a
Turbo polling element or meta-refresh at MVP. The page is complete and correct on first paint with
JavaScript disabled, and the freshness signal is the day-level "Updated" line (§8.1). The projection
cache TTL (§9.2) bounds how stale a served page may be; a visitor reloads to observe new state.

### 8.3 Branding / settings

- Title, logo, footer, and a custom message come from `status_page_settings.branding` (JSON) and the
  `slug` ([`DATABASE.md`](DATABASE.md) §3.18).
- Custom branding beyond this basic set (custom domain, full theming) is `Future` (`FR-84`) and MUST
  NOT be built at MVP.
- The custom message MUST be admin-authored static text — it MUST NOT interpolate monitored content.

### 8.4 Accessibility basics

- Semantic HTML (`<table>` or a list with clear labels; real headings).
- Colour is never the sole carrier of status — each label has a text name (§5.2).
- Sufficient contrast between label text and background.
- The page is fully operable with JS disabled (§8.2) and readable by a screen reader.

### 8.5 Server-rendered

- The page is rendered **server-side** with Blade and delivered as complete HTML
  ([`ARCHITECTURE.md`](ARCHITECTURE.md) §1.2; `FR-89`). No data is fetched by client-side JS from an
  admin API — there is no public API at MVP ([`DATABASE.md`](DATABASE.md) §1.1 note on
  `personal_access_tokens`: "SiteSentinel … does not expose an API at MVP").

**Implemented HTTP surface (Phase 8).** Four public routes share the same visibility gate:

| Route | Method | Behaviour |
| --- | --- | --- |
| `/status` | `GET` (`status.show`) | HTML projection; locked password mode renders the password form with no data; private mode returns `404` when not an admin |
| `/status.json` | `GET` (`status.json`) | Same projection as `dto->toArray()` (`banner`, `services`, `updatedDayBucket`); locked password mode returns `403 {"message":"Locked."}`; private mode returns `404` |
| `/status/unlock` | `POST` (`status.unlock`) | Throttled (`throttle.status-unlock`); wrong password → uniform `422` (JSON) / redirect-back-with-errors (form) |
| `/status/logout` | `POST` (`status.logout`) | Forgets the three unlock session keys; never logs out an admin session |

`status.json` is **included** in the MVP surface (it renders the same redacted DTO); it is not a
separate data source. Both HTML and JSON set `X-Robots-Tag: noindex, nofollow`; cache-control is
`public, max-age=60` in `Public` mode and `no-store, private` otherwise. The visibility decision is
made by `App\Http\Middleware\EnsureStatusVisibility` (applied to the status routes) and
`App\Services\StatusPage\VisibilityGate`.

---

## 9. Caching & Performance

### 9.1 Cache the projection, not the raw data

- The cached artefact is the **public-safe projection** (§4.1) — the already-redacted view model —
  never the raw `websites`/`incidents` rows. Caching the projection means the redaction runs once, per
  [`DECISIONS.md`](DECISIONS.md) `ADR-013` ("Redaction at the projection layer enforces this once
  rather than per-view").
- This also makes leakage structurally harder: the cache physically cannot contain a field that the
  projection dropped.

### 9.2 TTL and invalidation

> **Per page (Phase 11, `ADR-031`).** With multiple pages, `StatusPageCache` keys include the
> **page id** in addition to the visibility-mode hash and settings stamp, so a projection is never
> served across pages. Busting one page's cache MUST NOT evict or expose another page's projection.

- **TTL recommendation:** tie the cache TTL to the check cadence — a short TTL (on the order of the
  shortest published `check_interval_seconds`, floor ~60s) so the page is never meaningfully staler
  than the monitoring data behind it.
- **Invalidation on incident state change:** when an incident opens, escalates, or resolves
  ([`PRD.md`](PRD.md) §12), the status-page cache MUST be invalidated/refreshed so the coarse label
  updates promptly rather than waiting out the TTL.
- Cache store is Redis at MVP ([`ARCHITECTURE.md`](ARCHITECTURE.md) §12); the `cache` table exists
  only as a framework fallback and is not the primary store ([`DATABASE.md`](DATABASE.md) §3.21).

**Implemented behaviour (Phase 8, `ADR-029`; Phase 11, `ADR-031`).** `StatusPageCache::remember()`
caches the `PublicStatusDTO` under key `{cache_prefix}:{sha1(slug)}:{page_id}:{updated_at}:{epoch}`
with `cache_prefix = status:projection:v1` and TTL
`max(ttl_floor, min(published check_interval_seconds))`, `ttl_floor = 60 s`. The key contains no
website/row identifier — only the slug hash, page id and the settings stamp — so it cannot be
enumerated. `StatusPageCache::bust()` is called **post-commit** (failure-isolated, never breaking the
write) from `IncidentEngine`, `IncidentStateMachine::apply()`, the admin settings save, and every
website CRUD mutation in [`Admin\WebsiteController`](app/Http/Controllers/Admin/WebsiteController.php)
(`store`/`update`/`destroy`/`toggle`/`bulkDelete`/`bulkUpdateActive`) — a website edit changes the
projection inputs (`name`/`status_alias`, `is_active`, `is_visible_on_status`, cadence) exactly as a
page assignment does; it forgets the keys for all three modes at the current settings stamp. The
admin save also advances `status_page_settings.updated_at`, which rolls the key forward and
immediately retires the old projection. **Cache isolation:** only the redacted DTO is stored — no raw
`websites`/`incidents` row can ever be read back out of the cache (§9.1).

**Storage form (object vs array).** The cache stores `PublicStatusDTO::toArray()` — a plain
`array{banner, services[], updatedDayBucket}` — and rehydrates the DTO with
`PublicStatusDTO::fromArray()` on read. Laravel's `config('cache.serializable_classes')` defaults to
`false` (a gadget-chain defense), so a cached **object** comes back as `__PHP_Incomplete_Class` from
any serializing store (`database`/`file`/`redis`). The array form is store-agnostic and is still "DTO
only, never raw rows". The `array` test store (`serialize => false`) does not exercise this path, so a
regression test pins it against the `database` store.

### 9.3 Load considerations

- **`/status` is the only realistically high-traffic public endpoint.** `/` is login and `/admin` is
  authenticated; everything else runs off the request path.
- Therefore the status page MUST be: cheap to render, cached as a projection (§9.1), free of
  admin-dependency queries in the render path (`FR-82`), and safe to serve many times per second.
- It MUST NOT trigger per-request probing, per-request aggregation over `checks`, or per-request
  incident detail assembly — all of that is precomputed into the cached projection.

---

## 10. SSRF / Abuse and Privacy Considerations

### 10.1 No user-supplied URL fetching from the status page

- The status page performs **no outbound fetch** of any kind. It renders precomputed state only
  ([`ARCHITECTURE.md`](ARCHITECTURE.md) §10 flow).
- It MUST NOT accept URLs, targets, or parameters that induce a fetch — that would turn the one public
  endpoint into an SSRF proxy, the defining risk the monitor's SSRF guard exists to prevent
  ([`SECURITY.md`](SECURITY.md) §5; [`PRD.md`](PRD.md) §15.4).

### 10.2 Enumeration protection

- The public page MUST NOT reveal internal website IDs, DB primary keys, internal hostnames, file
  paths, queue names, or any identifier that aids an attacker's reconnaissance
  ([`PRD.md`](PRD.md) §14.4 "in … asset names").
- Use **opaque, non-derived** public identifiers for each row (e.g. a slug or index), never an
  `id`/class derived from `website_id`, rule id, or hash.
- Do not expose a count of total monitored websites unless the admin explicitly publishes it; the
  count itself is reconnaissance.
- **Implemented (Phase 8, `ADR-029`).** Each row's only identifier is a positional
  `opaqueIndex` (`s-1`, `s-2`, …) assigned in display order; no `website_id`, DB key, host, or hash
  is emitted in HTML, JSON, class names, or cache keys. **No service count is published** anywhere in
  the HTML or JSON — the number of published services is not rendered.

### 10.3 `noindex` and robots policy

> **Recommendation: send `X-Robots-Tag: noindex, nofollow` and serve a robots policy that discourages
> indexing of `/status`.**

- Even a "public" status page is not meant to be a search-indexed directory of a client's
  infrastructure. Indexing would make enumeration trivial and could surface stale outage pages in
  search results.
- Combine a `robots.txt` disallow with the response header, and rely on the header as the
  authoritative control (robots.txt is advisory).
- **Implemented (Phase 8).** Every status response (HTML, JSON, unlock form, locked/expired state)
  carries `X-Robots-Tag: noindex, nofollow`, and the status Blade views also set
  `<meta name="robots" content="noindex, nofollow">`. `public/robots.txt` disallows `/status` and
  `/admin`.

### 10.4 No DDoS mitigation in-app

- Denial-of-service against the public endpoint is explicitly **out of scope at the application
  layer** ([`SECURITY.md`](SECURITY.md) §1.3: mitigated at platform/edge). The application keeps the
  render path cheap (§9.3); edge protection is a deployment concern.

### 10.5 Visibility changes are explicit

- Changing `visibility_mode` from `Private` to `Public` (or to `Password Protected`) MUST be an
  **explicit admin action with a confirmation warning** (§11.3). There is no implicit or automatic
  exposure.

---

## 11. Admin Controls

### 11.1 Settings the admin manages

| Control | Stored in |
| --- | --- |
| Visibility mode | `status_page_settings.visibility_mode` (Phase 11: per-row `status_pages.visibility_mode`) |
| Status-page password | `status_page_settings.password_hash` (hashed) (Phase 11: `status_pages.password_hash`) |
| Public slug | `status_page_settings.slug` (unique: `uq_status_page_settings_slug`) (Phase 11: `status_pages.slug`) |
| Branding (title/logo/footer/custom message) | `status_page_settings.branding` (JSON) |
| Which websites are listed, and their display aliases | `websites.is_visible_on_status` + `websites.status_alias` (see §11.2) |
| Whether the page is shown at all | `visibility_mode = Private` (no public exposure) |

All from [`DATABASE.md`](DATABASE.md) §3.18. Per [`DECISIONS.md`](DECISIONS.md) open questions, whether
this stays a separate table or folds into `settings` is revisitable — this document does not change
the frozen name `status_page_settings`.

**Implemented per-website storage (Phase 8, `ADR-029`).** Per-website inclusion is the
`websites.is_visible_on_status` boolean (default `0`) and the public display alias is
`websites.status_alias` (nullable, `VARCHAR(255)`), not a separate join table. **The `slug` is
storage-only at Phase 8:** it is validated (`max:191`, `alpha_dash`) and persisted on the singleton,
but it is **not used for routing** — the page is always served at `/status` and `/status.json` — and
it is not rendered. It exists for a future custom-path feature (`FR-84`, out of MVP scope).

> **Superseded at Phase 11 (`ADR-031`).** The `slug` becomes the **public routing key**: pages are
> served at `/status/{slug}`, and the legacy `/status` `302` redirects to the default page's slug.
> The routing key is `status_pages.slug` (UNIQUE, `uq_status_pages_slug`), not the Phase 8 singleton
> column.

**Implemented confirmation UX (§11.3).** The admin form requires a `confirm_public` checkbox; the
server-side `UpdateStatusPageSettingsRequest` rejects `visibility_mode = Public` without it
(`'Going public requires explicit confirmation.'`). The mode change is audited as
`status_page.visibility.changed` with `{from, to}` metadata. Saving any settings change advances
`updated_at`, which invalidates the cached projection **and** every existing unlock session (§3.2,
§9.2).

### 11.4 Multiple status pages — admin CRUD (Phase 11, `ADR-031`)

> **Planned, additive.** Until Phase 11 is implemented, the singleton (§11.1) applies.

| Route | Method | Purpose |
| --- | --- | --- |
| `/admin/status-pages` | `GET` | `index` — list pages |
| `/admin/status-pages/create` | `GET` | `create` — new-page form |
| `/admin/status-pages` | `POST` | `store` — create a page |
| `/admin/status-pages/{status_page}/edit` | `GET` | `edit` — edit form |
| `/admin/status-pages/{status_page}` | `PUT`/`PATCH` | `update` — save a page |
| `/admin/status-pages/{status_page}` | `DELETE` | `destroy` — delete a page |

- All six routes are behind **auth + admin** ([`SECURITY.md`](SECURITY.md) §3).
- A page is identified externally by its unique `slug`; the admin sets `name`, `slug`,
  `visibility_mode`, `password_hash` (optional), and `is_default`.
- Selecting a website on a page publishes it: `syncWebsites()` sets **both**
  `websites.status_page_id` **and** `websites.is_visible_on_status = true`, because the projector
  filters on both (§4.7). Deselecting releases it (`status_page_id = NULL`), after which the site
  appears only if the legacy singleton screen (§11.2) has published it.
- Deleting a page MUST NOT delete websites: `websites.status_page_id` is `ON DELETE SET NULL`, so
  affected websites fall back to the default page.
- Exactly one page SHOULD remain `is_default = 1`; the default page is the target of the legacy
  `/status` `302` redirect.
- Each page's visibility change and delete are audited ([`SECURITY.md`](SECURITY.md) §9).

### 11.2 Per-website inclusion and aliasing

- `FR-83` requires Admin to toggle, **per website**, whether it appears on the status page at all.
- The admin also chooses whether the public row shows the real `websites.name` or an **alias**, so a
  client's real service name need not be exposed ([`PRD.md`](PRD.md) §14.2: "per website that Admin
  has chosen to publish").
- Aliasing is a **display mapping only** — it does not change the monitored row, and it MUST still
  pass through the §4 projection.

### 11.3 Confirmation / warning UX for going public

> **Going public requires an explicit, warned action.**

- Toggling to `Public` MUST show a confirmation that states plainly: *the page will be reachable by
  anyone with the URL and will list the selected websites' availability*.
- The confirmation MUST require a deliberate second action (e.g. a typed confirmation or an explicit
  checkbox), so a mis-click cannot publish a client list.
- The change SHOULD be recorded in `audit_logs` ([`DATABASE.md`](DATABASE.md) §3.19).
- Going **back** to `Private` MUST take effect immediately and invalidate the cached public projection
  and any status-page unlock sessions.

---

## 12. Acceptance Criteria & Testing

### 12.1 Testable acceptance criteria

1. `GET /status` in `Private` mode returns `404`/not-available to an unauthenticated visitor and the
   public projection to an authenticated admin (`FR-78`, §2.1).
2. `GET /status` in `Public` mode returns the public projection with **no** authentication
   (`FR-82`).
3. `GET /status` in `Password Protected` mode renders **no data** before a correct password; a wrong
   password is throttled; a correct password unlocks the session (§3.2–3.4; `FR-81`).
4. `status_page_settings.password_hash` is a **hash**, never plaintext or reversibly encrypted
   (§3.1; [`SECURITY.md`](SECURITY.md) §4).
5. Every published row shows only a label from the fixed set (§5.2), a coarse timestamp, and an
   alias/display name.
6. A website with a stale `last_checked_at` beyond threshold shows `Unknown`/`Stale`, never
   `Operational` (§6.4).
7. A `UP` + `INCIDENT` website does **not** display as an availability outage; it may show `Incident`
   or no outward change (§5.3, §7.1; [`PRD.md`](PRD.md) §14.4).
8. The page is complete and correct with JavaScript disabled (§8.2, §8.5).
9. Switching `Private → Public` requires the confirmation warning (§11.3); `Public → Private`
   immediately hides the page and clears the cache/unlock.

### 12.2 Required visibility-gate tests (all three modes)

- **Private:** unauthenticated → `404`; authenticated admin → projection. Assert the page's existence
  is not advertised.
- **Public:** anonymous request → projection; assert no admin-only data.
- **Password Protected:** assert (a) no data before unlock, (b) throttle after N failures, (c) unlock
  persists within the session, (d) password rotation revokes access (§3.5).

### 12.3 The redaction regression test (mandatory)

> **An explicit regression test MUST assert that no sensitive field ever appears in the public
> response.**

- The test builds a website/incident with **all** sensitive fields populated (keywords such as
  `casino`/`maxwin`, malicious domains, a multi-hop redirect chain, rule ids/weights/scores,
  content hashes, snapshot paths, response headers, resolved IPs, internal paths) and then asserts the
  raw public HTTP body contains **none** of them — including in HTML comments, JSON blocks, CSS class
  names, and asset/URL fragments ([`PRD.md`](PRD.md) §14.4; [`DECISIONS.md`](DECISIONS.md) `ADR-013`
  consequences: "redaction logic must be regression-tested to avoid accidental leakage").
- The test MUST cover **all three visibility modes**, since all render through the same projection.
- This test is a **release blocker**: a failure means the §14.4 hard rule is violated.

### 12.6 Per-page tests (Phase 11, `ADR-031` — planned)

- **Slug routing:** `GET /status/{slug}` serves the addressed page; an unknown slug does not leak the
  existence of other pages; legacy `GET /status` returns `302` to `/status/{default-slug}`.
- **Page assignment:** a website with `status_page_id` appears only on that page; a NULL
  `status_page_id` website appears on the default page only.
- **Per-page unlock:** unlocking one `Password Protected` page does not unlock another; each page's
  password and `updated_at` stamp revoke independently (`status_unlock.{page_id}`).
- **Per-page cache:** the `StatusPageCache` key includes the page id; busting one page does not
  affect another.
- **Per-page redaction:** the §12.3 redaction regression test is run **per page**, across all
  visibility modes — still a release blocker.

### 12.4 Additional required tests

- **Derivation table test** — each row of the §5.3 table maps to the expected public label; `SUSPECT`
  maps to `Degraded` and never to a security-flavoured label.
- **Staleness test** — clock-controlled; a stale website is `Unknown`, not `Operational`.
- **Cache test** — the cached artefact is the projection (no raw field present) and is invalidated on
  incident state change (§9.2).
- **No-SSRF test** — the status page performs no outbound request and accepts no fetch-inducing
  parameter (§10.1).
- **Enumeration test** — no `website_id`, rule id, or internal path appears in the response or in
  element/asset identifiers (§10.2).

### 12.5 Implementation status (Phase 8)

All of the above are implemented and covered by the `tests/Feature/StatusPage/*` suite (**94 tests**,
all passing), which asserts observable HTML source and JSON bodies only:

| Suite | Coverage |
| --- | --- |
| `RedactionRegressionTest` | §12.3 release blocker — canaries across every mode + unlock state (incl. rotated/expired session) |
| `VisibilityGateTest` | §12.2 all three modes + admin/viewer/anonymous |
| `PasswordGateTest` | §3.2–3.5 unlock, throttle (5/10 min), rotation revocation, uniform 422 |
| `DerivationTest` | §5.3 derivation + label allowlist + banner worst-case + empty-set `Unknown` |
| `StalenessTest` | §6.4 clock-controlled stale floor (300 s) |
| `CacheTest` | §9.2 DTO-only cache, key shape, bust on incident change |
| `StatusPageHttpTest` | §8.5 route surface, headers (`noindex`, cache-control), `status.json` |
| `NoSsrfTest` | §10.1 no outbound fetch |
| `EnumerationTest` | §10.2 no ids/paths/count |

Full-suite verification at Phase 8 close: **329 tests, 324 passed, 5 pre-existing `sessions` env
failures** (unchanged from the pre-Phase-8 baseline), zero regressions.

---

## 13. Operations — Troubleshooting the Status Page

Concrete recovery steps for the failure modes an operator will actually meet. All commands run from
the repository root.

### 13.1 Unlock stops working / password rotation did not take

The unlock stamp is `v1:{status_page_settings.updated_at}`. If the row's `updated_at` did not advance
when the password changed (e.g. a manual SQL update), existing unlocks will not revoke and a new
password may appear not to apply.

- Inspect: `php artisan tinker --execute="dump(App\Models\StatusPageSetting::singleton()->only(['visibility_mode','updated_at']));"`
- Fix: re-save through the admin form (`/admin/status-settings`), which advances `updated_at`, busts
  the projection cache, and rotates every session stamp. A manual `UPDATE` that leaves `updated_at`
  unchanged will **not** revoke sessions.

### 13.2 Stale projection / incident change not reflected

The projection is cached under `status:projection:v1:*`. Incident transitions bust it post-commit; a
crash between commit and bust, or a manual DB edit, can leave a stale entry.

- Flush just the projection keys:
  - Redis: `redis-cli --scan --pattern 'status:projection:v1:*' | xargs -r redis-cli del`
  - Or via Laravel: `php artisan tinker --execute="App\Services\StatusPage\StatusPageCache::bust();"`
- The cache self-heals at the TTL (`max(60, shortest published check_interval_seconds)`).

### 13.3 `/status` returns `404` instead of the login/password form

This is expected and intentional: `Private` mode returns `404` to a non-admin so the page's existence
is not advertised, and a `Password Protected` page with **no `password_hash`** also fails closed with
`404` (never renders data). To distinguish the causes:

- Confirm the mode: `php artisan tinker --execute="dump(App\Models\StatusPageSetting::singleton()->visibility_mode);"`
- If `Private`: log in as an admin, or switch to `Public`/`Password Protected` via
  `/admin/status-settings` (Public requires the `confirm_public` checkbox).
- If `Password Protected` with no hash: set a password in the admin form. The request validator
  refuses to save `Password Protected` without a hash.

### 13.4 `429 Too many attempts` on unlock

The unlock throttle is **5 attempts per 10 minutes** keyed on `status-unlock:{ip}:{sessionId}`. A
legitimate user who exhausts it must wait out the window or obtain a fresh session cookie (the key
includes the session id, so a new session has a new counter).

- Inspect the key's remaining TTL (Redis): `redis-cli ttl 'status-unlock:<ip>:<sessionId>'`
- Clear a single stuck key: `redis-cli del 'status-unlock:<ip>:<sessionId>'`
- Clear all unlock counters (Redis): `redis-cli --scan --pattern 'status-unlock:*' | xargs -r redis-cli del`
- The `429` response carries `Retry-After` (seconds), so clients can back off deterministically.

### 13.5 Unlock returns `422 Incorrect password` (JSON) but the form just redisplays

Both are the same rejection, by design (`ADR-028`): JSON clients receive a uniform `422` with zero
data; browsers receive a `302` redirect back to the form with an error. No service data is rendered
on a failed unlock in either case. Check the audit trail for `status_page.unlock.failed`
(`reason = bad_password` vs `no_password_configured`).

---

*End of `STATUS-PAGE.md` — implemented in Phase 8; authoritative for status page presentation.*
