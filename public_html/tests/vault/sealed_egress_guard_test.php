<?php
/** @joinery-test
 * name: sealed_egress_guard
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The hot-turn rule (specs/implemented/sealed_content_egress.md § Layer 2).
 *
 * The rule in one sentence: once a process has actually opened sealed content,
 * any long string it writes to the database must land somewhere that protects
 * it. Everything below is a consequence of that sentence, and each check exists
 * because getting it wrong is a silent confidentiality failure rather than a
 * visible bug.
 *
 * What is pinned here:
 *
 *  - a cold process is unaffected, which is the cost argument: nearly every
 *    request in the platform is cold and pays one boolean check;
 *  - fetching a vault key is not the same as reading content, so enrolment and
 *    rotation do not arm the rule;
 *  - once armed, a long plaintext write into a table with no sealing throws,
 *    and the exception names the destination — the message IS the fix
 *    instruction, so its content is asserted, not just its type;
 *  - the two ways a write passes while hot: the value is already sealed, or the
 *    row it lands in is sealed to the same owner;
 *  - the threshold is a deliberate, tested boundary rather than a number
 *    someone can drift;
 *  - mail is refused outright unless the call site names why it is allowed;
 *  - a unit-of-work boundary scopes the rule without laundering it — an outer
 *    hot state survives, so nesting cannot be used to go cold.
 *
 * Run: php tests/run.php db --filter=sealed_egress_guard
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('data/api_idempotency_keys_class.php'));

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process',
		'run manually: php -d apc.enable_cli=1 tests/vault/sealed_egress_guard_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

/** A string comfortably over the threshold — the shape of a subject or a summary. */
function seg_long(string $seed = 'x'): string {
	return str_repeat($seed, SealedEgressGuard::THRESHOLD + 20);
}

/** Run $fn and return the SealedContentEgressException it threw, or null. */
function seg_refusal(callable $fn): ?SealedContentEgressException {
	try {
		$fn();
	} catch (SealedContentEgressException $e) {
		return $e;
	}
	return null;
}

