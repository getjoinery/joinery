# Replacing an Installed Package from an Upload

## Status

Spec written 2026-10-02. Owner decisions the same day: Replace is allowed
on an active plugin (Q1); a site edits a plugin or theme Joinery ships by
forking it in place under the shipped name, and the fork survives deploys
until the operator lets it go (Q2, the fork model). Investigated against
the code 2026-10-03 before building (Q3 below records what was found); all
four work packages built the same day and proven on dev in the browser:
the replace panel for an installed name (both versions, the older note,
Discard, the system-theme refusal), Replace through root (transcript line,
kept copy, event-log row, manifest and row at the new version with the fork
mark), Allow upgrade and Disable upgrade writing the live manifest, the
sync leaving the row false (B6), the Local fork badge, the Plugins page
offering the toggle. Not yet proven: WP3's live deploy to a test node (a
publish is a fleet-wide act; the deploy rule is covered by
deploy_preserve_fork_test). Two bugs found and fixed on the way: B7, the
request panel's status poll failed with a 400 on every request because the
status action took two arguments and the API endpoint passes one; B8, the
installer left each upload's empty staging wrapper directory behind. Fixes B4 from the Style Themes spec (an upload
of an installed name fails after the verdict with a shell hint), B5, found
while writing this (the deploy reads the incoming archive's upgrade flag,
so "Disable upgrade" cannot protect a fork of a shipped extension), and
B6, found during the investigation (the sync copies the manifest's flag
onto the row, so an uploaded theme's row-only fork mark is undone by the
next sync or deploy). Builds on the Package Signing spec (the staged
upload, the root request, the verdict, the acknowledgement) and changes
nothing in its trust model.

## Problem

An operator uploads version 1.1 of a theme they installed by upload at 1.0.
The page says the package was unpacked and root was asked to install it.
Root verifies it, decides its kind, and then refuses:

```
install_extension: theme 'oceanlook' is already installed at .../theme/oceanlook.
Pass --replace to overwrite it (the current copy is kept beside it), or uninstall it first.
```

The request panel shows "Failed, exit 2". The operator has no `--replace`
to pass, because the root request dispatcher never adds the flag, and
uninstalling first means losing the theme's row, its trust record, its
preserved-on-deploy flag and, for a plugin, every row the plugin owns. The
same is true for a plugin uploaded as a local fork: it can never be updated
the way it was installed.

The refusal is deliberate and right as a default: replacing an installed
package in place is a different act from installing one, and doing it by
accident is how a local fork disappears. What is missing is the way to do it
on purpose from the page that offered the upload.

**B5.** Even with the refusal gone, a fork of a plugin or theme that
Joinery ships would not last. The deploy decides what to preserve by
reading the **incoming** archive's manifest, not the live one and not the
row (`DeploymentHelper::copyPreservedToStaging()`: "the staged manifest is
authoritative", to avoid a deadlock where a live `false` could never update
itself to `true`). A live copy survives only when the archive does not
carry its name, or the archive itself says `receives_upgrades: false`. So
the Themes page's "Disable upgrade" writes a flag the deploy never reads
for a shipped name, while telling the operator the theme "will be preserved
on deploy", and an uploaded plugin is never marked a local fork at all on
the root install path (only an uploaded theme is).

A plugin is addressed by its directory name everywhere: its table prefixes,
its settings, its routes, its class map. A fork under another name would be
a different plugin with none of the original's data. The only viable way to
let a site edit a shipped plugin is a fork in place, under the shipped
name, that deploys leave alone.

**B6.** Both syncs (`ThemeManager::sync()`, `PluginManager::sync()`) copy
the live manifest's `receives_upgrades` onto the row whenever the manifest
declares it, and every shipped manifest declares it `true`. The installer
marks an uploaded theme a fork by writing the row alone, so the next sync
or deploy flips the row back. The manifest is the source of truth and the
only place a fork mark lasts; the row follows it.

## Goals

- **An upload of a package that is already installed replaces it**, on the
  operator's say-so, from the Themes and Plugins pages. One confirmation,
  with the installed version and the uploaded version side by side, before
  anything is queued.
