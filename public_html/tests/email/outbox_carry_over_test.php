<?php
/** @joinery-test
 * name: outbox_carry_over
 * tier: test-db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/migrations/migrations.php, plugins/store/migrations/migrations.php, plugins/store/includes/SubscriptionEmails.php]
 */
/**
 * The send-once records each feature kept in its own column move to the
 * outbox, and the columns go (specs/email_outbox.md §9). On the test
 * database, each old column is put back with a mark in it, its migration is
 * run, and the outbox must then hold the same keys, so the feature's next
 * pass sends nothing:
 *
 *  - sm_039: cvp_buyer_notices, every key a Sent row about the site;
 *  - store_002: a receipt mark on the anchor line, a reminder mark on every
 *    line that has one, keyed in SubscriptionEmails::period_key()'s form;
 *  - sm_040: itx_email_times, in all three branches (no table; the new column
 *    absent, renamed in place; both present, copied), leaving only the
 *    finish's three acts, and no duplicate rows when run again.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$run = substr(bin2hex(random_bytes(4)), 0, 8);
EmailSender::$capture = function () { return true; };
harness_defer(function () { EmailSender::$capture = null; });

$migration = function (string $plugin, string $id) {
	foreach (require PathHelper::getIncludePath('plugins/' . $plugin . '/migrations/migrations.php') as $m) {
		if ($m['id'] === $id) {
			return $m['up'];
		}
	}
	throw new RuntimeException('no migration ' . $id);
};
$column = function (string $table, string $col) use ($db) {
	$q = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?');
	$q->execute(array($table, $col));
	return (bool)$q->fetchColumn();
};
$keys = function (string $type, int $id) use ($db) {
	$q = $db->prepare("SELECT equ_once_key FROM equ_queued_emails WHERE equ_entity_type = ? AND equ_entity_id = ?
		AND equ_once_key IS NOT NULL AND equ_status = 4 AND equ_service = 'carried' ORDER BY 1");
	$q->execute(array($type, $id));
	return $q->fetchAll(PDO::FETCH_COLUMN);
};
$owner = make_user('CarryOwner');
$site = function (string $slug) use ($owner) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'buyer');
	$p->set('cvp_usr_user_id', (int)$owner->key);
	$p->set('cvp_domain', $slug . '.example.com');
	$p->set('cvp_slug', $slug);
	$p->set('cvp_status', 'failed');
	$p->set('cvp_hosting_mode', 'operator');
	$p->set('cvp_buyer_email', $slug . '@example.com');
	$p->set('cvp_external_order_item_id', 870000000 + random_int(0, 99999));
	$p->save();
	$p->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
	return $p;
};

try {
	// ------------------------------------------------------------------
	section('sm_039: what a buyer was told');
	if (!$column('cvp_customer_cloud_provisions', 'cvp_buyer_notices')) {
		$db->exec('ALTER TABLE cvp_customer_cloud_provisions ADD COLUMN cvp_buyer_notices text');
	}
	$p = $site('carry-hbn-' . $run);
	$db->prepare('UPDATE cvp_customer_cloud_provisions SET cvp_buyer_notices = ? WHERE cvp_customer_cloud_provision_id = ?')
		->execute(array(json_encode(array('plan:grace:2026-12-01 00:00:00', 'setup:failed')), (int)$p->key));
	$migration('server_manager', 'sm_039_buyer_notices_to_outbox')(DbConnector::get_instance());
	check($keys('cvp_customer_cloud_provisions', (int)$p->key) === array('plan:grace:2026-12-01 00:00:00', 'setup:failed'),
		'each told key is a Sent row about the site', json_encode($keys('cvp_customer_cloud_provisions', (int)$p->key)));
	check(!$column('cvp_customer_cloud_provisions', 'cvp_buyer_notices'), 'and the column is gone');
	check(EmailSender::sendTemplateOnce($p, 'setup:failed', 'hosting_setup_failed', 'x@example.com', array()) === false,
		'the next pass sends nothing for a key already told');

	// ------------------------------------------------------------------
	section('store_002: the receipt and reminder marks');
	foreach (array('odi_receipted_invoice_id' => 'varchar(64)', 'odi_renewal_reminded_for' => 'timestamp(6)') as $col => $type) {
		if (!$column('odi_order_items', $col)) {
			$db->exec("ALTER TABLE odi_order_items ADD COLUMN $col $type");
		}
	}
	$buyer = make_user('CarryStore');
	$period_end = '2026-12-20 08:30:00';
	$lines = array();
	foreach (array(0, 1, 2) as $i) {
		$item = new OrderItem(NULL);
		$item->set('odi_ord_order_id', 0);
		$item->set('odi_pro_product_id', 999999002);
		$item->set('odi_usr_user_id', $buyer->key);
		$item->set('odi_price', '5.00');
		$item->set('odi_is_subscription', true);
		$item->set('odi_subscription_status', $i === 0 ? 'cancelled' : 'active');
		$item->set('odi_subscription_period_end', $period_end);
		$item->save();
		$item->load();
		$lines[] = $item;
	}
	// The oldest line is cancelled, so the due query's anchor is the second.
	$db->prepare('UPDATE odi_order_items SET odi_receipted_invoice_id = ? WHERE odi_order_item_id = ?')
		->execute(array('in_carry_' . $run, (int)$lines[0]->key));
	$db->prepare("UPDATE odi_order_items SET odi_renewal_reminded_for = ?::timestamp + interval '0.123456 seconds' WHERE odi_order_item_id IN (?, ?, ?)")
		->execute(array($period_end, (int)$lines[0]->key, (int)$lines[1]->key, (int)$lines[2]->key));
	$migration('store', 'store_002_subscription_email_marks_to_outbox')(DbConnector::get_instance());
	$renewal = 'renewal:' . SubscriptionEmails::period_key($period_end);
	check(in_array('receipt:in_carry_' . $run, $keys('odi_order_items', (int)$lines[0]->key), true), 'the receipt mark is a row about its line');
	check(in_array($renewal, $keys('odi_order_items', (int)$lines[1]->key), true)
		&& in_array($renewal, $keys('odi_order_items', (int)$lines[2]->key), true),
		'the reminder mark is on every line that had one, in the key period_key() reads back (R34, R58)',
		json_encode($keys('odi_order_items', (int)$lines[1]->key)));
	check(in_array($renewal, EmailSender::onceKeys($lines[1], 'renewal:'), true),
		'so the subscription whose oldest line is cancelled is still not reminded again');
	check(!$column('odi_order_items', 'odi_receipted_invoice_id') && !$column('odi_order_items', 'odi_renewal_reminded_for'),
		'and both columns are gone');

	// ------------------------------------------------------------------
	section('sm_040: the transfer emails, in each of its branches');
	$up = $migration('server_manager', 'sm_040_transfer_emails_to_outbox');
	$tp = $site('carry-itx-' . $run);
	$t = new InstanceTransfer(NULL);
	$t->set('itx_cvp_customer_cloud_provision_id', (int)$tp->key);
	$t->set('itx_instance_id', '1');
	$t->set('itx_state', 'done');
	$t->save();
	$t->load();
	harness_register_row('itx_instance_transfers', 'itx_instance_transfer_id', $t->key);
	$marks = json_encode(array('invite' => '2026-11-01 10:00:00', 'done' => '2026-11-02 10:00:00', 'node_note' => '2026-11-02 10:00:01',
		'done_alert' => '2026-11-02 10:00:02'));
	$sent_rows = function () use ($db, $t) {
		$q = $db->prepare("SELECT equ_template, equ_once_key, equ_sent_time FROM equ_queued_emails
			WHERE equ_entity_type = 'itx_instance_transfers' AND equ_entity_id = ? ORDER BY equ_template");
		$q->execute(array((int)$t->key));
		return $q->fetchAll(PDO::FETCH_ASSOC);
	};
	$acts = function () use ($db, $t) {
		return json_decode((string)$db->query('SELECT itx_done_acts FROM itx_instance_transfers WHERE itx_instance_transfer_id = '
			. (int)$t->key)->fetchColumn(), true);
	};

	$db->exec('ALTER TABLE itx_instance_transfers RENAME TO itx_instance_transfers_carry_aside');
	$up(DbConnector::get_instance());
	$db->exec('ALTER TABLE itx_instance_transfers_carry_aside RENAME TO itx_instance_transfers');
	check(true, 'with no table, it does nothing');

	$db->exec('ALTER TABLE itx_instance_transfers DROP COLUMN IF EXISTS itx_done_acts');
	$db->exec('ALTER TABLE itx_instance_transfers ADD COLUMN itx_email_times jsonb');
	$db->prepare('UPDATE itx_instance_transfers SET itx_email_times = ?::jsonb WHERE itx_instance_transfer_id = ?')
		->execute(array($marks, (int)$t->key));
	$up(DbConnector::get_instance());
	$rows = $sent_rows();
	check(count($rows) === 2 && $rows[0]['equ_template'] === 'instance_transfer_done' && $rows[0]['equ_once_key'] === 'done'
		&& $rows[1]['equ_template'] === 'instance_transfer_invite' && $rows[1]['equ_once_key'] === null
		&& substr((string)$rows[1]['equ_sent_time'], 0, 19) === '2026-11-01 10:00:00',
		'the plugin inactive (no new column): each email a Sent row at its own time, keyed done only for done', json_encode($rows));
	check($column('itx_instance_transfers', 'itx_done_acts') && !$column('itx_instance_transfers', 'itx_email_times')
		&& array_keys((array)$acts()) === array('node_note', 'done_alert'), 'the column renamed in place, holding only the acts',
		json_encode($acts()));

	$db->exec('ALTER TABLE itx_instance_transfers ADD COLUMN itx_email_times jsonb');
	$db->prepare('UPDATE itx_instance_transfers SET itx_email_times = ?::jsonb, itx_done_acts = NULL WHERE itx_instance_transfer_id = ?')
		->execute(array(json_encode(array('done' => '2026-11-02 10:00:00', 'stuck_alert' => '2026-11-02 09:00:00')), (int)$t->key));
	$up(DbConnector::get_instance());
	check(count($sent_rows()) === 2, 'both present: copied, and the done key it already carried is not a second row (R59)');
	check(!$column('itx_instance_transfers', 'itx_email_times') && array_keys((array)$acts()) === array('stuck_alert'),
		'the old column dropped, the acts copied to the new one', json_encode($acts()));
	$up(DbConnector::get_instance());
	check(count($sent_rows()) === 2, 'run again, it finds nothing to do');
	check((new InstanceTransfer($t->key, TRUE))->last_email()[0] === 'done', 'last_email() reads the carried rows');
} catch (Throwable $e) {
	check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
