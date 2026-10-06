<?php
/**
 * MovedSiteCheck - where the domain of the old machine of a switch-over goes
 * (specs/site_copy.md WP14). Two proofs, each answering half the question:
 *
 * 1. Has the domain LEFT the old container? The host's proof, the one
 *    decommission_moved_site enforces, run on its own (moved_site_check): the
 *    host puts a one-time token in the container and fetches each name it
 *    serves the container under. The token not coming back passes — so any
 *    other server answering passes, a parking page included.
 * 2. Does the domain REACH the new server? This management node places a
 *    one-time token on the new server through its own agent (ssl_probe_place,
 *    job type moved_site_reach) and fetches it over the domain. Only the
 *    token coming back passes.
 *
 * The page shows the site as moved only when both pass, and Permanently
 * Delete Site needs a fresh pass of the second (new_server_refusal) as well
 * as the host's proof at removal time. The second can refuse a removal but
 * never allow one the host's proof refuses, so this management node alone
 * still cannot destroy a site.
 *
 * Both are jobs (seconds), so they are not run on every page view. The row
 * keeps the last answers (mgn_moved_check_*, mgn_moved_reach_*); the page
 * shows them at once and asks again when they are older than STALE_SECONDS,
 * or when the operator presses Check again.
 *
 * @version 1.2 - leftover_certificates: what the host still holds for a removed container's domain
 * @version 1.1 - the second proof: the domain reaches the new server (moved_site_reach); the site reads as
 *                moved only when both pass, and the removal needs a fresh pass
 * @version 1.0
 */
class MovedSiteCheck {

	/** An answer older than this is asked again when the page is opened, and no longer allows a removal. */
	const STALE_SECONDS = 600;

	const JOB_TYPE = 'moved_site_check';

	/** The probe placed on the new server; its result is the fetch over the domain. */
	const REACH_JOB_TYPE = 'moved_site_reach';

	/** Empties the new server's probe file after the fetch. */
	const REACH_CLEAR_JOB_TYPE = 'moved_site_reach_clear';

	/** What finish_reach may record. */
	const REACH_STATES = ['reached', 'elsewhere', 'unsure', 'failed'];

	const FETCH_TIMEOUT = 15;
	const FETCH_BODY_CAP = 4096;

	/**
	 * Test seam: fn(string $url): array{status:int, body:string, error:string}.
	 * Null fetches over the network.
	 *
	 * @var callable|null
	 */
	public static $fetcher = null;

	/** The old container of a switch-over: the rows this check is for. */
	public static function applies($node): bool {
		return trim((string)$node->get('mgn_container_name')) !== ''
			&& JobCommandBuilder::decommission_is_moved($node);
	}

	/** The newest job of $type filed for this row, or null. */
	public static function latest_job($node, string $type = self::JOB_TYPE): ?ManagementJob {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_management_job_id FROM mjb_management_jobs
			 WHERE mjb_job_type = ? AND (mjb_parameters->>'victim_node_id')::bigint = ?
			 ORDER BY mjb_management_job_id DESC LIMIT 1");
		$q->execute([$type, (int)$node->key]);
		$id = (int)$q->fetchColumn();
		return $id ? new ManagementJob($id, TRUE) : null;
	}

	/**
	 * Fold finished checks the processor has not seen yet onto the row, and
	 * say whether one is still open.
	 */
	public static function settle($node): bool {
		$open = false;
		foreach ([self::JOB_TYPE, self::REACH_JOB_TYPE] as $type) {
			$job = self::latest_job($node, $type);
			if (!$job) {
				continue;
			}
			if (in_array((string)$job->get('mjb_status'), ['pending', 'running'], true)) {
				$open = true;
				continue;
			}
			if (JobResultProcessor::process_if_due($job)) {
				$node->load();
			}
		}
		return $open;
	}

	/** Whether the stored answers should be asked again. A removed container stays removed. */
	public static function is_stale($node): bool {
		if ((string)$node->get('mgn_moved_check_state') === 'absent') {
			return false;
		}
		return self::older_than_stale((string)$node->get('mgn_moved_check_time'))
			|| self::older_than_stale((string)$node->get('mgn_moved_reach_time'));
	}

