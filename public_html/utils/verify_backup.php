<?php
/**
 * verify_backup.php — prove one of this machine's backups can be recovered,
 * without restoring it.
 *
 * A backup that has never been opened is a hope. This script turns it into a
 * fact: it stages the set a restore of one run would need (the full, every
 * incremental up to that run, its database dump and metadata), recovers the
 * chain key from this machine's own config/backup_site_key, and then either
 *
 *   level 2, "opened and read" — decrypts every artifact to a pipe and reads it
 *   to the end (tar listing for the archives, a full decompression and header
 *   check for the dump); or
 *
 *   level 3, "rehearsed" — level 2, then replays the files into a scratch tree
 *   under the backup working area and loads the dump into a throwaway database
 *   on this machine's own PostgreSQL, counts what came back, and deletes both.
 *
 * Offloaded files (specs/implemented/backup_offloaded_files.md § Verification) are proven
 * with the rest. Level 2 opens the epoch envelope of every epoch the run's
 * index names with this machine's own key — no request per object; backup storage
 * listing already proved presence and size. Level 3 also brings back the
 * sample the request links (the 5 largest and 15 random, picked by whoever
 * signed the links from the same index), checks each against the index's
 * hash, decrypts it, and compares it to the rehearsed database's row.
 *
 * Nothing on the live site is touched at either level. The working directory
 * is removed on every exit path, including a fatal, because a verify that left
 * a staged chain behind would be a disk leak on a machine that may already be
 * tight.
 *
 * Everything about what may be fetched is BackupStaging's, shared with Prepare
 * (utils/stage_chain.php): a verify can never fetch something a Prepare would
 * refuse. As there, the script accepts no decryption key and no bucket
 * credential, and prints neither.
 *
 * The result is stamped on this machine's own history row for the run (the
 * node's history is the authority for "verified"): a pass or a failure in
 * full, a skip or a refusal as the run's message only, so a verify a person
 * started leaves a trace whatever became of it. It is printed as the
 * contract below for the management node or the scheduled task that ran it.
 *
 * Configuration arrives as JSON on stdin, and only on stdin:
 *
 *   php utils/verify_backup.php <<'EOF'
 *   {"chain_id":"chain-20260912_044520","profile":"manager","level":2,
 *    "manifest_url":"https://…signed…",
 *    "artifact_urls":{"files-0000.tar.gz.enc":"https://…","db-0000.sql.gz.enc":"https://…"},
 *    "epoch_envelope_urls":{"epoch-20260901_000000":"https://…"},
 *    "object_urls":{"beach.jpg":"https://…"},
 *    "seq":1}
 *   EOF
 *
 * seq is optional and defaults to the newest run in the manifest.
 * epoch_envelope_urls carries a signed link per epoch the run's index names
 * (needed whenever the run carries offloaded files); object_urls carries the
 * level-3 sample, keyed by the object's name in the index. Both are optional
 * on the wire and bounded (BackupStaging::link_map).
 *
 * The shell entry (maintenance_scripts/sysadmin_tools/verify_backup.sh) drives
 * the same engine over a chain an operator downloaded by hand, with a key the
 * operator recovered — the one path that exercises the recovery private key.
 * That request names a directory instead of links, and nothing is fetched,
 * locked or removed except a rehearsal's own scratch tree. It holds a chain
 * key, not the site key, so offloaded files are not proven on this path
 * (VERIFY_OBJECTS=0):
 *
 *   {"artifacts_dir":"/path/to/chain","key_file":"/path/to/chain.key","level":2,
 *    "seq":1,"project":"name"}
 *
 * Output, one key per line:
 *
 *   VERIFY_RESULT=pass|fail|skipped
 *   VERIFY_LEVEL=2|3
 *   VERIFY_RUN=<chain_id>/<seq>
 *   VERIFY_RUN_TIME=<manifest run time, UTC>
 *   VERIFY_ARTIFACTS=<n read>
 *   VERIFY_BYTES=<bytes read>
 *   VERIFY_FILES=<entries listed (2) or files restored (3)>
 *   VERIFY_OBJECTS=<offloaded files proven recoverable: stored, epoch envelope opened>
 *   VERIFY_OBJECT_BYTES=<their bytes in backup storage>
 *   VERIFY_OBJECTS_SAMPLED=<n opened and compared>  (level 3 only)
 *   VERIFY_TABLES=<n>                          (level 3 only)
 *   VERIFY_ROWS=usr_users:<n>,<table>:<n>,…    (level 3 only)
 *   VERIFY_DURATION=<seconds>
 *   VERIFY_REASON=<one line>                   (fail or skipped only)
 *   VERIFY_NEEDS_BYTES=<n>                     (skipped for disk only)
 *   VERIFY_FREE_BYTES=<n>                      (skipped for disk only)
 *
 * Exits 0 on pass or skipped, 1 on fail, 2 on a malformed request (with
 * VERIFY_FAIL: on stderr). A skip is neither a pass nor a failure: the machine
 * could not hold the set (reason `disk`, with both numbers), could not create
 * a throwaway database (`createdb`), or another backup held the locks for too
 * long (`busy`).
 *
 * Validate with `php -l` only — never the file validator (this is a CLI with a
 * run-on-include body).
 *
 * @version 1.2 - offloaded files: epoch_envelope_urls and object_urls in the request, staged through
 *                BackupStaging::fetch_objects after the set and handed to the verifier; the disk
 *                check is repeated with the sample's bytes once the index has been read
 * @version 1.1 - a skip, and a refusal after the request named its run, leave the reason on the
 *                run's history row (message only) instead of no trace; the stamp itself is
 *                BackupVerifier::stamp_history; the chain key is opened before the set is downloaded
 * @version 1.0
 */

