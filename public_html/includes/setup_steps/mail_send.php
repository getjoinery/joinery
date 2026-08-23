<?php
/**
 * Setup wizard step: Sending email (specs/setup_wizard.md § Step 3).
 * The same declared settings the email settings page renders — one renderer,
 * no parallel form — plus a test send that records its last success.
 * Included by views/setup.php with $page, $viewer, $settings, $site_name in scope.
 *
 * The step wears three faces, derived fresh on every render:
 *
 *   form   No working provider yet. One question (the From address), the
 *          provider picker with Mailgun preselected, and the API key. No
 *          domain field — the domain is the From address's domain, and the
 *          save handler registers it at the provider through its API
 *          (SendingDomainRegistrar), so the dashboard errand disappears.
 *   dns    Provider configured, but the provider does not yet report the
 *          sending domain verified. Shows the sending records
 *          (InboundEmailSetupCheck::sendingDnsPlan()), offers to publish
 *          them through a DNS driver (credential used once, never stored),
 *          and a check button that asks the provider to re-verify now.
 *   prove  Verified (or the provider has no registrar API to ask). Send a
 *          test, press "It arrived".
 *
 * The expensive work (provider API lookups, the record plan) runs only when
 * its stage renders — the step's status closure stays cheap.
 *
 * @version 2.4
 * @changelog 2.4 - The check link is a chrome-free button (the kit has no
 *   btn-link, so .btn was drawing button chrome) with real padding and margin.
 * @changelog 2.3 - "Check now" is a right-aligned refresh link directly under
 *   the intro paragraph (still a POST — it asks the provider to re-verify);
 *   its checked-at feedback rides with it.
 * @changelog 2.2 - The dns stage's intro paragraph moves to the top of the
 *   page (the step's generic copy is gone); automatic and manual are tabs
 *   (kit jy-tabs-list, automatic assumed); the publish credential fields go
 *   through FormWriter so each driver's credentialGuide() renders as the
 *   same "How do I get this?" modal the mail Setup tab shows; the folded
 *   change-settings form is absent on the dns stage.
 * @changelog 2.1 - The dns stage assumes the automatic path: the publish form
 *   leads and the record table folds behind "Do this manually"; the publish
 *   form's select and inputs pick up the kit's form-control styling.
 * @changelog 2.0 - The dns stage: the platform registers the From domain at
 *   the provider itself and walks the operator through the records — publish
 *   automatically through a DNS driver or add them by hand — gated on the
 *   provider reporting the domain verified. The Mailgun domain field and the
 *   add-it-in-the-dashboard guidance are gone; the domain is derived from
 *   the From address.
 * @changelog 1.4 - The two From identity fields collapse into one wizard
 *   question ('What email address would you like to use?', prefilled); the
 *   save handler maps it to defaultemail and defaults defaultemailname to
 *   the owner's name — both changeable later on the email settings page.
 * @changelog 1.2 - Mailgun preselected and labeled recommended; the
 *   connected-account choice dropped unless already active; the Mailgun EU
 *   endpoint behind an EU checkbox that fills or clears it.
 */
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

$setup_send_blocker = EmailSender::transactionalSendBlocker();
$setup_send_last = (string)$settings->get_setting('email_test_send_last_success');
$setup_send_service = EmailSender::activeServiceKey();
$setup_send_ready = ($setup_send_blocker === null && EmailSender::detectServiceType() !== 'none');
$setup_send_service_label = EmailSender::getAvailableServices()[$setup_send_service] ?? $setup_send_service;

$setup_send_notice = $_SESSION['setup_mail_send_result'] ?? null;
unset($_SESSION['setup_mail_send_result']);

// ---- Which face renders, derived from live state ----
$setup_send_provider_class = ($setup_send_service !== '')
	? (EmailSender::getDiscoveredProviders()[$setup_send_service] ?? null) : null;
$setup_send_registrar = $setup_send_provider_class !== null
	&& in_array('SendingDomainRegistrar', class_implements($setup_send_provider_class) ?: array(), true);
