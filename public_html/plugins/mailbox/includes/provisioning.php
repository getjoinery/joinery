<?php
/**
 * Mailbox - headless provisioning (specs/setup_wizard.md).
 *
 * Register a domain, create a store-mode mailbox on it, and grant it to a
 * user — callable from anywhere (the Setup tab, the setup wizard), with no
 * page or session plumbing. Callers are responsible for authorization;
 * every current caller sits behind check_permission(5) or higher.
 *
 * Both functions are IDEMPOTENT: a domain that already exists is reused, an
 * alias that already exists is reused with its grant ensured. Re-running a
 * partially failed provisioning call skips what is done and finishes the
 * rest — safe to call again, always.
 *
 * The vault-unlock gate on alias routing changes does not apply here: this
 * file only creates aliases, it never edits an existing alias's
 * destinations or delivery mode.
 *
 * @version 1.2 - a domain can be created as an IMAP source in its creating save,
 *                so a partially failed provisioning never leaves it hosted-shaped
 * @version 1.1 - the protected-mailbox grant rule is checked against the union
 *                actually being written, and reported as an error rather than thrown
 * @version 1.0 - extracted from admin_mailbox_setup_logic (add_domain) and
 *                admin_mailbox_alias_logic (alias save + grant sync)
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

/**
 * Ensure a receiving domain exists. Reuses an existing registration; on
 * creation, files fleet domain claims exactly as the Setup tab's add_domain
 * action always has.
 *
 * $imap_source shapes the CREATING save: a provider domain (gmail.com) is not
 * an identity this deployment holds, so it is born flagged as an IMAP source,
 * accepting unmatched mail, at Standard — in one save, so a later failure in
 * the same provisioning call can never leave it half-shaped as a hosted
 * domain. No fleet claim is filed for it either: an ownership challenge for
 * somebody else's domain could never be fulfilled. An EXISTING domain is
 * reused exactly as it is, whatever was asked.
 *
 * @return array{error: ?string, domain: ?InboundEmailDomain, created: bool}
 */
function mailbox_provision_domain(string $domain_name, bool $imap_source = false): array {
	$result = array('error' => null, 'domain' => null, 'created' => false);

	$domain_name = strtolower(trim($domain_name));
	if ($domain_name === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain_name)) {
		$result['error'] = 'Enter a valid domain name (like example.com).';
		return $result;
	}

	try {
		$domain = InboundEmailDomain::GetByDomain($domain_name);
		if (!$domain) {
			$domain = new InboundEmailDomain(NULL);
			$domain->set('ied_domain', $domain_name);
			$domain->set('ied_is_enabled', true);
			$domain->set('ied_reject_unmatched', !$imap_source);
			if ($imap_source) {
				$domain->set('ied_is_imap_source', true);
				$domain->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
			}
			$domain->prepare();
			$domain->save();
			$domain->load();
			$result['created'] = true;

			if (!$imap_source) {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
				(new FleetClient())->fileDomainClaims($domain_name);
			}
		}
		$result['domain'] = $domain;
	} catch (InboundEmailDomainException $e) {
		$result['error'] = $e->getMessage();
	}

	return $result;
}

/**
 * Ensure a store-mode mailbox exists on a domain and is granted to a user:
 * domain registration, alias creation, and access grant in one call.
 *
 * An existing alias is reused as-is (its delivery mode is never changed —
 * that is a routing edit and belongs to the alias page's ceremony); only
 * the grant is ensured. Grants are added by union, never overwritten, so
 * access other users already hold survives a re-run.
 *
 * @return array{error: ?string, domain: ?InboundEmailDomain,
 *               alias: ?InboundEmailAlias, domain_created: bool,
 *               alias_created: bool, grant_added: bool}
 */
function mailbox_provision_mailbox(string $domain_name, string $local_part, int $user_id,
		bool $imap_source = false): array {
	$result = array(
		'error' => null,
		'domain' => null,
		'alias' => null,
		'domain_created' => false,
		'alias_created' => false,
		'grant_added' => false,
	);

	$domain_step = mailbox_provision_domain($domain_name, $imap_source);
	if ($domain_step['error'] !== null) {
		$result['error'] = $domain_step['error'];
		return $result;
	}
	$domain = $domain_step['domain'];
	$result['domain'] = $domain;
	$result['domain_created'] = $domain_step['created'];

	// People often paste the whole address; keep the local part before the @.
	$local_part = strtolower(trim($local_part));
	$at = strpos($local_part, '@');
	if ($at !== false) {
		$local_part = substr($local_part, 0, $at);
	}
	if ($local_part === '') {
		$result['error'] = 'Enter the mailbox address (the part before the @).';
		return $result;
	}
	if ($user_id <= 0) {
		$result['error'] = 'A user to grant the mailbox to is required.';
		return $result;
	}

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

	try {
		$alias = null;
		$existing = new MultiInboundEmailAlias(array(
			'domain_id' => intval($domain->key),
			'alias' => $local_part,
			'deleted' => false,
		));
		$existing->load();
		foreach ($existing as $row) {
			$alias = $row;
			break;
		}

		if ($alias === null) {
			$alias = new InboundEmailAlias(NULL);
			$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
			$alias->set('iea_alias', $local_part);
			$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
			$alias->set('iea_is_enabled', true);
			$alias->prepare();
			$alias->save();
			$alias->load();
			$result['alias_created'] = true;
		}
		$result['alias'] = $alias;

		// Ensure the grant by union: sync_for_alias sets the list to exactly
		// what it is given, so include everyone who already has access.
		$granted = array();
		$grants = new MultiInboundEmailMailboxGrant(array('alias_id' => intval($alias->key)));
		$grants->load();
		foreach ($grants as $grant) {
			$granted[] = intval($grant->get('ieg_usr_user_id'));
		}
		if (!in_array($user_id, $granted, true)) {
			$granted[] = $user_id;
			// A protected mailbox seals to ONE holder with a vault, so the union
			// this call would otherwise write is checked against that rule first —
			// against the set actually being written, not the one user passed in
			// (specs/mailbox_protection_ceremony.md § 2b). sync_for_alias applies
			// the same rule at the write; this reports it as a result rather than
			// an exception, which is what a headless caller can act on.
			$protected_error = mailbox_protected_grant_error($domain, $granted, $alias);
			if ($protected_error !== null) {
				$result['error'] = $protected_error;
				return $result;
			}
			InboundEmailMailboxGrant::sync_for_alias(intval($alias->key), $granted);
			$result['grant_added'] = true;
		}
	} catch (InboundEmailAliasException $e) {
		$result['error'] = $e->getMessage();
	} catch (InboundEmailMailboxGrantException $e) {
		$result['error'] = $e->getMessage();
	}

	return $result;
}
