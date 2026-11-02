<?php
/**
 * ReleaseHistoryMap - which public commit stands for a release's commit after
 * the public history was rewritten (spec specs_private_repo, D3-D6).
 *
 * Every logged release names its core commit by hash, in a statement in the
 * public log. A history rewrite gives every commit a new hash, so the old ones
 * stop being on the public main branch. The rewrite leaves every shipped file
 * byte for byte as it was, so a release only needs a trusted answer to "which
 * new commit stands for this old one". This class reads that answer.
 *
 * THE FILE. maintenance_scripts/install_tools/release_history/commit_map.json,
 * committed on the rewritten main and shipped in the core archive:
 *
 *   {"format": "joinery-commit-map-1", "rewrites": [ {envelope, entry}, ... ]}
 *
 * Each rewrite is a DSSE envelope of type TransparencyProof::COMMIT_MAP_TYPE,
 * signed with a statement key and written to the public log, with the stored
 * log entry beside it, exactly as a RELEASE_STATEMENT stores its own. Its
 * payload:
 *
 *   {"repository": CORE_REPO, "rewritten_at": "...Z", "reason": "...",
 *    "commits": [{"version": "0.8.466", "old": "<40 hex>", "new": "<40 hex>"}, ...]}
 *
 * Rewrites are in order; a later one appends, and a lookup follows them
 * (old -> new -> newer).
 *
 * TWO READERS.
 *  - verifiedFor(): the release verifier, which reads the map from the public
 *    repository. A rewrite counts for a release only when its envelope
 *    verifies against a statement key that release itself trusts and its log
 *    entry against a log key the release lists (D4), and only for the version
 *    named.
 *  - current(): the links on the Updates and Releases pages and the notes
 *    drafter, which read the map this site installed. It arrived in a core
 *    archive the upgrade verified, or is the origin's own committed file;
 *    current() follows it without re-proving it, as ReleaseProvenance reads
 *    the RELEASE_STATEMENT without re-proving it.
 *
 * @version 1.0
 */

class ReleaseHistoryMapException extends Exception {}

class ReleaseHistoryMap {

	/** The map's path relative to the site root (the directory holding public_html). */
	const PATH = 'maintenance_scripts/install_tools/release_history/commit_map.json';

	const FORMAT = 'joinery-commit-map-1';

	/** @var array[] {envelope, entry, payload (decoded)} in order */
	private $rewrites;

	private function __construct(array $rewrites) {
		$this->rewrites = $rewrites;
	}

	/** A map with no rewrites: every commit stands for itself. */
	public static function none(): self {
		return new self(array());
	}

	/**
	 * The map this site carries, or none() when it carries none. A file that
	 * is there but unreadable throws: a broken map is not an empty one.
	 */
	public static function installed(?string $site_root = null): self {
		$path = rtrim($site_root ?? PathHelper::getSiteRoot(), '/') . '/' . self::PATH;
		if (!is_file($path)) {
			return self::none();
		}
		return self::parse((string)file_get_contents($path));
	}

	/** The map in $bytes. Checks its shape, not its signatures (verifiedFor() does). */
	public static function parse(string $bytes): self {
		$doc = json_decode($bytes, true);
		if (!is_array($doc) || ($doc['format'] ?? null) !== self::FORMAT || !is_array($doc['rewrites'] ?? null)) {
			throw new ReleaseHistoryMapException('the commit map is not the ' . self::FORMAT . ' form');
		}
		$rewrites = array();
		foreach (array_values($doc['rewrites']) as $i => $r) {
			$n = $i + 1;
			if (!is_array($r['envelope'] ?? null) || !is_array($r['entry'] ?? null)) {
				throw new ReleaseHistoryMapException("rewrite {$n} of the commit map has no envelope or no log entry");
			}
			if (($r['envelope']['payloadType'] ?? null) !== TransparencyProof::COMMIT_MAP_TYPE) {
				throw new ReleaseHistoryMapException("rewrite {$n} of the commit map is not a commit map envelope");
			}
			$payload = json_decode((string)base64_decode((string)($r['envelope']['payload'] ?? ''), true), true);
			if (!is_array($payload) || !is_array($payload['commits'] ?? null)) {
				throw new ReleaseHistoryMapException("rewrite {$n} of the commit map has no list of commits");
			}
			foreach ($payload['commits'] as $c) {
				if (!is_array($c) || !preg_match('/^\d+\.\d+\.\d+$/', (string)($c['version'] ?? ''))
						|| !self::isHash($c['old'] ?? null) || !self::isHash($c['new'] ?? null)) {
					throw new ReleaseHistoryMapException("rewrite {$n} of the commit map lists a commit that is not a version, an old hash and a new hash");
				}
			}
			$rewrites[] = array('envelope' => $r['envelope'], 'entry' => $r['entry'], 'payload' => $payload);
		}
		return new self($rewrites);
	}

