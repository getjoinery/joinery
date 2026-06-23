<?php
/**
 * Scaffolding / Code Generator CLI.
 *
 *   php utils/scaffold.php <manifest.json> [--force] [--dry-run]
 *
 *   --force     overwrite existing files (default: refuse and report collisions)
 *   --dry-run   render and validate, print the plan, write nothing
 *
 * Which page sets are emitted is controlled declaratively by surfaces: in the
 * manifest — not by a CLI flag. Full reference: docs/scaffolding.md.
 *
 * The executable body runs only when this file is the invoked script (see the
 * entry-point guard at the bottom), so requiring it for analysis is harmless.
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/scaffold/ScaffoldGenerator.php'));

/**
 * Run the CLI. Returns a process exit code.
 *
 * @param string[] $args argv minus the script name
 */
function scaffold_main(array $args): int {
    $force   = in_array('--force', $args, true);
    $dry_run = in_array('--dry-run', $args, true);
    $positional = array_values(array_filter($args, function ($a) { return strpos($a, '--') !== 0; }));

    if (empty($positional)) {
        fwrite(STDERR, "Usage: php utils/scaffold.php <manifest.json> [--force] [--dry-run]\n");
        return 1;
    }

    $manifest_path = $positional[0];
    if (!is_file($manifest_path)) {
        fwrite(STDERR, "Manifest not found: $manifest_path\n");
        return 1;
    }

    $manifest = json_decode(file_get_contents($manifest_path), true);
    if ($manifest === null && json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "Manifest is not valid JSON: " . json_last_error_msg() . "\n");
        return 1;
    }

    $gen = new ScaffoldGenerator($manifest);

    // --- validate manifest (--force demotes existence guards to warnings) ---
    $errors = $gen->validate($force);
    if (!empty($errors)) {
        fwrite(STDERR, "Manifest validation failed:\n  - " . implode("\n  - ", $errors) . "\n");
        return 1;
    }
    foreach ($gen->warnings() as $w) {
        echo "Warning (--force): " . $w . "\n";
    }

    // --- derived names + planned paths (confirmation banner) ---
    $names = $gen->derivedNames();
    echo "Scaffolding " . $names['entity'] . " / " . $names['multi'] . "\n";
    echo "  table:       " . $names['table'] . "\n";
    echo "  primary key: " . $names['pkey'] . "\n";
    echo "  surfaces:    " . $names['surfaces'] . "\n";
    echo "  public URL:  " . $names['public_url'] . "\n";
    echo "  admin URL:   " . $names['admin_url'] . "\n\n";

    $files = $gen->files();
    echo "Files (" . count($files) . "):\n";
    foreach (array_keys($files) as $rel) {
        echo "  " . $rel . "\n";
    }
    echo "\n";

    // --- post-generation guarantees: php -l + validator over the output ---
    echo "Validating generated output...\n";
    $failures = scaffold_validate_output($files);
    if (!empty($failures)) {
        fwrite(STDERR, "\nGenerated code failed validation (aborting, nothing written):\n  - "
            . implode("\n  - ", $failures) . "\n");
        return 1;
    }
    echo "  php -l: clean. validate_php_file.php: 0 pattern violations.\n\n";

    // --- third guarantee: prove the data class round-trips through the DB ---
    echo "Verifying database roundtrip...\n";
    $rt = $gen->verifyDatabaseRoundtrip();
    if (!$rt['ran']) {
        echo "  database roundtrip: skipped (" . $rt['skipped_reason'] . ").\n\n";
    } elseif (!empty($rt['failures'])) {
        fwrite(STDERR, "\nDatabase roundtrip failed (aborting, nothing written):\n  - "
            . implode("\n  - ", $rt['failures']) . "\n");
        return 1;
    } else {
        echo "  roundtrip: table built, row inserted, PK retrieved via canonical sequence, rolled back.\n\n";
    }

    if ($dry_run) {
        echo "Dry run — no files written. Re-run without --dry-run to write.\n";
        return 0;
    }

    // --- write ---
    try {
        $result = $gen->write($force);
    } catch (ScaffoldGeneratorException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    echo "Wrote " . count($result['written']) . " files:\n";
    foreach ($result['written'] as $rel) {
        echo "  " . $rel . "\n";
    }

    $into = $manifest['into'] ?? 'core';
    echo "\nStill yours to do:\n";
    if ($into === 'core') {
        echo "  1. Run update_database (admin utilities) to create the table.\n";
    } else {
        echo "  1. Run \"Sync with Filesystem\" on the plugin (admin Plugins page) to create the table.\n";
    }
    echo "  2. Fill in any emitted // TODO: stubs (business rules, non-descriptor fields).\n";
    return 0;
}

/**
 * Run php -l and validate_php_file.php over each rendered file (written to a
 * temp dir). Returns a list of failure descriptions; empty means all clean.
 * Cross-file "missing method" notes are advisory only and never fail the gate.
 *
 * @param array<string,string> $files
 * @return string[]
 */
function scaffold_validate_output(array $files): array {
    $failures = [];
    $validator = realpath(__DIR__ . '/../../maintenance_scripts/dev_tools/validate_php_file.php');
    $tmpdir = sys_get_temp_dir() . '/scaffold_' . getmypid();
    @mkdir($tmpdir, 0777, true);

    foreach ($files as $rel => $contents) {
        $tmp = $tmpdir . '/' . str_replace('/', '__', $rel);
        file_put_contents($tmp, $contents);

        $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        if (strpos((string)$lint, 'No syntax errors detected') === false) {
            $failures[] = "$rel: syntax error — " . trim((string)$lint);
        }

        if ($validator) {
            $out = shell_exec('php ' . escapeshellarg($validator) . ' ' . escapeshellarg($tmp) . ' 2>&1');
            if (preg_match('/Total pattern violations:\s*(\d+)/', (string)$out, $mm) && (int)$mm[1] > 0) {
                $failures[] = "$rel: {$mm[1]} pattern violation(s) (run validate_php_file.php to see)";
            }
        }

        @unlink($tmp);
    }
    @rmdir($tmpdir);
    return $failures;
}

// --- entry-point guard: execute only when invoked directly as a script ------
$invoked = $_SERVER['argv'][0] ?? '';
if (PHP_SAPI === 'cli' && $invoked !== '' && realpath($invoked) === realpath(__FILE__)) {
    exit(scaffold_main(array_slice($_SERVER['argv'], 1)));
}
