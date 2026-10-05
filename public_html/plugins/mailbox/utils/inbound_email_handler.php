#!/usr/bin/php
<?php
/**
 * Postfix pipe script for inbound email.
 * Receives raw email on stdin, envelope recipient as $argv[1] and the Postfix
 * queue id as $argv[2].
 *
 * Delegates to PostfixProvider::handleInbound() to extract raw MIME +
 * recipient (parallel to the webhook dispatcher's interaction with other
 * providers), then to InboundEmailRouter::processEmail() for routing.
 *
 * Exit codes (per Postfix pipe conventions):
 *   0  = success
 *   67 = unknown user (permanent rejection)
 *   75 = temporary failure (Postfix will retry)
 *
 * Unknown recipients are refused during the SMTP conversation by the recipient
 * lookup install_email.sh wires in front of this pipe, so a 67 here means the
 * lookup and the router disagreed, and Postfix is about to bounce the message
 * to its sender. Each one is logged as that mismatch.
 *
 * Nothing here may end in any other exit status: Postfix bounces a status that
 * is not a sysexits code. Everything that can fail — the database included —
 * runs inside the try, and a fatal error PHP cannot catch (out of memory, a
 * broken include) is turned into a deferral by the shutdown guard.
 *
 * A deferral on the message's last retries is dropped and logged rather than
 * returned: deferred past the queue lifetime, Postfix bounces it
 * (InboundDeferralExpiry).
 *
 * @version 1.5 - the database is first read inside the try, and a fatal error defers instead of
 *                exiting 255 (which Postfix bounces)
 * @version 1.4 - logs a 67 as a lookup/router mismatch; drops a deferral that would expire into a bounce
 * @version 1.3
 */

$envelope_recipient = isset($argv[1]) ? (string)$argv[1] : '';
$queue_id = isset($argv[2]) ? (string)$argv[2] : '';
$raw_email = '';

// A fatal error ends PHP with status 255, which Postfix bounces to the sender.
// Turn it into what any other failure here is: a deferral, or a logged drop
// on the message's last retries.
// Out of memory is the likeliest fatal, and the guard runs under the same
// limit, so it starts by freeing a reserve held for it and raising the limit.
$oom_reserve = str_repeat(' ', 512 * 1024);
register_shutdown_function(function () use (&$raw_email, &$envelope_recipient, &$queue_id, &$oom_reserve) {
	$oom_reserve = null;
	@ini_set('memory_limit', (string)(memory_get_usage() + 64 * 1024 * 1024));
	$e = error_get_last();
	if (!$e || !in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
		return;
	}
	error_log('inbound_email_handler: fatal error, deferring ' . $envelope_recipient . ': ' . $e['message']);
	$code = 75;
	try {
		if ($raw_email !== '' && class_exists('InboundDeferralExpiry')) {
			$code = InboundDeferralExpiry::defer_or_drop($raw_email, $envelope_recipient, $queue_id,
				'the handler failed: ' . $e['message']);
		}
	} catch (\Throwable $ignored) {
		$code = 75;
	}
	exit($code);
});

// Bootstrap Joinery (outside normal web request)
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/PostfixProvider.php'));

if ($envelope_recipient === '') {
	exit(67); // No recipient — reject
}

$raw_email = (string)file_get_contents('php://stdin');
if ($raw_email === '') {
	exit(75); // Temp failure — retry
}

try {
	// Master switch. Inside the try: it is the handler's first database read,
	// and a database that is down must defer the message, never bounce it.
	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('mailbox_enabled')) {
		exit(0); // Accept silently when disabled
	}

	// Hand off to the Postfix provider, parallel to webhook providers.
	$provider = new PostfixProvider();
	$result = $provider->handleInbound(['recipient' => $envelope_recipient], $raw_email);
	if ($result === null) {
		exit(67); // Permanent rejection (malformed input)
	}
	$router = new InboundEmailRouter();
	$exit_code = $router->processEmail($result['raw_mime'], $result['recipient']);
	if ($exit_code === 67) {
		error_log('inbound_email_handler: refused ' . $result['recipient'] . ' after accepting it; the SMTP-time '
			. 'recipient lookup (install_email.sh) should have refused it, and Postfix will bounce it to the sender');
	} elseif ($exit_code === 75) {
		$exit_code = InboundDeferralExpiry::defer_or_drop($raw_email, $envelope_recipient, $queue_id, 'the router deferred it');
	}
	exit($exit_code);
} catch (\Throwable $e) {
	// Catch Throwable, not Exception: a PHP Error (TypeError, an OOM-adjacent
	// fatal in a dependency) is not an Exception, so catching only Exception
	// lets it escape and the process exits 255 — which Postfix does not read
	// as its tempfail code and may bounce as a permanent failure, losing mail
	// the box would have accepted on retry. Exit 75 so Postfix retries.
	error_log('InboundEmailRouter fatal: ' . $e->getMessage());
	exit(InboundDeferralExpiry::defer_or_drop($raw_email, $envelope_recipient, $queue_id, 'the router failed: ' . $e->getMessage()));
}
