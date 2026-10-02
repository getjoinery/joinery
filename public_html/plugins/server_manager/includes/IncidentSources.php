<?php
/**
 * IncidentSources — the registry of the conditions this management node
 * watches for (incident_triage.md, Types).
 *
 * A source registers itself from a plugin bootstrap, as a header notice does
 * with AdminNotices; the reconciler, the pages and the notifications never
 * learn what any one source means. Plugin bootstraps are pulled in before the
 * registry is read, so a bootstrap-side registration is all a source needs.
 *
 * @version 1.0
 */
class IncidentSources {

	/** @var array<string, IncidentSource> by source name */
	private static $sources = array();

	/** Register a source. Registering the same name again replaces it. */
	public static function register(IncidentSource $source): void {
		$name = $source->name();
		if (!preg_match('/^plane:[a-z][a-z0-9_]{2,40}$/', $name)) {
			throw new InvalidArgumentException('An incident source is named plane:<name>, got ' . $name);
		}
		self::$sources[$name] = $source;
	}

	/** Every registered source, by name. */
	public static function all(): array {
		if (class_exists('PluginBootstraps')) {
			try {
				PluginBootstraps::load();
			} catch (\Throwable $e) {
				error_log('[IncidentSources] plugin bootstraps failed to load: ' . $e->getMessage());
			}
		}
		return self::$sources;
	}

	/** One source by name, or null. */
	public static function get(string $name): ?IncidentSource {
		return self::all()[$name] ?? null;
	}

	/** Forget every registration (tests only). */
	public static function resetForTests(): void {
		self::$sources = array();
	}
}
?>
