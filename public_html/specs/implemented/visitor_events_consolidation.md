# Visitor Events Consolidation — roll up and prune old analytics

**Status: IMPLEMENTED 2026-08-29.** Built and verified same day; tables and the
setting are live on dev and the daily `RetentionSweep` runs the job. Current-state
reference is `docs/analytics.md` § Retention and rollup — read that, not this, for
how it works today.

**One decision changed at build time:** only page-view-class rows are rolled up and
pruned; conversion rows (cart / checkout / purchase / signup / list-signup) are kept
raw **forever**. They are rare and each carries per-row data the rollup cannot hold —
the order link revenue attribution joins on, the buyer's event sequence a funnel
reconstructs. So the distinct-vs-event stand-in below only ever applies to page-view
reports, and revenue / conversions / buyer funnels stay exact on old data. Sections
below that say every type is rolled up predate this decision.

**Anchor decision (kept):** `vse_page` stays in the rollup (top-pages is a
high-level report worth preserving).

## The problem in plain terms

`vse_visitor_events` only ever grows — there is no retention or rollup anywhere.
On developers today: **926,538 rows dating to 2021-01-01, of which 922,108
(99.5%) are older than 90 days**, and essentially all of them are page views (4
conversion rows in the whole table). The table is ~122 MB, most of it row
overhead and indexes across ~926 K rows, and every backup re-dumps it.

We want to **keep all the high-level statistics and let go of the fine-grained
detail on old data** — specifically the funnel reconstruction and per-event
fields — so the table stays small and every backup shrinks with it.

## What each report needs (so we keep the right things)

- `admin_analytics_stats_logic` — traffic by day / month / year. **Aggregate.**
- `admin_analytics_attribution_logic` — by source / campaign / medium.
  **Aggregate.**
- `admin_analytics_email_stats` — by content / medium / campaign / source.
  **Aggregate.**
- top-pages (by `vse_page`) — **Aggregate**, and the reason page stays in the
  rollup.
- conversions by type (cart / checkout / purchase / signup / list-signup).
  **Aggregate.**
- `admin_analytics_funnels_logic` — reconstructs per-visitor event *sequences*.
  **Fine-grained; needs individual rows. This is what we let go of on old data.**
- `abt_tests` request accounting — reads recent type counts; operates on live
  traffic, not history, so unaffected.

## Design

### Rollup table (`vsr_visitor_stats_rollup`, name TBD)

One row per day per dimension combination:

- `vsr_day` (date)
- `vsr_type` (int2 — the `VisitorEvent::TYPE_*` value)
- `vsr_page` (text — **kept**, per decision)
- `vsr_source`, `vsr_campaign`, `vsr_medium`, `vsr_content` (varchar)
- `vsr_is_404` (bool)
- `vsr_event_count` (int8)

Built as `INSERT ... SELECT date_trunc('day', vse_timestamp), vse_type,
vse_page, vse_source, ... , count(*) ... GROUP BY <all dimensions>` over rows
older than the retention window.

### Distinct visitors (separate rollup)

Unique-visitor counts cannot be recovered from a dimensioned rollup (you can't
re-distinct across pre-summed rows). Keep a second, coarse rollup:

- `vsr_day`, `vsr_type`, `vsr_unique_visitors` — `count(distinct vse_visitor_id)`
  per day per type.

This preserves "unique visitors per day" (a headline number) without keeping
per-visitor rows. Finer unique counts (unique by campaign, etc.) on old data are
given up — flag in Open Questions if any report needs them.

### What is dropped from old data

Everything not a rollup dimension: `vse_ip`, `vse_referrer`, `vse_visitor_id`
(after the distinct count is taken), `vse_ref_type` / `vse_ref_id`, exact
`vse_timestamp`, `vse_usr_user_id`, `vse_meta`, and the individual rows
themselves. Consequence: **funnels and any per-visitor path analysis are
available only within the retention window**, not on rolled-up history.

### The job

A scheduled task (daily), profile-agnostic:

1. Walk the days older than `now() - retention_window`, oldest-to-newest, and for
   **each day in its own transaction**: roll it up into both rollup tables
   (idempotent — a day already rolled up is skipped or upserted), then delete the
   raw rows just rolled up.
2. Never touch rows inside the window (funnels and fine-grained stay intact for
   recent traffic).
3. Backfill and steady-state are the same path: the first run just has the whole
   backlog of old days to process; it self-throttles by committing per day and is
   resumable if interrupted.

### Reporting

Reports read **raw** inside the window and the **rollup** outside it, choosing
per report via a small shared helper (given the requested range and the retention
window, it returns which side(s) to read). Where a report currently counts
distinct visitors per page or per source, it uses the rollup's event count as the
stand-in outside the window and labels the boundary. The funnels report stays
raw-only and returns "not available for this period" outside the window.

## Space / effect

Collapsing ~922 K page-view rows into a daily rollup (a few dimensions, a few
hundred distinct pages × ~2000 days, bounded further by how many source/campaign
combinations actually occur) takes the table from ~122 MB to single-digit MB,
and takes the same weight out of every DB dump and every backup incremental.

