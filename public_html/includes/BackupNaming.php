<?php
/**
 * BackupNaming — what counts as a backup, and what a given artifact is.
 *
 * This used to be spelled out separately everywhere that had to decide: the
 * management API's local listing, the node Backups tab's restore button, the
 * job builder's globs, the result parser. They drifted, which is the expensive
 * kind of bug — adding an archive shape made encrypted project backups
 * invisible to the listing and unrestorable from the UI, while everything
 * carried on reporting success.
 *
 * One list, consulted by everyone.
 *
 * @version 1.1 - the objects index beside a standalone archive ({archive}.objects.json.gz, same
 *                stamp): named, recognised, and mapped back to its archive, so listings and
 *                retention file it with the archive and its envelope. It is not a backup artifact
 *                and never a restore button.
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));

class BackupNaming {

	/**
	 * Recognized artifact suffixes, longest first.
	 *
	 * Order matters: '.sql.gz' is a suffix of '.sql.gz.enc', so a shortest-first
	 * match would classify every encrypted dump as plaintext.
	 */
	const EXTENSIONS = [
		'.tar.gz.enc',
		'.sql.gz.enc',
		'.tar.gz',
		'.sql.gz',
	];

	/** Suffixes that mean "a whole project", as opposed to a database dump. */
	const PROJECT_EXTENSIONS = ['.tar.gz.enc', '.tar.gz'];

	/** Where backups live on a node. */
	const BACKUP_DIR = '/backups';

	/** Glob patterns for a directory, for PHP's glob(). */
	public static function glob_patterns($dir = self::BACKUP_DIR) {
		$dir = rtrim((string)$dir, '/');
		$out = [];
		foreach (self::EXTENSIONS as $ext) {
			$out[] = $dir . '/*' . $ext;
		}
		return $out;
	}

	/** The same patterns as a space-separated string, for a shell command. */
	public static function shell_glob($dir = self::BACKUP_DIR) {
		return implode(' ', self::glob_patterns($dir));
	}

	/** The suffix this name ends with, or '' if it is not a backup artifact. */
	public static function extension_of($name) {
		$name = (string)$name;
		foreach (self::EXTENSIONS as $ext) {
			if (substr($name, -strlen($ext)) === $ext) {
				return $ext;
			}
		}
		return '';
	}

	/** Is this a backup artifact? Sidecars are not — they describe one. */
	public static function is_backup($name) {
		return self::extension_of($name) !== '';
	}

	/** Is this artifact encrypted? */
	public static function is_encrypted($name) {
		return substr(self::extension_of($name), -4) === '.enc';
	}

	/** Is this the envelope beside an artifact rather than an artifact? */
	public static function is_sidecar($name) {
		return BackupEnvelope::is_sidecar_name($name);
	}

	/**
	 * What restoring this artifact would do: 'project', 'database', or '' when
	 * the name is not a backup at all. Callers use '' to mean "offer no restore
	 * button", which is safer than guessing.
	 */
	public static function restore_type($name) {
		$ext = self::extension_of($name);
		if ($ext === '') {
			return '';
		}
		return in_array($ext, self::PROJECT_EXTENSIONS, true) ? 'project' : 'database';
	}

	/** Suffix of the objects index that goes with a standalone archive. */
	const INDEX_SUFFIX = '.objects.json.gz';

	/** The objects index name for a standalone archive: the stamp survives, the archive suffix does not. */
	public static function index_for_archive($archive_name) {
		$ext = self::extension_of($archive_name);
		$base = ($ext !== '') ? substr((string)$archive_name, 0, -strlen($ext)) : (string)$archive_name;
		return $base . self::INDEX_SUFFIX;
	}

	/** Is this the objects index beside a standalone archive? */
	public static function is_index($name) {
		return substr((string)$name, -strlen(self::INDEX_SUFFIX)) === self::INDEX_SUFFIX;
	}

	/** The archive stem an index belongs to (name without its archive suffix), or '' if this is not an index. */
	public static function archive_stem_for_index($name) {
		if (!self::is_index($name)) {
			return '';
		}
		return substr((string)$name, 0, -strlen(self::INDEX_SUFFIX));
	}

	/** The artifact name an envelope belongs to, or '' if this is not an envelope. */
	public static function artifact_for_sidecar($name) {
		if (!self::is_sidecar($name)) {
			return '';
		}
		return substr((string)$name, 0, -strlen(BackupEnvelope::SIDECAR_SUFFIX));
	}

	/** Every backup artifact in a directory, newest first. Sidecars excluded. */
	public static function list_dir($dir = self::BACKUP_DIR) {
		$found = [];
		foreach (self::glob_patterns($dir) as $pattern) {
			foreach (glob($pattern) ?: [] as $path) {
				if (is_file($path)) {
					$found[$path] = filemtime($path);
				}
			}
		}
		arsort($found);
		return array_keys($found);
	}
}
