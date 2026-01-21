<?php
/**
 * DeploymentHelper - Shared deployment utilities for upgrade.php and deploy.sh
 *
 * Provides validation, rollback, and theme/plugin preservation functionality
 * used by both web-based (upgrade.php) and command-line (deploy.sh) deployment systems.
 */

class DeploymentHelper {

    // ============================================
    // VALIDATION METHODS
    // ============================================

    /**
     * Validate PHP syntax on all files in directory
     * @param string $directory Directory to validate
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool, 'errors' => array, 'files_checked' => int]
     *               Each error: ['file' => string, 'line' => int, 'message' => string, 'type' => 'syntax']
     */
    public static function validatePHPSyntax($directory, $verbose = false) {
        $result = [
            'success' => true,
            'errors' => [],
            'files_checked' => 0
        ];

        if ($verbose) {
            echo "Validating PHP syntax in: $directory\n";
        }

        if (!is_dir($directory)) {
            $result['success'] = false;
            $result['errors'][] = [
                'file' => $directory,
                'line' => 0,
                'message' => 'Directory does not exist',
                'type' => 'syntax'
            ];
            return $result;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;

            $result['files_checked']++;
            $filepath = $file->getPathname();

            if ($verbose) {
                echo "  Checking: " . str_replace($directory, '', $filepath) . "...";
            }

            $output = [];
            $return_var = 0;
            exec("php -l " . escapeshellarg($filepath) . " 2>&1", $output, $return_var);

            if ($return_var !== 0) {
                // Parse error message for line number
                $line = 0;
                $message = implode(' ', $output);
                if (preg_match('/on line (\d+)/', $message, $matches)) {
                    $line = (int)$matches[1];
                }

                $result['errors'][] = [
                    'file' => $filepath,
                    'line' => $line,
                    'message' => $message,
                    'type' => 'syntax'
                ];
                $result['success'] = false;

                if ($verbose) {
                    echo " FAILED\n";
                    echo "    Error: $message\n";
                }
            } else {
                if ($verbose) {
                    echo " OK\n";
                }
            }
        }

        if ($verbose) {
            echo "Syntax validation complete: {$result['files_checked']} files checked, " .
                 count($result['errors']) . " errors found\n";
        }

        return $result;
    }

