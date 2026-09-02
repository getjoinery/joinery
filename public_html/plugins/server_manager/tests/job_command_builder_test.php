<?php
/** @joinery-test
 * name: job_command_builder
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * JobCommandBuilder — the commands the management node sends to production nodes.
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
	// A node the last status check found holding a VERIFIED recovery key of its
	// own. That is the ordinary state of a managed node and the precondition for
	// every backup job, since backups seal to the node's key and nothing is
	// supplied from here — so it belongs in the fixture rather than in each test
	// that happens to build a backup.
	$node->set('mgn_last_status_data', json_encode(array('backup_recovery_state' => 'proven')));
	$node->set('mgn_backup_recovery_fpr', str_repeat('c3', 32));
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
check(in_array('primitive', $transports) && in_array('api', $transports)
		&& in_array('probe', $transports) && !in_array('ssh', $transports),
	'check_status reports agent, api and probe transports, and no SSH',
	implode(',', $transports));
check(JobCommandBuilder::transports_for('no_such_operation') === array(),
	'an unimplemented operation reports no transports');

check(!JobCommandBuilder::can_run($ssh_node, 'list_backups'),
	'SSH credentials alone run nothing with a variant builder — no _ssh variant remains');
check(!JobCommandBuilder::can_run($ssh_node, 'check_status'),
	'but not check_status, which has nothing on that node to reach or probe');
check(!JobCommandBuilder::can_run($bare_node, 'check_status'),
	'a node with neither transport cannot run anything');
check(!JobCommandBuilder::can_run($ssh_node, 'no_such_operation'),
	'no node can run an operation with no implementation');

// The disabled-button tooltip has to say something true, since it is the only
// explanation an admin gets for why an action is greyed out.
$why = JobCommandBuilder::why_cannot_run($bare_node, 'check_status');
check(strpos($why, 'no health check URL or port to probe') !== false,
	'the refusal reason names what is actually missing', $why);
check(strpos($why, 'no SSH implementation exists') === false,
	'and does not report the absence of a transport being retired as a shortfall', $why);
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

$cases['relay_add_tenant pull key'] = jcb_cmds(JobCommandBuilder::build_relay_add_tenant(
	$ssh_node, array('slug' => 'tenant-a', 'pull_pubkey' => $PAYLOAD)));

$cases['relay_add_tenant domains'] = jcb_cmds(JobCommandBuilder::build_relay_add_tenant(
	$ssh_node, array('slug' => 'tenant-a', 'pull_pubkey' => 'abc', 'domains' => $PAYLOAD)));

$cases['relay_set_domains domains'] = jcb_cmds(JobCommandBuilder::build_relay_set_domains(
	$ssh_node, array('slug' => 'tenant-a', 'domains' => $SUBSHELL)));

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
$cmd = jcb_cmds(JobCommandBuilder::build_relay_add_tenant(
	$ssh_node, array('slug' => 'tenant-a', 'pull_pubkey' => 'abc', 'domains' => $quote_payload)));
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
section('Local backup delete: a name, never a path');

// The node deletes inside its own compiled-in backup directory; the plane can
// only NAME a file. The legacy caller passes local_path, so the basename must
// be taken from it — a full path crossing the wire would reopen the plane's
// ability to point rm at anything.
$del_paired = jcb_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x05", 32)),
	'mgn_agent_version'    => '1.13.0'));
$del_built = JobCommandBuilder::build_delete_backup($del_paired,
	array('target' => 'local', 'local_path' => '/backups/auto_pre_install_x.sql.gz'));
check(($del_built['primitive'] ?? '') === 'delete_backup',
	'a paired node deletes a local backup as a primitive');
check(($del_built['params']['filename'] ?? '') === 'auto_pre_install_x.sql.gz',
	'the filename is the basename of the caller-supplied path');
check(strpos((string)json_encode($del_built), '/backups/') === false,
	'no path crosses to the node');

// ---------------------------------------------------------------------------
section('The bootstrap is one SSH session (specs/ssh_single_bootstrap.md)');

// install_node is the ONE reach the plane makes over SSH in a machine's life.
// It fetches the release and runs install.sh, which installs the host agent,
// creates the site, writes the universal proxy vhost, tries for a certificate
// and arms the host's own retry timer. Nothing after it opens SSH: no verify
// round trip, no proxy step, no cleanup, no user switch.
function jcb_ssh_steps($steps) {
	return array_values(array_filter($steps, function ($s) { return ($s['type'] ?? '') === 'ssh'; }));
}
function jcb_step_types($steps) {
	return array_values(array_unique(array_map(function ($s) { return $s['type'] ?? '?'; }, $steps)));
}

$boot_target = jcb_node(array('mgn_web_root' => '/var/www/html/bootsite/public_html',
	'mgn_site_url' => 'https://boot.example.com'));
$boot_steps = JobCommandBuilder::build_install_node($boot_target, array(
	'mode' => 'fresh', 'sitename' => 'bootsite', 'domain' => 'boot.example.com', 'docker_mode' => 'docker'));
$boot_ssh = jcb_ssh_steps($boot_steps);
check(count($boot_ssh) === 1, 'a fresh docker install is exactly one ssh step', count($boot_ssh) . ' ssh steps');
check(jcb_step_types($boot_steps) === array('local', 'ssh'),
	'the only other step is the local release pre-flight', implode(',', jcb_step_types($boot_steps)));
foreach ($boot_steps as $st) {
	check(empty($st['teardown']), 'the bootstrap has no teardown — there is no later session to clean up in');
}
$boot_cmd = $boot_ssh[0]['cmd'];
check(strpos($boot_cmd, "install.sh -y -q docker --management-node='https://") !== false,
	'the session installs Docker and the host agent, which asks to join this plane', $boot_cmd);
check(strpos($boot_cmd, "--node-name='bootsite-host'") !== false,
	'the host agent is named for the pending list');
check(strpos($boot_cmd, "install.sh -y -q site --docker 'bootsite' - 'boot.example.com' '") !== false,
	'then creates the site container', $boot_cmd);
check(strpos($boot_cmd, "--enable-agent --management-node='https://") !== false,
	'and the site\'s own agent asks to join too');
check(strpos($boot_cmd, '--no-ssl') === false,
	'no --no-ssl: install.sh writes the universal vhost and arms the certificate retry itself');
check(strpos($boot_cmd, 'manage_domain.sh') === false, 'no separate proxy step');
check(strpos($boot_cmd, 'user1') === false && strpos($boot_cmd, 'authorized_keys') === false,
	'no login is prepared for a next session, because there is none');
check(strpos($boot_cmd, 'sudo ') === false, 'nothing is sudo-wrapped: the bootstrap runs as root');
check(strpos($boot_cmd, 'INSTALL_SUCCESS') !== false && strpos($boot_cmd, 'set -eo pipefail') === 0,
	'INSTALL_SUCCESS is printed only past the last line, under set -e');
check(strpos($boot_cmd, 'rm -rf /opt/joinery-install/') !== false
	&& substr_count($boot_cmd, 'rm -rf') === 1,
	'the extracted release is replaced on the way in and never deleted on the way out');
$boot_target->load();
check($boot_target->get('mgn_container_name') === 'bootsite',
	'the container name is the site name this plane chose, recorded at build time');
check((int)$boot_target->get('mgn_port') >= 8080, 'the published port is allocated and recorded');

// A clone pulls from the source over HTTPS with the key the plane armed it with.
$clone_key = JobCommandBuilder::mint_clone_export_key();
$clone_target = jcb_node(array('mgn_web_root' => '/var/www/html/clonesite/public_html',
	'mgn_site_url' => 'https://clone.example.com'));
$clone_steps = JobCommandBuilder::build_install_node($clone_target, array(
	'mode' => 'from_backup', 'sitename' => 'clonesite', 'domain' => 'clone.example.com',
	'docker_mode' => 'docker', 'clone_from' => 'https://source.example.com', 'clone_key' => $clone_key));
$clone_ssh = jcb_ssh_steps($clone_steps);
check(count($clone_ssh) === 1 && !in_array('scp', jcb_step_types($clone_steps), true),
	'a clone is the same single session: no scp, nothing addressed to the source');
$clone_cmd = $clone_ssh[0]['cmd'];
check(strpos($clone_cmd, "--clone-from='https://source.example.com' --clone-key='" . $clone_key . "'") !== false,
	'the site command carries --clone-from and --clone-key', $clone_cmd);
check(strpos($clone_cmd, "'clone.example.com'") !== false,
	'the domain on the command is the NEW site\'s own, not the source\'s');
foreach ($clone_steps as $st) {
	check(empty($st['node_id']), 'no step names another node');
}
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmSecretRedactor.php'));
$shown = SmSecretRedactor::redact($clone_cmd);
check(strpos($shown, $clone_key) === false && strpos($shown, '--clone-key=') !== false,
	'the export key is redacted on display', substr($shown, strpos($shown, '--clone-key='), 40));

foreach (array(
	'no clone_from'   => array('clone_key' => $clone_key),
	'http clone_from' => array('clone_from' => 'http://source.example.com', 'clone_key' => $clone_key),
	'a path'          => array('clone_from' => 'https://source.example.com/x', 'clone_key' => $clone_key),
	'no key'          => array('clone_from' => 'https://source.example.com'),
	'a short key'     => array('clone_from' => 'https://source.example.com', 'clone_key' => 'abc'),
	'a shell key'     => array('clone_from' => 'https://source.example.com', 'clone_key' => $PAYLOAD),
) as $why => $extra) {
	$threw = false;
	try {
		JobCommandBuilder::build_install_node($clone_target, array_merge(array(
			'mode' => 'from_backup', 'sitename' => 'clonesite', 'domain' => 'clone.example.com',
			'docker_mode' => 'docker'), $extra));
	} catch (Exception $e) { $threw = true; }
	check($threw, 'a clone is refused with ' . $why);
}

// A bare instance is the docker half alone: a host agent, no site, and the
// machine's node takes the site name.
$bare_target = jcb_node(array());
$bare_steps = JobCommandBuilder::build_install_node($bare_target, array(
	'mode' => 'bare', 'sitename' => 'relayshard', 'docker_mode' => 'docker'));
$bare_cmd = jcb_ssh_steps($bare_steps)[0]['cmd'];
check(strpos($bare_cmd, "install.sh -y -q docker --management-node=") !== false
	&& strpos($bare_cmd, "--node-name='relayshard'") !== false,
	'a bare instance installs the host agent under the node\'s own name');
check(strpos($bare_cmd, 'install.sh -y -q site') === false, 'and no site');
$bare_target->load();
check((string)$bare_target->get('mgn_container_name') === '', 'a bare node is not given a container name');

// Bare metal: install.sh server (which hardens SSH — this session survives it,
// and there is no next one), then the site whose agent is the machine\'s.
$metal_target = jcb_node(array('mgn_web_root' => '/var/www/html/metalsite/public_html',
	'mgn_site_url' => 'https://metal.example.com'));
$metal_cmd = jcb_ssh_steps(JobCommandBuilder::build_install_node($metal_target, array(
	'mode' => 'fresh', 'sitename' => 'metalsite', 'domain' => 'metal.example.com',
	'docker_mode' => 'bare-metal')))[0]['cmd'];
check(strpos($metal_cmd, './install.sh -y -q server') !== false, 'bare metal runs install.sh server when prerequisites are missing');
check(strpos($metal_cmd, "install.sh -y -q site --bare-metal 'metalsite' --password-file=/root/.joinery_postgres_password 'metal.example.com' --enable-agent") !== false,
	'then the site, with the postgres role password the server setup recorded', $metal_cmd);
check(strpos($metal_cmd, 'install.sh -y -q docker') === false, 'and no Docker');
check(strpos($metal_cmd, 'grep dbpassword') === false,
	'no password is harvested from another site\'s config — a machine this plane creates has none');
check(strpos($metal_cmd, "test -n \"\$POSTGRES_PASSWORD\"") !== false && strpos($metal_cmd, 'export POSTGRES_PASSWORD;') !== false,
	'a failed password generation fails the session rather than being masked by export');

// Refusals: every value that becomes a name on the target is a shape.
foreach (array(
	'a shell sitename'      => array('sitename' => $PAYLOAD, 'domain' => 'x.example.com', 'docker_mode' => 'docker'),
	'an uppercase sitename' => array('sitename' => 'MySite', 'domain' => 'x.example.com', 'docker_mode' => 'docker'),
	'a shell domain'        => array('sitename' => 'site', 'domain' => $SUBSHELL, 'docker_mode' => 'docker'),
	'no domain'             => array('sitename' => 'site', 'domain' => '', 'docker_mode' => 'docker'),
	'an unknown docker_mode'=> array('sitename' => 'site', 'domain' => 'x.example.com', 'docker_mode' => 'kvm'),
	'bare on bare metal'    => array('mode' => 'bare', 'sitename' => 'site', 'docker_mode' => 'bare-metal'),
	'an unknown mode'       => array('mode' => 'restore', 'sitename' => 'site', 'domain' => 'x.example.com', 'docker_mode' => 'docker'),
) as $why => $params) {
	$threw = false;
	try { JobCommandBuilder::build_install_node(jcb_node(array()), array_merge(array('mode' => 'fresh'), $params)); }
	catch (Exception $e) { $threw = true; }
	check($threw, 'install_node refuses ' . $why);
}

// The builders SSH used to own are gone, not stubbed.
foreach (array('build_provision_ssl', 'build_enable_agent', 'build_discover_nodes', 'ssh_prefix',
	'proto_patch_cmd', 'sudo_prefix', 'get_db_credentials_script') as $gone) {
	check(!method_exists('JobCommandBuilder', $gone), $gone . ' no longer exists');
}

// A certificate for a container is issued on its HOST: the builder resolves
// the issuer, and refuses by name when the host has no paired agent.
$cert_host_node = jcb_node(array('mgn_host' => '192.0.2.77',
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0c", 32)),
	'mgn_agent_version'    => '1.16.3',
	'mgn_agent_primitives' => 'check_status,provision_certificate,decommission_site'));
$cert_host = ManagedHost::ensure_for_node($cert_host_node);
harness_register_row('mgh_managed_hosts', 'mgh_id', $cert_host->key);
$cert_container = jcb_node(array('mgn_host' => '192.0.2.77', 'mgn_container_name' => 'certsite',
	'mgn_web_root' => '/var/www/html/certsite/public_html', 'mgn_site_url' => 'https://cert.example.com'));
ManagedHost::ensure_for_node($cert_container);
$cert_threw = '';
try { JobCommandBuilder::certificate_issuer_for($cert_container); }
catch (Exception $e) { $cert_threw = $e->getMessage(); }
check(strpos($cert_threw, 'host') !== false && strpos($cert_threw, 'Pair') !== false,
	'a container on a host with no paired host agent has no issuer, and the refusal says to pair the host', $cert_threw);
$cert_host->set('mgh_mgn_host_node_id', $cert_host_node->key);
$cert_host->save();
$issuer = JobCommandBuilder::certificate_issuer_for($cert_container);
check((int)$issuer->key === (int)$cert_host_node->key, 'once the host agent is linked, the host node is the issuer');
$cert_built = JobCommandBuilder::build_provision_certificate($cert_container, array('domain' => 'Cert.Example.com'));
check(($cert_built['primitive'] ?? '') === 'provision_certificate'
	&& ($cert_built['params']['domain'] ?? '') === 'cert.example.com',
	'the envelope names the site\'s domain, lowercased');
check((int)JobCommandBuilder::certificate_issuer_for($cert_host_node)->key === (int)$cert_host_node->key,
	'a bare-metal node with the primitive is its own issuer');

// The two compiled-names settings writers.
$arm_paired = jcb_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x0d", 32)),
	'mgn_agent_version' => '1.17.0', 'mgn_agent_primitives' => 'clone_export_arm,fleet_enroll'));
$arm_built = JobCommandBuilder::build_clone_export_arm($arm_paired, array('export_key' => $clone_key));
check($arm_built === array('primitive' => 'clone_export_arm', 'params' => array('export_key' => $clone_key)),
	'clone_export_arm carries the key and nothing else', json_encode($arm_built));
check(JobCommandBuilder::build_clone_export_arm($arm_paired, array())['params']['export_key'] === '',
	'an absent key is an explicit disarm');
$threw = false;
try { JobCommandBuilder::build_clone_export_arm($ssh_node, array('export_key' => $clone_key)); }
catch (Exception $e) { $threw = true; }
check($threw, 'an unpaired source cannot be armed — there is no SSH route for it');
$fe_built = JobCommandBuilder::build_fleet_enroll($arm_paired, array(
	'service_url' => 'https://operator.example.com', 'public_key' => 'public_abcdefgh12345678', 'secret_key' => 'secret_abcdefgh12345678'));
check(($fe_built['primitive'] ?? '') === 'fleet_enroll' && count($fe_built['params']) === 3,
	'fleet_enroll carries the three values and nothing else', json_encode($fe_built));
foreach (array(
	array('service_url' => 'http://operator.example.com', 'public_key' => 'public_abcdefgh12345678', 'secret_key' => 'secret_abcdefgh12345678'),
	array('service_url' => 'https://operator.example.com', 'public_key' => 'public_' . $PAYLOAD, 'secret_key' => 'secret_abcdefgh12345678'),
	array('service_url' => 'https://operator.example.com', 'public_key' => 'public_abcdefgh12345678', 'secret_key' => 'nope'),
) as $bad) {
	$threw = false;
	try { JobCommandBuilder::build_fleet_enroll($arm_paired, $bad); } catch (Exception $e) { $threw = true; }
	check($threw, 'fleet_enroll refuses a value outside the platform\'s shapes');
}

// ---------------------------------------------------------------------------
section('Step structure');

// check_status has no SSH implementation at all. A node with no agent, no API
// and nothing to probe cannot be asked, and must say so rather than emit a job.
$threw = '';
try { JobCommandBuilder::build_check_status($ssh_node); }
catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== '', 'check_status refuses a node it has no way to reach');
check(strpos($threw, 'SSH') === false,
	'and does not blame SSH, which is no longer a transport for it', $threw);

// A node that publishes a health document is reached by reading it.
$probe_node = jcb_node(array(
	'mgn_health_check_url'    => 'https://192.0.2.12/health',
	'mgn_skip_joinery_checks' => true,
));
$built = JobCommandBuilder::build_check_status($probe_node);
check(is_array($built) && ($built['probe'] ?? '') === 'check_status',
	'a probe-only node gets a probe envelope, not a step list',
	var_export($built, true));
check(in_array('probe', JobCommandBuilder::transports_for('check_status'), true),
	'check_status advertises the probe transport');
check(!in_array('ssh', JobCommandBuilder::transports_for('check_status'), true),
	'check_status advertises no SSH transport');
check(JobCommandBuilder::can_run($probe_node, 'check_status'),
	'and the action is offered on that node');

// A node with neither a paired agent nor API credentials gets a refusal that
// names both, never an empty job the dispatcher would treat as completed.
$lb_threw = '';
try { JobCommandBuilder::build_list_backups($ssh_node); }
catch (Exception $e) { $lb_threw = $e->getMessage(); }
check(strpos($lb_threw, 'cannot run list_backups') !== false,
	'a node with SSH credentials alone is refused a listing — SSH is not a transport',
	$lb_threw);

// The per-file backup actions are primitive-only: an unpaired node is refused
// at build time with the fix in the message, never handed dead SSH steps.
$db_threw = '';
try { JobCommandBuilder::build_delete_backup($ssh_node, array('local_path' => '/backups/x.sql.gz')); }
catch (Exception $e) { $db_threw = $e->getMessage(); }
check(strpos($db_threw, 'paired agent') !== false,
	'an unpaired node is refused delete_backup and told to pair', $db_threw);

$ub_threw = '';
try { JobCommandBuilder::build_upload_backup($ssh_node, array('filename' => 'x.sql.gz')); }
catch (Exception $e) { $ub_threw = $e->getMessage(); }
check(strpos($ub_threw, 'paired agent') !== false,
	'an unpaired node is refused upload_backup and told to pair', $ub_threw);

section('Plugin installers');

// Primitive only: the node derives its own site root and reads its own DB
// credentials, so the job carries no parameters at all — nothing the plane
// sends can influence what runs.
$installer_paired = jcb_node(array(
	'mgn_web_root'         => '/var/www/html/jeremytunnell/public_html',
	'mgn_agent_public_key' => base64_encode(str_repeat("\x08", 32)),
	'mgn_agent_version'    => '1.13.0'));
$pi_built = JobCommandBuilder::build_run_plugin_installers($installer_paired);
check(($pi_built['primitive'] ?? '') === 'run_plugin_installers',
	'a paired node runs the installers as a primitive');
check(($pi_built['params'] ?? null) === array(),
	'the primitive carries no parameters', json_encode($pi_built));

// An unpaired node is refused at build time — the old SSH invocation is gone.
$threw = false;
try { JobCommandBuilder::build_run_plugin_installers($ssh_node); } catch (Exception $e) { $threw = true; }
check($threw, 'an unpaired node is refused run_plugin_installers at build time');

section('Restore jobs travel to the node, and refuse when they cannot');

// Restore moved to the agent channel on 2026-08-30
// (specs/restore_dispatch_approval_mechanism.md). The SSH steps this section
// used to compose are unreachable — the agent refuses ssh and scp by name — so
// composing them would produce a job that dies at its first step, during a
// restore. The entry point says what is actually wrong instead.
$restore_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/restoresite/public_html',
	'mgn_ssh_user' => 'root'));

$rp_threw = '';
try {
	JobCommandBuilder::build_restore_project($restore_node, array(
		'local_path' => '/backups/restoresite-2026-01-01-000000.tar.gz',
		'domain'     => 'restored.example.com',
	));
} catch (Exception $e) { $rp_threw = $e->getMessage(); }
check(strpos($rp_threw, 'no paired agent') !== false,
	'an unpaired node refuses a project restore, naming why', $rp_threw);
check(strpos($rp_threw, 'rebuild it from a backup') !== false,
	'and says what to do instead — a machine with no agent is rebuilt, not restored in place',
	$rp_threw);

// A paired node gets a primitive envelope, and the envelope carries a NAME.
$paired_restore = jcb_node(array(
	'mgn_web_root'         => '/var/www/html/restoresite/public_html',
	'mgn_agent_public_key' => base64_encode(str_repeat("\x01", 32)),
	'mgn_agent_version'    => '1.13.0'));
$rp_built = JobCommandBuilder::build_restore_project($paired_restore, array(
	'filename' => 'restoresite-2026-01-01-000000.tar.gz',
	'domain'   => 'restored.example.com',
));
check(($rp_built['primitive'] ?? '') === 'restore_project',
	'a paired node gets a primitive envelope');
check(!isset($rp_built['params']['domain']),
	'the domain does not travel — the node keeps its own identity, and a plane that could name '
	. 'one could redirect a restore onto a name of its choosing',
	json_encode($rp_built['params'] ?? null));

// WHAT WAS LOST WITH THE SSH STEPS, recorded rather than quietly dropped.
//
// The SSH job wrapped the restore in plane-side checks: "Verify restore" (the
// web root actually holds a serve.php), "Verify the site agrees with this
// machine", and "Verify the site is served" — the last of which exists because
// an HTTP-only check once passed comfortably while the site was answering on
// :80 under a container virtualhost with a valid certificate sitting unused.
//
// A primitive runs ONE script, so there is nowhere to hang those. The reconcile
// half moved into the scripts (restore_project.sh 1.4.0, restore_chain.sh both
// call reconcile_site.sh), but nothing proves afterwards that the site came
// back up. This is an assurance gap, not a data-safety one, and it needs a
// decision rather than a quiet fix — a probe inside the restore script would
// fail good restores whenever SSL or DNS was not yet settled.
harness_skip('a restore proves the site is served afterwards',
	'the SSH path checked it plane-side and the primitive path has nowhere to; '
	. 'see specs/restore_dispatch_approval_mechanism.md');

section('Chain restore: the fleet backups are restorable at all');

// The manager backup profile writes CHAINS, not standalone archives. Without a
// chain restore job the backups every scheduled run uploads could not be
// restored from the dashboard.
// Paired, because that is the only way a chain restore travels now.
$chain_node = jcb_node(array(
	'mgn_web_root'         => '/var/www/html/chainsite/public_html',
	'mgn_slug'             => 'chainsite',
	'mgn_agent_public_key' => base64_encode(str_repeat("\x02", 32)),
	'mgn_agent_version'    => '1.13.0'));

$chain_threw = '';
try {
	JobCommandBuilder::build_restore_chain($chain_node, array(
		'chain_id' => 'not-a-chain-id', 'domain' => 'chain.example.com'));
} catch (Exception $e) { $chain_threw = $e->getMessage(); }
check(strpos($chain_threw, 'chain id') !== false,
	'a malformed chain id is refused, naming what was expected', $chain_threw);

// The shape of the job itself. A chain restore is now a primitive: the plane
// names the chain and the node resolves everything else inside its own store.
$chain_built = JobCommandBuilder::build_restore_chain($chain_node, array(
	'chain_id' => 'chain-20260807_231507',
	'domain'   => 'chain.example.com'));

check(($chain_built['primitive'] ?? '') === 'restore_chain',
	'a chain restore is a primitive envelope, not a list of steps');
check(($chain_built['params']['chain_id'] ?? '') === 'chain-20260807_231507',
	'it names the chain');
check(($chain_built['params']['project'] ?? '') === 'chainsite',
	'and the project, from this node\'s own recorded web root');

// The properties the six SSH steps used to carry, restated against what
// actually crosses now. Each of these was a thing the plane could say and no
// longer can.
$chain_json = (string)json_encode($chain_built);
check(strpos($chain_json, 'BackupRecoveryKey') === false
	&& strpos($chain_json, 'recovery_private') === false
	&& strpos($chain_json, 'key') === false,
	'no key of any kind travels in the job record', $chain_json);
check(strpos($chain_json, 'chain.example.com') === false,
	'no domain travels — the node keeps its own', $chain_json);
check(!isset($chain_built['params']['profile']),
	'no profile travels — the node resolves the chain inside its own store by id');

// Staging is a SEPARATE, non-destructive job, and it is what fetches the
// manifest and recovers the chain key on the node. A chain restore dispatched
// without it refuses on the node, naming what is missing.
$stage_threw = '';
try {
	JobCommandBuilder::build_stage_chain($chain_node, array(
		'chain_id' => 'not-a-chain-id', 'profile' => 'manager'));
} catch (Exception $e) { $stage_threw = $e->getMessage(); }
check($stage_threw !== '', 'staging refuses a malformed chain id', $stage_threw);

// The target of a restore, for the engine-contract checks below.
$copy_target = jcb_node(array(
	'mgn_web_root' => '/var/www/html/copytarget/public_html'));

section('Restore semantics: replace, verified, loud');

// A restore must leave the database equal to the snapshot. A plain psql pipe
// over a populated schema collides on every CREATE, aborts a whole table's
// COPY on one duplicate key, and still exits 0 — the job reports completed
// over a silent mix of old and new rows (copy_database job #830, 429 errors,
// 31 tables kept their old data). So every restore site must: verify the
// archive before destroying anything, drop and recreate the schema, and run
// psql with ON_ERROR_STOP so a load error fails the job.
// The copy builders that restored INLINE — dropping the schema in the command
// itself — are retired (A3). Every restore that remains delegates to
// restore_database.sh, whose contract is checked just below, so there is no
// longer a command that must carry the replace contract in its own text.

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

// A database restore no longer composes steps at all — it names a primitive, and
// the engine contract below belongs to the SCRIPT rather than to a command this
// plane builds.
$engine_threw = '';
try {
	JobCommandBuilder::build_restore_database($copy_target,
		['local_path' => '/backups/copytarget-2026-01-01-000000.sql.gz']);
} catch (Exception $e) { $engine_threw = $e->getMessage(); }
check(strpos($engine_threw, 'no paired agent') !== false,
	'restore_database: an unpaired node is refused rather than sent dead SSH steps', $engine_threw);

// THE PRE-RESTORE SAFETY DUMP MOVED INTO THE ENGINE, and that is the correction
// this section used to be pinning the wrong side of.
//
// NOTHING IS KEPT BEFORE A RESTORE, decided 2026-08-30. The engine took a
// safety dump for a while, including unattended; that preserved exactly the
// state the operator had decided to discard and left a full copy of the
// database, per restore, indefinitely. The flags that controlled it are gone,
// and the engine REFUSES an unknown option — so a builder still passing one
// composes a job that fails on the node rather than one that quietly ignores an
// argument. That is what makes this a wire-format check and not a preference.
//
// The behaviour itself is proven against a real PostgreSQL in
// restore_roundtrip_gate.sh, which is where a claim about a script belongs.
$engine_src = (string)file_get_contents(
	PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/restore_database.sh');
check(strpos($engine_src, 'PRE_RESTORE_DUMP=true') === false,
	'restore_database.sh keeps nothing before it drops the schema');
$builder_src = (string)file_get_contents(
	PathHelper::getSiteRoot() . '/public_html/plugins/server_manager/includes/JobCommandBuilder.php');
foreach (array('--no-pre-restore-dump', '--pre-restore-dump-dir') as $dead_flag) {
	check(strpos($engine_src, $dead_flag . ')') === false,
		'the engine no longer accepts ' . $dead_flag);
	check(strpos($builder_src, $dead_flag) === false,
		'no builder still passes ' . $dead_flag . ', which the engine would refuse outright');
}

section('Teardown phase: scratch is torn down, deliverables are not');

// Steps flagged 'teardown' run on every exit. Nothing in the SSH surface that
// remains emits one: the bootstrap keeps what it extracts, and the relay
// builders carry their own cleanup inline.

// The bootstrap has no scratch to tear down: the extracted release stays on
// the machine on purpose (the deferred-SSL timer may run its setup_ssl.sh
// until the host agent's bundle lands), and there is no later session to
// delete anything in. So the audit here is that install_node emits NO
// teardown step, rather than that every scratch path has one.
$clone_docker_target = jcb_node(array(
	'mgn_web_root' => '/var/www/html/dockclone/public_html',
	'mgn_site_url' => 'https://dockclone.example.com'));
$install_teardown = 0;
foreach (JobCommandBuilder::build_install_node($clone_docker_target, array(
	'mode' => 'fresh', 'sitename' => 'freshsite', 'domain' => 'fresh.example.com',
	'docker_mode' => 'docker')) as $step) {
	if (!empty($step['teardown'])) { $install_teardown++; }
}
check($install_teardown === 0, 'install_node emits no teardown step');

// Rule 2 (deliverables): the publish-upgrade job exists to place release
// archives in the upgrade repository — nothing about it may be teardown.
$pub_steps = JobCommandBuilder::build_publish_upgrade(array('release_notes' => 'harness test'));
$pub_has_teardown = false;
foreach ($pub_steps as $step) {
	if (!empty($step['teardown'])) { $pub_has_teardown = true; }
}
check(!$pub_has_teardown, 'publish_upgrade emits no teardown step — its archives are the deliverable');

// The step runs on whichever management node builds the release. getjoinery
// publishes as well as dev, so a path from the machine this was written on is a
// job that fails at `cd` on every other site.
$pub_cmd = isset($pub_steps[0]['cmd']) ? $pub_steps[0]['cmd'] : '';
check(strpos($pub_cmd, 'cd ' . escapeshellarg(PathHelper::getRootDir()) . ' ') === 0,
      'publish_upgrade cds to the running site web root, not a hardcoded one');

require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
$bkt = new BackupTarget(NULL);
$bkt->set('bkt_name', 'HarnessTest Target ' . bin2hex(random_bytes(3)));
$bkt->set('bkt_provider', 'b2');
$bkt->set('bkt_bucket', 'harness-test-bucket');
$bkt->set('bkt_credentials', json_encode(array('key_id' => 'k', 'application_key' => 'a')));
$bkt->save();
harness_register_row('bkt_backup_targets', 'bkt_id', $bkt->key);

// The installer directory is per-job, so two installs on one machine never
// extract over each other.
$fresh_a = jcb_cmds(JobCommandBuilder::build_install_node($clone_docker_target, array(
	'mode' => 'fresh', 'sitename' => 'freshsite', 'domain' => 'fresh.example.com',
	'docker_mode' => 'bare-metal')));
check(preg_match('#/opt/joinery-install/[a-f0-9]{12}#', $fresh_a) === 1,
	'the installer directory carries the per-job transfer id');
check(strpos($fresh_a, '/tmp/joinery_install') === false,
	'nothing lands under /tmp, which Ubuntu empties at boot');
$fresh_b = jcb_cmds(JobCommandBuilder::build_install_node($clone_docker_target, array(
	'mode' => 'fresh', 'sitename' => 'freshsite', 'domain' => 'fresh.example.com',
	'docker_mode' => 'bare-metal')));
preg_match('#/opt/joinery-install/[a-f0-9]{12}#', $fresh_a, $ma);
preg_match('#/opt/joinery-install/[a-f0-9]{12}#', $fresh_b, $mb);
check(!empty($ma[0]) && !empty($mb[0]) && $ma[0] !== $mb[0],
	'two installs never share an installer directory', ($ma[0] ?? '?') . ' vs ' . ($mb[0] ?? '?'));

section('Backup key: resolved on the node, never sent from here');

// Restores prefer the envelope beside the archive, so a node can restore itself
// with no operator present, and fall back to the old node key for archives made
// before envelopes existed. That resolution moved ONTO the node with the
// transport: this plane sends no key and no key path, and restore_database.sh
// 3.4 resolves --key-file, then the sidecar beside the archive opened with the
// machine's own backup_site_key, then ~/.joinery_backup_key. A plane that could
// hand a node a key could use the node to open something the node could not
// open by itself, which is why there is no longer a field for one.
$enc_engine = (string)file_get_contents(
	PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/restore_database.sh');
check(strpos($enc_engine, 'keys.json') !== false,
	'the engine resolves the envelope beside the archive itself');
check(strpos($enc_engine, '.joinery_backup_key') !== false,
	'and still falls back to the node key for pre-envelope archives');

$enc_paired = jcb_node(array(
	'mgn_web_root'         => '/var/www/html/encnode/public_html',
	'mgn_agent_public_key' => base64_encode(str_repeat("\x03", 32)),
	'mgn_agent_version'    => '1.13.0'));
$enc_built = JobCommandBuilder::build_restore_database($enc_paired,
	array('filename' => 'site-20260802.sql.gz.enc'));
$enc_json = (string)json_encode($enc_built);
check(strpos($enc_json, 'key') === false && strpos($enc_json, '/backups/') === false,
	'a dispatched restore carries no key and no path — only a name and a profile', $enc_json);

// A restore with nothing to name must fail at build time, not on the node.
$threw = false;
try { JobCommandBuilder::build_restore_database($enc_paired, array('filename' => '')); }
catch (Exception $e) { $threw = true; }
check($threw, 'a restore that names no archive is refused up front');

section('Cloud credentials: placeholder-only (S-8) — no inline fallback exists');

// Job rows persist forever, so credentials must NEVER be inlined into a
// job payload. The agent channel resolves __SM_CREDS_<id>__ in memory when
// the job is handed out; the row at rest carries only the placeholder.
$cloud_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/credmode/public_html',
	'mgn_bkt_backup_target_id' => $bkt->key,
	'mgn_agent_public_key' => base64_encode(str_repeat("\x06", 32)),
	'mgn_agent_version'    => '1.13.0',
	'mgn_delete_local_after_upload' => false));

$ph_built = JobCommandBuilder::build_upload_backup($cloud_node, array('filename' => 'credmode.sql.gz'));
$token = '__SM_CREDS_' . (int)$bkt->key . '__';
check(($ph_built['params']['credentials_b64'] ?? '') === $token,
	'the upload primitive carries the __SM_CREDS_<id>__ token, not a credential');
$ph_json = (string)json_encode($ph_built);
check(strpos($ph_json, "'application_key'") === false && strpos($ph_json, 'secret_key') === false,
	'no credential data appears anywhere in the payload', substr($ph_json, 0, 300));
check(!property_exists('JobCommandBuilder', 'agent_placeholder_support_override'),
	'the inline-credentials fallback (and its heartbeat gate) is gone entirely');

section('Fleet backup run: declared parameters, nothing secret at rest');

// The manager-profile backup job. The node runs its own engine; what the
// builder contributes is the two things the node has no other way to reach —
// the bucket and the credential — as declared parameters the node validates
// field by field. What opens the archive is not among them and never travels
// at all.
$run_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/runnode/public_html',
	'mgn_bkt_backup_target_id' => $bkt->key,
	'mgn_agent_public_key' => base64_encode(str_repeat("\x09", 32)),
	'mgn_agent_version'    => '1.13.0'));

// Refusals: a job that cannot say where the backup goes, or which site to back
// up, fails at build time with a message the operator sees — not part-way
// through a backup on the node.
// Where a backup goes is the management node's decision, not the node's: the
// bucket and the credential travel with the run. So a node that names no target
// is only a problem when the choice is genuinely ambiguous. With several shelves
// enabled, refuse and say so.
$enabled_now = 0;
$enabled_before = new MultiBackupTarget(array('enabled' => true, 'deleted' => false));
$enabled_before->load();
foreach ($enabled_before as $ignored) { $enabled_now++; }

$threw = false;
$refusal = '';
try {
	JobCommandBuilder::build_backup_run(jcb_node(array(
		'mgn_web_root' => '/var/www/html/notarget/public_html',
		'mgn_agent_public_key' => base64_encode(str_repeat("\x0a", 32)),
		'mgn_agent_version'    => '1.13.0')));
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

// The happy path: a primitive envelope carrying the run config as declared
// parameters. Nothing this plane sends is executed as syntax.
$run_built = JobCommandBuilder::build_backup_run($run_node);
check(($run_built['primitive'] ?? '') === 'backup_run',
	'backup_run travels as a primitive');
$run_config = $run_built['params'] ?? null;
check(is_array($run_config), 'the primitive carries the run config as parameters');
check(($run_config['bucket'] ?? '') === 'harness-test-bucket',
	'the config names the target bucket', $run_config['bucket'] ?? '?');
check(($run_config['slug'] ?? '') === (string)$run_node->get('mgn_slug'),
	'the config carries the node slug for the bucket path');
check(($run_config['type'] ?? '') === 'project' && ($run_config['mode'] ?? '') === 'chain',
	'type and mode default to a chained project backup');
check(($run_config['full_interval_days'] ?? 0) === 7,
	'the full interval defaults to a weekly full');

// The credential is the resolve-at-run-time placeholder the agent channel
// swaps in memory — job rows persist forever, so credential DATA must never
// be inlined.
$run_token = '__SM_CREDS_' . (int)$bkt->key . '__';
check(($run_config['credentials_b64'] ?? '') === $run_token,
	'the credential is the __SM_CREDS_<id>__ placeholder, resolved at run time');
$run_json = (string)json_encode($run_built);
check(strpos($run_json, 'application_key') === false,
	'no credential data appears anywhere in the payload');

// No encryption key travels, in any field. Sealing to a public key always
// appears to succeed, so a management node that could supply one could re-seal
// every node's next backup — database and mail — to a key of its choosing, and
// nothing would look wrong until a restore was attempted. The node reads the
// key it holds and has proven; there is nothing here for a tampered plane to
// substitute.
foreach (array('recovery_public_key', 'recovery_fpr', 'recipients') as $forbidden) {
	check(!array_key_exists($forbidden, $run_config),
		"the config carries no {$forbidden} — no key material is supplied to a node");
}
check(strpos($run_json, 'recovery') === false,
	'and the word does not appear anywhere in the payload either', substr($run_json, 0, 300));

// Policy fields reach the config; unrecognised values coerce to the closed set
// rather than travelling to a node as text.
$run_config = JobCommandBuilder::build_backup_run($run_node, array(
	'type' => 'database', 'mode' => 'full', 'full_interval_days' => 3))['params'];
check(($run_config['type'] ?? '') === 'database' && ($run_config['mode'] ?? '') === 'full'
	&& ($run_config['full_interval_days'] ?? 0) === 3,
	'policy type, mode and full interval reach the node config');
$run_config = JobCommandBuilder::build_backup_run($run_node, array(
	'type' => $PAYLOAD, 'mode' => 'evil'))['params'];
check(($run_config['type'] ?? '') === 'project' && ($run_config['mode'] ?? '') === 'chain',
	'unrecognised type and mode coerce to the defaults rather than travelling as text');

// An unpaired node with an otherwise valid config is refused with the fix.
$bru_threw = '';
try {
	JobCommandBuilder::build_backup_run(jcb_node(array(
		'mgn_web_root' => '/var/www/html/unpairedrun/public_html',
		'mgn_bkt_backup_target_id' => $bkt->key)));
} catch (Exception $e) { $bru_threw = $e->getMessage(); }
check(strpos($bru_threw, 'paired agent') !== false,
	'an unpaired node is refused backup_run and told to pair', $bru_threw);

check(in_array('backup_run', ManagementJob::filterTypes(), true),
	'backup_run is a filterable job type, so fleet runs are findable on the jobs pages');

// ── A node with no verified key of its own cannot be backed up ─────────────
// The node refuses these runs itself; that is the guard. Refusing at build time
// as well is what puts the reason in front of an operator, and keeps the fleet
// schedule from filling the job log with runs that were never going to work.
// Never a quiet unencrypted fallback: an unencrypted whole site on somebody
// else's shelf is the outcome the refusal exists to prevent.
$rk_cases = array(
	'a node holding no key'            => array('unconfigured', ''),
	'a node holding an unreadable one' => array('invalid', ''),
	'a node whose key is unverified'   => array('unproven', str_repeat('d4', 32)),
	'a node nobody has checked yet'    => array('', ''),
);
foreach ($rk_cases as $label => $pair) {
	list($rk_state, $rk_fpr) = $pair;
	$rk_node = jcb_node(array(
		'mgn_web_root' => '/var/www/html/nokey/public_html',
		'mgn_bkt_backup_target_id' => $bkt->key,
		'mgn_last_status_data' => ($rk_state === '') ? null : json_encode(array('backup_recovery_state' => $rk_state)),
		'mgn_backup_recovery_fpr' => $rk_fpr));

	$rk_refusal = '';
	try { JobCommandBuilder::build_backup_run($rk_node); }
	catch (Exception $e) { $rk_refusal = $e->getMessage(); }
	check(strpos($rk_refusal, 'cannot be backed up') !== false,
		"{$label} is refused a fleet backup at build time", $rk_refusal);
	check(strpos($rk_refusal, 'management node cannot supply') !== false
		|| strpos($rk_refusal, 'administrator') !== false
		|| strpos($rk_refusal, 'status check') !== false,
		"and the refusal for {$label} says where it is fixed", $rk_refusal);
}

// A target holding a node (write-only) credential hands nodes THAT key's
// token; the main delete-capable credential then never travels to a node.
$bkt_split = new BackupTarget(NULL);
$bkt_split->set('bkt_name', 'HarnessTest Split Target ' . bin2hex(random_bytes(3)));
$bkt_split->set('bkt_provider', 'b2');
$bkt_split->set('bkt_bucket', 'harness-split-bucket');
// region and endpoint are part of a usable credential, not decoration: a
// restore download is SIGNED here rather than sent as a key, and a signature
// needs both. A fixture without them made the builder refuse for a reason that
// had nothing to do with what was being tested.
$bkt_split->set('bkt_credentials', json_encode(array(
	'access_key' => 'MAIN', 'secret_key' => 'main_full_perm',
	'region' => 'us-west-004', 'endpoint' => 'https://s3.us-west-004.example.invalid')));
$bkt_split->set('bkt_node_credentials', json_encode(array(
	'access_key' => 'NODE', 'secret_key' => 'node_write_only',
	'region' => 'us-west-004', 'endpoint' => 'https://s3.us-west-004.example.invalid')));
$bkt_split->save();
harness_register_row('bkt_backup_targets', 'bkt_id', $bkt_split->key);

$split_node = jcb_node(array(
	'mgn_web_root' => '/var/www/html/splitnode/public_html',
	'mgn_bkt_backup_target_id' => $bkt_split->key,
	'mgn_agent_public_key' => base64_encode(str_repeat("\x07", 32)),
	'mgn_agent_version'    => '1.13.0'));

$split_config = JobCommandBuilder::build_backup_run($split_node)['params'];
$node_token = '__SM_NODE_CREDS_' . (int)$bkt_split->key . '__';
$main_token = '__SM_CREDS_' . (int)$bkt_split->key . '__';
check(($split_config['credentials_b64'] ?? '') === $node_token,
	'with a node credential configured, backup_run carries the node token');
check(strpos((string)json_encode($split_config), $main_token) === false,
	'the main (delete-capable) token appears nowhere in the node-bound payload');

// The per-file upload action sends from the node too, so it follows the same
// rule.
$split_upload = JobCommandBuilder::build_upload_backup($split_node, array('filename' => 'splitnode.sql.gz'));
check(($split_upload['params']['credentials_b64'] ?? '') === $node_token,
	'the per-file upload also carries the node token');
check(strpos((string)json_encode($split_upload), $main_token) === false,
	'and never the main token');

// Only an UPLOAD may run under the write-only key. A cloud delete needs delete
// capability, so the delete-capable credential never travels at all: the plane
// deletes cloud objects itself, in-process, and the delete primitive names a
// local file with NO credential parameter through which one could arrive.
$split_del = JobCommandBuilder::build_delete_backup($split_node, array('filename' => 'y.tar.gz'));
$split_del_json = (string)json_encode($split_del);
check(strpos($split_del_json, $main_token) === false && strpos($split_del_json, $node_token) === false
	&& !array_key_exists('credentials_b64', $split_del['params'] ?? array()),
	'a local delete carries no credential token of either kind', $split_del_json);

// A RESTORE DOWNLOAD CARRIES NO TOKEN AT ALL — not the main one either.
//
// This used to hand a node the main, delete-capable credential, on the
// reasoning that the write-only one cannot read and a restore has to. That is
// the right diagnosis and the wrong remedy: the read a restore needs is ONE
// OBJECT, and the way to grant one object is to sign it here, on the machine
// that already holds the key, and send the signature. A signature is not a
// credential — it names one object, the name is inside it, and it expires with
// the job.
$split_paired = jcb_node(array(
	'mgn_web_root'             => '/var/www/html/splitnode/public_html',
	'mgn_slug'                 => 'splitnode',
	'mgn_bkt_backup_target_id' => $bkt_split->key,
	'mgn_agent_public_key'     => base64_encode(str_repeat("\x04", 32)),
	'mgn_agent_version'        => '1.13.0'));
$split_download = JobCommandBuilder::build_download_backup($split_paired, array(
	'filename'   => 'splitnode-20260101.sql.gz.enc',
	'cloud_path' => 'joinery-backups/splitnode/manager/splitnode-20260101.sql.gz.enc'));
$split_dl_json = (string)json_encode($split_download);

check(($split_download['primitive'] ?? '') === 'download_backup',
	'fetching a backup back is its own primitive');
check(strpos($split_dl_json, $main_token) === false && strpos($split_dl_json, $node_token) === false,
	'and carries neither credential token — a node is handed a signature, not a key',
	$split_dl_json);
check(strpos($split_dl_json, 'main_full_perm') === false
	&& strpos($split_dl_json, 'node_write_only') === false,
	'nor either secret key inline');
check(strpos($split_dl_json, 'X-Amz-Signature') !== false
	&& strpos($split_dl_json, 'X-Amz-Expires') !== false,
	'what it does carry is a signed, expiring link to one object', $split_dl_json);

// Without a node credential, everything stays on the main token ($run_node's
// target has none) — already asserted above via credentials_b64 === __SM_CREDS_.

section('Decommission: one destructive primitive to the host agent, or a refusal naming the fix');

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));

/** A placement record linked to its own paired host-agent node. */
function jcb_host_with_agent(array $host_agent_fields = array()) {
	$suffix = bin2hex(random_bytes(3));
	$host_node = jcb_node(array_merge(array(
		'mgn_agent_public_key' => base64_encode(str_repeat("\x0b", 32)),
		'mgn_agent_version'    => '1.15.0',
		'mgn_agent_primitives' => 'check_status,decommission_site',
	), $host_agent_fields));
	$host = new ManagedHost(NULL);
	$host->set('mgh_slug', 'harnesstest-host-' . $suffix);
	$host->set('mgh_name', 'HarnessTest Host ' . $suffix);
	$host->set('mgh_host', '192.0.2.10');
	$host->set('mgh_mgn_host_node_id', $host_node->key);
	$host->prepare();
	$host->save();
	$host->load();
	harness_register_row('mgh_managed_hosts', 'mgh_id', $host->key);
	return array($host, $host_node);
}

