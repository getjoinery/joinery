<?php
/**
 * SMTP2GO event webhook — /ajax/smtp2go_webhook
 *
 * The mail provider tells this plane what happened to a hosted customer's
 * messages. The deliveries are counted, and that count is the "sent this
 * month" line on the customer's banner (specs/hosted_trial_provisioning.md
 * §4.3, E2). Bounces, complaints and rejections are the provider's business:
 * it applies its own controls to the customer's subaccount, and nothing here
 * second-guesses them.
 *
 * WHAT THIS IS ALLOWED TO DO IS DELIBERATELY SMALL. SMTP2GO does not sign its
 * webhooks — the only checks available are a basic-auth secret in the URL and
 * the provider's own sending addresses — so a spoofed or dropped delivery can
 * move a number on a banner and can never move a cap. The cap is the
 * provider's own month-to-date count against the subaccount limit, and nothing
 * that arrives here influences it in either direction.
 *
 * The secret is required. A deployment that has not configured one refuses
 * every delivery rather than accepting anonymous ones — an open counter is
 * worse than no counter, because it looks like evidence.
 *
 * Always answers 200 once authenticated: a provider that gets an error retries,
 * and a retry of an event already counted would count it twice.
 *
 * THE URL IS /ajax/smtp2go_webhook (this file overrides the core ajax route),
 * and the webhook itself is created BY HAND in the SMTP2GO console against the
 * master account, with this deployment's webhook secret as the basic-auth
 * password. Nothing here registers it: the provider's webhook is account-wide
 * rather than per-subaccount, so it is one piece of operator setup, not a step
 * of every customer's provisioning.
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trial_class.php'));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	echo 'Method Not Allowed';
	exit();
}

// The provider's own addresses, resolved rather than hardcoded: SMTP2GO
// publishes them under one name and moving them is their business, not a
// setting somebody here has to remember to update. An empty answer means DNS
// could not be reached, and that is a REFUSAL — an allowlist that fails open is
// not an allowlist. Spec E2: basic-auth AND the address range, because these
// webhooks are unsigned and there is nothing else to check.
// get_client_ip(true) rather than REMOTE_ADDR: a deployment behind Cloudflare or
// any proxy would otherwise see the proxy's address, refuse every delivery, and
// say nothing anywhere about why the counters had stopped moving. The `true`
// asks for the auth-grade answer — a forwarded header is trusted only from a
// verified edge, never from anyone who sets one.
$source_ip = SessionControl::get_client_ip(true);
$allowed = smtp2go_webhook_allowed_addresses();
if (!$allowed || !in_array($source_ip, $allowed, true)) {
	// Logged, because the alternative is a silent 403 and a counter that
	// mysteriously stays at zero. The address is the whole diagnosis.
	error_log('smtp2go_webhook: refused a delivery from ' . ($source_ip ?: 'an unknown address')
		. ($allowed ? '' : ' (and the provider address list could not be resolved)'));
	http_response_code(403);
	echo 'Forbidden';
	exit();
}

$expected = trim(ProvisioningSetup::readSecret('server_manager_smtp2go_webhook_secret'));
if ($expected === '') {
	// Nothing to check against. An open counter looks like evidence and is not.
	http_response_code(503);
	echo 'Not configured';
	exit();
}

// The secret arrives as the basic-auth password on the URL the provider was
// given. hash_equals so a wrong secret takes the same time as a right one.
$supplied = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
if ($supplied === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])
		&& preg_match('/^Basic\s+(.+)$/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
	$decoded = base64_decode($m[1], true);
	if (is_string($decoded) && strpos($decoded, ':') !== false) {
		$supplied = substr($decoded, strpos($decoded, ':') + 1);
	}
}
if (!hash_equals($expected, $supplied)) {
	http_response_code(401);
	echo 'Unauthorized';
	exit();
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
	http_response_code(400);
	echo 'Bad payload';
	exit();
}

// One delivery may carry one event or a list of them.
$events = (isset($payload[0]) && is_array($payload[0])) ? $payload : array($payload);

$counted = 0;
foreach ($events as $event) {
	if (!is_array($event)) { continue; }
	$trial = smtp2go_webhook_trial_for($event);
	if ($trial === null) { continue; }

	// The count resets with the calendar month, matching the allowance it is
	// shown against. Done on read rather than by a job: a monthly job that
	// missed a run would leave last month's number on a banner all of this one.
	$reset = trim((string)$trial->get('htr_counts_reset_time'));
	if ($reset === '' || gmdate('Y-m', strtotime($reset . ' UTC')) !== gmdate('Y-m')) {
		$trial->set('htr_sent_count', 0);
		$trial->set('htr_counts_reset_time', gmdate('Y-m-d H:i:s'));
	}

	switch (strtolower(trim((string)($event['event'] ?? $event['type'] ?? '')))) {
		case 'processed':
		case 'delivered':
			$trial->set('htr_sent_count', (int)$trial->get('htr_sent_count') + 1);
			break;
		default:
			// Bounces, complaints and rejections included: the provider acts on
			// those itself, and a count of them here would decide nothing.
			continue 2;
	}
	$trial->save();
	$counted++;
}

http_response_code(200);
echo 'ok ' . $counted;

/**
 * The addresses SMTP2GO delivers webhooks from.
 *
 * Resolved from the name the provider publishes rather than hardcoded: moving
 * them is their business, not a setting somebody here has to remember. Returns
 * [] when DNS gives no answer, which the caller treats as a REFUSAL — for an
 * unsigned webhook the address is half of the entire check, and an allowlist
 * that fell open under DNS failure would be worth nothing on exactly the day it
 * mattered.
 *
 * Cached only for the life of the request. A file cache under the system temp
 * directory would be world-readable and written by the web user, so any local
 * account could pre-seed the allowlist; the resolver in front of this already
 * caches the lookup, so the file bought nothing for that.
 */
