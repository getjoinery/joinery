<?php
/**
 * RelaySealerPublisher - cross-compiles the relay-sealer binary into the
 * mailbox plugin artifact at publish time.
 *
 * A relay is a mail machine with no compiler: provision_relay.sh 2.9 consumes
 * a PREBUILT sealer from provisioning/bin/relay-sealer-<uname -m> and refuses
 * to proceed without one. Something has to produce those binaries, and the
 * publish is the only place that both has the source and knows a release is
 * being cut. So the mailbox plugin archive carries them, the same way the core
 * archive carries the agent artifact.
 *
 * Called by publish_upgrade.php beside AgentDistPublisher, before any plugin
 * archive is built, so the binaries are on disk in time to be hashed, covered
 * by the plugin's signed RELEASE_MANIFEST, and tarred with the plugin.
 *
 * Behaviour contract, deliberately the same shape as AgentDistPublisher:
 * - Source present, its hash differs from the recorded stamp (or a binary is
 *   missing): cross-compile both architectures, record the stamp.
 * - Source present, stamp matches, binaries plausible: leave bin/ BYTE
 *   IDENTICAL. This matters more here than it does for the agent — bin/ lives
 *   inside the plugin tree, so a needless rebuild would move the tree hash and
 *   auto-bump the mailbox plugin's version on every publish.
 * - Source absent (a box publishing without the mailbox plugin): nothing to do.
 * - A build was owed and did not happen: STATUS_FAILED. publish_upgrade.php
 *   refuses the release rather than shipping a plugin whose provisioning
 *   script would fail on the first relay that ran it.
 *
 * There is no "carry forward" state. The agent can carry a previous artifact
 * because its source lives outside the tree and may simply not be on this box;
 * the sealer's source ships IN the plugin, so source and binary are either in
 * step or they are not, and shipping them out of step is the one outcome worth
 * refusing a release over.
 *
 * publish() never throws — a broken sealer build must not abort an unrelated
 * platform publish by exception; it reports a status and the caller decides.
 *
 * @version 1.0
 *
 * Test seam: $go_locator, so the no-toolchain refusal can be exercised without
 * uninstalling Go.
 */

class RelaySealerPublisher {

	const SOURCE_SUBDIR = 'public_html/plugins/mailbox/provisioning/relay-sealer';
	const BIN_SUBDIR    = 'public_html/plugins/mailbox/provisioning/bin';

	/**
	 * Output name => GOARCH. The KEYS are `uname -m` names, not Go's, because
	 * the consumer is provision_relay.sh running on a relay: it has uname and
	 * no Go, and asking a shell script to translate x86_64 into amd64 would put
	 * a lookup table on the machine that exists to have nothing on it.
	 */
	const ARCHES = array('x86_64' => 'amd64', 'aarch64' => 'arm64');

	/**
	 * ELF e_machine, low byte at offset 18. Checked because a GOARCH mix-up
	 * produces a perfectly good binary under the wrong name — a wrong answer
	 * shaped like a right one, discovered as a mail delivery failure on a
	 * relay hours later rather than here.
	 */
	const ELF_MACHINE = array('x86_64' => 0x3e, 'aarch64' => 0xb7);

	/** Anything smaller than this is a stub, not the sealer (it is ~10 MB). */
	const MIN_BYTES = 524288;

	/** Records the source the binaries in bin/ were built FROM. */
	const STAMP_FILE = '.source_sha256';

	/** @var callable|null fn(): ?string — test seam for findGo(). */
	public static $go_locator = null;

	/** A rebuild was required and completed. */
	const STATUS_BUILT = 'built';
	/** Source and binaries already agree; bin/ left byte-identical. */
	const STATUS_SKIPPED = 'skipped';
	/** No sealer source on this box; nothing to build and nothing to ship. */
	const STATUS_ABSENT = 'absent';
	/** A rebuild was required and did not happen. bin/ is missing or stale. */
	const STATUS_FAILED = 'failed';

