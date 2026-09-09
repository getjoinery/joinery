# The site root sends visitors to sign in and members to /profile

## Problem

A fresh install answered its root URL with the built-in welcome page: a hero
saying the site was installed, a features grid, sign-in and register buttons.
Most installs exist for mail and members, and nobody sets a public homepage,
so that parking page sat at the front door of every site indefinitely.

The two settings that control the root already existed and both defaulted to
blank:

- `alternate_homepage` — where a visitor who is not signed in lands.
- `alternate_loggedin_homepage` — where a member lands; blank falls back to
  the public one.

## Change

**Factory defaults.** `alternate_homepage` defaults to `/login` and
`alternate_loggedin_homepage` to `/profile` (`settings.json`). The `/` route
already 302s any plain path, so no route code changed beyond the comment.

**The dropdown can hold the default.** `alternate_homepage` is a select fed
by `CoreSettingOptions::homepageTargets`, which listed only published pages
and the blog. A select whose stored value is not among its options renders
unselected, and the next save writes the empty first option back, so the
factory value would have evaporated the first time an operator saved the
settings page. `Sign-in page` (`/login`) now leads the list.

**Signed-in members never see the sign-in form.** With `/login` as the public
homepage, a member whose signed-in setting is blank would be sent from `/` to
`/login` and shown a login form while logged in. `login_logic` now redirects a
signed-in visitor to the signed-in homepage (or `/profile`), the same target a
successful login uses. Activation-code visits are handled before this, as
before; POSTs never reach it.

**Existing sites move with the default.** A `settings.json` default only seeds
a row that does not exist, so migration 181
(`migrations/homepage_defaults_login_and_profile.php`) sets rows still at the
old blank default, with one guard: `alternate_homepage` is left blank when the
active theme ships its own `views/index.php`, because that theme's homepage is
a real page. The signed-in row moves regardless. The cached copy of `/` is
invalidated when a row moves. Same wholesale-flip reasoning as migration 180
(pre-launch, an untouched blank is indistinguishable from a chosen one).

## Tests

- `tests/integration/declared_settings_test.php` — every select default is
  one of its own options (the evaporation bug, pinned generally).
- `tests/integration/routing_test.php` — the root may answer 200 or 302.

## Docs

`docs/routing.md` § The site root.

## Found by the new check

Two factory defaults were never among their own options, so a fresh install
lost them the first time an operator saved the settings page:

- **B1** `default_comment_status` defaulted to `Approved`; the options and
  the reader in `comments_class` use `approved`. New installs never
  auto-approved comments. Default corrected.
- **B2** `default_timezone` defaults to `UTC`, and the `zone` reference table
  (zones by country) has no UTC row, so every timezone list on the platform
  (settings, register, account edit, admin user forms, the setup wizard, list
  signup) rendered the default unselected and saved `Africa/Abidjan`, the
  first zone alphabetically. `Address::get_timezone_drop_array()` now leads
  with UTC when not filtered by country.
