<?php
/**
 * Shared Filters panel — the list, the two-step create/edit wizard, and the
 * Gmail import flow.
 *
 * One rendering for both surfaces, so the admin tab and the member page at
 * /profile/mailbox/filters cannot drift apart. They differ only in which
 * mailboxes the picker offers, and that difference is decided in
 * mailbox_filters_logic.php, not here.
 *
 * Everything is FormWriter, and every mutation is a POST: Edit is the one GET,
 * because it only opens the form.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_filters_logic.php'));

if (!function_exists('mailbox_render_filters_panel')) {

/**
 * @param object $page  AdminPage or PublicPage — anything with getFormWriter()
 * @param array  $vars  the logic's render vars (mode + whatever that mode needs)
 * @param string $base  this mount's own URL; every link and form action hangs off it
 */
function mailbox_render_filters_panel($page, array $vars, string $base): void {
	$mode = (string)($vars['mode'] ?? 'list');
	$pageoptions = array();

	if (!empty($vars['error'])) {
		echo '<div class="alert alert-danger">' . htmlspecialchars($vars['error']) . '</div>';
	}

	if ($mode === 'form') {
		mailbox_render_filter_form($page, $vars, $base, $pageoptions);
	} elseif ($mode === 'import') {
		mailbox_render_filter_import($page, $vars, $base, $pageoptions);
	} elseif ($mode === 'import_preview') {
		mailbox_render_filter_import_preview($page, $vars, $base, $pageoptions);
	} else {
		mailbox_render_filter_list($page, $vars, $base, $pageoptions);
	}
}

