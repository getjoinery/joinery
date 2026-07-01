<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));

class FileException extends SystemBaseException {}

class File extends SystemBase {	public static $prefix = 'fil';
	public static $tablename = 'fil_files';
	public static $pkey_column = 'fil_file_id';

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_description     = 'Files the user has uploaded.';
	public static $ai_excluded_fields = [];
	public static $ai_untrusted_fields = ['fil_title', 'fil_description', 'fil_name'];

	protected static $foreign_key_actions = [
		'fil_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
	];

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'fil_file_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'fil_name' => array('type'=>'varchar(255)'),
	    'fil_title' => array('type'=>'varchar(255)'),
	    'fil_description' => array('type'=>'text'),
	    'fil_type' => array('type'=>'varchar(128)'),
	    'fil_usr_user_id' => array('type'=>'int4'),
	    'fil_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'fil_delete_time' => array('type'=>'timestamp(6)'),
	    'fil_min_permission' => array('type'=>'int2'),
	    'fil_grp_group_id' => array('type'=>'int4'),
	    'fil_evt_event_id' => array('type'=>'int4'),
	    'fil_tier_min_level' => array('type'=>'int4', 'is_nullable'=>true),
	    'fil_private' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'false'),
	    'fil_storage_driver' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>"'local'"),
	    'fil_sync_failed_count' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>'0'),
	    'fil_sync_last_attempt' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