/** A container victim placed on a host, at a core that can render the consent panel. */
function jcb_decom_victim($host, array $fields = array()) {
	return jcb_node(array_merge(array(
		'mgn_container_name'  => 'decomsite',
		'mgn_web_root'        => '/var/www/html/decomsite/public_html',
		'mgn_mgh_host_id'     => $host->key,
		'mgn_joinery_version' => JobCommandBuilder::DECOMMISSION_PANEL_MIN_CORE_VERSION,
	), $fields));
}

// The happy path: envelope addressed at the host, ONE parameter, a NAME.
list($decom_host, $decom_host_node) = jcb_host_with_agent();
$decom_docker = jcb_decom_victim($decom_host);
$denv = JobCommandBuilder::build_decommission_node($decom_docker);
check(($denv['primitive'] ?? '') === 'decommission_site',
	'a container victim routes as the decommission_site primitive');
check(($denv['params'] ?? null) === array('site' => 'decomsite'),
	'the envelope carries exactly one parameter: the site NAME', json_encode($denv));
check(strpos((string)json_encode($denv), '/var/www') === false
	&& strpos((string)json_encode($denv), '__SM_CREDS_') === false,
	'no path and no credential crosses to the host');
check((int)JobCommandBuilder::decommission_host_node_for($decom_docker)->key === (int)$decom_host_node->key,
	'the job subject resolves through placement record to the host\'s own node');

