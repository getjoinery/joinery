<?php
/**
 * SignalBus — the platform's canonical "something happened" primitive.
 *
 * `SignalBus::dispatch($signal, $payload)` records a structured fact and fans
 * it out to every registered subscriber. The payload is a flat array of
 * JSON-serializable scalars (ids, names, ISO-8601 UTC times) — never objects
 * or pre-rendered HTML — so every consumer (notifications, webhooks, email
 * workflows, plugin handlers) derives its own presentation from it.
 *
 * Signals are declared in `signals.json` (core) and the `signals` key of plugin
 * manifests. Subscribers are registered in `signal_subscribers.json` (core) and
 * the `signalSubscribers` key of plugin manifests. The bus reads only a signal's
 * identity and payload schema; it never looks at the per-consumer `notify` block
 * (only Notify does).
 *
 * See docs/signals.md.
 *
 * @version 1.0
 */

class SignalBus {

	/** Per-request cache of the merged signal catalog. */
	private static $signals_cache = null;

	/** Per-request cache of the merged subscriber registry. */
	private static $subscribers_cache = null;

	/** Recursion-depth guard: a handler that re-dispatches cannot run away. */
	private static $depth = 0;
	const MAX_DEPTH = 10;

	/**
	 * Dispatch a signal. Never throws into the caller: the whole dispatch and
	 * each subscriber are wrapped, so one failing subscriber is logged and the
	 * rest still run. Where the producing operation is transactional (checkout),
	 * call this only AFTER the transaction commits.
	 *
	 * @param string $signal   declared signal name, e.g. 'purchase.completed'
	 * @param array  $payload  flat array of JSON-serializable values
	 */
	public static function dispatch($signal, array $payload = array()) {
		try {
			self::_dispatch($signal, $payload);
		} catch (Throwable $e) {
			error_log('[SignalBus] dispatch(' . $signal . ') failed: ' . $e->getMessage());
		}
	}

	private static function _dispatch($signal, array $payload) {
		// Recursion guardrail — no v1 subscriber re-dispatches, but a future one
		// that does cannot hang the request with unbounded recursion.
		if (self::$depth >= self::MAX_DEPTH) {
			error_log('[SignalBus] recursion limit (' . self::MAX_DEPTH . ') hit; dropping ' . $signal);
			return;
		}
		self::$depth++;
		try {
			if (self::_debug_enabled()) {
				self::_debug_log($signal, $payload);
			}

			$catalog = self::signals();
			if (!isset($catalog[$signal])) {
				// Forgiving: still fan out (wildcard subscribers must not lose a
				// signal over a missing catalog entry). The catalog is the
				// contract for consumers enumerating signals, not a dispatch gate.
				error_log('[SignalBus] dispatch() for undeclared signal: ' . $signal);
			}

			foreach (self::subscribers() as $name => $sub) {
				if (!self::_matches($signal, isset($sub['signals']) ? $sub['signals'] : array())) {
					continue;
				}
				try {
					self::_invoke($sub, $signal, $payload);
				} catch (Throwable $e) {
					error_log('[SignalBus] subscriber ' . $name . ' failed for ' . $signal . ': ' . $e->getMessage());
				}
			}
		} finally {
			self::$depth--;
		}
	}

	/**
	 * Lazily require the subscriber's file and call its static handler. The file
	 * is required only when one of the subscriber's signals actually fires.
	 */
	private static function _invoke(array $sub, $signal, array $payload) {
		$path = isset($sub['_resolved_file']) ? $sub['_resolved_file'] : null;
		if ($path && is_file($path)) {
			require_once($path);
		}

		$class  = isset($sub['class']) ? $sub['class'] : null;
		$method = isset($sub['method']) ? $sub['method'] : 'handle';

		if (!$class || !class_exists($class) || !method_exists($class, $method)) {
			error_log('[SignalBus] subscriber handler not callable: '
				. ($class ? $class : '?') . '::' . $method);
			return;
		}

		call_user_func(array($class, $method), $signal, $payload);
	}

