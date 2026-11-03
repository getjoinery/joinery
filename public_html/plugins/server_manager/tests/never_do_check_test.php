<?php
/** @joinery-test
 * name: never_do_check
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The management node's half of the never-do list (NeverDoCheck,
 * specs/node_unexplained_root.md § 5.1): the script's object held to its
 * shape, each report folded into the node's baseline, what a job explains
 * taken in and what it does not held, held items surviving their own removal,
 * Accept clearing them, and the incident raised while any are held.
 *
 * Run: php plugins/server_manager/tests/never_do_check_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

function nd_report(array $over = array()): array {
	$r = array(
		'findings' => array(
			'setuid_unpackaged' => array(), 'setuid_modified' => array(), 'uid0_accounts' => array(),
			'kernel_taint' => '', 'scratch_programs' => array(), 'deleted_root_programs' => array(), 'ld_preload' => '',
		),
		'inventory' => array(
			'ssh_keys' => array('root SHA256:aaaa'),
			'accounts' => array('root 0 /bin/bash', 'user1 1000 /bin/bash'),
			'sudoers'  => array('/etc/sudoers 0123456789abcdef'),
			'startup'  => array('/etc/cron.d/joinery-site 0123456789abcdef', '/etc/systemd/system/multi-user.target.wants/x.service -> /usr/lib/systemd/system/x.service'),
			'ports'    => array('tcp 0.0.0.0:22 sshd', 'tcp *:443 apache2'),
			'logs'     => array('/var/log/wtmp 100 38400', '/var/log/auth.log 200 5000'),
		),
		'truncated' => array(), 'partial' => false, 'generated_at' => 1762170000,
	);
	foreach ($over as $path => $v) {
		$ref = &$r;
		foreach (explode('.', $path) as $k) { $ref = &$ref[$k]; }
		$ref = $v;
		unset($ref);
	}
	return $r;
}

function nd_kinds(array $state): array {
	return array_map(function ($u) { return $u['kind'] . '|' . $u['item']; }, $state['unexplained']);
}

section('The script\'s object, held to its shape');

check(NeverDoCheck::sanitise(null) === null && NeverDoCheck::sanitise(array('findings' => 'x')) === null,
	'something that is not the object is not a report');
$s = NeverDoCheck::sanitise(nd_report(array(
	'inventory.ssh_keys' => array('root SHA256:aaaa', "root SHA256:bb\nbb", 'root <script>', 7),
	'findings.kernel_taint' => 'OE; rm -rf',
)));
check($s['inventory']['ssh_keys'] === array('root SHA256:aaaa'),
	'an item with a newline, markup or the wrong type is dropped', var_export($s['inventory']['ssh_keys'], true));
check($s['findings']['kernel_taint'] === '?', 'a taint that is not F, O and E letters reads as unknown, never passed through');
check($s['inventory']['logs'] === array('/var/log/wtmp' => array(100, 38400), '/var/log/auth.log' => array(200, 5000)),
	'logs are path, inode and size', var_export($s['inventory']['logs'], true));
check(NeverDoCheck::sanitise(nd_report(array('partial' => 'no')))['partial'] === true,
	'anything but false is partial: a run that cannot say it was complete is not');

section('The first report is the baseline');

$now = 1762170000;
$st = NeverDoCheck::fold(null, NeverDoCheck::sanitise(nd_report()), false, $now);
check($st['unexplained'] === array(), 'nothing is held from the first report of a quiet machine');
check($st['baseline']['ports'] === array('tcp 0.0.0.0:22 sshd', 'tcp *:443 apache2'), 'its lists become the baseline');

section('A change no job explains is held; one a job explains is taken in');

$changed = NeverDoCheck::sanitise(nd_report(array(
	'inventory.ssh_keys' => array('root SHA256:aaaa', 'root SHA256:evil'),
	'inventory.ports'    => array('tcp 0.0.0.0:22 sshd', 'tcp *:443 apache2', 'tcp 0.0.0.0:4444 nc'),
)));
$held = NeverDoCheck::fold($st, $changed, false, $now + 300);
check(nd_kinds($held) === array('ssh_keys|root SHA256:evil', 'ports|tcp 0.0.0.0:4444 nc'),
	'a new key and a new port are held', var_export(nd_kinds($held), true));
check(!in_array('root SHA256:evil', $held['baseline']['ssh_keys'], true), 'and are not taken into the baseline');
$taken = NeverDoCheck::fold($st, $changed, true, $now + 300);
check($taken['unexplained'] === array() && in_array('tcp 0.0.0.0:4444 nc', $taken['baseline']['ports'], true),
	'with a machine-changing job in the window they are taken in, nothing held');

section('Held stays held when the change is undone');

$undone = NeverDoCheck::fold($held, NeverDoCheck::sanitise(nd_report()), false, $now + 600);
check(count($undone['unexplained']) === 2, 'removing the key and closing the port does not clear the record of them');
$again = NeverDoCheck::fold($undone, $changed, false, $now + 900);
check(count($again['unexplained']) === 2, 'the same item seen again is held once');

section('A changed file reads as new');

$edited = NeverDoCheck::fold($st, NeverDoCheck::sanitise(nd_report(array(
	'inventory.startup' => array('/etc/cron.d/joinery-site fedcba9876543210', '/etc/systemd/system/multi-user.target.wants/x.service -> /usr/lib/systemd/system/x.service'),
))), false, $now + 300);
check(nd_kinds($edited) === array('startup|/etc/cron.d/joinery-site fedcba9876543210'),
	'a cron file whose contents changed is held', var_export(nd_kinds($edited), true));

section('Findings');

$f = NeverDoCheck::fold($st, NeverDoCheck::sanitise(nd_report(array(
	'findings.setuid_unpackaged' => array('/tmp/.x/sh'),
	'findings.scratch_programs'  => array('0 /tmp/installer-1/run'),
	'findings.kernel_taint'      => 'OE',
))), true, $now + 300);
check(nd_kinds($f) === array('setuid_unpackaged|/tmp/.x/sh', 'kernel_taint|OE'),
	'a setuid program and a tainted kernel are held even inside a job\'s window; a program in /tmp is excused there',
	var_export(nd_kinds($f), true));
$f2 = NeverDoCheck::fold($st, NeverDoCheck::sanitise(nd_report(array(
	'findings.scratch_programs' => array('33 /dev/shm/x'),
	'findings.deleted_root_programs' => array('/usr/sbin/kworkerd'),
))), false, $now + 300);
check(count($f2['unexplained']) === 2, 'outside a job\'s window a program in /dev/shm and a root program whose file is gone are held');

section('A cut log');

$cut = NeverDoCheck::fold($st, NeverDoCheck::sanitise(nd_report(array(
	'inventory.logs' => array('/var/log/wtmp 100 0', '/var/log/auth.log 201 10'),
))), true, $now + 300);
check(nd_kinds($cut) === array('log_cut|/var/log/wtmp 38400 -> 0'),
	'the same file getting shorter is held, even in a job\'s window; a rotated one (new inode) is not', var_export(nd_kinds($cut), true));

section('A partial run neither alarms nor teaches');

$p = NeverDoCheck::fold($st, NeverDoCheck::sanitise(nd_report(array(
	'partial' => true, 'inventory.ssh_keys' => array(), 'inventory.logs' => array('/var/log/wtmp 100 0'),
))), false, $now + 300);
check($p['unexplained'] === array() && $p['baseline']['ssh_keys'] === array('root SHA256:aaaa'),
	'keys it could not read are not taken as removed, and nothing is held from its lists');

section('A list the script cut keeps what it could not show');

$t = NeverDoCheck::fold($st, NeverDoCheck::sanitise(nd_report(array(
	'truncated' => array('startup'), 'inventory.startup' => array('/etc/cron.d/joinery-site 0123456789abcdef'),
))), false, $now + 300);
check(count($t['baseline']['startup']) === 2, 'an entry past the cap is not read as removed');

section('Accept');

$acc = NeverDoCheck::accept($held, 42, $now + 1200);
check($acc['unexplained'] === array() && in_array('root SHA256:evil', $acc['baseline']['ssh_keys'], true)
	&& $acc['accepted_by'] === 42, 'Accept clears what is held and takes the last report as the baseline, with the name');
$after = NeverDoCheck::fold($acc, $changed, false, $now + 1500);
check($after['unexplained'] === array(), 'the accepted change is not held again');

section('The incident');

$src = new IncidentSourceNeverDo();
$node = new ManagedNode(null);
check($src->evaluate($node) === null, 'a node never checked raises nothing');
$node->set('mgn_never_do', json_encode($st));
check($src->evaluate($node) === null, 'a quiet machine raises nothing');
$node->set('mgn_never_do', json_encode($held));
$inc = $src->evaluate($node);
check(is_array($inc) && $inc['severity'] === IncidentRecord::SEVERITY_CRITICAL
	&& strpos($inc['title'], 'new SSH key: root SHA256:evil') !== false,
	'a held item raises a critical incident naming it', var_export($inc, true));
$node->set('mgn_never_do', json_encode($acc));
check($src->evaluate($node) === null, 'after Accept it clears');
check(isset(IncidentSources::all()[IncidentSourceNeverDo::NAME]), 'the source is registered');

section('The words and levels');

check(SmActionLevels::level('node_detail', 'never_do_accept') === 10, 'accepting clears a security incident: superadmin');
check(JobCommandBuilder::build_never_do_check_primitive(null) === array('primitive' => 'never_do_check', 'params' => array()),
	'the job carries no parameters');

harness_finish();
