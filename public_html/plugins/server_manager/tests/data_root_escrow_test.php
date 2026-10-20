<?php
/** @joinery-test
 * name: data_root_escrow
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The escrow holder (specs/hosted_data_promise_disk_encryption.md D3, WP2):
 * EscrowEndpoint::handle(), the request apart from HTTP, driven with requests
 * signed the way the agent signs them (escrow.go).
 *
 *   - Signed by the unlock identity, under its own domain, fresh: anything
 *     else is 401. The message is pinned, because a drift locks every
 *     encrypted machine out of its holder.
 *   - Enrolment with no node key vouching waits for a person (C-3); approved,
 *     the secret goes only to the address it asked from; with this plane's
 *     node vouching (its node key over the identity and this holder's name),
 *     it is handed over at once. A confirmation needs the report key's MAC.
 *   - Unlock: answered from an address the machine may ask from (IPv4 and
 *     IPv6, compared as addresses, the stated set and those seen); refused from
 *     another, and recorded; one answer per boot until it reports unlocked.
 *   - Reports need the report key's MAC: a copy of the root disk, which has
 *     the identity but not the volume, can neither move the address check nor
 *     reset the one-answer rule.
 *   - A person's value from the holder's unlock page is answered once, and
 *     only for the key the box is sealed to. A retired row answers nothing.
 *   - The host report's identity becomes a "known" row for its node; this
 *     plane never enrols its own machine, or one whose holder is elsewhere.
 *
 * Throwaway rows are permanently removed in cleanup.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$made_escrows = array();
$made_nodes = array();
$host = 'dev.getjoinery.com';

// Debris from a run killed before its teardown: the test node, and the rows
// its machines left under their own names.
$debris_nodes = $db->query("SELECT mgn_managed_node_id FROM mgn_managed_nodes WHERE mgn_slug LIKE 'escrowtest-%'")->fetchAll(PDO::FETCH_COLUMN);
$db->exec("DELETE FROM dre_data_root_escrows WHERE dre_mgn_managed_node_id IS NULL
	AND dre_hostname IN ('escrow-test', 'gated', 'HarnessTest escrow node')");

// Teardown runs at finish and after a fatal error alike.
harness_defer(function () use ($db, &$made_escrows, &$made_nodes, $debris_nodes) {
	foreach ($made_escrows as $id) {
		$db->prepare('DELETE FROM dre_data_root_escrows WHERE dre_data_root_escrow_id = ?')->execute(array($id));
	}
	foreach (array_merge($made_nodes, $debris_nodes) as $id) {
		$db->prepare('DELETE FROM dre_data_root_escrows WHERE dre_mgn_managed_node_id = ?')->execute(array($id));
		$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ?')->execute(array($id));
		$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_managed_node_id = ?')->execute(array($id));
	}
});

/** A machine: its unlock identity, report key, and how it signs. */
function escrow_machine(): array {
	$kp = sodium_crypto_sign_keypair();
	return array(
		'pub'  => base64_encode(sodium_crypto_sign_publickey($kp)),
		'sec'  => sodium_crypto_sign_secretkey($kp),
		'rk'   => random_bytes(32),
	);
}

/** One request as the agent sends it: [action, raw, headers]. */
function escrow_req(array $m, string $action, array $payload, bool $mac = false, ?int $ts = null): array {
	$payload['action'] = $action;
	$raw = json_encode($payload);
	$ts = (string)($ts ?? time());
	$nonce = base64_encode(random_bytes(16));
	$msg = EscrowEndpoint::signing_message($action, $m['pub'], $ts, $nonce, hash('sha256', $raw));
	$headers = array('Identity' => $m['pub'], 'Timestamp' => $ts, 'Nonce' => $nonce,
		'Signature' => base64_encode(sodium_crypto_sign_detached($msg, $m['sec'])));
	if ($mac) {
		$headers['Report-Mac'] = base64_encode(hash_hmac('sha256', $raw, $m['rk'], true));
	}
	return array($action, $raw, $headers);
}

function escrow_call(array $req, string $addr, string $host = 'dev.getjoinery.com'): array {
	return EscrowEndpoint::handle($req[0], $req[1], $req[2], $addr, $host);
}

function escrow_row(array $m): ?DataRootEscrow {
	return DataRootEscrow::find_by_identity($m['pub']);
}

$box = json_encode(array('version' => 1, 'artifact' => 'data-root', 'recipients' => array(array(
	'kind' => 'recovery', 'fingerprint' => str_repeat('ab', 32), 'sealed' => base64_encode(random_bytes(32 + 64 + 16))))));

