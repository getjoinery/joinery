<?php
/**
 * SiteCopyRunner - copy a site onto a new server and keep it dormant there
 * (specs/site_copy.md WP8, steps 1-6, Refresh and Discard).
 *
 * The management node's half of a copy. Every step on a machine is an agent
 * word the machine checks for itself (the quiet state, the vouch, the owner's
 * approval on the source); this class only chooses the next word, queues it,
 * and judges its answer:
 *
 *   preflight         source_refusals(): why this node cannot be copied, if
 *                     it cannot. Read-only.
 *   the new server    start_new_server() has this management node create it
 *                     through the provisioning pipeline (install mode copy);
 *                     start_own_server() records the copy and shows the owner
 *                     the command to run on a server of their own. Either way
 *                     the server installs at the source's exact release, ends
 *                     quiet, and its agent asks to join; approve_join() binds
 *                     it to the copy's node row, in state `copy`.
 *   a copy run        once the copy's agent has reported its words, and again
 *                     at every Copy again: host_report on the copy (does it
 *                     fit?), copy_export on the source (its owner approves on
 *                     the source's own Backups page), copy_import, copy_stage,
 *                     copy_restore on the copy, then site_census on both and
 *                     the comparison (informational: the source is live).
 *   discard           the copy row leaves the dashboard. The server is not
 *                     deleted: the platform never deletes a cloud instance, so
 *                     the page names it for its owner to delete at the provider.
 *
 * advance() is called by the AdvanceSiteCopies task every tick, and by
 * job_finished() when a node posts a copy step's result (the Copy tab only
 * reads, so a page view writes nothing). A step that passes queues the next in
 * the same call, so a run moves as fast as its jobs finish. It saves every
 * state it moves to before the next, and holds an advisory lock so two movers
 * never race. A crash between calls resumes where it stood.
 *
 * Switching over (steps 7-10) is not here: it arrives with the first way to
 * move a site's address (WP7a). Its two pieces that are not about the address
 * are built: the node-id word (take_node_id) and the row swap (SiteCopySwap).
 *
 * @version 1.2 - a passed step queues the next in the same call, and job_finished() advances a copy when its
 *                 step's result arrives: a run took two task ticks per step (site_copy.md B40)
 * @version 1.1 - the no-site-address refusal says a status check fills it (site_copy.md B38)
 * @version 1.0
 */
class SiteCopyRunner {

	/** The words the source must report: what a copy asks of it. */
	const SOURCE_WORDS = array('copy_export', 'site_census');

	/** The words the copy must report before a run starts. */
	const COPY_WORDS = array('host_report', 'copy_import', 'copy_stage', 'copy_restore', 'site_census');

	/** How old the source's newest backup may be when a run starts. */
	const BACKUP_MAX_AGE_HOURS = 24;

	/**
	 * How long each step may take, from queueing, before the run stops on it.
	 * The export holds the source owner's approval window inside its claim.
	 */
	const STEP_WAIT_MINUTES = array(
		'host_report'  => 20,
		'copy_export'  => 90,
		'copy_import'  => 20,
		'copy_stage'   => 160,
		'copy_restore' => 160,
		'site_census'  => 30,
	);

	/** The steps of one run, in order. */
	const RUN_STEPS = array(
		array('op' => 'host_report',  'on' => 'copy'),
		array('op' => 'copy_export',  'on' => 'source'),
		array('op' => 'copy_import',  'on' => 'copy'),
		array('op' => 'copy_stage',   'on' => 'copy'),
		array('op' => 'copy_restore', 'on' => 'copy'),
		array('op' => 'site_census',  'on' => 'source'),
		array('op' => 'site_census',  'on' => 'copy'),
	);

	/** The advisory lock every mover takes. */
	const LOCK_KEY = 7720231001;

	/** Headroom the copy keeps free after a run, in bytes. */
	const DISK_HEADROOM = 2147483648;

	/**
	 * Lists a node's chains: BackupChainListHelper::for_node when null. A
	 * variable only so a test can stand in for backup storage; nothing in
	 * production sets it.
	 *
	 * @var callable|null
	 */
	public static $chain_lister = null;

	/**
	 * Builds the copy_stage job: JobCommandBuilder::build_copy_stage when null,
	 * which signs the chain's links against backup storage. A variable for the
	 * reason $chain_lister is one; nothing in production sets it.
	 *
	 * @var callable|null
	 */
	public static $stage_builder = null;

	// ── Preflight ──────────────────────────────────────────────────────────

