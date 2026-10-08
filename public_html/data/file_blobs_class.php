<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class FileBlobException extends SystemBaseException {}

/**
 * FileBlob — the physical unit of stored bytes, refcounted and shared.
 *
 * A File (fil_files) is the logical file users see: identity, ownership,
 * visibility. A FileBlob is the bytes underneath it — the stored name on disk /
 * in the bucket, size, content hash, storage driver, and offload counters. Many
 * files can point at one blob (dedup, and — once Drive lands — versions), so the
 * blob carries a reference count and is deleted only when the last file lets go.
 *
 * Everything physical lives here: where the bytes sit (fast-serve dir vs
 * restricted dir; the verified-private bucket for private bytes only), how they are read back,
 * resized, moved between visibility classes, offloaded, and reclaimed. File
 * delegates every byte operation to its blob; nothing here knows about
 * permissions, which are a File concern.
 *
 * Visibility is a physical placement, so it is a blob property (fbb_is_private),
 * but it is DERIVED from the referencing files: the invariant is that every file
 * pointing at a blob is in the same visibility class. Dedup scoping and the
 * flip / copy-on-write split in File::move_to_correct_directory() maintain it.
 *
 * @version 1.4.0 - an offloaded blob records its file store target and the full key of its original
 *                  (fbb_bkt_backup_target_id, fbb_remote_key); every cloud read, write and delete follows
 *                  the row, and a variant's key sits beside the original's (specs/storage_targets.md WP6)
 * @version 1.3.1 - a transient decode refusal (the site out of memory just then) is not recorded
 * @version 1.3.0 - one decode per resize: ImageDecoder opens the original once, shrunk and upright,
 *                  every size is cut from it under one ImageWorkLock hold, and a decode the
 *                  ceiling refuses is recorded in fbb_variant_refused (specs/image_decode_memory.md)
 * @version 1.2.4 - every resize decodes under ImageWorkLock, one image at a time on the site
 *                  (specs/multi_tenant_docker_hosts.md WP2)
 * @version 1.2.3 - fbb_sync_last_error: why the last offload attempt did not move the blob
 * @version 1.2.2 - one private store: the driver is resolved with no visibility; a cloud blob is always
 *                  a private blob, and flipping one public pulls its bytes home before the record flips
 * @version 1.2.1 - a cloud row can hold local bytes while it waits for a backup storage, so
 *                  reclaiming one deletes its bucket bytes AND unlinks its local paths; a blob
 *                  permanently deleted while waiting leaves nothing behind
 * @version 1.2.0
 */
class FileBlob extends SystemBase {
	public static $prefix = 'fbb';
	public static $tablename = 'fbb_file_blobs';
	public static $pkey_column = 'fbb_file_blob_id';

	// Physical storage: not user-facing, not API-exposed, not AI-readable. A
	// blob never soft-deletes — release() reclaims it directly at refcount 0.

	// A file store holding a blob's objects is refused deletion
	// (BackupTarget::delete_refusal()); a target removed for good under a
	// blob would leave the row naming nothing, so that is refused too.
	protected static $foreign_key_actions = array(
		'fbb_bkt_backup_target_id' => array('action' => 'prevent',
			'message' => 'offloaded files are still stored in this file store; move them or pull them back first'),
	);

	public static $field_specifications = array(
		'fbb_file_blob_id'      => array('type'=>'int8','is_nullable'=>false,'serial'=>true),
		'fbb_stored_name'       => array('type'=>'varchar(255)','is_nullable'=>false,'required'=>true,'unique'=>true),
		'fbb_size_bytes'        => array('type'=>'int8','is_nullable'=>false,'required'=>true),
		'fbb_sha256'            => array('type'=>'varchar(64)','is_nullable'=>true,'index'=>true),
		'fbb_mime_type'         => array('type'=>'varchar(128)','is_nullable'=>true),
		'fbb_is_private'        => array('type'=>'bool','is_nullable'=>false,'default'=>'false'),
		'fbb_reference_count'   => array('type'=>'int4','is_nullable'=>false,'default'=>1),
		'fbb_storage_driver'    => array('type'=>'varchar(32)','is_nullable'=>false,'default'=>'local'),
		// Where an offloaded blob's objects are: the file store target, and the
		// full key its original was written under. Its variants sit beside it
		// (remote_key_for()). Null while the blob is local.
		'fbb_bkt_backup_target_id' => array('type'=>'int8','is_nullable'=>true,'index'=>true),
		'fbb_remote_key'        => array('type'=>'varchar(1024)','is_nullable'=>true),
		// Size key written by store_encrypted_variant (a ciphertext blob's
		// client-produced thumbnail). Durable variant inventory: cloud-side
		// lifecycle ops can't scan a disk, and the value must survive later
		// image-size-registry changes. Null for every other blob.
		'fbb_encrypted_variant_key' => array('type'=>'varchar(32)','is_nullable'=>true),
		'fbb_sync_failed_count' => array('type'=>'int4','is_nullable'=>false,'default'=>0,'zero_on_create'=>true),
		'fbb_sync_last_attempt' => array('type'=>'timestamp(6)','is_nullable'=>true),
		'fbb_sync_last_error'   => array('type'=>'varchar(255)','is_nullable'=>true),
		// Why the last resize was refused (the decode would cost more than
		// image_decode_max_mb), so a thumbnail request does not try again on
		// every view. Null once a resize succeeds or regenerate clears it.
		'fbb_variant_refused'   => array('type'=>'varchar(255)','is_nullable'=>true),
		'fbb_create_time'       => array('type'=>'timestamp(6)','is_nullable'=>false,'default'=>'now()'),
	);

	// ------------------------------------------------------------------
	// Placement — which directory a blob's bytes live in, by visibility.
	// Public bytes go to the fast-serve dir (served directly by Apache);
	// private bytes stay in the restricted upload dir (served only via
	// serve.php's gate). Mirrors File's original get_fast_serve_dir().
	// ------------------------------------------------------------------

	private static function fast_serve_dir() {
		$settings = Globalvars::get_instance();
		return dirname($settings->get_setting('upload_dir')) . '/static_files/uploads';
	}

	private static function restricted_dir() {
		return Globalvars::get_instance()->get_setting('upload_dir');
	}

	/** The local dir this blob's bytes belong in given its visibility. */
	private function local_dir() {
		return $this->is_private_bool() ? self::restricted_dir() : self::fast_serve_dir();
	}

