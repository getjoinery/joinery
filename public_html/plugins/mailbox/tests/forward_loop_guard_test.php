<?php
/** @joinery-test
 * name: forward_loop_guard
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The forward loop guard (specs/security_inventory.md S14).
 *
 * A forwarded message must never be forwarded again by the next Joinery node,
 * or by this one when it comes back. Two owners forwarding to each other, or a
 * rule whose destination lands back on a matching rule, would otherwise relay
 * one message until something rate-limits, and a stranger can start that with
 * one message.
 *
 * What this pins down:
 *
 *  - every forward stamps X-Forwarded-By and Auto-Submitted: auto-forwarded,
 *    and a forwarded message parsed back is refused by the guard — the loop
 *    closes in one hop;
 *  - a foreign Auto-Submitted: auto-forwarded is refused; auto-replied and
 *    auto-generated are not (a bounce or vacation reply still reaches the owner);
 *  - the hop count: FORWARD_MAX_HOPS Received headers refuse, one fewer does not;
 *  - the alias forward path drops a looped message with a rejected log row and
 *    keeps the forward_and_store copy; a plain message still forwards once;
 *  - the filter action path (forwardStoredMessage) relays nothing for a stored
 *    message that arrived carrying the marker — read from the retained header
 *    block, because a lean record keeps no raw and a synthesized forward would
 *    carry none of the arrival headers.
 *
 * Run: php tests/run.php db --filter=forward_loop_guard
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

/** Records what reaches the relay instead of sending it. */
class FlgRecordingRelay implements RawMessageRelay {
	public $calls = array();
	public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
		$this->calls[] = array('raw' => $raw_mime, 'sender' => $envelope_sender, 'to' => $destinations);
		$map = array();
		foreach ($destinations as $d) { $map[$d] = true; }
		return $map;
	}
}

class FlgRouter extends InboundEmailRouter {
	public $forward_calls = 0;
	public $relay;
	public function __construct() { parent::__construct(); $this->relay = new FlgRecordingRelay(); }
	public function forwardEmail($raw_email, $parsed, $alias, $domain, $destinations) {
		$this->forward_calls++;
		$map = array();
		foreach ($destinations as $d) { $map[$d] = true; }
		return $map;
	}
	public function resolveRelayProvider() { return $this->relay; }
	/** buildForwardMessage is private by design; the test reaches it to prove the stamp. */
	public function build($raw, $domain, $to) {
		$m = new ReflectionMethod(InboundEmailRouter::class, 'buildForwardMessage');
		$m->setAccessible(true);
		return $m->invoke($this, $raw, $this->parseEmail($raw), $domain, $to);
	}
}

function flg_raw(array $extra_headers, string $token): string {
	$lines = array(
		'From: Sender <sender@example.com>',
		'To: someone@example.test',
		'Subject: loop guard ' . $token,
		'Message-ID: <' . $token . '@example.com>',
	);
	foreach ($extra_headers as $h) { $lines[] = $h; }
	$lines[] = 'MIME-Version: 1.0';
	$lines[] = 'Content-Type: text/plain; charset=UTF-8';
	$lines[] = '';
	$lines[] = 'Body ' . $token . '.';
	$lines[] = '';
	return implode("\r\n", $lines);
}

$db = DbConnector::get_instance()->get_db_link();
$suffix = substr(md5(uniqid('flg', true)), 0, 8);
mailbox_purge_domains('flg-test-%');

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'flg-test-' . $suffix . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
$domain_id = (int)$domain->key;
harness_defer(function () use ($db, $domain_id) {
	$db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . $domain_id);
	$db->exec("DELETE FROM iel_inbound_email_logs WHERE iel_ied_inbound_email_domain_id = " . $domain_id);
	$db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . $domain_id);
	$db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . $domain_id);
});

$alias_for = function (string $local, string $mode) use ($domain_id) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', $domain_id);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', $mode);
	if ($mode !== InboundEmailAlias::MODE_STORE) {
		$a->set('iea_destinations', 'dest@example.test');
	}
	$a->set('iea_is_enabled', true);
	$a->prepare(); $a->save();
	return $a;
};
$recipient = function (string $local) use ($suffix) { return $local . '@flg-test-' . $suffix . '.example'; };
$count_rows = function (string $token) use ($db) {
	$stmt = $db->prepare("SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_message_id_header = ?");
	$stmt->execute(array('<' . $token . '@example.com>'));
	return (int)$stmt->fetchColumn();
};
$last_log = function () use ($db, $domain_id) {
	$stmt = $db->prepare("SELECT iel_status, iel_error_message FROM iel_inbound_email_logs
		WHERE iel_ied_inbound_email_domain_id = ? ORDER BY iel_inbound_email_log_id DESC LIMIT 1");
	$stmt->execute(array($domain_id));
	return $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
};

$router = new FlgRouter();

// =====================================================================
section('the guard reads the three signals');
// =====================================================================

check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw(array(), 'plain'))) === null,
	'an ordinary message may be forwarded');
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw(array('X-Forwarded-By: Joinery Inbound Email'), 'ours'))) !== null,
	'our own X-Forwarded-By marker refuses');
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw(array('X-Forwarded-By: someone-else'), 'theirs'))) === null,
	'a foreign X-Forwarded-By is not our marker');
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw(array('Auto-Submitted: auto-forwarded'), 'af'))) !== null,
	'Auto-Submitted: auto-forwarded refuses');
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw(array('auto-submitted: Auto-Forwarded (by mailer)'), 'af2'))) !== null,
	'case and a trailing comment do not matter');
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw(array('Auto-Submitted: auto-replied'), 'ar'))) === null,
	'auto-replied is not refused — a vacation reply still reaches the owner');
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw(array('Auto-Submitted: auto-generated'), 'ag'))) === null,
	'nor is auto-generated — a bounce still reaches the owner');

