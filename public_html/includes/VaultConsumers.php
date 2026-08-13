<?php
/**
 * VaultConsumers - which packages consume the Sealed Vault, and what each one
 * owes it (docs/sealed_vault.md § Registering a consumer).
 *
 * Registration is declarative, the same idiom DirectKinds uses for Joinery
 * Direct kinds. Core consumers are declared in `vault_consumers.json`, each
 * entry carrying its own `bootstrap` path (they have no plugin.json; the
 * string shorthand `"name": "includes/File.php"` declares a bootstrap and no
 * obligations). A plugin's LOAD POINT is the top-level `bootstrap` key in its
 * plugin.json — every plugin gets one, vault consumer or not (see
 * PluginBootstraps) — and `vaultConsumer` carries only the vault obligations
 * riding on that bootstrap:
 *
 *   "bootstrap": "includes/bootstrap.php",
 *   "vaultConsumer": {
 *     "order": 20,
 *     "reseals": true,
 *     "caches": true
 *   }
 *
 * A plugin declaring only `bootstrap` appears in the registry as a load point
 * with no obligations, at the default order. A plugin declaring
 * `vaultConsumer` without a top-level `bootstrap` keeps its obligations with
 * no load path — the loader logs it and the obligations read unmet, which
 * refuses rotation rather than silently dropping a resealer.
 *
 * The registry is INSTANCE CONFIGURATION, readable without loading any consumer
 * code, which is what lets the rotation ceremony ask "does anything on this box
 * hold sealed content it has not re-sealed?" before it mints anything. A
 * consumer whose plugin is deactivated is simply absent from the merged set —
 * its hooks do not load and its window caps do not apply.
 *
 * § Obligations. Two flags say what a consumer owes the vault, and they are
 * enforced differently on purpose:
 *
 *   - `reseals` — this consumer stores sealed content and must register an
 *     onReseal callback. Declared-and-missing REFUSES a key rotation, because
 *     rotating past it destroys member content.
 *   - `caches` — this consumer keeps disposable in-window plaintext outside the
 *     sealed columns (a search index, a streaming scratch) and must register an
 *     onWipe callback. Declared-and-missing LOGS. It can never refuse, because
 *     the only moment it is observable is window close, and refusing to close a
 *     window would leave the vault open — strictly worse than the stale file it
 *     is guarding against.
 *
 * § Attribution. onReseal()/onWipe() take a bare callable and would otherwise
 * leave core holding closures with no record of who registered them, so a
 * missing obligation could be neither named nor distinguished from a consumer
 * that registered two callbacks. The loader publishes the consumer it is about
 * to require (beginLoading/endLoading) and each registration stamps whoever is
 * loading. This is correct ONLY while consumer bootstraps load exclusively
 * through loadConsumerBootstraps() — a stated invariant, checked against
 * get_included_files() so a violation logs its real cause rather than surfacing
 * later as a rotation refused for the wrong reason.
 *
 * @version 1.2
 * @changelog 1.2 - a plugin's load point is the top-level plugin.json
 *   `bootstrap` key (see PluginBootstraps); vaultConsumer carries only the
 *   obligations, and a bootstrap-only plugin registers as a load point with
 *   none.
 * @changelog 1.1 - a missing/corrupt vault_consumers.json refuses loudly instead
 *   of silently registering no consumers (which would disarm the rotation guard
 *   and skip every core onReseal hook).
 */

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));

class VaultConsumers {

	/** Where a consumer with no opinion about ordering lands. */
	const DEFAULT_ORDER = 100;

	/** Declaration key => the callback registration that satisfies it. */
	const OBLIGATION_RESEAL = 'reseals';
	const OBLIGATION_CACHES = 'caches';

	/** @var array<string,array>|null request-scoped merged registry (active only) */
	private static $registry = null;

	/** @var array<string,array>|null every declaration on disk, active or not */
	private static $all_declarations = null;