- **The verdict still decides what installs.** Replace only decides what
  happens to the directory that is there. A signed replacement installs; an
  unsigned page theme or plugin goes through the warning and the
  acknowledgement exactly as a fresh unsigned install does; an unsigned
  style theme installs without the warning. The replace step never
  shortens that path.
- **Nothing the site knows about the package is lost.** The row, its trust
  record, `receives_upgrades`, the plugin's tables and data, the look slot:
  all survive. What changes is the files and what the manifest says about
  them.
- **The previous copy is kept beside the new one**, as the installer does
  today for a shell `--replace`, so the replacement is reversible by an
  operator at a shell.
- **A fork of a shipped extension survives deploys.** An upload marks the
  package a local fork, plugin or theme alike; the deploy preserves a local
  fork whatever the incoming archive says; the operator ends the fork with
  one button, and the next deploy brings the shipped version back. The
  Plugins and Themes pages say what a fork costs.

## Non-goals

- Replacing from the marketplace by name. That path already replaces
  through `refreshFromUpstream()` with a verified package, and has no
  acknowledgement path by design.
- A Restore button for the kept copy. The kept copy is the shell-level
  undo; a page action to swap it back is its own piece of work, with its
  own questions about what the row and the plugin's data should do.
- Changing the ZIP paths in `AbstractExtensionManager` (`installFromZip`,
  `handleExistingExtension`). They refuse from the web already and are not
  on this path.
- Downgrade protection. A lower version is shown as such on the
  confirmation and installs if confirmed. A plugin whose migrations cannot
  run backwards is the plugin author's problem to state in its own
  migrations.

## Design

### The confirmation

`PackageInstallPage::upload()` stages the archive as today. Before it
submits the root request, it asks the manager whether the name is already
on disk. When it is, nothing is queued: the staged directory is left where
it is and the page shows a **replace panel** in the place the warning block
occupies, with the facts of both copies:

| | Installed | Uploaded |
|---|---|---|
| Version | from the live manifest | from the staged manifest |
| Author | | |
| Files | | |
| Kind (themes) | Pages / Styling | Pages / Styling |

and two buttons: **Replace**, which queues the root request with
`replace: true` in its arguments, and **Discard**, which removes the staged
directory. A lower uploaded version carries one line above the buttons:
"This is older than what is installed." A theme that is the active page
theme or the applied look, and a plugin that is active, carries one line
saying it is in use and the site will show the new files as soon as they
are in place.

The panel is `PackageInstallPage::replace_html()`, built like
`warning_html()`: a FormWriter token, two single-button action forms, no
hand-rolled form. The Replace button carries the staged directory (relative
to staging, as the request does) and the token; the page's logic file
handles `replace_staged` and `discard_staged` as it handles
`install_anyway`.

A system extension (`is_system` in the live manifest: the three system
themes) is never a fork, because the deploy pulls it fresh whatever its
flag says. The panel refuses it with that sentence instead of offering
Replace, and the staged directory is discarded.

Nothing sweeps `uploads/staging` today: a staged directory the operator
walks away from would stay forever. `upload()` removes staging directories
older than a day before it stages a new one; staging belongs to the web
user, so no root request is needed.

### The request and the dispatcher

`install_package {type, staged_dir, replace?, unsigned_ack?}`. The
dispatcher in `utils/root_request.php` appends `--replace` when the
argument is present and true. That is all it does with it.

Why this is safe to read from the request: the request file proves only
that something running as the web user wrote it, and the Package Signing
spec already accepts that for `type` and `staged_dir`. `replace` decides
whether an existing directory is set aside before the copy; it does not
decide whether anything is installed, which is still the verdict's and the
acknowledgement's. An attacker who can forge a request can at most cause
root to set a signed package aside and install another signed package in
its place, which is what the marketplace path lets them do already; for an
unsigned package the acknowledgement is still required and still cannot be
forged from a stolen session.

### The installer

`utils/install_extension.php` already does the work for `--replace`: the
existing directory is renamed to `<name>.replaced.<UTC time>`, older kept
copies are pruned to one, the verified copy is placed, and the database
half runs. Two things change:

