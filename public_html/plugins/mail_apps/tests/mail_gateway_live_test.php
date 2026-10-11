<?php
/** @joinery-test
 * name: mail_gateway_live
 * tier: live
 * env: dev-only
 * needs: [openssl]
 * timeout: 300
 */
/**
 * The whole path, live (specs/mail_apps_plugin.md § Tests, "Gateway"): the
 * built gateway binary, run on high loopback ports in front of this site,
 * spoken to the way a mail program speaks — IMAP over TLS and SMTP
 * submission over TLS — with a real app password.
 *
 * Covers: TLS by SNI; LOGIN with the mailbox address and an app password;
 * a wrong password refused; LIST with special-use attributes; SELECT INBOX;
 * UID FETCH of flags and sizes; a whole message whose length is the size told;
 * STORE \Flagged reaching mailbox; SMTP AUTH PLAIN, and a From that is not the
 * mailbox refused 550 (nothing is sent).
 *
 * Creates its own member, mailbox, mail and app password and removes them.
 * Needs the mail_apps plugin active and the site answering on 127.0.0.1:443.
 *
 * Run: php -d apc.enable_cli=1 plugins/mail_apps/tests/mail_gateway_live_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('MailAppContext')) {
	harness_skip('the mail_apps plugin is not active here');
	harness_finish();
}
require_once(__DIR__ . '/lib/mail_apps_fixtures.php');
$bin = PathHelper::getIncludePath('plugins/mail_apps/provisioning/bin/mail-gateway-' . php_uname('m'));
if (!is_file($bin)) {
	harness_skip('no gateway binary for this machine', $bin);
	harness_finish();
}
$host = (string)parse_url(LibraryFunctions::get_absolute_url('/'), PHP_URL_HOST);

// ---- fixtures -------------------------------------------------------------
$user = make_user('MailGatewayLive', 0);
$uid = (int)$user->key;
$domain = mafx_domain('gwlive');
$alias = mafx_alias($domain, 'reader', $uid);
$address = strtolower($alias->get_full_address());
$m1 = mafx_deliver($alias, $domain, mafx_raw($address, 'Over the wire', "Hello from the gateway test.\nSecond line."));
$m2 = mafx_deliver($alias, $domain, mafx_raw($address, 'With a file', 'see attached', array(
	array('type' => 'application/octet-stream', 'name' => 'x.bin', 'body' => random_bytes(3000)))));
// Mail apps must be allowed for the site to answer the gateway (the purpose's
// switch, and requires_setting on the actions): switched on for the run, put
// back as it was at teardown.
$was_enabled = (string)Globalvars::get_instance()->get_setting('mail_apps_enabled');
Setting::put('mail_apps_enabled', '1');
harness_defer(function () use ($was_enabled) { Setting::put('mail_apps_enabled', $was_enabled === '1' ? '1' : '0'); });
$minted = ApiKey::CreateAppPassword($uid, 'mail', 'Live gate');
harness_register_model('ApiKey', (int)$minted['api_key']->key);
$password = $minted['password'];

// ---- the gateway ----------------------------------------------------------
$dir = sys_get_temp_dir() . '/mailgw-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($dir . '/sites', 0700, true);
harness_defer(function () use ($dir) { exec('rm -rf ' . escapeshellarg($dir)); });
exec('openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 1 -subj /CN=' . escapeshellarg($host)
	. ' -addext subjectAltName=DNS:' . escapeshellarg($host) . ' -keyout ' . escapeshellarg("$dir/key.pem")
	. ' -out ' . escapeshellarg("$dir/cert.pem") . ' 2>/dev/null', $o, $code);
check($code === 0, 'A throwaway certificate for ' . $host);
file_put_contents("$dir/sites/live.json", json_encode(array(
	'site' => 'live', 'hostname' => $host, 'upstream' => 'https://127.0.0.1:443',
	'cert_file' => "$dir/cert.pem", 'key_file' => "$dir/key.pem")));
touch("$dir/sites/live.enabled");
$imap_port = 20000 + random_int(0, 9999);
$smtp_port = $imap_port + 1;
// The tree keeps files at 644; run a copy that may execute.
copy($bin, "$dir/mail-gateway");
chmod("$dir/mail-gateway", 0700);
$proc = proc_open(array("$dir/mail-gateway", '-config-dir', $dir, '-imap-listen', "127.0.0.1:$imap_port", '-smtp-listen', "127.0.0.1:$smtp_port"),
	array(0 => array('file', '/dev/null', 'r'), 1 => array('file', "$dir/gateway.log", 'a'), 2 => array('file', "$dir/gateway.log", 'a')), $pipes);
harness_defer(function () use ($proc) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } });
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
	usleep(100000);
	$probe = @stream_socket_client("tcp://127.0.0.1:$imap_port", $e, $es, 1);
	if ($probe) { fclose($probe); $ready = true; }
}
check($ready, 'The gateway is listening', @file_get_contents("$dir/gateway.log"));

$connect = function (int $port) use ($host) {
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false,
		'SNI_enabled' => true, 'peer_name' => $host)));
	$s = @stream_socket_client("ssl://127.0.0.1:$port", $e, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
	if ($s) { stream_set_timeout($s, 60); }
	return $s;
};
$imap = $connect($imap_port);
check((bool)$imap, 'TLS to the IMAP port, chosen by its name');
$greeting = $imap ? (string)fgets($imap) : '';
check(strpos($greeting, '* OK') === 0, 'A greeting', trim($greeting));

$n = 0;
$cmd = function (string $line) use ($imap, &$n): array {
	$tag = 'a' . (++$n);
	fwrite($imap, "$tag $line\r\n");
	$lines = array();
	while (($l = fgets($imap)) !== false) {
		$lines[] = $l;
		// A literal {N}: read its bytes whole.
		if (preg_match('/\{(\d+)\}\r\n$/', $l, $m)) {
			$want = (int)$m[1];
			$buf = '';
			while (strlen($buf) < $want && !feof($imap)) { $buf .= fread($imap, $want - strlen($buf)); }
			$lines[] = $buf;
		}
		if (strpos($l, "$tag ") === 0) { break; }
	}
	return $lines;
};
$last = function (array $lines) { return trim((string)end($lines)); };
$q = function ($s) { return '"' . addcslashes($s, "\"\\") . '"'; };

section('Signing in');
$r = $cmd('LOGIN ' . $q($address) . ' ' . $q('jm-aaaaaaaaaaaaaaaa-' . str_repeat('a', 52)));
check(strpos($last($r), 'NO') !== false, 'A wrong app password is refused', $last($r));
$r = $cmd('LOGIN ' . $q($address) . ' ' . $q($password));
check(strpos($last($r), ' OK') !== false, 'The mailbox address and the app password sign in', $last($r));

section('Folders');
$r = implode('', $cmd('LIST "" "*"'));
check(strpos($r, '"INBOX"') !== false || strpos($r, ' INBOX') !== false, 'INBOX is listed');
check(strpos($r, '\\Sent') !== false && strpos($r, '\\Trash') !== false && strpos($r, '\\Junk') !== false,
	'Special uses are listed', $r);
$r = $cmd('SELECT INBOX');
check(strpos(implode('', $r), '* 2 EXISTS') !== false && strpos($last($r), 'OK') !== false, 'INBOX holds the two messages', implode('', $r));

section('Reading');
$r = implode('', $cmd('UID FETCH 1:* (FLAGS RFC822.SIZE)'));
preg_match_all('/UID (\d+).*?RFC822\.SIZE (\d+)|RFC822\.SIZE (\d+).*?UID (\d+)/', $r, $mm, PREG_SET_ORDER);
$sizes = array();
foreach ($mm as $x) {
	$u = $x[1] !== '' ? $x[1] : $x[4];
	$sizes[(int)$u] = (int)($x[2] !== '' ? $x[2] : $x[3]);
}
check(count($sizes) === 2, 'Both messages carry a number and a size', $r);
$first_uid = $sizes ? min(array_keys($sizes)) : 0;
$r = $cmd("UID FETCH $first_uid (BODY.PEEK[])");
$body = '';
foreach ($r as $chunk) { if (strpos($chunk, 'Hello from the gateway test.') !== false) { $body = $chunk; } }
check($body !== '', 'The whole message comes back', $last($r));
check($body !== '' && strlen($body) === ($sizes[$first_uid] ?? -1), 'Its length is the size told', strlen($body) . ' vs ' . ($sizes[$first_uid] ?? -1));

section('Changing');
$r = $cmd("UID STORE $first_uid +FLAGS (\\Flagged)");
check(strpos($last($r), 'OK') !== false, 'STORE \\Flagged is accepted', $last($r));
$starred = DbConnector::get_instance()->get_db_link()->query(
	"SELECT iem_is_starred FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $m1")->fetchColumn();
check((bool)$starred, 'The message is starred in Joinery');
$cmd('LOGOUT');
fclose($imap);

section('The app-access headers switch nothing off elsewhere');
$http = function (string $path, array $hdrs, string $json = '{}') use ($host) {
	$ch = curl_init('https://127.0.0.1' . $path);
	curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array_merge(array('Host: ' . $host, 'Content-Type: application/json'), $hdrs),
		CURLOPT_RESOLVE => array($host . ':443:127.0.0.1'), CURLOPT_TIMEOUT => 20));
	curl_setopt($ch, CURLOPT_URL, 'https://' . $host . $path);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return array($code, (string)$body);
};
list($code, $body) = $http('/api/v1/action/password_reset_passkey_options', array('X-Joinery-App-Secret: x'));
check($code === 403, 'A sessionless action refuses the app-access headers', $code . ' ' . substr($body, 0, 200));
list($code, $body) = $http('/api/v1/action/mail_apps/mail_folders', array('X-Joinery-App-Username: ' . $address,
	'X-Joinery-App-Secret: ' . $password), json_encode(array('mailbox' => $address)));
check($code === 200, 'The credential works through the API itself', $code . ' ' . substr($body, 0, 200));
Setting::put('mail_apps_enabled', '0');
list($code, $body) = $http('/api/v1/action/mail_apps/mail_folders', array('X-Joinery-App-Username: ' . $address,
	'X-Joinery-App-Secret: ' . $password), json_encode(array('mailbox' => $address)));
check($code === 403 || $code === 401, 'With mail apps switched off, the same credential is refused', $code . ' ' . substr($body, 0, 200));
Setting::put('mail_apps_enabled', '1');

section('Submission');
$smtp = $connect($smtp_port);
check((bool)$smtp, 'TLS to the submission port');
$read = function () use ($smtp) {
	$out = '';
	while (($l = fgets($smtp)) !== false) { $out .= $l; if (strlen($l) >= 4 && $l[3] === ' ') { break; } }
	return $out;
};
$say = function ($line) use ($smtp, $read) { fwrite($smtp, $line . "\r\n"); return $read(); };
$read();
$say('EHLO gate.test');
$r = $say('AUTH PLAIN ' . base64_encode("\0" . $address . "\0" . $password));
check(strpos($r, '235') === 0, 'AUTH PLAIN with the app password', trim($r));
$say('MAIL FROM:<' . $address . '>');
$say('RCPT TO:<nobody@example.org>');
$say('DATA');
$r = $say("From: <someone@else.example>\r\nTo: <nobody@example.org>\r\nSubject: not mine\r\n\r\nbody\r\n.");
check(strpos($r, '550') === 0, 'A From that is not the mailbox is refused 550, and nothing is sent', trim($r));
$say('QUIT');
fclose($smtp);

harness_finish();
