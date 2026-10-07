<?php
/**
 * PackageSignature — root's answer to "did we build this?" before it puts code
 * on the box (specs/package_signing.md, WP2).
 *
 * Every archive the publisher ships carries a RELEASE_MANIFEST (the sha256 of
 * every file, paths relative to the site root) and a detached Ed25519
 * signature over it (RELEASE_MANIFEST.sig). TreeManifestPublisher writes the
 * pair; this class reads it back on the node, against the public keys in
 * config/release_verify_keys — a file root owns, written by the host converger
 * from the agent bundle's manifest and never by the web user.
 *
 * verify() answers with one verdict. Only `signed` means the bytes in $dir are
 * exactly the bytes a manifest our key signed describes: signature good, every
 * file present is listed with a matching hash, every listed file is present.
 * Anything else is a distinct verdict with a sentence for the operator, so a
 * refusal says WHY — a stranger's archive, a byte changed in transit, a file
 * smuggled in beside a signed set, a node with no key to check against.
 *
 * ON A NODE THAT REQUIRES THE LOG (config/release_log_required, and no
 * config/release_log_optional), `signed` is not enough: the package must also
 * carry a release statement that is in Sigstore's public log, checked offline
 * against the statement keys and log checkpoint keys the node already holds
 * (config/release_statement_keys, config/transparency_log_keys), never a key
 * read from the package. Anything less is `unlogged`.
 *
 * It reads nothing outside $dir and the key files. It does not know what a
 * plugin is, does not read the database, and does not care who is asking:
 * the callers (utils/upgrade.php, utils/install_extension.php, the host
 * converger through utils/verify_package.php) decide what a verdict means for
 * them. A verdict is a fact about bytes.
 *
 * WHAT A MANIFEST NEVER LISTS is decided here too, and TreeManifestPublisher
 * asks this class rather than keeping its own copy: the writer and the reader
 * have to agree on which paths are outside the promise, or every archive that
 * carries a config/ template or a .gitignore would fail its own verification.
 * The rule lives in core because the reader runs on nodes where the publisher
 * plugin is not active.
 *
 * @version 1.3 - the release log (spec release_transparency, D5, D6, WP4): on a node that requires it,
 *                `signed` also needs a release statement in the public log, else `unlogged`; the
 *                key chain is walked in memory and what it proves is returned as keys_proven for a
 *                root caller to persist (persistProvenKeys); a fresh archive refuses any member on
 *                a path no manifest lists (`fresh`)
 * @version 1.2 - STATEMENT_NAME, statementSubject() and statementLines(): the release statement is a
 *                listed file, and what it records for a manifest is that manifest without its
 *                statement lines, each of which must be that statement
 *                (spec release_transparency, D-F)
 * @version 1.1 - trustedListing() and droppedPaths(): the files one signed release shipped and the next
 *                does not, which an upgrade removes from maintenance_scripts/
 * @version 1.0
 */
class PackageSignature {

	/** The manifest and its detached signature, at the root of each artifact. */
	const MANIFEST_NAME  = 'RELEASE_MANIFEST';
	const SIGNATURE_NAME = 'RELEASE_MANIFEST.sig';

	/**
	 * The release statement (spec release_transparency, D4): the public log's
	 * record of the release, in every artifact beside its manifest. It is an
	 * ordinary listed file, so a verifier that has never heard of it sees a
	 * signed file with a matching hash. It cannot be outside the listing: every
	 * verifier refuses a file the manifest does not list.
	 */
	const STATEMENT_NAME = 'RELEASE_STATEMENT';

	/** Where a node keeps the public keys it verifies against, under the site root. */
	const KEYS_FILE = 'config/release_verify_keys';

	/** The P-256 statement keys a node holds: one base64 SubjectPublicKeyInfo per line. */
	const STATEMENT_KEYS_FILE = 'config/release_statement_keys';

	/** The log checkpoint keys a node holds: "<origin> <base64 SubjectPublicKeyInfo>" per line. */
	const LOG_KEYS_FILE = 'config/transparency_log_keys';

	/** Present: this node installs only logged releases (D6). Written by root, never removed. */
	const LOG_REQUIRED_FILE = 'config/release_log_required';

	/** Present, and root's: a fork owner's opt-out, created by hand. Nothing writes it. */
	const LOG_OPTIONAL_FILE = 'config/release_log_optional';