// Reject non-CLI access
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

@set_time_limit(0);

/** How long to wait for the backup locks before answering `busy`. */
const VERIFY_LOCK_WAIT_SECONDS = 1200;
const VERIFY_LOCK_POLL_SECONDS = 10;

function verify_backup_refuse($message, $code = 2) {
	// A refusal after the request named its run leaves a trace on that run's
	// row (message only, like a skip): a verify a person started from the
	// Backups page must not vanish without a word.
	$row = $GLOBALS['verify_backup_row'] ?? null;
	if (is_array($row)) {
		try {
			BackupVerifier::note_history($row['chain_id'], $row['seq'], $row['profile'], $message);
		} catch (\Throwable $e) {
			fwrite(STDERR, 'could not note the history row: ' . $e->getMessage() . "\n");
		}
	}
	fwrite(STDERR, 'VERIFY_FAIL: ' . $message . "\n");
	echo "VERIFY_RESULT=fail\n";
	echo 'VERIFY_REASON=' . str_replace(array("\r", "\n"), ' ', $message) . "\n";
	exit($code);
}

/** Print the contract and leave with the exit status it implies. */
function verify_backup_finish(array $result) {
	echo BackupVerifier::format_contract($result);
	exit(($result['result'] ?? '') === BackupVerifier::RESULT_FAIL ? 1 : 0);
}

/** This machine's PostgreSQL, as the platform's own config names it. */
function verify_backup_db() {
	$settings = Globalvars::get_instance();
	return array(
		'user'       => (string)$settings->get_setting('dbusername', true, true),
		'password'   => (string)$settings->get_setting('dbpassword', true, true),
		'connect_db' => (string)$settings->get_setting('dbname', true, true),
		'host'       => 'localhost',
		'port'       => 5432,
	);
}

/**
 * Stamp the result on this machine's own history row for the run, if it has
 * one (BackupVerifier::stamp_history: a pass or a failure in full, a skip as
 * its message only). Never fatal: the proof still stands and is still
 * printed, and the row is a copy of it.
 */
function verify_backup_stamp(array $result, $chain_id, $seq, $profile = null) {
	try {
		BackupVerifier::stamp_history($result, $chain_id, $seq, $profile);
	} catch (\Throwable $e) {
		fwrite(STDERR, 'could not stamp the history row: ' . $e->getMessage() . "\n");
	}
}

/** The newest run this machine's history holds for a chain, or 0. */
function verify_backup_newest_seq($chain_id, $profile) {
	try {
		$rows = new MultiBackupHistory(array('chain_id' => (string)$chain_id, 'profile' => $profile, 'deleted' => false),
			array('bkh_chain_seq' => 'DESC'), 1, 0);
		foreach ($rows as $row) { return (int)$row->get('bkh_chain_seq'); }
	} catch (\Throwable $e) {
		// no history to consult; the note goes to run 0 if it exists
	}
	return 0;
}

