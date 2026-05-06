<?php
/**
 * Normalize logic function signatures — Step 4 of logic_code_refactor.md.
 *
 * Transforms every _logic() function with exactly two GET/POST parameters
 * (e.g. $get_vars, $post_vars or $get, $post) to (array $input): LogicResult.
 * Renames all parameter references in the body, and updates call sites from
 * foo_logic($_GET, $_POST) to foo_logic(array_merge($_GET, $_POST)).
 *
 * Files with extra parameters beyond the GET/POST pair are skipped — those
 * are mixed page-handlers targeted by Step 5.
 *
 * Modes:
 *   (default) --scan    Report what would change; write nothing
 *   --apply             Apply all transformations
 *   --verify            Confirm all target files have the new signature
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

// ---------------------------------------------------------------------------
// Directories
// ---------------------------------------------------------------------------

$web_root = rtrim(PathHelper::getIncludePath(''), '/') . '/';

$logic_dirs = [PathHelper::getIncludePath('logic')];

// Collect plugin logic directories
$plugin_base = PathHelper::getIncludePath('plugins');
foreach (glob($plugin_base . '/*', GLOB_ONLYDIR) as $plugin_dir) {
    $d = $plugin_dir . '/logic';
    if (is_dir($d)) {
        $logic_dirs[] = $d;
    }
}

// Directories containing call sites (views + plugin views/admin dirs)
$callsite_dirs = [PathHelper::getIncludePath('views')];
foreach (glob($plugin_base . '/*', GLOB_ONLYDIR) as $plugin_dir) {
    foreach (['views', 'admin'] as $sub) {
        $d = $plugin_dir . '/' . $sub;
        if (is_dir($d)) {
            $callsite_dirs[] = $d;
        }
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Recursively collect all *.php files in a directory. */
function collect_php_files(string $dir): array {
    $result = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $result[] = $file->getPathname();
        }
    }
    sort($result);
    return $result;
}

/**
 * Shorten an absolute path to a display-friendly relative path.
 */
function short_path(string $path, string $web_root): string {
    return str_replace($web_root, '', $path);
}

/**
 * Analyze a logic file for its signature state.
 *
 * Returns an array with keys:
 *   status   'done' | 'transform' | 'skip' | 'no_fn'
 *   fn       function name matched (if any)
 *   p1       first param name without $ (if transform)
 *   p2       second param name without $ (if transform)
 *   sig      raw parameter list string
 *   reason   skip reason (if skip)
 */
function analyze_logic_file(string $filepath): array {
    $contents = file_get_contents($filepath);

    // Already normalized?
    if (preg_match('/\bfunction\s+\w+_logic\s*\(\s*array\s+\$input\s*\)\s*:\s*LogicResult\b/', $contents)) {
        preg_match('/\bfunction\s+(\w+_logic)\s*\(/', $contents, $m);
        return ['status' => 'done', 'fn' => $m[1] ?? '?', 'p1' => 'input', 'p2' => 'input', 'sig' => 'array $input'];
    }

    // Find the _logic function signature (handles spaces, leading tabs)
    if (!preg_match('/\bfunction\s+(\w+_logic)\s*\(([^)]*)\)/', $contents, $m)) {
        return ['status' => 'no_fn', 'fn' => '', 'p1' => '', 'p2' => '', 'sig' => '', 'reason' => 'no _logic function found'];
    }

    $fn_name   = $m[1];
    $params_raw = $m[2]; // raw param string, e.g. "$get_vars, $post_vars" or "$get_vars, $post_vars, $event"

    // Split on comma, trim each token
    $param_tokens = array_values(array_filter(array_map('trim', explode(',', $params_raw))));

    if (count($param_tokens) === 0) {
        return ['status' => 'skip', 'fn' => $fn_name, 'p1' => '', 'p2' => '',
                'sig' => $params_raw, 'reason' => 'no params'];
    }

    if (count($param_tokens) > 2) {
        return ['status' => 'skip', 'fn' => $fn_name, 'p1' => '', 'p2' => '',
                'sig' => $params_raw, 'reason' => 'extra params — Step 5'];
    }

    if (count($param_tokens) < 2) {
        return ['status' => 'skip', 'fn' => $fn_name, 'p1' => '', 'p2' => '',
                'sig' => $params_raw, 'reason' => 'fewer than 2 params'];
    }

    // Extract variable names (strip leading $)
    if (!preg_match('/\$(\w+)/', $param_tokens[0], $p1m) ||
        !preg_match('/\$(\w+)/', $param_tokens[1], $p2m)) {
        return ['status' => 'skip', 'fn' => $fn_name, 'p1' => '', 'p2' => '',
                'sig' => $params_raw, 'reason' => 'could not extract param names'];
    }

    return [
        'status' => 'transform',
        'fn'     => $fn_name,
        'p1'     => $p1m[1],
        'p2'     => $p2m[1],
        'sig'    => trim($params_raw),
    ];
}