	/**
	 * Interpret fbb_is_private robustly across PDO representations (native bool,
	 * pg 't'/'f', the declared string default on a not-yet-reloaded row).
	 */
	public function is_private_bool() {
		$v = $this->get('fbb_is_private');
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	/**
	 * Is this a raster image the platform decodes and resizes? Keyed to the same
	 * allowlist File uses for inline serving (INLINE_SAFE_TYPES) — never "starts
	 * with image/", so SVG is a plain file with no variants.
	 */
	public function is_image() {
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		return File::is_inline_safe_type($this->get('fbb_mime_type'));
	}

	/**
	 * Size keys that may hold a variant for this blob (original excluded) — the
	 * single source of truth every lifecycle path enumerates variants from
	 * (offload, cloud delete, splitCopy, visibility move, pull-back). Images may
	 * carry every registered size; a ciphertext blob carries the one slot
	 * store_encrypted_variant recorded (its MIME is octet-stream, so is_image()
	 * alone would hide it).
	 */
	public function variant_size_keys() {
		$keys = array();
		if ($this->is_image()) {
			require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
			foreach (ImageSizeRegistry::get_sizes() as $size_key => $cfg) {
				$keys[] = $size_key;
			}
		}
		$enc = (string)$this->get('fbb_encrypted_variant_key');
		if ($enc !== '' && !in_array($enc, $keys, true)) {
			$keys[] = $enc;
		}
		return $keys;
	}

	/**
	 * The relative name a size variant is offloaded under, inside the file
	 * store's folder: 'original' → "<stored_name>"; otherwise
	 * "<size>/<stored_name>". Variants are shared by every file referencing
	 * this blob.
	 */
	public function remote_name_for($size_key = 'original') {
		$name = $this->get('fbb_stored_name');
		return $size_key === 'original' ? $name : $size_key . '/' . $name;
	}

	/**
	 * The full key a size variant of this offloaded blob is at, in the store
	 * the row names: the recorded key for the original, and beside it for a
	 * variant. Read from the row, never from the store chosen now.
	 *
	 * @throws FileBlobException when the blob records no key (it is not offloaded)
	 */
	public function remote_key_for($size_key = 'original') {
		$recorded = (string)$this->get('fbb_remote_key');
		if ($recorded === '') {
			throw new FileBlobException('Blob ' . $this->key . ' records no file store key.');
		}
		return self::key_beside($recorded, (string)$this->get('fbb_stored_name'), (string)$size_key);
	}

	/**
	 * A variant's key beside its original's: "{folder}/{name}" →
	 * "{folder}/{size}/{name}". Pure.
	 */
	public static function key_beside(string $original_key, string $name, string $size_key): string {
		if ($size_key === 'original') {
			return $original_key;
		}
		if ($name === '' || substr($original_key, -strlen($name)) !== $name) {
			throw new FileBlobException('The recorded key ' . $original_key . ' does not end in the stored name ' . $name . '.');
		}
		return substr($original_key, 0, strlen($original_key) - strlen($name)) . $size_key . '/' . $name;
	}

	/**
	 * On-disk path for a size variant. Checks the fast-serve dir then the
	 * restricted dir (a blob's bytes may sit in either during a visibility flip
	 * window), falling back to the expected path in the blob's own class dir.
	 */
	public function filesystem_path($size_key = 'original') {
		$name = $this->get('fbb_stored_name');
		foreach (array(self::fast_serve_dir(), self::restricted_dir()) as $dir) {
			$path = ($size_key === 'original') ? $dir . '/' . $name : $dir . '/' . $size_key . '/' . $name;
			if (file_exists($path)) {
				return $path;
			}
		}
		$dir = $this->local_dir();
		return ($size_key === 'original') ? $dir . '/' . $name : $dir . '/' . $size_key . '/' . $name;
	}

	/**
	 * Raw bytes for a size variant, resolving local disk or cloud. Returns null
	 * when unreadable. Does NOT authorize — File's gate runs first.
	 */
	public function read_bytes($size_key = 'original') {
		if ($this->get('fbb_storage_driver') === 'cloud') {
			$driver = $this->_cloud_driver();
			if (!$driver) {
				return null;
			}
			$tmp = tempnam(sys_get_temp_dir(), 'fbb_read_');
			if ($tmp === false) {
				return null;
			}
			try {
				$driver->get($this->remote_key_for($size_key), $tmp);
				$bytes = file_get_contents($tmp);
				@unlink($tmp);
				return $bytes === false ? null : $bytes;
			} catch (Exception $e) {
				@unlink($tmp);
				error_log('FileBlob::read_bytes cloud GET failed fbb=' . $this->key . ': ' . $e->getMessage());
				return null;
			}
		}

		$path = $this->filesystem_path($size_key);
		if (!file_exists($path)) {
			return null;
		}
		$bytes = file_get_contents($path);
		return $bytes === false ? null : $bytes;
	}

	// ------------------------------------------------------------------
	// Cloud driver resolution — the one private store. Only a private blob
	// ever reaches it; a cloud blob's bytes are read back through the driver
	// whether the store is enabled, paused or draining.
	// ------------------------------------------------------------------

	/** The driver for the file store this blob's record names, or null. */
	public function cloud_driver() {
		return CloudStorageDriverFactory::forTarget((int)$this->get('fbb_bkt_backup_target_id'));
	}

	private function _cloud_driver() {
		return $this->cloud_driver();
	}

	// ==================================================================
	// Ingestion — the one byte-write path.
	// ==================================================================

	/**
	 * Create (or dedup onto) a blob from bytes already staged at $src_path.
	 *
	 * Hashes the bytes and looks for a live blob with the same
	 * (sha256, size, is_private): a hit retains that blob and discards the new
	 * bytes; a miss moves the bytes into the correct visibility dir under a
	 * collision-free stored name, detects the honest MIME type, and inserts.
	 *
	 * The caller owns choosing $is_private (from the file's restriction columns).
	 * On a miss the source file is consumed (moved into place); on a hit it is
	 * unlinked. Callers routed through File::createFromUpload() stage the source
	 * under the file's minted name, so a fresh blob's stored_name equals the
	 * file's fil_name.
	 *
	 * @param string $src_path   staged bytes to ingest (consumed)
	 * @param string $mime        caller's MIME hint (fallback if detection fails)
	 * @param bool   $is_private  target visibility class
	 * @return FileBlob the retained-or-created, loaded blob
	 * @throws FileBlobException when the bytes cannot be placed
	 */
	public static function createFromPath($src_path, $mime, $is_private) {
		if (!is_file($src_path)) {
			throw new FileBlobException('createFromPath: source not found: ' . $src_path);
		}
		$is_private = $is_private ? true : false;
		$size = filesize($src_path);
		$sha  = @hash_file('sha256', $src_path);

		// Dedup: identical bytes already stored in the same visibility class. The
		// retain is conditional — if the candidate blob was reclaimed to zero
		// between the lookup and here, retain() returns false and we fall through
		// to mint a fresh blob from the still-staged source (never link an empty
		// blob and discard the bytes).
		$existing = self::_find_dedup($sha, $size, $is_private);
		if ($existing && self::retain($existing->key)) {
			@unlink($src_path);
			return new self($existing->key, true); // reload for the fresh refcount
		}

		// Miss — detect the honest type from the bytes (the caller's $mime is a
		// spoofable fallback), then move the bytes into the correct dir.
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		$detected    = File::detect_mime_file($src_path);
		$stored_mime = ($detected !== null) ? $detected : (string)$mime;

		$candidate = self::_sanitize_name(basename($src_path));
		$stored_name = (!self::stored_name_exists($candidate) && !self::_on_disk($candidate))
			? $candidate
			: self::_unique_stored_name($candidate);

		$dir = $is_private ? self::restricted_dir() : self::fast_serve_dir();
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		if (!$is_private) {
			self::_ensure_fast_htaccess($dir);
		}
		$target = $dir . '/' . $stored_name;
		if (!@rename($src_path, $target)) {
			// Cross-device staging → copy then drop the source.
			if (!@copy($src_path, $target)) {
				throw new FileBlobException('createFromPath: cannot place bytes at ' . $target);
			}
			@unlink($src_path);
		}
		@chmod($target, 0666);

		$blob = new self(NULL);
		$blob->set('fbb_stored_name', $stored_name);
		$blob->set('fbb_size_bytes', ($size === false ? 0 : $size));
		$blob->set('fbb_sha256', ($sha === false) ? null : $sha);
		$blob->set('fbb_mime_type', substr($stored_mime, 0, 128));
		$blob->set('fbb_is_private', $is_private);
		$blob->set('fbb_reference_count', 1);
		$blob->set('fbb_storage_driver', 'local');
		$blob->save();
		$blob->load();
		return $blob;
	}

	/**
	 * Public dedup lookup for callers that already hold a content hash (Drive's
	 * upload_init short-circuit): a live blob with matching (sha256, size,
	 * visibility), or null. Does NOT retain — the caller retains on use.
	 *
	 * A client-claimed hash is not proof of possession, so when
	 * $possessed_by_user_id is given the match is restricted to blobs that user
	 * already references through their own files or file versions — a foreign
	 * hash+size matches nothing (no content disclosure, no existence oracle).
	 * Only a caller that hashed the bytes itself may pass 0.
	 */
	public static function find_dedup($sha, $size, $is_private, $possessed_by_user_id = 0) {
		return self::_find_dedup($sha, $size, $is_private ? true : false, (int)$possessed_by_user_id);
	}

	/**
	 * Dedup lookup: a live blob (refcount > 0) with matching hash, size and
	 * visibility. Only non-null hashes ever match — a cloud-backfilled row with
	 * a null hash simply never dedups, the safe default.
	 */
	private static function _find_dedup($sha, $size, $is_private, $possessed_by_user_id = 0) {
		if ($sha === null || $sha === false || $sha === '' || $size === false) {
			return null;
		}
		$possession = '';
		if ($possessed_by_user_id > 0) {
			$possession =
				" AND (EXISTS (SELECT 1 FROM fil_files pf
			                    WHERE pf.fil_fbb_file_blob_id = fbb_file_blob_id
			                      AND pf.fil_usr_user_id = :possessor)
			       OR EXISTS (SELECT 1 FROM fvr_file_versions pv
			                    JOIN fil_files pvf ON pvf.fil_file_id = pv.fvr_fil_file_id
			                   WHERE pv.fvr_fbb_file_blob_id = fbb_file_blob_id
			                     AND pvf.fil_usr_user_id = :possessor))";
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT fbb_file_blob_id FROM fbb_file_blobs
			 WHERE fbb_sha256 = :sha AND fbb_size_bytes = :size AND fbb_is_private = :priv
			   AND fbb_reference_count > 0" . $possession . "
			 ORDER BY fbb_file_blob_id ASC LIMIT 1");
		$q->bindValue(':sha', $sha, PDO::PARAM_STR);
		$q->bindValue(':size', $size, PDO::PARAM_INT);
		$q->bindValue(':priv', $is_private, PDO::PARAM_BOOL);
		if ($possessed_by_user_id > 0) {
			$q->bindValue(':possessor', $possessed_by_user_id, PDO::PARAM_INT);
		}
		$q->execute();
		$id = $q->fetchColumn();
		return ($id === false) ? null : new self((int)$id, true);
	}

