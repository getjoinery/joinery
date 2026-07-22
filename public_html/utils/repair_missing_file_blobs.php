<?php
/**
 * Re-attach bytes to file records that have none.
 *
 * A file record carries its identity (name, type, owner, visibility) and points
 * at a blob that holds the actual bytes. A record whose blob pointer is NULL is
 * a file the site believes it has and cannot serve: every page that renders it
 * shows a broken image, and nothing about the site otherwise looks wrong.
 *
 * That state is produced by a site clone whose database restore succeeded while
 * its file restore did not — the records arrive, the bytes do not. This utility
 * finds the orphaned records, locates matching bytes in a source directory left
 * behind by such a restore, and ingests them through the ordinary blob path
 * (FileBlob::createFromPath), so the repaired file is indistinguishable from one
 * uploaded normally: honest MIME detection, sha256, dedup against existing
 * blobs, correct public/private store.
 *
 * The source bytes are COPIED, never moved — ingestion consumes what it is
 * given, and in a botched-restore situation the source directory is usually the
 * only surviving copy. Nothing is deleted from it.
 *
 * Matching is by file name (the record's fil_name), searched recursively. The
 * SHALLOWEST match wins, because an uploads tree stores originals at its root
 * and rendered copies in size subdirectories (large/, hero/, thumbnail/,
 * avatar/, og_image/ ...) under the original's own name. Taking the first match
 * a directory walk happens to reach substitutes a resized derivative for the
 * original — same name, fewer pixels, silently. Where only a derivative survives
 * it is still used, and reported as such, since a smaller image beats a broken
 * one. A record with no match anywhere is reported and left untouched.
 *
 * USAGE:
 *   php utils/repair_missing_file_blobs.php --source=/path/to/uploads            # dry run
 *   php utils/repair_missing_file_blobs.php --source=/path/to/uploads --apply    # write
 *
 * Dry run is the default: it reports exactly what --apply would do and changes
 * nothing. Re-running after a successful repair is a no-op, because repaired
 * records no longer have a NULL blob pointer.
 *
 * @version 1.1
 */

if (php_sapi_name() !== 'cli') {
	echo "This utility must be run from the command line.\n";
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));

// ---- arguments -------------------------------------------------------------
$source = null;
$apply  = false;
foreach (array_slice($argv, 1) as $arg) {
	if (strpos($arg, '--source=') === 0) { $source = substr($arg, 9); }
	elseif ($arg === '--apply')          { $apply = true; }
	elseif ($arg === '--help' || $arg === '-h') {
		echo "Usage: php utils/repair_missing_file_blobs.php --source=DIR [--apply]\n";
		exit(0);
	}
}

if ($source === null || $source === '') {
	echo "ERROR: --source=DIR is required (the directory holding the un-restored bytes).\n";
	exit(1);
}
if (!is_dir($source)) {
	echo "ERROR: source directory not found: {$source}\n";
	exit(1);
}

echo "Repair missing file blobs\n";
echo "  source: {$source}\n";
echo "  mode:   " . ($apply ? "APPLY (writes)" : "DRY RUN (no changes)") . "\n\n";

// ---- index the source tree by basename --------------------------------------
// Built once: a per-record directory walk would rescan the tree for every file.
$by_name = array();
$source_root = rtrim($source, '/');
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $entry) {
	if (!$entry->isFile()) { continue; }
	$base = $entry->getFilename();
	$path = $entry->getPathname();
	// Depth below the source root: 0 is an original, deeper is a rendered copy
	// living under a size directory.
	$rel   = ltrim(substr($path, strlen($source_root)), '/');
	$depth = substr_count($rel, '/');
	if (!isset($by_name[$base]) || $depth < $by_name[$base]['depth']) {
		$by_name[$base] = array('path' => $path, 'depth' => $depth, 'rel' => $rel);
	}
}
echo "Indexed " . count($by_name) . " distinct file name(s) in the source tree.\n\n";