// Site name is derived from node fields only — never from operator input.
check(JobCommandBuilder::decommission_site_name($decom_docker) === 'decomsite',
	'docker site name = container name');
$name_bare = jcb_node(array('mgn_web_root' => '/var/www/html/baremetalsite/public_html'));
check(JobCommandBuilder::decommission_site_name($name_bare) === 'baremetalsite',
	'bare-metal site name = web-root parent directory');

// A malformed site name (no safe value derivable) refuses rather than guessing.
$bad = jcb_node(array('mgn_web_root' => ''));
$bad_refused = false;
try { JobCommandBuilder::decommission_site_name($bad); } catch (Exception $e) { $bad_refused = true; }
check($bad_refused, 'an underivable site name refuses instead of building a dangerous parameter');

// A container name outside the agent's wire pattern refuses at build time,
// with the reason — not on the host as a mystery.
$decom_upper = jcb_decom_victim($decom_host, array('mgn_container_name' => 'DecomSite'));
$upper_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_upper); } catch (Exception $e) { $upper_msg = $e->getMessage(); }
check(strpos($upper_msg, 'shape the host agent accepts') !== false,
	'a site name outside the wire pattern refuses naming the shape', $upper_msg);

// A relay is torn down through the relay flow: refused, unchanged.
$relay = jcb_node(array('mgn_is_relay' => true, 'mgn_web_root' => '/var/www/html/relaysite/public_html'));
$relay_refused = false;
try { JobCommandBuilder::build_decommission_node($relay); } catch (Exception $e) { $relay_refused = true; }
check($relay_refused, 'a relay node refuses decommission_node');

