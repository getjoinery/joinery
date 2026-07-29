<?php
/** @joinery-test
 * name: dns_publish_banner
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The verdict the operator reads after pressing publish.
 *
 * A publish that reached nothing — a refused credential, a zone the token
 * cannot see — once flashed in success green, which reads as published and
 * sends someone away believing their DNS is live. The severity is therefore
 * part of the contract, not styling: green only when every planned record is
 * in place, amber when some landed and some did not, red when none did.
 *
 * Runs offline in well under a second — no network, no DB.
 *
 * Run:  php tests/dns/dns_publish_banner_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsRecord.php'));

/** One entry of the per-record result list DnsReconciler::apply() returns. */
function result_row(string $action, bool $ok, string $reason = ''): array {
	return array(
		'record' => new DnsRecord('TXT', 'example.com', 'v=spf1 -all'),
		'action' => $action,
		'ok'     => $ok,
		'reason' => $reason,
	);
}

function publish_result(array $results, string $error = ''): array {
	return array('results' => $results, 'error' => $error, 'accounts' => array());
}

section('Nothing reached the provider');

$refused = publish_result(array(), 'Cloudflare refused the token (403): the token is restricted by client IP.');
check(DnsPublishBox::resultSeverity($refused) === DisplayMessage::MESSAGE_ERROR,
	'a credential the provider refused is an error, never an announcement');
check(DnsPublishBox::summarizeResults($refused) === $refused['error'],
	'and the banner carries the provider reason verbatim');

$no_zone = publish_result(array(), 'This credential can see no zone covering example.com.');
check(DnsPublishBox::resultSeverity($no_zone) === DisplayMessage::MESSAGE_ERROR,
	'a zone the credential cannot see is an error');

section('Some records landed and some did not');

$partial = publish_result(array(
	result_row('created', true),
	result_row('failed', false, 'the provider rejected the value'),
));
check(DnsPublishBox::resultSeverity($partial) === DisplayMessage::MESSAGE_WARNING,
	'a partial publish warns rather than claiming success');

$all_failed = publish_result(array(
	result_row('failed', false, 'the provider rejected the value'),
	result_row('failed', false, 'the provider rejected the value'),
));
check(DnsPublishBox::resultSeverity($all_failed) === DisplayMessage::MESSAGE_ERROR,
	'every record failing is an error, not a warning');

section('The whole plan is in place');

check(DnsPublishBox::resultSeverity(publish_result(array(
	result_row('created', true), result_row('updated', true), result_row('adopted', true),
))) === DisplayMessage::MESSAGE_ANNOUNCEMENT, 'writes that all succeeded announce success');

check(DnsPublishBox::resultSeverity(publish_result(array(
	result_row('unchanged', true), result_row('unchanged', true),
))) === DisplayMessage::MESSAGE_ANNOUNCEMENT, 'a domain that already matched is success with nothing written');

// A skip is a choice the operator made in the diff, not a failure of the
// publish — a plan where one record was left alone still succeeded.
check(DnsPublishBox::resultSeverity(publish_result(array(
	result_row('created', true), result_row('skipped', true),
))) === DisplayMessage::MESSAGE_ANNOUNCEMENT, 'a record deliberately skipped does not turn the banner amber');

harness_finish();
