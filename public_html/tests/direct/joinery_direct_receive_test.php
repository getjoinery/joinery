<?php
/** @joinery-test
 * name: joinery_direct_receive
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * The Joinery Direct receive framework's wire discipline, exercised end to end
 * against the real tables through the loopback send — no second instance, no
 * DNS record, no network.
 *
 * Every check here corresponds to a property the design would silently lose if
 * it regressed, and each one would be invisible in normal use:
 *
 *   - a replayed preflight nonce re-delivering a captured message;
 *   - a captured content transfer replayed against a consumed session;
 *   - a part delivered larger than its declared size;
 *   - a substituted part passing hash verification;
 *   - a kind this instance does not serve reaching a handler;
 *   - a stranger's answer differing from a non-existent address's, which would
 *     turn the endpoint into an address-harvesting oracle.
 *
 * The kind registered here is a TEST kind, so the assertions are about the
 * framework rather than about mail — which is exactly the claim: the discipline
 * is framework-enforced for every kind, not a convention mail happens to follow.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectReceiver.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectRecipients.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectContactGate.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));
require_once(PathHelper::getIncludePath('data/direct_nonces_class.php'));
require_once(PathHelper::getIncludePath('data/direct_sessions_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_parts_class.php'));

// ---------------------------------------------------------------------------
// A test kind, registered the way a plugin would declare one. It records what
// it was handed so the assertions can look at the handler's side of the
// contract as well as the wire's.
// ---------------------------------------------------------------------------

class DirectTestHandler implements DirectKindHandler {
	public static $ingested = array();
	public static $gate_calls = 0;
	public static $gate_answer = true;

	public function gate(DirectEnvelope $envelope): bool {
		self::$gate_calls++;
		return self::$gate_answer;
	}

	public function ingest(DirectEnvelope $envelope, array $parts, bool $gate_accepted): void {
		$bodies = array();
		foreach ($parts as $part) {
			$bodies[] = $part->open($envelope->vaultSecretKey());
		}
		self::$ingested[] = array(
			'sender' => $envelope->sender(),
			'recipient' => $envelope->recipient(),
			'accepted' => $gate_accepted,
			'bodies' => $bodies,
			'alias_id' => $envelope->recipientAliasId(),
			'domain_id' => $envelope->recipientDomainId(),
		);
	}
}

// Register the kind and a recipient resolver directly, which is what a plugin
// bootstrap does. Both are request-scoped, so nothing here outlives the test.
$registry = new ReflectionProperty('DirectKinds', 'registry');
$registry->setAccessible(true);
$handlers = new ReflectionProperty('DirectKinds', 'handlers');
$handlers->setAccessible(true);

$TEST_DOMAIN = 'direct-test.invalid';
$REAL   = 'real@' . $TEST_DOMAIN;
$ABSENT = 'nobody@' . $TEST_DOMAIN;
$SENDER = 'peer@sender-test.invalid';

// A stand-in recipient vault. Only the PUBLIC half is ever handed to a sender;
// the secret half opens the sealed parts the way an unlock window would.
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
$RECIPIENT_VAULT = (new SealedBox())->generateKeypair();
$RECIPIENT_VAULT_KEY = $RECIPIENT_VAULT['public'];

/** Install the kind registry and recipient posture for one scenario. */
function direct_test_setup(bool $seals_content, array $extra_kinds = array(), bool $recipient_has_vault = true) {
	global $TEST_DOMAIN, $REAL, $registry, $handlers, $RECIPIENT_VAULT_KEY;

	$registry->setValue(null, array_merge(array(
		'testkind' => array('kind' => 'testkind', 'handler' => '', 'class' => 'DirectTestHandler',
			'gate' => '', 'plugin' => ''),
	), $extra_kinds));
	$handlers->setValue(null, array('testkind' => new DirectTestHandler()));

	// Consumer bootstraps register the real resolver LAZILY, on the first
	// resolve() — so they have to be loaded before this one is installed, or the
	// first delivery would quietly run against the mailbox's resolver instead of
	// this test's.
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	VaultUnlock::loadConsumerBootstraps();

	DirectRecipients::resetForTests();
	DirectRecipients::registerResolver(function (string $address) use ($TEST_DOMAIN, $REAL, $seals_content, $recipient_has_vault, $RECIPIENT_VAULT_KEY) {
		if (DirectProtocol::domainOf($address) !== $TEST_DOMAIN) {
			return null; // not hosted here — a request-level refusal, not a gate answer
		}
		return array(
			'hosts_domain'  => true,
			'domain_id'     => 1,
			'seals_content' => $seals_content,
			'exists'        => (strtolower($address) === $REAL),
			'user_id'       => (strtolower($address) === $REAL) ? 1 : 0,
			'alias_id'      => (strtolower($address) === $REAL) ? 1 : 0,
			// A sealed tier's recipient normally holds a vault, which is what the
			// receiver answers a preflight with. A Standard recipient is never
			// offered one — the mailbox stores plaintext and ingest runs live.
			'vault_public_key' => ($seals_content && $recipient_has_vault
				&& strtolower($address) === $REAL) ? $RECIPIENT_VAULT_KEY : null,
			'key_generation'   => ($seals_content && $recipient_has_vault
				&& strtolower($address) === $REAL) ? 1 : 0,
		);
	});

	DirectTestHandler::$ingested = array();
	DirectTestHandler::$gate_calls = 0;
	DirectTestHandler::$gate_answer = true;
}