	/**
	 * The verdicts. `signed` is the only one that installs without a warning;
	 * the rest are the sentence in PackageVerdict::$detail.
	 */
	const SIGNED       = 'signed';        // the bytes are exactly what a key we trust signed
	const UNSIGNED     = 'unsigned';      // no manifest or no signature: nobody signed this
	const UNKNOWN_KEY  = 'unknown_key';   // a manifest and a signature, but not by a key we trust
	const NO_KEYS      = 'no_keys';       // this node has no key file, so nothing can be verified
	const TAMPERED     = 'tampered';      // a listed file's bytes differ from its signed hash
	const EXTRA_FILE   = 'extra_file';    // a file is present that the manifest does not list
	const MISSING_FILE = 'missing_file';  // a listed file is absent
	const UNREADABLE   = 'unreadable';    // the manifest or signature is not the format we write
	const UNLOGGED     = 'unlogged';      // signed by a key we trust, but not shown to be in the public log

	/**
	 * Paths never listed, matched against the site-root-relative path.
	 *
	 * Site-local mutable state (config/, logs/, cache/, uploads/, backups/) is
	 * excluded because it is not shipped and differs on every node — listing
	 * it would make every manifest wrong the moment the site ran. Development
	 * and packaging debris (.git, specs/, .claude) is excluded because it is
	 * not shipped either.
	 *
	 * Excluded at the TOP LEVEL only: config/, because a plugin may
	 * legitimately ship a config/ directory; and vendor/, because the site
	 * root's Composer tree is not shipped while a plugin's vendor/ is part of
	 * the plugin and has to be covered — a signed archive that could carry an
	 * unsigned Composer tree would be a signed archive carrying whatever it
	 * liked (specs/package_signing.md B3).
	 *
	 * The manifest and its signature exclude themselves, necessarily: a file
	 * cannot contain its own hash.
	 */
	const EXCLUDED_SEGMENTS  = array('.git', 'cache', 'logs', 'uploads', 'backups', 'specs', '.claude', 'node_modules');
	const EXCLUDED_TOP_LEVEL = array('config', 'vendor');
	const EXCLUDED_BASENAMES = array('.gitignore', self::MANIFEST_NAME, self::SIGNATURE_NAME);

	/** Whether a site-root-relative path is left out of a manifest. */
	public static function excluded(string $rel): bool {
		$segments = explode('/', $rel);
		if (in_array(basename($rel), self::EXCLUDED_BASENAMES, true)) {
			return true;
		}
		if (in_array($segments[0], self::EXCLUDED_TOP_LEVEL, true)) {
			return true;
		}
		foreach ($segments as $segment) {
			if (in_array($segment, self::EXCLUDED_SEGMENTS, true)) {
				return true;
			}
		}
		return false;
	}

	/** The node's key file. */
	public static function keysPath(): string {
		return PathHelper::getSiteRoot() . '/' . self::KEYS_FILE;
	}

	/**
	 * The node's release-log settings, as verify() takes them in its 'log'
	 * option: whether the log is required, and the statement and checkpoint
	 * keys held. A key file or an opt-out that someone other than root or the
	 * tree owner could have written is not trusted: a key file is read as
	 * empty, an opt-out as absent.
	 *
	 * @return array{required:bool, statement_keys:string[], log_keys:array<string,string[]>}
	 */
	public static function nodeLog(?string $site_root = null): array {
		$root = rtrim($site_root ?? PathHelper::getSiteRoot(), '/');
		$optional = $root . '/' . self::LOG_OPTIONAL_FILE;
		$statement = $root . '/' . self::STATEMENT_KEYS_FILE;
		$log = $root . '/' . self::LOG_KEYS_FILE;
		return array(
			'required'       => is_file($root . '/' . self::LOG_REQUIRED_FILE)
				&& !(is_file($optional) && self::keyFileRefusal($optional) === ''),
			'statement_keys' => (is_file($statement) && self::keyFileRefusal($statement) === '') ? self::readStatementKeys($statement) : array(),
			'log_keys'       => (is_file($log) && self::keyFileRefusal($log) === '') ? self::readLogKeys($log) : array(),
		);
	}

