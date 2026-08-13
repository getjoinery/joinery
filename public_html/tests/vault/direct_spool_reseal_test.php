<?php
/** @joinery-test
 * name: direct_spool_reseal
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 *
 * A held Direct delivery's sealed parts are sealed STRAIGHT to the recipient's
 * vault keypair — no per-item DEK to re-wrap — so a key rotation must re-seal
 * the bytes themselves. Without it, retiring the old generation would orphan
 * every held delivery: the drain would fail at the next unlock, nothing would
 * bounce, and the delivery would expire quietly under spool retention.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSpoolDrain.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSpoolService.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_parts_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

$user = make_user('SpoolReseal');
$user_id = (int)$user->key;

// Generation 1 (draining) and generation 2 (target) keypairs.
$old_pair = sodium_crypto_box_keypair();
$old_public = SealedBox::b64url(sodium_crypto_box_publickey($old_pair));
$old_secret = SealedBox::b64url(sodium_crypto_box_secretkey($old_pair));
$new_pair = sodium_crypto_box_keypair();
$new_public = SealedBox::b64url(sodium_crypto_box_publickey($new_pair));
$new_secret = SealedBox::b64url(sodium_crypto_box_secretkey($new_pair));

$crypto = new VaultCrypto();

function spool_row(int $user_id, int $generation, string $state): DirectSpool {
	$spool = new DirectSpool(NULL);
	$spool->set('jdp_kind', 'mail');
	$spool->set('jdp_nonce', bin2hex(random_bytes(16)));
	$spool->set('jdp_sender_address', 'sender@example.org');
	$spool->set('jdp_sender_domain', 'example.org');
	$spool->set('jdp_recipient', 'member@example.com');
	$spool->set('jdp_domain', 'example.com');
	$spool->set('jdp_usr_user_id', $user_id);
	$spool->set('jdp_manifest', '{}');
	$spool->set('jdp_key_generation', $generation);
	$spool->set('jdp_state', $state);
	$spool->save();
	harness_register_row('jdp_direct_spool', 'jdp_direct_spool_id', (int)$spool->key);
	return $spool;
}

function spool_part_raw(int $part_id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT * FROM jda_direct_spool_parts WHERE jda_direct_spool_part_id = ?');
	$q->execute(array($part_id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

// ---------------------------------------------------------------------------
section('A held delivery re-seals: inline part, file-backed part, plaintext part');
// ---------------------------------------------------------------------------
$spool = spool_row($user_id, 1, DirectSpool::STATE_HELD);

$body_plain = 'A sealed body the sender wrote. ' . bin2hex(random_bytes(32));
$body_sealed = $crypto->sealBulkDelivery($body_plain, $old_public);
DirectSpoolService::storeRelayedPart((int)$spool->key, 0,
	array('role' => 'body_text', 'content_type' => 'text/plain'),
	$body_sealed, true, DirectProtocol::hashBytes($body_sealed), $user_id);

// Big enough for the file store (> DirectSpoolPart::INLINE_MAX_BYTES).
$attachment_plain = random_bytes(DirectSpoolPart::INLINE_MAX_BYTES + 4096);
$attachment_sealed = $crypto->sealBulkDelivery($attachment_plain, $old_public);
DirectSpoolService::storeRelayedPart((int)$spool->key, 1,
	array('role' => 'attachment', 'content_type' => 'application/octet-stream', 'filename' => 'blob.bin'),
	$attachment_sealed, true, DirectProtocol::hashBytes($attachment_sealed), $user_id);

// A part that arrived plaintext-over-TLS (no key was discoverable) — untouched.
$plain_part_bytes = 'no seal on this one';
DirectSpoolService::storeRelayedPart((int)$spool->key, 2,
	array('role' => 'attachment', 'content_type' => 'text/plain', 'filename' => 'note.txt'),
	$plain_part_bytes, false, DirectProtocol::hashBytes($plain_part_bytes), $user_id);

$parts_before = DirectSpoolPart::forSpool((int)$spool->key);
check(count($parts_before) === 3, 'the delivery holds its three parts');
$old_attachment_file_id = intval($parts_before[1]->get('jda_fil_file_id'));
check($old_attachment_file_id > 0, 'the large part went to the file store');

// A held delivery for a DIFFERENT generation must be left alone.
$other = spool_row($user_id, 7, DirectSpool::STATE_HELD);
$other_plain = 'sealed on another generation';
$other_sealed = $crypto->sealBulkDelivery($other_plain, $new_public);
DirectSpoolService::storeRelayedPart((int)$other->key, 0,
	array('role' => 'body_text', 'content_type' => 'text/plain'),
	$other_sealed, true, DirectProtocol::hashBytes($other_sealed), $user_id);

DirectSpoolDrain::resealForUser($user_id, $old_secret, 1, $new_public, 2);

$spool_after = new DirectSpool((int)$spool->key, TRUE);
check((int)$spool_after->get('jdp_key_generation') === 2, 'the delivery moved to the new generation');

$parts_after = DirectSpoolPart::forSpool((int)$spool->key);
$body_after = $parts_after[0]->bytes();
check($body_after !== $body_sealed, 'the inline sealed bytes were rewritten');
check($crypto->openBulkDelivery($body_after, $new_secret) === $body_plain,
	'and open with the NEW secret to the same plaintext');
$old_opens = true;
try {
	$crypto->openBulkDelivery($body_after, $old_secret);
} catch (Throwable $e) {
	$old_opens = false;
}
check(!$old_opens, 'the old secret no longer opens them — the re-seal is real, not a copy');
check((string)spool_part_raw((int)$parts_after[0]->key)['jda_hash'] === DirectProtocol::hashBytes($body_after),
	'the stored hash describes the stored bytes');

$new_attachment_file_id = intval($parts_after[1]->get('jda_fil_file_id'));
check($new_attachment_file_id > 0 && $new_attachment_file_id !== $old_attachment_file_id,
	'the file-backed part points at a new file');
check($crypto->openBulkDelivery($parts_after[1]->bytes(), $new_secret) === $attachment_plain,
	'whose bytes open with the new secret to the original attachment');
$gone = DbConnector::get_instance()->get_db_link()->prepare(
	'SELECT 1 FROM fil_files WHERE fil_file_id = ?');
$gone->execute(array($old_attachment_file_id));
check($gone->fetchColumn() === false, 'and the superseded file is gone');

check($parts_after[2]->bytes() === $plain_part_bytes, 'the plaintext part is byte-identical — nothing to re-seal');

$other_after = new DirectSpool((int)$other->key, TRUE);
check((int)$other_after->get('jdp_key_generation') === 7, 'a delivery on another generation was not touched');
check(DirectSpoolPart::forSpool((int)$other->key)[0]->bytes() === $other_sealed, 'nor were its bytes');

// ---------------------------------------------------------------------------
section('Failure is loud: an unopenable part refuses the whole ceremony');
// ---------------------------------------------------------------------------
$broken = spool_row($user_id, 3, DirectSpool::STATE_HELD);
$garbage = random_bytes(64); // claims sealed, opens under nothing
DirectSpoolService::storeRelayedPart((int)$broken->key, 0,
	array('role' => 'body_text', 'content_type' => 'text/plain'),
	$garbage, true, DirectProtocol::hashBytes($garbage), $user_id);

$threw = false;
try {
	DirectSpoolDrain::resealForUser($user_id, $old_secret, 3, $new_public, 4);
} catch (RuntimeException $e) {
	$threw = true;
}
check($threw, 'the callback throws, so the ceremony refuses to retire the old generation');
$broken_after = new DirectSpool((int)$broken->key, TRUE);
check((int)$broken_after->get('jdp_key_generation') === 3,
	'and the failed delivery still sits on its old generation, to be retried');
check(DirectSpoolPart::forSpool((int)$broken->key)[0]->bytes() === $garbage,
	'with its bytes untouched — the per-delivery transaction rolled back');

// Cleanup: the spool rows own part rows and file bytes.
foreach (array($spool, $other, $broken) as $row) {
	try {
		(new DirectSpool((int)$row->key, TRUE))->permanent_delete();
	} catch (Throwable $e) {
		// harness_register_row cleanup remains as the fallback
	}
}

harness_finish();
?>
