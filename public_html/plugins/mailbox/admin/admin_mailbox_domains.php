<?php
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
 * @version 3.6 - the domain's signing-key owner is stated here, and changed here
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_domains_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

$page_vars = process_logic(admin_mailbox_domains_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);
$ceremony = $ceremony ?? null;

$page = new AdminPage();
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
// works. The choice lives in the Setup tab's Advanced section
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
		// Pre-fill the domain name when arriving from the Fortress "add a Standard
		// subdomain for automated mail" action (specs/mailbox_security_levels.md
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

	$page->begin_box(array('title' => $form_title));

	$formwriter = $page->getFormWriter('domain_form', [
		'model' => $form_domain,
		'edit_primary_key_value' => $form_domain->key,
	]);

	echo $formwriter->begin_form();

	// IMAP-source presets hide the domain-name field (the domain is implied); the
	// catch-all block only applies to a hosted (Custom) domain.
	$imap_hide = ['ied_domain', 'ied_catch_all_mode', 'ied_catch_all_address', 'ied_reject_unmatched', 'ied_security_level_fortress_card'];
	$type_visibility = [
		'custom'         => ['show' => ['ied_domain', 'ied_catch_all_mode', 'ied_security_level_fortress_card'], 'hide' => []],
		'imap_gmail'     => ['show' => [], 'hide' => $imap_hide],
		'imap_microsoft' => ['show' => [], 'hide' => $imap_hide],
		'imap_yahoo'     => ['show' => [], 'hide' => $imap_hide],
		'imap_icloud'    => ['show' => [], 'hide' => $imap_hide],
		'imap_fastmail'  => ['show' => [], 'hide' => $imap_hide],
		'imap_generic'   => ['show' => ['ied_domain'], 'hide' => ['ied_catch_all_mode', 'ied_catch_all_address', 'ied_reject_unmatched', 'ied_security_level_fortress_card']],
	];

	$formwriter->dropinput('domain_type', 'Type', [
		'options' => [
			'custom'         => 'Hosted mail',
			'imap_gmail'     => 'IMAP — Gmail',
			'imap_microsoft' => 'IMAP — Microsoft 365 / Outlook',
			'imap_yahoo'     => 'IMAP — Yahoo',
			'imap_icloud'    => 'IMAP — iCloud',
			'imap_fastmail'  => 'IMAP — Fastmail',
			'imap_generic'   => 'IMAP — Other host',
		],
		'value' => $domain_type,
		'visibility_rules' => $type_visibility,
	]);

	$formwriter->textinput('ied_domain', 'Domain Name', [
		'placeholder' => 'example.com',
	]);

	$formwriter->checkboxinput('ied_is_enabled', 'Enabled', []);

	// Security level — the per-domain protection posture. Outcome language only
	// (no mechanism names at the point of choice); defaults to Standard. The
	// Fortress card is hidden for IMAP-source domains via the domain_type
	// visibility rule above.
	// After a step-up round-trip the chosen level rides back as target_level —
	// preselect it so the operator's intent survives the ceremony.
	$level_value = $form_domain->get('ied_security_level') ?: InboundEmailDomain::LEVEL_STANDARD;
	$valid_levels = [InboundEmailDomain::LEVEL_STANDARD, InboundEmailDomain::LEVEL_PRIVATE, InboundEmailDomain::LEVEL_FORTRESS];
	if (!empty($_GET['target_level']) && in_array($_GET['target_level'], $valid_levels, true)) {
		$level_value = $_GET['target_level'];
	}

	$formwriter->radioinput('ied_security_level', 'Security level', [
		'card' => true,
		'required' => true,
		'value' => $level_value,
		'options' => [
			InboundEmailDomain::LEVEL_STANDARD => 'Standard',
			InboundEmailDomain::LEVEL_PRIVATE  => 'Private',
			InboundEmailDomain::LEVEL_FORTRESS => 'Fortress',
		],
		'descriptions' => [
			InboundEmailDomain::LEVEL_STANDARD => [
				'The server manages this mailbox for you.',
				'Best for club signups, newsletters, and low-stakes addresses.',
				'Nothing extra to set up. Stored mail is not protected at rest.',
			],
			InboundEmailDomain::LEVEL_PRIVATE => [
				'Only you can read your stored mail.',
				'Best for mail worth keeping private, where automation must keep working.',
				'You unlock to read. Lose every unlocker and the mail is gone for good.',
			],
			InboundEmailDomain::LEVEL_FORTRESS => [
				'Even a fully hacked server cannot read new mail or send as you.',
				'Best for the address that is you — banking, identity, primary correspondence.',
				'This domain can only send mail while you are signed in.',
			],
		],
		// The AI consent control only means something once mail is encrypted at
		// rest. On Standard the server already reads it, so there is nothing to
		// consent to (specs/in_window_deferred_work.md).
		'visibility_rules' => [
			InboundEmailDomain::LEVEL_STANDARD => ['show' => [], 'hide' => ['ied_ai_processing_enabled', 'ied_ai_cloud_enabled']],
			InboundEmailDomain::LEVEL_PRIVATE  => ['show' => ['ied_ai_processing_enabled', 'ied_ai_cloud_enabled'], 'hide' => []],
			InboundEmailDomain::LEVEL_FORTRESS => ['show' => ['ied_ai_processing_enabled', 'ied_ai_cloud_enabled'], 'hide' => []],
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
	$cloud_value = (bool)$form_domain->get('ied_ai_cloud_enabled');
	if (!empty($_GET['target_cloud'])) {
		$cloud_value = true;
	}

	$formwriter->checkboxinput('ied_ai_processing_enabled',
		"Let Joinery AI read this domain's mail while your vault is unlocked", [
			'value' => $ai_value,
			'helptext' => 'Off by default. Turning this on lets the AI email features (triage, '
				. 'security scan, calendar) read this domain\'s mail during an unlock window '
				. 'and send it to the configured model host. They cannot run at all while it '
				. 'is off, because encrypted mail is unreadable without you.',
		]);

	// The narrower second consent. Reading sealed mail on your own hardware and
	// sending that plaintext to a vendor are different promises, so they are
	// different switches — this one stays off when the first is turned on.
	$formwriter->checkboxinput('ied_ai_cloud_enabled',
		"Send this domain's decrypted mail to cloud AI models", [
			'value' => $cloud_value,
			'helptext' => 'Off by default, and separate from the setting above on purpose. With it '
				. 'off, this domain\'s mail is only ever read by a model running on hardware you '
				. 'control, and a recipe pinned to a cloud model is refused. Turning it on lets '
				. 'the decrypted mail be sent to that provider, who then holds it in the clear. '
				. 'Turning it back off stops those recipes at their next run.',
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

	$formwriter->textinput('ied_catch_all_address', 'Catch-All Address', []);

	$formwriter->checkboxinput('ied_reject_unmatched', 'Reject Unmatched', []);

	// Protection ceremony (specs/mailbox_protection_ceremony.md): choosing a
	// card ABOVE the current level reveals the prerequisite checklist for that
	// target and gates the submit until its required rows pass. The save
	// re-verifies server-side regardless — this is the guided surface, not the
	// enforcement.
	if ($ceremony !== null) {
		$urls = array(
			'editor_url' => $ceremony['editor_url'],
			'alias_url'  => '/plugins/mailbox/admin/admin_mailbox_alias',
		);
		echo str_replace('id="protection-ceremony"', 'id="protection-ceremony-private"',
			mailbox_protection_render($ceremony['rows_private'], $edit_domain, $urls, InboundEmailDomain::LEVEL_PRIVATE));
		echo str_replace('id="protection-ceremony"', 'id="protection-ceremony-fortress"',
			mailbox_protection_render($ceremony['rows_fortress'], $edit_domain, $urls, InboundEmailDomain::LEVEL_FORTRESS));
	}

	$formwriter->submitbutton('btn_submit', $edit_domain ? 'Update Domain' : 'Add Domain');

	echo $formwriter->end_form();

	$page->end_box();

	// Receipt sealing loop (specs/mailbox_raise_receipt.md): drive bounded
	// mailbox/seal_batch passes and resolve the card's progress row in place —
	// no page reloads. A pass that seals nothing while rows remain means an
	// unsealable backlog (a holder lost their vault after the raise): stop and
	// say so instead of spinning forever. Without JS the card's noscript form
	// runs the same batches one page load at a time.
	if ($ceremony !== null && !empty($ceremony['sealing_active']) && $ceremony['backlog'] > 0) {
		?>
		<script>
		(function () {
			var card = document.getElementById('raise-receipt');
			if (!card) return;
			var remaining = parseInt(card.dataset.backlog, 10) || 0;
			if (remaining <= 0) return;
			var sealedTotal = parseInt(card.dataset.sealedTotal, 10) || 0;
			var dot = document.querySelector('#receipt-seal-row .receipt-dot');
			var text = document.getElementById('receipt-seal-text');
			var csrf = (document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '';
			var noscriptForm = document.getElementById('sealing-continue');
			if (noscriptForm) noscriptForm.remove();
			function setDot(color) { if (dot) dot.style.background = color; }
			function finish() {
				setDot('#28a745');
				text.textContent = sealedTotal > 0
					? sealedTotal + ' earlier message' + (sealedTotal === 1 ? '' : 's') + ' sealed'
					: 'No earlier messages needed sealing';
				var title = document.getElementById('receipt-title');
				if (title && card.dataset.titleDone) title.textContent = card.dataset.titleDone;
				var btn = document.getElementById('receipt-action');
				if (btn) btn.classList.remove('d-none');
			}
			function stuck(n) {
				setDot('#dc3545');
				text.innerHTML = n + ' message' + (n === 1 ? '' : 's') + ' could not be sealed — see the '
					+ '<a href="/plugins/mailbox/admin/admin_mailbox_setup">Setup tab</a>.';
			}
			function batch() {
				fetch('/api/v1/action/mailbox/seal_batch', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf },
					body: JSON.stringify({ domain_id: parseInt(card.dataset.domainId, 10) })
				}).then(async function (r) {
					var j = await r.json();
					if (!r.ok) throw new Error((j && j.error) || 'Request failed.');
					return j.data;
				}).then(function (d) {
					var sealed = parseInt(d.sealed, 10) || 0;
					remaining = parseInt(d.remaining, 10) || 0;
					sealedTotal += sealed;
					if (remaining > 0 && sealed === 0) { stuck(remaining); return; }
					if (remaining > 0) {
						text.textContent = 'Sealing earlier messages — ' + remaining + ' remaining…';
						batch();
					} else {
						finish();
					}
				}).catch(function () {
					setDot('#dc3545');
					text.textContent = 'Sealing paused — reload this page to resume.';
				});
			}
			batch();
		})();
		</script>
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
		<script>
		(function () {
			var card = document.getElementById('lowering-receipt');
			if (!card || card.dataset.windowOpen !== '1') return;
			var remaining = parseInt(card.dataset.ownBacklog, 10) || 0;
			if (remaining <= 0) return;
			var unsealedTotal = 0;
			var dot = document.querySelector('#lowering-unseal-row .receipt-dot');
			var text = document.getElementById('lowering-unseal-text');
			var csrf = (document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '';
			var noscriptForm = document.getElementById('unsealing-continue');
			if (noscriptForm) noscriptForm.remove();
			function setDot(color) { if (dot) dot.style.background = color; }
			function finish() {
				setDot('#28a745');
				text.textContent = unsealedTotal > 0
					? unsealedTotal + ' earlier message' + (unsealedTotal === 1 ? '' : 's') + ' unsealed'
					: 'All earlier messages are readable';
				var btn = document.getElementById('lowering-action');
				if (btn) btn.classList.remove('d-none');
			}
			function updateOthers(n) {
				var row = document.getElementById('lowering-others-text');
				if (row && n > 0) {
					row.textContent = n + ' message' + (n === 1 ? '' : 's') + ' stay sealed until their readers next unlock';
				} else if (row && n === 0) {
					var li = document.getElementById('lowering-others-row');
					if (li) li.remove();
				}
			}
			function batch() {
				fetch('/api/v1/action/mailbox/unseal_batch', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf },
					body: JSON.stringify({ domain_id: parseInt(card.dataset.domainId, 10) })
				}).then(async function (r) {
					var j = await r.json();
					if (!r.ok) throw new Error((j && j.error) || 'Request failed.');
					return j.data;
				}).then(function (d) {
					var unsealed = parseInt(d.unsealed, 10) || 0;
					remaining = parseInt(d.own_remaining, 10) || 0;
					unsealedTotal += unsealed;
					updateOthers(parseInt(d.others_remaining, 10) || 0);
					if (d.locked) {
						setDot('#ffc107');
						text.textContent = 'Unlock your vault, then reload this page to continue unsealing — '
							+ remaining + ' remaining.';
						return;
					}
					if (remaining > 0 && unsealed === 0) {
						setDot('#dc3545');
						text.textContent = remaining + ' message' + (remaining === 1 ? '' : 's')
							+ ' could not be unsealed — see the error log.';
						return;
					}
					if (remaining > 0) {
						text.textContent = 'Unsealing earlier messages — ' + remaining + ' remaining…';
						batch();
					} else {
						finish();
					}
				}).catch(function () {
					setDot('#dc3545');
					text.textContent = 'Unsealing paused — reload this page to resume.';
				});
			}
			batch();
		})();
		</script>
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
		<script>
		(function () {
			var currentRank = <?php echo (int)$current_rank; ?>;
			var currentLevel = <?php echo json_encode($edit_domain->security_level()); ?>;
			var ranks = { standard: 0, private: 1, fortress: 2 };
			var form = document.querySelector('form[name="domain_form"], #domain_form') ||
				(document.querySelector('input[name="ied_security_level"]') || {}).form;
			if (!form) return;
			var submit = form.querySelector('button[type="submit"], input[type="submit"]');
			function refresh() {
				var chosen = form.querySelector('input[name="ied_security_level"]:checked');
				var level = chosen ? chosen.value : 'standard';
				var raising = (ranks[level] || 0) > currentRank;
				var privBox = document.getElementById('protection-ceremony-private');
				var fortBox = document.getElementById('protection-ceremony-fortress');
				var active = null;
				if (privBox) privBox.classList.add('d-none');
				if (fortBox) fortBox.classList.add('d-none');
				if (raising) {
					active = (level === 'fortress') ? fortBox : privBox;
					if (active) {
						// The checklist lives inside the chosen level's card,
						// and only appears when something needs attention.
						var card = document.getElementById('ied_security_level_' + level + '_card');
						if (card && active.parentElement !== card) card.appendChild(active);
						if (active.dataset.allGreen !== '1') active.classList.remove('d-none');
					}
				}
				if (submit) {
					submit.disabled = !!(active && active.dataset.requiredOk === '0');
				}
			}
			form.addEventListener('change', function (e) {
				if (e.target && e.target.name === 'ied_security_level') refresh();
			});
			refresh();

			// A level change is a sensitive action. Run the passkey step-up
			// INLINE before the form leaves the page, so the submission is
			// never lost to the redirect ceremony. Always — a render-time
			// freshness snapshot goes stale while the form sits open.
			// Fallback (no passkey, or the ceremony fails): plain submit —
			// the server redirects through /verify-stepup and target_level
			// preserves the choice.
			var stepupDone = false;
			var stepupInFlight = false;
			form.addEventListener('submit', function (e) {
				if (stepupDone) return;
				var chosen = form.querySelector('input[name="ied_security_level"]:checked');
				var level = chosen ? chosen.value : currentLevel;
				if (level === currentLevel) return;
				if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) {
					// The helper is absent, so it cannot report this itself -
					// record the fallthrough (keepalive survives the native
					// submit's navigation) so a server-ceremony detour is
					// never invisible in the request log.
					try {
						var fallthroughHeaders = { 'Content-Type': 'application/json' };
						var fallthroughToken = ((document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '');
						if (fallthroughToken) fallthroughHeaders['X-Joinery-Csrf'] = fallthroughToken;
						fetch('/api/v1/action/passkey_client_report', {
							method: 'POST', headers: fallthroughHeaders, keepalive: true,
							body: JSON.stringify({
								context: 'stepup-fallthrough:' + location.pathname,
								error_name: 'NoHelper',
								error_message: window.JoineryPasskeys ? 'WebAuthn unsupported' : 'passkeys.js not loaded at submit',
								focus: document.hasFocus(),
								visibility: document.visibilityState,
								elapsed_ms: 0,
							}),
						}).catch(function () {});
					} catch (te) { /* never block the fallback submit */ }
					return;
				}
				e.preventDefault();
				// One ceremony at a time: a second submit (double click, or the
				// validation layer re-dispatching) must not start a parallel
				// WebAuthn request — the browser kills the first with
				// NotAllowedError.
				if (stepupInFlight) return;
				stepupInFlight = true;
				var btnLabel = submit ? submit.textContent : '';
				if (submit) { submit.disabled = true; submit.textContent = 'Confirm with your passkey…'; }
				var csrf = (document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '';
				function api(url, body) {
					return fetch(url, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf },
						body: JSON.stringify(body || {})
					}).then(async function (r) {
						var j = await r.json();
						if (!r.ok) throw new Error(j.error || 'Request failed.');
						return j;
					});
				}
				api('/api/v1/action/passkey_stepup_options').catch(function (err) {
					// The server could not even start a passkey ceremony (e.g.
					// no passkeys enrolled — TOTP-only admin): hand the whole
					// submission to the server-side redirect ceremony instead.
					err.useServerCeremony = true;
					throw err;
				}).then(function (opts) {
					return JoineryPasskeys.authenticate(opts.data.options);
				}).then(function (credential) {
					return api('/api/v1/action/passkey_stepup_verify', { credential: credential });
				}).then(function () {
					stepupDone = true;
					form.submit();
				}).catch(async function (err) {
					if (err && err.useServerCeremony) { form.submit(); return; }
					stepupInFlight = false;
					if (submit) { submit.disabled = false; submit.textContent = btnLabel; }
					var msg = ((err && err.name) ? err.name + ': ' : '') + ((err && err.message) ? err.message : 'Passkey confirmation failed.');
					// A refused passkey must never dead-end the save — offer the
					// authenticator-code ceremony as the alternate path.
					if (window.JoineryModal && JoineryModal.confirmAsync) {
						var useCode = await JoineryModal.confirmAsync(msg + ' You can try the passkey again, or confirm with your authenticator code instead.', { confirmLabel: 'Use authenticator code', cancelLabel: 'Try passkey again', confirmStyle: 'primary' });
						if (useCode) { stepupDone = true; form.submit(); }
					}
				});
			});
		})();
		</script>
		<?php
	}

	// Protected sending identity — Fortress only.
	//
	// WHO THE KEY BELONGS TO IS DECIDED HERE. It is a property of the domain, not
	// a step in setting one up: Setup is where you publish records, verify them
	// and switch protection on. Until now the owner was written by the raise,
	// read by MailboxDkimSigner, and shown on no screen at all — for the one
	// value that decides who can ever send as this domain.
	//
	// Turning protection on, replacing a key and lifting protection all stay on
	// the Setup tab. This box states the fact and owns the one decision.
	if ($edit_domain && $edit_domain->key && !$edit_domain->get('ied_is_imap_source')
			&& $edit_domain->security_level() === InboundEmailDomain::LEVEL_FORTRESS) {
		$page->begin_box(array('title' => 'Protected sending identity'));
		$dom_key = (int)$edit_domain->key;
		$has_key = !empty($protect) && !empty($protect['has_key']);

		if ($edit_domain->is_protected_identity()) {
			echo '<p class="alert alert-success">Send protection is on — while you are signed out, nothing on this server '
				. 'can send mail as this domain that anyone will accept.</p>';
		} else {
			echo '<p>Arriving mail for this domain is sealed at the relay. Sending is not locked to your key — an '
				. 'optional extra step, with its cost explained, under Sending identity on the Setup tab.</p>';
		}

		if ($has_key) {
			echo '<p class="mb-2"><strong>The signing key belongs to '
				. htmlspecialchars($owner_label !== '' ? $owner_label : 'somebody who is no longer listed here')
				. '.</strong> Only that person can send as this domain once send protection is on.</p>';
		}

		if (!empty($protect) && !$has_key) {
			// The raise seals a key automatically and only fails to when it cannot
			// guess: a domain whose mailboxes already have holders, where the admin
			// raising the level need not be the person who reads the mail.
			echo '<p class="mb-2">This domain has no signing key yet, because more than one person could own it '
				. '— and only its owner will ever be able to send as this domain. That is not ours to guess.</p>';
			$own_form = $page->getFormWriter('domain_owner_form');
			echo $own_form->begin_form();
			$own_form->hiddeninput('ied_inbound_email_domain_id', '', array('value' => $dom_key));
			$own_form->hiddeninput('action', '', array('value' => 'protect_generate'));
			$own_form->dropinput('owner_user_id', 'Key belongs to', array(
				'options' => $protect['owner_options'],
				'value'   => $protect['default_owner_id'],
			));
			$own_form->submitbutton('btn_domain_owner', 'Make the key');
			echo $own_form->end_form();
		} elseif ($has_key && !$edit_domain->is_protected_identity()) {
			// CHANGING THE OWNER MEANS A NEW KEY, unavoidably: the current one is
			// sealed to its owner's vault, and re-sealing it to somebody else would
			// need the plaintext, which only that owner can produce. Say so, because
			// the published DNS record has to be replaced afterwards.
			echo '<details class="mb-2"><summary class="fix-toggle small">Change who this key belongs to</summary>';
			echo '<div class="mt-2">';
			echo '<p class="text-muted small">The key that exists is locked to its owner\'s vault and cannot be '
				. 'handed to anyone else, so choosing a different person makes a fresh one. Its DNS record changes '
				. 'with it and has to be republished. Nothing is enforced yet, so no mail is affected.</p>';
			$own_form = $page->getFormWriter('domain_owner_form');
			echo $own_form->begin_form();
			$own_form->hiddeninput('ied_inbound_email_domain_id', '', array('value' => $dom_key));
			$own_form->hiddeninput('action', '', array('value' => 'protect_generate'));
			$own_form->dropinput('owner_user_id', 'Key belongs to', array(
				'options' => $protect['owner_options'],
				'value'   => (int)$edit_domain->get('ied_owner_usr_user_id') ?: $protect['default_owner_id'],
			));
			$own_form->submitbutton('btn_domain_owner', 'Make a new key for this person');
			echo $own_form->end_form();
			echo '</div></details>';
		} elseif ($has_key) {
			// Protection is ON. A new key here would stop the domain sending until
			// its record was republished, and protect_generate refuses outright —
			// so offer the honest route rather than a control that gets rejected.
			echo '<p class="text-muted small mb-2">To hand this domain to a different owner, turn send protection '
				. 'off first under Sending identity on the Setup tab, then change it here. Replacing the key while '
				. 'keeping the same owner is a separate action there, and keeps mail working throughout.</p>';
		}

		echo '<a class="btn btn-primary" href="/plugins/mailbox/admin/admin_mailbox_setup?domain_id='
			. $dom_key . '">Go to setup for this domain</a>';
		$page->end_box();
	}
} // end show_form

// The active-domain list lives in the Accounts tree; this page is now purely the
// add/edit form (a bare visit is redirected to Accounts by the logic). Soft-
// deleted domain restore is handled from the Accounts tree (superadmin).

$page->admin_footer();
?>