public static function get_by_name($name, $search_deleted = false) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "SELECT fil_file_id FROM fil_files WHERE fil_name = :fil_name";
		if (!$search_deleted) {
			$sql .= " AND fil_delete_time IS NULL";
		}
		$sql .= " ORDER BY fil_file_id DESC LIMIT 1";

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':fil_name', $name, PDO::PARAM_STR);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		$r = $q->fetch();
		if (!$r) {
			return FALSE;
		}

		return new File($r->fil_file_id, TRUE);
	}
	
	/**
	 * Mint a File from in-memory bytes — a generated, received, or fetched blob
	 * that never passed through a $_FILES upload. General-purpose: any caller
	 * that holds the content directly rather than an uploaded temp file (inbound
	 * email attachments are the first consumer). Writes the bytes into upload_dir
	 * under a collision-free on-disk name, keeps $filename as the display title,
	 * applies the given restriction columns, saves (save() relocates a public
	 * file to the fast-serve dir; a restricted one stays in upload_dir), and
	 * returns the loaded File. No image variants are generated — a caller that
	 * wants them calls resize() itself.
	 *
	 * @param string $bytes        the file content
	 * @param string $filename     original/display name, e.g. "invoice.pdf" (kept as fil_title)
	 * @param string $content_type MIME type (stored in fil_type)
	 * @param int    $owner_id     fil_usr_user_id — the honest owner
	 * @param array  $restrictions extra columns to set at creation, e.g. ['fil_private' => true]
	 * @return File the saved, reloaded File
	 * @throws FileException when the bytes cannot be written to disk
	 */
	public static function createFromBytes($bytes, $filename, $content_type, $owner_id, array $restrictions = array()) {
		$settings = Globalvars::get_instance();
		$upload_dir = $settings->get_setting('upload_dir');

		if (!is_dir($upload_dir)) {
			@mkdir($upload_dir, 0777, true);
		}

		// Collision-free on-disk name: a random token inserted before the
		// extension, then sanitized — the same scheme the $_FILES upload flow
		// uses, so two files sharing a display name never share a fil_name.
		$base = ($filename !== null && $filename !== '') ? $filename : 'attachment';
		if (strpos($base, '.') === false) {
			$base .= '.bin';
		}
		$rand_string = '_' . LibraryFunctions::random_string(8) . '.';
		$dotpos = strrpos($base, '.');
		$new_name = substr($base, 0, $dotpos) . $rand_string . substr($base, $dotpos + 1);
		$new_name = str_replace(' ', '_', $new_name);
		$new_name = preg_replace('/[^A-Za-z0-9\.\-\_]/', '', $new_name);
		$new_name = preg_replace('/_+/', '_', $new_name);

		$target = $upload_dir . '/' . $new_name;
		if (file_put_contents($target, $bytes) === false) {
			throw new FileException('createFromBytes: unable to write bytes to ' . $target);
		}
		@chmod($target, 0666);

		$file = new File(NULL);
		$file->set('fil_name', $new_name);
		$file->set('fil_title', substr((string)$filename, 0, 255));
		$file->set('fil_type', substr((string)$content_type, 0, 128));
		$file->set('fil_usr_user_id', $owner_id);
		foreach ($restrictions as $col => $val) {
			$file->set($col, $val);
		}
		$file->save();
		$file->load();
		return $file;
	}

	/**
	 * Return this file's raw bytes, resolving local disk or cloud storage. For
	 * server-side consumers that need the content directly (attachment
	 * download/forward, AI triage) rather than a URL. Returns null when the bytes
	 * cannot be read (missing on disk, cloud outage, unconfigured driver). This
	 * does NOT authorize — a caller serving to a user gates with is_viewable()
	 * first.
	 *
	 * @param string $size_key 'original' or an ImageSizeRegistry variant key
	 * @return string|null
	 */
	function read_bytes($size_key = 'original') {
		if ($this->get('fil_storage_driver') === 'cloud') {
			$driver = $this->_cloud_driver();
			if (!$driver) {
				return null;
			}
			$tmp = tempnam(sys_get_temp_dir(), 'fil_read_');
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
				error_log('File::read_bytes cloud GET failed fil=' . $this->key . ': ' . $e->getMessage());
				return null;
			}
		}

		$path = $this->get_filesystem_path($size_key);
		if (!file_exists($path)) {
			return null;
		}
		$bytes = file_get_contents($path);
		return $bytes === false ? null : $bytes;
	}

	function get_name() {
		if($this->get('fil_title')){
			return $this->get('fil_title');
		}
		else{
			return $this->get('fil_name');
		}
	}
	
	function is_image(){
		if (strpos($this->get('fil_type'), 'image/') !== false) {
			return true;
		}
		else{
			return false;
		}
	}

	/**
	 * Get the fast-serve directory path, derived from existing upload_dir setting.
	 * Public files (no permission restrictions) are served from this directory.
	 *
	 * @return string Filesystem path to static_files/uploads directory
	 */
	private static function get_fast_serve_dir() {
		$settings = Globalvars::get_instance();
		return dirname($settings->get_setting('upload_dir')) . '/static_files/uploads';
	}

	/**
	 * Determine whether this file should be in the public (fast-serve) directory.
	 * A file is public when it has no permission restrictions and is not deleted.
	 *
	 * @return bool
	 */
	/**
	 * Is this file owner-or-admin private? Interprets the fil_private column
	 * robustly across representations: PDO may hand back a native bool, a pg
	 * 't'/'f' string, and a not-yet-reloaded new row carries the declared string
	 * default 'false' (which is truthy in PHP) — so test membership of the "true"
	 * set rather than raw truthiness. Only genuine true values count as private.
	 */
	private function _is_private() {
		$v = $this->get('fil_private');
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	function is_public() {
		if ($this->get('fil_delete_time')) return false;
		if ($this->_is_private()) return false;
		if ($this->get('fil_min_permission')) return false;
		if ($this->get('fil_grp_group_id')) return false;
		if ($this->get('fil_evt_event_id')) return false;
		if ($this->get('fil_tier_min_level')) return false;
		return true;
	}

	/**
	 * Bucket object key (without the driver-applied path prefix) for a given
	 * size variant of this file. 'original' → "<filename>"; otherwise
	 * "<size>/<filename>".
	 */
	function remote_key_for($size_key = 'original') {
		$filename = $this->get('fil_name');
		if ($size_key === 'original') {
			return $filename;
		}
		return $size_key . '/' . $filename;
	}

	/**
	 * Get the actual filesystem path for this file, checking both directories.
	 * Checks fast-serve directory first since most files are public.
	 *
	 * Defensive instrumentation: emits a warning if called on a 'cloud' row,
	 * so any caller we missed during the cloud-storage rollout is surfaced
	 * without breaking them. Callers that are cloud-aware dispatch on
	 * fil_storage_driver before calling this method.
	 *
	 * @param string $size_key Size key from ImageSizeRegistry, or 'original'
	 * @return string Filesystem path (may not exist if file is missing from disk)
	 */
	function get_filesystem_path($size_key = 'original') {
		if ($this->get('fil_storage_driver') === 'cloud') {
			error_log('CLOUD_STORAGE_UNEXPECTED_LOCAL_PATH_QUERY: fil=' . $this->key . ' size=' . $size_key);
		}

		$settings = Globalvars::get_instance();
		$filename = $this->get('fil_name');

		$dirs = [
			self::get_fast_serve_dir(),
			$settings->get_setting('upload_dir')
		];

		foreach ($dirs as $dir) {
			if ($size_key === 'original') {
				$path = $dir . '/' . $filename;
			} else {
				$path = $dir . '/' . $size_key . '/' . $filename;
			}
			if (file_exists($path)) {
				return $path;
			}
		}

		// Fallback: return expected path in normal upload_dir
		$fallback_dir = $settings->get_setting('upload_dir');
		if ($size_key === 'original') {
			return $fallback_dir . '/' . $filename;
		}
		return $fallback_dir . '/' . $size_key . '/' . $filename;
	}

	/**
	 * Move the file (and all resized versions) to the correct directory based
	 * on current permissions. Public files go to static_files/uploads/,
	 * restricted files go to uploads/.
	 *
	 * @throws FileException on duplicate filenames or move failures
	 */
	function move_to_correct_directory($old_visibility = null) {
		// Cloud-stored row: the bytes already live in a bucket. Keep the
		// security invariant true — a file's bytes must sit in the store that
		// matches its CURRENT visibility (public bytes in the public bucket,
		// restricted bytes in the verified-private bucket). A visibility flip
		// after offload (old_visibility != current) leaves the bytes in the
		// wrong bucket, so pull them back to local from the store that still
		// holds them; the next offload tick re-places them in the now-correct
		// store. No flip → bytes are correctly placed, nothing to do.
		if ($this->get('fil_storage_driver') === 'cloud') {
			$new_visibility = $this->_cloud_visibility();
			$source = $old_visibility ?: $new_visibility;
			if ($source !== $new_visibility) {
				$this->_pull_back_from_cloud_to_local($source);
			}
			return;
		}

		$settings = Globalvars::get_instance();
		$filename = $this->get('fil_name');

		$fast_dir = self::get_fast_serve_dir();
		$normal_dir = $settings->get_setting('upload_dir');

		$in_fast = file_exists($fast_dir . '/' . $filename);
		$in_normal = file_exists($normal_dir . '/' . $filename);

		// Safety check: if file exists in BOTH directories, there are duplicate
		// filenames across different records. Do not move — this would cause data loss.
		if ($in_fast && $in_normal) {
			throw new FileException("Cannot move file '$filename': duplicate filename exists in both upload directories.");
		}

		// Determine target based on permissions
		$target_dir = $this->is_public() ? $fast_dir : $normal_dir;

		// Determine source directory (where file actually is)
		$source_dir = null;
		if ($in_fast) {
			$source_dir = $fast_dir;
		} elseif ($in_normal) {
			$source_dir = $normal_dir;
		}

		if (!$source_dir || $source_dir === $target_dir) {
			return; // Already in correct location or file not found
		}

		// Ensure .htaccess exists in fast-serve directory for Tier 1 fallback
		if ($target_dir === $fast_dir) {
			$htaccess_path = $fast_dir . '/.htaccess';
			if (!file_exists($htaccess_path)) {
				if (!is_dir($fast_dir)) {
					mkdir($fast_dir, 0777, true);
				}
				file_put_contents($htaccess_path, "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(.*)$ /uploads/\$1 [R=302,L]\n");
			}
		}

		// Move original file
		if (!$this->move_single_file($source_dir, $target_dir, $filename)) {
			return; // Original failed to move, don't move resized versions
		}

		// Move all resized versions
		if ($this->is_image()) {
			require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
			$sizes = ImageSizeRegistry::get_sizes();
			foreach ($sizes as $key => $config) {
				$this->move_single_file(
					$source_dir . '/' . $key,
					$target_dir . '/' . $key,
					$filename
				);
			}
		}
	}

	/**
	 * Move a single file from source to target directory.
	 *
	 * @param string $source_dir Source directory
	 * @param string $target_dir Target directory
	 * @param string $filename File name
	 * @return bool True on success or if nothing to move
	 * @throws FileException on move failure or if target already exists
	 */
	private function move_single_file($source_dir, $target_dir, $filename) {
		$source = $source_dir . '/' . $filename;
		$target = $target_dir . '/' . $filename;

		if (!file_exists($source)) return true; // Nothing to move, not an error

		// Don't overwrite an existing file at the target
		if (file_exists($target)) {
			throw new FileException("Cannot move file '$filename': file already exists at target '$target'.");
		}

		// Ensure target directory exists
		if (!is_dir($target_dir)) {
			mkdir($target_dir, 0777, true);
		}

		if (!rename($source, $target)) {
			throw new FileException("Failed to move file '$filename' from '$source' to '$target'.");
		}

		return true;
	}

	/**
	 * For an already-offloaded ('cloud') row, the visibility currently recorded
	 * in the database — i.e. which bucket physically holds the bytes right now,
	 * captured BEFORE a save/delete changes the in-memory permission fields.
	 * Returns 'public'/'private', or null when the row isn't cloud-stored (no
	 * bucket relocation is possible) or isn't persisted yet. Lets the post-change
	 * move_to_correct_directory() detect a visibility flip and pull from the
	 * right store.
	 */
	private function _offloaded_visibility() {
		if (!$this->key || $this->get('fil_storage_driver') !== 'cloud') {
			return null;
		}
		$persisted = new File($this->key, true);
		if (!$persisted->key) {
			return null;
		}
		return $persisted->is_public() ? 'public' : 'private';
	}

	/**
	 * Save the file record and move to the correct directory based on permissions.
	 */
	function save($debug = false) {
		$old_visibility = $this->_offloaded_visibility();
		$result = parent::save($debug);
		$this->move_to_correct_directory($old_visibility);
		return $result;
	}

	/**
	 * Soft delete and move to restricted directory since deleted files
	 * should not be publicly accessible.
	 */
	function soft_delete() {
		$old_visibility = $this->_offloaded_visibility();
		$result = parent::soft_delete();
		$this->move_to_correct_directory($old_visibility);
		return $result;
	}

	/**
	 * Undelete and re-evaluate which directory the file belongs in.
	 */
	function undelete() {
		$old_visibility = $this->_offloaded_visibility();
		$result = parent::undelete();
		$this->move_to_correct_directory($old_visibility);
		return $result;
	}

	/**
	 * Get URL for a specific image size
	 *
	 * @param string $size_key Size key from ImageSizeRegistry, or 'original' for full size
	 * @param string $format 'short' for relative URL, 'full' for absolute URL
	 * @return string
	 */
	function get_url($size_key='original', $format='short') {

		// Cloud-stored files. PUBLIC files: the world-readable bucket URL goes
		// straight to the browser, no PHP in the loop — stable and cacheable.
		// ($format=='short' is treated as 'full' because the bucket is a
		// different domain.) PRIVATE files must NEVER expose a bucket URL — they
		// fall through to the local /uploads/* pattern, which serve.php
		// gate-streams from the verified-private bucket after is_viewable().
		if ($this->get('fil_storage_driver') === 'cloud') {
			if ($this->is_public()) {
				require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
				$driver = CloudStorageDriverFactory::default();
				if ($driver) {
					return $driver->url($this->remote_key_for($size_key));
				}
				// Falls through to the local URL pattern if cloud is unconfigured.
				// /uploads/* will then 302-redirect once cloud is reconfigured —
				// or 404 if the bucket bytes are gone.
			}
			// Private (or public-but-unconfigured): fall through to the local
			// /uploads/* URL, which routes through serve.php (gated stream for
			// private cloud bytes; the "never url()" rule).
		}

		$settings = Globalvars::get_instance();
		$upload_web_dir = $settings->get_setting('upload_web_dir');

		if ($size_key === 'original') {
			$file_path = $upload_web_dir . '/' . $this->get('fil_name');
		} else {
			$file_path = $upload_web_dir . '/' . $size_key . '/' . $this->get('fil_name');
		}

		// Ensure leading slash
		if ($file_path[0] !== '/') {
			$file_path = '/' . $file_path;
		}

		if ($format == 'full') {
			return LibraryFunctions::get_absolute_url($file_path);
		} else {
			return $file_path;
		}
	}

	function permanent_delete($debug=false){
		if ($this->get('fil_storage_driver') === 'cloud') {
			$this->_permanent_delete_cloud();
		} else {
			$file_path = $this->get_filesystem_path('original');
			if (file_exists($file_path)) {
				@unlink($file_path);
			}
			$this->delete_resized();
		}

		// Clean up all entity_photos rows referencing this file
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "DELETE FROM eph_entity_photos WHERE eph_fil_file_id = ?";
		try {
			$q = $dblink->prepare($sql);
			$q->execute([$this->key]);
		} catch (PDOException $e) {
			// Table may not exist yet during initial setup
			error_log('EntityPhoto cleanup on file delete: ' . $e->getMessage());
		}

		parent::permanent_delete($debug);
		return true;
	}

	/**
	 * The visibility of this file's bytes for store resolution: 'public' when
	 * the file has no restrictions, 'private' otherwise. Mirrors is_public(),
	 * the single source of truth for which bucket a 'cloud' row belongs to.
	 */
	private function _cloud_visibility() {
		return $this->is_public() ? 'public' : 'private';
	}

	/**
	 * The cloud driver for the store matching this file's visibility (public →
	 * public bucket, restricted → verified-private bucket). Falls back to the
	 * unlatched binding so deletes/resizes still resolve a driver while a store
	 * is mid-disable/drain. Null when that store is unconfigured. For a public
	 * file this is exactly the legacy default() path (forVisibility('public')
	 * === default()), so the public-files behaviour is unchanged.
	 */
	private function _cloud_driver() {
		require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
		return CloudStorageDriverFactory::forVisibilityWithFallback($this->_cloud_visibility());
	}

	/**
	 * Delete original + every variant from the bucket. Best-effort: failures
	 * are logged with CLOUD_STORAGE_ORPHAN so the admin can clean up manually,
	 * then the row is deleted anyway. The orphan consumes a few KB-MB until
	 * cleared with `aws s3 rm` or equivalent.
	 */
	private function _permanent_delete_cloud() {
		$driver = $this->_cloud_driver();
		if (!$driver) {
			error_log('CLOUD_STORAGE_ORPHAN: bucket=unknown keys=' . $this->remote_key_for('original') . ' (driver unconfigured)');
			return;
		}

		$keys = [$this->remote_key_for('original')];
		if ($this->is_image()) {
			require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
			foreach (ImageSizeRegistry::get_sizes() as $size_key => $cfg) {
				$keys[] = $this->remote_key_for($size_key);
			}
		}

		$failed_keys = [];
		foreach ($keys as $k) {
			try {
				$driver->delete($k);
			} catch (Exception $e) {
				// One brief retry — bucket DELETEs are rare and almost always succeed.
				try {
					usleep(500000);
					$driver->delete($k);
				} catch (Exception $e2) {
					$failed_keys[] = $k;
				}
			}
		}
		if (!empty($failed_keys)) {
			$bucket = Globalvars::get_instance()->get_setting('cloud_storage_bucket') ?: 'unknown';
			error_log('CLOUD_STORAGE_ORPHAN: bucket=' . $bucket . ' keys=' . implode(',', $failed_keys));
		}
	}

	/**
	 * Delete resized versions of this image
	 *
	 * @param string $size_key Specific size key to delete, or 'all' for all sizes
	 */
	function delete_resized($size_key = 'all'){
		if (!$this->is_image()) {
			return false;
		}

		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();

		if ($this->get('fil_storage_driver') === 'cloud') {
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
			$file_path = $this->get_filesystem_path($key);
			if (file_exists($file_path)) {
				@unlink($file_path);
			}
		}
	}
	
	/**
	 * Generate resized versions using ImageSizeRegistry
	 *
	 * @param string $size_key Specific size key to generate, or 'all' for all registered sizes
	 */
	function resize($size_key='all'){
		if (!$this->is_image()) {
			return false;
		}

		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();

		if ($this->get('fil_storage_driver') === 'cloud') {
			$this->_resize_cloud($size_key, $sizes);
			return;
		}

		$old_path = $this->get_filesystem_path('original');
		if (!file_exists($old_path)) {
			return false;
		}

		// Derive the base directory from where the original actually lives
		$upload_dir = dirname($old_path);

		// Ensure all resize subdirectories exist
		foreach ($sizes as $key => $config) {
			if ($size_key !== 'all' && $size_key !== $key) {
				continue;
			}
			$dir_path = $upload_dir . '/' . $key;
			if (!is_dir($dir_path)) {
				if (mkdir($dir_path, 0777, true)) {
					chmod($dir_path, 0777);
				} else {
					error_log("Failed to create resize directory: $dir_path");
				}
			}
		}

		foreach ($sizes as $key => $config) {
			if ($size_key !== 'all' && $size_key !== $key) {
				continue;
			}
			$new_path = $upload_dir . '/' . $key . '/' . $this->get('fil_name');
			$this->generate_resized($old_path, $new_path, $config['width'], $config['height'], $config['crop'], $config['quality']);
		}
	}

	/**
	 * Re-resize for a cloud-stored file: pull original to temp, generate
	 * variants in temp dir, push each variant back to bucket, drop temp dir.
	 * Used when ImageSizeRegistry gains a new size and existing files need
	 * a new variant (§7).
	 */
	private function _resize_cloud($size_key, $sizes) {
		$driver = $this->_cloud_driver();
		if (!$driver) {
			throw new FileException('Cannot re-resize cloud file: cloud storage driver not configured.');
		}

		$filename = $this->get('fil_name');
		$tmp_dir = sys_get_temp_dir() . '/cloud_resize_' . $this->key . '_' . uniqid();
		if (!mkdir($tmp_dir, 0777, true)) {
			throw new FileException('Failed to create temp dir for cloud resize: ' . $tmp_dir);
		}

		$cleanup = function() use ($tmp_dir) {
			if (!is_dir($tmp_dir)) return;
			$files = glob($tmp_dir . '/{,*/}{,.}*', GLOB_BRACE);
			foreach ($files as $f) {
				if (is_file($f)) @unlink($f);
			}
			foreach (glob($tmp_dir . '/*', GLOB_ONLYDIR) as $d) @rmdir($d);
			@rmdir($tmp_dir);
		};

		try {
			$tmp_original = $tmp_dir . '/' . $filename;
			$driver->get($this->remote_key_for('original'), $tmp_original);

			$content_type = $this->get('fil_type') ?: 'image/jpeg';

			foreach ($sizes as $key => $config) {
				if ($size_key !== 'all' && $size_key !== $key) {
					continue;
				}
				$variant_dir = $tmp_dir . '/' . $key;
				if (!is_dir($variant_dir)) {
					mkdir($variant_dir, 0777, true);
				}
				$variant_path = $variant_dir . '/' . $filename;
				$this->generate_resized($tmp_original, $variant_path, $config['width'], $config['height'], $config['crop'], $config['quality']);
				if (file_exists($variant_path)) {
					$driver->put($variant_path, $this->remote_key_for($key), $content_type);
				}
			}
		} finally {
			$cleanup();
		}
	}

	/**
	 * Generate a single resized version of an image
	 *
	 * @param string $old_path Source image path
	 * @param string $new_path Destination path
	 * @param int $width Target width (0 = auto from height)
	 * @param int $height Target height (0 = auto from width)
	 * @param bool $crop Whether to center-crop to exact dimensions
	 * @param int $quality JPEG quality (1-100)
	 */
	private function generate_resized($old_path, $new_path, $width, $height, $crop, $quality = 85) {
		try {
			$img = new Imagick($old_path);
			$img->setImageCompressionQuality($quality);

			$geo = $img->getImageGeometry();
			$src_w = $geo['width'];
			$src_h = $geo['height'];

			if ($crop && $width > 0 && $height > 0) {
				// Center crop to target aspect ratio
				if (($src_w / $width) < ($src_h / $height)) {
					$img->cropImage(
						$src_w,
						floor($height * $src_w / $width),
						0,
						(($src_h - ($height * $src_w / $width)) / 2)
					);
				} else {
					$img->cropImage(
						ceil($width * $src_h / $height),
						$src_h,
						(($src_w - ($width * $src_h / $height)) / 2),
						0
					);
				}

				// Only downscale after crop, never upscale
				$cropped = $img->getImageGeometry();
				if ($cropped['width'] > $width || $cropped['height'] > $height) {
					$img->thumbnailImage($width, $height, true);
				}
			} else {
				// Aspect-fit resize — only downscale, never upscale
				if ($width > 0 && $height > 0) {
					if ($src_w > $width || $src_h > $height) {
						$img->thumbnailImage($width, $height, true);
					}
				} elseif ($width > 0) {
					if ($src_w > $width) {
						$img->thumbnailImage($width, 0, false);
					}
				} elseif ($height > 0) {
					if ($src_h > $height) {
						$img->thumbnailImage(0, $height, false);
					}
				}
			}

			$img->writeImage($new_path);
		} catch (Exception $e) {
			error_log('File resize generation failed for ' . basename($new_path) . ': ' . $e->getMessage());
		}
	}

	function get_event_sessions(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('SELECT esf_evs_event_session_id FROM esf_event_session_files WHERE esf_fil_file_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->execute();
		
		$results = $q->fetchAll();
		
		//CONVERT INTO A MULTI OBJECT
		$event_sessions = new MultiEventSessions();
		foreach ($results as $result){
			$event_session = new EventSession($result['esf_evs_event_session_id'], TRUE);	
			$event_sessions->add($event_session);
		}
		
		return $event_sessions;
	}

	/**
	 * Pull bucket bytes back to the restricted local directory and flip the
	 * row to fil_storage_driver='local'. Called from move_to_correct_directory()
	 * on a visibility flip, so the bytes land back on disk and the next offload
	 * tick re-places them in the now-correct store.
	 *
	 * $source_visibility names the store the bytes are physically in (the row's
	 * visibility BEFORE the flip), so the driver is resolved against the right
	 * bucket — 'public' (default behaviour) or 'private'. Bytes always land in
	 * the restricted dir; for a private→public flip the next public offload tick
	 * moves them to the public bucket, and in the meantime is_viewable() returns
	 * true for a now-public file, so serving is correct throughout.
	 *
	 * Three explicit phases with strict ordering:
	 *
	 *   Phase 1: pull every key (original + variants) to a temp dir. Failure
	 *            → drop temps, leave bucket+DB unchanged, throw.
	 *   Phase 2: delete keys from bucket (with brief retries). Any delete
	 *            failure after retries → re-PUT successfully-deleted keys
	 *            from temps (best-effort), drop temps, throw.
	 *   Phase 3: copy temps to restricted dir, then BEGIN/UPDATE/COMMIT.
	 *            Failure here → re-PUT all temps to bucket so the row's
	 *            'cloud' flag stays truthful, log CLOUD_STORAGE_PARTIAL_FLIP.
	 *
	 * Invariants: bucket is authoritative until DB commit; temps live until
	 * DB commit so they remain rollback material.
	 */
	private function _pull_back_from_cloud_to_local(string $source_visibility) {
		require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
		// Resolve the driver for the store that physically holds the bytes,
		// working even mid-disable/drain (forVisibilityWithFallback).
		$driver = CloudStorageDriverFactory::forVisibilityWithFallback($source_visibility);
		if (!$driver) {
			throw new FileException('Cannot pull file back from cloud: ' . $source_visibility . ' driver not configured.');
		}

		$filename = $this->get('fil_name');
		$settings = Globalvars::get_instance();
		$restricted_dir = $settings->get_setting('upload_dir');

		// Build the list of keys to pull (original + variants for images).
		$keys = ['original'];
		if ($this->is_image()) {
			require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
			foreach (ImageSizeRegistry::get_sizes() as $size_key => $cfg) {
				$keys[] = $size_key;
			}
		}

		$tmp_dir = sys_get_temp_dir() . '/cloud_pullback_' . $this->key . '_' . uniqid();
		if (!mkdir($tmp_dir, 0777, true)) {
			throw new FileException('Failed to create temp dir for pull-back: ' . $tmp_dir);
		}
		$temp_paths = []; // size_key => temp filesystem path

		$drop_temps = function() use (&$temp_paths, $tmp_dir) {
			foreach ($temp_paths as $p) {
				if (is_file($p)) @unlink($p);
			}
			foreach (glob($tmp_dir . '/*', GLOB_ONLYDIR) as $d) @rmdir($d);
			@rmdir($tmp_dir);
		};

		// PHASE 1 — pull every key to temp.
		try {
			foreach ($keys as $size_key) {
				$tmp_path = ($size_key === 'original')
					? $tmp_dir . '/' . $filename
					: $tmp_dir . '/' . $size_key . '/' . $filename;
				$driver->get($this->remote_key_for($size_key), $tmp_path);
				$temp_paths[$size_key] = $tmp_path;
			}
		} catch (Exception $e) {
			$drop_temps();
			throw new FileException('Phase 1 (pull from bucket) failed: ' . $e->getMessage(), 0, $e);
		}

		// PHASE 2 — delete from bucket with brief retries.
		$deleted_keys = [];
		$retry_delays = [0, 1, 2]; // ~3s window
		foreach ($keys as $size_key) {
			$delete_ok = false;
			$last_err = null;
			foreach ($retry_delays as $delay) {
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
				// Roll back: re-PUT successfully-deleted keys from temps. Best-effort.
				foreach ($deleted_keys as $rb_size) {
					try {
						$driver->put($temp_paths[$rb_size], $this->remote_key_for($rb_size), $this->get('fil_type') ?: 'application/octet-stream');
					} catch (Exception $rb_err) {
						// Swallow — original failure already indicates broader trouble.
					}
				}
				$drop_temps();
				throw new FileException('Phase 2 (bucket delete) failed for ' . $size_key . ': ' . ($last_err ? $last_err->getMessage() : 'unknown'), 0, $last_err);
			}
			$deleted_keys[] = $size_key;
		}

		// PHASE 3 — copy temps to restricted dir, then commit DB row.
		$copied_paths = []; // restricted-dir paths actually written
		try {
			if (!is_dir($restricted_dir)) {
				mkdir($restricted_dir, 0777, true);
			}
			foreach ($keys as $size_key) {
				$dest = ($size_key === 'original')
					? $restricted_dir . '/' . $filename
					: $restricted_dir . '/' . $size_key . '/' . $filename;
				$dest_parent = dirname($dest);
				if (!is_dir($dest_parent)) {
					mkdir($dest_parent, 0777, true);
				}
				if (!copy($temp_paths[$size_key], $dest)) {
					throw new FileException('Phase 3 (local copy) failed for ' . $size_key);
				}
				$copied_paths[] = $dest;
			}

			$dbconnector = DbConnector::get_instance();
			$dblink = $dbconnector->get_db_link();
			$dblink->beginTransaction();
			try {
				$q = $dblink->prepare("UPDATE fil_files SET fil_storage_driver = 'local' WHERE fil_file_id = ?");
				$q->execute([$this->key]);
				$dblink->commit();
			} catch (PDOException $e) {
				$dblink->rollBack();
				throw new FileException('Phase 3 (DB commit) failed: ' . $e->getMessage(), 0, $e);
			}

			// Refresh in-memory state to match committed DB.
			$this->set('fil_storage_driver', 'local', false);
		} catch (Exception $e) {
			// Worst case: bucket empty, DB still 'cloud'. Best-effort: clean up
			// any partial restricted-dir writes, then re-PUT all temps to bucket
			// so the row's 'cloud' flag stays truthful.
			foreach ($copied_paths as $p) @unlink($p);
			foreach ($keys as $size_key) {
				try {
					$driver->put($temp_paths[$size_key], $this->remote_key_for($size_key), $this->get('fil_type') ?: 'application/octet-stream');
				} catch (Exception $reput) {
					// If re-PUT fails too, the row is genuinely broken (DB says
					// 'cloud', bucket empty). Logged below; manual recovery required.
				}
			}
			$drop_temps();
			error_log('CLOUD_STORAGE_PARTIAL_FLIP: fil=' . $this->key . ' name=' . $filename . ' err=' . $e->getMessage());
			throw $e;
		}

		$drop_temps();
	}

	// authenticate_write() is inherited from SystemBase — it applies the shared
	// owner-or-admin rule (is_owner_or_admin), the same rule is_viewable() uses for
	// private files. No File-specific override is needed.

	/**
	 * Content-visibility gate for the file-serving path (serve.php) — NOT API row
	 * authorization. Returns bool: may this session view the file? For a private
	 * file (fil_private) the rule is owner-or-admin — the identical rule
	 * authenticate_read uses for the record, via the shared is_owner_or_admin()
	 * helper. Otherwise the four restriction columns apply (min-permission, group
	 * membership, event registration, tier gating). fil_private is an alternative
	 * to those columns, not combined with them.
	 */
	function is_viewable($session){
		if(!$session){
			throw new SystemDisplayablePermanentError("Session is not present to authenticate.");
		}

		if($this->get('fil_delete_time')){
			return false;
		}

		if ($this->_is_private()) {
			return $this->is_owner_or_admin($session->get_user_id(), $session->get_permission());
		}

		if($this->get('fil_min_permission')){
			if (!$session->get_permission()) {
				return false;
			}
			if ($session->get_permission() < $this->get('fil_min_permission')){
				return false;
			}
	
		}	
	
		if ($group_id = $this->get('fil_grp_group_id')){
			require_once(PathHelper::getIncludePath('data/groups_class.php'));
			//CHECK TO SEE IF USER IS IN AUTHORIZED GROUP
			$group = new Group($group_id, TRUE);
			if(!$group->is_member_in_group($session->get_user_id())){
				return false;
			}
		}
		
		if ($event_id = $this->get('fil_evt_event_id')){
			require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
			//CHECK TO SEE IF USER IS IN AUTHORIZED EVENT
			$searches['user_id'] = $session->get_user_id();
			$searches['event_id'] = $event_id;
			$searches['expired'] = false;
			$event_registrations = new MultiEventRegistrant(
				$searches,
				NULL, //array('event_id'=>'DESC'),
				NULL,
				NULL);
			$numeventsregistrations = $event_registrations->count_all();	

			if(!$numeventsregistrations){
				return false;
			}
		}

		// Tier gating check
		if ($this->get('fil_tier_min_level')) {
			$tier_access = $this->authenticate_tier($session);
			if (!$tier_access['allowed']) {
				return false;
			}
		}

		return true;
	}

}

class MultiFile extends SystemMultiBase {
	protected static $model_class = 'File';

	function get_file_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $file) {
			$items[$file->key] = '('.$file->key.') '.$file->get('fil_title');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}

	function get_image_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $file) {
			$items[$file->key] = '<span class="dropimagewidth"><img loading="lazy" src="'.$file->get_url('avatar').'"></span>('.$file->key.') '.$file->get('fil_title');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}
	
	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['fil_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['group_id'])) {
			$filters['fil_grp_group_id'] = [$this->options['group_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['event_id'])) {
			$filters['fil_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['deleted'])) {
			$filters['fil_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['picture'])) {
			if ($this->options['picture']) {
				$filters['fil_type'] = "LIKE 'image/%'";
			} else {
				$filters['fil_type'] = "NOT LIKE 'image%'";
			}
		}

		if (isset($this->options['filename_like'])) {
			$filters['fil_name'] = 'ILIKE \'%'.$this->options['filename_like'].'%\'';
		}

		return $this->_get_resultsv2('fil_files', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
