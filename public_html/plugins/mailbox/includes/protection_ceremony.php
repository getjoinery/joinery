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
 * @version 1.3
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
 *   passkeys_enabled — bool
 *   relay_fronted    — bool (a MailboxRelay row fronts this deployment)
 *   aliases          — [{alias_id, address, holders: [{user_id, name,
 *                        has_vault, has_prf_passkey}]}] for live aliases
 */
function mailbox_protection_facts(InboundEmailDomain $domain): array {
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
	$aliases = new MultiInboundEmailAlias(array('domain_id' => intval($domain->key), 'deleted' => false));
	$aliases->load();
	foreach ($aliases as $alias) {
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

	return array(
		'passkeys_enabled' => (bool)$settings->get_setting('passkeys_enabled'),
		'relay_fronted'    => $fronted,
		'aliases'          => $aliases_out,
	);
}

/**
 * Evaluate the ceremony rows for a target level. Pure — facts in, rows out.
 * Each row: {id, severity: required|recommended|info, status: pass|fail|warn|info,
 * label, summary, actions: [{type, ...}]}. Action types:
 *   remove_grant {alias_id, user_id, name}  — inline one-reader fix
 *   add_reader   {alias_id}                 — holderless mailbox fix
 *   vault_self   {}                         — session user sets up their vault
 *   passkey_self {}                         — session user enrolls a passkey
 */
function mailbox_protection_rows(array $facts, string $target, int $acting_user_id): array {
	$rows = array();
	$fortress = ($target === InboundEmailDomain::LEVEL_FORTRESS);

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
 * The refusal message for a grant-list change on a protected domain, or null
 * when the change is fine (specs/mailbox_protection_ceremony.md § 2b). The
 * alias editor enforces this at its save — the raised state must be
 * impossible to corrupt, not merely alarmed about.
 */
function mailbox_protected_grant_error(InboundEmailDomain $domain, array $user_ids): ?string {
	if (!$domain->key || !$domain->seals_content()) {
		return null;
	}
	if (count($user_ids) > 1) {
		return 'This mailbox is on a protected domain, and protected mail is sealed to one '
			. 'person\'s key — it can have exactly one member. To share it, lower the domain to Standard first.';
	}
	if (count($user_ids) === 0) {
		return 'This mailbox is on a protected domain, so it needs its owner from the start — '
			. 'mail arriving with no one to seal to would be stored unprotected. Pick exactly one member.';
	}
	return null;
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

/** Unsealed, live rows on a domain — the backlog a raise must converge. */
function mailbox_protection_backlog_count(int $domain_id): int {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT COUNT(*) FROM iem_inbound_email_messages
		 WHERE iem_ied_inbound_email_domain_id = ?
		   AND iem_content_sealed = false AND iem_delete_time IS NULL");
	$stmt->execute(array($domain_id));
	return intval($stmt->fetchColumn());
}

/**
 * Seal one bounded batch of a domain's unsealed rows to each mailbox's
 * holder vault — the same per-row work as the reader-driven backfill_seal
 * action, but driveable from any admin session: sealing uses only the
 * holder's vault PUBLIC key. Rows whose holder has no vault are skipped
 * (counted in remaining; the Setup tab's backlog row keeps them loud).
 * Returns ['sealed' => n, 'remaining' => n].
 */
function mailbox_protection_seal_batch(InboundEmailDomain $domain, int $limit = 200): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT iem_inbound_email_message_id, iem_iea_inbound_email_alias_id
		 FROM iem_inbound_email_messages
		 WHERE iem_ied_inbound_email_domain_id = ?
		   AND iem_content_sealed = false AND iem_delete_time IS NULL
		 ORDER BY iem_inbound_email_message_id ASC LIMIT " . intval($limit));
	$stmt->execute(array(intval($domain->key)));
	$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$router = new InboundEmailRouter();
	$vault_cache = array();
	$sealed = 0;
	foreach ($targets as $t) {
		$alias_id = intval($t['iem_iea_inbound_email_alias_id']);
		if (!isset($vault_cache[$alias_id])) {
			$owner_id = $alias_id ? InboundEmailMessage::singleOwnerUserId($alias_id) : null;
			$vault_cache[$alias_id] = ($owner_id !== null) ? UserEncryptionVault::loadForUser($owner_id) : null;
		}
		$vault = $vault_cache[$alias_id];
		if ($vault === null) {
			continue; // no single holder with a vault — stays in the backlog count
		}
		$msg = new InboundEmailMessage(intval($t['iem_inbound_email_message_id']), TRUE);
		if (!$msg->key) {
			continue;
		}
		try {
			// Same per-row shape as backfill_seal_logic: an outbound row's
			// iem_recipient is real content and must seal; an inbound row's is
			// routing metadata and stays plaintext.
			$seal_recipient = ((string)$msg->get('iem_direction') === 'outbound');
			$dek = InboundEmailMessage::sealAndPersistContent(
				intval($msg->key), $vault,
				(string)$msg->get('iem_sender'), (string)$msg->get('iem_recipient'), (string)$msg->get('iem_subject'),
				(string)$msg->get('iem_body_plain'), (string)$msg->get('iem_body_html'), $seal_recipient
			);
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

	return array('sealed' => $sealed, 'remaining' => mailbox_protection_backlog_count(intval($domain->key)));
}

/**
 * Render the ceremony checklist for the domain editor. $urls carries the
 * editor's own URL (for the remove-grant forms and vault/passkey returns):
 *   editor_url — this domain's editor page (absolute path)
 *   alias_url  — the alias editor base
 */
function mailbox_protection_render(array $rows, InboundEmailDomain $domain, array $urls): string {
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
	$html .= '<h4 style="margin-top:1rem;">Before this domain can be protected</h4>';
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
			} elseif ($action['type'] === 'vault_self') {
				$html .= ' <a class="btn btn-sm btn-primary" style="margin-left:.5rem;" href="'
					. htmlspecialchars($security_url) . '#vault-panel">Set up your vault</a>';
			} elseif ($action['type'] === 'passkey_self') {
				$html .= ' <a class="btn btn-sm btn-outline-primary" style="margin-left:.5rem;" href="'
					. htmlspecialchars($security_url) . '#passkeys-panel">Add a passkey</a>';
			}
		}
		$html .= '</div></li>';
	}
	$html .= '</ul></div>';
	return $html;
}
?>
