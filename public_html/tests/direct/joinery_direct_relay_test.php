<?php
/** @joinery-test
 * name: joinery_direct_relay
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * The Fortress relay path, on the box's side of it.
 *
 * At Fortress the wire terminates at the relay, so the box never sees a
 * preflight. Two things cross the boundary instead, and both are checked here:
 *
 *   - **outbound**, the relay map fragment. Everything the relay needs to serve
 *     the channel travels as DATA — the served-kind list it compares as opaque
 *     strings, the decoy secret, the limits and caps. That is what makes a new
 *     payload kind a map update rather than a fleet upgrade, so a fragment that
 *     quietly stopped carrying it would strand every Fortress tenant on the
 *     next relay release.
 *   - **inbound**, the `.direct` container the relay writes to the spool and the
 *     pull brings across. It must land in the SAME Direct spool a locally
 *     accepted sealed-tier delivery lands in, because that is the one place the
 *     no-bounce, held-plugin and decline-is-local rules live. Two deferred paths
 *     would mean two places to keep them.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectRecipients.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectRelayIngest.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/JoineryDirect.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_parts_class.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectHandler.php'));

/** A stub kind handler, so the gate-at-commit relay path has something to ingest into. */
class RelayTestHandler implements DirectKindHandler {
	public static $ingested = array();
	public function gate(DirectEnvelope $envelope): bool { return true; }
	public function ingest(DirectEnvelope $envelope, array $parts, bool $gate_accepted): void {
		$bodies = array();
		foreach ($parts as $part) { $bodies[] = $part->open($envelope->vaultSecretKey()); }
		self::$ingested[] = array(
			'recipient' => $envelope->recipient(), 'accepted' => $gate_accepted,
			'alias_id' => $envelope->recipientAliasId(), 'bodies' => $bodies,
		);
	}
}

// ---------------------------------------------------------------------------
section('The relay never has to be taught a kind');
// ---------------------------------------------------------------------------

