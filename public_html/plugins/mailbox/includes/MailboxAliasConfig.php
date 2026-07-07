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
 * @version 1.0
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
