<?php
/** @joinery-test
 * name: site_backup_notice
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * A failing site backup is an admin notice from the FIRST failure, cleared by
 * the next success (specs/post_release_fleet_defects.md B3). The site profile
 * is the backup a self-hosted owner relies on; its failure used to land only in
 * the task's last-run status and the cron log.
 *
 * Run: php tests/backups/site_backup_notice_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$_SESSION['permission'] = 10;

$row = function (string $outcome, string $message, string $start, string $profile = 'site') {
	$h = new BackupHistory(NULL);
	$h->set('bkh_type', 'project');
	$h->set('bkh_outcome', $outcome);
	$h->set('bkh_slug', 'harness-notice');
	$h->set('bkh_profile', $profile);
	$h->set('bkh_start_time', $start);
	if ($outcome !== 'running') {
		$h->set('bkh_finish_time', $start);
	}
	$h->set('bkh_message', $message);
	$h->set('bkh_target_name', 'Harness target');
	$h->save();
	$id = (int)$h->key;
	harness_defer(function () use ($id) { $r = new BackupHistory($id, TRUE); $r->permanent_delete(); });
	return $h;
};

section('Nothing to say on a site with no site-profile run');
check(SiteBackupNotice::lastFinishedRun() === null, 'no run, no fact');
check(SiteBackupNotice::render() === '', 'and no notice');

section('The first failure is named, with the engine\'s last line');
$row('failed', "tar: a: Cannot open: Permission denied | \x1b[0;31m[ERROR]\x1b[0m Archive failed (tar exit 2)", '2026-09-13 06:00:28');
$html = SiteBackupNotice::render();
check($html !== '', 'one failed run is enough for the notice');
check(strpos($html, 'failed at 2026-09-13 06:00 UTC') !== false, 'it says when', strip_tags($html));
check(strpos($html, '[ERROR] Archive failed (tar exit 2)') !== false, 'with the engine\'s last line, colour codes stripped');
check(strpos($html, 'Cannot open') === false, 'and not the whole transcript');
check(strpos($html, 'Harness target') !== false, 'naming the target');
check(strpos($html, '/admin/admin_backups') !== false, 'linking to the Backups page');

section('A manager-profile run is not this site\'s own backup');
$row('failed', 'manager failure', '2026-09-13 07:00:00', 'manager');
check(strpos(SiteBackupNotice::render(), '06:00 UTC') !== false, 'the newest SITE run still decides');

section('A run that started hours ago and never finished is a failure');
// The process the kernel kills, or the one whose database went away, writes
// nothing: its row stays `running` for ever.
$row('running', '', '2026-09-13 08:00:00');
$stale = SiteBackupNotice::lastFinishedRun();
check($stale !== null && $stale->get('bkh_outcome') === 'running', 'a stale running row is returned as the last run');
$html = SiteBackupNotice::render();
check(strpos($html, 'started at 2026-09-13 08:00 UTC and never finished') !== false,
	'the header names it as a run that never finished', strip_tags($html));
check(strpos($html, 'Harness target') !== false, 'naming the target');

section('The next success clears it');
$row('success', 'Backed up site.tar', '2026-09-13 09:00:00');
$last = SiteBackupNotice::lastFinishedRun();
check($last !== null && $last->get('bkh_outcome') === 'success', 'the newest run is the success');
check(SiteBackupNotice::render() === '', 'and the notice is gone');

section('Wording, pure');
check(SiteBackupNotice::forRun('success', 'x', '2026-01-01 00:00:00', 't') === '', 'success renders nothing');
check(SiteBackupNotice::forRun('failed', '', '2026-01-01 00:00:00', '') !== '', 'a failure with no message still renders');
check(strpos(SiteBackupNotice::forRun('failed', '<b>x</b>', '2026-01-01 00:00:00', ''), '<b>') === false, 'the message is escaped');

section('The run window, pure');
$now = strtotime('2026-09-22 12:00:00 UTC');
check(SiteBackupNotice::isStale('2026-09-22 04:00:00', $now), 'eight hours running is stale');
check(!SiteBackupNotice::isStale('2026-09-22 07:00:00', $now), 'five hours running is not');
check(!SiteBackupNotice::isStale('', $now), 'no start time is never stale');
check(strpos(SiteBackupNotice::forStaleRun('2026-09-22 04:00:00', '<i>t</i>'), '<i>') === false, 'the target is escaped');

section('Only a superadmin sees it');
$_SESSION['permission'] = 5;
$row('failed', 'again', '2026-09-13 10:00:00');
check(SiteBackupNotice::render() === '', 'an admin below 10 sees nothing');
$_SESSION['permission'] = 10;
check(SiteBackupNotice::render() !== '', 'a superadmin does');

section('A run in progress is not a failure');
$row('running', '', gmdate('Y-m-d H:i:s', time() - 3600));
check(SiteBackupNotice::lastFinishedRun() === null && SiteBackupNotice::render() === '',
	'while the newest run started an hour ago and is running, the header is quiet');

harness_finish();
