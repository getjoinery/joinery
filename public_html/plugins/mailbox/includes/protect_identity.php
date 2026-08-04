<?php
/**
 * Outbound send protection for a sending identity domain
 * (specs/mailbox_outbound_send_protection.md, Phase 7).
 *
 * A protected domain's DKIM private key is generated and sealed to the owner's
 * vault public key — the plaintext never touches disk and is never given to
 * opendkim. The arc:
 *
 *   key sealed → publish DNS → verify (protected shape) → activate (flip the
 *   flag) → remove opendkim signing.
 *
 * The key is sealed by the Fortress raise itself (admin_mailbox_domains_logic),
 * so the operator never asks for one: the return address defaults to
 * fwd.<domain>, and the owner is the person doing the raise. The only case that
 * cannot be guessed is a domain whose mailboxes already have holders — the
 * admin raising the level need not be the person who reads the mail — so the
 * Setup tab asks there, and mailbox_protect_handle_action() takes the answer.
 *
 * THIS FILE OWNS THE STATE TRANSITIONS, NOT A PAGE. Every surface that drives
 * protection posts an action here and is redirected back to itself; the Setup
 * tab's Advanced section is that surface. Nothing renders the DNS records or the
 * verification from here — InboundEmailSetupCheck produces both, and a second
 * rendering could only ever drift from it.
 *
 * THE SHAPE IS PRESCRIBED ONLY ONCE PROTECTION IS ON, or inside the ceremony
 * that turns it on. It tells the world to reject anything the sealed key did not
 * sign, and nothing signs with that key until ied_is_protected_identity is set —
 * so prescribing it at the security level would hand a Fortress domain that has
 * not opted in a record set that rejects its own outgoing mail. Send protection
 * is a deliberate opt-in; a Fortress domain resting without it is finished
 * (specs/mailbox_relay_surface_simplification.md).
 *
 * PROOF OF PRESENCE SITS ON ENFORCEMENT, NOT ON KEY CREATION. Sealing needs
 * only the owner's public key, and a key that exists publishes nothing and
 * changes no mail, so generate and rotate run without an unlock window.
 * Activate and the rotation cutover require one: those decide what the rest of
 * the world will accept as this domain.
 *
 * Rotation is STAGED: rotate seals the new key into the pending columns while
 * the live key keeps signing; the cutover swaps pending → live only after the
 * pending selector's DNS record verifies; cancel abandons the staged key. The
 * live key is never overwritten or destroyed until its replacement is proven in
 * DNS.
 *
 * @version 1.2 - the whole ceremony is an opt-in that lives in Advanced, and the
 *                old on-disk key is a checked state rather than a remembered
 *                command (specs/mailbox_relay_surface_simplification.md)
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));

/**
 * Who could own this domain's signing key, as user_id => display label.
 *
 * The owner is the only person who can ever sign as the domain, so guessing is
 * only safe when there is nothing to guess between. A domain with no mailbox
 * holders has exactly one sensible answer — the person setting it up.
 *
 * @return array<int,string> Never empty: falls back to the acting user.
 */
function mailbox_protect_candidate_owners(InboundEmailDomain $domain, int $acting_user_id): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$out = array();
	$aliases = new MultiInboundEmailAlias(array('domain_id' => intval($domain->key), 'deleted' => false));
	$aliases->load();
	foreach ($aliases as $alias) {
		foreach (InboundEmailMailboxGrant::user_ids_for_alias(intval($alias->key)) as $uid) {
			$uid = intval($uid);
			if ($uid <= 0 || isset($out[$uid])) { continue; }
			$user = new User($uid, TRUE);
			$name = trim((string)$user->get('usr_first_name') . ' ' . (string)$user->get('usr_last_name'));
			if ($name === '') { $name = (string)$user->get('usr_email'); }
			$out[$uid] = ($uid === $acting_user_id) ? $name . ' (you)' : $name;
		}
	}
	if (empty($out) && $acting_user_id > 0) {
		$out[$acting_user_id] = 'You';
	}
	return $out;
}

