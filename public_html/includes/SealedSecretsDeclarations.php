<?php
/**
 * The sealed-secret declarations, read from the manifests.
 *
 * Every kind of value SecretBox protects is declared once — in a
 * `sealed_secrets` array in `settings.json` at the public_html root for core,
 * or in a plugin's `plugin.json` under `sealed_secrets`. The declaration is
 * what makes SecretBox::seal() refuse an unregistered value, and what the
 * reconciler and the import scrub walk.
 *
 * This is deliberately NOT the same thing as a setting's `secret:true` flag.
 * `secret:true` means "mask this field in the settings form" — it says nothing
 * about whether the stored value is encrypted at rest (hcaptcha_private is
 * masked yet stored plaintext). Masked-in-form and sealed-on-disk are two
 * different properties, so sealing gets its own declaration rather than being
 * inferred from `secret:true`.
 *
 * An entry is a CATEGORY, not one secret: "IMAP account passwords" is one entry
 * even though there is one per account. Each entry answers the two questions the
 * reconciler asks — how do I find every secret of this kind, and what do I do
 * when one is dead:
 *
 *   locator      Required, and the entry's unique id. Where the secret lives, in
 *                a code-free form so it still means something after the owning
 *                plugin is deleted:
 *                  - a singleton gives its setting name  ("file_signed_url_key")
 *                  - a row-scoped kind gives "table.column" ("iem_account.iem_password")
 *                This is the part that persists into the seeded registry table,
 *                so it must be enough to count and scrub with no plugin code loaded.
 *   label        Human name for the operator health surface.
 *   feature      The feature it belongs to, for the same surface.
 *   kind         operator | regenerable | regenerable-breaks-things | ephemeral
 *                  operator                  human-entered, cannot be regenerated
 *                  regenerable               machine re-mints, no side effects
 *                  regenerable-breaks-things machine CAN re-mint, but doing so
 *                                            silently damages live state
 *                  ephemeral                 per-run value; a dead one is just
 *                                            discarded, never healed or alerted
 *   reprovision  For `regenerable` only: "Class::method" that mints a fresh one.
 *   enumerator   Optional, for a row-scoped kind: "Class::method" returning each
 *                live row so a full reconcile can check/re-provision per row. It
 *                lives in code, is not persisted, and is used only when the
 *                owning plugin is loaded. The locator is the floor that always
 *                works; the enumerator is the ceiling that works when code is up.
 *
 * @version 1.0
 */
class SealedSecretsDeclarations {

	/** @var array|null locator => declaration, with '_source' resolved */
	private static $cache = null;

	const KINDS = array('operator', 'regenerable', 'regenerable-breaks-things', 'ephemeral');

	/**
	 * Every declaration on disk, keyed by locator.
	 *
	 * Plugins that are present but deactivated are included: their sealed rows
	 * persist in the database, so leaving them out would make every such row
	 * look like an orphan. (Reading a deactivated plugin's declaration costs
	 * nothing at the seal() gate — its classes do not resolve, so nothing can
	 * reach seal() under its locators anyway.)
	 */
	public static function all(): array {
		if (self::$cache !== null) return self::$cache;

		$declarations = array();

		$core_path = PathHelper::getIncludePath('settings.json');
		foreach (self::readManifest($core_path, 'sealed_secrets') as $entry) {
			$resolved = self::resolve($entry, 'core');
			if ($resolved !== null) $declarations[$resolved['locator']] = $resolved;
		}

		$plugin_dir = PathHelper::getIncludePath('plugins');
		foreach ((array)glob($plugin_dir . '/*/plugin.json') as $manifest_path) {
			$plugin_name = basename(dirname($manifest_path));
			foreach (self::readManifest($manifest_path, 'sealed_secrets') as $entry) {
				$resolved = self::resolve($entry, $plugin_name);
				// Core always wins a locator collision, keeping the resolver total.
				if ($resolved !== null && !isset($declarations[$resolved['locator']])) {
					$declarations[$resolved['locator']] = $resolved;
				}
			}
		}

		self::$cache = $declarations;
		return self::$cache;
	}

	public static function get(string $locator): ?array {
		return self::all()[$locator] ?? null;
	}

