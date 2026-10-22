<?php
/**
 * AgentLiveProofRun - agent words run once on a real machine before release,
 * with an agent built for the run (specs/fleet_security_patching.md WP7; Q8,
 * decided 2026-10-22: a throwaway key).
 *
 * The live-tier test agent_live_proofs_live drives it:
 *
 *   build()        builds the agent from the source being prepared, and the
 *                  support bundle from this tree, in a scratch site whose
 *                  only release key is one made for the run. The release's
 *                  own build (AgentDistPublisher, SupportBundlePublisher)
 *                  does it: the binary differs from a release's only in the
 *                  key baked into it. Nothing the fleet trusts signs anything,
 *                  and the key is deleted with the scratch site.
 *   make_server()  a throwaway server in the test cloud account
 *                  (test_server.php create, behind its safety catch).
 *   install()      over SSH with the server's root password: the agent
 *                  (install_agent.sh --siteless), the bundle, and a join to
 *                  this management node.
 *   adopt()        approves that join here, as the fingerprint the machine
 *                  printed, only from the server's own address and under the
 *                  run's label, and marks the node a test build: this plane
 *                  offers it none of its own agents or bundles, which it
 *                  would refuse (AgentChannelEndpoint), and the test cloud's
 *                  cleanup removes it with its server if the run dies.
 *   prove()        queues each word as a job, waits for it, and judges it.
 *   teardown()     removes the node and the run's join requests, deletes the
 *                  server and its DNS, and deletes the scratch site with the
 *                  key in it.
 *
 * A word is proven here only on a bare Ubuntu machine joined as a machine.
 * RECIPES names the words that can be, with what each is asked; a changed
 * word not there (one that runs on a site, or on the publishing box) is
 * proven by hand with record_live_proof.php.
 *
 * @version 1.1 - reviewer2: adopt() takes the join only from the server's own address (the address this plane saw it
 *                come from, never what the machine reports) and under the run's label (the
 *                SSH session that read the fingerprint checks no host identity); build() starts from no scratch
 *                site, so a killed run's key is never reused; teardown() removes the run's join requests; a dead
 *                run's root password file is removed by the next run
 * @version 1.0
 */
class AgentLiveProofRun {

	/**
	 * The words a bare machine can prove: the parameters each is asked with,
	 * and how long its job may take from queueing.
	 */
	const RECIPES = array(
		'check_status'             => array('params' => array(), 'wait' => 300),
		'agent_report'             => array('params' => array(), 'wait' => 300),
		'host_report'              => array('params' => array(), 'wait' => 600),
		'install_security_updates' => array('params' => array(), 'wait' => 1200),
	);

	/** The label every run's server carries, then six hex digits. */
	const LABEL_PREFIX = 'wp7-proof-';

	/** A run's root password file older than this belongs to a run the runner has killed (its cap is 30 minutes). */
	const DEAD_RUN_SECONDS = 7200;

	/** The tree whose scripts the bundle carries, and whose install_agent.sh sets the machine up. */
	private static function site_root(): string {
		return dirname(PathHelper::getRootDir());
	}

	/**
	 * Build the agent and the support bundle under $work, signed with a key
	 * made for the run.
	 *
	 * @return array{site: string, dist: string, version: string, commit: string, dirty: bool, key: string}
	 */
	public static function build(string $work, callable $say): array {
		$site = rtrim($work, '/') . '/site';
		// A killed run leaves its scratch site, key and all; this run's key is
		// made for it, never inherited.
		if (is_dir($site)) {
			exec('rm -rf ' . escapeshellarg($site), $o, $rc);
			if ($rc !== 0 || is_dir($site)) {
				throw new Exception("cannot remove an earlier run's scratch site {$site}");
			}
		}
		foreach (array('/config', '/public_html/agent_dist', '/maintenance_scripts/install_tools/release_keys/release') as $d) {
			if (!is_dir($site . $d) && !mkdir($site . $d, 0700, true)) {
				throw new Exception("cannot make {$site}{$d}");
			}
		}
		$keys = AgentDistPublisher::ensureKeys($site . '/config');
		file_put_contents($site . '/maintenance_scripts/install_tools/release_keys/release/live-proof.pub', $keys['public_b64'] . "\n");

		$src = AgentDistPublisher::sourcePath();
		$commit = (string)ReleaseCommit::head($src);
		$status = ReleaseCommit::git($src, array('status', '--porcelain', '--untracked-files=no'));
		$dirty = $status['exit'] !== 0 || array_filter($status['out'], 'strlen') !== array();

		$agent = AgentDistPublisher::publish($site, $say, array('dir' => $src, 'commit' => $commit));
		if ($agent['status'] !== AgentDistPublisher::STATUS_BUILT) {
			throw new Exception('the agent was not built: ' . $agent['message']);
		}
		$bundle = SupportBundlePublisher::publish($site, $say, self::site_root());
		if (!in_array($bundle['status'] ?? '', array('built', 'skipped'), true)) {
			throw new Exception('the support bundle was not built: ' . ($bundle['message'] ?? 'no answer'));
		}
		return array('site' => $site, 'dist' => $site . '/public_html/agent_dist', 'version' => (string)$agent['source_version'],
			'commit' => $commit, 'dirty' => $dirty, 'key' => substr(hash('sha256', $keys['public']), 0, 16));
	}

