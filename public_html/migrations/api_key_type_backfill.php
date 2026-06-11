<?php
/**
 * One-time data backfill for apk_type.
 *
 * Schema addition is handled by update_database from $field_specifications
 * before this runs: apk_type has been added (varchar(16), eventual NOT NULL
 * with default 'machine').
 *
 * Every key that existed before the type column was introduced is an
 * admin-provisioned machine key — session keys are only ever minted with an
 * explicit apk_type by ApiKey::CreateSessionKey(). Backfilling here (rather
 * than waiting for the --upgrade NOT NULL pass) keeps the fail-closed
 * machine-key gate in ManagementApiRouter working for existing integrations
 * immediately after deploy. Re-runs are no-ops.
 */
function api_key_type_backfill() {
    $dblink = DbConnector::get_instance()->get_db_link();

    $stmt = $dblink->prepare("
        UPDATE apk_api_keys
        SET apk_type = 'machine'
        WHERE apk_type IS NULL
    ");
    $stmt->execute();
    $backfilled = $stmt->rowCount();
    echo "Backfilled apk_type = 'machine' on $backfilled API key(s).\n";

    return true;
}
?>
