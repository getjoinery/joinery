# Plugin `ajax/` / `utils/` / `tests/` — collision vs. override

**Status:** Active — **awaiting an architectural decision** (to be made soon, then
this spec gets a concrete design). Do not implement until the decision below is
settled.
**Touches:** `RouteHelper` route resolution, every plugin that ships an `ajax/`,
`utils/`, or `tests/` directory, the `joinery_ai` chat endpoints, and
`docs/routing.md`.

## The plain-language problem

Plugin **pages** can't step on each other, but plugin **AJAX endpoints** can. A
page lives at `/admin/joinery_ai/chat_send`, with the plugin's name in the URL, so
two plugins can both have a `chat_send` page and never conflict. An AJAX endpoint
lives at `/ajax/chat_send` — **no plugin name, a single shared namespace** — so if
two plugins each ship `ajax/chat_send.php`, one silently wins and the other is
unreachable. The same is true for `utils/` and `tests/`.

We haven't been bitten yet only because every plugin author has picked unique
filenames by hand. That's discipline, not a guarantee — and the first real clash
is already on the horizon (below).

## How it resolves today (the evidence)

Two different code paths in `RouteHelper`:

- **Views are namespaced.** For `/admin/joinery_ai/chat_send`, RouteHelper
  extracts the plugin name from the URL and resolves only within
  `plugins/joinery_ai/views/...` (`extractPluginNameFromPattern` →
  `getThemeFilePath(..., $plugin_name_for_view, ...)`). The plugin name is part of
  the address, so isolation is automatic.
- **`ajax/` / `utils/` / `tests/` are flat and first-match-wins**
  (`RouteHelper.php`, the `ajax`/`utils`/`tests` branch):

  ```php
  foreach ($activePlugins as $pluginName => $pluginHelper) {
      if ($pluginHelper->includeFile($route_type . '/' . $file . '.php')) {
          return true;   // first active plugin with this filename wins
      }
  }
  // then fall back to core <route_type>/<file>.php
  ```

  No plugin name in the URL, no namespace; the resolver walks all active plugins
  in registration order and the first match wins, silently, then falls back to the
  core file of the same name.

## The architectural question this spec exists to settle

The flat mechanism is doing **two jobs that pull in opposite directions**, and one
filename-matching rule can't serve both:

1. **Override** — a plugin *intentionally* replacing a core (or earlier-loading)
   endpoint by shipping a file of the same name. Here "same filename = take over"
   is the *feature*, mirroring theme/view overrides. Flat-and-first-match is
   exactly right for this.
2. **Private endpoints** — a plugin's *own* endpoints that should never be
   confusable with anyone else's. Here "same filename = collision" is a *bug*, and
   what you want is isolation. Flat-and-first-match is exactly wrong for this.

Filename match alone cannot tell these apart. So the decision is fundamentally:
**does a plugin endpoint default to isolation or to override — and how does an
author express the other intent?** Every option below is an answer to that one
question. This needs a firm decision before we add more endpoints to the flat
namespace.

## Why it's urgent now, not someday

- **Four plugins already use the flat namespace for private endpoints** —
  `server_manager` (6), `dns_filtering` (6), `inbound_email` (6 ajax + 1 util + 12
  tests), `bookings` (1). None are overrides; all are private endpoints living in
  a shared room.
- **`inbound_email` already prefixes defensively** (`mailbox_*`,
  `inbound_email_*`) — author-side evidence that the collision pressure is real and
  is currently being absorbed by manual naming. That manual prefixing is the
  band-aid a proper fix removes.
- **The first genuine clash is imminent.** The planned Discord-style chat plugin
  ([`chat_plugin.md`](chat_plugin.md)) will want generic endpoint names —
  `chat_send`, `chat_poll`, `chat_confirm` — that `joinery_ai` also uses. Today
  `joinery_ai`'s chat endpoints are safe *because they're in `views/admin/`*
  (namespaced). The moment either plugin puts a `chat_send` in `ajax/`, they
  collide. This is the concrete reason the [async-chat spec](joinery_ai_chat_async.md)
  deliberately keeps `chat_poll` in `views/admin/` for now.

