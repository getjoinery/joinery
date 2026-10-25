# Permissions

Who may do what is one ladder of levels and one decision. Code says what its
data is (a class); one file says which level each class needs. A plugin moved
to another site carries its facts, and that site's file decides the numbers.

## The levels and the classes

Levels run from 0 (a signed-in member) to 10, and each includes the ones
below it. `permissions.json` at the `public_html/` root names them and gives
each class the level its four verbs need:

| Level | Name |
|---|---|
| 0 | Regular User |
| 5 | Assistant |
| 6 | Editor |
| 7 | Manager |
| 8 | Admin |
| 9 | Senior Admin |
| 10 | Master Admin |

| Class | read | write | soft_delete | permanent_delete | Covers |
|---|---|---|---|---|---|
| content | 5 | 6 | 7 | 9 | Pages, posts, events, forum, surveys, templates, components, groups, aggregate reports |
| money | 5 | 7 | 7 | 9 | Prices, tiers, coupons, product mappings |
| personal | 6 | 8 | 8 | 9 | Users, contact details, orders, bookings, messages, mailbox content, uploads, problem reports |
| config | 8 | 8 | 8 | 9 | Settings, menus, plugins, themes, scheduled tasks, nodes, releases, domains |
| restricted | 8 | 9 | 9 | 9 | Server, error and mail logs; stored passwords, always masked |
| keys | 10 | 10 | 10 | 10 | API, signing, encryption and backup keys; webhook, gateway and OAuth secrets |

The file is read at runtime, never seeded into a table. The user edit page's
level dropdown lists its levels, and its save refuses any other. Its schema
(`Access::schemaErrors()`): every level is 0 to 10 with a name, 0 and 10
among them; every class gives all four verbs a declared level, each verb at
least the one before it.

## Asking: `Access`

`includes/Access.php` makes every decision. A **requirement** is a level or a
class and a verb:

```php
Access::decide(8);                    // work with no row behind it: restart, send, publish
Access::decide('personal:write');     // read, write, soft_delete, permanent_delete
Access::level('money:soft_delete');   // 7
Access::allows('config:read', $permission);   // the comparison alone, no audit
Access::permits('personal:read');     // the session, not recorded: what a page shows
Access::demand(10);                   // decide(), throwing the page's 401 when refused
Access::staffLevel();                 // 5: the lowest level above a member's
```

- A malformed requirement throws `InvalidArgumentException`: an unknown class
  or verb, a level outside 0 to 10, a string `'8'`, null. A typo never reads
  as level 0.
- `decide()` compares with the **session's** level
  (`SessionControl::get_permission()`): the level the request was authorized
  at, which for an API call is the acting level `set_api_user()` simulates.
  It is never the user's stored `usr_permission`, which a login-as session or
  an IP-change-zeroed session is below. A browser session whose stored level
  was lowered takes the lower level at its next request, so a demotion holds
  at once; a raise holds from the next sign-in.