$received = array();
for ($i = 0; $i < InboundEmailRouter::FORWARD_MAX_HOPS - 1; $i++) {
	$received[] = 'Received: from hop' . $i . '.example by relay' . $i . '.example; Tue, 9 Sep 2026 10:00:00 +0000';
}
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw($received, 'hops-ok'))) === null,
	'one below the hop cap may still be forwarded');
$received[] = 'Received: from hop-last.example by relay-last.example; Tue, 9 Sep 2026 10:00:01 +0000';
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail(flg_raw($received, 'hops-over'))) !== null,
	'the hop cap refuses');

// =====================================================================
section('a forward carries both markers, and is refused on its way back in');
// =====================================================================

list($forwarded, $envelope) = $router->build(flg_raw(array(), 'stamp'), $domain, $recipient('stamp'));
check(preg_match('/^X-Forwarded-By: Joinery Inbound Email\r?$/mi', $forwarded) === 1,
	'the forward carries X-Forwarded-By');
check(preg_match_all('/^Auto-Submitted: auto-forwarded\r?$/mi', $forwarded) === 1,
	'and exactly one Auto-Submitted: auto-forwarded');
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail($forwarded)) !== null,
	'parsed back, the forwarded message is refused — the loop closes in one hop');

list($kept, $env2) = $router->build(flg_raw(array('Auto-Submitted: auto-replied'), 'keep'), $domain, $recipient('keep'));
check(preg_match_all('/^Auto-Submitted:/mi', $kept) === 1
	&& preg_match('/^Auto-Submitted: auto-replied\r?$/mi', $kept) === 1,
	'an original that already declares itself auto-submitted keeps that one declaration');
check(InboundEmailRouter::forwardLoopRefusal($router->parseEmail($kept)) !== null,
	'and is still refused on return, by our own marker');

// =====================================================================
section('the alias forward path drops a looped message and keeps the copy');
// =====================================================================

$alias_for('fas' . $suffix, InboundEmailAlias::MODE_FORWARD_AND_STORE);

$r = new FlgRouter();
$code = $r->processEmail(flg_raw(array(), 'flg-plain-' . $suffix), $recipient('fas' . $suffix));
check($code === 0 && $r->forward_calls === 1, 'a plain message is forwarded once', 'code ' . $code . ', forwards ' . $r->forward_calls);

$r = new FlgRouter();
$token = 'flg-loop-' . $suffix;
$code = $r->processEmail(flg_raw(array('X-Forwarded-By: Joinery Inbound Email', 'Auto-Submitted: auto-forwarded'), $token), $recipient('fas' . $suffix));
check($code === 0, 'a looped message is accepted from the wire (never bounced — a bounce could loop too)', 'code ' . $code);
check($r->forward_calls === 0, 'and not forwarded');
check($count_rows($token) === 1, 'while the forward_and_store copy is kept');
$log = $last_log();
check(($log['iel_status'] ?? '') === InboundEmailLog::STATUS_REJECTED
	&& strpos((string)($log['iel_error_message'] ?? ''), 'Forward loop guard') === 0,
	'the log row says why', json_encode($log));

// =====================================================================
section('the filter action path relays nothing for a message that already carries the marker');
// =====================================================================

$alias_for('store' . $suffix, InboundEmailAlias::MODE_STORE);

$r = new FlgRouter();
$token = 'flg-stored-' . $suffix;
$r->processEmail(flg_raw(array('X-Forwarded-By: Joinery Inbound Email'), $token), $recipient('store' . $suffix));
$stmt = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_message_id_header = ?");
$stmt->execute(array('<' . $token . '@example.com>'));
$stored_id = (int)$stmt->fetchColumn();
check($stored_id > 0, 'the stored message exists');
if ($stored_id > 0) {
	$msg = new InboundEmailMessage($stored_id, TRUE);
	$results = $r->forwardStoredMessage($msg, array('elsewhere@example.test'));
	check($results === array() && count($r->relay->calls) === 0,
		'forwardStoredMessage relays nothing', json_encode($results));
}

$r = new FlgRouter();
$token = 'flg-stored-ok-' . $suffix;
$r->processEmail(flg_raw(array(), $token), $recipient('store' . $suffix));
$stmt->execute(array('<' . $token . '@example.com>'));
$plain_id = (int)$stmt->fetchColumn();
if ($plain_id > 0) {
	$msg = new InboundEmailMessage($plain_id, TRUE);
	$results = $r->forwardStoredMessage($msg, array('elsewhere@example.test'));
	check(($results['elsewhere@example.test'] ?? false) === true && count($r->relay->calls) === 1,
		'while an ordinary stored message relays once', json_encode($results));
	check(count($r->relay->calls) === 1
		&& InboundEmailRouter::forwardLoopRefusal($r->parseEmail($r->relay->calls[0]['raw'])) !== null,
		'and what reaches the relay is itself marked, so the next node stops it');
}

harness_finish();
