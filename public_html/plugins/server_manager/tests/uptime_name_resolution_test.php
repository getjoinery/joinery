<?php
/** @joinery-test
 * name: uptime_name_resolution
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Uptime probes must not report a node down because THIS machine's resolver broke.
 *
 * The failure this guards against is not subtle in effect, only in origin. When
 * the monitoring host loses name resolution, every probe fails within one pass,
 * every node crosses the failure threshold together, and the operator is mailed
 * that the whole fleet is down while every site is serving traffic normally. The
 * signal is not merely noisy: it is inverted, because the one machine that was
 * actually broken is the only one that reported nothing wrong.
 *
 * A probe that dies in resolution never reached the node, so it carries no
 * evidence either way. The requirement is that it be recorded as inconclusive
 * rather than as a down result.
 *
 * These tests exercise the classifier directly, because the thing that has to be
 * right is the boundary: a resolver failure and an unreachable node arrive as the
 * same shape of curl failure, and the difference between them is the error number
 * plus the wording. Erring in one direction mutes real outages; erring in the
 * other restores the false-alarm flood. Both directions are asserted.
 *
 * Sections: named curl errors; timeouts during resolution; socket-level failures;
 * genuine node failures stay down; case and wording variance.
 *
 * Run: php plugins/server_manager/tests/uptime_name_resolution_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));

section('Curl names the condition outright');

check(NodeMonitorHealth::is_name_resolution_failure(6, 'Could not resolve host: phillyzouk.org'),
	'CURLE_COULDNT_RESOLVE_HOST is a resolution failure');

check(NodeMonitorHealth::is_name_resolution_failure(5, 'Could not resolve proxy: proxy.internal'),
	'CURLE_COULDNT_RESOLVE_PROXY is a resolution failure');

check(NodeMonitorHealth::is_name_resolution_failure(6, ''),
	'the error number alone is enough, with no message to read');

section('A resolver that hangs arrives as a generic timeout');

// The observed production case: the stub resolver stopped answering rather than
// returning NXDOMAIN, so curl's connect timeout fired first and reported errno 28
// — the same number a dead node produces. Only the wording separates them.
check(NodeMonitorHealth::is_name_resolution_failure(28, 'Resolving timed out after 10000 milliseconds'),
	'CURLE_OPERATION_TIMEDOUT while resolving is a resolution failure');

check(!NodeMonitorHealth::is_name_resolution_failure(28, 'Connection timed out after 10001 milliseconds'),
	'CURLE_OPERATION_TIMEDOUT while connecting is NOT — the node is unreachable');

check(!NodeMonitorHealth::is_name_resolution_failure(28,
		'Operation timed out after 10000 milliseconds with 0 bytes received'),
	'a timeout waiting for a response is NOT — the node answered the dial');

section('Socket probes report resolution failures with no error number');

// fsockopen surfaces getaddrinfo's text and errno 0, so classification has to
// work from the message alone.
check(NodeMonitorHealth::is_name_resolution_failure(0,
		'php_network_getaddresses: getaddrinfo for relay.example.com failed: Name or service not known'),
	'getaddrinfo failure with errno 0 is a resolution failure');

check(NodeMonitorHealth::is_name_resolution_failure(0,
		'php_network_getaddresses: getaddrinfo failed: Temporary failure in name resolution'),
	'temporary resolver failure with errno 0 is a resolution failure');

check(!NodeMonitorHealth::is_name_resolution_failure(0, 'Connection refused'),
	'a refused connection with errno 0 is NOT — the name resolved fine');

section('Genuine node failures must still register as down');

check(!NodeMonitorHealth::is_name_resolution_failure(7,
		'Failed to connect to 23.239.11.53 port 443: Connection refused'),
	'connection refused is not a resolution failure');

check(!NodeMonitorHealth::is_name_resolution_failure(35, 'SSL connect error'),
	'TLS handshake failure is not a resolution failure');

check(!NodeMonitorHealth::is_name_resolution_failure(60, 'SSL certificate problem: certificate has expired'),
	'an expired certificate is not a resolution failure');

check(!NodeMonitorHealth::is_name_resolution_failure(52, 'Empty reply from server'),
	'an empty reply from the server is not a resolution failure');

check(!NodeMonitorHealth::is_name_resolution_failure(7, 'curl errno 7'),
	'a bare curl errno with no message is not assumed to be DNS');

section('Wording and case variance');

check(NodeMonitorHealth::is_name_resolution_failure(6, 'Could not resolve: dns.scrolldaddy.app (Domain name not found)'),
	'c-ares wording is recognised');

check(NodeMonitorHealth::is_name_resolution_failure(0, "couldn't resolve host name"),
	"older curl's apostrophe form is recognised");

check(NodeMonitorHealth::is_name_resolution_failure(0, 'COULD NOT RESOLVE HOST: GETJOINERY.COM'),
	'matching is case-insensitive');

check(!NodeMonitorHealth::is_name_resolution_failure(0, ''),
	'an empty message with a non-DNS errno is not a resolution failure');

harness_finish();
