<?php
/**
 * Collapse legacy plg_status = 'uninstalled' rows to the new three-state model.
 *
 * Pre-spec behaviour left the plg_plugins row (and the plugin's tables) intact
 * after an uninstall, so an operator could in theory "preserve data" across
 * uninstall/reinstall. The new model makes uninstall destructive: no row, no
 * tables. Any rows left in the 'uninstalled' state at deploy time represent
 * an operator's already-committed intent to uninstall — we just finish the
 * destructive half they never got.
 *
 * For each legacy row:
 *   - Drop plugin tables (same regex-on-*_class.php approach as the runtime uninstall)
 *   - Delete plm_plugin_migrations rows
 *   - Delete the plg_plugins row
 *
 * Idempotent: if the rows are gone (prior run, manual cleanup), the migration
 * finds nothing and is a no-op.
 */
function cleanup_uninstalled_plugins() {
    $dblink = DbConnector::get_instance()->get_db_link();

    $stmt = $dblink->prepare("SELECT plg_name FROM plg_plugins WHERE plg_status = ?");
    $stmt->execute(['uninstalled']);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($names)) {
        echo "No legacy plg_status='uninstalled' rows. Nothing to do.\n";
        return true;
    }

    require_once(PathHelper::getIncludePath('data/plugin_migrations_class.php'));

    foreach ($names as $name) {
        echo "Cleaning up legacy uninstalled plugin: $name\n";

        $data_dir = PathHelper::getAbsolutePath("plugins/{$name}/data");
        if (is_dir($data_dir)) {
            foreach (glob($data_dir . '/*_class.php') as $class_file) {
                $content = file_get_contents($class_file);
                if (preg_match('/\$tablename\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                    $tablename = $matches[1];
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tablename)) {
                        $dblink->exec("DROP TABLE IF EXISTS " . $tablename . " CASCADE");
                        echo "  Dropped table: $tablename\n";

                        $seq_stmt = $dblink->prepare(
                            "SELECT relname FROM pg_class WHERE relkind = 'S' AND relname LIKE ?"
                        );
                        $seq_stmt->execute([$tablename . '\_%']);
                        foreach ($seq_stmt->fetchAll(PDO::FETCH_COLUMN) as $seqname) {
                            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $seqname)) {
                                $dblink->exec("DROP SEQUENCE IF EXISTS " . $seqname . " CASCADE");
                                echo "  Dropped sequence: $seqname\n";
                            }
                        }
                    }
                }
            }
        }

        $migrations = new MultiPluginMigration(array('plm_plugin_name' => $name));
        $migrations->load();
        foreach ($migrations as $migration) {
            $migration->permanent_delete();
        }

        $del = $dblink->prepare("DELETE FROM plg_plugins WHERE plg_name = ?");
        $del->execute([$name]);
        echo "  Deleted plg_plugins row for $name\n";
    }

    echo "Cleaned up " . count($names) . " legacy uninstalled plugin(s).\n";
    return true;
}
?>
