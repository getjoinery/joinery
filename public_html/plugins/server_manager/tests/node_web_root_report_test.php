<?php
/** @joinery-test
 * name: node_web_root_report
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A node's agent names its site's web root (agent 1.44.0), in its join and in
 * every status check. A node made from a join used to have none, and a node
 * with no web root hosts no site: no nightly backup, no recovery-key report,
 * and Run backup refuses. The report fills an empty web root and never
 * replaces a set one.
 *
 * Pinned here: which reported values are web roots at all, and the fill rule.
 * Nothing is saved; the node records are never written.
 *
 * Run: php plugins/server_manager/tests/node_web_root_report_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('What counts as a web root');

foreach (array('/var/www/html/site1/public_html', '/srv/a.b_c-d/public_html') as $good) {
	check(ManagedNode::valid_web_root($good) === $good, "$good is a web root");
}
foreach (array(
	'relative' => 'var/www/html/site1/public_html',
	'no site directory' => '/public_html',
	'not public_html' => '/var/www/html/site1',
	'trailing slash' => '/var/www/html/site1/public_html/',
	'dot-dot' => '/var/www/../../etc/public_html',
	'dot' => '/var/www/./public_html',
	'empty segment' => '/var//www/public_html',
	'space' => '/var/www/my site/public_html',
	'shell' => '/var/www/$(id)/public_html',
	'newline' => "/var/www/x\n/public_html",
	'too long' => '/' . str_repeat('a', 500) . '/public_html',
	'not a string' => array('/var/www/html/site1/public_html'),
	'empty' => '',
	'null' => null,
) as $what => $bad) {
	check(ManagedNode::valid_web_root($bad) === null, "refused: $what");
}

section('A report fills an empty web root, and only an empty one');

$node = new ManagedNode(NULL);
check(!$node->hosts_site(), 'a node made without a web root hosts no site');
check(ManagedNode::adopt_reported_web_root($node, '/var/www/html/site1/public_html', 'join') === 'filled',
	'a reported web root fills an empty one');
check($node->get('mgn_web_root') === '/var/www/html/site1/public_html' && $node->hosts_site(),
	'and the node now hosts a site');

check(ManagedNode::adopt_reported_web_root($node, '/var/www/html/site1/public_html', 'status check') === 'same',
	'the same report again changes nothing');
check(ManagedNode::adopt_reported_web_root($node, '/var/www/html/other/public_html', 'status check') === 'mismatch',
	'a different report is a mismatch');
check($node->get('mgn_web_root') === '/var/www/html/site1/public_html', 'and the recorded web root stays');

$slashed = new ManagedNode(NULL);
$slashed->set('mgn_web_root', '/var/www/html/site1/public_html/');
check(ManagedNode::adopt_reported_web_root($slashed, '/var/www/html/site1/public_html', 'status check') === 'same',
	'a recorded web root with a trailing slash is the same one');

$empty = new ManagedNode(NULL);
check(ManagedNode::adopt_reported_web_root($empty, '/etc/../public_html', 'status check') === 'refused',
	'a malformed report is refused');
check(trim((string)$empty->get('mgn_web_root')) === '', 'and fills nothing');
check(ManagedNode::adopt_reported_web_root($empty, null, 'status check') === 'none'
	&& ManagedNode::adopt_reported_web_root($empty, '', 'join') === 'none',
	'no report (an agent before 1.44.0, or a machine with no site) leaves the node as it is');
check(trim((string)$empty->get('mgn_web_root')) === '', 'still empty');

harness_finish();
