<?php
require_once(PathHelper::getIncludePath('plugins/bookings/includes/SchedulingServiceProvider.php'));

/**
 * Auto-discovers SchedulingServiceProvider implementations from
 * plugins/bookings/includes/scheduling_providers/ — same discovery pattern as
 * EmailSender's provider registry. The native provider is always present;
 * external ones appear when the external-integrations spec adds their files.
 */
class SchedulingProviderRegistry {

	/** @var array|null key => class name, cached per request */
	private static $providers = null;

	private static function discover(): array {
		if (self::$providers !== null) {
			return self::$providers;
		}
		self::$providers = [];
		$dir = PathHelper::getIncludePath('plugins/bookings/includes/scheduling_providers/');
		if (is_dir($dir)) {
			foreach (glob($dir . '*Provider.php') as $file) {
				require_once($file);
				$class = basename($file, '.php');
				if (class_exists($class) && in_array('SchedulingServiceProvider', class_implements($class) ?: [])) {
					self::$providers[$class::getKey()] = $class;
				}
			}
		}
		return self::$providers;
	}

	public static function resetCache(): void {
		self::$providers = null;
	}

	/** @return string[] key => class name */
	public static function all(): array {
		return self::discover();
	}

	/** Instantiate a provider by key, or the native provider as the default. */
	public static function get(?string $key): SchedulingServiceProvider {
		$providers = self::discover();
		if ($key && isset($providers[$key])) {
			return new $providers[$key]();
		}
		if (isset($providers['native'])) {
			return new $providers['native']();
		}
		throw new Exception('No scheduling provider available for key: ' . $key);
	}

	/** key => label, for admin dropdowns. */
	public static function options(): array {
		$out = [];
		foreach (self::discover() as $key => $class) {
			$out[$key] = $class::getLabel();
		}
		return $out;
	}
}
