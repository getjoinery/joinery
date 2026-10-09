<?php
/**
 * GoBinaryPublisher - cross-compiles one Go program into prebuilt binaries in
 * the tree at publish time, one per architecture, stamped with the source they
 * were built from.
 *
 * The machines that run these binaries have no compiler: a relay consumes a
 * prebuilt sealer, every node consumes a prebuilt parser-jail launcher. The
 * publish is the only place that both has the source and knows a release is
 * being cut, so it builds them, and the release archive carries them like any
 * other shipped file (hashed, covered by the signed manifest).
 *
 * A subclass names the program: where its source lives, where its binaries go,
 * what they are called, and where the build cache sits. Everything else — the
 * stamp, the ELF check, the staging swap, the statuses — is here once.
 *
 * Behaviour contract, the same shape as AgentDistPublisher:
 * - Source present, its hash differs from the recorded stamp (or a binary is
 *   missing): cross-compile both architectures, record the stamp.
 * - Source present, stamp matches, binaries plausible: leave bin/ BYTE
 *   IDENTICAL. A needless rebuild would move a tree hash and auto-bump a
 *   version on every publish.
 * - Source absent (a box publishing without it): nothing to do.
 * - A build was owed and did not happen: STATUS_FAILED. publish_upgrade.php
 *   refuses the release rather than shipping a consumer with nothing to run.
 *
 * There is no "carry forward" state. Source and binary ship together, so they
 * are either in step or they are not, and shipping them out of step is the one
 * outcome worth refusing a release over.
 *
 * publish() never throws — a broken build must not abort an unrelated platform
 * publish by exception; it reports a status and the caller decides.
 *
 * @version 1.4 - built binaries are written 0644: no installer runs one from the tree, and a 755 file
 *                under a converger that holds the tree at 644 is a permanent mode-only git diff
 * @version 1.3 - officialGo() names Go's proxy and checksum database itself rather than inheriting them
 * @version 1.2 - builds with the official toolchain go.mod pins, fetched by Go's checksum-verified
 *                download (officialGo()); the box's own Go only fetches it. A distribution's build of
 *                the pinned version compiles other bytes (spec release_transparency D3)
 * @version 1.1 - the build is pinned to the toolchain go.mod names: assertToolchain()
 *                refuses any other Go, and GOTOOLCHAIN=local keeps go from fetching
 *                one. A reproducible binary needs one compiler, not "a" compiler
 *                (spec release_transparency D3)
 * @version 1.0 - generalised from the relay sealer's publisher
 *
 * Test seam: $go_locator, so the no-toolchain refusal can be exercised without
 * uninstalling Go.
 */

abstract class GoBinaryPublisher {

	/** Site-relative directory holding the Go source (main.go and friends). */
	const SOURCE_SUBDIR = '';
	/** Site-relative directory the binaries are written to. */
	const BIN_SUBDIR = '';
	/** Binary name; the file is `<BINARY>-<uname -m>`. */
	const BINARY = '';
	/** Prefix for every line of output. */
	const LABEL = 'Go binary';
	/** Persistent build cache, so a root or user1 run does not depend on a home directory. */
	const CACHE_ROOT = '/var/tmp/joinery-go-build';

	/**
	 * Output name => GOARCH. The KEYS are `uname -m` names, not Go's, because
	 * the consumer is a shell script on a machine with uname and no Go, and
	 * asking it to translate x86_64 into amd64 would put a lookup table on the
	 * machine that exists to have nothing on it.
	 */
	const ARCHES = array('x86_64' => 'amd64', 'aarch64' => 'arm64');

	/**
	 * ELF e_machine, low byte at offset 18. Checked because a GOARCH mix-up
	 * produces a perfectly good binary under the wrong name — a wrong answer
	 * shaped like a right one, discovered on the consumer hours later rather
	 * than here.
	 */
	const ELF_MACHINE = array('x86_64' => 0x3e, 'aarch64' => 0xb7);

	/** Anything smaller than this is a stub, not a Go program. */
	const MIN_BYTES = 524288;

	/** Records the source the binaries in bin/ were built FROM. */
	const STAMP_FILE = '.source_sha256';

