<?php
/**
 * Enable protection ceremony for a sending identity domain
 * (specs/mailbox_outbound_send_protection.md, Phase 7).
 *
 * A protected domain's DKIM private key is generated and sealed here, in-window,
 * to the operator's vault public key — the plaintext never touches disk and is
 * never given to opendkim. The ceremony is ordered so compose is never broken:
 *
 *   set forwarding subdomain → generate → publish DNS → verify (Setup shape) →
 *   activate (flip the flag) → remove opendkim signing.
 *
 * "generate" seals the key and shows the DNS to publish but does NOT enforce;
 * it is refused once the domain is enforced. "activate" verifies the published
 * DNS is the correct protected shape (SPF excludes the box, strict DMARC, the
 * DKIM record matches the sealed key's public half, the forwarding subdomain
 * has SPF and an MX back to this box) before flipping ied_is_protected_identity,
 * then surfaces the root command that removes the domain from opendkim's
 * signing table.
 *
 * Rotation on an enforced domain is STAGED: "rotate" seals the new key into the
 * pending columns while the live key keeps signing; "activate_rotation" swaps
 * pending → live only after the pending selector's DNS record verifies;
 * "cancel_rotation" abandons the staged key. The live key is never overwritten
 * or destroyed until its replacement is proven in DNS.
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function mailbox_protect_domain_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDkimSigner.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$accounts_url = '/plugins/mailbox/admin/admin_mailbox_accounts';

	$domain_id = intval($input['ied_inbound_email_domain_id'] ?? 0);
	$domain = $domain_id > 0 ? new InboundEmailDomain($domain_id, TRUE) : null;
	if (!$domain || !$domain->key || $domain->get('ied_delete_time')) {
		$session->save_message(new DisplayMessage('That domain no longer exists.', 'Not found',
			'~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($accounts_url);
	}
	if ($domain->get('ied_is_imap_source')) {
		$session->save_message(new DisplayMessage(
			'An IMAP-source domain is not a hosted sending identity and cannot be protected.', 'Not applicable',
			'~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($accounts_url);
	}

	$action = (string)($input['action'] ?? '');
	$user_id = intval($session->get_user_id());

	// ── set the forwarding subdomain (required part of the protected shape) ──
	if ($action === 'set_forwarding_subdomain') {
		$name = strtolower((string)$domain->get('ied_domain'));
		$sub = strtolower(trim((string)($input['ied_forwarding_subdomain'] ?? '')));
		if ($sub === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $sub)) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'Enter a valid forwarding subdomain, e.g. fwd.' . $name . '.'));
		}
		if ($sub === $name) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'The forwarding subdomain must differ from the domain itself — the bare domain\'s SPF excludes this server, so an envelope under it would fail SPF everywhere.'));
		}
		if (substr($sub, -strlen('.' . $name)) !== '.' . $name) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'The forwarding subdomain must be a subdomain of ' . $name . ', e.g. fwd.' . $name . '.'));
		}
		$domain->set('ied_forwarding_subdomain', $sub);
		$domain->save();
		$session->save_message(new DisplayMessage(
			'Forwarding subdomain saved. Publish its SPF and MX records below, then Verify.',
			'Saved', '~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect(_protect_page_url($domain_id));
	}

	// ── generate (seal the first DKIM key in-window; pre-enforcement only) ───
	if ($action === 'generate') {
		if ($domain->is_protected_identity()) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'This domain is enforced — use Rotate key, which stages the new key and cuts over only after its DNS record verifies. Re-generating in place would break sending.'));
		}
		$vault = UserEncryptionVault::loadForUser($user_id);
		if ($vault === null) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'Set up your Sealed Vault before protecting a domain — the DKIM key seals to it.'));
		}
		if (!VaultUnlock::isOpen($user_id)) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'Unlock your vault to generate and seal the DKIM key, then try again.'));
		}

		try {
			$kp = MailboxDkimSigner::generateKeypair();
		} catch (Throwable $e) {
			error_log('mailbox protect: keypair generation failed: ' . $e->getMessage());
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'Could not generate a DKIM keypair on this server.'));
		}

		$generation = intval($domain->get('ied_dkim_key_generation')) + 1;
		$selector = 'mailk' . $generation;

		$crypto = new VaultCrypto();
		$sealed = $crypto->sealItemDek($kp['private_pem'], (string)$vault->get('uev_public_key'));

		$domain->set('ied_owner_usr_user_id', $user_id);
		$domain->set('ied_dkim_selector', $selector);
		$domain->set('ied_dkim_sealed_key', $sealed);
		$domain->set('ied_dkim_public_dns', $kp['dns_value']);
		$domain->set('ied_dkim_key_generation', $generation);
		$domain->save();

		$session->save_message(new DisplayMessage(
			'DKIM key generated and sealed. Publish the DNS records below, then Verify.',
			'Key sealed', '~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect(_protect_page_url($domain_id));
	}

	// ── rotate (stage a new key; the live key keeps signing until cutover) ───
	if ($action === 'rotate') {
		if (!$domain->is_protected_identity()) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'This domain is not enforced yet — use Enable protection / Re-generate key instead of Rotate.'));
		}
		$vault = UserEncryptionVault::loadForUser($user_id);
		if ($vault === null) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'Set up your Sealed Vault before rotating — the DKIM key seals to it.'));
		}
		if (!VaultUnlock::isOpen($user_id)) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'Unlock your vault to generate and seal the new DKIM key, then try again.'));
		}

		try {
			$kp = MailboxDkimSigner::generateKeypair();
		} catch (Throwable $e) {
			error_log('mailbox protect: keypair generation failed: ' . $e->getMessage());
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'Could not generate a DKIM keypair on this server.'));
		}

		$selector = 'mailk' . (intval($domain->get('ied_dkim_key_generation')) + 1);
		$crypto = new VaultCrypto();
		$sealed = $crypto->sealItemDek($kp['private_pem'], (string)$vault->get('uev_public_key'));

		$domain->set('ied_dkim_pending_selector', $selector);
		$domain->set('ied_dkim_pending_sealed_key', $sealed);
		$domain->set('ied_dkim_pending_public_dns', $kp['dns_value']);
		$domain->save();

		$session->save_message(new DisplayMessage(
			'New key staged under selector ' . $selector . '. Publish its DNS record below, then Verify & cut over. '
			. 'The current key keeps signing until the cutover — leave its DNS record in place.',
			'Rotation staged', '~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect(_protect_page_url($domain_id));
	}

	// ── activate_rotation (verify the pending DNS, then swap pending → live) ─
	if ($action === 'activate_rotation') {
		if ((string)$domain->get('ied_dkim_pending_sealed_key') === '') {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'No rotation is staged — use Rotate key first.'));
		}
		$checker = new InboundEmailSetupCheck();
		$pending = $checker->pendingDkimResult($domain);
		if ($pending['status'] !== InboundEmailSetupCheck::PASS) {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'The new key is not live in DNS yet — nothing changed, the current key keeps signing. '
				. $pending['label'] . ' — ' . $pending['summary']));
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

		$session->save_message(new DisplayMessage(
			'Rotation complete — compose now signs with the new key. Once outbound mail sent before the '
			. 'cutover has cleared (a day is plenty), you may remove the old DNS record at '
			. $old_selector . '._domainkey.' . $domain->get('ied_domain') . '.',
			'Rotation complete', '~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect(_protect_page_url($domain_id));
	}

	// ── cancel_rotation (abandon the staged key; the live key was untouched) ─
	if ($action === 'cancel_rotation') {
		$domain->set('ied_dkim_pending_selector', null);
		$domain->set('ied_dkim_pending_sealed_key', null);
		$domain->set('ied_dkim_pending_public_dns', null);
		$domain->save();
		$session->save_message(new DisplayMessage(
			'Rotation canceled. The current key was never touched and keeps signing.',
			'Rotation canceled', '~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect(_protect_page_url($domain_id));
	}

	// ── activate (verify DNS shape, then enforce) ────────────────────────────
	if ($action === 'activate') {
		if ((string)$domain->get('ied_dkim_sealed_key') === '') {
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, null,
				'Generate and seal a DKIM key first.'));
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
			return LogicResult::render(_protect_view_vars($domain, $settings, $session, $results,
				'The DNS is not yet the protected shape — publish the records below and re-verify: ' . implode(' | ', $blockers)));
		}

		$domain->set('ied_is_protected_identity', true);
		$domain->save();

		$remove_cmd = 'sudo bash ' . PathHelper::getIncludePath('plugins/mailbox/provisioning/provision_dkim.sh')
			. ' --remove ' . $domain->get('ied_domain');
		$session->save_message(new DisplayMessage(
			'Protection is enforced. Final step (root): run  ' . $remove_cmd
			. '  so opendkim stops signing this domain, leaving the in-app signer as the sole signer.',
			'Protection enforced', '~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect(_protect_page_url($domain_id));
	}

	// ── disable ──────────────────────────────────────────────────────────────
	if ($action === 'disable') {
		$domain->set('ied_is_protected_identity', false);
		$domain->save();
		$session->save_message(new DisplayMessage(
			'Protection disabled. This domain can send ambiently again; re-run provision_dkim.sh to restore opendkim signing if needed.',
			'Protection disabled', '~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect(_protect_page_url($domain_id));
	}

	// ── default: render current state ────────────────────────────────────────
	$results = null;
	if ((string)$domain->get('ied_dkim_sealed_key') !== '') {
		$checker = new InboundEmailSetupCheck();
		$results = $checker->protectedDomainChecks($domain);
	}
	return LogicResult::render(_protect_view_vars($domain, $settings, $session, $results, null));
}

/** The protection page URL for a domain. */
function _protect_page_url(int $domain_id): string {
	return '/plugins/mailbox/admin/admin_mailbox_protect?ied_inbound_email_domain_id=' . $domain_id;
}