/**
 * True when the key can be sealed without asking anything — no mailbox holders,
 * so the person setting the domain up is the only candidate owner.
 */
function mailbox_protect_owner_is_unambiguous(InboundEmailDomain $domain, int $acting_user_id): bool {
	$candidates = mailbox_protect_candidate_owners($domain, $acting_user_id);
	return count($candidates) === 1 && array_key_exists($acting_user_id, $candidates);
}

/**
 * Generate a DKIM keypair, seal the private half to $owner_id's vault, and
 * store it with a fresh selector. Also defaults the return address when unset.
 * Returns null on success, or a message for the operator.
 */
function mailbox_protect_seal_new_key(InboundEmailDomain $domain, int $owner_id): ?string {
	require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDkimSigner.php'));

	$vault = UserEncryptionVault::loadForUser($owner_id);
	if ($vault === null) {
		return 'That person has no vault yet — the key gets locked inside it, so it has to exist first.';
	}
	// render_pgsql_map.php reads this column, not forwarding_subdomain(), so an
	// unwritten default would leave Postfix refusing the bounces it must catch.
	if (trim((string)$domain->get('ied_forwarding_subdomain')) === '') {
		$domain->set('ied_forwarding_subdomain', 'fwd.' . strtolower((string)$domain->get('ied_domain')));
	}
	try {
		$kp = MailboxDkimSigner::generateKeypair();
	} catch (Throwable $e) {
		error_log('mailbox protect: keypair generation failed: ' . $e->getMessage());
		return 'Could not generate a signing key on this server.';
	}

	$generation = intval($domain->get('ied_dkim_key_generation')) + 1;
	$crypto = new VaultCrypto();

	$domain->set('ied_owner_usr_user_id', $owner_id);
	$domain->set('ied_dkim_selector', 'mailk' . $generation);
	$domain->set('ied_dkim_sealed_key', $crypto->sealItemDek($kp['private_pem'], (string)$vault->get('uev_public_key')));
	$domain->set('ied_dkim_public_dns', $kp['dns_value']);
	$domain->set('ied_dkim_key_generation', $generation);
	$domain->save();
	return null;
}

/**
 * Validate and store the return address (the SRS/envelope subdomain). Returns
 * null on success, or a message written to say what to type, not what rule was
 * broken.
 *
 * It must sit under the protected domain and never be the domain itself: the
 * bare domain's SPF excludes this server, so an envelope sent under it would
 * fail SPF everywhere.
 */
function mailbox_protect_save_return_address(InboundEmailDomain $domain, string $raw): ?string {
	$name = strtolower((string)$domain->get('ied_domain'));
	$sub = strtolower(trim($raw));
	if ($sub === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $sub)) {
		return 'That is not a valid name. Use something like fwd.' . $name . '.';
	}
	if ($sub === $name || substr($sub, -strlen('.' . $name)) !== '.' . $name) {
		return 'The name has to sit under ' . $name . ', and cannot be ' . $name . ' itself — forwarded mail needs a '
			. 'different name from your protected one. Try fwd.' . $name . '.';
	}
	$domain->set('ied_forwarding_subdomain', $sub);
	$domain->save();
	return null;
}

/**
 * The protection facts a surface needs to decide what to offer, for a hosted
 * domain at Private or Fortress. Cheap — column reads plus the owner scan.
 */
function mailbox_protect_state(InboundEmailDomain $domain, int $acting_user_id): array {
	$owner_options = mailbox_protect_candidate_owners($domain, $acting_user_id);
	// NOT forwarding_subdomain(): that falls back to the bare domain, which is
	// the one value this field must never hold — the bare domain authorizes no
	// sender, so an envelope under it fails SPF everywhere. Until a key seals
	// and writes the column, show what sealing would write.
	$stored = trim((string)$domain->get('ied_forwarding_subdomain'));
	return array(
		'is_protected'        => $domain->is_protected_identity(),
		'has_key'             => ((string)$domain->get('ied_dkim_sealed_key') !== ''),
		'has_pending'         => ((string)$domain->get('ied_dkim_pending_sealed_key') !== ''),
		'pending_selector'    => (string)$domain->get('ied_dkim_pending_selector'),
		'return_address'      => $stored !== '' ? $stored
			: 'fwd.' . strtolower((string)$domain->get('ied_domain')),
		'owner_options'       => $owner_options,
		'default_owner_id'    => isset($owner_options[$acting_user_id]) ? $acting_user_id : key($owner_options),
	);
}

