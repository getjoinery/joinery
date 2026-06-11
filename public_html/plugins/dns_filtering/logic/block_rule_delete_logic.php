<?php
/**
 * Delete a custom domain rule from a block.
 *
 * Input: rule_id. Ownership is verified through the rule → block → device
 * chain.
 *
 * Called from ajax/block_rule_delete.php (web editor) and exposed as
 * POST /api/v1/action/dns_filtering/block_rule_delete.
 *
 * @version 1.0
 */

function block_rule_delete_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_blocks_class.php'));

	$session = SessionControl::get_instance();

	if (!$session->get_user_id()) {
		return LogicResult::error('Not logged in.');
	}

	$rule_id = isset($input['rule_id']) ? (int)$input['rule_id'] : 0;

	if (!$rule_id) {
		return LogicResult::error('Missing rule_id.');
	}

	// Load rule → block → device → check ownership
	try {
		$rule = new SdScheduledBlockRule($rule_id, TRUE);
	} catch (Exception $e) {
		return LogicResult::error('Rule not found.');
	}

	$block = new SdScheduledBlock($rule->get('sbr_sdb_scheduled_block_id'), TRUE);
	$device = new SdDevice($block->get('sdb_sdd_device_id'), TRUE);

	if ($device->get('sdd_usr_user_id') != $session->get_user_id() && $session->get_permission() < 5) {
		return LogicResult::error('Not authorized.');
	}

	// Feature gate (delete is also gated, mirroring add)
	if (!SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_custom_rules', false)) {
		return LogicResult::error('Custom rules are available on Premium and Pro plans.');
	}

	$block->delete_rule($rule_id);

	return LogicResult::render(array());
}

function block_rule_delete_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Delete a custom domain rule (rule_id)',
	];
}

?>