/** One preflight, fully formed. */
function direct_test_envelope(string $recipient, string $kind = 'testkind', int $version = null): array {
	global $SENDER;
	return array(
		'protocol_version' => $version ?? DirectProtocol::PROTOCOL_VERSION,
		'kind'      => $kind,
		'sender'    => $SENDER,
		'recipient' => $recipient,
		'key_id'    => 'testkey',
		'nonce'     => DirectProtocol::newNonce(),
		'timestamp' => gmdate('Y-m-d H:i:s'),
	);
}

function direct_test_manifest(string $body): array {
	return array(array('role' => DirectProtocol::ROLE_BODY_TEXT,
		'content_type' => 'text/plain', 'filename' => '', 'content_id' => '',
		'is_inline' => false, 'size' => strlen($body)));
}

// The loopback context: the caller asserts a verified domain, standing in for
// the signature check the wire performs. Everything AFTER that point is the
// real framework.
$LOOPBACK = array('verified_domain' => 'sender-test.invalid');

// Direct is off by default, which is itself the first thing worth asserting:
// a deployment that has not turned it on must be indistinguishable from one
// that never heard of the channel.
section('While Direct is off, the endpoint is indistinguishable from one that never heard of it');

/**
 * Flip the enable switch for this run.
 *
 * The setting is written to the row AND into the settings singleton's in-memory
 * copy, because get_setting() caches a value it has already read and this test
 * reads it before flipping it.
 */
