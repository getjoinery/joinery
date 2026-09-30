<?php
/** @joinery-test
 * name: relay_parse_off_list
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Relay-sealed mail is parsed in the background, never by the mailbox list
 * (plugins/mailbox/docs/overview.md § Parsing the backlog).
 *
 * One parse includes the spam scan, which can wait seconds on the scanner's
 * network lookups; a list that parsed first held a whole mailbox back behind
 * a night's mail (jeremytunnell, 2026-09-30: six scans, 38 s, after one
 * unlock). What this pins:
 *
 *  - the list leaves pending rows pending, shows them sealed, and says
 *    `parsing` while the viewer's window is open — and not while it is shut;
 *  - opening a thread parses that thread's pending rows, so the mail shows;
 *  - a message whose parse lock another request holds is skipped by the drain
 *    and waited for (then found done) by a thread open — one message is never
 *    parsed twice.
 *
 * Run: php tests/run.php db --filter=relay_parse_off_list
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run manually: php -d apc.enable_cli=1 plugins/mailbox/tests/relay_parse_off_list_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

function rp_row(int $id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT iem_pending_parse, iem_relay_sealed_raw, iem_content_sealed FROM iem_inbound_email_messages
		  WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

function rp_pending(int $id): bool {
	$v = rp_row($id)['iem_pending_parse'] ?? null;
	return $v === true || $v === 't' || $v === 1 || $v === '1';
}

try {

	// -----------------------------------------------------------------------
	section('fixtures: a Private mailbox with relay-sealed mail waiting');

	$owner = make_user('RpOwner');
	$owner_id = intval($owner->key);
	$kp = sodium_crypto_box_keypair();
	$secret = SealedBox::b64url(sodium_crypto_box_secretkey($kp));
	$public = SealedBox::b64url(sodium_crypto_box_publickey($kp));

	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', $owner_id);
	$vault->set('uev_public_key', $public);
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));

	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'rp-' . bin2hex(random_bytes(4)) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$domain->save();
	$domain->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$alias->set('iea_alias', 'me');
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias($alias->key, array($owner_id));
	harness_defer(function () use ($alias) {
		InboundEmailMailboxGrant::sync_for_alias($alias->key, array());
	});
	$address = 'me@' . $domain->get('ied_domain');

	$router = new InboundEmailRouter();
	$box = new SealedBox();
	$pending = function (string $subject, string $offset) use ($router, $box, $public, $domain, $alias, $owner_id, $address): int {
		$mid = 'rp-' . bin2hex(random_bytes(8)) . '@elsewhere.example';
		$raw = "From: Sender <sender@elsewhere.example>\r\nTo: $address\r\nSubject: $subject\r\n"
			. "Message-ID: <$mid>\r\nDate: " . gmdate('D, d M Y H:i:s') . " +0000\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n\r\nBody of $subject.\r\n";
		$stored = $router->storeRelayPending(array(
			'recipient' => $address, 'message_id' => "<$mid>", 'size' => strlen($raw),
			'received_utc' => gmdate('Y-m-d H:i:s', strtotime("$offset minutes")),
			'spool_id' => 'rp-' . bin2hex(random_bytes(6)),
		), $box->sealDek($raw, $public), $domain, $alias, $owner_id);
		$msg = $stored['message'];
		harness_register_model('InboundEmailMessage', intval($msg->key));
		return intval($msg->key);
	};
	$first = $pending('First waiting', '-20');
	$second = $pending('Second waiting', '-10');
	$third = $pending('Third waiting', '-5');
	check(rp_pending($first) && rp_pending($second) && rp_pending($third), 'three relay-sealed rows are pending');

	$svc = new MailboxService(MailboxViewer::forUser($owner_id, 5));

	// -----------------------------------------------------------------------
	section('locked: the list says nothing about parsing');

	VaultUnlock::lockAll($owner_id);
	$list = $svc->listThreads(intval($alias->key), array('inbox' => true));
	check(empty($list['parsing']), 'no `parsing` while the window is shut', json_encode(array_keys($list)));
	check(count($list['threads'] ?? array()) === 3, 'the three pending rows list', count($list['threads'] ?? array()));

	// -----------------------------------------------------------------------
	section('open: the list does not parse, and says `parsing`');

	vault_fixture_open_window($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
	check(VaultUnlock::isOpen($owner_id), 'precondition: the window is open');

	$list = $svc->listThreads(intval($alias->key), array('inbox' => true));
	check(!empty($list['parsing']), 'the list says `parsing`');
	check(rp_pending($first) && rp_pending($second) && rp_pending($third), 'and every row is still pending after it');
	$subjects = array_map(function ($t) { return (string)($t['subject'] ?? ''); }, $list['threads'] ?? array());
	check(count(array_filter($subjects, function ($s) { return $s === MailboxService::SEALED_PLACEHOLDER; })) === 3,
		'the pending rows show the sealed placeholder', json_encode($subjects));

	// -----------------------------------------------------------------------
	section('opening a thread parses its pending row');

	$thread_key = null;
	foreach ($list['threads'] as $t) {
		if (intval($t['latest_id']) === $third) { $thread_key = (string)$t['thread_key']; }
	}
	check($thread_key !== null, 'the newest message has its own thread');
	$opened = $svc->getThread(intval($alias->key), (string)$thread_key);
	check(!rp_pending($third), 'opening it parses its row');
	check(($opened[0]['subject'] ?? '') === 'Third waiting', 'and the thread shows its subject',
		(string)($opened[0]['subject'] ?? ''));
	check(rp_pending($first) && rp_pending($second), 'the other threads\' rows are left for the drain');
	$target = $second;

	// -----------------------------------------------------------------------
	section('a message another request is parsing is never parsed twice');

	$settings = Globalvars::get_instance();
	$other = new PDO('pgsql:host=localhost dbname=' . $settings->get_setting('dbname', true, true), 'postgres',
		(string)$settings->get_setting('dbpassword', true, true));
	$other->query('SELECT pg_advisory_lock(' . DeferredIngest::LOCK_CLASS . ', ' . ($target & 0x7FFFFFFF) . ')');

	$key = VaultUnlock::secretKey($owner_id);
	check($key !== null, 'precondition: the window key is readable');
	$drained = DeferredIngest::drainForUser($owner_id, $key);
	check($drained === 1 && !rp_pending($first), 'the drain parses what is not locked', (string)$drained);
	check(rp_pending($target), 'the locked row is skipped, still pending');

	$t0 = microtime(true);
	$opened_now = DeferredIngest::parseMessages($owner_id, $key, array($target), 0.3);
	check($opened_now === 0 && rp_pending($target), 'a thread open waits, then gives up on a lock still held');
	check(microtime(true) - $t0 >= 0.25, 'and it did wait');

	// The holder "finishes": parse it as that request would, then release.
	$other->query('SELECT pg_advisory_unlock(' . DeferredIngest::LOCK_CLASS . ', ' . ($target & 0x7FFFFFFF) . ')');
	$other = null;
	check(DeferredIngest::parseMessages($owner_id, $key, array($target)) === 1, 'once released, the row parses');
	check(!rp_pending($target), 'and is no longer pending');
	check(DeferredIngest::parseMessages($owner_id, $key, array($target)) === 0,
		'a second parse of the same message does nothing');
	check(DeferredIngest::drainForUser($owner_id, $key) === 0, 'and the drain finds nothing left');

	$list = $svc->listThreads(intval($alias->key), array('inbox' => true));
	check(empty($list['parsing']), 'with nothing waiting, the list no longer says `parsing`');

} catch (Throwable $e) {
	check(false, 'unexpected exception: ' . get_class($e) . ': ' . $e->getMessage(), $e->getTraceAsString());
} finally {
	VaultUnlock::lockAll($owner_id ?? 0);
}

harness_finish();
