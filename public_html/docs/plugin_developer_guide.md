# Plugin and Theme Developer Guide

## Licensing

The Joinery license includes a **plugin and theme exception**. Plugins and themes you create are yours — you may license them under any terms you choose, including commercial terms. The PolyForm Noncommercial license covers Joinery's core code, not your extensions. See the [Plugin and Theme Exception](../../LICENSE.md#plugin-and-theme-exception) in LICENSE.md for the full text.

## Overview

This guide outlines the current plugin and theme architecture after implementing the hybrid plugin/theme system. The system provides clear separation of concerns between plugins (backend-only) and themes (user-facing routing and presentation), while enabling themes to seamlessly integrate with plugin functionality through a sophisticated view resolution system.

## Current Architecture

### Plugin Architecture

**Plugins provide:**
- Data models and business logic
- Admin interfaces
- Database migrations
- API endpoints and webhooks
- Background processing
- User-facing views served under the plugin's URL namespace (see [Plugin URL Namespace](#plugin-url-namespace) below)

### Theme Architecture (Frontend + Routing)

**Themes handle all user-facing functionality:**
- URL routing and route definitions
- Public page templates and views
- Static assets (CSS, JS, images)
- User interface presentation
- Integration with plugin backend services
- Theme-specific class implementations (PublicPage, FormWriter extensions)
- CSS framework-specific customizations

#### Hybrid Plugin/Theme System

The system now supports a hybrid approach where:
- **Plugin views can be accessed by themes** through the view resolution fallback chain
- **Themes can override plugin views** by creating their own versions
- **Multiple fallback paths** ensure views are found even when themes don't provide them
- **Theme-specific includes** allow custom class implementations while maintaining compatibility

### Route Processing Order

> For complete routing documentation (adding pages, route options, common patterns), see **[Routing](routing.md)**.
> This section covers how routing interacts with plugins and themes.

Routes are processed in this order:
1. **Static routes** - Direct file serving with caching
2. **Theme routes** - Theme-specific routing (serve.php in theme directory)
3. **Plugin routes** - Merged from active plugin serve.php files (namespaced)
4. **Custom routes** - Complex logic routes (in main serve.php)
5. **Dynamic routes** - Standard view and model routes
6. **View fallback** - Auto-discovery: theme → plugin namespace → base → 404

#### View Resolution Chain

When a view is requested, the system searches in this order:
1. **Theme-specific view** - `/theme/{theme}/views/{view}.php`
2. **Plugin views** (if plugin specified) - `/plugins/{plugin}/views/{view}.php`
3. **Base system views** - `/views/{view}.php`
4. **404 error** if no view is found

This allows themes to override any view while providing automatic fallback to plugin or system defaults.

## Plugin Development

### Where does each piece go?

Before diving in, a quick reference for the four common things plugins need to register. Use this to jump to the right section — each row points to the one canonical path.