section('The signing message is the agent\'s');
check(EscrowEndpoint::signing_message('unlock', 'ID', '1700000000', 'NONCE', 'HASH')
	=== "joinery-unlock-v1\nPOST\n/api/v1/agent/escrow/unlock\nID\n1700000000\nNONCE\nHASH",
	'joinery-unlock-v1, method, path, identity, timestamp, nonce, body hash, one per line (escrow.go escrowSigningMessage)');

section('Only a fresh request its identity signed is read');
$a = escrow_machine();
$r = escrow_req($a, 'unlock', array('boot_id' => 'b1'));
$bad = $r; $bad[2]['Signature'] = base64_encode(str_repeat("\0", 64));
check((escrow_call($bad, '203.0.113.9')['code'] ?? 0) === 401, 'A signature that does not verify: 401');
$stale = escrow_req($a, 'unlock', array('boot_id' => 'b1'), false, time() - 600);
check((escrow_call($stale, '203.0.113.9')['code'] ?? 0) === 401, 'Ten minutes old: 401');
$tampered = $r; $tampered[1] = str_replace('b1', 'b2', $tampered[1]);
check((escrow_call($tampered, '203.0.113.9')['code'] ?? 0) === 401, 'A body changed after signing: 401');
check((escrow_call(array('nonsense', $r[1], $r[2]), '203.0.113.9')['code'] ?? 0) === 404, 'An unknown action: 404');

section('No new enrolment until this holder takes them (D7: not before the release keys are rotated)');
harness_set_setting_mem('data_root_encryption_offered', '0');
$m0 = escrow_machine();
$r0 = escrow_call(escrow_req($m0, 'enrol', array('report_key' => base64_encode(random_bytes(32)), 'hostname' => 'gated',
	'addresses' => array('192.0.2.30'))), '192.0.2.30');
check($r0['code'] === 403 && strpos((string)($r0['error'] ?? ''), 'takes no new escrow enrolments') !== false
	&& escrow_row($m0) === null, 'Switched off: an enrolment is refused, saying why, and nothing is recorded', json_encode($r0));
check(!DataRootEscrow::takes_new_enrolments(), 'and the auto-enrolment from host reports and the node page ask the same rule');
harness_set_setting_mem('data_root_encryption_offered', '1');
check(DataRootEscrow::takes_new_enrolments(), 'Switched on: taken');

section('Enrolment without its node vouching waits for a person (C-3)');
$enrol = function (array $m, array $extra = array()) use ($box) {
	return escrow_req($m, 'enrol', array_merge(array('addresses' => array('192.0.2.10', '2001:db8::10'), 'box' => $box,
		'hostname' => 'escrow-test', 'report_key' => base64_encode($m['rk'])), $extra));
};
$res = escrow_call($enrol($a), '198.51.100.20');
$row = escrow_row($a);
if ($row) { $made_escrows[] = $row->key; }
check(($res['data']['status'] ?? '') === 'pending' && $row && $row->get('dre_status') === 'pending',
	'Answered pending, and the row waits', json_encode($res));
check(($res['data']['fingerprint'] ?? '') === DataRootEscrow::fingerprint_of($a['pub']) && !isset($res['data']['secret']),
	'It carries the fingerprint to compare, and no secret');
check($row && $row->address_allowed('198.51.100.20') && $row->address_allowed('192.0.2.10') && $row->address_allowed('2001:db8:0::10'),
	'Its stated addresses and the one it asked from are recorded, IPv6 compared as an address');
check(escrow_call(escrow_req($a, 'unlock', array('boot_id' => 'b1')), '198.51.100.20')['code'] === 403,
	'Not enrolled: an unlock is refused');

section('Approved, the secret goes to the address it asked from, once confirmed with the volume open');
$row->approve(1);
$elsewhere = escrow_call($enrol($a), '203.0.113.50');
check(($elsewhere['data']['status'] ?? '') === 'refused' && empty($elsewhere['data']['secret']),
	'Asked from an address it never stated: refused, no secret');
$got = escrow_call($enrol($a), '198.51.100.20');
$secret = base64_decode((string)($got['data']['secret'] ?? ''), true);
check(($got['data']['status'] ?? '') === 'enrolled' && is_string($secret) && strlen($secret) === 32,
	'From where it asked: the 32-byte secret', json_encode(array_diff_key($got['data'] ?? array(), array('secret' => 1))));
$again = escrow_call($enrol($a), '198.51.100.20');
check(base64_decode((string)($again['data']['secret'] ?? ''), true) === $secret, 'Asked again before confirming: the same secret');
check(escrow_call(escrow_req($a, 'confirm', array('slot' => 3)), '198.51.100.20')['code'] === 401,
	'A confirmation without the report key\'s MAC: 401');
