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
 *   discard           the copy row leaves the dashboard and the step it was
 *                     on is cancelled (a source waiting for its owner's export
 *                     approval sees that and withdraws the request). The server
 *                     is not deleted: the platform never deletes a cloud
 *                     instance, so the page names it for its owner to delete at
 *                     the provider.
 *
 * advance() is called by the AdvanceSiteCopies task every tick, and by
 * job_finished() when a node posts a copy step's result (the Copy tab only
 * reads, so a page view writes nothing). A step that passes queues the next in
 * the same call, so a run moves as fast as its jobs finish. It saves every
 * state it moves to before the next, and holds an advisory lock so two movers
 * never race. A crash between calls resumes where it stood.
 *
 * Switching over (steps 7-10, WP7a), from a dormant copy, each phase a run of
 * steps like a copy run:
 *
 *   begin_switch()  the owner's press, with a DNS token: every record naming
 *                   the source's address must be proxied (ProxiedOriginMove::
 *                   plan, read-only), then the source's row goes to
 *                   `switching` and the final copy runs (freezing): site_quiet
 *                   on, one more backup run, copy_vouch on the source (a
 *                   signature, no secret: the frozen site's owner cannot reach
 *                   an approval page, site_copy.md B44), copy_take_vouch,
 *                   copy_stage, copy_restore, and both censuses, which must
 *                   match exactly. Then `ready`.
 *   move_address()  the owner's press, with the token again: the proxied
 *                   records move to the copy, and the copy's own answer
 *                   through the proxy proves it; unproven, they move back.
 *                   Then (starting) take_node_id, which the management node
 *                   answers with the row swap, and site_quiet off on the node
 *                   that is now the copy's machine. Then `switched`.
 *   go_back()       from any of these, and after the switch: the records move
 *                   back (token) if they moved; after the swap, the copy's
 *                   machine is quieted while it is still the node, the rows
 *                   are swapped back, and the copy is discarded (its agent now
 *                   answers as a node whose row holds another key); then the
 *                   source runs again.
 *   finish()        the owner keeps the switch: the retired row leaves the
 *                   dashboard and its server is named for deleting.
 *
 * Nothing here holds a DNS credential: each press builds the driver from what
 * the owner typed, and it is gone when the request ends.
 *
 * @version 1.4 - the switch-over and the way back by a proxied origin change (site_copy.md WP7a)
 * @version 1.3 - discard cancels the step job it was on (site_copy.md B42)
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
		'host_report'     => 20,
		'copy_export'     => 90,
		'copy_import'     => 20,
		'copy_stage'      => 160,
		'copy_restore'    => 160,
		'site_census'     => 30,
		'site_quiet'      => 25,
		'backup_run'      => 270,
		'copy_vouch'      => 20,
		'copy_take_vouch' => 20,
		'take_node_id'    => 20,
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

	/** The words a switch-over asks of the source, beyond a copy's. */
	const SWITCH_SOURCE_WORDS = array('site_quiet', 'backup_run', 'copy_vouch', 'site_census');

	/** The words a switch-over asks of the copy, beyond a copy's. */
	const SWITCH_COPY_WORDS = array('copy_take_vouch', 'copy_stage', 'copy_restore', 'site_census', 'take_node_id', 'site_quiet');

	/**
	 * The final copy: the source frozen, one more backup run that extends the
	 * copy's chain, the source's vouch for it, and the copy brought to it.
	 */
	const FREEZE_STEPS = array(
		array('op' => 'site_quiet',      'on' => 'source', 'arg' => 'on'),
		array('op' => 'backup_run',      'on' => 'source'),
		array('op' => 'copy_vouch',      'on' => 'source'),
		array('op' => 'copy_take_vouch', 'on' => 'copy'),
		array('op' => 'copy_stage',      'on' => 'copy'),
		array('op' => 'copy_restore',    'on' => 'copy'),
		array('op' => 'site_census',     'on' => 'source'),
		array('op' => 'site_census',     'on' => 'copy'),
	);

	/**
	 * The final backup run's chain policy: the longest interval the agent
	 * takes, so the run extends the chain the copy holds the key for rather
	 * than starting a new one by age.
	 */
	const FINAL_RUN_FULL_INTERVAL_DAYS = 365;

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
			if (self::in_switch_over($copy)) {
				throw new SiteCopyException('This copy is in a switch-over. Go back first.');
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
			if (self::in_switch_over($copy)) {
				throw new SiteCopyException('This copy is in a switch-over. Go back, or keep the switch-over, instead.');
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
			// The step it was on is withdrawn too. A source waiting for its
			// owner to approve the export asks whether the job is still wanted,
			// and takes the approval request off its page when it is not.
			self::cancel_open_steps($copy->steps());
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
		// The provision follows its machine through a swap, so the copy row's
		// is the server on that row; one never joined has only the copy's own.
		$node = self::copy_row($copy, false);
		$provision = ($node ? CustomerCloudProvision::latest_for_node((int)$node->key) : null) ?: self::provision($copy);
		if ($provision && trim((string)$provision->get('cvp_instance_id')) !== '') {
			return ucfirst((string)$provision->get('cvp_provider')) . ' instance ' . $provision->get('cvp_instance_id')
				. ' (' . $provision->get('cvp_instance_ip') . ')';
		}
		return $node ? 'the server at ' . $node->get('mgn_host') : '';
	}

	/** Move every live copy along. What the scheduled task calls. */
	public static function advance_all(): int {
		$moved = 0;
		foreach (array_merge(array(SiteCopy::STATUS_WAITING), SiteCopy::MOVING_STATUSES) as $status) {
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
		$status = $copy->status();
		if (in_array($status, SiteCopy::MOVING_STATUSES, true)) {
			// A passed step is followed at once by queuing the next; the loop
			// stops at a step that is running, failed, or the run's end.
			for ($i = 0; $i <= count($copy->steps()) && $copy->status() === $status; $i++) {
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

		if ($step['op'] === 'go_back') {
			// The one step with no job: the rows swapped back, here.
			try {
				SiteCopySwap::go_back($source, $node);
			} catch (Exception $e) {
				self::fail_step($copy, $steps, $pos, $e->getMessage());
				return false;
			}
			$switch = $copy->switch_record();
			$switch['swapped_back_time'] = gmdate('Y-m-d H:i:s');
			$copy->set_switch_record($switch);
			$steps[$pos]['verdict'] = 'passed';
			$copy->set_steps($steps);
			$copy->save();
			return true;
		}

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
			case 'site_quiet':
				return JobCommandBuilder::build_site_quiet($step['on'] === 'source' ? $source : $node, (string)($step['arg'] ?? ''));
			case 'backup_run':
				return JobCommandBuilder::build_backup_run($source, array('type' => 'project', 'mode' => 'chain',
					'full_interval_days' => self::FINAL_RUN_FULL_INTERVAL_DAYS));
			case 'copy_vouch':
				return JobCommandBuilder::build_copy_vouch($source, $node, $chain);
			case 'copy_take_vouch':
				$made = self::step_job($steps, 'copy_vouch');
				$vouch = $made ? JobResultProcessor::vouch_of($made) : null;
				if ($vouch === null) {
					throw new SiteCopyException('the source made no vouch');
				}
				return JobCommandBuilder::build_copy_take_vouch($node, $vouch);
			case 'take_node_id':
				return JobCommandBuilder::build_take_node_id($node, $source);
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
			case 'backup_run':
				$r = json_decode((string)$job->get('mjb_result'), true);
				if (!is_array($r) || !in_array($r['backup_status'] ?? '', array('success', 'warning'), true)) {
					return 'the final backup did not succeed' . (is_array($r) && !empty($r['message']) ? ': ' . $r['message'] : '');
				}
				try {
					$chain = self::newest_chain($source);
				} catch (Exception $e) {
					return $e->getMessage();
				}
				if ($chain['chain_id'] !== (string)$copy->get('scp_chain_id')) {
					return 'the final backup started a new backup chain (' . $chain['chain_id'] . ') instead of extending '
						. $copy->get('scp_chain_id') . ', and the copy holds the key only for that one. Go back, copy '
						. 'again, then switch over again';
				}
				$copy->set('scp_chain_time', $chain['time']);
				return null;
			case 'copy_vouch':
				$r = json_decode((string)$job->get('mjb_result'), true);
				if (JobResultProcessor::vouch_of($job) === null) {
					return 'the source made no vouch';
				}
				return (string)($r['chain_id'] ?? '') === (string)$copy->get('scp_chain_id') ? null
					: 'the source vouched for chain ' . ($r['chain_id'] ?? '?') . ', not the copy\'s ' . $copy->get('scp_chain_id');
			case 'take_node_id':
				$r = json_decode((string)$job->get('mjb_result'), true);
				return (is_array($r) && !empty($r['taken'])) ? null
					: 'the copy did not take the site\'s node id' . (is_array($r) && !empty($r['reason']) ? ': ' . $r['reason'] : '');
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

	/**
	 * The last step passed. A copy run compares the two censuses
	 * informationally; the final copy requires them equal; the start and the
	 * way back each end their phase.
	 */
	private static function finish_run(SiteCopy $copy, array $steps): void {
		if ($copy->status() === SiteCopy::STATUS_STARTING) {
			$switch = $copy->switch_record();
			$switch['switched_time'] = gmdate('Y-m-d H:i:s');
			$copy->set_switch_record($switch);
			$copy->set('scp_status', SiteCopy::STATUS_SWITCHED);
			$copy->save();
			return;
		}
		if ($copy->status() === SiteCopy::STATUS_RETURNING) {
			self::finish_return($copy);
			return;
		}
		$final = $copy->status() === SiteCopy::STATUS_FREEZING;
		$s_job = self::step_job($steps, 'site_census', 'source');
		$c_job = self::step_job($steps, 'site_census', 'copy');
		$s = $s_job ? JobResultProcessor::census_of($s_job) : null;
		$c = $c_job ? JobResultProcessor::census_of($c_job) : null;
		if ($s === null || $c === null) {
			self::halt($copy, 'The census of one side is missing.');
			return;
		}
		$verdict = SiteCensus::compare($s, $c, $final);
		$copy->set('scp_census', $verdict);
		if ((int)$verdict['blocking'] > 0) {
			self::halt($copy, $final
				? 'The final census found ' . (int)$verdict['blocking'] . ' difference(s) between the frozen site and the '
					. 'copy, so nothing moves. See the comparison below. Go back to start the site again.'
				: 'The census found ' . (int)$verdict['blocking']
					. ' difference(s) the source being live does not explain. See the comparison below.');
			return;
		}
		if ($final) {
			$switch = $copy->switch_record();
			$switch['final_copied_time'] = gmdate('Y-m-d H:i:s');
			$copy->set_switch_record($switch);
			$copy->set('scp_status', SiteCopy::STATUS_READY);
			$copy->set('scp_last_copied_time', gmdate('Y-m-d H:i:s'));
			$copy->save();
			return;
		}
		$copy->set('scp_status', SiteCopy::STATUS_DORMANT);
		$copy->set('scp_last_copied_time', gmdate('Y-m-d H:i:s'));
		$copy->save();
	}

	// ── Switching over (WP7a) ─────────────────────────────────────────────

	/** Is this copy in a switch-over: started, under way, or done with the way back open? */
	public static function in_switch_over(SiteCopy $copy): bool {
		if (in_array($copy->status(), array(SiteCopy::STATUS_FREEZING, SiteCopy::STATUS_READY, SiteCopy::STATUS_STARTING,
				SiteCopy::STATUS_SWITCHED, SiteCopy::STATUS_RETURNING), true)) {
			return true;
		}
		$node = self::copy_row($copy, true);
		if ($node && trim((string)$node->get('mgn_install_state')) === 'retired') {
			return true;
		}
		try {
			return trim((string)self::source_row($copy)->get('mgn_install_state')) === 'switching';
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Why this copy cannot switch over now: one line per reason, empty when it
	 * can. Read-only; the DNS records are checked on the press, with the token.
	 */
	public static function switch_refusals(SiteCopy $copy): array {
		if ($copy->status() !== SiteCopy::STATUS_DORMANT) {
			return array('A switch-over starts from a dormant copy that is current; this one is '
				. strtolower($copy->status_label()) . '.');
		}
		try {
			$source = self::source_row($copy);
		} catch (Exception $e) {
			return array($e->getMessage());
		}
		$node = self::copy_row($copy, true);
		if (!$node || trim((string)$node->get('mgn_install_state')) !== 'copy' || trim((string)$node->get('mgn_agent_public_key')) === '') {
			return array('The copy\'s node record is gone or is no longer a dormant copy.');
		}
		$why = self::source_refusals($source, false);
		$missing = array_values(array_filter(self::SWITCH_SOURCE_WORDS, function ($w) use ($source) {
			return !JobCommandBuilder::has_primitive($source, $w);
		}));
		if ($missing) {
			$why[] = 'The site: ' . AgentVocabulary::needs_newer_agent_text($source, $missing);
		}
		$missing = array_values(array_filter(self::SWITCH_COPY_WORDS, function ($w) use ($node) {
			return !JobCommandBuilder::has_primitive($node, $w);
		}));
		if ($missing) {
			$why[] = 'The copy: ' . AgentVocabulary::needs_newer_agent_text($node, $missing);
		}
		if ((string)$source->get('mgn_joinery_version') !== (string)$copy->get('scp_release')) {
			$why[] = 'The site now runs release ' . $source->get('mgn_joinery_version') . ', and the copy was installed at '
				. $copy->get('scp_release') . '. Discard the copy and copy again.';
		}
		if (!preg_match('#^/\.joinery-look/[0-9a-f]{32}$#', (string)$copy->get('scp_look_path'))) {
			$why[] = 'The copy reported no look path. The switch-over asks for it through the proxy, because only the '
				. 'copy answers it, to prove the proxy reaches the copy. Copy again.';
		}
		if (!$why) {
			try {
				$chain = self::newest_chain($source);
				if ($chain['chain_id'] !== (string)$copy->get('scp_chain_id')) {
					$why[] = 'The site\'s newest backup is in chain ' . $chain['chain_id'] . ', and the copy holds the key for '
						. $copy->get('scp_chain_id') . '. Copy again first.';
				}
			} catch (Exception $e) {
				$why[] = $e->getMessage();
			}
		}
		return $why;
	}

	/**
	 * Switch over: check the records with the owner's DNS token (read-only),
	 * then freeze the site and run the final copy. The token is not kept; the
	 * address moves on a second press, with the token again.
	 */
	public static function begin_switch(SiteCopy $copy, DnsProvider $driver, $user_id): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		try {
			$copy->load();
			$why = self::switch_refusals($copy);
			if ($why) {
				throw new SiteCopyException('This copy cannot switch over yet: ' . implode(' ', $why));
			}
			$source = self::source_row($copy);
			$node = self::copy_row($copy, true);
			try {
				$plan = ProxiedOriginMove::plan($driver, self::site_domain($source), $source, $node);
			} catch (Exception $e) {
				throw new SiteCopyException('Nothing was frozen: the address cannot be moved through the proxy. ' . $e->getMessage());
			}
			$source->set('mgn_install_state', 'switching');
			$source->save();
			$copy->set_switch_record(array(
				'method'      => 'proxied_origin',
				'provider'    => $driver::getKey(),
				'zone'        => $plan['zone'],
				'records'     => $plan['records'],
				'address_at'  => 'source',
				'frozen_time' => gmdate('Y-m-d H:i:s'),
				'started_by'  => $user_id ? (int)$user_id : null,
			));
			$copy->set('scp_census', null);
			self::lay_steps($copy, self::FREEZE_STEPS, SiteCopy::STATUS_FREEZING, 'freeze');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
	}

	/** Step 10's two jobs: the node id, then the copy starts as the site. */
	private static function start_steps(?ManagedNode $node): array {
		$steps = array();
		if (!$node || trim((string)$node->get('mgn_install_state')) !== 'retired') {
			$steps[] = array('op' => 'take_node_id', 'on' => 'copy');
		}
		$steps[] = array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'off');
		return $steps;
	}

	/**
	 * Move the address (step 9) with the owner's DNS token, prove the proxy
	 * reaches the copy, and start it (step 10). Unproven, the records move
	 * back and the site stays frozen.
	 */
	public static function move_address(SiteCopy $copy, DnsProvider $driver, $user_id): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		try {
			$copy->load();
			if ($copy->status() !== SiteCopy::STATUS_READY) {
				throw new SiteCopyException('The address moves once the final copy has matched; this copy is '
					. strtolower($copy->status_label()) . '.');
			}
			$source = self::source_row($copy);
			$node = self::copy_row($copy, true);
			if (trim((string)$source->get('mgn_install_state')) !== 'switching' || !$node
					|| trim((string)$node->get('mgn_install_state')) !== 'copy') {
				throw new SiteCopyException('The site and its copy are not as the final copy left them; go back.');
			}
			$domain = self::site_domain($source);
			try {
				$plan = ProxiedOriginMove::plan($driver, $domain, $source, $node);
				ProxiedOriginMove::move($driver, $plan);
			} catch (Exception $e) {
				throw new SiteCopyException('The address did not move. ' . $e->getMessage());
			}
			// Recorded before the proof, so a request that dies here leaves the
			// page knowing where the address points.
			$switch = $copy->switch_record();
			$switch['zone'] = $plan['zone'];
			$switch['records'] = $plan['records'];
			$switch['address_at'] = 'copy';
			$switch['moved_time'] = gmdate('Y-m-d H:i:s');
			$copy->set_switch_record($switch);
			$copy->save();

			$proof = ProxiedOriginMove::prove($domain, (string)$copy->get('scp_look_path'));
			$switch['proof'] = $proof;
			if (!$proof['proven']) {
				try {
					$left = ProxiedOriginMove::move_back($driver, $plan);
				} catch (Exception $e) {
					$copy->set_switch_record($switch);
					$copy->save();
					throw new SiteCopyException('The proxy did not reach the copy within ' . $proof['seconds'] . ' seconds (last '
						. 'answer: ' . $proof['last'] . '), and moving the address back failed: ' . $e->getMessage()
						. ' Visitors reach the copy, which is not open. Go back, with the token, to put the address back.');
				}
				$switch['address_at'] = 'source';
				$switch['moved_back_time'] = gmdate('Y-m-d H:i:s');
				$copy->set_switch_record($switch);
				$copy->save();
				throw new SiteCopyException('The proxy did not reach the copy within ' . $proof['seconds'] . ' seconds (last '
					. 'answer: ' . $proof['last'] . '), so the address was moved back. The site is still frozen: try again, '
					. 'or go back.' . ($left ? ' ' . implode(' ', $left) : ''));
			}
			$copy->set_switch_record($switch);
			self::lay_steps($copy, self::start_steps($node), SiteCopy::STATUS_STARTING, 'start');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
	}

	/** Start the copy again after its start stopped: what of step 10 is left. */
	public static function retry_start(SiteCopy $copy): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		try {
			$copy->load();
			$switch = $copy->switch_record();
			if ($copy->status() !== SiteCopy::STATUS_HALTED || ($switch['phase'] ?? '') !== 'start'
					|| ($switch['address_at'] ?? '') !== 'copy') {
				throw new SiteCopyException('Only a start that stopped, with the address at the copy, is tried again.');
			}
			self::lay_steps($copy, self::start_steps(self::copy_row($copy, true)), SiteCopy::STATUS_STARTING, 'start');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
	}

	/**
	 * The way back, from anywhere in a switch-over: the address back to the
	 * site's server (with the owner's token, when it moved), and the site
	 * running there again. After the swap the copy's machine is quieted while
	 * it is still the node, then the rows swap back, and the copy is
	 * discarded: its agent answers as a node whose row holds another key.
	 * Anything written on the copy since it started stays there.
	 */
	public static function go_back(SiteCopy $copy, ?DnsProvider $driver): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		try {
			$copy->load();
			if ($copy->status() === SiteCopy::STATUS_RETURNING) {
				throw new SiteCopyException('It is already going back.');
			}
			$source = self::source_row($copy);
			$node = self::copy_row($copy, true);
			$swapped = $node && trim((string)$node->get('mgn_install_state')) === 'retired';
			if (!$swapped && trim((string)$source->get('mgn_install_state')) !== 'switching') {
				throw new SiteCopyException('There is nothing to go back from: the site runs on its own server.');
			}
			$switch = $copy->switch_record();
			if (($switch['address_at'] ?? 'source') === 'copy') {
				if (!$driver) {
					throw new SiteCopyException('The address points at the copy, and going back moves it back: enter the DNS token.');
				}
				try {
					$left = ProxiedOriginMove::move_back($driver, array('zone' => $switch['zone'] ?? '', 'records' => $switch['records'] ?? array()));
				} catch (Exception $e) {
					throw new SiteCopyException('The address did not move back, and nothing else was done. ' . $e->getMessage());
				}
				$switch['address_at'] = 'source';
				$switch['moved_back_time'] = gmdate('Y-m-d H:i:s');
				$switch['moved_back_notes'] = $left;
			}
			$copy->set_switch_record($switch);
			self::cancel_open_steps($copy->steps());
			$template = $swapped
				? array(
					array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'on'),
					array('op' => 'go_back',    'on' => 'source'),
					array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'off'),
				)
				: array(array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'off'));
			self::lay_steps($copy, $template, SiteCopy::STATUS_RETURNING, 'return');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
	}

	/** The way back's last step passed: the site runs on its own server again. */
	private static function finish_return(SiteCopy $copy): void {
		try {
			$source = self::source_row($copy);
		} catch (Exception $e) {
			self::halt($copy, $e->getMessage());
			return;
		}
		if (trim((string)$source->get('mgn_install_state')) === 'switching') {
			$source->set('mgn_install_state', null);
			$source->save();
		}
		$switch = $copy->switch_record();
		$switch['returned_time'] = gmdate('Y-m-d H:i:s');
		$copy->set_switch_record($switch);
		if (!empty($switch['swapped_back_time'])) {
			// The copy's agent took the site's node id; its row holds its own
			// key again, so it can no longer reach this management node.
			$node = self::copy_row($copy, true);
			if ($node) {
				$node->soft_delete();
			}
			$copy->set('scp_status', SiteCopy::STATUS_DISCARDED);
			$copy->save();
			return;
		}
		if (!empty($switch['final_copied_time'])) {
			$copy->set('scp_status', SiteCopy::STATUS_DORMANT);
			$copy->save();
			return;
		}
		self::halt($copy, 'Went back before the final copy finished. The site runs as before; copy again before switching over.');
	}

	/**
	 * Keep the switch-over: the retired row (the old server) leaves the
	 * dashboard and the way back closes. The old server is the owner's to
	 * delete at its provider; this names it.
	 */
	public static function finish(SiteCopy $copy): string {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		try {
			$copy->load();
			if ($copy->status() !== SiteCopy::STATUS_SWITCHED) {
				throw new SiteCopyException('Only a finished switch-over is kept; this copy is ' . strtolower($copy->status_label()) . '.');
			}
			$server = self::server_to_delete($copy);
			$node = self::copy_row($copy, true);
			if ($node && trim((string)$node->get('mgn_install_state')) === 'retired') {
				$node->soft_delete();
			}
			$copy->set('scp_status', SiteCopy::STATUS_FINISHED);
			$copy->save();
			return $server;
		} finally {
			self::unlock();
		}
	}

	/** Lay out a phase's steps and enter its status. */
	private static function lay_steps(SiteCopy $copy, array $template, string $status, string $phase): void {
		$steps = array();
		foreach ($template as $s) {
			$steps[] = array('op' => $s['op'], 'on' => $s['on'], 'arg' => $s['arg'] ?? '', 'job_id' => null,
				'verdict' => 'pending', 'reason' => '');
		}
		$switch = $copy->switch_record();
		$switch['phase'] = $phase;
		$copy->set_switch_record($switch);
		$copy->set_steps($steps);
		$copy->set('scp_halt_reason', null);
		$copy->set('scp_run_started_time', gmdate('Y-m-d H:i:s'));
		$copy->set('scp_status', $status);
		$copy->save();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private static function fail_step(SiteCopy $copy, array $steps, int $pos, string $why): void {
		$steps[$pos]['verdict'] = 'failed';
		$steps[$pos]['reason'] = mb_substr($why, 0, 500);
		$copy->set_steps(self::skip_pending($steps));
		$label = $steps[$pos]['op'] . (!empty($steps[$pos]['arg']) ? ' ' . $steps[$pos]['arg'] : '') . ' on the ' . $steps[$pos]['on'];
		self::halt($copy, $label . ': ' . $why);
	}

	private static function halt(SiteCopy $copy, string $why): void {
		$copy->set('scp_status', SiteCopy::STATUS_HALTED);
		$copy->set('scp_halt_reason', mb_substr($why, 0, 2000));
		$copy->save();
	}

	/** Cancel every step job not yet finished: queued, waiting to be claimed, or running. */
	private static function cancel_open_steps(array $steps): void {
		foreach ($steps as $s) {
			if (empty($s['job_id'])) {
				continue;
			}
			try {
				$job = new ManagementJob((int)$s['job_id'], TRUE);
			} catch (Exception $e) {
				continue;
			}
			if ($job->key && in_array((string)$job->get('mjb_status'), array('queued', 'pending', 'running'), true)) {
				$job->set('mjb_status', 'cancelled');
				$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
				$job->save();
			}
		}
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
