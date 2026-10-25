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
```

- A malformed requirement throws `InvalidArgumentException`: an unknown class
  or verb, a level outside 0 to 10, a string `'8'`, null. A typo never reads
  as level 0.
- `decide()` compares with the **session's** level
  (`SessionControl::get_permission()`): the level the request was authorized
  at, which for an API call is the acting level `set_api_user()` simulates.
  It is never the user's stored `usr_permission`, which a login-as session or
  an IP-change-zeroed session is below.
- `allows()` is the same comparison for a caller that already holds the level
  (`ApiAuth::authorize()` holds the acting level).

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
| An API action | `'auth' => ['min_user_permission' => 'personal:write']`, or a level | `ApiAuth::authorize()` |
| An admin page | `/** @joinery-admin class=personal */` (its class's read level), or `/** @joinery-admin level=8 */` | read by the declaration test and the inventory |
| A model | `public static $access_class = 'personal';`; exceptions `$read_permission`, `$write_permission`, `$delete_permission` (soft delete), `$permanent_delete_permission`; `$owner_column` when the owner is not `{prefix}_usr_user_id` | read by the declaration test and the inventory |
| A column stricter than its model | `'access_class' => 'keys'` in its `$field_specifications` entry | read by the declaration test and the inventory |

A page's header is read from the first 4 KB of its file, so it goes at the
top. An action's level reads the acting level (above). An action a member runs on
their own rows declares 0, and the model's owner rule is its gate. A
mutating action never carries a `:read` level.

`AccessDeclarations` (`includes/AccessDeclarations.php`) reads every
declaration from the source on disk, so an inactive plugin is read too:
`pages()`, `models()`, `actions()`, and `problems()`, the declaration test's
rules.

## The declaration test

`tests/unit/access_declarations_test.php` fails on:

- a page header that does not parse, or names no class or no level 0 to 10
- a model or column class not in `permissions.json`, or a model exception
  that is not a level
- an `$owner_column` that is not one of the model's columns
- a column class looser than its model's at any verb (an undeclared model
  counts as keys)
- a sealed column (a `table.column` locator in
  [sealed secrets](sealed_secrets.md)) declared as anything but keys
- an action level that is not a literal level or `class:verb`, and a mutating
  action on `:read`

`AccessDeclarations::RULES` lists the rules. The four that ask for a
declaration everywhere (an admin page without a header, an admin page or its
logic calling `check_permission()`, a model without a class, an action
without a level) are off: the test reports how many are left.

## The inventory

```bash
php maintenance_scripts/dev_tools/permissions_inventory.php [--table=pages|models|columns|actions|all] [--plugin=NAME]
```

prints every admin page, model, column exception and action as markdown
tables with its class and levels. What the code declares wins; what it does
not, the hand-kept `permissions_seed.json` beside the tool supplies, or the
table prints `?`.
