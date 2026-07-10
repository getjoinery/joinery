<?php
/**
 * v145 — tombstone (no-op).
 *
 * This migration once backfilled the product fulfillment provider columns and
 * dropped pro_evt_event_id. Those columns now belong to the store plugin and
 * are created by plugin sync, which runs AFTER the core migration chain — so a
 * migration must never touch them (it would fail on first upgrade and stall the
 * chain, skipping v146/v147). The backfill + old-column drop moved into the
 * store plugin's activation hook (plugins/store/activate.php), which runs after
 * the plugin's tables exist. This entry stays as a success no-op because v145
 * is already recorded as run on existing installs; renumbering would re-run
 * later migrations. Do not restore logic here.
 */
function generalize_pro_fulfillment() {
    return true;
}
?>
