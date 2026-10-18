<?php
/** @joinery-test
 * name: escrow_tier2
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Tier 2 of the escrow holder (specs/hosted_data_promise_disk_encryption.md
 * D3, WP5): before it answers a boot, the holder reads the machine's activity
 * log at its provider, through a fake provider here (EscrowTier2::$context_override).
 *
 *   - What refuses: a rescue boot, a root-password reset, a rebuild, a clone or
 *     an image of its disks, a backup restored onto it, an address taken away;
 *     a request from an address that is not the machine's instance's. What is
 *     answered: a plain boot or reboot, the provider's maintenance reboots and
 *     migrations, a resize, a failed event.
 *   - Only events since it last opened (or was enrolled, or excused) count.
 *   - The log is read twice, a few seconds apart: an event recorded late is seen.
 *   - A log that cannot be read refuses (the machine asks again in ten seconds).
 *   - The owner's "I did this myself" excuses every event up to then, by name.
 *   - Through EscrowEndpoint::unlock: refused with the event recorded, answered
 *     once excused; a machine Tier 2 does not cover is answered as before.
 *   - A key that stops working (B77), through real rows: the provider's 401
 *     marks the account refused and refuses the boot with what to do; while it
 *     is refused the machine stays Tier 2 and every boot is refused, never
 *     answered unchecked; a new key answers again. A Connect account, a key
 *     without the events scope, and an account removed from here are Tier 1.
 *   - A key's expiry (B77): check() records what the provider reports; past it
 *     the key is not usable and its boots are refused; plane:cloud_account_key
 *     is critical for a key that does not work and warns within a week of the
 *     expiry, on each machine Tier 2 covers.
 *
 * Throwaway rows are permanently removed in cleanup.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$made = array();

/** A provider whose log and addresses the test sets. Each read takes the next list in $reads. */
class Tier2FakeProvider implements CloudProviderEvents {
	public $reads = array();
	public $addresses = array();
	public $fail = false;
	public $fail_code = 503;
	public $asked = 0;
	public function instanceEvents(string $instance_id, string $since_utc): array {
		$this->asked++;
		if ($this->fail) {
			throw $this->fail_code === 401
				? new CloudComputeException('unauthorized: Invalid Token', 401)
				: new CloudComputeException('Linode API GET account/events failed (503): unavailable', 503);
		}
		$list = count($this->reads) > 1 ? array_shift($this->reads) : ($this->reads[0] ?? array());
		return array_values(array_filter($list, function ($e) use ($since_utc) { return $e['time'] >= $since_utc; }));
	}
	public function instanceForAddress(string $address): string {
		if ($this->fail && $this->fail_code === 401) {
			throw new CloudComputeException('unauthorized: Invalid Token', 401);
		}
		return $this->addresses[$address] ?? '';
	}
}
/** A provider that answers check(): its scopes, name, company and the key's own expiry. */
class Tier2KeyFake implements CloudKeyExpiry, CloudTokenScopes, CloudAccountIdentity, CloudAccountCleanup {
	public $expiry = null;
	public function tokenScopes(): array { return array('linodes:read_write', 'events:read_only'); }
	public static function missingScopes(array $granted, array $needed): array { return LinodeComputeDriver::missingScopes($granted, $needed); }
	public function accountName(): string { return 't2b77-acct'; }
	public function accountCompany(): string { return ''; }
	public function listVolumes(): array { return array(); }
	public function deleteVolume(string $volume_id): void {}
	public function keyExpiry(): ?string { return $this->expiry; }
}
function tier2_event(string $action, string $time, string $message = '', string $status = 'finished', ?string $via = null): array {
	// A boot or reboot names the profile it booted, unless it was into rescue.
	$via = $via ?? (in_array($action, array('linode_boot', 'linode_reboot'), true) ? 'linode_config:My Ubuntu 24.04 LTS Disk Profile' : '');
	return array('action' => $action, 'time' => $time, 'status' => $status, 'message' => $message, 'entity' => 'linode', 'via' => $via);
}

