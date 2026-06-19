<?php
/**
 * Inbound Email - Filters (Gmail-parity inbound rules).
 *
 * The list of filters, plus the two-step create/edit wizard (criteria ->
 * actions) that mirrors Gmail's "Create a filter" dialog. All forms are
 * FormWriter; the rule engine lives on InboundEmailFilter.
 *
 * The list is scoped to one mailbox at a time via a mailbox dropdown (navigates
 * on change); create/edit is pre-scoped to that mailbox.
 *
 * @see specs/implemented/inbound_email_filters.md
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_filters_logic.php'));

$page_vars = process_logic(admin_inbound_email_filters_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$base = '/plugins/inbound_email/admin/admin_inbound_email_filters';

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
		'Filters' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Filters');

if (!empty($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

if (($mode ?? 'list') === 'form') {
	// ----------------------------------------------------------- create / edit
	$is_edit = !empty($is_edit);
	$v = $form_values;
	$step = intval($form_step ?? 1);

	$pageoptions['title'] = ($is_edit ? 'Edit filter' : 'New filter') . ' — Step ' . $step . ' of 2: '
		. ($step === 1 ? 'Criteria' : 'Actions');
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('form1');
	echo $formwriter->begin_form();

	if ($step === 1) {
		$formwriter->hiddeninput('id', '', array('value' => $v['id']));
		// Scope is fixed to the mailbox the operator picked (or the filter's own
		// mailbox on edit) — carried as a hidden field, shown as read-only context.
		$formwriter->hiddeninput('scope', '', array('value' => $v['scope']));
		echo '<p class="filter-scope-context"><strong>Filter for:</strong> '
			. htmlspecialchars($scope_label ?? $v['scope']) . '</p>';

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

		if (!empty($label_options)) {
			$formwriter->dropinput('fil_action_label_id', 'Apply the label', array(
				'options' => array('0' => '— none —') + $label_options,
				'value' => (string)$v['fil_action_label_id'],
			));
		}

		$formwriter->checkboxinput('fil_action_star', 'Star it', array('checked' => $v['fil_action_star']));
		$formwriter->checkboxinput('fil_action_mark_read', 'Mark as read', array('checked' => $v['fil_action_mark_read']));
		$formwriter->checkboxinput('fil_action_archive', 'Skip the Inbox (Archive it)', array('checked' => $v['fil_action_archive']));
		$formwriter->checkboxinput('fil_action_mark_spam', 'Mark it as spam', array('checked' => $v['fil_action_mark_spam']));
		$formwriter->checkboxinput('fil_action_never_spam', 'Never send it to Spam', array('checked' => $v['fil_action_never_spam']));
		$formwriter->textinput('fil_action_forward_to', 'Forward it to', array(
			'value' => $v['fil_action_forward_to'],
			'helptext' => 'A single email address. Historical mail is never re-forwarded.',
		));
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
} else {
	// ----------------------------------------------------------------- list
	$pageoptions['title'] = 'Filters';
	$page->begin_box($pageoptions);

	if (empty($scope_options)) {
		echo '<p>No mailboxes yet. Add a mailbox under the Accounts tab first; filters are managed per mailbox.</p>';
		$page->end_box();
		$page->admin_footer();
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
		'helptext' => 'Filters apply to this mailbox. "All mailboxes in …" is a domain-wide rule.',
	));
	echo $scopeForm->end_form();

	echo '<p><a class="btn btn-primary" href="'
		. htmlspecialchars($base . '?op=new&scope=' . urlencode($active_scope))
		. '">Create filter for this mailbox</a></p>';

	if (empty($rows)) {
		echo '<p>No filters for <strong>' . htmlspecialchars($active_scope_label)
			. '</strong> yet. Filters run on locally-received mail (forwarded/stored mailboxes) as it arrives.</p>';
	} else {
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
			// Edit link + enable toggle + delete, the latter two as single-button
			// FormWriter action forms (POST, CSRF-handled).
			echo '<a href="' . htmlspecialchars($base . '?op=edit&id=' . $r['id']) . '">Edit</a> ';

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
			$del->submitbutton('delete_btn', 'Delete', array('onclick' => "return confirm('Delete this filter?');"));
			echo $del->end_form();

			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	$page->end_box();
}

$page->admin_footer();
?>
