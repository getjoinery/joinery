<?php
/**
 * Renderer for the DNS publish box.
 *
 * One box above whatever copy-paste table a page already renders. It leads with
 * the host the domain's DNS **already lives at**, read from the domain's own NS
 * records, and hides every other driver behind a "use another provider" link — a
 * list of providers, not a ranking, with no tier labels. That is safe because of
 * the rail, not because every driver is proven: a driver can only write what the
 * operator saw and confirmed on screen, so an untested driver misbehaving shows
 * up as a wrong diff to decline rather than a silent bad write.
 *
 * The box never asks anyone to move their nameservers. Configuring a domain
 * where it already is has no blast radius; moving a zone takes the website and
 * every other name with it, and is not something this platform offers.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));

/**
 * @param AdminPage $page
 * @param array     $vars From DnsPublishBox::build().
 */
function dns_publish_box_render($page, array $vars): void {
	// Nothing to offer, so nothing is shown. A domain whose DNS lives somewhere
	// this platform has no driver for gets the page it has always had: the
	// checks below, each carrying the record to publish. An empty box explaining
	// what cannot happen would be one more thing to read and no help at all.
	if (empty($vars['plan'])
			|| $vars['state'] === DnsPublishBox::STATE_NO_PROVIDER
			|| $vars['state'] === DnsPublishBox::STATE_UNKNOWN_HOST) {
		return;
	}

	$domain = $vars['domain'];
	$label  = $vars['provider_label'];

	$page->begin_box(array('title' => 'Automatically configure DNS for ' . $domain));

	// Where the DNS lives, stated before the action that will write to it.
	dns_publish_box_host_line($vars);

	// A prerequisite is a real blocker for this provider — say it before the
	// action rather than letting the API fail opaquely.
	if ($vars['prerequisite'] !== '') {
		echo '<div class="alert alert-warning">' . htmlspecialchars($vars['prerequisite']) . '</div>';
	}

	if (!empty($vars['accounts'])) {
		dns_publish_box_accounts($page, $vars);
	}

	if ($vars['state'] === DnsPublishBox::STATE_ALL_GREEN) {
		dns_publish_box_settled($vars);
	}

	if ($vars['state'] === DnsPublishBox::STATE_DIFF) {
		dns_publish_box_diff($page, $vars);
	} elseif ($vars['state'] !== DnsPublishBox::STATE_ALL_GREEN) {
		dns_publish_box_primary($page, $vars);
	}

	dns_publish_box_chooser($page, $vars);

	$page->end_box();
}

/**
 * Where the records are going, said only when it is worth saying.
 *
 * With the detected host selected there is nothing to warn about — the headline
 * and the button both name it already, so repeating it as prose is noise. The
 * one case worth a line is a deliberate mismatch: records written to a provider
 * the domain does not use will never be answered.
 */
function dns_publish_box_host_line(array $vars): void {
	if ($vars['detected_key'] === '' || $vars['detected_key'] === $vars['provider_key']) {
		return;
	}
	echo '<p class="text-muted small mb-2">' . htmlspecialchars($vars['domain'])
		. '&rsquo;s DNS is hosted at <strong>' . htmlspecialchars($vars['detected_label'])
		. '</strong>, but ' . htmlspecialchars($vars['provider_label'])
		. ' is selected. Records written there will not be answered until the domain is moved.</p>';
}

/**
 * The headline: what applying will do, in the operator's words.
 *
 * Publishing DNS is the job the page exists for, so the summary reads as work
 * to do — "Add 3 DNS records at Cloudflare" — rather than as a tally of faults.
 * A count of MISSING alongside a warning badge described the same fact as a
 * problem, which it is not: an empty zone is the normal starting state.
 */
function dns_publish_box_headline(array $vars): string {
	$counts = $vars['counts'];
	$parts = array();
	$total = 0;
	foreach (array(
		DnsReconciler::MISSING   => 'add',
		DnsReconciler::DIFFERS   => 'correct',
		DnsReconciler::CONFLICTS => 'replace',
	) as $outcome => $verb) {
		$n = (int)($counts[$outcome] ?? 0);
		if ($n > 0) {
			$parts[] = $verb . ' ' . $n;
			$total += $n;
		}
	}
	if (empty($parts)) {
		return '';
	}
	// "Add 2, correct 1 and replace 3 DNS records" — one sentence, one verb per
	// kind of change, so the count never needs a legend to read.
	$last = array_pop($parts);
	$phrase = $parts ? implode(', ', $parts) . ' and ' . $last : $last;
	return ucfirst($phrase) . ' DNS record' . ($total === 1 ? '' : 's')
		. ' at ' . $vars['provider_label'];
}

/**
 * Nothing left to do — a receipt, and no form.
 *
 * The receipt comes from the ownership table, which the apply already wrote:
 * it survives the session, the browser and the operator, unlike a flash message.
 * Showing a submit button here is what would make someone publish twice.
 */
