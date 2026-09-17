<?php
/**
 * The managed DNS records table is named for what it holds.
 *
 * dnr_dns_records was the platform's memory of which records in a zone are
 * its responsibility — the model was already called ManagedDnsRecord, and
 * the table takes the same name: dnr_managed_dns_records, primary key
 * dnr_managed_dns_record_id.
 *
 * update_database creates the new table from the spec, empty, before this
 * runs. This copies every row across (the primary key keeps its value),
 * points the new sequence past the highest id, and drops the old table.
 * The deletion-rule registry names tables, and is rebuilt from the specs on
 * the same run. The migration runner holds the transaction.
 *
 * Idempotent: a database with no old table (a fresh install, or a node
 * already migrated) is left alone.
 */
function managed_dns_records_table() {
    $db = DbConnector::get_instance()->get_db_link();

    $exists = function ($table) use ($db) {
        $q = $db->prepare("SELECT to_regclass(:t)");
        $q->execute(array(':t' => 'public.' . $table));
        return $q->fetchColumn() !== null;
    };

    if (!$exists('dnr_dns_records')) {
        echo "  dnr_dns_records: already gone\n";
        return;
    }
    if (!$exists('dnr_managed_dns_records')) {
        throw new Exception('dnr_managed_dns_records not yet created - run the schema pass first');
    }

    $copied = $db->exec(
        "INSERT INTO dnr_managed_dns_records
            (dnr_managed_dns_record_id, dnr_domain, dnr_type, dnr_name, dnr_value, dnr_owner,
             dnr_provider, dnr_zone, dnr_adopted, dnr_create_time, dnr_update_time, dnr_delete_time)
         SELECT dnr_dns_record_id, dnr_domain, dnr_type, dnr_name, dnr_value, dnr_owner,
                dnr_provider, dnr_zone, dnr_adopted, dnr_create_time, dnr_update_time, dnr_delete_time
           FROM dnr_dns_records
          WHERE dnr_dns_record_id NOT IN (SELECT dnr_managed_dns_record_id FROM dnr_managed_dns_records)");

    // The new table's serial starts at 1; move it past every id just kept.
    // update_database names a primary key's sequence {table}_{pkey}_seq and
    // does not make it OWNED BY the column, so pg_get_serial_sequence() knows
    // nothing of it — the name is read off the column default instead, and
    // the old table's sequence has to be dropped by name for the same reason.
    $q = $db->query(
        "SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d
           JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
          WHERE d.adrelid = 'public.dnr_managed_dns_records'::regclass AND a.attname = 'dnr_managed_dns_record_id'");
    if (!preg_match("/nextval\('([^']+)'/", (string)$q->fetchColumn(), $m)) {
        throw new Exception('dnr_managed_dns_records.dnr_managed_dns_record_id has no sequence default');
    }
    $sequence = $m[1];
    $db->exec(
        "SELECT setval('{$sequence}',
                       GREATEST((SELECT coalesce(max(dnr_managed_dns_record_id), 0) FROM dnr_managed_dns_records), 1),
                       (SELECT count(*) > 0 FROM dnr_managed_dns_records))");

    // The old table, then its sequence, which the table does not own.
    $db->exec("DROP TABLE dnr_dns_records");
    $db->exec("DROP SEQUENCE IF EXISTS dnr_dns_records_dnr_dns_record_id_seq");
    echo "  dnr_dns_records -> dnr_managed_dns_records: {$copied} rows copied, sequence carried, old table dropped\n";
}
?>