	/**
	 * Is this locator a declared sealed secret? This is the check SecretBox::seal()
	 * makes before it will encrypt anything — the teeth that make the declaration
	 * load-bearing. Reads the on-disk manifests only, never the database, so it
	 * works the instant new code lands (a fresh install minting its first secrets,
	 * a plugin activating, the two-pass upgrade window) with no dependency on
	 * update_database having seeded the table yet.
	 */
	public static function isDeclared(string $locator): bool {
		return isset(self::all()[$locator]);
	}

	/** All declarations of one kind. */
	public static function ofKind(string $kind): array {
		$out = array();
		foreach (self::all() as $locator => $d) {
			if ($d['kind'] === $kind) $out[$locator] = $d;
		}
		return $out;
	}

	/**
	 * Check every declaration for internal consistency. Returns a list of
	 * human-readable problems; empty means the manifests are well-formed. Called
	 * by plugin sync and the sealed-secrets declaration test.
	 */
	public static function schemaErrors(): array {
		$errors = array();
		$seen = array();

		// Re-read raw so a duplicate locator across manifests is visible (all()
		// silently keeps the first).
		$raw = array();
		$core_path = PathHelper::getIncludePath('settings.json');
		foreach (self::readManifest($core_path, 'sealed_secrets') as $entry) {
			$raw[] = array($entry, 'core');
		}
		foreach ((array)glob(PathHelper::getIncludePath('plugins') . '/*/plugin.json') as $manifest_path) {
			foreach (self::readManifest($manifest_path, 'sealed_secrets') as $entry) {
				$raw[] = array($entry, basename(dirname($manifest_path)));
			}
		}

		foreach ($raw as $pair) {
			list($d, $source) = $pair;
			$locator = is_array($d) && isset($d['locator']) ? (string)$d['locator'] : '?';
			$where = "{$source}:{$locator}";

			if (!is_array($d) || empty($d['locator']) || !is_string($d['locator'])) {
				$errors[] = "{$source}: a sealed_secrets entry must have a string 'locator'.";
				continue;
			}
			if (isset($seen[$locator])) {
				$errors[] = "{$where}: locator already declared by '{$seen[$locator]}'.";
			}
			$seen[$locator] = $source;

			if (empty($d['kind']) || !in_array($d['kind'], self::KINDS, true)) {
				$errors[] = "{$where}: kind must be one of " . implode(', ', self::KINDS) . '.';
			}
			if (empty($d['label']) || !is_string($d['label'])) {
				$errors[] = "{$where}: label is required.";
			}
			// A regenerable secret MUST say how to re-mint (the reconciler calls it
			// automatically). A regenerable-breaks-things secret MAY declare it —
			// the reconciler never auto-calls it, but the admin "acknowledge
			// re-mint" action does. operator and ephemeral must not.
			$kind = $d['kind'] ?? '';
			if ($kind === 'regenerable' && empty($d['reprovision'])) {
				$errors[] = "{$where}: a regenerable secret must declare a reprovision 'Class::method'.";
			}
			if (in_array($kind, array('operator', 'ephemeral'), true) && !empty($d['reprovision'])) {
				$errors[] = "{$where}: a {$kind} secret cannot be re-minted, so it must not declare reprovision.";
			}
			foreach (array('reprovision', 'enumerator') as $callable_key) {
				if (!empty($d[$callable_key]) && strpos((string)$d[$callable_key], '::') === false) {
					$errors[] = "{$where}: {$callable_key} must be 'Class::method'.";
				}
			}
		}

		return $errors;
	}

	/**
	 * Drop the cached manifests. Tests that write a manifest call this.
	 */
	public static function reset(): void {
		self::$cache = null;
	}

	// ── internals ───────────────────────────────────────────────────────────

	private static function readManifest(string $path, string $key): array {
		if (!file_exists($path)) return array();
		$data = json_decode((string)file_get_contents($path), true);
		if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) return array();
		return $data[$key];
	}

	private static function resolve($entry, string $source): ?array {
		if (!is_array($entry) || empty($entry['locator']) || !is_string($entry['locator'])) {
			return null;
		}
		$entry['_source'] = $source;
		return $entry;
	}
}
