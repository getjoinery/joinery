<?php
/**
 * PostgreSQL Sequence Synchronization Utility
 * 
 * This utility fixes PostgreSQL sequence synchronization issues that can occur when:
 * - Data is imported directly without using the model's save() method
 * - Sequences get out of sync due to manual database operations
 * - Database restores don't properly update sequence values
 * 
 * PROBLEM:
 * When a sequence's current value is less than or equal to the maximum primary key
 * value in the table, the next insert will fail with a duplicate key error.
 * 
 * SOLUTION:
 * This script automatically detects and fixes sequence synchronization issues by:
 * 1. Scanning all model classes that use serial primary keys
 * 2. Checking if the sequence value is properly synchronized with table data
 * 3. Updating sequences to the correct value (max_table_value + 1)
 * 
 * USAGE:
 * - Command line: php fix_sequences.php [--dry-run] [--verbose]
 * - Web interface: Access via browser for HTML output (requires permission level 10)
 * 
 * SAFETY:
 * - Read-only mode available with --dry-run flag
 * - Only processes tables with corresponding model classes
 * - Validates sequence existence before attempting fixes
 * - Reports all actions taken for audit purposes
 * 
 * @author Generated with Claude Code
 * @version 1.0
 * @since 2025-09-01
 */

// Determine if running from command line or web
$is_cli = (php_sapi_name() === 'cli');
$output_format = $is_cli ? 'text' : 'html';

// Permission check - require permission level 10 for web access
if (!$is_cli) {
    require_once(__DIR__ . '/../includes/PathHelper.php');
    PathHelper::requireOnce('includes/SessionControl.php');
    
    // Check if user is logged in and has sufficient permissions
    if (!SessionControl::requireLogin()) {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        echo "<p>Access denied. Please log in to continue.</p>";
        exit;
    }
    
    if (!SessionControl::checkPermissionLevel(10)) {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        echo "<p>Access denied. Administrator permissions (level 10) required to access this utility.</p>";
        exit;
    }
}

// Parse command line arguments
$dry_run = false;
$verbose = false;
if ($is_cli && $argc > 1) {
    for ($i = 1; $i < $argc; $i++) {
        switch ($argv[$i]) {
            case '--dry-run':
                $dry_run = true;
                break;
            case '--verbose':
                $verbose = true;
                break;
            case '--help':
                echo "PostgreSQL Sequence Synchronization Utility\n\n";
                echo "USAGE:\n";
                echo "  php fix_sequences.php [--dry-run] [--verbose] [--help]\n\n";
                echo "OPTIONS:\n";
                echo "  --dry-run   Show what would be fixed without making changes\n";
                echo "  --verbose   Show detailed information about all sequences\n";
                echo "  --help      Show this help message\n\n";
                echo "EXAMPLES:\n";
                echo "  php fix_sequences.php --dry-run    # Preview changes\n";
                echo "  php fix_sequences.php --verbose    # Fix with detailed output\n";
                echo "  php fix_sequences.php             # Fix sequences quietly\n\n";
                exit(0);
        }
    }
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
            'header' => '#f0f8ff'
        ];
        
        $color = $colors[$type] ?? $colors['info'];
        echo "<div style='background: $color; padding: 10px; margin: 5px 0; border-radius: 5px;'>";
        echo "<strong>" . ($type === 'info' ? '✅' : ($type === 'warning' ? '⚠️' : ($type === 'error' ? '❌' : ($type === 'skip' ? '⏭️' : '📊')))) . "</strong> ";
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
            'header' => '[HEADER] '
        ];
        
        echo ($prefix[$type] ?? '[INFO] ') . strip_tags($message) . "\n";
    }
}

// Start output
if ($output_format === 'html') {
    echo "<h2>PostgreSQL Sequence Synchronization Utility</h2>\n";
    if ($dry_run) {
        echo "<div style='background: #cce5ff; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #007bff;'>";
        echo "<strong>🔍 DRY RUN MODE</strong><br>";
        echo "This is a preview mode. No changes will be made to the database.";
        echo "</div>\n";
    }
} else {
    echo "PostgreSQL Sequence Synchronization Utility\n";
    echo str_repeat("=", 50) . "\n";
    if ($dry_run) {
        echo "🔍 DRY RUN MODE - No changes will be made\n";
        echo str_repeat("-", 50) . "\n";
    }
}

