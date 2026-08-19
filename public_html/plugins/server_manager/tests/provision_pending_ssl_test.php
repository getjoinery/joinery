<?php
/** @joinery-test
 * name: provision_pending_ssl_backoff
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * ProvisionPendingSsl retry pacing for a Cloudflare domain whose routing
 * probe keeps missing. The state never flips to 'failed' (a DNS cutover the
 * customer has not made is not a fault), so the pacing is the only thing
 * standing between "patiently waiting" and "hammering hourly forever in
 * silence" — 48 failed jobs in 58 hours was the latter.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionPendingSsl.php'));

section('Fast lane, then slow lane');

check(ProvisionPendingSsl::routing_retry_gap(0) === ProvisionPendingSsl::ROUTING_FAST_GAP,
	'a fresh miss retries on the fast gap');
check(ProvisionPendingSsl::routing_retry_gap(ProvisionPendingSsl::ROUTING_FAST_ATTEMPTS - 1) === ProvisionPendingSsl::ROUTING_FAST_GAP,
	'the last fast attempt still uses the fast gap');
check(ProvisionPendingSsl::routing_retry_gap(ProvisionPendingSsl::ROUTING_FAST_ATTEMPTS) === ProvisionPendingSsl::ROUTING_SLOW_GAP,
	'exhausting the fast attempts drops to the slow gap',
	'this changeover is also when the one-shot operator alert goes out');
check(ProvisionPendingSsl::routing_retry_gap(500) === ProvisionPendingSsl::ROUTING_SLOW_GAP,
	'and it stays slow no matter how long the wait drags on');

section('The lanes are sane');

check(ProvisionPendingSsl::ROUTING_FAST_GAP === 3600,
	'fast lane is hourly — same cadence as every other provisioning retry');
check(ProvisionPendingSsl::ROUTING_SLOW_GAP > ProvisionPendingSsl::ROUTING_FAST_GAP,
	'slow lane is genuinely slower than the fast lane');
check(ProvisionPendingSsl::ROUTING_FAST_ATTEMPTS * ProvisionPendingSsl::ROUTING_FAST_GAP >= 57600,
	'the fast lane lasts at least as long as the 16h give-up window other failures get',
	'a routing wait must never be treated more harshly than a certbot failure');

harness_finish();
