# Member-Area App Chrome

**Status:** Complete

## Problem

Member/app surfaces (calendar, mailbox, drive, profile) are core views, but
they render inside the active public theme's page class and CSS. Blog-shaped
themes constrain content to a reading column (e.g. jeremytunnell-html5's
720px inner column), which suffocates app UIs: the calendar and mailbox
become unusably small. Every commercial theme conversion is
content-column-first, so this recurs on every theme, forever.

## Principle

The public site is the theme's territory; the app surfaces are the
platform's. The admin area already follows this rule (PublicPageJoinerySystem
pins its own chrome regardless of `theme_template`); this spec extends the
same rule to the member area.

## Design

**Setting:** `member_area_app_chrome` (core, group `theme`, checkbox,
default **on**). When on, member-area requests skip theme file resolution and
render with the core `PublicPage` class and the `.jy-ui` kit styling —
full-width app chrome. When off, member pages render through the active
theme as any other page.

**Member-area definition (single source):**
`RouteHelper::isMemberAreaPath($path = null)` — true for `/profile` and
`/drive` (and anything beneath them, including plugin member namespaces like
`/profile/mailbox/...`). The member-nav check in `PublicPage` uses the same
helper; no duplicated regexes.

**Mechanism:** `PathHelper::getActiveThemeDirectory()` returns `null` for a
member-area web request while the setting is on. Every `getThemeFilePath()`
call (page class, FormWriter, views, assets) then resolves past the theme to
plugin/core paths, so the pin covers core views and plugin member views in
one place. CLI and non-request contexts are unaffected (no `REQUEST_URI`, no
pin).

**Branding is preserved:** `PublicPageBase::get_render_theme()` still
returns `theme_template`, so `render_brand_token_overrides()` keeps applying
the theme's declared brand tokens (and admin overrides) to the kit's custom
properties. Member pages keep the site's colors and logo; they lose only the
theme's layout.

**Theme switcher interaction:** none. The admin-bar switcher activates a
site-wide theme (`theme_switch` → `ThemeManager::activate()`); member pages
simply stop consulting the active theme while the setting is on — the same
relationship admin pages already have with `theme_template`.

## Out of scope

- A per-theme `theme.json` opt-out declaration (one site setting is enough
  until a theme that handles member pages well actually exists).
- Any change to admin chrome, checkout's noheader variant, or app-session
  (native webview) rendering — all separate paths.

## Touched

- `settings.json` — declare `member_area_app_chrome`.
- `includes/RouteHelper.php` — `isMemberAreaPath()`.
- `includes/PathHelper.php` — the member-area pin in
  `getActiveThemeDirectory()`.
- `includes/PublicPage.php` — member-nav check uses the shared helper.
- `docs/theme_integration_instructions.md` — document the boundary.
