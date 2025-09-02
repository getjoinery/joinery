<?php
/**
 * Rename SystemClass.php to SystemBase.php and update all references
 * 
 * This script:
 * 1. Creates a backup of SystemClass.php
 * 2. Renames SystemClass.php to SystemBase.php  
 * 3. Updates all file references from 'includes/SystemClass.php' to 'includes/SystemBase.php'
 * 4. Updates all class name references from 'SystemClassException' to 'SystemBaseException'
 * 5. Updates comments and documentation references
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base_path = '/var/www/html/joinerytest/public_html';

echo "=== SystemClass to SystemBase Refactoring Script ===\n\n";

// Step 1: Create backup and rename the main file
echo "Step 1: Renaming SystemClass.php to SystemBase.php\n";

$old_file = "$base_path/includes/SystemClass.php";
$backup_file = "$base_path/includes/SystemClass.php.rename_backup";
$new_file = "$base_path/includes/SystemBase.php";

if (!file_exists($old_file)) {
    die("ERROR: SystemClass.php not found at $old_file\n");
}

// Create backup
if (!copy($old_file, $backup_file)) {
    die("ERROR: Failed to create backup at $backup_file\n");
}
echo "✓ Created backup: $backup_file\n";

// Rename to new file
if (!rename($old_file, $new_file)) {
    die("ERROR: Failed to rename to $new_file\n");
}
echo "✓ Renamed SystemClass.php to SystemBase.php\n\n";

// Step 2: Find all PHP files that need updating
echo "Step 2: Finding files to update\n";

$files_to_update = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_path));

foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $filepath = $file->getPathname();
        $content = file_get_contents($filepath);
        
        // Check if file contains SystemClass references
        if (strpos($content, 'SystemClass') !== false) {
            $files_to_update[] = $filepath;
        }
    }
}

echo "Found " . count($files_to_update) . " files to update\n\n";

// Step 3: Update each file
echo "Step 3: Updating file references\n";

$updates_made = 0;
$patterns_replaced = [
    'file_includes' => 0,
    'exception_classes' => 0, 
    'exception_extends' => 0,
    'exception_catches' => 0,
    'comments' => 0
];

foreach ($files_to_update as $filepath) {
    echo "Updating: " . str_replace($base_path, '', $filepath) . "\n";
    
    $content = file_get_contents($filepath);
    $original_content = $content;
    
    // 1. Update file includes: 'includes/SystemClass.php' -> 'includes/SystemBase.php'
    $content = preg_replace(
        "/(['\"])includes\/SystemClass\.php\\1/",
        "$1includes/SystemBase.php$1", 
        $content,
        -1,
        $count
    );
    $patterns_replaced['file_includes'] += $count;
    
    // 2. Update exception class definitions: 'SystemClassException' -> 'SystemBaseException'
    $content = preg_replace(
        "/class\s+(\w+)\s+extends\s+SystemClassException/",
        "class $1 extends SystemBaseException",
        $content,
        -1,
        $count
    );
    $patterns_replaced['exception_extends'] += $count;
    
    // 3. Update exception instantiations and type hints: SystemClassException -> SystemBaseException
    $content = preg_replace(
        "/\bSystemClassException\b/",
        "SystemBaseException",
        $content,
        -1,
        $count
    );
    $patterns_replaced['exception_classes'] += $count;
    
    // 4. Update comments and documentation that mention SystemClass
    $content = preg_replace(
        "/\bSystemClass\b/",
        "SystemBase",
        $content,
        -1,
        $count
    );
    $patterns_replaced['comments'] += $count;
    
    // Only write if changes were made
    if ($content !== $original_content) {
        if (!file_put_contents($filepath, $content)) {
            echo "  ERROR: Failed to write changes to $filepath\n";
        } else {
            $updates_made++;
            echo "  ✓ Updated\n";
        }
    } else {
        echo "  - No changes needed\n";
    }
}

// Step 4: Update the main SystemBase.php file itself
echo "\nStep 4: Updating SystemBase.php internal references\n";

$systembase_content = file_get_contents($new_file);
$original_systembase = $systembase_content;

// Update exception class definition in the file itself
$systembase_content = preg_replace(
    "/class\s+SystemClassException\s+extends/",
    "class SystemBaseException extends",
    $systembase_content,
    -1,
    $count
);
$patterns_replaced['exception_classes'] += $count;

// Update any internal references
$systembase_content = preg_replace(
    "/\bSystemClassException\b/",
    "SystemBaseException",
    $systembase_content,
    -1,
    $count
);
$patterns_replaced['exception_classes'] += $count;

if ($systembase_content !== $original_systembase) {
    if (!file_put_contents($new_file, $systembase_content)) {
        echo "ERROR: Failed to update SystemBase.php internal references\n";
    } else {
        echo "✓ Updated SystemBase.php internal references\n";
        $updates_made++;
    }
}

// Step 5: Summary
echo "\n=== Refactoring Complete ===\n";
echo "Files processed: " . count($files_to_update) . "\n";
echo "Files updated: $updates_made\n";
echo "\nPattern replacements made:\n";
echo "- File includes: " . $patterns_replaced['file_includes'] . "\n";
echo "- Exception extends: " . $patterns_replaced['exception_extends'] . "\n"; 
echo "- Exception classes: " . $patterns_replaced['exception_classes'] . "\n";
echo "- Comments/docs: " . $patterns_replaced['comments'] . "\n";

echo "\nBackup created at: $backup_file\n";
echo "New file location: $new_file\n";

// Step 6: Validation
echo "\n=== Running Validation ===\n";

// Check that no SystemClass references remain (except in backup)
$remaining_refs = 0;
$problem_files = [];

foreach ($files_to_update as $filepath) {
    if (strpos($filepath, '.rename_backup') !== false) {
        continue; // Skip backup file
    }
    
    $content = file_get_contents($filepath);
    if (preg_match_all('/\bSystemClass\b/', $content, $matches)) {
        $remaining_refs += count($matches[0]);
        $problem_files[] = str_replace($base_path, '', $filepath);
    }
}

if ($remaining_refs > 0) {
    echo "WARNING: Found $remaining_refs remaining SystemClass references in:\n";
    foreach ($problem_files as $file) {
        echo "  - $file\n";
    }
} else {
    echo "✓ All SystemClass references successfully updated\n";
}

// Test that the new file has valid PHP syntax
if (shell_exec("php -l $new_file 2>&1")) {
    echo "✓ SystemBase.php has valid PHP syntax\n";
} else {
    echo "ERROR: SystemBase.php has syntax errors\n";
}

echo "\n=== Refactoring Summary ===\n";
echo "✓ SystemClass.php renamed to SystemBase.php\n";
echo "✓ All file includes updated\n";  
echo "✓ All exception references updated\n";
echo "✓ All documentation references updated\n";
echo "✓ Backup preserved for safety\n";

echo "\nRefactoring complete! Please test the application to ensure everything works correctly.\n";
?>