	// ==================================================================
	// Reference counting — the lifecycle owner (File delegates here).
	// ==================================================================

	/**
	 * Increment the reference count (a new file pointing at this blob). The
	 * increment is conditional on the blob still being live (refcount > 0): a
	 * blob that dropped to zero is being reclaimed and must never be revived, so
	 * a caller that lost that race gets FALSE and treats it as a dedup miss.
	 *
	 * @return bool TRUE if the reference was taken; FALSE if the blob is gone or
	 *              already at refcount 0 (dying).
	 */
	public static function retain($blob_id) {
		$blob_id = (int)$blob_id;
		if ($blob_id <= 0) {
			return false;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$own_tx = !$dblink->inTransaction();
		if ($own_tx) {
			$dblink->beginTransaction();
		}
		try {
			$q = $dblink->prepare(
				"UPDATE fbb_file_blobs SET fbb_reference_count = fbb_reference_count + 1
				 WHERE fbb_file_blob_id = ? AND fbb_reference_count > 0");
			$q->execute([$blob_id]);
			$took = ($q->rowCount() === 1);
			if ($own_tx) {
				$dblink->commit();
			}
			return $took;
		} catch (Exception $e) {
			if ($own_tx && $dblink->inTransaction()) {
				$dblink->rollBack();
			}
			throw $e;
		}
	}

	/** @var int[] blob ids whose at-zero physical reclaim waits for a foreign transaction to commit. */
	private static $deferred_reclaims = array();
	private static $deferred_hook_registered = false;

	/**
	 * Decrement the reference count; at zero, reclaim the physical bytes (+ every
	 * image variant) and the row. The at-zero byte deletion takes the offload
	 * engine's per-row advisory lock first so it never unlinks bytes the engine
	 * is mid-push/read.
	 *
	 * The physical reclaim is irreversible (an unlink cannot be rolled back), so
	 * it must run only AFTER the decrement is durable. When release() owns its
	 * transaction it commits and reclaims inline. When it runs inside a caller's
	 * open transaction, the decrement is not yet durable — a caller rollback would
	 * restore the reference — so the reclaim is deferred to a post-commit flush
	 * (register_shutdown, which runs after any request-scope transaction resolves)
	 * and each deferred reclaim re-checks the committed refcount under the lock, so
	 * a rolled-back decrement simply leaves the bytes intact.
	 */
	public static function release($blob_id) {
		$blob_id = (int)$blob_id;
		if ($blob_id <= 0) {
			return;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$own_tx = !$dblink->inTransaction();
		if ($own_tx) {
			$dblink->beginTransaction();
		}
		$now_zero = false;
		try {
			$q = $dblink->prepare(
				"UPDATE fbb_file_blobs SET fbb_reference_count = GREATEST(fbb_reference_count - 1, 0)
				 WHERE fbb_file_blob_id = ? RETURNING fbb_reference_count");
			$q->execute([$blob_id]);
			$new = $q->fetchColumn();
			if ($own_tx) {
				$dblink->commit();
			}
			$now_zero = ($new !== false && (int)$new === 0);
		} catch (Exception $e) {
			if ($own_tx && $dblink->inTransaction()) {
				$dblink->rollBack();
			}
			throw $e;
		}
		if ($now_zero) {
			if ($own_tx) {
				self::_reclaim($blob_id);
			} else {
				self::_defer_reclaim($blob_id);
			}
		}
	}

	/** Queue an at-zero reclaim to run once the caller's transaction has committed. */
	private static function _defer_reclaim($blob_id) {
		self::$deferred_reclaims[] = (int)$blob_id;
		if (!self::$deferred_hook_registered) {
			self::$deferred_hook_registered = true;
			register_shutdown_function(array(__CLASS__, 'flushDeferredReclaims'));
		}
	}

	/**
	 * Reclaim every blob queued while a foreign transaction was open. Safe to call
	 * at any time (and it runs at request shutdown): _reclaim re-reads the
	 * committed refcount under the advisory lock, so a blob whose decrement was
	 * rolled back (refcount back above zero) is left untouched.
	 */
	public static function flushDeferredReclaims() {
		if (empty(self::$deferred_reclaims)) {
			return;
		}
		$ids = array_unique(self::$deferred_reclaims);
		self::$deferred_reclaims = array();
		foreach ($ids as $id) {
			try {
				self::_reclaim($id);
			} catch (Throwable $e) {
				error_log('FileBlob deferred reclaim failed for fbb=' . $id . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * Delete the physical bytes + variants + row for a blob that just hit
	 * refcount 0. Guards against a concurrent CloudOffloadEngine push of the same
	 * blob by taking the engine's per-row advisory lock (namespace -42), and
	 * re-reads the committed refcount under it so a blob revived by a concurrent
	 * dedup retain() is left intact.
	 */
	private static function _reclaim($blob_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		// Block on the offload engine's per-row lock (namespace -42) — never delete
		// bytes without holding it, or a concurrent push could read a half-unlinked
		// original. The engine holds the lock only for one row's push cycle, so the
		// wait is bounded; and while we hold it the engine's pg_try_advisory_lock
		// skips this row.
		$lock = $dblink->prepare("SELECT pg_advisory_lock(:k1, :k2)");
		$lock->execute([':k1' => -42, ':k2' => $blob_id]);
		try {
			$blob = new self($blob_id, true);
			if (!$blob->key) {
				return; // already reclaimed
			}
			if ((int)$blob->get('fbb_reference_count') > 0) {
				return; // revived by a concurrent retain(); keep the bytes
			}
			if ($blob->get('fbb_storage_driver') === 'cloud') {
				$blob->_delete_cloud_bytes();
				// A cloud row may still hold local bytes: offload keeps them
				// until every enabled backup profile's backup storage has the object.
				$blob->_unlink_local_paths();
			} else {
				$blob->_delete_local_bytes();
			}
			$del = $dblink->prepare(
				"DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ? AND fbb_reference_count = 0");
			$del->execute([$blob_id]);
		} finally {
			$unlock = $dblink->prepare("SELECT pg_advisory_unlock(:k1, :k2)");
			$unlock->execute([':k1' => -42, ':k2' => $blob_id]);
		}
	}

	private function _delete_local_bytes() {
		$path = $this->filesystem_path('original');
		if (is_file($path)) {
			@unlink($path);
		}
		$this->delete_resized('all');
	}

	/**
	 * Unlink whatever local files this blob's name occupies — original and
	 * every variant, in either placement directory — without touching the
	 * bucket. delete_resized() on a cloud row deletes bucket variants, which
	 * is not this.
	 */
	private function _unlink_local_paths() {
		$name = $this->get('fbb_stored_name');
		foreach (array(self::fast_serve_dir(), self::restricted_dir()) as $dir) {
			$candidates = array($dir . '/' . $name);
			foreach ($this->variant_size_keys() as $size_key) {
				$candidates[] = $dir . '/' . $size_key . '/' . $name;
			}
			foreach ($candidates as $p) {
				if (is_file($p)) { @unlink($p); }
			}
		}
	}

	/**
	 * Delete original + every variant from the bucket. Best-effort, with the same
	 * CLOUD_STORAGE_ORPHAN retry/log behavior File used: a stubborn key is logged
	 * for manual cleanup and the row is removed regardless.
	 */
	private function _delete_cloud_bytes() {
		$target_id = (int)$this->get('fbb_bkt_backup_target_id');
		try {
			$keys = array($this->remote_key_for('original'));
			foreach ($this->variant_size_keys() as $size_key) {
				$keys[] = $this->remote_key_for($size_key);
			}
		} catch (FileBlobException $e) {
			error_log('CLOUD_STORAGE_ORPHAN: target=' . $target_id . ' name=' . $this->get('fbb_stored_name') . ' (' . $e->getMessage() . ')');
			return;
		}
		$driver = $this->_cloud_driver();
		if (!$driver) {
			error_log('CLOUD_STORAGE_ORPHAN: target=' . $target_id . ' keys=' . implode(',', $keys) . ' (no driver for its file store)');
			return;
		}

		$failed_keys = array();
		foreach ($keys as $k) {
			try {
				$driver->delete($k);
			} catch (Exception $e) {
				try {
					usleep(500000);
					$driver->delete($k);
				} catch (Exception $e2) {
					$failed_keys[] = $k;
				}
			}
		}
		if (!empty($failed_keys)) {
			error_log('CLOUD_STORAGE_ORPHAN: target=' . $target_id . ' keys=' . implode(',', $failed_keys));
		}
	}

	// ==================================================================
	// Visibility maintenance — flip (refcount 1) and copy-on-write split
	// (refcount > 1). Called by File::move_to_correct_directory().
	// ==================================================================

	/**
	 * Move THIS blob (the only reference) into the target visibility class. A
	 * local blob's bytes are renamed between the fast-serve and restricted dirs;
	 * a cloud blob is pulled home into the target dir before its record flips,
	 * so a blob made public is local by the time it reads public — nothing on a
	 * page is ever served from the bucket. A blob made private stays local until
	 * the next offload tick moves it. fbb_is_private is updated to match.
	 */
	public function flipVisibility($to_private) {
		$to_private = $to_private ? true : false;
		if ($this->get('fbb_storage_driver') === 'cloud') {
			$this->_pull_back_from_cloud($to_private);
		} else {
			$this->_relocate_local($to_private);
		}
	}

	/**
	 * Copy-on-write split: materialize this blob's bytes as a brand-new blob in
	 * the target visibility class (refcount 1) and return it. The caller repoints
	 * its file at the new blob and release()s this one. Hash and size carry over
	 * (identical bytes), so the copy remains a dedup candidate in its class.
	 */
	public function splitCopy($to_private) {
		$to_private  = $to_private ? true : false;
		$new_name    = self::_unique_stored_name($this->get('fbb_stored_name'));
		$target_dir  = $to_private ? self::restricted_dir() : self::fast_serve_dir();
		if (!is_dir($target_dir)) {
			@mkdir($target_dir, 0777, true);
		}
		if (!$to_private) {
			self::_ensure_fast_htaccess($target_dir);
		}

		$size_keys = array_merge(array('original'), $this->variant_size_keys());

		$is_cloud = ($this->get('fbb_storage_driver') === 'cloud');
		$driver   = $is_cloud ? $this->_cloud_driver() : null;
		if ($is_cloud && !$driver) {
			throw new FileBlobException('splitCopy: cloud driver not configured for blob ' . $this->key);
		}

		$written = array();
		try {
			foreach ($size_keys as $sk) {
				$dest = ($sk === 'original') ? $target_dir . '/' . $new_name : $target_dir . '/' . $sk . '/' . $new_name;
				$dest_parent = dirname($dest);
				if (!is_dir($dest_parent)) {
					@mkdir($dest_parent, 0777, true);
				}
				if ($is_cloud) {
					try {
						$driver->get($this->remote_key_for($sk), $dest);
						$written[] = $dest;
					} catch (Exception $e) {
						if ($sk === 'original') {
							throw new FileBlobException('splitCopy: cannot pull original: ' . $e->getMessage(), 0, $e);
						}
						// A missing variant is non-fatal; resize can regenerate it.
					}
				} else {
					$src = $this->filesystem_path($sk);
					if (file_exists($src)) {
						if (!copy($src, $dest)) {
							throw new FileBlobException('splitCopy: copy failed for ' . $sk);
						}
						$written[] = $dest;
					} elseif ($sk === 'original') {
						throw new FileBlobException('splitCopy: original bytes missing for blob ' . $this->key);
					}
				}
				if (is_file($dest)) {
					@chmod($dest, 0666);
				}
			}
		} catch (Exception $e) {
			foreach ($written as $p) {
				if (is_file($p)) {
					@unlink($p);
				}
			}
			throw $e;
		}

		$new = new self(NULL);
		$new->set('fbb_stored_name', $new_name);
		$new->set('fbb_size_bytes', $this->get('fbb_size_bytes'));
		$new->set('fbb_sha256', $this->get('fbb_sha256'));
		$new->set('fbb_mime_type', $this->get('fbb_mime_type'));
		$new->set('fbb_is_private', $to_private);
		$new->set('fbb_reference_count', 1);
		$new->set('fbb_storage_driver', 'local');
		$new->save();
		$new->load();
		return $new;
	}

	/**
	 * Overwrite this blob's original bytes in place with $new_bytes — for a
	 * consumer that stores a transformed version of one file's content (sealing
	 * an attachment as ciphertext, or unsealing it back). MUST be called only on a
	 * blob this file exclusively owns (refcount 1); File::replace_bytes() splits a
	 * shared blob off first. The bytes no longer describe a shareable original, so
	 * the content hash is cleared (the blob stops being a dedup target), the
	 * recorded size is updated, and every derived image variant is dropped. A
	 * cloud-resident blob is pulled home first so the write lands on a real path.
	 *
	 * @return bool whether the bytes were written
	 */
	/**
	 * Replace this blob's bytes with the contents of a file on disk, by moving
	 * that file into place — the streaming twin of overwriteBytes().
	 *
	 * A protection-level change rewrites whole files (a 2 GB video sealed on the
	 * way up, opened on the way down). overwriteBytes() takes the replacement as
	 * a string, which is fine for a mail attachment and impossible here, so the
	 * caller writes its replacement to a temp file and hands over the path.
	 *
	 * @param string $src_path replacement bytes; MOVED (gone afterwards on success)
	 * @return bool
	 */
	public function overwriteBytesFromPath($src_path) {
		if (!is_file($src_path)) {
			return false;
		}
		if ($this->get('fbb_storage_driver') === 'cloud') {
			// Bring the bytes local (same visibility class) so an in-place rewrite works.
			$this->flipVisibility($this->is_private_bool());
		}
		$path = $this->filesystem_path('original');
		if ($path === '' || $path === null) {
			return false;
		}
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		$size = filesize($src_path);
		if (!@rename($src_path, $path)) {
			// A cross-filesystem temp dir cannot be renamed into place; copy, then
			// drop the source so the caller's contract still holds.
			if (!@copy($src_path, $path)) {
				return false;
			}
			@unlink($src_path);
		}
		@chmod($path, 0666);
		// The rewritten bytes invalidate every derived variant and the dedup hash.
		$this->delete_resized('all');
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"UPDATE fbb_file_blobs SET fbb_sha256 = NULL, fbb_size_bytes = ? WHERE fbb_file_blob_id = ?");
		$q->execute([(int)$size, $this->key]);
		$this->set('fbb_sha256', null, false);
		$this->set('fbb_size_bytes', (int)$size, false);
		return true;
	}

	public function overwriteBytes($new_bytes) {
		if ($this->get('fbb_storage_driver') === 'cloud') {
			// Bring the bytes local (same visibility class) so an in-place rewrite works.
			$this->flipVisibility($this->is_private_bool());
		}
		$path = $this->filesystem_path('original');
		if ($path === '' || $path === null) {
			return false;
		}
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		if (@file_put_contents($path, $new_bytes) === false) {
			return false;
		}
		@chmod($path, 0666);
		// The rewritten bytes invalidate every derived variant and the dedup hash.
		$this->delete_resized('all');
		$size = strlen($new_bytes);
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"UPDATE fbb_file_blobs SET fbb_sha256 = NULL, fbb_size_bytes = ? WHERE fbb_file_blob_id = ?");
		$q->execute([$size, $this->key]);
		$this->set('fbb_sha256', null, false);
		$this->set('fbb_size_bytes', $size, false);
		return true;
	}

	/**
	 * Rename a local blob's original + variants into the target-visibility dir,
	 * then persist fbb_is_private. Detects where the bytes actually sit (fast or
	 * restricted) and refuses to move when the same name exists in both (the
	 * duplicate-filename guard File carried).
	 */
	private function _relocate_local($to_private) {
		$name        = $this->get('fbb_stored_name');
		$fast_dir    = self::fast_serve_dir();
		$restricted  = self::restricted_dir();
		$target_dir  = $to_private ? $restricted : $fast_dir;

		$in_fast   = file_exists($fast_dir . '/' . $name);
		$in_normal = file_exists($restricted . '/' . $name);
		if ($in_fast && $in_normal) {
			throw new FileBlobException("Cannot move blob '$name': stored name exists in both upload directories.");
		}
		$source_dir = $in_fast ? $fast_dir : ($in_normal ? $restricted : null);

		if ($target_dir === $fast_dir) {
			self::_ensure_fast_htaccess($fast_dir);
		}

		if ($source_dir && $source_dir !== $target_dir) {
			if ($this->_move_single($source_dir, $target_dir, $name)) {
				foreach ($this->variant_size_keys() as $size_key) {
					$this->_move_single($source_dir . '/' . $size_key, $target_dir . '/' . $size_key, $name);
				}
			}
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("UPDATE fbb_file_blobs SET fbb_is_private = :priv WHERE fbb_file_blob_id = :id");
		$q->bindValue(':priv', $to_private, PDO::PARAM_BOOL);
		$q->bindValue(':id', $this->key, PDO::PARAM_INT);
		$q->execute();
		$this->set('fbb_is_private', $to_private, false);
	}

	private function _move_single($source_dir, $target_dir, $name) {
		$source = $source_dir . '/' . $name;
		$target = $target_dir . '/' . $name;
		if (!file_exists($source)) {
			return true; // nothing to move — not an error
		}
		if (file_exists($target)) {
			throw new FileBlobException("Cannot move blob '$name': a file already exists at '$target'.");
		}
		if (!is_dir($target_dir)) {
			@mkdir($target_dir, 0777, true);
		}
		if (!rename($source, $target)) {
			throw new FileBlobException("Failed to move blob '$name' from '$source' to '$target'.");
		}
		return true;
	}

	/**
	 * Pull a cloud-resident blob's bytes back to local, landing them in the
	 * target-visibility dir and flipping fbb_storage_driver to 'local' +
	 * fbb_is_private to $to_private. Three strictly-ordered phases with the same
	 * invariants File's original pull-back used: bucket authoritative until the
	 * DB commit; temps live until commit so they stay rollback material.
	 */
	private function _pull_back_from_cloud($to_private) {
		$driver = $this->_cloud_driver();
		if (!$driver) {
			throw new FileBlobException('Cannot pull blob back from cloud: the store is not configured.');
		}

		$name = $this->get('fbb_stored_name');
		$target_dir = $to_private ? self::restricted_dir() : self::fast_serve_dir();

		$keys = array_merge(array('original'), $this->variant_size_keys());

		$tmp_dir = sys_get_temp_dir() . '/fbb_pullback_' . $this->key . '_' . uniqid();
		if (!mkdir($tmp_dir, 0777, true)) {
			throw new FileBlobException('Failed to create temp dir for pull-back: ' . $tmp_dir);
		}
		$temp_paths = array();
		$drop_temps = function() use (&$temp_paths, $tmp_dir) {
			foreach ($temp_paths as $p) {
				if (is_file($p)) @unlink($p);
			}
			foreach (glob($tmp_dir . '/*', GLOB_ONLYDIR) as $d) @rmdir($d);
			@rmdir($tmp_dir);
		};
		$ct = $this->get('fbb_mime_type') ?: 'application/octet-stream';

		// PHASE 1 — pull every key to temp.
		try {
			foreach ($keys as $size_key) {
				$tmp_path = ($size_key === 'original') ? $tmp_dir . '/' . $name : $tmp_dir . '/' . $size_key . '/' . $name;
				$driver->get($this->remote_key_for($size_key), $tmp_path);
				$temp_paths[$size_key] = $tmp_path;
			}
		} catch (Exception $e) {
			$drop_temps();
			throw new FileBlobException('Phase 1 (pull from bucket) failed: ' . $e->getMessage(), 0, $e);
		}

		// PHASE 2 — delete from bucket with brief retries.
		$deleted_keys = array();
		foreach ($keys as $size_key) {
			$delete_ok = false;
			$last_err = null;
			foreach (array(0, 1, 2) as $delay) {
				if ($delay) sleep($delay);
				try {
					$driver->delete($this->remote_key_for($size_key));
					$delete_ok = true;
					break;
				} catch (Exception $e) {
					$last_err = $e;
				}
			}
			if (!$delete_ok) {
				foreach ($deleted_keys as $rb) {
					try {
						$driver->put($temp_paths[$rb], $this->remote_key_for($rb), $ct);
					} catch (Exception $rb_err) { /* swallow — broader trouble already */ }
				}
				$drop_temps();
				throw new FileBlobException('Phase 2 (bucket delete) failed for ' . $size_key . ': ' . ($last_err ? $last_err->getMessage() : 'unknown'), 0, $last_err);
			}
			$deleted_keys[] = $size_key;
		}

		// PHASE 3 — copy temps to the target dir, then commit the DB flip.
		$copied_paths = array();
		try {
			if (!is_dir($target_dir)) {
				mkdir($target_dir, 0777, true);
			}
			if (!$to_private) {
				self::_ensure_fast_htaccess($target_dir);
			}
			foreach ($keys as $size_key) {
				$dest = ($size_key === 'original') ? $target_dir . '/' . $name : $target_dir . '/' . $size_key . '/' . $name;
				$dest_parent = dirname($dest);
				if (!is_dir($dest_parent)) {
					mkdir($dest_parent, 0777, true);
				}
				if (!copy($temp_paths[$size_key], $dest)) {
					throw new FileBlobException('Phase 3 (local copy) failed for ' . $size_key);
				}
				@chmod($dest, 0666);
				$copied_paths[] = $dest;
			}

			$dblink = DbConnector::get_instance()->get_db_link();
			$dblink->beginTransaction();
			try {
				$q = $dblink->prepare(
					"UPDATE fbb_file_blobs SET fbb_storage_driver = 'local', fbb_is_private = :priv,
					 fbb_bkt_backup_target_id = NULL, fbb_remote_key = NULL WHERE fbb_file_blob_id = :id");
				$q->bindValue(':priv', $to_private, PDO::PARAM_BOOL);
				$q->bindValue(':id', $this->key, PDO::PARAM_INT);
				$q->execute();
				$dblink->commit();
			} catch (PDOException $e) {
				$dblink->rollBack();
				throw new FileBlobException('Phase 3 (DB commit) failed: ' . $e->getMessage(), 0, $e);
			}

			$this->set('fbb_storage_driver', 'local', false);
			$this->set('fbb_is_private', $to_private, false);
			$this->set('fbb_bkt_backup_target_id', null, false);
			$this->set('fbb_remote_key', null, false);
		} catch (Exception $e) {
			foreach ($copied_paths as $p) @unlink($p);
			foreach ($keys as $size_key) {
				try {
					$driver->put($temp_paths[$size_key], $this->remote_key_for($size_key), $ct);
				} catch (Exception $reput) { /* row genuinely broken; logged below */ }
			}
			$drop_temps();
			error_log('CLOUD_STORAGE_PARTIAL_FLIP: fbb=' . $this->key . ' name=' . $name . ' err=' . $e->getMessage());
			throw $e;
		}

		$drop_temps();
	}

	// ==================================================================
	// Image variants — resize / delete, keyed on fbb_stored_name.
	// ==================================================================

	/**
	 * Write already-encrypted variant bytes into a size slot (docs/drive_encryption.md).
	 * An encrypted Drive file's thumbnail is generated and encrypted in the
	 * browser, then dropped into the blob's <size_key>/<stored_name> slot verbatim
	 * — the server resize pipeline skips ciphertext, so the slot is otherwise
	 * empty. Only ever called on a fresh, local blob at upload_complete. The size
	 * key is recorded on the row (fbb_encrypted_variant_key) so
	 * variant_size_keys() surfaces the slot to every lifecycle path — offload,
	 * cloud delete, splitCopy, visibility move, pull-back — which all skip
	 * non-image blobs otherwise.
	 *
	 * @return bool true on write
	 */
	public function store_encrypted_variant($size_key, $bytes) {
		if ($size_key === '' || $size_key === 'original' || $bytes === null || $bytes === '') {
			return false;
		}
		$dir = $this->local_dir() . '/' . $size_key;
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		$path = $dir . '/' . $this->get('fbb_stored_name');
		if (@file_put_contents($path, $bytes) === false) {
			return false;
		}
		@chmod($path, 0666);
		if ((string)$this->get('fbb_encrypted_variant_key') !== (string)$size_key) {
			$this->set('fbb_encrypted_variant_key', $size_key);
			$this->save();
		}
		return true;
	}

	/**
	 * Drop the encrypted variant slot — the exact inverse of
	 * store_encrypted_variant(), and the only variant remover that does NOT
	 * consult is_image().
	 *
	 * It cannot: a blob holding a container stores `application/octet-stream`,
	 * because that is what its bytes sniff as, so delete_resized() skips it
	 * entirely. Anything that stops a blob being sealed — opening a Private file
	 * back to Standard, deleting a Fortress file — has to remove the slot through
	 * this door or leave ciphertext behind in a size key that nothing tracks
	 * afterwards (fbb_encrypted_variant_key is what every lifecycle path
	 * enumerates from, and it is cleared here).
	 *
	 * @return bool whether a slot was recorded to remove
	 */
	public function delete_encrypted_variant() {
		$size_key = (string)$this->get('fbb_encrypted_variant_key');
		if ($size_key === '') {
			return false;
		}
		if ($this->get('fbb_storage_driver') === 'cloud') {
			$driver = $this->_cloud_driver();
			if ($driver) {
				try {
					$driver->delete($this->remote_key_for($size_key));
				} catch (Exception $e) {
					error_log('delete_encrypted_variant cloud: ' . $e->getMessage());
				}
			}
		} else {
			$path = $this->filesystem_path($size_key);
			if (file_exists($path)) {
				@unlink($path);
			}
		}
		$this->set('fbb_encrypted_variant_key', null);
		$this->save();
		return true;
	}

	/**
	 * Cut every registered size (or one, $size_key) from this blob's original.
	 *
	 * One decode: the original is opened once, through ImageDecoder, no larger
	 * than the largest registered size needs, upright, and every size is
	 * resampled from that one image under one ImageWorkLock hold. A decode the
	 * ceiling refuses (setting image_decode_max_mb) is recorded in
	 * fbb_variant_refused, so a later ensure_variant() does not try again on
	 * every view; regenerate_image_sizes.php clears it to retry.
	 *
	 * @return bool true when the sizes were written
	 */
	public function resize($size_key = 'all') {
		if (!$this->is_image()) {
			return false;
		}
		if ($this->is_variant_refused()) {
			return false;
		}
		$sizes = ImageSizeRegistry::get_sizes();
		$wanted = array();
		foreach ($sizes as $key => $config) {
			if ($size_key === 'all' || $size_key === $key) {
				$wanted[$key] = $config;
			}
		}
		if (!$wanted) {
			return false;
		}

		if ($this->get('fbb_storage_driver') === 'cloud') {
			return $this->_resize_cloud($wanted);
		}

		$old_path = $this->filesystem_path('original');
		if (!file_exists($old_path)) {
			return false;
		}
		$base_dir = dirname($old_path);
		$name = $this->get('fbb_stored_name');
		$dests = array();
		foreach ($wanted as $key => $config) {
			$dir_path = $base_dir . '/' . $key;
			if (!is_dir($dir_path)) {
				if (mkdir($dir_path, 0777, true)) {
					chmod($dir_path, 0777);
				} else {
					error_log('Failed to create resize directory: ' . $dir_path);
				}
			}
			$dests[$key] = $dir_path . '/' . $name;
		}
		return $this->_render_sizes($old_path, $wanted, $dests, true);
	}

	/** The reason this blob's last resize was refused, or null. */
	public function variant_refusal() {
		$why = $this->get('fbb_variant_refused');
		return ($why === null || $why === '') ? null : (string)$why;
	}

	public function is_variant_refused() {
		return $this->variant_refusal() !== null;
	}

	/** Forget a refusal so the next resize() tries again (a raised ceiling, a regenerate). */
	public function clear_variant_refusal() {
		if ($this->key && $this->is_variant_refused()) {
			self::updateColumns((int)$this->key, array('fbb_variant_refused' => null));
			$this->set('fbb_variant_refused', null, false);
		}
	}

	/**
	 * Make sure one variant exists on disk, generating just that size if it does
	 * not, and return its path (false if it cannot be produced).
	 *
	 * Variants are opt-in at ingestion — only a caller that knows it wants them
	 * calls resize() — so a subsystem that stores an image without asking (an
	 * inbound mail attachment, a chat upload) has bytes but no variants, while
	 * File::get_url() will still mint variant URLs for it. This is what makes
	 * those URLs honest, and it writes the ONE size that was asked for rather
	 * than every registered size, so a mailbox full of attachments does not grow
	 * four unwanted copies of every image.
	 *
	 * Local blobs only: producing a cloud variant means download, resize and
	 * re-upload, which does not belong inside a page request.
	 *
	 * @param string $size_key A registered ImageSizeRegistry key ('original' is not a variant)
	 * @return string|false Path to the variant, or false
	 */
	public function ensure_variant($size_key) {
		if ($size_key === 'original' || !$this->is_image()) {
			return false;
		}
		if ($this->get('fbb_storage_driver') !== 'local') {
			return false;
		}
		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();
		if (!isset($sizes[$size_key])) {
			return false;
		}

		$path = $this->filesystem_path($size_key);
		if (file_exists($path)) {
			return $path;
		}
		if ($this->is_variant_refused() || !file_exists($this->filesystem_path('original'))) {
			return false;
		}

		$this->resize($size_key);

		$path = $this->filesystem_path($size_key);
		return file_exists($path) ? $path : false;
	}

	private function _resize_cloud($wanted) {
		$driver = $this->_cloud_driver();
		if (!$driver) {
			throw new FileBlobException('Cannot re-resize cloud blob: cloud storage driver not configured.');
		}

		$name = $this->get('fbb_stored_name');
		$tmp_dir = sys_get_temp_dir() . '/cloud_resize_' . $this->key . '_' . uniqid();
		if (!mkdir($tmp_dir, 0777, true)) {
			throw new FileBlobException('Failed to create temp dir for cloud resize: ' . $tmp_dir);
		}
		$cleanup = function() use ($tmp_dir) {
			if (!is_dir($tmp_dir)) return;
			foreach (glob($tmp_dir . '/{,*/}{,.}*', GLOB_BRACE) as $f) {
				if (is_file($f)) @unlink($f);
			}
			foreach (glob($tmp_dir . '/*', GLOB_ONLYDIR) as $d) @rmdir($d);
			@rmdir($tmp_dir);
		};

		try {
			$tmp_original = $tmp_dir . '/' . $name;
			$driver->get($this->remote_key_for('original'), $tmp_original);
			$content_type = $this->get('fbb_mime_type') ?: 'image/jpeg';

			$dests = array();
			foreach ($wanted as $key => $config) {
				$variant_dir = $tmp_dir . '/' . $key;
				if (!is_dir($variant_dir)) {
					mkdir($variant_dir, 0777, true);
				}
				$dests[$key] = $variant_dir . '/' . $name;
			}
			$ok = $this->_render_sizes($tmp_original, $wanted, $dests, true);
			foreach ($dests as $key => $variant_path) {
				if (file_exists($variant_path)) {
					$driver->put($variant_path, $this->remote_key_for($key), $content_type);
				}
			}
			return $ok;
		} finally {
			$cleanup();
		}
	}

	public function delete_resized($size_key = 'all') {
		if (!$this->is_image()) {
			return false;
		}
		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();

		if ($this->get('fbb_storage_driver') === 'cloud') {
			$driver = $this->_cloud_driver();
			if (!$driver) {
				return false;
			}
			foreach ($sizes as $key => $config) {
				if ($size_key !== 'all' && $size_key !== $key) {
					continue;
				}
				try {
					$driver->delete($this->remote_key_for($key));
				} catch (Exception $e) {
					error_log('delete_resized cloud: ' . $e->getMessage());
				}
			}
			return;
		}

		foreach ($sizes as $key => $config) {
			if ($size_key !== 'all' && $size_key !== $key) {
				continue;
			}
			$file_path = $this->filesystem_path($key);
			if (file_exists($file_path)) {
				@unlink($file_path);
			}
		}
	}

	/**
	 * Render one configured size variant from an arbitrary plaintext image on
	 * disk into an arbitrary destination, without touching this blob's stored
	 * bytes or its variant layout.
	 *
	 * The sealed levels need exactly this: a Private file's thumbnail is made
	 * once, from the plaintext, at the moment it is still in hand — and then
	 * sealed and stored through store_encrypted_variant() rather than left in the
	 * plaintext variant tree the resize pipeline owns. A refused decode is not
	 * recorded on the blob here: the plaintext is the caller's, not the blob's.
	 *
	 * @param string $src_path  a readable plaintext image
	 * @param string $dest_path where to write the rendered variant
	 * @param string $size_key  a key from ImageSizeRegistry::get_sizes()
	 * @return bool true when a file was produced
	 */
	public function render_variant_to($src_path, $dest_path, $size_key) {
		$sizes = ImageSizeRegistry::get_sizes();
		if (!isset($sizes[$size_key])) {
			return false;
		}
		$this->_render_sizes($src_path, array($size_key => $sizes[$size_key]), array($size_key => $dest_path), false);
		return is_file($dest_path);
	}

	/**
	 * Decode $src_path once and write every size in $wanted to its path in
	 * $dests, under one ImageWorkLock hold. The decoded image is at most the
	 * largest registered size's worth of pixels (times what the M/8 shrink
	 * steps allow), never the full photo, and it is freed before the lock is.
	 *
	 * @param bool $record  note a refused decode on this blob (fbb_variant_refused)
	 * @return bool true when the sizes were written
	 */
	private function _render_sizes($src_path, $wanted, $dests, $record) {
		return ImageWorkLock::run(function () use ($src_path, $wanted, $dests, $record) {
			$max = ImageSizeRegistry::max_dimensions();
			try {
				$decoded = ImageDecoder::open($src_path, $max['width'], $max['height']);
			} catch (ImageDecodeRefused $e) {
				error_log('FileBlob resize refused for ' . basename($src_path) . ': ' . $e->getMessage());
				// A transient refusal (the site was out of memory just then) is
				// not recorded: the next ensure_variant() or regenerate tries again.
				if ($record && !$e->transient) {
					$this->_record_variant_refusal($e->getMessage());
				}
				return false;
			}
			try {
				foreach ($wanted as $key => $config) {
					$this->_render_size($decoded, $config, $dests[$key]);
				}
			} finally {
				unset($decoded);
			}
			if ($record) {
				$this->clear_variant_refusal();
			}
			return true;
		});
	}

	/**
	 * A refusal is written during the request that found it, which for an
	 * on-demand variant (ensure_variant() from serve.php) is a page view: the
	 * one row write that stops the next view decoding the same photo again.
	 */
	private function _record_variant_refusal($why) {
		if (!$this->key) {
			return;
		}
		$why = substr((string)$why, 0, 255);
		SystemBase::server_initiated_write(function () use ($why) {
			self::updateColumns((int)$this->key, array('fbb_variant_refused' => $why));
		});
		$this->set('fbb_variant_refused', $why, false);
	}

	/**
	 * Resample one size from a decoded image and write it atomically. Verbatim
	 * geometry from File's original generate_resized(): a crop takes the centred
	 * box of the target's aspect, a scale fits inside width x height, and
	 * neither ever enlarges.
	 */
	private function _render_size(ImageDecoded $decoded, $config, $new_path) {
		$width = (int)$config['width'];
		$height = (int)$config['height'];
		$crop = !empty($config['crop']);
		$quality = isset($config['quality']) ? (int)$config['quality'] : 85;
		try {
			$src = $decoded->image;
			$type = $decoded->type;
			$src_w = $decoded->width;
			$src_h = $decoded->height;

			$sx = 0; $sy = 0; $sw = $src_w; $sh = $src_h;
			if ($crop && $width > 0 && $height > 0) {
				if (($src_w / $width) < ($src_h / $height)) {
					$sw = $src_w;
					$sh = (int)floor($height * $src_w / $width);
					$sx = 0;
					$sy = (int)(($src_h - $sh) / 2);
				} else {
					$sw = (int)ceil($width * $src_h / $height);
					$sh = $src_h;
					$sx = (int)(($src_w - $sw) / 2);
					$sy = 0;
				}
			}

			$f = 1.0;
			if ($width > 0 && $height > 0) {
				$f = min($width / $sw, $height / $sh);
			} elseif ($width > 0) {
				$f = $width / $sw;
			} elseif ($height > 0) {
				$f = $height / $sh;
			}
			if ($f > 1.0) { $f = 1.0; }
			$dw = max(1, (int)round($sw * $f));
			$dh = max(1, (int)round($sh * $f));

			$dst = imagecreatetruecolor($dw, $dh);
			self::gd_preserve_alpha($dst, $type);
			imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

			if ($type === IMAGETYPE_GIF) {
				$src_transparent = imagecolortransparent($src);
				imagetruecolortopalette($dst, false, 255);
				if ($src_transparent >= 0) {
					imagecolortransparent($dst, imagecolorclosestalpha($dst, 0, 0, 0, 127));
				}
			}

			// Write to a private temp name in the same directory, then rename
			// into place — rename is atomic within a filesystem, so a reader
			// never sees a half-written variant. On-demand generation makes
			// that concurrency real: one listing page fires a thumb request per
			// row, and dedup siblings share a stored name, so several requests
			// can race to produce the very same variant file.
			$tmp_path = $new_path . '.tmp' . getmypid() . '_' . LibraryFunctions::random_string(6);
			self::gd_write($dst, $tmp_path, $type, $quality);
			unset($dst);
			if (file_exists($tmp_path)) {
				@chmod($tmp_path, 0666);
				if (!@rename($tmp_path, $new_path)) {
					@unlink($tmp_path);
					error_log('FileBlob resize: could not place variant ' . $new_path);
				}
			}

		} catch (\Throwable $e) {
			error_log('FileBlob resize generation failed for ' . basename($new_path) . ': ' . $e->getMessage());
		}
	}

	private static function gd_preserve_alpha($dst, $type) {
		if ($type === IMAGETYPE_JPEG) {
			return;
		}
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
		$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
		imagefill($dst, 0, 0, $transparent);
	}

	private static function gd_write($img, $path, $type, $quality) {
		switch ($type) {
			case IMAGETYPE_JPEG: imagejpeg($img, $path, $quality); break;
			case IMAGETYPE_PNG:  imagepng($img, $path); break;
			case IMAGETYPE_GIF:  imagegif($img, $path); break;
			case IMAGETYPE_WEBP: if (function_exists('imagewebp')) { imagewebp($img, $path, $quality); } break;
			case IMAGETYPE_AVIF: if (function_exists('imageavif')) { imageavif($img, $path, $quality); } break;
		}
	}

	// ==================================================================
	// Stored-name helpers
	// ==================================================================

	private static function _sanitize_name($name) {
		$name = str_replace(' ', '_', basename((string)$name));
		$name = preg_replace('/[^A-Za-z0-9\.\-\_]/', '', $name);
		$name = preg_replace('/_+/', '_', $name);
		if ($name === '' || $name === null) {
			$name = 'object.bin';
		}
		if (strpos($name, '.') === false) {
			$name .= '.bin';
		}
		return $name;
	}

	/**
	 * A collision-free stored name derived from $basename: a random token
	 * inserted before the extension, sanitized, checked against both the DB and
	 * on-disk placement. The random-token scheme File::createFromBytes used.
	 */
	private static function _unique_stored_name($basename) {
		$base = self::_sanitize_name($basename);
		for ($i = 0; $i < 12; $i++) {
			$rand = '_' . LibraryFunctions::random_string(8) . '.';
			$dot = strrpos($base, '.');
			$name = substr($base, 0, $dot) . $rand . substr($base, $dot + 1);
			$name = str_replace(' ', '_', $name);
			$name = preg_replace('/[^A-Za-z0-9\.\-\_]/', '', $name);
			$name = preg_replace('/_+/', '_', $name);
			if (!self::stored_name_exists($name) && !self::_on_disk($name)) {
				return $name;
			}
		}
		return preg_replace('/[^A-Za-z0-9\.\-\_]/', '', LibraryFunctions::random_string(24)) . '.bin';
	}

	public static function stored_name_exists($name) {
		$dblink = DbConnector::get_instance()->get_db_link();
		try {
			$q = $dblink->prepare("SELECT 1 FROM fbb_file_blobs WHERE fbb_stored_name = ? LIMIT 1");
			$q->execute([$name]);
			return (bool)$q->fetchColumn();
		} catch (PDOException $e) {
			return false; // table may not exist yet during initial setup
		}
	}

	private static function _on_disk($name) {
		return file_exists(self::fast_serve_dir() . '/' . $name)
			|| file_exists(self::restricted_dir() . '/' . $name);
	}

	private static function _ensure_fast_htaccess($fast_dir) {
		$htaccess_path = $fast_dir . '/.htaccess';
		if (!file_exists($htaccess_path)) {
			if (!is_dir($fast_dir)) {
				mkdir($fast_dir, 0777, true);
			}
			file_put_contents($htaccess_path, "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(.*)$ /uploads/\$1 [R=302,L]\n");
		}
	}
}

class MultiFileBlob extends SystemMultiBase {
	protected static $model_class = 'FileBlob';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['storage_driver'])) {
			$filters['fbb_storage_driver'] = array($this->options['storage_driver'], PDO::PARAM_STR);
		}
		if (isset($this->options['is_private'])) {
			$filters['fbb_is_private'] = array($this->options['is_private'] ? true : false, PDO::PARAM_BOOL);
		}
		if (isset($this->options['sha256'])) {
			$filters['fbb_sha256'] = array($this->options['sha256'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('fbb_file_blobs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
