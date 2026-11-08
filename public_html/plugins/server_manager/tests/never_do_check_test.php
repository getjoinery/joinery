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
 * specs/node_unexplained_root.md § 5.1, specs/never_do_false_alarms.md): the
 * script's object held to its shape; the alarm, held on the second report in a
 * row, surviving its own removal, and quiet after Mark safe until the file's
 * content changes; the review list, which raises nothing, with its baseline,
 * job window and Clear; a format-1 report read as it always was; a 1.0 state
 * sorted on read; and the incident raised only by the alarm.
 *
 * Run: php plugins/server_manager/tests/never_do_check_test.php
 *
 * @version 1.2 - the alarm and the review list (NeverDoCheck 1.1)
 * @version 1.1 - job lists hide its completed runs
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

function nd_report(array $over = array()): array {
	$r = array(
		'format' => 2,
		'findings' => array(
			'setuid_unpackaged' => array(), 'setuid_modified' => array(), 'setid_other' => array(), 'setuid_copied_root' => array(), 'uid0_accounts' => array(),
			'kernel_taint' => '', 'scratch_programs' => array(), 'deleted_root_programs' => array(), 'ld_preload' => '',
			'ld_preload_unpackaged' => array(), 'ld_preload_packaged' => array(),
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

function nd_kinds(array $state, string $list = 'review'): array {
	return array_map(function ($u) { return $u['kind'] . '|' . $u['item']; }, $state[$list]);
}

function nd_fold(?array $st, array $over, bool $explained, int $at): array {
	return NeverDoCheck::fold($st, NeverDoCheck::sanitise(nd_report($over)), $explained, $at);
}

function nd_raises(array $st): bool {
	$node = new ManagedNode(null);
	$node->set('mgn_never_do', json_encode($st));
	return (new IncidentSourceNeverDo())->evaluate($node) !== null;
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
check($s['format'] === 2 && NeverDoCheck::sanitise(nd_report(array('format' => null)))['format'] === 1
	&& NeverDoCheck::sanitise(nd_report(array('format' => '2')))['format'] === 1,
	'format 2 says so; anything else is a release older than script 1.7');

section('The first report is the baseline');

$now = 1762170000;
$st = nd_fold(null, array(), false, $now);
check($st['alarm'] === array() && $st['review'] === array(), 'nothing is held from the first report of a quiet machine');
check($st['baseline']['ports'] === array('tcp 0.0.0.0:22 sshd', 'tcp *:443 apache2'), 'its lists become the baseline');

section('The alarm: two reports in a row');

$planted = '/usr/local/bin/sentinel-planted-test 0123456789abcdef';
$one = nd_fold($st, array('findings.setuid_unpackaged' => array($planted)), true, $now + 300);
check($one['alarm'] === array() && count($one['pending']) === 1 && !nd_raises($one),
	'one report of a setuid-root program no package installed raises nothing yet, even outside a job\'s window');
$two = nd_fold($one, array('findings.setuid_unpackaged' => array($planted)), true, $now + 600);
check(nd_kinds($two, 'alarm') === array('setuid_unpackaged|' . $planted) && nd_raises($two),
	'the second report in a row holds it and raises, even inside a job\'s window', var_export(nd_kinds($two, 'alarm'), true));
check($two['alarm'][0]['seen'] === $now + 300, 'first seen is the first report');
$mod = nd_fold(nd_fold($st, array('findings.setuid_modified' => array('/usr/bin/passwd aaaaaaaaaaaaaaaa')), false, $now + 300),
	array('findings.setuid_modified' => array('/usr/bin/passwd aaaaaaaaaaaaaaaa')), false, $now + 600);
$mod1 = nd_fold($st, array('findings.setuid_modified' => array('/usr/bin/passwd aaaaaaaaaaaaaaaa')), false, $now + 300);
check(!nd_raises($mod1) && nd_raises($mod), 'a packaged setuid program whose bytes changed: one report raises nothing, two do');
$gap = nd_fold(nd_fold($one, array(), false, $now + 600), array('findings.setuid_unpackaged' => array($planted)), false, $now + 900);
check($gap['alarm'] === array() && count($gap['pending']) === 1,
	'seen, then gone, then seen again is not two in a row (an upgrade\'s half-written file)');
$rewritten = nd_fold($one, array('findings.setuid_unpackaged' => array('/usr/local/bin/sentinel-planted-test ffffffffffffffff')), false, $now + 600);
check(count($rewritten['alarm']) === 1, 'a file rewritten between the two reports is still the same path: held');
$partial_gap = nd_fold(nd_fold($one, array('partial' => true), false, $now + 600),
	array('findings.setuid_unpackaged' => array($planted)), false, $now + 900);
check(count($partial_gap['alarm']) === 1, 'a run whose walk ran out of time does not forget what the one before it saw');
$u0 = nd_fold(nd_fold($st, array('findings.uid0_accounts' => array('toor')), false, $now + 300), array('findings.uid0_accounts' => array('toor')), false, $now + 600);
check(nd_kinds($u0, 'alarm') === array('uid0_accounts|toor'), 'an account other than root with uid 0');
$pre = nd_fold(nd_fold($st, array('findings.ld_preload_unpackaged' => array('/usr/lib/libx.so 1111111111111111')), false, $now + 300),
	array('findings.ld_preload_unpackaged' => array('/usr/lib/libx.so 1111111111111111')), false, $now + 600);
check(nd_kinds($pre, 'alarm') === array('ld_preload|/usr/lib/libx.so 1111111111111111'), 'a preload library no package installed');

$cut1 = nd_fold($st, array('findings.setuid_unpackaged' => array($planted)), false, $now + 300);
$cut2 = nd_fold(nd_fold($cut1, array('truncated' => array('setuid_unpackaged')), false, $now + 600),
	array('findings.setuid_unpackaged' => array($planted)), false, $now + 900);
check(count($cut2['alarm']) === 1, 'an alarm list the script cut does not forget the first sighting either');
check(count(NeverDoCheck::sanitise(nd_report(array('findings.setuid_unpackaged' => array_map(function ($i) {
	return '/opt/b/' . $i . ' 0123456789abcdef'; }, range(1, 150)))))['findings']['setuid_unpackaged']) === 150,
	'the alarm\'s lists keep more than the review list\'s 20');

section('Held stays held when it is undone');

$undone = nd_fold($two, array(), false, $now + 900);
check(count($undone['alarm']) === 1 && nd_raises($undone), 'removing the program does not clear the record of it');
$again = nd_fold($undone, array('findings.setuid_unpackaged' => array($planted)), false, $now + 1200);
check(count($again['alarm']) === 1, 'the same item seen again is held once');

section('Mark safe remembers the file by its content (B1)');

$safe = NeverDoCheck::accept($two, 42, $now + 700);
check($safe['alarm'] === array() && !nd_raises($safe) && $safe['accepted_by'] === 42, 'Mark safe clears the alarm, with the name');
$quiet = $safe;
foreach (array(1000, 1300, 1600) as $dt) {
	$quiet = nd_fold($quiet, array('findings.setuid_unpackaged' => array($planted)), false, $now + $dt);
}
check($quiet['alarm'] === array() && $quiet['pending'] === array() && !nd_raises($quiet),
	'the same file in every later report stays quiet: no new incident five minutes later');
$changed_bin = '/usr/local/bin/sentinel-planted-test fedcba9876543210';
$c1 = nd_fold($quiet, array('findings.setuid_unpackaged' => array($changed_bin)), false, $now + 1900);
$c2 = nd_fold($c1, array('findings.setuid_unpackaged' => array($changed_bin)), false, $now + 2200);
check(!nd_raises($c1) && nd_kinds($c2, 'alarm') === array('setuid_unpackaged|' . $changed_bin),
	'once its content changes it alarms again, on the second report', var_export(nd_kinds($c2, 'alarm'), true));
$moved = nd_fold($two, array('findings.setuid_unpackaged' => array($changed_bin)), false, $now + 650);
check($moved['alarm'][0]['item'] === $changed_bin && $moved['alarm'][0]['seen'] === $now + 300,
	'a held file whose content changes takes its current hash, keeping when it was first seen');
$moved_safe = NeverDoCheck::accept($moved, 42, $now + 700);
$moved_after = nd_fold(nd_fold($moved_safe, array('findings.setuid_unpackaged' => array($changed_bin)), false, $now + 1000),
	array('findings.setuid_unpackaged' => array($changed_bin)), false, $now + 1300);
check($moved_after['alarm'] === array() && $moved_after['pending'] === array(),
	'so Mark safe remembers what is there now, and nothing alarms ten minutes later');
$unread = nd_fold($quiet, array('findings.setuid_unpackaged' => array('/usr/local/bin/sentinel-planted-test')), false, $now + 1650);
check($unread['pending'] === array(), 'a marked-safe file whose hash could not be read this time (path alone) stays quiet');
check($safe['baseline'] === $two['baseline'] && $safe['review'] === $two['review'], 'Mark safe does not touch the review list or its baseline');

section('The review list raises nothing');

$changed = array(
	'inventory.ssh_keys' => array('root SHA256:aaaa', 'root SHA256:evil'),
	'inventory.ports'    => array('tcp 0.0.0.0:22 sshd', 'tcp *:443 apache2', 'tcp 0.0.0.0:4444 nc'),
);
$held = nd_fold($st, $changed, false, $now + 300);
check(nd_kinds($held) === array('ssh_keys|root SHA256:evil', 'ports|tcp 0.0.0.0:4444 nc') && $held['alarm'] === array(),
	'a new key and a new port no job explains are listed for review, not alarmed', var_export(nd_kinds($held), true));
check(!in_array('root SHA256:evil', $held['baseline']['ssh_keys'], true), 'and are not taken into the baseline');
$taken = nd_fold($st, $changed, true, $now + 300);
check($taken['review'] === array() && in_array('tcp 0.0.0.0:4444 nc', $taken['baseline']['ports'], true),
	'with a machine-changing job in the window they are taken in, nothing listed');
$every_over = array(
	'findings.setid_other'           => array('/usr/local/bin/mygame 2222222222222222'),
	'findings.setuid_copied_root'    => array('/srv/img/rootfs/usr/bin/su 6666666666666666'),
	'findings.kernel_taint'          => 'OE',
	'findings.scratch_programs'      => array('0 /tmp/installer-1/run'),
	'findings.deleted_root_programs' => array('/usr/sbin/oldd'),
	'findings.ld_preload_packaged'   => array('/usr/lib/x86_64-linux-gnu/libsnoopy.so'),
	'inventory.ssh_keys' => array('root SHA256:aaaa', 'root SHA256:new'),
	'inventory.accounts' => array('root 0 /bin/bash', 'user1 1000 /bin/bash', 'bob 1001 /bin/bash'),
	'inventory.sudoers'  => array('/etc/sudoers 0123456789abcdef', '/etc/sudoers.d/joinery-mail-gateway 3333333333333333'),
	'inventory.startup'  => array('/etc/cron.d/joinery-site fedcba9876543210', '/etc/systemd/system/multi-user.target.wants/x.service -> /usr/lib/systemd/system/x.service'),
	'inventory.ports'    => array('tcp 0.0.0.0:22 sshd', 'tcp *:443 apache2', 'tcp 127.0.0.1:9980 coolwsd'),
	'inventory.logs'     => array('/var/log/wtmp 100 0', '/var/log/auth.log 200 5000'),
);
$every = nd_fold(nd_fold($st, $every_over, false, $now + 300), $every_over, false, $now + 600);
$kinds_listed = array_values(array_unique(array_map(function ($u) { return $u['kind']; }, $every['review'])));
sort($kinds_listed);
check($kinds_listed === array('accounts', 'deleted_root_programs', 'kernel_taint', 'ld_preload_packaged', 'log_cut', 'ports',
		'scratch_programs', 'setid_other', 'setuid_copied_root', 'ssh_keys', 'startup', 'sudoers'),
	'every review kind is listed', var_export($kinds_listed, true));
check($every['alarm'] === array() && $every['pending'] === array() && !nd_raises($every),
	'and none of them, over two reports, raises anything');
$in_window = nd_fold($st, array('findings.scratch_programs' => array('0 /tmp/installer-1/run')), true, $now + 300);
check($in_window['review'] === array(), 'a program in /tmp inside a job\'s window is not even listed (an installer)');
$cut = nd_fold($st, array('inventory.logs' => array('/var/log/wtmp 100 0', '/var/log/auth.log 201 10')), true, $now + 300);
check(nd_kinds($cut) === array('log_cut|/var/log/wtmp 38400 -> 0'),
	'a log the same file got shorter is listed, even in a job\'s window; a rotated one (new inode) is not', var_export(nd_kinds($cut), true));

section('Clear');

$cl = NeverDoCheck::clear($every, 7, $now + 700);
check($cl['review'] === array() && $cl['cleared_by'] === 7 && in_array('tcp 127.0.0.1:9980 coolwsd', $cl['baseline']['ports'], true),
	'Clear empties the list and takes the last report as what is expected, with the name');
$after = nd_fold($cl, $every_over, false, $now + 1000);
check($after['review'] === array(), 'what stays (a taint, a program, a port) is not listed again after Clear',
	var_export(nd_kinds($after), true));
$gone = $every_over; unset($gone['findings.kernel_taint']);
$back = nd_fold(nd_fold($after, $gone, false, $now + 1300), $every_over, false, $now + 1600);
check(nd_kinds($back) === array('kernel_taint|OE'), 'one that goes and comes back is listed again');
check(NeverDoCheck::clear($two, 7, $now + 700)['alarm'] === $two['alarm'], 'Clear does not touch the alarm');
$cr1 = nd_fold($st, array('findings.setuid_copied_root' => array('/srv/img/rootfs/usr/bin/su 6666666666666666')), false, $now + 300);
$cr2 = nd_fold($cr1, array('findings.setuid_copied_root' => array('/srv/img/rootfs/usr/bin/su 7777777777777777')), false, $now + 600);
check(nd_kinds($cr2) === array('setuid_copied_root|/srv/img/rootfs/usr/bin/su 7777777777777777'),
	'a copied root rebuilt keeps one row per program, with its current hash', var_export(nd_kinds($cr2), true));
$tmp_over = array('findings.scratch_programs' => array('0 /tmp/long-runner'));
$tc = NeverDoCheck::clear(nd_fold($st, $tmp_over, false, $now + 300), 7, $now + 400);
$tw = nd_fold(nd_fold($tc, $tmp_over, true, $now + 600), $tmp_over, false, $now + 900);
check($tw['review'] === array(), 'a Cleared program in /tmp is not listed again because a job ran while it was there');

section('A partial run neither lists nor teaches');

$p = nd_fold($st, array('partial' => true, 'inventory.ssh_keys' => array(), 'inventory.logs' => array('/var/log/wtmp 100 0')), false, $now + 300);
check($p['review'] === array() && $p['baseline']['ssh_keys'] === array('root SHA256:aaaa'),
	'keys it could not read are not taken as removed, and nothing is listed from its lists');

section('A list the script cut keeps what it could not show');

$t = nd_fold($st, array('truncated' => array('startup'), 'inventory.startup' => array('/etc/cron.d/joinery-site 0123456789abcdef')), false, $now + 300);
check(count($t['baseline']['startup']) === 2, 'an entry past the cap is not read as removed');

section('A machine on an older release (format 1)');

$old = array('format' => null, 'findings.setuid_unpackaged' => array('/usr/local/bin/sgid-game'),
	'findings.ld_preload' => '/usr/lib/libsnoopy.so', 'findings.kernel_taint' => 'O');
$o2 = nd_fold(nd_fold($st, $old, false, $now + 300), $old, false, $now + 600);
check(nd_kinds($o2, 'alarm') === array('setuid_unpackaged|/usr/local/bin/sgid-game', 'ld_preload|/usr/lib/libsnoopy.so'),
	'its setuid list (setgid included) and its preload alarm as they always did', var_export(nd_kinds($o2, 'alarm'), true));
check(nd_kinds($o2) === array('kernel_taint|O'), 'and its taint goes on the review list');
$o_safe = NeverDoCheck::accept($o2, 42, $now + 700);
$upgraded = array('findings.setuid_unpackaged' => array('/usr/local/bin/sgid-game 4444444444444444'));
$u1 = nd_fold($o_safe, $upgraded, false, $now + 1000);
$u2 = nd_fold($u1, $upgraded, false, $now + 1300);
check($u2['alarm'] === array() && in_array('/usr/local/bin/sgid-game 4444444444444444', array_column($u2['accepted'], 'item'), true),
	'a path marked safe from a format-1 report stays quiet when the machine upgrades, and takes the content first seen');
$u3 = nd_fold(nd_fold($u2, array('findings.setuid_unpackaged' => array('/usr/local/bin/sgid-game 5555555555555555')), false, $now + 1600),
	array('findings.setuid_unpackaged' => array('/usr/local/bin/sgid-game 5555555555555555')), false, $now + 1900);
check(count($u3['alarm']) === 1, 'and a change to that content after alarms');

section('A state from NeverDoCheck 1.0');

$v10 = array('baseline' => $st['baseline'], 'current' => $st['current'], 'logs' => $st['logs'], 'checked_at' => $now,
	'unexplained' => array(
		array('kind' => 'sudoers', 'item' => '/etc/sudoers.d/joinery-mail-gateway 3333333333333333', 'seen' => $now),
		array('kind' => 'scratch_programs', 'item' => '0 /usr/bin/bash /tmp/install', 'seen' => $now),
	), 'accepted_at' => $now - 86400, 'accepted_by' => 1);
$node = new ManagedNode(null);
$node->set('mgn_never_do', json_encode($v10));
$src = new IncidentSourceNeverDo();
check($src->evaluate($node) === null, 'held inventory and soft items raise nothing once read by 1.1: their incidents clear');
check(strpos($src->cleared_text($node), 'Changes to review') !== false, 'and the cleared text says where they went', $src->cleared_text($node));
$up = NeverDoCheck::state($node);
check(nd_kinds($up) === array('sudoers|/etc/sudoers.d/joinery-mail-gateway 3333333333333333', 'scratch_programs|0 /usr/bin/bash /tmp/install'),
	'they are on the review list', var_export(nd_kinds($up), true));
$v10['unexplained'][] = array('kind' => 'setuid_unpackaged', 'item' => '/usr/local/bin/x', 'seen' => $now);
$node->set('mgn_never_do', json_encode($v10));
check($src->evaluate($node) !== null, 'a held setuid item stays in the alarm');
$folded = NeverDoCheck::fold($v10, NeverDoCheck::sanitise(nd_report()), false, $now + 300);
check(!array_key_exists('unexplained', $folded) && count($folded['alarm']) === 1 && count($folded['review']) === 2,
	'the first fold writes the new shape');

section('The incident');

$node = new ManagedNode(null);
check($src->evaluate($node) === null, 'a node never checked raises nothing');
$node->set('mgn_never_do', json_encode($st));
check($src->evaluate($node) === null, 'a quiet machine raises nothing');
$node->set('mgn_never_do', json_encode($two));
$inc = $src->evaluate($node);
check(is_array($inc) && $inc['severity'] === IncidentRecord::SEVERITY_CRITICAL
	&& strpos($inc['title'], 'setuid-root program no package installed: /usr/local/bin/sentinel-planted-test (content 0123456789abcdef)') !== false,
	'the alarm raises a critical incident naming it', var_export($inc, true));
check(strpos($inc['detail']['Why'], 'two checks in a row') !== false && strpos($inc['detail']['Why'], 'no upgrade') === false,
	'its Why says what the alarm is, not that no job ran');
$node->set('mgn_never_do', json_encode($safe));
check($src->evaluate($node) === null && strpos($src->cleared_text($node), 'Marked safe') === 0, 'after Mark safe it clears, saying so');
check(isset(IncidentSources::all()[IncidentSourceNeverDo::NAME]), 'the source is registered');

section('The words and levels');

check(SmActionLevels::level('node_detail', 'never_do_accept') === 10, 'Mark safe clears a security incident: superadmin');
check(SmActionLevels::level('node_detail', 'never_do_clear') === 10, 'Clear resets what is expected: superadmin');
check(JobCommandBuilder::build_never_do_check_primitive(null) === array('primitive' => 'never_do_check', 'params' => array()),
	'the job carries no parameters');

section('Job lists');

check(in_array('never_do_check', ManagementJob::ROUTINE_TYPES, true),
	'the check is routine: its completed runs (every five minutes) are left out of job lists');
check(in_array('never_do_check', ManagementJob::filterTypes(), true), 'and can still be picked as a type to see them');
check(strpos(file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/node_detail_tabs/overview.php')),
	"'hide_routine' => true") !== false, 'the node page\'s Recent jobs hides them');

harness_finish();