	/**
	 * Why this node cannot be copied now: one line per reason, empty when it
	 * can. Read-only; nothing here asks the node anything.
	 *
	 * @param bool $for_new_copy also refuse when the node already has a copy
	 */
	public static function source_refusals(ManagedNode $source, bool $for_new_copy = true): array {
		$why = array();
		if ($source->get('mgn_delete_time')) {
			return array('It has been removed from the dashboard.');
		}
		if (!$source->hosts_site()) {
			return array('It hosts no Joinery site.');
		}
		if (trim((string)$source->get('mgn_container_name')) !== '') {
			$why[] = 'It is a container site. A copy is bare metal to bare metal, one site per machine; container '
				. 'sites move with the switch by DNS, which is not built yet.';
		}
		if (!$source->is_operational()) {
			$why[] = 'It is not a working site (' . $source->install_state_label() . ').';
		}
		if ($source->is_management_node()) {
			$why[] = 'It is a management node. A management node carries the fleet\'s control and is never copied.';
		}
		$version = trim((string)$source->get('mgn_joinery_version'));
		if ($version === '') {
			$why[] = 'Its release is not known yet. Run a status check on it.';
		} elseif (version_compare($version, JobCommandBuilder::COPY_SOURCE_MIN_VERSION, '<')) {
			$why[] = "It runs release {$version}. A copy needs " . JobCommandBuilder::COPY_SOURCE_MIN_VERSION
				. ' or newer, because the copy installs the source\'s own release. Update it first.';
		}
		$missing = array();
		foreach (self::SOURCE_WORDS as $word) {
			if (!JobCommandBuilder::has_primitive($source, $word)) {
				$missing[] = $word;
			}
		}
		if ($missing) {
			$why[] = AgentVocabulary::needs_newer_agent_text($source, $missing);
		}
		$key = RecoveryKeyFleet::node_state($source);
		if (!RecoveryKeyFleet::has_own_key($key)) {
			$why[] = 'Its backups cannot run: ' . RecoveryKeyFleet::blocker_summary($key);
		}
		if (!JobCommandBuilder::get_target($source)) {
			$why[] = 'This management node backs it up nowhere. A copy is made from the backups this management '
				. 'node takes of it; a site that backs up only to a bucket of its own cannot be copied yet.';
		}
		if (!preg_match('#^/var/www/html/[A-Za-z0-9][A-Za-z0-9_-]*/public_html$#', rtrim((string)$source->get('mgn_web_root'), '/'))) {
			$why[] = 'Its web root is not /var/www/html/<site>/public_html, the only layout a copy installs.';
		}
		if (self::site_domain($source) === '') {
			$why[] = 'It has no https site address on record, and the copy is installed under its domain. '
				. 'Its next status check fills it from the site\'s own config (agent 1.51.0 or later); '
				. 'or set the Site URL in the node\'s connection settings on its Overview tab.';
		}
		if ($for_new_copy) {
			if (SiteCopy::live_for_source((int)$source->key)) {
				$why[] = 'It already has a copy. Discard that one first.';
			} else {
				// A copy row made any other way still holds this site's copy.
				foreach (new MultiManagedNode(array('copy_of_node_id' => (int)$source->key, 'deleted' => false)) as $row) {
					if (trim((string)$row->get('mgn_install_state')) === 'copy') {
						$why[] = "It already has a dormant copy, '{$row->get('mgn_name')}' (node #{$row->key}). Remove that node first.";
						break;
					}
				}
			}
		}
		return $why;
	}

	/** The source's site directory name: what the copy is installed under. */
	public static function site_name(ManagedNode $source): string {
		return basename(dirname(rtrim((string)$source->get('mgn_web_root'), '/')));
	}

	/** The source's domain, from its https site address, or ''. */
	public static function site_domain(ManagedNode $source): string {
		$url = trim((string)$source->get('mgn_site_url'));
		if (!preg_match('#^https://#i', $url)) {
			return '';
		}
		$host = strtolower((string)parse_url($url, PHP_URL_HOST));
		return preg_match('/^[a-z0-9.-]+$/', $host) ? $host : '';
	}

	/**
	 * The source's newest chain among this management node's backups of it,
	 * with the time of its newest run. Throws when there is none, the listing
	 * fails, or it is older than BACKUP_MAX_AGE_HOURS.
	 *
	 * @return array{chain_id:string, time:string, bytes:int, files:int, db:int}
	 */
	public static function newest_chain(ManagedNode $source): array {
		$list = self::$chain_lister ? (self::$chain_lister)($source) : BackupChainListHelper::for_node($source);
		if (!empty($list['error'])) {
			throw new SiteCopyException('The source\'s backups could not be listed: ' . $list['error']);
		}
		foreach ($list['chains'] as $chain) {
			if (($chain['profile'] ?? '') !== BackupProfile::MANAGER || empty($chain['runs'])) {
				continue;
			}
			$newest = '';
			$files = 0;
			$db = 0;
			foreach ($chain['runs'] as $run) {
				if ((string)$run['time'] > $newest) {
					$newest = (string)$run['time'];
				}
				$files += (int)($run['artifacts']['files'] ?? 0);
				$db += (int)($run['artifacts']['db'] ?? 0);
			}
			$when = strtotime($newest);
			if (!$when || time() - $when > self::BACKUP_MAX_AGE_HOURS * 3600) {
				throw new SiteCopyException('The source\'s newest backup is from ' . ($newest ?: 'an unknown time')
					. ', more than ' . self::BACKUP_MAX_AGE_HOURS . ' hours ago. Run a backup of it from its Backups tab, then copy.');
			}
			return array('chain_id' => (string)$chain['chain_id'], 'time' => $newest,
				'bytes' => (int)$chain['bytes'], 'files' => $files, 'db' => $db);
		}
		throw new SiteCopyException('This management node holds no backup of the source to copy from. Run a backup '
			. 'of it from its Backups tab, then copy.');
	}

