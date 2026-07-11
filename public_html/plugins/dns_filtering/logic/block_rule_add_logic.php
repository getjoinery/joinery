<?php
/**
 * Add a custom domain rule to a block.
 *
 * Input: block_id (or device_id — resolves to that device's always-on
 * block), hostname, action (0=block, 1=allow), optional hard_block.
 *
 * hard_block marks the rule for client-side connection-level enforcement
 * (the apps' tunnel/VPN layers); the DNS resolver ignores it. It is only
 * settable on block-action rules belonging to the always-on block — the
 * tunnel syncs a static hostname list with no scheduler, so a hard-block
 * rule on a time-windowed scheduled block would be enforced 24/7 at the
 * connection level while staying scheduled at the DNS level.
 *
 * The web editor's page JS calls
 * POST /api/v1/action/dns_filtering/block_rule_add.
 *
 * @version 1.0
 */

function block_rule_add_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_blocks_class.php'));

	$session = SessionControl::get_instance();

	if (!$session->get_user_id()) {
		return LogicResult::error('Not logged in.');
	}

	$block_id = isset($input['block_id']) ? (int)$input['block_id'] : 0;
	// Callers that only know the device (e.g. the domain-test quick-add button) can pass device_id
	// and the logic resolves to that device's always-on block.
	$device_id = isset($input['device_id']) ? (int)$input['device_id'] : 0;
	// Accept either the new `hostname`/`action` keys or the legacy `sdr_hostname`/`sdr_action` keys.
	$hostname = isset($input['hostname']) ? trim($input['hostname']) : (isset($input['sdr_hostname']) ? trim($input['sdr_hostname']) : '');
	$action_raw = $input['action'] ?? $input['sdr_action'] ?? null;
	$action = ($action_raw === null) ? -1 : (int)$action_raw;
	$hard_block = !empty($input['hard_block']);

	if ($hostname === '') {
		return LogicResult::error('Missing hostname.');
	}

	if ($action !== 0 && $action !== 1) {
		return LogicResult::error('Invalid action.');
	}

	if (!$block_id && !$device_id) {
		return LogicResult::error('Missing block_id or device_id.');
	}

	// Resolve block — either directly or via device's always-on block
	if ($block_id) {
		try {
			$block = new SdScheduledBlock($block_id, TRUE);
		} catch (Exception $e) {
			return LogicResult::error('Block not found.');
		}
	}
	else {
		$block = SdScheduledBlock::getOrCreateAlwaysOnBlock($device_id);
	}

	$device = new SdDevice($block->get('sdb_sdd_device_id'), TRUE);
	if ($device->get('sdd_usr_user_id') != $session->get_user_id() && $session->get_permission() < 5) {
		return LogicResult::error('Not authorized.');
	}

	// Feature gate
	if (!SubscriptionTier::getUserFeature($session->get_user_id(), 'dns_filtering_scrolldaddy_custom_rules', false)) {
		return LogicResult::error('Custom rules are available on Premium and Pro plans.');
	}

	// Hard block: block-action rules on the always-on block only
	if ($hard_block) {
		if ($action !== 0) {
			return LogicResult::error('Hard block is only available on block rules.');
		}
		if (!$block->get('sdb_is_always_on')) {
			return LogicResult::error('Hard block is only available on always-on block rules.');
		}
	}

	$rule = $block->add_rule($hostname, $action, $hard_block);
	if (!$rule) {
		return LogicResult::error('Invalid hostname.');
	}

	return LogicResult::render(array(
		'rule_id' => $rule->key,
		'hostname' => $rule->get('sbr_hostname'),
		'action' => (int)$rule->get('sbr_action'),
		'action_label' => $rule->get('sbr_action') == 1 ? 'Allow' : 'Block',
		'hard_block' => (bool)$rule->get('sbr_hard_block'),
	));
}

function block_rule_add_logic_descriptor(): array {
	return [
		'description' => 'Add a custom domain rule to a block (block_id or device_id, hostname, action 0=block/1=allow, optional hard_block)',
		'mutates'     => true,
		'auth'        => ['requires_session' => true],
		'input'       => [
			'block_id'   => ['type' => 'int',    'required' => false, 'label' => 'Block ID'],
			'device_id'  => ['type' => 'int',    'required' => false, 'label' => 'Device ID'],
			'hostname'   => ['type' => 'string', 'required' => false, 'label' => 'Hostname'],
			'action'     => ['type' => 'int',    'required' => false, 'label' => '0=block, 1=allow'],
			'hard_block' => ['type' => 'bool',   'required' => false, 'label' => 'Hard block'],
		],
	];
}

?>
