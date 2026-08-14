<?php
/**
 * Setup wizard: exempt accounts that predate it (specs/setup_wizard.md).
 *
 * The first-login redirect to /setup fires only for accounts whose first
 * login comes after the wizard shipped. Anyone who has logged in before is
 * seeded as already-dismissed — they see the header pill, never the
 * redirect. A fresh install's owner has never logged in when this runs, so
 * a new site still gets the wizard on first login.
 *
 * Idempotent: only touches rows where the dismissal is still NULL.
 */
function seed_setup_dismissed_for_existing_accounts() {
	$db = DbConnector::get_instance()->get_db_link();

	$has_column = (int)$db->query(
		"SELECT COUNT(*) FROM information_schema.columns
		  WHERE table_schema = 'public'
		    AND table_name = 'usr_users'
		    AND column_name = 'usr_setup_dismissed_time'")->fetchColumn();
	if (!$has_column) {
		echo "  usr_users: no usr_setup_dismissed_time column yet (schema sync pending) — nothing to seed\n";
		return;
	}

	$q = $db->prepare(
		"UPDATE usr_users
		    SET usr_setup_dismissed_time = NOW()
		  WHERE usr_setup_dismissed_time IS NULL
		    AND usr_lastlogin_time IS NOT NULL");
	$q->execute();
	echo $q->rowCount() > 0
		? '  usr_users: exempted ' . $q->rowCount() . " account(s) that predate the setup wizard\n"
		: "  usr_users: no accounts to exempt (nobody has logged in yet)\n";
}
?>
