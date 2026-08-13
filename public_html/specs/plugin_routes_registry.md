# Plugin routes registry — plugins can own top-level URLs

**Status: DRAFT 2026-08-13 — unbuilt, and DEFERRED past the current release
by owner decision (2026-08-13). Intended as the first post-release spec.
Split out of `specs/core_api_simplification.md` (whose R5 records the
deferral decision); nothing in that spec or in
`specs/core_api_mechanical_pass.md` depends on this one.**

## Intent

A third-party plugin should be able to own a top-level URL the way the
first-party plugins do. Today it structurally cannot: core `serve.php`
hardcodes ~28 routes belonging to store, event_manager, and bookings
(`/checkout`, `/cart`, `/product/{slug}`, `/events`, `/event/{slug}`,
`/pricing`, `/profile/orders`, ...), and `RouteHelper.php:1522-1545` filters
every route a plugin's own `serve.php` declares against
`/{plugin}`, `/profile/{plugin}`, `/admin/{plugin}` — anything else is
logged and discarded. `plugins/store/serve.php` documents the consequence in
its own header: plugins cannot own top-level dynamic routes. A third-party
commerce plugin cannot ship `/checkout`; it gets `/myplugin/checkout` or
nothing.

The namespace filter exists for a good reason — it makes hijacking a core
URL structurally impossible — and this spec preserves that guarantee through
collision refusal rather than a namespace fence.

## The change

- A `routes` key in `plugin.json`, carrying the same option vocabulary
  serve.php routes use today (URL placeholders, `check_setting` /
  `requires_setting`, `min_permission`, model binding), merged by a route
  registry on the `includes/joinery_direct/DirectKinds.php` template: a
  malformed declaration is logged and skipped, never fatal; a deactivated
  plugin's routes leave the merged set (its URLs 404); `resetForTests()`.
- **Core wins on collision; a colliding plugin route is refused and logged**
  — the rule the vault scope registry uses, for the same reason: override
  semantics on a URL are a hijack, not an extension. The collision check
  must cover the whole resolution surface, not just serve.php's table: core
  views auto-route (`views/foo.php` → `/foo`), so a plugin declaring
  `/login` is refused because `views/login.php` exists, whether or not
  serve.php lists it. Two plugins declaring the same route are both refused,
  with both named in the log — deterministic regardless of plugin iteration
  order.
- **Fail closed.** A route declaration that cannot be fully honored (unknown
  option key, unresolvable view, malformed gate) is refused entirely — a
  broken declaration must become a 404, never an ungated route.
- The ~28 hardcoded plugin routes in core `serve.php` move to the owning
  plugins' declarations verbatim; the bookings literal-view-path
  inconsistency (`serve.php:118-119` declares a full view path where the
  store/event_manager entries use `plugin` + relative view) disappears in
  the move.
- `logic/booking_logic.php` and `logic/items_logic.php` — plugin business
  logic living in core (the second currently fatals when its plugin is
  absent until the mechanical pass guards it) — move home to
  `plugins/{name}/logic/`, which the API face already resolves.
- The RouteHelper namespace filter is replaced by the registry merge.
  Namespaced plugin routes declared in plugin `serve.php` keep working
  unchanged through the transition.

## The reduced middle path (recorded, declined for now)

The structural blocker is one function. Replacing the namespace filter with
a collision check — plugin `serve.php` routes may be top-level, refused and
logged on collision with any core route, core view, or another plugin —
delivers the third-party capability without the declarative registry or the
core-entry migration. It was considered for the release and declined with
the rest of this spec. If third-party demand arrives before this spec is
built, that reduced form remains the cheap first step, and this spec's
collision rules apply to it unchanged.

## Deferral rationale (so it is not re-litigated)

Deferring this spec creates **no compatibility debt**: nobody can build
against a capability that does not exist, so adding the registry later
breaks nothing and invalidates nothing — the core serve.php entries migrate
invisibly whenever it lands. Building it pre-release meant working in the
front controller, the highest-blast-radius file in the system, at the
riskiest possible time. Until it lands, the developer docs state the
namespace limitation plainly.

## Tests

- Registry merge, precedence, and refusal: core-wins collision (against a
  serve.php route AND against a bare core view), plugin-vs-plugin double
  refusal with both named, malformed declaration skipped and logged,
  deactivated plugin's routes 404, `resetForTests()`.
- Gate preservation: a migrated route's `min_permission` /
  `requires_setting` behavior is byte-identical before and after the move —
  the migration of the 28 entries is the behavior-preservation proof, in the
  same role `mailbox_reseal_test.php` played for the vault registry.
- Fail-closed: an unresolvable or partially-valid declaration yields 404,
  never an ungated route.

## Docs

- `docs/routing.md` — the plugin-routes section describes the `routes` key
  as the mechanism for top-level plugin URLs, current-state voice; the
  namespace limitation language is removed in the same change that removes
  the limitation, not before.
- `docs/plugin_developer_guide.md` — the routing passage gains the
  declaration shape and the collision rules.

## Non-goals

- No change to core view auto-routing or the theme resolution chain.
- No route override semantics — collision is always refusal, never
  precedence between plugins.
- No change to the namespaced plugin-route mechanism; it remains the default
  home for plugin pages that do not need a top-level URL.
