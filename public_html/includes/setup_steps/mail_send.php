<?php
/**
 * Setup wizard step: Email — sending AND receiving, one step.
 * The same declared settings the email settings page renders — one renderer,
 * no parallel form — plus a test send that records its last success.
 * Included by views/setup.php with $page, $viewer, $settings, $site_name in scope.
 *
 * The one address question answers everything: the From address is the
 * sending identity AND (with the mailbox plugin) the owner's mailbox, so the
 * save provisions both and the DNS stage publishes the domain's whole mail
 * shape — MX and the receiving stack included — in the one pass where the
 * operator hands over a DNS credential.
 *
 * The step wears three faces, derived fresh on every render:
 *
 *   form   No working provider yet. One question (the From address), the
 *          provider picker with Mailgun preselected, and the API key. No
 *          domain field — the domain is the From address's domain; the save
 *          handler provisions the mailbox for the address and registers the
 *          domain at the provider through its API (SendingDomainRegistrar),
 *          so both dashboard errands disappear.
 *   dns    Provider configured, but the provider does not yet report the
 *          sending domain verified. Shows the full record plan
 *          (_setup_wizard_dns_plan), offers to publish it through a DNS
 *          driver (credential used once, never stored), and a Refresh that
 *          re-asks the provider and re-grades the receiving verdict.
 *   prove  Verified (or the provider has no registrar API to ask). Send a
 *          test, press "It arrived" — with the mailbox's receiving state
 *          alongside, records shown while its DNS still pends.
 *
 * The expensive work (provider API lookups, the record plan) runs only when
 * its stage renders — the step's status closure stays cheap.
 *
 * @version 3.2
 * @changelog 3.2 - The prove stage's receiving checklist lists every store
 *   mailbox (SetupSteps::receivingMailboxes): a connected account reads
 *   "connected account" with a green dot while its feed is on and "connection
 *   paused" when not — never "waiting for DNS". The DNS-plan block stays keyed
 *   to a hosted From domain (specs/imap_source_domain_boundaries.md §6.2).
 * @version 3.1
 * @changelog 3.1 - The move-your-DNS offer only renders for a domain that
 *   serves nothing but this site (DnsRelocation::foreignUse) — a lived-in
 *   domain's operator pastes the records by hand instead of risking a
 *   relocation that cannot see all their records.
 * @version 3.0
 * @changelog 3.0 - Sending and receiving are one step (the separate receiving
 *   step is gone): the DNS stage publishes the full mail plan, the intro says
 *   mail will arrive here too, and the prove stage reports each mailbox
 *   address with its receiving verdict, records folded open while DNS pends.
 * @version 2.15
 * @changelog 2.15 - Picking a host the domain's DNS does not live at says so
 *   (amber mismatch notice), and picking Linode that way IS the move: the
 *   move form replaces the publish form, which is what the dropdown answer
 *   meant. Publishing into a host that could never answer is no longer the
 *   default outcome of that click.
 * @version 2.14
 * @changelog 2.14 - A started move persists across reloads: while
 *   dns_move_pending is set the auto tab IS the handover (plus a cancel
 *   button), whatever the dropdown would have detected; the stage clears
 *   the state itself once the domain's NS records answer from the target.
 * @changelog 2.13 - The move choice reveals just the token field and button
 *   (the radio already said Linode): lead-in paragraph gone, renderer asks
 *   no destination question for a single target.
 * @changelog 2.12 - The gate radio is a FormWriter radioinput — stacked
 *   form-check rows, not hand-rolled inline labels.
 * @changelog 2.11 - A gated provider selection asks its question in place: an
 *   amber restriction notice and a three-way radio (use an API key / add
 *   manually at the vendor / move DNS to Linode). The choice drives the
 *   page; the always-on gated callout and its inline relocation mount are
 *   gone, the move now lives behind the third choice, and a gated detected
 *   host is preselected like any other.
 * @changelog 2.10 - The detected host is marked "(autodetected)" in the
 *   dropdown option itself; the detection helptext is gone.
 * @changelog 2.9 - The publish form is fields and one "How do I do this?"
 *   link: per-field helptext and the vendor note paragraph are gone, the
 *   gate/prerequisite rides as the guide modal's caution, and the used-once
 *   reassurance paragraph is removed.
 * @changelog 2.8 - The detection status is an amber banner under the intro
 *   ("We don't detect your DNS entries yet…") with the check control inside
 *   it, vertically centered on the right and named Refresh; the muted
 *   unverified line at the bottom is gone.
 * @changelog 2.7 - The dns stage reconciles the receiving-domain row on view
 *   (server_initiated_write): without it, a deployment whose save predates
 *   the row's creation renders the manual tab without the two Joinery
 *   Direct records, and the copied list is silently incomplete.
 * @changelog 2.6 - A gated host's callout now carries the whole guided move
 *   (dns_relocation_render): destination choice, credential, seeded zone,
 *   nameserver handover — the mail_send_move action runs the seed.
 * @changelog 2.5 - The dns stage reads the domain's live NS records first and
 *   is honest about what it finds: a host we can automate is preselected; a
 *   host whose API is gated (apiGateNote) leads with the one-time
 *   move-your-DNS fix instead of a form that cannot work.
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
require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
require_once(PathHelper::getIncludePath('includes/dns/dns_relocation_box.php'));

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

// The receiving-domain row, reconciled before anything reads it. The two
// Joinery Direct records exist only once this deployment is authoritative
// for the domain — the signing identity lives behind its receiving-domain
// row. The save creates it; on a deployment whose save predates that,
// rendering without this reconciliation shows a record list MISSING the
// Direct pair, and an operator who copies it publishes an incomplete set.
// Idempotent: once the row exists this is a read.
if ($setup_send_stage === 'dns' && $setup_send_domain !== '' && class_exists('InboundEmailSetupCheck')) {
	SystemBase::server_initiated_write(function () use ($setup_send_domain) {
		_setup_ensure_receiving_domain($setup_send_domain);
	});
}

// ---- The receiving half: every store mailbox, read fresh ----
// The same list the step's status grades (SetupSteps::receivingMailboxes):
// each mailbox with whether it can receive and why. A hosted domain waits
// on its DNS verdict; a connected account is receiving while its feed is on
// — the account is the arrangement, there is no MX to wait for. Empty with
// the mailbox plugin off or no mailbox yet. The DNS-plan block below is a
// separate question: does the From domain, if hosted here, still need records.
$setup_send_rcv = array();
$setup_send_rcv_dns_pending = false;
if ($setup_send_stage !== 'form' && class_exists('InboundEmailSetupCheck')) {
	$setup_send_rcv = SetupSteps::receivingMailboxes();
	if ($setup_send_domain !== '') {
		$setup_send_rcv_model = InboundEmailDomain::GetByDomain($setup_send_domain);
		$setup_send_rcv_dns_pending = ($setup_send_rcv_model && $setup_send_rcv_model->is_authoritative()
			&& (string)$setup_send_rcv_model->get('ied_setup_status') !== 'ok');
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

// From address: the owner's first address on their own domain — the
// {first name}@{domain} mailbox the save provisions along with sending.
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
	// Receiving rides the same step: each mailbox address with its verdict,
	// and — while a hosted From domain's DNS still pends — the records that
	// get it there. DNS still propagating is an amber wait, never a block.
	if ($setup_send_rcv) {
?>
	<ul class="setup-checklist jy-mt-2">
<?php foreach ($setup_send_rcv as $setup_send_rcv_row) { ?>
		<li>
			<span class="setup-dot <?php echo $setup_send_rcv_row['ok'] ? 'green' : 'amber'; ?>"></span>
			<span><?php echo htmlspecialchars($setup_send_rcv_row['address']); ?></span>
			<span class="jy-muted"><?php echo htmlspecialchars($setup_send_rcv_row['note']); ?></span>
		</li>
<?php } ?>
	</ul>
<?php
		if ($setup_send_rcv_dns_pending) {
			$setup_send_rcv_plan = _setup_wizard_dns_plan($setup_send_domain);
			if ($setup_send_rcv_plan !== null && !$setup_send_rcv_plan->isEmpty()) {
?>
	<details class="jy-mt-2"><summary>DNS records for <?php echo htmlspecialchars($setup_send_domain); ?></summary>
		<p class="jy-muted jy-mt-2">Add any that are missing wherever your domain's records live. New records
			can take a while to propagate — check back here, or on the mail Setup tab.</p>
		<div style="overflow-x:auto">
			<table class="jy-table">
				<tr><th>Type</th><th>Name</th><th>Value</th></tr>
<?php foreach ($setup_send_rcv_plan->getRecords() as $setup_send_record) { if ($setup_send_record->absent) { continue; } ?>
				<tr>
					<td><?php echo htmlspecialchars($setup_send_record->type); ?></td>
					<td><code><?php echo htmlspecialchars($setup_send_record->name); ?></code></td>
					<td><code style="word-break:break-all"><?php echo htmlspecialchars($setup_send_record->value); ?></code></td>
				</tr>
<?php } ?>
			</table>
		</div>
	</details>
<?php
			}
		}
	}
}

// ---- The DNS stage: connect the domain, then prove it ----
if ($setup_send_stage === 'dns') {
	// The whole mail shape in one pass: sending verification AND the records
	// that route mail for the domain to this site, because the operator hands
	// over a DNS credential exactly once.
	$setup_send_plan = _setup_wizard_dns_plan($setup_send_domain);
	$setup_send_drivers = array();
	foreach (DnsDriverRegistry::all() as $setup_send_drv_key => $setup_send_drv_class) {
		if ($setup_send_drv_class::credentialMode() === DnsProvider::CREDENTIAL_API) {
			$setup_send_drivers[$setup_send_drv_key] = $setup_send_drv_class;
		}
	}
	$setup_send_has_plan = ($setup_send_plan !== null && !$setup_send_plan->isEmpty());

	// Where the domain's DNS actually lives, read from its live NS records —
	// only the host that answers for the domain can take these records, so the
	// form is honest about it instead of offering every driver as if any could
	// work. A gated host (apiGateNote) is one most accounts cannot get a key
	// for, and the form says so up front rather than after a doomed signup.
	$setup_send_dns_host  = '';
	$setup_send_dns_label = '';
	if ($setup_send_domain !== '') {
		$setup_send_dns_host = (string)DnsDriverRegistry::identifyHost(
			DnsPublishBox::liveNameservers($setup_send_domain));
		$setup_send_dns_class = $setup_send_dns_host !== '' ? DnsDriverRegistry::get($setup_send_dns_host) : null;
		if ($setup_send_dns_class !== null) {
			$setup_send_dns_label = $setup_send_dns_class::getLabel();
		}
	}
	// Preselected whether or not the host gates its API — a gated selection
	// asks its question with the radio choice below the dropdown.
	$setup_send_dns_auto = isset($setup_send_drivers[$setup_send_dns_host]);
	$setup_send_move = is_array($setup_send_notice) ? ($setup_send_notice['move'] ?? null) : null;
	// The move is offered only when the domain serves nothing but this site:
	// visible mail routing, addresses, or a sender policy pointing anywhere
	// else means a lived-in domain, whose unguessable records a relocation
	// would strand. Those operators paste the records by hand instead. An
	// in-flight move renders its handover regardless — that state was made
	// under this same rule.
	$setup_send_can_move = array_key_exists('linode', DnsRelocation::targets())
		&& DnsRelocation::foreignUse($setup_send_domain, $setup_send_plan) === '';

	// An in-flight move outlives page loads: the choice and the seeded
	// handover persist (dns_move_pending) until the domain's NS records
	// answer from the target — completion, observed here and cleared — or
	// the operator cancels. A pending row for some other domain is stale
	// (the From address changed) and is cleared the same way.
	$setup_send_move_pending = null;
	$setup_send_mp_raw = trim((string)$settings->get_setting('dns_move_pending'));
	if ($setup_send_mp_raw !== '') {
		$setup_send_mp = json_decode($setup_send_mp_raw, true);
		$setup_send_mp = is_array($setup_send_mp) ? $setup_send_mp : array();
		if ((string)($setup_send_mp['domain'] ?? '') !== $setup_send_domain
				|| $setup_send_dns_host === (string)($setup_send_mp['target'] ?? '')) {
			SystemBase::server_initiated_write(function () {
				Setting::put('dns_move_pending', '');
			});
		} else {
			$setup_send_move_pending = $setup_send_mp;
		}
	}

	// The move face this render shows: a result from the press that just
	// happened (success or error), else the persisted mid-move handover.
	$setup_send_move_face = $setup_send_move;
	if ($setup_send_move_face === null && $setup_send_move_pending !== null) {
		$setup_send_move_face = array_merge(array('error' => '', 'zone_created' => false,
			'nameservers' => array(), 'copied' => array(), 'summary' => ''), $setup_send_move_pending);
	}
?>
<?php if ($setup_send_rcv !== null) { ?>
	<p>Your domain needs a few DNS records. They show <?php echo htmlspecialchars($setup_send_service_label); ?> that
		<strong><?php echo htmlspecialchars($setup_send_domain); ?></strong> is really yours, and they route mail
		for <?php echo htmlspecialchars($setup_send_domain); ?> to this site — sending and receiving in one go.
		Tell us where your domain is managed and we'll add them for you.</p>
<?php } else { ?>
	<p>Before <?php echo htmlspecialchars($setup_send_service_label); ?> will send your mail, it needs to see
		a few records at your domain — that's how it knows <strong><?php echo htmlspecialchars($setup_send_domain); ?></strong> is really yours.
		Tell us where your domain is managed and we'll add them for you.</p>
<?php } ?>

<?php if ($setup_send_state !== 'not_registered') { ?>
	<!-- The detection status: amber because it is the one thing still standing
	     between here and a working sender. The refresh control lives inside it,
	     vertically centered on the right — the message names the button that
	     answers it. (The kit has no amber alert or btn-link, hence the inline
	     styles and the chrome-free button.) -->
	<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; background:#fffaeb; border:1px solid #fec84b; border-radius:8px; padding:10px 14px; margin:8px 0 12px">
		<div>
			We don't detect your DNS entries yet. Set them below, and then come back and press refresh to recheck.
<?php if (is_array($setup_send_notice) && ($setup_send_notice['checked_state'] ?? '') !== '') { ?>
			<div class="jy-muted" style="margin-top:4px">Checked just now, at
				<?php echo htmlspecialchars(LibraryFunctions::convert_time(gmdate('Y-m-d H:i:s'), 'UTC', SessionControl::get_instance()->get_timezone(), 'g:i:s a')); ?>.</div>
<?php } ?>
		</div>
		<form method="POST" action="/setup" style="flex:none; margin:0">
			<input type="hidden" name="action" value="mail_send_verify">
			<input type="hidden" name="step" value="mail_send">
			<button type="submit" style="background:none; border:none; padding:6px 8px; cursor:pointer; font:inherit; color:var(--jy-color-link, #2563eb); text-decoration:underline; text-underline-offset:3px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:4px"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg>Refresh</button>
		</form>
	</div>
<?php } ?>

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
		<p class="jy-muted"><?php echo htmlspecialchars($setup_send_service_label); ?> didn't answer just now — the records may be incomplete. Press Refresh in a moment.</p>
<?php } ?>
<?php if ($setup_send_has_plan && $setup_send_drivers) { ?>
		<ul class="jy-tabs-list" role="tablist">
			<li role="presentation"><button type="button" role="tab" data-ms-tab="auto" aria-selected="true">Add records automatically</button></li>
			<li role="presentation"><button type="button" role="tab" data-ms-tab="manual" aria-selected="false">Add records manually</button></li>
		</ul>
		<div class="jy-tabs-panel active" id="setup-ms-tab-auto">
<?php if ($setup_send_move_face !== null) { ?>
<?php
		// A move is the whole story of this tab while it is under way (or just
		// ran, or just failed): the handover with the nameserver errand — or
		// the error with the form open to retry — and a way out. The question
		// flow below only returns once the move completes or is cancelled.
		dns_relocation_render($page, array(
			'domain'       => $setup_send_domain,
			'source_key'   => $setup_send_dns_host,
			'source_label' => $setup_send_dns_label !== '' ? $setup_send_dns_label : 'your current DNS host',
			'form_action'  => '/setup',
			'hidden'       => array('action' => 'mail_send_move', 'step' => 'mail_send'),
			'result'       => $setup_send_move_face,
			'recheck_hint' => 'press Refresh at the top of this page',
			'only'         => array((string)($setup_send_move_face['target'] ?? 'linode')),
		));
?>
<?php if ($setup_send_move_pending !== null) { ?>
		<form method="POST" action="/setup" class="jy-mt-2">
			<input type="hidden" name="action" value="mail_send_move_cancel">
			<input type="hidden" name="step" value="mail_send">
			<button type="submit" class="btn btn-secondary">Cancel the move — choose another way</button>
		</form>
<?php } ?>
<?php } else { ?>
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
			// The host answering the domain's NS records is the only one that
			// can take these records: it is preselected, and the option itself
			// says why.
			$setup_send_drv_options[$setup_send_drv_key] = $setup_send_drv_class::getLabel()
				. (($setup_send_dns_auto && $setup_send_drv_key === $setup_send_dns_host) ? ' (autodetected)' : '');
		}
		$setup_pub->dropinput('dns_provider', 'Where is your domain managed?', array(
			'options' => $setup_send_drv_options,
			'empty_option' => 'Choose…',
			'value' => $setup_send_dns_auto ? $setup_send_dns_host : '',
		));
		// A vendor that gates its API away from ordinary accounts asks its
		// question before showing a form most people cannot fill: the gate as
		// an amber notice, then the operator's three honest ways forward.
		// Which one they pick drives the page (script at the bottom): the API
		// choice reveals the credential form, manual grays this tab and lands
		// on the manual one, and the move choice opens the guided transfer.
		foreach ($setup_send_drivers as $setup_send_drv_key => $setup_send_drv_class) {
			$setup_send_drv_gate = $setup_send_drv_class::apiGateNote();
			if ($setup_send_drv_gate === '') { continue; }
?>
		<div class="setup-ms-gate d-none" data-dns-driver="<?php echo htmlspecialchars($setup_send_drv_key); ?>">
			<div class="setup-ms-gate-amber" style="background:#fffaeb; border:1px solid #fec84b; border-radius:8px; padding:10px 14px; margin:8px 0">
				<?php echo htmlspecialchars($setup_send_drv_gate); ?>
			</div>
<?php
			$setup_send_gate_choices = array(
				'api'    => 'I meet the requirements and will use an API key',
				'manual' => 'I will enter the DNS entries at ' . $setup_send_drv_class::getLabel() . ' manually',
			);
			if ($setup_send_can_move) {
				$setup_send_gate_choices['move'] = 'I will move DNS management to Linode';
			}
			$setup_pub->radioinput('gate_choice_' . $setup_send_drv_key, '', array(
				'options' => $setup_send_gate_choices,
			));
?>
		</div>
<?php
		}
		// Choosing a host the domain's DNS does not actually live at is an
		// honest mistake the dropdown invites — "where is your domain
		// managed?" reads as "where do you WANT it managed?". Say the
		// mismatch plainly; for Linode, the answer IS the move, so the move
		// form takes over (script below).
		if ($setup_send_dns_label !== '') {
			foreach ($setup_send_drivers as $setup_send_drv_key => $setup_send_drv_class) {
				if ($setup_send_drv_key === $setup_send_dns_host) { continue; }
				$setup_send_drv_move_instead = ($setup_send_drv_key === 'linode' && $setup_send_can_move);
?>
		<div class="setup-ms-mismatch d-none" data-dns-driver="<?php echo htmlspecialchars($setup_send_drv_key); ?>"<?php echo $setup_send_drv_move_instead ? ' data-move-instead="1"' : ''; ?>>
			<div style="background:#fffaeb; border:1px solid #fec84b; border-radius:8px; padding:10px 14px; margin:8px 0">
				<strong><?php echo htmlspecialchars($setup_send_domain); ?></strong>'s DNS is currently answered by
				<?php echo htmlspecialchars($setup_send_dns_label); ?>, not <?php echo htmlspecialchars($setup_send_drv_class::getLabel()); ?> —
				records added at <?php echo htmlspecialchars($setup_send_drv_class::getLabel()); ?> won't be used until your DNS moves there.<?php
				if ($setup_send_drv_move_instead) {
					echo ' Set the move up below: your records get created at Linode first, then you make one nameserver change at your registrar.';
				} ?>
			</div>
		</div>
<?php
			}
		}
		foreach ($setup_send_drivers as $setup_send_drv_key => $setup_send_drv_class) {
			echo '<div class="setup-ms-cred d-none" data-dns-driver="' . htmlspecialchars($setup_send_drv_key) . '">';
			// The form is fields and one "How do I do this?" link; every note
			// and instruction lives inside that modal. The vendor's account
			// gate (unless the callout above already said it) and any setup
			// prerequisite, like Namecheap's IP allowlist, ride as the
			// modal's caution rather than as paragraphs over the fields.
			$setup_send_drv_note = trim(
				($setup_send_drv_key === $setup_send_dns_host ? '' : $setup_send_drv_class::apiGateNote() . ' ')
				. $setup_send_drv_class::prerequisiteNote());
			$setup_send_drv_guide = $setup_send_drv_class::credentialGuide();
			if ($setup_send_drv_note !== '' && is_array($setup_send_drv_guide)) {
				$setup_send_drv_guide['caution'] = trim($setup_send_drv_note . ' '
					. (string)($setup_send_drv_guide['caution'] ?? ''));
			}
			foreach ($setup_send_drv_class::credentialFields() as $setup_send_drv_field => $setup_send_drv_spec) {
				if ($setup_send_drv_field === 'session_token' || $setup_send_drv_field === 'client_ip') { continue; }
				$setup_send_drv_opts = array(
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
			<div class="jy-mt-2" id="setup-ms-submit">
				<?php echo $setup_pub->submitbutton('btn_ms_publish', 'Add the records for me', array('class' => 'btn btn-primary')); ?>
			</div>
<?php
		$setup_pub->end_form();
		// The guided move (its own form, so it sits AFTER the publish form —
		// forms never nest), revealed by the radio's move choice.
		if ($setup_send_can_move) {
?>
		<div id="setup-ms-move" class="d-none">
<?php
			dns_relocation_render($page, array(
				'domain'       => $setup_send_domain,
				'source_key'   => $setup_send_dns_host,
				'source_label' => $setup_send_dns_label !== '' ? $setup_send_dns_label : 'your current DNS host',
				'form_action'  => '/setup',
				'hidden'       => array('action' => 'mail_send_move', 'step' => 'mail_send'),
				'result'       => null,
				'recheck_hint' => 'press Refresh at the top of this page',
				'only'         => array('linode'),
			));
?>
		</div>
<?php
		}
?>
<?php } ?>
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
		<p class="jy-muted">The record list isn't available just now — press Refresh in a moment.</p>
<?php } else { ?>
		<p class="jy-muted">Find the records to add in your <?php echo htmlspecialchars($setup_send_service_label); ?> dashboard, under your domain.</p>
<?php } ?>

<?php if ($setup_send_state !== '' && $setup_send_state !== 'not_registered' && $setup_send_state !== 'unverified') { ?>
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
// A wizard question, not a settings field.
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

// The DNS-stage publish form. Ungated host: its credential fields, nothing
// else. Gated host: the amber restriction notice and the three-way radio
// lead, and the choice drives the page — "api" drops the amber and reveals
// the credential form, "manual" grays this tab and lands on the manual one,
// "move" opens the guided Linode transfer below the form.
(function () {
	var provider = document.getElementById('dns_provider');
	if (!provider) { return; }
	var autoTab = document.querySelector('[data-ms-tab="auto"]');
	var manualTab = document.querySelector('[data-ms-tab="manual"]');
	var submitRow = document.getElementById('setup-ms-submit');
	var moveBlock = document.getElementById('setup-ms-move');
	function sync() {
		var key = provider.value;
		var gate = document.querySelector('.setup-ms-gate[data-dns-driver="' + key + '"]');
		var checked = gate ? gate.querySelector('input[type="radio"]:checked') : null;
		var choice = checked ? checked.value : '';
		var mismatch = document.querySelector('.setup-ms-mismatch[data-dns-driver="' + key + '"]');
		// A Linode pick when the domain lives elsewhere means "move me there":
		// the mismatch notice plus the move form replace the publish form.
		var moveInstead = !!(mismatch && mismatch.getAttribute('data-move-instead') === '1');
		document.querySelectorAll('.setup-ms-gate').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-dns-driver') !== key);
		});
		document.querySelectorAll('.setup-ms-mismatch').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-dns-driver') !== key);
		});
		if (gate) {
			var amber = gate.querySelector('.setup-ms-gate-amber');
			if (amber) { amber.classList.toggle('d-none', choice === 'api'); }
		}
		document.querySelectorAll('.setup-ms-cred').forEach(function (div) {
			var mine = div.getAttribute('data-dns-driver') === key;
			div.classList.toggle('d-none', !mine || (!!gate && choice !== 'api') || moveInstead);
		});
		if (submitRow) { submitRow.classList.toggle('d-none', (!!gate && choice !== 'api') || moveInstead); }
		if (moveBlock) { moveBlock.classList.toggle('d-none', !moveInstead && (!gate || choice !== 'move')); }
		if (autoTab) { autoTab.style.opacity = (gate && choice === 'manual') ? '0.5' : ''; }
		if (gate && choice === 'manual' && manualTab && manualTab.getAttribute('aria-selected') !== 'true') {
			manualTab.click();
		}
	}
	provider.addEventListener('change', sync);
	document.querySelectorAll('.setup-ms-gate input[type="radio"]').forEach(function (radio) {
		radio.addEventListener('change', sync);
	});
	sync();
})();
</script>
