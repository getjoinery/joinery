<?php
/** @joinery-test
 * name: fortress_phone_flow
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A phone reading Fortress mail, end to end over HTTP (specs/fortress_mobile_apps.md
 * § R2, R4, R15): the calls a phone app makes, in its order, with the keys it
 * would hold.
 *
 *  - sign in (auth/login) → an app session key;
 *  - `mailboxes` names the mailbox Fortress, with its newest_unread_id;
 *  - `thread_list` carries the row sealed, nothing readable;
 *  - `device_key_enroll` → the owner approves (the link page's handover: the
 *    mail secret as PKCS#8 sealed to the device key) → the poll hands the
 *    phone the sealed key, which its device secret opens to the vault's secret;
 *  - with it, the phone opens the row's DEK and the subject;
 *  - `thread` gives every part a signed URL; fetched with no credential at
 *    all, it serves ciphertext that the DEK and the part's AD open to the
 *    original bytes;
 *  - `search_entries` and `device_rules` answer the session key.
 *
 * Set FORTRESS_CAPTURE_DIR to write the envelopes (mailboxes, thread_list,
 * thread) and the test keypair beside them, for the apps' parsing tests.
 */
require_once(__DIR__ . '/api_test_harness.php');

api_test_boot($argv);

require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/fortress_fixture.php'));
require_once(PathHelper::getIncludePath('logic/drive_device_link_approve_logic.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

$db = DbConnector::get_instance()->get_db_link();
$session = SessionControl::get_instance();

$fx = fortress_fixture('Phone');
$owner = new User($fx['owner_id'], TRUE);
$password = bin2hex(random_bytes(12));
$owner->set('usr_password', User::GeneratePassword($password));
$owner->set('usr_is_activated', true);
$owner->save();
harness_defer(function () use ($db, $fx) {
	$db->prepare("DELETE FROM dlk_device_links WHERE dlk_usr_user_id = ?")->execute(array($fx['owner_id']));
	$db->prepare("DELETE FROM sde_sync_devices WHERE sde_usr_user_id = ?")->execute(array($fx['owner_id']));
	$db->prepare("DELETE FROM apk_api_keys WHERE apk_usr_user_id = ?")->execute(array($fx['owner_id']));
});
$mid = fortress_ingest($fx, 'Phone flow numbers', 'wombatquill');
check($mid > 0, 'a Fortress message arrived');
$alias_id = intval($fx['alias']->key);

$capture = getenv('FORTRESS_CAPTURE_DIR') ?: '';
$keep = function (string $name, array $response) use ($capture) {
	if ($capture !== '' && is_dir($capture)) {
		file_put_contents($capture . '/' . $name . '.json', $response['raw']);
	}
};

// ---------------------------------------------------------------------------
section('sign in as the app does');

$r = api_request('POST', '/api/v1/auth/login', array(), array(
	'email' => (string)$owner->get('usr_email'), 'password' => $password, 'device_label' => 'Test iPhone'));
check($r['status'] === 200 && !empty($r['json']['data']['secret_key']), 'auth/login gives a session key', substr($r['raw'], 0, 200));
$h = key_headers((string)($r['json']['data']['public_key'] ?? ''), (string)($r['json']['data']['secret_key'] ?? ''));

// ---------------------------------------------------------------------------
section('the list, before the phone holds the key');

$r = api_request('POST', '/api/v1/action/mailbox/mailboxes', $h, array());
$keep('fortress_mailboxes', $r);
$box = null;
foreach (($r['json']['data']['mailboxes'] ?? array()) as $m) {
	if (intval($m['alias_id']) === $alias_id) { $box = $m; }
}
check($box && $box['security_level'] === 'fortress' && intval($box['newest_unread_id']) === $mid,
	'mailboxes names it Fortress, with the new message as its newest unread', json_encode($box));

$r = api_request('POST', '/api/v1/action/mailbox/thread_list', $h, array('alias_id' => $alias_id));
$keep('fortress_thread_list', $r);
$thread = null;
foreach (($r['json']['data']['threads'] ?? array()) as $t) {
	if (intval($t['latest_id']) === $mid) { $thread = $t; }
}
check($thread && $thread['subject'] === '' && !empty($thread['sealed']['iem_subject'])
	&& strpos($r['raw'], 'wombatquill') === false && strpos($r['raw'], 'Phone flow numbers') === false,
	'thread_list carries the row sealed and nothing readable');

// ---------------------------------------------------------------------------
section('enrollment hands the phone the mail key');

$device = sodium_crypto_box_keypair();
$device_secret = sodium_crypto_box_secretkey($device);
$device_pub = base64_encode(sodium_crypto_box_publickey($device));
$r = api_request('POST', '/api/v1/action/device_key_enroll', $h,
	array('device_pubkey' => $device_pub, 'platform' => 'ios', 'device_name' => 'Test iPhone'));
$enroll = $r['json']['data'] ?? array();
check($r['status'] === 200 && !empty($enroll['link_code']), 'the phone gets a code', substr($r['raw'], 0, 200));

// The link page's handover, as the owner's browser does it: the mail secret
// (PKCS#8) sealed to the device key.
$mail_secret = SealedBox::b64url_decode($fx['pair']['secret']);
$pkcs8 = hex2bin('302e020100300506032b656e04220420') . $mail_secret;
$session->set_api_user($fx['owner_id']);
$approved = drive_device_link_approve_logic(array('code' => $enroll['link_code'] ?? '',
	'sealed_vault_keys' => array('mail' => $fx['box']->sealEdge($pkcs8, $device_pub))));
$session->clear_api_user();
check(!$approved->error, 'the owner approves', (string)$approved->error);

$r = api_request('GET', '/api/v1/auth/device_link/' . ($enroll['poll_token'] ?? ''));
$blob = (string)($r['json']['data']['sealed_vault_keys']['mail'] ?? '');
$opened = $blob !== '' ? $fx['box']->openEdge($blob, SealedBox::b64url($device_secret), $device_pub) : '';
check($opened === $pkcs8, 'the poll\'s sealed key opens with the device secret to the vault\'s PKCS#8');
check(substr($opened, 16) === $mail_secret, 'whose last 32 bytes are the mail secret');

// ---------------------------------------------------------------------------
section('the phone reads');

$dek = fortress_open_dek($fx, (string)$thread['sealed']['sealed_dek']);
check(fortress_open_field($fx, $thread['sealed']['iem_subject'], $dek, 'mail:' . $mid . ':iem_subject') === 'Phone flow numbers',
	'the row key opens the subject');

$r = api_request('POST', '/api/v1/action/mailbox/thread', $h, array('alias_id' => $alias_id, 'thread_key' => $thread['thread_key']));
$keep('fortress_thread', $r);
$msg = $r['json']['data']['messages'][0] ?? array();
check(!empty($msg['fortress']) && count($msg['attachments'] ?? array()) === 3, 'thread: the message and its three parts');
$pdf = null;
$manifest = json_decode(fortress_open_field($fx, $msg['sealed']['iem_attachment_manifest'], $dek, 'mail:' . $mid . ':iem_attachment_manifest'), true);
foreach ((array)$manifest as $e) {
	if (($e['filename'] ?? '') === 'quarterly-report.pdf') { $pdf = $e; }
}
check($pdf !== null, 'the manifest names the PDF');
$part = null;
foreach ($msg['attachments'] as $a) {
	if ($pdf && $a['mime_part'] === $pdf['mime_part']) { $part = $a; }
}
check($part && is_string($part['url']) && strpos($part['url'], 'sig=') !== false, 'which carries a signed URL');
$bytes = $part ? harness_request('GET', $part['url'], array('accept' => null)) : array('status' => 0, 'raw' => '');
check($bytes['status'] === 200 && strncmp($bytes['raw'], 'v1.edge.', 8) === 0,
	'fetched with no credential, it serves ciphertext', 'status ' . $bytes['status']);
$plain = $bytes['status'] === 200 ? fortress_open_field($fx, $bytes['raw'], $dek, 'mail:' . $mid . ':att:' . $part['mime_part']) : '';
check($plain === '%PDF-1.4 fortress report', 'which the row key and the part\'s AD open to the PDF');

// ---------------------------------------------------------------------------
section('device work the session key reaches');

$r = api_request('POST', '/api/v1/action/mailbox/search_entries', $h, array('order' => 'old'));
check($r['status'] === 200, 'search_entries answers', 'status ' . $r['status'] . ' ' . substr($r['raw'], 0, 200));
$r = api_request('POST', '/api/v1/action/mailbox/device_rules', $h, array('alias_id' => $alias_id));
check($r['status'] === 200 && is_array($r['json']['data']['rules'] ?? null), 'device_rules answers', substr($r['raw'], 0, 200));

if ($capture !== '' && is_dir($capture)) {
	file_put_contents($capture . '/fortress_capture_keys.json', json_encode(array(
		'note' => 'Throwaway keypair of the fortress_phone_flow capture: opens the captured rows only.',
		'mail_secret_b64' => base64_encode($mail_secret), 'mail_public_b64' => $fx['pub'], 'message_id' => $mid,
		'subject' => 'Phone flow numbers', 'pdf_mime_part' => $part['mime_part'] ?? null,
		'pdf_plain' => '%PDF-1.4 fortress report', 'pdf_stored' => $bytes['raw'] ?? '',
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

harness_finish();
