<?php
/** @joinery-test
 * name: default_replyto
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Default Reply-To (specs/mailbox_strict_sending_identities.md, step 1):
 * the `defaultreplyto` setting lets a deployment keep its transactional From
 * on an automated sending subdomain while replies land in a human mailbox.
 *
 *  - Ambient send with no Reply-To → the default is applied.
 *  - An explicit Reply-To always wins.
 *  - Empty setting → nothing applied.
 *  - Injected-transport send (send-as-a-mailbox) → never stamped.
 *
 * All sends run under email_dry_run, which returns after the message is fully
 * built — the applied headers are observable with no transport touched.
 * sendBatch() shares the same three-line application (ambient by definition)
 * and is not separately exercised here.
 *
 * Run: php tests/run.php safe --filter=default_replyto
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));

class DefaultReplyToNullTransport implements EmailServiceProvider {
	public static function getKey(): string { return 'null_transport'; }
	public static function getLabel(): string { return 'Null transport (test)'; }
	public static function getSpfMechanism(string $domain): string { return ''; }
	public static function getSettingsFields(): array { return array(); }
	public static function validateConfiguration(): array { return array('valid' => true, 'errors' => array()); }
	public function send(EmailMessage $message): bool { return true; }
	public function sendBatch(EmailMessage $message, array $recipients): array {
		return array('success' => true, 'failed_recipients' => array());
	}
	public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
		return array('success' => true, 'failed_destinations' => array());
	}
}

function default_replyto_message(): EmailMessage {
	$m = new EmailMessage();
	$m->to('recipient@example.com', 'Recipient')
	  ->subject('Default Reply-To test')
	  ->text('body');
	return $m;
}

try {

	harness_set_setting_mem('email_dry_run', '1');
	harness_set_setting_mem('defaultemail', 'hello@mg.site.example');
	harness_set_setting_mem('defaultemailname', 'Site');

	// -----------------------------------------------------------------------
	section('Ambient sends');

	harness_set_setting_mem('defaultreplyto', 'info@site.example');
	$sender = new EmailSender();

	$msg = default_replyto_message();
	ok('dry-run send succeeds', $sender->send($msg, false) === true);
	ok('default Reply-To applied when message has none',
		$msg->getReplyTo() === 'info@site.example');
	ok('defaulted From untouched by the Reply-To application',
		$msg->getFrom() === 'hello@mg.site.example');

	$msg = default_replyto_message();
	$msg->replyTo('support@site.example');
	$sender->send($msg, false);
	ok('explicit Reply-To wins over the default',
		$msg->getReplyTo() === 'support@site.example');

	// -----------------------------------------------------------------------
	section('Empty setting');

	harness_set_setting_mem('defaultreplyto', '');
	$sender_empty = new EmailSender();
	$msg = default_replyto_message();
	$sender_empty->send($msg, false);
	ok('empty setting applies nothing', $msg->getReplyTo() === null);

	// -----------------------------------------------------------------------
	section('Injected transport (send-as-a-mailbox)');

	harness_set_setting_mem('defaultreplyto', 'info@site.example');
	$sender = new EmailSender();
	$msg = default_replyto_message();
	$msg->from('person@mailboxdomain.example', 'A Person');
	ok('injected-transport send succeeds',
		$sender->send($msg, false, new DefaultReplyToNullTransport()) === true);
	ok('injected-transport send is never stamped with the site Reply-To',
		$msg->getReplyTo() === null);

} finally {
	harness_finish();
}
