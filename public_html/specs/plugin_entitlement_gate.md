# Plugin Entitlement Gate — Deferred Enforcement for Sold Licenses

**Status:** Unbuilt, deliberately deferred (owner, 2026-07-30). Selling shipped
first: `specs/implemented/open_core_licensing.md` built key minting, license
files, manifest fields (`license`, `requires_entitlement`, `status`), and the
end of system plugins. This spec holds the enforcement half that was cut from
that build so it lands later as an intentional behavior change.

**Why deferred:** dev releases are delivered by pointing `upgrade_source` at
the dev box, and a download gate would break every unlicensed site doing
that. With perpetual purchases and updates included there is no lapsed state
to police, so the gate currently protects nothing but the first download.
Paid plugins are honor-system until this builds.

**What already exists to build on:** every key is minted and recorded from
day one (`lck_license_keys` in the store plugin: buyer, order, order item,
plugin name, `lck_revoked_time` for a future lapse model), and the paid
manifests already declare `requires_entitlement` — declared now, read only by
the catalog listing. Flipping enforcement on later affects downloads only,
never installed function; existing buyers just paste the key they already
hold.

## Build items

### 1 — Entitlement check on the download endpoint

`plugins/server_manager/includes/publish_theme.php`, `?download=` branch: if
the requested plugin's manifest declares `requires_entitlement`, require a
valid license key (header or parameter) that maps to a non-revoked
`lck_license_keys` row for that plugin, and 402 otherwise. Free plugins and
all themes keep serving anonymously — the installer bootstraps through this
endpoint.

The key is checked at **download and update time only**:

- No runtime enforcement, no license check on page load, no kill switch.
- A lapsed license (if a lapse model is ever introduced for future buyers)
  stops *updates*, never *function*.
- Security fixes are never withheld: a security-flagged release serves
  regardless of entitlement state (requires a release marker — design the
  marker with the gate).
- Manual archive upload stays available; an offline deployment is never
  locked out of software it bought.

### 2 — Key entry and status in admin

`/admin/admin_plugins` grows a license key field and shows, per plugin,
free / entitled-and-active / entitled-and-lapsed. A lapsed plugin shows
"updates paused" — never a warning banner implying the site is broken. The
key is stored per plugin (a `plg_plugins` column, not a setting).

### 3 — Client key transmission

`PluginManager::refreshFromUpstream()` sends the stored key with the download
request for plugins whose manifest declares `requires_entitlement`.

### 4 — Installer skip-and-report

`install.sh` `download_themes_and_plugins()` skips plugins whose manifest
declares `requires_entitlement` unless a key is supplied, and says so in its
output rather than silently omitting them.

## Tests (deferred with the gate)

- 402 on keyless entitled downloads; valid-key serve.
- Security-flagged releases serve regardless of entitlement.
- Lapsed-key behavior (updates refused, function untouched).
- Installer skip-and-report.
- Flip `plugin_distribution_anonymous_test.php`
  (`plugins/server_manager/tests/`) — it currently asserts the ungated serve
  **deliberately** and its header names this spec as the moment it gets
  rewritten.

## Documentation (deferred with the gate)

`docs/deploy_and_upgrade.md`: entitled plugin delivery, key handling, and the
guarantee that updates are gated but function never is. Nothing is written
until the gate exists — docs describe current state only.
