<?php
/**
 * restore_objects.php — bring a backup's offloaded files home.
 *
 * A backup's archives carry no offloaded file: a blob whose bytes live in the
 * file bucket is on the backup shelf once, encrypted under an epoch key, and
 * named by the run's offloaded-files index (objects-NNNN.json.gz). This runs
 * AFTER the archives and the database are restored and puts those files back
 * where the site expects them — only the ones the file bucket cannot serve
 * (`missing`, the default), or every one (`all`, a site leaving its bucket).
 * Each object is checked against the index before it is decrypted, decrypted
 * into the placement the storage profile computes, checked against its own
 * row (size, and hash where the row records one), and only then is the row
 * set to local. Nothing already on disk is overwritten; nothing in any bucket
 * is deleted. Running it twice finishes what the first run left.
 *
 * Two sources, one engine (BackupObjectRestore):
 *
 *   A downloaded tree — restore_chain.sh --objects DIR, or by hand — in the
 *   shelf's own layout, DIR/{epoch}/envelope.json and DIR/{epoch}/{name}.enc.
 *   Envelopes open with this machine's own config/backup_site_key; for an
 *   epoch it does not open, recover the key with backup_envelope.php and hand
 *   it over as --epoch-key:
 *
 *     php utils/restore_objects.php --index DIR/../objects-0003.json.gz --objects DIR \
 *         [--mode missing|all] [--dry-run] [--epoch-key epoch-20260901_000000=/tmp/k]
 *
 *   A page of presigned links — the restore_objects agent primitive, driven by
 *   a management node. Configuration is JSON on stdin, and only on stdin; the
 *   index is fetched by link and checked against this machine's upload ledger
 *   like every artifact, and every object against the index. A first job with
 *   no object links is a SURVEY: the node answers with the names it would
 *   bring home (RESTORE_OBJECTS_WANT), and the management node signs pages of
 *   links from that answer. No key and no bucket credential arrives on either
 *   shape; a request carrying one is refused.
 *
 *     {"chain_id":"chain-20260912_044520","profile":"manager","seq":3,"mode":"missing",
 *      "index_url":"https://…signed…",
 *      "epoch_envelope_urls":{"epoch-20260901_000000":"https://…"},
 *      "object_urls":{"beach.jpg":"https://…"}}
 *
 * Output, one key per line (RESTORE_OBJECTS_*, BackupObjectRestore::format_contract):
 *
 *   RESTORE_OBJECTS_RESULT=ok|fail
 *   RESTORE_OBJECTS_MODE=missing|all
 *   RESTORE_OBJECTS_RUN=<run the index describes>
 *   RESTORE_OBJECTS_INDEXED=<stored entries in the index>
 *   RESTORE_OBJECTS_NOT_ON_SHELF=<entries the index says never reached the shelf>
 *   RESTORE_OBJECTS_WANTED=<n to bring home>            (survey, dry run, tree)
 *   RESTORE_OBJECTS_EPOCHS=<epoch:n,…>                  (survey, dry run, tree)
 *   RESTORE_OBJECTS_WANT=<name,name,…>                  (survey; capped, MORE=1 when truncated)
 *   RESTORE_OBJECTS_RESTORED=<n placed>                 (page, tree)
 *   RESTORE_OBJECTS_BYTES=<plaintext bytes placed>      (page, tree)
 *   RESTORE_OBJECTS_KEPT=<n already on disk, adopted>   (page, tree)
 *   RESTORE_OBJECTS_SKIPPED=<n served, local or without a row>
 *   RESTORE_OBJECTS_DURATION=<seconds>
 *   RESTORE_OBJECTS_REASON=<one line>                   (fail only)
 *
 * Exits 0 on ok, 1 on a transfer, key, integrity or placement failure, 2 on a
 * request it could not understand (RESTORE_OBJECTS_FAIL: on stderr). A
 * failure stops at the object that failed, named; what was placed before it
 * stays placed and recorded.
 *
 * Validate with `php -l` only — never the file validator (this is a CLI with a
 * run-on-include body).
 *
 * @version 1.0.1 - the tree path's survey is uncapped by bytes as well as by count
 * @version 1.0
 */

// Reject non-CLI access
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/BackupObjectRestore.php'));
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

@set_time_limit(0);

$restore_objects_started = time();

function restore_objects_refuse($message, $code = 2) {
	fwrite(STDERR, 'RESTORE_OBJECTS_FAIL: ' . $message . "\n");
	echo "RESTORE_OBJECTS_RESULT=fail\n";
	echo 'RESTORE_OBJECTS_REASON=' . str_replace(array("\r", "\n"), ' ', $message) . "\n";
	exit((int)$code);
}

