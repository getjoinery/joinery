<?php
/**
 * Add a custom domain rule to a block.
 * POST: block_id (or device_id), hostname, action (0=block, 1=allow),
 * optional hard_block.
 *
 * Thin wrapper over block_rule_add_logic() — one copy of the rules, shared
 * with POST /api/v1/action/dns_filtering/block_rule_add.
 *
 * @version 2.0
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/block_rule_add_logic.php'));

$result = block_rule_add_logic($_POST);

if ($result->error) {
	echo json_encode(['success' => false, 'error' => $result->error]);
	exit;
}

echo json_encode(array_merge(['success' => true], $result->data));
exit;