	private static function older_than_stale(string $when): bool {
		return $when === '' || time() - strtotime($when . ' UTC') >= self::STALE_SECONDS;
	}

	/**
	 * File both checks: the host's (refusals throw, as build_moved_site_check
	 * refuses, and nothing is filed) and the new server's. A new-server check
	 * that cannot be filed is recorded as failed with its reason, so the page
	 * says why it does not know.
	 */
	public static function start($node, $created_by): ManagementJob {
		$built = JobCommandBuilder::build_moved_site_check($node);
		$host_node = JobCommandBuilder::decommission_host_node_for($node);
		$job = ManagementJob::createFromBuild($host_node->key, self::JOB_TYPE, $built,
			['victim_node_id' => (int)$node->key, 'site' => JobCommandBuilder::decommission_site_name($node)],
			$created_by);

		try {
			$domain = SiteCopyRunner::site_domain($node);
			if ($domain === '') {
				throw new Exception('This row has no https site address, so there is no domain to fetch.');
			}
			$token = JobCommandBuilder::mint_ssl_probe_token();
			$reach = JobCommandBuilder::build_moved_site_reach($node, $token);
			$new = JobCommandBuilder::moved_new_server_for($node);
			ManagementJob::createFromBuild($new->key, self::REACH_JOB_TYPE, $reach,
				['victim_node_id' => (int)$node->key, 'domain' => $domain], $created_by);
		} catch (Exception $e) {
			self::record_reach($node, 'failed', $e->getMessage());
		}
		return $job;
	}

	/**
	 * A moved_site_reach job has finished: fetch the domain and see whether
	 * the token placed on the new server comes back, fold the answer onto the
	 * old machine's row, and empty the new server's probe file. A job a newer
	 * check has superseded records its answer on itself only: the newer
	 * token has replaced its own.
	 *
	 * @return array the job's result: state, detail, domain
	 */
	public static function finish_reach(ManagementJob $job): array {
		$params = $job->get('mjb_parameters');
		if (is_string($params)) { $params = json_decode($params, true); }
		$params = is_array($params) ? $params : [];
		$victim_id = (int)($params['victim_node_id'] ?? 0);
		$domain = strtolower(trim((string)($params['domain'] ?? '')));
		$token = trim((string)($params['token'] ?? ''));

		$victim = null;
		if ($victim_id) {
			try {
				$victim = new ManagedNode($victim_id, TRUE);
				$victim = $victim->key ? $victim : null;
			} catch (Exception $e) {
				$victim = null;
			}
		}
		$new_name = '';
		try {
			$new_name = (string)(new ManagedNode((int)$job->get('mjb_mgn_managed_node_id'), TRUE))->get('mgn_name');
		} catch (Exception $e) {
			// The name only decorates the sentence.
		}
		$new_label = $new_name !== '' ? $new_name : 'the new server';

		if ($victim) {
			$latest = self::latest_job($victim, self::REACH_JOB_TYPE);
			if ($latest && (int)$latest->key !== (int)$job->key) {
				return ['state' => 'superseded', 'detail' => 'A newer check replaced this one.', 'domain' => $domain];
			}
		}

		if ((string)$job->get('mjb_status') !== 'completed') {
			[$state, $detail] = ['failed', 'The probe could not be placed on ' . $new_label . ': '
				. (trim((string)$job->get('mjb_error_message')) ?: 'the job did not finish') . '.'];
		} elseif (!preg_match('/^[a-z0-9.-]{1,253}$/', $domain) || !preg_match('/^sm-ssl-probe-[a-f0-9]{24}$/', $token)) {
			[$state, $detail] = ['failed', 'The check carries no usable domain or token.'];
		} else {
			[$state, $detail] = self::judge_reach($domain, $token, $new_label);
			self::clear_new_server($job, $victim_id);
		}
		$detail = substr(preg_replace('/[\x00-\x1f]/', ' ', $detail), 0, 500);
		if ($victim) {
			self::record_reach($victim, $state, $detail);
		}
		return ['state' => $state, 'detail' => $detail, 'domain' => $domain];
	}