$setup_send_from = trim((string)$settings->get_setting('defaultemail'));
$setup_send_at = strrpos($setup_send_from, '@');
$setup_send_domain = ($setup_send_at !== false)
	? strtolower(rtrim(substr($setup_send_from, $setup_send_at + 1), '.')) : '';
$setup_send_state = '';
$setup_send_stage = 'form';
if ($setup_send_ready) {
	$setup_send_stage = 'prove';
	if ($setup_send_registrar && $setup_send_domain !== '') {
		$setup_send_state = (string)$setup_send_provider_class::getSendingDomainState($setup_send_domain);
		if ($setup_send_state !== 'active') {
			$setup_send_stage = 'dns';
		}
	}
}

// Prefills for a site configuring email for the first time. Stored values
// always win — these only fill what is still empty, and nothing is written
// until the operator presses Save.
$setup_send_service_prefill = $setup_send_service !== '' ? $setup_send_service : 'mailgun';

// The site's own domain drives the address prefill. Blank when the site has
// no real address yet (localhost / bare IP).
$setup_web = trim((string)$settings->get_setting('webDir'));
$setup_host = $setup_web !== ''
	? preg_replace('#^https?://#', '', rtrim($setup_web, '/'))
	: (string)($_SERVER['HTTP_HOST'] ?? '');
$setup_host = strtolower(trim((string)preg_replace('/:\d+$/', '', (string)$setup_host)));
$setup_host = (string)preg_replace('/^www\./', '', $setup_host);
if ($setup_host === 'localhost' || filter_var($setup_host, FILTER_VALIDATE_IP)) {
	$setup_host = '';
}

// From address: the owner's first address on their own domain — the same
// {first name}@{domain} the receiving-email step offers to create next.
$setup_from_email = $setup_send_from;
if ($setup_from_email === '' && $setup_host !== '') {
	$setup_local = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', (string)$viewer->get('usr_first_name')));
	$setup_from_email = ($setup_local !== '' ? $setup_local : 'noreply') . '@' . $setup_host;
}

// ---- The proof: send a test, confirm it arrived ----
if ($setup_send_stage === 'prove') {
?>
	<div class="jy-fieldset">
		<h4>Prove it works</h4>
<?php if (!empty($_GET['sent'])) { ?>
		<div class="jy-alert jy-alert-info">Test sent — check your inbox at <?php echo htmlspecialchars((string)$viewer->get('usr_email')); ?>. If it arrives, press "It arrived".</div>
<?php } ?>
		<div class="setup-choice">
			<form method="POST" action="/setup">
				<input type="hidden" name="action" value="mail_send_test">
				<input type="hidden" name="step" value="mail_send">
				<button type="submit" class="btn <?php echo empty($_GET['sent']) ? 'btn-primary' : 'btn-secondary'; ?>">Send me a test at <?php echo htmlspecialchars((string)$viewer->get('usr_email')); ?></button>
			</form>
<?php if (!empty($_GET['sent'])) { ?>
			<form method="POST" action="/setup">
				<input type="hidden" name="action" value="mail_send_confirm">
				<input type="hidden" name="step" value="mail_send">
				<button type="submit" class="btn btn-primary">It arrived</button>
			</form>
<?php } ?>
		</div>
<?php if ($setup_send_last !== '') { ?>
		<p class="jy-muted jy-mt-2">Last successful test: <?php echo htmlspecialchars(LibraryFunctions::convert_time($setup_send_last, 'UTC', SessionControl::get_instance()->get_timezone(), 'M j, Y g:i A T')); ?></p>
<?php } ?>
	</div>
<?php
}