/**
 * The shell entry's request: a chain directory and a key the operator already
 * has. Nothing is fetched and nothing is locked; the directory is the
 * operator's and stays. A rehearsal's scratch tree and throwaway database are
 * removed on every exit path, as on the fetching path.
 */
function verify_backup_local(array $config) {
	$accepted = array('artifacts_dir', 'key_file', 'level', 'seq', 'project');
	$unknown = array_diff(array_keys($config), $accepted);
	if ($unknown) {
		sort($unknown);
		verify_backup_refuse('configuration carries unrecognised key(s): ' . implode(', ', $unknown));
	}
	$work = rtrim((string)($config['artifacts_dir'] ?? ''), '/');
	if ($work === '' || !is_dir($work)) {
		verify_backup_refuse("'artifacts_dir' must be a directory holding the chain's manifest and artifacts");
	}
	if (!is_file($work . '/' . BackupChain::MANIFEST_NAME)) {
		verify_backup_refuse('there is no ' . BackupChain::MANIFEST_NAME . ' in ' . $work);
	}
	$key_file = (string)($config['key_file'] ?? '');
	if ($key_file === '' || !is_file($key_file) || !is_readable($key_file)) {
		verify_backup_refuse("'key_file' must name a readable file holding the recovered chain key");
	}
	$level = (int)($config['level'] ?? 0);
	if (!BackupVerifier::is_runnable_level($level)) {
		verify_backup_refuse("'level' must be 2 (open and read) or 3 (rehearse a restore)");
	}
	$seq = (isset($config['seq']) && $config['seq'] !== '' && $config['seq'] !== null) ? (int)$config['seq'] : null;
	if ($seq !== null && ($seq < 0 || $seq > BackupStaging::MAX_SEQ)) {
		verify_backup_refuse('a chain run number must be between 0 and ' . BackupStaging::MAX_SEQ);
	}
	$project = (string)($config['project'] ?? '');

	try {
		$manifest = BackupChain::read($work . '/' . BackupChain::MANIFEST_NAME);
		$plan     = BackupChain::restore_plan($manifest, $seq);
	} catch (\Throwable $e) {
		verify_backup_finish(array('result' => BackupVerifier::RESULT_FAIL, 'level' => $level, 'reason' => $e->getMessage()));
	}
	$chain_id = (string)($manifest['chain_id'] ?? '');

	$GLOBALS['verify_backup_cleanup'] = array('work' => $work . '/scratch', 'db' => null, 'db_name' => '', 'locks' => null);
	register_shutdown_function(function () {
		$c = $GLOBALS['verify_backup_cleanup'] ?? array();
		if (!empty($c['db_name']) && is_array($c['db'] ?? null)) {
			BackupVerifier::drop_database($c['db'], $c['db_name']);
		}
		if (!empty($c['work'])) {
			BackupVerifier::remove_tree($c['work']);
		}
	});

	if ($level === BackupVerifier::LEVEL_REHEARSE) {
		$db = verify_backup_db();
		$GLOBALS['verify_backup_cleanup']['db']      = $db;
		$GLOBALS['verify_backup_cleanup']['db_name'] = BackupVerifier::throwaway_db_name((string)($manifest['slug'] ?? 'site'));
		$result = BackupVerifier::rehearse($work, $manifest, $plan['seq'], $key_file, $db, $project);
		$GLOBALS['verify_backup_cleanup']['db_name'] = '';
	} else {
		$result = BackupVerifier::read_all($work, $manifest, $plan['seq'], $key_file);
	}

	verify_backup_stamp($result, $chain_id, $plan['seq']);
	verify_backup_finish($result);
}

// ── Configuration ───────────────────────────────────────────────────────────

$raw = stream_get_contents(STDIN);
$config = json_decode((string)$raw, true);
unset($raw);

if (is_array($config) && array_key_exists('artifacts_dir', $config)) {
	verify_backup_local($config);   // never returns
}

try {
	$request = BackupStaging::parse_request($config, array('level', 'epoch_envelope_urls', 'object_urls'));
	$envelope_urls = BackupStaging::link_map($request['epoch_envelope_urls'] ?? null, 'epoch_envelope_urls',
		BackupStaging::EPOCH_ID_PATTERN, 64);
	$object_urls   = BackupStaging::link_map($request['object_urls'] ?? null, 'object_urls');
} catch (BackupStagingException $e) {
	verify_backup_refuse($e->getMessage(), $e->getCode());
}
unset($config);

