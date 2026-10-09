<?php
/**
 * A test node's backup storage: a backup target of the test's own and the
 * node's active storage space on it, both removed after the run.
 *
 * A node backs up to a target only through a storage space
 * (specs/storage_targets.md), so a test whose node needs "its backup target"
 * gives it one here rather than depending on whichever target the site it runs
 * on happens to have. Nothing contacts the bucket: the tests that use this
 * stand in for every read and write.
 *
 * @version 1.0
 */

function sm_test_node_space(ManagedNode $node, string $provider = 'b2'): StorageSpace {
	$target = new BackupTarget(NULL);
	$target->set('bkt_name', 'HarnessTest Target ' . bin2hex(random_bytes(3)));
	$target->set('bkt_provider', $provider);
	$target->set('bkt_bucket', 'harness-test-bucket');
	$target->set('bkt_enabled', true);
	$target->set('bkt_credentials', json_encode(array('key_id' => 'k', 'application_key' => 'a')));
	$target->save();
	harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $target->key);
	$space = StorageSpace::open($target, StorageSpace::OWNER_NODE, (int)$node->key, (string)$node->get('mgn_slug'));
	harness_register_row('sps_storage_spaces', 'sps_storage_space_id', $space->key);
	return $space;
}
