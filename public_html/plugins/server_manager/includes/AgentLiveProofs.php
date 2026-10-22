<?php
/**
 * AgentLiveProofs - which agent words a release changes, and whether each has
 * run once on a real machine (specs/fleet_security_patching.md WP7, Q5).
 *
 * On 2026-10-21 install_security_updates shipped having never run on Ubuntu:
 * its gate stubbed pgrep as "nothing running", and on every real machine it
 * waited ten minutes and said busy. A word is a root process the fleet runs
 * on the plane's say-so, so a new or changed one gets one live run on a real
 * test server before it ships, and the publish page warns about any that has
 * not (the owner's choice: a warning, not a block).
 *
 * A word has changed when, since the release this plane last published:
 *   - its Go file under the agent's primitives/ changed (git, from the commit
 *     the bundled agent was built from to the source's HEAD), or
 *   - the script it runs differs from the hash the signed RELEASE_MANIFEST
 *     holds for it.
 *
 * A proof is a row of agent_live_proofs.json beside the plugin: the word, the
 * agent version and the script's sha256 it ran with, where and when. It
 * covers a change when its agent version is the source's (for a Go change)
 * and its script hash is the script's now (for a script change).
 *
 * A proof is recorded by record(): by hand (maintenance_scripts/dev_tools/
 * record_live_proof.php) or by the live-tier test that makes a throwaway
 * server and runs each changed word through an agent built for the run
 * (AgentLiveProofRun, agent_live_proofs_live_test).
 *
 * @version 1.1 - record(): one place a proof is written, for the script and the live test
 * @version 1.0
 */
class AgentLiveProofs {

	/** The proof record, beside the plugin: committed with the change it proves. */
	public static function record_path(): string {
		return PathHelper::getIncludePath('plugins/server_manager/agent_live_proofs.json');
	}

	/** The proofs on record: [{word, agent_version, script_sha256, where, date, what}]. */
	public static function proofs(?string $path = null): array {
		$raw = @file_get_contents($path ?? self::record_path());
		$d = $raw !== false ? json_decode($raw, true) : null;
		return (is_array($d) && is_array($d['proofs'] ?? null)) ? array_values(array_filter($d['proofs'], 'is_array')) : array();
	}

	/**
	 * Every word the agent source registers, with its Go file and the script
	 * it runs (site-root relative), or '' for one that runs no script. Read
	 * from the source as text: each Register(Primitive{ ... Name: "x" ...
	 * ScriptPath: someConst }) in primitives/*.go, the const resolved in the
	 * same file.
	 *
	 * @return array<string, array{file: string, script: string}>
	 */
	public static function words(string $src): array {
		$out = array();
		foreach (glob(rtrim($src, '/') . '/primitives/*.go') ?: array() as $file) {
			if (substr($file, -8) === '_test.go') { continue; }
			$code = (string)file_get_contents($file);
			if (!preg_match_all('/Register\(Primitive\{(.*?)\n\t\}\)/s', $code, $blocks)) { continue; }
			foreach ($blocks[1] as $block) {
				if (!preg_match('/\bName:\s*"([a-z0-9_]+)"/', $block, $n)) { continue; }
				$script = '';
				if (preg_match('/\bScriptPath:\s*([A-Za-z0-9_]+|"[^"]+")/', $block, $sp)) {
					$ref = $sp[1];
					if ($ref[0] === '"') {
						$script = trim($ref, '"');
					} elseif (preg_match('/\b' . preg_quote($ref, '/') . '\s*=\s*"([^"]+)"/', $code, $c)) {
						$script = $c[1];
					}
				}
				$out[$n[1]] = array('file' => 'primitives/' . basename($file), 'script' => $script);
			}
		}
		ksort($out);
		return $out;
	}

	/** The sha256 the signed RELEASE_MANIFEST holds for each path. */
	public static function manifest_hashes(string $site_root): array {
		$out = array();
		$fh = @fopen(rtrim($site_root, '/') . '/RELEASE_MANIFEST', 'r');
		if (!$fh) { return $out; }
		while (($line = fgets($fh)) !== false) {
			$line = rtrim($line, "\r\n");
			if (strlen($line) > 66 && preg_match('/^[0-9a-f]{64}$/', substr($line, 0, 64))) {
				$out[substr($line, 66)] = substr($line, 0, 64);
			}
		}
		fclose($fh);
		return $out;
	}

