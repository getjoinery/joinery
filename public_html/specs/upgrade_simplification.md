# Upgrade System Simplification (In-Place)

## Problem

`utils/upgrade.php` is 1,985 lines. The apply flow is procedural and readable enough to follow, but the file is cluttered with:

- A dead legacy fallback path (for source servers that predate the published-archives manifest)
- An 80-line self-update marker file system that does what a 5-line VERSION check could do
- A 20-line permission bitfield decoder that `is_writable()` replaces
- ~6 copies of the "show red div / `rm -rf` staging / `exit(1)`" pattern
- ~20 CLI/web ternary HTML branches that emit the same content with different markup
- A dry-run mode no one uses, with ~8 inline `$dry_run` checks and ~150 lines of dry-run-specific output and cleanup branches
- A "refresh archives and apply" feature that overlaps with normal upgrade publishing — a separate UI button, a CLI flag, an AJAX handler, and a remote-call helper, all to do something that just republishing accomplishes more clearly

A full restructure into three files plus a runner class is the cleanest fix, but the rewrite risk on the deploy path is high and the in-place cleanup gets most of the readability win without touching the apply flow's shape.

## Goal

Surgical clean-up. No new files, no new classes, no restructuring into named steps. Just delete dead code, collapse duplication, and extract a few small helpers. After this, the file should be easier to read and ~550 lines shorter; the apply flow remains procedural and largely unchanged.

---

## The Seven Cleanups

### 1. Delete the legacy (no-manifest) fallback path

The discovery section in the apply body has two code paths: the manifest-driven path (used by every live source) and a legacy fallback for sources that predate the manifest. The fallback is dead — every active source has shipped the manifest for months — and any source old enough to lack it is too old to be a valid upgrade source.

**Removes:**

- The `if ($source_published_themes !== null && $source_published_plugins !== null)` branch and its `else` (lines ~460–486)
- `get_themes_to_upgrade()` (lines ~1636–1648)
- `get_installed_plugins()` (lines ~1734–1749)
- The `$theme_endpoint` URL construction and the `?? ($theme_endpoint . '?download=...')` fallbacks in the download loop
- The `$source_published_themes !== null` guards around stale reconciliation

After: discovery is one block. `themes_to_download` and `plugins_to_download` are computed unconditionally from `(get_upgradable + manifest) ∪ required`. Both lookup maps (`theme_url_by_name`, `plugin_url_by_name`) are populated from the manifest with no fallback.

**Estimated lines removed:** ~80

### 2. Kill the self-update marker file

The current self-update flow writes a JSON marker file to staging when deployment files change, then on re-run validates marker age (24h), version match, staging non-empty, and corrupt-JSON handling — all to detect "are we resuming after a self-update?"

**Replace with:** check `staging/VERSION` against the source's reported version. If they match and staging is non-empty, we're resuming — skip download, proceed.

```php
$resuming = false;
$staged_version_file = $stage_directory . '/VERSION';
if (file_exists($staged_version_file) && is_dir($stage_directory) && !is_dir_empty($stage_directory)) {
    $staged_version = trim(file_get_contents($staged_version_file));
    if ($staged_version === $decode_response['system_version']) {
        $resuming = true;
    }
}
```

That's the whole resume detection. The VERSION file is already written into every tarball by `publish_upgrade.php`, so no source-side change is needed. False-positives (partial extraction leaves staging populated but VERSION missing, or VERSION present but version mismatch) fall through to a fresh download — same behavior as marker-mismatch today.

**Removes:**

- Marker file write block (~15 lines, currently inside the self-update success branch)
- Marker file read/validate/age-check/cleanup block (lines ~403–449)
- Cleanup of stale marker file in error paths

**Estimated lines removed:** ~60

### 3. Replace the permission bitfield with `is_writable()`

Lines 115–139 decode `fileperms()` into rwx triplets to check that `$live_directory` is user-writable. PHP has `is_writable($live_directory)` for exactly this. The same block also has a separate `www-data` ownership check with a `debug=true` bypass (lines ~224–236) — `is_writable()` already covers both, since whatever user runs PHP needs to write the directory regardless of who owns it.

**Replace with:**

```php
if (!is_writable($live_directory)) {
    abort('Live directory is not writable: ' . $live_directory);
}
```

**Removes:** ~30 lines (bitfield decode + ownership branch + debug bypass).

### 4. Extract an `abort($title, $detail = '')` helper

The validation-failure pattern appears at least 6 times:

```php
echo '<div style="border: 2px solid #dc3545; padding: 15px; ...">';
echo '<strong>X Failed:</strong> ' . htmlspecialchars($detail) . '<br>';
echo '</div>';
exec("rm -rf " . escapeshellarg($stage_location) . "/*");
exit(1);
```

Replace with:

