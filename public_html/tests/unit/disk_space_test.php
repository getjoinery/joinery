<?php
/** @joinery-test
 * name: disk_space
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * DiskSpace — the guard that stops a job filling the filesystem.
 *
 * The interesting behaviour is at the edges rather than in the arithmetic, so
 * that is what is pinned here:
 *
 *  - NOT KNOWING IS NOT ZERO. A host where free space cannot be measured must
 *    still be able to run the jobs the guard protects; a check meant as a safety
 *    net that silently becomes a gate is worse than no check.
 *  - A path that does not exist YET is the normal case, not an error — callers
 *    ask about directories they are about to write into.
 *  - The floor is real: a request that fits the disk exactly is still refused,
 *    because a machine with nothing spare cannot log, journal, or recover.
 *  - The refusal SAYS THE NUMBERS. A message that does not tell the reader
 *    whether deleting one file would fix it has not told them anything.
 *
 * No writing and no database: every check reads the free space that is already
 * there and does arithmetic against it.
 *
 * Run: php tests/run.php safe --filter=disk_space
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$tmp = sys_get_temp_dir();
$free = DiskSpace::freeBytes($tmp);

section('Reading free space');

check(is_int($free) && $free > 0,
	'free space on the temp directory reads as a positive integer',
	'got ' . var_export($free, true));

$missing = $tmp . '/joinery-disk-space-' . bin2hex(random_bytes(6)) . '/not/created/yet';
check(!is_dir($missing), 'the test path genuinely does not exist');
check(DiskSpace::freeBytes($missing) === $free,
	'a path that does not exist answers for its nearest real ancestor',
	'a caller asking about a directory it is about to create must not be told "no space"');

check(is_int(DiskSpace::freeBytes('/')) && DiskSpace::freeBytes('/') > 0,
	'the root filesystem reads');

check(DiskSpace::freeBytes('') === DiskSpace::freeBytes('/'),
	'an empty path is treated as root rather than failing');

section('The least free of several paths');

check(DiskSpace::leastFreeBytes(array($tmp, '/')) === min($free, (int)DiskSpace::freeBytes('/')),
	'two paths answer with the tighter of the two');

check(DiskSpace::leastFreeBytes(array()) === null,
	'no paths at all is unknowable, not zero');

check(DiskSpace::leastFreeBytes(array($tmp, $missing)) === $free,
	'an unmeasurable path never drags the answer down');

section('Room for a job');

check(DiskSpace::roomFor(0, array($tmp), 0) === true,
	'nothing always fits');

check(DiskSpace::roomFor(PHP_INT_MAX >> 4, array($tmp)) === false,
	'a request far larger than the disk is refused');

// The floor is the whole point of this one: the bytes fit, the reserve does not.
check(DiskSpace::roomFor($free, array($tmp), 0) === true,
	'a job that exactly fills the disk fits when no reserve is asked for');
check(DiskSpace::roomFor($free, array($tmp), 1024) === false,
	'the same job is refused once a reserve is required',
	'"exactly enough" leaves a machine that cannot log or journal');

check(DiskSpace::roomFor($free - 1024, array($tmp), 1024) === true,
	'a job that leaves exactly the reserve is allowed');

check(DiskSpace::roomFor(1024, array(), DiskSpace::DEFAULT_FLOOR_BYTES) === true,
	'unmeasurable free space allows the job',
	'a host hiding disk_free_space would otherwise have the feature permanently off');

check(DiskSpace::roomFor(-5, array($tmp), 0) === true,
	'a negative size is treated as nothing rather than as credit');

section('What the refusal says');

check(DiskSpace::shortfallMessage(0, array($tmp), 0) === '',
	'a job that fits produces no message at all');

$message = DiskSpace::shortfallMessage($free + 4096, array($tmp), 0);
check($message !== '', 'a job that does not fit produces one');
check(strpos($message, 'Not enough disk space') === 0,
	'it opens by naming the problem');
foreach (array('needs about', 'in reserve', 'is free', 'Free up') as $phrase) {
	check(strpos($message, $phrase) !== false,
		'the message says "' . $phrase . '"',
		'all three numbers and the remedy, or the reader cannot act: ' . $message);
}

section('Bytes a person can read');

check(DiskSpace::format(0) === '0 B', 'zero');
check(DiskSpace::format(999) === '999 B', 'bytes stay bytes');
check(DiskSpace::format(1024) === '1 KB', 'a kilobyte');
check(DiskSpace::format(1073741824) === '1 GB', 'a gigabyte');
check(DiskSpace::format(1610612736) === '1.5 GB', 'a fraction of one');
check(DiskSpace::format(-10) === '0 B', 'a negative size does not print a negative');

section('What an import thinks it will cost');

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveImporter.php'));

check(MailArchiveImporter::estimatedStorageBytes(0) === 0,
	'an empty archive costs nothing');
check(MailArchiveImporter::estimatedStorageBytes(1000)
		=== 1000 * MailArchiveImporter::STORAGE_MULTIPLIER,
	'the estimate is the archive times the multiplier');
check(MailArchiveImporter::estimatedStorageBytes(1000) > 1000,
	'an import is always estimated to cost MORE than the archive it reads',
	'raw messages, extracted attachments and body columns all land on the same disk');
check(MailArchiveImporter::estimatedStorageBytes(-1) === 0,
	'a nonsense archive size does not produce a nonsense estimate');

$targets = MailArchiveImporter::storageTargets();
check(is_array($targets) && count($targets) > 0,
	'an import names at least one place it writes to');
$storage = rtrim(PathHelper::getSiteRoot(), '/') . '/storage/';
check(in_array($storage, $targets, true),
	'the raw-message store is one of them',
	'raw bytes are the bulk of what an import writes: ' . implode(', ', $targets));

harness_finish();
?>