- **The kept copy is made after the verdict and the acknowledgement, never
  before.** Today the existing-directory check runs after the verdict
  already; this spec pins it with a test, because a refused package that
  had moved the live copy aside would take the site down for a warning.
- **The transcript says what was replaced**: one line,
  `replaced theme oceanlook 1.0.0 with 1.1.0; previous copy kept at
  oceanlook.replaced.20261002T2150Z`, so the request panel and the event
  log both say what happened.

Nothing changes in the trust path. The row's trust column is set from this
install's verdict, as it is today: a signed replacement of an unsigned
package records `signed`; an unsigned replacement of a signed package goes
through the warning and records `unsigned`. For a theme, the sync re-reads
the kind, and a look whose theme gained pages is dropped from the slot with
the transcript line the Style Themes spec specifies. An uploaded theme
replacement is a local fork and keeps `receives_upgrades = false` as an
uploaded install does.

### A fork of a shipped extension

The fork model in one sentence: **an uploaded package is a local fork, a
local fork is preserved on every deploy, and only the operator ends it.**

**Marking the fork.** The installer marks every uploaded install and every
uploaded replacement a local fork, plugin and theme alike, by writing
`receives_upgrades: false` into the live manifest as soon as the files are
in place and before the database half runs. Root writes it (it is a tree
write, and the installer is root at that moment). The register step then
carries the manifest's value onto the row as the sync always does, so the
row and the manifest cannot disagree and the `--uploaded` register flag is
no longer needed. `PluginManager` gains `writeManifestReceivesUpgrades()`
beside `ThemeManager`'s, and the dispatcher's `set_receives_upgrades`
handles both types for the pages' toggles.

**Preserving it.** `copyPreservedToStaging()` preserves a live extension
when any of these holds, in this order:

1. the archive does not carry its name (as today);
2. the **live** manifest says `receives_upgrades: false` (new: the fork,
   or the operator's Disable upgrade);
3. the incoming manifest says `receives_upgrades: false` (as today: a
   package Joinery publishes as preserved).

The deadlock the current rule guards against is the fork model's intent: a
live `false` is the operator's choice, or an upload's, and the archive
cannot override it. What ends it is the operator: **Allow upgrade** writes
`true` to the row and the live manifest, and the next deploy replaces the
fork with the shipped version. The button's confirmation says exactly
that: "The next deploy will replace your copy of *name* with the version
Joinery ships. Your copy is not kept." (The deploy's swap keeps the
previous tree as `public_html_last`, which is the undo a deploy already
has; nothing new is kept.)

**Saying what it costs.** On both pages a forked extension shows one badge,
**Local fork**, in place of today's "Upgrades disabled" (themes) and
"Preserved" (plugins), with the hover: "Uploaded here. Deploys leave it
alone, so it gets no updates from Joinery, including security fixes, until
Allow upgrade is pressed." When the catalog the site last fetched carries
a different version of the same name, the badge says so beside it:
"shipped version is 1.4.0", from `MarketplaceClient::published_versions()`,
the day-old copy `published_names()` already keeps, extended to carry
each name's version. The Plugins page gains the Disable upgrade / Allow
upgrade pair the Themes page has, through the same root request. The
replace panel's in-use line for a shipped name says the package will
become a local fork.

**The marketplace on a fork.** Installing a fork's name from the
marketplace would put the catalog copy over the operator's edits.
`installFromTarGz()` refuses that today when the live manifest says
`false`; `refreshFromUpstream()`, the path the by-name install actually
takes, does not check and replaces. It gains the same refusal, and both
say the way out: "it is a local fork; press Allow upgrade on the Plugins
(or Themes) page first". The publisher's `included_in_publish` and
`audience` are untouched; they are about what is shipped, not about what
a site keeps.

### What the operator sees after

The request panel reads the transcript as it does today. The theme or
plugin row shows the new version. The event log gains one row,
`package_replaced`, with type, name, old version, new version, trust and the
operator, so a replacement is as visible as an unsigned install is.

### The plugin's database half

