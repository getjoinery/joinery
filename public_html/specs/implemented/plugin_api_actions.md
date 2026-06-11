# Plugin API Actions Spec

**Purpose:** Make the REST API's action layer plugin-aware (Phase 1, core),
then use it to expose the DNS-filtering plugin's existing logic as the API
surface both ScrollDaddy mobile apps are written against (Phase 2, plugin).
Phase 2 also adds the `sbr_hard_block` column the apps' tunnel/VPN layers
need.

**Created:** 2026-06-11

**Status:** Implemented 2026-06-11.

**Supersedes:** the "ScrollDaddy API actions (plugin)" item of
`scrolldaddy_ios_app.md` § Server-side work — that section is replaced by a
pointer to this spec (update it as part of Phase 2). The iOS and Android app
specs both depend on this spec and cannot start their API-consuming phases
before it lands.

**Depends on (implemented):** per-user session API keys
(`implemented/user_session_api_keys.md`) and the action/form endpoints in
`api/apiv1.php` + `includes/ApiActionEndpoint.php` / `ApiFormEndpoint.php`.

---

## Problem

The logic-API rollout covered core only. 25 core logic files expose
`{action}_logic_api()` opt-ins; the DNS plugin's ten logic files expose none,
and part of its write surface (custom rules, filter toggles) lives in raw
AJAX endpoints with inline business logic.

Catching the plugin up is not just adding opt-ins, because the action layer
itself cannot see plugins:

1. **Discovery is core-only.** `GET /api/v1/actions` globs
   `PathHelper::getIncludePath('logic')` (`api/apiv1.php:542`) — plugin
   actions would never be listed.
2. **Invocation has no plugin context.** `ApiActionEndpoint::resolve()`
   (`includes/ApiActionEndpoint.php:49`) resolves
   `getThemeFilePath('{action}_logic.php', 'logic')`, whose plugin fallback
   keys off `RouteHelper::getCurrentPlugin()` — and `/api/v1/...` is not a
   plugin-namespaced route, so there is no current plugin. The action name
   regex (`^[a-zA-Z0-9_]+$`, `ApiActionEndpoint.php:43`) also leaves no room
   to express which plugin an action belongs to.
3. **The form endpoint mirrors the same gap.** `ApiFormEndpoint` resolves
   identically (`includes/ApiFormEndpoint.php:49`), so plugin actions could
   never serve server-driven form definitions either.

Phase 1 fixes the platform gap once, for every plugin. Phase 2 is the first
consumer.

---

## Phase 1 — Plugin-aware API actions (core)

### Namespaced action names

A plugin action is addressed as `{plugin}/{action}`, where `{plugin}` is the
plugin directory name:

```
POST /api/v1/action/dns_filtering/device_edit
GET  /api/v1/form/dns_filtering/device_edit
```

Core actions are unchanged: single-segment names (`/api/v1/action/register`)
resolve exactly as today, theme chain included. The namespace makes
collisions structurally impossible — a plugin action can never shadow a core
action or another plugin's, and discovery output is self-describing.

### Resolution rules

When the action path has two segments (`$url_segments[3]` = plugin,
`$url_segments[4]` = action):

1. Validate both segments against `^[a-z0-9_]+$` (same traversal guard as
   today, applied per segment).
2. The plugin must be an **active** plugin (`PluginHelper`) — inactive or
   unknown plugin → the same `Unknown action` 404 as a missing core action,
   so the response does not reveal which plugins exist.
3. Resolve the logic file **directly** to
   `plugins/{plugin}/logic/{action}_logic.php`. No theme chain — themes do
   not override plugin logic, and the explicit path avoids depending on
   request plugin-context.
4. From there the existing contract applies unchanged: the file must define
   `{action}_logic_api()` (opt-in) and `{action}_logic()`; `requires_session`
   drives pre-auth vs. authenticated dispatch; results translate through
   `api_translate_logic_result()`.

### Integration points (all of them, decided once)

Every place that maps an action name to a logic file gains the same
two-segment handling:

| Touch point | File | Change |
|---|---|---|
| Action invocation (pre-auth + authenticated) | `includes/ApiActionEndpoint.php` `resolve()` | two-segment resolution per the rules above; action label in logs/`RequestLogger` becomes the full `{plugin}/{action}` string |
| Form definitions | `includes/ApiFormEndpoint.php` | identical two-segment resolution (it duplicates the convention today; extract one shared resolver used by both endpoints rather than patching it twice) |
| Action discovery | `api/apiv1.php` actions endpoint | after the core glob, glob `plugins/{plugin}/logic/*_logic.php` for each **active** plugin; list entries under their namespaced name with the same `description` / `requires_session` / `has_form` fields |
| URL segment dispatch | `api/apiv1.php` (segments after `action` / `form`) | pass through the extra segment; sessionless pre-auth dispatch path included |

