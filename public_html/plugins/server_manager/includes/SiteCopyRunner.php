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
 * The address moves one of three ways (switch_methods()):
 *
 *   proxied_origin  the proxy in front of the site forwards to the copy (WP7a,
 *                   above). Nothing here holds a DNS credential: each press
 *                   builds the driver from what the owner typed, and it is gone
 *                   when the request ends.
 *   ip_swap         the two servers swap their public IPv4 addresses at Linode
 *                   (WP12): the source powered off, the swap, the copy
 *                   restarted on its new address, the copy's look path asked at
 *                   the site's name. Each is a local step (IpSwapMove), with the
 *                   provider credential this management node already holds for
 *                   the servers it created. Unproven, the swap is undone
 *                   (undoing) and the source is started again, still frozen.
 *   manual          a copy from backups only: the owner points the site's DNS
 *                   at the copy and says so; the look path proves it.
 *
 * A copy from backups (WP10, scp_from 'backups') is for a site whose server is
 * dead. Nothing is asked of the source: its run is host_report, copy_look (the
 * copy's look path, so the owner can reach its page), copy_take_key (the copy
 * shows the backup on its own page, the owner types the recovery key there,
 * and the copy opens the chain's key itself: this management node never sees
 * the key or what it opens), copy_stage, copy_restore and the copy's census,
 * judged alone. Its switch-over freezes nothing and has no final copy: the
 * source's server is powered off when this management node created it, the
 * address moves, and the copy starts. There is no way back once it has taken
 * the node: the site it would go back to is dead.
 *
 * Local steps (a provider call, a probe, the row swap back) take no agent job.
 * A press that lays them out starts a short worker (utils/advance_site_copy.php)
 * that keeps advancing the copy until it waits on an agent job or stops, so a
 * machine's reboot is followed in seconds rather than at the next task tick.
 *
 * @version 1.7 - the new server is the source's size: its own plan when this management node created it, else
 *                 the smallest plan with its memory and the copy's disk (same_size_type); a container site's
 *                 copy is not held to its shared server's memory
 * @version 1.6 - a container site and a management node (not this one) are copied from their backups; a
 *                 copy from backups has its own release floor, the first with the copy's key page
 * @version 1.5 - the switch by IP swap (site_copy.md WP12) and the copy from backups (WP10)
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

	/** The words a copy from backups must report before a run starts. */
	const BACKUPS_COPY_WORDS = array('host_report', 'copy_look', 'copy_take_key', 'copy_stage', 'copy_restore', 'site_census');

	/** What the operating system and the installed release take on the copy's disk, beside what the copy needs. */
	const OS_DISK = 6442450944;

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
		'copy_look'       => 20,
		// The owner's hour at the copy's own page, inside the job.
		'copy_take_key'   => 80,
		// Local steps, from their first call.
		'power_off'       => 12,
		'power_on'        => 12,
		'power_cycle'     => 12,
		'ip_swap'         => 10,
		'probe'           => 5,
		// A copy from backups' certificate, once the address points at it.
		'provision_certificate' => 30,
	);

	/** Steps this management node does itself, with no agent job. */
	const LOCAL_STEPS = array('go_back', 'power_off', 'power_on', 'power_cycle', 'ip_swap', 'probe');

	/** How long one call of the probe step asks before it gives the worker back. */
	const PROBE_CALL_SECONDS = 15;

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

	/** A copy from backups: nothing is asked of the source. */
	const BACKUPS_RUN_STEPS = array(
		array('op' => 'host_report',   'on' => 'copy'),
		array('op' => 'copy_look',     'on' => 'copy'),
		array('op' => 'copy_take_key', 'on' => 'copy'),
		array('op' => 'copy_stage',    'on' => 'copy'),
		array('op' => 'copy_restore',  'on' => 'copy'),
		array('op' => 'site_census',   'on' => 'copy'),
	);

	/** The ways an address moves, by name. */
	const METHOD_PROXIED = 'proxied_origin';
	const METHOD_IP_SWAP = 'ip_swap';
	const METHOD_MANUAL  = 'manual';

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

	/**
	 * Reads a chain's manifest from backup storage: fn(ManagedNode $source,
	 * string $chain_id): string. Null reads it with the target's credentials.
	 * A variable for the reason $chain_lister is one.
	 *
	 * @var callable|null
	 */
	public static $manifest_reader = null;

	/**
	 * Starts the worker that follows local steps: fn(int $copy_id). Null
	 * starts utils/advance_site_copy.php detached; a test sets a no-op.
	 *
	 * @var callable|null
	 */
	public static $worker_starter = null;

	// ── Preflight ──────────────────────────────────────────────────────────

	/**
	 * Why this node cannot be copied now: one line per reason, empty when it
	 * can. Read-only; nothing here asks the node anything.
	 *
	 * A copy from backups asks nothing of the source's agent, so its words and
	 * its reported recovery-key state are not required: the backups and the
	 * owner's recovery key are.
	 *
	 * @param bool $for_new_copy also refuse when the node already has a copy
	 * @param string $from SiteCopy::FROM_SOURCE or FROM_BACKUPS
	 */
	public static function source_refusals(ManagedNode $source, bool $for_new_copy = true, string $from = SiteCopy::FROM_SOURCE): array {
		$backups = $from === SiteCopy::FROM_BACKUPS;
		$why = array();
		if ($source->get('mgn_delete_time')) {
			return array('It has been removed from the dashboard.');
		}
		if (!$source->hosts_site()) {
			return array('It hosts no Joinery site.');
		}
		// A copy from backups asks nothing of the source's machine, so a
		// container site's backups restore onto bare metal as any site's do.
		// A copy of the running site freezes its machine, which a container
		// cannot do to itself.
		if (!$backups && trim((string)$source->get('mgn_container_name')) !== '') {
			$why[] = 'It is a container site. A copy of a running site is bare metal to bare metal, one site per '
				. 'machine; a container site is copied from its backups.';
		}
		if (!$source->is_operational()) {
			$why[] = 'It is not a working site (' . $source->install_state_label() . ').';
		}
		// A management node is copied only from its backups: its secrets reach
		// the copy through the owner's recovery key, and the dormant copy runs
		// none of its fleet's work. Never this management node itself, whose
		// own row the switch-over would swap from under it.
		if ($source->is_self()) {
			$why[] = 'It is this management node. A management node is never copied by itself.';
		} elseif (!$backups && $source->is_management_node()) {
			$why[] = 'It is a management node. A management node carries the fleet\'s control and is copied only from '
				. 'its backups.';
		}
		$floor = $backups ? JobCommandBuilder::COPY_FROM_BACKUPS_MIN_VERSION : JobCommandBuilder::COPY_SOURCE_MIN_VERSION;
		$version = trim((string)$source->get('mgn_joinery_version'));
		if ($version === '') {
			$why[] = 'Its release is not known yet. Run a status check on it.';
		} elseif (version_compare($version, $floor, '<')) {
			$why[] = "It runs release {$version}. A copy" . ($backups ? ' from backups' : '') . " needs {$floor}"
				. ' or newer, because the copy installs the source\'s own release. Update it first.';
		}
		if (!$backups) {
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

	/** The plan this management node created the source's server on, or ''. */
	public static function source_instance_type(ManagedNode $source): string {
		if (trim((string)$source->get('mgn_container_name')) !== '') {
			return '';   // a container's plan is its shared server's, not its own
		}
		$p = CustomerCloudProvision::latest_for_node($source->key);
		return ($p && (string)$p->get('cvp_provider') === 'linode') ? trim((string)$p->get('cvp_instance_type')) : '';
	}

	/** The memory the source's machine reports, in bytes, or 0 when it has not said. */
	public static function source_memory_bytes(ManagedNode $source): int {
		$report = json_decode((string)$source->get('mgn_last_host_report'), true);
		$bytes = is_array($report) ? (int)($report['memory']['total_bytes'] ?? 0) : 0;
		if ($bytes <= 0) {
			$status = json_decode((string)$source->get('mgn_last_status_data'), true);
			$bytes = is_array($status) ? (int)($status['memory_total_mb'] ?? 0) * 1048576 : 0;
		}
		return $bytes;
	}

	/**
	 * The copy's server is the source's size: the source's own plan when this
	 * management node created its server, otherwise the smallest plan with at
	 * least the memory the source's machine reports and the disk this copy
	 * needs (a container reports its whole shared server's memory).
	 *
	 * @param callable $types returns the provider's plans, [id, memory_mb, disk_mb], smallest first
	 * @throws SiteCopyException when the source's size is not known
	 */
	public static function same_size_type(ManagedNode $source, string $from, callable $types): string {
		$own = self::source_instance_type($source);
		if ($own !== '') {
			return $own;
		}
		$memory = self::source_memory_bytes($source);
		if ($memory <= 0) {
			throw new SiteCopyException('This site\'s server has not reported its memory, so the copy\'s size is not known. '
				. 'Run a status check on it, then try again.');
		}
		$disk = self::disk_needed(self::newest_chain($source, self::chain_age_limit($from))) + self::OS_DISK;
		foreach ($types() as $t) {
			if ($t['memory_mb'] * 1048576 >= $memory && $t['disk_mb'] * 1048576 >= $disk) {
				return $t['id'];
			}
		}
		throw new SiteCopyException('No plan has ' . BackupRunner::human($memory) . ' of memory and '
			. BackupRunner::human($disk) . ' of disk.');
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
	 * A copy from backups takes the newest chain at any age ($max_hours null):
	 * its age is the data lost, and the owner sees it before typing the key.
	 *
	 * @return array{chain_id:string, time:string, bytes:int, files:int, db:int}
	 */
	public static function newest_chain(ManagedNode $source, ?int $max_hours = self::BACKUP_MAX_AGE_HOURS): array {
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
			if (!$when || ($max_hours !== null && time() - $when > $max_hours * 3600)) {
				throw new SiteCopyException('The source\'s newest backup is from ' . ($newest ?: 'an unknown time')
					. ', more than ' . $max_hours . ' hours ago. Run a backup of it from its Backups tab, then copy.');
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
	public static function start_new_server(ManagedNode $source, array $opts, $user_id, string $from = SiteCopy::FROM_SOURCE): SiteCopy {
		self::refuse_start($source, $from);
		$account = (string)($opts['account'] ?? '');
		$region = trim((string)($opts['region'] ?? ''));
		$type = trim((string)($opts['type'] ?? ''));
		if (!preg_match('/^[a-z0-9-]{2,40}$/', $region)) {
			throw new SiteCopyException('Choose the new server\'s region.');
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
		if ($type === '') {
			$token = $account === 'operator' ? ProvisionCustomerCloud::operator_compute_token()
				: (string)(($t = $cca->getToken()) ? $t->getAccessToken() : '');
			$type = self::same_size_type($source, $from, function () use ($token) {
				return (new LinodeComputeDriver($token))->instanceTypes();
			});
		}
		if (!preg_match('/^[a-z0-9-]{2,60}$/', $type)) {
			throw new SiteCopyException('Choose the new server\'s type.');
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

		$copy = self::new_record($source, $user_id, $from);
		$copy->set('scp_cvp_customer_cloud_provision_id', (int)$provision->key);
		$copy->save();
		return $copy;
	}

	/** Record a copy onto a server the owner brings; the page shows them the command. */
	public static function start_own_server(ManagedNode $source, $user_id, string $from = SiteCopy::FROM_SOURCE): SiteCopy {
		self::refuse_start($source, $from);
		$copy = self::new_record($source, $user_id, $from);
		$copy->save();
		return $copy;
	}

	private static function refuse_start(ManagedNode $source, string $from): void {
		if (!in_array($from, array(SiteCopy::FROM_SOURCE, SiteCopy::FROM_BACKUPS), true)) {
			throw new SiteCopyException('Choose whether the copy comes from the running site or from its backups.');
		}
		$why = self::source_refusals($source, true, $from);
		if ($why) {
			throw new SiteCopyException('This site cannot be copied: ' . implode(' ', $why));
		}
		self::newest_chain($source, self::chain_age_limit($from));
	}

	/** How old the newest backup may be: a day for a live source, any age from backups. */
	public static function chain_age_limit(string $from): ?int {
		return $from === SiteCopy::FROM_BACKUPS ? null : self::BACKUP_MAX_AGE_HOURS;
	}

	private static function new_record(ManagedNode $source, $user_id, string $from = SiteCopy::FROM_SOURCE): SiteCopy {
		$copy = new SiteCopy(NULL);
		$copy->set('scp_from', $from);
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
		if (AgentVocabulary::missing_words($node, $copy->from_backups() ? self::BACKUPS_COPY_WORDS : self::COPY_WORDS)) {
			return; // joined; its first poll reports what it can do
		}
		self::begin_run($copy);
	}

	/** Lay out a run's steps, after checking both ends still hold. */
	private static function begin_run(SiteCopy $copy): void {
		try {
			$source = self::source_row($copy);
			$why = self::source_refusals($source, false, (string)$copy->get('scp_from'));
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
			$chain = self::newest_chain($source, self::chain_age_limit((string)$copy->get('scp_from')));
		} catch (Exception $e) {
			self::halt($copy, $e->getMessage());
			return;
		}
		$steps = array();
		foreach ($copy->from_backups() ? self::BACKUPS_RUN_STEPS : self::RUN_STEPS as $s) {
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

		if (in_array($step['op'], self::LOCAL_STEPS, true)) {
			// Steps with no job: done here (a provider call, a probe, the rows
			// swapped back).
			return self::run_local($copy, $steps, $pos, $source, $node);
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
			case 'copy_look':
				return JobCommandBuilder::build_copy_look($node);
			case 'copy_take_key':
				return JobCommandBuilder::build_copy_take_key($node, self::key_request($source, (string)$copy->get('scp_chain_id')));
			case 'provision_certificate':
				return JobCommandBuilder::build_provision_certificate($source, array('domain' => self::site_domain($source)));
		}
		throw new SiteCopyException("unknown step {$op}");
	}

	/**
	 * What the copy shows its owner, and opens with what they give back, to
	 * take a chain's key from backups: the chain's manifest hash, its newest
	 * run, and the chain's data key as sealed to the recovery key (the
	 * recovery recipient of the manifest's envelope). Ciphertext and facts
	 * only: what opens it is typed on the copy's own page and never comes
	 * here.
	 */
	public static function key_request(ManagedNode $source, string $chain_id): array {
		$body = self::$manifest_reader ? (string)(self::$manifest_reader)($source, $chain_id) : self::read_manifest($source, $chain_id);
		$manifest = json_decode($body, true);
		if (!is_array($manifest) || (string)($manifest['chain_id'] ?? '') !== $chain_id) {
			throw new SiteCopyException("chain {$chain_id}'s manifest in backup storage could not be read");
		}
		$recipient = null;
		foreach ((array)($manifest['envelope']['recipients'] ?? array()) as $r) {
			if (($r['kind'] ?? '') === 'recovery') {
				$recipient = $r;
			}
		}
		if (!$recipient || !preg_match('/^[0-9a-f]{64}$/', (string)($recipient['fingerprint'] ?? ''))
				|| base64_decode((string)($recipient['sealed'] ?? ''), true) === false) {
			throw new SiteCopyException("chain {$chain_id} carries no key sealed to a recovery key, so it cannot be opened without its server");
		}
		$known = strtolower(trim((string)$source->get('mgn_backup_recovery_fpr')));
		if ($known !== '' && $known !== (string)$recipient['fingerprint']) {
			throw new SiteCopyException("chain {$chain_id} is sealed to recovery key " . substr((string)$recipient['fingerprint'], 0, 16)
				. ', and the site last reported ' . substr($known, 0, 16) . '. Its newest backups may predate a key change.');
		}
		$newest = '';
		foreach ((array)($manifest['runs'] ?? array()) as $run) {
			if ((string)($run['time'] ?? '') > $newest) {
				$newest = (string)$run['time'];
			}
		}
		return array(
			'chain_id'             => $chain_id,
			'manifest_sha256'      => hash('sha256', $body),
			'run_time'             => $newest,
			'recovery_fingerprint' => (string)$recipient['fingerprint'],
			'recovery_sealed'      => (string)$recipient['sealed'],
			'site'                 => self::site_domain($source),
		);
	}

	private static function read_manifest(ManagedNode $source, string $chain_id): string {
		$target = JobCommandBuilder::get_target($source);
		$creds = $target ? $target->get_credentials() : null;
		if (empty($creds)) {
			throw new SiteCopyException('This management node holds no credentials for the source\'s backup storage.');
		}
		$path = '/' . ltrim(BackupChainListHelper::chain_path($target, (string)$source->get('mgn_slug'), BackupProfile::MANAGER, $chain_id), '/')
			. '/' . BackupChain::MANIFEST_NAME;
		$got = S3Signer::get($creds, $target->get('bkt_bucket'), $path);
		if ((int)($got['status'] ?? 0) !== 200) {
			throw new SiteCopyException("chain {$chain_id}'s manifest could not be fetched from backup storage (HTTP " . (int)($got['status'] ?? 0) . ')');
		}
		return (string)$got['body'];
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
			case 'copy_look':
				$r = json_decode((string)$job->get('mjb_result'), true);
				$look = is_array($r) ? (string)($r['look_path'] ?? '') : '';
				if (!preg_match('#^/\.joinery-look/[0-9a-f]{32}$#', $look)) {
					return 'the copy reported no look path, so its own page cannot be reached';
				}
				$copy->set('scp_look_path', $look);
				return null;
			case 'copy_take_key':
				$r = json_decode((string)$job->get('mjb_result'), true);
				return (is_array($r) && (string)($r['chain_id'] ?? '') === (string)$copy->get('scp_chain_id')) ? null
					: 'the copy took no key for chain ' . $copy->get('scp_chain_id');
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
		// A container reports its whole shared server's memory, not the
		// site's, so a container site may be copied onto a smaller server.
		$s_mem = (is_array($s) && trim((string)$source->get('mgn_container_name')) === '') ? (int)($s['memory']['total_bytes'] ?? 0) : 0;
		if ($s_mem > 0 && $t_mem > 0 && $t_mem < $s_mem * 0.9) {
			return 'the copy\'s server has ' . BackupRunner::human($t_mem) . ' of memory and the source has '
				. BackupRunner::human($s_mem) . '; a copy needs at least the source\'s';
		}
		try {
			$chain = self::newest_chain($source, self::chain_age_limit((string)$copy->get('scp_from')));
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
		if ($copy->status() === SiteCopy::STATUS_UNDOING) {
			$switch = $copy->switch_record();
			$switch['address_at'] = 'source';
			$switch['moved_back_time'] = gmdate('Y-m-d H:i:s');
			$copy->set_switch_record($switch);
			$copy->set('scp_status', SiteCopy::STATUS_READY);
			$copy->save();
			return;
		}
		$final = $copy->status() === SiteCopy::STATUS_FREEZING;
		if ($copy->from_backups()) {
			// Nothing to compare with: the source is gone. A switch-over from
			// backups has no final copy, only the source's server powered off.
			if ($final) {
				$switch = $copy->switch_record();
				$switch['final_copied_time'] = gmdate('Y-m-d H:i:s');
				$copy->set_switch_record($switch);
				$copy->set('scp_status', SiteCopy::STATUS_READY);
				$copy->save();
				return;
			}
			$c_job = self::step_job($steps, 'site_census', 'copy');
			$c = $c_job ? JobResultProcessor::census_of($c_job) : null;
			if ($c === null) {
				self::halt($copy, 'The copy\'s census is missing.');
				return;
			}
			$verdict = SiteCensus::check($c);
			$copy->set('scp_census', $verdict);
			if ((int)$verdict['blocking'] > 0) {
				self::halt($copy, 'The copy\'s census found ' . (int)$verdict['blocking'] . ' problem(s): see below.');
				return;
			}
			$copy->set('scp_status', SiteCopy::STATUS_DORMANT);
			$copy->set('scp_last_copied_time', gmdate('Y-m-d H:i:s'));
			$copy->save();
			return;
		}
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

	// ── Switching over (steps 7-10) ───────────────────────────────────────

	/** Is this copy in a switch-over: started, under way, or done with the way back open? */
	public static function in_switch_over(SiteCopy $copy): bool {
		if (in_array($copy->status(), array(SiteCopy::STATUS_FREEZING, SiteCopy::STATUS_READY, SiteCopy::STATUS_STARTING,
				SiteCopy::STATUS_UNDOING, SiteCopy::STATUS_SWITCHED, SiteCopy::STATUS_RETURNING), true)) {
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
	 * The ways this copy's address could move, from this management node's
	 * own records and the domain's DNS host: method => why it cannot (empty
	 * when it can). The provider and the DNS host are asked on the press.
	 */
	public static function switch_methods(SiteCopy $copy): array {
		try {
			$source = self::source_row($copy);
		} catch (Exception $e) {
			return array();
		}
		$node = self::copy_row($copy, true);
		$domain = self::site_domain($source);
		$out = array();
		$out[self::METHOD_PROXIED] = ($domain !== '' && ProxiedOriginMove::driver_class_for($domain)) ? array()
			: array($domain === '' ? 'The site has no https address on record.' : $domain . '\'s DNS is not at a host that proxies (Cloudflare).');
		$out[self::METHOD_IP_SWAP] = $node ? IpSwapMove::record_refusals($source, $node) : array('The copy has no server.');
		if ($copy->from_backups()) {
			$out[self::METHOD_MANUAL] = array();
		}
		return $out;
	}

	/** The words a switch-over asks of each side. */
	private static function switch_words(SiteCopy $copy): array {
		return $copy->from_backups()
			? array('source' => array(), 'copy' => array('take_node_id', 'site_quiet', 'provision_certificate'))
			: array('source' => self::SWITCH_SOURCE_WORDS, 'copy' => self::SWITCH_COPY_WORDS);
	}

	/**
	 * Why this copy cannot switch over now: one line per reason, empty when it
	 * can. Read-only; how the address moves is checked on the press.
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
		$from = (string)$copy->get('scp_from');
		$why = self::source_refusals($source, false, $from);
		$words = self::switch_words($copy);
		$missing = array_values(array_filter($words['source'], function ($w) use ($source) {
			return !JobCommandBuilder::has_primitive($source, $w);
		}));
		if ($missing) {
			$why[] = 'The site: ' . AgentVocabulary::needs_newer_agent_text($source, $missing);
		}
		$missing = array_values(array_filter($words['copy'], function ($w) use ($node) {
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
			$why[] = 'The copy reported no look path. The switch-over asks for it at the site\'s name, because only the '
				. 'copy answers it, to prove the address reaches the copy. Copy again.';
		}
		if (!$why) {
			try {
				$chain = self::newest_chain($source, self::chain_age_limit($from));
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
	 * Switch over: check how the address will move (the DNS records with the
	 * owner's token, or the two servers at the provider: read-only), then
	 * freeze the site and run the final copy. A copy from backups has no site
	 * to freeze: the source's server is powered off when this management node
	 * created it, and the copy is ready to take the address.
	 */
	public static function begin_switch(SiteCopy $copy, string $method, ?DnsProvider $driver, $user_id): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		$template = array();
		try {
			$copy->load();
			$why = self::switch_refusals($copy);
			if ($why) {
				throw new SiteCopyException('This copy cannot switch over yet: ' . implode(' ', $why));
			}
			$methods = self::switch_methods($copy);
			if (!array_key_exists($method, $methods)) {
				throw new SiteCopyException('Choose how the address moves.');
			}
			if ($methods[$method]) {
				throw new SiteCopyException('The address cannot move this way: ' . implode(' ', $methods[$method]));
			}
			$source = self::source_row($copy);
			$node = self::copy_row($copy, true);
			$domain = self::site_domain($source);
			$record = array('method' => $method, 'address_at' => 'source', 'frozen_time' => gmdate('Y-m-d H:i:s'),
				'started_by' => $user_id ? (int)$user_id : null);
			try {
				if ($method === self::METHOD_PROXIED) {
					if (!$driver) {
						throw new SiteCopyException('Enter the DNS token.');
					}
					$plan = ProxiedOriginMove::plan($driver, $domain, $source, $node);
					$record['provider'] = $driver::getKey();
					$record['zone'] = $plan['zone'];
					$record['records'] = $plan['records'];
				} elseif ($method === self::METHOD_IP_SWAP) {
					$record['swap'] = IpSwapMove::plan($source, $node, $domain);
				} else {
					$addresses = ProxiedOriginMove::machine_addresses($node);
					$record['to'] = array_merge($addresses[4], $addresses[6]);
				}
			} catch (SiteCopyException $e) {
				throw $e;
			} catch (Exception $e) {
				throw new SiteCopyException('Nothing was ' . ($copy->from_backups() ? 'changed' : 'frozen') . ': ' . $e->getMessage());
			}
			if ($copy->from_backups()) {
				// The source's server, when this management node created it, is
				// powered off first: two machines must never run the site.
				$sp = IpSwapMove::provision_of($source);
				if (isset($record['swap'])) {
					$record['power'] = $record['swap'];
				} elseif ($sp && (string)$sp->get('cvp_provider') === 'linode' && !$sp->is_transferred()) {
					$record['power'] = array('source' => array('provision_id' => (int)$sp->key,
						'instance_id' => (string)$sp->get('cvp_instance_id')));
				}
				if (isset($record['power'])) {
					$template[] = array('op' => 'power_off', 'on' => 'source', 'arg' => 'source');
				}
			} else {
				$template = self::FREEZE_STEPS;
			}
			$source->set('mgn_install_state', 'switching');
			$source->save();
			$copy->set_switch_record($record);
			$copy->set('scp_census', null);
			self::lay_steps($copy, $template, SiteCopy::STATUS_FREEZING, 'freeze');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
		self::start_worker_for($copy, $template);
	}

	/**
	 * Step 10's jobs: the node id, then the copy starts as the site. A copy
	 * from backups then gets its certificate: none travelled (the source could
	 * not export one), and its name points at it now.
	 */
	private static function start_steps(SiteCopy $copy, ?ManagedNode $node): array {
		$steps = array();
		if (!$node || trim((string)$node->get('mgn_install_state')) !== 'retired') {
			$steps[] = array('op' => 'take_node_id', 'on' => 'copy');
		}
		$steps[] = array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'off');
		if ($copy->from_backups()) {
			$steps[] = array('op' => 'provision_certificate', 'on' => 'source');
		}
		return $steps;
	}

	/**
	 * Move the address (step 9) and start the copy (step 10). Through the
	 * proxy, with the owner's DNS token, here and now: unproven, the records
	 * move back and the site stays frozen. By IP swap, as local steps the
	 * worker follows: unproven, the swap is undone. By the owner's own DNS
	 * change: the copy's answer at the site's name is the proof.
	 */
	public static function move_address(SiteCopy $copy, ?DnsProvider $driver, $user_id): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		$template = array();
		try {
			$copy->load();
			if ($copy->status() !== SiteCopy::STATUS_READY) {
				throw new SiteCopyException('The address moves once the ' . ($copy->from_backups() ? 'switch-over has begun' : 'final copy has matched')
					. '; this copy is ' . strtolower($copy->status_label()) . '.');
			}
			$source = self::source_row($copy);
			$node = self::copy_row($copy, true);
			if (trim((string)$source->get('mgn_install_state')) !== 'switching' || !$node
					|| trim((string)$node->get('mgn_install_state')) !== 'copy') {
				throw new SiteCopyException('The site and its copy are not as the switch-over left them; go back.');
			}
			$switch = $copy->switch_record();
			$method = (string)($switch['method'] ?? self::METHOD_PROXIED);
			$switch['move_failure'] = null;
			if ($method === self::METHOD_IP_SWAP) {
				$template = array(
					array('op' => 'power_off',   'on' => 'source', 'arg' => 'source'),
					array('op' => 'ip_swap',     'on' => 'source', 'arg' => 'over'),
					array('op' => 'power_cycle', 'on' => 'copy',   'arg' => 'copy'),
					array('op' => 'probe',       'on' => 'copy'),
				);
				$switch['moved_time'] = gmdate('Y-m-d H:i:s');
				$copy->set_switch_record($switch);
				self::lay_steps($copy, array_merge($template, self::start_steps($copy, $node)), SiteCopy::STATUS_STARTING, 'start');
				self::advance_locked($copy);
				return;
			}
			if ($method === self::METHOD_MANUAL) {
				$template = array(array('op' => 'probe', 'on' => 'copy'));
				$switch['address_at'] = 'copy';
				$switch['moved_time'] = gmdate('Y-m-d H:i:s');
				$copy->set_switch_record($switch);
				self::lay_steps($copy, array_merge($template, self::start_steps($copy, $node)), SiteCopy::STATUS_STARTING, 'start');
				self::advance_locked($copy);
				return;
			}
			if (!$driver) {
				throw new SiteCopyException('Enter the DNS token.');
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
			$switch['zone'] = $plan['zone'];
			$switch['records'] = $plan['records'];
			$switch['address_at'] = 'copy';
			$switch['moved_time'] = gmdate('Y-m-d H:i:s');
			$copy->set_switch_record($switch);
			$copy->save();

			$proof = ProxiedOriginMove::prove($domain, (string)$copy->get('scp_look_path'), null, !$copy->from_backups());
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
			self::lay_steps($copy, self::start_steps($copy, $node), SiteCopy::STATUS_STARTING, 'start');
			self::advance_locked($copy);
		} finally {
			self::unlock();
			self::start_worker_for($copy, $template);
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
			self::lay_steps($copy, self::start_steps($copy, self::copy_row($copy, true)), SiteCopy::STATUS_STARTING, 'start');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
	}

	/**
	 * The way back, from anywhere in a switch-over: the address back to the
	 * site's server (with the owner's token, when it moved through the proxy;
	 * by swapping back, when the addresses swapped), and the site running
	 * there again. After the node swap the copy's machine is quieted while it
	 * is still the node, then the rows swap back, and the copy is discarded:
	 * its agent answers as a node whose row holds another key. Anything
	 * written on the copy since it started stays there.
	 *
	 * A copy from backups goes back only before it takes the node: the site it
	 * would go back to is dead. Going back leaves the source's server off.
	 */
	public static function go_back(SiteCopy $copy, ?DnsProvider $driver): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		$template = array();
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
			$backups = $copy->from_backups();
			if ($swapped && $backups) {
				throw new SiteCopyException('This copy came from backups and has become the site; its old server is dead, '
					. 'so there is nothing to go back to. Keep the switch-over.');
			}
			$switch = $copy->switch_record();
			$method = (string)($switch['method'] ?? self::METHOD_PROXIED);
			if ($method === self::METHOD_PROXIED && ($switch['address_at'] ?? 'source') === 'copy') {
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
			if ($swapped) {
				$template[] = array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'on');
				$template[] = array('op' => 'go_back',    'on' => 'source');
			}
			if ($method === self::METHOD_IP_SWAP && (!empty($switch['moved_time']) || ($switch['address_at'] ?? '') === 'copy')) {
				$template[] = array('op' => 'ip_swap',     'on' => 'source', 'arg' => 'back');
				$template[] = array('op' => 'power_cycle', 'on' => 'copy',   'arg' => 'copy');
			}
			if (!$backups) {
				if ($method === self::METHOD_IP_SWAP) {
					$template[] = array('op' => 'power_on', 'on' => 'source', 'arg' => 'source');
				}
				$template[] = array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'off');
			}
			self::lay_steps($copy, $template, SiteCopy::STATUS_RETURNING, 'return');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
		self::start_worker_for($copy, $template);
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

	// ── Local steps ────────────────────────────────────────────────────────

	/**
	 * One local step: done here, by this management node. True when it
	 * passed. A step that is waiting on a machine keeps its state and is
	 * called again; one that has waited past its limit, or throws, stops.
	 */
	private static function run_local(SiteCopy $copy, array $steps, int $pos, ManagedNode $source, ManagedNode $node): bool {
		$step = $steps[$pos];
		$op = (string)$step['op'];
		$switch = $copy->switch_record();
		$state = (array)($step['state'] ?? array());
		$state['started'] = $state['started'] ?? time();
		try {
			if ($op === 'go_back') {
				SiteCopySwap::go_back($source, $node);
				$switch['swapped_back_time'] = gmdate('Y-m-d H:i:s');
				$done = true;
			} elseif ($op === 'probe') {
				$proof = ProxiedOriginMove::prove(self::site_domain($source), (string)$copy->get('scp_look_path'), self::PROBE_CALL_SECONDS,
					!$copy->from_backups());
				$proof['seconds'] = time() - (int)$state['started'];
				$switch['proof'] = $proof;
				$done = !empty($proof['proven']);
				$state['last'] = (string)$proof['last'];
			} else {
				$plan = $switch['swap'] ?? $switch['power'] ?? null;
				if (!is_array($plan)) {
					throw new SiteCopyException('the switch-over recorded no server to act on');
				}
				$arg = (string)$step['arg'];
				$done = IpSwapMove::step($op, $arg, $plan, $state) === 'done';
				if ($done && $op === 'ip_swap') {
					$switch['address_at'] = $arg === 'back' ? 'source' : 'copy';
					$switch[$arg === 'back' ? 'moved_back_time' : 'swapped_time'] = gmdate('Y-m-d H:i:s');
					$switch['moved_back_notes'] = array_merge((array)($switch['moved_back_notes'] ?? array()), (array)($state['rdns'] ?? array()));
				}
				if ($done && $arg === 'source') {
					$switch['source_off'] = $op === 'power_off';
				}
			}
		} catch (Exception $e) {
			self::fail_local($copy, $steps, $pos, $e->getMessage());
			return false;
		}
		if (!$done) {
			$limit = self::STEP_WAIT_MINUTES[$op] ?? 10;
			if (time() - (int)$state['started'] > $limit * 60) {
				$copy->set_switch_record($switch);
				self::fail_local($copy, $steps, $pos, "it did not finish in {$limit} minutes"
					. (!empty($state['last']) ? ' (last answer: ' . $state['last'] . ')' : ''));
				return false;
			}
			$steps[$pos]['state'] = $state;
			$steps[$pos]['verdict'] = 'running';
			$copy->set_switch_record($switch);
			$copy->set_steps($steps);
			$copy->save();
			return false;
		}
		$steps[$pos]['state'] = $state;
		$steps[$pos]['verdict'] = 'passed';
		$steps[$pos]['reason'] = '';
		$copy->set_switch_record($switch);
		$copy->set_steps($steps);
		$copy->save();
		return true;
	}

	/**
	 * A local step of moving the address failed. Before the copy takes the
	 * node, an address swap is undone (the addresses swapped back, the copy
	 * restarted on its own, the source started again, still frozen) and the
	 * copy is ready again; an address the owner moved by hand is theirs to
	 * check. Anything else stops the copy where it is.
	 */
	private static function fail_local(SiteCopy $copy, array $steps, int $pos, string $why): void {
		$switch = $copy->switch_record();
		$method = (string)($switch['method'] ?? '');
		$op = (string)$steps[$pos]['op'];
		if (($switch['phase'] ?? '') !== 'start' || !in_array($op, array('power_off', 'ip_swap', 'power_cycle', 'probe'), true)
				|| !in_array($method, array(self::METHOD_IP_SWAP, self::METHOD_MANUAL), true)) {
			self::fail_step($copy, $steps, $pos, $why);
			return;
		}
		$steps[$pos]['verdict'] = 'failed';
		$steps[$pos]['reason'] = mb_substr($why, 0, 500);
		$copy->set_steps(self::skip_pending($steps));
		$switch['move_failure'] = $op . ': ' . mb_substr($why, 0, 500);
		if ($method === self::METHOD_MANUAL) {
			$switch['address_at'] = 'source';
			$copy->set_switch_record($switch);
			$copy->set('scp_status', SiteCopy::STATUS_READY);
			$copy->save();
			return;
		}
		$copy->set_switch_record($switch);
		$undo = array();
		if ($op !== 'power_off') {
			$undo[] = array('op' => 'ip_swap',     'on' => 'source', 'arg' => 'back');
			$undo[] = array('op' => 'power_cycle', 'on' => 'copy',   'arg' => 'copy');
		}
		if (!$copy->from_backups()) {
			$undo[] = array('op' => 'power_on', 'on' => 'source', 'arg' => 'source');
		}
		self::lay_steps($copy, $undo, SiteCopy::STATUS_UNDOING, 'undo');
	}

	/**
	 * Start the worker that follows local steps, when a phase has any: it
	 * advances the copy every few seconds until the copy waits on an agent
	 * job or stops. Without it the next task tick moves the copy on.
	 */
	private static function start_worker_for(SiteCopy $copy, array $template): void {
		$local = false;
		foreach ($template as $t) {
			$local = $local || in_array($t['op'], self::LOCAL_STEPS, true);
		}
		if (!$local || !$copy->key) {
			return;
		}
		if (self::$worker_starter) {
			(self::$worker_starter)((int)$copy->key);
			return;
		}
		$worker = PathHelper::getIncludePath('plugins/server_manager/utils/advance_site_copy.php');
		$php = (string)(defined('PHP_BINARY') ? PHP_BINARY : '');
		// The web tier's PHP_BINARY is php-fpm, which runs no script.
		if ($php === '' || strpos(basename($php), 'fpm') !== false || !is_executable($php)) {
			$php = 'php';
		}
		$log = PathHelper::getSiteRoot() . '/logs/site_copy_worker.log';
		exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . (int)$copy->key
			. ' >> ' . escapeshellarg($log) . ' 2>&1 &');
	}

	/** Is the copy's current step a local one, so a worker should keep calling? */
	public static function on_local_step(SiteCopy $copy): bool {
		if (!in_array($copy->status(), SiteCopy::MOVING_STATUSES, true)) {
			return false;
		}
		foreach ($copy->steps() as $s) {
			if ($s['verdict'] !== 'passed') {
				return in_array($s['op'], self::LOCAL_STEPS, true);
			}
		}
		return false;
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
