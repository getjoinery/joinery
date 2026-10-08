# Declared permissions

**Status:** Draft. Research 2026-10-07 and 2026-10-08. Design reworked
2026-10-08; no code. Q1, Q2 and Q4 are open.

Running each model's own rules on every save is a separate spec,
`model_rules_on_every_save`, because it carries its own breakage and testing.

## 1. What this does

Today each page decides for itself who may use it and who may press its
buttons. A page that forgets is open, and the menu, the page and the model each
hold their own copy of the rule.

This spec moves every permission rule into a declaration that the platform
enforces. There are three questions and three places:

| Question | Declared in | Enforced by |
|---|---|---|
| Who may open this page? | The section manifest: plugin.json, or admin_menus.json for core | The router, before any page code runs |
| Who may change or delete this kind of data? | The model | `save()`, `soft_delete()`, `undelete()`, `permanent_delete()`, `updateColumns()` |
| Who may run this action? | The API action descriptor | The API endpoint |

A page carries no permission rule of its own. The buttons it shows ask the
same declarations, so what the page offers cannot drift from what the server
allows.

The first user is Server Manager, viewable at permission 5 while every change
still needs 10. That lets an operator, or Claude's own account, watch the fleet
without the power to change it. View-only comes from the model and action
levels. There is no separate "viewer mode".

## 2. What the platform does today

File references are to the tree on the research date.

### 2.1 Pages

Admin pages are reached three ways, and only one passes a platform permission
check:

- `/admin/*` (serve.php:174) has `min_permission => 5`, enforced in
  `RouteHelper::matchRoute()`.
  - It covers core `adm/*.php` and the `/admin/{plugin}/*` pages
    (server_manager, joinery_ai).
  - Those plugin pages reach their view file only after `/admin/*` matched and
    found no core file (RouteHelper.php step 6), so the router learns the
    owning plugin after the check.
- `/plugins/{plugin}/admin/*` (serve.php:243) is a closure with no permission
  check.
  - It serves about 87 pages in store, mailbox, event_manager, bookings,
    items, bug_reports and persona_browser.
  - Each page checks for itself. A page that forgets is open to anyone.
- Plugin `dynamic` routes are matched before core routes, so
  `/admin/server_manager/publish` and `/publish_theme` skip the `/admin/*`
  check. `publish_theme` is public on purpose: other sites' marketplaces fetch
  it without a session.

**`check_permission()`** appears 373 times:

- adm and adm/logic: 138
- core logic: 72, 70 of them `check_permission(0)`, which means "signed in"
- plugins: about 140

### 2.2 Levels

- The UI offers 0, 5 (Assistant), 8 (Admin) and 10 (Master Admin)
  (adm/admin_users_edit.php:70). The docs describe 5/7/9/10.
- Core pages gate at 5 (35 pages), 7 (2), 8 (41), 9 (10) and 10 (41).
- A view/change split already exists informally: 38 page or logic files test
  the permission inline, mostly "5 to see, 8 to delete".
- Server Manager is 10 throughout.

### 2.3 Menus

- A menu entry's permission and the page's own check differ in 33 places
  (B15). The menu decides only what the sidebar shows.
- Core menu rows are seeded from admin_menus.json with `overwrite=false`.
  Admins can edit them at `/admin/admin_admin_menu_edit`, and the edits are
  kept. A permission stored there is therefore admin-editable, so it cannot be
  the source of a page's access rule.

### 2.4 Models

- Every model already has a write check, `authenticate_write()`. The default
  allows the row's owner (`{prefix}_usr_user_id`) or anyone at permission 5
  and above.
- 116 models override it. About 100 overrides are the same few lines:
  - 82 say "below 5, refuse";
  - 16 say "below 10, refuse";
  - 4 say "below 8, refuse".
- **Nothing enforces the check on a write.** It runs only when:
  - a caller remembers `assert_can_write()` (173 files);
  - the REST API calls it (api/apiv1.php).
- `updateColumns()` writes without loading the row and runs no check at all
  (121 callers).
