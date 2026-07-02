# Profile Menu — One Source of Truth

Make the seeded menu store the single thing that decides what appears in
a member's profile menu — on every web theme and, later, in the mobile
apps' navigation.

**Problem.** The seeded menu store (`amu_admin_menus`, profile-location
rows from `admin_menus.json` `profileMenu` + plugin `plugin.json`
`profileMenu`) is about to become what the apps render via the navigation
endpoint (`specs/ios_app_platform.md`) — but web themes hardcode their
profile menus, so web and app menus will drift the first time either
changes.

## The change

The store renders everywhere:

- A shared accessor (on `PublicPageBase`, available to all themes) returns
  the current user's profile menu from the seeded store — filtered by
  `amu_min_permission`, `amu_visibility` (in/out/both vs signed-in state),
  `amu_setting_activate`, and `amu_disable`, ordered by `amu_order`.
- The base header rendering consumes it; themes style the markup but no
  longer own the list. Inventory of hardcoded menus to convert (all four,
  decided once): `theme/scrolldaddy/includes/PublicPage.php`,
  `theme/galactictribune-html5/includes/PublicPage.php`,
  `theme/jeremytunnell-html5/includes/PublicPage.php`,
  `theme/phillyzouk-html5/includes/PublicPage.php`. Remaining themes
  inherit the base behavior.
- Theme-specific extra links (e.g. scrolldaddy's hardcoded
  `/profile/dns_filtering/devices`) move to the owning plugin's
  `plugin.json` `profileMenu` so they exist in the store — that is the
  point: adding a menu entry means one thing, everywhere.
- The app navigation endpoint (`specs/ios_app_platform.md`) then reads the
  same accessor, guaranteeing web and app menus can't diverge.

## Sequencing

Lands before `specs/ios_app_platform.md` Phase 1 — this workstream is a
prerequisite of the navigation endpoint's "canonical source" claim.

## Tests

- Menu output respects permission/visibility/setting filters per state
  (signed in/out, tier setting off).
- A plugin `profileMenu` entry appears on web after plugin sync with no
  theme change.
- Converted themes render the same entries the store holds.

## Acceptance checklist

1. Adding a `profileMenu` entry to a plugin's `plugin.json` (and syncing)
   changes the web header menu on every theme with no theme edits.
2. Removing an entry from `admin_menus.json` `profileMenu` (and reseeding)
   removes it from every theme's web header.
3. All four converted themes render menus visually consistent with their
   prior styling — the list source changes, the look does not.

## Out of scope

- Admin-menu rendering changes (`amu_location` admin rows are untouched).
- The navigation endpoint itself — `specs/ios_app_platform.md` Phase 1.

## Versioning

- Bump `@version` on `PublicPageBase` and each converted theme
  `PublicPage.php`.

## Documentation deliverables (on implementation)

- `docs/plugin_developer_guide.md` — profile menu entries as the single
  way onto the member menu (web + apps).