	/** @var array<string,array>|null test seam standing in for the plugin scan */
	private static $plugin_declarations_for_tests = null;

	/** @var string|null the consumer whose bootstrap is being required right now */
	private static $loading = null;

	/** @var array<string,array<string,int>> consumer => obligation => registrations */
	private static $registrations = array();

	/**
	 * The merged, ordered registry of consumers whose hooks should load on this
	 * request: core entries plus every ACTIVE plugin's, sorted by `order`
	 * ascending with the consumer name breaking ties.
	 *
	 * Sorting is not cosmetic. Mail parsing must precede AI judging (an unparsed
	 * message has no fields to read), and plugin iteration order is not a
	 * contract, so the order a consumer declares is the order it loads and the
	 * order VaultDeferredWork drains in.
	 *
	 * @return array<string,array{name:string,bootstrap:string,path:string,order:int,reseals:bool,caches:bool,plugin:string}>
	 */
	public static function registered(): array {
		if (self::$registry !== null) {
			return self::$registry;
		}
		$merged = array();
		foreach (self::allDeclarations() as $name => $declaration) {
			if ($declaration['plugin'] !== '' && !$declaration['active']) {
				continue;
			}
			$merged[$name] = $declaration;
		}
		uasort($merged, function ($a, $b) {
			if ($a['order'] === $b['order']) {
				return strcmp($a['name'], $b['name']);
			}
			return $a['order'] < $b['order'] ? -1 : 1;
		});
		return self::$registry = $merged;
	}

	/**
	 * Every declaration on disk, INCLUDING installed-but-inactive plugins.
	 *
	 * Only one caller wants this: the rotation guard. Deactivating a plugin
	 * removes its callbacks but not its sealed rows, so rotating past a
	 * deactivated consumer is the same silent data loss the declared-and-missing
	 * guard exists to prevent. A deactivated plugin's plugin.json is still on
	 * disk, so the obligation is still readable. Everywhere else the runtime
	 * registry excludes inactive plugins, per the DirectKinds idiom.
	 */
	public static function allDeclarations(): array {
		if (self::$all_declarations !== null) {
			return self::$all_declarations;
		}
		$merged = array();

		// The core file ships with the tree, so its absence or corruption is a
		// broken deploy — and tolerating it silently would be the quiet version of
		// the exact loss this registry exists to prevent: the core consumers'
		// onReseal hooks would never load AND the rotation guard would see no
		// declaration to refuse on, so a rotation would retire the generation
		// Drive's file keys sit on. Refuse loudly instead; a single unusable
		// ENTRY is still skipped per-entry by normalize().
		$core_file = PathHelper::getIncludePath('vault_consumers.json');
		$decoded = is_file($core_file)
			? json_decode((string)file_get_contents($core_file), true)
			: null;
		if (!is_array($decoded)) {
			$problem = is_file($core_file) ? 'is not valid JSON' : 'is missing';
			error_log('[VaultConsumers] vault_consumers.json ' . $problem
				. ' — the deploy is incomplete. Restore the file from the release archive.');
			throw new RuntimeException('The vault consumer registry (vault_consumers.json) ' . $problem . '.');
		}
		foreach ($decoded as $name => $declaration) {
			$normalized = self::normalize($name, $declaration, '', true);
			if ($normalized !== null) {
				$merged[$normalized['name']] = $normalized;
			}
		}

		try {
			foreach (self::pluginDeclarations() as $plugin_name => $entry) {
				$bootstrap = isset($entry['bootstrap']) && is_string($entry['bootstrap'])
					? trim($entry['bootstrap']) : '';
				// Test-seam only: an entry may carry a resolved 'path', so a fixture
				// bootstrap can live outside plugins/{name}/. The real plugin scan
				// never sets it.
				$path_override = isset($entry['path']) && is_string($entry['path']) ? $entry['path'] : null;
				$normalized = null;
				if ($entry['declaration'] !== null) {
					$normalized = self::normalize((string)$plugin_name, $entry['declaration'],
						(string)$plugin_name, !empty($entry['active']), $bootstrap, $path_override);
				}
				if ($normalized === null && $bootstrap !== '') {
					// A load point with no vault obligations — the plugin declared
					// only `bootstrap` (or its vaultConsumer block was unusable).
					$normalized = self::bootstrapOnlyEntry((string)$plugin_name, $bootstrap,
						!empty($entry['active']), $path_override);
				}
				if ($normalized === null) {
					continue;
				}
				if (isset($merged[$normalized['name']])) {
					// A plugin cannot take over a core consumer's name: the two
					// would fight over one bootstrap slot and one obligation set.
					error_log('[VaultConsumers] plugin "' . $plugin_name . '" declares a consumer name core '
						. 'already ships; the plugin declaration is skipped.');
					continue;
				}
				$merged[$normalized['name']] = $normalized;
			}
		} catch (\Throwable $e) {
			error_log('[VaultConsumers] plugin consumer registry read failed: ' . $e->getMessage());
		}

		return self::$all_declarations = $merged;
	}

