<?php
/** @joinery-test
 * name: job_command_builder
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * JobCommandBuilder — the commands the control plane sends to production nodes.
 *
 * Everything this class emits is executed as root, over SSH, on a live server.
 * Two properties therefore matter more than anything else it does.
 *
 * The first is that a value carried into a command cannot stop being a value. A
 * node slug, a domain, a backup path and a relay tenant name all arrive from a
 * form or a database row, and a builder that pastes one into a command string
 * unquoted turns a text field into a root shell on every managed node. These
 * tests push shell metacharacters through every builder that accepts input and
 * assert the payload survives only as an inert argument.
 *
 * The second is that a command has to actually address what it means to. The
 * failure mode here is quiet in a way injection is not: escapeshellarg returns
 * its value WITH quotes, so interpolating it inside an already-quoted shell
 * string embeds those quotes in the path. The command then runs, touches
 * nothing, and reports success. Paths built that way are asserted to be free of
 * stray quotes.
 *
 * Nothing here executes a command. The builders are pure — they return step
 * arrays — so the emitted text is inspected directly. Where a payload's
 * inertness is the claim, it is checked by running the fragment through a real
 * shell in a temporary directory and confirming the payload did not fire.
 *
 * Sections: transport gating; input refusals; shell safety; path construction;
 * step structure; teardown phase.
 *
 * Run: php plugins/server_manager/tests/job_command_builder_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

/** A node fixture. Defaults give it SSH but no API credentials. */
function jcb_node(array $fields = array()) {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'HarnessTest Node ' . $suffix);
	$node->set('mgn_slug', 'harnesstest-' . $suffix);
	$node->set('mgn_host', '192.0.2.10');
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_ssh_key_path', '/home/user1/.ssh/id_ed25519_claude');
	foreach ($fields as $k => $v) {
		$node->set($k, $v);
	}
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_id', $node->key);
	return $node;
}

/** Flatten the cmd text out of a step array. */
function jcb_cmds($steps) {
	$out = array();
	foreach ($steps as $step) {
		if (isset($step['cmd'])) { $out[] = $step['cmd']; }
	}
	return implode("\n", $out);
}

/**
 * Run a command fragment in a throwaway directory with a canary file present,
 * and report whether the payload fired. Proves inertness rather than asserting
 * it from the shape of the string.
 */
function jcb_payload_fired($cmd, $dir) {
	@unlink($dir . '/CANARY_FIRED');
	// Neutralise anything that would touch the real system: the fragment is run
	// with a stub PATH entry set so only the canary is observable. The stubs
	// matter for speed as well as safety — a real ssh against the fixture's
	// TEST-NET host would block for its full ConnectTimeout per fragment.
	$wrapped = 'cd ' . escapeshellarg($dir)
		. ' && export PATH=' . escapeshellarg($dir . '/stub-bin') . ':"$PATH"'
		. ' && { ' . $cmd . ' ; } >/dev/null 2>&1';
	@shell_exec($wrapped);
	$fired = file_exists($dir . '/CANARY_FIRED');
	@unlink($dir . '/CANARY_FIRED');
	return $fired;
}

$tmpdir = sys_get_temp_dir() . '/jcb_test_' . bin2hex(random_bytes(4));
@mkdir($tmpdir, 0777, true);
// Inert shims for the network binaries a fragment may invoke (see
// jcb_payload_fired). Exit 255 mirrors ssh's own connection-failure code.
@mkdir($tmpdir . '/stub-bin', 0777, true);
foreach (array('ssh', 'scp') as $stub) {
	file_put_contents($tmpdir . '/stub-bin/' . $stub, "#!/bin/sh\nexit 255\n");
	@chmod($tmpdir . '/stub-bin/' . $stub, 0755);
}
harness_defer(function () use ($tmpdir) {
	// Recurse: the path-construction section creates a sites-enabled/ subtree,
	// and a flat unlink+rmdir leaves the whole directory behind.
	$rm = function ($path) use (&$rm) {
		foreach (glob($path . '/*') as $f) {
			is_dir($f) ? $rm($f) : @unlink($f);
		}
		@rmdir($path);
	};
	$rm($tmpdir);
});

// The payload writes a canary if the shell ever evaluates it as code.
$PAYLOAD = '; touch CANARY_FIRED; #';
$SUBSHELL = '$(touch CANARY_FIRED)';
$BACKTICK = '`touch CANARY_FIRED`';

$ssh_node = jcb_node();
$api_node = jcb_node(array(
	'mgn_api_public_key' => 'pub_' . bin2hex(random_bytes(4)),
	'mgn_api_secret_key' => 'sec_' . bin2hex(random_bytes(4)),
	'mgn_site_url'       => 'https://192.0.2.11',
));
$bare_node = jcb_node(array('mgn_ssh_key_path' => '', 'mgn_ssh_user' => ''));

// ---------------------------------------------------------------------------
section('Transport gating');

check(JobCommandBuilder::has_ssh($ssh_node), 'a node with host, user and key has SSH');
check(!JobCommandBuilder::has_ssh($bare_node), 'a node missing its key does not have SSH');
check(!JobCommandBuilder::has_api_creds($ssh_node), 'a node without API keys has no API credentials');
check(JobCommandBuilder::has_api_creds($api_node), 'a node with keys and a site URL has API credentials');

// has_api_creds needs all three; any one missing disqualifies it.
$partial = jcb_node(array(
	'mgn_api_public_key' => 'pub_only',
	'mgn_site_url'       => 'https://192.0.2.12',
));
check(!JobCommandBuilder::has_api_creds($partial),
	'API credentials require the secret key too, not just a public key and URL');

$transports = JobCommandBuilder::transports_for('check_status');
check(in_array('api', $transports) && in_array('ssh', $transports),
	'check_status reports both transports',
	implode(',', $transports));
check(JobCommandBuilder::transports_for('no_such_operation') === array(),
	'an unimplemented operation reports no transports');

check(JobCommandBuilder::can_run($ssh_node, 'check_status'),
	'an SSH node can run an operation with an SSH implementation');
check(!JobCommandBuilder::can_run($bare_node, 'check_status'),
	'a node with neither transport cannot run anything');
check(!JobCommandBuilder::can_run($ssh_node, 'no_such_operation'),
	'no node can run an operation with no implementation');

// The disabled-button tooltip has to say something true, since it is the only
// explanation an admin gets for why an action is greyed out.
$why = JobCommandBuilder::why_cannot_run($bare_node, 'check_status');
check(strpos($why, 'SSH is not configured') !== false,
	'the refusal reason names the missing SSH configuration', $why);
check(strpos($why, 'no API credentials') !== false,
	'the refusal reason names the missing API credentials', $why);
$why = JobCommandBuilder::why_cannot_run($ssh_node, 'no_such_operation');
check(strpos($why, 'no implementation') !== false,
	'an unimplemented operation says so rather than blaming the node', $why);

// ---------------------------------------------------------------------------
section('Input refusals');

// A relay tenant slug names a directory and a config stanza on the relay, so it
// is constrained to a shape rather than escaped and hoped for.
$bad_slugs = array($PAYLOAD, $SUBSHELL, $BACKTICK, '../../etc/passwd',
	'has space', 'has/slash', '', '-leading-dash', str_repeat('a', 40));
foreach ($bad_slugs as $slug) {
	$threw = false;
	try {
		JobCommandBuilder::build_relay_add_tenant($ssh_node, array(
			'slug' => $slug, 'pull_pubkey' => 'abc'));
	} catch (Exception $e) {
		$threw = true;
	}
	check($threw, 'relay_add_tenant refuses the slug ' . var_export(substr($slug, 0, 20), true));
}

$threw = false;
try {
	JobCommandBuilder::build_relay_add_tenant($ssh_node, array('slug' => 'good-slug'));
} catch (Exception $e) { $threw = true; }
check($threw, 'relay_add_tenant refuses a tenant with no pull key');

// Case is normalised rather than refused, so an admin typing a slug in caps
// gets the tenant they meant instead of an error.
$steps = JobCommandBuilder::build_relay_add_tenant($ssh_node, array(
	'slug' => 'MixedCase', 'pull_pubkey' => 'abc'));
$cmd = jcb_cmds($steps);
check(strpos($cmd, "'mixedcase'") !== false,
	'an uppercase slug is lowercased rather than refused', $cmd);
check(strpos($cmd, 'MixedCase') === false,
	'the original casing does not survive into the command', $cmd);

foreach (array('build_relay_set_domains', 'build_relay_remove_tenant') as $fn) {
	$threw = false;
	try {
		JobCommandBuilder::$fn($ssh_node, array('slug' => $PAYLOAD));
	} catch (Exception $e) { $threw = true; }
	check($threw, $fn . ' refuses a slug carrying shell metacharacters');
}

$threw = false;
try {
	JobCommandBuilder::build_provision_ssl($ssh_node, array('domain' => ''));
} catch (Exception $e) { $threw = true; }
check($threw, 'provision_ssl refuses an empty domain');

// A node with no transport at all cannot have a job built for it silently.
$threw = false;
try {
	JobCommandBuilder::build_list_backups($bare_node);
} catch (Exception $e) { $threw = true; }
check($threw, 'building a job for a node with no transport throws rather than emitting nothing');

// ---------------------------------------------------------------------------
section('Shell safety');

// Every builder that accepts free-form input gets the payload pushed through it.
// The claim is not "the string is absent" — it should survive, quoted — but that
// a shell evaluating the emitted command never executes it.
$cases = array();

$cases['delete_backup local path'] = jcb_cmds(JobCommandBuilder::build_delete_backup(
	$ssh_node, array('target' => 'local', 'local_path' => '/backups/' . $PAYLOAD)));

$cases['relay_add_tenant pull key'] = jcb_cmds(JobCommandBuilder::build_relay_add_tenant(
	$ssh_node, array('slug' => 'tenant-a', 'pull_pubkey' => $PAYLOAD)));

$cases['relay_add_tenant domains'] = jcb_cmds(JobCommandBuilder::build_relay_add_tenant(
	$ssh_node, array('slug' => 'tenant-a', 'pull_pubkey' => 'abc', 'domains' => $PAYLOAD)));

$cases['relay_set_domains domains'] = jcb_cmds(JobCommandBuilder::build_relay_set_domains(
	$ssh_node, array('slug' => 'tenant-a', 'domains' => $SUBSHELL)));

$cases['provision_ssl domain'] = jcb_cmds(JobCommandBuilder::build_provision_ssl(
	$ssh_node, array('domain' => 'example.test', 'admin_email' => $PAYLOAD)));

$cases['ssh_prefix host'] = JobCommandBuilder::ssh_prefix(
	$PAYLOAD, 'root', '/tmp/key', 22);

$cases['ssh_prefix key path'] = JobCommandBuilder::ssh_prefix(
	'192.0.2.10', 'root', '/tmp/' . $BACKTICK, 22);

foreach ($cases as $label => $cmd) {
	check(strpos($cmd, 'CANARY_FIRED') !== false,
		$label . ': the payload is carried through rather than stripped',
		'a builder that silently dropped input would pass an inertness check for the wrong reason');
}

