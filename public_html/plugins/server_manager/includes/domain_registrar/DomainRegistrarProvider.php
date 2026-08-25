<?php
/**
 * DomainRegistrarProvider - buying and holding a domain name, as a seam.
 *
 * The managed-domain leg of provisioning needs four things from a registrar:
 * a live price and availability answer at checkout, a registration that names
 * the BUYER as the legal owner, an expiry date to count down from, and an
 * honest answer to "is this domain still in the operator's account?" — the
 * signal that custody has moved to the buyer.
 *
 * What it deliberately does NOT need is DNS. Publishing records is already a
 * solved problem here (includes/dns/), and a registrar that also hosts DNS is
 * reached through that stack, by key, not through this interface. A registrar
 * seam that re-implemented records would give the platform two ways to write
 * the same zone and no way to diff them.
 *
 * Renewal is absent by design, not by omission: the platform never renews a
 * buyer's domain and never fronts the cost, so there is no call to make. The
 * domain reaches the buyer's own registrar account before its first expiry
 * (see graduationMechanism) and renews there, on their card.
 *
 * @version 1.0
 */

/**
 * A registrar refusal. The transient flag is the whole contract: a transient
 * failure is retried on the next provisioning tick and changes nothing, while
 * a terminal one parks the row for a person. Getting this backwards either
 * spams a registrar with a request it will always refuse, or gives up on a
 * five-second network blip and makes an operator finish the purchase by hand.
 */
class DomainRegistrarException extends Exception {

	/** @var bool True → retry next tick; false → terminal, park and alert. */
	public $transient = false;

	public static function transient(string $message): self {
		$e = new self($message);
		$e->transient = true;
		return $e;
	}

	public static function terminal(string $message): self {
		$e = new self($message);
		$e->transient = false;
		return $e;
	}
}

interface DomainRegistrarProvider {

	/** Stable machine key, e.g. 'namecheap'. */
	public static function getKey(): string;

	/** Human-readable name for admin surfaces. */
	public static function getLabel(): string;

	/** Whether this deployment holds usable credentials for this registrar. */
	public static function isConfigured(): bool;

	// ------------------------------------------------------------------
	// Purchase
	// ------------------------------------------------------------------

	/**
	 * Live availability and one-year price.
	 *
	 * @param string[] $domains
	 * @return array domain => ['available' => bool, 'price_year' => ?string,
	 *                          'premium' => bool, 'message' => string]
	 */
	public function checkAvailability(array $domains): array;

	/**
	 * Register the domain with $registrant as the WHOIS owner.
	 *
	 * @param array $registrant first_name, last_name, address1, city,
	 *                          state_province, postal_code, country (ISO-2),
	 *                          phone, email
	 * @return array ['expiry' => string ISO-UTC 'Y-m-d H:i:s']
	 * @throws DomainRegistrarException
	 */
	public function register(string $domain, array $registrant, int $years): array;

	/** Ensure WHOIS privacy is on. A no-op when registration already enabled it. */
	public function applyWhoisPrivacy(string $domain): void;

	/**
	 * The registrant's phone number in whatever shape this registrar demands,
	 * or '' when the typed value cannot be read as a phone number.
	 *
	 * On the seam because it is the one contact field registrars are strict
	 * about, and a number the registrar rejects fails the registration AFTER
	 * the buyer has paid. Checkout validates by calling this and refusing an
	 * empty answer, so the same function decides both times.
	 */
	public function normalizeRegistrantPhone(string $phone): string;

	// ------------------------------------------------------------------
	// DNS is published through the existing DnsProvider stack, not here.
	// ------------------------------------------------------------------

	/** DnsDriverRegistry key of the driver that serves this registrar's DNS. */
	public function dnsDriverKey(): string;

	/** Credential array to construct that driver with. */
	public function dnsCredential(): array;

	// ------------------------------------------------------------------
	// Lifecycle
	// ------------------------------------------------------------------

	/** Expiry as 'Y-m-d H:i:s' UTC, or null when unknown. */
	public function getExpiry(string $domain): ?string;

	/**
	 * Is the domain still held in the operator's account?
	 *
	 * FALSE is the graduation success signal — the buyer accepted the push and
	 * the domain now lives in their own account — so a "not found in this
	 * account" answer is a value, never an exception.
	 */
	public function inAccount(string $domain): bool;

	// ------------------------------------------------------------------
	// Graduation
	// ------------------------------------------------------------------

	/**
	 * How management custody reaches the buyer:
	 *   'account_push'  the registrar moves the domain between accounts
	 *   'transfer_out'  the buyer transfers it to a registrar of their choice
	 */
	public function graduationMechanism(): string;
}
