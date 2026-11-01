<?php
/** @joinery-test
 * name: relay_status_report
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * RelayStatusReport, what the relay_status observe word (agent 1.101.0)
 * carries: this site's view of its relay from the relay record, never an
 * address, a name, a key or the relay's free text, and nothing written. The
 * script that prints it (maintenance_scripts/sysadmin_tools/relay_status.php)
 * prints one JSON object and refuses arguments.
 *
 * Run: php tests/run.php test-db --filter=relay_status_report
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

section('No relay');
$before = MailboxRelay::active();
if ($before !== null) {
	harness_skip('a relay row is already active in this database');
	harness_finish();
}
$none = RelayStatusReport::status();
check($none['relay'] === 'none' && isset($none['inbound']['last_day']) && !isset($none['version']),
	'no enabled relay: says so, with the mail counts', json_encode($none));

section('A relay on 3.16, after its transport key moved');
$old_public = base64_encode(random_bytes(32));
$new = (new SealedBox())->generateKeypair();
$relay = new MailboxRelay(NULL);
$relay->set('mrl_name', 'harnesstest relay1.secret-name.example');
$relay->set('mrl_is_enabled', true);
$relay->set('mrl_cloud_instance_id', '12345');
$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'reason' => '', 'detail' => 'answered from mx.secret-name.example',
	'provisioned' => '3.16', 'queue' => 2, 'sole' => true)));
$relay->set('mrl_last_health_time', '2026-11-01 13:50:02.123456');
$relay->set('mrl_transport_public_key', $new['public']);
$relay->set('mrl_transport_secret_sealed', 'sealed-current-secret-text');
$relay->set('mrl_transport_secret_retired_sealed', 'sealed-retired-secret-text');
$relay->set('mrl_transport_key_generation', 2);
$relay->set('mrl_transport_replaced_time', '2026-11-01 13:51:00');
$relay->set('mrl_map_version', 41);
$relay->set('mrl_last_push_time', '2026-11-01 13:51:00');
$relay->set('mrl_last_pull_time', '2026-11-01 13:54:00');
$relay->set('mrl_last_pull_drained_time', '2026-11-01 13:54:00');
$relay->set('mrl_last_pull_held', 1);
$relay->save();
harness_register_row('mrl_mailbox_relays', 'mrl_mailbox_relay_id', intval($relay->key));

$snapshot = function () use ($relay) {
	$row = DbConnector::get_instance()->get_db_link()
		->query('SELECT * FROM mrl_mailbox_relays WHERE mrl_mailbox_relay_id = ' . intval($relay->key))->fetch(PDO::FETCH_ASSOC);
	unset($row['mrl_update_time']);
	return $row;
};
$row_before = $snapshot();
$r = RelayStatusReport::status();
$json = json_encode($r);

check($r['relay'] === 'active' && $r['relay_id'] === intval($relay->key) && $r['route'] === 'cloud', 'the active relay, by id and route', $json);
check($r['version']['running'] === '3.16' && $r['version']['seals_post_quantum'] === true, 'its version, and that it takes X-Wing keys');
check($r['health']['state'] === 'ok' && $r['health']['queue'] === 2 && $r['health']['sole_tenant'] === true
	&& $r['health']['checked_time'] === '2026-11-01 13:50:02', 'its last health answer, the time to the second');
check($r['transport_key'] === array('kind' => 'xwing', 'generation' => 2, 'retired_kept' => true, 'replaced_time' => '2026-11-01 13:51:00'),
	'the transport key\'s kind and generation, and that the old secret is still kept', $json);
check($r['map'] === array('version' => 41, 'last_push_time' => '2026-11-01 13:51:00')
	&& $r['pickup']['held'] === 1 && $r['pickup']['last_drained_time'] === '2026-11-01 13:54:00', 'the map push and pickup');
check(strpos($json, 'secret-name') === false && strpos($json, 'sealed-') === false && strpos($json, substr($new['public'], 0, 24)) === false,
	'no name, host, key or sealed secret in it', $json);
check($snapshot() === $row_before, 'reading it wrote nothing to the relay row');

$relay->set('mrl_transport_public_key', $old_public);
$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.15')));
$relay->save();
$r = RelayStatusReport::status();
check($r['transport_key']['kind'] === 'x25519' && $r['version']['seals_post_quantum'] === false,
	'a 3.15 relay with the old key: the old kind, and no X-Wing keys');
check((string)(new MailboxRelay(intval($relay->key), TRUE))->get('mrl_transport_public_key') === $old_public,
	'and the old key is left alone, where building the map would replace it');

section('The script');
$script = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/relay_status.php';
$out = array();
exec('php ' . escapeshellarg($script) . ' --anything 2>&1', $out, $rc);
check($rc === 2, 'an argument is refused', implode("\n", $out));
$out = array();
exec('php ' . escapeshellarg($script) . ' 2>/dev/null', $out, $rc);
$d = json_decode(implode("\n", $out), true);
check($rc === 0 && count($out) === 1 && is_array($d) && ($d['mailbox'] ?? '') === 'active' && isset($d['relay']),
	'one JSON line, the mailbox active, the relay field present', implode("\n", $out));

harness_finish();