function direct_test_enable(string $value): void {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare("INSERT INTO stg_settings (stg_name, stg_value, stg_create_time, stg_update_time)
		VALUES ('joinery_direct_enabled', ?, now(), now())
		ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value");
	$stmt->execute(array($value));
	$settings = new ReflectionProperty('Globalvars', 'settings');
	$settings->setAccessible(true);
	$live = $settings->getValue(Globalvars::get_instance());
	$live['joinery_direct_enabled'] = $value;
	$settings->setValue(Globalvars::get_instance(), $live);
}

/**
 * Set (or, with null, clear) any Direct setting for this run, in the row AND the
 * settings singleton's cached copy, so a following read sees it.
 */
function direct_test_set_raw(string $name, ?string $value): void {
	$db = DbConnector::get_instance()->get_db_link();
	if ($value === null) {
		$db->prepare('DELETE FROM stg_settings WHERE stg_name = ?')->execute(array($name));
	} else {
		$db->prepare("INSERT INTO stg_settings (stg_name, stg_value, stg_create_time, stg_update_time)
			VALUES (?, ?, now(), now()) ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value")
			->execute(array($name, $value));
	}
	$prop = new ReflectionProperty('Globalvars', 'settings');
	$prop->setAccessible(true);
	$live = $prop->getValue(Globalvars::get_instance());
	if ($value === null) { unset($live[$name]); } else { $live[$name] = $value; }
	$prop->setValue(Globalvars::get_instance(), $live);
}

$was_enabled = DirectSettings::enabled();
$receiver = new DirectReceiver();
if (!$was_enabled) {
	$off = $receiver->preflight(direct_test_envelope('x@' . $TEST_DOMAIN), direct_test_manifest('hi'), $LOOPBACK);
	check($off['answer'] === 'refused' && (int)$off['status'] === 404,
		'a preflight to a deployment with Direct off is a plain not-found');
} else {
	check(true, 'Direct is already on here, so the off-state assertion does not apply');
}
direct_test_enable('1');
harness_defer(function () use ($was_enabled) { direct_test_enable($was_enabled ? '1' : '0'); });
check(DirectSettings::enabled(), 'the channel is on for the rest of this run');

// ---------------------------------------------------------------------------
section('A complete delivery at Standard: live gate, live ingest');
// ---------------------------------------------------------------------------

direct_test_setup(false);
$receiver = new DirectReceiver();
$body = 'the quick brown fox';
$envelope = direct_test_envelope($REAL);
$manifest = direct_test_manifest($body);

$answer = $receiver->preflight($envelope, $manifest, $LOOPBACK);
check($answer['answer'] === DirectProtocol::ANSWER_ACCEPT, 'a contact-gated recipient accepts');
check(DirectTestHandler::$gate_calls === 1, 'the handler\'s gate ran exactly once, at receive');
check(!isset($answer['key']),
	'no key is offered at Standard — the mailbox stores plaintext and ingest runs live');

check($receiver->acceptPart($envelope['nonce'], 0, $body), 'the part is taken');
$committed = $receiver->commit($envelope['nonce'], array(DirectProtocol::hashBytes($body)), false, 0, $LOOPBACK);
check($committed, 'the commit redeems the session');
check(count(DirectTestHandler::$ingested) === 1, 'the kind ingested exactly one delivery');
check(DirectTestHandler::$ingested[0]['bodies'][0] === $body, 'and got the bytes that were sent');
check(DirectTestHandler::$ingested[0]['accepted'] === true, 'carrying the gate\'s outcome');
check(DirectTestHandler::$ingested[0]['alias_id'] === 1 && DirectTestHandler::$ingested[0]['domain_id'] === 1,
	'and the recipient identity resolved at accept survived to ingest — the delivery files into its mailbox, not Unmatched');

// The spool row is done and its parts released — the staging store is not a
// second copy of every message ever delivered.
$done = new MultiDirectSpool(array('nonce' => $envelope['nonce']));
$done->load();
$row = null;
foreach ($done as $r) { $row = $r; }
check($row !== null && (string)$row->get('jdp_state') === DirectSpool::STATE_DONE,
	'the staging row is marked done');
check($row !== null && count(DirectSpoolPart::forSpool(intval($row->key))) === 0,
	'and its parts are released, so a delivered message is not held twice');
if ($row !== null) { harness_defer(function () use ($row) { try { $row->permanent_delete(); } catch (Throwable $e) {} }); }

// ---------------------------------------------------------------------------
section('Replay is closed at both steps');
// ---------------------------------------------------------------------------

$replay = $receiver->preflight($envelope, $manifest, $LOOPBACK);
check($replay['answer'] === 'refused' && (int)$replay['status'] === 409,
	'the same nonce a second time is a REQUEST-LEVEL refusal, not one of the gate\'s two answers');

$again = $receiver->commit($envelope['nonce'], array(DirectProtocol::hashBytes($body)), false, 0, $LOOPBACK);
check(!$again, 'a captured transfer replayed after delivery redeems nothing');

check(!$receiver->acceptPart($envelope['nonce'], 0, $body),
	'and no further part can be pushed onto the consumed session');

// ---------------------------------------------------------------------------
section('The admitted manifest is the transfer-time contract');
// ---------------------------------------------------------------------------

direct_test_setup(false);
$receiver = new DirectReceiver();
$e2 = direct_test_envelope($REAL);
$receiver->preflight($e2, direct_test_manifest('small'), $LOOPBACK);
check(!$receiver->acceptPart($e2['nonce'], 0, 'very much larger than declared'),
	'a part bigger than its declared size aborts the delivery');
check(!$receiver->commit($e2['nonce'], array(DirectProtocol::hashBytes('small')), false, 0, $LOOPBACK),
	'and the burned session cannot then be committed');

$e3 = direct_test_envelope($REAL);
$receiver->preflight($e3, direct_test_manifest('small'), $LOOPBACK);
check(!$receiver->acceptPart($e3['nonce'], 4, 'small'),
	'a part index the manifest never declared is refused');

// ---------------------------------------------------------------------------
section('A substituted part is rejected against its signed hash');
// ---------------------------------------------------------------------------

direct_test_setup(false);
$receiver = new DirectReceiver();
$e4 = direct_test_envelope($REAL);
$receiver->preflight($e4, direct_test_manifest('honest'), $LOOPBACK);
$receiver->acceptPart($e4['nonce'], 0, 'FORGED');
check(!$receiver->commit($e4['nonce'], array(DirectProtocol::hashBytes('honest')), false, 0, $LOOPBACK),
	'bytes that do not match the sender\'s signed hash reject the ENTIRE message');
check(count(DirectTestHandler::$ingested) === 0,
	'and nothing reaches the kind, so nothing is ever stamped verified on substituted content');

// ---------------------------------------------------------------------------
section('Request-level refusals stay in their own indistinguishable bucket');
// ---------------------------------------------------------------------------

direct_test_setup(false);
$receiver = new DirectReceiver();

$unserved = $receiver->preflight(direct_test_envelope($REAL, 'no_such_kind'), direct_test_manifest('x'), $LOOPBACK);
check($unserved['answer'] === 'refused' && (int)$unserved['status'] === 404,
	'a kind this instance does not serve is refused before any handler runs');
check(DirectTestHandler::$gate_calls === 0, 'and no gate was consulted for it');

$old_version = $receiver->preflight(direct_test_envelope($REAL, 'testkind', 99), direct_test_manifest('x'), $LOOPBACK);
check($old_version['answer'] === 'refused',
	'an unimplemented protocol version is refused cleanly — a partly-upgraded federation degrades, never breaks');

$stale = direct_test_envelope($REAL);
$stale['timestamp'] = gmdate('Y-m-d H:i:s', time() - 3600);
$stale_answer = $receiver->preflight($stale, direct_test_manifest('x'), $LOOPBACK);
check($stale_answer['answer'] === 'refused',
	'an envelope older than the freshness window is refused');

$future = direct_test_envelope($REAL);
$future['timestamp'] = gmdate('Y-m-d H:i:s', time() + 3600);
check($receiver->preflight($future, direct_test_manifest('x'), $LOOPBACK)['answer'] === 'refused',
	'and so is one dated well into the future');

$foreign = $receiver->preflight(direct_test_envelope('someone@not-hosted.invalid'), direct_test_manifest('x'), $LOOPBACK);
check($foreign['answer'] === 'refused' && (int)$foreign['status'] === 404,
	'a domain this deployment does not host is a fact about the deployment, so it refuses at request level');

// A nonce is a hex token no wider than the replay column, exactly as the relay's
// claimNonce requires — a non-hex or oversized one is refused identically, so PHP
// and Go reject the same set rather than PHP truncating two nonces to one row.
$bad_nonce = direct_test_envelope($REAL);
$bad_nonce['nonce'] = 'not a hex nonce!!';
check($receiver->preflight($bad_nonce, direct_test_manifest('x'), $LOOPBACK)['answer'] === 'refused',
	'a non-hex nonce is refused');
$long_nonce = direct_test_envelope($REAL);
$long_nonce['nonce'] = str_repeat('a', 65);
check($receiver->preflight($long_nonce, direct_test_manifest('x'), $LOOPBACK)['answer'] === 'refused',
	'and one wider than the replay column is refused');

$oversized = direct_test_envelope($REAL);
$huge = array(array('role' => DirectProtocol::ROLE_ATTACHMENT, 'content_type' => 'application/octet-stream',
	'filename' => 'big', 'content_id' => '', 'is_inline' => false,
	'size' => DirectSettings::maxBytesPerPart() + 1));
$over = $receiver->preflight($oversized, $huge, $LOOPBACK);
check($over['answer'] === 'refused' && (int)$over['status'] === 413,
	'a manifest over the per-part cap is refused at preflight, with no content transferred');

$too_many = array();
for ($i = 0; $i <= DirectSettings::maxParts(); $i++) {
	$too_many[] = array('role' => DirectProtocol::ROLE_ATTACHMENT, 'content_type' => 'text/plain',
		'filename' => 'p' . $i, 'content_id' => '', 'is_inline' => false, 'size' => 1);
}
check($receiver->preflight(direct_test_envelope($REAL), $too_many, $LOOPBACK)['answer'] === 'refused',
	'and so is one declaring more parts than the cap allows');

// ---------------------------------------------------------------------------
section('At Standard, a stranger and a nonexistent address answer identically');
// ---------------------------------------------------------------------------

direct_test_setup(false);
$receiver = new DirectReceiver();
DirectTestHandler::$gate_answer = false;

$stranger = $receiver->preflight(direct_test_envelope($REAL), direct_test_manifest('x'), $LOOPBACK);
check($stranger['answer'] === DirectProtocol::ANSWER_DECLINED,
	'a real address whose gate declines answers declined');

$nowhere = $receiver->preflight(direct_test_envelope($ABSENT), direct_test_manifest('x'), $LOOPBACK);
check($nowhere['answer'] === DirectProtocol::ANSWER_DECLINED,
	'an address that does not exist answers declined too');
check($stranger === $nowhere,
	'the two answers are byte-identical — nothing in the exchange reveals which addresses are real');

// ---------------------------------------------------------------------------
section('At a sealed tier: unconditional accept, a key for every address, deferred gate');
// ---------------------------------------------------------------------------

direct_test_setup(true);
$receiver = new DirectReceiver();
DirectTestHandler::$gate_answer = false; // would decline, if it were ever asked

$sealed_real = $receiver->preflight(direct_test_envelope($REAL), direct_test_manifest('x'), $LOOPBACK);
check($sealed_real['answer'] === DirectProtocol::ANSWER_ACCEPT,
	'a sealed-tier receiver accepts even when the gate would decline — acceptance discloses nothing');
check(DirectTestHandler::$gate_calls === 0,
	'the handler\'s gate is NOT called at receive here; it cannot tell which moment it runs in');
check(!empty($sealed_real['key']), 'and a key comes back');

$sealed_absent = $receiver->preflight(direct_test_envelope($ABSENT), direct_test_manifest('x'), $LOOPBACK);
check($sealed_absent['answer'] === DirectProtocol::ANSWER_ACCEPT,
	'a nonexistent address accepts identically');
check(!empty($sealed_absent['key']), 'with a key of its own — a decoy, indistinguishable from a real one');
check(strlen((string)$sealed_absent['key']) === strlen((string)$sealed_real['key']),
	'the two keys are the same shape on the wire');
check((string)$sealed_absent['key'] !== (string)$sealed_real['key'],
	'and they are different keys — the decoy is not a fixed value anyone could recognise');
check(array_keys($sealed_real) === array_keys($sealed_absent),
	'and the two answers carry the same fields, so existence is unknowable from the exchange');

// The real address's delivery is HELD rather than ingested: the gate has not run.
$sealed_body = 'held until unlock';
$sealed_envelope = direct_test_envelope($REAL);
$receiver->preflight($sealed_envelope, direct_test_manifest($sealed_body), $LOOPBACK);
$receiver->acceptPart($sealed_envelope['nonce'], 0, $sealed_body);
check($receiver->commit($sealed_envelope['nonce'], array(DirectProtocol::hashBytes($sealed_body)), false, 0, $LOOPBACK),
	'the sealed-tier delivery commits');
check(count(DirectTestHandler::$ingested) === 0,
	'but nothing is ingested at receive — authorization waits for the recipient\'s unlock');

$held = new MultiDirectSpool(array('nonce' => $sealed_envelope['nonce']));
$held->load();
$held_row = null;
foreach ($held as $r) { $held_row = $r; }
check($held_row !== null && (string)$held_row->get('jdp_state') === DirectSpool::STATE_HELD,
	'the delivery is held in the Direct spool');
check($held_row !== null && intval($held_row->get('jdp_bytes')) > 0,
	'and its declared bytes are charged against the spool caps');
check($held_row !== null && intval($held_row->get('jdp_recipient_alias_id')) === 1
	&& intval($held_row->get('jdp_recipient_domain_id')) === 1,
	'and the recipient identity is stored on the held row, to reach ingest at unlock');
if ($held_row !== null) {
	harness_defer(function () use ($held_row) { try { $held_row->permanent_delete(); } catch (Throwable $e) {} });
}

// ---------------------------------------------------------------------------
section('A decoy delivery keeps its byte charge, so a full spool is not an existence oracle');
// ---------------------------------------------------------------------------

// The decoy's declared bytes must go on counting against the caps exactly as a real
// held delivery's do — otherwise a nonexistent address, whose delivery uncharged at
// commit, would never fill a spool while a real one did, and the 507 would tell a
// prober which addresses exist. The charge lives on a HELD row reclaimed only by the
// ordinary retention sweep, like a real delivery nobody ever unlocks for.
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
direct_test_setup(true);
$receiver = new DirectReceiver();

$decoy_body = 'sealed to a decoy nobody can ever open';
$decoy_env = direct_test_envelope($ABSENT);
$decoy_pre = $receiver->preflight($decoy_env, direct_test_manifest($decoy_body), $LOOPBACK);
check($decoy_pre['answer'] === DirectProtocol::ANSWER_ACCEPT && !empty($decoy_pre['key']),
	'a nonexistent address accepts with a decoy key');
$decoy_sealed = (new VaultCrypto())->sealBulkDelivery($decoy_body, (string)$decoy_pre['key']);
$receiver->acceptPart($decoy_env['nonce'], 0, $decoy_sealed);
check($receiver->commit($decoy_env['nonce'], array(DirectProtocol::hashBytes($decoy_sealed)), true,
		(int)($decoy_pre['key_generation'] ?? 1), $LOOPBACK),
	'the decoy delivery commits like any other — the sender cannot tell it went nowhere');

$decoy_rows = new MultiDirectSpool(array('nonce' => $decoy_env['nonce']));
$decoy_rows->load();
$decoy_row = null;
foreach ($decoy_rows as $r) { $decoy_row = $r; }
if ($decoy_row !== null) {
	harness_defer(function () use ($decoy_row) { try { $decoy_row->permanent_delete(); } catch (Throwable $e) {} });
}
check($decoy_row !== null && (string)$decoy_row->get('jdp_state') === DirectSpool::STATE_HELD,
	'the decoy row is HELD, not discarded — its charge lives on like a real never-unlocked delivery');
check($decoy_row !== null && intval($decoy_row->get('jdp_bytes')) > 0,
	'its declared bytes still count against the caps, so a nonexistent address fills a spool as a real one does');
check($decoy_row !== null && count(DirectSpoolPart::forSpool(intval($decoy_row->key))) === 0,
	'while its useless sealed-to-decoy parts are released to reclaim the disk');
check(DirectSpool::bytesForAddress($ABSENT) > 0,
	'and the nonexistent address carries a real charge against its per-address cap');

// ---------------------------------------------------------------------------
section('Sealed parts stay sealed until the recipient unlocks, and the gate runs then');
// ---------------------------------------------------------------------------

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSpoolService.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));

