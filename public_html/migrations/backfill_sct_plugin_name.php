<?php
function backfill_sct_plugin_name() {
    $dblink = DbConnector::get_instance()->get_db_link();
    $plugins_dir = PathHelper::getIncludePath('plugins');

    if (!is_dir($plugins_dir)) {
        echo "No plugins directory found. Skipping backfill.\n";
        return true;
    }

    $updated = 0;
    $task_jsons = glob($plugins_dir . '/*/tasks/*.json');

    if (!empty($task_jsons)) {
        foreach ($task_jsons as $json_file) {
            $class_name = basename($json_file, '.json');
            $path_parts = explode('/', dirname($json_file));
            $plugin_name = $path_parts[count($path_parts) - 2];

            $stmt = $dblink->prepare(
                "UPDATE sct_scheduled_tasks
                 SET sct_plugin_name = ?
                 WHERE sct_task_class = ?
                   AND (sct_plugin_name IS NULL OR sct_plugin_name = '')"
            );
            $stmt->execute([$plugin_name, $class_name]);
            $updated += $stmt->rowCount();
        }
    }

    echo "Backfilled sct_plugin_name for $updated scheduled task(s).\n";
    return true;
}
?>
