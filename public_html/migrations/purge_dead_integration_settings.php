<?php
/**
 * Delete settings rows that were never settings.
 *
 * `acuity_api_key` / `acuity_user_id` fed a closed loop: the only code that ever
 * read them was the connection test on the General settings page, which tested a
 * client nothing else called. `urbit_endpoint` / `urbit_endpoint_password` have
 * had no readers at all — the galactictribune theme's Urbit views query Azimuth
 * directly.
 *
 * Also removes the `submit_*` rows the Plugin Settings tab minted: its
 * per-section buttons are named after their plugin, so the reserved-name list's
 * fixed `submit_button` entry never matched them.
 *
 * Nothing reads any of these, and the save path will not recreate them.
 */
function purge_dead_integration_settings() {
	$dblink = DbConnector::get_instance()->get_db_link();

	$doomed = array(
		'acuity_api_key',
		'acuity_user_id',
		'urbit_endpoint',
		'urbit_endpoint_password',
	);

	// The Plugin Settings tab names each section's submit button after its
	// plugin, so `submit_button` in the reserved list never matched them and a
	// save minted a row. Setting::isReservedName() now covers the whole
	// `submit_*` prefix; this removes what got through before it did.
	$submits = $dblink->query("SELECT stg_name FROM stg_settings WHERE stg_name LIKE 'submit\\_%'")
	                  ->fetchAll(PDO::FETCH_COLUMN);
	foreach ($submits as $name) {
		$doomed[] = $name;
	}

	$placeholders = implode(',', array_fill(0, count($doomed), '?'));
	$stmt = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name IN ({$placeholders})");
	$stmt->execute($doomed);

	$count = $stmt->rowCount();
	if ($count === 0) {
		echo "No dead setting rows found — nothing to purge.\n";
		return true;
	}

	echo "Purged {$count} setting row(s) that were never settings:\n";
	foreach ($doomed as $name) {
		echo "  - {$name}\n";
	}

	return true;
}
?>
