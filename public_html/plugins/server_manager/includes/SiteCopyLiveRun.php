<?php
/**
 * SiteCopyLiveRun - a site copied once, for real, the way most copies go:
 * the pieces the live test site_copy_live drives (site_copy_final_test.md,
 * the smoke test).
 *
 * The source is a throwaway server in the test cloud account
 * (AgentLiveProofRun::make_server), installed at the oldest release this
 * management node can still copy (the copy installs its source's exact
 * release, so this is the release a copy's install most often gets wrong),
 * named under the Cloudflare test zone with its records proxied, and joined
 * here. Its owner's two ceremonies, proving the recovery key and approving the
 * export, are done as root on the source (tests/lib/copy_live_source.php) with
 * a key made for the run, whose private half never leaves this process.
 *
 * The copy then runs as the Copy tab runs it: SiteCopyRunner on this
 * management node, its scheduled tasks advancing it, onto a new server made in
 * the test account. The switch is by the proxy's origin, with the test zone's
 * token, as an owner types it.
 *
 *   oldest_copyable_release()  the release the source is installed at
 *   install_source()           the source's records (DNS only), the release,
 *                              the site and its agent's join, then proxied
 *   push_rig() / source()      the seed, the checker's values and the owner's
 *                              steps, run on the source
 *   prove_recovery_key()       the possession ceremony, opened here
 *   answer_export()            the export's approval, after its fingerprint
 *                              is checked against the copy's own key
 *   wait_status()              follows the copy, answering the export as it
 *                              asks, until it reaches the status wanted
 *   marker()                   a seed's marker file, read over HTTPS
 *   teardown()                 everything the run made, whatever happened
 *
 * Every node the run makes is marked a test build (mgn_agent_test_build), as
 * a live proof's is: the test cloud's cleanup removes it with its server if
 * the run is killed before its teardown.
 *
 * @version 1.0
 */
class SiteCopyLiveRun {

	/** The label every run's source carries, then six hex digits. */
	const LABEL_PREFIX = 'copysmoke-';

	/** The Cloudflare zone the runs' sites are named in (site_copy_final_test.md, the proxy runs). */
	const ZONE = 'joinerytest2.com';

	/** The token scoped to that zone alone, outside every site's config (never printed). */
	const TOKEN_FILE = '.config/joinery-test/cloudflare_test_token';

	/** The source's server, and the copy's: a site and its database want more than the smallest. */
	const SERVER_TYPE = 'g6-standard-1';

	/** Where the copy is made. */
	const REGION = 'us-east';

	/** A run's leftovers older than this belong to a run the runner killed (its cap is 90 minutes). */
	const DEAD_RUN_SECONDS = 10800;

	/** The rig files the source runs, from tests/lib. */
	const RIG = array('seed_copy_source.php', 'copy_seed_values.php', 'copy_live_source.php');

	/** Where the rig goes on the source. */
	const RIG_DIR = '/root/copy-live-rig';

	/** The statuses a copy does not come back from by itself. */
	const STOPPED = array(SiteCopy::STATUS_HALTED, SiteCopy::STATUS_DISCARDED, SiteCopy::STATUS_FINISHED);

	// ── The release and the token ──────────────────────────────────────────

	/**
	 * The oldest release this management node serves and a copy accepts as its
	 * source's (COPY_SOURCE_MIN_VERSION), or null. A copy installs at its
	 * source's own release from here, so only one held here can be copied.
	 */
	public static function oldest_copyable_release(): ?string {
		$best = null;
		foreach (glob(PathHelper::getSiteRoot() . '/static_files/joinery-core-*.tar.gz') ?: array() as $f) {
			if (!preg_match('/joinery-core-(\d+\.\d+\.\d+)\.tar\.gz$/', $f, $m)) { continue; }
			$v = $m[1];
			if (version_compare($v, JobCommandBuilder::COPY_SOURCE_MIN_VERSION, '<') || !ReleaseStream::serves_releases($v)) { continue; }
			if ($best === null || version_compare($v, $best, '<')) { $best = $v; }
		}
		return $best;
	}

	/** The test zone's token file, in the home of whoever runs the test. */
	public static function token_path(): string {
		return rtrim((string)(getenv('HOME') ?: '/home/user1'), '/') . '/' . self::TOKEN_FILE;
	}