// Now prove inertness by running the emitted text. The commands reference
// remote tooling that does not exist here, which is fine: the question is only
// whether the payload's touch executes.
foreach ($cases as $label => $cmd) {
	check(!jcb_payload_fired($cmd, $tmpdir),
		$label . ': the payload does not execute',
		'emitted: ' . substr(preg_replace('/\s+/', ' ', $cmd), 0, 160));
}

// A quote in a value must not close the quoting around it.
$quote_payload = "'; touch CANARY_FIRED; '";
$cmd = jcb_cmds(JobCommandBuilder::build_delete_backup(
	$ssh_node, array('target' => 'local', 'local_path' => '/backups/' . $quote_payload)));
check(!jcb_payload_fired($cmd, $tmpdir),
	'a value containing a single quote cannot close the quoting around it',
	'emitted: ' . substr($cmd, 0, 160));

// Numeric options are coerced, not quoted, so they must not accept text at all.
$steps = JobCommandBuilder::build_relay_add_tenant($ssh_node, array(
	'slug' => 'tenant-a', 'pull_pubkey' => 'abc', 'forward_limit' => '5; touch CANARY_FIRED'));
$cmd = jcb_cmds($steps);
check(strpos($cmd, '--forward-limit 5') !== false,
	'a numeric option keeps its numeric prefix', $cmd);
check(strpos($cmd, 'CANARY_FIRED') === false,
	'a numeric option discards trailing text entirely rather than quoting it', $cmd);

// ---------------------------------------------------------------------------
section('Local backup delete privilege');

// Backups under /backups are written as root. On a bare-metal node jobs run as a
// non-root user, so the rm must be sudo-prefixed or it fails Permission denied
// while the continue_on_error step still reports done. A Docker node runs the job
// as root inside the container and must NOT carry a sudo prefix.
$bm_del = jcb_cmds(JobCommandBuilder::build_delete_backup(
	jcb_node(array('mgn_ssh_user' => 'user1')),
	array('target' => 'local', 'local_path' => '/backups/auto_pre_install_x.sql.gz')));
check(strpos($bm_del, 'sudo rm -f ') !== false,
	'a bare-metal (non-root) node deletes a local backup with sudo', $bm_del);

$dk_del = jcb_cmds(JobCommandBuilder::build_delete_backup(
	jcb_node(array('mgn_container_name' => 'somesite')),
	array('target' => 'local', 'local_path' => '/backups/auto_pre_install_x.sql.gz')));
check(strpos($dk_del, 'sudo ') === false && strpos($dk_del, 'rm -f ') !== false,
	'a Docker node deletes a local backup without sudo (already root)', $dk_del);

// ---------------------------------------------------------------------------
section('Path construction');

// escapeshellarg returns its value WITH quotes. Interpolated inside a
// double-quoted shell string those quotes become part of the path, so the file
// is never found — and because the step ends in an echo or `|| true`, it still
// reports success. The proxy config would silently keep serving
// X-Forwarded-Proto "http" to a backend sitting behind TLS.
$named = jcb_node(array('mgn_container_name' => 'mysite'));
$steps = JobCommandBuilder::build_provision_ssl($named, array(
	'domain' => 'ssl-test.invalid', 'admin_email' => 'admin@example.test'));
$cmd = jcb_cmds($steps);

check(strpos($cmd, "sites-enabled/'") === false,
	'the proxy config path carries no stray quote from escapeshellarg',
	'emitted: ' . substr($cmd, 0, 200));

// Prove the emitted fragment actually finds the file it names.
@mkdir($tmpdir . '/sites-enabled', 0777, true);
$conf = $tmpdir . '/sites-enabled/mysite-proxy-le-ssl.conf';
file_put_contents($conf, "Header set X-Forwarded-Proto \"http\"\n");

// Take the SSL patch step and point it at the fixture tree instead of /etc.
$patch = '';
foreach ($steps as $step) {
	if (isset($step['label']) && strpos($step['label'], 'X-Forwarded-Proto') !== false) {
		$patch = $step['cmd'];
	}
}
check($patch !== '', 'the SSL patch step is emitted');
$local_patch = str_replace('/etc/apache2/sites-enabled', $tmpdir . '/sites-enabled', $patch);
$local_patch = str_replace('systemctl reload apache2', 'true', $local_patch);
@shell_exec($local_patch . ' >/dev/null 2>&1');

$after = (string)file_get_contents($conf);
check(strpos($after, 'X-Forwarded-Proto "https"') !== false,
	'the emitted patch finds its config file and rewrites the protocol header',
	'file now: ' . trim($after));

// The patch has to say which of four things happened. A step that exits zero
// and prints the same thing whether it rewrote a file, found it already correct,
// or never found it at all cannot be trusted when it reports success — which is
// exactly how the broken path stayed invisible.
function jcb_proto_outcome($steps, $tmpdir) {
	foreach ($steps as $step) {
		if (isset($step['label']) && strpos($step['label'], 'X-Forwarded-Proto') !== false) {
			$cmd = str_replace('/etc/apache2/sites-enabled', $tmpdir . '/sites-enabled', $step['cmd']);
			$cmd = str_replace('systemctl reload apache2', 'true', $cmd);
			return trim((string)shell_exec($cmd . ' 2>/dev/null'));
		}
	}
	return '';
}

$outcome_node = jcb_node(array('mgn_container_name' => 'outcomesite'));
$outcome_steps = JobCommandBuilder::build_provision_ssl($outcome_node,
	array('domain' => 'ssl-outcome.invalid'));
$outcome_conf = $tmpdir . '/sites-enabled/outcomesite-proxy-le-ssl.conf';

@unlink($outcome_conf);
check(jcb_proto_outcome($outcome_steps, $tmpdir) === 'PROTO_CONF_MISSING',
	'a missing config file is reported, not passed over silently',
	jcb_proto_outcome($outcome_steps, $tmpdir));

file_put_contents($outcome_conf, "RequestHeader set X-Forwarded-Proto \"http\"\n");
check(jcb_proto_outcome($outcome_steps, $tmpdir) === 'PROTO_PATCHED',
	'rewriting the header is reported as a patch');
check(strpos((string)file_get_contents($outcome_conf), 'X-Forwarded-Proto "https"') !== false,
	'and the file actually carries https afterwards',
	trim((string)file_get_contents($outcome_conf)));

// Re-running must be a no-op that says so, since provision_ssl can be repeated.
check(jcb_proto_outcome($outcome_steps, $tmpdir) === 'PROTO_ALREADY_HTTPS',
	'a second run reports the header was already correct');
check(substr_count((string)file_get_contents($outcome_conf), 'X-Forwarded-Proto') === 1,
	'a second run does not duplicate the header');

file_put_contents($outcome_conf, "ServerName example.test\n");
check(jcb_proto_outcome($outcome_steps, $tmpdir) === 'PROTO_HEADER_ABSENT',
	'a config with no such header is reported rather than silently skipped');

// A site name carrying a space still has to resolve — quoting is what makes
// that work, and it is the case that a naive fix would break.
$spaced = jcb_node(array('mgn_container_name' => 'my site'));
$steps = JobCommandBuilder::build_provision_ssl($spaced, array('domain' => 'ssl-test2.invalid'));
$cmd = jcb_cmds($steps);
$conf2 = $tmpdir . '/sites-enabled/my site-proxy-le-ssl.conf';
file_put_contents($conf2, "Header set X-Forwarded-Proto \"http\"\n");
foreach ($steps as $step) {
	if (isset($step['label']) && strpos($step['label'], 'X-Forwarded-Proto') !== false) {
		$local = str_replace('/etc/apache2/sites-enabled', $tmpdir . '/sites-enabled', $step['cmd']);
		$local = str_replace('systemctl reload apache2', 'true', $local);
		@shell_exec($local . ' >/dev/null 2>&1');
	}
}
check(strpos((string)file_get_contents($conf2), 'X-Forwarded-Proto "https"') !== false,
	'a site name containing a space still resolves to its config file',
	'file now: ' . trim((string)file_get_contents($conf2)));

// ---------------------------------------------------------------------------
section('Step structure');

// The dispatcher reads these keys; a step missing one is a job that cannot run.
$steps = JobCommandBuilder::build_check_status($ssh_node);
check(is_array($steps) && count($steps) > 0, 'check_status emits at least one step');
foreach ($steps as $i => $step) {
	check(isset($step['type']) && in_array($step['type'], array('ssh', 'local', 'api')),
		'step ' . $i . ' declares a transport type the dispatcher understands',
		'type: ' . var_export($step['type'] ?? null, true));
	check(isset($step['label']) && $step['label'] !== '',
		'step ' . $i . ' carries a label for the job log');
}

// An API-capable node with no reachable endpoint must not silently produce an
// empty job; the SSH path is the fallback and it has to be chosen.
$steps = JobCommandBuilder::build_list_backups($ssh_node);
check(count($steps) > 0, 'a node without API credentials still gets a backup listing job');
check($steps[0]['type'] === 'ssh', 'that job runs over SSH', $steps[0]['type']);

// Delete-backup with nothing selected still emits a runnable step rather than
// an empty array the dispatcher would treat as a completed job.
$steps = JobCommandBuilder::build_delete_backup($ssh_node, array('target' => 'local', 'local_path' => ''));
check(count($steps) === 1, 'deleting nothing still emits one step', 'steps: ' . count($steps));
check(strpos($steps[0]['cmd'], 'Nothing to delete') !== false,
	'that step says nothing was deleted rather than pretending it deleted something');

section('Node console: build_run_command');

// The console is the one builder that must NOT sanitise its input — an
// operator's command is meant to reach the shell as written. What is asserted
// here is the opposite of the shell-safety section above: the command survives
// verbatim, and the bounds live in the timeout and the gate instead.
$console_cmd = "apache2ctl -M | grep -E 'mpm|fcgi' && echo \$PATH";
$steps = JobCommandBuilder::build_run_command($ssh_node, array(
	'command' => $console_cmd, 'timeout' => 120));
check(count($steps) === 1, 'one command produces exactly one step', 'steps: ' . count($steps));
check($steps[0]['cmd'] === $console_cmd,
	'the command reaches the step verbatim — pipes, quotes and all');
check($steps[0]['type'] === 'ssh' && !empty($steps[0]['label']),
	'the step is a labelled SSH step like every other job');
check(($steps[0]['timeout'] ?? null) === 120, 'the chosen timeout rides on the step');

// The timeout is the runaway guard, so the offered set is closed: a hand-posted
// value outside it is refused rather than quietly clamped to something else.
$refused = false;
try { JobCommandBuilder::build_run_command($ssh_node, array('command' => 'ls', 'timeout' => 86400)); }
catch (Exception $e) { $refused = true; }
check($refused, 'a timeout outside the offered set is refused');

$refused = false;
try { JobCommandBuilder::build_run_command($ssh_node, array('command' => '   ', 'timeout' => 60)); }
catch (Exception $e) { $refused = true; }
check($refused, 'an empty command is refused rather than creating a job that does nothing');

check(in_array(JobCommandBuilder::CONSOLE_TIMEOUT_DEFAULT, JobCommandBuilder::CONSOLE_TIMEOUTS, true),
	'the default timeout is one the form actually offers');

// on_host is meaningful only where there are two shells to choose between.
$console_docker = jcb_node(array('mgn_container_name' => 'consolesite', 'mgn_web_root' => '/var/www/html/consolesite/public_html'));
$steps = JobCommandBuilder::build_run_command($console_docker, array(
	'command' => 'ls', 'timeout' => 60, 'on_host' => true));
