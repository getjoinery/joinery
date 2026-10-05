<?php
/** @joinery-test
 * name: receive_only_forwarding
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Relays only receive; sites do the forwarding (specs/relay_receive_only_forwarding.md).
 *
 *   - the relay map carries no forward instructions: a forwarding mailbox and a
 *     forwarding catch-all are spooled like any other, with no destinations, no
 *     forward From identity and no SRS secret;
 *   - the relay pull routes transport-sealed mail through the router, so a
 *     forward leaves through this site's email service, and a re-pull of an
 *     entry whose ack was lost forwards nothing twice;
 *   - nothing is forwarded to a destination until the person at it confirms;
 *     a destination never asked is asked once; the confirmation page confirms
 *     only on the button, never on the link;
 *   - a mailbox or catch-all sealed to a key the server does not hold
 *     (Fortress, Seal at the relay) never forwards;
 *   - the catch-all forward never relays spam;
 *   - migration ifd_001 records every destination already forwarding as
 *     confirmed, so the upgrade stops no working forward.
 *
 * Run: php tests/run.php --only=plugins/mailbox/tests/receive_only_forwarding_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ForwardConfirmation.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapExporter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/forward_confirm_logic.php'));

/** Records what reaches the email service instead of sending it. */
class RofRecordingRelay implements RawMessageRelay {
	public $calls = array();
	public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
		$this->calls[] = $destinations;
		return array_fill_keys($destinations, true);
	}
}
class RofRouter extends InboundEmailRouter {
	public $relay;
	public function __construct() { parent::__construct(); $this->relay = new RofRecordingRelay(); }
	public function resolveRelayProvider() { return $this->relay; }
}

$db = DbConnector::get_instance()->get_db_link();
$suffix = getmypid() . '-' . random_int(1000, 9999);
$like = 'rof-%-' . $suffix . '.example';
$domain_ids = array();
$stage = sys_get_temp_dir() . '/rof-stage-' . $suffix;
@mkdir($stage, 0700, true);
harness_defer(function () use ($db, &$domain_ids, $like, $stage, $suffix) {
	foreach ($domain_ids as $id) {
		$db->exec("DELETE FROM iel_inbound_email_logs WHERE iel_ied_inbound_email_domain_id = " . (int)$id);
		$db->exec("DELETE FROM ief_inbound_email_filters WHERE ief_ied_inbound_email_domain_id = " . (int)$id);
	}
	mailbox_purge_domains($like);
	// The confirmation requests the mail path queued (never sent from a test database).
	$db->prepare("DELETE FROM equ_queued_emails WHERE equ_subject LIKE ?")->execute(array('%-' . $suffix . '.example%'));
	array_map('unlink', glob($stage . '/*') ?: array());
	@rmdir($stage);
});

function rof_domain(string $kind, string $suffix, array $fields, array &$ids): InboundEmailDomain {
	$d = new InboundEmailDomain(NULL);
	$d->set('ied_domain', 'rof-' . $kind . '-' . $suffix . '.example');
	$d->set('ied_is_enabled', true);
	$d->set('ied_catch_all_mode', 'forward');
	foreach ($fields as $k => $v) { $d->set($k, $v); }
	$d->save();
	$ids[] = (int)$d->key;
	return new InboundEmailDomain((int)$d->key, TRUE);
}
function rof_alias(InboundEmailDomain $d, string $local, string $mode, string $dests = '', ?string $level = null): InboundEmailAlias {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$d->key);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', $mode);
	$a->set('iea_destinations', $dests);
	$a->set('iea_is_enabled', true);
	if ($level !== null) { $a->set('iea_security_level', $level); }
	$a->prepare();
	$a->save();
	return new InboundEmailAlias((int)$a->key, TRUE);
}
function rof_raw(string $to, string $token, array $extra = array()): string {
	$lines = array_merge(array('From: Sender <sender@example.com>', 'To: ' . $to, 'Subject: rof ' . $token,
		'Message-ID: <' . $token . '@example.com>'), $extra,
		array('MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', '', 'Body ' . $token . '.', ''));
	return implode("\r\n", $lines);
}
function rof_logs(int $domain_id, string $status): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT * FROM iel_inbound_email_logs
		WHERE iel_ied_inbound_email_domain_id = ? AND iel_status = ? ORDER BY iel_inbound_email_log_id');
	$q->execute(array($domain_id, $status));
	return $q->fetchAll(PDO::FETCH_ASSOC);
}

