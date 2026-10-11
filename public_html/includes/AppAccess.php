<?php
/**
 * AppAccess - the purposes an app credential may be minted for.
 *
 * A program that cannot do a passkey sign-in (a mail app today; a calendar or
 * file-sync app later) signs in with an app credential: one device, one
 * purpose, revocable by the person. The PURPOSE is what the credential may do,
 * and an active plugin declares it in its plugin.json:
 *
 *   "appAccess": {
 *       "mail": { "label": "Mail apps", "borrowsUnlock": true }
 *   }
 *
 *   label          what the person sees ("Mail apps") on the App Sessions page
 *                  and the lock chip.
 *   borrowsUnlock  credentials of this purpose read sealed content in a window
 *                  borrowed from the person's website unlock
 *                  (VaultUnlock::useCredentialWindow()). Without it a purpose
 *                  never gets a window.
 *   requiresSetting  a declared setting that switches the purpose on. While it
 *                  is off the purpose is declared but not AVAILABLE: no
 *                  credential of it is minted, every one is refused, and none
 *                  borrows a window — the site's switch for the feature is
 *                  the switch for its credentials.
 *
 * Everything that governs an app credential — which actions accept it, whether
 * it borrows the unlock, where it is listed, revocation — is keyed to the
 * purpose and the credential's apk_api_keys id, never to HOW the credential
 * proves itself (an app password today; browser sign-in could be a second
 * kind). See specs/mail_apps_plugin.md § Core: app access.
 *
 * A purpose stops being declared the moment its plugin is deactivated, and
 * ApiAuth refuses every credential of an undeclared purpose — so turning the
 * plugin off is what turns its credentials off, with no sweep to run.
 *
 * @version 1.1.0 - requiresSetting: a purpose is available only while its switch is on
 * @version 1.0.0
 */
class AppAccess {

	/** A purpose name: what apk_purpose holds (varchar(32)). */
	const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

	/** @var array<string,array{label:string,borrowsUnlock:bool,plugin:string}>|null */
	private static $registry = null;

	/** @var array<string,array>|null plugin => appAccess block, standing in for the scan in tests */
	private static $declarations_for_tests = null;

	/**
	 * Every declared purpose, name => {label, borrowsUnlock, plugin}.
	 *
	 * @return array<string,array{label:string,borrowsUnlock:bool,plugin:string}>
	 */
	public static function purposes(): array {
		if (self::$registry !== null) {
			return self::$registry;
		}
		$registry = array();
		foreach (self::pluginDeclarations() as $plugin => $block) {
			if (!is_array($block)) {
				error_log('AppAccess: plugin ' . $plugin . ' declares appAccess that is not an object; ignored.');
				continue;
			}
			foreach ($block as $name => $declaration) {
				$name = (string)$name;
				if (!preg_match(self::NAME_PATTERN, $name)) {
					error_log('AppAccess: plugin ' . $plugin . ' declares an unusable purpose name "' . $name . '"; ignored.');
					continue;
				}
				if (isset($registry[$name])) {
					// Two plugins claiming one purpose would let either one's actions
					// accept the other's credentials. Neither gets it.
					error_log('AppAccess: purpose "' . $name . '" is declared by both ' . $registry[$name]['plugin']
						. ' and ' . $plugin . '; refusing it for both.');
					$registry[$name]['conflict'] = true;
					continue;
				}
				$declaration = is_array($declaration) ? $declaration : array();
				$registry[$name] = array(
					'label'           => trim((string)($declaration['label'] ?? '')) !== ''
										? trim((string)$declaration['label']) : $name,
					'borrowsUnlock'   => !empty($declaration['borrowsUnlock']),
					'requiresSetting' => trim((string)($declaration['requiresSetting'] ?? '')),
					'plugin'          => (string)$plugin,
				);
			}
		}
		foreach ($registry as $name => $entry) {
			if (!empty($entry['conflict'])) {
				unset($registry[$name]);
			}
		}
		self::$registry = $registry;
		return $registry;
	}

	/** True when an active plugin declares $purpose. */
	public static function isDeclared(?string $purpose): bool {
		return $purpose !== null && $purpose !== '' && isset(self::purposes()[$purpose]);
	}

	/**
	 * True when $purpose is declared AND switched on (its requiresSetting, if
	 * any, is set). The question minting, authentication and window-borrowing
	 * ask.
	 */
	public static function isAvailable(?string $purpose): bool {
		if (!self::isDeclared($purpose)) {
			return false;
		}
		$setting = self::purposes()[$purpose]['requiresSetting'];
		if ($setting === '') {
			return true;
		}
		try {
			return (bool)Globalvars::get_instance()->get_setting($setting);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/** True when credentials of $purpose borrow the website unlock (and it is available). */
	public static function borrowsUnlock(?string $purpose): bool {
		return self::isAvailable($purpose) && self::purposes()[$purpose]['borrowsUnlock'];
	}

	/** The person-facing name of a purpose ("Mail apps"), or the bare name. */
	public static function label(?string $purpose): string {
		if (!self::isDeclared($purpose)) {
			return (string)$purpose;
		}
		return self::purposes()[$purpose]['label'];
	}

	/** The purposes whose credentials borrow the unlock. @return string[] */
	public static function borrowingPurposes(): array {
		$out = array();
		foreach (self::purposes() as $name => $entry) {
			if ($entry['borrowsUnlock'] && self::isAvailable($name)) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/** plugin name => its `appAccess` block, for every active plugin. */
	private static function pluginDeclarations(): array {
		if (self::$declarations_for_tests !== null) {
			return self::$declarations_for_tests;
		}
		$declarations = array();
		try {
			$plugins = PluginHelper::getActivePlugins();
		} catch (\Throwable $e) {
			// No answer about which plugins are active is no declared purpose:
			// every app credential is refused until the answer comes back.
			error_log('AppAccess: the active plugins could not be read: ' . $e->getMessage());
			return array();
		}
		foreach ($plugins as $plugin_name => $plugin) {
			$block = $plugin->get('appAccess', null);
			if ($block !== null) {
				$declarations[(string)$plugin_name] = $block;
			}
		}
		return $declarations;
	}

	/**
	 * Stand in for the plugin scan so a test can declare purposes without
	 * installing a plugin. Pass null to go back to reading plugin.json files.
	 */
	public static function setDeclarationsForTests(?array $by_plugin): void {
		self::$declarations_for_tests = $by_plugin;
		self::$registry = null;
	}

	/** Forget the computed registry (a plugin was activated or deactivated). */
	public static function forget(): void {
		self::$registry = null;
	}
}
?>