$conf = escrow_call(escrow_req($a, 'confirm', array('slot' => 3), true), '198.51.100.20');
$row = escrow_row($a);
check(($conf['data']['status'] ?? '') === 'enrolled' && $row->get('dre_status') === 'enrolled' && (int)$row->get('dre_slot') === 3,
	'With it: enrolled, key slot 3');
$stored = $db->query('SELECT dre_secret FROM dre_data_root_escrows WHERE dre_data_root_escrow_id = ' . (int)$row->key)->fetchColumn();
check(strpos((string)$stored, base64_encode($secret)) === false, 'The secret is kept sealed, never as itself');

section('Unlock: from its addresses, one answer per boot');
$u = escrow_call(escrow_req($a, 'unlock', array('boot_id' => 'b1')), '2001:db8:0:0::10');
check(base64_decode((string)($u['data']['secret'] ?? ''), true) === $secret && ($u['data']['slot'] ?? null) === 3,
	'From its IPv6 address, written another way: answered with the secret and its slot');
$u2 = escrow_call(escrow_req($a, 'unlock', array('boot_id' => 'b2')), '192.0.2.10');
$row = escrow_row($a);
check(($u2['code'] ?? 0) === 403 && strpos((string)$row->get('dre_refusal'), 'no unlock reported since') !== false,
	'A second boot inside 15 minutes, nothing reported: refused, and the holder\'s page says why');
check(escrow_call(escrow_req($a, 'report', array('state' => 'unlocked', 'boot_id' => 'b1')), '192.0.2.10')['code'] === 401,
	'An unlocked report without the MAC (a copy of the disk): refused, the rule stands');
check(isset(escrow_call(escrow_req($a, 'report', array('state' => 'unlocked', 'boot_id' => 'b1'), true), '192.0.2.10')['data']),
	'With it: recorded');
$u3 = escrow_call(escrow_req($a, 'unlock', array('boot_id' => 'b3')), '192.0.2.10');
check(isset($u3['data']['secret']), 'After it reported unlocked, the next boot is answered');

section('A copy of the disk elsewhere');
$copy = escrow_call(escrow_req($a, 'unlock', array('boot_id' => 'c1')), '203.0.113.77');
$row = escrow_row($a);
check(($copy['code'] ?? 0) === 403 && $row->get('dre_refusal_address') === '203.0.113.77',
	'Asked from an address it never stated: refused, and the address recorded');
$moved = escrow_call(escrow_req($a, 'report', array('state' => 'addresses', 'addresses' => array('203.0.113.77'))), '203.0.113.77');
check(($moved['code'] ?? 0) === 401 && !escrow_row($a)->address_allowed('203.0.113.77'),
	'It cannot report its own address first: without the report key, nothing changes');
$swap = escrow_call(escrow_req($a, 'report', array('state' => 'addresses', 'addresses' => array('192.0.2.99')), true), '192.0.2.99');
check(isset($swap['data']) && escrow_row($a)->address_allowed('192.0.2.99'),
	'The machine itself, moved to a new address (an IP swap), reports it with the MAC and is answered there (T-E6)');

section('The holder\'s unlock page (D2b)');
$row = escrow_row($a);
$threw = false;
try { $row->accept_unlock_value(base64_encode(random_bytes(32)), base64_encode(random_bytes(32))); } catch (DataRootEscrowException $e) { $threw = true; }
check($threw, 'A value from a key the box is not sealed to is refused');
// A box sealed to a key we hold, so the fingerprint matches.
$rec = sodium_crypto_box_keypair();
$recpub = sodium_crypto_box_publickey($rec);
$row->note_box(json_encode(array('fingerprint' => hash('sha256', $recpub), 'sealed' => base64_encode(sodium_crypto_box_seal(random_bytes(64), $recpub)))));
$row->save();
$shared = random_bytes(32);
$row->accept_unlock_value(base64_encode($shared), base64_encode($recpub));
$escrow_page = escrow_call(escrow_req($a, 'unlock', array('boot_id' => 'p1')), '192.0.2.10');
check(base64_decode((string)($escrow_page['data']['shared'] ?? ''), true) === $shared && !isset($escrow_page['data']['secret']),
	'The machine\'s next ask gets the value, not the secret');
$stored_shared = $db->query('SELECT dre_pending_shared FROM dre_data_root_escrows WHERE dre_data_root_escrow_id = ' . (int)$row->key)->fetchColumn();
check($stored_shared === null || $stored_shared === false || $stored_shared === '', 'And it is forgotten once given');
check(escrow_row($a)->get('dre_box_changed_time') !== null, 'A box that differs from the one recorded is marked changed (D2b warning)');
// Opened, it says so, as every boot does; the next boot is answered only
// after that report (one answer per boot). Without it the unlock after the
// re-key below passed only when this answer and the earlier report fell in
// the same second.
check(isset(escrow_call(escrow_req($a, 'report', array('state' => 'unlocked', 'boot_id' => 'p1'), true), '192.0.2.10')['data']),
	'Opened by that value, it reports unlocked');

