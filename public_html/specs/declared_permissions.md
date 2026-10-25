# Declared permissions

**Status:** Draft. Research 2026-10-07 and 2026-10-08. Design reworked
2026-10-08, and again 2026-10-20 after the owner's level scheme and a code
review: named data classes, a read level on models, raise-only column
classes, page headers, one decision function, a generated inventory
(sections 3 and 8). Every open question answered 2026-10-23. WP1 built and
released in log mode (0.8.521); its flip is left (WP1). WP0 built 2026-10-25
(docs/permissions.md). WP2 built 2026-10-25 and flipped the same day on the
owner's word: a refused save throws, and the log mode is gone. WP3 built
2026-10-25; every page-top check is gone (section 5, WP3 Flip). WP4 built
2026-10-25. WP5 built 2026-10-25, and every inline level comparison is
converted (WP5 Flip). WP6 built 2026-10-25. The research bugs are fixed except
B1, WP1's flip (7).

Running each model's own rules on every save is a separate spec,
`model_rules_on_every_save`, because it carries its own breakage and testing.

## 1. What this does

Today each page decides for itself who may use it and who may press its
buttons. A page that forgets is open, and the menu, the page and the model each
hold their own copy of the rule. Whatever a page says, any signed-in level 5
reads every model through the REST API and the AI chat (B23).

This spec makes permission a declaration of what a thing is, not a number a
developer picks. Every model, page, column and action names its data class,
one table turns a class into levels, and one function answers every "may this
person?" question. There are four questions and four places:

| Question | Declared by | Enforced by |
|---|---|---|
| Who may open this page? | The page's header | The router, before any page code runs |
| Who may read, change or delete this kind of row? | The model's class | `save()`, `soft_delete()`, `undelete()`, `permanent_delete()`, `updateColumns()`, and every surface that returns rows |
| Who may see or change this column? | The column's class, when stricter than the model's | The same write paths, plus FormWriter, the admin table helper, the REST serializer and the AI query executor |
| Who may run this action? | The API action descriptor | The API endpoint |

A page carries no permission rule of its own. The buttons it shows ask the
same declarations, so what the page offers cannot drift from what the server
allows. A developer adding a feature writes one word per model, one per page
and one per action; the scaffolder writes all three from one field in its
manifest (3.9).

The first user is Server Manager: the dashboard and node detail at 7 with the
read-only node checks (Q1, Q10), every change at 8, and every destructive or
key-changing action at 10 (Q8, Q9).
View-only comes from the model, column and action levels. There is no
separate "viewer mode".

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
- `authenticate_read()` has the same default, so any level 5 reads any row of
  any model. The REST API (api/apiv1.php:700, :925) and the AI chat
  (`ChatTurnContext::ADMIN_PERMISSION` is 5, which turns owner scoping off)
  both read through it (B23).

### 2.5 API actions

- `/api/v1` actions accept POST only (ApiLogicEndpoint.php:134), reads
  included.
- `min_user_permission` defaults to 0 (ApiAuth.php:679). Only 31 of 370
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

### 3.1 The ladder and the classes

Levels are a ladder: each includes the one below. A class is a fact about the
data; a level is a policy about people. Code declares classes. Only
`permissions.json` and explicit exceptions hold numbers, so a plugin shipped
to another site carries its facts and that site's table decides.

`permissions.json` at the `public_html/` root, beside `settings.json` and
`admin_menus.json`, declares the level names and the class table. It is read
from the file at runtime and never seeded into an editable table.

```json
{
  "levels": { "0": "Regular User", "5": "Assistant", "6": "Editor", "7": "Manager",
              "8": "Admin", "9": "Senior Admin", "10": "Master Admin" },
  "classes": {
    "content":    { "read": 5,  "write": 6,  "soft_delete": 7,  "permanent_delete": 9 },
    "money":      { "read": 5,  "write": 7,  "soft_delete": 7,  "permanent_delete": 9 },
    "personal":   { "read": 6,  "write": 8,  "soft_delete": 8,  "permanent_delete": 9 },
    "config":     { "read": 8,  "write": 8,  "soft_delete": 8,  "permanent_delete": 9 },
    "restricted": { "read": 8,  "write": 9,  "soft_delete": 9,  "permanent_delete": 9 },
    "keys":       { "read": 10, "write": 10, "soft_delete": 10, "permanent_delete": 10 }
  }
}
```

The names for 6, 7 and 9 are the owner's (Q14). The user edit page's level
dropdown reads this file instead of its own list
(`adm/admin_users_edit.php:70`).

| Class | What it covers |
|---|---|
| content | Pages, posts, events, forum, surveys, email templates, components, product designs, groups and tags, aggregate reports and charts |
| money | Prices, tiers, coupons, product mappings |
| personal | Users, contact details, addresses, orders, bookings, messages, mailbox content, uploads, bug and problem reports, a user's own AI chats |
| config | Settings, menus, plugins, themes, cache, redirects, scheduled tasks, nodes, releases, transfers, domains, other users' AI chats and memory |
| restricted | Server, error, sandbox and mail logs; stored passwords and account credentials, always masked |
| keys | API keys, signing, encryption and backup keys, webhook secrets, gateway and OAuth client secrets, node and provisioning credentials, always masked |

Three rules the table does not say:

- **Masking is a property of secrets, not a class.** A column named by a
  sealed-secrets locator, or a setting flagged `secret: true`, never renders
  its value below its read level, and renders as "set" or "not set" to anyone
  who may change it (3.3).
- **Destructive system actions are 10 whatever the class** (Q8): uninstall a
  plugin, remove a node or host, delete a release or backup, restore, reset,
  rotate a key, move a site. They are API actions and carry the number
  themselves (3.5).
- **Code that runs on every page is config at 10 to write**: component custom
  HTML, scheduled tasks, plugin and theme installs, AI instruction files.

What the owner's nine classes of 2026-10-20 became: A is content with no
writes; C is content; P is personal; F is money; G and I are config, with the
destructive actions that made I special carried by the actions; L and PW are
restricted, PW being its secret-flagged columns; K is keys.

### 3.2 Models declare a class

```php
public static $access_class = 'personal';
```

- The class gives the model its read, write, soft-delete and permanent-delete
  levels from the table. The row's owner (`{prefix}_usr_user_id`) may read and
  write their own row whatever the level, as today. A model with no owner
  column is level-only.
  A model whose owner column has another name declares it,
  `public static $owner_column = 'aic_owner_user_id';` (`$ai_owner_field` is the
  precedent); without that the owner rule never fires for it.
- A model that is an exception says so with a number, and only for the level
  that differs:

  ```php
  public static $delete_permission = 10;
  // also $read_permission, $write_permission, $permanent_delete_permission
  ```

- A model with no class fails the declaration test (3.9). Until it has one it
  behaves as keys.
- Models with real rules keep an override of `authenticate_write()`: owner-only
  rows such as passkeys; rows whose rule depends on their contents, such as
  forum and chat posts (author, or a moderator, and on create the space's post
  rule; `SpaceAccess::mayModerate()` reads the post model's write level, so the
  class sets the moderator level too, see the community shared components
  spec, C2). The ~100 boilerplate overrides go.
- **Reads are enforced on every surface that returns rows.**
  `authenticate_read()` compares against the model's read level, so the REST
  API, the AI query executor and every `assert_can_read()` caller follow the
  class. The AI chat's admin threshold becomes the model's read level, not 5.
  This closes B23.
- **Writes are enforced on the write itself.** `save()`, `soft_delete()`,
  `undelete()`, `permanent_delete()` and `updateColumns()` run the check
  against the session on every web request. CLI, cron and scheduled tasks have
  no session and are exempt, as `assert_not_get_mutation()` already does.
  `updateColumns()` loads only the owner column, and only when the model uses
  the owner rule.
- **Server writes say so.** A write the server makes on its own, not on a
  user's behalf, runs inside `SystemBase::server_initiated_write()`. That
  marker already exists for writes during a page view. Examples: a member's
  registration updating the event's count; a webhook recording a payment; a
  "look" action's job record; the agent channel folding a job result.
- **Rollout is log-first,** like the GET-mutation check: a refused read or
  write is logged as `[WRITE_AUTH]` and allowed; burn down the log; turn on
  refusal.
- **Callers stop checking.** The 173 `assert_can_write()` callers become
  redundant once refusal is on and are removed as their files are touched.
  `assert_can_write()` and `assert_can_read()` stay as the way to ask early.

### 3.3 Columns may raise, never lower

A column stricter than its model names its class in `$field_specifications`,
beside the type and validation FormWriter already reads there:

```php
'usr_password_hash' => ['type' => 'varchar(255)', 'access_class' => 'restricted'],
'usr_allowed_ips'   => ['type' => 'text',         'access_class' => 'config'],
'nod_api_token'     => ['type' => 'text',         'access_class' => 'keys'],
```

- **The model's class is the floor.** A column may only be stricter. A page
  that knows the model's class can trust that nothing on it is looser, and a
  reviewer reads one word per model plus its exceptions.
- **A model exception never lowers a column.** `$read_permission` and the
  other exceptions (3.2) move the model; a column stays at or above the
  model's class. A model whose only secret is one column is config with that
  column at keys, not keys with a read exception.
- **Secrets are declared once.** A column named by a sealed-secrets locator
  (`SealedSecretsDeclarations`, 15 core and 25 plugin entries naming
  `table.column`) is keys without saying so. A setting flagged `secret: true`
  (23 core, 23 plugin; `SettingsDeclarations::isSecret()`) is keys. The
  declaration test fails on a sealed column declared as anything else. B11
  becomes "declare the PayPal and Stripe fields secret in store's plugin.json".
- **Metadata is other columns.** "An 8 sees a key's prefix and created date
  but not its value" needs no masking mode: `apk_public_key` is config,
  `apk_secret_key` is keys, and each renders at its own level.
- **Below a column's read level:** FormWriter does not emit the input; the
  admin table helper drops the column; the REST serializer omits it; the AI
  query executor adds it to the set it already excludes
  (`$ai_excluded_fields`). A secret column the viewer may change but not read
  renders as "set" or "not set" with an empty change field, as password
  fields do today.
- **Below a column's write level:** `save()` and `updateColumns()` compare each
  incoming column with the stored value. Unchanged is ignored, so a stale
  form that still carries the field does not break (the settings-page
  lesson, B6 of that spec). Changed refuses the whole save, naming the column
  and the level. Refusing beats dropping the column silently, which would
  hide the bug that posted it.
- `get()` is untouched. Server code needs the values.

This replaces the per-field class on every model that the 2026-10-20 scheme
asked for. Most models declare nothing per column; the dozen that hold a
secret or a config column inside personal data declare that one column; the
mixed settings pages (B20), the user page's password and key tabs and the
Server Manager node page become "columns at their own level" instead of split
forms.

### 3.4 Pages declare a class; the router enforces it

Every admin page under `adm/`, `plugins/*/admin/` and
`plugins/*/views/admin/` opens with a header the router reads before any page
code runs, the way `@joinery-test` declares a test:

```php
/** @joinery-admin class=personal */
```

- The page's level is its class's read level. A page that needs a number
  instead (a redirect stub, a diagnostic page) says `level=8`.
- One check in `RouteHelper::processRoutes()`, after the navigation gates and
  ahead of custom, dynamic and fallback routes, maps the path to its file and
  reads the header, cached by file mtime. It covers both URL families
  (`/admin/*` and `/plugins/{plugin}/admin/*`) and any dynamic route inside a
  plugin's namespace.
- Below the level: the login redirect or a 401, as `check_permission()` does
  today, including the IP-change logout.
- **Undeclared means 10.** An admin page without a header requires permission
  10, and the declaration test fails. Today such a page is open unless it
  checks for itself.
- A route can opt out explicitly. Two do: `publish_theme`, which is public,
  and the joinery_ai chat endpoints, which are also reached from `/profile/`.
  The opt-out is written on the route, never inferred, and it never removes
  the check from the `/admin/` path.
- **Pages stop checking.** The `check_permission(N)` at the top of each admin
  page and admin logic file goes away; the declaration test fails on a new
  one. Member pages' `check_permission(0)` ("must be signed in") is not an
  admin level and is not covered here.
- **Menus derive.** A menu entry, core (`admin_menus.json`) or plugin
  (`adminMenu` in plugin.json), shows to whoever can open the page it links
  to. The permission field leaves both files and the menu editor, which can
  still hide, order and rename. This closes B15.

### 3.5 Actions declare a level

```php
'min_user_permission' => 'personal:write',   // class:verb; verbs read, write, soft_delete, permanent_delete
'min_user_permission' => 8,                  // a side effect with no row: restart, backup, send, publish
```

- Every descriptor declares one. The default of 0 goes away: a descriptor
  without one fails the declaration test.
- **An action a member runs on their own rows declares 0**, which means
  signed in; the model's owner rule is the gate, as it is for the page.
  `class:verb` is for actions that work across users: admin pages, the AI
  assistant and recipes. A mutating action never carries a `:read` level.
- The floor reads the session's permission, not the stored one (B3).
- `mutates` stays, but grants or refuses nothing. It tells the AI assistant
  whether to ask a person before running the action. Whether the user is
  allowed comes from the action's level and the model and column checks under
  it.
- A side effect that is not a row write (restart a node, run a backup, send
  mail, publish a release) is gated only here. Such work must therefore be an
  API action. Legacy page handlers that cause one move to an action when
  touched; WP4 lists them.

### 3.6 One decision function

Every check above is one call, `Access::decide($requirement, $session)`,
where the requirement is a level, a class and verb, a model or a column. It
returns allow or refuse. Nothing else in the tree compares a permission
number.

- **It is where audit lives.** A refusal, any allowed write at 9 or above, and
  any read of a keys column land in the event log with who, what and the
  level asked for. The `[WRITE_AUTH]` rollout log is the same call in
  log-only mode.
- **It is where the ladder can change.** If a ladder ever stops being enough,
  the integer becomes a capability set inside this one function and no page,
  model or action changes.

### 3.7 Pages show only what the user can do

Controls ask the declarations instead of testing a level:

- `$obj->can_read($session)`, `$obj->can_write($session)` and
  `$obj->can_delete($session)` return a bool from the same rule as 3.2.
- FormWriter, handed a model (`['model' => $obj]`), renders the form read-only
  with a "needs level N" note when `can_write()` is false, and applies the
  column rules of 3.3 to each input.
- `action_button()` and `renderActionEntry()` (altlinks POST entries) take the
  action or model they act on, and render disabled with the same note when
  the user is below it.
- JavaScript-driven buttons are rendered by a helper that takes the API action
  name. It renders the button disabled when the session is below the action's
  level. An API refusal (403) shows its message through the existing
  `smApiPost` / `joineryApi` error path.
- The 38 inline "if permission >= 8, show delete" checks become
  `can_delete()`.

### 3.8 Users: the target rule

