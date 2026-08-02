<?php
/** @joinery-test
 * name: idempotency_sealed
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The sealed idempotency cache
 * (specs/implemented/sealed_content_egress.md § resolved decision 6).
 *
 * Any /api/v1 response can carry protected content — mail read on a protected
 * domain, a protected chat's reply — and the idempotency cache stores response
 * bodies verbatim for replay. So the storage decision follows the hot-turn
 * rule's view of the request that produced the body:
 *
 *  - a cold request caches in the clear, exactly as it always has;
 *  - a hot request with one attributable owner seals the body to that owner —
 *    replay inside their unlock window returns it unchanged, replay outside
 *    one is told the response is not retained;
 *  - a hot request with several owners (or an owner with no vault) stores no
 *    body at all — there is no single person the response belongs to;
 *  - in every case the STATUS is stored and the key row survives, because
 *    suppressing the duplicate is the part that actually protects the client.
 *
 * This drives the same protected internals the API dispatcher uses
 * (idempotencyFinalize / idempotencyReplayBody), so what is pinned here is the
 * decision logic itself, not a reimplementation of it.
 *
 * Run: php tests/run.php db --filter=idempotency_sealed
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/ApiLogicEndpoint.php'));
require_once(PathHelper::getIncludePath('data/api_idempotency_keys_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process',
		'run manually: php -d apc.enable_cli=1 tests/api/idempotency_sealed_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

/** Reach the protected idempotency internals the dispatcher uses. */
class IdempotencySealedTestEndpoint extends ApiLogicEndpoint {
	public static function finalize($row, int $status, string $json): void {
		self::idempotencyFinalize($row, $status, $json);
	}
	public static function replayBody($row): ?string {
		return self::idempotencyReplayBody($row);
	}
}

/** A claimed key row, as idempotencyBegin leaves it: no outcome stored yet. */
function ids_row(string $tag): ApiIdempotencyKey {
	$row = new ApiIdempotencyKey(NULL);
	$row->set('aik_key_hash', hash('sha256', 'ids-' . $tag . '-' . bin2hex(random_bytes(4))));
	$row->set('aik_credential_scope', 'user:1');
	$row->set('aik_action', 'ids/test');
	$row->set('aik_body_hash', hash('sha256', 'body'));
	$row->save();
	harness_register_row('aik_api_idempotency_keys', 'aik_api_idempotency_key_id', (int)$row->key);
	return $row;
}

/** The row's stored columns, read raw so no decryption gets in the way. */
function ids_stored(int $row_id): array {
	$stmt = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT aik_response_status, aik_response_body, aik_content_sealed, aik_sealed_owner_user_id
		   FROM aik_api_idempotency_keys WHERE aik_api_idempotency_key_id = ?');
	$stmt->execute(array($row_id));
	return $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
}

/** A response body whose payload is comfortably over the guard's threshold. */
function ids_body(string $seed): string {
	return json_encode(array('subject' => str_repeat($seed, SealedEgressGuard::THRESHOLD + 20)));
}

try {
	// =====================================================================
	section('a cold request caches in the clear, as always');

	SealedEgressGuard::reset();
	$cold_row = ids_row('cold');
	$cold_body = ids_body('c');
	IdempotencySealedTestEndpoint::finalize($cold_row, 200, $cold_body);

	$stored = ids_stored((int)$cold_row->key);
	check((int)$stored['aik_response_status'] === 200, 'the status is stored');
	check((string)$stored['aik_response_body'] === $cold_body, 'the body is stored verbatim');
	check(!(new ApiIdempotencyKey((int)$cold_row->key, TRUE))->rowIsSealed(),
		'and the row is not sealed — an ordinary replay stays free to read');
	$replayed = IdempotencySealedTestEndpoint::replayBody(new ApiIdempotencyKey((int)$cold_row->key, TRUE));
	check($replayed === $cold_body, 'and a replay hands it back unchanged');

	// =====================================================================
	section('a hot request with one owner seals the body to them');

	$owner = make_user('IdsOwner');
	$owner_id = (int)$owner->key;
	$kp = sodium_crypto_box_keypair();
	$secret = SealedBox::b64url(sodium_crypto_box_secretkey($kp));
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', $owner_id);
	$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($kp)));
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

	SealedEgressGuard::reset();
	SealedEgressGuard::noteScopeOpened($owner_id);
	SealedEgressGuard::markHot('ids:test:content');
	$hot_row = ids_row('hot');
	$hot_body = ids_body('h');
	IdempotencySealedTestEndpoint::finalize($hot_row, 200, $hot_body);

	$stored = ids_stored((int)$hot_row->key);
	check((int)$stored['aik_response_status'] === 200, 'the status is stored, so a retry is safe either way');
	check(strpos((string)$stored['aik_response_body'], 'v1.aead.') === 0,
		'the body is stored as ciphertext, not plaintext');
	check(strpos((string)$stored['aik_response_body'], 'subject') === false,
		'and no fragment of the response survives in the clear');
	check((int)$stored['aik_sealed_owner_user_id'] === $owner_id, 'sealed to the owner whose scope was open');

	// =====================================================================
	section('replay inside the owner window returns it; outside, it is not retained');

	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
	$replayed = IdempotencySealedTestEndpoint::replayBody(new ApiIdempotencyKey((int)$hot_row->key, TRUE));
	check($replayed === $hot_body, 'an in-window replay decrypts and returns the original body');

	VaultUnlock::lockAll($owner_id);
	$locked = IdempotencySealedTestEndpoint::replayBody(new ApiIdempotencyKey((int)$hot_row->key, TRUE));
	check($locked === null, 'a locked replay answers null — the 409 "not retained" outcome');
	$still = ids_stored((int)$hot_row->key);
	check(!empty($still), 'while the key row survives, so the duplicate is still suppressed');

	// =====================================================================
	section('more than one owner means no body is stored at all');

	SealedEgressGuard::reset();
	SealedEgressGuard::noteScopeOpened($owner_id);
	SealedEgressGuard::noteScopeOpened($owner_id + 1);
	SealedEgressGuard::markHot('ids:test:multi');
	$multi_row = ids_row('multi');
	IdempotencySealedTestEndpoint::finalize($multi_row, 201, ids_body('m'));

	$stored = ids_stored((int)$multi_row->key);
	check((int)$stored['aik_response_status'] === 201, 'the status is still stored');
	check($stored['aik_response_body'] === null, 'but no body is — it belongs to no single person');
	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
	check(IdempotencySealedTestEndpoint::replayBody(new ApiIdempotencyKey((int)$multi_row->key, TRUE)) === null,
		'and even an open window cannot conjure one back');
	VaultUnlock::lockAll($owner_id);

	// =====================================================================
	section('an owner with no vault also stores no body');

	$bare = make_user('IdsBare');
	SealedEgressGuard::reset();
	SealedEgressGuard::noteScopeOpened((int)$bare->key);
	SealedEgressGuard::markHot('ids:test:novault');
	$bare_row = ids_row('bare');
	IdempotencySealedTestEndpoint::finalize($bare_row, 200, ids_body('b'));

	$stored = ids_stored((int)$bare_row->key);
	check((int)$stored['aik_response_status'] === 200, 'the status is stored');
	check($stored['aik_response_body'] === null, 'the body is not — there is no key to seal it to');

} finally {
	// Cleanup only — never harness_finish() here; it exit()s, which would
	// swallow an in-flight exception and report a short PASS. This suite arms
	// the rule deliberately, and teardown writes rows, so cold matters.
	SealedEgressGuard::reset();
}

harness_finish();