direct_test_setup(true);
$receiver = new DirectReceiver();
DirectTestHandler::$gate_answer = true;

$secret_text = 'nobody in the path may read this';
$seal_envelope = direct_test_envelope($REAL);
$accepted = $receiver->preflight($seal_envelope, direct_test_manifest($secret_text), $LOOPBACK);
check($accepted['answer'] === DirectProtocol::ANSWER_ACCEPT, 'the sealed-tier preflight accepts');

// The SENDER seals, which is the whole point: no machine between the two
// endpoints — proxy, CDN, or relay — ever holds plaintext.
$ciphertext = (new VaultCrypto())->sealBulkDelivery($secret_text, (string)$accepted['key']);
check(strpos($ciphertext, $secret_text) === false, 'the bytes that cross the wire are not the plaintext');

// The manifest declares the PLAINTEXT length — it is written before the key
// exists — and sealing grows it. A receiver that offered a key allows for that
// growth, or every honest sealed delivery would be aborted for arriving exactly
// as it was asked to.
check(strlen($ciphertext) > strlen($secret_text), 'sealing grows a part');
check(strlen($ciphertext) <= DirectProtocol::sealedSizeCeiling(strlen($secret_text)),
	'and the growth is bounded by a ceiling the receiver can compute from the declaration alone');