	/** The P-256 statement keys in a key file, as DER. Any other kind is skipped (D5). */
	public static function readStatementKeys(string $path): array {
		$keys = array();
		foreach (preg_split('/\r\n|\r|\n/', (string)@file_get_contents($path)) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') {
				continue;
			}
			$der = base64_decode($line, true);
			if ($der !== false && TransparencyProof::isP256($der) && !in_array($der, $keys, true)) {
				$keys[] = $der;
			}
		}
		return $keys;
	}

	/**
	 * The checkpoint keys in a key file: origin => DER[]. An origin may hold
	 * more than one: a log's key can rotate in place, and the file only grows.
	 * Lines that are not "<origin> <Ed25519 SubjectPublicKeyInfo>" are skipped.
	 */
	public static function readLogKeys(string $path): array {
		$keys = array();
		foreach (preg_split('/\r\n|\r|\n/', (string)@file_get_contents($path)) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || !preg_match('/^([a-z0-9.-]+)\s+(\S+)$/', $line, $m)) {
				continue;
			}
			$der = base64_decode($m[2], true);
			if ($der === false) {
				continue;
			}
			try {
				TransparencyProof::ed25519Raw($der);
			} catch (TransparencyProofException $e) {
				continue;
			}
			if (!in_array($der, $keys[$m[1]] ?? array(), true)) {
				$keys[$m[1]][] = $der;
			}
		}
		return $keys;
	}

	/**
	 * Append keys a verdict proved (PackageVerdict::$keys_proven) to the
	 * node's key files. For the root callers only - upgrade.php,
	 * install_extension.php, the converger - after a verdict that installs:
	 * verify() never writes (B9). Append-only, nothing removed; a key already
	 * held is not written twice. Returns the lines added.
	 */
	public static function persistProvenKeys(array $keys_proven, ?string $site_root = null): array {
		$root = rtrim($site_root ?? PathHelper::getSiteRoot(), '/');
		$added = array();
		$plan = array(
			self::STATEMENT_KEYS_FILE => array_map('base64_encode', $keys_proven['statement'] ?? array()),
			self::LOG_KEYS_FILE       => array(),
		);
		foreach ($keys_proven['log'] ?? array() as $origin => $ders) {
			foreach ((array)$ders as $der) {
				$plan[self::LOG_KEYS_FILE][] = $origin . ' ' . base64_encode($der);
			}
		}
		foreach ($plan as $rel => $lines) {
			if (!$lines) {
				continue;
			}
			$path = $root . '/' . $rel;
			$have = is_file($path) ? array_map('trim', preg_split('/\r\n|\r|\n/', (string)file_get_contents($path))) : array();
			$new = array_values(array_diff($lines, $have));
			if (!$new) {
				continue;
			}
			$existing = is_file($path) ? (string)file_get_contents($path) : '';
			$body = rtrim($existing, "\n") . ($existing !== '' ? "\n" : '') . implode("\n", $new) . "\n";
			if (@file_put_contents($path, $body) === false) {
				throw new RuntimeException('could not write ' . $path);
			}
			@chmod($path, 0644);
			foreach ($new as $line) { $added[] = $rel . ': ' . $line; }
		}
		return $added;
	}

	/**
	 * Whether this box is the publisher: it holds the secret half of the
	 * release key. Every plugin here is the source the archives are built
	 * from, and its live manifests are stale the moment a file is edited, so
	 * the publisher trusts its own tree where a node would verify — the host
	 * installers (WP5) and an install-by-name from disk. The file is root's
	 * and 0600; only its existence is asked.
	 */
	public static function publisherBox(): bool {
		return is_file(PathHelper::getSiteRoot() . '/config/agent_signing_key');
	}

	/**
	 * The public keys in a key file: one base64 Ed25519 public key per line,
	 * blank lines and `#` comments ignored, malformed lines skipped. Raw bytes.
	 *
	 * @return string[]
	 */
	public static function readKeys(string $path): array {
		if (!is_file($path)) {
			return array();
		}
		$keys = array();
		foreach (preg_split('/\r\n|\r|\n/', (string)@file_get_contents($path)) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') {
				continue;
			}
			$raw = base64_decode($line, true);
			if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
				$keys[base64_encode($raw)] = $raw;
			}
		}
		return array_values($keys);
	}

	/**
	 * The keys to verify against, or the sentence saying why there are none.
	 *
	 * The node's own file is trusted only when root could have written it and
	 * nobody else can: owned by root or by the tree owner, no group or other
	 * write bit. A key file the web user can write is a key file the web user
	 * can add a key to, and then every "signed" answer is theirs. The same
	 * guard the host converger puts on the scripts it runs. A file handed in
	 * explicitly (a test fixture, a gate's throwaway key) is the caller's.
	 *
	 * @return string[]|string
	 */
	private static function keysOrRefusal(string $keys_file, bool $own_keys) {
		if ($own_keys && is_file($keys_file)) {
			$refusal = self::keyFileRefusal($keys_file);
			if ($refusal !== '') {
				return $refusal;
			}
		}
		$keys = self::readKeys($keys_file);
		if ($keys === array()) {
			return 'this machine has no release verification key at ' . $keys_file
				. ', so no package can be verified here; the host converger writes it from the agent bundle';
		}
		return $keys;
	}

	/**
	 * Why a key file is not trusted, or '' when it is: owned by root or by the
	 * tree owner (root on a node, the developer's account on a developer box),
	 * and writable by nobody else.
	 *
	 * @param int|null $tree_owner Injected for tests; who owns public_html otherwise.
	 */
	public static function keyFileRefusal(string $keys_file, ?int $tree_owner = null): string {
		$owner = @fileowner($keys_file);
		$tree_owner = $tree_owner ?? @fileowner(PathHelper::getRootDir());
		$mode = @fileperms($keys_file);
		if ($owner === false || $mode === false
			|| ($owner !== 0 && $owner !== $tree_owner)
			|| ($mode & 0022) !== 0) {
			return 'the release verification key file at ' . $keys_file . ' is not root\'s alone '
				. '(owner uid ' . (int)$owner . ', mode ' . substr(sprintf('%o', (int)$mode), -4)
				. '); it is not trusted until the host converger rewrites it';
		}
		return '';
	}

	/**
	 * Verify the package in $dir.
	 *
	 * The manifest's paths are relative to the SITE ROOT whatever the artifact
	 * (a plugin's lists public_html/plugins/<name>/...), so the directory the
	 * manifest describes is derived from the listing: the longest directory
	 * prefix every entry shares. A core archive's is '' (it spans public_html/
	 * and maintenance_scripts/); a plugin's is public_html/plugins/<name>. The
	 * verdict carries it as $root, and a caller that expects a plugin checks
	 * it names one. When the derived root is not empty its last segment must
	 * be $dir's own name — a package whose manifest describes some other
	 * directory is not this package.
	 *
	 * Two options:
	 *  - 'fresh' => true: $dir is an archive just unpacked, not a live tree. A
	 *    live tree legitimately holds cache, uploads and the like on paths no
	 *    manifest lists, so the walk skips them; an archive has no reason to,
	 *    and any such member is `extra_file` (B6). The one allowance is the
	 *    core archive's config/default_Globalvars_site.php, which must be the
	 *    exact bytes of the listed install_tools copy.
	 *  - 'log' => nodeLog()'s shape: whether the release log is required and
	 *    the keys to check it with. Default: the node's own, when $keys_file
	 *    is the node's own; when a key file is handed in (a test, a gate's
	 *    throwaway key) the log is not required unless 'log' says so.
	 *
	 * @param string      $dir       The unpacked artifact: its RELEASE_MANIFEST is at $dir/RELEASE_MANIFEST
	 * @param string|null $keys_file The key file; the node's own when null
	 * @param array       $opts      'fresh' (bool), 'log' (see nodeLog())
	 */
	public static function verify(string $dir, ?string $keys_file = null, array $opts = array()): PackageVerdict {
		$dir = rtrim($dir, '/');
		$own_keys = ($keys_file === null);
		$keys_file = $keys_file ?? self::keysPath();
		$fresh = !empty($opts['fresh']);
		$log = $opts['log'] ?? ($own_keys ? self::nodeLog() : array('required' => false, 'statement_keys' => array(), 'log_keys' => array()));

		if (!is_dir($dir)) {
			return new PackageVerdict(self::UNREADABLE, 'there is no directory at ' . $dir . ' to verify');
		}

		$manifest_path  = $dir . '/' . self::MANIFEST_NAME;
		$signature_path = $dir . '/' . self::SIGNATURE_NAME;
		if (!is_file($manifest_path) || !is_file($signature_path)) {
			return new PackageVerdict(self::UNSIGNED,
				'the package carries no ' . self::MANIFEST_NAME . ' and signature, so nobody we know built it');
		}

		$keys = self::keysOrRefusal($keys_file, $own_keys);
		if (!is_array($keys)) {
			return new PackageVerdict(self::NO_KEYS, $keys);
		}

		$body = @file_get_contents($manifest_path);
		$signature = base64_decode(trim((string)@file_get_contents($signature_path)), true);
		if ($body === false || $body === '' || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			return new PackageVerdict(self::UNREADABLE,
				'the manifest or its signature is not in the format the publisher writes');
		}

		$verified_by = null;
		foreach ($keys as $key) {
			if (sodium_crypto_sign_verify_detached($signature, $body, $key)) {
				$verified_by = base64_encode($key);
				break;
			}
		}
		if ($verified_by === null) {
			return new PackageVerdict(self::UNKNOWN_KEY,
				'the manifest is signed, but not by any of the ' . count($keys) . ' key(s) this machine trusts');
		}

		// The signature is good, so the LISTING is trusted from here; what
		// remains is whether the bytes on disk are the bytes it lists.
		$listed = self::parse($body);
		if ($listed === null) {
			return new PackageVerdict(self::UNREADABLE, 'the manifest has a line that is not "<sha256>  <path>"');
		}

		$root = self::commonRoot(array_keys($listed));
		if ($root !== '' && basename($root) !== basename($dir)) {
			return new PackageVerdict(self::UNREADABLE,
				'the manifest describes ' . $root . ', which is not ' . basename($dir));
		}
		$prefix = $root === '' ? '' : $root . '/';

		// Walk $dir. Every file found must be listed with a matching hash,
		// unless the rule says a manifest never lists it. Symlinks are not
		// followed: a link is not a file we signed, whatever it points at.
		$seen = array();
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
			RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $entry) {
			$sub = substr($entry->getPathname(), strlen($dir) + 1);
			$rel = $prefix . $sub;
			if ($entry->isLink()) {
				return new PackageVerdict(self::EXTRA_FILE, 'a symlink is present at ' . $sub . ', which no manifest lists',
					$root, $verified_by, $sub);
			}
			if ($entry->isDir()) {
				continue;
			}
			if (self::excluded($rel)) {
				if ($fresh && !self::freshAllowance($sub, $rel, $listed, $entry->getPathname())) {
					return new PackageVerdict(self::EXTRA_FILE, 'a file is present that the manifest does not list: ' . $sub
						. ' (a path no manifest lists, which an archive has no reason to carry)', $root, $verified_by, $sub);
				}
				continue;
			}
			if (!isset($listed[$rel])) {
				return new PackageVerdict(self::EXTRA_FILE, 'a file is present that the manifest does not list: ' . $sub,
					$root, $verified_by, $sub);
			}
			$hash = @hash_file('sha256', $entry->getPathname());
			if ($hash === false || !hash_equals($listed[$rel], $hash)) {
				return new PackageVerdict(self::TAMPERED, 'the bytes of ' . $sub . ' are not the bytes that were signed',
					$root, $verified_by, $sub);
			}
			$seen[$rel] = true;
		}

		foreach ($listed as $rel => $hash) {
			if (!isset($seen[$rel])) {
				$sub = substr($rel, strlen($prefix));
				return new PackageVerdict(self::MISSING_FILE, 'a file the manifest lists is absent: ' . $sub,
					$root, $verified_by, $sub);
			}
		}

		$signed = new PackageVerdict(self::SIGNED,
			count($listed) . ' file(s) verified', $root, $verified_by, null, count($listed));
		if (self::statementLines($body) === array() && empty($log['required'])) {
			return $signed;
		}
		$logged = self::checkLogged($dir, $prefix, $body, $log);
		if ($logged['ok']) {
			$signed->detail .= ', logged publicly at ' . $logged['origin'] . ' index ' . $logged['index'];
			$signed->log = array('origin' => $logged['origin'], 'index' => $logged['index'], 'version' => $logged['version']);
			$signed->keys_proven = $logged['keys_proven'];
			return $signed;
		}
		if (!empty($log['required'])) {
			return new PackageVerdict(self::UNLOGGED, 'signed by a key this machine trusts, but not shown to be in the public log: '
				. $logged['why'], $root, $verified_by, null, count($listed));
		}
		return $signed;
	}

	/**
	 * Whether an excluded member may stand in a fresh archive: the manifest
	 * and signature at its top, and the core's config template when it is the
	 * listed install_tools copy byte for byte.
	 */
	private static function freshAllowance(string $sub, string $rel, array $listed, string $abs): bool {
		if ($sub === self::MANIFEST_NAME || $sub === self::SIGNATURE_NAME) {
			return true;
		}
		if ($rel === 'config/default_Globalvars_site.php') {
			$template = $listed['maintenance_scripts/install_tools/default_Globalvars_site.php'] ?? null;
			$hash = @hash_file('sha256', $abs);
			return $template !== null && $hash !== false && hash_equals($template, $hash);
		}
		return false;
	}

	/**
	 * D5's four checks on a package whose listing is already verified and
	 * whose files already match it: the statement it lists, its envelope, its
	 * leaf, its checkpoint, its inclusion proof, and that it records this
	 * manifest. Reads only $dir and the keys in $log; walks the key chain in
	 * memory, and returns what the walk proved without writing it (B9).
	 *
	 * @return array{ok:bool, why:string, origin:?string, index:?int, version:?string, keys_proven:array}
	 */
	private static function checkLogged(string $dir, string $prefix, string $body, array $log): array {
		$fail = function ($why) {
			return array('ok' => false, 'why' => $why, 'origin' => null, 'index' => null, 'version' => null, 'keys_proven' => array());
		};
		$lines = self::statementLines($body);
		if ($lines === array()) {
			return $fail('the package carries no ' . self::STATEMENT_NAME);
		}
		if (count(array_unique($lines)) !== 1) {
			return $fail('the package lists more than one ' . self::STATEMENT_NAME . ', and they differ');
		}
		$first = (string)array_key_first($lines);
		if ($prefix !== '' && strpos($first, $prefix) !== 0) {
			return $fail('the statement is listed outside the package');
		}
		$bytes = @file_get_contents($dir . '/' . substr($first, strlen($prefix)));
		// Every line statementSubject() leaves out must be this statement (B1).
		if ($bytes === false || !hash_equals(reset($lines), hash('sha256', $bytes))) {
			return $fail('the listed statement is not the file in the package');
		}
		$doc = json_decode($bytes, true);
		if (!is_array($doc) || !is_array($doc['envelope'] ?? null) || !is_array($doc['entry'] ?? null)) {
			return $fail('the statement is not the format the publisher writes');
		}

		$held_statement = array_values($log['statement_keys'] ?? array());
		$held_log = $log['log_keys'] ?? array();
		if ($held_statement === array() || $held_log === array()) {
			return $fail('this machine holds no statement key or no log key to check a statement with');
		}

		// The chain, forward from what this machine holds: a link that
		// verifies under the keys held so far adds the keys it installs; one
		// that does not adds nothing.
		$statement = $held_statement;
		$logs = $held_log;
		foreach (is_array($doc['key_chain'] ?? null) ? $doc['key_chain'] : array() as $link) {
			if (!is_array($link['envelope'] ?? null) || !is_array($link['entry'] ?? null)) {
				continue;
			}
			if (self::entryVerifies($link['envelope'], $link['entry'], $statement, $logs) !== '') {
				continue;
			}
			$installed = self::payloadKeys($link['envelope']);
			foreach ($installed['statement'] as $der) {
				if (!in_array($der, $statement, true)) { $statement[] = $der; }
			}
			foreach ($installed['log'] as $origin => $ders) {
				foreach ($ders as $der) {
					if (!in_array($der, $logs[$origin] ?? array(), true)) { $logs[$origin][] = $der; }
				}
			}
		}

		$why = self::entryVerifies($doc['envelope'], $doc['entry'], $statement, $logs);
		if ($why !== '') {
			return $fail($why);
		}
		$payload = json_decode((string)base64_decode((string)$doc['envelope']['payload'], true), true);
		$artifacts = is_array($payload['artifacts'] ?? null) ? $payload['artifacts'] : array();
		if (!in_array(self::statementSubject($body), array_values($artifacts), true)) {
			return $fail('the logged statement does not record this package');
		}

		$proven = array('statement' => array_values(array_diff($statement, $held_statement)), 'log' => array());
		foreach ($logs as $origin => $ders) {
			$new = array_values(array_filter($ders, function ($der) use ($held_log, $origin) {
				return !in_array($der, $held_log[$origin] ?? array(), true);
			}));
			if ($new) { $proven['log'][$origin] = $new; }
		}
		return array('ok' => true, 'why' => '', 'origin' => (string)$doc['entry']['log_origin'], 'index' => (int)$doc['entry']['log_index'],
			'version' => isset($payload['version']) ? (string)$payload['version'] : null, 'keys_proven' => $proven);
	}

	/** '' when the entry verifies against one of the held keys for its log, else why not. */
	private static function entryVerifies(array $envelope, array $entry, array $statement_keys, array $log_keys): string {
		$origin = (string)($entry['log_origin'] ?? '');
		$why = "the statement is logged on {$origin}, a log this machine holds no key for";
		foreach ($log_keys[$origin] ?? array() as $der) {
			try {
				TransparencyProof::verifyEntry($envelope, $entry, $statement_keys, array($origin => $der));
				return '';
			} catch (TransparencyProofException $e) {
				$why = $e->getMessage();
			}
		}
		return $why;
	}

	/** The statement and log keys a statement's payload installs, P-256 and Ed25519 only: {statement: DER[], log: origin => DER[]}. */
	private static function payloadKeys(array $envelope): array {
		$payload = json_decode((string)base64_decode((string)($envelope['payload'] ?? ''), true), true);
		$keys = is_array($payload['keys_installed'] ?? null) ? $payload['keys_installed'] : array();
		$out = array('statement' => array(), 'log' => array());
		foreach (is_array($keys['statement_keys'] ?? null) ? $keys['statement_keys'] : array() as $b64) {
			$der = base64_decode((string)$b64, true);
			if ($der !== false && TransparencyProof::isP256($der)) { $out['statement'][] = $der; }
		}
		foreach (is_array($keys['log_keys'] ?? null) ? $keys['log_keys'] : array() as $pair) {
			$der = base64_decode((string)($pair['key'] ?? ''), true);
			$origin = (string)($pair['origin'] ?? '');
			if ($der === false || !preg_match('/^[a-z0-9.-]+$/', $origin)) { continue; }
			try {
				TransparencyProof::ed25519Raw($der);
				if (!in_array($der, $out['log'][$origin] ?? array(), true)) { $out['log'][$origin][] = $der; }
			} catch (TransparencyProofException $e) {
				continue;
			}
		}
		return $out;
	}

	/**
	 * The listing of the manifest at $dir ([site-root-relative path => sha256])
	 * when a key this machine trusts signed it, or null. Reads the manifest and
	 * its signature and nothing else: it says what a release shipped, not
	 * whether the files are still there.
	 *
	 * @param string|null $keys_file The key file; the node's own when null
	 */
	public static function trustedListing(string $dir, ?string $keys_file = null): ?array {
		$dir = rtrim($dir, '/');
		$own_keys = ($keys_file === null);
		$keys = self::keysOrRefusal($keys_file ?? self::keysPath(), $own_keys);
		if (!is_array($keys)) {
			return null;
		}
		$body = @file_get_contents($dir . '/' . self::MANIFEST_NAME);
		$signature = base64_decode(trim((string)@file_get_contents($dir . '/' . self::SIGNATURE_NAME)), true);
		if ($body === false || $body === '' || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			return null;
		}
		foreach ($keys as $key) {
			if (sodium_crypto_sign_verify_detached($signature, $body, $key)) {
				return self::parse($body);
			}
		}
		return null;
	}

	/**
	 * The paths under $under (a site-root-relative directory, e.g.
	 * 'maintenance_scripts') that the previous release's listing names and the
	 * new one does not: the files a release stopped shipping. A path neither
	 * listing names is local, and is never among them.
	 */
	public static function droppedPaths(array $previous, array $new, string $under): array {
		$lead = trim($under, '/') . '/';
		$dropped = array();
		foreach (array_keys($previous) as $rel) {
			if (strpos($rel, $lead) === 0 && !isset($new[$rel])) {
				$dropped[] = $rel;
			}
		}
		sort($dropped);
		return $dropped;
	}

	/**
	 * The manifest body as [site-root-relative path => sha256], or null when a
	 * non-comment line is not two fields with a 64-hex hash first. The format
	 * is fixed by the agent's parser (primitives/manifest.go) and by
	 * TreeManifestPublisher::build().
	 */
	public static function parse(string $body): ?array {
		$out = array();
		foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') {
				continue;
			}
			if (!preg_match('/^([0-9a-f]{64})\s+(\S.*)$/', $line, $m)) {
				return null;
			}
			$path = str_replace('\\', '/', $m[2]);
			// A listing that reaches outside itself is not a listing we
			// would have written, and must not be resolved against anything.
			if ($path[0] === '/' || preg_match('~(^|/)\.\.($|/)~', $path)) {
				return null;
			}
			$out[$path] = $m[1];
		}
		return $out;
	}

	/**
	 * What a release statement records for a manifest: the sha256 (hex) of its
	 * body with every line listing a RELEASE_STATEMENT removed, every other
	 * byte as it is.
	 *
	 * A manifest lists its artifact's statement and the statement records the
	 * manifest, so one of them has to leave the other out. The statement is
	 * written after the manifest it describes; the manifest is then signed again
	 * with the statement's line in it. Removing that line gives back exactly the
	 * bytes the statement recorded, and also exactly what anyone regenerating
	 * the manifest from the public commit gets, since no commit holds a
	 * statement.
	 */
	public static function statementSubject(string $body): string {
		$kept = array();
		foreach (explode("\n", $body) as $line) {
			if (preg_match('/^[0-9a-f]{64}\s+(\S.*)$/', $line, $m) && basename(str_replace('\\', '/', $m[1])) === self::STATEMENT_NAME) {
				continue;
			}
			$kept[] = $line;
		}
		return hash('sha256', implode("\n", $kept));
	}

	/**
	 * The lines statementSubject() leaves out: path => sha256 of every listed
	 * RELEASE_STATEMENT. Leaving them out is safe only when each one is the
	 * statement being verified, so a verifier checks every hash here equals
	 * sha256 of that statement's bytes. Otherwise a signed manifest could list
	 * other bytes under the name, at any depth, and ship them unlogged.
	 */
	public static function statementLines(string $body): array {
		$lines = array();
		foreach (explode("\n", $body) as $line) {
			if (preg_match('/^([0-9a-f]{64})\s+(\S.*)$/', $line, $m) && basename(str_replace('\\', '/', $m[2])) === self::STATEMENT_NAME) {
				$lines[str_replace('\\', '/', $m[2])] = $m[1];
			}
		}
		return $lines;
	}

	/** The longest directory prefix every listed path shares ('' when none). */
	public static function commonRoot(array $paths): string {
		if ($paths === array()) {
			return '';
		}
		$common = null;
		foreach ($paths as $path) {
			$dirs = explode('/', $path);
			array_pop($dirs);               // the file's own name is never part of the root
			if ($common === null) {
				$common = $dirs;
				continue;
			}
			$n = min(count($common), count($dirs));
			$keep = 0;
			while ($keep < $n && $common[$keep] === $dirs[$keep]) {
				$keep++;
			}
			$common = array_slice($common, 0, $keep);
			if ($common === array()) {
				return '';
			}
		}
		return implode('/', $common ?? array());
	}
}

