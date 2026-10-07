# Admin view and change levels

**Status:** Draft, 2026-10-07. Research done (sections 2–3), no code. Open questions Q1–Q4 need the owner before WP2.

## 1. What this does

Today an admin page has one permission level. Whoever may open the page may
also press every button on it. This spec gives an admin section two levels:
who may **view** it, and who may **change** anything in it. The first user is
Server Manager, viewable at permission 5 and changeable at 10. That lets an
operator (or Claude's own account) watch the fleet without the power to alter
it.

The design goal is one declaration per section, enforced by the platform. A
page does not carry its own copy of the rule, and a new page is covered
without remembering to be.

## 2. What the platform does today

Research 2026-10-07; file references are to the tree at that date.

**Routing.** Admin pages are reached three ways, and only one passes a
platform permission check:

- `/admin/*` (serve.php:174) has `min_permission => 5`, enforced in
  `RouteHelper::matchRoute()`. It covers core `adm/*.php` and the
  `/admin/{plugin}/*` pages (server_manager, joinery_ai). Those pages reach
  their view file only after `/admin/*` matched and found no core file
  (RouteHelper.php step 6), so the router learns the owning plugin after the
  check.
- `/plugins/{plugin}/admin/*` (serve.php:243) is a closure with no
  permission check. It serves about 87 pages in store, mailbox,
  event_manager, bookings, items, bug_reports and persona_browser. Each page
  checks for itself.
- Plugin `dynamic` routes are matched before core routes, so
  `/admin/server_manager/publish` and `/publish_theme` skip the `/admin/*`
  check. `publish_theme` is public on purpose: other sites' marketplaces fetch
  it without a session.

**Levels.** The UI offers 0, 5 (Assistant), 8 (Admin) and 10 (Master Admin)
(adm/admin_users_edit.php:70). The docs describe 5/7/9/10. Core pages gate at
5 (35 pages), 7 (2), 8 (41), 9 (10) and 10 (41). A view/change split already
exists informally: 38 page or logic files test the permission inline, mostly
"5 to see, 8 to delete". Server Manager is 10 throughout.

**Two sources of truth already disagree.** A menu entry's permission
(plugin.json `adminMenu`, core `admin_menus.json`, editable by admins at
`/admin/admin_admin_menu_edit`) and the page's own check differ in 33 places.
The menu decides only what the sidebar shows. A section declaration must not
become a third source.

**The "a GET never changes anything" rule is not true.**

- About 80 core admin pages and most plugin logic files hand
  `array_merge($_GET, $_POST)` or `$_REQUEST` to their handlers without
  checking for a POST. `?action=delete` on the page's own URL works as a GET.
- Some of these have visible GET links: admin_static_cache, admin_plugins,
  admin_themes, admin_emails_send, admin_email `?send_test=1`.
- `SystemBase::assert_not_get_mutation()` (SystemBase.php:2884) only logs; its
  throw is commented out. It never sees raw SQL, `updateColumns()`,
  `Setting::put`, email, `exec` or file writes.
- **This is a security problem in its own right (B1).** The session cookie is
  `SameSite=Lax`, which a browser still sends on a top-level GET navigation
  from another site. A link or redirect anywhere on the web can therefore
  perform any GET-reachable admin action as a signed-in admin.

**POSTs that only read.**

- `/api/v1` actions accept POST only (ApiLogicEndpoint.php:134), reads
  included. Each descriptor has a `mutates` flag that nothing reads, and that
  is wrong for several actions.
- Only 18 of 284 descriptors declare `min_user_permission`; the rest check
  inside their logic.
- Pages: the six analytics date filters, admin_question "Test", static cache
  diagnose, scheduled-task dry run, backup target tests.
- Server Manager's node "look" actions (check_status, site_log, file_head,
  page_probe, disk_usage and more) are POSTs that create a job record but
  change nothing on the node.

**Changes made without a POST form.**

- Server Manager's backup and transfer buttons are `type="button"` and call
  `/api/v1` from JavaScript (all at permission 10 today).
- Plugin and theme admin build their forms in JavaScript.
- Confirm modals submit forms with `.submit()`.

So "switch off every button inside a POST form" misses these. The server must
refuse; the page can only make it clear.

