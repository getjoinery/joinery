<?php
/**
 * One parse of a JSON manifest per process.
 *
 * A request reads every plugin's plugin.json more than once: PluginHelper
 * for the plugin itself, SettingsDeclarations for the settings it declares.
 * The files are large and parsing them is most of the cost, so every reader
 * asks here and the second one gets the array the first one parsed.
 *
 * A file is parsed again when its modification time or size differs from
 * what was parsed, checked with one stat per read. A file written within the
 * last two seconds is never kept: a second write inside the same second and
 * of the same size could not be told from the first.
 *
 * Nothing is kept between processes.
 *
 * @version 1.0
 */
final class ManifestCache {

	/** read()'s $error when there is no such file. */
	const MISSING = 'missing';

	/** path => [mtime, size, parsed array] */
	private static $parsed = array();

	/**
	 * The manifest at $path as an array, or null when the file is missing
	 * or does not hold a JSON object or list. $error then says which:
	 * MISSING, or what the parser said.
	 */
	public static function read(string $path, &$error = null): ?array {
		$error = null;
		clearstatcache(true, $path);
		$stat = @stat($path);
		if ($stat === false || !is_file($path)) {
			unset(self::$parsed[$path]);
			$error = self::MISSING;
			return null;
		}
		$kept = self::$parsed[$path] ?? null;
		if ($kept !== null && $kept[0] === $stat['mtime'] && $kept[1] === $stat['size']) {
			return $kept[2];
		}
		$data = json_decode((string)file_get_contents($path), true);
		if (!is_array($data)) {
			unset(self::$parsed[$path]);
			$error = json_last_error() === JSON_ERROR_NONE ? 'it does not hold a JSON object' : json_last_error_msg();
			return null;
		}
		if ((time() - $stat['mtime']) >= 2) {
			self::$parsed[$path] = array($stat['mtime'], $stat['size'], $data);
		} else {
			unset(self::$parsed[$path]);
		}
		return $data;
	}
}