	/** One consumer's declaration, or null when nothing declares that name. */
	public static function declaration(string $name): ?array {
		$all = self::allDeclarations();
		return $all[$name] ?? null;
	}

	/**
	 * Publish the consumer whose bootstrap is about to be required, so the
	 * onReseal()/onWipe() calls inside it attribute to it. Always paired with
	 * endLoading() in a finally block — a bootstrap that throws must not leave
	 * the next one's registrations credited to it.
	 */
	public static function beginLoading(string $name): void {
		self::$loading = $name;
	}

	public static function endLoading(): void {
		self::$loading = null;
	}

	/** The consumer currently loading, or null outside a loading context. */
	public static function loadingConsumer(): ?string {
		return self::$loading;
	}

	/**
	 * Record that the consumer currently loading registered a callback covering
	 * $obligation. A registration made outside any loading context (a test
	 * wiring a callback directly) attributes to nobody and satisfies nothing,
	 * which is the honest answer rather than crediting it to whoever happened to
	 * load last.
	 */
	public static function noteRegistration(string $obligation): void {
		if (self::$loading === null) {
			return;
		}
		$name = self::$loading;
		if (!isset(self::$registrations[$name])) {
			self::$registrations[$name] = array();
		}
		self::$registrations[$name][$obligation] = (self::$registrations[$name][$obligation] ?? 0) + 1;
	}

	/**
	 * How many callbacks each consumer registered, per obligation. A consumer
	 * that registered two (mailbox composes the generic model reseal alongside
	 * its bespoke DKIM pass) reads as 2, not as "registered".
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function registrationCounts(): array {
		return self::$registrations;
	}

	/**
	 * Which declared obligations nobody registered for, per consumer. Callers
	 * must have loaded the bootstraps first — VaultUnlock::loadConsumerBootstraps().
	 *
	 * $include_inactive adds installed-but-inactive plugin declarations, which
	 * by construction registered nothing. Only the rotation guard passes true.
	 *
	 * @return array<string,string[]> consumer => obligations with no callback
	 */
	public static function unmetObligations(bool $include_inactive = false): array {
		$unmet = array();
		$source = $include_inactive ? self::allDeclarations() : self::registered();
		foreach ($source as $name => $declaration) {
			$missing = array();
			foreach (array(self::OBLIGATION_RESEAL, self::OBLIGATION_CACHES) as $obligation) {
				if (!$declaration[$obligation]) {
					continue;
				}
				if ((self::$registrations[$name][$obligation] ?? 0) < 1) {
					$missing[] = $obligation;
				}
			}
			if ($missing) {
				$unmet[$name] = $missing;
			}
		}
		return $unmet;
	}