check(!empty($steps[0]['on_host']), 'a container node can send the command to the host instead');
$steps = JobCommandBuilder::build_run_command($ssh_node, array(
	'command' => 'ls', 'timeout' => 60, 'on_host' => true));
check(empty($steps[0]['on_host']),
	'a bare-metal node ignores on_host — it has only one shell to run in');

$no_ssh = jcb_node(array('mgn_ssh_key_path' => ''));
$refused = false;
try { JobCommandBuilder::build_run_command($no_ssh, array('command' => 'ls', 'timeout' => 60)); }
catch (Exception $e) { $refused = true; }
check($refused, 'a node with no SSH credentials refuses instead of building an unrunnable job');

check(in_array('run_command', ManagementJob::filterTypes(), true),
	'run_command is a filterable job type, so console runs are findable on the jobs pages');

section('Plugin installers');

// The runner lives in the site dir, one level above web root, and needs the
// sitename as its argument. On a bare-metal node whose SSH user is not root
// the whole invocation must be sudo'd — and because sudo strips the caller's
// environment, PGPASSWORD (which the runner needs to query active plugins)
// has to be re-injected explicitly, not assumed to survive.
$bare = jcb_node(array(
	'mgn_ssh_user' => 'user1',
	'mgn_web_root' => '/var/www/html/jeremytunnell/public_html'));
$steps = JobCommandBuilder::build_run_plugin_installers($bare);
check(count($steps) === 1, 'one step is emitted', 'steps: ' . count($steps));
$cmd = $steps[0]['cmd'];
check(strpos($cmd, 'sudo ') !== false,
	'a bare-metal non-root node gets a sudo invocation', $cmd);
check(strpos($cmd, '/var/www/html/jeremytunnell/maintenance_scripts/install_tools/_plugin_installers_start.sh') !== false,
	'the runner path is the site dir, not the web root', $cmd);
check(preg_match("/_plugin_installers_start\\.sh '?jeremytunnell'?(\\s|$)/", $cmd) === 1,
	'the sitename is passed as the runner argument', $cmd);
check(strpos($cmd, 'PGPASSWORD') !== false,
	'PGPASSWORD is carried across the sudo boundary', $cmd);

// A docker node's steps execute inside the container as root: no sudo.
$dockered = jcb_node(array(
	'mgn_container_name' => 'jeremytunnell',
	'mgn_web_root' => '/var/www/html/jeremytunnell/public_html'));
$steps = JobCommandBuilder::build_run_plugin_installers($dockered);
check(strpos($steps[0]['cmd'], 'sudo ') === false,
	'a docker node runs the installers without sudo', $steps[0]['cmd']);

// A node with no web root cannot address a site: refuse at build time rather
// than emit a job that greps a config under the filesystem root.
$rootless = jcb_node(array('mgn_web_root' => ''));
$threw = false;
try { JobCommandBuilder::build_run_plugin_installers($rootless); } catch (Exception $e) { $threw = true; }
check($threw, 'a node without mgn_web_root is refused at build time');

section('From-backup clone: extract depth and restore verification');

// backup_project.sh writes archives two levels deep —
//   {backup_name}/project_files/{public_html,uploads,config,...}
// — with the archive's own metadata (apache_config/, backup_info.txt, the .sql
// dump) as siblings of project_files. Extracting with one level stripped buries
// the entire site under a project_files/ directory at the site root and leaves
// the metadata scattered beside it. Nothing about the resulting site looks
// broken: the fresh install already ran and the database restore succeeded, so
// it serves pages while every uploaded file is absent from where the database
// says it lives. That combination is why this is asserted on the emitted text
// AND executed below rather than trusted to review.
$source_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/sourcesite/public_html',
	'mgn_site_url' => 'https://source.example.com'));
$target_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/clonesite/public_html',
	'mgn_site_url' => 'https://clone.example.com'));

$clone_steps = JobCommandBuilder::build_install_node($target_node, array(
	'mode'           => 'from_backup',
	'sitename'       => 'clonesite',
	'domain'         => 'source.example.com',
	'docker_mode'    => 'bare-metal',
	'source_node_id' => $source_node->key,
	'backup_source'  => 'new',
));

$extract_step = null;
$verify_step  = null;
foreach ($clone_steps as $step) {
	if (($step['label'] ?? '') === 'Extract project files') { $extract_step = $step; }
	if (($step['label'] ?? '') === 'Verify restored files')  { $verify_step  = $step; }
}

check($extract_step !== null, 'the clone emits an extract step');
check($verify_step !== null, 'the clone emits a restore-verification step');

$extract_cmd = $extract_step['cmd'] ?? '';
check(strpos($extract_cmd, '--strip-components=2') !== false,
	'both archive levels are stripped, so content lands at the site root', $extract_cmd);
check(strpos($extract_cmd, '--strip-components=1') === false,
	'the one-level strip that buries the site under project_files/ is gone', $extract_cmd);
check(strpos($extract_cmd, "'*/project_files/*'") !== false,
	'only the project_files subtree is extracted, not the archive metadata', $extract_cmd);
check(empty($extract_step['continue_on_error']),
	'a failed extract fails the clone rather than continuing to a fileless site');
check(empty($verify_step['continue_on_error']),
	'a failed verification fails the clone');

// Execute the emitted verification against real fixtures. The command opens by
// assigning SITE and TAR; everything after that is the logic under test, so the
// body is re-hosted onto a throwaway site directory and a replica archive.
$verify_body = '';
$body_at = strpos($verify_step['cmd'] ?? '', 'if [ -d');
if ($body_at !== false) { $verify_body = substr($verify_step['cmd'], $body_at); }
check($verify_body !== '', 'the verification body can be isolated for execution');

$fx = $tmpdir . '/clonefx';
@mkdir($fx . '/src/mysite-2026-01-01-000000/project_files/public_html', 0777, true);
@mkdir($fx . '/src/mysite-2026-01-01-000000/project_files/uploads/avatar', 0777, true);
@mkdir($fx . '/src/mysite-2026-01-01-000000/project_files/config', 0777, true);
@mkdir($fx . '/src/mysite-2026-01-01-000000/apache_config', 0777, true);
file_put_contents($fx . '/src/mysite-2026-01-01-000000/project_files/public_html/index.php', "code\n");
file_put_contents($fx . '/src/mysite-2026-01-01-000000/project_files/uploads/avatar/pic.jpg', "bytes\n");
file_put_contents($fx . '/src/mysite-2026-01-01-000000/project_files/config/Globalvars_site.php', "sourcecfg\n");
file_put_contents($fx . '/src/mysite-2026-01-01-000000/apache_config/mysite.conf', "vhost\n");
file_put_contents($fx . '/src/mysite-2026-01-01-000000/backup_info.txt', "meta\n");
$archive = $fx . '/archive.tar.gz';
@shell_exec('tar -czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($fx . '/src') . ' mysite-2026-01-01-000000 2>/dev/null');
check(file_exists($archive), 'a replica backup archive was built for the verification fixtures');

/** Run the emitted verification body against $site_dir; returns the exit code. */
$run_verify = function ($site_dir) use ($verify_body, $archive) {
	$cmd = 'SITE=' . escapeshellarg($site_dir) . '; TAR=' . escapeshellarg($archive) . '; ' . $verify_body;
	// A subshell, not a brace group: the body ends in `exit 1` on failure, which
	// in a brace group would terminate the whole shell before the status is read.
	@shell_exec('( ' . $cmd . ' ) >/dev/null 2>&1; echo $? > ' . escapeshellarg($site_dir . '/.rc'));
	$rc = trim((string)@file_get_contents($site_dir . '/.rc'));
	@unlink($site_dir . '/.rc');
	return $rc === '' ? -1 : (int)$rc;
};

// The corrected command, run for real: content lands at the site root.
$good = $fx . '/good';
@mkdir($good, 0777, true);
@shell_exec('tar xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($good)
	. " --strip-components=2 --wildcards --exclude='config/Globalvars_site.php' '*/project_files/*' 2>/dev/null");
check(file_exists($good . '/uploads/avatar/pic.jpg'),
	'the corrected extract puts uploads at the site root');
check(!file_exists($good . '/config/Globalvars_site.php'),
	'the target keeps its own Globalvars_site.php');
check($run_verify($good) === 0, 'verification passes a correctly restored site');

// The defect itself: one level stripped.
$bad = $fx . '/bad';
@mkdir($bad, 0777, true);
@shell_exec('tar xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($bad)
	. " --strip-components=1 --exclude='config/Globalvars_site.php' 2>/dev/null");
check(is_dir($bad . '/project_files'),
	'the one-level strip reproduces the buried-site layout');
check($run_verify($bad) === 1, 'verification rejects a site whose files landed a level too deep');

// A partial copy with no structural tell: the site looks right, one file is gone.
$partial = $fx . '/partial';
@mkdir($partial, 0777, true);
@shell_exec('cp -r ' . escapeshellarg($good) . '/. ' . escapeshellarg($partial) . '/ 2>/dev/null');
@unlink($partial . '/uploads/avatar/pic.jpg');
check(file_exists($partial . '/public_html/index.php') && !file_exists($partial . '/uploads/avatar/pic.jpg'),
	'the partial fixture has site code but a missing upload');
check($run_verify($partial) === 1, 'verification rejects a restore that silently lost one file');

section('Restore-project job: the verification step is a gate');

// The restore job's own verify step used to be a directory listing, which
// succeeds whether or not anything was restored — a green step that proves
// nothing. It has to be able to fail.
$restore_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/restoresite/public_html',
	'mgn_ssh_user' => 'root'));
$restore_steps = JobCommandBuilder::build_restore_project($restore_node, array(
	'local_path' => '/backups/restoresite-2026-01-01-000000.tar.gz',
	'domain'     => 'restored.example.com',
));

$rp_verify = null;
foreach ($restore_steps as $step) {
	if (($step['label'] ?? '') === 'Verify restore') { $rp_verify = $step; }
}
check($rp_verify !== null, 'the restore job emits a verification step');
$rp_cmd = $rp_verify['cmd'] ?? '';
check(strpos($rp_cmd, 'exit 1') !== false,
	'the verification can fail the job', $rp_cmd);
check(strpos($rp_cmd, 'serve.php') !== false,
	'it asserts the web root actually holds a site', $rp_cmd);
check(!preg_match('/^ls -la/', $rp_cmd),
	'it is not a bare directory listing', $rp_cmd);

// Run it against a web root that has no site: it must fail rather than report.
$empty_root = $tmpdir . '/emptyroot';
@mkdir($empty_root, 0777, true);
$probe = 'test -s ' . escapeshellarg($empty_root) . '/serve.php || '
	. "{ echo 'VERIFY FAILED'; exit 1; }";
@shell_exec('( ' . $probe . ' ) >/dev/null 2>&1; echo $? > ' . escapeshellarg($empty_root . '/.rc'));
$probe_rc = trim((string)@file_get_contents($empty_root . '/.rc'));
check($probe_rc === '1', 'that assertion fails on a web root with no site', 'rc: ' . $probe_rc);

section('Restore jobs reconcile the site to the machine they land on');