section('What refuses, and what is answered');
$t = '2026-10-11 12:00:00';
foreach (array('linode_boot', 'linode_reboot', 'linode_shutdown', 'host_reboot', 'lassie_reboot', 'linode_migrate', 'linode_resize', 'linode_mutate') as $a) {
	check(EscrowTier2::first_refusing(array(tier2_event($a, $t))) === null, "$a: answered");
}
foreach (array('password_reset', 'linode_rebuild', 'linode_clone', 'disk_imagize', 'disk_duplicate', 'backups_restore', 'linode_deleteip') as $a) {
	$r = EscrowTier2::first_refusing(array(tier2_event('linode_boot', $t), tier2_event($a, $t)));
	check($r !== null && $r['action'] === $a && $r['words'] !== '', "$a: refuses, in words");
}
check(EscrowTier2::first_refusing(array(tier2_event('linode_reboot', $t, '', 'finished', ''))) !== null,
	'a reboot naming no configuration profile (how Linode logs a rescue boot) refuses');
check(EscrowTier2::first_refusing(array(tier2_event('password_reset', $t, '', 'failed'))) === null, 'a failed event is not one that happened');

section('Through the holder: the instance, the events since it opened, read twice');
$fake = new Tier2FakeProvider();
$fake->addresses = array('192.0.2.10' => '555', '2001:db8::10' => '555', '198.51.100.7' => '777');
EscrowTier2::$context_override = function (DataRootEscrow $row, &$why) use ($fake) { return array($fake, '555'); };
$row = new DataRootEscrow(NULL);
$row->set('dre_identity_key', base64_encode(random_bytes(32)));
$row->set('dre_status', 'enrolled');
$row->set('dre_enrolled_time', '2026-10-11 10:00:00');
$row->set('dre_unlocked_time', '2026-10-11 11:00:00');
$row->save();
$made[] = $row->key;
$nosleep = function () {};

$fake->reads = array(array(tier2_event('linode_reboot', '2026-10-11 11:30:00')));
check(EscrowTier2::refusal($row, '192.0.2.10', $nosleep) === null, 'Only a reboot since it opened: answered');
check(EscrowTier2::refusal($row, '2001:db8::10', $nosleep) === null, 'Asked over IPv6 from its own instance: answered');
$why = EscrowTier2::refusal($row, '198.51.100.7', $nosleep);
check($why !== null && strpos($why, 'instance 777') !== false && strpos((string)$row->get('dre_tier2_event'), '198.51.100.7') !== false,
	'From another instance\'s address (a copy, or its address moved): refused, and recorded', (string)$why);
$why = EscrowTier2::refusal($row, '203.0.113.9', $nosleep);
check($why !== null && strpos($why, 'no instance of this account') === false && strpos($why, 'belongs to no instance') !== false,
	'From an address no instance of the account has: refused', (string)$why);

$fake->reads = array(array(tier2_event('password_reset', '2026-10-11 10:30:00')));
check(EscrowTier2::refusal($row, '192.0.2.10', $nosleep) === null, 'A password reset before it last opened: not counted');
$fake->reads = array(array(tier2_event('password_reset', '2026-10-11 11:45:00')));
$why = EscrowTier2::refusal($row, '192.0.2.10', $nosleep);
check($why !== null && strpos($why, 'root password was reset') !== false
	&& (string)$row->get('dre_tier2_event') === 'password_reset at 2026-10-11 11:45:00', 'A password reset since: refused, the event recorded', (string)$why);

$slept = 0;
$fake->reads = array(array(), array(tier2_event('linode_rebuild', '2026-10-11 11:50:00')));
$fake->asked = 0;
$why = EscrowTier2::refusal($row, '192.0.2.10', function ($s) use (&$slept) { $slept += $s; });
check($why !== null && $fake->asked === 2 && $slept === EscrowTier2::REREAD_SECONDS,
	'An event the log recorded late: the second read, ' . EscrowTier2::REREAD_SECONDS . ' seconds on, refuses');

$fake->fail = true;
$why = EscrowTier2::refusal($row, '192.0.2.10', $nosleep);
check($why !== null && strpos($why, 'could not be read') !== false, 'A log that cannot be read: refused until it can be');
$fake->fail = false;