	/** How many rewrites the map holds. */
	public function count(): int {
		return count($this->rewrites);
	}

	/**
	 * The commit that stands for $commit now: followed through every rewrite,
	 * or $commit itself when none lists it. Not proven; see the class comment.
	 */
	public function current(string $commit): string {
		$commit = strtolower($commit);
		foreach ($this->rewrites as $r) {
			if (($r['payload']['repository'] ?? null) !== ReleaseProvenance::CORE_REPO) { continue; }
			foreach ($r['payload']['commits'] as $c) {
				if ($c['old'] === $commit) {
					$commit = $c['new'];
					break;
				}
			}
		}
		return $commit;
	}

	/**
	 * The commit that stands for release $version's $commit, through only the
	 * rewrites that release trusts (D4): each envelope verifies against one of
	 * $statement_keys and its log entry against $log_keys, and each lists
	 * $commit under $version. Null when no rewrite lists it.
	 *
	 * A listed rewrite that does not verify throws, naming why: a map that
	 * claims to speak for this release and cannot prove it is a failure, not
	 * an absence.
	 *
	 * @param string[] $statement_keys  P-256 SubjectPublicKeyInfo DER: the release's statement keys
	 * @param array    $log_keys        origin => DER or list of DER: the release's log keys
	 * @return array{commit:string, hops:array[]}|null hops: {old, new, rewritten_at, log_origin, log_index}
	 */
	public function verifiedFor(string $version, string $commit, array $statement_keys, array $log_keys): ?array {
		$commit = strtolower($commit);
		$hops = array();
		foreach ($this->rewrites as $i => $r) {
			if (($r['payload']['repository'] ?? null) !== ReleaseProvenance::CORE_REPO) { continue; }
			$new = null;
			foreach ($r['payload']['commits'] as $c) {
				if ($c['old'] === $commit && (string)$c['version'] === $version) {
					$new = $c['new'];
					break;
				}
			}
			if ($new === null) { continue; }
			try {
				$proof = TransparencyProof::verifyEntry($r['envelope'], $r['entry'], $statement_keys, $log_keys,
					TransparencyProof::COMMIT_MAP_TYPE);
			} catch (TransparencyProofException $e) {
				throw new ReleaseHistoryMapException('rewrite ' . ($i + 1) . " of the commit map lists release {$version}, but it is not "
					. 'proven by a key that release trusts: ' . $e->getMessage());
			}
			$hops[] = array('old' => $commit, 'new' => $new, 'rewritten_at' => (string)($r['payload']['rewritten_at'] ?? ''),
				'log_origin' => $proof['origin'], 'log_index' => $proof['log_index']);
			$commit = $new;
		}
		return $hops === array() ? null : array('commit' => $commit, 'hops' => $hops);
	}

	/**
	 * A rewrite's payload, as bytes. Not canonicalised: the envelope carries
	 * these exact bytes.
	 *
	 * @param array[] $commits {version, old, new}
	 */
	public static function payload(array $commits, string $reason, ?int $now = null): string {
		if ($commits === array()) {
			throw new ReleaseHistoryMapException('a commit map lists at least one commit');
		}
		$list = array();
		foreach ($commits as $c) {
			if (!preg_match('/^\d+\.\d+\.\d+$/', (string)($c['version'] ?? '')) || !self::isHash($c['old'] ?? null) || !self::isHash($c['new'] ?? null)) {
				throw new ReleaseHistoryMapException('every commit in a map is a version, an old hash and a new hash');
			}
			$list[] = array('version' => (string)$c['version'], 'old' => (string)$c['old'], 'new' => (string)$c['new']);
		}
		return json_encode(array(
			'repository'   => ReleaseProvenance::CORE_REPO,
			'rewritten_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),
			'reason'       => $reason,
			'commits'      => $list,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	}

	/**
	 * The map file with one more rewrite appended: $existing is the file's
	 * current bytes, or '' when there is none yet.
	 */
	public static function append(string $existing, array $envelope, array $entry): string {
		$rewrites = array();
		if ($existing !== '') {
			self::parse($existing);
			$rewrites = json_decode($existing, true)['rewrites'];
		}
		$rewrites[] = array('envelope' => $envelope, 'entry' => $entry);
		$bytes = json_encode(array('format' => self::FORMAT, 'rewrites' => array_values($rewrites)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		self::parse($bytes);
		return $bytes;
	}

	private static function isHash($v): bool {
		return is_string($v) && preg_match('/^[0-9a-f]{40}$/', $v) === 1;
	}
}