try {
    // Load required dependencies
    require_once(__DIR__ . '/../includes/PathHelper.php');
    PathHelper::requireOnce('includes/DbConnector.php');
    PathHelper::requireOnce('includes/LibraryFunctions.php');

    // Initialize database connection
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();

    // Get all model classes to map sequences to tables correctly
    output("Discovering model classes...", 'header');
    $classes = LibraryFunctions::discover_model_classes(['require_tablename' => true]);
    output("Found " . count($classes) . " model classes", 'info');

    $stats = [
        'checked' => 0,
        'fixed' => 0,
        'skipped' => 0,
        'errors' => 0
    ];

    foreach ($classes as $class) {
        try {
            $stats['checked']++;
            $table_name = $class::$tablename;
            $pkey_column = $class::$pkey_column;
            
            // Check if this model uses a serial primary key
            $field_specs = $class::$field_specifications ?? [];
            if (!isset($field_specs[$pkey_column]) || !isset($field_specs[$pkey_column]['serial']) || !$field_specs[$pkey_column]['serial']) {
                if ($verbose) {
                    output("SKIP: $class - Does not use serial primary key", 'skip');
                }
                $stats['skipped']++;
                continue;
            }
            
            // Build expected sequence name
            $sequence_name = $table_name . '_' . $pkey_column . '_seq';
            
            // Check if this sequence exists
            $seq_sql = "SELECT last_value FROM pg_sequences WHERE sequencename = ? AND schemaname = 'public'";
            $seq_q = $dblink->prepare($seq_sql);
            $seq_q->execute([$sequence_name]);
            $seq_result = $seq_q->fetch(PDO::FETCH_ASSOC);
            
            if (!$seq_result) {
                output("NO SEQUENCE: $sequence_name (class: $class)", 'skip');
                $stats['skipped']++;
                continue;
            }

            $current_value = $seq_result['last_value'];
            
            // Get current max value from table
            $max_sql = "SELECT COALESCE(MAX($pkey_column), 0) as max_val FROM $table_name";
            $max_q = $dblink->prepare($max_sql);
            $max_q->execute();
            $max_result = $max_q->fetch(PDO::FETCH_ASSOC);
            $max_val = $max_result['max_val'];
            
            // Check if sequence needs fixing
            if ($current_value <= $max_val && $max_val > 0) {
                $new_value = $max_val + 1;
                
                if ($dry_run) {
                    output("WOULD FIX: $sequence_name (class: $class)<br>" .
                          "Current sequence value: $current_value<br>" .
                          "Max table value: $max_val<br>" .
                          "Would set sequence to: $new_value", 'warning');
                } else {
                    output("FIXING: $sequence_name (class: $class)<br>" .
                          "Current sequence value: $current_value<br>" .
                          "Max table value: $max_val<br>" .
                          "Setting sequence to: $new_value", 'warning');
                    
                    // Fix the sequence
                    $fix_sql = "SELECT setval('$sequence_name', $new_value, false)";
                    $fix_q = $dblink->prepare($fix_sql);
                    $fix_q->execute();
                    
                    output("FIXED: $sequence_name successfully updated", 'info');
                }
                $stats['fixed']++;
            } else {
                if ($verbose) {
                    output("OK: $sequence_name (class: $class) (seq: $current_value, max: $max_val)", 'info');
                }
            }
            
        } catch (Exception $e) {
            output("ERROR: $class - " . $e->getMessage(), 'error');
            $stats['errors']++;
        }
    }

    // Output summary
    if ($output_format === 'html') {
        echo "<div style='background: #f8f9fa; padding: 15px; margin: 20px 0; border-radius: 5px; border: 1px solid #dee2e6;'>";
        echo "<h3>📊 Summary Report</h3>";
        echo "<table style='width: 100%; border-collapse: collapse;'>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Models Checked:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd;'>" . $stats['checked'] . "</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Sequences " . ($dry_run ? "Needing Fix:" : "Fixed:") . "</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: " . ($stats['fixed'] > 0 ? "#d63384" : "#198754") . ";'>" . $stats['fixed'] . "</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Skipped (No Serial Key):</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd;'>" . $stats['skipped'] . "</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>Errors:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: " . ($stats['errors'] > 0 ? "#dc3545" : "#198754") . ";'>" . $stats['errors'] . "</td></tr>";
        echo "</table>";
        echo "</div>";
        
        if ($dry_run && $stats['fixed'] > 0) {
            echo "<div style='background: #d1ecf1; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #0c5460;'>";
            echo "<strong>💡 Next Steps:</strong><br>";
            echo "Run without --dry-run flag to apply the fixes:<br>";
            echo "<code style='background: #f8f9fa; padding: 4px 8px; border-radius: 3px; font-family: monospace;'>php " . basename(__FILE__) . "</code>";
            echo "</div>";
        }
        
        echo "<h3>✅ Sequence synchronization check complete!</h3>\n";
    } else {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "SUMMARY REPORT\n";
        echo str_repeat("-", 50) . "\n";
        echo "Models Checked: " . $stats['checked'] . "\n";
        echo "Sequences " . ($dry_run ? "Needing Fix: " : "Fixed: ") . $stats['fixed'] . "\n";
        echo "Skipped (No Serial Key): " . $stats['skipped'] . "\n";
        echo "Errors: " . $stats['errors'] . "\n";
        
        if ($dry_run && $stats['fixed'] > 0) {
            echo "\nNext Steps:\n";
            echo "Run without --dry-run flag to apply the fixes:\n";
            echo "php " . basename(__FILE__) . "\n";
        }
        
        echo "\nSequence synchronization check complete!\n";
    }

} catch (Exception $e) {
    output("FATAL ERROR: " . $e->getMessage(), 'error');
    if ($output_format === 'text') {
        exit(1);
    }
}
?>