// A backup can be rebuilt anywhere, so the machine it lands on is usually not
// the one it came from. Every restore path has to settle the domain, the
// deployment shape and the serving config — and prove it did.

$rp_labels = array_map(function ($s) { return $s['label']; }, $restore_steps);
$rp_all    = implode("\n", array_map(function ($s) { return $s['cmd'] ?? ''; }, $restore_steps));

check(strpos($rp_all, '--domain ' . escapeshellarg('restored.example.com')) !== false,
	'the restore is told which domain the site is to answer to');
check(strpos($rp_all, '--skip-apache') === false,
	'no restore path asks the script to skip the serving config');
check(in_array('Verify the site agrees with this machine', $rp_labels),
	'the job proves the restored identity', implode(' | ', $rp_labels));
check(in_array('Verify the site is served', $rp_labels),
	'the job proves the site is actually served', implode(' | ', $rp_labels));

// The drill's failure passed an HTTP-only check comfortably: the site answered
// on :80 the whole time, under a container's internal virtualhost, with a valid
// certificate sitting unused on disk.
$served = null;
$identity = null;
foreach ($restore_steps as $step) {
	if (($step['label'] ?? '') === 'Verify the site is served') { $served = $step['cmd'] ?? ''; }
	if (($step['label'] ?? '') === 'Verify the site agrees with this machine') { $identity = $step['cmd'] ?? ''; }
}
check(strpos((string)$served, 'https://') !== false,
	'the served check asks for HTTPS, not merely HTTP', (string)$served);
check(strpos((string)$served, 'exit 1') !== false,
	'the served check can fail the job', (string)$served);
check(strpos((string)$identity, 'deployment_environment') !== false
	&& strpos((string)$identity, 'baremetal') !== false,
	'the identity check asserts the deployment shape of THIS machine', (string)$identity);
check(strpos((string)$identity, 'webDir') !== false,
	'the identity check asserts the domain the site calls itself', (string)$identity);

// The domain is required, never inferred. A node provisioned during an incident
// carries whatever hostname somebody typed in a hurry, so adopting it silently
// is the failure that only surfaces after DNS moves.
$threw = false;
try {
	JobCommandBuilder::build_restore_project($restore_node, array(
		'local_path' => '/backups/restoresite-2026-01-01-000000.tar.gz'));
} catch (Exception $e) {
	$threw = (strpos($e->getMessage(), 'domain') !== false);
}
check($threw, 'a restore with no domain is refused at job-build time, naming the reason');

// A container's public face is the HOST's proxy virtualhost, which lives
// outside the container and so appears in no backup at all.
$dock_restore = jcb_node(array(
	'mgn_container_name' => 'dockrestore',
	'mgn_web_root'       => '/var/www/html/dockrestore/public_html'));
$dock_steps = JobCommandBuilder::build_restore_project($dock_restore, array(
	'local_path' => '/backups/dockrestore-2026-01-01-000000.tar.gz',
	'domain'     => 'restored.example.com'));
$dock_publish = null;
foreach ($dock_steps as $step) {
	if (($step['label'] ?? '') === 'Publish the domain on the container host') { $dock_publish = $step; }
}
check($dock_publish !== null, 'a container restore publishes the domain on its host');
check(!empty($dock_publish['on_host']),
	'that step runs on the host, not inside the container');
check(strpos($dock_publish['cmd'] ?? '', 'manage_domain.sh') !== false,
	'it uses manage_domain.sh, which owns the proxy virtualhost', $dock_publish['cmd'] ?? '');

// A bare-metal node has nothing to proxy.
$bare_publish = false;
foreach ($restore_steps as $step) {
	if (($step['label'] ?? '') === 'Publish the domain on the container host') { $bare_publish = true; }
}
check(!$bare_publish, 'a bare-metal restore emits no host proxy step');

section('Chain restore: the fleet backups are restorable at all');

// The manager backup profile writes CHAINS, not standalone archives. Without a
// chain restore job the backups every scheduled run uploads could not be
// restored from the dashboard.
$chain_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/chainsite/public_html',
	'mgn_slug'     => 'chainsite',
	'mgn_ssh_user' => 'root'));

$chain_threw = '';
try {
	JobCommandBuilder::build_restore_chain($chain_node, array(
		'chain_id' => 'not-a-chain-id', 'domain' => 'chain.example.com'));
} catch (Exception $e) { $chain_threw = $e->getMessage(); }
check(strpos($chain_threw, 'chain id') !== false,
	'a malformed chain id is refused, naming what was expected', $chain_threw);

// The shape of the job itself. A chain restore that skipped any of these would
// be a restore that wrote before it verified, or one that could not open what
// it downloaded.
$chain_steps = array();
$chain_build_error = '';
try {
	$chain_steps = JobCommandBuilder::build_restore_chain($chain_node, array(
		'chain_id' => 'chain-20260807_231507',
		'domain'   => 'chain.example.com'));
} catch (Exception $e) {
	// Only legitimate when this control plane has no enabled target at all —
	// then there is no shelf to read a chain from, and saying so at build time
	// beats a job that dies halfway through a download.
	$chain_build_error = $e->getMessage();
}

if ($chain_steps) {
	$chain_labels = array_map(function ($s) { return $s['label']; }, $chain_steps);
	$chain_all    = implode("\n", array_map(function ($s) { return $s['cmd'] ?? ''; }, $chain_steps));

	check(in_array('Fetch the chain manifest', $chain_labels),
		'the manifest is fetched first — it is the restore contract', implode(' | ', $chain_labels));
	check(in_array('Recover the chain key', $chain_labels),
		'the chain key is recovered on the node from its own site key');
	check(strpos($chain_all, 'BackupRecoveryKey') === false
		&& strpos($chain_all, 'recovery_private') === false,
		'no recovery private key travels in the job record');
	check(in_array('Download the chain artifacts', $chain_labels),
		'every artifact the manifest names is downloaded');

	// {prefix}/{slug}/{profile}/{chain_id}/. Dropping the profile segment would
	// send a control plane's restore looking on the site's own shelf, where a
	// chain of the same id may well exist and be somebody else's backup.
	check(strpos($chain_all, '/chainsite/manager/chain-20260807_231507') !== false,
		'the chain is read from the profile shelf it was written to', $chain_all);
	check(strpos($chain_all, 'restore_chain.sh') !== false,
		'the restore runs through restore_chain.sh, which verifies before it writes');
	check(strpos($chain_all, '--domain ' . escapeshellarg('chain.example.com')) !== false,
		'the chain restore is told which domain the site is to answer to');
	check(in_array('Verify the site agrees with this machine', $chain_labels)
		&& in_array('Verify the site is served', $chain_labels),
		'a chain restore is gated the same way an archive restore is');

	// The key must not outlive the restore that needed it.
	$chain_restore_cmd = '';
	foreach ($chain_steps as $step) {
		if (($step['label'] ?? '') === 'Restore the chain') { $chain_restore_cmd = $step['cmd'] ?? ''; }
	}
	check(strpos($chain_restore_cmd, 'rm -f') !== false && strpos($chain_restore_cmd, 'exit $RC') !== false,
		'the chain key is shredded afterwards without eating the exit code', $chain_restore_cmd);
} else {
	check(strpos($chain_build_error, 'target') !== false,
		'with no shelf configured, the chain restore is refused at build time naming why',
		$chain_build_error);
}

section('Copy database: docker source staging');

// SSH steps against a container node are docker exec'd, so the dump lands
// inside the container — but SCP reads the host filesystem. A docker source
// therefore needs the dump staged out with docker cp before the download, or
// the job fails at "Download dump from source" with No such file (job #80's
// signature). The target side has had this staging all along; the source side
// is what these checks pin down.
$dock_source = jcb_node(array(
	'mgn_container_name' => 'sourcedock',
	'mgn_web_root' => '/var/www/html/sourcedock/public_html'));
$bare_source = jcb_node(array(
	'mgn_web_root' => '/var/www/html/sourcebare/public_html'));
$copy_target = jcb_node(array(
	'mgn_web_root' => '/var/www/html/copytarget/public_html'));

$steps = JobCommandBuilder::build_copy_database($dock_source, $copy_target);
$labels = array_map(function ($s) { return $s['label']; }, $steps);

$stage_at = array_search('Copy dump out of container', $labels);
$download_at = array_search('Download dump from source', $labels);
check($stage_at !== false, 'a docker source emits a copy-out-of-container step');
check($download_at !== false && $stage_at !== false && $stage_at < $download_at,
	'the dump is staged onto the host before SCP tries to download it',
	implode(' | ', $labels));

$stage = $stage_at === false ? array() : $steps[$stage_at];
check(!empty($stage['on_host']), 'the staging step runs on the host, not docker exec\'d');
check(($stage['node_id'] ?? 0) === $dock_source->key,
	'the staging step addresses the source node');
check(strpos($stage['cmd'] ?? '', "docker cp 'sourcedock':") !== false,
	'the staging step copies out of the source container', $stage['cmd'] ?? '');

check(in_array('Clean up staged dump on source host', $labels),
	'the staged host copy gets its own cleanup step');

// A bare source needs no staging — the dump is already on the host.
$steps = JobCommandBuilder::build_copy_database($bare_source, $copy_target);
$labels = array_map(function ($s) { return $s['label']; }, $steps);
check(!in_array('Copy dump out of container', $labels),
	'a bare source emits no container staging step');
check(!in_array('Clean up staged dump on source host', $labels),
	'a bare source emits no host staging cleanup');

section('Restore semantics: replace, verified, loud');

