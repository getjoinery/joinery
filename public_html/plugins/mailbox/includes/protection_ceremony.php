<?php
/**
 * protection_ceremony.php — the guided path to Private and Fortress
 * (specs/mailbox_protection_ceremony.md).
 *
 * Raising a domain's security level has prerequisites scattered across the
 * platform (the holder's vault, the single-reader rule, a relay for Fortress).
 * This include turns them into a checklist the domain editor renders: every
 * row is a verdict with an in-place fix, and the raise is refused server-side
 * until every required row passes — the button state is a convenience, the
 * save re-verification is the enforcement.
 *
 * Also owns backlog sealing: sealing is per-row, so a raise would leave every
 * already-received message plaintext forever. Sealing needs only the holder's
 * vault PUBLIC key, so any admin session can drive it; the editor auto-runs
 * bounded batches after a raise until the backlog is empty.
 *
 * Facts gathering (DB reads) and row evaluation (pure) are split so tests can
 * drive the evaluation matrix without fixtures.
 *
 * The card owns the whole arc (specs/mailbox_raise_receipt.md): the checklist
 * guides the raise in, and after the save the same surface renders the
 * receipt — sealing progress resolving in place into the completed facts.
 *
 * Lowering converges the other way (specs/mailbox_lowering_unseal.md):
 * mailbox_protection_unseal_batch() unseals history back to plaintext —
 * caller-scoped, since unsealing needs each holder's own unlock window —
 * and mailbox_lowering_receipt_render() is the downgrade's receipt card.
 *
 * @version 1.9
 * @changelog 1.9 - every pass takes an optional MAILBOX scope, so one pulled-in
 *   mailbox raises and lowers through this same ceremony, and the sealing
 *   predicate asks the mailbox rather than its domain
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

/**
 * Gather the facts the row evaluation needs for a domain. One shape:
 *   passkeys_enabled          — bool
 *   relay_fronted             — bool (a MailboxRelay row fronts this deployment)
 *   aliases                   — [{alias_id, address, holders: [{user_id, name,
 *                                has_vault, has_prf_passkey}]}] for live aliases
 *   acting_has_second_factor  — bool, only when $acting_user_id is given
 *
 * $acting_user_id is optional because most facts are about the domain, not the
 * person looking at it. Omit it and the second-factor row is skipped rather
 * than failed: an unknown actor must not manufacture a blocker.
 */
function mailbox_protection_facts(InboundEmailDomain $domain, int $acting_user_id = 0,
		int $alias_scope_id = 0): array {
	$settings = Globalvars::get_instance();

	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
	$fronted = false;
	try {
		$fronted = (MailboxRelay::active() !== null);
	} catch (\Throwable $e) {
		// No relay table/row — colocated.
	}

	$aliases_out = array();
	$holder_cache = array();
	// Scoped to ONE mailbox when the caller names it (raising a pulled-in mailbox
	// on its own, specs/mailbox_connect_flow.md § D) — the same checklist rows,
	// gathered for one address instead of every alias on the domain.
	$alias_filter = array('domain_id' => intval($domain->key), 'deleted' => false);
	$aliases = new MultiInboundEmailAlias($alias_filter);
	$aliases->load();
	foreach ($aliases as $alias) {
		if ($alias_scope_id > 0 && intval($alias->key) !== $alias_scope_id) {
			continue;
		}
		$holders = array();
		foreach (InboundEmailMailboxGrant::user_ids_for_alias(intval($alias->key)) as $uid) {
			$uid = intval($uid);
			if (!isset($holder_cache[$uid])) {
				$user = new User($uid, TRUE);
				$name = trim((string)$user->get('usr_first_name') . ' ' . (string)$user->get('usr_last_name'));
				if ($name === '') {
					$name = (string)$user->get('usr_email');
				}
				$prf = new MultiPasskey(array('user_id' => $uid, 'prf_capable' => true, 'deleted' => false));
				$prf->load();
				$holder_cache[$uid] = array(
					'user_id'         => $uid,
					'name'            => $name,
					'has_vault'       => (UserEncryptionVault::loadForUser($uid) !== null),
					'has_prf_passkey' => ($prf->count() > 0),
				);
			}
			$holders[] = $holder_cache[$uid];
		}
		$aliases_out[] = array(
			'alias_id' => intval($alias->key),
			'address'  => (string)$alias->get_full_address(),
			'holders'  => $holders,
		);
	}

	// The domain owner is who mail with NO mailbox seals to
	// (specs/mailbox_unmatched_sealing.md). The catch-all accepts mail for
	// addresses nobody created — postmaster@, typos, guesses — and that mail has
	// no mailbox and therefore no holder. Without an owner it cannot be sealed at
	// all, so at a sealing level the owner is a prerequisite, not a nicety.
	$owner_id = intval($domain->get('ied_owner_usr_user_id'));
	$owner_name = '';
	$owner_has_vault = false;
	if ($owner_id > 0) {
		$owner_user = new User($owner_id, TRUE);
		if ($owner_user->key) {
			$owner_name = trim((string)$owner_user->get('usr_first_name') . ' '
				. (string)$owner_user->get('usr_last_name'));
			if ($owner_name === '') {
				$owner_name = (string)$owner_user->get('usr_email');
			}
			$owner_has_vault = (UserEncryptionVault::loadForUser($owner_id) !== null);
		} else {
			$owner_id = 0;   // a deleted user is no owner at all
		}
	}

	$facts = array(
		'passkeys_enabled' => (bool)$settings->get_setting('passkeys_enabled'),
		'relay_fronted'    => $fronted,
		'aliases'          => $aliases_out,
		'scope_alias_id'   => $alias_scope_id,
	);

	// The domain owner is a fact about MAIL WITH NO MAILBOX, so it belongs to a
	// domain-wide raise only. Raising one pulled-in mailbox changes nothing about
	// where the domain's catch-all mail seals, and demanding a domain owner for it
	// would be a blocker invented by the scoping.
	if ($alias_scope_id <= 0) {
		$facts['domain_owner_id']        = $owner_id;
		$facts['domain_owner_name']      = $owner_name;
		$facts['domain_owner_has_vault'] = $owner_has_vault;
	}

	// Whether the person doing the raise already satisfies the Fortress posture
	// gate (SessionControl::must_enroll_2fa_for_fortress). Owning a Fortress
	// domain locks that account out of every page but /profile/security until a
	// second factor exists, and sealing the signing key makes them the owner —
	// so this has to be a prerequisite of the raise, not a surprise after it.
	if ($acting_user_id > 0) {
		$acting = new User($acting_user_id, TRUE);
		$facts['acting_has_second_factor'] = $acting->key
			? SessionControl::get_instance()->user_has_independent_second_factor($acting)
			: true;
	}

	return $facts;
}

