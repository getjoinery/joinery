<?php
/**
 * Server Manager plugin bootstrap - the plugin's declared load point (the
 * top-level `bootstrap` key in plugin.json), loaded once per request by the
 * plugin bootstrap loader (PluginBootstraps) whenever the plugin is active.
 * Registrations only: no request work, no output, nothing that assumes a
 * signed-in user.
 *
 * Registers the four admin-header notices that read what the fleet has said
 * (FleetAttentionNotice): a node whose latest host report names a failed
 * unit, a node whose agent says a recipe's check is failing, a node with an
 * open case nobody has marked read, and a node whose last scheduled backup
 * failed. All read stored facts and never probe (specs/agent_tier1_recipes.md,
 * WP3 and slice 7; specs/disk_headroom_and_unit_diagnosis.md §3).
 *
 * @version 1.2 - fleet_failed_backups
 * @version 1.1 - fleet_failing_recipes
 * @version 1.0
 */

AdminNotices::register('fleet_failed_units', array('FleetAttentionNotice', 'render_failed_units'));
AdminNotices::register('fleet_failing_recipes', array('FleetAttentionNotice', 'render_failing_recipes'));
AdminNotices::register('fleet_open_cases', array('FleetAttentionNotice', 'render_open_cases'));
AdminNotices::register('fleet_failed_backups', array('FleetAttentionNotice', 'render_failed_backups'));
?>
