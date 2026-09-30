<?php
/** @joinery-test
 * name: spam_learning_corrections
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Spam learning teaches corrections, from rebuilt messages, in the pass that
 * can open them (plugins/mailbox/includes/SpamLearning.php).
 *
 * Found on jeremytunnell 2026-09-30 with learning on and an empty corpus: the
 * task had never been activated; had it run, it would have taught every
 * ingest verdict as if a member had made it, found no raw on a lean record,
 * and written off every sealed row as unteachable. What this pins:
 *
 *  - only a member's correction is selected, never the verdict ingest wrote;
 *  - a lean record is rebuilt as one text/plain message from its header block
 *    and body (multipart headers dropped, folded lines too);
 *  - an end-to-end row is marked handled without a request;
 *  - a row sealed to a vault is left to the window: the keyless pass never
 *    selects it, a closed window defers it, an open one teaches it;
 *  - the scanner's answers: 200 taught, 4xx handled, 5xx deferred (unmarked).
 *
 * The scanner is a local stub that records each request, so the assertions
 * hold whether or not rspamd runs on the machine.
 *
 * Run: php tests/run.php db --filter=spam_learning_corrections
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/LearnSpamFeedback.php'));

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run through tests/run.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

/** A stub rspamd controller: answers with the code in <dir>/code, records path and body per request. */
function sl_start_stub(): ?array {
	$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if (!$sock) return null;
	$name = stream_socket_get_name($sock, false);
	fclose($sock);
	$port = (int)substr($name, strrpos($name, ':') + 1);
	$dir = sys_get_temp_dir() . '/spam_learn_stub_' . getmypid() . '_' . $port;
	@mkdir($dir, 0777, true);
	file_put_contents($dir . '/code', '200');
	file_put_contents($dir . '/router.php', '<?php
$dir = __DIR__;
$n = (int)@file_get_contents($dir . "/count") + 1;
file_put_contents($dir . "/count", (string)$n);
file_put_contents($dir . "/req." . $n, $_SERVER["REQUEST_URI"] . "\n" . file_get_contents("php://input"));
http_response_code((int)file_get_contents($dir . "/code"));
echo "{}";
return true;
');
	$proc = proc_open(array('bash', '-c', sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($dir . '/router.php'))),
		array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($proc)) return null;
	for ($i = 0; $i < 100; $i++) {
		$s = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
		if ($s) { fclose($s); return array('proc' => $proc, 'port' => $port, 'dir' => $dir); }
		usleep(50000);
	}
	proc_terminate($proc, SIGKILL);
	return null;
}

function sl_stop_stub(array $stub): void {
	if (is_resource($stub['proc'])) { proc_terminate($stub['proc'], SIGKILL); proc_close($stub['proc']); }
	foreach (glob($stub['dir'] . '/*') as $f) @unlink($f);
	@rmdir($stub['dir']);
}

/** Requests the stub has seen: [[path, body], ...]. */
function sl_requests(array $stub): array {
	$out = array();
	for ($n = 1; is_file($stub['dir'] . '/req.' . $n); $n++) {
		$raw = file_get_contents($stub['dir'] . '/req.' . $n);
		$nl = strpos($raw, "\n");
		$out[] = array(substr($raw, 0, $nl), substr($raw, $nl + 1));
	}
	return $out;
}

function sl_learned(int $id) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT iem_learned_verdict FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return $q->fetchColumn();
}

function sl_message(int $domain_id, ?int $alias_id, array $cols): int {
	$msg = new InboundEmailMessage(NULL);
	$msg->set('iem_ied_inbound_email_domain_id', $domain_id);
	if ($alias_id) $msg->set('iem_iea_inbound_email_alias_id', $alias_id);
	$msg->set('iem_direction', 'inbound');
	$msg->set('iem_sender', 'sender@elsewhere.example');
	$msg->set('iem_recipient', 'me@elsewhere.example');
	$msg->set('iem_message_id_header', 'sl-' . bin2hex(random_bytes(8)) . '@elsewhere.example');
	$msg->set('iem_spam_verdict', InboundEmailMessage::SPAM_VERDICT_HAM);
	foreach ($cols as $k => $v) $msg->set($k, $v);
	$msg->save();
	$msg->load();
	harness_register_model('InboundEmailMessage', intval($msg->key));
	return intval($msg->key);
}