section('A new volume key (D8): proven with the old one, a new secret for the new slot');
$old_rk = $a['rk'];
$new = $a; $new['rk'] = random_bytes(32);
$rekey = function (array $signer, string $addr) use ($new) {
	return escrow_call(escrow_req($signer, 'rekey', array('report_key' => base64_encode($new['rk']), 'addresses' => array())), $addr);
};
check(($rekey($new, '192.0.2.10')['code'] ?? 0) === 401 && ($rekey($a, '192.0.2.10')['code'] ?? 0) === 401,
	'Without the old report key\'s MAC (none, or the new key\'s): 401');
$rk_req = escrow_req($a, 'rekey', array('report_key' => base64_encode($new['rk'])), true);
check((escrow_call($rk_req, '203.0.113.77')['code'] ?? 0) === 403, 'From an address it has not stated: refused');
$rk_req = escrow_req($a, 'rekey', array('report_key' => base64_encode($new['rk'])), true);
$rk = escrow_call($rk_req, '192.0.2.10');
$new_secret = base64_decode((string)($rk['data']['secret'] ?? ''), true);
check(($rk['data']['status'] ?? '') === 'enrolled' && is_string($new_secret) && strlen($new_secret) === 32 && $new_secret !== $secret,
	'With the old key\'s MAC, from its address: a NEW 32-byte secret, never the old one');
check(escrow_row($a)->get('dre_status') === 'approved', 'Waiting for its new slot to be confirmed');
check((escrow_call(escrow_req($a, 'confirm', array('slot' => 1), true), '192.0.2.10')['code'] ?? 0) === 401,
	'Confirmed with the old key\'s MAC: 401, the holder reads the new one now');
$c = escrow_call(escrow_req($new, 'confirm', array('slot' => 1), true), '192.0.2.10');
check(($c['data']['status'] ?? '') === 'enrolled' && (int)escrow_row($a)->get('dre_slot') === 1, 'Confirmed with the new key\'s MAC: enrolled, slot 1');
check((escrow_call(escrow_req($a, 'rekey', array('report_key' => base64_encode(random_bytes(32))), true), '192.0.2.10')['code'] ?? 0) === 401,
	'The old key\'s MAC opens nothing afterwards: a second change with it is refused');
$u2 = escrow_call(escrow_req($new, 'unlock', array('boot_id' => 'k1')), '192.0.2.10');
check(base64_decode((string)($u2['data']['secret'] ?? ''), true) === $new_secret, 'The next boot\'s unlock hands the new secret');
$a = $new;

section('Retired');
$enrolled_row = escrow_row($a);
check(EscrowHolderBackup::enrolment_uncovered($enrolled_row, null)
	&& EscrowHolderBackup::enrolment_uncovered($enrolled_row, '2000-01-01 00:00:00')
	&& !EscrowHolderBackup::enrolment_uncovered($enrolled_row, gmdate('Y-m-d H:i:s', time() + 60)),
	'O11: an enrolment newer than the holder\'s newest backup (or with none at all) is not covered by it; one older is');
escrow_row($a)->retire();
check(escrow_call(escrow_req($a, 'unlock', array('boot_id' => 'r1')), '192.0.2.10')['code'] === 403
	&& escrow_row($a)->secret() === null, 'A retired row answers nothing, and keeps no secret');
check(escrow_row($a)->get('dre_retired_time') && !EscrowHolderBackup::enrolment_uncovered(escrow_row($a), null),
	'O11: giving a key up is recorded as an escrow change, and a retired row needs no backup note');
list($c_old, $c_new) = EscrowHolderBackup::changes_since(gmdate('Y-m-d H:i:s', time() - 30));
check($c_new !== null, 'and it is among the changes since before it');

section('O11: the holder backs itself up within the hour of an escrow change');
$t = 1790000000;
$at = function ($ago) use ($t) { return gmdate('Y-m-d H:i:s', $t - $ago); };
check(!EscrowHolderBackup::decide(null, null, null, $t)['due'], 'nothing changed since the last backup: none');
check(!EscrowHolderBackup::decide($at(120), $at(60), null, $t)['due'], 'a change a minute old: it waits for quiet');
check(EscrowHolderBackup::decide($at(900), $at(700), null, $t)['due'], 'ten quiet minutes: due');
check(EscrowHolderBackup::decide($at(46 * 60), $at(30), null, $t)['due'], 'a burst that will not end: due 45 minutes after its first change');
check(!EscrowHolderBackup::decide($at(900), $at(700), array('start' => $at(300), 'outcome' => 'running'), $t)['due'],
	'a backup running since the change: none');
