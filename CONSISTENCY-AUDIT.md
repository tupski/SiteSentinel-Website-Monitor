# CONSISTENCY-AUDIT.md — SiteSentinel Documentation Set

> **Scope.** This report records the consistency audit performed across the eleven SiteSentinel
> documents at repository root: `PRD.md`, `ARCHITECTURE.md`, `DATABASE.md`, `DETECTION-RULES.md`,
> `NOTIFICATIONS.md`, `STATUS-PAGE.md`, `SECURITY.md`, `PLAN.md`, `AGENTS.md`, `CHANGELOG.md`, and
> `DECISIONS.md`. It is **not** a product document and introduces no requirements.

---

## 1. Method

All eleven documents were read in full and treated as the audit corpus. Every inconsistency was
resolved against two authorities, in this order:

1. the **canonical spec** (the technology stack, roles, routes, key rule, severity/state sets,
   scoring thresholds and correlation guard, rule-id prefixes and the 36-rule count, lifecycle,
   MVP channels, status-page visibility modes, retention windows, snapshot scope, SSRF policy,
   fingerprint documentation, version);
2. the **precedence order** `PRD.md` > `DATABASE.md` > `DETECTION-RULES.md` > `SECURITY.md` >
   `NOTIFICATIONS.md` / `STATUS-PAGE.md` > `ARCHITECTURE.md` > `PLAN.md` > `DECISIONS.md` >
   `CHANGELOG.md`.

Where a higher-precedence document was contradicted by a lower one, the lower document was fixed.
Fixes were applied surgically: only the inconsistent text was edited, no document was restyled or
rewritten, and no new sections were added beyond what a contradiction required. Every inline
`> ⚠️ Consistency note:` blockquote raised by the original authors was removed; where a note raised a
genuine open question it was converted into an entry in §4 below rather than left inline. One
unrelated `⚠️` note in `NOTIFICATIONS.md` (a residual-risk note, not a consistency note) was retained.

---

## 2. Results table