## Decisions (formerly the live agenda)

- **Retention window. DECIDED.** A core setting (`analytics_retention_days`,
  declared in `settings.json`) with discrete choices — 30 / 90 / 180 / 365 /
  **never** — defaulting to **90**. `never` makes the rollup job a no-op: every
  row stays raw forever, which is the clean opt-out for anyone who wants full
  fine-grained history. One-way caveat worth a release note: rows already rolled
  up and deleted cannot be un-rolled, so switching from a short window to a long
  one (or to `never`) only affects data still inside the old window — it does not
  resurrect detail already collapsed.
- **Page cardinality. DECIDED.** Store the **path only** — strip the query
  string before grouping, so `/product?id=1` and `/product?id=2` collapse to one
  `/product` rollup row. That kills the real bloat source (query-string variety)
  with trivial, predictable logic, and a stripped path is exactly the grain the
  top-pages report reads at. **No top-N cap** — path-stripping alone bounds the
  width; a cap is machinery held in reserve for a specific site that ever proves
  it needs one, addable later without changing the rollup's shape. Raw rows
  inside the window keep the full URL untouched.
- **Distinct-visitor granularity. DECIDED.** Keep **per-day-per-type only**. That
  preserves the one headline that genuinely needs a distinct-count (unique
  visitors per day), while the campaign/source *volume* questions are already
  answered by the main rollup, which carries those dimensions as event counts.
  Adding campaign/source to the distinct rollup is a permanent size cost against
  a unique-by-campaign-on-old-data report that does not exist; if one ever does,
  it wants the raw window anyway. Once the raw rows are deleted this grain cannot
  be recomputed, so per-day-per-type is the floor for historical uniques.
- **Distinct counts on old data. DECIDED.** Several reports show
  `COUNT(DISTINCT vse_visitor_id)` — unique *people*, not events: the stats-by-day
  top-line, top-pages, 404-pages, and attribution visits-by-source. A pre-summed
  rollup can only reproduce the per-day-per-type distinct count we keep; it cannot
  reproduce distinct people per page or per source. **Decision: on rolled-up data,
  top-pages and attribution-visits fall back to event count** (the main rollup's
  `vsr_event_count`) as a stand-in for visitor count. The *ranking* is preserved
  (the busiest pages stay the busiest); only the absolute number changes meaning
  (views, not unique visitors) outside the window, and the report labels that at
  the boundary. The alternative — widening the distinct rollup to per-day-per-page
  and per-day-per-source — was rejected: it reintroduces almost the full page-
  dimension cardinality we are shedding, for a precision a ranking does not trade
  on. Recent (in-window) data is unaffected; it is still raw and still counts real
  visitors.

- **Reporting seam. DECIDED.** Per-report range branching, not a stitched-together
  view. The reports do not share a query shape — top-pages groups by page,
  attribution by source/campaign, stats by day — so a single unifying view would
  have to paper over columns that genuinely differ (raw carries visitor IDs, the
  rollup carries counts), and funnels opts out either way because it needs real
  rows. Each report chooses raw / rollup / split-at-boundary for the requested
  range, backed by one small shared helper that, given a date range and the
  retention window, returns which side(s) to read. Reports are written against
  this shape once.
- **Backfill. DECIDED.** Backfill — roll-forward-only would never touch the 922 K
  existing old rows, so the table stays 122 MB and the stated problem goes unfixed.
  No separate one-time script: the recurring daily job is written idempotent and
  day-scoped, so the **first run simply has more days to chew through**. It walks
  old days oldest-to-newest, rolling up and deleting **one day per transaction**,
  resumable if interrupted (an already-rolled day is skipped/upserted). That
  self-throttles — no table-wide lock, no giant transaction — and backfill and
  steady-state share exactly one code path.
- **The 2021-01-01 floor. DECIDED.** Not job logic — a one-time data check in the
  rollout. The oldest rows sit at exactly `2021-01-01 00:00:02`, which usually
  means seeded/imported filler. Before the first backfill on each site, spot-check
  those rows; if they are obviously synthetic (all the same second, no real
  visitor IDs / referrers / pages) **delete them outright** rather than enshrine
  fake numbers in the permanent rollup — if they are a legitimate import they roll
  up like anything else. The job itself carries no hard-coded floor date and
  treats every in-range row the same.
- **Bot traffic.** Already filtered at write time (`crawlerDetect`), so the
  rollup inherits clean data — no extra filtering needed. (Noted so we do not
  re-solve it.)
- **Other sites. DECIDED.** Design holds across profiles — no gap to close. The
  job is profile-agnostic (runs per site). Conversion-heavy sites are the *easy*
  case: every conversion report counts events (`SUM`), which the rollup reproduces
  exactly, so they never touch the lossy distinct-visitor proxy — that seam only
  affects page-view reports. The one thing that varies between sites, page
  cardinality, is already bounded uniformly by path-stripping. Per-site work is
  limited to the existing 2021-floor pre-flight check at rollout. The page-view-
  heavy sample (developers) stressed the harder half.
