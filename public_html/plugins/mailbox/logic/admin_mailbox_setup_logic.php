<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Logic for the Inbound Email Setup tab.
 *
 * The page is mailbox-first: an admin picks one of the mailboxes already
 * registered on the Accounts tab, and the checks are scoped to that mailbox —
 * a "Receiving" group always, plus a "Forwarding" group when the mailbox
 * forwards or a "Sending" group (relay, DKIM) when it stores only. The server-wide diagnostics (inbound provider, this server's mail
 * hostname/IP, and the full Postfix/relay health run) live behind the Advanced
 * disclosure (?advanced=1); they are useful but not per-mailbox, so they stay
 * out of the default view.
 *
 * A domain with no mailbox yet can be focused directly (?domain_id=). Its setup
 * is domain-level — the vault, outbound protection, the relay, the DNS shape —
 * so it renders the guided steps, the publish box and runDomainChecks(), and no
 * per-mailbox checks. For a Fortress domain those domain checks ARE the
 * protected-shape verification, so publishing can prove itself with no mailbox.
 *
 * Outbound send protection has no page of its own: mailbox_protect_handle_action()
 * runs every protect_* transition here and redirects back to the focused domain.
 *
 * POST actions: save the mail hostname / public IP, switch provider, enable the
 * plugin, enable SRS, register a domain, or apply a one-click fix — each writes
 * through a model and redirects so the next render reads fresh settings.
 *
 * @version 2.15 - the machine sender ceremony (specs/mailbox_machine_sender_card.md):
 *                 machine_setup view state, register/switch/test-send actions,
 *                 machine records in the publish box while the ceremony is open
 * @version 2.14 - the ceremony's publish box renders only while a readiness row
 *                 is unmet; a settled step is stated, not offered
 * @version 2.13 - the send-protection ceremony shows the checks and offers the
 *                 records that actually gate the press, not the finished shape
 * @version 2.12
 */
