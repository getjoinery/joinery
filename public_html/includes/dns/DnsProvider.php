<?php
/**
 * DnsProvider - the contract every DNS host driver implements.
 *
 * A driver is a thin wrapper over one vendor's API, holding that vendor's
 * quirks so no caller ever learns them. It is constructed with a credential
 * that lives for exactly one publish request and is never persisted anywhere —
 * not in the database, not in a file, not sealed. See docs/dns_management.md.
 *
 * What a driver does NOT decide: who is authoritative for the zone. A driver
 * works the same whether the platform holds the zone in its own account and the
 * owner delegated their nameservers to it, or the owner granted access to a
 * zone they already run elsewhere.
 *
 * The static half of the interface is the driver's declared capability — how it
 * is authorized, whether it can create zones, what it needs set up first. The
 * publish box reads those to decide what to show without instantiating
 * anything.
 *
 * @version 1.1
 * @changelog 1.1 - Added credentialGuide(): a driver says where its credential comes from, rendered beside the field it fills
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRecord.php'));

/** Any driver failure. */
class DnsProviderException extends Exception {}

/**
 * The provider refuses to let this record be written because one of its own
 * features owns it (Cloudflare Email Routing owning MX, for instance). Carries
 * the feature name so the diff can say what to turn off rather than reporting a
 * generic failure.
 */
class DnsManagedRecordException extends DnsProviderException {

	/** @var string The provider feature that holds the record. */
	private $feature;

	public function __construct(string $feature, string $message) {
		parent::__construct($message);
		$this->feature = $feature;
	}

	public function getFeature(): string { return $this->feature; }
}

/** The credential cannot see a zone covering the requested domain. */
class DnsZoneNotFoundException extends DnsProviderException {}

interface DnsProvider {

	/** How the driver is authorized. */
	const CREDENTIAL_OAUTH2 = 'oauth2';
	const CREDENTIAL_API    = 'api';

	// ------------------------------------------------------------------
	// Declared capability (static — read before any credential exists)
	// ------------------------------------------------------------------

	/** Stable key, also the value of the dns_default_provider setting. */
	public static function getKey(): string;

	/** Human-readable label for the provider chooser. */
	public static function getLabel(): string;

	/** CREDENTIAL_OAUTH2 or CREDENTIAL_API. */
	public static function credentialMode(): string;

	/** OAuth2ProviderRegistry key for an OAuth2 driver; '' for an API driver. */
	public static function oauthProviderKey(): string;

	/** Scopes to request at consent time. Empty for an API driver. */
	public static function oauthScopes(): array;

	/**
	 * The fields an API-credential driver collects at the publish moment.
	 * [name => ['label' => string, 'help' => string, 'secret' => bool]]
	 * Empty for an OAuth2 driver. Nothing collected here is ever stored.
	 */
	public static function credentialFields(): array;

	/**
	 * A setup prerequisite the operator must satisfy before this provider can
	 * publish at all — Namecheap's IP allowlist, for instance. '' when there is
	 * none. Surfaced in the publish box rather than left to fail silently.
	 */
	public static function prerequisiteNote(): string;

	/**
	 * Where this provider's credential comes from: the clicks that produce it,
	 * in the vendor's own words. NULL when there is no guide.
	 *
	 * Distinct from prerequisiteNote() on purpose. A prerequisite blocks the
	 * publish and is shown unconditionally; a guide is optional reading behind a
	 * link, for the operator who does not already know their way around this
	 * vendor's dashboard. Namecheap has both, and they read differently.
	 *
	 * The shape is FormWriter's help_modal option, rendered by the publish box:
	 *   title     — what the operator is about to create
	 *   steps     — ordered strings, each one thing to click
	 *   caution   — a wrong-credential warning, kept out of the numbered steps
	 *               (Cloudflare's Global API Key, GoDaddy's OTE key) (optional)
	 *   url       — https deep link to the page where it starts (optional)
	 *   url_label — link text (optional)
	 *   copy      — [['label' => string, 'value' => string]] for values the
	 *               vendor's own form needs from us, offered as copy buttons
	 *               (optional)
	 */
	public static function credentialGuide(): ?array;

	/**
	 * The provider's nameservers, where it publishes a fixed set. Empty when
	 * every zone gets its own names.
	 *
	 * @return string[]
	 */
	public static function nameservers(): array;

	/**
	 * Fragments of a nameserver name that identify this host — how the platform
	 * works out where a domain's DNS actually lives, by looking at the NS records
	 * the domain answers with.
	 *
	 * Fragments, not whole names, because most vendors assign per-zone
	 * nameservers: Cloudflare answers `chuck.ns.cloudflare.com`, Route 53
	 * `ns-123.awsdns-45.org`. Matching is a case-insensitive substring test, so
	 * `ns.cloudflare.com` and `awsdns-` each identify their host whatever the
	 * per-zone prefix happens to be.
	 *
	 * @return string[]
	 */
	public static function nameserverSuffixes(): array;

	/** Whether createZone()/deleteZone() are supported. */
	public static function supportsZones(): bool;

	// ------------------------------------------------------------------
	// Operations (instance — a credential is in hand)
	// ------------------------------------------------------------------

	/**
	 * The provider-side zone identifier covering $domain, by longest-suffix
	 * match against the zones this credential can see — so mail.example.com
	 * resolves to the example.com zone and a same-named sibling TLD cannot be
	 * hit by accident.
	 *
	 * @return string|null Null when no visible zone covers the domain.
	 * @throws DnsProviderException on any API failure.
	 */
	public function zoneFor(string $domain): ?string;

	/**
	 * Every record in a zone, in the platform's vocabulary. Types outside that
	 * vocabulary (NS, SOA, SRV, …) are omitted — they are never compared and
	 * never written. Each returned record carries provider_id.
	 *
	 * @return DnsRecord[]
	 * @throws DnsProviderException on any API failure.
	 */
	public function listRecords(string $zone): array;

	/**
	 * Create one record.
	 * @throws DnsManagedRecordException when a provider feature owns the record.
	 * @throws DnsProviderException on any other API failure.
	 */
	public function createRecord(string $zone, DnsRecord $record): void;

	/**
	 * Replace $live's content with $desired.
	 * @throws DnsManagedRecordException when a provider feature owns the record.
	 * @throws DnsProviderException on any other API failure.
	 */
	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void;

	/**
	 * Delete one record. Only ever called for records the platform owns.
	 * @throws DnsProviderException on any API failure.
	 */
	public function deleteRecord(string $zone, DnsRecord $live): void;

	/**
	 * Create an empty zone for $domain and return its identifier. Used by the
	 * platform-authoritative topology, where the zone is created when the domain
	 * is added so records can be staged before nameservers are switched.
	 * @throws DnsProviderException when unsupported or on API failure.
	 */
	public function createZone(string $domain): string;

	/**
	 * Delete a zone. The caller guarantees it holds no records the platform does
	 * not own — a zone with foreign records is never deleted.
	 * @throws DnsProviderException when unsupported or on API failure.
	 */
	public function deleteZone(string $zone): void;

	/**
	 * Accounts this credential reaches. One entry is the common case and is used
	 * without asking; several means the publish box asks which, once, as a
	 * one-click choice that is never persisted.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	public function accounts(): array;
}
