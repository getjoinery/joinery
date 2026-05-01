<?php
function rename_scrolldaddy_settings() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // Rename setting names: scrolldaddy_* → dns_filtering_*
    // Plugin rule: declared setting names must start with the plugin's directory
    // name. After the plugin rename to dns_filtering, the legacy scrolldaddy_*
    // setting names violate that rule and PluginManager skips syncing them.
    $stmt = $dblink->prepare("
        UPDATE stg_settings
        SET stg_name = 'dns_filtering_' || substring(stg_name from 13)
        WHERE stg_name LIKE 'scrolldaddy\\_%'
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    echo "Renamed $n setting(s) from scrolldaddy_* to dns_filtering_*\n";
    return true;
}
?>