check(!EscrowHolderBackup::decide($at(900), $at(700), array('start' => $at(300), 'outcome' => 'failed'), $t)['due'],
	'one that failed since the change: not again at once');
check(EscrowHolderBackup::decide($at(9000), $at(8000), array('start' => $at(3700), 'outcome' => 'failed'), $t)['due'],
	'but an hour after it');
check(!EscrowHolderBackup::decide($at(900), $at(700), array('start' => $at(300), 'outcome' => 'success'), $t)['due'],
	'one that succeeded without reaching backup storage counts as not backed up, and is not repeated every minute');
check(EscrowHolderBackup::decide($at(900), $at(700), array('start' => $at(5000), 'outcome' => 'failed'), $t)['due'],
	'a failure from before the change does not hold it back');

section('This plane\'s own node vouching (enrolment after the join)');
$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest escrow node');
$node->set('mgn_slug', 'escrowtest-' . bin2hex(random_bytes(3)));
$node->set('mgn_host', '127.0.0.1');
$node->set('mgn_uptime_enabled', false);
$nkp = sodium_crypto_sign_keypair();
$node->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey($nkp)));
$node->save();
$made_nodes[] = $node->key;
$b = escrow_machine();
$proof = function (string $holder) use ($b, $node, $nkp) {
	$t = (string)time();
	$msg = "joinery-escrow-enrol-v1\n" . $b['pub'] . "\n" . rtrim($holder, '/') . "\n" . $t;
	return array('node_id' => (int)$node->key, 'node_time' => $t, 'holder' => $holder,
		'node_signature' => base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($nkp))));
};
$wrong = escrow_call($enrol($b, $proof('https://elsewhere.example')), '198.51.100.30');
if (escrow_row($b)) { $made_escrows[] = escrow_row($b)->key; }
check(($wrong['data']['status'] ?? '') === 'pending', 'Signed for another holder\'s name: not this holder\'s proof, it waits');
$vouched = escrow_call($enrol($b, $proof('https://' . $host)), '198.51.100.30');
$brow = escrow_row($b);
check(($vouched['data']['status'] ?? '') === 'enrolled' && strlen((string)base64_decode((string)($vouched['data']['secret'] ?? ''), true)) === 32
	&& (int)$brow->get('dre_mgn_managed_node_id') === (int)$node->key,
	'Signed by its node\'s key for this holder: the secret at once, and the row is that node\'s');
check((string)$brow->get('dre_hostname') === 'HarnessTest escrow node',
	'and it is named by its node, not by the hostname its image was born with (B21)', (string)$brow->get('dre_hostname'));

section('An IP swap this holder made moves the node\'s addresses (IpSwapMove)');
$brow->set('dre_addresses', json_encode(array('198.51.100.30', '2001:db8::30')));
$brow->set('dre_seen_addresses', json_encode(array('198.51.100.30')));
$brow->save();
$moved = DataRootEscrow::address_moved((int)$node->key, '198.51.100.30', '198.51.100.31');
$brow = escrow_row($b);
check($moved === 1 && json_decode($brow->get('dre_addresses'), true) === array('198.51.100.31', '2001:db8::30')
	&& json_decode($brow->get('dre_seen_addresses'), true) === array('198.51.100.31')
	&& $brow->address_allowed('198.51.100.31') && !$brow->address_allowed('198.51.100.30'),
	'Its next boot asks from the address it holds now; the one it gave up is no longer its own');
check(DataRootEscrow::address_moved((int)$node->key, '198.51.100.30', '198.51.100.31') === 0
	&& DataRootEscrow::address_moved((int)$node->key + 1000000, '198.51.100.31', '198.51.100.32') === 0,
	'Asked again, or for another node: nothing changes');

section('The host report names the node\'s identity (DataRootEscrowSync)');
$c = escrow_machine();
$enc = JobResultProcessor::host_report_data_root_encryption(array('backing' => 'luks-file', 'open' => false, 'identity' => $c['pub'],
	'holder' => '', 'slot' => 'unknown', 'recipient_fingerprint' => str_repeat('cd', 32), 'box_fingerprint' => str_repeat('cd', 32),
	'box_sealed' => base64_encode(random_bytes(112)), 'opened_by' => ''));
DataRootEscrowSync::from_host_report($node, $enc);
$crow = escrow_row($c);
if ($crow) { $made_escrows[] = $crow->key; }
check($crow && $crow->get('dre_status') === 'known' && (int)$crow->get('dre_mgn_managed_node_id') === (int)$node->key && $crow->box_parts() !== null,
	'A known row for its node, with its box, so the holder can unlock it before it enrols');
