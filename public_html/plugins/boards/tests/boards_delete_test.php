<?php
/** @joinery-test
 * name: boards_delete
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Removing a board for good, with the deletion rules live.
 *
 * The test-db copy carries no deletion rules (they are registered in the live
 * database), so boards_model proves the result through the database's own foreign
 * keys. This suite runs against the live dev database, where the rules and the
 * models' permanent_delete() overrides do the work, and cleans up after itself.
 *
 * Run: php tests/run.php db --filter=boards_delete
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/boards_test_support.php');

$suffix = strtoupper(LibraryFunctions::random_string(5));

section('the deletion rules for Boards are registered');
$q = DbConnector::GetPreparedStatement("SELECT COUNT(*) FROM del_deletion_rules WHERE del_source_table = 'bbd_boards'");
$q->execute();
check((int)$q->fetchColumn() >= 6, 'rules exist for the tables under a board');

section('the email columns are cleared, not blocking, when what they point at goes');
foreach (array(
	array('iem_inbound_email_messages', 'bcd_cards', 'bcd_iem_message_id', 'purging the email a card came from leaves the card'),
	array('ied_inbound_email_domains', 'bbd_boards', 'bbd_ied_inbound_email_domain_id', 'removing a mail domain a board chose leaves the board'),
	array('bls_lists', 'bbd_boards', 'bbd_email_bls_list_id', 'removing the list email cards land in leaves the board'),
) as list($source, $target, $column, $label)) {
	$q = DbConnector::GetPreparedStatement('SELECT del_action FROM del_deletion_rules WHERE del_source_table = ? AND del_target_table = ? AND del_target_column = ?');
	$q->execute(array($source, $target, $column));
	check($q->fetchColumn() === 'null', $label);
}

$w = boards_world($suffix);
boards_removal_checks($w, $suffix);

harness_finish();