```php
function abort($title, $detail = '', $clear_staging = true) {
    global $is_cli, $stage_location;
    if ($is_cli) {
        echo "ERROR: $title\n";
        if ($detail) echo "  $detail\n";
    } else {
        echo '<div class="alert alert-danger"><strong>' . htmlspecialchars($title) . ':</strong> '
           . htmlspecialchars($detail) . '</div>';
    }
    if ($clear_staging && $stage_location && is_dir($stage_location)) {
        exec("rm -rf " . escapeshellarg($stage_location) . "/*");
    }
    exit(1);
}
```

Call sites (tarball validation, PHP syntax, plugin loading, bootstrap, active theme missing, preserve failure, deploy failure, etc.) collapse to one line each.

**Estimated lines removed:** ~40 (six 6-line blocks → six 1-line calls).

### 5. Extract `out_alert($level, $msg)` and `out_step($title)` helpers

The `if ($is_cli) { echo "..." } else { echo "<div ...>" }` ternary appears ~20 times for non-fatal output: progress headers, success rows, warnings, info banners.

```php
function out_step($title) {
    global $is_cli;
    echo $is_cli ? "\n=== " . strtoupper($title) . " ===\n"
                 : '<br><h3>' . htmlspecialchars($title) . '</h3>';
}

function out_alert($level, $msg) {
    // $level: 'success', 'warning', 'info'
    global $is_cli;
    $prefix = ['success' => '[OK] ', 'warning' => '[WARN] ', 'info' => '[INFO] '][$level];
    if ($is_cli) {
        echo $prefix . $msg . "\n";
    } else {
        $colors = [
            'success' => '#155724;background:#d4edda;border-color:#c3e6cb',
            'warning' => '#856404;background:#fff3cd;border-color:#ffeeba',
            'info'    => '#0c5460;background:#d1ecf1;border-color:#bee5eb',
        ];
        echo '<div style="padding:10px;margin:10px 0;border:1px solid;color:' . $colors[$level] . '">' . $msg . '</div>';
    }
}
```

Call sites switch from 5–10-line if/else blocks to one-line calls. The styled-div HTML stops being copy-pasted with slight variations (current code has at least three different shades of green for success).

**Estimated lines removed:** ~80

### 6. Remove dry-run mode entirely

Dry-run downloads, extracts, and validates the upgrade but skips the mv-swap, composer, migration, sync, and cleanup. The promise is "see if it would work without committing," but the real upgrade already runs every validation BEFORE the mv-swap and aborts cleanly if any fail. Dry-run's only unique behavior is leaving staged files in place for inspection, which any failed real run already does.

**Removes:**

- `--dry-run` CLI flag and `?dry-run=1` web parameter parsing
- The "DRY RUN MODE" banner (CLI box + web blue div)
- The "Dry Run Mode" checkbox on the web UI form (and its label)
- Eight `if ($dry_run)` branches in the apply body (around deploy, composer, opcache, migration, staging cleanup, summary)
- The "Dry Run Complete!" success page
- The dry-run summary block at the end

**Estimated lines removed:** ~150

After this, the apply flow has no awareness of dry-run. Validation runs always; if it fails, the upgrade aborts before any destructive action.

### 7. Remove "refresh archives and apply" entirely (cross-file)

The refresh-archives feature lets a target ask its source to regenerate published tarballs without a version bump, then apply them. It's surfaced four ways: an upgrade.php web button (refresh-only), an upgrade.php `--refresh-archives` CLI flag (refresh-and-apply), a Server Manager dashboard "Refresh & Apply" button (queues a remote `refresh_archives` job), and the job system itself. The mechanism overlaps with normal publishing, requires its own auth path (an IP whitelist + a feature toggle, separate from session auth), and is confusing in practice — three of the four entry points behave differently from each other.

After removal, the only path to ship file changes from a source is to republish: `php plugins/server_manager/includes/publish_upgrade.php "notes"`. One pathway, no second auth surface, no UI duplication.

**Removes — target side (`utils/upgrade.php`):**

- `--refresh-archives` CLI option from `getopt()` (line ~35)
- `$do_refresh` handler block (lines ~146–164)
- "Server Archive Management" fieldset on the web UI form (lines ~1534–1543)
- `refreshServerArchives()` JavaScript (lines ~1567–1607)
- `request_archive_refresh()` PHP helper (lines ~1942–1983)

**Removes — source side (`plugins/server_manager/includes/publish_upgrade.php`):**

- `?refresh-archives=1` request handler block (lines ~84–150)
- `is_ip_in_list()` helper (line ~823)
- `regenerate_current_archives()` function (line ~873)

**Removes — Server Manager dashboard:**

