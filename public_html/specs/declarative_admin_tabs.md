# Declarative Admin Tab Strips from the Menu Tree

## Overview

In-page admin **tab strips** (the row of Setup / Domains / Logs / … links across
the top of a feature's admin pages) are today built one of two ways, both
imperative:

1. A hand-rolled `<ul class="nav nav-tabs">…</ul>` block **copy-pasted into every
   sibling page** (e.g. the `inbound_email` plugin's admin pages).
2. A per-page PHP array literal passed to `AdminPage::tab_menu($tabs, $current)`
   (e.g. core settings pages).

Both duplicate the tab list across the pages that render it, drift out of sync,
and ignore permissions — every tab shows regardless of whether the viewer can
open its target, relying on the destination page to slam the door.

Meanwhile the platform **already** models "sibling pages under a common parent"
declaratively: the admin menu tree in `amu_admin_menus`, seeded from
`admin_menus.json` (core) and each plugin's `plugin.json` `adminMenu`. A tab
strip *is* a projection of that tree — the children of one parent. This spec adds
that projection as a thin new feed into the existing tab renderer, so a tab group
can be declared **once** (in JSON) and rendered permission-aware, with no
duplication and with cross-plugin extensibility.

It is **opt-in and additive**. Contextual/record-scoped tab strips (edit-record
sub-tabs, wizard steps, anything whose URLs carry instance ids) keep using the
explicit-array form — they are not menu-tree nodes and must not be forced into
one. See Non-goals.

> Motivated by the Inbound Email Mailbox Reader spec
> (`specs/inbound_email_mailbox_reader.md`), which currently ships a self-contained
> `inbound_email_admin_tabs()` PHP helper. Once this lands, that plugin becomes
> the **first adopter**: it declares its admin pages in `plugin.json` and drops
> the helper. This spec does **not** depend on the reader and can ship first or
> independently.

## What exists today (grounding)

- **`amu_admin_menus`** (`data/admin_menus_class.php`) columns:
  `amu_menudisplay` (title), `amu_slug` (unique), `amu_parent_menu_id`,
  `amu_defaultpage` (target page/url), `amu_order`, `amu_min_permission`,
  `amu_disable`, `amu_icon`, `amu_setting_activate` (feature-flag gate),
  `amu_location` (default `admin_sidebar`), `amu_visibility` (default `in`).
- **`MultiAdminMenu::getadminmenu($user_permission, $current_menu_slug,
  $get_all=false, $location='admin_sidebar')`** — already fetches menu items
  filtered by permission and location, ordered, honoring disable; the sidebar is
  rendered from it (`AdminPage.php`, `AdminPage-uikit3.php`). `getMultiResults()`
  already supports a `parent_menu_id` filter.
- **`AdminPage::tab_menu($tabs, $current)` → `renderTabMenu($tabs, $current)`** —
  the theme-aware renderer, overridden per `PublicPage*` theme. Takes a
  `['Title' => '/url']` array and a current-title string. This stays the single
  sink; the projection only changes how the array is *built*.

So the menu data, the permission filter, the parent grouping, and the renderer
all already exist. The gap is a builder that turns "a parent's children" into the
`['Title' => '/url']` array the renderer wants.

## Design — one new builder

Add a sibling to `tab_menu()` that sources the tab list from the menu tree:

```php
// AdminPage (or PublicPageBase, alongside tab_menu)
static function tab_menu_for(string $parent_slug, ?string $current = null): string;
```

Behavior:

1. Resolve the parent item by `amu_slug = $parent_slug`.
2. Fetch its **children** — `MultiAdminMenu` filtered by
   `parent_menu_id = <parent id>`, gated by the current session's permission
   (`amu_min_permission`), honoring `amu_disable`, `amu_setting_activate`
   feature-flags, and `amu_visibility`, ordered by `amu_order`. This reuses the
   same selection logic `getadminmenu()` already applies to the sidebar (extend
   `getadminmenu()` to accept a `parent_menu_id`, or add a focused
   `get_child_tabs($permission, $parent_slug)` — see Open question).
3. Build `['amu_menudisplay' => amu_defaultpage, …]` from the surviving children.
4. Resolve `$current`: if passed, use it; otherwise derive it by matching the
   current request path against each child's `amu_defaultpage` (longest-prefix
   match, query string ignored), so adopting pages don't have to name their own
   active tab.
5. Hand the array to `renderTabMenu()` — identical output path and theme behavior
   as `tab_menu()` today.

Net result: a top-level admin tab group is declared once in JSON; every page in
it calls `echo AdminPage::tab_menu_for('inbound_email')`; permission-hidden tabs
disappear for users who can't reach them; a plugin can add a tab to another
feature's group by declaring a child with that parent — no edits to the other
code.

## Sidebar vs. tab visibility

Declaring the sub-pages as menu children raises one question: should they also
appear in the **sidebar** (nested under the parent), or only as tabs?

The columns to express this already exist — `amu_location` and `amu_visibility`.
Recommended model:

- The **parent** item is the sidebar entry (e.g. the plugin's "Incoming" link).
- Its **children** are the tab group. A child that should render as a tab but
  *not* clutter the sidebar is marked via `amu_location` (e.g. a `tabs` value, or
  excluded from `admin_sidebar`) so `getadminmenu(..., 'admin_sidebar')` skips it
  while `tab_menu_for()` includes it.

Decide the exact flag convention during build (a dedicated `amu_location` value
for tab-only items is the cleanest, and keeps the sidebar selection untouched).
This must be settled before adoption so declared tabs don't double-list in the
sidebar.

## JSON shape (adopter side)

A plugin opts in by declaring the group's pages as `adminMenu` children in
`plugin.json` (core features use `admin_menus.json` identically). Illustrative —
the inbound_email group once adopted:

```json
"adminMenu": [
  { "slug": "inbound_email", "title": "Incoming",
    "url": "/plugins/inbound_email/admin/admin_inbound_email_setup",
    "parent": "emails", "permission": 5, "order": 10 },

  { "slug": "inbound_email_domains", "title": "Domains",
    "url": "/plugins/inbound_email/admin/admin_inbound_email_domains",
    "parent": "inbound_email", "permission": 5, "order": 20, "location": "tabs" },
  { "slug": "inbound_email_mailbox", "title": "Mailbox",
    "url": "/plugins/inbound_email/admin/admin_inbound_email_reader",
    "parent": "inbound_email", "permission": 5, "order": 50, "location": "tabs" }
]
```

`parent` is resolved by slug at seed time (already how the system links parents).
The seeding pipeline that maps `plugin.json` `adminMenu` → `amu_admin_menus` must
carry the `location` field through (confirm/extend the seeder).

## Files

### To modify
| File | Change |
|------|--------|
| `includes/AdminPage.php` (and/or `includes/PublicPageBase.php`) | Add `tab_menu_for($parent_slug, $current=null)` alongside `tab_menu()` |
| `data/admin_menus_class.php` | Extend `getadminmenu()` to accept a `parent_menu_id`/`parent_slug`, or add `get_child_tabs($permission, $parent_slug)`; ensure permission/disable/feature-flag/visibility filtering matches the sidebar |
| Menu seeder (the `plugin.json`/`admin_menus.json` → `amu_admin_menus` path invoked by `update_database`) | Carry the `location` (and any tab-only marker) field through to the row |

### To verify
- `renderTabMenu()` across the theme subclasses (`PublicPageBase`,
  `PublicPageJoinerySystem`, `PublicPageFalcon`, `PublicPageTailwind*`) renders an
  identical strip whether fed by `tab_menu()` or `tab_menu_for()` — the builder
  changes, the sink does not. (Also a good moment to remove the hardcoded
  `'Edit Address'`/`'Edit Phone Number'` skips in the base `renderTabMenu()` — a
  pre-existing wart that a declarative source makes unnecessary.)

## Testing

- **Builder** — `tab_menu_for('x')` returns the children of `x` as
  `['title' => 'url']` in `amu_order`, excluding disabled / feature-flag-off /
  over-permission / wrong-visibility items.
- **Permission filter** — a permission-5 user does not see a permission-10 tab; a
  permission-10 user sees both.
- **Active resolution** — current tab is derived correctly from the request path
  (ignoring query string) when `$current` is omitted, and overridable when passed.
- **Sidebar isolation** — tab-only children render in `tab_menu_for()` but not in
  `getadminmenu(..., 'admin_sidebar')`.
- **Cross-plugin injection** — a child declared by plugin B under plugin A's
  parent appears in A's tab strip without editing A.
- **Parity** — output markup matches the legacy hand-rolled strip closely enough
  that adopted pages look unchanged.

Run `php -l` and `validate_php_file.php` on every modified PHP file.

## Documentation

- Add a **"Declarative tab strips"** section to `docs/admin_pages.md`: when to use
  `tab_menu_for($parent_slug)` (static top-level groups) vs. `tab_menu($tabs,
  $current)` (contextual/record-scoped strips), the JSON shape, and the
  sidebar-vs-tab `amu_location` convention.
- Cross-reference from the admin menu / routing docs where `adminMenu` /
  `admin_menus.json` is described.

## Adoption (after this lands)

1. **Inbound Email** — first adopter: declare its admin pages as `adminMenu`
   children of `inbound_email` in `plugin.json`, replace
   `AdminPage::tab_menu(inbound_email_admin_tabs(), …)` with
   `AdminPage::tab_menu_for('inbound_email')`, and delete the
   `inbound_email_admin_tabs()` helper — the whole file
   `plugins/inbound_email/includes/admin_tabs.php` (it holds nothing else),
   along with the `require_once(...'admin_tabs.php')` line in each of its seven
   consumer pages.
2. **Core settings pages** — optionally migrate `admin_settings*`'s array literal
   to a declared `settings` group, gaining permission-aware tabs.
3. Other features migrate opportunistically; nothing is forced.

## Non-goals

- **Migrating every tab strip.** Contextual/record-scoped strips (edit-record
  sub-tabs, wizard steps, instance-id URLs) are not menu nodes and keep the
  explicit-array `tab_menu()`. This spec adds a feed; it does not deprecate the
  array form.
- **Changing `renderTabMenu()`'s markup or the themes' tab styling** (beyond
  optionally dropping the hardcoded name skips).
- **A new menu table or a rewrite of the sidebar.** The projection reuses
  `amu_admin_menus` and the existing selection logic as-is.

## Versioning

- Bump `@version` on each modified core file (`AdminPage.php` /
  `PublicPageBase.php`, `admin_menus_class.php`, the seeder).
- No schema change is required if `amu_location` suffices for the tab-only marker;
  if a new column is chosen instead, it is added declaratively via
  `$field_specifications` (auto-applied by `update_database`), not a migration.