/** Create / edit: step 1 is the criteria, step 2 the actions (Gmail's two dialogs). */
function mailbox_render_filter_form($page, array $vars, string $base, array $pageoptions): void {
	$is_edit = !empty($vars['is_edit']);
	$v = $vars['form_values'];
	$step = intval($vars['form_step'] ?? 1);

	$pageoptions['title'] = ($is_edit ? 'Edit filter' : 'New filter') . ' — Step ' . $step . ' of 2: '
		. ($step === 1 ? 'Criteria' : 'Actions');
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('form1');
	echo $formwriter->begin_form();

	if ($step === 1) {
		$formwriter->hiddeninput('id', '', array('value' => $v['id']));
		// Scope is fixed to the mailbox that was picked (or the filter's own
		// mailbox on edit) — carried as a hidden field, shown as read-only context.
		$formwriter->hiddeninput('scope', '', array('value' => $v['scope']));
		echo '<p class="filter-scope-context"><strong>Filter for:</strong> '
			. htmlspecialchars($vars['scope_label'] ?? $v['scope']) . '</p>';

		$formwriter->textinput('fil_name', 'Filter name', array(
			'value' => $v['fil_name'],
			'helptext' => 'Optional label so you remember what this rule is for.',
		));

		$formwriter->textinput('fil_match_from', 'From', array(
			'value' => $v['fil_match_from'],
			'helptext' => 'Matches the sender. Separate multiple addresses with commas (any one matches).',
		));
		$formwriter->textinput('fil_match_to', 'To', array(
			'value' => $v['fil_match_to'],
			'helptext' => 'Matches the recipient. Commas are OR.',
		));
		$formwriter->textinput('fil_match_subject', 'Subject', array(
			'value' => $v['fil_match_subject'],
		));
		$formwriter->textinput('fil_match_has_words', 'Has the words', array(
			'value' => $v['fil_match_has_words'],
			'helptext' => 'Every word must appear somewhere in the sender, subject, or body.',
		));
		$formwriter->textinput('fil_match_excludes', "Doesn't have", array(
			'value' => $v['fil_match_excludes'],
			'helptext' => 'The message must contain none of these words.',
		));

		$formwriter->dropinput('fil_match_size_op', 'Size', array(
			'options' => array('' => 'Any size', 'gt' => 'Greater than', 'lt' => 'Less than'),
			'value' => $v['fil_match_size_op'],
			'visibility_rules' => array(
				'gt' => array('show' => array('size_value', 'size_unit'), 'hide' => array()),
				'lt' => array('show' => array('size_value', 'size_unit'), 'hide' => array()),
				''   => array('show' => array(), 'hide' => array('size_value', 'size_unit')),
			),
		));
		$formwriter->numberinput('size_value', 'Size value', array('value' => $v['size_value']));
		$formwriter->dropinput('size_unit', 'Size unit', array(
			'options' => array('B' => 'Bytes', 'KB' => 'KB', 'MB' => 'MB'),
			'value' => $v['size_unit'],
		));

		$formwriter->checkboxinput('fil_match_has_attachment', 'Has attachment', array(
			'checked' => $v['fil_match_has_attachment'],
		));

		$formwriter->submitbutton('continue_btn', 'Continue');
	} else {
		// Step 2 carries every step-1 value as a hidden input so the save sees the
		// whole filter in one post.
		$carry = array('id', 'scope', 'fil_name', 'fil_match_from', 'fil_match_to',
			'fil_match_subject', 'fil_match_has_words', 'fil_match_excludes',
			'fil_match_size_op', 'size_value', 'size_unit');
		foreach ($carry as $k) {
			$formwriter->hiddeninput($k, '', array('value' => $v[$k]));
		}
		if (!empty($v['fil_match_has_attachment'])) {
			$formwriter->hiddeninput('fil_match_has_attachment', '', array('value' => '1'));
		}

		// Apply a label — a custom label (ilb_) in the global namespace, shared with the
		// reader and IMAP sync. "Create new label…" reveals a name field and mints the
		// label on save (Gmail's inline "New label…").
		$formwriter->dropinput('fil_action_ilb_inbound_email_label_id', 'Apply the label', array(
			'options' => array('0' => '— none —') + ($vars['label_options'] ?? array()) + array('new' => 'Create new label…'),
			'value' => (string)$v['fil_action_ilb_inbound_email_label_id'],
			'visibility_rules' => array(
				'new'     => array('show' => array('fil_action_label_new'), 'hide' => array()),
				'default' => array('show' => array(), 'hide' => array('fil_action_label_new')),
			),
		));
		$formwriter->textinput('fil_action_label_new', 'New label name', array(
			'value' => $v['fil_action_label_new'],
			'helptext' => 'Creates this label and applies it.',
		));

		$formwriter->checkboxinput('fil_action_star', 'Star it', array('checked' => $v['fil_action_star']));
		$formwriter->checkboxinput('fil_action_mark_read', 'Mark as read', array('checked' => $v['fil_action_mark_read']));
		$formwriter->checkboxinput('fil_action_archive', 'Skip the Inbox (Archive it)', array('checked' => $v['fil_action_archive']));
		$formwriter->checkboxinput('fil_action_mark_spam', 'Mark it as spam', array('checked' => $v['fil_action_mark_spam']));
		$formwriter->checkboxinput('fil_action_never_spam', 'Never send it to Spam', array('checked' => $v['fil_action_never_spam']));
		$formwriter->textinput('fil_action_forward_to', 'Forward it to', array(
			'value' => $v['fil_action_forward_to'],
			'helptext' => 'A single email address. Historical mail is never re-forwarded.',
		));
		// On a domain that seals its mail, forwarding sends it back out in clear
		// text. That is allowed, but only once it has been said so in writing — and
		// the consent lapses whenever the domain's security level is raised.
		if (!empty($vars['forward_ack_domain'])) {
			$formwriter->checkboxinput('fil_forward_ack',
				InboundEmailFilter::forwardAcknowledgmentText(
					$v['fil_action_forward_to'] !== '' ? $v['fil_action_forward_to'] : 'the address above',
					$vars['forward_ack_domain']),
				array(
					'checked'  => $v['fil_forward_ack'],
					'helptext' => 'Required only while a forwarding address is set — leave the '
						. 'address blank and this is ignored. Raising this domain security level '
						. 'clears it, and forwarding stops until you confirm again.',
				));
		}
		$formwriter->checkboxinput('fil_action_delete', 'Delete it', array('checked' => $v['fil_action_delete']));

		$formwriter->checkboxinput('apply_existing', 'Also apply this filter to matching existing mail', array(
			'checked' => $v['apply_existing'],
			'helptext' => 'Runs in the background. Forwarding is not applied to existing mail.',
		));

		$formwriter->submitbutton('save_filter', $is_edit ? 'Save filter' : 'Create filter');
	}

	echo $formwriter->end_form();
	echo '<p><a href="' . htmlspecialchars($base) . '">Cancel</a></p>';
	$page->end_box();
}

