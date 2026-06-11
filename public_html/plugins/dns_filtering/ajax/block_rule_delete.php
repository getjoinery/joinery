<?php
/**
 * Delete a custom domain rule from a block.
 * POST: rule_id
 *
 * Thin wrapper over block_rule_delete_logic() — one copy of the rules,
 * shared with POST /api/v1/action/dns_filtering/block_rule_delete.
 *
 * @version 2.0
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/block_rule_delete_logic.php'));

$result = block_rule_delete_logic($_POST);

if ($result->error) {
	echo json_encode(['success' => false, 'error' => $result->error]);
	exit;
}

echo json_encode(['success' => true]);
exit;