// A bare-metal node is a whole machine: decommissioned at the PROVIDER, and
// the refusal says so where the operator acts.
$decom_bare = jcb_node(array('mgn_web_root' => '/var/www/html/baremetalsite/public_html'));
$bare_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_bare); } catch (Exception $e) { $bare_msg = $e->getMessage(); }
check(strpos($bare_msg, 'provider') !== false,
	'a bare-metal victim refuses naming the provider-deletion answer', $bare_msg);

// A victim with no placement record cannot say which host to address.
$decom_unplaced = jcb_node(array(
	'mgn_container_name'  => 'decomsite2',
	'mgn_joinery_version' => JobCommandBuilder::DECOMMISSION_PANEL_MIN_CORE_VERSION));
$unplaced_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_unplaced); } catch (Exception $e) { $unplaced_msg = $e->getMessage(); }
check(strpos($unplaced_msg, 'placement record') !== false,
	'a victim with no placement record refuses naming the fix', $unplaced_msg);

// A host with no agent identity of its own refuses naming the fix: pair it.
$suffix_np = bin2hex(random_bytes(3));
$host_unpaired = new ManagedHost(NULL);
$host_unpaired->set('mgh_slug', 'harnesstest-host-' . $suffix_np);
$host_unpaired->set('mgh_name', 'HarnessTest Unpaired Host ' . $suffix_np);
$host_unpaired->set('mgh_host', '192.0.2.11');
$host_unpaired->prepare();
$host_unpaired->save();
$host_unpaired->load();
harness_register_row('mgh_managed_hosts', 'mgh_id', $host_unpaired->key);
$decom_no_agent = jcb_decom_victim($host_unpaired, array('mgn_container_name' => 'decomsite3'));
$np_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_no_agent); } catch (Exception $e) { $np_msg = $e->getMessage(); }
check(strpos($np_msg, "Pair the host's agent") !== false,
	'an unpaired host refuses naming the fix', $np_msg);

