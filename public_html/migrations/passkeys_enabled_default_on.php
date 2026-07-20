<?php
/**
 * Passkeys default on (specs/mailbox_protection_ceremony.md § 4).
 *
 * passkeys_enabled shipped defaulting to '0', which hid the passkey panel AND
 * the vault panel (vault setup runs through a PRF passkey) on every deployment
 * that never found the switch. The setting is now an emergency kill switch
 * defaulting to '1'; rows still sitting at the old factory '0' are flipped —
 * pre-launch, no operator has deliberately disabled it.
 */
function passkeys_enabled_default_on() {
    $db = DbConnector::get_instance()->get_db_link();
    $q = $db->prepare("UPDATE stg_settings SET stg_value = '1' WHERE stg_name = 'passkeys_enabled' AND stg_value = '0'");
    $q->execute();
    echo $q->rowCount() > 0
        ? "passkeys_enabled flipped on (was at the old factory default '0').\n"
        : "passkeys_enabled already on.\n";
}
?>