- There is no separate delete level, though core pages use "5 to edit, 8 to
  delete" inline.

### 2.5 API actions

- `/api/v1` actions accept POST only (ApiLogicEndpoint.php:134), reads
  included.
- `min_user_permission` defaults to 0 (ApiAuth.php:444). Only about 20 of 284
  descriptors set it. The rest check inside their logic or not at all.
- The floor compares the stored `usr_permission`, not the session's (B3). The
  same stored value is copied into the session the action runs under
  (`SessionControl::set_api_user()`), so the logic sees it too. Both the
  action face and the form face do this.
- Each descriptor has a `mutates` flag. The API does not enforce it. The AI
  assistant (joinery_ai) reads it to decide which actions need a person's
  approval before the AI runs them (ActionRegistry, RiskHeuristic,
  DescribeActionsTool, the recipe editor).

### 2.6 "A GET never changes anything" is not true (B1)

- About 80 core admin pages and most plugin logic files hand
  `array_merge($_GET, $_POST)` or `$_REQUEST` to their handlers without
  checking for a POST. `?action=delete` on the page's own URL works as a GET.
- Some of these have visible GET links: admin_static_cache, admin_plugins,
  admin_themes, admin_emails_send, admin_email `?send_test=1`.
- `SystemBase::assert_not_get_mutation()` (SystemBase.php:2884) only logs; its
  throw is commented out.
- The session cookie is `SameSite=Lax`, which a browser still sends on a
  top-level GET navigation from another site. A link anywhere on the web can
  therefore perform any GET-reachable admin action as a signed-in admin.

The declarations in this spec answer "is this person allowed?". They do not
answer "did this person mean to?". B1 stays a separate prerequisite (WP1).

### 2.7 Changes made outside a POST form

- Server Manager's backup and transfer buttons are `type="button"` and call
  `/api/v1` from JavaScript.
- Plugin and theme admin build their forms in JavaScript.
- Confirm modals submit forms with `.submit()`.

Any rule that only disables POST-form buttons would miss these. The server
must refuse; the page can only make the refusal clear.

## 3. Design

### 3.1 Sections declare who may open a page

**Plugins** declare one section in plugin.json:

```json
"adminAccess": {
  "view": 5,
  "pages": { "publish_upgrade": 10 }
}
```

- `view` covers every page of the plugin under `/admin/{plugin}/` and
  `/plugins/{plugin}/admin/`.
- `pages` lists the exceptions: pages stricter (or looser) than the section,
  named by the page's file name.
- The plugin's `adminMenu` entries take their permission from the section and
  the page exceptions. They no longer write one of their own, so the menu
  cannot disagree with the page.

**Core** declares sections in admin_menus.json under a new `adminSections` key:

```json
"adminSections": [
  { "slug": "users",  "view": 5,  "pages": ["admin_user*", "admin_users*"] },
  { "slug": "emails", "view": 10, "pages": ["admin_email*"],
    "exceptions": { "admin_email_statistics": 5 } }
]
```

- Pages are matched by file-name prefix. Every `adm/*.php` page must match
  exactly one section; a test fails on a page that matches none or two.
- `adminSections` is read straight from the file at runtime. It is never
  seeded into the editable menu table, so an admin cannot change who may open
  a page by editing the menu.
- Menu rows take their visibility from the section of the page they link to.
  The menu editor stops offering a permission field. It can still hide, order
  and rename.

**Undeclared means 10.** An admin URL that resolves to no section (a new
plugin with no `adminAccess`, a core page outside every prefix) requires
permission 10. Today such a page is open unless it checks for itself.

**Pages stop checking.** The `check_permission(N)` at the top of each admin
page and admin logic file goes away. A test fails on a new one under `adm/`,
`plugins/*/admin/` or `plugins/*/views/admin/`. Member pages'
`check_permission(0)` ("must be signed in") is not an admin level and is not
covered here.

### 3.2 The router enforces the section before any page code runs

