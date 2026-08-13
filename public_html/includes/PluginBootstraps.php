<?php
/**
 * PluginBootstraps - the one loader for every plugin's load point
 * (docs/plugin_developer_guide.md § Bootstrap).
 *
 * A plugin declares a top-level `bootstrap` key in plugin.json; the file runs
 * once per request, lazily, the first time any code path needs registered
 * hooks live — a File decrypt hook, an upload purpose, a window-cap or policy
 * callable, a deferred-work consumer, a rotation callback. Static registries
 * reset at the start of every request (a fresh PHP execution context even
 * under php-fpm worker reuse), so every registry's read side calls load()
 * (usually through VaultUnlock::loadConsumerBootstraps(), which delegates
 * here) before consulting its callbacks.
 *
 * Core consumers (vault_consumers.json entries, which have no plugin.json)
 * load through the same loop, interleaved with plugins by their declared
 * `order` — mail parsing must precede AI judging, and declaration order is
 * the one deterministic contract. A plugin with no `vaultConsumer` block
 * carries the default order and so loads after every consumer that declared
 * one.
 *
 * THE INVARIANT: plugin bootstraps load through here and nowhere else. Each
 * load is wrapped in VaultConsumers::beginLoading()/endLoading(), so the
 * registrations a bootstrap makes attribute to it — which is what lets a
 * missing vault obligation be reported by name, and refused by the rotation
 * guard, instead of surfacing later as silent data loss. A bootstrap pulled
 * in by some other require_once registers under no consumer; the
 * already-included check below makes that failure name its actual cause. (A
 * plugin without a `vaultConsumer` block simply accrues no obligations from
 * the attribution.)
 *
 * A declared bootstrap that cannot load is never fatal, but it is never
 * silent either: the consumer lands in notLoaded(), which
 * VaultUnlock::capsForUser() treats as "the strictest window policy may be
 * missing" and fails CLOSED to the Fortress caps.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/VaultConsumers.php'));

class PluginBootstraps {

	/** @var bool guards load() to once per request. */
	private static $loaded = false;

	/** @var string[] declared bootstraps that never ran (missing on disk, or no path). */
	private static $not_loaded = array();

	/**
	 * Load every registered bootstrap — core consumers and active plugins — in
	 * declared order. Lazy and idempotent; every registry read site may call it.
	 */
	public static function load(): void {
		if (self::$loaded) {
			return;
		}
		self::$loaded = true;

		foreach (VaultConsumers::registered() as $name => $entry) {
			$path = (string)$entry['path'];
			if ($path === '' || !file_exists($path)) {
				error_log('[VaultConsumers] consumer "' . $name . '" declares a bootstrap that does not exist: '
					. ($path === '' ? '(no top-level bootstrap key in plugin.json)' : $path)
					. ' — unlock windows fail closed to the Fortress caps until it is restored.');
				self::$not_loaded[] = $name;
				continue;
			}
			$real = realpath($path);
			if ($real !== false && in_array($real, get_included_files(), true)) {
				error_log('[VaultConsumers] consumer "' . $name . '" was already included outside '
					. 'the plugin bootstrap loader; its hook registrations are not attributed to it and any '
					. 'obligation it declares will read as unmet.');
				continue;
			}
			VaultConsumers::beginLoading($name);
			try {
				require_once($path);
			} finally {
				VaultConsumers::endLoading();
			}
		}

		self::warnOnUnmetCacheObligations();
	}

	/**
	 * The consumers whose declared bootstrap never ran this request. Everything
	 * such a bootstrap would have registered silently does not exist, so
	 * VaultUnlock::capsForUser() fails closed while this is non-empty.
	 */
	public static function notLoaded(): array {
		return self::$not_loaded;
	}

	/**
	 * A `caches: true` consumer that registered no onWipe holds member plaintext
	 * outside the sealed columns with nothing to clear it, so its content
	 * survives the lock, the logout and the session while the lock chip claims
	 * otherwise. That is worth saying loudly — and it is all this can do.
	 *
	 * It deliberately does NOT refuse, unlike its `reseals` twin. The only moment
	 * a missing wipe callback becomes observable is window close, and refusing to
	 * close a window would leave the vault OPEN: a stale plaintext file traded for
	 * a live unlocked vault, which is strictly worse than the thing being guarded.
	 */
	private static function warnOnUnmetCacheObligations(): void {
		foreach (VaultConsumers::unmetObligations() as $name => $missing) {
			if (in_array(VaultConsumers::OBLIGATION_CACHES, $missing, true)) {
				error_log('[VaultConsumers] consumer "' . $name . '" declares caches: true but registered no '
					. 'onWipe callback — its in-window plaintext outlives the lock.');
			}
		}
	}

	/** Let the bootstraps load again. Tests only. */
	public static function resetForTests(): void {
		self::$loaded = false;
		self::$not_loaded = array();
	}
}
?>
