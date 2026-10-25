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
 * A word is proven on a bare Ubuntu machine joined as a machine (RECIPES),
 * or on a site (SITE_RECIPES, the live test agent_live_proofs_site_live):
 *
 *   build(..., true)  the agent also trusts the real release keys: the site
 *                     starts at a published release they signed, and moves
 *                     to a candidate the run's key signed.
 *   build_candidate() the core archive a publish of this tree would ship
 *                     (its staging rules, its manifest, its member list),
 *                     signed with the run's key, carrying the run's agent and
 *                     no release statement, at the next version.
 *   install_site()    the newest published release, installed as the
 *                     provisioning flow installs a bare-metal site, its agent
 *                     asking to join here.
 *   swap_agent()      the run's agent in place of the release's, pinned to its
 *                     own dist so it never updates itself back.
 *   stage_candidate() the candidate served from the server itself, the run's
 *                     key added to the site's trusted keys and the log
 *                     requirement lifted, on the test site alone.
 *   prove_site()      upgrade_preflight, then apply_update (judged as a
 *                     staged rollout judges it), then upgrade_preflight on the
 *                     upgraded site.
 *
 * A changed word in neither list (publish_upgrade, which only the publishing
 * box runs) is proven by hand with record_live_proof.php.
 *
 * @version 1.5 - wait_ssh(), ssh(), scp(), must() and run_local() are public: the site copy live test
 *                (SiteCopyLiveRun) reaches its servers with them too
 * @version 1.4 - install.sh server --keep-root-login: the run logs in with the root password after the install
 * @version 1.3 - net_health and web_workers are words a bare machine proves; site_log is proven on the upgraded site
 * @version 1.2 - a site run: the newest published release installed, upgraded through apply_update onto a candidate
 *                built from this tree, with upgrade_preflight before and after (Q9)
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
		'net_health'               => array('params' => array(), 'wait' => 300),
		'web_workers'              => array('params' => array(), 'wait' => 300),
	);

	/**
	 * The words a site proves, in the order they run: how long each job may
	 * take from queueing. upgrade_preflight runs before and after the
	 * upgrade; apply_update is judged as a staged rollout judges it;
	 * site_log reads the upgraded site's access log.
	 */
	const SITE_RECIPES = array(
		'upgrade_preflight' => array('wait' => 300),
		'apply_update'      => array('wait' => 1200),
		'site_log'          => array('wait' => 300),
	);

	/** A site run's server: a site and its database want more than the smallest. */
	const SITE_SERVER_TYPE = 'g6-standard-1';

	/** Where the candidate is served from on the site's own server, to itself only. */
	const CANDIDATE_PORT = 8099;

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
	public static function build(string $work, callable $say, bool $also_release_keys = false): array {
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
		// A site run starts at a published release: its agent must also trust
		// the keys that signed it. Their public halves only; nothing the fleet
		// trusts signs anything here.
		if ($also_release_keys) {
			foreach (glob(self::site_root() . '/maintenance_scripts/install_tools/release_keys/release/*.pub') ?: array() as $pub) {
				copy($pub, $site . '/maintenance_scripts/install_tools/release_keys/release/' . basename($pub));
			}
		}

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
			'commit' => $commit, 'dirty' => $dirty, 'key' => substr(hash('sha256', $keys['public']), 0, 16),
			'key_b64' => $keys['public_b64']);
	}

	/** The newest release this plane has published: the highest joinery-core-X.Y.Z archive it serves, or null. */
	public static function newest_published(): ?string {
		$best = null;
		foreach (glob(self::site_root() . '/static_files/joinery-core-*.tar.gz') ?: array() as $f) {
			if (preg_match('/joinery-core-(\d+\.\d+\.\d+)\.tar\.gz$/', $f, $m) && ($best === null || version_compare($m[1], $best, '>'))) {
				$best = $m[1];
			}
		}
		return $best;
	}

	/** The version after one: its last number raised. */
	public static function next_version(string $version): string {
		$p = explode('.', $version);
		$p[count($p) - 1] = (string)((int)$p[count($p) - 1] + 1);
		return implode('.', $p);
	}

	/**
	 * What publish leaves out of public_html when it stages the core:
	 * publish_upgrade.php's own list (the safe test agent_live_proofs pins the
	 * two together), and the agent files the admin names.
	 */
	const CORE_EXCLUDES = array('.git', '.gitignore', '.claude', 'specs', 'uploads', 'cache', 'logs', 'backups',
		'.playwright-mcp', 'theme-sources', '/agent_dist.*', '/theme/*', '/plugins/*');

	private static function agent_file_excludes(): array {
		$names = array('CLAUDE.md', 'GEMINI.md', 'AGENTS.md');
		try {
			$q = DbConnector::get_instance()->get_db_link()->query("SELECT agf_target_filenames FROM agf_agent_files WHERE agf_delete_time IS NULL");
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $raw) {
				$targets = is_array($raw) ? $raw : json_decode((string)$raw, true);
				foreach (is_array($targets) ? $targets : array() as $n) {
					if (is_string($n) && $n !== '' && strpos($n, '/') === false && strpos($n, '\\') === false) { $names[] = $n; }
				}
			}
		} catch (\Throwable $e) {
			// The fallback names still apply.
		}
		return array_values(array_unique($names));
	}

	/**
	 * The core archive a publish of this tree would ship, as the candidate a
	 * site upgrades onto: publish's staging (public_html without the excluded
	 * paths, empty theme/ and plugins/, the licenses, the config template,
	 * install_tools and sysadmin_tools), $version in the staged VERSION, the
	 * run's agent in agent_dist, and no release statement (the run's key is in
	 * no log). The manifest lists what ships (git knows it, or publish builds
	 * it), signed with the run's key; the archive holds exactly the listed
	 * members, as publish's does. Checked against the run's key before it is
	 * returned. Nothing outside $build's scratch site is written.
	 *
	 * @return string the archive's path
	 */
	public static function build_candidate(array $build, string $version, callable $say): string {
		$root = self::site_root();
		$base = dirname($build['site']) . '/candidate';
		exec('rm -rf ' . escapeshellarg($base));
		$stage = $base . '/stage';
		foreach (array('/public_html', '/config', '/maintenance_scripts', '/out') as $d) {
			$dir = ($d === '/out' ? $base : $stage) . $d;
			if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new Exception("cannot make {$dir}"); }
		}
		$excludes = '';
		foreach (array_merge(self::CORE_EXCLUDES, self::agent_file_excludes()) as $x) {
			$excludes .= ' --exclude=' . escapeshellarg($x);
		}
		self::must_local('rsync -a' . $excludes . ' ' . escapeshellarg($root . '/public_html/') . ' ' . escapeshellarg($stage . '/public_html/'), 'stage public_html');
		foreach (array('theme', 'plugins') as $d) {
			if (!is_dir($stage . '/public_html/' . $d)) { mkdir($stage . '/public_html/' . $d, 0755, true); }
		}
		$license = is_file($root . '/LICENSE.md') ? $root . '/LICENSE.md' : $root . '/public_html/LICENSE.md';
		if (!is_file($license) || !copy($license, $stage . '/public_html/LICENSE.md')) { throw new Exception('LICENSE.md could not be staged'); }
		if (is_file(dirname($license) . '/LICENSE-BUSINESS.md')) { copy(dirname($license) . '/LICENSE-BUSINESS.md', $stage . '/public_html/LICENSE-BUSINESS.md'); }
		copy($root . '/maintenance_scripts/install_tools/default_Globalvars_site.php', $stage . '/config/default_Globalvars_site.php');
		foreach (array('install_tools', 'sysadmin_tools') as $d) {
			self::must_local('rsync -a --exclude=.git --exclude=.gitignore ' . escapeshellarg($root . '/maintenance_scripts/' . $d . '/')
				. ' ' . escapeshellarg($stage . '/maintenance_scripts/' . $d . '/'), 'stage ' . $d);
		}
		file_put_contents($stage . '/public_html/VERSION', $version . "\n");
		self::must_local('rm -rf ' . escapeshellarg($stage . '/public_html/agent_dist') . ' && cp -a ' . escapeshellarg($build['dist'])
			. ' ' . escapeshellarg($stage . '/public_html/agent_dist'), 'put the run\'s agent in agent_dist');
		foreach (array('/public_html/' . PackageSignature::STATEMENT_NAME, '/public_html/agent_dist/' . PackageSignature::STATEMENT_NAME) as $f) {
			@unlink($stage . $f);
		}
		$known = ReleaseCommit::knownFiles($root);
		if ($known === null) { throw new Exception('git could not list the files this tree ships'); }
		$ships = function ($rel) use ($known) {
			return ReleaseCommit::joineryFileShips($rel, $known) && basename($rel) !== PackageSignature::STATEMENT_NAME;
		};
		$keys = AgentDistPublisher::ensureKeys($build['site'] . '/config');
		$manifest = TreeManifestPublisher::write($stage, $stage, $keys, $ships);
		$members = TreeManifestPublisher::archiveMembers($stage, '.', '');
		if ($members === null) { throw new Exception('the staged manifest could not be read back'); }
		$list = $base . '/members';
		file_put_contents($list, implode("\0", $members) . "\0");
		$archive = $base . '/out/joinery-core-' . $version . '.tar.gz';
		self::must_local(sprintf('tar -czf %s --null --no-recursion -C %s -T %s', escapeshellarg($archive), escapeshellarg($stage), escapeshellarg($list)), 'pack the candidate');
		// What a site will do with it: unpack, and verify against the run's key.
		$check = $base . '/check';
		mkdir($check, 0700, true);
		self::must_local('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($check), 'unpack the candidate');
		file_put_contents($base . '/keys', $keys['public_b64'] . "\n");
		$v = PackageSignature::verify($check, $base . '/keys', array('fresh' => true));
		if ($v->verdict !== PackageSignature::SIGNED) {
			throw new Exception('the candidate does not verify against the run\'s key: ' . $v->detail);
		}
		exec('rm -rf ' . escapeshellarg($check) . ' ' . escapeshellarg($stage));
		$say('candidate ' . $version . ': ' . $manifest['files'] . ' files, ' . round(filesize($archive) / 1048576, 1) . ' MB, verifies with the run\'s key');
		return $archive;
	}

	/**
	 * The newest published release, installed on the server as the
	 * provisioning flow installs a bare-metal site (install.sh server, then
	 * site --enable-agent), named $site at <label>.joinerytest.com without a
	 * certificate, its agent asking to join this management node. Returns the
	 * key fingerprint the install printed.
	 */
	public static function install_site(array $server, string $site, string $version, callable $say): string {
		$plane = rtrim(LibraryFunctions::get_absolute_url('/'), '/');
		$domain = $server['label'] . '.joinerytest.com';
		$dns = array(PHP_BINARY, self::site_root() . '/maintenance_scripts/dev_tools/test_server.php', 'dns', (string)$server['label'], (string)$server['ipv4']);
		if (!empty($server['ipv6'])) { $dns[] = strtok((string)$server['ipv6'], '/'); }
		self::must(self::run_local($dns, 120), 'point ' . $domain . ' at the server');
		self::wait_ssh($server);
		$script = implode("\n", array(
			'set -eo pipefail',
			'D=/opt/joinery-install/live-proof; rm -rf "$D"; mkdir -p "$D"',
			'curl -sfL ' . escapeshellarg($plane . '/utils/latest_release?version=' . $version) . ' | tar xz -C "$D"',
			'cd "$D/maintenance_scripts/install_tools" && chmod +x *.sh',
			"POSTGRES_PASSWORD=\$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)",
			'echo "$POSTGRES_PASSWORD" > /root/.joinery_postgres_password && chmod 600 /root/.joinery_postgres_password && export POSTGRES_PASSWORD',
			// --keep-root-login: the run logs in as root again after this.
			'./install.sh -y -q server --keep-root-login',
			'./install.sh -y -q site --bare-metal ' . escapeshellarg($site) . ' --password-file=/root/.joinery_postgres_password '
				. escapeshellarg($domain) . ' --no-ssl --enable-agent --management-node=' . escapeshellarg($plane)
				. ' --upgrade-server=' . escapeshellarg($plane),
			'echo INSTALL_SUCCESS',
		));
		$out = self::must(self::ssh($server, $script, 2400), 'install ' . $version . ' as ' . $site);
		if (strpos($out, 'INSTALL_SUCCESS') === false || !preg_match('/SITE_AGENT_KEY=([0-9a-fA-F ]{16,})/', $out, $m)) {
			throw new Exception('the install printed no agent key: ' . trim(substr($out, -600)));
		}
		$say('the site ' . $site . ' is installed at ' . $version);
		return strtolower(preg_replace('/[^0-9a-fA-F]/', '', $m[1]));
	}

	/**
	 * The run's agent in place of the release's: the binary swapped, the trust
	 * record the release's agent may have written removed (it would override
	 * the keys built in), and its dist pinned to the run's own, so it never
	 * updates itself back to the release's binary. The site's tree is not
	 * touched.
	 */
	public static function swap_agent(array $server, array $build, callable $say): void {
		self::must(self::ssh($server, 'mkdir -p /root/live-proof/dist'), 'make /root/live-proof');
		$files = array();
		foreach (scandir($build['dist']) as $f) {
			if ($f[0] !== '.' && is_file($build['dist'] . '/' . $f)) { $files[] = $build['dist'] . '/' . $f; }
		}
		self::must(self::scp($server, $files, '/root/live-proof/dist/'), 'copy the run\'s agent');
		self::must(self::ssh($server, implode("\n", array(
			'set -e',
			'a=$(dpkg --print-architecture)',
			'gunzip -c "/root/live-proof/dist/joinery-agent-linux-$a.gz" > /usr/local/bin/joinery-agent.new',
			'chmod 755 /usr/local/bin/joinery-agent.new && mv -f /usr/local/bin/joinery-agent.new /usr/local/bin/joinery-agent',
			'rm -f /etc/joinery-agent/release_trust',
			"grep -q '^AGENT_DIST_DIR=' /etc/joinery-agent/joinery-agent.env 2>/dev/null || echo 'AGENT_DIST_DIR=/root/live-proof/dist' >> /etc/joinery-agent/joinery-agent.env",
			'systemctl restart joinery-agent',
		)), 120), 'put the run\'s agent in place');
		$say('the run\'s agent runs in place of the release\'s');
	}

	/** Wait for the node's agent to name its site (the web root a site word needs). */
	public static function await_site(ManagedNode $node, int $seconds = 300): void {
		$until = time() + $seconds;
		do {
			$node->load();
			if ($node->hosts_site()) { return; }
			sleep(5);
		} while (time() < $until);
		throw new Exception('the agent did not report its site within ' . $seconds . ' s');
	}

	/**
	 * The candidate served to the site from its own server, on the test site
	 * alone: the archive and an upgrade answer naming it, served on loopback;
	 * the site's upgrade source pointed there; the run's key added to the
	 * keys it trusts, and the log requirement lifted (the run's key is in no
	 * log).
	 */
	public static function stage_candidate(array $server, string $site, string $archive, string $version, string $key_b64, callable $say): void {
		$port = self::CANDIDATE_PORT;
		$url = 'http://127.0.0.1:' . $port;
		self::must(self::ssh($server, 'mkdir -p /root/live-proof/serve/utils'), 'make the serving directory');
		self::must(self::scp($server, array($archive), '/root/live-proof/serve/'), 'copy the candidate');
		$answer = json_encode(array('system_version' => $version, 'upgrade_name' => basename($archive),
			'core_location' => $url . '/' . basename($archive), 'published_themes' => array(), 'published_plugins' => array(),
			'required_themes' => array(), 'required_plugins' => array()));
		$cfg = '/var/www/html/' . $site . '/config';
		self::must(self::ssh($server, implode("\n", array(
			'set -e',
			'printf %s ' . escapeshellarg($answer) . ' > /root/live-proof/serve/utils/upgrade',
			'nohup setsid python3 -m http.server ' . $port . ' --bind 127.0.0.1 --directory /root/live-proof/serve > /root/live-proof/serve.log 2>&1 < /dev/null &',
			'sleep 2',
			'curl -sf ' . escapeshellarg($url . '/utils/upgrade?serve-upgrade=1') . ' > /dev/null',
			// One key a line: a file not ending in a newline gets one first.
			'[ -z "$(tail -c1 ' . escapeshellarg($cfg . '/release_verify_keys') . ')" ] || echo >> ' . escapeshellarg($cfg . '/release_verify_keys'),
			'echo ' . escapeshellarg($key_b64) . ' >> ' . escapeshellarg($cfg . '/release_verify_keys'),
			'touch ' . escapeshellarg($cfg . '/release_log_optional') . ' && chown root:root ' . escapeshellarg($cfg . '/release_log_optional')
				. ' && chmod 644 ' . escapeshellarg($cfg . '/release_log_optional'),
			// The postgres role's password is the one install.sh server set.
			'PGPASSWORD="$(cat /root/.joinery_postgres_password)" psql -q -U postgres -h 127.0.0.1 -d ' . escapeshellarg($site)
				. ' -c ' . escapeshellarg("UPDATE stg_settings SET stg_value = '" . $url . "' WHERE stg_name = 'upgrade_source'"),
		)), 120), 'serve the candidate to the site');
		$say('the candidate ' . $version . ' is offered to the site from its own server');
	}

	/**
	 * Run one site word through the node's agent and judge it: upgrade_preflight
	 * ready, or apply_update completed onto $version with its deploy tier
	 * passed and no rollback (StagedRolloutRunner's own judges).
	 *
	 * @return array{ok: bool, job_id: int, what: string}
	 */
	public static function prove_site(ManagedNode $node, string $word, string $version): array {
		$node->load();
		if (!isset(self::SITE_RECIPES[$word])) {
			return array('ok' => false, 'job_id' => 0, 'what' => 'no site recipe');
		}
		if ($word === 'site_log') {
			$built = JobCommandBuilder::build_site_log_primitive($node, 'access', false, 20);
		} else {
			$built = $word === 'apply_update' ? JobCommandBuilder::build_apply_update($node) : JobCommandBuilder::build_upgrade_preflight($node);
		}
		$job = ManagementJob::createFromBuild($node->key, $word, $built, null, null);
		$until = time() + self::SITE_RECIPES[$word]['wait'];
		do {
			sleep(5);
			$job->load();
		} while (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true) && time() < $until);
		if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
			return array('ok' => false, 'job_id' => (int)$job->key, 'what' => 'its job did not finish in ' . self::SITE_RECIPES[$word]['wait'] . ' s');
		}
		JobResultProcessor::process_if_due($job);
		$job->load();
		$tail = self::output_tail($job);
		if ($word === 'site_log') {
			return array('job_id' => (int)$job->key) + self::judge($word, $job);
		}
		if ($word === 'apply_update') {
			$why = StagedRolloutRunner::gate($job, $version);
			if ($why !== null && $tail !== '') { $why .= "\n      its output ended:\n" . $tail; }
			$r = json_decode((string)$job->get('mjb_result'), true);
			$apply = is_array($r) ? ($r['apply'] ?? array()) : array();
			return array('ok' => $why === null, 'job_id' => (int)$job->key, 'what' => $why ?? ('upgraded ' . ($apply['version_before'] ?? '?')
				. ' to ' . ($apply['version_after'] ?? '?') . ', deploy tier passed, no rollback'));
		}
		$why = StagedRolloutRunner::preflight_verdict($job);
		if ($why !== null && $tail !== '') { $why .= "\n      its output ended:\n" . $tail; }
		$r = json_decode((string)$job->get('mjb_result'), true);
		return array('ok' => $why === null, 'job_id' => (int)$job->key,
			'what' => $why ?? ('ready: ' . count((array)($r['checks'] ?? array())) . ' checks passed'));
	}

	/**
	 * A throwaway server in the test cloud account: {id, label, ipv4, ipv6,
	 * password_file}. test_server.php refuses any account but the test one.
	 */
	public static function make_server(string $label, ?string $type = null): array {
		$argv = array(PHP_BINARY, self::site_root() . '/maintenance_scripts/dev_tools/test_server.php', 'create', $label);
		if ($type !== null) { $argv[] = '--type=' . $type; }
		$r = self::run_local($argv, 300);
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
		self::wait_ssh($server);
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
		// A site's agent claims its site's name; a machine's, the run's label.
		$claimed = (string)($server['site'] ?? $server['label']);
		if ($b === false || !isset($ours[$b]) || (string)$request->get('ajr_claimed_name') !== $claimed) {
			throw new Exception('the join with fingerprint ' . $fingerprint . ' came from ' . ($source !== '' ? $source : 'an unknown address')
				. ' as "' . $request->get('ajr_claimed_name') . '", not from the server ' . $server['label'] . ' at its own address as "' . $claimed . '"; not adopted');
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
		foreach ($server ? array_unique(array_filter(array((string)($server['label'] ?? ''), (string)($server['site'] ?? '')), 'strlen')) : array() as $named) {
			try {
				foreach (new MultiAgentJoinRequest(array('ajr_claimed_name' => $named)) as $r) {
					$r->permanent_delete();
				}
			} catch (Exception $e) {
				$left[] = 'the join requests named ' . $named . ': ' . $e->getMessage();
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

	/**
	 * The last lines a job's word printed (the envelope's output, or the raw
	 * transcript), tags stripped: what a failed word said, kept in the run's
	 * own report, since the job goes with the node at teardown.
	 */
	public static function output_tail(ManagementJob $job, int $lines = 25): string {
		$raw = (string)$job->get('mjb_output');
		if (preg_match('/\{"api_version".*\}\s*$/s', $raw, $m)) {
			$d = json_decode($m[0], true);
			if (is_array($d) && is_string($d['data']['output'] ?? null)) { $raw = $d['data']['output']; }
			elseif (is_array($d) && is_string($d['error'] ?? null)) { $raw = $d['error']; }
		}
		$text = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $raw)));
		$rows = array_slice(array_filter(array_map('rtrim', explode("\n", $text)), 'strlen'), -$lines);
		return implode("\n", array_map(function ($r) { return '        ' . substr($r, 0, 300); }, $rows));
	}

	/** Wait up to seven minutes for the server to answer SSH. */
	public static function wait_ssh(array $server): void {
		for ($i = 0; $i < 40; $i++) {
			if (self::ssh($server, 'true', 20)['exit'] === 0) {
				return;
			}
			sleep(10);
		}
		throw new Exception('the server did not answer SSH in seven minutes');
	}

	/** Run a local shell command that must succeed (its parts already escaped), or an exception naming it. */
	private static function must_local(string $command, string $what): void {
		exec($command . ' 2>&1', $out, $rc);
		if ($rc !== 0) {
			throw new Exception($what . ' failed (exit ' . $rc . '): ' . trim(substr(implode("\n", $out), -400)));
		}
	}

	private static function ssh_base(array $server): array {
		return array('sshpass', '-f', (string)$server['password_file']);
	}

	private static function ssh_opts(): array {
		return array('-o', 'StrictHostKeyChecking=no', '-o', 'UserKnownHostsFile=/dev/null', '-o', 'LogLevel=ERROR', '-o', 'ConnectTimeout=15');
	}

	/** Run a command on the server as root: {exit, out}. */
	public static function ssh(array $server, string $command, int $timeout = 120): array {
		return self::run_local(array_merge(self::ssh_base($server), array('ssh'), self::ssh_opts(),
			array('root@' . $server['ipv4'], $command)), $timeout);
	}

	/** Copy local files to a directory on the server: {exit, out}. */
	public static function scp(array $server, array $files, string $to): array {
		return self::run_local(array_merge(self::ssh_base($server), array('scp', '-q'), self::ssh_opts(), $files,
			array('root@' . $server['ipv4'] . ':' . $to)), 300);
	}

	/** The output of a step that must succeed, or an exception naming it. */
	public static function must(array $r, string $what): string {
		if ($r['exit'] !== 0) {
			throw new Exception($what . ' failed (exit ' . $r['exit'] . '): ' . trim(substr($r['out'], -600)));
		}
		return $r['out'];
	}

	/** Run a local command (an argument list, never a shell string), bounded: {exit, out}. */
	public static function run_local(array $argv, int $timeout): array {
		$cmd = 'timeout ' . (int)$timeout . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
		exec($cmd, $out, $rc);
		return array('exit' => $rc, 'out' => implode("\n", $out));
	}
}
?>