**Nearest existing piece.** `GuardedPdo::$read_only` (GuardedPdo.php:41)
refuses every INSERT/UPDATE/DELETE at the database layer, from models and raw
SQL alike, unless the write is `SystemBase::server_initiated_write()`.
PageProbe uses it for throwaway viewers. It does not stop email, `exec`, file
deletes or outbound calls.

## 3. What this means for the design

Refusing POSTs at the router (the first idea) is not enough on its own:

- GET actions bypass it (B1).
- Read-only POSTs and API reads would be wrongly refused.
- Plugin pages under `/plugins/{p}/admin/` are not on the path it watches.

Disabling buttons in POST forms misses the JavaScript ones. Each piece below
closes one of those gaps, and the design needs all of them.

## 4. Design

### 4.1 One declaration per section, which also drives the menu

A plugin declares its admin section once in plugin.json:

```json
"adminAccess": { "view": 5, "change": 10 }
```

- It covers every page of the plugin under `/admin/{plugin}/` and
  `/plugins/{plugin}/admin/`.
- The plugin's `adminMenu` permission is taken from `view` and no longer
  written separately, so the menu cannot disagree with the section.
- A section with no declaration behaves exactly as today.
- Core `adm/` sections can adopt it later (Q3); this spec does not
  reclassify core pages.

### 4.2 The router knows the section before any page code runs

- One check in `RouteHelper::processRoutes()`, after the navigation gates and
  ahead of custom, dynamic and fallback routes, maps the path to its section.
  It handles both URL families and any dynamic route inside the namespace.
- Below `view`: the login redirect or 401, as `check_permission` does today.
- At or above `view` but below `change`, the request runs as a **viewer
  request**.
- A route can opt out explicitly (`publish_theme`, which is public; the
  joinery_ai chat endpoints, which are also reached from `/profile/`). The
  opt-out is written on the route, never inferred.

### 4.3 A viewer request cannot change anything

- **A POST to a page is refused** before the page runs. The page reloads with
  "Nothing was changed: this needs permission N", or a JSON 403 when the
  request asked for JSON.
- **The database is read-only for the whole request** (`GuardedPdo::$read_only`).
  This backs up the POST refusal and catches any GET that still writes. Bookkeeping
  the server does on its own, inside `server_initiated_write()`, still works,
  such as folding a finished job's result.
- Side effects outside the database (email, `exec`, file deletes, outbound
  calls) are not caught by either layer. They are covered by WP1, which makes
  every one of them POST-only, and by the POST refusal.

### 4.4 API actions say whether they change anything

- An action's descriptor already carries `min_user_permission`.
- An action that only reads is callable at the section's `view` level. One
  that changes something needs `change`.
- The `mutates` flag is made accurate and enforced: a `mutates: false` action
  runs read-only (GuardedPdo) for every caller, so a read cannot quietly
  write.
- Actions reached from a section's pages are listed in the section
  declaration test (WP5), so a new action that forgets its level fails the
  test.

### 4.5 The page shows view-only, centrally

- `AdminPage::admin_header()` reads the router's viewer flag. It prints one
  "View only" line beside the flash messages.
- The three places that render change controls render them disabled with a
  "needs permission N" tooltip:
  - `PublicPageBase::action_button()`
  - `renderActionEntry()` (altlinks POST entries)
  - FormWriter's POST form output
- JavaScript-driven buttons get a shared `data-change` attribute that the
  header's script disables. An API refusal (403) shows its message through the
  existing `smApiPost` / `joineryApi` error path.

## 5. Work packages

**WP1: make "a GET never changes anything" true (B1).** This is a prerequisite
for any view-only role and a security fix by itself.

- Handlers act only on POST.
- GET links become POST buttons (CLAUDE.md rule 7).
- `assert_not_get_mutation()` throws instead of logging, once the log is clean.
- Order:
  1. Shared pieces first: `DnsPublishBox::handle` acts on GET input and is
     used by Server Manager, mailbox setup and `/setup`.
  2. Server Manager: the publish page's delete is a GET (`?delete=N`).
  3. Core `adm/`, then each plugin.
- Scope is Q2.

**WP2: section declaration and router check (4.1, 4.2).** Parse
`adminAccess`, derive the menu from it, add the processRoutes check covering
both URL families and in-namespace dynamic routes, and add the route opt-out.

**WP3: viewer requests (4.3).** Refuse POSTs, make the database read-only for
the request, and return a JSON-aware refusal.

