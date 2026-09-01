<?php
/**
 * JobResultProcessor - Parses completed job output into structured data.
 *
 * Called when a job transitions to 'completed'. Extracts meaningful data
 * from raw command output and updates related records.
 *
 * @version 1.19 - process_decommission_node reads the primitive envelope first and finalizes the
 *                 VICTIM named in the job params — the job's subject is now the HOST that ran the
 *                 teardown (docker_host_agent.md)
 * @version 1.18 - process_backup_database and process_backup_project are gone with their
 *                 builders; backup_run is the one backup result that stamps a node
 * @version 1.17 - the managed-domain pair: process_managed_domain_prepare reads the node's mail plan
 *                 off the job, and process_managed_domain_notice puts the type in the sweep
 * @version 1.16 - process_backup_run unwraps the primitive/API JSON envelope before parsing BACKUP_RESULT,
 *                 so a backup that ran over the primitive transport is stamped by its real verdict instead
 *                 of reading as failed fleet-wide (the /m anchors missed the envelope's escaped newlines)
 * @version 1.15 - the agent SSL chain reports what it did rather than that it ran: provision_certificate
 *                 is classified from its output (setup_ssl.sh exits 0 whether or not it issued anything),
 *                 and the probe place/clear results are recorded, with a replaced token logged
 * @version 1.14 - one fold path for node status, with per-key provenance: every writer records which
 *                 transport measured each key and when, unmeasured keys carry with their ORIGINAL
 *                 measured time, and a key nothing has measured for 30 days is dropped. Adds the
 *                 run_plugin_installers and restart_agent handlers, and dedupes the recovery_key_report
 *                 job a primitive status check queues
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

	/** Where the per-key provenance lives, inside the status blob itself. */
	const STATUS_META_KEY = 'status_meta';

	/** The derived display list: which keys this blob inherited rather than measured. */
	const STATUS_CARRIED_KEY = 'status_carried_keys';

	/** The provenance entry describing the fold itself rather than any one key. */
	const STATUS_FOLD_KEY = '_fold';

	/** Provenance for a key that predates provenance — measured at an unknown time. */
	const STATUS_TRANSPORT_LEGACY = 'legacy';

	/** A key nothing has measured for this long is dropped rather than carried forever. */
	const STATUS_MAX_UNMEASURED_DAYS = 30;

	/** Past this age, a reading is too old to colour a health badge with. */
	const STATUS_STALE_AFTER_SECONDS = 21600; // 6 hours

	/**
	 * Fold a freshly measured set of node facts into the ones already stored.
	 *
	 * EVERY writer of mgn_last_status_data comes through here. There are four —
	 * a check_status job (agent primitive, API envelope or SSH text), the
	 * dashboard's synchronous API refresh, the recovery-key report, and the
	 * dashboard refresh button's HTTP fallback, which learns only a version — and
	 * before this they disagreed about what a missing key meant. The job path
	 * carried absent keys forward; the API refresh replaced the whole blob. So
	 * the same node's facts survived or were deleted depending on which writer
	 * happened to run last, and nothing recorded which transport had measured
	 * what.
	 *
	 * The rule is that transports answer different questions, so an absent key
	 * means "this transport did not measure it" and never "the node stopped
	 * having one". The agent can read /etc/letsencrypt, which the API (running
	 * as the web user) cannot; the API can call BackupRecoveryKey::key_report(),
	 * which the agent has no way to invoke.
	 *
	 * Carrying alone is not enough, because a carried value keeps looking current
	 * forever. So each key carries a stamp beside it:
	 *
	 *   status_meta: {
	 *     "<key>":  {"t": "<transport>", "m": "<UTC time it was measured>"},
	 *     "<key>":  {"t": "legacy", "m": null, "s": "<UTC time this fold first saw it>"},
	 *     "_fold":  {"t": "<transport>", "m": "<UTC time of this fold>"}
	 *   }
	 *
	 * A writer stamps only the keys it actually measured. A carried key keeps its
	 * ORIGINAL stamp, so its age is the age of the measurement, not of the carry.
	 * 'legacy' is the honest answer for a key inherited from a blob written before
	 * provenance existed: we know we hold the value and not when it was taken, so
	 * `m` is null and readers treat its age as unknowable rather than fresh. `s`
	 * starts its expiry clock, which is the only thing about it we can date.
	 *
	 * Ageing is what retires a key the fleet no longer measures. `databases` was
	 * superseded by `db_list` and five ssl_* fields were replaced by the
	 * certificate enumeration, and every one of them sat in all nine nodes' blobs
	 * indefinitely because carry-forward has no expiry of its own.
	 *
	 * @param string|array|null $previous      the stored blob (raw JSON or decoded)
	 * @param array             $measured      what THIS writer actually measured
	 * @param string            $transport     'primitive' | 'api' | 'ssh'
	 * @param string|null       $measured_time UTC 'Y-m-d H:i:s'; defaults to now
	 * @return array the blob to store
	 */
	public static function fold_status_data($previous, array $measured, $transport, $measured_time = null) {
		$now       = $measured_time ?: gmdate('Y-m-d H:i:s');
		$transport = (string)$transport;

		if (is_string($previous)) {
			$previous = json_decode($previous, true);
		}
		if (!is_array($previous)) {
			$previous = [];
		}

		$meta = [];
		if (isset($previous[self::STATUS_META_KEY]) && is_array($previous[self::STATUS_META_KEY])) {
			$meta = $previous[self::STATUS_META_KEY];
		}
		// The fold owns these two; they are never data and never carried as data.
		unset($previous[self::STATUS_META_KEY], $previous[self::STATUS_CARRIED_KEY]);
		unset($measured[self::STATUS_META_KEY], $measured[self::STATUS_CARRIED_KEY]);

		$folded   = $measured;
		$new_meta = [];
		foreach ($measured as $key => $ignored) {
			$new_meta[$key] = ['t' => $transport, 'm' => $now];
		}

		// DB times are ISO-formatted UTC, so string comparison is the ordering.
		$cutoff = gmdate('Y-m-d H:i:s',
			strtotime($now . ' UTC') - (self::STATUS_MAX_UNMEASURED_DAYS * 86400));

		foreach ($previous as $key => $value) {
			if (array_key_exists($key, $folded)) {
				continue; // measured this time; the fresh stamp stands
			}
			$stamp = (isset($meta[$key]) && is_array($meta[$key]) && array_key_exists('m', $meta[$key]))
				? $meta[$key]
				: ['t' => self::STATUS_TRANSPORT_LEGACY, 'm' => null, 's' => $now];

			// Age from the measurement when we have one, from first sight when we
			// do not. Either way the clock is running, so a key the fleet has
			// stopped measuring leaves rather than becoming permanent furniture.
			$age_from = $stamp['m'] ?: ($stamp['s'] ?? $now);
			if ((string)$age_from < $cutoff) {
				continue;
			}
			$folded[$key]   = $value;
			$new_meta[$key] = $stamp;
		}

		$carried = [];
		foreach ($new_meta as $key => $stamp) {
			if (($stamp['m'] ?? null) !== $now) {
				$carried[] = $key;
			}
		}
		sort($carried);

		$new_meta[self::STATUS_FOLD_KEY] = ['t' => $transport, 'm' => $now];
		$folded[self::STATUS_META_KEY]   = $new_meta;
		if ($carried) {
			$folded[self::STATUS_CARRIED_KEY] = $carried;
		}
		return $folded;
	}

	/** The provenance map out of a status blob, or an empty one. */
	public static function status_meta($status) {
		if (is_string($status)) {
			$status = json_decode($status, true);
		}
		if (!is_array($status) || !isset($status[self::STATUS_META_KEY])
				|| !is_array($status[self::STATUS_META_KEY])) {
			return [];
		}
		return $status[self::STATUS_META_KEY];
	}

	/**
	 * When anything in this blob was last actually measured, or null.
	 *
	 * This is the honest answer to "last checked". mgn_last_status_check answers
	 * a different question — when a check last RAN — and a check that reached the
	 * node and read nothing off it stamps that column while measuring nothing.
	 * The fold stamp (_fold) is excluded for the same reason: a fold that folded
	 * an empty measurement is not a measurement.
	 */
	public static function status_last_measured($status) {
		$newest = null;
		foreach (self::status_meta($status) as $key => $stamp) {
			if ($key === self::STATUS_FOLD_KEY || !is_array($stamp)) {
				continue;
			}
			$m = $stamp['m'] ?? null;
			if ($m && ($newest === null || (string)$m > $newest)) {
				$newest = (string)$m;
			}
		}
		return $newest;
	}

	/**
	 * How old, in seconds, the OLDEST of the named readings is — or null when
	 * that cannot be known.
	 *
	 * Oldest rather than newest because a reader that draws one conclusion from
	 * several figures is only as current as its stalest input: a health badge
	 * computed from disk, postgres and load is not fresh because the load number
	 * is. Null is returned when any named key is present without a measurement
	 * stamp (a legacy carry), and that is not the same as "no data" — it means we
	 * hold a figure and cannot date it, which a caller must not render as fresh.
	 */
	public static function status_age_seconds($status, array $keys) {
		if (is_string($status)) {
			$status = json_decode($status, true);
		}
		if (!is_array($status)) {
			return null;
		}
		$meta   = self::status_meta($status);
		$oldest = null;
		foreach ($keys as $key) {
			if (!array_key_exists($key, $status)) {
				continue;
			}
			$m = (isset($meta[$key]) && is_array($meta[$key])) ? ($meta[$key]['m'] ?? null) : null;
			if (!$m) {
				return null;
			}
			if ($oldest === null || (string)$m < $oldest) {
				$oldest = (string)$m;
			}
		}
		if ($oldest === null) {
			return null;
		}
		return max(0, time() - strtotime($oldest . ' UTC'));
	}

	/**
	 * Are the named readings too old to draw a health conclusion from?
	 *
	 * True also when their age cannot be established, which is the case that used
	 * to read as green: a figure of unknown age is not a figure you may colour a
	 * badge with.
	 */
	public static function status_figures_are_stale($status, array $keys) {
		$age = self::status_age_seconds($status, $keys);
		return ($age === null) || ($age > self::STATUS_STALE_AFTER_SECONDS);
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

		// The agent enumerates every certificate lineage on the node rather than
		// answering about one name the plane chose. Matching the node's expected
		// host against what it reported happens HERE, so the node stays ignorant
		// of what this plane believes it is called.
		//
		// Folded into the same token shape the SSH step produced, deliberately:
		// the branch below already does the right thing with "no certificate" —
		// it falls through to an HTTPS probe, which is what catches a
		// Cloudflare-terminated site that has no origin certificate at all and is
		// perfectly healthy. A zero from the node must never short-circuit that.
		if ($ssl_token === null && $node) {
			$ssl_token = self::ssl_token_from_certificates($result, $node);
			if ($ssl_token !== null) {
				// Lets a view tell "the node looked and found none" apart from
				// "this transport cannot see certificates". They are not the same
				// fact, and rendering the second as the first reads as a node
				// that lost its certificate.
				$result['ssl_source'] = 'node_enumeration';
			}
		}

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

			// Fold what this report measured into what the node already carried.
			// fold_status_data owns the carry-forward rule and stamps each key
			// with the transport that measured it, so a later reader can date a
			// figure instead of assuming the whole blob is as fresh as the check
			// that last touched it.
			//
			// Naming the transport honestly matters here: a primitive check_status
			// arrives in the same envelope shape as an API one, and they do NOT
			// measure the same things. backup_recovery_state comes only from the
			// API/SSH path — RecoveryKeyFleet gates every backup on it — so a
			// primitive check that claimed to have measured it would let the next
			// fold treat a stale answer as current.
			$transport = $job->isPrimitiveJob() ? 'primitive' : ($is_api_path ? 'api' : 'ssh');
			$folded    = self::fold_status_data($node->get('mgn_last_status_data'), $result, $transport);

			$node->set('mgn_last_status_data', json_encode($folded));
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
			// carrying a key the management node did not put there, because that
			// is the one case the push deliberately walks away from.
			if (isset($folded['backup_recovery_state'])) {
				$node->set('mgn_backup_recovery_fpr', (string)($folded['backup_recovery_fpr'] ?? ''));
			}

			// What the node says about MY backups of it. The node's history is the
			// authority — it is the only record that includes runs which failed —
			// so a status check refreshes the fleet's copy from it rather than
			// trusting what the last job happened to stamp. The site's own profile
			// travels in the same payload and is kept as information, never
			// promoted into a fleet problem.
			if (isset($folded['backups']['manager'])) {
				$mgr = $folded['backups']['manager'];
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

			// If backup_recovery_state was INHERITED rather than measured, ask
			// the node for it now.
			//
			// The primitive transport cannot produce that field — it comes from
			// BackupRecoveryKey::key_report(), PHP the agent cannot call — and
			// every backup of this node is gated on it. Carrying it forward stops
			// a primitive status check from deleting the answer, but carried is
			// not measured: a node whose recovery key genuinely changed would go
			// unnoticed for as long as only primitives ran. So a primitive status
			// check schedules the one small observe job that CAN measure it, and
			// the answer is fresh again by the time anything reads it.
			//
			// Queued rather than done inline because measuring it means running a
			// script on the node, which is a job, not a page render.
			//
			// Asked for at most once per node per six hours. Every primitive
			// status check carries backup_recovery_state forward, so every one of
			// them wants this job; the fleet status sweep runs them together and
			// on 08-28 that queued 33 identical reports across nine nodes inside a
			// minute. A recovery key that changes does so at a human's pace, so
			// one measurement per node in a six-hour window is as fresh as the
			// answer can usefully be, and the check below also refuses to pile on
			// a report that is already queued or running.
			$carried = $folded[self::STATUS_CARRIED_KEY] ?? [];
			if (in_array('backup_recovery_state', $carried, true)
					&& JobCommandBuilder::has_primitive($node, 'recovery_key_report')
					&& !ManagementJob::activeOrRecentForNode($node->key, 'recovery_key_report', 6 * 3600)) {
				try {
					$built = JobCommandBuilder::build_recovery_key_report($node);
					ManagementJob::createFromBuild($node->key, 'recovery_key_report', $built, null,
						$job->get('mjb_created_by'));
				} catch (Exception $e) {
					// Not being able to ask is not a reason to fail the status
					// check that just succeeded.
				}
			}

			// An empty slot is REPORTED and nothing else. That slot holds the key
			// for the site's own backups, and its custodian is whoever
			// administers the site — filling it from here would make this management
			// node the holder of the private half of a key the site believes is
			// its own. A management node's own backups of this node need nothing in
			// it: the manager profile carries its key with each run.
			// The first confirmation of an active cert doubles as the
			// reverse-DNS moment for cloud-born nodes: the domain has just
			// proven it resolves to this box, which is the provider's
			// precondition for accepting it as rDNS. Best-effort and
			// transition-only — a stale grant or manual node leaves the PTR
			// to the mailbox Setup tab checklist, and a later custom PTR is
			// never overwritten by routine status checks.
			if ($ssl_new_state === 'active' && $prev_ssl_state !== 'active') {
				$rdns_domain = $folded['ssl_domain']
					?? (parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '');
				if ($rdns_domain && !filter_var($rdns_domain, FILTER_VALIDATE_IP)) {
					require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
					$result['rdns_attempt'] = NodeReverseDns::setQuietly($node, $rdns_domain);
				}
			}
		}

		// What THIS job measured, which is not what the node now holds — the node
		// carries the fold, this carries the reading. A job result that repeated
		// the inherited keys read as a job that had measured them.
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
	/**
	 * Build the same token from the agent's enumerated certificate lineages.
	 *
	 * The node lists every lineage in /etc/letsencrypt/live and says nothing
	 * about which one matters; deciding that is this plane's job, because the
	 * expected hostname is this plane's belief. The SSH step asked the node
	 * about one name, so a node holding a certificate under a lineage the plane
	 * did not name looked exactly like a node holding none — which is the real
	 * shape of a certbot re-issue writing {domain}-0001 while the vhost still
	 * points at {domain}: a current certificate that is not being served and a
	 * stale one that is.
	 *
	 * Returns null when the node reported no certificate data at all — that is a
	 * transport that cannot see certificates, not a node without one. The
	 * management API runs as the web user and /etc/letsencrypt/live is
	 * drwx------ root, so an API-collected node can never answer this.
	 */
	private static function ssl_token_from_certificates($result, $node) {
		// Key on the COUNT, not the list. The collector reports
		// ssl_certificate_count = 0 and returns without setting ssl_certificates
		// when /etc/letsencrypt/live does not exist — a complete answer, and the
		// common one on a Cloudflare-terminated node. Keying on the list made
		// that read as "this transport did not look", so five derived ssl_ fields
		// fell through to carry-forward on every primitive status check and were
		// permanently inherited.
		if (!array_key_exists('ssl_certificate_count', $result)) {
			return null;
		}
		// Looked and could not see. Not an absence, and not something to derive
		// a state from — leave the previous answer standing.
		if (!empty($result['ssl_certificates_unreadable'])) {
			return null;
		}
		$domain = parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '';
		if ($domain === '') {
			return null;
		}

		// Absent means zero: see the count check above.
		$certs = isset($result['ssl_certificates']) && is_array($result['ssl_certificates'])
			? $result['ssl_certificates'] : [];
		foreach ($certs as $cert) {
			// A self-signed placeholder is served by Apache and trusted by no
			// browser. Counted as "a certificate" it would read as "TLS is fine".
			if (!empty($cert['self_signed'])) {
				continue;
			}
			$names = isset($cert['domains']) && is_array($cert['domains']) ? $cert['domains'] : [];
			foreach ($names as $name) {
				if (self::cert_name_covers((string)$name, $domain)) {
					return [
						'found'      => true,
						'domain'     => $domain,
						'expiry_raw' => (string)($cert['not_after'] ?? ''),
					];
				}
			}
		}

		// No lineage covers this host — including the zero-certificate case. The
		// caller probes HTTPS from here, which is what recognises a
		// Cloudflare-terminated site with no origin certificate as healthy.
		return ['found' => false, 'domain' => $domain];
	}

	/** Does a certificate name cover this host? Exact, or a single-label wildcard. */
	private static function cert_name_covers($name, $domain) {
		$name   = strtolower(trim($name));
		$domain = strtolower($domain);
		if ($name === '' || $domain === '') return false;
		if ($name === $domain) return true;

		if (strpos($name, '*.') === 0) {
			// *.example.com covers a.example.com and NOT a.b.example.com or
			// example.com itself — the same rule browsers apply.
			$suffix = substr($name, 1);
			if (strlen($domain) <= strlen($suffix)) return false;
			if (substr($domain, -strlen($suffix)) !== $suffix) return false;
			return strpos(substr($domain, 0, -strlen($suffix)), '.') === false;
		}
		return false;
	}

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
		$verdict = self::parse_backup_run_verdict(
			$job->get('mjb_output') ?: '',
			(string)$job->get('mjb_status')
		);
		$status = $verdict['status'];

		$result = ['backup_status' => $status];
		if ($verdict['message'] !== '') {
			$result['message'] = $verdict['message'];
		}

		$node_id = $job->get('mjb_mgn_node_id');
		if ($node_id && $status !== 'skipped') {
			try {
				$node = new ManagedNode($node_id, TRUE);
				$node->set('mgn_last_backup_time', $verdict['time'] !== '' ? $verdict['time'] : gmdate('Y-m-d H:i:s'));
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
	 * Read a manager-profile run's verdict out of its output. Pure — no DB, no
	 * job mutation — so both wire shapes can be pinned by the fold test.
	 *
	 * The verdict comes from the machine-readable BACKUP_RESULT line, not the
	 * exit status: the runner exits 0 for a run it SKIPPED (another backup was
	 * already in progress), and a skip is neither success nor failure. The time
	 * comes from the BACKUP_TIME line (when the run STARTED, as the node's own
	 * history records it) so the stamp means one thing whichever path wrote it.
	 *
	 * The primitive/API transport wraps that text in a JSON envelope
	 * ({api_version,data:{output}}), where the runner's newlines survive as
	 * literal \n escapes — so unwrapping FIRST is what lets the /m anchors below
	 * find a line start. Without it every primitive-transport run parses as
	 * 'unknown' and stamps 'failed'. The plain SSH path has no envelope, so the
	 * helper returns null and the raw output stands.
	 *
	 * @return array{status:string,time:string,message:string}
	 */
	public static function parse_backup_run_verdict(string $output, string $job_status): array {
		$envelope = self::extract_api_envelope_data($output);
		if ($envelope !== null && isset($envelope['output']) && is_string($envelope['output'])) {
			$output = $envelope['output'];
		}

		$status = 'unknown';
		if (preg_match('/^BACKUP_RESULT=(\w+)$/m', $output, $m)) {
			$status = $m[1];
		} elseif ($job_status === 'failed') {
			// The step never got far enough to say anything — a failed job with
			// no verdict is a failed backup, not an unknown one.
			$status = 'error';
		}

		$time = '';
		if (preg_match('/^BACKUP_TIME=(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})$/m', $output, $m)) {
			$time = $m[1];
		}

		$message = '';
		if (preg_match('/^\[[^\]]+\] manager \w+: (.+)$/m', $output, $m)) {
			$message = trim($m[1]);
		}

		return ['status' => $status, 'time' => $time, 'message' => $message];
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
			// standalone site never needs the management-node panel. Best-effort
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
	 * Record what a provision_certificate job actually achieved.
	 *
	 * THE JOB'S STATUS DOES NOT ANSWER THIS, and that is the whole reason this
	 * handler is more than a status copy. setup_ssl.sh ends `return 0` on every
	 * branch by design — issued, fell back to DNS-01, or found no challenge path
	 * at all — so that a site which cannot get a certificate stays on HTTP
	 * rather than failing its install. A handler that read 'completed' as
	 * success would set this node's SSL to active while it holds nothing, and
	 * nothing on the dashboard would look wrong.
	 *
	 * SslProvisionOutcome reads the output and separates the states that need
	 * different things from a person — in particular a missing
	 * /etc/letsencrypt/{provider}.ini, which is one file away from working and
	 * which the script has already named — so the recorded result can say which
	 * one it was instead of "completed".
	 */
	private static function process_provision_certificate($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$outcome = SslProvisionOutcome::classify($job->get('mjb_output') ?: '');
		$result  = [
			'ssl_outcome' => $outcome['state'],
			'detail'      => $outcome['detail'],
			'ssl_state'   => $node->get('mgn_ssl_state'),
		];

		// A job that never ran tells us nothing about the certificate; only the
		// terminal-failure fact is recorded.
		if ($job->get('mjb_status') !== 'completed') {
			$job->set('mjb_result', json_encode($result));
			$job->save();
			return;
		}

		if (!SslProvisionOutcome::is_issued($outcome['state'])) {
			$result['needs_operator'] = SslProvisionOutcome::needs_operator($outcome['state']);
			$job->set('mjb_result', json_encode($result));
			$job->save();
			return;
		}

		$was_active = ($node->get('mgn_ssl_state') === 'active');
		$node->set('mgn_ssl_state', 'active');
		$node->save();

		$result['ssl_state'] = 'active';
		$result['challenge'] = $outcome['challenge'];

		// Reverse DNS, on first issuance only, and only where the certificate is
		// itself the evidence. HTTP-01 cannot have succeeded unless the domain
		// resolves to this box, which is exactly the precondition the PTR
		// provider checks. DNS-01 proves control of the zone and says nothing
		// about where the name points — on a Cloudflare-proxied domain the A
		// records are Cloudflare's, which is what the provider would reject.
		// Best-effort: a stale grant leaves the PTR to the Setup tab checklist,
		// and a later custom PTR is never overwritten by a renewal.
		if (!$was_active && $outcome['challenge'] === SslProvisionOutcome::CHALLENGE_HTTP_01) {
			$params = json_decode($job->get('mjb_parameters') ?: '', true);
			$rdns_domain = is_array($params) && !empty($params['domain'])
				? $params['domain']
				: (parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '');
			if ($rdns_domain && !filter_var($rdns_domain, FILTER_VALIDATE_IP)) {
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
				$result['rdns_attempt'] = NodeReverseDns::setQuietly($node, $rdns_domain);
			}
		}

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Record a routing-probe placement.
	 *
	 * The one fact here worth more than the status is `replaced`. The node
	 * overwrites an existing token on purpose — refusing would wedge a domain
	 * permanently whenever a probe died between place and clear — but a
	 * replacement still means either that an earlier probe leaked or that two
	 * are racing on one node, and both are worth knowing BEFORE somebody starts
	 * debugging it as a Cloudflare problem. So it is logged, not just stored.
	 */
	private static function process_ssl_probe_place($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');

		$result = ['placed' => false];
		if (is_array($data) && array_key_exists('placed', $data)) {
			$result = [
				'placed'   => (bool)$data['placed'],
				'replaced' => !empty($data['replaced']),
				'filename' => $data['filename'] ?? null,
			];
			if (!empty($data['replaced'])) {
				error_log('JobResultProcessor: SSL routing probe on node '
					. $job->get('mjb_mgn_node_id') . ' replaced a token that was already there — '
					. 'an earlier probe did not clean up, or two are in flight.');
			}
		}

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Record a routing-probe cleanup.
	 *
	 * Nothing to clear is success, not a failure: the request names an end state
	 * — no probe token on this node — and a file already gone satisfies it. The
	 * result keeps `was_present` for anyone who cares which of the two happened.
	 */
	private static function process_ssl_probe_clear($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');

		$result = ['cleared' => false];
		if (is_array($data) && array_key_exists('cleared', $data)) {
			$result = [
				'cleared'     => true,
				'was_present' => (bool)$data['cleared'],
				'filename'    => $data['filename'] ?? null,
			];
		}

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * managed_domain_prepare: the mail DNS the node says its own topology needs.
	 *
	 * The utility prints ONE JSON line — {"ok":…,"dkim_ready":…,"records":[…]} —
	 * and the agent wraps that text inside its own envelope, so the line is
	 * behind escaped newlines that no /m anchor matches. Unwrap first, then read
	 * the LAST decodable line: anything before it is noise from the site's own
	 * bootstrap.
	 *
	 * ok:false and dkim_ready:false are both recorded rather than flattened into
	 * a failure. They are different facts and the caller branches on both: a
	 * refusal is retried, while records without DKIM are published anyway — MX
	 * and SPF are what make mail arrive — and the step is left open so the
	 * signing key still gets published later.
	 */
	private static function process_managed_domain_prepare($job) {
		$output = (string)($job->get('mjb_output') ?: '');

		$data = self::extract_api_envelope_data($output);
		if (is_array($data) && isset($data['output'])) {
			$output = (string)$data['output'];
		}

		$payload = null;
		$lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$decoded = json_decode($lines[$i], true);
			if (is_array($decoded) && array_key_exists('ok', $decoded)) {
				$payload = $decoded;
				break;
			}
		}

		if ($payload === null) {
			// A node that answered something unreadable. Recorded as measured:
			// false rather than invented, so the caller retries instead of
			// publishing a record set nobody described.
			$job->set('mjb_result', json_encode(['answered' => false]));
			$job->save();
			return;
		}

		$job->set('mjb_result', json_encode([
			'answered'    => true,
			'ok'          => !empty($payload['ok']),
			'dkim_ready'  => !empty($payload['dkim_ready']),
			'records'     => is_array($payload['records'] ?? null) ? $payload['records'] : [],
			'error'       => (string)($payload['error'] ?? ''),
		]));
		$job->save();
	}

	/**
	 * managed_domain_notice: intentionally thin, like restart_agent.
	 *
	 * There is nothing to fold. The script writes four settings and says so;
	 * whether it did is the job's own terminal status, which process()'s
	 * backstop already records. The handler exists so processable_types() lists
	 * the type deliberately rather than by omission — without one the dashboard
	 * sweep skips it and a terminal notice job keeps mjb_result NULL forever,
	 * which is how the sweep re-processes a job on every render.
	 *
	 * It matters more here than for restart_agent: ManagedDomainWatch decides
	 * whether to re-push by looking for the last COMPLETED notice job, so a job
	 * the sweep never finishes is a push that repeats every tick.
	 */
	private static function process_managed_domain_notice($job) {
		// Deliberately empty: see the docblock.
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

		// The primitive reports a structured result; the SSH steps printed
		// marker lines. Read the structure first — a primitive job carries no
		// LOCAL_DELETE_OK, so grepping alone would record a successful delete
		// as a failed one and the tab would keep showing a file that is gone.
		$data = self::extract_api_envelope_data($output);
		if (is_array($data) && array_key_exists('deleted', $data)) {
			$job->set('mjb_result', json_encode([
				// Deleted, or already absent: both mean the file is not there,
				// which is what was asked for. 'deleted' distinguishes them for
				// anyone who cares which happened.
				'local_deleted' => true,
				'was_present'   => (bool)$data['deleted'],
				'filename'      => $data['filename'] ?? null,
				'freed_bytes'   => $data['freed_bytes'] ?? null,
				// The node never deletes a cloud object — that credential stays
				// on this plane, and backup_actions_logic does it in-process
				// before the job is ever created.
				'cloud_deleted' => false,
			]));
			$job->save();
			return;
		}

		$result = [
			'local_deleted' => strpos($output, 'LOCAL_DELETE_OK') !== false,
			'cloud_deleted' => strpos($output, 'CLOUD_DELETE_OK') !== false,
		];

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Process recovery_key_report result: fold the node's answer into its stored
	 * status, where RecoveryKeyFleet and every backup builder read it.
	 *
	 * It writes into mgn_last_status_data rather than a column of its own so both
	 * transports leave the same shape: the SSH check_status merges the identical
	 * keys from the identical parser. A reader cannot tell — and should not have
	 * to — which route measured it.
	 */
	private static function process_recovery_key_report($job) {
		$output = $job->get('mjb_output') ?: '';

		// Script primitives return their text inside the agent's JSON envelope,
		// so the RECOVERY_KEY= line is behind escaped newlines that no /m anchor
		// will match. Unwrap before parsing.
		$data = self::extract_api_envelope_data($output);
		if (is_array($data) && isset($data['output'])) {
			$output = (string)$data['output'];
		}

		$recovery = self::parse_recovery_key_token($output);
		if ($recovery === null) {
			// A node too old to carry the tool, or one that answered something
			// unreadable. Record that we asked and got nothing, and change no
			// stored fact: a silent node is not a node without a key.
			$job->set('mjb_result', json_encode(['measured' => false]));
			$job->save();
			return;
		}

		$node_id = $job->get('mjb_mgn_node_id');
		if ($node_id) {
			try {
				$node = new ManagedNode($node_id, TRUE);

				// The third writer of the status blob, and it measures exactly two
				// keys. Through the same fold as the other two: it stamps only what
				// it measured, everything else keeps the stamp it already had, and
				// the recovery keys stop being listed as inherited because this job
				// is the measurement they were waiting for.
				$transport = $job->isPrimitiveJob() ? 'primitive' : 'ssh';
				$status = self::fold_status_data(
					$node->get('mgn_last_status_data'), $recovery, $transport);

				$node->set('mgn_last_status_data', json_encode($status));
				if (isset($recovery['backup_recovery_fpr'])) {
					$node->set('mgn_backup_recovery_fpr', $recovery['backup_recovery_fpr']);
				}
				$node->save();
			} catch (Exception $e) {
				// The node record is gone or unreadable; the job result below
				// still records what the node said.
			}
		}

		$job->set('mjb_result', json_encode(array_merge(['measured' => true], $recovery)));
		$job->save();
	}

	/**
	 * Process upload_backup result.
	 *
	 * The primitive invokes a shipped script, so what comes back is text rather
	 * than fields: the script prints contract lines the plane parses. Read those
	 * rather than the human sentence above them — the sentence is for a person
	 * reading the job, the lines are the interface.
	 */
	private static function process_upload_backup($job) {
		$output = $job->get('mjb_output') ?: '';

		// A script primitive's text arrives INSIDE the agent's JSON envelope, so
		// the contract lines are separated by escaped \n rather than real
		// newlines and a /m anchor matches none of them. Unwrap first, then
		// parse. Caught only by running it: the upload succeeded, both objects
		// landed, and the recorded result said uploaded=false.
		$data = self::extract_api_envelope_data($output);
		if (is_array($data) && isset($data['output'])) {
			$output = (string)$data['output'];
		}

		$grab = function ($key) use ($output) {
			return preg_match('/^' . $key . '=(.+)$/m', $output, $m) ? trim($m[1]) : null;
		};

		$retries = [];
		if (preg_match_all('/^RETRY: (.+)$/m', $output, $m)) {
			$retries = array_map('trim', $m[1]);
		}

		// The envelope's own outcome. 'absent' and 'failed' are deliberately
		// different: absent means the node knew the key was not there and
		// uploaded the archive anyway, on an operator's override; failed means
		// the envelope upload was attempted, stopped, and NOTHING landed. The
		// envelope goes first precisely so a partial failure leaves an orphan
		// key rather than an archive nobody can open.
		$envelope = $grab('UPLOAD_ENVELOPE');

		$job->set('mjb_result', json_encode([
			'uploaded'          => $grab('UPLOAD_RESULT') === 'ok',
			'envelope'          => $envelope,
			'envelope_key'      => $grab('ENVELOPE_KEY'),
			// The one an operator has to be told about: the cloud copy exists
			// and cannot be decrypted from itself.
			'unrecoverable'     => ($envelope === 'absent'),
			'key'       => $grab('UPLOAD_KEY'),
			'bytes'     => ($v = $grab('UPLOAD_BYTES')) !== null ? (int)$v : null,
			'attempts'  => ($a = $grab('UPLOAD_ATTEMPTS')) !== null ? (int)$a : null,
			// Kept because a transfer that succeeded on the fourth attempt is a
			// working upload and a sick link, and only one of those is visible
			// from the green.
			'retries'   => $retries,
			'failure'   => preg_match('/^UPLOAD_FAIL: (.+)$/m', $output, $f) ? trim($f[1]) : null,
		]));
		$job->save();
	}

	/**
	 * Finalize a permanent node deletion once the host teardown is verified.
	 *
	 * Only a completed job whose output carries DECOMMISSION_VERIFIED (and NOT
	 * DECOMMISSION_FAILED_VERIFY) finalizes the record — the site is genuinely gone
	 * from the host. The verdict is composed on the host by the self-verifying
	 * remove_account.sh and travels inside the primitive's result envelope, so the
	 * envelope is unwrapped first (the known class of envelope bug). A failed,
	 * refused or unverified job leaves the node intact and enabled so the operator
	 * can retry; we never leave a half-deleted record pointing at a live site.
	 *
	 * THE JOB'S SUBJECT IS THE HOST'S NODE — the machine that ran the teardown —
	 * and the record to finalize is the VICTIM, carried in the job params
	 * (victim_node_id). The pre-primitive shape had the victim as subject; that is
	 * kept as the fallback so any old job row still finalizes correctly.
	 *
	 * The record is soft-deleted, not hard-deleted: the port reservation, the job
	 * history, and the backup-key escrow rows all survive (the escrow FK is SET NULL and
	 * soft-delete triggers no cascade), so the node's offsite backups stay recoverable.
	 */
	private static function process_decommission_node($job) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

		$output = (string)($job->get('mjb_output') ?: '');
		$data = self::extract_api_envelope_data($output);
		if (is_array($data) && isset($data['output'])) {
			$output = (string)$data['output'];
		}
		$status = (string)$job->get('mjb_status');
		$verified = strpos($output, 'DECOMMISSION_VERIFIED') !== false
			&& strpos($output, 'DECOMMISSION_FAILED_VERIFY') === false;

		$params = $job->get('mjb_parameters');
		if (is_string($params)) { $params = json_decode($params, true); }
		$victim_id = (int)(is_array($params) ? ($params['victim_node_id'] ?? 0) : 0);

		if ($status === 'completed' && $verified) {
			$soft_deleted = false;
			$node_id = $victim_id;
			if (!$node_id) {
				// Legacy shape: pre-primitive jobs carried the victim as the
				// SUBJECT. The fallback applies only when the subject actually
				// looks like a site (container name or web root) — a job whose
				// subject is a HOST node and whose params name no victim is a
				// build defect, and finalizing the host's record for it is the
				// one wrong answer.
				$subject = new ManagedNode($job->get('mjb_mgn_node_id'), TRUE);
				if ($subject->key && (trim((string)$subject->get('mgn_container_name')) !== ''
						|| trim((string)$subject->get('mgn_web_root')) !== '')) {
					$node_id = $subject->key;
				}
			}
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
	 * Read what the plugin-installer runner actually did.
	 *
	 * The runner is deliberately fail-safe: it exits 0 whether it ran every
	 * installer or none of them, because it also runs at container start and a
	 * broken plugin installer must never stop a site from booting. That is the
	 * right call there and a lie here — the exit code is all the job has, so
	 * "run the installers" came back green on nodes where nothing ran at all,
	 * and the builder's own docblock told operators to read the output instead
	 * of trusting the colour. This makes the colour worth trusting.
	 *
	 * Three things it says, in its own vocabulary:
	 *   "<name>: ok"                    an installer ran and succeeded
	 *   "WARNING - ... failed"          an installer ran and failed
	 *   "... - skipping" / "- refused"  an installer that should have run did not
	 *
	 * A missing declared extension is reported separately: it degrades a plugin
	 * without stopping its installer, so it is worth surfacing and not worth
	 * failing the job over. "no active plugins - nothing to run" is a complete,
	 * successful answer and stays green.
	 */
	private static function process_run_plugin_installers($job) {
		$output = (string)($job->get('mjb_output') ?: '');

		// Script primitives return their text inside the agent's JSON envelope,
		// where the lines are separated by escaped \n that no /m anchor matches.
		$data = self::extract_api_envelope_data($output);
		if (is_array($data) && isset($data['output'])) {
			$output = (string)$data['output'];
		}

		$failures = [];
		$warnings = [];
		$ran      = [];

		if (preg_match_all('/^(?:core|plugin) installers: WARNING - (.+)$/m', $output, $m)) {
			foreach ($m[1] as $line) {
				$line = trim($line);
				// The extension installer's own warning; every other WARNING the
				// runner emits is an installer that failed.
				if (strpos($line, 'could not install ') === 0) {
					$warnings[] = $line;
				} else {
					$failures[] = $line;
				}
			}
		}
		if (preg_match_all('/^(?:core|plugin) installers: (.+ - (?:skipping|refused))$/m', $output, $m)) {
			foreach ($m[1] as $line) {
				$failures[] = trim($line);
			}
		}
		if (preg_match_all('/^(?:core|plugin) installers: (.+): ok$/m', $output, $m)) {
			foreach ($m[1] as $name) {
				$ran[] = trim($name);
			}
		}

		$nothing_to_run = (strpos($output, 'no active plugins - nothing to run') !== false);

		// A run that printed nothing is not a run that went well. The runner
		// narrates every path it takes, including the ones where it does nothing,
		// so silence means the output never reached us — and a green job whose
		// output we do not have is the exact thing this handler exists to stop.
		$silent = (trim($output) === '');
		if ($silent) {
			$failures[] = 'the runner produced no output, so nothing about this run can be confirmed';
		}

		$result = [
			'installers_run' => $ran,
			'failures'       => $failures,
			'warnings'       => $warnings,
			'nothing_to_run' => $nothing_to_run,
		];

		if ($failures && $job->get('mjb_status') === 'completed') {
			$job->set('mjb_status', 'failed');
			$job->set('mjb_error_message',
				count($failures) . ' installer step'
				. (count($failures) === 1 ? '' : 's')
				. ' did not complete: ' . implode('; ', array_slice($failures, 0, 3)));
		}

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * restart_agent: intentionally thin.
	 *
	 * It is here so processable_types() lists restart_agent deliberately rather
	 * than by omission — without a handler the dashboard sweep skips the type
	 * entirely and a terminal restart job keeps mjb_result NULL forever, which is
	 * how the sweep re-processes a job on every render.
	 *
	 * There is nothing to fold beyond that. The node's answer to "restart" is the
	 * job's own terminal status: it restarts only when it can prove something will
	 * start it again, and when it will not, it REFUSES — which the channel records
	 * as a refusal on the job, not as text in the output for anyone to parse. So
	 * process()'s backstop recording the status is the whole result, and inventing
	 * a richer one here would mean asserting something the output does not say.
	 */
	private static function process_restart_agent($job) {
		// Deliberately empty: see the docblock. The backstop in process() records
		// the terminal status, which is the entire fact this job produces.
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
