<?php
/**
 * Set or clear a single filter/service rule on a scheduled block.
 * Used by the always-on editor for save-on-change UX so users don't
 * have to scroll to a Save button.
 *
 * POST: block_id, type ('filter'|'service'), key, action ('0'|'1'|'')
 *   action '' = remove the row entirely (Allow on always-on means "no row";
 *   see the resolver-merge note in scheduled_block_edit.php).
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_blocks_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_block_filters_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_block_services_class.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/includes/ScrollDaddyHelper.php'));

$session = SessionControl::get_instance();

if (!$session->get_user_id()) {
	echo json_encode(['success' => false, 'error' => 'Not logged in.']);
	exit;
}

$block_id = isset($_POST['block_id']) ? (int)$_POST['block_id'] : 0;
$type = $_POST['type'] ?? '';
$key = $_POST['key'] ?? '';
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

if (!$block_id || !in_array($type, ['filter', 'service'], true) || $key === '') {
	echo json_encode(['success' => false, 'error' => 'Missing or invalid parameters.']);
	exit;
}

if ($action !== '' && $action !== '0' && $action !== '1') {
	echo json_encode(['success' => false, 'error' => 'Invalid action.']);
	exit;
}

try {
	$block = new SdScheduledBlock($block_id, TRUE);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'error' => 'Block not found.']);
	exit;
}

$device = new SdDevice($block->get('sdb_sdd_device_id'), TRUE);
if ($device->get('sdd_usr_user_id') != $session->get_user_id() && $session->get_permission() < 5) {
	echo json_encode(['success' => false, 'error' => 'Not authorized.']);
	exit;
}

// Validate the key against the canonical lists so users can't write arbitrary
// rows that the resolver would ignore but pollute the table.
if ($type === 'filter') {
	if (!isset(ScrollDaddyHelper::$filters[$key])) {
		echo json_encode(['success' => false, 'error' => 'Unknown filter key.']);
		exit;
	}
	// Tier-gate advanced filters: only writes/changes are blocked. Removing a row
	// is allowed (option-C escape hatch for downgraded users — see editor docs).
	if (in_array($key, ScrollDaddyHelper::getRestrictedFilters(), true) && $action !== '') {
		if (!SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_advanced_filters', false)) {
			echo json_encode(['success' => false, 'error' => 'Advanced filters require Premium or Pro.']);
			exit;
		}
	}
}
else {
	$service_known = false;
	foreach (ScrollDaddyHelper::$services as $items) {
		if (isset($items[$key])) { $service_known = true; break; }
	}
	if (!$service_known) {
		echo json_encode(['success' => false, 'error' => 'Unknown service key.']);
		exit;
	}
}

if ($type === 'filter') {
	$existing = new MultiSdScheduledBlockFilter([
		'block_id' => $block->key,
		'filter_key' => $key,
	]);
	$existing->load();
	$row = ($existing->count() > 0) ? $existing->get(0) : null;

	if ($action === '') {
		if ($row) { $row->permanent_delete(); }
	}
	else {
		if (!$row) {
			$row = new SdScheduledBlockFilter(NULL);
			$row->set('sbf_sdb_scheduled_block_id', $block->key);
			$row->set('sbf_filter_key', $key);
		}
		$row->set('sbf_action', (int)$action);
		$row->save();
	}
}
else {
	$existing = new MultiSdScheduledBlockService([
		'block_id' => $block->key,
		'service_key' => $key,
	]);
	$existing->load();
	$row = ($existing->count() > 0) ? $existing->get(0) : null;

	if ($action === '') {
		if ($row) { $row->permanent_delete(); }
	}
	else {
		if (!$row) {
			$row = new SdScheduledBlockService(NULL);
			$row->set('sbs_sdb_scheduled_block_id', $block->key);
			$row->set('sbs_service_key', $key);
		}
		$row->set('sbs_action', (int)$action);
		$row->save();
	}
}

echo json_encode(['success' => true]);
