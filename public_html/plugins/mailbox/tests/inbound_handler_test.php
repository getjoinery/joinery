<?php
/** @joinery-test
 * name: inbound_handler
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Handler aliases: a store-mode alias that also names a plugin handler.
 *
 *   - the registry reads declarations, hands out the handler object, and creates one
 *     alias per handler per domain (ensureAlias), idempotently, refusing a taken local
 *     part, a sealing domain, and a relay too old to accept +tag;
 *   - the router stores every message first, then hands the stored row to the handler;
 *     duplicates and mail filed as junk for a hard reason (auth, scanner) are not handed
 *     over, a sealing alias never is, and "not mine", a missing handler and a thrown
 *     exception are each logged with the message left stored;
 *   - `base+tag` finds a handler alias and only a handler alias;
 *   - a handler alias takes no mailbox grants, and the health check names one on a
 *     sealing level.
 *
 * Run: php tests/run.php test-db --filter=inbound_handler
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));

/** A handler that records what it was handed and does what the test tells it to. */
class IhtTestHandler implements InboundAddressHandler {
	public static $calls = array();
	public static $mode = 'handle';   // handle | decline | throw

	public function handle(InboundEmailMessage $msg, ?string $tag): bool {
		self::$calls[] = array('id' => intval($msg->key), 'tag' => $tag);
		if (self::$mode === 'throw') {
			throw new RuntimeException('handler blew up');
		}
		return self::$mode === 'handle';
	}
}

/** Not a handler at all. */
class IhtNotAHandler {}

$db = DbConnector::get_instance()->get_db_link();
$suffix = getmypid() . '-' . random_int(1000, 9999);
$like = 'iht-%-' . $suffix . '.example';

$domain_ids = array();
harness_defer(function () use ($db, &$domain_ids, $like) {
	foreach ($domain_ids as $id) {
		$db->exec("DELETE FROM iel_inbound_email_logs WHERE iel_ied_inbound_email_domain_id = " . (int)$id);
	}
	mailbox_purge_domains($like, 'iht-%@example.test');
});

function iht_domain(string $kind, string $suffix, array $fields, array &$ids): InboundEmailDomain {
	$d = new InboundEmailDomain(NULL);
	$d->set('ied_domain', 'iht-' . $kind . '-' . $suffix . '.example');
	$d->set('ied_is_enabled', true);
	$d->set('ied_reject_unmatched', true);
	foreach ($fields as $k => $v) { $d->set($k, $v); }
	$d->save();
	$ids[] = (int)$d->key;
	return $d;
}

/** Declare handlers the way the registry reads them from plugin.json (no plugin to declare them here). */
function iht_declare(array $handlers): void {
	$p = new ReflectionProperty(InboundHandlerRegistry::class, 'declared');
	$p->setValue(null, $handlers);
}

function iht_raw(string $to, string $from = 'someone@sender.example', string $subject = 'hello'): string {
	return "Return-Path: <" . $from . ">\r\nFrom: Someone <" . $from . ">\r\nTo: <" . $to . ">\r\n"
		. "Subject: " . $subject . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@sender.example>\r\n"
		. "Date: " . gmdate('D, d M Y H:i:s') . " +0000\r\n\r\nbody text\r\n";
}