/** Print the contract and leave with the exit status it implies. */
function restore_objects_finish(array $result) {
	$result['duration'] = time() - $GLOBALS['restore_objects_started'];
	echo BackupObjectRestore::format_contract($result);
	exit(($result['result'] ?? '') === BackupObjectRestore::RESULT_OK ? 0 : 1);
}

/** A failure after the request was understood: the contract, with the reason, exit 1. */
function restore_objects_fail(array $base, $reason) {
	restore_objects_finish(array_merge($base, array('result' => BackupObjectRestore::RESULT_FAIL, 'reason' => $reason)));
}

function restore_objects_progress($what, $name, $detail = '') {
	echo $what . ' ' . $name . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
}

// ── Arguments (the shell path) ──────────────────────────────────────────────
// Arguments and stdin are two doors to the same tree shape; the agent's link
// shape only ever arrives on stdin.

function restore_objects_args(array $argv) {
	$config = array();
	for ($i = 1; $i < count($argv); $i++) {
		$arg = $argv[$i];
		$value = null;
		if (strpos($arg, '=') !== false && substr($arg, 0, 2) === '--') {
			list($arg, $value) = explode('=', $arg, 2);
		}
		$take = function () use (&$i, $argv, $arg, $value) {
			if ($value !== null) { return $value; }
			if (!isset($argv[$i + 1])) { restore_objects_refuse($arg . ' needs a value'); }
			return $argv[++$i];
		};
		switch ($arg) {
			case '--index':     $config['index'] = $take(); break;
			case '--objects':   $config['objects_dir'] = $take(); break;
			case '--mode':      $config['mode'] = $take(); break;
			case '--dry-run':   $config['dry_run'] = true; break;
			case '--epoch-key':
				$pair = $take();
				$bits = explode('=', $pair, 2);
				if (count($bits) !== 2 || $bits[0] === '' || $bits[1] === '') {
					restore_objects_refuse('--epoch-key takes EPOCH=FILE');
				}
				$config['epoch_keys'][$bits[0]] = $bits[1];
				break;
			case '--help': case '-h':
				echo "Usage: php utils/restore_objects.php --index objects-NNNN.json.gz --objects DIR [--mode missing|all] [--dry-run] [--epoch-key EPOCH=FILE]...\n";
				echo "       php utils/restore_objects.php < request.json   (JSON on stdin: the same keys, or a management node's link request)\n";
				exit(0);
			default:
				restore_objects_refuse('unknown argument ' . $arg);
		}
	}
	return $config;
}

// ── Configuration ───────────────────────────────────────────────────────────

if ($argc > 1) {
	$config = restore_objects_args($argv);
} else {
	$raw = stream_get_contents(STDIN);
	$config = json_decode((string)$raw, true);
	unset($raw);
}

if (is_array($config) && (array_key_exists('index', $config) || array_key_exists('objects_dir', $config))) {
	restore_objects_tree($config);   // never returns
}
restore_objects_links($config);      // never returns

// ── The tree shape ──────────────────────────────────────────────────────────

function restore_objects_tree($config) {
	try {
		$request = BackupObjectRestore::parse_tree_request($config);
	} catch (BackupObjectRestoreException $e) {
		restore_objects_refuse($e->getMessage(), $e->getCode());
	}
	try {
		$index = BackupObjects::read_index_file($request['index']);
	} catch (BackupObjectsException $e) {
		restore_objects_refuse($e->getMessage());
	}
	$base = array('mode' => $request['mode'], 'run' => (string)($index['run'] ?? ''));

	// Everything the index marks stored is a candidate; the tree path has no
	// page to fit, so the survey is not capped.
	$survey = BackupObjectRestore::survey($index, $request['mode'], PHP_INT_MAX, PHP_INT_MAX);
	$base = array_merge($base, array('indexed' => $survey['indexed'], 'not_stored' => $survey['not_stored'],
		'wanted' => $survey['wanted'], 'epochs' => $survey['epochs'],
		'skipped' => $survey['served'] + $survey['local'] + $survey['no_row']));
	if ($request['dry_run']) {
		echo 'Would bring ' . $survey['wanted'] . ' offloaded file' . ($survey['wanted'] === 1 ? '' : 's') . ' home'
			. ($survey['epochs'] ? ' from ' . count($survey['epochs']) . ' epoch' . (count($survey['epochs']) === 1 ? '' : 's') : '') . ".\n";
		restore_objects_finish(array_merge($base, array('result' => BackupObjectRestore::RESULT_OK)));
	}

	$dir = $request['objects_dir'];
	try {
		$keys = BackupObjectRestore::epoch_keys(BackupObjectRestore::subset($index, $survey['want']),
			function ($epoch) use ($dir) { return $dir . '/' . $epoch . '/' . BackupObjects::ENVELOPE_NAME; },
			$request['epoch_keys']);
		$counts = BackupObjectRestore::restore($index, $survey['want'], $request['mode'], $keys,
			BackupObjectRestore::tree_source($dir), 'restore_objects_progress');
	} catch (BackupObjectRestoreException $e) {
		if ($e->getCode() === BackupObjectRestoreException::MALFORMED) { restore_objects_refuse($e->getMessage(), $e->getCode()); }
		restore_objects_fail($base, $e->getMessage());
	} catch (\Throwable $e) {
		restore_objects_fail($base, $e->getMessage());
	}
	unset($base['wanted'], $base['epochs']);
	restore_objects_finish(array_merge($base, $counts, array('result' => BackupObjectRestore::RESULT_OK,
		'skipped' => $counts['skipped'] + $survey['served'] + $survey['local'] + $survey['no_row'])));
}