	/**
	 * A throwaway server in the test cloud account: {id, label, ipv4, ipv6,
	 * password_file}. test_server.php refuses any account but the test one.
	 */
	public static function make_server(string $label): array {
		$r = self::run_local(array(PHP_BINARY, self::site_root() . '/maintenance_scripts/dev_tools/test_server.php', 'create', $label), 300);
		$json = null;
		foreach (array_reverse(explode("\n", $r['out'])) as $line) {
			$j = json_decode(trim($line), true);
			if (is_array($j) && isset($j['id'], $j['ipv4'], $j['password_file'])) { $json = $j; break; }
		}
		if ($r['exit'] !== 0 || $json === null) {
			$why = preg_match('/^(?:test_server|ERROR): (.+)$/m', $r['out'], $m) ? $m[1] : trim(substr($r['out'], -500));
			throw new Exception('the test server was not made: ' . $why);
		}
		$json['ipv4'] = is_array($json['ipv4']) ? (string)$json['ipv4'][0] : (string)$json['ipv4'];
		return $json;
	}

	/**
	 * The agent, the bundle and a join to this management node, on the
	 * server. Returns the key fingerprint the machine printed.
	 */
	public static function install(array $server, array $build, callable $say): string {
		$up = false;
		for ($i = 0; $i < 40 && !$up; $i++) {
			$up = self::ssh($server, 'true', 20)['exit'] === 0;
			if (!$up) { sleep(10); }
		}
		if (!$up) {
			throw new Exception('the server did not answer SSH in seven minutes');
		}
		$say('the server answers SSH');
		self::must(self::ssh($server, 'mkdir -p /root/live-proof/dist'), 'make /root/live-proof');
		$files = array();
		foreach (scandir($build['dist']) as $f) {
			if ($f[0] !== '.' && is_file($build['dist'] . '/' . $f)) { $files[] = $build['dist'] . '/' . $f; }
		}
		self::must(self::scp($server, $files, '/root/live-proof/dist/'), 'copy the agent and the bundle');
		self::must(self::scp($server, array(self::site_root() . '/maintenance_scripts/install_tools/install_agent.sh'), '/root/live-proof/'),
			'copy install_agent.sh');
		self::must(self::ssh($server, 'bash /root/live-proof/install_agent.sh --siteless --dist-dir=/root/live-proof/dist --enable', 600),
			'install the agent');
		$say('the agent is installed');
		self::must(self::ssh($server, 'joinery-agent bundle install /root/live-proof/dist/' . SupportBundlePublisher::BUNDLE_NAME, 300),
			'install the support bundle');
		$say('the support bundle is installed');
		$plane = rtrim(LibraryFunctions::get_absolute_url('/'), '/');
		$join = self::must(self::ssh($server, 'joinery-agent join --management-node=' . escapeshellarg($plane)
			. ' --name=' . escapeshellarg((string)$server['label']) . ' --no-wait', 120), 'ask to join');
		if (!preg_match('/Key fingerprint:\s*([0-9a-fA-F][0-9a-fA-F ]{14,})/', $join, $m)) {
			throw new Exception('the join printed no fingerprint: ' . trim(substr($join, -300)));
		}
		return strtolower(preg_replace('/[^0-9a-fA-F]/', '', $m[1]));
	}