// The old-executor tripwire, plane half: an older host agent is never routed
// at. One that REPORTS a vocabulary without decommission_site refuses on the
// report; one that predates reporting refuses on the 1.15.0 floor — the
// restore floor (1.13.0) must not vouch for a primitive it predates.
list($host_old) = jcb_host_with_agent(array(
	'mgn_agent_version' => '1.13.1', 'mgn_agent_primitives' => 'check_status,restore_database'));
$decom_old = jcb_decom_victim($host_old, array('mgn_container_name' => 'decomsite4'));
$old_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_old); } catch (Exception $e) { $old_msg = $e->getMessage(); }
check(strpos($old_msg, "Update the host's agent") !== false,
	'a host agent that does not report decommission_site refuses loudly', $old_msg);
list($host_mute) = jcb_host_with_agent(array(
	'mgn_agent_version' => '1.10.0', 'mgn_agent_primitives' => ''));
$decom_mute = jcb_decom_victim($host_mute, array('mgn_container_name' => 'decomsite5'));
$mute_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_mute); } catch (Exception $e) { $mute_msg = $e->getMessage(); }
check(strpos($mute_msg, "Update the host's agent") !== false,
	'a pre-report host agent refuses on the 1.15.0 floor, not the restore floor', $mute_msg);

// A victim below the release carrying the approval panel cannot render the
// consent it would be asked for: refused, with the upgrade in the message.
$decom_old_core = jcb_decom_victim($decom_host, array(
	'mgn_container_name' => 'decomsite6', 'mgn_joinery_version' => '0.8.350'));