/**
 * Thrown by a fetch-and-place step (PluginManager::refreshFromUpstream()) when
 * the package it downloaded did not verify. Carries the verdict so the caller
 * can print the same line the verifier would have.
 */
class PackageUnverifiedException extends Exception {
	/** @var PackageVerdict */
	public $verdict;

	public function __construct(PackageVerdict $verdict) {
		$this->verdict = $verdict;
		parent::__construct($verdict->line());
	}
}

/**
 * What PackageSignature::verify() found. $verdict is one of the
 * PackageSignature constants; $detail is the sentence for the operator.
 */
class PackageVerdict {
	/** @var string One of PackageSignature::SIGNED, UNSIGNED, ... */
	public $verdict;
	/** @var string One sentence saying why, for a transcript or a page. */
	public $detail;
	/** @var string The site-root-relative directory the manifest describes ('' for a site root). */
	public $root;
	/** @var string|null Base64 of the key the signature verified against, when it did. */
	public $key;
	/** @var string|null The file (relative to the package) the verdict is about, when one is. */
	public $file;
	/** @var int Files verified, on `signed`. */
	public $files;
	/** @var array|null {origin, index, version} of the public log entry, when the package was shown to be logged. */
	public $log = null;
	/**
	 * @var array Keys the key chain proved that the node did not hold:
	 *            {statement: DER[], log: origin => DER[]}. verify() never writes
	 *            them; a root caller persists them after installing (B9).
	 */
	public $keys_proven = array();

	public function __construct(string $verdict, string $detail, string $root = '',
			?string $key = null, ?string $file = null, int $files = 0) {
		$this->verdict = $verdict;
		$this->detail  = $detail;
		$this->root    = $root;
		$this->key     = $key;
		$this->file    = $file;
		$this->files   = $files;
	}

	public function signed(): bool {
		return $this->verdict === PackageSignature::SIGNED;
	}

	/** "<verdict>: <detail>", the line a transcript carries. */
	public function line(): string {
		return $this->verdict . ': ' . $this->detail;
	}
}
