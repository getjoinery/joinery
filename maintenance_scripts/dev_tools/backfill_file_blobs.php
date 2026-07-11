<?php
/**
 * backfill_file_blobs.php — one-time backfill of the file-blob layer.
 *
 * Every fil_files row predates fbb_file_blobs and carries its physical state
 * inline (fil_storage_driver + the bytes at fil_name). This script mints one
 * FileBlob per file and repoints the file at it:
 *
 *   fbb_stored_name    = fil_name
 *   fbb_is_private     = !File::is_public()
 *   fbb_storage_driver = the old fil_storage_driver ('local' | 'cloud')
 *   fbb_reference_count= 1
 *   fbb_size_bytes     = local: filesize(); cloud: S3 HeadObject (no download)
 *   fbb_sha256         = local: hash_file(); cloud: NULL (hashed lazily later)
 *   fbb_mime_type      = fil_type
 *
 * then sets fil_fbb_file_blob_id. Idempotent: only rows with a NULL
 * fil_fbb_file_blob_id are processed, so a re-run resumes. Two files that share
 * a fil_name (and therefore the same bytes on disk) share one blob. Run BEFORE
 * the fil_* storage columns are dropped.
 *
 * Usage: php maintenance_scripts/dev_tools/backfill_file_blobs.php [--batch=N] [--dry-run]
 *
 * @version 1.0.1
 */

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "This script must be run from the command line.\n");
	exit(1);
}

$bootstrap_path = __DIR__ . '/../../public_html/includes/PathHelper.php';
if (!file_exists($bootstrap_path)) {
	fwrite(STDERR, "ERROR: Cannot find PathHelper.php at: $bootstrap_path\n");
	exit(1);
}
require_once($bootstrap_path);
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));

// --- args ---
$BATCH = 200;
$DRY = false;
foreach (array_slice($argv, 1) as $a) {
	if (preg_match('/^--batch=(\d+)$/', $a, $m)) { $BATCH = max(1, (int)$m[1]); }
	elseif ($a === '--dry-run') { $DRY = true; }
}

$settings = Globalvars::get_instance();
$upload_dir = $settings->get_setting('upload_dir');
$fast_dir   = dirname($upload_dir) . '/static_files/uploads';
$dblink = DbConnector::get_instance()->get_db_link();

// Confirm the legacy column is still present (backfill must run before the drop).
try {
	$dblink->query("SELECT fil_storage_driver FROM fil_files LIMIT 0");
} catch (PDOException $e) {
	fwrite(STDERR, "ERROR: fil_storage_driver is already gone — run the backfill BEFORE dropping the legacy columns.\n");
	exit(1);
}

/** Locate a local original's bytes: fast-serve dir (public) or upload dir (restricted). */
function locate_local($stored_name, $fast_dir, $upload_dir) {
	foreach (array($fast_dir . '/' . $stored_name, $upload_dir . '/' . $stored_name) as $p) {
		if (is_file($p)) return $p;
	}
	return null;
}

$total = 0; $created = 0; $shared = 0; $missing = 0; $cloud = 0; $errors = 0;

echo "Backfilling file blobs (batch=$BATCH" . ($DRY ? ", DRY RUN" : "") . ")...\n";

while (true) {
	$q = $dblink->prepare(
		"SELECT fil_file_id, fil_name, fil_type, fil_storage_driver
		 FROM fil_files
		 WHERE fil_fbb_file_blob_id IS NULL
		 ORDER BY fil_file_id ASC
		 LIMIT :lim");
	$q->bindValue(':lim', $BATCH, PDO::PARAM_INT);
	$q->execute();
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	if (empty($rows)) {
		break;
	}

	foreach ($rows as $row) {
		$total++;
		$id = (int)$row['fil_file_id'];
		$stored_name = (string)$row['fil_name'];
		$driver = ($row['fil_storage_driver'] === null || $row['fil_storage_driver'] === '')
			? 'local' : (string)$row['fil_storage_driver'];
		$mime = $row['fil_type'];

		try {
			$file = new File($id, true);
			if (!$file->key) { continue; }
			$is_private = !$file->is_public();

			if ($DRY) {
				echo sprintf("  would blob fil=%d name=%s driver=%s private=%s\n",
					$id, $stored_name, $driver, $is_private ? 'y' : 'n');
				continue;
			}

			// Two files sharing a stored name share the same bytes → share one blob.
			$existing_id = null;
			$eq = $dblink->prepare("SELECT fbb_file_blob_id FROM fbb_file_blobs WHERE fbb_stored_name = ? LIMIT 1");
			$eq->execute([$stored_name]);
			$found = $eq->fetchColumn();
			if ($found !== false) {
				$existing_id = (int)$found;
			}

			if ($existing_id !== null) {
				FileBlob::retain($existing_id);
				$blob_id = $existing_id;
				// Fail-safe visibility: two files that share a stored name share one
				// physical byte set, so they must share one visibility class. If any
				// referrer is private, the whole blob must be private — never let a
				// public-first ordering leave a restricted file's bytes in the
				// world-served dir (or push them to the public bucket).
				if ($is_private) {
					$existing = new FileBlob($existing_id, true);
					if ($existing->key && !$existing->is_private_bool()) {
						$existing->flipVisibility(true);
					}
				}
				$shared++;
			} else {
				if ($driver === 'cloud') {
					$cloud++;
					$cdrv = CloudStorageDriverFactory::forVisibilityWithFallback($is_private ? 'private' : 'public');
					$size = ($cdrv && method_exists($cdrv, 'size')) ? $cdrv->size($stored_name) : null;
					$size = ($size === null) ? 0 : (int)$size;
					$sha = null;
				} else {
					$path = locate_local($stored_name, $fast_dir, $upload_dir);
					if ($path !== null) {
						$size = filesize($path);
						$size = ($size === false) ? 0 : (int)$size;
						$sha = @hash_file('sha256', $path);
						if ($sha === false) { $sha = null; }
					} else {
						$missing++;
						$size = 0;
						$sha = null;
						error_log('backfill_file_blobs: local bytes missing for fil=' . $id . ' name=' . $stored_name);
					}
				}

				$blob = new FileBlob(NULL);
				$blob->set('fbb_stored_name', $stored_name);
				$blob->set('fbb_size_bytes', $size);
				$blob->set('fbb_sha256', $sha);
				$blob->set('fbb_mime_type', ($mime === null ? null : substr((string)$mime, 0, 128)));
				$blob->set('fbb_is_private', $is_private ? true : false);
				$blob->set('fbb_reference_count', 1);
				$blob->set('fbb_storage_driver', $driver);
				$blob->save();
				$blob_id = (int)$blob->key;
				$created++;
			}

			$upd = $dblink->prepare("UPDATE fil_files SET fil_fbb_file_blob_id = ? WHERE fil_file_id = ?");
			$upd->execute([$blob_id, $id]);
		} catch (Exception $e) {
			$errors++;
			error_log('backfill_file_blobs: fil=' . $id . ' failed: ' . $e->getMessage());
			fwrite(STDERR, "  ERROR fil=$id: " . $e->getMessage() . "\n");
		}
	}

	echo "  ...processed $total so far\n";
	if ($DRY) {
		break; // dry run doesn't advance the NULL cursor; one pass is enough
	}
}

echo "\nDone.\n";
echo "  files seen:        $total\n";
echo "  blobs created:     $created\n";
echo "  shared existing:   $shared\n";
echo "  cloud rows:        $cloud\n";
echo "  local bytes missing: $missing\n";
echo "  errors:            $errors\n";

exit($errors > 0 ? 1 : 0);