$core_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_old_core); } catch (Exception $e) { $core_msg = $e->getMessage(); }
check(strpos($core_msg, 'consent') !== false
	&& strpos($core_msg, JobCommandBuilder::DECOMMISSION_PANEL_MIN_CORE_VERSION) !== false,
	'a victim below the panel-carrying core refuses naming the upgrade', $core_msg);

// A victim mid-work is not demolished: open jobs refuse the dispatch.
$decom_busy = jcb_decom_victim($decom_host, array('mgn_container_name' => 'decomsite7'));
$busy_job = ManagementJob::createJob($decom_busy->key, 'check_status',
	array(array('type' => 'api', 'label' => 'Busy fixture', 'method' => 'GET', 'endpoint' => 'status', 'timeout' => 30)),
	array(), 1);
harness_register_row('mjb_management_jobs', 'mjb_id', $busy_job->key);
$busy_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_busy); } catch (Exception $e) { $busy_msg = $e->getMessage(); }
check(strpos($busy_msg, 'pending or running job') !== false,
	'a victim with open jobs refuses until they are finished or cancelled', $busy_msg);

// Dispatch bookkeeping survives the real filing path: the job record carries
// the CALLER's victim_node_id beside the envelope's site — createFromBuild
// once discarded caller params for primitive envelopes, which made a verified
// teardown finalize the HOST's record.
list($host_b, $host_b_node) = jcb_host_with_agent();
$decom_v1 = jcb_decom_victim($host_b, array('mgn_container_name' => 'decomsite8'));
$denv2 = JobCommandBuilder::build_decommission_node($decom_v1);
$djob = ManagementJob::createFromBuild($host_b_node->key, 'decommission_node', $denv2,
	array('victim_node_id' => (int)$decom_v1->key, 'site' => 'decomsite8'), 1);
