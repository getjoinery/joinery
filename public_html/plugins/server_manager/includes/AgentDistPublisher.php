<?php
/**
 * AgentDistPublisher - bundles the joinery-agent binary into the platform
 * release (specs/agent_release_channel.md).
 *
 * Called by publish_upgrade.php before plugin archives are built. Writes
 * plugins/server_manager/agent_dist/ (manifest.json + gzipped, Ed25519-signed
 * per-arch binaries + the systemd unit). The agent on every control plane
 * watches that directory and installs verified updates itself.
 *
 * Behavior contract:
 * - Agent source present and its version differs from the bundled manifest:
 *   cross-compile, sign, regenerate agent_dist.
 * - Agent source present, version unchanged: leave agent_dist byte-identical
 *   (keeps the server_manager plugin tree hash stable between publishes).
 * - Agent source absent (a control plane publishing without the agent repo):
 *   the existing agent_dist carries forward unchanged. Publishing never
 *   breaks; the agent version simply does not advance.
 * - Signing keypair is generated on first use at {site}/config/
 *   agent_signing_key(.pub) — zero-config, same pattern as provisioning_key.
 *
 * @version 1.2 - signing key escrowed on every load (idempotent), not only at first mint,
 *                with truthful source (generated vs migrated)
 * @version 1.1
 */

class AgentDistPublisher {

	const DEFAULT_SOURCE_PATH = '/home/user1/joinery-agent';
	const ARCHES = array('amd64', 'arm64');

	/**
	 * Bundle (or carry forward) the agent artifact. Never throws — a broken
	 * agent build must not abort a platform publish; problems are reported
	 * through $out and the previous artifact stays in place.
	 *
	 * @param string $full_site_dir e.g. /var/www/html/joinerytest
	 * @param callable|null $out    line-output callback (publish_output)
	 */
	public static function publish($full_site_dir, $out = null) {
		$say = function ($msg) use ($out) { if ($out) { call_user_func($out, $msg); } };

		try {
			$dist_dir = $full_site_dir . '/public_html/plugins/server_manager/agent_dist';
			$manifest = self::readManifest($dist_dir);
			$bundled_version = $manifest['version'] ?? null;

			$src = self::sourcePath();
			if (!is_dir($src) || !file_exists($src . '/main.go')) {
				if ($bundled_version) {
					$say("Agent artifact: source not present on this box - carrying forward v{$bundled_version}");
				} else {
					$say("Agent artifact: WARNING - no agent source at {$src} and no existing artifact; this release ships without an agent bundle");
				}
				return;
			}

			$agent_version = self::readSourceVersion($src);
			if ($agent_version === null) {
				$say("Agent artifact: WARNING - could not read agent version from {$src}/main.go; carrying forward" . ($bundled_version ? " v{$bundled_version}" : ' nothing'));
				return;
			}

			if ($agent_version === $bundled_version && self::artifactsPresent($dist_dir, $manifest)) {
				$say("Agent artifact: v{$agent_version} already bundled - unchanged");
				return;
			}

			$keys = self::ensureKeys($full_site_dir . '/config');
			$say("Agent artifact: building v{$agent_version} (was " . ($bundled_version ?: 'none') . ") - signing key read from config/agent_signing_key");

			$go = self::findGo();
			if ($go === null) {
				$say("Agent artifact: WARNING - Go toolchain not found; carrying forward" . ($bundled_version ? " v{$bundled_version}" : ' nothing'));
				return;
			}

			$staging = $dist_dir . '.staging';
			self::rrmdir($staging);
			if (!mkdir($staging, 0755, true)) {
				throw new Exception("cannot create staging dir {$staging}");
			}

			$binaries = array();
			foreach (self::ARCHES as $arch) {
				$raw_path = $staging . '/joinery-agent-linux-' . $arch;
				self::buildBinary($go, $src, $arch, $agent_version, $keys['public_b64'], $raw_path);

				$raw = file_get_contents($raw_path);
				if ($raw === false || strlen($raw) < 1024 * 1024) {
					throw new Exception("built binary for {$arch} is missing or implausibly small");
				}
				$signature = sodium_crypto_sign_detached($raw, $keys['secret']);
				// Round-trip check: never ship a bundle the agent would refuse.
				if (!sodium_crypto_sign_verify_detached($signature, $raw, $keys['public'])) {
					throw new Exception("self-verification of the {$arch} signature failed");
				}

				$gz_name = 'joinery-agent-linux-' . $arch . '.gz';
				if (file_put_contents($staging . '/' . $gz_name, gzencode($raw, 9)) === false) {
					throw new Exception("cannot write {$gz_name}");
				}
				unlink($raw_path);

				$binaries['linux-' . $arch] = array(
					'file'      => $gz_name,
					'sha256'    => hash('sha256', $raw),
					'signature' => base64_encode($signature),
				);
				$say('  - ' . $gz_name . ' (' . round(filesize($staging . '/' . $gz_name) / 1048576, 1) . ' MB)');
			}

			$service_src = $src . '/install/joinery-agent.service';
			if (file_exists($service_src)) {
				copy($service_src, $staging . '/joinery-agent.service');
			} else {
				$say('Agent artifact: WARNING - systemd unit missing from agent source; bundle ships without it');
			}

			$manifest_json = json_encode(
				array('version' => $agent_version, 'binaries' => $binaries),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			);
			if (file_put_contents($staging . '/manifest.json', $manifest_json . "\n") === false) {
				throw new Exception('cannot write manifest.json');
			}

			// Swap staging into place; the old artifact survives any failure above.
			$old = $dist_dir . '.old';
			self::rrmdir($old);
			if (is_dir($dist_dir) && !rename($dist_dir, $old)) {
				throw new Exception('cannot move previous agent_dist aside');
			}
			if (!rename($staging, $dist_dir)) {
				if (is_dir($old)) { rename($old, $dist_dir); }
				throw new Exception('cannot move new agent_dist into place');
			}
			self::rrmdir($old);

			$say("Agent artifact: bundled v{$agent_version} for " . implode(', ', self::ARCHES));
		} catch (\Throwable $e) {
			$say('Agent artifact: WARNING - ' . $e->getMessage() . '; previous artifact (if any) carried forward');
			if (isset($staging)) { self::rrmdir($staging); }
		}
	}

