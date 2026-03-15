#!/usr/bin/php
<?php
/**
 * Postfix pipe script for email forwarding.
 * Receives raw email on stdin, envelope recipient as $argv[1].
 *
 * Exit codes (per Postfix pipe conventions):
 *   0  = success
 *   67 = unknown user (permanent rejection)
 *   75 = temporary failure (Postfix will retry)
 *
 * @version 1.0
 */

// Bootstrap Joinery (outside normal web request)
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/includes/EmailForwarder.php'));

// Check master switch
$settings = Globalvars::get_instance();
if (!$settings->get_setting('email_forwarding_enabled')) {
	exit(0); // Accept silently when disabled
}

// Envelope recipient from Postfix (NOT the To: header — they can differ for BCC, lists, etc.)
$envelope_recipient = isset($argv[1]) ? $argv[1] : null;
if (empty($envelope_recipient)) {
	exit(67); // No recipient — reject
}

// Read raw email from stdin
$raw_email = file_get_contents('php://stdin');
if (empty($raw_email)) {
	exit(75); // Temp failure — retry
}

// Process — wrapped in try/catch so PHP errors return temp failure instead of crashing
try {
	$forwarder = new EmailForwarder();
	$exit_code = $forwarder->processEmail($raw_email, $envelope_recipient);
	exit($exit_code);
} catch (Exception $e) {
	error_log('EmailForwarder fatal: ' . $e->getMessage());
	exit(75); // Temp failure — Postfix will retry
}
