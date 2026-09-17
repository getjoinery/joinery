<?php
/**
 * ContentVersion takes a prefix of its own (specs/implemented/shared_prefix_content_version.md).
 *
 * cnv was ContentVersion's and Conversation's; ContentVersion is cvn:
 * cnv_content_versions -> cvn_content_versions, primary key
 * cvn_content_version_id. No other table names this one by foreign key.
 *
 * update_database creates the new table from the spec, empty, before this
 * runs (migration 187's pattern). This copies every row across with its id
 * — the previous/next version columns are plain ids into this same table
 * and stay valid because ids are kept — points the new sequence past the
 * highest id, and drops the old table and its sequence. The migration
 * runner holds the transaction.
 *
 * Idempotent: a database with no old table (a fresh install, or a node
 * already migrated) is left alone.
 */
function content_version_prefix() {
    $db = DbConnector::get_instance()->get_db_link();

    $exists = function ($table) use ($db) {
        $q = $db->prepare("SELECT to_regclass(:t)");
        $q->execute(array(':t' => 'public.' . $table));
        return $q->fetchColumn() !== null;
    };

    if (!$exists('cnv_content_versions')) {
        echo "  cnv_content_versions: already gone\n";
        return;
    }
    if (!$exists('cvn_content_versions')) {
        throw new Exception('cvn_content_versions not yet created - run the schema pass first');
    }

    $columns = array(
        'cnv_content_version_id'  => 'cvn_content_version_id',
        'cnv_title'               => 'cvn_title',
        'cnv_usr_user_id'         => 'cvn_usr_user_id',
        'cnv_description'         => 'cvn_description',
        'cnv_type'                => 'cvn_type',
        'cnv_foreign_key_id'      => 'cvn_foreign_key_id',
        'cnv_next_version_id'     => 'cvn_next_version_id',
        'cnv_previous_version_id' => 'cvn_previous_version_id',
        'cnv_content'             => 'cvn_content',
        'cnv_create_time'         => 'cvn_create_time',
        'cnv_delete_time'         => 'cvn_delete_time',
    );
    $old_cols = implode(', ', array_keys($columns));
    $new_cols = implode(', ', array_values($columns));

    $copied = $db->exec(
        "INSERT INTO cvn_content_versions ({$new_cols})
         SELECT {$old_cols} FROM cnv_content_versions
          WHERE cnv_content_version_id NOT IN (SELECT cvn_content_version_id FROM cvn_content_versions)");

    // The new table's serial starts at 1; move it past every id just kept.
    // update_database names a primary key's sequence {table}_{pkey}_seq and
    // does not make it OWNED BY the column, so the name is read off the
    // column default, and the old sequence is dropped by name below.
    $q = $db->query(
        "SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d
           JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
          WHERE d.adrelid = 'public.cvn_content_versions'::regclass AND a.attname = 'cvn_content_version_id'");
    if (!preg_match("/nextval\('([^']+)'/", (string)$q->fetchColumn(), $m)) {
        throw new Exception('cvn_content_versions.cvn_content_version_id has no sequence default');
    }
    $db->exec(
        "SELECT setval('{$m[1]}',
                       GREATEST((SELECT coalesce(max(cvn_content_version_id), 0) FROM cvn_content_versions), 1),
                       (SELECT count(*) > 0 FROM cvn_content_versions))");

    $db->exec("DROP TABLE cnv_content_versions");
    $db->exec("DROP SEQUENCE IF EXISTS cnv_content_versions_cnv_content_version_id_seq");
    echo "  cnv_content_versions -> cvn_content_versions: {$copied} rows copied, sequence carried, old table dropped\n";
}
?>