	/**
	 * The words this release changes, and why: [word => ['go' => bool,
	 * 'script' => bool, 'script_path' => string, 'script_sha256' => string]].
	 * $since_commit is the agent commit the bundled agent was built from;
	 * null reads every word's Go as unchanged (no bundle to compare with).
	 */
	public static function changed(string $src, ?string $since_commit, string $site_root): array {
		$words = self::words($src);
		$go_changed = array();
		if ($since_commit !== null && preg_match('/^[0-9a-f]{7,40}$/', $since_commit)) {
			$r = ReleaseCommit::git($src, array('diff', '--name-only', $since_commit, 'HEAD', '--', 'primitives/'));
			if ($r['exit'] === 0) {
				foreach ($r['out'] as $f) { $go_changed[trim($f)] = true; }
			}
		}
		$hashes = self::manifest_hashes($site_root);
		$own_files = array();
		foreach ($words as $w) { $own_files[$w['file']] = true; }
		$out = array();
		foreach ($words as $word => $w) {
			// Its own file, or a file of its own beside it (observe_check_status_php.go
			// is check_status's): one whose name extends its file's.
			$stem = substr($w['file'], 0, -3);
			$go = false;
			foreach (array_keys($go_changed) as $f) {
				if ($f === $w['file'] || (strpos($f, $stem . '_') === 0 && substr($f, -8) !== '_test.go' && !isset($own_files[$f]))) { $go = true; break; }
			}
			$script = false;
			$sha = '';
			if ($w['script'] !== '') {
				$path = rtrim($site_root, '/') . '/' . $w['script'];
				$sha = is_file($path) ? (string)hash_file('sha256', $path) : '';
				// A script the last release did not carry, or carried otherwise, is new code.
				$script = ($sha !== '' && ($hashes[$w['script']] ?? '') !== $sha);
			}
			if ($go || $script) {
				$out[$word] = array('go' => $go, 'script' => $script, 'script_path' => $w['script'], 'script_sha256' => $sha);
			}
		}
		return $out;
	}

	/**
	 * The changed words no proof covers, each with what is missing:
	 * [word => reason]. A proof covers a Go change when it ran on
	 * $agent_version, and a script change when it ran that script's hash.
	 */
	public static function unproven(array $changed, string $agent_version, array $proofs): array {
		$out = array();
		foreach ($changed as $word => $c) {
			$covered = false;
			foreach ($proofs as $p) {
				if (($p['word'] ?? '') !== $word) { continue; }
				$go_ok = !$c['go'] || (($p['agent_version'] ?? '') === $agent_version);
				$script_ok = !$c['script'] || (($p['script_sha256'] ?? '') === $c['script_sha256']);
				if ($go_ok && $script_ok) { $covered = true; break; }
			}
			if (!$covered) {
				$what = array();
				if ($c['go']) { $what[] = 'its agent code changed (agent ' . $agent_version . ')'; }
				if ($c['script']) { $what[] = 'its script ' . $c['script_path'] . ' changed'; }
				$out[$word] = implode(' and ', $what);
			}
		}
		return $out;
	}

	/**
	 * What the publish page shows: the words this release would ship without
	 * a live run. Empty when nothing changed, every change is proven, or there
	 * is no agent source here to read.
	 */
	/**
	 * Record that a word ran once on a real machine: its script as it is now
	 * (its sha256), the agent version it ran on, where and what. One row per
	 * word, agent version and script; a later run replaces it.
	 *
	 * @return array the row written
	 */
	public static function record(string $word, string $agent_version, string $where, string $what, ?string $path = null): array {
		$src = AgentDistPublisher::sourcePath();
		$site_root = dirname(PathHelper::getRootDir());
		$words = self::words($src);
		if (!isset($words[$word])) {
			throw new Exception("the agent source at {$src} registers no word '{$word}'");
		}
		$script = $words[$word]['script'];
		$row = array(
			'word'          => $word,
			'agent_version' => $agent_version,
			'script'        => $script,
			'script_sha256' => $script !== '' && is_file($site_root . '/' . $script) ? hash_file('sha256', $site_root . '/' . $script) : '',
			'where'         => trim($where),
			'what'          => trim($what),
			'date'          => gmdate('Y-m-d'),
		);
		$path = $path ?? self::record_path();
		$d = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
		if (!is_array($d)) {
			$d = array('_about' => 'Agent words run once on a real machine before release (specs/fleet_security_patching.md WP7). '
				. 'Added with maintenance_scripts/dev_tools/record_live_proof.php or the live test agent_live_proofs_live; '
				. 'the publish page warns about a changed word with no row here.', 'proofs' => array());
		}
		$kept = array();
		foreach ((array)($d['proofs'] ?? array()) as $p) {
			if (($p['word'] ?? '') === $row['word'] && ($p['agent_version'] ?? '') === $row['agent_version'] && ($p['script_sha256'] ?? '') === $row['script_sha256']) { continue; }
			$kept[] = $p;
		}
		$kept[] = $row;
		$d['proofs'] = $kept;
		if (file_put_contents($path, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
			throw new Exception('the proof could not be written to ' . $path);
		}
		return $row;
	}

	public static function publish_warnings(): array {
		$src = AgentDistPublisher::sourcePath();
		if (!is_dir($src . '/primitives')) {
			return array();
		}
		$manifest = AgentDistPublisher::readManifest(PathHelper::getIncludePath('agent_dist'));
		$since = is_array($manifest) ? ($manifest['source_commit'] ?? null) : null;
		$version = (string)(AgentDistPublisher::readCommittedVersion($src) ?? '');
		return self::unproven(self::changed($src, is_string($since) ? $since : null, dirname(PathHelper::getRootDir())), $version, self::proofs());
	}
}
?>
