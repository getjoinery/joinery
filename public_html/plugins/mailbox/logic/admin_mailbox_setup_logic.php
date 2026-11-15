<?php
/**
 * Logic for the Inbound Email Setup tab.
 *
 * The page is mailbox-first: an admin picks one of the mailboxes already
 * registered on the Accounts tab, and the checks are scoped to that mailbox —
 * a "Receiving" group always, plus a "Forwarding" group when the mailbox
 * forwards or a "Sending" group (relay, DKIM) when it stores only. Everything
 * that belongs to the whole server rather than one mailbox — the relay, this
 * server's mail hostname/IP, the full health run — is on the Server tab
 * (admin_mailbox_server_logic.php).
 *
 * A domain with no mailbox yet can be focused directly (?domain_id=). Its setup
 * is domain-level — the vault, outbound protection, the relay, the DNS shape —
 * so it renders the guided steps, the publish box and runDomainChecks(), and no
 * per-mailbox checks. For a domain with the sending lock those domain checks ARE the
 * protected-shape verification, so publishing can prove itself with no mailbox.
 *
 * Outbound send protection has no page of its own: mailbox_protect_handle_action()
 * runs every protect_* transition here and redirects back to the focused domain.
 *
 * POST actions: the send-protection ceremony, DNS publishing, the machine
 * sender switch, and the one-click fixes a check row carries
 * (setup_fix_actions.php) — each writes through a model and redirects so the
 * next render reads fresh settings.
 *
 * @version 2.26 - without a press, the records come as one ordered list that rechecks itself (protect_record_list);
 *   after the flag, while the ceremony is open, the same list with group 2 active
 * @version 2.25 - the press box is offered while the vault is locked; its button waits behind an in-place unlock
 * @version 2.24 - one press turns send protection on where the DNS host takes an API key (protect_press_box)
 * @version 2.23 - a domain_id focus is kept for any registered domain, not only the machine sender's:
 *   the editor's send-lock save and Finish send protection landed on an empty picker
 * @version 2.22 - the DNS Apply handler resolves the plan of the box pressed (dns_plan=signing for the
 *   send-protection step), so a mailbox-focused page publishes the signing records it showed
 * @version 2.21 - server-wide work moved to the Server tab: no Advanced state, no relay
 *   actions, no hostname save, no full health run; the one-click fixes are shared
 * @version 2.20 - its actions run only on a POST: a GET carrying action= changed the relay, domains or keys
 * @version 2.19 - the relay-or-direct choice card is gone (Enable/Disable relay decide it), so no gate to handle
 * @version 2.18 - the Sending identity box and guided steps follow the add-ons
 *   (focus_relay_on, focus_send_requested)
 * @version 2.17 - wizard_provision is gone: the setup wizard's Email step
 *   provisions the mailbox itself (setup_logic) and posts nothing here.
 * @version 2.16 - wizard_provision: the setup wizard's one-go apply
 *   (specs/setup_wizard.md § Step 4) — provision + optional additive publish.
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
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_accounts_class.php'));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	$base = '/plugins/mailbox/admin/admin_mailbox_setup';

	// The page focuses either a mailbox or a bare domain. A domain is registered
	// before any mailbox exists on it, and a Private domain's setup — vault,
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
	// ceremony state, so a POST action returns the operator to exactly where they
	// were. Declared before every action handler below, all of which redirect
	// through it, and handed to the view as self_url: a form that posts anywhere
	// but here drops the focus and dumps the operator back on the picker.
	// Whether the operator has explicitly opened the send-protection ceremony
	// for the focused domain. Pure view state, never stored: a domain that
	// asked for the sending lock already HAS a sealed key (switching it on seals
	// one). Only this flag distinguishes an operator who has opened the ceremony
	// from one who is simply looking at their domain — and nothing prescribes
	// the protected DNS shape until they have.
	$protect_setup = !empty($input['protect_setup']);

	// Whether the machine sender ceremony is open for the focused domain
	// (specs/mailbox_machine_sender_card.md). Pure view state, like
	// $protect_setup: the ceremony itself holds nothing — mid-flight progress
	// is re-derived by probing the provider and DNS for mail.<domain>.
	$machine_setup = !empty($input['machine_setup']);

	$state_qs = function (array $over = array()) use ($base, $selected_alias_id, $selected_domain_id,
			$protect_setup, $machine_setup) {
		$alias  = array_key_exists('alias_id', $over)  ? $over['alias_id']  : $selected_alias_id;
		$domain = array_key_exists('domain_id', $over) ? $over['domain_id'] : $selected_domain_id;
		$psetup = array_key_exists('protect_setup', $over) ? $over['protect_setup'] : $protect_setup;
		$msetup = array_key_exists('machine_setup', $over) ? $over['machine_setup'] : $machine_setup;
		$parts = array();
		if ($alias)       { $parts[] = 'alias_id=' . (int)$alias; }
		elseif ($domain)  { $parts[] = 'domain_id=' . (int)$domain; }
		if ($psetup)      { $parts[] = 'protect_setup=1'; }
		if ($msetup)      { $parts[] = 'machine_setup=1'; }
		return $base . ($parts ? '?' . implode('&', $parts) : '');
	};
	$redirect_url = $state_qs();

	// Every action below is a form submit (POST). The page merges the query
	// string into $input for its view state, so a GET must reach none of them:
	// a link could otherwise enable the relay or destroy a signing key.
	$posted = LibraryFunctions::isFormSubmission() ? $input : array();

	// Outbound send protection state transitions. This tab is the surface that
	// drives them — there is no separate ceremony page — so every protect_*
	// action lands here and redirects back to the focused domain.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));
	$protect_redirect = mailbox_protect_handle_action($posted, $session, $state_qs());
	if ($protect_redirect !== null) {
		return $protect_redirect;
	}

	// DNS publish actions (specs/dns_record_management.md). Handled before the
	// setting saves below so a publish never also writes the hostname form.
	require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));

	// The one-press send protection (specs/send_protection_one_press.md WP1):
	// its box's Apply is not a publish of one plan but the whole ceremony, so it
	// is taken here, before the ordinary box handler would publish it in one go.
	if (($posted['dns_action'] ?? '') === 'dns_apply' && ($posted['dns_plan'] ?? '') === 'press') {
		return _setup_protect_press($posted, $session,
			_setup_focus_domain_name($selected_alias_id, $selected_domain_id), $state_qs());
	}

	$dns_redirect = DnsPublishBox::handle($posted, function () use ($posted, $selected_alias_id, $selected_domain_id,
			$machine_setup) {
		// The records to publish are the ones the pressed box rendered. Apply
		// resolves the plan again rather than trusting the POST, so it has to know
		// WHICH box was pressed: the ceremony's box carries the SIGNING-STAGE plan
		// and says so in its post URL (dns_plan=signing). Resolving the finished
		// shape for it would write `v=spf1 -all` and `p=reject` on a domain whose
		// sealed key is still signing nothing, from a button that showed neither
		// record; resolving the ordinary plan publishes none of the records it
		// showed. Keyed on the box, not on the page's state, so a mailbox-focused
		// and a domain-focused page resolve the same thing.
		//
		// The strict SPF and DMARC come afterwards, from the ordinary plan, once
		// the flag is on and protectedShapeApplies() prescribes them.
		$signing = (($posted['dns_plan'] ?? '') === 'signing');
		if (($posted['dns_plan'] ?? '') === 'press') {
			$name = _setup_focus_domain_name($selected_alias_id, $selected_domain_id);
			return $name !== '' ? (new InboundEmailSetupCheck())->protectPressPlan($name) : null;
		}
		return $selected_alias_id
			? _setup_dns_plan_for_alias($selected_alias_id, $machine_setup, $signing)
			: _setup_dns_plan_for_domain($selected_domain_id, $signing, $machine_setup);
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

	// --- POST action: one-click fixes a check row carries ---
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/setup_fix_actions.php'));
	$fix_redirect = mailbox_setup_fix_action($posted, $session, $redirect_url);
	if ($fix_redirect !== null) {
		return $fix_redirect;
	}

	if (isset($posted['action'])) {
		// --- Machine sender ceremony (specs/mailbox_machine_sender_card.md) ---

		// Step 3: switch system mail to the machine identity. Offered by the
		// view only once steps 1–2 verify, and re-checked here — a stale tab
		// must not flip system mail onto an identity that stopped verifying.
		if ($posted['action'] === 'machine_switch') {
			$parent = strtolower(trim($posted['domain'] ?? ''));
			$local  = strtolower(trim($posted['machine_local'] ?? ''));
			$replyto = trim($posted['machine_replyto'] ?? '');
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
		// The picker lists only mailbox-less domains, but every domain-level
		// task focuses ANY registered domain by domain_id — the machine sender
		// card, the domain editor's send-lock save, the Finish send protection
		// link. Dropping the focus dumps the operator on the picker with no way
		// back in. Keep it whenever the domain resolves.
		$keep = false;
		if ($selected_domain_id > 0) {
			$dm = new InboundEmailDomain($selected_domain_id, TRUE);
			$keep = (bool)$dm->key && !$dm->get('ied_is_imap_source');
		}
		if (!$keep) {
			$selected_domain_id = 0;
		} else {
			// Listed so the picker shows what is focused, not "Choose one".
			$domain_options[$selected_domain_id] = strtolower((string)$dm->get('ied_domain')) . '  —  Domain';
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
		$scoped = mailbox_setup_scoped_rows($selected_alias_id);
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
	// the one-time vault ceremony; the Seal at the relay add-on adds the relay,
	// and the sending-lock add-on adds the protect ceremony. Reuse the built flows — link, never
	// reimplement.
	// A directly focused domain has no mailbox to resolve through, so name it
	// here. Everything downstream — the guided box, the DNS plan, the
	// sending-identity panel — keys on $focus_domain and does not care which way it arrived.
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
	$focus_relay_on     = false;
	$focus_send_requested = false;
	$acting_has_vault   = false;
	$protect            = null;   // protection state, for the guided box + sending identity
	if ($arrival !== 'imap' && $focus_domain !== '') {
		$focus_domain_model = InboundEmailDomain::GetByDomain($focus_domain);
		$uid = (int)$session->get_user_id();
		if ($focus_domain_model) {
			$security_level     = $focus_domain_model->security_level();
			$focus_domain_id    = (int)$focus_domain_model->key;
			$focus_is_protected = $focus_domain_model->is_protected_identity();
			$focus_relay_on       = $focus_domain_model->relay_seals_to_owner();
			$focus_send_requested = $focus_domain_model->send_lock_requested();
			// The Sending identity box exists once the sending lock has been asked
			// for (or is on); a domain that never asked has nothing to set up.
			if ($focus_send_requested || $focus_is_protected) {
				$protect = mailbox_protect_state($focus_domain_model, $uid);
			}
		}
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		$acting_has_vault = $uid > 0 && (UserEncryptionVault::loadForUser($uid) !== null);
	}

	// A focused domain has no mailbox to scope per-address checks to, but the
	// domain-level DNS is exactly what its setup is about — and for a domain
	// with the sending lock those rows ARE the protected-shape verification
	// (InboundEmailSetupCheck::protectedShapeResults, reached from checkDomain).
	// Without them a domain focus would show records to publish and no way to
	// see whether publishing worked.
	$domain_rows = array();
	if ($domain_selected && $focus_domain !== '') {
		$domain_rows = (new InboundEmailSetupCheck())->runDomainChecks($focus_domain);
	}

	$checker     = new InboundEmailSetupCheck();
	// The provider's own getDnsRecords() is NOT read here. It is a fixed list that
	// reads neither the receive topology nor the security level, so on a
	// relay-fronted or protected domain it prescribes records that would undo both.
	// dnsPlan() is the single source of what to publish
	// (specs/implemented/mailbox_relay_surface_simplification.md, docs/dns_management.md).

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

	// Where the publish box belongs: up front while there is DNS to fix, at the
	// foot of the page once there is not. A domain whose records are all correct
	// should not be led with an invitation to configure them — the page already knows
	// they are correct, because the checks it just rendered say so. Asking for
	// the diff explicitly always keeps the box in view, so pressing the button
	// never makes it jump somewhere else. The machine sender ceremony keeps it
	// in view too — its step 2 IS the publish box.
	$dns_box_last = !_setup_has_dns_work($receiving_rows, $forwarding_rows, $domain_rows)
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
	// case: a domain with send protection off and its ceremony closed pays
	// nothing and is told nothing.
	$protect_preflight     = array();
	$protect_dns_box       = null;
	$protect_press_box     = null;
	$protect_press_note    = '';
	// What the last press did, shown once on the page it lands on.
	$protect_press_result  = null;
	if ($focus_domain_id > 0 && isset($_SESSION['mailbox_protect_press'][$focus_domain_id])) {
		$protect_press_result = $_SESSION['mailbox_protect_press'][$focus_domain_id];
		unset($_SESSION['mailbox_protect_press'][$focus_domain_id]);
	}
	$protect_signing_ready = false;
	$protect_vault_unlocked = false;
	$protect_record_list   = null;
	// Signing is on and the records that reject forgeries are not all live
	// yet: the same list, group 2 now active, until they are. Only while the
	// ceremony is open (turning it on lands back here with protect_setup), so a
	// finished domain's ordinary view pays no lookups for it.
	if ($protect_setup && $protect !== null && $focus_domain_model !== null && $focus_is_protected) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));
		$after_flag = mailbox_protect_record_list($focus_domain_model, $checker);
		if (!$after_flag['strict_live']) {
			$protect_record_list = $after_flag;
		}
	}
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
		// moves to the foot of the page once no check carries a record to fix: a finished
		// domain is not led with an offer to configure it. A record that later
		// drifts fails its check again and brings the box straight back.
		$protect_signing_ready = _setup_rows_all_pass($protect_preflight);

		// ONE PRESS, where the DNS host takes an API key: the whole ceremony
		// behind one confirmation that lists every record it writes. The diff is
		// shown straight away; it is the consent. An OAuth host keeps the two
		// presses below (its callback applies a plan frozen at consent time, and
		// a consent round trip can outlast the vault's idle window), and so does
		// a host with no driver, which gets the records to copy.
		//
		// It is offered only where it can finish: the sealed key has to open for
		// the person pressing (only its owner's vault opens it), and the change
		// has to be one a single confirmation can cover — every record checked,
		// nothing removed, no mail redirected. Otherwise the two presses stay,
		// with their own confirmations, and the reason is said. A locked vault
		// does not take it away: its button waits behind an Unlock that opens the
		// vault in place (VaultGate), and the press checks the vault again.
		$protect_press_note = '';
		$press = DnsPublishBox::build($checker->protectPressPlan($focus_domain),
			array('dns_show' => '1') + $input,
			DnsPublishBox::urlWith($state_qs(), array('dns_plan' => 'press')));
		$press_class = $press['provider_class'] ?? null;
		if ($press_class !== null && $press['state'] === DnsPublishBox::STATE_DIFF
				&& $press_class::credentialMode() !== DnsProvider::CREDENTIAL_OAUTH2) {
			$unsuitable = mailbox_protect_press_unsuitable($press['rows']);
			// Asked cheaply: opening the key here would count as using the
			// vault on every page view and keep it from ever idling out. The
			// press itself opens it, before it writes anything.
			if ((int)$focus_domain_model->get('ied_owner_usr_user_id') !== (int)$session->get_user_id()) {
				$protect_press_note = 'Only the key\'s owner can turn send protection on in one press: the '
					. 'key opens only in their unlocked vault.';
			} elseif ($unsuitable !== '') {
				$protect_press_note = 'One press is not offered here. ' . $unsuitable;
			} else {
				$press['press'] = _setup_press_confirmation($press['rows'], $focus_domain);
				$press['press']['vault_locked'] = !$protect_vault_unlocked;
				$protect_press_box = $press;
			}
		}

		// WITHOUT A PRESS, ONE ORDERED LIST (WP4): every record in publish
		// order, the strict ones greyed out, rechecked every 30 seconds by the
		// page until the button may be pressed.
		if ($protect_press_box === null) {
			$protect_record_list = mailbox_protect_record_list($focus_domain_model, $checker, $protect_preflight);
		}

		// dns_plan=signing in its post URL tells the Apply handler this box's plan.
		if (!$protect_signing_ready && $protect_press_box === null) {
			$protect_dns_box = DnsPublishBox::build(
				$checker->signingReadinessPlan($focus_domain),
				$input,
				DnsPublishBox::urlWith($state_qs(), array('dns_plan' => 'signing'))
			);
		}
	}

	return LogicResult::render(array(
		'dns_box_last'               => $dns_box_last,
		'session'                    => $session,
		'settings'                   => $settings,
		'base'                       => $base,
		'self_url'                   => $self_url,
		'dns_box'                    => $dns_box,
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
		'focus_relay_on'             => $focus_relay_on,
		'focus_send_requested'       => $focus_send_requested,
		'acting_has_vault'           => $acting_has_vault,
		'protect'                    => $protect,
		'protect_setup'              => $protect_setup,
		'protect_preflight'          => $protect_preflight,
		'protect_dns_box'            => $protect_dns_box,
		'protect_press_box'          => $protect_press_box,
		'protect_press_note'         => $protect_press_note,
		'protect_press_result'       => $protect_press_result,
		'protect_signing_ready'      => $protect_signing_ready,
		'protect_record_list'        => $protect_record_list,
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
	));
}

/**
 * The domain the page is focused on, by mailbox or directly, for a handler that
 * runs before the page has resolved anything. '' for none, or an IMAP source.
 */
