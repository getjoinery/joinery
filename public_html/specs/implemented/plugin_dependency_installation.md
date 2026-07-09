# Plugin Dependency Installation

**Status:** Implemented 2026-07-09

## Goal

A plugin (or core) that needs something installed on the host gets it installed
by the platform's own tooling, driven by declarations and plugin-owned scripts
— never by a hardcoded list in an installer that drifts out of sync with the
code. The immediate motivating bug: `VaultUnlock` requires APCu, root
`composer.json` declares `ext-apcu`, `ComposerValidator` checks it — and
`install.sh` installs a hardcoded PHP extension list that doesn't include it,
so a fresh box fatals on first vault unlock.

The strategy is three tiers, matched to what each dependency kind needs:

| Tier | Dependency kind | Declared in | Installed by | Example |
|---|---|---|---|---|
| 1 — Declarations | PHP extensions | `composer.json` `ext-*` (core), `plugin.json` `requires.extensions` (plugins) | Generic step at the root moments | apcu, sodium, sqlite3 |
| 2 — Host installers | Services, system packages, host config | `plugin.json` `host_installer` | The plugin's own idempotent root script, run automatically at the root moments | Postfix + opendkim (mailbox) |
| 3 — Composer | PHP libraries | `plugin.json` `requires.composer` (new), root `composer.json` (core) | Composer reconcile at activation and at `update_database` | web-auth/webauthn-lib |

Detection and refusal stay where they are today: `PluginManager::validatePlugin()`
gates activation, and `provisioners` detect-and-report host state. This spec
adds the install half at the moments that have root, and closes the gaps in
the check half (themes skip extension checks entirely).

## The root moments

Installing apt packages needs root; plugin activation runs as www-data. The
platform has exactly four moments where root (or an equivalent) is available,
and all four become dependency-install moments:

1. **Base image build** (`Dockerfile.base`) — root during `docker build`.
2. **Site build** (`install.sh site`) — root on the host.
3. **Container start** (`Dockerfile.template` CMD) — root inside the container.
4. **Node upgrade** (`utils/upgrade.php`, invoked by the server-manager agent)
   — root via `docker exec` on Docker nodes, `sudo` prefix on bare-metal
   (`JobCommandBuilder::sudo_prefix()`).

Activation itself (www-data, web request) installs only what userland can:
Tier 3 composer packages. For Tiers 1–2 it remains check-and-refuse, but the
refusal names the exact command to run — and on Docker nodes a container
restart satisfies it automatically (moment 3).

## Tier 1 — Declared PHP extensions

### Declarations (no new syntax for core, existing syntax for plugins)

- Core: `ext-*` entries in root `composer.json` `require` (already exists:
  `ext-sodium`, `ext-apcu`, `ext-sqlite3`).
- Plugins: `requires.extensions` in `plugin.json` (already exists, already
  enforced at activation via `extension_loaded()` in
  `PluginManager::validatePlugin()`).

### New: the dependency resolver

`utils/list_dependencies.php` — CLI, no web route. Emits the union of required
PHP extensions as machine-readable output (one per line, or `--json`):

- root `composer.json` `ext-*` requires
- every **bundled** plugin's `requires.extensions` (all plugins on disk, not
  just active — extensions are cheap, and the base image is built before any
  plugin is activated; installing the union means activation never fails on a
  missing extension)

Flags: `--extensions` (default), `--apt` (mapped to apt package names),
`--composer` (Tier 3 packages, see below), `--active-only`.

The ext→apt mapping is the `php8.3-{ext}` convention with an explicit override
map inside the resolver for the exceptions (e.g. distros where APCu ships as
`php-apcu`). The mapping lives in one place only.

### Consumers

- **`Dockerfile.template`** (not the base image — the base builds from
  `install.sh server` with no site source present, so it has nothing to
  resolve): after the source COPY, a build step runs the resolver and
  apt-installs the output. The base image keeps the bootstrap stack;
  declared extensions ride the site layer.
- **`install.sh`** (bare-metal site path): `install_declared_dependencies()`
  after `deploy_application_code`, before `_site_init.sh` so composer/
  update_database see the extensions. The `server` path's hardcoded list
  remains as the bootstrap set only.