section('The owner\'s word, and the unlock itself');
$fake->reads = array(array(tier2_event('linode_reboot', '2026-10-11 11:46:00', '', 'finished', '')));
$row->set_report_key(random_bytes(32));
$row->mint_secret();
$row->set('dre_addresses', json_encode(array('192.0.2.10')));
$row->save();
$m_kp = sodium_crypto_sign_keypair();
$row->set('dre_identity_key', base64_encode(sodium_crypto_sign_publickey($m_kp)));
$row->save();
$unlock = function () use ($m_kp) {
	$payload = array('action' => 'unlock', 'boot_id' => bin2hex(random_bytes(8)));
	$raw = json_encode($payload);
	$ts = (string)time();
	$nonce = base64_encode(random_bytes(16));
	$pub = base64_encode(sodium_crypto_sign_publickey($m_kp));
	$msg = EscrowEndpoint::signing_message('unlock', $pub, $ts, $nonce, hash('sha256', $raw));
	return EscrowEndpoint::handle('unlock', $raw, array('Identity' => $pub, 'Timestamp' => $ts, 'Nonce' => $nonce,
		'Signature' => base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($m_kp)))), '192.0.2.10', 'dev.getjoinery.com');
};
$r = $unlock();
$fresh = new DataRootEscrow($row->key, TRUE);
check(($r['code'] ?? 0) === 403 && strpos((string)($r['error'] ?? ''), 'rescue mode') !== false
	&& strpos((string)$fresh->get('dre_tier2_event'), 'linode_reboot at') === 0,
	'A boot into rescue mode since it opened: the unlock is refused, with the event on the holder\'s page', json_encode($r));
EscrowTier2::excuse($fresh, 1);
$fresh = new DataRootEscrow($row->key, TRUE);
check(EscrowTier2::since($fresh) > '2026-10-11 11:46:00' && (int)$fresh->get('dre_tier2_excused_usr_user_id') === 1
	&& (string)$fresh->get('dre_tier2_event') === '', 'I did this myself: recorded with who said it, and every event up to now excused');
$r = $unlock();
check(isset($r['data']['secret']) && base64_decode($r['data']['secret'], true) === $fresh->secret(), 'Its next ask is answered', json_encode(array_keys($r['data'] ?? $r)));

EscrowTier2::$context_override = function (DataRootEscrow $row, &$why) { $why = 'it is in its owner\'s own cloud account'; return null; };
check(EscrowTier2::refusal($fresh, '203.0.113.200', $nosleep) === null, 'A machine Tier 2 does not cover: Tier 1, nothing read');
EscrowTier2::$context_override = null;

section('A key that stops working: the machine stays Tier 2 (B77)');
// Real rows this time: its node, the provision naming one of our accounts and
// the instance, and the account, whose driver is the fake provider.
$fake77 = new Tier2FakeProvider();
$fake77->addresses = array('192.0.2.77' => 't2b77-inst');
OperatorCloudAccount::$driver_factory = function ($token) use ($fake77) { return $fake77; };
harness_defer(function () { OperatorCloudAccount::$driver_factory = null; });
$suffix77 = bin2hex(random_bytes(3));
$oca77 = new OperatorCloudAccount(NULL);
$oca77->set('oca_method', OperatorCloudAccount::METHOD_KEY);
$oca77->set('oca_account_name', 't2b77 account ' . $suffix77);
$oca77->set('oca_scopes', 'linodes:read_write events:read_only');
$oca77->store_credential('t2b77-token-' . $suffix77);
$oca77->save();
$oca77->load();
harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $oca77->key);
$node77 = new ManagedNode(NULL);
$node77->set('mgn_name', 'HarnessTest t2b77 node');
$node77->set('mgn_slug', 't2b77-' . $suffix77);
$node77->set('mgn_host', '192.0.2.77');
$node77->set('mgn_uptime_enabled', false);
$node77->save();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node77->key);
$prov77 = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 't2b77-' . $suffix77 . '.example.com',
		'cvp_slug' => 't2b77-' . $suffix77, 'cvp_sitename' => 't2b77' . $suffix77, 'cvp_hosting_mode' => 'operator',
		'cvp_region' => 'us-east', 'cvp_instance_type' => 'g6-nanode-1', 'cvp_docker_mode' => 'bare-metal',
		'cvp_install_mode' => 'adopted', 'cvp_status' => 'ready', 'cvp_mgn_managed_node_id' => (int)$node77->key,
		'cvp_oca_operator_cloud_account_id' => (int)$oca77->key, 'cvp_instance_id' => 't2b77-inst') as $k => $v) {
	$prov77->set($k, $v);
}
$prov77->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $prov77->key);
$row77 = new DataRootEscrow(NULL);
$row77->set('dre_identity_key', base64_encode(random_bytes(32)));
$row77->set('dre_status', 'enrolled');
$row77->set('dre_mgn_managed_node_id', (int)$node77->key);
$row77->set('dre_enrolled_time', '2026-10-15 01:00:00');
$row77->set('dre_unlocked_time', '2026-10-15 02:00:00');
$row77->save();
$made[] = $row77->key;