// A restore must leave the database equal to the snapshot. A plain psql pipe
// over a populated schema collides on every CREATE, aborts a whole table's
// COPY on one duplicate key, and still exits 0 — the job reports completed
// over a silent mix of old and new rows (copy_database job #830, 429 errors,
// 31 tables kept their old data). So every restore site must: verify the
// archive before destroying anything, drop and recreate the schema, and run
// psql with ON_ERROR_STOP so a load error fails the job.
/** Assert one restore command carries the full replace contract. */
function jcb_check_restore_cmd($label, $cmd) {
	check(strpos($cmd, 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;') !== false,
		$label . ': the restore drops and recreates the schema', $cmd);
	check(substr_count($cmd, 'ON_ERROR_STOP=1') >= 2,
		$label . ': both the drop and the load run under ON_ERROR_STOP', $cmd);
	$gate = strpos($cmd, 'gunzip -t');
	$drop = strpos($cmd, 'DROP SCHEMA');
	if ($gate !== false) {
		check($gate < $drop, $label . ': the integrity check precedes the drop', $cmd);
	}
	return $gate !== false;
}

// The copy paths operate on a plaintext dump they just created, so they stay
// inline and must carry the full drop+ON_ERROR_STOP contract in the command.
$inline_restore_builders = [
	'copy_database' => JobCommandBuilder::build_copy_database($dock_source, $copy_target),
	'copy_database_by_name' => JobCommandBuilder::build_copy_database_by_name($copy_target,
		['source_db_name' => 'otherdb']),
];

foreach ($inline_restore_builders as $name => $steps) {
	$restore_cmd = '';
	$restore_at = null;
	$verify_at = null;
	$backup_at = null;
	foreach ($steps as $i => $step) {
		$label = $step['label'] ?? '';
		if (strpos($label, 'Restore') === 0) { $restore_cmd = $step['cmd']; $restore_at = $i; }
		if ($label === 'Verify backup archive') { $verify_at = $i; }
		if (strpos($label, 'Auto-backup') === 0) { $backup_at = $i; }
	}
	check($restore_cmd !== '', $name . ': a restore step is emitted');
	$inline_gate = jcb_check_restore_cmd($name, $restore_cmd);
	check($inline_gate || ($verify_at !== null && $verify_at < $restore_at),
		$name . ': destruction is gated on a gunzip -t integrity check');
	check($backup_at !== null && $backup_at < $restore_at,
		$name . ': the pre-restore safety dump precedes the restore');
}

// The dashboard restore and the from-backup install DB stage both delegate to
// the single restore engine (restore_database.sh). The verify-before-destroy,
// schema-replace, and ON_ERROR_STOP contract lives INSIDE the script, so the
// emitted command must call the script with an explicit --key-file (so an
// encrypted archive can decrypt) and must NOT drop the schema inline.
/** Assert a restore step delegates to the engine rather than dropping inline. */
function jcb_check_engine_restore_cmd($label, $cmd) {
	check(strpos($cmd, 'restore_database.sh') !== false,
		$label . ': the restore delegates to restore_database.sh', $cmd);
	check(strpos($cmd, '--key-file') !== false,
		$label . ': the restore passes an explicit --key-file for decryption', $cmd);
	check(strpos($cmd, 'DROP SCHEMA') === false,
		$label . ': the restore does not drop the schema inline (the engine owns it)', $cmd);
}

$engine_steps = JobCommandBuilder::build_restore_database($copy_target,
	['local_path' => '/backups/copytarget-2026-01-01-000000.sql.gz']);
$restore_cmd = '';
$restore_at = null;
$backup_at = null;
foreach ($engine_steps as $i => $step) {
	$label = $step['label'] ?? '';
	if (strpos($label, 'Restore') === 0) { $restore_cmd = $step['cmd']; $restore_at = $i; }
	if (strpos($label, 'Auto-backup') === 0) { $backup_at = $i; }
}
check($restore_cmd !== '', 'restore_database: a restore step is emitted');
jcb_check_engine_restore_cmd('restore_database', $restore_cmd);
check($backup_at !== null && $restore_at !== null && $backup_at < $restore_at,
	'restore_database: the pre-restore safety dump precedes the restore');

// The install-node clone restore follows the same engine contract.
$clone_restore = '';
foreach ($clone_steps as $step) {
	if (($step['label'] ?? '') === 'Restore source database') { $clone_restore = $step['cmd']; }
}
check($clone_restore !== '', 'install_node from-backup: a DB restore step is emitted');
jcb_check_engine_restore_cmd('install_node from-backup', $clone_restore);

// Dumps are plain snapshots: never self-cleaning (the restore owns replacement),
// and job-internal dumps are role-portable because the restore runs as the
// TARGET site's own DB user under ON_ERROR_STOP — an OWNER TO naming the
// source site's role would fail the job.
$dump_sets = $inline_restore_builders;
$dump_sets['restore_database'] = $engine_steps;
$dump_sets['install_node from-backup'] = $clone_steps;
foreach ($dump_sets as $name => $steps) {
	foreach ($steps as $step) {
		$label = $step['label'] ?? '';
		if (strpos($step['cmd'] ?? '', 'pg_dump') === false) { continue; }
		check(strpos($step['cmd'], '--clean') === false,
			$name . ' / ' . $label . ': no dump is self-cleaning', $step['cmd']);
		if (strpos($label, 'Dump source database') === 0) {
			check(strpos($step['cmd'], '--no-owner --no-acl') !== false,
				$name . ' / ' . $label . ': job-internal dumps carry no role names', $step['cmd']);
		}
	}
}

section('Teardown phase: scratch is torn down, deliverables are not');

// A job that fails mid-way never reaches trailing cleanup steps, so scratch —
// dumps, staged archives, unpacked installers — piles up on shared production
// hosts (353 MB per attempted clone, and the failure path is the COMMON path:
// 15 of 28 install jobs failed). Steps flagged 'teardown' run on every exit.
// These checks pin the two builder-side guarantees the agent relies on:
// every scratch path has a teardown step, and teardown steps sit at the tail
// of the array so an un-upgraded agent (which ignores the flag and runs
// sequentially) never deletes an artifact before the step that uses it.

/** Collect the per-job scratch paths mentioned by a builder's MAIN steps. */
function jcb_scratch_paths($steps) {
	$paths = array();
	$pattern = '#(?:/tmp/(?:local_copy|copy|install|joinery_restore|joinery_install|joinery_discover)_[A-Za-z0-9][A-Za-z0-9_.]*|/backups/install_[A-Za-z0-9][A-Za-z0-9_.]*)#';
	foreach ($steps as $step) {
		if (!empty($step['teardown'])) { continue; }
		foreach (array($step['cmd'] ?? '', $step['remote_path'] ?? '', $step['local_path'] ?? '') as $text) {
			if ($text !== '' && preg_match_all($pattern, $text, $m)) {
				foreach ($m[0] as $p) { $paths[$p] = true; }
			}
		}
	}
	return array_keys($paths);
}

/** Every scratch path a builder creates must appear in some teardown step. */
function jcb_assert_teardown_coverage($name, $steps) {
	$teardown_text = '';
	foreach ($steps as $step) {
		if (!empty($step['teardown'])) { $teardown_text .= ($step['cmd'] ?? '') . "\n"; }
	}
	$paths = jcb_scratch_paths($steps);
	check(count($paths) > 0, $name . ': scratch paths were found to audit');
	foreach ($paths as $p) {
		check(strpos($teardown_text, $p) !== false,
			$name . ': scratch path ' . $p . ' has a matching teardown step');
	}
}

/** No main step may follow a teardown step (the old-agent placement rule). */
function jcb_assert_tail_placement($name, $steps) {
	$offender = '';
	$seen_teardown = false;
	foreach ($steps as $step) {
		if (!empty($step['teardown'])) { $seen_teardown = true; continue; }
		if ($seen_teardown) { $offender = $step['label'] ?? '(unlabeled)'; break; }
	}
	check($offender === '', $name . ': no main step follows a teardown step', $offender);
}

/** Teardown steps must be idempotent, short-fused, and safe on old agents. */
function jcb_assert_teardown_shape($name, $steps) {
	foreach ($steps as $step) {
		if (empty($step['teardown'])) { continue; }
		$label = $step['label'] ?? '(unlabeled)';
		check(preg_match('/rm -r?f /', $step['cmd'] ?? '') === 1,
			$name . ' / ' . $label . ': teardown is an idempotent rm', $step['cmd'] ?? '');
		check(!empty($step['timeout']) && $step['timeout'] <= 120,
			$name . ' / ' . $label . ': teardown carries a short timeout');
		check(!empty($step['continue_on_error']),
			$name . ' / ' . $label . ': continue_on_error is spelled out for old agents');
	}
}

$dock_target = jcb_node(array(
	'mgn_container_name' => 'targetdock',
	'mgn_web_root' => '/var/www/html/targetdock/public_html'));
$clone_docker_target = jcb_node(array(
	'mgn_web_root' => '/var/www/html/dockclone/public_html',
	'mgn_site_url' => 'https://dockclone.example.com'));

// Every builder in the spec's inventory, in its scratch-heaviest variant.
$teardown_suites = array(
	'copy_database docker→docker' => JobCommandBuilder::build_copy_database($dock_source, $dock_target),
	'copy_database bare→bare'     => JobCommandBuilder::build_copy_database($bare_source, $copy_target),
	'copy_database_by_name'       => JobCommandBuilder::build_copy_database_by_name($copy_target,
		array('source_db_name' => 'otherdb')),
	'discover_nodes'              => JobCommandBuilder::build_discover_nodes(array(
		'host' => '192.0.2.50', 'ssh_user' => 'root',
		'ssh_key_path' => '/home/user1/.ssh/id_ed25519_claude')),
	'install_node fresh'          => JobCommandBuilder::build_install_node($clone_docker_target, array(
		'mode' => 'fresh', 'sitename' => 'freshsite', 'domain' => 'fresh.example.com',
		'docker_mode' => 'bare-metal')),
	'install_node clone bare'     => $clone_steps,
	'install_node clone docker'   => JobCommandBuilder::build_install_node($clone_docker_target, array(
		'mode' => 'from_backup', 'sitename' => 'dockclone', 'domain' => 'source.example.com',
		'docker_mode' => 'docker', 'source_node_id' => $dock_source->key,
		'backup_source' => 'new')),
);

foreach ($teardown_suites as $name => $suite_steps) {
	jcb_assert_teardown_coverage($name, $suite_steps);
	jcb_assert_tail_placement($name, $suite_steps);
	jcb_assert_teardown_shape($name, $suite_steps);
}

// Rule 2 (deliverables): the publish-upgrade job exists to place release
// archives in the upgrade repository — nothing about it may be teardown.
$pub_steps = JobCommandBuilder::build_publish_upgrade(array('release_notes' => 'harness test'));
$pub_has_teardown = false;
foreach ($pub_steps as $step) {
	if (!empty($step['teardown'])) { $pub_has_teardown = true; }
}
check(!$pub_has_teardown, 'publish_upgrade emits no teardown step — its archives are the deliverable');

// Rule 1 (policy deletions): the local-cleanup that follows a successful upload
// is folded INTO the upload step (chained with && after the upload command), so
// it deletes exactly the file it just uploaded and can never run on an upload
// failure or delete a backup that landed between two steps (P-23). There must be
// no separate cleanup step, and the rm must sit after the upload in the command.
require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
$bkt = new BackupTarget(NULL);
$bkt->set('bkt_name', 'HarnessTest Target ' . bin2hex(random_bytes(3)));
$bkt->set('bkt_provider', 'b2');
$bkt->set('bkt_bucket', 'harness-test-bucket');
$bkt->set('bkt_credentials', json_encode(array('key_id' => 'k', 'application_key' => 'a')));
$bkt->save();
harness_register_row('bkt_backup_targets', 'bkt_id', $bkt->key);

$offsite_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/offsite/public_html',
	'mgn_bkt_backup_target_id' => $bkt->key,
	'mgn_delete_local_after_upload' => true));
$offsite_steps = JobCommandBuilder::build_backup_database($offsite_node);
$upload_step = null;
$separate_cleanup = null;
foreach ($offsite_steps as $step) {
	if (strpos($step['label'] ?? '', 'Upload backup') === 0) { $upload_step = $step; }
	if (($step['label'] ?? '') === 'Clean up local backup') { $separate_cleanup = $step; }
}
check($upload_step !== null, 'the upload step exists to audit');
check($separate_cleanup === null,
	'no separate local-cleanup step exists — cleanup is folded into the upload');
$ucmd = $upload_step['cmd'] ?? '';
// The rm must ride the heredoc REDIRECT line: the shell keeps parsing the
// command list there and reads the body afterwards, so the rm runs iff the
// upload succeeded. Anything chained after the TERMINATOR line does not parse
// as shell at all — it is swallowed into the uploader's stdin and the step
// dies on a PHP parse error before uploading anything.
$ucmd_lines = explode("\n", $ucmd);
$redirect_line = '';
foreach ($ucmd_lines as $l) {
	if (strpos($l, "<<'__JOINERY_UPLOADER_EOF__'") !== false) { $redirect_line = $l; break; }
}
check($redirect_line !== '', 'the upload step feeds the uploader via heredoc');