    /**
     * Test that plugin class files can be loaded
     * @param string $stage_dir Staging directory
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool, 'errors' => array, 'files_checked' => int]
     *               Each error: ['file' => string, 'message' => string, 'type' => string]
     *               Types: 'syntax', 'missing_dependency', 'fatal_error', 'include_error'
     */
    public static function testPluginLoading($stage_dir, $verbose = false) {
        $result = [
            'success' => true,
            'errors' => [],
            'files_checked' => 0
        ];

        if ($verbose) {
            echo "Testing plugin loading in: $stage_dir\n";
        }

        $plugins_dir = $stage_dir . '/plugins';
        if (!is_dir($plugins_dir)) {
            if ($verbose) {
                echo "  No plugins directory found - skipping plugin tests\n";
            }
            return $result;
        }

        // Find all plugin class files
        $plugin_classes = glob($plugins_dir . '/*/_class.php');

        if (empty($plugin_classes)) {
            if ($verbose) {
                echo "  No plugin class files found\n";
            }
            return $result;
        }

        foreach ($plugin_classes as $class_file) {
            $result['files_checked']++;
            $plugin_name = basename(dirname($class_file));

            if ($verbose) {
                echo "  Testing plugin: $plugin_name...";
            }

            // First check syntax
            $output = [];
            $return_var = 0;
            exec("php -l " . escapeshellarg($class_file) . " 2>&1", $output, $return_var);

            if ($return_var !== 0) {
                $result['errors'][] = [
                    'file' => $class_file,
                    'message' => implode(' ', $output),
                    'type' => 'syntax'
                ];
                $result['success'] = false;

                if ($verbose) {
                    echo " SYNTAX ERROR\n";
                    echo "    " . implode(' ', $output) . "\n";
                }
                continue;
            }

            // Try to load the plugin class in isolated context
            $test_script = tempnam(sys_get_temp_dir(), 'plugin_test_');
            file_put_contents($test_script, "<?php
                // Simulate PathHelper context
                define('TESTING_PLUGIN_LOAD', true);
                error_reporting(E_ALL);

                // Try to include the plugin class
                try {
                    include_once('$class_file');
                    echo 'SUCCESS';
                } catch (Error \$e) {
                    echo 'ERROR: ' . \$e->getMessage();
                } catch (Exception \$e) {
                    echo 'EXCEPTION: ' . \$e->getMessage();
                }
            ");

            $output = [];
            exec("php " . escapeshellarg($test_script) . " 2>&1", $output, $return_var);
            unlink($test_script);

            $output_text = implode("\n", $output);

            if (strpos($output_text, 'SUCCESS') === false) {
                $error_type = 'fatal_error';
                if (strpos($output_text, 'Class') !== false && strpos($output_text, 'not found') !== false) {
                    $error_type = 'missing_dependency';
                } elseif (strpos($output_text, 'Failed opening') !== false || strpos($output_text, 'include') !== false) {
                    $error_type = 'include_error';
                }

                $result['errors'][] = [
                    'file' => $class_file,
                    'message' => $output_text,
                    'type' => $error_type
                ];
                $result['success'] = false;

                if ($verbose) {
                    echo " FAILED\n";
                    echo "    " . $output_text . "\n";
                }
            } else {
                if ($verbose) {
                    echo " OK\n";
                }
            }
        }

        if ($verbose) {
            echo "Plugin loading tests complete: {$result['files_checked']} plugins checked, " .
                 count($result['errors']) . " errors found\n";
        }

        return $result;
    }

    /**
     * Test application bootstrap (PathHelper, Globalvars, DbConnector)
     * @param string $stage_dir Staging directory
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool, 'error' => string|null, 'components_loaded' => array]
     */
    public static function testBootstrap($stage_dir, $verbose = false) {
        $result = [
            'success' => false,
            'error' => null,
            'components_loaded' => []
        ];

        if ($verbose) {
            echo "Testing application bootstrap in: $stage_dir\n";
        }

        // Create a test script to bootstrap the application
        $test_script = tempnam(sys_get_temp_dir(), 'bootstrap_test_');
        file_put_contents($test_script, "<?php
            error_reporting(E_ALL);
            \$components = [];

            try {
                // Try to load PathHelper
                if (file_exists('$stage_dir/includes/PathHelper.php')) {
                    require_once('$stage_dir/includes/PathHelper.php');
                    \$components[] = 'PathHelper';
                } else {
                    echo 'ERROR: PathHelper.php not found';
                    exit(1);
                }

                // Try to load Globalvars
                if (file_exists('$stage_dir/includes/Globalvars.php')) {
                    require_once('$stage_dir/includes/Globalvars.php');
                    \$components[] = 'Globalvars';
                } else {
                    echo 'ERROR: Globalvars.php not found';
                    exit(1);
                }

                // Try to load DbConnector
                if (file_exists('$stage_dir/includes/DbConnector.php')) {
                    require_once('$stage_dir/includes/DbConnector.php');
                    \$components[] = 'DbConnector';
                } else {
                    echo 'ERROR: DbConnector.php not found';
                    exit(1);
                }

                echo 'SUCCESS:' . implode(',', \$components);

            } catch (Error \$e) {
                echo 'ERROR: ' . \$e->getMessage();
                exit(1);
            } catch (Exception \$e) {
                echo 'EXCEPTION: ' . \$e->getMessage();
                exit(1);
            }
        ");

        $output = [];
        exec("php " . escapeshellarg($test_script) . " 2>&1", $output, $return_var);
        unlink($test_script);

        $output_text = implode("\n", $output);

        if (strpos($output_text, 'SUCCESS:') !== false) {
            $result['success'] = true;
            $components = str_replace('SUCCESS:', '', $output_text);
            $result['components_loaded'] = explode(',', trim($components));

            if ($verbose) {
                echo "  ✓ Bootstrap test passed (loaded: " . implode(', ', $result['components_loaded']) . ")\n";
            }
        } else {
            $result['error'] = $output_text;

            if ($verbose) {
                echo "  ✗ Bootstrap test FAILED\n";
                echo "    " . $output_text . "\n";
            }
        }

        return $result;
    }

    // ============================================
    // THEME/PLUGIN MANAGEMENT
    // ============================================

    /**
     * Update only themes/plugins that are already installed
     * This is the new "sparse-fetch, update-only" model.
     *
     * Key behavior:
     * - Only processes themes/plugins that are ALREADY in public_html
     * - Never adds new themes/plugins from staging
     * - Stock themes (is_stock=true) are updated from staging
     * - Custom themes (is_stock=false) are preserved
     * - Themes not in repo (custom uploads) are preserved
     *
     * @param string $stage_dir Staging directory with new themes/plugins
     * @param string $public_html_dir Current public_html directory
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool,
     *                'themes_updated' => int, 'themes_preserved' => int,
     *                'plugins_updated' => int, 'plugins_preserved' => int,
     *                'errors' => array]
     */
    public static function updateInstalledThemesOnly($stage_dir, $public_html_dir, $verbose = false) {
        $result = [
            'success' => true,
            'themes_updated' => 0,
            'themes_preserved' => 0,
            'plugins_updated' => 0,
            'plugins_preserved' => 0,
            'errors' => []
        ];

        if ($verbose) {
            echo "Updating installed themes and plugins (update-only model)...\n";
        }

        // Process themes - only themes already in public_html
        $installed_themes_dir = $public_html_dir . '/theme';
        $staged_themes_dir = $stage_dir . '/theme';

        if (is_dir($installed_themes_dir)) {
            foreach (scandir($installed_themes_dir) as $theme_name) {
                if ($theme_name === '.' || $theme_name === '..') continue;

                $installed_path = $installed_themes_dir . '/' . $theme_name;
                $staged_path = $staged_themes_dir . '/' . $theme_name;

                if (!is_dir($installed_path)) continue;

                // Check if theme exists in staging (repository)
                if (is_dir($staged_path)) {
                    // Read installed theme's manifest to determine stock status
                    $manifest_path = $installed_path . '/theme.json';
                    $is_stock = true;

                    if (file_exists($manifest_path)) {
                        $manifest = json_decode(file_get_contents($manifest_path), true);
                        $is_stock = $manifest['is_stock'] ?? true;
                    }

                    if ($is_stock) {
                        // Update from staging
                        exec("rm -rf " . escapeshellarg($installed_path));
                        exec("cp -r " . escapeshellarg($staged_path) . " " . escapeshellarg($installed_themes_dir . '/'));
                        $result['themes_updated']++;

                        if ($verbose) echo "  Updated stock theme: $theme_name\n";
                    } else {
                        // Preserve custom theme
                        $result['themes_preserved']++;
                        if ($verbose) echo "  Preserved custom theme: $theme_name\n";
                    }
                } else {
                    // Theme not in repo (custom upload) - preserve it
                    $result['themes_preserved']++;
                    if ($verbose) echo "  Preserved uploaded theme: $theme_name (not in repo)\n";
                }
            }
        }

        // Process plugins - only plugins already in public_html
        $installed_plugins_dir = $public_html_dir . '/plugins';
        $staged_plugins_dir = $stage_dir . '/plugins';

        if (is_dir($installed_plugins_dir)) {
            foreach (scandir($installed_plugins_dir) as $plugin_name) {
                if ($plugin_name === '.' || $plugin_name === '..') continue;

                $installed_path = $installed_plugins_dir . '/' . $plugin_name;
                $staged_path = $staged_plugins_dir . '/' . $plugin_name;

                if (!is_dir($installed_path)) continue;

                // Check if plugin exists in staging (repository)
                if (is_dir($staged_path)) {
                    // Read installed plugin's manifest to determine stock status
                    $manifest_path = $installed_path . '/plugin.json';
                    $is_stock = true;

                    if (file_exists($manifest_path)) {
                        $manifest = json_decode(file_get_contents($manifest_path), true);
                        $is_stock = $manifest['is_stock'] ?? true;
                    }

                    if ($is_stock) {
                        // Update from staging
                        exec("rm -rf " . escapeshellarg($installed_path));
                        exec("cp -r " . escapeshellarg($staged_path) . " " . escapeshellarg($installed_plugins_dir . '/'));
                        $result['plugins_updated']++;

                        if ($verbose) echo "  Updated stock plugin: $plugin_name\n";
                    } else {
                        // Preserve custom plugin
                        $result['plugins_preserved']++;
                        if ($verbose) echo "  Preserved custom plugin: $plugin_name\n";
                    }
                } else {
                    // Plugin not in repo (custom upload) - preserve it
                    $result['plugins_preserved']++;
                    if ($verbose) echo "  Preserved uploaded plugin: $plugin_name (not in repo)\n";
                }
            }
        }

        if ($verbose) {
            echo "Theme/Plugin update complete:\n";
            echo "  Themes: {$result['themes_updated']} updated, {$result['themes_preserved']} preserved\n";
            echo "  Plugins: {$result['plugins_updated']} updated, {$result['plugins_preserved']} preserved\n";
        }

        return $result;
    }

    /**
     * Preserve custom themes/plugins based on is_stock flag
     * @deprecated Use updateInstalledThemesOnly() instead for the new sparse-fetch model
     * @param string $stage_dir Staging directory with new themes/plugins
     * @param string $backup_dir Backup directory with existing themes/plugins
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool,
     *                'themes_preserved' => int, 'themes_updated' => int, 'themes_added' => int,
     *                'plugins_preserved' => int, 'plugins_updated' => int, 'plugins_added' => int,
     *                'errors' => array]
     */
    public static function preserveCustomThemesPlugins($stage_dir, $backup_dir, $verbose = false) {
        $result = [
            'success' => true,
            'themes_preserved' => 0,
            'themes_updated' => 0,
            'themes_added' => 0,
            'plugins_preserved' => 0,
            'plugins_updated' => 0,
            'plugins_added' => 0,
            'errors' => []
        ];

        if ($verbose) {
            echo "Preserving custom themes and plugins...\n";
        }

        // Process themes
        $themes_dir = $stage_dir . '/theme';
        if (is_dir($themes_dir)) {
            foreach (scandir($themes_dir) as $theme_name) {
                if ($theme_name == '.' || $theme_name == '..') continue;

                $action = self::processThemeOrPlugin('theme', $theme_name, $stage_dir, $backup_dir, $verbose);

                if ($action == 'preserved') {
                    $result['themes_preserved']++;
                } elseif ($action == 'updated') {
                    $result['themes_updated']++;
                } elseif ($action == 'added') {
                    $result['themes_added']++;
                } elseif ($action == 'error') {
                    $result['errors'][] = "Failed to process theme: $theme_name";
                    $result['success'] = false;
                }
            }
        }

        // Process plugins
        $plugins_dir = $stage_dir . '/plugins';
        if (is_dir($plugins_dir)) {
            foreach (scandir($plugins_dir) as $plugin_name) {
                if ($plugin_name == '.' || $plugin_name == '..') continue;

                $action = self::processThemeOrPlugin('plugin', $plugin_name, $stage_dir, $backup_dir, $verbose);

                if ($action == 'preserved') {
                    $result['plugins_preserved']++;
                } elseif ($action == 'updated') {
                    $result['plugins_updated']++;
                } elseif ($action == 'added') {
                    $result['plugins_added']++;
                } elseif ($action == 'error') {
                    $result['errors'][] = "Failed to process plugin: $plugin_name";
                    $result['success'] = false;
                }
            }
        }

        if ($verbose) {
            echo "Theme/Plugin preservation complete:\n";
            echo "  Themes: {$result['themes_preserved']} preserved, {$result['themes_updated']} updated, {$result['themes_added']} added\n";
            echo "  Plugins: {$result['plugins_preserved']} preserved, {$result['plugins_updated']} updated, {$result['plugins_added']} added\n";
        }

        return $result;
    }

    /**
     * Process individual theme or plugin (private helper)
     * @return string 'preserved'|'updated'|'added'|'error'
     */
    private static function processThemeOrPlugin($type, $name, $stage_dir, $backup_dir, $verbose) {
        $subdir = ($type == 'theme') ? 'theme' : 'plugins';
        $manifest_filename = ($type == 'theme') ? 'theme.json' : 'plugin.json';

        $staging_path = $stage_dir . '/' . $subdir . '/' . $name;
        $staging_manifest = $staging_path . '/' . $manifest_filename;
        $existing_path = $backup_dir . '/' . $subdir . '/' . $name;
        $existing_manifest = $existing_path . '/' . $manifest_filename;

        // Auto-generate manifest if missing in staging
        if (!file_exists($staging_manifest)) {
            self::generateManifest($staging_manifest, $type, $name);
        }

        // Check if this theme/plugin exists in previous deployment
        if (is_dir($existing_path)) {
            // It existed before - check if it's custom
            if (file_exists($existing_manifest)) {
                $manifest_data = json_decode(file_get_contents($existing_manifest), true);
                $is_stock = $manifest_data['is_stock'] ?? true;

                if ($is_stock === false) {
                    // Preserve custom theme/plugin by copying over staged version
                    if ($verbose) {
                        echo "  Preserving custom $type: $name\n";
                    }

                    // Remove staged version
                    exec("rm -rf " . escapeshellarg($staging_path));

                    // Copy existing custom version to staging
                    $copy_result = 0;
                    exec("cp -r " . escapeshellarg($existing_path) . " " . escapeshellarg($staging_path), $output, $copy_result);

                    if ($copy_result !== 0) {
                        if ($verbose) {
                            echo "    ERROR: Failed to copy custom $type\n";
                        }
                        return 'error';
                    }

                    return 'preserved';
                } else {
                    // Stock theme/plugin - update it
                    if ($verbose) {
                        echo "  Updating stock $type: $name\n";
                    }
                    return 'updated';
                }
            } else {
                // No manifest in existing - assume stock and update
                if ($verbose) {
                    echo "  Updating $type (no manifest): $name\n";
                }
                return 'updated';
            }
        } else {
            // New theme/plugin
            if ($verbose) {
                echo "  Adding new $type: $name\n";
            }
            return 'added';
        }
    }

    /**
     * Auto-generate manifest file if missing (private helper)
     */
    private static function generateManifest($path, $type, $name) {
        $manifest = [
            'name' => $name,
            'version' => '1.0.0',
            'description' => "Auto-generated manifest for $name $type",
            'is_stock' => true
        ];

        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT));
    }