$why77 = '';
$blocked77 = null;
check(EscrowTier2::context($row77, $why77, $blocked77) !== null && $blocked77 === null && EscrowTier2::applies($row77),
	'its own account, a lasting key with the events scope: Tier 2', $why77);
$fake77->reads = array(array(tier2_event('linode_reboot', '2026-10-15 03:00:00')));
check(EscrowTier2::refusal($row77, '192.0.2.77', $nosleep) === null, 'a plain reboot since: answered');

$fake77->fail = true;
$fake77->fail_code = 401;
$why = EscrowTier2::refusal($row77, '192.0.2.77', $nosleep);
$acct77 = new OperatorCloudAccount($oca77->key, TRUE);
check($why !== null && strpos($why, 'key was refused by the provider') !== false && strpos($why, 'Hosting tab of Provisioning Setup') !== false,
	'the provider refuses the key (401): the boot is refused, saying where to replace the key', (string)$why);
check((string)$acct77->get('oca_status') === OperatorCloudAccount::STATUS_REFUSED && strpos((string)$acct77->get('oca_check_error'), 'Invalid Token') !== false,
	'and the account is marked refused, with the provider\'s words, for the Hosting tab');
$fake77->fail = false;
$fake77->asked = 0;
$why = EscrowTier2::refusal($row77, '192.0.2.77', $nosleep);
$why77 = '';
$blocked77 = null;
EscrowTier2::context($row77, $why77, $blocked77);
check($why !== null && strpos($why, 'never answered unchecked') !== false && $fake77->asked === 0
	&& $blocked77 !== null && EscrowTier2::applies($row77),
	'while it is refused: still Tier 2, every boot refused, nothing asked of the provider (never Tier 1 by a dead key)', (string)$why);

$acct77->set('oca_status', OperatorCloudAccount::STATUS_ACTIVE);
$acct77->set('oca_check_error', null);
$acct77->save();
check(EscrowTier2::refusal($row77, '192.0.2.77', $nosleep) === null, 'a working key again: answered');

// A key whose scopes were never read is not "chosen without the scope": it is
// the dead-key shape, refused until a check reads them (reviewer1 F1).
$acct77->set('oca_scopes', null);
$acct77->save();
$why77 = '';
$blocked77 = null;
EscrowTier2::context($row77, $why77, $blocked77);
$inc77 = (new IncidentSourceCloudAccountKey())->evaluate($node77);
check($blocked77 !== null && strpos((string)EscrowTier2::refusal($row77, '192.0.2.77', $nosleep), 'never been checked') !== false
	&& EscrowTier2::applies($row77) && $inc77 !== null && $inc77['severity'] === IncidentRecord::SEVERITY_CRITICAL
	&& strpos($inc77['title'], 'never been checked') !== false,
	'scopes never read: still Tier 2, the boot refused until a check reads them, and a critical incident', json_encode($inc77));
$acct77->set('oca_scopes', 'linodes:read_write events:read_only');
$acct77->save();
check(EscrowTier2::machines_in_account((int)$oca77->key) === array((int)$node77->key => 'HarnessTest t2b77 node'),
	'machines_in_account names the encrypted machine its removal would drop to Tier 1 (N1)', json_encode(EscrowTier2::machines_in_account((int)$oca77->key)));
$acct77->set('oca_scopes', 'linodes:read_write');
$acct77->set('oca_status', OperatorCloudAccount::STATUS_REFUSED);
$acct77->save();
$why77 = '';
check(EscrowTier2::context($row77, $why77) === null && strpos($why77, 'events:read_only') !== false && EscrowTier2::refusal($row77, '192.0.2.77', $nosleep) === null,
	'a key that never had the events scope: Tier 1, refused or not', $why77);
