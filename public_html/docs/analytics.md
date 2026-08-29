# Analytics: Visitor Events, Conversions & Attribution

The platform tracks visitor behavior in one table, `vse_visitor_events`, covering both page-view traffic and named conversion events. This doc covers the conventions for recording events and the reporting that consumes them.

## Event types

Constants on `VisitorEvent` (`data/visitor_events_class.php`):

| Constant | Value | Purpose |
|---|---|---|
| `TYPE_PAGE_VIEW` | 1 | A page view (default for `save_visitor_event()`) |
| `TYPE_COOKIE_CONSENT` | 2 | Cookie consent acknowledgment |
| `TYPE_CART_ADD` | 3 | Item added to shopping cart |
| `TYPE_CHECKOUT_START` | 4 | Visitor reached checkout with cart items |
| `TYPE_PURCHASE` | 5 | Order completed (payment cleared) |
| `TYPE_SIGNUP` | 6 | New user account created |
| `TYPE_LIST_SIGNUP` | 7 | Subscribed to a mailing list (one event per list) |
| `TYPE_COUPON_ATTEMPT` | 8 | Arrived with `?coupon=CODE` URL (diagnostic, not a conversion) |

## Recording events

### Bot filtering

Before any row is inserted, `save_visitor_event()` short-circuits on `SessionControl::crawlerDetect($USER_AGENT)`. The filter is a case-insensitive substring match against a list of known bot patterns (Googlebot, bingbot, facebookexternalhit, Ahrefs, Semrush, curl, python-requests, etc.), plus any request with an empty UA.

**Historical note:** The filter was silently reporting every real bot as *not a bot* for a long time due to a reversed `strpos()` — so bot traffic was being counted in `vse_visitor_events`. When the filter was fixed, page-view totals typically drop by 20–40% on small sites as bot traffic stops being recorded. If you compare pre- and post-fix analytics numbers, expect that discontinuity.

The same filter gates A/B test counters — see [`ab_testing.md`](ab_testing.md).

### Recording events

The canonical call is on `SessionControl`:

```php
$session->save_visitor_event($type, $is_404 = FALSE, $ref_type = NULL, $ref_id = NULL, $meta = NULL);
```

- `$type` — a `VisitorEvent::TYPE_*` constant
- `$ref_type` / `$ref_id` — a polymorphic reference to the entity the event is about (e.g. `'order'` + `ord_order_id`)
- `$meta` — free-form metadata for diagnostic rows (e.g. attempted coupon code for `TYPE_COUPON_ATTEMPT`)

### UTM auto-attribution

`save_visitor_event()` stamps UTM values onto every event row:

1. **Page views** pull UTM from the current request query string; values are also mirrored to `$_SESSION['utm_*']` on first touch for later reuse.
2. **Conversion events** (non-page-view types) fall back to the session UTM when the request has no query string — so a `PURCHASE` event fired from a POST handler still carries the original source.

This means conversion counts and revenue can be grouped directly by `vse_source` without joining back through the event stream.

## Conversion hook sites

| Event | Canonical site | Reference columns |
|---|---|---|
| `CART_ADD` | `ShoppingCart::add_item()` (store plugin) after the item is pushed | — |
| `CHECKOUT_START` | `plugins/store/views/cart.php` when the checkout form renders, guarded by `$_SESSION['checkout_started']` | — |
| `PURCHASE` | `plugins/store/logic/cart_charge_logic.php` after `STATUS_PAID` | `ref_type='order'`, `ref_id=ord_order_id` |
| `SIGNUP` | `User::CreateCompleteNew()` when a genuinely new user is created | `ref_type='user'`, `ref_id=usr_user_id` |
| `LIST_SIGNUP` | `User::add_user_to_mailing_lists()` after each successful subscription | `ref_type='mailing_list'`, `ref_id=mlt_mailing_list_id` |
| `COUPON_ATTEMPT` | `SessionControl::capture_marketing_coupon()` for both valid and invalid codes | `vse_meta=<code>` (never in `vse_source`) |

The `$_SESSION['checkout_started']` flag is cleared in two places so a fresh cart cycle gets a fresh `CHECKOUT_START`:
- `ShoppingCart::clear_cart()` — cart emptied
- `cart_charge_logic.php` — after the `PURCHASE` event fires

## Retention and rollup