$seal_envelope2 = direct_test_envelope($REAL);
$sealed_manifest = direct_test_manifest($secret_text);
$accepted2 = $receiver->preflight($seal_envelope2, $sealed_manifest, $LOOPBACK);
$ciphertext2 = (new VaultCrypto())->sealBulkDelivery($secret_text, (string)$accepted2['key']);
check($receiver->acceptPart($seal_envelope2['nonce'], 0, $ciphertext2),
	'the sealed part is taken against a manifest that declared the plaintext size');
check($receiver->commit($seal_envelope2['nonce'], array(DirectProtocol::hashBytes($ciphertext2)), true, 1, $LOOPBACK),
	'and the commit verifies the hash of the SEALED bytes — checkable without unsealing');
check(count(DirectTestHandler::$ingested) === 0, 'nothing is ingested while the vault is closed');

$sealed_spool = new MultiDirectSpool(array('nonce' => $seal_envelope2['nonce']));
$sealed_spool->load();
$sealed_row = null;
foreach ($sealed_spool as $r) { $sealed_row = $r; }
check($sealed_row !== null, 'the delivery is held');
if ($sealed_row !== null) {
	harness_defer(function () use ($sealed_row) { try { $sealed_row->permanent_delete(); } catch (Throwable $e) {} });
	$stored_parts = DirectSpoolPart::forSpool(intval($sealed_row->key));
	check(count($stored_parts) === 1 && (bool)$stored_parts[0]->get('jda_is_sealed'),
		'and its part is recorded as sealed');
	check(strpos($stored_parts[0]->bytes(), $secret_text) === false,
		'the plaintext is nowhere in the held delivery');

	// The drain: the gate runs now, against the list that has just become
	// readable, and ingest gets the recipient's in-window secret.
	DirectSpoolService::ingest($sealed_row, true, $RECIPIENT_VAULT['secret']);
	check(count(DirectTestHandler::$ingested) === 1, 'the kind ingests once the recipient unlocks');
	check(DirectTestHandler::$ingested[0]['bodies'][0] === $secret_text,
		'and only then does the plaintext exist');
	check(DirectTestHandler::$ingested[0]['alias_id'] === 1 && DirectTestHandler::$ingested[0]['domain_id'] === 1,
		'and the recipient identity survived the spool round-trip — the deferred delivery files into its mailbox');
}

