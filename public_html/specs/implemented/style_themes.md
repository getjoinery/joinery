# Style Themes

## Status

Spec written 2026-10-02. Owner decisions the same day: the event-log row
stays (Q1); Custom CSS is retired (Q2). **Built 2026-10-02, all five work
packages.** Proven on dev: a hand-made style theme ZIP
installed with no warning and no email, listed as Styling with no badge,
applied, and its stylesheet came last in the head on the base, admin and
member pages; a second version with a PHP file was refused with the
warning; Activate and Remove were refused while the vault was locked (B3).
The three new tests pass (style_theme 45, theme_tail 102, theme_look 32).
`thm_kind` is on dev; the migration ran on dev (Custom CSS was empty, row
deleted). Builds on nothing; the mailbox reader styling spec is a separate
piece whose override path is a style theme from this one.

Found while building, open: **B4** — an upload of a theme that is already
installed is refused by the installer ("already installed, pass --replace")
before any verdict is acted on, so the "second version adds PHP" path in
Acceptance 7 is reached by the sync (a theme whose directory gains PHP is
re-read as a page theme and dropped from the look slot, covered by
`theme_look_test`), not by the upload form. Replacing an installed theme
from an upload is its own piece of work, not this spec's.

Two details settled in the build: the allowed set also admits `.txt` and
`.md` notes and the signing record a published package carries
(`RELEASE_MANIFEST`, `RELEASE_MANIFEST.sig`), since a signed style theme
ships them; and `CoreSettingOptions::looks()` returns "None" (the empty
value) first and then the style themes, so the list is never empty on a
site with no style theme installed, which the declared-settings test
requires of every discovered list.

## Problem

A member who wants the site to look different has two tools, and neither
fits a package of stylesheets, fonts and images:

1. **Custom CSS**, a textarea on the settings page, meant for a small fix
   that only one site needs. It restyles everything that reads the kit's
   tokens, but it cannot carry a font file or a logo, it cannot be shared,
   and it is one blob. It is empty on dev; no node report carries settings,
   so whether any other node set it is unknown.
2. **A theme.** The themes page accepts an upload, verifies it, and installs
   it. But a theme today is one object: the pages and their styling. Each
   theme's page class writes its own stylesheet link, and activating a theme
   means replacing the active one. A package that holds only styling has
   nothing to replace the pages with, so activating it drops the site to the
   base views.

The signing gate makes the second tool loud as well. A theme is PHP the
server runs on every request, so a theme we did not build goes through the
warning page, a second-factor confirmation, an email to every superadmin,
and a permanent Unsigned badge. That is the right gate for PHP. A package
with no PHP and no script has none of that reach, and the gate has no way to
say so.

Two defects in Custom CSS itself were found while reading for this spec,
and are recorded here because retiring the setting closes them:

- **B1.** The base page class emits Custom CSS before the theme's own
  stylesheet, so a theme rule at equal specificity beats the member's
  Custom CSS.
- **B2.** Four page classes (the base, the admin theme, the Tailwind and
  Falcon bases) and three themes (jeremytunnell, getjoinery, phillyzouk)
  emit Custom CSS a second time after their stylesheet link. Every rule in
  the textarea is parsed twice on those pages.

A style theme does what Custom CSS was for, with files, with a name, and
with one emit point, so the setting goes.

## Goals

- **A theme with no pages is a style theme**, decided by what the package
  contains, not by a declared type. It installs through the themes page like
  any theme and layers over the active page theme instead of replacing it.
- **A style theme never triggers the unsigned warning.** It has no PHP and no
  script, so there is nothing for the warning to warn about. The signing
  verdict is still recorded; the badge and the health row stop treating a
  style theme as a risk.
- **A later version that gains PHP or script is a page theme again**, with
  the full warning, with nothing carried forward to remember that.
- **One emit point for what layers over the page theme**: the look's
  stylesheets, after the page theme's stylesheet and before nothing.
- **Custom CSS is retired.** The setting, its field and every page class's
  emit of it go. A site that had set it is told what it held and where it
  belongs now. B1 and B2 close with it.
- **One help sentence.** The themes page shows pages and look as two slots.
  A style theme's button says Apply, a page theme's says Activate, and the
  text beside Apply says why.

## Non-goals

- A `styles` list on a page theme. A page theme writes its own links today
  and keeps doing so; this spec honours `styles` only on a style theme.