`vse_visitor_events` is dominated by page views — a busy site accumulates a
million of them against a handful of conversions. To keep the table and every
backup small without losing the high-level statistics, page-view rows are rolled
up into daily totals once they age past a window, and the individual rows are
deleted. Conversion rows are never rolled up: they are rare and each carries
per-row data the rollup cannot hold (the order link revenue attribution joins on,
the visitor's event sequence a funnel reconstructs), so they stay raw forever.

**The window** is the `analytics_retention_days` setting (Settings → Data
retention): 30 / 90 / 180 / 365 days, default 90, or *never* (`0`) to keep every
page-view row in full. It follows the platform's `0 = off` retention convention.

**The rollup** is two tables, both written and read only through SQL:

- `vsr_visitor_stats_rollup` — one row per day per dimension combination
  (`vsr_type`, `vsr_page`, `vsr_source`, `vsr_campaign`, `vsr_medium`,
  `vsr_content`, `vsr_is_404`) carrying `vsr_event_count`. `vsr_page` is the path
  only — the query string is stripped before grouping, so `/product?id=1` and
  `/product?id=2` collapse to one `/product` row.
- `vsu_visitor_daily_uniques` — one row per day per type carrying
  `vsu_unique_visitors`, the `COUNT(DISTINCT vse_visitor_id)` for that day. A
  distinct-visitor count cannot be recovered from a summed rollup, so it is kept
  separately, at day-and-type grain.

**The job** is `VisitorEvent::rollupAndPrunePageViews()`, the method form of the
class's `$retention_policy`, run by the daily `RetentionSweep` (there is no
separate task). It processes whole days older than the window, oldest first, one
day per transaction — rolling a day into both tables and deleting that day's raw
rows together, so an interrupted run leaves no day half-summarised or
double-counted. Backfill and steady state are the same path; a first run on a
site with years of history is capped at `ROLLUP_MAX_DAYS_PER_RUN` days and
continues on the next run.

**Reading across the boundary.** Recent page views are raw rows; older ones are
rollup rows. `AnalyticsRollup::pageview_relation()` returns one relation that
unions the two — seamless because a day is in exactly one side — so each report
reads a single shape. `AnalyticsRollup::VISITORS` counts distinct real visitors
where the rows are raw and falls back to the event count where they are rolled
up: the ranking a report shows is preserved, but the older span reports page
views rather than unique visitors. Reports whose range reaches before
`AnalyticsRollup::proxy_boundary()` show that caveat inline. Conversion numbers
and revenue read raw rows only and are exact for any range. Funnels read raw rows
too — conversion-event steps span any range, page-URL steps only reach back to
the window.

## Attribution reporting

Admin page: **Statistics → Attribution** (`/admin/admin_analytics_attribution`)

Filters: date range, optional source filter, optional campaign filter, include-test-orders toggle.

Sections:
1. **Channels overview** — grouped by `vse_source` with visits, signups, list signups, cart-adds, checkouts, purchases, revenue, conversion rate
2. **Time-series chart** — daily visits by top-5 sources (Chart.js 2.8.0)
3. **Campaign drilldown** — grouped by (source, campaign) to spot which campaign within a channel is producing results

### Query conventions

Every Part E query enumerates specific `vse_type` values — no bare `COUNT(*)` against `vse_visitor_events`, no `vse_type >= N` range filters. The conversion set is:

```sql
WHERE vse_type IN (TYPE_CART_ADD, TYPE_CHECKOUT_START, TYPE_PURCHASE,
                   TYPE_SIGNUP, TYPE_LIST_SIGNUP)
```

Source normalization happens in the query (`LOWER(vse_source)`) so `reddit` / `Reddit` / `REDDIT` collapse. `NULL` sources are coalesced to `'(direct)'`. Test orders are excluded from revenue unless the admin checks "Include test orders".

### Attribution model

Implicit **last-touch on the event row**: the UTM that was in session when the conversion fired. Multi-touch models (first-touch / linear / time-decay / data-driven) are not implemented. The speculative design for those is in `specs/FUTURE_attribution_models.md`.

## Adding a new event type

1. Add a `const TYPE_X = N` to `VisitorEvent`
2. Wire the call site(s) via `SessionControl::save_visitor_event(VisitorEvent::TYPE_X, ...)`
3. If the event is a conversion that should appear in attribution reports, add its column to the Part E channels/campaigns queries (conditional `SUM(CASE WHEN vse_type = :type_x THEN 1 ELSE 0 END)`)
4. If the event uses a reference entity, document the `ref_type` string and target table
5. Decide whether it is high-volume, low-value traffic that should be rolled up and pruned with the page views (add it to `VisitorEvent::ROLLUP_TYPES`) or a rare, per-row-precious record that should stay raw forever (leave it out — the default). Anything carrying a reference entity or revenue belongs raw.