function _setup_focus_domain_name(int $alias_id, int $domain_id): string {
	if ($alias_id > 0) {
		$alias = new InboundEmailAlias($alias_id, TRUE);
		$domain_id = $alias->key ? (int)$alias->get('iea_ied_inbound_email_domain_id') : 0;
	}
	if ($domain_id <= 0) {
		return '';
	}
	$domain = new InboundEmailDomain($domain_id, TRUE);
	if (!$domain->key || $domain->get('ied_is_imap_source') || $domain->get('ied_delete_time')) {
		return '';
	}
	return strtolower(trim((string)$domain->get('ied_domain')));
}

/**
 * What the press's confirmation says, beyond the table: each live record it
 * replaces, and any DMARC setting the replacement leaves out.
 *
 * keys are consent tokens (mailbox_protect_consent_token()): each binds the
 * record shown to the live values shown beside it.
 *
 * @return array{label:string,confirm:string,lines:string[],keys:string[]}
 */
function _setup_press_confirmation(array $rows, string $domain): array {
	$lines = array();
	$keys = array();
	foreach ($rows as $row) {
		$record = $row['record'];
		$keys[] = mailbox_protect_consent_token($row);
		if ($row['outcome'] === DnsReconciler::MATCHES || empty($row['live'])) {
			continue;
		}
		foreach ($row['live'] as $live) {
			$line = 'Replaces ' . $record->type . ' ' . $record->name . ' "' . $live->value . '" with "'
				. $record->value . '"';
			if ($record->txtKind() === 'dmarc') {
				$dropped = InboundEmailSetupCheck::dmarcDroppedTags($live->value, $record->value);
				if (!empty($dropped)) {
					$line .= ', leaving out ' . implode(', ', $dropped)
						. ' (they would let some forged mail through)';
				}
			}
			$lines[] = $line . '.';
		}
	}
	return array(
		'label'   => 'Turn on send protection',
		'confirm' => 'Turn send protection on for ' . $domain . '? This publishes the records above, starts '
			. 'signing with your key, and then publishes the records that make other servers reject anything '
			. 'your key did not sign. You will need your vault unlocked to send mail as this domain.',
		'lines'   => $lines,
		'keys'    => $keys,
	);
}