/** Import step 1: the Gmail mailFilters.xml upload form. */
function mailbox_render_filter_import($page, array $vars, string $base, array $pageoptions): void {
	$active_scope = (string)$vars['active_scope'];

	$pageoptions['title'] = 'Import filters from Gmail';
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('import_form', array('enctype' => 'multipart/form-data'));
	echo $formwriter->begin_form();
	// No hidden op needed: the import_upload submit name drives the next step
	// (the logic checks the import submits before the op=import render branch,
	// since the form's action URL still carries ?op=import).
	$formwriter->hiddeninput('scope', '', array('value' => $active_scope));

	echo '<p><strong>Import into:</strong> ' . htmlspecialchars($vars['active_scope_label']) . '</p>';

	$formwriter->fileinput('import_file', 'Gmail mailFilters.xml', array(
		'helptext' => 'In Gmail: Settings → Filters and Blocked Addresses → Export. Upload the downloaded file here.',
	));
	$formwriter->submitbutton('import_upload', 'Upload and preview');
	echo $formwriter->end_form();

	echo '<p><a href="' . htmlspecialchars($base . '?scope=' . urlencode($active_scope)) . '">Cancel</a></p>';
	$page->end_box();
}

/** Import step 2: what the export would create, with each row tickable. */
function mailbox_render_filter_import_preview($page, array $vars, string $base, array $pageoptions): void {
	$active_scope = (string)$vars['active_scope'];
	$candidates = (array)$vars['candidates'];

	$importable = 0;
	foreach ($candidates as $c) { if (!empty($c['importable'])) { $importable++; } }

	$pageoptions['title'] = 'Import preview — ' . $importable . ' of ' . count($candidates) . ' importable';
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('import_confirm');
	echo $formwriter->begin_form();
	$formwriter->hiddeninput('scope', '', array('value' => $active_scope));
	$formwriter->hiddeninput('import_xml', '', array('value' => $vars['import_xml']));

	echo '<p><strong>Import into:</strong> ' . htmlspecialchars($vars['active_scope_label'])
		. '. New labels are created on confirm. Importable rows are checked by default.</p>';

	echo '<table class="table"><thead><tr>'
		. '<th>Import</th><th>Name</th><th>Criteria</th><th>Actions</th><th>Skipped</th>'
		. '</tr></thead><tbody>';
	foreach ($candidates as $i => $c) {
		echo '<tr>';

		echo '<td>';
		if (!empty($c['importable'])) {
			$formwriter->checkboxinput('import_row[' . intval($i) . ']', '', array(
				'checked' => true,
				'id' => 'import_row_' . intval($i),
			));
		} else {
			echo '<span title="Needs at least one criterion and one action">—</span>';
		}
		echo '</td>';

		echo '<td>' . htmlspecialchars($c['name']) . '</td>';

		echo '<td>';
		foreach (_filter_candidate_criteria_chips($c['fields']) as $chip) {
			echo '<div><small>' . htmlspecialchars($chip) . '</small></div>';
		}
		echo '</td>';

		echo '<td>';
		foreach (_filter_candidate_action_chips($c['fields']) as $chip) {
			echo '<span class="badge">' . htmlspecialchars($chip) . '</span> ';
		}
		if (!empty($c['label'])) {
			echo '<span class="badge">label: ' . htmlspecialchars($c['label']) . '</span> ';
		}
		echo '</td>';

		echo '<td>';
		if (!empty($c['skipped'])) {
			echo '<small>' . htmlspecialchars(implode(', ', $c['skipped'])) . '</small>';
		}
		echo '</td>';

		echo '</tr>';
	}
	echo '</tbody></table>';

	$formwriter->submitbutton('save_import', 'Create ' . $importable . ' filter' . ($importable === 1 ? '' : 's'));
	echo $formwriter->end_form();

	echo '<p><a href="' . htmlspecialchars($base . '?scope=' . urlencode($active_scope)) . '">Cancel</a></p>';
	$page->end_box();
}