$chain_id      = $request['chain_id'];
$profile       = $request['profile'];
$manifest_url  = $request['manifest_url'];
$artifact_urls = $request['artifact_urls'];
$seq           = $request['seq'];
$level         = isset($request['level']) ? (int)$request['level'] : 0;
unset($request);
if ($object_urls && $level !== BackupVerifier::LEVEL_REHEARSE) {
	verify_backup_refuse('a sample of offloaded files is opened by a rehearsal (level 3) only');
}

// From here a refusal names its run. A seq of null is the newest run in the
// manifest, which is not known yet; the note then goes to the newest row of
// the chain, which is what a person asked to verify.
$GLOBALS['verify_backup_row'] = array('chain_id' => $chain_id, 'profile' => $profile,
	'seq' => ($seq === null) ? verify_backup_newest_seq($chain_id, $profile) : (int)$seq);

if (!BackupVerifier::is_runnable_level($level)) {
	verify_backup_refuse("'level' must be 2 (open and read) or 3 (rehearse a restore)");
}

// ── Where, and under which locks ────────────────────────────────────────────
// verify-<pid> inside the PROFILE's directory — the one whose backup storage the chain
// came from — so a manager-profile verify and a site-profile verify never share
// a directory, and the local sweep finds either where it looks for backups.
try {
	$base        = BackupRunner::output_dir();
	$profile_dir = BackupProfile::output_dir($profile, $base);
} catch (\Throwable $e) {
	verify_backup_refuse($e->getMessage());
}
$work = $profile_dir . '/' . BackupVerifier::WORK_PREFIX . getmypid();

// Same two locks as a backup run, so this never reads a manifest a run is
// rewriting and never downloads gigabytes beside a run that is archiving. A
// run that finds them held skips its tick exactly as it would for another run;
// this side waits, because a verify is dispatched at most every few weeks and
// the run it is waiting for is minutes.
$lock_plan = array('base_dir' => $base, 'output_dir' => $profile_dir);
$locks = false;
$waited = 0;
while (($locks = BackupRunner::take_locks($lock_plan)) === false) {
	if ($waited >= VERIFY_LOCK_WAIT_SECONDS) {
		$busy = array(
			'result' => BackupVerifier::RESULT_SKIPPED,
			'level'  => $level,
			'run'    => $chain_id . '/' . ($seq === null ? '' : (int)$seq),
			'reason' => 'busy: another backup was running on this machine for the whole of the '
				. (int)(VERIFY_LOCK_WAIT_SECONDS / 60) . ' minutes this verify waited',
		);
		verify_backup_stamp($busy, $chain_id, $GLOBALS['verify_backup_row']['seq'], $profile);
		verify_backup_finish($busy);
	}
	sleep(VERIFY_LOCK_POLL_SECONDS);
	$waited += VERIFY_LOCK_POLL_SECONDS;
}

// ── Leave nothing behind, however this ends ─────────────────────────────────
// The working directory (level 3's scratch tree lives under it), the throwaway
// database if one was created, and the locks. Registered before the first byte
// is fetched, and it runs on exit(), on an uncaught exception and on a fatal.
$GLOBALS['verify_backup_cleanup'] = array('work' => $work, 'db' => null, 'db_name' => '', 'locks' => $locks);
register_shutdown_function(function () {
	$c = $GLOBALS['verify_backup_cleanup'] ?? array();
	if (!empty($c['db_name']) && is_array($c['db'] ?? null)) {
		BackupVerifier::drop_database($c['db'], $c['db_name']);
	}
	if (!empty($c['work'])) {
		BackupVerifier::remove_tree($c['work']);
	}
	if (!empty($c['locks'])) {
		BackupRunner::release_locks($c['locks']);
	}
});