    // ============================================
    // ROLLBACK/BACKUP
    // ============================================

    /**
     * Rollback to previous deployment
     * @param string $target_site Site name (e.g., 'joinerytest')
     * @param bool $preserve_failed Save failed deployment for debugging
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool,
     *                'failed_dir' => string|null,
     *                'backup_restored' => bool,
     *                'permissions_fixed' => bool,
     *                'error' => string|null]
     */
    public static function performRollback($target_site, $preserve_failed = true, $verbose = false) {
        $result = [
            'success' => false,
            'failed_dir' => null,
            'backup_restored' => false,
            'permissions_fixed' => false,
            'error' => null
        ];

        if ($verbose) {
            echo "Performing rollback for site: $target_site\n";
        }

        $public_html = "/var/www/html/$target_site/public_html";
        $backup_dir = "/var/www/html/$target_site/public_html_last";

        // Check if backup exists
        if (!is_dir($backup_dir)) {
            $result['error'] = "Backup directory does not exist: $backup_dir";
            if ($verbose) {
                echo "  ERROR: " . $result['error'] . "\n";
            }
            return $result;
        }

        // Preserve failed deployment if requested
        if ($preserve_failed && is_dir($public_html)) {
            $failed_dir = "/var/www/html/$target_site/public_html_failed_" . date('Ymd_His');

            if ($verbose) {
                echo "  Preserving failed deployment to: $failed_dir\n";
            }

            $rename_result = rename($public_html, $failed_dir);

            if (!$rename_result) {
                $result['error'] = "Failed to preserve failed deployment";
                if ($verbose) {
                    echo "  ERROR: " . $result['error'] . "\n";
                }
                return $result;
            }

            $result['failed_dir'] = $failed_dir;

            // Block web access to failed deployment
            $htaccess_content = "Order Deny,Allow\nDeny from all\n<RequireAll>\nRequire all denied\n</RequireAll>";
            file_put_contents("$failed_dir/.htaccess", $htaccess_content);

            if ($verbose) {
                echo "  Created .htaccess to block web access to failed deployment\n";
            }
        } else {
            // Just remove the failed deployment
            if (is_dir($public_html)) {
                exec("rm -rf " . escapeshellarg($public_html));
            }
        }

        // Restore from backup
        if ($verbose) {
            echo "  Restoring from backup: $backup_dir\n";
        }

        mkdir($public_html);
        $copy_result = 0;
        exec("cp -r $backup_dir/* $public_html/", $output, $copy_result);

        if ($copy_result !== 0) {
            $result['error'] = "Failed to restore from backup";
            if ($verbose) {
                echo "  ERROR: " . $result['error'] . "\n";
            }
            return $result;
        }

        $result['backup_restored'] = true;

        // Fix permissions
        $perm_result = self::fixPermissions($public_html);
        $result['permissions_fixed'] = $perm_result['success'];

        if ($verbose) {
            if ($result['permissions_fixed']) {
                echo "  Permissions fixed successfully\n";
            } else {
                echo "  WARNING: Some permission fixes failed\n";
                foreach ($perm_result['warnings'] as $warning) {
                    echo "    " . $warning . "\n";
                }
            }
        }

        $result['success'] = true;

        if ($verbose) {
            echo "Rollback completed successfully\n";
        }

        return $result;
    }

