<?php
/**
 * JobResultProcessor - Parses completed job output into structured data.
 *
 * Called when a job transitions to 'completed'. Extracts meaningful data
 * from raw command output and updates related records.
 *
 * @version 1.37 - backup_run: the BACKUP_KEEP_DAYS line stamps mgn_backup_keep_days, the site's own
 *                retention window the fleet pass prunes by
 * @version 1.36 - process_if_due(): the one rule for folding a single finished job (terminal, handled type,
 *                unprocessed, live node), used by the agent channel as a result arrives and by the job page;
 *                TERMINAL_STATUSES names completed and failed once
 * @version 1.35 - process_reset_failed_unit: the unit's state before and after, and a host_report
 *                queued behind a reset the node accepted, so the Host card re-measures rather than
 *                showing the cleared unit until the next report
 * @version 1.34 - parse_backup_run_verdict reads BACKUP_LEVEL / BACKUP_BYTES and process_backup_run
 *                stores them in mjb_result (level, bytes)
 * @version 1.33 - restore_objects: process_restore_objects records the node's answer (a survey's names,
 *                 a page's counts) and issues the next job of the loop through FleetObjectRestore;
 *                 process_restore_chain starts that loop in missing mode as the chain restore's last
 *                 step (specs/implemented/backup_offloaded_files.md § Restore)
 * @version 1.32 - the hosted welcome email carries the A-record instruction when the buyer brought their
 *                 own domain (no registration row for the order), and says there is nothing to add only
 *                 when this plane registered the name (specs/managed_hosting_phase1_purchase.md §14)
 * @version 1.32 - process_unit_journal and process_disk_usage: the two observe words of
 *                 specs/disk_headroom_and_unit_diagnosis.md land as bounded results the job page
 *                 renders; sanitise_host_report carries avail_bytes, inodes_used_pct and the three
 *                 kernel-event counts
 * @version 1.31 - process_site_log and process_log_table_tail record a log word's envelope as the job's
 *                 result, bounded on intake, so the job page renders the excerpt (specs/agent_log_access.md)
 * @version 1.30 - a status check on a node that hosts no site (ManagedNode::hosts_site) queues no
 *                 recovery_key_report: the host has no key to report, the bundle does not carry the
 *                 reporting script, and the refusal read as a tampered file (docker-prod, 2026-09-15)
 * @version 1.29 - process_host_converge reads a host_converge job's transcript the way
 *                 process_run_plugin_installers reads the full run: host_housekeeping.sh: ok is green,
 *                 a WARNING, a refusal, a lock it never got or silence is red with the reason; a
 *                 completed run queues one host_report so the Host card shows the machine after it
 * @version 1.29 - process_verify_backup carries the offloaded-files counters of the contract (objects,
 *                 object_bytes, objects_sampled) into the job's result, beside the rest
 * @version 1.28 - process_host_report stores a host_report job's object in mgn_last_host_report with
 *                 mgn_last_host_report_time, after sanitise_host_report caps it on intake: every key
 *                 present, every list and string bounded, anything unreadable the string unknown.
 *                 Its own column, not the status fold: check_status is the site, this is the machine
 * @version 1.27 - the status fold adopts a verify the node reports in its own backup summary
 *                 (adopt_reported_verify) when it is newer than the stamp this plane holds
 * @version 1.26 - process_verify_backup reads a verify_backup job's VERIFY_* lines and stamps the
 *                 node's mgn_backup_verify_* columns (a skip records its reason and leaves the time
 *                 alone); parse_verify_backup_result is the pure half, for the fold test
 * @version 1.25 - a primitive status check on a node whose recovery-key state was never measured
 *                 queues the recovery_key_report job, not only one whose state was carried forward
 * @version 1.24 - parse_check_status_ssh_output reads the Swap: line of free -m it always
 *                 received (swap_total_mb, swap_used_mb), so a node's swap pressure is a
 *                 recorded fact and not a guess (specs/vault_exposure_quick_fixes.md Q5)
 * @version 1.23 - process_apply_update keeps the node's own refusal or failure reason instead of
 *                 replacing it with the version verdict; the probe only confirms the version
 *                 did not move.
 * @version 1.23 - backup_run: a BACKUP_WARNING line (a full a tenth the size of the last one) stamps
 *                 the node's outcome 'warning' and carries the text, so the fleet card says it
 * @version 1.22 - process_retire_install_password records whether the machine retired its install
 *                 password; the provision pipeline reads the job's status and erases the password
 * @version 1.21 - process_backup_run stamps a run with no BACKUP_TIME at the job's own completion
 *                 time, never at the moment the sweep happened to read it, and records the node's
 *                 refusal reason as the run's message so the health card can repeat it
 * @version 1.20 - SSH is one bootstrap (specs/ssh_single_bootstrap.md): process_provision_ssl and
 *                 process_discover_nodes are gone with their builders; process_provision_certificate
 *                 stamps the node the job was FOR (for_node_id — a container's certificate is issued
 *                 on its host); process_install_node leaves a bare host's SSL state alone;
 *                 process_clone_export_arm and process_fleet_enroll blank the secret the job carried
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
 * @version 1.11 - the relay job handlers are gone (specs/relay_without_a_shell.md WP4): a relay
 *                 registers itself through its birth report, never through a job
 * @version 1.11 - the hosted tier's two settings writers: process_hosted_mail_settings blanks the
 *                 SMTP password once the node has answered, process_hosted_plan_notice puts the
 *                 banner job in the sweep so it is not re-filed every tick
 * @version 1.10 - processable_types() drives the dashboard sweep (P-17: relay/ssl/backup results no longer skipped)
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));

class JobResultProcessor {

	/**
	 * The terminal statuses a result is folded for. A failed job carries a
	 * result too — a verify or a backup that failed is exactly what the node
	 * card must say — and a node's refusal is recorded as 'failed'.
	 */
	const TERMINAL_STATUSES = ['completed', 'failed'];

	/**
	 * Every job type this processor can reconcile — i.e. each type with a
	 * process_<type> handler. The dashboard sweep derives its list from this so
	 * an unwatched terminal job of ANY handled type (SSL, backups, …) is
	 * reconciled, not just a hardcoded few (P-17).
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

	/**
	 * Fold a job's result if it is due: terminal, of a type this processor
	 * handles, not yet processed (no mjb_result), and on a node that has not
	 * been removed. The one rule every caller that meets a single finished
	 * job uses — the agent channel as a result arrives, and the job page — and
	 * the same rule the dashboard sweep applies as a query over all of them.
	 *
	 * @return bool whether it was processed
	 */
	public static function process_if_due($job) {
		if (!in_array((string)$job->get('mjb_status'), self::TERMINAL_STATUSES, true)) { return false; }
		if (!in_array((string)$job->get('mjb_job_type'), self::processable_types(), true)) { return false; }
		if ($job->get('mjb_result')) { return false; }
		$node_id = $job->get('mjb_mgn_managed_node_id');
		if ($node_id) {
			try {
				$node = new ManagedNode($node_id, TRUE);
			} catch (Exception $e) {
				return false;
			}
			if ($node->get('mgn_delete_time')) { return false; }
		}
		self::process($job);
		return true;
	}

	/**
	 * Process a finished job. Dispatches to the type-specific handler if one exists.
	 */
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
			if (in_array($job->get('mjb_status'), self::TERMINAL_STATUSES, true)
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
		$node_id = $job->get('mjb_mgn_managed_node_id');
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
				// A verify the node ran itself (its own scheduled task, or its
				// own Backups page) is as much a proof as one dispatched from
				// here, and the node's history row is the authority either way.
				// Taken only when newer than what this plane already holds, so a
				// report cannot roll a fresher job result back.
				self::adopt_reported_verify($node, $mgr);
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
			//
			// A node that has NEVER had it measured wants the job just as much:
			// nothing is carried because there is nothing to carry from, and the
			// backup gate reads that as "awaiting its recovery key" forever. The
			// plane's own node, paired after the API/SSH path retired, was
			// skipped by every fleet backup pass this way (2026-09-13).
			//
			// Never asked of a node that hosts no site. The Docker host is a
			// machine in the fleet, not a site on one: it has no recovery key,
			// its agent runs scripts from the support bundle, and the bundle
			// does not carry set_recovery_key.php. Asking got a manifest
			// refusal that the trust classifier read as a file that fails its
			// release, and the host showed as tampered with (2026-09-15). The
			// recovery-key page already reports such a node as not applicable;
			// this is the same test.
			if ($node->hosts_site()
					&& self::wants_recovery_key_report($folded)
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
	 * Stamp a verify the node reported in its status summary onto the node's
	 * mgn_backup_verify_* columns, when it is newer than the stamp held.
	 * Pure over the node object, so the fold test can pin it.
	 *
	 * @return bool whether anything was stamped
	 */
	public static function adopt_reported_verify($node, array $summary): bool {
		$time = trim((string)($summary['last_verify_time'] ?? ''));
		$outcome = (string)($summary['last_verify_outcome'] ?? '');
		if ($time === '' || !in_array($outcome, ['pass', 'fail'], true)) {
			return false;
		}
		$reported = strtotime($time . ' UTC');
		$held_raw = trim((string)$node->get('mgn_backup_verify_time'));
		$held = ($held_raw !== '') ? strtotime($held_raw . ' UTC') : false;
		if ($reported === false || ($held !== false && $reported <= $held)) {
			return false;
		}
		$node->set('mgn_backup_verify_time', gmdate('Y-m-d H:i:s', $reported));
		$node->set('mgn_backup_verify_level', (int)($summary['last_verify_level'] ?? 0) ?: null);
		$node->set('mgn_backup_verify_outcome', $outcome);
		$node->set('mgn_backup_verify_message', (string)($summary['last_verify_message'] ?? ''));
		return true;
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
	 * Does a folded status blob leave the node's recovery-key state unmeasured?
	 * True when the state was carried forward from an earlier check rather than
	 * measured by this one, and equally when it has never been measured at all
	 * — both are a node the backup gate cannot yet answer for.
	 */
	public static function wants_recovery_key_report(array $folded): bool {
		$carried = $folded[self::STATUS_CARRIED_KEY] ?? [];
		return in_array('backup_recovery_state', is_array($carried) ? $carried : [], true)
			|| !array_key_exists('backup_recovery_state', $folded);
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
		// A swapless box prints "Swap: 0 0 0" - a real reading of zero, kept.
		if (preg_match('/Swap:\s+(\d+)\s+(\d+)\s+(\d+)/m', $output, $m)) {
			$result['swap_total_mb'] = intval($m[1]);
			$result['swap_used_mb']  = intval($m[2]);
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
	public static function cert_name_covers($name, $domain) {
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
	 * whichever path wrote it. A run that never said when it started (the node
	 * refused it, or the runner predates the line) is stamped at the job's own
	 * completion time — see backup_run_stamp_time().
	 *
	 * A refused run carries its reason in mjb_error_message and nothing in its
	 * output; that reason becomes the run's message, so the one line that says
	 * WHY a backup did not happen is on the result the health card reads.
	 */
	private static function process_backup_run($job) {
		$verdict = self::parse_backup_run_verdict(
			$job->get('mjb_output') ?: '',
			(string)$job->get('mjb_status')
		);
		$status = $verdict['status'];
		// A run the node kept but does not vouch for. It is not a success the
		// card may rest on, and not a failure either: the archive exists.
		if ($status === 'success' && $verdict['warning'] !== '') {
			$status = 'warning';
		}

		$result = ['backup_status' => $status];
		foreach (['level', 'bytes', 'keep_days'] as $k) {
			if (isset($verdict[$k])) { $result[$k] = $verdict[$k]; }
		}
		if ($verdict['warning'] !== '') {
			$result['warning'] = $verdict['warning'];
		}
		if ($verdict['message'] !== '') {
			$result['message'] = $verdict['message'];
		} elseif ($status !== 'success' && trim((string)$job->get('mjb_error_message')) !== '') {
			$result['message'] = trim((string)$job->get('mjb_error_message'));
		}

		$node_id = $job->get('mjb_mgn_managed_node_id');
		if ($node_id && $status !== 'skipped') {
			try {
				$node = new ManagedNode($node_id, TRUE);
				$node->set('mgn_last_backup_time',
					self::backup_run_stamp_time($verdict, (string)$job->get('mjb_completed_time')));
				$node->set('mgn_last_backup_outcome',
					($status === 'success') ? 'success' : (($status === 'warning') ? 'warning' : 'failed'));
				if (isset($verdict['keep_days'])) {
					$node->set('mgn_backup_keep_days', $verdict['keep_days']);
				}
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
	 * Post-process verify_backup: the node has opened and read one of its
	 * backups, or rehearsed a restore of it, and printed VERIFY_* lines saying
	 * how it went. Those lines are the node's own words about its own history
	 * row, which is the authority; this stamps the plane's copy on the node
	 * (mgn_backup_verify_*) and stores the result in plain words on the job.
	 *
	 * Three outcomes, three different stamps. A pass and a fail both stamp the
	 * time, the level, the outcome and the message — a failed verify is
	 * surfaced exactly like a failed backup, and never triggers anything on its
	 * own. A skip (not enough disk, a throwaway database that could not be
	 * created, a machine busy with a backup) proves nothing either way, so it
	 * leaves the time and outcome alone and records only the reason, where the
	 * card can show "could not verify" beside the last real result.
	 */
	private static function process_verify_backup($job) {
		$verdict = self::parse_verify_backup_result(
			$job->get('mjb_output') ?: '',
			(string)$job->get('mjb_status'),
			trim((string)$job->get('mjb_error_message'))
		);
		$result = [
			'verify_status' => $verdict['result'],
			'level'         => (int)($verdict['level'] ?? 0),
			'message'       => $verdict['message'],
		];
		foreach (['run', 'run_time', 'artifacts', 'bytes', 'files', 'objects', 'object_bytes', 'objects_sampled',
		          'tables', 'rows', 'duration', 'reason', 'needs_bytes', 'free_bytes'] as $k) {
			if (isset($verdict[$k])) { $result[$k] = $verdict[$k]; }
		}

		$node_id = $job->get('mjb_mgn_managed_node_id');
		if ($node_id) {
			try {
				$node = new ManagedNode($node_id, TRUE);
				if ($verdict['result'] === 'skipped') {
					$node->set('mgn_backup_verify_message', $verdict['message']);
				} else {
					$node->set('mgn_backup_verify_time',
						self::backup_run_stamp_time([], (string)$job->get('mjb_completed_time')));
					$node->set('mgn_backup_verify_level', (int)($verdict['level'] ?? 0) ?: null);
					$node->set('mgn_backup_verify_outcome', $verdict['result']);
					$node->set('mgn_backup_verify_message', $verdict['message']);
				}
				$node->save();
			} catch (Exception $e) {
				error_log('JobResultProcessor: could not stamp the verify outcome for node '
					. $node_id . ': ' . $e->getMessage());
			}
		}

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * A chain restore's last step: once the archives and the database are
	 * back, the run's offloaded files are brought home in missing mode
	 * (specs/implemented/backup_offloaded_files.md § Restore). The loop is
	 * FleetObjectRestore's; here the restore is recorded and the survey is
	 * started. A node whose agent lacks the word, or a run with no index, is
	 * recorded as such — the restore itself stands either way.
	 *
	 * Only a FRESH result starts the loop. Results are processed lazily, and
	 * a restore that finished before this plane knew about offloaded files
	 * has sat with no result since; the sweep reaching it months later must
	 * not start bringing files home behind an operator who restored again
	 * since. Fresh means within the restore's own claim budget, the longest
	 * a genuine result can lag its job.
	 */
	const RESTORE_CHAIN_OBJECTS_WINDOW_SECONDS = 15720;

	private static function process_restore_chain($job) {
		$result = ['status' => (string)$job->get('mjb_status')];
		$stamp = trim((string)$job->get('mjb_completed_time'));
		$completed = ($stamp !== '') ? strtotime($stamp . ' UTC') : false;
		if ($job->get('mjb_status') === 'completed'
				&& $completed !== false && (time() - $completed) > self::RESTORE_CHAIN_OBJECTS_WINDOW_SECONDS) {
			$result['objects'] = 'Offloaded files were not brought home: this restore finished at '
				. (string)$job->get('mjb_completed_time') . ' UTC, before its result was read. Use Bring them back.';
		} elseif ($job->get('mjb_status') === 'completed') {
			$params = $job->get('mjb_parameters');
			if (is_string($params)) { $params = json_decode($params, true); }
			$params = is_array($params) ? $params : [];
			try {
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetObjectRestore.php'));
				$node = new ManagedNode((int)$job->get('mjb_mgn_managed_node_id'), TRUE);
				$survey = FleetObjectRestore::start($node, [
					'chain_id' => (string)($params['chain_id'] ?? ''),
					'profile'  => (string)($params['profile'] ?? ''),
					'seq'      => $params['seq'] ?? '',
					'mode'     => BackupObjectRestore::MODE_MISSING,
				], $job->get('mjb_created_by'), (int)$job->key);
				$result['objects_job'] = (int)$survey->key;
				$result['objects'] = 'Bringing the offloaded files home the file store cannot serve: job #' . (int)$survey->key . '.';
			} catch (Throwable $e) {
				$result['objects'] = 'Offloaded files were not brought home: ' . $e->getMessage();
			}
		}
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * One job of the offloaded-files loop: a survey's answer (the names the
	 * node would bring home) or a page's counts, recorded as the job's
	 * result; then the next job of the loop, issued by FleetObjectRestore
	 * from this one's record and answer. A job that failed ends the loop with
	 * its reason in its own result.
	 */
	private static function process_restore_objects($job) {
		$verdict = self::parse_restore_objects_result(
			$job->get('mjb_output') ?: '',
			(string)$job->get('mjb_status'),
			trim((string)$job->get('mjb_error_message'))
		);
		$result = $verdict;
		$result['restore_status'] = $verdict['result'];
		// The result is written BEFORE the next job is issued: a page reads
		// the survey's names out of the survey's result.
		$job->set('mjb_result', json_encode($result));
		$job->save();

		try {
			require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetObjectRestore.php'));
			$why = '';
			$next = FleetObjectRestore::continue_after($job, $verdict, $why);
			$result['next_job'] = $next ? (int)$next->key : null;
			$result['next']     = $next ? 'job #' . (int)$next->key : $why;
		} catch (Throwable $e) {
			$result['next_job'] = null;
			$result['next']     = 'no further job could be issued: ' . $e->getMessage();
			error_log('JobResultProcessor: restore_objects loop stopped after job ' . $job->key . ': ' . $e->getMessage());
		}
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Read a restore_objects job's answer out of its output. Pure. The
	 * contract is BackupObjectRestore's, printed by the node and parsed by
	 * the same class here. A job that failed before printing a result is a
	 * failed step with the job's own error as its reason.
	 *
	 * @return array the parsed contract plus 'message' in plain words
	 */
	public static function parse_restore_objects_result(string $output, string $job_status, string $job_error = ''): array {
		require_once(PathHelper::getIncludePath('includes/BackupObjectRestore.php'));

		$envelope = self::extract_api_envelope_data($output);
		if ($envelope !== null && isset($envelope['output']) && is_string($envelope['output'])) {
			$output = $envelope['output'];
		}

		$parsed = BackupObjectRestore::parse_contract($output);
		if (!preg_match('/^RESTORE_OBJECTS_RESULT=/m', $output)) {
			$parsed['result'] = BackupObjectRestore::RESULT_FAIL;
			$parsed['reason'] = ($job_status === 'failed' && $job_error !== '')
				? $job_error
				: 'the node reported no result';
		}
		$parsed['message'] = BackupObjectRestore::describe($parsed);
		return $parsed;
	}

	/**
	 * Read a verify_backup job's verdict out of its output. Pure — no DB, no
	 * job mutation — so the fold test can pin every shape.
	 *
	 * The contract is BackupVerifier's, printed by the node and parsed by the
	 * same class here, so the two sides cannot disagree about a key. The
	 * primitive transport wraps the text in the same JSON envelope backup_run's
	 * does, so it is unwrapped first. A job that failed before printing a
	 * result (the node refused the script, the agent timed out) is a failed
	 * verify with the job's own error as its reason — never an unknown.
	 *
	 * @return array the parsed contract plus 'message' in plain words
	 */
	public static function parse_verify_backup_result(string $output, string $job_status, string $job_error = ''): array {
		require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));

		$envelope = self::extract_api_envelope_data($output);
		if ($envelope !== null && isset($envelope['output']) && is_string($envelope['output'])) {
			$output = $envelope['output'];
		}

		$parsed = BackupVerifier::parse_contract($output);
		if (!preg_match('/^VERIFY_RESULT=/m', $output)) {
			$parsed['result'] = 'fail';
			$parsed['reason'] = ($job_status === 'failed' && $job_error !== '')
				? $job_error
				: 'the node reported no result';
		}
		$parsed['message'] = BackupVerifier::describe($parsed);
		return $parsed;
	}

	/**
	 * When to say a run happened. The node's BACKUP_TIME line when it gave one;
	 * otherwise the job's completion time, which is when the plane learned the
	 * outcome. Never now(): the sweep processes a refused job on whatever page
	 * view comes next, and a stamp taken then reads on the dashboard as a backup
	 * that failed the moment the operator looked, hours after it actually did.
	 * Pure, so the fold test can pin it.
	 */
	public static function backup_run_stamp_time(array $verdict, string $completed_time): string {
		if (!empty($verdict['time'])) {
			return $verdict['time'];
		}
		if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $completed_time, $m)) {
			return $m[1];
		}
		return gmdate('Y-m-d H:i:s');
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

		$warning = '';
		if (preg_match('/^BACKUP_WARNING=(.+)$/m', $output, $m)) {
			$warning = trim($m[1]);
		}

		// The files artifact's level and size, as the run printed them. Absent
		// on a failed run and from a runner that predates the lines.
		$figures = [];
		if (preg_match('/^BACKUP_LEVEL=(\d{1,2})$/m', $output, $m)) {
			$figures['level'] = (int)$m[1];
		}
		if (preg_match('/^BACKUP_BYTES=(\d{1,18})$/m', $output, $m)) {
			$figures['bytes'] = (int)$m[1];
		}
		// The site's own retention window, which this management node prunes
		// its copies by (never below its own minimum). Absent from a runner
		// that predates the line.
		if (preg_match('/^BACKUP_KEEP_DAYS=(\d{1,5})$/m', $output, $m) && (int)$m[1] > 0) {
			$figures['keep_days'] = (int)$m[1];
		}

		return ['status' => $status, 'time' => $time, 'message' => $message, 'warning' => $warning] + $figures;
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
		$node_id = $job->get('mjb_mgn_managed_node_id');
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
			$node_said = (string)$job->get('mjb_agent_outcome');
			$verdict = self::behind_verdict($node_said, (string)$job->get('mjb_error_message'),
				(string)$job->get('mjb_output'), $version, $target);
			$result['reason'] = $verdict['reason'];
			if ($verdict['node_outcome'] !== '') { $result['node_outcome'] = $verdict['node_outcome']; }
			if ($verdict['rewrite_message']) { $job->set('mjb_error_message', $verdict['reason']); }
			$job->set('mjb_status', 'failed');
		}

		self::record_apply_update_result($job, $result);
	}

	/**
	 * Why is the node still behind, in one sentence for the job record?
	 *
	 * What the node itself said outranks the probe. A node that refused the
	 * primitive, or ran it and failed, has already given the reason the upgrade
	 * did not happen; replacing that with 'still on X, not Y' swaps a cause for
	 * a symptom and sends whoever reads the job hunting a broken upgrader
	 * instead of the refusal that stopped it before it started. On those the
	 * probe only confirms the version did not move, and the stored message is
	 * left exactly as the node wrote it.
	 */
	private static function behind_verdict(string $node_outcome, string $node_message,
		string $output, string $version, string $target): array {
		if ($node_outcome === 'refused' || $node_outcome === 'failed') {
			$message = trim($node_message);
			if ($message !== '') {
				return ['reason' => $message, 'node_outcome' => $node_outcome, 'rewrite_message' => false];
			}
			return [
				'reason' => 'The node reported ' . $node_outcome . ' and is still on '
					. $version . ', not ' . $target . '.',
				'node_outcome'    => $node_outcome,
				'rewrite_message' => true,
			];
		}

		return [
			'reason' => self::halted_at_self_update($output)
				? 'Upgrade stopped after refreshing its own deployment tooling. '
					. 'The node is still on ' . $version . ' and needs a second pass to reach ' . $target . '.'
				: 'Upgrade finished but the node is still on ' . $version . ', not ' . $target . '.',
			'node_outcome'    => '',
			'rewrite_message' => true,
		];
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
	 * retire_install_password: the closing half of the bootstrap. The
	 * executor completes this job only after the machine refused the
	 * password, so a completed job means retired. The provision pipeline
	 * (ProvisionCustomerCloud) is what erases the sealed password from the
	 * row; this only records the outcome on the job.
	 */
	private static function process_retire_install_password($job) {
		$output = (string)$job->get('mjb_output');
		$retired = $job->get('mjb_status') === 'completed' && strpos($output, 'INSTALL_PASSWORD_RETIRED') !== false;
		$reason = '';
		if (!$retired) {
			$reason = preg_match('/^RETIRE_FAILED=(.+)$/m', $output, $m) ? trim($m[1]) : (string)$job->get('mjb_error_message');
		}
		$job->set('mjb_result', json_encode(['retired' => $retired, 'reason' => $reason]));
		$job->save();
	}

	/**
	 * Post-process install_node: mark the node online on success or install_failed on failure.
	 * All fresh installs get ssl_state=pending so ProvisionPendingSsl picks them up automatically.
	 * Also sends the welcome email for auto-provisioned orders (mjb_external_order_item_id set).
	 * Runs for both 'completed' and 'failed' terminal states.
	 */
	private static function process_install_node($job) {
		$node_id = $job->get('mjb_mgn_managed_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$status = $job->get('mjb_status');
		$output = $job->get('mjb_output') ?: '';

		if ($status === 'completed' && strpos($output, 'INSTALL_SUCCESS') !== false) {
			$node->set('mgn_install_state', null);
			// A bare instance (a Docker host with no site) has no domain to
			// certify; everything else awaits its certificate from here.
			if ($node->get('mgn_ssl_state') !== 'active' && !$node->get('mgn_skip_joinery_checks')) {
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
	 * Send the post-provisioning welcome email to the customer via getjoinery's
	 * QueuedEmail API. Reads credentials from Server Manager plugin settings.
	 * Silently returns on any failure — email delivery is best-effort.
	 * Public: ProvisionCustomerCloud's failed-provision recovery path also
	 * sends it (for completed retry jobs that carry no order-item linkage).
	 */
	public static function send_provisioning_welcome_email($job, $node) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/GetJoineryApiClient.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_hosts_class.php'));

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
		$host_id = $node->get('mgn_mgh_managed_host_id');
		if ($host_id) {
			try {
				$host    = new ManagedHost($host_id, true);
				$host_ip = (string)$host->get('mgh_host');
			} catch (Exception $e) {}
		}
		if ($host_ip === '') {
			$host_ip = (string)$node->get('mgn_host');
		}

		// A site whose DNS this plane published needs no A-record instruction,
		// and a site whose admin password this plane sealed needs a link to
		// where it can be read — once. Both are true of a hosted provision and
		// neither is true of a bring-your-own one, so the two emails differ.
		$provision = null;
		if (class_exists('CustomerCloudProvision')) {
			$provision = CustomerCloudProvision::latest_for_node($node->key);
		}
		$hosted = $provision !== null && $provision->is_operator_hosted();
		$sites_url = trim((string)$settings->get_setting('server_manager_hosted_manage_url'));

		// A hosted site whose name this plane did NOT register is the buyer's
		// own domain: the one thing left for them to do is the A record, and
		// the email has to say so. Decided by the registration row, which
		// exists exactly when the plane bought the name for this order.
		$needs_dns = false;
		if ($hosted) {
			$needs_dns = !self::plane_registered_domain($provision);
		}

		$client = new GetJoineryApiClient($api_url, $pub_key, $sec_key);
		$client->post('QueuedEmail', [
			'equ_from'      => $from_email,
			'equ_from_name' => $from_name,
			'equ_to'        => $admin_email,
			'equ_to_name'   => $user_name,
			'equ_subject'   => 'Your site is ready: ' . $domain,
			'equ_body'      => $hosted
				? self::build_hosted_welcome_email_body($domain, $user_name, $sites_url,
					$provision->admin_password_state() === 'sealed', $needs_dns ? $host_ip : '')
				: self::build_welcome_email_body($domain, $host_ip, $user_name),
			'equ_status'    => 2, // READY_TO_SEND
		]);
	}

	/** Did this plane register the provision's domain (a registration row on its order)? */
	public static function plane_registered_domain($provision): bool {
		$order_item_id = (int)$provision->get('cvp_external_order_item_id');
		if ($order_item_id <= 0 || !class_exists('MultiRegisteredDomain')) {
			return false;
		}
		$rows = new MultiRegisteredDomain(['external_order_item_id' => $order_item_id, 'deleted' => false]);
		foreach ($rows as $row) {
			return true;
		}
		return false;
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
	 * The hosted welcome: the site's address, and where the first password is.
	 *
	 * THE PASSWORD IS NOT IN THIS EMAIL. Email is a copy that lives in somebody
	 * else's system indefinitely, and this one is going to an address that was
	 * typed at a checkout. The password is shown once, behind a sign-in, on the
	 * buyer's own sites page — which erases it as it shows it.
	 */
	private static function build_hosted_welcome_email_body($domain, $user_name, $sites_url, $has_password, $dns_ip = '') {
		$name      = htmlspecialchars($user_name);
		$dom       = htmlspecialchars($domain);
		$login_url = htmlspecialchars('https://' . $domain . '/admin');
		$sites     = htmlspecialchars($sites_url);
		$ip        = htmlspecialchars($dns_ip);

		$password_block = $has_password && $sites_url !== ''
			? "<h3>Your password</h3>\n<p>For safety it is not in this email. Sign in to your account "
				. "and open <a href=\"{$sites}\">your sites</a> — it is shown there once, and your site "
				. "will ask you to choose your own as soon as you use it.</p>"
			: "<h3>Your password</h3>\n<p>Use the <strong>Forgot password</strong> link at "
				. "<a href=\"{$login_url}\">{$login_url}</a> with this email address to set one.</p>";

		// The buyer's own domain: the one record they add, and everything
		// else already done. A domain this plane registered needs nothing.
		$intro = $dns_ip !== ''
			? "<p>Your site for <strong>{$dom}</strong> is built. The server, its email and its offsite "
				. "backups are all set up and running — one thing is left, and it is yours to do.</p>\n"
				. "<h3>Next step: point your domain at it</h3>\n"
				. "<p>At your domain registrar, add an <strong>A record</strong> for <code>{$dom}</code> pointing to:</p>\n"
				. "<p style=\"font-size:1.5em;text-align:center;font-weight:bold;letter-spacing:.05em;background:#f4f4f4;"
				. "padding:12px;border-radius:4px\">{$ip}</p>\n"
				. "<p>DNS changes typically take a few minutes to a few hours. Once your domain resolves to that "
				. "address, HTTPS is provisioned automatically — nothing more to do on your part.</p>"
			: "<p>Your site is live at <strong><a href=\"https://{$dom}\">https://{$dom}</a></strong>. The server, its\n"
				. "email and its offsite backups are all set up and running — there is no DNS record to add and nothing\n"
				. "to install.</p>";

		return <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333">
<h2 style="color:#1a1a1a">Your site is ready!</h2>
<p>Hi {$name},</p>
{$intro}

<h3>Log in</h3>
<p><a href="{$login_url}">{$login_url}</a> — your username is this email address.</p>

{$password_block}

<p style="color:#666;font-size:.9em">Questions? Reply to this email or contact support@getjoinery.com.</p>
<p>— The Get Joinery Team</p>
</body></html>
HTML;
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
		// A container's certificate is issued by its HOST's agent, so the job
		// is filed against the host and names the site it was for. Stamp that
		// node, never the one the job ran on.
		$params = $job->get('mjb_parameters');
		if (is_string($params)) { $params = json_decode($params, true); }
		$node_id = (is_array($params) && !empty($params['for_node_id']))
			? (int)$params['for_node_id']
			: $job->get('mjb_mgn_managed_node_id');
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
					. $job->get('mjb_mgn_managed_node_id') . ' replaced a token that was already there — '
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
	 * clone_export_arm: the source of a clone was armed (or disarmed).
	 *
	 * Records which, from the script's own line, and BLANKS THE KEY out of the
	 * job row once the job is terminal: the row is redacted on display, but a
	 * bearer token that opens a full-site export has no reason to outlive the
	 * job that delivered it. The provision that minted the key holds it sealed
	 * until it disarms the source.
	 */
	private static function process_clone_export_arm($job) {
		$data   = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		$text   = is_array($data) && isset($data['output']) ? (string)$data['output'] : (string)($job->get('mjb_output') ?: '');
		$result = ['armed' => strpos($text, 'CLONE_EXPORT_ARM=armed') !== false];
		if (strpos($text, 'CLONE_EXPORT_ARM=disarmed') !== false) {
			$result['disarmed'] = true;
		}
		self::blank_secret_params($job, ['export_key']);
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * fleet_enroll: the site's fleet credentials were seeded (or not).
	 *
	 * The secret half of the key pair rode the job row to the node. Once the
	 * node has answered — either way — it is blanked here, so the plaintext
	 * does not outlive the job. ProvisionCustomerCloud reads `seeded`.
	 */
	private static function process_fleet_enroll($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		$text = is_array($data) && isset($data['output']) ? (string)$data['output'] : (string)($job->get('mjb_output') ?: '');
		self::blank_secret_params($job, ['secret_key']);
		$job->set('mjb_result', json_encode([
			'seeded' => ($job->get('mjb_status') === 'completed' && strpos($text, 'FLEET_ENROLL=ok') !== false),
		]));
		$job->save();
	}

	/**
	 * hosted_mail_settings: the site was given its mail credentials, or not.
	 *
	 * The SMTP password rode the job row to the node. Once the node has
	 * answered — either way — it is blanked here, so the plaintext does not
	 * outlive the job that delivered it. The plane keeps no copy: the next
	 * attempt mints a fresh credential rather than re-sending this one, which
	 * is a better property than a password sitting in a job table.
	 */
	private static function process_hosted_mail_settings($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		$text = is_array($data) && isset($data['output'])
			? (string)$data['output'] : (string)($job->get('mjb_output') ?: '');
		self::blank_secret_params($job, ['password']);
		$job->set('mjb_result', json_encode([
			'configured' => ($job->get('mjb_status') === 'completed'
				&& strpos($text, 'HOSTED_MAIL_SETTINGS=ok') !== false),
		]));
		$job->save();
	}

	/**
	 * hosted_plan_notice: intentionally thin, like managed_domain_notice.
	 *
	 * There is nothing to fold — the script writes five settings and says so.
	 * The handler exists so processable_types() lists the type deliberately
	 * rather than by omission: without one the dashboard sweep skips it, a
	 * terminal job keeps mjb_result NULL for ever, and HostedTrialWatch — which
	 * decides whether to re-push by looking for the last COMPLETED notice job —
	 * would re-file the same banner on every tick.
	 */
	private static function process_hosted_plan_notice($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		$text = is_array($data) && isset($data['output'])
			? (string)$data['output'] : (string)($job->get('mjb_output') ?: '');
		$job->set('mjb_result', json_encode([
			'shown' => ($job->get('mjb_status') === 'completed'
				&& strpos($text, 'HOSTED_PLAN_NOTICE=ok') !== false),
		]));
		$job->save();
	}

	/**
	 * Blank the clone key out of a finished bootstrap job: the recorded
	 * clone_key parameter and the --clone-key= value in the session's command.
	 * Called by the provision at completion, once the source is disarmed.
	 */
	public static function blank_install_clone_key($job): void {
		$params = $job->get('mjb_parameters');
		if (is_string($params)) { $params = json_decode($params, true); }
		$key = is_array($params) ? (string)($params['clone_key'] ?? '') : '';
		if ($key === '') {
			return;
		}
		$params['clone_key'] = '';
		$job->set('mjb_parameters', json_encode($params));
		$commands = $job->get('mjb_commands');
		if (is_string($commands)) { $commands = json_decode($commands, true); }
		if (is_array($commands) && isset($commands['steps']) && is_array($commands['steps'])) {
			foreach ($commands['steps'] as &$step) {
				if (isset($step['cmd'])) {
					$step['cmd'] = str_replace($key, '', (string)$step['cmd']);
				}
			}
			unset($step);
			$job->set('mjb_commands', json_encode($commands));
		}
		$job->save();
	}

	/**
	 * Blank named parameters out of both places a primitive job carries them:
	 * the envelope the node read (mjb_commands) and the plane's own record
	 * (mjb_parameters). The value is replaced, not removed, so the row still
	 * says what shape the job had.
	 */
	private static function blank_secret_params($job, array $names) {
		foreach (['mjb_commands', 'mjb_parameters'] as $column) {
			$raw = $job->get($column);
			$decoded = is_string($raw) ? json_decode($raw, true) : $raw;
			if (!is_array($decoded)) continue;
			$changed = false;
			foreach ($names as $name) {
				if (isset($decoded['params'][$name]) && $decoded['params'][$name] !== '') {
					$decoded['params'][$name] = '';
					$changed = true;
				}
				if (isset($decoded[$name]) && $decoded[$name] !== '') {
					$decoded[$name] = '';
					$changed = true;
				}
			}
			if ($changed) {
				$job->set($column, json_encode($decoded));
			}
		}
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

	/** The most bytes of log text a site_log result keeps; the node's own output cap, mirrored. */
	const LOG_EXCERPT_MAX_BYTES = 65536;
	/** The most columns a log_table_tail result keeps; every table the node knows has fewer. */
	const LOG_TABLE_MAX_COLUMNS = 32;
	/** The longest a single cell of a log_table_tail result may be. */
	const LOG_TABLE_MAX_CELL = 4096;

	/**
	 * A site_log job's result is the node's envelope, reduced to the keys the
	 * job page renders and bounded on intake (specs/agent_log_access.md §4).
	 * The node redacted the text before it left; nothing here undoes that, and
	 * the page's own redactor is the second pass. An unreadable envelope
	 * records read=false rather than nothing, so the job never looks
	 * unprocessed and the page says plainly that no excerpt came back.
	 */
	private static function process_site_log($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		if (!is_array($data) || !array_key_exists('text', $data)) {
			$job->set('mjb_result', json_encode(['read' => false]));
			$job->save();
			return;
		}
		$text = is_string($data['text']) ? $data['text'] : '';
		$truncated_here = strlen($text) > self::LOG_EXCERPT_MAX_BYTES;
		if ($truncated_here) {
			$text = substr($text, 0, self::LOG_EXCERPT_MAX_BYTES);
		}
		$file = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($data['file'] ?? '')));
		$job->set('mjb_result', json_encode([
			'read'           => true,
			'file'           => substr($file, 0, 64),
			'previous'       => !empty($data['previous']),
			'present'        => !empty($data['present']),
			'size_bytes'     => max(0, (int)($data['size_bytes'] ?? 0)),
			'modified_time'  => substr(preg_replace('/[^0-9TZ:\- ]/', '', (string)($data['modified_time'] ?? '')), 0, 32),
			'lines_returned' => max(0, min((int)($data['lines_returned'] ?? 0), JobCommandBuilder::LOG_MAX_COUNT)),
			'truncated'      => !empty($data['truncated']) || $truncated_here,
			'text'           => $text,
		]));
		$job->save();
	}

	/**
	 * A log_table_tail job's result: the node's columns and rows, kept only as
	 * far as the plane's own caps (LOG_MAX_COUNT rows, LOG_TABLE_MAX_COLUMNS
	 * columns, LOG_TABLE_MAX_CELL per cell) and with every cell reduced to a
	 * scalar, so the page renders a table it can bound rather than whatever
	 * shape the node chose to send.
	 */
	private static function process_log_table_tail($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
			$job->set('mjb_result', json_encode(['read' => false]));
			$job->save();
			return;
		}
		$columns = [];
		foreach ((is_array($data['columns'] ?? null) ? $data['columns'] : []) as $c) {
			if (!is_scalar($c)) { continue; }
			$columns[] = substr(preg_replace('/[^A-Za-z0-9_]/', '', (string)$c), 0, 64);
			if (count($columns) >= self::LOG_TABLE_MAX_COLUMNS) { break; }
		}
		$rows = [];
		$truncated_here = count($data['rows']) > JobCommandBuilder::LOG_MAX_COUNT;
		foreach (array_slice($data['rows'], 0, JobCommandBuilder::LOG_MAX_COUNT) as $row) {
			if (!is_array($row)) { continue; }
			$kept = [];
			foreach ($columns as $c) {
				$v = $row[$c] ?? null;
				if (is_bool($v) || $v === null) {
					$kept[$c] = $v;
				} elseif (is_int($v) || is_float($v)) {
					$kept[$c] = $v;
				} else {
					$kept[$c] = substr(is_scalar($v) ? (string)$v : json_encode($v), 0, self::LOG_TABLE_MAX_CELL);
				}
			}
			$rows[] = $kept;
		}
		$table = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($data['table'] ?? '')));
		$job->set('mjb_result', json_encode([
			'read'          => true,
			'table'         => substr($table, 0, 64),
			'columns'       => $columns,
			'rows_returned' => count($rows),
			'rows'          => $rows,
			'truncated'     => !empty($data['truncated']) || $truncated_here,
		]));
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

		$node_id = $job->get('mjb_mgn_managed_node_id');
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
	 * A host_report job: the machine as its host_report.sh described it.
	 *
	 * The script prints one JSON object; the agent carries it back as the
	 * script primitive's text inside its envelope. It is untrusted input from
	 * the node, so nothing it says is stored as it arrived: sanitise_host_report
	 * rebuilds the object from the keys the plane knows, capping every list and
	 * every string, and anything missing or malformed becomes the string
	 * unknown for that key. The result lands in its own column, never in the
	 * status fold (agent_tier1_recipes.md Q2).
	 *
	 * An answer that is not an object at all — an older script, a refusal, a
	 * node that printed something else — records that it was asked and changes
	 * no stored fact: a silent node is not a node with an empty host.
	 */
	/**
	 * A unit_journal job's result: why one unit is in the state it is in.
	 *
	 * The node printed a bounded JSON object and the agent redacted it before
	 * it left the machine; this keeps the compiled facts as their own kinds
	 * (a state is one of a known set, an exit status is a number) and the
	 * journal as a capped list of capped strings. A hostile node can lie about
	 * its own unit; it cannot put anything but these keys and these kinds of
	 * value on the plane.
	 */
	private static function process_unit_journal($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		$text = (is_array($data) && isset($data['output'])) ? (string)$data['output'] : '';
		$decoded = ($text !== '') ? json_decode(trim($text), true) : null;
		if (!is_array($decoded) || !isset($decoded['unit'])) {
			$job->set('mjb_result', json_encode(['read' => false]));
			$job->save();
			return;
		}

		$journal = [];
		if (isset($decoded['journal']) && is_array($decoded['journal'])) {
			foreach (array_slice(array_values($decoded['journal']), 0, JobCommandBuilder::LOG_MAX_COUNT) as $line) {
				if (!is_scalar($line)) { continue; }
				$journal[] = substr((string)$line, 0, self::UNIT_JOURNAL_MAX_LINE);
			}
		}

		$job->set('mjb_result', json_encode([
			'read'           => true,
			'unit'           => self::host_report_name($decoded['unit'] ?? ''),
			'load_state'     => self::unit_journal_word($decoded['load_state'] ?? ''),
			'active_state'   => self::unit_journal_word($decoded['active_state'] ?? ''),
			'sub_state'      => self::unit_journal_word($decoded['sub_state'] ?? ''),
			'result'         => self::unit_journal_word($decoded['result'] ?? ''),
			'exit_status'    => self::host_report_count($decoded['exit_status'] ?? null),
			'last_run_unix'  => self::host_report_count($decoded['last_run_unix'] ?? null),
			'lines_returned' => count($journal),
			'journal'        => $journal,
		]));
		$job->save();
	}

	/**
	 * One of the unit's compiled facts — a state, a result — as the script
	 * bounds it, and the string unknown where the node said nothing usable.
	 * Unknown rather than empty, so the card reads the same way the Host card
	 * reads: a fact nobody could establish says so.
	 */
	private static function unit_journal_word($v) {
		$name = self::host_report_name($v);
		return ($name !== '') ? $name : 'unknown';
	}

	/**
	 * A reset_failed_unit job's result: the unit's state before and after, and
	 * whether systemd accepted the reset. Compiled facts only, bounded the way
	 * unit_journal's are.
	 *
	 * A reset the node accepted is followed by a host_report, because the
	 * Host card that offered the Clear button is rendered from the last one:
	 * without a fresh report the cleared unit would stay named until the next
	 * scheduled read. Not queued when one is already queued or ran in the
	 * last minute.
	 */
	private static function process_reset_failed_unit($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		$text = (is_array($data) && isset($data['output'])) ? (string)$data['output'] : '';
		$decoded = ($text !== '') ? json_decode(trim($text), true) : null;
		if (!is_array($decoded) || !isset($decoded['unit'])) {
			$job->set('mjb_result', json_encode(['read' => false]));
			$job->save();
			return;
		}
		$state = function ($v) {
			$v = is_array($v) ? $v : [];
			return [
				'active_state' => self::unit_journal_word($v['active_state'] ?? ''),
				'sub_state'    => self::unit_journal_word($v['sub_state'] ?? ''),
				'result'       => self::unit_journal_word($v['result'] ?? ''),
			];
		};
		$reset = ($decoded['reset'] ?? false) === true;
		$job->set('mjb_result', json_encode([
			'read'   => true,
			'unit'   => self::host_report_name($decoded['unit'] ?? ''),
			'reset'  => $reset,
			'before' => $state($decoded['before'] ?? null),
			'after'  => $state($decoded['after'] ?? null),
		]));
		$job->save();

		$node_id = (int)$job->get('mjb_mgn_managed_node_id');
		if ($reset && $node_id) {
			try {
				$node = new ManagedNode($node_id, TRUE);
				if (JobCommandBuilder::has_primitive($node, 'host_report')
						&& !ManagementJob::activeOrRecentForNode($node_id, 'host_report', 60)) {
					ManagementJob::createFromBuild($node_id, 'host_report',
						JobCommandBuilder::build_host_report($node), null, $job->get('mjb_created_by'));
				}
			} catch (Exception $e) {
				// The reset happened; not being able to re-measure is not a
				// reason to call it anything else.
			}
		}
	}

	/** One journal line, capped on the plane as the node caps it. */
	const UNIT_JOURNAL_MAX_LINE = 2000;
	/** Directories reported from a disk_usage walk; the script caps at the same figure. */
	const DISK_USAGE_MAX_ENTRIES = 20;

	/**
	 * A disk_usage job's result: where the space went.
	 *
	 * Sizes only, and the shape enforces it — every entry is one path and one
	 * byte count, and nothing else the node sent is kept. Paths are reduced to
	 * the same safe set the script already reduced them to, so a directory
	 * named in a way that would break a page cannot.
	 */
	private static function process_disk_usage($job) {
		$data = self::extract_api_envelope_data($job->get('mjb_output') ?: '');
		$text = (is_array($data) && isset($data['output'])) ? (string)$data['output'] : '';
		$decoded = ($text !== '') ? json_decode(trim($text), true) : null;
		if (!is_array($decoded) || !isset($decoded['tree'])) {
			$job->set('mjb_result', json_encode(['read' => false]));
			$job->save();
			return;
		}

		$fs = is_array($decoded['filesystem'] ?? null) ? $decoded['filesystem'] : [];
		$tree = is_array($decoded['tree'] ?? null) ? $decoded['tree'] : [];

		$job->set('mjb_result', json_encode([
			'read'       => true,
			'filesystem' => [
				'path'        => self::disk_usage_path($fs['path'] ?? ''),
				'used_bytes'  => self::host_report_count($fs['used_bytes'] ?? null),
				'total_bytes' => self::host_report_count($fs['total_bytes'] ?? null),
				'avail_bytes' => self::host_report_count($fs['avail_bytes'] ?? null),
			],
			'tree' => [
				'path'        => self::disk_usage_path($tree['path'] ?? ''),
				'total_bytes' => self::host_report_count($tree['total_bytes'] ?? null),
				'partial'     => !empty($tree['partial']),
				'entries'     => self::disk_usage_entries($tree['entries'] ?? null),
			],
			'machine'      => self::disk_usage_entries($decoded['machine'] ?? null),
			'generated_at' => self::host_report_count($decoded['generated_at'] ?? null),
		]));
		$job->save();
	}

	/** A path as the walker bounds it: safe characters, capped, never empty. */
	private static function disk_usage_path($v) {
		if (!is_string($v)) { return 'unknown'; }
		$p = substr(preg_replace('#[^A-Za-z0-9._/@:-]#', '', $v), 0, 200);
		return ($p !== '') ? $p : 'unknown';
	}

	/** A list of {path, bytes}, capped, with anything else in it dropped. */
	private static function disk_usage_entries($in) {
		if (!is_array($in)) { return []; }
		$out = [];
		foreach (array_slice(array_values($in), 0, self::DISK_USAGE_MAX_ENTRIES) as $e) {
			if (!is_array($e)) { continue; }
			$path = self::disk_usage_path($e['path'] ?? '');
			if ($path === 'unknown') { continue; }
			// "absent" is an answer — the directory is not there — and it is
			// the one non-numeric value a size may take.
			$bytes = (isset($e['bytes']) && $e['bytes'] === 'absent')
				? 'absent' : self::host_report_count($e['bytes'] ?? null);
			$out[] = ['path' => $path, 'bytes' => $bytes];
		}
		return $out;
	}

	private static function process_host_report($job) {
		$output = $job->get('mjb_output') ?: '';
		$data = self::extract_api_envelope_data($output);
		$text = (is_array($data) && isset($data['output'])) ? (string)$data['output'] : '';
		$decoded = ($text !== '') ? json_decode(trim($text), true) : null;

		if (!is_array($decoded) || !isset($decoded['generated_at'])) {
			$job->set('mjb_result', json_encode(['measured' => false]));
			$job->save();
			return;
		}

		$report = self::sanitise_host_report($decoded);
		$read_at = gmdate('Y-m-d H:i:s');

		$node_id = $job->get('mjb_mgn_managed_node_id');
		if ($node_id) {
			try {
				$node = new ManagedNode($node_id, TRUE);
				$node->set('mgn_last_host_report', json_encode($report));
				$node->set('mgn_last_host_report_time', $read_at);
				$node->save();
			} catch (Exception $e) {
				// The node record is gone or unreadable; the job result below
				// still records what the node said.
			}
		}

		$job->set('mjb_result', json_encode(array_merge(['measured' => true, 'read_at' => $read_at], $report)));
		$job->save();
	}

	/** The unit states host_report.sh may report; anything else is unknown. */
	const HOST_REPORT_UNIT_STATES = ['active', 'inactive', 'failed', 'absent', 'unknown'];
	/** The units host_report.sh always names, in the order the card shows them. */
	const HOST_REPORT_EXPECTED_UNITS = ['fail2ban', 'apache2', 'php-fpm', 'cron', 'postgresql'];
	/** Caps mirroring the script's own (MAX_LIST, MAX_NAME): the plane's copy of the bound. */
	const HOST_REPORT_MAX_LIST = 20;
	const HOST_REPORT_MAX_NAME = 64;

	/**
	 * Rebuild a host report from what the node sent, keeping only what the
	 * plane knows and bounding all of it. Pure; the fold test drives it.
	 *
	 * The shape it returns is the shape the Host card renders and the only
	 * shape mgn_last_host_report ever holds: every key present, lists capped at
	 * HOST_REPORT_MAX_LIST, names reduced to [A-Za-z0-9._@:-] and capped at
	 * HOST_REPORT_MAX_NAME, numbers non-negative integers, and the string
	 * unknown wherever the node said nothing usable. A hostile node can lie
	 * about its host; it cannot put anything but these keys and these kinds of
	 * value on the plane.
	 */
	public static function sanitise_host_report($in) {
		$in = is_array($in) ? $in : [];

		$units = 'unknown';
		if (isset($in['failed_units']) && is_array($in['failed_units'])) {
			$units = [];
			foreach (array_slice(array_values($in['failed_units']), 0, self::HOST_REPORT_MAX_LIST) as $u) {
				$name = self::host_report_name($u);
				if ($name !== '') { $units[] = $name; }
			}
		}

		$expected = [];
		foreach (self::HOST_REPORT_EXPECTED_UNITS as $unit) {
			$state = (isset($in['expected_units']) && is_array($in['expected_units']) && isset($in['expected_units'][$unit]))
				? $in['expected_units'][$unit] : 'unknown';
			$expected[$unit] = (is_string($state) && in_array($state, self::HOST_REPORT_UNIT_STATES, true)) ? $state : 'unknown';
		}

		$jails = 'unknown';
		if (isset($in['fail2ban_jails']) && is_array($in['fail2ban_jails'])) {
			$jails = [];
			foreach (array_slice(array_values($in['fail2ban_jails']), 0, self::HOST_REPORT_MAX_LIST) as $j) {
				if (!is_array($j)) { continue; }
				$name = self::host_report_name($j['name'] ?? '');
				if ($name === '') { continue; }
				$jails[] = ['name' => $name, 'banned' => self::host_report_count($j['banned'] ?? null)];
			}
		}

		$sshd = ['password_authentication' => 'unknown', 'permit_root_login' => 'unknown'];
		if (isset($in['sshd']) && is_array($in['sshd'])) {
			foreach (array_keys($sshd) as $k) {
				$v = isset($in['sshd'][$k]) && is_string($in['sshd'][$k]) ? preg_replace('/[^a-z-]/', '', strtolower($in['sshd'][$k])) : '';
				$sshd[$k] = ($v !== '') ? substr($v, 0, 32) : 'unknown';
			}
		}

		$disk = self::host_report_gauge($in['disk'] ?? null);
		$path = (isset($in['disk']['path']) && is_string($in['disk']['path']))
			? substr(preg_replace('#[^A-Za-z0-9._/-]#', '', $in['disk']['path']), 0, 200) : '';
		// avail is NOT total minus used: a filesystem holds blocks back for
		// root, and the subtraction overstates what a writer can use by exactly
		// the amount that matters when a disk is filling. The node reports the
		// real figure; a node too old to report it says unknown, which reads
		// differently from zero.
		$disk = ['path' => ($path !== '' ? $path : 'unknown')] + $disk + [
			'avail_bytes'     => self::host_report_count($in['disk']['avail_bytes'] ?? null),
			'inodes_used_pct' => self::host_report_percent($in['disk']['inodes_used_pct'] ?? null),
		];

		$reboot = $in['reboot_required'] ?? null;
		$reboot = is_bool($reboot) ? $reboot : 'unknown';

		return [
			'failed_units'                 => $units,
			'expected_units'               => $expected,
			'fail2ban_jails'               => $jails,
			'ssh_auth_failures_24h'        => self::host_report_count($in['ssh_auth_failures_24h'] ?? null),
			'kernel_events_24h'            => self::host_report_kernel_events($in['kernel_events_24h'] ?? null),
			'sshd'                         => $sshd,
			'disk'                         => $disk,
			'memory'                       => self::host_report_gauge($in['memory'] ?? null),
			'swap'                         => self::host_report_gauge($in['swap'] ?? null),
			'reboot_required'              => $reboot,
			'unattended_upgrades_last_run' => self::host_report_count($in['unattended_upgrades_last_run'] ?? null),
			'generated_at'                 => self::host_report_count($in['generated_at'] ?? null),
		];
	}

	/**
	 * A percentage as the script bounds it: 0..100, or unknown. A filesystem
	 * that does not count inodes (btrfs, zfs) has no figure at all, which is
	 * unknown and not zero.
	 */
	private static function host_report_percent($v) {
		if (!is_int($v) && !(is_string($v) && ctype_digit($v))) { return 'unknown'; }
		$n = (int)$v;
		return ($n >= 0 && $n <= 100) ? $n : 'unknown';
	}

	/**
	 * The three kernel-event counts, or the string unknown for the whole
	 * object. COUNTS ONLY — the node counts the matching journal lines and
	 * discards them, so there is no text here to bound, only three numbers.
	 *
	 * A machine with no kernel journal (a container) answers unknown, which is
	 * the honest answer and reads differently from three zeros.
	 */
	private static function host_report_kernel_events($in) {
		if (!is_array($in)) { return 'unknown'; }
		$out = [];
		foreach (['oom', 'enospc', 'io_error'] as $k) {
			$out[$k] = self::host_report_count($in[$k] ?? null);
		}
		return $out;
	}

	/** A unit or jail name as the script bounds it: safe characters, capped. */
	private static function host_report_name($v) {
		if (!is_string($v)) { return ''; }
		return substr(preg_replace('/[^A-Za-z0-9._@:-]/', '', $v), 0, self::HOST_REPORT_MAX_NAME);
	}

	/** A non-negative integer, or unknown. A JSON number arrives as int or float; a digit string counts too. */
	private static function host_report_count($v) {
		if (is_int($v) && $v >= 0) { return $v; }
		if (is_float($v) && $v >= 0 && $v == floor($v) && $v < PHP_INT_MAX) { return (int)$v; }
		if (is_string($v) && preg_match('/^[0-9]{1,18}$/', $v)) { return (int)$v; }
		return 'unknown';
	}

	/** {used_bytes, total_bytes}, each a count or unknown. */
	private static function host_report_gauge($v) {
		$v = is_array($v) ? $v : [];
		return [
			'used_bytes'  => self::host_report_count($v['used_bytes'] ?? null),
			'total_bytes' => self::host_report_count($v['total_bytes'] ?? null),
		];
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
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));

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
				$subject = new ManagedNode($job->get('mjb_mgn_managed_node_id'), TRUE);
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
	 * A host_converge job: host_housekeeping.sh, run through the host runner's
	 * single-installer mode. Read the way process_run_plugin_installers reads
	 * the full run, and for the same reason: the runner exits 0 on every path,
	 * including the ones where nothing ran, so the transcript is the only
	 * verdict there is.
	 *
	 * Green is one line: "core installers: host_housekeeping.sh: ok". On a
	 * container node the installer prints that fail2ban is the host's and
	 * does nothing, and that is a complete, green answer — the machine in
	 * front of the container runs its own housekeeping.
	 *
	 * Red is everything else the runner can say instead, each with its reason
	 * kept: the installer failed (WARNING), was missing (skipping), was refused
	 * as untrusted (installer refused: ... owned by ... mode ...), the runner
	 * could not take or open its lock (another run holds the lock / refusing
	 * to run unlocked), the runner refused the mode itself (--only ... refused,
	 * exit 2, which the channel has already failed), or it said nothing at all.
	 *
	 * A run that completed queues one host_report for the node, so the Host
	 * card shows the machine after the run rather than before it. One: a
	 * report already pending or running for the node is left to answer.
	 */
	private static function process_host_converge($job) {
		$output = (string)($job->get('mjb_output') ?: '');

		// Script primitives return their text inside the agent's JSON envelope,
		// where the lines are separated by escaped \n that no /m anchor matches.
		$data = self::extract_api_envelope_data($output);
		if (is_array($data) && isset($data['output'])) {
			$output = (string)$data['output'];
		}

		$failures = [];
		$ran      = (bool)preg_match('/^core installers: host_housekeeping\.sh: ok$/m', $output);

		if (preg_match_all('/^core installers: WARNING - (.+)$/m', $output, $m)) {
			foreach ($m[1] as $line) {
				$failures[] = trim($line);
			}
		}
		if (preg_match_all('/^core installers: (.+ - skipping)$/m', $output, $m)) {
			foreach ($m[1] as $line) {
				$failures[] = trim($line);
			}
		}
		if (preg_match_all('/^installer refused: (.+)$/m', $output, $m)) {
			foreach ($m[1] as $line) {
				$failures[] = 'installer refused: ' . trim($line);
			}
		}
		if (preg_match_all('/^host installers: (another run holds the lock .+|cannot open .+ - refusing to run unlocked|--only=.+ - refused|--only and --when-changed .+ - refused)$/m', $output, $m)) {
			foreach ($m[1] as $line) {
				$failures[] = trim($line);
			}
		}
		if (preg_match_all('/^plugin installers: (_tree_trust\.sh missing .+)$/m', $output, $m)) {
			foreach ($m[1] as $line) {
				$failures[] = trim($line);
			}
		}

		// The runner narrates every path it takes, including the ones where it
		// does nothing, so silence means the output never reached us — and a
		// green job whose output we do not have is the thing this handler
		// exists to stop.
		if (trim($output) === '') {
			$failures[] = 'the runner produced no output, so nothing about this run can be confirmed';
		} elseif (!$ran && !$failures) {
			$failures[] = 'the transcript never says host_housekeeping.sh: ok, and gives no reason';
		}

		$result = [
			'ran'      => $ran,
			'failures' => $failures,
		];

		if ($failures && $job->get('mjb_status') === 'completed') {
			$job->set('mjb_status', 'failed');
			$job->set('mjb_error_message',
				'host housekeeping did not complete: ' . implode('; ', array_slice($failures, 0, 3)));
		}

		$job->set('mjb_result', json_encode($result));
		$job->save();

		// The machine after the run. Queued rather than read inline because
		// reading it means running a script on the node, which is a job, not a
		// page render; and once, because a report already on its way will
		// describe the same machine.
		$node_id = $job->get('mjb_mgn_managed_node_id');
		if ($job->get('mjb_status') === 'completed' && $node_id) {
			try {
				$node = new ManagedNode($node_id, TRUE);
				if (JobCommandBuilder::has_primitive($node, 'host_report')
						&& !ManagementJob::activeOrRecentForNode($node->key, 'host_report', 0)) {
					$built = JobCommandBuilder::build_host_report($node);
					ManagementJob::createFromBuild($node->key, 'host_report', $built, null,
						$job->get('mjb_created_by'));
				}
			} catch (Exception $e) {
				// Not being able to ask for the report is not a reason to fail
				// the housekeeping run that just completed.
			}
		}
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
	 * Format bytes into human-readable size. Public so the Host card shows a
	 * report's byte figures the way the backup listing shows a file's.
	 */
	public static function format_size($bytes) {
		if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
		if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'M';
		if ($bytes >= 1024) return round($bytes / 1024, 1) . 'K';
		return $bytes . 'B';
	}
}
?>