- Per-member looks. A style theme is the operator's choice for the site. The
  mailbox reader's Classic and Fluent presets are a per-member choice inside
  one app, specified separately.
- Screening CSS for what it hides. A stylesheet can hide the Fortress banner
  or an Unsigned badge. The operator who applies a look is the operator who
  could have typed that into Custom CSS until now; applying a look is
  vault-gated for the same reason changing the page theme is.
- Stacking several looks. One look slot. A second look replaces the first.

## Design

### What makes a theme a style theme

A theme is a style theme when its directory holds nothing the platform can
execute or render as a document. The listing is walked at registration
(the sync that reads `theme.json` off disk) and again at every install, and
the kind is recorded on the row.

Refused in a style theme, each a named reason in the transcript:

| Refused | Why |
|---|---|
| `.php`, `.phtml` | runs on the server |
| `.js`, `.mjs` | runs in the browser with the session, the CSRF token and the unlocked vault |
| `.html`, `.htm`, `.xml` | a document the browser would render from our origin |
| any dotfile or dot-directory | `.htaccess` is a server directive; nothing in a style theme starts with a dot |
| a stylesheet with an external reference | `@import` or `url()` naming another origin is a beacon: it reports page visits to that origin, and with attribute selectors can read some form values. Relative paths, same-origin absolute paths and `data:` URLs pass. |

Allowed: `theme.json`, stylesheets, fonts, raster images, and SVG. SVG is
safe here because the static-file server already marks every SVG, HTML and
XML response as an attachment, so a direct visit downloads the file, while
`<img>` and CSS `url()` contexts render it and never execute its scripts.

Any file outside that set makes it a page theme, which is every theme that
exists today. Nothing shipped changes kind.

`ThemeHelper::styleThemeRefusal(string $dir): ?string` is the one
implementation: `null` for a style theme, otherwise the first reason with
the file's path. The installer, the sync and the test call the same method.

### The manifest

A style theme's `theme.json` carries the fields every theme carries (`name`,
`display_name`, `version`, `description`, `author`, `status`) plus:

```json
{
	"styles": ["assets/css/look.css", "assets/css/print.css"],
	"brand_tokens": { "jy_color_primary": "#0f6cbd" }
}
```

`styles` lists the stylesheets to emit, in order, relative to the theme
directory. It is required on a style theme (a look with no stylesheet is a
mistake, refused at registration with that sentence). `brand_tokens` is the
same key a page theme may declare today and resolves one step above the
page theme's: kit default, then the page theme's tokens, then the look's,
then the admin's settings. `publicPageBase`, `requires_plugins`, `audience`
and `cssFramework` are ignored on a style theme; a style theme has no pages
to require anything for.

### The look slot

Two settings, both in the `theme` group, both vault-gated:

| Setting | Holds | Exists |
|---|---|---|
| `theme_template` | the active page theme | today |
| `theme_look` | the applied style theme, or empty for none | new |

`theme_look` is a select whose options come from
`CoreSettingOptions::looks()`: the registered style themes, plus "None".
`theme_template`'s options (`CoreSettingOptions::themes()`) drop the style
themes, so a style theme can never be chosen as the page theme from either
page.

The theme model gains `thm_kind`, `varchar(8)`, `page` or `style`, set at
registration from `styleThemeRefusal()` and never by hand. `Theme::is_style()`
reads it.

### Apply and Activate

On the themes page every theme is one row in the one table, with a Kind
column reading Pages or Styling. The buttons differ by kind:

- A page theme has **Activate**, as today.
- A style theme has **Apply**, or **Remove** when it is the applied look.
  Beside Apply, one sentence: "A styling theme changes colours, fonts and
  images on the pages you already have. Your page theme stays active."

Above the table, two lines: "Pages: *name*" and "Look: *name*" or "Look:
none".

`ThemeManager::applyLook(string $name)` and `removeLook()` write
`theme_look` through `SettingsWriter`, so the vault gate on the setting
holds. `ThemeManager::activate()` refuses a style theme with "it has no
pages; apply it as a look instead". `theme_look` naming a theme that no
longer exists or is no longer a style theme reads as none; the sync clears
it and logs one line.