// A deferred DECLINE is a local disposition, never a drop and never a signal
// back to a sender who is long gone.
DirectTestHandler::$ingested = array();
if ($sealed_row !== null) {
	DirectSpoolService::ingest($sealed_row, false, $RECIPIENT_VAULT['secret']);
	check(count(DirectTestHandler::$ingested) === 1,
		'a deferred decline still reaches ingest — the message is filed, not dropped');
	check(DirectTestHandler::$ingested[0]['accepted'] === false,
		'carrying the decline, so the kind can file it where the ordinary path would have');
}

// ---------------------------------------------------------------------------
section('A sealing domain but an UNENCRYPTED mailbox: accept uniformly, gate at commit, never held');
// ---------------------------------------------------------------------------

// A group alias or a vaultless owner on a sealing domain has a plaintext address
// book and no unlock coming. It must keep the sealed-tier wire posture — accept,
// no live decline — but be gated at COMMIT and filed then, never parked for an
// unlock that will never fire (which is silent data loss).
direct_test_setup(true, array(), false); // sealing domain, recipient holds NO vault
$receiver = new DirectReceiver();

$nv_pre = $receiver->preflight(direct_test_envelope($REAL), direct_test_manifest('x'), $LOOPBACK);
check($nv_pre['answer'] === DirectProtocol::ANSWER_ACCEPT,
	'an unencrypted mailbox on a sealing domain still accepts unconditionally');
