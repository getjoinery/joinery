<?php
/**
 * ComputeDriverRegistry - which compute driver acts for a cloud provider.
 *
 * Every account names its provider (oca_provider, cca_provider, and the
 * provider a server's provision record names), and everything that acts on a
 * server or an account asks here for that provider's driver. Nothing outside
 * a driver names one by its class, so a provider added here is offered
 * wherever its capabilities are (specs/site_copy_anything_to_anything.md D2).
 *
 * A capability a driver lacks is asked with `instanceof` on the driver, or
 * supports() before there is a credential to build one with.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_compute/CloudComputeProvider.php'));

class ComputeDriverRegistry {

	/** provider key => [class, label]. */
	const DRIVERS = array(
		'linode'  => array('class' => 'LinodeComputeDriver',  'label' => 'Linode'),
		'hetzner' => array('class' => 'HetznerComputeDriver', 'label' => 'Hetzner'),
	);

	/**
	 * Test seam: provider key => fn(string $token) returning a driver. A key
	 * set here is a provider (a test's fake second provider); one naming a
	 * provider above replaces its driver. Nothing in production sets it.
	 *
	 * @var array<string,callable>
	 */
	public static $factories = array();

	/** Every provider a driver acts for: [key => label]. */
	public static function providers(): array {
		$out = array();
		foreach (self::DRIVERS as $key => $d) {
			$out[$key] = $d['label'];
		}
		foreach (array_keys(self::$factories) as $key) {
			$out[$key] = $out[$key] ?? ucfirst($key);
		}
		return $out;
	}

	/** Is there a driver for this provider. */
	public static function known(string $provider): bool {
		return isset(self::DRIVERS[$provider]) || isset(self::$factories[$provider]);
	}

	/** The provider as people know it: 'Linode', 'Hetzner'. */
	public static function label(string $provider): string {
		return self::DRIVERS[$provider]['label'] ?? ucfirst($provider);
	}

	/**
	 * The provider's driver, acting with this credential.
	 *
	 * @throws CloudComputeException when no driver acts for the provider
	 */
	public static function driver(string $provider, string $token): CloudComputeProvider {
		if (isset(self::$factories[$provider])) {
			return (self::$factories[$provider])($token);
		}
		if (!isset(self::DRIVERS[$provider])) {
			throw new CloudComputeException("No compute driver acts for the cloud provider '{$provider}'.");
		}
		$class = self::DRIVERS[$provider]['class'];
		return new $class($token);
	}

	/**
	 * Does the provider's driver have this capability (an interface name),
	 * asked before there is a credential to build one with.
	 */
	public static function supports(string $provider, string $capability): bool {
		if (isset(self::$factories[$provider])) {
			return self::driver($provider, '') instanceof $capability;
		}
		$class = self::DRIVERS[$provider]['class'] ?? '';
		return $class !== '' && in_array($capability, class_implements($class) ?: array(), true);
	}

	/**
	 * The scopes of $needed this provider's credential lacks, from those it
	 * reported. A provider whose tokens carry no scopes lacks none.
	 */
	public static function missing_scopes(string $provider, array $granted, array $needed): array {
		$class = self::DRIVERS[$provider]['class'] ?? '';
		if ($class === '' || !in_array('CloudTokenScopes', class_implements($class) ?: array(), true)) {
			return array();
		}
		return $class::missingScopes($granted, $needed);
	}
}