function dns_publish_box_settled(array $vars): void {
	$written = (string)$vars['last_written'];
	$pending = (int)($vars['counts'][DnsReconciler::PENDING] ?? 0);

	if ($written !== '') {
		$session = SessionControl::get_instance();
		$when = LibraryFunctions::convert_time($written, 'UTC', $session->get_timezone(), 'M j, Y g:i A T');
		$total = count($vars['rows']);
		echo '<p class="mb-2"><strong>' . $total . ' DNS record' . ($total === 1 ? '' : 's')
			. ' written at ' . htmlspecialchars($vars['provider_label'])
			. ' on ' . htmlspecialchars($when) . '.</strong></p>';
	} else {
		echo '<p class="mb-2"><strong>Nothing to change &mdash; '
			. htmlspecialchars($vars['domain']) . '&rsquo;s records are all live at '
			. htmlspecialchars($vars['provider_label']) . '.</strong></p>';
	}

	if ($pending > 0) {
		echo '<p class="text-muted small mb-2">' . $pending . ' of them '
			. ($pending === 1 ? 'is' : 'are') . ' not visible in public DNS yet. '
			. 'That is normal — it usually takes a few minutes. The checks below go green '
			. 'on their own once it propagates; there is nothing more to do here.</p>';
	}

	echo '<p class="mb-0"><a class="btn btn-sm btn-outline-secondary" href="'
		. htmlspecialchars(DnsPublishBox::urlWith($vars['return_url'], array('dns_show' => '1')))
		. '">Check again</a></p>';
}

/** The primary action: reveal the diff. Nothing is written. */
function dns_publish_box_primary($page, array $vars): void {
	$form = $page->getFormWriter('dns_show_form', array('action' => $vars['return_url']));
	echo $form->begin_form();
	$form->hiddeninput('dns_action', '', array('value' => 'dns_show'));
	$form->hiddeninput('dns_provider', '', array('value' => $vars['provider_key']));
	$form->submitbutton('btn_dns_show', 'Configure DNS at ' . $vars['provider_label']);
	echo $form->end_form();
	echo '<p class="text-muted small mb-0">Shows exactly what would change before anything is written. '
		. 'Reading your live DNS needs no credential — only the write does.</p>';
}

/** The diff: four outcomes, cutovers called out, then Apply. */
function dns_publish_box_diff($page, array $vars): void {
	$rows = $vars['rows'];
	$counts = $vars['counts'];

	$headline = dns_publish_box_headline($vars);
	if ($headline !== '') {
		echo '<p class="mb-2"><strong>' . htmlspecialchars($headline) . '</strong></p>';
	}
	if (!empty($counts[DnsReconciler::UNKNOWN])) {
		echo '<p class="text-muted small mb-2">' . (int)$counts[DnsReconciler::UNKNOWN]
			. ' record' . ($counts[DnsReconciler::UNKNOWN] === 1 ? '' : 's')
			. ' could not be checked — the resolver did not answer.</p>';
	}

	$form = $page->getFormWriter('dns_apply_form', array('action' => $vars['return_url']));
	echo $form->begin_form();
	$form->hiddeninput('dns_action', '', array('value' => 'dns_apply'));
	$form->hiddeninput('dns_provider', '', array('value' => $vars['provider_key']));

	$conflicts = array();
	$cutovers  = array();

	// A DKIM value is longer than any column: the diff scrolls inside its own
	// container rather than pushing the page sideways.
	echo '<div class="table-responsive mb-3">';
	echo '<table class="table table-sm table-bordered mb-0">';
	echo '<thead><tr><th>Action</th><th>Record</th><th>Live now</th><th>What happens</th></tr></thead><tbody>';
	foreach ($rows as $row) {
		$record = $row['record'];
		echo '<tr>';
		echo '<td>' . dns_publish_box_badge($row['outcome']) . '</td>';
		echo '<td><code>' . htmlspecialchars($record->describe()) . '</code>';
		if ($record->note !== '') {
			echo '<div class="text-muted small">' . htmlspecialchars($record->note) . '</div>';
		}
		echo '</td>';
		echo '<td>';
		if (empty($row['live'])) {
			// An em dash, not the word "nothing" — the consequence column
			// already says what an empty slot means, and saying it twice read
			// as a complaint about the zone being empty.
			echo '<span class="text-muted">&mdash;</span>';
		} else {
			$live = array();
			foreach ($row['live'] as $existing) {
				$live[] = $existing->describe();
			}
			echo '<code class="small">' . htmlspecialchars(implode(' · ', $live)) . '</code>';
		}
		echo '</td>';
		echo '<td class="small">' . htmlspecialchars(dns_publish_box_consequence($row)) . '</td>';
		echo '</tr>';

		if ($row['outcome'] === DnsReconciler::CONFLICTS) {
			$conflicts[$row['key']] = $record->describe() . ' — replaces what is there now';
		}
		if (!empty($row['cutover'])) {
			$cutovers[$row['key']] = $row['cutover_note'];
		}
	}
	echo '</tbody></table></div>';

	// The two decisions that are genuinely the operator's, asked once each.
	if (!empty($conflicts)) {
		$form->checkboxList('dns_adopt', 'Records to adopt and overwrite', array(
			'options'   => $conflicts,
			'help_text' => 'A record the platform does not own is never overwritten without this choice. '
				. 'Leave one unticked and it is skipped, not silently changed.',
		));
	}
	if (!empty($cutovers)) {
		$form->checkboxList('dns_cutover', 'Cutovers to confirm', array(
			'options'   => $cutovers,
			'help_text' => 'These changes redirect traffic that already flows.',
		));
	}

	// An API-credential provider collects its key here, at the moment of the
	// write. Nothing entered is stored — not in the session, not sealed.
	foreach ($vars['credential_fields'] as $field => $spec) {
		$options = array(
			'help_text'    => $spec['help'] ?? '',
			'autocomplete' => 'off',
		);
		if (!empty($spec['secret'])) {
			$form->passwordinput('dns_cred_' . $field, $spec['label'] ?? $field, $options);
		} else {
			$form->textinput('dns_cred_' . $field, $spec['label'] ?? $field, $options);
		}
	}

	$form->submitbutton('btn_dns_apply', 'Apply');
	echo $form->end_form();

	echo '<p class="text-muted small mb-0">'
		. ($vars['provider_class'] !== null
			&& $vars['provider_class']::credentialMode() === DnsProvider::CREDENTIAL_OAUTH2
			? 'Apply sends you to ' . htmlspecialchars($vars['provider_label']) . ' to approve this one publish. '
				. 'The grant is used for the write and discarded — nothing is kept.'
			: 'The credential is used for this one publish and discarded when the request returns.')
		. ' Each record is applied on its own: if one fails, the others still land.</p>';
}

