# Upgrade: Graceful handling of missing theme/plugin archives

**Created:** 2026-04-12
**Status:** Active

## Problem

When `upgrade.php` downloads individual theme and plugin archives, a 404 on any single theme aborts the entire upgrade. This means a deprecated or locally-deleted theme that no longer exists on the upgrade server blocks core updates, database migrations, and all other theme/plugin updates.

This happened in practice: deprecated themes like `phillyzouk`, `galactictribune`, `jeremytunnell` (replaced by `-html5` versions) were still installed on remote nodes. The upgrade server no longer publishes archives for them, so the download failed with a 404 and the upgrade aborted.

## Expected Behavior

A missing theme or plugin archive should **warn and skip**, not abort. The upgrade should:

1. Log a warning: "Theme 'galactictribune' not available from upgrade server — skipping"
2. Continue downloading remaining themes and plugins
3. Apply the core upgrade and all successfully downloaded themes/plugins
4. Show a summary at the end listing what was skipped

The core upgrade is the important part. Theme/plugin updates are supplementary — a missing archive for one theme should never block the entire deployment.

## Current Behavior

In `upgrade.php`, the theme/plugin download section calls the upgrade server's `publish_theme` endpoint. When the response is HTTP 404, the code outputs a red error banner and exits (or stops processing further downloads, which has the same effect since the staged archive is incomplete).

## Fix

In the download loop for individual themes and plugins in `upgrade.php`:

- On 404: log a warning, add the item to a `$skipped_items` array, continue to the next item
- On other HTTP errors (500, timeout): same treatment — warn and skip, don't abort
- After the download loop: if `$skipped_items` is not empty, show a yellow warning banner listing what was skipped
- Proceed with the upgrade using whatever was successfully downloaded

The skipped themes/plugins simply don't get updated in this upgrade cycle — their existing local copies remain untouched.

## Additional Improvement

Deprecated themes that are installed locally but no longer published should be flagged during the upgrade summary. The warning could suggest: "These themes are deprecated and no longer maintained. Consider removing them if not in use."

## Files

| File | Action |
|------|--------|
| `utils/upgrade.php` | MODIFY — change theme/plugin download error handling from abort to warn-and-skip |

## Verification

1. Install a theme that doesn't exist on the upgrade server (e.g., create a dummy `theme/nonexistent/theme.json`)
2. Run an upgrade — verify it warns about the missing theme but completes successfully
3. Verify all other themes and the core upgrade were applied
4. Remove the dummy theme and verify a clean upgrade works normally