/**
 * Evaluate the ceremony rows for a target level. Pure — facts in, rows out.
 * Each row: {id, severity: required|recommended|info, status: pass|fail|warn|info,
 * label, summary, actions: [{type, ...}]}. Action types:
 *   remove_grant {alias_id, user_id, name}  — inline one-reader fix
 *   add_reader   {alias_id}                 — holderless mailbox fix
 *   vault_self   {}                         — session user sets up their vault
 *   passkey_self {}                         — session user enrolls a passkey
 *   second_factor_self {}                   — session user enrolls a 2nd factor
 *   set_domain_owner {}                     — choose who owns the domain
 */
function mailbox_protection_rows(array $facts, string $target, int $acting_user_id): array {
	$rows = array();
	$fortress = ($target === InboundEmailDomain::LEVEL_FORTRESS);

	// Fortress locks its owner out of the admin UI until they hold a second
	// factor independent of any one passkey, and the raise makes the person
	// doing it the owner. Refuse here, where it is still a choice, instead of
	// letting the save through and bouncing them to /profile/security with no
	// idea what they did. Absent from $facts means the caller never named an
	// acting user — skip rather than block.
	if ($fortress && array_key_exists('acting_has_second_factor', $facts)
			&& !$facts['acting_has_second_factor']) {
		$rows[] = array('id' => 'second_factor_self', 'severity' => 'required', 'status' => 'fail',
			'label' => 'Your second factor',
			'summary' => 'Fortress makes this domain yours to sign for, and an account that can sign for a Fortress '
				. 'domain needs a second way to prove it is you — an authenticator app, or a second passkey. '
				. 'Until you add one you will be held on the security page, so set it up before raising the level.',
			'actions' => array(array('type' => 'second_factor_self')));
	}

	// Platform kill switch: vault setup itself runs through a PRF passkey, so
	// with passkeys off nothing below can be fixed. One loud required row.
	if (!$facts['passkeys_enabled']) {
		$rows[] = array('id' => 'passkeys_platform', 'severity' => 'required', 'status' => 'fail',
			'label' => 'Passkeys available',
			'summary' => 'Passkeys are disabled on this deployment, and vault setup needs them. '
				. 'Turn on the passkeys setting in admin Settings, then return here.',
			'actions' => array());
	}

	// One reader per mailbox — the sealing model is one owner, one key.
	$shared = array();
	$holderless = array();
	foreach ($facts['aliases'] as $a) {
		if (count($a['holders']) > 1) {
			$shared[] = $a;
		} elseif (count($a['holders']) === 0) {
			$holderless[] = $a;
		}
	}
	if (count($shared)) {
		foreach ($shared as $a) {
			$actions = array();
			foreach ($a['holders'] as $h) {
				$actions[] = array('type' => 'remove_grant', 'alias_id' => $a['alias_id'],
					'user_id' => $h['user_id'], 'name' => $h['name']);
			}
			$rows[] = array('id' => 'single_reader:' . $a['alias_id'], 'severity' => 'required', 'status' => 'fail',
				'label' => 'One reader — ' . $a['address'],
				'summary' => 'Protected mail is sealed to one person\'s key, so a protected mailbox has exactly '
					. 'one reader. ' . $a['address'] . ' currently has ' . count($a['holders'])
					. ' — remove access for everyone but its owner.',
				'actions' => $actions);
		}
	}
	if (count($holderless)) {
		foreach ($holderless as $a) {
			$rows[] = array('id' => 'has_reader:' . $a['alias_id'], 'severity' => 'required', 'status' => 'fail',
				'label' => 'Mailbox has a reader — ' . $a['address'],
				'summary' => $a['address'] . ' has no member, so its mail would have no one to seal to. '
					. 'Add its owner on the mailbox.',
				'actions' => array(array('type' => 'add_reader', 'alias_id' => $a['alias_id'])));
		}
	}
	if (!count($shared) && !count($holderless) && count($facts['aliases'])) {
		$rows[] = array('id' => 'single_reader', 'severity' => 'required', 'status' => 'pass',
			'label' => 'One reader per mailbox',
			'summary' => 'Each mailbox on this domain has exactly one reader.', 'actions' => array());
	}

	// Every reader holds a vault — the key their mail seals to. Evaluated per
	// holder (the sealing target), never for the admin running the ceremony.
	$holders = array();
	foreach ($facts['aliases'] as $a) {
		foreach ($a['holders'] as $h) {
			$holders[$h['user_id']] = $h;
		}
	}
	$no_vault = array_filter($holders, function ($h) { return !$h['has_vault']; });
	if (count($no_vault)) {
		foreach ($no_vault as $h) {
			if ($h['user_id'] === $acting_user_id) {
				$rows[] = array('id' => 'holder_vault:' . $h['user_id'], 'severity' => 'required', 'status' => 'fail',
					'label' => 'Your vault',
					'summary' => 'Your mail will be sealed to your personal vault, and you don\'t have one yet. '
						. 'Set it up now — you\'ll come straight back here.',
					'actions' => array(array('type' => 'vault_self')));
			} else {
				$rows[] = array('id' => 'holder_vault:' . $h['user_id'], 'severity' => 'required', 'status' => 'fail',
					'label' => 'Vault — ' . $h['name'],
					'summary' => $h['name'] . ' reads a mailbox on this domain and has no vault yet. Only they '
						. 'can create it (it\'s their key, not yours): ask them to set it up from their '
						. 'Security page, then return here.',
					'actions' => array());
			}
		}
	} elseif (count($holders)) {
		$rows[] = array('id' => 'holder_vault', 'severity' => 'required', 'status' => 'pass',
			'label' => 'Reader vaults',
			'summary' => 'Every reader\'s vault is ready to seal to.', 'actions' => array());
	}

	// The domain owner — whose key mail with no mailbox seals to. Absent from
	// $facts means an older caller that never resolved it; skip rather than block.
	if (array_key_exists('domain_owner_id', $facts)) {
		$owner_id = intval($facts['domain_owner_id']);
		$owner_name = (string)$facts['domain_owner_name'];
		if ($owner_id <= 0) {
			$rows[] = array('id' => 'domain_owner', 'severity' => 'required', 'status' => 'fail',
				'label' => 'Domain owner',
				'summary' => 'Not all mail arrives in a mailbox. Anything sent to an address you have not '
					. 'created — postmaster@, a typo, an address a spammer guessed — is accepted for the '
					. 'domain itself, and it seals to the domain\'s owner. This domain has no owner, so that '
					. 'mail would be stored unencrypted. Choose who owns this domain first.',
				'actions' => array(array('type' => 'set_domain_owner')));
		} elseif (!$facts['domain_owner_has_vault']) {
			if ($owner_id === $acting_user_id) {
				$rows[] = array('id' => 'domain_owner_vault', 'severity' => 'required', 'status' => 'fail',
					'label' => 'Your vault (domain owner)',
					'summary' => 'You own this domain, so mail that arrives for an address without a mailbox '
						. 'seals to your vault — and you don\'t have one yet. Set it up now; you\'ll come '
						. 'straight back here.',
					'actions' => array(array('type' => 'vault_self')));
			} else {
				$rows[] = array('id' => 'domain_owner_vault', 'severity' => 'required', 'status' => 'fail',
					'label' => 'Vault — ' . $owner_name . ' (domain owner)',
					'summary' => $owner_name . ' owns this domain, so mail arriving for an address with no '
						. 'mailbox seals to their vault. They have none yet, and only they can create it. '
						. 'Ask them to set it up from their Security page, then return here.',
					'actions' => array());
			}
		} else {
			$rows[] = array('id' => 'domain_owner', 'severity' => 'required', 'status' => 'pass',
				'label' => 'Domain owner',
				'summary' => 'Mail arriving for an address with no mailbox seals to ' . $owner_name . '.',
				'actions' => array());
		}
	}

	// Unlock by touch — recommended. Vault setup enrolls a PRF passkey, so this
	// is normally green the moment the vault row is; it stays a separate row so
	// a holder whose passkey was removed sees exactly what to restore.
	if ($facts['passkeys_enabled'] && count($holders)) {
		$no_prf = array_filter($holders, function ($h) { return !$h['has_prf_passkey']; });
		if (count($no_prf)) {
			foreach ($no_prf as $h) {
				$self = ($h['user_id'] === $acting_user_id);
				$rows[] = array('id' => 'holder_passkey:' . $h['user_id'], 'severity' => 'recommended', 'status' => 'warn',
					'label' => 'Unlock by touch' . ($self ? '' : ' — ' . $h['name']),
					'summary' => ($self ? 'You have' : $h['name'] . ' has') . ' no passkey that can unlock the '
						. 'vault by touch — reading protected mail will always need a bypass phrase or a '
						. 'recovery code.',
					'actions' => $self ? array(array('type' => 'passkey_self')) : array());
			}
		} else {
			$rows[] = array('id' => 'holder_passkey', 'severity' => 'recommended', 'status' => 'pass',
				'label' => 'Unlock by touch',
				'summary' => 'Every reader can unlock with a passkey.', 'actions' => array());
		}
	}

	if ($fortress) {
		$rows[] = $facts['relay_fronted']
			? array('id' => 'relay_fronted', 'severity' => 'required', 'status' => 'pass',
				'label' => 'Relay in front',
				'summary' => 'Mail arrives through your relay, so it can be sealed before it reaches this server.',
				'actions' => array())
			: array('id' => 'relay_fronted', 'severity' => 'required', 'status' => 'fail',
				'label' => 'Relay in front',
				'summary' => 'Fortress seals mail before it ever reaches this server, which needs a relay in '
					. 'front. Set one up in the Setup tab\'s Relay section first.',
				'actions' => array());
		$rows[] = array('id' => 'fortress_dns', 'severity' => 'info', 'status' => 'info',
			'label' => 'What happens next',
			'summary' => 'After the level saves, you\'ll publish the protected DNS shape and activate outbound '
				. 'protection — the next screen walks through it. Mail keeps flowing throughout.',
			'actions' => array());
	}

	return $rows;
}