- "Refresh & Apply" action button + handler in `views/admin/node_detail.php` (lines ~236–238, ~1349, ~1410)
- `refresh_archives` from job-type filter dropdowns in `views/admin/jobs.php` and `views/admin/index.php`
- `JobCommandBuilder::build_refresh_archives()`
- `JobResultProcessor::process_refresh_archives()`

**Removes — settings:**

- `allow_remote_archive_refresh` from `settings.json`
- `archive_refresh_allowed_ips` from `settings.json`
- Any field rendering for the two settings in the admin settings UI

**Removes — docs:**

- `CLAUDE.md` lines ~494–496 ("Small fixes (no version bump)")
- `docs/server_manager.md` line ~184 (`refresh_archives` job-type table row)
- `docs/deploy_and_upgrade.md` lines ~148, ~164–166, ~181

**Notes:**

- **Existing `stg_settings` rows** for the two removed settings will linger on every deployed site once code stops reading them. They're harmless. Recommendation: leave them, don't write a delete migration. (Migration adds rollout risk for zero functional gain.)
- **Existing `refresh_archives` job history** in `mjb_management_jobs` stays in place. Old job rows remain visible in lists that don't filter by type; the filter dropdown just no longer offers the option.

**Estimated lines removed:** ~360 across all files (~110 in `upgrade.php`, ~150 in `publish_upgrade.php`, ~80 across Server Manager + settings + docs).

---

## What Gets Removed (Summary)

| Cleanup | Scope | Lines |
|---|---|---|
| 1. Legacy fallback path | upgrade.php | ~80 |
| 2. Self-update marker file | upgrade.php | ~60 |
| 3. Permission bitfield + ownership check | upgrade.php | ~30 |
| 4. `abort()` helper extraction | upgrade.php | ~40 |
| 5. `out_alert` / `out_step` helpers | upgrade.php | ~80 |
| 6. Dry-run mode | upgrade.php | ~150 |
| 7. Refresh-archives feature | cross-file | ~360 |
| **Total** | | **~800** |

`upgrade.php` size: 1,985 → ~1,435 lines (cleanups 1–6 + the upgrade.php-side of #7).
Remaining ~250 lines come out of `publish_upgrade.php`, the Server Manager dashboard, settings, and docs.

---

## What Stays the Same

- Three-purpose file structure (serve / apply / web UI all in one)
- Procedural apply flow (no runner class, no named steps)
- The serve response JSON shape and field names
- All validation steps (tarball, PHP syntax, plugin loading, bootstrap, active theme)
- The deploy swap sequence (mv live→backup, mv staging→live)
- Rollback on migration failure
- ThemeManager / PluginManager sync
- Stale reconciliation
- CLI flags: `--verbose`, `--force-upgrade`, `--confirm-downgrade`
- Self-update behavior (deployment files copied to live, user asked to re-run) — only the marker mechanism changes

---

## What Stays Messy

Honest about the tradeoffs:

- Output is still interleaved with logic throughout the apply body
- The apply body is still a ~1,000-line procedural script
- HTML and CLI output paths still both exist; helpers reduce duplication but don't eliminate the dual-format burden
- The download loop, validation block, and deploy block are still inline — not named methods on a runner

These are what the full refactor was for. The in-place cleanup doesn't fix them; it just makes the file ~22% shorter and removes the worst patterns.

---

## Bug Fix Included

The current self-update logic compares MD5 of staged vs. live deployment files **and** filters out files where staged `filemtime` is older than live (lines ~661–676). This guard was added to prevent stale tarballs from downgrading deployment tools, but it's misplaced — a stale tarball should fail at the manifest-version check or VERSION-file check, not silently skip self-update. With cleanup #2 (VERSION-file resume detection), the manifest is already authoritative for "is this tarball current," so the filemtime guard becomes redundant. Drop it as part of cleanup #2.

---

## Order of Operations

Each cleanup is independent and can be tested in isolation. Suggested order:

1. **Cleanup 7 (refresh-archives removal)** — biggest cross-file removal but all of it is pure deletion. Do it first so the form, settings UI, dashboard, and docs are simpler before later changes touch them. Suggest landing as one commit per file group: target side, source side, dashboard, settings, docs.
2. **Cleanup 6 (dry-run removal)** — biggest single in-file reduction, touches many spots, easier when done before the helpers land
3. **Cleanup 1 (legacy fallback)** — pure deletion, lowest risk
4. **Cleanup 3 (`is_writable`)** — pure deletion, lowest risk
5. **Cleanup 4 (`abort` helper)** — extraction, validate by running an upgrade with a deliberate bad tarball
6. **Cleanup 5 (`out_alert` / `out_step`)** — extraction, validate visually
7. **Cleanup 2 (marker file)** — slight behavioral change, validate with a self-update scenario

Each step should keep upgrade.php fully working; commits are bite-sized.
