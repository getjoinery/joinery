<?php
/**
 * ReleaseVerifier - checks, from anywhere, that a published Joinery release
 * is the public code and is in the public log (spec release_transparency, D7).
 * utils/verify_release.php is its command line.
 *
 * WHAT IT PROVES. The release statement names the public commits a release
 * was built from (core C, agent A) and a hash for every artifact. The checks:
 *
 *  1. The statement is signed by a statement key the commit C lists, its log
 *     entry is proven under a checkpoint key C lists, and the keys it says the
 *     release installs are exactly the ones C's release_keys/ gives. A key
 *     chain, when present, walks from genesis.
 *  2. Every log key C lists is one Sigstore's trusted root publishes.
 *  3. The log, today, serves the same bytes at the entry's index, and the
 *     checkpoint it serves today is signed by that key. The bytes it serves
 *     are not proven under today's checkpoint (no consistency proof from the
 *     stored one); what proves the entry is in the log is check 1's
 *     inclusion proof under a signed checkpoint.
 *  4. Each plugin and theme manifest, rebuilt from C, is the one the
 *     statement records.
 *  5. The core archive the upgrade source serves is signed by a release key C
 *     lists, carries exactly what its manifest lists, and its manifest is the
 *     one the statement records; and every line of that manifest is a file in
 *     C with the same bytes, or a file publish builds that passes its own
 *     check (below).
 *  6. With the pinned Go toolchain, the agent rebuilt from A and the relay
 *     sealer rebuilt from C are byte-identical to what the statement records.
 *
 * WHAT PUBLISH BUILDS, and how each is checked: the agent binaries
 * (decompressed, against the statement; rebuilt in 6), the agent bundle's
 * manifest.json (its signatures verify, its keys are C's), the agent's
 * systemd unit (A's file), the support bundle (the statement's hash; its own
 * signed manifest; every file in it from C or a relay sealer the statement
 * records), the support bundle's index, the relay sealer binaries and their
 * source stamp, the license copies (C's files) and every RELEASE_STATEMENT
 * (the statement itself).
 *
 * WHERE THINGS COME FROM. The commits are fetched from the public
 * repositories on GitHub, so what is checked is what anyone can read. The core
 * archive and the statement come from the release's upgrade source, which
 * serves them without a credential. Plugin and theme archives are not
 * needed: their manifests are rebuilt from C.
 *
 * It runs with no site: no settings, no database. Core classes resolve by
 * name (ClassAutoloader::restrictToCore()); the four plugin classes it reuses
 * are loaded by path.
 *
 * @version 1.2 - the core check runs both ways: every file the core commit ships must be listed, so an archive
 *                that drops one is refused (WP7 review B5)
 * @version 1.1 - rebuilds with the official toolchain each go.mod pins, fetched checksum-verified by any
 *                local Go (GoBinaryPublisher::officialGo); the statement's go_toolchain must be the agent's pin
 * @version 1.0
 */
class ReleaseVerifier {

	/** @var array options, see __construct() */
	private $opts;

	/** @var callable(string): void */
	private $out;

	/** @var array[] {status: ok|fail|skip, what, detail} */
	private $results = array();

	private $work;
	private $statement_bytes;
	private $payload;
	private $c_dir;
	private $a_dir;
	private $core_dir;
	private $keys;
	private $c_hashes = array();

	/**
	 * @param array $opts version (required), source (the upgrade source's URL), statement (a
	 *                    RELEASE_STATEMENT file instead of the core archive's), core_archive (a local
	 *                    archive instead of fetching), core_repo / agent_repo (a local git clone instead
	 *                    of fetching from GitHub), rebuild (bool, default true), go (path), keep (bool),
	 *                    offline (bool: nothing is fetched; the commits come from the local clones, and the checks
	 *                    that need GitHub or Sigstore are reported as not run). For tests: core_url / agent_url (the
	 *                    public repositories), sigstore (false: Sigstore is not asked)
	 * @param callable|null $out one line of output
	 */
	public function __construct(array $opts, $out = null) {
		$this->opts = $opts + array('rebuild' => true, 'keep' => false);
		$this->out = $out ?: function ($line) { echo $line, "\n"; };
	}