- **`utils/upgrade.php`**: after the code swap and before composer
  validation, run the resolver and install anything newly declared, then
  gracefully reload web PHP. Requires root (docker exec has it; bare-metal
  non-root degrades to a warning naming the manual command). Install failure
  logs a loud warning but does not roll back the upgrade — the activation
  gate is the backstop that keeps a plugin needing the missing extension
  from running.

### Theme parity

`ComponentBase::checkRequirements()` gains the `requires.extensions` check so
themes get the same activation gate plugins have (today
`ThemeManager::onActivate()` checks only PHP and Joinery versions).

## Tier 2 — Plugin host installers

### Declaration

New optional `plugin.json` key:

```json
"host_installer": "provisioning/install_email.sh"
```

Discovery is by manifest key, not filename convention — the mailbox plugin's
existing `provisioning/install_email.sh` is declared as-is, no rename. The
`provisioners[].script` fields are unchanged and keep pointing at the same
scripts for the detect-and-report UI.

### Contract (documented in the plugin developer guide)

The script must be: idempotent (safe to run on every container start), root,
non-interactive (`DEBIAN_FRONTEND=noninteractive`), and exit 0 when
not-applicable (plugin inactive for the site, feature setting off). The
mailbox installer already satisfies all of this — it is the reference
implementation.

### Runner

`maintenance_scripts/install_tools/_plugin_installers_start.sh SITENAME` —
the generalization of `_mail_stack_start.sh`. It reads the site's active
plugins from the database (same dbname-extraction pattern
`_mail_stack_start.sh` uses), and for each active plugin whose manifest
declares `host_installer`, runs the script with the same fail-safe semantics:
any failure logs and exits 0 so the container always starts.
`_mail_stack_start.sh` is deleted; the mailbox-specific activation check moves
into the generic active-plugin filter.

Invoked from:
- `Dockerfile.template` CMD (replacing the `_mail_stack_start.sh` line)
- `install.sh` site build, after `_site_init.sh`
- `utils/upgrade.php`, after the code swap (idempotency makes this free, and
  it's what picks up a plugin's new host requirements on deploy)

### Activation

Unchanged in mechanism, improved in message: activating a plugin whose
provisioner checks fail keeps succeeding or failing exactly as today, but the
provisioning failure surface (admin badges, `check_provisioning.php`) is the
canonical "what to run" pointer. On Docker nodes the practical instruction is
"restart the container"; the admin provisioning UI for a failed check on a
plugin with a `host_installer` says so.

## Tier 3 — Per-plugin composer packages

### Declaration

New optional `plugin.json` block:

```json
"requires": {
    "composer": { "web-auth/webauthn-lib": "^5.0" }
}
```

### Mechanism

A reconcile step, implemented in `ComposerValidator` (which already knows how
to cross-reference `composer.lock` and shell out to composer via
`installIfNeeded()`):

1. Collect `requires.composer` from all **active** plugins.
2. Cross-plugin constraint disagreements are detected before composer runs:
   the root manifest holds one constraint per package, so two plugins
   declaring the same package with different constraints would silently
   last-win and composer would never see the conflict. The reconcile refuses
   (activation refusal names both plugins); `validate()` surfaces the same
   condition as a warning for already-active plugins.
3. For each package absent from `composer.lock` (or whose root constraint
   differs), run `composer require vendor/pkg:constraint --no-dev` against
   the root manifest. Composer environment: processes with a writable HOME
   (root CLI runs) are untouched; only www-data (activation) gets
   `COMPOSER_HOME` pointed at the site's existing `{site root}/cache`
   directory — no new location invented. An unsatisfiable constraint fails
   the require and the failure output is surfaced verbatim.
4. Root `composer.json` remains the single manifest; plugin-driven requires
   are ordinary entries in it, added by the reconcile step. No merge plugin,
   no per-plugin vendor dirs, one autoloader. The container start-time chown
   covers `vendor/` so the www-data reconcile can write it.

### Run moments

- **Plugin activation** (`PluginManager::onActivate()`): reconcile runs
  synchronously — composer is userland and the vendor dir is www-data
  writable, so this is the one tier that genuinely installs at activation.
  Failure (network down, conflict) refuses activation with composer's output.
