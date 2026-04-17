<?php
/**
 * PostgreSQL Duplicate Primary Key Resolution Utility
 *
 * This utility resolves duplicate primary key issues by reassigning duplicates to new valid values.
 * It preserves the first occurrence of each duplicate and assigns new sequential IDs to subsequent ones.
 *
 * PROBLEM:
 * Duplicate primary keys can occur due to:
 * - Direct database manipulation bypassing constraints
 * - Failed imports or merges
 * - Corruption or constraint violations during bulk operations
 * - Manual data fixes gone wrong
 *
 * SOLUTION:
 * This script:
 * 1. Detects duplicate primary key values in specified table
 * 2. Keeps the first occurrence (by ctid) of each duplicate
 * 3. Assigns new sequential primary key values to duplicate records
 * 4. Updates the sequence to ensure future inserts work correctly
 *
 * USAGE:
 * - Command line: php fix_duplicate_keys.php --table=tablename [--dry-run] [--verbose]
 * - Web interface: Access via browser with ?table=tablename parameter (requires permission level 10)
 *
 * SAFETY:
 * - Requires explicit table name to prevent accidental operations
 * - Dry-run mode shows what would be changed without making modifications
 * - Only processes tables with corresponding model classes
 * - Creates detailed log of all changes made
 *
 * @author Generated with Claude Code
 * @version 1.0
 * @since 2025-09-26
 */

// Determine if running from command line or web
$is_cli = (php_sapi_name() === 'cli');
$output_format = $is_cli ? 'text' : 'html';

// Permission check - require permission level 10 for web access
if (!$is_cli) {
    require_once(__DIR__ . '/../includes/PathHelper.php');
    require_once(PathHelper::getIncludePath('includes/SessionControl.php'));

    $session = SessionControl::get_instance();

    // Check if user is logged in and has sufficient permissions
    if (!$session->is_logged_in()) {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        echo "<p>Access denied. Please <a href='/login'>log in</a> to continue.</p>";
        exit;
    }

    // Check permission level
    if ($session->get_permission() < 10) {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        echo "<p>Access denied. Administrator permissions (level 10) required to access this utility.</p>";
        exit;
    }
}

// Parse parameters
$table_name = null;
$dry_run = false;
$verbose = false;

if ($is_cli) {
    // Parse command line arguments
    for ($i = 1; $i < $argc; $i++) {
        if (strpos($argv[$i], '--table=') === 0) {
            $table_name = substr($argv[$i], 8);
        } elseif ($argv[$i] === '--dry-run') {
            $dry_run = true;
        } elseif ($argv[$i] === '--verbose') {
            $verbose = true;
        } elseif ($argv[$i] === '--help') {
            echo "PostgreSQL Duplicate Primary Key Resolution Utility\n\n";
            echo "USAGE:\n";
            echo "  php fix_duplicate_keys.php --table=tablename [--dry-run] [--verbose] [--help]\n\n";
            echo "OPTIONS:\n";
            echo "  --table=name  Specify the table to check and fix (required)\n";
            echo "  --dry-run     Show what would be fixed without making changes\n";
            echo "  --verbose     Show detailed information about all operations\n";
            echo "  --help        Show this help message\n\n";
            echo "EXAMPLES:\n";
            echo "  php fix_duplicate_keys.php --table=usr_users --dry-run    # Preview changes\n";
            echo "  php fix_duplicate_keys.php --table=pro_products --verbose  # Fix with details\n";
            echo "  php fix_duplicate_keys.php --table=evt_events             # Fix quietly\n\n";
            exit(0);
        }
    }
} else {
    // Parse web parameters
    $table_name = $_GET['table'] ?? null;
    $dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
    $verbose = isset($_GET['verbose']) && $_GET['verbose'] === '1';
}