	/** Run every check. True when none failed. */
	public function run() {
		self::requirePluginClasses();
		$this->work = sys_get_temp_dir() . '/joinery-verify-' . bin2hex(random_bytes(4));
		mkdir($this->work, 0700);
		try {
			$this->steps();
		} catch (ReleaseVerifyStop $e) {
			$this->fail($e->getMessage());
		} finally {
			if (empty($this->opts['keep'])) {
				exec('rm -rf ' . escapeshellarg($this->work));
			} else {
				$this->say('Working files kept in ' . $this->work);
			}
		}
		return $this->summary();
	}

	/** @return array[] every check: {status, what, detail} */
	public function results() {
		return $this->results;
	}

	private function steps() {
		$version = (string)($this->opts['version'] ?? '');
		if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
			throw new ReleaseVerifyStop('a version is three numbers, like 0.8.467');
		}

		$this->section("Release {$version}");
		$this->fetchCore($version);
		$this->readStatement($version);

		$this->section('The public commits');
		$this->c_dir = $this->checkout('core', $this->opts['core_url'] ?? ReleaseProvenance::CORE_REPO, $this->payload['core_commit'], $this->opts['core_repo'] ?? null);
		$this->a_dir = $this->checkout('agent', $this->opts['agent_url'] ?? ReleaseProvenance::AGENT_REPO, $this->payload['agent_commit'], $this->opts['agent_repo'] ?? null);

		$this->section('The statement and the public log');
		$this->checkStatement();
		if (!empty($this->opts['offline']) || (isset($this->opts['sigstore']) && !$this->opts['sigstore'])) {
			$this->skip('Sigstore was not asked (--offline): the commit\'s log keys were not compared with Sigstore\'s trusted root, '
				. 'and the live log was not read');
		} else {
			$this->checkTrustedRoot();
			$this->checkLiveLog();
		}

		$this->section('Plugins and themes, rebuilt from the commit');
		$this->checkExtensions();

		$this->section('The core archive');
		$this->checkCore();

