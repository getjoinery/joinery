<?php
function rename_scrolldaddy_to_dns_filtering() {
    $dblink = DbConnector::get_instance()->get_db_link();
    $total = 0;

    // Plugin row: plg_plugins.plg_name
    $stmt = $dblink->prepare("UPDATE plg_plugins SET plg_name='dns_filtering' WHERE plg_name='scrolldaddy'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  plg_plugins: renamed $n row(s)\n";
    $total += $n;

    // Scheduled-task plugin binding (DownloadBlocklists task)
    $stmt = $dblink->prepare("UPDATE sct_scheduled_tasks SET sct_plugin_name='dns_filtering' WHERE sct_plugin_name='scrolldaddy'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  sct_scheduled_tasks: rebound $n task(s)\n";
    $total += $n;

    // Plugin-theme-mode pointer (legacy; only set on deployments using theme_template='plugin')
    $stmt = $dblink->prepare("UPDATE stg_settings SET stg_value='dns_filtering' WHERE stg_name='active_theme_plugin' AND stg_value='scrolldaddy'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  active_theme_plugin: updated pointer\n";
    $total += $n;

    echo "Rename migration: $total row(s) updated.\n";
    return true;
}
?>
