<?php
/** @joinery-test
 * name: disk_allowance
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * DiskAllowance — a site on a shared host stops taking uploads and stored mail
 * at its disk allowance, before the disk refuses every write
 * (specs/multi_tenant_docker_hosts.md WP4, S13).
 *
 *  - A site with no allowance (every self-hosted site) is never refused, and
 *    has no figure.
 *  - Not knowing what is used is not full: nothing is refused on a guess.
 *  - Something that would take the site past its allowance is refused, in
 *    words naming what is used and the way out; at the allowance, everything is.
 *  - The refusal reaches each way bytes come in: a chunked upload's
 *    space_refusal(), a form upload's validate(), and storing a message.
 *
 * The allowance and its use are held (DiskAllowance::readWith()), so nothing
 * here reads or writes the disk.
 *
 * Run: php tests/run.php safe --filter=disk_allowance
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$MB = 1024 * 1024;
$set = function ($allowance, $used) {
	DiskAllowance::readWith(function () use ($allowance, $used) { return array($allowance, $used); });
};
harness_defer(function () { DiskAllowance::readWith(null); });

section('A site with no allowance');

$set(null, null);
check(DiskAllowance::state() === null, 'no allowance: no figure');
check(DiskAllowance::refusal(5000 * $MB) === '' && DiskAllowance::isFull() === false, 'and nothing is refused');

section('Within the allowance');

$set(1000 * $MB, 500 * $MB);
$s = DiskAllowance::state();
check($s['percent'] === 50 && $s['full'] === false, 'half used reads 50%, not full', json_encode($s));
check(DiskAllowance::refusal(400 * $MB) === '', 'what fits is not refused');
$why = DiskAllowance::refusal(600 * $MB);
check($why !== '' && strpos($why, 'of its 1000 MB') !== false && strpos($why, 'Delete') !== false,
	'what would pass the allowance is refused, naming the allowance and the way out', $why);

section('At the allowance');

$set(1000 * $MB, 1000 * $MB);
check(DiskAllowance::isFull() === true, 'used up: full');
check(DiskAllowance::refusal(0) !== '' && DiskAllowance::refusal(1) !== '', 'and everything is refused');
$set(1000 * $MB, 1100 * $MB);
check(DiskAllowance::state()['percent'] === 110 && DiskAllowance::isFull(), 'into the 10% above it: still full, the figure past 100');

section('An allowance with no limit behind it is no allowance');

// Released (its project's limit gone) while the file stayed: the disk seen is
// the whole pool, and every other site's bytes would read as this one's.
DiskAllowance::readWith(function () use ($MB) { return array(1000 * $MB, 25000 * $MB, 30000 * $MB); });
check(DiskAllowance::state() === null && DiskAllowance::refusal(1) === '' && !DiskAllowance::isFull(),
	'a disk far bigger than the allowance (the pool) means no allowance: nothing refused (reviewer2 B1)');
DiskAllowance::readWith(function () use ($MB) { return array(1000 * $MB, 1050 * $MB, 1100 * $MB); });
check(DiskAllowance::isFull(), 'the allowance plus its 10% is the limit in force, and is read');

section('Not knowing is not full');

$set(1000 * $MB, null);
check(DiskAllowance::isFull() === false && DiskAllowance::refusal(5000 * $MB) === '',
	'an allowance whose use the disk will not say refuses nothing');

section('Each way bytes come in is refused at the allowance');

$set(1000 * $MB, 1000 * $MB);
check(strpos(FileUpload::space_refusal(1), 'disk space') !== false, 'a chunked upload (FileUpload::space_refusal)');
$src = file_get_contents(PathHelper::getIncludePath('includes/UploadHandler.php'));
check(preg_match('/function validate\(.*?DiskAllowance::refusal\(/s', $src) === 1, 'a form upload (UploadHandler::validate)');
if (class_exists('InboundEmailMessage')) {
	// The one write every way mail arrives shares: a new message row.
	$threw = '';
	try { (new InboundEmailMessage(NULL))->save(); } catch (\Throwable $e) { $threw = get_class($e); }
	check($threw === 'MailboxAtDiskAllowance' && is_subclass_of('MailboxAtDiskAllowance', 'MailboxStoreDeclined'),
		'a new message row is refused (InboundEmailMessage::save), as a store declined for now', $threw);
	// Each way mail arrives defers on that refusal rather than dropping or
	// skipping the message (reviewer2 B3): the router (its spam-held copy too),
	// the IMAP feed, Direct, an import.
	$mb = PathHelper::getIncludePath('plugins/mailbox/includes/');
	$router = file_get_contents($mb . 'InboundEmailRouter.php');
	check(substr_count($router, 'catch (MailboxAtDiskAllowance $e) {') === 2 && substr_count($router, 'return $this->deferAtDiskAllowance(') === 3,
		'the router defers (75) on it in the main and spam-held stores, logged once a window');
	check(strpos(file_get_contents($mb . 'ImapIngestor.php'), 'InboundStoreCollisionException | MailboxStoreDeclined $e') !== false
		&& strpos(file_get_contents($mb . 'MailDirectHandler.php'), 'catch (MailboxStoreDeclined $e)') !== false
		&& strpos(file_get_contents($mb . 'import/MailArchiveImporter.php'), 'catch (MailboxStoreDeclined $e)') !== false,
		'the IMAP feed holds its cursor, Direct holds the delivery, an import holds the entry');
}
// Within it, the allowance is the measure: the disk such a site sees is its
// allowance plus 10%, so the whole-disk reserve (1 GB) would refuse a small
// allowance outright. Seen on a 1 GB site, 2026-10-07.
$set(1000 * $MB, 300 * $MB);
DiskSpace::readWith(function ($dir) use ($MB) { return 50 * $MB; });
harness_defer(function () { DiskSpace::readWith(null); });
check(FileUpload::space_refusal(100 * $MB) === '',
	'well within the allowance, an upload is taken whatever the whole-disk reserve would say');
$set(null, null);
check(FileUpload::space_refusal(100 * $MB) !== '', 'and a site with no allowance keeps the whole-disk reserve');
DiskSpace::readWith(null);

harness_finish();
