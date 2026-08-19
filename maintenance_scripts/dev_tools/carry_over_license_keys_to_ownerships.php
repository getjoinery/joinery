<?php
/**
 * carry_over_license_keys_to_ownerships.php — one-off data carry-over for
 * specs/store_own_once_products.md.
 *
 * The store gained an ownership model. What used to be recorded as "this buyer
 * holds a license key for this plugin" is now recorded as "this buyer owns this
 * tag", which is the fact the row always described; the key string was one
 * operator's artifact for proving it to a remote machine, and it is preserved
 * verbatim so keys already emailed to buyers stay true.
 *
 * Three steps, each idempotent:
 *
 *   1. lck_license_keys  -> own_ownerships   (plugin name becomes the tag)
 *   2. pro_licensed_plugin -> pro_ownership_tag  on every product
 *   3. backfill: paid order items of tagged products that have no ownership row
 *      get one, so buyers from before this landed gain the exemption
 *
 * Nothing is dropped here. After a clean run, drop the old table and column by
 * hand:
 *
 *   ALTER TABLE pro_products DROP COLUMN pro_licensed_plugin;
 *   DROP TABLE lck_license_keys;
 *
 * Usage:
 *   php carry_over_license_keys_to_ownerships.php            # report only
 *   php carry_over_license_keys_to_ownerships.php --apply    # write
 *
 * @version 1.0.0
 */

$root = dirname(dirname(__DIR__)) . '/public_html';
require_once($root . '/includes/PathHelper.php');
require_once($root . '/includes/ClassAutoloader.php');
ClassAutoloader::register();

$apply = in_array('--apply', $argv, true);
$mode = $apply ? 'APPLY' : 'DRY RUN';
echo "Own-once carry-over ({$mode})\n";
echo str_repeat('=', 60) . "\n";

$dblink = DbConnector::get_instance()->get_db_link();

function table_exists($dblink, $table) {
	$q = $dblink->prepare('SELECT to_regclass(?)');
	$q->execute(array('public.' . $table));
	return (bool)$q->fetchColumn();
}

