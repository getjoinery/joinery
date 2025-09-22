<?php
/**
 * Migration Script: Move /adm/includes to /assets
 *
 * This script automates the migration of admin assets from /adm/includes/ to /assets/
 * following the same structure as plugin assets.
 *
 * Run with: php utils/migrate_adm_includes_to_assets.php
 */

// Setup
$root_dir = dirname(__DIR__);
$source_dir = $root_dir . '/adm/includes';
$target_dir = $root_dir . '/assets';
$dry_run = false; // Set to true to preview changes without making them

if (isset($argv[1]) && $argv[1] === '--dry-run') {
    $dry_run = true;
    echo "🔍 DRY RUN MODE - No changes will be made\n\n";
}

echo "=================================================\n";
echo "Migration: /adm/includes/ → /assets/\n";
echo "=================================================\n\n";

// Step 1: Create target directory structure
echo "Step 1: Creating directory structure...\n";
$directories = [
    $target_dir,
    $target_dir . '/vendor',
    $target_dir . '/js',
    $target_dir . '/css',
    $target_dir . '/images'
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        if (!$dry_run) {
            mkdir($dir, 0755, true);
            echo "  ✅ Created: $dir\n";
        } else {
            echo "  📁 Would create: $dir\n";
        }
    } else {
        echo "  ⏩ Already exists: $dir\n";
    }
}

// Step 2: Move files to new locations
echo "\nStep 2: Moving files...\n";
$moves = [
    // Vendor libraries
    '/Trumbowyg-2-26' => '/vendor/Trumbowyg-2-26',
    '/jquery-timepicker-1.3.5' => '/vendor/jquery-timepicker-1.3.5',
    '/uikit-3.6.14' => '/vendor/uikit-3.6.14',
    // Scripts to js
    '/scripts' => '/js',
    // Images
    '/images' => '/images'
];

foreach ($moves as $source => $target) {
    $source_path = $source_dir . $source;
    $target_path = $target_dir . $target;

    if (file_exists($source_path)) {
        if ($source === '/scripts' && file_exists($source_path)) {
            // Special handling for scripts directory - move contents not directory
            $files = glob($source_path . '/*');
            foreach ($files as $file) {
                $filename = basename($file);
                $target_file = $target_path . '/' . $filename;
                if (!$dry_run) {
                    rename($file, $target_file);
                    echo "  ✅ Moved: scripts/$filename → js/$filename\n";
                } else {
                    echo "  📦 Would move: scripts/$filename → js/$filename\n";
                }
            }
        } elseif ($source === '/images' && file_exists($source_path)) {
            // Special handling for images directory - move contents not directory
            $files = glob($source_path . '/*');
            foreach ($files as $file) {
                $filename = basename($file);
                $target_file = $target_path . '/' . $filename;
                if (!$dry_run) {
                    rename($file, $target_file);
                    echo "  ✅ Moved: images/$filename → images/$filename\n";
                } else {
                    echo "  📦 Would move: images/$filename → images/$filename\n";
                }
            }
        } else {
            if (!$dry_run) {
                rename($source_path, $target_path);
                echo "  ✅ Moved: $source → $target\n";
            } else {
                echo "  📦 Would move: $source → $target\n";
            }
        }
    } else {
        echo "  ⚠️  Source not found: $source_path\n";
    }
}

// Step 3: Update code references
echo "\nStep 3: Updating code references...\n";

$files_to_update = [
    '/includes/FormWriterHTML5.php',
    '/includes/FormWriterBootstrap.php',
    '/includes/FormWriterUIKit.php',
    '/includes/AdminPage-uikit3.php',
    '/adm/admin_files.php'
];

$replacements = [
    // Trumbowyg
    '/adm/includes/Trumbowyg-2-26/' => '/assets/vendor/Trumbowyg-2-26/',
    // jQuery timepicker
    '/adm/includes/jquery-timepicker-1.3.5/' => '/assets/vendor/jquery-timepicker-1.3.5/',
    // UIKit
    '/adm/includes/uikit-3.6.14/' => '/assets/vendor/uikit-3.6.14/',
    // Scripts
    '/adm/includes/scripts/' => '/assets/js/',
    // Images
    '/adm/includes/images/' => '/assets/images/'
];

foreach ($files_to_update as $file) {
    $file_path = $root_dir . $file;
    if (file_exists($file_path)) {
        $content = file_get_contents($file_path);
        $original_content = $content;
        $changes = 0;

        foreach ($replacements as $old => $new) {
            $count = 0;
            $content = str_replace($old, $new, $content, $count);
            $changes += $count;
        }

        if ($changes > 0) {
            if (!$dry_run) {
                file_put_contents($file_path, $content);
                echo "  ✅ Updated: $file ($changes replacements)\n";
            } else {
                echo "  📝 Would update: $file ($changes replacements)\n";
            }
        } else {
            echo "  ⏩ No changes needed: $file\n";
        }
    } else {
        echo "  ⚠️  File not found: $file\n";
    }
}

