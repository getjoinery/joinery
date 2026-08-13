<?php
/**
 * VaultScopes - which Sealed Vault scopes this instance offers
 * (docs/sealed_vault.md § Scopes).
 *
 * A scope is one keypair, with its own unlockers and its own unlock. Core
 * scopes are declared in `vault_scopes.json`; a plugin declares its own under
 * `vaultScopes` in `plugin.json`:
 *
 *   "vaultScopes": {
 *     "passwords": { "custody": "client", "label": "Password vault" }
 *   }
 *
 * § The PRF context is DERIVED, never declared. prfContext() returns
 * `vault-{scope}-kek`, with the one grandfathered exception `user` →
 * `vault-kek`. This is not a convenience. A declared context is a footgun with
 * no floor under it: a developer who copies another plugin's declaration and
 * forgets to change the string silently destroys the isolation guarantee,
 * because two scopes deriving their key-encryption keys under one context means
 * unlocking either one opens both. Deriving from the scope name makes that
 * mistake unrepresentable.
 *
 * § A name collision is REFUSED, never merged — the one place this registry
 * deliberately departs from the DirectKinds idiom it otherwise copies.
 * DirectKinds lets a plugin take over a core kind, because two declarations of
 * one kind are two implementations of the same thing. Two declarations of one
 * scope are two DIFFERENT things sharing a PRF context, which is precisely the
 * isolation failure derivation exists to prevent. So core always wins, and two
 * plugins colliding are both refused — deterministic regardless of plugin
 * iteration order, and the safe direction, since honoring either would let an
 * unlock for one open the other.
 *
 * § A plugin-declared scope is a CLIENT-custody scope. `user` is the only
 * server-custody scope that can exist: both code paths that create a vault row
 * either hardcode it (VaultCeremonies) or accept client scopes only
 * (vault_client_setup_logic), and neither the ceremonies nor the onReseal
 * signature carry a scope at all. A second server-custody scope would be
 * enrollable by nobody and rotatable by nothing, so a plugin declaring
 * `custody: server` is refused. Server custody is not withheld from third
 * parties — it is reached by declaring no scope and sealing into `user`.
 *
 * @version 1.1
 * @changelog 1.1 - a missing/corrupt vault_scopes.json refuses loudly instead of
 *   silently offering no scopes, and the server-custody scope has a structural
 *   floor the file cannot remove.
 */

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));

class VaultScopes {

	const CUSTODY_SERVER = 'server';
	const CUSTODY_CLIENT = 'client';

	/** The one server-custody scope, and the one derivation exception. */
	const SERVER_SCOPE = 'user';
	const SERVER_SCOPE_CONTEXT = 'vault-kek';

	/** @var array<string,array>|null request-scoped registry cache */
	private static $registry = null;

	/** @var array<string,array>|null test seam standing in for the plugin scan */
	private static $plugin_declarations_for_tests = null;