// Validate table name is provided
if (empty($table_name)) {
    if ($output_format === 'html') {
        echo "<h1>Error: Table name required</h1>";
        echo "<p>Please specify a table name using the <code>?table=tablename</code> parameter.</p>";
        echo "<p>Example: <code>" . htmlspecialchars($_SERVER['REQUEST_URI']) . "?table=usr_users</code></p>";
        echo "<p>Add <code>&dry_run=1</code> to preview changes without applying them.</p>";
        echo "<p>Add <code>&verbose=1</code> for detailed output.</p>";
    } else {
        echo "Error: Table name required\n";
        echo "Use --table=tablename to specify the table to check\n";
        echo "Use --help for more information\n";
        exit(1);
    }
    exit;
}

// Initialize output functions based on format
function output($message, $type = 'info') {
    global $output_format;

    if ($output_format === 'html') {
        $colors = [
            'info' => '#d4edda',
            'warning' => '#fff3cd',
            'error' => '#f8d7da',
            'skip' => '#e2e3e5',
            'header' => '#f0f8ff',
            'success' => '#d1ecf1'
        ];

        $color = $colors[$type] ?? $colors['info'];
        $icon = [
            'info' => 'ℹ️',
            'warning' => '⚠️',
            'error' => '❌',
            'skip' => '⏭️',
            'header' => '📊',
            'success' => '✅'
        ];

        echo "<div style='background: $color; padding: 10px; margin: 5px 0; border-radius: 5px;'>";
        echo "<strong>" . ($icon[$type] ?? $icon['info']) . "</strong> ";
        echo $message;
        echo "</div>\n";

        // Flush output for real-time display
        if (ob_get_level()) ob_flush();
        flush();
    } else {
        $prefix = [
            'info' => '[INFO] ',
            'warning' => '[WARN] ',
            'error' => '[ERROR] ',
            'skip' => '[SKIP] ',
            'header' => '[HEADER] ',
            'success' => '[SUCCESS] '
        ];

        echo ($prefix[$type] ?? '[INFO] ') . strip_tags($message) . "\n";
    }
}

// Start output
if ($output_format === 'html') {
    echo "<h2>PostgreSQL Duplicate Primary Key Resolution Utility</h2>\n";
    echo "<div style='background: #e7f3fe; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #2196F3;'>";
    echo "<strong>Table:</strong> " . htmlspecialchars($table_name) . "<br>";
    if ($dry_run) {
        echo "<strong>🔍 DRY RUN MODE</strong> - No changes will be made to the database.";
    }
    echo "</div>\n";
} else {
    echo "PostgreSQL Duplicate Primary Key Resolution Utility\n";
    echo str_repeat("=", 50) . "\n";
    echo "Table: $table_name\n";
    if ($dry_run) {
        echo "🔍 DRY RUN MODE - No changes will be made\n";
    }
    echo str_repeat("-", 50) . "\n";
}