- One check in `RouteHelper::processRoutes()`, after the navigation gates and
  ahead of custom, dynamic and fallback routes, maps the path to its section
  and page.
  - It covers both URL families and any dynamic route inside a plugin's
    namespace.
- Below the page's level: the login redirect or a 401, as `check_permission`
  does today, including the IP-change logout.
- A route can opt out explicitly. Two do: `publish_theme`, which is public, and
  the joinery_ai chat endpoints, which are also reached from `/profile/`. The
  opt-out is written on the route, never inferred.

### 3.3 Models declare who may change and delete their rows

```php
public static $write_permission  = 10;  // default 5
public static $delete_permission = 10;  // default: the write level
```

- **The rule.** The row's owner may write it, or anyone at `$write_permission`
  and above. A model with no owner column is level-only.
  - Soft delete, undelete and permanent delete use `$delete_permission` the
    same way.
- **Overrides.** The ~100 boilerplate overrides of `authenticate_write()` are
  replaced by the declaration. Models with real rules keep their override:
  - owner-only rows such as passkeys;
  - rows whose rule depends on their contents.
- **Enforcement is on the write itself.** `save()`, `soft_delete()`,
  `undelete()`, `permanent_delete()` and `updateColumns()` run the check
  against the session on every web request. CLI, cron and scheduled tasks have
  no session and are exempt, as `assert_not_get_mutation()` already does.
  - `updateColumns()` loads only the owner column, and only when the model
    uses the owner rule.
- **Server writes say so.** A write the server makes on its own, not on a
  user's behalf, runs inside `SystemBase::server_initiated_write()`. That
  marker already exists for writes during a page view. Examples:
  - a member's registration updating the event's count;
  - a webhook recording a payment;
  - a "look" action's job record;
  - the agent channel folding a job result.
- **Rollout is log-first,** like the GET-mutation check:
  1. A refused write is logged as `[WRITE_AUTH]` and allowed.
  2. Burn down the log.
  3. Turn on refusal.
- **Callers stop checking.** The 173 `assert_can_write()` callers become
  redundant once refusal is on. They are removed as their files are touched.
  `assert_can_write()` stays as the way to ask early.
- **Reads are unchanged.** `authenticate_read()` and the REST API's use of it
  stay as they are. Who may see a page is the section's job.

### 3.4 API actions declare who may run them

- Every descriptor declares `min_user_permission`. The default of 0 goes away:
  a descriptor without one fails the descriptor test.
- The floor reads the session's permission, not the stored one (B3).
- `mutates` stays, but it does not grant or refuse anything. It tells the AI
  assistant whether to ask a person before running the action. Whether the
  user is allowed comes from the action's level and the model checks under it.
- A side effect that is not a row write (restart a node, run a backup, send
  mail, publish a release) is gated only here. Such work must therefore be an
  API action. Legacy page handlers that cause one move to an action when
  touched; WP4 lists them.

### 3.5 Pages show only what the user can do

Controls ask the declarations instead of testing a level:

- `$obj->can_write($session)` and `$obj->can_delete($session)` return a bool
  from the same rule as 3.3.
- FormWriter, handed a model, renders the form read-only with a "needs
  permission N" note when `can_write()` is false.
- `action_button()` and `renderActionEntry()` (altlinks POST entries) take the
  action or model they act on, and render disabled with the same note when the
  user is below it.
- JavaScript-driven buttons are rendered by a helper that takes the API action
  name. It renders the button disabled when the session is below the action's
  level. An API refusal (403) shows its message through the existing
  `smApiPost` / `joineryApi` error path.
- The 38 inline "if permission >= 8, show delete" checks become `can_delete()`.

## 4. Decisions

- **D1. Separate delete level** (`$delete_permission`, defaulting to the write
  level). Owner, 2026-10-08.
- **D2. Core sections are declared in admin_menus.json by page-name prefix.**
  Owner, 2026-10-08. They are read from the file, never from the editable menu
  table (3.1).
