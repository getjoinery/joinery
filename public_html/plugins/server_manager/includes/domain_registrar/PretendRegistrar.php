<?php
/**
 * PretendRegistrar - a registrar that answers the name check and nothing else,
 * for a workspace box that cannot reach a real one.
 *
 * A box whose saved registrar key does not open (it has its own secret key)
 * still needs the domain step of the setup page to behave, so copy and layout
 * can be worked on there. This answers "is the name free, and what does it
 * cost": every name is free at a fixed price, except a name containing
 * 'taken', which is taken. Anything that would buy or look up a real name
 * refuses, terminally.
 *
 * It is on only when the site's config file says so
 * ($this->settings['registrar_pretend'] = true in Globalvars_site.php). That
 * file is never written by the platform or by an install, and the database is
 * never asked, so no setting on any real site can turn it on. While it is on it
 * is the only registrar the box offers (DomainRegistrarRegistry::configured()).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarProvider.php'));

class PretendRegistrar implements DomainRegistrarProvider {

	const PRICE_YEAR = '11.28';
	const RENEW_YEAR = '15.88';

	public static function getKey(): string   { return 'pretend'; }
	public static function getLabel(): string { return 'Pretend registrar (workspace only)'; }

	public static function isConfigured(): bool {
		return self::on();
	}

	/** Whether the site's config file turns it on. */
	public static function on(): bool {
		return Globalvars::get_instance()->config_file_setting('registrar_pretend', false) === true;
	}

	public function checkAvailability(array $domains): array {
		$out = array();
		foreach ($domains as $domain) {
			$name = strtolower(trim((string)$domain));
			if ($name === '') {
				continue;
			}
			$free = strpos($name, 'taken') === false;
			$out[$name] = array(
				'available'  => $free,
				'price_year' => $free ? self::PRICE_YEAR : null,
				'renew_year' => $free ? self::RENEW_YEAR : null,
				'premium'    => false,
				'message'    => $free ? '' : 'That name is already taken.',
			);
		}
		return $out;
	}

	public function register(string $domain, array $registrant, int $years): array {
		throw self::refuse('register ' . $domain);
	}

	public function applyWhoisPrivacy(string $domain): void {
		throw self::refuse('set WHOIS privacy on ' . $domain);
	}

	/** The same shape the real registrar asks for, so checkout validates alike. */
	public function normalizeRegistrantPhone(string $phone): string {
		return NamecheapRegistrar::normalizePhone($phone);
	}

	public function dnsDriverKey(): string { return ''; }

	public function dnsCredential(): array { return array(); }

	public function getExpiry(string $domain): ?string {
		throw self::refuse('read the expiry of ' . $domain);
	}

	public function inAccount(string $domain): bool {
		throw self::refuse('look up ' . $domain);
	}

	public function graduationMechanism(): string { return 'account_push'; }

	private static function refuse(string $what): DomainRegistrarException {
		return DomainRegistrarException::terminal('The pretend registrar cannot ' . $what
			. ': this is a workspace box, which registers no real names.');
	}
}