	/** @var callable|null fn(): ?string — test seam for findGo(). */
	public static $go_locator = null;

	/** A rebuild was required and completed. */
	const STATUS_BUILT = 'built';
	/** Source and binaries already agree; bin/ left byte-identical. */
	const STATUS_SKIPPED = 'skipped';
	/** No source on this box; nothing to build and nothing to ship. */
	const STATUS_ABSENT = 'absent';
	/** A rebuild was required and did not happen. bin/ is missing or stale. */
	const STATUS_FAILED = 'failed';

	/**
	 * Build (or leave alone) the prebuilt binaries.
	 *
	 * @param string $full_site_dir e.g. /var/www/html/joinerytest
	 * @param callable|null $out    line-output callback (publish_output)
	 * @return array{status:string, message:string, source_hash:?string, stamped_hash:?string}
	 */
	public static function publish($full_site_dir, $out = null) {
		$label = static::LABEL;
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
		// genuine build failure from a box that simply has no source.
		$build_required = false;
		$source_hash = null;
		$stamped_hash = null;
		$staging = null;

		try {
			$src = rtrim($full_site_dir, '/') . '/' . static::SOURCE_SUBDIR;
			$bin = rtrim($full_site_dir, '/') . '/' . static::BIN_SUBDIR;

			if (!is_dir($src) || !file_exists($src . '/main.go')) {
				$msg = $label . ': no source at ' . $src . ' - nothing to build';
				$say($msg);
				return $result(static::STATUS_ABSENT, $msg);
			}

			$source_hash = static::sourceHash($src);
			$stamped_hash = static::readStamp($bin);

			if ($stamped_hash === $source_hash && static::binariesPresent($bin) === null) {
				$msg = $label . ': binaries already current for this source - unchanged';
				$say($msg);
				return $result(static::STATUS_SKIPPED, $msg, $source_hash, $stamped_hash);
			}

			// Past this point bin/ is known to be wrong for this release.
			$build_required = true;

			$why = ($stamped_hash === null)
				? 'no binaries present'
				: (($stamped_hash === $source_hash)
					? (string)static::binariesPresent($bin)
					: 'source changed');
			$say($label . ': building for ' . implode(', ', array_keys(static::ARCHES)) . " ({$why})");

			// A box publishing a release owns the source it ships. A missing
			// toolchain here is a broken publishing box, not a reason to ship a
			// consumer with nothing to run.
			$official = static::officialGo(static::findGo(), $src, static::CACHE_ROOT . '/gomodcache', static::CACHE_ROOT);
			$go = $official['go'];
			$say($label . ': toolchain ' . $official['toolchain'] . ' (official, checksum-verified)');

			$staging = $bin . '.staging';
			static::rrmdir($staging);
			if (!mkdir($staging, 0755, true)) {
				throw new Exception("cannot create staging dir {$staging}");
			}

			foreach (static::ARCHES as $machine => $goarch) {
				$out_path = $staging . '/' . static::BINARY . '-' . $machine;
				static::buildBinary($go, $src, $goarch, $out_path);
				static::assertUsable($out_path, $machine);
				// Not executable in the tree: nothing runs a binary from here. Each
				// installer copies it into place and sets the mode it needs (the
				// jail's install -m 4755, the relay's chmod 755). A 755 file in
				// git stays dirty forever on a box whose converger holds the tree
				// at 644, and would be committed as a mode change on every publish.
				chmod($out_path, 0644);
				$say('  - ' . static::BINARY . '-' . $machine . ' ('
					. round(filesize($out_path) / 1048576, 1) . ' MB)');
			}

			if (file_put_contents($staging . '/' . static::STAMP_FILE, $source_hash . "\n") === false) {
				throw new Exception('cannot write the source stamp');
			}
			chmod($staging . '/' . static::STAMP_FILE, 0644);

			// Swap staging into place. The previous bin/ survives any failure
			// above, and comes back if the swap itself half-completes.
			$old = $bin . '.old';
			static::rrmdir($old);
			if (is_dir($bin) && !rename($bin, $old)) {
				throw new Exception('cannot move the previous bin/ aside');
			}
			if (!rename($staging, $bin)) {
				if (is_dir($old)) { rename($old, $bin); }
				throw new Exception('cannot move the new bin/ into place');
			}
			static::rrmdir($old);
			$staging = null;

			$msg = $label . ': built ' . count(static::ARCHES) . ' binaries into ' . static::BIN_SUBDIR;
			$say($msg);
			return $result(static::STATUS_BUILT, $msg, $source_hash, $stamped_hash);
		} catch (\Throwable $e) {
			if ($staging !== null) { static::rrmdir($staging); }

			if (!$build_required) {
				$msg = $label . ': WARNING - ' . $e->getMessage() . '; nothing was owed for this release';
				$say($msg);
				return $result(static::STATUS_ABSENT, $msg, $source_hash, $stamped_hash);
			}

			$msg = $label . ': FAILED to build - ' . $e->getMessage();
			$say($msg);
			return $result(static::STATUS_FAILED, $msg, $source_hash, $stamped_hash);
		}
	}