harness_register_row('mjb_management_jobs', 'mjb_id', $djob->key);
$recorded = json_decode((string)$djob->get('mjb_parameters'), true);
check((int)($recorded['victim_node_id'] ?? 0) === (int)$decom_v1->key
	&& ($recorded['site'] ?? '') === 'decomsite8',
	'the filed job records victim_node_id beside the envelope params', json_encode($recorded));

// An in-flight decommission is filed against the HOST, invisible to the
// victim's own queue — a second dispatch on that host refuses.
$decom_v2 = jcb_decom_victim($host_b, array('mgn_container_name' => 'decomsite9'));
$inflight_msg = '';
try { JobCommandBuilder::build_decommission_node($decom_v2); } catch (Exception $e) { $inflight_msg = $e->getMessage(); }
check(strpos($inflight_msg, 'already has a site removal') !== false,
	'a host with a removal pending refuses a second dispatch', $inflight_msg);

// A destructive operation with no declared version floor fails closed rather
// than inheriting the restore family's.
check(JobCommandBuilder::node_can_dispatch_destructive($decom_host_node, 'no_such_destructive_op') === false,
	'an undeclared destructive floor refuses instead of inheriting');

section('Placement records: the FK survives what the host-row lifecycle does');

// A port reserved under a soft-deleted host row stays reserved on the machine:
// the allocator unions siblings across every host row sharing the address,
// deleted rows included (P-18 across a delete-and-re-mint).
$suffix_pa = bin2hex(random_bytes(3));
$host_old_row = new ManagedHost(NULL);
$host_old_row->set('mgh_slug', 'harnesstest-portpool-' . $suffix_pa);
$host_old_row->set('mgh_name', 'HarnessTest PortPool ' . $suffix_pa);
$host_old_row->set('mgh_host', '192.0.2.99');
$host_old_row->prepare();
$host_old_row->save();
$host_old_row->load();
harness_register_row('mgh_managed_hosts', 'mgh_id', $host_old_row->key);
$ported = jcb_node(array('mgn_host' => '192.0.2.99', 'mgn_mgh_host_id' => $host_old_row->key,
	'mgn_container_name' => 'portpoolsite', 'mgn_port' => 9055));
