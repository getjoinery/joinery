# AJAX Estate Migration — drain `/ajax/` to `/api/v1` actions

## Status: implemented (2026-07-11)

One acceptance item remains open by design: the core `ajax/` directory still
holds `entity_photos_ajax.php`, which migrates with
[`file_blob_layer.md`](file_blob_layer.md) — remove the directory there.
`vs.php` was deleted on code evidence + 7 quiet days of dev logs (production
log check was unavailable).

This is the executor package for every legacy `/ajax/` endpoint **not** already
claimed by another spec. It executes the remaining scope of
[`plugin_ajax_namespace_collision.md`](plugin_ajax_namespace_collision.md) (the
four plugins' page-JS migrations — the decision and rationale live there) and
covers the entire core `/ajax/` misc estate. When this spec completes, both
specs move to `specs/implemented/`.

**Not in scope — claimed elsewhere:**

- `ajax/checkout_ajax.php`, `ajax/cookie_consent.php` — being migrated right
  now under [`anonymous_browser_credential.md`](anonymous_browser_credential.md)
  (already deleted on this branch).
- `ajax/entity_photos_ajax.php` — the multipart upload endpoint.
  [`file_blob_layer.md`](file_blob_layer.md) already rewrites it onto
  `File::createFromUpload()` (its :146-149); migrating it now would mean doing
  it twice, and the JSON action surface doesn't carry multipart anyway. It
  moves with the blob-layer work.
- **Webhooks stay flat by design** (`plugins/store/ajax/` Stripe/PayPal,
  `plugins/mailbox/ajax/inbound_email_webhook.php`) — external callers, not
  page JS. The flat namespace, its `serve.php` route (`'/ajax/*'`, serve.php:139),
  and the StaticPageCache bypass prefix (StaticPageCache.php:391) all remain
  for them. So do plugin `utils/` and `tests/`, which have no API analog.

**Relationship to [`logic_api_descriptor_migration.md`](logic_api_descriptor_migration.md):**
every logic file this spec creates or touches carries a `_logic_descriptor()`
(never a new `_logic_api()`), including authoring descriptors for the three
existing calendar actions that today have only `_logic_api()`. This spec
shrinks that estate's tail; it never grows it.

**Platform dependency (already on this branch):** the `session_write`
descriptor flag and `SessionControl::reopen()` from
`anonymous_browser_credential.md` phase 1 (`executeAction()`,
ApiLogicEndpoint.php:225-228). The notification mark actions use it. Nothing
here needs the anonymous credential itself — every migrated caller is either
logged-in or sessionless.

## Migration recipe (applies to every endpoint below)

1. Logic file with `_logic_descriptor()` — honest `input` schema
   (optional-when-unsure, per the descriptor-migration spec's rule), `mutates`,
   and an `auth` block that **preserves the endpoint's current permission floor
   exactly** (floors listed per endpoint below; vocabulary:
   `min_user_permission`, `requires_browser_session`, `session_write`,
   `requires_session => false` for public — ApiAuth.php:302-345).
2. Callers switch to `POST /api/v1/action/{name}` (core) or
   `/api/v1/action/{plugin}/{name}`. **Actions are POST-only**
   (ApiLogicEndpoint.php:95) — every GET-style feed becomes a JSON POST body.
   Browser callers send `X-Joinery-Csrf` from the `joinery-api-csrf` meta tag
   (docs/api.md § Authentication); sessionless actions need no header.
3. Callers parse the response envelope (docs/api.md § Response envelope):
   payload is under `data`, errors carry `error_type`/`message`. Preserve each
   endpoint's current payload shape *inside* `data` so response-handling
   changes stay mechanical.
4. Delete the `/ajax/` file in the same change.
5. `php -l` + `validate_php_file.php` on every touched PHP file. **Never run
   the validator on run-on-include scripts** (it executes the target).
6. **Core-chrome callers get a live browser check** on dev after migrating —
   `PublicPageBase`, `FormWriterV2Base`, and `data/reactions_class.php` emit
   JS on every page, so a mistake there breaks the whole site, not one page.

## Phase 1 — Shared JS surfaces (platform plumbing)

Four pieces of core chrome emit `/ajax/` fetches on behalf of many consumers.
Each gains API-action support once, landed together with its first migrated
consumer.

### 1a. FormWriter remote validation (`assets/js/joinery-validate.js`)

The `remote` validator (joinery-validate.js:673-739) GETs a URL and expects
literal text `'true'`/`'false'`. Teach it an API-action mode: when the
configured URL starts with `/api/v1/`, POST JSON (`{field_name: value}` plus
any extra data), send `X-Joinery-Csrf` from the meta tag, and read
`data.valid` (bool) from the envelope. Non-API URLs keep the legacy text
contract (nothing else uses it after this spec, but the validator stays
general). Both FormWriter rule shapes route through this: the
`'remote' => ['url' => ...]` rule (adm/admin_settings.php:482,705,866) and the
`'custom' => ['rule' => ..., 'url' => ...]` shape (adm/admin_user_add.php:60-71).
Contract for validation actions: return `LogicResult` success with
`['valid' => bool]`.

### 1b. FormWriter select autocomplete (`FormWriterV2HTML5::buildAjaxSelectScript`, FormWriterV2HTML5.php:169-170)

The generated debounced-search JS (fetch at FormWriterV2Base.php:3132) GETs
`?q=...` and expects a bare `[{id, text}]` array. When the endpoint is an
`/api/v1/` action: POST JSON `{q, ...}` with the CSRF header and read
`data.items` (`[{id, text}]`). The `ajaxendpoint` option keeps its name and
now carries the action URL; query-string suffixes callers append today (e.g.
`?includenone=1`, plugins/store/admin/admin_coupon_code_edit.php:153) become
keys in the POST body — the generated JS parses any query string off the
configured endpoint into body fields.

`FormWriterV2JSON` serializes `ajaxendpoint` as `search_endpoint` for native
renderers (FormWriterV2JSON.php:176-178) — it passes the new action URL
through unchanged. No native member-app surface renders a search field today;
the native FormRenderer gains envelope/POST handling when one first does (note
it in docs/mobile_apps.md only if a doc passage claims otherwise).

### 1c. FormWriter image selector (`FormWriterV2Base::imageselector`)

The modal picker (default endpoint `'/ajax/image_list_ajax'`,
FormWriterV2Base.php:1237; fetch at :1684) switches its default to
`/api/v1/action/image_list`, POSTs JSON `{q, offset, limit}` with the CSRF
header, and reads `data.{images, total, hasMore}`. No caller overrides
`ajax_endpoint` today (verify with grep before deleting).

### 1d. `calendar_grid` component feed (views/components/calendar_grid.php)

The `feed_url` paging fetch switches to POST JSON `{start, end}`, sending the
CSRF header when the meta tag is present (its two consumers are logged-in
pages). Response items come from `data.items`. Consumers pass action URLs:
`views/profile/calendar.php:109` → `/api/v1/action/calendar_feed`;
`plugins/bookings/views/profile/availability.php:125` →
`/api/v1/action/availability_preview`.

## Phase 2 — Core misc endpoints

### Already have API-action twins — switch callers, delete file

The three calendar endpoints were re-implemented as actions for the native
apps; the web pages never switched.

| ajax file | existing action | caller changes |
|---|---|---|
| `calendar_feed.php` | `calendar_feed` (logic/calendar_feed_logic.php) | via 1d |
| `calendar_entry_quick_save.php` (save branch) | `calendar_entry_save` | views/profile/calendar.php:349 (popover FormWriter action), :543, :562 — field names map: `entry_date→date`, `entry_title→title`, `entry_all_day→all_day`, `entry_blocks→blocks`, `entry_start/entry_end→start_time/end_time`, `entry_id→entry_id` |
| `calendar_entry_quick_save.php` (delete branch) | `calendar_entry_delete` | same popover JS |

All three actions carry only `_logic_api()` today — author descriptors while
here. Reconcile behavior before switching: the action's save path is richer
(recurrence, scope) but must accept the popover's minimal payload identically
to the quick-save file (verify all-day conversion and the `blocks` default
`true` match calendar_entry_quick_save.php's semantics).

### New core actions (one logic file each, `logic/{name}_logic.php`)

| ajax file | new action(s) | auth floor (preserve!) | callers to update | notes |
|---|---|---|---|---|
| `availability_preview.php` | `availability_preview` | logged-in (any) | plugins/bookings/views/profile/availability.php:125 (via 1d) | Owner-only feed: open availability + commitments. Read-only. Body is a lift of the ajax file (CalendarItemSourceRegistry + SlotGenerator). |
| `notifications_ajax.php` | `notification_mark_read`, `notification_mark_all_read`, `notification_unread_count` | logged-in (any) | views/notifications.php:160,182 | Mark actions: `mutates`, `session_write => true` — they invalidate `$_SESSION['notification_unread_count']` and that write must persist past the API's early lock release. `notification_unread_count` becomes purely read-only: **drop** its `$_SESSION` cache refresh (the mark actions' invalidation already forces recompute on next page load). Ownership check (`ntf_usr_user_id`) preserved. |
| `reaction_ajax.php` | `reaction_toggle` (mutates), `reaction_status`, `reaction_count` | logged-in (any) | data/reactions_class.php:217 (emitted button JS — core chrome, browser-check) | Keep the entity_type/reaction_type regex validation in the descriptors' input schemas or logic. Payloads: toggle `{action, count}`, status `{reacted, count}`, count `{count}`. |
| `theme_switch_ajax.php` | `theme_switch` | `min_user_permission => 10` | includes/PublicPageBase.php:782 (preview bar — core chrome, browser-check); tests/integration/routing_test.php:644 fixture | `mutates`. Theme-name regex + `ThemeHelper::themeExists()` checks preserved; body wraps `ThemeManager::activate()`. |
| `user_search_ajax.php` | `user_search` | `min_user_permission => 5` | via 1b: plugins/store/admin/admin_order_edit.php:48, admin_order_item_edit.php:141, admin_coupon_code_edit.php:153 (`includenone`), utils/forms_example_html5v2.php:303 | Read-only. Returns `{items: [{id, text}]}` (text = display name + email; `includenone` prepends the `{id: 0, text: 'None'}` row). Rewrite cleanly — the ajax file has dead code (`$returnlist`/undefined `$i`, user_search_ajax.php:69-74). Preserve observable search behavior (email vs name vs numeric-id branches). |
| `image_list_ajax.php` | `image_list` | `min_user_permission => 5` | via 1c | Read-only. `{images, total, hasMore}` shape preserved inside `data`. |
| `check_provisioning.php` | `plugin_provisioning_check` | `min_user_permission => 5` | adm/admin_plugins.php:442 | Read-only wrapper over `PluginProvisioning::runChecks()`; payload `{plugins: [...]}`. |
| `email_check_ajax.php` | `email_available` | `min_user_permission => 5` — **security fix: today this is completely unauthenticated** (an account-enumeration oracle; email_check_ajax.php has no session check). Its only live caller is the admin user-add form, so the floor is 5. | adm/admin_user_add.php:60-71 (via 1a) | Input: `email`. Returns `{valid: bool}` (true = available) for the 1a contract. The multi-field-name sniffing (`lbx_reg_usr_email`, `lbx_email`) has no live caller — drop it. |
| `validate_file_ajax.php` | `validate_server_file` | `min_user_permission => 5` (matches the current check, validate_file_ajax.php:8) | adm/admin_settings.php:482,705,866 (via 1a) | Input: `field` (`apache_error_log` \| `preview_image` \| `logo_link`) + `value`. Returns `{valid: bool}`. Preserve the logo_link leading-`/` + `PathHelper::getRootDir()` mapping and empty-is-valid. This is an admin-only file-existence oracle by design — keep the field whitelist strict, never accept a bare arbitrary path key. |

### iframe HTML previews → admin pages, not actions

Three endpoints are **document loads** (iframe `src` / preview links), not JS
fetches — JSON actions are the wrong surface. Each becomes a plain `adm/` page
(auto-routed at `/admin/{name}`, no serve.php entry needed) that does its
permission check and echoes the same raw HTML with no admin chrome:

| ajax file | new page | permission | callers to update |
|---|---|---|---|
| `email_preview_ajax.php` | `adm/admin_email_preview.php` | `check_permission(5)` | adm/admin_email.php:39,148; adm/admin_emails_send.php:172 |
| `email_template_preview_ajax.php` | `adm/admin_email_template_preview.php` | `check_permission(10)` | adm/admin_email_template.php:46 |
| `debug_email_log_preview_ajax.php` | `adm/admin_debug_email_log_preview.php` | `check_permission(8)` | adm/admin_debug_email_log.php:27 |

Bodies move verbatim (they are thin: load record, echo body / render
`EmailMessage::fromTemplate`). Keep the query-param names so only the URL path
changes in callers.

## Phase 3 — Plugin batches (executes plugin_ajax_namespace_collision.md's remaining scope)

Per that spec's decided model; any order, one plugin per change-set. All new
logic files use `_logic_descriptor()` (superseding that spec's `_logic_api()`
wording, per the forward rule).

- **mailbox** (5: `mailbox_send`, `mailbox_thread`, `mailbox_mailboxes`,
  `mailbox_list`, `mailbox_action`) — logic is inline in the ajax files;
  extract to `plugins/mailbox/logic/`. Under the plugin namespace the
  defensive prefix goes: actions become `mailbox/send`, `mailbox/thread`,
  `mailbox/mailboxes`, `mailbox/list`, `mailbox/action`. All five callers live
  in one place (`mailbox_reader_mount.php`) and an existing test covers the
  reader — it must pass after the switch.
- **dns_filtering** (6: `scan_url`, `purge_querylog`, `block_rule_add`,
  `block_rule_delete`, `block_filter_set`, `test_domain`) — already thin
  wrappers over existing `_logic` functions: add descriptors to those logic
  files, point the page JS at `/api/v1/action/dns_filtering/{name}`, delete
  the wrappers. Verify wrapper-vs-inline per file before assuming.
- **server_manager** (6: `probe_api`, `job_status`, `discover_nodes`,
  `backup_actions`, `refresh_node_status`, `add_discovered_nodes`) — inline
  logic; extract to `plugins/server_manager/logic/`. Callers are
  `fetch('/ajax/...')` strings in the plugin's views/admin pages.
- **bookings** (1: `booking_slots`) — public read-only slots feed
  (plugins/bookings/ajax/booking_slots.php). Becomes
  `bookings/booking_slots` with `requires_session => false` (sessionless
  pre-auth dispatch — same category as `register`): the callers are public,
  possibly cached, pages (views/book.php:57, views/booking_manage.php:57) and
  the endpoint already computes at `busy` visibility with no session
  dependence. Its picker JS switches to POST JSON `{slug, start, end}`, no
  CSRF header needed. Payload `{slots: [...]}` preserved. Keep the
  `bookings_active` setting check and slug/active-type fail-soft behavior.

Preserve each endpoint's current auth check as its descriptor floor — read
every file before writing its descriptor; the table in
plugin_ajax_namespace_collision.md is the endpoint list, not an auth source.

## Phase 4 — Estate retirement

- **`ajax/vs.php`** (visitor beacon): no code anywhere emits a call to it.
  Verify against live traffic before deleting — grep recent access logs on dev
  **and** the production nodes (Server Manager / node exec) for `POST /ajax/vs`
  hits; if quiet, delete the file. Do not migrate it.
- **Dead theme references:** `theme/linka-reference-html5/views/contact.php:89`
  and `post.php:167` post forms to `/ajax/contact` and `/ajax/comment`, which
  do not exist (broken today). Remove or rewire those reference-theme forms —
  executor judgment, low stakes.
- **Core `ajax/` directory:** once phases 2 + the in-flight anonymous-credential
  deletions land, the core directory is empty — remove it. The `'/ajax/*'`
  route and cache-bypass prefix stay (plugin webhooks).
- **routing_test fixture:** tests/integration/routing_test.php:644 uses
  `/ajax/theme_switch_ajax` as its "existing AJAX endpoint" — swap to a
  surviving flat endpoint (a webhook path) or drop the case.

## Tests

House style (`tests/lib/harness.php`, `@joinery-test` header):

- `tests/functional/api/ajax_migration_actions_test.php` — per new core
  action: envelope shape, auth floor enforced (401 with no credential, 403
  below floor), and payload spot-checks. Pin the security fix explicitly:
  unauthenticated `email_available` → 401 (regression against the old open
  oracle). Pin `notification_mark_read`'s session invalidation surviving to a
  second request (the `session_write` contract), and that
  `notification_unread_count` works with a plain read-only descriptor.
- `bookings/booking_slots` sessionless: POST with no credential returns slots
  for an active type; inactive/unknown slug fail-soft shape preserved.
- Existing mailbox reader test passes after the mailbox switch; run the
  relevant `tests/functional/api/` suites after each batch.
- `php tests/run.php db` green at the end of each phase.

**Browser verification checklist (dev, after the relevant change):**
notifications page mark-read/mark-all; a reaction button toggle on a post;
theme preview bar switch (superadmin); admin_settings logo-link validation
message; user autocomplete on a store admin order edit; image selector modal;
calendar month paging + quick-save popover (create, edit, delete); bookings
availability editor preview; public booking page slot picker; admin Plugins
provisioning panel; all three email preview iframes.

## Docs (current-state voice, on ship)

- docs/formwriter.md — remote validation, autocomplete, and image selector
  now speak the API-action contract (`data.valid` / `data.items` /
  `data.images`).
- docs/api.md — nothing structural; add the new core actions if it carries an
  action inventory.
- docs/plugin_developer_guide.md — per plugin_ajax_namespace_collision.md's
  Documentation section: endpoint guidance points at API actions; flat
  namespace's remaining legitimate uses are webhooks, `utils/`, `tests/`.
- docs/social_features.md (reactions), calendar/bookings docs — update any
  `/ajax/` endpoint mentions.
- Sweep: `grep -rn "/ajax/" docs/ plugins/*/docs/` must return only webhook
  references when done.

## Acceptance

1. Core `ajax/` directory is gone; `plugins/*/ajax/` contains only the three
   webhooks.
2. `grep -rn "'/ajax/\|\"/ajax/" adm/ views/ includes/ data/ theme/ plugins/
   assets/ utils/` returns only webhook references and the serve.php route
   comment.
3. Every logic file created or touched here defines `_logic_descriptor()`;
   `php -l` + `validate_php_file.php` clean on all touched files.
4. `GET /api/v1/actions` lists every new action with a non-null input schema.
5. Test suite green (`php tests/run.php db`); browser checklist done.
6. This spec and `plugin_ajax_namespace_collision.md` move to
   `specs/implemented/`.

## Executor cautions

- **No git commits** without explicit user direction; no Apache config edits.
- Blast radius: PublicPageBase, FormWriterV2Base, joinery-validate.js, and
  reactions_class run on every page — browser-check immediately after each of
  those lands, and land them in small, separately verifiable steps.
- The permission floors above are the current observed behavior — if a floor
  looks wrong while implementing (other than the `email_available` fix, which
  is deliberate), flag it rather than silently changing it.
- Dev's `/assets/*` are Cloudflare-cached ~12h — verify edited JS at origin
  with a `?cb=` query when browser-checking.