// ---- The DNS stage: connect the domain, then prove it ----
if ($setup_send_stage === 'dns') {
	$setup_send_plan = null;
	if (class_exists('InboundEmailSetupCheck')) {
		$setup_send_plan = (new InboundEmailSetupCheck())->sendingDnsPlan($setup_send_domain);
	}
	require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
	$setup_send_drivers = array();
	foreach (DnsDriverRegistry::all() as $setup_send_drv_key => $setup_send_drv_class) {
		if ($setup_send_drv_class::credentialMode() === DnsProvider::CREDENTIAL_API) {
			$setup_send_drivers[$setup_send_drv_key] = $setup_send_drv_class;
		}
	}
	$setup_send_has_plan = ($setup_send_plan !== null && !$setup_send_plan->isEmpty());
?>
	<p>Before <?php echo htmlspecialchars($setup_send_service_label); ?> will send your mail, it needs to see
		a few records at your domain — that's how it knows <strong><?php echo htmlspecialchars($setup_send_domain); ?></strong> is really yours.
		Tell us where your domain is managed and we'll add them for you.</p>

	<div style="text-align:right; margin:8px 0 12px">
		<form method="POST" action="/setup" style="display:inline">
			<input type="hidden" name="action" value="mail_send_verify">
			<input type="hidden" name="step" value="mail_send">
			<!-- The kit has no btn-link style, so this is a plain button drawn
			     as a link on purpose — no .btn chrome to fight. -->
			<button type="submit" style="background:none; border:none; padding:6px 8px; cursor:pointer; font:inherit; color:var(--jy-color-link, #2563eb); text-decoration:underline; text-underline-offset:3px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:4px"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg>Check now</button>
		</form>
<?php if (is_array($setup_send_notice) && ($setup_send_notice['checked_state'] ?? '') !== '') { ?>
		<p class="jy-muted" style="margin:4px 0 0">Checked just now, at
			<?php echo htmlspecialchars(LibraryFunctions::convert_time(gmdate('Y-m-d H:i:s'), 'UTC', SessionControl::get_instance()->get_timezone(), 'g:i:s a')); ?>.</p>
<?php } ?>
	</div>

<?php if (is_array($setup_send_notice)) { ?>
<?php if ($setup_send_notice['registered'] === true) { ?>
	<div class="jy-callout jy-callout-info">
		<div class="jy-callout-title">Done for you</div>
		<p><strong><?php echo htmlspecialchars($setup_send_domain); ?></strong> is registered with <?php echo htmlspecialchars($setup_send_service_label); ?> — no need to add it in their dashboard. One thing remains: the records below.</p>
	</div>
<?php } elseif ($setup_send_notice['registered'] === false) { ?>
	<div class="jy-alert jy-alert-error">Registering <?php echo htmlspecialchars($setup_send_domain); ?> with <?php echo htmlspecialchars($setup_send_service_label); ?> didn't work<?php echo $setup_send_notice['register_error'] !== '' ? ': ' . htmlspecialchars($setup_send_notice['register_error']) : ''; ?>. You can try again below.</div>
<?php } ?>
<?php if ($setup_send_notice['publish_summary'] !== '') { ?>
	<div class="jy-alert jy-alert-info">Records: <?php echo htmlspecialchars($setup_send_notice['publish_summary']); ?></div>
<?php } ?>
<?php if ($setup_send_notice['publish_error'] !== '') { ?>
	<div class="jy-alert jy-alert-error"><?php echo htmlspecialchars($setup_send_notice['publish_error']); ?></div>
<?php } ?>
<?php } ?>

	<div class="jy-fieldset">
<?php if ($setup_send_state === 'not_registered') { ?>
		<p><strong><?php echo htmlspecialchars($setup_send_service_label); ?> doesn't know your domain yet.</strong>
			Registering it is normally automatic — press the button to do it now.</p>
		<form method="POST" action="/setup" class="jy-mt-2">
			<input type="hidden" name="action" value="mail_send_register">
			<input type="hidden" name="step" value="mail_send">
			<button type="submit" class="btn btn-primary">Register <?php echo htmlspecialchars($setup_send_domain); ?> with <?php echo htmlspecialchars($setup_send_service_label); ?></button>
		</form>
<?php } else { ?>
<?php if ($setup_send_state === '') { ?>
		<p class="jy-muted"><?php echo htmlspecialchars($setup_send_service_label); ?> didn't answer just now — the records may be incomplete. Try "Check now" in a moment.</p>
<?php } ?>
<?php if ($setup_send_has_plan && $setup_send_drivers) { ?>
		<ul class="jy-tabs-list" role="tablist">
			<li role="presentation"><button type="button" role="tab" data-ms-tab="auto" aria-selected="true">Add records automatically</button></li>
			<li role="presentation"><button type="button" role="tab" data-ms-tab="manual" aria-selected="false">Add records manually</button></li>
		</ul>
		<div class="jy-tabs-panel active" id="setup-ms-tab-auto">
<?php
		// The same fields the mail Setup tab's publish box draws, guides
		// included — the driver's credentialGuide() hangs off its first
		// field, one "How do I get this?" per credential.
		$setup_pub = $page->getFormWriter('setup-ms-publish', array('action' => '/setup', 'method' => 'POST'));
		$setup_pub->begin_form();
		$setup_pub->hiddeninput('action', '', array('value' => 'mail_send_publish'));
		$setup_pub->hiddeninput('step', '', array('value' => 'mail_send'));
		$setup_send_drv_options = array();
		foreach ($setup_send_drivers as $setup_send_drv_key => $setup_send_drv_class) {
			$setup_send_drv_options[$setup_send_drv_key] = $setup_send_drv_class::getLabel();
		}
		$setup_pub->dropinput('dns_provider', 'Where is your domain managed?', array(
			'options' => $setup_send_drv_options,
			'empty_option' => 'Choose…',
			'value' => '',
		));
		foreach ($setup_send_drivers as $setup_send_drv_key => $setup_send_drv_class) {
			echo '<div class="setup-ms-cred d-none" data-dns-driver="' . htmlspecialchars($setup_send_drv_key) . '">';
			$setup_send_drv_guide = $setup_send_drv_class::credentialGuide();
			foreach ($setup_send_drv_class::credentialFields() as $setup_send_drv_field => $setup_send_drv_spec) {
				if ($setup_send_drv_field === 'session_token' || $setup_send_drv_field === 'client_ip') { continue; }
				$setup_send_drv_opts = array(
					'helptext' => $setup_send_drv_spec['help'] ?? '',
					'autocomplete' => 'off',
					'help_modal' => $setup_send_drv_guide,
				);
				$setup_send_drv_guide = null;
				if (!empty($setup_send_drv_spec['secret'])) {
					$setup_pub->passwordinput('dns_cred_' . $setup_send_drv_field, $setup_send_drv_spec['label'] ?? $setup_send_drv_field, $setup_send_drv_opts);
				} else {
					$setup_pub->textinput('dns_cred_' . $setup_send_drv_field, $setup_send_drv_spec['label'] ?? $setup_send_drv_field, $setup_send_drv_opts);
				}
			}
			echo '</div>';
		}
?>
			<p class="jy-muted jy-mt-2">Your sign-in details are used once to add the records and never stored. Records that already exist are left alone.</p>
			<div class="jy-mt-2">
				<?php echo $setup_pub->submitbutton('btn_ms_publish', 'Add the records for me', array('class' => 'btn btn-primary')); ?>
			</div>
<?php
		$setup_pub->end_form();
?>
		</div>
		<div class="jy-tabs-panel" id="setup-ms-tab-manual">
			<p class="jy-muted jy-mt-2">Add these wherever your domain's records live — the same place you'd change an A record.</p>
			<div style="overflow-x:auto">
				<table class="jy-table">
					<tr><th>Type</th><th>Name</th><th>Value</th></tr>
<?php foreach ($setup_send_plan->getRecords() as $setup_send_record) { if ($setup_send_record->absent) { continue; } ?>
					<tr>
						<td><?php echo htmlspecialchars($setup_send_record->type); ?></td>
						<td><code><?php echo htmlspecialchars($setup_send_record->name); ?></code></td>
						<td><code style="word-break:break-all"><?php echo htmlspecialchars($setup_send_record->value); ?></code></td>
					</tr>
<?php } ?>
				</table>
			</div>
		</div>
<?php } elseif ($setup_send_has_plan) { ?>
		<p class="jy-muted">Add these wherever your domain's records live — the same place you'd change an A record.</p>
		<div style="overflow-x:auto">
			<table class="jy-table">
				<tr><th>Type</th><th>Name</th><th>Value</th></tr>
<?php foreach ($setup_send_plan->getRecords() as $setup_send_record) { if ($setup_send_record->absent) { continue; } ?>
				<tr>
					<td><?php echo htmlspecialchars($setup_send_record->type); ?></td>
					<td><code><?php echo htmlspecialchars($setup_send_record->name); ?></code></td>
					<td><code style="word-break:break-all"><?php echo htmlspecialchars($setup_send_record->value); ?></code></td>
				</tr>
<?php } ?>
			</table>
		</div>
<?php } elseif ($setup_send_plan !== null) { ?>
		<p class="jy-muted">The record list isn't available just now — press "Check now" in a moment to refresh it.</p>
<?php } else { ?>
		<p class="jy-muted">Find the records to add in your <?php echo htmlspecialchars($setup_send_service_label); ?> dashboard, under your domain.</p>
<?php } ?>

<?php if ($setup_send_state === 'unverified') { ?>
		<p class="jy-muted jy-mt-2"><?php echo htmlspecialchars($setup_send_service_label); ?> can see your domain but not all of the records yet. New records can take a little while to show up — press "Check now" (top right) whenever you like.</p>
<?php } elseif ($setup_send_state !== '' && $setup_send_state !== 'not_registered') { ?>
		<p class="jy-muted jy-mt-2"><?php echo htmlspecialchars($setup_send_service_label); ?> currently reports your domain as "<?php echo htmlspecialchars($setup_send_state); ?>".</p>
<?php } ?>
<?php } ?>
	</div>
<script>
// The two ways of adding records are tabs; automatic is the assumed path.
(function () {
	var tabs = document.querySelectorAll('[data-ms-tab]');
	if (!tabs.length) { return; }
	tabs.forEach(function (btn) {
		btn.addEventListener('click', function () {
			tabs.forEach(function (b) { b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
			['auto', 'manual'].forEach(function (key) {
				var panel = document.getElementById('setup-ms-tab-' + key);
				if (panel) { panel.classList.toggle('active', btn.getAttribute('data-ms-tab') === key); }
			});
		});
	});
})();
</script>
<?php
}

if ($setup_send_stage === 'form' && $setup_send_blocker !== null && $setup_send_service !== '') {
?>
	<div class="jy-alert jy-alert-error"><?php echo htmlspecialchars($setup_send_blocker); ?></div>
<?php
}

// ---- The settings: the work when unconfigured, folded away once proven.
// Absent on the dns stage on purpose — Back leaves the step, and the email
// settings page is where a stored credential gets changed.
if ($setup_send_stage !== 'dns') {
if ($setup_send_stage === 'prove') {
	echo '<details class="jy-mt-3"><summary>Change email settings — currently '
		. htmlspecialchars($setup_send_service_label)
		. ', sending as ' . htmlspecialchars($setup_send_from)
		. '</summary><div class="jy-mt-2">';
}

$formwriter = $page->getFormWriter('setup-mail-send', array('action' => '/setup', 'method' => 'POST'));
$formwriter->begin_form();
$formwriter->hiddeninput('action', '', array('value' => 'mail_send_save'));
$formwriter->hiddeninput('step', '', array('value' => 'mail_send'));

SettingsFieldRenderer::renderGroup($formwriter, 'email_delivery', array(
	'source' => 'core',
	'only' => array('email_service'),
	'values' => array('email_service' => $setup_send_service_prefill),
	'field_options' => array('email_service' => array(
		// No account can be connected during first setup, so the choice only
		// appears when it is somehow already the active service.
		'skip_options' => $setup_send_service === 'connected_account' ? array() : array('connected_account'),
		'option_labels' => array('mailgun' => 'Mailgun (recommended)'),
	)),
));

// One question stands in for the whole From identity: the address. The save
// handler writes it to defaultemail, defaults defaultemailname to the owner's
// name, and — for a provider whose API can register sending domains — derives
// the sending domain from it and registers it, so no domain question exists.
// A wizard question (like mail_receive's local_part), not a settings field.
$formwriter->textinput('wizard_from_address', 'What email address would you like to use?', array(
	'value' => $setup_from_email,
	'required' => true,
	'validation' => array('email' => true),
	'helptext' => 'Mail from this site goes out from this address.',
));

foreach (EmailSender::getDiscoveredProviders() as $setup_provider_key => $setup_provider_class) {
	if ($setup_provider_key === 'connected_account' && $setup_send_service !== 'connected_account') {
		continue;
	}
	$setup_group = 'email_provider_' . $setup_provider_key;
	if (!SettingsFieldRenderer::namesFor($setup_group, 'core')) {
		continue;
	}
	echo '<div class="setup-provider-fields d-none" data-email-provider="' . htmlspecialchars($setup_provider_key) . '">';
	if ($setup_provider_key === 'mailgun') {
		// No domain field: the sending domain is the From address's domain,
		// registered at Mailgun by the save handler. The EU endpoint only
		// matters to EU-region accounts, so its URL field sits behind a
		// plain-words checkbox: ticking reveals it (the script below fills
		// the standard EU address), unticking clears it. The checkbox itself
		// is page furniture, not a setting.
		$formwriter->checkboxinput('mailgun_eu_region', 'I am located in the European Union', array(
			'checked' => trim((string)$settings->get_setting('mailgun_eu_api_link')) !== '',
			'visibility_rules' => array(
				'checked'   => array('show' => array('mailgun_eu_api_link'), 'hide' => array()),
				'unchecked' => array('show' => array(), 'hide' => array('mailgun_eu_api_link')),
			),
		));
		SettingsFieldRenderer::renderGroup($formwriter, $setup_group, array(
			'source' => 'core',
			'only' => array('mailgun_eu_api_link'),
		));
	} else {
		SettingsFieldRenderer::renderGroup($formwriter, $setup_group, array('source' => 'core'));
	}
	echo '</div>';
}

// The Mailgun API key sits last on the page on purpose: everything above it
// is prefilled or a checkbox, so the key is the one thing left to type. A
// second provider div for the same provider is fine — the toggle below shows
// and hides them together.
echo '<div class="setup-provider-fields d-none" data-email-provider="mailgun">';
SettingsFieldRenderer::renderGroup($formwriter, 'email_provider_mailgun', array(
	'source' => 'core',
	'only' => array('mailgun_api_key'),
));
echo '</div>';
?>
<div class="jy-mt-2">
	<?php echo $formwriter->submitbutton('btn_mail_send_save', 'Save', array('class' => 'btn btn-primary')); ?>
</div>
<?php
$formwriter->end_form();

if ($setup_send_stage === 'prove') {
	echo '</div></details>';
}
}
?>

<script>
// Only the chosen provider's credential fields are on screen.
(function () {
	var select = document.getElementById('email_service');
	if (!select) { return; }
	function toggle() {
		document.querySelectorAll('.setup-provider-fields').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-email-provider') !== select.value);
		});
	}
	select.addEventListener('change', toggle);
	toggle();
})();

// The EU checkbox drives the endpoint value too, not just its visibility:
// ticking fills the standard EU address into an empty field, unticking clears
// it so the save stores nothing. A hidden field still submits, so clearing on
// untick is what makes the checkbox honest.
(function () {
	var box = document.getElementById('mailgun_eu_region');
	var field = document.getElementById('mailgun_eu_api_link');
	if (!box || !field) { return; }
	box.addEventListener('change', function () {
		if (box.checked) {
			if (!field.value) { field.value = 'https://api.eu.mailgun.net'; }
		} else {
			field.value = '';
		}
	});
})();

// The DNS-stage publish form: only the chosen DNS host's credential fields
// are on screen.
(function () {
	var provider = document.getElementById('dns_provider');
	if (!provider) { return; }
	function sync() {
		document.querySelectorAll('.setup-ms-cred').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-dns-driver') !== provider.value);
		});
	}
	provider.addEventListener('change', sync);
	sync();
})();
</script>