	// ── Starting a copy ────────────────────────────────────────────────────

	/**
	 * Have this management node create the copy's server: a provision in
	 * install mode copy, at the source's release, on the operator's cloud
	 * account (account 'operator') or a connected one (its id).
	 *
	 * @param array $opts account, region, type
	 */
	public static function start_new_server(ManagedNode $source, array $opts, $user_id): SiteCopy {
		self::refuse_start($source);
		$account = (string)($opts['account'] ?? '');
		$region = trim((string)($opts['region'] ?? ''));
		$type = trim((string)($opts['type'] ?? ''));
		if (!preg_match('/^[a-z0-9-]{2,40}$/', $region) || !preg_match('/^[a-z0-9-]{2,60}$/', $type)) {
			throw new SiteCopyException('Choose the new server\'s region and type.');
		}

		$provision = new CustomerCloudProvision(NULL);
		if ($account === 'operator') {
			if (ProvisionCustomerCloud::operator_compute_token() === '') {
				throw new SiteCopyException('No operator cloud token is set. Set it on the Provisioning Setup page, '
					. 'or choose a connected cloud account.');
			}
			$provision->set('cvp_hosting_mode', 'operator');
			$provision->set('cvp_usr_user_id', (int)$user_id);
			$provision->set('cvp_provider', 'linode');
		} else {
			$cca = new CustomerCloudAccount((int)$account, TRUE);
			if (!$cca->key || $cca->get('cca_status') !== 'active' || $cca->get('cca_delete_time')) {
				throw new SiteCopyException('Choose an active connected cloud account.');
			}
			if (CustomerCloudAccount::grant_expired($cca)) {
				throw new SiteCopyException('That account\'s cloud grant has expired. Re-connect it, then try again.');
			}
			$provision->set('cvp_cca_customer_cloud_account_id', (int)$cca->key);
			$provision->set('cvp_usr_user_id', (int)$cca->get('cca_usr_user_id'));
			$provision->set('cvp_provider', $cca->get('cca_provider'));
		}
		$provision->set('cvp_origin', 'admin');
		$provision->set('cvp_domain', self::site_domain($source));
		$provision->set('cvp_slug', AgentChannelEndpoint::freeSlug($source->get('mgn_slug') . '-copy'));
		$provision->set('cvp_sitename', self::site_name($source));
		$provision->set('cvp_status', 'ready');
		$provision->set('cvp_region', $region);
		$provision->set('cvp_instance_type', $type);
		$provision->set('cvp_docker_mode', 'bare-metal');
		$provision->set('cvp_install_mode', 'copy');
		$provision->set('cvp_source_node_id', (int)$source->key);
		$provision->set('cvp_release', (string)$source->get('mgn_joinery_version'));
		$provision->prepare();
		$provision->save();

		$copy = self::new_record($source, $user_id);
		$copy->set('scp_cvp_customer_cloud_provision_id', (int)$provision->key);
		$copy->save();
		return $copy;
	}

	/** Record a copy onto a server the owner brings; the page shows them the command. */
	public static function start_own_server(ManagedNode $source, $user_id): SiteCopy {
		self::refuse_start($source);
		$copy = self::new_record($source, $user_id);
		$copy->save();
		return $copy;
	}

	private static function refuse_start(ManagedNode $source): void {
		$why = self::source_refusals($source);
		if ($why) {
			throw new SiteCopyException('This site cannot be copied: ' . implode(' ', $why));
		}
		self::newest_chain($source);
	}

	private static function new_record(ManagedNode $source, $user_id): SiteCopy {
		$copy = new SiteCopy(NULL);
		$copy->set('scp_source_node_id', (int)$source->key);
		$copy->set('scp_release', (string)$source->get('mgn_joinery_version'));
		$copy->set('scp_status', SiteCopy::STATUS_WAITING);
		$copy->set('scp_created_by', $user_id ?: null);
		return $copy;
	}

