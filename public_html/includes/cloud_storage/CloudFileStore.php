<?php
/**
 * CloudFileStore — one file store target, ready to write to: which target it
 * is, the folder its objects go under, and a driver for it.
 *
 * The file store is a backup target row with bkt_purpose 'files', chosen by
 * the site setting file_store_target_id (specs/storage_targets.md §7). A row
 * offloaded to it records the target and the full object key it was written
 * under, so every later read, delete or move follows the row, never the
 * store chosen now. This object is what a write needs: the key a name is
 * stored under is composed here, once, from the target's folder.
 *
 * @version 1.0 - specs/storage_targets.md WP6
 */

class CloudFileStore {

	/** @var int */
	public $target_id;
	/** @var string the target's folder, no trailing slash */
	public $prefix;
	/** @var CloudStorageDriver */
	public $driver;

	public function __construct(int $target_id, string $prefix, CloudStorageDriver $driver) {
		$this->target_id = $target_id;
		$this->prefix = trim($prefix, '/');
		$this->driver = $driver;
	}

	/** The full object key a relative name is stored under in this store. */
	public function key(string $name): string {
		return ($this->prefix !== '' ? $this->prefix . '/' : '') . ltrim($name, '/');
	}

	/**
	 * The folder a new file store uses when none is given: this site's name
	 * (site_template), so several Joinery sites can share one bucket. It is
	 * read once, when the store is set up; changing site_template later moves
	 * nothing, because every row records its own key.
	 */
	public static function default_prefix(): string {
		$template = strtolower((string)Globalvars::get_instance()->get_setting('site_template'));
		$folder = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9-]/', '-', $template)), '-');
		return $folder !== '' ? $folder : 'joinery-files';
	}
}
