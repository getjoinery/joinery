<?php
/** @joinery-test
 * name: fortress_relay_pin
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * The relay pin (specs/client_custody_mail.md § R10, WP8).
 *
 *  - the shared vector (fixtures/relay_pin_vector.json): PHP's pin MAC formula
 *    (MailboxRelayPin::pinMessage, HKDF 'sealed-vault:pin', HMAC-SHA256) gives
 *    the vector's MAC, the statement verifies under the relay identity over the
 *    prefixed bytes and fails when a byte changes, and mailbox_fortress.js
 *    carries the same values for its selfCheck;
 *  - relay_pin_set (setPin) refuses a mailbox that is not the caller's and
 *    anything not shaped like a pin; a first pin and a new MAC over the same
 *    identity change nothing trusted, another identity does (the step-up);
 *  - the proxy (sealTarget) hands the relay's body back byte for byte, with the
 *    stored pin and the relay identity; it answers only for the owner's
 *    Fortress mailbox under Seal at the relay;
 *  - the pins listing is what a rotation re-makes.
 *
 * Run: php tests/run.php test-db --filter=fortress_relay_pin
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

/** A relay whose API is a stub answering one seal-target body. */
class FortressPinStubRelay extends MailboxRelay {
	public $answer;
	public $asked = array();
	public function withApi(callable $fn) {
		$client = (new ReflectionClass('FortressPinStubClient'))->newInstanceWithoutConstructor();
		$client->relay = $this;
		return $fn($client);
	}
}
class FortressPinStubClient extends RelayClient {
	public $relay;
	public function sealTarget(string $recipient): ?string {
		$this->relay->asked[] = $recipient;
		return $this->relay->answer;
	}
}

$db = DbConnector::get_instance()->get_db_link();

