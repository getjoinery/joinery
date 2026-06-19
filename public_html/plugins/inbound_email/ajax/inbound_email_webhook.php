<?php
/**
 * Generic inbound email webhook dispatcher.
 *
 * Looks up an inbound provider by ?provider=<key>, verifies it is the
 * active provider (so a stale webhook from a previously-configured
 * service cannot smuggle mail in once the admin has switched providers),
 * delegates signature verification + payload extraction to the provider,
 * and then hands off to InboundEmailRouter::processEmail().
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundProviderRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailRouter.php'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo 'Method Not Allowed';
	exit();
}

$settings = Globalvars::get_instance();
if (!$settings->get_setting('inbound_email_enabled')) {
	// Mirror the pipe handler — silently accept when disabled.
	http_response_code(200);
	echo 'inbound_email disabled';
	exit();
}

$key = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';
if ($key === '') {
	http_response_code(400);
	echo 'Missing provider';
	exit();
}

$class = InboundProviderRegistry::get($key);
if ($class === null) {
	http_response_code(404);
	echo 'Unknown provider';
	exit();
}

$active = trim((string)$settings->get_setting('inbound_email_provider'));
if ($active === '') {
	$active = 'postfix';
}
if ($active !== $key) {
	http_response_code(403);
	echo 'Provider not active';
	exit();
}

if (!$class::isWebhook()) {
	http_response_code(404);
	echo 'Provider does not accept webhooks';
	exit();
}

$raw_body = file_get_contents('php://input');
$provider = new $class();

try {
	$result = $provider->handleInbound($_POST, (string)$raw_body);
} catch (\Throwable $e) {
	error_log('inbound_email_webhook: provider error: ' . $e->getMessage());
	http_response_code(500);
	echo 'Provider error';
	exit();
}

if ($result === null) {
	http_response_code(406);
	echo 'Rejected';
	exit();
}

try {
	$router = new InboundEmailRouter();
	$exit_code = $router->processEmail($result['raw_mime'], $result['recipient'], $result['auth'] ?? null, $result['spam'] ?? null);
} catch (\Throwable $e) {
	error_log('inbound_email_webhook: router error: ' . $e->getMessage());
	http_response_code(503);
	echo 'Temporary failure';
	exit();
}

if ($exit_code === 0) {
	http_response_code(200);
	echo 'OK';
} elseif ($exit_code === 75) {
	http_response_code(503);
	echo 'Temporary failure';
} elseif ($exit_code === 67) {
	http_response_code(406);
	echo 'Unknown recipient';
} else {
	http_response_code(500);
	echo 'Unexpected exit code: ' . intval($exit_code);
}
exit();
?>