		$this->section('Binaries, rebuilt from source');
		$this->checkRebuilds();
	}

	// ---- inputs -------------------------------------------------------------

	private function fetchCore($version) {
		$archive = (string)($this->opts['core_archive'] ?? '');
		if ($archive === '') {
			$source = rtrim((string)($this->opts['source'] ?? ''), '/');
			if (!preg_match('~^https?://[^/\s]+$~', $source)) {
				throw new ReleaseVerifyStop('name the upgrade source the release came from, as --source=https://example.com '
					. '(the Updates page of a site shows its own)');
			}
			$archive = $this->work . '/joinery-core-' . $version . '.tar.gz';
			$url = $source . '/static_files/joinery-core-' . $version . '.tar.gz';
			$status = self::download($url, $archive);
			if ($status !== 200) {
				throw new ReleaseVerifyStop("{$url} answered HTTP {$status}: the upgrade source does not serve this release's core archive");
			}
			$this->pass('Downloaded the core archive from ' . $source);
		}
		$this->core_dir = $this->work . '/core';
		mkdir($this->core_dir);
		self::run_cmd('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($this->core_dir), 'unpack the core archive');
	}

	private function readStatement($version) {
		$file = (string)($this->opts['statement'] ?? '');
		$path = $file !== '' ? $file : $this->core_dir . '/public_html/' . PackageSignature::STATEMENT_NAME;
		$bytes = is_file($path) ? (string)file_get_contents($path) : '';
		if ($bytes === '') {
			throw new ReleaseVerifyStop($file !== '' ? "{$file} is empty or missing"
				: 'the core archive carries no release statement: this release was not logged');
		}
		$doc = json_decode($bytes, true);
		$payload = is_array($doc) ? json_decode((string)base64_decode((string)($doc['envelope']['payload'] ?? ''), true), true) : null;
		if (!is_array($payload) || !preg_match('/^[0-9a-f]{40}$/', (string)($payload['core_commit'] ?? ''))
				|| !preg_match('/^[0-9a-f]{40}$/', (string)($payload['agent_commit'] ?? '')) || !is_array($payload['artifacts'] ?? null)) {
			throw new ReleaseVerifyStop('the release statement is not one a publish writes');
		}
		if ((string)($payload['version'] ?? '') !== $version) {
			throw new ReleaseVerifyStop("the statement is for release {$payload['version']}, not {$version}");
		}
		$this->statement_bytes = $bytes;
		$this->payload = $payload;
		$this->pass("The statement names release {$version}: core commit " . substr($payload['core_commit'], 0, 12)
			. ', agent commit ' . substr($payload['agent_commit'], 0, 12) . ', published ' . ($payload['published_at'] ?? 'at an unrecorded time'));
	}

	/**
	 * The commit's files, from a local clone when given, else from the public
	 * repository - once the commit is shown to be on the public main branch.
	 *
	 * Fetching a commit by its hash is not enough: GitHub serves any commit in
	 * a repository's fork network, and one force-pushed off every branch, by
	 * hash through the upstream URL. So the public main's history is fetched
	 * (commits only, no files) and the commit must be an ancestor of its tip,
	 * which is the test publish itself applies (ReleaseCommit::onRemote()).
	 * Offline, that test is reported as not run.
	 */
	private function checkout($label, $public_url, $commit, $local) {
		$dir = $this->work . '/' . $label . '-commit';
		mkdir($dir);
		$repo = $this->work . '/' . $label . '.git';
		$scratch = 'git -C ' . escapeshellarg($repo);
		self::run_cmd('git init -q --bare ' . escapeshellarg($repo) . ' && ' . $scratch . ' remote add origin ' . escapeshellarg($public_url),
			'create a scratch repository');

		if (!empty($this->opts['offline'])) {
			if (!$local) {
				throw new ReleaseVerifyStop("--offline reads the commits from local clones: pass --{$label}-repo=DIR");
			}
			$this->skip("The {$label} commit " . substr($commit, 0, 12) . ' was not checked against the public repository (--offline)');
		} else {
			exec($scratch . ' fetch -q --filter=tree:0 origin main 2>&1', $o, $code);
			if ($code !== 0) {
				throw new ReleaseVerifyStop("could not read the main branch of {$public_url}: " . implode(' | ', array_slice($o, -2)));
			}
			exec($scratch . ' merge-base --is-ancestor ' . escapeshellarg($commit) . ' refs/remotes/origin/main 2>&1', $o2, $code2);
			if ($code2 !== 0) {
				throw new ReleaseVerifyStop("the {$label} commit {$commit} is not on the main branch of the public repository {$public_url}");
			}
			$this->pass("The {$label} commit " . substr($commit, 0, 12) . " is on the main branch of {$public_url}");
		}

		if ($local) {
			$git = 'git -C ' . escapeshellarg($local);
			$where = $local;
		} else {
			$git = $scratch;
			$where = $public_url;
		}
		$tar = $this->work . '/' . $label . '.tar';
		exec($git . ' archive --format=tar -o ' . escapeshellarg($tar) . ' ' . escapeshellarg($commit) . ' 2>&1', $o3, $code3);
		if ($code3 === 0) {
			exec('tar -xf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $o4, $code3);
		}
		if ($code3 !== 0) {
			throw new ReleaseVerifyStop("could not read the {$label} commit {$commit} from {$where}");
		}
		return $dir;
	}

	// ---- the statement and the log ------------------------------------------

	private function checkStatement() {
		$this->keys = AgentDistPublisher::repoKeyLists($this->c_dir);
		try {
			ReleaseStatementPublisher::verifyDocument($this->statement_bytes, $this->keys, array());
		} catch (Exception $e) {
			throw new ReleaseVerifyStop($e->getMessage());
		}
		$doc = json_decode($this->statement_bytes, true);
		$this->pass('The statement is signed by a statement key the commit lists, and its log entry is proven under a log key the commit lists'
			. (!empty($doc['key_chain']) ? '; its key chain walks from the first logged release' : ''));
		$this->pass('The keys the release installs are exactly the ones in the commit\'s release_keys/ ('
			. count($this->keys['release_keys']) . ' release, ' . count($this->keys['statement_keys']) . ' statement, '
			. count($this->keys['log_keys']) . ' log)');
	}

	private function checkTrustedRoot() {
		try {
			$root = (new ReleaseLogClient(array(), null))->trustedRoot();
		} catch (Exception $e) {
			$this->fail('Could not read Sigstore\'s trusted root: ' . $e->getMessage());
			return;
		}
		foreach ($this->keys['log_keys'] as $pair) {
			$this->check(ReleaseLogClient::rootListsKey($root, $pair['origin'], (string)base64_decode($pair['key'], true)),
				"Sigstore's trusted root publishes the commit's key for {$pair['origin']}",
				"the commit's key for {$pair['origin']} is not the one Sigstore publishes: a log key nobody can check");
		}
	}

	private function checkLiveLog() {
		$doc = json_decode($this->statement_bytes, true);
		$origin = (string)$doc['entry']['log_origin'];
		$index = (int)$doc['entry']['log_index'];
		$log_keys = array();
		foreach ($this->keys['log_keys'] as $pair) {
			$log_keys[$pair['origin']] = (string)base64_decode($pair['key'], true);
		}
		try {
			$reader = new LogTileReader();
			$checkpoint = $reader->checkpoint($origin, $log_keys);
			$served = $reader->entry($origin, $index, $checkpoint['tree_size']);
		} catch (Exception $e) {
			$this->fail("Could not read entry {$index} from {$origin}: " . $e->getMessage());
			return;
		}
		$this->check(hash_equals((string)base64_decode((string)$doc['entry']['leaf'], true), $served),
			"{$origin} serves this statement's entry at index {$index} today, and the checkpoint it serves today "
				. "({$checkpoint['tree_size']} entries) is signed by its key",
			"{$origin} serves different bytes at index {$index} than the statement's entry");
	}

	// ---- manifests ----------------------------------------------------------

	private function checkExtensions() {
		foreach ($this->payload['artifacts'] as $name => $recorded) {
			if (!preg_match('~^(plugin|theme)/([A-Za-z0-9_.-]+)$~', $name, $m)) {
				continue;
			}
			$root = 'public_html/' . ($m[1] === 'plugin' ? 'plugins' : 'theme') . '/' . $m[2];
			if (!is_dir($this->c_dir . '/' . $root)) {
				$this->fail("{$name}: the commit has no {$root}");
				continue;
			}
			$entries = PackageSignature::parse(TreeManifestPublisher::build($this->c_dir . '/' . $root, $this->c_dir));
			$missing = $this->addBuiltBinaries($root, $entries);
			if ($missing !== '') {
				$this->fail("{$name}: {$missing}");
				continue;
			}
			$subject = PackageSignature::statementSubject(TreeManifestPublisher::render($entries));
			$this->check(hash_equals($recorded, $subject), "{$name}: " . count($entries) . ' files, the manifest the statement records',
				"{$name}: the manifest rebuilt from the commit is not the one the statement records");
		}
	}

	/**
	 * The prebuilt Go binaries an extension ships (the relay sealer, in the
	 * mailbox plugin): each binary as the statement records it, and the stamp
	 * of the source in C it was built from. Returns '' or why it cannot.
	 */
	private function addBuiltBinaries($root, array &$entries) {
		$bin = RelaySealerPublisher::BIN_SUBDIR;
		if (strpos($bin . '/', $root . '/') !== 0) {
			return '';
		}
		foreach (array_keys(RelaySealerPublisher::ARCHES) as $machine) {
			$sha = $this->payload['artifacts']['relay-sealer/' . $machine] ?? null;
			if ($sha === null) {
				return "the statement records no relay-sealer/{$machine}";
			}
			$entries[$bin . '/' . RelaySealerPublisher::BINARY . '-' . $machine] = $sha;
		}
		$entries[$bin . '/' . RelaySealerPublisher::STAMP_FILE] = hash('sha256',
			RelaySealerPublisher::sourceHash($this->c_dir . '/' . RelaySealerPublisher::SOURCE_SUBDIR) . "\n");
		return '';
	}

	private function checkCore() {
		$keys_file = $this->work . '/release_verify_keys';
		file_put_contents($keys_file, implode("\n", $this->keys['release_keys']) . "\n");
		$log = array('required' => true, 'statement_keys' => array(), 'log_keys' => array());
		foreach ($this->keys['statement_keys'] as $b64) {
			$log['statement_keys'][] = (string)base64_decode($b64, true);
		}
		foreach ($this->keys['log_keys'] as $pair) {
			$log['log_keys'][$pair['origin']][] = (string)base64_decode($pair['key'], true);
		}
		$verdict = PackageSignature::verify($this->core_dir, $keys_file, array('fresh' => true, 'log' => $log));
		if (!$verdict->signed() || $verdict->root !== '') {
			$this->fail('The core archive does not verify: ' . $verdict->line());
			return;
		}
		$this->pass('The core archive is signed by a release key the commit lists, carries exactly what its manifest lists ('
			. (int)$verdict->files . ' files), and its manifest is the one the statement records');

		$listed = PackageSignature::parse((string)file_get_contents($this->core_dir . '/' . PackageSignature::MANIFEST_NAME));
		$from_commit = 0;
		$built = array();
		$bad = array();
		foreach ($listed as $rel => $sha) {
			$in_commit = $this->commitHash($rel);
			if ($in_commit !== null) {
				if (hash_equals($in_commit, $sha)) {
					$from_commit++;
				} else {
					$bad[] = "{$rel} is not the commit's file";
				}
				continue;
			}
			$why = $this->builtFile($rel, $this->core_dir . '/' . $rel);
			if ($why === null) {
				$bad[] = "{$rel} is neither in the commit nor a file publish builds";
			} elseif ($why === '') {
				$built[] = $rel;
			} else {
				$bad[] = "{$rel}: {$why}";
			}
		}
		// And the other direction: every file the commit ships into the core
		// archive is listed. A listing can only be checked line by line; an
		// archive that left a file out (a gate, a verifier helper) would
		// otherwise pass.
		$dropped = array();
		foreach ($this->coreFilesOfCommit() as $rel) {
			if (!isset($listed[$rel])) {
				$dropped[] = $rel;
			}
		}
		$this->check($dropped === array(), 'Every file the core commit ships is in the core manifest',
			'The core manifest leaves out ' . count($dropped) . ' file(s) the commit ships: ' . implode(', ', array_slice($dropped, 0, 10))
			. (count($dropped) > 10 ? ', and ' . (count($dropped) - 10) . ' more' : ''));

		$this->check($bad === array(), "Every line of the core manifest derives from the commits: {$from_commit} files are the commit's own, "
			. count($built) . ' are built by publish and pass their checks', implode('; ', array_slice($bad, 0, 10))
			. (count($bad) > 10 ? '; and ' . (count($bad) - 10) . ' more' : ''));
	}

	/**
	 * The files of commit C that publish puts in the core archive: public_html
	 * as publish copies it (its rsync exclusions; plugins/ and theme/ ship as
	 * their own archives; agent_dist is built and checked as built files), and
	 * maintenance_scripts/install_tools and sysadmin_tools, less what no
	 * manifest lists. Publish ships only files git knows, which is exactly the
	 * commit's own list.
	 */
	private function coreFilesOfCommit() {
		$out = array();
		exec('git -C ' . escapeshellarg($this->c_dir) . ' ls-files -z 2>/dev/null', $lines);
		$skip_names = array('specs', 'uploads', 'cache', 'logs', 'backups', '.playwright-mcp', 'theme-sources', '.claude', '.git', '.gitignore',
			'CLAUDE.md', 'GEMINI.md', 'AGENTS.md');
		foreach (explode("\0", implode("\n", $lines)) as $rel) {
			$rel = trim($rel, "\n");
			if ($rel === '') { continue; }
			if (strpos($rel, 'public_html/') === 0) {
				$parts = explode('/', substr($rel, strlen('public_html/')));
				if (in_array($parts[0], array('theme', 'plugins'), true) || strpos($parts[0], 'agent_dist') === 0
						|| array_intersect($parts, $skip_names)) {
					continue;
				}
			} elseif (strpos($rel, 'maintenance_scripts/install_tools/') === 0 || strpos($rel, 'maintenance_scripts/sysadmin_tools/') === 0) {
				if (array_intersect(explode('/', $rel), array('.git', '.gitignore'))) { continue; }
			} else {
				continue;
			}
			if (TreeManifestPublisher::excluded($rel)) { continue; }
			$out[] = $rel;
		}
		return $out;
	}

	/** sha256 of a path in commit C, or null when C has no such file. */
	private function commitHash($rel) {
		if (!array_key_exists($rel, $this->c_hashes)) {
			$path = $this->c_dir . '/' . $rel;
			$this->c_hashes[$rel] = (is_file($path) && !is_link($path)) ? hash_file('sha256', $path) : null;
		}
		return $this->c_hashes[$rel];
	}

	/**
	 * A file publish builds: '' when it passes its check, why when it does
	 * not, null when $rel is not a file publish builds.
	 */
	private function builtFile($rel, $abs) {
		$artifacts = $this->payload['artifacts'];
		if (basename($rel) === PackageSignature::STATEMENT_NAME) {
			return hash_equals(hash('sha256', $this->statement_bytes), hash_file('sha256', $abs)) ? '' : 'it is not this release\'s statement';
		}
		if ($rel === 'public_html/LICENSE.md' || $rel === 'public_html/LICENSE-BUSINESS.md') {
			$source = $this->commitHash(basename($rel));
			return ($source !== null && hash_equals($source, hash_file('sha256', $abs))) ? '' : 'it is not the commit\'s ' . basename($rel);
		}
		if (preg_match('~^public_html/agent_dist/joinery-agent-(linux-[a-z0-9]+)\.gz$~', $rel, $m)) {
			$raw = @gzdecode((string)file_get_contents($abs));
			$want = $artifacts['agent/' . $m[1]] ?? '';
			return ($raw !== false && $want !== '' && hash_equals($want, hash('sha256', $raw))) ? '' : "it is not the agent binary the statement records for {$m[1]}";
		}
		if ($rel === 'public_html/agent_dist/manifest.json') {
			return $this->agentManifest($abs);
		}
		if ($rel === 'public_html/agent_dist/joinery-agent.service') {
			$source = $this->a_dir . '/install/joinery-agent.service';
			return (is_file($source) && hash_equals(hash_file('sha256', $source), hash_file('sha256', $abs))) ? '' : 'it is not the agent commit\'s install/joinery-agent.service';
		}
		if ($rel === 'public_html/agent_dist/support_bundle.tar.gz') {
			return $this->supportBundle($abs);
		}
		if ($rel === 'public_html/agent_dist/support_bundle.json') {
			$info = json_decode((string)file_get_contents($abs), true);
			$tar = dirname($abs) . '/support_bundle.tar.gz';
			return (is_array($info) && is_file($tar) && ($info['sha256'] ?? '') === hash_file('sha256', $tar)
				&& (int)($info['bytes'] ?? -1) === filesize($tar) && ($info['file'] ?? '') === 'support_bundle.tar.gz')
				? '' : 'it does not describe the support bundle beside it';
		}
		return null;
	}

	/** The agent bundle's manifest.json: its binaries are the statement's and signed, its keys are the commit's. */
	private function agentManifest($abs) {
		$m = json_decode((string)file_get_contents($abs), true);
		if (!is_array($m) || !is_array($m['binaries'] ?? null)) {
			return 'it is not an agent bundle manifest';
		}
		$signer = (string)base64_decode((string)($m['signing_public_key'] ?? ''), true);
		if (!in_array((string)($m['signing_public_key'] ?? ''), $this->keys['release_keys'], true)) {
			return 'the binaries are signed by a key the commit does not list';
		}
		foreach ($m['binaries'] as $platform => $bin) {
			$gz = dirname($abs) . '/' . basename((string)($bin['file'] ?? ''));
			$raw = is_file($gz) ? @gzdecode((string)file_get_contents($gz)) : false;
			$sig = base64_decode((string)($bin['signature'] ?? ''), true);
			if ($raw === false || ($this->payload['artifacts']['agent/' . $platform] ?? '') !== ($bin['sha256'] ?? null)
					|| hash('sha256', $raw) !== $bin['sha256'] || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
					|| !sodium_crypto_sign_verify_detached($sig, $raw, $signer)) {
				return "its {$platform} binary is not the statement's, or its signature does not verify";
			}
		}
		foreach (array('release_keys', 'statement_keys', 'log_keys') as $list) {
			if (($m[$list] ?? null) !== $this->keys[$list]) {
				return "its {$list} are not the commit's";
			}
		}
		if (($m['go_toolchain'] ?? null) !== ($this->payload['go_toolchain'] ?? '')) {
			return 'it names a different Go toolchain than the statement';
		}
		if (isset($m['source_commit']) && $m['source_commit'] !== $this->payload['agent_commit']) {
			return 'it says the binaries were built from agent commit ' . $m['source_commit'] . ', not the one the statement names';
		}
		return '';
	}

	/** The support bundle: the statement's hash, its own signed manifest, every file from C or a relay sealer the statement records. */
	private function supportBundle($abs) {
		if (($this->payload['artifacts']['support_bundle'] ?? '') !== hash_file('sha256', $abs)) {
			return 'it is not the support bundle the statement records';
		}
		$dir = $this->work . '/support_bundle';
		@mkdir($dir);
		exec('tar -xzf ' . escapeshellarg($abs) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $o, $code);
		if ($code !== 0) {
			return 'it does not unpack';
		}
		$verdict = PackageSignature::verify($dir, $this->work . '/release_verify_keys', array('fresh' => true));
		if (!$verdict->signed()) {
			return 'its own manifest does not verify: ' . $verdict->line();
		}
		$sealers = array();
		foreach (array_keys(RelaySealerPublisher::ARCHES) as $machine) {
			$sealers[RelaySealerPublisher::BIN_SUBDIR . '/' . RelaySealerPublisher::BINARY . '-' . $machine] = $this->payload['artifacts']['relay-sealer/' . $machine] ?? '';
		}
		foreach (PackageSignature::parse((string)file_get_contents($dir . '/' . PackageSignature::MANIFEST_NAME)) as $rel => $sha) {
			$want = $sealers[$rel] ?? $this->commitHash($rel);
			if ($want === null || !hash_equals((string)$want, $sha)) {
				return "its {$rel} is neither the commit's file nor a relay sealer the statement records";
			}
		}
		return '';
	}

	// ---- rebuilds -----------------------------------------------------------

	private function checkRebuilds() {
		if (empty($this->opts['rebuild'])) {
			$this->skip('Rebuilding the binaries was turned off (--no-rebuild)');
			return;
		}
		// Any Go fetches the official toolchain each go.mod pins
		// (GoBinaryPublisher::officialGo), the one publish builds with.
		$downloader = (string)($this->opts['go'] ?? '') ?: trim((string)shell_exec('command -v go 2>/dev/null'));
		$cache = $this->work . '/gocache';
		$official = function ($src, $what) use ($downloader, $cache) {
			try {
				return GoBinaryPublisher::officialGo($downloader, $src, $cache . '/mod', $cache);
			} catch (Exception $e) {
				$this->skip("{$what} was not rebuilt: " . $e->getMessage());
				return null;
			}
		};

		$agent_manifest = json_decode((string)@file_get_contents($this->core_dir . '/public_html/agent_dist/manifest.json'), true);
		$want = (string)($this->payload['go_toolchain'] ?? '');
		if (!is_array($agent_manifest)) {
			$this->fail('The agent cannot be rebuilt: the core archive has no agent bundle manifest');
		} elseif (GoBinaryPublisher::pinnedToolchain($this->a_dir) !== $want) {
			$this->fail("The statement says the agent was built with {$want}, and the agent commit's go.mod pins "
				. (GoBinaryPublisher::pinnedToolchain($this->a_dir) ?? 'no toolchain'));
		} elseif (($go = $official($this->a_dir, 'The agent')) !== null) {
			$baked = AgentDistPublisher::bakedKeyFlags((array)($agent_manifest['baked_keys'] ?? array()));
			$ldflags = sprintf('-X main.version=%s -X main.updatePubKeyB64=%s -X main.releaseStatementKeysB64=%s -X main.releaseLogKeysB64=%s',
				$agent_manifest['version'] ?? '', $agent_manifest['signing_public_key'] ?? '', $baked['statement'], $baked['log']);
			foreach (array_keys($agent_manifest['binaries'] ?? array()) as $platform) {
				$goarch = substr($platform, strlen('linux-'));
				$this->compareBuild($go['go'], $this->a_dir, $goarch, $ldflags, $this->payload['artifacts']['agent/' . $platform] ?? '',
					"The agent for {$platform}, built from the agent commit");
			}
		}

		$src = $this->c_dir . '/' . RelaySealerPublisher::SOURCE_SUBDIR;
		if (!is_dir($src) || ($go = $official($src, 'The relay sealer')) === null) {
			return;
		}
		foreach (RelaySealerPublisher::ARCHES as $machine => $goarch) {
			$this->compareBuild($go['go'], $src, $goarch, '-s -w', $this->payload['artifacts']['relay-sealer/' . $machine] ?? '',
				"The relay sealer for {$machine}, built from the core commit");
		}
	}

	/** Build one Go program the way publish does, and compare its sha256 with the statement's. */
	private function compareBuild($go, $src, $goarch, $ldflags, $want, $what) {
		$cache = $this->work . '/gocache';
		@mkdir($cache . '/build', 0700, true);
		@mkdir($cache . '/mod', 0700, true);
		$out = $this->work . '/build-' . bin2hex(random_bytes(4));
		$cmd = sprintf('cd %s && env HOME=%s GOCACHE=%s GOMODCACHE=%s GOFLAGS=-modcacherw GOTOOLCHAIN=local CGO_ENABLED=0 GOOS=linux GOARCH=%s %s build -buildvcs=false -trimpath -ldflags %s -o %s . 2>&1',
			escapeshellarg($src), escapeshellarg($cache), escapeshellarg($cache . '/build'), escapeshellarg($cache . '/mod'),
			escapeshellarg($goarch), escapeshellarg($go), escapeshellarg($ldflags), escapeshellarg($out));
		exec($cmd, $output, $code);
		if ($code !== 0) {
			$this->fail("{$what}: the build failed: " . implode(' | ', array_slice($output, -3)));
			return;
		}
		$this->check($want !== '' && hash_equals($want, hash_file('sha256', $out)), "{$what} is byte-identical to the release's",
			"{$what} is not the binary the release ships");
	}

	// ---- results ------------------------------------------------------------

	private function summary() {
		$failed = array_filter($this->results, function ($r) { return $r['status'] === 'fail'; });
		$skipped = array_filter($this->results, function ($r) { return $r['status'] === 'skip'; });
		$this->say('');
		if ($failed) {
			$this->say('NOT VERIFIED: ' . count($failed) . ' check(s) failed.');
			return false;
		}
		$version = (string)($this->payload['version'] ?? $this->opts['version']);
		$doc = json_decode((string)$this->statement_bytes, true);
		$this->say("VERIFIED: release {$version} is built from public commit {$this->payload['core_commit']}"
			. " (agent {$this->payload['agent_commit']}) and is in the public log {$doc['entry']['log_origin']} at index {$doc['entry']['log_index']}."
			. ($skipped ? ' ' . count($skipped) . ' check(s) were not run; see above.' : ''));
		return true;
	}

	private function section($title) {
		$this->say('');
		$this->say($title);
	}

	private function check($ok, $what, $why) {
		$ok ? $this->pass($what) : $this->fail($why);
	}

	private function pass($what) { $this->record('ok', $what); }
	private function fail($what) { $this->record('fail', $what); }
	private function skip($what) { $this->record('skip', $what); }

	private function record($status, $what) {
		$this->results[] = array('status' => $status, 'what' => $what);
		$this->say(array('ok' => '  ok    ', 'fail' => '  FAIL  ', 'skip' => '  --    ')[$status] . $what);
	}

	private function say($line) {
		call_user_func($this->out, $line);
	}

	// ---- plumbing -----------------------------------------------------------

	/**
	 * The plugin classes this reuses. A plugin's classes resolve by name only
	 * on a site where it is active, and this runs with no site.
	 */
	public static function requirePluginClasses() {
		foreach (array('server_manager/includes/TreeManifestPublisher.php', 'server_manager/includes/AgentDistPublisher.php',
				'server_manager/includes/ReleaseStatementPublisher.php', 'mailbox/includes/RelaySealerPublisher.php') as $file) {
			require_once(PathHelper::getIncludePath('plugins/' . $file));
		}
	}

	private static function download($url, $path) {
		$fh = fopen($path, 'wb');
		$ch = curl_init($url);
		curl_setopt_array($ch, array(CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT => 600, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FAILONERROR => false));
		curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		fclose($fh);
		return $status;
	}

	private static function run_cmd($cmd, $what) {
		exec($cmd . ' 2>&1', $output, $code);
		if ($code !== 0) {
			throw new ReleaseVerifyStop("could not {$what}: " . implode(' | ', array_slice($output, -3)));
		}
	}
}

/** A check whose failure leaves nothing after it worth running. */
class ReleaseVerifyStop extends Exception {}
