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
 * It reads nothing outside $dir and the key file. It does not know what a
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
 * @version 1.0
 */
class PackageSignature {

	/** The manifest and its detached signature, at the root of each artifact. */
	const MANIFEST_NAME  = 'RELEASE_MANIFEST';
	const SIGNATURE_NAME = 'RELEASE_MANIFEST.sig';

	/** Where a node keeps the public keys it verifies against, under the site root. */
	const KEYS_FILE = 'config/release_verify_keys';

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
	 * @param string      $dir       The unpacked artifact: its RELEASE_MANIFEST is at $dir/RELEASE_MANIFEST
	 * @param string|null $keys_file The key file; the node's own when null
	 */
	public static function verify(string $dir, ?string $keys_file = null): PackageVerdict {
		$dir = rtrim($dir, '/');
		$own_keys = ($keys_file === null);
		$keys_file = $keys_file ?? self::keysPath();

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

		return new PackageVerdict(self::SIGNED,
			count($listed) . ' file(s) verified', $root, $verified_by, null, count($listed));
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