- **D3. One `view` level per section.** Who may change something comes from
  the models and actions under it, so a section-level change level would be a
  second source.

## 5. Work packages

**WP1: make "a GET never changes anything" true (B1).** A prerequisite, and a
security fix by itself.

- Handlers act only on POST.
- GET links become POST buttons (CLAUDE.md rule 7).
- `assert_not_get_mutation()` throws instead of logging, once the log is clean.
- Order:
  1. Shared pieces: `DnsPublishBox::handle` acts on GET input and is used by
     Server Manager, mailbox setup and `/setup`.
  2. Server Manager: the publish page's delete is a GET (`?delete=N`, B2).
  3. Core `adm/`, then each plugin.
- Scope is Q2.

**WP2: model write and delete levels (3.3).**

- Add the two declarations, `can_write()` and `can_delete()`.
- Enforce in the five write paths, log-only.
- Replace the boilerplate overrides.
- Mark server writes with `server_initiated_write()`.
- Burn down `[WRITE_AUTH]`, then turn on refusal.

**WP3: section manifests and router check (3.1, 3.2).**

- Parse `adminAccess` and `adminSections`.
- Derive menu visibility from them; drop the permission field from the menu
  editor. This closes B15.
- Add the processRoutes check covering both URL families and in-namespace
  dynamic routes, plus the route opt-out.
- Undeclared means 10.
- Remove page-top `check_permission` from admin pages; add the test that
  forbids it.

**WP4: API action levels (3.4).**

- Every descriptor declares its level, and the test enforces it.
- Fix B3 in both places: the floor check, and the session the action or form
  runs under.
- List legacy page handlers with non-row side effects; move them to actions.

**WP5: page display (3.5),** plus a test per declared section:

- every page is reachable at the section's level and refused below it;
- every model and action a section's pages change refuses a user below its
  level.

**WP6: Server Manager adopts it.**

- Declare `"adminAccess": {"view": 5}`, with exceptions for any page that
  shows secrets.
- Its models declare write and delete at 10.
- Its read actions declare 5: `job_status`, `refresh_node_status`,
  `probe_api`, `moved_site_check`, and `backup_actions` for `refresh_list` and
  `list_status`.
- Every action that changes something declares 10.
- Q1 decides the node "look" actions.

## 6. Open questions

- **Q1. Node "look" actions for viewers.** May a permission-5 user run the
  read-only diagnostics on a node: check status, site log, log table tail,
  unit journal, file head, page probe, disk usage, schema probe?
  - Each creates a job record and asks the agent, but changes nothing on the
    node. The owner has said agent output is fine at permission 5.
  - Allowing it: these actions declare 5, and their job record is a server
    write.
  - Refusing it: a viewer sees only results someone else ran.
- **Q2. Scope of WP1.** Make GETs safe across the whole platform now (about 80
  core pages and most plugins), or do the shared pieces and Server Manager
  first and the rest as a following pass? B1 is live on every admin page until
  the whole pass is done.
- **Q4. Levels 7 and 9.** The UI cannot assign them, yet 12 core pages gate on
  them. Fold them into 8 and 10 when the core sections are declared?

## 7. Bugs found by the research

Each is tracked in memory `project_todo_admin_access_research_bugs`. They need
fixing whatever happens to this spec.

| ID | Bug | Where | Checked |
|---|---|---|---|
| B1 | Admin actions run on a GET, so another site can trigger them (SameSite=Lax sends the cookie on top-level navigation) | ~80 core pages, most plugin logic; DnsPublishBox::handle | pattern confirmed (admin_page, node_detail:83) |
| B2 | Server Manager publish page deletes a release archive and row on a GET | plugins/server_manager/includes/publish_upgrade.php:268, :1584 | reported |
| B3 | API action and form floors use the stored `usr_permission`, not the session's (login-as, IP-change zeroing); session simulation copies the stored value into the logic's session too | ApiLogicEndpoint.php:195, :700; SessionControl.php:1060; vs ApiAuth.php:301 | confirmed by reading |
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
