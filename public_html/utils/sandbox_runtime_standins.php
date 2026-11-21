<?php
/**
 * sandbox_runtime_standins.php - the Globalvars stand-in SandboxRuntime puts
 * in that singleton, inside the fence (utils/sandbox_runtime.php). Required
 * after the real class is loaded, which it extends, and before anything else
 * of core: some core files ask for settings as they load. Never by the site
 * itself. The session and database stand-ins are in
 * sandbox_runtime_standins_session.php, loaded once this one is in place.
 *
 * @version 1.2 - default_email_template is a public fact (EmailSender::quickSend() reads it)
 * @version 1.1 - a setting's bare name answers only where core declares no setting of that name (reviewer2 F1)
 * @version 1.0
 */

/** The plugin's own settings, and three public facts of the site. */
class SandboxGlobalvars extends Globalvars {

	/** Site settings a plugin may read: public facts, never a secret or the config file. */
	const PUBLIC_SETTINGS = array('site_name', 'webDir', 'default_timezone', 'default_email_template');

	/** @var array<string,mixed> */
	private $values = array();

	public function __construct() {
		$env = SandboxRuntime::$envelope;
		$plugin = (string)($env['plugin'] ?? '');
		// A setting's bare manifest name answers only where core has no setting
		// of that name: core code in the fence reads core names (FormWriter
		// reads debug and use_captcha), and a plugin's setting must not stand
		// in for one. The full sbx_{plugin}__{name} always answers.
		$core = self::coreNames();
		foreach ((array)($env['settings'] ?? array()) as $name => $value) {
			$name = (string)$name;
			if (!isset($core[$name]) && !in_array($name, self::PUBLIC_SETTINGS, true)) {
				$this->values[$name] = $value;
			}
			$this->values['sbx_' . $plugin . '__' . $name] = $value;
		}
		foreach ((array)($env['site'] ?? array()) as $name => $value) {
			if (in_array($name, self::PUBLIC_SETTINGS, true)) {
				$this->values[$name] = $value;
			}
		}
	}

	/** @return array<string,true> the names core declares (settings.json, granted to the fence) */
	private static function coreNames(): array {
		$decoded = json_decode((string)@file_get_contents(PathHelper::getIncludePath('settings.json')), true);
		$names = array();
		foreach ((array)($decoded['settings'] ?? array()) as $setting) {
			if (is_array($setting) && is_string($setting['name'] ?? null)) {
				$names[$setting['name']] = true;
			}
		}
		return $names;
	}

	/** A setting by name; anything this plugin may not read answers as unset (null). */
	public function get_setting($setting, $calculated_values = true, $fail_silently = false) {
		return $this->values[(string)$setting] ?? null;
	}

	/** The config file is not in the fence. */
	public function config_file_setting(string $setting, $default = null) {
		return $default;
	}

	public function forget_setting(string $setting): void {
	}
}
