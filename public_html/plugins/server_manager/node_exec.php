<?php
/**
 * node_exec.php — Run a command on any managed node from the control plane.
 *
 * Designed for use by Claude during production investigations. Handles SSH
 * and Docker transparently so no manual discovery is needed.
 *
 * Usage:
 *   php node_exec.php                             # list all active nodes
 *   php node_exec.php <slug>                      # print exec prefix for the node
 *   php node_exec.php <slug> "<command>"          # run command on the node
 *   echo "SQL..." | php node_exec.php <slug> --stdin "<command>"  # pipe stdin to command
 *
 * The --stdin flag pipes local stdin to the remote command, bypassing shell
 * quoting limits. Useful for SQL queries and multi-line scripts:
 *   echo "SELECT stg_value FROM stg_settings WHERE stg_name = 'theme_template';" \
 *     | php node_exec.php scrolldaddy --stdin "PGPASSWORD=xxx psql -U postgres scrolldaddy"
 *
 * Exit code mirrors the remote command's exit code.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(__DIR__ . '/data/managed_node_class.php');
require_once(__DIR__ . '/includes/JobCommandBuilder.php');

// Parse args — strip --stdin flag wherever it appears, collect remainder
$use_stdin = false;
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--stdin') {
        $use_stdin = true;
    } else {
        $args[] = $arg;
    }
}

$slug    = isset($args[0]) ? trim($args[0]) : null;
$command = isset($args[1]) ? $args[1] : null;

// No args — list all active nodes
if ($slug === null) {
    $nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true]);
    $nodes->load();

    if (count($nodes) === 0) {
        echo "No active nodes found.\n";
        exit(0);
    }

    $fmt = "%-20s %-20s %-16s %s\n";
    printf($fmt, 'SLUG', 'NAME', 'HOST', 'CONTAINER');
    echo str_repeat('-', 72) . "\n";
    foreach ($nodes as $node) {
        printf($fmt,
            $node->get('mgn_slug'),
            $node->get('mgn_name'),
            $node->get('mgn_host'),
            $node->get('mgn_container_name') ?: '(bare metal)'
        );
    }
    exit(0);
}

// Look up node by slug (or fall back to case-insensitive name match)
$nodes = new MultiManagedNode(['slug' => strtolower($slug), 'deleted' => false]);
$nodes->load();

if (count($nodes) === 0) {
    // Try name match
    $all = new MultiManagedNode(['deleted' => false]);
    $all->load();
    $node = null;
    foreach ($all as $n) {
        if (strtolower($n->get('mgn_name')) === strtolower($slug)) {
            $node = $n;
            break;
        }
    }
    if ($node === null) {
        fwrite(STDERR, "node_exec: node not found: {$slug}\n");
        exit(1);
    }
} else {
    $node = $nodes->get(0);
}

if (!$node->get('mgn_enabled')) {
    fwrite(STDERR, "node_exec: node is disabled: {$slug}\n");
    exit(1);
}

$host          = $node->get('mgn_host');
$ssh_user      = $node->get('mgn_ssh_user') ?: 'root';
$ssh_key_path  = $node->get('mgn_ssh_key_path');
$ssh_port      = intval($node->get('mgn_ssh_port') ?: 22) ?: 22;
$container     = $node->get('mgn_container_name');

if (empty($host)) {
    fwrite(STDERR, "node_exec: node has no host configured: {$slug}\n");
    exit(1);
}

if (empty($ssh_key_path)) {
    fwrite(STDERR, "node_exec: node has no SSH key configured: {$slug}\n");
    exit(1);
}

$ssh = JobCommandBuilder::ssh_prefix($host, $ssh_user, $ssh_key_path, $ssh_port);

// No command — print exec prefix and exit
if ($command === null) {
    if ($container) {
        echo $ssh . ' ' . escapeshellarg("docker exec {$container} <cmd>") . "\n";
    } else {
        echo $ssh . " <cmd>\n";
    }
    exit(0);
}

// Build and run the full command
if ($use_stdin) {
    // Pipe local stdin to the remote command — docker exec needs -i for stdin.
    // Wrap in bash -c so env var prefixes (e.g. PGPASSWORD=x psql ...) work correctly,
    // since docker exec does not run a shell by default.
    $remote = $container
        ? "docker exec -i {$container} bash -c " . escapeshellarg($command)
        : "bash -c " . escapeshellarg($command);
    $full_cmd = $ssh . ' ' . escapeshellarg($remote);
    $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    $proc = proc_open($full_cmd, $descriptors, $pipes);
    if ($proc === false) {
        fwrite(STDERR, "node_exec: failed to open process\n");
        exit(1);
    }
    exit(proc_close($proc));
}

if ($container) {
    $remote = "docker exec {$container} " . $command;
} else {
    $remote = $command;
}

$full_cmd = $ssh . ' ' . escapeshellarg($remote);

passthru($full_cmd, $exit_code);
exit($exit_code);
?>
