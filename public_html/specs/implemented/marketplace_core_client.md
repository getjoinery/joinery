# Marketplace client in core

**Status:** implemented 2026-08-15. Dev-verified (page, menu, altlinks, migration 173, db tier green); the node-side acceptance items (first live Install on a node, alias 404 on a non-publisher, API face over HTTP) await the next publish and are tracked in the live verification queue.

## The problem in plain terms

A Joinery site is supposed to get new plugins and themes by browsing the
marketplace and clicking Install. That page exists and works — but it lives
inside the server_manager plugin, which only the publishing box has. It was
swept into server_manager along with the publishing tooling when
`publish_upgrade` moved there; the publisher side belonged there, the client
side did not.

The consequence is a catch-22 on every managed node: the upgrade process only
refreshes plugins already on disk (`utils/upgrade.php` intersects installed
with published), so a node can never *acquire* a plugin it doesn't have — and
the page that would let an operator install one is itself inside a plugin the
node doesn't have. Concrete case: jeremytunnell.com runs the latest release
(0.8.272), messenger is published and flagged `included_in_publish`, and there
is no path — UI or upgrade — that gets it onto that site.

The fix is to move the marketplace client back into core, so every install
ships with it, and make it the front door for adding plugins and themes. The
publishing/catalog server side stays in server_manager — only the source site
needs it.

## What is true today

| Fact | Where |
|---|---|
| Marketplace client lives in the server_manager plugin | `plugins/server_manager/logic/admin_marketplace_logic.php`, `plugins/server_manager/views/admin/marketplace.php`, route `/admin/server_manager/marketplace` |
| Its menu entry is declared by the plugin | `plugins/server_manager/plugin.json` slug `system-marketplace`, permission 8 |
| The logic itself requires permission 10 — the menu and the page disagree | `admin_marketplace_logic.php:11` |
| The catalog/download server endpoint is in server_manager | `plugins/server_manager/includes/publish_theme.php`, served at `/admin/server_manager/publish_theme` (`?list=themes`, `?list=plugins`, `?download=name[&type=plugin]`) |
| Core ships a legacy alias that `require`s the plugin file — on a node without server_manager it fatals instead of 404ing | `utils/publish_theme.php` |
| Everything else the client needs is already core | `upgrade_source` in `settings.json:1879`; `AbstractExtensionManager::installFromTarGz()` (`includes/AbstractExtensionManager.php:312`) with `receives_upgrades: false` overwrite protection; `PluginManager` / `ThemeManager` |
| Node upgrades never deliver a new plugin | `utils/upgrade.php:519` — download list is `array_intersect(installed, published)`; only `required_plugins` (system-flagged) bypass it |
| admin_themes still links to the original core URL, now dead | `adm/admin_themes.php:73` → `/admin/admin_marketplace` (404) |
| docs claim the old URL redirects; no such route exists | `docs/deploy_and_upgrade.md:739`; `serve.php` has no marketplace entry |
| A disabled core menu row for the marketplace exists on installs that ran the old migrations | `migrations/migrations.php:563` inserted `system-marketplace` → `admin_marketplace`; `:599` later set `amu_disable = 1` |
| The core menu seed will not resurrect that row | `utils/update_database.php:718` — `syncMenus('core', …, ['overwrite' => false, 'prune' => false])`; existing rows are left untouched |
| The install form's CSRF is hand-rolled | `admin_marketplace_logic.php:100-123` — custom `$_SESSION['csrf_tokens']` array, not FormWriter/`validateCSRF()` |
| "Add New" on the plugins and themes pages means "upload a ZIP" | `adm/admin_plugins.php:26`, `adm/admin_themes.php:24` |

## The design

The marketplace client is a core superadmin page at `/admin/admin_marketplace`,
present on every install, and it is the primary way a site acquires plugins
and themes. ZIP upload remains as the secondary path for custom/private
extensions that are not in any catalog. Publishing and the catalog endpoint
remain in server_manager: a site needs server_manager to *serve* a catalog,
never to *consume* one.

