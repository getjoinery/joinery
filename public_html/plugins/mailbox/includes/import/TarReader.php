<?php
/**
 * TarReader - .tar and .tar.gz, the shape most Unix-side export scripts produce.
 *
 * A gzipped tar is sequential-access only: there is no index and no way to seek
 * to a member, so unlike a zip it genuinely has to be expanded before anything
 * can be read out of it twice. The run holds a working area for its whole life
 * and it is removed when the run finishes.
 *
 * Once expanded, a tar IS a folder of saved messages, so everything after the
 * extraction step is EmlDirectoryReader's walk.
 *
 * .tar.bz2 is deliberately absent: the bz2 extension is not present on this
 * platform, and .zip plus .tar.gz cover every export tool in scope.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/EmlDirectoryReader.php'));

class TarReader extends EmlDirectoryReader {

	/** Marker written once the expansion finished, so a resumed run trusts it. */
	const COMPLETE_MARKER = '.joinery-extracted';

	public static function key(): string { return 'tar'; }
	public static function label(): string { return 'Tar archive'; }

	public static function sniff(string $path, string $filename): bool {
		if (!is_file($path)) {
			return false;
		}
		$name = strtolower($filename);
		if (preg_match('/\.(tar|tar\.gz|tgz)$/', $name)) {
			return true;
		}
		// A gzip stream whose name says nothing: accept it only if it unpacks as a
		// tar, which PharData decides for us at prepare() time.
		$handle = @fopen($path, 'rb');
		if (!$handle) {
			return false;
		}
		$magic = (string)fread($handle, 2);
		fclose($handle);
		return $magic === "\x1f\x8b" && preg_match('/\.gz$/', $name);
	}

	/**
	 * Expand into the working area, once. A resumed run finds the marker and skips
	 * straight to reading, so re-extraction never costs a second copy.
	 */
	public function prepare(string $path, string $workDir): string {
		$this->work_dir = $workDir;
		$target = rtrim($workDir, '/') . '/tree';
		$marker = $target . '/' . self::COMPLETE_MARKER;

		if (is_file($marker)) {
			return $target;
		}
		if (!is_dir($target) && !@mkdir($target, 0777, true) && !is_dir($target)) {
			throw new RuntimeException('The working area could not be created.');
		}

		try {
			$archive = new PharData($path);
			// Extract everything, overwriting a partial expansion from a run that
			// died mid-extract — half a tree is worse than doing it again.
			$archive->extractTo($target, null, true);
		} catch (Throwable $e) {
			throw new RuntimeException('The tar archive could not be expanded: ' . $e->getMessage());
		}

		@file_put_contents($marker, gmdate('c'));
		@chmod($marker, 0666);
		return $target;
	}
}
?>