/** A one-click account choice, only when a grant reached more than one. */
function dns_publish_box_accounts($page, array $vars): void {
	echo '<div class="alert alert-info"><p class="mb-2">This login reaches more than one account. '
		. 'Choose which one holds the zone:</p>';
	$options = array();
	foreach ($vars['accounts'] as $account) {
		$options[$account['id']] = $account['label'];
	}
	$form = $page->getFormWriter('dns_account_form', array('action' => $vars['return_url']));
	echo $form->begin_form();
	$form->hiddeninput('dns_action', '', array('value' => 'dns_apply'));
	$form->hiddeninput('dns_provider', '', array('value' => $vars['provider_key']));
	$form->dropinput('dns_account', 'Account', array('options' => $options));
	$form->submitbutton('btn_dns_account', 'Publish to this account');
	echo $form->end_form();
	echo '<p class="text-muted small mb-0">The choice applies to this publish only — no account is remembered.</p>';
	echo '</div>';
}

/** Every other driver, behind a link, with no tier labels. */
function dns_publish_box_chooser($page, array $vars): void {
	if (empty($vars['show_chooser'])) {
		echo '<p class="small mb-0"><a href="'
			. htmlspecialchars(DnsPublishBox::urlWith($vars['return_url'], array('dns_choose' => '1')))
			. '">Use another provider</a></p>';
		return;
	}
	$form = $page->getFormWriter('dns_provider_form', array('method' => 'GET', 'action' => $vars['return_url']));
	echo $form->begin_form();
	$form->dropinput('dns_provider', 'DNS provider', array(
		'options' => $vars['provider_options'],
		'value'   => $vars['provider_key'],
	));
	$form->submitbutton('btn_dns_provider', 'Use this provider');
	echo $form->end_form();
}

/** What applying does to this record, said once and in plain terms. */
function dns_publish_box_consequence(array $row): string {
	switch ($row['outcome']) {
		case DnsReconciler::MATCHES:
			return $row['owned']
				? 'Already correct — nothing to do.'
				: 'Already correct. Applying just records it as managed here, with no DNS write.';
		case DnsReconciler::MISSING:
			return 'Created. Nothing here to replace.';
		case DnsReconciler::PENDING:
			return 'Written here already. Waiting for public DNS to catch up.';
		case DnsReconciler::DIFFERS:
			return 'Corrected. This record is managed here and its value has drifted.';
		case DnsReconciler::CONFLICTS:
			return 'Left alone unless you tick it below — this record was not created here.';
		default:
			return 'Not checked. The resolver did not answer, so nothing is claimed either way.';
	}
}

/**
 * The action column reads as work, not as fault.
 *
 * Publishing records into an empty zone is the normal path, so it gets no alarm
 * colour — a warning badge on the thing the page exists to do told the operator
 * something was wrong when nothing was. Only Replace keeps a real colour: it is
 * the one action that destroys something the platform did not create.
 */
function dns_publish_box_badge(string $outcome): string {
	switch ($outcome) {
		case DnsReconciler::MATCHES:   return '<span class="badge badge-subtle-success">Done</span>';
		case DnsReconciler::MISSING:   return '<span class="badge badge-subtle-primary">Add</span>';
		case DnsReconciler::DIFFERS:   return '<span class="badge badge-subtle-primary">Correct</span>';
		case DnsReconciler::CONFLICTS: return '<span class="badge badge-warning">Replace</span>';
		case DnsReconciler::PENDING:   return '<span class="badge badge-subtle-success">Written</span>';
		default:                       return '<span class="badge badge-subtle-secondary">Skipped</span>';
	}
}
