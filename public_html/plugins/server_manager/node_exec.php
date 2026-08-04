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
 *
 * Every execution is recorded as a run_command management job, the same row the
 * node detail Console tab creates, so the CLI is not the one path onto a node
 * that leaves no trace. Command and output are redacted before storage.
 * Listing and prefix modes record nothing — only real executions.
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
require_once(__DIR__ . '/data/management_job_class.php');
require_once(__DIR__ . '/includes/JobCommandBuilder.php');
require_once(__DIR__ . '/includes/SmSecretRedactor.php');

/** Most output retained for the job row. Everything still reaches the terminal
 *  live; only what gets stored is bounded, keeping the tail because that is
 *  where a command's conclusion is. */
const NODE_EXEC_OUTPUT_CAP = 262144;

/**
 * Run a command, streaming its output to this terminal as it arrives while
 * retaining a copy for the job record. stdout and stderr keep their own
 * destinations locally and are interleaved in the retained copy, which is what
 * the job-detail page shows for agent-run jobs too.
 *
 * @return array{0: string, 1: int} retained output, exit code
 */
function node_exec_run_tee(string $full_cmd, bool $pass_stdin): array {
	$descriptors = [
		0 => $pass_stdin ? STDIN : ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	$proc = proc_open($full_cmd, $descriptors, $pipes);
	if ($proc === false) {
		fwrite(STDERR, "node_exec: failed to open process\n");
		exit(1);
	}
	if (!$pass_stdin && isset($pipes[0])) {
		fclose($pipes[0]);
	}

	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);

	$retained = '';
	$open = [1 => $pipes[1], 2 => $pipes[2]];
	while ($open) {
		$read = array_values($open);
		$write = $except = null;
		if (stream_select($read, $write, $except, 1) === false) {
			break;
		}
		foreach ($read as $stream) {
			$chunk = fread($stream, 8192);
			if ($chunk === '' || $chunk === false) {
				if (feof($stream)) {
					foreach ($open as $fd => $s) {
						if ($s === $stream) { fclose($s); unset($open[$fd]); }
					}
				}
				continue;
			}
			fwrite($stream === $pipes[2] ? STDERR : STDOUT, $chunk);
			$retained .= $chunk;
			if (strlen($retained) > NODE_EXEC_OUTPUT_CAP) {
				$retained = "[earlier output truncated]\n"
					. substr($retained, -NODE_EXEC_OUTPUT_CAP);
			}
		}
	}

	return [$retained, proc_close($proc)];
}

/**
 * Record a finished CLI execution as a run_command job.
 *
 * The row is written already-terminal — the command ran here, not through the
 * agent, so it never passes through pending/running. created_by is null: a
 * shell on the control plane has no session user, and claiming one would be a
 * worse record than admitting the run came from the CLI.
 *
 * Recording must never change what the CLI does, so any failure here is
 * reported on stderr and swallowed — an unreachable database is not a reason
 * to lose the exit code of a command that already ran.
 */
function node_exec_record($node, string $command, string $output, int $exit_code, bool $on_host): void {
	try {
		$job = new ManagementJob(NULL);
		$job->set('mjb_mgn_node_id', $node->key);
		$job->set('mjb_job_type', 'run_command');
		$job->set('mjb_status', $exit_code === 0 ? 'completed' : 'failed');
		$job->set('mjb_commands', json_encode(['steps' => [[
			'type'  => 'ssh',
			'label' => 'Run command (CLI)',
			'cmd'   => SmSecretRedactor::redact($command),
		]]]));
		$job->set('mjb_parameters', json_encode([
			'command' => SmSecretRedactor::redact($command),
			'on_host' => $on_host,
			'source'  => 'cli',
		]));
		$job->set('mjb_output', SmSecretRedactor::redact($output));
		$job->set('mjb_total_steps', 1);
		$job->set('mjb_current_step', 1);
		$job->set('mjb_started_time', gmdate('Y-m-d H:i:s'));
		$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
		if ($exit_code !== 0) {
			$job->set('mjb_error_message', 'Command exited ' . $exit_code . '.');
		}
		$job->save();
	} catch (Throwable $e) {
		fwrite(STDERR, "node_exec: command ran but could not be recorded: " . $e->getMessage() . "\n");
	}
}

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

    list($output, $exit_code) = node_exec_run_tee($full_cmd, true);
    node_exec_record($node, $command, $output, $exit_code, false);
    exit($exit_code);
}

if ($container) {
    $remote = "docker exec {$container} " . $command;
} else {
    $remote = $command;
}

$full_cmd = $ssh . ' ' . escapeshellarg($remote);

list($output, $exit_code) = node_exec_run_tee($full_cmd, true);
node_exec_record($node, $command, $output, $exit_code, false);
exit($exit_code);
?>