## Current inventory (flat-namespace participants)

No collisions exist **today** (every basename is unique across active plugins +
core), but the surface is already broad:

| Dir | Plugin | Endpoints |
|---|---|---|
| `ajax/` | server_manager | probe_api, job_status, discover_nodes, backup_actions, refresh_node_status, add_discovered_nodes |
| `ajax/` | dns_filtering | scan_url, purge_querylog, block_rule_add, block_rule_delete, block_filter_set, test_domain |
| `ajax/` | inbound_email | mailbox_send, mailbox_thread, mailbox_mailboxes, mailbox_list, mailbox_action, inbound_email_webhook |
| `ajax/` | bookings | booking_slots |
| `utils/` | inbound_email | inbound_email_handler |
| `tests/` | inbound_email | 12 `*_test` files |
| (views/admin, **not** ajax) | joinery_ai | chat_send, chat_confirm, chat_set_capabilities, run *(JSON)* — namespaced today, hence safe |

## The options to decide between

### Option A — Isolation by default; explicit override opt-in *(recommended lean)*

Resolve plugin `ajax/`/`utils/`/`tests/` **by plugin name, like views** — e.g.
`/ajax/{plugin}/{file}` → `plugins/{plugin}/ajax/{file}.php`. Cross-plugin
collision becomes structurally impossible. A plugin that *wants* to override a
core endpoint declares it explicitly (e.g. an `ajax_overrides` list in
`plugin.json`), so an override is a deliberate, visible act instead of a filename
coincidence.

- **Pro:** fixes the cause at the routing layer; makes the platform self-consistent
  (ajax behaves like views); kills manual prefixing; override stays possible but
  becomes intentional.
- **Con:** URL change for **every** existing plugin ajax/utils/tests endpoint and
  all their JS callers — a real migration (inbound_email is the biggest surface).
  Core `/ajax/*` endpoints stay flat (they have no plugin namespace).

### Option B — Keep flat; add loud collision detection *(possible interim guardrail)*

Leave resolution flat, but make plugin sync / `update_database` (or activation)
**refuse or hard-warn** when two active plugins — or a plugin and core — declare
the same `ajax`/`utils`/`tests` basename. Silent shadowing becomes a surfaced
error.

- **Pro:** cheap (a validation pass); no URL changes, no migration; preserves the
  override feature; can ship **first**, independent of the bigger decision, to stop
  silent collisions while A is being weighed.
- **Con:** doesn't deliver true isolation — endpoints still share one namespace and
  authors still hand-pick global names; two plugins that both legitimately want
  `chat_send` are forced to rename one. It surfaces the problem rather than
  removing it.

### Relationship between them

B is not a substitute for A — it's a guardrail. A reasonable path is **B now
(safety), A later (cure)**, but that's part of the decision, not a foregone
conclusion. B counts as surfacing-not-papering (it makes the collision an explicit
error), so it isn't the kind of band-aid that hides an edge case.

## What the decision unblocks

Once decided, this spec gets a concrete design + migration plan covering:

- The `RouteHelper` resolution change (A) or the sync-time validator (B).
- Migration of the four existing plugins' endpoints and **every JS caller**
  (`fetch('/ajax/...')` strings live in those plugins' `views/`), plus any
  `plugin.json`/menu references.
- Whether `joinery_ai`'s chat endpoints (and the new `chat_poll`) move into a
  now-safe namespaced `ajax/`, or stay in `views/admin/`. **Until the decision,
  they stay in `views/admin/`** — that is the currently-safe location, per the
  async-chat spec.
- `docs/routing.md` updated to document the chosen model as the end state.

## Out of scope

- The async-chat work itself ([`joinery_ai_chat_async.md`](joinery_ai_chat_async.md))
  — it intentionally avoids this namespace and is not blocked by this decision.
- Core `/ajax/*` endpoints' own naming (they are the base namespace either way).