function smtp2go_webhook_allowed_addresses(): array {
	static $cached = null;
	if ($cached !== null) {
		return $cached;
	}
	$out = array();
	foreach (array('webhooks.smtp2go.com') as $name) {
		foreach ((array)@dns_get_record($name, DNS_A) as $record) {
			if (!empty($record['ip'])) { $out[] = (string)$record['ip']; }
		}
		foreach ((array)@dns_get_record($name, DNS_AAAA) as $record) {
			if (!empty($record['ipv6'])) { $out[] = (string)$record['ipv6']; }
		}
	}
	return $cached = array_values(array_unique($out));
}

/**
 * Which customer an event belongs to.
 *
 * Matched on the sending credential the provider names — the SMTP username
 * minted for exactly one customer — and failing that on the subaccount id.
 * Never on the recipient or the sender address: those are attacker-supplied in
 * the spoofed case, and the whole point of matching on the credential is that
 * a customer's events are attributable to the credential we gave them.
 */
function smtp2go_webhook_trial_for(array $event) {
	$username = trim((string)($event['auth'] ?? $event['username'] ?? ''));
	$subaccount = trim((string)($event['subaccount_id'] ?? ''));

	$db = DbConnector::get_instance()->get_db_link();
	if ($username !== '') {
		$q = $db->prepare("SELECT cvp_id FROM cvp_customer_cloud_provisions
			WHERE cvp_smtp2go_user_id = ? AND cvp_delete_time IS NULL ORDER BY cvp_id DESC LIMIT 1");
		$q->execute(array($username));
		$id = $q->fetchColumn();
		if ($id) { return HostedTrial::for_provision((int)$id); }
	}
	if ($subaccount !== '') {
		$q = $db->prepare("SELECT cvp_id FROM cvp_customer_cloud_provisions
			WHERE cvp_smtp2go_subaccount_id = ? AND cvp_delete_time IS NULL ORDER BY cvp_id DESC LIMIT 1");
		$q->execute(array($subaccount));
		$id = $q->fetchColumn();
		if ($id) { return HostedTrial::for_provision((int)$id); }
	}
	return null;
}
