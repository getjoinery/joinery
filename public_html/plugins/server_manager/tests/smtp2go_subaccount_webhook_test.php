<?php
/** @joinery-test
 * name: smtp2go_subaccount_webhook
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Every customer subaccount reports its sends to this site (Smtp2GoLeg::ensureWebhook).
 *
 * A webhook on SMTP2GO's master account does not fire for a subaccount's mail
 * (live on dev 2026-11-09), so each subaccount carries its own.
 *
 *  - A subaccount with no webhook gets one: this site's /ajax/smtp2go_webhook,
 *    the processed event, JSON, Basic authorization with the webhook secret.
 *  - A subaccount already pointing here is left alone, so a retried step never
 *    adds a second webhook.
 *  - No webhook secret on this site: nothing is asked of the provider, and the
 *    answer says why, for the operator.
 *  - The key check lists the Webhooks permission among those a hosted order uses.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

/** A client whose provider answers $bodies in order, recording every request into $history. */
function sswt_client(array $bodies, array &$history): Smtp2GoClient {
	$responses = array();
	foreach ($bodies as $body) {
		$responses[] = new \GuzzleHttp\Psr7\Response(200, array(), json_encode(array('data' => $body)));
	}
	$stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
	$stack->push(\GuzzleHttp\Middleware::history($history));
	return new Smtp2GoClient('test-key', new \GuzzleHttp\Client(array('handler' => $stack)));
}

/** The paths and bodies the client sent. */
function sswt_sent(array $history): array {
	$out = array();
	foreach ($history as $entry) {
		$out[] = array(
			'path' => basename(dirname($entry['request']->getUri()->getPath())) . '/' . basename($entry['request']->getUri()->getPath()),
			'body' => json_decode((string)$entry['request']->getBody(), true) ?: array(),
		);
	}
	return $out;
}

$url = Smtp2GoLeg::webhookUrl();
check(substr($url, -strlen('/ajax/smtp2go_webhook')) === '/ajax/smtp2go_webhook', 'the webhook points at this site\'s endpoint', $url);

section('A subaccount with no webhook gets one');
harness_set_setting_mem('server_manager_smtp2go_webhook_secret', 'sekret17charsabcd');
$history = array();
$client = sswt_client(array(array(), array('id' => 4320, 'url' => $url)), $history);
$gap = Smtp2GoLeg::ensureWebhook($client, 'sub1');
$sent = sswt_sent($history);
check($gap === '', 'nothing to report', $gap);
check(count($sent) === 2 && $sent[0]['path'] === 'webhook/view' && $sent[1]['path'] === 'webhook/add', 'it looked, then added', json_encode($sent));
$add = $sent[1]['body'] ?? array();
check(($add['subaccount_id'] ?? '') === 'sub1', 'on the customer\'s subaccount', json_encode($add));
check(($add['url'] ?? '') === $url && ($add['events'] ?? null) === array('processed') && ($add['output_format'] ?? '') === 'json',
	'this site\'s URL, the processed event, as JSON', json_encode($add));
check(($add['auth_header_type'] ?? '') === 'basic' && base64_decode((string)($add['auth_header_value'] ?? '')) === 'joinery:sekret17charsabcd',
	'Basic authorization carrying the webhook secret');

section('A subaccount already pointing here is left alone');
foreach (array('one object' => array('id' => 7, 'url' => $url . '/'), 'a list' => array(array('id' => 6, 'url' => 'https://elsewhere.example/hook'), array('id' => 7, 'url' => $url))) as $shape => $answer) {
	$history = array();
	$gap = Smtp2GoLeg::ensureWebhook(sswt_client(array($answer), $history), 'sub1');
	$sent = sswt_sent($history);
	check($gap === '' && count($sent) === 1 && $sent[0]['path'] === 'webhook/view', 'only looked, when the provider answers ' . $shape, json_encode($sent));
}

section('No webhook secret on this site');
harness_set_setting_mem('server_manager_smtp2go_webhook_secret', '');
$history = array();
$gap = Smtp2GoLeg::ensureWebhook(sswt_client(array(), $history), 'sub1');
check($gap !== '' && stripos($gap, 'webhook secret') !== false, 'the answer says the secret is missing', $gap);
check(count($history) === 0, 'and nothing was asked of the provider');

section('The key check asks for the Webhooks permission');
$groups = array();
foreach (Smtp2GoClient::ENDPOINTS_USED as $path => $spec) { $groups[$spec[1]][] = $path; }
check(isset($groups['Webhooks']) && in_array('webhook/add', $groups['Webhooks'], true) && in_array('webhook/view', $groups['Webhooks'], true),
	'webhook/view and webhook/add are listed under Webhooks', json_encode($groups['Webhooks'] ?? null));

harness_finish();
