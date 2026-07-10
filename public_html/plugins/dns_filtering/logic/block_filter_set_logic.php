<?php
/**
 * Set or clear a single filter/service rule on a scheduled block.
 * Used by the always-on editor (and the apps' save-on-change editors) so
 * users don't have to scroll to a Save button.
 *
 * Input: block_id, type ('filter'|'service'), key, action ('0'|'1'|'')
 *   action '' = remove the row entirely (Allow on always-on means "no row";
 *   see the resolver-merge note in scheduled_block_edit.php).
 *
 * Called from ajax/block_filter_set.php (web editor) and exposed as
 * POST /api/v1/action/dns_filtering/block_filter_set.
 *
 * @version 1.0
 */

function block_filter_set_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_blocks_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_block_filters_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_block_services_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/includes/ScrollDaddyHelper.php'));

	$session = SessionControl::get_instance();

	if (!$session->get_user_id()) {
		return LogicResult::error('Not logged in.');
	}

	$block_id = isset($input['block_id']) ? (int)$input['block_id'] : 0;
	$type = $input['type'] ?? '';
	$key = $input['key'] ?? '';
	$action = isset($input['action']) ? (string)$input['action'] : '';

	if (!$block_id || !in_array($type, ['filter', 'service'], true) || $key === '') {
		return LogicResult::error('Missing or invalid parameters.');
	}

	if ($action !== '' && $action !== '0' && $action !== '1') {
		return LogicResult::error('Invalid action.');
	}

	try {
		$block = new SdScheduledBlock($block_id, TRUE);
	} catch (Exception $e) {
		return LogicResult::error('Block not found.');
	}

	$device = new SdDevice($block->get('sdb_sdd_device_id'), TRUE);
	if ($device->get('sdd_usr_user_id') != $session->get_user_id() && $session->get_permission() < 5) {
		return LogicResult::error('Not authorized.');
	}

	// Validate the key against the canonical lists so users can't write arbitrary
	// rows that the resolver would ignore but pollute the table.
	if ($type === 'filter') {
		if (!isset(ScrollDaddyHelper::$filters[$key])) {
			return LogicResult::error('Unknown filter key.');
		}
		// Tier-gate advanced filters: only writes/changes are blocked. Removing a row
		// is allowed (option-C escape hatch for downgraded users — see editor docs).
		if (in_array($key, ScrollDaddyHelper::getRestrictedFilters(), true) && $action !== '') {
			if (!SubscriptionTier::getUserFeature($session->get_user_id(), 'dns_filtering_scrolldaddy_advanced_filters', false)) {
				return LogicResult::error('Advanced filters require Premium or Pro.');
			}
		}
	}
	else {
		$service_known = false;
		foreach (ScrollDaddyHelper::$services as $items) {
			if (isset($items[$key])) { $service_known = true; break; }
		}
		if (!$service_known) {
			return LogicResult::error('Unknown service key.');
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

	return LogicResult::render(array());
}

function block_filter_set_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Set or clear one filter/service toggle on a block (block_id, type filter/service, key, action 0/1/empty-to-remove)',
	];
}

?>
