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
escrow_row($a)->retire();
check(escrow_call(escrow_req($a, 'unlock', array('boot_id' => 'r1')), '192.0.2.10')['code'] === 403
	&& escrow_row($a)->secret() === null, 'A retired row answers nothing, and keeps no secret');

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

// Cleanup.
foreach ($made_escrows as $id) {
	$db->prepare('DELETE FROM dre_data_root_escrows WHERE dre_data_root_escrow_id = ?')->execute(array($id));
}
foreach ($made_nodes as $id) {
	$db->prepare('DELETE FROM dre_data_root_escrows WHERE dre_mgn_managed_node_id = ?')->execute(array($id));
	$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ?')->execute(array($id));
	$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_managed_node_id = ?')->execute(array($id));
}

harness_finish();
