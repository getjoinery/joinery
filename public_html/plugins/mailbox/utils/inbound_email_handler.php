#!/usr/bin/php
<?php
/**
 * Postfix pipe script for inbound email.
 * Receives raw email on stdin, envelope recipient as $argv[1].
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
 * @version 1.3
 */

// Bootstrap Joinery (outside normal web request)
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/PostfixProvider.php'));

// Check master switch
$settings = Globalvars::get_instance();
if (!$settings->get_setting('mailbox_enabled')) {
	exit(0); // Accept silently when disabled
}

$envelope_recipient = isset($argv[1]) ? $argv[1] : null;
if (empty($envelope_recipient)) {
	exit(67); // No recipient — reject
}

$raw_email = file_get_contents('php://stdin');
if (empty($raw_email)) {
	exit(75); // Temp failure — retry
}

// Hand off to the Postfix provider, parallel to webhook providers.
try {
	$provider = new PostfixProvider();
	$result = $provider->handleInbound(['recipient' => $envelope_recipient], $raw_email);
	if ($result === null) {
		exit(67); // Permanent rejection (malformed input)
	}
	$router = new InboundEmailRouter();
	$exit_code = $router->processEmail($result['raw_mime'], $result['recipient']);
	exit($exit_code);
} catch (\Throwable $e) {
	// Catch Throwable, not Exception: a PHP Error (TypeError, an OOM-adjacent
	// fatal in a dependency) is not an Exception, so catching only Exception
	// lets it escape and the process exits 255 — which Postfix does not read
	// as its tempfail code and may bounce as a permanent failure, losing mail
	// the box would have accepted on retry. Exit 75 so Postfix retries.
	error_log('InboundEmailRouter fatal: ' . $e->getMessage());
	exit(75); // Temp failure — Postfix will retry
}
