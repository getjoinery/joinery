<?php
/**
 * Logic for the Filters tab (Gmail-parity inbound rules).
 *
 * Three jobs: render the list of filters; drive the two-step create/edit flow
 * (criteria -> actions, mirroring Gmail); and handle the single-button enable
 * toggle and delete. Staff-only (permission >= 5). The rule engine itself lives
 * on InboundEmailFilter; this file is just CRUD + the wizard plumbing.
 *
 * The list is scoped to one mailbox at a time (a mailbox, or a domain-wide
 * bucket) via a mailbox picker; create/edit is pre-scoped to that selection.
 * Only mailboxes where filters can fire (locally-stored, non-IMAP) are offered.
 *
 * @see specs/implemented/inbound_email_filters.md
 * @see specs/inbound_email_filter_import.md
 * @version 1.3
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

const FILTER_UNIT_MULTIPLIERS = array('B' => 1, 'KB' => 1024, 'MB' => 1048576);

function admin_mailbox_filters_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$list_url = '/plugins/mailbox/admin/admin_mailbox_filters';
	$op = isset($input['op']) ? (string)$input['op'] : '';

	// Return to the mailbox the operator was viewing after an action.
	$scoped_list = function (string $scope) use ($list_url) {
		return $scope !== '' ? $list_url . '?scope=' . urlencode($scope) : $list_url;
	};
	$return_scope = isset($input['scope']) ? (string)$input['scope'] : '';

	// --- single-button actions: enable toggle + delete ---
	if ($op === 'toggle' && !empty($input['id'])) {
		$f = new InboundEmailFilter(intval($input['id']), TRUE);
		if ($f->key) {
			// Flip the bool directly (no prepare(), so criteria validation does not
			// re-fire on a simple enable/disable).
			$f->set('fil_is_enabled', $f->get('fil_is_enabled') ? false : true);
			$f->save();
		}
		return LogicResult::redirect($scoped_list($return_scope));
	}
	if ($op === 'delete' && !empty($input['id'])) {
		$f = new InboundEmailFilter(intval($input['id']), TRUE);
		if ($f->key) {
			$f->soft_delete();
		}
		_filter_flash($session, 'Filter deleted.', $scoped_list($return_scope));
		return LogicResult::redirect($scoped_list($return_scope));
	}

	$scope = _filter_scope_options();

	// --- import step 1: the upload form ---
	if ($op === 'import') {
		$active_scope = _filter_active_scope($input, $scope['options']);
		return LogicResult::render(array(
			'mode'               => 'import',
			'scope_options'      => $scope['options'],
			'active_scope'       => $active_scope,
			'active_scope_label' => $scope['options'][$active_scope] ?? $active_scope,
			'session'            => $session,
			'settings'           => $settings,
		));
	}

	// --- import step 2: parse the uploaded export, render the preview ---
	if (isset($input['import_upload'])) {
		$active_scope = _filter_active_scope($input, $scope['options']);
		try {
			$xml = _filter_read_upload();
			$candidates = InboundEmailFilter::parseGmailExport($xml);
		} catch (\Throwable $e) {
			return LogicResult::render(array(
				'mode'               => 'import',
				'scope_options'      => $scope['options'],
				'active_scope'       => $active_scope,
				'active_scope_label' => $scope['options'][$active_scope] ?? $active_scope,
				'error'              => $e->getMessage(),
				'session'            => $session,
				'settings'           => $settings,
			));
		}
		return LogicResult::render(array(
			'mode'               => 'import_preview',
			'scope_options'      => $scope['options'],
			'active_scope'       => $active_scope,
			'active_scope_label' => $scope['options'][$active_scope] ?? $active_scope,
			'candidates'         => $candidates,
			'import_xml'         => $xml,
			'session'            => $session,
			'settings'           => $settings,
		));
	}

	// --- import step 3: confirm -> create the checked, importable rows ---
	if (isset($input['save_import'])) {
		$active_scope = _filter_active_scope($input, $scope['options']);
		$xml = (string)($input['import_xml'] ?? '');
		// Only checked boxes post back; their indices are the keys of import_row[].
		$checked = (isset($input['import_row']) && is_array($input['import_row']))
			? array_map('intval', array_keys($input['import_row'])) : array();
		try {
			$summary = _filter_import_confirm($xml, $checked, $active_scope, $scope['alias_domain']);
		} catch (\Throwable $e) {
			return LogicResult::render(array(
				'mode'               => 'import',
				'scope_options'      => $scope['options'],
				'active_scope'       => $active_scope,
				'active_scope_label' => $scope['options'][$active_scope] ?? $active_scope,
				'error'              => $e->getMessage(),
				'session'            => $session,
				'settings'           => $settings,
			));
		}
		_filter_flash($session, $summary, $scoped_list($active_scope));
		return LogicResult::redirect($scoped_list($active_scope));
	}

	// --- save (step 2 submitted) ---
	if (isset($input['save_filter'])) {
		$values = _filter_collect_input($input);
		try {
			$filter = _filter_save($values, $scope['alias_domain']);
			_filter_flash($session, 'Filter saved.', $scoped_list($values['scope']));
			return LogicResult::redirect($scoped_list($values['scope']));
		} catch (\Throwable $e) {
			// Re-render step 2 with the error and the entered values intact.
			return LogicResult::render(array(
				'mode'          => 'form',
				'form_step'     => 2,
				'is_edit'       => !empty($values['id']),
				'form_values'   => $values,
				'scope_options' => $scope['options'],
				'label_options' => _filter_label_options($values['scope']),
				'error'         => $e->getMessage(),
				'session'       => $session,
				'settings'      => $settings,
			));
		}
	}

	// --- step 1 "Continue" submitted -> validate criteria, render step 2 ---
	if (isset($input['continue_btn'])) {
		$values = _filter_collect_input($input);
		$err = _filter_validate_criteria($values);
		if ($err !== null) {
			return LogicResult::render(array(
				'mode'          => 'form',
				'form_step'     => 1,
				'is_edit'       => !empty($values['id']),
				'form_values'   => $values,
				'scope_options' => $scope['options'],
				'error'         => $err,
				'session'       => $session,
				'settings'      => $settings,
			));
		}
		return LogicResult::render(array(
			'mode'          => 'form',
			'form_step'     => 2,
			'is_edit'       => !empty($values['id']),
			'form_values'   => $values,
			'scope_options' => $scope['options'],
			'label_options' => _filter_label_options($values['scope']),
			'session'       => $session,
			'settings'      => $settings,
		));
	}

	// --- new / edit: render step 1 ---
	if ($op === 'new' || $op === 'edit') {
		if ($op === 'edit' && !empty($input['id'])) {
			$values = _filter_values_from_model(new InboundEmailFilter(intval($input['id']), TRUE));
		} else {
			// A new filter is pre-scoped to the mailbox the operator is viewing.
			$values = _filter_blank_values();
			if (!empty($input['scope'])) {
				$values['scope'] = (string)$input['scope'];
			}
		}
		return LogicResult::render(array(
			'mode'          => 'form',
			'form_step'     => 1,
			'is_edit'       => ($op === 'edit'),
			'form_values'   => $values,
			'scope_options' => $scope['options'],
			'scope_label'   => $scope['options'][$values['scope']] ?? $values['scope'],
			'session'       => $session,
			'settings'      => $settings,
		));
	}

	// --- default: the list, scoped to one mailbox (or a domain-wide bucket) ---
	$active_scope = isset($input['scope']) ? (string)$input['scope'] : '';
	if ($active_scope === '' || !isset($scope['options'][$active_scope])) {
		$active_scope = _filter_default_scope($scope['options']);
	}
	return LogicResult::render(array(
		'mode'               => 'list',
		'scope_options'      => $scope['options'],
		'active_scope'       => $active_scope,
		'active_scope_label' => $scope['options'][$active_scope] ?? '',
		'rows'               => _filter_list_rows($active_scope),
		'session'            => $session,
		'settings'           => $settings,
	));
}

/** The mailbox to land on when none is chosen: the first real mailbox, else the first option. */
function _filter_default_scope(array $options): string {
	foreach ($options as $val => $label) {
		if (strncmp($val, 'alias:', 6) === 0) {
			return $val;
		}
	}
	return (string)array_key_first($options);
}