/**
 * The press's handler: the credential from the form, one connection, the
 * ceremony, and its result shown once on the page it lands on.
 */
function _setup_protect_press(array $posted, $session, string $domain_name, string $return_url): LogicResult {
	require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
	$session->check_permission(Access::level('config:write'));
	$fail = function (string $msg) use ($session, $return_url) {
		$session->save_message(new DisplayMessage($msg, 'Send protection is not on',
			'~/plugins/mailbox/admin/~', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($return_url);
	};
	$domain = $domain_name !== '' ? InboundEmailDomain::GetByDomain($domain_name) : false;
	if (!$domain) {
		return $fail('That domain no longer exists.');
	}
	$driver_class = DnsDriverRegistry::get(DnsPublishBox::chosenProviderKey($posted, $domain_name));
	if ($driver_class === null || $driver_class::credentialMode() === DnsProvider::CREDENTIAL_OAUTH2) {
		return $fail('This DNS host cannot be written in one press. Use the steps below.');
	}
	$credential = DnsPublishBox::credentialFrom($driver_class, $posted);
	if ($credential === null) {
		return $fail('Enter the ' . $driver_class::getLabel() . ' credential to turn send protection on.');
	}
	$conn = DnsPublishBox::connect($driver_class, $credential, $domain_name);
	unset($credential);   // the driver holds the only copy, gone with this request
	if ($conn['error'] !== '') {
		return $fail($conn['error']);
	}
	$keys = array_values(array_filter(explode(',', (string)($posted['press_keys'] ?? ''))));
	$result = mailbox_protect_press($domain, (int)$session->get_user_id(), $conn['driver'], $conn['zone'], $keys);
	unset($conn);
	$_SESSION['mailbox_protect_press'][(int)$domain->key] = $result;
	return LogicResult::redirect($return_url);
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
function _setup_dns_plan_for_alias(int $alias_id, bool $machine_stage = false,
		bool $signing_stage = false): ?DnsRecordPlan {
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
	$checker = new InboundEmailSetupCheck();
	return $signing_stage ? $checker->signingReadinessPlan($name)
		: $checker->dnsPlan($name, false, $machine_stage);
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

?>