check(!DataRootEscrowSync::should_enrol($node, $crow, array_merge($enc, array('holder' => 'https://elsewhere.example'))),
	'Its machine names another holder: not enrolled here');
harness_set_setting_mem('data_root_encryption_offered', '0');
check(!DataRootEscrowSync::should_enrol($node, $crow, $enc), 'No enrolment job while this holder takes no new enrolments (D7)');
harness_set_setting_mem('data_root_encryption_offered', '1');
$mid_run = array();
foreach (array('encrypt', 'decrypt', 'reencrypt') as $mode) {
	$mid_run[] = DataRootEscrowSync::should_enrol($node, $crow, array_merge($enc, array('reencrypt' => $mode))) ? $mode : '-';
}
check(DataRootEscrowSync::should_enrol($node, $crow, $enc) && $mid_run === array('-', '-', '-'),
	'Enrolled once no run is under way; not while an encryption, its undo or a change of key is (B67)', implode(',', $mid_run));
// An older agent's dormant copy refuses an enrolment until it takes over its
// source's node: it is known, and asked nothing.
$dnode = new ManagedNode(NULL);
$dnode->set('mgn_name', 'HarnessTest escrow copy');
$dnode->set('mgn_slug', 'escrowtest-' . bin2hex(random_bytes(3)));
$dnode->set('mgn_host', '127.0.0.1');
$dnode->set('mgn_uptime_enabled', false);
$dnode->set('mgn_install_state', 'copy');
$dnode->set('mgn_agent_primitives', 'data_root_enrol,data_root_recipient,host_report');
$dnode->set('mgn_agent_version', '1.75.2');
$dnode->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
$dnode->save();
$made_nodes[] = $dnode->key;
$d = escrow_machine();
$denc = array_merge($enc, array('identity' => $d['pub'], 'open' => true));
DataRootEscrowSync::from_host_report($dnode, $denc);
$drow = escrow_row($d);
if ($drow) { $made_escrows[] = $drow->key; }
$asked = (int)$db->query("SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = " . (int)$dnode->key
	. " AND mjb_job_type IN ('data_root_enrol', 'data_root_recipient')")->fetchColumn();
check($drow && $drow->get('dre_status') === 'known' && $asked === 0,
	'An older agent\'s dormant copy, open: known, and sent no enrolment or recipient it would refuse every hour', 'jobs: ' . $asked);
$enrols = function (ManagedNode $n) use ($db) {
	return (int)$db->query("SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = " . (int)$n->key
		. " AND mjb_job_type = 'data_root_enrol'")->fetchColumn();
};
$dnode->set('mgn_install_state', null);
$dnode->save();
DataRootEscrowSync::from_host_report($dnode, $denc);
check($enrols($dnode) === 1, 'once it is the site, its next host report enrols it', 'jobs: ' . $enrols($dnode));
DataRootEscrowSync::from_host_report($dnode, $denc);
check($enrols($dnode) === 1, 'that job not yet done holds the next back an hour');
// B60: a job that succeeded holds nothing back. A machine reported not
// enrolled after it is another machine (a switch-over), or a header made again.
$db->exec("UPDATE mjb_management_jobs SET mjb_status = 'completed', mjb_completed_time = now() WHERE mjb_mgn_managed_node_id = "
	. (int)$dnode->key . " AND mjb_job_type = 'data_root_enrol'");
DataRootEscrowSync::from_host_report($dnode, $denc);
check($enrols($dnode) === 2, 'one that succeeded, with this machine still not enrolled: enrolled again at once (B60)', 'jobs: ' . $enrols($dnode));
// B58: a dormant copy whose agent takes the escrow words on a quiet site is
// enrolled before its switch-over, whose address swap restarts it.
$enode = new ManagedNode(NULL);
$enode->set('mgn_name', 'HarnessTest escrow copy 2');
$enode->set('mgn_slug', 'escrowtest-' . bin2hex(random_bytes(3)));
$enode->set('mgn_host', '127.0.0.1');
$enode->set('mgn_uptime_enabled', false);
$enode->set('mgn_install_state', 'copy');
$enode->set('mgn_agent_primitives', 'data_root_enrol,data_root_recipient,host_report');
$enode->set('mgn_agent_version', DataRootEscrowSync::DORMANT_COPY_MIN_AGENT_VERSION);
$enode->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
$enode->save();
$made_nodes[] = $enode->key;
$e = escrow_machine();
DataRootEscrowSync::from_host_report($enode, array_merge($enc, array('identity' => $e['pub'], 'open' => true)));
$erow = escrow_row($e);
if ($erow) { $made_escrows[] = $erow->key; }
check($erow && (int)$erow->get('dre_mgn_managed_node_id') === (int)$enode->key && $enrols($enode) === 1,
	'A dormant copy on agent ' . DataRootEscrowSync::DORMANT_COPY_MIN_AGENT_VERSION . ': enrolled before its switch-over (B58)', 'jobs: ' . $enrols($enode));
