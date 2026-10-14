<?php
/**
 * SandboxCompatibility — whether this site has what a sandboxed plugin needs (Q13).
 *
 * The API, the signal catalog and the manifest's UI primitives are a promise
 * to plugin authors: additions are always allowed, and a change that breaks
 * an existing one raises the platform version. A manifest names the platform
 * version it was built for; this refuses a plugin built for a later one, and
 * one that needs an action, a model or a signal this site does not have,
 * naming each, instead of letting it install and fail at its first call.
 *
 * The UI primitives (page section and field types) are checked by the
 * manifest validator, which knows only the ones this site renders.
 *
 * Nor does a sandboxed plugin take a native plugin's name: one in the
 * publisher's catalog or on this site's disk. Core checks those by name,
 * meaning their PHP is here, and one arriving from the catalog later would
 * find its name held.
 *
 * @version 1.0
 */

class SandboxCompatibility {

	/** @return string[] every reason this site cannot run the plugin, in words; empty when it can */
	public static function problems(SandboxManifest $m): array {
		$problems = array();
		if (in_array($m->name(), self::nativePluginNames(), true)) {
			$problems[] = 'Its name, ' . $m->name() . ', is a Joinery plugin\'s (a native one, in the publisher\'s catalog or on this site); '
				. 'a sandboxed plugin needs a name of its own.';
		}
		if ($m->platform() > SandboxManifest::PLATFORM_VERSION) {
			$problems[] = 'It was built for platform version ' . $m->platform() . '; this site speaks version '
				. SandboxManifest::PLATFORM_VERSION . '. Upgrade the site first.';
		}

		$catalog = ApiLogicEndpoint::actionCatalog();
		foreach ($m->permittedActions() as $action) {
			if (!isset($catalog[$action])) {
				$problems[] = "It needs the action $action, which this site does not have (or whose feature is off).";
			} elseif (!ApiLogicEndpoint::machineKeyCanCall($catalog[$action]['meta'])) {
				$problems[] = "It asks for the action $action, which only a signed-in person can use.";
			}
		}

		$exposed = array('read' => array(), 'write' => array());
		foreach (LibraryFunctions::discover_model_classes(array('include_plugins' => true, 'plugin_status' => 'active')) as $class) {
			if ($class::$api_readable) {
				$exposed['read'][$class] = true;
			}
			if ($class::$api_writable) {
				$exposed['write'][$class] = true;
			}
		}
		foreach ($m->permittedRecords() as $model => $verbs) {
			if (!ApiKey::is_model_class($model)) {
				$problems[] = "It needs the records $model, which this site does not have (is the plugin that holds them active?).";
				continue;
			}
			foreach ($verbs as $verb) {
				$kind = $verb === 'read' ? 'read' : 'write';
				if (!isset($exposed[$kind][$model])) {
					$problems[] = "It needs to $verb $model records, which this site's API does not allow.";
				}
			}
		}

		$signals = SignalBus::signals();
		foreach ($m->signals() as $signal) {
			if (!isset($signals[$signal])) {
				$problems[] = "It needs the signal $signal, which this site does not raise.";
			}
		}
		return $problems;
	}

	/**
	 * The names native plugins have: the publisher's catalog's, as far as this
	 * site knows it (MarketplaceClient keeps a day-old copy; the catalog lists
	 * plugins with a plugin.json), and every plugin.json folder on disk.
	 *
	 * @return string[]
	 */
	public static function nativePluginNames(): array {
		$names = array();
		try {
			$names = (array)(MarketplaceClient::published_names('plugins') ?? array());
		} catch (Throwable $e) {
			// The catalog unknown: the folders on disk still answer.
		}
		foreach (glob(PathHelper::getIncludePath('plugins') . '/*/plugin.json') ?: array() as $json) {
			$names[] = basename(dirname($json));
		}
		return array_values(array_unique(array_map('strval', $names)));
	}
}
