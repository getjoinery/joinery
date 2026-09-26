<?php
/** @joinery-test
 * name: accept_browser_sealed_fields
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * SystemBase::acceptBrowserSealedFields() — the door a browser adds a field
 * through, under a row's EXISTING key (specs/fortress_mail_device_ai.md § R4).
 * Driven through the mail model, whose allow-list is the AI verdicts, on a
 * real Fortress message delivered through the MTA path.
 *
 * Pins: an allow-listed field lands and opens under the row's own key and AD;
 * the row's key and key generation do not move; refused — a field off the
 * allow-list (a body in particular, which would be a rewrite of the received
 * message), a column that is not sealed at all, plaintext, a row that is not
 * sealed, a row whose key is sealed to a server-custody scope
 * (`v1.edgeseal.user.`, a lowered row), a row still waiting for its browser
 * parse; and a verdict added while the mail key is being rotated opens after
 * the rotation moves the row's key to the new generation. Who may post is the
 * caller's check, pinned in plugins/mailbox/tests/fortress_device_ai_test.php.
 *
 * Run: php tests/run.php test-db --filter=accept_browser_sealed_fields
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!class_exists('InboundEmailMessage')) {
	harness_skip('the mailbox plugin is not active, and this suite drives the door through its model');
	harness_finish();
}
harness_test_mode();
require_once(__DIR__ . '/../../plugins/mailbox/tests/lib/fortress_fixture.php');

/** Runs $fn; the refusal message, or null when it did not throw. */
function absf_refusal(callable $fn): ?string {
	try { $fn(); } catch (RuntimeException $e) { return $e->getMessage(); }
	return null;
}