/**
 * Handle a protect_* action posted by whatever surface is driving protection.
 *
 * Returns a LogicResult redirect back to $return_url (with a flash message,
 * good or bad), or null when $input carries no protect action. Errors are
 * flashes rather than rendered state so any surface can host these controls —
 * none of them can fail in a way that needs its own screen.
 */
function mailbox_protect_handle_action(array $input, $session, string $return_url) {
	$action = (string)($input['action'] ?? '');
	if (strpos($action, 'protect_') !== 0) {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));

	$say = function ($msg, $title) use ($session, $return_url) {
		$session->save_message(new DisplayMessage($msg, $title, '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($return_url);
	};

	$domain_id = intval($input['ied_inbound_email_domain_id'] ?? 0);
	$domain = $domain_id > 0 ? new InboundEmailDomain($domain_id, TRUE) : null;
	if (!$domain || !$domain->key || $domain->get('ied_delete_time')) {
		return $say('That domain no longer exists.', 'Not found');
	}
	if ($domain->get('ied_is_imap_source')) {
		return $say('An IMAP-source domain is not a sending identity and cannot be protected.', 'Not applicable');
	}
	$user_id = intval($session->get_user_id());

	// ── seal the first key (only reached when the owner was ambiguous) ───────
	if ($action === 'protect_generate') {
		if ($domain->is_protected_identity()) {
			return $say('Send protection is already on. Use Replace my key — it keeps the current key working until the '
				. 'new one is proven in DNS.', 'Already on');
		}
		$owner_id = intval($input['owner_user_id'] ?? 0) ?: $user_id;
		$candidates = mailbox_protect_candidate_owners($domain, $user_id);
		if (!isset($candidates[$owner_id])) {
			return $say('Choose who this key belongs to — it has to be someone who reads mail on this domain.',
				'Pick an owner');
		}
		$error = mailbox_protect_seal_new_key($domain, $owner_id);
		if ($error !== null) {
			return $say($error, 'Could not make the key');
		}
		return $say('Your key is made and locked in the vault. Publish the DNS records below, then turn protection '
			. 'on. Nothing is enforced until you do.', 'Key created');
	}

	// ── return address ───────────────────────────────────────────────────────
	if ($action === 'protect_return_address') {
		$error = mailbox_protect_save_return_address($domain, (string)($input['ied_forwarding_subdomain'] ?? ''));
		if ($error !== null) {
			return $say($error, 'Not saved');
		}
		return $say('Saved. Republish the two records for that name, then check the DNS again.', 'Saved');
	}

	// ── activate: verify the published shape, then enforce ───────────────────
	if ($action === 'protect_activate') {
		if ((string)$domain->get('ied_dkim_sealed_key') === '') {
			return $say('There is no key yet — make one first.', 'No key');
		}
		// The one place proof of presence matters: from here on, mail from this
		// domain is rejected everywhere unless this vault signed it. An idle
		// admin session must not be able to switch that on.
		if (!VaultUnlock::isOpen($user_id)) {
			return $say('Unlock your vault before turning protection on — from here on, only your key can send as '
				. 'this domain, so we need to know you are really here.', 'Unlock first');
		}
		$checker = new InboundEmailSetupCheck();
		$results = $checker->protectedDomainChecks($domain);
		$blockers = array();
		foreach ($results as $r) {
			if ($r['severity'] === InboundEmailSetupCheck::REQUIRED
					&& in_array($r['status'], array(InboundEmailSetupCheck::FAIL, InboundEmailSetupCheck::UNKNOWN), true)) {
				$blockers[] = $r['label'] . ' — ' . $r['summary'];
			}
		}
		if (!empty($blockers)) {
			return $say('Some DNS is missing or does not match, so nothing was turned on and your mail is '
				. 'unaffected. Publish the records below and check again. Still to fix: '
				. implode(' | ', $blockers), 'Not yet');
		}

		$domain->set('ied_is_protected_identity', true);
		$domain->save();

		// The old on-disk key is NOT named here as a command to remember. It is a
		// checked state now (domain.local_signing_key), so it keeps asking from
		// the Setup tab until it is actually gone — which is what a one-shot
		// flash message could never do.
		return $say('Send protection is on. From now on nothing can send as ' . $domain->get('ied_domain')
			. ' that your key did not sign. One step is left, and the Setup tab is holding it: the ordinary '
			. 'signing key still on this server has to be destroyed.',
			'Send protection is on');
	}

	// ── rotate: stage a replacement; the live key keeps signing ──────────────
	if ($action === 'protect_rotate') {
		if (!$domain->is_protected_identity()) {
			return $say('Send protection is not on yet, so there is nothing to replace — turn it on first.',
				'Not on yet');
		}
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDkimSigner.php'));

		$owner_id = intval($domain->get('ied_owner_usr_user_id')) ?: $user_id;
		$vault = UserEncryptionVault::loadForUser($owner_id);
		if ($vault === null) {
			return $say('The key owner has no vault — the replacement gets locked inside it.', 'No vault');
		}
		try {
			$kp = MailboxDkimSigner::generateKeypair();
		} catch (Throwable $e) {
			error_log('mailbox protect: keypair generation failed: ' . $e->getMessage());
			return $say('Could not generate a signing key on this server.', 'Failed');
		}
		$crypto = new VaultCrypto();
		$domain->set('ied_dkim_pending_selector', 'mailk' . (intval($domain->get('ied_dkim_key_generation')) + 1));
		$domain->set('ied_dkim_pending_sealed_key',
			$crypto->sealItemDek($kp['private_pem'], (string)$vault->get('uev_public_key')));
		$domain->set('ied_dkim_pending_public_dns', $kp['dns_value']);
		$domain->save();

		return $say('Your replacement key is ready. Publish its DNS record below, then switch over. The old key is '
			. 'still doing the work, so leave its record alone.', 'Replacement key ready');
	}

	// ── cutover: swap pending → live, once the new record verifies ───────────
	if ($action === 'protect_activate_rotation') {
		if ((string)$domain->get('ied_dkim_pending_sealed_key') === '') {
			return $say('There is no replacement key waiting — press Replace my key first.', 'Nothing staged');
		}
		// Staging was free; switching over changes what signs live mail.
		if (!VaultUnlock::isOpen($user_id)) {
			return $say('Unlock your vault before switching keys — this changes what signs your live mail.',
				'Unlock first');
		}
		$checker = new InboundEmailSetupCheck();
		$pending = $checker->pendingDkimResult($domain);
		if ($pending['status'] !== InboundEmailSetupCheck::PASS) {
			return $say('The new key is not visible in DNS yet, so nothing changed and your current key still '
				. 'works. If you just published it, wait a few minutes and try again. ('
				. $pending['label'] . ' — ' . $pending['summary'] . ')', 'Not yet');
		}

		$old_selector = (string)$domain->get('ied_dkim_selector');
		$domain->set('ied_dkim_selector', (string)$domain->get('ied_dkim_pending_selector'));
		$domain->set('ied_dkim_sealed_key', (string)$domain->get('ied_dkim_pending_sealed_key'));
		$domain->set('ied_dkim_public_dns', (string)$domain->get('ied_dkim_pending_public_dns'));
		$domain->set('ied_dkim_key_generation', intval($domain->get('ied_dkim_key_generation')) + 1);
		$domain->set('ied_dkim_pending_selector', null);
		$domain->set('ied_dkim_pending_sealed_key', null);
		$domain->set('ied_dkim_pending_public_dns', null);
		$domain->save();

		return $say('Your mail is now signed with the new key. Leave the old record up for a day so mail already on '
			. 'its way is still accepted, then delete '
			. $old_selector . '._domainkey.' . $domain->get('ied_domain') . '.', 'Key replaced');
	}

	// ── cancel a staged rotation; the live key was never touched ─────────────
	if ($action === 'protect_cancel_rotation') {
		$domain->set('ied_dkim_pending_selector', null);
		$domain->set('ied_dkim_pending_sealed_key', null);
		$domain->set('ied_dkim_pending_public_dns', null);
		$domain->save();
		return $say('Forgotten. Your current key was never touched and is still working.', 'Replacement discarded');
	}

	// ── turn protection off ──────────────────────────────────────────────────
	if ($action === 'protect_disable') {
		$domain->set('ied_is_protected_identity', false);
		$domain->save();
		return $say('Send protection is off. This server can send as this domain again without you signed in, and '
			. 'arriving mail is still sealed — this only affected sending. If mail stops being accepted, re-run '
			. 'provision_dkim.sh to put an ordinary signing key back on disk.',
			'Send protection is off');
	}

	return $say('Unknown protection action.', 'Not done');
}

/**
 * Destroy the ordinary on-disk signing key for one domain.
 *
 * Runs a fixed-verb root helper, in the same shape the listener on/off controls
 * use: one allowlisted binary, one argument this side validates against the
 * registered domains, a success marker the caller demands, and no shell the web
 * user composes. Never blanket sudo, and never called on a schedule — deleting
 * key material is a thing a person decides to do.
 *
 * The caller is responsible for the safety gates (send protection on, protected
 * DNS verified); this function only refuses what it can see itself.
 *
 * @return array{ok:bool,message:string}
 */
function mailbox_destroy_local_signing_key(string $domain): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	$domain = strtolower(trim($domain));

	// The argument has to be a domain this deployment actually hosts. Anything
	// else is either a mistake or an attempt to point the helper somewhere else.
	// GetByDomain() returns FALSE for a miss, not null — a === null guard here
	// would be dead code that let every unknown domain through.
	if ($domain === '' || !InboundEmailDomain::GetByDomain($domain)) {
		return array('ok' => false, 'message' => 'No such domain on this server — nothing was touched.');
	}

	$path = InboundEmailSetupCheck::localSigningKeyPath($domain);
	$helper = InboundEmailSetupCheck::localSigningKeyHelper();
	if (!is_file($helper)) {
		return array('ok' => false, 'message' =>
			'This server has no key-removal helper installed, so it cannot do this for you. Run once as root: '
			. 'sudo bash ' . PathHelper::getIncludePath('plugins/mailbox/provisioning/provision_dkim.sh')
			. ' --remove ' . $domain);
	}

	$output = array();
	$code = 1;
	exec('sudo -n ' . escapeshellarg($helper) . ' ' . escapeshellarg($domain) . ' 2>&1', $output, $code);
	$text = trim(implode("\n", $output));
	if ($code !== 0 || strpos($text, 'DKIM_REMOVED') === false) {
		return array('ok' => false, 'message' =>
			'The key was not removed (exit ' . intval($code) . '): ' . ($text !== '' ? $text : 'no output')
			. ' — nothing changed, and your mail is unaffected.');
	}
	// Believe the marker, but check the file: this is the one operation whose
	// whole point is that the file is gone.
	if (is_readable($path)) {
		return array('ok' => false, 'message' =>
			'The helper reported success but the key file is still readable at ' . $path
			. ' — treat the key as live and remove it by hand.');
	}
	return array('ok' => true, 'message' =>
		'The old signing key for ' . $domain . ' is gone. Your sealed key is now the only thing on this server '
		. 'that can sign as this domain.');
}
?>
