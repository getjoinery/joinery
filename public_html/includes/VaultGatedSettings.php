<?php
/**
 * VaultGatedSettings — the closed, declared list of settings that redirect
 * protected mail's plaintext (specs/mailbox_security_levels.md § Vault-Gated
 * Settings). Changing one requires an OPEN unlock window when the acting account
 * holds a vault: the same "prompt-and-continue" gate the filter/alias editors
 * already apply, moved to the core settings-save so a repointed relay can't
 * route future outbound plaintext through an attacker's box from behind a
 * phished password.
 *
 * The list is not hardcoded in core: each active plugin declares its own names
 * in plugin.json under `vaultGatedSettings`, so the rule stays a closed list a
 * plugin opts into, never a core dependency on any one plugin.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));

class VaultGatedSettings {

	/** @var string[]|null request-scoped cache of the merged gated-name set */
	private static $names = null;

	/** Every setting name declared vault-gated by an active plugin. */
	public static function names(): array {
		if (self::$names !== null) {
			return self::$names;
		}
		$names = array();
		foreach (PluginHelper::getActivePlugins() as $plugin) {
			foreach ($plugin->getVaultGatedSettings() as $name) {
				$name = trim((string)$name);
				if ($name !== '') {
					$names[$name] = true;
				}
			}
		}
		self::$names = array_keys($names);
		return self::$names;
	}

	/** True when this setting name is vault-gated. */
	public static function isGated(string $name): bool {
		return in_array($name, self::names(), true);
	}
}
?>