| What you're adding | Where it goes | Section |
|---|---|---|
| Runtime hook registrations (upload purposes, File decrypt hooks, policy callables, window caps, deferred work) | a bootstrap file named by the top-level `bootstrap` key in `plugin.json` | [Bootstrap](#bootstrap-declarative) |
| Tables and columns | `$field_specifications` in a data class under `data/` — applied automatically on install and sync | [Table Creation](#table-creation-automatic) |
| Admin menu entries | `adminMenu` key in `plugin.json` — created on activate, removed on deactivate/uninstall | [Admin Menus](#admin-menus-declarative) |
| Default plugin settings | `settings` array in `plugin.json` — seeded on activate and sync | [Plugin Settings](#plugin-settings-declarative) |
| Signals your plugin emits, or reactions to existing signals | `signals` / `signalSubscribers` keys in `plugin.json` | [Plugin Signals & Subscribers](#plugin-signals--subscribers-declarative) |
| A cloud-offload store profile (move rows' bytes to a bucket) | `storage_profiles` array in `plugin.json` — a `StorageProfile` class name | [Storage Profiles](#storage-profiles-declarative) |
| A cross-instance payload kind on the Joinery Direct channel | `directKinds` key in `plugin.json` — kind name → handler class (`gate`/`ingest`) | [Joinery Direct](joinery_direct.md#serving-a-kind-the-plugin-surface) |
| Other initial data (seed rows, categories, etc.) | `.sql` file in `migrations/`, numbered for order, idempotent | [Migration System](#migration-system) |
| Activate/deactivate logic | `activate.php`, `deactivate.php` at the plugin root, each defining `{plugin}_activate()` / `_deactivate()` | [Plugin Lifecycle](#plugin-lifecycle) |
| Uninstall external cleanup *(optional)* | `uninstall.php` defining `{plugin}_uninstall()` — only for work the declarative systems can't do (external API calls, filesystem cleanup) | [Uninstall Script](#uninstall-script) |

If you find yourself writing SQL to INSERT menu rows, or CREATE TABLE statements in a migration, stop — you're on the wrong path. Those pieces come from the data class and `plugin.json` respectively.

### Bootstrap (Declarative)

A plugin whose hooks must be **registered at runtime** — an upload purpose
(`UploadPurposeRegistry::register()`), a File decrypt hook
(`File::registerDecryptHook()` / `registerStreamingDecryptHook()`), a policy
callable (the `MailIdentityGuard` shape), a window-cap provider, a
deferred-work consumer — declares one load point in `plugin.json`:

```json
"bootstrap": "includes/bootstrap.php"
```

The file runs **once per request, lazily**, loaded by `PluginBootstraps` the
first time any registry needs its callbacks live, while the plugin is active.
It should contain registrations and requires only — no request work, no output,
nothing that assumes a signed-in user. Static registries reset with every
request, so registration belongs here rather than in an activate hook.

Load order follows `vaultConsumer.order` for plugins that declare one (see
[Building a Vault Consumer](#building-a-vault-consumer)); a plugin without it
loads after every ordered consumer, sorted by plugin name. A bootstrap is never
`require`d directly from other code — anything needing a class it defines calls
`VaultUnlock::loadConsumerBootstraps()` and lets the loader run it, which is
what keeps each registration attributed to its plugin.

**Contributing setup wizard steps.** A plugin registers steps into the
`SetupSteps` registry from its bootstrap (`SetupSteps::register('key', [...])`
— see `docs/admin_pages.md` § Setup steps for the field contract). The registry
pulls plugin bootstraps in itself before anyone reads it, so a bootstrap-side
registration is all a step needs to appear in `/setup`, the header pill, and
the login gate. Keep the step's `render_file` partial under the plugin's
`includes/` (not `views/`, which would make it routable), and keep `status()`
cheap — it runs when the wizard or pill asks, wrapped so a throw reads as
not-started rather than a fatal. The mailbox plugin's `mail_receive` /
`mail_import` steps are the reference implementations.

### Classes Resolve By Name

Name a class and it loads. That covers every class in core `includes/` and
`data/`, and every class in an active plugin's `includes/` and `data/` — your
own plugin's classes included. Nothing has to be required first.

`PathHelper` stays for files that are not classes: view fragments, templates,
seed data, the Composer autoloader.

```php
// In any plugin file (admin, views, includes, etc.)

// ✅ CORRECT — name the class, it loads
$settings = Globalvars::get_instance();
$theme = $settings->get_setting('theme_template');

$user = new User($session->get_user_id(), TRUE);
$notes = new MultiAcmeNote(array('user_id' => $user->key));

// ✅ CORRECT — PathHelper for a file that is not a class
require_once(PathHelper::getIncludePath('plugins/acme/includes/acme_panel.php'));

// ❌ WRONG — hand-built relative paths
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(__DIR__ . '/../../data/users_class.php');
```

A class added while a site is running resolves without a cache flush: the
lookup rebuilds its map once before giving up. Activating or deactivating a
plugin drops the map, so the active set and the resolvable set never disagree.

An inactive plugin's classes deliberately do NOT resolve — that is what makes
`class_exists('SomePluginClass')` a truthful answer to "is this feature
available here?"

#### Common SessionControl methods

`SessionControl::get_instance()` is the only session entry point. The methods a plugin most
often needs:

| Method | Returns | Use |
|--------|---------|-----|
| `is_logged_in()` | bool | Gate member-only behaviour |
| `get_user_id()` | int user id (0/none if not logged in) | Scope queries to the current member — essential for any member-owned plugin data |
| `get_permission()` | int permission level | Compare against the [permission ladder](#) (0 any member, 5+ admin) |
| `check_permission($level)` | void (redirects/401s if unauthorized) | First line of an admin page — redirects to `/login` if not logged in, throws 401 if under-ranked |
| `get_timezone($default = NULL)` | IANA tz string | Pass to `LibraryFunctions::convert_time()` for display |
| `set_return()` / `get_return()` | void / url | Remember where to send the user back after login |
| `get_shopping_cart()` | cart object | Commerce flows |

Read `includes/SessionControl.php` for the full surface; the above is what most plugin code
touches. Note `get_user_id()` is the member-scoping primitive — there is no `$_SESSION` access
in plugin code.

### Plugin Naming

Plugin directory names appear directly in user-facing URLs (`/{pluginname}/*`, `/profile/{pluginname}/*`, `/admin/{pluginname}/*`), so choose them carefully:

- **Must be distinctive** — avoid generic names like `events`, `billing`, `users`
- **Use the product or brand name** — e.g. `scrolldaddy`, `mailbox`
- **Short, lowercase, underscores for multi-word** — e.g. `dns_filtering` not `DnsFiltering`
- **Must not match a reserved system segment** — the following names are rejected at activation:
  `profile`, `admin`, `login`, `ajax`, `api`, `assets`, `theme`, `plugins`, `views`, `uploads`, `utils`, `tests`, `docs`, `specs`, `migrations`, `data`, `includes`, `logic`, `adm`
- **Must not clash with existing base view filenames** — if `views/profile/billing.php` exists, a plugin named `billing` is rejected

A plugin name that passes activation will own its URL namespace for the lifetime of the install. Choose something that won't conflict with other plugins or future system pages.

### Plugin URL Namespace

Every active plugin owns three URL prefixes automatically:

| URL pattern | View file | Example |
|-------------|-----------|---------|
| `/{plugin}` | `plugins/{plugin}/views/index.php` | `/scrolldaddy` |
| `/{plugin}/*` | `plugins/{plugin}/views/*.php` | `/scrolldaddy/pricing` |
| `/profile/{plugin}` | `plugins/{plugin}/views/profile/index.php` | `/profile/scrolldaddy` |
| `/profile/{plugin}/*` | `plugins/{plugin}/views/profile/*.php` | `/profile/scrolldaddy/devices` |
| `/admin/{plugin}` | `plugins/{plugin}/views/admin/index.php` | `/admin/scrolldaddy` |
| `/admin/{plugin}/*` | `plugins/{plugin}/views/admin/*.php` | `/admin/scrolldaddy/settings` |

**Auto-discovery:** Create a view file and the URL works immediately — no serve.php entry needed. The router searches: theme override → plugin directory → base directory → 404.

**Index convention:** When the URL has no trailing path (e.g. `/profile/scrolldaddy`), the router loads `index.php` from the corresponding views subdirectory.

**Internal links must always use namespaced URLs:**
```php
// ✅ CORRECT
<a href="/profile/scrolldaddy/devices">My Devices</a>

// ❌ WRONG — only works on sites where this plugin IS the theme
<a href="/profile/devices">My Devices</a>
```

**Plugin-as-theme shortcut:** When a plugin is set as the active theme (`theme_template = 'plugin'`), its views are found through theme resolution. Both `/profile/devices` (clean URL via theme) and `/profile/scrolldaddy/devices` (namespaced URL) resolve to the same file.

**Adding permissions or model binding:** Use serve.php for routes that need more than a view file — but the route pattern must be within the namespace:
```php
// plugins/myplugin/serve.php
$routes = [
    'dynamic' => [
        '/profile/myplugin/settings' => [
            'view'           => 'views/profile/settings',
            'min_permission' => 0,
        ],
    ],
];
```
Routes outside the namespace are dropped with a logged warning.

### Required Plugin Structure

```
/plugins/my-plugin/
├── plugin.json                  # Plugin metadata
├── serve.php                    # Only needed for routes requiring model/permission config
├── views/
│   ├── index.php                # /myplugin (landing page)
│   ├── pricing.php              # /myplugin/pricing
│   ├── profile/
│   │   ├── index.php            # /profile/myplugin
│   │   ├── dashboard.php        # /profile/myplugin/dashboard
│   │   └── settings.php        # /profile/myplugin/settings
│   └── admin/
│       ├── index.php            # /admin/myplugin
│       └── config.php           # /admin/myplugin/config
├── data/                        # Data model classes
├── logic/                       # Business logic (LogicResult pattern)
├── admin/                       # Admin interface files (/adm/admin_*)
├── ajax/                        # External webhooks only (page JS uses /api/v1 actions)
├── includes/                    # Helper classes and libraries
├── migrations/                  # Database migrations
├── sync.php                     # (optional) runs on every sync — seed declared rows here
└── uninstall.php               # (optional) external-cleanup hook — most plugins don't need one
```

### Plugin.json Requirements

**Minimum required plugin.json:**
```json
{
    "name": "My Plugin Name",
    "version": "1.0.0",
    "description": "Plugin description"
}
```

**Complete plugin.json example:**
```json
{
    "name": "My Advanced Plugin",
    "description": "A comprehensive backend plugin",
    "version": "2.1.0",
    "author": "Your Name or Company",
    "license": "MIT",
    "status": "beta",
    "homepage": "https://yoursite.com/plugin-docs",
    "requires": {
        "php": ">=8.0",
        "joinery": ">=1.0",
        "extensions": ["pdo", "json", "curl"],
        "composer": { "vendor/package": "^2.0" }
    },
    "depends": {
        "core-plugin": ">=1.0"
    },
    "host_installer": "provisioning/install_myplugin.sh",
    "provides": ["api-endpoint", "widget-support"],
    "tags": ["utility", "api", "backend"]
}
```

> **Informational-only keys.** `author`, `license`, `homepage`, `tags`, and `provides` are
> documentation metadata — the system reads the manifest but does **not** act on these keys.
> In particular, `provides` does **not** create any dependency, capability, or routing effect;
> declaring `provides: ["widget-support"]` does not make another plugin able to `depends` on it.
> The keys the loader actually consumes are `name`, `version`, `description`, `requires`,
> `depends`/`conflicts`, `settings`, `adminMenu`, `profileMenu`, `provisioners`,
> `directKinds`, `host_installer`, `receives_upgrades`, `included_in_publish`, `status`,
> and `deprecated`/`superseded_by`.

#### Licensing and maturity metadata

**`license`** names the terms the plugin ships under and must agree with the
`LICENSE.md` file in the plugin's directory. First-party free plugins declare
`PolyForm-Shield-1.0.0`; the commercial ones (`store`, `server_manager`)
declare `Joinery-Commercial`. The core `LICENSE.md`'s plugin and theme
exception means third-party authors license their own plugins however they
choose — a plugin that interfaces with the platform through its extension
points is not a derivative work of the core.

**`status`** is an honest maturity label: one of `experimental`, `beta`,
`stable`, or `deprecated`. Absent means `stable` and renders no badge; any
other value renders a badge wherever the plugin is listed (the admin Plugins
page and the distribution catalog). An unknown value is a manifest validation
error, not a silently ignored string. Status gates nothing — an
`experimental` plugin installs, activates, and updates exactly like a
`stable` one.

**`requires_entitlement`** marks a commercial plugin whose license is sold as
a store product. It is surfaced by the distribution catalog
(`?list=plugins`) so installers and marketplace listings can tell paid
plugins from free ones. Delivery is not gated on it — buying the plugin
issues a per-buyer license key (see the store docs), and the license terms,
not a technical check, carry the one-production-instance grant.

#### Component Versioning

The `version` field is the source of truth for a component's released identity, and it carries through to its archive filename (`{name}-{version}.tar.gz`). Versioning is governed by three rules:

- **Authors bump minor/major for meaningful releases.** A new feature or behavior change is a minor (or major) bump you make in the manifest and commit.
- **Publish auto-bumps the patch when content changed without a bump.** At publish time, each component's working tree is content-hashed and compared against the last release's snapshot. If the files changed but the version did not, the publisher patch-bumps the manifest automatically and lists it in the publish summary for you to commit. This keeps archive filenames honest even when a content change ships without a manual bump. (See [Deploy and Upgrade](deploy_and_upgrade.md) for the full decision rule.)
- **Activation is gated on `requires`** for plugins *and* themes. A component whose `requires.joinery` / `requires.php` / `requires.extensions` are not satisfied is refused activation with the specific failure reported. The gate runs only on activation, so an already-active component that newly fails requirements keeps running.

**Dependency version constraints** (`depends`) are evaluated against the dependency's **live manifest** version, read from its `plugin.json` on disk — not a cached copy. A constraint like `"depends": {"mailbox": ">=1.10.0"}` is satisfied whenever the installed `mailbox` manifest reports a version that meets it.

#### Declaring Dependencies

A plugin that needs something installed on the host declares it; the platform installs it at the moments that have the privileges to do so. There are three tiers, matched to what each dependency kind needs:

**1. PHP extensions — `requires.extensions`.** Declare the extension name (e.g. `"extensions": ["imagick"]`). The install tooling reads every bundled plugin's declarations via `utils/list_dependencies.php` and apt-installs the union at the root moments: Docker image build, `install.sh` site build, and `upgrade.php` on nodes. Activation checks `extension_loaded()` and refuses with the specific missing extension when the host somehow lacks it — the same gate applies to themes via `theme.json` `requires.extensions`. Core's own extension requirements live as `ext-*` entries in root `composer.json`, not in any plugin manifest.

**2. Composer libraries — `requires.composer`.** Declare `{"vendor/package": "constraint"}`. This is the one tier that installs **at activation**: `PluginManager` runs a reconcile (owned by `ComposerValidator::reconcilePluginPackages()`) that `composer require`s anything missing into the single root `composer.json` — there is one manifest, one vendor dir, one autoloader. Composer resolves each constraint; an unsatisfiable one refuses activation with composer's output. Because the root manifest holds one constraint per package, two plugins declaring the **same package with different constraints** is detected before composer runs and refuses activation naming both plugins (identical constraints are fine — declare what you need even if another plugin already ships it). The reconcile also runs at `update_database` time, covering deploys that add a dependency to an already-active plugin. Reconciled packages are recorded in `composer.json` `extra.joinery-plugin-packages`; `utils/list_dependencies.php --orphans` lists recorded packages no plugin declares anymore (deactivation never removes packages). Activation-time reconcile needs network access to the composer registry.

**3. Host services and system packages — `host_installer`.** For anything with real host-level complexity (daemons, config files, apt packages beyond PHP extensions), the plugin ships its own installer script and declares its path: `"host_installer": "provisioning/install_myplugin.sh"`. The path must stay inside the plugin directory. The script's contract:

- **Idempotent** — it runs on every container start; re-running must be safe and cheap.
- **Root** — it may `apt-get install` and edit `/etc`; it should verify root and refuse otherwise.
- **Non-interactive** — set `DEBIAN_FRONTEND=noninteractive`; never prompt.
- **Exit 0 when not-applicable** — plugin inactive for the site, feature setting off, wrong platform.

The runner `maintenance_scripts/install_tools/_plugin_installers_start.sh` executes every **active** plugin's declared installer at the root moments without systemd: the container `CMD` (every start), `install.sh` site builds, and `upgrade.php`. On a bare-metal node, activating a plugin after install has no such moment — run the installers on demand from the node's detail page in Server Manager (Actions → Run Plugin Installers), which queues a `run_plugin_installers` job. The runner is fail-safe — an installer failure logs a warning and never blocks container start. The Mailbox plugin's `provisioning/install_email.sh` is the reference implementation.

Activation cannot run a `host_installer` (web requests lack root) — pair the installer with `provisioners` entries (below) so the admin UI detects missing host state and points at the fix; on Docker nodes a container restart runs the installer automatically.

#### Deprecation Fields

Plugins (and themes) support two optional deprecation fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `deprecated` | bool | `false` | Marks the extension as deprecated |
| `superseded_by` | string | `null` | Directory name of the replacement extension |

```json
{
    "name": "Old Plugin",
    "version": "1.0.0",
    "receives_upgrades": true,
    "included_in_publish": true,
    "deprecated": true,
    "superseded_by": "new-plugin"
}
```

**Effect of `deprecated: true`:**
- Admin list pages show a "Deprecated" badge and sort the extension to the bottom
- Activating a deprecated extension shows a warning message (activation is not blocked)
- Deprecated extensions are excluded from deployment archives for new installs
- Existing sites already running a deprecated extension continue to receive updates normally

### Data Models

Plugins provide data models using the SystemBase pattern. For a complete annotated reference model
covering every property — schema, REST API exposure/authorization, AI surface, deletion, and the
Multi collection class — see **[`docs/example_class.php`](example_class.php)**.

```php
// plugins/my-plugin/data/my_data_class.php
class MyData extends SystemBase {
    public static $prefix = 'mdt';                 // REQUIRED — see below
    public static $tablename = 'mdt_my_data';
    public static $pkey_column = 'mdt_id';

    public static $field_specifications = array(
        'mdt_id'          => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
        'mdt_usr_user_id' => array('type' => 'int8', 'is_nullable' => true),
        'mdt_name'        => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
        'mdt_description' => array('type' => 'text', 'is_nullable' => true),
        'mdt_created'     => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
    );

    // REQUIRED for every foreign-key-shaped column: what happens to these rows
    // when the referenced row is deleted. An undeclared relationship registers
    // as 'prevent' and refuses the referenced row's deletion. See
    // docs/deletion_system.md for how to choose an action.
    protected static $foreign_key_actions = array(
        'mdt_usr_user_id' => array('action' => 'set_value', 'value' => User::USER_DELETED)
    );
}
```

**Schema dialect — get this right.** Column types are SQL type strings: `int8` (with
`'serial' => true` for an auto-increment primary key), `varchar(255)`, `text`, `timestamp(6)`,
`numeric(10,2)`. Use `'is_nullable' => false` (a **schema** constraint) to make a column
`NOT NULL`; `'required' => true` is a separate **validation** key checked on save. There is no
`'length'` key and no bare `'int'` / `'varchar'` — those produce no usable column. (The same
correct dialect appears under [Table Creation](#table-creation-automatic).)

**The `$prefix` property is required.** Every column in the table is named `{$prefix}_{field}`,
and the constructor throws `SystemBaseException('This object has no prefix.')` if it is unset
(`includes/SystemBase.php:59`). It is also the key FormWriter and the REST API use to map a
field back to its model. `$tablename` and `$pkey_column` are likewise required; a Multi
collection class additionally needs `$model_class` (see [Writing a Multi (collection) class](#writing-a-multi-collection-class) below).

**REST API exposure & per-record scope.** A core model is a CRUD resource only if it opts in with
`public static $api_readable = true;` and/or `$api_writable = true;` (both default `false`); plugin
models are never exposed to CRUD — expose plugin behaviour through action endpoints instead. For an
exposed model, the default row scope is **owner-or-staff (deny)**: a caller may touch a row only if
they own it (the `{prefix}_usr_user_id` column matches the acting user) or they are staff
(permission ≥ 5). Set `public static $api_public_read = true;` to make a resource world-readable
(catalog content), or override `authenticate_read($data)` / `authenticate_write($data)` for a custom
rule — copy the pattern from `data/orders_class.php`. See
[REST API → Per-record authorization](api.md#per-record-authorization).

**Deletion Behavior**: For complete documentation on defining foreign key actions, cascading deletes, soft-delete cascading patterns, and undelete strategies, see the [Deletion System Documentation](deletion_system.md).

#### Writing a Multi (collection) class

Every single-row model has a companion **Multi** class (collection) in the same file, extending
`SystemMultiBase`. It declares `$model_class` (the single-row class name) and implements
`getMultiResults()`, which translates caller-supplied option keys into SQL filters:

```php
class MultiMyData extends SystemMultiBase {
    public static $model_class = 'MyData';   // REQUIRED — the single-row class

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = array();

        // 1. Parameterized value (safe binding) — produces  mdt_usr_user_id = ?
        if (isset($this->options['user_id'])) {
            $filters['mdt_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
        }

        // 2. Literal condition appended as-is — produces  mdt_delete_time IS NULL
        if (isset($this->options['active'])) {
            $filters['mdt_delete_time'] = 'IS NULL';
        }

        // 3. ILIKE / raw fragment — produces  mdt_name ILIKE '%term%'
        if (isset($this->options['name_like'])) {
            $filters['mdt_name'] = "ILIKE '%" . $this->options['name_like'] . "%'";
        }

        return $this->_get_resultsv2(static::$model_class::$tablename, $filters, $this->order_by, $only_count, $debug);
    }
}
```

Construct and use it:

```php
$rows = new MultiMyData(
    array('user_id' => $uid, 'active' => true),   // $options → filter keys above
    array('mdt_created' => 'DESC'),               // $order_by
    20, 0                                          // $limit, $offset
);
$total = $rows->count_all();   // count ignoring limit/offset
$rows->load();                 // populate
foreach ($rows as $row) { /* $row is a MyData */ }
```

The constructor signature is
`__construct($options = array(), $order_by = array(), $limit = NULL, $offset = NULL, $operation = 'AND', $write_lock = FALSE)`.
The **option keys are whatever `getMultiResults()` reads** (here `user_id`, `active`, `name_like`)
— they are deliberately *not* the raw column names. Always read a Multi class's
`getMultiResults()` to learn its accepted keys.

**AI Auto-Discovery**: To make a plugin model queryable by joinery_ai recipes, declare the three `$ai_*` static properties (`$ai_readable`, `$ai_description`, `$ai_excluded_fields`) on the class. Default-deny: omit them and the model stays invisible to AI tools. See the [Joinery AI Plugin Documentation](/plugins/joinery_ai/docs/overview.md) for the property contract and the auto-block regex.

### Business Logic Files

Plugin logic files follow the same LogicResult pattern as core logic files. Every logic file in the codebase — core or plugin — uses one signature: `function foo_logic(array $input): LogicResult`. There is no second variant. For comprehensive documentation, see the [Logic Architecture Guide](logic_architecture.md).

```php
// plugins/my-plugin/logic/my_feature_logic.php
<?php
function my_feature_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/my-plugin/data/my_data_class.php'));

    // Business logic processing
    $data = new MyData($input['id'], TRUE);

    // Use LogicResult for consistent returns
    if (($input['action'] ?? null) === 'delete') {
        $data->soft_delete();
        return LogicResult::redirect('/plugins/my-plugin/admin/list');
    }

    return LogicResult::render(['data' => $data]);
}
?>
```

Key points for plugin logic files:
- Always use `LogicResult::render()`, `LogicResult::redirect()`, or `LogicResult::error()`
- Follow the naming convention: `[feature]_logic.php` with matching function name
- Include paths are relative to the plugin directory when using `__DIR__`
- Can be called from views, admin pages, or the router

### Exposing API Actions

A plugin logic function becomes a REST API action by adding the same `_logic_descriptor()` companion core logic files use:

```php
// plugins/my-plugin/logic/my_feature_logic.php

function my_feature_logic_descriptor(): array {
    return [
        'description'      => 'What this action does',
        'requires_session' => true,   // default: true
        'mutates'          => true,
        'input'            => [
            'device_id' => ['type' => 'int', 'required' => true],
        ],
    ];
}
```

The `input` schema drives boundary validation and appears in discovery — see [docs/api.md](api.md#making-a-logic-function-available-via-api) for the field types.

The action is addressed under the plugin's namespace — `POST /api/v1/action/my-plugin/my_feature` — and listed in `GET /api/v1/actions` as `my-plugin/my_feature`. Resolution goes directly to `plugins/{plugin}/logic/{action}_logic.php`; only active plugins resolve, and the namespace means a plugin action can never collide with a core action or another plugin's.

- `requires_session => true` actions run under session simulation as the API key's user, so `SessionControl` works exactly as it does on the web. Use `$session->is_api_context()` when a function needs to return a JSON-clean payload instead of view-shaped objects.
- **Authorization** defaults to the action surface's standard: the key needs the write capability (`apk_permission >= 2`). To override — e.g. a read-only action, or one requiring a higher user role — add an `'auth'` block to the descriptor: `'auth' => ['capability' => 'read', 'min_user_permission' => 5]`. The contract is enforced by `ApiAuth::authorize()`; see [docs/api.md](api.md#declaring-endpoint-authorization).
- An optional `{action}_logic_form()` companion exposes a server-driven form definition at `GET /api/v1/form/{plugin}/{action}` — see [docs/api.md](api.md) and [docs/formwriter.md](formwriter.md#11-json-output-mode-server-driven-forms).
- Inputs arrive through the `$input` parameter (merged GET + JSON body). Do not read `$_REQUEST` — it never sees the JSON body.

### Admin Interface

Plugin admin pages are accessed via the plugin admin discovery route:
`/plugins/{plugin}/admin/{page}`

```php
// plugins/my-plugin/admin/admin_my_plugin.php
<?php
// Core files are already available - no need to require them
// PathHelper, Globalvars, and SessionControl are pre-loaded

// Use PathHelper for other includes
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new AdminPage();
$page->admin_header([
    'title' => 'My Plugin',
    'menu-id' => 'my-plugin',
    'readable_title' => 'My Plugin Management'
]);

// Admin interface content here

$page->admin_footer();
?>
```

**Two admin surfaces — which to use.** A plugin can render admin pages two ways, and both
resolve automatically:

| Surface | File | Gives you |
|---------|------|-----------|
| **AdminPage route** (recommended) | `plugins/{plugin}/admin/{page}.php` → `/plugins/{plugin}/admin/{page}` | The full admin shell: an `AdminPage` object, `admin_header()`/`admin_footer()` chrome, `$page->getFormWriter()`, sidebar highlight. Mirrors core `/adm/` + `/adm/logic/`. |
| **View auto-discovery** | `plugins/{plugin}/views/admin/{page}.php` → `/admin/{plugin}/{page}` | A plain view in the plugin's URL namespace, no AdminPage chrome. |

Use the **AdminPage route** (`plugins/{plugin}/admin/`) for real admin tooling — list tables,
edit forms, anything that should look and behave like the rest of the admin. Pair it with a
`plugins/{plugin}/admin/logic/{page}_logic.php` logic file, exactly as core admin pages pair
`/adm/` with `/adm/logic/`. Reach for `views/admin/*` only for a simple custom panel that
genuinely doesn't want the admin chrome.

#### Referencing plugin assets (CSS/JS) with cache-busting

A plugin's own assets live in `plugins/{plugin}/assets/` and are served by the built-in static
route `/plugins/{plugin}/assets/*` (the route enforces that the plugin is active and refuses to
serve `.php`). There is **no** `PluginHelper::asset()` helper — cache-busting is done inline with
`filemtime()`, the same mechanism themes use. Define a tiny closure in your view and reference
assets through it:

```php
$asset = function ($rel) {
    $path = PathHelper::getIncludePath('plugins/my-plugin/assets/' . $rel);
    return '/plugins/my-plugin/assets/' . $rel . '?v=' . (is_file($path) ? filemtime($path) : '1');
};
echo '<link rel="stylesheet" href="' . htmlspecialchars($asset('css/my-plugin.css')) . '">';
echo '<script defer src="' . htmlspecialchars($asset('js/my-plugin.js')) . '"></script>';
```

The `?v={filemtime}` query changes whenever the file changes, so browsers refetch on every edit
and otherwise cache. Always reference your assets by their namespaced `/plugins/{plugin}/assets/…`
URL — never a relative path.

The inline closure above is for assets that belong to **one page** (a page-specific reader script,
say). For a plugin's **stylesheet** — the sheet that styles its UI wherever it appears — declare it
instead (next section); don't echo a `<link>` per page.

#### Plugin Stylesheets (Declarative)

Declare a plugin's stylesheets under the `styles` key in `plugin.json` — an array of paths relative
to the plugin root. Every declared sheet loads on **every page while the plugin is active**,
cache-busted by file mtime, emitted **after the `.jy-ui` kit and before the active theme's
stylesheet**. That position is deliberate: your rules resolve the kit's `--jy-*` design tokens, and
a theme can still override them.

```json
{
  "name": "My Plugin",
  "styles": ["assets/css/my-plugin.css"]
}
```

Because the sheet is global, **scope every rule** so it stays inert until your markup opts in — wrap
your UI in `.jy-ui` and prefix classes `.jy-{plugin}-*` (e.g. `.jy-ui .jy-myplugin-card { … }`), or
use a distinctive component prefix. No call site is needed; `PluginHelper::renderActivePluginStyleLinks()`
emits the `<link>` tags from the head. Changes to `styles` take effect immediately (the manifest is
read live from disk) — no activation or `update_database` step.

### Plugin Menus (Declarative)

Plugins declare menu contributions in `plugin.json` under three keys:

- `adminMenu` — items in the admin sidebar (`/admin/*`).
- `profileMenu` — items in the member section nav (the horizontal nav across the member area) and the logged-out auth links.
- `settingsMenu` — sections in the member settings hub's left rail (`/profile/settings`).

All three keys are synced into the same `amu_admin_menus` table, distinguished by an `amu_location` column (`admin_sidebar` / `user_dropdown` / `member_settings`). The system automatically creates menu rows on activation, updates them on sync, and removes them on deactivation/uninstall. This is the only supported way to register plugin menus — do not INSERT into `amu_admin_menus` from migrations.

A `profileMenu` entry is the single way onto the member nav, everywhere: every theme renders the seeded store (`$menu_data['user_menu']['member_nav']`, each item `{label, link, icon, slug, parent}`) via `PublicPageBase::render_member_subnav()`, and the mobile apps' navigation endpoint reads the same store. Themes style the markup but never hardcode member nav entries — adding one entry in a manifest surfaces it on all web themes and in every shipped app with no theme edits or app release. The avatar dropdown itself is identity-only (Dashboard, Settings, Sign out) and is not extensible.

**Locations:**

| Location          | Source key     | Permission floor | Visibility |
|-------------------|----------------|------------------|------------|
| `admin_sidebar`   | `adminMenu`    | ≥ 1              | always `in` (logged in) |
| `user_dropdown`   | `profileMenu`  | ≥ 0              | `in` / `out` / `both` |
| `member_settings` | `settingsMenu` | ≥ 0              | `in` |

**Slug rules (both locations):**

- **Enforced at menu sync** (`PluginManager::syncMenus()`): each item must supply a
  `slug`, `title`, and `order`; the `slug` must be a non-empty string; and the `slug` must
  **not** start with `core-` (that prefix is reserved for core menu rows seeded by
  migrations). These are the only rules the live sync checks.
- **Recommended convention** (not validated): prefix the slug with your plugin name
  (e.g. `mybooks-shelf`), keep it to `[a-z0-9-]`, ≤ 32 chars, and unique within the plugin.
  This is what keeps your slugs from colliding with core or other plugins — but it is a
  convention, not an enforced requirement. A bare slug like `shelf` will sync fine, and a
  hyphenated plugin name is the right prefix for an underscore-named plugin directory
  (e.g. `dns_filtering` → `dns-filtering-…`).

#### `adminMenu`

**Three placement patterns:**

**1. Parent group with children** -- creates a top-level menu section:

```json
{
  "adminMenu": [
    {
      "slug": "my-plugin",
      "title": "My Plugin",
      "icon": "plug",
      "permission": 8,
      "order": 15,
      "items": [
        { "slug": "my-plugin-dashboard", "title": "Dashboard", "url": "/admin/my_plugin", "order": 1 },
        { "slug": "my-plugin-settings", "title": "Settings", "url": "/admin/my_plugin/settings", "order": 2 }
      ]
    }
  ]
}
```

Children inherit the parent's `permission` unless they override it.

**2. Child attachment** -- attaches to any existing menu by slug:

```json
{
  "adminMenu": [
    {
      "slug": "mailbox-reader",
      "title": "Mailbox",
      "url": "/plugins/mailbox/admin/admin_mailbox_reader",
      "parent": "emails",
      "permission": 5,
      "order": 10,
      "settingActivate": "mailbox_enabled"
    }
  ]
}
```

The `parent` value is the `amu_slug` of any menu in the system -- core menus, other plugin menus, or groups from the same plugin.

**3. Standalone top-level** -- a single entry with no children or parent:

```json
{
  "adminMenu": [
    { "slug": "my-tool", "title": "My Tool", "url": "/admin/my_tool", "icon": "wrench", "permission": 10, "order": 16 }
  ]
}
```

**Available fields:**

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `slug` | Yes | -- | Unique identifier (`[a-z0-9-]`, max 32 chars) |
| `title` | Yes | -- | Display text (max 32 chars) |
| `order` | Yes | -- | Sort position within parent level |
| `url` | No | `""` | Target page. URLs starting with `/` are stored as-is |
| `icon` | No | null | Icon identifier |
| `permission` | No | 10 | Min permission level (1-10) |
| `settingActivate` | No | null | Setting that must be truthy for menu to display |
| `disabled` | No | false | Whether disabled by default |
| `parent` | No | null | Slug of parent menu to attach under |
| `items` | No | null | Array of child menu items |

**Important:** Menus declared in `plugin.json` are the source of truth. Manual edits via the admin menu UI will be overwritten on the next sync.

#### `profileMenu`

Profile menu items appear in the member section nav (logged-in) or as auth links (logged-out, per `visibility`). Entries may name a `parent` slug — a parented entry belongs inside its section (linked from that section's own pages) and is dropped from the top-level nav; nested `items` are not supported.

```json
{
  "profileMenu": [
    {
      "slug": "scrolldaddy-filtering",
      "title": "Filtering",
      "url": "/profile/scrolldaddy",
      "icon": "shield",
      "visibility": "in",
      "permission": 1,
      "order": 75
    }
  ]
}
```

**Available fields:**

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `slug` | Yes | -- | Unique identifier; conventionally prefixed with the hyphenated plugin name (e.g. `myplugin-settings`) to avoid collisions. Sync enforces only that it is a non-empty string and does not start with `core-` (see the Slug rules above). |
| `title` | Yes | -- | Display text (max 32 chars). |
| `url` | Yes | -- | Target page (no `.php`). Stored as-is. |
| `order` | Yes | -- | Sort position in the dropdown. Core slots: home=10, profile=50, signout=200. |
| `icon` | No | null | Icon identifier passed through to theme renderers. |
| `visibility` | No | `"in"` | One of `"in"` (logged-in), `"out"` (logged-out), `"both"`. |
| `permission` | No | 0 | Min permission level (0-10). Only applies when logged in. |
| `settingActivate` | No | null | Setting that must be truthy for the row to display. |
| `disabled` | No | false | Whether disabled by default. |

`parent` names another profile-menu slug; nested `items` are not supported. A parented entry (e.g. AI Memory under AI) stays in the store — the mobile apps and the admin menu editor still see it — but the top-level member nav skips it, so its own section must link to it.

**Themes consuming the menu data** (`PublicPageBase::get_menu_data()`):

- `$menu_data['user_menu']['member_nav']` — the full seeded list `{label, link, icon, slug, parent}`; the member section nav renders the unparented, non-admin rows (`PublicPageBase::member_subnav_items()`).
- `$menu_data['user_menu']['items']` — the avatar dropdown: identity-only when logged in (Dashboard, Settings, Sign out); the seeded auth links when logged out.
- `$menu_data['user_menu']['launcher_items']` — the admin nine-dots launcher rows (`core-home`, `core-profile`, `core-admin-*`).

Filter by `slug` (e.g. `str_starts_with($item['slug'], 'core-admin-')`) — never by `label`, since admins can rename labels in the admin UI.

#### `settingsMenu`

Settings menu entries are sections on the member settings hub's left rail, rendered by `PublicPageBase::settings_layout_start()` across every settings page. Core seeds the account sections (Account, Password, Address, Phone Numbers, Contact Preferences, Notifications, Security); a plugin adds its member-owned configuration or account-scoped pages here.

```json
{
  "settingsMenu": [
    {
      "slug": "myplugin-preferences",
      "title": "My Plugin",
      "url": "/profile/myplugin/preferences",
      "icon": "cog",
      "permission": 0,
      "order": 85,
      "settingActivate": "myplugin_active"
    }
  ]
}
```

Fields match `profileMenu` (minus `visibility` and `nativeScreen` — the rail is logged-in web only). Core sections occupy orders 10–70; plugin sections conventionally start at 80. The rows are editable at `/admin/admin_admin_menu?location=member_settings`.

### Plugin Settings (Declarative)

Declaring a setting in `plugin.json` is the whole job: it seeds the row, gives
the plugin its own subtab on the **Plugin Settings** tab
(`/admin/admin_settings_plugins?plugin={name}`), and defines what a save is
allowed to write.
There is no form file. On activate and on every sync, PluginManager seeds any
declared row that doesn't already exist in `stg_settings`; existing values are
never overwritten.

```json
{
  "name": "My Plugin",
  "version": "1.0.0",
  "settingsGroups": { "connection": "Connection" },
  "settings": [
    { "name": "myplugin_enabled", "default": "1", "group": "connection",
      "label": "My Plugin enabled", "type": "select",
      "options": { "1": "Yes", "0": "No" } },
    { "name": "myplugin_max_items", "default": "50", "group": "connection",
      "label": "Max items", "type": "number",
      "validation": { "number": true, "min": 1 } },
    { "name": "myplugin_api_key", "default": "", "group": "connection",
      "label": "API Key", "secret": true }
  ]
}
```

**Fields:**

| Field | Required | Default | Description |
|---|---|---|---|
| `name` | Yes | — | Setting key. Must start with the plugin's directory name (unless `legacy_core`). |
| `default` | No | `""` | String value stored in `stg_value`. Always a string — use `"0"`/`"1"` for booleans, `"42"` for numbers. JSON-native booleans/numbers are rejected at validation time. |
| `group` | No | plugin name | Which box the field renders in. Headings come from the `settingsGroups` map. |
| `label` | For anything renderable | — | Field label. Without it the field renders under its raw name. |
| `type` | No | `text` | `text`, `number`, `checkbox`, `select`, `password`, `textarea`. |
| `options` / `options_from` | For `select` | — | Literal `value: label` map, or `Class::method` returning one. `options_from` needs `options_include` unless the class is core. |
| `validation` | No | — | A FormWriter rule array, verbatim. Enforced on every write path. |
| `show_when` | No | — | `{ "other_setting": "value" }` — reveals this field when that setting has that value. |
| `secret` | No | `false` | A credential: never emits its stored value, a blank submission keeps it, and a Clear checkbox beside it wipes it. |
| `vault_gated` | No | `false` | Changing it requires an open vault unlock window. |
| `managed` | No | `false` | Machine-written. Never rendered. Mutually exclusive with `label`. |
| `legacy_core` | No | `false` | Opts this one setting out of the prefix rule so it keeps an unprefixed core-era name. See below. |

The full field-spec reference, including how a plugin admin page requests a group
and wraps it with context, is in [Settings](settings.md).

**Validation rules** (enforced on activate and sync):
1. Every declared `name` must start with the plugin's directory name (e.g., a plugin at `/plugins/bookings/` must declare settings named `bookings_*`) — unless the entry sets `"legacy_core": true`.
2. No declared `name` may collide with a core setting in `settings.json` at the `public_html/` root.

**`legacy_core` — carrying a core setting into a plugin.** When a feature moves from core into a plugin, its settings keep their original unprefixed names on purpose — menu `settingActivate` keys, existing `stg_settings` rows, and code all reference the old name. Mark each such entry `"legacy_core": true` to opt out of the prefix rule (the store and event_manager plugins do this for `products_active`, `events_active`, etc.). The collision and string-default rules still apply: the name must have been **removed** from core `settings.json` when it moved. New settings never use this — it exists only for extractions.

Validation failures throw. On `activate()` the plugin does not activate; on `sync()` the offending plugin is skipped with a logged error and other plugins continue.

**Seed-only policy:** Existing setting values are never overwritten. If your plugin's v2 changes a declared default, existing sites keep their old value and only new installs get the new default. If you need existing sites to pick up a new default, write an SQL migration — silent default changes across upgrades have bitten production systems badly enough that the operator needs to opt in.

**Orphan rows:** Settings dropped from the manifest in a later version are **not** automatically deleted. Use an SQL migration if you need the row gone. Orphan setting rows are otherwise harmless — nothing reads them.

**Blank defaults:** `default: ""` creates a row with an empty value. Use this for things that have no meaningful factory default but should still be present (API keys, SMTP hosts, custom CSS). `get_setting()` returns `''` for anything unset (and logs a notice — pass the `$fail_silently` flag to suppress it), so supply a floor in code: `intval(...) ?: 100`.

**Uninstall:** On uninstall, PluginManager deletes rows matching the names in the current manifest. Settings declared in an earlier version but dropped from the current manifest are left in place.

### Storage Profiles (Declarative)

A plugin contributes a cloud-offload **store profile** — code that moves a table's rows' bytes to a bucket and pulls them back — by declaring a `StorageProfile` class name in `plugin.json` under an optional `storage_profiles` key. This sits next to `settings` and the menu keys as a plugin extension point. See [Cloud Storage](cloud_storage.md) for the full architecture (driver / engine + lifecycle / visibility / profile).

```json
"storage_profiles": ["MyPluginRawStore"]
```

- Each entry is **just a class name**. The class file lives at `plugins/<plugin>/includes/<ClassName>.php`, implements the `StorageProfile` interface (`includes/cloud_storage/StorageProfile.php`), and has a **no-argument constructor**.
- **Visibility comes from the profile, not the manifest.** The class's `visibility()` returns `'public'` or `'private'`; that single value decides which store the rows go to, how bytes are read back, and the privacy guarantee. The manifest never restates it.
- Declarations are read **off disk, active or not.** `StorageProfileRegistry` scans every plugin's `plugin.json` regardless of activation state, so the binding-immutability guard can always see a deactivated plugin's offloaded (`cloud`) rows — deactivation leaves the files (and so the declaration and class) in place.

**Drain before uninstall (private profiles).** Uninstalling a plugin removes its files, so its declaration and class disappear and the guard can no longer see its rows. A plugin that owns a **`private`** profile must therefore have its store **drained back to local first** (the Disable-and-Pull flow) before uninstall — otherwise its `cloud` rows become invisible to the guard and a later bucket change could strand them. Deactivation alone is safe; only uninstall requires the drain.

### Plugin Signals & Subscribers (Declarative)

Plugins integrate with the [signal bus](signals.md) through two optional `plugin.json` keys. Both are merged with core for active plugins only, cached per request.

**`signals`** — signals your plugin emits. Same shape as core `signals.json`: identity (`label`, `description`, `category`), a `payload` schema, and an optional `notify` block to make the signal produce notifications. Declaring a signal lets other plugins and core subscribers consume it; dispatch it from your plugin code with `SignalBus::dispatch()`.

```json
"signals": {
  "myplugin.thing_happened": {
    "label": "Thing happened",
    "description": "A thing happened in My Plugin.",
    "category": "My Plugin",
    "payload": { "thing_id": "Thing id", "source_user_id": "Acting user id" },
    "notify": {
      "ntf_type": "system",
      "supports_topic": true,
      "default_email": false,
      "title_template": "A thing happened (#{thing_id})",
      "body_template": "Thing {thing_id} happened.",
      "link_template": "/admin/myplugin/things"
    }
  }
}
```

To make a plugin signal notifiable, add the `notify` block inline as above — do **not** register a separate subscriber for it; the core Notify subscriber handles any signal that carries a `notify` block.

**`signalSubscribers`** — reactions to signals (yours or core's). Same shape as core `signal_subscribers.json`, with `file` relative to your plugin directory:

```json
"signalSubscribers": {
  "myplugin_provisioner": {
    "file": "includes/MyProvisioner.php",
    "class": "MyProvisioner",
    "method": "handle_signal",
    "signals": ["subscription.expired"]
  }
}
```

The handler is a static method `(string $signal, array $payload): void`. Keep inline work cheap (a local insert) and push slow work to a scheduled task — see the [handler cost budget](signals.md#handler-cost-budget). The file is required lazily, only when one of its signals fires.

### Seed Rows (`sync.php`)

Settings, menus and scheduled tasks are declared on disk and reconciled into the database automatically. A plugin that needs the same treatment for rows of its own — curated defaults it wants present on every install — does that in a `sync.php` hook.

```php
<?php
function myplugin_sync() {
    require_once(PathHelper::getIncludePath('plugins/myplugin/includes/ThingSeeder.php'));
    return ThingSeeder::seedDeclared();   // string[] messages for the sync report
}
```

The hook is `plugins/{name}/sync.php` defining `{name}_sync()`. It runs at the end of every `PluginManager::sync()` — after tables, migrations, settings and tasks, so it can rely on all of them — and again at activation. A hook that throws is logged and skipped; the rest of the sync completes, because sync is also how an operator repairs a broken install.

**The hook must be idempotent.** It runs on every deploy and on every admin sync.

Four rules make declared rows safe to ship, all of them learned the hard way:

- **Match on a declared key, not a name.** Put a nullable, unique `{prefix}_declared_key` column on the table. Names aren't unique, so without a key a re-sync can't tell an already-seeded row from a new declaration and duplicates everything on every upgrade.
- **Create only.** A declaration creates a row when one with its key doesn't exist, and otherwise does nothing. Never overwrite — the operator's edits are the whole reason the row is theirs now.
- **Count soft-deleted rows as existing.** Otherwise a row the operator deleted on purpose comes back at the next upgrade, for ever.
- **Removing a declaration deletes nothing.** A withdrawn declaration stops arriving on new installs and leaves existing ones alone.

Anything that only makes sense on the instance that authored the row — an owner id, a foreign key into another install's data, a model or provider name — must be fixed at seed time rather than declared. If a row can't arrive inert, it shouldn't ship.

Joinery AI is the worked example: `plugins/joinery_ai/recipes.json` declares a few curated recipes and its seeder is documented in [the plugin's overview](../plugins/joinery_ai/docs/overview.md#shipped-recipes).

### Plugin Lifecycle

**PluginManager is the single entry point for all lifecycle operations.** Plugin models (`Plugin`, `PluginHelper`) are pure CRUD — never call lifecycle methods directly on them.

Three states: `active`, `inactive`, and *uninstalled* (no row at all).

```
Discovery → Install → Activate ↔ Deactivate → Uninstall
              ↑                                    │
              └────────────── Install ─────────────┘
```

**Install** (`PluginManager::install($name)`)
1. Fetches a fresh archive from the upgrade endpoint and extracts over `plugins/{name}/`, so plugins with `included_in_publish: true` on the upgrade server get current code on every install; plugins not in the publisher's catalog 404 silently and install proceeds with on-disk files.
2. Validates plugin structure and dependencies
3. Creates/updates database tables from data class `$field_specifications` (via `DatabaseUpdater::runPluginTablesOnly()`)
4. Runs pending `.sql` migration files in `plugins/{name}/migrations/`
5. Records the plugin in `plg_plugins` with status `inactive`

**Activate** (`PluginManager::activate($name)`)
1. Re-validates dependencies
2. Runs `DatabaseUpdater::runPluginTablesOnly()` — picks up any `$field_specifications` changes since install
3. Runs `activate.php` hook (calls `{plugin_name}_activate()` if defined)
4. Registers deletion rules via PluginHelper
5. Creates rows for tasks declaring `activate_on_install`, then runs the `sync.php` hook
6. Resumes any suspended scheduled tasks for this plugin
7. Sets `plg_active = 1`

**Developer workflow for schema changes** — Modify `$field_specifications` on an already-installed plugin, then run **Sync with Filesystem** from the admin Plugins page (`/admin/admin_plugins?action=sync_filesystem`). Sync applies the full column reconciliation: new tables, new columns, type/length and nullability modifications (widening a `varchar`, adding `NOT NULL`) on existing columns, unique constraints, and indexes. Column *removal* is the one schema change sync never performs — dropping a column absent from the spec stays a deliberate migration-or-manual act. Schema changes are also applied automatically during deploys (`upgrade.php`) and when running `update_database` from admin utilities.

**Schema changes on inactive plugins are deferred.** Sync and `update_database` only touch tables for active plugins. If you modify `$field_specifications` on a plugin that is installed but not active, the schema change will not be applied until the plugin is next activated (`PluginManager::activate()` calls `runPluginTablesOnly()` as its first step).

**Sync** (`PluginManager::sync()`)
1. Scans filesystem — discovers new plugins, updates metadata from manifests, detects missing directories
2. Updates database tables for **all active plugins** via `DatabaseUpdater::runPluginTablesOnly()` — creates missing tables, adds missing columns
3. Applies column modifications on existing columns (type/length widening, nullability) via `DatabaseUpdater::processAdvancedColumnOperations()`, then unique constraints and indexes
4. Runs pending migrations for all active plugins
5. Re-registers deletion rules for all active plugins via `PluginHelper::registerAllActiveDeletionRules()`
6. Seeds declared settings, activates declared tasks, then runs each active plugin's `sync.php` hook

Sync is the recommended way to apply schema changes after code deploys. It is also available as an admin UI action on the Plugins page and the Themes page.

**Deactivate** (`PluginManager::deactivate($name)`)
1. Runs `deactivate.php` hook
2. Removes deletion rules for this plugin
3. Suspends active scheduled tasks (`sct_is_active = false`) — tasks resume on reactivation
4. Sets `plg_active = 0`

**Uninstall** (`PluginManager::uninstall($name)`) — **destructive, cannot be undone.** Plugin files stay on disk; everything else is removed.

1. Deletes declared settings (from current `plugin.json`). Settings dropped from a later manifest version are left as orphans.
2. Deletes declared admin menus
3. Removes deletion rules
4. Deletes scheduled task records
5. Deletes version, dependency, and migration records
6. Runs `uninstall.php` hook if present. Tables are still available here for external teardown (e.g., revoking cached external state).
7. Drops plugin tables and orphan sequences
8. Deletes the `plg_plugins` row

**Hook failure is fatal.** If step 6 throws or returns false, steps 7 and 8 do NOT run — tables and the row remain intact. Steps 1–5 are idempotent, so the operator fixes the hook and re-runs uninstall. Use this to guard external work: if you can't revoke an API key, don't let the plugin's local state be destroyed.

**After uninstall,** the plugin appears in the admin UI as "Inactive" with an **Install** action (no DB row, files still on disk). Reinstall goes through the normal install path — on install the upgrade-endpoint refresh pulls fresh published code, so stale on-disk files don't linger.

**Important:** The core `update_database.php` script excludes plugins from its main pipeline (`include_plugins => false`) because plugin tables have independent lifecycles. However, `update_database` runs a plugin/theme sync as its final step, so plugin schema changes are still applied when you run it.

### Table Creation (Automatic)

Plugin tables are created automatically from data class `$field_specifications` — you do NOT write CREATE TABLE statements. Simply define your data model classes in `plugins/{name}/data/` and tables will be created when the plugin is installed.

```php
// plugins/my-plugin/data/my_data_class.php
class MyData extends SystemBase {
    public static $prefix = 'mdt';
    public static $tablename = 'mdt_my_data';
    public static $pkey_column = 'mdt_my_data_id';

    public static $field_specifications = array(
        'mdt_my_data_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'mdt_name' => array('type'=>'varchar(255)', 'required'=>true),
        'mdt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'mdt_delete_time' => array('type'=>'timestamp(6)'),
    );
}
```

**Indexes** are declared the same way as for core tables and applied on install and on **Sync with Filesystem** — inline with `'index' => true` / `'index_with' => array(...)` for plain btree, or a table-level `$index_specifications` array for partial, expression, method-override, and scoped-unique indexes. See [Index Management](deploy_and_upgrade.md#index-management) for the full surface.

**Choosing a prefix:** Your plugin's table prefix (e.g. `abc` in `abc_items`) must be unique across all plugins installed on a site. Use a short abbreviation of your plugin name — at least 3 characters. The system will block installation if your class names or table names collide with an installed plugin, and will warn if your prefix matches even when table names don't.

### Migration System

For default plugin settings, use the `settings` key in `plugin.json` (see [Plugin Settings](#plugin-settings-declarative) above). Migrations are for **initial data seeds only** — dropdown options, category rows, reference data — that doesn't fit the settings model. Schema is handled automatically from `$field_specifications` (see [Table Creation](#table-creation-automatic) above), and admin menus are declared in `plugin.json` (see [Admin Menus](#admin-menus-declarative) above) — none of those belong in a migration.

Migrations are `.sql` files placed in `plugins/{name}/migrations/`:

```sql
-- plugins/my-plugin/migrations/001_seed_categories.sql
INSERT INTO mpc_my_plugin_categories (mpc_name)
SELECT 'Default Category'
WHERE NOT EXISTS (SELECT 1 FROM mpc_my_plugin_categories WHERE mpc_name = 'Default Category');
```

Rules:
- Name files with a numeric prefix for ordering (e.g. `001_seed_categories.sql`, `002_seed_defaults.sql`).
- Files run in filename order during plugin installation.
- Execution is tracked in `plm_plugin_migrations`; each file runs exactly once per site.
- Write idempotent SQL (`WHERE NOT EXISTS`, `ON CONFLICT DO NOTHING`) so a file that partially applied can be safely re-run after the tracking row is cleared.

### Plugin Settings on Your Own Admin Page

A plugin's settings appear on the **Plugin Settings** tab automatically. When a
plugin also wants them on one of its own admin pages — next to a connection test,
a topology diagram, or state the settings page cannot show — ask the shared
renderer for a group:

```php
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

$form = $page->getFormWriter('settings_form', array('action' => $base));
$form->begin_form();

$page->begin_box(array('title' => 'Connection'));
echo '<p>' . htmlspecialchars($connection_state) . '</p>';
SettingsFieldRenderer::renderGroup($form, 'connection', array('source' => 'myplugin'));
$page->end_box();

$form->submitbutton('save_settings', 'Save settings');
echo $form->end_form();
```

That is the **same field** as the one on the Plugin Settings tab, not a second
one — same label, same rules, same credential handling. Two pages cannot drift.

Save through the shared writer:

```php
require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));
$write = SettingsWriter::write($input, array(
    'page'   => 'admin_myplugin_settings',
    'source' => 'myplugin',
));
SettingsWriter::reportTo($write, '~/plugins/myplugin/admin/~');
```

**Rules:**
- **A page never draws a settings field of its own.** Declare it and render the
  group. A hand-drawn field is a field with no rules and no write scope, and
  FormWriter refuses it — on a box with `debug` on, the page throws and names the
  setting, its group, and the manifest file to edit.
- A page decides *whether* to render a group, may disable fields within it, and
  may print any amount of state around it. That is reasoning about the
  deployment, which is the page's job.
- To split one declared group across two boxes, pass `only` with the names each
  box shows. `only` and `skip` narrow a set the manifest decided; neither adds a
  field. The full option list is in **[settings.md](settings.md)**.
- Value-driven visibility ("show the secret when the feature is on") belongs in
  `show_when` on the declaration, so it works identically wherever the group is
  shown. Never hand-roll a JS toggle.
- **Output fields only** inside the page's form. `SettingsFieldRenderer` never
  opens a form, closes one, or adds a submit button — the page owns all three.

### Uninstall Script

`uninstall.php` is **optional**. Most plugins don't need one — the system automatically drops tables, deletes declared settings and menus, removes scheduled task / version / dependency / migration records, and deletes the `plg_plugins` row.

Create `uninstall.php` only when you need external cleanup the system can't do:
- Revoking an API key or token with a third-party service
- Removing uploaded files or cached assets outside the database
- Writing a final archival record to a log table before teardown
- Notifying a paired service (resolver, remote node) to drop cached state

**Contract:**
- Function name: `{plugin_name}_uninstall()` — must match the plugin directory name.
- Runs **after** settings/menus/scaffolding are deleted but **before** plugin tables are dropped, so you can still query your own tables.
- Return `true` on success. Return `false` or throw to signal failure.
- **Failure is fatal**: tables and the `plg_plugins` row are preserved, leaving the plugin in a recoverable state. Fix the hook and re-run uninstall — the scaffolding cleanup steps are idempotent.

```php
// plugins/my-plugin/uninstall.php
function my_plugin_uninstall() {
    try {
        // Example: revoke an API key with an external service.
        // Tables are still available here if you need to read credentials
        // or enumerate records that reference external resources.
        $api_key = Globalvars::get_instance()->get_setting('my_plugin_api_key');
        if ($api_key) {
            external_api_revoke_key($api_key);
        }
        return true;
    } catch (Exception $e) {
        error_log("My Plugin uninstall failed: " . $e->getMessage());
        return false; // preserves tables + row so operator can fix and retry
    }
}
```

**Do not** include `DROP TABLE`, `DELETE FROM stg_settings`, or `DELETE FROM amu_admin_menus` in the hook — those are the system's job now. A hook that duplicates them isn't harmful (drops are `IF EXISTS`, deletes match exact keys the system already cleared), but the extra code rots.

## Building a Vault Consumer

Your plugin can hold content that only its owner can read — encrypted at rest,
opened after they prove presence with a passkey. The platform's
[Sealed Vault](sealed_vault.md) provides the identity, the unlocker, the unlock
window and the key rotation; you provide the content.

There are two ways to do it, and the choice is about **who can read the
content**, not about how much work it is.

### Which one you want

**Seal it at rest (server custody).** The server can open this content while the
member is present. Previews, search, notifications, exports and AI features keep
working. A stolen database, a stolen backup, or a snapshot taken while nobody is
signed in yields ciphertext.

**Encrypt it to the edge (client custody).** The keys are unwrapped only in the
browser. The server never holds one and can never read the content — and neither
can any server-side feature of yours, ever. No search, no thumbnails, no
server-side rendering, no AI. Everything happens in the member's browser or not
at all.

Server custody is the right default. Reach for client custody when the content
is sensitive enough that "the server could read this while you are signed in" is
not acceptable — passwords, or a member's most private files.

Both paths owe the member the same honesty at opt-in: **lose every unlocker and
the content is gone.** There is no support-desk recovery, no operator override,
no reset link. Say so where they turn it on.

One thing the platform does not offer: content that is isolated from the other
server-custody consumers *and* readable by the server. One unlock opens every
server-custody consumer at once — that is the whole point of a single tap, and
its accepted cost. If your content must not be readable whenever mail is,
client custody is the answer.

### Path 1 — seal it at rest

Three declarations and one line of code. There is no crypto in your plugin.

**1. Declare yourself a consumer** in `plugin.json`. The load point is your
plugin's ordinary [bootstrap](#bootstrap-declarative); `vaultConsumer` declares
the vault obligations riding on it:

```json
"bootstrap": "includes/bootstrap.php",
"vaultConsumer": {
  "order": 50,
  "reseals": true
}
```

`reseals: true` says you store sealed content. It is not paperwork — it is what
makes a key rotation refuse rather than quietly destroy your content if the
callback below ever goes missing.

**2. Declare which columns hold content**, plus the four convention columns, on
your model:

```php
class AcmeNote extends SystemBase {
    public static $prefix = 'acn';
    public static $tablename = 'acn_acme_notes';
    public static $pkey_column = 'acme_note_id';

    public static $sealed_fields = array('acn_title', 'acn_body');

    public static $field_specifications = array(
        'acn_acme_note_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'acn_usr_user_id'  => array('type'=>'int8', 'is_nullable'=>false),
        'acn_title'        => array('type'=>'text'),
        'acn_body'         => array('type'=>'text'),

        // The four sealing columns, by convention.
        'acn_content_sealed'       => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        'acn_sealed_key'           => array('type'=>'text'),
        'acn_sealed_owner_user_id' => array('type'=>'int8'),
        'acn_key_generation'       => array('type'=>'int4'),
    );
}
```

**3. Register the re-seal** in your bootstrap:

```php
// plugins/acme/includes/bootstrap.php
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('plugins/acme/data/acme_notes_class.php'));

VaultUnlock::onReseal(VaultUnlock::modelReseal(array(AcmeNote::class)));
```

That is the whole integration. Reading and writing are ordinary:

```php
$note = new AcmeNote(NULL);
$note->set('acn_usr_user_id', $user_id);
$note->set('acn_title', $title);
$note->set('acn_body',  $body);
$note->save();                       // sealed

$note = new AcmeNote($id, TRUE);
echo $note->get('acn_body');         // plaintext, in-window
```

**The one runtime behavior to learn:** a read or a sealed-column update against
a closed vault raises `VaultLockedException`. That is a **one-tap unlock
prompt**, never an error. Catch it and show the member the unlock control; do
not log it as a failure and do not show them a stack trace. Creating a new row
works with the vault locked — sealing needs only the public key — so an ingest
path never has to store content in the clear because "the window might close".

**Sealing is per row.** By default a row seals when its owner has a vault, and a
member with no vault has their content stored in the clear. If your plugin
decides per row — a per-item setting, a per-domain level — override one method:

```php
protected static function shouldSeal(array $row): bool {
    return $row['acn_visibility'] === 'private';
}
```

**If you keep a plaintext cache** — a search index, a working copy on disk —
declare `"caches": true` and register `VaultUnlock::onWipe()` to clear it when
the window closes. Otherwise the lock chip tells the member their content is
locked while your copy of it sits there.

**If you have an opinion about how long the window lasts**, register a provider
with `VaultUnlock::onWindowCaps()`. Every server-custody consumer shares one
window and the strictest opinion wins.

See [Sealed Vault § The consumer contract](sealed_vault.md#the-consumer-contract-server-custody)
for the full contract, and `includes/ApiIdempotencySealed.php` for the smallest
complete consumer in the tree.

### Path 2 — encrypt it to the edge

The browser generates the keypair, wraps it to each unlocker, encrypts every
value, and sends the server opaque blobs. The whole client-side layer is core —
you do not write crypto either.

**1. Declare a scope** in `plugin.json`. A scope is one keypair with its own
unlockers and its own unlock:

```json
"vaultScopes": {
  "acme_secrets": { "custody": "client", "label": "Acme secrets vault" }
}
```

The passkey derivation context is derived from the scope name, so it is
impossible for two scopes to share one by accident. A scope a plugin declares is
always client custody — `custody: server` is refused, because server custody is
what you get from path 1 by declaring no scope at all.

**2. Store blobs** through the core `vault_client_*` actions
(`includes/VaultClientCustody.php`): create the keypair record, fetch the keyring
view, add and remove unlocker wrappings, consume a recovery key. The server
stores and returns ciphertext byte-for-byte and never inspects it.

**3. Drive the crypto in the browser** with `assets/js/vault-crypto.js` and
`assets/js/vault-keyring.js`, both core and both scope-parameterized.

The reference implementation is the [password manager](../plugins/vault/docs/overview.md)
— its entire server footprint is four logic files, because everything that
matters happens in the browser. [Drive's Fortress folders](drive_encryption.md)
reuse the same layer for a completely different content type and add no keyring
or identity surface at all.

## Declaring Host Provisioners

`update_database` handles the *database* side of plugin setup. **Provisioners** handle the other side: the external runtime resources a plugin needs that the database system knows nothing about — mail servers, relays, services, extensions, APIs.

A plugin can be installed and activated while one of these resources is missing or misconfigured, so the feature silently fails. Provisioning checks detect that on demand and surface it on the admin Plugins page (`/admin/admin_plugins`), with the command that fixes it where one exists. The provisioning check itself **only detects and reports — it never runs a fix.** Installation is the job of the plugin's declared `host_installer` (see [Declaring Dependencies](#declaring-dependencies)), which the platform runs automatically at container start, site build, and upgrade; a provisioner's `script` field typically points at that same installer so the admin UI names the fix.

### Declaration

Declare runtime dependencies as a `provisioners` array in `plugin.json`, alongside `settings` and `adminMenu`:

```json
"provisioners": [
  {
    "key": "inbound_mail_server",
    "label": "Inbound mail server (Postfix) running",
    "details": "Postfix on the host receives inbound mail and pipes it to the forwarder.",
    "check": { "type": "probe", "probe": "tcp", "host": "host-gateway", "port": 25 },
    "script": "provisioning/install_email.sh"
  },
  {
    "key": "outbound_forwarding_relay",
    "label": "Outbound mail relay for forwarding",
    "details": "Forwarded messages are relayed out through this SMTP server.",
    "check": { "type": "code", "call": "InboundEmailHealth::checkForwardingRelay" }
  }
]
```

| Field | Required | Purpose |
|---|---|---|
| `key` | yes | Stable identifier, unique within the plugin. |
| `label` | yes | Human-readable name shown in the admin UI. |
| `details` | no | One-line explanation shown under the label. |
| `check` | yes | A check object; `type` is `code` or `probe`. |
| `script` | no | Path to a fix script, relative to the plugin root. Include it only when the fix is a host-level install; omit it when the failure is a configuration problem the admin fixes directly. |

### Two check types — when to use each

**`code` check** — `{ "type": "code", "call": "Class::method" }`. Use this for a resource your plugin *reaches out to acquire* (an SMTP relay, a database, an extension). The check IS your plugin's real acquisition routine, invoked on demand: it exercises the exact code path the feature uses, and it works the same inside a container or on bare metal because it only asks "can our code acquire this." A `code` check passing yields the `verified` state — the strongest pass.

**`probe` check** — `{ "type": "probe", "probe": "tcp", "host": "...", "port": N }`. Use this for a dependency that *pushes into* your plugin rather than being acquired by it (an inbound mail server that pipes mail to a script — your code never connects to it, so a `code` check is structurally blind to it). The system opens a TCP connection within a 5-second enforced timeout. A `probe` passing yields the weaker `reachable` state — it proves something is listening, not that it is the right software or correctly configured.

`probe` is `tcp` in v1. `host` may be a literal IP/hostname or the token **`host-gateway`**, which resolves to the Docker bridge gateway inside a container and to `127.0.0.1` on bare metal — the portable way to say "reach a service on my host." Container-vs-bare-metal is decided by the `deployment_environment` flag recorded in `Globalvars_site.php` at install time (a reliable stored value, not a runtime heuristic). Prefer a literal `127.0.0.1` over `host-gateway` when the dependency is **co-located** with the app — same container or same host (as Email Forwarding's Postfix is); `host-gateway` is only needed to reach out of a container to a service running on the host itself.

### The `code` check contract

`call` names a **static method** (`Class::method`). By convention the class is a `*Health` class in the plugin's `includes/` directory — the system loads `plugins/{plugin}/includes/{Class}.php` automatically if the class is not already loaded. The method must:

- **Perform the plugin's real acquisition step** — or call the shared routine the feature itself uses. Factor each dependency into one acquisition routine called from both places so the check and the feature cannot diverge.
- **Have no side effects** — open and close a connection, never send a real message or write data. It verifies *acquisition*, not *use*.
- **Be idempotent and cheap** — it runs every time the Plugins page opens.

Outcomes:

- **Returns normally** → `verified`.
- **Throws `ProvisioningCheckFailed`** → `unmet`. This is the *expected* failure signal. Catch the underlying acquisition exception (a `PDOException`, an SMTP error) and rethrow it as `ProvisioningCheckFailed` with a clean, human-readable message — that message is shown to the admin.
- **Throws any other `Throwable`, or the class/method cannot be loaded** → `error` (a broken check, reported distinctly from a missing dependency).

> ⚠️ **A `code` check must set its own short connection timeout.** The provisioning system runs the check inside a request and **cannot forcibly interrupt blocked PHP I/O**. A check method that connects to a dead host without a timeout will hang its own badge indefinitely. Setting a short timeout (e.g. `$mailer->Timeout = 5`) is a convention, not something the system enforces — it is the only thing protecting against a stuck check.

### Result states and the admin UI

Checks run asynchronously (via the `plugin_provisioning_check` API action) after the Plugins page renders, so a slow check never blocks the page. Each plugin with provisioners gets a rolled-up badge:

| Rollup | Badge |
|---|---|
| All `verified` | green **Setup complete** |
| All pass, some only `reachable` | teal **Reachable — not fully verified** |
| Any `unmet` | amber **Needs setup** |
| Any `error` | red **Check failed** |

The teal state is deliberate: a plugin whose green status rests on probes never claims the unqualified "Setup complete." Expanding the badge lists each provisioner, with the reason and — for `unmet` provisioners that declare a `script` — the fix command as an absolute path.

The CLI equivalent is `php utils/check_provisioning.php`, which prints the same results and exits non-zero when anything is `unmet` or `error`.

## Declaring DNS Needs

A plugin that needs records published in someone's DNS does not write DNS. It
describes what it wants as a `DnsRecordPlan` and hands that to the shared publish
box, which shows the operator a diff and writes it through whichever DNS host the
deployment uses. Full reference: **📖 [DNS Management](dns_management.md)**.

Build the plan wherever the plugin already knows the answer:

```php
require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));

$plan = new DnsRecordPlan('example.com', 'myplugin');   // domain, owning subsystem
$plan->addRecord('MX', 'example.com', 'mail.example.com', null, 10,
    'Inbound mail for example.com arrives here.');
```

Then two calls put the box on an admin page — one in the action path, one in the
render path:

```php
require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));

$redirect = DnsPublishBox::handle($input, function () use ($id) {
    return MyPlanSource::forThing($id);       // called only when needed
}, $return_url);
if ($redirect !== null) { return $redirect; }

$dns_box = DnsPublishBox::build(MyPlanSource::forThing($id), $input, $return_url);
```

```php
// view
require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
dns_publish_box_render($page, $dns_box);
```

Four rules that keep a plugin's plan honest:

- **Only A, AAAA, CNAME, MX, TXT and CAA.** `NS` and `SOA` throw — a plan that
  could rewrite delegation could take a zone away from its owner.
- **Stay inside the domain's own zone.** A name that is not the domain or beneath
  it does not belong in that domain's plan.
- **Never plan a placeholder.** If the value is not known yet, leave the record
  out; writing `YOUR_SERVER_IP` into a live zone is worse than writing nothing.
- **Omit the TTL unless you genuinely need one.** A record with no TTL means
  "provider default", and a live record whose only difference is a TTL you never
  asked for matches rather than differs.

A plugin never handles a DNS credential. The box authorizes the write at the
moment of the write and discards the grant when the request returns; nothing
DNS-write-capable is ever stored.

To add support for a DNS host the platform does not drive yet, drop a
`DnsProvider` implementation into `includes/dns/drivers/` — the registry
discovers it by interface and the provider chooser picks it up with no page
change. See the driver contract in
[DNS Management](dns_management.md#writing-a-driver).

## Provider Abstractions

The system has two pluggable provider abstractions for external services. Each follows the same shape: an interface, a service manager that auto-discovers concrete classes, and one provider class per third-party service. Adding a new provider is a single-file change — drop a class into the providers directory and the rest of the system picks it up.

### Email providers (`EmailServiceProvider`)

- Interface: `includes/EmailServiceProvider.php`
- Manager: `EmailSender` (`includes/EmailSender.php`) — `EmailSender::getAvailableServices()`, `EmailSender::validateService()`
- Implementations: `includes/email_providers/*Provider.php` (Mailgun, SMTP, …)

### Mailing list providers (`MailingListProvider`)

- Interface: `includes/mailing_list_providers/MailingListProvider.php`
- Abstract base: `includes/mailing_list_providers/AbstractMailingListProvider.php` — concrete providers extend this rather than implementing the interface directly
- Typed exception: `includes/mailing_list_providers/MailingListProviderException.php` — `isRetryable()` distinguishes transient (rate limit, 5xx, network) from permanent (list missing, credentials revoked) failures
- Manager: `MailingListService` (`includes/MailingListService.php`) — `MailingListService::getProvider()`, `getAvailableServices()`, `getProviderSettings($key)`
- Implementations: `includes/mailing_list_providers/*Provider.php` (MailChimp, …)

**Required methods on the interface:**
| Method | Purpose |
|---|---|
| `getKey()` / `getLabel()` | Identity for the `mailing_list_provider` setting and admin dropdown |
| `getSettingsFields()` | Setting field definitions rendered dynamically by the admin UI |
| `validateConfiguration()` | Cheap, no-network check that required settings are non-empty |
| `validateApiConnection()` | Live API ping for the admin "Connection OK?" panel |
| `subscribe()` / `unsubscribe()` | Idempotent operations on a remote list. Email is normalized to lowercase; throw `MailingListProviderException` on provider-side failures, `\InvalidArgumentException` on bad input |
| `getSubscribers()` | Opaque-cursor pagination — caller passes `null` first, then echoes back `next_cursor` until it is `null`. Returns the canonical four-value `status` enum (`subscribed`, `unsubscribed`, `bounced`, `pending`) |

**Non-universal methods** (e.g. `getLists()`) live on `AbstractMailingListProvider` with a default body that throws `\BadMethodCallException`. Providers override them when their API supports the operation; consumers wrap calls in `try/catch \BadMethodCallException`. Future non-universal additions (sequences, broadcasts, list stats) follow the same pattern, keeping additions non-breaking for existing provider classes.

**Adding a new provider:**

1. Create `includes/mailing_list_providers/MyServiceProvider.php`:
   ```php
   require_once(PathHelper::getComposerAutoloadPath());
   require_once(PathHelper::getIncludePath('includes/mailing_list_providers/AbstractMailingListProvider.php'));

   class MyServiceProvider extends AbstractMailingListProvider {
       public static function getKey(): string { return 'myservice'; }
       public static function getLabel(): string { return 'My Service'; }
       // … implement the remaining required methods
   }
   ```
2. Add any provider-specific settings to `settings.json` (factory defaults seed automatically).
3. Pick the provider in admin settings (`/admin/admin_settings_email` → Mailing List Provider section) — the dropdown auto-populates from your new class.

No other files need to change. The model layer (`MailingList::sync_subscribe()` / `sync_unsubscribe()`) and the sync utility (`utils/mailing_list_synchronize.php`) call the configured provider through `MailingListService::getProvider()`.

**Canonical subscriber status enum.** `getSubscribers()` returns one of four `status` values regardless of provider. Each provider class maps its native vocabulary into this set:

| Canonical | Meaning | MailChimp | ConvertKit | Listmonk |
|---|---|---|---|---|
| `subscribed` | Actively receives mail | `subscribed` | `active` | `enabled` |
| `unsubscribed` | Opted out (incl. spam-complained) | `unsubscribed` | `cancelled`, `inactive`, `complained` | `disabled` |
| `bounced` | Email invalid; provider stopped sending | `cleaned` | `bounced` | `blocklisted` |
| `pending` | Double opt-in not yet confirmed | `pending` | (n/a) | (n/a) |

`complained` (spam-marked) collapses into `unsubscribed` — for the platform's purposes the action taken on the local row is the same. Mapping is typically ~5 lines of `switch` per provider.

**Out of scope (deliberate deferrals).** Three categories of methods are intentionally NOT on the interface today; they will be added when a concrete second provider needs them:

- **Webhooks.** Real-time event notifications (`registerWebhook`, `verifyWebhookSignature`) are not part of the contract. When added they go on the required interface — every modern provider supports them.
- **OAuth flows.** Some providers (HubSpot, Klaviyo) use OAuth2 instead of API keys. The current `getSettingsFields()` shape can't express an OAuth flow. When a provider needing OAuth is added, that provider class implements an additional method (e.g. `getOAuthAuthorizationUrl()`) outside the formal interface; the admin UI checks for its presence via `method_exists`.
- **Programmatic list creation.** `createList()` is not on the interface. Current workflow: admins create lists in the provider's UI and enter the ID locally. Add programmatically only when a concrete use case appears.

Non-universal future methods (sequences, broadcasts, list stats) get default throwing bodies on `AbstractMailingListProvider` so additions stay non-breaking for existing provider classes.

## Theme Development

### Theme Structure with Plugin Integration

Themes can range from simple presentation layers to complex integrations with multiple plugins:

**Basic Theme Structure:**
```
/theme/my-theme/
├── theme.json                  # Theme metadata and configuration
├── serve.php                   # Theme routing (optional)
├── views/                      # Theme templates and view overrides
│   ├── index.php
│   ├── page.php
│   └── plugin_overrides/       # Plugin view overrides
├── assets/                     # Theme assets
│   ├── css/
│   ├── js/
│   └── images/
└── includes/                   # Theme-specific classes
    ├── PublicPage.php          # Theme-specific PublicPage implementation
    └── FormWriter.php          # Theme-specific FormWriter (optional)
```

**Advanced Theme with Plugin Integration:**
```
/theme/advanced-theme/
├── theme.json
├── serve.php                   # Includes plugin routes
├── views/
│   ├── index.php
│   ├── items/                  # Plugin view overrides
│   │   ├── list.php
│   │   └── detail.php
│   └── profile/                # Plugin view overrides
│       └── dashboard.php
├── assets/
└── includes/
    ├── PublicPage.php          # Bootstrap/UIKit/WordPress-specific implementation
    └── ThemeHelper.php         # Theme-specific utilities
```

### Theme Routing (serve.php)

Themes can define their own routes in RouteHelper format, including integration with plugin functionality:

**Basic Theme Routing:**
```php
// theme/my-theme/serve.php
$routes = [
    'dynamic' => [
        // Simple view routes (uses view resolution chain)
        '/my-page' => ['view' => 'views/my_page'],
        '/about' => ['view' => 'views/about'],
        
        // Model-based routes using plugin data
        '/item/{slug}' => [
            'model' => 'Item',
            'model_file' => 'plugins/items/data/items_class'
        ],
    ],
    
    'custom' => [
        // Complex routing logic
        '/custom-handler' => function($params, $settings, $session, $template_directory) {
            // Custom logic here
            require_once(PathHelper::getThemeFilePath('custom.php', 'views'));
            return true;
        },
    ],
];
```

**Plugin serve.php (namespaced routes only):**
```php
// plugins/controld/serve.php
$routes = [
    'dynamic' => [
        // Routes must be within the plugin's namespace
        '/profile/controld/device_edit' => [
            'view'           => 'views/profile/device_edit',
            'min_permission' => 0,
        ],
        '/controld/create_account' => [
            'view' => 'views/create_account',
        ],
    ],
];
```

Note: The plugin name is extracted automatically from the URL pattern — no `plugin_specify` field is needed or supported.

### Plugin Integration in Themes

Themes integrate with plugin backend services through data models and the view resolution system:

**Using Plugin Data Models:**
```php
// theme/my-theme/views/items.php
<?php
require_once(PathHelper::getIncludePath('plugins/items/data/items_class.php'));

// Use plugin data models
$items = new MultiItem(['itm_active' => 1], ['itm_name' => 'ASC']);
$items->load();

foreach ($items as $item) {
    echo '<h3>' . $item->get('itm_name') . '</h3>';
    echo '<p>' . $item->get('itm_description') . '</p>';
}
?>
```

**View Override Pattern:**
```php
// theme/my-theme/views/items/list.php - Overrides plugin view
<?php
// This theme view will be used instead of plugins/items/views/items/list.php
// But can still access plugin data models and helpers
require_once(PathHelper::getIncludePath('plugins/items/data/items_class.php'));
require_once(PathHelper::getIncludePath('plugins/items/includes/ItemsHelper.php'));

$items = ItemsHelper::getActiveItems();
foreach ($items as $item) {
    // Theme-specific presentation
    include 'item_card_template.php';
}
?>
```

**Theme-Specific Class Integration:**
```php
// theme/bootstrap-theme/includes/PublicPage.php
class PublicPage extends PublicPageBase {
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table' => 'table table-striped table-hover',
            'header' => 'thead-dark'
        ];
    }
    
    // Bootstrap-specific implementations
    public function renderAlert($message, $type = 'info') {
        return "<div class='alert alert-{$type}' role='alert'>{$message}</div>";
    }
}
```

**Profile/Member Area:**

Profile pages (`/profile/*`) and `/notifications` use the active theme's `PublicPage` directly — no separate `MemberPage` wrapper. Profile views call `$page->public_header()` / `$page->public_footer()` like any other public view and render their content inside a `.jy-ui` scope using the jy-ui kit components (`.jy-panel`, `.jy-page-header`, `.jy-breadcrumbs`, `.card`, etc.). In-page navigation between profile sub-pages is handled by the existing user dropdown in the theme header and, where relevant, a per-page `PublicPage::tab_menu()` tab bar.

### Asset Management

Theme assets are served through the theme asset route with automatic caching:
`/theme/{theme}/assets/*`

**Basic Asset Usage:**
```php
// In theme templates
<link rel="stylesheet" href="/theme/<?= $template_directory ?>/assets/css/style.css">
<script src="/theme/<?= $template_directory ?>/assets/js/app.js"></script>
<img src="/theme/<?= $template_directory ?>/assets/images/logo.png" alt="Logo">
```

**Base Assets:**

`PublicPageBase` loads fallback CSS/JS (`base.css`, `joinery-styles.css`, `base.js`) via the `render_base_assets()` method, called from `global_includes_top()`. Themes that provide their own complete CSS (like `PublicPageJoinerySystem`) override `render_base_assets()` with an empty body to prevent style conflicts. See [Theme Integration Instructions](theme_integration_instructions.md) for details.

**Using ThemeHelper for Assets:**
```php
// Enhanced asset management
<?php
$theme = ThemeHelper::getInstance();
?>
<link rel="stylesheet" href="<?= $theme->asset('css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= $theme->asset('css/theme.css') ?>">
<script src="<?= $theme->asset('js/theme.js') ?>"></script>
```

**Theme Configuration:**
```php
// Using theme.json configuration in templates
<?php
$theme_config = ThemeHelper::config('cssFramework', 'bootstrap');
if ($theme_config === 'bootstrap') {
    echo '<div class="container">';
} elseif ($theme_config === 'uikit') {
    echo '<div class="uk-container">';
}
?>
```

### Theme Metadata (theme.json)

All themes should include a `theme.json` file for proper system integration.

#### Distribution Flags

Two boolean flags control how a theme moves between the publisher and customer
sites. Both default to `true` if missing, but should be declared explicitly:

- **`receives_upgrades`** — *customer-side, deploy preservation.* If `true`, the
  on-disk copy is replaced from the upgrade payload during a deploy swap and
  the container reconciler will re-download it on boot if it goes missing.
  Set to `false` to keep a hand-edited copy across deploys. Mirrored to the
  database (`thm_receives_upgrades`); the admin Themes page can toggle it.
- **`included_in_publish`** — *publisher-side, packaging filter.* If `true`,
  `publish_upgrade.php` packages this theme into the upgrade archive and the
  marketplace catalog advertises it. If `false`, it is skipped. Manifest-only
  (no DB column, no admin UI).

For a freshly authored site theme that should stay on its origin site and not
ship downstream, set both flags to `false`. For a theme published via the
upgrade pipeline, set both to `true`. The same pair applies to `plugin.json`.

**Basic theme.json:**
```json
{
  "name": "my-theme",
  "displayName": "My Custom Theme",
  "version": "1.0.0",
  "description": "A custom theme for my site",
  "author": "Your Name",
  "receives_upgrades": false,
  "included_in_publish": false,
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterV2HTML5",
  "publicPageBase": "PublicPageBase"
}
```

**Plugin-integrated theme.json:**
```json
{
  "name": "advanced-theme",
  "displayName": "Advanced Plugin-Integrated Theme",
  "version": "2.1.0",
  "description": "Theme with full plugin integration",
  "author": "Developer Team",
  "receives_upgrades": false,
  "included_in_publish": false,
  "requires": {
    "php": ">=8.0",
    "joinery": ">=1.0.0"
  },
  "supports_plugins": ["controld", "items"],
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterV2HTML5",
  "publicPageBase": "PublicPageBase",
  "features": {
    "responsive": true,
    "dark_mode": true,
    "plugin_integration": true
  }
}
```

**HTML5 framework-agnostic theme.json:**
```json
{
  "name": "custom-theme",
  "displayName": "Custom HTML5 Theme",
  "version": "1.0.0",
  "description": "Framework-agnostic theme with custom styling",
  "author": "Developer",
  "receives_upgrades": false,
  "included_in_publish": false,
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "html5",
  "formWriterBase": "FormWriterV2HTML5",
  "publicPageBase": "PublicPageBase"
}
```

**Theme with plugin dependencies (requires_plugins):**
```json
{
  "name": "scrolldaddy-theme",
  "displayName": "ScrollDaddy Theme",
  "version": "1.0.0",
  "requires_plugins": ["scrolldaddy"],
  "cssFramework": "html5",
  "formWriterBase": "FormWriterV2HTML5",
  "publicPageBase": "PublicPageBase"
}
```

The `requires_plugins` field declares plugins that must be active for the theme to work correctly. When present:
- **Theme activation is blocked** if any listed plugin is not active (with a clear error message directing the admin to activate the plugin first).
- **Plugin deactivation is blocked** if the active theme lists that plugin in `requires_plugins` (with an error directing the admin to switch themes first).

Use this when the theme directly uses plugin-provided classes, helpers, or pages — for example, a theme that renders a widget from a specific plugin's helper class, or whose navigation links to plugin-namespaced URLs.

Themes also support the `deprecated` and `superseded_by` fields described in the [plugin.json Deprecation Fields](#deprecation-fields) section above. The behavior is identical for themes and plugins.

## ThemeHelper Enhanced Capabilities

### Theme Management Methods

**Get Active Theme:**
```php
$current_theme = ThemeHelper::getActive();
```

**Get Theme Configuration:**
```php
$css_framework = ThemeHelper::config('cssFramework', 'bootstrap', 'theme-name');
$supports_plugins = ThemeHelper::config('supports_plugins', [], 'theme-name');
```

## Migration from Old Architecture

### For Existing Plugins

1. **Remove user-facing routes** from plugin serve.php files
2. **Keep admin interfaces** and backend functionality  
3. **Ensure plugin.json exists** with proper versioning
4. **Convert migrations** to new format if needed
5. **Add uninstall script** for clean removal
6. **Update view paths** to work with the new resolution system

### For Themes Using Plugin Features

1. **Move plugin routes to theme serve.php** using RouteHelper format
2. **Update view templates** to use plugin data models directly
3. **Ensure assets are in theme/assets/** not plugin directories  
4. **Test plugin admin access** via `/plugins/{plugin}/admin/*`
5. **Create theme.json** with proper metadata and plugin support
6. **Implement theme-specific classes** (PublicPage, FormWriter) if needed
7. **Test view resolution chain** to ensure fallbacks work correctly

### Working with Forms in Views

#### Getting FormWriter Instances

In views with PublicPage available (most frontend views):
```php
// Preferred method in views - uses PublicPage wrapper
$formwriter = $page->getFormWriter('form1');
```

In different contexts:
```php
// Admin pages - use the page object
$formwriter = $page->getFormWriter('form1'); // $page is AdminPage instance

// Utilities and logic files - direct instantiation
require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
$formwriter = new FormWriter('form1');
```

The `$page->getFormWriter()` method automatically:
- Loads the theme's `FormWriter.php` if it provides one
- Falls back to the system `FormWriterV2HTML5` renderer otherwise
- Handles all the complexity internally

#### FormWriter Class
- FormWriter renders semantic HTML5 via `FormWriterV2HTML5`, regardless of the theme's `cssFramework`.
- A theme's `FormWriter.php` extends `FormWriterV2HTML5` (or `FormWriterV2Base`) to add theme-specific overrides.

### Example: ControlD Plugin Migration

**Before (Plugin served routes):**
```php
// plugins/controld/serve.php (REMOVED)
$routes = [
    '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
    '/create_account' => ['view' => 'views/create_account'],
];
```

**After (Theme serves routes):**
```php
// theme/sassa/serve.php (CURRENT)
$routes = [
    'dynamic' => [
        '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
        '/pricing' => ['view' => 'views/pricing'],
    ],
];
```

**Plugin now only provides:**
- Admin interface: `/plugins/controld/admin/*`
- Data models: `CtldAccount`, `CtldDevice`, etc.
- Business logic: `ControlDHelper` class and logic files

## Hybrid Architecture

### Separation of Concerns
- **Plugins**: Backend logic, data, admin interfaces
- **Themes**: User interface, routing, presentation
- **Hybrid Integration**: Themes can access plugin functionality without coupling

### View Resolution
- **View Resolution Chain**: Automatic fallback from theme → plugin → system views
- **Framework Support**: Multiple CSS frameworks with proper implementations
- **Plugin Integration**: Themes can include plugin routes without breaking separation
- **Override Capability**: Themes can override any plugin view while maintaining fallbacks

### Security Model
- Plugin code not directly accessible via web URLs
- Admin interfaces protected by plugin admin discovery route
- Clear separation between public and admin functionality
- Theme-specific includes isolated from system includes

### Performance
- Static asset caching through RouteHelper
- Reduced routing complexity with priority-based processing
- Plugin code only loaded when needed
- View resolution caching prevents repeated file system checks
- Framework-specific optimizations in theme implementations

## Storing Uploaded Files

A plugin that stores bytes goes through `File::createFromUpload()` /
`File::createFromBytes()` and **stamps its own `fil_source`**, then declares that
tag in `File::source_catalog()` (`data/files_class.php`) saying whether it is a
file people browse or one the system keeps for itself:

```php
$file = File::createFromBytes($bytes, $name, $mime, $owner_id, array(
    'fil_private' => true,
    'fil_source'  => File::SOURCE_MAILBOX_SEARCH_INDEX,
));
```

An undeclared tag is treated as a normal, listable file, so a plugin that skips
the declaration has its files appear in the admin Files listing rather than
vanish. Declaring one `internal` is what keeps a machine-owned artifact — a search
index, a scratch blob — out of every browse surface at once. See
[Drive § Origin tags](drive.md#origin-tags--where-a-file-came-from).

## File Loading in Plugins and Themes

**Two methods for including files:**

1. **`PathHelper::getIncludePath()`** - Direct loading, no overrides
   ```php
   require_once(PathHelper::getIncludePath('data/user_class.php'));  // Data models
   require_once(PathHelper::getIncludePath('includes/MyHelper.php')); // System files
   ```

2. **`PathHelper::getThemeFilePath()`** - Theme-aware file resolution with override chain
   ```php
   // Files that can be overridden by themes
   require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));
   require_once(PathHelper::getThemeFilePath('devices.php', 'views/profile'));

   // With explicit plugin context (5th parameter)
   require_once(PathHelper::getThemeFilePath('devices.php', 'views/profile', 'system', null, 'controld'));

   // Parameters: filename, subdirectory, path_format, theme_name, plugin_name
   ```
   **Override chain:** theme → plugin → base

**When to use:**
- `PathHelper::getIncludePath()`: Direct file access for system files, data models, plugin files
- `PathHelper::getIncludePath()`: Direct file access, no theme overrides needed (plugins, data files)
- `PathHelper::getThemeFilePath()`: Files that themes/plugins can override (views, logic, includes)

### File Override System

**Important:** The file override system uses `PathHelper::getThemeFilePath()` which checks:
1. Theme override: `/theme/{theme}/{subdirectory}/{filename}`
2. Plugin version: `/plugins/{plugin}/{subdirectory}/{filename}`
3. Base fallback: `/{subdirectory}/{filename}`

Always use the two-parameter format:
- First parameter: filename only (e.g., 'profile.php')
- Second parameter: subdirectory path (e.g., 'views', 'logic', 'views/profile')

## Development Workflow

### Creating a New Plugin

1. Create plugin directory under `/plugins/{name}/` with `plugin.json`
2. Create data model classes in `plugins/{name}/data/` with `$field_specifications` (tables created automatically on install)
3. Declare admin menus in `plugin.json` under the `adminMenu` key (see [Admin Menus](#admin-menus-declarative))
4. Declare default settings in `plugin.json` under the `settings` key (see [Plugin Settings](#plugin-settings-declarative))
5. Create `.sql` migration files in `plugins/{name}/migrations/` only if you have other initial data seeds (dropdowns, categories, reference rows)
6. Create admin interface in `plugins/{name}/admin/` if needed
7. *(Optional)* Create `uninstall.php` only if you have external cleanup to perform (API calls, filesystem, remote-service notifications) — the system handles tables, settings, menus, and scaffolding automatically. See [Uninstall Script](#uninstall-script).
8. **Install** the plugin via Admin > System > Plugins (creates tables, runs SQL migrations)
9. **Activate** the plugin to make it live (seeds declared settings)
10. Test admin functionality via `/plugins/{plugin}/admin/*`
11. No user-facing routes - these go in themes

### Creating a New Theme

1. **Create theme directory structure** with theme.json manifest
2. **Choose CSS framework** and implement corresponding PublicPage class
3. **Add serve.php** only if custom routing or plugin integration needed
4. **Create view templates** using plugin data models and ThemeHelper methods
5. **Add theme assets** (CSS, JS, images) in proper directory structure
6. **Test view resolution chain** to ensure plugin view fallbacks work
7. **Validate theme.json accuracy** against actual implementations
8. **Test integration** with existing plugins using the hybrid system

### Integrating Plugin and Theme

1. **Plugin provides backend services** and data models through SystemBase classes
2. **Theme creates user-facing routes** that use plugin models via serve.php
3. **Theme templates use plugin data** through proper model loading and ThemeHelper
4. **View resolution chain** allows themes to override plugin views while maintaining fallbacks  
5. **Plugin admin remains separate** from theme routing via `/plugins/{plugin}/admin/*`
6. **Theme.json documents integration** with supported plugins and framework choices
7. **CSS framework consistency** maintained between plugin data and theme presentation

## Debugging and Troubleshooting

### Route Debugging

Enable route debugging with URL parameter:
```
http://example.com/any-page?debug_routes=1
```

This shows detailed routing information in HTML comments.

### Common Issues

**404 on plugin admin pages:**
- Check plugin directory name matches URL
- Verify admin file exists in `plugins/{plugin}/admin/`
- Check file permissions

**Theme not finding plugin data:**
- Ensure plugin data class is properly included using PathHelper
- Verify plugin is installed and tables exist
- Check data model usage syntax and constructor parameters

**Views not resolving correctly:**
- Check view path format in routes (should not start with `/`)
- Test view resolution chain: theme → plugin namespace → base
- For auto-discovered views, confirm URL matches `/profile/{pluginname}/...` pattern and file exists at `plugins/{pluginname}/views/profile/....php`
- For explicit routes, confirm the route pattern is within the plugin namespace

**CSS framework conflicts:**
- Verify theme.json cssFramework matches actual implementation
- Check PublicPage class extends proper base and implements getTableClasses()
- Ensure the theme's `FormWriter.php` extends `FormWriterV2HTML5` (the HTML renderer used by every theme)
- Validate CSS classes match framework documentation

**Assets not loading:**
- Verify asset paths use correct theme directory
- Check file exists in `theme/{theme}/assets/`
- Ensure web server can serve static files
- Test ThemeHelper::asset() method for enhanced asset management

**Class not found errors:**
- Distinguish between theme includes (direct) vs views (resolution chain)
- Use proper require_once(PathHelper::getIncludePath()) for includes
- Check abstract method implementation in theme-specific classes
- Verify class file naming conventions match theme requirements

## Cookie Consent Integration

If your plugin adds analytics or marketing scripts to public pages, you should wrap them for GDPR/CCPA consent compliance.

**Using ConsentHelper to wrap scripts:**
```php
require_once(PathHelper::getIncludePath('includes/ConsentHelper.php'));
$consent = ConsentHelper::get_instance();
echo $consent->wrapTrackingCode('<script>...your tracking code...</script>', 'analytics');
```

**Or manually add the consent attribute to script tags:**
```html
<script type="text/plain" data-joinery-consent="analytics">
  // This script only runs after user consents to analytics
</script>
```

**Consent categories:**
- `analytics` - For analytics and tracking scripts (e.g., Google Analytics)
- `marketing` - For advertising and remarketing scripts (e.g., Facebook Pixel)

When cookie consent is enabled, scripts marked with `data-joinery-consent` remain inactive until the user grants consent for that category.

## CSS Framework Integration

### CSS Frameworks and FormWriter

A theme's `cssFramework` declares which CSS its chrome loads; FormWriter is unaffected and always renders semantic HTML5 via `FormWriterV2HTML5`.

**Bootstrap Themes:**
- CSS Framework: `bootstrap`
- Table Classes: `table`, `table-striped`, `table-hover`
- Container Classes: `container`, `container-fluid`

**HTML5 Themes (Framework-Agnostic):**
- CSS Framework: `html5` or `custom`
- Pure semantic HTML5 markup
- No framework-specific classes
- Themes can apply any CSS styling

In every case FormWriter renders through `FormWriterV2HTML5`; a theme may extend it (or `FormWriterV2Base`) for custom output.

### Framework-Specific Implementations

**PublicPage Class Implementations:**

```php
// Bootstrap theme
protected function getTableClasses() {
    return [
        'wrapper' => 'table-responsive',
        'table' => 'table table-striped table-hover',
        'header' => 'thead-dark'
    ];
}

// UIKit theme  
protected function getTableClasses() {
    return [
        'wrapper' => 'uk-overflow-auto',
        'table' => 'uk-table uk-table-striped', 
        'header' => 'uk-table-header'
    ];
}

// WordPress theme
protected function getTableClasses() {
    return [
        'wrapper' => 'table-wrapper',
        'table' => 'wp-list-table widefat fixed striped',
        'header' => 'thead'
    ];
}
```

## Current Plugin Status

### Active Plugins

**ControlD (Backend-only)**
- Location: `/plugins/controld/`
- Admin: `/plugins/controld/admin/*`
- Data models: Account, Device, Filter, etc.
- User routes: Moved to sassa theme

**Items (Backend-only)**  
- Location: `/plugins/items/`
- Admin: `/plugins/items/admin/*`
- Data models: Item, ItemRelation, etc.
- User routes: Moved to sassa theme

### Theme Integration Examples

**Sassa Theme (Plugin-enabled, Bootstrap)**
- CSS Framework: `bootstrap`
- Includes ControlD routes: `/profile/*`, `/pricing`
- Includes Items routes: `/items`, `/item/{slug}`
- File: `/theme/sassa/serve.php`
- Custom PublicPage with Bootstrap table classes

**Jeremy Tunnell Theme (WordPress CSS)**
- CSS Framework: `wordpress`
- PublicPage with WordPress-specific table classes
- FormWriter using default base
- Theme.json accurately reflects implementation

**Zouk Room Theme (UIKit)**
- CSS Framework: `uikit` 
- PublicPage with UIKit table classes
- Theme.json specifies UIKit framework
- Custom styling for UIKit components

**Other Themes (Various Frameworks)**
- Falcon (Bootstrap), Tailwind (Tailwind CSS), Default (minimal)
- Each with framework-appropriate implementations
- Clean separation of concerns maintained

## Best Practices Summary

### For Plugin Developers
1. **Backend-only focus** - No user-facing routes or views
2. **Proper data models** using SystemBase patterns
3. **Admin interfaces** accessible via `/plugins/{name}/admin/*`
4. **Clean uninstall** scripts for data cleanup
5. **Version management** through plugin.json

### For Theme Developers
1. **Framework consistency** - Match CSS framework to implementations
2. **Accurate manifests** - theme.json should reflect actual code
3. **View resolution** - Leverage the fallback chain effectively
4. **Plugin integration** - Use data models, not direct plugin coupling
5. **Asset management** - Proper theme asset organization
6. **Abstract methods** - Implement required PublicPageBase methods
7. **Base class render methods** - Call `$this->render_notification_icon($menu_data)` in `top_right_menu()` for notifications; override only if theme needs different markup

### For System Integration
1. **Clear separation** - Plugins (backend) vs Themes (frontend)
2. **Flexible routing** - Theme serve.php can include plugin routes
3. **View fallbacks** - Automatic resolution chain prevents 404s
4. **Framework support** - Multiple CSS frameworks supported cleanly
5. **Maintainability** - Updates to plugins don't break theme functionality

This hybrid architecture provides maximum flexibility while maintaining clean separation of concerns and ensuring backward compatibility across all existing themes and plugins.

## Plugin Theme System

### Overview

The plugin theme system allows plugins to act as complete theme providers, replacing the entire user interface while maintaining all plugin functionality. This enables white-label solutions, complete UI replacements, and branded experiences.

### How the System Works

1. **PathHelper** intercepts theme file requests and redirects to plugin directory for PHP classes
2. **RouteHelper** sets template directory to plugin path for view loading
3. **ThemeHelper** serves assets from plugin directory instead of theme directory
4. **Admin Settings** provides UI for selecting which plugin provides the theme

### Three Types of Plugins

#### 1. Feature Plugins (Standard)
**Purpose**: Add specific functionality without affecting the UI
**Examples**: Bookings, Items, OAuth providers, Payment processors
**Characteristics**:
- Work within existing theme framework
- Add new routes under `/[plugin-name]/*`
- Can provide admin interfaces
- Cannot override system views or routes

**Directory Structure**:
```
/plugins/bookings/
├── plugin.json
├── serve.php
├── admin/
│   └── manage_bookings.php
├── views/
│   └── booking_list.php
└── assets/
    └── js/bookings.js
```

#### 2. Theme Provider Plugins
**Purpose**: Complete UI replacement when selected as active theme
**Examples**: ControlD, White-label solutions, Custom branded interfaces

**Required Files**:
```
/plugins/controld/
├── plugin.json (with "provides_theme": true)
├── serve.php
├── includes/
│   ├── PublicPage.php (required - base page class)
│   └── FormWriter.php (required - form generation)
├── views/
│   ├── index.php (homepage view)
│   ├── profile.php (user profile)
│   └── [other system view overrides]
└── assets/
    ├── css/style.css
    ├── js/main.js
    └── img/logo.png
```

**How Theme Provider Mode Works**:
1. Admin selects "plugin" as the theme
2. Admin selects specific plugin (e.g., "controld") as the theme provider
3. System modifications activate:
   - PathHelper loads PHP classes from `/plugins/controld/includes/`
   - RouteHelper loads views from `/plugins/controld/views/`
   - ThemeHelper loads assets from `/plugins/controld/assets/`
4. Plugin provides complete UI while system handles core functionality

#### 3. Hybrid Plugins
**Purpose**: Dual-mode plugins that can work as features OR complete themes
**Examples**: Complex applications with optional standalone mode

**Behavior Modes**:
- **Feature Mode**: When regular theme active, provides features within that theme
- **Theme Mode**: When selected as theme provider, replaces entire UI
- Same codebase, different activation modes

## System Configuration Documentation

### New Database Settings

**`active_theme_plugin`**
- **Type**: String (plugin directory name)
- **Default**: Empty string
- **Purpose**: Specifies which plugin provides the complete UI when plugin theme is active
- **Valid Values**: Must match an installed plugin directory name
- **Dependencies**: Only used when `theme_template = 'plugin'`
- **Example**: `'controld'` to use ControlD plugin as theme

### Modified Settings

**`theme_template`**
- **New Option**: `'plugin'` - Delegates all theme functionality to a plugin
- **Existing Options**: `'falcon'`, `'sassa'`, `'tailwind'`, etc.

## Admin Interface Documentation

### Settings Page Updates (`/adm/admin_settings.php`)

**Theme Selection Enhancement**:
When "Plugin-Provided Theme" is selected from the theme dropdown:
1. A new dropdown appears labeled "Active Theme Plugin"
2. Dropdown populates with all installed plugins
3. Plugins with `"provides_theme": true` are prioritized
4. Help text explains the plugin must provide theme infrastructure

**JavaScript Behavior**:
- Plugin selector is hidden when regular themes are selected
- Plugin selector shows immediately when "plugin" theme is selected
- Settings save normally through existing form processing

## Technical Implementation Notes

### File Resolution Order

When plugin theme is active, the system checks for files in this order:

**For PHP Classes** (via PathHelper):
1. `/plugins/{active_plugin}/includes/{file}`
2. `/theme/plugin/includes/{file}` (fallback)
3. `/includes/{file}` (system fallback)

**For Views** (via RouteHelper/ThemeHelper):
1. `/plugins/{active_plugin}/views/{file}`
2. `/views/{file}` (system fallback)

**For Assets** (via ThemeHelper):
1. `/plugins/{active_plugin}/assets/{file}`
2. `/theme/plugin/assets/{file}` (shouldn't exist)
3. Current route's plugin assets (existing behavior)

### Performance Considerations

- **Additional Database Queries**: One extra query to get `active_theme_plugin` setting
- **File Existence Checks**: Additional `is_dir()` and `file_exists()` checks
- **Caching Opportunity**: Could cache plugin theme selection in session
- **Impact**: Minimal - only adds conditional checks when plugin theme active

### Security Considerations

- **Plugin Validation**: System should verify plugin exists before activation
- **Fallback Strategy**: Falls back to safe defaults if plugin missing
- **No New Attack Vectors**: Uses existing file inclusion mechanisms
- **Admin Only**: Theme selection requires admin permissions