// ---- the orphaned records ---------------------------------------------------
$dblink = DbConnector::get_instance()->get_db_link();
$q = $dblink->prepare("SELECT fil_file_id, fil_name, fil_type
                       FROM fil_files
                       WHERE fil_fbb_file_blob_id IS NULL
                         AND fil_delete_time IS NULL
                       ORDER BY fil_file_id");
$q->execute();
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
	echo "No file records are missing bytes. Nothing to do.\n";
	exit(0);
}
echo count($rows) . " file record(s) have no bytes attached.\n\n";

$repaired = 0;
$unmatched = array();
$failed = array();
$derivatives = array();

foreach ($rows as $row) {
	$id   = (int)$row['fil_file_id'];
	$name = (string)$row['fil_name'];
	$mime = (string)$row['fil_type'];

	if (!isset($by_name[$name])) {
		$unmatched[] = "{$id}  {$name}";
		echo "  MISS   [{$id}] {$name} — no matching bytes in the source tree\n";
		continue;
	}
	$src_file = $by_name[$name]['path'];
	$bytes    = filesize($src_file);
	// Anything below the source root is a rendered copy standing in for an
	// original that did not survive — worth saying out loud, since the repaired
	// file will be smaller than the one the record was created for.
	$is_derivative = ($by_name[$name]['depth'] > 0);
	$note = $is_derivative
		? '  [DERIVATIVE from ' . dirname($by_name[$name]['rel']) . '/ — original missing]'
		: '';
	if ($is_derivative) { $derivatives[] = "{$id}  {$name}  (from " . dirname($by_name[$name]['rel']) . "/)"; }

	if (!$apply) {
		echo "  WOULD  [{$id}] {$name} <- {$by_name[$name]['rel']} ({$bytes} bytes){$note}\n";
		$repaired++;
		continue;
	}

	// Ingest a COPY: createFromPath consumes the file it is handed, and the
	// source directory is the only place these bytes still exist.
	$stage_dir = sys_get_temp_dir() . '/filrepair_' . bin2hex(random_bytes(4));
	@mkdir($stage_dir, 0777, true);
	$staged = $stage_dir . '/' . $name;

	try {
		if (!@copy($src_file, $staged)) {
			throw new Exception('could not stage a copy at ' . $staged);
		}
		$file = new File($id, TRUE);
		if (!$file || !$file->key) {
			throw new Exception('file record disappeared between query and load');
		}
		$is_private = !$file->is_public();

		$blob = FileBlob::createFromPath($staged, $mime, $is_private);
		if (!$blob || !$blob->key) {
			throw new Exception('blob creation returned nothing');
		}

		$file->set('fil_fbb_file_blob_id', $blob->key);
		$file->save();

		echo "  OK     [{$id}] {$name} -> blob {$blob->key} ({$bytes} bytes, "
			. ($is_private ? 'private' : 'public') . "){$note}\n";
		$repaired++;
	} catch (Throwable $e) {
		$failed[] = "{$id}  {$name}: " . $e->getMessage();
		echo "  FAIL   [{$id}] {$name} — " . $e->getMessage() . "\n";
	} finally {
		if (is_file($staged)) { @unlink($staged); }
		@rmdir($stage_dir);
	}
}

// ---- summary ----------------------------------------------------------------
echo "\n";
echo "----------------------------------------\n";
echo ($apply ? "Repaired:  " : "Would repair: ") . $repaired . "\n";
echo "Unmatched: " . count($unmatched) . "\n";
echo "Failed:    " . count($failed) . "\n";
if ($derivatives) {
	echo "\nRepaired from a rendered copy because the original was missing\n"
	   . "(these files are now smaller than the record was created for):\n";
	foreach ($derivatives as $d) { echo "  {$d}\n"; }
}
if ($unmatched) {
	echo "\nRecords with no bytes found in the source tree:\n";
	foreach ($unmatched as $u) { echo "  {$u}\n"; }
}
if ($failed) {
	echo "\nRecords that failed to repair:\n";
	foreach ($failed as $f) { echo "  {$f}\n"; }
}
if (!$apply) {
	echo "\nDry run — nothing was changed. Re-run with --apply to write.\n";
}
exit(count($failed) > 0 ? 1 : 0);
