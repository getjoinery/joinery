<?php
/**
 * bring_back_objects.php — this site brings its offloaded files back from its
 * own backup storage.
 *
 * The file store has lost some offloaded files (the cloud-storage page's
 * daily check says which), and this site backs itself up to a target of its
 * own, so a copy of each is on that shelf. This reads the named run's
 * offloaded-files index from backup storage with the site's own credential, works
 * out which files the file bucket cannot serve, and brings them home a page
 * at a time — each fetched by a link this machine signs for itself, checked
 * against the index, decrypted with the site's own key, placed where the site
 * expects it, checked against its own row, and recorded as local
 * (BackupObjectRestoreLauncher::run). Nothing already on disk is overwritten;
 * nothing in any bucket is deleted. Running it twice finishes what the first
 * run left.
 *
 * The Backups page and the cloud-storage page start it in the background with
 * the request on stdin (only on stdin):
 *
 *     {"chain_id":"chain-20260912_044520","seq":3,"mode":"missing"}
 *
 * From a shell the same request can be given as arguments:
 *
 *     php utils/bring_back_objects.php --chain chain-20260912_044520 --seq 3 [--mode missing|all]
 *
 * `missing` (the default) touches only what the file bucket cannot serve;
 * `all` brings every offloaded file home — a site leaving its bucket. What it
 * did is written to the inventory record both pages read, and printed here
 * as RESTORE_OBJECTS_* lines (BackupObjectRestore::format_contract). Exits 0
 * when it finished, 1 when it stopped on a failure, 2 when the request could
 * not be understood.
 *
 * A site backed up only by a management node has no backup storage credential here;
 * its files come back as a management-node job (Bring them back on the node's
 * Backups tab there). No key and no credential is printed.
 *
 * Validate with `php -l` only — never the file validator (this is a CLI with a
 * run-on-include body).
 *
 * @version 1.0
 */

// Reject non-CLI access
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/BackupObjectRestoreLauncher.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectRestore.php'));

$request = array();
if ($argc > 1) {
	for ($i = 1; $i < $argc; $i++) {
		$arg = $argv[$i];
		$value = ($i + 1 < $argc) ? $argv[$i + 1] : null;
		switch ($arg) {
			case '--chain': $request['chain_id'] = (string)$value; $i++; break;
			case '--seq':   $request['seq'] = (int)$value; $i++; break;
			case '--mode':  $request['mode'] = (string)$value; $i++; break;
			case '--help': case '-h':
				echo "Usage: php utils/bring_back_objects.php --chain chain-YYYYMMDD_HHMMSS --seq N [--mode missing|all]\n";
				echo "       or a JSON request on stdin: {\"chain_id\":\"…\",\"seq\":N,\"mode\":\"missing\"}\n";
				exit(0);
			default:
				fwrite(STDERR, "BRING_BACK_FAIL: unknown argument $arg\n");
				exit(2);
		}
	}
} else {
	$raw = stream_get_contents(STDIN);
	$request = json_decode((string)$raw, true);
	if (!is_array($request)) {
		fwrite(STDERR, "BRING_BACK_FAIL: the request must be JSON on stdin, or --chain/--seq arguments\n");
		exit(2);
	}
}
if (!isset($request['mode']) || $request['mode'] === '') {
	$request['mode'] = BackupObjectRestore::MODE_MISSING;
}
$unknown = array_diff(array_keys($request), array('chain_id', 'seq', 'mode', 'when'));
if ($unknown) {
	fwrite(STDERR, 'BRING_BACK_FAIL: the request carries unrecognised key(s): ' . implode(', ', $unknown) . "\n");
	exit(2);
}

$result = BackupObjectRestoreLauncher::run($request, function ($what, $name, $detail = '') {
	echo $what . ' ' . $name . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
});
echo BackupObjectRestore::format_contract($result);
echo BackupObjectRestore::describe($result) . "\n";
exit(($result['result'] ?? '') === BackupObjectRestore::RESULT_OK ? 0 : 1);