/**
 * The refusal message for a grant-list change on a protected mailbox, or null
 * when the change is fine (specs/mailbox_protection_ceremony.md § 2b). Editors
 * call this BEFORE saving so the operator sees the reason on the form they are
 * looking at; InboundEmailMailboxGrant::sync_for_alias() applies the identical
 * rule at the write itself, so the raised state cannot be corrupted by a path
 * that forgets to ask.
 *
 * $alias, when given, is the mailbox being edited — its own level wins over the
 * domain's (specs/mailbox_connect_flow.md § D). Without one the domain's level
 * is the answer, which is right for a mailbox that does not exist yet.
 */
function mailbox_protected_grant_error(InboundEmailDomain $domain, array $user_ids,
		?InboundEmailAlias $alias = null): ?string {
	$seals = ($alias !== null && $alias->key) ? $alias->seals_content()
		: ($domain->key && $domain->seals_content());
	return InboundEmailMailboxGrant::grant_set_error($seals, $user_ids);
}

/** Whether every required row passes (the raise gate). */
function mailbox_protection_required_ok(array $rows): bool {
	foreach ($rows as $row) {
		if ($row['severity'] === 'required' && $row['status'] !== 'pass') {
			return false;
		}
	}
	return true;
}

/** The first failing required row's summary, for the server-side refusal message. */
function mailbox_protection_first_failure(array $rows): string {
	foreach ($rows as $row) {
		if ($row['severity'] === 'required' && $row['status'] !== 'pass') {
			return $row['summary'];
		}
	}
	return '';
}