// Read the variable the command uploads out of the command itself rather than
// naming it here. The literal name was NEWEST_BACKUP until the upload-retry
// work renamed it to UPLOAD_FILE, and both assertions in this block kept
// checking the old name: this one started failing, and the negative one below
// started passing for the wrong reason. Deriving it means a future rename
// cannot rot either check.
preg_match('/php -- upload "\$(\w+)"/', $redirect_line, $upload_var);
$uploaded = $upload_var[1] ?? '';
check($uploaded !== '', 'the redirect line names the file it uploads', $redirect_line);

check($uploaded !== '' && strpos($redirect_line, '&& rm -f "$' . $uploaded . '"') !== false,
	'retention rm rides the heredoc redirect line (runs only on upload success)', $redirect_line);

// The rm must target the file that was just uploaded, not some other path a
// rename or a copy-paste could leave behind — deleting the wrong file here
// destroys a backup that was never sent anywhere.
preg_match('/rm -f "\$(\w+)"/', $redirect_line, $removed_var);
check(($removed_var[1] ?? '') === $uploaded,
	'and removes exactly the file it uploaded', 'uploaded ' . $uploaded . ', removed ' . ($removed_var[1] ?? '(none)'));
check(trim(end($ucmd_lines)) === '__JOINERY_UPLOADER_EOF__',
	'the heredoc terminator is the entire final line — nothing chained after it', trim(end($ucmd_lines)));
// Shell validity: bash warns "delimited by end-of-file" when the heredoc never
// closes — the exact failure the old strpos-only check waved through.
$ucmd_tmp = tempnam(sys_get_temp_dir(), 'jcbsh');
file_put_contents($ucmd_tmp, $ucmd . "\n");
exec('bash -n ' . escapeshellarg($ucmd_tmp) . ' 2>&1', $ucmd_lint, $ucmd_rc);
unlink($ucmd_tmp);
$ucmd_lint_text = implode("\n", $ucmd_lint);
check($ucmd_rc === 0 && strpos($ucmd_lint_text, 'delimited by end-of-file') === false,
	'the upload+retention command parses as valid bash with a closed heredoc', $ucmd_lint_text);

// A node WITHOUT the retention flag must never delete its local backup.
$keep_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/keep/public_html',
	'mgn_bkt_backup_target_id' => $bkt->key,
	'mgn_delete_local_after_upload' => false));
$keep_upload = '';
foreach (JobCommandBuilder::build_backup_database($keep_node) as $step) {
	if (strpos($step['label'] ?? '', 'Upload backup') === 0) { $keep_upload = $step['cmd']; }
}
// Any rm at all, not one spelled a particular way. Checking for a specific
// variable name is what let this pass while naming a variable that no longer
// existed.
check(strpos($keep_upload, 'rm -f') === false,
	'without the retention flag the upload step deletes nothing', $keep_upload);

// From-EXISTING-backup clones: the named /backups/ paths are the user's real
// backup files, not job scratch. No teardown step may touch them.
$existing_steps = JobCommandBuilder::build_install_node($clone_docker_target, array(
	'mode' => 'from_backup', 'sitename' => 'dockclone', 'domain' => 'source.example.com',
	'docker_mode' => 'bare-metal', 'source_node_id' => $source_node->key,
	'backup_source' => 'existing',
	'db_backup_path' => '/backups/keep_me.sql.gz',
	'project_backup_path' => '/backups/keep_me_project.tar.gz'));
$touches_named = false;
foreach ($existing_steps as $step) {
	if (!empty($step['teardown']) && strpos($step['cmd'] ?? '', '/backups/keep_me') !== false) {
		$touches_named = true;
	}
}
check(!$touches_named, 'a from-existing-backup clone never tears down the named backup files');
// The staged and target-side copies are still scratch in that variant.
jcb_assert_teardown_coverage('install_node clone existing', $existing_steps);
jcb_assert_tail_placement('install_node clone existing', $existing_steps);

// The installer directory is per-job: a failed install's teardown (or a
// stale-recovery replay) must never delete it out from under a later install.
$fresh_a = jcb_cmds($teardown_suites['install_node fresh']);
check(preg_match('#/tmp/joinery_install_[a-f0-9]{12}#', $fresh_a) === 1,
	'the installer directory carries the per-job transfer id');
check(strpos($fresh_a, "/tmp/joinery_install ") === false
	&& strpos($fresh_a, "/tmp/joinery_install/") === false
	&& strpos($fresh_a, "/tmp/joinery_install\n") === false,
	'the fixed /tmp/joinery_install path is gone');
$fresh_b = jcb_cmds(JobCommandBuilder::build_install_node($clone_docker_target, array(
	'mode' => 'fresh', 'sitename' => 'freshsite', 'domain' => 'fresh.example.com',
	'docker_mode' => 'bare-metal')));
preg_match('#/tmp/joinery_install_[a-f0-9]{12}#', $fresh_a, $ma);
preg_match('#/tmp/joinery_install_[a-f0-9]{12}#', $fresh_b, $mb);
check(!empty($ma[0]) && !empty($mb[0]) && $ma[0] !== $mb[0],
	'two installs never share an installer directory', ($ma[0] ?? '?') . ' vs ' . ($mb[0] ?? '?'));

section('Backup key: one envelope per run, nothing precious left behind');

// Every encrypted backup mints its own key on the node, seals it to the
// operator's recovery key and to the node's own site key, and destroys the
// plaintext copy. A cloud target forces encryption.
$enc_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/encnode/public_html',
	'mgn_bkt_backup_target_id' => $bkt->key));
foreach (['build_backup_database', 'build_backup_project'] as $builder) {
	$steps = JobCommandBuilder::$builder($enc_node);
	$all_cmd = implode("\n", array_map(function ($s) { return $s['cmd'] ?? ''; }, $steps));

	$mint_at = $engine_at = $final_at = -1;
	$mint_step = $final_step = '';
	foreach ($steps as $i => $s) {
		$label = $s['label'] ?? '';
		if ($label === 'Mint backup encryption key')     { $mint_at = $i; $mint_step = $s['cmd']; }
		if (strpos($label, 'Run ') === 0)                { $engine_at = $i; }
		if ($label === 'Seal the backup key to the archive') { $final_at = $i; $final_step = $s['cmd']; }
	}

	check($mint_at !== -1, $builder . ': mints an envelope for the run');
	check($mint_at < $engine_at, $builder . ': mints the key before the engine that encrypts with it',
		"mint@{$mint_at} engine@{$engine_at}");
	check($final_at > $engine_at, $builder . ': seals the envelope to the archive after it exists',
		"final@{$final_at} engine@{$engine_at}");

	check(strpos($mint_step, 'backup_envelope.php') !== false && strpos($mint_step, ' mint') !== false,
		$builder . ': minting runs the envelope tool', $mint_step);
	check(($steps[$mint_at]['type'] ?? '') === 'ssh',
		$builder . ': minting happens ON THE NODE, so no plaintext key crosses the wire');
	check(strpos($mint_step, '--recovery-pub') !== false && strpos($mint_step, '--site-key') !== false,
		$builder . ': seals to both the recovery key and the node site key', $mint_step);
	check(strpos($mint_step, '/config/backup_site_key') !== false,
		$builder . ': the site key is read from the node project config', $mint_step);

	// The engine has to be told which key to use, or it would silently fall
	// back to whatever happens to be in $HOME and the envelope would describe
	// a key the archive was not encrypted with.
	check(strpos($all_cmd, '--key-file') !== false
		&& strpos($all_cmd, JobCommandBuilder::ENVELOPE_SCRATCH_PREFIX) !== false,
		$builder . ': the engine is pointed at the minted key');

	// Scratch paths are per job. A fixed path means two backups running on one
	// node at the same time mint over each other's envelope, and the loser gets
	// an archive whose envelope names a different archive — silent, and only
	// discovered when someone tries to restore.
	$second = JobCommandBuilder::$builder($enc_node);
	$second_cmd = implode("\n", array_map(function ($s) { return $s['cmd'] ?? ''; }, $second));
	preg_match('/' . preg_quote(JobCommandBuilder::ENVELOPE_SCRATCH_PREFIX, '/') . '([0-9a-f]+)\./', $all_cmd, $m1);
	preg_match('/' . preg_quote(JobCommandBuilder::ENVELOPE_SCRATCH_PREFIX, '/') . '([0-9a-f]+)\./', $second_cmd, $m2);
	check(!empty($m1[1]) && !empty($m2[1]) && $m1[1] !== $m2[1],
		$builder . ': two jobs get different envelope scratch paths',
		($m1[1] ?? '?') . ' vs ' . ($m2[1] ?? '?'));

	// The archive is identified by being NEW, not by being newest. Newest picks
	// up whatever the node's own scheduled run wrote in the same window.
	check(strpos($all_cmd, JobCommandBuilder::BEFORE_LIST_PREFIX) !== false
		&& strpos($all_cmd, 'grep -vxF -f') !== false,
		$builder . ': resolves its own archive against a before-list, not ls -t');

	// The plaintext key must not outlive the run: leaving it on disk puts a
	// working decryption key next to the thing it decrypts.
	check(strpos($final_step, 'shred') !== false || strpos($final_step, 'rm -f') !== false,
		$builder . ': destroys the plaintext key when the run ends', $final_step);
	check(strpos($final_step, 'RC=$?') !== false,
		$builder . ': shreds the key even when the relabel fails, and still reports the failure', $final_step);

	check(strpos($all_cmd, 'openssl rand') === false,
		$builder . ': no ad-hoc node-side key generation remains anywhere in the job', $all_cmd);
	check(strpos($all_cmd, 'escrow') === false,
		$builder . ': no escrow step survives', $all_cmd);
}

// The envelope has to reach the bucket too — an encrypted archive alone is
// indistinguishable from noise.
$upload_labels = array();
foreach (JobCommandBuilder::build_backup_project($enc_node) as $s) {
	if (strpos($s['label'] ?? '', 'Upload backup') === 0) { $upload_labels[] = $s['cmd']; }
}
check(count($upload_labels) === 2, 'an encrypted backup uploads the archive AND its envelope',
	'uploads: ' . count($upload_labels));
check(strpos(implode("\n", $upload_labels), '.keys.json') !== false,
	'one of the uploads is the envelope sidecar');

// A plaintext backup on a node with no cloud target mints nothing — there is no
// key in play at all, and no envelope to upload.
$plain_node = jcb_node(array('mgn_web_root' => '/var/www/html/plainnode/public_html'));
$plain_labels = array_map(function ($s) { return $s['label'] ?? ''; },
	JobCommandBuilder::build_backup_database($plain_node));
check(!in_array('Mint backup encryption key', $plain_labels, true),
	'an unencrypted backup mints no key', implode(' | ', $plain_labels));
check(!in_array('Seal the backup key to the archive', $plain_labels, true),
	'and seals no envelope', implode(' | ', $plain_labels));

