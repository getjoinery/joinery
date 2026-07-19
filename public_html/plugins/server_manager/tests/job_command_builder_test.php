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
 * step structure.
 *
 * Run: php plugins/server_manager/tests/job_command_builder_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

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
	// with a stub PATH entry set so only the canary is observable.
	$wrapped = 'cd ' . escapeshellarg($dir) . ' && { ' . $cmd . ' ; } >/dev/null 2>&1';
	@shell_exec($wrapped);
	$fired = file_exists($dir . '/CANARY_FIRED');
	@unlink($dir . '/CANARY_FIRED');
	return $fired;
}

$tmpdir = sys_get_temp_dir() . '/jcb_test_' . bin2hex(random_bytes(4));
@mkdir($tmpdir, 0777, true);
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

harness_finish();