The client keeps its current contract with the source: fetch
`{upgrade_source}/admin/server_manager/publish_theme?list=themes|plugins`,
download `?download={name}[&type=plugin]`, install via
`installFromTarGz()` + `sync()`, then send the operator to
`/admin/admin_plugins` or `/admin/admin_themes` to activate. The
`receives_upgrades: false` overwrite refusal is unchanged.

### Build plan

1. **Move the client to core.**
   - `adm/logic/admin_marketplace_logic.php` and the page/view under `adm/`,
     following the current admin-page pattern (see `docs/admin_pages.md`).
   - URL `/admin/admin_marketplace` — this un-breaks the existing
     `admin_themes.php:73` link. No serve.php entry needed (standard view
     resolution).
   - Permission **10** everywhere (page, logic, menu). Installing code is the
     same trust level as `admin_plugins`, which checks 10.
   - Delete the server_manager copies and the `system-marketplace` menu entry
     from `plugins/server_manager/plugin.json`. No redirect from
     `/admin/server_manager/marketplace` — only the publishing box ever served
     that URL.

2. **Menu.** Add `system-marketplace` to `admin_menus.json` (System group,
   near Plugins/Themes, `admin_marketplace`, permission 10, icon `store`).
   Because the core seed never touches existing rows, add a data migration
   that re-enables and repoints the row where it already exists:
   `UPDATE amu_admin_menus SET amu_disable = 0, amu_defaultpage = 'admin_marketplace' WHERE amu_slug = 'system-marketplace'`.
   Fresh installs get it from the JSON alone.

3. **Modernize while moving.**
   - Replace the hand-rolled CSRF session array with the platform mechanism:
     FormWriter-emitted tokens (the install buttons stay single-button action
     forms — the allowed exception) verified with `validateCSRF()` in the
     handler, handled before render.
   - Strip Bootstrap-era classes from the view; the admin theme is vanilla
     jy-ui.
   - Add a `_logic_descriptor()` exposing list and install as API actions, per
     the API endpoint rule for touched features. This is also the hook a
     future "push plugin to node" Server Manager action would call.

4. **Make it primary.**
   - `adm/admin_plugins.php`: `Add New` altlink points to
     `/admin/admin_marketplace`; keep `Upload ZIP` as a separate, secondary
     altlink for custom plugins.
   - `adm/admin_themes.php`: same treatment; the upload panel's marketplace
     link already points at the core URL.
   - Marketplace page links back to Plugins/Themes for activation (already
     does, via the post-install message).

5. **Fix the legacy alias.** `utils/publish_theme.php` guards with
   `is_file()` on the server_manager include and returns 404 when the plugin
   is absent, instead of fataling. It ships in core to every node but can only
   work on a publisher.

### Out of scope

- Server-side publishing and the catalog endpoint — stay in server_manager.
- Pushing a plugin *to* a node from the Server Manager dashboard (the
  marketplace covers the pull path; the descriptor from step 3 is the future
  hook).
- Paid/entitlement gating of catalog items — see `specs/plugin_entitlement_gate.md`.
- Setup-wizard integration (offering marketplace picks during first run).

## Documentation (lands with the build)

- `docs/deploy_and_upgrade.md` § Marketplace — file paths, URL
  `/admin/admin_marketplace`, permission 10; delete the stale redirect note at
  line 739; state the primary-vs-ZIP-upload split.
- `docs/admin_pages.md` — no change expected; verify the page follows it.

## Tests

- `tests/` safe-tier test: catalog/local-status merge (`enrich_with_local_status`
  behavior), missing-`upgrade_source` render path, and install action refused
  without a valid CSRF token.
- Existing gate `tests/unit/core_api_mechanical_test.php` must stay green
  (descriptor addition).

## Acceptance

1. A site with only core (no server_manager) shows System > Marketplace,
   lists the source's catalog, and installs a plugin that then appears on
   `/admin/admin_plugins` for activation.
2. Live proof: install messenger on jeremytunnell.com through the page —
   this is also the first live acquisition of a new plugin by a node.
   (Add to the live verification queue.)
3. `/admin/admin_themes` upload panel's marketplace link resolves.
4. `/utils/publish_theme` on a node without server_manager returns 404, not a
   fatal.
