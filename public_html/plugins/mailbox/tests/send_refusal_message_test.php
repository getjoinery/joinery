<?php
/** @joinery-test
 * name: send_refusal_message
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * MailboxSender::refusedMessage() — what a person sees when the transport
 * refused their message, in the reader's compose and as a mail program's 451
 * through the mail gateway. The send report's reason is shown, so the person
 * learns what to fix; only a refusal with no reason falls back to checking
 * the mailbox connection.
 *
 * Pure function, no DB.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('A refusal with a reason says it');
$m = MailboxSender::refusedMessage(array('error' => 'No email service is configured.'));
check(strpos($m, 'no email service for sending') !== false && strpos($m, 'Settings, Email') !== false
	&& strpos($m, 'mailbox connection') === false,
	'No email service: says so and where an administrator sets one, not the mailbox connection', $m);
$m = MailboxSender::refusedMessage(array('error' => "Email service 'mailgun' is not configured: API key missing"));
check($m === "The message could not be sent: Email service 'mailgun' is not configured: API key missing.",
	'A service missing its settings: the reason, once', $m);
$m = MailboxSender::refusedMessage(array('error' => 'The smtp service refused the message.'));
check($m === 'The message could not be sent: The smtp service refused the message.',
	'A provider refusal: the reason, one full stop', $m);

section('A refusal with no reason');
foreach (array(array('error' => null), array('error' => ''), array('error' => '   '), array()) as $report) {
	check(MailboxSender::refusedMessage($report) === 'The message could not be sent. Check the mailbox connection and try again.',
		'falls back to checking the mailbox connection', json_encode($report));
}

harness_finish();
