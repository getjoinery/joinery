<?php
/**
 * ManagedNode - A remote Joinery server or container managed by the management node.
 *
 * @version 1.25 - mgn_backup_keep_days: the site's own retention window, reported by its backup runs
 * @version 1.24 - MultiManagedNode option reports_failed_backup: the nodes whose last scheduled
 *                (manager-profile) backup is recorded as failed
 * @version 1.23 - mgn_agent_server_manager: whether Server Manager is active on the node as its agent
 *                last reported at poll (active|inactive); is_management_node() reads it first, the
 *                check_status blob second, because nothing runs check_status routinely
 * @version 1.22 - is_management_node(): the node's own report that Server Manager is active there
 *                (check_status server_manager_active), which is what makes it a node that publishes
 * @version 1.21 - mgn_agent_log_access: the owner's log-access switch as the node last reported it at
 *                poll (on|off), so the Logs action can show the reason before a job is queued
 *                (specs/agent_log_access.md §1)
 * @version 1.20 - MultiManagedNode option reports_failing_recipe: the nodes whose stored recipe list
 *                 says a recipe's check last failed (an entry ending :fail), answered by the database
 * @version 1.19 - MultiManagedNode option reports_failed_units: the nodes whose latest host report
 *                 names a failed unit, answered by the database from the stored JSON
 * @version 1.18 - hosts_site(): whether this node has a Joinery site to ask about. A host node in
 *                 machine posture (the Docker host, a bare box) has none, and every question that
 *                 only a site can answer — its recovery key first — is not put to it
 * @version 1.17 - mgn_agent_recipes: the recipes the node's agent runs on its own clock, each with its
 *                 mode (name:report-only or name:armed), as the agent reported them at its last poll;
 *                 empty for an agent before 1.27.0, which runs none. The plane is told, never tells
 * @version 1.16 - mgn_last_host_report / mgn_last_host_report_time: the machine as the host_report
 *                 observe word last described it (units, jails, sshd posture, an SSH auth-failure
 *                 count, disk, memory, reboot-required, unattended-upgrades), its own column and not
 *                 folded into mgn_last_status_data: the two shapes stay apart (agent_tier1_recipes Q2)
 * @version 1.15 - mgn_backup_verify_time / _level / _outcome / _message: when this node last proved
 *                 one of its backups restorable, stamped from the verify_backup job result and the
 *                 status report; mgn_backup_shelf_problem: what the fleet pass's backup storage check found
 *                 wrong with a backup in backup storage, empty when every backup is whole
 * @version 1.14 - managed_by(): which management node this site's own agent is connected to, from the
 *                 agent_join_state setting; a plane that is another plane's node publishes from there
 * @version 1.13 - self_node(): the management node's record of itself, found by its own site URL.
 *                 The plane pairs to itself so its own work (publishing) is a job of its own agent.
 * @version 1.12 - mgn_script_trust and its companions: whether the node can verify its own scripts,
 *                  recorded on the node instead of being left to be read off individual job failures
 * @version 1.11 - mgn_agent_primitives and mgn_agent_bundle_version: the node reports what it can
 *                 do and which signed script tree it holds, on every claim. A version number is a
 *                 guess about vocabulary; the machine's own list is not
 * @version 1.10 - mgn_allow_console removed: the Console tab it gated is retired (A1). The physical
 *                 column lingers as the pairing-token columns do — nothing reads it, and dropping a
 *                 column is not something a field-spec removal does
 * @version 1.9 - mgn_agent_quiet_time: a node says it is going quiet when its agent is switched
 *                off, so deliberate silence reads differently from a node that broke
 * @version 1.8 - mgn_agent_channel_enabled removed: a connected agent is routed to
 *                unconditionally (hard cutover, owner-set)
 * @version 1.7 - pairing-token columns removed: enrollment is a node-initiated join with no shared
 *                secret (Phase 1.5, A6); pending requests live in ajr_agent_join_requests
 * @version 1.6 - agent channel: node-generated public key (a verifier, never a credential), one-time
 *                pairing token hash + expiry, paired/last-poll stamps, per-node cutover flag
 * @version 1.6 - mgn_backup_shelf_bytes: what the node's backup storage holds, summed from the listing the
 *                retention pass already takes
 * @version 1.5 - mgn_backup_shelf_checked_time / mgn_backup_shelf_newest_time: the bucket's own
 *                testimony about the fleet-backup storage, so a node claiming success while nothing
 *                lands is catchable
 * @version 1.4 - mgn_allow_console: per-node opt-in for the node detail Console tab
 * @version 1.3.4
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ManagedNodeException extends SystemBaseException {}

class ManagedNode extends SystemBase {
	public static $prefix = 'mgn';
	public static $tablename = 'mgn_managed_nodes';
	public static $pkey_column = 'mgn_managed_node_id';

	public static $json_vars = array('mgn_last_status_data', 'mgn_backup_policy', 'mgn_last_host_report');

	protected static $foreign_key_actions = [
		'mgn_mgh_managed_host_id' => ['action' => 'null'],
		'mgn_bkt_backup_target_id' => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'mgn_managed_node_id'                  => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mgn_name'                => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'mgn_slug'                => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'mgn_host'                => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'mgn_ssh_user'            => array('type'=>'varchar(50)', 'is_nullable'=>false, 'default'=>'root'),
		'mgn_ssh_key_path'        => array('type'=>'varchar(500)'),
		'mgn_ssh_port'            => array('type'=>'int4', 'default'=>'22'),
		'mgn_container_name'      => array('type'=>'varchar(100)'),
		'mgn_container_user'      => array('type'=>'varchar(50)'),
		'mgn_web_root'            => array('type'=>'varchar(500)'),
		'mgn_site_url'            => array('type'=>'varchar(500)'),
		'mgn_health_check_url'    => array('type'=>'varchar(500)'),
		'mgn_joinery_version'     => array('type'=>'varchar(20)'),
		'mgn_last_status_check'   => array('type'=>'timestamp(6)'),
		'mgn_last_status_data'    => array('type'=>'jsonb'),
		// The machine, as the host_report observe word last described it, and
		// when. Untrusted input from the node: JobResultProcessor caps every
		// field on intake and the Host card escapes every field on render.
		// Deliberately NOT part of mgn_last_status_data — check_status is the
		// site, host_report is the machine, and the shapes stay apart.
		'mgn_last_host_report'      => array('type'=>'jsonb'),
		'mgn_last_host_report_time' => array('type'=>'timestamp(6)'),
		'mgn_api_public_key'      => array('type'=>'varchar(255)'),
		'mgn_api_secret_key'      => array('type'=>'varchar(255)'),
		'mgn_tls_insecure'        => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'mgn_bkt_backup_target_id' => array('type'=>'int8'),
		'mgn_delete_local_after_upload' => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// Fingerprint of the backup recovery public key this node is holding, as
		// the status check last found it. Stored so the fleet view can show which
		// nodes have the management node's key without reaching out to every node
		// on page load — and so a node holding a key the management node did not
		// put there is visible rather than silently left behind.
		'mgn_backup_recovery_fpr' => array('type'=>'varchar(64)'),

		// This management node's backup policy for this node — the manager profile.
		// A blob rather than a column each because it is read whole, written
		// whole, and every field of it is a preference: enabled, frequency, time
		// window, mode, retention count, full interval, target override.
		//
		// A node with no policy inherits the fleet default, which is ENABLED.
		// That default is what stops a node falling through unnoticed; there is
		// deliberately no detector for "nobody decided about this node", because
		// a node nobody decided about does not exist.
		'mgn_backup_policy'       => array('type'=>'jsonb'),

		// The last manager-profile run, denormalised for sorting and alerting.
		// The authoritative history lives on the node; this is the fleet's copy
		// of the one question a dashboard has to answer without visiting anyone.
		'mgn_last_backup_time'    => array('type'=>'timestamp(6)'),
		'mgn_last_backup_outcome' => array('type'=>'varchar(20)'),

		// Can this node run script primitives at all?
		//
		// The agent verifies every site script against a signed manifest before
		// running it as root. When that verification cannot be done, EVERY script
		// primitive is refused at once — apply_update included, which is what makes
		// the state self-sustaining rather than self-correcting. It is recorded on
		// the node because it is a property of the node, not of the jobs that keep
		// failing because of it: reading it off individual job failures is what let
		// getjoinery sit refused for four days.
		//
		// '' or 'ok'          - nothing has refused on trust grounds
		// 'untrusted_manifest'- the signed manifest is missing, unsigned or signed
		//                       by a key this agent does not carry. RECOVERABLE:
		//                       a correct manifest fixes it.
		// 'untrusted_file'    - a file does not match its signed hash. NOT the same
		//                       problem and NOT recoverable by re-delivering a
		//                       manifest; it means the file on disk is not the file
		//                       that was published.
		// The two are kept apart because the remedies are opposites — see
		// specs/agent_manifest_trust_recovery.md.
		'mgn_script_trust'        => array('type'=>'varchar(24)'),
		'mgn_script_trust_since'  => array('type'=>'timestamp(6)'),
		'mgn_script_trust_reason' => array('type'=>'text'),
		// The job type whose refusal set the state. Clearing keys on it: a later
		// job of a type that once refused on trust grounds and now completes is
		// the node's own proof that it can verify scripts again. The plane holds
		// no list of which primitives are script-backed and must not invent one.
		'mgn_script_trust_job_type' => array('type'=>'varchar(50)'),

		// The bucket's own testimony about this node's backup storage: when this management
		// node last listed it, and the newest object write it saw. Stamped by
		// the scheduler from the retention pass's listing — taken with this
		// management node's credential, never the node's word. Comparing these
		// against the claimed last run is the only check that catches a node
		// reporting success while nothing actually lands.
		// How many days of backups the site keeps, as its last backup run
		// reported it (BACKUP_KEEP_DAYS). The fleet pass deletes this node's
		// copies by it, never keeping fewer than the policy's keep_days minimum.
		// Empty until a run reports it.
		'mgn_backup_keep_days'          => array('type'=>'int4'),
		'mgn_backup_shelf_checked_time' => array('type'=>'timestamp(6)'),
		'mgn_backup_shelf_newest_time'  => array('type'=>'timestamp(6)'),
		// How much this node's backup storage holds, in bytes, as of that same check.
		// Summed from the listing the retention pass already takes rather than
		// measured separately: the pass walks the whole prefix every cycle and
		// the provider returns each object's size, so the figure is free and is
		// taken with the one credential that can see the whole shelf.
		'mgn_backup_shelf_bytes'        => array('type'=>'int8'),
		// What the fleet pass's backup storage check found wrong: an artifact a manifest
		// names that the listing does not hold, or holds at a different size,
		// or a manifest with no envelope. One line of text, empty when every
		// backup in backup storage is whole. Stamped on every pass from the same
		// listing as the three columns above.
		'mgn_backup_shelf_problem'      => array('type'=>'text'),
		// When this node last proved one of its backups restorable, and how:
		// level 2 (opened and read) or 3 (rehearsed), pass or fail, and the
		// node's own words. Stamped from the verify_backup job's result lines
		// and from the status report's backup summary (a verify the site ran
		// itself). A verify that was skipped leaves the time alone and records
		// the reason in the message. The node's history row is the authority;
		// this is the management node's copy.
		'mgn_backup_verify_time'        => array('type'=>'timestamp(6)'),
		'mgn_backup_verify_level'       => array('type'=>'int4'),
		'mgn_backup_verify_outcome'     => array('type'=>'varchar(20)'),
		'mgn_backup_verify_message'     => array('type'=>'text'),
		// Compared against the newest escrow row to detect a manually regenerated
		// (un-escrowed) node key.
		'mgn_enabled'             => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'mgn_skip_joinery_checks' => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// Whether the node detail Console tab may run an ad-hoc command here.
		// Default off: the management node holds SSH keys to every node, so being
		// reachable from a browser form is a decision made per node rather than
		// a property every node acquires the moment it is registered.
		'mgn_mgh_managed_host_id'         => array('type'=>'int8'),
		'mgn_ssl_state'           => array('type'=>'varchar(20)'),
		'mgn_port'                => array('type'=>'int4'),
		'mgn_install_state'       => array('type'=>'varchar(20)'),
		'mgn_notes'               => array('type'=>'text'),
		'mgn_uptime_enabled'              => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'mgn_uptime_check_type'           => array('type'=>'varchar(20)', 'default'=>'http_status', 'is_nullable'=>false),
		// Real cadence for uptime probing. RunNodeUptimeChecks fires every cron
		// pass as a floor, but only probes a node whose interval has elapsed —
		// the same task-floor/per-item pattern PollImapAccounts uses. Keeps
		// probe volume independent of how often cron ticks.
		'mgn_uptime_interval_seconds'     => array('type'=>'int4', 'default'=>'300', 'is_nullable'=>false),
		'mgn_uptime_last_check'           => array('type'=>'timestamp(6)'),
		// Last check that actually concluded up or down. A check that cannot
		// conclude (misconfigured, no target) advances mgn_uptime_last_check but
		// not this, so a node whose monitoring has silently stopped working is
		// detectable instead of looking like one that was simply never checked.
		'mgn_uptime_last_conclusive'      => array('type'=>'timestamp(6)'),
		'mgn_uptime_last_error'           => array('type'=>'varchar(255)'),
		// Port for the tcp_port check type — services with no web endpoint
		// (an inbound mail relay, for example) are proven alive by accepting a
		// TCP connection on the port they exist to serve.
		'mgn_uptime_tcp_port'             => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),
		'mgn_uptime_last_status'          => array('type'=>'varchar(20)'),
		'mgn_uptime_consecutive_failures' => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),
		'mgn_uptime_down_since'           => array('type'=>'timestamp(6)'),
		'mgn_cert_expiry_ts'              => array('type'=>'timestamp(6)'),
		'mgn_cert_alerted_ts'             => array('type'=>'timestamp(6)'),
		// Hardened ingest relay (specs/relay_without_a_shell.md). A relay is a
		// ManagedNode row in the DISPOSABLE posture so it gets the dashboard health
		// dot: mgn_is_relay marks it (no Joinery app runs on it, so the Joinery-app
		// health checks are skipped; no agent, no key path, a tcp/25 probe). Its
		// only acts are Update and Delete on the mailbox Setup tab.
		// ── The agent channel (specs/agent_on_node_architecture.md §3.1) ──
		//
		// The node's agent polls this plane outbound over HTTPS and takes
		// primitive jobs. What is stored here is a VERIFIER and nothing else:
		// mgn_agent_public_key is the public half of a keypair the node
		// generated and kept, so this plane holds nothing that could
		// authenticate AS the node. Compromising this plane yields no
		// credential to steal — which is the whole point of the migration.
		'mgn_agent_public_key'    => array('type'=>'varchar(64)'),

		// Enrollment shares no secret (Phase 1.5, A6): the node initiates a
		// join carrying only its public key, and a human approves it after
		// comparing fingerprints. The pending requests live in
		// ajr_agent_join_requests; the moment of approval is what sets the
		// key above and the time below.
		'mgn_agent_paired_time'        => array('type'=>'timestamp(6)'),
		// When the node last said it was going quiet — an operator switched its
		// agent off there. Distinguishes a deliberate silence from a broken one,
		// which is otherwise indistinguishable from here: both just stop polling.
		// Compared against mgn_agent_last_poll rather than cleared, so a node that
		// comes back needs no second write to look alive again.
		'mgn_agent_quiet_time'         => array('type'=>'timestamp(6)'),

		// Liveness, centrally visible. The agent's own heartbeat row lives in
		// each site's OWN database and stays there; a poll against this plane
		// is the only liveness signal this plane can see for itself, so the
		// last poll IS the heartbeat (§3.1).
		'mgn_agent_last_poll'     => array('type'=>'timestamp(6)'),
		'mgn_agent_version'       => array('type'=>'varchar(20)'),

		// What the agent says it can DO, reported on every claim beside the
		// version. A comma-separated list of primitive names, in the node's own
		// words.
		//
		// The plane must never guess a node's vocabulary, and a version number
		// is a guess: the first apply_update rollout inferred the capability
		// from the version, dispatched to nine agents whose compiled-in
		// vocabulary predated it, and collected nine refusals. A version says
		// which release a machine is running; only the machine says what that
		// release compiled into it.
		//
		// Empty for an agent that predates the report (1.10.0 and earlier),
		// which is what keeps JobCommandBuilder::PRIMITIVE_MIN_AGENT_VERSION a
		// live fallback rather than dead code.
		'mgn_agent_primitives'    => array('type'=>'text'),

		// The recipes the agent runs on its own clock, each with its mode:
		// "fail2ban:report-only", comma-separated and sorted, normalised on
		// intake exactly as the vocabulary is. The plane cannot set, start,
		// stop or arm a recipe — the agent reports, the Host card shows a
		// person. Empty for an agent before 1.27.0, which runs none. From
		// agent 1.33.0 an entry carries what the check last said after the
		// mode (fail2ban:armed:fail), so a failing recipe is visible here
		// and not only in a case.
		'mgn_agent_recipes'       => array('type'=>'text'),

		// Which signed support bundle the machine holds — the tree its script
		// primitives resolve against when it has no site of its own. Empty on
		// every machine that has a site tree, which is every machine that
		// verifies scripts against its own release manifest and needs no
		// bundle. It is the only evidence this plane gets that a bundle it
		// serves actually landed.
		'mgn_agent_bundle_version' => array('type'=>'varchar(32)'),

		// Whether the node's owner lets this plane read its logs, as the
		// agent reported it on its last poll: 'on', 'off', or empty for an
		// agent that has no log words to gate. Reported, never set from here:
		// the switch is on the node's own admin and the node enforces it.
		'mgn_agent_log_access'    => array('type'=>'varchar(8)'),

		// Whether the Server Manager plugin is active on the node, as the agent
		// reported it on its last poll: 'active', 'inactive', or empty for an
		// agent that predates the fact. This is what makes a node a management
		// node (is_management_node()); the check_status report carries the
		// same fact and is the fallback for an agent that polls without it.
		'mgn_agent_server_manager' => array('type'=>'varchar(8)'),

		'mgn_is_relay'            => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'mgn_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mgn_update_time'         => array('type'=>'timestamp(6)'),
		'mgn_delete_time'         => array('type'=>'timestamp(6)'),
	);

	/**
	 * This management node's record of itself, or null when it has none.
	 *
	 * A management node pairs to its own site the way any node does
	 * (`joinery-agent join --management-node=<own URL>`, approved on its own
	 * dashboard), and the row that makes is the one whose site URL is this
	 * site's own. Work the plane does on its own machine — publishing a
	 * release — is dispatched to that row's agent, so the plane never needs
	 * a job queue of its own.
	 */
	/**
	 * Does this node have a Joinery site to ask about?
	 *
	 * A node in machine posture — the Docker host, a bare box the fleet
	 * enrolled for its own housekeeping — has no web root, no settings table
	 * and no recovery key. It answers the machine questions (host_report,
	 * host_converge, load and memory) and none of the site ones. Anything that
	 * would dispatch a site question, or report its absence as a gap, asks
	 * here first; a node whose Joinery checks are switched off is treated the
	 * same way, because the operator has said the site is not to be asked.
	 */
	public function hosts_site(): bool {
		return self::hosts_site_from($this);
	}

	/** The rule hosts_site() applies, over anything that answers get() for the two columns. */
	public static function hosts_site_from($node): bool {
		return trim((string)$node->get('mgn_web_root')) !== ''
			&& !$node->get('mgn_skip_joinery_checks');
	}

	/**
	 * Whether this node is a management node — a site with the Server Manager
	 * plugin active, so it has a Publish page and an upgrades table it serves
	 * releases from. Read from the node's own report — at poll
	 * (mgn_agent_server_manager) first, since every node polls and nothing
	 * runs check_status routinely, then the check_status blob
	 * (server_manager_active) — never inferred: every agent compiles the
	 * publish_upgrade primitive in, so its vocabulary cannot tell a plane from
	 * a plain site. A node that has not reported the fact (an agent that
	 * predates it) is not one, so the publish action is never offered on a
	 * guess; its next poll settles it.
	 */
	public function is_management_node(): bool {
		// The plane's own record: the code answering IS the Server Manager
		// plugin, active here. Nothing to report, and no first-release
		// deadlock — the release that carries the reporting agent is published
		// from this page before any agent has reported.
		if ($this->is_self()) {
			return true;
		}
		$at_poll = (string)$this->get('mgn_agent_server_manager');
		if ($at_poll !== '') {
			return $at_poll === 'active';
		}
		return self::is_management_node_from($this->get('mgn_last_status_data'));
	}

	/** Whether this record is this management node's own — the rule self_node() finds it by. */
	public function is_self(): bool {
		$own_url = rtrim((string)LibraryFunctions::get_absolute_url(), '/');
		$site_url = rtrim(trim((string)$this->get('mgn_site_url')), '/');
		return $own_url !== '' && $site_url === $own_url;
	}

	/** The rule is_management_node() applies, over a status-data array or its JSON. */
	public static function is_management_node_from($status_data): bool {
		if (is_string($status_data)) {
			$status_data = json_decode($status_data, true);
		}
		return is_array($status_data) && ($status_data['server_manager_active'] ?? null) === true;
	}

	/** Whether the node has said anything about Server Manager at all, at poll or in check_status. */
	public function reports_management_status(): bool {
		if ($this->is_self()) {
			return true;
		}
		if ((string)$this->get('mgn_agent_server_manager') !== '') {
			return true;
		}
		$status_data = $this->get('mgn_last_status_data');
		if (is_string($status_data)) {
			$status_data = json_decode($status_data, true);
		}
		return is_array($status_data) && array_key_exists('server_manager_active', $status_data);
	}

	public static function self_node() {
		$own_url = rtrim((string)LibraryFunctions::get_absolute_url(), '/');
		if ($own_url === '') {
			return null;
		}
		foreach ([$own_url, $own_url . '/'] as $url) {
			$nodes = new MultiManagedNode(['mgn_site_url' => $url, 'deleted' => false]);
			foreach ($nodes as $node) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * The management node this site's own agent is connected to, or null when
	 * this site is managed by nobody. Read from the join state the agent
	 * writes for the Management Node page: a site that is another plane's node
	 * cannot also be its own, so its releases are published from that plane's
	 * node detail page, and this is how the Publish page knows to say so.
	 */
	public static function managed_by() {
		$state = json_decode((string)Globalvars::get_instance()->get_setting('agent_join_state'), true);
		return self::managed_by_from(is_array($state) ? $state : null);
	}

	/** The rule managed_by() applies, over the decoded state, so it can be tested without settings. */
	public static function managed_by_from($state) {
		if (!is_array($state) || ($state['status'] ?? '') !== 'connected') {
			return null;
		}
		$url = rtrim(trim((string)($state['url'] ?? '')), '/');
		return $url === '' ? null : $url;
	}

	function prepare() {
		// Normalize slug to lowercase alphanumeric + hyphens
		$slug = strtolower(trim($this->get('mgn_slug')));
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$this->set('mgn_slug', $slug);

		if (empty($slug)) {
			throw new ManagedNodeException('Node slug is required.');
		}

		if (empty($this->get('mgn_name'))) {
			throw new ManagedNodeException('Node name is required.');
		}

		if (empty($this->get('mgn_host'))) {
			throw new ManagedNodeException('SSH host is required.');
		}

		// Check for duplicate slug
		$existing = new MultiManagedNode(array('slug' => $slug, 'deleted' => false));
		$existing->load();
		foreach ($existing as $ex) {
			if ($ex->key != $this->key) {
				throw new ManagedNodeException('A node with this slug already exists.');
			}
		}

		$this->set('mgn_update_time', gmdate('Y-m-d H:i:s'));
	}
}

class MultiManagedNode extends SystemMultiBase {
	protected static $model_class = 'ManagedNode';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		// Nodes whose latest host report names at least one failed unit. The
		// report is stored in the shape sanitise_host_report gives it, where
		// failed_units is a list or the string unknown; a node with no report
		// has a null there and is out.
		if (!empty($this->options['reports_failed_units'])) {
			// Both halves are evaluated whatever the first says, so the second
			// must be safe on a string: a jsonb compare, not an array length.
			$filters["jsonb_typeof(mgn_last_host_report->'failed_units')"] =
				"= 'array' AND mgn_last_host_report->'failed_units' <> '[]'::jsonb";
		}

		// Nodes whose last scheduled backup failed. A warning (kept, but not
		// vouched for) and a run still in flight are not failures.
		if (!empty($this->options['reports_failed_backup'])) {
			$filters['mgn_last_backup_outcome'] = "= 'failed'";
		}

		if (!empty($this->options['reports_failing_recipe'])) {
			// The stored list is canonical (AgentChannelEndpoint::normalised_recipes):
			// name:mode[:verdict], comma-separated, verdicts from a closed set,
			// so "an entry ends in :fail" is the whole question.
			$filters['mgn_agent_recipes'] = "~ ':fail(,|$)'";
		}

		if (isset($this->options['slug'])) {
			$filters['mgn_slug'] = [$this->options['slug'], PDO::PARAM_STR];
		}

		if (isset($this->options['host'])) {
			$filters['mgn_host'] = [$this->options['host'], PDO::PARAM_STR];
		}

		if (isset($this->options['host_id'])) {
			if ($this->options['host_id'] === null) {
				$filters['mgn_mgh_managed_host_id'] = "IS NULL";
			} else {
				$filters['mgn_mgh_managed_host_id'] = [$this->options['host_id'], PDO::PARAM_INT];
			}
		}

		if (isset($this->options['enabled'])) {
			$filters['mgn_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}


		// Use array_key_exists so null values (→ IS NULL) are handled correctly
		if (array_key_exists('ssl_state', $this->options)) {
			$filters['mgn_ssl_state'] = $this->options['ssl_state'] === null
				? "IS NULL"
				: [$this->options['ssl_state'], PDO::PARAM_STR];
		}

		if (array_key_exists('install_state', $this->options)) {
			$filters['mgn_install_state'] = $this->options['install_state'] === null
				? "IS NULL"
				: [$this->options['install_state'], PDO::PARAM_STR];
		}

		return $this->_get_resultsv2('mgn_managed_nodes', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