// ── The link shape ──────────────────────────────────────────────────────────

function restore_objects_links($config) {
	try {
		$request = BackupObjectRestore::parse_link_request($config);
	} catch (BackupObjectRestoreException $e) {
		restore_objects_refuse($e->getMessage(), $e->getCode());
	}
	$base = array('mode' => $request['mode'], 'run' => $request['chain_id'] . '/' . $request['seq']);

	// A private working directory under the backup base, removed on every exit
	// path: it holds the index and, one at a time, an object's ciphertext.
	$work = rtrim(BackupRunner::output_dir(), '/') . '/restore-objects-' . getmypid();
	try {
		BackupStaging::prepare_workspace($work);
	} catch (BackupStagingException $e) {
		restore_objects_refuse($e->getMessage(), $e->getCode());
	}
	register_shutdown_function(function () use ($work) { BackupVerifier::remove_tree($work); });

	try {
		$index = BackupStaging::fetch_index($request['profile'], $work, $request['chain_id'], $request['seq'], $request['index_url']);
	} catch (BackupStagingException $e) {
		restore_objects_fail($base, $e->getMessage());
	}
	$base['run'] = (string)($index['run'] ?? $base['run']);

	// No object links: the survey. Named, capped, and the counts around it.
	if (!$request['object_urls']) {
		$survey = BackupObjectRestore::survey($index, $request['mode']);
		$settled = $survey['served'] + $survey['local'] + $survey['no_row'];
		unset($survey['served'], $survey['local'], $survey['no_row']);
		restore_objects_finish(array_merge($base, $survey, array('result' => BackupObjectRestore::RESULT_OK,
			'skipped' => $settled)));
	}

	// A page: the envelopes of the epochs this page's objects are sealed
	// under, opened with this machine's own key; then each object, fetched,
	// checked, decrypted, placed, recorded.
	$names = array_keys($request['object_urls']);
	$page  = BackupObjectRestore::subset($index, $names);
	try {
		$envelopes = BackupStaging::fetch_envelopes($work, $page, $request['epoch_envelope_urls'], 'restore_objects_progress');
		$keys = BackupObjectRestore::epoch_keys($page, function ($epoch) use ($envelopes) {
			return (string)($envelopes['envelopes'][$epoch] ?? '');
		});
		$counts = BackupObjectRestore::restore($index, $names, $request['mode'], $keys,
			BackupObjectRestore::link_source($work, $request['object_urls'], 'restore_objects_progress'), 'restore_objects_progress');
	} catch (BackupStagingException $e) {
		if ($e->getCode() === BackupStagingException::MALFORMED) { restore_objects_refuse($e->getMessage(), $e->getCode()); }
		restore_objects_fail($base, $e->getMessage());
	} catch (BackupObjectRestoreException $e) {
		if ($e->getCode() === BackupObjectRestoreException::MALFORMED) { restore_objects_refuse($e->getMessage(), $e->getCode()); }
		restore_objects_fail($base, $e->getMessage());
	} catch (\Throwable $e) {
		restore_objects_fail($base, $e->getMessage());
	}
	$stored = count(BackupObjects::index_entries($index));
	$not_stored = count($index['objects'] ?? array()) - $stored;
	restore_objects_finish(array_merge($base, $counts, array('result' => BackupObjectRestore::RESULT_OK,
		'indexed' => $stored, 'not_stored' => $not_stored)));
}