// Step 4: Update serve.php to add static route
echo "\nStep 4: Updating serve.php routing...\n";
$serve_path = $root_dir . '/serve.php';
if (file_exists($serve_path)) {
    $content = file_get_contents($serve_path);

    // Check if assets route already exists
    if (strpos($content, "'/assets/*'") === false) {
        // Find the static routes section and add our route
        $search = "'static' => [\n        // Semantic placeholders for clear segment control";
        $replace = "'static' => [\n        '/assets/*' => ['cache' => 43200],  // Global system assets\n        // Semantic placeholders for clear segment control";

        if (strpos($content, $search) !== false) {
            $content = str_replace($search, $replace, $content);
            if (!$dry_run) {
                file_put_contents($serve_path, $content);
                echo "  ✅ Added /assets/* route to serve.php\n";
            } else {
                echo "  📝 Would add /assets/* route to serve.php\n";
            }
        } else {
            // Alternative location - after the comment
            $search = "    'static' => [\n        // Semantic placeholders";
            $replace = "    'static' => [\n        '/assets/*' => ['cache' => 43200],  // Global system assets\n        // Semantic placeholders";

            if (strpos($content, $search) !== false) {
                $content = str_replace($search, $replace, $content);
                if (!$dry_run) {
                    file_put_contents($serve_path, $content);
                    echo "  ✅ Added /assets/* route to serve.php\n";
                } else {
                    echo "  📝 Would add /assets/* route to serve.php\n";
                }
            } else {
                // Try more generic pattern
                $pattern = "/'static' => \[/";
                $replacement = "'static' => [\n        '/assets/*' => ['cache' => 43200],  // Global system assets";
                $content = preg_replace($pattern, $replacement, $content, 1);
                if (!$dry_run) {
                    file_put_contents($serve_path, $content);
                    echo "  ✅ Added /assets/* route to serve.php\n";
                } else {
                    echo "  📝 Would add /assets/* route to serve.php\n";
                }
            }
        }
    } else {
        echo "  ⏩ Route /assets/* already exists in serve.php\n";
    }
} else {
    echo "  ⚠️  serve.php not found\n";
}

// Step 5: Clean up empty directories
echo "\nStep 5: Cleaning up...\n";
$dirs_to_remove = [
    $source_dir . '/scripts',
    $source_dir . '/images',
    $source_dir
];

foreach ($dirs_to_remove as $dir) {
    if (file_exists($dir) && is_dir($dir)) {
        $is_empty = count(glob($dir . '/*')) === 0;
        if ($is_empty) {
            if (!$dry_run) {
                rmdir($dir);
                echo "  ✅ Removed empty directory: $dir\n";
            } else {
                echo "  🗑️  Would remove empty directory: $dir\n";
            }
        } else {
            echo "  ⏩ Directory not empty, keeping: $dir\n";
        }
    }
}

// Step 6: Verification
echo "\n=================================================\n";
echo "Verification:\n";
echo "=================================================\n";

// Check if new directories exist
$check_dirs = [
    '/assets/vendor/Trumbowyg-2-26',
    '/assets/vendor/jquery-timepicker-1.3.5',
    '/assets/vendor/uikit-3.6.14',
    '/assets/js',
    '/assets/images'
];

$all_good = true;
foreach ($check_dirs as $dir) {
    $full_path = $root_dir . $dir;
    if (file_exists($full_path)) {
        echo "  ✅ $dir exists\n";
    } else {
        echo "  ❌ $dir missing\n";
        $all_good = false;
    }
}

// Step 7: Test URLs
echo "\nTest URLs to verify:\n";
$test_urls = [
    'https://joinerytest.site/assets/vendor/Trumbowyg-2-26/dist/trumbowyg.min.js',
    'https://joinerytest.site/assets/vendor/uikit-3.6.14/css/uikit.min.css',
    'https://joinerytest.site/assets/js/jquery.validate-1.9.1.js',
    'https://joinerytest.site/assets/images/pdf_icon_80px.png'
];

echo "\nPlease test these URLs after migration:\n";
foreach ($test_urls as $url) {
    echo "  🔗 $url\n";
}

// Summary
echo "\n=================================================\n";
if ($dry_run) {
    echo "DRY RUN COMPLETE - Review the changes above\n";
    echo "Run without --dry-run flag to apply changes\n";
} else {
    if ($all_good) {
        echo "✅ MIGRATION COMPLETE!\n";
        echo "\nNext steps:\n";
        echo "1. Test the admin page editor (Trumbowyg)\n";
        echo "2. Test admin pages with date/time pickers\n";
        echo "3. Check file manager icons\n";
        echo "4. Verify no console errors\n";
    } else {
        echo "⚠️  MIGRATION COMPLETED WITH WARNINGS\n";
        echo "Please review the verification results above\n";
    }
}
echo "=================================================\n";

// Rollback instructions
if (!$dry_run) {
    echo "\nRollback instructions saved to: /tmp/rollback_assets_migration.sh\n";
    $rollback = "#!/bin/bash\n";
    $rollback .= "# Rollback script for assets migration\n\n";
    $rollback .= "# Move files back\n";
    $rollback .= "mv $target_dir/vendor/Trumbowyg-2-26 $source_dir/\n";
    $rollback .= "mv $target_dir/vendor/jquery-timepicker-1.3.5 $source_dir/\n";
    $rollback .= "mv $target_dir/vendor/uikit-3.6.14 $source_dir/\n";
    $rollback .= "mkdir -p $source_dir/scripts\n";
    $rollback .= "mv $target_dir/js/*.js $source_dir/scripts/\n";
    $rollback .= "mkdir -p $source_dir/images\n";
    $rollback .= "mv $target_dir/images/* $source_dir/images/\n";
    $rollback .= "\n# Restore file contents\n";
    $rollback .= "echo 'Please restore the original file contents from backup'\n";
    $rollback .= "echo 'Files to restore:'\n";
    foreach ($files_to_update as $file) {
        $rollback .= "echo '  - $file'\n";
    }
    $rollback .= "echo '  - /serve.php'\n";

    file_put_contents('/tmp/rollback_assets_migration.sh', $rollback);
    chmod('/tmp/rollback_assets_migration.sh', 0755);
}
?>