/** Save a DisplayMessage flash for the next page load. */
function _filter_flash(SessionControl $session, string $msg, string $url): void {
	$session->save_message(new DisplayMessage(
		$msg, 'Saved', $url,
		DisplayMessage::MESSAGE_ANNOUNCEMENT,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
}

/**
 * The scope dropdown options — only mailboxes where filters can actually fire.
 * Filters run from InboundEmailRouter::storeMessage, which is reached ONLY for
 * locally-received mail that is stored. So a mailbox qualifies only when it
 * stores (delivery mode store / forward_and_store) AND is not IMAP-backed (IMAP
 * mail is stored via storeExtracted, which has no filter hook). A domain's
 * "All mailboxes in <domain>" bucket appears only when the domain stores
 * locally-received mail (catch-all store, or ≥ 1 qualifying mailbox).
 *
 * Returns ['options'=>[value=>label], 'alias_domain'=>[alias_id=>domain_id]].
 */
function _filter_scope_options(): array {
	$options = array();
	$alias_domain = array();
	$domains = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
	$domains->load();
	foreach ($domains as $domain) {
		$dname = $domain->get('ied_domain');
		$aliases = new MultiInboundEmailAlias(
			array('domain_id' => $domain->key, 'deleted' => false),
			array('iea_alias' => 'ASC')
		);
		$aliases->load();

		$alias_opts = array();
		foreach ($aliases as $alias) {
			if (!_filter_alias_is_filterable($alias)) {
				continue;
			}
			$alias_opts['alias:' . $alias->key] = $alias->get('iea_alias') . '@' . $dname;
			$alias_domain[intval($alias->key)] = intval($domain->key);
		}

		// Domain-wide bucket only if the domain stores locally-received mail.
		$catchall_stores = ($domain->get('ied_catch_all_mode') === InboundEmailDomain::CATCHALL_STORE);
		if ($alias_opts || $catchall_stores) {
			$options['domain:' . $domain->key] = 'All mailboxes in ' . $dname;
		}
		foreach ($alias_opts as $k => $label) {
			$options[$k] = $label;
		}
	}
	return array('options' => $options, 'alias_domain' => $alias_domain);
}

/**
 * Can inbound filters fire for this mailbox? True only when it stores
 * locally-received mail (delivery mode store / forward_and_store) and is NOT
 * IMAP-backed — the exact condition under which storeMessage (and thus the
 * filter hook) runs. Pure-forward mailboxes never store; IMAP-polled mailboxes
 * are stored off the storeExtracted path, which has no filter hook.
 */
function _filter_alias_is_filterable(InboundEmailAlias $alias): bool {
	$mode = $alias->get('iea_delivery_mode');
	if (!in_array($mode, array(InboundEmailAlias::MODE_STORE, InboundEmailAlias::MODE_FORWARD_AND_STORE), true)) {
		return false;
	}
	$feeds = new MultiInboundImapAccount(array('alias_id' => $alias->key, 'deleted' => false));
	$feeds->load();
	return count($feeds) === 0;
}

/**
 * Label options: every custom label (the global label namespace). A label is an ilb_
 * row, not a per-mailbox IMAP folder, so the same list applies to any scope — including
 * a domain-wide bucket. The reader and IMAP sync share these labels. Keyed by label id;
 * returns [] only when no labels exist yet (the view still offers "Create new label…").
 */
function _filter_label_options(string $scopeValue = ''): array {
	$labels = new MultiInboundEmailLabel(
		array('deleted' => false),
		array('ilb_name' => 'ASC')
	);
	$labels->load();
	$options = array();
	foreach ($labels as $label) {
		$options[intval($label->key)] = $label->get('ilb_name');
	}
	return $options;
}

/** A blank value set for a new filter. */
function _filter_blank_values(): array {
	return array(
		'id' => 0, 'scope' => '', 'fil_name' => '',
		'fil_match_from' => '', 'fil_match_to' => '', 'fil_match_subject' => '',
		'fil_match_has_words' => '', 'fil_match_excludes' => '',
		'fil_match_size_op' => '', 'size_value' => '', 'size_unit' => 'MB',
		'fil_match_has_attachment' => false,
		'fil_action_ilb_inbound_email_label_id' => '0', 'fil_action_label_new' => '',
		'fil_action_star' => false, 'fil_action_mark_read' => false,
		'fil_action_archive' => false, 'fil_action_mark_spam' => false,
		'fil_action_never_spam' => false, 'fil_action_delete' => false,
		'fil_action_forward_to' => '', 'apply_existing' => false,
	);
}

/** Read every filter field out of submitted input into the normalized value set. */
function _filter_collect_input(array $input): array {
	$op = isset($input['fil_match_size_op']) && in_array($input['fil_match_size_op'],
		array(InboundEmailFilter::SIZE_OP_GT, InboundEmailFilter::SIZE_OP_LT), true)
		? $input['fil_match_size_op'] : '';
	$unit = isset($input['size_unit']) && isset(FILTER_UNIT_MULTIPLIERS[$input['size_unit']])
		? $input['size_unit'] : 'MB';
	return array(
		'id'    => intval($input['id'] ?? 0),
		'scope' => (string)($input['scope'] ?? ''),
		'fil_name' => trim((string)($input['fil_name'] ?? '')),
		'fil_match_from'      => trim((string)($input['fil_match_from'] ?? '')),
		'fil_match_to'        => trim((string)($input['fil_match_to'] ?? '')),
		'fil_match_subject'   => trim((string)($input['fil_match_subject'] ?? '')),
		'fil_match_has_words' => trim((string)($input['fil_match_has_words'] ?? '')),
		'fil_match_excludes'  => trim((string)($input['fil_match_excludes'] ?? '')),
		'fil_match_size_op'   => $op,
		'size_value'          => trim((string)($input['size_value'] ?? '')),
		'size_unit'           => $unit,
		'fil_match_has_attachment' => !empty($input['fil_match_has_attachment']),
		// Raw selection: a label id, '0' (none), or 'new' (create one inline).
		'fil_action_ilb_inbound_email_label_id' => (string)($input['fil_action_ilb_inbound_email_label_id'] ?? '0'),
		'fil_action_label_new'    => trim((string)($input['fil_action_label_new'] ?? '')),
		'fil_action_star'      => !empty($input['fil_action_star']),
		'fil_action_mark_read' => !empty($input['fil_action_mark_read']),
		'fil_action_archive'   => !empty($input['fil_action_archive']),
		'fil_action_mark_spam' => !empty($input['fil_action_mark_spam']),
		'fil_action_never_spam'=> !empty($input['fil_action_never_spam']),
		'fil_action_delete'    => !empty($input['fil_action_delete']),
		'fil_action_forward_to'=> trim((string)($input['fil_action_forward_to'] ?? '')),
		'apply_existing'       => !empty($input['apply_existing']),
	);
}

/** Reconstruct the editable value set from a stored filter (edit prefill). */
function _filter_values_from_model(InboundEmailFilter $f): array {
	$v = _filter_blank_values();
	if (!$f->key) {
		return $v;
	}
	$v['id'] = intval($f->key);
	$v['scope'] = ($f->get('fil_iea_inbound_email_alias_id') !== null)
		? 'alias:' . intval($f->get('fil_iea_inbound_email_alias_id'))
		: 'domain:' . intval($f->get('fil_ied_inbound_email_domain_id'));
	foreach (array('fil_name', 'fil_match_from', 'fil_match_to', 'fil_match_subject',
			'fil_match_has_words', 'fil_match_excludes', 'fil_action_forward_to') as $col) {
		$v[$col] = (string)$f->get($col);
	}
	foreach (array('fil_match_has_attachment', 'fil_action_star', 'fil_action_mark_read',
			'fil_action_archive', 'fil_action_mark_spam', 'fil_action_never_spam',
			'fil_action_delete') as $col) {
		$v[$col] = (bool)$f->get($col);
	}
	$lid = intval($f->get('fil_action_ilb_inbound_email_label_id'));
	$v['fil_action_ilb_inbound_email_label_id'] = $lid > 0 ? (string)$lid : '0';
	$v['fil_match_size_op'] = (string)$f->get('fil_match_size_op');
	$bytes = intval($f->get('fil_match_size_bytes'));
	if ($bytes > 0) {
		// Show the largest exact unit so 5 MB reads back as 5 MB, not 5242880 B.
		if ($bytes % FILTER_UNIT_MULTIPLIERS['MB'] === 0) { $v['size_unit'] = 'MB'; $v['size_value'] = (string)($bytes / FILTER_UNIT_MULTIPLIERS['MB']); }
		elseif ($bytes % FILTER_UNIT_MULTIPLIERS['KB'] === 0) { $v['size_unit'] = 'KB'; $v['size_value'] = (string)($bytes / FILTER_UNIT_MULTIPLIERS['KB']); }
		else { $v['size_unit'] = 'B'; $v['size_value'] = (string)$bytes; }
	}
	return $v;
}

/** Null if criteria are present, else a human error (the step-1 gate). */
function _filter_validate_criteria(array $v): ?string {
	$hasText = ($v['fil_match_from'] !== '' || $v['fil_match_to'] !== '' || $v['fil_match_subject'] !== ''
		|| $v['fil_match_has_words'] !== '' || $v['fil_match_excludes'] !== '');
	$hasSize = ($v['fil_match_size_op'] !== '' && (float)$v['size_value'] > 0);
	if ($v['scope'] === '') {
		return 'Choose which mailbox this filter applies to.';
	}
	if (!$hasText && !$hasSize && !$v['fil_match_has_attachment']) {
		return 'Add at least one criterion (From, To, Subject, words, size, or attachment).';
	}
	return null;
}

/** Persist a filter from the normalized value set. Throws on validation failure. */
function _filter_save(array $v, array $alias_domain): InboundEmailFilter {
	$filter = $v['id'] ? new InboundEmailFilter(intval($v['id']), TRUE) : new InboundEmailFilter(NULL);

	// Scope -> alias/domain.
	$alias_id = null; $domain_id = null;
	if (strncmp($v['scope'], 'alias:', 6) === 0) {
		$alias_id = intval(substr($v['scope'], 6));
		$domain_id = $alias_domain[$alias_id] ?? null;
		if ($domain_id === null) {
			$alias = new InboundEmailAlias($alias_id, TRUE);
			$domain_id = $alias->key ? intval($alias->get('iea_ied_inbound_email_domain_id')) : null;
		}
	} elseif (strncmp($v['scope'], 'domain:', 7) === 0) {
		$domain_id = intval(substr($v['scope'], 7));
	}
	if (!$domain_id) {
		throw new InboundEmailFilterException('Pick a valid mailbox or domain for this filter.');
	}

	$filter->set('fil_iea_inbound_email_alias_id', $alias_id);
	$filter->set('fil_ied_inbound_email_domain_id', $domain_id);
	$filter->set('fil_name', $v['fil_name'] !== '' ? $v['fil_name'] : null);

	$filter->set('fil_match_from', $v['fil_match_from'] !== '' ? $v['fil_match_from'] : null);
	$filter->set('fil_match_to', $v['fil_match_to'] !== '' ? $v['fil_match_to'] : null);
	$filter->set('fil_match_subject', $v['fil_match_subject'] !== '' ? $v['fil_match_subject'] : null);
	$filter->set('fil_match_has_words', $v['fil_match_has_words'] !== '' ? $v['fil_match_has_words'] : null);
	$filter->set('fil_match_excludes', $v['fil_match_excludes'] !== '' ? $v['fil_match_excludes'] : null);
	$filter->set('fil_match_has_attachment', $v['fil_match_has_attachment']);

	// Size: normalize value + unit to bytes (blank unless a real op + positive value).
	if ($v['fil_match_size_op'] !== '' && (float)$v['size_value'] > 0) {
		$bytes = (int)round((float)$v['size_value'] * FILTER_UNIT_MULTIPLIERS[$v['size_unit']]);
		$filter->set('fil_match_size_op', $v['fil_match_size_op']);
		$filter->set('fil_match_size_bytes', $bytes);
	} else {
		$filter->set('fil_match_size_op', null);
		$filter->set('fil_match_size_bytes', null);
	}

	// Actions. A label is an ilb_ row, not a mailbox-scoped folder, so the apply-label
	// action is valid for every scope, domain-wide buckets included. The selection
	// is a label id, or 'new' to mint a label inline (Gmail's "New label…").
	$filter->set('fil_action_ilb_inbound_email_label_id', _filter_resolve_label($v));
	$filter->set('fil_action_star', $v['fil_action_star']);
	$filter->set('fil_action_mark_read', $v['fil_action_mark_read']);
	$filter->set('fil_action_archive', $v['fil_action_archive']);
	$filter->set('fil_action_mark_spam', $v['fil_action_mark_spam']);
	$filter->set('fil_action_never_spam', $v['fil_action_never_spam']);
	$filter->set('fil_action_delete', $v['fil_action_delete']);
	$filter->set('fil_action_forward_to', $v['fil_action_forward_to'] !== '' ? $v['fil_action_forward_to'] : null);

	// "Also apply to existing": flag for the backfill task and reset its cursor.
	if ($v['apply_existing']) {
		$filter->set('fil_apply_existing_pending', true);
		$filter->set('fil_apply_existing_cursor', 0);
	}

	$filter->prepare();
	$filter->save();
	return $filter;
}

/**
 * Resolve the filter's label selection to a label id (or null). '0'/empty means no
 * label; 'new' find-or-creates a custom label from the typed name; an integer selects an
 * existing label. Returns null when nothing valid is chosen, so an unset action stores
 * cleanly.
 */
function _filter_resolve_label(array $v): ?int {
	$sel = (string)($v['fil_action_ilb_inbound_email_label_id'] ?? '0');
	if ($sel === 'new') {
		$name = trim((string)($v['fil_action_label_new'] ?? ''));
		if ($name === '') {
			return null;
		}
		$label = InboundEmailLabel::findOrCreate($name);
		return $label ? intval($label->key) : null;
	}
	$lid = intval($sel);
	return $lid > 0 ? $lid : null;
}

/**
 * Rows for the list table: the non-deleted filters of a single scope
 * (one mailbox, or a domain-wide bucket), with display summaries.
 */
function _filter_list_rows(string $scope = ''): array {
	$options = array('deleted' => false);
	if (strncmp($scope, 'alias:', 6) === 0) {
		$options['alias_id'] = intval(substr($scope, 6));
	} elseif (strncmp($scope, 'domain:', 7) === 0) {
		// Domain-wide rows for this domain: NULL alias + this domain id.
		$options['domain_wide'] = true;
		$options['domain_id'] = intval(substr($scope, 7));
	}
	$multi = new MultiInboundEmailFilter(
		$options,
		array('fil_order' => 'ASC', 'fil_inbound_email_filter_id' => 'ASC')
	);
	$multi->load();

	$rows = array();
	$alias_cache = array();
	$domain_cache = array();
	foreach ($multi as $f) {
		$aliasId = $f->get('fil_iea_inbound_email_alias_id');
		if ($aliasId !== null) {
			$aid = intval($aliasId);
			if (!isset($alias_cache[$aid])) {
				$a = new InboundEmailAlias($aid, TRUE);
				$alias_cache[$aid] = $a->key ? $a->get_full_address() : ('alias #' . $aid);
			}
			$mailbox = $alias_cache[$aid];
		} else {
			$did = intval($f->get('fil_ied_inbound_email_domain_id'));
			if (!isset($domain_cache[$did])) {
				$d = new InboundEmailDomain($did, TRUE);
				$domain_cache[$did] = $d->key ? ('All mailboxes in ' . $d->get('ied_domain')) : ('domain #' . $did);
			}
			$mailbox = $domain_cache[$did];
		}
		$rows[] = array(
			'id'        => intval($f->key),
			'mailbox'   => $mailbox,
			'name'      => $f->get('fil_name') ?: '(unnamed)',
			'enabled'   => (bool)$f->get('fil_is_enabled'),
			'criteria'  => _filter_criteria_summary($f),
			'actions'   => _filter_action_summary($f),
			'pending'   => (bool)$f->get('fil_apply_existing_pending'),
		);
	}
	return $rows;
}

/** Compact human summary of a filter's criteria for the list. */
function _filter_criteria_summary(InboundEmailFilter $f): array {
	$parts = array();
	if ($f->get('fil_match_from'))      { $parts[] = 'From: ' . $f->get('fil_match_from'); }
	if ($f->get('fil_match_to'))        { $parts[] = 'To: ' . $f->get('fil_match_to'); }
	if ($f->get('fil_match_subject'))   { $parts[] = 'Subject: ' . $f->get('fil_match_subject'); }
	if ($f->get('fil_match_has_words')) { $parts[] = 'Has: ' . $f->get('fil_match_has_words'); }
	if ($f->get('fil_match_excludes'))  { $parts[] = 'Excludes: ' . $f->get('fil_match_excludes'); }
	if ($f->get('fil_match_size_op')) {
		$parts[] = 'Size ' . ($f->get('fil_match_size_op') === 'gt' ? '>' : '<') . ' '
			. number_format((int)$f->get('fil_match_size_bytes')) . ' B';
	}
	if ($f->get('fil_match_has_attachment')) { $parts[] = 'Has attachment'; }
	return $parts;
}

/** Compact human chips for a filter's actions in the list. */
function _filter_action_summary(InboundEmailFilter $f): array {
	$chips = array();
	if ($f->get('fil_action_never_spam')) { $chips[] = 'Never spam'; }
	if ($f->get('fil_action_mark_spam'))  { $chips[] = 'Mark spam'; }
	if ($f->get('fil_action_ilb_inbound_email_label_id')) { $chips[] = 'Label'; }
	if ($f->get('fil_action_star'))       { $chips[] = 'Star'; }
	if ($f->get('fil_action_mark_read'))  { $chips[] = 'Mark read'; }
	if ($f->get('fil_action_archive'))    { $chips[] = 'Archive'; }
	if ($f->get('fil_action_forward_to')) { $chips[] = 'Forward'; }
	if ($f->get('fil_action_delete'))     { $chips[] = 'Delete'; }
	return $chips;
}

// =====================================================================
// Gmail filter import (specs/inbound_email_filter_import.md)
// =====================================================================

/** The active scope from input, falling back to the default mailbox. */
function _filter_active_scope(array $input, array $options): string {
	$s = (string)($input['scope'] ?? '');
	if ($s === '' || !isset($options[$s])) {
		$s = _filter_default_scope($options);
	}
	return $s;
}

/** Read and size-check the uploaded Gmail export, returning its raw XML. */
function _filter_read_upload(): string {
	if (empty($_FILES['import_file']) || !isset($_FILES['import_file']['error'])) {
		throw new InboundEmailFilterException('Choose a Gmail mailFilters.xml file to upload.');
	}
	$f = $_FILES['import_file'];
	if ($f['error'] === UPLOAD_ERR_NO_FILE) {
		throw new InboundEmailFilterException('No file was selected.');
	}
	if ($f['error'] !== UPLOAD_ERR_OK) {
		throw new InboundEmailFilterException('The file did not upload correctly (error code ' . intval($f['error']) . ').');
	}
	if (intval($f['size']) > 1048576) {
		throw new InboundEmailFilterException('That file is too large — the limit is 1 MB.');
	}
	$xml = file_get_contents($f['tmp_name']);
	if ($xml === false || trim($xml) === '') {
		throw new InboundEmailFilterException('The uploaded file is empty.');
	}
	return $xml;
}

/** Resolve a scope value ('alias:N' / 'domain:N') to [alias_id|null, domain_id|null]. */
function _filter_scope_to_ids(string $scope, array $alias_domain): array {
	$alias_id = null; $domain_id = null;
	if (strncmp($scope, 'alias:', 6) === 0) {
		$alias_id = intval(substr($scope, 6));
		$domain_id = $alias_domain[$alias_id] ?? null;
		if ($domain_id === null) {
			$a = new InboundEmailAlias($alias_id, TRUE);
			$domain_id = $a->key ? intval($a->get('iea_ied_inbound_email_domain_id')) : null;
		}
	} elseif (strncmp($scope, 'domain:', 7) === 0) {
		$domain_id = intval(substr($scope, 7));
	}
	return array($alias_id, $domain_id ? intval($domain_id) : null);
}

/**
 * Create the checked, importable candidates as filters scoped to the active
 * mailbox. Re-parses the carried XML (no server-side session state), resolves
 * each candidate's label (find-or-create) on confirm, and skips any candidate
 * whose mapped fields + resolved label already exist in the scope. Returns a
 * human flash summary.
 */
function _filter_import_confirm(string $xml, array $checked, string $scope, array $alias_domain): string {
	$candidates = InboundEmailFilter::parseGmailExport($xml);
	$checkedSet = array_flip($checked);

	list($alias_id, $domain_id) = _filter_scope_to_ids($scope, $alias_domain);
	if (!$domain_id) {
		throw new InboundEmailFilterException('Pick a valid mailbox before importing.');
	}

	// Signatures already present in this scope, for the re-import skip.
	$existing = _filter_existing_signatures($alias_id, $domain_id);

	$created = 0; $newLabels = 0; $dupes = 0;
	foreach ($candidates as $i => $cand) {
		if (empty($cand['importable']) || !isset($checkedSet[$i])) {
			continue;
		}
		// Resolve the label only now (a DB write the parser deliberately deferred).
		$labelId = null;
		if (!empty($cand['label'])) {
			$pre = InboundEmailLabel::getByName($cand['label']);
			$label = InboundEmailLabel::findOrCreate($cand['label']);
			$labelId = $label ? intval($label->key) : null;
			if ($label && !$pre) { $newLabels++; }
		}
		$sig = _filter_signature($cand['fields'], $labelId);
		if (isset($existing[$sig])) {
			$dupes++;
			continue;
		}
		_filter_create_from_candidate($cand, $labelId, $alias_id, $domain_id);
		$existing[$sig] = true; // also collapse exact duplicates within one file
		$created++;
	}

	$summary = 'Imported ' . $created . ' filter' . ($created === 1 ? '' : 's');
	if ($newLabels > 0) {
		$summary .= ' (created ' . $newLabels . ' new label' . ($newLabels === 1 ? '' : 's') . ')';
	}
	if ($dupes > 0) {
		$summary .= '; ' . $dupes . ' already present';
	}
	return $summary . '.';
}

/** Persist one parsed candidate as a filter in the given scope. */
function _filter_create_from_candidate(array $cand, ?int $labelId, ?int $alias_id, int $domain_id): InboundEmailFilter {
	$fields = $cand['fields'];
	$filter = new InboundEmailFilter(NULL);
	$filter->set('fil_iea_inbound_email_alias_id', $alias_id);
	$filter->set('fil_ied_inbound_email_domain_id', $domain_id);
	$filter->set('fil_name', substr((string)$cand['name'], 0, 255));

	foreach (array('fil_match_from', 'fil_match_to', 'fil_match_subject',
			'fil_match_has_words', 'fil_match_excludes', 'fil_action_forward_to') as $c) {
		$filter->set($c, (isset($fields[$c]) && $fields[$c] !== '') ? $fields[$c] : null);
	}
	foreach (array('fil_match_has_attachment', 'fil_action_archive', 'fil_action_mark_read',
			'fil_action_star', 'fil_action_delete', 'fil_action_never_spam') as $c) {
		$filter->set($c, !empty($fields[$c]));
	}
	if (!empty($fields['fil_match_size_op'])) {
		$filter->set('fil_match_size_op', $fields['fil_match_size_op']);
		$filter->set('fil_match_size_bytes', intval($fields['fil_match_size_bytes']));
	}
	$filter->set('fil_action_ilb_inbound_email_label_id', $labelId);

	$filter->prepare();
	$filter->save();
	return $filter;
}

/**
 * A canonical signature over a filter's identity (criteria + actions + resolved
 * label id) for the re-import dedup. Built from the same shape for both candidates
 * and stored models so a re-import of the same export matches and is skipped.
 */
function _filter_signature(array $fields, ?int $labelId): string {
	$sig = array();
	foreach (array('fil_match_from', 'fil_match_to', 'fil_match_subject',
			'fil_match_has_words', 'fil_match_excludes', 'fil_action_forward_to') as $c) {
		$v = trim((string)($fields[$c] ?? ''));
		if ($v !== '') { $sig[$c] = $v; }
	}
	foreach (array('fil_match_has_attachment', 'fil_action_archive', 'fil_action_mark_read',
			'fil_action_star', 'fil_action_delete', 'fil_action_never_spam', 'fil_action_mark_spam') as $c) {
		if (!empty($fields[$c])) { $sig[$c] = 1; }
	}
	if (!empty($fields['fil_match_size_op'])) {
		$sig['size'] = $fields['fil_match_size_op'] . ':' . intval($fields['fil_match_size_bytes'] ?? 0);
	}
	if ($labelId) { $sig['label'] = intval($labelId); }
	ksort($sig);
	return json_encode($sig);
}

/** Signatures of every non-deleted filter already in the given scope. */
function _filter_existing_signatures(?int $alias_id, int $domain_id): array {
	$options = array('deleted' => false);
	if ($alias_id !== null) {
		$options['alias_id'] = $alias_id;
	} else {
		$options['domain_wide'] = true;
		$options['domain_id'] = $domain_id;
	}
	$multi = new MultiInboundEmailFilter($options, array('fil_inbound_email_filter_id' => 'ASC'));
	$multi->load();

	$sigs = array();
	foreach ($multi as $f) {
		$fields = array(
			'fil_match_from'           => (string)$f->get('fil_match_from'),
			'fil_match_to'             => (string)$f->get('fil_match_to'),
			'fil_match_subject'        => (string)$f->get('fil_match_subject'),
			'fil_match_has_words'      => (string)$f->get('fil_match_has_words'),
			'fil_match_excludes'       => (string)$f->get('fil_match_excludes'),
			'fil_action_forward_to'    => (string)$f->get('fil_action_forward_to'),
			'fil_match_has_attachment' => (bool)$f->get('fil_match_has_attachment'),
			'fil_action_archive'       => (bool)$f->get('fil_action_archive'),
			'fil_action_mark_read'     => (bool)$f->get('fil_action_mark_read'),
			'fil_action_star'          => (bool)$f->get('fil_action_star'),
			'fil_action_delete'        => (bool)$f->get('fil_action_delete'),
			'fil_action_never_spam'    => (bool)$f->get('fil_action_never_spam'),
			'fil_action_mark_spam'     => (bool)$f->get('fil_action_mark_spam'),
		);
		if ($f->get('fil_match_size_op')) {
			$fields['fil_match_size_op'] = (string)$f->get('fil_match_size_op');
			$fields['fil_match_size_bytes'] = intval($f->get('fil_match_size_bytes'));
		}
		$lid = intval($f->get('fil_action_ilb_inbound_email_label_id'));
		$sigs[_filter_signature($fields, $lid > 0 ? $lid : null)] = true;
	}
	return $sigs;
}

/** Compact human criteria chips for a parsed candidate (preview table). */
function _filter_candidate_criteria_chips(array $fields): array {
	$chips = array();
	if (!empty($fields['fil_match_from']))      { $chips[] = 'From: ' . $fields['fil_match_from']; }
	if (!empty($fields['fil_match_to']))        { $chips[] = 'To: ' . $fields['fil_match_to']; }
	if (!empty($fields['fil_match_subject']))   { $chips[] = 'Subject: ' . $fields['fil_match_subject']; }
	if (!empty($fields['fil_match_has_words'])) { $chips[] = 'Has: ' . $fields['fil_match_has_words']; }
	if (!empty($fields['fil_match_excludes']))  { $chips[] = 'Excludes: ' . $fields['fil_match_excludes']; }
	if (!empty($fields['fil_match_size_op'])) {
		$chips[] = 'Size ' . ($fields['fil_match_size_op'] === 'gt' ? '>' : '<') . ' '
			. number_format(intval($fields['fil_match_size_bytes'] ?? 0)) . ' B';
	}
	if (!empty($fields['fil_match_has_attachment'])) { $chips[] = 'Has attachment'; }
	return $chips;
}

/** Compact human action chips for a parsed candidate, excluding the label. */
function _filter_candidate_action_chips(array $fields): array {
	$chips = array();
	if (!empty($fields['fil_action_never_spam'])) { $chips[] = 'Never spam'; }
	if (!empty($fields['fil_action_star']))       { $chips[] = 'Star'; }
	if (!empty($fields['fil_action_mark_read']))  { $chips[] = 'Mark read'; }
	if (!empty($fields['fil_action_archive']))    { $chips[] = 'Archive'; }
	if (!empty($fields['fil_action_forward_to'])) { $chips[] = 'Forward: ' . $fields['fil_action_forward_to']; }
	if (!empty($fields['fil_action_delete']))     { $chips[] = 'Delete'; }
	return $chips;
}
?>