/** The list: one mailbox at a time, picked from the scope dropdown. */
function mailbox_render_filter_list($page, array $vars, string $base, array $pageoptions): void {
	$scope_options = (array)($vars['scope_options'] ?? array());
	$active_scope = (string)($vars['active_scope'] ?? '');
	$rows = (array)($vars['rows'] ?? array());
	$operator = !empty($vars['operator']);

	$pageoptions['title'] = 'Filters';
	$page->begin_box($pageoptions);

	if (empty($scope_options)) {
		echo $operator
			? '<p>No mailboxes yet. Add a mailbox under the Accounts tab first; filters are managed per mailbox.</p>'
			: '<p>None of your mailboxes can run filters. Filters act on mail this site receives and stores '
				. 'for you; a mailbox that only forwards, or one polled from another provider, is filtered there.</p>';
		$page->end_box();
		return;
	}

	// Mailbox picker — filters are managed one mailbox at a time. A FormWriter
	// dropdown whose option values are scope URLs and whose onchange navigates to
	// the chosen mailbox's filters, so there is no separate submit button. "All
	// mailboxes in …" entries are the domain-wide buckets.
	$scope_url_options = array();
	foreach ($scope_options as $val => $label) {
		$scope_url_options[$base . '?scope=' . urlencode($val)] = $label;
	}
	$scopeForm = $page->getFormWriter('scope_form', array('action' => $base, 'method' => 'GET', 'csrf' => false));
	echo $scopeForm->begin_form();
	$scopeForm->dropinput('scope_nav', 'Mailbox', array(
		'options' => $scope_url_options,
		'value' => $base . '?scope=' . urlencode($active_scope),
		'onchange' => 'window.location.href=this.value;',
		'helptext' => $operator
			? 'Filters apply to this mailbox. "All mailboxes in …" is a domain-wide rule.'
			: 'Filters apply to this mailbox as its mail arrives.',
	));
	echo $scopeForm->end_form();

	echo '<p><a class="btn btn-primary" href="'
		. htmlspecialchars($base . '?op=new&scope=' . urlencode($active_scope))
		. '">Create filter for this mailbox</a> '
		. '<a class="btn btn-secondary" href="'
		. htmlspecialchars($base . '?op=import&scope=' . urlencode($active_scope))
		. '">Import filters</a></p>';

	if (empty($rows)) {
		echo '<p>No filters for <strong>' . htmlspecialchars((string)($vars['active_scope_label'] ?? ''))
			. '</strong> yet. Filters run on locally-received mail (forwarded/stored mailboxes) as it arrives.</p>';
		$page->end_box();
		return;
	}

	echo '<table class="table"><thead><tr>'
		. '<th>Name</th><th>Criteria</th><th>Actions</th><th>Status</th><th></th>'
		. '</tr></thead><tbody>';
	foreach ($rows as $r) {
		echo '<tr>';
		echo '<td>' . htmlspecialchars($r['name']) . '</td>';

		echo '<td>';
		foreach ($r['criteria'] as $c) {
			echo '<div><small>' . htmlspecialchars($c) . '</small></div>';
		}
		echo '</td>';

		echo '<td>';
		foreach ($r['actions'] as $a) {
			echo '<span class="badge">' . htmlspecialchars($a) . '</span> ';
		}
		if ($r['pending']) {
			echo '<div><small><em>Backfill pending</em></small></div>';
		}
		echo '</td>';

		echo '<td>' . ($r['enabled'] ? 'Enabled' : 'Disabled') . '</td>';

		echo '<td>';
		// One Actions menu per row, the kit's <details> dropdown so it behaves the
		// same under any theme. Edit is a GET link; Disable/Enable and Delete are
		// single-button FormWriter posts whose submit buttons are the menu items.
		echo '<details class="jy-ui jy-actions-dropdown">';
		echo '<summary class="btn btn-soft-default btn-sm">Actions</summary>';
		echo '<div class="jy-actions-menu">';

		echo '<a href="' . htmlspecialchars($base . '?op=edit&id=' . $r['id']) . '">Edit</a>';

		$toggle = $page->getFormWriter('toggle_' . $r['id'], array('action' => $base, 'method' => 'POST'));
		echo $toggle->begin_form();
		$toggle->hiddeninput('op', '', array('value' => 'toggle'));
		$toggle->hiddeninput('id', '', array('value' => $r['id']));
		$toggle->hiddeninput('scope', '', array('value' => $active_scope));
		$toggle->submitbutton('toggle_btn', $r['enabled'] ? 'Disable' : 'Enable');
		echo $toggle->end_form();

		$del = $page->getFormWriter('delete_' . $r['id'], array('action' => $base, 'method' => 'POST'));
		echo $del->begin_form();
		$del->hiddeninput('op', '', array('value' => 'delete'));
		$del->hiddeninput('id', '', array('value' => $r['id']));
		$del->hiddeninput('scope', '', array('value' => $active_scope));
		$del->submitbutton('delete_btn', 'Delete', array(
			'class' => 'jy-action-danger',
			'onclick' => "return confirm('Delete this filter?');",
		));
		echo $del->end_form();

		echo '</div></details>';
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';

	$page->end_box();
}

}
?>