	/**
	 * Build (or leave alone) the prebuilt sealer binaries.
	 *
	 * @param string $full_site_dir e.g. /var/www/html/joinerytest
	 * @param callable|null $out    line-output callback (publish_output)
	 * @return array{status:string, message:string, source_hash:?string, stamped_hash:?string}
	 */
	public static function publish($full_site_dir, $out = null) {
		$say = function ($msg) use ($out) { if ($out) { call_user_func($out, $msg); } };

		$result = function ($status, $message, $source_hash = null, $stamped_hash = null) {
			return array(
				'status'       => $status,
				'message'      => $message,
				'source_hash'  => $source_hash,
				'stamped_hash' => $stamped_hash,
			);
		};

		// Set once a build is known to be owed, so the catch block can tell a
		// genuine build failure from a box that simply has no sealer source.
		$build_required = false;
		$source_hash = null;
		$stamped_hash = null;
		$staging = null;

		try {
			$src = rtrim($full_site_dir, '/') . '/' . self::SOURCE_SUBDIR;
			$bin = rtrim($full_site_dir, '/') . '/' . self::BIN_SUBDIR;

			if (!is_dir($src) || !file_exists($src . '/main.go')) {
				$msg = 'Relay sealer: no source at ' . $src . ' - nothing to build';
				$say($msg);
				return $result(self::STATUS_ABSENT, $msg);
			}

			$source_hash = self::sourceHash($src);
			$stamped_hash = self::readStamp($bin);

			if ($stamped_hash === $source_hash && self::binariesPresent($bin) === null) {
				$msg = 'Relay sealer: binaries already current for this source - unchanged';
				$say($msg);
				return $result(self::STATUS_SKIPPED, $msg, $source_hash, $stamped_hash);
			}

			// Past this point bin/ is known to be wrong for this release.
			$build_required = true;

			$why = ($stamped_hash === null)
				? 'no binaries present'
				: (($stamped_hash === $source_hash)
					? (string)self::binariesPresent($bin)
					: 'sealer source changed');
			$say('Relay sealer: building for ' . implode(', ', array_keys(self::ARCHES)) . " ({$why})");

			$go = self::findGo();
			if ($go === null) {
				// A box publishing a release owns the source it ships. A missing
				// toolchain here is a broken publishing box, not a reason to ship
				// a plugin whose provisioning script cannot run.
				throw new Exception('Go toolchain not found (install golang-go on the publishing box)');
			}

			$staging = $bin . '.staging';
			self::rrmdir($staging);
			if (!mkdir($staging, 0755, true)) {
				throw new Exception("cannot create staging dir {$staging}");
			}

			foreach (self::ARCHES as $machine => $goarch) {
				$out_path = $staging . '/relay-sealer-' . $machine;
				self::buildBinary($go, $src, $goarch, $out_path);
				self::assertUsable($out_path, $machine);
				chmod($out_path, 0755);
				$say('  - relay-sealer-' . $machine . ' ('
					. round(filesize($out_path) / 1048576, 1) . ' MB)');
			}

			if (file_put_contents($staging . '/' . self::STAMP_FILE, $source_hash . "\n") === false) {
				throw new Exception('cannot write the source stamp');
			}
			chmod($staging . '/' . self::STAMP_FILE, 0644);

			// Swap staging into place. The previous bin/ survives any failure
			// above, and comes back if the swap itself half-completes.
			$old = $bin . '.old';
			self::rrmdir($old);
			if (is_dir($bin) && !rename($bin, $old)) {
				throw new Exception('cannot move the previous bin/ aside');
			}
			if (!rename($staging, $bin)) {
				if (is_dir($old)) { rename($old, $bin); }
				throw new Exception('cannot move the new bin/ into place');
			}
			self::rrmdir($old);
			$staging = null;

			$msg = 'Relay sealer: built ' . count(self::ARCHES) . ' binaries into ' . self::BIN_SUBDIR;
			$say($msg);
			return $result(self::STATUS_BUILT, $msg, $source_hash, $stamped_hash);
		} catch (\Throwable $e) {
			if ($staging !== null) { self::rrmdir($staging); }

			if (!$build_required) {
				$msg = 'Relay sealer: WARNING - ' . $e->getMessage() . '; nothing was owed for this release';
				$say($msg);
				return $result(self::STATUS_ABSENT, $msg, $source_hash, $stamped_hash);
			}

			$msg = 'Relay sealer: FAILED to build - ' . $e->getMessage();
			$say($msg);
			return $result(self::STATUS_FAILED, $msg, $source_hash, $stamped_hash);
		}
	}

