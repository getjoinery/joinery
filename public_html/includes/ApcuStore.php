<?php
/**
 * Reads from APCu that never load a class.
 *
 * The web user writes APCu, and apcu_fetch() rebuilds every object an entry
 * holds, autoloading its class if it is not loaded yet, before the caller
 * sees the value. So code that ran once in a worker could plant an object
 * under a key a later request reads, and its magic methods would run in that
 * request, a member's vault unlock included (specs/unseal_daemon.md C4). A
 * planted object of a class whose file loads Composer would also switch on
 * the whole vendor tree mid-fetch.
 *
 * Nothing the platform stores in APCu is an object: every value is a scalar
 * or an array of them. fetch() reads with an autoloader at the front of the
 * queue that refuses every class, so a planted object of a class not already
 * loaded is never built, and then refuses any value holding an object at
 * all. A refused entry is deleted, logged, and read as a miss.
 *
 * What it cannot stop: an object of a class this request has already loaded
 * is rebuilt (and its __wakeup run) before the value can be looked at.
 *
 * Every apcu_fetch() in production code goes through here; a test fails on
 * any other (tests/security/stored_object_guards_test.php).
 *
 * @version 1.0
 */
final class ApcuStore {

	/** Like apcu_fetch($key, $success) for one key, refusing any object. */
	public static function fetch(string $key, &$success = null) {
		static $guard = null;
		if ($guard === null) {
			$guard = static function ($class) {
				throw new RuntimeException('a class load (' . $class . ') during an APCu read');
			};
		}
		$success = false;
		$refused = null;
		$value = false;
		spl_autoload_register($guard, true, true);
		try {
			$value = @apcu_fetch($key, $ok);
			$success = (bool)$ok;
		} catch (Throwable $e) {
			$refused = $e->getMessage();
		} finally {
			spl_autoload_unregister($guard);
		}
		if ($refused === null && $success && self::holdsObject($value)) {
			$refused = 'an object in the value';
		}
		if ($refused !== null) {
			$value = null;
			@apcu_delete($key);
			error_log('[ApcuStore] refused the APCu entry ' . json_encode($key) . ': ' . $refused . '; it is deleted and reads as a miss');
			$success = false;
			return false;
		}
		return $success ? $value : false;
	}

	private static function holdsObject($value, int $depth = 0): bool {
		if (is_object($value)) {
			return true;
		}
		if (is_array($value) && $depth < 64) {
			foreach ($value as $item) {
				if (self::holdsObject($item, $depth + 1)) {
					return true;
				}
			}
		}
		return false;
	}
}
