<?php
/**
 * Every user's contact-preferences link token becomes random. The token was a
 * digest of the user id and a salt written in the code, so anyone could work out
 * any user's link and change their mailing-list subscriptions (specs
 * declared_permissions B29). A link in mail sent before this stops working and
 * says to sign in instead.
 *
 * Runs once (its database version); a user saved after it already has a random
 * token, and running it again would only mint new ones.
 */
function user_authhash_random() {
	$db = DbConnector::get_instance()->get_db_link();

	$ids = $db->query('SELECT usr_user_id FROM usr_users')->fetchAll(PDO::FETCH_COLUMN);
	$update = $db->prepare('UPDATE usr_users SET usr_authhash = ? WHERE usr_user_id = ?');
	foreach ($ids as $id) {
		$update->execute(array(User::new_authhash(), (int)$id));
	}
	echo '  Gave ' . count($ids) . " user(s) a random contact-preferences link token.\n";
	return true;
}
?>