	/**
	 * Approve the machine's join, as the fingerprint it printed, and mark its
	 * node a test build. Waits up to a minute for the request to arrive.
	 *
	 * The fingerprint was read over an SSH session that checks no host
	 * identity, so the request must also have come from the server's own
	 * address: its IPv4 or its IPv6 (a test server reaches this plane over
	 * either, with no NAT between). That is ajr_source_ip, the address this
	 * plane saw the request arrive from, and only that: the addresses a
	 * machine reports about itself, and the name it claims, are its own word,
	 * and the label crossed the same unchecked session. The claimed name must
	 * also be the label, a second and weaker gate.
	 */
	public static function adopt(string $fingerprint, array $server): ManagedNode {
		$request = null;
		for ($i = 0; $i < 12 && !$request; $i++) {
			foreach (AgentJoinRequest::pending() as $r) {
				if (hash_equals(strtolower((string)$r->get('ajr_fingerprint')), $fingerprint)) { $request = $r; break; }
			}
			if (!$request) { sleep(5); }
		}
		if (!$request) {
			throw new Exception('no pending join with fingerprint ' . $fingerprint . ' arrived');
		}
		$ours = array();
		foreach (array($server['ipv4'] ?? '', $server['ipv6'] ?? '') as $a) {
			$b = @inet_pton(strtok((string)$a, '/'));
			if ($b !== false) { $ours[$b] = true; }
		}
		$source = trim((string)$request->get('ajr_source_ip'));
		$b = $source !== '' ? @inet_pton($source) : false;
		if ($b === false || !isset($ours[$b]) || (string)$request->get('ajr_claimed_name') !== (string)$server['label']) {
			throw new Exception('the join with fingerprint ' . $fingerprint . ' came from ' . ($source !== '' ? $source : 'an unknown address')
				. ' as "' . $request->get('ajr_claimed_name') . '", not from the server ' . $server['label'] . ' at its own address; not adopted');
		}
		$node = AgentChannelEndpoint::adoptJoin($request)['node'];
		$node->set('mgn_agent_test_build', true);
		$node->save();
		$node->load();
		return $node;
	}

	/** Wait for the node's agent to report itself at the version built. */
	public static function await_agent(ManagedNode $node, string $version, int $seconds = 300): void {
		$until = time() + $seconds;
		do {
			$node->load();
			if ((string)$node->get('mgn_agent_version') === $version && trim((string)$node->get('mgn_agent_primitives')) !== '') {
				return;
			}
			sleep(5);
		} while (time() < $until);
		throw new Exception('the agent did not report itself at ' . $version . ' within ' . $seconds . ' s (it reports '
			. ((string)$node->get('mgn_agent_version') ?: 'nothing') . ')');
	}

