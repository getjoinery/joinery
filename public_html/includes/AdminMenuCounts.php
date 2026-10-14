<?php
/**
 * AdminMenuCounts — a number beside an admin menu entry: how many things wait
 * on its page (Server Manager → Incidents: how many need a person).
 *
 * A subsystem registers a counter for a menu slug from its plugin bootstrap,
 * as it registers a header notice with AdminNotices; the menu asks here for
 * each entry it draws and never learns what a count means. A counter returns
 * an int, and 0 shows nothing, so a quiet menu is the normal state.
 *
 * Counters run on every admin page load, so they read STORED facts and never
 * probe. A counter that throws is logged and shows nothing: a menu never
 * fails to draw because a count could not be had.
 *
 * Core's own counters are fixed (coreCounters()); a plugin's come from its
 * bootstrap.
 *
 * @version 1.1 - core counters: open content reports
 * @version 1.0
 */
class AdminMenuCounts {
	/** @var array<string, callable> counters, by menu slug */
	private static $counters = array();

	/** Core counters, by menu slug. Each is a callable returning an int. */
	private static function coreCounters(): array {
		return array(
			// Content members reported, waiting for an admin to look.
			'content-reports' => array('ContentReport', 'open_count'),
		);
	}

	/** Register the counter for a menu slug. Registering again replaces it. */
	public static function register(string $slug, callable $counter): void {
		self::$counters[$slug] = $counter;
	}

	/**
	 * The count for every slug that has a counter: slug => int, zeros left
	 * out. Plugin bootstraps are pulled in first, as AdminNotices does.
	 */
	public static function all(): array {
		if (class_exists('PluginBootstraps')) {
			try {
				PluginBootstraps::load();
			} catch (\Throwable $e) {
				error_log('[AdminMenuCounts] plugin bootstraps failed to load: ' . $e->getMessage());
			}
		}
		$out = array();
		foreach (self::coreCounters() + self::$counters as $slug => $counter) {
			try {
				$n = (int)call_user_func($counter);
			} catch (\Throwable $e) {
				error_log('[AdminMenuCounts] count for "' . $slug . '" failed: ' . $e->getMessage());
				continue;
			}
			if ($n > 0) {
				$out[$slug] = $n;
			}
		}
		return $out;
	}

	/** Forget every registration (tests only). */
	public static function resetForTests(): void {
		self::$counters = array();
	}
}
?>
