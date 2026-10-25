<?php
/**
 * The seeded accounts sign in, and are known, by username instead of a made-up
 * address (spec optional_username). An account may now have no email, so:
 *
 *   - the system user (User::USER_SYSTEM) becomes the username system and the
 *     deleted-user row (User::USER_DELETED) the username deleted-user, each
 *     with its placeholder address cleared. Found by id, never by address:
 *     sites seeded them under different placeholders. A row at that id that
 *     is not the placeholder (a site installed before the seed, whose first
 *     members took ids 2 and 3) is left alone (seeded_accounts_is_placeholder()).
 *   - the seeded admin, user 1, when it still has exactly admin@example.com,
 *     becomes the username admin and loses that address, which nobody can
 *     receive at (and which passed the mail-domain check only by accident).
 *     It signs in as admin from now on; its password is unchanged. The
 *     install's config/admin_credentials.txt, while it is still there, is
 *     rewritten to name the username (seeded_accounts_rewrite_credentials()).
 *
 * A username another account already holds is left alone and said, with the
 * address kept, so no account is ever left with neither.
 *
 * And a blank address stored as '' becomes NULL, as every save now stores it:
 * the email's unique index ignores NULL, not '', so two '' rows would keep the
 * index from ever being made. update_database makes indexes before it runs
 * migrations and tries a skipped unique index again after them, so such a
 * site gets the index in the same run.
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
		array(User::USER_SYSTEM, 'system', null, 'System'),
		array(User::USER_DELETED, 'deleted-user', null, 'Deleted'),
		array(1, 'admin', 'admin@example.com', null),
	);
	$read = $db->prepare('SELECT usr_user_id, usr_email, usr_username, usr_first_name, usr_last_name FROM usr_users WHERE usr_user_id = ?');
	$taken = $db->prepare('SELECT usr_user_id FROM usr_users WHERE LOWER(usr_username) = LOWER(?) AND usr_user_id <> ?');
	$update = $db->prepare('UPDATE usr_users SET usr_username = ?, usr_email = NULL WHERE usr_user_id = ?');

	foreach ($rows as list($id, $username, $only_if_email, $placeholder_first)) {
		$read->execute(array($id));
		$row = $read->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			continue;
		}
		if ((string)$row['usr_username'] !== '') {
			echo "  user $id already has a username ({$row['usr_username']})\n";
			// A run before the credentials rewrite existed (dev) gave the admin
			// its username but left the file naming the old address.
			if ($only_if_email !== null && strcasecmp((string)$row['usr_username'], $username) === 0) {
				echo '  ' . seeded_accounts_rewrite_credentials(PathHelper::getSiteRoot() . '/config/admin_credentials.txt', $only_if_email, $username) . "\n";
			}
			continue;
		}
		if ($only_if_email !== null && strtolower((string)$row['usr_email']) !== $only_if_email) {
			continue;
		}
		if ($placeholder_first !== null && !seeded_accounts_is_placeholder($row, $placeholder_first, $username)) {
			echo "  user $id is a person's account, not the $username placeholder; left as it was\n";
			continue;
		}
		$taken->execute(array($username, $id));
		if ($holder = $taken->fetchColumn()) {
			echo "  user $id: username $username is already user $holder's; left as it was\n";
			continue;
		}
		$update->execute(array($username, $id));
		echo "  user $id is now the username $username, with no email\n";
		if ($only_if_email !== null) {
			echo '  ' . seeded_accounts_rewrite_credentials(PathHelper::getSiteRoot() . '/config/admin_credentials.txt', $only_if_email, $username) . "\n";
		}
	}
	return true;
}

/**
 * Whether row 2 or 3 is the seeded placeholder. Installs before 2026-01 seeded
 * only the admin, so on an older site ids 2 and 3 can be its first members,
 * whose addresses must never be cleared. The placeholder is named '<First>
 * User' or has the '<username>@' address every site seeded it under.
 */
function seeded_accounts_is_placeholder(array $row, string $first, string $username): bool {
	if (strcasecmp(trim((string)$row['usr_first_name']), $first) === 0
		&& strcasecmp(trim((string)$row['usr_last_name']), 'User') === 0) {
		return true;
	}
	$local = strtolower(strstr((string)$row['usr_email'], '@', true) ?: '');
	return $local === ($username === 'system' ? 'system-user' : $username);
}

/**
 * The install's credentials file, while it is still there, tells the operator
 * to sign in with the address the seeded admin just lost. Name the username in
 * its place. The file is root's (600); a run that cannot write it, the admin
 * page's update_database as the web user, says what to sign in with instead.
 * Returns the line to print.
 */
function seeded_accounts_rewrite_credentials(string $path, string $email, string $username): string {
	if (!is_file($path)) {
		return "sign in as $username (no credentials file to update)";
	}
	$text = @file_get_contents($path);
	$pattern = '/^Email:[ \t]*' . preg_quote($email, '/') . '[ \t]*$/mi';
	if ($text === false || !preg_match($pattern, $text)) {
		return "sign in as $username" . ($text === false ? '; config/admin_credentials.txt could not be read, and may still name ' . $email : '');
	}
	$text = preg_replace('/^Username:.*\R/mi', '', $text);
	$text = preg_replace($pattern, 'Username: ' . $username, $text);
	if (!is_writable($path) || @file_put_contents($path, $text) === false) {
		return "sign in as $username; config/admin_credentials.txt still names $email (not writable here)";
	}
	return "config/admin_credentials.txt now names the username $username";
}
?>