	private static function token(): string {
		$t = trim((string)@file_get_contents(self::token_path()));
		if ($t === '') {
			throw new Exception('the Cloudflare test token cannot be read at ' . self::token_path());
		}
		return $t;
	}

	/**
	 * The DNS driver a switch is pressed with, built from the token as the
	 * Copy tab builds it from what the owner types: the driver the site's own
	 * nameservers name, given the token in its first credential field.
	 */
	public static function switch_driver(string $domain): DnsProvider {
		$class = ProxiedOriginMove::driver_class_for($domain);
		if (!$class) {
			throw new Exception('no proxy driver answers for ' . $domain . ' (its nameservers name no provider that proxies)');
		}
		$fields = array_keys($class::credentialFields());
		return new $class(array($fields[0] => self::token()));
	}

	// ── The test zone's records: the run's own, written here ───────────────
	//
	// The platform's driver publishes DNS-only and only moves a proxied
	// record's origin, by design; the run makes its records proxied itself,
	// as the zone's owner does in Cloudflare's dashboard.

	private static function cf(string $method, string $path, ?array $body = null): array {
		$ch = curl_init('https://api.cloudflare.com/client/v4/' . $path);
		curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . self::token(), 'Content-Type: application/json')));
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}
		for ($try = 0; $try < 5; $try++) {
			$r = json_decode((string)curl_exec($ch), true);
			// 971: Cloudflare asks to slow down; it means wait, not no.
			$throttled = is_array($r) && in_array(971, array_column((array)($r['errors'] ?? array()), 'code'), true);
			if (!$throttled) { break; }
			sleep(10 * ($try + 1));
		}
		curl_close($ch);
		if (!is_array($r) || empty($r['success'])) {
			throw new Exception('Cloudflare ' . $method . ' ' . strtok($path, '?') . ': ' . json_encode(is_array($r) ? ($r['errors'] ?? $r) : 'no answer'));
		}
		return (array)$r['result'];
	}

	private static function zone_id(): string {
		static $id = null;
		if ($id === null) {
			$z = self::cf('GET', 'zones?name=' . self::ZONE);
			if (!$z) { throw new Exception('the token sees no zone ' . self::ZONE); }
			$id = (string)$z[0]['id'];
		}
		return $id;
	}

	/** A name's A and AAAA records: [{id, type, content, proxied, created_on}]. */
	public static function records(string $name): array {
		$out = array();
		foreach (self::cf('GET', 'zones/' . self::zone_id() . '/dns_records?per_page=100&name=' . rawurlencode($name)) as $r) {
			if (in_array($r['type'] ?? '', array('A', 'AAAA'), true)) { $out[] = $r; }
		}
		return $out;
	}

	/** One A or AAAA record at a name, made or replaced. */
	public static function put_record(string $name, string $type, string $content, bool $proxied): void {
		$body = array('type' => $type, 'name' => $name, 'content' => $content, 'ttl' => 1, 'proxied' => $proxied);
		foreach (self::records($name) as $r) {
			if ($r['type'] === $type) {
				self::cf('PUT', 'zones/' . self::zone_id() . '/dns_records/' . $r['id'], $body);
				return;
			}
		}
		self::cf('POST', 'zones/' . self::zone_id() . '/dns_records', $body);
	}

	/** Turn the proxy on or off for a name's address records, leaving where they point. */
	public static function set_proxied(string $name, bool $proxied): void {
		foreach (self::records($name) as $r) {
			self::cf('PATCH', 'zones/' . self::zone_id() . '/dns_records/' . $r['id'], array('proxied' => $proxied));
		}
	}

	/** Remove a name's address records; how many went. */
	public static function delete_records(string $name): int {
		$n = 0;
		foreach (self::records($name) as $r) {
			self::cf('DELETE', 'zones/' . self::zone_id() . '/dns_records/' . $r['id']);
			$n++;
		}
		return $n;
	}

	/**
	 * What runs the runner killed left that nothing else removes: their
	 * records in the test zone, their copy records (the test cloud's own
	 * cleanup takes their test-build nodes with their servers, which leaves a
	 * copy naming neither), and their root password files.
	 *
	 * @return string[] what was removed
	 */
	public static function forget_dead_runs(): array {
		$gone = array();
		$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT c.scp_site_copy_id, p.cvp_customer_cloud_provision_id
			FROM scp_site_copies c JOIN cvp_customer_cloud_provisions p ON p.cvp_customer_cloud_provision_id = c.scp_cvp_customer_cloud_provision_id
			WHERE c.scp_source_node_id IS NULL AND c.scp_copy_node_id IS NULL AND p.cvp_sitename LIKE ? AND c.scp_create_time < ?");
		$q->execute(array(str_replace('-', '', self::LABEL_PREFIX) . '%', gmdate('Y-m-d H:i:s', time() - self::DEAD_RUN_SECONDS)));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$c = new SiteCopy((int)$row['scp_site_copy_id'], TRUE);
			$p = new CustomerCloudProvision((int)$row['cvp_customer_cloud_provision_id'], TRUE);
			if ($c->key) { $c->permanent_delete(); }
			if ($p->key) { $p->permanent_delete(); }
			$gone[] = 'copy #' . (int)$row['scp_site_copy_id'] . ' and its provision #' . (int)$row['cvp_customer_cloud_provision_id'];
		}
		foreach (self::cf('GET', 'zones/' . self::zone_id() . '/dns_records?per_page=100') as $r) {
			$age = time() - (int)strtotime((string)($r['created_on'] ?? ''));
			if (strpos((string)$r['name'], self::LABEL_PREFIX) === 0 && $age > self::DEAD_RUN_SECONDS) {
				self::cf('DELETE', 'zones/' . self::zone_id() . '/dns_records/' . $r['id']);
				$gone[] = $r['type'] . ' ' . $r['name'];
			}
		}
		foreach (glob('/dev/shm/test_server_' . self::LABEL_PREFIX . '*') ?: array() as $f) {
			if (is_file($f) && time() - (int)filemtime($f) > self::DEAD_RUN_SECONDS && @unlink($f)) {
				$gone[] = 'the password file ' . basename($f);
			}
		}
		return $gone;
	}

	// ── The source ─────────────────────────────────────────────────────────

	/**
	 * $version installed on the server as the provisioning flow installs a
	 * bare-metal site, named $domain in the test zone, its agent asking to join
	 * this management node. Its records are DNS-only for the install, so the
	 * certificate is issued, and proxied after it. Returns the key fingerprint
	 * the install printed.
	 */
	public static function install_source(array $server, string $site, string $domain, string $version, callable $say): string {
		$plane = rtrim(LibraryFunctions::get_absolute_url('/'), '/');
		self::put_record($domain, 'A', (string)$server['ipv4'], false);
		if (!empty($server['ipv6'])) {
			self::put_record($domain, 'AAAA', (string)strtok((string)$server['ipv6'], '/'), false);
		}
		AgentLiveProofRun::wait_ssh($server);
		$script = implode("\n", array(
			'set -eo pipefail',
			'D=/opt/joinery-install/copy-live; rm -rf "$D"; mkdir -p "$D"',
			'curl -sfL ' . escapeshellarg($plane . '/utils/latest_release?version=' . $version) . ' | tar xz -C "$D"',
			'cd "$D/maintenance_scripts/install_tools" && chmod +x *.sh',
			"POSTGRES_PASSWORD=\$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)",
			'echo "$POSTGRES_PASSWORD" > /root/.joinery_postgres_password && chmod 600 /root/.joinery_postgres_password && export POSTGRES_PASSWORD',
			// The run logs in as root again after this: a release that can turn
			// root login off is told to keep it (older ones never turn it off).
			'KEEP=""; grep -q -- --keep-root-login install.sh && KEEP=--keep-root-login',
			'./install.sh -y -q server $KEEP',
			'./install.sh -y -q site --bare-metal ' . escapeshellarg($site) . ' --password-file=/root/.joinery_postgres_password '
				. escapeshellarg($domain) . ' --enable-agent --management-node=' . escapeshellarg($plane)
				. ' --upgrade-server=' . escapeshellarg($plane),
			'echo INSTALL_SUCCESS',
		));
		$out = AgentLiveProofRun::must(AgentLiveProofRun::ssh($server, $script, 2400), 'install ' . $version . ' as ' . $site);
		if (strpos($out, 'INSTALL_SUCCESS') === false || !preg_match('/SITE_AGENT_KEY=([0-9a-fA-F ]{16,})/', $out, $m)) {
			throw new Exception('the install printed no agent key: ' . trim(substr($out, -600)));
		}
		self::set_proxied($domain, true);
		$say('the site ' . $site . ' is installed at ' . $version . ' as https://' . $domain . ', its records proxied');
		return strtolower(preg_replace('/[^0-9a-fA-F]/', '', $m[1]));
	}

	/** The rig files, onto the source. */
	public static function push_rig(array $server): void {
		AgentLiveProofRun::must(AgentLiveProofRun::ssh($server, 'mkdir -p ' . self::RIG_DIR), 'make the rig folder');
		$files = array_map(function ($f) { return PathHelper::getIncludePath('plugins/server_manager/tests/lib/' . $f); }, self::RIG);
		AgentLiveProofRun::must(AgentLiveProofRun::scp($server, $files, self::RIG_DIR . '/'), 'copy the rig');
	}

	/**
	 * Run a rig script on the source as root; its KEY=value lines.
	 *
	 * @param string[] $args already plain words (no quoting needed), each escaped here
	 */
	public static function source(array $server, string $site, string $script, array $args, int $timeout = 300): array {
		$cmd = 'php ' . escapeshellarg(self::RIG_DIR . '/' . $script) . ' ' . escapeshellarg('/var/www/html/' . $site)
			. ($args ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '');
		$out = AgentLiveProofRun::must(AgentLiveProofRun::ssh($server, $cmd, $timeout), $script . ' ' . ($args[0] ?? ''));
		$kv = array();
		foreach (explode("\n", $out) as $line) {
			if (preg_match('/^([A-Z]+)=(.*)$/', trim($line), $m)) { $kv[$m[1]] = $m[2]; }
		}
		return $kv;
	}

	/** A seed on the source: {id, path} (its marker file's public path). */
	public static function seed(array $server, string $site): array {
		$kv = self::source($server, $site, 'seed_copy_source.php', array());
		$seed = json_decode((string)($kv['SEED'] ?? ''), true);
		if (!is_array($seed) || empty($seed['id']) || empty($seed['items']['marker']['file_id'])) {
			throw new Exception('the seed printed no marker: ' . json_encode($kv));
		}
		$path = (string)(self::source($server, $site, 'copy_live_source.php', array('marker-path', (string)$seed['items']['marker']['file_id']))['PATH'] ?? '');
		if ($path === '') {
			throw new Exception('the seed\'s marker file has no public path');
		}
		return array('id' => (string)$seed['id'], 'path' => $path);
	}

	/**
	 * The site's backup recovery key set to the run's key and proven, as its
	 * owner does it on the Backups page: the challenge is opened here, and only
	 * what it opened to goes back. Returns the key's state on the site.
	 */
	public static function prove_recovery_key(array $server, string $site, string $keypair): string {
		$pub = base64_encode(sodium_crypto_box_publickey($keypair));
		$challenge = (string)(self::source($server, $site, 'copy_live_source.php', array('recovery-key', $pub))['CHALLENGE'] ?? '');
		$opened = sodium_crypto_box_seal_open((string)base64_decode($challenge, true), $keypair);
		if ($opened === false) {
			throw new Exception('the site\'s possession challenge does not open with the run\'s key');
		}
		return (string)(self::source($server, $site, 'copy_live_source.php', array('prove', base64_encode($opened)))['STATE'] ?? '');
	}

	/**
	 * What an approval challenge opens to with the recovery key's private half:
	 * X25519 with the challenge's ephemeral key, HKDF-SHA256 over the scope's
	 * info, AES-256-GCM, as the Backups page opens it in the browser
	 * (recovery-readiness.js).
	 */
	public static function open_challenge(string $challenge_b64, string $keypair, string $info): string {
		$blob = (string)base64_decode($challenge_b64, true);
		if (strlen($blob) < 32 + 12 + 16 + 1) {
			throw new Exception('the challenge is too short to be one');
		}
		$eph = substr($blob, 0, 32);
		$iv = substr($blob, 32, 12);
		$tag = substr($blob, -16);
		$ct = substr($blob, 44, -16);
		$pub = sodium_crypto_box_publickey($keypair);
		$shared = sodium_crypto_scalarmult(sodium_crypto_box_secretkey($keypair), $eph);
		$key = hash_hkdf('sha256', $shared, 32, $info . $eph . $pub, '');
		$plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		sodium_memzero($shared);
		sodium_memzero($key);
		if ($plain === false) {
			throw new Exception('the challenge does not open with the run\'s key');
		}
		return $plain;
	}

	/**
	 * Approve the export the source is waiting on, as its owner would: only
	 * once the machine it names (the full SHA-256 of its key) is the copy's
	 * own, from the key this management node approved its join by. Returns
	 * the job approved, or null when nothing is waiting.
	 */
	public static function answer_export(array $server, string $site, string $keypair, SiteCopy $copy): ?int {
		$pending = json_decode((string)(self::source($server, $site, 'copy_live_source.php', array('export-pending'))['PENDING'] ?? 'null'), true);
		if (!is_array($pending) || ($pending['answered'] ?? null) !== null) {
			return null;
		}
		$row = SiteCopyRunner::copy_row($copy, false);
		$key = $row ? (string)base64_decode(trim((string)$row->get('mgn_agent_public_key')), true) : '';
		if ($key === '') {
			throw new Exception('the source asks to export, and the copy\'s agent key is not on record to check it against');
		}
		$want = hash('sha256', $key);
		$named = '';
		foreach ((array)($pending['facts'] ?? array()) as $fact) {
			$v = is_array($fact) ? (string)($fact['value'] ?? $fact['Value'] ?? '') : '';
			if (preg_match('/^[0-9a-f]{64}$/', $v)) { $named = $v; }
		}
		if (!hash_equals($want, $named)) {
			throw new Exception('the export names the machine ' . ($named !== '' ? substr($named, 0, 16) : 'none')
				. ', not the copy (' . substr($want, 0, 16) . '): not approved');
		}
		$answer = self::open_challenge((string)$pending['challenge'], $keypair, (string)$pending['info']);
		self::source($server, $site, 'copy_live_source.php', array('export-answer', (string)(int)$pending['job_id'], base64_encode($answer)));
		return (int)$pending['job_id'];
	}

	// ── This management node's side ────────────────────────────────────────

	/** Queue a job and wait for it to end, its result processed. */
	public static function job(ManagedNode $node, string $word, array $built, $params, int $wait, $user_id): ManagementJob {
		$job = ManagementJob::createFromBuild($node->key, $word, $built, $params, $user_id);
		return self::await_job((int)$job->key, $wait);
	}

	public static function await_job(int $job_id, int $wait): ManagementJob {
		$job = new ManagementJob($job_id, TRUE);
		$until = time() + $wait;
		while (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true) && time() < $until) {
			sleep(5);
			$job->load();
		}
		if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
			throw new Exception('job ' . $job->key . ' (' . $job->get('mjb_job_type') . ') did not end in ' . $wait . ' s');
		}
		JobResultProcessor::process_if_due($job);
		$job->load();
		return $job;
	}

	/** What a job printed, the envelope's output when it has one. */
	public static function output(ManagementJob $job): string {
		$raw = (string)$job->get('mjb_output');
		if (preg_match('/\{"api_version".*\}\s*$/s', $raw, $m)) {
			$d = json_decode($m[0], true);
			if (is_array($d) && is_string($d['data']['output'] ?? null)) { return $d['data']['output']; }
		}
		return $raw;
	}

	/** Wait for the node's agent to report the words it can run. */
	public static function await_words(ManagedNode $node, int $seconds = 300): void {
		$until = time() + $seconds;
		do {
			$node->load();
			if (trim((string)$node->get('mgn_agent_primitives')) !== '') { return; }
			sleep(5);
		} while (time() < $until);
		throw new Exception('the agent did not report its words within ' . $seconds . ' s');
	}

	/** The copy's first step not yet passed, in a line, or ''. */
	public static function step_line(SiteCopy $copy): string {
		foreach ($copy->steps() as $s) {
			if (!in_array((string)($s['verdict'] ?? ''), SiteCopyRunner::DONE_VERDICTS, true)) {
				return (string)($s['op'] ?? '?') . ' on ' . (string)($s['on'] ?? '?') . ': ' . (string)($s['verdict'] ?? 'waiting')
					. (trim((string)($s['reason'] ?? '')) !== '' ? ' (' . substr(trim((string)$s['reason']), 0, 200) . ')' : '');
			}
		}
		return '';
	}

	/**
	 * Follow the copy until it reaches one of $want, saying each change;
	 * $each runs on every look (the export's approval, the copy's node).
	 * Throws when it stops anywhere else, its server's provision fails, or the
	 * time runs out.
	 */
	public static function wait_status(SiteCopy $copy, array $want, int $seconds, callable $say, ?callable $each = null): string {
		$until = time() + $seconds;
		$last = '';
		while (true) {
			$copy->load();
			$status = $copy->status();
			$line = $status . ($copy->status_label() !== $status ? ' (' . $copy->status_label() . ')' : '') . ' ' . self::step_line($copy);
			if ($line !== $last) {
				$say(gmdate('H:i:s') . ' ' . $line);
				$last = $line;
			}
			if (in_array($status, $want, true)) {
				return $status;
			}
			if (in_array($status, self::STOPPED, true)) {
				throw new Exception('the copy stopped at ' . $status . ': ' . trim((string)$copy->get('scp_error') . ' ' . self::step_line($copy)));
			}
			$p = SiteCopyRunner::provision($copy);
			if ($p && (string)$p->get('cvp_status') === 'failed') {
				throw new Exception('the copy\'s server failed to be made: ' . (string)$p->get('cvp_error'));
			}
			if (time() >= $until) {
				throw new Exception('the copy did not reach ' . implode(' or ', $want) . ' in ' . $seconds . ' s; it is ' . $line);
			}
			if ($each) {
				$each($copy);
			}
			sleep(10);
		}
	}

	/**
	 * A seed's marker file over HTTPS at the site's name: through the proxy,
	 * or at $ip itself. {ok, code}: ok when its bytes are the seed's.
	 */
	public static function marker(string $domain, array $seed, ?string $ip = null): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/tests/lib/copy_seed_values.php'));
		$ch = curl_init('https://' . $domain . $seed['path']);
		$opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false);
		if ($ip !== null) {
			$opts[CURLOPT_RESOLVE] = array($domain . ':443:' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip));
			// The origin's certificate is checked at the proxy, not here.
			$opts[CURLOPT_SSL_VERIFYPEER] = false;
			$opts[CURLOPT_SSL_VERIFYHOST] = 0;
		}
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return array('ok' => $code === 200 && $body === copy_seed_value((string)$seed['id'], 'marker'), 'code' => $code);
	}

	/** Every seed's marker, until each reads right or $seconds pass: [id => code]; empty when all read right. */
	public static function markers_wrong(string $domain, array $seeds, int $seconds = 0, ?string $ip = null): array {
		$until = time() + $seconds;
		do {
			$wrong = array();
			foreach ($seeds as $seed) {
				$r = self::marker($domain, $seed, $ip);
				if (!$r['ok']) { $wrong[$seed['id']] = $r['code']; }
			}
			if (!$wrong || time() >= $until) { return $wrong; }
			sleep(10);
		} while (true);
	}

	/** The security the node's machine promises, read fresh: CopySecurityCheck's rows after a new host report. */
	public static function security_rows(ManagedNode $node, $user_id, bool $quiet = false): array {
		foreach (CopySecurityCheck::refresh($node, $user_id) as $job_id) {
			self::await_job((int)$job_id, 900);
		}
		$node->load();
		return CopySecurityCheck::rows($node, $quiet);
	}

	/** The rows that apply and failed. */
	public static function failed_rows(array $rows): array {
		return array_values(array_filter($rows, function ($r) { return $r['applies'] && $r['pass'] === false; }));
	}

	/** The copy_probes word on the node's machine, as the site: {ok, out}. */
	public static function probes(ManagedNode $node, string $site, string $old_address, $user_id): array {
		$job = self::job($node, 'copy_probes', JobCommandBuilder::build_copy_probes($node, $site, '', $old_address), null, 600, $user_id);
		$out = self::output($job);
		return array('ok' => (string)$job->get('mjb_status') === 'completed' && preg_match('/^RESULT: PASS\s*$/m', $out) === 1
			&& preg_match('/^FAIL\b/m', $out) !== 1, 'out' => $out);
	}

	// ── Teardown ───────────────────────────────────────────────────────────

	/**
	 * Remove everything the run made, each part tried whatever the others did:
	 * the source's copies (a live one discarded first, after going back if it
	 * is mid-switch), every node the copy touched with its backups and its
	 * servers' records, both servers, the run's join requests, the source's
	 * records in the test zone and its root password file. Returns what could
	 * not be done.
	 */
	public static function teardown(?ManagedNode $source, ?array $server, string $domain): array {
		$left = array();
		$try = function (string $what, callable $fn) use (&$left) {
			try { $fn(); } catch (Throwable $e) { $left[] = $what . ': ' . $e->getMessage(); }
		};
		$node_ids = array();
		$provisions = array();
		$instances = array();
		if ($server) {
			$instances[(string)$server['id']] = (string)$server['label'];
		}
		if ($source && $source->key) {
			$sid = (int)$source->key;
			$node_ids[$sid] = true;
			foreach (new MultiSiteCopy(array('source_node_id' => $sid)) as $c) {
				if ($c->is_live()) {
					if (SiteCopyRunner::in_switch_over($c)) {
						$try('going back from copy #' . $c->key, function () use ($c, $domain) { SiteCopyRunner::go_back($c, self::switch_driver($domain)); });
					}
					$try('discarding copy #' . $c->key, function () use ($c) { SiteCopyRunner::discard($c); });
				}
				$c->load();
				if ((int)$c->get('scp_copy_node_id') > 0) { $node_ids[(int)$c->get('scp_copy_node_id')] = true; }
				if ((int)$c->get('scp_cvp_customer_cloud_provision_id') > 0) { $provisions[(int)$c->get('scp_cvp_customer_cloud_provision_id')] = true; }
			}
			foreach (new MultiManagedNode(array('copy_of_node_id' => $sid)) as $n) {
				$node_ids[(int)$n->key] = true;
			}
		}
		foreach (array_keys($node_ids) as $id) {
			foreach (new MultiCustomerCloudProvision(array('node_id' => $id)) as $p) {
				$provisions[(int)$p->key] = true;
			}
		}
		foreach (array_keys($provisions) as $pid) {
			$p = new CustomerCloudProvision($pid, TRUE);
			if ($p->key && trim((string)$p->get('cvp_instance_id')) !== '') {
				$instances[trim((string)$p->get('cvp_instance_id'))] = (string)$p->get('cvp_slug');
			}
		}
		// The backups the run's nodes took, from the target they went to; the
		// spaces themselves go with their nodes.
		foreach (array_keys($node_ids) as $id) {
			foreach (StorageSpace::of_owner(StorageSpace::OWNER_NODE, $id, true) as $space) {
				$try('the backups in ' . $space->base(), function () use ($space) {
					list(, $creds, $bucket) = $space->reach();
					foreach (S3Signer::list($creds, $bucket, $space->base()) as $o) {
						S3Signer::delete($creds, $bucket, '/' . ltrim((string)$o['key'], '/'));
					}
				});
			}
		}
		if ($source && $source->key) {
			foreach (new MultiSiteCopy(array('source_node_id' => (int)$source->key)) as $c) {
				$try('copy #' . $c->key, function () use ($c) { $c->permanent_delete(); });
			}
		}
		foreach (array_keys($node_ids) as $id) {
			$try('node #' . $id, function () use ($id) {
				$n = new ManagedNode($id, TRUE);
				if ($n->key) { $n->remove_permanently(); }
			});
		}
		// What the nodes' removal left of their provisions (it removes their own).
		$exists = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM cvp_customer_cloud_provisions WHERE cvp_customer_cloud_provision_id = ?');
		foreach (array_keys($provisions) as $pid) {
			$exists->execute(array($pid));
			if (!$exists->fetchColumn()) { continue; }
			$try('provision #' . $pid, function () use ($pid) {
				$p = new CustomerCloudProvision($pid, TRUE);
				if ($p->key) { $p->permanent_delete(); }
			});
		}
		foreach ($instances as $id => $label) {
			$r = AgentLiveProofRun::run_local(array(PHP_BINARY, dirname(PathHelper::getRootDir()) . '/maintenance_scripts/dev_tools/test_server.php',
				'delete', (string)$id), 300);
			// Gone already (a copy discarded before its server was made) is gone.
			if ($r['exit'] !== 0 && stripos($r['out'], 'no server') === false) {
				$left[] = 'server ' . $label . ' (' . $id . '): ' . trim(substr($r['out'], -300));
			}
		}
		// The run's join requests: the source's and its copy's both claim the site's name.
		foreach ($server ? array_unique(array_filter(array((string)$server['label'], (string)($server['site'] ?? '')), 'strlen')) : array() as $named) {
			$try('the join requests named ' . $named, function () use ($named) {
				foreach (new MultiAgentJoinRequest(array('ajr_claimed_name' => $named)) as $r) {
					$r->permanent_delete();
				}
			});
		}
		$try('the records of ' . $domain, function () use ($domain) { self::delete_records($domain); });
		if ($server && !empty($server['password_file']) && is_file($server['password_file'])) {
			@unlink($server['password_file']);
		}
		return $left;
	}
}
