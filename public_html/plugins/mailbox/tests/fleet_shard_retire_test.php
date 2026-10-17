<?php
/** @joinery-test
 * name: fleet_shard_retire
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Retiring a fleet shard (MailboxFleetShard::retire()): the shard and its
 * managed node leave together, so a retired shard's node is never left on
 * the dashboard being monitored.
 *
 *  - Refused while a tenant holds a live slot on the shard; an evicted or
 *    released slot does not hold it.
 *  - Refused while the shard's birth run is still in flight.
 *  - Retiring soft-deletes the shard and hides its node (kept, restorable).
 *  - A node already hidden is left as it is; a shard with no node retires.
 *  - Retiring twice is refused.
 *
 * Creates scratch node/shard/slot/run rows and deletes them.
 *
 * Run: php plugins/mailbox/tests/fleet_shard_retire_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$suffix = getmypid() . '-' . random_int(1000, 9999);

function fsr_node(string $label) {
	global $suffix;
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest FSR ' . $label);
	$node->set('mgn_slug', 'fsr-' . strtolower($label) . '-' . $suffix);
	$node->set('mgn_host', '198.51.100.72');
	$node->set('mgn_enabled', true);
	$node->prepare();
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

function fsr_shard(string $label, $node = null) {
	$shard = new MailboxFleetShard(NULL);
	$shard->set('mfs_name', 'fsr-' . $label);
	$shard->set('mfs_capacity', 5);
	if ($node !== null) {
		$shard->set('mfs_mgn_managed_node_id', intval($node->key));
	}
	$shard->save();
	$shard->load();
	harness_register_row('mfs_mailbox_fleet_shards', 'mfs_mailbox_fleet_shard_id', $shard->key);
	return $shard;
}

function fsr_refused($shard, string $needle): bool {
	try {
		$shard->retire();
	} catch (MailboxFleetShardException $e) {
		return stripos($e->getMessage(), $needle) !== false;
	}
	return false;
}

function fsr_fresh($model) {
	$class = get_class($model);
	return new $class($model->key, TRUE);
}

section('refused while a tenant holds a slot');
$node = fsr_node('main');
$shard = fsr_shard('main', $node);
$slot = new MailboxFleetSlot(NULL);
$slot->set('mft_mfs_mailbox_fleet_shard_id', intval($shard->key));
$slot->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
$slot->save();
harness_register_row('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', $slot->key);
check(fsr_refused($shard, 'Move them to another shard'), 'a live slot refuses the retire, naming what to do first');
check(!fsr_fresh($shard)->get('mfs_delete_time') && !fsr_fresh($node)->get('mgn_delete_time'),
	'a refused retire changes nothing');

$slot->set('mft_status', 'released');
$slot->save();

section('refused while the shard is being born');
$run = new RelayCloudProvision(NULL);
$run->set('rcl_kind', 'provision');
$run->set('rcl_status', 'awaiting_grant');
$run->set('rcl_mfs_mailbox_fleet_shard_id', intval($shard->key));
$run->save();
harness_register_row('rcl_relay_cloud_provisions', 'rcl_relay_cloud_provision_id', $run->key);
check(fsr_refused($shard, 'still being born'), 'a live birth run refuses the retire');
$run->set('rcl_status', 'done');
$run->save();

section('retire: the shard and its node leave together');
$notes = $shard->retire();
check((bool)fsr_fresh($shard)->get('mfs_delete_time'), 'the shard is soft-deleted');
$after = fsr_fresh($node);
check($after->key && (bool)$after->get('mgn_delete_time'), 'its node is hidden from the dashboard, and kept');
check((bool)array_filter($notes, function ($n) { return stripos($n, 'hidden from the dashboard') !== false; }),
	'the notes say the node was hidden');
check((bool)array_filter($notes, function ($n) { return stripos($n, 'server was not touched') !== false; }),
	'the notes say the server is the operator\'s to destroy');
check(fsr_refused(fsr_fresh($shard), 'already retired'), 'retiring twice is refused');

section('a node already hidden, and a shard with no node');
$hidden = fsr_node('hidden');
$hidden->hide();
$hidden_at = (string)fsr_fresh($hidden)->get('mgn_delete_time');
$shard2 = fsr_shard('hidden', fsr_fresh($hidden));
$notes2 = $shard2->retire();
check((bool)fsr_fresh($shard2)->get('mfs_delete_time'), 'a shard whose node is already hidden retires');
check((string)fsr_fresh($hidden)->get('mgn_delete_time') === $hidden_at, 'the hidden node is left as it is');
check(!array_filter($notes2, function ($n) { return stripos($n, 'hidden from the dashboard') !== false; }),
	'nothing claims to have hidden it again');

$bare = fsr_shard('bare');
$bare->retire();
check((bool)fsr_fresh($bare)->get('mfs_delete_time'), 'a shard with no node retires');

harness_finish();
