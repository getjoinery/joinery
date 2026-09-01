<?php
/**
 * JobCommandBuilder - Generates step arrays for each job type.
 *
 * All job-type intelligence lives here. The Go agent is a generic executor
 * that reads these steps and runs them in order.
 *
 * @version 1.50 - decommission crosses to the channel: build_decommission_node routes ONE
 *                 destructive primitive (decommission_site) to the Docker HOST's own paired
 *                 agent, or refuses naming the fix; the scp/ssh teardown bodies are deleted.
 *                 The victim approves its own removal on its own admin (docker_host_agent.md)
 * @version 1.49 - the last SSH fallbacks behind primitive-routed ops are gone: upload_backup and
 *                 delete_backup route primitive or refuse, and upload_step /
 *                 build_node_uploader_script / strip_php_tags go with them
 * @version 1.48 - the dead SSH surface comes out: build_backup_database and build_backup_project
 *                 (superseded by backup_run), the SSH step composition behind the three restore
 *                 builders (every restore travels as a primitive), build_list_backups_ssh, and the
 *                 helpers only those paths used
 * @version 1.47 - managed domains cross to the channel: build_managed_domain_prepare and
 *                 build_managed_domain_notice, both primitive-only with no SSH sibling
 * @version 1.46 - check_status routes primitive -> api -> probe; build_check_status_ssh deleted.
 *                 A machine with no agent and no site reports itself over HTTP instead of
 *                 being read over a shell
 * @version 1.45 - the three restore operations gain primitive builders so the plane can address
 *                 what the agent ships, and a destructive-class gate keeps them OFF the primitive
 *                 transport: node_can_dispatch_destructive() is false until a node-verified
 *                 approval verifier exists, so live restore stays on SSH
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
 * @version 1.27 - a node that names no backup target falls back to the management
 *                 node's sole enabled one, so a registered node is backed up
 *                 without per-node setup; two or more and it still refuses
 * @version 1.26 - paths and the site URL are cast before parsing, so an unset one
 *                 raises nothing on PHP 8.5
 * @version 1.25 - node-bound backup steps carry __SM_NODE_CREDS_<id>__ when the target holds a
 *                 write-only node credential, so a node is handed a key that can add to the shelf
 *                 but never erase it; the main (delete-capable) credential then stays on the
 *                 management node. With no node credential configured, the main token is emitted
 *                 and nothing changes.
 * @version 1.26 - every restore path reconciles the site to the machine it lands on and proves it
 *                 (identity + served-over-HTTPS gates); the Apache choice is gone, the domain is a
 *                 required parameter, and build_restore_chain() makes the fleet's actual backups —
 *                 incremental chains — restorable from the dashboard for the first time
 * @version 1.24 - build_backup_run(): this management node's own backup of a node, run by the node's
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
 * @version 1.20 - backup key escrow runs as a management-node step (step_escrow_backup_key) instead of
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
		if (method_exists(static::class, "build_{$operation}_probe")) {
			$transports[] = 'probe';
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
		'apply_update'     => '1.10.0',
		// The restore family. These floors are live now: the destructive gate
		// below opens for a node whose agent can ask its own operator for
		// approval, and that verifier landed in 1.13.0. A 1.12.0 agent ships the
		// restore vocabulary and refuses every job in it at a compiled ceiling,
		// so routing to it would trade a transport for a guaranteed refusal —
		// during a restore, which is the worst moment on the list to find that
		// out.
		'restore_database' => '1.13.0',
		'restore_project'  => '1.13.0',
		'restore_chain'    => '1.13.0',
		// Bringing a backup back off the shelf, which is what makes any of the
		// above have something to restore FROM.
		'download_backup'  => '1.13.0',
		'stage_chain'      => '1.13.0',
		// The managed-domain pair, new in 1.14.0 and therefore newer than every
		// agent in the field. A node that reports its vocabulary is answered by
		// that report; these floors are for the agents at 1.10.0 and earlier
		// that never send one, where routing to a vocabulary this plane cannot
		// confirm buys a guaranteed refusal.
		'managed_domain_prepare' => '1.14.0',
		'managed_domain_notice'  => '1.14.0',
		// Removing a container site from its host, dispatched to the HOST's
		// own machine-posture agent. Its own floor rather than the restore
		// family's: the restore verifier (1.13.0) must not vouch for a
		// primitive that only exists from 1.15.0.
		'decommission_site' => '1.15.0',
	];

	/**
	 * The platform release that carries the decommission approval panel
	 * (DecommissionApprovalPanel and its settings rows). A victim below this
	 * cannot RENDER the consent it would be asked for, so dispatch refuses
	 * with the fix in the message rather than staging a ceremony into rows no
	 * page reads.
	 */
	const DECOMMISSION_PANEL_MIN_CORE_VERSION = '0.8.357';

	/**
	 * Operations the agent registers as ClassDestructive.
	 *
	 * Kept as a plane-side list rather than read from the agent because it is a
	 * ROUTING decision, and routing has to be answerable on a management node
	 * with no agent source beside it. The agent's own class declaration is the
	 * enforcement — a destructive primitive is refused at its compiled ceiling
	 * whatever this plane believes — so the two are not a duplicated authority:
	 * this list decides what the plane will not ask for, and the agent decides
	 * what it will not do.
	 */
	const DESTRUCTIVE_PRIMITIVES = ['restore_database', 'restore_project', 'restore_chain', 'decommission_site'];

	/**
	 * May this node be sent a destructive primitive job?
	 *
	 * TRUE FOR A NODE WHOSE AGENT CAN ASK ITS OWN OPERATOR, and that is the
	 * whole of what this method decides. It is not permission to destroy
	 * anything: this plane grants none, holds none, and is not in the approval
	 * path at all — not as a gate, and not as a relay.
	 *
	 * WHAT ACTUALLY AUTHORIZES A RESTORE happens entirely on the node. Its agent
	 * claims the job, runs nothing, composes its OWN statement of what it would
	 * do from its own records, seals a one-time challenge to the backup recovery
	 * public key it already holds, and stages it for the node's own site. An
	 * operator there opens the challenge with their recovery key and answers.
	 * Only then does the restore run. This plane sees neither half, and the
	 * restore vocabulary declares no parameter through which an approval answer
	 * could travel — so relaying one is impossible by wire format rather than by
	 * builder care (specs/restore_dispatch_approval_mechanism.md).
	 *
	 * So a compromised management node can dispatch a restore job and can do
	 * nothing whatsoever to get it approved.
	 *
	 * THE VERSION FLOOR IS CHECKED HERE, and it has to be here rather than left
	 * to has_primitive()'s general path. That path returns early for a node that
	 * REPORTS its vocabulary — the node's own list wins, which is right for
	 * every other operation — so a node running 1.12.0 reports
	 * `restore_database,restore_project,restore_chain` (it ships them) and is
	 * routed at, while its agent refuses the whole class at a compiled ceiling
	 * because the approval verifier only landed in 1.13.0. Shipping the
	 * vocabulary and being able to AUTHORIZE a job in it are different facts,
	 * and only this method knows the difference.
	 *
	 * What that cost, before the floor moved here: a mid-rollout node collected
	 * the agent's opaque "this node does not accept destructive primitives"
	 * instead of this plane's "apply an update first, there is no SSH route
	 * left" — the worse of the two messages, at the worse of the two moments.
	 *
	 * @param ManagedNode $node The node a job would be dispatched to.
	 */
	public static function node_can_dispatch_destructive($node, $operation = 'restore_database') {
		if (!self::has_agent_channel($node)) {
			return false;
		}
		// Every destructive operation carries its OWN floor. Falling back to
		// another operation's — the restore family's 1.13.0 was the tempting
		// one — is how a verifier quietly vouches for work it predates, so an
		// operation with no declared floor fails closed: adding a destructive
		// primitive means adding its PRIMITIVE_MIN_AGENT_VERSION row, and a
		// forgotten row is a refusal at build time, not an inherited pass.
		$min = self::PRIMITIVE_MIN_AGENT_VERSION[$operation] ?? null;
		if ($min === null) {
			return false;
		}
		$version = trim((string)$node->get('mgn_agent_version'));
		return $version !== '' && version_compare($version, $min, '>=');
	}

	public static function has_primitive($node, $operation) {
		if (!self::has_agent_channel($node)
				|| !method_exists(static::class, "build_{$operation}_primitive")) {
			return false;
		}

		// The destructive gate comes FIRST, ahead of every capability question
		// below it. Shipping the primitive is not the same as being able to
		// authorize a job in it: a node that ships restore_database and would
		// refuse it at a compiled ceiling is still a node this plane must not
		// dispatch restore to. The version and vocabulary checks below then
		// apply as they do to everything else.
		if (in_array($operation, self::DESTRUCTIVE_PRIMITIVES, true)
				&& !self::node_can_dispatch_destructive($node, $operation)) {
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
		if (in_array('probe', $op_transports) && NodeHealthProbe::has_target($node)) return true;
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
			return "Operation '{$operation}' has no implementation on the management node.";
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
		if (in_array('probe', $op_transports) && !NodeHealthProbe::has_target($node)) {
			$parts[] = 'there is no health check URL or port to probe';
		}
		if (in_array('ssh', $op_transports) && !self::has_ssh($node)) {
			$parts[] = 'SSH is not configured';
		}
		if (!in_array('api', $op_transports)) {
			$parts[] = 'no API implementation exists';
		}
		// Only worth saying where SSH could still be the answer. An operation
		// that reaches nodes by primitive or probe has no SSH implementation by
		// design, and reporting that as a shortfall points the reader at a
		// transport that is being retired.
		if (!in_array('ssh', $op_transports)
				&& !in_array('primitive', $op_transports)
				&& !in_array('probe', $op_transports)) {
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

		// A stored secret a human must act on (a dead operator credential, or a
		// destructive re-mint awaiting acknowledgement) shows amber on the node's
		// badge. The fix happens ON the node — the management node never holds the
		// node's keys — so this is notify-and-link, never remote-fix.
		$sealed = isset($status_data['sealed_secrets']) && is_array($status_data['sealed_secrets'])
			? $status_data['sealed_secrets'] : array();
		$sealed_attention = (int)($sealed['dead_operator'] ?? 0) + (int)($sealed['dead_needs_ack'] ?? 0);

		if ((isset($status_data['disk_usage_percent']) && $status_data['disk_usage_percent'] > 80) ||
			(isset($status_data['load_1m']) && $status_data['load_1m'] > 5) ||
			$sealed_attention > 0 ||
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
		if (NodeHealthProbe::has_target($node)) {
			return self::build_check_status_probe($node);
		}
		throw new Exception(
			"Node '{$node->get('mgn_slug')}' cannot run check_status: it has no agent, no API "
			. "credentials, and no health check URL or port for this plane to probe."
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
	 * Probe path: the node is asked nothing and runs nothing. This plane reads
	 * what the machine already publishes about itself over HTTP, or establishes
	 * that it answers on its port.
	 *
	 * For the machines that will never carry an agent and host no site - the DNS
	 * servers and the mail relay - this is the whole of check_status. Those boxes
	 * report their own disk and memory in their /health document, taken by the
	 * same syscalls the agent's check_status primitive uses, so the figures are
	 * published by the machine rather than extracted from it.
	 *
	 * Returned as an envelope, like the primitive path. NodeHealthProbe runs it,
	 * and it runs to completion inside the request that asked for it.
	 */
	public static function build_check_status_probe($node) {
		return ['probe' => 'check_status'];
	}


	/**
	 * A management node's own backup of a node — the manager profile.
	 *
	 * The node does the work. It builds the archive, extends the chain, seals the
	 * envelope, uploads and sweeps its own local copies, all through the same
	 * BackupRunner that takes its own backups. Routing any of that through the
	 * management node would drag whole archives down and push them back up for no
	 * reason, and would put the management node in the path of every restore.
	 *
	 * What the management node contributes is the two things the node has no other
	 * way to reach: the bucket and the credential. Both arrive with the run and
	 * leave with it.
	 *
	 * What opens the archive is not among them. The node seals to the recovery
	 * key it holds and has proven, read on the node, and refuses a run that
	 * arrives carrying key material. Supplying one from here would be the
	 * convenient arrangement — one key opening any node's backups — and is
	 * exactly why it is not built: sealing to a public key always appears to
	 * succeed, so a management node that had been tampered with could re-seal
	 * every node's next backup, databases and mail included, to a key of its
	 * choosing, and nothing on any machine would look wrong until someone tried
	 * to restore. The price is paid knowingly: recovering a node's backup needs
	 * that node's recovery key, and no key here opens the fleet.
	 *
	 * The credential is a WRITE-ONLY one. The node can add objects to the shelf
	 * and cannot remove any, so a compromised node cannot erase the fleet's
	 * backups — which is why manager retention runs on the management node instead
	 * (see FleetBackupRetention) and why this job never asks the node to prune.
	 *
	 * Config travels on stdin, not argv: argv is world-readable on the box for
	 * the life of the process and one of these fields is a credential.
	 */
	public static function build_backup_run($node, $params = []) {
		// Config problems refuse first, paired or not: 'no shelf' or 'no
		// verified key' is the reason an operator can act on, and it must not
		// be shadowed by a generic pairing message.
		self::backup_run_config($node, $params);
		if (!self::has_primitive($node, 'backup_run')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot run a backup: that needs a paired agent. "
				. 'Pair the node.');
		}
		return self::build_backup_run_primitive($node, $params);
	}

	/**
	 * The config as declared parameters. The node validates every field
	 * against its compiled-in spec, composes the engine's config itself, and
	 * hands it to run_backup.php on stdin — so the credential never reaches
	 * argv, and nothing this plane sends is executed as syntax.
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
				? 'this management node has no enabled backup target at all'
				: ($enabled_count > 1
					? "this management node has {$enabled_count} enabled backup targets and this node names "
						. 'none, so which one to use is a real choice — assign one to the node'
					: 'the backup target this node names is missing or switched off');
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' has nowhere to put a backup this management node takes: "
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
	 * Restore a database from one of the node's own backup archives.
	 *
	 * Primitive only: the job travels to the node's agent, which asks its own
	 * operator for approval. A node that cannot take one is refused with the
	 * fix named — there is no other transport.
	 *
	 * Params:
	 *   filename - archive name; the node resolves it in its own backup directory
	 */
	public static function build_restore_database($node, $params) {
		if (self::has_primitive($node, 'restore_database')) {
			return self::build_restore_database_primitive($node, $params);
		}
		self::refuse_dead_restore_transport($node, 'restore_database');
	}

	/**
	 * The one artifact name a restore primitive may carry, checked here.
	 *
	 * A NAME, NEVER A PATH — the same fence upload_backup and delete_backup
	 * stand behind, and the reason the primitive transport is worth crossing to
	 * at all. Under SSH this plane composed an absolute path and the node ran
	 * it, which is read-and-overwrite-anything wearing a restore's clothes. The
	 * node resolves this name inside its own compiled-in backup directory, so
	 * the shape of the dangerous request no longer exists.
	 *
	 * It REFUSES a path rather than quietly taking the basename. A caller that
	 * sends /backups/x.sql.gz means something by the directory part, and the
	 * two readings — "that file" and "whatever x.sql.gz is on this node" —
	 * are not reliably the same file. Silently discarding it would restore the
	 * second while the operator believed the first.
	 */
	private static function restore_artifact_name($params) {
		$name = trim((string)($params['filename'] ?? ''));
		if ($name === '') {
			throw new Exception('No backup filename given to restore.');
		}
		// The agent's own backupFileName pattern and length, deliberately
		// duplicated rather than approximated. A plane rule that is merely
		// STRICTER would still be wrong: it would refuse jobs the node would
		// have run, from a message that blames the wrong side. Requiring an
		// alphanumeric first character is what excludes "." and ".." along with
		// every hidden file; excluding the separators is what excludes a path.
		if (strlen($name) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
			throw new Exception(
				"A restore names a backup, not a path: '{$name}' cannot be sent to a node. "
				. 'The node resolves the name inside its own backup directory.');
		}
		return $name;
	}

	/**
	 * Whose backup directory a restore looks in. REQUIRED by the agent for
	 * database and project restores, and not defaulted there for a reason worth
	 * repeating here: the two profiles keep separate directories, an archive of
	 * the same name exists in both often enough, and a guess would eventually
	 * restore the management node's own backup over a site.
	 *
	 * This plane's own backups are manager-profile, which is why that is the
	 * answer when a caller does not say — the same default upload_backup and
	 * delete_backup already send.
	 */
	private static function restore_profile($params) {
		return in_array(($params['profile'] ?? ''), ['site', 'manager'], true)
			? $params['profile'] : 'manager';
	}

	/**
	 * The project a restore lands in: one directory name under the web root's
	 * parent, bound to the agent's own project_name pattern.
	 */
	private static function restore_project_name($node) {
		$web_root = rtrim((string)$node->get('mgn_web_root'), '/');
		if ($web_root === '') {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' has no recorded web root, so there is no project to restore into.");
		}
		$name = basename(dirname($web_root));
		if (strlen($name) > 128 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
			throw new Exception("Cannot derive a usable project name from web root '{$web_root}'.");
		}
		return $name;
	}

	/**
	 * Primitive path: the node restores one of its own database backups.
	 *
	 * NOT REACHABLE IN THIS BUILD. restore_database is ClassDestructive, and
	 * node_can_dispatch_destructive() is false, so has_primitive() is false and
	 * build_restore_database() above always takes the SSH path. This exists so
	 * the plane can address what the agent ships (primitive_transport_parity)
	 * and so the approval round has a builder to switch on rather than a
	 * builder to write.
	 *
	 * TWO THINGS THE PLANE STOPS BEING ABLE TO SAY, both deliberate:
	 *
	 * - A PATH. See restore_artifact_name(). The node's own backup directory is
	 *   compiled in; a filename is the whole of what crosses.
	 * - A KEY. The SSH path unseals a decryption key into a temp file on the
	 *   node and passes --key-file. The primitive sends nothing of the sort:
	 *   the node decrypts with its own key on its own disk, and a job carrying
	 *   key material is refused. This is A4 exfiltration doctrine applied to
	 *   the write side — a plane that cannot hand a node a key cannot use a
	 *   node to open something the node could not open by itself.
	 *
	 * db_name and db_user are sent ONLY when a caller supplies them, and no
	 * caller does. THIS PLANE HOLDS NO RECORD OF A NODE'S DATABASE NAME: there
	 * is no mgn_ column for it, and the SSH path greps it out of the node's own
	 * Globalvars_site.php at run time, which is the only place it exists. So the
	 * node is the sole party that knows, and composing a value here to satisfy a
	 * required parameter would be this plane asserting a fact it does not have —
	 * against the wrong node, that asserted fact names somebody else's database.
	 * Absent, the agent resolves both from the config it already reads.
	 *
	 * The parameter stays in the vocabulary because an operator restoring into a
	 * deliberately different database is a real case and the node cannot infer
	 * it. It is optional, not defaulted.
	 *
	 * WHAT THIS PATH CANNOT DO YET, and must before the gate opens: a cloud-only
	 * backup. The SSH path downloads the object to /backups first, using bucket
	 * credentials; the primitive contract carries no bucket, key or credential,
	 * by design, so the file must already be on the node. Restoring a cloud-only
	 * backup over the channel needs upload_backup's mirror image, and that is a
	 * separate primitive, not a parameter added here.
	 */
	public static function build_restore_database_primitive($node, $params) {
		$primitive_params = [
			'file' => self::restore_artifact_name($params),
			// SENT EXPLICITLY, not left to the node's default. The agent treats
			// an absent profile as the site's own directory — the backup base
			// itself — while a manager profile lives in manager/ beneath it, and
			// THIS PLANE'S OWN BACKUPS ARE MANAGER-PROFILE. upload_backup and
			// delete_backup already default the same way for the same reason. A
			// silent disagreement about which of two directories a name is
			// resolved in is how a restore reads "no such backup" for a file the
			// operator is looking at, or finds a same-named one that is not it.
			'profile' => self::restore_profile($params),
		];

		// The agent's patterns, matched exactly. db_name is a PostgreSQL
		// identifier with no dash; db_user allows one. Both are bounded at 63,
		// which is PostgreSQL's own identifier limit.
		$patterns = [
			'db_name' => '/^[A-Za-z_][A-Za-z0-9_]*$/',
			'db_user' => '/^[A-Za-z_][A-Za-z0-9_-]*$/',
		];
		foreach ($patterns as $key => $pattern) {
			$value = trim((string)($params[$key] ?? ''));
			if ($value === '') {
				continue;
			}
			if (strlen($value) > 63 || !preg_match($pattern, $value)) {
				throw new Exception("'{$value}' is not a usable {$key} for a restore.");
			}
			$primitive_params[$key] = $value;
		}

		return ['primitive' => 'restore_database', 'params' => $primitive_params];
	}

	/**
	 * Restore a full project backup (.tar.gz) onto an existing node.
	 *
	 * Primitive only, like every restore: the node's agent runs it, the node's
	 * operator approves it.
	 *
	 * $params:
	 *   filename      - archive name; the node resolves it in its own backup directory
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
		if (self::has_primitive($node, 'restore_project')) {
			return self::build_restore_project_primitive($node, $params);
		}
		self::refuse_dead_restore_transport($node, 'restore_project');
	}

	/**
	 * Primitive path: the node restores one of its own project archives.
	 *
	 * NOT REACHABLE IN THIS BUILD — see build_restore_database_primitive() for
	 * why, and node_can_dispatch_destructive() for the single place that
	 * changes.
	 *
	 * `force` is always true and is not a caller's choice. It is what makes
	 * restore_project.sh non-interactive, and a job dispatched to an agent has
	 * no terminal to answer a prompt on: an unforced restore over the channel
	 * would not ask anybody anything, it would hang until the primitive's
	 * deadline killed it.
	 *
	 * NO DOMAIN CROSSES, AND ITS ABSENCE IS THE POINT. The primitive answers a
	 * narrow question — restore this node's own backup onto itself — and
	 * restore_project.sh defaults to the machine's own configured domain when
	 * --domain is absent. So the node keeps its own identity and a compromised
	 * plane cannot redirect a restore onto a domain of its choosing. Moving a
	 * site to a different name is install_node's job, not this one's.
	 *
	 * Cloud-only archives have the same gap as restore_database: no bucket, no
	 * key, no credential crosses, so the file must already be on the node.
	 */
	public static function build_restore_project_primitive($node, $params) {
		return ['primitive' => 'restore_project', 'params' => [
			'project_name' => self::restore_project_name($node),
			'file'         => self::restore_artifact_name($params),
			'profile'      => self::restore_profile($params),
			'force'        => true,
		]];
	}

	/**
	 * Restore a node from an incremental backup CHAIN.
	 *
	 * Chains are what the fleet actually produces — the manager backup profile
	 * writes a full plus incrementals, not standalone archives — so without this
	 * the backups every scheduled run uploads could not be restored from the
	 * dashboard at all.
	 *
	 * Primitive only: stage_chain puts the artifacts and the recovered chain key
	 * on the node first, then this job has restore_chain.sh verify and apply
	 * them. The node's agent runs it; the node's operator approves it.
	 *
	 * $params:
	 *   chain_id  - e.g. chain-20260807_231507 (required)
	 *   seq       - restore as at this run; default the newest in the manifest
	 *   skip_database - bool
	 */
	public static function build_restore_chain($node, $params) {
		if (self::has_primitive($node, 'restore_chain')) {
			return self::build_restore_chain_primitive($node, $params);
		}
		self::refuse_dead_restore_transport($node, 'restore_chain');
	}

	/**
	 * Primitive path: the node restores one of its own backup chains.
	 *
	 * NOT REACHABLE IN THIS BUILD — see build_restore_database_primitive() for
	 * why, and node_can_dispatch_destructive() for the single place that
	 * changes.
	 *
	 * The chain is named, never located. `chain_id` is checked against the same
	 * pattern the SSH path checks it against, and the node resolves it inside
	 * its own chain store — so the plane cannot name a bucket, a prefix or a
	 * directory, and the object layout stops being a thing two implementations
	 * both compute.
	 *
	 * NO RECOVERY KEY CROSSES, which is the sharpest difference from the SSH
	 * path. That path fetches the chain manifest and has the node open the
	 * sealed data key with its OWN site key, refusing when the chain belongs to
	 * a different machine — the plane's recovery private key never travels
	 * because it is the key of last resort for a machine that no longer exists,
	 * and a job record holding it would be a copy of it in every job table. The
	 * primitive keeps exactly that property by having no parameter that could
	 * carry it: recovery from the plane's key stays a human at a shell.
	 *
	 * No domain crosses, for the reason set out in
	 * build_restore_project_primitive(): the script defaults to the machine's
	 * own configured domain, so a node restoring its own chain keeps its own
	 * identity and the plane has no way to point a restore at another name.
	 *
	 * THE ARTIFACTS MUST ALREADY BE STAGED. Under SSH this plane composes six
	 * steps around restore_chain.sh — workspace, manifest fetch, envelope open,
	 * artifact download, pre-restore dump, restore — and one ScriptSpec starts
	 * one program. The primitive therefore resolves the artifact directory and
	 * the chain key at fixed node-side paths and refuses legibly when they are
	 * not there. Staging them (a node-side job script, and the bucket-read
	 * credential question that artifact download raises) belongs to
	 * specs/restore_dispatch_approval_mechanism.md, and this path cannot be
	 * dispatched before that lands anyway.
	 *
	 * seq and skip_database are sent only when they say something. An optional
	 * key that is always present as its own default is a value the node has to
	 * interpret and a reader has to check — "restore the whole chain" is what
	 * an absent seq means, and it means it more clearly by being absent.
	 */
	public static function build_restore_chain_primitive($node, $params) {
		$chain_id = trim((string)($params['chain_id'] ?? ''));
		if ($chain_id === '' || strlen($chain_id) > 64 || !preg_match('/^chain-[0-9_]+$/', $chain_id)) {
			throw new Exception('A chain restore needs the chain id (for example chain-20260807_231507).');
		}

		// normalize('') means the SITE profile, a different shelf — so an unset
		// parameter defaults to manager here rather than falling through to it,
		// exactly as the SSH path does.
		// NO PROFILE. The SSH path needs one to compose the bucket key
		// ({prefix}/{slug}/{profile}/{chain_id}), and the node needs none: it
		// resolves the chain inside its own store, where the staged artifact
		// directory is named by the chain id alone. A parameter the node would
		// ignore is a lie the sender believes, and the agent refuses undeclared
		// keys outright — so sending it would not be harmlessly redundant, it
		// would be a refusal.
		$primitive_params = [
			'project'  => self::restore_project_name($node),
			'chain_id' => $chain_id,
		];

		if (isset($params['seq']) && $params['seq'] !== '') {
			$seq = (int)$params['seq'];
			// The agent's own bounds. Out of range is refused here so the
			// operator sees it, rather than travelling to a node to be refused
			// there — a refusal on the wire reads as a node problem.
			if ($seq < 0 || $seq > 100000) {
				throw new Exception('A chain run number must be between 0 and 100000.');
			}
			$primitive_params['seq'] = $seq;
		}
		if (!empty($params['skip_database'])) {
			$primitive_params['skip_database'] = true;
		}

		return ['primitive' => 'restore_chain', 'params' => $primitive_params];
	}

	/**
	 * Apply a Joinery update on the node via its own upgrade.php.
	 *
	 * The most-used operation this plane has. Primitive only: the node runs its
	 * own upgrade.php from its own compiled-in paths — this plane sends a name,
	 * never a command string or its belief about where someone else's site
	 * lives.
	 */
	public static function build_apply_update($node, $params = []) {
		if (!self::has_primitive($node, 'apply_update')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot apply an update: that needs a paired agent "
				. 'of at least ' . self::PRIMITIVE_MIN_AGENT_VERSION['apply_update'] . '. Pair the node.');
		}
		return self::build_apply_update_primitive($node);
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
		if (!self::has_primitive($node, 'run_plugin_installers')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot run plugin installers: that needs a "
				. 'paired agent. Pair the node.');
		}
		return self::build_run_plugin_installers_primitive($node);
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
	 * Publish a new upgrade from the management node (runs locally).
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
	 * All steps are 'local' type — the agent runs SSH commands from the management node.
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
	 * There is deliberately no way to write a node's recovery key from here, and
	 * no way to hand one to a job.
	 *
	 * That key decides who can open every backup the node makes — the ones the
	 * node takes for itself and the ones this management node takes of it, which
	 * are the same key now. Its custodian is whoever administers the node, and
	 * possession is proven there, against a challenge that node issued. A management
	 * node that could write it, or could pass one with a run, would be a management
	 * node that could quietly become the only party able to read the fleet.
	 *
	 * `set_recovery_key.php --report` is still asked during check_status, because
	 * whether a node holds a proven key decides whether it can be backed up at
	 * all — see assert_node_can_be_backed_up(). It reports and only reports.
	 */

	/**
	 * Refuse to build a backup job for a node that cannot encrypt one.
	 *
	 * The node refuses these runs itself — that is the guard, and it holds
	 * whatever a management node believes. This is the second thing: failing at
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
		// credential, recovery key — is supplied by this management node anyway,
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
	 * How many enabled shelves this management node has, for the refusal message
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
	 * Upload one already-existing backup file from the node to its cloud target —
	 * the Backups tab's per-file action, for a backup that is sitting local-only
	 * because its original upload hit a transient provider failure.
	 *
	 * The file lives on the node, so the transfer runs there. Routing it through
	 * the management node instead would drag the whole archive down and push it back
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
		if (!self::has_primitive($node, 'upload_backup')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot push a backup to the shelf: that needs a "
				. 'paired agent. There is no SSH equivalent — the old one heredoc-fed the node an '
				. 'uploader with the bucket credentials inside it. Pair the node.');
		}
		return self::build_upload_backup_primitive($node, $params);
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
	 * Bring one of a node's own backups back from the shelf, onto the node.
	 *
	 * THE OPERATION THAT MADE RESTORE POSSIBLE AGAIN. Every node in the fleet
	 * deletes its local archive once it is safely uploaded — right for a small
	 * disk, and it means the normal state of a machine is "my backups are all
	 * offsite". The restore primitives take the NAME of a file they expect to
	 * find in their own backup directory, and that file is never there. Opening
	 * the destructive gate on its own would have produced a restore that was
	 * permitted and still restored nothing.
	 *
	 * NO CREDENTIAL IS SENT, and that is the whole design of this builder. A node
	 * holds a WRITE-ONLY bucket credential on purpose: it may add to the shelf
	 * and may not read from it, because a node that could read the shelf is a
	 * node whose compromise reaches every other node's backups. So this plane
	 * signs ONE object key here, with the credential it already holds, for a
	 * window no longer than the job's own claim budget, and sends the SIGNATURE.
	 * A signature is not a key: it names one object, the object name is inside
	 * it, and it expires. There is deliberately no parameter in the primitive
	 * through which access_key, secret_key or a credentials blob could arrive.
	 *
	 * THE NODE CHECKS WHAT ARRIVES. This plane chooses the bucket, the signature
	 * and the name the file lands under, so the node verifies what it fetches
	 * against its own upload ledger — written by its own backup run, at upload
	 * time, before the bytes were anywhere this plane could reach — and refuses
	 * anything it has no record of making. See BackupLedger; the point of it is
	 * REPLAY, which sealing does not touch: this plane could otherwise serve a
	 * node its own genuine month-old archive under a fresh-looking name.
	 *
	 * PRIMITIVE ONLY, and there is no SSH twin to fall back to. The SSH path
	 * heredoc'd a downloader program with credentials baked into it, which is
	 * the exact capability being deleted; a node that cannot take a primitive
	 * cannot be given this, and says so.
	 *
	 * @param array $params filename, cloud_path (the object key from the
	 *   listing), profile, include_envelope
	 */
	public static function build_download_backup($node, $params = []) {
		if (!self::has_primitive($node, 'download_backup')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot fetch its own backups back: that needs a paired "
				. 'agent of at least ' . self::PRIMITIVE_MIN_AGENT_VERSION['download_backup'] . '. '
				. 'There is no SSH equivalent — the old one shipped a downloader with the bucket '
				. 'credentials inside it.');
		}
		return self::build_download_backup_primitive($node, $params);
	}

	public static function build_download_backup_primitive($node, $params = []) {
		require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
		require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));

		$filename = basename(trim((string)($params['filename'] ?? '')));
		if ($filename === '' || $filename === '.' || $filename === '..') {
			throw new Exception('No backup filename given.');
		}

		$target = self::get_target($node);
		if (!$target) {
			throw new Exception("Node '{$node->get('mgn_slug')}' has no enabled cloud backup target, "
				. 'so there is no shelf to fetch from.');
		}

		$profile = in_array(($params['profile'] ?? ''), ['site', 'manager'], true)
			? $params['profile'] : 'manager';
		$key = self::node_object_key($node, $target, $params['cloud_path'] ?? '', $profile);

		$creds = $target->get_credentials();
		if (empty($creds)) {
			throw new Exception('The backup target has no stored credentials, so no download can be signed.');
		}

		$expires = self::signed_link_seconds('download_backup');
		$primitive_params = [
			'filename' => $filename,
			// WHOSE shelf, so the node knows which ledger to check the bytes
			// against. This plane's own backups of a node are manager-profile,
			// which is why that is the default here as it is on every other
			// backup primitive. node_object_key() has already checked it agrees
			// with the shelf the object is actually on.
			'profile'  => $profile,
			'url'      => S3Signer::presign_get($creds, $target->get('bkt_bucket'), '/' . ltrim($key, '/'), $expires),
		];

		// The envelope, when it is wanted. Its object key is DERIVED here from
		// the archive's, and its landing name is derived again on the node from
		// the archive it was given — so neither side is naming a .keys.json file
		// the other did not expect. An encrypted archive without its envelope is
		// a restore point nobody can open, which is why this is normally on.
		if (!empty($params['include_envelope'])) {
			$envelope_key = self::sidecar_object_key($key);
			$primitive_params['envelope_url'] = S3Signer::presign_get(
				$creds, $target->get('bkt_bucket'), '/' . ltrim($envelope_key, '/'), $expires);
		}

		return ['primitive' => 'download_backup', 'params' => $primitive_params];
	}

	/**
	 * Put a whole incremental chain back on a node, ready to restore.
	 *
	 * Chains are what this fleet actually produces, so this is the staging the
	 * common restore needs. It replaces the six SSH steps that used to surround
	 * restore_chain.sh — workspace, manifest fetch through a heredoc'd
	 * downloader, envelope open, a PYTHON PROGRAM COMPOSED ON THIS PLANE to work
	 * out which artifacts a run needs, artifact downloads, pre-restore dump.
	 *
	 * The Python program is the part worth naming. It made the chain layout
	 * something two implementations computed, with the authoritative one running
	 * on the machine that did not write the chain. Here this plane signs every
	 * object it can see under the chain's prefix and hands the links over keyed
	 * by bare name; the NODE reads its own manifest and decides which of them it
	 * needs. This plane has no say in that and cannot express one.
	 *
	 * ClassOperate on the node: staging destroys nothing, so it needs no
	 * approval and can run while the operator is still deciding.
	 */
	public static function build_stage_chain($node, $params = []) {
		if (!self::has_primitive($node, 'stage_chain')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot stage a backup chain: that needs a paired agent "
				. 'of at least ' . self::PRIMITIVE_MIN_AGENT_VERSION['stage_chain'] . '.');
		}
		return self::build_stage_chain_primitive($node, $params);
	}

	public static function build_stage_chain_primitive($node, $params = []) {
		require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
		require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupChainListHelper.php'));

		$chain_id = trim((string)($params['chain_id'] ?? ''));
		if ($chain_id === '' || strlen($chain_id) > 64 || !preg_match('/^chain-[0-9_]+$/', $chain_id)) {
			throw new Exception('Staging a chain needs the chain id (for example chain-20260807_231507).');
		}

		$target = self::get_target($node);
		if (!$target) {
			throw new Exception("Node '{$node->get('mgn_slug')}' has no enabled cloud backup target.");
		}
		$creds = $target->get_credentials();
		if (empty($creds)) {
			throw new Exception('The backup target has no stored credentials, so no download can be signed.');
		}

		$slug = trim((string)$node->get('mgn_slug'));
		if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
			throw new Exception("Node slug '{$slug}' cannot be used as a bucket path segment.");
		}
		// normalize('') means the SITE profile, a different shelf — so an unset
		// parameter defaults to manager rather than falling through to it, the
		// same rule the restore builders follow.
		$profile   = BackupProfile::normalize(trim((string)($params['profile'] ?? '')) ?: BackupProfile::MANAGER);
		$chain_key = BackupChainListHelper::chain_path($target, $slug, $profile, $chain_id);

		// Everything on the shelf under this chain, signed. Listed rather than
		// computed: this plane signs what is THERE, and the node picks from what
		// its own manifest names. A name in the manifest with no link here is a
		// missing object, and the node says so by name.
		$listing = S3Signer::list($creds, $target->get('bkt_bucket'), $chain_key . '/');
		if (empty($listing) || !is_array($listing)) {
			throw new Exception("Nothing is stored under {$chain_id} on this node's shelf, so there is "
				. 'nothing to stage.');
		}

		$expires  = self::signed_link_seconds('stage_chain');
		$manifest_url = '';
		$artifact_urls = [];
		foreach ($listing as $object) {
			$key  = (string)($object['key'] ?? $object['Key'] ?? '');
			if ($key === '' || strpos($key, $chain_key . '/') !== 0) {
				continue;
			}
			$name = basename($key);
			$url  = S3Signer::presign_get($creds, $target->get('bkt_bucket'), '/' . ltrim($key, '/'), $expires);
			if ($name === BackupChain::MANIFEST_NAME) {
				$manifest_url = $url;
				continue;
			}
			// Bare names only, and the node bounds them again. A key with
			// anything else in it is not something this chain's manifest can
			// name, so signing it would only widen what travels.
			if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
				$artifact_urls[$name] = $url;
			}
		}

		if ($manifest_url === '') {
			throw new Exception("The chain {$chain_id} has no manifest on the shelf, so its artifacts "
				. 'cannot be identified. A chain without its manifest is not a restore point.');
		}
		if (!$artifact_urls) {
			throw new Exception("The chain {$chain_id} has a manifest but no artifacts on the shelf.");
		}

		$primitive_params = [
			'chain_id'      => $chain_id,
			'profile'       => BackupProfile::path_segment($profile),
			'manifest_url'  => $manifest_url,
			'artifact_urls' => $artifact_urls,
		];
		if (isset($params['seq']) && $params['seq'] !== '') {
			$seq = (int)$params['seq'];
			if ($seq < 0 || $seq > 100000) {
				throw new Exception('A chain run number must be between 0 and 100000.');
			}
			$primitive_params['seq'] = $seq;
		}

		// The node applies the same ceiling, byte for byte. Checked here so a
		// chain too long to describe in one job fails where an operator is
		// standing, naming the reason, rather than travelling to a node to be
		// refused there.
		$size = strlen((string)json_encode($primitive_params));
		if ($size > ManagementJob::MAX_PARAMS_BYTES) {
			throw new Exception("Staging {$chain_id} would need {$size} bytes of signed links, over the "
				. ManagementJob::MAX_PARAMS_BYTES . '-byte job limit. This chain has grown longer than a '
				. 'single staging job can describe — start a fresh chain, or restore it from a shell.');
		}

		return ['primitive' => 'stage_chain', 'params' => $primitive_params];
	}

	/**
	 * Stop a restore that would be composed for a transport nothing runs.
	 *
	 * Every restore now travels as a primitive to the node's own agent, where
	 * the node's own operator approves it. A node that cannot take one has no
	 * remaining route: the agent refuses ssh and scp steps outright. This turns
	 * that into an answer an operator can act on — upgrade the agent, or pair
	 * the node — rather than a job that fails at step one with a message about
	 * a step type.
	 */
	private static function refuse_dead_restore_transport($node, $operation) {
		$slug = $node->get('mgn_slug');
		if (!self::has_agent_channel($node)) {
			throw new Exception(
				"Node '{$slug}' has no paired agent, so there is no way to restore it in place. "
				. 'Restores travel to the node\'s own agent and are approved on the node itself; '
				. 'the SSH route was removed. Pair the node, or rebuild it from a backup.');
		}
		$min     = self::PRIMITIVE_MIN_AGENT_VERSION[$operation] ?? '';
		$version = (string)$node->get('mgn_agent_version');
		throw new Exception(
			"Node '{$slug}' is running agent " . ($version !== '' ? $version : 'an unknown version')
			. ", which cannot ask its own operator to approve a restore. That needs at least {$min}. "
			. 'Apply an update to the node first — there is no SSH route left to fall back to.');
	}

	/**
	 * How long a signed download link stays valid: exactly the claim budget of
	 * the job that carries it.
	 *
	 * "Expiring with the job" rather than a round number chosen for comfort. A
	 * link that outlives its job is a standing read on someone else's backup
	 * sitting in a job row; a link shorter than the transfer it authorizes is a
	 * download that dies half way through a multi-gigabyte archive.
	 */
	private static function signed_link_seconds($primitive) {
		return (int)(ManagementJob::PRIMITIVE_CLAIM_BUDGETS[$primitive] ?? ManagementJob::CLAIM_TIMEOUT_SECONDS);
	}

	/**
	 * The object key a download may name, checked to be one of THIS node's.
	 *
	 * The caller passes the key it read out of the shelf listing, which is the
	 * honest source — this plane should not be recomputing an object layout the
	 * node's own backup engine already decided. What it must not do is sign a
	 * key belonging to a different node: every node's archives live in one
	 * bucket under its own slug, so an unchecked key here would let one node be
	 * handed another's backup and, with a matching ledger entry absent, at least
	 * waste a transfer — and at worst, on a slug typo, restore the wrong site.
	 */
	private static function node_object_key($node, $target, $cloud_path, $profile = null) {
		$key = ltrim(trim((string)$cloud_path), '/');
		if ($key === '') {
			throw new Exception('No cloud object was named for this download.');
		}
		if (strpos($key, '..') !== false) {
			throw new Exception('That is not a usable object key.');
		}
		$slug = trim((string)$node->get('mgn_slug'));
		$prefix = rtrim(trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups', '/');
		$node_prefix = $prefix . '/' . $slug . '/';
		if (strpos($key, $node_prefix) !== 0) {
			throw new Exception("That backup is not on node '{$slug}' shelf, so it will not be sent there.");
		}

		// The profile has to agree with the shelf the object is actually on.
		//
		// The two are chosen independently — one from the caller's parameter,
		// one from the object key it read out of a listing — and nothing made
		// them agree. A disagreement is not exploitable (the node lands the file
		// in the named profile's directory and checks it against that profile's
		// ledger, where the name will not be found, so it refuses) but it is a
		// job that was always going to fail, dispatched, and a refusal for a
		// reason that has nothing to do with what went wrong. Two values that
		// look like they should agree, and did not have to, is how a real
		// mismatch hides.
		$rest = substr($key, strlen($node_prefix));
		$segment = strtok($rest, '/');
		if ($profile !== null && in_array($segment, array('site', 'manager'), true) && $segment !== $profile) {
			throw new Exception("That backup is on the '{$segment}' shelf but the restore names the "
				. "'{$profile}' one. The node would look for it in the wrong directory and refuse.");
		}
		return $key;
	}

	/** The envelope's object key beside an archive's. Derived, never supplied. */
	private static function sidecar_object_key($key) {
		$dir = trim((string)dirname($key), '.');
		$name = BackupEnvelope::sidecar_name(basename($key));
		return ($dir === '' || $dir === '/') ? $name : rtrim($dir, '/') . '/' . $name;
	}

	/**
	 * The credential placeholder a NODE-bound step carries for this target.
	 *
	 * A target can hold a second, write-only credential (bkt_node_credentials).
	 * When it does, node-bound steps carry __SM_NODE_CREDS_<id>__ and the node
	 * is handed a key that can add objects to the shelf but never delete —
	 * a compromised node then cannot erase the fleet's backups. The main
	 * (delete-capable) credential stays on the management node for retention and
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
	 * List backup files on a node. Local only — cloud listings are done
	 * web-server-side via TargetLister when the Backups tab renders.
	 * Routes primitive first, then the management API.
	 */
	public static function build_list_backups($node) {
		if (self::has_primitive($node, 'list_backups')) {
			return self::build_list_backups_primitive($node);
		}
		if (self::has_api($node, 'list_backups')) {
			return self::build_list_backups_api($node);
		}
		throw new Exception(
			"Node '{$node->get('mgn_slug')}' cannot run list_backups: "
			. "no paired agent and no API credentials (or the health probe failed)."
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
	 * The HOST node a container victim's decommission is addressed to.
	 *
	 * The routing chain is the placement record: victim → mgn_mgh_host_id →
	 * host row → mgh_mgn_host_node_id → the host's own paired ManagedNode.
	 * Every missing link refuses naming its fix — the operator's next step is
	 * in the message, not in a runbook.
	 */
	public static function decommission_host_node_for($node) {
		$host_id = (int)$node->get('mgn_mgh_host_id');
		if (!$host_id) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' has no placement record (mgn_mgh_host_id), so this plane "
				. "cannot say which host to address. Assign the node to its host first."
			);
		}
		$host = new ManagedHost($host_id, TRUE);
		if (!$host->key || $host->get('mgh_delete_time')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' names a host record that no longer exists."
			);
		}
		$host_node = $host->host_node();
		if (!$host_node) {
			throw new Exception(
				"The host '{$host->get('mgh_name')}' has no agent node of its own yet. Pair the host's "
				. "agent (siteless install, then join) and link its node on the host's edit page."
			);
		}
		return $host_node;
	}

	/**
	 * Permanently remove a container site from its shared host, with the site's
	 * own consent.
	 *
	 * Routed as ONE destructive primitive (decommission_site) to the HOST's
	 * own paired agent — the victim's agent lives inside the container being
	 * destroyed and cannot outlive the work. The host runs the bundled,
	 * self-verifying remove_account.sh; before anything is touched, the victim
	 * approves its own removal on its own admin with its own recovery key
	 * (DecommissionApprovalPanel). This plane is not in the approval path —
	 * the primitive declares no parameter an answer could travel through.
	 *
	 * Refusals, each naming the operator's next step:
	 * - a relay: torn down through the relay flow, unchanged;
	 * - a bare-metal node: a whole machine is decommissioned at the PROVIDER
	 *   (delete the instance, then delete the node record) — the settled
	 *   answer, stated where the operator acts;
	 * - an unpaired host: pair the host's agent;
	 * - a victim below the release that carries the approval panel: upgrade it
	 *   first, or it cannot render the consent it would be asked for;
	 * - a victim with pending or running jobs: finish or cancel them first.
	 */
	public static function build_decommission_node($node, $params = []) {
		if ($node->get('mgn_is_relay')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' is a relay. Decommission relays through the relay "
				. "teardown flow (remove its tenants first), not site removal."
			);
		}
		if (!trim((string)$node->get('mgn_container_name'))) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' is not a container site. A dedicated machine is "
				. "decommissioned at its provider: delete the instance there, then delete this node "
				. "record from the dashboard."
			);
		}

		$site = self::decommission_site_name($node);
		// The agent's own wire pattern, applied at build time so a legacy
		// container name the host would refuse fails here, with the reason.
		if (!preg_match('/^[a-z0-9_-]{1,50}$/', $site)) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' derives the site name '{$site}', which is not in the "
				. "shape the host agent accepts (lowercase letters, digits, _ and -, at most 50)."
			);
		}

		$host_node = self::decommission_host_node_for($node);
		if (!self::has_primitive($host_node, 'decommission_site')) {
			throw new Exception(
				"The host agent '{$host_node->get('mgn_slug')}' cannot remove a site: that needs a "
				. "paired agent of at least " . self::PRIMITIVE_MIN_AGENT_VERSION['decommission_site']
				. " reporting the decommission_site primitive. Update the host's agent."
			);
		}

		// The victim renders its own consent, so the release carrying the
		// approval panel must be ON the victim before the host can stage one.
		$core = trim((string)$node->get('mgn_joinery_version'));
		if ($core === '' || version_compare($core, self::DECOMMISSION_PANEL_MIN_CORE_VERSION, '<')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' runs core " . ($core === '' ? '(unknown)' : $core)
				. ", which cannot render the removal approval. Upgrade it to "
				. self::DECOMMISSION_PANEL_MIN_CORE_VERSION . " or later first — the site must be able "
				. "to consent to its own removal."
			);
		}

		// A victim mid-work is not demolished. Finish or cancel its jobs first,
		// so nothing dies strangely when the container goes.
		$open = self::open_job_count($node);
		if ($open > 0) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' has {$open} pending or running job"
				. ($open === 1 ? '' : 's') . ". Finish or cancel them before removing the site."
			);
		}

		// An in-flight decommission is filed against the HOST, so the victim's
		// own queue cannot see it — a second dispatch would otherwise pass
		// every refusal above and run two teardowns. One removal per host at a
		// time.
		$db = DbConnector::get_instance()->get_db_link();
		$hq = $db->prepare(
			"SELECT COUNT(*) FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'decommission_node'
			   AND mjb_status IN ('pending', 'running')");
		$hq->execute([(int)$host_node->key]);
		if ((int)$hq->fetchColumn() > 0) {
			throw new Exception(
				"The host '{$host_node->get('mgn_slug')}' already has a site removal pending or running. "
				. "Wait for it to finish before dispatching another."
			);
		}

		return self::build_decommission_site_primitive($host_node, ['site' => $site]);
	}

	/**
	 * The envelope, addressed to the HOST node. One parameter: a site NAME,
	 * never a path — every path is composed host-side from compiled-in
	 * patterns, and the approval answer has no field to travel through.
	 */
	public static function build_decommission_site_primitive($host_node, $params = []) {
		return ['primitive' => 'decommission_site', 'params' => ['site' => (string)($params['site'] ?? '')]];
	}

	/** Pending or running jobs filed against a node. */
	private static function open_job_count($node) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT COUNT(*) FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_status IN ('pending', 'running')");
		$q->execute([(int)$node->key]);
		return (int)$q->fetchColumn();
	}

	/**
	 * Delete ONE LOCAL backup file on the node. Local only: the cloud copy is
	 * deleted by the plane itself, in-process with S3Signer, which is what
	 * backup_actions_logic does before it ever files a job — a cloud delete
	 * needs the delete-capable credential, and that one stays on the
	 * management node.
	 *
	 * $params: filename (or local_path, from which the basename is taken), profile
	 */
	public static function build_delete_backup($node, $params) {
		if (!self::has_primitive($node, 'delete_backup')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot delete a local backup: that needs a "
				. 'paired agent. Pair the node.');
		}
		return self::build_delete_backup_primitive($node, $params);
	}

	/**
	 * Primitive path: delete ONE LOCAL backup file on the node.
	 *
	 * Local only, and the omission is the design. The SSH path's cloud branch
	 * shipped the MAIN, delete-capable bucket credential to the node, because a
	 * write-only key cannot delete — while creds_token()'s own docblock says
	 * that credential "stays on the management node", the whole point of the
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
	 * Build a local shell command that updates a ManagedNode field in the management node DB.
	 * Reads DB credentials from the management node's Globalvars_site.php. Used during the
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

	// ── Managed domains ──
	//
	// Both of these reach into a customer's own box, and the reach is a job. It
	// was not always: PHP shelled out directly, which made it invisible to
	// transports_for(), to can_run(), to the SSH-only inventory and to the
	// agent's own refusal of shell steps, because it never entered the job
	// system at all. It enters it here.
	//
	// Neither has an SSH sibling, and neither may grow one: an SSH fallback would
	// recreate exactly what this removed. A node without the primitive gets an
	// exception naming what is missing, and the caller writes it where an
	// operator can read it.

	/**
	 * Ask a node to make itself mail-ready for one managed domain and report
	 * the DNS records that requires.
	 *
	 * The management node owns the registrar and the zone; the BOX owns
	 * everything that decides what belongs in that zone — its receive topology,
	 * its SPF shape, its DKIM key, whether it speaks Joinery Direct. A plane
	 * that computed those records itself would publish a plausible set the box
	 * does not match, and the mismatch shows up as mail silently failing
	 * authentication. So the box prints desired state and this plane publishes
	 * it. That split is the design; only the transport changed.
	 *
	 * The answer comes back as one JSON line in the job's output, which
	 * JobResultProcessor::process_managed_domain_prepare parses onto the job.
	 */
	public static function build_managed_domain_prepare($node, $params) {
		if (!self::has_primitive($node, 'managed_domain_prepare')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot prepare a managed domain for mail: its agent "
				. "does not offer the managed_domain_prepare primitive. Apply an update to the node; "
				. "there is no SSH route left for this.");
		}
		return self::build_managed_domain_prepare_primitive($node, $params);
	}

	public static function build_managed_domain_prepare_primitive($node, $params) {
		// Lowercased before it crosses, because the node's pattern is lowercase
		// and one name has one spelling in a zone.
		$domain = strtolower(trim((string)($params['domain'] ?? '')));
		if ($domain === '') {
			throw new Exception('Preparing a node for mail needs the domain it is about.');
		}
		return ['primitive' => 'managed_domain_prepare', 'params' => ['domain' => $domain]];
	}

	/**
	 * Set the four managed-domain facts on a node, from which its own
	 * ManagedDomainNotice renders the take-ownership countdown.
	 *
	 * FOUR VALUES CROSS, AND NOT THE SETTING NAMES. Those are compiled into
	 * utils/managed_domain_notice.php on the node: this plane supplies what the
	 * notice says and cannot express where it lands. The alternative — a generic
	 * write-a-setting job — would hand a compromised management node every row
	 * in every node's stg_settings, which is where the credentials are.
	 *
	 * An empty state is a real value and the ordinary one: it is what renders
	 * nothing, and it is what a box holds for the first six months.
	 */
	public static function build_managed_domain_notice($node, $params) {
		if (!self::has_primitive($node, 'managed_domain_notice')) {
			throw new Exception(
				"Node '{$node->get('mgn_slug')}' cannot be told about its managed domain: its agent "
				. "does not offer the managed_domain_notice primitive. Apply an update to the node; "
				. "there is no SSH route left for this.");
		}
		return self::build_managed_domain_notice_primitive($node, $params);
	}

	public static function build_managed_domain_notice_primitive($node, $params) {
		$domain = strtolower(trim((string)($params['domain'] ?? '')));
		if ($domain === '') {
			throw new Exception('A managed-domain notice needs the domain it is about.');
		}

		// Only the domain is required. The other three are sent even when empty
		// so the node clears them: the caller converges on desired state, and a
		// push that could only add would leave a stale expiry date on a
		// customer's site after a renewal.
		$expiry = trim((string)($params['expiry_time'] ?? ''));
		if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $expiry)) {
			// Refused here as well as on the node, so a malformed date fails
			// where the row that holds it can be looked at rather than as an
			// opaque refusal from a machine.
			throw new Exception('The expiry ' . $expiry . ' is not a date this notice can carry.');
		}

		$state = trim((string)($params['state'] ?? ''));
		if (!in_array($state, self::MANAGED_DOMAIN_STATES, true)) {
			throw new Exception("Custody state '{$state}' is not one the notice renders.");
		}

		return ['primitive' => 'managed_domain_notice', 'params' => [
			'domain'      => $domain,
			'expiry_time' => $expiry,
			'state'       => $state,
			'manage_url'  => trim((string)($params['manage_url'] ?? '')),
		]];
	}

	/**
	 * The custody states the notice renders, plus the empty one that renders
	 * nothing. Mirrors the agent's own enum and RegisteredDomain's GRAD_
	 * constants; kept here so a builder can refuse a bad state without loading
	 * the domain model.
	 */
	const MANAGED_DOMAIN_STATES = ['operator_managed', 'push_requested', 'push_sent', 'self_custody', ''];

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
			// token into the site's webroot, and the management node fetches it
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
	 * the machine — deleted rows included, so a removed-but-still-running
	 * container's port is never handed out again (P-18 collision-safety).
	 *
	 * Sibling identity is the placement FK, never the per-node host string —
	 * a container node without one cannot be allocated a port. But one
	 * MACHINE can be described by more than one host ROW (duplicates from the
	 * per-SSH-tuple backfill; a soft-deleted row re-minted later), and a port
	 * reserved under any of them is reserved on the machine — so the max runs
	 * over every node whose placement row, deleted rows included, shares the
	 * target row's address. Excludes the node being installed.
	 */
	public static function next_container_port($host_id, $exclude_node_id = 0) {
		if (!(int)$host_id) {
			throw new Exception('next_container_port requires a managed host id: a container node names its host by mgn_mgh_host_id.');
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT COALESCE(MAX(n.mgn_port), 0)
			 FROM mgn_managed_nodes n
			 JOIN mgh_managed_hosts h ON h.mgh_id = n.mgn_mgh_host_id
			 WHERE h.mgh_host = (SELECT mgh_host FROM mgh_managed_hosts WHERE mgh_id = ?)
			   AND n.mgn_id <> ?");
		$q->execute([(int)$host_id, (int)$exclude_node_id]);
		$max = (int)$q->fetchColumn();
		return $max >= 8080 ? $max + 1 : 8080;
	}

	private static function allocate_container_port($node) {
		$host_id = (int)$node->get('mgn_mgh_host_id');
		if (!$host_id) {
			$host = ManagedHost::ensure_for_node($node);
			$host_id = (int)$host->key;
		}
		return self::next_container_port($host_id, (int)$node->key);
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

		// Management node URL — where the target fetches the Joinery release tarball from.
		// Uses the webDir config setting (our site's own hostname).
		$settings = Globalvars::get_instance();
		$webdir = $settings->get_setting('webDir') ?: $_SERVER['HTTP_HOST'] ?? 'dev.getjoinery.com';
		$release_url = "https://{$webdir}/utils/latest_release";
		$release_url_esc = escapeshellarg($release_url);

		$sitename_esc = escapeshellarg($sitename);
		$domain_esc = escapeshellarg($domain);
		$mode_flag = ($docker === 'docker') ? ' --docker' : ' --bare-metal';
		// P-18: pin the container's published port. Without this $port_arg was
		// empty, so install.sh self-allocated a port the management node never
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

		// 1. Pre-flight: verify the management node is serving a release archive
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

			$steps[] = ['type' => 'scp', 'label' => 'Fetch DB backup to management node',
				'node_id' => $source_node_id, 'direction' => 'download',
				'remote_path' => $scp_db_remote, 'local_path' => $local_db_backup];
			$steps[] = ['type' => 'scp', 'label' => 'Fetch project backup to management node',
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

		// Docker mode: record the container name in the management node DB so future jobs
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
			$steps[] = ['type' => 'local', 'label' => 'Record container name in management node',
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

			$teardown[] = ['type' => 'local', 'label' => 'Clean up backup files on management node',
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
		// this management node's own backups, which carry their key with each run;
		// the site's own key is its operator's to set up, on its own Backups page,
		// with the possession ceremony that makes it trustworthy. Handing one over
		// at install time would put this management node's key in a slot whose
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

		// 1. Pre-flight on the management node: the sealer source + installer exist,
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
