<?php
/**
 * DnsDriverRegistry - discovers DNS host drivers by interface.
 *
 * Same scan-and-walk pattern as OAuth2ProviderRegistry: require every file in
 * includes/dns/drivers/, then walk get_declared_classes() for DnsProvider
 * implementations keyed by getKey(). The provider chooser is populated from
 * this, never from a hardcoded list, so a driver added to the directory appears
 * in the UI without touching a page.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsProvider.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class DnsDriverRegistry {

	/** The provider used when a deployment has expressed no preference. */
	const DEFAULT_PROVIDER = 'linode';

	/** @var array<string,string>|null Cached key => class map. */
	private static $drivers = null;

	/** All discovered drivers as [key => class], sorted by label. */
	public static function all(): array {
		if (self::$drivers !== null) {
			return self::$drivers;
		}

		$dirs = array(PathHelper::getIncludePath('includes/dns/drivers/'));

		try {
			foreach (PluginHelper::getActivePlugins() as $name => $plugin) {
				$dirs[] = PathHelper::getIncludePath('plugins/' . $name . '/includes/dns_drivers/');
			}
		} catch (Throwable $e) {
			error_log('DnsDriverRegistry: error enumerating active plugins: ' . $e->getMessage());
		}

		foreach ($dirs as $dir) {
			if (is_dir($dir)) {
				foreach (glob($dir . '*.php') as $file) {
					require_once($file);
				}
			}
		}

		$found = array();
		foreach (get_declared_classes() as $class) {
			if (in_array('DnsProvider', class_implements($class) ?: array(), true)) {
				$reflect = new ReflectionClass($class);
				if ($reflect->isAbstract()) {
					continue;
				}
				$key = $class::getKey();
				if ($key !== '') {
					$found[$key] = $class;
				}
			}
		}
		uasort($found, function ($a, $b) { return strcasecmp($a::getLabel(), $b::getLabel()); });

		self::$drivers = $found;
		return self::$drivers;
	}

	/** Driver class-string for a key, or null. */
	public static function get(string $key): ?string {
		$drivers = self::all();
		return $drivers[$key] ?? null;
	}

	/** [key => label], for the provider chooser. No tiers, no rankings. */
	public static function options(): array {
		$out = array();
		foreach (self::all() as $key => $class) {
			$out[$key] = $class::getLabel();
		}
		return $out;
	}

	/**
	 * The deployment's default DNS provider: a settings value that is a provider
	 * NAME, never a secret. Falls back to Linode with no configuration at all,
	 * and to any discovered driver if even that is missing — the page must never
	 * render a chooser pointing at nothing.
	 */
	public static function defaultKey(): string {
		$settings = Globalvars::get_instance();
		$preference = trim((string)$settings->get_setting('dns_default_provider'));
		if ($preference !== '' && self::get($preference) !== null) {
			return $preference;
		}
		if (self::get(self::DEFAULT_PROVIDER) !== null) {
			return self::DEFAULT_PROVIDER;
		}
		$all = self::all();
		return $all ? (string)array_key_first($all) : '';
	}

	/**
	 * Which driver hosts this domain's DNS, worked out from the NS records the
	 * domain actually answers with.
	 *
	 * This is what lets the publish box lead with the host a domain is already
	 * on instead of the deployment's default. Matching is a case-insensitive
	 * substring test against each driver's nameserverSuffixes(), because most
	 * vendors assign per-zone names; the longest matching fragment wins, so a
	 * specific one is never beaten by a broader one.
	 *
	 * @param string[] $live_ns The domain's NS records.
	 * @return string|null Driver key, or null when no shipped driver serves this host.
	 */
	public static function identifyHost(array $live_ns): ?string {
		$best = null;
		$best_len = 0;
		foreach ($live_ns as $ns) {
			$ns = strtolower(rtrim(trim((string)$ns), '.'));
			if ($ns === '') {
				continue;
			}
			foreach (self::all() as $key => $class) {
				foreach ($class::nameserverSuffixes() as $suffix) {
					$suffix = strtolower(rtrim(trim((string)$suffix), '.'));
					if ($suffix === '' || strpos($ns, $suffix) === false) {
						continue;
					}
					if (strlen($suffix) > $best_len) {
						$best = $key;
						$best_len = strlen($suffix);
					}
				}
			}
		}
		return $best;
	}

	/** Test/dev helper: forget cached discovery so a re-scan picks up new classes. */
	public static function reset(): void {
		self::$drivers = null;
	}
}