	/**
	 * Run one word through the node's agent and judge it.
	 *
	 * @return array{ok: bool, job_id: int, what: string}
	 */
	public static function prove(ManagedNode $node, string $word): array {
		if (!isset(self::RECIPES[$word])) {
			return array('ok' => false, 'job_id' => 0, 'what' => 'no recipe: a bare machine cannot prove it');
		}
		$node->load();
		if (!JobCommandBuilder::has_primitive($node, $word)) {
			return array('ok' => false, 'job_id' => 0, 'what' => 'the agent built for the run does not report the word');
		}
		$recipe = self::RECIPES[$word];
		$job = ManagementJob::createFromBuild($node->key, $word, array('primitive' => $word, 'params' => $recipe['params']), null, null);
		$until = time() + $recipe['wait'];
		do {
			sleep(5);
			$job->load();
		} while (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true) && time() < $until);
		if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
			return array('ok' => false, 'job_id' => (int)$job->key, 'what' => 'its job did not finish in ' . $recipe['wait'] . ' s (status ' . $job->get('mjb_status') . ')');
		}
		JobResultProcessor::process_if_due($job);
		$job->load();
		return array('job_id' => (int)$job->key) + self::judge($word, $job);
	}

	/**
	 * What a finished job says of its word: completed with an answer to read,
	 * and for install_security_updates an outcome that installed or found
	 * nothing to install.
	 *
	 * @return array{ok: bool, what: string}
	 */
	public static function judge(string $word, ManagementJob $job): array {
		$status = (string)$job->get('mjb_status');
		if ($status !== 'completed') {
			return array('ok' => false, 'what' => 'its job ' . $status . ((string)$job->get('mjb_error_message') !== '' ? ': ' . $job->get('mjb_error_message') : ''));
		}
		if ($word === 'install_security_updates') {
			$v = FleetSecurityRunner::verdict($job);
			return array('ok' => $v['verdict'] === 'done', 'what' => 'outcome ' . ($v['fields']['outcome'] !== '' ? $v['fields']['outcome'] : 'none')
				. (is_int($v['fields']['changed']) ? ', ' . $v['fields']['changed'] . ' packages changed' : '') . ($v['reason'] !== '' ? '; ' . $v['reason'] : ''));
		}
		$data = null;
		if (preg_match('/\{"api_version".*\}\s*$/s', (string)$job->get('mjb_output'), $m)) {
			$d = json_decode($m[0], true);
			$data = is_array($d) ? ($d['data'] ?? null) : null;
		}
		if (!is_array($data) || $data === array()) {
			return array('ok' => false, 'what' => 'its job completed with no answer to read');
		}
		return array('ok' => true, 'what' => 'answered with ' . implode(', ', array_slice(array_keys($data), 0, 12)));
	}

	/**
	 * Remove the node, delete the server and its DNS, and delete the scratch
	 * site with the run's key. Each part is tried whatever the others did;
	 * returns what could not be done.
	 */
	public static function teardown(?ManagedNode $node, ?array $server, ?string $work): array {
		$left = array();
		// The run's join requests: approved (its node is going) or still
		// pending (a run that stopped before adopting). Nobody else would.
		if ($server && (string)($server['label'] ?? '') !== '') {
			try {
				foreach (new MultiAgentJoinRequest(array('ajr_claimed_name' => (string)$server['label'])) as $r) {
					$r->permanent_delete();
				}
			} catch (Exception $e) {
				$left[] = 'the join requests named ' . $server['label'] . ': ' . $e->getMessage();
			}
		}
		if ($node && $node->key) {
			try {
				$node->load();
				$node->remove_permanently();
			} catch (Exception $e) {
				$left[] = 'node #' . $node->key . ': ' . $e->getMessage();
			}
		}
		if ($server) {
			$r = self::run_local(array(PHP_BINARY, self::site_root() . '/maintenance_scripts/dev_tools/test_server.php', 'delete', (string)$server['id']), 300);
			if ($r['exit'] !== 0) {
				$left[] = 'server ' . $server['label'] . ' (' . $server['id'] . '): ' . trim(substr($r['out'], -300));
			}
			if (!empty($server['password_file']) && is_file($server['password_file'])) {
				@unlink($server['password_file']);
			}
		}
		if ($work !== null && is_dir($work)) {
			exec('rm -rf ' . escapeshellarg($work), $o, $rc);
			if ($rc !== 0) { $left[] = 'the scratch site ' . $work . ' (it holds the run\'s key)'; }
		}
		return $left;
	}

	/**
	 * Remove the root password files of runs the runner killed before their
	 * teardown: test_server.php leaves them in /dev/shm, which only a reboot
	 * clears. The servers themselves go with the test cloud's nightly cleanup.
	 */
	public static function forget_dead_runs(): int {
		$n = 0;
		foreach (glob('/dev/shm/test_server_' . self::LABEL_PREFIX . '*') ?: array() as $f) {
			if (is_file($f) && time() - (int)filemtime($f) > self::DEAD_RUN_SECONDS && @unlink($f)) { $n++; }
		}
		return $n;
	}

	private static function ssh_base(array $server): array {
		return array('sshpass', '-f', (string)$server['password_file']);
	}

	private static function ssh_opts(): array {
		return array('-o', 'StrictHostKeyChecking=no', '-o', 'UserKnownHostsFile=/dev/null', '-o', 'LogLevel=ERROR', '-o', 'ConnectTimeout=15');
	}

	/** Run a command on the server as root. */
	private static function ssh(array $server, string $command, int $timeout = 120): array {
		return self::run_local(array_merge(self::ssh_base($server), array('ssh'), self::ssh_opts(),
			array('root@' . $server['ipv4'], $command)), $timeout);
	}

	/** Copy local files to a directory on the server. */
	private static function scp(array $server, array $files, string $to): array {
		return self::run_local(array_merge(self::ssh_base($server), array('scp', '-q'), self::ssh_opts(), $files,
			array('root@' . $server['ipv4'] . ':' . $to)), 300);
	}

	/** The output of a step that must succeed, or an exception naming it. */
	private static function must(array $r, string $what): string {
		if ($r['exit'] !== 0) {
			throw new Exception($what . ' failed (exit ' . $r['exit'] . '): ' . trim(substr($r['out'], -600)));
		}
		return $r['out'];
	}

	/** Run a local command (an argument list, never a shell string), bounded: {exit, out}. */
	private static function run_local(array $argv, int $timeout): array {
		$cmd = 'timeout ' . (int)$timeout . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
		exec($cmd, $out, $rc);
		return array('exit' => $rc, 'out' => implode("\n", $out));
	}
}
?>