$acct77->set('oca_scopes', 'linodes:read_write events:read_only');
$acct77->set('oca_method', OperatorCloudAccount::METHOD_CONNECT);
$acct77->save();
$why77 = '';
check(EscrowTier2::context($row77, $why77) === null && strpos($why77, 'two hours') !== false && !EscrowTier2::applies($row77),
	'an account held by a two-hour Linode sign-in: Tier 1', $why77);
$acct77->set('oca_method', OperatorCloudAccount::METHOD_KEY);
$acct77->set('oca_delete_time', gmdate('Y-m-d H:i:s'));
$acct77->save();
$why77 = '';
check(EscrowTier2::context($row77, $why77) === null && strpos($why77, 'no longer held here') !== false && !EscrowTier2::applies($row77),
	'an account removed from this management node: Tier 1', $why77);
check((new IncidentSourceCloudAccountKey())->evaluate($node77) === null, 'and plane:cloud_account_key says nothing of it');

// The key's own expiry, recorded by check() from the provider.
$acct77->set('oca_delete_time', null);
$acct77->save();
$kf77 = new Tier2KeyFake();
$kf77->expiry = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
check($acct77->check($kf77) === '' && (string)(new OperatorCloudAccount($oca77->key, TRUE))->get('oca_token_expires') === $kf77->expiry,
	'check() records the expiry the provider reports for the key');
$acct77 = new OperatorCloudAccount($oca77->key, TRUE);
check($acct77->usable() && $acct77->key_expires_within(OperatorCloudAccount::KEY_EXPIRY_WARNING_SECONDS) === null
	&& (new IncidentSourceCloudAccountKey())->evaluate($node77) === null, 'a month away: usable, and nothing to say');
$kf77->expiry = gmdate('Y-m-d H:i:s', time() + 3 * 86400);
$acct77->check($kf77);
$inc77 = (new IncidentSourceCloudAccountKey())->evaluate($node77);
check($inc77 !== null && $inc77['severity'] === IncidentRecord::SEVERITY_WARNING && strpos($inc77['title'], 'expires on') !== false
	&& strpos((string)($inc77['detail']['Fix'] ?? ''), 'Hosting tab') !== false && EscrowTier2::refusal($row77, '192.0.2.77', $nosleep) === null,
	'three days away: a warning on the machine, with where to replace it; its boots still answered', json_encode($inc77));
$acct77 = new OperatorCloudAccount($oca77->key, TRUE);
$acct77->set('oca_token_expires', gmdate('Y-m-d H:i:s', time() - 60));
$acct77->save();
$inc77 = (new IncidentSourceCloudAccountKey())->evaluate($node77);
$why = EscrowTier2::refusal($row77, '192.0.2.77', $nosleep);
check(!$acct77->usable() && strpos($acct77->unusable_reason(), 'API key') !== false && strpos($acct77->unusable_reason(), 'expired on') !== false
	&& !$acct77->expired(), 'past it: not usable, said as an API key that expired (not a two-hour sign-in)', $acct77->unusable_reason());
check($inc77 !== null && $inc77['severity'] === IncidentRecord::SEVERITY_CRITICAL && strpos((string)$why, 'never answered unchecked') !== false,
	'and the incident is critical, and its boots are refused, never answered unchecked', json_encode($inc77));
$acct77->set('oca_token_expires', null);
$acct77->set('oca_status', OperatorCloudAccount::STATUS_REFUSED);
$acct77->save();
$inc77 = (new IncidentSourceCloudAccountKey())->evaluate($node77);
check($inc77 !== null && $inc77['severity'] === IncidentRecord::SEVERITY_CRITICAL && strpos((string)($inc77['detail']['Why'] ?? ''), 'refused') !== false,
	'a refused key: critical, saying the provider refused it', json_encode($inc77));
$acct77->check($kf77 = new Tier2KeyFake());
check((new IncidentSourceCloudAccountKey())->evaluate($node77) === null && (new OperatorCloudAccount($oca77->key, TRUE))->usable(),
	'a key the provider answers to again, with no expiry: the incident clears');

foreach ($made as $id) {
	$db->prepare('DELETE FROM dre_data_root_escrows WHERE dre_data_root_escrow_id = ?')->execute(array($id));
}
harness_finish();