$host_old_row->soft_delete();
$reminted = jcb_node(array('mgn_host' => '192.0.2.99', 'mgn_container_name' => 'portpoolsite2'));
$new_host = ManagedHost::ensure_for_node($reminted);
harness_register_row('mgh_managed_hosts', 'mgh_id', $new_host->key);
check((int)$new_host->key !== (int)$host_old_row->key,
	'a deleted host row is re-minted, not resurrected');
check(JobCommandBuilder::next_container_port($new_host->key, (int)$reminted->key) === 9056,
	'a port reserved under the deleted row is still reserved on the machine',
	(string)JobCommandBuilder::next_container_port($new_host->key, (int)$reminted->key));

// Duplicate host rows for one address: every caller converges on the oldest.
$dup_row = new ManagedHost(NULL);
$dup_row->set('mgh_slug', 'harnesstest-dup-' . $suffix_pa);
$dup_row->set('mgh_name', 'HarnessTest Dup ' . $suffix_pa);
$dup_row->set('mgh_host', '192.0.2.99');
$dup_row->prepare();
$dup_row->save();
$dup_row->load();
harness_register_row('mgh_managed_hosts', 'mgh_id', $dup_row->key);
$converge = jcb_node(array('mgn_host' => '192.0.2.99'));
$picked = ManagedHost::ensure_for_node($converge);
check((int)$picked->key === (int)min((int)$new_host->key, (int)$dup_row->key),
	'ensure_for_node picks the oldest live row for an address, deterministically');

// A long hostname mints a row instead of overflowing mgh_slug (varchar 50).
$long_node = jcb_node(array('mgn_host' => str_repeat('very-long-hostname.', 5) . 'example.com'));
$long_host = ManagedHost::ensure_for_node($long_node);
harness_register_row('mgh_managed_hosts', 'mgh_id', $long_host->key);
check(strlen($long_host->get('mgh_slug')) <= 50,
	'a minted slug fits the column whatever the address length', $long_host->get('mgh_slug'));

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
