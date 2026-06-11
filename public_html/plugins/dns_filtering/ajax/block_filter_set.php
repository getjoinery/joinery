<?php
/**
 * Set or clear a single filter/service rule on a scheduled block.
 * Used by the always-on editor for save-on-change UX so users don't
 * have to scroll to a Save button.
 *
 * POST: block_id, type ('filter'|'service'), key, action ('0'|'1'|'')
 *   action '' = remove the row entirely (Allow on always-on means "no row";
 *   see the resolver-merge note in scheduled_block_edit.php).
 *
 * Thin wrapper over block_filter_set_logic() — one copy of the rules,
 * shared with POST /api/v1/action/dns_filtering/block_filter_set.
 *
 * @version 2.0
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/block_filter_set_logic.php'));

$result = block_filter_set_logic($_POST);

if ($result->error) {
	echo json_encode(['success' => false, 'error' => $result->error]);
	exit;
}

echo json_encode(['success' => true]);
exit;
