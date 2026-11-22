<?php
/**
 * UbuntuCveTracker - asks Ubuntu's CVE tracker whether a CVE is fixed in a
 * source package on a release (specs/package_fixes_ahead_of_ubuntu.md WP2, WP5).
 *
 * The tracker answers "is it fixed on 26.04" for any source package: per CVE,
 * per package, per release, a status (released, needs-triage, needed,
 * deferred, pending, ignored, not-affected, DNE) and, once released, the
 * version that fixed it. Its priority is not a rating on day one: on
 * 2026-11-20 it was "medium" for both CVEs PHP rated critical, the triage
 * default. Callers rate a CVE from upstream and NVD, not from here.
 *
 * Every call returns null when the tracker cannot be read; callers decide
 * what an unknown means for them.
 *
 * @version 1.1 - cvesForPackage() leaves out a CVE that does not list the package: the tracker's
 *                package search matches by prefix, so "postfix" also returned postfixadmin's CVEs
 * @version 1.0
 */

class UbuntuCveTracker {

	const CVE_URL = 'https://ubuntu.com/security/cves/%s.json';
	const PACKAGE_URL = 'https://ubuntu.com/security/cves.json?package=%s&limit=%d&offset=%d';
	/** The statuses that leave a CVE unfixed on a release: what cvesForPackage(..., true) asks for. */
	const OPEN_STATUSES = array('needs-triage', 'needed', 'pending', 'deferred', 'ignored');
	const CODENAME = 'resolute';

	/** Statuses that mean nothing is owed on that release. */
	const DONE_STATUSES = array('released', 'not-affected', 'DNE');

	/**
	 * The tracker answers a burst of requests with 503: calls are spaced this
	 * far apart, and one that fails is asked once more after RETRY_SECONDS.
	 */
	const SPACING_SECONDS = 2;
	const RETRY_SECONDS = 10;

	/** @var callable(string $url): ?string */
	protected $fetcher;
	protected $paced;
	protected $last_call = 0.0;

	/** @param callable|null $fetcher tests only: fn(string $url): ?string, the body or null; never paced */
	public function __construct($fetcher = null) {
		$this->paced = $fetcher === null;
		$this->fetcher = $fetcher ?? array(self::class, 'httpGet');
	}

	/**
	 * One CVE's status for one source package on 26.04.
	 *
	 * @return array{status:string, version:string, priority:string}|null
	 *         null when the tracker cannot be read; status 'unknown' when it
	 *         has the CVE but does not list the package on the release
	 */
	public function cveStatus($cve, $source, $codename = self::CODENAME) {
		if (!preg_match('/^CVE-\d{4}-\d{4,}$/', $cve)) {
			return null;
		}
		$data = $this->getJson(sprintf(self::CVE_URL, $cve));
		if (!is_array($data)) {
			return null;
		}
		return self::statusIn($data, $source, $codename);
	}

	/**
	 * Every CVE the tracker lists for a source package, with its status on 26.04.
	 * With $open_only, only those not fixed there (the tracker filters: a few
	 * pages, not every CVE the package ever had).
	 *
	 * @return array<string, array{status:string, version:string, priority:string, severity:string, published:string}>|null
	 */
	public function cvesForPackage($source, $codename = self::CODENAME, $open_only = false) {
		if (!preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $source)) {
			return null;
		}
		$filter = '';
		if ($open_only) {
			$filter = '&version=' . rawurlencode($codename);
			foreach (self::OPEN_STATUSES as $st) {
				$filter .= '&status=' . rawurlencode($st);
			}
		}
		$out = array();
		// The tracker refuses a page larger than 20 (HTTP 422).
		$limit = 20;
		for ($offset = 0; $offset < 5000; $offset += $limit) {
			$data = $this->getJson(sprintf(self::PACKAGE_URL, rawurlencode($source), $limit, $offset) . $filter);
			if (!is_array($data) || !isset($data['cves']) || !is_array($data['cves'])) {
				return null;
			}
			foreach ($data['cves'] as $c) {
				if (!is_array($c) || !isset($c['id'])) {
					continue;
				}
				// The search matches by prefix ("postfix" finds postfixadmin):
				// a CVE that does not list this package is another package's.
				if (!in_array($source, array_column(array_filter((array)($c['packages'] ?? array()), 'is_array'), 'name'), true)) {
					continue;
				}
				$st = self::statusIn($c, $source, $codename);
				$st['published'] = (string)($c['published'] ?? '');
				$out[(string)$c['id']] = $st;
			}
			if (count($data['cves']) < $limit || $offset + $limit >= (int)($data['total_results'] ?? 0)) {
				break;
			}
		}
		return $out;
	}

	/** Whether a status means 26.04 owes nothing for that CVE. */
	public static function isDone($status) {
		return in_array($status, self::DONE_STATUSES, true);
	}

	/** @return array{status:string, version:string, priority:string, severity:string} */
	protected static function statusIn(array $cve, $source, $codename) {
		$priority = (string)($cve['priority'] ?? '');
		$severity = self::severityIn($cve);
		foreach ($cve['packages'] ?? array() as $pkg) {
			if (!is_array($pkg) || ($pkg['name'] ?? '') !== $source) {
				continue;
			}
			foreach ($pkg['statuses'] ?? array() as $s) {
				if (is_array($s) && ($s['release_codename'] ?? '') === $codename) {
					return array('status' => (string)($s['status'] ?? 'unknown'),
						'version' => (string)($s['description'] ?? ''), 'priority' => $priority, 'severity' => $severity);
				}
			}
		}
		return array('status' => 'unknown', 'version' => '', 'priority' => $priority, 'severity' => $severity);
	}

	/**
	 * NVD's rating as the tracker mirrors it (impact: CVSS 4, else 3.x), in
	 * lower case: critical, high, medium, low, none; empty while NVD has none.
	 */
	public static function severityIn(array $cve) {
		$impact = is_array($cve['impact'] ?? null) ? $cve['impact'] : array();
		foreach (array(array('baseMetricV4', 'cvssV4'), array('baseMetricV3', 'cvssV3')) as $path) {
			$m = $impact[$path[0]][$path[1]] ?? null;
			if (is_array($m)) {
				$sev = strtolower((string)($m['baseSeverity'] ?? ''));
				if (in_array($sev, array('critical', 'high', 'medium', 'low', 'none'), true)) {
					return $sev;
				}
			}
			$sev = strtolower((string)($impact[$path[0]]['baseSeverity'] ?? ''));
			if (in_array($sev, array('critical', 'high', 'medium', 'low', 'none'), true)) {
				return $sev;
			}
		}
		return '';
	}

	protected function getJson($url) {
		$body = $this->fetchPaced($url);
		if (!is_string($body) && $this->paced) {
			sleep(self::RETRY_SECONDS);
			$body = $this->fetchPaced($url);
		}
		if (!is_string($body) || $body === '') {
			return null;
		}
		$data = json_decode($body, true);
		return is_array($data) ? $data : null;
	}

	protected function fetchPaced($url) {
		if ($this->paced) {
			$wait = $this->last_call + self::SPACING_SECONDS - microtime(true);
			if ($wait > 0) {
				usleep((int)($wait * 1000000));
			}
			$this->last_call = microtime(true);
		}
		return call_user_func($this->fetcher, $url);
	}

	/** GET $url; the body on a 200, otherwise null. */
	public static function httpGet($url) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_USERAGENT => 'joinery-package-fixes',
		));
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		return ($body !== false && $code === 200) ? (string)$body : null;
	}
}
?>