/**
 * Unsealed, live rows on a domain — the backlog a raise must converge.
 *
 * Only rows whose MAILBOX seals are counted (specs/mailbox_connect_flow.md
 * § D): a Standard mailbox's plaintext is correct, not backlog, and counting it
 * would leave the receipt's progress row stuck at a number nothing can move.
 *
 * A pending-parse row is excluded: the relay already sealed it to the owner's
 * vault public key, and it holds no plaintext for this pass to seal. Counting
 * it would report a sealed message as backlog and hand the seal batch a row
 * whose only content is a blob it cannot open.
 */
function mailbox_protection_backlog_count(int $domain_id, int $alias_scope_id = 0): int {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT COUNT(*) FROM iem_inbound_email_messages m
		 " . mailbox_protection_posture_join() . "
		 WHERE m.iem_ied_inbound_email_domain_id = ?
		   AND m.iem_content_sealed = false AND m.iem_pending_parse = false
		   AND m.iem_delete_time IS NULL
		   AND " . mailbox_protection_seals_sql()
		. mailbox_protection_alias_scope_sql($alias_scope_id, 'm'));
	$stmt->execute(array($domain_id));
	return intval($stmt->fetchColumn());
}

/**
 * The joins that put a message row's effective protection posture in reach:
 * its mailbox (LEFT — the catch-all has none) and its domain.
 */
function mailbox_protection_posture_join(): string {
	return 'LEFT JOIN iea_inbound_email_aliases a
			ON a.iea_inbound_email_alias_id = m.iem_iea_inbound_email_alias_id
		 JOIN ied_inbound_email_domains d
			ON d.ied_inbound_email_domain_id = m.iem_ied_inbound_email_domain_id';
}

/** True-when-sealing predicate for a query carrying the posture join above. */
function mailbox_protection_seals_sql(): string {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	return InboundEmailAlias::effectiveLevelSql('a', 'd') . " IN ('"
		. InboundEmailDomain::LEVEL_PRIVATE . "','" . InboundEmailDomain::LEVEL_FORTRESS . "')";
}

/** Sealed, live rows on a domain — the receipt's "N earlier messages sealed"
 *  fact. Counted from real state so the number stays truthful across resumed
 *  or no-JS sealing runs. */
function mailbox_protection_sealed_count(int $domain_id, int $alias_scope_id = 0): int {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT COUNT(*) FROM iem_inbound_email_messages
		 WHERE iem_ied_inbound_email_domain_id = ?
		   AND iem_content_sealed = true AND iem_delete_time IS NULL"
		. mailbox_protection_alias_scope_sql($alias_scope_id));
	$stmt->execute(array($domain_id));
	return intval($stmt->fetchColumn());
}

/**
 * The extra WHERE clause narrowing a domain-wide message query to one mailbox,
 * or '' for the whole domain. Written once so the count, the seal pass and the
 * receipt can never disagree about which rows a scoped raise covers. The id is
 * cast to int, so it is never a value from outside.
 */
function mailbox_protection_alias_scope_sql(int $alias_scope_id, string $tbl = ''): string {
	$col = ($tbl !== '' ? $tbl . '.' : '') . 'iem_iea_inbound_email_alias_id';
	return $alias_scope_id > 0 ? ' AND ' . $col . ' = ' . intval($alias_scope_id) : '';
}

/**
 * Seal one bounded batch of a domain's unsealed rows to each mailbox's
 * holder vault — the same per-row work as the reader-driven backfill_seal
 * action, but driveable from any admin session: sealing uses only the
 * holder's vault PUBLIC key. A row belonging to no mailbox seals to the domain
 * owner instead (specs/mailbox_unmatched_sealing.md). Rows with nobody to seal
 * to are skipped
 * (counted in remaining; the Setup tab's backlog row keeps them loud), and
 * pending-parse rows are never selected — they carry no plaintext, so sealing
 * one would store empty content under a fresh key and mark it done.
 * Returns ['sealed' => n, 'remaining' => n].
 */
function mailbox_protection_seal_batch(InboundEmailDomain $domain, int $limit = 200,
		int $alias_scope_id = 0): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT m.iem_inbound_email_message_id, m.iem_iea_inbound_email_alias_id
		 FROM iem_inbound_email_messages m
		 " . mailbox_protection_posture_join() . "
		 WHERE m.iem_ied_inbound_email_domain_id = ?
		   AND m.iem_content_sealed = false AND m.iem_pending_parse = false
		   AND m.iem_delete_time IS NULL
		   AND " . mailbox_protection_seals_sql()
		. mailbox_protection_alias_scope_sql($alias_scope_id, 'm') . "
		 ORDER BY m.iem_inbound_email_message_id ASC LIMIT " . intval($limit));
	$stmt->execute(array(intval($domain->key)));
	$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$router = new InboundEmailRouter();
	$vault_cache = array();
	$sealed = 0;
	foreach ($targets as $t) {
		$alias_id = intval($t['iem_iea_inbound_email_alias_id']);
		if (!isset($vault_cache[$alias_id])) {
			// alias 0 means the row belongs to no mailbox — the catch-all stored
			// it for an address nobody created. Those seal to the DOMAIN owner
			// (specs/mailbox_unmatched_sealing.md); sealOwnerUserId() decides,
			// so delivery and this backlog pass can never disagree about whose
			// key a message belongs under.
			$owner_id = InboundEmailMessage::sealOwnerUserId($alias_id ?: null, intval($domain->key));
			$vault_cache[$alias_id] = ($owner_id !== null) ? UserEncryptionVault::loadForUser($owner_id) : null;
		}
		$vault = $vault_cache[$alias_id];
		if ($vault === null) {
			continue; // nobody with a vault to seal to — stays in the backlog count
		}
		$msg = new InboundEmailMessage(intval($t['iem_inbound_email_message_id']), TRUE);
		if (!$msg->key) {
			continue;
		}
		try {
			// Same path as backfill_seal_logic: seal every content column this
			// row holds, chosen by the predicate the read path uses.
			$dek = InboundEmailMessage::sealExistingRow($msg, $vault);
			$raw = $msg->getRawMessage();
			if ($raw !== null && $raw !== '') {
				$router->resealBackfillAttachments(intval($msg->key), $raw, $dek);
				$router->destroyRawAfterBackfill(intval($msg->key));
			}
			$sealed++;
		} catch (\Throwable $e) {
			error_log('protection_ceremony seal_batch: failed for message '
				. $t['iem_inbound_email_message_id'] . ': ' . $e->getMessage());
		}
	}

	return array('sealed' => $sealed,
		'remaining' => mailbox_protection_backlog_count(intval($domain->key), $alias_scope_id));
}