	/**
	 * scope => ['scope' => name, 'custody' => 'server'|'client',
	 *           'label' => string, 'plugin' => name|'']
	 */
	public static function all(): array {
		if (self::$registry !== null) {
			return self::$registry;
		}

		$merged = array();

		// The core file ships with the tree, so its absence or corruption is a
		// broken deploy, and this registry is load-bearing in the worst way: read
		// tolerantly, a bad archive would silently drop every scope — no passkey
		// unlock resolves a PRF context, every recovery surface reads "inert" —
		// with nothing but a log line to say why. Refuse loudly instead; a single
		// unusable ENTRY is still skipped per-entry by normalize().
		$core_file = PathHelper::getIncludePath('vault_scopes.json');
		$decoded = is_file($core_file)
			? json_decode((string)file_get_contents($core_file), true)
			: null;
		if (!is_array($decoded)) {
			$problem = is_file($core_file) ? 'is not valid JSON' : 'is missing';
			error_log('[VaultScopes] vault_scopes.json ' . $problem
				. ' — the deploy is incomplete. Restore the file from the release archive.');
			throw new RuntimeException('The vault scope registry (vault_scopes.json) ' . $problem . '.');
		}
		foreach ($decoded as $scope => $declaration) {
			$normalized = self::normalize($scope, $declaration, '');
			if ($normalized !== null) {
				$merged[$normalized['scope']] = $normalized;
			}
		}

		// The structural floor. The server-custody scope is not configuration:
		// VaultCeremonies and the onReseal contract hardcode it, so an edit that
		// drops it from the file must not be able to drop it from the instance.
		if (!isset($merged[self::SERVER_SCOPE])) {
			error_log('[VaultScopes] vault_scopes.json does not declare the "' . self::SERVER_SCOPE
				. '" scope; restoring it — the server-custody scope is structural and cannot be removed.');
			$merged[self::SERVER_SCOPE] = array(
				'scope'   => self::SERVER_SCOPE,
				'custody' => self::CUSTODY_SERVER,
				'label'   => '',
				'plugin'  => '',
			);
		}

		// Gather every plugin declaration first, so a name two plugins both claim
		// can be refused for BOTH of them rather than resolved by whichever the
		// filesystem happened to list first.
		$claims = array();
		try {
			foreach (self::pluginDeclarations() as $plugin_name => $scopes) {
				if (!is_array($scopes)) {
					continue;
				}
				foreach ($scopes as $scope => $declaration) {
					$normalized = self::normalize($scope, $declaration, (string)$plugin_name);
					if ($normalized === null) {
						continue;
					}
					$claims[$normalized['scope']][] = $normalized;
				}
			}
		} catch (\Throwable $e) {
			error_log('[VaultScopes] plugin scope registry read failed: ' . $e->getMessage());
		}

		foreach ($claims as $scope => $claimants) {
			if (isset($merged[$scope])) {
				error_log('[VaultScopes] plugin "' . $claimants[0]['plugin'] . '" declares scope "' . $scope
					. '", which core already ships; the plugin declaration is skipped.');
				continue;
			}
			if (count($claimants) > 1) {
				$names = array();
				foreach ($claimants as $claimant) {
					$names[] = $claimant['plugin'];
				}
				error_log('[VaultScopes] scope "' . $scope . '" is declared by more than one plugin ('
					. implode(', ', $names) . '); all of them are refused, because sharing a scope name '
					. 'means sharing a PRF context and one plugin\'s unlock would open the other\'s.');
				continue;
			}
			$merged[$scope] = $claimants[0];
		}

		return self::$registry = $merged;
	}

	/** The declaration for one scope, or null when this instance does not offer it. */
	public static function declaration(string $scope): ?array {
		$all = self::all();
		return $all[$scope] ?? null;
	}

	public static function isRegistered(string $scope): bool {
		return self::declaration($scope) !== null;
	}

	/** 'server', 'client', or null for a scope nothing declares. */
	public static function custodyFor(string $scope): ?string {
		$declaration = self::declaration($scope);
		return $declaration === null ? null : $declaration['custody'];
	}

	public static function isClientCustody(string $scope): bool {
		return self::custodyFor($scope) === self::CUSTODY_CLIENT;
	}

	/** Human name for this scope's keyring and recovery card. */
	public static function labelFor(string $scope): string {
		$declaration = self::declaration($scope);
		if ($declaration !== null && $declaration['label'] !== '') {
			return $declaration['label'];
		}
		return ucfirst($scope) . ' vault';
	}

	/** Every registered scope name. */
	public static function names(): array {
		return array_keys(self::all());
	}

	/** Just the client-custody ones — the scopes the browser-held-key actions serve. */
	public static function clientScopes(): array {
		$scopes = array();
		foreach (self::all() as $scope => $declaration) {
			if ($declaration['custody'] === self::CUSTODY_CLIENT) {
				$scopes[] = $scope;
			}
		}
		return $scopes;
	}

