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
	public $asked = 0;
	public function instanceEvents(string $instance_id, string $since_utc): array {
		$this->asked++;
		if ($this->fail) {
			throw new CloudComputeException('Linode API GET account/events failed (503): unavailable', 503);
		}
		$list = count($this->reads) > 1 ? array_shift($this->reads) : ($this->reads[0] ?? array());
		return array_values(array_filter($list, function ($e) use ($since_utc) { return $e['time'] >= $since_utc; }));
	}
	public function instanceForAddress(string $address): string {
		return $this->addresses[$address] ?? '';
	}
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

foreach ($made as $id) {
	$db->prepare('DELETE FROM dre_data_root_escrows WHERE dre_data_root_escrow_id = ?')->execute(array($id));
}
harness_finish();