try {
	$db = DbConnector::get_instance()->get_db_link();
	$crypto = new VaultCrypto();
	$fx = fortress_fixture('AppendFields');
	$mid = fortress_ingest($fx);
	$row = fortress_row($mid);
	check($mid > 0 && strncmp((string)$row['iem_sealed_key'], 'v1.edgeseal.mail.', 17) === 0, 'a Fortress message is stored under the mail key');
	$dek = fortress_open_dek($fx, (string)$row['iem_sealed_key']);
	$seal = function (string $field, string $plain, int $id = 0) use ($crypto, $dek, $mid) {
		return $crypto->sealFieldForBrowser($plain, $dek, 'mail:' . ($id ?: $mid) . ':' . $field);
	};
	$set = function (string $sql, array $params) use ($db) { $db->prepare($sql)->execute($params); };

	// -------------------------------------------------------------------------
	section('An allow-listed field lands under the row\'s own key');

	$summary = $seal('iem_ai_summary', 'Order confirmation with two attachments.');
	check(absf_refusal(function () use ($mid, $summary) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_ai_summary' => $summary));
	}) === null, 'the summary is accepted');
	$after = fortress_row($mid);
	check($after['iem_ai_summary'] === $summary, 'stored as sent');
	check(fortress_open_field($fx, $after['iem_ai_summary'], $dek, 'mail:' . $mid . ':iem_ai_summary')
		=== 'Order confirmation with two attachments.', 'and it opens with the row\'s DEK under its own AD');
	check($after['iem_sealed_key'] === $row['iem_sealed_key'] && (int)$after['iem_key_generation'] === (int)$row['iem_key_generation'],
		'the row\'s key and key generation did not move');
	check($after['iem_body_html'] === $row['iem_body_html'] && $after['iem_subject'] === $row['iem_subject'],
		'nothing else on the row changed');

	// -------------------------------------------------------------------------
	section('What it refuses');

	$e = absf_refusal(function () use ($mid, $seal) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_body_html' => $seal('iem_body_html', '<p>replaced</p>')));
	});
	check($e !== null && strpos($e, 'not a field a browser may add') !== false, 'a body: refused — the door never rewrites a received message', (string)$e);
	check(fortress_row($mid)['iem_body_html'] === $row['iem_body_html'], 'and the body is untouched');
	$e = absf_refusal(function () use ($mid, $seal) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_sender' => $seal('iem_sender', 'someone@else.example')));
	});
	check($e !== null, 'the sender: refused', (string)$e);
	$e = absf_refusal(function () use ($mid) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_spam_score' => 'v1.edge.AAAA'));
	});
	check($e !== null, 'a column that is not sealed at all: refused', (string)$e);
	$e = absf_refusal(function () use ($mid) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_ai_summary' => 'plain words'));
	});
	check($e !== null && strpos($e, 'v1.edge.') !== false, 'plaintext: refused', (string)$e);
	check(absf_refusal(function () use ($mid) { InboundEmailMessage::acceptBrowserSealedFields($mid, array()); }) !== null,
		'no fields: refused');
	check(absf_refusal(function () use ($seal) {
		InboundEmailMessage::acceptBrowserSealedFields(2147483000, array('iem_ai_summary' => $seal('iem_ai_summary', 'x', 2147483000)));
	}) !== null, 'a row that does not exist: refused');

	$set('UPDATE iem_inbound_email_messages SET iem_pending_parse = true WHERE iem_inbound_email_message_id = ?', array($mid));
	$e = absf_refusal(function () use ($mid, $seal) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_ai_scan' => $seal('iem_ai_scan', '{}')));
	});
	check($e !== null && strpos($e, 'waiting to be read') !== false, 'a row waiting for its browser parse: refused', (string)$e);
	$set('UPDATE iem_inbound_email_messages SET iem_pending_parse = false WHERE iem_inbound_email_message_id = ?', array($mid));

	$lowered = 'v1.edgeseal.user.' . substr((string)$row['iem_sealed_key'], strlen('v1.edgeseal.mail.'));
	$set('UPDATE iem_inbound_email_messages SET iem_sealed_key = ? WHERE iem_inbound_email_message_id = ?', array($lowered, $mid));
	$e = absf_refusal(function () use ($mid, $seal) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_ai_scan' => $seal('iem_ai_scan', '{}')));
	});
	check($e !== null && strpos($e, 'client-custody') !== false, 'a row sealed to a server-custody scope (v1.edgeseal.user.): refused', (string)$e);
	$set('UPDATE iem_inbound_email_messages SET iem_content_sealed = false, iem_sealed_key = ? WHERE iem_inbound_email_message_id = ?',
		array($row['iem_sealed_key'], $mid));
	$e = absf_refusal(function () use ($mid, $seal) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_ai_scan' => $seal('iem_ai_scan', '{}')));
	});
	check($e !== null && strpos($e, 'not sealed') !== false, 'a row that is not sealed: refused', (string)$e);
	$set('UPDATE iem_inbound_email_messages SET iem_content_sealed = true WHERE iem_inbound_email_message_id = ?', array($mid));
	check(fortress_row($mid)['iem_ai_scan'] === null, 'none of the refused posts wrote anything');

	// -------------------------------------------------------------------------
	section('A verdict added mid-rotation opens after it');

	// A rotation of the mail key re-wraps each row's DEK to the new key and moves
	// the row to the new generation (acceptBrowserReseal), content untouched.
	// Post a verdict between the two steps: it must still open afterwards.
	$next = $fx['box']->generateKeypair();
	$next_pub = base64_encode(SealedBox::b64url_decode($next['public']));
	$gen = (int)$row['iem_key_generation'];
	$scan_json = json_encode(array('verdict' => 'safe', 'red_flags' => array(), 'summary' => 'A shop order.',
		'model' => 'test-model', 'recipe_id' => 1));
	$scan = $seal('iem_ai_scan', $scan_json);
	check(absf_refusal(function () use ($mid, $scan) {
		InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_ai_scan' => $scan));
	}) === null, 'a scan verdict is accepted while the rotation is under way');
	InboundEmailMessage::acceptBrowserReseal($fx['owner_id'], $mid, $crypto->sealItemDekToBrowserKey($dek, $next_pub, 'mail'), $gen, $gen + 1);
	$rotated = fortress_row($mid);
	check((int)$rotated['iem_key_generation'] === $gen + 1 && $rotated['iem_sealed_key'] !== $row['iem_sealed_key'],
		'the rotation moved the row to the new key');
	$new_dek = $fx['box']->openEdge(substr((string)$rotated['iem_sealed_key'], strlen('v1.edgeseal.mail.')), $next['secret'], $next_pub);
	check(fortress_open_field($fx, $rotated['iem_ai_scan'], $new_dek, 'mail:' . $mid . ':iem_ai_scan') === $scan_json
		&& fortress_open_field($fx, $rotated['iem_ai_summary'], $new_dek, 'mail:' . $mid . ':iem_ai_summary') === 'Order confirmation with two attachments.',
		'both verdicts open under the new key');
	$late = $crypto->sealFieldForBrowser('Added after the move.', $new_dek, 'mail:' . $mid . ':iem_ai_summary');
	InboundEmailMessage::acceptBrowserSealedFields($mid, array('iem_ai_summary' => $late));
	check((int)fortress_row($mid)['iem_key_generation'] === $gen + 1, 'a later post leaves the new generation as it is');
} catch (Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
