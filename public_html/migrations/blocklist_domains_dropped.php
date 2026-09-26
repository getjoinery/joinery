<?php
/**
 * The DNS servers download the category blocklists themselves
 * (specs/dns_resolvers_read_over_https.md D1, WP7). The site's copy of them,
 * bld_blocklist_domains, had no reader left once they read the site over HTTPS,
 * and the task that filled it and the setting that versioned it are gone with it.
 *
 * bld_blocklist_domains is a dns_filtering table. It exists wherever the plugin
 * was ever active, including a node where it is inactive now, so this runs as a
 * core migration and checks the table itself: a node that never had it has
 * nothing to drop. The table's sequence is not OWNED BY its column, so it goes
 * separately. The dns_filtering_blocklist_version row goes too, so every
 * stored setting stays declared.
 *
 * Idempotent: a table, sequence or row already gone is skipped. The migration
 * runner holds the transaction.
 */
function blocklist_domains_dropped() {
	$db = DbConnector::get_instance()->get_db_link();

	$q = $db->prepare('SELECT to_regclass(:t)');
	$q->execute(array(':t' => 'public.bld_blocklist_domains'));
	if ($q->fetchColumn() !== null) {
		$rows = (int)$db->query('SELECT count(*) FROM bld_blocklist_domains')->fetchColumn();
		$db->exec('DROP TABLE bld_blocklist_domains');
		echo "  bld_blocklist_domains: dropped ({$rows} rows)\n";
	} else {
		echo "  bld_blocklist_domains: already gone\n";
	}

	$q = $db->query("SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' AND sequencename ~ '^bld_blocklist_domains_'");
	foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sequence) {
		$db->exec('DROP SEQUENCE IF EXISTS ' . $sequence);
		echo "  {$sequence}: dropped\n";
	}

	$del = $db->prepare('DELETE FROM stg_settings WHERE stg_name = ?');
	$del->execute(array('dns_filtering_blocklist_version'));
	if ($del->rowCount() > 0) {
		echo "  Removed retired setting dns_filtering_blocklist_version.\n";
	}
	return true;
}
?>
