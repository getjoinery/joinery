<?php
/** @joinery-test
 * name: smtp2go_webhook_sources
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Where SMTP2GO's webhook deliveries may come from (Smtp2GoWebhookSources).
 *
 *  - Svix's published addresses are accepted: SMTP2GO delivers its webhooks
 *    through Svix, and the test button's 403 on 2026-11-08 came from Svix's
 *    54.148.139.208.
 *  - Addresses are compared as addresses: IPv6 ranges match inside and refuse
 *    outside, an IPv4 written as ::ffff:a.b.c.d is that IPv4, text that is not
 *    an address matches nothing.
 *  - The list is SMTP2GO's resolved addresses plus Svix's, or empty when the
 *    name does not resolve.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$svix = Smtp2GoWebhookSources::svixSources();

section('Svix addresses');
check(Smtp2GoWebhookSources::matches('54.148.139.208', $svix), 'the address the test button came from is accepted');
check(Smtp2GoWebhookSources::matches('52.215.16.239', $svix), 'an EU address is accepted');
check(!Smtp2GoWebhookSources::matches('54.148.139.209', $svix), 'its neighbour is not');
check(!Smtp2GoWebhookSources::matches('172.105.179.45', $svix), 'an address on no list is not');

section('Compared as addresses');
check(Smtp2GoWebhookSources::matches('2600:1f24:64:80ab::1', $svix), 'inside the US IPv6 range');
check(Smtp2GoWebhookSources::matches('2600:1F24:0064:8000:0000::0001', $svix), 'the same range written long and in capitals');
check(!Smtp2GoWebhookSources::matches('2600:1f24:64:8100::1', $svix), 'just outside the /56');
check(Smtp2GoWebhookSources::matches('::ffff:54.148.139.208', $svix), 'an IPv4-mapped address is that IPv4');
check(!Smtp2GoWebhookSources::matches('::ffff:54.148.139.209', $svix), 'and its neighbour is still refused');
check(!Smtp2GoWebhookSources::matches('garbage', $svix), 'text that is not an address matches nothing');
check(!Smtp2GoWebhookSources::matches('', $svix), 'an empty address matches nothing');
check(Smtp2GoWebhookSources::matches('10.1.2.3', array('10.0.0.0/8')), 'an IPv4 range');
check(!Smtp2GoWebhookSources::matches('10.1.2.3', array('10.0.0.0/33')), 'a range with an impossible length matches nothing');
check(!Smtp2GoWebhookSources::matches('54.148.139.208', array()), 'an empty list refuses');

section('The whole list');
$allowed = Smtp2GoWebhookSources::allowed();
$resolved = (array)@dns_get_record(Smtp2GoWebhookSources::PUBLISHED_NAME, DNS_A);
if ($resolved) {
	check(count(array_intersect($svix, $allowed)) === count($svix), 'every Svix address is in it', json_encode($allowed));
	check(Smtp2GoWebhookSources::matches((string)$resolved[0]['ip'], $allowed), 'and SMTP2GO\'s own resolved address');
} else {
	check($allowed === array(), 'no DNS answer means an empty list, which refuses', json_encode($allowed));
}

harness_finish();