**WP4: API levels (4.4).** Make `mutates` accurate for the actions admin pages
call, enforce it read-only, and set read actions to the section's view level.
Settle B3 first.

**WP5: page display (4.5),** plus a test per declared section:

- every page is reachable as a viewer and renders the view-only line;
- every POST from a viewer is refused;
- every API action the section's pages call declares its level.

**WP6: Server Manager adopts it.**

- Declare `{"view": 5, "change": 10}` and drop the per-page
  `check_permission(10)`s, or make them read the section's levels.
- Set its read API actions to 5: `job_status`, `refresh_node_status`,
  `probe_api`, `moved_site_check`, and `backup_actions` for `refresh_list`
  and `list_status`.
- Leave every change at 10.
- Q1 decides the node "look" actions.

## 6. Open questions

- **Q1. Node "look" actions for viewers.** May a viewer run the read-only
  diagnostics on a node: check status, site log, log table tail, unit journal,
  file head, page probe, disk usage, schema probe?
  - Each creates a job record and asks the agent, but changes nothing on the
    node, and the owner has said agent output is fine at permission 5.
  - Allowing it means these actions are declared as reads (their job record
    is server bookkeeping).
  - Refusing it means a viewer sees only results someone else ran.
- **Q2. Scope of WP1.** Make GETs safe across the whole platform now (about
  80 core pages and most plugins), or do the shared pieces and Server Manager
  first and the rest as a following pass? B1 is live on every admin page
  until the whole pass is done.
- **Q3. Core sections.** Core `adm/` pages carry the informal "5 view, 8
  change" split inline. Declare core sections too (it needs a way to group
  `adm/` pages, which have no section today), or leave core as it is?
- **Q4. Levels 7 and 9.** The UI cannot assign them, yet 12 core pages gate
  on them. Fold them into 8 and 10?

## 7. Bugs found by the research

Each is tracked in memory `project_todo_admin_access_research_bugs`. They need
fixing whatever happens to this spec.

| ID | Bug | Where | Checked |
|---|---|---|---|
| B1 | Admin actions run on a GET, so another site can trigger them (SameSite=Lax sends the cookie on top-level navigation) | ~80 core pages, most plugin logic; DnsPublishBox::handle | pattern confirmed (admin_page, node_detail:83) |
| B2 | Server Manager publish page deletes a release archive and row on a GET | plugins/server_manager/includes/publish_upgrade.php:268, :1584 | reported |
| B3 | API action floor uses the stored `usr_permission`, not the session's (login-as, IP-change zeroing) | ApiLogicEndpoint.php:193, ApiAuth.php:426 vs :299 | reported, unverified |
| B4 | Undelete on a message calls `soft_delete()` | adm/admin_message.php:32-34 | confirmed |
| B5 | Root-request panel polls `/api/v1/root_request_status` (missing `/action/`): ~2,500 400s, panels stay "Queued" | includes/AdminPage.php:131 | confirmed |
| B6 | Opening the IMAP delete page deletes the feed when nothing depends on it | plugins/mailbox/logic/admin_mailbox_imap_delete_logic.php:47 | confirmed |
| B7 | Order delete treats any input as confirmation; a GET with `confirm=1` permanently deletes an order | plugins/store/logic/admin_order_delete_logic.php:22 | reported |
| B8 | Product permanent delete has no permission-10 check in the handler | plugins/store/logic/admin_product_logic.php | reported |
| B9 | Opening "Edit Question" saves it | adm/logic/admin_question_edit_logic.php:54 | reported |
| B10 | `admin_mailbox_message` shows any unsealed message by id at permission 5 with no mailbox grant check | plugins/mailbox | reported |
| B11 | Store payment settings print part of the PayPal secret and Stripe endpoint secret | plugins/store admin_settings_payments:321, :458 | reported |
| B12 | File upload widget posts to `/admin/admin_file_upload_process` without a CSRF header, at permission 5 | FormWriterV2Base.php:4927 | reported |
| B13 | Store order page and joinery_ai sweep write on page view without `server_initiated_write` | store admin_order_logic:25, :71; ChatAsync::sweepMessage | reported |
| B14 | User permanent-delete confirmation unreachable; admin_softdelete_logic never deletes; admin_page form nested in `#layoutForm`; items admin links to missing `/admin/admin_item*` | various | reported |
| B15 | Menu permission and page check disagree in 33 places | admin_menus.json, plugin.json vs pages | confirmed by count |
