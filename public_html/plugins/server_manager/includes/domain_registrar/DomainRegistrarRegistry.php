<?php
/**
 * DomainRegistrarRegistry - discovers registrar drivers by interface.
 *
 * Same scan-and-walk pattern as OAuth2ProviderRegistry and DnsDriverRegistry:
 * require every file in the domain_registrar directory, then walk
 * get_declared_classes() for DomainRegistrarProvider implementations keyed by
 * getKey(). Nothing anywhere names a registrar in a list, so adding a second
 * one is a class in this directory and no edits elsewhere.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarProvider.php'));

class DomainRegistrarRegistry {

	/** @var array<string,string>|null Cached key => class map. */
	private static $registrars = null;

	/** All discovered registrars as [key => class], sorted by label. */
	public static function all(): array {
		if (self::$registrars !== null) {
			return self::$registrars;
		}

		$dir = PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/');
		if (is_dir($dir)) {
			foreach (glob($dir . '*.php') as $file) {
				require_once($file);
			}
		}

		$found = array();
		foreach (get_declared_classes() as $class) {
			if (!in_array('DomainRegistrarProvider', class_implements($class) ?: array(), true)) {
				continue;
			}
			$reflect = new ReflectionClass($class);
			if ($reflect->isAbstract()) {
				continue;
			}
			$key = $class::getKey();
			if ($key !== '') {
				$found[$key] = $class;
			}
		}
		uasort($found, function ($a, $b) { return strcasecmp($a::getLabel(), $b::getLabel()); });

		self::$registrars = $found;
		return self::$registrars;
	}

	/** Registrar class-string for a key, or null. */
	public static function get(string $key): ?string {
		$all = self::all();
		return $all[$key] ?? null;
	}

	/** Only registrars whose credentials are present. */
	public static function configured(): array {
		$out = array();
		foreach (self::all() as $key => $class) {
			if ($class::isConfigured()) {
				$out[$key] = $class;
			}
		}
		return $out;
	}

	/**
	 * An instance of the first configured registrar, or null.
	 *
	 * v1 offers one registrar at a time: the deployment either has credentials
	 * or it does not, and the checkout gate reads exactly that.
	 */
	public static function firstConfigured() {
		foreach (self::configured() as $class) {
			return new $class();
		}
		return null;
	}

	/** [key => label], for an admin chooser. */
	public static function options(): array {
		$out = array();
		foreach (self::all() as $key => $class) {
			$out[$key] = $class::getLabel();
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Name gates — shared by the checkout field and the availability action so
	// the two can never disagree about what is even askable.
	// ------------------------------------------------------------------

	/** A registrable name: at least two ASCII labels, no leading/trailing dash. */
	const DOMAIN_REGEX = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,}$/';

	/** Lowercase a typed name and strip a pasted scheme, path or trailing dot. */
	public static function normalizeName(string $raw): string {
		$name = strtolower(trim($raw));
		$name = preg_replace('#^https?://#', '', $name);
		$name = explode('/', $name)[0];
		return rtrim($name, '.');
	}

	public static function isRegistrableName(string $domain): bool {
		return (bool)preg_match(self::DOMAIN_REGEX, $domain);
	}

	/** Everything after the first label — 'co.uk' for 'a.co.uk'. */
	public static function tldOf(string $domain): string {
		$dot = strpos($domain, '.');
		return $dot === false ? '' : substr($domain, $dot + 1);
	}

	/**
	 * The TLDs this deployment offers, lowercase and without a leading dot.
	 * A name outside them is refused at checkout rather than quoted and then
	 * failed at registration.
	 */
	public static function offeredTlds(): array {
		$settings = Globalvars::get_instance();
		$raw = trim((string)$settings->get_setting('server_manager_domain_tlds', false, true));
		if ($raw === '') {
			$raw = 'com net org';
		}
		$out = array();
		foreach (preg_split('/[\s,]+/', strtolower($raw)) as $tld) {
			$tld = ltrim(trim($tld), '.');
			if ($tld !== '') {
				$out[] = $tld;
			}
		}
		return array_values(array_unique($out));
	}

	public static function tldOffered(string $domain): bool {
		return in_array(self::tldOf($domain), self::offeredTlds(), true);
	}

	/** ".com, .net or .org" — the offered list, said to a person. */
	public static function offeredTldsPhrase(): string {
		$dotted = array_map(function ($t) { return '.' . $t; }, self::offeredTlds());
		if (count($dotted) <= 1) {
			return implode('', $dotted);
		}
		$last = array_pop($dotted);
		return implode(', ', $dotted) . ' or ' . $last;
	}

	/** Test/dev helper: forget cached discovery so a re-scan picks up new classes. */
	public static function reset(): void {
		self::$registrars = null;
	}
}