/**
 * Unseal one bounded batch of sealed rows back to plaintext on domains that
 * no longer seal (specs/mailbox_lowering_unseal.md). Caller-scoped by
 * construction: unsealing needs the per-message DEK, which unwraps only
 * inside the sealed OWNER's unlock window — so this session converges only
 * rows sealed to $caller_user_id, and only while their window is open.
 * $domain null means every non-sealing scope (the reader-driven path).
 * Anything that still seals is never touched, at any layer — and the question
 * is asked of the MAILBOX (specs/mailbox_connect_flow.md § D), so a still-Private
 * pulled-in mailbox on a lowered domain keeps its mail sealed while its
 * neighbours converge. $alias_scope_id narrows a domain pass to one mailbox,
 * which is what a single-mailbox lowering converges.
 *
 * Each pass first drains the caller's pending-parse rows (a lowered Fortress
 * domain's relay blobs — DeferredIngest parses and re-seals them, and a later
 * pass unseals the result), then unseals up to $limit rows. Batches are small
 * ($limit 25, not sealing's 200) because unsealing rewrites attachment bytes.
 *
 * Returns:
 *   unsealed         — rows converged this pass
 *   own_remaining    — caller-owned rows still sealed or pending
 *   others_remaining — rows sealed to other holders (this session can never
 *                      touch them; they converge from those holders' sessions)
 */
