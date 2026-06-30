<?php
/**
 * Collapse the per-store offload task pairs into the single CloudOffloadRun tick.
 *
 * The forward/reverse task pairs (public files, private files, inbound-mail raw)
 * are replaced by one platform task driven by each store's mode. This migration:
 *
 *   1. Carries a mid-drain forward: if an old reverse task was active, set the
 *      matching store's draining flag so the new tick finishes the pull-back.
 *   2. Removes the six obsolete task rows (their class files are gone; the runner
 *      would otherwise carry dead rows).
 *   3. Activates CloudOffloadRun when any store is enabled or still draining, so
 *      offload continues without an admin having to re-save.
 *
 * Idempotent: re-running after cleanup is a no-op (the test gate skips it once the
 * obsolete rows are gone).
 */
function migrate_offload_single_task() {
	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
	require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

	$db = DbConnector::get_instance()->get_db_link();
	$settings = Globalvars::get_instance();

	$active_in = function(array $classes) use ($db) {
		$in = implode(',', array_fill(0, count($classes), '?'));
		$q = $db->prepare("SELECT COUNT(*) FROM sct_scheduled_tasks WHERE sct_is_active = true AND sct_task_class IN ($in)");
		$q->execute($classes);
		return (int)$q->fetchColumn() > 0;
	};

	// 1. Carry a mid-drain forward via the store's draining flag (upsert so it
	//    works whether or not settings.json has been seeded yet this run).
	$set_flag = function($name) use ($db) {
		$q = $db->prepare("INSERT INTO stg_settings (stg_name, stg_value) VALUES (?, '1')
			ON CONFLICT (stg_name) DO UPDATE SET stg_value = '1'");
		$q->execute([$name]);
	};
	$pub_draining  = $active_in(['CloudStorageReverseSync']);
	$priv_draining = $active_in(['FilePrivateCloudReverseSync', 'PullInboundRawBackToLocal']);
	if ($pub_draining) {
		$set_flag('cloud_storage_draining');
		echo "  Public store was mid-drain; set cloud_storage_draining=1.\n";
	}
	if ($priv_draining) {
		$set_flag('cloud_storage_private_draining');
		echo "  Private store was mid-drain; set cloud_storage_private_draining=1.\n";
	}

	// 2. Remove the six obsolete task rows.
	$obsolete = ['CloudStorageSync', 'CloudStorageReverseSync',
		'FilePrivateCloudSync', 'FilePrivateCloudReverseSync',
		'OffloadInboundRawToCloud', 'PullInboundRawBackToLocal'];
	$in = implode(',', array_fill(0, count($obsolete), '?'));
	$del = $db->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_task_class IN ($in)");
	$del->execute($obsolete);
	echo "  Removed " . $del->rowCount() . " obsolete offload task row(s).\n";

	// 3. Activate the single tick if any store is enabled or draining. (Use the
	//    drain signals just computed, not the settings singleton, which won't yet
	//    reflect the flags written above this run.)
	$needs_tick =
		$settings->get_setting('cloud_storage_enabled')
		|| $settings->get_setting('cloud_storage_private_enabled')
		|| $pub_draining
		|| $priv_draining;
	if ($needs_tick) {
		CloudStorageLifecycle::ensureTickActive();
		echo "  A store is in use; CloudOffloadRun activated.\n";
	} else {
		echo "  No store enabled or draining; CloudOffloadRun left inactive.\n";
	}

	return true;
}
?>
