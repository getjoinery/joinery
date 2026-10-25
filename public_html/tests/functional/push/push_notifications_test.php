<?php
/** @joinery-test
 * name: push_notifications
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Push notifications (community_shared_components.md C5):
 * the shared destinations table, the sender, the browser driver and Notify's
 * push channel. Nothing here reaches the network: every send goes to a Guzzle
 * handler that records the request, and the test decrypts what a browser would
 * receive with the browser key it made.
 *
 * What is pinned:
 *  - the site's key pair is minted once, is a P-256 pair, and a re-mint retires
 *    every browser destination;
 *  - registration accepts only a browser push service over https, and a real
 *    P-256 browser key and 16-byte secret; an endpoint follows whoever
 *    registered it last; a member keeps at most MAX_PER_USER;
 *  - a send is signed with the site's key (VAPID) and encrypted so that only
 *    the browser can read it, and carries title, body and link;
 *  - the content rule: a protected message, or a driver that may not carry
 *    text, gets the generic text;
 *  - the one cleanup path: gone (410) retires at once, repeated failures retire
 *    after MAX_FAILURES, a success clears the count; sign-out on a browser
 *    retires only that browser; an app key's destinations go with the key;
 *  - Notify pushes what it puts in the bell, unless push is unticked for the
 *    signal, and a payload marked push_protected is pushed generic;
 *  - the four browser actions work for the signed-in member only;
 *  - the site has a standalone web app manifest, linked from every page (an
 *    iPhone allows push only to a Home Screen app).
 *
 * Run: php tests/run.php test-db --filter=push_notifications
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
harness_test_mode();

foreach (array('push_config', 'push_subscribe', 'push_unsubscribe', 'push_test') as $action) {
	require_once(PathHelper::getIncludePath('logic/' . $action . '_logic.php'));
}

harness_set_setting_mem('defaultemail', 'push-test@dev.getjoinery.com');
harness_set_setting_mem('defaultemailname', 'Push test');

$db = DbConnector::get_instance()->get_db_link();

// ── helpers ────────────────────────────────────────────────────────────────

$b64u = function ($raw) { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); };
$b64u_dec = function ($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); };

// A browser: a P-256 key pair and an auth secret, as PushManager.subscribe() makes.
$make_browser = function ($label) use ($b64u) {
	$key = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
	$d = openssl_pkey_get_details($key);
	$public = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
	$auth = random_bytes(16);
	return array(
		'key'      => $key,
		'public'   => $public,
		'auth_raw' => $auth,
		'input'    => array(
			'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $label . '-' . bin2hex(random_bytes(8)),
			'p256dh'   => $b64u($public),
			'auth'     => $b64u($auth),
		),
	);
};

// A raw P-256 point as a key openssl can use.
$point_to_key = function ($point) {
	$der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
	return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
};

// What the browser does with an aes128gcm push body (RFC 8291 + RFC 8188).
$decrypt = function ($body, $browser) use ($point_to_key) {
	$salt = substr($body, 0, 16);
	$idlen = ord($body[20]);
	$server_public = substr($body, 21, $idlen);
	$ciphertext = substr($body, 21 + $idlen);
	$shared = openssl_pkey_derive($point_to_key($server_public), $browser['key'], 32);
	$prk_key = hash_hmac('sha256', $shared, $browser['auth_raw'], true);
	$ikm = hash_hmac('sha256', "WebPush: info\0" . $browser['public'] . $server_public . "\x01", $prk_key, true);
	$prk = hash_hmac('sha256', $ikm, $salt, true);
	$cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
	$nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
	$tag = substr($ciphertext, -16);
	$plain = openssl_decrypt(substr($ciphertext, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
	if ($plain === false) return null;
	$plain = rtrim($plain, "\0");
	return substr($plain, -1) === "\x02" ? substr($plain, 0, -1) : null;
};

// The network: every request is recorded and answered with the next status.
$requests = array();
$statuses = array();
WebPushDriver::useHttpHandlerForTest(function ($request, $options) use (&$requests, &$statuses) {
	$requests[] = $request;
	$status = $statuses ? array_shift($statuses) : 201;
	return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response($status));
});
harness_defer(function () { WebPushDriver::useHttpHandlerForTest(null); PushSender::clear_queue(); });

$destinations_of = function ($user_id) use ($db) {
	$q = $db->prepare('SELECT psh_push_destination_id FROM psh_push_destinations WHERE psh_usr_user_id = ? ORDER BY psh_push_destination_id');
	$q->execute(array((int)$user_id));
	return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
};

$keys_before = get_setting_raw(WebPushDriver::KEYS_SETTING);
harness_defer(function () use ($keys_before) {
	set_setting_raw(WebPushDriver::KEYS_SETTING, (string)$keys_before);
	WebPushDriver::resetForTests();
});

$member  = make_user('push_member');
$other   = make_user('push_other');
$super   = make_user('push_super', 10);
foreach (array($member, $other, $super) as $u) {
	harness_defer(function () use ($u) { PushSender::retire_for_user((int)$u->key, 'test teardown'); });
}

try {
	// ------------------------------------------------------------------
	section('The site key pair');

	check(WebPushDriver::provisionKeys(), 'provisioning leaves usable keys');
	$public = WebPushDriver::public_key();
	$public_raw = $b64u_dec((string)$public);
	check(strlen($public_raw) === 65 && $public_raw[0] === "\x04", 'the public key is a P-256 point', strlen($public_raw) . ' bytes');
	$blob = get_setting_raw(WebPushDriver::KEYS_SETTING);
	check(strpos((string)$blob, 'private') === false, 'the stored pair is sealed, not plain text');
	WebPushDriver::provisionKeys();
	WebPushDriver::resetForTests();
	check(WebPushDriver::public_key() === $public, 'provisioning again keeps the same pair');

	// ------------------------------------------------------------------
	section('Registering a browser');

	$browser = $make_browser('member');
	$refused = array(
		'http, not https'        => 'http://fcm.googleapis.com/fcm/send/x',
		'not a push service'     => 'https://example.com/push',
		'a lookalike host'       => 'https://fcm.googleapis.com.example.com/x',
		'a metadata address'     => 'https://169.254.169.254/latest',
		'localhost'              => 'https://localhost/x',
		'an explicit port'       => 'https://fcm.googleapis.com:8443/x',
		'credentials in the URL' => 'https://user@fcm.googleapis.com/x',
	);
	foreach ($refused as $why => $endpoint) {
		$threw = false;
		try { PushSender::register((int)$member->key, 'web', array_merge($browser['input'], array('endpoint' => $endpoint))); }
		catch (InvalidArgumentException $e) { $threw = true; }
		check($threw, 'refuses an endpoint that is ' . $why);
	}
	foreach (array('p256dh' => $b64u(random_bytes(65)), 'auth' => $b64u(random_bytes(12))) as $field => $bad) {
		$threw = false;
		try { PushSender::register((int)$member->key, 'web', array_merge($browser['input'], array($field => $bad))); }
		catch (InvalidArgumentException $e) { $threw = true; }
		check($threw, 'refuses a malformed ' . $field);
	}
	foreach (array('https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/x', 'https://wns2-par02p.notify.windows.com/w/?token=x') as $endpoint) {
		check(WebPushDriver::is_push_service_url($endpoint), 'accepts ' . parse_url($endpoint, PHP_URL_HOST));
	}

	$device_token = bin2hex(random_bytes(32));
	$dest = PushSender::register((int)$member->key, 'web', $browser['input'], $device_token);
	check($dest->key && $destinations_of($member->key) === array((int)$dest->key), 'a member registers this browser');
	$again = PushSender::register((int)$member->key, 'web', $browser['input'], $device_token);
	check((int)$again->key === (int)$dest->key, 'registering the same browser again keeps one row');

	$shared = $make_browser('shared');
	$shared_device = bin2hex(random_bytes(32));
	PushSender::register((int)$other->key, 'web', $shared['input'], $shared_device);
	$refused = null;
	try { PushSender::register((int)$member->key, 'web', $shared['input'], bin2hex(random_bytes(32))); }
	catch (InvalidArgumentException $e) { $refused = $e->getMessage(); }
	check($refused !== null && count($destinations_of($other->key)) === 1,
		'another device that knows the endpoint cannot take it over', (string)$refused);
	$moved = PushSender::register((int)$member->key, 'web', $shared['input'], $shared_device);
	check($destinations_of($other->key) === array() && in_array((int)$moved->key, $destinations_of($member->key), true),
		'the same browser, signed in as someone else, moves the endpoint to them');
	PushSender::retire(array((int)$moved->key), 'test');

	for ($i = 0; $i < PushSender::MAX_PER_USER + 2; $i++) {
		PushSender::register((int)$other->key, 'web', $make_browser('many' . $i)['input']);
	}
	check(count($destinations_of($other->key)) === PushSender::MAX_PER_USER, 'a member keeps at most ' . PushSender::MAX_PER_USER . ' destinations',
		count($destinations_of($other->key)) . ' rows');
	PushSender::retire_for_user((int)$other->key, 'test');

	// ------------------------------------------------------------------
	section('What a browser receives');

	$requests = array();
	$out = PushSender::send(array((int)$member->key), array(
		'title' => 'A title', 'body' => 'A body', 'link' => '/forum/t/1?post=2', 'tag' => 'forum.reply_posted',
	));
	check(($out[(int)$dest->key]['result'] ?? '') === PushDriver::RESULT_SENT, 'the send is reported sent', json_encode($out));
	check(count($requests) === 1, 'one request to the push service', count($requests) . ' requests');
	$req = $requests[0] ?? null;
	if ($req) {
		check((string)$req->getUri() === $browser['input']['endpoint'], 'it goes to the browser\'s endpoint');
		check($req->getHeaderLine('Content-Encoding') === 'aes128gcm', 'encrypted as aes128gcm');
		check($req->getHeaderLine('TTL') === (string)WebPushDriver::TTL, 'held a day for an offline browser', $req->getHeaderLine('TTL'));
		$auth = $req->getHeaderLine('Authorization');
		check(preg_match('/^vapid t=([^,]+), k=(.+)$/', $auth, $m) === 1 && $m[2] === $public, 'signed with the site\'s public key (VAPID)', substr($auth, 0, 20));
		if (!empty($m[1])) {
			list($h, $p, $s) = explode('.', $m[1]);
			$claims = json_decode($b64u_dec($p), true);
			check(($claims['aud'] ?? '') === 'https://fcm.googleapis.com' && ($claims['exp'] ?? 0) > time()
				&& ($claims['sub'] ?? '') === 'mailto:push-test@dev.getjoinery.com', 'the token names the push service, the site and an expiry', json_encode($claims));
			$sig = $b64u_dec($s);
			$der_int = function ($i) { $i = ltrim($i, "\0"); if ($i === '' || ord($i[0]) > 0x7f) $i = "\0" . $i; return "\x02" . chr(strlen($i)) . $i; };
			$der = $der_int(substr($sig, 0, 32)) . $der_int(substr($sig, 32));
			$der = "\x30" . chr(strlen($der)) . $der;
			check(openssl_verify($h . '.' . $p, $der, $point_to_key($public_raw), OPENSSL_ALGO_SHA256) === 1, 'the signature checks against the site\'s public key');
		}
		$plain = $decrypt((string)$req->getBody(), $browser);
		$msg = $plain !== null ? json_decode($plain, true) : null;
		check(is_array($msg) && $msg['title'] === 'A title' && $msg['body'] === 'A body' && $msg['link'] === '/forum/t/1?post=2',
			'the browser decrypts title, body and link', (string)$plain);
		$wrong = $make_browser('wrong');
		$wrong['auth_raw'] = $browser['auth_raw'];
		check($decrypt((string)$req->getBody(), $wrong) === null, 'another browser key cannot read it');
	}

	// ------------------------------------------------------------------
	section('The content rule');

	$web = PushSender::driver('web');
	$full = PushSender::render(array('title' => 'Secret subject', 'body' => 'Secret body', 'link' => '/x'), $web);
	check($full['title'] === 'Secret subject' && $full['body'] === 'Secret body', 'the web driver carries the full text');
	$generic = PushSender::render(array('title' => 'Secret subject', 'body' => 'Secret body', 'link' => '/x', 'protected' => true), $web);
	check($generic['title'] !== 'Secret subject' && strpos($generic['body'], 'Secret') === false && $generic['link'] === '/x',
		'a protected message gets the generic text, link kept', json_encode($generic));
	$textless = new class implements PushDriver {
		public function kind(): string { return 'ios'; }
		public function carriesText(): bool { return false; }
		public function available(): bool { return true; }
		public function register(array $input): array { return array('endpoint' => 'token:' . $input['token'], 'details' => array()); }
		public $sent = array();
		public function send(array $deliveries): array { $o = array(); foreach ($deliveries as $d) { $this->sent[] = $d['message']; $o[(int)$d['destination']->key] = array('result' => PushDriver::RESULT_SENT, 'reason' => ''); } return $o; }
		public function retired(PushDestination $destination, string $reason): void {}
	};
	$g = PushSender::render(array('title' => 'Secret subject', 'body' => 'Secret body', 'generic_title' => 'Site', 'generic_body' => 'Something new'), $textless);
	check($g['title'] === 'Site' && $g['body'] === 'Something new', 'a driver that may not carry text gets the generic text');
	$long = PushSender::render(array('title' => str_repeat('t', 500), 'body' => str_repeat('b', 5000)), $web);
	check(mb_strlen($long['title']) === PushSender::MAX_TITLE && mb_strlen($long['body']) === PushSender::MAX_BODY, 'long text is cut to fit a push');

	// ------------------------------------------------------------------
	section('Send outcomes and the one cleanup path');

	$statuses = array(500);
	PushSender::send(array((int)$member->key), array('title' => 't', 'body' => 'b'));
	$row = new PushDestination((int)$dest->key, true);
	check((int)$row->get('psh_failure_count') === 1, 'a failed send is counted');
	PushSender::send(array((int)$member->key), array('title' => 't', 'body' => 'b'));
	$row = new PushDestination((int)$dest->key, true);
	check((int)$row->get('psh_failure_count') === 0 && $row->get('psh_last_sent_time'), 'a success clears the count');

	$statuses = array_fill(0, PushSender::MAX_FAILURES, 503);
	for ($i = 0; $i < PushSender::MAX_FAILURES - 1; $i++) {
		PushSender::send(array((int)$member->key), array('title' => 't', 'body' => 'b'));
	}
	check($destinations_of($member->key) === array((int)$dest->key), 'it survives ' . (PushSender::MAX_FAILURES - 1) . ' failures in a row');
	PushSender::send(array((int)$member->key), array('title' => 't', 'body' => 'b'));
	check($destinations_of($member->key) === array(), 'and is retired at ' . PushSender::MAX_FAILURES);

	$gone = PushSender::register((int)$member->key, 'web', $browser['input'], $device_token);
	$statuses = array(410);
	$out = PushSender::send(array((int)$member->key), array('title' => 't', 'body' => 'b'));
	check(($out[(int)$gone->key]['result'] ?? '') === PushDriver::RESULT_GONE && $destinations_of($member->key) === array(),
		'a push service answering 410 Gone retires it at once', json_encode($out));

	$here = PushSender::register((int)$member->key, 'web', $browser['input'], $device_token);
	$elsewhere = PushSender::register((int)$member->key, 'web', $make_browser('laptop')['input'], bin2hex(random_bytes(32)));
	check(PushSender::retire_device((int)$other->key, $device_token) === 0, 'another member\'s sign-out on a browser does not touch this member\'s');
	check(PushSender::retire_device((int)$member->key, $device_token) === 1 && $destinations_of($member->key) === array((int)$elsewhere->key),
		'signing out on a browser retires that browser only');

	$key = make_machine_key((int)$member->key, 'push test app key');
	PushSender::register_driver($textless);
	$phone = PushSender::register((int)$member->key, 'ios', array('token' => bin2hex(random_bytes(16))), null, (int)$key['api_key']->key);
	check(PushSender::retire_for_api_key((int)$key['api_key']->key) === 1 && !in_array((int)$phone->key, $destinations_of($member->key), true),
		'revoking an app key retires the destinations registered under it');

	$phone = PushSender::register((int)$member->key, 'ios', array('token' => bin2hex(random_bytes(16))));
	$requests = array();
	PushSender::send(array((int)$member->key), array('title' => 'Full title', 'body' => 'Full body', 'link' => '/l'));
	check(count($textless->sent) === 1 && $textless->sent[0]['title'] !== 'Full title' && count($requests) === 1,
		'one send reaches each kind through its own driver, by its own content rule', json_encode($textless->sent));
	PushSender::reset_drivers();
	PushSender::retire(array((int)$phone->key), 'test');

	// ------------------------------------------------------------------
	section('A re-minted key pair retires every browser');

	check(count($destinations_of($member->key)) === 1, 'the member has a browser registered');
	set_setting_raw(WebPushDriver::KEYS_SETTING, '');
	WebPushDriver::resetForTests();
	check(WebPushDriver::public_key() === null, 'with no pair, push says it cannot send');
	check(WebPushDriver::provisionKeys() && WebPushDriver::public_key() !== $public, 'provisioning mints a new pair');
	check($destinations_of($member->key) === array(), 'and every browser subscribed with the old pair is retired');

	// ------------------------------------------------------------------
	section('Notify pushes what it puts in the bell');

	$sdest = PushSender::register((int)$super->key, 'web', $make_browser('super')['input']);
	$signals = Notify::notifiable_signals();
	$marker = 'HarnessTest push ' . LibraryFunctions::random_string(6);
	$payload = array();
	foreach (array_keys($signals['secret.unreadable']['payload'] ?? array()) as $field) {
		$payload[$field] = $marker;
	}
	$queued_for = function ($uid) {
		$found = array();
		foreach (PushSender::queued() as $item) {
			if ((int)$item[0] === (int)$uid) $found[] = $item[1];
		}
		return $found;
	};
	$cleanup_rows = function () use ($db, $marker) {
		$like = '%' . $marker . '%';
		$db->prepare('DELETE FROM ntf_notifications WHERE ntf_title LIKE ?')->execute(array($like));
		$db->prepare('DELETE FROM equ_queued_emails WHERE equ_subject LIKE ?')->execute(array($like));
	};
	harness_defer($cleanup_rows);

	PushSender::clear_queue();
	SignalBus::dispatch('secret.unreadable', $payload);
	$mine = $queued_for($super->key);
	check(count($mine) === 1 && strpos($mine[0]['title'], $marker) !== false && $mine[0]['tag'] === 'secret.unreadable' && empty($mine[0]['protected']),
		'a superadmin\'s alert is queued for push with its title', json_encode($mine));
	$requests = array();
	PushSender::flush();
	$to_super = array_values(array_filter($requests, function ($r) use ($sdest) { return (string)$r->getUri() === $sdest->get('psh_endpoint'); }));
	check(count($to_super) === 1, 'and sent to their browser at the end of the request');

	PushSender::clear_queue();
	SignalBus::dispatch('secret.unreadable', array_merge($payload, array('push_protected' => true)));
	$mine = $queued_for($super->key);
	check(count($mine) === 1 && !empty($mine[0]['protected']), 'a payload marked push_protected is queued protected');
	check(strpos(PushSender::render($mine[0], $web)['title'], $marker) === false, 'so the browser gets the generic text');

	$states = Notify::preference_states((int)$super->key, $signals);
	$ticked = array_keys(array_filter($states, function ($s) { return $s['subscribed']; }));
	$emails = array_keys(array_filter($states, function ($s) { return $s['email']; }));
	$pushes = array_values(array_diff(array_keys(array_filter($states, function ($s) { return $s['push']; })), array('secret.unreadable')));
	Notify::save_preferences((int)$super->key, $signals, $ticked, $emails, $pushes);
	$pref = NotificationPreference::get_for((int)$super->key, 'secret.unreadable');
	check($pref && $pref->get('ntp_subscribed') && !$pref->get('ntp_push_enabled'), 'unticking push saves a row that keeps the alert');
	PushSender::clear_queue();
	SignalBus::dispatch('secret.unreadable', $payload);
	check($queued_for($super->key) === array(), 'and the alert is no longer pushed');
	check(Notify::preference_states((int)$super->key, $signals)['secret.unreadable']['push'] === false, 'and the page shows push off');
	PushSender::clear_queue();
	$cleanup_rows();

	// ------------------------------------------------------------------
	section('The browser actions');

	$saved_session = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null, 'permission' => $_SESSION['permission'] ?? null);
	harness_defer(function () use ($saved_session) {
		foreach ($saved_session as $k => $v) { if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; } }
	});
	$_SESSION['usr_user_id'] = (int)$member->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = 0;

	$b = $make_browser('action');
	$cfg = push_config_logic(array('endpoint' => $b['input']['endpoint']))->data;
	check($cfg['available'] === true && $cfg['public_key'] === WebPushDriver::public_key() && $cfg['registered'] === false,
		'push_config gives the public key, and says this browser is not registered', json_encode($cfg));
	$r = push_subscribe_logic($b['input']);
	check(!$r->error && PushSender::destination_for((int)$member->key, $b['input']['endpoint']) !== null && PushSender::device_token() !== null,
		'push_subscribe registers it and gives the browser a device cookie', (string)$r->error);
	check(push_config_logic(array('endpoint' => $b['input']['endpoint']))->data['registered'] === true, 'push_config then says registered');
	$r = push_subscribe_logic(array_merge($b['input'], array('endpoint' => 'https://evil.example/x')));
	check((bool)$r->error, 'push_subscribe refuses a non-push address', (string)$r->error);

	$requests = array();
	$t = push_test_logic(array('endpoint' => $b['input']['endpoint']))->data;
	check(!empty($t['sent']) && count($requests) === 1, 'push_test sends to this browser now', json_encode($t));

	$_SESSION['usr_user_id'] = (int)$other->key;
	check((bool)push_test_logic(array('endpoint' => $b['input']['endpoint']))->error, 'another member cannot send a test to it');
	check(push_unsubscribe_logic(array('endpoint' => $b['input']['endpoint']))->data['removed'] === 0, 'or turn it off');
	$_SESSION['usr_user_id'] = (int)$member->key;
	check(push_unsubscribe_logic(array('endpoint' => $b['input']['endpoint']))->data['removed'] === 1
		&& PushSender::destination_for((int)$member->key, $b['input']['endpoint']) === null, 'push_unsubscribe turns it off');

	unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);
	check((bool)push_config_logic(array())->error, 'signed out, the actions refuse');

	// ------------------------------------------------------------------
	section('The Home Screen app');

	// An iPhone opens a Home Screen site as its own app, the only place it
	// allows push, only when the site's manifest says standalone.
	ob_start();
	include PathHelper::getIncludePath('views/manifest.php');
	$manifest = json_decode((string)ob_get_clean(), true);
	check(is_array($manifest) && ($manifest['display'] ?? '') === 'standalone' && ($manifest['start_url'] ?? '') === '/'
		&& ($manifest['name'] ?? '') !== '', 'the manifest names the site and opens standalone at /', json_encode($manifest));
	check(strpos((string)file_get_contents(PathHelper::getIncludePath('serve.php')), "'/manifest.webmanifest' => ['view' => 'views/manifest'") !== false
		&& strpos((string)file_get_contents(PathHelper::getIncludePath('includes/PublicPageBase.php')), '<link rel="manifest" href="/manifest.webmanifest">') !== false,
		'it is routed, and every page links it');
} catch (\Throwable $e) {
	check(false, 'suite ran without an exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
