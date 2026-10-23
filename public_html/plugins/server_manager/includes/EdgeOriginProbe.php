<?php
/**
 * EdgeOriginProbe — is a site slow, or is the path to it slow?
 *
 * A site behind an edge (Cloudflare, a relay) is reached two ways: through the
 * edge by its public name, and straight at the machine's own addresses. This
 * times the same requests down both at the same moment, from this management
 * node, and says which one stalls. It is the comparison that settled the
 * jeremytunnell.com stalls of 2026-10-23 by hand: 5 of 40 stalled through
 * Cloudflare, 0 of 40 direct (specs/slow_request_diagnosis_words.md WP5).
 *
 * Each round sends, at once, a GET of /login (the cheapest page every site
 * serves, no session) and a POST of {} to an action that refuses it without a
 * session, down every path. The POST is there because a body that never
 * arrives is its own kind of stall. Rounds run one after another, so a site
 * sees at most a handful of requests at a time from this.
 *
 * The direct legs verify neither name nor chain: an origin behind an edge may
 * present a Cloudflare Origin CA certificate or Apache's fallback certificate,
 * which no public chain trusts. This is a timing measure; nothing is acted on.
 * The result names the certificate each origin presented.
 *
 * It runs inside the request that asked for it, and that request reaches this
 * node through its own edge, which gives up after 100 s: so no request waits
 * longer than REQUEST_TIMEOUT and no round starts after BUDGET_SECONDS.
 *
 * The direct addresses are what the node's own agent reported (mgn_addresses)
 * besides its node row's address. A node could name an address that is not
 * its own; the plane would then send this run's requests there with the site's
 * name. That is bounded (operator-clicked, RUNS_PER_HOUR) and every address
 * timed is printed in the result, so it is seen.
 *
 * An edge that challenges an unknown client answers 403 or 503 quickly. The
 * verdict says so rather than reading fast challenges as a healthy path.
 *
 * The POST legs arrive with no credential and count against the site's keyless
 * per-address limit (api_rate_limit_requests); RUNS_PER_HOUR keeps this far
 * under it, and a 429 is reported as the site's own limit, apart from errors.
 *
 * @version 1.1 - one DNS resolution per run; the verdict names an edge that challenged the probe (403/503
 *                through the edge and not direct); the header names what an agent-reported address allows
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));

class EdgeOriginProbe {

	const JOB_TYPE        = 'edge_vs_origin';
	const USER_AGENT      = 'JoineryEdgeProbe/1';
	const ROUNDS          = 20;
	const REQUEST_TIMEOUT = 10;     // seconds a request may take before it is a stall that never answered
	const STALL_MS        = 5000;   // a request this slow is a stall
	const BUDGET_SECONDS  = 45;     // no round starts after this
	const RUNS_PER_HOUR   = 10;
	const MAX_ORIGINS     = 3;
	const POST_PATH       = '/api/v1/action/vault_heartbeat';
	const GET_PATH        = '/login';

	/** The site's public name and its https base URL, or null when the node names no site. */
	public static function site($node): ?array {
		$url = trim((string)$node->get('mgn_site_url'));
		$host = (string)parse_url($url, PHP_URL_HOST);
		if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
			return null;
		}
		return array('host' => strtolower($host), 'base' => 'https://' . strtolower($host));
	}

	/**
	 * The machine's own public addresses to time directly: the node's address
	 * and what its agent reported (mgn_addresses), IPv4 first, at most
	 * MAX_ORIGINS.
	 */
	public static function origins($node): array {
		$list = array();
		$host = trim((string)$node->get('mgn_host'));
		if (IpAddress::binary($host) !== null) {
			$list[] = $host;
		}
		foreach ($node->reported_addresses() as $a) {
			$list[] = $a;
		}
		$out = array();
		foreach ($list as $a) {
			if (!IpAddress::isPublic($a) || IpAddress::in($a, $out)) {
				continue;
			}
			$out[] = inet_ntop(IpAddress::binary($a));
		}
		usort($out, function ($a, $b) { return IpAddress::family($a) <=> IpAddress::family($b); });
		return array_slice($out, 0, self::MAX_ORIGINS);
	}

	/**
	 * Whether the site is fronted: its public name resolves to none of the
	 * machine's own addresses. For a site that is not, the two legs are one
	 * path and the comparison means nothing.
	 */
	public static function is_fronted($node): bool {
		$site = self::site($node);
		$origins = self::origins($node);
		if (!$site || !$origins) {
			return false;
		}
		try {
			$public = array_merge(DnsResolver::getA($site['host']), DnsResolver::getAaaa($site['host']));
		} catch (Throwable $e) {
			return false;
		}
		if (!$public) {
			return false;
		}
		foreach ($public as $ip) {
			if (IpAddress::in((string)$ip, $origins)) {
				return false;
			}
		}
		return true;
	}

	/** Why this node cannot be probed now, or null when it can. */
	public static function refusal($node): ?string {
		if (!self::site($node)) {
			return 'This node names no site to time (its site URL is empty or an address).';
		}
		if (!self::origins($node)) {
			return 'This node has no public address of its own on record to time directly.';
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = ?"
			. " AND mjb_create_time > now() - interval '1 hour'");
		$q->execute(array((int)$node->key, self::JOB_TYPE));
		if ((int)$q->fetchColumn() >= self::RUNS_PER_HOUR) {
			return 'This node was compared ' . self::RUNS_PER_HOUR . ' times in the last hour, which is the most allowed:'
				. ' each run sends its requests without a credential and counts against the site\'s own per-address limit.';
		}
		return null;
	}

	/** Run the comparison and file the completed job that says what it found. */
	public static function run_and_record($node, $created_by = null) {
		$why = self::refusal($node);
		if ($why !== null) {
			throw new Exception($why);
		}
		$started = gmdate('Y-m-d H:i:s');
		$result  = self::run($node);
		$now     = gmdate('Y-m-d H:i:s');

		$job = new ManagementJob(NULL);
		$job->set('mjb_mgn_managed_node_id', $node->key);
		$job->set('mjb_job_type', self::JOB_TYPE);
		$job->set('mjb_status', 'completed');
		$job->set('mjb_commands', json_encode(array('probe' => self::JOB_TYPE, 'steps' => array())));
		$job->set('mjb_result', json_encode($result));
		$job->set('mjb_total_steps', 1);
		$job->set('mjb_current_step', 1);
		$job->set('mjb_created_by', $created_by);
		$job->set('mjb_output', self::transcript($result));
		$job->set('mjb_started_time', $started);
		$job->set('mjb_completed_time', $now);
		$job->save();
		return $job;
	}

	/**
	 * The comparison itself. Returns {site, rounds, budget_reached, paths:
	 * [{name, kind, address?, certificate?, requests, stalls, p50_ms, p95_ms,
	 * statuses, errors, rate_limited}], verdict}.
	 */
	public static function run($node): array {
		$site    = self::site($node);
		$origins = self::origins($node);
		$paths   = array(array('name' => 'edge', 'kind' => 'edge', 'address' => null));
		foreach ($origins as $ip) {
			$paths[] = array('name' => 'direct ' . $ip, 'kind' => 'direct', 'address' => $ip);
		}
		$samples = array_fill(0, count($paths), array());
		$certs   = array();
		$t0 = microtime(true);
		$rounds = 0;
		$budget_reached = false;

		for ($r = 0; $r < self::ROUNDS; $r++) {
			if (microtime(true) - $t0 > self::BUDGET_SECONDS) {
				$budget_reached = true;
				break;
			}
			$mh = curl_multi_init();
			$handles = array();
			foreach ($paths as $i => $p) {
				foreach (array('GET', 'POST') as $method) {
					$ch = self::handle($site, $p, $method, $r === 0);
					curl_multi_add_handle($mh, $ch);
					$handles[] = array($i, $method, $ch);
				}
			}
			do {
				$status = curl_multi_exec($mh, $running);
				if ($running) {
					curl_multi_select($mh, 1.0);
				}
			} while ($running && $status === CURLM_OK);

			foreach ($handles as list($i, $method, $ch)) {
				$errno = curl_errno($ch);
				$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$ms    = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
				$samples[$i][] = array('method' => $method, 'code' => $errno ? 0 : $code, 'ms' => $ms, 'errno' => $errno);
				if ($r === 0 && $paths[$i]['kind'] === 'direct' && !isset($certs[$i])) {
					$certs[$i] = self::certificate_of($ch);
				}
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);
			}
			curl_multi_close($mh);
			$rounds++;
		}

		$out = array();
		foreach ($paths as $i => $p) {
			$row = self::summarise($samples[$i]);
			$row = array('name' => $p['name'], 'kind' => $p['kind'], 'address' => $p['address']) + $row;
			if ($p['kind'] === 'direct') {
				$row['certificate'] = $certs[$i] ?? null;
			}
			$out[] = $row;
		}
		$fronted = self::is_fronted($node);
		return array(
			'site'           => $site['host'],
			'fronted'        => $fronted,
			'rounds'         => $rounds,
			'budget_reached' => $budget_reached,
			'stall_ms'       => self::STALL_MS,
			'paths'          => $out,
			'verdict'        => self::verdict($out) . ($fronted ? ''
				: ' The site\'s public name resolves to this machine itself, so both legs took the same path.'),
		);
	}

	private static function handle(array $site, array $path, string $method, bool $want_cert) {
		$url = $site['base'] . ($method === 'GET' ? self::GET_PATH : self::POST_PATH);
		$ch = curl_init($url);
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => self::REQUEST_TIMEOUT,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_USERAGENT      => self::USER_AGENT,
			CURLOPT_FORBID_REUSE   => true,
			CURLOPT_FRESH_CONNECT  => true,
		);
		if ($method === 'POST') {
			$opts[CURLOPT_POST]       = true;
			$opts[CURLOPT_POSTFIELDS] = '{}';
			$opts[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
		}
		if ($path['kind'] === 'direct') {
			$addr = IpAddress::family($path['address']) === 6 ? '[' . $path['address'] . ']' : $path['address'];
			$opts[CURLOPT_RESOLVE]        = array($site['host'] . ':443:' . $addr);
			$opts[CURLOPT_SSL_VERIFYPEER] = false;
			$opts[CURLOPT_SSL_VERIFYHOST] = 0;
			$opts[CURLOPT_CERTINFO]       = $want_cert;
		}
		curl_setopt_array($ch, $opts);
		return $ch;
	}

	/** The subject and issuer of the certificate a direct leg was shown, or null. */
	private static function certificate_of($ch): ?array {
		$info = curl_getinfo($ch, CURLINFO_CERTINFO);
		if (!is_array($info) || empty($info[0])) {
			return null;
		}
		return array(
			'subject' => substr((string)($info[0]['Subject'] ?? ''), 0, 200),
			'issuer'  => substr((string)($info[0]['Issuer'] ?? ''), 0, 200),
		);
	}

	/** Counts and percentiles for one path's samples. */
	public static function summarise(array $samples): array {
		$times = array();
		$stalls = 0;
		$errors = 0;
		$limited = 0;
		$statuses = array();
		foreach ($samples as $s) {
			$times[] = $s['ms'];
			$key = $s['code'] === 0 ? 'no answer' : (string)$s['code'];
			$statuses[$key] = ($statuses[$key] ?? 0) + 1;
			if ($s['code'] === 0 || $s['ms'] >= self::STALL_MS) {
				$stalls++;
			}
			if ($s['code'] === 429) {
				$limited++;
			} elseif ($s['code'] === 0 || $s['code'] >= 500) {
				$errors++;
			}
		}
		sort($times);
		ksort($statuses);
		return array(
			'requests'     => count($samples),
			'stalls'       => $stalls,
			'p50_ms'       => self::percentile($times, 50),
			'p95_ms'       => self::percentile($times, 95),
			'statuses'     => $statuses,
			'errors'       => $errors,
			'rate_limited' => $limited,
		);
	}

	public static function percentile(array $sorted, int $p): ?int {
		if (!$sorted) {
			return null;
		}
		$idx = (int)ceil($p / 100 * count($sorted)) - 1;
		return (int)$sorted[max(0, min(count($sorted) - 1, $idx))];
	}

	/** One line a person reads first. */
	public static function verdict(array $paths): string {
		$edge = null;
		$direct = array('requests' => 0, 'stalls' => 0, 'p95' => 0, 'limited' => 0);
		$limited = 0;
		foreach ($paths as $p) {
			$limited += $p['rate_limited'];
			if ($p['kind'] === 'edge') {
				$edge = $p;
				continue;
			}
			$direct['requests'] += $p['requests'];
			$direct['stalls']   += $p['stalls'];
			$direct['p95']       = max($direct['p95'], (int)$p['p95_ms']);
		}
		if (!$edge || $edge['requests'] === 0) {
			return 'Nothing was measured.';
		}
		$tail = $limited > 0 ? ' (' . $limited . ' answered 429: the site\'s own rate limit, not counted as stalls)' : '';
		// An edge that challenges an unknown client answers fast with 403 or
		// 503; the direct legs never see it. Fast challenges are not a path.
		$challenged = (int)($edge['statuses']['403'] ?? 0) + (int)($edge['statuses']['503'] ?? 0);
		$direct_challenged = 0;
		foreach ($paths as $p) {
			if ($p['kind'] === 'direct') {
				$direct_challenged += (int)($p['statuses']['403'] ?? 0) + (int)($p['statuses']['503'] ?? 0);
			}
		}
		if ($challenged > 0 && $direct_challenged === 0) {
			$tail .= ' The edge answered ' . $challenged . ' of ' . $edge['requests'] . ' with 403 or 503 and the machine none:'
				. ' it may be challenging this probe, so the edge\'s timings may not be what visitors get.';
		}
		$counts = $edge['stalls'] . ' of ' . $edge['requests'] . ' stalled through the edge, '
			. $direct['stalls'] . ' of ' . $direct['requests'] . ' direct';
		if ($direct['stalls'] > 0 || ($direct['p95'] > 3000 && (int)$edge['p95_ms'] > 3000)) {
			return 'Slow on the server (' . $counts . '; direct p95 ' . $direct['p95'] . ' ms).' . $tail;
		}
		if ($edge['stalls'] > 0) {
			return 'Slow through the edge, fast direct (' . $counts . ').' . $tail;
		}
		return 'No problem seen (edge p95 ' . (int)$edge['p95_ms'] . ' ms, direct p95 ' . $direct['p95'] . ' ms).' . $tail;
	}

	private static function transcript(array $result): string {
		$lines = array();
		$lines[] = '=== [Step 1/1] Compare edge and direct: ' . $result['site'] . ' ===';
		$lines[] = $result['verdict'];
		$lines[] = '';
		$lines[] = $result['rounds'] . ' round(s), each a GET ' . self::GET_PATH . ' and a POST ' . self::POST_PATH
			. ' down every path at once; a request taking ' . (self::STALL_MS / 1000) . ' s or more is a stall.'
			. ($result['budget_reached'] ? ' Stopped early: the run reached its ' . self::BUDGET_SECONDS . ' s budget.' : '');
		foreach ($result['paths'] as $p) {
			$st = array();
			foreach ($p['statuses'] as $code => $n) {
				$st[] = $code . ' x' . $n;
			}
			$lines[] = sprintf('%-28s %3d requests  %2d stalls  p50 %5s ms  p95 %5s ms  [%s]',
				$p['name'], $p['requests'], $p['stalls'],
				$p['p50_ms'] === null ? '-' : $p['p50_ms'], $p['p95_ms'] === null ? '-' : $p['p95_ms'], implode(', ', $st));
			if (!empty($p['certificate'])) {
				$lines[] = '    certificate presented: ' . $p['certificate']['subject'] . ' / issued by ' . $p['certificate']['issuer'];
			}
		}
		$lines[] = '';
		$lines[] = 'The POST legs carry no credential: each is a no_credential row in the site\'s request log.';
		return implode("\n", $lines) . "\n";
	}
}
