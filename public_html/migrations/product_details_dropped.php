<?php
/**
 * The store's dormant session counter is gone.
 *
 * prd_product_details held, per user and product, a count of sessions bought and
 * used. No purchase path ever wrote it; only a pair of admin pages edited it by
 * hand, and both went with the model (core_improvements_for_plugins.md C10),
 * so the platform has one place for how many sessions or credits a person has left.
 *
 * update_database never drops a table, so this does. It drops the table only when
 * it holds no rows: a node with rows keeps it and says so, because those rows are
 * somebody's record of sessions owed and nothing else reads them.
 *
 * It is a core migration and checks the table itself: the table exists wherever
 * the store plugin was ever active, including a node where it is inactive now.
 * The table's sequence is not OWNED BY its column, so it goes separately.
 *
 * Idempotent: a table or sequence already gone is skipped. The migration runner
 * holds the transaction.
 */
function product_details_dropped() {
	$db = DbConnector::get_instance()->get_db_link();

	$q = $db->prepare('SELECT to_regclass(:t)');
	$q->execute(array(':t' => 'public.prd_product_details'));
	if ($q->fetchColumn() === null) {
		echo "  prd_product_details: already gone\n";
	} else {
		$rows = (int)$db->query('SELECT count(*) FROM prd_product_details')->fetchColumn();
		if ($rows > 0) {
			echo "  prd_product_details: KEPT - {$rows} row(s) of sessions bought and used; copy them to wherever the sessions are tracked now, then drop the table by hand\n";
			return true;
		}
		$db->exec('DROP TABLE prd_product_details');
		echo "  prd_product_details: dropped (empty)\n";
	}

	$q = $db->query("SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' AND sequencename ~ '^prd_product_details_'");
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sequence) {
		$db->exec('DROP SEQUENCE IF EXISTS ' . $sequence);
		echo "  {$sequence}: dropped\n";
	}
	return true;
}
?>
