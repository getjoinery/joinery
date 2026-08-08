<?php
/**
 * MailboxAliasConfig - shared mailbox-alias config machinery for the joinery_ai
 * email pipeline jobs, which bind a recipe to a list of stored mailbox aliases
 * (`mailbox_aliases` in rcp_source_config).
 *
 * Lives in the mailbox plugin (not joinery_ai) because it is mailbox-domain
 * knowledge, exactly like EmailSecurityDigest — the dependency points
 * mailbox <- joinery_ai, never the reverse, so this class takes plain values
 * (address strings, user ids, config arrays) and never references Recipe or
 * anything else from joinery_ai.
 *
 * See specs/implemented/joinery_ai_email_triage.md § 1a and
 * specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md § Phase 1.
 *
 * It also answers the two security-posture questions a job needs before it can
 * read a mailbox at all (specs/in_window_deferred_work.md): whether the mail is
 * sealed at rest, and whether the domain has consented to AI reading it.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

class MailboxAliasConfig {

	/** address (local@domain) -> alias id, for an enabled, store-capable,
	 *  non-deleted alias. Null when no such alias exists. */
	public static function resolveAliasId(string $address): ?int {
		$address = strtolower(trim($address));
		if ($address === '') return null;

		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT a.iea_inbound_email_alias_id
			   FROM iea_inbound_email_aliases a
			   JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id
			  WHERE a.iea_delete_time IS NULL
			    AND lower(a.iea_alias || '@' || d.ied_domain) = ?
			  LIMIT 1");
		$q->execute([$address]);
		$id = $q->fetchColumn();
		return $id !== false ? (int)$id : null;
	}

	/** address -> "address — description" for every enabled, store-capable
	 *  mailbox — the Job dropdown's option list. */
	public static function aliasOptions(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->query(
			"SELECT a.iea_alias, d.ied_domain, a.iea_description
			   FROM iea_inbound_email_aliases a
			   JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id
			  WHERE a.iea_delete_time IS NULL AND a.iea_is_enabled = true
			    AND a.iea_delivery_mode IN ('store', 'forward_and_store')
			    AND d.ied_is_enabled = true
			  ORDER BY d.ied_domain, a.iea_alias");

		$options = [];
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$address = strtolower($row['iea_alias'] . '@' . $row['ied_domain']);
			$desc = trim((string)$row['iea_description']);
			$options[$address] = $address . ($desc !== '' ? " — $desc" : '');
		}
		return $options;
	}

	/**
	 * The `mailbox_aliases` checkbox-list field array a job's
	 * configDescriptor() returns, with the caller's own label/help text.
	 *
	 * Checkboxes carry no silent-default hazard — nothing is pre-checked, an
	 * untouched form posts an empty list — so shipped templates naturally stay
	 * unbound. An empty list is legal: the recipe covers nothing and finds no
	 * candidates. The items deliberately carry no enum of current addresses:
	 * membership is enforced by validateOwnerGrant() at save time, and the
	 * run-time re-coercion must let a since-disabled address pass so it can be
	 * DROPPED at resolve time with a coverage note instead of failing the run.
	 */
	public static function descriptorListField(string $label, string $help): array {
		return [
			'type'    => 'array',
			'label'   => $label,
			'help'    => $help,
			'options' => self::aliasOptions(),
			'items'   => ['type' => 'string', 'max_length' => 320],
		];
	}

	/**
	 * The addresses a config's `mailbox_aliases` list names — normalized
	 * (lowercased, trimmed), de-duplicated, order preserved. Purely the stored
	 * list: no liveness check, no grant check (resolveBoundAliases() is that).
	 */
	public static function listedAddresses(array $config): array {
		$raw = $config['mailbox_aliases'] ?? [];
		if (!is_array($raw)) return [];
		$out = [];
		foreach ($raw as $address) {
			if (!is_string($address) && !is_numeric($address)) continue;
			$address = strtolower(trim((string)$address));
			if ($address !== '' && !in_array($address, $out, true)) {
				$out[] = $address;
			}
		}
		return $out;
	}

	/**
	 * What the recipe covers RIGHT NOW: [alias_id => address] for every listed
	 * address that still resolves to an enabled, store-capable alias on an
	 * enabled domain AND on which $owner_user_id still holds a grant.
	 *
	 * Live on purpose — a grant revoked or a mailbox disabled after the recipe
	 * was saved drops that address out here, at every read, rather than the
	 * recipe continuing on stale authority. The gap is reported by the jobs'
	 * coverageNotes(), never silently.
	 */
	public static function resolveBoundAliases(array $config, int $owner_user_id): array {
		$addresses = self::listedAddresses($config);
		if (empty($addresses) || $owner_user_id <= 0) return [];

		$granted = InboundEmailMailboxGrant::alias_ids_for_user($owner_user_id);
		if (empty($granted)) return [];

		$out = [];
		foreach ($addresses as $address) {
			$alias_id = self::resolveActiveAliasId($address);
			if ($alias_id !== null && in_array($alias_id, $granted, true)) {
				$out[$alias_id] = $address;
			}
		}
		return $out;
	}

	/** address -> alias id like resolveAliasId(), but only for an alias that
	 *  is enabled and store-capable on an enabled domain — the same liveness
	 *  bar aliasOptions() applies when offering the address in the first place. */
	public static function resolveActiveAliasId(string $address): ?int {
		$address = strtolower(trim($address));
		if ($address === '') return null;

		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT a.iea_inbound_email_alias_id
			   FROM iea_inbound_email_aliases a
			   JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id
			  WHERE a.iea_delete_time IS NULL
			    AND a.iea_is_enabled = true
			    AND a.iea_delivery_mode IN ('store', 'forward_and_store')
			    AND d.ied_is_enabled = true
			    AND lower(a.iea_alias || '@' || d.ied_domain) = ?
			  LIMIT 1");
		$q->execute([$address]);
		$id = $q->fetchColumn();
		return $id !== false ? (int)$id : null;
	}

	/**
	 * The security level of the domain behind $address ('standard', 'private',
	 * 'fortress'), or null when the address resolves to nothing.
	 */
	public static function securityLevelForAddress(string $address): ?string {
		$row = self::domainPostureForAddress($address);
		return $row === null ? null : (string)$row['ied_security_level'];
	}

	/**
	 * Is this address's mail sealed at rest? True for 'private' and 'fortress'.
	 *
	 * Callers use this to decide whether reading the mail needs the owner's
	 * unlock window — on a sealed domain a cron job can never read it at all
	 * (specs/in_window_deferred_work.md).
	 */
	public static function isSealedAtRest(string $address): bool {
		$level = self::securityLevelForAddress($address);
		return $level === 'private' || $level === 'fortress';
	}

	/**
	 * May AI features read this address's mail?
	 *
	 * On a standard domain the server already reads the mail in the clear, so
	 * there is no separate consent and this is always true. On a sealed domain
	 * it is the domain's explicit opt-in (ied_ai_processing_enabled), which is
	 * off until someone deliberately turns it on.
	 */
	public static function aiProcessingAllowed(string $address): bool {
		$row = self::domainPostureForAddress($address);
		if ($row === null) {
			return false;
		}
		$level = (string)$row['ied_security_level'];
		if ($level !== 'private' && $level !== 'fortress') {
			return true;
		}
		return (bool)$row['ied_ai_processing_enabled'];
	}

	/**
	 * May this address's decrypted mail be sent to a model running off the box?
	 *
	 * Only meaningful where there is something to protect: on a standard domain
	 * the mail is not sealed at rest, so no promise is broken by a cloud model
	 * reading it, and this returns true. On a sealed domain it is the domain's
	 * explicit second consent, default off.
	 *
	 * Deliberately separate from aiProcessingAllowed(): letting the AI read
	 * sealed mail on hardware you control and letting that plaintext leave the
	 * box are different decisions, and an operator may reasonably want the first
	 * without the second.
	 */
	public static function aiCloudAllowed(string $address): bool {
		$row = self::domainPostureForAddress($address);
		if ($row === null) {
			return false;
		}
		$level = (string)$row['ied_security_level'];
		if ($level !== 'private' && $level !== 'fortress') {
			return true;
		}
		return (bool)$row['ied_ai_cloud_enabled'];
	}

	/**
	 * @var array<string, ?array> Per-request memo. The vault heartbeat asks this
	 * for every one of a user's recipes on every beat, and most of them point at
	 * the same handful of mailboxes — without the memo that is one query per
	 * recipe, every 25 seconds, on every page.
	 */
	private static $posture_cache = array();

	/** The domain posture columns behind an address, or null. */
	private static function domainPostureForAddress(string $address): ?array {
		$address = strtolower(trim($address));
		if ($address === '') return null;
		if (array_key_exists($address, self::$posture_cache)) {
			return self::$posture_cache[$address];
		}

		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT d.ied_security_level, d.ied_ai_processing_enabled, d.ied_ai_cloud_enabled
			   FROM iea_inbound_email_aliases a
			   JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id
			  WHERE a.iea_delete_time IS NULL
			    AND lower(a.iea_alias || '@' || d.ied_domain) = ?
			  LIMIT 1");
		$q->execute([$address]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		self::$posture_cache[$address] = ($row === false ? null : $row);
		return self::$posture_cache[$address];
	}

	/** Drop the per-request posture memo — for tests and for code that changes a
	 *  domain's level or AI consent and then re-reads it in the same request. */
	public static function clearPostureCache(): void {
		self::$posture_cache = array();
	}

	/**
	 * Confirms $address resolves to a real, enabled, store-capable mailbox
	 * AND that $owner_user_id holds an explicit grant on it
	 * (ieg_inbound_email_mailbox_grants) — the same access check the Mailbox
	 * Reader itself enforces, so a recipe can never read mail its owner
	 * couldn't already see in their inbox. Returns the resolved alias id.
	 */
	public static function validateOwnerGrant(string $address, int $owner_user_id): int {
		$alias_id = self::resolveAliasId($address);
		if ($alias_id === null) {
			throw new InvalidArgumentException(
				"Mailbox to scan ($address) does not match a stored, enabled mailbox alias.");
		}

		$granted_ids = InboundEmailMailboxGrant::alias_ids_for_user($owner_user_id);
		if (!in_array($alias_id, $granted_ids, true)) {
			throw new InvalidArgumentException(
				"The recipe owner does not hold a mailbox grant for $address.");
		}

		return $alias_id;
	}

}
