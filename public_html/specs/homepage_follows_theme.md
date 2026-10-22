# Which Page a Site Opens On

**Status:** Draft, 2026-10-21. Moved out of `fleet_security_patching.md` (its Q4) at the owner's
request: "this needs to be thought through and it probably needs its own spec, however small".
Nothing is built. Open questions: Q1 and Q2.

## The problem in plain terms

A site's root page shows one of four things: the sign-in page, a page the owner picked, the blog,
or the theme's own home page. Two settings decide which one: one for visitors and one for
signed-in members. Their factory values are sign-in and the profile page. Choosing a theme that
has its own home page changes neither setting. That causes three problems:

- **The home page is hidden behind sign-in.** After its conversion, xandyliberato.com opened on
  the sign-in page until the visitors' setting was blanked by hand (2026-10-21).
- **Signed-in people cannot reach the home page.** On a website theme, the root sends every
  signed-in member to their profile. **B12:** this is true on xandyliberato.com today, and
  probably on phillyzouk.org and scrolldaddy.app.
- **Switching the other way shows a parking page.** If the theme is switched to one without a
  home page while the setting is blank, visitors land on the built-in "the install worked" page.

Fixing this in theme activation alone would not work. A new site activates its theme by writing
the setting directly (`_site_init.sh --activate`), not through the themes page, and that is the
path that hit xandy.

## How it works today

- **The root route** (`serve.php`, the `/` closure):
  - a signed-in member gets `alternate_loggedin_homepage`, or `alternate_homepage` when that is
    blank;
  - `/blog` renders the blog, `/page/{slug}` renders that page, and any other path is a 302 to
    it;
  - blank renders the active theme's `views/index.php`, or else the built-in `views/index.php`
    (the welcome page).
- **The settings** (`settings.json`):
  - `alternate_homepage`: factory `/login`. A select of the sign-in page, published pages and the
    blog, whose blank choice reads "Select...".
  - `alternate_loggedin_homepage`: factory `/profile`, free text.
- **After sign-in,** `login_logic` sends the member to `alternate_loggedin_homepage`, or to
  `/profile` when it is blank.
- **The earlier migration** `homepage_defaults_login_and_profile` moved blank rows to `/login` and
  `/profile`. It left the visitors' row blank where the active theme had its own home page.
- **"Has its own home page"** means the active theme directory has `views/index.php`
  (`PathHelper::getActiveThemeDirectory`, which covers a theme or a plugin acting as the theme).
  Today:
  - themes: devonnearhill-html5, phillyzouk-html5, scrolldaddy, xandyliberato-html5;
  - plugins acting as the theme: forum, joinery_ai.
- **Ways a theme becomes active:**
  - the themes page (`ThemeManager::onActivate`);
  - a new site (`_site_init.sh --activate`, which writes the setting directly);
  - a copy's first boot (`install_extension.php`);
  - a restore (settings arrive with the backup);
  - a script using `Setting::put`.
- **Live sites, root as a visitor, 2026-10-21:**
  - 200: phillyzouk.org, scrolldaddy.app, xandyliberato.com, galactictribune.net,
    jeremytunnell.com, developers.getjoinery.com, getjoinery.com;
  - sent to `/login`: mapsofwisdom.org, orgs.getjoinery.com.
- **Readers:** docs/routing.md (the `/` paragraph); tests/account_security/login_test.php.

## The proposal: blank means "the theme decides"

The settings are not rewritten when the theme changes. Instead, a blank setting is worked out on
every request from whichever theme is active:

- **Visitors' setting blank:** the theme's own home page if it has one, otherwise the sign-in
  page.
- **Members' setting blank:** the theme's own home page if it has one, otherwise `/profile`.
- **Anything a person picked** (sign-in, a page, the blog, a path) is used as it is, whatever the
  theme.
- **Both factory values become blank.** The root never serves the built-in welcome page.

Switching themes then needs nothing from the themes page, the installer, a copy or a restore: the
next request follows the theme. An owner who wants sign-in on a website theme picks "Sign-in
page", and that choice now lasts through later theme changes.

After sign-in, a member still lands on `/profile` when the members' setting is blank. Only the
root changes.

**The settings page:**
- The visitors' blank choice reads "The theme's home page". Its help line says what that shows
  right now: "This theme has its own home page", or "This theme has none, so visitors see the
  sign-in page".
- The members' setting says the same for its blank value.

## Existing sites

A migration blanks the rows whose blank value would show the same thing:

- A visitors' `/login` row, where the active theme has no home page, becomes blank. Visitors see
  no change, and a later switch to a website theme just works.
- A members' `/profile` row, where the theme has no home page, becomes blank. Same.

Rows on a site whose theme *has* its own home page are Q2.

## Open questions

- **Q1 — Members at the root of a website theme.** With the members' setting blank, a signed-in
  member who goes to the root sees the theme's home page. Signing in still lands them on their
  profile.
  - The catch: on a membership site with a website theme, the logo no longer takes members to
    their profile.
  - The other way: the members' factory value stays `/profile`, and every website theme keeps
    B12.
  - **Recommend blank.** That is what blank means for visitors too.
- **Q2 — Sites whose theme has its own home page and an old value** (xandyliberato.com,
  phillyzouk.org, scrolldaddy.app). Nothing records whether `/login` or `/profile` there was
  chosen or left over from the factory, so the migration leaves these rows alone and each site is
  decided on its own:
  - xandyliberato.com members: blank (B12).
  - phillyzouk.org members: a dance community's website, so blank.
  - scrolldaddy.app members: keep `/profile`. Its members come to manage devices, not to read the
    marketing page.

  Each is one setting write on that site, with the owner's OK.

## Work

- **`serve.php` `/`:** a blank resolves as above, through one helper that `serve.php` and the
  settings page's help line share, so the two cannot disagree. The fallback to the built-in
  welcome page goes.
- **`settings.json`:** both factory values blank, the blank choice's label, and the help text.
- **The migration** above, idempotent.
- **docs/routing.md:** the `/` paragraph describes the rule.
- **Tests:** the root for each case:
  - a theme with and without its own home page, and a plugin acting as the theme;
  - each value of each setting;
  - signed in and signed out;
  - the migration;
  - the sign-in redirect unchanged.
- **B12 and Q2:** the setting writes on the three sites.

## Done when

- A theme with its own home page shows it at the root on a new site, after a switch on the themes
  page, and after a copy or restore, with no setting touched.
- Switching to a theme without one sends visitors to sign-in.
- B12 is gone on xandyliberato.com, and the Q2 sites are as decided.
