# Spec: Replace `is_stock` with `receives_upgrades` + `included_in_publish`

## Background

Themes and plugins carry a single boolean `is_stock` that today drives two unrelated decisions:

1. **Customer-side deploy behavior** — Should the installed copy be replaced from the upgrade payload, or preserved across the deploy swap?
2. **Publisher-side packaging behavior** — Should this extension be included when the publish server bundles archives for downstream sites?

The vocabulary "stock" / "custom" is overloaded in English ("in inventory" vs. "from the factory" vs. "official") and the field name only names one pole of the binary while every UI label, comment, error message, and admin action talks about both. The conflation has caused repeated misreadings of `is_stock: true` as "currently active" or "system-built-in" rather than its actual meaning.

This spec replaces `is_stock` with two action-named flags that say what they *do*, eliminates "stock" / "custom" vocabulary from the platform, and aligns admin UI with the fact that only the deploy-side flag is operator-controllable.

## Design

### Two manifest flags, one DB column

| Flag | Default | Meaning | Who reads it | Who writes it |
|---|---|---|---|---|
| `receives_upgrades` | `true` | If true, replace the on-disk copy from the upgrade payload during deploy. If false, preserve. | Customer-side deploy code (`DeploymentHelper`, `upgrade.php`, `deploy.sh`, `_reconcile_upgradable_assets.sh`, `AbstractExtensionManager` overwrite check) | Manifest authors (in source); operator via admin UI; auto-set to `false` when an extension is uploaded |
| `included_in_publish` | `true` | If true, include this extension when the publish server packages upgrade archives. If false, the publish pipeline skips it. | Publisher-side packaging (`publish_upgrade.php`, `publish_theme.php` catalog + download endpoints) | Manifest authors only — never via admin UI |

Both flags live in the manifest (`plugin.json` / `theme.json`). **Only `receives_upgrades` is mirrored to the database** so the admin UI can read/toggle without touching the file on every render. `included_in_publish` is manifest-only — only the publish pipeline reads it, and that always reads from the manifest directly. No DB column, no field-spec entry, no migration data-copy for it. If admin UI ever needs to display it, the existing `Plugin::get_plugin_metadata()` / `Theme` equivalent already reads the manifest cheaply.

### Mapping from current state

Today's `is_stock=true` becomes `receives_upgrades=true, included_in_publish=true`.
Today's `is_stock=false` becomes `receives_upgrades=false, included_in_publish=false`.

After the migration, the only `false` cases in the repo are `theme/getjoinery` and `theme/scrolldaddy` — both site-specific themes that should neither be packaged for distribution nor overwritten on deploy.

### Admin UI changes

The current "Mark as Stock / Mark as Custom" toggle becomes a single boolean affecting `receives_upgrades` only.

- **Badges**: Drop the always-shown "Stock" badge entirely. Show a single "Preserved on deploy" badge only when `receives_upgrades=false`. The default is the no-badge case — most extensions don't need a label for the default behavior. Keep the existing "System" badge (separate concept, unchanged).
- **Action labels**: "Mark as Custom" → "Preserve on deploy"; "Mark as Stock" → "Allow upgrade replacement". (Wording open to refinement during implementation; final text must avoid "stock" and "custom".)
- **Action keys**: `mark_custom` → `mark_preserved`; `mark_stock` → `mark_upgradable`.
- **Modal warning text**: "stock theme" / "custom theme" rewritten to explain in terms of the actual behavior (e.g., "This theme is preserved on deploy. Deleting it removes a copy that won't be re-downloaded by the upgrade system.").
- **`included_in_publish` is not exposed as a control.** No toggle. No badge. Not stored in the DB. If admin UI ever needs to display it, read the manifest at render time via the existing `Plugin::get_plugin_metadata()` / `Theme` equivalent.

### Defaults and seeding rules

- **Manifest authors**: must explicitly declare both `receives_upgrades` and `included_in_publish` in the manifest. No silent default at the file level (the loader still defaults missing keys to `true` for forward compatibility, but core/plugin manifests we ship must include both keys explicitly).
- **Uploaded extensions** (via `PluginManager::postInstall` / `ThemeManager::postInstall`): `receives_upgrades` set to `false`. Operator can toggle `receives_upgrades` to `true` from the admin UI later if they want updates. `included_in_publish` is irrelevant on customer sites; the publish pipeline doesn't run there.
- **System extensions** (`is_system=true`): `receives_upgrades` is forced `true` and the admin UI cannot toggle it off — same protection that exists today for system themes against `mark_custom`.

