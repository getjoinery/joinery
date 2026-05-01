<?php
/**
 * Add a custom domain rule to a block.
 * POST: block_id, hostname, action (0=block, 1=allow)
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

$block_id = isset($_POST['block_id']) ? (int)$_POST['block_id'] : 0;
// Callers that only know the device (e.g. the domain-test quick-add button) can pass device_id
// and the endpoint resolves to that device's always-on block.
$device_id = isset($_POST['device_id']) ? (int)$_POST['device_id'] : 0;
// Accept either the new `hostname`/`action` keys or the legacy `sdr_hostname`/`sdr_action` keys.
$hostname = isset($_POST['hostname']) ? trim($_POST['hostname']) : (isset($_POST['sdr_hostname']) ? trim($_POST['sdr_hostname']) : '');
$action_raw = $_POST['action'] ?? $_POST['sdr_action'] ?? null;
$action = ($action_raw === null) ? -1 : (int)$action_raw;

if ($hostname === '') {
	echo json_encode(['success' => false, 'error' => 'Missing hostname.']);
	exit;
}

if ($action !== 0 && $action !== 1) {
	echo json_encode(['success' => false, 'error' => 'Invalid action.']);
	exit;
}

if (!$block_id && !$device_id) {
	echo json_encode(['success' => false, 'error' => 'Missing block_id or device_id.']);
	exit;
}

// Resolve block — either directly or via device's always-on block
if ($block_id) {
	try {
		$block = new SdScheduledBlock($block_id, TRUE);
	} catch (Exception $e) {
		echo json_encode(['success' => false, 'error' => 'Block not found.']);
		exit;
	}
}
else {
	$block = SdScheduledBlock::getOrCreateAlwaysOnBlock($device_id);
}

$device = new SdDevice($block->get('sdb_sdd_device_id'), TRUE);
if ($device->get('sdd_usr_user_id') != $session->get_user_id() && $session->get_permission() < 5) {
	echo json_encode(['success' => false, 'error' => 'Not authorized.']);
	exit;
}

// Feature gate
if (!SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_custom_rules', false)) {
	echo json_encode(['success' => false, 'error' => 'Custom rules are available on Premium and Pro plans.']);
	exit;
}

$rule = $block->add_rule($hostname, $action);
if (!$rule) {
	echo json_encode(['success' => false, 'error' => 'Invalid hostname.']);
	exit;
}

echo json_encode([
	'success' => true,
	'rule_id' => $rule->key,
	'hostname' => $rule->get('sbr_hostname'),
	'action' => (int)$rule->get('sbr_action'),
	'action_label' => $rule->get('sbr_action') == 1 ? 'Allow' : 'Block',
]);
