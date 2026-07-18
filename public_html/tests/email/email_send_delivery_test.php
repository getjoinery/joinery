<?php
/** @joinery-test
 * name: email_send_delivery
 * tier: live
 * env: dev-only
 * needs: [mailgun]
 * timeout: 600
 */
/**
 * Closed-loop outbound delivery: send real mail through the configured providers
 * to a local inbound alias and prove it actually arrives.
 *
 * Unlike a "the provider accepted the request" check, this walks the whole path.
 * It mints a throwaway store-mode alias on the dev inbound domain, sends a token-
 * stamped message, then polls iem_inbound_email_messages until the message lands
 * (typically a few seconds) — so a send that is accepted but never delivered
 * fails. The active provider (Mailgun) is exercised, and the SMTP fallback is
 * exercised via a transport override (SKIPed when SMTP is not configured).
 *
 * Sends real mail, so it is live/dev-only — it needs the dev inbound domain
 * (dev.getjoinery.com) to receive, and self-SKIPs where that domain is absent.
 * Replaces the send/fallback coverage of the retired tests/email/suites
 * framework, at a stronger bar (end-to-end delivery, not just acceptance).
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));

$settings = Globalvars::get_instance();
$db = DbConnector::get_instance()->get_db_link();

// The dev inbound domain that receives the loop. Absent off-dev → the whole
// suite SKIPs rather than failing.
$INBOUND_DOMAIN = 'dev.getjoinery.com';
$domain_id = (int)$db->query(
	"SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
	 WHERE ied_domain = " . $db->quote($INBOUND_DOMAIN) . " AND ied_is_enabled = TRUE"
)->fetchColumn();
if ($domain_id <= 0) {
	harness_skip("inbound domain $INBOUND_DOMAIN not present/enabled — closed-loop delivery not testable here");
	harness_finish();
}

/** A throwaway store-mode alias on the inbound domain; registered for teardown. */
function mig_make_alias(int $domain_id): array {
	$local = 'zzmigsend' . bin2hex(random_bytes(4));
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_forward_destinations', ''); // store-only, no forwards
	$alias->set('iea_is_enabled', true);
	$alias->prepare();
	$alias->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);
	return array($local . '@dev.getjoinery.com', (int)$alias->key);
}

/**
 * Poll the inbound store for a token-stamped message to $addr. On arrival,
 * register the stored row for teardown and return it; null on timeout.
 */
function mig_poll(string $addr, string $token, int $timeout = 150): ?array {
	$db = DbConnector::get_instance()->get_db_link();
	for ($t = 0; $t < $timeout; $t += 5) {
		$q = $db->prepare(
			"SELECT iem_inbound_email_message_id AS id, iem_sender, iem_subject, iem_body_plain
			 FROM iem_inbound_email_messages
			 WHERE iem_recipient LIKE ? AND iem_subject LIKE ?
			 ORDER BY iem_received_time DESC LIMIT 1"
		);
		$q->execute(array('%' . $addr . '%', '%' . $token . '%'));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$row['id']);
			return $row;
		}
		sleep(5);
	}
	return null;
}

$from      = $settings->get_setting('defaultemail');
$from_name = $settings->get_setting('defaultemailname');
$sender    = new EmailSender();

// ── Active provider (Mailgun) ────────────────────────────────────────────
section('active provider (Mailgun) delivers to the local inbound');
list($addr1, $alias_id1) = mig_make_alias($domain_id);
$token1 = 'MIGSEND-' . bin2hex(random_bytes(6));
$msg1 = EmailMessage::create($addr1, 'Delivery check ' . $token1, "Closed-loop delivery. token=$token1\n");
$msg1->from($from, $from_name);
$accepted1 = $sender->send($msg1, false); // false = don't queue on failure
check($accepted1 === true, 'active provider accepted the send', 'send() returned ' . var_export($accepted1, true));
$got1 = mig_poll($addr1, $token1);
check($got1 !== null, 'message actually arrived at the local inbound within the window');
if ($got1) {
	check(strpos((string)$got1['iem_subject'], $token1) !== false, 'stored subject carries the sent token');
	check(strpos((string)$got1['iem_body_plain'], $token1) !== false, 'stored body carries the sent token');
}

// ── SMTP fallback via transport override ─────────────────────────────────
section('SMTP fallback delivers to the local inbound');
$discovered = EmailSender::getDiscoveredProviders();
if (trim((string)$settings->get_setting('smtp_host')) === '' || !isset($discovered['smtp'])) {
	harness_skip('SMTP not configured — skipping the SMTP delivery leg');
} else {
	list($addr2, $alias_id2) = mig_make_alias($domain_id);
	$token2 = 'MIGSMTP-' . bin2hex(random_bytes(6));
	$msg2 = EmailMessage::create($addr2, 'SMTP delivery ' . $token2, "SMTP closed-loop delivery. token=$token2\n");
	$msg2->from($from, $from_name);
	$smtp_class = $discovered['smtp'];
	$smtp = new $smtp_class();
	$accepted2 = $sender->send($msg2, false, $smtp); // force the SMTP transport
	check($accepted2 === true, 'SMTP provider accepted the send', 'send() returned ' . var_export($accepted2, true));
	$got2 = mig_poll($addr2, $token2);
	check($got2 !== null, 'SMTP message actually arrived at the local inbound');
}

harness_finish();