// B64: a machine the copy lets go (discarded, or the old server of a kept
// switch-over) is answered no more; another node's row is untouched.
if ($erow) {
	$erow->set('dre_status', 'enrolled');
	$erow->set('dre_slot', 0);
	$erow->save();
}
DataRootEscrowSync::let_go($enode);
$erow2 = $erow ? new DataRootEscrow($erow->key, TRUE) : null;
check($erow2 && (string)$erow2->get('dre_status') === 'retired' && DataRootEscrow::for_node((int)$enode->key) === null
	&& $crow && (string)(new DataRootEscrow($crow->key, TRUE))->get('dre_status') !== 'retired',
	'A machine a site copy lets go has the key held for it retired; another node\'s row is untouched (B64)',
	$erow2 ? (string)$erow2->get('dre_status') : 'no row');
// B35: the same key is sent once an hour at most; a rotated one at once.
$key_a = base64_encode(random_bytes(32));
$key_b = base64_encode(random_bytes(32));
$sent = ManagementJob::createFromBuild($node->key, 'data_root_recipient',
	array('primitive' => 'data_root_recipient', 'params' => array('public_key' => $key_a)), null, null);
$recent = new ReflectionMethod('DataRootEscrowSync', 'recipient_sent_recently');
$recent->setAccessible(true);
check($recent->invoke(null, $node, $key_a) && !$recent->invoke(null, $node, $key_b),
	'A recipient sent within the hour holds back only that key again; a rotated key goes at once (B35)');