try {
	section('The shared vector');
	$v = json_decode((string)file_get_contents(__DIR__ . '/fixtures/relay_pin_vector.json'), true);
	$key = hash_hkdf('sha256', hex2bin($v['vault_secret_hex']), 32, 'sealed-vault:pin', '');
	$mac = base64_encode(hash_hmac('sha256', MailboxRelayPin::pinMessage(intval($v['alias_id']), $v['relay_identity_public_key']), $key, true));
	check($mac === $v['pin_mac'], 'the pin MAC formula gives the vector\'s MAC');
	$pub = base64_decode($v['relay_identity_public_key']);
	$sig = base64_decode($v['signature']);
	check(sodium_crypto_sign_verify_detached($sig, "joinery-relay:seal-target:v1\n" . $v['statement'], $pub),
		'the statement verifies under the relay identity over the prefixed bytes');
	check(!sodium_crypto_sign_verify_detached($sig, "joinery-relay:seal-target:v1\n" . str_replace('"map_version":7', '"map_version":8', $v['statement']), $pub),
		'and fails when a byte changes');
	$js = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_fortress.js'));
	check(strpos($js, $v['pin_mac']) !== false && strpos($js, $v['signature']) !== false && strpos($js, $v['vault_secret_hex']) !== false,
		'mailbox_fortress.js selfCheck carries the same vector');

	section('Fixtures');
	$box = new SealedBox();
	$pair = $box->generateKeypair();
	$mail_pub = base64_encode(SealedBox::b64url_decode($pair['public']));
	$owner = make_user('FrnOwner');
	$owner_id = intval($owner->key);
	vault_fixture_client_vault($owner_id, $mail_pub, InboundEmailMessage::SEAL_SCOPE_FORTRESS);
	$stranger = make_user('FrnStranger');

	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'harnesstest-frn-' . bin2hex(random_bytes(4)) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_owner_usr_user_id', $owner_id);
	$domain->save();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
	$domain->set_security_level(InboundEmailDomain::LEVEL_FORTRESS);
	$domain->set('ied_relay_seals_to_owner', true);
	$domain->save();

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$alias->set('iea_alias', 'harnesstest_pin');
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias_id = intval($alias->key);
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $alias_id);
	InboundEmailMailboxGrant::sync_for_alias($alias_id, array($owner_id));
	harness_defer(function () use ($db, $alias_id) {
		$db->prepare('DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = ?')->execute(array($alias_id));
	});
	check(true, 'a Fortress mailbox under Seal at the relay, one owner with a mail vault');

	$relay = new FortressPinStubRelay(NULL);
	$relay->set('mrl_identity_public_key', $v['relay_identity_public_key']);
	$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.2')));
	$relay->answer = '{"statement":"{\"a\":1}","signature":"c2ln"}' . "\n";

	$refused = function (callable $fn): bool {
		try { $fn(); return false; } catch (MailboxRelayPinException $e) { return true; }
	};

	section('relay_pin_set');
	$identity = $v['relay_identity_public_key'];
	$a_mac = base64_encode(random_bytes(32));
	check($refused(function () use ($stranger, $alias_id, $identity, $a_mac) {
		MailboxRelayPin::setPin(intval($stranger->key), $alias_id, $identity, $a_mac);
	}), 'a mailbox that is not the caller\'s is refused');
	check($refused(function () use ($owner_id, $alias_id, $a_mac) {
		MailboxRelayPin::setPin($owner_id, $alias_id, base64_encode('short'), $a_mac);
	}) && $refused(function () use ($owner_id, $alias_id, $identity) {
		MailboxRelayPin::setPin($owner_id, $alias_id, $identity, 'not base64 !');
	}), 'something not shaped like a pin is refused');
	check(!MailboxRelayPin::changesIdentity($owner_id, $alias_id, $identity), 'a first pin trusts nothing new (first use)');
	MailboxRelayPin::setPin($owner_id, $alias_id, $identity, $a_mac);
	$stored = json_decode((string)(new InboundEmailAlias($alias_id, TRUE))->get('iea_relay_identity_pin'), true);
	check($stored === array('relay_identity_public_key' => $identity, 'mac' => $a_mac), 'the pin is stored as sent');
	check(!MailboxRelayPin::changesIdentity($owner_id, $alias_id, $identity), 'a new MAC over the same relay needs no step-up (a rotation)');
	$other_identity = base64_encode(random_bytes(32));
	check(MailboxRelayPin::changesIdentity($owner_id, $alias_id, $other_identity), 'another relay identity does');

	section('relay_seal_target: the relay\'s body, unchanged');
	$answer = MailboxRelayPin::sealTarget($owner_id, $alias_id, $relay);
	check($answer['relay_answer'] === $relay->answer, 'the relay\'s body comes back byte for byte');
	check($relay->asked === array('harnesstest_pin@' . $domain->get('ied_domain')), 'asked about this mailbox\'s address');
	check($answer['relay_identity_public_key'] === $identity && $answer['pin'] === $stored, 'with the relay identity and the stored pin');
	check($refused(function () use ($stranger, $alias_id, $relay) {
		MailboxRelayPin::sealTarget(intval($stranger->key), $alias_id, $relay);
	}), 'not for someone else\'s mailbox');
	$relay->answer = null;
	check($refused(function () use ($owner_id, $alias_id, $relay) {
		MailboxRelayPin::sealTarget($owner_id, $alias_id, $relay);
	}), 'a relay that does not know the mailbox is an error, not an empty answer');
	check(count(MailboxRelayPin::mailboxesToCheck($owner_id, $relay)) === 1, 'the owner\'s page checks this mailbox');

	$domain->set('ied_relay_seals_to_owner', false);
	$domain->save();
	$relay->answer = '{}';
	check($refused(function () use ($owner_id, $alias_id, $relay) {
		MailboxRelayPin::sealTarget($owner_id, $alias_id, $relay);
	}) && MailboxRelayPin::mailboxesToCheck($owner_id, $relay) === array(), 'with Seal at the relay off there is nothing to check');

	section('The rotation re-makes every pin');
	check(MailboxRelayPin::pins($owner_id) === array(array('alias_id' => $alias_id, 'relay_identity_public_key' => $identity, 'mac' => $a_mac)),
		'relay_pins lists the owner\'s pin');
	check(MailboxRelayPin::pins(intval($stranger->key)) === array(), 'and nobody else\'s');
	check(VaultUnlock::clientResealsFor('mail')['scripts'] === array('plugins/mailbox/assets/mailbox-reseal.js'),
		'the mail rotation page loads mailbox-reseal.js');
} catch (\Throwable $e) {
	check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