function mailbox_protection_unseal_batch(?InboundEmailDomain $domain, int $caller_user_id,
		int $limit = 25, int $alias_scope_id = 0): array {
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DeferredIngest.php'));

	$db = DbConnector::get_instance()->get_db_link();

	// The scope: rows whose effective posture no longer seals — inside one
	// domain, or anywhere. A scope that still seals yields no rows, so refusal is
	// by construction rather than by a guard somebody has to remember.
	$scope_sql = 'NOT (' . mailbox_protection_seals_sql() . ')';
	if ($domain !== null) {
		if (!$domain->key) {
			return array('unsealed' => 0, 'own_remaining' => 0, 'others_remaining' => 0);
		}
		$scope_sql .= ' AND m.iem_ied_inbound_email_domain_id = ' . intval($domain->key);
	}
	$scope_sql .= mailbox_protection_alias_scope_sql($alias_scope_id, 'm');
	$join_sql = mailbox_protection_posture_join();

	$counts = function () use ($db, $scope_sql, $join_sql, $caller_user_id) {
		$stmt = $db->prepare(
			"SELECT
				COUNT(*) FILTER (WHERE m.iem_sealed_owner_user_id = ?) AS own,
				COUNT(*) FILTER (WHERE m.iem_sealed_owner_user_id IS DISTINCT FROM ?) AS others
			 FROM iem_inbound_email_messages m
			 $join_sql
			 WHERE $scope_sql
			   AND (m.iem_content_sealed = true OR m.iem_pending_parse = true)
			   AND m.iem_delete_time IS NULL");
		$stmt->execute(array($caller_user_id, $caller_user_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return array('own' => intval($row['own'] ?? 0), 'others' => intval($row['others'] ?? 0));
	};

	$secret = VaultUnlock::secretKey($caller_user_id);
	if ($secret === null) {
		$c = $counts();
		return array('unsealed' => 0, 'own_remaining' => $c['own'], 'others_remaining' => $c['others'], 'locked' => true);
	}

	// Pending-parse rows first — DeferredIngest is caller-scoped already.
	DeferredIngest::drainForUser($caller_user_id, $secret);

	$stmt = $db->prepare(
		"SELECT m.iem_inbound_email_message_id FROM iem_inbound_email_messages m
		 $join_sql
		 WHERE $scope_sql
		   AND m.iem_content_sealed = true AND m.iem_pending_parse = false
		   AND m.iem_sealed_owner_user_id = ?
		   AND m.iem_delete_time IS NULL
		 ORDER BY m.iem_inbound_email_message_id ASC LIMIT " . intval($limit));
	$stmt->execute(array($caller_user_id));
	$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

	$unsealed = 0;
	foreach ($ids as $id) {
		$msg = new InboundEmailMessage(intval($id), TRUE);
		if (!$msg->key) {
			continue;
		}
		if (InboundEmailMessage::unsealAndPersistContent($msg)) {
			$unsealed++;
		}
	}

	$c = $counts();
	return array('unsealed' => $unsealed, 'own_remaining' => $c['own'], 'others_remaining' => $c['others']);
}

/**
 * Render the ceremony checklist for the domain editor. $urls carries the
 * editor's own URL (for the remove-grant forms and vault/passkey returns):
 *   editor_url — this domain's editor page (absolute path)
 *   alias_url  — the alias editor base
 * $target names the destination level so the heading reads as the sentence the
 * receipt later resolves ("Before this domain can be Private"). $scope_label
 * replaces "this domain" when the checklist is for ONE mailbox — saying the
 * domain there would promise something the raise does not do.
 */
function mailbox_protection_render(array $rows, InboundEmailDomain $domain, array $urls,
		string $target = '', string $scope_label = ''): string {
	$dot = function ($status) {
		$color = array('pass' => '#28a745', 'fail' => '#dc3545', 'warn' => '#ffc107', 'info' => '#6c757d');
		return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'
			. ($color[$status] ?? '#6c757d') . ';margin-right:8px;flex:none;"></span>';
	};
	$security_url = '/profile/security?return=' . urlencode($urls['editor_url']);

	$all_green = true;
	foreach ($rows as $r) {
		if ($r['status'] === 'fail' || $r['status'] === 'warn') {
			$all_green = false;
			break;
		}
	}

	$html = '<div id="protection-ceremony" class="d-none" data-required-ok="';
	$html .= mailbox_protection_required_ok($rows) ? '1' : '0';
	$html .= '" data-all-green="' . ($all_green ? '1' : '0') . '">';
	$destination = $target !== '' ? ucfirst($target) : 'protected';
	$subject = ($scope_label !== '') ? $scope_label : 'this domain';
	$html .= '<h4 style="margin-top:1rem;">Before ' . htmlspecialchars($subject) . ' can be '
		. htmlspecialchars($destination) . '</h4>';
	$html .= '<ul style="list-style:none;padding:0;margin:0;">';
	foreach ($rows as $row) {
		$html .= '<li style="display:flex;align-items:baseline;margin:.5rem 0;" data-row-id="'
			. htmlspecialchars($row['id']) . '" data-severity="' . htmlspecialchars($row['severity'])
			. '" data-status="' . htmlspecialchars($row['status']) . '">';
		$html .= $dot($row['status']);
		$html .= '<div><strong>' . htmlspecialchars($row['label']) . '</strong> — '
			. htmlspecialchars($row['summary']);
		foreach ($row['actions'] as $action) {
			if ($action['type'] === 'remove_grant') {
				$html .= '<form method="post" action="' . htmlspecialchars($urls['editor_url'])
					. '" style="display:inline;margin-left:.5rem;">'
					. '<input type="hidden" name="action" value="ceremony_remove_grant">'
					. '<input type="hidden" name="ied_inbound_email_domain_id" value="' . intval($domain->key) . '">'
					. '<input type="hidden" name="alias_id" value="' . intval($action['alias_id']) . '">'
					. '<input type="hidden" name="user_id" value="' . intval($action['user_id']) . '">'
					. '<button type="submit" class="btn btn-sm btn-outline-danger" '
					. 'onclick="return confirm(\'Remove ' . htmlspecialchars(addslashes($action['name']))
					. '\\\'s access to this mailbox?\');">Remove ' . htmlspecialchars($action['name']) . '</button>'
					. '</form>';
			} elseif ($action['type'] === 'add_reader') {
				$html .= ' <a class="btn btn-sm btn-outline-primary" style="margin-left:.5rem;" href="'
					. htmlspecialchars($urls['alias_url'] . '?iea_inbound_email_alias_id=' . intval($action['alias_id']))
					. '">Add its owner</a>';
			} elseif ($action['type'] === 'set_domain_owner') {
				// No owner picker exists, and the Fortress outbound ceremony
				// already establishes "the person doing this becomes the owner".
				// Make that explicit and deliberate rather than a side effect.
				$html .= '<form method="post" style="display:inline;margin-left:.5rem;">'
					. '<input type="hidden" name="action" value="ceremony_set_domain_owner">'
					. '<input type="hidden" name="ied_inbound_email_domain_id" value="' . intval($domain->key) . '">'
					. '<button type="submit" class="btn btn-sm btn-outline-primary">Make me the owner</button>'
					. '</form>';
			} elseif ($action['type'] === 'vault_self') {
				$html .= ' <a class="btn btn-sm btn-primary" style="margin-left:.5rem;" href="'
					. htmlspecialchars($security_url) . '#vault-panel">Set up your vault</a>';
			} elseif ($action['type'] === 'passkey_self') {
				$html .= ' <a class="btn btn-sm btn-outline-primary" style="margin-left:.5rem;" href="'
					. htmlspecialchars($security_url) . '#passkeys-panel">Add a passkey</a>';
			} elseif ($action['type'] === 'second_factor_self') {
				// Either an authenticator app or a second passkey satisfies this,
				// and both live on the security page — land on the page, not a panel.
				$html .= ' <a class="btn btn-sm btn-primary" style="margin-left:.5rem;" href="'
					. htmlspecialchars($security_url) . '">Add a second factor</a>';
			}
		}
		$html .= '</div></li>';
	}
	$html .= '</ul></div>';
	return $html;
}

/**
 * Render the raise receipt card (specs/mailbox_raise_receipt.md) — the single
 * component that carries a raise from "sealing earlier messages" to the
 * completed facts. Rendered whenever a sealing domain arrives from a raise
 * (the sealed_now marker) or still has a backlog to converge (a resumed run).
 *
 * $state:
 *   backlog        — unsealed live rows right now (0 = receipt immediately)
 *   sealed_total   — sealed live rows right now (the completed-fact count)
 *   acting_user_id — for "your unlock" vs naming another holder
 *   editor_url     — this editor page (the no-JS continue form)
 *   alias_scope_id — the ONE mailbox this raise covers, or 0 for the domain
 *   scope_level    — that mailbox's level, when scoped (the title states it)
 *
 * With a backlog the card renders the receipt layout with the sealing row
 * live; the editor's JS drives mailbox/seal_batch and resolves the row in
 * place (no-JS falls back to the ceremony_seal_batch POST loop). Fortress
 * before outbound protection is a HANDOFF, not a terminus: the title stays
 * honest and the button continues into the protect ceremony.
 *
 * The SAME card serves a single mailbox raised on its own
 * (specs/mailbox_connect_flow.md § D): the scope narrows what is counted and
 * sealed, not what the ceremony is. There is no second receipt.
 */
function mailbox_protection_receipt_render(InboundEmailDomain $domain, array $facts, array $state): string {
	$backlog = intval($state['backlog'] ?? 0);
	$sealed_total = intval($state['sealed_total'] ?? 0);
	$acting_user_id = intval($state['acting_user_id'] ?? 0);
	$alias_scope_id = intval($state['alias_scope_id'] ?? 0);
	$level = ($alias_scope_id > 0 && !empty($state['scope_level']))
		? (string)$state['scope_level'] : $domain->security_level();
	// Fortress is domain-only, so a mailbox-scoped raise never hands off.
	$handoff = ($alias_scope_id <= 0 && $level === InboundEmailDomain::LEVEL_FORTRESS
		&& !$domain->is_protected_identity());

	// $live marks the one dot the shared batch loop recolors as it works
	// (data-ceremony-dot); the rest are static facts.
	$dot = function ($status, $live = false) {
		$color = array('pass' => '#28a745', 'fail' => '#dc3545', 'warn' => '#ffc107', 'info' => '#6c757d');
		return '<span class="receipt-dot"' . ($live ? ' data-ceremony-dot' : '')
			. ' style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'
			. ($color[$status] ?? '#6c757d') . ';margin-right:8px;flex:none;"></span>';
	};

	// Titles: the event, stated once. A Fortress raise before outbound
	// protection never claims Fortress — one step still remains.
	if ($handoff) {
		$title = ($backlog > 0) ? 'Sealing earlier messages — one step left after this'
			: 'Earlier messages sealed — one step left';
		$title_done = 'Earlier messages sealed — one step left';
	} elseif ($alias_scope_id > 0) {
		// One mailbox raised on its own names the mailbox, because the domain
		// around it has not changed and saying it had would be untrue.
		$scope_name = '';
		foreach (($facts['aliases'] ?? array()) as $a) {
			if (intval($a['alias_id']) === $alias_scope_id) { $scope_name = (string)$a['address']; }
		}
		$title = ($scope_name !== '' ? $scope_name : 'This mailbox') . ' is now ' . ucfirst($level);
		$title_done = $title;
	} else {
		$title = 'This domain is now ' . ucfirst($level);
		$title_done = $title;
	}

	// The unlock fact names the reader(s) — "your unlock" when the acting
	// admin is the sole holder, their names otherwise.
	$holders = array();
	foreach (($facts['aliases'] ?? array()) as $a) {
		foreach ($a['holders'] as $h) {
			$holders[$h['user_id']] = $h['name'];
		}
	}
	if (count($holders) === 1 && isset($holders[$acting_user_id])) {
		$unlock_fact = 'Reading takes your unlock';
	} elseif (count($holders)) {
		$unlock_fact = 'Reading takes an unlock by ' . implode(', ', $holders);
	} else {
		$unlock_fact = 'Reading takes the reader\'s unlock';
	}

	if ($backlog > 0) {
		$seal_dot = 'warn';
		$seal_fact = 'Sealing earlier messages — ' . $backlog . ' remaining&hellip;';
	} elseif ($sealed_total > 0) {
		$seal_dot = 'pass';
		$seal_fact = $sealed_total . ' earlier message' . ($sealed_total === 1 ? '' : 's') . ' sealed';
	} else {
		$seal_dot = 'pass';
		$seal_fact = 'No earlier messages needed sealing';
	}

	if ($handoff) {
		$button_label = 'Continue: activate outbound protection';
		$button_url = '/plugins/mailbox/admin/admin_mailbox_setup?domain_id=' . intval($domain->key);
	} else {
		$button_label = 'Open mailbox';
		$button_url = '/plugins/mailbox/admin/admin_mailbox_reader';
	}

	// The progress row is driven by the shared batch loop
	// (assets/js/ceremony-batch.js): it calls mailbox/seal_batch until the
	// backlog is empty, resolves this row in place, and stops with a plain
	// statement if a pass seals nothing while rows remain.
	$ceremony_config = json_encode(array(
		'action'    => 'mailbox/seal_batch',
		'payload'   => ($alias_scope_id > 0)
			? array('alias_id' => $alias_scope_id)
			: array('domain_id' => intval($domain->key)),
		'remaining' => $backlog,
		'doneTotal' => $sealed_total,
		'doneKey'   => 'sealed',
		'labels'    => array(
			'working' => 'Sealing earlier messages — {remaining} remaining…',
			'done'    => '{total} earlier message{s:total} sealed',
			'none'    => 'No earlier messages needed sealing',
			'stuck'   => '{remaining} message{s:remaining} could not be sealed — see the Setup tab.',
			'paused'  => 'Sealing paused — reload this page to resume.',
		),
	));

	$html = '<div id="raise-receipt" style="border:1px solid #d8dee4;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1rem;"'
		. ' data-domain-id="' . intval($domain->key) . '"'
		. ' data-title-done="' . htmlspecialchars($title_done) . '"'
		. ($backlog > 0 ? ' data-ceremony-batch="' . htmlspecialchars($ceremony_config, ENT_QUOTES) . '"' : '')
		. '>';
	$html .= '<h3 id="receipt-title" style="margin-top:0;">' . htmlspecialchars($title) . '</h3>';
	$html .= '<ul style="list-style:none;padding:0;margin:0;">';
	$html .= '<li id="receipt-seal-row" style="display:flex;align-items:baseline;margin:.5rem 0;">'
		. $dot($seal_dot, true) . '<div id="receipt-seal-text" data-ceremony-text>' . $seal_fact . '</div></li>';
	$html .= '<li style="display:flex;align-items:baseline;margin:.5rem 0;">'
		. $dot('pass') . '<div>New mail seals on arrival</div></li>';
	$html .= '<li style="display:flex;align-items:baseline;margin:.5rem 0;">'
		. $dot('pass') . '<div>' . htmlspecialchars($unlock_fact) . '</div></li>';
	$html .= '</ul>';
	$html .= '<div style="margin-top:.75rem;"><a id="receipt-action" class="btn btn-primary'
		. ($backlog > 0 ? ' d-none' : '') . '"' . ($backlog > 0 ? ' data-ceremony-when-done hidden' : '')
		. ' href="' . htmlspecialchars($button_url) . '">'
		. htmlspecialchars($button_label) . '</a></div>';
	if ($backlog > 0) {
		// No-JS fallback: the bounded server-side batch loop, one page load per
		// pass. The shared loop removes this form once it takes over.
		$html .= '<form method="post" action="' . htmlspecialchars($state['editor_url'] ?? '') . '"'
			. ' id="sealing-continue" data-ceremony-noscript>'
			. '<input type="hidden" name="action" value="ceremony_seal_batch">'
			. '<input type="hidden" name="ied_inbound_email_domain_id" value="' . intval($domain->key) . '">'
			. ($alias_scope_id > 0
				? '<input type="hidden" name="alias_scope_id" value="' . $alias_scope_id . '">' : '')
			. '<noscript><button type="submit" class="btn btn-primary">Continue sealing</button></noscript>'
			. '</form>';
	}
	$html .= '</div>';
	return $html;
}

/**
 * Sealed-or-pending rows on one domain, split by whether the caller's own
 * session can converge them (specs/mailbox_lowering_unseal.md). Returns
 * ['own' => n, 'others' => n]; legacy rows with no recorded owner count as
 * others (no session can claim them).
 */
function mailbox_protection_unseal_counts(InboundEmailDomain $domain, int $caller_user_id,
		int $alias_scope_id = 0): array {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT
			COUNT(*) FILTER (WHERE m.iem_sealed_owner_user_id = ?) AS own,
			COUNT(*) FILTER (WHERE m.iem_sealed_owner_user_id IS DISTINCT FROM ?) AS others
		 FROM iem_inbound_email_messages m
		 " . mailbox_protection_posture_join() . "
		 WHERE m.iem_ied_inbound_email_domain_id = ?
		   AND NOT (" . mailbox_protection_seals_sql() . ")
		   AND (m.iem_content_sealed = true OR m.iem_pending_parse = true)
		   AND m.iem_delete_time IS NULL"
		. mailbox_protection_alias_scope_sql($alias_scope_id, 'm'));
	$stmt->execute(array($caller_user_id, $caller_user_id, intval($domain->key)));
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return array('own' => intval($row['own'] ?? 0), 'others' => intval($row['others'] ?? 0));
}

/**
 * Render the lowering receipt card (specs/mailbox_lowering_unseal.md) — the
 * downgrade mirror of the raise receipt: history converges back OUT of the
 * sealed form, per holder, caller-driven.
 *
 * $state:
 *   own_backlog    — sealed-or-pending rows the acting user can converge
 *   others_backlog — rows sealed to other holders (converge from their sessions)
 *   window_open    — whether the acting user's unlock window is open now
 *   editor_url     — this editor page (the no-JS continue form)
 *   alias_scope_id — the ONE mailbox this lowering covers, or 0 for the domain
 *   scope_label    — that mailbox's address, when scoped (the title states it)
 *   scope_level    — that mailbox's level, when scoped
 */
function mailbox_lowering_receipt_render(InboundEmailDomain $domain, array $state): string {
	$own = intval($state['own_backlog'] ?? 0);
	$others = intval($state['others_backlog'] ?? 0);
	$window_open = !empty($state['window_open']);
	$alias_scope_id = intval($state['alias_scope_id'] ?? 0);

	// $live marks the one dot the shared batch loop recolors as it works
	// (data-ceremony-dot); the rest are static facts.
	$dot = function ($status, $live = false) {
		$color = array('pass' => '#28a745', 'fail' => '#dc3545', 'warn' => '#ffc107', 'info' => '#6c757d');
		return '<span class="receipt-dot"' . ($live ? ' data-ceremony-dot' : '')
			. ' style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'
			. ($color[$status] ?? '#6c757d') . ';margin-right:8px;flex:none;"></span>';
	};

	$title = ($alias_scope_id > 0)
		? (((string)($state['scope_label'] ?? '') !== '' ? (string)$state['scope_label'] : 'This mailbox')
			. ' is now ' . ucfirst((string)($state['scope_level'] ?? InboundEmailDomain::LEVEL_STANDARD)))
		: ('This domain is now ' . ucfirst($domain->security_level()));

	if ($own > 0 && $window_open) {
		$unseal_dot = 'warn';
		$unseal_fact = 'Unsealing earlier messages — ' . $own . ' remaining&hellip;';
	} elseif ($own > 0) {
		$unseal_dot = 'warn';
		$unseal_fact = 'Unlock your vault, then reload this page to continue unsealing — '
			. $own . ' message' . ($own === 1 ? '' : 's') . ' remaining.';
	} else {
		$unseal_dot = 'pass';
		$unseal_fact = 'All earlier messages are readable';
	}

	$html = '<div id="lowering-receipt" style="border:1px solid #d8dee4;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1rem;"'
		. ' data-domain-id="' . intval($domain->key) . '"'
		. ' data-alias-id="' . $alias_scope_id . '"'
		. ' data-own-backlog="' . $own . '"'
		. ' data-window-open="' . ($window_open ? '1' : '0') . '">';
	$html .= '<h3 id="lowering-title" style="margin-top:0;">' . htmlspecialchars($title) . '</h3>';
	$html .= '<ul style="list-style:none;padding:0;margin:0;">';
	$html .= '<li id="lowering-unseal-row" style="display:flex;align-items:baseline;margin:.5rem 0;">'
		. $dot($unseal_dot) . '<div id="lowering-unseal-text">' . $unseal_fact . '</div></li>';
	$html .= '<li style="display:flex;align-items:baseline;margin:.5rem 0;">'
		. $dot('pass') . '<div>New mail is stored ready to read — no unlock needed</div></li>';
	if ($others > 0) {
		$html .= '<li id="lowering-others-row" style="display:flex;align-items:baseline;margin:.5rem 0;">'
			. $dot('info') . '<div id="lowering-others-text">' . $others . ' message' . ($others === 1 ? '' : 's')
			. ' stay sealed until their readers next unlock</div></li>';
	}
	$html .= '</ul>';
	$html .= '<div style="margin-top:.75rem;"><a id="lowering-action" class="btn btn-primary'
		. (($own > 0 && $window_open) ? ' d-none' : '') . '" href="/plugins/mailbox/admin/admin_mailbox_reader">'
		. 'Open mailbox</a></div>';
	if ($own > 0 && $window_open) {
		// No-JS fallback: bounded server-side unseal batches, one page load
		// per pass. The JS loop removes this form once it takes over.
		$html .= '<form method="post" action="' . htmlspecialchars($state['editor_url'] ?? '') . '" id="unsealing-continue">'
			. '<input type="hidden" name="action" value="ceremony_unseal_batch">'
			. '<input type="hidden" name="ied_inbound_email_domain_id" value="' . intval($domain->key) . '">'
			. ($alias_scope_id > 0
				? '<input type="hidden" name="alias_scope_id" value="' . $alias_scope_id . '">' : '')
			. '<noscript><button type="submit" class="btn btn-primary">Continue unsealing</button></noscript>'
			. '</form>';
	}
	$html .= '</div>';
	return $html;
}
?>
