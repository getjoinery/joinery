<?php
/**
 * Uninstall function for scrolldaddy plugin.
 * Removes only scrolldaddy-specific settings.
 * Does NOT drop ctld_ tables — they are shared with the controld plugin.
 * @return bool True on success, false on failure
 */
function scrolldaddy_uninstall() {
    try {
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();

        // Remove scrolldaddy plugin settings only
        $sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'scrolldaddy_%'";
        $q = $dblink->prepare($sql);
        $q->execute();

        // Remove admin menu entries for this plugin
        $sql = "DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/scrolldaddy/%'";
        $q = $dblink->prepare($sql);
        $q->execute();

        return true;

    } catch (Exception $e) {
        error_log("ScrollDaddy plugin uninstall failed: " . $e->getMessage());
        return false;
    }
}
?>
