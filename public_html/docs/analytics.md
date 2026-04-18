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
| `CART_ADD` | `ShoppingCart::add_item()` after the item is pushed | — |
| `CHECKOUT_START` | `views/cart.php` when the checkout form renders, guarded by `$_SESSION['checkout_started']` | — |
| `PURCHASE` | `logic/cart_charge_logic.php` after `STATUS_PAID` | `ref_type='order'`, `ref_id=ord_order_id` |
| `SIGNUP` | `User::CreateCompleteNew()` when a genuinely new user is created | `ref_type='user'`, `ref_id=usr_user_id` |
| `LIST_SIGNUP` | `User::add_user_to_mailing_lists()` after each successful subscription | `ref_type='mailing_list'`, `ref_id=mlt_mailing_list_id` |
| `COUPON_ATTEMPT` | `SessionControl::capture_marketing_coupon()` for both valid and invalid codes | `vse_meta=<code>` (never in `vse_source`) |

The `$_SESSION['checkout_started']` flag is cleared in two places so a fresh cart cycle gets a fresh `CHECKOUT_START`:
- `ShoppingCart::clear_cart()` — cart emptied
- `cart_charge_logic.php` — after the `PURCHASE` event fires

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