- **`update_database`**: the existing `ComposerValidator` call site extends to
  validate plugin-declared packages too, and `installIfNeeded()` reconciles —
  this covers deploys where code for an already-active plugin arrives with a
  new dependency.
- **`utils/composer_install_if_needed.php`**: same extension, covers
  `_site_init.sh`.

### Deactivation

Packages are left installed. An installed-but-unused composer package is
inert; removal risks breaking another plugin activated later with the same
dep. `list_dependencies.php --composer --orphans` lists packages in the
managed set no active plugin declares, for manual cleanup.

## What the mechanism fixes by itself

No manual setup of any environment is part of this spec. These outcomes fall
out of the mechanism:

- Fresh installs get APCu because the Tier 1 resolver reads the `ext-apcu`
  already declared in root `composer.json` — the known drift bug dies with
  the hardcoded list, not with a hand-patch.
- Existing nodes converge without intervention, because `upgrade.php` is one
  of the install moments.
- The only manifest edit is `plugins/mailbox/plugin.json` gaining
  `host_installer: "provisioning/install_email.sh"`, which is a code change
  shipped like any other.

## Files

| File | Change |
|---|---|
| `utils/list_dependencies.php` | New — resolver (extensions, apt mapping, composer set, orphans) |
| `maintenance_scripts/install_tools/_plugin_installers_start.sh` | New — generic host-installer runner (replaces `_mail_stack_start.sh`) |
| `maintenance_scripts/install_tools/_mail_stack_start.sh` | Deleted |
| `maintenance_scripts/install_tools/Dockerfile.template` | Build-time resolver-driven extension install; CMD calls the generic runner |
| `maintenance_scripts/install_tools/install.sh` | `install_declared_dependencies()`; runner after site init (bare-metal path); version bump |
| `utils/upgrade.php` | Post-swap: resolver-driven extensions + runner |
| `utils/composer_install_if_needed.php` | Plugin-aware reconcile |
| `includes/ComposerValidator.php` | Validate + reconcile plugin-declared packages |
| `includes/PluginManager.php` | Activation-time composer reconcile; refusal messaging |
| `includes/ComponentBase.php` | `checkRequirements()` gains extension check (theme parity) |
| `plugins/mailbox/plugin.json` | `host_installer` key |

## Documentation to update

- `docs/plugin_developer_guide.md` — new "Declaring Dependencies" section
  covering the three tiers, the `host_installer` contract, and
  `requires.composer`; update the manifest key table.
- `docs/deploy_and_upgrade.md` — the four root moments and what each installs.
- `docs/settings.md` — no change (no new settings).

## Acceptance

1. Fresh `install.sh site` on a clean host → `/profile/security` vault setup
   and unlock work with no manual package installs (APCu present).
2. A test plugin declaring `requires.composer` installs its package on
   activation; activation with the network blocked refuses with composer's
   error and the plugin stays inactive.
3. Mailbox provisioning behavior is unchanged through a container
   stop/start/rebuild cycle (generic runner replaces `_mail_stack_start.sh`
   with identical outcomes).
4. `upgrade.php` on a node whose new code declares a new extension installs
   it during the upgrade run.
5. Activating a theme whose `theme.json` declares a missing extension is
   refused (parity check).

## Open items

1. **Ext→apt override map contents** — RESOLVED: `php8.3-apcu` exists
   (sury PPA on dev; stock noble ships `php-apcu`). The mapping tries
   `php8.3-{ext}` first and falls back to `php-{ext}`.
2. **Bare-metal non-docker sites** — moments 1 and 3 don't exist there;
   site build and upgrade cover install, but nothing re-asserts services on
   reboot. Out of scope here (pre-existing condition, systemd owns it on
   bare metal), but the runner should be safe to call from a systemd unit if
   one is added later.
3. **Composer at activation needs network** — air-gapped or
   egress-restricted deployments would need the reconcile done at build time
   (`--active-only` off). Not designed for here; noted as the constraint.
4. **Union-install scope** — the base image installs extensions for all
   bundled plugins, active or not. If a future plugin needs a pathological
   extension (huge, conflicting), revisit with an opt-out flag in the
   resolver.
