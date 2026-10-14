<?php
/**
 * ContentReportRegistry — the handlers for every kind of content members can
 * report, keyed by entity type.
 *
 * A feature registers its ContentReportHandler from its plugin bootstrap:
 *
 *   ContentReportRegistry::register(new ForumReportHandler());
 *
 * Plugin bootstraps are pulled in before any read, as AdminMenuCounts does, so
 * a caller never has to load them first. A type whose plugin is inactive has
 * no handler: its reports stay in the queue and can only be dismissed.
 *
 * See docs/social_features.md § Content reports.
 *
 * @version 1.0.0
 */
class ContentReportRegistry {
	/** @var array<string, ContentReportHandler> */
	private static $handlers = array();

	/** Register a handler. Registering a type again replaces its handler. */
	public static function register(ContentReportHandler $handler): void {
		self::$handlers[$handler->entityType()] = $handler;
	}

	/** The handler for a type, or null when nothing reportable has that type. */
	public static function get(string $entity_type): ?ContentReportHandler {
		self::loadBootstraps();
		return self::$handlers[$entity_type] ?? null;
	}

	/** @return array<string, ContentReportHandler> every handler, by type */
	public static function all(): array {
		self::loadBootstraps();
		return self::$handlers;
	}

	/** Forget every registration (tests only). */
	public static function resetForTests(): void {
		self::$handlers = array();
	}

	private static function loadBootstraps(): void {
		if (class_exists('PluginBootstraps')) {
			try {
				PluginBootstraps::load();
			} catch (\Throwable $e) {
				error_log('[ContentReportRegistry] plugin bootstraps failed to load: ' . $e->getMessage());
			}
		}
	}
}
?>
