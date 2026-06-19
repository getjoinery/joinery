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
 * @version 1.2
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

const FILTER_UNIT_MULTIPLIERS = array('B' => 1, 'KB' => 1024, 'MB' => 1048576);

function admin_inbound_email_filters_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_filter_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_folder_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$list_url = '/plugins/inbound_email/admin/admin_inbound_email_filters';
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
 * Label options for a scope value: the tracked IMAP folders of the chosen
 * mailbox. Empty for a domain-wide scope (apply-label is per-mailbox; see the
 * spec's open decision) or a mailbox with no IMAP feed (pure local store).
 */
function _filter_label_options(string $scopeValue): array {
	if (strncmp($scopeValue, 'alias:', 6) !== 0) {
		return array();
	}
	$aliasId = intval(substr($scopeValue, 6));
	if ($aliasId <= 0) {
		return array();
	}
	$accounts = new MultiInboundImapAccount(array('alias_id' => $aliasId, 'deleted' => false));
	$accounts->load();
	$options = array();
	foreach ($accounts as $account) {
		$folders = new MultiInboundImapFolder(
			array('account_id' => $account->key, 'tracked' => true),
			array('iif_name' => 'ASC')
		);
		$folders->load();
		foreach ($folders as $folder) {
			$options[intval($folder->key)] = $folder->get('iif_name');
		}
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
		'fil_action_label_id' => 0,
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
		'fil_action_label_id'  => intval($input['fil_action_label_id'] ?? 0),
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
	$v['fil_action_label_id'] = intval($f->get('fil_action_label_id'));
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

	// Actions. Apply-label is per-mailbox: a domain-wide filter never labels (the
	// spec's open decision (a) — domain-wide filters do flag/spam/forward/delete).
	$filter->set('fil_action_label_id', ($alias_id !== null && $v['fil_action_label_id'] > 0)
		? $v['fil_action_label_id'] : null);
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
	if ($f->get('fil_action_label_id'))   { $chips[] = 'Label'; }
	if ($f->get('fil_action_star'))       { $chips[] = 'Star'; }
	if ($f->get('fil_action_mark_read'))  { $chips[] = 'Mark read'; }
	if ($f->get('fil_action_archive'))    { $chips[] = 'Archive'; }
	if ($f->get('fil_action_forward_to')) { $chips[] = 'Forward'; }
	if ($f->get('fil_action_delete'))     { $chips[] = 'Delete'; }
	return $chips;
}
?>
