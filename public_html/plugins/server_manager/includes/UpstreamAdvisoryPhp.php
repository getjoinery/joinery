<?php
/**
 * UpstreamAdvisoryPhp - PHP's own security advisories, from php-src's GitHub
 * security advisories (specs/package_fixes_ahead_of_ubuntu.md WP5).
 *
 * Each published advisory names its CVE, PHP's rating, and the releases that
 * fix it (vulnerabilities[].patched_versions: "8.5.13"). One unauthenticated
 * call an hour, well inside GitHub's 60 an hour for an address.
 *
 * @version 1.0
 */
class UpstreamAdvisoryPhp implements UpstreamAdvisoryDriver {

	const URL = 'https://api.github.com/repos/php/php-src/security-advisories?state=published&per_page=100&sort=published&direction=desc';

	/** @var callable(string $url): ?string */
	protected $fetcher;

	/** @param callable|null $fetcher tests only: fn(string $url): ?string */
	public function __construct($fetcher = null) {
		$this->fetcher = $fetcher ?? array('UbuntuCveTracker', 'httpGet');
	}

	public function covers(string $source): bool {
		return (bool)preg_match('/^php\d+\.\d+$/', $source);
	}

	public function label(): string {
		return 'PHP';
	}

	public function advisories(): ?array {
		$body = call_user_func($this->fetcher, self::URL);
		$data = is_string($body) ? json_decode($body, true) : null;
		if (!is_array($data) || ($data !== array() && !array_is_list($data))) {
			return null;
		}
		$out = array();
		foreach ($data as $a) {
			if (!is_array($a) || !is_string($a['cve_id'] ?? null) || !preg_match('/^CVE-\d{4}-\d{4,}$/', $a['cve_id'])) {
				continue;
			}
			$rating = strtolower((string)($a['severity'] ?? ''));
			if (!in_array($rating, array('critical', 'high', 'medium', 'low'), true)) {
				$rating = '';
			}
			$fixed_in = array();
			foreach (is_array($a['vulnerabilities'] ?? null) ? $a['vulnerabilities'] : array() as $v) {
				foreach (preg_split('/[\s,]+/', (string)($v['patched_versions'] ?? '')) as $pv) {
					if (preg_match('/^\d+\.\d+\.\d+$/', $pv)) {
						$fixed_in[$pv] = true;
					}
				}
			}
			$fixed_in = array_keys($fixed_in);
			usort($fixed_in, 'version_compare');
			$out[$a['cve_id']] = array(
				'rating'   => $rating,
				'fixed'    => count($fixed_in) > 0,
				'fixed_in' => implode(', ', $fixed_in),
				'url'      => is_string($a['html_url'] ?? null) && strpos($a['html_url'], 'https://github.com/') === 0 ? $a['html_url'] : '',
			);
		}
		return $out;
	}
}
?>