try {
	$db = DbConnector::get_instance()->get_db_link();

	// =====================================================================
	section('a cold process is untouched');

	SealedEgressGuard::reset();
	check(!SealedEgressGuard::isHot(), 'a fresh process is cold');

	// err_general_errors has no sealing of any kind, so this is the write the
	// rule would refuse if it were armed. Cold, it is nobody's business.
	$cold_row = new GeneralError(NULL);
	$cold_row->set('err_level', 'Test');
	$cold_row->set('err_message', seg_long('c'));
	$cold_row->save();
	harness_register_row('err_general_errors', 'err_general_error_id', (int)$cold_row->key);
	check((int)$cold_row->key > 0, 'a long plaintext write to an unsealable table succeeds while cold');

	// =====================================================================
	section('holding a key is not the same as reading content');

	$fixture = vault_fixture_vault('SealEgress');
	$owner_id = (int)$fixture['user']->key;
	$vault = $fixture['vault'];

	SealedEgressGuard::reset();
	SealedEgressGuard::noteScopeOpened($owner_id);
	check(!SealedEgressGuard::isHot(),
		'noting an opened scope does not arm the rule — enrolment and rotation fetch keys without reading');
	check(SealedEgressGuard::ownerUserId() === $owner_id,
		'but the owner is recorded, ready to attribute a read that follows');

	// =====================================================================
	section('opening sealed content arms it');

	$crypto = new VaultCrypto();
	$dek = $crypto->newItemDek();
	$blob = $crypto->sealField('a decrypted subject line', $dek, 'test:1:subject');
	$opened = $crypto->openField($blob, $dek, 'test:1:subject');
	check($opened === 'a decrypted subject line', 'the content opens normally');
	check(SealedEgressGuard::isHot(), 'and opening it is what makes the process hot');
	check(in_array('test:1:subject', SealedEgressGuard::sources(), true),
		'the source is recorded, so a refusal can say what was read');

	// =====================================================================
	section('while hot, a long plaintext write is refused');

	$refusal = seg_refusal(function () {
		$hot_row = new GeneralError(NULL);
		$hot_row->set('err_level', 'Test');
		$hot_row->set('err_message', seg_long('h'));
		$hot_row->save();
	});
	check($refusal !== null, 'the write throws SealedContentEgressException');
	check($refusal && strpos($refusal->getMessage(), 'err_general_errors') !== false,
		'the message names the destination table');
	check($refusal && strpos($refusal->getMessage(), 'test:1:subject') !== false,
		'and names the source scope that made the process hot');
	check($refusal && strpos($refusal->getMessage(), 'reference') !== false,
		'and states the preferred fix, because the exception is the instruction');

	// =====================================================================
	section('the threshold is a boundary, not a suggestion');

	$at_threshold = str_repeat('t', SealedEgressGuard::THRESHOLD);
	$short_row = new GeneralError(NULL);
	$short_row->set('err_level', 'Test');
	$short_row->set('err_message', $at_threshold);
	$short_row->save();
	harness_register_row('err_general_errors', 'err_general_error_id', (int)$short_row->key);
	check((int)$short_row->key > 0,
		'a value exactly at the threshold passes while hot — the accepted residual gap of decision 8');

	$over = seg_refusal(function () {
		$row = new GeneralError(NULL);
		$row->set('err_level', 'Test');
		$row->set('err_message', str_repeat('t', SealedEgressGuard::THRESHOLD + 1));
		$row->save();
	});
	check($over !== null, 'one character over the threshold is refused');

	// =====================================================================
	section('already-sealed values pass');

	$idem = new ApiIdempotencyKey(NULL);
	$idem->set('aik_key_hash', hash('sha256', 'seg-' . $owner_id));
	$idem->set('aik_credential_scope', 'user:' . $owner_id);
	$idem->set('aik_action', 'seg/test');
	$idem->set('aik_body_hash', hash('sha256', 'body'));
	$idem->save();
	harness_register_row('aik_api_idempotency_keys', 'aik_api_idempotency_key_id', (int)$idem->key);
	check((int)$idem->key > 0, 'a row with no long values inserts while hot');

	$response = json_encode(array('subject' => seg_long('r')));
	ApiIdempotencyKey::sealColumns((int)$idem->key, $vault, array('aik_response_body' => $response));
	$stored = $db->prepare('SELECT aik_response_body, aik_content_sealed, aik_sealed_owner_user_id
		FROM aik_api_idempotency_keys WHERE aik_api_idempotency_key_id = ?');
	$stored->execute(array((int)$idem->key));
	$idem_row = $stored->fetch(PDO::FETCH_ASSOC);
	check(strpos((string)$idem_row['aik_response_body'], 'v1.aead.') === 0,
		'sealColumns() writes ciphertext through the guard while hot — this is how the fix passes the rule');
	check(strpos((string)$response, (string)$idem_row['aik_response_body']) === false,
		'and no plaintext of the response survives in the column');

	// The cache's contract when it cannot be read: the response is gone, the row
	// is not. Suppressing the duplicate is the part that protects the client, and
	// it survives a locked vault (specs/implemented/sealed_content_egress.md § decision 6).
	$locked_read = false;
	try {
		(new ApiIdempotencyKey((int)$idem->key, TRUE))->get('aik_response_body');
	} catch (VaultLockedException $e) {
		$locked_read = true;
	}
	check($locked_read, 'a sealed cached body reads as locked, not as ciphertext');
	$survives = $db->prepare('SELECT aik_key_hash FROM aik_api_idempotency_keys
		WHERE aik_api_idempotency_key_id = ?');
	$survives->execute(array((int)$idem->key));
	check((string)$survives->fetchColumn() === hash('sha256', 'seg-' . $owner_id),
		'while the key row itself stands, so a retry is still refused rather than re-executed');

	// A seal flag arrives as a real bool, as t/f, as 0/1, or as whatever string a
	// field spec declared. All but a bare false are truthy in PHP, and a row
	// wrongly read as sealed has its content columns skipped by save() and
	// silently never written — which is exactly what a plain unsealed row looked
	// like before this was pinned.
	$unsealed = new ApiIdempotencyKey((int)$idem->key, TRUE);
	check($unsealed->rowIsSealed(), 'a genuinely sealed row reports sealed');
	$plain = new ApiIdempotencyKey(NULL);
	check(!$plain->rowIsSealed(), 'and a row with no flag set reports unsealed, whatever spelling the default uses');

	// =====================================================================
	section('a row sealed to the recorded owner may receive plaintext');

	// The row above is now sealed to this owner. Writing a long value into a
	// column it does NOT seal is still landing in a protected row, so it passes:
	// the rule is about where content ends up, not which column names it.
	$passthrough = seg_refusal(function () use ($db, $idem) {
		$stmt = $db->prepare('UPDATE aik_api_idempotency_keys SET aik_action = ?
			WHERE aik_api_idempotency_key_id = ?');
		$stmt->execute(array(substr(seg_long('a'), 0, 120), (int)$idem->key));
	});
	check($passthrough === null, 'an update to a row sealed to the recorded owner passes while hot');

	// =====================================================================
	section('mail is refused unless the call site says why it is allowed');

	$mail_refusal = seg_refusal(function () {
		SealedEgressGuard::assertSendAllowed('', 'Re: your invoice');
	});
	check($mail_refusal !== null, 'a hot send with no assertion is refused');
	check($mail_refusal && strpos($mail_refusal->getMessage(), 'unencrypted channel') !== false,
		'and says why: mail is an unencrypted channel');

	$named = seg_refusal(function () {
		SealedEgressGuard::assertSendAllowed(EmailSender::EGRESS_CONTENT_FREE, 'Run finished');
	});
	check($named === null, 'a send the call site declares content-free proceeds');

	$compose = seg_refusal(function () {
		SealedEgressGuard::assertSendAllowed(EmailSender::EGRESS_USER_COMPOSE, 'my own message');
	});
	check($compose === null, 'and so does a message the user is sending themselves');

	$bogus = seg_refusal(function () {
		SealedEgressGuard::assertSendAllowed('yes', 'Re: your invoice');
	});
	check($bogus !== null,
		'an assertion that is not one of the declared ones is no assertion — a typo cannot open the door');
	foreach (array(EmailSender::EGRESS_CONTENT_FREE, EmailSender::EGRESS_USER_COMPOSE,
			EmailSender::EGRESS_ACKNOWLEDGED_FORWARD) as $constant) {
		check(in_array($constant, SealedEgressGuard::SEND_ASSERTIONS, true),
			'the guard recognises EmailSender::' . $constant, $constant);
	}

	// A refused send never reaches the transport, so it is never queued for
	// retry — which is how the equ_queued_emails spill closes without a second
	// rule anywhere near the queue.
	$before_queue = (int)$db->query('SELECT count(*) FROM equ_queued_emails')->fetchColumn();
	$send_refusal = seg_refusal(function () {
		(new EmailSender())->send(EmailMessage::create('nobody@example.com', 'Hot subject', seg_long('q')));
	});
	$after_queue = (int)$db->query('SELECT count(*) FROM equ_queued_emails')->fetchColumn();
	check($send_refusal !== null, 'EmailSender::send() refuses a hot send with no assertion');
	check($before_queue === $after_queue,
		'and nothing is queued for retry, because a message that is never sent is never queued');

	// =====================================================================
	section('a unit of work scopes the rule without laundering it');

	check(SealedEgressGuard::isHot(), 'the process is still hot going in');
	$inside = SealedEgressGuard::isolate(function () {
		$row = new GeneralError(NULL);
		$row->set('err_level', 'Test');
		$row->set('err_message', seg_long('u'));
		$row->save();
		harness_register_row('err_general_errors', 'err_general_error_id', (int)$row->key);
		return array('hot' => SealedEgressGuard::isHot(), 'id' => (int)$row->key);
	});
	check($inside['hot'] === false, 'an isolated unit starts cold');
	check($inside['id'] > 0, 'so a write of its own making succeeds');
	check(SealedEgressGuard::isHot(), 'and the outer hot state survives — nesting cannot launder a process cold');

	$still_refused = seg_refusal(function () {
		$row = new GeneralError(NULL);
		$row->set('err_level', 'Test');
		$row->set('err_message', seg_long('z'));
		$row->save();
	});
	check($still_refused !== null, 'the very next write outside the unit is refused again');

	// =====================================================================
	section('more than one owner means no owner');

	SealedEgressGuard::reset();
	SealedEgressGuard::noteScopeOpened($owner_id);
	SealedEgressGuard::noteScopeOpened($owner_id + 1);
	SealedEgressGuard::markHot('mail:1:body');
	check(SealedEgressGuard::ownerUserId() === null,
		'a process that read two people content can name neither, so nothing can be sealed on its behalf');

} finally {
	// Cleanup only — never harness_finish() here. harness_finish() exit()s, so
	// calling it inside the try would swallow an in-flight exception and report a
	// short PASS. Left outside, an exception reaches the shutdown reporter.
	// Returning the process to cold matters more than usual in this suite: it is
	// the one that deliberately arms the rule, and harness teardown writes rows.
	SealedEgressGuard::reset();
}

harness_finish();
