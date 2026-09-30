<?php
/**
 * API action: mailbox/rule_outcomes — which rows of a rule_backlog page the
 * rule matched, as the caller's device found (specs/fortress_mobile_apps.md
 * § R14, MailboxDeviceRules::outcomes()). The server applies the rule's own
 * actions to those rows (never a forward: existing mail is not re-sent) and
 * moves the rule's walk past the page.
 *
 * POST /api/v1/action/mailbox/rule_outcomes (browser session or app session
 * key). Input: rule_id, through_id (the page's through_id), matched_ids (JSON
 * array or array of row ids). Returns {applied, cursor}.
 *
 * @version 1.0
 */

function rule_outcomes_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	$ids = $input['matched_ids'] ?? array();
	if (!is_array($ids)) {
		$ids = json_decode((string)$ids, true);
		$ids = is_array($ids) ? $ids : array();
	}
	try {
		return LogicResult::render(MailboxDeviceRules::outcomes($user_id, intval($input['rule_id'] ?? 0),
			intval($input['through_id'] ?? 0), $ids));
	} catch (MailboxDeviceRulesException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function rule_outcomes_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_person_credential' => true),
		'mutates' => true,
		'description' => 'Apply a mail rule to the rows of a rule_backlog page the caller\'s device found it matched, and move the rule\'s walk past the page: {applied, cursor}',
		'input' => [
			'rule_id' => ['type' => 'int', 'required' => true, 'label' => 'Rule ID'],
			'through_id' => ['type' => 'int', 'required' => true, 'label' => 'The page\'s last row ID'],
			'matched_ids' => ['type' => 'text', 'required' => false, 'max_length' => 20000, 'label' => 'JSON array: the page\'s row IDs the rule matched'],
		],
	);
}
?>