- `allows()` is the same comparison for a caller that already holds the level
  (`ApiAuth::authorize()` holds the acting level, a stored user's
  `usr_permission` is another person's).
- `permits()` is the session's comparison without the audit: for what a page
  shows (a column, a button, deleted rows in a list) or a choice that refuses
  nothing (a save that fills a field itself below the level). A refusal is
  `decide()` or `demand()`, so it is recorded.
- `demand()` is `decide()` for one action: refused, it throws
  `SystemAuthenticationError`, a 401 that names the level.
- Code never compares a level itself (`get_permission() >= 10`): it asks one
  of these, a row's rule (`$row->can_write($session)`), or a model's level
  (`Model::access_level('soft_delete')`).

`decide()` records to the event log (`evl_event_logs`), as the server's own
write so a GET may make it:

| Event | When |
|---|---|
| `access_refused` | a signed-in user is refused (an anonymous refusal is a sign-in page and is not recorded) |
| `access_granted` | allowed work that needs 9 or more, and any allowed use of the keys class, reads included |

The note reads `personal:permanent_delete (needs 9, has 10) POST /admin/...`.

## Declaring

| What | Declaration | Enforced by |
|---|---|---|
| An API action | `'auth' => ['min_user_permission' => 'personal:write']`, or a level; every descriptor declares one (a builder such as `ForumAction::memberDescriptor()` may) | `ApiAuth::authorize()` |
| An admin page | `/** @joinery-admin class=personal */` (its class's read level), or `/** @joinery-admin level=8 */` | the router (Pages, below) |
| A model | `public static $access_class = 'personal';`; exceptions `$read_permission`, `$write_permission`, `$delete_permission` (soft delete), `$permanent_delete_permission`; `$owner_column` when the owner is not `{prefix}_usr_user_id` | every write path and every read surface (Models, below) |
| A column stricter than its model | `'access_class' => 'keys'` in its `$field_specifications` entry | `save()` and `updateColumns()` (Models, below) |

A page's header is read from the first 4 KB of its file, so it goes at the
top. An action's level reads the acting level (above). An action a member runs on
their own rows declares 0, and the model's owner rule is its gate. A
mutating action never carries a `:read` level.

`AccessDeclarations` (`includes/AccessDeclarations.php`) reads every
declaration from the source on disk, so an inactive plugin is read too:
`pages()`, `models()`, `actions()`, and `problems()`, the declaration test's
rules.

## Pages

An admin page's first line after `<?php` is its header:

```php
/** @joinery-admin class=personal */   // its class's read level
/** @joinery-admin level=8 */          // a number, for a page with no class
```

- **The router checks it** (`AdminPageAccess`, from `RouteHelper::processRoutes()`)
  before any page code runs, for `/admin/{name}` (`adm/`),
  `/admin/{plugin}/{name}` (a plugin's `views/admin/`),
  `/plugins/{plugin}/admin/{name}` (a plugin's `admin/`), and a plugin's
  dynamic route inside `/admin/` (its `view` file). Signed out, the sign-in
  page; below the level, a 401. Undeclared, or a header that does not parse,
  needs 10.
- **A route opts out** with `'admin_page_check' => false` on the route:
  `/admin/server_manager/publish_theme`, which other sites fetch with no session.
- **Menus derive.** An admin or member menu entry shows to whoever may open the
  page it links to (`MultiAdminMenu::visible_entries()`); a section with no
  page shows when one of its entries does. `admin_menus.json`, a plugin's
  menus and the menu editor carry no permission.
- **A page never checks a level itself.** Its saves are checked by their
  models. One action that needs more than the page asks `Access::demand()`
  (installing code, running a task, a call to a payment provider before the
  save); a page whose every action needs more declares the higher level. The
  level is cached by the file's mtime.
- An inactive plugin's pages map to no file and 404, as every `/plugins/*`
  path does.

## Models

A model's class gives its rows four levels; `access_level($verb)` reads them,
a per-verb exception first:

```php
public static $access_class = 'personal';
public static $read_permission = 8;          // only for a verb that differs
public static $owner_column = 'aic_owner_user_id';   // or false: level-only
```

- **The owner, else the level.** The row's owner (`{prefix}_usr_user_id`, the
  model's `$owner_column`, or its `access_owner_is()` override for an owner
  that is not a column) may read, write and soft-delete their own row;
  destroying it (permanent delete) is the level's alone. Anyone else needs
  the model's level for the verb. A member's right to a row nobody owns (a
  conversation they take part in, a membership, a queued notification) is
  the flow's own rule: once its logic has checked it, the writes run inside
  `rule_checked_write()`, and before anyone is signed in inside
  `server_initiated_write()`. A model with no owner column is
  level-only, and `$owner_column = false` says the user column records who
  acted (a log, an error, a login), not who owns the row.
- **Undeclared is keys.** A model with no class needs 10 for everything, and
  the declaration test fails.
- **Asking.** `$obj->can_read($session)`, `can_write($session)`,
  `can_delete($session)` and `can_delete($session, true)` (permanent) return a
  bool; `assert_can_read()` and `assert_can_write()` throw.
  `authenticate_read()`, `authenticate_write()` and `authenticate_delete()` are
  the rules; a model with a real rule of its own (a conversation's
  participants, a board role, the user target rule) overrides one and compares
  levels through `Access`. The delete check runs the write rule first.
- **Columns.** A column stricter than its model names its class in its
  `$field_specifications` entry (`'access_class' => 'keys'`); a column a
  sealed-secrets locator names is keys without saying so
  (`column_access_class()`). A column is never looser than its model.
  `authenticate_columns()` refuses a save that changes such a column below its
  write level, naming the column; an unchanged value (a stale form) and the
  owner pass.

### Every write is checked

`save()`, `soft_delete()`, `undelete()`, `permanent_delete()` and
`updateColumns()` run the rule against the session on every web request:

- `save()` asks the write rule and the column rule; `soft_delete()` and
  `undelete()` the soft-delete level; `permanent_delete()` the permanent one.
- `updateColumns()` passes a caller at the model's write level who changes no
  stricter column without reading anything, unless the model has a write rule
  of its own; for anyone else it loads the row, stages the columns and asks the
  same rules `save()` asks.
- A permanent delete's cascade (a `permanent_delete` deletion rule) follows
  from the decision already made for the parent: its dependents are not asked
  again (they may be another person's).
- A model method that writes its row with its own SQL (a membership's
  `remove()`, an undelete that brings children back, cleanup a delete does
  before its parent's) asks `guard_raw_write($op)` first: the GET guard and
  the rule for `write`, `soft_delete` or `permanent_delete`. A `remove()` that
  deletes a membership row asks `soft_delete`: the membership ends and the
  person can join again.
- The owner rule reads a saved row's stored owner, not one `set()` gave it in
  the request: naming yourself the owner of another person's row is a change
  the model's level decides.
- The CLI, cron and scheduled tasks have no one signed in and are not
  checked. A test suite is: `harness_boot()` sets `Access::$check_cli_persons`,
  so a write the suite makes while a simulated person is signed in meets the
  check, refusing, exactly as that person's request would on the web.
- The REST record surface and every action run as their caller
  (`set_api_user()`), at the level the request was authorized at.

Two markers let a write past the check, and each caller is listed in
`tests/unit/core_api_mechanical_test.php`:

| Marker | For |
|---|---|
| `SystemBase::server_initiated_write()` | a write no person asked for: observation, reconciliation, a node's signed channel, a credential proved before anyone is signed in |
| `SystemBase::rule_checked_write()` | a write a rule of the caller's own has already admitted: the messenger posting a participant's message, a notification to another person, Drive's bookkeeping under a file the caller may change, a sign-up or password reset before anyone is signed in |

A request the server answers for itself sets `SystemBase::$allow_get_mutation`
for its whole length (a payment gateway's webhook, the inbound mail webhook),
and that passes the model check too: those requests have no person behind
them, only a signature the endpoint verified.

The checkout charge (`cart_charge_logic`) picks its marker by who sent the
request. A payment gateway's return (Stripe Checkout's `?session_id=`, PayPal's
`?id=` or `?subscription=`), which the handler verifies with the gateway, runs
inside `server_initiated_write()`. The buyer's own POST (a card charge, a free
order) runs inside `rule_checked_write()`: a guest buyer has no identity to
check, a gift's rows are the recipient's, and what a purchase grants (a tier,
an ownership, a coupon use) is the payment's to grant, so the checkout's own
rules and the payment are the authority. Any other GET persists nothing.

A refused write records an `access_refused` event, logs `[WRITE_REFUSED]`
with the code that wrote, and throws `SystemAuthenticationError` (403).

A permanent delete records `access_granted`.

### The REST API and the AI chat

The REST record surface asks `authenticate_read()` and `authenticate_write()`,
so it follows the class. A list of a model is owner-filtered for a caller
below its read level, and refused (403) when the model has no owner column.
The AI chat reads another person's rows of a model only at that model's read
level; below it, a staff member's reads of that model are owner-scoped, as a
member's are.

## Surfaces show what the reader may do

A column stricter than its model is left out wherever rows are shown, for a
reader below its read level who does not own the row
(`$row->columns_hidden_from_session()`):

| Surface | What it does |
|---|---|
| The REST serializer (`export_for_api()` and its locked and sealed shapes) | omits the column |
| FormWriter, handed a saved row (`['model' => $row]`) | does not draw the input or its value; a column the reader may see but not change (`columns_locked_for_session()`) is disabled; a row they may not change (`can_write()`) makes the whole form read only, with a note naming the level |
| The admin table (`tableheader()`) | with `'columns' => array(position => 'Class.column')`, drops that column's header and every row's cell |
| The AI query executor | adds it to the excluded fields, unless the reads are scoped to the reader's own rows |

The sealed-for-browser shape hands out `sealed_dek` (the row's key column's
value) by design, whatever that column's class: it is what lets a member of a
protected conversation open it in the browser. An input FormWriter did not
draw is not posted, so a save that copies request fields onto a row only sets
a column when the request carries it.

Controls ask the declarations instead of testing a number, so a page offers
what the server allows:

```php
AdminPage::action_button('Delete', $url, array('model' => $row, 'verb' => 'soft_delete', 'hidden' => ...));
AdminPage::action_button('Rotate', $url, array('requires' => 10));     // a level, class:verb, or 'api:{action}'
// an altlinks POST entry takes the same 'requires' or 'model' + 'verb'
AdminPage::api_action_button('Refresh', 'server_manager/refresh_node_status', array('class' => '...'));
```

Below the requirement the control is drawn disabled with "Needs level N
(Name)". `api_action_button()` reads the action's declared level and carries
`data-jy-api-action` for the page's script. A page that shows a link or
control only to some readers asks `$row->can_write($session)`,
`can_delete($session)` (soft) or `can_delete($session, true)` (permanent), or
`Access::permits(Model::access_level('write'))` for a new row.

## The declaration test

`tests/unit/access_declarations_test.php` fails on:

- an admin page without a header, or one that does not parse, or names no
  class or no level 0 to 10
- a model without a class
- a model or column class not in `permissions.json`, or a model exception
  that is not a level
- an `$owner_column` that is not one of the model's columns
- a column class looser than its model's at any verb (an undeclared model
  counts as keys)
- a sealed column (a `table.column` locator in
  [sealed secrets](sealed_secrets.md)) declared as anything but keys
- an action without a level, one that is not a literal level or `class:verb`,
  a mutating action on `:read`, and a `'min_permission'` key in a descriptor
  (nothing reads it)

- an admin page or its logic calling `check_permission()`: the header is the
  gate
- a level compared in code (`get_permission() >= 10`, `$_SESSION['permission']`,
  a stored `usr_permission`): ask `Access`, a row's rule or a model's level

`AccessDeclarations::RULES` lists the rules; all are on.

## The inventory

```bash
php maintenance_scripts/dev_tools/permissions_inventory.php [--table=pages|models|columns|actions|all] [--plugin=NAME]
```

prints every admin page, model, column exception and action as markdown
tables with its class and levels. What the code declares wins; what it does
not, the hand-kept `permissions_seed.json` beside the tool supplies, or the
table prints `?`.
