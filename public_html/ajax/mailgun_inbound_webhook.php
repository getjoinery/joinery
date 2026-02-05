<?php
/**
 * Mailgun Inbound Webhook
 *
 * Receives inbound emails forwarded by Mailgun and stores them in the database.
 * Used for automated testing to verify email content and extract links.
 *
 * @see /specs/inbound_email_testing.md
 * @version 1.1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('data/inbound_email_class.php'));

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo 'Method Not Allowed';
	exit();
}

// Validate Mailgun signature
// Mailgun uses a separate HTTP Webhook Signing Key (not the API key) for signatures
$settings = Globalvars::get_instance();
$signing_key = $settings->get_setting('mailgun_webhook_signing_key');
if (empty($signing_key)) {
	$signing_key = $settings->get_setting('mailgun_api_key');
}

$timestamp = $_POST['timestamp'] ?? '';
$token = $_POST['token'] ?? '';
$signature = $_POST['signature'] ?? '';

if (empty($timestamp) || empty($token) || empty($signature) || empty($signing_key)) {
	http_response_code(406);
	echo 'Missing signature parameters';
	exit();
}

$expected_signature = hash_hmac('sha256', $timestamp . $token, $signing_key);

if (!hash_equals($expected_signature, $signature)) {
	http_response_code(406);
	echo 'Invalid signature';
	exit();
}

// Extract email fields from POST data
$sender = $_POST['sender'] ?? '';
$recipient = $_POST['recipient'] ?? '';
$subject = $_POST['subject'] ?? '';
$body_plain = $_POST['body-plain'] ?? '';
$body_html = $_POST['body-html'] ?? '';

// Create and save inbound email record
try {
	$email = new InboundEmail(NULL);
	$email->set('iem_sender', $sender);
	$email->set('iem_recipient', $recipient);
	$email->set('iem_subject', $subject);
	$email->set('iem_body_plain', $body_plain);
	$email->set('iem_body_html', $body_html);
	$email->prepare();
	$email->save();

	http_response_code(200);
	echo 'OK';
} catch (Exception $e) {
	error_log("Mailgun inbound webhook error: " . $e->getMessage());
	http_response_code(500);
	echo 'Error saving email';
}

exit();
?>
