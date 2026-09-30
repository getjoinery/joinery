<?php
/**
 * API action: mailbox/device_rules — one of the caller's mailboxes' mail rules
 * as a device evaluates them (specs/fortress_mobile_apps.md § R14): the
 * criteria of each enabled rule of the mailbox and its domain, in order, and
 * whether a match needs the opened message posted with it (a forward). The
 * device that parses a relay-sealed row runs them and posts the matching ids
 * with the parse (fortress_parse_store `rule_matches`).
 *
 * POST /api/v1/action/mailbox/device_rules (browser session or app session key).
 * Input: alias_id. Returns {alias_id, rules: [{id, match: {from, to, subject,
 * has_words, excludes, size_op, size_bytes, has_attachment}, forwards}]}.
 *
 * @version 1.0
 */

function device_rules_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	try {
		return LogicResult::render(MailboxDeviceRules::rulesFor($user_id, intval($input['alias_id'] ?? 0)));
	} catch (MailboxDeviceRulesException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function device_rules_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_person_credential' => true),
		'mutates' => false,
		'description' => 'One of the caller\'s mailboxes\' enabled mail rules, as a device evaluates them on end-to-end mail it opens: {alias_id, rules: [{id, match, forwards}]}',
		'input' => [
			'alias_id' => ['type' => 'int', 'required' => true, 'label' => 'Mailbox alias ID'],
		],
	);
}
?>
