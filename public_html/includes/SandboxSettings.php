<?php
/**
 * SandboxSettings — a sandboxed plugin's settings, as ordinary declared settings.
 *
 * A manifest's `settings` become declarations in the same registry core and
 * native plugins use (SettingsDeclarations), stored as rows in stg_settings
 * named sbx_{plugin}__{name}. So the settings form (SettingsFieldRenderer), the
 * one writer (SettingsWriter), Setting::put() and the sealing of secrets all
 * apply to them unchanged. The module never reads the table: core hands it the
 * values it declared, per call (values()).
 *
 * @version 1.0
 */

class SandboxSettings {

	/** Manifest setting type => the declaration type the settings form draws. */
	const TYPES = array(
		'text'     => 'text',
		'textarea' => 'textarea',
		'number'   => 'number',
		'boolean'  => 'checkbox',
		'select'   => 'select',
		'url'      => 'text',
		'email'    => 'text',
	);

	/**
	 * Declarations for every installed sandboxed plugin whose manifest is
	 * valid, in SettingsDeclarations' raw entry shape (it adds _source and
	 * _group). A folder that is not installed declares nothing.
	 *
	 * @return array<string,array[]> plugin name => entries
	 */
	public static function declarationsByPlugin(): array {
		$out = array();
		foreach (SandboxPlugins::installedOnDisk() as $plugin) {
			try {
				$out[$plugin] = self::declarationsFor(SandboxPlugins::manifest($plugin));
			} catch (SandboxManifestException $e) {
				continue;
			}
		}
		return $out;
	}

	/** @return array[] one plugin's declarations */
	public static function declarationsFor(SandboxManifest $manifest): array {
		$entries = array();
		foreach ($manifest->settings() as $name => $s) {
			$type = $s['type'] ?? 'text';
			$entry = array(
				'name'  => SandboxManifest::settingName($manifest->name(), $name),
				'label' => $s['label'],
				'type'  => self::TYPES[$type] ?? 'text',
				'default' => isset($s['default']) ? (is_bool($s['default']) ? ($s['default'] ? '1' : '0') : (string)$s['default']) : '',
			);
			if (!empty($s['secret'])) {
				// A secret is drawn as a password field: the stored value never
				// reaches the page, and a blank submission keeps it.
				$entry['secret'] = true;
				$entry['type'] = 'password';
			}
			if (isset($s['help'])) {
				$entry['helptext'] = $s['help'];
			}
			if ($type === 'select') {
				$entry['options'] = $s['options'];
			}
			$validation = array();
			if (!empty($s['required'])) {
				$validation['required'] = true;
			}
			if ($type === 'url') {
				$validation['url'] = true;
			} elseif ($type === 'email') {
				$validation['email'] = true;
			} elseif ($type === 'number') {
				$validation['number'] = true;
			}
			if ($validation) {
				$entry['validation'] = $validation;
			}
			$entries[] = $entry;
		}
		return $entries;
	}

	/** Seed the plugin's declared settings that have no row yet. */
	public static function seed(string $plugin): void {
		try {
			$entries = self::declarationsFor(SandboxPlugins::manifest($plugin));
		} catch (SandboxManifestException $e) {
			return;
		}
		Setting::seed_declared($entries);
		SettingsDeclarations::reset();
	}

	/** Remove the plugin's setting rows; uninstall. */
	public static function unseed(string $plugin): void {
		try {
			$entries = self::declarationsFor(SandboxPlugins::manifest($plugin));
		} catch (SandboxManifestException $e) {
			return;
		}
		Setting::unseed_declared($entries);
		SettingsDeclarations::reset();
	}

	/**
	 * The values a module is handed with each call: every setting its
	 * manifest declares, by its manifest name, secrets opened. A secret that
	 * cannot be opened is handed as empty.
	 *
	 * @return array<string,string>
	 */
	public static function values(string $plugin): array {
		$out = array();
		try {
			$manifest = SandboxPlugins::manifest($plugin);
		} catch (SandboxManifestException $e) {
			return $out;
		}
		$settings = Globalvars::get_instance();
		foreach ($manifest->settings() as $name => $s) {
			$value = $settings->get_setting(SandboxManifest::settingName($plugin, $name), true, true);
			$out[$name] = $value === null || $value === false ? '' : (string)$value;
		}
		return $out;
	}
}
