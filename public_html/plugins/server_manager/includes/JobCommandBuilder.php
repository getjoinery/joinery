<?php
/**
 * JobCommandBuilder - Generates step arrays for each job type.
 *
 * All job-type intelligence lives here. The Go agent is a generic executor
 * that reads these steps and runs them in order.
 *
 * @version 1.44 - relay provision pre-flight tars bin/ (the prebuilt sealer) and drops the Go
 *                 source, with an ls guard so a missing binary fails on the plane not the relay
 * @version 1.43 - has_primitive asks the NODE what it can do: the vocabulary an agent reports on
 *                 every claim decides routing, and PRIMITIVE_MIN_AGENT_VERSION stays as the
 *                 fallback for agents that predate the report (1.10.0 and earlier)
 * @version 1.42 - has_primitive gates on the agent version that introduced the primitive, so the
 *                 upgrade delivering a new agent is never dispatched as a primitive the fielded
 *                 agent does not ship (nine refusals on the 0.8.352 rollout)
 * @version 1.41 - apply_update crosses the agent channel: build_apply_update_primitive, with the
 *                 SSH builder kept behind it until the Phase 3 cutover
 * @version 1.40 - status_color_for_node greys a node whose health figures are stale or undateable,
 *                 off the fold's provenance — the same rule the node detail overview applies
 * @version 1.39 - fetch_status_via_api folds into the stored status instead of replacing it, so a
 *                 dashboard refresh no longer deletes the facts only the agent can see
 * @version 1.38 - upload_backup, delete_backup and run_plugin_installers join the primitive
 *                 transport. upload/delete send a FILENAME, never a path — the node resolves it
 *                 inside its own backup directory. delete_backup is local-only: the SSH cloud
 *                 branch shipped the delete-capable bucket credential to the node, which the
 *                 write-only node key exists to prevent, so cloud deletes stay plane-side.
 *                 run_plugin_installers sends nothing at all.
 * @version 1.37 - restart_agent joins the primitive transport, and is the first operation with
 *                 NO ssh implementation at all: the SSH equivalent is pkill, which is a command,
 *                 so the operation exists only where it is safe. The node refuses unless it can
 *                 prove a supervisor will start it again.
 * @version 1.36 - A1/A3 retirement: build_run_command and both copy_database builders are gone.
 *                 No builder composes an instruction a node executes as text; a database copy is
 *                 backup-on-source then restore-on-target, through the backup target
 * @version 1.35 - backup_run joins the primitive transport: the same config as declared params,
 *                 composed into the engine's stdin ON THE NODE, so no credential enters argv
 * @version 1.34 - list_backups joins the primitive transport: the node reads its own backup
 *                 directory from a compiled-in path, so no glob crosses the wire
 * @version 1.33 - build_enable_agent: turn a node's agent on and optionally have it ask to join,
 *                 over SSH. Exists only while SSH does — Phase 3 takes both
 * @version 1.32 - a connected agent IS the cutover: has_agent_channel() asks only whether the
 *                 node's key is bound (the per-node routing flag is gone — hard cutover, owner-set)
 * @version 1.31 - no builder supplies encryption key material to a node. Backup jobs seal to the
 *                 recovery key the node holds and has proven, read there; a node without one is
 *                 refused at build time with the reason, rather than backed up to a key from here
 * @version 1.30 - the Cloudflare routing probe documents its serving contract:
 *                 the node's /sm-ssl-probe.txt route is what makes the token fetchable
 * @version 1.29 - the publish-upgrade step cds to the running site's web root
 *                 instead of the path of the machine it was written on
 * @version 1.28 - fetch_status_via_api returns the curl errno alongside reason 'transport',
 *                 so a caller can tell an unreachable node from an unresolvable name
 * @version 1.27 - a node that names no backup target falls back to the control
 *                 plane's sole enabled one, so a registered node is backed up
 *                 without per-node setup; two or more and it still refuses
 * @version 1.26 - paths and the site URL are cast before parsing, so an unset one
 *                 raises nothing on PHP 8.5
 * @version 1.25 - node-bound backup steps carry __SM_NODE_CREDS_<id>__ when the target holds a
 *                 write-only node credential, so a node is handed a key that can add to the shelf
 *                 but never erase it; the main (delete-capable) credential then stays on the
 *                 control plane. With no node credential configured, the main token is emitted
 *                 and nothing changes.
 * @version 1.26 - every restore path reconciles the site to the machine it lands on and proves it
 *                 (identity + served-over-HTTPS gates); the Apache choice is gone, the domain is a
 *                 required parameter, and build_restore_chain() makes the fleet's actual backups —
 *                 incremental chains — restorable from the dashboard for the first time
 * @version 1.24 - build_backup_run(): this control plane's own backup of a node, run by the node's
 *                 own engine with the bucket, a write-only credential and the recovery key supplied
 *                 per run and never stored there. Writing a node's own recovery key is retired —
 *                 that slot's custodian is whoever administers the site. Backup jobs now resolve
 *                 their archive against a before-list rather than `ls -t`, and mint their envelope
 *                 at a per-job scratch path, so a concurrent run cannot be sealed or uploaded by
 *                 mistake; a From-Backup install no longer clones the source's site key
 * @version 1.23 - build_run_command(): one ad-hoc command from the node detail Console tab, bounded by
 *                 a closed timeout set rather than by inspecting the command
 * @version 1.21 - build_upload_backup(): push one already-existing backup from the node to its cloud
 *                 target (the per-file Backups tab action), sharing upload_step() with the automatic
 *                 post-backup upload; the step timeout is sized from S3Signer's retry budget
 * @version 1.20 - backup key escrow runs as a control-plane step (step_escrow_backup_key) instead of
 *                 inside the web request: node SSH keys are operator-owned, so only the agent can read
 *                 them, and encrypting backups seal the key on their way in
 * @version 1.19 - local backup delete is sudo-prefixed on bare-metal nodes (root-owned /backups files;
 *                 the job runs as user1 there, so a plain rm failed Permission denied)
 * @version 1.18 - status dot reflects uptime for skip-Joinery nodes (a relay's dot follows its TCP/HTTP
 *                 probe, not a status check that is expected to fail; also a general no-data uptime fallback)
 * @version 1.17 - decommission verify: join per-resource checks with '; ' (a space made `fi if`,
 *                 a bash syntax error that exited 2 and failed the verify step)
 * @version 1.16 - build_decommission_node: ship + run the tested remove_account.sh on the host, then
 *                 verify the site is gone (container/volumes/vhost/root all absent)
 * @version 1.15 - retention rm rides the heredoc redirect line (a chain after the terminator is
 *                 swallowed into the uploader's stdin); credentials are placeholder-only (no inline
 *                 fallback); Cloudflare SSL requires a routing probe; one container-port allocator
 * @version 1.15 - the primitive transport joins api/ssh in transports_for(): build_<op>_primitive,
 *                 has_agent_channel()/has_primitive() gating on pairing + the per-node cutover flag,
 *                 and check_status as the first crossed operation
 * @version 1.14 - fingerprint step hashes the key VALUE (matches escrow) + quote-robust for the agent
 * @version 1.13 - P-18: allocate + record + pass the container published port to install.sh (mgn_port no longer diverges)
 * @version 1.12 - is_cloudflare_domain made public (ProvisionPendingSsl P-6 dispatch)
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

class JobCommandBuilder {

	// ── Transport capability helpers ──
	//
	// Two orthogonal questions:
	//   1. Does the node HAVE the transport configured? (has_api_creds / has_ssh)
	//   2. Does the operation HAVE an implementation for a transport? (transports_for)
	// can_run() combines both: this node + this operation ⇒ can the builder build a job?
	// has_api() adds a live /health probe on top of has_api_creds (used at job-build time).

	public static function has_api_creds($node) {
		return !empty($node->get('mgn_api_public_key'))
			&& !empty($node->get('mgn_api_secret_key'))
			&& !empty($node->get('mgn_site_url'));
	}

	public static function has_ssh($node) {
		return !empty($node->get('mgn_host'))
			&& !empty($node->get('mgn_ssh_user'))
			&& !empty($node->get('mgn_ssh_key_path'));
	}

	/**
	 * Which transports does this operation have an implementation for?
	 * Looks for build_<op>_primitive, build_<op>_api and build_<op>_ssh methods.
	 *
	 * Order is preference order. The primitive transport comes first because it
	 * is the one where the node decides what it will run
	 * (specs/agent_on_node_architecture.md §3.1); api and ssh remain until each
	 * operation has crossed and each node's cutover flag is on.
	 */
	public static function transports_for($operation) {
		$transports = [];
		if (method_exists(static::class, "build_{$operation}_primitive")) {
			$transports[] = 'primitive';
		}
		if (method_exists(static::class, "build_{$operation}_api")) {
			$transports[] = 'api';
		}
		if (method_exists(static::class, "build_{$operation}_ssh")) {
			$transports[] = 'ssh';
		}
		return $transports;
	}

	/**
	 * Has this node's agent joined this plane? Connected IS the cutover:
	 * approving the join is the operator's routing decision, and every
	 * operation with a primitive travels the channel from that moment.
	 * Operations without one keep using api/ssh — transports_for() decides
	 * per operation, not per node.
	 */
	public static function has_agent_channel($node) {
		return !empty($node->get('mgn_agent_public_key'));
	}

	/**
	 * Routing decision at job-build time: should this (node, operation) pair
	 * run as a primitive job on the node's own agent?
	 */
	/**
	 * A primitive added to the agent after a node's running version does not
	 * exist on that node, whatever the plane can build. The plane learns each
	 * node's agent version on every claim, so the floor is checkable here —
	 * and the first rollout of a new primitive is exactly when it matters:
	 * the 0.8.352 upgrade was dispatched as an apply_update primitive to nine
	 * agents whose compiled-in vocabulary predated it, and all nine refused.
	 * The upgrade that DELIVERS a new agent can never require the new agent.
	 *
	 * Only primitives newer than some fielded agent need a row. An operation
	 * absent here is in every agent the fleet has ever paired.
	 */
	const PRIMITIVE_MIN_AGENT_VERSION = [
		'apply_update' => '1.10.0',
	];

	public static function has_primitive($node, $operation) {
		if (!self::has_agent_channel($node)
				|| !method_exists(static::class, "build_{$operation}_primitive")) {
			return false;
		}

		// THE NODE'S OWN LIST WINS, because it is the only account of a node's
		// vocabulary that is not a guess. An agent reports what its binary
		// actually compiled in on every claim; a version number is an inference
		// about that, and the inference is what failed — the 0.8.352 rollout
		// dispatched apply_update to nine agents whose vocabulary predated it
		// and collected nine refusals.
		//
		// A primitive absent from a reported vocabulary is NOT routed to that
		// node, whatever the version map would have allowed. That direction
		// matters more than the permissive one: dispatching to a node that
		// cannot run it buys a guaranteed refusal in place of a working
		// transport.
		$reported = trim((string)$node->get('mgn_agent_primitives'));
		if ($reported !== '') {
			return in_array($operation, explode(',', $reported), true);
		}

		// No report: an agent at 1.10.0 or earlier, which never sends one. The
		// version floor is the fallback contract for exactly those, and stays.
		$min = self::PRIMITIVE_MIN_AGENT_VERSION[$operation] ?? null;
		if ($min === null) {
			return true;
		}
		// An unknown agent version routes away from the primitive: sending a
		// job to a vocabulary we cannot confirm trades a working SSH dispatch
		// for a guaranteed refusal. Stale-by-one-poll after a self-update is
		// the known cost, and it only means one extra SSH dispatch.
		$version = (string)$node->get('mgn_agent_version');
		return $version !== '' && version_compare($version, $min, '>=');
	}

	/**
	 * Optimistic: do we have at least one viable (transport, credentials) pair for this
	 * node + operation? Uses has_api_creds (config check, no probe) so the UI isn't
	 * gray-out-flickering on a transient endpoint hiccup.
	 */
	public static function can_run($node, $operation) {
		$op_transports = self::transports_for($operation);
		if (in_array('primitive', $op_transports) && self::has_agent_channel($node)) return true;
		if (in_array('api', $op_transports) && self::has_api_creds($node)) return true;
		if (in_array('ssh', $op_transports) && self::has_ssh($node)) return true;
		return false;
	}

	/**
	 * Return a human-readable reason explaining why can_run() is false.
	 * Used for tooltips on disabled action buttons.
	 */
	public static function why_cannot_run($node, $operation) {
		$op_transports = self::transports_for($operation);
		if (empty($op_transports)) {
			return "Operation '{$operation}' has no implementation on the control plane.";
		}
		$parts = [];
		if (in_array('primitive', $op_transports) && !self::has_agent_channel($node)) {
			$parts[] = empty($node->get('mgn_agent_public_key'))
				? 'no agent has paired with this plane'
				: 'the agent channel is not switched on for this node';
		}
		if (in_array('api', $op_transports) && !self::has_api_creds($node)) {
			$parts[] = 'no API credentials are configured';
		}
		if (in_array('ssh', $op_transports) && !self::has_ssh($node)) {
			$parts[] = 'SSH is not configured';
		}
		if (!in_array('api', $op_transports)) {
			$parts[] = 'no API implementation exists';
		}
		if (!in_array('ssh', $op_transports)) {
			$parts[] = 'no SSH implementation exists';
		}
		return "Cannot run '{$operation}' on this node: " . implode('; ', $parts) . '.';
	}

	/**
	 * Routing decision at job-build time: should the dispatcher emit API steps
	 * for this (node, operation) pair? True iff:
	 *   1. The node has API credentials configured.
	 *   2. build_<op>_api exists on this class.
	 *   3. A fresh GET /health probe against the node succeeds (1s timeout).
	 */
	public static function has_api($node, $operation) {
		if (!self::has_api_creds($node)) return false;
		if (!method_exists(static::class, "build_{$operation}_api")) return false;

		$probe = self::probe_api_health($node, 1);
		return !empty($probe['ok']);
	}

	/**
	 * Synchronously probe /api/v1/management/health on a node.
	 * Returns ['ok' => bool, 'elapsed_ms' => int, 'message' => string|null, 'reason' => string|null].
	 * Never throws — all failures come back as ok=false with a reason string.
	 */
	public static function probe_api_health($node, $timeout_seconds = 2) {
		$start = microtime(true);
		$site_url = rtrim((string)$node->get('mgn_site_url'), '/');
		$public_key = (string)$node->get('mgn_api_public_key');
		$secret_key = (string)$node->get('mgn_api_secret_key');

		if ($site_url === '' || $public_key === '' || $secret_key === '') {
			return [
				'ok' => false,
				'elapsed_ms' => 0,
				'message' => 'API credentials or site URL not configured',
				'reason' => 'config',
			];
		}

		$url = $site_url . '/api/v1/management/health';
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $timeout_seconds,
			CURLOPT_TIMEOUT        => $timeout_seconds,
			CURLOPT_HTTPHEADER     => [
				'public-key: ' . $public_key,
				'secret-key: ' . $secret_key,
				'Accept: application/json',
			],
			CURLOPT_SSL_VERIFYPEER => $node->get('mgn_tls_insecure') ? false : true,
			CURLOPT_SSL_VERIFYHOST => $node->get('mgn_tls_insecure') ? 0 : 2,
			CURLOPT_FOLLOWLOCATION => false,
		]);
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		$errmsg = curl_error($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		$elapsed_ms = intval(round((microtime(true) - $start) * 1000));

		if ($errno) {
			return [
				'ok' => false,
				'elapsed_ms' => $elapsed_ms,
				'message' => $errmsg ?: 'transport failure',
				'reason' => 'transport',
			];
		}
		if ($status === 401 || $status === 403) {
			return [
				'ok' => false,
				'elapsed_ms' => $elapsed_ms,
				'message' => 'authentication failed',
				'reason' => 'auth',
			];
		}
		if ($status !== 200) {
			return [
				'ok' => false,
				'elapsed_ms' => $elapsed_ms,
				'message' => 'HTTP ' . intval($status),
				'reason' => 'status',
			];
		}

		$decoded = json_decode((string)$body, true);
		if (!is_array($decoded) || empty($decoded['data']['ok'])) {
			return [
				'ok' => false,
				'elapsed_ms' => $elapsed_ms,
				'message' => 'unexpected response body',
				'reason' => 'body',
			];
		}

		return [
			'ok' => true,
			'elapsed_ms' => $elapsed_ms,
			'message' => null,
			'reason' => null,
		];
	}

	/**
	 * Synchronously call GET /api/v1/management/stats, fold the result into the
	 * node record (mgn_last_status_check, mgn_last_status_data via
	 * JobResultProcessor::fold_status_data, and mgn_joinery_version if returned),
	 * and return the parsed data.
	 *
	 * What comes back is what this call measured. What the node record holds is
	 * that folded over what other transports measured earlier — the API cannot
	 * see everything an agent can, so it stamps its own keys and leaves the rest
	 * carrying the provenance they already had.
	 *
	 * No job record is created — this is a lightweight refresh used by the
	 * dashboard on page load. For user-initiated status checks with audit
	 * history, go through the job pipeline (build_check_status).
	 *
	 * Returns ['ok' => bool, 'elapsed_ms' => int, 'data' => array|null,
	 *          'message' => string|null, 'reason' => string|null].
	 */
	public static function fetch_status_via_api($node, $timeout_seconds = 5) {
		$start = microtime(true);
		$site_url = rtrim((string)$node->get('mgn_site_url'), '/');
		$public_key = (string)$node->get('mgn_api_public_key');
		$secret_key = (string)$node->get('mgn_api_secret_key');

		if ($site_url === '' || $public_key === '' || $secret_key === '') {
			return [
				'ok' => false, 'elapsed_ms' => 0, 'data' => null,
				'message' => 'API credentials or site URL not configured',
				'reason' => 'config',
			];
		}

		$ch = curl_init($site_url . '/api/v1/management/stats');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $timeout_seconds,
			CURLOPT_TIMEOUT        => $timeout_seconds,
			CURLOPT_HTTPHEADER     => [
				'public-key: ' . $public_key,
				'secret-key: ' . $secret_key,
				'Accept: application/json',
			],
			CURLOPT_SSL_VERIFYPEER => $node->get('mgn_tls_insecure') ? false : true,
			CURLOPT_SSL_VERIFYHOST => $node->get('mgn_tls_insecure') ? 0 : 2,
			CURLOPT_FOLLOWLOCATION => false,
		]);
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		$errmsg = curl_error($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		$elapsed_ms = intval(round((microtime(true) - $start) * 1000));

		if ($errno) {
			// Carry the curl error number out with the message. 'transport' covers
			// everything from a refused connection to an unresolvable name, and
			// callers that must tell those apart cannot do it from prose alone.
			return ['ok' => false, 'elapsed_ms' => $elapsed_ms, 'data' => null,
				'message' => $errmsg ?: 'transport failure', 'reason' => 'transport',
				'errno' => $errno];
		}
		if ($status === 401 || $status === 403) {
			return ['ok' => false, 'elapsed_ms' => $elapsed_ms, 'data' => null,
				'message' => 'authentication failed', 'reason' => 'auth'];
		}
		if ($status !== 200) {
			return ['ok' => false, 'elapsed_ms' => $elapsed_ms, 'data' => null,
				'message' => 'HTTP ' . intval($status), 'reason' => 'status'];
		}

		$decoded = json_decode((string)$body, true);
		if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
			return ['ok' => false, 'elapsed_ms' => $elapsed_ms, 'data' => null,
				'message' => 'unexpected response body', 'reason' => 'body'];
		}
		$data = $decoded['data'];

		$node->set('mgn_last_status_check', gmdate('Y-m-d H:i:s'));
		if (!empty($data['joinery_version'])) {
			$node->set('mgn_joinery_version', $data['joinery_version']);
		}

		// Successful HTTPS API call proves SSL is working — mark active
		$api_domain = parse_url($site_url, PHP_URL_HOST) ?: '';
		if (!$node->get('mgn_tls_insecure') && strpos($site_url, 'https://') === 0
				&& $api_domain && !filter_var($api_domain, FILTER_VALIDATE_IP)
				&& $api_domain !== 'localhost') {
			$node->set('mgn_ssl_state', 'active');
			$data['ssl_state']            = 'active';
			$data['ssl_domain']           = $api_domain;
			$data['ssl_detection_method'] = 'https_probe';
			$data['ssl_https_probe']      = true;
		}

		// Through the same fold every other status writer uses. This path used to
		// replace the blob wholesale, so whichever facts the API cannot see — the
		// certificate lineages the agent reads out of /etc/letsencrypt, among
		// others — were deleted from the node record every time the dashboard
		// refreshed, then restored by the next agent check. Which of the two ran
		// last decided what the node appeared to know.
		$folded = JobResultProcessor::fold_status_data(
			$node->get('mgn_last_status_data'), $data, 'api');

		$node->set('mgn_last_status_data', json_encode($folded));
		$node->save();

		// The caller is told what THIS call measured, not what the node now
		// holds — it is reporting on a request it just made.
		return ['ok' => true, 'elapsed_ms' => $elapsed_ms, 'data' => $data,
			'message' => null, 'reason' => null];
	}

	/**
	 * Quick HTTPS probe: HEAD request to https://$domain/ with full cert verification.
	 * Returns ['ok' => true] when a valid SSL connection is made.
	 * Used as a fallback SSL detection method for Cloudflare and other edge SSL.
	 */
	public static function probe_https($domain, $timeout = 4) {
		if (!$domain || filter_var($domain, FILTER_VALIDATE_IP) || $domain === 'localhost') {
			return ['ok' => false];
		}
		$ch = curl_init('https://' . $domain . '/');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY         => true,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
		]);
		curl_exec($ch);
		$errno  = curl_errno($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		return ['ok' => ($errno === 0 && $status > 0)];
	}

	/**
	 * Derive the dashboard badge color for a node. Single source of truth used by
	 * both the dashboard page render and the AJAX refresh endpoint.
	 *
	 * $status_data - the parsed mgn_last_status_data blob. It must be the FOLDED
	 *                blob, not a raw transport response: the staleness test reads
	 *                the per-key provenance the fold writes, and a response that
	 *                carries none is undateable and greys out — including one
	 *                measured a moment ago. A caller holding a fresh reading wants
	 *                the node record it was just folded into.
	 * $last_job_failed - true if the most recent check_status job failed (page-render path)
	 */
	public static function status_color_for_node($node, $status_data = null, $last_job_failed = false) {
		$install_state = $node->get('mgn_install_state');
		if ($install_state === 'installing')    return 'info';
		if ($install_state === 'install_failed') return 'danger';

		// Skip-Joinery infrastructure (a mail relay, a DNS box) is health-checked by
		// its uptime probe, not by the SSH status check — a failed status check against
		// it is expected, not a health signal. So the uptime result is authoritative for
		// these nodes and takes precedence over a failed status job.
		if ($node->get('mgn_skip_joinery_checks') && $node->get('mgn_uptime_enabled')) {
			$uptime = $node->get('mgn_uptime_last_status');
			if ($uptime === 'up')   return 'success';
			if ($uptime === 'down') return 'danger';
			return 'secondary'; // not yet probed
		}

		if ($last_job_failed) return 'danger';

		$last_check = $node->get('mgn_last_status_check');
		if (!$last_check || !is_array($status_data) || empty($status_data)) {
			// No status-check data yet, but the node may still be uptime-monitored
			// (a Joinery node awaiting its first status check). Prefer the uptime
			// result over a grey "unknown" dot.
			if ($node->get('mgn_uptime_enabled')) {
				$uptime = $node->get('mgn_uptime_last_status');
				if ($uptime === 'up')   return 'success';
				if ($uptime === 'down') return 'danger';
			}
			return 'secondary';
		}

		// Grey rather than green when the three figures below are too old to draw a
		// conclusion from, or cannot be dated at all. Same rule and same threshold
		// as the node detail overview, read off the fold's per-key provenance —
		// mgn_last_status_check above answers only "did a check run", which a probe
		// that measured nothing can satisfy.
		if (JobResultProcessor::status_figures_are_stale($status_data,
				['disk_usage_percent', 'postgres_status', 'load_1m'])) {
			return 'secondary';
		}

		if ((isset($status_data['disk_usage_percent']) && $status_data['disk_usage_percent'] > 90) ||
			(isset($status_data['postgres_status']) && $status_data['postgres_status'] !== 'accepting connections')) {
			return 'danger';
		}

		// SSL absence: FQDN domain with SSL not active → warning
		$ssl_domain = $node->get('mgn_site_url') ? parse_url($node->get('mgn_site_url'), PHP_URL_HOST) : null;
		$ssl_warn = $ssl_domain
			&& !filter_var($ssl_domain, FILTER_VALIDATE_IP)
			&& $ssl_domain !== 'localhost'
			&& $node->get('mgn_ssl_state') !== 'active';

		if ((isset($status_data['disk_usage_percent']) && $status_data['disk_usage_percent'] > 80) ||
			(isset($status_data['load_1m']) && $status_data['load_1m'] > 5) ||
			$ssl_warn) {
			return 'warning';
		}
		return 'success';
	}

	/**
	 * Get the path to Globalvars_site.php on a remote node.
	 * Config is one level up from web_root (public_html).
	 */
	private static function get_config_path($node) {
		// mgn_web_root is NULL on a node with no site — a bare install, a relay
		// shard, a DNS box. rtrim(NULL) is deprecated and becomes a TypeError in
		// PHP 9; the path this builds for such a node was already meaningless,
		// and it stays exactly as meaningless rather than changing behaviour.
		$web_root = rtrim((string)$node->get('mgn_web_root'), '/');
		return dirname($web_root) . '/config/Globalvars_site.php';
	}

	/**
	 * Build shell script snippet to extract DB credentials from remote config.
	 * Sets $DB_NAME, $DB_USER, and $PGPASSWORD variables in the shell context.
	 * PGPASSWORD is exported so psql picks it up automatically.
	 */
	private static function get_db_credentials_script($node) {
		$config = self::get_config_path($node);
		// Extract dbname, dbusername, and dbpassword from Globalvars_site.php
		// Pattern: grep the line, take text before semicolon, take value after =,
		// strip whitespace, strip surrounding single quotes via sed
		$extract = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed s/^.// | sed s/.$//';
		return "DB_NAME=\$(grep dbname {$config} | {$extract}) && "
			 . "DB_USER=\$(grep dbusername {$config} | {$extract}) && "
			 . "export PGPASSWORD=\$(grep dbpassword {$config} | {$extract})";
	}

	/**
	 * Get the maintenance scripts path from the web root.
	 */
	private static function get_scripts_path($node) {
		// See get_config_path(): a siteless node stores NULL here.
		$web_root = rtrim((string)$node->get('mgn_web_root'), '/');
		return dirname($web_root) . '/maintenance_scripts';
	}

	/**
	 * Returns 'sudo ' when the node is bare-metal with a non-root SSH user,
	 * empty string for Docker nodes (commands run as root inside the container).
	 */
	private static function sudo_prefix($node) {
		$is_docker = (bool)$node->get('mgn_container_name');
		$ssh_user  = $node->get('mgn_ssh_user') ?: 'root';
		return (!$is_docker && $ssh_user !== 'root') ? 'sudo ' : '';
	}

	/**
	 * Check system health metrics on a node. Dispatches between API and SSH
	 * implementations based on has_api(). If API creds exist and /health probes
	 * green, the job runs as a single api step; otherwise it runs the six-ish
	 * SSH steps that have always been the default.
	 */
	public static function build_check_status($node) {
		if (self::has_primitive($node, 'check_status')) {
			return self::build_check_status_primitive($node);
		}
		if (self::has_api($node, 'check_status')) {
			return self::build_check_status_api($node);
		}
		if (self::has_ssh($node)) {
			return self::build_check_status_ssh($node);
		}
		throw new Exception(
			"Node '{$node->get('mgn_slug')}' cannot run check_status: "
			. "no API credentials (or health probe failed) and no SSH credentials configured."
		);
	}

	/**
	 * API path: a single GET to /api/v1/management/stats. The response JSON
	 * is parsed by JobResultProcessor::process_check_status into the same
	 * mgn_last_status_data shape the SSH path produces.
	 */
	/**
	 * Primitive path: {primitive: check_status, params: {}} — a NAME the node
	 * looks up in its own compiled-in vocabulary, not an instruction this plane
	 * composed. The node collects disk, memory, load, uptime and its own
	 * database facts without running a command at all, and returns the same key
	 * set the management API's stats endpoint returns, so the two transports
	 * leave mgn_last_status_data identical and JobResultProcessor needs to know
	 * nothing about which one ran.
	 *
	 * Returned as an envelope rather than a step list; ManagementJob::
	 * createPrimitiveJob is what stores it.
	 */
	public static function build_check_status_primitive($node) {
		return ['primitive' => 'check_status', 'params' => []];
	}

	public static function build_check_status_api($node) {
		return [
			['type' => 'api', 'label' => 'Fetch node stats', 'method' => 'GET', 'endpoint' => 'stats', 'timeout' => 30],
		];
	}

	/**
	 * Legacy SSH path — unchanged. Not called directly; the dispatcher above
	 * routes here when API isn't available.
	 */
	public static function build_check_status_ssh($node) {
		// Cast for the same reason as get_config_path(): a siteless node stores
		// NULL, and this value reaches dirname() below.
		$web_root = (string)$node->get('mgn_web_root');
		$skip_joinery = $node->get('mgn_skip_joinery_checks');

		$steps = [
			['type' => 'ssh', 'label' => 'Check disk usage', 'cmd' => 'df -h /'],
			['type' => 'ssh', 'label' => 'Check memory', 'cmd' => 'free -m'],
			['type' => 'ssh', 'label' => 'Check uptime', 'cmd' => 'uptime'],
		];

		if (!$skip_joinery) {
			$steps[] = ['type' => 'ssh', 'label' => 'Check PostgreSQL', 'cmd' => 'pg_isready'];
			$steps[] = ['type' => 'ssh', 'label' => 'Check Joinery version',
				'cmd' => self::get_db_credentials_script($node) . " && psql -U \"\$DB_USER\" -d \"\$DB_NAME\" -tAc \"SELECT 'VERSION=' || stg_value FROM stg_settings WHERE stg_name = 'system_version'\""];
			$steps[] = ['type' => 'ssh', 'label' => 'Recent errors',
				'cmd' => "grep -i 'fatal\\|error\\|exception' " . dirname($web_root) . "/logs/error.log | tail -20",
				'continue_on_error' => true];
		}

		if ($node->get('mgn_container_name')) {
			$container = $node->get('mgn_container_name');
			$steps[] = ['type' => 'ssh', 'label' => 'Container stats',
						'cmd' => "docker stats --no-stream {$container}", 'on_host' => true];
		}

		if (!$skip_joinery) {
			// Which recovery key this node is holding, if any. Asked here because
			// the status check is the job that already runs against every node —
			// the fleet view can then show the answer without reaching out to
			// each node on page load. Reports, never writes; a node too old to
			// have the tool simply does not answer.
			$steps[] = ['type' => 'ssh', 'label' => 'Check backup recovery key',
				'cmd' => 'php ' . escapeshellarg(self::get_scripts_path($node) . '/sysadmin_tools/set_recovery_key.php')
					. ' --report',
				'continue_on_error' => true];
		}

		if (!$skip_joinery) {
			$creds = self::get_db_credentials_script($node);
			$steps[] = ['type' => 'ssh', 'label' => 'Check cron health',
				'cmd' => "{$creds} && psql -U \"\$DB_USER\" -d \"\$DB_NAME\" -tAc \"SELECT 'CRON_LAST_RUN=' || stg_value FROM stg_settings WHERE stg_name = 'scheduled_tasks_last_cron_run'\"",
				'continue_on_error' => true];
		}

		if (!$skip_joinery) {
			// List databases in this node's PostgreSQL instance for the Internal Copy dropdown.
			// For Docker this runs inside the container; for bare-metal on the host. Either way,
			// it returns the databases accessible to the node's DB user.
			$creds = self::get_db_credentials_script($node);
			$steps[] = ['type' => 'ssh', 'label' => 'List databases',
				'cmd' => "{$creds} && echo \"CURRENT_DB=\$DB_NAME\" && psql -U \"\$DB_USER\" -tAc \"SELECT 'DB:' || datname FROM pg_database WHERE datistemplate = false AND datname NOT IN ('postgres') ORDER BY datname\"",
				'continue_on_error' => true];
		}

		// SSL certificate check. For Docker nodes the cert lives on the host (where the
		// reverse-proxy Apache and certbot run), not inside the container — hence on_host.
		// mgn_site_url is NULL on a node with no site — a bare install, a relay
		// shard, a DNS box — so it is cast rather than passed straight through.
		$domain = parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST) ?: '';
		if ($domain) {
			$is_docker  = (bool)$node->get('mgn_container_name');
			$domain_esc = escapeshellarg($domain);
			$steps[] = [
				'type'             => 'ssh',
				'label'            => 'Check SSL certificate',
				'on_host'          => $is_docker,
				'cmd'              => "if [ -f /etc/letsencrypt/live/{$domain_esc}/fullchain.pem ]; then"
				                   . " EXPIRY=\$(openssl x509 -enddate -noout -in /etc/letsencrypt/live/{$domain_esc}/fullchain.pem | cut -d= -f2);"
				                   . " echo \"SSL_CERT_FOUND domain={$domain} expiry=\$EXPIRY\";"
				                   . " else echo \"SSL_CERT_MISSING domain={$domain}\"; fi",
				'continue_on_error' => true,
			];
		}

		return $steps;
	}

	/**
	 * Backup a node's database using backup_database.sh.
	 * If the node has a cloud backup target, appends upload and optional cleanup steps.
	 */
	public static function build_backup_database($node, $params = []) {
		$scripts = self::get_scripts_path($node);
		$creds = self::get_db_credentials_script($node);

		// Force encryption whenever backups will be uploaded to a cloud target
		$target = self::get_target($node);
		if ($target) {
			$params['encryption'] = true;
		}

		// Script encrypts by default; pass --plaintext to disable
		$flags = '--non-interactive';
		if (empty($params['encryption'])) {
			$flags .= ' --plaintext';
		}

		$steps = [];
		$scratch = self::new_scratch_id();

		$sudo = self::sudo_prefix($node);
		$steps[] = ['type' => 'ssh', 'label' => 'Ensure backup directory',
			'cmd' => "{$sudo}mkdir -p /backups && {$sudo}chmod 1777 /backups"];
		$steps[] = self::step_snapshot_before($scratch);

		// The key is minted before the engine runs, because the engine encrypts
		// with it. Directory first, since that is where the key lands.
		if (!empty($params['encryption'])) {
			$steps[] = self::step_mint_envelope($node, $scratch);
			$flags .= ' --key-file ' . escapeshellarg(self::envelope_key_path($scratch));
		}

		$steps[] = ['type' => 'ssh', 'label' => 'Run database backup',
			'cmd' => "{$creds} && cd /backups && bash {$scripts}/sysadmin_tools/backup_database.sh {$flags} \"\$DB_NAME\"",
			'timeout' => 3600];

		if (!empty($params['encryption'])) {
			$steps[] = self::step_finalize_envelope($node, $scratch);
		}

		// Append upload step if node has a cloud target
		self::append_upload_steps($steps, $node, !empty($params['encryption']), $scratch);

		$steps[] = ['type' => 'ssh', 'label' => 'List backup files',
			'cmd' => 'ls -lht ' . self::backup_glob() . ' 2>/dev/null | head -5',
			'continue_on_error' => true];

		$steps[] = self::step_clean_before($scratch);

		return $steps;
	}

	/**
	 * Full project backup (DB + files + Apache config).
	 * If the node has a cloud backup target, appends upload and optional cleanup steps.
	 */
	public static function build_backup_project($node, $params = []) {
		$scripts = self::get_scripts_path($node);
		$web_root = rtrim($node->get('mgn_web_root'), '/');
		$project_root = dirname($web_root);
		// Extract project name from path: /var/www/html/empoweredhealthtn/public_html -> empoweredhealthtn
		$project_name = basename($project_root);

		// Force encryption whenever backups will be uploaded to a cloud target
		$target = self::get_target($node);
		if ($target) {
			$params['encryption'] = true;
		}

		// Script encrypts by default; pass --plaintext to disable
		$flags = '--non-interactive --output-dir /backups';
		if (empty($params['encryption'])) {
			$flags .= ' --plaintext';
		}

		$steps = [];
		$scratch = self::new_scratch_id();

		$sudo = self::sudo_prefix($node);
		$steps[] = ['type' => 'ssh', 'label' => 'Ensure backup directory',
			'cmd' => "{$sudo}mkdir -p /backups && {$sudo}chmod 1777 /backups"];
		$steps[] = self::step_snapshot_before($scratch);

		// The key is minted before the engine runs, because the engine encrypts
		// the archive with it as tar streams out.
		if (!empty($params['encryption'])) {
			$steps[] = self::step_mint_envelope($node, $scratch);
			$flags .= ' --key-file ' . escapeshellarg(self::envelope_key_path($scratch));
		}

		// Emit the credentials preamble so the script inherits DB_NAME/DB_USER/
		// PGPASSWORD from the environment instead of self-harvesting the config,
		// which the unprivileged SSH user may not be able to read (P-10).
		$creds = self::get_db_credentials_script($node);
		$steps[] = ['type' => 'ssh', 'label' => 'Run full project backup',
			'cmd' => "{$creds} && bash {$scripts}/sysadmin_tools/backup_project.sh {$project_name} {$flags}",
			'timeout' => 3600];

		if (!empty($params['encryption'])) {
			$steps[] = self::step_finalize_envelope($node, $scratch);
		}

		self::append_upload_steps($steps, $node, !empty($params['encryption']), $scratch);

		$steps[] = ['type' => 'ssh', 'label' => 'List backup files',
			'cmd' => "ls -lht /backups/ 2>/dev/null | head -5",
			'continue_on_error' => true];

		$steps[] = self::step_clean_before($scratch);

		return $steps;
	}

	/**
	 * A control plane's own backup of a node — the manager profile.
	 *
	 * The node does the work. It builds the archive, extends the chain, seals the
	 * envelope, uploads and sweeps its own local copies, all through the same
	 * BackupRunner that takes its own backups. Routing any of that through the
	 * control plane would drag whole archives down and push them back up for no
	 * reason, and would put the control plane in the path of every restore.
	 *
	 * What the control plane contributes is the two things the node has no other
	 * way to reach: the bucket and the credential. Both arrive with the run and
	 * leave with it.
	 *
	 * What opens the archive is not among them. The node seals to the recovery
	 * key it holds and has proven, read on the node, and refuses a run that
	 * arrives carrying key material. Supplying one from here would be the
	 * convenient arrangement — one key opening any node's backups — and is
	 * exactly why it is not built: sealing to a public key always appears to
	 * succeed, so a control plane that had been tampered with could re-seal
	 * every node's next backup, databases and mail included, to a key of its
	 * choosing, and nothing on any machine would look wrong until someone tried
	 * to restore. The price is paid knowingly: recovering a node's backup needs
	 * that node's recovery key, and no key here opens the fleet.
	 *
	 * The credential is a WRITE-ONLY one. The node can add objects to the shelf
	 * and cannot remove any, so a compromised node cannot erase the fleet's
	 * backups — which is why manager retention runs on the control plane instead
	 * (see FleetBackupRetention) and why this job never asks the node to prune.
	 *
	 * Config travels on stdin, not argv: argv is world-readable on the box for
	 * the life of the process and one of these fields is a credential.
	 */
	public static function build_backup_run($node, $params = []) {
		require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

		if (self::has_primitive($node, 'backup_run')) {
			return self::build_backup_run_primitive($node, $params);
		}

		$config = self::backup_run_config($node, $params);
		$web_root = rtrim((string)$node->get('mgn_web_root'), '/');

		// JSON_UNESCAPED_SLASHES only for readability in the job log; the value is
		// consumed by json_decode either way. The heredoc is quoted, so nothing in
		// the body is expanded by the shell.
		$json = json_encode($config, JSON_UNESCAPED_SLASHES);
		$eof  = '__JOINERY_BACKUP_CONFIG_EOF__';

		$cmd = 'php ' . escapeshellarg($web_root . '/utils/run_backup.php') . ' --profile=manager'
			. " <<'{$eof}'\n{$json}\n{$eof}";

		return [[
			'type'    => 'ssh',
			'label'   => 'Run backup to ' . $config['target_name'],
			'cmd'     => $cmd,
			// The engine ceiling plus the uploader's own retry budget: the agent
			// must not kill a transfer part-way through a retry.
			'timeout' => S3Signer::transfer_budget_seconds() + 10800,
		]];
	}

	/**
	 * Primitive path: the SAME config, as declared parameters rather than a
	 * shell heredoc. The node validates every field against its compiled-in
	 * spec, composes the engine's config itself, and hands it to
	 * run_backup.php on stdin — so the credential still never reaches argv,
	 * and nothing this plane sends is executed as syntax.
	 *
	 * A4 becomes structural here: the node declares no parameter through
	 * which encryption key material could arrive, so a job carrying one is
	 * refused as out-of-vocabulary rather than inspected and rejected.
	 *
	 * This method's EXISTENCE is what routes backup_run onto the channel —
	 * has_primitive() and transports_for() discover operations by
	 * method_exists on build_<op>_primitive, so an inline envelope inside
	 * build_backup_run would leave the gate permanently false.
	 */
	public static function build_backup_run_primitive($node, $params = []) {
		return ['primitive' => 'backup_run', 'params' => self::backup_run_config($node, $params)];
	}

	/**
	 * The shared config both transports carry: validated here on the plane,
	 * revalidated field by field on the node.
	 */
	private static function backup_run_config($node, $params = []) {
		self::assert_node_can_be_backed_up($node);

		$target = self::get_target($node);
		if (!$target) {
			$enabled_count = self::enabled_target_count();
			$why = ($enabled_count === 0)
				? 'this control plane has no enabled backup target at all'
				: ($enabled_count > 1
					? "this control plane has {$enabled_count} enabled backup targets and this node names "
						. 'none, so which one to use is a real choice — assign one to the node'
					: 'the backup target this node names is missing or switched off');
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' has nowhere to put a backup this control plane takes: "
				. $why . '.');
		}

		$web_root = rtrim((string)$node->get('mgn_web_root'), '/');
		if ($web_root === '') {
			throw new Exception("Node '{$node->get('mgn_slug')}' hosts no Joinery site to back up.");
		}

		$slug = trim((string)$node->get('mgn_slug'));
		if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
			throw new Exception(
				"Node slug '{$slug}' cannot be used as a bucket path segment; it may only contain "
				. 'letters, numbers, hyphens and underscores.');
		}

		$config = [
			'target_name'               => (string)$target->get('bkt_name'),
			'provider'                  => (string)$target->get('bkt_provider'),
			'bucket'                    => (string)$target->get('bkt_bucket'),
			'path_prefix'               => (string)($target->get('bkt_path_prefix') ?: 'joinery-backups'),
			'credentials_b64'           => self::creds_token($target),
			'slug'                      => $slug,
			'type'                      => (($params['type'] ?? 'project') === 'database') ? 'database' : 'project',
			'mode'                      => (($params['mode'] ?? 'chain') === 'full') ? 'full' : 'chain',
			'full_interval_days'        => (int)($params['full_interval_days'] ?? 7),
			'keep_local_days'           => (int)($params['keep_local_days'] ?? 7),
			'delete_local_after_upload' => (bool)($params['delete_local_after_upload']
				?? $node->get('mgn_delete_local_after_upload')),
		];

		return $config;
	}


	/**
	 * Restore a database from a backup file (local or cloud).
	 * Auto-prepends a backup before overwrite.
	 * If the file is cloud-only, downloads it to /backups/ first.
	 *
	 * Params:
	 *   filename   - original filename (used for cloud download target)
	 *   local_path - path on server if file exists locally (may be null)
	 *   cloud_path - provider path if file exists in cloud (may be null)
	 */
	public static function build_restore_database($node, $params) {
		$creds      = self::get_db_credentials_script($node);
		$scripts    = self::get_scripts_path($node);
		$sudo       = self::sudo_prefix($node);
		$local_path = $params['local_path'] ?? $params['backup_path'] ?? null;
		$cloud_path = $params['cloud_path'] ?? null;
		$filename   = $params['filename'] ?? basename((string)($local_path ?: $cloud_path));

		$steps = [];

		// Auto-backup target before overwrite
		$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup database before restore',
			'cmd' => "{$sudo}mkdir -p /backups && {$sudo}chmod 1777 /backups && {$creds} && umask 077 && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_restore_\$(date +%Y%m%d_%H%M%S).sql.gz",
			'timeout' => 3600];

		// If cloud-only: download to /backups/ on the remote server first
		if (!$local_path && $cloud_path) {
			$target = self::get_target($node);
			if ($target) {
				$bucket    = $target->get('bkt_bucket');
				$dl_path   = '/backups/' . basename($filename);

				$uploader_script = self::build_node_uploader_script($bucket, $target, 'download');
				$eof = '__JOINERY_UPLOADER_EOF__';
				$cp_arg = escapeshellarg($cloud_path);
				$dl_arg = escapeshellarg($dl_path);
				$dl_cmd = "php -- download {$cp_arg} {$dl_arg} <<'{$eof}'\n{$uploader_script}\n{$eof}";

				$steps[] = ['type' => 'ssh', 'label' => 'Download backup from cloud',
					'cmd' => $dl_cmd, 'timeout' => 3600];
				$local_path = $dl_path;
			}
		}

		// Neither a local path nor a downloadable cloud path leaves nothing to
		// restore FROM. Left unchecked this becomes an empty argument, and the
		// engine is asked to restore a file called "" after the pre-restore
		// snapshot has already run — a confusing failure in the one operation
		// where clarity matters most.
		if (!$local_path) {
			throw new Exception(
				'No backup file given to restore. Pass local_path, or cloud_path on a node with a configured target.');
		}

		$restore_path = escapeshellarg($local_path);
		$engine       = escapeshellarg("{$scripts}/sysadmin_tools/restore_database.sh");

		// One restore engine for every path: it verifies the archive (decrypting
		// an .enc with the resolved key) BEFORE dropping anything, replaces the
		// schema, and loads under ON_ERROR_STOP — a bad key or corrupt file
		// leaves the database intact.
		// The trailing cleanup removes the key step_resolve_restore_key unsealed
		// into a temp file — whatever the engine's outcome, and without eating
		// its exit code. A usable decryption key must not outlive the restore
		// that needed it. The node's standing legacy key is not ours to remove.
		$key_resolve = self::step_resolve_restore_key($node, $restore_path);
		$steps[] = ['type' => 'ssh', 'label' => 'Restore database from backup',
			'cmd' => "{$creds} && {$key_resolve} && bash {$engine} \"\$DB_NAME\" {$restore_path} --non-interactive --db-user \"\$DB_USER\" --key-file \"\$KEY_PATH\""
				. '; RC=$?; if [ -n "$KEY_PATH" ] && [ "$KEY_PATH" != "$HOME/.joinery_backup_key" ]; then rm -f "$KEY_PATH"; fi; exit $RC',
			'timeout' => 3600];

		// Verify
		$steps[] = ['type' => 'ssh', 'label' => 'Verify restore',
			'cmd' => "{$creds} && psql -U \"\$DB_USER\" \"\$DB_NAME\" -c \"SELECT count(*) AS table_count FROM information_schema.tables WHERE table_schema = 'public'\""];

		return $steps;
	}

	/**
	 * Restore a full project backup (.tar.gz) onto an existing node.
	 *
	 * $params:
	 *   filename      - display name of the archive (for logging)
	 *   local_path    - /backups/*.tar.gz on the node, or null
	 *   cloud_path    - remote object key in the bucket, or null
	 *   domain        - REQUIRED. The domain the restored site is to answer to.
	 *   skip_database - bool
	 *   skip_files    - bool
	 *
	 * There is no "restore the Apache config" choice. The captured virtualhost is
	 * never installed, in any case: the restore regenerates the serving config
	 * for this box from the platform's own templates and keeps a differing
	 * capture beside it for review. Making that an operator choice made the
	 * correct behaviour something you had to know to ask for.
	 */
	public static function build_restore_project($node, $params) {
		$local_path = $params['local_path'] ?? null;
		$cloud_path = $params['cloud_path'] ?? null;
		$filename   = $params['filename'] ?? basename((string)($local_path ?: $cloud_path));

		$skip_db     = !empty($params['skip_database']);
		$skip_files  = !empty($params['skip_files']);

		if ($skip_db && $skip_files) {
			throw new Exception('At least one of project files or database must be restored.');
		}

		$domain = self::restore_domain($node, $params);

		$web_root    = rtrim($node->get('mgn_web_root'), '/');
		$project_dir = dirname($web_root);
		$project_name = basename($project_dir);

		$scripts = self::get_scripts_path($node);
		$creds   = self::get_db_credentials_script($node);
		$steps   = [];

		// 1. Download from cloud if the backup only exists remotely
		if (!$local_path && $cloud_path) {
			$target = self::get_target($node);
			if (!$target) {
				throw new Exception('Cannot restore cloud-only backup: node has no backup target configured.');
			}
			$bucket    = $target->get('bkt_bucket');
			$dl_path   = '/backups/' . basename($filename);

			$uploader_script = self::build_node_uploader_script($bucket, $target, 'download');
			$eof    = '__JOINERY_UPLOADER_EOF__';
			$cp_arg = escapeshellarg($cloud_path);
			$dl_arg = escapeshellarg($dl_path);
			$dl_cmd = "php -- download {$cp_arg} {$dl_arg} <<'{$eof}'\n{$uploader_script}\n{$eof}";

			$steps[] = ['type' => 'ssh', 'label' => 'Download backup from cloud',
				'cmd' => $dl_cmd, 'timeout' => 3600];
			$local_path = $dl_path;
		}

		$sudo = self::sudo_prefix($node);
		// 2. Auto-backup current DB before overwrite (plaintext — fast recovery, no key needed)
		if (!$skip_db) {
			$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup database before restore',
				'cmd' => "{$sudo}mkdir -p /backups && {$sudo}chmod 1777 /backups && {$creds} && umask 077 && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_project_restore_\$(date +%Y%m%d_%H%M%S).sql.gz",
				'timeout' => 3600];
		}

		// 3. Auto-backup current project tree (no DB, no Apache — just the files)
		if (!$skip_files) {
			$parent = escapeshellarg(dirname($project_dir));
			$base   = escapeshellarg(basename($project_dir));
			$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup project files before restore',
				'cmd' => "{$sudo}mkdir -p /backups && {$sudo}chmod 1777 /backups && {$sudo}tar czf /backups/auto_pre_project_restore_\$(date +%Y%m%d_%H%M%S).tar.gz -C {$parent} {$base}",
				'timeout' => 3600];
		}

		// 4. Run restore_project.sh — --force activates non-interactive mode and
		// cascades --non-interactive into the inner restore_database.sh call.
		// The restore ends by reconciling the site to this machine (domain,
		// deployment shape, regenerated virtualhost, armed certificate retry).
		$skip_flags = '';
		if ($skip_db)     $skip_flags .= ' --skip-database';
		if ($skip_files)  $skip_flags .= ' --skip-files';

		// Resolve DB user + key path in this NON-sudo shell and pass them absolute
		// into the sudo'd restore, which forwards them to the DB restore engine
		// (B-2: a $HOME-relative key lookup breaks once sudo changes $HOME).
		$restore_cmd = "{$creds} && KEY_PATH=\"\$HOME/.joinery_backup_key\" && cd /backups && {$sudo}bash " . escapeshellarg("{$scripts}/sysadmin_tools/restore_project.sh")
			. ' ' . escapeshellarg($project_name)
			. ' ' . escapeshellarg($local_path)
			. ' --force' . $skip_flags
			. ' --domain ' . escapeshellarg($domain)
			. ' --db-user "$DB_USER" --key-file "$KEY_PATH"';

		$steps[] = ['type' => 'ssh', 'label' => 'Run project restore', 'cmd' => $restore_cmd, 'timeout' => 3600];

		// A container's public face is the HOST's proxy virtualhost, which lives
		// outside the container and therefore in no backup at all. The restore
		// inside the container cannot write it; this step can.
		foreach (self::steps_publish_container_domain($node, $domain) as $s) {
			$steps[] = $s;
		}

		// 5. Verify. A directory listing always succeeds, so it confirms nothing —
		// assert the restored web root actually holds a site, and that a restored
		// database came back with a populated schema. restore_project.sh checks
		// every file individually and fails on a partial copy; this is the cheap
		// second gate that catches a restore which ran against the wrong target.
		$web_root_esc = escapeshellarg($web_root);
		$verify_cmd = "test -s {$web_root_esc}/serve.php || "
		            . "{ echo 'VERIFY FAILED: no serve.php under the web root after restore'; exit 1; }; "
		            . "echo \"restore verify: \$(find {$web_root_esc} -type f | wc -l) files under the web root\"";
		if (!$skip_db) {
			$verify_cmd .= "; {$creds} && "
			            . "TABLES=\$(psql -U \"\$DB_USER\" \"\$DB_NAME\" -tAc \"SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'\") && "
			            . "echo \"restore verify: \$TABLES tables in the restored schema\" && "
			            . "test \"\$TABLES\" -gt 0 || "
			            . "{ echo 'VERIFY FAILED: the restored database has no tables'; exit 1; }";
		}
		$steps[] = ['type' => 'ssh', 'label' => 'Verify restore', 'cmd' => $verify_cmd];

		$steps[] = self::step_verify_identity($node, $domain);
		$steps[] = self::step_verify_served($node, $domain);

		return $steps;
	}

	/**
	 * The domain a restore job is to leave the site answering to.
	 *
	 * Required, never inferred. The correct answer depends on intent that is not
	 * present in the data: a real rebuild keeps the site's own domain and cuts
	 * DNS at the end, while a rehearsal must NOT claim it — the same backup and
	 * the same target want opposite answers. The tempting rule "the node's
	 * recorded URL wins when set" fails in exactly the case that matters most: a
	 * node provisioned during an incident carries whatever hostname somebody
	 * typed in a hurry, so the restore would adopt a throwaway name and the
	 * mistake would surface only after DNS moved.
	 *
	 * The dashboard pre-fills the field from mgn_site_url. Requiring the value
	 * costs one field and records the decision at the moment somebody knows it.
	 */
	private static function restore_domain($node, array $params) {
		$domain = trim((string)($params['domain'] ?? ''));
		if ($domain === '') {
			throw new Exception(
				'A restore must be told which domain the site is to answer to. It is not inferred: '
				. 'a rebuild keeps the site\'s own domain while a rehearsal must not claim it, and the '
				. 'backup looks identical either way.');
		}
		if (preg_match('/[^A-Za-z0-9.\-]/', $domain)) {
			throw new Exception("'{$domain}' is not a valid domain name for a restore.");
		}
		return $domain;
	}

	/**
	 * Publish a container site's domain on its HOST.
	 *
	 * In the container shape, TLS terminates on the host: the container serves
	 * :80 under an internal ServerName and the host proxies to it. That proxy
	 * virtualhost is outside the container's filesystem, so no backup contains
	 * it and no in-container restore can write it. manage_domain.sh writes it,
	 * is idempotent, and installs mod_proxy if the host lacks it.
	 *
	 * Empty for a bare-metal node (nothing to proxy) and for an IP or localhost
	 * (a ServerName-based proxy needs a routable name).
	 */
	private static function steps_publish_container_domain($node, $domain) {
		$container = trim((string)$node->get('mgn_container_name'));
		if ($container === '') {
			return [];
		}
		if ($domain === 'localhost' || preg_match('/^\d+\.\d+\.\d+\.\d+$/', $domain)) {
			return [];
		}

		// maintenance_scripts is baked into the container image, not present on
		// the host — so run the copy inside the container has no host effect.
		// Read the script out of the container and run it on the host instead.
		// manage_domain.sh identifies the site by its CONTAINER name (that is what
		// it looks for in `docker ps` and what it proxies to), not by the node's
		// slug, which is a control-plane label and need not match.
		$c = escapeshellarg($container);
		$d = escapeshellarg($domain);
		$s = $c;
		$scripts = self::get_scripts_path($node);
		$md = escapeshellarg($scripts . '/sysadmin_tools/manage_domain.sh');

		return [[
			'type' => 'ssh', 'label' => 'Publish the domain on the container host', 'on_host' => true,
			'cmd' => "TMP=\$(mktemp) && sudo docker exec {$c} cat {$md} > \"\$TMP\" && "
			       . "sudo bash \"\$TMP\" set {$s} {$d} --no-ssl; RC=\$?; rm -f \"\$TMP\"; exit \$RC",
			'timeout' => 600,
			// A host that already proxies this name is the normal case on a
			// re-restore, and manage_domain.sh says so rather than failing —
			// but a host with no Apache at all should not sink the restore
			// that already succeeded inside the container.
			'continue_on_error' => true,
		]];
	}

	/**
	 * Prove the restored site AGREES with the machine it landed on.
	 *
	 * The rebuild drill passed every check it had and still produced a site that
	 * believed it was in a container, at the old address, with a database
	 * password from another box. Each of those is one grep away from being
	 * caught, which is why this step exists.
	 */
	private static function step_verify_identity($node, $domain) {
		$config = escapeshellarg(self::get_config_path($node));
		$sudo   = self::sudo_prefix($node);
		$is_docker = (bool)$node->get('mgn_container_name');
		$want_env  = $is_docker ? 'docker' : 'baremetal';
		$d = escapeshellarg($domain);
		$creds = self::get_db_credentials_script($node);

		$cmd = "CFG={$config}; "
		     . "GOT_DOMAIN=\$({$sudo}grep \"settings\\['webDir'\\]\" \$CFG | head -1 | grep -oP \"'[^']*'\" | tail -1 | tr -d \"'\"); "
		     . "GOT_ENV=\$({$sudo}grep \"settings\\['deployment_environment'\\]\" \$CFG | head -1 | grep -oP \"'[^']*'\" | tail -1 | tr -d \"'\"); "
		     . "case \"\$GOT_ENV\" in bare-metal) GOT_ENV=baremetal ;; esac; "
		     . "echo \"identity: webDir=\$GOT_DOMAIN deployment_environment=\$GOT_ENV\"; "
		     . "test \"\$GOT_DOMAIN\" = {$d} || { echo \"VERIFY FAILED: the site still calls itself \$GOT_DOMAIN\"; exit 1; }; "
		     . "test \"\$GOT_ENV\" = '{$want_env}' || { echo \"VERIFY FAILED: the site thinks it is running on \$GOT_ENV, not {$want_env}\"; exit 1; }; "
		     . "{$creds} && psql -U \"\$DB_USER\" \"\$DB_NAME\" -tAc 'SELECT 1' > /dev/null || "
		     . "{ echo 'VERIFY FAILED: the database will not open with this machine credentials'; exit 1; }; "
		     . "echo 'identity verify: OK'";

		return ['type' => 'ssh', 'label' => 'Verify the site agrees with this machine', 'cmd' => $cmd];
	}

	/**
	 * Prove the site is actually being SERVED — over HTTPS when it can be.
	 *
	 * Called out separately because the drill's failure passed an HTTP-only
	 * check comfortably: the site answered on :80 the whole time, under a
	 * container's internal virtualhost, with a valid certificate sitting unused
	 * on disk.
	 *
	 * HTTPS is required only once the domain resolves HERE. Restoring before the
	 * DNS cutover is the normal shape of a rebuild, and the certificate arrives
	 * on its own afterwards — so a name that does not point here yet reports
	 * pending rather than failing.
	 */
	private static function step_verify_served($node, $domain) {
		$d = escapeshellarg($domain);
		$cmd = "DOM={$d}; "
		     . "MYIP=\$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 icanhazip.com 2>/dev/null); "
		     . "DNSIP=\$(getent ahostsv4 \"\$DOM\" 2>/dev/null | awk '{print \$1; exit}'); "
		     . "if [ -n \"\$DNSIP\" ] && [ \"\$DNSIP\" = \"\$MYIP\" ]; then "
		     .   "CODE=\$(curl -sILo /dev/null -w '%{http_code}' --max-time 20 \"https://\$DOM/\" 2>/dev/null); "
		     .   "echo \"served: https://\$DOM -> HTTP \$CODE\"; "
		     .   "case \"\$CODE\" in 200|301|302|303) echo 'served verify: OK over HTTPS' ;; "
		     .     "*) echo \"VERIFY FAILED: \$DOM resolves here but HTTPS answered \$CODE\"; exit 1 ;; esac; "
		     . "else "
		     .   "CODE=\$(curl -sILo /dev/null -w '%{http_code}' --max-time 20 -H \"Host: \$DOM\" 'http://127.0.0.1/' 2>/dev/null); "
		     .   "echo \"served: \$DOM does not resolve to this server yet (DNS \${DNSIP:-none} vs \${MYIP:-unknown})\"; "
		     .   "echo \"served: local HTTP answered \$CODE\"; "
		     .   "echo 'served verify: certificate deferred — the retry timer issues it once DNS points here'; "
		     . "fi";

		return ['type' => 'ssh', 'label' => 'Verify the site is served', 'cmd' => $cmd,
		        'on_host' => true, 'timeout' => 120];
	}

	/**
	 * Restore a node from an incremental backup CHAIN.
	 *
	 * Chains are what the fleet actually produces — the manager backup profile
	 * writes a full plus incrementals, not standalone archives — so without this
	 * the backups every scheduled run uploads could not be restored from the
	 * dashboard at all.
	 *
	 * The shape of the job:
	 *   1. fetch the chain's manifest onto the node
	 *   2. recover the chain data key from the manifest's envelope, using the
	 *      node's OWN backup_site_key (every chain seals to the node as well as
	 *      to the control plane's recovery key, so a node can always open its
	 *      own backups without anybody's private key travelling)
	 *   3. download every artifact the manifest names, up to the chosen run
	 *   4. restore_chain.sh verifies each one against its recorded size and hash
	 *      BEFORE writing anything, applies them in order, then reconciles
	 *
	 * $params:
	 *   chain_id  - e.g. chain-20260807_231507 (required)
	 *   seq       - restore as at this run; default the newest in the manifest
	 *   domain    - REQUIRED, see restore_domain()
	 *   skip_database - bool
	 */
	public static function build_restore_chain($node, $params) {
		require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

		$chain_id = trim((string)($params['chain_id'] ?? ''));
		if ($chain_id === '' || !preg_match('/^chain-[0-9_]+$/', $chain_id)) {
			throw new Exception('A chain restore needs the chain id (for example chain-20260807_231507).');
		}
		$seq    = isset($params['seq']) && $params['seq'] !== '' ? (int)$params['seq'] : null;
		$domain = self::restore_domain($node, $params);
		$skip_db = !empty($params['skip_database']);

		$target = self::get_target($node);
		if (!$target) {
			throw new Exception('This node has no backup target, so there is no shelf to read a chain from.');
		}

		$slug = trim((string)$node->get('mgn_slug'));
		if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
			throw new Exception("Node slug '{$slug}' cannot be used as a bucket path segment.");
		}

		$web_root     = rtrim((string)$node->get('mgn_web_root'), '/');
		$project_dir  = dirname($web_root);
		$project_name = basename($project_dir);
		$scripts      = self::get_scripts_path($node);
		$sudo         = self::sudo_prefix($node);
		$creds        = self::get_db_credentials_script($node);

		// {prefix}/{slug}/{profile}/{chain_id}/. The profile segment keeps two
		// parties' backups apart — the site's own and this control plane's — so
		// it is carried from the listing rather than assumed. A chain restore
		// started from the dashboard is normally a manager-profile chain.
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupChainListHelper.php'));
		// normalize('') means the SITE profile, which is a different shelf — so an
		// unset parameter defaults here rather than falling through to it.
		$profile   = BackupProfile::normalize(
			trim((string)($params['profile'] ?? '')) ?: BackupProfile::MANAGER);
		$chain_key = BackupChainListHelper::chain_path($target, $slug, $profile, $chain_id);
		$work      = '/backups/restore_' . $chain_id;

		$uploader = self::build_node_uploader_script($target->get('bkt_bucket'), $target, 'download');
		$eof      = '__JOINERY_UPLOADER_EOF__';

		$steps = [];

		$steps[] = ['type' => 'ssh', 'label' => 'Prepare the restore workspace',
			'cmd' => "{$sudo}mkdir -p " . escapeshellarg($work) . " && {$sudo}chmod 1777 /backups && "
			       . "{$sudo}chmod 700 " . escapeshellarg($work) . " && echo WORKSPACE_READY"];

		// The manifest first: it names every artifact, in order, with the size and
		// hash each must match, and carries the sealed data keys. A bucket full of
		// files-0003.tar.gz.enc without it is not a backup.
		$manifest_remote = escapeshellarg($chain_key . '/manifest.json');
		$manifest_local  = escapeshellarg($work . '/manifest.json');
		$steps[] = ['type' => 'ssh', 'label' => 'Fetch the chain manifest',
			'cmd' => "php -- download {$manifest_remote} {$manifest_local} <<'{$eof}'\n{$uploader}\n{$eof}",
			'timeout' => 600];

		// Recover the chain data key on the NODE, from the node's own site key.
		// The control plane's recovery private key never travels: it is the key
		// of last resort for a machine that no longer exists, and putting it in a
		// job record would make every stored job a copy of it.
		$envelope_tool = escapeshellarg($scripts . '/sysadmin_tools/backup_envelope.php');
		$site_key      = escapeshellarg($project_dir . '/config/backup_site_key');
		$key_out       = escapeshellarg($work . '/chain.key');
		$steps[] = ['type' => 'ssh', 'label' => 'Recover the chain key',
			'cmd' => "{$sudo}test -f {$site_key} || { echo 'This node has no backup_site_key, so it cannot open its own chain.'; "
			       . "echo 'Recover the key with backup_envelope.php open --sidecar manifest.json --private <recovery key> and restore from a shell.'; exit 1; }; "
			       . "{$sudo}php {$envelope_tool} open --sidecar {$manifest_local} --private {$site_key} --key-out {$key_out} > /dev/null 2>&1 || "
			       . "{ echo 'The chain envelope did not open with this node key — this chain was taken by a different machine.'; "
			       . "echo 'Restore it from a shell with the recovery key: backup_envelope.php open --sidecar manifest.json --private <recovery key>'; exit 1; }; "
			       . "{$sudo}chmod 600 {$key_out}; echo CHAIN_KEY_OK",
			'timeout' => 300];

		// Download exactly what the manifest names, in the run range asked for.
		// Reading the manifest ON the node keeps the artifact list and the file
		// list one thing — a control plane that computed names itself would be a
		// second implementation of the chain layout, free to drift.
		// `|| exit 1` rides the heredoc REDIRECT line, not the line after the
		// terminator: anything placed after a terminator is a fresh statement, and
		// putting the guard there both breaks the loop's syntax and (in the older
		// form of this bug) got swallowed into the uploader's stdin.
		$seq_arg = ($seq === null) ? '' : (string)$seq;
		$fetch = "cd " . escapeshellarg($work) . " || exit 1\n"
		       . "NAMES=\$(python3 - manifest.json " . escapeshellarg($seq_arg) . " <<'PYEOF'\n"
		       . "import json,sys\n"
		       . "m=json.load(open(sys.argv[1]))\n"
		       . "runs=m.get('runs') or []\n"
		       . "want=sys.argv[2]\n"
		       . "seq=(len(runs)-1) if want=='' else int(want)\n"
		       . "if seq<0 or seq>=len(runs): sys.exit('this chain has no run %s' % want)\n"
		       . "names=[runs[i]['artifacts']['files']['name'] for i in range(seq+1)]\n"
		       . "last=runs[seq].get('artifacts') or {}\n"
		       . "for kind in ('db','meta'):\n"
		       . "    if kind in last: names.append(last[kind]['name'])\n"
		       . "print('\\n'.join(names))\n"
		       . "PYEOF\n"
		       . ") || exit 1\n"
		       . "test -n \"\$NAMES\" || { echo 'the manifest names no artifacts'; exit 1; }\n"
		       . "for N in \$NAMES; do\n"
		       . "  echo \"fetching \$N\"\n"
		       . "  php -- download " . escapeshellarg($chain_key) . "/\"\$N\" \"\$N\" <<'{$eof}' || exit 1\n"
		       . "{$uploader}\n"
		       . "{$eof}\n"
		       . "done\n"
		       . "echo CHAIN_ARTIFACTS_FETCHED";
		$steps[] = ['type' => 'ssh', 'label' => 'Download the chain artifacts',
			'cmd' => $fetch, 'timeout' => S3Signer::transfer_budget_seconds() + 3600];

		// Pre-restore snapshot, same as every other restore path.
		if (!$skip_db) {
			$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup database before restore',
				'cmd' => "{$creds} && umask 077 && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_chain_restore_\$(date +%Y%m%d_%H%M%S).sql.gz",
				'timeout' => 3600];
		}

		$restore = "{$sudo}bash " . escapeshellarg("{$scripts}/sysadmin_tools/restore_chain.sh")
			. ' ' . escapeshellarg($project_name)
			. ' --artifacts ' . escapeshellarg($work)
			. ' --key-file ' . $key_out
			. ' --domain ' . escapeshellarg($domain)
			. ' --force';
		if ($seq !== null)  { $restore .= ' --seq ' . escapeshellarg((string)$seq); }
		if ($skip_db)       { $restore .= ' --skip-database'; }

		// The key is shredded whatever the outcome, without eating the exit code.
		// A usable decryption key must not outlive the restore that needed it.
		$steps[] = ['type' => 'ssh', 'label' => 'Restore the chain',
			'cmd' => $restore . "; RC=\$?; {$sudo}rm -f {$key_out}; exit \$RC",
			'timeout' => 7200];

		foreach (self::steps_publish_container_domain($node, $domain) as $s) {
			$steps[] = $s;
		}

		$steps[] = self::step_verify_identity($node, $domain);
		$steps[] = self::step_verify_served($node, $domain);

		$steps[] = ['type' => 'ssh', 'label' => 'Clean up the restore workspace',
			'cmd' => "{$sudo}rm -rf " . escapeshellarg($work),
			'teardown' => true, 'continue_on_error' => true, 'timeout' => 300];

		return $steps;
	}

	/**
	 * Apply a Joinery update on the node via its own upgrade.php.
	 *
	 * The most-used operation this plane has, and the last large one to cross.
	 * The SSH form below composes `cd {web_root} && php utils/upgrade.php
	 * --verbose` from a column in THIS plane's database and sends it to be run
	 * as root — which is the whole shape the migration exists to end: the string
	 * is executed, and the path in it is this plane's belief about where someone
	 * else's site lives.
	 *
	 * The SSH builder stays until the Phase 3 cutover, for the nodes that have
	 * not paired. transports_for() prefers the primitive wherever it is
	 * available, so a paired node with the channel switched on stops using this
	 * path without any caller changing.
	 */
	public static function build_apply_update($node, $params = []) {
		if (self::has_primitive($node, 'apply_update')) {
			return self::build_apply_update_primitive($node);
		}
		$web_root = $node->get('mgn_web_root');

		return [
			['type' => 'ssh', 'label' => 'Apply Joinery update',
			 'cmd' => "cd {$web_root} && php utils/upgrade.php --verbose",
			 'timeout' => 3600],
		];
	}

	/**
	 * Primitive path: a NAME, and nothing else.
	 *
	 * A node upgrades from the source IT is configured with. Every parameter the
	 * SSH string carried was either this plane's belief about the node (the web
	 * root, which the node knows better) or a flag the node fixes for itself
	 * (--verbose, compiled into the primitive because the result processor reads
	 * the transcript). There is deliberately nothing here through which this
	 * plane could name a version, a source, or an argument.
	 *
	 * WHAT THE NODE DOES WITH ITS AGENT WHILE THIS RUNS. An upgrade runs the
	 * host installers, and the first of those installs and restarts the agent —
	 * the process running this job. install_agent.sh 2.7 defers both while a job
	 * is in progress and the agent takes the new binary through its own signed
	 * self-update about a minute later. The consequence for this plane is that
	 * an apply_update job on a paired node routinely completes and is THEN
	 * followed by the node's agent going away and coming back; a poll gap of a
	 * minute or two after a successful upgrade is the design working.
	 *
	 * This method's EXISTENCE is what routes the operation — has_primitive() and
	 * transports_for() discover by method_exists on build_<op>_primitive.
	 */
	public static function build_apply_update_primitive($node) {
		return ['primitive' => 'apply_update', 'params' => []];
	}

	/**
	 * Run every active plugin's host installer on the node via
	 * maintenance_scripts/install_tools/_plugin_installers_start.sh. Container
	 * starts and code upgrades already run it; this job is the root moment a
	 * bare-metal node otherwise lacks after activating a plugin whose
	 * host_installer configures system services (e.g. mailbox -> Postfix).
	 * The runner is fail-safe by contract (inactive plugin, unreachable DB, or
	 * an installer failure all exit 0), so the job output is the record of what
	 * ran — read it, don't infer from the green.
	 */
	public static function build_run_plugin_installers($node) {
		if (self::has_primitive($node, 'run_plugin_installers')) {
			return self::build_run_plugin_installers_primitive($node);
		}
		if (!self::has_ssh($node)) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot run plugin installers: no SSH credentials configured.");
		}
		$web_root = rtrim($node->get('mgn_web_root'), '/');
		if (!$web_root || dirname($web_root) === '/' || dirname($web_root) === '.') {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot run plugin installers: mgn_web_root is not set.");
		}
		$site_dir     = dirname($web_root);
		$sitename_esc = escapeshellarg(basename($site_dir));
		$runner       = $site_dir . '/maintenance_scripts/install_tools/_plugin_installers_start.sh';
		$sudo         = self::sudo_prefix($node);
		$creds        = self::get_db_credentials_script($node);

		return [
			['type' => 'ssh', 'label' => 'Run active plugin host installers',
			 // PGPASSWORD is passed explicitly because sudo does not forward the
			 // caller's environment; the runner needs it to query active plugins.
			 'cmd' => "{$creds} && {$sudo}env PGPASSWORD=\"\$PGPASSWORD\" bash {$runner} {$sitename_esc}",
			 'timeout' => 900],
		];
	}

	/**
	 * Turn the agent on for a node, and optionally have it ask to join this
	 * management node — the fleet path, so an operator does not visit every
	 * node's own admin page to enable something they administer already.
	 *
	 * Both halves are the node's own decisions, recorded in the node's own
	 * settings by the node's own CLI. Nothing here reaches past what SSH to
	 * that machine already permits, which is precisely why this action exists
	 * only while SSH does: at the Phase 3 cutover it goes with it, and a node's
	 * own Management Node page becomes the only way in.
	 *
	 * What it deliberately does NOT do is enroll anything. A join is a request;
	 * an administrator on this plane still approves it after comparing the key
	 * fingerprint the node reports against the one the request carries. Doing
	 * that comparison for a fleet is the operator's job, not this job's.
	 *
	 * @param array $params plane_url (omit to enable without asking to join)
	 */
	public static function build_enable_agent($node, $params = []) {
		if (!self::has_ssh($node)) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot be given an agent: no SSH credentials configured.");
		}
		$web_root = rtrim($node->get('mgn_web_root'), '/');
		if (!$web_root || dirname($web_root) === '/' || dirname($web_root) === '.') {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot be given an agent: mgn_web_root is not set.");
		}

		$plane_url = trim((string)($params['plane_url'] ?? ''));
		$switch    = '--on';
		if ($plane_url !== '') {
			// The same rule the node's own page and CLI apply, from the same
			// function: what counts as a management node address is one
			// decision, and a second copy here would be the one that drifts.
			require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));
			$refusal = admin_management_node_url_refusal($plane_url);
			if ($refusal !== null) {
				throw new Exception('That is not a usable management node URL to join: ' . $refusal);
			}
			$switch .= ' --join=' . escapeshellarg(rtrim($plane_url, '/'));
		}

		$site_dir     = dirname($web_root);
		$sitename_esc = escapeshellarg(basename($site_dir));
		$runner       = $site_dir . '/maintenance_scripts/install_tools/_plugin_installers_start.sh';
		$sudo         = self::sudo_prefix($node);
		$creds        = self::get_db_credentials_script($node);

		return [
			['type' => 'ssh', 'label' => 'Switch the agent on for this node',
			 'cmd' => "cd {$web_root} && php utils/agent_control.php {$switch}",
			 'timeout' => 120],
			// The switch is a setting; this is the root moment that acts on it.
			// Same runner as Run Plugin Installers — the agent installer is a
			// core one, so it runs here whether or not any plugin is active.
			['type' => 'ssh', 'label' => 'Install and start the agent',
			 'cmd' => "{$creds} && {$sudo}env PGPASSWORD=\"\$PGPASSWORD\" bash {$runner} {$sitename_esc}",
			 'timeout' => 900],
		];
	}

	/**
	 * Primitive path: NO PARAMETERS AT ALL.
	 *
	 * Both values the SSH step carries are dropped rather than translated:
	 *
	 *  - the site name, because the node knows what it is called. Under SSH it
	 *    was computed here from mgn_web_root, so a node was told its own name by
	 *    a remote party — as correct as a row someone else can edit, and since
	 *    the name becomes a filesystem path, as safe.
	 *  - PGPASSWORD, which existed only because sudo drops the caller's
	 *    environment. The agent is already root on the node.
	 *
	 * So nothing crosses but the node id and a name. Nothing the plane sends can
	 * influence what runs.
	 *
	 * The runner meets it there: it derives its own site root from where the file
	 * lives and reads its own database credentials out of the site config, so it
	 * needs neither an argument nor anything in the environment. SITENAME stays
	 * optional for the callers that still pass one — the Dockerfile CMD,
	 * install.sh, upgrade.php — which is why this primitive can send nothing at
	 * all and still have the plugin loop run.
	 *
	 * What the runner reports, JobResultProcessor::process_run_plugin_installers
	 * reads: the runner exits 0 on every path because it also runs at container
	 * start, where a failing installer must not stop a site from booting, so the
	 * job's colour comes from parsing its output rather than from its exit code.
	 */
	public static function build_run_plugin_installers_primitive($node) {
		return ['primitive' => 'run_plugin_installers', 'params' => []];
	}

	/**
	 * Which backup recovery key a node holds.
	 *
	 * PRIMITIVE ONLY, and it exists to close a hole this transport could not see
	 * in itself. Every backup is gated on backup_recovery_state
	 * (RecoveryKeyFleet::node_state), and that fact comes from
	 * BackupRecoveryKey::key_report() — PHP the agent cannot call. So a primitive
	 * check_status answered everything except the field backups depend on. The
	 * SSH check_status asks for it as one of its steps; the primitive one has no
	 * equivalent, which is why it is asked for separately here.
	 *
	 * It runs the node's own shipped reporting tool rather than anything new:
	 * set_recovery_key.php --report is reports-only (its write path was removed
	 * deliberately — a recovery key arriving from outside cannot be verified by
	 * the site receiving it), and the plane already parses its RECOVERY_KEY=
	 * line from the SSH path. One definition of what counts as proven, used by
	 * both transports.
	 */
	public static function build_recovery_key_report($node) {
		if (!self::has_primitive($node, 'recovery_key_report')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot report its recovery key over the agent channel: "
				. "no agent has paired with this plane. The SSH check_status asks for it as a step.");
		}
		return self::build_recovery_key_report_primitive($node);
	}

	public static function build_recovery_key_report_primitive($node) {
		return ['primitive' => 'recovery_key_report', 'params' => []];
	}

	/**
	 * Restart a node's agent.
	 *
	 * PRIMITIVE ONLY, and that is the interesting part: there is no
	 * build_restart_agent_ssh below, and there never will be. The SSH way to
	 * restart an agent is `pkill -x joinery-agent` — a command, which is the one
	 * thing A1 says this plane may not send. So the operation exists on exactly
	 * one transport, and a node that has not paired simply cannot be asked
	 * (can_run() reports why). An operation whose only implementation is the
	 * safe one cannot be performed the unsafe way by accident.
	 *
	 * It exists because of a real morning: three container nodes inherited an
	 * flock on the site's .upgrade.lock across a fork and refused every later
	 * upgrade with 'Another upgrade is already running', held by the agent
	 * itself. The agents were healthy enough to poll and take work. Clearing it
	 * needed a human with an SSH key on each machine, which is the errand this
	 * whole migration exists to end.
	 *
	 * The node may refuse, and a refusal here is the primitive working. It
	 * restarts only when it can prove something will start it again — systemd
	 * supervising this process, or the cron keepalive installed and switched on.
	 * An agent that exits under no supervisor does not come back, and after the
	 * Phase 3 cutover there is no SSH key left to go and start it with. That
	 * decision is the node's because the node is the only party that can see the
	 * answer: this plane cannot read /etc/joinery-agent/enabled, and a plane that
	 * guessed would be guessing about whether it is about to lose the node.
	 */
	public static function build_restart_agent($node) {
		if (!self::has_primitive($node, 'restart_agent')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot be asked to restart its agent: "
				. "no agent has paired with this plane. Restarting is an agent primitive and has "
				. "deliberately no SSH equivalent — the SSH version would be an arbitrary command."
			);
		}
		return self::build_restart_agent_primitive($node);
	}

	/**
	 * Primitive path: a NAME and nothing else. There is nothing to configure
	 * about becoming new again, and a parameter would only be a way for this
	 * plane to influence how the node comes back.
	 *
	 * The agent posts this job's result BEFORE it exits. A job that ended when
	 * the process did would sit claimed until the plane's claim timeout returned
	 * it to pending, and the restarted agent would run it again — a restart that
	 * reads as a hang, and then repeats.
	 */
	public static function build_restart_agent_primitive($node) {
		return ['primitive' => 'restart_agent', 'params' => []];
	}


	/**
	 * Publish a new upgrade from the control plane (runs locally).
	 * If major/minor/patch are in $params, passes them as an explicit version arg;
	 * otherwise the CLI auto-detects the next version.
	 */
	public static function build_publish_upgrade($params) {
		$notes = escapeshellarg($params['release_notes']);
		$version_arg = '';
		if (isset($params['major'], $params['minor'], $params['patch'])) {
			$version = intval($params['major']) . '.' . intval($params['minor']) . '.' . intval($params['patch']);
			$version_arg = escapeshellarg($version) . ' ';
		}
		// The publish runs on whichever site is building the release, which is not
		// always the one this was written on: getjoinery publishes too, and its web
		// root is its own. Ask for the running site's path rather than naming one.
		$web_root = escapeshellarg(PathHelper::getRootDir());
		return [
			['type' => 'local', 'label' => 'Publish upgrade',
			 'cmd' => "cd {$web_root} && php plugins/server_manager/includes/publish_upgrade.php {$version_arg}{$notes}"],
		];
	}

	/**
	 * Build an SSH command prefix for local-type steps that SSH to a remote host.
	 * Used by discover_nodes which runs before a node record exists.
	 */
	public static function ssh_prefix($host, $ssh_user, $ssh_key_path, $ssh_port = 22) {
		$port_flag = ($ssh_port != 22) ? "-p {$ssh_port} " : '';
		return "ssh -i " . escapeshellarg($ssh_key_path)
			 . " -o StrictHostKeyChecking=no -o ConnectTimeout=10 -o BatchMode=yes "
			 . $port_flag
			 . escapeshellarg("{$ssh_user}@{$host}");
	}

	/**
	 * Discover Joinery instances on a remote host.
	 * All steps are 'local' type — the agent runs SSH commands from the control plane.
	 * No node record is needed.
	 */
	public static function build_discover_nodes($params) {
		$host = $params['host'];
		$ssh_user = $params['ssh_user'] ?? 'root';
		$ssh_key_path = $params['ssh_key_path'];
		$ssh_port = intval($params['ssh_port'] ?? 22) ?: 22;

		$ssh = self::ssh_prefix($host, $ssh_user, $ssh_key_path, $ssh_port);

		$steps = [];

		// Step 1: Test connection and get hostname
		$steps[] = ['type' => 'local', 'label' => 'Test SSH connection',
			'cmd' => "{$ssh} 'echo CONNECT_OK && hostname'"];

		// Step 2: List Docker containers (continue on error — may not have Docker)
		$steps[] = ['type' => 'local', 'label' => 'List Docker containers',
			'cmd' => "{$ssh} 'docker ps --format \"{{.Names}}\" 2>/dev/null || echo NO_DOCKER'",
			'continue_on_error' => true];

		// Step 3: Write scan script to temp file and execute remotely via stdin
		$scan_script = self::get_discover_script();
		$script_path = '/tmp/joinery_discover_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.sh';

		$steps[] = ['type' => 'local', 'label' => 'Write scan script',
			'cmd' => "cat > {$script_path} << 'SCANEOF'\n{$scan_script}\nSCANEOF\nchmod +x {$script_path}"];

		$steps[] = ['type' => 'local', 'label' => 'Scan for Joinery instances',
			'cmd' => "{$ssh} 'bash -s' < {$script_path}",
			'timeout' => 120];

		$steps[] = ['type' => 'local', 'label' => 'Clean up scan script',
			'cmd' => "rm -f {$script_path}",
			'teardown' => true, 'timeout' => 120, 'continue_on_error' => true];

		return $steps;
	}

	// ── Backup target helpers ──

	/**
	 * Scratch paths for a backup job, before the archive's real name is known.
	 *
	 * Per job, not fixed. Two backups running on one node at the same time — two
	 * jobs, or a job alongside the node's own scheduled run — used to mint over
	 * each other's envelope at one shared path, and the loser ended up with an
	 * archive whose envelope named a different archive. That is unrecoverable and
	 * silent: the archive looks encrypted and complete right up until someone
	 * needs it.
	 *
	 * The id is baked into the stored command, so a teardown replayed against a
	 * stale job still addresses that job's own scratch and nobody else's.
	 */
	const ENVELOPE_SCRATCH_PREFIX = '/backups/.jy_envelope_';
	const BEFORE_LIST_PREFIX      = '/backups/.jy_before_';

	private static function new_scratch_id() {
		return bin2hex(random_bytes(6));
	}

	private static function envelope_key_path($scratch) {
		return self::ENVELOPE_SCRATCH_PREFIX . $scratch . '.key';
	}

	private static function envelope_sidecar_path($scratch) {
		return self::ENVELOPE_SCRATCH_PREFIX . $scratch . '.keys.json';
	}

	private static function before_list_path($scratch) {
		return self::BEFORE_LIST_PREFIX . $scratch . '.list';
	}

	/**
	 * Record what is in the backup directory BEFORE this job writes anything.
	 *
	 * Every later step that has to name "the archive this job just produced"
	 * resolves it by being new, not by being newest. Newest is wrong whenever
	 * anything else writes to the same directory in the same window — the node's
	 * own scheduled backup, most obviously — and the failure mode is that the job
	 * seals, uploads or deletes a file belonging to somebody else's run.
	 */
	private static function step_snapshot_before($scratch) {
		$before = escapeshellarg(self::before_list_path($scratch));
		return ['type' => 'ssh', 'label' => 'Note existing backup files',
			'cmd' => 'ls -1d /backups/* 2>/dev/null > ' . $before . ' || true',
			'continue_on_error' => true, 'timeout' => 60];
	}

	/** Remove the before-list. Scratch at a per-job path: teardown-safe. */
	private static function step_clean_before($scratch) {
		return ['type' => 'ssh', 'label' => 'Clean up backup scratch',
			'cmd' => 'rm -f ' . escapeshellarg(self::before_list_path($scratch)),
			'teardown' => true, 'continue_on_error' => true, 'timeout' => 60];
	}

	/**
	 * Shell that puts the path of the file this job produced in $ARCHIVE — the
	 * newest backup artifact that was NOT there when the job started.
	 *
	 * Falls back to plain newest only when the before-list is missing, which
	 * means the snapshot step could not run at all.
	 */
	private static function resolve_new_archive($scratch) {
		return 'ARCHIVE=$(' . self::new_file_pipeline(self::backup_glob(), $scratch, true) . ')';
	}

	/** The same, for the envelope this job's finalize step wrote. */
	private static function resolve_new_sidecar($scratch) {
		return 'UPLOAD_FILE=$(' . self::new_file_pipeline('/backups/*.keys.json', $scratch, false) . ')';
	}

	private static function new_file_pipeline($glob, $scratch, $drop_sidecars) {
		$before = escapeshellarg(self::before_list_path($scratch));
		$pipe = 'ls -1t ' . $glob . ' 2>/dev/null';
		if ($drop_sidecars) {
			// A sidecar is a backup artifact by glob but never the archive, and
			// it is also new — so it would win a newest-first race with the file
			// it describes.
			$pipe .= " | grep -v '\\.keys\\.json\$'";
		}
		$pipe .= ' | { if [ -f ' . $before . ' ]; then grep -vxF -f ' . $before . '; else cat; fi; }';
		return $pipe . ' | head -1';
	}

	/**
	 * Mint the encryption key for this backup, on the node.
	 *
	 * Every run gets its own random key, sealed to the recovery key the node
	 * holds and to the node's own site key, and written beside the archive as a
	 * JSON envelope. Nothing precious is left on the node: the plaintext key
	 * exists only as a 0600 file for the length of the run, and losing the node
	 * loses no ability to read any backup it made.
	 *
	 * No key travels in the step command. The node reads the recovery key it
	 * holds and has proven, which is the only key anyone here can be sure was
	 * verified by someone holding its private half — a key sent from here would
	 * seal just as happily whether or not anybody could ever open the result,
	 * which is what makes a substituted one invisible. `backup_envelope.php mint`
	 * refuses a supplied key outright, so a control plane still passing one is
	 * told rather than obeyed.
	 */
	private static function step_mint_envelope($node, $scratch) {
		// Asked here rather than in each caller: this is the step that needs a
		// key, so every path that mints one is covered by construction.
		self::assert_node_can_be_backed_up($node);

		$scripts      = self::get_scripts_path($node);
		$project_root = dirname(rtrim($node->get('mgn_web_root'), '/'));
		$site_key     = $project_root . '/config/backup_site_key';

		$cmd = 'php ' . escapeshellarg($scripts . '/sysadmin_tools/backup_envelope.php') . ' mint'
			. ' --artifact pending'
			. ' --key-out ' . escapeshellarg(self::envelope_key_path($scratch))
			. ' --sidecar-out ' . escapeshellarg(self::envelope_sidecar_path($scratch))
			. ' --site-key ' . escapeshellarg($site_key);

		return ['type' => 'ssh', 'label' => 'Mint backup encryption key', 'cmd' => $cmd, 'timeout' => 120];
	}

	/**
	 * There is deliberately no way to write a node's recovery key from here, and
	 * no way to hand one to a job.
	 *
	 * That key decides who can open every backup the node makes — the ones the
	 * node takes for itself and the ones this control plane takes of it, which
	 * are the same key now. Its custodian is whoever administers the node, and
	 * possession is proven there, against a challenge that node issued. A control
	 * plane that could write it, or could pass one with a run, would be a control
	 * plane that could quietly become the only party able to read the fleet.
	 *
	 * `set_recovery_key.php --report` is still asked during check_status, because
	 * whether a node holds a proven key decides whether it can be backed up at
	 * all — see assert_node_can_be_backed_up(). It reports and only reports.
	 */

	/**
	 * Refuse to build a backup job for a node that cannot encrypt one.
	 *
	 * The node refuses these runs itself — that is the guard, and it holds
	 * whatever a control plane believes. This is the second thing: failing at
	 * BUILD time puts the reason in front of the operator immediately, and keeps
	 * the fleet schedule from filling the job log with runs that were never going
	 * to work. Never silently downgrade: the alternative to a refusal here is an
	 * unencrypted copy of a whole site on somebody else's shelf.
	 */
	private static function assert_node_can_be_backed_up($node) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryKeyFleet.php'));

		$state = RecoveryKeyFleet::node_state($node);
		if ($state['state'] === 'n/a' || RecoveryKeyFleet::has_own_key($state)) {
			return;
		}
		throw new Exception(
			"Node '" . $node->get('mgn_slug') . "' cannot be backed up. "
			. RecoveryKeyFleet::blocker_summary($state));
	}

	/**
	 * Point the envelope at the archive that was just written, and destroy the
	 * plaintext key.
	 *
	 * The key is shredded whether or not the relabel worked: leaving it behind
	 * would put a usable decryption key next to the thing it decrypts, which is
	 * the one arrangement the whole design exists to avoid. A failed relabel
	 * still fails the step, so the operator sees an archive whose envelope needs
	 * attention rather than one silently missing its key.
	 */
	private static function step_finalize_envelope($node, $scratch) {
		$scripts = self::get_scripts_path($node);
		$tool    = escapeshellarg($scripts . '/sysadmin_tools/backup_envelope.php');
		$key     = escapeshellarg(self::envelope_key_path($scratch));
		$sidecar = escapeshellarg(self::envelope_sidecar_path($scratch));

		$resolve = self::resolve_new_archive($scratch);
		$shred   = '{ shred -u ' . $key . ' 2>/dev/null || rm -f ' . $key . '; }';

		$cmd = $resolve
			. ' && test -n "$ARCHIVE"'
			. ' && php ' . $tool . ' relabel --sidecar ' . $sidecar . ' --artifact "$ARCHIVE" --out "$ARCHIVE.keys.json"'
			. '; RC=$?; ' . $shred . '; exit $RC';

		return ['type' => 'ssh', 'label' => 'Seal the backup key to the archive', 'cmd' => $cmd, 'timeout' => 120];
	}

	/**
	 * The archive shapes a node may hold. One list, because "which files are
	 * backups" was previously spelled out separately at every place that had to
	 * decide, and they drifted.
	 */
	public static function backup_glob() {
		require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));
		return BackupNaming::shell_glob();
	}

	/**
	 * Shell that leaves the decryption key for an archive in KEY_PATH.
	 *
	 * The envelope beside the archive is tried first — that is the key that
	 * provably belongs to this file, and it is what lets a node restore itself
	 * with no operator present. Archives made before envelope keys existed have
	 * no envelope, so the node's old key remains the fallback and those restores
	 * keep working untouched.
	 *
	 * KEY_PATH is resolved here, in the non-sudo shell, and passed absolute:
	 * a $HOME-relative lookup breaks once sudo changes $HOME (B-2).
	 *
	 * @param string $archive_expr shell expression for the archive path, already quoted
	 */
	private static function step_resolve_restore_key($node, $archive_expr) {
		$scripts      = self::get_scripts_path($node);
		$tool         = escapeshellarg($scripts . '/sysadmin_tools/backup_envelope.php');
		$project_root = dirname(rtrim($node->get('mgn_web_root'), '/'));
		$site_key     = escapeshellarg($project_root . '/config/backup_site_key');

		return 'KEY_PATH="$HOME/.joinery_backup_key"'
			. " && SIDECAR={$archive_expr}.keys.json"
			. " && { if [ -f \"\$SIDECAR\" ] && [ -f {$site_key} ] && [ -f {$tool} ]; then"
			. "   UNSEALED=$(mktemp /tmp/jy_restore_key_XXXXXX);"
			. "   if php {$tool} open --sidecar \"\$SIDECAR\" --private {$site_key} --key-out \"\$UNSEALED\" >/dev/null 2>&1; then"
			. '     KEY_PATH="$UNSEALED";'
			. '   else rm -f "$UNSEALED"; fi;'
			. ' fi; }';
	}

	/**
	 * Load the backup target for a node, if configured.
	 * Returns BackupTarget or null.
	 */
	public static function get_target($node) {
		require_once(PathHelper::getIncludePath('data/backup_target_class.php'));

		// A node that names a shelf gets that shelf, and only that shelf. If the
		// named one is gone or switched off, this returns null rather than
		// quietly redirecting the archive somewhere the operator did not choose.
		$target_id = $node->get('mgn_bkt_backup_target_id');
		if ($target_id) {
			try {
				$target = new BackupTarget($target_id, TRUE);
				if ($target->get('bkt_enabled')) {
					return $target;
				}
			} catch (Exception $e) {}
			return null;
		}

		// Nothing named. Everything the run needs — bucket, write-only
		// credential, recovery key — is supplied by this control plane anyway,
		// so the only open question is which shelf; and with exactly one enabled
		// target there is no question to ask. Requiring the answer anyway is how
		// a node ends up silently un-backed-up from the moment it is registered.
		//
		// Two or more, and the choice is real: refuse and let the operator make
		// it, rather than guess which bucket a site's data belongs in.
		$enabled = new MultiBackupTarget(array('enabled' => true, 'deleted' => false));
		$enabled->load();
		$sole = null;
		$count = 0;
		foreach ($enabled as $candidate) {
			$count++;
			if ($count > 1) return null;
			$sole = $candidate;
		}
		return $sole;
	}

	/**
	 * How many enabled shelves this control plane has, for the refusal message
	 * that tells an operator which problem they actually have: none configured,
	 * or several and no choice recorded for this node.
	 */
	private static function enabled_target_count() {
		require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
		$enabled = new MultiBackupTarget(array('enabled' => true, 'deleted' => false));
		$enabled->load();
		$count = 0;
		foreach ($enabled as $ignored) { $count++; }
		return $count;
	}

	/**
	 * Append the upload (and optional local cleanup) step to a steps array if the
	 * node has a cloud backup target configured. Picks the newest backup file,
	 * which is the one the preceding backup step just wrote.
	 */
	private static function append_upload_steps(&$steps, $node, $encrypted, $scratch) {
		$target = self::get_target($node);
		if (!$target) return;

		$delete_local = (bool) $node->get('mgn_delete_local_after_upload');

		$resolve = self::resolve_new_archive($scratch) . ' && UPLOAD_FILE="$ARCHIVE"';
		$steps[] = self::upload_step($node, $target, $resolve, $delete_local);

		// The envelope is what makes the archive readable again, so it has to
		// reach the bucket too — an encrypted archive sitting there alone is
		// indistinguishable from noise. It is resolved separately rather than
		// carried over from the previous step because each step is its own SSH
		// session; the archive upload may also have just deleted its own file,
		// which is why this finds the sidecar directly rather than deriving it
		// from a path that no longer exists.
		if ($encrypted) {
			$steps[] = self::upload_step($node, $target, self::resolve_new_sidecar($scratch), $delete_local);
		}
	}

	/**
	 * Upload one already-existing backup file from the node to its cloud target —
	 * the Backups tab's per-file action, for a backup that is sitting local-only
	 * because its original upload hit a transient provider failure.
	 *
	 * The file lives on the node, so the transfer runs there. Routing it through
	 * the control plane instead would drag the whole archive down and push it back
	 * up again for no reason.
	 *
	 * Never deletes the local copy, whatever the node's delete-after-upload setting
	 * says: an operator asking for an offsite copy of a file they are looking at
	 * did not ask for that file to disappear. Deleting stays an explicit action.
	 */
	public static function build_upload_backup($node, $params = []) {
		$filename = basename(trim((string)($params['filename'] ?? '')));
		if ($filename === '' || $filename === '.' || $filename === '..') {
			throw new Exception('No backup filename given.');
		}
		$target = self::get_target($node);
		if (!$target) {
			throw new Exception("Node '{$node->get('mgn_slug')}' has no enabled cloud backup target.");
		}
		if (self::has_primitive($node, 'upload_backup')) {
			return self::build_upload_backup_primitive($node, $params);
		}
		$resolve = 'UPLOAD_FILE=' . escapeshellarg('/backups/' . $filename);
		return [self::upload_step($node, $target, $resolve, false)];
	}

	/**
	 * Primitive path: the node uploads one of its own backups.
	 *
	 * THE PLANE CANNOT NAME A PATH. It sends a bare filename; the node resolves
	 * it inside its own compiled-in backup directory and refuses anything that
	 * is not a recognised backup artifact. Under the SSH path UPLOAD_FILE was a
	 * full path composed here, so a compromised plane could have named any file
	 * on any node and had it uploaded to a bucket it controls — read-anything-
	 * from-every-node, wearing a backup's clothes. That is not narrowed by this
	 * change, it is unsayable: there is no parameter of the shape.
	 *
	 * The object key is composed on the node from prefix/slug/filename, matching
	 * the REMOTE_KEY the shell built, so existing objects keep their addresses.
	 *
	 * provider and target_name are deliberately NOT sent. backup_run declares
	 * them because the backup engine writes history rows keyed on them; an
	 * upload writes none, so they are out of the vocabulary rather than sent and
	 * ignored — an ignored parameter is a lie the sender believes.
	 */
	public static function build_upload_backup_primitive($node, $params = []) {
		$target = self::get_target($node);
		if (!$target) {
			throw new Exception("Node '{$node->get('mgn_slug')}' has no enabled cloud backup target.");
		}
		$primitive_params = [
			'filename'        => basename(trim((string)($params['filename'] ?? ''))),
			// WHOSE backup, not where it is. This plane takes manager-profile
			// backups, so that is the default; the node maps the name to a
			// directory from its own configured backup base.
			'profile'         => in_array(($params['profile'] ?? ''), ['site', 'manager'], true)
				? $params['profile'] : 'manager',
			'bucket'          => $target->get('bkt_bucket'),
			'path_prefix'     => $target->get('bkt_path_prefix') ?: 'joinery-backups',
			'slug'            => $node->get('mgn_slug'),
			// The placeholder, not the secret. AgentChannelEndpoint substitutes
			// it when the job is handed out, so the credential never rests in
			// the job row. creds_token() prefers the write-only node slot, and
			// upload is the one operation that can use it.
			'credentials_b64' => self::creds_token($target),
		];

		// Send the flag only when it is true — never as false.
		//
		// The node's script refuses an unrecognised key, so a config that always
		// carried the field would fail outright on a node whose core predates it.
		// A node that has not upgraded keeps doing exactly what it always did.
		//
		// It is a BOOLEAN, not a sidecar filename, and that is the whole reason
		// the fence holds: the plane names an archive and asks for its key to
		// travel too; the NODE derives <archive>.keys.json. Letting the plane
		// name the sidecar directly would have reopened "the plane can express a
		// path", which is the one property this primitive exists to remove.
		if (!empty($params['include_envelope'])) {
			$primitive_params['include_envelope'] = true;
		}

		return ['primitive' => 'upload_backup', 'params' => $primitive_params];
	}

	/**
	 * The shared upload step. $resolve_cmd is a shell assignment that puts the
	 * absolute path of the file to upload in UPLOAD_FILE.
	 */
	private static function upload_step($node, $target, $resolve_cmd, $delete_local) {
		require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

		$slug = $node->get('mgn_slug');
		$prefix = $target->get('bkt_path_prefix') ?: 'joinery-backups';
		$bucket = $target->get('bkt_bucket');

		$check = 'test -n "$UPLOAD_FILE" && test -f "$UPLOAD_FILE"';
		$remote_key = "REMOTE_KEY=\"{$prefix}/{$slug}/\$(basename \"\$UPLOAD_FILE\")\"";

		$uploader_script = self::build_node_uploader_script($bucket, $target, 'upload');
		$eof = '__JOINERY_UPLOADER_EOF__';

		// Optional local cleanup is folded into the SAME step as the upload so it
		// deletes exactly the file it just uploaded (one UPLOAD_FILE evaluation).
		// A separate cleanup step re-globs "the newest now" and would delete a
		// backup that landed in between, un-uploaded (P-23). The rm is chained
		// with && ON THE REDIRECT LINE, before the heredoc body: the shell keeps
		// parsing the command list on that line and only then reads the body, so
		// the rm runs iff the upload succeeded. Chaining after the terminator
		// line instead would not parse — the terminator must be the entire line,
		// so the chain is swallowed into the uploader's stdin and the step dies.
		$rm = $delete_local ? " && rm -f \"\$UPLOAD_FILE\"" : '';
		$upload_cmd = "php -- upload \"\$UPLOAD_FILE\" \"\$REMOTE_KEY\" <<'{$eof}'{$rm}\n{$uploader_script}\n{$eof}";

		$cmd = "{$resolve_cmd} && {$check} && {$remote_key} && {$upload_cmd}";

		// No continue_on_error: if upload fails, halt so (a) the local copy — the
		// only surviving one — is not deleted, and (b) the job is marked failed so
		// the failure is visible in the UI instead of silently labeled "completed".
		return [
			'type' => 'ssh',
			'label' => 'Upload backup to ' . $target->get('bkt_name'),
			'cmd' => $cmd,
			// Sized from the uploader's own retry budget rather than a bare 3600, so
			// the agent cannot kill a transfer part-way through a retry. The slack
			// covers process start and feeding the heredoc.
			'timeout' => S3Signer::transfer_budget_seconds() + 300,
		];
	}

	/**
	 * Assemble the standalone PHP uploader script that will be heredoc'd onto
	 * the node. Concatenates S3Signer.php + node_uploader.php + a credentials
	 * block. The result runs under `php -` on the node with no file deps.
	 *
	 * Credentials never persist in the step command: the block reads a
	 * credential token (see creds_token) that the agent (>= 0.4.0; >= 0.4.1 for
	 * node tokens) replaces with the unsealed credentials in memory just before
	 * the step runs (S-8). There is deliberately no inline fallback — a job an
	 * old agent cannot run fails visibly, whereas inlined credentials would
	 * persist in the job row forever.
	 */
	private static function build_node_uploader_script($bucket, $target, $op = 'upload') {
		$signer_path = PathHelper::getIncludePath('includes/S3Signer.php');
		$dispatcher_path = PathHelper::getIncludePath('plugins/server_manager/includes/node_uploader.php');

		$signer = self::strip_php_tags(file_get_contents($signer_path));
		$dispatcher = self::strip_php_tags(file_get_contents($dispatcher_path));

		// Only an upload can run under the write-only node credential. A
		// download needs read and a cloud delete needs delete, which only the
		// main credential has — handing those operations the node key would
		// fail them against a properly scoped bucket key.
		$token = ($op === 'upload')
			? self::creds_token($target)
			: '__SM_CREDS_' . (int)$target->key . '__';
		$creds_block = '$creds = json_decode(base64_decode(' . var_export($token, true) . '), true);' . "\n"
		             . '$bucket = ' . var_export($bucket, true) . ";\n";

		return "<?php\n" . $signer . "\n" . $creds_block . "\n" . $dispatcher;
	}

	/**
	 * The credential placeholder a NODE-bound step carries for this target.
	 *
	 * A target can hold a second, write-only credential (bkt_node_credentials).
	 * When it does, node-bound steps carry __SM_NODE_CREDS_<id>__ and the node
	 * is handed a key that can add objects to the shelf but never delete —
	 * a compromised node then cannot erase the fleet's backups. The main
	 * (delete-capable) credential stays on the control plane for retention and
	 * listings. When no node credential is configured, the main token is
	 * emitted and behaviour is unchanged.
	 *
	 * The choice is made at build time, where the data lives; the agent stays
	 * strict and resolves exactly the slot the token names. A node token built
	 * while the slot was filled fails visibly if the slot is later cleared.
	 */
	private static function creds_token($target) {
		$slot = $target->has_node_credentials() ? '__SM_NODE_CREDS_' : '__SM_CREDS_';
		return $slot . (int)$target->key . '__';
	}

	/**
	 * Strip opening and closing PHP tags so a file body can be concatenated
	 * inside another `<?php ... ?>` block.
	 */
	private static function strip_php_tags($code) {
		$code = preg_replace('/^\s*<\?php\s*/', '', $code);
		$code = preg_replace('/\?>\s*$/', '', $code);
		return $code;
	}

	/**
	 * List backup files on a node. Local only — cloud listings are done
	 * web-server-side via TargetLister when the Backups tab renders.
	 * Dispatches to API or SSH based on has_api().
	 */
	public static function build_list_backups($node) {
		if (self::has_primitive($node, 'list_backups')) {
			return self::build_list_backups_primitive($node);
		}
		if (self::has_api($node, 'list_backups')) {
			return self::build_list_backups_api($node);
		}
		if (self::has_ssh($node)) {
			return self::build_list_backups_ssh($node);
		}
		throw new Exception(
			"Node '{$node->get('mgn_slug')}' cannot run list_backups: "
			. "no API credentials (or health probe failed) and no SSH credentials configured."
		);
	}

	/**
	 * Primitive path: a NAME the node looks up in its own vocabulary. The node
	 * reads its backup directory — the directory and the recognised suffixes are
	 * compiled into the agent, so this plane cannot ask it to enumerate anything
	 * else, which the SSH path's shell glob could be steered into doing.
	 *
	 * Returns the same files[] shape the management API produces, so
	 * JobResultProcessor::process_list_backups reads either without knowing
	 * which transport ran.
	 */
	public static function build_list_backups_primitive($node) {
		return ['primitive' => 'list_backups', 'params' => []];
	}

	public static function build_list_backups_api($node) {
		return [
			['type' => 'api', 'label' => 'List local backups', 'method' => 'GET', 'endpoint' => 'backups/list', 'timeout' => 30],
		];
	}

	public static function build_list_backups_ssh($node) {
		return [
			['type' => 'ssh', 'label' => 'List local backups',
			 'cmd' => "for f in " . self::backup_glob() . "; do "
			        . "[ -f \"\$f\" ] && stat --format='LOCAL|%s|%Y|%n' \"\$f\"; "
			        . "done 2>/dev/null; echo 'LOCAL_LIST_DONE'",
			 'continue_on_error' => true],
		];
	}

	/**
	 * The site name remove_account.sh operates on: the Docker container name for a
	 * containerized node, or the project directory name (the parent of the web root)
	 * for a bare-metal node. Both map to /var/www/html/<site>, the container, the
	 * ${site}_* volumes, the ${site}.conf vhost, and the ${site} database — the
	 * naming convention install.sh established. Derived from node fields only, never
	 * from operator input, so it cannot be steered at a different site.
	 */
	public static function decommission_site_name($node) {
		$container = trim((string)$node->get('mgn_container_name'));
		if ($container !== '') {
			$site = $container;
		} else {
			$web_root = rtrim((string)$node->get('mgn_web_root'), '/');
			$site = $web_root !== '' ? basename(dirname($web_root)) : '';
		}
		if ($site === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $site)) {
			throw new Exception(
				"Cannot decommission node '{$node->get('mgn_slug')}': could not derive a safe site "
				. "name from its container name or web root."
			);
		}
		return $site;
	}

	/**
	 * Permanently remove a site from its host and confirm it is gone.
	 *
	 * Ships the tested remove_account.sh (sysadmin_tools) to the host, runs it with
	 * -y, then re-probes the host and emits DECOMMISSION_VERIFIED only when the
	 * container, its ${site}_* volumes, and the ${site}.conf vhost are all absent.
	 * For a Docker node every step runs on the host (on_host); for bare-metal the
	 * commands run on the node directly. remove_account.sh self-selects docker vs
	 * bare-metal and is idempotent (REMOVE_ACCOUNT_NOTHING when there is nothing left).
	 *
	 * Relays are refused: a relay is not a remove_account.sh-shaped site and is torn
	 * down through the relay flow (rebuild_relay / relay_remove_tenant) instead.
	 */
	public static function build_decommission_node($node, $params = []) {
		if ($node->get('mgn_is_relay')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' is a relay. Decommission relays through the relay "
				. "teardown flow (remove its tenants first), not site removal."
			);
		}
		if (!self::has_ssh($node)) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' has no SSH credentials configured; the host teardown "
				. "cannot run without them."
			);
		}

		$is_docker = (bool)$node->get('mgn_container_name');
		$site      = self::decommission_site_name($node);
		$site_esc  = escapeshellarg($site);

		$transfer_id  = substr(md5(uniqid(mt_rand(), true)), 0, 12);
		$remote_script = "/tmp/joinery_remove_account_{$transfer_id}.sh";
		$remote_esc    = escapeshellarg($remote_script);

		// The control plane's own copy of the remover (this is where the agent runs).
		$local_script = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/remove_account.sh';

		$steps = [];

		// 1. Ship the tested remover to the host. On a Docker host this MUST run at
		//    host scope (docker + apache live there), so the script goes to the host
		//    filesystem and every following step is on_host.
		$steps[] = ['type' => 'scp', 'label' => 'Ship site remover to host',
			'direction' => 'upload', 'local_path' => $local_script, 'remote_path' => $remote_script];

		// 2. Run it. -y skips the interactive prompt; the marker lands in the output.
		$steps[] = ['type' => 'ssh', 'label' => 'Remove the site from the host',
			'on_host' => $is_docker, 'timeout' => 600,
			'cmd' => "bash {$remote_esc} {$site_esc} -y"];

		// 3. Verify gone. This is what the result processor gates the record finalize
		//    on — never trust the run step alone.
		$steps[] = ['type' => 'ssh', 'label' => 'Verify the site is gone',
			'on_host' => $is_docker, 'continue_on_error' => false,
			'cmd' => self::decommission_verify_cmd($site, $is_docker)];

		// 4. Teardown: drop the shipped script. Never fail the job over cleanup.
		$steps[] = ['type' => 'ssh', 'label' => 'Clean up shipped remover',
			'on_host' => $is_docker, 'teardown' => true, 'continue_on_error' => true,
			'cmd' => "rm -f {$remote_esc}"];

		return $steps;
	}

	/**
	 * Shell that re-probes the host and prints DECOMMISSION_VERIFIED only when every
	 * trace of the site is gone, else DECOMMISSION_FAILED_VERIFY and a non-zero exit.
	 */
	private static function decommission_verify_cmd($site, $is_docker) {
		$site_esc = escapeshellarg($site);
		$checks = [];
		if ($is_docker) {
			$checks[] = "if command -v docker >/dev/null 2>&1 && docker ps -a --format '{{.Names}}' | grep -qx {$site_esc}; then GONE=0; echo 'still present: container'; fi";
			$checks[] = "if command -v docker >/dev/null 2>&1 && docker volume ls --format '{{.Name}}' | grep -q \"^{$site}_\"; then GONE=0; echo 'still present: volumes'; fi";
		}
		// The reverse-proxy vhost and project dir live on the host for both topologies.
		$checks[] = "if [ -f /etc/apache2/sites-available/{$site}.conf ]; then GONE=0; echo 'still present: vhost'; fi";
		$checks[] = "if [ -d /var/www/html/{$site} ]; then GONE=0; echo 'still present: web root'; fi";
		// Join with '; ' — each check ends in `fi`, and `fi if` on one line is a
		// bash syntax error (exit 2). `fi; if` is valid.
		return "GONE=1; " . implode('; ', $checks)
		     . "; if [ \"\$GONE\" = 1 ]; then echo DECOMMISSION_VERIFIED; else echo DECOMMISSION_FAILED_VERIFY; exit 1; fi";
	}

	/**
	 * Delete a backup file from local, cloud, or both.
	 * $params: target ('local', 'cloud', 'both'), local_path, cloud_path, filename
	 */
	public static function build_delete_backup($node, $params) {
		if (self::has_primitive($node, 'delete_backup')) {
			return self::build_delete_backup_primitive($node, $params);
		}
		$which = $params['target'] ?? 'local';
		$local_path = $params['local_path'] ?? '';
		$cloud_path = $params['cloud_path'] ?? '';
		$steps = [];

		if (($which === 'local' || $which === 'both') && $local_path) {
			// Backups under /backups are written as root by the backup scripts; on a
			// bare-metal node jobs run as user1, so the rm needs sudo (empty for a
			// Docker node, where the job already runs as root). Without it the rm
			// fails Permission denied while the step still reports done.
			$sudo = self::sudo_prefix($node);
			$steps[] = [
				'type' => 'ssh', 'label' => 'Delete local backup',
				'cmd' => "{$sudo}rm -f " . escapeshellarg($local_path) . " && echo 'LOCAL_DELETE_OK'",
				'continue_on_error' => true,
			];
		}

		if (($which === 'cloud' || $which === 'both') && $cloud_path) {
			$target = self::get_target($node);
			if ($target) {
				$bucket = $target->get('bkt_bucket');

				$uploader_script = self::build_node_uploader_script($bucket, $target, 'delete');
				$eof = '__JOINERY_UPLOADER_EOF__';
				$remote_key = escapeshellarg($cloud_path);
				$cmd = "php -- delete {$remote_key} <<'{$eof}'\n{$uploader_script}\n{$eof}";

				$steps[] = [
					'type' => 'ssh', 'label' => 'Delete cloud backup',
					'cmd' => $cmd,
					'continue_on_error' => true,
				];
			}
		}

		if (empty($steps)) {
			$steps[] = ['type' => 'ssh', 'label' => 'No files to delete', 'cmd' => 'echo "Nothing to delete"'];
		}

		return $steps;
	}

	/**
	 * Primitive path: delete ONE LOCAL backup file on the node.
	 *
	 * Local only, and the omission is the design. The SSH path's cloud branch
	 * shipped the MAIN, delete-capable bucket credential to the node, because a
	 * write-only key cannot delete — while creds_token()'s own docblock says
	 * that credential "stays on the control plane", the whole point of the
	 * write-only node key being that "a compromised node then cannot erase the
	 * fleet's backups". Migrating that branch would have carried a live
	 * contradiction across the boundary and made it look reviewed. So the cloud
	 * object is deleted by the plane, in-process, with S3Signer — which
	 * backup_actions_logic already does — and the node's vocabulary has no way
	 * to name a bucket, a key, or a credential.
	 *
	 * The node also cannot be told a path: it gets a filename and resolves it
	 * inside its compiled-in backup directory.
	 *
	 * Outcome rule, which is NOT the step's continue_on_error: a file already
	 * absent is SUCCESS, because the requested end state holds and deleting the
	 * same backup twice is not an error; any other failure is loud. The old flag
	 * ignored the outcome, so a cloud delete that quietly did nothing looked
	 * exactly like one that worked. Defining the end state is the only kind of
	 * forgiveness that is safe.
	 */
	public static function build_delete_backup_primitive($node, $params) {
		$filename = basename(trim((string)($params['filename'] ?? ($params['local_path'] ?? ''))));
		if ($filename === '' || $filename === '.' || $filename === '..') {
			throw new Exception('No backup filename given.');
		}
		return ['primitive' => 'delete_backup', 'params' => [
			'filename' => $filename,
			'profile'  => in_array(($params['profile'] ?? ''), ['site', 'manager'], true)
				? $params['profile'] : 'manager',
		]];
	}

	/**
	 * Build a local shell command that updates a ManagedNode field in the control plane DB.
	 * Reads DB credentials from the control plane's Globalvars_site.php. Used during the
	 * install_node flow to switch mgn_ssh_user to 'user1' after install.sh server disables
	 * root SSH login.
	 */
	private static function _update_node_ssh_user_cmd($node, $new_user) {
		$node_id = intval($node->key);
		$new_user_esc = escapeshellarg($new_user);
		$cfg = escapeshellarg(PathHelper::getSiteRoot() . '/config/Globalvars_site.php');
		$extract = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed s/^.// | sed s/.$//';
		return "CFG={$cfg} && "
		     . "DB_NAME=\$(grep dbname \$CFG | {$extract}) && "
		     . "DB_USER=\$(grep dbusername \$CFG | {$extract}) && "
		     . "export PGPASSWORD=\$(grep dbpassword \$CFG | {$extract}) && "
		     . "psql -U \"\$DB_USER\" -d \"\$DB_NAME\" -c \"UPDATE mgn_managed_nodes SET mgn_ssh_user = {$new_user_esc} WHERE mgn_id = {$node_id}\" && "
		     . "echo SSH_USER_UPDATED_TO_{$new_user}";
	}

	/**
	 * Run certbot on the node's host to provision a TLS certificate.
	 *
	 * For Docker nodes certbot runs on the host (where Apache reverse-proxy lives);
	 * for bare-metal it runs on the node itself. Called by ProvisionPendingSsl once
	 * DNS resolves to the host IP.
	 *
	 * $params:
	 *   domain      - FQDN to certify (required)
	 *   admin_email - Let's Encrypt notification address (uses --register-unsafely-without-email if absent)
	 */
	/**
	 * Issue or renew the origin certificate for a domain, on the node itself.
	 *
	 * BARE METAL ONLY, AND THIS PLANE IS THE ONLY PARTY THAT CAN ENFORCE THAT.
	 *
	 * On a container node the agent runs inside the container while Apache,
	 * certbot and /etc/letsencrypt live on the host — which is why every certbot
	 * step in the SSH job carried on_host. Dispatched at a container node this
	 * does not simply fail: certbot would install and issue INSIDE the
	 * container, writing /etc/letsencrypt to a filesystem the next rebuild
	 * discards, and spending one of the five certificates per domain per week
	 * Let's Encrypt allows. The failure is invisible, repeats on a timer, and is
	 * recoverable only by waiting out a rate limit.
	 *
	 * The node cannot refuse it on its own. "Am I in a container" has only
	 * heuristic answers (/.dockerenv, /proc/1/cgroup), and a heuristic that
	 * misfires would refuse certificates on legitimate machines. mgn_container_name
	 * is the non-heuristic answer and it lives here, so the gate lives here.
	 *
	 * The domain is the only value that crosses, and it is pattern-bound tightly
	 * on both sides for a reason beyond hygiene: Let's Encrypt allows five FAILED
	 * validations per hostname per hour. A bare IP, localhost or a wildcard
	 * cannot succeed but still spends that budget, so they are refused before a
	 * job exists rather than at the CA.
	 */
	public static function build_provision_certificate($node, $params) {
		if ($node->get('mgn_container_name')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' is a container node, so its certificate is issued on "
				. "the Docker host, not inside the container. Running certbot in the container would "
				. "write /etc/letsencrypt to a filesystem the next rebuild discards and spend one of "
				. "this domain's five certificates per week."
			);
		}
		if (!self::has_primitive($node, 'provision_certificate')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot issue its own certificate: no agent has paired "
				. "with this plane.");
		}
		return self::build_provision_certificate_primitive($node, $params);
	}

	public static function build_provision_certificate_primitive($node, $params) {
		// Lowercased before it crosses: the node's pattern accepts either case,
		// but two cases of one name are two certificate requests against a
		// weekly limit.
		$domain = strtolower(trim((string)($params['domain'] ?? '')));
		if ($domain === '') {
			throw new Exception('Issuing a certificate needs a domain.');
		}
		return ['primitive' => 'provision_certificate', 'params' => ['domain' => $domain]];
	}

	/**
	 * Place the one-time routing-probe token in the node's own webroot.
	 *
	 * The Cloudflare branch of provision_ssl needs to prove that a domain
	 * behind Cloudflare actually proxies to THIS node. The node writes a nonce
	 * this plane minted; this plane then fetches the domain from outside and
	 * compares. The fetch stays here on purpose — a node verifying its own
	 * routing proves nothing.
	 *
	 * The token is the only value that crosses. The old step also carried the
	 * PATH, composed here as mgn_web_root with '/var/www/html/{site}/public_html'
	 * behind a ?: — this plane's belief about the node's layout, with a hardcoded
	 * guess for when the belief was missing. The node has its own webroot and
	 * writes where its own probe view reads.
	 *
	 * Placing over an existing token SUCCEEDS rather than refusing, and the
	 * result says replaced:true when it happened. Refusing would look like the
	 * careful choice and be the wedging one: a probe abandoned between place and
	 * fetch would then block that domain permanently, curable only by someone
	 * with filesystem access to the node — and ProvisionPendingSsl retries this
	 * path, so an orphaned token is likely rather than theoretical. The token has
	 * no secrecy value (see views/sm_ssl_probe.php), so a refusal defends
	 * nothing. Treat replaced:true as a signal worth logging: it means either a
	 * previous probe leaked or two are racing on one node, and both are worth
	 * knowing before someone debugs it as a Cloudflare problem.
	 */
	public static function build_ssl_probe_place($node, $params) {
		if (!self::has_primitive($node, 'ssl_probe_place')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot place an SSL routing probe: no agent has paired "
				. "with this plane.");
		}
		return self::build_ssl_probe_place_primitive($node, $params);
	}

	public static function build_ssl_probe_place_primitive($node, $params) {
		$token = trim((string)($params['token'] ?? ''));
		// Minted here because the party that compares a nonce is the party that
		// must own it. The node checks the same shape independently.
		if (!preg_match('/^sm-ssl-probe-[a-f0-9]{24}$/', $token)) {
			throw new Exception('An SSL routing probe needs a well-formed one-time token.');
		}
		return ['primitive' => 'ssl_probe_place', 'params' => ['token' => $token]];
	}

	/** Mint a probe token in the one shape both ends accept. */
	public static function mint_ssl_probe_token() {
		return 'sm-ssl-probe-' . substr(md5(uniqid(mt_rand(), true)), 0, 24);
	}

	/**
	 * Remove the probe token. NO PARAMETERS — the path is compiled into the
	 * agent, and clearing does not depend on which token is there; a token
	 * parameter would invite a caller to believe it does.
	 *
	 * Clearing when nothing is there is success (cleared:false), so this is safe
	 * in a finally — which is where it belongs, since a failed probe must not
	 * leave a token sitting in a public webroot. A cleanup that can fail for
	 * having nothing to do would mask the error it was cleaning up after.
	 */
	public static function build_ssl_probe_clear($node) {
		if (!self::has_primitive($node, 'ssl_probe_clear')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot clear an SSL routing probe: no agent has paired "
				. "with this plane.");
		}
		return self::build_ssl_probe_clear_primitive($node);
	}

	public static function build_ssl_probe_clear_primitive($node) {
		return ['primitive' => 'ssl_probe_clear', 'params' => []];
	}

	public static function build_provision_ssl($node, $params) {
		$domain   = $params['domain'] ?? '';
		$email    = $params['admin_email'] ?? '';
		$sitename = $node->get('mgn_container_name') ?: $node->get('mgn_slug');

		if (!$domain) {
			throw new Exception("provision_ssl requires a domain.");
		}

		$domain_esc   = escapeshellarg($domain);
		$sitename_esc = escapeshellarg($sitename);
		$email_arg    = $email
			? ' -m ' . escapeshellarg($email)
			: ' --register-unsafely-without-email';
		$is_docker    = (bool)$node->get('mgn_container_name');

		// The site name reaches the remote shell through a variable rather than
		// being interpolated into the path directly. escapeshellarg returns the
		// value WITH its quotes, so placing it inside a double-quoted string
		// makes those quotes literal path characters — the config file is then
		// never found, the patch below never runs, and the step still reports
		// success. Assigning first and expanding as "${SITE}" keeps the value
		// both quoted and correct.
		$site_var = 'SITE=' . escapeshellarg($sitename) . '; ';

		// Bare-metal nodes run jobs as user1 after install; every command that
		// touches /etc, /var/log/letsencrypt, or services needs the sudo prefix
		// (empty for Docker/root nodes).
		$sudo = self::sudo_prefix($node);

		if (self::is_cloudflare_domain($domain)) {
			// Cloudflare-proxied: certbot is skipped (Cloudflare terminates TLS at
			// its edge). But "resolves to Cloudflare" only proves the domain is
			// behind Cloudflare — not that the zone proxies to THIS node. So
			// completion is gated on a routing probe: the node writes a one-time
			// token into the site's webroot, and the control plane fetches it
			// through the domain. The token is only fetchable because the node's
			// front controller serves it via its /sm-ssl-probe.txt route
			// (views/sm_ssl_probe.php) — a webroot file is not otherwise
			// reachable on a Joinery site, so a node whose code predates that
			// route can never pass this probe and needs an upgrade first.
			// A mismatch fails the job before any config is
			// touched — the domain stays pending (ProvisionPendingSsl keeps
			// retrying and exempts this case from its give-up window) and the
			// proxy conf keeps its correct pre-cutover X-Forwarded-Proto until
			// traffic genuinely arrives through Cloudflare.
			$web_root   = rtrim($node->get('mgn_web_root'), '/') ?: '/var/www/html/' . $sitename . '/public_html';
			$token      = 'sm-ssl-probe-' . substr(md5(uniqid(mt_rand(), true)), 0, 24);
			$probe_path = escapeshellarg($web_root . '/sm-ssl-probe.txt');
			$probe_url  = escapeshellarg("http://{$domain}/sm-ssl-probe.txt");

			return [
				['type' => 'ssh', 'label' => 'Place routing probe token in webroot',
				 'cmd' => "echo {$token} | {$sudo}tee {$probe_path} >/dev/null && echo PROBE_PLACED",
				 'timeout' => 30],
				['type' => 'local', 'label' => 'Verify the domain routes to this node',
				 'cmd' => "RESP=\$(curl -fsSL --max-time 15 {$probe_url} 2>/dev/null); "
				        . "if [ \"\$RESP\" = \"{$token}\" ]; then echo CF_ROUTING_VERIFIED; "
				        . "else echo CF_ROUTING_UNVERIFIED; exit 1; fi",
				 'timeout' => 60],
				['type' => 'ssh', 'label' => 'Cloudflare detected — skip certbot, patch proxy config', 'on_host' => $is_docker,
				 'cmd' => $site_var
				          . self::proto_patch_cmd('"/etc/apache2/sites-enabled/${SITE}-proxy.conf"', $sudo, $is_docker)
				          . ' && echo SSL_SKIPPED_CLOUDFLARE',
				 'timeout' => 30],
				['type' => 'ssh', 'label' => 'Remove routing probe token',
				 'cmd' => "{$sudo}rm -f {$probe_path}",
				 'continue_on_error' => true],
			];
		}

		// certbot's Apache plugin copies X-Forwarded-Proto "http" from the HTTP VHost into
		// the SSL VHost it generates — always patch it to "https" after certbot runs.
		// The conf is only guaranteed to exist behind the Docker host proxy; on
		// bare metal there is no proxy vhost, so a missing conf is informational.
		$ssl_patch_cmd = $site_var
		               . self::proto_patch_cmd('"/etc/apache2/sites-enabled/${SITE}-proxy-le-ssl.conf"', $sudo, $is_docker);

		return [
			['type' => 'ssh', 'label' => 'Ensure certbot is installed', 'on_host' => $is_docker,
			 'cmd' => "command -v certbot >/dev/null 2>&1 || {$sudo}apt-get install -y -qq certbot python3-certbot-apache",
			 'timeout' => 120],
			['type' => 'ssh', 'label' => 'Run certbot', 'on_host' => $is_docker,
			 'cmd' => "{$sudo}certbot --apache -d {$domain_esc} --non-interactive --agree-tos{$email_arg}",
			 'timeout' => 300],
			['type' => 'ssh', 'label' => 'Fix X-Forwarded-Proto in SSL VHost', 'on_host' => $is_docker,
			 'cmd' => $ssl_patch_cmd,
			 'timeout' => 30],
			['type' => 'ssh', 'label' => 'Verify certificate', 'on_host' => $is_docker,
			 'cmd' => "{$sudo}test -f /etc/letsencrypt/live/{$domain_esc}/fullchain.pem && echo SSL_CERT_VERIFIED",
			 'continue_on_error' => true],
		];
	}

	/**
	 * Shell fragment that forces X-Forwarded-Proto to "https" in a proxy vhost
	 * and names the outcome in the job output.
	 *
	 * A site is installed with a plain HTTP proxy before DNS cutover, so
	 * manage_domain.sh writes the header as "http" — correct at that moment.
	 * This flips it once TLS is actually terminating in front of the backend,
	 * whether by certbot or at the Cloudflare edge. Getting it wrong means the
	 * application believes every request arrived unencrypted.
	 *
	 * The outcome is reported because the previous form could not tell
	 * "rewrote it", "already correct" and "never found the file" apart: all
	 * three exited zero and printed the same thing. That is how a patch whose
	 * target path had stopped matching went unnoticed — a step that cannot fail
	 * visibly cannot be trusted when it says it succeeded. Each case now names
	 * itself, so JobResultProcessor and a human reading the log see the same
	 * four outcomes.
	 *
	 * @param string $conf_shell_path Shell expression for the config path,
	 *                                already quoted for the remote shell.
	 * @param string $sudo            Sudo prefix ('' or 'sudo ').
	 * @param bool   $required        True where the conf must exist (Docker host
	 *                                proxy): a missing conf then fails the step
	 *                                instead of being reported and skipped.
	 * @return string
	 */
	private static function proto_patch_cmd($conf_shell_path, $sudo = '', $required = false) {
		$http_pattern  = 'X-Forwarded-Proto "http"';
		$https_pattern = 'X-Forwarded-Proto "https"';
		$missing = $required ? 'echo PROTO_CONF_MISSING; exit 1; ' : 'echo PROTO_CONF_MISSING; ';
		return 'CONF=' . $conf_shell_path . '; '
		     . 'if [ ! -f "$CONF" ]; then ' . $missing
		     . 'elif grep -q \'' . $http_pattern . '\' "$CONF"; then '
		     .   $sudo . 'sed -i \'s/' . $http_pattern . '/' . $https_pattern . '/\' "$CONF" '
		     .   '&& ' . $sudo . 'systemctl reload apache2 && echo PROTO_PATCHED; '
		     . 'elif grep -q \'' . $https_pattern . '\' "$CONF"; then echo PROTO_ALREADY_HTTPS; '
		     . 'else echo PROTO_HEADER_ABSENT; fi';
	}

	public static function is_cloudflare_domain($domain) {
		try {
			$ips = DnsResolver::getA($domain);
		} catch (DnsLookupException $e) {
			return false; // DNS resolution failed
		}
		foreach ($ips as $ip) {
			$ip_long = ip2long($ip);
			if ($ip_long === false) {
				continue;
			}
			foreach (self::get_cloudflare_ip_ranges() as $cidr) {
				[$subnet, $bits] = explode('/', $cidr);
				$mask = -1 << (32 - (int)$bits);
				if (($ip_long & $mask) === (ip2long($subnet) & $mask)) {
					return true;
				}
			}
		}
		return false;
	}

	private static function get_cloudflare_ip_ranges() {
		static $ranges = null;
		if ($ranges !== null) {
			return $ranges;
		}
		// Short timeout (this runs inside a scheduled-task tick) and strict CIDR
		// validation: a captive-portal/HTML response must fall through to the
		// baked-in list, not silently reclassify every CF domain as non-CF.
		$ctx = stream_context_create(['http' => ['timeout' => 5]]);
		$fetched = @file_get_contents('https://www.cloudflare.com/ips-v4', false, $ctx);
		if ($fetched !== false) {
			$parsed = array_values(array_filter(array_map('trim', explode("\n", $fetched)), function ($line) {
				return (bool)preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/', $line);
			}));
			if (!empty($parsed)) {
				return $ranges = $parsed;
			}
		}
		return $ranges = [
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
			'103.31.4.0/22',   '141.101.64.0/18', '108.162.192.0/18',
			'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
			'198.41.128.0/17', '162.158.0.0/15',  '104.16.0.0/13',
			'104.24.0.0/14',   '172.64.0.0/13',   '131.0.72.0/22',
		];
	}

	/**
	 * Build steps for one-click node install (fresh or from-backup).
	 *
	 * Target is assumed to be a bare host running an Ubuntu LTS the installer
	 * supports (26.04 or 24.04), with SSH root access.
	 * The flow bootstraps whichever prereqs (Docker or Apache/PHP/Postgres)
	 * are needed based on the admin's choice, then creates the site.
	 *
	 * $params:
	 *   mode           - 'fresh' or 'from_backup'
	 *   sitename       - site directory name (e.g. 'mysite' → /var/www/html/mysite)
	 *   domain         - primary domain (fresh) or source domain (from-backup)
	 *   docker_mode    - 'docker' or 'bare-metal' (required; no auto-detect)
	 *   source_node_id - (from-backup only) source node ID
	 *   backup_source  - (from-backup only) 'new' or 'existing'
	 *   db_backup_path / project_backup_path - (existing backup) remote paths on source
	 */
	/**
	 * Next free published port for a host's Docker containers (base 8080). THE
	 * single allocator — every path that assigns a container port goes through
	 * here so one set of rules applies. Uses MAX(mgn_port)+1 over ALL nodes on
	 * the host — deleted rows included, so a removed-but-still-running
	 * container's port is never handed out again (P-18 collision-safety). Rows
	 * are matched by host string AND host id, because nodes carry one or the
	 * other depending on how they were created. Excludes the node being
	 * installed.
	 */
	public static function next_container_port($host, $host_id = null, $exclude_node_id = 0) {
		$db = DbConnector::get_instance()->get_db_link();
		$where  = "(mgn_host = ?" . ($host_id ? " OR mgn_mgh_host_id = ?" : "") . ") AND mgn_id <> ?";
		$params = $host_id
			? [$host, (int)$host_id, (int)$exclude_node_id]
			: [$host, (int)$exclude_node_id];
		$q = $db->prepare("SELECT COALESCE(MAX(mgn_port), 0) FROM mgn_managed_nodes WHERE {$where}");
		$q->execute($params);
		$max = (int)$q->fetchColumn();
		return $max >= 8080 ? $max + 1 : 8080;
	}

	private static function allocate_container_port($node) {
		return self::next_container_port(
			$node->get('mgn_host'),
			$node->get('mgn_mgh_host_id') ?: null,
			(int)$node->key
		);
	}

	public static function build_install_node($node, $params) {
		$mode      = $params['mode'] ?? 'fresh';
		$sitename  = $params['sitename'] ?? $node->get('mgn_slug');
		$domain    = $params['domain'] ?? '';
		$docker    = $params['docker_mode'] ?? '';
		if ($docker !== 'docker' && $docker !== 'bare-metal') {
			throw new Exception("install_node requires docker_mode = 'docker' or 'bare-metal' (got: " . var_export($docker, true) . ")");
		}

		$transfer_id = substr(md5(uniqid(mt_rand(), true)), 0, 12);
		// Per-job path: teardown (including a stale-recovery replay) must never
		// delete the unpacked installer out from under a concurrent install.
		$remote_install_dir = "/tmp/joinery_install_{$transfer_id}";
		$remote_tools_dir = "{$remote_install_dir}/maintenance_scripts/install_tools";

		// Control plane URL — where the target fetches the Joinery release tarball from.
		// Uses the webDir config setting (our site's own hostname).
		$settings = Globalvars::get_instance();
		$webdir = $settings->get_setting('webDir') ?: $_SERVER['HTTP_HOST'] ?? 'dev.getjoinery.com';
		$release_url = "https://{$webdir}/utils/latest_release";
		$release_url_esc = escapeshellarg($release_url);

		$sitename_esc = escapeshellarg($sitename);
		$domain_esc = escapeshellarg($domain);
		$mode_flag = ($docker === 'docker') ? ' --docker' : ' --bare-metal';
		// P-18: pin the container's published port. Without this $port_arg was
		// empty, so install.sh self-allocated a port the control plane never
		// recorded — mgn_port stayed blank and diverged from reality, and the
		// next container's MAX(mgn_port)+1 allocation collided. Allocate the port
		// here (if not already set by a cloud caller), record it, and pass it so
		// install.sh publishes exactly that port.
		$port_arg = '';
		if ($docker === 'docker') {
			$port = (int)$node->get('mgn_port');
			if (!$port) {
				$port = self::allocate_container_port($node);
				$node->set('mgn_port', $port);
				$node->save();
			}
			$port_arg = ' ' . escapeshellarg((string)$port);
		}

		$steps = [];
		// Teardown steps collect here and go at the tail of the array, after
		// every main step — an un-upgraded agent runs the array sequentially,
		// so tail placement is what keeps it correct.
		$teardown = [];

		// 1. Pre-flight: verify the control plane is serving a release archive
		$steps[] = ['type' => 'local', 'label' => 'Pre-flight: check release archive is available',
			'cmd' => "CODE=\$(curl -sILo /dev/null -w '%{http_code}' {$release_url_esc}) && "
			       . "test \"\$CODE\" = '200' -o \"\$CODE\" = '302' || { echo \"Release URL {$release_url} returned HTTP \$CODE\"; exit 1; } && "
			       . "echo PREFLIGHT_OK"];

		// From-Backup: grab source backups BEFORE installing
		if ($mode === 'from_backup') {
			$source_node_id = intval($params['source_node_id'] ?? 0);
			if (!$source_node_id) {
				throw new Exception('From-Backup mode requires source_node_id.');
			}
			require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
			$source_node = new ManagedNode($source_node_id, TRUE);
			$source_scripts = self::get_scripts_path($source_node);
			$source_creds = self::get_db_credentials_script($source_node);
			$source_web_root = rtrim($source_node->get('mgn_web_root'), '/');
			$source_project = basename(dirname($source_web_root));

			$db_backup_remote = $params['db_backup_path'] ?? '';
			$project_backup_remote = $params['project_backup_path'] ?? '';

			if (($params['backup_source'] ?? 'new') === 'new') {
				$db_backup_remote = "/backups/install_{$transfer_id}.sql.gz";
				$project_backup_remote = "/backups/install_{$transfer_id}_project.tar.gz";

				$source_sudo = self::sudo_prefix($source_node);
				$steps[] = ['type' => 'ssh', 'label' => 'Ensure backup directory on source',
					'node_id' => $source_node_id, 'cmd' => "{$source_sudo}mkdir -p /backups && {$source_sudo}chmod 1777 /backups"];
				$steps[] = ['type' => 'ssh', 'label' => 'Dump source database',
					'node_id' => $source_node_id,
					'cmd' => "{$source_creds} && umask 077 && pg_dump --no-owner --no-acl -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > {$db_backup_remote}",
					'timeout' => 3600];
				$steps[] = ['type' => 'ssh', 'label' => 'Archive source project files',
					'node_id' => $source_node_id,
					'cmd' => "bash {$source_scripts}/sysadmin_tools/backup_project.sh {$source_project} --non-interactive --plaintext --output-dir /backups "
					       . "&& NEW_BK=\$(ls -t /backups/{$source_project}*.tar.gz 2>/dev/null | head -1) "
					       . "&& test -n \"\$NEW_BK\" && mv \"\$NEW_BK\" {$project_backup_remote}",
					'timeout' => 3600];
			} else {
				if (!$db_backup_remote || !$project_backup_remote) {
					throw new Exception('From-Backup with existing backup requires db_backup_path and project_backup_path.');
				}
			}

			$local_db_backup = "/tmp/install_{$transfer_id}.sql.gz";
			$local_project_backup = "/tmp/install_{$transfer_id}_project.tar.gz";

			// Docker source: files are inside the container; copy them to /tmp/ on the host
			// so that SCP (which reads from the host filesystem) can transfer them.
			$source_container = $source_node->get('mgn_container_name');
			$scp_db_remote  = $db_backup_remote;
			$scp_prj_remote = $project_backup_remote;
			if ($source_container) {
				$sc   = escapeshellarg($source_container);
				$db_r = escapeshellarg($db_backup_remote);
				$pr_r = escapeshellarg($project_backup_remote);
				// Stage to /tmp/ on the host (always writable by root)
				$scp_db_remote  = $local_db_backup;
				$scp_prj_remote = $local_project_backup;
				$db_host = escapeshellarg($local_db_backup);
				$pr_host = escapeshellarg($local_project_backup);
				$steps[] = ['type' => 'ssh', 'label' => 'Copy DB dump from container to host',
					'node_id' => $source_node_id, 'on_host' => true,
					'cmd' => "docker cp {$sc}:{$db_r} {$db_host}"];
				$steps[] = ['type' => 'ssh', 'label' => 'Copy project archive from container to host',
					'node_id' => $source_node_id, 'on_host' => true,
					'cmd' => "docker cp {$sc}:{$pr_r} {$pr_host}"];
			}

			$steps[] = ['type' => 'scp', 'label' => 'Fetch DB backup to control plane',
				'node_id' => $source_node_id, 'direction' => 'download',
				'remote_path' => $scp_db_remote, 'local_path' => $local_db_backup];
			$steps[] = ['type' => 'scp', 'label' => 'Fetch project backup to control plane',
				'node_id' => $source_node_id, 'direction' => 'download',
				'remote_path' => $scp_prj_remote, 'local_path' => $local_project_backup];
		}

		// 2. Fetch the Joinery release tarball on the target and extract it.
		// Target needs curl (usually present on Ubuntu; install if missing).
		// All commands sudo-wrapped so they work whether the agent connects as root or user1.
		$steps[] = ['type' => 'ssh', 'label' => 'Ensure curl is installed',
			'on_host' => true,
			'cmd' => "command -v curl >/dev/null || sudo bash -c 'apt-get update -qq && apt-get install -y -qq curl'"];

		$steps[] = ['type' => 'ssh', 'label' => 'Download and extract Joinery release',
			'on_host' => true,
			'cmd' => "sudo rm -rf {$remote_install_dir} && sudo mkdir -p {$remote_install_dir} && "
			       . "curl -sL {$release_url_esc} | sudo tar xz -C {$remote_install_dir} && "
			       . "sudo test -f {$remote_tools_dir}/install.sh && sudo chmod +x {$remote_tools_dir}/*.sh && "
			       . "echo RELEASE_EXTRACTED",
			'timeout' => 600];

		// 3. Install prereqs (Docker or bare-metal server setup)
		if ($docker === 'docker') {
			// install.sh docker is idempotent — short-circuits if Docker is already installed.
			// Docker subcommand does NOT harden SSH, so root access stays intact.
			$steps[] = ['type' => 'ssh', 'label' => 'Install Docker (if missing)',
				'on_host' => true,
				'cmd' => "cd {$remote_tools_dir} && ./install.sh -y -q docker",
				'timeout' => 1800];
		} else {
			// Bare-metal: install.sh server runs `PermitRootLogin no` + restarts sshd, locking
			// out our root-keyed agent. Before it runs, pre-stage user1 with root's authorized
			// keys and NOPASSWD sudo so the agent can keep talking to the target. After, we
			// switch the ManagedNode's ssh_user to user1 so subsequent steps (and future jobs)
			// connect as user1.
			// All commands prefixed with sudo — works as root (no-op) or as user1 (NOPASSWD sudo
			// already present from a prior successful run). On retry where we're already user1,
			// this step is effectively a no-op re-sync.
			$steps[] = ['type' => 'ssh', 'label' => 'Pre-stage user1 for managed access',
				'on_host' => true,
				'cmd' => "set -e; "
				       . "sudo test -s /root/.ssh/authorized_keys || { echo 'FATAL: /root/.ssh/authorized_keys is empty or missing — cannot pre-stage user1 safely. Aborting before install.sh server locks out root SSH.'; exit 1; }; "
				       . "id user1 >/dev/null 2>&1 || sudo useradd -m -s /bin/bash user1; "
				       . "sudo install -d -m 700 -o user1 -g user1 /home/user1/.ssh; "
				       . "sudo touch /home/user1/.ssh/authorized_keys; "
				       . "sudo bash -c 'cat /root/.ssh/authorized_keys >> /home/user1/.ssh/authorized_keys && sort -u /home/user1/.ssh/authorized_keys -o /home/user1/.ssh/authorized_keys'; "
				       . "sudo chmod 600 /home/user1/.ssh/authorized_keys; "
				       . "sudo chown user1:user1 /home/user1/.ssh/authorized_keys; "
				       . "echo 'user1 ALL=(ALL:ALL) NOPASSWD: ALL' | sudo tee /etc/sudoers.d/user1 >/dev/null; "
				       . "sudo chmod 440 /etc/sudoers.d/user1; "
				       . "echo USER1_READY"];

			// Switch the agent to user1 BEFORE running install.sh server (which disables
			// root login). The SSH pool re-creates its connection using the updated user
			// on the next step since install.sh server also restarts sshd.
			$steps[] = ['type' => 'local', 'label' => 'Switch SSH user to user1',
				'cmd' => self::_update_node_ssh_user_cmd($node, 'user1')];

			// Now as user1 (via sudo, NOPASSWD). Only run server setup if prereqs missing —
			// install.sh server resets the postgres role password and would break other sites.
			//
			// The password file at /root/.joinery_postgres_password is required by the site
			// creation step below (it uses --password-file to ensure the site's DB password
			// matches the postgres role password — _site_init.sh uses the site password as
			// PGPASSWORD for createdb -U postgres). If prereqs are already installed but the
			// file doesn't exist (host was set up manually), harvest the password from an
			// existing site's Globalvars_site.php.
			$steps[] = ['type' => 'ssh', 'label' => 'Install Apache/PHP/Postgres (if missing)',
				'on_host' => true,
				'cmd' => "cd {$remote_tools_dir} && "
				       . "if command -v apache2 >/dev/null && command -v psql >/dev/null && command -v php >/dev/null; then "
				       .   "echo 'PREREQS_ALREADY_INSTALLED — skipping install.sh server'; "
				       .   "if ! sudo test -s /root/.joinery_postgres_password; then "
				       .     "echo 'Harvesting postgres password from an existing site config...'; "
				       .     "EXISTING_CFG=\$(sudo find /var/www/html -maxdepth 3 -name Globalvars_site.php -path '*/config/*' 2>/dev/null | head -1); "
				       .     "if [ -z \"\$EXISTING_CFG\" ]; then "
				       .       "echo 'FATAL: prereqs installed but no postgres password available — cannot determine DB password. Manually create /root/.joinery_postgres_password containing the postgres role password.'; exit 1; "
				       .     "fi; "
				       .     "PW=\$(sudo grep dbpassword \"\$EXISTING_CFG\" | head -1 | cut -d\\; -f1 | cut -d= -f2 | tr -d ' ' | sed \"s/^.//;s/.$//\"); "
				       .     "test -n \"\$PW\" || { echo 'FATAL: could not extract dbpassword from existing config'; exit 1; }; "
				       .     "echo \"\$PW\" | sudo tee /root/.joinery_postgres_password >/dev/null && sudo chmod 600 /root/.joinery_postgres_password; "
				       .     "echo 'Password harvested from existing site config'; "
				       .   "fi; "
				       . "else "
				       .   "export POSTGRES_PASSWORD=\$(openssl rand -base64 18 | tr -d '/+=' | head -c 24) && "
				       .   "echo 'Auto-generated postgres password (recorded in /root/.joinery_postgres_password on target):' && "
				       .   "echo \"\$POSTGRES_PASSWORD\" | sudo tee /root/.joinery_postgres_password >/dev/null && sudo chmod 600 /root/.joinery_postgres_password && "
				       .   "sudo -E ./install.sh -y -q server; "
				       . "fi",
				'timeout' => 3600];
		}

		// 4. Create the site.
		// --no-ssl is always passed (DNS typically not yet pointing here).
		// Prefix with sudo so it works whether connecting as root or user1.
		//
		// Bare-metal: _site_init.sh uses $PASSWORD as PGPASSWORD for the `postgres` role when
		// running createdb, so the site's DB password MUST match the postgres role password
		// set by install.sh server (stored in /root/.joinery_postgres_password). Without this,
		// createdb auth-fails and the schema load skips silently. Passing `-` (auto-generate)
		// produces a mismatch — use --password-file instead.
		//
		// Docker mode runs Postgres inside the container with a fresh password, so `-` is fine.
		if ($docker === 'docker') {
			$pass_arg = ' -';
		} else {
			$pass_arg = ' --password-file=/root/.joinery_postgres_password';
		}
		// A site this management node is installing comes up running its agent.
		// That is the one case where "should this machine run an agent?" is
		// already answered — someone asked this plane to build and manage it —
		// and it saves a root moment per node during the fleet rollout. It still
		// enrolls nothing: joining is a request the operator approves here.
		$install_cmd = "cd {$remote_tools_dir} && sudo ./install.sh -y -q site{$mode_flag} {$sitename_esc}{$pass_arg} {$domain_esc}{$port_arg} --no-ssl --enable-agent";
		$steps[] = ['type' => 'ssh', 'label' => 'Create the site',
			'on_host' => true, 'cmd' => $install_cmd, 'timeout' => 3600];

		// Docker mode: report the port the container ACTUALLY publishes. install.sh
		// auto-picks a different port when the pinned one is busy, so the ledger is
		// only trustworthy if it records ground truth read back from Docker —
		// JobResultProcessor parses CONTAINER_PORT= and corrects mgn_port.
		if ($docker === 'docker') {
			$steps[] = ['type' => 'ssh', 'label' => 'Report published container port', 'on_host' => true,
				'cmd' => "echo \"CONTAINER_PORT=\$(docker port {$sitename_esc} 80/tcp | head -1 | awk -F: '{print \$NF}')\"",
				'continue_on_error' => true];
		}

		// Docker mode: record the container name in the control plane DB so future jobs
		// (backups, restores, status checks) correctly use docker exec to reach the site.
		if ($docker === 'docker') {
			$sitename_db_esc = str_replace("'", "''", $sitename);
			$node_id_int = intval($node->key);
			$cfg_esc = escapeshellarg(PathHelper::getSiteRoot() . '/config/Globalvars_site.php');
			$extr = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed s/^.// | sed s/.$//';
			$update_cmd = "CFG={$cfg_esc} && "
			            . "DB_NAME=\$(grep dbname \$CFG | {$extr}) && "
			            . "DB_USER=\$(grep dbusername \$CFG | {$extr}) && "
			            . "export PGPASSWORD=\$(grep dbpassword \$CFG | {$extr}) && "
			            . "psql -U \"\$DB_USER\" -d \"\$DB_NAME\" -c \"UPDATE mgn_managed_nodes SET mgn_container_name = '{$sitename_db_esc}' WHERE mgn_id = {$node_id_int}\" && "
			            . "echo CONTAINER_NAME_UPDATED";
			$steps[] = ['type' => 'local', 'label' => 'Record container name in control plane',
				'cmd' => $update_cmd];
		}

		// Docker mode: set up an HTTP reverse proxy on the host so port 80 serves the site.
		// In docker mode, maintenance_scripts/ is baked into the container image — not on
		// the host — so we use the still-extracted copy under the per-job install dir
		// (removed only at teardown). manage_domain.sh auto-installs Apache + mod_proxy if
		// missing, writes {sitename}-proxy.conf, and reloads. Idempotent. SSL stays a
		// separate admin action after DNS cutover.
		// Skip for localhost / bare IP — a ServerName-based proxy needs a routable domain.
		$is_ip = (bool)preg_match('/^\d+\.\d+\.\d+\.\d+$/', $domain);
		if ($docker === 'docker' && $domain !== '' && $domain !== 'localhost' && !$is_ip) {
			$manage_domain = "{$remote_install_dir}/maintenance_scripts/sysadmin_tools/manage_domain.sh";
			$steps[] = ['type' => 'ssh', 'label' => 'Set up HTTP reverse proxy',
				'on_host' => true,
				'cmd' => "sudo bash {$manage_domain} set {$sitename_esc} {$domain_esc} --no-ssl",
				'timeout' => 300];
		}

		// From-Backup: restore DB + files onto freshly-installed site
		if ($mode === 'from_backup') {
			$target_config = "/var/www/html/{$sitename}/config/Globalvars_site.php";
			$remote_db_dump = "/tmp/joinery_restore_{$transfer_id}.sql.gz";
			$remote_project_tar = "/tmp/joinery_restore_{$transfer_id}_project.tar.gz";
			$local_db_backup = "/tmp/install_{$transfer_id}.sql.gz";
			$local_project_backup = "/tmp/install_{$transfer_id}_project.tar.gz";

			// SCP uploads to target: for Docker, files land on HOST /tmp/
			$steps[] = ['type' => 'scp', 'label' => 'Upload DB backup to target',
				'direction' => 'upload', 'local_path' => $local_db_backup, 'remote_path' => $remote_db_dump];
			$steps[] = ['type' => 'scp', 'label' => 'Upload project backup to target',
				'direction' => 'upload', 'local_path' => $local_project_backup, 'remote_path' => $remote_project_tar];

			// Docker target: SCP landed on host /tmp/ but restore runs inside the container —
			// copy files from host into the container so the restore steps can access them.
			// Use $docker/$sitename here (not mgn_container_name — it's blank until the post-install update step runs).
			$is_docker_install = ($docker === 'docker');
			$restore_on_host   = !$is_docker_install; // bare-metal: on_host=true; Docker: run inside container
			if ($is_docker_install) {
				$tc   = escapeshellarg($sitename);   // container name = sitename for new Docker installs
				$db_r = escapeshellarg($remote_db_dump);
				$pr_r = escapeshellarg($remote_project_tar);
				$steps[] = ['type' => 'ssh', 'label' => 'Copy DB dump into container',
					'on_host' => true,
					'cmd' => "docker cp {$remote_db_dump} {$tc}:{$db_r}"];
				$steps[] = ['type' => 'ssh', 'label' => 'Copy project backup into container',
					'on_host' => true,
					'cmd' => "docker cp {$remote_project_tar} {$tc}:{$pr_r}"];
			}

			$extract = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed s/^.// | sed s/.$//';
			$creds = "DB_NAME=\$(grep dbname {$target_config} | {$extract}) && "
			       . "DB_USER=\$(grep dbusername {$target_config} | {$extract}) && "
			       . "export PGPASSWORD=\$(grep dbpassword {$target_config} | {$extract})";

			$sudo = self::sudo_prefix($node);
			$step_base = $restore_on_host ? ['on_host' => true] : [];

			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Auto-backup fresh DB before restore',
				'cmd' => "{$sudo}mkdir -p /backups && {$sudo}chmod 1777 /backups && {$creds} && umask 077 && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_install_restore_\$(date +%Y%m%d_%H%M%S).sql.gz",
				'timeout' => 3600]);

			// Same restore engine as every other path: verify-before-destroy,
			// schema replace, ON_ERROR_STOP. Handles a plaintext clone dump or an
			// .enc archive identically (audit finding 9 — an .enc dump used to die
			// at gunzip -t after the fresh site was already installed).
			$restore_engine = "/var/www/html/{$sitename}/maintenance_scripts/sysadmin_tools/restore_database.sh";
			$db_dump_arg = escapeshellarg($remote_db_dump);
			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Restore source database',
				'cmd' => "{$creds} && KEY_PATH=\"\$HOME/.joinery_backup_key\" && bash " . escapeshellarg($restore_engine) . " \"\$DB_NAME\" {$db_dump_arg} --non-interactive --db-user \"\$DB_USER\" --key-file \"\$KEY_PATH\"",
				'timeout' => 3600]);

			// backup_project.sh archives are two levels deep:
			//   {backup_name}/project_files/{public_html,uploads,config,...}
			// with the archive's own metadata (apache_config/, backup_info.txt, the
			// .sql dump) as siblings of project_files. Both levels have to come off,
			// and only the project_files subtree may be extracted — stripping one
			// level buries the whole site a directory deep under project_files/ and
			// scatters the metadata across the site root. The site still comes up
			// (the fresh install ran first, the DB restore succeeded), so the failure
			// is silent: every uploaded file is simply absent from where the database
			// says it is. The extract must also be allowed to fail the job, since a
			// clone that lost its files is not a usable clone.
			//
			// config/backup_site_key is excluded for a different reason than
			// Globalvars_site.php. It is the keypair that identifies THIS machine
			// as a recipient of its own backups, and it is supposed to be per-site
			// and disposable. A clone that inherits its source's key makes two
			// sites share one identity: the envelope's site recipient stops saying
			// which machine made a backup, and one machine's key opens the other's
			// archives. backup_envelope.php mints a fresh one on first use, so
			// leaving it absent is the correct state, not a gap.
			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Extract project files',
				'cmd' => "tar xzf {$remote_project_tar} -C /var/www/html/{$sitename} --strip-components=2 --wildcards"
					. " --exclude='config/Globalvars_site.php' --exclude='config/backup_site_key' '*/project_files/*'",
				'timeout' => 3600]);

			// Prove the files actually landed. Every regular file the archive carries
			// must now exist at the site root; the two files the target keeps or
			// mints for itself are excluded on both sides. A leftover project_files/
			// directory is checked by name because it is the exact signature of an
			// extract at the wrong depth.
			$site_dir_esc = escapeshellarg("/var/www/html/{$sitename}");
			$tar_esc      = escapeshellarg($remote_project_tar);
			$verify_cmd =
				  "SITE={$site_dir_esc}; TAR={$tar_esc}; "
				. "if [ -d \"\$SITE/project_files\" ]; then "
				.   "echo 'VERIFY FAILED: project_files/ present in the site root - archive extracted at the wrong depth'; exit 1; fi; "
				. "LIST=\$(tar tzf \"\$TAR\" | sed -n 's|^[^/]*/project_files/||p' | grep -v '/\$' "
				.   "| grep -v '^config/Globalvars_site\\.php\$' | grep -v '^config/backup_site_key\$'); "
				. "TOTAL=\$(printf '%s\\n' \"\$LIST\" | grep -c . || true); "
				. "MISSING=\$(printf '%s\\n' \"\$LIST\" | while IFS= read -r f; do "
				.   "if [ -n \"\$f\" ] && [ ! -e \"\$SITE/\$f\" ]; then printf '%s\\n' \"\$f\"; fi; done); "
				. "MCOUNT=\$(printf '%s\\n' \"\$MISSING\" | grep -c . || true); "
				. "echo \"restore verify: \$TOTAL files expected, \$MCOUNT missing\"; "
				. "if [ \"\$MCOUNT\" -gt 0 ]; then echo 'missing (first 20):'; printf '%s\\n' \"\$MISSING\" | head -20; exit 1; fi; "
				. "echo 'restore verify: OK'";
			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Verify restored files',
				'cmd' => $verify_cmd,
				'timeout' => 3600]);

			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Fix permissions',
				'cmd' => "bash /var/www/html/{$sitename}/maintenance_scripts/install_tools/fix_permissions.sh {$sitename}",
				'continue_on_error' => true]);

			// The clone now carries the SOURCE site's identity — its domain in the
			// restored database, its idea of what machine it is on — while sitting
			// on this one. Reconciliation settles that, and it is the same step
			// every other restore path ends with, so a clone and a rebuild cannot
			// drift apart in what they fix up.
			//
			// It is a gate, not a fixup: it refuses if the restored database will
			// not open with this machine's credentials, which is the failure that
			// otherwise shows up as SQLSTATE[08006] on every page of a clone that
			// reported success.
			$target_domain = parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '';
			if ($target_domain) {
				$reconcile = "/var/www/html/{$sitename}/maintenance_scripts/sysadmin_tools/reconcile_site.sh";
				$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Reconcile the clone to this machine',
					'cmd' => 'bash ' . escapeshellarg($reconcile)
					       . ' ' . escapeshellarg($sitename)
					       . ' --domain ' . escapeshellarg($target_domain),
					'timeout' => 600]);

				// A container's public name is served by the HOST's proxy, which is
				// outside the container and so outside everything above.
				if ($is_docker_install && $target_domain !== 'localhost'
				    && !preg_match('/^\d+\.\d+\.\d+\.\d+$/', $target_domain)) {
					$manage_domain_host = "{$remote_install_dir}/maintenance_scripts/sysadmin_tools/manage_domain.sh";
					$steps[] = ['type' => 'ssh', 'label' => 'Publish the clone domain on the host',
						'on_host' => true,
						'cmd' => 'sudo bash ' . escapeshellarg($manage_domain_host) . ' set '
						       . escapeshellarg($sitename) . ' ' . escapeshellarg($target_domain) . ' --no-ssl',
						'timeout' => 300, 'continue_on_error' => true];
				}
			}

			$teardown[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Clean up restore artifacts on target',
				'cmd' => "rm -f {$remote_db_dump} {$remote_project_tar}",
				'teardown' => true, 'timeout' => 120, 'continue_on_error' => true]);

			// For Docker: also clean up the staged files on the host
			if ($is_docker_install) {
				$teardown[] = ['type' => 'ssh', 'label' => 'Clean up restore artifacts on host',
					'on_host' => true,
					'cmd' => "rm -f {$remote_db_dump} {$remote_project_tar}",
					'teardown' => true, 'timeout' => 120, 'continue_on_error' => true];
			}

			$teardown[] = ['type' => 'local', 'label' => 'Clean up backup files on control plane',
				'cmd' => "rm -f {$local_db_backup} {$local_project_backup}",
				'teardown' => true, 'timeout' => 120, 'continue_on_error' => true];

			// The dump and the project archive were written on the source too. A
			// Docker source holds two copies - one inside the container where the
			// dump was written, one on the host where docker cp staged it for SCP -
			// and both are the full site, so a few copies fill the disk of a shared
			// host serving live sites. Only the backup_source === 'new' variant may
			// touch /backups/ on the source: when an EXISTING backup was named,
			// those paths are the user's real backup files, not job scratch.
			if (($params['backup_source'] ?? 'new') === 'new') {
				$teardown[] = ['type' => 'ssh', 'label' => 'Clean up backup files on source',
					'node_id' => $source_node_id,
					'cmd' => 'rm -f ' . escapeshellarg($db_backup_remote) . ' ' . escapeshellarg($project_backup_remote),
					'teardown' => true, 'timeout' => 120, 'continue_on_error' => true];

				if ($source_container) {
					$teardown[] = ['type' => 'ssh', 'label' => 'Clean up staged backup files on source host',
						'node_id' => $source_node_id, 'on_host' => true,
						'cmd' => 'rm -f ' . escapeshellarg($local_db_backup) . ' ' . escapeshellarg($local_project_backup),
						'teardown' => true, 'timeout' => 120, 'continue_on_error' => true];
				}
			}
		}

		// Cleanup installer on target (release tarball was piped through tar; no local file)
		$teardown[] = ['type' => 'ssh', 'label' => 'Clean up installer on target',
			'on_host' => true,
			'cmd' => "sudo rm -rf {$remote_install_dir}",
			'teardown' => true, 'timeout' => 120, 'continue_on_error' => true];

		// Post-install verification. Globalvars_site.php is chmod 640 root:www-data so
		// user1 needs sudo to test-read it.
		// Docker mode: config lives inside the container — exec test through docker.
		if ($docker === 'docker') {
			$verify_cmd = "echo INSTALL_SUCCESS && hostname && "
			            . "sudo docker exec {$sitename} test -f /var/www/html/{$sitename}/config/Globalvars_site.php && echo CONFIG_OK";
		} else {
			$verify_cmd = "echo INSTALL_SUCCESS && hostname && "
			            . "sudo test -f /var/www/html/{$sitename}/config/Globalvars_site.php && echo CONFIG_OK";
		}
		$steps[] = ['type' => 'ssh', 'label' => 'Verify install',
			'on_host' => true,
			'cmd' => $verify_cmd];

		// A new site is NOT given a recovery key here. It is covered from birth by
		// this control plane's own backups, which carry their key with each run;
		// the site's own key is its operator's to set up, on its own Backups page,
		// with the possession ceremony that makes it trustworthy. Handing one over
		// at install time would put this control plane's key in a slot whose
		// custodian is somebody else.
		return array_merge($steps, $teardown);
	}

	/**
	 * The bash script that runs on the remote host to discover Joinery instances.
	 * Outputs structured lines: JOINERY_INSTANCE|type|name|web_root|domain|db_name|version
	 */
	private static function get_discover_script() {
		return <<<'BASH'
#!/bin/bash
found=0

# Check Docker containers
containers=$(docker ps --format "{{.Names}}" 2>/dev/null)
if [ -n "$containers" ]; then
  for c in $containers; do
    config=$(docker exec "$c" find /var/www/html -maxdepth 3 -name "Globalvars_site.php" -path "*/config/*" 2>/dev/null | head -1)
    if [ -n "$config" ]; then
      web_root=$(echo "$config" | sed 's|/config/Globalvars_site.php||')/public_html
      web_dir=$(docker exec "$c" grep "webDir" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
      db_name=$(docker exec "$c" grep "dbname" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
      db_user=$(docker exec "$c" grep "dbusername" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
      db_pass=$(docker exec "$c" grep "dbpassword" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
      version=""
      if [ -n "$db_name" ]; then
        version=$(docker exec "$c" bash -c "PGPASSWORD='$db_pass' psql -U '${db_user:-postgres}' -d '$db_name' -tAc \"SELECT stg_value FROM stg_settings WHERE stg_name = 'system_version'\"" 2>/dev/null)
      fi
      echo "JOINERY_INSTANCE|docker|$c|$web_root|$web_dir|$db_name|$version"
      found=$((found+1))
    fi
  done
fi

# Check bare metal if no containers found
if [ "$found" = "0" ]; then
  for config in $(find /var/www/html -maxdepth 3 -name "Globalvars_site.php" -path "*/config/*" 2>/dev/null); do
    site_dir=$(dirname $(dirname "$config"))
    web_root="$site_dir/public_html"
    web_dir=$(grep "webDir" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
    db_name=$(grep "dbname" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
    db_user=$(grep "dbusername" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
    db_pass=$(grep "dbpassword" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
    dir_name=$(basename "$site_dir")
    version=""
    if [ -n "$db_name" ]; then
      version=$(PGPASSWORD="$db_pass" psql -U "${db_user:-postgres}" -d "$db_name" -tAc "SELECT stg_value FROM stg_settings WHERE stg_name = 'system_version'" 2>/dev/null)
    fi
    echo "JOINERY_INSTANCE|bare|$dir_name|$web_root|$web_dir|$db_name|$version"
    found=$((found+1))
  done
fi

if [ "$found" = "0" ]; then
  echo "NO_JOINERY_FOUND"
fi
echo "SCAN_COMPLETE|$found"
BASH;
	}

	/**
	 * Build the steps that stand up a HARDENED INGEST RELAY on a fresh VPS
	 * (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 6). Reuses the
	 * job/agent machinery: delivers the shipped provisioning/ files (the sealer Go
	 * source + provision_relay.sh) as a tarball, runs the installer, optionally
	 * peers the main box's WireGuard key, and emits the markers
	 * process_provision_relay parses.
	 *
	 * $params: mail_hostname (required), main_wg_public_key (optional — the main
	 * box's WG public key to add as a [Peer] so Joinery can dial out).
	 */
	public static function build_provision_relay($node, $params) {
		$mail_hostname = trim((string)($params['mail_hostname'] ?? ''));
		if ($mail_hostname === '' || strpos($mail_hostname, '.') === false) {
			throw new Exception("provision_relay requires a FQDN mail_hostname (e.g. mx.example.com).");
		}
		$main_wg_pubkey = trim((string)($params['main_wg_public_key'] ?? ''));

		// Fleet shards are skeleton-only: the OPERATOR's deployment is not a
		// tenant of the shard — tenants are added later by relay_add_tenant jobs
		// as they enroll (specs/mailbox_relay_shared_fleet.md).
		$skeleton_only = !empty($params['skeleton_only']);

		// Relay outbound mode (specs/mailbox_relay_inbound_only.md): the relay is
		// inbound-only by default; the tunnel submission listener (smarthost) is
		// opened only when the deployment has opted in. Pass the opt-in through to
		// provision_relay.sh as a positional arg so a rebuild preserves the choice.
		$smarthost = (strtolower(trim((string)Globalvars::get_instance()->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost');
		$smarthost_arg = $smarthost ? ' smarthost' : '';

		// The relay pull key's public half: installed on the relay so the web
		// user's steady-state connections (spool pull, map push, health battery)
		// authenticate with their own identity instead of this node's admin key,
		// which the web user cannot read. Generated by provision_relay_main.sh.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
		$pull_pubkey = trim((string)@file_get_contents(RelaySsh::pullKeyPath() . '.pub'));
		if ($pull_pubkey === '' && !$skeleton_only) {
			throw new Exception("Relay pull key missing - run 'sudo bash plugins/mailbox/provisioning/provision_relay_main.sh' on the main box first.");
		}

		$transfer_id = substr(md5(uniqid(mt_rand(), true)), 0, 12);
		$provisioning_dir = PathHelper::getIncludePath('plugins/mailbox/provisioning');
		$local_tarball = "/tmp/joinery-relay-{$transfer_id}.tgz";
		$remote_tarball = "/tmp/joinery-relay-{$transfer_id}.tgz";
		$remote_dir = "/tmp/joinery-relay-{$transfer_id}";

		$hostname_esc = escapeshellarg($mail_hostname);
		$provisioning_esc = escapeshellarg($provisioning_dir);
		$tarball_esc = escapeshellarg($local_tarball);
		$remote_tarball_esc = escapeshellarg($remote_tarball);
		$remote_dir_esc = escapeshellarg($remote_dir);

		$steps = [];

		// 1. Pre-flight on the control plane: the sealer source + installer exist,
		//    packaged into one tarball for delivery.
		$steps[] = ['type' => 'local', 'label' => 'Pre-flight: package relay provisioning files',
			'cmd' => "test -f {$provisioning_esc}/provision_relay.sh && "
			       . "ls {$provisioning_esc}/bin/relay-sealer-* >/dev/null 2>&1 && "
			       . "tar czf {$tarball_esc} -C {$provisioning_esc} bin provision_relay.sh && echo PREFLIGHT_OK"];

		// 2. Deliver the tarball to the relay.
		$steps[] = ['type' => 'scp', 'label' => 'Upload relay provisioning bundle',
			'direction' => 'upload', 'local_path' => $local_tarball, 'remote_path' => $remote_tarball];

		// 3. Extract and run the installer (builds the sealer + merge unit, wires
		//    Postfix + milters + WireGuard + firewall — the SHARD SKELETON; the
		//    relay stack is tenancy-native). Idempotent; safe to re-run.
		$steps[] = ['type' => 'ssh', 'label' => 'Run provision_relay.sh', 'on_host' => true,
			'cmd' => "sudo rm -rf {$remote_dir_esc} && sudo mkdir -p {$remote_dir_esc} && "
			       . "sudo tar xzf {$remote_tarball_esc} -C {$remote_dir_esc} && "
			       . "cd {$remote_dir_esc} && sudo bash provision_relay.sh {$hostname_esc}{$smarthost_arg}",
			'timeout' => 1800];

		// 4. Add THIS deployment as tenant 'main' — a self-hosted relay is a
		//    fleet of one (specs/mailbox_relay_shared_fleet.md). One operation
		//    creates the spool subdirectory, the restricted pull account (forced
		//    command: the tenant shell — the steady-state ssh/rsync consumers
		//    hold no root-class login), the WireGuard peer at the first-tenant
		//    address, and the '*' domain allowlist (no other tenant exists to
		//    claim against on a self-hosted box). Skipped for fleet shards,
		//    whose tenants enroll through the fleet service.
		if (!$skeleton_only) {
			$pull_pub_esc = escapeshellarg($pull_pubkey);
			$wg_arg = ($main_wg_pubkey !== '') ? ' --wg-pubkey ' . escapeshellarg($main_wg_pubkey) : '';
			$steps[] = ['type' => 'ssh', 'label' => 'Add main tenant (fleet of one)', 'on_host' => true,
				'cmd' => "cd {$remote_dir_esc} && sudo bash provision_relay.sh add-tenant main "
				       . "--pull-pubkey {$pull_pub_esc} --tunnel-ip 10.99.0.2 --domains '*'{$wg_arg}"];
		}

		// 5. Verify + emit the markers the result processor parses.
		$steps[] = ['type' => 'ssh', 'label' => 'Verify relay + report WireGuard details', 'on_host' => true,
			'cmd' => "echo RELAY_WG_PUBKEY=$(sudo cat /etc/wireguard/relay_public.key 2>/dev/null); "
			       . "echo RELAY_PUBLIC_IP=$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}'); "
			       // The operator is not a tenant of their own shards, so there is no
			       // joinery-ping credential to ask a shard its version with. It comes
			       // back through root SSH here instead, and an absent marker reads as
			       // unknown rather than as up to date.
			       . "echo RELAY_VERSION=$(sudo cat /opt/joinery-relay/version 2>/dev/null); "
			       . "sudo postfix status >/dev/null 2>&1 && echo PROVISION_RELAY_SUCCESS"];

		return $steps;
	}

	/**
	 * Rebuild an existing relay in place (or on a fresh VPS): the same provisioning
	 * run again. Incident response is click → wait → update DNS, and it is also
	 * schedulable (per-shard on the published fleet cadence) so persistence on the
	 * relay has a shelf life.
	 *
	 * NO ACCEPTED MESSAGE IS EVER LOST (specs/mailbox_relay_shared_fleet.md
	 * § Fleet operations): an accepted, sealed, spooled item not yet pulled
	 * exists only on the relay's disk — its sender got a 250 and will never
	 * resend — and the Postfix deferred queue can hold outbound forwards for
	 * days. The rebuild therefore brackets the provisioning run:
	 *
	 *   1. Close port 25 and flush the queue for a bounded window, so in-flight
	 *      accept→seal work drains and retryable forwards get one more attempt.
	 *   2. Copy the per-tenant spools and any still-deferred queue files aside.
	 *   3. Re-run the full provisioning (the wipe's security purpose is killing
	 *      implanted code).
	 *   4. VALIDATING RESTORE of the spool: only files matching the strict
	 *      <id>.seal / <id>.meta pattern, into the owning tenant's directory,
	 *      correct ownership, no exec bits — data survives, persistence doesn't.
	 *      Deferred queue files return to the Postfix queue the same run.
	 *   5. Reopen port 25.
	 *
	 * Mail not yet accepted waits at senders' MTAs through the window. A relay
	 * compromised before rebuild could have poisoned spool entries regardless;
	 * carrying them across adds no surface the pull path did not already face.
	 * Self-hosted rebuilds use the same sequence; N=1 is the same job.
	 */
	public static function build_rebuild_relay($node, $params) {
		$carry_dir = '/var/lib/joinery-relay-rebuild-carry';
		$carry_esc = escapeshellarg($carry_dir);

		$steps = [];

		// 1. Stop accepting + bounded flush (senders queue; nothing is refused
		//    permanently — 25/tcp closed reads as connection failure = retry).
		$steps[] = ['type' => 'ssh', 'label' => 'Close port 25 + flush the queue (bounded)', 'on_host' => true,
			'cmd' => "sudo ufw deny 25/tcp >/dev/null 2>&1; "
			       . "sudo postqueue -f 2>/dev/null; sleep 60; sudo postqueue -f 2>/dev/null; sleep 60; "
			       . "sudo postfix stop 2>/dev/null || true; echo QUEUE_FLUSHED",
			'timeout' => 600];

		// 2. Carry the spool + still-deferred queue files aside (root-owned dir
		//    outside every service path).
		$steps[] = ['type' => 'ssh', 'label' => 'Carry spool + deferred queue aside', 'on_host' => true,
			'cmd' => "sudo rm -rf {$carry_esc} && sudo mkdir -p {$carry_esc}/spool {$carry_esc}/queue && "
			       . "sudo cp -a /var/spool/joinery-relay/. {$carry_esc}/spool/ 2>/dev/null || true; "
			       . "for q in deferred active incoming; do sudo cp -a /var/spool/postfix/\$q {$carry_esc}/queue/ 2>/dev/null || true; done; "
			       . "sudo cp -a /opt/joinery-relay/tenants {$carry_esc}/tenants 2>/dev/null || true; "
			       . "echo CARRY_SAVED"];

		// 3. The full provisioning run (skeleton + add-tenant for this
		//    deployment) — identical to a fresh provision.
		foreach (self::build_provision_relay($node, $params) as $step) {
			$steps[] = $step;
		}

		// 4. Validating restore: spool entries only (strict name pattern, no
		//    exec bits, owner = sealer, group = the tenant whose directory they
		//    sit in), then the deferred queue files, then reopen 25.
		$steps[] = ['type' => 'ssh', 'label' => 'Validating restore of spool + queue; reopen 25', 'on_host' => true,
			'cmd' => "sudo bash -c '"
			       . "shopt -s nullglob; "
			       . "for tdir in " . $carry_dir . "/spool/*/; do "
			       .   "slug=\$(basename \"\$tdir\"); "
			       .   "[[ \"\$slug\" =~ ^[a-z0-9][a-z0-9-]{0,27}\$ ]] || continue; "
			       .   "dest=/var/spool/joinery-relay/\$slug; "
			       .   "[[ -d \"\$dest\" ]] || continue; "
			       .   "for f in \"\$tdir\"*.seal \"\$tdir\"*.meta; do "
			       .     "b=\$(basename \"\$f\"); "
			       .     "[[ \"\$b\" =~ ^[A-Za-z0-9._-]+\\.(seal|meta)\$ ]] || continue; "
			       .     "[[ -f \"\$f\" && ! -L \"\$f\" ]] || continue; "
			       .     "install -m 0640 -o joinery-relay -g jt-\$slug \"\$f\" \"\$dest/\$b\"; "
			       .   "done; "
			       . "done; "
			       . "for q in deferred active incoming; do "
			       .   "if [[ -d " . $carry_dir . "/queue/\$q ]]; then "
			       .     "cp -a " . $carry_dir . "/queue/\$q/. /var/spool/postfix/\$q/ 2>/dev/null || true; "
			       .   "fi; "
			       . "done; "
			       . "postsuper -r ALL 2>/dev/null || true; "
			       // Tenant registry (allowlists, limits, tunnel allocations,
			       // last-accepted fragments): restore anything the re-provision
			       // did not recreate, then re-merge so the maps serve again.
			       . "cp -an " . $carry_dir . "/tenants/. /opt/joinery-relay/tenants/ 2>/dev/null || true; "
			       . "/opt/joinery-relay/relay-sealer merge-maps >/dev/null 2>&1 || true; "
			       . "rm -rf " . $carry_dir . "; "
			       . "ufw allow 25/tcp >/dev/null 2>&1; "
			       . "postfix start 2>/dev/null || postfix reload 2>/dev/null || true; "
			       . "echo SPOOL_RESTORED'"];

		return $steps;
	}

	/**
	 * Add a tenant to a relay/shard: one box-level operation
	 * (provision_relay.sh add-tenant — spool subdirectory, restricted pull
	 * account, WireGuard peer, allowlist, limits). What a tenant IS is the
	 * mailbox plugin's business; this builder just runs the operation with the
	 * parameters it was handed.
	 *
	 * $params: slug (required), pull_pubkey (required), wg_pubkey, tunnel_ip,
	 * domains (csv | '*' | '-'), forward_limit, spool_max_mib, spool_max_entries.
	 */
	public static function build_relay_add_tenant($node, $params) {
		$slug = strtolower(trim((string)($params['slug'] ?? '')));
		if (!preg_match('/^[a-z0-9][a-z0-9-]{0,27}$/', $slug)) {
			throw new Exception("relay_add_tenant requires a valid slug.");
		}
		$pull_pubkey = trim((string)($params['pull_pubkey'] ?? ''));
		if ($pull_pubkey === '') {
			throw new Exception("relay_add_tenant requires the tenant's pull_pubkey.");
		}

		$cmd = "sudo bash /opt/joinery-relay/provision_relay.sh add-tenant " . escapeshellarg($slug)
		     . " --pull-pubkey " . escapeshellarg($pull_pubkey);
		$wg_pubkey = trim((string)($params['wg_pubkey'] ?? ''));
		if ($wg_pubkey !== '') {
			$cmd .= " --wg-pubkey " . escapeshellarg($wg_pubkey);
		}
		$tunnel_ip = trim((string)($params['tunnel_ip'] ?? ''));
		if ($tunnel_ip !== '') {
			$cmd .= " --tunnel-ip " . escapeshellarg($tunnel_ip);
		}
		$domains = trim((string)($params['domains'] ?? '*'));
		$cmd .= " --domains " . escapeshellarg($domains === '' ? '-' : $domains);
		foreach (array('forward_limit' => '--forward-limit', 'spool_max_mib' => '--spool-max-mib',
			'spool_max_entries' => '--spool-max-entries') as $key => $flag) {
			if (isset($params[$key])) {
				$cmd .= " {$flag} " . intval($params[$key]);
			}
		}

		return [
			['type' => 'ssh', 'label' => "Add relay tenant {$slug}", 'on_host' => true,
				'cmd' => $cmd, 'timeout' => 300],
		];
	}

	/**
	 * Replace a tenant's domain allowlist on its relay/shard and re-merge
	 * (provision_relay.sh set-domains). $params: slug, domains (csv | '*' |
	 * '-' to empty the list — suspension).
	 */
	public static function build_relay_set_domains($node, $params) {
		$slug = strtolower(trim((string)($params['slug'] ?? '')));
		if (!preg_match('/^[a-z0-9][a-z0-9-]{0,27}$/', $slug)) {
			throw new Exception("relay_set_domains requires a valid slug.");
		}
		$domains = trim((string)($params['domains'] ?? ''));
		if ($domains === '') {
			$domains = '-';
		}
		return [
			['type' => 'ssh', 'label' => "Set relay tenant {$slug} domains", 'on_host' => true,
				'cmd' => "sudo bash /opt/joinery-relay/provision_relay.sh set-domains "
				       . escapeshellarg($slug) . " " . escapeshellarg($domains), 'timeout' => 300],
		];
	}

	/**
	 * Remove a tenant from a relay/shard (provision_relay.sh remove-tenant).
	 * Refused by the script while the tenant's spool holds undrained mail
	 * unless force is passed. $params: slug, force (bool).
	 */
	public static function build_relay_remove_tenant($node, $params) {
		$slug = strtolower(trim((string)($params['slug'] ?? '')));
		if (!preg_match('/^[a-z0-9][a-z0-9-]{0,27}$/', $slug)) {
			throw new Exception("relay_remove_tenant requires a valid slug.");
		}
		$force = !empty($params['force']) ? ' --force' : '';
		return [
			['type' => 'ssh', 'label' => "Remove relay tenant {$slug}", 'on_host' => true,
				'cmd' => "sudo bash /opt/joinery-relay/provision_relay.sh remove-tenant "
				       . escapeshellarg($slug) . $force, 'timeout' => 300],
		];
	}
}
?>
