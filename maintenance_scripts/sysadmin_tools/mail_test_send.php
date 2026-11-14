<?php
/**
 * mail_test_send.php — what the mail_test_send agent word runs: one fixed
 * message from one of this site's own ordinary mail domains to the one probe
 * mailbox, so a receiver's SPF, DKIM and DMARC verdicts on real mail can be
 * read without a person reading headers.
 *
 * Usage: php mail_test_send.php DOMAIN
 *
 * The message is MailTestProbe's, compiled there: the From local part, the
 * recipient, the subject and the body. The token in the subject is made there
 * and returned, so the message can be found at the receiver, where its
 * Authentication-Results header carries the verdicts.
 *
 * Refused, with nothing sent:
 *   - a domain this site does not host, or an IMAP-source one;
 *   - a protected domain: its mail leaves only through its owner's signed-in
 *     session, and no word goes round that (the Setup page's Send test
 *     message button is that path);
 *   - while the site's email test mode is on (the trap would swallow the
 *     probe and the verdict would be the trap's) or email_dry_run is on;
 *   - a second send for the same domain within ten minutes, kept on this
 *     machine, whoever asks (the Setup page's button shares the record).
 *
 * The receiver is our inbound test node, a stopgap (owner, 2026-11-14): the
 * end state is an address on the site's own management node.
 *
 * Prints one JSON object; exit 0 on an answer (a refusal is an answer), 1 on
 * an error, 2 on bad usage.
 *
 * @version 1.0.0 - the mail_test_send word (specs/agent_recipes_and_vocabulary.md, 2026-11-14)
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

if (count($argv) !== 2) {
	fwrite(STDERR, "Usage: php mail_test_send.php DOMAIN\n");
	exit(2);
}
$domain = strtolower(trim($argv[1]));
if (strlen($domain) > 253 || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
	fwrite(STDERR, "mail_test_send: not a domain name\n");
	exit(2);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');

try {
	if (!class_exists('MailTestProbe')) {
		echo json_encode(array('mailbox' => 'inactive')) . "\n";
		exit(0);
	}
	echo json_encode(MailTestProbe::sendOrdinary($domain)) . "\n";
} catch (\Throwable $e) {
	echo 'ERROR: the test message could not be sent: ' . get_class($e) . "\n";
	exit(1);
}
exit(0);