$ok_auth = array('source' => 'mailgun', 'dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'pass');
$bad_auth = array('source' => 'mailgun', 'dkim' => 'fail', 'spf' => 'fail', 'dmarc' => 'fail', 'dmarc_policy' => 'reject');

harness_set_setting_mem('mailbox_receive_mode', 'direct');
harness_set_setting_mem('mailbox_spam_filtering_enabled', '1');

$std  = iht_domain('std', $suffix, array(), $domain_ids);
$std2 = iht_domain('std2', $suffix, array(), $domain_ids);
$priv = iht_domain('priv', $suffix, array('ied_security_level' => 'private', 'ied_level_set_time' => gmdate('Y-m-d H:i:s')), $domain_ids);
$off  = iht_domain('off', $suffix, array('ied_is_enabled' => false), $domain_ids);
$imap = iht_domain('imap', $suffix, array('ied_is_imap_source' => true), $domain_ids);

// A handler key is a local part, so keep it unique to this run.
$key = 'iht' . preg_replace('/\D/', '', $suffix);
iht_declare(array(
	$key => array('class' => 'IhtTestHandler', 'label' => 'Test handler', 'plugin' => 'test'),
	$key . 'x' => array('class' => 'IhtNotAHandler', 'label' => 'Not a handler', 'plugin' => 'test'),
	$key . 'ghost' => array('class' => 'IhtNoSuchClass', 'label' => 'Ghost', 'plugin' => 'test'),
));

section('The registry hands out handlers');

check(InboundHandlerRegistry::isDeclared($key) && !InboundHandlerRegistry::isDeclared('nobody'),
	'a declared key is known, an undeclared one is not');
check(InboundHandlerRegistry::handlerFor($key) instanceof IhtTestHandler, 'the handler object comes from the declared class');
check(InboundHandlerRegistry::handlerFor($key . 'x') === null, 'a class that is not an InboundAddressHandler is no handler');
check(InboundHandlerRegistry::handlerFor($key . 'ghost') === null, 'a class that does not resolve is no handler');
check(InboundHandlerRegistry::handlerFor('nobody') === null, 'an undeclared key is no handler');

section('Relay versions: the +tag fallback needs 3.9');

check(RelayVersion::acceptsSubaddresses('3.9') && RelayVersion::acceptsSubaddresses('3.10')
	&& !RelayVersion::acceptsSubaddresses('3.8') && !RelayVersion::acceptsSubaddresses(''),
	'3.9 and 3.10 accept sub-addresses; 3.8 and a relay that has not said do not');

section('ensureAlias: one alias per handler per domain');

$r = InboundHandlerRegistry::ensureAlias($key, $std);
check($r['alias'] !== null && $r['reason'] === null, 'creates the handler alias', (string)$r['reason']);
$first = $r['alias'];
check($first->get('iea_alias') === $key && $first->handler_key() === $key
	&& $first->get('iea_delivery_mode') === InboundEmailAlias::MODE_STORE && $first->get('iea_is_enabled'),
	'its local part is the key, it stores, and it names the handler');
$r2 = InboundHandlerRegistry::ensureAlias($key, intval($std->key));
check($r2['alias'] !== null && intval($r2['alias']->key) === intval($first->key), 'asking again returns the same alias (idempotent)');
$n = $db->query("SELECT COUNT(*) FROM iea_inbound_email_aliases WHERE iea_alias = '" . $key . "' AND iea_ied_inbound_email_domain_id = " . (int)$std->key)->fetchColumn();
check((int)$n === 1, 'and still exactly one row');

$db->exec("UPDATE iea_inbound_email_aliases SET iea_is_enabled = false WHERE iea_inbound_email_alias_id = " . (int)$first->key);
$r3 = InboundHandlerRegistry::ensureAlias($key, $std);
check($r3['alias'] !== null && $r3['alias']->get('iea_is_enabled'), 're-enables a handler alias that was switched off');

$r4 = InboundHandlerRegistry::ensureAlias($key, $std2);
check($r4['alias'] !== null && intval($r4['alias']->key) !== intval($first->key), 'a second domain gets its own alias');
check(intval(InboundHandlerRegistry::aliasFor($key, $std)->key) === intval($first->key)
	&& intval(InboundHandlerRegistry::aliasFor($key, $std2)->key) === intval($r4['alias']->key),
	'and the first stays where it was: aliasFor() answers per domain');
check(InboundHandlerRegistry::aliasFor($key, $priv) === null, 'aliasFor() is null on a domain with none');

$plain_key = $key . 'p';
iht_declare(InboundHandlerRegistry::declared() + array($plain_key => array('class' => 'IhtTestHandler', 'label' => 'P', 'plugin' => 'test')));
$plain = new InboundEmailAlias(NULL);
$plain->set('iea_ied_inbound_email_domain_id', intval($std->key));
$plain->set('iea_alias', $plain_key);
$plain->set('iea_delivery_mode', 'store');
$plain->save();
$r5 = InboundHandlerRegistry::ensureAlias($plain_key, $std);
check($r5['alias'] === null && strpos((string)$r5['reason'], 'already used') !== false,
	'refuses a local part an ordinary mailbox already holds', (string)$r5['reason']);

$r6 = InboundHandlerRegistry::ensureAlias($key, $priv);
check($r6['alias'] === null && strpos((string)$r6['reason'], 'Private') !== false, 'refuses a sealing domain', (string)$r6['reason']);
check(InboundHandlerRegistry::ensureAlias($key, $off)['alias'] === null, 'refuses a disabled domain');
check(InboundHandlerRegistry::ensureAlias($key, $imap)['alias'] === null, 'refuses an IMAP-feed anchor domain');
check(InboundHandlerRegistry::ensureAlias('nobody', $std)['alias'] === null, 'refuses a handler no plugin declares');

harness_set_setting_mem('mailbox_receive_mode', 'relay');
$r7 = InboundHandlerRegistry::ensureAlias($key . 'p', $std2);
check($r7['alias'] === null && strpos((string)$r7['reason'], 'relay version') !== false,
	'behind a relay that reports no version (or one below ' . RelayVersion::ACCEPTS_SUBADDRESSES . ') it is refused, saying why', (string)$r7['reason']);
check(InboundHandlerRegistry::eligibleDomains() === array(), 'and no domain is eligible');
harness_set_setting_mem('mailbox_receive_mode', 'direct');

$eligible = array_map(function ($d) { return intval($d->key); }, InboundHandlerRegistry::eligibleDomains());
check($eligible === array(intval($std->key), intval($std2->key)),
	'eligible domains are the Standard, enabled, authoritative ones, lowest id first', implode(',', $eligible));
check(intval(InboundHandlerRegistry::defaultDomain()->key) === intval($std->key), 'the default is the first of them');

section('The router stores, then hands the stored row to the handler');

$router = new InboundEmailRouter();
$addr = $key . '@' . $std->get('ied_domain');
IhtTestHandler::$calls = array();
IhtTestHandler::$mode = 'handle';
$raw = iht_raw($addr);
check($router->processEmail($raw, $addr, $ok_auth) === 0, 'mail to the handler alias is accepted');
$stored = new MultiInboundEmailMessage(array('recipient' => $addr));
check(count($stored) === 1, 'and stored exactly as any store-mode alias stores it');
check(count(IhtTestHandler::$calls) === 1 && IhtTestHandler::$calls[0]['tag'] === null
	&& IhtTestHandler::$calls[0]['id'] === intval($stored->get(0)->key),
	'the handler was handed the stored row, with no tag for the bare address');
$logline = function (string $like) use ($db, $std) {
	$q = $db->prepare("SELECT iel_destinations FROM iel_inbound_email_logs WHERE iel_ied_inbound_email_domain_id = ?
		AND iel_status = 'handler' AND iel_destinations LIKE ? ORDER BY iel_inbound_email_log_id DESC LIMIT 1");
	$q->execute(array(intval($std->key), $like));
	return (string)$q->fetchColumn();
};
check($logline('%handled') !== '', 'the outcome "handled" is in the routing log');

section('A +tag reaches the handler with the tag');

IhtTestHandler::$calls = array();
$tagged = $key . '+r7k2abc@' . $std->get('ied_domain');
check($router->processEmail(iht_raw($tagged), $tagged, $ok_auth) === 0, 'mail to alias+tag is accepted');
check(count(IhtTestHandler::$calls) === 1 && IhtTestHandler::$calls[0]['tag'] === 'r7k2abc', 'and the handler is handed the tag');
$upper = strtoupper($key) . '+AbC@' . strtoupper($std->get('ied_domain'));
IhtTestHandler::$calls = array();
$router->processEmail(iht_raw($upper), $upper, $ok_auth);
check(count(IhtTestHandler::$calls) === 1 && IhtTestHandler::$calls[0]['tag'] === 'abc', 'in any case (the tag arrives lowercased)');

section('Sub-addresses find a handler alias, and only a handler alias');

check($router->lookupAlias($key . '+x', $std) !== null && intval($router->lookupAlias($key . '+x', $std)->key) === intval($first->key),
	'lookupAlias: alias+tag finds the handler alias');
check($router->lookupAlias($plain_key . '+x', $std) === null,
	'lookupAlias: an alias that names no handler is never sub-addressed');
check(InboundEmailAlias::GetByAddress($key . '+x@' . $std->get('ied_domain')) instanceof InboundEmailAlias,
	'GetByAddress: alias+tag finds the handler alias');
check(InboundEmailAlias::GetByAddress($plain_key . '+x@' . $std->get('ied_domain')) === false,
	'GetByAddress: and not an alias without a handler');
check($router->lookupAlias($key, $std)->handler_key() === $key, 'the exact address still wins');
check($router->lookupAlias('+x', $std) === null, 'a +tag with no base finds nothing');

section('Junk, duplicates and sealing aliases are not handed over');

IhtTestHandler::$calls = array();
$spam_addr = $key . '+junk@' . $std->get('ied_domain');
check($router->processEmail(iht_raw($spam_addr), $spam_addr, $bad_auth) === 0, 'mail failing authentication is accepted');
$sq = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_recipient = ?");
$sq->execute(array($spam_addr));
$spam_row = new InboundEmailMessage(intval($sq->fetchColumn()), TRUE);
check($spam_row->key && $spam_row->get('iem_spam_verdict') === 'spam' && $spam_row->get('iem_spam_reason') === 'auth',
	'and stored, filed as junk for the auth reason');
check(IhtTestHandler::$calls === [] && $logline('%withheld (filed as junk: auth)') !== '',
	'it is withheld from the handler, and the routing log says so');

// The other reasons are tested on the stored row: a verdict from the learned guesses
// (sender_history, bayes) does not withhold, a hard one (scanner) does.
$handOver = new ReflectionMethod(InboundEmailRouter::class, 'handOver');
$ref_msg = function (string $verdict, string $reason) use ($router, $std, $first, $key, $ok_auth, $db) {
	$to = $key . '+r' . bin2hex(random_bytes(3)) . '@' . $std->get('ied_domain');
	$router->processEmail(iht_raw($to), $to, $ok_auth);
	$q = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_recipient = ?");
	$q->execute(array($to));
	$id = intval($q->fetchColumn());
	$db->prepare("UPDATE iem_inbound_email_messages SET iem_spam_verdict = ?, iem_spam_reason = ? WHERE iem_inbound_email_message_id = ?")
		->execute(array($verdict, $reason, $id));
	return array(new InboundEmailMessage($id, TRUE), $to);
};
foreach (array('scanner' => false, 'sender_history' => true, 'bayes' => true) as $reason => $handed) {
	list($m, $to) = $ref_msg('spam', $reason);
	IhtTestHandler::$calls = array();
	$handOver->invoke($router, $first, $m, array('from' => 'someone@sender.example'), $to, $std);
	check((count(IhtTestHandler::$calls) === 1) === $handed,
		'junk for the "' . $reason . '" reason is ' . ($handed ? 'still handed over' : 'withheld'));
}

IhtTestHandler::$calls = array();
$dup_to = $key . '@' . $std->get('ied_domain');
$dup_raw = iht_raw($dup_to);
$router->processEmail($dup_raw, $dup_to, $ok_auth);
$router->processEmail($dup_raw, $dup_to, $ok_auth);
check(count(IhtTestHandler::$calls) === 1, 'the same message delivered twice is handed over once');

$sealing = new InboundEmailAlias(intval($first->key), TRUE);
$sealing->set('iea_security_level', 'private');
list($m2, $to2) = $ref_msg('ham', 'none');
IhtTestHandler::$calls = array();
$handOver->invoke($router, $sealing, $m2, array('from' => 'someone@sender.example'), $to2, $std);
check(IhtTestHandler::$calls === [] && $logline('%withheld (the mailbox seals its content)') !== '',
	'an alias that seals its content never hands over; the mail stays stored');

section('A handler that declines, is missing, or throws leaves the mail stored');

IhtTestHandler::$mode = 'decline';
$d_to = $key . '+nope@' . $std->get('ied_domain');
check($router->processEmail(iht_raw($d_to), $d_to, $ok_auth) === 0, '"not mine" still accepts the message');
check($logline('%not mine') !== '' && count(new MultiInboundEmailMessage(array('recipient' => $d_to))) === 1,
	'and logs "not mine" with the message stored');

IhtTestHandler::$mode = 'throw';
$t_to = $key . '+boom@' . $std->get('ied_domain');
check($router->processEmail(iht_raw($t_to), $t_to, $ok_auth) === 0, 'a handler that throws does not fail the delivery');
check($logline('%error') !== '' && count(new MultiInboundEmailMessage(array('recipient' => $t_to))) === 1,
	'the exception is logged and the message stays stored');
IhtTestHandler::$mode = 'handle';

$ghost = new InboundEmailAlias(NULL);
$ghost->set('iea_ied_inbound_email_domain_id', intval($std->key));
$ghost->set('iea_alias', 'ghost' . $key);
$ghost->set('iea_delivery_mode', 'store');
$ghost->set('iea_handler', 'nobody');
$ghost->save();
$g_to = 'ghost' . $key . '@' . $std->get('ied_domain');
check($router->processEmail(iht_raw($g_to), $g_to, $ok_auth) === 0, 'an alias naming a handler no plugin declares still accepts');
$q = $db->prepare("SELECT iel_destinations FROM iel_inbound_email_logs WHERE iel_status = 'handler' AND iel_destinations LIKE 'nobody:%' LIMIT 1");
$q->execute();
check((string)$q->fetchColumn() === 'nobody: handler not available' && count(new MultiInboundEmailMessage(array('recipient' => $g_to))) === 1,
	'"handler not available" is logged and the message stays stored');

section('A handler alias stores only, and takes no member grants');

$ghost2 = new InboundEmailAlias(intval($first->key), TRUE);
$ghost2->set('iea_delivery_mode', 'forward');
$ghost2->set('iea_destinations', 'someone@example.net');
$threw = false;
try { $ghost2->prepare(); } catch (InboundEmailAliasException $e) { $threw = true; }
check($threw, 'a handler alias cannot be set to forward');

$uid = mailbox_make_user('iht-a-' . $suffix . '@example.test', 5);
$refused = false;
try { InboundEmailMailboxGrant::sync_for_alias(intval($first->key), array($uid)); }
catch (InboundEmailMailboxGrantException $e) { $refused = true; }
check($refused, 'a grant on a handler alias is refused');
$none = (int)$db->query("SELECT COUNT(*) FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = " . (int)$first->key)->fetchColumn();
check($none === 0, 'and nothing was written');

section('Health: a handler alias on a sealing level is named');

$passed = true;
try { InboundEmailHealth::checkHandlerAliasLevels(); } catch (ProvisioningCheckFailed $e) { $passed = false; }
check($passed, 'with every handler alias on a Standard level the check passes');
$db->exec("UPDATE iea_inbound_email_aliases SET iea_security_level = 'private' WHERE iea_inbound_email_alias_id = " . (int)$first->key);
$msg = '';
try { InboundEmailHealth::checkHandlerAliasLevels(); } catch (ProvisioningCheckFailed $e) { $msg = $e->getMessage(); }
check(strpos($msg, $key . '@' . $std->get('ied_domain')) !== false, 'raised to Private, the alias is named', $msg);
$holders_ok = true;
try { InboundEmailHealth::checkSealingMailboxHolders(); } catch (ProvisioningCheckFailed $e) {
	$holders_ok = strpos($e->getMessage(), $key . '@') === false;
}
check($holders_ok, 'and it is not also reported as a protected mailbox missing its member');
$db->exec("UPDATE iea_inbound_email_aliases SET iea_security_level = NULL WHERE iea_inbound_email_alias_id = " . (int)$first->key);

harness_finish();