## File-by-file changes

### Schema

**`data/themes_class.php`**
- Replace `'thm_is_stock' => array('type'=>'bool', 'default'=>true)` with `'thm_receives_upgrades' => array('type'=>'bool', 'default'=>true)`. No DB column for `included_in_publish`.
- `is_stock()` accessor → remove. Replace with `receives_upgrades()` accessor only.
- `MultiTheme::getMultiResults()` filter handler at line 292 — replace `thm_is_stock` filter key with `thm_receives_upgrades`.

**`data/plugins_class.php`**
- Same field-spec swap: `'plg_is_stock'` → `'plg_receives_upgrades'`. No DB column for `included_in_publish`.
- `is_stock()` → remove; replace with `receives_upgrades()` accessor only.
- `load_stock_status()` at line 236 → rename to `load_receives_upgrades()`; reads `receives_upgrades` from manifest, writes `plg_receives_upgrades`.

### Migration

**New migration in `migrations/migrations.php`** (data migration, not schema — `update_database` will auto-create the new columns from the field-spec changes).

**Confirmed safe ordering** (verified against `utils/update_database.php`): `update_database` runs in this order — Step 1 adds missing columns from `$field_specifications` (only adds, never drops); Step 3.9 verifies all `$field_specifications` columns exist in the DB; Step 4 runs migrations. By Step 4, `thm_receives_upgrades` / `plg_receives_upgrades` / `plg_is_system` exist (Step 1 added them) and `thm_is_stock` / `plg_is_stock` also still exist (Step 1 doesn't drop). The migration's `UPDATE ... SET new = old` succeeds, then `ALTER TABLE ... DROP COLUMN old` removes the legacy column. Step 3.9 doesn't fail on extra columns, only on missing ones.

```php
$migration = [];
$migration['id'] = 'extension_flag_split_2026_05_XX';
$migration['description'] = 'Copy is_stock to receives_upgrades, then drop is_stock';
$migration['test'] = "SELECT CASE WHEN EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_name='thm_themes' AND column_name='thm_is_stock'
) THEN 0 ELSE 1 END as count";
$migration['migration_sql'] = "
    UPDATE thm_themes SET thm_receives_upgrades = thm_is_stock;
    UPDATE plg_plugins SET plg_receives_upgrades = plg_is_stock;
    ALTER TABLE thm_themes DROP COLUMN thm_is_stock;
    ALTER TABLE plg_plugins DROP COLUMN plg_is_stock;
";
```

The new columns will already exist when this migration runs, because `update_database` runs the field-spec sync before iterating migrations. The data copy then drops the old column.

**Existing migration at `migrations/migrations.php:1193`** (joinery-system insert) — update the column list and value tuple to use the new column names. (This is an `INSERT` migration with a `test` that gates on existence, so editing it is safe.)

### Customer-side deploy (PHP)

**`includes/AbstractExtensionManager.php:320-321`** — overwrite protection on tar.gz install:
```php
if (is_array($local_manifest) && isset($local_manifest['receives_upgrades']) && !$local_manifest['receives_upgrades']) {
    throw new Exception("Cannot replace {$this->extension_type} '$extension_name': it is marked receives_upgrades: false (preserved on deploy).");
}
```

**`includes/DeploymentHelper.php`** — read the new key throughout:
- Lines 470-472, 513-535 (`updateInstalledThemesOnly` themes loop): `$is_stock = $manifest['is_stock'] ?? true` → `$receives_upgrades = $manifest['receives_upgrades'] ?? true`. Branch label `Updated stock theme:` → `Updated theme:`; `Preserved custom theme:` → `Preserved theme (receives_upgrades=false):`.
- Lines 553-579 (same logic for plugins) — same swap.
- Line 594 docblock for `preserveCustomThemesPlugins` — rename method to `preserveExtensionsAcrossDeploy` and update docs. Update all callers.
- Lines 671-738 (`processThemeOrPlugin` private helper) — read `receives_upgrades` from manifest.
- Line 748 (`generateManifest`) — auto-generated manifest writes `'receives_upgrades' => true, 'included_in_publish' => true`.
- Lines 1019-1180 (`copyCustomToStaging`) — rename to `copyPreservedToStaging`. Reads `receives_upgrades` from manifest. Update all callers.

**`includes/PluginManager.php`**
- Line 141-146 (postInstall after upload): set `plg_receives_upgrades=false`. Comment updated to "Mark as preserved-on-deploy since it was uploaded." `included_in_publish` is irrelevant on customer sites — uploaded extensions are never published; the publish pipeline only runs on the publisher and only reads its own theme/ and plugins/ directories.
- Line 167-174 (`loadMetadataIntoModel`): set `plg_receives_upgrades` from `$metadata['receives_upgrades'] ?? true`. No `included_in_publish` write.
- Line 188-191 (`updateExistingMetadata`): call new `load_receives_upgrades()` method.
- Lines 770, 815, 861 — comments mention "custom plugin"; rewrite to "uploaded/preserved plugin (receives_upgrades=false)".

**`includes/ThemeManager.php`**
- Line 119-128 (postInstall): set `thm_receives_upgrades=false`, mirror the PluginManager pattern.
- Line 138-149 (`loadMetadataIntoModel`): set `thm_receives_upgrades` from `$metadata['receives_upgrades'] ?? true`. No `included_in_publish` write.
- Line 217-234 (`updateExistingMetadata`): replace `is_stock` reads/writes with `receives_upgrades`.
- Lines 553-579 (`writeManifestStockStatus`): rename to `writeManifestReceivesUpgrades($theme_name, $receives_upgrades)`. The auto-generated manifest stub at line 565-569 includes both `receives_upgrades` and `included_in_publish` (defaulting to `true`/`true` for new generated stubs, since auto-generation only triggers for existing on-disk extensions that lack a manifest, which by definition the operator chose to keep).

**`utils/upgrade.php`**
- Lines 893-934 (active-theme-missing pre-flight): read `receives_upgrades` instead of `is_stock`. The user-facing instruction at line 923 changes from `Mark the theme as custom by adding "is_stock": false` to `Mark the theme as preserved by adding "receives_upgrades": false to its theme.json`.
- Line 980-998 (preservation step): uses renamed `DeploymentHelper::copyPreservedToStaging`. Update headings and echo strings (no "Custom Themes/Plugins"; use "Preserved Themes/Plugins").
- Lines 1357-1385 (stale reconciliation SQL): `plg_is_stock = true` → `plg_receives_upgrades = true`; same for `thm_is_stock`. Semantically: "of the things this site is set to receive updates for, which ones did upstream stop publishing?"
- Lines 1611-1625 (`get_installed_stock_themes`): rename to `get_themes_to_upgrade`; filter on `receives_upgrades`.
- Lines 1627-1662 (`get_system_required_themes`, `get_system_required_plugins`): change manifest read from `is_stock` to `receives_upgrades` (a system-required item is by definition something that upgrades).
- Lines 1729-1773 (`get_all_themes_info`, `get_all_plugins_info`): `is_stock` field in returned arrays replaced by `receives_upgrades`. The `will_upgrade` derived field semantics unchanged.
- Lines 1775-1830 (`output_component_status`): "Stock" column header → "Upgrades" or "Auto-update". Comment at 1788-1790 rewritten.

### Customer-side deploy (Bash)

**`maintenance_scripts/install_tools/_reconcile_stock_assets.sh`**
- Rename to `_reconcile_upgradable_assets.sh`. Single caller: `maintenance_scripts/install_tools/Dockerfile.template:123` (CMD line) — update there. Comment at `Dockerfile.template:15` references the file's prior rename history; update for accuracy. Stale copies under `uploads/upgrades/` regenerate on next publish — no manual update needed.
- File header comment: rewrite to use "receives_upgrades" terminology. Drop the "stock" framing.
- Line 81: `WHERE plg_is_stock = true` → `WHERE plg_receives_upgrades = true`.
- Line 85: `WHERE thm_is_stock = true` → `WHERE thm_receives_upgrades = true`.
- All echo strings: drop "stock" — use "upgradable" or just "registered".

**`maintenance_scripts/install_tools/deploy.sh`**
- Lines 275-322: replace `is_stock` variable name and `get_json_value "$manifest_file" "is_stock" "true"` with `receives_upgrades` and the new key.
- Verbose-echo strings: "Updating stock theme:" → "Updating theme:"; "Preserved custom theme:" → "Preserved theme:".

### Publisher-side packaging

**`plugins/server_manager/includes/publish_upgrade.php`**
- Lines 438-445 (theme archive packaging): `$is_stock = $theme_data['is_stock'] ?? true; if (!$is_stock) skip` → `$published = $theme_data['included_in_publish'] ?? true; if (!$published) skip`. Skip-message: "(not stock)" → "(included_in_publish=false)".
- Lines 495-502 (plugin archive packaging): same swap.
- Lines 980-1051 (the alternate publish_theme entrypoint variant in this same file): same swap.

**`plugins/server_manager/includes/publish_theme.php`**
- Lines 35-58 (catalog `?list=themes`): `is_stock` filter → `included_in_publish` filter. The `is_stock` field in the JSON response → replace with `included_in_publish` for clarity (consumers of this endpoint also need the rename).
- Lines 60-90 (catalog `?list=plugins`): same.

### Admin UI

**`adm/admin_themes.php`**
- Line 101: `$is_stock = ... $theme->get('thm_is_stock')` → `$receives_upgrades = ... $theme->get('thm_receives_upgrades')`.
- Lines 116-123 (badge logic): drop the always-shown Stock badge. Show "Preserved on deploy" badge only when `receives_upgrades=false`. System badge unchanged.
- Lines 165-180 (action menu): `mark_custom` → `mark_preserved`; `mark_stock` → `mark_upgradable`. Action labels updated to action-named verbs (e.g. "Preserve on deploy" / "Allow upgrade replacement"). `is_stock_theme` JS variable → `receives_upgrades_theme`.
- Lines 213-218 (notes block): rewrite all three bullets to use the new terminology and explain `receives_upgrades` directly. Drop `included_in_publish` from this list — it's not user-controllable so it doesn't belong in operator-facing help.
- Lines 273-318 (delete modal HTML + JS): rename DOM ids `stockThemeWarning` / `customThemeWarning` → `upgradableThemeWarning` / `preservedThemeWarning`. Inline copy: "stock theme" / "custom theme" rewritten to describe behavior. JS function param `isStock` → `receivesUpgrades`.

**`adm/admin_plugins.php`**
- Lines 155-162: same badge logic — drop default "Stock" badge, show "Preserved on deploy" only when `receives_upgrades=false`.
- Plugin admin currently has no toggle (it's read-only for stock/custom display). After this change it shows the badge and nothing else — same UX.

**`adm/logic/admin_themes_logic.php`**
- Lines 65-92 (`mark_stock` / `mark_custom` cases): rename action keys, update field names, update success messages. Call renamed `writeManifestReceivesUpgrades()`.

### Migration sync helper

**`migrations/theme_plugin_registry_sync.php`**
- Lines 55-77: swap `plg_is_stock` reads/writes for new method names. Update the "stock/custom" terminology in echo lines.

## Manifest file updates (22 files)

Every plugin.json and theme.json that currently has `"is_stock": ...` needs to be rewritten to use the two new keys. This is mechanical — for each file:

- If `is_stock: true` (or absent): add `"receives_upgrades": true` and `"included_in_publish": true`, remove `is_stock`.
- If `is_stock: false`: add `"receives_upgrades": false` and `"included_in_publish": false`, remove `is_stock`.

Files affected:
- `plugins/bookings/plugin.json`
- `plugins/dns_filtering/plugin.json`
- `plugins/email_forwarding/plugin.json`
- `plugins/items/plugin.json`
- `plugins/joinery_ai/plugin.json`
- `plugins/server_manager/plugin.json`
- `theme/default/theme.json`
- `theme/devonandjerry-html5/theme.json`
- `theme/devonandjerry/theme.json`
- `theme/devonnearhill-html5/theme.json`
- `theme/empoweredhealth-html5/theme.json`
- `theme/galactictribune-html5/theme.json`
- `theme/getjoinery/theme.json` *(both flags false)*
- `theme/jeremytunnell-html5/theme.json`
- `theme/joinery-system/theme.json`
- `theme/linka-reference-html5/theme.json`
- `theme/phillyzouk-html5/theme.json`
- `theme/scrolldaddy/theme.json` *(both flags false)*
- `theme/tailwind/theme.json`
- `theme/xandyliberato-html5/theme.json`
- `theme/zoukphilly-html5/theme.json`
- `theme/zoukroom-html5/theme.json`

## Documentation updates

Per project convention, developer docs go in `/docs/`, not in this spec. The following files need terminology rewrites — the spec author owes it to remove every "stock" / "custom" / `is_stock` mention from these docs and replace with the two new flags' semantics. Anchors below use heading names (locate with `grep -n "^### Heading"`) plus current line numbers as a hint; rely on the heading anchor since line numbers will drift.

### `docs/deploy_and_upgrade.md`

1. **Heading `### install.sh`** (~L81-87). Body sentence at L85 says "Supports `--themes` flag to download stock themes/plugins from the upgrade server after site creation." Rewrite "stock themes/plugins" → "published themes/plugins" (i.e., extensions with `included_in_publish: true` on the upgrade server).

2. **Heading `### deploy.sh`** (~L91-118). Features bullet list at L111-116 contains "Preserves custom themes/plugins (is_stock: false)" at L115. Rewrite to "Preserves extensions marked `receives_upgrades: false`".

3. **Heading `### upgrade.php`** (~L120-183). Features bullet list at L151-158 has the same "Preserves custom themes/plugins (is_stock: false)" line at L155 — same rewrite.

4. **Same heading**, paragraph at L160 "**Plugin refresh scope:**". The phrase "Stock plugins succeed; custom plugins 404 ... see [Stock vs. Custom](#stock-vs-custom-themesplugins) below" — rewrite the prose to drop "stock"/"custom" vocabulary and update the anchor link target (the [Stock vs. Custom] section is being renamed in step 8 below — point this link at the new heading). The clarifying parenthetical about why custom plugins 404 ("they were never packaged") becomes "they were never packaged because they have `included_in_publish: false`".

5. **Same heading**, paragraph at L162. This whole paragraph is *the* canonical explanation of what `is_stock` governs (publish + deploy-swap + container-reconcile). Rewrite end-to-end with the two-flag model: `included_in_publish` governs what `publish_upgrade.php` packages; `receives_upgrades` governs what `DeploymentHelper` preserves across a deploy swap and what `_reconcile_upgradable_assets.sh` re-downloads on container boot. Mention also that the upgrade-time refresh loop no longer filters by either flag — it just tries everything installed.

6. **Same heading**, "Download Flow" numbered list at L168-175. Step 3 at L171: "Downloads each stock theme archive" → "Downloads each published theme archive (`included_in_publish: true`)".

7. **Heading `### publish_upgrade.php`** (~L186-219). Features bullet list at L201-207, L204 says "Each stock theme/plugin gets its own versioned archive" → "Each theme/plugin with `included_in_publish: true` gets its own versioned archive".

8. **Heading `### Deployment Flow`** (~L251). Step 3 at L259 "DeploymentHelper preserves custom themes/plugins (is_stock: false)" → "DeploymentHelper preserves extensions marked `receives_upgrades: false`".

9. **Heading `## Theme/Plugin Preservation`** (~L291-308). **Replace this entire section wholesale.** New section title: `## Extension Distribution Flags`. New body explains `receives_upgrades` (customer-side, deploy preservation, admin-toggleable) and `included_in_publish` (publisher-side, packaging filter, manifest-only) as separate concerns. Example manifest showing both keys. Drop the "If manifest is missing, it's auto-generated with `is_stock: true`" line at L308 — replace with: "If manifest is missing, it's auto-generated with both flags `true`." Also: this is the section linked from L160's `[Stock vs. Custom]` anchor — pick a new slug and update the link.

10. **Heading `## Common Issues`** > subheading `**Custom Theme Overwritten:**` (~L391-393). The bullet at L392 "Check manifest has `"is_stock": false`" → "Check manifest has `"receives_upgrades": false`". Also rewrite the subheading itself: `**Custom Theme Overwritten:**` → `**Preserved Theme Overwritten:**`.

11. **Heading `## Marketplace`** > subheading `### Overwrite Protection` (~L440-443). Both bullets:
    - L442: "**Stock extensions** (or those without a manifest) can be reinstalled/replaced..." → "**Extensions with `receives_upgrades: true`** (or those without a manifest) can be reinstalled/replaced..."
    - L443: "**Custom extensions** (`is_stock: false` in manifest) are protected..." → "**Extensions with `receives_upgrades: false`** are protected...".

12. **Same heading** > subheading `### Catalog Endpoint Fields` (~L445-450). Field list at L450 — replace `is_stock` with `included_in_publish` (and verify against the actual JSON the rewritten endpoint emits — see the `publish_theme.php` code-change section above).

### `docs/plugin_developer_guide.md`

1. **Heading `### Plugin.json Requirements`** > subheading `#### Deprecation Fields` (~L232-256). Code example at L241-249 contains `"is_stock": true`. Replace with `"receives_upgrades": true,\n    "included_in_publish": true,`. The example is illustrative; both flags `true` is right.

2. **Heading `### Plugin Lifecycle`** (~L519+), the install-flow numbered list. Item 1 at L532 mentions "stock plugins get current code on every install. Custom plugins 404 silently". Rewrite: "plugins with `included_in_publish: true` on the upgrade server get current code on every install; plugins not in the publisher's catalog 404 silently and the install proceeds with on-disk files." Same heading, paragraph at L577: "the upgrade-endpoint refresh pulls fresh stock code, so stale on-disk files don't linger" → "the upgrade-endpoint refresh pulls fresh published code, so stale on-disk files don't linger".

3. **Heading `### Theme Metadata (theme.json)`** (~L961-1026). Three example blocks: "Basic theme.json" (L965-982), "Tailwind theme.json" (L984-1007), "HTML5 framework-agnostic theme.json" (L1009-1026). All three currently have `"is_stock": false` at L973, L992, L1017. Each example is a starter template for someone *creating a new theme on a customer site* — the right defaults for that case are `"receives_upgrades": false` and `"included_in_publish": false` (a freshly-authored site theme is preserved across upgrades and not republished). Replace each `"is_stock": false` line with both new keys both `false`.

4. **New subsection** to add immediately under the `### Theme Metadata (theme.json)` heading, before the example blocks: a short "Distribution Flags" callout that explains `receives_upgrades` and `included_in_publish` side-by-side, and notes that this same pair applies to `plugin.json`. ~5-8 lines. This is the canonical developer-facing explanation; the deploy_and_upgrade.md section is operator-facing.

### `docs/theme_integration_instructions.md`

1. **First theme.json example at L215-225** (just under whatever heading owns it — locate by reading from L200 forward). L221 has `"is_stock": true,` — replace with `"receives_upgrades": true,\n    "included_in_publish": true,`.

2. **Second theme.json example at L1575-1585**. L1581 same rewrite.

Both occurrences are template/example manifests intended to be copied by integrators creating themes that *will be shipped via the publisher*; default both flags `true` for these examples.

## `is_system` normalization (in scope)

While we're rewriting every manifest and touching the extension data layer, fix the parallel mess `is_system` has accumulated:

- **Canonical manifest key is `is_system`.** Some current manifests use `system`, some use `is_system`, and `publish_theme.php:49` reads either via `$theme_data['is_system'] ?? $theme_data['system'] ?? false`. Pick `is_system` everywhere — matches the DB column convention (`thm_is_system`) and the rest of our boolean naming (`is_active`, `is_nullable`).
- **Add `plg_is_system` to the plugin schema.** Themes have `thm_is_system`; plugins don't. Plugin system-status is read manifest-only today. Add `plg_is_system` (`bool default false`) to `Plugin::$field_specifications`, populate from manifest at sync time the same way themes do. Eliminates an asymmetry that makes admin UI and any future cross-extension query treat themes and plugins differently.
- **`is_system` does NOT override the new flags.** A system extension with `receives_upgrades: false` in its manifest is respected — the operator/developer must have a reason. Independent flags. Consequence: the existing admin UI block that prevents `mark_custom` on system themes (`admin_themes_logic.php:81-85`) is removed in the rewrite. The "System" and "Preserved on deploy" badges can both appear on the same extension; the operator sees both signals.
- **No backcompat shim for `system` → `is_system`.** All in-tree manifests get rewritten in lockstep with the code change. The publisher-side loader stops accepting `system` as a fallback.

### Manifest rewrite expansion

The 22-file manifest pass already enumerated above also normalizes `is_system`/`system`. For each file:
- If `system: true` exists, replace with `is_system: true`.
- If `is_system: true` already exists, leave it.
- If neither exists (most files), no change — default is false.

Files known to currently use the wrong key need an explicit grep before the pass; no manual list maintained here.

### Code changes for `is_system`

- `data/plugins_class.php`: add `'plg_is_system' => array('type'=>'bool', 'default'=>false)` to field specifications.
- `includes/PluginManager.php::loadMetadataIntoModel`: set `plg_is_system` from `$metadata['is_system'] ?? false`. Drop the `$metadata['system']` fallback.
- `includes/ThemeManager.php::loadMetadataIntoModel:148`: change `$metadata['system'] ?? false` to `$metadata['is_system'] ?? false`. Drop the `system` fallback.
- `migrations/theme_plugin_registry_sync.php:38`: change `$manifest['system']` to `$manifest['is_system']`.
- `plugins/server_manager/includes/publish_theme.php:49, 81`: drop the `?? $theme_data['system'] ?? false` fallback — read `is_system` only.
- `utils/upgrade.php:1637, 1655`: same — drop the `system` fallback.
- `adm/logic/admin_themes_logic.php:81-85`: remove the block that prevents marking a system theme as custom. Independent flags now.
- Admin UI badges: "System" badge logic unchanged (still shows for `is_system=true`). "Preserved on deploy" badge can co-appear.

### Migration for `is_system`

Add to the migration described above:
```sql
-- plg_is_system column will be auto-created by update_database before this runs
-- (same ordering assumption as for the new flags above)
-- No data copy needed; default is false; new manifests get sync'd at next ThemeManager::sync()/PluginManager::sync()
```

Trigger a `PluginManager::sync()` after the migration so the new `plg_is_system` column is populated from each plugin's manifest immediately. Otherwise the column stays false until something else triggers a sync.

## Out of scope

- Plugin/theme `status` lifecycle (`active`, `inactive`, `installed`, `error`, `stale`) is unchanged.
- The `deprecated` and `superseded_by` fields are unchanged.
- No backward compatibility shim. Manifests after this change must use the new keys; the loader's `?? true` fallback only protects against the new keys being absent (e.g., for unmigrated third-party manifests in the wild). All in-tree manifests get rewritten in lockstep with the code change.

## Rollout

Single atomic change — DB migration, code rename, and manifest rewrites land together. No backcompat shim, no transitional vocabulary, no dual-key reader.

We are pre-launch and every live site runs on `docker-prod` under our control, so the upgrade-pipeline format break is contained: push the publish-server change and the customer-side reader change in the same upgrade payload, run upgrade on every container in `docker-prod`, done. There is no third-party site running an old `utils/upgrade.php` to worry about.

Order of operations on the day of the change:

1. Merge code + manifest rewrites + migration on the publish server in one commit.
2. Run `update_database` on the publish server. Confirm the migration ran and the old columns are gone.
3. Republish the upgrade archive (`php plugins/server_manager/includes/publish_upgrade.php "is_stock split"`).
4. Run `php utils/upgrade.php --verbose` on each `docker-prod` container. Each one applies the new code (which contains the new reader) before its own deploy-swap looks at any manifests, so no container ever runs an old reader against a new manifest.

## Verification

Before marking this complete:

1. **`grep -rn "is_stock\|plg_is_stock\|thm_is_stock" --include="*.php" --include="*.json" --include="*.md" --include="*.sh"`** returns zero matches in the public_html tree and the maintenance_scripts tree (except in spec history, which is allowed).
2. **`grep -rn "stock" adm/admin_themes.php adm/admin_plugins.php adm/logic/admin_themes_logic.php`** returns zero matches.
3. Run `php -l` on every modified PHP file.
4. Run `validate_php_file.php` on every modified PHP file.
5. Manual test on `joinerytest.site`:
   - Themes admin page renders correctly; "Preserved on deploy" badge appears only on themes/plugins with `receives_upgrades=false`.
   - "Preserve on deploy" / "Allow upgrade replacement" actions work and round-trip to the manifest.
   - System theme cannot be set to preserved (existing protection still works).
   - Delete modal shows the right warning text for both states.
6. Dry-run an upgrade (`php utils/upgrade.php --dry-run --verbose`) and confirm:
   - Pre-flight active-theme-missing check still works.
   - "Preserved Themes/Plugins" stage runs; the two false-marked themes (`getjoinery`, `scrolldaddy`) are reported as preserved.
   - Stale reconciliation SQL runs without error.
7. Republish (`php plugins/server_manager/includes/publish_upgrade.php`) and confirm:
   - The two false-marked themes are skipped from packaging.
   - Catalog endpoint (`?list=themes`, `?list=plugins`) returns only `included_in_publish=true` entries with the new field name.
8. Run `_reconcile_upgradable_assets.sh` in a container restart and confirm it queries the renamed column without error.