Forward note: the FUTURE descriptor work (`FUTURE_descriptor_consumers.md`)
reads the same logic files; when it lands, namespaced names carry over as-is
(a descriptor in a plugin logic file is discovered under
`{plugin}/{action}`). Nothing in Phase 1 blocks or is blocked by it.

### Out of scope for Phase 1

No changes to authentication, key permissions (`apk_permission >= 2` for
sessioned actions stands), result translation, or the logic-function
contract. No plugin action is added in Phase 1 itself — Phase 2 is the first
consumer, and core ships Phase 1 inert.

---

## Phase 2 — ScrollDaddy API surface (dns_filtering plugin)

All filter business logic already exists in the plugin's logic layer and
model classes; Phase 2 exposes it. Design rules carried over from the web
product and the app specs:

- **Tier gating is server-enforced.** Every gate already in the web/AJAX
  paths (`SubscriptionTier::getUserFeature()`) applies identically to API
  calls; responses include the user's flags so clients can render locked
  states, but the server rejects gated writes regardless.
- **"Allow" on the always-on block means "no row"** — API save semantics are
  the web editor's semantics because both call the same logic functions.

### Actions

| Action (`dns_filtering/…`) | Source | Notes |
|---|---|---|
| `devices` | existing `devices_logic()` | list devices; each entry gains `doh_url` (`https://{dns_filtering_dns_host}/resolve/{sdd_resolver_uid}`, the construction in `activation_logic.php:33-41`), DoT hostname, active-block summary, and the merged hard-block list (below) |
| `device_edit` | existing `device_edit_logic()` | create + rename; create auto-creates the always-on block; `scrolldaddy_max_devices` gate already inside |
| `device_delete` | existing `device_delete_logic()` | permanent delete |
| `device_soft_delete` | existing `device_soft_delete_logic()` | deactivate |
| `block_list` | new thin logic | scheduled + always-on blocks for one owned device |
| `block_edit` | existing `scheduled_block_edit_logic()` | read one block (filters + services + rules + schedule) and save with the web editor's submit semantics; `scrolldaddy_advanced_filters` / `scrolldaddy_max_scheduled_blocks` gates already inside |
| `block_rule_add` | **converted** from `ajax/block_rule_add.php` | add custom rule (block/allow, optional `hard_block`); `scrolldaddy_custom_rules` gate |
| `block_rule_delete` | **converted** from `ajax/block_rule_delete.php` | delete custom rule via ownership chain |
| `block_filter_set` | **converted** from `ajax/block_filter_set.php` | single filter/service toggle (the app's save-on-change editor needs it, same as the web editor) |
| `catalog` | new thin logic | `ScrollDaddyHelper::$filters`, `$service_categories`, `$services`, with `getRestrictedFilters()` keys flagged `advanced: true` so the app never hardcodes the catalog or the gating |
| `account_summary` | new thin logic | tier name + the five feature flags (`scrolldaddy_max_devices`, `scrolldaddy_max_scheduled_blocks`, `scrolldaddy_custom_rules`, `scrolldaddy_advanced_filters`, `scrolldaddy_query_logging`) + device count vs. max |
| `querylog` | existing `querylog_logic()` | device query log fetched from the DNS server; `scrolldaddy_query_logging` gate and line-count clamping already inside |
| `purge_querylog` | **converted** from `ajax/purge_querylog.php` | truncate a device's query log on the DNS server |
| `test_domain` | **converted** from `ajax/test_domain.php` | test one domain against a device's filter (today GET-based AJAX; actions are POST — the web wrapper keeps its GET contract) |
| `scan_url` | **converted** from `ajax/scan_url.php` | fetch a page, extract external domains, batch-test each against the device's filter; the SSRF target/redirect validation moves into the logic layer **unchanged** |

All actions are sessioned (`requires_session => true`); the acting user comes
from the session-key user via the standard simulation in
`ApiActionEndpoint::execute()`. Device/block/rule ownership checks remain in
the logic layer exactly as they are in the web paths.

Out of Phase 2 scope: `activation` and `mobileconfig`. They are not deferred —
they are **not applicable**: both exist to onboard devices *without* the app
(web flow generating an Apple configuration profile / DoH setup
instructions). The apps replace them with native configuration
(`NEDNSSettingsManager` on iOS, the VPN service on Android), so no client
would ever call them as JSON actions.

### AJAX conversion pattern

The six converted endpoints currently authenticate, ownership-check, gate,
and mutate inline. Each moves its body into a real logic function
(`plugins/dns_filtering/logic/block_rule_add_logic.php`, etc.) returning a
`LogicResult`; the AJAX endpoint becomes a thin wrapper that calls the logic
function and emits its existing JSON shape, so the web editor's JS contract
is unchanged (including `test_domain`'s GET interface). One copy of the
rules, called from both surfaces — the same move the core API rollout made.

### Input normalization

`device_edit_logic`, `device_delete_logic`, and `device_soft_delete_logic`
read `$_REQUEST` directly. Under the API, `ApiActionEndpoint::execute()`
populates `$_POST` from the JSON body (`ApiActionEndpoint.php:134`) — but
PHP's `$_REQUEST` is built at request start and does **not** see that, so
these functions would silently miss their inputs. Normalize them to read from
the `$input` parameter (which the endpoint passes as merged GET + body
params), the pattern `devices_logic()` and `querylog_logic()` already follow.
This is a behavior-preserving cleanup for the web path and a correctness
requirement for the API path.

### `sbr_hard_block` column

Add to `SdScheduledBlockRule::$field_specifications`
(`plugins/dns_filtering/data/scheduled_block_rules_class.php`):

```php
'sbr_hard_block' => array('type'=>'int2', 'default'=>0),
```

(`int2` matching the class's existing boolean idiom, `sbr_is_active` /
`sbr_action`. Schema syncs via the plugin sync step — no migration.)

Semantics, per the app specs:

- Settable only on **block**-action custom rules, and in v1 only on rules
  belonging to the device's **always-on block**. The tunnel/VPN layer syncs a
  static hostname list with no scheduler, so a hard-block rule on a
  time-windowed scheduled block would be enforced 24/7 at the connection
  level while staying scheduled at the DNS level — a silent semantic split.
  Restricting the flag to the always-on block keeps "hard block" meaning
  exactly "always blocked, at both layers." Schedule-aware hard blocking
  (shipping schedule windows in the sync payload) is a future addition if an
  app ever needs it. `block_rule_add` rejects `hard_block` on scheduled-block
  rules; rides the existing `scrolldaddy_custom_rules` tier gate (no new
  flag).
- **The resolver ignores the column** — DNS-level behavior is unchanged. It
  exists for client-side connection-level enforcement (the iOS packet-tunnel
  / Android VPN layer).
- Device API responses (`devices`, `block_list`) include the device's
  **hard-block hostname list**: active, block-action, `hard_block` rules on
  the device's always-on block, de-duplicated — the list the app syncs into
  its tunnel extension.
- `block_rule_add` accepts an optional `hard_block` boolean; the web editor
  UI for it is **not** part of this spec (the flag is app-driven; web
  exposure can ride a later editor change).

---

## Concerns & Edge Cases

- **Plugin enumeration:** discovery lists actions only for active plugins,
  and resolution 404s identically for "plugin not active" and "no such
  action" — the API does not leak the installed-plugin list beyond what its
  actions already imply.
- **Logging labels:** `RequestLogger` operation strings and error messages
  use the full namespaced name so API logs distinguish
  `dns_filtering/device_edit` from any future core `device_edit`.
- **Catalog size:** `ScrollDaddyHelper::$services` is ~1000 entries; the
  `catalog` response is static per deployment, so mark it cacheable
  client-side (the apps fetch it on launch, not per screen).
- **`scan_url` is the one heavy action:** it fetches a third-party page and
  batch-tests extracted domains via `curl_multi`, so responses can take
  seconds — same as the web AJAX today. The SSRF guard
  (`scan_url_validate_target()`, host and redirect validation) must move into
  the logic function verbatim and be re-verified after the move; it is the
  security boundary for a server-side URL fetch now reachable by any API
  key holder. Re-verify specifically: scheme allowlist, all-resolved-IPs
  private-range check failing closed, `CURLOPT_RESOLVE` pinning intact,
  `CURLOPT_FOLLOWLOCATION` still false, every redirect hop re-validated, and
  the guard's exit-style rejections correctly rewritten as `LogicResult`
  errors. Volume is already bounded upstream: the API's per-IP rate limiters
  (`apiv1.php:123-134`) run before action dispatch, including pre-auth.
- **LogicResult shapes:** the existing plugin logic functions return
  view-shaped page vars. Where a function's success payload is unusable as
  JSON (e.g. embedded FormWriter objects), the action returns a cleaned data
  array — same translation discipline the core `_api()` opt-ins applied.
  Verify each action's JSON output against what the app specs consume.
- **Two clients, one contract:** the Android spec consumes exactly the iOS
  surface; nothing here is platform-specific. Any field either app needs gets
  added to the shared action response, not forked.

---

## Scope

**Phase 1 (core):**
- Shared two-segment resolver used by `ApiActionEndpoint` and
  `ApiFormEndpoint`; segment validation + active-plugin check.
- Plugin-aware action discovery in `api/apiv1.php`.
- Segment pass-through for `/api/v1/action/...` and `/api/v1/form/...`
  (pre-auth and authenticated paths).

**Phase 2 (dns_filtering):**
- `_logic_api()` opt-ins on `devices`, `device_edit`, `device_delete`,
  `device_soft_delete`, `scheduled_block_edit` (as `block_edit`), `querylog`.
- New logic files: `block_list_logic.php`, `catalog_logic.php`,
  `account_summary_logic.php`.
- AJAX→logic conversion: `block_rule_add`, `block_rule_delete`,
  `block_filter_set`, `purge_querylog`, `test_domain`, `scan_url`
  (endpoints become thin wrappers).
- `$_REQUEST` → `$input` normalization in the three device logic files.
- `sbr_hard_block` column + hard-block list in device responses +
  `hard_block` input on `block_rule_add`.
- Tests: the AJAX→logic conversion makes the SSRF validator a directly
  callable function for the first time — add a test under `tests/` covering
  the rejection cases (non-http(s) scheme, loopback/private/link-local IPv4
  and IPv6 targets, hostname resolving to a private address, redirect hop to
  a private address) and the accept case. This is the spec's one security
  boundary; it gets locked down in the same change that moves it.
- Update `scrolldaddy_ios_app.md` § Server-side work item 2 to reference this
  spec.

Phases are independently shippable; Phase 1 alone changes no behavior for
existing API consumers.

---

## File Map

```
Phase 1 (core):
includes/
  ApiActionEndpoint.php        # two-segment resolve via shared resolver
  ApiFormEndpoint.php          # same
api/
  apiv1.php                    # discovery globs active plugins; segment dispatch

Phase 2 (plugin):
plugins/dns_filtering/
  data/scheduled_block_rules_class.php   # + sbr_hard_block
  logic/devices_logic.php                # + _logic_api(); doh_url + hard-block list in payload
  logic/device_edit_logic.php            # + _logic_api(); $input normalization
  logic/device_delete_logic.php          # + _logic_api(); $input normalization
  logic/device_soft_delete_logic.php     # + _logic_api(); $input normalization
  logic/scheduled_block_edit_logic.php   # + _logic_api() (exposed as block_edit)
  logic/querylog_logic.php               # + _logic_api()
  logic/block_list_logic.php             # new
  logic/block_rule_add_logic.php         # new (from ajax)
  logic/block_rule_delete_logic.php      # new (from ajax)
  logic/block_filter_set_logic.php       # new (from ajax)
  logic/purge_querylog_logic.php         # new (from ajax)
  logic/test_domain_logic.php            # new (from ajax)
  logic/scan_url_logic.php               # new (from ajax; SSRF guard moves intact)
  logic/catalog_logic.php                # new
  logic/account_summary_logic.php        # new
  ajax/block_rule_add.php                # thin wrapper
  ajax/block_rule_delete.php             # thin wrapper
  ajax/block_filter_set.php              # thin wrapper
  ajax/purge_querylog.php                # thin wrapper
  ajax/test_domain.php                   # thin wrapper (keeps GET contract)
  ajax/scan_url.php                      # thin wrapper

specs/
  scrolldaddy_ios_app.md       # § Server-side work item 2 → pointer to this spec
```

Bump `@version` on every modified file; new files start at 1.0. Run `php -l`
and `validate_php_file.php` on all touched PHP files.

---

## Documentation

- **`docs/api.md`** — add the namespaced plugin action form to the actions
  section (`/api/v1/action/{plugin}/{action}`, discovery output, form
  endpoint), written as current state.
- **`docs/plugin_developer_guide.md`** — new "Exposing API actions" section:
  the `_logic_api()` opt-in inside `plugins/{plugin}/logic/`, namespacing,
  `requires_session`, optional `_logic_form()` companion.
- **`plugins/dns_filtering/docs/overview.md`** — document the Phase 2 action
  surface, the `sbr_hard_block` semantics (resolver ignores it; clients
  enforce), and the merged hard-block list.