| # | Category | Files involved | Issue found | Resolution | Status |
| --- | --- | --- | --- | --- | --- |
| 1 | Rule-set consistency | `PRD.md`, `DETECTION-RULES.md` | `PRD.md` §11.2 defined categories as `keyword / structure / link / redirect / script / header / baseline` and used non-canonical rule IDs (`KW-SPAM-001`, `STR-HIDDEN-001`, `LNK-DOMAIN-001`, `RDR-EXTERNAL-001`, `HDR-ANOM-001`, `BAS-TITLE-001`, `BAS-HASH-001`). | Rewrote §11.2's `Signal.category` to the canonical seven (`availability`, `ssl`, `redirect`, `content-fingerprint`, `content-keyword`, `external-link`, `seo-pattern`), declared `DETECTION-RULES.md` the authoritative catalogue, and replaced the illustrative weight table with canonical `RULE-*` IDs and categories. | Fixed |
| 2 | Rule-set consistency | `DETECTION-RULES.md` | §6.7 declared "Total rules documented: 34" — the canonical count is 36; §5.4's per-prefix counts were wrong and its PRD cross-reference table was obsolete. | Corrected the total to 36; rewrote §5.4 as a prefix/count table (AV 6, SSL 5, RED 6, CNT 5, KW 5, LNK 5, SEO 4 = 36); removed the obsolete PRD-mapping table. | Fixed |
| 3 | Threshold consistency | `PRD.md`, `DETECTION-RULES.md` | Correlation guard / thresholds were stated in several places with different framing; `DETECTION-RULES.md` §6.4 carried a note asking the orchestrator to ratify lookback values that `PRD.md` §11.4 already fixes. | Aligned all statements to `INFO >= 1 / WARNING >= 8 / CRITICAL >= 15` and "CRITICAL needs ≥ 2 independent categories; single category caps at WARNING"; replaced the note with a statement that the lookback (3 checks / 15 min, ×0.5 carry-forward, ×0.5 per-check decay) matches `PRD.md` §11.4. | Fixed |
| 4 | Severity & state consistency | `PRD.md`, `DETECTION-RULES.md`, `STATUS-PAGE.md` | Availability had an invented third value: `PRD.md` §10.3/glossary listed an optional `DEGRADED` state and `STATUS-PAGE.md` §6 repeated it, contradicting the canonical `UP`/`DOWN`. Separately, `DETECTION-RULES.md` §6.6 and the `RULE-AV-001` example said a *first* failure opens a `WARNING` incident, contradicting `PRD.md` `FR-53` ("not on a single transient failure"). | Removed `DEGRADED` from `PRD.md` §10.3 and the glossary and from `STATUS-PAGE.md` §6, keeping a graduated response-time signal in the `availability` category without a third stored value; rewrote the §6.6 table so sub-threshold failures open no incident and the threshold opens `WARNING`; fixed the `RULE-AV-001` example. | Fixed |
| 5 | Database naming consistency | `PLAN.md`, `DATABASE.md`, `SECURITY.md`, `DETECTION-RULES.md` | `PLAN.md` used non-frozen column names (`is_enabled`, `check_interval`, `timeout`, `expected_status_codes`, `follow_redirects`, `note`, `last_check_at`, `rule_id`, `auditable_type`/`auditable_id`) and a lowercase visibility enum; `SECURITY.md` §12 referenced `websites.lock_token`; `DETECTION-RULES.md` cited `DATABASE.md` §3.11 for `website_rule_settings`; `DATABASE.md` lacked `follow_redirects`/`note` required by `PRD.md` `FR-14`/`FR-22`. | Renamed to the frozen spellings (`is_active`, `check_interval_seconds`, `timeout_seconds`, `expected_status`, `last_checked_at`, `detection_rule_id`, `subject_type`/`subject_id`), corrected the enum to `('Private','Public','Password Protected')`, fixed `last_lock_token`, fixed the §3.9 reference, and added `follow_redirects` + `note` to `websites` in `DATABASE.md`. See §3. | Fixed |
| 6 | Retention consistency | `PRD.md`, `DATABASE.md`, `ARCHITECTURE.md`, `NOTIFICATIONS.md`, `SECURITY.md`, `PLAN.md`, `AGENTS.md` | No contradiction found: all state checks 30d (30/60/90), incidents 365d, notification logs 90d, snapshots 14d. | Verified only. | Accepted-as-is |
| 7 | Notification consistency | `PRD.md`, `NOTIFICATIONS.md` | `NOTIFICATIONS.md` fixed a 15-minute cooldown default and 2-consecutive-failure / 2-consecutive-success flapping defaults, but `PRD.md` §13.4 stated the cooldown without a default value or bypass rule. | Added the 15-minute default and the bypass rule (escalation and recovery never suppressed; a new incident's first notification never suppressed) to `PRD.md` §13.4 to match `NOTIFICATIONS.md` §9.2–§9.3. | Fixed |
| 8 | Status page consistency | `STATUS-PAGE.md`, `PLAN.md` | `STATUS-PAGE.md` carried a note re-litigating the `Password Protected` label; `PLAN.md` Phase 8 introduced the visibility enum in lowercase (`private`/`public`/`password_protected`). | Kept the frozen label `Password Protected`, removed the note, and corrected `PLAN.md` to the frozen enum with `Private` default. | Fixed |
| 9 | SSRF consistency | `SECURITY.md`, `ARCHITECTURE.md`, `AGENTS.md`, `DETECTION-RULES.md`, `DECISIONS.md` | Per-hop revalidation and the block-list agree everywhere; `SECURITY.md` §7.4 carried a note about an unresolved per-host rate-limit value. | Verified; converted the note to a plain statement pointing at `DECISIONS.md` Open Questions #7. | Accepted-as-is |
| 10 | Snapshot scope consistency | `PRD.md`, `DATABASE.md`, `DETECTION-RULES.md`, `SECURITY.md`, `PLAN.md`, `DECISIONS.md`, `AGENTS.md` | No contradiction found: HTML + headers = MVP, screenshot = Phase 2/Future, consistently. | Verified only. | Accepted-as-is |
| 11 | Phase consistency | `PLAN.md`, `CHANGELOG.md`, `ARCHITECTURE.md`, `DETECTION-RULES.md`, `NOTIFICATIONS.md`, `STATUS-PAGE.md`, `SECURITY.md`, `AGENTS.md` | Phase 0–10 list and names agree; `CHANGELOG.md` claims no implemented functionality and is explicitly "documentation only". | Verified only. | Accepted-as-is |
| 12 | Terminology consistency | `DETECTION-RULES.md`, `PRD.md` | `PRD.md` §10.6 used `KW-SPAM-*`; `DETECTION-RULES.md` used `BAS-HASH-*` and a typo `ADoC-008`. "target"/"site"/"endpoint" appear only as ordinary vocabulary, never as the monitored-entity noun (which is consistently "website"). | Replaced `KW-SPAM-*` → `RULE-KW-*`, `BAS-HASH-*` → `RULE-CNT-001`, and `ADoC-008` → `ADR-008`. | Fixed |
| 13 | Route consistency | all | `/` = login, `/admin`, `/status` consistently across all eleven documents. | Verified only. | Accepted-as-is |
| 14 | No implementation claims | all, `CHANGELOG.md` | Every document carries a "Specification only" banner; `CHANGELOG.md` states documentation-only in prose. | Verified only. | Accepted-as-is |
| 15 | Reference integrity | `SECURITY.md`, `DETECTION-RULES.md`, `STATUS-PAGE.md`, `PLAN.md` | Broken/incorrect cross-references: `SECURITY.md` §12 → `websites.lock_token`; throttling cited as `SECURITY.md` §3.4 in `STATUS-PAGE.md` and `PLAN.md` (the lockout lives in §2.4); `DETECTION-RULES.md` cited `DATABASE.md` §3.11 for `website_rule_settings` (§3.9). | Corrected each reference to the section that actually exists. | Fixed |
| 16 | Inline consistency notes | `PRD.md` (via `DETECTION-RULES.md`), `DETECTION-RULES.md` ×4, `NOTIFICATIONS.md` ×2, `SECURITY.md` ×3, `STATUS-PAGE.md` ×2 | Eleven `⚠️ Consistency note:` blockquotes raised genuine or already-resolved questions. | All eleven removed and replaced with resolved prose, or converted into §4 entries below. | Fixed |
| 17 | `settings` encryption scope | `SECURITY.md` | A note asked whether `settings` has credential keys at MVP; `DATABASE.md` §3.17 only defines `is_encrypted`. | Stated that MVP has no credential keys in `settings` and that any future credential row MUST set `is_encrypted = 1`. | Fixed |
| 18 | Probe timeout relationship | `SECURITY.md`, `PRD.md` | `SECURITY.md` §6 used a 15 s total / 5 s connect timeout while `PRD.md` §10.1 fixes `websites.timeout_seconds` default 10 s, with a note asking for ratification. | Defined the total request timeout as `timeout_seconds + 5 s` (default 15 s) with a fixed 5 s connect allowance, binding the operator-visible value to the engine's behaviour. | Fixed |

---

## 3. Naming reconciliations

All renames below move the lower-precedence document onto the spelling frozen in `DATABASE.md`.

**Renamed columns (used in `PLAN.md` → frozen name):**

| Non-frozen name used | Frozen name applied | Table |
| --- | --- | --- |
| `is_enabled` | `is_active` | `websites` |
| `check_interval` | `check_interval_seconds` | `websites` |
| `timeout` | `timeout_seconds` | `websites` |
| `expected_status_codes` | `expected_status` | `websites` |
| `last_check_at` | `last_checked_at` | `websites` |
| `rule_id` (as a `website_rule_settings` FK) | `detection_rule_id` | `website_rule_settings` |
| `auditable_type` / `auditable_id` | `subject_type` / `subject_id` | `audit_logs` |
| `lock_token` | `last_lock_token` | `websites` (in `SECURITY.md`) |

**Added frozen columns to `DATABASE.md`** (required by higher-precedence `PRD.md` `FR-14`/`FR-22`
but previously absent, so the schema was reconciled to the requirements):

| Column added | Table | Source requirement |
| --- | --- | --- |
| `follow_redirects` `TINYINT(1)` default `1` | `websites` | `PRD.md` `FR-22` |
| `note` `TEXT` nullable | `websites` | `PRD.md` `FR-14` |

**Enum value corrections:**

| Location | Before | After |
| --- | --- | --- |
| `PLAN.md` Phase 8 (`status_page_settings.visibility_mode`) | `private` / `public` / `password_protected` | `Private` / `Public` / `Password Protected` (default `Private`) |

**Section-number reference corrections:**

| Location | Before | After |
| --- | --- | --- |
| `DETECTION-RULES.md` §6.9 | `DATABASE.md` §3.11 | `DATABASE.md` §3.9 |
| `PLAN.md` Phase 2 | `SECURITY.md` §3.4 (login throttle) | `SECURITY.md` §2.4 |
| `STATUS-PAGE.md` §3.4 | `SECURITY.md` §3.4 / §2.4 | `SECURITY.md` §2.4 |

No table was renamed. The frozen table-name list in `DATABASE.md` §1.1 is unchanged.

---

## 4. Remaining assumptions / unresolved questions

These are genuine open questions that the documents could not resolve on their own. None of them
blocks the internal consistency of the set; each is either already recorded in `DECISIONS.md`
Open Questions or is a value-level decision for the implementing agent.

1. **Per-host outbound rate-limit value** — The mechanism (a per-host token bucket applied in the
   probe layer before each connection) is fixed in `SECURITY.md` §7.4 and `ARCHITECTURE.md` §12, but
   no numeric value is agreed. Carried in `DECISIONS.md` Open Questions #7. **Resolution needed
   before Phase 9.**
2. **Status-page password throttle parameters** — `STATUS-PAGE.md` §3.4 fixes the behaviour
   (throttle + backoff + audit) but not concrete per-IP / per-session numeric limits; the admin-login
   lockout in `SECURITY.md` §2.4 has values but they do not automatically apply to the status page.
   **Resolution needed before Phase 8.**
3. **Snapshot capture trigger** — Whether snapshots are captured on availability failures or only on
   security findings remains unresolved (`DECISIONS.md` Open Questions). The MVP scope
   (HTML + headers) is not in question. **Resolution needed before Phase 5.**
4. **`notification_logs` latency field** — `FR-71`'s "response time" has no dedicated `latency_ms`
   column; `NOTIFICATIONS.md` §11.1 records latency inside the existing `error`/metadata path and
   does not invent a column name. **Carried as an accepted-as-is design note.**
5. **Session driver** — `DATABASE.md` §3.3 selects database sessions but notes the `sessions` table
   becomes unused if the Redis driver is preferred; recorded in `DECISIONS.md` Open Questions.
6. **`settings` vs `status_page_settings` boundary** — Whether status-page config folds into
   `settings` is a possible future simplification; the frozen name `status_page_settings` is
   unchanged, so this is not a contradiction.
7. **`summary` reading in notification payloads** — `PRD.md` `FR-55` ("safe for notification
   payloads") and `FR-73` ("no keyword/domain lists") were reconciled by treating `summary` as a
   human-authored, non-identifying description (`NOTIFICATIONS.md` §8.2). This reading is now stated
   in both documents; it is recorded here only as the interpretation chosen.

---

## 5. Verdict

**The SiteSentinel documentation set is internally consistent and implementation-ready.**

All eleven documents agree on the canonical stack, the fixed routes, the two-dimensional
availability/security model, the severity and lifecycle state sets, the seven signal categories and
their `RULE-*` prefixes, the 36-rule count, the scoring thresholds and the correlation guard, the
retention windows, the snapshot scope, the SSRF policy, the MVP/future channel split, the status-page
visibility model and redaction boundary, and the Phase 0–10 roadmap. Every inline
`⚠️ Consistency note:` has been resolved or escalated into §4, every table/column name used outside
`DATABASE.md` now matches the frozen spelling, and every cross-document section reference points to a
section that exists. No document claims any functionality is implemented; the set remains a
specification, and `CHANGELOG.md` records a documentation-only `0.1.0`. The seven items in §4 are
value-level decisions for the implementing agent, not contradictions, and none of them weakens the
consistency of the specification set.
