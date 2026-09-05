<?php
/**
 * NodeMonitorHealth — is this node's uptime monitoring actually working?
 *
 * Distinct from whether the node is up. A node can be perfectly healthy while
 * its monitoring is dead: an api check with no credentials, or a check type
 * with nothing to probe, produces neither an up nor a down result. Those nodes
 * look identical to never-checked ones, so the failure hides.
 *
 * Every surface that reports monitoring state uses this class, so the
 * dashboard, the node detail page and the uptime task cannot disagree.
 *
 * It also surfaces backup recovery problems (backup_recovery_problems), in the
 * same shape, so an unrecoverable-backup node is as visible as broken monitoring.
 *
 * @version 1.13 - fleet backup outcome 'warning': the node kept a full backup a tenth the size of
 *                 its last one; the card says so with the node's own words instead of a green tick
 * @version 1.12 - note_reported_script_trust(): a node volunteers its own answer on every poll,
 *                  which is the only way to see one that is refusing with no job dispatched to it
 * @version 1.11 - script trust: classify_script_trust() tells a manifest that cannot be used from a
 *                  file that does not match it, note_script_trust() records the state on the node as
 *                  the refusal arrives, and script_trust_problems() puts it on the dashboard
 * @version 1.10 - a failed fleet backup is reported from the run history: since when it has been
 *                 failing, how many runs, when it last worked, and the reason the node gave —
 *                 backup_run_summary() over backup_runs_from_here() — with the failed job linked
 * @version 1.9 - fleet_backup_health leads with whether the node holds a verified recovery key of
 *                its own: backups seal to the node's key, read there, so a node without one will
 *                never back up and says so on the first pass instead of ageing into "never"
 * @version 1.8 - is_name_resolution_failure(): a probe that died in the monitoring host's own
 *                resolver is a statement about us, not about the node, so callers can decline
 *                to conclude instead of reporting the whole fleet down
 * @version 1.7 - fleet_backup_health cross-checks the node's claimed success against the bucket:
 *                a shelf listed after the claimed run that holds nothing new is a node whose
 *                backups are not landing, however healthy its own reports look
 * @version 1.6 - surfaces a fleet trust root whose only offsite copy (this site's own whole-site
 *                backup) does not exist yet — the implicit guarantee that replaced the signing-key
 *                escrow record is made checkable
 * @version 1.5 - backup_recovery_problems reports the one thing that can still be wrong: recovery
 *                itself is not set up. Per-node key rows are gone — a backup seals its own key as
 *                it is made, so a node holds nothing that can go missing
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

class NodeMonitorHealth {

	/** Monitoring is off by choice. Not a problem. */
	const STATE_DISABLED = 'disabled';
	/** Configured, concluding, current. */
	const STATE_OK = 'ok';
	/** Enabled but can never conclude as configured — needs a human. */
	const STATE_MISCONFIGURED = 'misconfigured';
	/** Configured fine, but has not concluded in far too long. */
	const STATE_STALE = 'stale';
	/** Enabled and configured, simply not probed yet. */
	const STATE_PENDING = 'pending';

	/** A node is stale once it has not concluded in this many intervals. */
	const STALE_INTERVAL_MULTIPLE = 4;
	/** Floor for the stale window, for nodes with very short intervals. */
	const STALE_MINIMUM_SECONDS = 900;

	/** Curl error numbers, named locally so they read as themselves. */
	const CURLE_COULDNT_RESOLVE_PROXY = 5;
	const CURLE_COULDNT_RESOLVE_HOST  = 6;

	/**
	 * Lowercase fragments that identify a name-resolution failure in an error
	 * message. Covers curl's own wording (both the stock resolver and c-ares),
	 * curl's timeout-during-resolution, and getaddrinfo as PHP's socket
	 * functions report it.
	 */
	const RESOLUTION_FAILURE_MARKERS = [
		'could not resolve',
		'couldn\'t resolve',
		'resolving timed out',
		'getaddrinfo',
		'name or service not known',
		'temporary failure in name resolution',
		'domain name not found',
	];

	/**
	 * Evaluate one node.
	 *
	 * @return array{state:string, label:string, detail:string, is_problem:bool}
	 */
	public static function evaluate($node): array {
		if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
			return self::result(self::STATE_DISABLED, 'Monitoring off',
				'Uptime monitoring is disabled for this node.', false);
		}

		// Can this check type reach anything at all, as configured?
		$target = self::describe_target($node);
		if ($target['problem'] !== '') {
			return self::result(self::STATE_MISCONFIGURED, 'Monitoring misconfigured',
				$target['problem'], true);
		}

		// A recorded reason the check could not conclude outranks staleness —
		// it is the specific cause, and staleness is only its symptom.
		$last_error = trim((string)$node->get('mgn_uptime_last_error'));
		if ($last_error !== '') {
			return self::result(self::STATE_MISCONFIGURED, 'Monitoring misconfigured',
				$last_error, true);
		}

		$conclusive = trim((string)$node->get('mgn_uptime_last_conclusive'));
		if ($conclusive === '') {
			// Never concluded. If it has been attempted, something is wrong;
			// if never attempted, it is simply waiting for the next tick.
			$attempted = trim((string)$node->get('mgn_uptime_last_check'));
			if ($attempted === '') {
				return self::result(self::STATE_PENDING, 'Not yet checked',
					'Waiting for the first uptime check.', false);
			}
			return self::result(self::STATE_MISCONFIGURED, 'Monitoring misconfigured',
				'Checks are running but have never concluded up or down.', true);
		}

		$age = time() - strtotime($conclusive . ' UTC');
		$window = self::stale_window($node);
		if ($age > $window) {
			return self::result(self::STATE_STALE, 'Monitoring stale',
				sprintf('No conclusive check for %s (expected every %s).',
					self::humanize($age), self::humanize(self::interval($node))), true);
		}

		return self::result(self::STATE_OK, 'Monitoring OK',
			sprintf('Last conclusive check %s ago.', self::humanize($age)), false);
	}

	/**
	 * What this node's check type needs in order to probe anything, and what
	 * is missing. Returns ['problem' => string] — empty problem means usable.
	 */
	public static function describe_target($node): array {
		$type = self::effective_check_type($node);

		if ($type === 'tcp_port') {
			$host = trim((string)$node->get('mgn_host'));
			$port = (int)$node->get('mgn_uptime_tcp_port');
			if ($host === '') {
				return ['problem' => 'TCP check selected but the node has no host address.'];
			}
			if ($port <= 0 || $port > 65535) {
				return ['problem' => 'TCP check selected but no valid port is set.'];
			}
			return ['problem' => ''];
		}

		if ($type === 'http_status') {
			$url = trim((string)$node->get('mgn_health_check_url'));
			if ($url === '') { $url = trim((string)$node->get('mgn_site_url')); }
			if ($url === '') {
				return ['problem' => 'HTTP check selected but the node has no site URL or health check URL.'];
			}
			return ['problem' => ''];
		}

		// api
		if (trim((string)$node->get('mgn_site_url')) === '') {
			return ['problem' => 'API check selected but the node has no site URL.'];
		}
		return ['problem' => ''];
	}

	/**
	 * Stored type, with the skip-Joinery override applied.
	 *
	 * The override exists because the api check needs a Joinery install to talk
	 * to, so a node flagged as non-Joinery can never satisfy it. It therefore
	 * redirects away from `api` only — an explicitly chosen http_status or
	 * tcp_port is a deliberate statement about how this node proves it is
	 * alive, and overriding that would silently break checks the operator
	 * configured on purpose (a mail relay has no web endpoint to fall back to).
	 */
	public static function effective_check_type($node): string {
		$stored = $node->get('mgn_uptime_check_type') ?: 'http_status';
		if ($stored === 'api' && $node->get('mgn_skip_joinery_checks')) {
			return 'http_status';
		}
		return $stored;
	}

	public static function interval($node): int {
		$i = (int)$node->get('mgn_uptime_interval_seconds');
		return $i > 0 ? $i : 300;
	}

	/**
	 * Did a probe fail because THIS machine could not turn the node's hostname
	 * into an address?
	 *
	 * A monitoring host whose resolver breaks fails every probe at once and
	 * reports the entire fleet down while every node is serving traffic
	 * normally. The probe never reached the node, so it proves nothing about
	 * it; callers treat this as inconclusive rather than as a down result.
	 *
	 * Recognised from two sources. Curl names the condition outright with
	 * CURLE_COULDNT_RESOLVE_HOST / _PROXY, but a resolver that hangs instead of
	 * answering trips the connect timeout first and arrives as a generic
	 * CURLE_OPERATION_TIMEDOUT whose only distinguishing mark is the message
	 * ("Resolving timed out after..."). Socket probes have no error number to
	 * offer at all, only getaddrinfo's text. Both are therefore matched on
	 * message as well as number; pass $errno 0 where there is none.
	 */
	public static function is_name_resolution_failure(int $errno, string $message): bool {
		if ($errno === self::CURLE_COULDNT_RESOLVE_PROXY || $errno === self::CURLE_COULDNT_RESOLVE_HOST) {
			return true;
		}
		$haystack = strtolower($message);
		foreach (self::RESOLUTION_FAILURE_MARKERS as $marker) {
			if (strpos($haystack, $marker) !== false) {
				return true;
			}
		}
		return false;
	}

	private static function stale_window($node): int {
		return max(self::STALE_MINIMUM_SECONDS,
			self::interval($node) * self::STALE_INTERVAL_MULTIPLE);
	}

	/**
	 * Evaluate every enabled node and return only those needing attention.
	 * Used by the dashboard to surface broken monitoring where it is seen.
	 */
	public static function problems(): array {
		$nodes = new MultiManagedNode(['deleted' => false], ['mgn_name' => 'ASC'], 1000, 0);
		$nodes->load();

		$problems = [];
		foreach ($nodes as $node) {
			$health = self::evaluate($node);
			if (!$health['is_problem']) { continue; }
			$problems[] = [
				'node'   => $node,
				'slug'   => $node->get('mgn_slug'),
				'name'   => $node->get('mgn_name'),
				'id'     => $node->key,
				'health' => $health,
			];
		}
		return $problems;
	}

	/**
	 * Backup recovery problems, in the same shape as problems() so the dashboard
	 * renders them identically. A backup you cannot restore is as silent as
	 * monitoring that cannot alert, so it is surfaced the same way.
	 *
	 * Two things can be wrong:
	 *   - recovery was never set up, in which case no node can take an encrypted
	 *     backup at all. Per-node key problems no longer exist — each backup
	 *     seals its own key to the recovery key as it is made, so a node holds
	 *     nothing that can go missing.
	 *   - the agent signing key (the fleet trust root) exists but this site has
	 *     never completed a whole-site backup. The key's only offsite copy is
	 *     inside this site's own encrypted project archive — that is the design,
	 *     replacing the old standalone recovery record — so until one such
	 *     backup is confirmed offsite, losing this machine loses the trust root
	 *     and every fleet agent must be re-keyed by hand.
	 *
	 * The state comes from BackupRecoveryKey::setup_state(), so the dashboard,
	 * the walkthrough, and the node Backups tab cannot disagree about what is
	 * outstanding.
	 */
	public static function backup_recovery_problems(): array {
		require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

		$setup = BackupRecoveryKey::setup_state();
		if (!$setup['is_ready']) {
			return [[
				'node'   => null,
				'slug'   => 'management-node',
				'name'   => 'Management node',
				'id'     => 0,
				'link'   => BackupRecoveryKey::SETUP_URL,
				'health' => self::result('recovery', 'Backup key recovery not set up',
					BackupRecoveryKey::outstanding_summary($setup)
					. ' Encrypted backups do not run until it is set up.', true),
			]];
		}

		$problems = [];
		if (is_file(PathHelper::getSiteRoot() . '/config/agent_signing_key')
			&& !self::has_offsite_project_backup()) {
			$problems[] = [
				'node'   => null,
				'slug'   => 'management-node',
				'name'   => 'Management node',
				'id'     => 0,
				'link'   => '/admin/admin_backups',
				'health' => self::result('recovery', 'Fleet trust root not yet backed up',
					'The agent signing key lives only in config/ on this machine, and its offsite copy '
					. 'is this site\'s own whole-site backup — which has never completed. Until one is '
					. 'confirmed offsite, losing this machine loses the fleet trust root.', true),
			];
		}
		return $problems;
	}

	/** Has this site ever confirmed a whole-site backup in the bucket? */
	private static function has_offsite_project_backup(): bool {
		try {
			require_once(PathHelper::getIncludePath('data/backup_history_class.php'));
			// This site's OWN backups. A copy some other management node took of
			// this machine is sealed to that party's key and lives on its shelf,
			// so it is not evidence that the trust root here is recoverable.
			$rows = new MultiBackupHistory(
				array('type' => 'project', 'outcome' => 'success', 'offsite' => true, 'deleted' => false,
				      'profile' => BackupProfile::SITE),
				array('bkh_start_time' => 'DESC'), 1, 0);
			$rows->load();
			foreach ($rows as $r) { return true; }
		} catch (\Throwable $e) {
			// An unreadable history must not paint a false problem row.
			error_log('NodeMonitorHealth: backup history check failed: ' . $e->getMessage());
			return true;
		}
		return false;
	}

	/**
	 * Nodes whose backups THIS management node takes are not working.
	 *
	 * The alarm is "my backups of this node are broken", not "this node is
	 * unprotected". Whether a site also backs itself up is that site's business,
	 * under its own key, and this management node is not in a position to judge it:
	 * a site taking no copies of its own is exercising a choice, and one taking
	 * plenty is no reason to stop taking mine.
	 *
	 * A node with fleet backups switched off produces nothing here either. What
	 * stops a node falling through unnoticed is the DEFAULT — fleet backups are
	 * on for a node nobody has decided about — not a detector for indecision.
	 */
	public static function fleet_backup_problems(): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));

		$problems = [];
		// The same eligibility list the scheduler dispatches from — shared so a
		// node this monitor watches is always a node that scheduler could reach.
		foreach (FleetBackupPolicy::eligible_nodes() as $node) {
			$policy = FleetBackupPolicy::for_node($node);
			if (empty($policy['enabled'])) continue;   // somebody's decision

			$health = self::fleet_backup_health($node, $policy);
			if (!$health['is_problem']) continue;

			$problems[] = [
				'node'   => $node,
				'slug'   => $node->get('mgn_slug'),
				'name'   => $node->get('mgn_name'),
				'id'     => $node->key,
				'link'   => '/admin/server_manager/node_detail?mgn_id=' . (int)$node->key . '&tab=backups',
				'health' => $health,
			];
		}
		return $problems;
	}

	/**
	 * Does this refusal mean the node can no longer verify its own scripts?
	 *
	 * The agent refuses a script primitive when it cannot prove the file it is
	 * about to run as root is the file the publisher signed. Two very different
	 * things produce that refusal, and reading them as one is the mistake worth
	 * designing against:
	 *
	 *   - THE MANIFEST is missing, unsigned, unparseable, or signed by a key this
	 *     agent does not carry. Nothing is known about any file, so everything is
	 *     refused. Delivering a correct manifest fixes it.
	 *   - A FILE does not match its signed hash, or is not listed at all. The
	 *     manifest is fine and is doing its job: the file on disk is not the file
	 *     that was published. Delivering a manifest would paper over exactly the
	 *     event the check exists to catch.
	 *
	 * Returns NULL for any other refusal — a node declining a primitive it does
	 * not carry, a policy refusal — which must not colour the node red for this.
	 *
	 * Matching is on the agent's own wording. That is a coupling, and the honest
	 * failure mode of getting it wrong is a state that is not reported, never one
	 * that is reported falsely: an unmatched refusal is simply not a trust event.
	 */
	public static function classify_script_trust(string $reason): ?string {
		if ($reason === '') { return null; }

		// A modified or unlisted file. Checked FIRST: this wording also travels
		// inside a refusal that mentions verification, and mistaking it for a
		// manifest problem would recommend the one remedy that must not be
		// applied to it.
		if (stripos($reason, 'has been modified since release') !== false
			|| stripos($reason, 'does not match its signed hash') !== false
			|| stripos($reason, 'is not in the signed release manifest') !== false) {
			return 'untrusted_file';
		}

		// The manifest itself could not be used. Both the "no manifest at all"
		// and the "signature does not verify" wordings carry this phrase.
		if (stripos($reason, 'can be verified before running as root') !== false) {
			return 'untrusted_manifest';
		}

		return null;
	}

	/**
	 * Record what a finished job says about this node's ability to run scripts.
	 *
	 * Called on every terminal agent result, so the state is current the moment a
	 * node refuses rather than the next time somebody reads a job.
	 *
	 * CLEARING keys on the job type that refused, not on a list of which
	 * primitives are script-backed. The plane holds no such list and must not
	 * invent one — it does not get to guess a node vocabulary (the first
	 * apply_update rollout is the standing lesson). A job type that once refused
	 * here on trust grounds, completing now, is the node's own evidence that it
	 * can verify scripts again, and that evidence needs no list to read.
	 */
	public static function note_script_trust($node, $job): void {
		if (!$node || !$node->key) { return; }
		$outcome = (string)$job->get('mjb_agent_outcome');
		$type    = (string)$job->get('mjb_job_type');

		if ($outcome === 'refused') {
			$state = self::classify_script_trust((string)$job->get('mjb_error_message'));
			if ($state === null) { return; }
			// Keep the first sighting: how long a node has been unmanageable is
			// the number that makes it urgent, and re-stamping it on every
			// nightly refusal would report every such node as new today.
			if ((string)$node->get('mgn_script_trust') !== $state) {
				$node->set('mgn_script_trust_since', gmdate('Y-m-d H:i:s'));
			}
			$node->set('mgn_script_trust', $state);
			$node->set('mgn_script_trust_reason', (string)$job->get('mjb_error_message'));
			$node->set('mgn_script_trust_job_type', $type);
			$node->save();
			return;
		}

		if ($outcome !== 'completed') { return; }
		$current = (string)$node->get('mgn_script_trust');
		if ($current === '' || $current === 'ok') { return; }

		// Only this node's own history clears it, and only a job type that has
		// actually refused here on trust grounds. A completed ssl_probe proves
		// nothing about script verification.
		if (!self::type_ever_trust_refused((int)$node->key, $type)) { return; }

		$node->set('mgn_script_trust', 'ok');
		$node->set('mgn_script_trust_since', null);
		$node->set('mgn_script_trust_reason', '');
		$node->set('mgn_script_trust_job_type', '');
		$node->save();
	}

	/**
	 * Record what a node says about itself on its poll.
	 *
	 * Distinct from note_script_trust(), which reads a refusal. This is the node
	 * volunteering the answer, which covers the case a refusal cannot: a node
	 * that is refusing but has no job dispatched to it never produces one.
	 *
	 * A node's own account of itself is the better evidence and wins, in both
	 * directions — including clearing a state set from an old refusal, which is
	 * the node saying it can verify scripts again without having to be sent a
	 * job of the right type first. Only the caller decides whether it was said
	 * at all; an absent answer never reaches here.
	 *
	 * Does not save. The claim handler saves the node once, after folding
	 * everything the poll reported.
	 */
	public static function note_reported_script_trust($node, string $reported): void {
		if (!$node || !$node->key) { return; }
		if (!in_array($reported, ['ok', 'untrusted_manifest', 'untrusted_file'], true)) { return; }

		$current = (string)$node->get('mgn_script_trust');
		if ($reported === 'ok') {
			if ($current === '' || $current === 'ok') { return; }
			$node->set('mgn_script_trust', 'ok');
			$node->set('mgn_script_trust_since', null);
			$node->set('mgn_script_trust_reason', '');
			$node->set('mgn_script_trust_job_type', '');
			return;
		}

		if ($current !== $reported) {
			$node->set('mgn_script_trust_since', gmdate('Y-m-d H:i:s'));
			$node->set('mgn_script_trust', $reported);
			// Its own report carries no wording. A reason already recorded from
			// a refusal is the more useful of the two and is kept; otherwise say
			// plainly where this came from rather than leaving it blank.
			if (trim((string)$node->get('mgn_script_trust_reason')) === '') {
				$node->set('mgn_script_trust_reason',
					'The node reported on its poll that it cannot verify its own scripts.');
			}
		}
	}

	/** Has this job type ever refused on this node for a trust reason? */
	private static function type_ever_trust_refused(int $node_id, string $type): bool {
		if ($node_id <= 0 || $type === '') { return false; }
		$db = DbConnector::get_instance()->get_db_link();
		$sql = 'SELECT mjb_error_message FROM mjb_management_jobs
				WHERE mjb_mgn_node_id = ? AND mjb_job_type = ? AND mjb_agent_outcome = ?
				ORDER BY mjb_id DESC LIMIT 50';
		$stmt = $db->prepare($sql);
		$stmt->execute([$node_id, $type, 'refused']);
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $message) {
			if (self::classify_script_trust((string)$message) !== null) { return true; }
		}
		return false;
	}

	/**
	 * Nodes that can no longer run script primitives, in the shape problems()
	 * returns so the dashboard renders them beside everything else.
	 *
	 * This is its own surface rather than a line on the backup card because it is
	 * not a backup problem. Backups are one of the things it breaks; upgrades,
	 * certificate provisioning and restores are the others, and a node in this
	 * state cannot be repaired through the agent at all.
	 */
	public static function script_trust_problems(): array {
		$nodes = new MultiManagedNode(['deleted' => false], ['mgn_name' => 'ASC'], 1000, 0);

		$problems = [];
		foreach ($nodes as $node) {
			$state = (string)$node->get('mgn_script_trust');
			if ($state !== 'untrusted_manifest' && $state !== 'untrusted_file') { continue; }
			$problems[] = [
				'node'   => $node,
				'slug'   => $node->get('mgn_slug'),
				'name'   => $node->get('mgn_name'),
				'id'     => $node->key,
				'link'   => '/admin/server_manager/node_detail?mgn_id=' . (int)$node->key,
				'health' => self::script_trust_health($node),
			];
		}
		return $problems;
	}

	/** Where one node's script trust stands, phrased for someone who has to act. */
	public static function script_trust_health($node): array {
		$state = (string)$node->get('mgn_script_trust');
		if ($state !== 'untrusted_manifest' && $state !== 'untrusted_file') {
			return self::result('ok', 'Scripts verify', '', false);
		}

		$since = $node->get('mgn_script_trust_since');
		$for   = '';
		if ($since) {
			$age = time() - strtotime($since . ' UTC');
			if ($age > 0) { $for = ' for ' . self::humanize($age); }
		}

		$reason = trim((string)$node->get('mgn_script_trust_reason'));
		$type   = (string)$node->get('mgn_script_trust_job_type');

		if ($state === 'untrusted_file') {
			return self::result('script_trust', 'A file on this node does not match the release' . $for,
				'The signed manifest is good and a file on disk does not match it, so the agent will '
				. 'not run it as root. This is not fixed by re-delivering a manifest — find out why the '
				. 'file differs before anything else.'
				. ($type !== '' ? ' First seen refusing: ' . $type . '.' : '')
				. ($reason !== '' ? ' The node said: ' . $reason : ''), true);
		}

		return self::result('script_trust', 'This node can no longer be managed' . $for,
			'Its agent cannot verify the scripts it would run as root, so it refuses every script '
			. 'primitive — upgrades and backups included. It cannot repair itself through the agent, '
			. 'because the upgrade that would fix it is refused by the same check.'
			. ($type !== '' ? ' First seen refusing: ' . $type . '.' : '')
			. ($reason !== '' ? ' The node said: ' . $reason : ''), true);
	}

	/** Where one node's fleet backups stand. */
	public static function fleet_backup_health($node, array $policy): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryKeyFleet.php'));

		$last    = $node->get('mgn_last_backup_time');
		$outcome = (string)$node->get('mgn_last_backup_outcome');

		// Asked first, and without a grace period. Every backup seals to the
		// recovery key the NODE holds and has proven — nothing is supplied from
		// here — so a node without one is not a node whose backups are late. It
		// is a node whose backups will never run, and it should read that way
		// from the first pass rather than as "never backed up" two days later,
		// which invites someone to wait.
		$rk = RecoveryKeyFleet::node_state($node);
		if ($rk['state'] !== 'n/a' && !RecoveryKeyFleet::has_own_key($rk)) {
			return self::result('backups', 'Cannot be backed up: no verified recovery key on the node',
				RecoveryKeyFleet::blocker_summary($rk), $rk['state'] !== 'unknown');
		}

		if (!$last) {
			// Never yet, which is normal for the first few hours of a node's life
			// and a real problem after that. The slot is at most a day away, so a
			// grace of two intervals distinguishes the two without a flag.
			$age = strtotime((string)$node->get('mgn_create_time') . ' UTC');
			if ($age !== false && (time() - $age) < (2 * 86400)) {
				return self::result('backups', 'No backup yet',
					'This node has not been backed up from here yet. Its first run is scheduled for '
					. FleetBackupPolicy::slot_time($policy, (string)$node->get('mgn_slug')) . '.', false);
			}
			return self::result('backups', 'Never backed up',
				'This node has been managed for more than two days and no backup taken from here has '
				. 'ever completed.', true);
		}

		$age = time() - strtotime($last . ' UTC');
		$window = ($policy['frequency'] === 'weekly') ? (9 * 86400) : (2 * 86400);

		if ($outcome === 'warning') {
			// The node kept the run but does not vouch for it: its files archive
			// was a tenth the size of the previous full, or smaller. Not a green
			// tick, and not "failed" either — the archive exists. Said in the
			// node's own words, which name both sizes.
			$summary = isset($node->key) && $node->key
				? self::backup_run_summary(self::backup_runs_from_here((int)$node->key))
				: self::backup_run_summary(array());
			$r = self::result('backups', 'Last backup is suspiciously small',
				'The most recent backup taken from here (' . self::humanize($age) . ' ago) completed, but '
				. ($summary['reason'] !== '' ? 'the node said: ' . $summary['reason']
					: 'its files archive was a tenth the size of the previous full, or smaller. Check it before trusting it.'),
				true);
			if (!empty($summary['job_id'])) {
				$r['job_id'] = $summary['job_id'];
			}
			return $r;
		}

		if ($outcome !== 'success') {
			// The stamp says it failed; the runs say since when, how often, and
			// why. A node whose backups have been failing for a month must not
			// read like one that failed once last night, and the reason the node
			// gave is the thing an operator needs in order to fix it.
			$summary = isset($node->key) && $node->key
				? self::backup_run_summary(self::backup_runs_from_here((int)$node->key))
				: self::backup_run_summary(array());
			$r = self::result('backups', 'Last backup failed',
				self::failed_backup_detail($summary, $age), true);
			if (!empty($summary['job_id'])) {
				$r['job_id'] = $summary['job_id'];
			}
			return $r;
		}

		// The node's report and the bucket disagree. The report says the last
		// run succeeded; the shelf — listed from here with this management node's
		// own credential, after that run — holds nothing written since. The
		// shelf is the one witness a compromised or misconfigured node cannot
		// talk into its story, so this is the only check that catches a node
		// lying by omission. An hour of slack absorbs clock skew between the
		// node and the storage provider.
		// Empty columns stay false: strtotime(' UTC') on a bare timezone reads
		// as "now", which would make an empty shelf look freshly written to.
		$checked_raw = trim((string)$node->get('mgn_backup_shelf_checked_time'));
		$newest_raw  = trim((string)$node->get('mgn_backup_shelf_newest_time'));
		$checked = ($checked_raw !== '') ? strtotime($checked_raw . ' UTC') : false;
		$newest  = ($newest_raw !== '')  ? strtotime($newest_raw . ' UTC')  : false;
		$claimed = strtotime($last . ' UTC');
		if ($checked !== false && $claimed !== false && $checked > $claimed
			&& ($newest === false || $newest < $claimed - 3600)) {
			return self::result('backups', 'Backups are not landing',
				'This node reports its backups succeeding, but its shelf was listed '
				. self::humanize(time() - $checked) . ' ago and nothing has actually arrived since the '
				. 'run it reported. The archive either never uploaded or went somewhere else.', true);
		}

		if ($age > $window) {
			return self::result('backups', 'Backups have stopped',
				'The last successful backup from here was ' . self::humanize($age) . ' ago, which is longer '
				. 'than this node\'s schedule allows for.', true);
		}

		return self::result('backups', 'Backed up',
			'Last backup ' . self::humanize($age) . ' ago.', false);
	}

	/** The sentence the card shows for a failing fleet backup, from the run summary. */
	private static function failed_backup_detail(array $summary, $age): string {
		if ($summary['failures'] === 0) {
			return 'The most recent backup taken from here failed (' . self::humanize($age) . ' ago).';
		}
		if ($summary['failures'] === 1) {
			$text = 'The most recent backup taken from here failed (' . self::humanize($age) . ' ago).';
		} else {
			$text = 'Every backup taken from here since ' . self::local($summary['since']) . ' has failed ('
				. $summary['failures'] . ' runs).';
		}
		$text .= $summary['last_success'] !== null
			? ' The last one that worked was ' . self::local($summary['last_success']) . ' ('
				. self::humanize(time() - strtotime($summary['last_success'] . ' UTC')) . ' ago).'
			: ' None taken from here has ever worked.';
		if ($summary['reason'] !== '') {
			$text .= ' The node said: ' . $summary['reason'];
		}
		return $text;
	}

	/** A stored UTC time in the operator's timezone, the way the rest of the admin shows one. */
	private static function local(string $utc): string {
		return LibraryFunctions::convert_time($utc, 'UTC',
			SessionControl::get_instance()->get_timezone(), 'M j, Y g:i A T');
	}

	/**
	 * This management node's backup runs of one node, newest first, as the
	 * rows backup_run_summary() reads: id, outcome, time, message.
	 *
	 * Read from the job table rather than the node's stamp because the stamp
	 * holds one outcome and the question is a history. Each row's outcome is
	 * what process_backup_run recorded — 'success', 'skipped', or a failure —
	 * and a terminal job the sweep has not read yet has no outcome at all.
	 */
	public static function backup_runs_from_here(int $node_id, int $limit = 60): array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			'SELECT mjb_id, mjb_status, mjb_result, mjb_error_message, mjb_completed_time, mjb_create_time '
			. 'FROM mjb_management_jobs '
			. 'WHERE mjb_mgn_node_id = ? AND mjb_job_type = ? AND mjb_delete_time IS NULL '
			. "AND mjb_status IN ('completed', 'failed') "
			. 'ORDER BY mjb_id DESC LIMIT ' . (int)$limit
		);
		$q->execute(array($node_id, 'backup_run'));
		$rows = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$result = is_string($row['mjb_result']) ? json_decode($row['mjb_result'], true) : $row['mjb_result'];
			$outcome = is_array($result) && isset($result['backup_status']) ? (string)$result['backup_status'] : '';
			$message = is_array($result) && !empty($result['message'])
				? (string)$result['message'] : trim((string)$row['mjb_error_message']);
			$time = (string)($row['mjb_completed_time'] ?: $row['mjb_create_time']);
			$rows[] = array(
				'id'      => (int)$row['mjb_id'],
				'outcome' => $outcome,
				'time'    => preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $time, $m) ? $m[1] : $time,
				'message' => $message,
			);
		}
		return $rows;
	}

	/**
	 * What a run history says about the current failure, pure so it can be
	 * asserted directly:
	 *
	 *   failures     consecutive failed runs since the last success
	 *   since        start of the oldest of those runs
	 *   last_success time of the newest run that worked, or null
	 *   reason       the newest failed run's message
	 *   job_id       that run's job, for the link
	 *
	 * Rows are newest first. 'skipped' runs and rows with no outcome (a job the
	 * sweep has not read) are neither success nor failure and are stepped over;
	 * everything else that is not 'success' is a failure, which is exactly how
	 * the node stamp reads them.
	 */
	public static function backup_run_summary(array $rows): array {
		$summary = array('failures' => 0, 'since' => null, 'last_success' => null, 'reason' => '', 'job_id' => null);
		foreach ($rows as $row) {
			$outcome = (string)($row['outcome'] ?? '');
			if ($outcome === '' || $outcome === 'skipped') continue;
			if ($outcome === 'success') {
				$summary['last_success'] = (string)$row['time'];
				break;
			}
			$summary['failures']++;
			$summary['since'] = (string)$row['time'];
			if ($summary['job_id'] === null) {
				$summary['job_id'] = (int)$row['id'];
				$summary['reason'] = trim((string)($row['message'] ?? ''));
			}
		}
		return $summary;
	}

	private static function result($state, $label, $detail, $is_problem): array {
		return ['state' => $state, 'label' => $label, 'detail' => $detail, 'is_problem' => $is_problem];
	}

	/** Rough, readable duration. Public because the node page formats a probed
	 *  machine's service uptime with it, and two spellings of 'up 34 days' on one
	 *  page is how they drift. */
	public static function humanize($seconds): string {
		$seconds = (int)$seconds;
		if ($seconds < 90)    return $seconds . 's';
		if ($seconds < 5400)  return round($seconds / 60) . ' min';
		if ($seconds < 172800) return round($seconds / 3600) . ' hr';
		return round($seconds / 86400) . ' days';
	}
}