$stub = sl_start_stub();
if ($stub === null) {
	harness_skip('could not start the stub scanner');
	harness_finish();
}

try {

	// -----------------------------------------------------------------------
	section('fixtures');

	harness_set_setting_mem('mailbox_spam_filtering_enabled', '1');
	harness_set_setting_mem('mailbox_spam_learning_enabled', '1');
	harness_set_setting_mem('mailbox_rspamd_controller_url', 'http://127.0.0.1:' . $stub['port']);
	MailboxSpamPolicy::reset();
	check(MailboxSpamPolicy::learningEnabled(), 'learning is on for this test');
	$controller = MailboxSpamPolicy::controllerUrl();

	$owner = make_user('SlOwner');
	$owner_id = intval($owner->key);
	$kp = sodium_crypto_box_keypair();
	$secret = SealedBox::b64url(sodium_crypto_box_secretkey($kp));
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', $owner_id);
	$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($kp)));
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));

	$clear = new InboundEmailDomain(NULL);
	$clear->set('ied_domain', 'sl-clear-' . bin2hex(random_bytes(4)) . '.example');
	$clear->set('ied_is_enabled', true);
	$clear->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
	$clear->save();
	$clear->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($clear->key));

	$sealed_dom = new InboundEmailDomain(NULL);
	$sealed_dom->set('ied_domain', 'sl-sealed-' . bin2hex(random_bytes(4)) . '.example');
	$sealed_dom->set('ied_is_enabled', true);
	$sealed_dom->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$sealed_dom->save();
	$sealed_dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($sealed_dom->key));
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($sealed_dom->key));
	$alias->set('iea_alias', 'me');
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias($alias->key, array($owner_id));
	harness_defer(function () use ($alias) { InboundEmailMailboxGrant::sync_for_alias($alias->key, array()); });

	$headers = "Received: from mx.elsewhere.example\r\nFrom: Sender <sender@elsewhere.example>\r\n"
		. "Subject: Cheap watches\r\nContent-Type: multipart/alternative;\r\n\tboundary=\"b1\"\r\n"
		. "MIME-Version: 1.0\r\nContent-Transfer-Encoding: 7bit\r\nX-Kept: yes";

	// Scanner's own verdict: never a correction.
	$ingested = sl_message(intval($clear->key), null, array('iem_subject' => 'Ingested',
		'iem_body_plain' => 'An ordinary message.', 'iem_raw_headers' => $headers));
	// A lean record the member corrects.
	$lean = sl_message(intval($clear->key), null, array('iem_subject' => 'Cheap watches',
		'iem_body_plain' => "Buy cheap watches today\nwith free shipping.", 'iem_raw_headers' => $headers));
	// A sealed row the member corrects.
	$sealed = sl_message(intval($sealed_dom->key), intval($alias->key), array('iem_subject' => 'Sealed pitch',
		'iem_body_plain' => 'Sealed body words for the corpus.', 'iem_raw_headers' => $headers,
		'iem_recipient' => 'me@' . $sealed_dom->get('ied_domain')));
	$seal = mailbox_protection_seal_batch($sealed_dom, 200);
	check($seal['sealed'] >= 1, 'the sealed-domain row seals at rest', json_encode($seal));

	$svc = new MailboxService(MailboxViewer::forUser(0, 10));
	check($svc->setSpamVerdict(array($lean, $sealed), InboundEmailMessage::SPAM_VERDICT_SPAM) === 2,
		'the member marks two rows as spam');

	// An end-to-end row: its key opens only on the owner's devices.
	$fortress = sl_message(intval($clear->key), null, array('iem_subject' => '', 'iem_body_plain' => ''));
	DbConnector::get_instance()->get_db_link()->prepare(
		"UPDATE iem_inbound_email_messages SET iem_sealed_key = ?, iem_content_sealed = true,
		        iem_spam_verdict = 'spam', iem_spam_corrected_time = now()
		  WHERE iem_inbound_email_message_id = ?")
		->execute(array(InboundEmailMessage::MAIL_KEY_PREFIX . 'fixture', $fortress));

	// -----------------------------------------------------------------------
	section('only corrections are selected, split by what opens them');

	$keyless = SpamLearning::keylessIds(1000);
	check(!in_array($ingested, $keyless, true), 'an ingest verdict is never selected');
	check(in_array($lean, $keyless, true), 'a corrected clear row is selected by the keyless pass');
	check(in_array($fortress, $keyless, true), 'an end-to-end row is too (to be written off)');
	check(!in_array($sealed, $keyless, true), 'a row sealed to a vault is not');
	check(SpamLearning::windowIds($owner_id) === array($sealed), 'it is its owner\'s window work',
		json_encode(SpamLearning::windowIds($owner_id)));

	// -----------------------------------------------------------------------
	section('a lean record is rebuilt as one text/plain message');

	$text = SpamLearning::plainMessage($headers, "line one\nline two");
	check(stripos($text, 'multipart') === false && stripos($text, 'boundary') === false,
		'the multipart header and its folded line are gone');
	check(stripos($text, 'Content-Transfer-Encoding: 7bit') === false, 'the old transfer encoding is gone');
	check(strpos($text, "X-Kept: yes\r\n") !== false && strpos($text, 'Subject: Cheap watches') !== false,
		'the other headers stay');
	check(strpos($text, "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\nline one\r\nline two") !== false,
		'a text/plain declaration, a blank line, then the body with CRLF line ends');

	// -----------------------------------------------------------------------
	section('the keyless pass: clear row taught, end-to-end row written off');

	$r = (new LearnSpamFeedback())->run(array());
	check(($r['status'] ?? '') === 'success', 'the task runs', json_encode($r));
	$reqs = sl_requests($stub);
	check(count($reqs) === 1 && $reqs[0][0] === '/learnspam', 'one learnspam request, for the clear row only',
		json_encode(array_column($reqs, 0)));
	check(isset($reqs[0]) && strpos($reqs[0][1], 'Buy cheap watches today') !== false
		&& strpos($reqs[0][1], 'Content-Type: text/plain') !== false, 'carrying the rebuilt message');
	check(sl_learned($lean) === 'spam', 'the clear row is marked taught');
	check(sl_learned($fortress) === 'spam', 'the end-to-end row is marked handled');
	check(sl_learned($sealed) === false || sl_learned($sealed) === null, 'the sealed row is untouched');
	check(!in_array($lean, SpamLearning::keylessIds(1000), true), 'a taught row is not selected again');

	// -----------------------------------------------------------------------
	section('the window pass: deferred while shut, taught while open');

	VaultUnlock::lockAll($owner_id);
	check(SpamLearning::teach($sealed, $controller) === SpamLearning::DEFERRED, 'a shut window defers it');
	check(sl_learned($sealed) === null, 'and leaves it untaught');
	MailboxSpamPolicy::overrideScannerAvailable(true);
	check(SpamLearning::hasWindowWork($owner_id), 'the heartbeat predicate reports it');

	vault_fixture_open_window($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
	$done = SpamLearning::drainForUser($owner_id, microtime(true) + 5);
	check($done === 1 && sl_learned($sealed) === 'spam', 'an open window teaches it', (string)$done);
	$reqs = sl_requests($stub);
	check(count($reqs) === 2 && strpos($reqs[1][1], 'Sealed body words for the corpus.') !== false,
		'with the opened body');
	check(!SpamLearning::hasWindowWork($owner_id), 'and there is no window work left');

	// -----------------------------------------------------------------------
	section('the scanner\'s answers');

	$svc->setSpamVerdict(array($lean), InboundEmailMessage::SPAM_VERDICT_HAM);
	file_put_contents($stub['dir'] . '/code', '500');
	check(SpamLearning::teach($lean, $controller) === SpamLearning::DEFERRED, 'a 500 defers');
	check(sl_learned($lean) === 'spam', 'and the marker still says what was last taught');
	file_put_contents($stub['dir'] . '/code', '400');
	check(SpamLearning::teach($lean, $controller) === SpamLearning::HANDLED, 'a 400 (the message refused) is handled');
	check(sl_learned($lean) === 'ham', 'and stops re-selecting');
	$reqs = sl_requests($stub);
	check(end($reqs)[0] === '/learnham', 'a flip-back is taught the other way');

} catch (Throwable $e) {
	check(false, 'unexpected exception: ' . get_class($e) . ': ' . $e->getMessage(), $e->getTraceAsString());
} finally {
	MailboxSpamPolicy::overrideScannerAvailable(null);
	MailboxSpamPolicy::reset();
	VaultUnlock::lockAll($owner_id ?? 0);
	sl_stop_stub($stub);
}

harness_finish();
