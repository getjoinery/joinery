<?php
/**
 * MailboxAliasConfig - shared mailbox-alias config machinery for joinery_ai
 * pipeline jobs (EmailSecurityScanJob, EmailTriageJob) that bind a recipe to
 * one stored mailbox alias.
 *
 * Lives in the mailbox plugin (not joinery_ai) because it is mailbox-domain
 * knowledge, exactly like EmailSecurityDigest — the dependency points
 * mailbox <- joinery_ai, never the reverse, so this class takes plain values
 * (address strings, user ids) and never references Recipe or anything else
 * from joinery_ai.
 *
 * See specs/implemented/joinery_ai_email_triage.md § 1a.
 *
 * It also answers the two security-posture questions a job needs before it can
 * read a mailbox at all (specs/in_window_deferred_work.md): whether the mail is
 * sealed at rest, and whether the domain has consented to AI reading it.
 *
 * @version 1.1
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

	/** The `mailbox_alias` select field array a job's configDescriptor()
	 *  returns, with the caller's own label/help text. */
	public static function descriptorField(string $label, string $help): array {
		$options = self::aliasOptions();
		return [
			'type'     => 'select',
			'required' => true,
			'label'    => $label,
			'help'     => $help,
			'options'  => $options,
			'enum'     => array_keys($options),
		];
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
			"SELECT d.ied_security_level, d.ied_ai_processing_enabled
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
