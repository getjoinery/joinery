<?php
/**
 * Server Manager plugin bootstrap - the plugin's declared load point (the
 * top-level `bootstrap` key in plugin.json), loaded once per request by the
 * plugin bootstrap loader (PluginBootstraps) whenever the plugin is active.
 * Registrations only: no request work, no output, nothing that assumes a
 * signed-in user.
 *
 * Registers the one admin-header line that says what needs a person
 * (IncidentNotice: how many incidents need you, and new problem reports from
 * sites), the same count beside the Incidents menu entry (AdminMenuCounts),
 * and the conditions this management node watches on every node as incident
 * sources (IncidentSources), which the Reconcile Incidents task turns into
 * incidents (incident_triage.md). All read stored facts and never probe.
 *
 * @version 1.21 - plane:two_layers (a machine under our encryption and its provider's both, management nodes aside;
 *                 specs/site_copy_final_test.md E4)
 * @version 1.20 - plane:site_refresh: a host's nightly replacement of its site containers left a site down, was
 *                rolled back, could not build its base, or has not checked for fixes in two days (IncidentSourceSiteRefresh)
 * @version 1.19 - plane:kernel_warning (a shared host's kernel logged a warning) and plane:kernel_settings (a pinned
 *                kernel setting does not hold), specs/multi_tenant_host_hardening.md D7, D1
 * @version 1.18 - plane:data_root_stopped: a data root stopped by hand (reviewer2 B17 D2)
 * @version 1.17 - plane:cloud_account_key: an encrypted machine's cloud account key does not work, or expires soon (B77)
 * @version 1.16 - plane:site_code_unverified (specs/host_restrictions.md D8)
 * @version 1.15 - plane:mail_gateway (IncidentSourceMailGateway)
 * @version 1.14 - plane:data_root_locked (specs/hosted_data_promise_disk_encryption.md D2b)
 * @version 1.13 - plane:customer_backups (specs/storage_targets.md F1, F2)
 * @version 1.12 - plane:agent_bundle_refused (spec release_transparency, WP7 review)
 * @version 1.11 - plane:release_log_entry and plane:release_log_tail_blind (spec release_transparency, O5)
 * @version 1.10 - plane:agent_update_refused (spec release_transparency, O7)
 * @version 1.9 - plane:test_cloud_cleanup (specs/test_cloud_account_and_prod_management.md WP3)
 * @version 1.8 - plane:release_log and plane:release_log_blind (spec release_transparency, O6)
 * @version 1.7 - plane:machine_transfer (specs/node_outbound_and_transfer.md WP1)
 * @version 1.6 - incident analysis registers its token spend with joinery_ai's CostGuard
 * @version 1.5 - every plane source (backups three ways, failed units, certificates, agent silent,
 *                unmanageable, monitoring broken); the fleet_failed_units, fleet_failing_recipes and
 *                fleet_failed_backups notices are gone, their conditions now incidents
 * @version 1.4 - the incident sources this management node watches: plane:site_down
 * @version 1.3 - fleet_incidents replaces fleet_open_cases; the Incidents menu entry counts what needs a person
 * @version 1.2 - fleet_failed_backups
 * @version 1.1 - fleet_failing_recipes
 * @version 1.0
 */

AdminNotices::register('fleet_incidents', array('IncidentNotice', 'render'));
AdminMenuCounts::register('server-manager-incidents', array('IncidentNotice', 'menu_count'));
foreach (array('IncidentSourceSiteDown', 'IncidentSourceBackupFailed', 'IncidentSourceBackupsStopped', 'IncidentSourceBackupUnverified',
	'IncidentSourceFailedUnits', 'IncidentSourceCertificate', 'IncidentSourceAgentSilent', 'IncidentSourceUnmanageable',
	'IncidentSourceMonitoringBroken', 'IncidentSourceMachineTransfer', 'IncidentSourceReleaseLog', 'IncidentSourceReleaseLogBlind',
	'IncidentSourceTestCloudCleanup', 'IncidentSourceAgentUpdateRefused', 'IncidentSourceAgentBundleRefused',
	'IncidentSourceReleaseLogEntry', 'IncidentSourceReleaseLogTailBlind', 'IncidentSourceCustomerBackups',
	'IncidentSourceDataRootLocked', 'IncidentSourceMailGateway', 'IncidentSourceSiteCodeUnverified',
	'IncidentSourceCloudAccountKey', 'IncidentSourceDataRootStopped', 'IncidentSourceKernelWarning',
	'IncidentSourceKernelSettings', 'IncidentSourceSiteRefresh', 'IncidentSourceTwoLayers') as $incident_source) {
	IncidentSources::register(new $incident_source());
}
// Incident analysis spends model tokens through joinery_ai; they count toward
// its monthly ceiling.
if (class_exists('CostGuard') && method_exists('CostGuard', 'registerUsageCounter')) {
	CostGuard::registerUsageCounter('incident_analysis', array('IncidentAnalyst', 'usage_since'));
}
?>