	/**
	 * The WebAuthn PRF context a scope's passkey unlock derives its KEK under.
	 * Derived, so two scopes can never share one — see the class docblock.
	 */
	public static function prfContext(string $scope): string {
		if ($scope === self::SERVER_SCOPE) {
			return self::SERVER_SCOPE_CONTEXT;
		}
		return 'vault-' . $scope . '-kek';
	}

	/** Every context a consumer may legitimately ask a passkey to derive. */
	public static function prfContexts(): array {
		$contexts = array();
		foreach (self::names() as $scope) {
			$contexts[] = self::prfContext($scope);
		}
		return $contexts;
	}

	/** plugin name => its `vaultScopes` block, for every active plugin. */
	private static function pluginDeclarations(): array {
		if (self::$plugin_declarations_for_tests !== null) {
			return self::$plugin_declarations_for_tests;
		}
		$declarations = array();
		foreach (PluginHelper::getActivePlugins() as $plugin_name => $plugin) {
			$scopes = $plugin->get('vaultScopes', null);
			if ($scopes !== null) {
				$declarations[(string)$plugin_name] = $scopes;
			}
		}
		return $declarations;
	}

	/**
	 * Stand in for the plugin scan so a test can exercise the merge — collisions,
	 * custody refusal, name validation — without installing plugins. Pass null to
	 * go back to reading real plugin.json files.
	 */
	public static function setPluginDeclarationsForTests(?array $by_plugin): void {
		self::$plugin_declarations_for_tests = $by_plugin;
		self::$registry = null;
	}

	/** Normalize one declaration; null when it is unusable (logged and skipped). */
	private static function normalize($scope, $declaration, string $plugin): ?array {
		$raw = (string)$scope;
		$scope = self::normalizeName($raw);
		if ($scope === '') {
			// The name is not cosmetic: it flows into the derived PRF context, the
			// APCu window key, and the /dev/shm window-marker filename, whose
			// sanitizer collapses anything outside [a-z0-9_] — so two names
			// differing only in refused characters would collide after
			// sanitization, which is the isolation failure again by another route.
			error_log('[VaultScopes] scope name "' . $raw . '" is not a valid scope name '
				. '(lowercase letters, digits and underscore, 1-32 characters); skipped.');
			return null;
		}
		if (is_string($declaration)) {
			$declaration = array('custody' => $declaration);
		}
		if (!is_array($declaration)) {
			error_log('[VaultScopes] scope "' . $scope . '" has an unusable declaration; skipped.');
			return null;
		}

		$custody = strtolower(trim((string)($declaration['custody'] ?? self::CUSTODY_CLIENT)));
		if ($custody !== self::CUSTODY_SERVER && $custody !== self::CUSTODY_CLIENT) {
			error_log('[VaultScopes] scope "' . $scope . '" declares custody "' . $custody
				. '", which is neither server nor client; skipped.');
			return null;
		}
		if ($custody === self::CUSTODY_SERVER && $plugin !== '') {
			error_log('[VaultScopes] plugin "' . $plugin . '" declares scope "' . $scope
				. '" with server custody; refused. Server custody is reached by declaring no scope '
				. 'and sealing into "' . self::SERVER_SCOPE . '" — a second server-custody scope has '
				. 'no enrollment or rotation path and would be unreachable.');
			return null;
		}

		return array(
			'scope'   => $scope,
			'custody' => $custody,
			'label'   => (string)($declaration['label'] ?? ''),
			'plugin'  => $plugin,
		);
	}

	private static function normalizeName(string $scope): string {
		$scope = strtolower(trim($scope));
		return preg_match('/^[a-z0-9_]{1,32}$/', $scope) ? $scope : '';
	}

	/** Drop the registry cache. Tests only — plugin activation changes it. */
	public static function resetForTests(): void {
		self::$registry = null;
		self::$plugin_declarations_for_tests = null;
	}
}
?>
