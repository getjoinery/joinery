# Plugin `ajax/` / `utils/` / `tests/` — collision vs. override

**Status:** Active — decision settled. The guardrail and the store/event endpoint
treatments shipped with
[`store_and_event_manager_plugin_extraction.md`](implemented/store_and_event_manager_plugin_extraction.md):
the sync-time collision validator runs in `PluginManager::sync()`, `checkout_ajax`'s
successor surface is store API actions, `session_search` was deleted, and the
Stripe/PayPal webhooks stay flat from `plugins/store/ajax/`.
**Remaining scope here:** the four plugins' page-JS migrations below.
**Touches:** `server_manager`, `dns_filtering`, `mailbox`, `bookings` and their JS
callers; `docs/routing.md`, `docs/plugin_developer_guide.md`.

## The decision

**Isolation is an addressing question; override is a resolution question — and the
platform already answers both correctly everywhere except the legacy flat
namespace.** No new namespaced `/ajax/` routing will be built. The cure is the
surface that already exists: plugin endpoints are API actions at
`/api/v1/action/{plugin}/{action}`. The legacy flat namespace has a sync-time
collision validator for the remainder of its life, and its page-JS endpoints
migrate to API actions.

## Why this is the right model (rationale)

The original framing was "one filename-matching rule is doing two jobs — override
and private endpoints — and can't serve both." True, but the platform had already
solved this split on its two modern surfaces, using two separate mechanisms:

- **Isolation by address.** When the owner's name is in the URL, collision is
  structurally impossible. Views do this (`/admin/joinery_ai/chat_send` resolves
  only inside `plugins/joinery_ai/views/`), and API actions do this
  (`api_resolve_logic_path()` in `api/apiv1.php` resolves `{plugin}/{action}`
  strictly inside `plugins/{plugin}/logic/` — no fallback, no shadowing).
- **Override by chain position.** Overriding a *core* resource is expressed by
  where an implementation sits in the resolution chain (theme → core), not by a
  plugin grabbing a global filename. Views resolve through the theme chain;
  single-segment core API actions do too (`getThemeFilePath(..., 'logic')`).
  Plugins cannot shadow core actions or each other.

The two mechanisms never conflict because they answer different questions: the
address decides *who owns the name*; the chain decides *whose implementation
serves it*. The flat `ajax/`/`utils/`/`tests/` resolution in `RouteHelper`
(first active plugin wins, then core fallback) is the one place that collapses
both questions into a filename match — and it is exactly the surface the API
forward rule already closed to new endpoints (`docs/api.md` § Authentication:
`/ajax/` is legacy, no new endpoints).

Two facts seal it:

- **The flat override "feature" has zero users.** Every flat plugin endpoint in
  the inventory below is a private endpoint; none intentionally shadows core or
  another plugin. Retiring the mechanism loses nothing.
- **The feared `chat_send` clash cannot happen on the mandated surface.** The
  planned chat plugin ([`chat_plugin.md`](chat_plugin.md)) must expose its
  endpoints as API actions, which land at `/api/v1/action/{chat_plugin}/chat_send`
  — fully isolated from `joinery_ai`, whose chat endpoints are namespaced under
  `views/admin/` today and have the same API-action future home.

## The shipped guardrail (context, not scope)

`PluginManager::sync()` (which also runs as `update_database`'s plugin step and on
plugin activation) validates the flat namespace: it collects every `ajax/`,
`utils/`, and `tests/` top-level basename across all **active** plugins plus the
corresponding core directory, and **fails the sync with a named-file error** when
two participants declare the same basename in the same directory type. Silent
shadowing is an explicit error. This permanently covers `utils/` and `tests/`,
which have no API-action analog and stay flat.

## What remains to build

### Migrate plugin page-JS `ajax/` endpoints to API actions

Each browser-facing endpoint becomes a logic action in
`plugins/{plugin}/logic/{action}_logic.php` with the `_logic_api()` opt-in, and
its JS callers switch to `POST /api/v1/action/{plugin}/{action}` with the
browser-session credential (session cookie + `X-Joinery-Csrf` header), per
`docs/api.md`. The old `/ajax/` file is deleted in the same change.

Migration surface (callers are `fetch('/ajax/...')` strings in each plugin's
`views/`):

| Plugin | Endpoints to migrate |
|---|---|
| server_manager | probe_api, job_status, discover_nodes, backup_actions, refresh_node_status, add_discovered_nodes |
| dns_filtering | scan_url, purge_querylog, block_rule_add, block_rule_delete, block_filter_set, test_domain |
| mailbox | mailbox_send, mailbox_thread, mailbox_mailboxes, mailbox_list, mailbox_action |
| bookings | booking_slots *(public page caller — set `requires_session` per its auth needs)* |

The migration also removes mailbox's defensive `mailbox_*` prefixing
pressure — under `/api/v1/action/mailbox/...` the plugin owns its names.

**Stays flat (validator-guarded):**

- `mailbox/ajax/inbound_email_webhook.php` — called by external providers,
  not page JS; webhooks live in the flat namespace alongside the store's
  Stripe/PayPal webhooks.
- `mailbox/utils/inbound_email_handler` and the 12 `tests/*_test` files —
  `utils/` and `tests/` have no API-action analog.

### Documentation

As each plugin migrates: `docs/plugin_developer_guide.md`'s endpoint guidance
points at API actions, noting the sync validator and the flat namespace's
remaining legitimate uses (webhooks, utils, tests).

## Sequencing

The per-plugin migrations land one plugin at a time in any order; each is
self-contained (logic file + JS callers + delete old file).

## Notes

- `joinery_ai`'s chat endpoints (`chat_send`, `chat_poll`, `chat_confirm`, etc.)
  are JSON endpoints under `views/admin/` — namespaced by address, so they are
  safe where they are and are **not** part of this migration. Their eventual
  home is `/api/v1/action/joinery_ai/...` under the standard
  migrate-when-touched rule.
- Core `/ajax/*` endpoints are out of scope; they are the base namespace and
  migrate opportunistically per the existing forward rule.
