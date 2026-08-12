<?php
/**
 * DirectKinds - which payloads this instance serves on Joinery Direct.
 *
 * Registration is declarative, the same idiom the platform already uses for
 * settings, menus and signals. Core kinds are declared in `direct_kinds.json`;
 * a plugin declares its own under `directKinds` in `plugin.json`:
 *
 *   "directKinds": {
 *     "chat": { "handler": "includes/ChatDirectHandler.php", "gate": "contacts" }
 *   }
 *
 * The string shorthand `"chat": "includes/ChatDirectHandler.php"` is equivalent
 * and means the handler supplies its own gate.
 *
 * The registry is INSTANCE CONFIGURATION, readable without loading plugin code,
 * so "does this instance serve kind X" is answerable the moment a preflight
 * arrives. A kind whose plugin is deactivated is simply absent from the served
 * set, so its preflights refuse exactly like an unknown kind's — the
 * partially-upgraded-federation behavior falls out with no special case, and the
 * refusal is request-level, issued before any handler code runs.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class DirectKinds {

	/** The canned gate name a kind declares instead of writing its own. */
	const GATE_CONTACTS = 'contacts';

	/** @var array<string,array>|null request-scoped registry cache */
	private static $registry = null;

	/** @var array<string,DirectKindHandler> instantiated handlers, per request */
	private static $handlers = array();

	/**
	 * kind => ['handler' => path, 'gate' => name|'', 'plugin' => name|'']
	 *
	 * Core entries first, plugin entries after, so a plugin can deliberately
	 * take over a kind name the same way it can override a core signal.
	 */
	public static function served(): array {
		if (self::$registry !== null) {
			return self::$registry;
		}

		$merged = array();

		$core_file = PathHelper::getIncludePath('direct_kinds.json');
		if (is_file($core_file)) {
			$decoded = json_decode((string)file_get_contents($core_file), true);
			if (is_array($decoded)) {
				foreach ($decoded as $kind => $declaration) {
					$normalized = self::normalize($kind, $declaration, '');
					if ($normalized !== null) {
						$merged[$normalized['kind']] = $normalized;
					}
				}
			} else {
				error_log('[DirectKinds] direct_kinds.json is not valid JSON.');
			}
		}

		try {
			foreach (PluginHelper::getActivePlugins() as $plugin_name => $plugin) {
				$kinds = $plugin->get('directKinds', null);
				if (!is_array($kinds)) {
					continue;
				}
				foreach ($kinds as $kind => $declaration) {
					$normalized = self::normalize($kind, $declaration, (string)$plugin_name);
					if ($normalized !== null) {
						$merged[$normalized['kind']] = $normalized;
					}
				}
			}
		} catch (\Throwable $e) {
			error_log('[DirectKinds] plugin kind registry read failed: ' . $e->getMessage());
		}

		return self::$registry = $merged;
	}

	/** Just the names, which is all the relay ever needs (it compares opaque strings). */
	public static function servedNames(): array {
		return array_keys(self::served());
	}

	public static function isServed(string $kind): bool {
		return array_key_exists(self::normalizeName($kind), self::served());
	}

	/** The declaration for one kind, or null when this instance does not serve it. */
	public static function declaration(string $kind): ?array {
		$served = self::served();
		$kind = self::normalizeName($kind);
		return $served[$kind] ?? null;
	}

	/** True when this kind uses the framework's canned contact gate. */
	public static function usesContactGate(string $kind): bool {
		$declaration = self::declaration($kind);
		return $declaration !== null && $declaration['gate'] === self::GATE_CONTACTS;
	}

	/**
	 * The handler for one kind, instantiated once per request.
	 *
	 * Returns null when the kind is not served or its handler file is missing or
	 * does not implement the contract — all of which the caller treats as
	 * "not served", so a broken declaration degrades to a clean request-level
	 * refusal rather than a 500.
	 */
	public static function handler(string $kind): ?DirectKindHandler {
		$kind = self::normalizeName($kind);
		if (isset(self::$handlers[$kind])) {
			return self::$handlers[$kind];
		}
		$declaration = self::declaration($kind);
		if ($declaration === null) {
			return null;
		}

		$path = $declaration['handler'];
		$full = ($declaration['plugin'] !== '')
			? PathHelper::getIncludePath('plugins/' . $declaration['plugin'] . '/' . ltrim($path, '/'))
			: PathHelper::getIncludePath(ltrim($path, '/'));

		if (!is_file($full)) {
			error_log('[DirectKinds] handler file for kind "' . $kind . '" not found at ' . $full);
			return null;
		}
		require_once($full);

		$class = $declaration['class'];
		if ($class === '' || !class_exists($class)) {
			// Fall back to the filename, which is the convention every handler
			// follows; a declaration may name the class explicitly instead.
			$class = pathinfo($path, PATHINFO_FILENAME);
		}
		if (!class_exists($class) || !in_array('DirectKindHandler', class_implements($class) ?: array(), true)) {
			error_log('[DirectKinds] kind "' . $kind . '" declares ' . $class . ', which is not a DirectKindHandler.');
			return null;
		}

		return self::$handlers[$kind] = new $class();
	}

	/** Normalize one declaration; null when it is unusable. */
	private static function normalize($kind, $declaration, string $plugin): ?array {
		$kind = self::normalizeName((string)$kind);
		if ($kind === '') {
			return null;
		}
		if (is_string($declaration)) {
			$declaration = array('handler' => $declaration);
		}
		if (!is_array($declaration) || empty($declaration['handler'])) {
			error_log('[DirectKinds] kind "' . $kind . '" declares no handler; skipped.');
			return null;
		}
		return array(
			'kind'    => $kind,
			'handler' => (string)$declaration['handler'],
			'class'   => (string)($declaration['class'] ?? ''),
			'gate'    => (string)($declaration['gate'] ?? ''),
			'plugin'  => $plugin,
		);
	}

	private static function normalizeName(string $kind): string {
		$kind = strtolower(trim($kind));
		return preg_match('/^[a-z0-9_]{1,32}$/', $kind) ? $kind : '';
	}

	/** Drop the registry cache. Tests only — plugin activation changes it. */
	public static function resetForTests(): void {
		self::$registry = null;
		self::$handlers = array();
	}
}