**B3, found while reading for this spec.** `ThemeManager::onActivate()`
writes `theme_template` through the `Setting` model directly, so Activate on
the themes page bypasses the vault gate the setting declares. This spec
routes Activate through `SettingsWriter` as well, in the same work package
as Apply, so both buttons honour the gate the settings page already enforces.

### One emit point: the theme tail

`PublicPageBase::render_theme_tail()` emits the look's stylesheets, each a
`<link rel="stylesheet">` cache-busted by file mtime, at
`/theme/<look>/<rel>`, following `PluginHelper::renderActivePluginStyleLinks()`
for shape. Nothing else is emitted there, and nothing is emitted after it.

Every page class that writes its own stylesheet link calls
`render_theme_tail()` immediately after that link: the three themes and the
base classes named under B2, and any other page class the audit in WP3
finds writing a link. A page class that writes no link of its own gets the
tail from the base at the end of its head.

The order of the head is then fixed for every page: kit, plugin stylesheets,
brand tokens, the page theme's stylesheet, the look's stylesheets. There is
one place that writes the tail and it writes it once.

`render_brand_token_overrides()` reads the look's `brand_tokens` between the
page theme's and the admin settings.

### Retiring Custom CSS

The `custom_css` entry leaves `settings.json`, so the field leaves the
settings page and `Setting::put()` refuses the name. Every emit of it goes:
`global_includes_top()`, and the second copy in each page class named under
B2. The developer guide and the settings doc stop mentioning it.

A migration, `custom_css_retired.php`, runs once with `update_database`:

- If the row is absent or empty, it deletes nothing and returns.
- If the row holds CSS, it emails every superadmin the saved text in full,
  with one sentence: the setting is gone, and this CSS belongs in a style
  theme, applied from the themes page. Then it deletes the row, so no
  undeclared setting is left behind.

The email is the record. Nothing on the site keeps rendering the old value,
because a value that renders from nowhere visible is the kind of thing that
is still there three years on.

### Install, trust and the badge

The staged-upload path is unchanged up to the verdict. `install_extension.php`
computes the kind after the signature verdict and before anything is moved:

| Verdict | Kind | Outcome |
|---|---|---|
| signed | either | installs, as today |
| not signed, no acknowledgement | style | **installs.** `thm_trust` records the verdict, `thm_kind` records `style`, one event-log row names the kind; no warning, no email, no step-up |
| not signed, no acknowledgement | page | refused with the verdict, as today; the warning page follows |
| not signed, acknowledged | page | installs under the unsigned restrictions, as today |

The trust column keeps meaning what it means: who built the bytes. A style
theme we did not build is `unsigned` and says so in its row. What changes is
how that is shown:

- The red **Unsigned** badge and its hover text appear only on a page theme.
  A style theme's row shows the Kind column's "Styling" and nothing red.
- `VaultHealth::checkUnsignedExtensions()` lists unsigned page themes only.
  A style theme has everything the site has in the way a stylesheet does,
  which is what the operator chose when they applied it.

An update to a style theme is the same table applied to the new package. One
that adds PHP or script is a page theme: not signed, no acknowledgement,
refused, warning page. If the operator acknowledges and installs it, the
registration finds the kind changed to `page` and, if `theme_look` names
this theme, clears it with a transcript line: "*name* now carries pages, so
it is no longer applied as the look." Nothing else needs to remember what
the theme used to be.

### Documentation

`docs/plugin_developer_guide.md`, in its themes section: what a style theme
is, the file rules in one table, the manifest with `styles` and
`brand_tokens`, the two slots, the head order. `docs/settings.md` gains the
`theme_look` row. Written in the current-state voice.

The themes page's own help text (the Apply sentence and the Kind column
hover) is the member-facing documentation; there is no separate page.

## Files

- `includes/ThemeHelper.php` — `styleThemeRefusal()`, the stylesheet
  screen, `styles` and `brand_tokens` reads
- `data/themes_class.php` — `thm_kind`, `is_style()`
- `includes/ThemeManager.php` — kind at registration, `applyLook()`,
  `removeLook()`, `activate()` refusal, `onActivate()` through
  `SettingsWriter` (B3), the sync's stale-look clear
- `includes/CoreSettingOptions.php` — `looks()`, `themes()` minus style
  themes
- `settings.json` — `theme_look` added, `custom_css` removed
- `migrations/custom_css_retired.php` — **new**
- `includes/PublicPageBase.php` — `render_theme_tail()`, Custom CSS removed
  from `global_includes_top()`, look brand tokens
