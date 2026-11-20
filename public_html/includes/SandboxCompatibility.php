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

		foreach ($m->permittedOwnRecords() as $model => $verbs) {
			$why = self::ownRecordsProblem((string)$model);
			if ($why !== null) {
				$problems[] = $why;
				continue;
			}
			foreach ($verbs as $verb) {
				$kind = $verb === 'read' ? 'read' : 'write';
				if (!isset($exposed[$kind][$model])) {
					$problems[] = "It needs to $verb each member's own $model records, which this site's API does not allow.";
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
	 * Why a model cannot be granted as each member's own rows, or null: it
	 * must be one, not public (own rows of public content mean nothing), with
	 * an owner column (so a list of a member's rows is narrowed in the query
	 * and its count reveals no one else's) and the API's read path.
	 */
	public static function ownRecordsProblem(string $model): ?string {
		if (!ApiKey::is_model_class($model)) {
			return "It needs each member's own $model records, which this site does not have.";
		}
		if ($model::$api_public_read) {
			return "It asks for each member's own $model records, but $model is public content, which has no owner's rows.";
		}
		if (!$model::$api_readable) {
			return "It asks for each member's own $model records, which this site's API does not show.";
		}
		// Credentials and what only the top admins handle (permissions.json
		// classes keys and restricted): a member's passkeys, sign-in records.
		if (in_array((string)$model::$access_class, array('keys', 'restricted'), true)) {
			return "It asks for each member's own $model records, which hold sign-in or key details no plugin is given.";
		}
		if ($model::access_owner_column() === null) {
			return "It asks for each member's own $model records, but $model has no owner column to tell whose a row is.";
		}
		return null;
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