	/**
	 * Hash of the sources that actually go into the binary.
	 *
	 * `*_test.go` is excluded on purpose: it never reaches the binary, and
	 * including it would rebuild bin/, move a tree hash and auto-bump a
	 * version every time somebody edited a test.
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
		$raw = @file_get_contents($bin . '/' . static::STAMP_FILE);
		if ($raw === false) { return null; }
		$raw = trim($raw);
		return $raw === '' ? null : $raw;
	}

	/**
	 * Null when every architecture's binary is present and plausible, otherwise
	 * a short reason naming the first problem found.
	 */
	public static function binariesPresent($bin) {
		foreach (static::ARCHES as $machine => $goarch) {
			$path = $bin . '/' . static::BINARY . '-' . $machine;
			if (!file_exists($path)) {
				return static::BINARY . "-{$machine} is missing";
			}
			try {
				static::assertUsable($path, $machine);
			} catch (\Throwable $e) {
				return static::BINARY . "-{$machine}: " . $e->getMessage();
			}
		}
		return null;
	}

	/**
	 * The toolchain a Go source tree is pinned to: the `toolchain goX.Y.Z`
	 * line of its go.mod. Null when the line is absent.
	 */
	public static function pinnedToolchain($src) {
		$mod = @file_get_contents(rtrim($src, '/') . '/go.mod');
		if ($mod === false || !preg_match('/^toolchain\s+(go\d+\.\d+(?:\.\d+)?)\s*$/m', $mod, $m)) {
			return null;
		}
		return $m[1];
	}

	/**
	 * The official Go toolchain go.mod pins, as the path of its go binary.
	 *
	 * A version string does not name a compiler: a distribution's go1.22.2
	 * (Ubuntu's golang-1.22-go carries its own patches) compiles different
	 * bytes from the go1.22.2 the Go project publishes, and a verifier on
	 * another machine could match neither. So the build never uses the box's
	 * own Go. $downloader, any Go 1.21 or later, is asked for the pinned
	 * version with GOTOOLCHAIN; Go fetches the official toolchain as the
	 * golang.org/toolchain module, checks it against its public checksum
	 * database, and unpacks it in $modcache. That is the only place it can
	 * come from, so a GOROOT anywhere else - a downloader that is itself the
	 * pinned version, which Go then uses as it is - is refused.
	 *
	 * @return array{go:string, toolchain:string} the go binary and the version, e.g. "go1.27.2"
	 */
	public static function officialGo($downloader, $src, $modcache, $home) {
		$pinned = static::pinnedToolchain($src);
		if ($pinned === null) {
			throw new Exception('go.mod in ' . $src . ' has no toolchain line; add "toolchain goX.Y.Z" so the build is reproducible');
		}
		if ($downloader === null || $downloader === '') {
			throw new Exception("no Go found to fetch {$pinned} with (any Go 1.21 or later can)");
		}
		@mkdir($modcache, 0777, true);
		// The checksum database is named here, not inherited: a caller whose
		// environment turned it off (GOSUMDB=off, GONOSUMDB=golang.org, a
		// GOPROXY that is not Go's) would otherwise fetch an unverified
		// toolchain and nothing would say so.
		$cmd = sprintf('cd %s && env HOME=%s GOMODCACHE=%s GOFLAGS=-modcacherw GOSUMDB=sum.golang.org GONOSUMDB= '
			. 'GOPRIVATE= GONOPROXY= GOINSECURE= GOPROXY=https://proxy.golang.org GOTOOLCHAIN=%s %s env GOROOT GOVERSION 2>&1',
			escapeshellarg($src), escapeshellarg($home), escapeshellarg($modcache), escapeshellarg($pinned), escapeshellarg($downloader));
		exec($cmd, $out, $code);
		$goroot = (string)($out[count($out) - 2] ?? '');
		$version = (string)($out[count($out) - 1] ?? '');
		if ($code !== 0 || $version !== $pinned) {
			throw new Exception("could not fetch the official {$pinned} with {$downloader}: " . implode(' | ', array_slice($out, -3)));
		}
		$official = rtrim((string)realpath($modcache), '/') . '/golang.org/toolchain@v0.0.1-' . $pinned . '.';
		if (strpos($goroot, $official) !== 0) {
			throw new Exception("{$downloader} is itself {$pinned} ({$goroot}), so Go builds with it instead of fetching the official "
				. "{$pinned}, and another build of the same version compiles different bytes; fetch with a Go of any other version");
		}
		if (!is_executable($goroot . '/bin/go')) {
			throw new Exception("the official {$pinned} at {$goroot} has no runnable bin/go");
		}
		return array('go' => $goroot . '/bin/go', 'toolchain' => $pinned);
	}

