<?php
/**
 * Delete a custom domain rule from a block.
 * POST: rule_id
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_blocks_class.php'));

$session = SessionControl::get_instance();

if (!$session->get_user_id()) {
	echo json_encode(['success' => false, 'error' => 'Not logged in.']);
	exit;
}

$rule_id = isset($_POST['rule_id']) ? (int)$_POST['rule_id'] : 0;

if (!$rule_id) {
	echo json_encode(['success' => false, 'error' => 'Missing rule_id.']);
	exit;
}

// Load rule → block → device → check ownership
try {
	$rule = new SdScheduledBlockRule($rule_id, TRUE);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'error' => 'Rule not found.']);
	exit;
}

$block = new SdScheduledBlock($rule->get('sbr_sdb_scheduled_block_id'), TRUE);
$device = new SdDevice($block->get('sdb_sdd_device_id'), TRUE);

if ($device->get('sdd_usr_user_id') != $session->get_user_id() && $session->get_permission() < 5) {
	echo json_encode(['success' => false, 'error' => 'Not authorized.']);
	exit;
}

// Feature gate (delete is also gated, mirroring add)
if (!SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_custom_rules', false)) {
	echo json_encode(['success' => false, 'error' => 'Custom rules are available on Premium and Pro plans.']);
	exit;
}

$block->delete_rule($rule_id);

echo json_encode(['success' => true]);