// A server made here, sealed to this node's key at its install, is sent the
// key again marked as the operator's, so its site does not call it another
// key (found in the site copy final test's C3); one that says so, or an older
// report that does not say, is not.
$onode = new ManagedNode(NULL);
$onode->set('mgn_name', 'HarnessTest escrow ours');
$onode->set('mgn_slug', 'escrowtest-' . bin2hex(random_bytes(3)));
$onode->set('mgn_host', '127.0.0.1');
$onode->set('mgn_uptime_enabled', false);
$onode->set('mgn_cloud_account', CloudAccounts::MAIN);
$onode->set('mgn_agent_primitives', 'data_root_enrol,data_root_recipient,host_report');
$onode->set('mgn_agent_version', DataRootEscrowSync::DORMANT_COPY_MIN_AGENT_VERSION);
$onode->set('mgn_agent_public_key', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
$onode->save();
$made_nodes[] = $onode->key;
$recipients = function (ManagedNode $n) {
	return (int)DbConnector::get_instance()->get_db_link()->query('SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = '
		. (int)$n->key . " AND mjb_job_type = 'data_root_recipient'")->fetchColumn();
};
$op_fpr = DataRootEscrowSync::operator_fingerprint();
DataRootEscrowSync::push_operator_recipient($onode, array('recipient_fingerprint' => $op_fpr, 'recipient_set_by' => 'operator'));
DataRootEscrowSync::push_operator_recipient($onode, array('recipient_fingerprint' => $op_fpr, 'recipient_set_by' => ''));
$quiet = $recipients($onode);
DataRootEscrowSync::push_operator_recipient($onode, array('recipient_fingerprint' => $op_fpr, 'recipient_set_by' => 'install'));
check($op_fpr !== '' && $quiet === 0 && $recipients($onode) === 1,
	'A server made here sealed to this node\'s key as the install\'s is sent it again as the operator\'s; one marked so, or an older report, is not',
	'fpr ' . ($op_fpr !== '' ? 'known' : 'none') . ', before ' . $quiet . ', after ' . $recipients($onode));

// B55: decrypted to the end, it holds no slot of this holder's.
$gone = new DataRootEscrow(NULL);
$gone->set('dre_identity_key', base64_encode(random_bytes(32)));
$gone->set('dre_status', 'retired');
$gone->set('dre_hostname', 'escrow-test');
$gone->set('dre_mgn_managed_node_id', $dnode->key);
$gone->save();
$made_escrows[] = $gone->key;
$drow->set('dre_status', 'enrolled');
$drow->set('dre_slot', 0);
$drow->save();
$still = function ($row) { return $row && DataRootEscrow::find_by_identity((string)$row->get('dre_identity_key')) !== null; };
DataRootEscrowSync::from_plain_report($dnode, 'not_mounted');
DataRootEscrowSync::from_plain_report($dnode, 'none');
check($still($drow), 'A report with no data root mounted says nothing of one: what it enrolled stays');
DataRootEscrowSync::from_plain_report($dnode, array('path' => '/srv/joinery', 'fstype' => 'xfs'));
check(!$still($drow) && $still($gone) && $still($crow) && DataRootEscrow::for_node((int)$dnode->key) === null,
	'Its data root mounted and plain: its enrolment here goes, a key given up stays on record, another node\'s row is untouched (B55)');
check(JobResultProcessor::host_report_data_root_encryption(array('recipient_set_by' => 'operator'))['recipient_set_by'] === 'operator'
	&& JobResultProcessor::host_report_data_root_encryption(array('recipient_set_by' => 'someone'))['recipient_set_by'] === ''
	&& JobResultProcessor::host_report_data_root_encryption(array())['recipient_set_by'] === '',
	'Who named the recovery key is kept when it is site, operator or install, and empty otherwise (host_report 1.35)');
check(JobResultProcessor::host_report_data_root_encryption('none') === 'none'
	&& JobResultProcessor::host_report_data_root_encryption(array('identity' => 'not base64!', 'holder' => 'http://x', 'slot' => 99))['identity'] === '',
	'The report section is held to its shape');

section('Locked is its own uptime state (D2b, T-U2)');
// A machine at its unlock page answers 503 with X-Joinery-Locked; one that is
// simply failing answers 503 without it.
$doc = sys_get_temp_dir() . '/jy_locked_' . getmypid();
@mkdir($doc);
file_put_contents($doc . '/router.php', '<?php http_response_code(503); if (getenv("JY_LOCKED") === "1") { header("X-Joinery-Locked: 1"); } header("Retry-After: 60"); echo "locked";');
$port = random_int(18100, 18900);
$serve = function (bool $locked) use ($doc, $port) {
	$cmd = 'JY_LOCKED=' . ($locked ? '1' : '0') . ' exec php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($doc . '/router.php') . ' >/dev/null 2>&1';
	$proc = proc_open($cmd, array(), $pipes);
	usleep(400000);
	return $proc;
};
$probe_node = new ManagedNode(NULL);
$probe_node->set('mgn_site_url', 'http://127.0.0.1:' . $port);
$probe_node->set('mgn_host', '127.0.0.1');
$proc = $serve(true);
$probe = NodeHealthProbe::http($probe_node, 5);
check(!$probe['ok'] && $probe['locked'] === true, 'The HTTP probe reads 503 with X-Joinery-Locked as locked', json_encode($probe));
proc_terminate($proc); proc_close($proc);
$proc = $serve(false);
$probe = NodeHealthProbe::http($probe_node, 5);
check(!$probe['ok'] && $probe['locked'] === false && $probe['detail'] === 'HTTP 503', 'A 503 without it is down, as before');
proc_terminate($proc); proc_close($proc);
@unlink($doc . '/router.php'); @rmdir($doc);
$locked_node = new ManagedNode(NULL);
$locked_node->set('mgn_enabled', true);
$locked_node->set('mgn_uptime_enabled', true);
$locked_node->set('mgn_uptime_last_status', 'locked');
$locked_node->set('mgn_uptime_locked_since', '2026-10-11 12:00:00');
$locked_node->set('mgn_site_url', 'https://locked.example');
$inc = (new IncidentSourceDataRootLocked())->evaluate($locked_node);
check(is_array($inc) && $inc['severity'] === IncidentRecord::SEVERITY_CRITICAL && strpos($inc['detail']['Unlock it'], 'own page') !== false,
	'A locked node opens a critical incident; held nowhere here, it names the machine\'s own page');
check((new IncidentSourceSiteDown())->evaluate($locked_node) === null, 'and it is not "site down"');
$inc = (new IncidentSourceDataRootLocked())->evaluate($node);
check($inc === null, 'A node that is not locked opens none');
// The next probe after an unlock is not locked: a failure ends the locked state and counts toward down.
require_once(PathHelper::getIncludePath('plugins/server_manager/tasks/RunNodeUptimeChecks.php'));
$apply_state = new ReflectionMethod('RunNodeUptimeChecks', 'apply_state');
$apply_state->setAccessible(true);
$apply_state->invoke(new RunNodeUptimeChecks(), $locked_node, false, 'HTTP 500');
check($locked_node->get('mgn_uptime_last_status') === 'up' && !$locked_node->get('mgn_uptime_locked_since')
	&& (int)$locked_node->get('mgn_uptime_consecutive_failures') === 1
	&& (new IncidentSourceDataRootLocked())->evaluate($locked_node) === null,
	'Unlocked, one failing probe: no longer locked, the incident closes, the failure counts toward down');

harness_finish();