/**
 * Apply the signature and body transformation to a logic file.
 * Returns the new file contents (unchanged if match fails).
 */
function transform_logic_file(string $filepath, string $fn_name, string $p1, string $p2): string {
    $contents = file_get_contents($filepath);

    // Replace the signature (handles spaces, tab-indented declarations)
    $sig_pattern = '/\bfunction\s+' . preg_quote($fn_name, '/') . '\s*\([^)]*\)/';
    $new_contents = preg_replace($sig_pattern, 'function ' . $fn_name . '(array $input): LogicResult', $contents, 1, $replaced);

    if (!$replaced) {
        return $contents; // Could not match — leave unchanged
    }

    // Rename $p1 → $input everywhere in the file (word boundary prevents partial matches)
    $new_contents = preg_replace('/\$' . preg_quote($p1, '/') . '\b/', '$input', $new_contents);

    // Rename $p2 → $input (skip if same name as p1 — already done)
    if ($p2 !== $p1) {
        $new_contents = preg_replace('/\$' . preg_quote($p2, '/') . '\b/', '$input', $new_contents);
    }

    return $new_contents;
}

/**
 * Update call sites in a file: foo_logic($_GET, $_POST) → foo_logic(array_merge($_GET, $_POST)).
 * Only matches calls with exactly two superglobal args (no trailing args — those are Step 5 targets).
 * Returns [new_contents, count_of_replacements].
 */
