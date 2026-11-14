<?php
/** @joinery-admin class=config */
/**
 * Inbound Email - Domain editor
 *
 * The add/edit domain form, reached from the Accounts tree (which is the domain
 * list). DNS and host verification live on the Setup tab
 * (admin_mailbox_setup), driven by InboundEmailSetupCheck. Raising the security
 * level runs the protection ceremony (specs/mailbox_protection_ceremony.md):
 * choosing a higher card reveals the prerequisite checklist and the save is
 * gated until its required rows pass. A raise lands on the receipt card
 * (specs/mailbox_raise_receipt.md), which seals the domain's earlier messages
 * in place and resolves into the completed facts. A lowering lands on its
 * mirror (specs/mailbox_lowering_unseal.md), which unseals them back.
 *
 * @version 4.13 - the Protected sending identity box states who owns the key and links to Setup, where it is chosen
 * @version 4.12 - no inline script: the Fortress gate, receipt title, unseal loop and ceremony gating are assets/admin_domains.js
 * @version 4.11 - Set up a relay opens the Server tab
 * @version 4.10 - the catch-all address says it must confirm (and that a sealed level keeps the mail
 *   instead), with its confirmation state and Resend under the form
 * @version 4.9 - Reject Unmatched says what it does: refused during the SMTP conversation, or accepted and dropped
 * @version 4.8 - the Fortress card: mail rules run on the device under Seal at the relay; the phone note
 * @version 4.7 - Save works on a domain whose checklist offers a fix (no form inside the form); Fortress
 *   shows its checklist; the step-up waits for the vault to open; a vault that fails to open says so
 * @version 4.6 - the Fortress card names the relay pin (trust on first use)
 * @version 4.5 - the Fortress receipt names relay-sealed mail still waiting to be opened (B46)
 * @version 4.4 - the Fortress card says, under Seal at the relay, that this server never sees the mail (B38)
 * @version 4.3 - Fortress (specs/client_custody_mail.md § R8, R12): its card and notes, the
 *   Fortress receipt for both directions, the mail vault opened before a save that
 *   chooses Fortress; the AI switches hide at Fortress
 * @version 4.2 - the Extra protection block states the short unlock window only; neither add-on
 *   asks for a second factor
 * @version 4.1 - the level cards and the Extra protection switches render
 *   through ProtectionLevelPicker, so their copy is the catalog's
 * @version 4.0 - two cards, Standard and Private, and an Extra protection block
 *   under Private: Seal at the relay and Only send while I'm signed in
 *   (specs/implemented/protection_levels_fold.md)
 * @version 3.9 - the AI travel consent shows at every security level: where
 *   mail may be sent to be read is a decision for Standard domains too
 *   (specs/security_inventory.md S19); the read switch stays sealed-only
 * @version 3.8 - an ADD offers only Hosted mail: a new pulled-in domain is
 *   born in the connect wizard, with its first mailbox
 * @version 3.7 - a provider (IMAP-source) domain shows no protection level: it is
 *   somebody else's domain, and each pulled-in mailbox chooses for itself
 * @version 3.6 - the domain's signing-key owner is stated here, and changed here
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_domains_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

$page_vars = process_logic(admin_mailbox_domains_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);
$ceremony = $ceremony ?? null;

$page = new AdminPage();
// The Fortress choice opens the owner's mail vault before it saves, and the
// Fortress receipt moves messages with it (specs/client_custody_mail.md § R8).
if (!empty($edit_domain)) {
	$page->needs_vault_client();
}
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
			'Domains' => '',
		),
		'session' => $session,
	)
);

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

// Flash messages render in the AdminPage header (admin pages must not
// fetch or render session messages themselves).

// How mail reaches this server is a deployment fact, not a question every page
// has to have answered first: an undecided deployment receives directly and
// works. The choice lives in the Server tab's Relay section
// (specs/mailbox_relay_surface_simplification.md).
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));

if (isset($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// --- Add/Edit Domain Form (only shown when editing or adding) ---
$show_form = $edit_domain || (isset($_GET['action']) && $_GET['action'] === 'add');

if ($show_form) {
	$form_domain = $edit_domain ?: new InboundEmailDomain(NULL);
	$form_title = $edit_domain ? 'Edit Domain' : 'Add Domain';

	// A new domain defaults to enabled with a store-locally catch-all (the
	// common case).
	if (!$form_domain->key) {
		$form_domain->set('ied_is_enabled', true);
		$form_domain->set('ied_catch_all_mode', 'store');
		// Pre-fill the domain name when arriving from the sending lock's "add a
		// Standard subdomain for automated mail" action (specs/mailbox_security_levels.md
		// Phase 3). Level defaults to Standard (the picker default), which is what
		// an automated-mail subdomain wants.
		if (!empty($_GET['prefill_domain'])) {
			$form_domain->set('ied_domain', strtolower(trim((string)$_GET['prefill_domain'])));
		}
	}

	// The raise receipt (specs/mailbox_raise_receipt.md): the headline of the
	// event, at the top of the page. Present on arrival from a raise or while
	// a backlog remains to converge; the JS loop below drives the sealing row.
	if ($ceremony !== null && !empty($ceremony['sealing_active'])) {
		echo mailbox_protection_receipt_render($edit_domain, $ceremony['facts'], array(
			'backlog' => $ceremony['backlog'],
			'sealed_total' => $ceremony['sealed_total'],
			'acting_user_id' => $ceremony['acting_user_id'],
			'editor_url' => $ceremony['editor_url'],
		));
	}
	// The lowering receipt (specs/mailbox_lowering_unseal.md): the downgrade
	// mirror — history converges back to plaintext in place.
	if ($ceremony !== null && !empty($ceremony['unseal_active'])) {
		echo mailbox_lowering_receipt_render($edit_domain, array(
			'own_backlog' => $ceremony['unseal_own_backlog'],
			'others_backlog' => $ceremony['unseal_others_backlog'],
			'window_open' => $ceremony['window_open'],
			'editor_url' => $ceremony['editor_url'],
		));
	}

	// The Fortress receipt (specs/client_custody_mail.md § R8): on arrival from
	// a change to or from Fortress, and while messages remain to move either way.
	if ($ceremony !== null && !empty($ceremony['fortress_active'])) {
		echo mailbox_fortress_receipt_render($edit_domain, array(
			'is_fortress'   => $edit_domain->is_fortress(),
			'raise_backlog' => $ceremony['fortress_raise_backlog'],
			'lower_backlog' => $ceremony['fortress_lower_backlog'],
			'waiting'       => $ceremony['fortress_waiting'],
			'window_open'   => VaultUnlock::isOpen(intval($ceremony['acting_user_id'])),
		));
		echo '<script defer src="/plugins/mailbox/assets/mailbox_fortress_level.js?v='
			. (@filemtime(PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_fortress_level.js')) ?: '1') . '"></script>';
	}

	$page->begin_box(array('title' => $form_title));

	$formwriter = $page->getFormWriter('domain_form', [
		'model' => $form_domain,
		'edit_primary_key_value' => $form_domain->key,
	]);

	echo $formwriter->begin_form();

	// IMAP-source presets hide the domain-name field (the domain is implied); the
	// catch-all block only applies to a hosted (Custom) domain.
	//
	// The protection level goes with them (specs/mailbox_connect_flow.md § D): a
	// provider domain is somebody else's, so it makes no claim about the mail —
	// each pulled-in mailbox under it chooses for itself, in the mailbox editor.
	// The AI consents go with the level too: the read switch only means
	// something once mail is sealed, and the travel consent belongs to the
	// domain that receives the mail.
	// The add-ons go with the level: they are properties of a domain this
	// deployment hosts, and a provider domain is not one. They render as one
	// block, emitted by ProtectionLevelPicker under the level cards.
	$addon_fields = ['ied_security_level_addons'];   // the picker's add-on block: {field}_addons
	$imap_only_hide = array_merge(['ied_catch_all_mode', 'ied_catch_all_address', 'ied_reject_unmatched',
		'ied_security_level',
		'ied_ai_processing_enabled', 'ied_ai_processing_consent'], $addon_fields);
	$imap_hide = array_merge(['ied_domain'], $imap_only_hide);
	$hosted_show = ['ied_domain', 'ied_catch_all_mode', 'ied_security_level'];
	$type_visibility = [
		'custom'         => ['show' => $hosted_show, 'hide' => []],
		'imap_gmail'     => ['show' => [], 'hide' => $imap_hide],
		'imap_microsoft' => ['show' => [], 'hide' => $imap_hide],
		'imap_yahoo'     => ['show' => [], 'hide' => $imap_hide],
		'imap_icloud'    => ['show' => [], 'hide' => $imap_hide],
		'imap_fastmail'  => ['show' => [], 'hide' => $imap_hide],
		'imap_generic'   => ['show' => ['ied_domain'], 'hide' => $imap_only_hide],
	];

	// A NEW pulled-in (IMAP) domain is not created here: it comes into being
	// through the connect wizard, together with its first mailbox (rule 1 of
	// specs/mailbox_connect_flow.md — the logic refuses it server-side too).
	// So an ADD offers only Hosted mail; an existing domain shows its real type.
	$type_options = [
		'custom'         => 'Hosted mail',
		'imap_gmail'     => 'IMAP — Gmail',
		'imap_microsoft' => 'IMAP — Microsoft 365 / Outlook',
		'imap_yahoo'     => 'IMAP — Yahoo',
		'imap_icloud'    => 'IMAP — iCloud',
		'imap_fastmail'  => 'IMAP — Fastmail',
		'imap_generic'   => 'IMAP — Other host',
	];
	if (!$form_domain->key) {
		$type_options = ['custom' => 'Hosted mail'];
	}
	$formwriter->dropinput('domain_type', 'Type', [
		'options' => $type_options,
		'value' => $domain_type,
		'visibility_rules' => $type_visibility,
		'helptext' => $form_domain->key ? ''
			: 'A mailbox pulled in from Gmail, Microsoft or another IMAP host is set up with the '
				. 'Connect a mailbox button on the Accounts page — its domain comes with it.',
	]);

	$formwriter->textinput('ied_domain', 'Domain Name', [
		'placeholder' => 'example.com',
	]);

	$formwriter->checkboxinput('ied_is_enabled', 'Enabled', []);

	// Protection level — Standard or Private, plus the Extra protection add-ons
	// under Private (specs/protection_levels_platform.md § Add-ons). Outcome
	// language only (no mechanism names at the point of choice); defaults to
	// Standard. The picker is hidden for IMAP-source domains via the domain_type
	// visibility rule above.
	// After a step-up round-trip the choices ride back as target_level /
	// target_relay / target_send — preselect them so the operator's intent
	// survives the ceremony.
	$level_value = $form_domain->key ? $form_domain->security_level() : InboundEmailDomain::LEVEL_STANDARD;
	if (!empty($_GET['target_level']) && in_array($_GET['target_level'], InboundEmailDomain::SETTABLE_LEVELS, true)) {
		$level_value = $_GET['target_level'];
	}
	$relay_value = $form_domain->key && $form_domain->addon_flag('ied_relay_seals_to_owner');
	$send_value  = $form_domain->key && ($form_domain->addon_flag('ied_send_lock_requested')
		|| $form_domain->is_protected_identity());
	if (isset($_GET['target_relay'])) { $relay_value = ($_GET['target_relay'] === '1'); }
	if (isset($_GET['target_send']))  { $send_value  = ($_GET['target_send'] === '1'); }

	// The cards and the Extra protection switches both come from
	// ProtectionLevelPicker, so a mail domain states its levels and add-ons in
	// the same words as every other service (specs/protection_levels_platform.md
	// § Add-ons rule 3). Only what is particular to mail — where switching the
	// sending lock on leads, and a relay that does not exist yet — is said here,
	// through the picker's note and link.
	$relay_offered = !empty($relay_available) || $relay_value;
	$relay_addon = ['checked' => $relay_value && $relay_offered, 'disabled' => !$relay_offered];
	if (!$relay_offered) {
		$relay_addon['note'] = 'It needs a relay in front of this server first.';
		$relay_addon['link'] = ['/plugins/mailbox/admin/admin_mailbox_server#relay-section', 'Set up a relay'];
	}
	// What only this page knows about Fortress, under its card (R12): whether
	// mail is seen as it arrives (it is, unless the relay seals it for your
	// devices under Seal at the relay, and then your mail rules run on the
	// device that opens it), how a phone reads it, and that an account which
	// unlocks by passphrase is as safe as its passphrase.
	$fortress_notes = [($relay_value && $relay_offered)
		? 'This server never sees your mail, not even as it arrives. Your browser checks which key the relay seals to, '
			. 'pinned on first use. New mail is opened on your device, and your mail rules run there when it is opened.'
		: 'New mail is encrypted the moment it arrives; a server hacked while mail is arriving '
			. 'could read what arrives then.',
		'Your phone reads this mail once you hand it the key from a computer; the key stays behind your '
			. 'phone\'s face or fingerprint lock.'];
	$root_vault = UserEncryptionVault::loadForUser((int)$session->get_user_id(), VaultScopes::ROOT_SCOPE);
	if ($root_vault !== null && (new MultiUserEncryptionWrapping(['vault_id' => (int)$root_vault->key,
			'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE, 'deleted' => false]))->count() > 0) {
		$fortress_notes[] = 'You unlock with a passphrase, so this mail is as safe from a hacked server as your '
			. 'passphrase is hard to guess. A passkey that can hold a key makes it only-your-devices.';
	}

	ProtectionLevelPicker::render($formwriter, 'ied_security_level', [
		'service' => ProtectionLevelPicker::SERVICE_MAIL,
		'levels'  => InboundEmailDomain::SETTABLE_LEVELS,
		'value'   => $level_value,
		'label'   => 'Protection level',
		'notes'   => [InboundEmailDomain::LEVEL_FORTRESS => $fortress_notes],
		// The AI READ switch only means something once mail is encrypted at
		// rest: on Standard the server already reads it, so there is nothing to
		// consent to (specs/in_window_deferred_work.md). The TRAVEL consent
		// below it shows at every level — where mail may be sent to be read is
		// a decision for a Standard domain too. The picker shows its add-on
		// block only on Private: Standard has nothing sealed for them to guard.
		'visibility_rules' => [
			InboundEmailDomain::LEVEL_STANDARD => ['show' => ['ied_ai_processing_consent'],
				'hide' => ['ied_ai_processing_enabled']],
			InboundEmailDomain::LEVEL_PRIVATE  => ['show' => ['ied_ai_processing_enabled', 'ied_ai_processing_consent']],
			// No server-side AI reads Fortress mail (R7): nothing to consent to.
			InboundEmailDomain::LEVEL_FORTRESS => ['hide' => ['ied_ai_processing_enabled', 'ied_ai_processing_consent']],
		],
		// Either add-on shortens the unlock window (§ Add-ons rule 5).
		'addons_note' => 'Either one keeps your unlock window short (2 hours idle, 24 hours at most).',
		'addons' => [
			ProtectionLevelPicker::ADDON_RELAY_SEALS_TO_OWNER => $relay_addon,
			ProtectionLevelPicker::ADDON_SEND_LOCK => [
				'checked' => $send_value,
				'note'    => 'Switching it on takes you through publishing its DNS records on the Setup tab.',
			],
		],
	]);

	// After a step-up round-trip the intent rides back as target_ai/target_cloud
	// — keep whichever boxes they ticked ticked, so the operator does not have
	// to remember what they were doing, and the graver of the two consents is
	// not silently dropped by the lost POST.
	$ai_value = (bool)$form_domain->get('ied_ai_processing_enabled');
	if (!empty($_GET['target_ai'])) {
		$ai_value = true;
	}
	$consent_value = $form_domain->ai_processing_consent();
	if (!empty($_GET['target_cloud'])) {
		$consent_value = (string)$_GET['target_cloud'];
		if (!in_array($consent_value, InboundEmailDomain::CONSENTS, true)) {
			$consent_value = InboundEmailDomain::CONSENT_LOCAL;
		}
	}

	// One plain sentence per endpoint explaining what the operator is agreeing
	// to on THIS install, taken from the endpoint's own trust_note so the
	// wording follows the catalog rather than being restated here.
	$trust_notes = [];
	try {
		foreach (AiEndpointRegistry::endpoints() as $ep_key => $ep) {
			if (!AiEndpointRegistry::isAvailable((string)$ep_key)) continue;
			$note = trim((string)($ep['trust_note'] ?? ''));
			if ($note !== '' && (string)$ep['trust'] !== InboundEmailDomain::CONSENT_LOCAL) {
				$trust_notes[] = (string)$ep['label'] . ': ' . $note;
			}
		}
	} catch (Throwable $e) {
		// No usable catalog — the control still works, it just cannot name
		// which vendors "trusted" covers here.
	}

	$formwriter->checkboxinput('ied_ai_processing_enabled',
		"Let Joinery AI read this domain's mail while your vault is unlocked", [
			'value' => $ai_value,
			'helptext' => 'Off by default. Turning this on lets the AI email features (triage, '
				. 'security scan, calendar) read this domain\'s mail during an unlock window '
				. 'and send it to the configured model host. They cannot run at all while it '
				. 'is off, because encrypted mail is unreadable without you.',
		]);

	// The travel consent: not whether the AI may read this mail, but how far
	// the text may travel to be read. Reading mail on your own hardware,
	// handing it to a vendor you have accepted terms with, and handing it to a
	// general cloud are three different promises, so the operator gets three
	// answers rather than one switch that conflates the last two. At every
	// level: a Standard domain's mail leaving for a hosted API is what this
	// setting exists to refuse, sealed at rest or not.
	$formwriter->dropinput('ied_ai_processing_consent',
		"How far this domain's mail may travel to be read by AI", [
			'value'   => $consent_value,
			'options' => [
				InboundEmailDomain::CONSENT_LOCAL   => 'Stay on my hardware (default)',
				InboundEmailDomain::CONSENT_TRUSTED => 'My hardware, or a vendor I have accepted',
				InboundEmailDomain::CONSENT_CLOUD   => 'Any configured AI endpoint, including cloud',
			],
			'helptext' => 'Starts at the strictest setting. On the first, this domain\'s mail is only '
				. 'ever read by a model running on hardware you control, and a recipe that would '
				. 'need anything else is refused. Loosening it lets the mail be sent to that '
				. 'provider, who then holds it in the clear. Tightening it back stops those '
				. 'recipes at their next run.'
				. ($trust_notes ? ' On this install, "a vendor I have accepted" means — '
					. implode(' ', $trust_notes) : ''),
		]);

	$formwriter->dropinput('ied_catch_all_mode', 'Catch-All Mode', [
		'options' => [
			'forward' => 'Forward to an address',
			'store'   => 'Store locally (every unmatched recipient)',
		],
		'visibility_rules' => [
			'forward' => ['show' => ['ied_catch_all_address', 'ied_reject_unmatched'], 'hide' => []],
			'store'   => ['show' => [], 'hide' => ['ied_catch_all_address', 'ied_reject_unmatched']],
		],
	]);

	$formwriter->textinput('ied_catch_all_address', 'Catch-All Address', [
		'helptext' => 'Mail to an address this domain does not have is forwarded here, once the person at it '
			. 'confirms; until then it is not forwarded. At Fortress, or with Seal at the relay, nothing is '
			. 'forwarded: that mail is kept here instead.',
	]);

	$formwriter->checkboxinput('ied_reject_unmatched', 'Reject Unmatched', [
		'helptext' => 'When there is no catch-all address: mail to an address this domain does not have is refused '
			. 'while the sending server is still connected, so the sender\'s own mail system tells them. '
			. 'Unticked, it is accepted and dropped.',
	]);

	// Protection ceremony (specs/mailbox_protection_ceremony.md): choosing
	// Private above the current level, or switching an add-on on, reveals the
	// prerequisite checklist for it and gates the submit until its required
	// rows pass. The save re-verifies server-side regardless — this is the
	// guided surface, not the enforcement.
	if ($ceremony !== null) {
		$urls = array(
			'editor_url' => $ceremony['editor_url'],
			'alias_url'  => '/plugins/mailbox/admin/admin_mailbox_alias',
		);
		// Fortress asks everything Private does (the save re-verifies the same
		// rows), so the one set of rows renders under each card with its heading.
		echo str_replace('id="protection-ceremony"', 'id="protection-ceremony-private"',
			mailbox_protection_render($ceremony['rows_private'], $edit_domain, $urls, InboundEmailDomain::LEVEL_PRIVATE));
		echo str_replace('id="protection-ceremony"', 'id="protection-ceremony-fortress"',
			mailbox_protection_render($ceremony['rows_private'], $edit_domain, $urls, InboundEmailDomain::LEVEL_FORTRESS));
		echo str_replace('id="protection-ceremony"', 'id="protection-ceremony-relay"',
			mailbox_protection_render($ceremony['rows_relay'], $edit_domain, $urls, '', '',
				'Before Seal at the relay can be on'));
		echo str_replace('id="protection-ceremony"', 'id="protection-ceremony-send"',
			mailbox_protection_render($ceremony['rows_send'], $edit_domain, $urls, '', '',
				'Only send while I\'m signed in'));
	}

	$formwriter->submitbutton('btn_submit', $edit_domain ? 'Update Domain' : 'Add Domain');

	echo $formwriter->end_form();
	if ($ceremony !== null) {
		echo mailbox_protection_fix_form($formwriter->getCSRFToken());
	}

	// The catch-all address's confirmation state, outside the form: Resend is
	// a form of its own.
	if ($edit_domain && $form_domain->key && !$form_domain->is_imap_source() && $form_domain->forwarding_offered()
			&& (string)$form_domain->get('ied_catch_all_mode') !== InboundEmailDomain::CATCHALL_STORE
			&& trim((string)$form_domain->get('ied_catch_all_address')) !== '') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/forward_confirmation_panel.php'));
		echo mailbox_forward_confirmation_panel(intval($form_domain->key), null,
			array((string)$form_domain->get('ied_catch_all_address')), '/plugins/mailbox/admin/admin_mailbox_domains',
			array('ied_inbound_email_domain_id' => intval($form_domain->key)));
	}

	$page->end_box();

	// What the page's script (assets/admin_domains.js) is to do, filled below.
	$mbx_domain_cfg = array();

	// Choosing Fortress opens the owner's mail vault first — making it, with
	// recovery codes, on first use — because the save refuses a Fortress domain
	// with no key to seal to (specs/client_custody_mail.md § R8). The submit is
	// held, the vault opened, and the same submit asked for again; the validator
	// re-dispatches too, so this lets a prevented event pass and runs once.
	if ($edit_domain && !$edit_domain->is_fortress()) {
		?>
		<?php $mbx_domain_cfg['fortress_gate'] = true; ?>
		<?php
	}

	// Receipt sealing loop (specs/mailbox_raise_receipt.md): the card carries the
	// shared batch driver's configuration (assets/js/ceremony-batch.js), which
	// runs bounded mailbox/seal_batch passes and resolves the progress row in
	// place — no page reloads. A pass that seals nothing while rows remain means
	// an unsealable backlog (a holder lost their vault after the raise): the
	// driver stops and says so instead of spinning forever. Without JS the card's
	// noscript form runs the same batches one page load at a time.
	if ($ceremony !== null && !empty($ceremony['sealing_active']) && $ceremony['backlog'] > 0) {
		?>
		<script defer src="/assets/js/ceremony-batch.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/ceremony-batch.js')) ?: '1'; ?>"></script>
		<?php $mbx_domain_cfg['receipt_title'] = true; ?>
		<?php
	}

	// Lowering unseal loop (specs/mailbox_lowering_unseal.md): the downgrade
	// mirror of the sealing loop — bounded mailbox/unseal_batch passes resolve
	// the card's progress row in place. Runs only while the acting user's
	// window is open (the card renders the unlock hint otherwise). A pass that
	// converges nothing while own rows remain stops red (undecryptable rows,
	// logged server-side); a locked answer stops with the unlock hint.
	if ($ceremony !== null && !empty($ceremony['unseal_active'])
			&& $ceremony['unseal_own_backlog'] > 0 && !empty($ceremony['window_open'])) {
		?>
		<?php $mbx_domain_cfg['unseal_loop'] = true; ?>
		<?php
	}

	// Ceremony reveal + submit gating.
	if ($ceremony !== null) {
		$current_rank = array(
			InboundEmailDomain::LEVEL_STANDARD => 0,
			InboundEmailDomain::LEVEL_PRIVATE  => 1,
			InboundEmailDomain::LEVEL_FORTRESS => 2,
		)[$edit_domain->security_level()] ?? 0;
		?>
		<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
		<?php $mbx_domain_cfg['ceremony'] = array(
			'current_rank'  => (int)$current_rank,
			'current_level' => $edit_domain->security_level(),
			'current_relay' => (bool)$edit_domain->relay_seals_to_owner(),
			'current_send'  => (bool)$edit_domain->send_lock_requested(),
			'relay_name'    => ProtectionLevelPicker::addonFieldName('ied_security_level', ProtectionLevelPicker::ADDON_RELAY_SEALS_TO_OWNER),
			'send_name'     => ProtectionLevelPicker::addonFieldName('ied_security_level', ProtectionLevelPicker::ADDON_SEND_LOCK),
		); ?>
		<?php
	}

	// The page's script for the four parts above, with what each needs.
	if (!empty($mbx_domain_cfg)) {
		echo PageScript::json('mailbox-domain-config', $mbx_domain_cfg);
		echo PageScript::src('plugins/mailbox/assets/admin_domains.js');
	}

	// Protected sending identity — once the sending lock is asked for, or on.
	// The domain states who its signing key belongs to; choosing or changing
	// that, publishing, verifying and switching protection on all happen in the
	// Sending identity box on the Setup tab, one place for the whole task.
	if ($edit_domain && $edit_domain->key && !$edit_domain->get('ied_is_imap_source')
			&& ($edit_domain->send_lock_requested() || $edit_domain->is_protected_identity())) {
		$page->begin_box(array('title' => 'Protected sending identity'));
		$dom_key = (int)$edit_domain->key;
		$has_key = !empty($protect) && !empty($protect['has_key']);
		$setup_link = '/plugins/mailbox/admin/admin_mailbox_setup?domain_id=' . $dom_key;

		if ($edit_domain->is_protected_identity()) {
			echo '<p class="alert alert-success">Send protection is on — while you are signed out, nothing on this server '
				. 'can send mail as this domain that anyone will accept.</p>';
		} elseif (!$has_key) {
			echo '<p>You asked for <strong>Only send while I\'m signed in</strong>. This domain has no signing key '
				. 'yet: Setup says what it is waiting for (who owns it, or the vault it is locked inside). Only the '
				. 'key\'s owner will ever be able to send as this domain.</p>';
			$setup_link .= '#sending-identity';
		} else {
			echo '<p>You asked for <strong>Only send while I\'m signed in</strong>. It is not on yet: finish it '
				. 'on the Setup tab, or switch it off above and this domain is finished as it is.</p>';
			$setup_link .= '&protect_setup=1';
		}

		if ($has_key) {
			echo '<p class="mb-2"><strong>The signing key belongs to '
				. htmlspecialchars($owner_label !== '' ? $owner_label : 'somebody who is no longer listed here')
				. '.</strong> Only that person can send as this domain once send protection is on.'
				. ($edit_domain->is_protected_identity()
					? ' To hand it to someone else, turn send protection off on the Setup tab first.'
					: ' You can change who it belongs to on the Setup tab until then.')
				. '</p>';
		}

		echo '<a class="btn btn-primary" href="' . htmlspecialchars($setup_link) . '">'
			. ($has_key || $edit_domain->is_protected_identity() ? 'Go to setup for this domain' : 'Make the signing key')
			. '</a>';
		$page->end_box();
	}
} // end show_form

// The active-domain list lives in the Accounts tree; this page is now purely the
// add/edit form (a bare visit is redirected to Accounts by the logic). Soft-
// deleted domain restore is handled from the Accounts tree (superadmin).

$page->admin_footer();
?>