	/**
	 * Fetch the probe over the domain: https, certificate checked, no
	 * redirect followed, a fresh query string so no cache answers.
	 *
	 * @return array{0:string, 1:string} state and one sentence
	 */
	private static function judge_reach(string $domain, string $token, string $new_label): array {
		$url = 'https://' . $domain . '/sm-ssl-probe.txt?moved=' . bin2hex(random_bytes(8));
		$got = self::$fetcher ? (self::$fetcher)($url) : self::fetch($url);
		$status = (int)($got['status'] ?? 0);
		$error = trim((string)($got['error'] ?? ''));
		if ($error !== '') {
			return ['unsure', "https://{$domain} did not answer ({$error}), so where it goes is not known."];
		}
		if (strpos((string)($got['body'] ?? ''), $token) !== false) {
			return ['reached', "https://{$domain} answered with the probe placed on {$new_label}."];
		}
		if ($status >= 500) {
			return ['unsure', "https://{$domain} answered {$status}, which may be {$new_label} failing rather than "
				. 'another server.'];
		}
		return ['elsewhere', "https://{$domain} answered {$status} without the probe placed on {$new_label}, so it "
			. 'reaches some other server.'];
	}

	/** One GET, its body capped at FETCH_BODY_CAP. */
	private static function fetch(string $url): array {
		$body = '';
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT,
			CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
			CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body) {
				$room = self::FETCH_BODY_CAP - strlen($body);
				if ($room <= 0) {
					return 0;   // enough read: stop the transfer
				}
				$body .= substr($chunk, 0, $room);
				return strlen($chunk);
			},
		]);
		$ok = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = '';
		if ($ok === false && !(curl_errno($ch) === CURLE_WRITE_ERROR && strlen($body) >= self::FETCH_BODY_CAP)) {
			$error = curl_error($ch) ?: 'no answer';
		}
		curl_close($ch);
		return ['status' => $status, 'body' => $body, 'error' => $error];
	}

	/** Empty the new server's probe file, behind the fetch. Best effort: the token has no secrecy value. */
	private static function clear_new_server(ManagementJob $job, int $victim_id): void {
		try {
			$new = new ManagedNode((int)$job->get('mjb_mgn_managed_node_id'), TRUE);
			ManagementJob::createFromBuild($new->key, self::REACH_CLEAR_JOB_TYPE,
				JobCommandBuilder::build_ssl_probe_clear($new), ['victim_node_id' => $victim_id], null);
		} catch (Exception $e) {
			error_log('MovedSiteCheck: could not file the probe clear on node '
				. (int)$job->get('mjb_mgn_managed_node_id') . ': ' . $e->getMessage());
		}
	}

	private static function record_reach($node, string $state, string $detail): void {
		$node->set('mgn_moved_reach_state', $state);
		$node->set('mgn_moved_reach_detail', substr($detail, 0, 500));
		$node->set('mgn_moved_reach_time', gmdate('Y-m-d H:i:s'));
		$node->save();
	}

	/**
	 * Why the old machine may not be removed yet as far as the new server is
	 * concerned, or null: a removal needs this management node to have seen
	 * the domain reach the new server within STALE_SECONDS.
	 */
	public static function new_server_refusal($node): ?string {
		$state = (string)$node->get('mgn_moved_reach_state');
		if ($state === 'reached' && !self::older_than_stale((string)$node->get('mgn_moved_reach_time'))) {
			return null;
		}
		$detail = trim((string)$node->get('mgn_moved_reach_detail'));
		return "The domain of '{$node->get('mgn_slug')}' has not been shown to reach the site's new server in the "
			. 'last ten minutes' . ($state !== 'reached' && $detail !== '' ? ' (' . rtrim($detail, '.') . ')' : '')
			. '. Open its Overview, wait for the check beside its site to say it moved, then remove it.';
	}

	/**
	 * The HTTPS certificates the host still reports for this row's domain
	 * once its container is gone: a lineage named after the domain, or
	 * certbot's second one (-NNNN). Removing the container took its vhost;
	 * before remove_account.sh 2.4 it left these, and certbot kept failing to
	 * renew them. Read from the host's last status check.
	 *
	 * @return string[] certificate names
	 */
	public static function leftover_certificates($node): array {
		if ((string)$node->get('mgn_moved_check_state') !== 'absent') {
			return [];
		}
		$domain = SiteCopyRunner::site_domain($node);
		if ($domain === '') {
			return [];
		}
		try {
			$host_node = JobCommandBuilder::decommission_host_node_for($node);
		} catch (Exception $e) {
			return [];
		}
		$status = json_decode((string)$host_node->get('mgn_last_status_data'), true);
		$names = [];
		foreach ((array)($status['ssl_certificates'] ?? []) as $cert) {
			$name = is_array($cert) ? (string)($cert['name'] ?? '') : '';
			if ($name === $domain || preg_match('/^' . preg_quote($domain, '/') . '-[0-9]{4}$/', $name)) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/** The new server's name for the label, or a plain phrase when there is none. */
	private static function new_server_name($node): string {
		try {
			return (string)JobCommandBuilder::moved_new_server_for($node)->get('mgn_name');
		} catch (Exception $e) {
			return '';
		}
	}

	/**
	 * The line shown beside the site: the stored answers, or that a check is
	 * running. $refusal is why a check could not be started, when it could not.
	 */
	public static function label_html($node, bool $checking, string $timezone, string $refusal = ''): string {
		$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
		$state = (string)$node->get('mgn_moved_check_state');
		$detail = (string)$node->get('mgn_moved_check_detail');
		$when = (string)$node->get('mgn_moved_check_time');
		$reach = (string)$node->get('mgn_moved_reach_state');
		$reach_detail = (string)$node->get('mgn_moved_reach_detail');
		$reach_when = (string)$node->get('mgn_moved_reach_time');
		$new_name = self::new_server_name($node);
		$new_label = $new_name !== '' ? $new_name . ', the new server' : 'the new server';

		$labels = [
			'here'   => ['text-danger', '✗ The domain still reaches this container'],
			'unsure' => ['text-warning', '? Could not tell where the domain goes'],
			'absent' => ['text-muted', 'The container is gone from its host'],
			'failed' => ['text-warning', '? The check did not run'],
		];
		$extra = '';
		if ($state === 'moved') {
			if ($reach === 'reached') {
				[$class, $text] = ['text-success', '✓ Moved: the domain reaches ' . $new_label];
				$detail = trim($detail . ' ' . $reach_detail);
			} elseif ($reach === 'elsewhere') {
				[$class, $text] = ['text-warning', '! The domain left this container but does not reach ' . $new_label];
				$extra = $reach_detail;
			} else {
				[$class, $text] = ['text-warning', '? The domain left this container; whether it reaches ' . $new_label
					. ' is not known'];
				$extra = $reach_detail;
			}
			// The older of the two answers is how old the verdict is.
			if ($reach_when !== '' && ($when === '' || strtotime($reach_when) < strtotime($when))) {
				$when = $reach_when;
			}
		} elseif (isset($labels[$state])) {
			[$class, $text] = $labels[$state];
			if ($state !== 'absent') {
				$extra = $detail;
			}
		}

		if (isset($class)) {
			$ago = $when !== '' ? LibraryFunctions::time_ago_or_time($when, 'UTC', $timezone, 'M j, g:i A') : '';
			$out = '<span class="' . $class . '" title="' . $h($detail) . '">' . $h($text) . '</span>';
			if ($ago !== '') {
				$out .= ' <span class="text-muted">· checked ' . $h($ago) . '</span>';
			}
			if ($extra !== '') {
				$out .= '<div class="text-muted">' . $h($extra) . '</div>';
			}
		} else {
			$out = '<span class="text-muted">Not checked yet</span>';
		}
		if ($checking) {
			$out .= '<div class="text-muted">Checking where the domain goes…</div>';
		} elseif ($refusal !== '') {
			$out .= '<div class="text-warning">Cannot check: ' . $h($refusal) . '</div>';
		}
		return $out;
	}
}
