# Profile Menu — One Source of Truth

Make the seeded menu store the single thing that decides what appears in
a member's profile menu — on every web theme and, later, in the mobile
apps' navigation.

**Current state.** The store and its accessor already exist (see
`docs/plugin_developer_guide.md` § Plugin Menus): profile rows live in
`amu_admin_menus` (`amu_location='user_dropdown'`), core entries seed from
`admin_menus.json` `profileMenu`, plugin entries sync from each plugin's
`plugin.json` `profileMenu`, and `PublicPageBase::get_menu_data()`
(`includes/PublicPageBase.php:214`) serves the filtered result as
`$menu_data['user_menu']['items']` — each item `{label, link, icon,
slug}`, already filtered by `amu_min_permission`, `amu_visibility`
(in/out/both vs signed-in state), `amu_setting_activate`, `amu_disable`,
ordered by `amu_order`. The base include-class renderers (canvas
`PublicPage`, `PublicPageFalcon`, `PublicPageJoinerySystem`) render from
it with slug-based admin filtering.

**Problem.** Four themes bypass the accessor and hardcode their profile
menus, so the store and those themes drift the first time either changes
— and the app navigation endpoint (`specs/ios_app_platform.md` § 1),
which reads the same store, would drift with them.

## The change

Convert the four hardcoded themes to render
`$menu_data['user_menu']['items']`; themes keep their markup and styling
but no longer own the list. Audited inventory (all four, decided once):

| Theme `includes/PublicPage.php` | Hardcoded today |
|---|---|
| `theme/scrolldaddy` | `$profile_menu` array (My Profile → `/profile/profile`, Admin → `/admin/admin_users` at perm ≥ 5, Settings → `/profile/account_edit`, Sign out) + `$logged_out_menu` (Sign in); separate literal Devices links to `/profile/dns_filtering/devices` and a `/profile` Settings link in two render blocks |
| `theme/galactictribune-html5` | Sign in / Sign up (gated on `site_info.register_enabled`) / Sign out literals in both desktop and mobile headers; never iterates `items` |
| `theme/jeremytunnell-html5` | Sidebar literals: Profile, Admin (`permission_level >= 5`), Sign out; Login when signed out |
| `theme/phillyzouk-html5` | Dropdown literals: Profile, Admin (`permission_level >= 5`), Sign out; Login when signed out |

Conversion rules, applied uniformly:

- **The store's entry wins over a theme's private variant.** Scrolldaddy's
  `/profile/profile` and `/profile/account_edit` links collapse to the
  store's `core-profile` (`/profile`) like every other theme. If a
  distinct Settings entry is wanted on the member menu, it is added once
  to `admin_menus.json` `profileMenu` — for every theme — not kept as a
  theme private.
- **Plugin links live in the plugin's manifest.** Scrolldaddy's hardcoded
  Devices link moves to `plugins/dns_filtering/plugin.json` `profileMenu`
  (which already declares `dns-filtering` → `/profile/dns_filtering`;
  add `dns-filtering-devices` → `/profile/dns_filtering/devices`). That
  is the point: adding a menu entry means one thing, everywhere.
- **Signed-out menus come from the store too.** `core-signin` /
  `core-signup` / `core-forgot-password` carry `visibility=out`
  (`core-signup` is gated by `settingActivate: register_active`), so the
  themes' hand-rolled logged-out branches and galactictribune's
  `register_enabled` check are replaced by rendering the same `items`
  array — the visibility filter already produced the right list.
- **Admin filtering is by slug, never label.** Use the
  `PublicPageBase::isAdminMenuItem()` / `isAdminLauncherItem()` helpers
  (slug prefix `core-admin-`), matching the already-converted base
  classes.

Remaining themes inherit the base behavior — no work.

The app navigation endpoint (`specs/ios_app_platform.md` § 1) reads the
same store with the same filters, which is what makes web and app menus
unable to diverge once this lands.

## Sequencing

Lands before `specs/ios_app_platform.md` Phase 1 — this workstream is a
prerequisite of the navigation endpoint's "canonical source" claim.

## Tests

- Menu output respects permission/visibility/setting filters per state
  (signed in/out, `register_active` off).
- The `dns-filtering-devices` `plugin.json` entry appears on web after
  plugin sync with no theme change.
- Converted themes render the same entries the store holds.

## Acceptance checklist

1. Adding a `profileMenu` entry to a plugin's `plugin.json` (and syncing)
   changes the web header menu on every theme with no theme edits.
2. Removing an entry from `admin_menus.json` `profileMenu` (and reseeding)
   removes it from every theme's web header.
3. All four converted themes render menus visually consistent with their
   prior styling — the list source changes, the look does not.

## Out of scope

- Admin-menu rendering changes (`amu_location='admin_sidebar'` rows are
  untouched).
- The navigation endpoint itself — `specs/ios_app_platform.md` Phase 1.
- New store entries beyond the Devices move (content decisions happen in
  `admin_menus.json` / plugin manifests, not here).
- Operator overrides that survive plugin sync (settings-style declared
  default vs stored value). Today sync overwrites hand-edits to
  plugin-owned rows; if operator customization of plugin entries becomes
  a real need, adopt the override model rather than warning labels.

## Versioning

- Bump `@version` on each converted theme `PublicPage.php` and on
  `plugins/dns_filtering/plugin.json`.

## Documentation deliverables (on implementation)

- `docs/plugin_developer_guide.md` — profile menu entries as the single
  way onto the member menu (web + apps).
