<?php
/** @joinery-test
 * name: joinery_direct_protocol
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The Joinery Direct shared layer, in the parts that need no database and no
 * network: the signed byte-forms, the signing identity's verification, the kind
 * registry, capability-record parsing, and the decoy key's two load-bearing
 * properties.
 *
 * Each of these is a real way the channel could fail silently. A signature over
 * a non-canonical serialization verifies on one implementation and not the
 * other. A transfer signature not bound to the preflight nonce lets content be
 * spliced onto a different exchange. A decoy key that is not a valid curve point
 * — or that changes between probes of one address — is the exact existence
 * oracle the unconditional accept exists to close.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectCapability.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectDecoyKeys.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

// ---------------------------------------------------------------------------
section('What a preflight signature covers is canonical, not textual');
// ---------------------------------------------------------------------------

$envelope = array(
	'protocol_version' => DirectProtocol::PROTOCOL_VERSION,
	'kind'      => 'mail',
	'sender'    => 'Alice@Example.com',
	'recipient' => 'BOB@receiver.test',
	'key_id'    => 'k1',
	'nonce'     => 'abcdef0123456789abcdef0123456789',
	'timestamp' => '2026-08-12 10:00:00',
);
$manifest = array(
	array('role' => 'body_text', 'content_type' => 'text/plain', 'size' => 12),
	array('role' => 'attachment', 'content_type' => 'application/pdf', 'filename' => 'x.pdf', 'size' => 900),
);

$bytes = DirectProtocol::preflightSigningBytes($envelope, $manifest);

// Field ORDER in the caller's array must not change what is signed — two
// implementations agreeing on the fields but not on their order would otherwise
// produce signatures neither could verify.
$shuffled = array_reverse($envelope, true);
check(DirectProtocol::preflightSigningBytes($shuffled, $manifest) === $bytes,
	'the signed bytes do not depend on the caller\'s key order');

// Addresses are compared lowercased everywhere else, so they are signed that way.
check(strpos($bytes, 'alice@example.com') !== false && strpos($bytes, 'Alice@Example.com') === false,
	'addresses are lowercased inside what the signature covers');

$changed = $envelope;
$changed['recipient'] = 'carol@receiver.test';
check(DirectProtocol::preflightSigningBytes($changed, $manifest) !== $bytes,
	'changing the recipient changes what was signed');

// An absent kind, an empty kind and an explicit 'mail' all sign identically:
// "names none" is a blank value as much as a missing one. If they diverged, the
// relay would sign one thing and the box another for the same envelope, and a
// kindless delivery would verify on one end and fail on the other.
check(DirectProtocol::kindOrDefault('') === 'mail' && DirectProtocol::kindOrDefault('chat') === 'chat',
	'kindOrDefault fills only a blank kind');
$mail_kind  = $envelope;                        // already kind => 'mail'
$no_kind    = $envelope; unset($no_kind['kind']);
$empty_kind = $envelope; $empty_kind['kind'] = '';
$signed_mail = DirectProtocol::preflightSigningBytes($mail_kind, $manifest);
check(DirectProtocol::preflightSigningBytes($no_kind, $manifest) === $signed_mail,
	'an absent kind signs as mail');
check(DirectProtocol::preflightSigningBytes($empty_kind, $manifest) === $signed_mail,
	'and so does an empty one — no PHP/Go drift on the default kind');

$bigger = $manifest;
$bigger[1]['size'] = 901;
check(DirectProtocol::preflightSigningBytes($envelope, $bigger) !== $bytes,
	'the manifest sizes are inside the signature, so a declared size cannot be edited in flight');

// The manifest deliberately carries NO per-part hash: those cover the SEALED
// bytes, which do not exist until the recipient's key arrives in the accept.
$canonical = DirectProtocol::canonicalManifest($manifest);
check(!array_key_exists('hash', $canonical[0]),
	'a manifest entry carries no hash — sealed bytes do not exist at preflight');

// Invalid UTF-8 must FAIL loudly, not silently sign a prefix-only string. Bare
// json_encode returns false on invalid UTF-8, and 'prefix' . false is just the
// prefix — so without this guard every malformed envelope would sign one identical
// byte string and the signature would stop binding anything.
$bad = $envelope;
$bad['sender'] = "r\xE9sum\xE9@bad.test"; // raw Latin-1 bytes, not valid UTF-8
$threw = false;
try {
	DirectProtocol::preflightSigningBytes($bad, $manifest);
} catch (\Throwable $e) {
	$threw = true;
}
check($threw, 'signing bytes over invalid UTF-8 throw, rather than silently covering only the prefix');

// ---------------------------------------------------------------------------
section('A sealed part is raw on the wire, not base64 — the ceiling knows it');
// ---------------------------------------------------------------------------

// A Direct part is bulk payload of any size, so it seals RAW (crypto_box_seal)
// rather than in the base64 DEK form. Base64 would inflate every sealed part by
// a third and hold a third copy of it in memory beside plaintext and ciphertext.
// The size ceiling has to match the wire exactly, or a receiver that offered a
// key aborts an honest delivery for arriving the size it was told it would.
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
$kp = (new SealedBox())->generateKeypair();
$plain = str_repeat('payload bytes ', 5000);            // ~70 KB, well past a DEK
$sealed = (new VaultCrypto())->sealBulkDelivery($plain, $kp['public']);

check(strlen($sealed) === strlen($plain) + SODIUM_CRYPTO_BOX_SEALBYTES,
	'a sealed part is exactly plaintext + seal overhead — no base64 inflation', (string)strlen($sealed));
check(strpos($sealed, 'v1.seal.') !== 0,
	'and carries none of the DEK text wrapping');
check(DirectProtocol::sealedSizeCeiling(strlen($plain)) === strlen($sealed),
	'the ceiling the receiver computes from the declared size matches the sealed bytes exactly');
check((new VaultCrypto())->openBulkDelivery($sealed, $kp['secret']) === $plain,
	'and the recipient opens it back to the original bytes');

// ---------------------------------------------------------------------------
section('A transfer signature is bound to its preflight');
// ---------------------------------------------------------------------------

$hashes = array(DirectProtocol::hashBytes('one'), DirectProtocol::hashBytes('two'));
$transfer = DirectProtocol::transferSigningBytes($envelope['nonce'], $hashes);

check($transfer !== DirectProtocol::transferSigningBytes('0000000000000000000000000000ffff', $hashes),
	'the same hashes under a different nonce sign differently — content cannot be spliced onto another preflight');
check($transfer !== DirectProtocol::transferSigningBytes($envelope['nonce'], array_reverse($hashes)),
	'part ORDER is part of what is signed');
check(DirectProtocol::hashBytes('one') !== DirectProtocol::hashBytes('onE'),
	'the part hash actually distinguishes content');

$tmp = tempnam(sys_get_temp_dir(), 'jd');
file_put_contents($tmp, 'one');
check(DirectProtocol::hashFile($tmp) === DirectProtocol::hashBytes('one'),
	'a streamed file hash matches the in-memory one, so a large part never has to be held whole');
@unlink($tmp);

// ---------------------------------------------------------------------------
section('Instance signatures authenticate, and only the right key does');
// ---------------------------------------------------------------------------

$pair   = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = base64_encode(sodium_crypto_sign_publickey($pair));
$sig    = base64_encode(sodium_crypto_sign_detached($bytes, $secret));

check(DirectSigningIdentity::verify($bytes, $sig, $public), 'a valid signature verifies');
check(!DirectSigningIdentity::verify($bytes . 'x', $sig, $public), 'altered bytes do not');

$other  = sodium_crypto_sign_keypair();
check(!DirectSigningIdentity::verify($bytes, $sig, base64_encode(sodium_crypto_sign_publickey($other))),
	'another domain\'s key does not — being in someone\'s contacts is not a name anyone can claim');
check(!DirectSigningIdentity::verify($bytes, 'not base64 at all!!', $public),
	'a malformed signature is refused rather than throwing');
check(!DirectSigningIdentity::verify($bytes, $sig, 'short'),
	'a malformed public key is refused rather than throwing');

// ---------------------------------------------------------------------------
section('The capability record: only a well-formed key counts');
// ---------------------------------------------------------------------------

$good = DirectCapability::keyRecordValue('k1', $public);
$keys = DirectCapability::parseKeyRecords(array($good));
check(isset($keys['k1']) && $keys['k1'] === $public, 'a well-formed key record parses to id => key');

$rotating = DirectCapability::parseKeyRecords(array(
	$good, DirectCapability::keyRecordValue('k2', base64_encode(sodium_crypto_sign_publickey($other)))));
check(count($rotating) === 2,
	'two published keys are both honored — which is what makes a rotation a non-event');

check(DirectCapability::parseKeyRecords(array('v=spf1 include:example.com -all')) === array(),
	'an unrelated TXT string at the same name is ignored');
check(DirectCapability::parseKeyRecords(array('v=joinery1; k=k1; p=' . base64_encode('too short'))) === array(),
	'a key of the wrong length is no key — a malformed one would fail at verification anyway');
check(DirectCapability::parseKeyRecords(array('v=joinery9; k=k1; p=' . $public)) === array(),
	'a record version this build does not speak is ignored');

check(DirectCapability::srvRecordValue('Direct.Example.com.', 443) === '0 5 443 direct.example.com',
	'the published SRV value is canonical');

// ---------------------------------------------------------------------------
section('Decoy keys are valid, deterministic, and generation 1');
// ---------------------------------------------------------------------------

$decoy_a = DirectDecoyKeys::publicKeyFor('nobody@example.com');
$decoy_b = DirectDecoyKeys::publicKeyFor('NOBODY@Example.com');
check($decoy_a === $decoy_b,
	'a decoy is deterministic and case-insensitive — a key that changed between probes would be the tell');
check($decoy_a !== DirectDecoyKeys::publicKeyFor('someone-else@example.com'),
	'different addresses get different decoys');

$raw = SealedBox::b64url_decode($decoy_a);
check($raw !== false && strlen($raw) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES,
	'a decoy is a full-length X25519 public key');

// The load-bearing property: a sender must be able to SEAL to it without error,
// or a malformed-key failure would identify the decoy immediately.
$sealed = null;
try {
	$sealed = (new SealedBox())->sealDek('probe', $decoy_a);
} catch (Throwable $e) {
	$sealed = null;
}
check($sealed !== null && $sealed !== '',
	'sealing to a decoy succeeds exactly as sealing to a real key does');

check(DirectDecoyKeys::DECOY_GENERATION === 1,
	'a decoy reports generation 1 — the value a never-rotated vault carries, which is most of them');

// ---------------------------------------------------------------------------
section('The kind registry answers before any handler code runs');
// ---------------------------------------------------------------------------

DirectKinds::resetForTests();
$served = DirectKinds::servedNames();
check(is_array($served), 'the served set is readable as plain instance configuration');
check(!DirectKinds::isServed('definitely_not_a_kind'),
	'an unknown kind is not served, so its preflight refuses before dispatch');
check(DirectKinds::declaration('definitely_not_a_kind') === null,
	'and it has no declaration to load a handler from');
check(DirectKinds::handler('definitely_not_a_kind') === null,
	'asking for its handler yields nothing rather than an error');

if (in_array('mail', $served, true)) {
	check(DirectKinds::usesContactGate('mail'),
		'mail declares the canned contact gate, so the framework runs it and never calls a handler gate');
} else {
	check(true, 'mail is not served here (mailbox plugin inactive) — the registry says so cleanly');
}

// ---------------------------------------------------------------------------
section('The typed envelope handlers see');
// ---------------------------------------------------------------------------

$typed = DirectEnvelope::fromVerified(array(
	'kind' => 'mail', 'sender' => 'Alice@Example.com', 'sender_domain' => 'example.com',
	'recipient' => 'bob@receiver.test', 'recipient_user_id' => 7, 'recipient_alias_id' => 3,
	'nonce' => $envelope['nonce'], 'timestamp' => $envelope['timestamp'],
));
check($typed->sender() === 'alice@example.com', 'the sender is exposed lowercased');
check($typed->verifiedSenderDomain() === 'example.com', 'the VERIFIED signing domain is its own accessor');
check($typed->senderIsAligned(),
	'an address whose domain matches the verified signature is aligned');

$spoofed = $typed->with(array('sender' => 'alice@example.com', 'sender_domain' => 'attacker.test'));
check(!$spoofed->senderIsAligned(),
	'a From claiming another domain than the one that signed is NOT aligned — a spoofed From cannot borrow a place in your contacts');

check($typed->transport() === 'joinery_direct', 'the transport tag a kind records is fixed');
check($typed->vaultSecretKey() === null,
	'a live-path envelope carries no vault secret — that only exists on the deferred path');

harness_finish();