    /**
     * Create backup of current deployment
     * @param string $source_dir Directory to backup
     * @param string $backup_dir Destination for backup
     * @param bool $verbose Echo progress to screen
     * @return array ['success' => bool,
     *                'files_backed_up' => int,
     *                'size_bytes' => int,
     *                'error' => string|null]
     */
    public static function createBackup($source_dir, $backup_dir, $verbose = false) {
        $result = [
            'success' => false,
            'files_backed_up' => 0,
            'size_bytes' => 0,
            'error' => null
        ];

        if ($verbose) {
            echo "Creating backup of: $source_dir\n";
            echo "  Destination: $backup_dir\n";
        }

        if (!is_dir($source_dir)) {
            $result['error'] = "Source directory does not exist: $source_dir";
            if ($verbose) {
                echo "  ERROR: " . $result['error'] . "\n";
            }
            return $result;
        }

        // Remove old backup if it exists
        if (is_dir($backup_dir)) {
            if ($verbose) {
                echo "  Removing old backup...\n";
            }
            exec("rm -rf " . escapeshellarg($backup_dir));
        }

        // Create backup directory
        mkdir($backup_dir, 0775, true);

        // Copy files
        $copy_result = 0;
        exec("cp -r $source_dir/* $backup_dir/", $output, $copy_result);

        if ($copy_result !== 0) {
            $result['error'] = "Failed to copy files to backup";
            if ($verbose) {
                echo "  ERROR: " . $result['error'] . "\n";
            }
            return $result;
        }

        // Count files and calculate size
        $file_count = 0;
        $total_size = 0;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backup_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            $file_count++;
            $total_size += $file->getSize();
        }