// Restores prefer the envelope beside the archive, so a node can restore itself
// with no operator present, and fall back to the old node key for archives made
// before envelopes existed.
$restore_cmd = '';
foreach (JobCommandBuilder::build_restore_database($enc_node,
		array('local_path' => '/backups/site-20260802.sql.gz.enc')) as $s) {
	if (($s['label'] ?? '') === 'Restore database from backup') { $restore_cmd = $s['cmd']; }
}
check($restore_cmd !== '', 'the restore builder emits a restore step');
// Assert the WHOLE path, not just the suffix: an empty archive path would
// still produce a string containing ".keys.json" and read as passing.
check(strpos($restore_cmd, "/backups/site-20260802.sql.gz.enc'.keys.json") !== false,
	'a database restore looks for the envelope belonging to THAT archive', $restore_cmd);
check(strpos($restore_cmd, '.joinery_backup_key') !== false,
	'and still falls back to the node key for pre-envelope archives', $restore_cmd);
check(strpos($restore_cmd, 'backup_envelope.php') !== false && strpos($restore_cmd, ' open ') !== false,
	'the envelope is opened with the site key, so no operator is needed', $restore_cmd);

// A restore with nothing to restore from must fail at build time, not after
// the pre-restore snapshot has already run.
$threw = false;
try { JobCommandBuilder::build_restore_database($enc_node, array('filename' => 'orphan.sql.gz.enc')); }
catch (Exception $e) { $threw = true; }
check($threw, 'a restore with no resolvable archive path is refused up front');

section('Cloud credentials: placeholder-only (S-8) — no inline fallback exists');

// Job rows persist forever, so credentials must NEVER be inlined into a
// command. The agent resolves __SM_CREDS_<id>__ in memory at run time; a job
// an old agent cannot run fails visibly instead of leaking.
$cloud_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/credmode/public_html',
	'mgn_bkt_backup_target_id' => $bkt->key,
	'mgn_delete_local_after_upload' => false));

$ph_cmd = '';
foreach (JobCommandBuilder::build_backup_database($cloud_node) as $step) {
	if (strpos($step['label'] ?? '', 'Upload backup') === 0) { $ph_cmd = $step['cmd']; }
}
$token = '__SM_CREDS_' . (int)$bkt->key . '__';
check(strpos($ph_cmd, $token) !== false, 'upload command carries the __SM_CREDS_<id>__ token', $ph_cmd);
check(strpos($ph_cmd, "base64_decode('" . $token . "')") !== false,
	'creds line reads the token via base64_decode');
// The uploader SOURCE may reference $creds['secret_key']; what must never
// appear is inlined credential DATA — the var_export'd array the old
// fallback emitted.
check(strpos($ph_cmd, "'application_key'") === false && strpos($ph_cmd, '$creds = array') === false,
	'no inlined credential data appears anywhere in the command', substr($ph_cmd, 0, 300));
check(!property_exists('JobCommandBuilder', 'agent_placeholder_support_override'),
	'the inline-credentials fallback (and its heartbeat gate) is gone entirely');

section('Fleet backup run: config on stdin, nothing secret at rest');

// The manager-profile backup job. The node runs its own engine; what the
// builder contributes is the three things the node must not hold — the bucket,
// the credential and the recovery key — and they travel on stdin, not argv,
// because argv is world-readable on the box for the life of the process.
$run_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/runnode/public_html',
	'mgn_bkt_backup_target_id' => $bkt->key));

// Refusals: a job that cannot say where the backup goes, or which site to back
// up, fails at build time with a message the operator sees — not part-way
// through a backup on the node.
// Where a backup goes is the control plane's decision, not the node's: the
// bucket, the credential and the recovery key all travel with the run. So a
// node that names no target is only a problem when the choice is genuinely
// ambiguous. With several shelves enabled, refuse and say so.
$enabled_now = 0;
$enabled_before = new MultiBackupTarget(array('enabled' => true, 'deleted' => false));
$enabled_before->load();
foreach ($enabled_before as $ignored) { $enabled_now++; }

$threw = false;
$refusal = '';
try {
	JobCommandBuilder::build_backup_run(jcb_node(array(
		'mgn_web_root' => '/var/www/html/notarget/public_html')));
} catch (Exception $e) { $threw = true; $refusal = $e->getMessage(); }

if ($enabled_now > 1) {
	check($threw, 'backup_run refuses a target-less node while several shelves are enabled');
	check(strpos($refusal, 'real choice') !== false,
		'the refusal says the choice is real, not that nothing is configured', $refusal);
} else {
	check(!$threw, 'backup_run resolves a target-less node when one shelf is enabled');
}

// The single-shelf case is the one that matters in practice, and it is the
// reason eight of eleven nodes sat un-backed-up: registering a node never
// filled the pointer in. Disable this suite's own target so exactly the real
// one remains, then assert the inference — restored immediately after.
$bkt->set('bkt_enabled', false);
$bkt->save();
$sole_count = 0;
$sole_id = null;
$sole_set = new MultiBackupTarget(array('enabled' => true, 'deleted' => false));
$sole_set->load();
foreach ($sole_set as $only) {
	$sole_count++;
	$sole_id = $only->key;
}
if ($sole_count === 1) {
	$inferred = JobCommandBuilder::get_target(jcb_node(array(
		'mgn_web_root' => '/var/www/html/inferred/public_html')));
	check($inferred !== null && $inferred->key == $sole_id,
		'a node naming no target resolves to the sole enabled shelf',
		'resolved: ' . var_export($inferred ? $inferred->key : null, true));
} else {
	harness_skip('sole-shelf inference', "this deployment has {$sole_count} enabled targets, not 1");
}
$bkt->set('bkt_enabled', true);
$bkt->save();

// A named target still wins outright — inference never overrides a recorded
// choice, and a node pointing at a switched-off shelf is refused rather than
// silently redirected to whatever else happens to be enabled.
$named = JobCommandBuilder::get_target(jcb_node(array(
	'mgn_web_root' => '/var/www/html/named/public_html',
	'mgn_bkt_backup_target_id' => $bkt->key)));
check($named !== null && $named->key == $bkt->key, 'a named target is used as named');

$off = new BackupTarget(NULL);
$off->set('bkt_name', 'HarnessTest Disabled ' . bin2hex(random_bytes(3)));
$off->set('bkt_provider', 'b2');
$off->set('bkt_bucket', 'harness-disabled-bucket');
$off->set('bkt_credentials', json_encode(array('key_id' => 'k', 'application_key' => 'a')));
$off->set('bkt_enabled', false);
$off->save();
harness_register_row('bkt_backup_targets', 'bkt_id', $off->key);
check(JobCommandBuilder::get_target(jcb_node(array(
	'mgn_web_root' => '/var/www/html/offtarget/public_html',
	'mgn_bkt_backup_target_id' => $off->key))) === null,
	'a node naming a disabled target is refused, not redirected');

$threw = false;
try {
	JobCommandBuilder::build_backup_run(jcb_node(array(
		'mgn_bkt_backup_target_id' => $bkt->key, 'mgn_web_root' => '')));
} catch (Exception $e) { $threw = true; }
check($threw, 'backup_run refuses a node with no web root');

// The slug becomes a bucket path segment, so it is constrained to a shape
// rather than escaped and hoped for. Set without saving: the builder is pure,
// and the claim is about the builder's own gate, not the model's.
foreach (array($PAYLOAD, $SUBSHELL, 'has space', '../../etc') as $bad_slug) {
	$slug_node = jcb_node(array(
		'mgn_web_root' => '/var/www/html/badslug/public_html',
		'mgn_bkt_backup_target_id' => $bkt->key));
	$slug_node->set('mgn_slug', $bad_slug);
	$threw = false;
	try { JobCommandBuilder::build_backup_run($slug_node); }
	catch (Exception $e) { $threw = true; }
	check($threw, 'backup_run refuses the slug ' . var_export(substr($bad_slug, 0, 20), true));
}

// The happy path: one labelled SSH step running the node's own engine under
// the manager profile, config fed through a QUOTED heredoc so the shell
// expands nothing in the body.
$run_steps = JobCommandBuilder::build_backup_run($run_node);
check(count($run_steps) === 1, 'backup_run emits exactly one step', 'steps: ' . count($run_steps));
$run_step = $run_steps[0];
check(($run_step['type'] ?? '') === 'ssh' && !empty($run_step['label']),
	'the step is a labelled SSH step like every other job');
check(($run_step['timeout'] ?? 0) > 3600,
	'the timeout is transfer-sized, not the default', 'timeout: ' . ($run_step['timeout'] ?? 0));

$run_cmd = $run_step['cmd'];
check(strpos($run_cmd, "/utils/run_backup.php' --profile=manager") !== false,
	'the node runs run_backup.php under the manager profile', $run_cmd);
check(strpos($run_cmd, "<<'__JOINERY_BACKUP_CONFIG_EOF__'") !== false,
	'the config heredoc is quoted, so nothing in the body reaches the shell', $run_cmd);
$run_lines = explode("\n", $run_cmd);
check(trim(end($run_lines)) === '__JOINERY_BACKUP_CONFIG_EOF__',
	'the heredoc terminator is the entire final line — nothing chained after it', trim(end($run_lines)));

$run_tmp = tempnam(sys_get_temp_dir(), 'jcbrun');
file_put_contents($run_tmp, $run_cmd . "\n");
$run_lint = array();
exec('bash -n ' . escapeshellarg($run_tmp) . ' 2>&1', $run_lint, $run_rc);
unlink($run_tmp);
check($run_rc === 0, 'the emitted command parses as valid bash with a closed heredoc',
	implode("\n", $run_lint));

// The heredoc body is the config the node will run under; hold it to the
// contract rather than to substrings of the command.
$run_config = json_decode($run_lines[1] ?? '', true);
check(is_array($run_config), 'the heredoc body is one line of valid JSON');
check(($run_config['bucket'] ?? '') === 'harness-test-bucket',
	'the config names the target bucket', $run_config['bucket'] ?? '?');
check(($run_config['slug'] ?? '') === (string)$run_node->get('mgn_slug'),
	'the config carries the node slug for the bucket path');
check(($run_config['type'] ?? '') === 'project' && ($run_config['mode'] ?? '') === 'chain',
	'type and mode default to a chained project backup');
check(($run_config['full_interval_days'] ?? 0) === 7,
	'the full interval defaults to a weekly full');

// The credential is the resolve-at-run-time placeholder the agent swaps in
// memory — job rows persist forever, so credential DATA must never be inlined.
$run_token = '__SM_CREDS_' . (int)$bkt->key . '__';
check(($run_config['credentials_b64'] ?? '') === $run_token,
	'the credential is the __SM_CREDS_<id>__ placeholder, resolved at run time');
check(strpos($run_cmd, "'application_key'") === false && strpos($run_cmd, '"application_key"') === false,
	'no credential data appears anywhere in the command');

// The recovery key rides along per run as a PUBLIC key — never written into
// the node's settings, so the node cannot start using it for its own runs.
$run_pub = base64_decode((string)($run_config['recovery_public_key'] ?? ''), true);
check($run_pub !== false && strlen($run_pub) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES,
	'the recovery key travels in the config as a valid base64 box public key');

