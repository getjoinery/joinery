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
 * address moves, and the copy starts. A container source's old container is
 * stopped on its host, and kept stopped until it is removed, once the copy
 * has taken the site (hold_container, WP14). There is no way back once it has
 * taken the node: the site it would go back to is dead.
 *
 * Local steps (a provider call, a probe, the row swap back) take no agent job.
 * A press that lays them out starts a short worker (utils/advance_site_copy.php)
 * that keeps advancing the copy until it waits on an agent job or stops, so a
 * machine's reboot is followed in seconds rather than at the next task tick.
 *
 * @version 1.38 - a copy onto a host refuses, saying so, when this management node holds no archive of the site's
 *                release (holds_release; UpgradeRetention keeps the ones it applies, B55)
 * @version 1.37 - a container source's host stages the site's own certificate inside it for the export and clears it
 *                 after (site_certificate_stage), so its copy starts on the site's certificate, as a bare-metal one
 *                 does (C7's B49)
 * @version 1.36 - a settled move by DNS names the copy's IPv6 in reverse DNS too, from the AAAA it moved (B33)
 * @version 1.35 - a copy onto a new server takes its plan from those its region sells, and refuses one it does not
 *                 sell before anything is made (B31)
 * @version 1.34 - a manual switch's way back names the records the owner points back (manual_records_back), and
 *                 the note stays while DNS still names the copy (went_back_notes, C4's B28)
 * @version 1.33 - a DNS switch records the TTL the DNS host holds for each lowered record (ttl_held, B12)
 * @version 1.32 - a kept switch-over keeps the old server Keep named: removing the old row afterwards made the tab
 *                 name the copy's own server, the one the site now runs on (B22)
 * @version 1.31 - server_to_delete reads the row's newest provision whether or not it was removed: removing a
 *                 row (Discard, a way back after the take-over) removes its provision, and the server stays
 * @version 1.30 - a way back before the copy took the node asks it no untrust (B13); a switch-over records what still names the old server's address (SiteCopyAddressFacts, D1's
 *                IP-bound list), and once a move by DNS settles sets the copy's reverse DNS on a server made here
 * @version 1.29 - stale_release(): a copy made at an older release than its site's is refused by the Refresh
 *                before anything moves and named on its tab; the switch-over asks the site its release first
 * @version 1.28 - review of 1.27 (B2-B9, B12): the copy is proven at its own address whether or not anything
 *                 forwards; a forward needs a shared address family, or the switch notes why not; TTLs are lowered
 *                 last and put back by any way back; a settle that stops can be retried or kept; any old server
 *                 not powered off or held is quieted at settle; a swap that needs both servers off powers the
 *                 copy off too; a running machine whose report does not list its sites is asked for one
 * @version 1.27 - alive or dead is the source's, for every target (D5); the ways the address can move are
 *                 SiteCopyMethods' (D6), the switch by DNS record with its forward and watch among them (D1); a
 *                 bare-metal source whose machine serves anything else is refused (D8); the copy's encryption is
 *                 decided by copy_encryption(), never ticked (specs/site_copy_final_test.md E1)
 * @version 1.26 - the copy's plan and a dead source's power-off are its provider's (ComputeDriverRegistry), not
 *                 Linode's by name (specs/site_copy_anything_to_anything.md D2)
 * @version 1.25 - a copy from backups stages the run whose key its owner opened (key_step_seq, vouched_seq):
 *                 a run the source made since is in a newer manifest the copy holds no vouch for, and staging
 *                 the newest halted every copy whose source backed up while it waited for its key (B20)
 * @version 1.24 - a copy onto a host, as a container there (specs/site_container_engine.md D9): start_on_host() and
 *                 host_refusals(); the host's agent installs it (site_install) and its join is approved by the key its
 *                 install printed; its switch-over freezes the site, from outside by a container source's own host too,
 *                 and makes a final copy that must match; its start lifts its quiet on its host, has its host issue the
 *                 certificate and holds the old container; going back is offered until it takes the node; a discard asks
 *                 its host to remove it (discard_copy); a provision_certificate step is filed on its issuer
 * @version 1.23 - a swap back keeps the AAAA record left at the copy as aaaa_left, and went_back_notes() names it only
 *                 while DNS still does (IpSwapMove::aaaa_note, B66)
 * @version 1.22 - a machine the copy lets go (discarded, gone back from, or the old server of a kept switch-over)
 *                 has the escrow keys held for it retired (DataRootEscrowSync::let_go, B64)
 * @version 1.21 - an address swapped back passes on what the swap noted (an AAAA record still at the copy, B61); a
 *                new move clears what the last undo said
 * @version 1.20 - a copy run from the running site backs it up first (backup_run, arg run) and copies the chain
 *                that backup lands in: the newest backup could be a day old and from before the source's
 *                upgrade, and its code was laid over the release the copy installed (B53). No age limit on the
 *                source's backups to start one (chain_age_limit() is gone); the final backup keeps its own.
 * @version 1.19 - a copy asks the source which release it runs as it starts and as its run begins: a site
 *                upgraded outside this node kept its old release on record, and a copy installed it (B48)
 * @version 1.18 - the owner's commands fetch the release and name the upgrade server from ReleaseStream: a
 *                 management node that serves no releases sends them to its own source (B38)
 * @version 1.17 - copy_born_encrypted(): the copy's new server is born with its data root encrypted by the form's tick,
 *                 on by default in one of our accounts, and never while data_root_encryption_offered is off
 *                 (specs/hosted_data_promise_disk_encryption.md D1a, C-2)
 * @version 1.16 - the copy's server goes in any account this node holds, ours ('oca:<id>') or a
 *                 customer's ('cca:<id>'); ours is our server, whoever asked (cloud_accounts_list)
 * @version 1.15 - key_request carries manifest_url, a link to the chain's newest manifest signed for as long as
 *                 the copy_take_key step may wait: the copy reads the backup at its provider itself
 *                 (specs/storage_targets.md F7)
 * @version 1.14 - the chain's newest manifest is read (one per run from chain version 3) and refused when its hash
 *                 differs from the one the broker recorded when the source wrote it (specs/storage_targets.md WP5)
 * @version 1.13 - a chain's manifest is read from whichever of the source's storage spaces holds it;
 *                 a copy's node is given no storage space of its own: its backups are its source's (backup_node_of)
 * @version 1.12 - a copy's node is given the target Where new backups go names
 * @version 1.11 - source_root_keys(): the root SSH keys the source's last host report lists; start_new_server carries them to
 *                 the copy (cvp_root_ssh_keys) when asked and the fingerprints the operator saw still match (WP15)
 * @version 1.10 - cleanup_left(): each kept switch-over's old container not yet removed, or removed with its
 *                 certificate still on its host, for as long as that lasts (B5);
 *                 a container source's old container is stopped and held on its host as the switch-over's last
 *                 step (hold_container, site_copy.md WP14); the host's agent must have the word to start
 * @version 1.9 - server_to_delete never names the copy's provision for a row that has a machine of its own: after a
 *                 kept switch-over from a container that provision is the live site's (B58); old_container() names a
 *                 container source's old row, removed from its host (site_copy.md WP14)
 * @version 1.8 - Copy again queues the run's first step at once (B52)
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
	const SOURCE_WORDS = array('backup_run', 'copy_export', 'site_census');

	/** The words the copy must report before a run starts. */
	const COPY_WORDS = array('host_report', 'copy_import', 'copy_stage', 'copy_restore', 'site_census');

	/** The words a copy from backups must report before a run starts. */
	const BACKUPS_COPY_WORDS = array('host_report', 'copy_look', 'copy_take_key', 'copy_stage', 'copy_restore', 'site_census');

	/**
	 * The words a copy onto a host asks of that host (specs/site_container_engine.md
	 * D9): it installs the copy, lifts its quiet once it takes over, issues its
	 * certificate, and removes it when it is discarded.
	 */
	const HOST_COPY_WORDS = array('site_install', 'site_quiet_host', 'provision_certificate', 'discard_copy');

	/**
	 * The words a copy asks of a container source's own host: the site's
	 * certificate staged inside it for each export (the host ends its TLS), the
	 * source held quiet from outside for its final copy, and its old container
	 * held stopped once the copy has taken the site.
	 */
	const SOURCE_HOST_WORDS = array('site_certificate_stage', 'site_quiet_host', 'hold_container');

	/**
	 * A release can be copied onto a host when it carries this: the host's half
	 * of a container's quiet state, which its install.sh and its container's
	 * start command go with.
	 */
	const HOST_COPY_RELEASE_FILE = 'maintenance_scripts/sysadmin_tools/site_quiet_host.sh';

	/** What the operating system and the installed release take on the copy's disk, beside what the copy needs. */
	const OS_DISK = 6442450944;

	/**
	 * A running site's agent must have polled within this to be copied as a
	 * running site (the agent-silent incident's threshold).
	 */
	const ALIVE_WITHIN_SECONDS = 7200;

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
		// A container source's old container, stopped on its host once the
		// copy has taken the site.
		'hold_container'  => 15,
		// A copy onto a host: its install (the image is built there), and the
		// host holding a site quiet from outside, or letting it go.
		'site_install'    => 90,
		'site_quiet_host' => 10,
		// A container source's certificate, staged for the export and cleared.
		'site_certificate_stage' => 10,
		// The copy's machine believing the old server's forwarded addresses.
		'trust_forwarder' => 10,
	);

	/** How long the copy's machine believes the old server, in hours: past any watch's wait. */
	const FORWARDER_TRUST_HOURS = 72;

	/** Steps this management node does itself, with no agent job. */
	const LOCAL_STEPS = array('go_back', 'power_off', 'power_on', 'power_cycle', 'ip_swap', 'probe');

	/** How long one call of the probe step asks before it gives the worker back. */
	const PROBE_CALL_SECONDS = 15;

	/**
	 * The steps of one run, in order. The source is backed up first, so the
	 * copy is of the site as it is now, at the release the copy installed: its
	 * newest backup could be from before an upgrade (B53).
	 */
	const RUN_STEPS = array(
		array('op' => 'host_report',  'on' => 'copy'),
		array('op' => 'backup_run',   'on' => 'source', 'arg' => 'run'),
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
	const METHOD_DNS     = 'dns';

	/** The words a switch-over asks of the source, beyond a copy's. */
	const SWITCH_SOURCE_WORDS = array('site_quiet', 'backup_run', 'copy_vouch', 'site_census');

	/** The words a switch-over asks of the copy, beyond a copy's. */
	const SWITCH_COPY_WORDS = array('copy_take_vouch', 'copy_stage', 'copy_restore', 'site_census', 'take_node_id', 'site_quiet');

	/**
	 * The final copy of a copy onto a host (Q2 there): FREEZE_STEPS, with a
	 * container source also held quiet from outside by its own host, once it
	 * shows its maintenance page (freeze_steps()), so no visitor meets a site
	 * whose outside calls fail. The copy keeps the chain's key its owner gave
	 * it; the source's vouch covers the run the freeze adds.
	 */
	const FREEZE_ON_HOST_STEP = array('op' => 'site_quiet_host', 'on' => 'source_host', 'arg' => 'on');

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
	 * string $chain_id): array{body: string, url: string}, the manifest and a
	 * signed link to it. Null reads it with the target's credentials. A
	 * variable for the reason $chain_lister is one.
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

	/**
	 * Whether a release carries a file: fn(string $version, string $file): bool.
	 * Null reads the release's signed manifest from this management node's
	 * published archives. A variable for the reason $chain_lister is one.
	 *
	 * @var callable|null
	 */
	public static $release_carrier = null;

	// ── Preflight ──────────────────────────────────────────────────────────

	/**
	 * Why this node cannot be copied now: one line per reason, empty when it
	 * can. Read-only; nothing here asks the node anything.
	 *
	 * Alive or dead is the source's, whatever the target
	 * (specs/site_copy_anything_to_anything.md D5). A running site's agent
	 * must answer, since it is frozen through it and copied once more at the
	 * switch. A dead site is asked nothing, so its words and its reported
	 * recovery-key state are not required: its backups, its agent's key (the
	 * copy is installed as its copy) and the owner's recovery key are.
	 *
	 * @param bool $for_new_copy also refuse when the node already has a copy
	 * @param string $from SiteCopy::FROM_SOURCE (alive) or FROM_BACKUPS (dead)
	 * @param bool $on_host the copy goes onto a host, whose runs take the chain's key from the recovery key
	 */
	public static function source_refusals(ManagedNode $source, bool $for_new_copy = true, string $from = SiteCopy::FROM_SOURCE,
			bool $on_host = false): array {
		$backups = $from === SiteCopy::FROM_BACKUPS;
		$why = array();
		if ($source->get('mgn_delete_time')) {
			return array('It has been removed from the dashboard.');
		}
		if (!$source->hosts_site()) {
			return array('It hosts no Joinery site.');
		}
		if (!$backups) {
			// Frozen through its agent, and copied once more: it must answer.
			$heard = strtotime((string)$source->get('mgn_agent_last_poll') . ' UTC');
			if (!$heard || time() - $heard > self::ALIVE_WITHIN_SECONDS) {
				$why[] = 'Its agent has not been heard from ' . ($heard ? 'since ' . gmdate('M j, H:i', $heard) . ' UTC' : 'at all')
					. ', and a copy of the running site freezes it through its agent. If the site is dead, copy it from its backups.';
			}
		} elseif (trim((string)$source->get('mgn_agent_public_key')) === '') {
			$why[] = 'Its agent\'s key is not on record, and the copy is installed as the copy of that agent. Connect its agent '
				. 'first.';
		}
		$why = array_merge($why, self::machine_wide_refusals($source, !$backups));
		// A running container site is frozen from outside by its host, and its
		// old container held stopped there once the copy has the site, whatever
		// the target (D4). A dead site's host is asked only at the switch-over,
		// when its old container is to be held: it may be gone with the site.
		if (!$backups && trim((string)$source->get('mgn_container_name')) !== '') {
			try {
				$source_host = JobCommandBuilder::decommission_host_node_for($source);
				$missing = array_values(array_filter(self::SOURCE_HOST_WORDS,
					function ($w) use ($source_host) { return !JobCommandBuilder::has_primitive($source_host, $w); }));
				if ($missing) {
					$why[] = 'Its host, which freezes it from outside and holds its old container stopped once the copy has the '
						. 'site: ' . AgentVocabulary::needs_newer_agent_text($source_host, $missing);
				}
			} catch (Exception $e) {
				$why[] = $e->getMessage();
			}
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
		if (!$backups && !$on_host) {
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

	/**
	 * A site on a server of its own, which the quiet state, the power-off and
	 * an address swap act on whole (D8): refused when the machine serves
	 * anything else. Its own test twin (<site>_test) is not another site.
	 * A running machine whose last report does not list its sites (none yet,
	 * or older than host_report 1.32) is asked for one first; a dead one's
	 * last report is all there is.
	 */
	public static function machine_wide_refusals(ManagedNode $source, bool $alive = true): array {
		if (trim((string)$source->get('mgn_container_name')) !== '') {
			return array();
		}
		$why = array();
		$report = json_decode((string)$source->get('mgn_last_host_report'), true);
		if ($alive && !(is_array($report) && is_array($report['sites'] ?? null))) {
			$why[] = 'Its server\'s last status report does not say what else it serves, and freezing the site, powering its server '
				. 'off or moving its address acts on the whole machine. Run a status check on it, then try again.';
		}
		$containers = (is_array($report) && is_array($report['containers'] ?? null)) ? $report['containers'] : array();
		if ($containers) {
			$why[] = 'Its server also runs ' . count($containers) . ' site container(s), and freezing the site, powering its server '
				. 'off or moving its address acts on the whole machine. Copy those sites off it first.';
		}
		$name = self::site_name($source);
		$others = array();
		foreach ((is_array($report) && is_array($report['sites'] ?? null)) ? $report['sites'] : array() as $site) {
			$site = (string)$site;
			if ($site !== '' && $site !== $name && $site !== $name . '_test') {
				$others[] = $site;
			}
		}
		$host = trim((string)$source->get('mgn_host'));
		if ($host !== '') {
			foreach (new MultiManagedNode(array('host' => $host, 'deleted' => false)) as $n) {
				if ((int)$n->key === (int)$source->key || trim((string)$n->get('mgn_container_name')) !== ''
						|| in_array(trim((string)$n->get('mgn_install_state')), array('copy', 'retired'), true)) {
					continue;
				}
				$other = self::site_name($n);
				if ($other !== '' && $other !== $name . '_test' && !in_array($other, $others, true)) {
					$others[] = $other;
				}
			}
		}
		if ($others) {
			$why[] = 'Its server also serves ' . implode(', ', $others) . ', and freezing the site, powering its server off or '
				. 'moving its address acts on the whole machine. One site per server is the only kind a copy moves whole.';
		}
		return $why;
	}

	/**
	 * The plan this management node created the source's server on, when the
	 * copy goes to the same provider (a plan id means nothing at another), or ''.
	 */
	public static function source_instance_type(ManagedNode $source, string $provider): string {
		if (trim((string)$source->get('mgn_container_name')) !== '') {
			return '';   // a container's plan is its shared server's, not its own
		}
		$p = CustomerCloudProvision::latest_for_node($source->key);
		return ($p && (string)$p->get('cvp_provider') === $provider) ? trim((string)$p->get('cvp_instance_type')) : '';
	}

	/**
	 * The root SSH keys the source's last host report lists (host_report 1.16).
	 * known=false: the machine has not reported them (an older report, or none).
	 * keys: every key with its fingerprint; carry=true marks the bare keys a copy can take,
	 * a key with options is listed but never carried.
	 *
	 * @return array{known:bool, keys:array<int,array{fingerprint:string,carry:bool,type:string,key:string,comment:string}>}
	 */
	public static function source_root_keys(ManagedNode $source): array {
		$report = json_decode((string)$source->get('mgn_last_host_report'), true);
		$keys = (is_array($report) && isset($report['root_ssh']) && is_array($report['root_ssh']))
			? ($report['root_ssh']['keys'] ?? null) : null;
		if (!is_array($keys)) {
			return array('known' => false, 'keys' => array());
		}
		$out = array();
		foreach ($keys as $k) {
			if (is_array($k) && isset($k['fingerprint'])) {
				$out[] = array('fingerprint' => (string)$k['fingerprint'], 'carry' => !empty($k['carry']),
					'type' => (string)($k['type'] ?? ''), 'key' => (string)($k['key'] ?? ''),
					'comment' => (string)($k['comment'] ?? ''));
			}
		}
		return array('known' => true, 'keys' => $out);
	}

	/**
	 * The authorized_keys text for the keys of the source the operator ticked, or ''.
	 * The tick carries the fingerprints it showed; if the source's keys have changed since the
	 * page was drawn the copy is refused rather than carrying keys nobody looked at.
	 */
	private static function carried_key_lines(ManagedNode $source, array $opts): string {
		if (empty($opts['carry_root_keys'])) {
			return '';
		}
		$report = self::source_root_keys($source);
		$lines = array();
		$now = array();
		foreach ($report['keys'] as $k) {
			if ($k['carry']) {
				$now[] = $k['fingerprint'];
				$lines[] = trim($k['type'] . ' ' . $k['key'] . ' ' . $k['comment']);
			}
		}
		sort($now);
		$shown = array_values(array_filter(array_map('trim', explode(',', (string)($opts['key_fingerprints'] ?? '')))));
		sort($shown);
		if (!$report['known'] || !$lines) {
			throw new SiteCopyException('The source has reported no root SSH keys to carry.');
		}
		if ($now !== $shown) {
			throw new SiteCopyException('The source\'s root SSH keys changed since you looked. Reload the Copy tab and check them again.');
		}
		return implode("\n", $lines);
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
	 * management node created its server and the copy's region still sells
	 * it, otherwise the smallest plan with at least the memory the source's
	 * machine reports and the disk this copy needs (a container reports its
	 * whole shared server's memory).
	 *
	 * @param callable $types returns the plans the copy's region sells, [id, memory_mb, disk_mb], smallest first
	 * @param string $provider the copy's provider
	 * @throws SiteCopyException when the source's size is not known
	 */
	public static function same_size_type(ManagedNode $source, string $from, callable $types, string $provider): string {
		$plans = $types();
		$own = self::source_instance_type($source, $provider);
		if ($own !== '' && in_array($own, array_column($plans, 'id'), true)) {
			return $own;
		}
		$memory = self::source_memory_bytes($source);
		if ($memory <= 0) {
			throw new SiteCopyException('This site\'s server has not reported its memory, so the copy\'s size is not known. '
				. 'Run a status check on it, then try again.');
		}
		$disk = self::disk_needed(self::newest_chain($source, null)) + self::OS_DISK;
		foreach ($plans as $t) {
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
	 * A copy takes the newest chain at any age ($max_hours null): from the
	 * running site its run backs the site up first, and from backups the age
	 * is the data lost, which the owner sees before typing the key. The final
	 * backup of a switch-over is checked at the default age.
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
	 * install mode copy, at the source's release, in one of our accounts
	 * ('oca:<id>') or a customer's connected one ('cca:<id>'). A copy in one
	 * of ours is our server, recorded against the admin who asked; one in a
	 * customer's account belongs to that customer.
	 *
	 * @param array $opts account, region, type, carry_root_keys (bool), key_fingerprints (comma list the operator saw);
	 *                    its encryption is decided, never chosen (copy_encryption())
	 */
	public static function start_new_server(ManagedNode $source, array $opts, $user_id, string $from = SiteCopy::FROM_SOURCE): SiteCopy {
		self::refuse_start($source, $from);
		$account = (string)($opts['account'] ?? '');
		$region = trim((string)($opts['region'] ?? ''));
		$type = trim((string)($opts['type'] ?? ''));
		if (!preg_match('/^[a-z0-9-]{2,40}$/', $region)) {
			throw new SiteCopyException('Choose the new server\'s region.');
		}

		try {
			$target = HeldCloudAccounts::resolve($account);
		} catch (DisplayableUserException $e) {
			throw new SiteCopyException($e->getMessage());
		}
		$provision = new CustomerCloudProvision(NULL);
		$provision->set('cvp_hosting_mode', $target['hosting_mode']);
		$provision->set('cvp_provider', $target['provider']);
		if ($target['oca']) {
			$provision->set('cvp_oca_operator_cloud_account_id', (int)$target['oca']->key);
			$provision->set('cvp_usr_user_id', (int)$user_id);
		} else {
			$provision->set('cvp_cca_customer_cloud_account_id', (int)$target['cca']->key);
			$provision->set('cvp_usr_user_id', $target['owner_id']);
		}
		// The plans the region sells, or null where they cannot be read here
		// (the create then has the last word). A plan it does not sell is
		// refused now: at the create, the copy would already be made.
		$provider = (string)$target['provider'];
		$provider_label = ComputeDriverRegistry::label($provider);
		$plans = null;
		try {
			$plan_driver = OperatorCloudAccount::driver_for((string)$target['token'], $provider);
			if ($plan_driver instanceof CloudInstanceTypes) {
				$plans = $plan_driver->instanceTypes($region);
			}
		} catch (CloudComputeException $e) {
			$plans = null;
		}
		if ($plans === array()) {
			throw new SiteCopyException($provider_label . ' sells no plan in ' . $region . ': choose another region.');
		}
		if ($type === '') {
			if ($plans === null) {
				throw new SiteCopyException($provider_label . ' does not list its plans here: choose the new server\'s type.');
			}
			$type = self::same_size_type($source, $from, function () use ($plans) { return $plans; }, $provider);
		}
		if (!preg_match('/^[a-z0-9-]{2,60}$/', $type)) {
			throw new SiteCopyException('Choose the new server\'s type.');
		}
		if ($plans !== null && !in_array($type, array_column($plans, 'id'), true)) {
			throw new SiteCopyException($provider_label . ' does not sell plan ' . $type . ' in ' . $region . '. Plans sold there: '
				. implode(', ', array_column($plans, 'id')) . '.');
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
		$carried = self::carried_key_lines($source, $opts);
		if ($carried !== '') {
			$provision->set('cvp_root_ssh_keys', $carried);
		}
		$born = self::copy_encryption($source, (string)$target['hosting_mode']);
		// One layer: where the provider's own encryption cannot be turned off
		// (a distributed region), a server born with ours would have two.
		// Refused before anything is made; the provision checks again.
		if ($born['ours']) {
			try {
				$region_driver = OperatorCloudAccount::driver_for((string)$target['token'], (string)$target['provider']);
				if ($region_driver instanceof CloudRegionFacts && $region_driver->regionInfo($region)['site_type'] === 'distributed') {
					throw new SiteCopyException("Region {$region} is a distributed region, where the provider's own disk encryption is "
						. 'always on, and the copy is encrypted by us: it would have two layers. Choose a core region.');
				}
			} catch (CloudComputeException $e) {
				// Not known now: the provision asks again before it creates.
			}
		}
		if ($born['ours']) {
			$provision->set('cvp_data_root_encrypted', true);
			if ($born['recipient'] !== '') {
				$provision->set('cvp_recovery_public_key', $born['recipient']);
			}
		}
		$provision->prepare();
		$provision->save();

		$copy = self::new_record($source, $user_id, $from);
		$copy->set('scp_cvp_customer_cloud_provision_id', (int)$provision->key);
		$copy->save();
		return $copy;
	}

	/**
	 * Which layer of encryption the copy's new server is born with, decided
	 * here and never ticked (specs/site_copy_final_test.md E1): one layer,
	 * never two, never none where ours can be had.
	 *
	 *   - In one of our accounts: ours, sealed to this management node's
	 *     recovery key, with the provider's own layer off.
	 *   - In its owner's account, with the site's recovery key proven (and its
	 *     public half on record): ours, sealed to that key, the provider's off.
	 *   - In its owner's account without one: the provider's default, and none
	 *     of ours.
	 *
	 * Nothing of ours while this management node does not offer it.
	 *
	 * @return array{ours: bool, recipient: string, says: string} recipient: the
	 *         base64 key it is sealed to when not this management node's own
	 */
	public static function copy_encryption(ManagedNode $source, string $hosting_mode): array {
		if (!CustomerCloudProvision::data_root_encryption_offered()) {
			return array('ours' => false, 'recipient' => '', 'says' => 'Its disks are encrypted by the provider\'s default, if it has '
				. 'one: this management node does not encrypt data roots yet.');
		}
		if ($hosting_mode === 'operator') {
			return array('ours' => true, 'recipient' => '', 'says' => 'Its data is encrypted by us, sealed to this management node\'s '
				. 'recovery key, and the provider\'s own disk encryption is off: one layer.');
		}
		$pub = trim((string)$source->get('mgn_backup_recovery_public_key'));
		if ($pub !== '') {
			return array('ours' => true, 'recipient' => $pub, 'says' => 'Its data is encrypted by us, sealed to this site\'s own '
				. 'recovery key, and the provider\'s own disk encryption is off: one layer.');
		}
		return array('ours' => false, 'recipient' => '', 'says' => 'Its disks are encrypted by the provider\'s default only. Prove '
			. 'this site\'s recovery key on its Backups page first, and a copy into its owner\'s account is encrypted by us instead.');
	}

	/** Record a copy onto a server the owner brings; the page shows them the command. */
	public static function start_own_server(ManagedNode $source, $user_id, string $from = SiteCopy::FROM_SOURCE): SiteCopy {
		self::refuse_start($source, $from);
		$copy = self::new_record($source, $user_id, $from);
		$copy->save();
		return $copy;
	}

	/**
	 * Copy the site onto a host, as a container there (specs/site_container_engine.md
	 * D9): from its backups, installed by the host's own agent at the source's
	 * release and quiet from its first process. The copy's row is made here,
	 * placed on the host with a port of its own; its install is queued at once,
	 * and the run starts once its agent has joined. A running site is frozen
	 * and copied once more at the switch; a dead one is not (D5).
	 */
	public static function start_on_host(ManagedNode $source, int $host_id, $user_id, string $from = SiteCopy::FROM_SOURCE): SiteCopy {
		self::refuse_start($source, $from, true);
		$host = new ManagedHost($host_id > 0 ? $host_id : NULL, $host_id > 0);
		if (!$host->key) {
			throw new SiteCopyException('Choose the host the copy goes onto.');
		}
		$why = self::host_refusals($source, $host, $from);
		if ($why) {
			throw new SiteCopyException('The site cannot be copied onto ' . $host->get('mgh_name') . ': ' . implode(' ', $why));
		}
		$name = self::site_name($source);
		$node = new ManagedNode(NULL);
		$node->set('mgn_name', $source->get('mgn_name') . ' (copy)');
		$node->set('mgn_slug', AgentChannelEndpoint::freeSlug($source->get('mgn_slug') . '-copy'));
		$node->set('mgn_host', (string)$host->get('mgh_host'));
		$node->set('mgn_site_url', 'https://' . self::site_domain($source));
		$node->set('mgn_web_root', '/var/www/html/' . $name . '/public_html');
		$node->set('mgn_container_name', $name);
		$node->set('mgn_mgh_managed_host_id', (int)$host->key);
		$node->set('mgn_enabled', true);
		$node->set('mgn_skip_joinery_checks', false);
		$node->set('mgn_uptime_enabled', false);
		$node->set('mgn_install_state', 'copy');
		$node->set('mgn_copy_of_node_id', (int)$source->key);
		$node->prepare();
		$node->save();
		$node->load();
		$node->set('mgn_port', JobCommandBuilder::next_container_port((int)$host->key, (int)$node->key));
		$node->save();

		$copy = self::new_record($source, $user_id, $from);
		$copy->set('scp_copy_node_id', (int)$node->key);
		$copy->set('scp_mgh_managed_host_id', (int)$host->key);
		$copy->save();
		// Its install goes now, not at the next task tick.
		self::advance($copy);
		$copy->load();
		return $copy;
	}

	/**
	 * Why the site cannot be copied onto this host: one line per reason, empty
	 * when it can. Read-only, from this management node's records and the
	 * host's last report.
	 */
	public static function host_refusals(ManagedNode $source, ManagedHost $host, string $from = SiteCopy::FROM_SOURCE): array {
		$alive = $from !== SiteCopy::FROM_BACKUPS;
		if ($host->get('mgh_delete_time')) {
			return array('That host has been removed from the dashboard.');
		}
		$host_node = $host->host_node();
		if (!$host_node) {
			return array('It has no agent of its own paired with this management node.');
		}
		$why = array();
		$missing = array_values(array_filter(self::HOST_COPY_WORDS, function ($w) use ($host_node) {
			return !JobCommandBuilder::has_primitive($host_node, $w);
		}));
		if ($missing) {
			$why[] = 'Its agent: ' . AgentVocabulary::needs_newer_agent_text($host_node, $missing);
		}
		$report = json_decode((string)$host_node->get('mgn_last_host_report'), true);
		$engine = is_array($report) ? (string)($report['site_engine'] ?? '') : '';
		if ($engine !== 'podman') {
			$why[] = $engine === ''
				? 'It has not said which engine its sites run on (its host report is older, or missing): run a status check on it.'
				: 'Its sites run on ' . $engine . '. A copy goes onto a host whose sites run on the site container engine, which keeps a copy quiet beside the others.';
		}
		if ((int)$source->get('mgn_mgh_managed_host_id') === (int)$host->key) {
			$why[] = 'It is the site\'s own host.';
		}
		$name = self::site_name($source);
		foreach (new MultiManagedNode(array('host_id' => (int)$host->key, 'deleted' => false)) as $n) {
			if (trim((string)$n->get('mgn_container_name')) === $name || self::site_name($n) === $name) {
				$why[] = "It already has a site named {$name} ({$n->get('mgn_name')}).";
				break;
			}
		}
		foreach ((is_array($report) && is_array($report['containers'] ?? null)) ? $report['containers'] : array() as $c) {
			if (is_array($c) && (string)($c['name'] ?? '') === $name) {
				$why[] = "Its last report lists a container named {$name}.";
				break;
			}
		}
		$version = trim((string)$source->get('mgn_joinery_version'));
		if ($version !== '' && !self::holds_release($version)) {
			$why[] = "This management node holds no archive of release {$version}, the site's release, which a copy onto a host "
				. 'installs. It keeps the archive of each release it upgrades to, so the copy can start once the site runs one of those.';
		} elseif ($version !== '' && !self::release_carries($version, self::HOST_COPY_RELEASE_FILE)) {
			$why[] = "The site runs release {$version}. A copy onto a host installs the site's own release, and needs one that "
				. 'carries its host\'s half of the quiet state. Update the site first.';
		}
		// A container source's own host is source_refusals()'.
		if (!$alive) {
			return $why;
		}
		$missing = array_values(array_filter(self::SWITCH_SOURCE_WORDS, function ($w) use ($source) {
			return !JobCommandBuilder::has_primitive($source, $w);
		}));
		if ($missing) {
			$why[] = 'The site, which is frozen for the final copy: ' . AgentVocabulary::needs_newer_agent_text($source, $missing);
		}
		// The final copy backs the frozen site up once more.
		$key = RecoveryKeyFleet::node_state($source);
		if (!RecoveryKeyFleet::has_own_key($key)) {
			$why[] = 'Its backups cannot run, and the final copy backs it up once more: ' . RecoveryKeyFleet::blocker_summary($key);
		}
		return $why;
	}

	/** Whether this management node holds release $version's signed manifest, and it lists $file. */
	/** Whether this management node holds the archive of that core release (published or kept, UpgradeRetention). */
	public static function holds_release(string $version): bool {
		if (self::$release_carrier) {
			return true;
		}
		return ReleaseManifestSource::read('', $version) !== null;
	}

	public static function release_carries(string $version, string $file): bool {
		if (self::$release_carrier) {
			return (bool)(self::$release_carrier)($version, $file);
		}
		$pair = ReleaseManifestSource::read('', $version);
		if ($pair === null) {
			return false;
		}
		foreach (explode("\n", (string)$pair['manifest']) as $line) {
			if (preg_match('/^[0-9a-f]{64}\s+(\S.*)$/', $line, $m) && trim($m[1]) === $file) {
				return true;
			}
		}
		return false;
	}

	/** The agent node of the host a copy onto a host is installed on. */
	public static function copy_host_node(SiteCopy $copy): ManagedNode {
		$host = new ManagedHost((int)$copy->get('scp_mgh_managed_host_id'), TRUE);
		if (!$host->key || $host->get('mgh_delete_time')) {
			throw new SiteCopyException('the host the copy is installed on has no record any more');
		}
		$node = $host->host_node();
		if (!$node) {
			throw new SiteCopyException('the host the copy is installed on has no agent paired with this management node');
		}
		return $node;
	}

	/**
	 * A copy onto a host's install: queued once on the host's own agent, then
	 * followed. True once it has finished, the copy's port and its agent's key
	 * recorded from what it printed; a failure stops the copy.
	 */
	private static function advance_install(SiteCopy $copy): bool {
		try {
			$source = self::source_row($copy);
			$node = self::copy_row($copy, true);
			$host_node = self::copy_host_node($copy);
		} catch (Exception $e) {
			self::halt($copy, 'The copy cannot be installed: ' . $e->getMessage());
			return false;
		}
		$job_id = (int)$copy->get('scp_install_job_id');
		if ($job_id <= 0) {
			try {
				$built = JobCommandBuilder::build_site_install($host_node, array(
					'name'        => self::site_name($source),
					'domain'      => self::site_domain($source),
					'port'        => (int)$node->get('mgn_port'),
					'release'     => (string)$copy->get('scp_release'),
					'copy_of'     => (int)$source->key,
					'copy_of_key' => trim((string)$source->get('mgn_agent_public_key')),
					'memory'      => self::source_memory_limit($source),
				));
				$job = ManagementJob::createFromBuild($host_node->key, 'site_install', $built,
					array('site_copy_id' => (int)$copy->key), $copy->get('scp_created_by'));
			} catch (Exception $e) {
				self::halt($copy, 'The copy\'s install could not be queued on its host: ' . $e->getMessage());
				return false;
			}
			$copy->set('scp_install_job_id', (int)$job->key);
			$copy->save();
			return false;
		}
		try {
			$job = new ManagementJob($job_id, TRUE);
		} catch (Exception $e) {
			self::halt($copy, 'The copy\'s install job is gone.');
			return false;
		}
		if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
			$queued = strtotime((string)$job->get('mjb_create_time') . ' UTC');
			$limit = self::STEP_WAIT_MINUTES['site_install'];
			if ($queued && time() - $queued > $limit * 60) {
				self::halt($copy, "The copy's install on its host has not finished in {$limit} minutes (status "
					. $job->get('mjb_status') . '; is the host\'s agent claiming jobs?).');
			}
			return false;
		}
		JobResultProcessor::process_if_due($job);
		$job->load();
		$output = self::job_output($job);
		if ((string)$job->get('mjb_status') !== 'completed') {
			$err = trim((string)$job->get('mjb_error_message'));
			$tail = trim(implode("\n", array_slice(preg_split('/\R/', $output), -6)));
			self::halt($copy, 'The copy\'s install on its host failed' . ($err !== '' ? ': ' . $err : '') . ($tail !== '' ? ' (' . $tail . ')' : ''));
			return false;
		}
		if ($copy->get('scp_expected_site_key') === null || (string)$copy->get('scp_expected_site_key') === '') {
			// install.sh moves off a port that turned out to be busy, and says
			// which one it took.
			if (preg_match_all('/^CONTAINER_PORT=([0-9]{4,5})\s*$/m', $output, $m)) {
				$port = (int)end($m[1]);
				if ($port !== (int)$node->get('mgn_port')) {
					$node->set('mgn_port', $port);
					$node->save();
				}
			}
			// Kept after the join, as the record of the key the install showed:
			// the copy is let in only while it waits with no key, so the record
			// can never let a second agent in (reviewer2 WP5 R4).
			$key = JoinAutoApproval::site_key_from_output($output);
			if ($key === null) {
				self::halt($copy, 'The copy\'s install finished without showing its agent\'s key, so its join cannot be told from a stranger\'s. '
					. 'Discard it and copy again.');
				return false;
			}
			$copy->set('scp_expected_site_key', $key);
			$copy->save();
		}
		return true;
	}

	/** What a script word's job printed, out of the agent's envelope. */
	private static function job_output(ManagementJob $job): string {
		return JobResultProcessor::script_text((string)($job->get('mjb_output') ?: ''));
	}

	/**
	 * The memory limit a copy onto a host is given: a container source's own,
	 * as its host's last report lists it, or none (a source with no limit, or
	 * one on a server of its own).
	 */
	private static function source_memory_limit(ManagedNode $source): string {
		$name = trim((string)$source->get('mgn_container_name'));
		if ($name === '') {
			return '';
		}
		try {
			$host = JobCommandBuilder::decommission_host_node_for($source);
		} catch (Exception $e) {
			return '';
		}
		$report = json_decode((string)$host->get('mgn_last_host_report'), true);
		foreach ((is_array($report) && is_array($report['containers'] ?? null)) ? $report['containers'] : array() as $c) {
			if (is_array($c) && (string)($c['name'] ?? '') === $name) {
				$limit = $c['memory']['limit_bytes'] ?? null;
				return (is_int($limit) && $limit > 0) ? max(256, (int)ceil($limit / 1048576)) . 'm' : '';
			}
		}
		return '';
	}

	/**
	 * The addresses a copy onto a host's join comes from: its host's own, as
	 * this management node knows them. The copy's traffic leaves through it.
	 */
	public static function host_addresses(SiteCopy $copy): array {
		$out = array();
		try {
			$host_node = self::copy_host_node($copy);
			$host = new ManagedHost((int)$copy->get('scp_mgh_managed_host_id'), TRUE);
		} catch (Exception $e) {
			return $out;
		}
		$a = ProxiedOriginMove::machine_addresses($host_node);
		foreach (array_merge($a[4], $a[6], array((string)$host->get('mgh_host'))) as $ip) {
			if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
				$out[] = $ip;
			}
		}
		return $out;
	}

	private static function from_host(SiteCopy $copy, string $ip): bool {
		$packed = @inet_pton(trim($ip));
		if ($packed === false) {
			return false;
		}
		foreach (self::host_addresses($copy) as $a) {
			if (@inet_pton($a) === $packed) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A copy onto a host's join, approved without a person: pending, under the
	 * site's name, from its host's address, and carrying the key its install
	 * printed. Anything less is left for the owner to approve by eye.
	 */
	private static function auto_approve_join(SiteCopy $copy): void {
		if (!JoinAutoApproval::enabled()) {
			return;
		}
		$expected = strtolower(trim((string)$copy->get('scp_expected_site_key')));
		if (!preg_match('/^[0-9a-f]{16}$/', $expected)) {
			return;
		}
		foreach (self::candidate_joins($copy) as $request) {
			if (strtolower(trim((string)$request->get('ajr_fingerprint'))) !== $expected
					|| !self::from_host($copy, (string)$request->get('ajr_source_ip'))) {
				continue;
			}
			try {
				self::approve_join($copy, $request);
			} catch (Exception $e) {
				error_log('SiteCopyRunner: copy #' . (int)$copy->key . '\'s join was not approved: ' . $e->getMessage());
			}
			return;
		}
	}

	private static function refuse_start(ManagedNode $source, string $from, bool $on_host = false): void {
		if (!in_array($from, array(SiteCopy::FROM_SOURCE, SiteCopy::FROM_BACKUPS), true)) {
			throw new SiteCopyException('Choose whether the copy comes from the running site or from its backups.');
		}
		self::confirm_source_release($source);
		$why = self::source_refusals($source, true, $from, $on_host);
		if ($why) {
			throw new SiteCopyException('This site cannot be copied: ' . implode(' ', $why));
		}
		self::newest_chain($source, null);
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
		// The release and the upgrades after come from whoever serves this
		// plane's releases: itself, or its own source when it serves none.
		$stream = ReleaseStream::source_for($plane, $release);
		$key = trim((string)$source->get('mgn_agent_public_key'));
		$lines = array(
			'mkdir -p /root/joinery-copy && cd /root/joinery-copy',
			'curl -sL ' . escapeshellarg($stream . '/utils/latest_release?version=' . $release) . ' | tar xz',
			'cd maintenance_scripts/install_tools',
			'POSTGRES_PASSWORD=$(openssl rand -base64 18 | tr -d \'/+=\' | head -c 24)',
			'echo "$POSTGRES_PASSWORD" > /root/.joinery_postgres_password && chmod 600 /root/.joinery_postgres_password',
			'POSTGRES_PASSWORD="$POSTGRES_PASSWORD" ./install.sh -y -q server',
			'./install.sh -y -q site --bare-metal ' . escapeshellarg(self::site_name($source))
				. ' --password-file=/root/.joinery_postgres_password ' . escapeshellarg(self::site_domain($source))
				. ' --enable-agent --management-node=' . escapeshellarg($plane) . ' --upgrade-server=' . escapeshellarg($stream)
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
		if ($node && $copy->on_host()) {
			if (trim((string)$node->get('mgn_agent_public_key')) !== '') {
				throw new SiteCopyException('The copy already has a connected agent.');
			}
			// A copy on a host reaches this management node through its host.
			if (!self::from_host($copy, (string)$request->get('ajr_source_ip'))) {
				throw new SiteCopyException('That join request does not come from the host the copy is installed on.');
			}
		} elseif ($node) {
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
			// Asked before anything moves: a site that took a release since the
			// copy was made is refused here, with the copy left as it was.
			$source = self::source_row($copy);
			self::confirm_source_release($source);
			$stale = self::stale_release($copy, $source);
			if ($stale !== null) {
				throw new SiteCopyException($stale);
			}
			self::begin_run($copy);
			// Its first step goes now, not at the next task tick (B52).
			self::advance_locked($copy);
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
			// A copy onto a host is removed from it by the host's own agent,
			// which refuses anything it does not record as a dormant copy.
			// Its install is let finish first, as a new server's is.
			if ($copy->on_host()) {
				self::discard_from_host($copy);
			}
			// The step it was on is withdrawn too. A source waiting for its
			// owner to approve the export asks whether the job is still wanted,
			// and takes the approval request off its page when it is not.
			self::cancel_open_steps($copy->steps());
			self::clear_staged_certificate($copy);
			$node = self::copy_row($copy, false);
			if ($node && !$node->get('mgn_delete_time')) {
				DataRootEscrowSync::let_go($node);
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
	 * A container source's certificate staged inside it for an export that
	 * never reached its clear (a declined or failed export halts the run there)
	 * is taken back out when the copy is discarded: the source runs on, and the
	 * key is not left in its container's layer. Best effort: a later stage
	 * clears first, and the host keeps the record of what it staged.
	 */
	private static function clear_staged_certificate(SiteCopy $copy): void {
		$staged = false;
		foreach ($copy->steps() as $s) {
			if (($s['op'] ?? '') === 'site_certificate_stage') {
				$staged = ($s['arg'] ?? '') === 'stage' ? ($staged || ($s['verdict'] ?? '') === 'passed')
					: ($staged && ($s['verdict'] ?? '') !== 'passed');
			}
		}
		if (!$staged) {
			return;
		}
		try {
			$source = self::source_row($copy);
			$host = JobCommandBuilder::decommission_host_node_for($source);
			$built = JobCommandBuilder::build_site_certificate_stage($host, trim((string)$source->get('mgn_container_name')), 'clear');
			ManagementJob::createFromBuild($host->key, 'site_certificate_stage', $built,
				array('site_copy_id' => (int)$copy->key), $copy->get('scp_created_by'));
		} catch (Exception $e) {
			error_log('SiteCopyRunner: copy ' . (int)$copy->key . ': the staged certificate was not cleared: ' . $e->getMessage());
		}
	}

	/**
	 * Queue a copy onto a host's removal from it. One that was never installed
	 * has nothing to remove; one whose install is still running is refused.
	 */
	private static function discard_from_host(SiteCopy $copy): void {
		$job_id = (int)$copy->get('scp_install_job_id');
		if ($job_id <= 0) {
			return;
		}
		try {
			$job = new ManagementJob($job_id, TRUE);
		} catch (Exception $e) {
			$job = null;
		}
		if ($job && !in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
			throw new SiteCopyException('The copy is being installed on its host. Discard it once its install finishes or fails.');
		}
		try {
			$host_node = self::copy_host_node($copy);
			$source = self::source_row($copy);
			$built = JobCommandBuilder::build_discard_copy($host_node, self::site_name($source));
			$removal = ManagementJob::createFromBuild($host_node->key, 'discard_copy', $built,
				array('site_copy_id' => (int)$copy->key), $copy->get('scp_created_by'));
		} catch (Exception $e) {
			throw new SiteCopyException('The copy could not be removed from its host, so nothing was discarded: ' . $e->getMessage());
		}
		$switch = $copy->switch_record();
		$switch['discard_job_id'] = (int)$removal->key;
		$copy->set_switch_record($switch);
	}

	/**
	 * What the owner has to delete at the provider when a copy ends: the
	 * provider, instance and address, from the provision when this management
	 * node created the server, else the address the copy's row recorded. A
	 * discarded copy names its own server; a kept switch-over, the old one.
	 */
	public static function server_to_delete(SiteCopy $copy): string {
		// A kept switch-over's old server is the one Keep named, while its row
		// was still there. The row is removed afterwards, and the copy's own
		// provision is then the server the site runs on: never that (B22).
		if ($copy->status() === SiteCopy::STATUS_FINISHED) {
			return (string)($copy->switch_record()['server_to_delete'] ?? '');
		}
		// The provision follows its machine through a swap, so the copy row's
		// is the server on that row; one never joined has only the copy's own.
		$node = self::copy_row($copy, false);
		// A container is removed from its shared host (old_container), never
		// deleted at a provider.
		if ($node && trim((string)$node->get('mgn_container_name')) !== '') {
			return '';
		}
		// Only a row never joined falls back to the copy's own provision. A row
		// with a machine names that machine's provision or its address: after a
		// switch-over the copy's provision belongs to the live site (B58).
		// Removing a row (Discard, a way back after the take-over) removes its
		// provision with it, while the server stays: a removed one names it too.
		$provision = $node ? self::server_provision((int)$node->key) : self::provision($copy);
		if ($provision && trim((string)$provision->get('cvp_instance_id')) !== '') {
			return ucfirst((string)$provision->get('cvp_provider')) . ' instance ' . $provision->get('cvp_instance_id')
				. ' (' . $provision->get('cvp_instance_ip') . ')';
		}
		return $node ? 'the server at ' . $node->get('mgn_host') : '';
	}

	/** A row's newest provision, removed or not. */
	private static function server_provision(int $node_id): ?CustomerCloudProvision {
		foreach (new MultiCustomerCloudProvision(array('node_id' => $node_id), array('cvp_customer_cloud_provision_id' => 'DESC')) as $p) {
			return $p;
		}
		return null;
	}

	/**
	 * The old machine of a kept switch-over when it is a container: removed
	 * from its host with Permanently Delete Site on its own row, where the
	 * host's proof that the domain left stands in for the site's approval
	 * (site_copy.md WP14). Null for anything else.
	 */
	public static function old_container(SiteCopy $copy): ?ManagedNode {
		$node = self::copy_row($copy, false);
		if (!$node || trim((string)$node->get('mgn_container_name')) === ''
				|| !JobCommandBuilder::decommission_is_moved($node)) {
			return null;
		}
		return $node;
	}

	/**
	 * What kept switch-overs of this site still leave on an old container's
	 * host (site_copy.md B5): each old container not yet removed, and each one
	 * removed whose host still holds its certificate. Newest first, one entry
	 * per old container: array('node' => the old row, 'removed' => bool,
	 * 'certificates' => names). Nothing is listed once its cleanup is done,
	 * however long ago the switch-over was.
	 */
	public static function cleanup_left(int $source_id): array {
		$out = array();
		foreach (new MultiSiteCopy(array('source_node_id' => $source_id, 'status' => SiteCopy::STATUS_FINISHED, 'deleted' => false),
				array('scp_site_copy_id' => 'DESC')) as $copy) {
			$old = self::old_container($copy);
			if (!$old) {
				continue;
			}
			$removed = (string)$old->get('mgn_moved_check_state') === 'absent';
			$certificates = $removed ? MovedSiteCheck::leftover_certificates($old) : array();
			if ($removed && !$certificates) {
				continue;
			}
			$out[] = array('node' => $old, 'removed' => $removed, 'certificates' => $certificates);
		}
		return $out;
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
		foreach (new MultiSiteCopy(array('status' => SiteCopy::STATUS_SWITCHED, 'deleted' => false)) as $copy) {
			if (($copy->switch_record()['watch']['state'] ?? '') === 'watching') {
				self::advance_watch($copy);
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
		// A copy onto a host: its host installs it, then its agent's join is
		// approved by the key its install printed.
		if ($copy->on_host()) {
			if (!self::advance_install($copy)) {
				return;
			}
			$node = self::copy_row($copy, false);
			if ($node && trim((string)$node->get('mgn_agent_public_key')) === '') {
				self::auto_approve_join($copy);
			}
		}
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
		if (AgentVocabulary::missing_words($node, $copy->runs_from_backups() ? self::BACKUPS_COPY_WORDS : self::COPY_WORDS)) {
			return; // joined; its first poll reports what it can do
		}
		self::begin_run($copy);
	}

	/**
	 * Why this copy can no longer be refreshed or switched to, or null: it runs
	 * the release it was installed at, and its site has since taken a newer one
	 * (a copy must run its source's exact release). The site's release on record
	 * is what is compared; the Refresh and the switch-over ask the site itself
	 * first.
	 */
	public static function stale_release(SiteCopy $copy, ?ManagedNode $source = null): ?string {
		$release = (string)$copy->get('scp_release');
		if ($release === '') {
			return null;
		}
		try {
			$source = $source ?: self::source_row($copy);
		} catch (Exception $e) {
			return null;
		}
		$version = (string)$source->get('mgn_joinery_version');
		if ($version === '' || $version === $release) {
			return null;
		}
		return "The site now runs release {$version}, and this copy was made at {$release}. A copy must run its "
			. 'site\'s exact release, so it can no longer be refreshed or switched to: discard it and copy again.';
	}

	/**
	 * The copy installs its source's exact release, so the release on record
	 * is asked of the site itself first: one upgraded outside this node is on
	 * record at its old release until a status check (B48). A site that does
	 * not answer (a copy from backups of a site that is down) keeps the record.
	 */
	private static function confirm_source_release(ManagedNode $source): void {
		$said = $source->probe_joinery_version();
		if ($said !== null && preg_match('/^\d+\.\d+\.\d+$/', $said) && $said !== (string)$source->get('mgn_joinery_version')) {
			$source->set('mgn_joinery_version', $said);
			$source->save();
		}
	}

	/** Lay out a run's steps, after checking both ends still hold. */
	private static function begin_run(SiteCopy $copy): void {
		try {
			$source = self::source_row($copy);
			self::confirm_source_release($source);
			$why = self::source_refusals($source, false, (string)$copy->get('scp_from'), $copy->on_host());
			if ($why) {
				throw new SiteCopyException('The source cannot be copied now: ' . implode(' ', $why));
			}
			$stale = self::stale_release($copy, $source);
			if ($stale !== null) {
				throw new SiteCopyException($stale);
			}
			$node = self::copy_row($copy, true);
			if (!$node || trim((string)$node->get('mgn_install_state')) !== 'copy') {
				throw new SiteCopyException('The copy\'s node record is gone or is no longer a dormant copy.');
			}
			$chain = self::newest_chain($source, null);
		} catch (Exception $e) {
			self::halt($copy, $e->getMessage());
			return;
		}
		$steps = array();
		foreach ($copy->runs_from_backups() ? self::BACKUPS_RUN_STEPS : self::run_steps($source) as $s) {
			$steps[] = array('op' => $s['op'], 'on' => $s['on'], 'arg' => $s['arg'] ?? '', 'job_id' => null,
				'verdict' => 'pending', 'reason' => '');
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
		try {
			if ($step['on'] === 'host') {
				$target = self::hold_host($copy);
			} elseif ($step['on'] === 'source_host' || $step['on'] === 'copy_host') {
				$target = self::switch_host($copy, $step['on']);
			} elseif ($step['on'] === 'site_host') {
				// A container source's host as it stands, before any switch-over.
				$target = JobCommandBuilder::decommission_host_node_for($source);
			} else {
				$target = $step['on'] === 'source' ? $source : $node;
			}
			// A container's certificate is issued on its host: a copy onto a
			// host is a container from the moment it takes the site.
			if ($step['op'] === 'provision_certificate') {
				$target = JobCommandBuilder::certificate_issuer_for($target);
			}
		} catch (Exception $e) {
			self::fail_step($copy, $steps, $pos, $e->getMessage());
			return false;
		}

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
				// A copy from backups stages the run whose key its owner opened:
				// the source's own schedule goes on meanwhile, and a run it adds is
				// in a newer manifest the copy holds no vouch for (B20). The
				// switch's final copy brings those, vouched by the source then.
				$stage = $chain;
				if (!self::step_job($steps, 'copy_take_vouch')) {
					$seq = self::key_step_seq($steps, $source, $chain['chain_id']);
					if ($seq !== null) {
						$stage['vouched_seq'] = $seq;
					}
				}
				return self::$stage_builder ? (self::$stage_builder)($node, $stage) : JobCommandBuilder::build_copy_stage($node, $stage);
			case 'copy_restore':
				return JobCommandBuilder::build_copy_restore($node, array('chain_id' => $chain['chain_id']));
			case 'site_census':
				return JobCommandBuilder::build_site_census($step['on'] === 'source' ? $source : $node);
			case 'site_quiet':
				if (($step['arg'] ?? '') === 'forward') {
					return JobCommandBuilder::build_site_quiet_forward($step['on'] === 'source' ? $source : $node,
						(string)($copy->switch_record()['forward_to'] ?? ''));
				}
				return JobCommandBuilder::build_site_quiet($step['on'] === 'source' ? $source : $node, (string)($step['arg'] ?? ''));
			case 'trust_forwarder':
				$on = (string)$step['on'];
				$machine = $on === 'copy_host' ? self::switch_host($copy, 'copy_host') : ($on === 'source' ? $source : $node);
				return JobCommandBuilder::build_trust_forwarder($machine, (string)$step['arg'],
					(string)($copy->switch_record()['forward_from'] ?? ''), self::FORWARDER_TRUST_HOURS);
			case 'backup_run':
				// A run's first backup takes the source's own chain policy; the
				// final one of a switch-over must extend the chain the copy holds.
				if (($step['arg'] ?? '') === 'run') {
					return JobCommandBuilder::build_backup_run($source, array('type' => 'project', 'mode' => 'chain'));
				}
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
			case 'hold_container':
				if (($step['on'] ?? '') === 'copy_host') {
					// A copy onto a host gone back from: its own container, held stopped there.
					return JobCommandBuilder::build_hold_container(self::switch_host($copy, 'copy_host'),
						(string)($copy->switch_record()['hosts']['copy']['name'] ?? self::site_name($source)), (string)($step['arg'] ?? ''));
				}
				return JobCommandBuilder::build_hold_container(self::hold_host($copy),
					(string)($copy->switch_record()['hold']['name'] ?? ''), (string)($step['arg'] ?? ''));
			case 'site_certificate_stage':
				return JobCommandBuilder::build_site_certificate_stage(JobCommandBuilder::decommission_host_node_for($source),
					trim((string)$source->get('mgn_container_name')), (string)($step['arg'] ?? ''),
					($step['arg'] ?? '') === 'stage' ? self::site_domain($source) : '');
			case 'site_quiet_host':
				$on = (string)$step['on'];
				$entry = $copy->switch_record()['hosts'][$on === 'source_host' ? 'source' : 'copy'] ?? array();
				$name = (string)($entry['name'] ?? self::site_name($source));
				if (($step['arg'] ?? '') === 'forward') {
					return JobCommandBuilder::build_site_quiet_host_forward(self::switch_host($copy, $on), $name,
						(string)($copy->switch_record()['forward_to'] ?? ''));
				}
				return ($step['arg'] ?? '') === 'on'
					? JobCommandBuilder::build_site_quiet_host(self::switch_host($copy, $on), $name, 'on', 'switchover')
					: JobCommandBuilder::build_site_quiet_host(self::switch_host($copy, $on), $name, 'off');
		}
		throw new SiteCopyException("unknown step {$op}");
	}

	/**
	 * What the copy shows its owner, and opens with what they give back, to
	 * take a chain's key from backups: the chain's manifest hash, its newest
	 * run, and the chain's data key as sealed to the recovery key (the
	 * recovery recipient of the manifest's envelope). Ciphertext and facts
	 * only: what opens it is typed on the copy's own page and never comes
	 * here. With them, a link to the manifest: the copy reads it at its
	 * provider and holds all of the above to it, and shows its owner when the
	 * provider stored it, so none of it rests on this plane's word
	 * (specs/storage_targets.md F7).
	 */
	public static function key_request(ManagedNode $source, string $chain_id): array {
		$read = self::$manifest_reader ? (self::$manifest_reader)($source, $chain_id) : self::read_manifest($source, $chain_id);
		$body = (string)($read['body'] ?? '');
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
			'manifest_url'         => (string)($read['url'] ?? ''),
		);
	}

	/**
	 * The chain's manifest, from whichever of the source's storage spaces holds
	 * the chain, with a link to it signed for as long as the copy_take_key step
	 * may wait (the copy reads it as the job starts).
	 *
	 * @return array{body: string, url: string}
	 */
	private static function read_manifest(ManagedNode $source, string $chain_id): array {
		$status = 0;
		foreach (StorageSpace::of_owner(StorageSpace::OWNER_NODE, (int)$source->key) as $space) {
			try {
				list($target, $creds, $bucket) = $space->reach();
			} catch (StorageSpaceException $e) {
				throw new SiteCopyException('The source\'s backup storage cannot be read: ' . $e->getMessage());
			}
			// The chain's newest manifest: a version-3 chain writes one per run.
			$dir = BackupChainListHelper::chain_path($space, BackupProfile::MANAGER, $chain_id) . '/';
			$names = array();
			try {
				foreach (S3Signer::list($creds, $bucket, $dir) as $o) {
					$name = substr((string)($o['key'] ?? ''), strlen($dir));
					if ($name !== '' && strpos($name, '/') === false) { $names[] = $name; }
				}
			} catch (Exception $e) {
				throw new SiteCopyException('The source\'s backup storage cannot be listed: ' . $e->getMessage());
			}
			$newest = ShelfObject::preferredManifestName((int)$space->key, $dir, $names);
			if ($newest === '') {
				$status = 404;
				continue;
			}
			$got = S3Signer::get($creds, $bucket, '/' . $dir . $newest);
			$status = (int)($got['status'] ?? 0);
			if ($status === 200) {
				// The manifest is checked against the hash the broker recorded when
				// the source wrote it, not taken on the bucket's word: nothing a
				// node writes is written twice, so a manifest whose bytes differ
				// was put there by something else (specs/storage_targets.md §6).
				$recorded = ShelfObject::forKey((int)$space->key, $dir . $newest);
				$recorded_sha = $recorded ? strtolower(trim((string)$recorded->get('svo_sha256'))) : '';
				if ($recorded_sha !== '' && !hash_equals($recorded_sha, hash('sha256', (string)$got['body']))) {
					throw new SiteCopyException("chain {$chain_id}'s manifest in backup storage is not the one its server wrote: "
						. 'its hash differs from the one recorded when it was written. Nothing is copied from it.');
				}
				// As long as the step may wait, so a claim late in the wait or
				// a claim taken again still reads a live link.
				$expires = (self::STEP_WAIT_MINUTES['copy_take_key'] ?? 60) * 60 + 600;
				return array('body' => (string)$got['body'], 'url' => S3Signer::presign_get($creds, $bucket, $dir . $newest, $expires));
			}
		}
		if ($status === 0) {
			throw new SiteCopyException('This management node keeps no backup storage for the source.');
		}
		throw new SiteCopyException("chain {$chain_id}'s manifest could not be fetched from backup storage (HTTP " . $status . ')');
	}

	/**
	 * The run whose key the owner opened on the copy's page: the run of the
	 * manifest the key step read that has its newest time. Null when the steps
	 * took no key (a copy of a running source is vouched for by its export).
	 */
	private static function key_step_seq(array $steps, ManagedNode $source, string $chain_id): ?int {
		$job = self::step_job($steps, 'copy_take_key');
		if (!$job) {
			return null;
		}
		$commands = json_decode((string)$job->get('mjb_commands'), true);
		$run_time = (string)($commands['params']['run_time'] ?? '');
		if ($run_time === '') {
			return null;
		}
		$read = self::$manifest_reader ? (self::$manifest_reader)($source, $chain_id) : self::read_manifest($source, $chain_id);
		$manifest = json_decode((string)($read['body'] ?? ''), true);
		foreach ((array)($manifest['runs'] ?? array()) as $run) {
			if ((string)($run['time'] ?? '') === $run_time && isset($run['seq'])) {
				return (int)$run['seq'];
			}
		}
		throw new SiteCopyException("chain {$chain_id} no longer lists the run of {$run_time} whose key the copy took");
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
				$first = ($step['arg'] ?? '') === 'run';
				if (!is_array($r) || !in_array($r['backup_status'] ?? '', array('success', 'warning'), true)) {
					return ($first ? 'the backup of the site' : 'the final backup') . ' did not succeed'
						. (is_array($r) && !empty($r['message']) ? ': ' . $r['message'] : '');
				}
				if ($first) {
					// The copy is of the chain this backup is in: an increment of
					// the newest chain, or a new chain where the policy began one.
					try {
						$chain = self::newest_chain($source, null);
					} catch (Exception $e) {
						return $e->getMessage();
					}
					$copy->set('scp_chain_id', $chain['chain_id']);
					$copy->set('scp_chain_time', $chain['time']);
					return null;
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
			case 'hold_container':
				$r = json_decode((string)$job->get('mjb_result'), true);
				if (is_array($r) && !empty($r['done']) && ($r['action'] ?? '') === ($step['arg'] ?? '')) {
					// Whether the old container is held, for the way back.
					if (($step['on'] ?? '') === 'host') {
						$switch = $copy->switch_record();
						$switch['held'] = ($step['arg'] ?? '') === 'stop';
						$copy->set_switch_record($switch);
					}
					return null;
				}
				return 'the old container was not ' . (($step['arg'] ?? '') === 'start' ? 'started' : 'stopped')
					. (is_array($r) && ($r['state'] ?? '') !== '' ? ' (it is ' . $r['state'] . ')' : '');
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
		// A container reports its share of a shared server (its limit, or the
		// whole server where it has none), so a container site may be copied
		// onto a smaller server.
		$s_mem = (is_array($s) && trim((string)$source->get('mgn_container_name')) === '') ? (int)($s['memory']['total_bytes'] ?? 0) : 0;
		if ($s_mem > 0 && $t_mem > 0 && $t_mem < $s_mem * 0.9) {
			return 'the copy\'s server has ' . BackupRunner::human($t_mem) . ' of memory and the source has '
				. BackupRunner::human($s_mem) . '; a copy needs at least the source\'s';
		}
		try {
			$chain = self::newest_chain($source, null);
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
		if ($copy->status() === SiteCopy::STATUS_SETTLING) {
			$switch = $copy->switch_record();
			$switch['watch']['let_go_time'] = gmdate('Y-m-d H:i:s');
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
		// A run from backups is judged alone; a final copy is of the frozen
		// site, whatever the run before it was, and the two censuses must match.
		if ($copy->runs_from_backups() && !($final && $copy->source_alive())) {
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
	 * when it can), as SiteCopyMethods said before the copy was made, with
	 * the two servers on record for the swap. The provider and the DNS host
	 * are asked on the press.
	 */
	public static function switch_methods(SiteCopy $copy): array {
		try {
			$source = self::source_row($copy);
		} catch (Exception $e) {
			return array();
		}
		$out = SiteCopyMethods::for_target($source, SiteCopyMethods::target_of($copy));
		// The two servers exist: the swap is judged from their own records.
		$node = self::copy_row($copy, true);
		if (!$copy->on_host()) {
			$out[self::METHOD_IP_SWAP] = $node ? IpSwapMove::record_refusals($source, $node) : array('The copy has no server.');
		}
		return $out;
	}

	/** The words a switch-over asks of each side. */
	private static function switch_words(SiteCopy $copy): array {
		// A living source is frozen for a final copy whatever the target; a
		// dead one is asked nothing, and the copy takes the site's node id as
		// any copy does.
		return $copy->source_alive()
			? array('source' => self::SWITCH_SOURCE_WORDS, 'copy' => self::SWITCH_COPY_WORDS)
			: array('source' => array(), 'copy' => array('take_node_id', 'site_quiet', 'provision_certificate'));
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
		$why = self::source_refusals($source, false, $from, $copy->on_host());
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
		$why = array_merge($why, self::host_word_refusals($copy, $source));
		$stale = self::stale_release($copy, $source);
		if ($stale !== null) {
			$why[] = $stale;
		}
		if (!preg_match('#^/\.joinery-look/[0-9a-f]{32}$#', (string)$copy->get('scp_look_path'))) {
			$why[] = 'The copy reported no look path. The switch-over asks for it at the site\'s name, because only the '
				. 'copy answers it, to prove the address reaches the copy. Copy again.';
		}
		if (!$why) {
			try {
				$chain = self::newest_chain($source, null);
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
	 * What the hosts a switch-over acts through cannot yet do: the copy's host
	 * (installs, lifts its quiet, issues its certificate) for a copy onto a
	 * host, and a container source's own host, which freezes a running site
	 * from outside (site_quiet_host) and holds its old container stopped once
	 * the copy has the site (hold_container), whatever the target (D4).
	 */
	private static function host_word_refusals(SiteCopy $copy, ManagedNode $source): array {
		$why = array();
		try {
			if ($copy->on_host()) {
				$host_node = self::copy_host_node($copy);
				$missing = array_values(array_filter(self::HOST_COPY_WORDS, function ($w) use ($host_node) {
					return !JobCommandBuilder::has_primitive($host_node, $w);
				}));
				if ($missing) {
					$why[] = 'The copy\'s host: ' . AgentVocabulary::needs_newer_agent_text($host_node, $missing);
				}
			}
			$hold = self::old_container_hold($source);
			if ($hold) {
				$source_host = new ManagedNode($hold['host_node_id'], TRUE);
				$words = $copy->source_alive() ? self::SOURCE_HOST_WORDS : array('hold_container');
				$missing = array_values(array_filter($words, function ($w) use ($source_host) {
					return !JobCommandBuilder::has_primitive($source_host, $w);
				}));
				if ($missing) {
					$why[] = 'The old container\'s host, which ' . ($copy->source_alive() ? 'freezes the site from outside and ' : '')
						. 'stops it once the copy has taken over: ' . AgentVocabulary::needs_newer_agent_text($source_host, $missing);
				}
			}
		} catch (Exception $e) {
			$why[] = $e->getMessage();
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
			// The site's release as it answers now: one upgraded outside this
			// node is on record at its old release until a status check.
			try {
				self::confirm_source_release(self::source_row($copy));
			} catch (Exception $e) {
				// switch_refusals() names a source that is gone.
			}
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
				} elseif ($method === self::METHOD_DNS) {
					if (!$driver) {
						throw new SiteCopyException('Enter the DNS token.');
					}
					$plan = DnsRecordMove::plan($driver, $domain, $source, $node);
					// A record whose TTL an earlier attempt lowered and did not put
					// back keeps the TTL it had before that attempt.
					$plan['records'] = DnsRecordMove::keep_earlier_ttls($plan['records'], (array)($copy->switch_record()['records'] ?? array()));
					$record['provider'] = $driver::getKey();
					$record['zone'] = $plan['zone'];
					$record['records'] = $plan['records'];
				} else {
					$addresses = ProxiedOriginMove::machine_addresses($node);
					$record['to'] = array_merge($addresses[4], $addresses[6]);
					$record['records'] = DnsRecordMove::manual_plan($domain, $source, $node)['records'];
				}
			} catch (SiteCopyException $e) {
				throw $e;
			} catch (Exception $e) {
				throw new SiteCopyException('Nothing was ' . ($copy->from_backups() ? 'changed' : 'frozen') . ': ' . $e->getMessage());
			}
			$record['address_facts'] = SiteCopyAddressFacts::for_switch($source, $domain, $method, $node, $copy->on_host());
			// A container source's old container, and the hosts a freeze or a
			// copy onto a host act through, are recorded now, before the rows
			// swap: after, the site's row is the copy's.
			try {
				$hold = self::old_container_hold($source);
			} catch (Exception $e) {
				throw new SiteCopyException('Nothing was ' . ($copy->from_backups() ? 'changed' : 'frozen') . ': ' . $e->getMessage());
			}
			if ($hold) {
				$record['hold'] = $hold;
			}
			if (in_array($method, array(self::METHOD_DNS, self::METHOD_MANUAL), true)) {
				$record = array_merge($record, self::forward_plan($source, $node, $hold));
				// A machine whose agent cannot forward, or two machines with no
				// address family in common: the move goes ahead without, and
				// visitors whose resolvers still name the old server get its
				// maintenance page until their caches let go.
				$why = $copy->source_alive() ? self::forward_refusals($copy, $source, $node) : array();
				if ($copy->source_alive() && !$why && ($record['forward_to'] === '' || $record['forward_from'] === '')) {
					$why[] = 'The old server and the copy have no public address of the same family (IPv4 or IPv6) on record, so '
						. 'the old one cannot forward to the copy.';
				}
				if ($why) {
					$record['forward_to'] = '';
					$record['forward_from'] = '';
					$record['no_forward'] = implode(' ', $why);
				}
				// The old server is powered off once the move settles, when this
				// management node created it.
				$sp = IpSwapMove::provision_of($source);
				if ($copy->source_alive() && $sp && ComputeDriverRegistry::known((string)$sp->get('cvp_provider')) && !$sp->is_transferred()) {
					$record['power'] = array('source' => array('provision_id' => (int)$sp->key,
						'instance_id' => (string)$sp->get('cvp_instance_id')));
				}
			}
			if ($copy->source_alive()) {
				// A living site is frozen, from outside by its own host too when
				// it is a container, and the final copy brings the copy to it.
				$record['hosts'] = array('source' => $hold);
				if ($copy->on_host()) {
					try {
						$record['hosts']['copy'] = array('host_node_id' => (int)self::copy_host_node($copy)->key,
							'name' => self::site_name($source));
					} catch (Exception $e) {
						throw new SiteCopyException('Nothing was frozen: ' . $e->getMessage());
					}
				}
				$template = self::freeze_steps($source);
			} else {
				// A dead site's server, when this management node created it, is
				// powered off first: two machines must never run the site. A
				// container source's old container is stopped on its host, and
				// kept stopped, once the copy has taken the site (start_steps()).
				$sp = IpSwapMove::provision_of($source);
				if (isset($record['swap'])) {
					$record['power'] = $record['swap'];
				} elseif ($sp && ComputeDriverRegistry::known((string)$sp->get('cvp_provider')) && !$sp->is_transferred()) {
					$record['power'] = array('source' => array('provision_id' => (int)$sp->key,
						'instance_id' => (string)$sp->get('cvp_instance_id')));
				}
				if (isset($record['power'])) {
					$template[] = array('op' => 'power_off', 'on' => 'source', 'arg' => 'source');
				}
			}
			if ($method === self::METHOD_DNS) {
				// Lowered last, once nothing else can refuse, before anything
				// is frozen: a resolver that asks again after this waits a
				// minute, and so does a move back.
				$plan = array('zone' => $record['zone'], 'records' => $record['records']);
				try {
					$record['records'] = DnsRecordMove::lower_ttl($driver, $plan);
				} catch (Exception $e) {
					// Some records may have reached a minute: their TTLs go back
					// now, or, when that fails too, the page names each original.
					$put_back = '';
					try {
						DnsRecordMove::restore_ttl($driver, $plan, 'from');
						$put_back = ' The TTLs already lowered were put back.';
					} catch (Exception $e2) {
						$was = array();
						foreach ($plan['records'] as $rec) {
							$was[] = "{$rec['name']} ({$rec['type']}) " . (int)$rec['ttl_was'] . ' s';
						}
						$put_back = ' Putting back the TTLs already lowered failed too; set them back by hand: ' . implode(', ', $was) . '.';
					}
					throw new SiteCopyException('Nothing was ' . ($copy->from_backups() ? 'changed' : 'frozen') . ': ' . $e->getMessage() . $put_back);
				}
				$record['ttl_lowered'] = true;
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
	 * A copy's run of a living site: RUN_STEPS, and for a site in a container
	 * its host's certificate staged inside it just before the export and
	 * cleared just after. The host ends the site's TLS, so its lineage is on the
	 * host, where the export (made inside the container) cannot see it.
	 */
	private static function run_steps(ManagedNode $source): array {
		$steps = array();
		$container = trim((string)$source->get('mgn_container_name')) !== '';
		foreach (self::RUN_STEPS as $s) {
			if ($container && $s['op'] === 'copy_export') {
				$steps[] = array('op' => 'site_certificate_stage', 'on' => 'site_host', 'arg' => 'stage');
				$steps[] = $s;
				$steps[] = array('op' => 'site_certificate_stage', 'on' => 'site_host', 'arg' => 'clear');
				continue;
			}
			$steps[] = $s;
		}
		return $steps;
	}

	/**
	 * The final copy's steps: FREEZE_STEPS, a container source held quiet from
	 * outside by its own host straight after it freezes itself (its 503 up,
	 * its scheduled tasks held, its command-line PHP finished), before the
	 * final backup.
	 */
	private static function freeze_steps(ManagedNode $source): array {
		$steps = self::FREEZE_STEPS;
		if (trim((string)$source->get('mgn_container_name')) !== '') {
			array_splice($steps, 1, 0, array(self::FREEZE_ON_HOST_STEP));
		}
		return $steps;
	}

	/**
	 * Where a frozen site forwards while its address moves by DNS, and the
	 * address its forwarded requests arrive from: forward_to, the copy's
	 * machine's public address, and forward_from, the old site's own machine's
	 * address of the same family (its host's, for a container): IPv4 where
	 * both have one, else IPv6. Empty strings where they share no family:
	 * nothing forwards. probe_at, the copy's address its proof asks at, is
	 * kept whether or not anything forwards.
	 */
	private static function forward_plan(ManagedNode $source, ManagedNode $node, ?array $hold): array {
		$to = ProxiedOriginMove::machine_addresses($node);
		$from_node = $source;
		if ($hold) {
			$host = new ManagedNode((int)$hold['host_node_id'], TRUE);
			$from_node = $host->key ? $host : $source;
		}
		$from = ProxiedOriginMove::machine_addresses($from_node);
		$out = array('probe_at' => (string)($to[4][0] ?? ($to[6][0] ?? '')), 'forward_to' => '', 'forward_from' => '');
		foreach (array(4, 6) as $family) {
			if (!empty($to[$family][0]) && !empty($from[$family][0])) {
				$out['forward_to'] = (string)$to[$family][0];
				$out['forward_from'] = (string)$from[$family][0];
				break;
			}
		}
		return $out;
	}

	/**
	 * What stops a running site forwarding to its copy while a DNS move
	 * settles: the old site (and a container's host) forwards, and the
	 * machine answering the copy (its own, or its host) believes it, each
	 * needing an agent with the forward.
	 */
	private static function forward_refusals(SiteCopy $copy, ManagedNode $source, ?ManagedNode $node): array {
		$machines = array('The site' => $source);
		try {
			$hold = self::old_container_hold($source);
			if ($hold) {
				$machines['The site\'s own host'] = new ManagedNode((int)$hold['host_node_id'], TRUE);
			}
			$machines[$copy->on_host() ? 'The copy\'s host' : 'The copy'] = $copy->on_host() ? self::copy_host_node($copy) : $node;
		} catch (Exception $e) {
			return array($e->getMessage());
		}
		$why = array();
		foreach ($machines as $who => $m) {
			if (!$m) {
				$why[] = $who . ' has no record.';
				continue;
			}
			$agent = (string)AgentVocabulary::version($m);
			if ($agent === '' || version_compare($agent, JobCommandBuilder::FORWARD_MIN_AGENT_VERSION, '<')) {
				$why[] = $who . ' runs agent ' . ($agent !== '' ? $agent : 'of an unknown version') . '; forwarding visitors while the '
					. 'records move needs ' . JobCommandBuilder::FORWARD_MIN_AGENT_VERSION . ' or newer.';
			}
		}
		return $why;
	}

	/** Does this switch-over forward the frozen site's visitors while its DNS moves? */
	private static function forwards(SiteCopy $copy): bool {
		$switch = $copy->switch_record();
		return $copy->source_alive() && in_array((string)($switch['method'] ?? ''), array(self::METHOD_DNS, self::METHOD_MANUAL), true)
			&& (string)($switch['forward_to'] ?? '') !== '' && (string)($switch['forward_from'] ?? '') !== '';
	}

	/**
	 * The start of a switch by DNS (D1), once its records name the copy: the
	 * copy takes the node and starts as the site, its machine believes the old
	 * server's forwarded addresses, the old server forwards every visitor whose
	 * resolver still names it, and then the copy is issued its certificate,
	 * since the issuer's own resolver may still be one of those. After the
	 * row swap the copy's machine is the node's row ('source') and the old
	 * machine the retired one ('copy'). An old container is held stopped only
	 * once the move settles: it forwards until then.
	 */
	private static function dns_start_steps(SiteCopy $copy, ?ManagedNode $node): array {
		$steps = array();
		if (!$node || trim((string)$node->get('mgn_install_state')) !== 'retired') {
			$steps[] = array('op' => 'take_node_id', 'on' => 'copy');
		}
		$steps[] = array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'off');
		if ($copy->on_host()) {
			$steps[] = array('op' => 'site_quiet_host', 'on' => 'copy_host', 'arg' => 'off');
		}
		if (self::forwards($copy)) {
			$steps[] = array('op' => 'trust_forwarder', 'on' => $copy->on_host() ? 'copy_host' : 'source', 'arg' => 'trust');
			if (is_array($copy->switch_record()['hosts']['source'] ?? null)) {
				$steps[] = array('op' => 'site_quiet_host', 'on' => 'source_host', 'arg' => 'forward');
			}
			$steps[] = array('op' => 'site_quiet', 'on' => 'copy', 'arg' => 'forward');
		}
		if (!self::copy_carries_certificate($copy)) {
			$steps[] = array('op' => 'provision_certificate', 'on' => 'source');
		}
		// An old container that forwards nobody is held stopped now, as after
		// any other move.
		if (!self::forwards($copy) && is_array($copy->switch_record()['hold'] ?? null)) {
			$steps[] = array('op' => 'hold_container', 'on' => 'host', 'arg' => 'stop');
		}
		return $steps;
	}

	/**
	 * Once a DNS move has settled: the copy's machine stops believing the old
	 * server, whose address goes back to its provider when it is deleted, and
	 * the old server stops forwarding: powered off when this management node
	 * created it, an old container held stopped on its host, and any other
	 * old server (one brought, or transferred to its owner) quiet again, its
	 * maintenance page back in place of the forward.
	 */
	private static function settle_steps(SiteCopy $copy): array {
		$switch = $copy->switch_record();
		$steps = array();
		if (self::forwards($copy)) {
			$steps[] = array('op' => 'trust_forwarder', 'on' => $copy->on_host() ? 'copy_host' : 'source', 'arg' => 'untrust');
		}
		if (is_array($switch['hold'] ?? null)) {
			if (self::forwards($copy)) {
				$steps[] = array('op' => 'hold_container', 'on' => 'host', 'arg' => 'stop');
			}
		} elseif ($copy->source_alive() && is_array($switch['power'] ?? null)) {
			$steps[] = array('op' => 'power_off', 'on' => 'copy', 'arg' => 'source');
		} elseif (self::forwards($copy)) {
			$steps[] = array('op' => 'site_quiet', 'on' => 'copy', 'arg' => 'on');
		}
		return $steps;
	}

	/**
	 * A switched copy whose address moved by DNS: ask the public resolvers,
	 * and once they agree on the copy and one old TTL has passed, let go of
	 * the old server (settle_steps()). Called every tick for a switched copy
	 * still watching; never throws.
	 */
	public static function advance_watch(SiteCopy $copy): void {
		if (!self::lock()) {
			return;
		}
		$template = array();
		try {
			$copy->load();
			$switch = $copy->switch_record();
			if ($copy->status() !== SiteCopy::STATUS_SWITCHED || ($switch['watch']['state'] ?? '') !== 'watching') {
				return;
			}
			$watch = $switch['watch'];
			$r = DnsRecordMove::settled(array('records' => (array)($switch['records'] ?? array())), (string)$watch['moved_time']);
			$watch['last_check'] = gmdate('Y-m-d H:i:s');
			$watch['agree'] = $r['agree'];
			$watch['waiting_on'] = $r['waiting_on'];
			$watch['wait_until'] = gmdate('Y-m-d H:i:s', $r['wait_until']);
			if ($r['settled']) {
				$watch['state'] = 'settled';
				$watch['settled_time'] = gmdate('Y-m-d H:i:s');
				$watch['by_time'] = $r['by_time'];
				$switch['rdns_note'] = self::settle_reverse_dns($copy, !empty($r['agree']));
			}
			$switch['watch'] = $watch;
			$copy->set_switch_record($switch);
			$copy->save();
			if ($r['settled']) {
				$template = self::settle_steps($copy);
				if ($template) {
					self::lay_steps($copy, $template, SiteCopy::STATUS_SETTLING, 'settle');
					self::advance_locked($copy);
				}
			}
		} catch (Throwable $e) {
			error_log('SiteCopyRunner: copy #' . (int)$copy->key . '\'s DNS watch failed: ' . $e->getMessage());
		} finally {
			self::unlock();
		}
		self::start_worker_for($copy, $template);
	}

	/**
	 * Once a move by DNS has settled, the copy's server answers the site's
	 * name in reverse DNS too, when this management node made it: a provider
	 * takes only a name that resolves to the address, so not before. After
	 * the take-over the site's row is the copy's server. A container's
	 * address is its host's, and so is its reverse DNS. When the public
	 * resolvers agree, this machine's own resolver is not asked again (it may
	 * hold the old answer a little longer): the addresses they agree on are
	 * the moved records' new ones, the AAAA's among them, so the copy's IPv6
	 * is named when the site has one; the provider checks the name itself.
	 * Never throws: what happened is a line for the Copy tab.
	 */
	private static function settle_reverse_dns(SiteCopy $copy, bool $resolvers_agree): string {
		try {
			if ($copy->on_host()) {
				return '';
			}
			$node = self::source_row($copy);
			if (!SiteCopyAddressFacts::made_here($node)) {
				return '';
			}
			$domain = self::site_domain($node);
			$named = null;
			if ($resolvers_agree) {
				$named = array();
				foreach ((array)($copy->switch_record()['records'] ?? array()) as $rec) {
					if (strtolower((string)($rec['name'] ?? '')) === strtolower($domain) && in_array($rec['type'] ?? '', array('A', 'AAAA'), true)) {
						$named[] = (string)$rec['to'];
					}
				}
			}
			$p = NodeReverseDns::provisionForNode($node);
			$driver = IpSwapMove::$driver_for ? (IpSwapMove::$driver_for)($p) : null;
			$r = NodeReverseDns::setQuietly($node, $domain, $driver, $named);
			return $r['ok'] ? 'Reverse DNS: ' . $r['message'] . '.' : 'Reverse DNS was not set: ' . $r['message'];
		} catch (Exception $e) {
			return 'Reverse DNS was not set: ' . $e->getMessage();
		}
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
		if ($copy->on_host()) {
			// The copy's host lets its network go (the copy has left its own
			// quiet state).
			$steps[] = array('op' => 'site_quiet_host', 'on' => 'copy_host', 'arg' => 'off');
		}
		if (!self::copy_carries_certificate($copy)) {
			// Issued now that its name points there.
			$steps[] = array('op' => 'provision_certificate', 'on' => 'source');
		}
		// Last, so an old container its host cannot stop never holds up the
		// site, which already runs on the copy.
		if (is_array($copy->switch_record()['hold'] ?? null)) {
			$steps[] = array('op' => 'hold_container', 'on' => 'host', 'arg' => 'stop');
		}
		return $steps;
	}

	/**
	 * Did the site's certificate travel to the copy? Only in an export from a
	 * site on a server of its own: a run from backups carries none, and a
	 * container's certificate is on its host, not in the site.
	 */
	public static function copy_carries_certificate(SiteCopy $copy): bool {
		return !$copy->runs_from_backups() && !is_array($copy->switch_record()['hold'] ?? null);
	}

	/**
	 * Where a container source's old container is held stopped once its copy
	 * from backups has taken the site (site_copy.md WP14): its host's node and
	 * the container's name, recorded when the switch-over begins, before the
	 * node rows swap. Null for a source that is not a container; throws when
	 * the source is a container whose host this management node cannot reach.
	 */
	public static function old_container_hold(ManagedNode $source): ?array {
		$name = trim((string)$source->get('mgn_container_name'));
		if ($name === '') {
			return null;
		}
		$host = JobCommandBuilder::decommission_host_node_for($source);
		return array('host_node_id' => (int)$host->key, 'name' => $name);
	}

	/**
	 * The host a copy onto a host's switch-over step is addressed to, as the
	 * switch-over recorded it before the rows swapped: source_host (a container
	 * source's own) or copy_host. The copy's host, before any switch-over,
	 * is the one it was installed on.
	 */
	private static function switch_host(SiteCopy $copy, string $which): ManagedNode {
		$entry = $copy->switch_record()['hosts'][$which === 'source_host' ? 'source' : 'copy'] ?? null;
		if (!is_array($entry) || empty($entry['host_node_id'])) {
			if ($which === 'copy_host') {
				return self::copy_host_node($copy);
			}
			throw new SiteCopyException('the switch-over recorded no host for the site');
		}
		try {
			$host = new ManagedNode((int)$entry['host_node_id'], TRUE);
		} catch (Exception $e) {
			$host = null;
		}
		if (!$host || !$host->key || $host->get('mgn_delete_time')) {
			throw new SiteCopyException('a host this switch-over uses has no node record any more');
		}
		return $host;
	}

	/** The host node a hold step is addressed to, as the switch-over recorded it. */
	private static function hold_host(SiteCopy $copy): ManagedNode {
		$hold = $copy->switch_record()['hold'] ?? null;
		if (!is_array($hold) || empty($hold['host_node_id'])) {
			throw new SiteCopyException('the switch-over recorded no old container to stop');
		}
		try {
			$host = new ManagedNode((int)$hold['host_node_id'], TRUE);
		} catch (Exception $e) {
			$host = null;
		}
		if (!$host || !$host->key || $host->get('mgn_delete_time')) {
			throw new SiteCopyException('the old container\'s host has no node record any more');
		}
		return $host;
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
			// What the last undo said is about the move this one replaces.
			$switch['move_failure'] = null;
			$switch['moved_back_notes'] = array();
			$switch['aaaa_left'] = null;
			if ($method === self::METHOD_IP_SWAP) {
				$template = array(array('op' => 'power_off', 'on' => 'source', 'arg' => 'source'));
				if (!empty($switch['swap']['both_off'])) {
					$template[] = array('op' => 'power_off', 'on' => 'source', 'arg' => 'copy');
				}
				$template[] = array('op' => 'ip_swap',     'on' => 'source', 'arg' => 'over');
				$template[] = array('op' => 'power_cycle', 'on' => 'copy',   'arg' => 'copy');
				$template[] = array('op' => 'probe',       'on' => 'copy');
				$switch['moved_time'] = gmdate('Y-m-d H:i:s');
				$copy->set_switch_record($switch);
				self::lay_steps($copy, array_merge($template, self::start_steps($copy, $node)), SiteCopy::STATUS_STARTING, 'start');
				self::advance_locked($copy);
				return;
			}
			if ($method === self::METHOD_DNS || $method === self::METHOD_MANUAL) {
				// The copy answers at its own address under the site's name
				// first: nothing moves toward a copy that does not.
				$domain = self::site_domain($source);
				if ((string)($switch['probe_at'] ?? ($switch['forward_to'] ?? '')) === '') {
					throw new SiteCopyException('The copy has no public address on record to be proven at, so nothing moved. Run a status '
						. 'check on it, or go back.');
				}
				// At the copy's own address, whether or not anything forwards:
				// the name still resolves to the frozen site.
				$proof = ProxiedOriginMove::prove($domain, (string)$copy->get('scp_look_path'), null,
					self::copy_carries_certificate($copy), (string)($switch['probe_at'] ?? ($switch['forward_to'] ?? '')));
				$switch['proof'] = $proof;
				if (!$proof['proven']) {
					$copy->set_switch_record($switch);
					$copy->save();
					throw new SiteCopyException('The copy did not answer at its own address under the site\'s name within '
						. $proof['seconds'] . ' seconds (last answer: ' . $proof['last'] . '), so nothing moved. The site is still frozen: '
						. 'try again, or go back.');
				}
				if ($method === self::METHOD_DNS) {
					if (!$driver) {
						throw new SiteCopyException('Enter the DNS token.');
					}
					try {
						DnsRecordMove::move($driver, array('zone' => $switch['zone'] ?? '', 'records' => $switch['records'] ?? array()));
					} catch (Exception $e) {
						throw new SiteCopyException('The address did not move. ' . $e->getMessage());
					}
				}
				$switch['address_at'] = 'copy';
				$switch['moved_time'] = gmdate('Y-m-d H:i:s');
				$copy->set_switch_record($switch);
				// Watched until the old server can be let go, when there is
				// anything of it to let go: a forward, or its power.
				if (self::settle_steps($copy)) {
					$switch['watch'] = array('state' => 'watching', 'moved_time' => $switch['moved_time']);
					$copy->set_switch_record($switch);
				}
				$template = self::dns_start_steps($copy, $node);
				self::lay_steps($copy, $template, SiteCopy::STATUS_STARTING, 'start');
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

			$proof = ProxiedOriginMove::prove($domain, (string)$copy->get('scp_look_path'), null, self::copy_carries_certificate($copy));
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
			$method = (string)($switch['method'] ?? '');
			$steps = in_array($method, array(self::METHOD_DNS, self::METHOD_MANUAL), true)
				? self::dns_start_steps($copy, self::copy_row($copy, true)) : self::start_steps($copy, self::copy_row($copy, true));
			self::lay_steps($copy, $steps, SiteCopy::STATUS_STARTING, 'start');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
	}

	/**
	 * Let go of the old server again after the settle stopped (an agent that
	 * did not answer, a provider that refused the power-off): every settle
	 * step again, each of which passes when already done.
	 */
	public static function retry_settle(SiteCopy $copy): void {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		$template = array();
		try {
			$copy->load();
			$switch = $copy->switch_record();
			if ($copy->status() !== SiteCopy::STATUS_HALTED || ($switch['phase'] ?? '') !== 'settle') {
				throw new SiteCopyException('Only a letting-go of the old server that stopped is tried again.');
			}
			$template = self::settle_steps($copy);
			self::lay_steps($copy, $template, SiteCopy::STATUS_SETTLING, 'settle');
			self::advance_locked($copy);
		} finally {
			self::unlock();
		}
		self::start_worker_for($copy, $template);
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
	/**
	 * What going back noted, for the Copy tab: what it recorded (a reverse
	 * DNS set back, records a move back left), a site AAAA record still at
	 * the copy's IPv6 for as long as DNS still names it (B66), and, after a
	 * manual switch went back, each record the owner has yet to point back,
	 * for as long as DNS still names the copy there.
	 */
	public static function went_back_notes(array $switch): array {
		$notes = array_values(array_filter(array_map('strval', (array)($switch['moved_back_notes'] ?? array()))));
		$aaaa = IpSwapMove::aaaa_note($switch['aaaa_left'] ?? null);
		if ($aaaa !== '') {
			$notes[] = $aaaa;
		}
		if ((string)($switch['method'] ?? '') === self::METHOD_MANUAL && ($switch['address_at'] ?? '') === 'source'
				&& !empty($switch['moved_back_time'])) {
			$left = self::manual_records_back($switch, true);
			if ($left) {
				$notes[] = 'DNS still names the copy. At your DNS host, point these back at this site\'s own server: '
					. implode('; ', $left) . '. Until then visitors whose resolvers follow it reach the copy, which no longer serves the site.';
			}
		}
		return $notes;
	}

	/**
	 * A manual switch's records as the owner points them back at their DNS
	 * host, one line each ("NAME TYPE: copy address → site's address"). With
	 * $still_at_copy, only those DNS still names the copy's address for; a
	 * lookup that fails says nothing either way, so the record is left out.
	 */
	public static function manual_records_back(array $switch, bool $still_at_copy = false): array {
		if ((string)($switch['method'] ?? '') !== self::METHOD_MANUAL) {
			return array();
		}
		$out = array();
		foreach ((array)($switch['records'] ?? array()) as $rec) {
			$name = (string)($rec['name'] ?? '');
			$type = (string)($rec['type'] ?? '');
			$to = (string)($rec['to'] ?? '');
			$from = (string)($rec['from'] ?? '');
			if ($name === '' || $to === '' || $from === '') {
				continue;
			}
			if ($still_at_copy) {
				try {
					$now = $type === DnsRecord::TYPE_AAAA ? DnsResolver::getAaaa($name) : DnsResolver::getA($name);
				} catch (Throwable $e) {
					continue;
				}
				$named = false;
				foreach ($now as $a) {
					if (@inet_pton((string)$a) !== false && @inet_pton((string)$a) === @inet_pton($to)) {
						$named = true;
					}
				}
				if (!$named) {
					continue;
				}
			}
			$out[] = $name . ' ' . $type . ' from ' . $to . ' to ' . $from;
		}
		return $out;
	}

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
			// Read before the watch is ended below: a settle that began asked for
			// the old server's power-off, whether or not it finished.
			$settle_began = !empty($switch['watch']['settled_time']);
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
			if ($method === self::METHOD_DNS && (($switch['address_at'] ?? 'source') === 'copy' || !empty($switch['ttl_lowered']))) {
				// The records back at the site's server, and their TTLs back, from
				// wherever the switch-over stopped.
				if (!$driver) {
					throw new SiteCopyException(($switch['address_at'] ?? 'source') === 'copy'
						? 'The site\'s records name the copy, and going back changes them back: enter the DNS token.'
						: 'The site\'s records carry a one-minute TTL for the switch-over, and going back puts their TTLs back: enter the DNS token.');
				}
				$plan = array('zone' => $switch['zone'] ?? '', 'records' => $switch['records'] ?? array());
				try {
					if (($switch['address_at'] ?? 'source') === 'copy') {
						$switch['moved_back_notes'] = DnsRecordMove::move_back($driver, $plan);
						$switch['address_at'] = 'source';
						$switch['moved_back_time'] = gmdate('Y-m-d H:i:s');
					}
					DnsRecordMove::restore_ttl($driver, $plan, 'from');
					$switch['ttl_lowered'] = false;
				} catch (Exception $e) {
					throw new SiteCopyException('The records did not move back, and nothing else was done. ' . $e->getMessage());
				}
			} elseif ($method === self::METHOD_MANUAL && ($switch['address_at'] ?? 'source') === 'copy') {
				// The owner changes the records back at their DNS host; the tab
				// names them (manual_records_back), and keeps naming any DNS
				// still points at the copy (went_back_notes).
				$switch['address_at'] = 'source';
				$switch['moved_back_time'] = gmdate('Y-m-d H:i:s');
			}
			if (isset($switch['watch'])) {
				$switch['watch']['state'] = 'ended';
			}
			$copy->set_switch_record($switch);
			self::cancel_open_steps($copy->steps());
			if ($swapped) {
				// The copy, still the node, is quieted (and held off the network
				// by its host, for a copy onto a host), then the rows swap back.
				$template[] = array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'on');
				if ($copy->on_host()) {
					$template[] = array('op' => 'site_quiet_host', 'on' => 'copy_host', 'arg' => 'on');
				}
				$template[] = array('op' => 'go_back',    'on' => 'source');
			}
			if ($method === self::METHOD_IP_SWAP && (!empty($switch['moved_time']) || ($switch['address_at'] ?? '') === 'copy')) {
				if (!empty($switch['swap']['both_off'])) {
					$template[] = array('op' => 'power_off',   'on' => 'source', 'arg' => 'copy');
				}
				$template[] = array('op' => 'ip_swap',     'on' => 'source', 'arg' => 'back');
				$template[] = array('op' => 'power_cycle', 'on' => 'copy',   'arg' => 'copy');
			}
			// The copy's machine stops believing the old server's forwarded
			// addresses (after the swap back, the copy's row is the copy's).
			// It was told to only as it took the node (dns_start_steps()); a
			// copy that never took it believes nothing, and as a dormant copy
			// refuses the word.
			if (self::forwards($copy) && $swapped) {
				$template[] = array('op' => 'trust_forwarder', 'on' => $copy->on_host() ? 'copy_host' : 'copy', 'arg' => 'untrust');
			}
			if (!$backups) {
				// The frozen site runs again: its server is powered on when the
				// swap or a settled DNS move powered it off, an old container
				// held stopped starts; a container's host lets its network go
				// while it still shows its maintenance page; then it leaves its
				// own quiet, and stops forwarding.
				// After a settle that asked for the power-off too, whether or not it
				// finished: powering on a running server is a step that passes.
				if ($method === self::METHOD_IP_SWAP || !empty($switch['source_off'])
						|| ($settle_began && is_array($switch['power']['source'] ?? null))) {
					$template[] = array('op' => 'power_on', 'on' => 'source', 'arg' => 'source');
				}
				if (is_array($switch['hold'] ?? null) && !empty($switch['held'])) {
					$template[] = array('op' => 'hold_container', 'on' => 'host', 'arg' => 'start');
				}
				if (is_array($switch['hosts']['source'] ?? null)) {
					$template[] = array('op' => 'site_quiet_host', 'on' => 'source_host', 'arg' => 'off');
				}
				$template[] = array('op' => 'site_quiet', 'on' => 'source', 'arg' => 'off');
			}
			// A copy onto a host that had become the site is held stopped on
			// its host, with what was written on it, for its owner to remove.
			if ($swapped && $copy->on_host()) {
				$template[] = array('op' => 'hold_container', 'on' => 'copy_host', 'arg' => 'stop');
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
			if ($copy->on_host()) {
				try {
					$switch['moved_back_notes'] = array_merge((array)($switch['moved_back_notes'] ?? array()), array(
						'The copy\'s container ' . self::site_name($source) . ' is held stopped on ' . self::switch_host($copy, 'copy_host')->get('mgn_name')
						. ', with what was written on it while it was the site. Remove it there once nothing of it is wanted.'));
					$copy->set_switch_record($switch);
				} catch (Exception $e) {
					// The note only names where it is.
				}
			}
			// The copy's agent took the site's node id; its row holds its own
			// key again, so it can no longer reach this management node.
			$node = self::copy_row($copy, true);
			if ($node) {
				DataRootEscrowSync::let_go($node);
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
	public static function finish(SiteCopy $copy, ?DnsProvider $driver = null): string {
		if (!self::lock()) {
			throw new SiteCopyException('This copy is being moved along right now; try again in a moment.');
		}
		try {
			$copy->load();
			$switch = $copy->switch_record();
			// A settle that stopped has moved the site already: it can be kept,
			// and the tab names what of the old server was not let go.
			$settle_halted = $copy->status() === SiteCopy::STATUS_HALTED && ($switch['phase'] ?? '') === 'settle';
			if ($copy->status() !== SiteCopy::STATUS_SWITCHED && !$settle_halted) {
				throw new SiteCopyException('Only a finished switch-over is kept; this copy is ' . strtolower($copy->status_label()) . '.');
			}
			$watch = (string)($switch['watch']['state'] ?? '');
			if ($watch === 'watching') {
				throw new SiteCopyException('The site\'s address is still moving: the old server forwards the visitors whose resolvers '
					. 'still name it. Keep the switch-over once the move has settled.');
			}
			if ((string)($switch['method'] ?? '') === self::METHOD_DNS) {
				// The records' TTLs go back to what they were.
				if (!$driver) {
					throw new SiteCopyException('Enter the DNS token: keeping the switch-over puts the records\' TTLs back.');
				}
				try {
					DnsRecordMove::restore_ttl($driver, array('zone' => $switch['zone'] ?? '', 'records' => $switch['records'] ?? array()), 'to');
				} catch (Exception $e) {
					throw new SiteCopyException('The records\' TTLs could not be put back, so nothing was kept: ' . $e->getMessage());
				}
			}
			$server = self::server_to_delete($copy);
			$node = self::copy_row($copy, true);
			if ($node && trim((string)$node->get('mgn_install_state')) === 'retired') {
				DataRootEscrowSync::let_go($node);
				$node->soft_delete();
			}
			$switch = $copy->switch_record();
			$switch['server_to_delete'] = $server;
			$copy->set_switch_record($switch);
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
					self::copy_carries_certificate($copy));
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
					$switch['aaaa_left'] = $state['aaaa_left'] ?? null;
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
		if ($op === 'power_off' && (string)($steps[$pos]['arg'] ?? '') === 'copy') {
			// The copy, powered off for the swap (both_off), on again.
			$undo[] = array('op' => 'power_on', 'on' => 'source', 'arg' => 'copy');
		} elseif ($op !== 'power_off') {
			if (!empty($switch['swap']['both_off'])) {
				$undo[] = array('op' => 'power_off', 'on' => 'source', 'arg' => 'copy');
			}
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