- `includes/PublicPageJoinerySystem.php`, `includes/PublicPageTailwindHTML5.php`,
  `includes/PublicPageFalcon.php`, `theme/jeremytunnell-html5/includes/PublicPage.php`,
  `theme/getjoinery/includes/PublicPage.php`,
  `theme/phillyzouk-html5/includes/PublicPage.php`,
  `theme/scrolldaddy/includes/PublicPage.php`,
  `theme/galactictribune-html5/includes/PublicPage.php` — call the tail
  after the stylesheet link; drop their Custom CSS block
- `utils/install_extension.php` — the kind in the verdict table
- `includes/VaultHealth.php` — page themes only
- `adm/admin_themes.php`, `adm/logic/admin_themes_logic.php` — Kind column,
  the two slot lines, Apply and Remove, the badge rule, the help sentence
- `docs/plugin_developer_guide.md`, `docs/settings.md`
- `tests/security/style_theme_test.php` — **new**, safe tier: fixture trees
  for every row of the refusal table, SVG allowed, a dotfile refused, each
  stylesheet case (relative, same-origin absolute, `data:`, external
  `url()`, `@import`)
- `tests/core/theme_tail_test.php` — **new**, functional: render a page
  under each page class and assert the head order kit, plugin, theme, look,
  and that no page class emits the retired setting
- `tests/models/` theme tests — apply, remove, activate-refusal, the stale
  look clear, `themes()` and `looks()` disjoint

## Work packages

- **WP1 — the kind.** `styleThemeRefusal()`, the stylesheet screen,
  `thm_kind` at registration, the safe-tier test. `update_database` on dev
  for the column. Every shipped theme registers as `page`.
- **WP2 — the slot.** `theme_look`, `looks()`, `themes()` minus style
  themes, `applyLook()`, `removeLook()`, the activate refusal, B3, the stale
  clear, the model tests.
- **WP3 — the tail, and Custom CSS out.** `render_theme_tail()`, the audit
  of every page class for a stylesheet link, the call sites, every emit of
  Custom CSS deleted, `custom_css` out of `settings.json`, the migration,
  look brand tokens, the functional test. Proven on dev under the base, the
  admin theme and the jeremytunnell theme: view source, head order as
  specified, no `<style>` block from the old setting anywhere.
- **WP4 — install and the page.** The verdict table in
  `install_extension.php`, the event-log row, `VaultHealth`, the themes
  page. Proven on dev: upload a hand-made style theme ZIP and see it install
  with no warning and appear with Kind "Styling"; Apply it and see its
  stylesheet after the theme's in the head; upload a second version with a
  `.php` file and see the warning page; acknowledge, and see the look slot
  cleared with the transcript line.
- **WP5 — docs.** The developer guide section and the settings row.

## Acceptance

1. Every theme shipped today registers as a page theme; nothing changes on
   upgrade for any site.
2. A ZIP of `theme.json`, stylesheets, fonts, PNGs and SVGs installs from
   the themes page with no warning, no step-up and no email, and its row
   reads Styling with no red badge.
3. The same ZIP with one `.php`, `.js`, `.html` or dotfile added, or one
   stylesheet with an external `url()` or `@import`, registers as a page
   theme and gets the unsigned warning.
4. Apply on a style theme leaves the page theme active and puts the look's
   stylesheets after the page theme's stylesheet in the head; Remove takes
   them out. Activate on a style theme is refused with the sentence.
5. No page class emits Custom CSS; the settings page has no such field;
   `update_database` on a site that held a value emails every superadmin
   the text and deletes the row (B1, B2 close with the setting).
6. Activate on the themes page and Active theme on the settings page both
   refuse while the vault is locked (B3).
7. A style theme updated with a PHP file, acknowledged and installed, is no
   longer the applied look, and the transcript says so.
8. `VaultHealth` lists an unsigned page theme and not an unsigned style theme.
9. `php tests/run.php db --changed` passes with the three new tests.

## Decisions

- **Q1, 2026-10-02** — the event-log row for a style theme install stays:
  one line, no email, so the log is a complete record of what entered
  `theme/`.
- **Q2, 2026-10-02** — Custom CSS is retired rather than moved. It existed
  for small per-site fixes, which a style theme carries with a name and a
  file; keeping both would be two ways to do one thing.
