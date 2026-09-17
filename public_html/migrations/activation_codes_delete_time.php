<?php
/**
 * Activation codes carry the platform's soft-delete and create-time columns.
 *
 * act_activation_codes voided a spent code with a bool (act_deleted) and
 * stamped its birth as act_created_time. The platform names are
 * act_delete_time and act_create_time — the deleted filter, delete() /
 * undelete() and the retention timer all read {prefix}_delete_time, and a
 * bool records that a code was spent but never when.
 *
 * update_database adds the two new columns from the spec before this runs.
 * This copies the old values across (a spent code takes its create time as
 * its delete time — nothing ever recorded the real moment) and drops the
 * old columns. The migration runner holds the transaction.
 *
 * Idempotent: a table without the old columns (a fresh install, or a node
 * already migrated) is left alone.
 */
function activation_codes_delete_time() {
    $db = DbConnector::get_instance()->get_db_link();

    $has = function ($column) use ($db) {
        $q = $db->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'act_activation_codes' AND column_name = :c");
        $q->execute(array(':c' => $column));
        return $q->fetchColumn() !== false;
    };

    $has_deleted = $has('act_deleted');
    $has_created = $has('act_created_time');
    if (!$has_deleted && !$has_created) {
        echo "  act_activation_codes: already on act_delete_time / act_create_time\n";
        return;
    }
    if (!$has('act_delete_time') || !$has('act_create_time')) {
        throw new Exception('act_activation_codes: act_delete_time / act_create_time not yet added — run the schema pass first');
    }

    $moved = 0;
    if ($has_created) {
        $q = $db->prepare("UPDATE act_activation_codes SET act_create_time = act_created_time");
        $q->execute();
        $moved = $q->rowCount();
        if ($has_deleted) {
            $q = $db->prepare(
                "UPDATE act_activation_codes SET act_delete_time = act_created_time
                  WHERE act_deleted AND act_delete_time IS NULL");
            $q->execute();
            echo "  act_activation_codes: {$moved} create times copied, " . $q->rowCount() . " spent codes stamped\n";
        }
        $db->exec("ALTER TABLE act_activation_codes DROP COLUMN act_created_time");
    } elseif ($has_deleted) {
        $q = $db->prepare(
            "UPDATE act_activation_codes SET act_delete_time = act_create_time
              WHERE act_deleted AND act_delete_time IS NULL");
        $q->execute();
        echo "  act_activation_codes: " . $q->rowCount() . " spent codes stamped\n";
    }
    if ($has_deleted) {
        $db->exec("ALTER TABLE act_activation_codes DROP COLUMN act_deleted");
    }
}
?>