try {
    // Load required dependencies
    require_once(__DIR__ . '/../includes/PathHelper.php');
    require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

    // Initialize database connection
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();

    // Find the model class for this table
    output("Looking for model class for table '$table_name'...", 'header');
    $classes = LibraryFunctions::discover_model_classes(['require_tablename' => true]);

    $model_class = null;
    $pkey_column = null;

    foreach ($classes as $class) {
        if ($class::$tablename === $table_name) {
            $model_class = $class;
            $pkey_column = $class::$pkey_column;
            break;
        }
    }

    if (!$model_class) {
        throw new Exception("No model class found for table '$table_name'. Please verify the table name.");
    }

    output("Found model class: $model_class", 'info');
    output("Primary key column: $pkey_column", 'info');

    // Check if table exists
    $check_sql = "SELECT EXISTS (
        SELECT FROM information_schema.tables
        WHERE table_name = :table_name
    )";
    $check_q = $dblink->prepare($check_sql);
    $check_q->execute(['table_name' => $table_name]);
    $exists = $check_q->fetchColumn();

    if (!$exists) {
        throw new Exception("Table '$table_name' does not exist in the database.");
    }

    // Find duplicate primary keys
    output("Searching for duplicate primary keys...", 'header');

    $dup_sql = "SELECT $pkey_column, COUNT(*) as count
                FROM $table_name
                GROUP BY $pkey_column
                HAVING COUNT(*) > 1
                ORDER BY $pkey_column";

    $dup_q = $dblink->prepare($dup_sql);
    $dup_q->execute();
    $duplicates = $dup_q->fetchAll(PDO::FETCH_ASSOC);

    if (empty($duplicates)) {
        output("No duplicate primary keys found in table '$table_name'.", 'success');
        if ($output_format === 'html') {
            echo "<h3>✅ Table is clean - no duplicates to fix!</h3>";
        } else {
            echo "\n✅ Table is clean - no duplicates to fix!\n";
        }
        exit(0);
    }

    // Report findings
    $total_duplicates = 0;
    foreach ($duplicates as $dup) {
        $total_duplicates += ($dup['count'] - 1); // Subtract 1 because we keep the original
    }

    output("Found " . count($duplicates) . " duplicate key values affecting $total_duplicates records that need new keys", 'warning');

    if ($verbose) {
        echo ($output_format === 'html') ? "<details><summary>Click to see duplicate details</summary><pre>" : "\nDuplicate details:\n";
        foreach ($duplicates as $dup) {
            $detail = sprintf("  Key %s: %d occurrences (%d to reassign)",
                             $dup[$pkey_column],
                             $dup['count'],
                             $dup['count'] - 1);
            echo $detail . "\n";
        }
        echo ($output_format === 'html') ? "</pre></details>" : "";
    }

    // Check for foreign key references to this table
    output("Checking for foreign key references to table '$table_name'...", 'header');

    $fk_check_sql = "
        SELECT
            tc.table_name AS referencing_table,
            kcu.column_name AS referencing_column,
            ccu.table_name AS referenced_table,
            ccu.column_name AS referenced_column
        FROM information_schema.table_constraints AS tc
        JOIN information_schema.key_column_usage AS kcu
            ON tc.constraint_name = kcu.constraint_name
            AND tc.table_schema = kcu.table_schema
        JOIN information_schema.constraint_column_usage AS ccu
            ON ccu.constraint_name = tc.constraint_name
            AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY'
            AND ccu.table_name = :table_name
            AND ccu.column_name = :column_name
    ";

    $fk_check_q = $dblink->prepare($fk_check_sql);
    $fk_check_q->execute(['table_name' => $table_name, 'column_name' => $pkey_column]);
    $foreign_keys = $fk_check_q->fetchAll(PDO::FETCH_ASSOC);

    $has_fk_issues = false;
    $fk_conflicts = [];

    if (!empty($foreign_keys)) {
        output("Found " . count($foreign_keys) . " foreign key reference(s) to this table:", 'warning');

        foreach ($foreign_keys as $fk) {
            output("  - Table '{$fk['referencing_table']}' column '{$fk['referencing_column']}' references '{$fk['referenced_table']}.{$fk['referenced_column']}'", 'info');

            // Check if any of the duplicate IDs are referenced
            foreach ($duplicates as $dup) {
                $ref_check_sql = "SELECT COUNT(*) FROM {$fk['referencing_table']} WHERE {$fk['referencing_column']} = :id";
                $ref_check_q = $dblink->prepare($ref_check_sql);
                $ref_check_q->execute(['id' => $dup[$pkey_column]]);
                $ref_count = $ref_check_q->fetchColumn();

                if ($ref_count > 0) {
                    $has_fk_issues = true;
                    $fk_conflicts[] = [
                        'id' => $dup[$pkey_column],
                        'table' => $fk['referencing_table'],
                        'column' => $fk['referencing_column'],
                        'count' => $ref_count
                    ];
                    output("    ⚠️ ID {$dup[$pkey_column]} is referenced by $ref_count record(s) in {$fk['referencing_table']}", 'error');
                }
            }
        }

        if ($has_fk_issues) {
            output("", 'error'); // Empty line for spacing
            output("CRITICAL: Cannot safely fix duplicates - foreign key references exist!", 'error');
            output("The duplicate primary keys are being referenced by other tables.", 'error');
            output("Changing these IDs would break referential integrity.", 'error');

            if ($output_format === 'html') {
                echo "<div style='background: #f8d7da; padding: 15px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #dc3545;'>";
                echo "<h3>🚫 Operation Blocked - Foreign Key Conflicts</h3>";
                echo "<p>The following duplicate IDs have foreign key references that prevent safe modification:</p>";
                echo "<ul>";
                foreach ($fk_conflicts as $conflict) {
                    echo "<li>ID <strong>{$conflict['id']}</strong> is referenced by <strong>{$conflict['count']}</strong> record(s) in <strong>{$conflict['table']}.{$conflict['column']}</strong></li>";
                }
                echo "</ul>";
                echo "<h4>Recommended Solutions:</h4>";
                echo "<ol>";
                echo "<li><strong>Manual Resolution:</strong> Manually update or delete the referencing records first</li>";
                echo "<li><strong>Cascade Update:</strong> Implement a cascade update strategy (requires careful planning)</li>";
                echo "<li><strong>Data Merge:</strong> Merge the duplicate records' related data before fixing duplicates</li>";
                echo "</ol>";
                echo "</div>";
            } else {
                echo "\n" . str_repeat("=", 50) . "\n";
                echo "FOREIGN KEY CONFLICTS DETECTED\n";
                echo str_repeat("-", 50) . "\n";
                foreach ($fk_conflicts as $conflict) {
                    echo "  ID {$conflict['id']}: {$conflict['count']} references in {$conflict['table']}.{$conflict['column']}\n";
                }
                echo "\nRecommended Solutions:\n";
                echo "1. Manual Resolution: Update/delete referencing records first\n";
                echo "2. Cascade Update: Implement cascade update strategy\n";
                echo "3. Data Merge: Merge related data before fixing duplicates\n";
            }

            exit(1);
        }
    } else {
        output("No foreign key references found to this table - safe to proceed", 'success');
    }

    // Get the current max ID to start assigning new IDs from
    $max_sql = "SELECT COALESCE(MAX($pkey_column), 0) as max_id FROM $table_name";
    $max_q = $dblink->prepare($max_sql);
    $max_q->execute();
    $max_id = $max_q->fetchColumn();

    $next_id = $max_id + 1;
    output("Current maximum ID: $max_id", 'info');
    output("Will assign new IDs starting from: $next_id", 'info');

    // Process each duplicate
    $fixes_applied = 0;
    $changes_log = [];

    foreach ($duplicates as $dup) {
        $dup_id = $dup[$pkey_column];

        // Get all records with this duplicate ID, ordered by ctid to keep the first one
        // ctid is PostgreSQL's internal row identifier
        $records_sql = "SELECT ctid, * FROM $table_name WHERE $pkey_column = :id ORDER BY ctid";
        $records_q = $dblink->prepare($records_sql);
        $records_q->execute(['id' => $dup_id]);
        $records = $records_q->fetchAll(PDO::FETCH_ASSOC);

        // Skip the first record (we keep it), reassign the rest
        $first = true;
        foreach ($records as $record) {
            if ($first) {
                $first = false;
                if ($verbose) {
                    output("Keeping original record with $pkey_column=$dup_id at ctid=" . $record['ctid'], 'skip');
                }
                continue;
            }

            if ($dry_run) {
                $change_msg = "WOULD CHANGE: Record at ctid=" . $record['ctid'] .
                             " from $pkey_column=$dup_id to $pkey_column=$next_id";
                output($change_msg, 'warning');
            } else {
                // Apply the fix
                $update_sql = "UPDATE $table_name SET $pkey_column = :new_id WHERE ctid = :ctid";
                $update_q = $dblink->prepare($update_sql);
                $update_q->execute(['new_id' => $next_id, 'ctid' => $record['ctid']]);

                $change_msg = "FIXED: Record at ctid=" . $record['ctid'] .
                             " changed from $pkey_column=$dup_id to $pkey_column=$next_id";
                output($change_msg, 'success');
            }

            $changes_log[] = [
                'ctid' => $record['ctid'],
                'old_id' => $dup_id,
                'new_id' => $next_id
            ];

            $fixes_applied++;
            $next_id++;
        }
    }

    // Update the sequence if we made changes
    if (!$dry_run && $fixes_applied > 0) {
        // Check if this model uses a serial primary key
        $field_specs = $model_class::$field_specifications ?? [];
        if (isset($field_specs[$pkey_column]) && isset($field_specs[$pkey_column]['serial']) && $field_specs[$pkey_column]['serial']) {
            $sequence_name = $table_name . '_' . $pkey_column . '_seq';

            output("Updating sequence '$sequence_name' to $next_id...", 'header');

            try {
                $seq_sql = "SELECT setval('$sequence_name', :val, false)";
                $seq_q = $dblink->prepare($seq_sql);
                $seq_q->execute(['val' => $next_id]);

                output("Sequence updated successfully", 'success');
            } catch (PDOException $e) {
                output("Warning: Could not update sequence: " . $e->getMessage(), 'warning');
            }
        }
    }

    // Output summary
    if ($output_format === 'html') {
        echo "<div style='background: #f8f9fa; padding: 15px; margin: 20px 0; border-radius: 5px; border: 1px solid #dee2e6;'>";
        echo "<h3>📊 Summary Report</h3>";
        echo "<table style='width: 100%; border-collapse: collapse;'>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Table:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd;'>" . htmlspecialchars($table_name) . "</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Model Class:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd;'>$model_class</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Primary Key:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd;'>$pkey_column</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Duplicate Values Found:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd;'>" . count($duplicates) . "</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Records " . ($dry_run ? "To Fix:" : "Fixed:") . "</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: " . ($fixes_applied > 0 ? "#d63384" : "#198754") . ";'>" . $fixes_applied . "</td></tr>";
        echo "</table>";

        if ($verbose && !empty($changes_log)) {
            echo "<details style='margin-top: 15px;'>";
            echo "<summary>View detailed change log (" . count($changes_log) . " changes)</summary>";
            echo "<pre style='background: #f8f9fa; padding: 10px; margin-top: 10px; border-radius: 5px;'>";
            foreach ($changes_log as $change) {
                echo sprintf("ctid=%-20s: %s=%d → %s=%d\n",
                           $change['ctid'],
                           $pkey_column, $change['old_id'],
                           $pkey_column, $change['new_id']);
            }
            echo "</pre>";
            echo "</details>";
        }

        echo "</div>";

        if ($dry_run && $fixes_applied > 0) {
            echo "<div style='background: #d1ecf1; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #0c5460;'>";
            echo "<strong>💡 Next Steps:</strong><br>";
            echo "Run without dry_run to apply the fixes:<br>";
            if ($is_cli) {
                echo "<code style='background: #f8f9fa; padding: 4px 8px; border-radius: 3px; font-family: monospace;'>php " . basename(__FILE__) . " --table=$table_name</code>";
            } else {
                $url = strtok($_SERVER["REQUEST_URI"], '?') . "?table=$table_name";
                echo "<a href='" . htmlspecialchars($url) . "' style='background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>Apply Fixes</a>";
            }
            echo "</div>";
        }

        echo "<h3>✅ Duplicate key resolution " . ($dry_run ? "analysis" : "process") . " complete!</h3>\n";
    } else {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "SUMMARY REPORT\n";
        echo str_repeat("-", 50) . "\n";
        echo "Table: $table_name\n";
        echo "Model Class: $model_class\n";
        echo "Primary Key: $pkey_column\n";
        echo "Duplicate Values Found: " . count($duplicates) . "\n";
        echo "Records " . ($dry_run ? "To Fix: " : "Fixed: ") . $fixes_applied . "\n";

        if ($dry_run && $fixes_applied > 0) {
            echo "\nNext Steps:\n";
            echo "Run without --dry-run flag to apply the fixes:\n";
            echo "php " . basename(__FILE__) . " --table=$table_name\n";
        }

        echo "\nDuplicate key resolution " . ($dry_run ? "analysis" : "process") . " complete!\n";
    }

} catch (Exception $e) {
    output("FATAL ERROR: " . $e->getMessage(), 'error');
    if ($output_format === 'text') {
        exit(1);
    }
}
?>