	/**
	 * Hash of the sources that actually go into the binary.
	 *
	 * `*_test.go` is excluded on purpose: it never reaches the binary, and
	 * including it would rebuild bin/, move the mailbox tree hash and auto-bump
	 * the plugin's version every time somebody edited a test.
	 */
	public static function sourceHash($src) {
		$files = glob($src . '/*.go') ?: array();
		foreach (array('go.mod', 'go.sum') as $extra) {
			if (file_exists($src . '/' . $extra)) { $files[] = $src . '/' . $extra; }
		}
		sort($files);

		$parts = array();
		foreach ($files as $file) {
			$name = basename($file);
			if (substr($name, -8) === '_test.go') { continue; }
			$parts[] = $name . ':' . hash_file('sha256', $file);
		}
		return hash('sha256', implode("\n", $parts));
	}

	/** The recorded source hash, or null when bin/ carries none. */
	public static function readStamp($bin) {
		$raw = @file_get_contents($bin . '/' . self::STAMP_FILE);
		if ($raw === false) { return null; }
		$raw = trim($raw);
		return $raw === '' ? null : $raw;
	}

	/**
	 * Null when every architecture's binary is present and plausible, otherwise
	 * a short reason naming the first problem found.
	 */
	public static function binariesPresent($bin) {
		foreach (self::ARCHES as $machine => $goarch) {
			$path = $bin . '/relay-sealer-' . $machine;
			if (!file_exists($path)) {
				return "relay-sealer-{$machine} is missing";
			}
			try {
				self::assertUsable($path, $machine);
			} catch (\Throwable $e) {
				return "relay-sealer-{$machine}: " . $e->getMessage();
			}
		}
		return null;
	}

	/** Locate the Go toolchain. Mailbox does not depend on server_manager. */
	public static function findGo() {
		if (self::$go_locator !== null) {
			return call_user_func(self::$go_locator);
		}
		foreach (array('/usr/bin/go', '/usr/local/go/bin/go') as $candidate) {
			if (is_executable($candidate)) { return $candidate; }
		}
		$found = trim((string)shell_exec('command -v go 2>/dev/null'));
		return $found !== '' ? $found : null;
	}

	/**
	 * A built file is only usable if it is a big enough ELF for the very
	 * architecture whose name it carries. provision_relay.sh checks the ELF
	 * magic too, but by then it is on the relay and the operator is watching a
	 * provisioning run fail; the publish is where this is cheap to catch.
	 */
	public static function assertUsable($path, $machine) {
		$size = @filesize($path);
		if ($size === false || $size < self::MIN_BYTES) {
			throw new Exception('implausibly small (' . (int)$size . ' bytes)');
		}
		$head = @file_get_contents($path, false, null, 0, 20);
		if ($head === false || strlen($head) < 20) {
			throw new Exception('unreadable');
		}
		if (substr($head, 0, 4) !== "\x7f" . 'ELF') {
			throw new Exception('not an ELF executable');
		}
		$want = self::ELF_MACHINE[$machine] ?? null;
		if ($want !== null && ord($head[18]) !== $want) {
			throw new Exception(sprintf(
				'built for the wrong architecture (ELF machine 0x%02x, expected 0x%02x)',
				ord($head[18]), $want));
		}
	}

	/** Cross-compile one architecture, statically, with no VCS stamping. */
	private static function buildBinary($go, $src, $goarch, $out_path) {
		// Persistent caches so repeat publishes are fast and a root or user1 run
		// does not depend on either account's home directory. -buildvcs=false for
		// the reason AgentDistPublisher records: VCS stamping fails whenever the
		// publish runs as a user other than the repo's owner (git reports
		// "dubious ownership" and exits 128).
		$cache_root = '/var/tmp/joinery-relay-sealer-build';
		@mkdir($cache_root . '/gocache', 0777, true);
		@mkdir($cache_root . '/gomodcache', 0777, true);

		$cmd = sprintf(
			'cd %s && env HOME=%s GOCACHE=%s GOMODCACHE=%s CGO_ENABLED=0 GOOS=linux GOARCH=%s %s build -buildvcs=false -trimpath -ldflags %s -o %s . 2>&1',
			escapeshellarg($src),
			escapeshellarg($cache_root),
			escapeshellarg($cache_root . '/gocache'),
			escapeshellarg($cache_root . '/gomodcache'),
			escapeshellarg($goarch),
			escapeshellarg($go),
			escapeshellarg('-s -w'),
			escapeshellarg($out_path)
		);
		$output = array();
		$exit_code = 0;
		exec($cmd, $output, $exit_code);
		if ($exit_code !== 0) {
			throw new Exception("go build for {$goarch} failed: " . implode(' | ', array_slice($output, -5)));
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
?>
