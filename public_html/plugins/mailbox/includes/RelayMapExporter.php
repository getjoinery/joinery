<?php
/**
 * RelayMapExporter - build this deployment's MAP FRAGMENT for the relay.
 *
 * The relay holds no database connection, so everything it needs to validate
 * recipients at SMTP time, seal to the right key, and forward is compiled here
 * from the enabled InboundEmailDomain + InboundEmailAlias rows and pushed over
 * the tunnel as ONE JSON fragment (specs/mailbox_relay_shared_fleet.md § Map
 * sync: fragment push and shard-side merge). The fragment carries ONLY this
 * tenant's routing data — its domains, recipients, forwarding domains, and
 * per-tenant identity (SRS secret, forward From identity, transport key). The
 * relay's merge unit validates it against the tenant's root-owned domain
 * allowlist and derives all Postfix map lines shard-side; nothing this side
 * emits can bypass that validation.
 *
 * The seal target per recipient follows the existing implicit sealing rule
 * (encryption-at-rest): a recipient whose single grantee holds a Sealed Vault is
 * Fortress → seal to that vault's public key (key_kind=user); everyone else seals
 * to the relay's ambient transport key (key_kind=transport), which Joinery opens
 * at pull. Catch-all recipients have no single owner, so they are always
 * transport-sealed.
 *
 * @version 2.1 - the fragment carries Joinery Direct's served kinds, decoy secret,
 *                limits and caps, so the relay serves the channel as DATA
 * @version 2.0 - emits the tenancy-native fragment (fragment_format 1); the
 *                Postfix artifacts are derived by the relay-side merge unit
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class RelayMapExporter {

	/** @var MailboxRelay */
	private $relay;
	/** @var string */
	private $transport_public_key;
	/** @var Globalvars */
	private $settings;
	/** Per-request owner→vault-public-key cache so a busy domain resolves once. */
	private $vault_cache = array();

	public function __construct(MailboxRelay $relay) {
		$this->relay = $relay;
		$this->transport_public_key = $relay->ensureTransportKeypair();
		$this->settings = Globalvars::get_instance();
	}

	/**
	 * Build the fragment. The output is DETERMINISTIC for a given routing state
	 * (no timestamps, no counters — the fragment's 'version' is 0 here and
	 * stamped by RelayMapSync just before a real push) so the sync can hash it
	 * and skip an unchanged push. Returns:
	 *   [ 'fragment' => string ]   // the fragment JSON (pretty)
	 */
	public function build(): array {
		$domains = new MultiInboundEmailDomain(array('enabled' => true, 'deleted' => false));
		$domains->load();

		// Forward From-rewrite identity, mirroring InboundEmailRouter::buildForwardMessage /
		// forwardedFromDisplay so relay-side forwards align DMARC exactly as colocated
		// forwards do (specs/mailbox_relay_fix_pack.md § Fix 5).
		$forward_from = (string)$this->settings->get_setting('defaultemail');
		$forward_from_name = (string)($this->settings->get_setting('defaultemailname') ?: 'Inbound Email');
		$forward_show_via = ((string)$this->settings->get_setting('mailbox_from_show_via') !== '0');

		$fragment = array(
			'fragment_format'      => 1,
			'tenant'               => $this->relay->tenantSlug(),
			'version'              => 0,
			'srs_secret'           => $this->srsSecret(),
			'forward_from_name'    => $forward_from_name,
			'forward_show_via'     => $forward_show_via,
			'transport_public_key' => $this->transport_public_key,
			'forwarding_domains'   => array(),
			'recipients'           => array(),
			'domains'              => array(),
		);

		// Joinery Direct (docs/joinery_direct.md § The relay at Fortress). At
		// Fortress the relay IS the endpoint, so everything it needs to answer a
		// preflight travels here AS DATA: the served-kind list it compares as
		// opaque strings, the domain secret behind decoy keys, and the limits and
		// caps it enforces at the edge. That is what keeps a new payload kind a
		// map update rather than a relay release — RELAY_VERSION moves only when
		// the shared layer itself changes.
		$fragment = array_merge($fragment, $this->directFragment());

		// SRS-bounce accept (specs/mailbox_relay_fix_pack.md § Fix 6): bounces to
		// forwarded mail return to SRS0=...@forwardingdomain, which is not in the
		// alias list. The relay must accept these (the merge derives a regexp
		// check_recipient_access entry per forwarding domain) and the sealer must
		// store them (transport key) so the pull consumer can decode the NDR.
		// Gated on the SRS setting: with SRS off no forward generates an SRS
		// sender, so accepting the addresses would only spool bounces the
		// consumer must then discard (§ R2-4).
		$srs_on = (bool)$this->settings->get_setting('mailbox_srs_enabled');
		$forwarding_domains = array();

		foreach ($domains as $domain) {
			$domain_name = strtolower(trim((string)$domain->get('ied_domain')));
			if ($domain_name === '') {
				continue;
			}
			// IMAP-source domains (mail pulled by IMAP poll, no MX at the relay) are
			// not fronted by the relay. Including them makes the relay wrongly
			// authoritative for e.g. gmail.com, so a forward to any address there
			// loops back into the sealer instead of leaving over SMTP.
			if ((bool)$domain->get('ied_is_imap_source')) {
				continue;
			}

			$catch_all_mode = (string)$domain->get('ied_catch_all_mode');
			$catch_all_address = trim((string)$domain->get('ied_catch_all_address'));
			$reject_unmatched = (bool)$domain->get('ied_reject_unmatched');
			$forwarding_domain = strtolower((string)$domain->forwarding_subdomain());
			if ($srs_on && $forwarding_domain !== '') {
				$forwarding_domains[$forwarding_domain] = true;
			}

			// Map the domain catch-all onto the sealer's store|forward|none.
			$map_catch_mode = 'none';
			if ($catch_all_mode === InboundEmailDomain::CATCHALL_STORE) {
				$map_catch_mode = 'store';
			} elseif ($catch_all_mode === InboundEmailDomain::CATCHALL_FORWARD && $catch_all_address !== '') {
				$map_catch_mode = 'forward';
			}

			$fragment['domains'][$domain_name] = array(
				'catch_all_mode'    => $map_catch_mode,
				'catch_all_address' => $catch_all_address,
				'reject_unmatched'  => $reject_unmatched,
				'public_key'        => $this->transport_public_key,
				'key_kind'          => 'transport',
				'forwarding_domain' => $forwarding_domain,
				'forward_from'      => $forward_from,
			);

			$aliases = new MultiInboundEmailAlias(array(
				'domain_id' => $domain->key, 'enabled' => true, 'deleted' => false,
			));
			$aliases->load();

			foreach ($aliases as $alias) {
				$local = strtolower(trim((string)$alias->get('iea_alias')));
				if ($local === '') {
					continue;
				}
				$address = $local . '@' . $domain_name;

				list($public_key, $key_kind) = $this->sealTargetForAlias($alias, $domain);

				$fragment['recipients'][$address] = array(
					'public_key'        => $public_key,
					'key_kind'          => $key_kind,
					// The generation the relay reports in a Direct accept, so a
					// sealed part can be tagged with the key it was sealed to and
					// an unopenable message told apart from a corrupt one.
					'key_generation'    => $this->keyGenerationFor($alias, $domain, $key_kind),
					'mode'              => (string)$alias->get('iea_delivery_mode'),
					'destinations'      => array_values($alias->get_destinations_array()),
					'forwarding_domain' => $forwarding_domain,
					'forward_from'      => $forward_from,
				);
			}
		}

		$fragment['forwarding_domains'] = array_keys($forwarding_domains);
		sort($fragment['forwarding_domains']);

		// Deterministic ordering so an unchanged routing state hashes identically.
		ksort($fragment['recipients']);
		ksort($fragment['domains']);

		return array(
			'fragment' => json_encode($fragment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		);
	}

	/**
	 * The Joinery Direct half of the fragment.
	 *
	 * With Direct off this still ships `direct_enabled: false` rather than
	 * omitting the keys, so a relay merging an older fragment and a newer one
	 * reads the same shape from both and a tenant turning Direct OFF actually
	 * turns it off at the edge.
	 */
	private function directFragment(): array {
		require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
		require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));

		if (!DirectSettings::enabled()) {
			return array('direct_enabled' => false, 'direct_kinds' => array());
		}
		return array(
			'direct_enabled'      => true,
			'direct_kinds'        => array_values(DirectKinds::servedNames()),
			// The relay answers preflights on this tenant's behalf, so it needs
			// the same secret the box would derive a decoy from — a decoy that
			// differed between the two would be a distinguisher in itself.
			'direct_decoy_secret' => DirectSettings::decoySecret(),

			'direct_preflight_limit'          => DirectSettings::preflightLimit(),
			'direct_preflight_window'         => DirectSettings::preflightWindowSeconds(),
			'direct_max_parts'                => DirectSettings::maxParts(),
			'direct_max_part_bytes'           => DirectSettings::maxBytesPerPart(),
			'direct_max_total_bytes'          => DirectSettings::maxTotalBytes(),
			'direct_spool_domain_cap_bytes'   => DirectSettings::spoolDomainCapBytes(),
			'direct_spool_address_cap_bytes'  => DirectSettings::spoolAddressCapBytes(),
			'direct_session_ttl'              => DirectSettings::sessionTtlSeconds(),
		);
	}

	/**
	 * The (public_key, key_kind) an alias's mail is sealed to. Only a Fortress
	 * domain seals to the owner's vault key (key_kind=user → sealed-to-owner,
	 * pending-parse at unlock); every other posture — including a Private domain
	 * whose owner holds a vault — seals to the ambient transport key, which
	 * Joinery opens at pull and re-seals at ingest per its own level
	 * (specs/mailbox_security_levels.md § Level → mechanism-branch switch, point
	 * 2). A key_kind=user blob therefore exists only for Fortress, so the
	 * pending-parse path needs no level check of its own.
	 */
	private function sealTargetForAlias($alias, $domain): array {
		if ($domain->security_level() === InboundEmailDomain::LEVEL_FORTRESS) {
			$owner_id = InboundEmailMessage::singleOwnerUserId(intval($alias->key));
			if ($owner_id !== null) {
				$vault_pk = $this->vaultPublicKey($owner_id);
				if ($vault_pk !== null) {
					return array($vault_pk, 'user');
				}
			}
		}
		return array($this->transport_public_key, 'transport');
	}

	/**
	 * The key generation to report for one alias. A transport-key recipient has
	 * no vault generation of its own, so it reports 1 — the same value a
	 * never-rotated vault carries, which is also what a decoy reports, so the
	 * three are indistinguishable on the wire.
	 */
	private function keyGenerationFor($alias, $domain, string $key_kind): int {
		if ($key_kind !== 'user') {
			return 1;
		}
		$owner_id = InboundEmailMessage::singleOwnerUserId(intval($alias->key));
		if ($owner_id === null) {
			return 1;
		}
		try {
			$vault = UserEncryptionVault::loadForUser($owner_id);
		} catch (\Throwable $e) {
			return 1;
		}
		return ($vault !== null) ? max(1, intval($vault->get('uev_key_generation'))) : 1;
	}

	private function vaultPublicKey(int $owner_id): ?string {
		if (array_key_exists($owner_id, $this->vault_cache)) {
			return $this->vault_cache[$owner_id];
		}
		$vault = UserEncryptionVault::loadForUser($owner_id);
		$pk = $vault !== null ? (string)$vault->get('uev_public_key') : null;
		$this->vault_cache[$owner_id] = $pk;
		return $pk;
	}

	/** The SRS secret the relay uses for forward-mode envelope rewriting, or ''. */
	private function srsSecret(): string {
		if (!$this->settings->get_setting('mailbox_srs_enabled')) {
			return '';
		}
		return (string)$this->settings->get_setting('mailbox_srs_secret');
	}
}
