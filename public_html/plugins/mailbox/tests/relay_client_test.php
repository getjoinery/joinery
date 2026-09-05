<?php
/** @joinery-test
 * name: relay_client
 * tier: db
 * env: any
 * needs: []
 * timeout: 180
 *
 * The plane speaks HTTPS to a relay without a shell
 * (specs/relay_without_a_shell.md WP2): RelayClient and every consumer behind
 * the per-row switch, against the REAL relay binary on a loopback port.
 *
 *   - the relay client identity is minted once, sealed, and signs
 *   - a pinned, signed ping answers; each failure is CLASSED (wrong pin,
 *     nothing listening, unregistered key) because the class is the diagnosis
 *   - pollHealth() takes the API path on a row with a pin and records the class
 *   - the spool pull lists, fetches and acks over the API with the ingest
 *     untouched: an unroutable entry is acked away, a held one stays
 *   - the map push is one signed PUT and the verdict comes back in-band
 *   - Direct egress on the public listener is signed
 *
 * The relay row is a fixture (disabled, so it never becomes this deployment's
 * active relay) and is removed at teardown. The client identity is this
 * deployment's real one - minting it is idempotent and it is what a relay
 * would be told about.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/lib/relay_ping_probe.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_client_identity_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));

$binary = RelayPingProbe::binary();
if ($binary === null) {
	harness_skip('relay client against the real relay', 'no prebuilt relay-sealer and no go toolchain');
	harness_finish();
}

// ---------------------------------------------------------------------------
section('The relay client identity');

$identity = RelayClientIdentity::ensure(RelayClientIdentity::KIND_CLIENT);
$public = RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT);
check(strlen(base64_decode($public, true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'the client identity is an Ed25519 key');
check(RelayClientIdentity::ensure(RelayClientIdentity::KIND_CLIENT)->key === $identity->key, 'ensure() is idempotent');
$sig = RelayClientIdentity::sign(RelayClientIdentity::KIND_CLIENT, 'hello');
check(sodium_crypto_sign_verify_detached(base64_decode($sig), 'hello', base64_decode($public)), 'it signs, and the signature verifies against its public key');
$stored = base64_decode((string)$identity->get('rci_sealed_secret_key'), true);
check((string)$identity->get('rci_sealed_secret_key') !== '' && ($stored === false || strlen($stored) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES),
	'the secret is stored sealed, never as the bare key');

// ---------------------------------------------------------------------------
section('A relay that holds this deployment\'s key');

$probe = new RelayPingProbe($binary);
check($probe->addTenantWithKey('main', $public), 'tenant main registered with THIS deployment\'s public key');
$probe->addTenant('other', 'other.test'); // a second tenant: the spool must stay scoped
if (!$probe->start()) {
	check(false, 'the relay listener starts', (string)@file_get_contents($probe->home . '/serve.log'));
	harness_finish();
}
RelayClient::$base_url_override['127.0.0.1'] = $probe->baseUrl();

// The fixture row: a relay without a shell, at 127.0.0.1, pinned. Disabled, so
// MailboxRelay::active() never returns it while this runs.
$relay = new MailboxRelay(NULL);
$relay->set('mrl_name', 'relay_client_test');
$relay->set('mrl_is_enabled', false);
$relay->set('mrl_public_ip', '127.0.0.1');
$relay->set('mrl_identity_fingerprint', $probe->fingerprint());
$relay->set('mrl_identity_public_key', $probe->identityPublicKey());
$relay->set('mrl_tenant_slug', 'main');
$relay->set('mrl_mx_hostname', 'mx.probe.test');
$relay->set('mrl_authserv_id', 'mx.probe.test');
$relay->save();
harness_register_model('MailboxRelay', $relay->key);
$relay->ensureTransportKeypair();
check($relay->usesRelayApi(), 'a row with an identity pin speaks the relay API');
check(strlen($probe->identityPublicKey()) === 44, 'the probe reads the relay identity key from its certificate', $probe->identityPublicKey());

try {
	$client = RelayClient::forRelay($relay);
	$ping = $client->ping();
	check(isset($ping['identity']['identity_fingerprint']) && $ping['identity']['identity_fingerprint'] === $probe->fingerprint(),
		'a pinned, signed ping answers with the pinned identity');
	check(($ping['slug'] ?? '') === 'main', 'the relay answers for the tenant the signature named');
} catch (RelayClientException $e) {
	check(false, 'a pinned, signed ping answers', $e->failure_class . ': ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
section('Failures are classed');

$wrong = new RelayClient('127.0.0.1', base64_encode(str_repeat("\x00", 32)), 'main');
try { $wrong->ping(); check(false, 'a wrong pin is refused'); }
catch (RelayClientException $e) { check($e->failure_class === RelayClient::FAIL_PIN_MISMATCH, 'a wrong pin is a pin_mismatch (curl 90)', $e->failure_class); }

$sock = stream_socket_server('tcp://127.0.0.1:0'); $dead_port = (int)substr(stream_socket_get_name($sock, false), strrpos(stream_socket_get_name($sock, false), ':') + 1); fclose($sock);
RelayClient::$base_url_override['203.0.113.77'] = 'https://127.0.0.1:' . $dead_port;
$dead = new RelayClient('203.0.113.77', $probe->fingerprint(), 'main');
try { $dead->ping(); check(false, 'nothing listening is refused'); }
catch (RelayClientException $e) { check($e->failure_class === RelayClient::FAIL_REFUSED, 'nothing listening is refused (curl 7)', $e->failure_class); }

$stranger = new RelayClient('127.0.0.1', $probe->fingerprint(), 'stranger');
try { $stranger->ping(); check(false, 'an unregistered tenant is refused'); }
catch (RelayClientException $e) { check($e->failure_class === RelayClient::FAIL_SIGNATURE_REFUSED && $e->http_status === 401,
	'an unregistered tenant is signature_refused (401)', $e->failure_class . ' ' . $e->http_status); }

// ---------------------------------------------------------------------------
section('pollHealth() takes the API path and records the failure class');

$health = $relay->pollHealth();
check($health['state'] !== MailboxRelay::HEALTH_UNREACHABLE, 'the ping was reached', (string)$health['detail']);
check(isset($health['ping']['build']), 'the whole ping object is stored for the Setup tab');
$fresh = new MailboxRelay(intval($relay->key), TRUE);
check((string)$fresh->get('mrl_last_health_failure') === '', 'a successful poll leaves the failure class empty');
check($fresh->lastHealth() !== null && ($fresh->lastHealth()['slug'] ?? '') === 'main', 'the answer is cached on the row');

$relay->set('mrl_identity_fingerprint', base64_encode(str_repeat("\x11", 32)));
$relay->save();
$health = $relay->pollHealth();
check($health['state'] === MailboxRelay::HEALTH_UNREACHABLE && ($health['failure'] ?? '') === RelayClient::FAIL_PIN_MISMATCH,
	'a pin mismatch is reported as its own class', json_encode($health['failure'] ?? null));
$fresh = new MailboxRelay(intval($relay->key), TRUE);
check((string)$fresh->get('mrl_last_health_failure') === RelayClient::FAIL_PIN_MISMATCH, 'the failure class is recorded on the row');
check($fresh->lastHealth() !== null && ($fresh->lastHealth()['slug'] ?? '') === 'main', 'the last GOOD answer is kept, not overwritten by the failure');
$relay->set('mrl_identity_fingerprint', $probe->fingerprint());
$relay->save();

// ---------------------------------------------------------------------------
section('The spool pull over the API: list, fetch, ingest, ack');

// A disabled domain: its mail is HELD (left on the relay), as the hold test pins.
$suffix = substr(md5(uniqid('rct', true)), 0, 8);
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'rct-off-' . $suffix . '.example');
$domain->set('ied_is_enabled', false);
$domain->save();
harness_register_model('InboundEmailDomain', $domain->key);

$held_id = '1700000001-' . bin2hex(random_bytes(4));
$probe->spoolWrite('main', $held_id, 'meta', json_encode(array('spool_id' => $held_id, 'recipient' => 'x@rct-off-' . $suffix . '.example',
	'key_kind' => 'transport', 'received_utc' => gmdate('c'))));
$probe->spoolWrite('main', $held_id, 'seal', 'v1.seal.HELD');
$bad_id = '1700000002-' . bin2hex(random_bytes(4));
$probe->spoolWrite('main', $bad_id, 'meta', json_encode(array('spool_id' => $bad_id, 'recipient' => 'no-at-sign', 'key_kind' => 'transport')));
$probe->spoolWrite('main', $bad_id, 'seal', 'v1.seal.BAD');
$torn_id = '1700000003-' . bin2hex(random_bytes(4));
$probe->spoolWrite('main', $torn_id, 'seal', 'v1.seal.TORN'); // no .meta: not listed, not touched
$probe->spoolWrite('other', '1700000004-aaaaaaaa', 'meta', '{}');
$probe->spoolWrite('other', '1700000004-aaaaaaaa', 'seal', 'v1.seal.OTHER');

$result = (new RelaySpoolConsumer($relay))->pull();
check($result['status'] === 'success', 'the pull completes over the API', json_encode($result));
check(($result['seals'] ?? -1) === 2, 'two complete entries were listed; the torn one was not', json_encode($result));
check(($result['held'] ?? -1) === 1 && ($result['acked'] ?? -1) === 1, 'the disabled-domain entry is held, the unroutable one acked away', json_encode($result));
$left = $probe->spoolIds('main');
check(in_array($held_id, $left, true) && in_array($torn_id, $left, true) && !in_array($bad_id, $left, true),
	'the relay keeps the held and torn entries and no longer holds the acked one', implode(',', $left));
check($probe->spoolIds('other') === array('1700000004-aaaaaaaa'), 'another tenant\'s spool was never touched');
$fresh = new MailboxRelay(intval($relay->key), TRUE);
check((string)$fresh->get('mrl_last_pull_time') !== '' && intval($fresh->get('mrl_last_pull_held')) === 1, 'the pull bookkeeping is recorded');

// ---------------------------------------------------------------------------
section('The map push is one signed PUT with the verdict in-band');

$before = intval($relay->get('mrl_map_version'));
$push = RelayMapSync::push($relay, true);
check(($push['status'] ?? '') === 'success', 'the fragment is merged by the relay\'s root applier', json_encode($push));
$accepted = $probe->acceptedFragment('main');
check(is_array($accepted) && intval($accepted['version'] ?? 0) === $before + 1, 'the relay holds the pushed fragment at the new version');
$fresh = new MailboxRelay(intval($relay->key), TRUE);
check(intval($fresh->get('mrl_map_version')) === $before + 1 && (string)$fresh->get('mrl_map_content_hash') !== '', 'version and hash recorded');
$again = RelayMapSync::push($relay, false);
check(($again['status'] ?? '') === 'skipped' || ($again['status'] ?? '') === 'success', 'an unchanged map is skipped or re-pushed cleanly', json_encode($again));

// ---------------------------------------------------------------------------
section('Direct egress on the public listener is signed');

$ping_before = RelayClient::forRelay($relay)->ping();
$egress_before = intval($ping_before['direct']['egress_1h'] ?? -1);
$answer = RelayClient::forRelay($relay)->egress('https://198.51.100.10/.well-known/joinery-direct?step=preflight', '{}', 'application/json');
check($answer === null, 'a target the relay must not reach (TEST-NET) yields no upstream answer');
$ping_after = RelayClient::forRelay($relay)->ping();
check(intval($ping_after['direct']['egress_1h'] ?? -1) === $egress_before + 1, 'the relay counted one signed egress call',
	$egress_before . ' -> ' . ($ping_after['direct']['egress_1h'] ?? '?'));

$probe->stop();
harness_finish();