function update_callsites(string $contents): array {
    $count = 0;
    // Match: NAME_logic( $_GET , $_POST ) — closing ) must immediately follow $_POST (no extra args)
    $new_contents = preg_replace_callback(
        '/(\b\w+_logic\s*\(\s*)\$_GET\s*,\s*\$_POST(\s*\))/',
        function ($m) use (&$count) {
            $count++;
            return $m[1] . 'array_merge($_GET, $_POST)' . $m[2];
        },
        $contents
    );
    return [$new_contents ?? $contents, $count];
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
$mode = in_array('--apply', $args) ? 'apply' : (in_array('--verify', $args) ? 'verify' : 'scan');

echo "=== normalize_logic_signatures.php — mode: {$mode} ===\n\n";

// ---------------------------------------------------------------------------
// Phase 1: Logic files
// ---------------------------------------------------------------------------

echo "=== Phase 1: Logic files ===\n\n";

$transform_targets = []; // filepath => analysis info
$skip_files        = [];
$done_files        = [];

foreach ($logic_dirs as $logic_dir) {
    foreach (collect_php_files($logic_dir) as $filepath) {
        $info  = analyze_logic_file($filepath);
        $short = short_path($filepath, $web_root);

        switch ($info['status']) {
            case 'done':
                $done_files[] = $filepath;
                echo 'DONE   ' . $short . "  (already normalized)\n";
                break;

            case 'transform':
                $transform_targets[$filepath] = $info;
                echo 'XFORM  ' . $short . '  ($' . $info['p1'] . ', $' . $info['p2'] . ") → (array \$input): LogicResult\n";
                break;

            case 'skip':
                $skip_files[] = $filepath;
                echo 'SKIP   ' . $short . '  (' . trim($info['sig']) . ')  — ' . ($info['reason'] ?? '') . "\n";
                break;

            case 'no_fn':
                echo 'SKIP   ' . $short . "  — no _logic function\n";
                break;
        }
    }
}

$n_xform = count($transform_targets);
$n_skip  = count($skip_files);
$n_done  = count($done_files);

echo "\n  {$n_xform} to transform, {$n_skip} skipped, {$n_done} already done.\n\n";

if ($mode === 'apply') {
    $phase1_wrote = 0;
    foreach ($transform_targets as $filepath => $info) {
        $new_contents = transform_logic_file($filepath, $info['fn'], $info['p1'], $info['p2']);
        if ($new_contents !== file_get_contents($filepath)) {
            file_put_contents($filepath, $new_contents);
            $phase1_wrote++;
            echo 'WROTE  ' . short_path($filepath, $web_root) . "\n";
        } else {
            echo 'WARN   ' . short_path($filepath, $web_root) . "  — no change (check manually)\n";
        }
    }
    echo "\n  Phase 1 applied: {$phase1_wrote} files written.\n\n";
}

// ---------------------------------------------------------------------------
// Phase 2: Call sites
// ---------------------------------------------------------------------------

echo "=== Phase 2: Call sites ===\n\n";

$callsite_total     = 0;
$callsite_files     = 0;

foreach ($callsite_dirs as $dir) {
    foreach (collect_php_files($dir) as $filepath) {
        $contents = file_get_contents($filepath);
        [$new_contents, $count] = update_callsites($contents);

        if ($count > 0) {
            $callsite_total += $count;
            $callsite_files++;
            $short = short_path($filepath, $web_root);

            if ($mode === 'apply') {
                file_put_contents($filepath, $new_contents);
                echo 'WROTE  ' . $short . "  ({$count} site" . ($count > 1 ? 's' : '') . ")\n";
            } else {
                // Scan: show what lines would change
                $lines = explode("\n", $contents);
                foreach ($lines as $lineno => $line) {
                    if (preg_match('/\b\w+_logic\s*\(\s*\$_GET\s*,\s*\$_POST\s*\)/', $line)) {
                        echo 'UPDATE ' . $short . ':' . ($lineno + 1) . '  ' . trim($line) . "\n";
                    }
                }
            }
        }
    }
}

echo "\n  {$callsite_total} call site" . ($callsite_total !== 1 ? 's' : '') . " in {$callsite_files} file" . ($callsite_files !== 1 ? 's' : '') . ".\n\n";

if ($mode === 'apply') {
    echo "Phase 2 applied.\n\n";
}

// ---------------------------------------------------------------------------
// Phase 2b: apiv1.php — dynamic call_user_func invocation
// ---------------------------------------------------------------------------
// apiv1.php calls: call_user_func($logic_function, $get_params, $post_params)
// After Step 4 all logic functions take one merged array, so this must become:
//   call_user_func($logic_function, array_merge($get_params, $post_params))
// ---------------------------------------------------------------------------

echo "=== Phase 2b: apiv1.php dynamic invocation ===\n\n";

$apiv1_path = PathHelper::getIncludePath('api/apiv1.php');
$apiv1_pattern = '/call_user_func\s*\(\s*\$logic_function\s*,\s*\$get_params\s*,\s*\$post_params\s*\)/';
$apiv1_replacement = 'call_user_func($logic_function, array_merge($get_params, $post_params))';

$apiv1_contents = file_get_contents($apiv1_path);
$apiv1_matched  = preg_match($apiv1_pattern, $apiv1_contents);

if ($apiv1_matched) {
    if ($mode === 'apply') {
        $apiv1_new = preg_replace($apiv1_pattern, $apiv1_replacement, $apiv1_contents);
        file_put_contents($apiv1_path, $apiv1_new);
        echo "WROTE  api/apiv1.php  (call_user_func updated to array_merge)\n";
    } else {
        // Show the matching line
        $apiv1_lines = explode("\n", $apiv1_contents);
        foreach ($apiv1_lines as $lineno => $line) {
            if (preg_match($apiv1_pattern, $line)) {
                echo 'UPDATE api/apiv1.php:' . ($lineno + 1) . '  ' . trim($line) . "\n";
            }
        }
    }
} else {
    // Already updated or pattern not found
    if (strpos($apiv1_contents, 'array_merge($get_params, $post_params)') !== false) {
        echo "DONE   api/apiv1.php  (already uses array_merge)\n";
    } else {
        echo "WARN   api/apiv1.php  — expected pattern not found (check manually)\n";
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// Verify (--verify mode)
// ---------------------------------------------------------------------------

if ($mode === 'verify') {
    echo "=== Verify ===\n\n";

    // Logic file signatures
    echo "--- Logic file signatures ---\n";
    $ok = 0; $fail = 0;
    foreach (array_keys($transform_targets) as $filepath) {
        $contents = file_get_contents($filepath);
        $short    = short_path($filepath, $web_root);
        if (preg_match('/\bfunction\s+\w+_logic\s*\(\s*array\s+\$input\s*\)\s*:\s*LogicResult\b/', $contents)) {
            echo "OK     {$short}\n";
            $ok++;
        } else {
            // Show what the actual signature looks like
            preg_match('/\bfunction\s+\w+_logic\s*\([^)]*\)/', $contents, $actual);
            echo 'FAIL   ' . $short . '  (found: ' . ($actual[0] ?? 'no match') . ")\n";
            $fail++;
        }
    }
    echo "\n  Signatures: {$ok} OK, {$fail} failed.\n\n";

    // apiv1.php dynamic invocation
    echo "--- apiv1.php dynamic invocation ---\n";
    $apiv1_verify = file_get_contents(PathHelper::getIncludePath('api/apiv1.php'));
    if (strpos($apiv1_verify, 'array_merge($get_params, $post_params)') !== false) {
        echo "OK     api/apiv1.php  (uses array_merge)\n";
    } elseif (preg_match('/call_user_func\s*\(\s*\$logic_function\s*,\s*\$get_params\s*,\s*\$post_params\s*\)/', $apiv1_verify)) {
        echo "FAIL   api/apiv1.php  (still passes two separate params — needs update)\n";
    } else {
        echo "WARN   api/apiv1.php  (neither pattern found — check manually)\n";
    }
    echo "\n";

    // Call sites — scan for any remaining $_GET, $_POST patterns in non-skip logic functions
    echo "--- Remaining \$_GET, \$_POST call sites (should be zero for transformed functions) ---\n";
    $remaining = 0;
    foreach ($callsite_dirs as $dir) {
        foreach (collect_php_files($dir) as $filepath) {
            $contents = file_get_contents($filepath);
            $lines    = explode("\n", $contents);
            foreach ($lines as $lineno => $line) {
                if (preg_match('/\b\w+_logic\s*\(\s*\$_GET\s*,\s*\$_POST\s*\)/', $line)) {
                    echo 'REMAIN ' . short_path($filepath, $web_root) . ':' . ($lineno + 1) . '  ' . trim($line) . "\n";
                    $remaining++;
                }
            }
        }
    }
    if ($remaining === 0) {
        echo "  (none — all call sites updated)\n";
    } else {
        echo "\n  {$remaining} remaining \$_GET, \$_POST call site(s).\n";
    }
}

echo "\nDone.\n";
if ($mode === 'scan') {
    echo "Run with --apply to write changes, then --verify to confirm.\n";
}