// The relay compares the envelope's kind against a list it was GIVEN. Nothing in
// the Go binary interprets a kind, so a plugin shipping one reaches a Fortress
// tenant through a map push. If this ever became code, every new kind would need
// a relay release and a fleet upgrade before Fortress tenants could use it.
$exporter_source = (string)file_get_contents(
	PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapExporter.php'));
check(strpos($exporter_source, 'direct_kinds') !== false,
	'the fragment carries the served-kind list');
check(strpos($exporter_source, 'direct_decoy_secret') !== false,
	'and the decoy secret, so the relay answers a nonexistent address the way the box would');
foreach (array('direct_preflight_limit', 'direct_max_part_bytes',
		'direct_spool_domain_cap_bytes', 'direct_spool_address_cap_bytes') as $key) {
	check(strpos($exporter_source, $key) !== false,
		'and ' . $key . ', so the cap is enforced at the edge rather than after the box is touched');
}

$relay_source = (string)file_get_contents(PathHelper::getIncludePath(
	'plugins/mailbox/provisioning/relay-sealer/direct_handler.go'));
// The default kind lives in the shared kindOrDefault helper (direct_protocol.go),
// so both files count when asking what kinds the relay names literally.
$relay_source .= (string)file_get_contents(PathHelper::getIncludePath(
	'plugins/mailbox/provisioning/relay-sealer/direct_protocol.go'));
check(strpos($relay_source, 'tc.servesKind(kind)') !== false,
	'the relay compares the kind against tenant data rather than a hard-coded list');
check(strpos($relay_source, '"mail"') !== false && substr_count($relay_source, '"chat"') === 0,
	'and names no kind but the default, so a new one needs no relay change');

// ---------------------------------------------------------------------------
section('The relay signs nothing');
// ---------------------------------------------------------------------------

// The instance signing key never leaves the box. A relay that could sign as its
// tenant is a far stronger position than one that can only forward, and the
// whole custody argument rests on it not having one.
$serve_source = (string)file_get_contents(PathHelper::getIncludePath(
	'plugins/mailbox/provisioning/relay-sealer/direct_serve.go'));
$crypto_source = (string)file_get_contents(PathHelper::getIncludePath(
	'plugins/mailbox/provisioning/relay-sealer/direct_crypto.go'));
check(strpos($crypto_source, 'ed25519.Sign(') === false,
	'the relay has no signing call at all');
check(strpos($crypto_source, 'ed25519.Verify') !== false,
	'only verification, which needs no secret and no vault');
check(strpos($serve_source, 'X-Joinery-Direct-Target') !== false,
	'egress transports a request the box built, named by a header');

// ---------------------------------------------------------------------------
section('The egress client is only used by a relay-fronted deployment');
// ---------------------------------------------------------------------------

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DirectRelayEgress.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
VaultUnlock::loadConsumerBootstraps();

// This deployment is not relay-fronted, so Direct must send from the box —
// exactly as it did before the relay path existed.
check(DirectRelayEgress::forDeployment() === null,
	'with no relay enabled the egress resolver yields nothing, so requests go out from the box');

// ---------------------------------------------------------------------------
section('A relayed delivery lands in the ordinary Direct spool');
// ---------------------------------------------------------------------------

$TEST_DOMAIN = 'relay-direct-test.invalid';
$REAL  = 'real@' . $TEST_DOMAIN;   // an ENCRYPTED mailbox (holds a vault key) — held for unlock
$GROUP = 'group@' . $TEST_DOMAIN;  // an UNENCRYPTED mailbox (group alias, no vault) — gated at commit

// A stand-in recipient vault public key, so $REAL reads as an encrypted mailbox
// the way A2 distinguishes them (a non-null vault key => defer to unlock).
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
$ENCRYPTED_VAULT_KEY = (new SealedBox())->generateKeypair()['public'];

DirectRecipients::resetForTests();
DirectRecipients::registerResolver(function (string $address) use ($TEST_DOMAIN, $REAL, $GROUP, $ENCRYPTED_VAULT_KEY) {
	if (DirectProtocol::domainOf($address) !== $TEST_DOMAIN) {
		return null;
	}
	$addr = strtolower($address);
	if ($addr === $REAL) {
		return array(
			'hosts_domain' => true, 'domain_id' => 1, 'seals_content' => true, 'exists' => true,
			'user_id' => 1, 'alias_id' => 1,
			'vault_public_key' => $ENCRYPTED_VAULT_KEY, 'key_generation' => 3,
		);
	}
	if ($addr === $GROUP) {
		// Real, but no single vault — an unencrypted mailbox with a plaintext book.
		return array(
			'hosts_domain' => true, 'domain_id' => 1, 'seals_content' => true, 'exists' => true,
			'user_id' => 0, 'alias_id' => 2,
			'vault_public_key' => null, 'key_generation' => 0,
		);
	}
	return array(
		'hosts_domain' => true, 'domain_id' => 1, 'seals_content' => true, 'exists' => false,
		'user_id' => 0, 'alias_id' => 0, 'vault_public_key' => null, 'key_generation' => 0,
	);
});

// Register a kind so the container is servable, the way a plugin's declaration
// would. The registry is instance configuration, so it is set the same way.
$registry = new ReflectionProperty('DirectKinds', 'registry');
$registry->setAccessible(true);
$registry->setValue(null, array(
	'mail' => array('kind' => 'mail', 'handler' => '', 'class' => '', 'gate' => 'contacts', 'plugin' => ''),
));

// The relay forwards the sender's OWN signatures; the box re-verifies them against
// the sender domain's key rather than trusting the relay. Stand up a signing
// identity for the sender domain and seed its capability record so the box can
// resolve the key with no DNS — exactly what a real lookup would return.
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectCapability.php'));
require_once(PathHelper::getIncludePath('data/direct_capability_cache_class.php'));

$SENDER_DOMAIN = 'sender-test.invalid';
$sender_identity = DirectSigningIdentity::ensureFor($SENDER_DOMAIN);
$SENDER_KEY_ID = (string)$sender_identity->get('jdi_key_id');
harness_defer(function () use ($sender_identity) { try { $sender_identity->permanent_delete(); } catch (Throwable $e) {} });
DirectSigningIdentity::resetForTests();

$cap_row = DirectCapabilityCache::forDomain($SENDER_DOMAIN);
if ($cap_row === null) { $cap_row = new DirectCapabilityCache(NULL); }
$cap_row->set('jdc_domain', $SENDER_DOMAIN);
$cap_row->set('jdc_has_capability', true);
$cap_row->set('jdc_host', 'relay.' . $SENDER_DOMAIN);
$cap_row->set('jdc_port', 443);
$cap_row->set('jdc_keys', json_encode(array($SENDER_KEY_ID => (string)$sender_identity->get('jdi_public_key'))));
$cap_row->set('jdc_expires_time', gmdate('Y-m-d H:i:s', time() + 3600));
$cap_row->set('jdc_update_time', gmdate('Y-m-d H:i:s'));
$cap_row->save();
harness_defer(function () use ($cap_row) { try { $cap_row->permanent_delete(); } catch (Throwable $e) {} });
DirectCapability::resetForTests();

// Assemble a container the way the relay would — the sender's signatures over the
// preflight and the transfer, forwarded verbatim for the box to re-verify.
$make_container = function (string $body, string $nonce, array $over = array())
		use ($SENDER_DOMAIN, $SENDER_KEY_ID, $REAL) {
	$sender    = $over['sender'] ?? ('peer@' . $SENDER_DOMAIN);
	$recipient = $over['recipient'] ?? $REAL;
	$kind      = $over['kind'] ?? 'mail';
	$ts        = $over['timestamp'] ?? gmdate('Y-m-d H:i:s');
	$manifest  = DirectProtocol::canonicalManifest(array(array(
		'role' => DirectProtocol::ROLE_BODY_TEXT, 'content_type' => 'text/plain',
		'filename' => '', 'content_id' => '', 'is_inline' => false, 'size' => strlen($body),
	)));
	$envelope = array(
		'protocol_version' => DirectProtocol::PROTOCOL_VERSION, 'kind' => $kind,
		'sender' => $sender, 'recipient' => $recipient, 'key_id' => $SENDER_KEY_ID,
		'nonce' => $nonce, 'timestamp' => $ts,
	);
	$pre  = DirectSigningIdentity::sign($SENDER_DOMAIN, DirectProtocol::preflightSigningBytes($envelope, $manifest));
	$hash = DirectProtocol::hashBytes($body);
	$xfer = DirectSigningIdentity::sign($SENDER_DOMAIN, DirectProtocol::transferSigningBytes($nonce, array($hash)));
	return array(
		'spool_id' => 'test-' . $nonce, 'kind' => $kind,
		'protocol_version' => DirectProtocol::PROTOCOL_VERSION,
		'sender' => $sender, 'verified_sender_domain' => $SENDER_DOMAIN,
		'recipient' => $recipient, 'nonce' => $nonce,
		'sealed' => $over['sealed'] ?? true, 'key_generation' => $over['key_generation'] ?? 3,
		'received_utc' => gmdate('c'), 'key_id' => $SENDER_KEY_ID, 'timestamp' => $ts,
		'signed_manifest' => $manifest,
		'preflight_signature' => $pre['signature'], 'transfer_signature' => $xfer['signature'],
		'parts' => array(array(
			'sequence' => 0, 'role' => DirectProtocol::ROLE_BODY_TEXT,
			'content_type' => 'text/plain', 'filename' => '', 'content_id' => '',
			'is_inline' => false, 'bytes' => strlen($body), 'hash' => $hash,
			'content' => base64_encode($body),
		)),
	);
};

$nonce = DirectProtocol::newNonce();
$body = 'delivered through the relay';
$container = $make_container($body, $nonce);

$outcome = DirectRelayIngest::store($container);
check($outcome === 'pending',
	'a relayed delivery whose forwarded signatures re-verify is stored as pending', $outcome);

$rows = new MultiDirectSpool(array('nonce' => $nonce));
$rows->load();
$spool = null;
foreach ($rows as $row) { $spool = $row; }
check($spool !== null, 'and it is in the ordinary Direct spool, not a second store');

if ($spool !== null) {
	harness_defer(function () use ($spool) { try { $spool->permanent_delete(); } catch (Throwable $e) {} });

	check((string)$spool->get('jdp_state') === DirectSpool::STATE_HELD,
		'HELD rather than staging — the transfer is complete, it is the unlock that is pending');
	check((string)$spool->get('jdp_sender_domain') === $SENDER_DOMAIN,
		'carrying the domain the BOX re-verified against, so a spoofed From cannot borrow a place in the contacts');
	check(intval($spool->get('jdp_key_generation')) === 3,
		'and the key generation the parts were sealed to');
	check(intval($spool->get('jdp_usr_user_id')) === 1,
		'attributed to the user whose unlock will gate it');

	$parts = DirectSpoolPart::forSpool(intval($spool->key));
	check(count($parts) === 1, 'with its part stored');
	if (count($parts) === 1) {
		check((bool)$parts[0]->get('jda_is_sealed'), 'recorded as sealed by the sender');
		check($parts[0]->bytes() === $body, 'and the delivered bytes intact');
		check((string)$parts[0]->get('jda_hash') === DirectProtocol::hashBytes($body),
			'under the hash the box re-verified it against');
	}

	// A re-pull of an un-acked-but-stored delivery must be a no-op, keyed on the
	// same nonce that made the delivery single-use on the wire.
	check(DirectRelayIngest::store($container) === 'dedup',
		'a re-pull of the same delivery stores nothing twice');
}

// ---------------------------------------------------------------------------
section('A relayed delivery to an UNENCRYPTED mailbox is gated at commit, not held');
// ---------------------------------------------------------------------------

// A group alias / vaultless owner has a plaintext book and its parts crossed
// UNSEALED (the relay handed the sender a keyless accept — B18). There is no
// unlock to wait for, so the box gates it now and ingests; holding it, as the
// pre-fix relay path did for every delivery, would strand it forever. Install a
// handler so there is something to ingest into.
$handlers = new ReflectionProperty('DirectKinds', 'handlers');
$handlers->setAccessible(true);
$handlers->setValue(null, array('mail' => new RelayTestHandler()));
RelayTestHandler::$ingested = array();

$group_nonce = DirectProtocol::newNonce();
$group_body  = 'delivered to a group alias through the relay';
$group_container = $make_container($group_body, $group_nonce,
	array('recipient' => $GROUP, 'sealed' => false, 'key_generation' => 0));

check(DirectRelayIngest::store($group_container) === 'pending',
	'the delivery is accepted off the relay');

$group_rows = new MultiDirectSpool(array('nonce' => $group_nonce));
$group_rows->load();
$group_spool = null;
foreach ($group_rows as $r) { $group_spool = $r; }
check($group_spool !== null, 'and a spool row exists');
if ($group_spool !== null) {
	harness_defer(function () use ($group_spool) { try { $group_spool->permanent_delete(); } catch (Throwable $e) {} });
	check((string)$group_spool->get('jdp_state') === DirectSpool::STATE_DONE,
		'DONE, not HELD — an unencrypted mailbox gates at commit and never waits for an unlock that will not come');
	check(intval($group_spool->get('jdp_recipient_alias_id')) === 2,
		'and the recipient alias rode the row, so the gate resolved the right book');
	check(count(DirectSpoolPart::forSpool(intval($group_spool->key))) === 0,
		'its parts are released once ingested');
}
check(count(RelayTestHandler::$ingested) === 1, 'the kind ingested the delivery once, at commit');
if (count(RelayTestHandler::$ingested) === 1) {
	check(RelayTestHandler::$ingested[0]['bodies'][0] === $group_body,
		'with the plaintext body — the parts crossed unsealed');
	check((int)RelayTestHandler::$ingested[0]['alias_id'] === 2,
		'filed into the group alias, not Unmatched');
}

// ---------------------------------------------------------------------------
section('The box re-verifies — a compromised relay cannot forge or tamper');
// ---------------------------------------------------------------------------

// The relay holds no signing key, so it cannot make the sender's signatures say
// anything else. The box proves it by re-checking them, and treats the relay's own
// "verified_sender_domain" as noise.
$lie = $make_container('the relay lies about who signed', DirectProtocol::newNonce());
$lie['verified_sender_domain'] = 'attacker-claimed.invalid';
check(DirectRelayIngest::store($lie) === 'pending',
	'the relay\'s verified_sender_domain claim is ignored — the box derives the sender from the signed envelope');
$lie_rows = new MultiDirectSpool(array('nonce' => $lie['nonce']));
$lie_rows->load();
foreach ($lie_rows as $r) {
	check((string)$r->get('jdp_sender_domain') === $SENDER_DOMAIN,
		'and the stored sender domain is the one the signature actually verified against, not the relay\'s claim');
	harness_defer(function () use ($r) { try { $r->permanent_delete(); } catch (Throwable $e) {} });
}

// Swap a part's content while keeping the signed hash: the content no longer hashes
// to what the sender signed, so the box refuses it.
$swapped = $make_container('the sender wrote this', DirectProtocol::newNonce());
$swapped['parts'][0]['content'] = base64_encode('the relay swapped this in');
check(DirectRelayIngest::store($swapped) === 'unroutable',
	'a part whose content no longer matches its signed hash is refused, not stored');

// Break the transfer signature: any tamper the box cannot reconcile is dropped.
$forged_sig = $make_container('tampered signature', DirectProtocol::newNonce());
$forged_sig['transfer_signature'] = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));
check(DirectRelayIngest::store($forged_sig) === 'unroutable',
	'a transfer signature that does not verify is dropped with a loud log, never stored');