The User model keeps one rule of its own, in its `authenticate_write()`: a
user may read, write, delete, undelete, impersonate, reset the password of,
change the factors of, or issue a key for only a user strictly below their
own level, and may grant at most their own level. Level 10 is exempt, so two
owners can manage each other. Without this a 9 resets a 10's password and
signs in as 10 (B22). The rule covers `admin_users_edit`,
`admin_users_password_edit`, `admin_user_login_as`, `admin_api_key_edit`
(a key carries its user's level) and passkey and second-factor changes.

### 3.9 Developer surface

- A new model: `$access_class`. A new page: the header. A new action:
  `min_user_permission`. The scaffolder (docs/scaffolding.md) takes
  `"access_class"` in its manifest and writes all three.
- `php maintenance_scripts/dev_tools/permissions_inventory.php [--plugin=NAME]`
  prints every page, model, column exception and action with its class and
  levels. Section 8.4 is what it prints; once the tool exists the hand-kept
  table goes.
- The declaration test fails on: an admin page without a header; a model
  without a class; a descriptor without a level; a `check_permission()` in an
  admin page or admin logic file; a sealed column declared below keys; a
  column class looser than its model.

## 4. Decisions

- **D1. Separate delete level.** Owner, 2026-10-08. Now derived from the
  class, with `$delete_permission` as the per-model exception.
- **D2. Core sections declared in admin_menus.json by page-name prefix.**
  Owner, 2026-10-08. Superseded by D5 on 2026-10-20.
- **D3. One `view` level per section.** Superseded by D5 and D6 on
  2026-10-20.
- **D4. Code declares classes, not numbers.** Owner, 2026-10-20. Six classes
  (3.1); numbers live in `permissions.json` and in explicit exceptions.
- **D5. A page declares its class in a header in its own file**, core and
  plugin alike; the audit inventory is generated. Owner, 2026-10-20.
- **D6. A column may raise above its model's class, never lower**, enforced
  on the write paths and shown by the surfaces (3.3). Owner, 2026-10-20.
- **D7. The user target rule is strictly below, with 10 exempt** (3.8).
  Owner, 2026-10-20.
- **D8. Models carry a read level**; the REST API and the AI surface honour
  it (3.2). Owner, 2026-10-20.
- **D9. AI chats are config: admins only (8) open another person's chat**;
  each person reaches their own through the owner rule. Owner, 2026-10-21.

## 5. Work packages

**WP0: the foundation.** `permissions.json`, `Access::decide()`, the
declaration test (passing on nothing until the later packages declare
things), the inventory tool.

- Built 2026-10-25. `permissions.json` as 3.1; `Access` (`level()`,
  `allows()`, `decide()`, `schemaErrors()`); `AccessDeclarations` reads every
  page header, model class and exception, owner column, column class and
  action level from the source, and `problems()` is the test's rules
  (`tests/unit/access_declarations_test.php`; the audit rows in
  `tests/models/access_audit_test.php`). The inventory tool reads
  permissions.json and lays the code's declarations over its seed.
- `ApiAuth::authorize()` and the key page's scope list read an action's level
  through `Access::allows()`. Before this, `(int)'personal:write'` was 0: the
  first `class:verb` level declared would have opened its action to everyone.
- Requirements are a level or `class:verb`. A model or a column as the
  requirement (3.6), and the log-only mode `[WRITE_AUTH]` uses, come with WP2,
  their first caller.
- An anonymous refusal is not audited: it is a sign-in page, and recording it
  would let any visitor fill the event log (the B32 lesson).
- The user edit page's level dropdown lists `Access::levels()` (3.1, Q4), and
  its save refuses a level the file does not name. Before, the dropdown was
  0/5/8/10 and the save stored whatever was posted (B38).
- The four rules asking for a declaration everywhere are off in
  `AccessDeclarations::RULES`; each package turns its own on: page headers
  and `check_permission()` (WP3), model classes (WP2), action levels (WP4).

**WP1: make "a GET never changes anything" true (B1).** A prerequisite, and a
security fix by itself. Owner 2026-10-22: one guard at the database layer, not a
POST check in each of the ~60 handlers.

- `GuardedPdo` (1.3) checks every statement on a web GET, HEAD or OPTIONS: a
  write that is not the server's own (`server_initiated_write`) is logged as
  `[GET_WRITE]` with the request, verb, table and file:line
  (`PAGE_VIEW_WRITES = 'log'`). It catches raw SQL and every plugin, which the
  model-level `assert_not_get_mutation()` never saw.
- The server's own page-view writes are marked: visitor events, logout's
  cleanup, Stripe/App Store/Google Play subscription reconciliation, the Direct
  capability cache, mailed one-time links (email verify, recovery confirm),
  the app bridge claim, the getjoinery Connect return, the share-link counter.
- GET links that wrote become POST buttons: static cache, plugin and theme
  sync, email send test / queue / send queued, Log in as user, update_database,
  the Stripe sync utilities, the publish page's delete (B2). Handlers made
  POST-only on the way: DnsPublishBox (a link could set the OAuth client),
  setup_logic actions, password-reset request, question edit (opening it saved
  the question blank), the permanent-delete confirm pages for users and orders
  (`?confirm=1` deleted), notification preferences, the product version
  buttons (they blanked the version name), and the four handlers that carried
  the stale opt-in comment.
- Sign-out stays a link (a GET), accepted: logout() also runs from the server's
  own forced sign-outs, a forged sign-out costs a person one sign-in, and its
  cleanup writes are marked as the server's own.
- Built 2026-10-22; the test harness runs GET logic calls as page views. A
  full fresh run (775 suites) logged no page-view write, but only 32 harness
  calls are GETs, so that is not an inventory (reviewer1). **Left, the flip:**
  ship 'log' for a release; read `[GET_WRITE]` from real nodes for that
  release (Drive sync clients, the app, mailbox flows and webhooks reach paths
  dev does not; an agent word that counts a marker in a node's error log is
  the missing primitive); sweep every view route and API read action with
  PageProbe's view-only session on dev; then set `PAGE_VIEW_WRITES =
  'refuse'` and drop `assert_not_get_mutation()` from SystemBase.

**WP2: models (3.2, 3.3, 3.8).**

- `$access_class` on every model, from the class column of 8.4; a read level
  on every model.
- Column classes, with the sealed-secret and secret-setting derivations.
- `can_read()`, `can_write()`, `can_delete()`.
- Enforce reads and the five write paths through `Access::decide()`,
  log-only; replace the boilerplate overrides; mark server writes; burn down
  `[WRITE_AUTH]`; turn on refusal.
- The user target rule.

- Built 2026-10-25, log mode (docs/permissions.md § Models). Every one of the
  254 models declares `$access_class` from the seed; the declaration test's
  `model_undeclared` rule is on. 143 boilerplate `authenticate_read/write()`
  overrides are gone; the kept ones read levels through `access_level()`
  (User, Message, ContentReport, SpaceAccess's moderator level) or override
  `access_owner_is()` (User, Entry, Schedule). 54 column classes from 8.6;
  a sealed column is keys by `column_access_class()`.
- Exceptions added beside the seed's: Message and Conversation read 8 (Q13);
  Recipe and the three plugin-record models write 10 (3.1, code); joinery_ai's
  AiMemory, RecipeNote, RecipeRun and AipRecipeItemLog write 10. The verbs an
  override held above its class, confirmed at the class (8.3 item 6; the owner
  may raise any back to an exception):

  | Model | Verb | Was | Now (class) |
  |---|---|---|---|
  | AppBridgeToken | read / write | 10 | 6 / 8 (personal; the token is a hash, restricted) |
  | EmailTemplate | write | 10 | 6 (content) |
  | ProblemReport | read / write | 9 | 6 / 8 (personal) |
  | ReceivedBugReport | read / write | 9 | 6 / 8 (personal) |
  | PushDestination | read / write | 10 | 6 / 8 (personal) |
  | InboundPersonalAddress | write | 10 | 8 (personal) |
  | SupportThread | read / write | 9 | 6 / 8 (personal) |
  | SupportDocRelease, SupportDocSection, SupportFaqEntry, SupportWordGap | write | 9 | 6 (content) |
  | StripeInvoice | read | 8 | 6 (personal) |
  | Setting | write | 10 | 8 (config; a secret setting is keys, B20) |
  | PublicMenu | write | 10 | 8 (config) |
  | RelayClientIdentity | read / write | 10 | 8 (config; its secret is sealed, so keys) |
  | InboundBayesToken | write | 10 | 9 (restricted) |

  Raised by the class: SiteSupportThread write 5 to 8; every "below 5" model
  to its class's levels (a 5 no longer edits content).
- `$owner_column = false`: a user column that records who acted is not an
  owner (EventLog, GeneralError, Login, RequestLog, VisitorEvent and nine
  more). Without it, removing a "below 5" override would have let a user
  read and write their own log rows.
- The write paths: `save()` and `updateColumns()` ask the write and column
  rules, `soft_delete()`/`undelete()` the soft-delete level,
  `permanent_delete()` its own; a web request only. `Access::$model_enforcement`
  is `'log'`: a refusal writes one `[WRITE_AUTH]` line per place per request
  (the trail from `GuardedPdo::writer()`) and the write goes ahead.
- Explicit asks (`assert_can_*`, the REST record surface, the AI chat) follow
  the class at once, not log-first: they already threw, and every class's
  level is at least today's 5, so the change is only tighter (B23 closed:
  a 5 no longer reads personal or config rows over the REST API or the AI
  chat), plus the owner rule on models that gained a declared owner column.
- **A second marker, `rule_checked_write()`** (deviation from 3.2's "server
  writes run inside `server_initiated_write()`"): some writes have a person
  behind them that a rule of the caller's own already admitted (the messenger
  posting a participant's message into a protected conversation, a
  notification to another user, Drive's blob, change-feed and key-grant
  rows). `server_initiated_write()` would also lift the page-view guard, which
  must still hold for them. Callers listed in core_api_mechanical_test.
- **The REST record surface ran with an anonymous session** (found by the
  burn-down): only actions called `set_api_user()`. Every route family past
  the action dispatch now runs as its caller, so the model check sees the
  person ApiAuth authorized.
- Server writes marked from dev's `[WRITE_AUTH]` lines (other sessions' gates
  and live tests, 05:35 to 06:00): the agent channel, relay birth, backup
  broker, Direct receiver, auth/login and device_link, the password rehash,
  API idempotency rows, cookie consent, and the six webhook receivers (marked
  after their signature check).
- Review (reviewer2, 2026-10-25): `updateColumns()` asked only an owner
  column, so a member's own write on a model whose owner is not a column
  (User, CalendarEntry, InboundEmailMessage, AiConversationMessage) was
  refused; it now loads the row on the below-level branch and asks the rules
  `save()` asks. A permanent delete's cascade asked each dependent again as the
  user; dependents now follow the parent's decision (`rule_checked_write`).
  Sign-up and the password reset write before anyone is signed in and are
  marked. `SystemMultiBase::assert_can_read()` called itself (since
  e9d32f5c8). The provisioning service account reads and queues mail for
  other people's orders: personal, so it is created at 8 and migration
  sm_026 raises an existing one from 5.
- **Flipped 2026-10-25** on the owner's word, before a release in log mode:
  `Access::$model_enforcement` and the `[WRITE_AUTH]` log mode are gone; a
  refused write records `access_refused`, logs `[WRITE_REFUSED]` with the code
  that wrote, and throws. Dev's log held 227 `[WRITE_AUTH]` lines that day, all
  from before the server writes were marked or from tests; none came from the
  fresh full gate run that afternoon, or from dev's traffic, before the flip. A model helper that writes with its own
  SQL asks `guard_raw_write()` (B46); the owner rule reads the stored owner
  (B45). Risk carried into the release: a server write a dev path never takes
  is refused on a node; `[WRITE_REFUSED]` with user 0 names it (live
  verification queue).
- **Flip review (reviewer2, 2026-10-25), 5 NEEDED, all taken.** N1: the flip
  refused every member and visitor write to a row with no owner (163 of 261
  models): passkey ceremonies, the hosted mailbox, tier changes, a first
  message, forum topics, boards, AI chat, the support desk, bookings, calendar
  exceptions, newsletter signup, comments, notification mail, profile photos,
  Drive collaborators. Dev went back to logging within the hour; each flow now
  says why it may write (a real owner rule where one exists: Board, schedule
  windows and overrides, entry exceptions, vault wrappings, entity photos,
  mailbox rows by grant, AI chat by its owner, DNS blocks by device; else
  `rule_checked_write()` after the flow's own check, or
  `server_initiated_write()` before sign-in and for the platform's own mail,
  logs and drains), every caller listed in core_api_mechanical_test, and dev
  refuses again. The gate could not see N1 because the CLI was exempt: a test
  now checks every write made while a simulated person is signed in
  (`Access::$check_cli_persons`, set by harness_boot), refusing. N2 (owner
  decision): the owner rule covers read, write and soft delete; a permanent
  delete is the level's alone, and a member's own purge says so. N3: agent
  files are 10 throughout (their pages, `$delete_permission`, and
  `write_to_disk()` itself). N4: `updateColumns()` asks a model's own write
  rule always. N5: the coupon's links and an email's recipient groups follow
  their parent's rule; removal buttons draw disabled below the row's rule.
  V1-V5 taken (the request-wide flag documented, one audit row per guarded
  delete).
- The 173 `assert_can_write()` callers are redundant
  and go as their files are touched. joinery_ai's AiMemory, RecipeRun, RecipeNote and AipRecipeItemLog
  overrides that compared 10 became a write exception of 10 (and an owner
  column for the first and third).

**WP3: pages (3.4).**

- A header on every admin page, from 8.4.
- The processRoutes check, both URL families and in-namespace dynamic routes,
  plus the route opt-out. Undeclared means 10.
- Menus derive; the permission field leaves admin_menus.json, plugin.json and
  the menu editor (closes B15).
- Remove page-top `check_permission` from admin pages; the declaration test
  forbids it.
- The user edit page offers every level in `permissions.json` (Q4).

- Built 2026-10-25 (docs/permissions.md § Pages). Every one of the 253 admin
  pages carries its header from 8.4 (the three Server Manager pages 8.4 lacks
  are config; WP6 sets the dashboard's 7); `page_undeclared` is on.
  `/admin/server_manager/publish`'s script, `includes/publish_upgrade.php`, says
  `level=8`.
- The check is `RouteHelper::enforceAdminPage()` with `AdminPageAccess`: before
  custom, dynamic and fallback routes, for the three URL families and a
  plugin's dynamic route inside `/admin/` (checked against its `view`).
  `check_permission()` does the refusing, so the sign-in redirect and the
  IP-change logout are unchanged. The opt-out is `'admin_page_check' => false`
  on publish_theme's route. The joinery_ai chat endpoints need none: their
  `/profile/` copies are not admin paths, and under `/admin/` they are
  checked like any page.
- Menus derive (B15 closed): `MultiAdminMenu::visible_entries()` shows an
  entry to whoever may open its page and a section when one of its entries
  shows, for the admin sidebar and the member menus. `permission` left
  admin_menus.json, every plugin.json, the sandbox manifest's menus and the
  menu editor; `syncMenus()` ignores one still sent. `amu_min_permission`
  stays in the table (NOT NULL in existing tables), written 0, never read;
  `update_database --cleanup` can drop it.
- Page-top `check_permission()`: the 94 at or below their page's level are
  gone.
- **Flip, 2026-10-25:** the other 147 went with WP2's flip, each read for what
  its page does that no model check covers. Most were write proxies and were
  deleted: the page opens at its class. Seventeen pages whose every action
  needs more declare it: `class=keys` for the mailbox credential pages and
  provisioning setup; `level=10` for run-now, stop-run, install-node, help
  edit and login-as; `level=9` for error delete, the static cache, problem
  reports and a bug report; `class=config` for the support threads (their
  transcript is AI conversation data); `level=8` for the user edit page. One
  action needing more than its page asks `Access::demand()`: installing,
  activating or removing code (plugins, themes, marketplace, sandbox
  approval, updates), running a task or a dry run, rebuilding the test
  database, writing agent files to disk, a domain retry (it buys the domain),
  a service tenant's grant and release, a mail shard, the mail-apps gateway
  (restricted write), a DKIM-carrying domain's permanent delete (keys), the
  2FA resets (keys), activating a user (restricted write), and a tier change
  (money write). A Stripe refund and a product's Stripe create ask the row's
  rule before the provider is called. The `admin_check_permission` rule is
  on; `AdminPageAccess::levelForFile()` reads the header alone.
  `admin_page_content_permanent_delete_logic.php`, whose page was deleted
  earlier, went too.
- The scaffolder writes the header (`class={access_class}`) on its admin views,
  no `check_permission()` in its admin logic, an `assert_can_write()` before
  the edit save, and `'{access_class}:write'` on the admin edit action
  (`admin_permission` left the manifest).
- Test: `tests/functional/admin_page_access_test.php` (33 checks: the mapping,
  the levels, the menu rule, and over HTTP signed out, at 6 and at 10).
- Review (reviewer2, 2026-10-25): `/setup` forwarded four backups actions to
  admin_backups_logic, whose own `check_permission(10)` this package removed;
  the forwarding now asks the backups page's level. A kept higher check is the
  page's level while it stays (`AdminPageAccess::levelForFile()` reads it, cached
  by mtime in APCu), so menus and pages agree. An inactive plugin's admin pages
  map to no file and 404 as before. The header is matched on a line of its own.
  The adminMenu report-problem entry links to a member page, so it shows to
  everyone who sees the admin sidebar (it said 5). A newer plugin.json meeting
  an older core's syncMenus (which required `permission`) is not a real path:
  plugins ship with their release, and the marketplace builds sandboxed
  plugins' menus from their manifests.

**WP4: API action levels (3.5).**

- Every descriptor declares its level, and the test enforces it.
- Fix B3 in both places: the floor check, and the session the action or form
  runs under.
- List legacy page handlers with non-row side effects; move them to actions.

- Built 2026-10-25. Every descriptor outside Server Manager (WP6) declares
  `'auth' => ['min_user_permission' => …]` (357 declare; 303 were written here
  from 8.7's proposals);
  `action_undeclared` is on. A descriptor made by a builder declares its level
  in the builder, and `AccessDeclarations` follows `return Class::method(`
  (`ForumAction::memberDescriptor()` already said 0; `MailAppAction::descriptor()`
  now does).
- Where a proposal would have locked members out it was 0 instead, the logic's
  own rule being the gate: the joinery_ai panel and chat actions
  (ai_action_resolve, ai_actions_list, ai_panel_state, ai_panel_toggle,
  ai_status, chat_level_batch, and chat_set_capabilities, which the shared
  chat body offers on both the admin and the /profile/ chat), the mailbox's
  attachment_text, send, setup_status and direct_status,
  forum_post_moderate (moderators are ForumPost's write level, 5),
  office_editor_create, passkey_client_report, vault_heartbeat,
  support_origin_confirm, resolver_snapshot (a DNS server's key) and the three
  fleet_* actions a fleet member calls.
- Raised from a declared level: plugin_provisioning_check 5 to 8,
  user_search 5 to personal:read, validate_server_file 5 to config:read,
  resolver_key_issue 8 to keys:write, resolver_key_revoke 8 to 10 (an 8 on the
  config plugin-settings page sees them refuse until WP5 draws them disabled;
  key-changing is 10, Q8), test_connection 8 to 10 (its logic refused below
  10). **Left for
  the owner** (never lowered here): email_available and image_list declare 5,
  8.7 proposes 0; the support desk's eight support_* actions declare 5, 8.7
  proposes 0 (assets/js/support_panel.js calls them from member pages, so 0
  moves nothing else); marketplace_catalog declares 10, 8.7 proposes 8.
- B41 (found here): root_request_status's descriptor said
  `'min_permission' => 8`, a key nothing reads, so its floor was 0 (the logic
  checks 8 itself). It declares config:read; the declaration test's
  `action_level_misplaced` rule refuses that key.
- B3 was fixed in both places on 2026-10-24 (`ApiLogicEndpoint::actingPermission()`).
- Legacy page handlers with a side effect that is not a row write (to move to
  an action when touched, 3.5): admin_backups (exec), admin_plugins and its
  logic (activate, deactivate, uninstall), admin_themes (activate),
  admin_sandbox_approve (activate), admin_scheduled_tasks, admin_static_cache
  (cache clear), admin_emails_send, admin_settings_email (test send),
  admin_debug_email_logs (resend), admin_user (mail), admin_problem_reports
  (send), bookings' admin_booking_type_edit (mail), the mailbox setup page
  (test mail), admin_apache_errors (shell), and Server Manager's index,
  node_detail, job_detail, install_node_form and publish_upgrade pages (jobs).

**WP5: surfaces (3.3, 3.7),** plus a test per plugin and per core section:

- FormWriter, the admin table helper, the REST serializer and the AI query
  executor apply the column rules; `action_button()`, `renderActionEntry()`
  and the JavaScript button helper take a model or action.
- Every page is reachable at its level and refused below it; every model and
  action a page changes refuses a user below its level.

- Built 2026-10-25 (docs/permissions.md § Surfaces). The column rules:
  `columns_hidden_from_session()` and `columns_locked_for_session()` on a row
  (none on the CLI; the owner sees their own). The REST serializer omits a
  hidden column in all three shapes; FormWriter handed a saved row does not
  draw a hidden input or its value, disables a locked one, and draws a row the
  reader may not change read only with a "needs level N" note (a new row is
  left to its save, which knows its owner); `tableheader()` takes
  `'columns' => [position => 'Class.column']` and drops that column; the AI
  executor excludes a hidden column unless the reads are owner-scoped.
- Controls: `action_button()` and `renderActionEntry()` take `'requires'`
  (a level, `class:verb` or `api:{action}`) or `'model'` + `'verb'`
  (`action_refusal()`), and below it draw the button disabled with the note;
  `api_action_button()` reads an action's declared level from its descriptor
  and carries `data-jy-api-action`. Converted to the row's rule or the model's
  level: admin_page, admin_post, admin_survey, admin_message (delete, undelete,
  permanent delete, photos), admin_event (edit, photos, emailing registrants,
  new session), store admin_products, admin_product_edit, admin_product_groups,
  event_manager admin_event_types.
- Until WP2's flip a hidden control's handler may still accept the POST (the
  model check only logs): the page then shows the rule that the flip enforces.
- Tests: `tests/functional/admin_page_levels_test.php`, one check per core
  section and active plugin (14): every admin page opens at its level and
  refuses (401) the level below; it found admin_emails_send, a send handler
  declared as a personal page, now `level=8`. The surfaces are in
  model_access_test (47).
- Review (reviewer2, 2026-10-25), all taken: a link or POST entry that targets
  another page asks that page's level (`'requires' => 'page:{path}'`,
  `PublicPageBase::may_open()`): admin_page's and admin_post's Permanent
  Delete, admin_survey's Edit, and the two send buttons to admin_emails_send.
  A refusal by the row's own rule at the reader's level says "Not allowed for
  this row.". The two classes are styled in joinery-styles.css. A 500 now
  fails admin_page_levels.
- **A 5 stops editing content in the UI with this package** (the owner's
  scheme, 8.3 item 1): seven edit pages that open at 5 hand FormWriter a row
  whose model writes at 6 or 7, so a non-owner 5 sees them read only where they
  could save before: admin_post_edit, admin_page_edit, admin_component_edit
  (authors keep theirs), admin_email_template_edit, admin_question_edit,
  admin_subscription_tier_edit (B17) and forum's admin_forum_category.
- B42 (found by admin_page_levels): 39 admin pages answered 500 when opened
  without their row id, and two at any time. A record loaded with no key
  answers 404 (`ErrorStatus::of()`), a missing or malformed request variable
  read by `LibraryFunctions::fetch_variable()` answers 400 (it threw a
  permanent error); admin_surveys sorted its question counts by a column that
  does not exist; admin_stripe_invoices handed its collection null options
  without `?u=`; admin_order_refund threw a permanent error for a missing item.
  Opening every page that way also showed 64 PHP warnings in 45 pages (missing
  request keys, uninitialised variables), fixed at each.
- **Flip, 2026-10-25:** every inline level comparison (about 190 in 150
  files) asks `Access` (`permits()` for what a page shows, `decide()` or
  `demand()` for an action, `allows()` for a stored level), a row's rule or a
  model's level; the `level_compared_inline` rule fails a new one. Levels that
  moved with their meaning: deleted rows in a list show at the model's
  soft-delete level (7 or 8, was 10); Delete, Undelete and Edit controls follow
  the row's rule (content soft delete 7, was 8; permanent delete 9, was 10 or
  8; Add User 8, was 10); Drive sharing, key grants and link revocation for
  another member's item, and a DNS filter on another member's device, take the
  row's write rule (8, was 5); the IMAP feed controls take the account's
  write level (9, was 10); the mailbox and domain trash its soft-delete level
  (8, was 10); the test-mode coupon list money read (5, was 8); a bug report
  notice the reports' read level (6, was 9); the sender's member account
  personal read (6, was 5). `Access` gained `permits()`, `demand()` and
  `staffLevel()`. A read of a keys column is not yet
  audited (3.6). No admin list page passes `'columns'` yet: none shows a
  column stricter than its model.

**WP6: Server Manager adopts it.**

- Pages are config, the dashboard at 7 (Q9, Q10) and the redirect stubs at 8.
- Its models are config; node and provisioning credentials are keys columns.
- Read actions declare `config:read` or 7 for the dashboard's own: `job_status`,
  `refresh_node_status`, `probe_api`, `moved_site_check`, and `backup_actions`
  for `refresh_list` and `list_status`.
- Refresh, probe, run job and publish declare 8. Destructive and key-changing
  actions declare 10 (Q8).
- The node "look" actions declare 7 (Q1); Q11 decides job output.
- **Split `backup_actions`.** One action both lists and runs backups. Split the
  read sub-ops (`refresh_list`, `list_status`) into their own action at 7; the
  run and restore sub-ops stay at 8 (destructive restores at 10, Q8). Until the
  split lands the whole action is 8 (8.7).

- Built 2026-10-25 (plugins/server_manager/docs/overview.md, Pages). The
  dashboard, node detail, jobs and job detail open at 7 (`level=7`); their
  blanket `check_permission(10)` is gone, and so is that of publish_upgrade,
  rollouts, fleet_security, incident, incidents, machines and specs (8). Every
  POST on those pages asks `SmActionLevels::refuse_post()` first: the node
  detail actions (76: the looks at 7, changes at 8, destructive and
  key-changing at 10), the dashboard's join, escrow and break-glass actions
  (10), job cancel and rerun (8), deleting a release and pruning archives (10),
  triage, rollouts and security runs (8). An action not placed needs 10; the
  test fails a page action without one. Below 8 node detail shows only its
  overview, jobs and backups tabs, and the DNS publish box is not offered.
- Pages that keep a 10 of their own (the router reads it): host_add, node_add,
  machine_edit and install_node_form (credentials, a server bought), and the
  domains, transfers, service tenants and provisioning setup logic; targets and
  target_info are keys.
- Actions: job_status, refresh_node_status, probe_api and moved_site_check
  at 7; **the split**: `backup_list` (refresh_list, list_status) at 7, and
  backup_actions at 8 with delete_file and restore_objects at 10 in the logic
  (`Access::decide(10)`); incident_triage and release_notes_draft at 8 (their
  logic no longer checks 10 itself); transfer_operator 10; releases (public),
  domain_check (guest checkout), the services_* and shelf_* actions (another
  site's key) and transfer_customer (the buyer's own page) at 0.
- Models are config with node and provisioning credentials as keys columns
  (WP2: mgn_api_secret_key and the other credentials are sealed, so keys).
- The provisioning service account is 8 (WP2's review: it reads and queues
  mail for other people's orders); migration sm_026.
- Q11's job output reaches 7 through job_detail, redacted on the node and by
  `SmSecretRedactor` on the page.
- Test: plugins/server_manager/tests/sm_action_levels_test.php (70).
- Review (reviewer2, 2026-10-25): 0 NEEDED. The services_* and shelf_* actions
  are 0 because the tenant is the calling key's (`ShelfBroker::tenantFor()`,
  `JoineryServices::enrol()` refuse a tenant whose user is not the caller).
  backup_list declares `mutates` (refresh_list files a job), so the AI
  assistant asks before that look. **For the owner (Q8):** hold_container
  (stopping a container is an outage, reversible) and apply_update_all_on_host
  (one click upgrades every site on a host) are at 8 as changes; say if either
  is "destructive" and belongs at 10.
- **Left:** node detail's tabs still draw a 7 the buttons for 8 and 10 actions,
  which refuse with the level when pressed; drawing them disabled
  (`action_refusal()`) goes with each tab as it is touched.

## 6. Open questions

- **Q1. Node "look" actions for viewers.** Answered 2026-10-23: all at 7.
  Check status, site log, log table tail, unit journal, file head, page probe,
  disk usage and schema probe declare 7; each creates a job record and asks the
  agent, but changes nothing on the node. The raw-text results (site log, log
  table tail, unit journal, file head) reach 7, so what 7 sees of them is Q11.
- **Q2. Scope of WP1.** Answered 2026-10-22: the whole platform at once, by
  one guard at the database layer (WP1).
- **Q4. Levels 6, 7 and 9.** Answered 2026-10-20: real levels (8.1). The UI
  offers them from `permissions.json`.
- **Q5. Keys.** Answered 2026-10-20: keys are 10 to read and change. A key's
  metadata is its other columns, at their own class (3.3).
- **Q6. Money.** Answered 2026-10-20: 5 reads, 7 writes and soft-deletes.
- **Q7. Permanent erasure of a user.** Answered 2026-10-20: 9, the personal
  class rule.
- **Q8. Destructive actions.** Answered 2026-10-20: destructive and
  key-changing system actions are 10 (3.1).
- **Q9. Server Manager dashboard.** Answered 2026-10-20: dashboard at 7, node
  detail at 8, other actions 8.
- **Q10. The dashboard shows addresses today.** Answered 2026-10-23: show
  them at 7. None is a secret (domains and site IPs are public, keys and
  fingerprints are made to be shared), join-request source IPs included. Node
  detail opens at 7 too, so a 7 can run the Q1 look actions; its 8 actions are
  hidden from a 7 (3.7). This revises Q9's node detail at 8.
  - Facts (2026-10-20): the dashboard renders host and node names (`mgn_name`,
    `mgh_name`, `mgh_host`, `mgn_host`, `mgn_site_url`, `mgn_web_root`,
    `mgn_container_name`); domains (`cvp_domain`, `dre_hostname`); IP addresses
    (`ajr_source_ip`, the join-request IP, `cvp_instance_ip`); fingerprints
    (`ajr_fingerprint`, the escrow `fingerprint()`, the join-request fingerprint);
    the agent's public key (`mgn_agent_public_key`); and versions, heartbeats
    and status.
- **Q11. Job output.** Answered 2026-10-23: `job_detail` and `jobs` at 7, for
  every job. Output is redacted twice: the node redacts before it sends, and
  the page runs `SmSecretRedactor` over the transcript, log text, samples,
  tables and the raw result (job_detail 1.3, 2026-10-23 trace); file_head
  refuses files that are secrets and returns a credential line as its key
  only. What is left is personal data, which 7 reads.
- **Q12. The sent-mail archive** (`admin_email`, `admin_emails`,
  `admin_email_view`). Answered 2026-10-23: personal at 6. It holds mail an
  admin wrote (lists, newsletters, the events digest, a message from a user's
  page) and its recipients (`erc_email`, `erc_name`, status); no system mail,
  so no tokens.
- **Q13. User-to-user conversations** (`admin_conversation`,
  `admin_conversations`, `admin_message`). Answered 2026-10-23: 8, as D9 does
  for AI chats. Conversation and Message stay personal, with reading another
  person's raised to 8; soft delete stays at 8. A sealed conversation's bodies
  are ciphertext to staff whatever the level.
- **Q14. Names for levels 6, 7 and 9.** Answered 2026-10-23: Editor, Manager,
  Senior Admin (3.1).
- **Q15. Who may open another person's AI chats?** Answered 2026-10-21: admins
  only (D9). AiConversation is config; each person reaches their own chats
  through the owner rule.

## 7. Bugs found by the research

Each is tracked in memory `project_todo_admin_access_research_bugs`. They need
fixing whatever happens to this spec. Status as of 2026-10-24: every one is fixed
except B1 (WP1's flip), whose fix is that package; B15 and B23 were fixed by WP3
and WP2 on 2026-10-25.

| ID | Bug | Where | Status |
|---|---|---|---|
| B1 | Admin actions run on a GET, so another site can trigger them (SameSite=Lax sends the cookie on top-level navigation) | ~80 core pages, most plugin logic; DnsPublishBox::handle | WP1: guard logs, links are buttons; refusal is the flip |
| B2 | Server Manager publish page deletes a release archive and row on a GET | plugins/server_manager/includes/publish_upgrade.php:268, :1584 | fixed (WP1) |
| B3 | API action and form floors use the stored `usr_permission`, not the session's (login-as, IP-change zeroing); session simulation copies the stored value into the logic's session too | ApiLogicEndpoint.php; SessionControl::set_api_user() | fixed 10-24: `ApiLogicEndpoint::actingPermission()` (session's level for the browser credential, stored for a key) feeds both the floor and `set_api_user()` |
| B4 | Undelete on a message calls `soft_delete()` | adm/admin_message.php | fixed before 10-24 |
| B5 | Root-request panel polls `/api/v1/root_request_status` (missing `/action/`) | includes/AdminPage.php | fixed before 10-24 (AdminPage 1.4) |
| B6 | Opening the IMAP delete page deletes the feed when nothing depends on it | plugins/mailbox/logic/admin_mailbox_imap_delete_logic.php | fixed 10-24: only the confirm form's POST deletes; with nothing mirrored the page asks yes/no |
| B7 | Order delete treats any input as confirmation; a GET with `confirm=1` permanently deletes an order | plugins/store/admin/logic/admin_order_delete_logic.php | fixed (WP1) |
| B8 | Product permanent delete runs after `check_permission(5)`; the 10 check only hides the link | plugins/store/admin/logic/admin_product_logic.php | fixed 10-24: the handler requires 10; every action is POST-only |
| B9 | Opening "Edit Question" saves it | adm/logic/admin_question_edit_logic.php | fixed (WP1) |
| B10 | `admin_mailbox_message` shows any unsealed message by id at permission 5 with no mailbox grant check | plugins/mailbox/logic/admin_mailbox_message_logic.php | fixed 10-24: `MailboxViewer::canReadMessage()`, as the reader; delete is POST-only |
| B11 | Store payment settings print part of the PayPal secret and Stripe endpoint secret | plugins/store/admin/admin_settings_payments.php | fixed 10-24: lengths only; the token and Basic-auth fragments are gone (the fields were already `secret: true`) |
| B12 | File upload widget posts to `/admin/admin_file_upload_process` without a CSRF header | FormWriterV2Base.php | not a bug: no CSRF in admin (owner, settled) |
| B13 | Store order page and joinery_ai sweep write on page view without `server_initiated_write` | StripeHelper::update_order_refund_amount_from_stripe(); ChatAsync::sweepMessage | fixed 10-24: both marked, listed in core_api_mechanical_test |
| B14 | User permanent-delete confirmation unreachable; admin_softdelete never deletes; admin_page form nested in `#layoutForm`; items admin links to missing `/admin/admin_item*` | various | fixed 10-24: permanent delete reachable (WP1); admin_softdelete removed (nothing linked to it); admin_page's order inputs join `#layoutForm` by its id so each Remove is its own form (Remove and Save Order both removed the last component); items links point at `/plugins/items/admin/`, and the item pages no longer require a missing core file |
| B15 | Menu permission and page check disagree in 33 places | admin_menus.json, plugin.json vs pages | fixed 10-25 (WP3): a menu entry shows to whoever may open its page |
| B16 | The single API key page and the key create and edit pages, and the webhook pages, are gated at 8, but they issue or show secrets | adm/logic/admin_api_key_logic.php, admin_api_key_edit_logic.php, admin_webhook_logic.php, admin_webhook_edit_logic.php | fixed 10-24: 10 (8.4); the webhook list stays 8; API Keys menu entry 10 |
| B17 | Subscription tier edit and tier edit write prices at 5 | adm/logic/admin_subscription_tier_edit_logic.php, adm/admin_tier_edit.php | fixed 10-24: open at 5, save at 7 |
| B18 | Store orders list is gated at 5 and renders buyer addresses | plugins/store/admin/admin_orders.php | fixed 10-24: 6 |
| B19 | Mailbox account list is gated at 5 (config) | plugins/mailbox/logic/admin_mailbox_accounts_logic.php | fixed 10-24: 8 |
| B20 | Settings pages take secret fields at 8; keys need 10 | SettingsWriter, SettingsFieldRenderer | fixed 10-24: a credential is drawn locked below 10 and `SettingsWriter` refuses its change, naming it |
| B21 | Sandbox page accepts POST with only the router gate at 5 | adm/logic/admin_sandbox_page_logic.php | fixed before 10-24 (`SandboxPluginManager::ADMIN_PERMISSION`, 8) |
| B22 | Password edit has no guard on the target user's level: an 8 resets a 10's password; users edit lets an 8 change a 10's email and allowed IPs | data/users_class.php authenticate_write | fixed 10-24: the target rule (3.8) in `User::authenticate_write()`, asked by password edit, users edit (incl. 2FA reset), activate, soft delete and undelete |
| B23 | Any level 5 reads every row of every model through the REST API and the AI chat | SystemBase is_owner_or_admin(); data/users_class.php; api/apiv1.php; ChatTurnContext.php | fixed 10-25 (WP2): the REST record surface and the AI chat read at each model's read level |
| B24 | `User.usr_totp_secret` holds the TOTP seed in plaintext | data/users_class.php | fixed 10-24: sealed (`usr_users.usr_totp_secret`); the reconciler sealed the stored ones |
| B25 | `User.usr_second_factor_hmac_key` holds a second-factor HMAC key in plaintext | data/users_class.php | fixed 10-24: sealed (`usr_users.usr_second_factor_hmac_key`, ephemeral) |
| B26 | `ManagedNode.mgn_api_secret_key` stores the node's API secret in plaintext | plugins/server_manager | fixed 10-24: sealed (`ManagedNode::set_api_secret_key()` / `api_secret_key()`) |
| B27 | `SdDeviceBackup.sbk_deactivation_pin` is a device deactivation PIN in plaintext, shown back on the devices page | plugins/dns_filtering | verified 10-24: the owner is meant to read it (they type it on the device), and neither device model is on the AI or REST surface; the PIN came from `rand()`, now `random_int()` |
| B28 | `OrderItem.odi_play_purchase_token` stores a Google Play purchase token in plaintext | plugins/store | not a bug: it is not a credential (using it needs the store's Google service account), it is looked up by value when Google notifies, and `*_token` already keeps it off the REST and AI surfaces |
| B29 | `User.usr_authhash`, the contact-preferences link token, was a digest of the user id and a salt in the code: anyone could work out any user's link | data/users_class.php; logic/contact_preferences_logic.php | fixed 10-24: random 128-bit token (`User::new_authhash()`), compared with hash_equals; migration 222 replaced every stored one |
| B30 | A deleted user's Undelete went to admin_users_undelete, which cleared the disabled flags and never the delete time | adm/admin_users_undelete.php | fixed 10-24: Undelete is a POST action on admin_user at 8; the page is removed |
| B31 | admin_page and admin_post read the page's id from the URL only, so their Soft Delete and Undelete buttons, which post it in the body, did nothing | adm/admin_page.php, adm/admin_post.php | fixed 10-24: the id is read from the request; every action is POST-only |
| B32 | Found checking B29 on getjoinery (0.8.535): every page whose logic refused the request (LogicResult::error with nothing else to show) answered HTTP 500 and wrote an err_general_errors row, so any visitor could fill the error log with stale links, and uptime checks read refusals as faults. Root cause: process_logic() threw SystemDisplayableError, and the three error handlers each mapped everything but three exception types to 500 | includes/LibraryFunctions.php, includes/SystemBase.php, includes/ErrorClasses.php, includes/ErrorHandler.php | fixed 10-25: process_logic() throws LogicRefusal (NoLog); one status rule, ErrorStatus::of(): refusal 422 (as /api/v1), user-fixable 400, permission 403, fault 500; a NoLog exception writes no row and offers no report link |
| B33 | Found the same day: the release check could not see whether the 2FA secrets were sealed, because the reconciler sealed plaintext silently ('healed 0' does not count it) | includes/SecretReconciler.php | fixed 10-25: the pass counts what it seals; the summary reads 'sealed N plaintext' |
| B34 | Found by installdefaults in 0.8.535: the action face's executeAction() used $acting_permission without receiving it (B3's fix), so set_api_user() got null and simulated the stored level. Keys were unaffected (their acting level is the stored one), but a browser session whose session level was below its stored level ran actions at the stored level | includes/ApiLogicEndpoint.php | fixed 10-25 (1.8.5): executeAction() takes the acting level |
| B35 | Why B34 passed every gate: a suite that calls the site over HTTP never sees a PHP warning the request raised; dev's log had about 130 per gate run | tests/run.php, tests/lib/php_warnings.php (new) | fixed 10-25: the runner reads what the site's error log gained during the run, and a PHP warning or fatal in a file under the site root fails the run, once per place |
| B36 | Found by installdefaults in dev's log (the warning B35 now catches): logout() began with get_user_id(), which ran the session checks; when the app-key check had called logout(), the account-standing check (not yet run) called logout() again inside it, which ended the session, and the outer call's session_destroy() then found none. Both checks fail together after a password change (it bumps the session generation and revokes the app keys): app_platform's password-change step. The three warnings in dev's log came from that step (each client requested only /profile, /profile/profile and /login) | includes/SessionControl.php | fixed 10-25 (1.18): logout() marks both checks done before its own identity calls, and destroys only an active session (a page-probe request has none); app_platform checks the ended session's old cookie is anonymous |
| B37 | Found building WP0: the API floor cast `min_user_permission` to int, so the first `class:verb` level declared would have read as 0 and opened its action to everyone | includes/ApiAuth.php, adm/logic/admin_api_key_edit_logic.php | fixed 10-25 (WP0): both read the level through `Access::allows()`; a malformed one throws |
| B38 | Found doing Q4 in WP0: the user edit save stored any posted `usr_permission` (99, text) | adm/logic/admin_users_edit_logic.php | fixed 10-25: only a level permissions.json names; the dropdown lists them |
| B39 | Found by WP2's burn-down: the REST record surface (CRUD, drive upload, auth, app, management) ran with the anonymous session, so anything below it that asked the session (the model write check, a model's own rule) saw nobody | api/apiv1.php | fixed 10-25 (2.31): every route family past the action dispatch runs as its caller (`set_api_user()`) |
| B43 | Found by WP5's admin_page_levels (every admin page asked with a GET): admin_file_upload_process imported every loose file in uploads/ as a new File row on a GET (WP1's guard logged it, in log mode, and it went ahead: 935 rows on dev, fil_files 132429-133463, owner user 3, 06:42-06:44 UTC 10-25); admin_errors_delete ran its DELETE on a GET | adm/logic/admin_file_upload_process_logic.php, adm/admin_errors_delete.php | fixed 10-25: both are POST-only. **Owner:** the 935 dev rows are test debris, not yet removed (a DB write) |
| B44 | Found by a sweep for leftover check_permission(5) floors after WP3: AbTestVersionsPanel ran its actions for anyone at 5, and an admin_page or admin_component_edit reader at 5 could crown a variant into the page (content write is 6; the model writes only log); DnsPublishBox::handle's own floor was 5 for a config write (every caller gates at 8 or 10 first, so no hole) | data/ab_tests_class.php, includes/dns/DnsPublishBox.php | fixed 10-25: the panel's actions need can_write on the entity; the box's floor is config:write |
| B45 | Found classifying the page-top checks: the owner rule read the row's owner after set(), so a 5 who set a booking type's owner column to themselves passed BookingType's money write (7) as its owner | includes/SystemBase.php access_owner_is(), data/entries_class.php, data/schedules_class.php | fixed 10-25: a saved row's owner is the stored one (access_stored()) |
| B46 | Model helpers that write their row with their own SQL skipped the model check and the GET guard: GroupMember, EventRegistrant and WaitingList remove(), Board::undelete(), and File and User permanent_delete()'s cleanup before the parent's check (photo links, mailing-list unsubscribes) | data/group_members_class.php, plugins/event_manager/data/{event_registrants,waiting_lists}_class.php, plugins/boards/data/boards_class.php, data/files_class.php, data/users_class.php | fixed 10-25: each asks guard_raw_write() first |
| B47 | admin_page_edit built an empty link from $event, which the page never defines: saving a page with no link fatalled | adm/admin_page_edit.php | fixed 10-25 |
| B48 | admin_video_edit named its link field pag_link and set the link after the save, so a video's link was never stored | adm/admin_video_edit.php | fixed 10-25 |
| B49 | create_install_sql read each admin menu entry's permission, which WP3 removed from admin_menus.json (an undefined key per entry) | utils/create_install_sql.php | fixed 10-25: the menu rows carry no level |
| B50 | The coupon edit's clear step filtered MultiCouponCodeProduct by 'coupon_id', an option it does not implement, so it threw on every coupon save that reached it (since 2052690b8) | plugins/store/admin/logic/admin_coupon_code_edit_logic.php | fixed 10-25: 'coupon_code_id' |
| B51 | Three admin pages called admin_header() with a bare number, a fatal on PHP 8 when the page reached its header | adm/admin_email_view.php, adm/admin_log_event.php, adm/admin_file_upload_process.php | fixed 10-25: real options |
| B52 | Removing a recipient group from an email never checked the group was that email's: any group id posted was deleted | adm/logic/admin_email_logic.php, adm/admin_email_recipients_modify.php | fixed 10-25: the group must be the email's, and the email's write rule decides |
| B53 | /lists: a visitor who typed someone's email unsubscribed that person from every other list, private ones included, and a member's save took them off private lists they cannot see; /list let a visitor unsubscribe any address and made an account doing it | logic/lists_logic.php, logic/list_logic.php | fixed 10-25: only the public lists shown are touched; a visitor's post only adds |
| B54 | Contact preferences: a member could post a private list's id and subscribe themselves, and a save unsubscribed them from lists the form never showed | logic/contact_preferences_logic.php, data/users_class.php | fixed 10-25: add_user_to_mailing_lists() takes the offered lists as its scope |
| B55 | Blog comments: the server never checked comments_active or comments_unregistered_users; only the view hid the form | data/comments_class.php | fixed 10-25 |
| B56 | A failed send was never queued for retry when the message had no display name (equ_from_name required) | includes/EmailSender.php | fixed 10-25: the address stands in |
| B57 | Checkout stored a coupon-discounted Stripe price as the plan's price (get_or_create_price overwrote prv_stripe_price_id), so later tier changes billed at the coupon amount; prices matched on amount only, first 10, any interval | plugins/store/includes/StripeHelper.php | fixed 10-25: the canonical price comes only from prv_version_price, matched in cents, paged, by interval and trial; any other amount gets its own price and never writes the version |
| B58 | A free cart completed on a GET to /cart_charge (any link could finish it), and a non-gateway GET made an unpaid order | plugins/store/logic/cart_charge_logic.php, checkout_logic.php, views/checkout.php | fixed 10-25: the billing form posts; a non-gateway GET redirects and writes nothing |
| B59 | /cart_charge?subscription=1 with no PayPal subscription id marked the order paid unverified; a PayPal one-time payment's amount was never checked and its id could be replayed onto another order | plugins/store/logic/cart_charge_logic.php | fixed 10-25: a verified subscription id on a recurring cart; amount and currency must match the cart; an id already on another order is refused |
| B60 | A guest could check out as an existing account by its email: the card, customer id and order were written to that account | plugins/store/logic/cart_charge_logic.php | fixed 10-25: refused before any write, except a gateway return |
| B61 | A first App Store or Play claim never compared the receipt's account token with the caller: a member could claim another member's purchase | plugins/store/includes/MobileBilling.php | fixed 10-25: the token must name the caller; a receipt without one is refused (the owner decided 10-25: keep the refusal) |
| B62 | Public booking had no spam gate, and a visitor typing a member's email filed the booking and answers under that account and got its manage token | plugins/bookings/logic/book_logic.php, views/book.php | fixed 10-25: register's gates and a rate limit; an existing account's email needs that account signed in |
| B63 | Newsletter signup subscribed an existing account by its typed email | logic/list_logic.php, logic/lists_logic.php | fixed 10-25: a visitor is asked to sign in for that address (lands with misc4's optional_username commit) |
| B64 | isSecure() believed X-Forwarded-Proto/-Ssl/-Port from any client, which flipped protocol_observed_scheme and the session cookie's Secure flag | includes/LibraryFunctions.php, includes/SessionControl.php, api/apiv1.php, includes/UploadHandler.php, maintenance_scripts/install_tools/host_housekeeping.sh | fixed 10-25: believed only from a proxy Apache trusts (JOINERY_FORWARDER_TRUSTED), loopback or a Cloudflare edge |
| B65 | Another device that knew a member's push endpoint URL could take it over | includes/PushSender.php | fixed 10-25: another account's endpoint moves only with the same device cookie |
| B42 | Found by WP5's admin_page_levels: an admin page opened without its row id answered 500 (39 pages), admin_surveys and admin_stripe_invoices always did, and 45 pages logged PHP warnings | includes/ErrorClasses.php, includes/LibraryFunctions.php, adm/admin_surveys.php, plugins/store/admin/admin_stripe_invoices.php and others | fixed 10-25: a no-key load answers 404, a bad request variable 400; the two lists fixed; the warnings fixed at each page |
| B41 | Found doing WP4: root_request_status's descriptor declared `'min_permission' => 8`, which nothing reads; its floor was 0 and only the logic's own check held | logic/root_request_status_logic.php | fixed 10-25: `config:read` in `auth`; the declaration test refuses the misplaced key |
| B40 | Found doing WP2: `SpaceAccess::moderatorLevel()` read `$post_model::$write_permission` directly; once every model has that property (null unless declared), a post model with no exception would have made everyone a moderator | includes/SpaceAccess.php | fixed 10-25 (1.2.0): it reads `access_level('write')` |

## 8. Level scheme and page inventory

Proposed by the owner on 2026-10-20 to replace the current 0 / 5 / 8 / 10
levels. Every level includes the one below it, so each page and action needs
one number. The inventory in 8.4 applies this scheme to every admin page as
the research recommendation. Nothing here is built.

### 8.1 The levels

| Level | Reads | Writes | Deletes |
|---|---|---|---|
| 5 | Content and money | Nothing | Nothing |
| 6 | Also personal data | Content | Nothing |
| 7 | As 6 | Also money | Soft delete of content and money |
| 8 | Also config and restricted, secrets masked | Also personal and config | Soft delete of personal and config |
| 9 | As 8 | Also restricted: passwords, clearing logs | Permanent delete of everything but keys; soft delete of restricted |
| 10 | Everything, keys included | Everything | Everything, and the destructive system actions (Q8) |

Each row is the class table of 3.1 read by level. The two never disagree
because 8.1 is derived from `permissions.json`, not written by hand.

### 8.2 Data classes

See 3.1. A page's class is the highest class it shows; a column stricter than
its model is masked rather than raising the page (3.3). The nine letters of
the first draft map to the six classes as 3.1 says, and the inventory below
uses the names.

### 8.3 Does the scheme work?

Yes. The ladder is one-way, so one class per page works with the router check
in 3.4 and one level per action works with 3.5. It gives the owner the split
they asked for: 5 views, 6 edits content, 7 can archive and price, 8 runs the
site, 9 can erase and set passwords, 10 holds the keys.

Decided 2026-10-20:

- **Q4.** Levels 6, 7 and 9 are real levels. The admin UI reads them from
  `permissions.json` (`adm/admin_users_edit.php:70` offers 0, 5, 8, 10 today).
- **Q5. Keys.** Reading and changing a key is level 10. A key's metadata is
  its other, lower-class columns.
- **Q6. Money.** 5 reads, 7 writes and soft-deletes. Store product and coupon
  edits drop to 7. Today they are 8 or 10, so the inventory loosens them.
- **Q7. Permanent erasure of a user.** Level 9, the personal class rule. Today
  it is 10.
- **Q8. Destructive actions.** The owner wants anything destructive at 10.
  That collides with Q7: erasing a person is destructive, and it is 9.
  Decision: destructive system actions are 10. That means uninstalling a
  plugin, removing a node, deleting a release or backup, restoring, resetting,
  and rotating keys. Deleting records stays with the class rule: soft at 7 or
  8, permanent at 9. A 9 cannot wipe a node or a release. An 8 or 9 who must
  remove a node is promoted to 10.
- **Q9. Server Manager dashboard at 7.** The dashboard shows status and
  counts at 7. Node detail, with addresses, stays at 8. Key-changing and
  destructive actions are 10. Other actions, such as refresh, probe, run job
  and publish, are 8. A 7 reads what a 6 reads, so the dashboard must not
  show config. Addresses and host details go on node detail (Q10 says what
  the page shows today).

Changes that follow from the scheme:

1. **Level 5 stops being an editor.** Today 5 can write on 36 pages in the
   inventory: pages, posts, questions, bookings, forum categories, and others.
   Under the scheme they need 6 or higher.
2. **Reads get wider on pages, and narrower everywhere else.** 120 pages can
   be read one or more levels below their current gate. Most content and
   personal-data pages move from 8 to 6, and analytics from 10 to 5. Today a 5
   already reads all of it through the REST API and the AI chat (B23), so the
   model read level of 3.2 is what sets the real floor, and the page widening
   exposes nothing new.
3. **Mixed pages are columns, not splits.** A page that shows a customer's
   address and a secret takes the address's class; the secret column masks
   itself (3.3). D3 is superseded by D5 and D6.
4. **Server Manager (WP6)** is config throughout, with the dashboard at 7 and
   keys columns and destructive actions at 10.
5. **Settings pages** keep one form. Their `secret: true` fields are keys and
   render masked below 10 (3.3, B20).
6. **Inversions the first inventory carried go away** once levels belong to
   models: a user is soft-deleted at 8 and undeleted at 10
   (`admin_users_undelete`), an order is erased at 10 (`admin_order_delete`)
   while a person is erased at 9. Undelete takes the soft-delete level; order
   erasure is personal, 9. Any page in 8.4 whose write column sits above its
   model's class is a candidate exception for WP2 to confirm or drop, not a
   rule.

### 8.4 Pages

Printed by `maintenance_scripts/dev_tools/permissions_inventory.php --table=pages`.
Every value is derived from the code, except Class and Note, which come from the
seed (`maintenance_scripts/dev_tools/permissions_seed.json`).

- **Class** is the page's class (3.1), the highest class of what it shows.
- **Now** is the gate the code has today: the highest `check_permission` level
  in the page and the files it includes (two levels deep), and in its
  conventional logic file. `5 (router)` means only the `/admin/*` route check
  (`serve.php:186`) applies. `open` means nothing applies.
- **Read** is the class's read level, derived from the class table. It is never
  typed.
- **Level override** is set only for redirect stubs.
- **Models written** lists the models the page or its files save, soft-delete,
  undelete, permanently delete or `updateColumns()`. Those models' levels come
  from 8.5, not from the page. `-` means the page writes nothing.

| Page | Class | Now | Read | Level override | Models written | Note |
|---|---|---|---|---|---|---|
| `/admin/admin_ab_tests` | content | 5 | 5 |  | - |  |
| `/admin/admin_address_edit` | personal | 8 | 6 |  | Address |  |
| `/admin/admin_admin_menu` | config | 10 | 8 |  | AdminMenu |  |
| `/admin/admin_admin_menu_edit` | config | 10 | 8 |  | AdminMenu |  |
| `/admin/admin_agent_file_compare` | config | 10 | 8 |  | - | AI instructions |
| `/admin/admin_agent_file_edit` | config | 10 | 8 |  | AgentFile | AI instructions |
| `/admin/admin_agent_files` | config | 10 | 8 |  | - | AI instructions |
| `/admin/admin_analytics_activitybydate` | content | 10 | 5 |  | - | aggregate only |
| `/admin/admin_analytics_attribution` | content | 5 | 5 |  | - |  |
| `/admin/admin_analytics_email_stats` | content | 5 | 5 |  | - |  |
| `/admin/admin_analytics_funnels` | content | 5 | 5 |  | - |  |
| `/admin/admin_analytics_stats` | content | 5 | 5 |  | - |  |
| `/admin/admin_analytics_users` | personal | 5/10 | 6 |  | - | per-user rows |
| `/admin/admin_apache_errors` | restricted | 9 | 8 |  | - | server log |
| `/admin/admin_api_key` | keys | 8 | 10 |  | ApiKey |  |
| `/admin/admin_api_key_edit` | keys | 8 | 10 |  | ApiKey |  |
| `/admin/admin_api_keys` | config | 10 | 8 |  | - | shows public key ids only; revoke is the soft delete; the secret column is keys (3.3) |
| `/admin/admin_backups` | keys | 10 | 10 |  | BackupTarget, BackupHistory, Setting | backup keys |
| `/admin/admin_cloud_storage` | keys | 10 | 10 |  | BackupTarget | bucket credentials |
| `/admin/admin_comment` | personal | 8 | 6 |  | Comment | author identity |
| `/admin/admin_comment_edit` | personal | 5 | 6 |  | Comment |  |
| `/admin/admin_comments` | personal | 8 | 6 |  | - |  |
| `/admin/admin_component_edit` | content | 5 | 5 |  | PageContent, Page | custom HTML runs on every page: write 8 (3.1) |
| `/admin/admin_component_type` | config | 10 | 8 |  | - |  |
| `/admin/admin_component_types` | config | 10 | 8 |  | - |  |
| `/admin/admin_components` | content | 5 | 5 |  | PageContent | custom HTML: write 8 (3.1) |
| `/admin/admin_contact_type` | content | 8 | 5 |  | ContactType |  |
| `/admin/admin_contact_type_edit` | content | 8 | 5 |  | ContactType |  |
| `/admin/admin_contact_types` | content | 8 | 5 |  | - |  |
| `/admin/admin_content_reports` | personal | 5 | 6 |  | - | reporter identity |
| `/admin/admin_conversation` | personal | 8 | 8 |  | Message, Conversation | private chats: 8 (Q13) |
| `/admin/admin_conversations` | personal | 8 | 8 |  | - | private chats: 8 (Q13) |
| `/admin/admin_debug_email_logs` | restricted | 8 | 8 |  | - |  |
| `/admin/admin_email` | personal | 8 | 6 |  | Email, EmailRecipientGroup | sent-mail archive: personal (Q12) |
| `/admin/admin_email_edit` | personal | 8 | 6 |  | Email |  |
| `/admin/admin_email_preview` | content | 5 | 5 |  | - |  |
| `/admin/admin_email_recipients_modify` | personal | 8 | 6 |  | EmailRecipientGroup |  |
| `/admin/admin_email_template` | content | 5 | 5 |  | - |  |
| `/admin/admin_email_template_edit` | content | 5 | 5 |  | EmailTemplate | an edited template reaches every user |
| `/admin/admin_email_template_permanent_delete` | content | 10 | 5 |  | EmailTemplate |  |
| `/admin/admin_email_template_preview` | content | 10 | 5 |  | - |  |
| `/admin/admin_email_templates` | content | 8 | 5 |  | - |  |
| `/admin/admin_email_view` | personal | 8 | 6 |  | - | sent-mail archive: personal (Q12) |
| `/admin/admin_emails` | personal | 8 | 6 |  | - | sent-mail archive: personal (Q12) |
| `/admin/admin_emails_queue` | personal | 8 | 6 |  | - |  |
| `/admin/admin_emails_send` | personal | 8 | 6 |  | Email, EmailRecipient | sends mail to users |
| `/admin/admin_errors` | restricted | 9 | 8 |  | - |  |
| `/admin/admin_errors_delete` | restricted | 9 | 8 |  | GeneralError |  |
| `/admin/admin_file` | personal | 8 | 6 |  | File | private uploads |
| `/admin/admin_file_delete` | personal | 8 | 6 |  | File |  |
| `/admin/admin_file_edit` | personal | 8 | 6 |  | File |  |
| `/admin/admin_file_upload` | personal | 5 | 6 |  | - |  |
| `/admin/admin_file_upload_process` | personal | 5 | 6 |  | - |  |
| `/admin/admin_files` | personal | 5 | 6 |  | - |  |
| `/admin/admin_form_error` | restricted | 9 | 8 |  | - |  |
| `/admin/admin_group_edit` | content | 10 | 5 |  | - | tags and tier membership; Group is public-read in the API |
| `/admin/admin_group_members` | content | 5 | 5 |  | - | tier membership grants paid access: write 8 |
| `/admin/admin_group_permanent_delete` | content | 8 | 5 |  | Group |  |
| `/admin/admin_groups` | content | 5 | 5 |  | - |  |
| `/admin/admin_help` | content | 5 | 5 |  | - |  |
| `/admin/admin_help_edit` | content | 10 | 5 |  | - |  |
| `/admin/admin_log_event` | restricted | 9 | 8 |  | EventLog |  |
| `/admin/admin_mailing_list` | personal | 8 | 6 |  | MailingList | subscriber addresses |
| `/admin/admin_mailing_list_edit` | personal | 8 | 6 |  | MailingList |  |
| `/admin/admin_mailing_lists` | personal | 5 | 6 |  | - |  |
| `/admin/admin_management_node` | keys | 10 | 10 |  | Setting | node credentials |
| `/admin/admin_marketplace` | config | 10 | 8 |  | - | installs code (Q8) |
| `/admin/admin_message` | personal | 8 | 8 |  | Message | private chats: 8 (Q13) |
| `/admin/admin_notification_preferences` | content | 5 | 5 |  | - | own row: the owner rule applies |
| `/admin/admin_oauth_providers` | keys | 10 | 10 |  | - | client secrets |
| `/admin/admin_page` | content | 5 | 5 |  | Page |  |
| `/admin/admin_page_edit` | content | 5 | 5 |  | Page |  |
| `/admin/admin_page_permanent_delete` | content | 10 | 5 |  | PageContent, Page |  |
| `/admin/admin_pages` | content | 5 | 5 |  | - |  |
| `/admin/admin_passkey_lab` | keys | 10 | 10 |  | - | passkey secrets |
| `/admin/admin_phone_edit` | personal | 8 | 6 |  | PhoneNumber |  |
| `/admin/admin_phone_verify` | personal | 9 | 6 |  | - |  |
| `/admin/admin_plugins` | config | 10 | 8 |  | Plugin | installs and removes code |
| `/admin/admin_post` | content | 5 | 5 |  | Post |  |
| `/admin/admin_post_edit` | content | 5 | 5 |  | Post |  |
| `/admin/admin_post_permanent_delete` | content | 10 | 5 |  | Post |  |
| `/admin/admin_posts` | content | 5 | 5 |  | - |  |
| `/admin/admin_problem_reports` | personal | 9 | 6 |  | - | user reports |
| `/admin/admin_public_menu` | config | 10 | 8 |  | PublicMenu |  |
| `/admin/admin_public_menu_edit` | config | 10 | 8 |  | PublicMenu |  |
| `/admin/admin_question` | content | 5 | 5 |  | Question |  |
| `/admin/admin_question_edit` | content | 5 | 5 |  | QuestionOption, Question |  |
| `/admin/admin_questions` | content | 8 | 5 |  | - |  |
| `/admin/admin_recovery_readiness` | keys | 10 | 10 |  | - | shows recovery material |
| `/admin/admin_require_second_factor` | config | 10 | 8 |  | Setting |  |
| `/admin/admin_sandbox_approve` | config | 10 | 8 |  | - |  |
| `/admin/admin_sandbox_log` | restricted | 5 (router) | 8 |  | - | was router only |
| `/admin/admin_sandbox_page` | config | 5 (router) | 8 |  | Setting | was router only |
| `/admin/admin_scheduled_tasks` | config | 10 | 8 |  | ScheduledTask | runs code on a schedule |
| `/admin/admin_sealed_secrets` | keys | 10 | 10 |  | - |  |
| `/admin/admin_seo_page_edit` | content | 10 | 5 |  | SeoPageMetadata |  |
| `/admin/admin_seo_pages` | content | 10 | 5 |  | SeoPageMetadata |  |
| `/admin/admin_settings` | config | 8 | 8 |  | Setting | key fields need 10: see notes |
| `/admin/admin_settings_email` | config | 8 | 8 |  | Setting | secret settings are keys columns, masked below 10 (3.3) |
| `/admin/admin_settings_plugins` | config | 8 | 8 |  | Setting | plugin keys need 10: see notes |
| `/admin/admin_spec_view` | config | 10 | 8 |  | - |  |
| `/admin/admin_static_cache` | config | 9 | 8 |  | - |  |
| `/admin/admin_subscription_tier_edit` | money | 5 | 5 |  | SubscriptionTier | prices and tiers |
| `/admin/admin_subscription_tiers` | money | 5/7 | 5 |  | - |  Logic gate is 7, not 5 (adm/logic/admin_subscription_tiers_logic.php:11) |
| `/admin/admin_survey` | content | 5 | 5 |  | SurveyQuestion, Survey |  |
| `/admin/admin_survey_answers` | personal | 5 | 6 |  | - |  |
| `/admin/admin_survey_edit` | content | 10 | 5 |  | Survey |  |
| `/admin/admin_survey_user_answers` | personal | 5 | 6 |  | - |  |
| `/admin/admin_survey_users` | personal | 5 | 6 |  | - |  |
| `/admin/admin_surveys` | content | 7 | 5 |  | - |  |
| `/admin/admin_test_database` | config | 10 | 8 |  | - |  |
| `/admin/admin_themes` | config | 10 | 8 |  | Theme | installs and activates code |
| `/admin/admin_tier_edit` | money | 5 | 5 |  | SubscriptionTier | prices and tiers (Q6) |
| `/admin/admin_updates` | config | 10 | 8 |  | - | applies upgrades (Q8) |
| `/admin/admin_url` | config | 8 | 8 |  | Url | redirects can phish |
| `/admin/admin_url_edit` | config | 10 | 8 |  | Url |  |
| `/admin/admin_urls` | config | 8 | 8 |  | - |  |
| `/admin/admin_user` | personal | 5/8/9/10 | 6 |  | User | password column restricted, key columns keys; permanent delete is on admin_users_permanent_delete |
| `/admin/admin_user_add` | personal | 8 | 6 |  | - |  |
| `/admin/admin_user_login_as` | config | 10 | 8 |  | - | impersonation |
| `/admin/admin_users` | personal | 5 | 6 |  | - | list shows emails |
| `/admin/admin_users_edit` | personal | 8 | 6 |  | User | password fields need 9 |
| `/admin/admin_users_message` | personal | 8 | 6 |  | Email |  |
| `/admin/admin_users_password_edit` | restricted | 8 | 8 |  | User | write 9 |
| `/admin/admin_users_permanent_delete` | personal | 10 | 6 |  | User | irreversible erasure (Q7) |
| `/admin/admin_utilities` | config | 10 | 8 |  | - | maintenance commands |
| `/admin/admin_video` | content | 8 | 5 |  | Video |  |
| `/admin/admin_video_edit` | content | 8 | 5 |  | Video |  |
| `/admin/admin_videos` | content | 8 | 5 |  | - |  |
| `/admin/admin_webhook` | keys | 8 | 10 |  | SignalReceiver | signing secret |
| `/admin/admin_webhook_edit` | keys | 8 | 10 |  | SignalReceiver | signing secret |
| `/admin/admin_webhooks` | config | 8 | 8 |  | - |  |
| `/plugins/boards/admin/admin_boards` | content | 10 | 5 |  | Board |  |
| `/plugins/bookings/admin/admin_booking` | personal | 5 | 6 |  | Booking | attendee details |
| `/plugins/bookings/admin/admin_booking_type_edit` | content | 5 | 5 |  | BookingType |  |
| `/plugins/bookings/admin/admin_booking_types` | content | 5 | 5 |  | - |  |
| `/plugins/bookings/admin/admin_bookings` | personal | 5 | 6 |  | - | attendee details |
| `/plugins/bug_reports/admin/admin_bug_report` | personal | 9 | 6 |  | ReceivedBugReport | may hold user data |
| `/plugins/bug_reports/admin/admin_bug_reports` | personal | 9 | 6 |  | ReceivedBugReport | may hold user data |
| `/plugins/event_manager/admin/admin_event` | content | 8 | 5 |  | Event |  |
| `/plugins/event_manager/admin/admin_event_bundle` | content | 5 | 5 |  | - |  |
| `/plugins/event_manager/admin/admin_event_bundle_edit` | content | 10 | 5 |  | - |  |
| `/plugins/event_manager/admin/admin_event_bundles` | content | 5 | 5 |  | - |  |
| `/plugins/event_manager/admin/admin_event_edit` | content | 8 | 5 |  | Event |  |
| `/plugins/event_manager/admin/admin_event_emails` | personal | 8 | 6 |  | - |  |
| `/plugins/event_manager/admin/admin_event_session_edit` | content | 8 | 5 |  | EventSession |  |
| `/plugins/event_manager/admin/admin_event_type_edit` | content | 7 | 5 |  | EventType |  |
| `/plugins/event_manager/admin/admin_event_types` | content | 7 | 5 |  | - |  |
| `/plugins/event_manager/admin/admin_events` | content | 8 | 5 |  | - |  |
| `/plugins/event_manager/admin/admin_location` | content | 8 | 5 |  | Location |  |
| `/plugins/event_manager/admin/admin_location_edit` | content | 5 | 5 |  | Location |  |
| `/plugins/event_manager/admin/admin_locations` | content | 7 | 5 |  | - |  |
| `/plugins/forum/admin/admin_forum_categories` | content | 5 | 5 |  | ForumCategory | gate is ForumPost model level 5 |
| `/plugins/forum/admin/admin_forum_category` | content | 5 | 5 |  | ForumCategory |  |
| `/plugins/forum/admin/admin_forum_held` | content | 5 | 5 |  | - | moderation queue |
| `/plugins/forum/admin/admin_forum_topics` | content | 5 | 5 |  | ForumTopic |  |
| `/plugins/items/admin/admin_item` | content | 5 | 5 |  | Item |  |
| `/plugins/items/admin/admin_item_edit` | content | 5 | 5 |  | Item |  |
| `/plugins/items/admin/admin_item_relation_types` | content | 7 | 5 |  | - |  |
| `/plugins/items/admin/admin_items` | content | 5 | 5 |  | - |  |
| `/plugins/mail_apps/admin/admin_mail_apps` | restricted | 9 | 8 |  | - | issues app passwords |
| `/plugins/mailbox/admin/admin_mailbox` | personal | 5/10 | 6 |  | InboundEmailAlias |  |
| `/plugins/mailbox/admin/admin_mailbox_accounts` | config | 5 | 8 |  | - | renders delivery mode and enabled only (B19, verified 10-20) |
| `/plugins/mailbox/admin/admin_mailbox_alias` | config | 5 | 8 |  | InboundEmailAlias |  |
| `/plugins/mailbox/admin/admin_mailbox_attachment` | personal | 5 | 6 |  | - |  |
| `/plugins/mailbox/admin/admin_mailbox_connect` | restricted | 10 | 8 |  | InboundImapAccount |  |
| `/plugins/mailbox/admin/admin_mailbox_domains` | config | 5/10 | 8 |  | InboundEmailDomain | DKIM keys (Q8) |
| `/plugins/mailbox/admin/admin_mailbox_filters` | personal | 5 | 6 |  | - | operator mount; gate is logic level 5 |
| `/plugins/mailbox/admin/admin_mailbox_fleet` | config | 10 | 8 |  | MailboxFleetShard |  |
| `/plugins/mailbox/admin/admin_mailbox_imap` | restricted | 10 | 8 |  | InboundImapAccount | feed credentials |
| `/plugins/mailbox/admin/admin_mailbox_imap_delete` | restricted | 10 | 8 |  | InboundImapAccount |  |
| `/plugins/mailbox/admin/admin_mailbox_imap_edit` | restricted | 10 | 8 |  | InboundImapAccount, InboundImapFolder, InboundEmailAlias |  |
| `/plugins/mailbox/admin/admin_mailbox_import` | personal | 5 | 6 |  | - |  |
| `/plugins/mailbox/admin/admin_mailbox_logs` | restricted | 5 | 8 |  | - |  |
| `/plugins/mailbox/admin/admin_mailbox_message` | personal | 5 | 6 |  | InboundEmailMessage | message content |
| `/plugins/mailbox/admin/admin_mailbox_reader` | personal | 5 | 6 |  | - | message content |
| `/plugins/mailbox/admin/admin_mailbox_reports` | personal | 5 | 6 |  | - |  |
| `/plugins/mailbox/admin/admin_mailbox_settings` | config | 5 | 8 |  | Setting |  |
| `/plugins/mailbox/admin/admin_mailbox_setup` | restricted | 5 | 8 |  | InboundEmailDomain, Setting |  |
| `/plugins/persona_browser/admin/admin_persona_senders` | personal | 5 | 6 |  | PersonaBlockedSender, PersonaAllowedSender |  |
| `/plugins/store/admin/admin_coupon_code` | money | 8 | 5 |  | CouponCode |  |
| `/plugins/store/admin/admin_coupon_code_edit` | money | 10 | 5 |  | CouponCode, CouponCodeProduct |  |
| `/plugins/store/admin/admin_coupon_codes` | money | 7 | 5 |  | - |  |
| `/plugins/store/admin/admin_order` | personal | 8 | 6 |  | - | payment details |
| `/plugins/store/admin/admin_order_delete` | personal | 10 | 6 |  | Order | B7: permanent; personal erasure is 9 (8.3 item 6) |
| `/plugins/store/admin/admin_order_edit` | personal | 8 | 6 |  | Order |  |
| `/plugins/store/admin/admin_order_item_edit` | personal | 8 | 6 |  | OrderItem |  |
| `/plugins/store/admin/admin_order_refund` | personal | 8 | 6 |  | Order, OrderItem | money moves |
| `/plugins/store/admin/admin_orders` | personal | 5 | 6 |  | - |  |
| `/plugins/store/admin/admin_ownership_edit` | personal | 5 | 6 |  | Ownership |  |
| `/plugins/store/admin/admin_ownerships` | personal | 5 | 6 |  | Ownership |  |
| `/plugins/store/admin/admin_product` | money | 5 | 5 |  | Product | permanent delete handler runs at 5 today (B8) |
| `/plugins/store/admin/admin_product_edit` | money | 8 | 5 |  | Product, ProductVersion | prices |
| `/plugins/store/admin/admin_product_group_edit` | money | 7 | 5 |  | ProductGroup |  |
| `/plugins/store/admin/admin_product_groups` | money | 7 | 5 |  | - |  |
| `/plugins/store/admin/admin_product_version_edit` | money | 8 | 5 |  | ProductVersion |  |
| `/plugins/store/admin/admin_products` | money | 8 | 5 |  | - |  |
| `/plugins/store/admin/admin_settings_payments` | config | 8 | 8 |  | Setting | gateway secrets are keys columns once flagged secret (B11, 3.3) |
| `/plugins/store/admin/admin_store_product_mapping_edit` | money | 8 | 5 |  | MobileStoreProduct |  |
| `/plugins/store/admin/admin_store_product_mappings` | money | 8 | 5 |  | - |  |
| `/plugins/store/admin/admin_stripe_invoices` | personal | 5 | 6 |  | - |  |
| `/plugins/store/admin/admin_stripe_orders` | personal | 5 | 6 |  | - |  |
| `/plugins/store/admin/admin_user_payment_methods` | personal | 8 | 6 |  | - | brand, last4 and expiry only |
| `/plugins/store/admin/admin_yearly_report_donations` | personal | 10 | 6 |  | - |  |
| `/plugins/support_desk/admin/admin_support_faq_edit` | content | 9 | 5 |  | SupportFaqEntry |  |
| `/plugins/support_desk/admin/admin_support_thread` | personal | 9 | 6 |  | - |  |
| `/plugins/support_desk/admin/admin_support_threads` | personal | 9 | 6 |  | - |  |
| `/admin/joinery_ai/chat` | personal | 5 (router) | 6 |  | - |  |
| `/admin/joinery_ai/chat_cancel` | personal | 5 (router) | 6 |  | AiConversationMessage | own chats only |
| `/admin/joinery_ai/chat_export` | personal | 5 (router) | 6 |  | - |  |
| `/admin/joinery_ai/chat_list` | personal | 5 (router) | 6 |  | - |  |
| `/admin/joinery_ai/chat_poll` | personal | 5 (router) | 6 |  | - |  |
| `/admin/joinery_ai/chat_send` | personal | 5 (router) | 6 |  | AiConversationMessage | own chats only |
| `/admin/joinery_ai/chat_thread_action` | personal | 5 (router) | 6 |  | AiConversation | own chats only |
| `/admin/joinery_ai/chat_turn_action` | personal | 5 (router) | 6 |  | AiConversationMessage | own chats only |
| `/admin/joinery_ai/deleted_conversation_purge` | config | 10 | 8 |  | AiConversation | other users' chats |
| `/admin/joinery_ai/deleted_conversations` | config | 10 | 8 |  | AiConversation | other users' chats |
| `/admin/joinery_ai/edit` | keys | 10 | 10 |  | Recipe, RecipeRun | tokens and keys |
| `/admin/joinery_ai/index` | config | 10 | 8 |  | - |  |
| `/admin/joinery_ai/memory` | config | 10 | 8 |  | - |  |
| `/admin/joinery_ai/memory_edit` | config | 10 | 8 |  | AiMemory |  |
| `/admin/joinery_ai/note` | personal | 10 | 6 |  | RecipeNote |  |
| `/admin/joinery_ai/notes` | personal | 10 | 6 |  | - |  |
| `/admin/joinery_ai/run` | config | 10 | 8 |  | - |  |
| `/admin/joinery_ai/run_now` | config | 10 | 8 |  | RecipeRun |  |
| `/admin/joinery_ai/runs` | config | 10 | 8 |  | - |  |
| `/admin/joinery_ai/stop_run` | config | 10 | 8 |  | RecipeRun |  |
| `/admin/server_manager/backups` | config | 5 (router) | 8 | 8 | - | redirect stub to node detail |
| `/admin/server_manager/database` | config | 5 (router) | 8 | 8 | - | redirect stub to node detail |
| `/admin/server_manager/domains` | config | 10 | 8 |  | RegisteredDomain |  |
| `/admin/server_manager/host_add` | config | 10 | 8 |  | ManagedHost | delete host is destructive (Q8) |
| `/admin/server_manager/incident` | config | 10 | 8 |  | - |  |
| `/admin/server_manager/incidents` | config | 10 | 8 |  | - |  |
| `/admin/server_manager/index` | config | 10 | 8 |  | AgentJoinRequest, CustomerCloudProvision | dashboard: status and counts only (Q9); shows addresses today (Q10) |
| `/admin/server_manager/install_node_form` | config | 10 | 8 |  | CustomerCloudProvision | installs with keys: credential columns are keys (Q8, 3.3) |
| `/admin/server_manager/job_detail` | config | 10 | 7 |  | ManagementJob | output redacted twice (Q11) |
| `/admin/server_manager/jobs` | config | 10 | 7 |  | - | Q11 |
| `/admin/server_manager/node_add` | config | 10 | 8 |  | ManagedNode | stores node credentials (Q8) |
| `/admin/server_manager/node_detail` | config | 10 | 7 |  | - | look actions 7 (Q1, Q10); changes 8; power, restore, decommission, keys: 10 (Q8) |
| `/admin/server_manager/nodes` | config | 5 (router) | 8 | 8 | - | redirect stub to node detail |
| `/admin/server_manager/nodes_edit` | config | 5 (router) | 8 | 8 | - | redirect stub to node detail |
| `/admin/server_manager/provisioning_setup` | config | 10 | 8 |  | OperatorCloudAccount | provisioning credentials are keys columns (Q8, 3.3) |
| `/admin/server_manager/publish_upgrade` | config | 10 | 8 |  | Upgrade | publish is 8; deleting a release is 10 (Q8) |
| `/admin/server_manager/rollouts` | config | 10 | 8 |  | - | applies updates to nodes |
| `/admin/server_manager/service_tenants` | config | 10 | 8 |  | - |  |
| `/admin/server_manager/specs` | config | 10 | 8 |  | - |  |
| `/admin/server_manager/target_info` | keys | 10 | 10 |  | - |  |
| `/admin/server_manager/targets` | keys | 10 | 10 |  | BackupTarget, Setting | backup targets hold keys |
| `/admin/server_manager/transfers` | config | 10 | 8 |  | - | moves a site and may remove the source (Q8) |
| `/admin/server_manager/updates` | config | 5 (router) | 8 | 8 | - | redirect stub to node detail |

### 8.5 Models

One row per class extending `SystemBase` (249). Printed by
`permissions_inventory.php --table=models`.

- **Class** is from the seed. A model written only by personal pages is personal
  unless its rows are plainly content.
- **When in doubt, the lower class** (owner, 2026-10-20). A model that holds a
  secret in one column is not raised for it: the column is keys (3.3), and the
  rest of the row stays at the lower class. ActivationCode is the one exception
  kept at restricted, since its code is a credential.
- **Owner column** is yes when `{prefix}_usr_user_id` is a field.
- **Today's write rule** is parsed from the `authenticate_write()` override:
  `boilerplate: drop` is the ~100 copies of the same check; `owner only`; and
  `keep override` for real rules.
- **REST** and **AI** are the `$api_*` and `$ai_readable` flags. A
  `public read` model cannot sit above `content`.
- **Exceptions** are the numbers that differ from the class, with the reason.
  Q13 raises Conversation and Message reads to 8.
- **Confidence**: `traced` = read the code at the line; `read` = the spec or
  the code's own wording; `instinct` = judgment. Every `instinct` row is listed
  for reviewer spot-checks.

| Model | File | Class | Owner column | Today's write rule | REST | AI | Exceptions | Confidence |
|---|---|---|---|---|---|---|---|---|
| AbTest | data/ab_tests_class.php | content | - | default: owner or 5 | - | - |  | read |
| ActivationCode | data/activation_codes_class.php | restricted | act_usr_user_id | owner only | - | - |  (one-time code; lean permissive, still a credential) | instinct |
| AdminMenu | data/admin_menus_class.php | config | - | keep override | - | - | write 10 (menu rows decide what each level sees) | read |
| AgentFile | data/agent_files_class.php | config | - | keep override | - | read | read 10; write 10 (AI instruction files (3.1)) | read |
| ApiIdempotencyKey | data/api_idempotency_keys_class.php | restricted | - | default: owner or 5 | - | - |  | instinct |
| ApiKey | data/api_keys_class.php | config | apk_usr_user_id | owner only | - | - |  | read |
| AppBridgeToken | data/app_bridge_tokens_class.php | personal | abt_usr_user_id | keep override | - | - |  (bearer token value is hashed in abt_token_hash) | instinct |
| BackupHistory | data/backup_history_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| BackupTarget | data/backup_targets_class.php | config | - | default: owner or 5 | - | - |  | read |
| CalendarEmail | data/calendar_emails_class.php | personal | cme_usr_user_id | default: owner or 5 | - | - |  | instinct |
| CalendarPreference | data/calendar_preferences_class.php | personal | cpr_usr_user_id | owner only | read, write | - |  | instinct |
| ChangeTracking | data/change_tracking_class.php | restricted | cht_usr_user_id | keep override | - | - |  | instinct |
| Comment | data/comments_class.php | content | cmt_usr_user_id | owner only | read, write | read |  (author name shown with the comment; lean permissive) | instinct |
| Component | data/components_class.php | content | - | default: owner or 5 | - | - |  | read |
| ContactType | data/contact_types_class.php | content | - | keep override | - | - |  | instinct |
| ContentReport | data/content_reports_class.php | personal | rpt_usr_user_id | keep override | - | - |  | instinct |
| ContentVersion | data/content_versions_class.php | content | cvn_usr_user_id | keep override | - | - |  | instinct |
| ConversationKeyGrant | data/conversation_key_grants_class.php | personal | ckg_usr_user_id | default: owner or 5 | - | - |  (wrapped key column is keys) | instinct |
| ConversationParticipant | data/conversation_participants_class.php | personal | cnp_usr_user_id | owner only | read, write | read |  | instinct |
| ConversationRemotePeer | data/conversation_remote_peers_class.php | personal | - | default: owner or 5 | - | - |  | instinct |
| Conversation | data/conversations_class.php | personal | - | default: owner or 5 | read, write | read |  read 8 (Q13) | read |
| DebugEmailLog | data/debug_email_logs_class.php | restricted | - | default: owner or 5 | - | - |  | read |
| DeletionRule | data/deletion_rules_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| DeviceLink | data/device_links_class.php | personal | dlk_usr_user_id | default: owner or 5 | - | - |  (sealed vault key columns are keys) | instinct |
| DirectCapabilityCache | data/direct_capability_cache_class.php | config | - | keep override | - | - |  | instinct |
| DirectIdentity | data/direct_identities_class.php | keys | - | keep override | - | - |  | read |
| DirectNonce | data/direct_nonces_class.php | config | - | keep override | - | - |  | instinct |
| DirectSession | data/direct_sessions_class.php | restricted | - | keep override | - | - |  | instinct |
| DirectSpool | data/direct_spool_class.php | personal | jdp_usr_user_id | keep override | - | - |  | instinct |
| DirectSpoolPart | data/direct_spool_parts_class.php | personal | - | keep override | - | - |  | instinct |
| DriveUsage | data/drive_usage_class.php | config | dru_usr_user_id | default: owner or 5 | - | - |  | instinct |
| EmailRecipientGroup | data/email_recipient_groups_class.php | personal | - | keep override | - | - |  | instinct |
| EmailRecipient | data/email_recipients_class.php | personal | erc_usr_user_id | owner only | - | - |  | instinct |
| EmailTemplate | data/email_templates_class.php | content | - | keep override | - | - |  | read |
| Email | data/emails_class.php | personal | eml_usr_user_id | owner only | - | - |  personal (Q12) | read |
| EntityAttachment | data/entity_attachments_class.php | content | eat_usr_user_id | default: owner or 5 | - | - |  | instinct |
| EntityPhoto | data/entity_photos_class.php | content | - | default: owner or 5 | - | read |  | instinct |
| CalendarEntry | data/entries_class.php | personal | - | keep override | - | read |  | instinct |
| CalEntryException | data/entry_exceptions_class.php | personal | - | default: owner or 5 | - | - |  | instinct |
| EventLog | data/event_logs_class.php | restricted | evl_usr_user_id | keep override | - | - |  | read |
| FileAccessGrant | data/file_access_grants_class.php | personal | fga_usr_user_id | default: owner or 5 | - | - |  | instinct |
| FileBlob | data/file_blobs_class.php | personal | - | default: owner or 5 | - | - |  | instinct |
| FileChange | data/file_changes_class.php | personal | fch_usr_user_id | default: owner or 5 | - | - |  | instinct |
| FileKeyGrant | data/file_key_grants_class.php | personal | fkg_usr_user_id | default: owner or 5 | - | - |  (wrapped file key column is keys) | instinct |
| FileShareLink | data/file_share_links_class.php | personal | fsl_usr_user_id | default: owner or 5 | - | - |  | instinct |
| FileUpload | data/file_uploads_class.php | personal | fup_usr_user_id | default: owner or 5 | - | - |  | instinct |
| FileVersion | data/file_versions_class.php | personal | fvr_usr_user_id | default: owner or 5 | - | - |  | instinct |
| File | data/files_class.php | personal | fil_usr_user_id | default: owner or 5 | - | read |  | instinct |
| Folder | data/folders_class.php | personal | fol_usr_user_id | default: owner or 5 | - | - |  | instinct |
| GeneralError | data/general_errors_class.php | restricted | err_usr_user_id | keep override | - | - |  | read |
| GroupMember | data/group_members_class.php | content | - | keep override | - | read | write 8 (tier membership grants paid access (M4)) | instinct |
| Group | data/groups_class.php | content | - | owner only | read, write, public read | read |  | read |
| LogFormError | data/log_form_errors_class.php | restricted | lfe_usr_user_id | keep override | - | - |  | read |
| Login | data/logins_class.php | restricted | log_usr_user_id | keep override | - | - |  | read |
| MailingListRegistrant | data/mailing_list_registrants_class.php | personal | mlr_usr_user_id | owner only | read, write | read |  | instinct |
| MailingList | data/mailing_lists_class.php | content | - | keep override | read, write, public read | read |  ($api_public_read: the list itself is content (M3)) | read |
| ManagedDnsRecord | data/managed_dns_records_class.php | config | - | keep override | - | - |  | instinct |
| MessageAttachment | data/message_attachments_class.php | personal | - | default: owner or 5 | - | - |  | instinct |
| MessageReaction | data/message_reactions_class.php | personal | msr_usr_user_id | default: owner or 5 | - | - |  | instinct |
| Message | data/messages_class.php | personal | msg_usr_user_id_sender | owner only | read, write | read | read 8 (Q13) | read |
| Migration | data/migrations_class.php | config | - | keep override | - | - |  | instinct |
| NotificationPreference | data/notification_preferences_class.php | personal | ntp_usr_user_id | owner only | read, write | - |  | instinct |
| Notification | data/notifications_class.php | personal | ntf_usr_user_id | owner only | read, write | read |  | instinct |
| PageContent | data/page_contents_class.php | content | pac_usr_user_id | owner only | read, write, public read | read |  | read |
| PageProbeGrant | data/page_probe_grants_class.php | config | ppg_usr_user_id | default: owner or 5 | - | - |  | instinct |
| Page | data/pages_class.php | content | pag_usr_user_id | default: owner or 5 | read, write, public read | read |  | read |
| PasskeyCeremony | data/passkey_ceremonies_class.php | restricted | - | default: owner or 5 | - | - |  | instinct |
| Passkey | data/passkey_credentials_class.php | restricted | pkc_usr_user_id | default: owner or 5 | read | - | write owner only (passkeys are owner-only (M6)) | read |
| PhoneNumber | data/phone_numbers_class.php | personal | phn_usr_user_id | owner only | read, write | read |  | read |
| PluginDependency | data/plugin_dependencies_class.php | config | - | keep override | - | - |  | instinct |
| PluginMigration | data/plugin_migrations_class.php | config | - | keep override | - | - |  | instinct |
| PluginVersion | data/plugin_versions_class.php | config | - | keep override | - | - |  | instinct |
| Plugin | data/plugins_class.php | config | - | keep override | - | - | write 10 (installing or removing code) | read |
| Post | data/posts_class.php | content | pst_usr_user_id | owner only | read, write, public read | read |  | read |
| ProblemReport | data/problem_reports_class.php | personal | prr_usr_user_id | owner only | - | - |  | read |
| PublicMenu | data/public_menus_class.php | config | - | keep override | - | - |  | read |
| PushDestination | data/push_destinations_class.php | personal | psh_usr_user_id | owner only | - | - |  | instinct |
| QuestionOption | data/question_options_class.php | content | - | keep override | read, write, public read | read |  | instinct |
| Question | data/questions_class.php | content | - | keep override | read, write, public read | read |  | read |
| QueuedEmail | data/queued_emails_class.php | personal | - | default: owner or 5 | write | - |  | instinct |
| Reaction | data/reactions_class.php | content | rct_usr_user_id | default: owner or 5 | read, write | read |  (a reaction on public content is public (M5)) | instinct |
| RecoveryVerification | data/recovery_verifications_class.php | restricted | rcv_usr_user_id | default: owner or 5 | - | - |  | instinct |
| ReleaseInstall | data/release_installs_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| RequestLog | data/request_logs_class.php | restricted | rql_usr_user_id | keep override | - | - |  | read |
| SandboxInstall | data/sandbox_installs_class.php | config | sbi_usr_user_id | default: owner or 5 | - | - |  | instinct |
| SandboxLog | data/sandbox_logs_class.php | restricted | sbl_usr_user_id | default: owner or 5 | - | - |  | read |
| ScheduleOverride | data/schedule_overrides_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| ScheduleWindow | data/schedule_windows_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| ScheduledTask | data/scheduled_tasks_class.php | config | - | keep override | - | - | write 10 (runs code on a schedule) | read |
| Schedule | data/schedules_class.php | config | - | keep override | - | read |  | instinct |
| SealedSecretRegistry | data/sealed_secret_registry_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| SeoPageMetadata | data/seo_page_metadata_class.php | content | - | default: owner or 5 | - | read |  | instinct |
| SessionAnalytic | data/session_analytics_class.php | personal | sev_usr_user_id | keep override | - | - |  | instinct |
| Setting | data/settings_class.php | config | stg_usr_user_id | keep override | - | - |  | read |
| SetupDecision | data/setup_decisions_class.php | config | sud_usr_user_id | default: owner or 5 | - | - |  | instinct |
| SignalDelivery | data/signal_deliveries_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| SignalReceiver | data/signal_receivers_class.php | config | - | default: owner or 5 | - | - |  (signing secret is a keys column) | instinct |
| SiteSupportThread | data/site_support_threads_class.php | personal | spt_usr_user_id | keep override | - | - |  | instinct |
| SubscriptionTier | data/subscription_tiers_class.php | money | - | default: owner or 5 | read, write, public read | read |  | read |
| SurveyAnswer | data/survey_answers_class.php | personal | sva_usr_user_id | keep override | read, write | read |  | read |
| SurveyQuestion | data/survey_questions_class.php | content | - | keep override | - | read |  | read |
| Survey | data/surveys_class.php | content | - | owner only | read, write, public read | read |  | read |
| SyncDevice | data/sync_devices_class.php | personal | sde_usr_user_id | default: owner or 5 | - | - |  | instinct |
| Theme | data/themes_class.php | config | - | default: owner or 5 | - | - | write 10 (theme code runs on every page) | read |
| Upgrade | data/upgrades_class.php | config | - | owner only | - | - | write 10 (publishes upgrades) | instinct |
| Url | data/urls_class.php | config | - | owner only | - | - |  | instinct |
| UserEncryptionVault | data/user_encryption_vaults_class.php | config | uev_usr_user_id | default: owner or 5 | - | - |  (public keys and salt only; secrets are in wrappings) | instinct |
| UserEncryptionWrapping | data/user_encryption_wrappings_class.php | config | - | default: owner or 5 | - | - |  (wrapped secret column is keys) | instinct |
| Address | data/users_addrs_class.php | personal | usa_usr_user_id | owner only | read, write | read |  | read |
| User | data/users_class.php | personal | usr_user_id | keep override | read, write | read |  | read |
| AbTestVariant | data/variants_class.php | content | - | default: owner or 5 | - | - |  | read |
| Video | data/videos_class.php | content | vid_usr_user_id | owner only | - | read |  | read |
| VisitorEvent | data/visitor_events_class.php | personal | vse_usr_user_id | keep override | - | - |  | instinct |
| VisitorStatsRollup | data/visitor_stats_rollup_class.php | content | - | keep override | - | - |  | instinct |
| WebhookLog | data/webhook_logs_class.php | restricted | - | default: owner or 5 | - | - |  | instinct |
| BoardActivity | plugins/boards/data/activity_class.php | content | bac_usr_user_id | keep override | - | - |  | instinct |
| BoardAttachment | plugins/boards/data/attachments_class.php | content | bat_usr_user_id | keep override | - | - |  | instinct |
| BoardComment | plugins/boards/data/board_comments_class.php | content | bco_usr_user_id | keep override | - | - |  | instinct |
| BoardMember | plugins/boards/data/board_members_class.php | personal | bbm_usr_user_id | keep override | - | - |  | instinct |
| BoardWatcher | plugins/boards/data/board_watchers_class.php | personal | bbw_usr_user_id | owner only | - | - |  | instinct |
| Board | plugins/boards/data/boards_class.php | content | - | default: owner or 5 | - | - |  | read |
| BoardCardLabel | plugins/boards/data/card_labels_class.php | content | - | keep override | - | - |  | instinct |
| BoardCardMember | plugins/boards/data/card_members_class.php | personal | bcm_usr_user_id | keep override | - | - |  | instinct |
| BoardCardWatcher | plugins/boards/data/card_watchers_class.php | personal | bcw_usr_user_id | owner only | - | - |  | instinct |
| BoardCard | plugins/boards/data/cards_class.php | content | - | keep override | - | - |  | read |
| BoardChecklistItem | plugins/boards/data/checklist_items_class.php | content | bci_usr_user_id | keep override | - | - |  | instinct |
| BoardChecklist | plugins/boards/data/checklists_class.php | content | - | keep override | - | - |  | instinct |
| BoardLabel | plugins/boards/data/labels_class.php | content | - | keep override | - | - |  | instinct |
| BoardList | plugins/boards/data/lists_class.php | content | - | keep override | - | - |  | instinct |
| BookingEmail | plugins/bookings/data/booking_emails_class.php | personal | - | default: owner or 5 | - | - |  | instinct |
| BookingType | plugins/bookings/data/booking_types_class.php | money | bty_usr_user_id | owner only | - | - |  | instinct |
| Booking | plugins/bookings/data/bookings_class.php | personal | - | owner only | - | read |  | read |
| ReceivedBugReport | plugins/bug_reports/data/received_bug_reports_class.php | personal | - | keep override | - | - |  | read |
| SdDeviceBackup | plugins/dns_filtering/data/device_backups_class.php | personal | sbk_usr_user_id | default: owner or 5 | - | - |  (its PIN column is a keys column) | instinct |
| SdDevice | plugins/dns_filtering/data/devices_class.php | personal | sdd_usr_user_id | owner only | - | read |  | instinct |
| SdScheduledBlockFilter | plugins/dns_filtering/data/scheduled_block_filters_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| SdScheduledBlockRule | plugins/dns_filtering/data/scheduled_block_rules_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| SdScheduledBlockService | plugins/dns_filtering/data/scheduled_block_services_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| SdScheduledBlock | plugins/dns_filtering/data/scheduled_blocks_class.php | config | - | keep override | - | - |  | instinct |
| EventRegistrant | plugins/event_manager/data/event_registrants_class.php | personal | evr_usr_user_id | owner only | read, write | read |  | read |
| EventSessionFile | plugins/event_manager/data/event_session_files_class.php | content | - | default: owner or 5 | - | read |  | instinct |
| EventSession | plugins/event_manager/data/event_sessions_class.php | content | - | keep override | read, write, public read | read |  | read |
| EventType | plugins/event_manager/data/event_types_class.php | content | - | default: owner or 5 | read, write, public read | read |  | read |
| Event | plugins/event_manager/data/events_class.php | content | - | keep override | read, write, public read | read |  | read |
| Location | plugins/event_manager/data/locations_class.php | content | - | keep override | read, write, public read | read |  | read |
| WaitingList | plugins/event_manager/data/waiting_lists_class.php | personal | ewl_usr_user_id | keep override | read, write | - |  | instinct |
| ForumCategory | plugins/forum/data/forum_categories_class.php | content | - | keep override | - | - |  | read |
| ForumPost | plugins/forum/data/forum_posts_class.php | content | fpo_usr_user_id | owner only | - | - |  | read |
| ForumTopicRead | plugins/forum/data/forum_topic_reads_class.php | personal | ftr_usr_user_id | keep override | - | - |  | instinct |
| ForumTopic | plugins/forum/data/forum_topics_class.php | content | ftp_usr_user_id | keep override | - | - |  | read |
| ForumWatch | plugins/forum/data/forum_watches_class.php | personal | fwt_usr_user_id | keep override | - | - |  | instinct |
| ItemRelationType | plugins/items/data/item_relation_types_class.php | content | - | default: owner or 5 | - | read |  | instinct |
| ItemRelation | plugins/items/data/item_relations_class.php | content | itr_usr_user_id | default: owner or 5 | - | read |  | instinct |
| Item | plugins/items/data/items_class.php | content | itm_usr_user_id | default: owner or 5 | - | read |  | read |
| AiQueuedAction | plugins/joinery_ai/data/ai_queued_actions_class.php | personal | - | owner only | - | - |  | instinct |
| AiConversationMessage | plugins/joinery_ai/data/conversation_messages_class.php | config | - | keep override | - | - |  | read |
| AiConversation | plugins/joinery_ai/data/conversations_class.php | config | aic_owner_user_id (undeclared) | keep override | - | - |  (D9: admins only; own chats through the owner rule) | read |
| AiMemory | plugins/joinery_ai/data/memories_class.php | config | - | owner only | - | - |  | read |
| AiMessageAttachment | plugins/joinery_ai/data/message_attachments_class.php | config | - | keep override | - | - |  | instinct |
| RecipeNote | plugins/joinery_ai/data/notes_class.php | config | - | owner only | - | read |  | instinct |
| AipRecipeItemLog | plugins/joinery_ai/data/recipe_item_log_class.php | restricted | - | keep override | - | - |  | instinct |
| RecipeRun | plugins/joinery_ai/data/recipe_runs_class.php | config | - | keep override | - | - |  | instinct |
| Recipe | plugins/joinery_ai/data/recipes_class.php | config | - | keep override | - | read |  | instinct |
| MailAppFolder | plugins/mail_apps/data/mail_app_folders_class.php | personal | - | keep override | - | - |  | instinct |
| MailAppMessage | plugins/mail_apps/data/mail_app_messages_class.php | personal | - | keep override | - | - |  | instinct |
| MailAppUid | plugins/mail_apps/data/mail_app_uids_class.php | personal | - | keep override | - | - |  | instinct |
| DeliverabilityReportSource | plugins/mailbox/data/deliverability_report_sources_class.php | restricted | - | default: owner or 5 | - | - |  | instinct |
| DeliverabilityReport | plugins/mailbox/data/deliverability_reports_class.php | restricted | - | default: owner or 5 | - | - |  | instinct |
| InboundBayesToken | plugins/mailbox/data/inbound_bayes_tokens_class.php | restricted | - | keep override | - | - |  | instinct |
| InboundEmailAlias | plugins/mailbox/data/inbound_email_aliases_class.php | config | - | keep override | - | - |  | instinct |
| InboundEmailDomain | plugins/mailbox/data/inbound_email_domains_class.php | config | - | keep override | - | - |  | instinct |
| InboundEmailFilterDeviceProgress | plugins/mailbox/data/inbound_email_filter_device_progress_class.php | restricted | ifp_usr_user_id | keep override | - | - |  | instinct |
| InboundEmailFilter | plugins/mailbox/data/inbound_email_filters_class.php | personal | - | keep override | - | - |  | instinct |
| InboundEmailLabel | plugins/mailbox/data/inbound_email_labels_class.php | personal | - | keep override | - | - |  | instinct |
| InboundEmailLog | plugins/mailbox/data/inbound_email_logs_class.php | restricted | - | default: owner or 5 | - | - |  | instinct |
| InboundEmailMailboxGrant | plugins/mailbox/data/inbound_email_mailbox_grants_class.php | config | ieg_usr_user_id | keep override | - | - |  | instinct |
| InboundEmailMessage | plugins/mailbox/data/inbound_email_messages_class.php | personal | iem_sealed_owner_user_id | keep override | - | read |  | read |
| InboundForwardDestination | plugins/mailbox/data/inbound_forward_destinations_class.php | config | - | keep override | - | - |  | instinct |
| InboundImapAccount | plugins/mailbox/data/inbound_imap_accounts_class.php | restricted | - | keep override | - | - |  | read |
| InboundImapFolder | plugins/mailbox/data/inbound_imap_folders_class.php | personal | - | keep override | - | - |  | instinct |
| InboundImapIngestFailure | plugins/mailbox/data/inbound_imap_ingest_failures_class.php | restricted | - | keep override | - | - |  | instinct |
| InboundImapSeedProof | plugins/mailbox/data/inbound_imap_seed_proofs_class.php | restricted | - | keep override | - | - |  | instinct |
| InboundLabelMember | plugins/mailbox/data/inbound_label_members_class.php | personal | - | keep override | - | - |  | instinct |
| InboundMailboxSearchIndex | plugins/mailbox/data/inbound_mailbox_search_index_class.php | personal | imi_usr_user_id | default: owner or 5 | - | - |  | instinct |
| InboundMessageAttachment | plugins/mailbox/data/inbound_message_attachments_class.php | personal | - | keep override | - | - |  | instinct |
| InboundPersonalAddress | plugins/mailbox/data/inbound_personal_addresses_class.php | personal | ipa_usr_user_id | keep override | - | - |  | instinct |
| InboundSenderRecord | plugins/mailbox/data/inbound_sender_records_class.php | personal | - | keep override | - | - |  | instinct |
| MailImportEntry | plugins/mailbox/data/mail_import_entries_class.php | personal | - | keep override | - | - |  | instinct |
| MailImportRun | plugins/mailbox/data/mail_import_runs_class.php | personal | mir_usr_user_id | keep override | - | - |  | instinct |
| MailboxContactIndexKey | plugins/mailbox/data/mailbox_contact_index_keys_class.php | personal | mck_usr_user_id | keep override | - | - |  (sealed key column is keys) | instinct |
| MailboxContact | plugins/mailbox/data/mailbox_contacts_class.php | personal | imc_usr_user_id | keep override | - | - |  | instinct |
| MailboxDeviceAiHost | plugins/mailbox/data/mailbox_device_ai_hosts_class.php | config | mdh_usr_user_id | keep override | - | - |  | instinct |
| MailboxFleetDomainClaim | plugins/mailbox/data/mailbox_fleet_domain_claims_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| MailboxFleetShard | plugins/mailbox/data/mailbox_fleet_shards_class.php | config | - | keep override | - | - |  | instinct |
| MailboxFleetSlot | plugins/mailbox/data/mailbox_fleet_slots_class.php | config | mft_usr_user_id | default: owner or 5 | - | - |  | instinct |
| MailboxRelay | plugins/mailbox/data/mailbox_relays_class.php | config | - | keep override | - | - |  | instinct |
| MailboxSearchKey | plugins/mailbox/data/mailbox_search_keys_class.php | personal | msk_usr_user_id | owner only | - | - |  (sealed key column is keys) | instinct |
| MailboxSendAttempt | plugins/mailbox/data/mailbox_send_attempts_class.php | restricted | mst_usr_user_id | default: owner or 5 | - | - |  | instinct |
| RelayClientIdentity | plugins/mailbox/data/relay_client_identities_class.php | config | - | keep override | - | - |  (sealed secret key is a keys column) | instinct |
| RelayCloudProvision | plugins/mailbox/data/relay_cloud_provisions_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| PersonaAdTally | plugins/persona_browser/data/persona_ad_tallies_class.php | content | - | default: owner or 5 | - | - |  | instinct |
| PersonaAllowedSender | plugins/persona_browser/data/persona_allowed_senders_class.php | personal | - | default: owner or 5 | - | - |  | instinct |
| PersonaBlockedSender | plugins/persona_browser/data/persona_blocked_senders_class.php | personal | - | default: owner or 5 | - | - |  | instinct |
| PersonaFeedItem | plugins/persona_browser/data/persona_feed_items_class.php | content | - | default: owner or 5 | - | - |  | instinct |
| PersonaStory | plugins/persona_browser/data/persona_stories_class.php | content | - | default: owner or 5 | - | - |  | instinct |
| AgentHeartbeat | plugins/server_manager/data/agent_heartbeats_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| AgentJoinRequest | plugins/server_manager/data/agent_join_requests_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| CustomerCloudAccount | plugins/server_manager/data/customer_cloud_accounts_class.php | config | cca_usr_user_id | default: owner or 5 | - | - |  (tokens are keys columns) | instinct |
| CustomerCloudProvision | plugins/server_manager/data/customer_cloud_provisions_class.php | config | cvp_usr_user_id | default: owner or 5 | - | - |  | instinct |
| DataRootEscrow | plugins/server_manager/data/data_root_escrows_class.php | config | - | default: owner or 5 | - | - |  (escrowed secrets are keys columns) | instinct |
| HostedTrial | plugins/server_manager/data/hosted_trials_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| IncidentEvent | plugins/server_manager/data/incident_events_class.php | config | ine_usr_user_id | default: owner or 5 | - | - |  (an 8 must acknowledge incidents) | instinct |
| IncidentRecord | plugins/server_manager/data/incident_records_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| InstanceTransfer | plugins/server_manager/data/instance_transfers_class.php | config | itx_usr_user_id | default: owner or 5 | - | - |  | instinct |
| MachineTransfer | plugins/server_manager/data/machine_transfers_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| ManagedHost | plugins/server_manager/data/managed_hosts_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| ManagedNode | plugins/server_manager/data/managed_nodes_class.php | config | - | default: owner or 5 | - | - |  | read |
| ManagementJob | plugins/server_manager/data/management_jobs_class.php | config | - | default: owner or 5 | - | - |  (job output is a column question (Q11)) | instinct |
| OperatorCloudAccount | plugins/server_manager/data/operator_cloud_accounts_class.php | config | - | default: owner or 5 | - | - |  (credential is a keys column) | instinct |
| RegisteredDomain | plugins/server_manager/data/registered_domains_class.php | config | rdm_usr_user_id | default: owner or 5 | - | - |  | instinct |
| ReleaseLogEntry | plugins/server_manager/data/release_log_entries_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| ServiceTenant | plugins/server_manager/data/service_tenants_class.php | config | svt_usr_user_id | default: owner or 5 | - | - |  | instinct |
| ShelfObject | plugins/server_manager/data/shelf_objects_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| ShelfRun | plugins/server_manager/data/shelf_runs_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| SiteCopy | plugins/server_manager/data/site_copies_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| StagedRollout | plugins/server_manager/data/staged_rollouts_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| StorageSpace | plugins/server_manager/data/storage_spaces_class.php | config | - | default: owner or 5 | - | - |  | instinct |
| CouponCodeProduct | plugins/store/data/coupon_code_products_class.php | money | - | keep override | - | - |  | instinct |
| CouponCodeUse | plugins/store/data/coupon_code_uses_class.php | money | - | keep override | - | - |  | instinct |
| CouponCode | plugins/store/data/coupon_codes_class.php | money | - | keep override | - | read |  | read |
| MobileStoreProduct | plugins/store/data/mobile_store_products_class.php | money | - | default: owner or 5 | - | - |  | instinct |
| OrderItemRequirement | plugins/store/data/order_item_requirements_class.php | personal | - | keep override | read | - |  | instinct |
| OrderItem | plugins/store/data/order_items_class.php | personal | odi_usr_user_id | owner only | read, write | read |  | read |
| Order | plugins/store/data/orders_class.php | personal | ord_usr_user_id | owner only | read, write | read |  | read |
| Ownership | plugins/store/data/ownerships_class.php | personal | own_usr_user_id | keep override | - | - |  | instinct |
| ProductGroup | plugins/store/data/product_groups_class.php | content | - | default: owner or 5 | read, write, public read | read |  | instinct |
| ProductRequirementInstance | plugins/store/data/product_requirement_instances_class.php | personal | - | keep override | - | read |  | instinct |
| ProductRequirement | plugins/store/data/product_requirements_class.php | content | - | keep override | - | read |  | instinct |
| ProductVersion | plugins/store/data/product_versions_class.php | money | - | default: owner or 5 | - | - |  | instinct |
| Product | plugins/store/data/products_class.php | money | - | default: owner or 5 | read, write, public read | read |  | read |
| StripeCustomer | plugins/store/data/stripe_customers_class.php | personal | stc_usr_user_id | keep override | - | - |  | instinct |
| StripeInvoice | plugins/store/data/stripe_invoices_class.php | personal | siv_usr_user_id | keep override | read, write | - |  | instinct |
| SupportDocRelease | plugins/support_desk/data/doc_releases_class.php | content | - | keep override | - | - |  | instinct |
| SupportDocSection | plugins/support_desk/data/doc_sections_class.php | content | - | keep override | - | - |  | instinct |
| SupportFaqEntry | plugins/support_desk/data/faq_entries_class.php | content | - | keep override | - | - |  | instinct |
| SupportThread | plugins/support_desk/data/support_threads_class.php | personal | - | keep override | - | - |  | read |
| SupportWordGap | plugins/support_desk/data/word_gaps_class.php | content | - | keep override | - | - |  | instinct |
| VaultEntry | plugins/vault/data/vault_entries_class.php | personal | vle_usr_user_id | default: owner or 5 | - | - |  | instinct |
| VaultKeyring | plugins/vault/data/vault_keyring_class.php | keys | vlk_usr_user_id | default: owner or 5 | - | - |  | instinct |

### 8.6 Column exceptions

Printed by `permissions_inventory.php --table=columns`. Sources:

- **sealed**: every `sealed_secrets` locator in `settings.json` and
  `plugin.json` (15 core, 25 plugin). Keys, automatically.
- **secret setting**: every setting flagged `secret: true` (23 core, 23 plugin).
  Keys, automatically.
- **declared**: columns whose name matches the secret pattern (the spec's, plus
  `authhash`, `hmac` and `_key` endings, which the first pass missed), and the
  non-secret raises the seed names. Names reviewed and found not secret are
  counted at the foot of the table, not listed.

Any `declared` row marked `plaintext?` without a sealed locator is a bug. Those
are B24 to B26 in section 7.

| Model.column | Class | Source | Note |
|---|---|---|---|
| AgentJoinRequest.ajr_public_key | config | declared | public key or expected fingerprint; not secret |
| AiConversation.aic_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| AiConversationMessage.aim_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| AiQueuedAction.aqa_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| ApiIdempotencyKey.aik_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| ApiKey.apk_public_key | config | declared | public identifier; the list shows it |
| ApiKey.apk_secret_key | keys | declared | secret half of the API key; confirm stored hashed or sealed |
| AppBridgeToken.abt_token_hash | restricted | declared | stored hash of a token or password; never rendered |
| BackupTarget.bkt_credentials | keys | sealed (core) | Backup target credentials |
| Booking.bkn_action_token | restricted | declared | bearer token in the manage URL (plugins/bookings/views/booking_manage.php:52) |
| CalendarEntry.cal_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| ConversationKeyGrant.ckg_wrapped_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| CustomerCloudAccount.cca_access_token | keys | sealed (server_manager) | Customer cloud access token |
| CustomerCloudAccount.cca_refresh_token | keys | sealed (server_manager) | Customer cloud refresh token |
| CustomerCloudProvision.cvp_admin_pass_sealed | keys | sealed (server_manager) | Hosted site's first admin password |
| CustomerCloudProvision.cvp_expected_host_key | config | declared | public key or expected fingerprint; not secret |
| CustomerCloudProvision.cvp_expected_site_key | config | declared | public key or expected fingerprint; not secret |
| CustomerCloudProvision.cvp_recovery_public_key | config | declared | public key or expected fingerprint; not secret |
| CustomerCloudProvision.cvp_registrant_sealed | keys | sealed (server_manager) | Managed site draft's domain registrant contact |
| CustomerCloudProvision.cvp_root_pass_sealed | keys | sealed (server_manager) | Provisioning root password |
| CustomerCloudProvision.cvp_root_ssh_keys | keys | declared | provisioning SSH keys; confirm public-only |
| DataRootEscrow.dre_identity_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| DataRootEscrow.dre_pending_shared | keys | sealed (server_manager) | Encrypted data root unlock value |
| DataRootEscrow.dre_report_key | keys | sealed (server_manager) | Encrypted data root report key |
| DataRootEscrow.dre_secret | keys | sealed (server_manager) | Encrypted data root escrow secret |
| DeviceLink.dlk_code_hash | restricted | declared | hash of the link code |
| DeviceLink.dlk_poll_token_hash | restricted | declared | stored hash of a token or password; never rendered |
| DeviceLink.dlk_sealed_vault_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| DeviceLink.dlk_sealed_vault_keys | keys | declared | sealed vault key set |
| DeviceLink.dlk_secret_once | keys | sealed (core) | Device-link session secret |
| DirectIdentity.jdi_public_key | config | declared | public key or expected fingerprint; not secret |
| DirectIdentity.jdi_sealed_secret_key | keys | declared | sealed by the DEK path (includes/joinery_direct/DirectIdentity.php:173) |
| DirectIdentity.jdi_secret_key | keys | sealed (core) | Joinery Direct signing identity |
| File.fil_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| FileBlob.fbb_encrypted_variant_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| FileKeyGrant.fkg_wrapped_file_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| FileShareLink.fsl_password_hash | restricted | declared | stored hash of a token or password; never rendered |
| FileShareLink.fsl_token_sha256 | restricted | declared | stored hash of a token or password; never rendered |
| FileUpload.fup_token_sha256 | restricted | declared | stored hash of a token or password; never rendered |
| InboundEmailDomain.ied_dkim_pending_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| InboundEmailDomain.ied_dkim_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| InboundEmailMessage.iem_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| InboundForwardDestination.ifd_token_hash | restricted | declared | stored hash of a token or password; never rendered |
| InboundImapAccount.iia_oauth_access_token_enc | keys | sealed (mailbox) | IMAP OAuth access token |
| InboundImapAccount.iia_oauth_refresh_token_enc | keys | sealed (mailbox) | IMAP OAuth refresh token |
| InboundImapAccount.iia_password_enc | keys | sealed (mailbox) | IMAP account password |
| InboundImapAccount.iia_provider_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| InboundMailboxSearchIndex.imi_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| InboundPersonalAddress.ipa_token | restricted | declared | routing token that is part of the address; not sealed |
| InstanceTransfer.itx_token_sealed | keys | sealed (server_manager) | Server transfer code |
| MailboxContact.imc_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| MailboxContactIndexKey.mck_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| MailboxFleetSlot.mft_public_key | config | declared | public key or expected fingerprint; not secret |
| MailboxRelay.mrl_identity_public_key | config | declared | public key or expected fingerprint; not secret |
| MailboxRelay.mrl_transport_public_key | config | declared | public key or expected fingerprint; not secret |
| MailboxRelay.mrl_transport_secret_sealed | keys | sealed (mailbox) | Relay transport secret |
| MailboxSearchKey.msk_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| MailboxSendAttempt.mst_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| ManagedNode.mgn_agent_public_key | config | declared | public half of a pair |
| ManagedNode.mgn_api_public_key | config | declared | public half of a pair |
| ManagedNode.mgn_api_secret_key | keys | declared | PLAINTEXT node API secret (plugins/server_manager/logic/node_detail_actions_logic.php:993); see B26 |
| ManagedNode.mgn_backup_recovery_public_key | config | declared | public half of a pair |
| ManagedNode.mgn_ssh_key_path | config | declared | path to a key file, not the key |
| Message.msg_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| OperatorCloudAccount.oca_credential | keys | sealed (server_manager) | Our cloud account credentials |
| OrderItem.odi_play_purchase_token | restricted | declared | PLAINTEXT Google Play purchase token; not sealed; see B28 |
| Ownership.own_license_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| PageProbeGrant.ppg_token_hash | restricted | declared | stored hash of a token or password; never rendered |
| RecipeRun.rcr_sealed_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| RecoveryVerification.rcv_item_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| RegisteredDomain.rdm_registrant_sealed | keys | sealed (server_manager) | Domain registrant contact |
| RelayClientIdentity.rci_public_key | config | declared | public key or expected fingerprint; not secret |
| RelayClientIdentity.rci_sealed_secret_key | keys | sealed (mailbox) | Relay client identity secret key |
| RelayCloudProvision.rcl_sealed_run_token | keys | sealed (mailbox) | Relay run token |
| RelayCloudProvision.rcl_sealed_token | keys | sealed (mailbox) | Relay cloud provisioning token |
| SandboxInstall.sbi_api_secret | keys | sealed (core) | Sandboxed plugin API key secret |
| SdDeviceBackup.sbk_deactivation_pin | keys | declared | plaintext PIN copied from the device; see B27 (to verify) |
| ShelfObject.svo_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| ShelfRun.svr_base_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| ShelfRun.svr_token_hash | restricted | declared | stored hash of a token or password; never rendered |
| SignalReceiver.sgr_signing_secret | keys | sealed (core) | Webhook signing secret |
| SiteCopy.scp_expected_site_key | config | declared | public key or expected fingerprint; not secret |
| SiteSupportThread.spt_token | restricted | declared | support thread token (data/site_support_threads_class.php:125) |
| StorageSpace.sps_base_key | keys | declared | key material stored sealed or wrapped by name; confirm a SecretBox write |
| SupportThread.sth_token_hash | restricted | declared | stored hash of a token or password; never rendered |
| User.usr_allowed_ips | config | declared | IP allowlist; raise from personal |
| User.usr_authhash | restricted | declared | PLAINTEXT auth hash used in contact-preference links (logic/contact_preferences_logic.php:18); see B29 |
| User.usr_password | restricted | declared | password hash; never rendered |
| User.usr_remember_tokens | restricted | declared | already on the API unreadable floor; hashing not verified |
| User.usr_second_factor_hmac_key | keys | declared | PLAINTEXT second-factor HMAC key (data/users_class.php:830); no sealed locator; see B25 |
| User.usr_totp_backup_codes | restricted | declared | Argon2id hashes per the write comment (data/users_class.php:895) |
| User.usr_totp_secret | keys | declared | PLAINTEXT TOTP seed (data/users_class.php:816); no sealed locator; see B24 |
| UserEncryptionVault.uev_pending_public_key | config | declared | public key or expected fingerprint; not secret |
| UserEncryptionVault.uev_public_key | config | declared | public key or expected fingerprint; not secret |
| UserEncryptionWrapping.uew_wrapped_secret_key | keys | declared | wrapped secret key |
| setting: brevo_api_key | keys | secret setting (core) | Brevo API key |
| setting: captcha_private | keys | secret setting (core) | Google reCAPTCHA secret key |
| setting: dns_filtering_dns_api_key | keys | secret setting (dns_filtering) | ScrollDaddy DNS API Key |
| setting: dns_filtering_dns_secondary_api_key | keys | secret setting (dns_filtering) | Secondary DNS API Key |
| setting: dns_install_credential | keys | sealed (core); secret setting (core) | DNS credential handed in at install |
| setting: file_signed_url_key | keys | sealed (core); secret setting (core) | File URL signing key |
| setting: hcaptcha_private | keys | secret setting (core) | hCaptcha secret key |
| setting: joinery_ai_anthropic_api_key | keys | secret setting (joinery_ai) | Anthropic API Key |
| setting: joinery_ai_brave_search_api_key | keys | secret setting (joinery_ai) | Brave Search API Key |
| setting: joinery_ai_fireworks_api_key | keys | secret setting (joinery_ai) | Fireworks API Key |
| setting: joinery_ai_local_api_key | keys | secret setting (joinery_ai) | Local API Key |
| setting: joinery_ai_market_data_api_key | keys | secret setting (joinery_ai) | Market Data API Key (Finnhub) |
| setting: joinery_direct_decoy_secret | keys | sealed (core) | Joinery Direct decoy secret |
| setting: mailbox_fleet_api_secret_key | keys | sealed (mailbox); secret setting (mailbox) | Relay fleet API secret key |
| setting: mailbox_forwarding_smtp_password | keys | secret setting (mailbox) | Forwarding SMTP Password |
| setting: mailbox_srs_secret | keys | secret setting (mailbox) | SRS Secret |
| setting: mailchimp_api_key | keys | secret setting (core) | MailChimp API key |
| setting: mailgun_api_key | keys | secret setting (core) | Mailgun API key |
| setting: mailgun_webhook_signing_key | keys | secret setting (core) | Mailgun Webhook Signing Key |
| setting: mailjet_api_secret | keys | secret setting (core) | Mailjet API secret |
| setting: oauth_digitalocean_client_secret | keys | sealed (core); secret setting (core) | DigitalOcean OAuth client secret |
| setting: oauth_dnsimple_client_secret | keys | sealed (core); secret setting (core) | DNSimple OAuth client secret |
| setting: oauth_google_client_secret | keys | sealed (core); secret setting (core) | Google OAuth client secret |
| setting: oauth_linode_client_secret | keys | sealed (core); secret setting (core) | Linode OAuth client secret |
| setting: oauth_microsoft_client_secret | keys | sealed (core); secret setting (core) | Microsoft OAuth client secret |
| setting: paypal_api_secret | keys | secret setting (store) | PayPal client secret |
| setting: paypal_api_secret_test | keys | secret setting (store) | Test PayPal client secret |
| setting: persona_browser_token | keys | secret setting (persona_browser) | Service Token |
| setting: postmark_server_token | keys | secret setting (core) | Postmark server token |
| setting: push_vapid_keys | keys | sealed (core); secret setting (core) | Browser push key pair |
| setting: resend_api_key | keys | secret setting (core) | Resend API key |
| setting: sendgrid_api_key | keys | secret setting (core) | SendGrid API key |
| setting: sendgrid_inbound_secret | keys | secret setting (core) | Inbound Parse Shared Secret |
| setting: server_manager_getjoinery_api_secret_key | keys | sealed (server_manager); secret setting (server_manager) | getjoinery provisioning API secret |
| setting: server_manager_namecheap_api_key | keys | sealed (server_manager); secret setting (server_manager) | Namecheap API key |
| setting: server_manager_namecheap_promotion_code | keys | sealed (server_manager); secret setting (server_manager) | Namecheap promotion code |
| setting: server_manager_smtp2go_api_key | keys | sealed (server_manager); secret setting (server_manager) | SMTP2GO master API key |
| setting: server_manager_smtp2go_webhook_secret | keys | sealed (server_manager); secret setting (server_manager) | SMTP2GO webhook secret |
| setting: services_api_secret_key | keys | sealed (core); secret setting (core) | Services API secret key |
| setting: ses_secret_access_key | keys | secret setting (core) | AWS secret access key |
| setting: session:mailbox_imap_connect_stash | keys | sealed (mailbox) | IMAP connect wizard token |
| setting: smtp2go_api_key | keys | secret setting (core) | SMTP2GO API key |
| setting: smtp_password | keys | secret setting (core) | Password |
| setting: store_app_store_private_key | keys | secret setting (store) | App Store private key |
| setting: store_play_service_account_json | keys | secret setting (store) | Play service account JSON |
| setting: stripe_api_pkey | keys | secret setting (store) | Stripe secret key |
| setting: stripe_api_pkey_test | keys | secret setting (store) | Test Stripe secret key |
| setting: stripe_endpoint_secret | keys | secret setting (store) | Stripe Endpoint Secret |

66 further name matches were reviewed and are not secrets (seed class "none").

### 8.7 Actions

Printed by `permissions_inventory.php --table=actions`. One row per
`*_logic_descriptor()` (358 in the tree; the spec's earlier figure of 370 was an
estimate).

- **Today min_user_permission** is what the descriptor declares, `0` if absent.
- **Models written** is what the logic file writes. `?` means it writes but names
  no model in a way the tool can read.
- **Callers** decide first. A member action (reached only from member pages) is
  `0` and the model's owner rule is the gate (3.5). An action an admin page, the
  AI assistant or a shared include reaches keeps its class level. A seed can
  pin a level or mark an action as a member's own (`member_own`).
- **Mutating on a read floor** is printed with `!` and must be fixed; the count
  is in the footer.
- **Proposed level** is `class:verb` when the action reads or writes one class of
  row. It is an integer when it is a side effect with no row (a probe, a send, a
  backup, a publish) or destructive or key-changing (10, Q8). It is `0` for
  public sign-in and recovery actions, where the logic's own proof is the gate.
- **Confidence** as in 8.5. Most action levels are `instinct`; the seed names the
  judgment for each.


| Action | File | Today min_user_permission | mutates | Models written | Proposed level | Confidence |
|---|---|---|---|---|---|---|
| account_edit | logic/account_edit_logic.php | 0 | true | User, ? | 0 | read |
| account_summary | plugins/dns_filtering/logic/account_summary_logic.php | 0 | - | - | 0 | read |
| address_edit | logic/address_edit_logic.php | 0 | true | Address, ? | 0 | read |
| admin_booking_type_edit | plugins/bookings/logic/admin_booking_type_edit_logic.php | 0 | true | ? | content:write | instinct |
| ai_action_resolve | plugins/joinery_ai/logic/ai_action_resolve_logic.php | 0 | true | - | personal:write | instinct |
| ai_actions_list | plugins/joinery_ai/logic/ai_actions_list_logic.php | 0 | false | - | personal:read | instinct |
| ai_device_recipes | plugins/mailbox/logic/ai_device_recipes_logic.php | 0 | - | - | 0 | read |
| ai_device_record | plugins/mailbox/logic/ai_device_record_logic.php | 0 | - | - | 0 | read |
| ai_panel_state | plugins/joinery_ai/logic/ai_panel_state_logic.php | 0 | false | - | personal:read | instinct |
| ai_panel_toggle | plugins/joinery_ai/logic/ai_panel_toggle_logic.php | 0 | true | - | personal:write | instinct |
| ai_status | plugins/joinery_ai/logic/ai_status_logic.php | 0 | false | - | personal:read | instinct |
| app_credential_revoke | logic/app_credential_revoke_logic.php | 0 | - | ? | 0 | read |
| app_password_create | logic/app_password_create_logic.php | 0 | - | - | 0 | read |
| app_store_claim | plugins/store/logic/app_store_claim_logic.php | 0 | true | - | 0 | read |
| attachment_text | plugins/mailbox/logic/attachment_text_logic.php | 0 | - | - | 8 | instinct |
| availability_preview | logic/availability_preview_logic.php | 0 | false | - | 0 | read |
| backup_actions | plugins/server_manager/logic/backup_actions_logic.php | 10 | true | - | 8 | instinct |
| backup_recovery_prove | logic/backup_recovery_prove_logic.php | 0 | true | - | 0 | read |
| backup_recovery_save | logic/backup_recovery_save_logic.php | 0 | true | - | 0 | read |
| billing_catalog | plugins/store/logic/billing_catalog_logic.php | 0 | false | - | 0 | read |
| block_filter_set | plugins/dns_filtering/logic/block_filter_set_logic.php | 0 | true | SdScheduledBlockService | 0 | read |
| block_list | plugins/dns_filtering/logic/block_list_logic.php | 0 | - | - | 0 | read |
| block_rule_add | plugins/dns_filtering/logic/block_rule_add_logic.php | 0 | true | - | 0 | read |
| block_rule_delete | plugins/dns_filtering/logic/block_rule_delete_logic.php | 0 | true | - | 0 | read |
| boards_board | plugins/boards/logic/boards_board_logic.php | 0 | false | - | 0 | read |
| boards_board_save | plugins/boards/logic/boards_board_save_logic.php | 0 | true | ? | 0 | read |
| boards_card | plugins/boards/logic/boards_card_logic.php | 0 | false | - | 0 | read |
| boards_card_move | plugins/boards/logic/boards_card_move_logic.php | 0 | true | - | 0 | read |
| boards_card_save | plugins/boards/logic/boards_card_save_logic.php | 0 | true | BoardCard, ? | 0 | read |
| boards_checklist_save | plugins/boards/logic/boards_checklist_save_logic.php | 0 | true | ? | 0 | read |
| boards_comment_save | plugins/boards/logic/boards_comment_save_logic.php | 0 | true | - | 0 | read |
| boards_email | plugins/boards/logic/boards_email_logic.php | 0 | true | - | 0 | read |
| boards_import | plugins/boards/logic/boards_import_logic.php | 0 | true | - | 0 | read |
| boards_label_save | plugins/boards/logic/boards_label_save_logic.php | 0 | true | - | 0 | read |
| boards_list_move | plugins/boards/logic/boards_list_move_logic.php | 0 | true | - | 0 | read |
| boards_list_save | plugins/boards/logic/boards_list_save_logic.php | 0 | true | - | 0 | read |
| boards_members_save | plugins/boards/logic/boards_members_save_logic.php | 0 | true | - | 0 | read |
| boards_my_cards | plugins/boards/logic/boards_my_cards_logic.php | 0 | false | - | 0 | read |
| boards_poll | plugins/boards/logic/boards_poll_logic.php | 0 | false | - | 0 | read |
| boards_upload | plugins/boards/logic/boards_upload_logic.php | 0 | true | ? | 0 | read |
| booking | logic/booking_logic.php | 0 | - | - | 0 | read |
| booking_slots | plugins/bookings/logic/booking_slots_logic.php | 0 | false | - | 0 | read |
| calendar_entry | logic/calendar_entry_logic.php | 0 | - | - | 0 | read |
| calendar_entry_delete | logic/calendar_entry_delete_logic.php | 0 | true | CalendarEntry | 0 | read |
| calendar_entry_save | logic/calendar_entry_save_logic.php | 0 | true | ? | 0 | read |
| calendar_feed | logic/calendar_feed_logic.php | 0 | false | - | 0 | read |
| calendar_level_batch | logic/calendar_level_batch_logic.php | 0 | true | - | 0 | read |
| calendar_level_change | logic/calendar_level_change_logic.php | 0 | true | - | 0 | read |
| calendar_settings | logic/calendar_settings_logic.php | 0 | true | CalendarPreference | 0 | read |
| cart | plugins/store/logic/cart_logic.php | 0 | - | - | 0 | read |
| cart_clear | plugins/store/logic/cart_clear_logic.php | 0 | true | - | 0 | read |
| catalog | plugins/dns_filtering/logic/catalog_logic.php | 0 | - | - | 0 | read |
| change_password_required | logic/change_password_required_logic.php | 0 | true | User, ? | 0 | read |
| change_tier | plugins/store/logic/change_tier_logic.php | 0 | true | Order, OrderItem, ? | 0 | read |
| chat_controls | plugins/joinery_ai/logic/chat_controls_logic.php | 0 | - | - | 0 | read |
| chat_level_batch | plugins/joinery_ai/logic/chat_level_batch_logic.php | 0 | true | - | personal:write | instinct |
| chat_list | plugins/joinery_ai/logic/chat_list_logic.php | 0 | - | - | 0 | read |
| chat_poll | plugins/joinery_ai/logic/chat_poll_logic.php | 0 | - | - | 0 | read |
| chat_send | plugins/joinery_ai/logic/chat_send_logic.php | 0 | - | AiConversationMessage | 0 | read |
| chat_set_capabilities | plugins/joinery_ai/logic/chat_set_capabilities_logic.php | 0 | - | AiConversation | config:write | traced |
| chat_thread | plugins/joinery_ai/logic/chat_thread_logic.php | 0 | - | - | 0 | read |
| chat_thread_action | plugins/joinery_ai/logic/chat_thread_action_logic.php | 0 | - | AiConversation, ? | 0 | read |
| chat_turn_action | plugins/joinery_ai/logic/chat_turn_action_logic.php | 0 | - | AiConversationMessage | 0 | read |
| check_mail | plugins/mailbox/logic/check_mail_logic.php | 0 | - | InboundImapAccount | 0 | read |
| checkout | plugins/store/logic/checkout_logic.php | 0 | - | - | 0 | read |
| checkout_apply_coupon | plugins/store/logic/checkout_apply_coupon_logic.php | 0 | true | - | 0 | read |
| checkout_check_email | plugins/store/logic/checkout_check_email_logic.php | 0 | false | - | 0 | read |
| checkout_remove_coupon | plugins/store/logic/checkout_remove_coupon_logic.php | 0 | true | - | 0 | read |
| checkout_submit_survey | plugins/event_manager/logic/checkout_submit_survey_logic.php | 0 | true | SurveyAnswer, EventRegistrant | 0 | read |
| consent_record | logic/consent_record_logic.php | 0 | true | - | 0 | read |
| contact_delete | plugins/mailbox/logic/contact_delete_logic.php | 0 | - | - | 0 | read |
| contact_preferences | logic/contact_preferences_logic.php | 0 | true | - | 0 | read |
| contact_save | plugins/mailbox/logic/contact_save_logic.php | 0 | - | - | 0 | read |
| contacts | plugins/mailbox/logic/contacts_logic.php | 0 | - | - | 0 | read |
| contacts_import | plugins/mailbox/logic/contacts_import_logic.php | 0 | - | - | 0 | read |
| content_report | logic/content_report_logic.php | 0 | true | - | 0 | read |
| conversation_action | logic/conversation_action_logic.php | 0 | - | ? | 0 | read |
| conversation_list | logic/conversation_list_logic.php | 0 | - | - | 0 | read |
| conversation_send | logic/conversation_send_logic.php | 0 | - | - | 0 | read |
| conversation_thread | logic/conversation_thread_logic.php | 0 | - | ? | 0 | read |
| create_calendar_entry | logic/create_calendar_entry_logic.php | 0 | true | - | 0 | read |
| desk_ask | plugins/support_desk/logic/desk_ask_logic.php | 0 | true | - | 0 | read |
| desk_cancel | plugins/support_desk/logic/desk_cancel_logic.php | 0 | true | AiConversationMessage | 0 | read |
| desk_escalate | plugins/support_desk/logic/desk_escalate_logic.php | 0 | true | - | 0 | read |
| desk_feedback | plugins/support_desk/logic/desk_feedback_logic.php | 0 | true | AiConversationMessage | 0 | read |
| desk_poll | plugins/support_desk/logic/desk_poll_logic.php | 0 | - | - | 0 | read |
| desk_threads | plugins/support_desk/logic/desk_threads_logic.php | 0 | - | SupportThread | 0 | read |
| device_ai_entries | plugins/mailbox/logic/device_ai_entries_logic.php | 0 | - | - | 0 | read |
| device_ai_host | plugins/mailbox/logic/device_ai_host_logic.php | 0 | - | - | 0 | read |
| device_ai_test_prompt | plugins/mailbox/logic/device_ai_test_prompt_logic.php | 0 | - | - | 0 | read |
| device_ai_verdict | plugins/mailbox/logic/device_ai_verdict_logic.php | 0 | - | - | 0 | read |
| device_delete | plugins/dns_filtering/logic/device_delete_logic.php | 0 | - | SdDevice | 0 | read |
| device_edit | plugins/dns_filtering/logic/device_edit_logic.php | 0 | - | SdDevice | 0 | read |
| device_key_enroll | logic/device_key_enroll_logic.php | 0 | true | DeviceLink | 0 | read |
| device_rules | plugins/mailbox/logic/device_rules_logic.php | 0 | false | - | 0 | read |
| device_soft_delete | plugins/dns_filtering/logic/device_soft_delete_logic.php | 0 | - | SdDevice | 0 | read |
| devices | plugins/dns_filtering/logic/devices_logic.php | 0 | - | - | 0 | read |
| devices_link | logic/devices_link_logic.php | 0 | false | - | 0 | read |
| direct_status | plugins/mailbox/logic/direct_status_logic.php | 0 | - | - | config:read | traced |
| domain_check | plugins/server_manager/logic/domain_check_logic.php | 0 | false | - | 8 | instinct |
| domain_setup_check | logic/domain_setup_check_logic.php | 0 | false | - | 0 | read |
| draft_attachment_delete | plugins/mailbox/logic/draft_attachment_delete_logic.php | 0 | - | - | 0 | read |
| draft_delete | plugins/mailbox/logic/draft_delete_logic.php | 0 | - | - | 0 | read |
| draft_get | plugins/mailbox/logic/draft_get_logic.php | 0 | - | - | 0 | read |
| draft_save | plugins/mailbox/logic/draft_save_logic.php | 0 | - | - | 0 | read |
| drive_changes | logic/drive_changes_logic.php | 0 | false | - | 0 | read |
| drive_delete_forever | logic/drive_delete_forever_logic.php | 0 | true | - | 0 | read |
| drive_device_link_approve | logic/drive_device_link_approve_logic.php | 0 | true | SyncDevice, DeviceLink | 0 | read |
| drive_device_link_deny | logic/drive_device_link_deny_logic.php | 0 | true | DeviceLink | 0 | read |
| drive_device_link_info | logic/drive_device_link_info_logic.php | 0 | false | - | 0 | read |
| drive_device_rename | logic/drive_device_rename_logic.php | 0 | true | SyncDevice | 0 | read |
| drive_device_revoke | logic/drive_device_revoke_logic.php | 0 | true | ApiKey, SyncDevice | 0 | read |
| drive_devices | logic/drive_devices_logic.php | 0 | false | - | 0 | read |
| drive_folder_create | logic/drive_folder_create_logic.php | 0 | true | - | 0 | read |
| drive_index | logic/drive_index_logic.php | 0 | false | - | 0 | read |
| drive_key_grants | logic/drive_key_grants_logic.php | 0 | false | - | 0 | read |
| drive_key_grants_reseal | logic/drive_key_grants_reseal_logic.php | 0 | true | FileKeyGrant | 0 | read |
| drive_key_grants_sync | logic/drive_key_grants_sync_logic.php | 0 | true | - | 0 | read |
| drive_level_batch | logic/drive_level_batch_logic.php | 0 | true | - | 0 | read |
| drive_level_change | logic/drive_level_change_logic.php | 0 | true | - | 0 | read |
| drive_link_create | logic/drive_link_create_logic.php | 0 | true | - | 0 | read |
| drive_link_revoke | logic/drive_link_revoke_logic.php | 0 | true | - | 0 | read |
| drive_list | logic/drive_list_logic.php | 0 | false | - | 0 | read |
| drive_move | logic/drive_move_logic.php | 0 | true | - | 0 | read |
| drive_public_keys | logic/drive_public_keys_logic.php | 0 | false | - | 0 | read |
| drive_rename | logic/drive_rename_logic.php | 0 | true | ? | 0 | read |
| drive_restore | logic/drive_restore_logic.php | 0 | true | - | 0 | read |
| drive_share_sync | logic/drive_share_sync_logic.php | 0 | true | - | 0 | read |
| drive_shares | logic/drive_shares_logic.php | 0 | false | - | 0 | read |
| drive_stat | logic/drive_stat_logic.php | 0 | false | - | 0 | read |
| drive_trash | logic/drive_trash_logic.php | 0 | true | ? | 0 | read |
| drive_upload_complete | logic/drive_upload_complete_logic.php | 0 | true | FileKeyGrant, ? | 0 | read |
| drive_upload_init | logic/drive_upload_init_logic.php | 0 | true | FileUpload, ? | 0 | read |
| drive_version_restore | logic/drive_version_restore_logic.php | 0 | true | - | 0 | read |
| drive_versions | logic/drive_versions_logic.php | 0 | false | - | 0 | read |
| email_available | logic/email_available_logic.php | 5 | false | - | 0 | read |
| entries_list | plugins/vault/logic/entries_list_logic.php | 0 | - | - | 0 | read |
| entry_delete | plugins/vault/logic/entry_delete_logic.php | 0 | - | VaultEntry | 0 | read |
| entry_restore | plugins/vault/logic/entry_restore_logic.php | 0 | - | VaultEntry | 0 | read |
| entry_save | plugins/vault/logic/entry_save_logic.php | 0 | - | VaultEntry, ? | 0 | read |
| event_register | plugins/event_manager/logic/event_register_logic.php | 0 | true | - | 0 | read |
| event_sessions | plugins/event_manager/logic/event_sessions_logic.php | 0 | - | - | 0 | read |
| event_sessions_course | plugins/event_manager/logic/event_sessions_course_logic.php | 0 | - | - | 0 | read |
| event_waiting_list | plugins/event_manager/logic/event_waiting_list_logic.php | 0 | true | User, WaitingList | 0 | read |
| event_withdraw | plugins/event_manager/logic/event_withdraw_logic.php | 0 | true | - | 0 | read |
| feed_allow_sender | plugins/persona_browser/logic/feed_allow_sender_logic.php | 0 | true | - | 0 | read |
| feed_block_sender | plugins/persona_browser/logic/feed_block_sender_logic.php | 0 | true | - | 0 | read |
| feed_hide_post | plugins/persona_browser/logic/feed_hide_post_logic.php | 0 | true | PersonaFeedItem | 0 | read |
| feed_hide_story | plugins/persona_browser/logic/feed_hide_story_logic.php | 0 | true | PersonaStory | 0 | read |
| feed_mark_ad | plugins/persona_browser/logic/feed_mark_ad_logic.php | 0 | true | - | 0 | read |
| feed_mark_reviewed | plugins/persona_browser/logic/feed_mark_reviewed_logic.php | 0 | true | - | 0 | read |
| fleet_claim_domain | plugins/mailbox/logic/fleet_claim_domain_logic.php | 0 | true | - | 0 | read |
| fleet_enroll | plugins/mailbox/logic/fleet_enroll_logic.php | 0 | true | - | 10 | instinct |
| fleet_release | plugins/mailbox/logic/fleet_release_logic.php | 0 | true | - | 10 | instinct |
| fleet_status | plugins/mailbox/logic/fleet_status_logic.php | 0 | false | - | 0 | read |
| fleet_verify_domain | plugins/mailbox/logic/fleet_verify_domain_logic.php | 0 | true | - | config:write | instinct |
| fortress_backlog | plugins/mailbox/logic/fortress_backlog_logic.php | 0 | false | - | 0 | read |
| fortress_parse_store | plugins/mailbox/logic/fortress_parse_store_logic.php | 0 | - | - | 0 | read |
| fortress_pending | plugins/mailbox/logic/fortress_pending_logic.php | 0 | false | - | 0 | read |
| forum_like | plugins/forum/logic/forum_like_logic.php | 0 | true | - | 0 | read |
| forum_mark_read | plugins/forum/logic/forum_mark_read_logic.php | 0 | true | - | 0 | read |
| forum_post_create | plugins/forum/logic/forum_post_create_logic.php | 0 | true | - | 0 | read |
| forum_post_delete | plugins/forum/logic/forum_post_delete_logic.php | 0 | true | - | 0 | read |
| forum_post_edit | plugins/forum/logic/forum_post_edit_logic.php | 0 | true | - | 0 | read |
| forum_post_moderate | plugins/forum/logic/forum_post_moderate_logic.php | 0 | true | - | content:write | instinct |
| forum_post_source | plugins/forum/logic/forum_post_source_logic.php | 0 | false | - | 0 | read |
| forum_search | plugins/forum/logic/forum_search_logic.php | 0 | false | - | 0 | read |
| forum_topic_create | plugins/forum/logic/forum_topic_create_logic.php | 0 | true | - | 0 | read |
| forum_topic_set | plugins/forum/logic/forum_topic_set_logic.php | 0 | true | - | 0 | read |
| forum_upload | plugins/forum/logic/forum_upload_logic.php | 0 | true | - | 0 | read |
| forum_watch_set | plugins/forum/logic/forum_watch_set_logic.php | 0 | true | - | 0 | read |
| forward_confirm | plugins/mailbox/logic/forward_confirm_logic.php | 0 | true | - | 0 | read |
| image_list | logic/image_list_logic.php | 5 | false | - | 0 | read |
| incident_triage | plugins/server_manager/logic/incident_triage_logic.php | 10 | true | - | 8 | instinct |
| job_status | plugins/server_manager/logic/job_status_logic.php | 10 | true | - | 7 | traced |
| keyring_get | plugins/vault/logic/keyring_get_logic.php | 0 | - | - | 0 | read |
| keyring_replace | plugins/vault/logic/keyring_replace_logic.php | 0 | true | VaultKeyring | 0 | read |
| keyring_save | plugins/vault/logic/keyring_save_logic.php | 0 | - | VaultKeyring | 0 | read |
| login_2fa_passkey_options | logic/login_2fa_passkey_options_logic.php | 0 | - | - | 0 | read |
| login_2fa_passkey_verify | logic/login_2fa_passkey_verify_logic.php | 0 | - | - | 0 | read |
| mail_append | plugins/mail_apps/logic/mail_append_logic.php | 0 | - | - | 0 | read |
| mail_changes | plugins/mail_apps/logic/mail_changes_logic.php | 0 | - | - | 0 | read |
| mail_copy | plugins/mail_apps/logic/mail_copy_logic.php | 0 | - | - | 0 | read |
| mail_expunge | plugins/mail_apps/logic/mail_expunge_logic.php | 0 | - | - | 0 | read |
| mail_fetch | plugins/mail_apps/logic/mail_fetch_logic.php | 0 | - | - | 0 | read |
| mail_folder_create | plugins/mail_apps/logic/mail_folder_create_logic.php | 0 | - | - | 0 | read |
| mail_folder_delete | plugins/mail_apps/logic/mail_folder_delete_logic.php | 0 | - | - | 0 | read |
| mail_folder_rename | plugins/mail_apps/logic/mail_folder_rename_logic.php | 0 | - | - | 0 | read |
| mail_folders | plugins/mail_apps/logic/mail_folders_logic.php | 0 | - | - | 0 | read |
| mail_import_discard | plugins/mailbox/logic/mail_import_discard_logic.php | 0 | true | - | 0 | read |
| mail_import_select | plugins/mailbox/logic/mail_import_select_logic.php | 0 | true | - | 0 | read |
| mail_import_start | plugins/mailbox/logic/mail_import_start_logic.php | 0 | true | - | 0 | read |
| mail_import_status | plugins/mailbox/logic/mail_import_status_logic.php | 0 | false | - | 0 | read |
| mail_import_undo | plugins/mailbox/logic/mail_import_undo_logic.php | 0 | true | - | 0 | read |
| mail_login | plugins/mail_apps/logic/mail_login_logic.php | 0 | - | - | 0 | read |
| mail_move | plugins/mail_apps/logic/mail_move_logic.php | 0 | - | - | 0 | read |
| mail_search | plugins/mail_apps/logic/mail_search_logic.php | 0 | - | - | 0 | read |
| mail_store | plugins/mail_apps/logic/mail_store_logic.php | 0 | - | - | 0 | read |
| mail_submit | plugins/mail_apps/logic/mail_submit_logic.php | 0 | - | MailAppMessage, ? | 0 | read |
| mail_sync | plugins/mail_apps/logic/mail_sync_logic.php | 0 | - | - | 0 | read |
| mailboxes | plugins/mailbox/logic/mailboxes_logic.php | 0 | - | - | 0 | read |
| markdown_preview | logic/markdown_preview_logic.php | 0 | false | - | 0 | read |
| marketplace_catalog | logic/marketplace_catalog_logic.php | 10 | false | - | 8 | instinct |
| marketplace_install | logic/marketplace_install_logic.php | 10 | true | - | 10 | instinct |
| mention_search | logic/mention_search_logic.php | 0 | false | - | 0 | read |
| message_source | plugins/mailbox/logic/message_source_logic.php | 0 | - | - | 0 | read |
| message_timeline | plugins/mailbox/logic/message_timeline_logic.php | 0 | - | - | 0 | read |
| messenger_action | plugins/messenger/logic/messenger_action_logic.php | 0 | - | Message, Conversation, ? | 0 | read |
| messenger_group | plugins/messenger/logic/messenger_group_logic.php | 0 | - | EntityPhoto | 0 | read |
| messenger_people | plugins/messenger/logic/messenger_people_logic.php | 0 | - | - | 0 | read |
| messenger_poll | plugins/messenger/logic/messenger_poll_logic.php | 0 | - | - | 0 | read |
| messenger_send | plugins/messenger/logic/messenger_send_logic.php | 0 | - | ? | 0 | read |
| messenger_thread | plugins/messenger/logic/messenger_thread_logic.php | 0 | - | - | 0 | read |
| messenger_upload | plugins/messenger/logic/messenger_upload_logic.php | 0 | - | - | 0 | read |
| moved_site_check | plugins/server_manager/logic/moved_site_check_logic.php | 10 | true | - | 7 | traced |
| my_events | plugins/event_manager/logic/my_events_logic.php | 0 | - | - | 0 | read |
| notification_mark_all_read | logic/notification_mark_all_read_logic.php | 0 | true | Notification | 0 | read |
| notification_mark_read | logic/notification_mark_read_logic.php | 0 | true | Notification | 0 | read |
| notification_unread_count | logic/notification_unread_count_logic.php | 0 | false | - | 0 | read |
| order_list | plugins/store/logic/order_list_logic.php | 0 | - | - | 0 | read |
| orders_recurring_action | plugins/store/logic/orders_recurring_action_logic.php | 0 | true | - | 0 | read |
| passkey_client_report | logic/passkey_client_report_logic.php | 0 | - | - | restricted:read | instinct |
| passkey_lab_options | logic/passkey_lab_options_logic.php | 0 | - | - | personal:read | traced |
| passkey_lab_report | logic/passkey_lab_report_logic.php | 0 | - | - | restricted:read | instinct |
| passkey_lab_verify | logic/passkey_lab_verify_logic.php | 0 | - | - | personal:read | traced |
| passkey_login_options | logic/passkey_login_options_logic.php | 0 | - | - | 0 | read |
| passkey_login_verify | logic/passkey_login_verify_logic.php | 0 | - | - | 0 | read |
| passkey_register_options | logic/passkey_register_options_logic.php | 0 | - | - | 0 | read |
| passkey_register_verify | logic/passkey_register_verify_logic.php | 0 | - | UserEncryptionWrapping | 0 | read |
| passkey_rename | logic/passkey_rename_logic.php | 0 | - | - | 0 | read |
| passkey_revoke | logic/passkey_revoke_logic.php | 0 | - | - | 0 | read |
| passkey_stepup_options | logic/passkey_stepup_options_logic.php | 0 | - | - | 0 | read |
| passkey_stepup_verify | logic/passkey_stepup_verify_logic.php | 0 | - | - | 0 | read |
| password_edit | logic/password_edit_logic.php | 0 | true | User | 0 | read |
| password_reset_1 | logic/password_reset_1_logic.php | 0 | true | - | 0 | read |
| password_reset_2 | logic/password_reset_2_logic.php | 0 | true | ? | 0 | read |
| password_reset_2fa | logic/password_reset_2fa_logic.php | 0 | - | - | 0 | read |
| password_reset_2fa_passkey_options | logic/password_reset_2fa_passkey_options_logic.php | 0 | - | - | 0 | read |
| password_reset_2fa_passkey_verify | logic/password_reset_2fa_passkey_verify_logic.php | 0 | - | - | 0 | read |
| password_reset_passkey_options | logic/password_reset_passkey_options_logic.php | 0 | - | - | 0 | read |
| password_reset_passkey_verify | logic/password_reset_passkey_verify_logic.php | 0 | - | - | 0 | read |
| password_reset_totp | logic/password_reset_totp_logic.php | 0 | - | - | 0 | read |
| password_set | logic/password_set_logic.php | 0 | true | User | 0 | read |
| phone_numbers_edit | logic/phone_numbers_edit_logic.php | 0 | true | PhoneNumber, ? | 0 | read |
| play_claim | plugins/store/logic/play_claim_logic.php | 0 | true | - | 0 | read |
| plugin_provisioning_check | logic/plugin_provisioning_check_logic.php | 5 | false | - | 8 | instinct |
| private_ai_consent | logic/private_ai_consent_logic.php | 0 | true | - | 0 | read |
| probe_api | plugins/server_manager/logic/probe_api_logic.php | 10 | false | - | 7 | traced |
| profile_dashboard | logic/profile_dashboard_logic.php | 0 | - | - | 0 | read |
| purge_querylog | plugins/dns_filtering/logic/purge_querylog_logic.php | 0 | true | - | 0 | read |
| push_config | logic/push_config_logic.php | 0 | false | - | 0 | read |
| push_subscribe | logic/push_subscribe_logic.php | 0 | true | - | 0 | read |
| push_test | logic/push_test_logic.php | 0 | true | - | 0 | read |
| push_unsubscribe | logic/push_unsubscribe_logic.php | 0 | true | - | 0 | read |
| querylog | plugins/dns_filtering/logic/querylog_logic.php | 0 | - | - | 0 | read |
| reaction_count | logic/reaction_count_logic.php | 0 | false | - | 0 | read |
| reaction_status | logic/reaction_status_logic.php | 0 | false | - | 0 | read |
| reaction_toggle | logic/reaction_toggle_logic.php | 0 | true | - | 0 | read |
| refresh_node_status | plugins/server_manager/logic/refresh_node_status_logic.php | 10 | true | ManagedNode | 7 | traced |
| register | logic/register_logic.php | 0 | true | User | 0 | traced |
| relay_pin_set | plugins/mailbox/logic/relay_pin_set_logic.php | 0 | - | - | 0 | read |
| relay_pins | plugins/mailbox/logic/relay_pins_logic.php | 0 | false | - | 0 | read |
| relay_seal_target | plugins/mailbox/logic/relay_seal_target_logic.php | 0 | false | - | 0 | read |
| release_notes_draft | plugins/server_manager/logic/release_notes_draft_logic.php | 10 | false | - | 8 | instinct |
| releases | plugins/server_manager/logic/releases_logic.php | 0 | false | - | 0 | read |
| report_problem_submit | logic/report_problem_submit_logic.php | 0 | true | - | 0 | read |
| report_submit | plugins/bug_reports/logic/report_submit_logic.php | 0 | true | - | 0 | read |
| resolver_key_issue | plugins/dns_filtering/logic/resolver_key_issue_logic.php | 8 | true | - | keys:write | instinct |
| resolver_key_revoke | plugins/dns_filtering/logic/resolver_key_revoke_logic.php | 8 | true | - | 10 | instinct |
| resolver_snapshot | plugins/dns_filtering/logic/resolver_snapshot_logic.php | 0 | false | - | 8 | instinct |
| root_request_status | logic/root_request_status_logic.php | 0 | false | - | config:read | instinct |
| rule_backlog | plugins/mailbox/logic/rule_backlog_logic.php | 0 | true | - | 0 | read |
| rule_outcomes | plugins/mailbox/logic/rule_outcomes_logic.php | 0 | true | - | 0 | read |
| sandbox_page | logic/sandbox_page_logic.php | 0 | true | - | 0 | read |
| scan_url | plugins/dns_filtering/logic/scan_url_logic.php | 0 | false | - | 0 | read |
| scheduled_block_edit | plugins/dns_filtering/logic/scheduled_block_edit_logic.php | 0 | - | SdScheduledBlock, ? | 0 | read |
| seal_batch | plugins/mailbox/logic/seal_batch_logic.php | 0 | - | - | 0 | read |
| search_entries | plugins/mailbox/logic/search_entries_logic.php | 0 | false | - | 0 | read |
| search_key | plugins/mailbox/logic/search_key_logic.php | 0 | - | - | 0 | read |
| security | logic/security_logic.php | 0 | true | User, ApiKey, SyncDevice, ? | 0 | read |
| security_overview | logic/security_overview_logic.php | 0 | - | - | 0 | read |
| send | plugins/mailbox/logic/send_logic.php | 0 | - | - | 8 | instinct |
| sender_context | plugins/mailbox/logic/sender_context_logic.php | 0 | - | - | 0 | read |
| services_enroll | plugins/server_manager/logic/services_enroll_logic.php | 0 | true | - | 0 | read |
| services_release | plugins/server_manager/logic/services_release_logic.php | 0 | true | - | 0 | read |
| services_status | plugins/server_manager/logic/services_status_logic.php | 0 | false | - | 0 | read |
| setup_https_check | logic/setup_https_check_logic.php | 0 | false | - | 0 | read |
| setup_status | plugins/mailbox/logic/setup_status_logic.php | 0 | false | - | config:read | instinct |
| shelf_begin_run | plugins/server_manager/logic/shelf_begin_run_logic.php | 0 | true | - | 0 | read |
| shelf_finish_run | plugins/server_manager/logic/shelf_finish_run_logic.php | 0 | true | - | 0 | read |
| shelf_list | plugins/server_manager/logic/shelf_list_logic.php | 0 | false | - | 0 | read |
| shelf_sign | plugins/server_manager/logic/shelf_sign_logic.php | 0 | true | - | 0 | read |
| shelf_status | plugins/server_manager/logic/shelf_status_logic.php | 0 | false | - | 0 | read |
| shelf_verified_run | plugins/server_manager/logic/shelf_verified_run_logic.php | 0 | true | - | 0 | read |
| signature_save | plugins/mailbox/logic/signature_save_logic.php | 0 | - | - | 0 | read |
| subscription_summary | plugins/store/logic/subscription_summary_logic.php | 0 | false | - | 0 | read |
| support_cancel | logic/support_cancel_logic.php | 5 | true | - | 0 | read |
| support_consent | logic/support_consent_logic.php | 5 | true | Setting | 0 | read |
| support_escalate | logic/support_escalate_logic.php | 5 | true | - | 0 | read |
| support_feedback | logic/support_feedback_logic.php | 5 | true | - | 0 | read |
| support_origin_confirm | logic/support_origin_confirm_logic.php | 0 | true | - | 8 | instinct |
| support_poll | logic/support_poll_logic.php | 5 | false | - | 0 | read |
| support_send | logic/support_send_logic.php | 5 | true | - | 0 | read |
| support_state | logic/support_state_logic.php | 5 | - | - | 0 | read |
| support_threads | logic/support_threads_logic.php | 5 | - | - | 0 | read |
| survey | logic/survey_logic.php | 0 | - | SurveyAnswer | 0 | read |
| terms_accept | logic/terms_accept_logic.php | 0 | true | User | 0 | read |
| test_connection | plugins/joinery_ai/logic/test_connection_logic.php | 0 | false | - | 8 | instinct |
| test_domain | plugins/dns_filtering/logic/test_domain_logic.php | 0 | false | - | 0 | read |
| tests_run | logic/tests_run_logic.php | 10 | - | - | 10 | instinct |
| theme_switch | logic/theme_switch_logic.php | 10 | true | - | 10 | instinct |
| thread | plugins/mailbox/logic/thread_logic.php | 0 | - | - | 0 | read |
| thread_action | plugins/mailbox/logic/thread_action_logic.php | 0 | - | - | 0 | read |
| thread_list | plugins/mailbox/logic/thread_list_logic.php | 0 | - | - | 0 | read |
| transfer_customer | plugins/server_manager/logic/transfer_customer_logic.php | 0 | true | - | 0 | read |
| transfer_operator | plugins/server_manager/logic/transfer_operator_logic.php | 10 | true | InstanceTransfer | 10 | instinct |
| unseal_batch | plugins/mailbox/logic/unseal_batch_logic.php | 0 | - | - | 0 | read |
| upload_complete | logic/upload_complete_logic.php | 0 | true | - | 0 | read |
| upload_init | logic/upload_init_logic.php | 0 | true | - | 0 | read |
| user_search | logic/user_search_logic.php | 5 | false | - | personal:read | instinct |
| validate_server_file | logic/validate_server_file_logic.php | 5 | false | - | config:read | instinct |
| vault_add_passkey_options | logic/vault_add_passkey_options_logic.php | 0 | - | - | 0 | read |
| vault_add_passkey_verify | logic/vault_add_passkey_verify_logic.php | 0 | - | UserEncryptionWrapping | 0 | read |
| vault_client_add_wrapping | logic/vault_client_add_wrapping_logic.php | 0 | - | ? | 0 | read |
| vault_client_consume_recovery | logic/vault_client_consume_recovery_logic.php | 0 | - | UserEncryptionWrapping | 0 | read |
| vault_client_prf_options | logic/vault_client_prf_options_logic.php | 0 | - | - | 0 | read |
| vault_client_probe | logic/vault_client_probe_logic.php | 0 | false | - | 0 | read |
| vault_client_remove_wrapping | logic/vault_client_remove_wrapping_logic.php | 0 | - | UserEncryptionWrapping | 0 | read |
| vault_client_replace_recovery | logic/vault_client_replace_recovery_logic.php | 0 | - | ? | 0 | read |
| vault_client_reseal_rows | logic/vault_client_reseal_rows_logic.php | 0 | false | - | 0 | read |
| vault_client_resume | logic/vault_client_resume_logic.php | 0 | true | - | 0 | read |
| vault_client_rotate_begin | logic/vault_client_rotate_begin_logic.php | 0 | true | - | 0 | read |
| vault_client_rotate_commit | logic/vault_client_rotate_commit_logic.php | 0 | true | - | 0 | read |
| vault_client_setup | logic/vault_client_setup_logic.php | 0 | - | - | 0 | read |
| vault_client_status | logic/vault_client_status_logic.php | 0 | - | - | 0 | read |
| vault_custody_rows | logic/vault_custody_rows_logic.php | 0 | false | - | 0 | read |
| vault_deferred_work | logic/vault_deferred_work_logic.php | 0 | - | - | 0 | read |
| vault_heartbeat | logic/vault_heartbeat_logic.php | 0 | - | - | 0 | read |
| vault_lock | logic/vault_lock_logic.php | 0 | - | - | 0 | read |
| vault_passkey_deactivate | logic/vault_passkey_deactivate_logic.php | 0 | - | ? | 0 | read |
| vault_passphrase_enroll | logic/vault_passphrase_enroll_logic.php | 0 | - | ? | 0 | read |
| vault_passphrase_remove | logic/vault_passphrase_remove_logic.php | 0 | - | ? | 0 | read |
| vault_regenerate_codes | logic/vault_regenerate_codes_logic.php | 0 | - | - | 0 | read |
| vault_rotate_options | logic/vault_rotate_options_logic.php | 0 | - | - | 0 | read |
| vault_rotate_verify | logic/vault_rotate_verify_logic.php | 0 | - | - | 0 | read |
| vault_row_custody | logic/vault_row_custody_logic.php | 0 | true | - | 0 | read |
| vault_row_reseal | logic/vault_row_reseal_logic.php | 0 | true | - | 0 | read |
| vault_setup_options | logic/vault_setup_options_logic.php | 0 | - | - | 0 | read |
| vault_setup_passphrase | logic/vault_setup_passphrase_logic.php | 0 | - | - | 0 | read |
| vault_setup_verify | logic/vault_setup_verify_logic.php | 0 | - | - | 0 | read |
| vault_status | logic/vault_status_logic.php | 0 | - | - | 0 | read |
| vault_unlock_options | logic/vault_unlock_options_logic.php | 0 | - | - | 0 | read |
| vault_unlock_passkey | logic/vault_unlock_passkey_logic.php | 0 | - | ? | 0 | read |
| vault_unlock_passphrase | logic/vault_unlock_passphrase_logic.php | 0 | - | - | 0 | read |
| vault_unlock_recovery | logic/vault_unlock_recovery_logic.php | 0 | - | - | 0 | read |
| verify_totp | logic/verify_totp_logic.php | 0 | true | - | 0 | read |
| widget_ask | plugins/support_desk/logic/widget_ask_logic.php | 0 | true | - | 0 | read |
| widget_poll | plugins/support_desk/logic/widget_poll_logic.php | 0 | - | - | 0 | read |

Member actions at 0 (called only from member pages): 192. Mutating actions on a :read floor (!): 0.
