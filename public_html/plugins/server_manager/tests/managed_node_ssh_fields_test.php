<?php
/** @joinery-test
 * name: managed_node_ssh_fields
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A node's SSH user and host reach ssh as one argument, user@host. The shell
 * never sees inside it (escapeshellarg), but ssh reads an argument that starts
 * with "-" as an option, and -oProxyCommand= runs a command on the management
 * node as the job worker. So the user is a plain account name and the host a
 * hostname or IP address, held there in two places: ManagedNode::prepare() on
 * every save, and InstallJobExecutor before any step. These checks pin the one
 * definition both read (specs/shell_command_audit.md U2).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('a plain account name is an SSH user');
foreach (array('root', 'user1', 'deploy-bot', 'svc.account', '_apt', 'A9') as $ok) {
	check(ManagedNode::is_ssh_user($ok), $ok . ' is accepted');
}
foreach (array('', '-oProxyCommand=id', '-l', 'root@evil', 'bob;id', 'a b', str_repeat('a', 33)) as $bad) {
	check(!ManagedNode::is_ssh_user($bad), ($bad === '' ? '(empty)' : $bad) . ' is refused');
}

section('a hostname or IP address is an SSH host');
foreach (array('dev.getjoinery.com', '173.255.229.58', '2600:3c02::cb18:00ff:fea7:15d0', '::1', 'localhost', 'node-7') as $ok) {
	check(ManagedNode::is_ssh_host($ok), $ok . ' is accepted');
}
foreach (array('', '-oProxyCommand=id', 'host;id', 'a b.example.com', 'bad_host.example.com', '-.example.com', 'host.', str_repeat('a', 256)) as $bad) {
	check(!ManagedNode::is_ssh_host($bad), ($bad === '' ? '(empty)' : $bad) . ' is refused');
}

section('prepare() refuses a row that would hand ssh an option');
$node = new ManagedNode(NULL);
$node->set('mgn_slug', 'ssh-fields-' . bin2hex(random_bytes(3)));
$node->set('mgn_name', 'ssh fields test');
$node->set('mgn_host', '203.0.113.9');
$node->set('mgn_ssh_user', '-oProxyCommand=id>/tmp/p');
$refused = '';
try {
	$node->prepare();
} catch (ManagedNodeException $e) {
	$refused = $e->getMessage();
}
check($refused !== '' && stripos($refused, 'SSH user') !== false,
	'an SSH user beginning with - is refused before the duplicate-slug query, naming the field', $refused);

$node->set('mgn_ssh_user', 'root');
$node->set('mgn_host', '-oProxyCommand=id');
$refused = '';
try {
	$node->prepare();
} catch (ManagedNodeException $e) {
	$refused = $e->getMessage();
}
check($refused !== '' && stripos($refused, 'SSH host') !== false,
	'a host beginning with - is refused the same way', $refused);

// A node made without an SSH user (an agent join, a site copy, a provisioned
// server) gets the column's default, root, on insert; prepare() lets it by.
$bare = new ManagedNode(NULL);
$bare->set('mgn_slug', 'ssh-fields-' . bin2hex(random_bytes(3)));
$bare->set('mgn_name', 'ssh fields test');
$bare->set('mgn_host', '203.0.113.9');
$refused = '';
try {
	$bare->prepare();
} catch (ManagedNodeException $e) {
	$refused = $e->getMessage();
}
check(stripos($refused, 'SSH user') === false, 'a node with no SSH user set is not refused for it', $refused);

harness_finish();