`PluginManager::install()` already treats an existing row as a reinstall:
it keeps the row, creates any new tables, runs pending migrations, and
materialises declared foreign keys. That is what a replacement needs. One
addition: after the files are in place, the class map is flushed
(`ClassAutoloader::flush()`), so a request that arrives between the rename
and the copy, or that holds a stale map, does not fail to find a class that
moved. The window between setting the old directory aside and the copy
finishing is the same as a deploy's and is accepted.

## Files

- `includes/PackageInstallPage.php` — `upload()` returns a `pending_replace`
  outcome instead of a request id when the name is on disk, refuses a system
  extension, and sweeps staging directories older than a day;
  `replace_html()`; `confirmReplace()` and `discard()`
- `includes/MarketplaceClient.php` — `published_versions()`: the remembered
  catalog's name to version map, kept in the same day-old copy
- `includes/AbstractExtensionManager.php` — `refreshFromUpstream()` refuses
  to replace a local fork, naming Allow upgrade; `installFromTarGz()`'s
  refusal says the same
- `adm/logic/admin_themes_logic.php`, `adm/logic/admin_plugins_logic.php` —
  `replace_staged` and `discard_staged` actions; the panel in the page vars
- `adm/admin_themes.php`, `adm/admin_plugins.php` — show the panel where the
  warning block shows
- `includes/RootRequest.php` — the `replace` argument documented on
  `PACKAGE_KIND`
- `utils/root_request.php` — `--replace` from the argument
- `utils/install_extension.php` — the transcript line and the
  `package_replaced` event-log row; the kept copy only after the verdict
  and the acknowledgement
- `includes/PluginManager.php` — class map flush after a replacement's
  register step, if not already there; `writeManifestReceivesUpgrades()`
- `includes/DeploymentHelper.php` — `copyPreservedToStaging()` reads the
  live manifest before the incoming one
- `utils/install_extension.php` — an uploaded plugin or theme is marked a
  local fork in the live manifest before the database half runs; the row
  follows the manifest; `--uploaded` is retired
- `utils/root_request.php` — `set_receives_upgrades` for plugins
- `adm/admin_plugins.php`, `adm/logic/admin_plugins_logic.php` — the
  Disable upgrade / Allow upgrade pair, the Local fork badge
- `adm/admin_themes.php` — the Local fork badge and the Allow upgrade
  confirmation sentence
- `tests/core/deploy_preserve_fork_test.php` — **new**, safe tier: fixture
  live and staging trees; a live `false` is preserved whatever the
  incoming manifest says; absent from the archive is preserved; incoming
  `false` is preserved; live `true` with incoming `true` is replaced
- `tests/security/package_replace_test.php` — **new**, safe tier: the
  dispatcher adds `--replace` only for a true argument; the installer with
  `--replace` and a refused verdict leaves the live directory untouched
  (fixture trees, a throwaway key, as `package_signature_test` builds them)
- `tests/core/package_replace_page_test.php` — **new**, db tier: upload of
  an existing name stages and returns `pending_replace` without queueing;
  Discard removes the staged directory; Replace queues a request carrying
  `replace: true`
- `docs/plugin_developer_guide.md` — one paragraph under the upload
  section: an upload of an installed package asks to replace it, the
  previous copy is kept beside it

## Work packages

- **WP1 — the request.** `replace` on the request, `--replace` from the
  dispatcher, the transcript line, the event-log row, the pinned order in
  the installer, the safe-tier test.
- **WP2 — the page.** `upload()`'s pending outcome, the replace panel,
  Replace and Discard on both pages, the db-tier test. Proven on dev: upload
  a theme twice and see the panel with both versions; Discard and see the
  staging directory gone; Replace and see the new version on the row, the
  kept copy beside it on disk, the event-log row; the same with a plugin.
- **WP3 — the fork survives deploys (B5).** The live-manifest rule in
  `copyPreservedToStaging()`, the uploaded plugin marked a fork, the
  Plugins page's toggle, the Local fork badge on both pages, the Allow
  upgrade confirmation, the safe-tier test. Proven on dev: fork a shipped
  plugin by upload, publish and apply an upgrade to a test node that
  carries the plugin, and see the fork still there; press Allow upgrade,
  apply again, and see the shipped version back.