	/** Resolve the agent source path from settings, with the dev default. */
	public static function sourcePath() {
		$settings = Globalvars::get_instance();
		$configured = $settings->get_setting('server_manager_agent_source_path');
		return $configured ?: self::DEFAULT_SOURCE_PATH;
	}

	/** Parse the agent's own version out of main.go. */
	public static function readSourceVersion($src) {
		$main = @file_get_contents($src . '/main.go');
		if ($main && preg_match('/var\s+version\s*=\s*"([^"]+)"/', $main, $m)) {
			return $m[1];
		}
		return null;
	}

	/** Read agent_dist/manifest.json; returns null when absent/unreadable. */
	public static function readManifest($dist_dir) {
		$raw = @file_get_contents($dist_dir . '/manifest.json');
		if ($raw === false) { return null; }
		$m = json_decode($raw, true);
		return is_array($m) ? $m : null;
	}

	/** True when every binary the manifest names exists on disk. */
	public static function artifactsPresent($dist_dir, $manifest) {
		if (!$manifest || empty($manifest['binaries'])) { return false; }
		foreach ($manifest['binaries'] as $entry) {
			if (empty($entry['file']) || !file_exists($dist_dir . '/' . $entry['file'])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Load the Ed25519 signing keypair, generating it on first use.
	 * Secret key: {config_dir}/agent_signing_key (base64, 0600).
	 * Public key: {config_dir}/agent_signing_key.pub (base64).
	 *
	 * @return array{secret:string, public:string, public_b64:string}
	 */
	public static function ensureKeys($config_dir) {
		$secret_path = $config_dir . '/agent_signing_key';
		$public_path = $secret_path . '.pub';

		if (!file_exists($secret_path)) {
			if (!is_dir($config_dir) || !is_writable($config_dir)) {
				throw new Exception("config dir {$config_dir} is not writable; cannot create agent signing key");
			}
			$pair = sodium_crypto_sign_keypair();
			$secret_b64 = base64_encode(sodium_crypto_sign_secretkey($pair));
			$public_b64 = base64_encode(sodium_crypto_sign_publickey($pair));
			// Create the file 0600 BEFORE writing key material so the secret is
			// never briefly world-readable (P-21).
			if (file_put_contents($secret_path, '') === false) {
				throw new Exception("cannot write {$secret_path}");
			}
			if (chmod($secret_path, 0600) === false) {
				@unlink($secret_path);
				throw new Exception("cannot secure {$secret_path} (chmod 600 failed)");
			}
			if (file_put_contents($secret_path, $secret_b64 . "\n") === false) {
				throw new Exception("cannot write {$secret_path}");
			}
			file_put_contents($public_path, $public_b64 . "\n");
			chmod($public_path, 0644);
			$minted = true;
		} else {
			$minted = false;
		}

		$secret_b64 = @file_get_contents($secret_path);
		if ($secret_b64 === false) {
			throw new Exception("cannot read {$secret_path} (created by another user? fix ownership)");
		}

		// Escrow the signing key on EVERY load, not just at mint: idempotent by
		// fingerprint, and this is what escrows a key that predates escrow being
		// configured (or a mint that happened before the recovery key was set).
		// Best-effort — never blocks a publish; the dashboard surfaces a key
		// that remains unescrowed.
		try {
			require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupKeyCustody.php'));
			BackupKeyCustody::escrowAgentSigningKey(trim($secret_b64), $minted ? 'generated' : 'migrated');
		} catch (\Throwable $e) {
			error_log('AgentDistPublisher: agent signing key escrow failed: ' . $e->getMessage());
		}

		$secret = base64_decode(trim($secret_b64), true);
		if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
			throw new Exception("agent signing key at {$secret_path} is malformed");
		}
		$public = sodium_crypto_sign_publickey_from_secretkey($secret);
		$public_b64 = base64_encode($public);
		// Keep the .pub sibling honest (it is what manual builds embed).
		$on_disk = @file_get_contents($public_path);
		if ($on_disk === false || trim($on_disk) !== $public_b64) {
			@file_put_contents($public_path, $public_b64 . "\n");
			@chmod($public_path, 0644);
		}

		return array('secret' => $secret, 'public' => $public, 'public_b64' => $public_b64);
	}

	/** Locate the Go toolchain. */
	public static function findGo() {
		foreach (array('/usr/bin/go', '/usr/local/go/bin/go') as $candidate) {
			if (is_executable($candidate)) { return $candidate; }
		}
		$found = trim((string)shell_exec('command -v go 2>/dev/null'));
		return $found !== '' ? $found : null;
	}

	/** Cross-compile one arch with the version and update public key baked in. */
	private static function buildBinary($go, $src, $arch, $version, $public_b64, $out_path) {
		// Persistent caches so repeat publishes are fast and root/user1 runs
		// do not depend on either account's home directory.
		$cache_root = '/var/tmp/joinery-agent-build';
		@mkdir($cache_root . '/gocache', 0777, true);
		@mkdir($cache_root . '/gomodcache', 0777, true);

		$ldflags = sprintf('-X main.version=%s -X main.updatePubKeyB64=%s', $version, $public_b64);
		$cmd = sprintf(
			'cd %s && env HOME=%s GOCACHE=%s GOMODCACHE=%s CGO_ENABLED=0 GOOS=linux GOARCH=%s %s build -trimpath -ldflags %s -o %s . 2>&1',
			escapeshellarg($src),
			escapeshellarg($cache_root),
			escapeshellarg($cache_root . '/gocache'),
			escapeshellarg($cache_root . '/gomodcache'),
			escapeshellarg($arch),
			escapeshellarg($go),
			escapeshellarg($ldflags),
			escapeshellarg($out_path)
		);
		exec($cmd, $output, $exit_code);
		if ($exit_code !== 0) {
			throw new Exception("go build for {$arch} failed: " . implode(' | ', array_slice($output, -5)));
		}
	}

	/** Recursively remove a directory if it exists. */
	private static function rrmdir($dir) {
		if (!is_dir($dir)) { return; }
		foreach (scandir($dir) ?: array() as $f) {
			if ($f === '.' || $f === '..') { continue; }
			$path = $dir . '/' . $f;
			is_dir($path) ? self::rrmdir($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}
