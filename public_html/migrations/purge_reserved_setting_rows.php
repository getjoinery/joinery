<?php
/**
 * Delete settings rows that were never settings.
 *
 * The settings pages save by walking the submitted POST, so the fields the form
 * machinery contributes — a CSRF token, a submit button, captcha responses, the
 * routing parameter — and the General page's readonly path mirrors were all
 * stored as settings rows and re-written on every save. The save path now
 * refuses these names; this removes what accumulated before it did.
 *
 * The list is not repeated here: the rows to delete are exactly the stored names
 * Setting::isReservedName() rejects, so the migration and the save path can
 * never disagree. Nothing reads these rows.
 */
function purge_reserved_setting_rows() {
	require_once(PathHelper::getIncludePath('data/settings_class.php'));

	$dblink = DbConnector::get_instance()->get_db_link();

	$names = $dblink->query("SELECT stg_name FROM stg_settings ORDER BY stg_name")
	                ->fetchAll(PDO::FETCH_COLUMN);

	$doomed = array();
	foreach ($names as $name) {
		if (Setting::isReservedName($name)) {
			$doomed[] = $name;
		}
	}

	if (empty($doomed)) {
		echo "No reserved setting rows found — nothing to purge.\n";
		return true;
	}

	$placeholders = implode(',', array_fill(0, count($doomed), '?'));
	$stmt = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name IN ({$placeholders})");
	$stmt->execute($doomed);

	echo "Purged " . $stmt->rowCount() . " setting row(s) that were form or request plumbing:\n";
	foreach ($doomed as $name) {
		echo "  - {$name}\n";
	}

	return true;
}
?>