try {
	$std = rof_domain('std', $suffix, array('ied_reject_unmatched' => true), $domain_ids);

	// ---------------------------------------------------------------------
	section('Nothing is forwarded to a destination until the person at it confirms');
	$fwd = rof_alias($std, 'fwd', 'forward', 'friend@example.test');
	$router = new RofRouter();
	$code = $router->processEmail(rof_raw('fwd@' . $std->get('ied_domain'), 'u1-' . $suffix), 'fwd@' . $std->get('ied_domain'));
	check($code === 0 && $router->relay->calls === array(), 'an unconfirmed destination gets nothing');
	$un = rof_logs((int)$std->key, InboundEmailLog::STATUS_UNCONFIRMED);
	check(count($un) === 1 && $un[0]['iel_destinations'] === 'friend@example.test', 'logged unconfirmed, naming it');
	$row = InboundForwardDestination::find((int)$std->key, (int)$fwd->key, 'friend@example.test');
	check($row && !$row->is_confirmed() && (int)$row->get('ifd_request_count') === 1 && $row->get('ifd_token_hash'),
		'a destination never asked is asked once, by the first message');
	$q = $db->prepare("SELECT count(*) FROM equ_queued_emails WHERE equ_status = ? AND equ_subject LIKE ?");
	$q->execute(array(QueuedEmail::READY_TO_SEND, '%fwd@' . $std->get('ied_domain') . '%'));
	check((int)$q->fetchColumn() === 1, 'the request is queued, so delivery never waits on a send');
	$router->processEmail(rof_raw('fwd@' . $std->get('ied_domain'), 'u2-' . $suffix), 'fwd@' . $std->get('ied_domain'));
	$row = InboundForwardDestination::find((int)$std->key, (int)$fwd->key, 'friend@example.test');
	check((int)$row->get('ifd_request_count') === 1, 'later mail does not ask again');
	check(ForwardConfirmation::request((int)$std->key, (int)$fwd->key, 'friend@example.test') === ForwardConfirmation::OUTCOME_THROTTLED,
		'a resend inside the hour is held back');

	section('The confirmation page confirms on the button, never on the link');
	$token = bin2hex(random_bytes(32));
	$row->set('ifd_token_hash', hash('sha256', $token));
	$row->save();
	$view = forward_confirm_logic(array('t' => $token))->data;
	check($view['is_valid_page'] && !$view['confirmed_now'] && !$view['already_confirmed']
		&& $view['source'] === 'fwd@' . $std->get('ied_domain'), 'opening the link asks, naming the address');
	check(!InboundForwardDestination::find((int)$std->key, (int)$fwd->key, 'friend@example.test')->is_confirmed(),
		'and confirms nothing');
	check(forward_confirm_logic(array('t' => str_repeat('a', 64), 'confirm' => true))->data['is_valid_page'] === false,
		'an unknown token is not a valid page');
	$view = forward_confirm_logic(array('t' => $token, 'confirm' => true))->data;
	check($view['confirmed_now'], 'the button confirms');
	$router = new RofRouter();
	$router->processEmail(rof_raw('fwd@' . $std->get('ied_domain'), 'u3-' . $suffix), 'fwd@' . $std->get('ied_domain'));
	check($router->relay->calls === array(array('friend@example.test')), 'a confirmed destination receives the forward');

	section('Only the confirmed destinations of a mailbox receive it');
	$two = rof_alias($std, 'two', 'forward', 'yes@example.test, no@example.test');
	mailbox_confirm_forward_destination((int)$std->key, (int)$two->key, 'yes@example.test');
	$router = new RofRouter();
	$router->processEmail(rof_raw('two@' . $std->get('ied_domain'), 'm1-' . $suffix), 'two@' . $std->get('ied_domain'));
	check($router->relay->calls === array(array('yes@example.test')), 'relayed to the confirmed one only');
	$un = rof_logs((int)$std->key, InboundEmailLog::STATUS_UNCONFIRMED);
	check(end($un)['iel_destinations'] === 'no@example.test', 'the other is logged unconfirmed');
	check(InboundForwardDestination::find((int)$std->key, null, 'yes@example.test') === null,
		'a confirmation belongs to its mailbox, not the domain');

	// ---------------------------------------------------------------------
	section('The catch-all forward: confirmed only, and never spam');
	$catch = rof_domain('catch', $suffix, array('ied_catch_all_address' => 'catcher@example.test'), $domain_ids);
	$catch_addr = 'anyone@' . $catch->get('ied_domain');
	$router = new RofRouter();
	$router->processEmail(rof_raw($catch_addr, 'c1-' . $suffix), $catch_addr);
	check($router->relay->calls === array() && count(rof_logs((int)$catch->key, InboundEmailLog::STATUS_UNCONFIRMED)) === 1,
		'an unconfirmed catch-all address gets nothing');
	mailbox_confirm_forward_destination((int)$catch->key, null, 'catcher@example.test');
	$router = new RofRouter();
	$router->processEmail(rof_raw($catch_addr, 'c2-' . $suffix), $catch_addr);
	check($router->relay->calls === array(array('catcher@example.test')), 'a confirmed one receives it');
	$router = new RofRouter();
	$router->processEmail(rof_raw($catch_addr, 'c3-' . $suffix,
		array('X-Spam: Yes', 'X-Spam-Status: Yes, score=30.00')), $catch_addr);
	check($router->relay->calls === array() && count(rof_logs((int)$catch->key, InboundEmailLog::STATUS_SPAM_HELD)) === 1,
		'spam to the catch-all is held, not relayed');

	// ---------------------------------------------------------------------
	section('A mailbox sealed to a key the server does not hold never forwards');
	check($fwd->forwarding_offered(), 'a Standard mailbox may forward');
	$fort = rof_alias($std, 'fort', 'forward', 'out@example.test', InboundEmailDomain::LEVEL_FORTRESS);
	mailbox_confirm_alias_destinations($fort);
	check(!$fort->forwarding_offered(), 'a Fortress mailbox may not');
	$router = new RofRouter();
	$code = $router->processEmail(rof_raw('fort@' . $std->get('ied_domain'), 'f1-' . $suffix), 'fort@' . $std->get('ied_domain'));
	check($router->relay->calls === array(), 'a Fortress mailbox saved forwarding relays nothing (it stores instead)', 'exit ' . $code);
	$sealed = rof_domain('sealed', $suffix, array('ied_catch_all_address' => 'x@example.test'), $domain_ids);
	$sealed->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
	$sealed->set('ied_relay_seals_to_owner', true);
	$sealed->save();
	$sealed = new InboundEmailDomain((int)$sealed->key, TRUE);
	check(!$sealed->forwarding_offered(), 'a domain with Seal at the relay offers no catch-all forward');
	check(!rof_alias($sealed, 'box', 'store')->forwarding_offered(), 'nor does a mailbox on it');
	$std_private = rof_domain('private', $suffix, array(), $domain_ids);
	$std_private->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
	$std_private->save();
	check((new InboundEmailDomain((int)$std_private->key, TRUE))->forwarding_offered(),
		'Private without Seal at the relay still forwards (the server reads it at pull)');

	// ---------------------------------------------------------------------
	section('The relay pull forwards through this site, once');
	$kp = (new SealedBox())->generateKeypair();
	$consumer = new RelaySpoolConsumer(new MailboxRelay(NULL));
	$pull_router = new RofRouter();
	foreach (array('router' => $pull_router, 'transport_secret' => $kp['secret']) as $prop => $val) {
		$p = new ReflectionProperty(RelaySpoolConsumer::class, $prop);
		$p->setAccessible(true);
		$p->setValue($consumer, $val);
	}
	$ingest = new ReflectionMethod(RelaySpoolConsumer::class, 'ingestOne');
	$ingest->setAccessible(true);
	$stage_entry = function (string $recipient, string $raw) use ($stage, $kp): array {
		$id = (string)hrtime(true) . '-' . bin2hex(random_bytes(4));
		file_put_contents($stage . '/' . $id . '.seal', (new SealedBox())->sealDek($raw, $kp['public']));
		file_put_contents($stage . '/' . $id . '.meta', json_encode(array('spool_id' => $id, 'recipient' => $recipient,
			'key_kind' => 'transport', 'public_key' => $kp['public'])));
		return array($stage . '/' . $id . '.seal', $stage . '/' . $id . '.meta', $id);
	};
	$e = $stage_entry('fwd@' . $std->get('ied_domain'), rof_raw('fwd@' . $std->get('ied_domain'), 'p1-' . $suffix));
	check($ingest->invoke($consumer, $e[0], $e[1], $e[2]) === 'routed', 'a transport entry for a forwarding mailbox is routed');
	check($pull_router->relay->calls === array(array('friend@example.test')), 'and forwarded through this site\'s email service');
	$q = $db->prepare('SELECT iel_status FROM iel_inbound_email_logs WHERE iel_relay_spool_id = ?');
	$q->execute(array($e[2]));
	check($q->fetchAll(PDO::FETCH_COLUMN) === array(InboundEmailLog::STATUS_FORWARDED), 'its log line carries the spool id');
	check($ingest->invoke($consumer, $e[0], $e[1], $e[2]) === 'dedup' && count($pull_router->relay->calls) === 1,
		'a re-pull after a lost ack forwards nothing twice');
	$store = rof_alias($std, 'keep', 'store');
	$e = $stage_entry('keep@' . $std->get('ied_domain'), rof_raw('keep@' . $std->get('ied_domain'), 'p2-' . $suffix));
	check($ingest->invoke($consumer, $e[0], $e[1], $e[2]) === 'routed', 'a store mailbox\'s entry is routed');
	$q = $db->prepare('SELECT count(*) FROM iem_inbound_email_messages WHERE iem_relay_spool_id = ? AND iem_iea_inbound_email_alias_id = ?');
	$q->execute(array($e[2], (int)$store->key));
	check((int)$q->fetchColumn() === 1, 'its stored row carries the spool id');

	// ---------------------------------------------------------------------
	section('The relay map carries no forward instructions');
	$exporter = (new ReflectionClass('RelayMapExporter'))->newInstanceWithoutConstructor();
	foreach (array('relay' => new MailboxRelay(NULL), 'transport_public_key' => $kp['public'],
			'settings' => Globalvars::get_instance()) as $prop => $val) {
		$p = new ReflectionProperty('RelayMapExporter', $prop);
		$p->setAccessible(true);
		$p->setValue($exporter, $val);
	}
	$frag = json_decode($exporter->build()['fragment'], true);
	foreach (array('srs_secret', 'forward_from_name', 'forward_show_via') as $k) {
		check(!array_key_exists($k, $frag), 'no ' . $k . ' in the fragment');
	}
	$r = $frag['recipients']['fwd@' . $std->get('ied_domain')] ?? array();
	check(($r['mode'] ?? '') === 'store' && !isset($r['destinations']) && !isset($r['forward_from']),
		'a forwarding mailbox is spooled, with no destinations');
	$cd = $frag['domains'][(string)$catch->get('ied_domain')] ?? array();
	check(($cd['catch_all_mode'] ?? '') === 'store' && !isset($cd['catch_all_address']),
		'a forwarding catch-all is spooled, with no address');
	$modes = array_values(array_unique(array_map(function ($x) { return $x['mode']; }, array_values($frag['recipients']))));
	check($modes === array('store'), 'every recipient is store');

	// ---------------------------------------------------------------------
	section('The upgrade stops no working forward');
	$mig_domain = rof_domain('mig', $suffix, array('ied_catch_all_address' => 'old-catch@example.test'), $domain_ids);
	$m1 = rof_alias($mig_domain, 'one', 'forward_and_store', 'old1@example.test');
	$m2 = rof_alias($mig_domain, 'two', 'store');
	$db->prepare("INSERT INTO ief_inbound_email_filters (ief_ied_inbound_email_domain_id, ief_iea_inbound_email_alias_id,
		ief_name, ief_action_forward_to) VALUES (?, NULL, 'rof', 'filter-out@example.test')")->execute(array((int)$mig_domain->key));
	$migration = null;
	foreach (require(PathHelper::getIncludePath('plugins/mailbox/migrations/migrations.php')) as $mig) {
		if ($mig['id'] === 'ifd_001_confirm_existing_destinations') { $migration = $mig; }
	}
	ob_start();
	$result = $migration['up'](DbConnector::get_instance());
	ob_end_clean();
	check($result === true, 'ifd_001 runs');
	$confirmed = function (?int $alias_id, string $dest) use ($mig_domain): bool {
		$row = InboundForwardDestination::find((int)$mig_domain->key, $alias_id, $dest);
		return $row !== null && $row->is_confirmed();
	};
	check($confirmed((int)$m1->key, 'old1@example.test'), 'a mailbox\'s destination is recorded confirmed');
	check($confirmed(null, 'old-catch@example.test'), 'the catch-all address is');
	check($confirmed(null, 'filter-out@example.test') && $confirmed((int)$m1->key, 'filter-out@example.test')
		&& $confirmed((int)$m2->key, 'filter-out@example.test'), 'a domain-wide filter\'s address is, for every mailbox');
	ob_start();
	$migration['up'](DbConnector::get_instance());
	ob_end_clean();
	$q = $db->prepare('SELECT count(*) FROM ifd_inbound_forward_destinations WHERE ifd_ied_inbound_email_domain_id = ?');
	$q->execute(array((int)$mig_domain->key));
	check((int)$q->fetchColumn() === 5, 'a second run adds nothing');
} catch (\Throwable $e) {
	check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