check(!isset($nv_pre['key']),
	'with no key — the parts cross plaintext under TLS, as this mailbox stores them anyway');
check(DirectTestHandler::$gate_calls === 0,
	'and the gate does not run at receive — the wire answer discloses nothing');

// A contact (gate says yes): committed and INGESTED at commit, not held.
DirectTestHandler::$gate_answer = true;
$nv_body = 'delivered, not parked';
$nv_env = direct_test_envelope($REAL);
$receiver->preflight($nv_env, direct_test_manifest($nv_body), $LOOPBACK);
$receiver->acceptPart($nv_env['nonce'], 0, $nv_body);
check($receiver->commit($nv_env['nonce'], array(DirectProtocol::hashBytes($nv_body)), false, 0, $LOOPBACK),
	'the delivery commits');
check(count(DirectTestHandler::$ingested) === 1,
	'and is ingested at commit — the gate ran against the plaintext book, no unlock needed');
check(DirectTestHandler::$ingested[0]['accepted'] === true, 'carrying the gate\'s accept');
$nv_held = new MultiDirectSpool(array('nonce' => $nv_env['nonce'], 'state' => DirectSpool::STATE_HELD));
$nv_held->load();
check(count($nv_held) === 0, 'nothing is left held — an unencrypted mailbox is never parked for an unlock');

// A stranger (gate says no): still filed, not lost and not bounced.
DirectTestHandler::$ingested = array();
DirectTestHandler::$gate_answer = false;
$nv_env2 = direct_test_envelope($REAL);
$receiver->preflight($nv_env2, direct_test_manifest($nv_body), $LOOPBACK);
$receiver->acceptPart($nv_env2['nonce'], 0, $nv_body);
$receiver->commit($nv_env2['nonce'], array(DirectProtocol::hashBytes($nv_body)), false, 0, $LOOPBACK);
check(count(DirectTestHandler::$ingested) === 1 && DirectTestHandler::$ingested[0]['accepted'] === false,
	'a stranger\'s message is still filed at commit, carrying the decline — filed, never dropped');

// ---------------------------------------------------------------------------
section('An abandoned delivery is reclaimed, so its bytes stop being charged');
// ---------------------------------------------------------------------------

// A sender that preflights and then never transfers leaves a staging row holding
// a charge against the spool caps. Without reclamation, a peer doing only that
// would fill a recipient's cap with nothing — a denial of service that costs the
// attacker one request per slot and never delivers a byte.
direct_test_setup(true);
$receiver = new DirectReceiver();
$abandoned_envelope = direct_test_envelope($REAL);
$receiver->preflight($abandoned_envelope, direct_test_manifest('never sent'), $LOOPBACK);

$before = DirectSpool::bytesForAddress($REAL);
check($before > 0, 'an accepted-but-untransferred delivery is charged against the address cap');