- **WP4 — docs.** The upload paragraph, and the fork model in the
  developer guide's distribution-flags section: what `receives_upgrades`
  means on a site (live manifest, operator's choice) versus in a published
  package.

## Acceptance

1. Uploading a theme or plugin whose name is not on disk behaves exactly as
   today: queued at once, no panel.
2. Uploading one whose name is on disk queues nothing and shows the panel
   with both versions; Discard removes the staged directory; Replace queues
   the request with `replace: true`.
3. A signed replacement installs; the row keeps its id, trust and
   `receives_upgrades`; the previous copy is at `<name>.replaced.<time>` and
   only the newest kept copy remains.
4. An unsigned page theme or plugin uploaded as a replacement reaches the
   warning page, and after acknowledgement installs under the unsigned
   restrictions; the live directory is untouched until then.
5. An unsigned style theme uploaded as a replacement installs without the
   warning, and a style theme that gained pages is dropped from the look
   slot with the transcript line.
6. A plugin replacement keeps every row the plugin owns and runs the new
   version's pending migrations.
7. The event log has a `package_replaced` row for every replacement.
8. An uploaded plugin, fresh or replacing, has `receives_upgrades = false`
   on its row and in its live manifest, as an uploaded theme does.
9. A deploy whose archive carries a name whose live manifest says `false`
   leaves the live copy in place; after Allow upgrade the next deploy
   replaces it with the shipped version.
10. Both pages show Local fork on a forked extension with the hover text,
    and the Plugins page can toggle it.
11. `php tests/run.php db --changed` passes with the three new tests.
12. After a fork is made and the sync runs, the row still says `false`:
    the manifest carried it (B6).
13. A marketplace install or repair of a fork's name is refused with a
    sentence naming Allow upgrade; the fork's files are untouched.
14. Uploading a system theme's name shows the refusal, not the panel, and
    leaves nothing in staging.
15. A fork whose name the remembered catalog lists at another version shows
    that version beside the Local fork badge.

## Decisions

- **Q1, 2026-10-02** — Replace is allowed on an active plugin. A deploy
  replaces active plugins' files the same way, and deactivating first can
  refuse when a theme requires the plugin. The in-use line on the panel
  says what will happen.

- **Q2, 2026-10-02** — the fork model. A site must be able to edit a
  plugin Joinery ships, and a fork in place under the shipped name is the
  only viable way, so the fork survives deploys until the operator presses
  Allow upgrade. The cost, no Joinery updates to that plugin meanwhile, is
  said on the page.

- **Q3, 2026-10-03** — is the fork model the right solution? Checked
  against the code before building. The upgrade script's own header
  already defines the flag as "operator on the target site says: replace
  this on upgrade; set false to keep a local fork", and the Themes page
  already has the Disable/Allow pair: the May 2026 deadlock fix (commit
  74e09a09) let the publisher's manifest override the target's choice and
  broke that doctrine, and this spec restores it. The deadlock that fix
  solved was a publisher flipping a shipped theme's flag from false to
  true; no shipped manifest says false, and the docs tell publishers to
  express "do not ship" with `included_in_publish` and `audience`, which
  never reach a node, so nothing Joinery ships depends on overriding a
  live false. The alternatives are worse: a fork under a new name is a
  different plugin (identity is the directory name); an overlay layer
  already exists for what it can cover (themes override plugin views,
  style themes override CSS, signal subscribers add behaviour) and cannot
  cover a plugin's logic or data classes without a new class-resolution
  layer. Backups and site copy both carry the code tree, so a fork
  survives restore and copy. The deployment helper is one of the five
  files the upgrade self-updates ahead of a release, so the new preserve
  rule applies on the first deploy that carries it. One gap is left open
  on purpose: a fork is marked only when it arrives by upload; an edit
  made on the node itself is unmarked until Disable upgrade is pressed.
  Every installed package carries a signed file listing, so "edited since
  install" is detectable without a flag; that is the Local Edits Detected
  spec, a follow-up.
