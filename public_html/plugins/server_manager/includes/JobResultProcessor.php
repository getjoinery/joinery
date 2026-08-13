<?php
/**
 * JobResultProcessor - Parses completed job output into structured data.
 *
 * Called when a job transitions to 'completed'. Extracts meaningful data
 * from raw command output and updates related records.
 *
 * @version 1.13 - records which backup recovery key each node holds, and queues a push to any node
 *                 the status check finds with an empty slot
 * @version 1.12 - process_decommission_node: soft-delete the node only on a verified host teardown
 *                 (escrow rows + job history preserved); leave it intact on any failure
 * @version 1.11 - every terminal job records a result (sweep never re-processes); CF SSL gated on
 *                 CF_ROUTING_VERIFIED (no rDNS on the CF path); escrow reconcile matches ANY row;
 *                 install records the ACTUAL published container port (CONTAINER_PORT readback)
 * @version 1.10 - processable_types() drives the dashboard sweep (P-17: relay/ssl/backup results no longer skipped)
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

class JobResultProcessor {

	/**
	 * Process a completed job. Dispatches to type-specific handler if one exists.
	 */
	/**
	 * Every job type this processor can reconcile — i.e. each type with a
	 * process_<type> handler. The dashboard sweep derives its list from this so
	 * an unwatched terminal job of ANY handled type (relay, SSL, backups, …) is
	 * reconciled, not just a hardcoded few. Before this, only check_status /
	 * install_node / apply_update were swept, so an unwatched provision_relay
	 * left its relay row + WG pubkey unregistered forever (P-17), and the same
	 * for provision_ssl and the backup types.
	 */
	public static function processable_types(): array {
		$types = [];
		foreach ((new ReflectionClass(self::class))->getMethods() as $m) {
			if ($m->isStatic() && strpos($m->getName(), 'process_') === 0) {
				$types[] = substr($m->getName(), strlen('process_'));
			}
		}
		return $types;
	}

	public static function process($job) {
		$type = $job->get('mjb_job_type');
		$method = 'process_' . $type;
		if (!method_exists(self::class, $method)) {
			return;
		}
		// The Go agent marks jobs completed by writing the DB directly, so result
		// processing runs lazily on the first PHP view of the finished job — often
		// a GET (job detail page, status poll). These writes are server-side
		// reconciliation, not something a user asked a link for.
		SystemBase::server_initiated_write(function () use ($job, $method) {
			self::$method($job);
			// Sweep invariant: a terminal job must never leave processing without
			// a recorded result — the dashboard sweep keys on mjb_result IS NULL
			// and would re-process it on every render, forever. Handlers record
			// their own richer shapes; this backstop covers every path that
			// returns without recording.
			if (in_array($job->get('mjb_status'), ['completed', 'failed'], true)
				&& !$job->get('mjb_result')) {
				$job->set('mjb_result', json_encode(['status' => (string)$job->get('mjb_status')]));
				$job->save();
			}
		});
	}

	/**
	 * Parse check_status output into structured data and update the node record.
	 *
	 * Handles both transports:
	 *   - API path: output is a JSON envelope {api_version,data:{...}} — extract data.
	 *   - SSH path: output is concatenated command output — parse with regexes.
	 *
	 * SSL detection uses two paths:
	 *   1. SSH cert token: Let's Encrypt cert file found on disk → 'letsencrypt'
	 *   2. HTTPS probe fallback: curl HEAD to https://domain/ — catches Cloudflare/edge SSL
	 * The API path implicitly proves HTTPS works (no separate probe needed; handled in
	 * fetch_status_via_api). For job-based API output, we probe explicitly here.
	 */
	private static function process_check_status($job) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

		$output = $job->get('mjb_output') ?: '';

		$api_data = self::extract_api_envelope_data($output);
		if (is_array($api_data) && !empty($api_data)) {
			$result = $api_data;
		} else {
			$result = self::parse_check_status_ssh_output($output);
		}
		$version     = $result['joinery_version'] ?? null;
		$is_api_path = ($api_data !== null);

		// Load node early — needed for HTTPS probe (mgn_site_url, mgn_tls_insecure)
		$node_id = $job->get('mjb_mgn_node_id');
		$node    = null;
		if ($node_id) {
			try { $node = new ManagedNode($node_id, TRUE); } catch (Exception $e) {}
		}

		// SSL detection
		$ssl_token     = self::parse_ssl_tokens($output);
		$ssl_new_state = null;  // null = no explicit state change from detection

		if ($ssl_token !== null) {
			// SSH path: explicit cert check result from the job steps
			$result['ssl_domain']   = $ssl_token['domain'];
			$result['ssl_le_cert']  = $ssl_token['found'];
			if ($ssl_token['found']) {
				$result['ssl_state']            = 'active';
				$result['ssl_detection_method'] = 'letsencrypt';
				$ssl_new_state = 'active';
				if (!empty($ssl_token['expiry_raw'])) {
					$result['ssl_expiry_raw'] = $ssl_token['expiry_raw'];
					$ts = strtotime($ssl_token['expiry_raw']);
					if ($ts) $result['ssl_expiry_ts'] = $ts;
				}
			} else {
				// No LE cert on disk — probe HTTPS to catch Cloudflare / other edge SSL
				$probe = JobCommandBuilder::probe_https($ssl_token['domain']);
				$result['ssl_https_probe'] = $probe['ok'];
				if ($probe['ok']) {
					$result['ssl_state']            = 'active';
					$result['ssl_detection_method'] = 'https_probe';
					$ssl_new_state = 'active';
				} else {
					$result['ssl_state'] = null;
				}
			}
		} elseif ($is_api_path && $node) {
			// API path: the Go agent called the API via HTTPS; probe to confirm valid cert
			$domain = parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '';
			if ($domain && !filter_var($domain, FILTER_VALIDATE_IP)
					&& $domain !== 'localhost' && !$node->get('mgn_tls_insecure')) {
				$probe = JobCommandBuilder::probe_https($domain);
				$result['ssl_https_probe'] = $probe['ok'];
				if ($probe['ok']) {
					$result['ssl_state']            = 'active';
					$result['ssl_domain']           = $domain;
					$result['ssl_detection_method'] = 'https_probe';
					$ssl_new_state = 'active';
				}
			}
		}

		if ($node) {
			$prev_ssl_state = $node->get('mgn_ssl_state');
			$node->set('mgn_last_status_check', gmdate('Y-m-d H:i:s'));
			$node->set('mgn_last_status_data', json_encode($result));
			if ($version) {
				$node->set('mgn_joinery_version', $version);
			}
			if ($ssl_new_state !== null) {
				$node->set('mgn_ssl_state', $ssl_new_state);
			} elseif ($ssl_token !== null && !$ssl_token['found']) {
				// SSH cert missing AND HTTPS probe failed — cert disappeared from active node
				if ($node->get('mgn_ssl_state') === 'active') {
					$node->set('mgn_ssl_state', 'failed');
				}
			}
			// Which recovery key the node is holding. Recorded even when it is
			// not ours: the fleet view has to be able to show that a node is
			// carrying a key the control plane did not put there, because that
			// is the one case the push deliberately walks away from.
			if (isset($result['backup_recovery_state'])) {
				$node->set('mgn_backup_recovery_fpr', (string)($result['backup_recovery_fpr'] ?? ''));
			}

			// What the node says about MY backups of it. The node's history is the
			// authority — it is the only record that includes runs which failed —
			// so a status check refreshes the fleet's copy from it rather than
			// trusting what the last job happened to stamp. The site's own profile
			// travels in the same payload and is kept as information, never
			// promoted into a fleet problem.
			if (isset($result['backups']['manager'])) {
				$mgr = $result['backups']['manager'];
				// A run still in flight reports 'running' — neither success nor
				// failure yet. It keeps the previous stamp: writing it as failed
				// would raise a dashboard alarm for every backup a status check
				// happens to land in the middle of, and manager runs take long
				// enough that one usually does.
				if (!empty($mgr['last_run']) && ($mgr['last_outcome'] ?? '') !== 'running') {
					$node->set('mgn_last_backup_time', $mgr['last_run']);
					$node->set('mgn_last_backup_outcome',
						(($mgr['last_outcome'] ?? '') === 'success') ? 'success' : 'failed');
				}
			}
			$node->save();

			// An empty slot is REPORTED and nothing else. That slot holds the key
			// for the site's own backups, and its custodian is whoever
			// administers the site — filling it from here would make this control
			// plane the holder of the private half of a key the site believes is
			// its own. A control plane's own backups of this node need nothing in
			// it: the manager profile carries its key with each run.
			// The first confirmation of an active cert doubles as the
			// reverse-DNS moment for cloud-born nodes: the domain has just
			// proven it resolves to this box, which is the provider's
			// precondition for accepting it as rDNS. Best-effort and
			// transition-only — a stale grant or manual node leaves the PTR
			// to the mailbox Setup tab checklist, and a later custom PTR is
			// never overwritten by routine status checks.
			if ($ssl_new_state === 'active' && $prev_ssl_state !== 'active') {
				$rdns_domain = $result['ssl_domain']
					?? (parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '');
				if ($rdns_domain && !filter_var($rdns_domain, FILTER_VALIDATE_IP)) {
					require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
					$result['rdns_attempt'] = NodeReverseDns::setQuietly($node, $rdns_domain);
				}
			}
		}

		// Save structured result on the job
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Read the RECOVERY_KEY=<outcome> [fpr=<sha256>] [proven=0|1] line that
	 * set_recovery_key.php prints, in either of its modes.
	 *
	 * The keys it returns are the ones the management API's stats endpoint
	 * returns for the same facts, so a node reached over SSH and a node reached
	 * over the API leave mgn_last_status_data looking identical.
	 *
	 * @return array{backup_recovery_state:string, backup_recovery_fpr?:string}|null
	 */
	private static function parse_recovery_key_token($output) {
		if (!preg_match('/^RECOVERY_KEY=(\w+)(?:\s+fpr=([0-9a-f]{64}))?(?:\s+proven=([01]))?/m',
				(string)$output, $m)) {
			return null;
		}
		$fpr    = $m[2] ?? '';
		$proven = (($m[3] ?? '0') === '1');

		switch ($m[1]) {
			case 'none':    return ['backup_recovery_state' => 'unconfigured', 'backup_recovery_fpr' => ''];
			case 'invalid': return ['backup_recovery_state' => 'invalid',      'backup_recovery_fpr' => ''];
		}
		if ($fpr === '') return null;

		return [
			'backup_recovery_state' => $proven ? 'proven' : 'unproven',
			'backup_recovery_fpr'   => $fpr,
		];
	}

	/**
	 * Pull the `data` field out of an api_success-style JSON envelope that the
	 * agent appended to mjb_output. The envelope is wrapped in step-header text
	 * ("=== [Step 1/1] ... ==="), so we scan for the first "{" and parse from
	 * there. Returns the decoded data array on success, null otherwise.
	 */
	private static function extract_api_envelope_data($output) {
		$start = strpos($output, '{');
		if ($start === false) return null;
		$candidate = substr($output, $start);
		// Trim trailing step-footer text ("[Step 1/1 OK ...") if present.
		$decoded = json_decode($candidate, true);
		if (is_array($decoded) && isset($decoded['api_version'], $decoded['data'])) {
			return is_array($decoded['data']) ? $decoded['data'] : null;
		}
		// Try progressively shorter prefixes — agents may append bytes after the JSON.
		$end = strrpos($candidate, '}');
		while ($end !== false && $end > 0) {
			$decoded = json_decode(substr($candidate, 0, $end + 1), true);
			if (is_array($decoded) && isset($decoded['api_version'], $decoded['data'])) {
				return is_array($decoded['data']) ? $decoded['data'] : null;
			}
			$end = strrpos(substr($candidate, 0, $end), '}');
		}
		return null;
	}

	/**
	 * Parse the multi-command SSH output into the structured result array.
	 */
	private static function parse_check_status_ssh_output($output) {
		$result = [];

		if (preg_match('/(\d+)%\s+\/\s*$/m', $output, $m)) {
			$result['disk_usage_percent'] = intval($m[1]);
		}
		if (preg_match('/(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d+)%\s+\/\s*$/m', $output, $m)) {
			$result['disk_total']     = $m[2];
			$result['disk_used']      = $m[3];
			$result['disk_available'] = $m[4];
		}

		if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/m', $output, $m)) {
			$result['memory_total_mb'] = intval($m[1]);
			$result['memory_used_mb']  = intval($m[2]);
			$result['memory_free_mb']  = intval($m[3]);
		}

		if (preg_match('/up\s+(.+?),\s+\d+\s+user/m', $output, $m)) {
			$result['uptime'] = trim($m[1]);
		}

		if (preg_match('/load average:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)/m', $output, $m)) {
			$result['load_1m']  = floatval($m[1]);
			$result['load_5m']  = floatval($m[2]);
			$result['load_15m'] = floatval($m[3]);
		}

		if (preg_match('/accepting connections/i', $output)) {
			$result['postgres_status'] = 'accepting connections';
		} elseif (preg_match('/no response|not accepting/i', $output)) {
			$result['postgres_status'] = 'not responding';
		}

		if (preg_match("/VERSION\s*=\s*['\"]?([^'\";\s]+)/", $output, $m)) {
			$result['joinery_version'] = trim($m[1]);
		}

		if (preg_match('/^CRON_LAST_RUN=(.+)$/m', $output, $m)) {
			$result['cron_last_run'] = trim($m[1]);
		}

		$recovery = self::parse_recovery_key_token($output);
		if ($recovery !== null) {
			$result = array_merge($result, $recovery);
		}

		if (preg_match('/^CURRENT_DB=(\S+)$/m', $output, $m)) {
			$result['current_db'] = trim($m[1]);
		}
		if (preg_match_all('/^DB:(\S+)$/m', $output, $m)) {
			$result['db_list'] = $m[1];
		}

		return $result;
	}

	/**
	 * Scan raw job output for SSL_CERT_FOUND / SSL_CERT_MISSING tokens emitted by
	 * the check_status SSL step. Returns an array with 'found', 'domain', and
	 * (when found) 'expiry_raw', or null if no token is present.
	 */
	private static function parse_ssl_tokens($output) {
		if (preg_match('/SSL_CERT_FOUND domain=(\S+) expiry=(.+)$/m', $output, $m)) {
			return ['found' => true, 'domain' => $m[1], 'expiry_raw' => trim($m[2])];
		}
		if (preg_match('/SSL_CERT_MISSING domain=(\S+)/m', $output, $m)) {
			return ['found' => false, 'domain' => $m[1]];
		}
		return null;
	}

	/**
	 * Parse backup output to extract file path and size.
	 */
	private static function process_backup_database($job) {
		$output = $job->get('mjb_output') ?: '';
		$result = [];

		// Look for backup file path in output (.sql.gz, .sql.gz.enc, or .tar.gz)
		if (preg_match('/(\/\S+\.(?:sql\.gz(?:\.enc)?|tar\.gz))\b/', $output, $m)) {
			$result['backup_file'] = $m[1];
		}

		// Look for file size from ls output
		if (preg_match('/(\d+(?:\.\d+)?[KMGT]?)\s+.*\.(?:sql\.gz|tar\.gz)/', $output, $m)) {
			$result['backup_size'] = $m[1];
		}

		// ALWAYS record a result. The dashboard sweep selects on
		// mjb_result IS NULL, so a handler that returns without recording
		// leaves the job re-processed on every sweep forever (the invariant
		// record_apply_update_result documents). A failed backup — no path in
		// the output — records what is known instead of nothing.
		if (empty($result)) {
			$result = [
				'status' => (string)$job->get('mjb_status'),
				'note'   => 'no backup file path in output',
			];
		}
		$job->set('mjb_result', json_encode($result));
		$job->save();

	}

	/**
	 * Parse backup_project output.
	 */
	private static function process_backup_project($job) {
		self::process_backup_database($job);
	}

	/**
	 * Record what a manager-profile run did, and stamp it on the node.
	 *
	 * The verdict comes from the machine-readable BACKUP_RESULT line rather than
	 * the exit status, because the runner deliberately exits 0 for a run it
	 * SKIPPED — another backup was already in progress on that machine. A skip
	 * is neither a success nor a failure: nothing was backed up, so it must not
	 * refresh "last successful backup", and nothing is wrong, so it must not
	 * raise an alarm.
	 *
	 * The time comes from the node's BACKUP_TIME line for the same reason: it is
	 * when the run STARTED, as the node's own history records it — the identical
	 * value a status check would later copy — so the stamp means one thing
	 * whichever path wrote it. Falling back to now() only covers a node whose
	 * runner predates the line.
	 */
	private static function process_backup_run($job) {
		$output = $job->get('mjb_output') ?: '';

		$status = 'unknown';
		if (preg_match('/^BACKUP_RESULT=(\w+)$/m', $output, $m)) {
			$status = $m[1];
		} elseif ($job->get('mjb_status') === 'failed') {
			// The step never got far enough to say anything — a failed job with
			// no verdict is a failed backup, not an unknown one.
			$status = 'error';
		}

		$time = '';
		if (preg_match('/^BACKUP_TIME=(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})$/m', $output, $m)) {
			$time = $m[1];
		}

		$result = ['backup_status' => $status];
		if (preg_match('/^\[[^\]]+\] manager \w+: (.+)$/m', $output, $m)) {
			$result['message'] = trim($m[1]);
		}

		$node_id = $job->get('mjb_mgn_node_id');
		if ($node_id && $status !== 'skipped') {
			try {
				$node = new ManagedNode($node_id, TRUE);
				$node->set('mgn_last_backup_time', $time !== '' ? $time : gmdate('Y-m-d H:i:s'));
				$node->set('mgn_last_backup_outcome', ($status === 'success') ? 'success' : 'failed');
				$node->save();
			} catch (Exception $e) {
				error_log('JobResultProcessor: could not stamp the backup outcome for node '
					. $node_id . ': ' . $e->getMessage());
			}
		}

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Post-process apply_update: stamp the node's current version so the
	 * Updates tab reflects reality the moment the job completes, instead of
	 * waiting for someone to refresh the dashboard. The running site is the
	 * authority — X-Joinery-Version from a HEAD probe of the node's site URL.
	 *
	 * The probe is also the verdict. upgrade.php exits 0 in states where nothing
	 * was installed — most notably after refreshing its own deployment tooling,
	 * where a version predating the automatic re-run stops and waits for a human.
	 * Trusting the exit code alone reports those jobs as successful upgrades and
	 * the node silently stays on its old version. A job is therefore only a
	 * success if the node is actually running the version it was sent.
	 */
	private static function process_apply_update($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) { return self::record_apply_update_result($job, ['probed' => false, 'reason' => 'no node on job']); }
		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) {
			return self::record_apply_update_result($job, ['probed' => false, 'reason' => 'node could not be loaded']);
		}
		if (!$node->key) { return self::record_apply_update_result($job, ['probed' => false, 'reason' => 'node not found']); }
		$site_url = rtrim((string)$node->get('mgn_site_url'), '/');
		if ($site_url === '') { return self::record_apply_update_result($job, ['probed' => false, 'reason' => 'node has no site URL']); }

		$version = null;
		$ch = curl_init($site_url . '/');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY         => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_SSL_VERIFYPEER => $node->get('mgn_tls_insecure') ? false : true,
			CURLOPT_SSL_VERIFYHOST => $node->get('mgn_tls_insecure') ? 0 : 2,
			CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$version) {
				if (stripos($header, 'X-Joinery-Version:') === 0) {
					$v = trim(substr($header, strlen('X-Joinery-Version:')));
					if ($v !== '') { $version = $v; }
				}
				return strlen($header);
			},
		]);
		curl_exec($ch);
		$curl_error = curl_error($ch);

		if ($version) {
			$node->set('mgn_joinery_version', $version);
			$node->save();
		}

		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
		$target = LibraryFunctions::get_joinery_version();

		$result = [
			'probed'         => true,
			'site_url'       => $site_url,
			'version'        => $version,
			'target_version' => $target !== '' ? $target : null,
			'error'          => $curl_error !== '' ? $curl_error : null,
		];

		// Only a positive reading counts against the target. An unreachable node
		// or a missing header tells us nothing, and guessing failure there would
		// turn every probe hiccup into a red job.
		$is_behind = $version !== null
			&& $target !== ''
			&& preg_match('/^\d+\.\d+\.\d+$/', $version)
			&& version_compare($version, $target) < 0;

		$result['upgraded'] = !$is_behind;

		if ($is_behind) {
			$result['reason'] = self::halted_at_self_update($job->get('mjb_output') ?: '')
				? 'Upgrade stopped after refreshing its own deployment tooling. '
					. 'The node is still on ' . $version . ' and needs a second pass to reach ' . $target . '.'
				: 'Upgrade finished but the node is still on ' . $version . ', not ' . $target . '.';
			$job->set('mjb_status', 'failed');
			$job->set('mjb_error_message', $result['reason']);
		}

		self::record_apply_update_result($job, $result);
	}

	/**
	 * Did this upgrade stop to refresh its own deployment tooling?
	 *
	 * upgrade.php copies new deployment files over the live ones and restarts the
	 * pipeline. Versions from 0.8.112 onward re-run themselves; older ones print
	 * this request and exit 0, which is the case worth naming in the job result
	 * because the remedy is simply to run it again.
	 */
	private static function halted_at_self_update(string $output): bool {
		return stripos($output, 'PLEASE RE-RUN THE UPGRADE') !== false
			|| stripos($output, 'Re-run with the same command to continue') !== false
			|| stripos($output, 'Automatic re-run already attempted once') !== false;
	}

	/**
	 * Mark an apply_update job as processed.
	 *
	 * Every terminal path must land here. The dashboard selects finished jobs
	 * with mjb_result IS NULL, so a path that returns without recording leaves
	 * the job permanently unprocessed — and its HTTPS probe then re-runs on
	 * every page load, forever, once per accumulated job.
	 */
	private static function record_apply_update_result($job, array $result) {
		$result['processed_time'] = gmdate('Y-m-d H:i:s');
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Post-process install_node: mark the node online on success or install_failed on failure.
	 * All fresh installs get ssl_state=pending so ProvisionPendingSsl picks them up automatically.
	 * Also sends the welcome email for auto-provisioned orders (mjb_external_order_item_id set).
	 * Runs for both 'completed' and 'failed' terminal states.
	 */
	private static function process_install_node($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$status = $job->get('mjb_status');
		$output = $job->get('mjb_output') ?: '';

		if ($status === 'completed' && strpos($output, 'INSTALL_SUCCESS') !== false) {
			$node->set('mgn_install_state', null);
			if ($node->get('mgn_ssl_state') !== 'active') {
				$node->set('mgn_ssl_state', 'pending');
			}
			// Ground truth for the port ledger: install.sh auto-picks a different
			// port when the pinned one is busy, so the recorded port is whatever
			// Docker actually publishes (the CONTAINER_PORT= readback step).
			if (preg_match('/CONTAINER_PORT=(\d{2,5})\b/', $output, $pm)) {
				$actual_port = (int)$pm[1];
				if ($actual_port > 0 && $actual_port !== (int)$node->get('mgn_port')) {
					$node->set('mgn_port', $actual_port);
				}
			}
			$node->save();
			// Send welcome email for auto-provisioned orders
			if ($job->get('mjb_external_order_item_id')) {
				self::send_provisioning_welcome_email($job, $node);
			}
		} else {
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
		}

		$job->set('mjb_result', json_encode([
			'install_state' => $node->get('mgn_install_state'),
			'ssl_state'     => $node->get('mgn_ssl_state'),
		]));
		$job->save();
	}

	/**
	 * Post-process provision_relay: on success (PROVISION_RELAY_SUCCESS marker),
	 * store the relay's returned WireGuard public key + endpoint + public IP on the
	 * ManagedNode, flag it a relay, and clear the install state; on failure set
	 * install_failed. Runs for both 'completed' and 'failed' terminal states.
	 */
	private static function process_provision_relay($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$status = $job->get('mjb_status');
		$output = $job->get('mjb_output') ?: '';

		if ($status === 'completed' && strpos($output, 'PROVISION_RELAY_SUCCESS') !== false) {
			$node->set('mgn_is_relay', true);
			$node->set('mgn_install_state', null);
			// The relay runs no Joinery app, so skip the app health checks for it.
			$node->set('mgn_skip_joinery_checks', true);

			$wg_pubkey = self::extract_marker($output, 'RELAY_WG_PUBKEY');
			$public_ip = self::extract_marker($output, 'RELAY_PUBLIC_IP');
			if ($wg_pubkey !== '') {
				$node->set('mgn_wg_public_key', substr($wg_pubkey, 0, 255));
			}
			if ($public_ip !== '') {
				$node->set('mgn_wg_endpoint', $public_ip . ':51820');
			}
			// The relay's tunnel IP is fixed by provision_relay.sh.
			$node->set('mgn_wg_ip', '10.99.0.1');
			$node->save();

			// Register (or refresh) the MailboxRelay row the mailbox plugin drives —
			// born ENABLED so pulling and map pushes start immediately, before any
			// MX points at it (Fix 10). Disable is the emergency stop.
			// The output carries the TENANT_* markers (pull account, spool subdir).
			// Fleet SHARDS (skeleton_only) are not this deployment's relay — the
			// operator's box is not a tenant of them — so no relay row is minted.
			$job_params = json_decode((string)$job->get('mjb_parameters'), true) ?: array();
			if (empty($job_params['skeleton_only'])) {
				self::register_relay_row($node, $public_ip, $wg_pubkey, $output,
					(string)($job_params['mail_hostname'] ?? ''));
			} else {
				// A skeleton run IS a fleet shard. Stamp the version it reported so
				// the operator can see which shards are behind — the tenants on them
				// have been told the operator upgrades their relay, and nobody can
				// keep that promise without being able to see the answer.
				self::stamp_shard_version($node, self::extract_marker($output, 'RELAY_VERSION'));
			}

			// Peer the relay on the MAIN box's WireGuard interface — the other half
			// of the tunnel. provision_relay_main.sh installs the root helper + a
			// sudoers rule for exactly this call. Best-effort: on failure the tunnel
			// health checks go red and the log says what to run. Fleet shards skip
			// this too — the operator's box holds no tunnel into its shards.
			if ($wg_pubkey !== '' && $public_ip !== '' && empty($job_params['skeleton_only'])) {
				$peer_cmd = 'sudo -n /usr/local/sbin/joinery-relay-peer '
					. escapeshellarg($wg_pubkey) . ' ' . escapeshellarg($public_ip . ':51820') . ' 2>&1';
				$peer_out = array(); $peer_code = 1;
				exec($peer_cmd, $peer_out, $peer_code);
				if ($peer_code !== 0) {
					error_log('process_provision_relay: main-box WireGuard peer add failed ('
						. $peer_code . '): ' . implode(' ', $peer_out)
						. ' — run plugins/mailbox/provisioning/provision_relay_main.sh on the main box');
				}
			}
		} else {
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
		}

		$job->set('mjb_result', json_encode([
			'is_relay'      => (bool)$node->get('mgn_is_relay'),
			'wg_public_key' => $node->get('mgn_wg_public_key'),
			'wg_endpoint'   => $node->get('mgn_wg_endpoint'),
			'install_state' => $node->get('mgn_install_state'),
		]));
		$job->save();
	}

	/** rebuild_relay post-processing is identical to a fresh provision. */
	private static function process_rebuild_relay($job) {
		self::process_provision_relay($job);
	}

	/**
	 * Record the relay code version a fleet shard reported, on the shard row for
	 * this node. Owned by the mailbox plugin, so it is required lazily and skipped
	 * (no fatal) when that plugin is absent.
	 *
	 * An EMPTY version is written as empty, never skipped: a shard whose job
	 * emitted no marker must read as unknown rather than keeping a stale value
	 * that would render as up to date.
	 */
	private static function stamp_shard_version($node, string $version): void {
		$shard_class = PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_shard_class.php');
		if (!is_file($shard_class)) {
			return; // mailbox plugin not present
		}
		try {
			require_once($shard_class);
			$shards = new MultiMailboxFleetShard(array('node_id' => intval($node->key), 'deleted' => false));
			$shards->load();
			foreach ($shards as $shard) {
				$shard->set('mfs_provisioned_version', substr(trim($version), 0, 20));
				$shard->save();
			}
		} catch (\Throwable $e) {
			error_log('stamp_shard_version failed for node ' . intval($node->key) . ': ' . $e->getMessage());
		}
	}

	/**
	 * Create or update the mailbox plugin's MailboxRelay row for a provisioned node
	 * (Fix 10). Owned by the mailbox plugin, so it is required lazily and skipped
	 * (no fatal) when that plugin is inactive. A newly created row is born ENABLED
	 * so pulling and map pushes start immediately; Disable is the emergency stop.
	 */
	private static function register_relay_row($node, string $public_ip, string $wg_pubkey, string $job_output = '', string $mail_hostname = ''): void {
		$relay_class = PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php');
		if (!is_file($relay_class)) {
			return; // mailbox plugin not present
		}
		try {
			require_once($relay_class);
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->prepare(
				"SELECT mrl_mailbox_relay_id FROM mrl_mailbox_relays
				  WHERE mrl_mgn_managed_node_id = ? AND mrl_delete_time IS NULL LIMIT 1"
			);
			$stmt->execute(array($node->key));
			$existing = $stmt->fetchColumn();

			$relay = $existing ? new MailboxRelay(intval($existing), TRUE) : new MailboxRelay(NULL);
			$relay->set('mrl_mgn_managed_node_id', $node->key);
			$relay->set('mrl_name', $node->get('mgn_name'));
			// The main box reaches the relay over the tunnel at its WireGuard IP.
			$relay->set('mrl_host', (string)$node->get('mgn_wg_ip') ?: '10.99.0.1');
			if ($public_ip !== '') { $relay->set('mrl_public_ip', $public_ip); }
			// The steady-state login is the RESTRICTED TENANT ACCOUNT the
			// add-tenant step created (forced command: the tenant shell), never
			// root. The markers carry the coordinates; a self-hosted relay is a
			// fleet of one with slug 'main'.
			$tenant_user = self::extract_marker($job_output, 'TENANT_SSH_USER') ?: 'jt-main';
			$tenant_spool = self::extract_marker($job_output, 'TENANT_SPOOL') ?: '/var/spool/joinery-relay/main';
			$tenant_slug = self::extract_marker($job_output, 'TENANT_SLUG') ?: 'main';
			$relay->set('mrl_tenant_slug', substr($tenant_slug, 0, 28));
			// The MX hostname the admin provisioned with — the topology-aware
			// setup checks prescribe every domain's MX against it.
			$mail_hostname = strtolower(trim($mail_hostname));
			if ($mail_hostname !== '') {
				$relay->set('mrl_mx_hostname', substr($mail_hostname, 0, 255));
			}
			$relay->set('mrl_ssh_user', substr($tenant_user, 0, 50));
			$relay->set('mrl_ssh_port', intval($node->get('mgn_ssh_port')) ?: 22);
			// The relay's steady-state connections run as the WEB USER (cron tasks,
			// health battery), so the row points at the web-user-owned pull key the
			// provision job authorized — never the node's admin key, which the web
			// user cannot read (ssh demands caller-owned mode-600 key files).
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
			$pull_key = RelaySsh::pullKeyPath();
			$relay->set('mrl_ssh_key_path', is_file($pull_key) ? $pull_key : (string)$node->get('mgn_ssh_key_path'));
			$relay->set('mrl_spool_path', substr($tenant_spool, 0, 500));
			if ($wg_pubkey !== '') { $relay->set('mrl_wg_public_key', substr($wg_pubkey, 0, 255)); }
			$relay->set('mrl_wg_endpoint', (string)$node->get('mgn_wg_endpoint'));
			$relay->set('mrl_wg_ip', (string)$node->get('mgn_wg_ip'));
			if (!$existing) {
				// Born enabled: pulling and map pushes start immediately, so the
				// relay is ready before any MX points at it. Doctrine effects key
				// off the recorded cutover state; Disable is an emergency stop.
				$relay->set('mrl_is_enabled', true);
			}
			$relay->save();
			// Mint the ambient transport keypair now so the first map push can seal
			// Standard/Private mail immediately once enabled.
			$relay->ensureTransportKeypair();
		} catch (\Throwable $e) {
			error_log('JobResultProcessor::register_relay_row failed: ' . $e->getMessage());
		}
	}

	/**
	 * Pull the value of an `echo KEY=value` marker line out of streamed job output
	 * (the last occurrence wins). Returns '' when absent.
	 */
	private static function extract_marker(string $output, string $key): string {
		if (preg_match_all('/^' . preg_quote($key, '/') . '=(.*)$/m', $output, $m) && !empty($m[1])) {
			return trim((string)end($m[1]));
		}
		return '';
	}

	/**
	 * Send the post-provisioning welcome email to the customer via getjoinery's
	 * QueuedEmail API. Reads credentials from Server Manager plugin settings.
	 * Silently returns on any failure — email delivery is best-effort.
	 * Public: ProvisionCustomerCloud's failed-provision recovery path also
	 * sends it (for completed retry jobs that carry no order-item linkage).
	 */
	public static function send_provisioning_welcome_email($job, $node) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/GetJoineryApiClient.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));

		$settings   = Globalvars::get_instance();
		$api_url    = $settings->get_setting('server_manager_getjoinery_api_url');
		$pub_key    = $settings->get_setting('server_manager_getjoinery_api_public_key');
		$sec_key    = ProvisioningSetup::readApiSecret();
		$from_email = $settings->get_setting('server_manager_provisioning_welcome_from_email') ?: 'support@getjoinery.com';
		$from_name  = $settings->get_setting('server_manager_provisioning_welcome_from_name')  ?: 'Get Joinery Support';

		if (!$api_url || !$pub_key || !$sec_key) return;

		$params = $job->get('mjb_parameters');
		$params = is_string($params) ? json_decode($params, true) : $params;
		$params = is_array($params) ? $params : [];

		$domain      = $params['domain'] ?? '';
		$admin_email = $params['admin_email'] ?? '';
		$user_name   = $params['user_name'] ?? 'Customer';

		if (!$admin_email || !$domain) return;

		// Resolve host IP for the DNS A-record instruction: shared-host nodes
		// live on a ManagedHost machine; customer-cloud nodes have no host row
		// — the node's own address is the DNS target.
		$host_ip = '';
		$host_id = $node->get('mgn_mgh_host_id');
		if ($host_id) {
			try {
				$host    = new ManagedHost($host_id, true);
				$host_ip = (string)$host->get('mgh_host');
			} catch (Exception $e) {}
		}
		if ($host_ip === '') {
			$host_ip = (string)$node->get('mgn_host');
		}

		$client = new GetJoineryApiClient($api_url, $pub_key, $sec_key);
		$client->post('QueuedEmail', [
			'equ_from'      => $from_email,
			'equ_from_name' => $from_name,
			'equ_to'        => $admin_email,
			'equ_to_name'   => $user_name,
			'equ_subject'   => 'Your site is ready: ' . $domain,
			'equ_body'      => self::build_welcome_email_body($domain, $host_ip, $user_name),
			'equ_status'    => 2, // READY_TO_SEND
		]);
	}

	private static function build_welcome_email_body($domain, $host_ip, $user_name) {
		$name      = htmlspecialchars($user_name);
		$dom       = htmlspecialchars($domain);
		$ip        = htmlspecialchars($host_ip);
		$login_url = htmlspecialchars('https://' . $domain . '/admin');

		return <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333">
<h2 style="color:#1a1a1a">Your site is ready!</h2>
<p>Hi {$name},</p>
<p>Your Joinery site for <strong>{$dom}</strong> has been installed successfully.</p>

<h3>Next step: point your DNS</h3>
<p>Add an <strong>A record</strong> for <code>{$dom}</code> pointing to:</p>
<p style="font-size:1.5em;text-align:center;font-weight:bold;letter-spacing:.05em;background:#f4f4f4;padding:12px;border-radius:4px">{$ip}</p>
<p>DNS changes typically propagate in a few minutes to a few hours. Once your domain resolves to that IP, HTTPS will be provisioned automatically — no action needed on your part.</p>

<h3>Log in</h3>
<p>After DNS resolves, your admin panel is at:<br>
<a href="{$login_url}">{$login_url}</a></p>

<p style="color:#666;font-size:.9em">Questions? Reply to this email or contact support@getjoinery.com.</p>
<p>— The Get Joinery Team</p>
</body></html>
HTML;
	}

	/**
	 * On provision_ssl success, flip mgn_ssl_state to active.
	 * Failure tracking and the 16h give-up logic live in ProvisionPendingSsl.
	 */
	private static function process_provision_ssl($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$rdns_attempt = null;
		if ($job->get('mjb_status') === 'completed') {
			$output = $job->get('mjb_output') ?: '';
			$is_cf  = (strpos($output, 'SSL_SKIPPED_CLOUDFLARE') !== false);

			// Belt-and-braces on the Cloudflare path: the build fails the job
			// when the routing probe misses, so a completed CF job should always
			// carry CF_ROUTING_VERIFIED — but "active" is a promise to stop
			// watching this domain, so it is only made on explicit evidence that
			// traffic for the domain reaches this node.
			if ($is_cf && strpos($output, 'CF_ROUTING_VERIFIED') === false) {
				$result = ['ssl_state' => $node->get('mgn_ssl_state'), 'note' => 'cloudflare path completed without routing verification'];
				$job->set('mjb_result', json_encode($result));
				$job->save();
				return;
			}

			$was_active = ($node->get('mgn_ssl_state') === 'active');
			$node->set('mgn_ssl_state', 'active');
			$node->save();

			// The cert just issued, so the domain provably resolves to this
			// box — the provider's precondition for accepting it as reverse
			// DNS. Set the PTR through the birth provision's grant now, so a
			// standalone site never needs the control-plane panel. Best-effort
			// and first-issuance-only: a stale grant or manual node leaves the
			// PTR to the mailbox Setup tab checklist, and a later custom PTR
			// is never overwritten by a cert renewal. Not on the Cloudflare
			// path: there the domain's A records are Cloudflare's, which is
			// exactly what the PTR provider would reject.
			if (!$was_active && !$is_cf) {
				$params = json_decode($job->get('mjb_parameters') ?: '', true);
				$rdns_domain = is_array($params) && !empty($params['domain'])
					? $params['domain']
					: (parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '');
				if ($rdns_domain && !filter_var($rdns_domain, FILTER_VALIDATE_IP)) {
					require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
					$rdns_attempt = NodeReverseDns::setQuietly($node, $rdns_domain);
				}
			}
		}

		$result = ['ssl_state' => $node->get('mgn_ssl_state')];
		if ($rdns_attempt !== null) {
			$result['rdns_attempt'] = $rdns_attempt;
		}
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Parse discover_nodes output into structured instance data.
	 */
	private static function process_discover_nodes($job) {
		$output = $job->get('mjb_output') ?: '';
		$params = $job->get('mjb_parameters');
		$params = is_string($params) ? json_decode($params, true) : $params;

		$result = [
			'host' => $params['host'] ?? '',
			'ssh_user' => $params['ssh_user'] ?? 'root',
			'ssh_key_path' => $params['ssh_key_path'] ?? '',
			'ssh_port' => intval($params['ssh_port'] ?? 22),
			'hostname' => '',
			'has_docker' => true,
			'instances' => [],
		];

		// Parse hostname from connection test
		if (preg_match('/CONNECT_OK\s*\n\s*(.+)/m', $output, $m)) {
			$result['hostname'] = trim($m[1]);
		}

		// Check for NO_DOCKER
		if (strpos($output, 'NO_DOCKER') !== false) {
			$result['has_docker'] = false;
		}

		// Parse JOINERY_INSTANCE lines
		// Format: JOINERY_INSTANCE|type|name|web_root|domain|db_name|version
		//         m[1]=type, m[2]=name, m[3]=web_root, m[4]=domain, m[5]=db_name, m[6]=version
		if (preg_match_all('/JOINERY_INSTANCE\|([^|]*)\|([^|]*)\|([^|]*)\|([^|]*)\|([^|]*)\|(.*)$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$name = trim($m[2]);
				$domain = trim($m[4]);
				$instance = [
					'type' => trim($m[1]),
					'container_name' => (trim($m[1]) === 'docker') ? $name : '',
					'name' => ucwords(str_replace(['-', '_'], ' ', $name)),
					'slug' => strtolower($name),
					'web_root' => trim($m[3]),
					'site_url' => $domain ? 'https://' . $domain : '',
					'db_name' => trim($m[5]),
					'version' => trim($m[6]),
				];
				$result['instances'][] = $instance;
			}
		}

		// Check which slugs already exist as nodes
		$existing_slugs = [];
		$existing_nodes = new MultiManagedNode(['deleted' => false]);
		$existing_nodes->load();
		foreach ($existing_nodes as $en) {
			$existing_slugs[] = $en->get('mgn_slug');
		}
		foreach ($result['instances'] as &$inst) {
			$inst['already_added'] = in_array($inst['slug'], $existing_slugs);
		}
		unset($inst);

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Parse list_backups output into a local-file list. Cloud listings are
	 * fetched web-server-side at display time via TargetLister, merged by
	 * BackupListHelper.
	 *
	 * Handles both transports:
	 *   - API path: JSON envelope with data.files[] (already structured).
	 *   - SSH path: LOCAL|size_bytes|mtime_epoch|filepath lines.
	 */
	private static function process_list_backups($job) {
		$output = $job->get('mjb_output') ?: '';
		$files = [];

		$api_data = self::extract_api_envelope_data($output);
		if (is_array($api_data) && isset($api_data['files']) && is_array($api_data['files'])) {
			$files = $api_data['files'];
		} elseif (preg_match_all('/^LOCAL\|(\d+)\|(\d+)\|(.+)$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$path = trim($m[3]);
				$filename = basename($path);
				$size_bytes = intval($m[1]);
				$mtime = intval($m[2]);
				$files[] = [
					'filename' => $filename,
					'size' => self::format_size($size_bytes),
					'size_bytes' => $size_bytes,
					'date' => gmdate('Y-m-d', $mtime),
					'mtime' => $mtime,
					'local_path' => $path,
					'cloud_path' => null,
					'location' => 'local',
				];
			}
		}

		usort($files, function($a, $b) { return ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0); });

		$job->set('mjb_result', json_encode(['files' => $files]));
		$job->save();
	}

	/**
	 * Process delete_backup result.
	 */
	private static function process_delete_backup($job) {
		$output = $job->get('mjb_output') ?: '';
		$result = [
			'local_deleted' => strpos($output, 'LOCAL_DELETE_OK') !== false,
			'cloud_deleted' => strpos($output, 'CLOUD_DELETE_OK') !== false,
		];

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Finalize a permanent node deletion once the host teardown is verified.
	 *
	 * Only a completed job whose output carries DECOMMISSION_VERIFIED (and NOT
	 * DECOMMISSION_FAILED_VERIFY) finalizes the record — the site is genuinely gone
	 * from the host. A failed or unverified job leaves the node intact and enabled so
	 * the operator can retry; we never leave a half-deleted record pointing at a live
	 * site. The record is soft-deleted, not hard-deleted: the port reservation, the job
	 * history, and the backup-key escrow rows all survive (the escrow FK is SET NULL and
	 * soft-delete triggers no cascade), so the node's offsite backups stay recoverable.
	 */
	private static function process_decommission_node($job) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

		$output = (string)($job->get('mjb_output') ?: '');
		$status = (string)$job->get('mjb_status');
		$verified = strpos($output, 'DECOMMISSION_VERIFIED') !== false
			&& strpos($output, 'DECOMMISSION_FAILED_VERIFY') === false;

		if ($status === 'completed' && $verified) {
			$soft_deleted = false;
			$node_id = $job->get('mjb_mgn_node_id');
			if ($node_id) {
				$node = new ManagedNode($node_id, TRUE);
				if ($node->key && !$node->get('mgn_delete_time')) {
					$node->soft_delete();
					$soft_deleted = true;
				}
			}
			$job->set('mjb_result', json_encode([
				'status' => 'completed',
				'decommissioned' => true,
				'node_soft_deleted' => $soft_deleted,
			]));
			$job->save();
			return;
		}

		$note = $status !== 'completed'
			? 'Host teardown did not complete; node left intact.'
			: 'Teardown ran but the site could not be verified gone; node left intact.';
		$job->set('mjb_result', json_encode([
			'status' => $status,
			'decommissioned' => false,
			'note' => $note,
		]));
		$job->save();
	}

	/**
	 * Format bytes into human-readable size.
	 */
	private static function format_size($bytes) {
		if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
		if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'M';
		if ($bytes >= 1024) return round($bytes / 1024, 1) . 'K';
		return $bytes . 'B';
	}
}
?>
