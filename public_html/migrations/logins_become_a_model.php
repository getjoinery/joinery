<?php
/**
 * log_logins is a model: a serial key, and its IP as text.
 *
 * The table was created by hand with a (user, time) pair as its primary key
 * and an inet address column. The Login model declares a serial
 * log_login_id and a varchar(45) log_ip, the platform's shapes. update_database
 * adds both columns from the spec before this runs — and, in upgrade mode,
 * its primary-key fix has already filled log_login_id from the sequence,
 * dropped the pair and made log_login_id the key. This finishes whatever it
 * left, per step, so it comes out the same either way:
 *
 *   1. log_login_id filled from its sequence where NULL (rows keep their
 *      arrival order — the sequence hands out ids in physical order);
 *   2. the key on log_login_id, if it is not there yet;
 *   3. the sequence past the highest id, by its real name off the column
 *      default (the platform's sequences are not OWNED BY their column);
 *   4. log_ip = host(log_ip_address), then the inet column dropped;
 *   5. rows whose user no longer exists removed — the model cascades a
 *      person's history with the person, and the hand-rolled table had no
 *      rule, so every user deleted before this left their rows behind.
 *
 * Idempotent: a table already in the model's shape is left alone. The
 * migration runner holds the transaction.
 */
function logins_become_a_model() {
    $db = DbConnector::get_instance()->get_db_link();

    $has_column = function ($column) use ($db) {
        $q = $db->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'log_logins' AND column_name = :c");
        $q->execute(array(':c' => $column));
        return $q->fetchColumn() !== false;
    };

    if ($db->query("SELECT to_regclass('public.log_logins')")->fetchColumn() === null) {
        echo "  log_logins: absent here, skipped\n";
        return;
    }
    if (!$has_column('log_login_id')) {
        throw new Exception('log_logins.log_login_id not yet added - run the schema pass first');
    }

    // The sequence, by the name the column default carries.
    $default = (string)$db->query(
        "SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d
           JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
          WHERE d.adrelid = 'public.log_logins'::regclass AND a.attname = 'log_login_id'")->fetchColumn();
    if (!preg_match("/nextval\\('([^']+)'/", $default, $m)) {
        throw new Exception('log_logins.log_login_id has no sequence default - the schema pass did not finish');
    }
    $sequence = $m[1];

    // 1. every row has an id
    $q = $db->query("UPDATE log_logins SET log_login_id = nextval('{$sequence}') WHERE log_login_id IS NULL");
    $filled = $q->rowCount();

    // 2. the key
    $pkey_col = $db->query(
        "SELECT a.attname FROM pg_index i
           JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
          WHERE i.indrelid = 'public.log_logins'::regclass AND i.indisprimary
          ORDER BY a.attnum LIMIT 1")->fetchColumn();
    if ($pkey_col !== 'log_login_id') {
        $existing = $db->query(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'public.log_logins'::regclass AND contype = 'p'")->fetchColumn();
        if ($existing !== false) {
            $db->exec("ALTER TABLE log_logins DROP CONSTRAINT \"{$existing}\"");
        }
        $db->exec("ALTER TABLE log_logins ALTER COLUMN log_login_id SET NOT NULL");
        $db->exec("ALTER TABLE log_logins ADD CONSTRAINT \"log_logins_pkey\" PRIMARY KEY (log_login_id)");
    }

    // 3. the sequence continues past the highest id
    $db->exec(
        "SELECT setval('{$sequence}',
                       GREATEST((SELECT coalesce(max(log_login_id), 0) FROM log_logins), 1),
                       (SELECT count(*) > 0 FROM log_logins))");

    // 4. the address as text
    $converted = 0;
    if ($has_column('log_ip_address')) {
        if (!$has_column('log_ip')) {
            throw new Exception('log_logins.log_ip not yet added - run the schema pass first');
        }
        $q = $db->query("UPDATE log_logins SET log_ip = host(log_ip_address) WHERE log_ip IS NULL AND log_ip_address IS NOT NULL");
        $converted = $q->rowCount();
        $db->exec("ALTER TABLE log_logins DROP COLUMN log_ip_address");
    }

    // 5. history of people who are gone
    $q = $db->query("DELETE FROM log_logins WHERE NOT EXISTS (SELECT 1 FROM usr_users u WHERE u.usr_user_id = log_logins.log_usr_user_id)");
    $orphaned = $q->rowCount();

    echo "  log_logins: {$filled} ids assigned, key on log_login_id, {$converted} addresses converted to text, "
       . "{$orphaned} rows of deleted users removed\n";
}
?>