// ── Stage the set ───────────────────────────────────────────────────────────
$fetching = '';
try {
	BackupStaging::prepare_workspace($work);
	$manifest = BackupStaging::fetch_manifest($profile, $work, $chain_id, $manifest_url);
	$plan     = BackupStaging::plan($manifest, $seq);

	// The key before the download: a manifest whose envelope this machine's
	// key does not open fails here, on one small file, not after the set.
	$key_file = BackupStaging::write_chain_key($manifest, $work);

	// Disk before download (the whole set, and for a rehearsal the tree and
	// the database it will make), measured where it will land.
	$skip = BackupVerifier::disk_check($manifest, $plan['seq'], $level, $profile_dir);
	if ($skip !== null) {
		verify_backup_stamp($skip, $chain_id, $plan['seq'], $profile);
		verify_backup_finish($skip);
	}

	$on_fetch = function ($what, $name) use (&$fetching) {
		$fetching = ($what === 'fetching') ? $name : '';
	};
	BackupStaging::fetch_artifacts($profile, $work, $chain_id, BackupStaging::wanted($plan),
		$artifact_urls, $plan['seq'], $on_fetch);
	$fetching = '';

	// The run's offloaded files: the index is staged with the set; from it,
	// every epoch envelope it names and — for a rehearsal — the sample the
	// request linked. A run with no index has none, and links for it are
	// a request that does not match the run.
	$objects = null;
	if (!empty($plan['objects']['name'])) {
		$index = BackupObjects::read_index_file($work . '/' . $plan['objects']['name']);
		if ($object_urls) {
			// The sample's bytes were unknown at the first disk check; the set
			// is on disk by now and is not counted twice.
			$skip = BackupVerifier::disk_check($manifest, $plan['seq'], $level, $profile_dir, null,
				BackupVerifier::sample_bytes($index, array_keys($object_urls)), true);
			if ($skip !== null) {
				verify_backup_stamp($skip, $chain_id, $plan['seq'], $profile);
				verify_backup_finish($skip);
			}
		}
		$objects = BackupStaging::fetch_objects($work, $index, $envelope_urls, $object_urls, $on_fetch);
		unset($index);
	} elseif ($object_urls) {
		verify_backup_refuse('the request links offloaded files, but run ' . (int)$plan['seq'] . ' of '
			. $chain_id . ' carries no index of them');
	}
} catch (BackupObjectsException $e) {
	$failed = array(
		'result'   => BackupVerifier::RESULT_FAIL,
		'level'    => $level,
		'run'      => $chain_id . '/' . (int)$plan['seq'],
		'run_time' => BackupVerifier::run_time($manifest, $plan['seq']),
		'reason'   => $e->getMessage(),
	);
	verify_backup_stamp($failed, $chain_id, $plan['seq'], $profile);
	verify_backup_finish($failed);
} catch (BackupStagingException $e) {
	// The set could not be staged, which is a verify failure with the node's own
	// reason. An object retention deleted from under this verify is named as
	// `gone`, so the next pass verifies the newer chain rather than retrying.
	$reason = $e->getMessage();
	if ($fetching !== '' && preg_match('/HTTP 40[34]\b/', $reason)) {
		$reason = 'gone: ' . $fetching . ' is no longer in backup storage (' . $reason . ')';
	}
	$failed = array(
		'result'   => BackupVerifier::RESULT_FAIL,
		'level'    => $level,
		'run'      => $chain_id . '/' . (isset($plan) ? (int)$plan['seq'] : ($seq === null ? '' : (int)$seq)),
		'run_time' => isset($manifest, $plan) ? BackupVerifier::run_time($manifest, $plan['seq']) : '',
		'reason'   => $reason,
	);
	verify_backup_stamp($failed, $chain_id, isset($plan) ? $plan['seq'] : $GLOBALS['verify_backup_row']['seq'], $profile);
	verify_backup_finish($failed);
}

// ── Open and read, or rehearse ──────────────────────────────────────────────
if ($level === BackupVerifier::LEVEL_REHEARSE) {
	$db = verify_backup_db();
	$GLOBALS['verify_backup_cleanup']['db']      = $db;
	$GLOBALS['verify_backup_cleanup']['db_name'] = BackupVerifier::throwaway_db_name((string)($manifest['slug'] ?? 'site'));
	$result = BackupVerifier::rehearse($work, $manifest, $plan['seq'], $key_file, $db, '', $objects);
	$GLOBALS['verify_backup_cleanup']['db_name'] = '';   // rehearse() dropped it on its way out
} else {
	$result = BackupVerifier::read_all($work, $manifest, $plan['seq'], $key_file, $objects);
}

// ── Stamp the run's own history row ─────────────────────────────────────────
verify_backup_stamp($result, $chain_id, $plan['seq'], $profile);

verify_backup_finish($result);