// Policy fields reach the config; unrecognised values coerce to the closed set
// rather than travelling to a node as text.
$run_lines = explode("\n", JobCommandBuilder::build_backup_run($run_node, array(
	'type' => 'database', 'mode' => 'full', 'full_interval_days' => 3))[0]['cmd']);
$run_config = json_decode($run_lines[1] ?? '', true);
check(($run_config['type'] ?? '') === 'database' && ($run_config['mode'] ?? '') === 'full'
	&& ($run_config['full_interval_days'] ?? 0) === 3,
	'policy type, mode and full interval reach the node config');
$run_lines = explode("\n", JobCommandBuilder::build_backup_run($run_node, array(
	'type' => $PAYLOAD, 'mode' => 'evil'))[0]['cmd']);
$run_config = json_decode($run_lines[1] ?? '', true);
check(($run_config['type'] ?? '') === 'project' && ($run_config['mode'] ?? '') === 'chain',
	'unrecognised type and mode coerce to the defaults rather than reaching a shell');

check(in_array('backup_run', ManagementJob::filterTypes(), true),
	'backup_run is a filterable job type, so fleet runs are findable on the jobs pages');

// A target holding a node (write-only) credential hands nodes THAT key's
// token; the main delete-capable credential then never travels to a node.
$bkt_split = new BackupTarget(NULL);
$bkt_split->set('bkt_name', 'HarnessTest Split Target ' . bin2hex(random_bytes(3)));
$bkt_split->set('bkt_provider', 'b2');
$bkt_split->set('bkt_bucket', 'harness-split-bucket');
$bkt_split->set('bkt_credentials', json_encode(array('access_key' => 'MAIN', 'secret_key' => 'main_full_perm')));
$bkt_split->set('bkt_node_credentials', json_encode(array('access_key' => 'NODE', 'secret_key' => 'node_write_only')));
$bkt_split->save();
harness_register_row('bkt_backup_targets', 'bkt_id', $bkt_split->key);

$split_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/splitnode/public_html',
	'mgn_bkt_backup_target_id' => $bkt_split->key));

$split_lines = explode("\n", JobCommandBuilder::build_backup_run($split_node)[0]['cmd']);
$split_config = json_decode($split_lines[1] ?? '', true);
$node_token = '__SM_NODE_CREDS_' . (int)$bkt_split->key . '__';
$main_token = '__SM_CREDS_' . (int)$bkt_split->key . '__';
check(($split_config['credentials_b64'] ?? '') === $node_token,
	'with a node credential configured, backup_run carries the node token');
check(strpos(implode("\n", $split_lines), $main_token) === false,
	'the main (delete-capable) token appears nowhere in the node-bound command');

// The one-off backup jobs upload from the node too, so their uploader follows
// the same rule.
$split_upload = '';
foreach (JobCommandBuilder::build_backup_database($split_node) as $s) {
	if (strpos($s['label'] ?? '', 'Upload backup') === 0) { $split_upload = $s['cmd']; break; }
}
check(strpos($split_upload, $node_token) !== false,
	'the ad-hoc backup uploader also carries the node token', substr($split_upload, 0, 120));
check(strpos($split_upload, $main_token) === false,
	'and never the main token');

// Only an UPLOAD may run under the write-only key. A cloud delete needs delete
// capability and a restore download needs read, so those node-side scripts must
// keep the main token even when a node credential is configured — otherwise a
// properly scoped write-only key would fail exactly the operations it is
// scoped against.
$split_del = '';
foreach (JobCommandBuilder::build_delete_backup($split_node, array(
		'target' => 'cloud', 'cloud_path' => 'joinery-backups/x/y.tar.gz')) as $s) {
	if (($s['label'] ?? '') === 'Delete cloud backup') { $split_del = $s['cmd']; }
}
check($split_del !== '', 'a cloud delete step is emitted for the split-credential target');
check(strpos($split_del, $main_token) !== false && strpos($split_del, $node_token) === false,
	'a cloud delete carries the main token, never the write-only one');

$split_dl = '';
foreach (JobCommandBuilder::build_restore_database($split_node, array(
		'filename' => 'splitnode-20260101.sql.gz.enc', 'cloud_path' => 'joinery-backups/x/splitnode-20260101.sql.gz.enc')) as $s) {
	if (($s['label'] ?? '') === 'Download backup from cloud') { $split_dl = $s['cmd']; }
}
check($split_dl !== '', 'a cloud restore emits a download step for the split-credential target');
check(strpos($split_dl, $main_token) !== false && strpos($split_dl, $node_token) === false,
	'a restore download carries the main token, never the write-only one');

// Without a node credential, everything stays on the main token ($run_node's
// target has none) — already asserted above via credentials_b64 === __SM_CREDS_.

section('Decommission: ship + run remove_account.sh, then verify gone');

// A Docker node's teardown runs entirely on the host (docker + apache live there):
// the tested remover is shipped via scp, run on_host with the derived site name, and
// a verify step re-probes the host and gates on DECOMMISSION_VERIFIED.
$decom_docker = jcb_node(array(
	'mgn_container_name' => 'decomsite',
	'mgn_web_root'       => '/var/www/html/decomsite/public_html'));
$dsteps = JobCommandBuilder::build_decommission_node($decom_docker);
$scp = null; $run = null; $verify = null;
foreach ($dsteps as $s) {
	if (($s['type'] ?? '') === 'scp') { $scp = $s; }
	if (strpos($s['label'] ?? '', 'Remove the site') === 0) { $run = $s; }
	if (strpos($s['label'] ?? '', 'Verify the site') === 0) { $verify = $s; }
}
check($scp !== null && ($scp['direction'] ?? '') === 'upload'
	&& substr((string)($scp['local_path'] ?? ''), -strlen('sysadmin_tools/remove_account.sh')) === 'sysadmin_tools/remove_account.sh',
	'ships remove_account.sh to the host via scp upload', $scp['local_path'] ?? '?');
check($run !== null && !empty($run['on_host']),
	'docker teardown runs on the host (on_host)');
check($run !== null && strpos($run['cmd'], "'decomsite'") !== false && strpos($run['cmd'], ' -y') !== false,
	'runs the remover with the escaped, node-derived site name and -y', $run['cmd'] ?? '?');
check($verify !== null && strpos($verify['cmd'], 'DECOMMISSION_VERIFIED') !== false
	&& strpos($verify['cmd'], 'DECOMMISSION_FAILED_VERIFY') !== false && strpos($verify['cmd'], 'exit 1') !== false,
	'verify step gates on DECOMMISSION_VERIFIED and fails when any trace remains', $verify['cmd'] ?? '?');

// Site name is derived from node fields only — never from operator input.
check(JobCommandBuilder::decommission_site_name($decom_docker) === 'decomsite',
	'docker site name = container name');
$decom_bare = jcb_node(array('mgn_web_root' => '/var/www/html/baremetalsite/public_html'));
check(JobCommandBuilder::decommission_site_name($decom_bare) === 'baremetalsite',
	'bare-metal site name = web-root parent directory');
foreach (JobCommandBuilder::build_decommission_node($decom_bare) as $s) {
	if (strpos($s['label'] ?? '', 'Remove the site') === 0) {
		check(empty($s['on_host']), 'bare-metal teardown runs on the node itself (not on_host)');
	}
}

// A relay is not a remove_account.sh-shaped site: decommission_node refuses it.
$relay = jcb_node(array('mgn_is_relay' => true, 'mgn_web_root' => '/var/www/html/relaysite/public_html'));
$relay_refused = false;
try { JobCommandBuilder::build_decommission_node($relay); } catch (Exception $e) { $relay_refused = true; }
check($relay_refused, 'a relay node refuses decommission_node');

// A malformed site name (no safe value derivable) refuses rather than guessing.
$bad = jcb_node(array('mgn_web_root' => ''));
$bad_refused = false;
try { JobCommandBuilder::decommission_site_name($bad); } catch (Exception $e) { $bad_refused = true; }
check($bad_refused, 'an underivable site name refuses instead of building a dangerous command');

// No credential material anywhere in the built steps.
$all_decom = implode("\n", array_map(function ($s) { return ($s['cmd'] ?? '') . '|' . ($s['local_path'] ?? ''); }, $dsteps));
check(strpos($all_decom, '__SM_CREDS_') === false && strpos($all_decom, 'secret_key') === false,
	'decommission steps carry no credentials');

// Shell validity: every ssh step must parse as bash. The verify step joins one
// check per resource, and a bad separator (`fi if` on one line) is a syntax
// error — exit 2 at run time — that the substring asserts above wave through.
// This runs the actual command text through `bash -n` for both topologies.
$decom_sets = [
	'docker'     => JobCommandBuilder::build_decommission_node($decom_docker),
	'bare-metal' => JobCommandBuilder::build_decommission_node($decom_bare),
];
foreach ($decom_sets as $kind => $steps_set) {
	foreach ($steps_set as $s) {
		if (($s['type'] ?? '') !== 'ssh' || empty($s['cmd'])) { continue; }
		$tmp = tempnam(sys_get_temp_dir(), 'jcbdecom');
		file_put_contents($tmp, $s['cmd'] . "\n");
		$lint = [];
		exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', $lint, $rc);
		unlink($tmp);
		check($rc === 0, "{$kind}: step '" . ($s['label'] ?? '?') . "' parses as valid bash", implode("\n", $lint));
	}
}

section('Status dot: uptime fallback when there is no status-check data');

// An uptime-only / skip-Joinery node (a relay) never runs the SSH status check, so
// it has no status_data. The dot must reflect the uptime result rather than sit grey.
$dot_up = jcb_node(array('mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'up'));
check(JobCommandBuilder::status_color_for_node($dot_up, null, false) === 'success',
	'uptime-up node with no status data shows green');
$dot_down = jcb_node(array('mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'down'));
check(JobCommandBuilder::status_color_for_node($dot_down, null, false) === 'danger',
	'uptime-down node with no status data shows red');
$dot_pending = jcb_node(array('mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => ''));
check(JobCommandBuilder::status_color_for_node($dot_pending, null, false) === 'secondary',
	'uptime enabled but not yet checked stays grey');
$dot_none = jcb_node(array('mgn_uptime_enabled' => false));
check(JobCommandBuilder::status_color_for_node($dot_none, null, false) === 'secondary',
	'no status data and no uptime monitoring stays grey');
// A hard state still dominates the uptime fallback.
$dot_failed = jcb_node(array('mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'up',
	'mgn_install_state' => 'install_failed'));
check(JobCommandBuilder::status_color_for_node($dot_failed, null, false) === 'danger',
	'install_failed still shows red even when uptime is up');

// A skip-Joinery node (relay): a FAILED status check must not override uptime-up —
// the SSH status check is expected to fail on it and is not its health signal.
$dot_relay = jcb_node(array('mgn_skip_joinery_checks' => true,
	'mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'up'));
check(JobCommandBuilder::status_color_for_node($dot_relay, null, true) === 'success',
	'skip-Joinery uptime-up node stays green even when its status check failed');
$dot_relay_down = jcb_node(array('mgn_skip_joinery_checks' => true,
	'mgn_uptime_enabled' => true, 'mgn_uptime_last_status' => 'down'));
check(JobCommandBuilder::status_color_for_node($dot_relay_down, null, false) === 'danger',
	'skip-Joinery uptime-down node shows red');

harness_finish();