// A sender domain the box cannot resolve a key for is HELD, not dropped — a
// transient DNS failure must not lose a legitimately signed delivery.
$no_key = $make_container('signed, but key unresolvable here', DirectProtocol::newNonce(),
	array('sender' => 'peer@no-capability.invalid'));
check(DirectRelayIngest::store($no_key) === 'hold',
	'an unresolvable sender key is recoverable — the delivery stays on the relay for the next pull');

// ---------------------------------------------------------------------------
section('What the relay path refuses at request level, and how');
// ---------------------------------------------------------------------------

$missing_recipient = $make_container('x', DirectProtocol::newNonce());
$missing_recipient['recipient'] = '';
check(DirectRelayIngest::store($missing_recipient) === 'unroutable',
	'a container with no recipient is undeliverable, not retried forever');

$unknown_kind = $make_container('x', DirectProtocol::newNonce(), array('kind' => 'no_such_kind'));
check(DirectRelayIngest::store($unknown_kind) === 'hold',
	'a kind this box no longer serves is HELD — the plugin may come back, and nothing goes back to the sender');

$gone_mailbox = $make_container('x', DirectProtocol::newNonce(), array('recipient' => 'nobody@' . $TEST_DOMAIN));
check(DirectRelayIngest::store($gone_mailbox) === 'hold',
	'and so is a mailbox that does not resolve — recoverable, so it stays on the relay');

// ---------------------------------------------------------------------------
section('The pull consumer reads both artifacts from one listing');
// ---------------------------------------------------------------------------

$consumer_source = (string)file_get_contents(
	PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));
check(strpos($consumer_source, "--include=*.direct") !== false,
	'the pull asks for .direct entries as well as .seal');
check(strpos($consumer_source, 'ingestDirect') !== false,
	'and routes them to the Direct ingest rather than the mail router');
check(strpos($consumer_source, "glob(\$stage . '/*.direct')") !== false,
	'both artifact kinds come out of one listing, so neither can be starved by the other');

harness_finish();