/** Assemble the view variables (state + the DNS records to publish). */
function _protect_view_vars(InboundEmailDomain $domain, $settings, $session, $results, $error): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	$name = (string)$domain->get('ied_domain');
	$fwd = $domain->forwarding_subdomain();

	// Box IP for the SPF records: the configured public IP, else live detection.
	$box_ip = trim((string)$settings->get_setting('mailbox_public_ip'));
	if ($box_ip === '') {
		$box_ip = (new InboundEmailSetupCheck())->getPublicIp();
	}
	$box_ip = $box_ip !== '' ? $box_ip : 'YOUR_SERVER_IP';

	// MX target for the forwarding subdomain: the box's canonical mail host.
	$mail_host = strtolower(trim((string)$settings->get_setting('mailbox_mail_hostname')));
	if ($mail_host === '') {
		$mail_host = 'YOUR_MAIL_HOST';
	}

	$dns_records = array();
	$selector = (string)$domain->get('ied_dkim_selector');
	$public_dns = (string)$domain->get('ied_dkim_public_dns');
	if ($selector !== '' && $public_dns !== '') {
		$dns_records[] = array('type' => 'TXT', 'name' => $selector . '._domainkey.' . $name, 'value' => $public_dns,
			'note' => 'DKIM public key (matches the in-app sealed key).');
	}
	$pending_selector = (string)$domain->get('ied_dkim_pending_selector');
	$pending_dns = (string)$domain->get('ied_dkim_pending_public_dns');
	if ($pending_selector !== '' && $pending_dns !== '') {
		$dns_records[] = array('type' => 'TXT', 'name' => $pending_selector . '._domainkey.' . $name, 'value' => $pending_dns,
			'note' => 'PENDING rotation key — publish this, then Verify & cut over. Leave the current key\'s record in place until after the cutover.');
	}
	$dns_records[] = array('type' => 'TXT', 'name' => $name, 'value' => 'v=spf1 -all',
		'note' => 'Excludes this server — a locked box cannot send SPF-aligned mail.');
	$dns_records[] = array('type' => 'TXT', 'name' => '_dmarc.' . $name, 'value' => 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@' . $name,
		'note' => 'Strict alignment — the only path to acceptance is the in-app signature.');
	if ($fwd !== '' && strcasecmp($fwd, $name) !== 0) {
		$dns_records[] = array('type' => 'TXT', 'name' => $fwd, 'value' => 'v=spf1 ip4:' . $box_ip . ' -all',
			'note' => 'Forwarding-subdomain SPF — lets alias forwarding route while locked.');
		$dns_records[] = array('type' => 'MX', 'name' => $fwd, 'value' => '10 ' . $mail_host,
			'note' => 'Forwarding-subdomain MX — routes delivery-failure notices (SRS bounces) back to this server.');
	}

	return array(
		'domain' => $domain,
		'domain_name' => $name,
		'is_protected' => $domain->is_protected_identity(),
		'has_key' => ((string)$domain->get('ied_dkim_sealed_key') !== ''),
		'has_pending_rotation' => ((string)$domain->get('ied_dkim_pending_sealed_key') !== ''),
		'pending_selector' => $pending_selector,
		'forwarding_subdomain' => $fwd,
		'has_forwarding_subdomain' => ($fwd !== '' && strcasecmp($fwd, $name) !== 0),
		'dns_records' => $dns_records,
		'check_results' => $results,
		'error' => $error,
		'session' => $session,
		'settings' => $settings,
	);
}
?>
