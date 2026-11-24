<?php
/**
 * UpstreamAdvisoryOpenssl - OpenSSL's own security advisories, from its CVE
 * records (specs/package_fixes_automatic.md WP2).
 *
 * OpenSSL's fix commits seldom name the CVE, so a search of commit messages
 * finds nothing. Its CVE records do name them: each is a CVE JSON 5 record
 * (the same one openssl-library.org serves at news/secjson/<cve>.json) whose
 * references hold one "<version> git commit" link per release branch,
 * https://github.com/openssl/openssl/commit/<sha>, and whose affected versions
 * end at each line's fixed release ("lessThan": "3.5.10"). Its rating is
 * OpenSSL's own (Low, Moderate, High, Critical).
 *
 * Read once an hour from the openssl/release-metadata repository, from which
 * OpenSSL publishes them, with no archive to unpack (no C parser on outside
 * bytes, tests/security/parser_surfaces_test.php): one GitHub API call lists
 * the repository (its security advisories are secadv/YYYYMMDD.txt), each
 * advisory of the last WINDOW_SECONDS is read for the CVEs it names, and each
 * of those CVEs' records is read, at most MAX_RECORDS: a handful of small
 * fetches from raw.githubusercontent.com. The watch raises only CVEs
 * published in its last 30 days. Any of them that cannot be read makes the
 * whole answer unread, so the watch keeps its last good one.
 *
 * OpenSSL publishes an advisory when the fix is in its public git, which can
 * be before the release that carries it is tagged (2026-11-24: fixes in the
 * openssl-3.5 branch, 3.5.10 scheduled for 1 December). "fixed" here is that
 * the record names fixed releases; UpstreamFixFinder uses the commits only
 * once the fixed release's signed tag exists.
 *
 * @version 1.0
 */
class UpstreamAdvisoryOpenssl implements UpstreamAdvisoryDriver {

	/** The repository's file list. */
	const URL = 'https://api.github.com/repos/openssl/release-metadata/git/trees/main?recursive=1';

	/** Its files, by path. */
	const RAW = 'https://raw.githubusercontent.com/openssl/release-metadata/main/';

	/** Advisories this recent are read: 120 days. */
	const WINDOW_SECONDS = 10368000;

	/** The most CVE records read in one look. */
	const MAX_RECORDS = 60;

	const RATINGS = array('critical' => 'critical', 'high' => 'high', 'moderate' => 'medium', 'medium' => 'medium', 'low' => 'low');

	/** @var callable(string $url): ?string */
	protected $fetcher;

	/** @var int|null */
	protected $now;

	/** @var array|null the last advisories read, which ids() and commits() answer from */
	protected $read = null;

	/**
	 * @param callable|null $fetcher tests only: fn(string $url): ?string
	 * @param int|null      $now     tests only
	 */
	public function __construct($fetcher = null, ?int $now = null) {
		$this->fetcher = $fetcher ?? array('UbuntuCveTracker', 'httpGet');
		$this->now = $now;
	}

	public function covers(string $source): bool {
		return $source === 'openssl';
	}

	public function label(): string {
		return 'OpenSSL';
	}

	public function advisories(): ?array {
		$list = json_decode((string)call_user_func($this->fetcher, self::URL), true);
		if (!is_array($list) || !is_array($list['tree'] ?? null)) {
			return null;
		}
		$now = $this->now ?? time();
		$records = array();
		foreach ($list['tree'] as $t) {
			$path = (string)($t['path'] ?? '');
			if (preg_match('~^secjson/(CVE-\d{4}-\d{4,})\.json$~', $path, $m)) { $records[$m[1]] = true; }
		}
		// Newest advisory first, so the cap leaves out the oldest.
		$adv = array();
		foreach ($list['tree'] as $t) {
			if (preg_match('~^secadv/(\d{4})(\d{2})(\d{2})\.txt$~', (string)($t['path'] ?? ''), $m)) { $adv[$m[0]] = $m; }
		}
		krsort($adv);
		$cves = array();
		foreach ($adv as $m) {
			$at = gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
			if ($at === false || $now - $at > self::WINDOW_SECONDS || $at - $now > 86400) { continue; }
			$text = call_user_func($this->fetcher, self::RAW . $m[0]);
			if (!is_string($text)) {
				return null;
			}
			preg_match_all('/\bCVE-\d{4}-\d{4,}\b/', $text, $found);
			foreach ($found[0] as $cve) {
				if (isset($records[$cve])) { $cves[$cve] = true; }
			}
		}
		$cves = array_slice(array_keys($cves), 0, self::MAX_RECORDS);
		$out = array();
		foreach ($cves as $cve) {
			$rec = json_decode((string)call_user_func($this->fetcher, self::RAW . 'secjson/' . $cve . '.json'), true);
			if (!is_array($rec)) {
				return null;
			}
			if (strtoupper((string)($rec['cveMetadata']['cveId'] ?? '')) !== $cve) { continue; }
			$a = self::advisory($rec);
			if ($a !== null) {
				$out[$cve] = $a;
			}
		}
		ksort($out);
		return $this->read = $out;
	}

	public function ids(string $cve): array {
		return array();
	}

	public function commits(string $cve): array {
		$a = $this->read ?? $this->advisories();
		return is_array($a) && isset($a[$cve]) ? $a[$cve]['commits'] : array();
	}

	/** One CVE JSON 5 record as an advisory; null when it is not one. */
	public static function advisory(array $rec): ?array {
		$cna = $rec['containers']['cna'] ?? null;
		if (!is_array($cna)) { return null; }

		$rating = '';
		foreach (is_array($cna['metrics'] ?? null) ? $cna['metrics'] : array() as $mt) {
			$word = strtolower(trim((string)($mt['other']['content']['text'] ?? '')));
			if (isset(self::RATINGS[$word])) { $rating = self::RATINGS[$word]; break; }
		}
		$fixed_in = array();
		foreach (is_array($cna['affected'] ?? null) ? $cna['affected'] : array() as $af) {
			foreach (is_array($af['versions'] ?? null) ? $af['versions'] : array() as $v) {
				$lt = (string)($v['lessThan'] ?? '');
				if (($v['status'] ?? '') === 'affected' && preg_match('/^\d+\.\d+\.\d+$/', $lt)) { $fixed_in[$lt] = true; }
			}
		}
		$fixed_in = array_keys($fixed_in);
		usort($fixed_in, 'version_compare');
		$url = '';
		$commits = array();
		foreach (is_array($cna['references'] ?? null) ? $cna['references'] : array() as $ref) {
			$u = (string)($ref['url'] ?? '');
			$tags = is_array($ref['tags'] ?? null) ? $ref['tags'] : array();
			if (preg_match('~^https://github\.com/openssl/openssl/commit/([0-9a-f]{40})$~', $u, $m)
				|| preg_match('~^https://git\.openssl\.org/gitweb/\?p=openssl\.git;a=commitdiff;h=([0-9a-f]{40})$~', $u, $m)) {
				$commits[$m[1]] = true;
			} elseif ($url === '' && in_array('vendor-advisory', $tags, true) && preg_match('~^https://(www\.)?openssl(-library)?\.org/\S+$~', $u)) {
				$url = $u;
			}
		}
		return array(
			'rating'   => $rating,
			'fixed'    => count($fixed_in) > 0,
			'fixed_in' => implode(', ', $fixed_in),
			'url'      => $url,
			'ids'      => array(),
			'commits'  => array_keys($commits),
		);
	}
}
?>