	/**
	 * The commands the owner runs, as root, on a fresh Ubuntu server of their
	 * own: fetch the source's exact release from this management node, install
	 * the server prerequisites, then a dormant site under the source's site
	 * name and domain that records the source's node id and agent key and asks
	 * to join here.
	 */
	public static function owner_command(SiteCopy $copy): string {
		$source = new ManagedNode((int)$copy->get('scp_source_node_id'), TRUE);
		$plane = self::plane_url();
		$release = (string)$copy->get('scp_release');
		$key = trim((string)$source->get('mgn_agent_public_key'));
		$lines = array(
			'mkdir -p /root/joinery-copy && cd /root/joinery-copy',
			'curl -sL ' . escapeshellarg($plane . '/utils/latest_release?version=' . $release) . ' | tar xz',
			'cd maintenance_scripts/install_tools',
			'POSTGRES_PASSWORD=$(openssl rand -base64 18 | tr -d \'/+=\' | head -c 24)',
			'echo "$POSTGRES_PASSWORD" > /root/.joinery_postgres_password && chmod 600 /root/.joinery_postgres_password',
			'POSTGRES_PASSWORD="$POSTGRES_PASSWORD" ./install.sh -y -q server',
			'./install.sh -y -q site --bare-metal ' . escapeshellarg(self::site_name($source))
				. ' --password-file=/root/.joinery_postgres_password ' . escapeshellarg(self::site_domain($source))
				. ' --enable-agent --management-node=' . escapeshellarg($plane) . ' --upgrade-server=' . escapeshellarg($plane)
				. ' --dormant --copy-of=' . (int)$source->key . ' --copy-of-key=' . escapeshellarg($key),
		);
		return implode("\n", $lines);
	}

	private static function plane_url(): string {
		$webdir = (string)Globalvars::get_instance()->get_setting('webDir');
		return 'https://' . preg_replace('#^https?://#', '', rtrim($webdir, '/'));
	}

	// ── The copy's join ────────────────────────────────────────────────────

	/**
	 * Join requests that could be this copy's server: pending, from an agent
	 * that names itself after the source's site (a site's agent claims its
	 * site directory's name).
	 */
	public static function candidate_joins(SiteCopy $copy): array {
		if ($copy->status() !== SiteCopy::STATUS_WAITING) {
			return array();
		}
		$row = self::copy_row($copy, false);
		if ($row && trim((string)$row->get('mgn_agent_public_key')) !== '') {
			return array();
		}
		$source = new ManagedNode((int)$copy->get('scp_source_node_id'), TRUE);
		$name = self::site_name($source);
		$out = array();
		foreach (AgentJoinRequest::pending() as $request) {
			if ((string)$request->get('ajr_claimed_name') === $name) {
				$out[] = $request;
			}
		}
		return $out;
	}

	/**
	 * Bind a join request to this copy: onto the row the provisioning made for
	 * a server this management node created (after the provider confirms the
	 * instance runs at the join's address), or onto a new row in state `copy`
	 * for a server the owner brought. The owner compared the fingerprint with
	 * the copy's own install output before pressing the button.
	 */
	public static function approve_join(SiteCopy $copy, AgentJoinRequest $request): ManagedNode {
		if ($copy->status() !== SiteCopy::STATUS_WAITING) {
			throw new SiteCopyException('This copy is not waiting for its server.');
		}
		if ($request->is_expired() || $request->get('ajr_status') !== AgentJoinRequest::STATUS_PENDING) {
			throw new SiteCopyException('That join request is no longer pending. Send it again from the new server.');
		}
		$source = new ManagedNode((int)$copy->get('scp_source_node_id'), TRUE);
		if ((string)$request->get('ajr_claimed_name') !== self::site_name($source)) {
			throw new SiteCopyException('That join request does not come from a site named ' . self::site_name($source) . '.');
		}
		if ((string)$request->get('ajr_public_key') === (string)$source->get('mgn_agent_public_key')) {
			throw new SiteCopyException('That join request carries the source\'s own key; a copy has a key of its own.');
		}

		$node = self::copy_row($copy, false);
		if ($node) {
			if (trim((string)$node->get('mgn_agent_public_key')) !== '') {
				throw new SiteCopyException('The copy already has a connected agent.');
			}
			$approval = (new ProvisionCustomerCloud())->join_approval_check((string)$request->get('ajr_source_ip'), $node);
			if (!$approval['ok']) {
				throw new SiteCopyException('Join not approved. ' . $approval['reason']);
			}
		} else {
			if ($copy->get('scp_cvp_customer_cloud_provision_id')) {
				throw new SiteCopyException('The new server is still being created; its record appears when its install starts.');
			}
			$node = new ManagedNode(NULL);
			$node->set('mgn_name', $source->get('mgn_name') . ' (copy)');
			$node->set('mgn_slug', AgentChannelEndpoint::freeSlug($source->get('mgn_slug') . '-copy'));
			$node->set('mgn_host', AgentChannelEndpoint::node_address_for_join($request, (string)$request->get('ajr_source_ip')));
			$node->set('mgn_site_url', 'https://' . self::site_domain($source));
			$node->set('mgn_enabled', true);
			$node->set('mgn_skip_joinery_checks', false);
			$node->set('mgn_uptime_enabled', false);
			$node->set('mgn_install_state', 'copy');
			$node->set('mgn_copy_of_node_id', (int)$source->key);
			$node->prepare();
			$node->save();
			$node->load();
			$copy->set('scp_copy_node_id', (int)$node->key);
			$copy->save();
		}
		AgentChannelEndpoint::approveJoin($request, $node);
		$node->load();
		return $node;
	}