// Age the session out, which is what the TTL does in the ordinary course.
$db = DbConnector::get_instance()->get_db_link();
$db->prepare('UPDATE jds_direct_sessions SET jds_expires_time = now() - interval \'1 minute\'
	WHERE jds_nonce = ?')->execute(array($abandoned_envelope['nonce']));

$swept = DirectSpool::purgeSpool(30);
check(is_array($swept) && isset($swept['removed']) && isset($swept['message']),
	'the sweep returns the platform retention contract');
check($swept['removed'] >= 1, 'and reclaims the abandoned delivery', json_encode($swept));
check(DirectSpool::bytesForAddress($REAL) < $before,
	'so its charge against the cap is released');

$gone = new MultiDirectSpool(array('nonce' => $abandoned_envelope['nonce']));
$gone->load();
check(count($gone) === 0, 'and the staging row itself is gone, parts included');

// ---------------------------------------------------------------------------
section('The replay cache and the session store are opaque and self-expiring');
// ---------------------------------------------------------------------------

$nonce = DirectProtocol::newNonce();
check(DirectNonce::claim($nonce), 'a fresh nonce is claimable');
check(!DirectNonce::claim($nonce), 'the same one is not claimable twice');
check(DirectNonce::TTL_SECONDS > DirectProtocol::MAX_AGE_SECONDS,
	'the replay cache outlives the freshness window, so the two checks compose with no gap between them');

check(DirectSession::redeem('a-nonce-that-was-never-opened') === null,
	'redeeming an unknown nonce yields nothing — unknown, expired and consumed are one answer');

// ---------------------------------------------------------------------------
section('Replay state is reclaimed even when held deliveries are kept forever');
// ---------------------------------------------------------------------------

// The replay nonces and delivery sessions expire on their own short TTLs and must
// be swept every pass. Their cleanup used to ride the held-delivery retention
// rule, so setting that window to 0 — a valid "hold indefinitely" choice — turned
// the whole sweep off and grew the replay tables without bound. purgeSpool is now
// windowless and reads the held window itself.
$b7_db = DbConnector::get_instance()->get_db_link();
$b7_retention_prior = $b7_db->query(
	"SELECT stg_value FROM stg_settings WHERE stg_name = 'joinery_direct_spool_retention_days'")->fetchColumn();
harness_defer(function () use ($b7_retention_prior) {
	direct_test_set_raw('joinery_direct_spool_retention_days',
		$b7_retention_prior === false ? null : (string)$b7_retention_prior);
});

$b7_nonce = 'b7' . bin2hex(random_bytes(16));
$b7_db->prepare("INSERT INTO jdn_direct_nonces (jdn_nonce, jdn_expires_time)
	VALUES (?, now() - interval '1 hour')")->execute(array($b7_nonce));
direct_test_set_raw('joinery_direct_spool_retention_days', '0');

DirectSpool::purgeSpool();

$b7_check = $b7_db->prepare('SELECT count(*) FROM jdn_direct_nonces WHERE jdn_nonce = ?');
$b7_check->execute(array($b7_nonce));
check((int)$b7_check->fetchColumn() === 0,
	'an expired replay nonce is reclaimed even with the held-delivery window at 0');

// ---------------------------------------------------------------------------
section('An unreadable decoy secret fails closed instead of silently rotating every decoy');
// ---------------------------------------------------------------------------

// A stored decoy secret that will not decrypt (a lost or rotated SecretBox key)
// must refuse, never re-mint: re-minting rotates every decoy at once, and a decoy
// that changes between two probes of one address is the exact existence tell the
// mechanism exists to close.
$secret_db = DbConnector::get_instance()->get_db_link();
$decoy_prior = $secret_db->query(
	"SELECT stg_value FROM stg_settings WHERE stg_name = 'joinery_direct_decoy_secret'")->fetchColumn();
harness_defer(function () use ($decoy_prior) {
	direct_test_set_raw('joinery_direct_decoy_secret', $decoy_prior === false ? null : (string)$decoy_prior);
});

direct_test_set_raw('joinery_direct_decoy_secret', 'not-valid-secretbox-ciphertext');
$decoy_threw = false;
try { DirectSettings::decoySecret(); } catch (\Throwable $e) { $decoy_threw = true; }
check($decoy_threw, 'decoySecret refuses an unreadable stored secret rather than deriving a new one');

$decoy_after = $secret_db->query(
	"SELECT stg_value FROM stg_settings WHERE stg_name = 'joinery_direct_decoy_secret'")->fetchColumn();
check((string)$decoy_after === 'not-valid-secretbox-ciphertext',
	'and it did not overwrite the stored secret, so no decoy rotated', (string)$decoy_after);

harness_finish();
