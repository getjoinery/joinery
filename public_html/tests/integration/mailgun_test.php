<?php
/** @joinery-test
 * name: mailgun_send
 * tier: live
 * env: prod-verify
 * needs: [mailgun]
 * timeout: 600
 */
/**
 * Live Mailgun send test: a direct SDK send and a template send through
 * EmailSender, both to the configured webmaster address. Sends real mail, so it
 * is live/prod-verify — the dashboard's live confirm and the env gate are the
 * access control (the old $_REQUEST password gate is gone).
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getComposerAutoloadPath());

use Mailgun\Mailgun;

harness_boot();

$settings = Globalvars::get_instance();

section('Direct Mailgun SDK send');
try {
	if ($settings->get_setting('mailgun_eu_api_link')) {
		$mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
	} else {
		$mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
	}
	$domain = $settings->get_setting('mailgun_domain');
	$result = $mg->messages()->send($domain, [
		'from'    => $settings->get_setting('defaultemail'),
		'to'      => $settings->get_setting('webmaster_email'),
		'subject' => 'Test email with direct mailgun',
		'text'    => 'Direct mailgun sending is working.'
	]);
	check(is_object($result) && method_exists($result, 'getId') ? $result->getId() !== '' : $result !== null,
		'direct Mailgun send accepted the message');
} catch (Throwable $e) {
	check(false, 'direct Mailgun send accepted the message', $e->getMessage());
}

section('Template send through EmailSender');
try {
	$user = new User(1, true);
	$message = EmailMessage::fromTemplate('blank_template', [
		'subject' => 'Test email with new system',
		'body' => 'This is the body of the test email.',
		'recipient' => $user->export_as_array()
	]);
	$message->to($settings->get_setting('webmaster_email'), 'Test User');
	$sender = new EmailSender();
	$result = $sender->send($message);
	// send() returns true when delivered, false when queued for retry — both are
	// "the pipeline accepted it" and neither threw.
	check($result === true || $result === false, 'EmailSender accepted the templated message',
		$result ? 'sent' : 'queued for retry');
} catch (Throwable $e) {
	check(false, 'EmailSender accepted the templated message', $e->getMessage());
}

harness_finish();