function column_exists($dblink, $table, $column) {
	$q = $dblink->prepare('SELECT 1 FROM information_schema.columns
		WHERE table_name = ? AND column_name = ?');
	$q->execute(array($table, $column));
	return (bool)$q->fetchColumn();
}

if (!table_exists($dblink, 'own_ownerships')) {
	fwrite(STDERR, "own_ownerships does not exist. Run update_database first.\n");
	exit(1);
}

// ---------------------------------------------------------------------------
// 1. License keys become ownerships.
// ---------------------------------------------------------------------------
echo "\n1. lck_license_keys -> own_ownerships\n";

if (!table_exists($dblink, 'lck_license_keys')) {
	echo "   lck_license_keys is already gone — nothing to carry over.\n";
}
else {
	$rows = $dblink->query('SELECT * FROM lck_license_keys ORDER BY lck_license_key_id',
		PDO::FETCH_ASSOC)->fetchAll();
	$copied = 0;
	$skipped = 0;
	foreach ($rows as $row) {
		// Idempotent on the key string, which is unique per row and preserved.
		$check = $dblink->prepare('SELECT 1 FROM own_ownerships WHERE own_license_key = ?');
		$check->execute(array($row['lck_key']));
		if ($check->fetchColumn()) {
			$skipped++;
			continue;
		}
		if ($apply) {
			$insert = $dblink->prepare('INSERT INTO own_ownerships
				(own_usr_user_id, own_tag, own_ord_order_id, own_odi_order_item_id,
				 own_license_key, own_create_time, own_revoked_time, own_delete_time)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
			$insert->execute(array(
				$row['lck_usr_user_id'],
				$row['lck_plugin_name'],
				$row['lck_ord_order_id'],
				$row['lck_odi_order_item_id'],
				$row['lck_key'],
				$row['lck_create_time'],
				$row['lck_revoked_time'],
				$row['lck_delete_time'],
			));
		}
		$copied++;
	}
	echo "   " . count($rows) . " license key(s); {$copied} to copy, {$skipped} already present.\n";
}

// ---------------------------------------------------------------------------
// 2. The product column.
// ---------------------------------------------------------------------------
echo "\n2. pro_licensed_plugin -> pro_ownership_tag\n";

if (!column_exists($dblink, 'pro_products', 'pro_licensed_plugin')) {
	echo "   pro_licensed_plugin is already gone — nothing to carry over.\n";
}
else {
	$products = $dblink->query("SELECT pro_product_id, pro_name, pro_licensed_plugin, pro_ownership_tag
		FROM pro_products
		WHERE pro_licensed_plugin IS NOT NULL AND pro_licensed_plugin <> ''", PDO::FETCH_ASSOC)->fetchAll();
	$tagged = 0;
	foreach ($products as $product_row) {
		if (trim((string)$product_row['pro_ownership_tag']) !== '') {
			echo "   product #{$product_row['pro_product_id']} already tagged '"
				. $product_row['pro_ownership_tag'] . "' — left alone.\n";
			continue;
		}
		echo "   product #{$product_row['pro_product_id']} ({$product_row['pro_name']}) -> tag '"
			. $product_row['pro_licensed_plugin'] . "'\n";
		if ($apply) {
			$update = $dblink->prepare('UPDATE pro_products SET pro_ownership_tag = ? WHERE pro_product_id = ?');
			$update->execute(array($product_row['pro_licensed_plugin'], $product_row['pro_product_id']));
		}
		$tagged++;
	}
	echo "   {$tagged} product(s) to tag.\n";
}

// ---------------------------------------------------------------------------
// 3. Backfill: paid items of tagged products with no ownership row.
// ---------------------------------------------------------------------------
echo "\n3. Backfill ownerships for paid order items of tagged products\n";

$sql = "SELECT odi.odi_order_item_id
	FROM odi_order_items odi
	JOIN pro_products pro ON pro.pro_product_id = odi.odi_pro_product_id
	LEFT JOIN own_ownerships own ON own.own_odi_order_item_id = odi.odi_order_item_id
	WHERE pro.pro_ownership_tag IS NOT NULL AND pro.pro_ownership_tag <> ''
	  AND odi.odi_status = " . (int)OrderItem::STATUS_PAID . "
	  AND COALESCE(odi.odi_is_subscription, FALSE) = FALSE
	  AND own.own_ownership_id IS NULL
	ORDER BY odi.odi_order_item_id";

$item_ids = $dblink->query($sql, PDO::FETCH_ASSOC)->fetchAll();
echo "   " . count($item_ids) . " paid order item(s) without an ownership row.\n";

$recorded = 0;
foreach ($item_ids as $item_row) {
	$order_item = new OrderItem($item_row['odi_order_item_id'], TRUE);
	$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
	$owner = new User($order_item->get('odi_usr_user_id'), TRUE);
	$order = $order_item->get('odi_ord_order_id')
		? new Order($order_item->get('odi_ord_order_id'), TRUE) : NULL;
	$product_version = NULL;
	if ($order_item->get('odi_prv_product_version_id')) {
		$product_version = new ProductVersion($order_item->get('odi_prv_product_version_id'), TRUE);
	}

	if (!$owner->key) {
		echo "   order item #{$order_item->key}: no owner — skipped.\n";
		continue;
	}

	echo "   order item #{$order_item->key}: {$owner->get('usr_email')} owns '"
		. $product->get('pro_ownership_tag') . "'\n";
	if ($apply) {
		Ownership::record_purchase($product, $product_version, $owner, $order_item, $order);
	}
	$recorded++;
}
echo "   {$recorded} ownership row(s) to record.\n";

echo "\n" . str_repeat('=', 60) . "\n";
if ($apply) {
	echo "Done. Drop the old table and column by hand when you are satisfied:\n";
	echo "  ALTER TABLE pro_products DROP COLUMN pro_licensed_plugin;\n";
	echo "  DROP TABLE lck_license_keys;\n";
}
else {
	echo "Nothing was written. Re-run with --apply to make these changes.\n";
}
