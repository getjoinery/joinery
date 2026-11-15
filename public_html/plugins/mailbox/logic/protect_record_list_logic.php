<?php
/**
 * API action: mailbox/protect_record_list — the records send protection needs,
 * and which are live.
 *
 * POST /api/v1/action/mailbox/protect_record_list (session credential). Param:
 * domain_id. The Setup tab's send-protection ceremony polls it every 30
 * seconds while a person publishes the records by hand
 * (specs/send_protection_one_press.md WP4), and enables Turn on send
 * protection when 'signing_ready' says the activate gate would pass.
 *
 * The answer is mailbox_protect_record_list(): the same rows the page
 * rendered, read from the zone's own nameservers, so a record published a
 * moment ago reads as live. It reads DNS and writes nothing; the activate
 * press checks everything again.
 *
 * Same level as the Setup tab that asks (class restricted).
 *
 * @version 1.1 - the class is declared in the descriptor, where ApiAuth enforces it
 * @version 1.0
 */

function protect_record_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));

	$domain_id = intval($input['domain_id'] ?? 0);
	$domain = $domain_id > 0 ? new InboundEmailDomain($domain_id, TRUE) : null;
	if (!$domain || !$domain->key || $domain->get('ied_delete_time') || $domain->get('ied_is_imap_source')) {
		return LogicResult::error('No such domain.');
	}

	try {
		return LogicResult::render(mailbox_protect_record_list($domain) + array('checked' => gmdate('c')));
	} catch (\Throwable $e) {
		// A lookup that blew up says nothing about the records. The page keeps
		// what it shows and asks again next time.
		error_log('mailbox protect_record_list: domain ' . $domain_id . ' failed: ' . $e->getMessage());
		return LogicResult::error('Could not check the records just now.');
	}
}

function protect_record_list_logic_descriptor(): array {
	return array(
		'description'      => 'The records send protection needs for one domain, in publish order, and which are live (admin only).',
		'requires_session' => true,
		'mutates'          => false,
		// The Setup tab's own class (class=restricted), enforced by ApiAuth.
		'auth'             => array('min_user_permission' => 'restricted:read'),
		'input'            => array(
			'domain_id' => array('type' => 'int', 'required' => true, 'label' => 'Domain'),
		),
	);
}
?>