	/**
	 * Has this plugin EVER been activated on this instance?
	 *
	 * The rotation guard's refusal for an inactive resealer exists because
	 * "deactivated" removes callbacks but not sealed rows. A plugin that was
	 * never switched on here has no rows — often not even tables — so refusing
	 * for it would make rotation permanently unavailable on every deployment
	 * that ships the plugin's code without using the feature. Activation history
	 * is the cheap, table-agnostic way to tell the two apart: plg_activated_time
	 * is nulled on deactivation, but plg_last_activated_time/-deactivated_time
	 * survive it.
	 *
	 * When the answer is unknowable (mid-install, the plugins table absent) this
	 * says YES — the refusing direction, because the guard errs toward
	 * protecting content.
	 */
	public static function pluginEverActivated(string $plugin_name): bool {
		if (self::$ever_activated_for_tests !== null) {
			return !empty(self::$ever_activated_for_tests[$plugin_name]);
		}
		// A test that fakes the plugin scan means its declarations to be taken at
		// face value; an inactive fake is the deactivated-after-use scenario
		// unless the test says otherwise through the seam above.
		if (self::$plugin_declarations_for_tests !== null) {
			return true;
		}
		try {
			$stmt = DbConnector::get_instance()->get_db_link()->prepare(
				'SELECT 1 FROM plg_plugins WHERE plg_name = ?
				  AND (plg_activated_time IS NOT NULL
				   OR plg_last_activated_time IS NOT NULL
				   OR plg_last_deactivated_time IS NOT NULL) LIMIT 1');
			$stmt->execute(array($plugin_name));
			return (bool)$stmt->fetchColumn();
		} catch (\Throwable $e) {
			return true;
		}
	}

	/** @var array<string,bool>|null test seam standing in for the activation-history lookup */
	private static $ever_activated_for_tests = null;

	/** Fake activation history for tests. Pass null to read plg_plugins again. */
	public static function setPluginEverActivatedForTests(?array $by_plugin): void {
		self::$ever_activated_for_tests = $by_plugin;
	}

	/**
	 * plugin name => ['declaration' => the vaultConsumer block or null,
	 * 'active' => bool, 'bootstrap' => the top-level plugin.json bootstrap key
	 * or null], for every plugin on disk that declares either. Inactive ones
	 * are carried because the rotation guard needs them; everything else
	 * filters them out.
	 */
	private static function pluginDeclarations(): array {
		if (self::$plugin_declarations_for_tests !== null) {
			return self::$plugin_declarations_for_tests;
		}
		$declarations = array();
		foreach (PluginHelper::getAvailablePlugins() as $plugin_name => $plugin) {
			$declaration = $plugin->get('vaultConsumer', null);
			$bootstrap = $plugin->get('bootstrap', null);
			if ($declaration === null && ($bootstrap === null || $bootstrap === '')) {
				continue;
			}
			$declarations[(string)$plugin_name] = array(
				'declaration' => $declaration,
				'active'      => PluginHelper::isPluginActive((string)$plugin_name),
				'bootstrap'   => is_string($bootstrap) ? $bootstrap : null,
			);
		}
		return $declarations;
	}

	/**
	 * Stand in for the plugin scan so a test can exercise the merge, the ordering
	 * and the obligation checks without installing plugins. Pass null to go back
	 * to reading real plugin.json files.
	 */
	public static function setPluginDeclarationsForTests(?array $by_plugin): void {
		self::$plugin_declarations_for_tests = $by_plugin;
		self::$registry = null;
		self::$all_declarations = null;
	}

	/**
	 * Normalize one declaration; null when it is unusable (logged and skipped,
	 * never fatal — a broken consumer declaration must not take the site down).
	 *
	 * A CORE entry carries its own bootstrap (string shorthand allowed). A
	 * PLUGIN entry's load point is the top-level plugin.json `bootstrap` key,
	 * passed in as $plugin_bootstrap; the vaultConsumer block carries only the
	 * obligations. A vaultConsumer with no plugin bootstrap keeps its
	 * obligations with an empty path — the loader logs it and the unmet
	 * obligation refuses rotation, the fail-safe direction for a resealer
	 * whose load point went missing.
	 */
	private static function normalize($name, $declaration, string $plugin, bool $active,
			string $plugin_bootstrap = '', ?string $path_override = null): ?array {
		$name = self::normalizeName((string)$name);
		if ($name === '') {
			error_log('[VaultConsumers] a consumer declares an unusable name; skipped.');
			return null;
		}
		if ($plugin === '') {
			if (is_string($declaration)) {
				$declaration = array('bootstrap' => $declaration);
			}
			if (!is_array($declaration) || empty($declaration['bootstrap'])) {
				error_log('[VaultConsumers] consumer "' . $name . '" declares no bootstrap; skipped.');
				return null;
			}
			$bootstrap = ltrim((string)$declaration['bootstrap'], '/');
			$path = PathHelper::getIncludePath($bootstrap);
		} else {
			if (is_string($declaration)) {
				error_log('[VaultConsumers] plugin "' . $plugin . '" declares vaultConsumer as a string; '
					. 'a plugin\'s load point is the top-level "bootstrap" key in plugin.json, and '
					. 'vaultConsumer carries only order/reseals/caches. The block is ignored.');
				return null;   // the top-level bootstrap key, if any, still yields a load point
			}
			if (!is_array($declaration)) {
				error_log('[VaultConsumers] consumer "' . $name . '" has an unusable declaration; skipped.');
				return null;
			}
			$bootstrap = ltrim($plugin_bootstrap, '/');
			$path = ($bootstrap === '')
				? ''
				: ($path_override ?? PathHelper::getIncludePath('plugins/' . $plugin . '/' . $bootstrap));
			if ($bootstrap === '') {
				error_log('[VaultConsumers] plugin "' . $plugin . '" declares vaultConsumer but no '
					. 'top-level "bootstrap" key in plugin.json; its hooks cannot load and any '
					. 'obligation it declares will read as unmet.');
			}
		}

		$order = isset($declaration['order']) && is_numeric($declaration['order'])
			? (int)$declaration['order'] : self::DEFAULT_ORDER;

		return array(
			'name'      => $name,
			'bootstrap' => $bootstrap,
			'path'      => $path,
			'order'     => $order,
			'reseals'   => !empty($declaration[self::OBLIGATION_RESEAL]),
			'caches'    => !empty($declaration[self::OBLIGATION_CACHES]),
			'plugin'    => $plugin,
			'active'    => $active,
		);
	}

	/**
	 * The registry entry for a plugin that declares a load point and no vault
	 * obligations — the plain `bootstrap` key. Default order, so it loads after
	 * every consumer that declared one.
	 */
	private static function bootstrapOnlyEntry(string $plugin, string $bootstrap, bool $active,
			?string $path_override = null): ?array {
		$name = self::normalizeName($plugin);
		if ($name === '') {
			error_log('[VaultConsumers] a plugin bootstrap declares an unusable name; skipped.');
			return null;
		}
		$bootstrap = ltrim($bootstrap, '/');
		return array(
			'name'      => $name,
			'bootstrap' => $bootstrap,
			'path'      => $path_override ?? PathHelper::getIncludePath('plugins/' . $plugin . '/' . $bootstrap),
			'order'     => self::DEFAULT_ORDER,
			'reseals'   => false,
			'caches'    => false,
			'plugin'    => $plugin,
			'active'    => $active,
		);
	}

	private static function normalizeName(string $name): string {
		$name = strtolower(trim($name));
		return preg_match('/^[a-z0-9_]{1,64}$/', $name) ? $name : '';
	}

	/** Drop every cache and attribution. Tests only. */
	public static function resetForTests(): void {
		self::$registry = null;
		self::$all_declarations = null;
		self::$loading = null;
		self::$registrations = array();
		self::$plugin_declarations_for_tests = null;
		self::$ever_activated_for_tests = null;
	}
}
?>
