<?php
/** @joinery-test
 * name: recipe_case_notice
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The case on the site itself (includes/RecipeCaseNotice.php, tasks/RecipeCaseMail.php;
 * specs/agent_tier1_recipes.md, settled Q3 "Unpaired").
 *
 * The agent writes a rendered case outward under cache/recipes/ and never reads
 * it back; the web user can write there too. So what is worth testing is that
 * the notice and the mail render whatever is in that file as a REPORT — worded
 * "as reported by the agent's ledger", every field escaped or made plain, no
 * link that acts — and that a closed or unreadable file is silence.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = sys_get_temp_dir() . '/harness_recipe_case_' . bin2hex(random_bytes(4));
mkdir($root . '/cache/recipes', 0777, true);
harness_defer(function () use ($root) {
	foreach (glob($root . '/cache/recipes/*') ?: array() as $f) { @unlink($f); }
	@rmdir($root . '/cache/recipes'); @rmdir($root . '/cache'); @rmdir($root);
});

$open = array(
	'id' => 3, 'source' => 'recipe:fail2ban', 'recipe' => 'fail2ban', 'status' => 'open',
	'opened' => '2026-09-14T12:10:00Z', 'reason' => '3 attempts in the last hour and the check still fails (fail2ban is inactive)',
	'notes' => 4, 'last_note' => 'fail2ban is inactive', 'last_note_time' => '2026-09-14T12:50:00Z',
	'body' => array('mode' => 'report-only', 'attempts' => array(
		array('id' => 1, 'started' => '2026-09-14T11:20:00Z', 'word' => 'host_converge', 'outcome' => 'report-only', 'detail' => 'would have run host_converge'),
	), 'host_report' => 'unknown: no environment', 'vocabulary' => 'host_report', 'recipes' => 'fail2ban:report-only'),
	'delivery' => 'local', 'rendered' => '2026-09-14T12:50:00Z',
);

section('The rendered files are read as records, bounded');
file_put_contents($root . '/cache/recipes/fail2ban.case.json', json_encode($open));
file_put_contents($root . '/cache/recipes/fail2ban.jsonl', "{}\n");
file_put_contents($root . '/cache/recipes/broken.case.json', 'not json');
file_put_contents($root . '/cache/recipes/List.case.json', '[1,2]');
$records = RecipeCaseNotice::records($root);
check(array_keys($records) === array('fail2ban'), 'One record per well-formed <recipe>.case.json; the ledger, a broken file and a list are skipped',
	json_encode(array_keys($records)));
check(($records['fail2ban']['id'] ?? 0) === 3 && $records['fail2ban']['recipe'] === 'fail2ban', 'The record carries the case');

section('The notice says it is a report, and escapes everything');
$html = RecipeCaseNotice::forRecord($records['fail2ban']);
check(strpos($html, "as reported by the agent&#039;s ledger") !== false || strpos($html, "as reported by the agent's ledger") !== false,
	'The notice says the case is as reported by the agent\'s ledger');
check(strpos($html, 'case #3 is open') !== false, 'It names the case');
check(strpos($html, 'report-only') !== false, 'A report-only case says nothing was changed');
check(strpos($html, 'not paired') !== false, 'A locally delivered case says a person is the next actor');
check(strpos($html, '<a ') === false, 'The notice carries no link');

$hostile = $open;
$hostile['reason'] = '<script>alert(1)</script> http://evil.example/ ' . str_repeat('x', 3000);
$hostile['last_note'] = '"><img src=x onerror=alert(1)>';
$hostile['recipe'] = 'fail2ban';
$html = RecipeCaseNotice::forRecord($hostile);
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'A forged reason is escaped');
check(strpos($html, '<img') === false, 'A forged note is escaped');
check(strlen($html) < 2500, 'A forged reason is bounded', strlen($html) . ' bytes');
check(!preg_match('#href=#', $html), 'A URL in a forged field is never a link');

$paired = $open; $paired['delivery'] = 'management node';
check(strpos(RecipeCaseNotice::forRecord($paired), 'management node this host is paired to has the case too') !== false,
	'A case the management node has says so');

$closed = $open; $closed['status'] = 'closed';
check(RecipeCaseNotice::forRecord($closed) === '', 'A closed case is silence');
check(RecipeCaseNotice::forRecord(array('status' => 'open')) !== '', 'A bare open record still renders, with unknowns');

section('The mail is plain text with no link');
$mail = RecipeCaseNotice::mail_body($hostile, 'Example <b>site</b>');
check(strip_tags($mail) === $mail, 'No markup survives into the mail (the sender would switch to HTML on the first tag)');
check(strpos($mail, 'http://') === false && strpos($mail, '://') === false, 'No link at all');
check(strpos($mail, "as reported by the agent's ledger") !== false, 'The mail says it is a report');
check(strpos($mail, 'host_converge: report-only') !== false, 'The mail lists what the agent tried');
check(strpos($mail, 'once a day per recipe') !== false, 'The mail says how often it comes');

section('The mail is the unpaired path only, once a day per recipe');
require_once(PathHelper::getIncludePath('tasks/RecipeCaseMail.php'));
$sends = array();
$send = function ($to, $subject, $body) use (&$sends) { $sends[] = $to . ' | ' . $subject; };
$plane = $open; $plane['delivery'] = 'management node';
$r = RecipeCaseMail::mail(array('fail2ban' => $plane), array('fail2ban' => 1), 1000000, array('a@example.test'), 'Example', $send);
check(count($sends) === 0 && !isset($r['log']['fail2ban']),
	'A case the management node has is never mailed from the node, and its log entry is cleared', json_encode($r));
$r = RecipeCaseMail::mail(array('fail2ban' => $open), array(), 1000000, array('a@example.test', 'b@example.test'), 'Example', $send);
check(count($sends) === 2 && ($r['log']['fail2ban'] ?? 0) === 1000000, 'A local open case is mailed to every superadmin and logged', json_encode($r));
check(strpos($sends[0], 'fail2ban recipe gave up: case #3 open') !== false, 'The subject names the recipe and the case', $sends[0]);
$sends = array();
$r = RecipeCaseMail::mail(array('fail2ban' => $open), $r['log'], 1000000 + 3600, array('a@example.test'), 'Example', $send);
check(count($sends) === 0, 'The same recipe is not mailed again inside a day');
$r = RecipeCaseMail::mail(array('fail2ban' => $open), $r['log'], 1000000 + 86401, array('a@example.test'), 'Example', $send);
check(count($sends) === 1, 'A day later it is mailed once more while still open');
$sends = array();
$r = RecipeCaseMail::mail(array('fail2ban' => $closed), $r['log'], 1000000 + 90000, array('a@example.test'), 'Example', $send);
check(count($sends) === 0 && !isset($r['log']['fail2ban']), 'A closed case sends nothing and clears its log entry, so the next case mails at once');
$hostile_site = $open;
$r = RecipeCaseMail::mail(array('fail2ban' => $hostile_site), array(), 1, array('a@example.test'), "Evil <b>site</b>\r\nBcc: x", $send);
check(count($sends) === 1 && strpos($sends[0], "\n") === false && strpos($sends[0], "\r") === false, 'A line break in the site name cannot reach the subject', json_encode($sends));

section('The task is declared to activate on install, hourly');
$task = json_decode((string)file_get_contents(__DIR__ . '/../../tasks/RecipeCaseMail.json'), true);
check(is_array($task) && !empty($task['activate_on_install']) && ($task['default_frequency'] ?? '') === 'hourly'
	&& !array_key_exists('default_time', $task),
	'tasks/RecipeCaseMail.json: activate_on_install, hourly, no default_time', json_encode($task));
check(class_exists('RecipeCaseMail') && (new RecipeCaseMail()) instanceof ScheduledTaskInterface, 'The task class implements ScheduledTaskInterface');
$settings = json_decode((string)file_get_contents(__DIR__ . '/../../settings.json'), true);
$declared = false;
foreach ($settings['settings'] ?? array() as $s) {
	if (($s['name'] ?? '') === 'recipe_case_mail_log' && !empty($s['managed'])) { $declared = true; }
}
check($declared, 'The mail log setting is declared in settings.json as managed, so Setting::put accepts it');

harness_finish();