	/**
	 * Exact-name or '*' match. Prefix wildcards (subscription.*) are a deferred
	 * matcher enhancement — see specs/signal_bus.md Future enhancements.
	 */
	private static function _matches($signal, $patterns) {
		if (!is_array($patterns)) {
			return false;
		}
		foreach ($patterns as $pattern) {
			if ($pattern === '*' || $pattern === $signal) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Merged signal catalog: core `signals.json` plus the `signals` key of every
	 * active plugin's manifest. Cached for the duration of the request. Plugin
	 * entries override core entries of the same name.
	 *
	 * @return array  map of signal_name => declaration array
	 */
	public static function signals() {
		if (self::$signals_cache !== null) {
			return self::$signals_cache;
		}

		$merged = array();

		$core_file = PathHelper::getIncludePath('signals.json');
		if (is_file($core_file)) {
			$decoded = json_decode(file_get_contents($core_file), true);
			if (is_array($decoded)) {
				$merged = $decoded;
			} else {
				error_log('[SignalBus] signals.json is not valid JSON.');
			}
		}

		try {
			foreach (PluginHelper::getActivePlugins() as $plugin) {
				$signals = $plugin->get('signals', null);
				if (is_array($signals)) {
					foreach ($signals as $name => $meta) {
						$merged[$name] = $meta;
					}
				}
			}
		} catch (Exception $e) {
			error_log('[SignalBus] plugin signal catalog read failed: ' . $e->getMessage());
		}

		self::$signals_cache = $merged;
		return $merged;
	}

	/**
	 * Merged subscriber registry: core `signal_subscribers.json` (in file order)
	 * then the `signalSubscribers` key of every active plugin (in load order).
	 * Each declaration is normalized with a `_resolved_file` absolute path.
	 * Cached for the duration of the request.
	 *
	 * @return array  ordered map of subscriber_name => declaration array
	 */
	public static function subscribers() {
		if (self::$subscribers_cache !== null) {
			return self::$subscribers_cache;
		}

		$merged = array();

		$core_file = PathHelper::getIncludePath('signal_subscribers.json');
		if (is_file($core_file)) {
			$decoded = json_decode(file_get_contents($core_file), true);
			if (is_array($decoded)) {
				foreach ($decoded as $sub_name => $sub) {
					if (isset($sub['file'])) {
						$sub['_resolved_file'] = PathHelper::getIncludePath($sub['file']);
					}
					$merged[$sub_name] = $sub;
				}
			} else {
				error_log('[SignalBus] signal_subscribers.json is not valid JSON.');
			}
		}

		try {
			foreach (PluginHelper::getActivePlugins() as $plugin) {
				$subs = $plugin->get('signalSubscribers', null);
				if (is_array($subs)) {
					foreach ($subs as $sub_name => $sub) {
						if (isset($sub['file'])) {
							// Plugin subscriber files are relative to the plugin dir.
							$sub['_resolved_file'] = $plugin->getIncludePath($sub['file']);
						}
						$merged[$sub_name] = $sub;
					}
				}
			}
		} catch (Exception $e) {
			error_log('[SignalBus] plugin subscriber registry read failed: ' . $e->getMessage());
		}

		self::$subscribers_cache = $merged;
		return $merged;
	}

	private static function _debug_enabled() {
		try {
			return (bool)Globalvars::get_instance()->get_setting('signal_bus_debug');
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Debug logging: record the dispatch and enforce the spec's most important
	 * discipline — payloads stay JSON-serializable for webhooks/workflows.
	 */
	private static function _debug_log($signal, array $payload) {
		$json = json_encode($payload);
		if ($json === false) {
			error_log('[SignalBus] NON-SERIALIZABLE payload for ' . $signal
				. ': ' . json_last_error_msg());
			$json = '(unserializable)';
		}
		error_log('[SignalBus] dispatch ' . $signal . ' ' . $json);
	}
}
