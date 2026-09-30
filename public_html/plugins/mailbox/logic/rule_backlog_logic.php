<?php
/**
 * API action: mailbox/rule_backlog — the next page of "also apply to existing
 * mail" on the caller's end-to-end rows, which only their device can read
 * (specs/fortress_mobile_apps.md § R14, MailboxDeviceRules::backlog()).
 *
 * POST /api/v1/action/mailbox/rule_backlog (browser session or app session
 * key). No input. Returns {rule: {id, match, forwards} | null, rows: [{id,
 * recipient, size_bytes, has_attachment, sealed: {key, sealed_dek,
 * sealed_ad_prefix, iem_sender, iem_subject, iem_body_plain, iem_body_html,
 * iem_recipient?}}], through_id}. `rule: null` means there is no work.
 *
 * @version 1.0
 */

function rule_backlog_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	return LogicResult::render(MailboxDeviceRules::backlog($user_id));
}

function rule_backlog_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_person_credential' => true),
		// Closing a finished rule's walk is bookkeeping on the caller's own rule.
		'mutates' => true,
		'description' => 'The next page of applying a mail rule to the caller\'s existing end-to-end mail: {rule, rows, through_id}; rule is null when there is nothing to do',
		'input' => [],
	);
}
?>
