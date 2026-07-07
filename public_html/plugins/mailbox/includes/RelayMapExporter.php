<?php
/**
 * RelayMapExporter - build the DB-free routing map the relay runs on.
 *
 * (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 3). The relay
 * holds no database connection, so everything it needs to validate recipients at
 * SMTP time, seal to the right key, and forward, is compiled here from the
 * enabled InboundEmailDomain + InboundEmailAlias rows and pushed over the tunnel.
 *
 * It emits four artifacts:
 *
 *   - relay-domains.map — the domains the relay is authoritative for
 *     (Postfix relay_domains). reject_unauth_destination accepts recipients in
 *     these and rejects relay attempts for anything else.
 *   - recipients.access — check_recipient_access rules that preserve
 *     reject_unmatched semantics: `alias@domain OK` for every enabled alias, plus
 *     a domain-level `OK` (accept-all: catch-all or reject_unmatched=false) or
 *     `REJECT` (reject-unmatched, no catch-all). Postfix matches the full address
 *     before the domain, so listed aliases are accepted even under a domain REJECT
 *     — no backscatter, and a newly synced alias stops bouncing.
 *   - transport.map — routes each hosted domain to the `joinery` pipe (the Go
 *     sealer) as its transport.
 *   - routing.json — the sealer's per-recipient table: mode, destinations, the
 *     public key to seal to, and whether that key is the user's vault key
 *     (Fortress) or the ambient transport key (Standard/Private).
 *
 * The seal target per recipient follows the existing implicit sealing rule
 * (encryption-at-rest): a recipient whose single grantee holds a Sealed Vault is
 * Fortress → seal to that vault's public key (key_kind=user); everyone else seals
 * to the relay's ambient transport key (key_kind=transport), which Joinery opens
 * at pull. Catch-all recipients have no single owner, so they are always
 * transport-sealed.
 *
 * @version 1.0
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
	 * Build every artifact. The output is DETERMINISTIC for a given routing state
	 * (no timestamps, no counters) so RelayMapSync can hash it and skip an
	 * unchanged push. Returns:
	 *   [
	 *     'relay_domains'    => string,  // Postfix map body
	 *     'recipients'       => string,  // Postfix access-map body
	 *     'transport'        => string,  // Postfix transport-map body
	 *     'routing_json'     => string,  // the sealer routing table (pretty JSON)
	 *   ]
	 */
	public function build(): array {
		$domains = new MultiInboundEmailDomain(array('enabled' => true, 'deleted' => false));
		$domains->load();

		$relay_domains = array();
		$recipient_access = array();
		$transport = array();
		// Forward From-rewrite identity, mirroring InboundEmailRouter::buildForwardMessage /
		// forwardedFromDisplay so relay-side forwards align DMARC exactly as colocated
		// forwards do (specs/mailbox_relay_fix_pack.md § Fix 5).
		$forward_from = (string)$this->settings->get_setting('defaultemail');
		$forward_from_name = (string)($this->settings->get_setting('defaultemailname') ?: 'Inbound Email');
		$forward_show_via = ((string)$this->settings->get_setting('mailbox_from_show_via') !== '0');

		$routing = array(
			'srs_secret'            => $this->srsSecret(),
			'forward_from_name'     => $forward_from_name,
			'forward_show_via'      => $forward_show_via,
			'transport_public_key'  => $this->transport_public_key,
			'forwarding_domains'    => array(),
			'recipients'            => array(),
			'domains'               => array(),
		);

		// SRS-bounce accept (specs/mailbox_relay_fix_pack.md § Fix 6): bounces to
		// forwarded mail return to SRS0=...@forwardingdomain, which is not in the
		// alias list. Postfix must accept these (a regexp check_recipient_access
		// entry per forwarding domain) and the sealer must store them (transport
		// key) so the pull consumer can decode the NDR. Gated on the SRS setting:
		// with SRS off no forward generates an SRS sender, so accepting the
		// addresses would only spool bounces the consumer must then discard —
		// reject them at SMTP time instead (§ R2-4).
		$srs_on = (bool)$this->settings->get_setting('mailbox_srs_enabled');
		$forwarding_domains = array();

		foreach ($domains->results as $domain) {
			$domain_name = strtolower(trim((string)$domain->get('ied_domain')));
			if ($domain_name === '') {
				continue;
			}
			$relay_domains[] = $domain_name . "\tOK";
			$transport[] = $domain_name . "\tjoinery:";

			$catch_all_mode = (string)$domain->get('ied_catch_all_mode');
			$catch_all_address = trim((string)$domain->get('ied_catch_all_address'));
			$reject_unmatched = (bool)$domain->get('ied_reject_unmatched');
			$forwarding_domain = strtolower((string)$domain->forwarding_subdomain());
			if ($srs_on && $forwarding_domain !== '') {
				$forwarding_domains[$forwarding_domain] = true;
				// A forwarding subdomain distinct from the hosted domain must also be
				// accepted as a relay destination + routed to the sealer, or the SRS
				// bounce is rejected as an unauth relay before recipient checks run.
				if ($forwarding_domain !== $domain_name) {
					$relay_domains[] = $forwarding_domain . "\tOK";
					$transport[] = $forwarding_domain . "\tjoinery:";
				}
			}

			// Map the domain catch-all onto the sealer's store|forward|none.
			$map_catch_mode = 'none';
			if ($catch_all_mode === InboundEmailDomain::CATCHALL_STORE) {
				$map_catch_mode = 'store';
			} elseif ($catch_all_mode === InboundEmailDomain::CATCHALL_FORWARD && $catch_all_address !== '') {
				$map_catch_mode = 'forward';
			}

			// Accept-all when the domain stores or forwards unmatched mail, or when
			// it explicitly does not reject it; otherwise reject the domain (listed
			// aliases still match first and are accepted).
			$accept_all = ($map_catch_mode !== 'none') || !$reject_unmatched;
			$recipient_access[] = $domain_name . "\t" . ($accept_all ? 'OK' : 'REJECT');

			$routing['domains'][$domain_name] = array(
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

			foreach ($aliases->results as $alias) {
				$local = strtolower(trim((string)$alias->get('iea_alias')));
				if ($local === '') {
					continue;
				}
				$address = $local . '@' . $domain_name;
				$recipient_access[] = $address . "\tOK";

				list($public_key, $key_kind) = $this->sealTargetForAlias($alias);

				$routing['recipients'][$address] = array(
					'public_key'        => $public_key,
					'key_kind'          => $key_kind,
					'mode'              => (string)$alias->get('iea_delivery_mode'),
					'destinations'      => array_values($alias->get_destinations_array()),
					'forwarding_domain' => $forwarding_domain,
					'forward_from'      => $forward_from,
				);
			}
		}

		// The sealer needs the set of forwarding domains (to store SRS bounces) and
		// the transport key to seal them to.
		$routing['forwarding_domains'] = array_values(array_keys($forwarding_domains));
		sort($routing['forwarding_domains']);

		// Postfix regexp check_recipient_access accepting SRS bounces at each
		// forwarding domain (matched against the full recipient). No postmap needed
		// for a regexp map.
		// SRS0 only: SRSRewriter generates (and can decode) only SRS0; an SRS1
		// at our forwarding domain is undecodable, so accepting it would spool
		// blobs the consumer can only discard.
		$srs_access = array();
		foreach (array_keys($forwarding_domains) as $fd) {
			$srs_access[] = '/^SRS0=[^@]*@' . preg_quote($fd, '/') . '$/ OK';
		}

		// Deterministic ordering so an unchanged routing state hashes identically.
		sort($relay_domains);
		$relay_domains = array_values(array_unique($relay_domains));
		sort($recipient_access);
		sort($transport);
		$transport = array_values(array_unique($transport));
		sort($srs_access);
		ksort($routing['recipients']);
		ksort($routing['domains']);

		return array(
			'relay_domains' => $this->joinLines($relay_domains),
			'recipients'    => $this->joinLines($recipient_access),
			'transport'     => $this->joinLines($transport),
			'srs_access'    => $this->joinLines($srs_access),
			'routing_json'  => json_encode($routing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		);
	}

	/**
	 * The (public_key, key_kind) an alias's mail is sealed to. Fortress (single
	 * grantee with a vault) → the user's vault key; everyone else → the ambient
	 * transport key.
	 */
	private function sealTargetForAlias($alias): array {
		$owner_id = InboundEmailMessage::singleOwnerUserId(intval($alias->key));
		if ($owner_id !== null) {
			$vault_pk = $this->vaultPublicKey($owner_id);
			if ($vault_pk !== null) {
				return array($vault_pk, 'user');
			}
		}
		return array($this->transport_public_key, 'transport');
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

	private function joinLines(array $lines): string {
		return $lines ? implode("\n", $lines) . "\n" : "";
	}
}
