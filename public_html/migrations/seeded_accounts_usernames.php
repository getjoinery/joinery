<?php
/**
 * The seeded accounts sign in, and are known, by username instead of a made-up
 * address (spec optional_username). An account may now have no email, so:
 *
 *   - the system user (User::USER_SYSTEM) becomes the username system and the
 *     deleted-user row (User::USER_DELETED) the username deleted-user, each
 *     with its placeholder address cleared. Found by id, never by address:
 *     sites seeded them under different placeholders.
 *   - the seeded admin, user 1, when it still has exactly admin@example.com,
 *     becomes the username admin and loses that address, which nobody can
 *     receive at (and which passed the mail-domain check only by accident).
 *     It signs in as admin from now on; its password is unchanged.
 *
 * A username another account already holds is left alone and said, with the
 * address kept, so no account is ever left with neither.
 *
 * And a blank address stored as '' becomes NULL, as every save now stores it:
 * the email's unique index ignores NULL, not '', so two '' rows would keep the
 * index from ever being made. update_database makes indexes before it runs
 * migrations, so such a site gets the index on its next run.
 *
 * Idempotent: a row already carrying its username is skipped.
 */
function seeded_accounts_usernames() {
	$db = DbConnector::get_instance()->get_db_link();

	$blank = $db->exec("UPDATE usr_users SET usr_email = NULL WHERE usr_email IS NOT NULL AND TRIM(usr_email) = ''");
	if ($blank) {
		echo "  $blank blank address(es) stored as NULL\n";
	}

	$rows = array(
		array(User::USER_SYSTEM, 'system', null),
		array(User::USER_DELETED, 'deleted-user', null),
		array(1, 'admin', 'admin@example.com'),
	);
	$read = $db->prepare('SELECT usr_user_id, usr_email, usr_username FROM usr_users WHERE usr_user_id = ?');
	$taken = $db->prepare('SELECT usr_user_id FROM usr_users WHERE LOWER(usr_username) = LOWER(?) AND usr_user_id <> ?');
	$update = $db->prepare('UPDATE usr_users SET usr_username = ?, usr_email = NULL WHERE usr_user_id = ?');

	foreach ($rows as list($id, $username, $only_if_email)) {
		$read->execute(array($id));
		$row = $read->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			continue;
		}
		if ((string)$row['usr_username'] !== '') {
			echo "  user $id already has a username ({$row['usr_username']})\n";
			continue;
		}
		if ($only_if_email !== null && strtolower((string)$row['usr_email']) !== $only_if_email) {
			continue;
		}
		$taken->execute(array($username, $id));
		if ($holder = $taken->fetchColumn()) {
			echo "  user $id: username $username is already user $holder's; left as it was\n";
			continue;
		}
		$update->execute(array($username, $id));
		echo "  user $id is now the username $username, with no email\n";
	}
	return true;
}
?>