        $result['files_backed_up'] = $file_count;
        $result['size_bytes'] = $total_size;
        $result['success'] = true;

        if ($verbose) {
            $size_mb = round($total_size / 1024 / 1024, 2);
            echo "  Backup complete: $file_count files, {$size_mb}MB\n";
        }

        return $result;
    }

    // ============================================
    // UTILITY HELPERS
    // ============================================

    /**
     * Check if directory is empty (private helper)
     * @return bool True if empty or only contains . and ..
     */
    private static function isDirEmpty($dir) {
        if (!is_dir($dir)) {
            return true;
        }

        $files = scandir($dir);
        return count($files) <= 2; // Only . and ..
    }

    /**
     * Fix permissions after deployment (private helper)
     * @param string $path Directory or file path
     * @param string $owner Owner username
     * @param string $group Group name
     * @param string $mode Octal permissions as string
     * @return array ['success' => bool, 'warnings' => array]
     */
    private static function fixPermissions($path, $owner = 'www-data', $group = 'user1', $mode = '775') {
        $result = [
            'success' => true,
            'warnings' => []
        ];

        // Change ownership
        $chown_result = 0;
        exec("chown -R $owner:$group " . escapeshellarg($path) . " 2>&1", $output, $chown_result);

        if ($chown_result !== 0) {
            $result['warnings'][] = "chown failed: " . implode(' ', $output);
            $result['success'] = false;
        }

        // Change permissions
        $chmod_result = 0;
        exec("chmod -R $mode " . escapeshellarg($path) . " 2>&1", $output, $chmod_result);

        if ($chmod_result !== 0) {
            $result['warnings'][] = "chmod failed: " . implode(' ', $output);
            $result['success'] = false;
        }

        // Special handling for uploads directory (needs 777)
        $uploads_dir = $path . '/uploads';
        if (is_dir($uploads_dir)) {
            $chmod_uploads = 0;
            exec("chmod -R 777 " . escapeshellarg($uploads_dir) . " 2>&1", $output, $chmod_uploads);

            if ($chmod_uploads !== 0) {
                $result['warnings'][] = "chmod 777 on uploads failed: " . implode(' ', $output);
            }
        }

        return $result;
    }
}

?>