function admin_mailbox_setup_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundProviderRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	$gate_redirect = mailbox_receive_gate_handle($input);
	if ($gate_redirect !== null) {
		return $gate_redirect;
	}

	$base = '/plugins/mailbox/admin/admin_mailbox_setup';
	$advanced = !empty($input['advanced']);

	// The page focuses either a mailbox or a bare domain. A domain is registered
	// before any mailbox exists on it, and a Fortress domain's setup — vault,
	// protect ceremony, relay, DNS shape — is entirely domain-level, so a
	// mailbox-only picker would leave a freshly added domain with no guided
	// surface at all. The two states share one dropdown ('a<id>' / 'd<id>') but
	// stay separate query parameters, so every existing ?alias_id= link still
	// resolves the same way.
	$selected_alias_id  = isset($input['alias_id'])  ? (int)$input['alias_id']  : 0;
	$selected_domain_id = isset($input['domain_id']) ? (int)$input['domain_id'] : 0;
	if (isset($input['focus']) && is_string($input['focus']) && $input['focus'] !== '') {
		$focus_raw = trim($input['focus']);
		$focus_id  = (int)substr($focus_raw, 1);
		if ($focus_raw[0] === 'd') {
			$selected_domain_id = $focus_id;
			$selected_alias_id  = 0;
		} elseif ($focus_raw[0] === 'a') {
			$selected_alias_id  = $focus_id;
			$selected_domain_id = 0;
		}
	}
	// A mailbox already names its domain; carrying both would let them disagree.
	if ($selected_alias_id) { $selected_domain_id = 0; }

	// Build a redirect URL that keeps the focused mailbox or domain and the
	// advanced state, so a POST action returns the operator to exactly where they
	// were. Declared before every action handler below, all of which redirect
	// through it, and handed to the view as self_url: a form that posts anywhere
	// but here drops the focus and dumps the operator back on the picker.
	// Whether the operator has explicitly opened the send-protection ceremony
	// for the focused domain. Pure view state, never stored: every Fortress
	// domain already HAS a sealed key (the raise seals one), so "has a key but
	// is not enforcing" is the resting state of a finished domain, not a job in
	// progress. Only this flag distinguishes an operator who has asked to set
	// send protection up from one who is simply looking at their domain — and
	// nothing prescribes the protected DNS shape until they have asked.
	$protect_setup = !empty($input['protect_setup']);

	// Whether the machine sender ceremony is open for the focused domain
	// (specs/mailbox_machine_sender_card.md). Pure view state, like
	// $protect_setup: the ceremony itself holds nothing — mid-flight progress
	// is re-derived by probing the provider and DNS for mail.<domain>.
	$machine_setup = !empty($input['machine_setup']);

	$state_qs = function (array $over = array()) use ($base, $selected_alias_id, $selected_domain_id,
			$advanced, $protect_setup, $machine_setup) {
		$alias  = array_key_exists('alias_id', $over)  ? $over['alias_id']  : $selected_alias_id;
		$domain = array_key_exists('domain_id', $over) ? $over['domain_id'] : $selected_domain_id;
		$adv    = array_key_exists('advanced', $over)  ? $over['advanced']  : $advanced;
		$psetup = array_key_exists('protect_setup', $over) ? $over['protect_setup'] : $protect_setup;
		$msetup = array_key_exists('machine_setup', $over) ? $over['machine_setup'] : $machine_setup;
		$parts = array();
		if ($alias)       { $parts[] = 'alias_id=' . (int)$alias; }
		elseif ($domain)  { $parts[] = 'domain_id=' . (int)$domain; }
		if ($adv)         { $parts[] = 'advanced=1'; }
		if ($psetup)      { $parts[] = 'protect_setup=1'; }
		if ($msetup)      { $parts[] = 'machine_setup=1'; }
		return $base . ($parts ? '?' . implode('&', $parts) : '');
	};
	$redirect_url = $state_qs();

	// Relay section actions (lifecycle, provisioning, hosted-slot enrollment)
	// post back to this tab. Must run before the mail-hostname save below —
	// the provision form also carries a mail_hostname field.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));
	$relay_redirect = admin_mailbox_relay_tenant_actions($input, $session, $state_qs());
	if ($relay_redirect !== null) {
		return $relay_redirect;
	}

	// Outbound send protection state transitions. This tab is the surface that
	// drives them — there is no separate ceremony page — so every protect_*
	// action lands here and redirects back to the focused domain.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));
	$protect_redirect = mailbox_protect_handle_action($input, $session, $state_qs());
	if ($protect_redirect !== null) {
		return $protect_redirect;
	}

	// DNS publish actions (specs/dns_record_management.md). Handled before the
	// setting saves below so a publish never also writes the hostname form.
	require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
	$dns_redirect = DnsPublishBox::handle($input, function () use ($selected_alias_id, $selected_domain_id,
			$protect_setup, $machine_setup) {
		// Inside the ceremony the records to publish are the SIGNING-STAGE ones —
		// the same set its publish box rendered. Apply resolves the plan again
		// rather than trusting the POST, so this branch has to agree with what was
		// on screen; resolving the finished shape here would write `v=spf1 -all`
		// and `p=reject` on a domain whose sealed key is still signing nothing,
		// rejecting its own mail, from a button that showed neither record.
		//
		// Those two come afterwards, from the ordinary plan below, once the flag
		// is on and protectedShapeApplies() prescribes them.
		if ($protect_setup && $selected_domain_id) {
			return _setup_dns_plan_for_domain($selected_domain_id, true);
		}
		return $selected_alias_id
			? _setup_dns_plan_for_alias($selected_alias_id, $machine_setup)
			: _setup_dns_plan_for_domain($selected_domain_id, false, $machine_setup);
	}, $state_qs());
	if ($dns_redirect !== null) {
		return $dns_redirect;
	}

	$announce = function ($msg, $title) use ($session) {
		$session->save_message(new DisplayMessage(
			$msg, $title, '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	};

	// --- POST action: save mail hostname / public IP ---
	if (isset($input['mail_hostname']) || isset($input['public_ip'])) {
		mailbox_setup_write_setting('mailbox_mail_hostname', strtolower(trim($input['mail_hostname'] ?? '')));
		mailbox_setup_write_setting('mailbox_public_ip', trim($input['public_ip'] ?? ''));
		$announce('Setup details saved.', 'Saved');
		return LogicResult::redirect($state_qs(array('advanced' => true)));
	}

	// --- POST action: one-click fixes ---
	if (isset($input['action'])) {
		if ($input['action'] === 'enable_plugin') {
			mailbox_setup_write_setting('mailbox_enabled', '1');
			$announce('Inbound email is now enabled.', 'Enabled');
			return LogicResult::redirect($redirect_url);
		}

		if ($input['action'] === 'enable_srs') {
			mailbox_setup_write_setting('mailbox_srs_enabled', '1');
			// Generate a signing secret only if one is not already set, so
			// re-running the fix never rotates a live secret.
			if (trim((string)$settings->get_setting('mailbox_srs_secret')) === '') {
				mailbox_setup_write_setting('mailbox_srs_secret', bin2hex(random_bytes(24)));
			}
			$announce('SRS is now enabled with a signing secret.', 'Enabled');
			return LogicResult::redirect($redirect_url);
		}

		if ($input['action'] === 'add_domain') {
			$domain_name = strtolower(trim($input['domain'] ?? ''));
			if ($domain_name !== '') {
				try {
					if (!InboundEmailDomain::GetByDomain($domain_name)) {
						$domain = new InboundEmailDomain(NULL);
						$domain->set('ied_domain', $domain_name);
						$domain->set('ied_is_enabled', true);
						$domain->set('ied_reject_unmatched', true);
						$domain->prepare();
						$domain->save();
						// A fleet-fronted deployment files the domain's ownership
						// challenge at registration, so the Setup tab's ownership
						// row carries a publishable record immediately.
						require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
						(new FleetClient())->fileDomainClaims($domain_name);
					}
					$announce('Domain "' . $domain_name . '" registered.', 'Domain added');
				} catch (InboundEmailDomainException $e) {
					$announce('Could not add domain: ' . $e->getMessage(), 'Error');
				}
			}
			// Land on the publish box with the diff already on screen: adding a
			// domain should leave exactly the decisions that deserve a human,
			// not a checklist to go and find.
			return LogicResult::redirect(DnsPublishBox::urlWith($redirect_url, array('dns_show' => '1')));
		}

		// --- Machine sender ceremony (specs/mailbox_machine_sender_card.md) ---

		// Step 1: register mail.<domain> at the outbound provider. Idempotent —
		// re-pressing on a registered domain announces success and changes
		// nothing. DKIM authority stays on the subdomain itself (the provider
		// implementation guarantees it) so its keys align strictly.
		if ($input['action'] === 'machine_register') {
			$machine_domain = strtolower(trim($input['domain'] ?? ''));
			$class = EmailSender::activeServiceKey() !== ''
				? (EmailSender::getDiscoveredProviders()[EmailSender::activeServiceKey()] ?? null) : null;
			if ($machine_domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $machine_domain)) {
				$announce('No valid machine domain to register.', 'Error');
			} elseif ($class === null
					|| !in_array('SendingDomainRegistrar', class_implements($class) ?: array(), true)) {
				$announce('The configured outbound provider cannot register sending domains from here — '
					. 'add ' . $machine_domain . ' in its dashboard instead.', 'Not available');
			} else {
				$result = $class::createSendingDomain($machine_domain);
				if (($result['status'] ?? '') === 'ok') {
					$announce($machine_domain . ' is registered at ' . $class::getLabel()
						. '. Publish its DNS records next.', 'Registered');
				} else {
					$announce('Could not register ' . $machine_domain . ' at ' . $class::getLabel() . ': '
						. ($result['error'] ?? $result['status'] ?? 'unknown error'), 'Error');
				}
			}
			return LogicResult::redirect($redirect_url);
		}

		// Step 3: switch system mail to the machine identity. Offered by the
		// view only once steps 1–2 verify, and re-checked here — a stale tab
		// must not flip system mail onto an identity that stopped verifying.
		if ($input['action'] === 'machine_switch') {
			$parent = strtolower(trim($input['domain'] ?? ''));
			$local  = strtolower(trim($input['machine_local'] ?? ''));
			$replyto = trim($input['machine_replyto'] ?? '');
			$machine_domain = 'mail.' . $parent;
			if ($parent === '' || !preg_match('/^[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?$/', $local)) {
				$announce('Enter a valid address name (letters, digits, dots, dashes).', 'Error');
				return LogicResult::redirect($redirect_url);
			}
			if ($replyto !== '' && !filter_var($replyto, FILTER_VALIDATE_EMAIL)) {
				$announce($replyto . ' is not a valid Reply-To address.', 'Error');
				return LogicResult::redirect($redirect_url);
			}
			$readiness = (new InboundEmailSetupCheck())->machineSenderReadiness($parent);
			foreach ($readiness as $row) {
				if (!in_array($row['status'], array(InboundEmailSetupCheck::PASS, InboundEmailSetupCheck::INFO), true)) {
					$announce('Not yet: ' . $row['label'] . ' has not verified (' . $row['summary'] . ') '
						. '— flipping system mail now would move it onto an identity that cannot deliver.', 'Not yet');
					return LogicResult::redirect($redirect_url);
				}
			}
			mailbox_setup_write_setting('defaultemail', $local . '@' . $machine_domain);
			// Saved as submitted, including cleared: an empty Reply-To is a
			// deliberate act here, not an oversight (owner decision 2026-08-08).
			mailbox_setup_write_setting('defaultreplyto', $replyto);
			$announce('System mail now sends as ' . $local . '@' . $machine_domain
				. ($replyto !== '' ? ', with replies going to ' . $replyto : '') . '.', 'Switched');
			// The ceremony is complete — drop its flag so the page renders the
			// finished (on) state rather than the setup steps.
			return LogicResult::redirect($state_qs(array('machine_setup' => false)));
		}

		// Prove the whole ambient path — guard, provider, alignment — with one
		// real send to the operator. The one sub-check that cannot be inferred.
		if ($input['action'] === 'machine_test_send') {
			require_once(PathHelper::getIncludePath('data/users_class.php'));
			$acting = new User((int)$session->get_user_id(), TRUE);
			$to = $acting->key ? trim((string)$acting->get('usr_email')) : '';
			if ((string)$settings->get_setting('email_dry_run') === '1') {
				$announce('Email dry run is on — a test send would be suppressed, which proves nothing. '
					. 'Turn email_dry_run off first.', 'Dry run is on');
				return LogicResult::redirect($redirect_url);
			}
			if ($to === '') {
				$announce('Your account has no email address to send the test to.', 'Error');
				return LogicResult::redirect($redirect_url);
			}
			try {
				$ok = EmailSender::quickSend($to, 'Test: automated mail from this site',
					'This is a test of the site\'s automated mail identity, sent from the Setup tab. '
					. 'Check the From address and the authentication results (SPF, DKIM, DMARC) in the '
					. 'message headers.');
				$announce($ok
					? 'Test email sent to ' . $to . '. Check its headers for SPF/DKIM/DMARC results.'
					: 'The test email could not be sent — it was queued for retry. Check the Sending route '
						. 'row under Advanced.', $ok ? 'Sent' : 'Queued');
			} catch (Exception $e) {
				$announce('The test send was refused: ' . $e->getMessage(), 'Refused');
			}
			return LogicResult::redirect($redirect_url);
		}

		// Destroy the ordinary on-disk signing key for a protected domain. Never
		// automatic and never a side effect of anything else: this deletes key
		// material and cannot be undone from a browser. The gates are the two
		// facts that make it safe — sending is already locked to the sealed key,
		// and the DNS shape that makes that stick is published and verified.
		if ($input['action'] === 'destroy_local_key') {
			$domain_name = strtolower(trim($input['domain'] ?? ''));
			// GetByDomain() returns FALSE on a miss, never null.
			$model = $domain_name !== '' ? InboundEmailDomain::GetByDomain($domain_name) : false;
			if (!$model) {
				$announce('No such domain on this server.', 'Error');
				return LogicResult::redirect($redirect_url);
			}
			if (!$model->is_protected_identity()) {
				$announce('Send protection is not on for ' . $domain_name . '. Until it is, this key is what '
					. 'signs the domain\'s mail — destroying it would leave the domain signing nothing.', 'Not yet');
				return LogicResult::redirect($redirect_url);
			}
			$shape = (new InboundEmailSetupCheck())->protectedDomainChecks($model);
			foreach ($shape as $row) {
				if ($row['severity'] === InboundEmailSetupCheck::REQUIRED
						&& $row['status'] !== InboundEmailSetupCheck::PASS) {
					$announce('The protected DNS records for ' . $domain_name . ' are not all verified yet ('
						. $row['label'] . '). Destroying the on-disk key now could leave mail unsigned on a path '
						. 'that is still in use.', 'Not yet');
					return LogicResult::redirect($redirect_url);
				}
			}
			$result = mailbox_destroy_local_signing_key($domain_name);
			$announce($result['message'], $result['ok'] ? 'Destroyed' : 'Error');
			return LogicResult::redirect($redirect_url);
		}
	}

	// --- Picker: every enabled mailbox, plus any domain with none yet ---
	$mailbox_options = array();   // alias_id  => display label
	$mailbox_index   = array();   // alias_id  => meta about the mailbox
	$domain_options  = array();   // domain_id => display label
	$domains = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
	$domains->load();
	foreach ($domains as $domain) {
		$is_imap = (bool)$domain->get('ied_is_imap_source');
		$aliases = new MultiInboundEmailAlias(
			array('domain_id' => $domain->key, 'deleted' => false, 'enabled' => true),
			array('iea_alias' => 'ASC')
		);
		$aliases->load();
		foreach ($aliases as $alias) {
			$mode    = $alias->get('iea_delivery_mode') ?: InboundEmailAlias::MODE_FORWARD;
			$address = strtolower($alias->get('iea_alias') . '@' . $domain->get('ied_domain'));
			$tag = ($mode === InboundEmailAlias::MODE_STORE) ? 'Mailbox'
				: (($mode === InboundEmailAlias::MODE_FORWARD_AND_STORE) ? 'Forward + store' : 'Forward');
			if ($is_imap) { $tag .= ' · IMAP'; }
			$mailbox_options[$alias->key] = $address . '  —  ' . $tag;
			$mailbox_index[$alias->key] = array(
				'address'   => $address,
				'domain'    => strtolower($domain->get('ied_domain')),
				'is_imap'   => $is_imap,
				'mode'      => $mode,
				'forwards'  => in_array($mode, array(InboundEmailAlias::MODE_FORWARD, InboundEmailAlias::MODE_FORWARD_AND_STORE), true),
			);
		}
		// Only while it has no mailbox: once one exists the mailbox entry reaches
		// the same domain-level guidance, and two ways in would just be two
		// answers to the same question. An IMAP source has no DNS shape to set
		// up here at all.
		if (!$is_imap && !count($aliases)) {
			$domain_options[$domain->key] = strtolower($domain->get('ied_domain')) . '  —  Domain, no mailbox yet';
		}
	}

	// Nothing is chosen by default. Every check below costs DNS lookups and
	// host probes, and running a full suite against an arbitrary first mailbox
	// is work nobody asked for — the page waits to be told what to check.
	// An id that no longer resolves falls back to unchosen rather than silently
	// checking something else.
	if (!isset($mailbox_options[$selected_alias_id])) {
		$selected_alias_id = 0;
	}
	if (!isset($domain_options[$selected_domain_id])) {
		// The picker lists only mailbox-less domains, but the machine sender
		// ceremony legitimately focuses ANY registered domain — its card links
		// here with domain_id, and dropping the focus would dump the operator
		// on the picker with no way back in. Keep it when the domain resolves.
		$keep = false;
		if ($machine_setup && $selected_domain_id > 0) {
			$dm = new InboundEmailDomain($selected_domain_id, TRUE);
			$keep = (bool)$dm->key && !$dm->get('ied_is_imap_source');
		}
		if (!$keep) {
			$selected_domain_id = 0;
		}
	}

	// One URL for every control on this page to post back to. The focus lives in
	// the query string, so a form or button that posts to the bare path throws it
	// away and the redirect lands the operator back on the picker. Rebuilt rather
	// than reusing $state_qs so it reflects the ids after the check above dropped
	// any that no longer resolve.
	$self_parts = array();
	if ($selected_alias_id)      { $self_parts[] = 'alias_id=' . (int)$selected_alias_id; }
	elseif ($selected_domain_id) { $self_parts[] = 'domain_id=' . (int)$selected_domain_id; }
	if ($advanced)               { $self_parts[] = 'advanced=1'; }
	$self_url = $base . ($self_parts ? '?' . implode('&', $self_parts) : '');

	// One dropdown, two kinds of entry. Mailboxes first: they are the common
	// case, and a domain without one is a setup state on its way to becoming a
	// mailbox.
	$focus_options = array();
	foreach ($mailbox_options as $id => $label) { $focus_options['a' . $id] = $label; }
	foreach ($domain_options as $id => $label)  { $focus_options['d' . $id] = $label; }
	$focus_value = $selected_alias_id ? 'a' . $selected_alias_id
		: ($selected_domain_id ? 'd' . $selected_domain_id : '');

	// The inbound provider is NOT resolved here any more. Choosing it moved to the
	// Settings tab, and nothing on this page needed the answer: how mail arrives
	// for the focused mailbox comes back as $arrival from mailbox_setup_scope.php,
	// which asks the registry itself.

	// --- Scoped checks for the chosen mailbox ---
	// The grouping lives in mailbox_setup_scope.php, so the reader's setup
	// banner grades exactly the rows this page renders.
	$selected        = $selected_alias_id > 0;
	$domain_selected = $selected_domain_id > 0;
	$address        = '';
	$focus_domain   = '';
	$arrival        = '';          // 'imap' | 'webhook' | 'postfix'
	$forwards       = false;
	$mode           = '';
	$receiving_rows = array();
	$forwarding_rows = array();
	$selected_imap  = null;

	if ($selected) {
		$scoped = mailbox_setup_scoped_rows($selected_alias_id,
			$state_qs(array('advanced' => true)) . '#relay-section');
		if ($scoped !== null) {
			$address         = $scoped['address'];
			$focus_domain    = $scoped['domain'];
			$mode            = $scoped['mode'];
			$forwards        = $scoped['forwards'];
			$arrival         = $scoped['arrival'];
			$selected_imap   = $scoped['imap'];
			$receiving_rows  = $scoped['receiving'];
			$forwarding_rows = $scoped['forwarding'];
			// The checks just ran for real, so stamp the verdict the reader's
			// banner reads. Fixing a record here and going back to the mailbox
			// shows the fix immediately instead of waiting out a cache.
			mailbox_setup_status_remember($selected_alias_id, mailbox_setup_verdict($scoped));
		}
	}

	// --- Level-guided setup (specs/mailbox_security_levels.md § Phase 3) ---
	// The chosen level drives the next steps shown below the checks: Private adds
	// the one-time vault ceremony; Fortress adds the protect ceremony, the relay,
	// and the session-gated-send confirmation. Reuse the built flows — link, never
	// reimplement.
	// A directly focused domain has no mailbox to resolve through, so name it
	// here. Everything downstream — the guided box, the DNS plan, the Advanced
	// health run — keys on $focus_domain and does not care which way it arrived.
	if ($domain_selected) {
		$domain_model = new InboundEmailDomain($selected_domain_id, TRUE);
		if ($domain_model->key) {
			$focus_domain = strtolower(trim((string)$domain_model->get('ied_domain')));
		}
	}

	// Whether the focused domain already has mailboxes — the "add a mailbox"
	// box must not claim there is none when the ceremony focused a domain the
	// picker would not have offered.
	$focus_domain_has_mailboxes = false;
	if ($focus_domain !== '') {
		foreach ($mailbox_index as $mi) {
			if ($mi['domain'] === $focus_domain) { $focus_domain_has_mailboxes = true; break; }
		}
	}

	$focus_domain_model = null;
	$security_level     = InboundEmailDomain::LEVEL_STANDARD;
	$focus_domain_id    = 0;
	$focus_is_protected = false;
	$acting_has_vault   = false;
	$protect            = null;   // protection state, for the guided box + Advanced
	if ($arrival !== 'imap' && $focus_domain !== '') {
		$focus_domain_model = InboundEmailDomain::GetByDomain($focus_domain);
		$uid = (int)$session->get_user_id();
		if ($focus_domain_model) {
			$security_level     = $focus_domain_model->security_level();
			$focus_domain_id    = (int)$focus_domain_model->key;
			$focus_is_protected = $focus_domain_model->is_protected_identity();
			if ($security_level === InboundEmailDomain::LEVEL_FORTRESS) {
				$protect = mailbox_protect_state($focus_domain_model, $uid);
			}
		}
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		$acting_has_vault = $uid > 0 && (UserEncryptionVault::loadForUser($uid) !== null);
	}

	// A focused domain has no mailbox to scope per-address checks to, but the
	// domain-level DNS is exactly what its setup is about — and for a Fortress
	// domain those rows ARE the protected-shape verification
	// (InboundEmailSetupCheck::protectedShapeResults, reached from checkDomain).
	// Without them a domain focus would show records to publish and no way to
	// see whether publishing worked.
	$domain_rows = array();
	if ($domain_selected && $focus_domain !== '') {
		$domain_rows = (new InboundEmailSetupCheck())->runDomainChecks($focus_domain);
	}

	// --- Advanced (server-wide) — only run the full suite when expanded ---
	$results     = array();
	$checker     = new InboundEmailSetupCheck();
	if ($advanced) {
		$results = $checker->run($focus_domain !== '' ? $focus_domain : null, $address !== '' ? $address : null);
	}
	// The provider's own getDnsRecords() is NOT read here. It is a fixed list that
	// reads neither the receive topology nor the security level, so on a
	// relay-fronted or protected domain it prescribes records that would undo both.
	// dnsPlan() is the single source of what to publish
	// (specs/implemented/mailbox_relay_surface_simplification.md, docs/dns_management.md).

	// The Relay section renders whenever the deployment's receive mode is
	// relay, or a relay row exists whatever the stored choice says.
	// Also assembled whenever Advanced is open, so the relay cards' "Go to relay
	// setup" always lands on something. A deployment that chose direct receive
	// and has no relay would otherwise be invited to set one up and find no form.
	$relay_section = null;
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/listener_admin.php'));
	if ($advanced || mailbox_receive_mode() === 'relay' || mailbox_receive_relay_exists()
			|| mailbox_listener_setting() === 'decommissioned') {
		$relay_section = admin_mailbox_relay_tenant_vars();
	}

	// The DNS publish box: the plan for the focused domain, plus whatever state
	// the box is in. Costs one plan build and (only when the diff is on screen)
	// a handful of credential-free public DNS lookups.
	// The box's forms post back to a URL carrying the RESOLVED mailbox, not the
	// one the request arrived with — otherwise a page loaded without ?alias_id
	// would post back without it and the handler would have no domain to plan for.
	$dns_box = DnsPublishBox::build(
		($focus_domain !== '' && $arrival !== 'imap')
			? $checker->dnsPlan($focus_domain, false, $machine_setup) : null,
		$input,
		$machine_setup ? $state_qs() : $self_url
	);

	// Where the publish box belongs: up front while there is DNS to fix, behind
	// Advanced once there is not. A domain whose records are all correct should
	// not be led with an invitation to configure them — the page already knows
	// they are correct, because the checks it just rendered say so. Asking for
	// the diff explicitly always keeps the box in view, so pressing the button
	// never makes it jump somewhere else. The machine sender ceremony keeps it
	// in view too — its step 2 IS the publish box.
	$dns_box_in_advanced = !_setup_has_dns_work($receiving_rows, $forwarding_rows, $domain_rows)
		&& empty($input['dns_show']) && !$machine_setup;

	// --- The machine sender ceremony, when the operator has opened it ----------
	// The subdomain is fixed to mail.<domain> — the doctrine's conventional
	// choice — which is what lets the ceremony hold no state: after any reload,
	// mid-flight progress is re-derived by probing the provider and DNS.
	$machine_domain           = '';
	$machine_on               = false;
	$machine_readiness        = array();
	$machine_ready            = false;
	$machine_can_register     = false;
	$machine_provider_label   = '';
	$machine_replyto_prefill  = '';
	if ($machine_setup && $focus_domain !== '' && $arrival !== 'imap') {
		$machine_on     = $checker->machineSenderDomainFor($focus_domain) !== '';
		$machine_domain = $machine_on
			? $checker->machineSenderDomainFor($focus_domain) : 'mail.' . $focus_domain;
		if (!$machine_on) {
			$machine_readiness = $checker->machineSenderReadiness($focus_domain);
			// Ready for the flip when nothing is failing or unresolved. INFO
			// counts as ready: it is the non-verifiable-provider case, where
			// the operator vouched for the dashboard side themselves.
			$machine_ready = true;
			foreach ($machine_readiness as $row) {
				if (!in_array($row['status'],
						array(InboundEmailSetupCheck::PASS, InboundEmailSetupCheck::INFO), true)) {
					$machine_ready = false;
					break;
				}
			}
			$active_key = EmailSender::activeServiceKey();
			$active_class = $active_key !== ''
				? (EmailSender::getDiscoveredProviders()[$active_key] ?? null) : null;
			if ($active_class !== null) {
				$machine_provider_label = $active_class::getLabel();
				$machine_can_register = in_array('SendingDomainRegistrar',
					class_implements($active_class) ?: array(), true);
			}
			// Reply-To prefill: the domain's primary mailbox — the first stored
			// mailbox on the focused domain (owner decision 2026-08-08: prefilled
			// and saved unless cleared, so replies work by default).
			if ($focus_domain_id > 0) {
				$stored = new MultiInboundEmailAlias(
					array('domain_id' => $focus_domain_id, 'deleted' => false, 'enabled' => true),
					array('iea_alias' => 'ASC'));
				$stored->load();
				foreach ($stored as $alias) {
					$alias_mode = $alias->get('iea_delivery_mode') ?: InboundEmailAlias::MODE_FORWARD;
					if (in_array($alias_mode,
							array(InboundEmailAlias::MODE_STORE, InboundEmailAlias::MODE_FORWARD_AND_STORE), true)) {
						$machine_replyto_prefill = strtolower($alias->get('iea_alias') . '@' . $focus_domain);
						break;
					}
				}
			}
		}
	}

	// --- The send-protection ceremony, when the operator has opened it ---------
	// Everything here costs DNS lookups, so none of it runs for the ordinary
	// case: a Fortress domain resting with send protection off pays nothing and
	// is told nothing.
	$protect_preflight     = array();
	$protect_dns_box       = null;
	$protect_signing_ready = false;
	$protect_vault_unlocked = false;
	if ($protect_setup && $protect !== null && $focus_domain_model !== null
			&& !$focus_is_protected && !empty($protect['has_key'])) {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		$protect_vault_unlocked = VaultUnlock::isOpen((int)$session->get_user_id());
		// THE PAGE SHOWS WHAT THE BUTTON CHECKS, and nothing more. Rendering the
		// finished shape here told the operator to strip the provider from SPF
		// and publish `v=spf1 -all` while the sealed key was still signing
		// nothing — recreating, by instruction, the outage the ordering exists to
		// prevent (specs/mailbox_fortress_send_protection_completion.md). The
		// activate guard has always used the smaller set; only the page around it
		// disagreed, and a red card next to a button that would have passed reads
		// as a blocker either way.
		//
		// The strict SPF and DMARC are not skipped, only deferred: once the flag
		// is on, the page's ordinary publish box prescribes them, and
		// domain.send_protection keeps asking until they are live.
		$protect_preflight = $checker->signingReadinessChecks($focus_domain_model);

		// AN OFFER TO CONFIGURE DNS THAT HAS NOTHING TO CONFIGURE IS NOISE, and
		// worse than noise here: the publish box does not read live DNS until the
		// operator presses its button, so on a domain whose records are already
		// live it renders a promise ("shows exactly what would change") above
		// nothing at all, and the only way to learn there is nothing to do is to
		// press it. The answer is already on this page — the readiness checks
		// immediately below were just computed — so it is used instead of asking
		// the operator to go and find it.
		//
		// This mirrors what the page already does with its own publish box, which
		// moves to Advanced once no check carries a record to fix: a finished
		// domain is not led with an offer to configure it. A record that later
		// drifts fails its check again and brings the box straight back.
		$protect_signing_ready = _setup_rows_all_pass($protect_preflight);
		if (!$protect_signing_ready) {
			$protect_dns_box = DnsPublishBox::build(
				$checker->signingReadinessPlan($focus_domain),
				$input,
				$state_qs()
			);
		}
	}

	return LogicResult::render(array(
		'dns_box_in_advanced'        => $dns_box_in_advanced,
		'session'                    => $session,
		'settings'                   => $settings,
		'base'                       => $base,
		'self_url'                   => $self_url,
		'dns_box'                    => $dns_box,
		'relay_section'              => $relay_section,
		// Mailbox-first view, with a domain-only fallback for a domain that has
		// no mailbox yet.
		'mailbox_options'            => $mailbox_options,
		'domain_options'             => $domain_options,
		'focus_options'              => $focus_options,
		'focus_value'                => $focus_value,
		'selected_alias_id'          => $selected_alias_id,
		'selected_domain_id'         => $selected_domain_id,
		'selected'                   => $selected,
		'domain_selected'            => $domain_selected,
		'address'                    => $address,
		'focus_domain'               => $focus_domain,
		'arrival'                    => $arrival,
		'mode'                       => $mode,
		'forwards'                   => $forwards,
		'receiving_rows'             => $receiving_rows,
		'forwarding_rows'            => $forwarding_rows,
		'selected_imap'              => $selected_imap,
		// Level-guided setup (Phase 3)
		'focus_domain_has_mailboxes' => $focus_domain_has_mailboxes,
		'security_level'             => $security_level,
		'focus_domain_id'            => $focus_domain_id,
		'focus_is_protected'         => $focus_is_protected,
		'acting_has_vault'           => $acting_has_vault,
		'protect'                    => $protect,
		'protect_setup'              => $protect_setup,
		'protect_preflight'          => $protect_preflight,
		'protect_dns_box'            => $protect_dns_box,
		'protect_signing_ready'      => $protect_signing_ready,
		'protect_vault_unlocked'     => $protect_vault_unlocked,
		// Machine sender ceremony (specs/mailbox_machine_sender_card.md)
		'machine_setup'              => $machine_setup,
		'machine_domain'             => $machine_domain,
		'machine_on'                 => $machine_on,
		'machine_readiness'          => $machine_readiness,
		'machine_ready'              => $machine_ready,
		'machine_can_register'       => $machine_can_register,
		'machine_provider_label'     => $machine_provider_label,
		'machine_replyto_prefill'    => $machine_replyto_prefill,
		'domain_rows'                => $domain_rows,
		// Advanced
		'advanced'                   => $advanced,
		'results'                    => $results,
		'mail_hostname'              => $checker->getMailHostname(),
		'public_ip'                  => $checker->getPublicIp(),
		'public_ip_private'          => $checker->publicIpIsPrivate(),
		'configured_public_ip'       => trim((string)$settings->get_setting('mailbox_public_ip')),
	));
}

/**
 * Does any rendered check still want a DNS record published?
 *
 * The check rows carry the prescription itself (`fix.dns_record`), and a passing
 * row carries none — so "is there DNS work" is answerable from what the page
 * already computed, with no extra lookups and no second opinion that could
 * disagree with the checks the operator is looking at.
 *
 * @param array[] ...$groups Row lists.
 */
/**
 * Is every REQUIRED row in this set passing?
 *
 * UNKNOWN IS NOT PASS. A row that could not be resolved — a DNS lookup that
 * failed, an API that did not answer — is an open question, and treating it as
 * settled would hide the one control that could close it.
 */
function _setup_rows_all_pass(array $rows): bool {
	foreach ($rows as $r) {
		if (($r['severity'] ?? '') !== InboundEmailSetupCheck::REQUIRED) {
			continue;
		}
		if (($r['status'] ?? '') !== InboundEmailSetupCheck::PASS) {
			return false;
		}
	}
	return true;
}

function _setup_has_dns_work(array ...$groups): bool {
	foreach ($groups as $rows) {
		foreach ($rows as $r) {
			if (!empty($r['fix']['dns_record'])) {
				return true;
			}
		}
	}
	return false;
}

/**
 * The DNS plan for the domain behind one mailbox, for the publish box's action
 * handler — which runs before the page has resolved anything and must not pay
 * for the whole mailbox index to find one domain.
 */
function _setup_dns_plan_for_alias(int $alias_id, bool $machine_stage = false): ?DnsRecordPlan {
	if ($alias_id <= 0) {
		return null;
	}
	$alias = new InboundEmailAlias($alias_id, TRUE);
	if (!$alias->key) {
		return null;
	}
	$domain = new InboundEmailDomain($alias->get('iea_ied_inbound_email_domain_id'), TRUE);
	$name = strtolower(trim((string)$domain->get('ied_domain')));
	if ($name === '' || $domain->get('ied_is_imap_source')) {
		return null;   // an IMAP-pull mailbox has no MX/host stack to publish
	}
	return (new InboundEmailSetupCheck())->dnsPlan($name, false, $machine_stage);
}

/**
 * The DNS plan for a directly focused domain — the same contract as
 * _setup_dns_plan_for_alias(), for a domain that has no mailbox to reach it
 * through.
 *
 * $signing_stage asks for the records that let the sealed key START signing —
 * the sealed DKIM record and the forwarding subdomain, and nothing that rejects
 * anything. Only the ceremony passes it, and it must resolve the SAME plan the
 * ceremony's publish box rendered: the box is the operator's consent, so a
 * handler that rebuilt a different plan would write records nobody had seen,
 * which is precisely what the publish rail exists to prevent.
 */
function _setup_dns_plan_for_domain(int $domain_id, bool $signing_stage = false,
		bool $machine_stage = false): ?DnsRecordPlan {
	if ($domain_id <= 0) {
		return null;
	}
	$domain = new InboundEmailDomain($domain_id, TRUE);
	$name = strtolower(trim((string)$domain->get('ied_domain')));
	if (!$domain->key || $name === '' || $domain->get('ied_is_imap_source')) {
		return null;
	}
	$checker = new InboundEmailSetupCheck();
	return $signing_stage ? $checker->signingReadinessPlan($name)
		: $checker->dnsPlan($name, false, $machine_stage);
}

/**
 * Upsert a single stg_settings row by name. Settings are written through the
 * Setting model (there is no set_setting()); a missing row is created.
 */
function mailbox_setup_write_setting(string $name, string $value): void {
	$existing = new MultiSetting(array('setting_name' => $name));
	$existing->load();
	if (count($existing)) {
		$setting = $existing->get(0);
	} else {
		$setting = new Setting(NULL);
		$setting->set('stg_name', $name);
	}
	$setting->set('stg_value', $value);
	$setting->save();
}
?>