	/** Locate the Go toolchain. */
	public static function findGo() {
		if (static::$go_locator !== null) {
			return call_user_func(static::$go_locator);
		}
		foreach (array('/usr/bin/go', '/usr/local/go/bin/go') as $candidate) {
			if (is_executable($candidate)) { return $candidate; }
		}
		$found = trim((string)shell_exec('command -v go 2>/dev/null'));
		return $found !== '' ? $found : null;
	}

	/**
	 * A built file is only usable if it is a big enough ELF for the very
	 * architecture whose name it carries. The consuming script checks the ELF
	 * magic too, but by then it is on the machine and the operator is watching
	 * a run fail; the publish is where this is cheap to catch.
	 */
	public static function assertUsable($path, $machine) {
		$size = @filesize($path);
		if ($size === false || $size < static::MIN_BYTES) {
			throw new Exception('implausibly small (' . (int)$size . ' bytes)');
		}
		$head = @file_get_contents($path, false, null, 0, 20);
		if ($head === false || strlen($head) < 20) {
			throw new Exception('unreadable');
		}
		if (substr($head, 0, 4) !== "\x7f" . 'ELF') {
			throw new Exception('not an ELF executable');
		}
		$want = static::ELF_MACHINE[$machine] ?? null;
		if ($want !== null && ord($head[18]) !== $want) {
			throw new Exception(sprintf(
				'built for the wrong architecture (ELF machine 0x%02x, expected 0x%02x)',
				ord($head[18]), $want));
		}
	}

	/** Cross-compile one architecture, statically, with no VCS stamping. */
	protected static function buildBinary($go, $src, $goarch, $out_path) {
		// Persistent caches so repeat publishes are fast and a root or user1 run
		// does not depend on either account's home directory. -buildvcs=false for
		// the reason AgentDistPublisher records: VCS stamping fails whenever the
		// publish runs as a user other than the repo's owner (git reports
		// "dubious ownership" and exits 128).
		$cache_root = static::CACHE_ROOT;
		@mkdir($cache_root . '/gocache', 0777, true);
		@mkdir($cache_root . '/gomodcache', 0777, true);

		$cmd = sprintf(
			'cd %s && env HOME=%s GOCACHE=%s GOMODCACHE=%s GOTOOLCHAIN=local CGO_ENABLED=0 GOOS=linux GOARCH=%s %s build -buildvcs=false -trimpath -ldflags %s -o %s . 2>&1',
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
	protected static function rrmdir($dir) {
		if (!is_dir($dir)) { return; }
		foreach (scandir($dir) ?: array() as $f) {
			if ($f === '.' || $f === '..') { continue; }
			$path = $dir . '/' . $f;
			is_dir($path) ? static::rrmdir($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}
?>