	// ── Runs ───────────────────────────────────────────────────────────────

	/** Start a run on a dormant or stopped copy: Copy again. */
	public static function copy_again(SiteCopy $copy, $user_id): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		try {
			$copy->load();
			if (!in_array($copy->status(), array(SiteCopy::STATUS_DORMANT, SiteCopy::STATUS_HALTED), true)) {
				throw new SiteCopyException('A copy run starts on a dormant or stopped copy; this one is '
					. strtolower($copy->status_label()) . '.');
			}
			if (!self::copy_row($copy, false)) {
				throw new SiteCopyException('This copy has no server yet. Discard it and start again.');
			}
			self::begin_run($copy);
		} finally {
			self::unlock();
		}
	}

	/**
	 * Discard: the copy leaves the dashboard and the record ends. The server is
	 * not deleted (the platform never deletes a cloud instance); the page that
	 * called this names it for its owner to delete at the provider.
	 */
	public static function discard(SiteCopy $copy): void {
		DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_lock(' . self::LOCK_KEY . ')');
		try {
			$copy->load();
			if (!$copy->is_live()) {
				return;
			}
			// A server being created or installed right now is left to finish:
			// discarding under it would leave the pipeline making a copy nobody
			// tracks. Its install ends in minutes, either way.
			$provision = self::provision($copy);
			$p_status = $provision ? (string)$provision->get('cvp_status') : '';
			if (in_array($p_status, array('booting', 'installing'), true)) {
				throw new SiteCopyException('The new server is being installed. Discard it once its install finishes or fails.');
			}
			if ($p_status === 'ready') {
				// Nothing was created yet: the provision is simply withdrawn.
				$provision->soft_delete();
			}
			$node = self::copy_row($copy, false);
			if ($node && !$node->get('mgn_delete_time')) {
				$node->soft_delete();
			}
			$copy->set('scp_status', SiteCopy::STATUS_DISCARDED);
			$copy->set_steps(self::skip_pending($copy->steps()));
			$copy->save();
		} finally {
			self::unlock();
		}
	}

	/**
	 * What the owner has to delete at the provider after a discard: the
	 * provider, instance and address, from the provision when this management
	 * node created the server, else the address the copy's row recorded.
	 */
	public static function server_to_delete(SiteCopy $copy): string {
		$provision = self::provision($copy);
		if ($provision && trim((string)$provision->get('cvp_instance_id')) !== '') {
			return ucfirst((string)$provision->get('cvp_provider')) . ' instance ' . $provision->get('cvp_instance_id')
				. ' (' . $provision->get('cvp_instance_ip') . ')';
		}
		$node = self::copy_row($copy, true);
		return $node ? 'the server at ' . $node->get('mgn_host') : '';
	}

	/** Move every live copy along. What the scheduled task calls. */
	public static function advance_all(): int {
		$moved = 0;
		foreach (array(SiteCopy::STATUS_WAITING, SiteCopy::STATUS_COPYING) as $status) {
			foreach (new MultiSiteCopy(array('status' => $status, 'deleted' => false)) as $copy) {
				self::advance($copy);
				$moved++;
			}
		}
		return $moved;
	}

	/**
	 * A node posted a job's result: when the job is a copy's step, advance that
	 * copy now instead of at the next task tick. Never throws; a copy that
	 * cannot move here moves at the next tick.
	 */
	public static function job_finished(ManagementJob $job): void {
		try {
			$params = $job->get('mjb_parameters');
			if (is_string($params)) {
				$params = json_decode($params, true);
			}
			$copy_id = is_array($params) ? (int)($params['site_copy_id'] ?? 0) : 0;
			if ($copy_id <= 0) {
				return;
			}
			$copy = new SiteCopy($copy_id, TRUE);
			if ($copy->key && !$copy->get('scp_delete_time')) {
				self::advance($copy);
			}
		} catch (Throwable $e) {
			error_log('SiteCopyRunner: advancing on job #' . (int)$job->key . "'s result failed: " . $e->getMessage());
		}
	}

	/** Move a copy as far as it can go now, under the lock. */
	public static function advance(SiteCopy $copy): void {
		if (!self::lock()) {
			return;
		}
		try {
			$copy->load();
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
	}

	private static function advance_locked(SiteCopy $copy): void {
		if ($copy->status() === SiteCopy::STATUS_WAITING) {
			self::advance_waiting($copy);
		}
		// A run that just began queues its first step now, too.
		if ($copy->status() === SiteCopy::STATUS_COPYING) {
			// A passed step is followed at once by queuing the next; the loop
			// stops at a step that is running, failed, or the run's end.
			for ($i = 0; $i <= count($copy->steps()) && $copy->status() === SiteCopy::STATUS_COPYING; $i++) {
				if (!self::advance_run($copy)) {
					break;
				}
			}
		}
	}

	/**
	 * Waiting: link the row the provisioning made, stop on a failed install,
	 * and start the first run once the copy's agent has joined and reported its
	 * words.
	 */
	private static function advance_waiting(SiteCopy $copy): void {
		$provision = self::provision($copy);
		if ($provision) {
			if (!$copy->get('scp_copy_node_id') && $provision->get('cvp_mgn_managed_node_id')) {
				$copy->set('scp_copy_node_id', (int)$provision->get('cvp_mgn_managed_node_id'));
				$copy->save();
			}
			if ((string)$provision->get('cvp_status') === 'failed') {
				self::halt($copy, 'The new server\'s install failed: ' . trim((string)$provision->get('cvp_error')));
				return;
			}
		}
		$node = self::copy_row($copy, false);
		if (!$node || trim((string)$node->get('mgn_agent_public_key')) === ''
				|| trim((string)$node->get('mgn_install_state')) !== 'copy') {
			return;
		}
		if (AgentVocabulary::missing_words($node, self::COPY_WORDS)) {
			return; // joined; its first poll reports what it can do
		}
		self::begin_run($copy);
	}

	/** Lay out a run's steps, after checking both ends still hold. */
	private static function begin_run(SiteCopy $copy): void {
		try {
			$source = self::source_row($copy);
			$why = self::source_refusals($source, false);
			if ($why) {
				throw new SiteCopyException('The source cannot be copied now: ' . implode(' ', $why));
			}
			$version = (string)$source->get('mgn_joinery_version');
			if ($version !== (string)$copy->get('scp_release')) {
				throw new SiteCopyException("The source now runs release {$version}, and this copy was installed at "
					. $copy->get('scp_release') . '. A copy must run its source\'s exact release; discard it and copy again.');
			}
			$node = self::copy_row($copy, true);
			if (!$node || trim((string)$node->get('mgn_install_state')) !== 'copy') {
				throw new SiteCopyException('The copy\'s node record is gone or is no longer a dormant copy.');
			}
			$chain = self::newest_chain($source);
		} catch (Exception $e) {
			self::halt($copy, $e->getMessage());
			return;
		}
		$steps = array();
		foreach (self::RUN_STEPS as $s) {
			$steps[] = array('op' => $s['op'], 'on' => $s['on'], 'job_id' => null, 'verdict' => 'pending', 'reason' => '');
		}
		$copy->set_steps($steps);
		$copy->set('scp_chain_id', $chain['chain_id']);
		$copy->set('scp_chain_time', $chain['time']);
		$copy->set('scp_census', null);
		$copy->set('scp_halt_reason', null);
		$copy->set('scp_run_started_time', gmdate('Y-m-d H:i:s'));
		$copy->set('scp_status', SiteCopy::STATUS_COPYING);
		$copy->save();
	}

	/**
	 * One step of a run: queue the current step's job, or judge the job that
	 * finished, or finish the run.
	 */
	/** One move of a run. True when a step passed, so the next can be queued now. */
	private static function advance_run(SiteCopy $copy): bool {
		$steps = $copy->steps();
		$pos = null;
		foreach ($steps as $i => $s) {
			if ($s['verdict'] !== 'passed') {
				$pos = $i;
				break;
			}
		}
		if ($pos === null) {
			self::finish_run($copy, $steps);
			return false;
		}
		$step = $steps[$pos];
		try {
			$source = self::source_row($copy);
			$node = self::copy_row($copy, true);
			if (!$node) {
				throw new SiteCopyException('the copy\'s node record is gone');
			}
		} catch (Exception $e) {
			self::fail_step($copy, $steps, $pos, $e->getMessage());
			return false;
		}
		$target = $step['on'] === 'source' ? $source : $node;

		if (empty($step['job_id'])) {
			try {
				$built = self::build_step($copy, $steps, $step, $source, $node);
				$job = ManagementJob::createFromBuild($target->key, $step['op'], $built,
					array('site_copy_id' => (int)$copy->key), $copy->get('scp_created_by'));
			} catch (Exception $e) {
				self::fail_step($copy, $steps, $pos, 'it could not be queued: ' . $e->getMessage());
				return false;
			}
			$steps[$pos]['job_id'] = (int)$job->key;
			$steps[$pos]['verdict'] = 'running';
			$copy->set_steps($steps);
			$copy->save();
			return false;
		}

		try {
			$job = new ManagementJob((int)$step['job_id'], TRUE);
		} catch (Exception $e) {
			self::fail_step($copy, $steps, $pos, 'its job is gone');
			return false;
		}
		if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
			$queued = strtotime((string)$job->get('mjb_create_time') . ' UTC');
			$limit = self::STEP_WAIT_MINUTES[$step['op']] ?? 60;
			if ($queued && time() - $queued > $limit * 60) {
				self::fail_step($copy, $steps, $pos, "it has not finished in {$limit} minutes (status "
					. $job->get('mjb_status') . '; is the agent claiming jobs?)');
			}
			return false;
		}
		JobResultProcessor::process_if_due($job);
		$job->load();
		$why = self::judge($copy, $step, $job, $source, $node);
		if ($why !== null) {
			self::fail_step($copy, $steps, $pos, $why);
			return false;
		}
		$steps[$pos]['verdict'] = 'passed';
		$steps[$pos]['reason'] = '';
		$copy->set_steps($steps);
		$copy->save();
		return true;
	}

	/** The job for one step. */
	private static function build_step(SiteCopy $copy, array $steps, array $step, ManagedNode $source, ManagedNode $node): array {
		$chain = array('chain_id' => (string)$copy->get('scp_chain_id'), 'profile' => BackupProfile::MANAGER);
		$op = (string)$step['op'];
		switch ($op) {
			case 'host_report':
				return JobCommandBuilder::build_host_report($node);
			case 'copy_export':
				return JobCommandBuilder::build_copy_export($source, $node, $chain);
			case 'copy_import':
				$export = self::step_job($steps, 'copy_export');
				$bundle = $export ? JobResultProcessor::bundle_of($export) : null;
				if ($bundle === null) {
					throw new SiteCopyException('the export kept no bundle');
				}
				return JobCommandBuilder::build_copy_import($node, $bundle);
			case 'copy_stage':
				return self::$stage_builder ? (self::$stage_builder)($node, $chain) : JobCommandBuilder::build_copy_stage($node, $chain);
			case 'copy_restore':
				return JobCommandBuilder::build_copy_restore($node, array('chain_id' => $chain['chain_id']));
			case 'site_census':
				return JobCommandBuilder::build_site_census($step['on'] === 'source' ? $source : $node);
		}
		throw new SiteCopyException("unknown step {$op}");
	}

	/** The job of the first step with this op that has one, or null. */
	private static function step_job(array $steps, string $op, string $on = '') {
		foreach ($steps as $s) {
			if ($s['op'] === $op && ($on === '' || $s['on'] === $on) && !empty($s['job_id'])) {
				try {
					return new ManagementJob((int)$s['job_id'], TRUE);
				} catch (Exception $e) {
					return null;
				}
			}
		}
		return null;
	}

	/**
	 * Did this step's finished job do what the run needs? null when it did,
	 * else why not.
	 */
	private static function judge(SiteCopy $copy, array $step, $job, ManagedNode $source, ManagedNode $node): ?string {
		if ((string)$job->get('mjb_status') !== 'completed') {
			$err = trim((string)$job->get('mjb_error_message'));
			$what = $step['op'] === 'copy_export'
				? 'the export was not made (declined or not approved in time on the source\'s Backups page?)'
				: 'its job ' . $job->get('mjb_status');
			return $what . ($err !== '' ? ': ' . $err : '');
		}
		switch ($step['op']) {
			case 'host_report':
				$node->load();
				return self::fit_refusal($copy, $source, $node);
			case 'copy_export':
				return JobResultProcessor::bundle_of($job) === null ? 'the export returned no bundle' : null;
			case 'copy_import':
				$look = JobResultProcessor::look_path_of($job);
				if ($look !== '') {
					$copy->set('scp_look_path', $look);
				}
				return null;
			case 'site_census':
				return JobResultProcessor::census_of($job) === null ? 'the census could not be taken' : null;
		}
		return null;
	}

	/**
	 * Does the copy's server fit this run? Read from both machines' host
	 * reports: memory at least the source's, and free disk for the staged
	 * chain, the extracted tree and the database loaded twice over, plus
	 * headroom. An estimate from the chain's sizes, said as one.
	 */
	public static function fit_refusal(SiteCopy $copy, ManagedNode $source, ManagedNode $node): ?string {
		$t = json_decode((string)$node->get('mgn_last_host_report'), true);
		$s = json_decode((string)$source->get('mgn_last_host_report'), true);
		if (!is_array($t) || empty($t['disk']['avail_bytes'])) {
			return 'the copy\'s server did not report its disk';
		}
		$t_mem = (int)($t['memory']['total_bytes'] ?? 0);
		$s_mem = is_array($s) ? (int)($s['memory']['total_bytes'] ?? 0) : 0;
		if ($s_mem > 0 && $t_mem > 0 && $t_mem < $s_mem * 0.9) {
			return 'the copy\'s server has ' . BackupRunner::human($t_mem) . ' of memory and the source has '
				. BackupRunner::human($s_mem) . '; a copy needs at least the source\'s';
		}
		try {
			$chain = self::newest_chain($source);
		} catch (Exception $e) {
			return $e->getMessage();
		}
		$need = self::disk_needed($chain);
		$avail = (int)$t['disk']['avail_bytes'];
		if ($avail < $need) {
			return 'the copy\'s server has ' . BackupRunner::human($avail) . ' free, and this copy needs about '
				. BackupRunner::human($need) . ' (the chain staged, the files unpacked, the database loaded, and room to spare)';
		}
		return null;
	}

	/** About how much free disk a copy run needs, from the chain's sizes. */
	public static function disk_needed(array $chain): int {
		return (int)$chain['bytes'] + 2 * (int)$chain['files'] + 6 * (int)$chain['db'] + self::DISK_HEADROOM;
	}

	/** The last step passed: compare the two censuses, informationally. */
	private static function finish_run(SiteCopy $copy, array $steps): void {
		$s_job = self::step_job($steps, 'site_census', 'source');
		$c_job = self::step_job($steps, 'site_census', 'copy');
		$s = $s_job ? JobResultProcessor::census_of($s_job) : null;
		$c = $c_job ? JobResultProcessor::census_of($c_job) : null;
		if ($s === null || $c === null) {
			self::halt($copy, 'The census of one side is missing.');
			return;
		}
		$verdict = SiteCensus::compare($s, $c, false);
		$copy->set('scp_census', $verdict);
		if ((int)$verdict['blocking'] > 0) {
			self::halt($copy, 'The census found ' . (int)$verdict['blocking']
				. ' difference(s) the source being live does not explain. See the comparison below.');
			return;
		}
		$copy->set('scp_status', SiteCopy::STATUS_DORMANT);
		$copy->set('scp_last_copied_time', gmdate('Y-m-d H:i:s'));
		$copy->save();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private static function fail_step(SiteCopy $copy, array $steps, int $pos, string $why): void {
		$steps[$pos]['verdict'] = 'failed';
		$steps[$pos]['reason'] = mb_substr($why, 0, 500);
		$copy->set_steps(self::skip_pending($steps));
		$label = $steps[$pos]['op'] . ' on the ' . $steps[$pos]['on'];
		self::halt($copy, $label . ': ' . $why);
	}

	private static function halt(SiteCopy $copy, string $why): void {
		$copy->set('scp_status', SiteCopy::STATUS_HALTED);
		$copy->set('scp_halt_reason', mb_substr($why, 0, 2000));
		$copy->save();
	}

	private static function skip_pending(array $steps): array {
		foreach ($steps as $i => $s) {
			if (($s['verdict'] ?? '') === 'pending' || ($s['verdict'] ?? '') === 'running') {
				$steps[$i]['verdict'] = 'skipped';
			}
		}
		return $steps;
	}

	private static function source_row(SiteCopy $copy): ManagedNode {
		$id = (int)$copy->get('scp_source_node_id');
		$source = $id ? new ManagedNode($id, TRUE) : null;
		if (!$source || !$source->key || $source->get('mgn_delete_time')) {
			throw new SiteCopyException('The source node is gone.');
		}
		return $source;
	}

	/** The copy's node row, or null; $live_only skips a removed one. */
	public static function copy_row(SiteCopy $copy, bool $live_only = true): ?ManagedNode {
		$id = (int)$copy->get('scp_copy_node_id');
		if (!$id) {
			return null;
		}
		try {
			$node = new ManagedNode($id, TRUE);
		} catch (Exception $e) {
			return null;
		}
		if (!$node->key || ($live_only && $node->get('mgn_delete_time'))) {
			return null;
		}
		return $node;
	}

	public static function provision(SiteCopy $copy): ?CustomerCloudProvision {
		$id = (int)$copy->get('scp_cvp_customer_cloud_provision_id');
		if (!$id) {
			return null;
		}
		try {
			$p = new CustomerCloudProvision($id, TRUE);
		} catch (Exception $e) {
			return null;
		}
		return $p->key ? $p : null;
	}

	private static function lock(): bool {
		return (bool)DbConnector::get_instance()->get_db_link()
			->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn();
	}

	private static function unlock(): void {
		DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
	}
}
