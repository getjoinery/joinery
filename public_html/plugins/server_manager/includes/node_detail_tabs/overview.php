<?php
/**
 * node_detail — Overview tab partial.
 *
 * Included by views/admin/node_detail.php in the shell's scope; the shell
 * owns node loading, the tab whitelist, and the permission gate. Lives under
 * includes/ (not views/) so it is not reachable as a standalone URL.
 *
 * In scope: $node, $page, $session, $base_url, $node_name, $page_regex,
 * $skip_joinery, $tab.
 *
 * @version 1.48 - the outbound limits include each site's speed ceiling (node_outbound_and_transfer WP4); a
 *                 machine where tc refused part of it says so
 * @version 1.47 - a site container on a host can be held stopped, with the reason why, and started again; a held
 *                 site's own page says who held it, when and why (multi_tenant_docker_hosts WP7)
 * @version 1.46 - a script edited on this management node and not yet published warns as one committed does
 * @version 1.45 - the machine's outbound connection limits (host_report 1.11, node_outbound_and_transfer
 *                 WP3): on, off, or not in force and why; a site whose limits dropped packets since the
 *                 last report is amber, and a site container they do not cover says so
 * @version 1.44 - a site container held stopped (a switch-over's old container) reads Held stopped, with no Restart
 * @version 1.42 - a pending reboot names when it was asked for, and is amber only once it has
 *                 waited more than a day: a multi-tenant host takes its own the night an update
 *                 asks, so a day's wait is one that did not happen (host_report 1.8)
 * @version 1.41 - a removed old container whose host still holds its certificate offers Remove it from the host
 * @version 1.40 - the old machine's Site line reads moved only when the domain also reaches the new server; the
 *                 delete confirmation says so
 * @version 1.39 - Health shows the machine's outbound transfer this month against its allowance
 *                (specs/node_outbound_and_transfer.md WP1)
 * @version 1.38 - a script committed after the last publish (unpublished_file) shows as a warning, not a refusal
 * @version 1.37 - each site container shows its own figures (memory, peak, CPU, traffic, disk,
 *                 processes, an amber count of out-of-memory kills) on its server's page, and a
 *                 container site's page shows its own line from its server's report
 * @version 1.36 - the old machine of a switch-over shows beside its site whether the domain still reaches it
 *                 (MovedSiteCheck): the stored answer at once, asked again when stale, and Check again
 * @version 1.35 - Permanently Delete Site on the old machine of a switch-over says the host checks the domain
 *                 left instead of asking the site to approve (site_copy.md WP14)
 * @version 1.34 - the move panel asks CustomerCloudProvision::is_sold()
 * @version 1.33 - the Move to customer's Linode panel on a Managed site's node: the transfer check, run when the
 *                 tab opens, with Re-check and Start, and the transfer's state linked to its queue row
 *                 (specs/managed_to_self_hosted_transfer.md §6); a moved site's Reverse DNS panel says it is the customer's
 * @version 1.32 - the Cases card is the Incidents card (incident_triage.md WP1): this node's incidents, linked
 *                 to their own pages, and the alert under the header counts what needs a person by the header line's rule
 * @version 1.31 - every time on the page reads as an age (LibraryFunctions::time_ago): minutes, hours, days with
 *                 the time of day, the date beyond a week; a quiet site's scheduled tasks read as held, never as
 *                 the last-run time its database carries from before it went quiet; a live site's last run is told
 *                 against the status check that measured it, not against now
 * @version 1.30 - the page leads with the node at a glance (node number, address, site, release, OS, how long it has
 *                 run); Health shows bars for disk, memory, swap and load, and each service running or not; the
 *                 agent and the machine's security each have their own box; disk and memory are shown once
 * @version 1.29 - the Host card says a quiet site's PHP-FPM is quiet (host_report 1.6); the copy banner links to its source's Copy tab; the retired banner says removing the row does not delete the server
 * @version 1.28 - a banner for each of the site copy's install states (copy, switching, retired), and the
 *                 certificate card's DNS check skips any node in an install state
 * @version 1.27 - Remove from Dashboard's confirmation says what else goes with the node, and what is kept
 * @version 1.26 - the Service box reads a DNS server's source_ok only; no DNS server reports db_connected
 * @version 1.25 - the Service box reads a DNS server's source_ok (site reachable/unreachable), and
 *                 db_connected only from a 1.8 server that reports it instead
 * @version 1.24 - specs/agent_recipes_and_vocabulary.md: services answer/Restart, served certificates, site
 *                 containers, sshd's widened settings; config/page/table/installer/reset forms; a paired node
 *                 lacking any word this tab offers shows the one "needs a newer agent" state (AgentVocabulary)
 * @version 1.23 - the Machine box names the operating system and the release upgrade the node's own
 *                 check last offered, with the date of that check
 * @version 1.22 - a Plugin Checks card: each plugin check the node records for fleet reporting,
 *                 naming any that does not pass (the same list that fails the node's badge)
 * @version 1.21 - a Clear button beside each failed unit on the compiled list (reset_failed_unit),
 *                 with a confirm that says what it does and does not do
 * @version 1.20 - the Logs picker offers what this node's agent will answer about
 *                 (JobCommandBuilder::site_log_files_for), so the PostgreSQL entry appears only
 *                 on an agent that has it
 * @version 1.19 - the Host card asks the two new questions: Why? beside a failed unit
 *                 (unit_journal) and What is using it? beside the disk figures (disk_usage);
 *                 the Machine box shows free space, inode use and any kernel event the node counted
 * @version 1.18 - the Logs box: read a log file or a log table from a node whose agent ships site_log /
 *                log_table_tail; shown disabled with the owner's reason when the node last reported
 *                its log-access switch off (specs/agent_log_access.md §4)
 * @version 1.17 - the not-applicable caption covers both reasons a recipe does not tick here: a container agent
 *                 whose recipe's subject is the host, and a machine with no site whose recipe's repair is an
 *                 installer the support bundle does not carry (agent_supervision)
 * @version 1.16 - the recipe line carries what each check last said (fail2ban (armed, check: fail)) as the
 *                 agent reported it at its last poll, so a failing recipe is visible before its case
 * @version 1.15 - Run Plugin Installers is shown only for a node with a site (a machine with no site has
 *                 no plugins to have installers); Run Host Housekeeping stays for every node with the word,
 *                 since on a machine it now runs the runner's --machine mode; the recipe line explains a
 *                 not-applicable mode (a container agent whose recipe's subject is the host)
 * @version 1.14 - the Cases card, under the Host card: the cases this node's agent opened, open first, then
 *                 closed, each with a human's note and a mark-read control (IncidentCaseCard); shown for every
 *                 node with an agent, so a node with no case says so
 * @version 1.13 - the Host card opens with the node's recipe list and each recipe's mode (report-only
 *                 or armed), as the agent reported it at its last poll, so a person can see from the
 *                 node page that a node is checking on its own clock and whether it acts
 * @version 1.12 - Run Host Housekeeping in the Actions dropdown, beside Run Plugin Installers, for a
 *                 node whose agent ships host_converge: fail2ban housekeeping now, as root, through
 *                 the host runner
 * @version 1.11 - the Host card: the machine as the host_report observe word last described it
 *                 (expected units and their state, failed units, jails with ban counts, SSH auth
 *                 failures as a count, sshd posture, reboot-required, unattended-upgrades, when it
 *                 was read), every value escaped, unknown shown as unknown; a Host Report button
 *                 beside Install Report for a node whose agent ships the word
 * @version 1.10 - the Memory card shows swap used beside memory used, a swapless box saying so
 * @version 1.9 - Install Report button beside Check Status, for a node whose agent ships
 *                install_report.
 * @version 1.8 - the health badge and the measured-at line read the fold's per-key provenance, so
 *                figures too old to judge health by grey the badge out instead of colouring it green
 * @version 1.8 - Permanently Delete Site is offered for container sites (the removal runs on the
 *                host's agent, so SSH plays no part in the gate); a dedicated machine gets the
 *                provider-deletion note instead
 * @version 1.7 - the Actions item leads to the API Keys tab: either the pending join requests to
 *                review, or the connect instructions (enrollment starts on the node — Phase 1.5)
 * @version 1.6 - Actions menu offers agent pairing (superadmin, unpaired nodes) — posts the existing
 *                pair_agent action and lands on the API Keys tab where the one-time token is shown
 * @version 1.5 - DNS publish box above the Reverse DNS panel
 * @version 1.4 - Permanently Delete Entry: when offsite backups still exist for the slug, the menu item
 *                shows a "removal not allowed" alert up front instead of the type-to-confirm box
 * @version 1.3 - Danger Zone onclick values are htmlspecialchars(json_encode(), ENT_QUOTES) — a raw
 *                json_encode string embeds a double quote that closed the double-quoted onclick attribute,
 *                so Permanently Delete Site/Entry silently did nothing when clicked
 * @version 1.2 - Danger Zone: a removed node is offered the host teardown only when this management
 *                node once saw a live site here (status/version/uptime); otherwise a note + purge only
 * @version 1.1 - Danger Zone: two-tier delete (Remove from Dashboard / Permanently Delete Site),
 *                plus Permanently Delete Entry (purge_node) on an already-removed node
 * @version 1.0
 */
?>
<form id="nodeActionCheckStatus" method="post" action="<?php echo $base_url; ?>" hidden>
	<input type="hidden" name="action" value="check_status">
	<?php echo SmAdminCsrf::field(); ?>
</form>
<form id="nodeActionInstallReport" method="post" action="<?php echo $base_url; ?>" hidden>
	<input type="hidden" name="action" value="install_report">
	<?php echo SmAdminCsrf::field(); ?>
</form>
<form id="nodeActionHostReport" method="post" action="<?php echo $base_url; ?>" hidden>
	<input type="hidden" name="action" value="host_report">
	<?php echo SmAdminCsrf::field(); ?>
</form>
<form id="run_plugin_installers_form" method="post" action="<?php echo $base_url; ?>" hidden>
	<input type="hidden" name="action" value="run_plugin_installers">
	<?php echo SmAdminCsrf::field(); ?>
</form>
<form id="nodeActionDiskUsage" method="post" action="<?php echo $base_url; ?>" hidden>
	<input type="hidden" name="action" value="disk_usage">
	<?php echo SmAdminCsrf::field(); ?>
</form>
<form id="host_converge_form" method="post" action="<?php echo $base_url; ?>" hidden>
	<input type="hidden" name="action" value="host_converge">
	<?php echo SmAdminCsrf::field(); ?>
</form>
<?php
	// Status summary card
	$status_data = $node->get('mgn_last_status_data');
	if (is_string($status_data)) {
		$status_data = json_decode($status_data, true);
	}
	// When the figures below were last MEASURED, which is not when a check last
	// ran: a probe can reach a node, learn nothing from it, and stamp
	// mgn_last_status_check on the way past. The fold records a measurement time
	// per key; that column is the fallback for a node whose blob predates it.
	$last_check = JobResultProcessor::status_last_measured($status_data)
		?: $node->get('mgn_last_status_check');

	// The three readings the badge is computed from. Named here because the badge
	// is exactly as current as the stalest of them.
	$badge_keys = array('disk_usage_percent', 'postgres_status', 'load_1m');
	$figures_stale = JobResultProcessor::status_figures_are_stale($status_data, $badge_keys);

	$last_check_job = ManagementJob::latestForNode($node->key, 'check_status');
	$last_job_failed = $last_check_job && $last_check_job->get('mjb_status') === 'failed';

	if ($last_job_failed) {
		$status_color = 'danger';
	} elseif (!$last_check || !$status_data) {
		$status_color = 'secondary';
	} elseif ($figures_stale) {
		// Grey, not green. Green is a claim about the node right now, and these
		// numbers are too old to support it — a node that filled its disk a month
		// after its last real status check showed green the whole time.
		$status_color = 'secondary';
	} elseif (
		(isset($status_data['disk_usage_percent']) && $status_data['disk_usage_percent'] > 90) ||
		(isset($status_data['postgres_status']) && $status_data['postgres_status'] !== 'accepting connections')
	) {
		$status_color = 'danger';
	} elseif (
		(isset($status_data['disk_usage_percent']) && $status_data['disk_usage_percent'] > 80) ||
		(isset($status_data['load_1m']) && $status_data['load_1m'] > 5)
	) {
		$status_color = 'warning';
	} else {
		$status_color = 'success';
	}

	// The machine as its agent last described it (host_report), sanitised
	// once here for the header, the health box, the agent and security boxes.
	$host_report = $node->get('mgn_last_host_report');
	if (is_string($host_report)) { $host_report = json_decode($host_report, true); }
	$host_report_time = trim((string)$node->get('mgn_last_host_report_time'));
	$hr = is_array($host_report) ? JobResultProcessor::sanitise_host_report($host_report) : null;
	$hr_str = function ($v) { return htmlspecialchars(is_scalar($v) ? (string)$v : 'unknown', ENT_QUOTES, 'UTF-8'); };
	$hr_when = function ($unix) use ($session) {
		if (!is_int($unix)) { return 'unknown'; }
		return LibraryFunctions::time_ago(gmdate('Y-m-d H:i:s', $unix), $session->get_timezone());
	};

	// ── The node at a glance: who it is, where it is, what it runs ──
	echo '<div class="border rounded p-3 mb-3">';
	echo '<div class="d-flex justify-content-between align-items-start gap-3">';
	echo '<div class="d-flex align-items-center flex-wrap gap-2">';
	echo '<span class="badge bg-' . $status_color . '" title="Overall health from the last status check">&bull;</span>';
	echo '<span class="fs-4 fw-semibold">' . $node_name . '</span>';
	echo '<span class="badge bg-secondary">Node #' . (int)$node->key . '</span>';
	if (!$node->is_operational()) {
		echo '<span class="badge bg-' . JobCommandBuilder::install_state_color((string)$node->get('mgn_install_state')) . '">'
			. htmlspecialchars($node->install_state_label()) . '</span>';
	}
	echo '</div>';
	?>
	<?php
	// Permanent-delete-the-SITE is offered whenever the removal can actually be
	// dispatched: a CONTAINER site (not a relay) with a safe site name derivable
	// from node fields — the teardown runs on the host's own agent, so no SSH
	// figures in it. A bare-metal node is a whole machine and gets the
	// provider-deletion note instead of a button that could only refuse.
	// Available for a removed node too — its site may still be running on the
	// host (Remove from Dashboard leaves it up).
	$decommission_site = null;
	$is_container_site = trim((string)$node->get('mgn_container_name')) !== '';
	if (!$node->get('mgn_is_relay') && $is_container_site) {
		try { $decommission_site = JobCommandBuilder::decommission_site_name($node); }
		catch (Throwable $e) { $decommission_site = null; }
	}
	$is_removed = (bool)$node->get('mgn_delete_time');
	// The page cannot SSH to hosts (the web user has no host key), so "does the
	// site still exist?" is answered from evidence this management node already holds:
	// a status check, a read version, or an uptime result all mean a live site was
	// once seen here. With none of that — e.g. an install that failed and never
	// stood a site up — there is nothing to tear down, so a removed node is not
	// offered the host-teardown action (the decommission job would find nothing).
	$site_ever_confirmed = $node->get('mgn_last_status_check')
		|| $node->get('mgn_joinery_version')
		|| $node->get('mgn_uptime_last_status');

	// Whether the record may be purged: blocked while offsite backups still exist
	// for the slug (or a target can't be listed). Checked here so the menu item
	// says "not allowed" up front instead of only rejecting after the confirm box.
	// Backup listing is a management-node S3 call (the web user can do it), unlike the
	// host SSH probe, so it is safe to run on render — only for a removed node.
	$purge_block = null; // null = allowed; string = reason it is blocked
	if ($is_removed) {
		require_once(PathHelper::getIncludePath('includes/TargetBackups.php'));
		try {
			$bk = TargetBackups::slug_backup_count($node->get('mgn_slug'));
			if ($bk['count'] > 0) {
				$purge_block = 'This site still has ' . $bk['count'] . ' offsite backup'
					. ($bk['count'] === 1 ? '' : 's')
					. '. Delete them from the backup target Stored Backups panel before deleting the record.';
			} elseif (!empty($bk['unchecked'])) {
				$purge_block = 'Backups could not be verified on: ' . implode(', ', $bk['unchecked'])
					. '. Resolve those targets before deleting the record.';
			}
		} catch (Throwable $e) {
			$purge_block = 'Could not check for existing backups (' . $e->getMessage()
				. '). Resolve that before deleting the record.';
		}
	}
	?>
	<div class="btn-group svm-relative">
		<button type="button" class="btn btn-sm btn-primary dropdown-toggle" onclick="var m=this.nextElementSibling;m.style.display=m.style.display==='block'?'none':'block'">Actions</button>
		<ul class="dropdown-menu dropdown-menu-end svm-dropdown-menu">
			<li><a class="dropdown-item" href="<?php echo $base_url; ?>&tab=overview&edit=1#connectionSettings">Edit Connection Settings</a></li>
			<?php
			// A machine with no site (a Docker host, a relay) has no plugins,
			// so nothing for this to run; the action would fail closed at the
			// runner. Its host-scope twin, Run Host Housekeeping, stays.
			if (JobCommandBuilder::has_primitive($node, 'run_plugin_installers') && trim((string)$node->get('mgn_web_root')) !== ''): ?>
				<li><a class="dropdown-item" href="#" onclick="JoineryModal.confirm('Run every active plugin\'s host installer on this node (root, idempotent)? Needed after activating a plugin that configures system services, e.g. the mail stack.', function(){ document.getElementById('run_plugin_installers_form').submit(); }); return false;">Run Plugin Installers</a></li>
			<?php endif; ?>
			<?php
			// fail2ban housekeeping now, through the host runner: the host_converge
			// operate word. Idempotent, and what the host timer already runs daily;
			// the job's transcript says what it did, and a host_report follows so
			// the Health box shows the machine after the run.
			if (JobCommandBuilder::has_primitive($node, 'host_converge')): ?>
				<li><a class="dropdown-item" href="#" onclick="JoineryModal.confirm('Run fail2ban housekeeping on this machine now, as root, through the host runner? Idempotent: it is what the host timer runs daily, and the transcript shows what it did.', function(){ document.getElementById('host_converge_form').submit(); }); return false;">Run Host Housekeeping</a></li>
			<?php endif; ?>
			<?php if ($session->get_permission() >= 10 && !$node->get('mgn_agent_public_key')):
				$overview_pending_joins = class_exists('AgentJoinRequest') ? count(AgentJoinRequest::pending()) : 0; ?>
				<li><a class="dropdown-item" href="<?php echo $base_url; ?>&tab=api_keys"><?php
					echo $overview_pending_joins > 0
						? 'Review agent join request' . ($overview_pending_joins > 1 ? 's' : '')
							. ' (' . (int)$overview_pending_joins . ')&hellip;'
						: 'Connect ' . htmlspecialchars($node->get('mgn_name')) . '\'s agent&hellip;';
				?></a></li>
			<?php endif; ?>
			<?php
			// A disposable relay (specs/relay_without_a_shell.md): no shell, no
			// agent, no key. Its whole vocabulary is Update and Delete, and both
			// are the mailbox plugin's acts on its Setup tab, where the relay's
			// row lives; this card only points at them. Present only while the
			// mailbox plugin is active and a relay row names this node.
			$disposable_relay = null;
			if ($node->get('mgn_is_relay') && !$is_removed && PluginHelper::isPluginActive('mailbox') && class_exists('MailboxRelay')) {
				foreach (new MultiMailboxRelay(array('deleted' => false)) as $candidate) {
					if (intval($candidate->get('mrl_mgn_managed_node_id')) === intval($node->key) && $candidate->usesRelayApi()) {
						$disposable_relay = $candidate;
						break;
					}
				}
			}
			if ($disposable_relay !== null): ?>
				<li><span class="dropdown-item-text text-muted small d-block px-3" style="max-width:22rem;white-space:normal;">Disposable relay: no shell, no agent. It is updated by re-imaging and removed by deleting its row; the machine itself is deleted at the provider by hand.</span></li>
				<li><a class="dropdown-item" href="/plugins/mailbox/admin/admin_mailbox_setup#relay-section">Update relay&hellip;</a></li>
				<li><a class="dropdown-item text-danger" href="/plugins/mailbox/admin/admin_mailbox_setup#relay-section">Delete relay&hellip;</a></li>
				<li><hr class="dropdown-divider"></li>
			<?php endif; ?>
			<li><hr class="dropdown-divider"></li>
			<?php if (!$is_removed): ?>
				<li>
					<form method="post" action="<?php echo $base_url; ?>" id="delete_node_form" style="margin:0;">
						<input type="hidden" name="action" value="delete_node">
						<?php echo SmAdminCsrf::field(); ?>
						<button type="button" class="dropdown-item text-danger" onclick="JoineryModal.confirm('Remove this site from the dashboard? The site keeps running on its host. Its tracking record goes, with any provisioning record and hosted trial from its hosting order; a domain bought for it is kept for its buyer.', function(){ document.getElementById('delete_node_form').submit(); })">Remove from Dashboard</button>
					</form>
				</li>
			<?php endif; ?>
			<?php if ($decommission_site !== null && (!$is_removed || $site_ever_confirmed)): ?>
				<li>
					<form method="post" action="<?php echo $base_url; ?>" id="decommission_node_form" style="margin:0;">
						<input type="hidden" name="action" value="decommission_node">
						<input type="hidden" name="confirm_site_name" value="<?php echo htmlspecialchars($decommission_site); ?>">
						<?php echo SmAdminCsrf::field(); ?>
						<button type="button" class="dropdown-item text-danger" onclick="JoineryModal.confirmTyped(<?php echo htmlspecialchars(json_encode(JobCommandBuilder::decommission_is_moved($node) ? 'Permanently delete the old machine\'s site on its host? It needs a check from the last ten minutes that the domain reaches the new server, and the host checks again that the domain no longer reaches this container. Otherwise nothing is removed. Then it destroys the container, its database, and every uploaded file. Offsite backups are kept. This cannot be undone.' : ($is_removed ? 'Permanently delete the site on the host? If it is still running there, this destroys the container, its database, and every uploaded file. Offsite backups are kept. This cannot be undone.' : 'Permanently delete this site? This destroys the container, its database, and every uploaded file on the host. Offsite backups are kept. This cannot be undone.')), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($decommission_site), ENT_QUOTES); ?>, function(){ document.getElementById('decommission_node_form').submit(); })">Permanently Delete Site&hellip;</button>
					</form>
				</li>
			<?php elseif ($decommission_site !== null && $is_removed): ?>
				<li><span class="dropdown-item-text text-muted small d-block px-3" style="max-width:22rem;white-space:normal;">No live site was ever confirmed on this host, so there is nothing to tear down. Use Permanently Delete Entry to remove the record.</span></li>
			<?php elseif (!$node->get('mgn_is_relay') && !$is_container_site && $node->get('mgn_web_root')): ?>
				<li><span class="dropdown-item-text text-muted small d-block px-3" style="max-width:22rem;white-space:normal;">This is a dedicated machine, not a container site. To retire it, delete the instance at its provider, then remove this record from the dashboard.</span></li>
			<?php endif; ?>
			<?php if ($is_removed): ?>
				<li>
					<?php if ($purge_block !== null): ?>
						<button type="button" class="dropdown-item text-danger" onclick="JoineryModal.alert(<?php echo htmlspecialchars(json_encode('Removal not allowed. ' . $purge_block), ENT_QUOTES); ?>)">Permanently Delete Entry&hellip;</button>
					<?php else: ?>
						<form method="post" action="<?php echo $base_url; ?>" id="purge_node_form" style="margin:0;">
							<input type="hidden" name="action" value="purge_node">
							<input type="hidden" name="confirm_slug" value="<?php echo htmlspecialchars($node->get('mgn_slug')); ?>">
							<?php echo SmAdminCsrf::field(); ?>
							<button type="button" class="dropdown-item text-danger" onclick="JoineryModal.confirmTyped(<?php echo htmlspecialchars(json_encode('Permanently delete the Server Manager entry for this site? This erases the tracking record and its history. It does NOT touch the host. This cannot be undone.'), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($node->get('mgn_slug')), ENT_QUOTES); ?>, function(){ document.getElementById('purge_node_form').submit(); })">Permanently Delete Entry&hellip;</button>
						</form>
					<?php endif; ?>
				</li>
			<?php endif; ?>
		</ul>
	</div>
	<?php
	echo '</div>';

	// The facts a person looks for first, each where the eye lands.
	$fact = function ($label, $value_html) {
		echo '<div><div class="text-muted small text-uppercase">' . $label . '</div>'
			. '<div class="fw-semibold" style="overflow-wrap:anywhere">' . $value_html . '</div></div>';
	};
	echo '<div class="svm-facts mt-3">';

	$addr_html = '<code>' . htmlspecialchars((string)$node->get('mgn_host')) . '</code>';
	$head_provision = class_exists('CustomerCloudProvision') ? CustomerCloudProvision::latest_for_node($node->key) : null;
	if ($head_provision && trim((string)$head_provision->get('cvp_instance_ipv6')) !== '') {
		$addr_html .= '<div class="small fw-normal"><code>' . htmlspecialchars((string)$head_provision->get('cvp_instance_ipv6')) . '</code></div>';
	}
	$fact('Address', $addr_html);

	if ($node->get('mgn_site_url')) {
		$head_url = htmlspecialchars((string)$node->get('mgn_site_url'));
		$site_html = '<a href="' . $head_url . '" target="_blank" rel="noopener">' . htmlspecialchars((string)parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST) ?: $head_url) . ' ↗</a>';
		if (MovedSiteCheck::applies($node)) {
			// The old machine of a switch-over: has its domain left it, and
			// does it reach the new server? The two proofs Permanently Delete
			// Site needs. The page asks again when the stored answers are stale.
			$moved_checking = MovedSiteCheck::settle($node);
			$site_html .= '<div class="small fw-normal" id="movedCheck" data-node="' . (int)$node->key . '"'
				. ' data-ask="' . (!$moved_checking && MovedSiteCheck::is_stale($node) ? '1' : '0') . '"'
				. ' data-checking="' . ($moved_checking ? '1' : '0') . '">'
				. '<div id="movedCheckLabel">' . MovedSiteCheck::label_html($node, $moved_checking, $session->get_timezone()) . '</div>'
				. '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075 mt-1" id="movedCheckAgain">Check again</button></div>';
			// The container is gone but its host still holds the site's
			// certificate, which certbot keeps failing to renew.
			$leftover_certs = MovedSiteCheck::leftover_certificates($node);
			if ($leftover_certs) {
				$cert_host_name = '';
				try {
					$cert_host_name = (string)JobCommandBuilder::decommission_host_node_for($node)->get('mgn_name');
				} catch (Exception $e) {
					// The name only decorates the sentence.
				}
				foreach ($leftover_certs as $cert_name) {
					$site_html .= '<div class="small fw-normal mt-2"><span class="text-warning">Its HTTPS certificate '
						. '<code>' . htmlspecialchars($cert_name) . '</code> is still on ' . htmlspecialchars($cert_host_name ?: 'its host')
						. ', which keeps trying to renew it.</span>'
						. '<form method="post" action="' . htmlspecialchars($base_url . '&tab=overview') . '" class="mt-1">'
						. '<input type="hidden" name="action" value="remove_site_certificate">'
						. '<input type="hidden" name="cert_name" value="' . htmlspecialchars($cert_name) . '">' . SmAdminCsrf::field()
						. '<button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2 svm-fs-075">Remove it from the host</button>'
						. '</form></div>';
				}
			}
		}
		$fact('Site', $site_html);
		if (MovedSiteCheck::applies($node)) {
			?>
<script>
(function () {
	var box = document.getElementById('movedCheck');
	var label = document.getElementById('movedCheckLabel');
	var again = document.getElementById('movedCheckAgain');
	var polls = 0;
	// The host's check takes seconds; poll while it runs, for about three minutes.
	function ask(force) {
		again.disabled = true;
		joineryApi.post('server_manager/moved_site_check', { node_id: parseInt(box.dataset.node, 10), force: force ? 1 : 0 })
			.then(function (data) {
				if (!data || !data.ok) {
					label.insertAdjacentHTML('beforeend', '<div class="text-warning"></div>');
					label.lastChild.textContent = (data && data.message) || 'The check could not run.';
					again.disabled = false;
					return;
				}
				label.innerHTML = data.html;
				if (data.checking && polls++ < 60) {
					setTimeout(function () { ask(false); }, 3000);
				} else {
					again.disabled = false;
				}
			})
			.catch(function (err) {
				label.insertAdjacentHTML('beforeend', '<div class="text-warning"></div>');
				label.lastChild.textContent = (err && err.message) || 'The check could not run.';
				again.disabled = false;
			});
	}
	again.addEventListener('click', function () { polls = 0; ask(true); });
	if (box.dataset.ask === '1' || box.dataset.checking === '1') { ask(false); }
})();
</script>
			<?php
		}
	} elseif ($node->hosts_site()) {
		$fact('Site', '<span class="text-muted fw-normal">no address recorded</span>');
	} else {
		$fact('Site', '<span class="text-muted fw-normal">none: a machine only</span>');
	}

	$cp_version = LibraryFunctions::get_joinery_version();
	$node_version = $node->get('mgn_joinery_version');
	$version_cmp = ($cp_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $node_version ?? ''))
		? version_compare($node_version, $cp_version) : null;
	if ($node_version) {
		$rel = htmlspecialchars($node_version);
		if ($version_cmp === -1) {
			$rel .= ' <span class="badge bg-warning">' . htmlspecialchars($cp_version) . ' available</span>';
		} elseif ($version_cmp === 1) {
			$rel .= ' <span class="badge bg-danger">ahead of this management node</span>';
		} elseif ($version_cmp === 0) {
			$rel .= ' <span class="badge bg-success">current</span>';
		}
		$fact('Release', $rel);
	}

	if ($hr && is_array($hr['os']) && $hr['os']['version'] !== 'unknown') {
		$os = $hr['os'];
		$os_html = htmlspecialchars(ucfirst($os['id']) . ' ' . $os['version']);
		if (!in_array($os['release_upgrade']['offered'], array('none', 'unknown'), true)) {
			$os_html .= '<div class="small fw-normal text-info">' . htmlspecialchars($os['release_upgrade']['offered']) . ' offered</div>';
		}
		$fact('Operating system', $os_html);
	}

	// Up: how long the machine has run, and whether the site answers from outside.
	$up_html = !empty($status_data['uptime']) ? htmlspecialchars((string)$status_data['uptime']) : '<span class="text-muted fw-normal">unknown</span>';
	$uptime_enabled = $node->get('mgn_uptime_enabled');
	$uptime_status  = $node->get('mgn_uptime_last_status');
	$uptime_down    = $node->get('mgn_uptime_down_since');
	if (!$uptime_enabled) {
		$up_html .= '<div class="small fw-normal text-muted">not monitored</div>';
	} elseif ($uptime_status === 'down') {
		$up_html .= '<div class="small text-danger">site down since ' . htmlspecialchars($uptime_down
			? LibraryFunctions::time_ago($uptime_down, $session->get_timezone()) : 'unknown') . '</div>';
	} elseif ($uptime_status === 'up') {
		$up_html .= '<div class="small fw-normal text-success">site answering</div>';
	} else {
		$up_html .= '<div class="small fw-normal text-muted">site not yet checked</div>';
	}
	$fact('Running for', $up_html);
	echo '</div>';
	echo '<div class="d-flex align-items-center gap-2 flex-wrap mt-3">';
	if ($last_check) {
		// "Measured", not "checked": the word has to survive the distinction the
		// value now respects, or the honest number reads as the old claim.
		echo '<small class="text-muted">Figures measured '
			. htmlspecialchars(LibraryFunctions::time_ago($last_check, $session->get_timezone()))
			. '</small>';
		if ($figures_stale && $status_data) {
			echo '<small class="text-warning">Too old to judge health by &mdash; run a status check.</small>';
		}
	} elseif (!$status_data) {
		echo '<small class="text-muted">No status check has been run yet.</small>';
	}
	echo '<button type="submit" form="nodeActionCheckStatus" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075">Check Status</button>';
	// The install log has only ever been readable by a shell on the box. A
	// node whose agent can read it offers the report here; one that cannot
	// shows nothing rather than a button that would refuse.
	if (JobCommandBuilder::has_primitive($node, 'install_report')) {
		echo ' <button type="submit" form="nodeActionInstallReport" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075" title="How the first-boot install went: DNS, certificate, and the tail of the install log">Install Report</button>';
	}
	// The machine, read now: units, jails, sshd posture, reboot-required. The
	// Health and Security boxes below render the answer.
	if (JobCommandBuilder::has_primitive($node, 'host_report')) {
		echo ' <button type="submit" form="nodeActionHostReport" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075" title="Read the machine now: failed units, fail2ban jails, SSH auth failures, sshd posture, reboot-required">Host Report</button>';
	}
	echo '</div>';

	// The words this tab offers, declared once (AgentVocabulary). A paired
	// node that lacks any of them gets the one standard state, naming what it
	// lacks, where the buttons and forms for them would be - never a blank.
	if ($node->get('mgn_agent_public_key')) {
		$tab_words = ['host_report', 'site_log', 'log_table_tail', 'file_head', 'unit_journal', 'reset_failed_unit',
			'disk_usage', 'restart_unit', 'schema_probe', 'run_installer', 'page_probe', 'reclaim_managed_file'];
		if (!$node->hosts_site()) {
			$tab_words = array_values(array_diff($tab_words, ['site_log', 'log_table_tail', 'schema_probe', 'page_probe']));
		}
		$tab_missing = AgentVocabulary::missing_words($node, $tab_words);
		if ($tab_missing) {
			echo '<div class="mt-2">' . AgentVocabulary::needs_newer_agent_html($node, $tab_missing) . '</div>';
		}
	}

	// The site's own logs, read on the node and redacted there, for a node
	// whose agent ships the two log words. The owner's switch on the node
	// decides; the node reports it at every poll, so a node that has said
	// "off" gets the reason here instead of a job that would be refused.
	$has_site_log  = JobCommandBuilder::has_primitive($node, 'site_log');
	$has_log_table = JobCommandBuilder::has_primitive($node, 'log_table_tail');
	$has_file_head = JobCommandBuilder::has_primitive($node, 'file_head');
	if ($has_site_log || $has_log_table || $has_file_head) {
		$log_refusal = JobCommandBuilder::log_access_refusal($node);
		echo '<details class="mt-2"><summary class="small text-muted" style="cursor:pointer;">Logs</summary>';
		if ($log_refusal !== null) {
			echo '<div class="small text-muted mt-1">' . htmlspecialchars($log_refusal) . '</div>';
		} else {
			echo '<div class="small text-muted mt-1 mb-2">The last lines of one of the site\'s log files, or the newest rows of one of its log tables. '
				. 'The node masks credentials and personal data before anything is sent; the result is on the job page.</div>';
			echo '<div class="d-flex flex-wrap gap-4">';
			if ($has_site_log) {
				echo '<div>';
				$fw_log = $page->getFormWriter('site_log_form', [
					'action' => $base_url . '&tab=overview',
					'values' => ['file' => 'error', 'lines' => '100'],
				]);
				$fw_log->begin_form();
				$fw_log->hiddeninput('action', '', ['id' => 'site_log_action', 'value' => 'site_log']);
				$fw_log->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'site_log_csrf', 'value' => SmAdminCsrf::token()]);
				// What this node's own agent will answer about: the PostgreSQL
				// entry is newer than the word, so an older agent does not
				// offer it and the picker does not either.
				$fw_log->dropinput('file', 'Log file', ['options' => JobCommandBuilder::site_log_files_for($node)]);
				$fw_log->checkboxinput('previous', 'Previous rotation (yesterday\'s file)');
				$fw_log->numberinput('lines', 'Lines (1 to ' . JobCommandBuilder::LOG_MAX_COUNT . ')', ['min' => 1, 'max' => JobCommandBuilder::LOG_MAX_COUNT]);
				$fw_log->submitbutton('btn_site_log', 'Read log file', ['class' => 'btn btn-sm btn-outline-secondary']);
				$fw_log->end_form();
				echo '</div>';
			}
			if ($has_log_table) {
				echo '<div>';
				$fw_tbl = $page->getFormWriter('log_table_form', [
					'action' => $base_url . '&tab=overview',
					'values' => ['table' => 'logins', 'rows' => '50'],
				]);
				$fw_tbl->begin_form();
				$fw_tbl->hiddeninput('action', '', ['id' => 'log_table_action', 'value' => 'log_table_tail']);
				$fw_tbl->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'log_table_csrf', 'value' => SmAdminCsrf::token()]);
				$fw_tbl->dropinput('table', 'Log table', ['options' => JobCommandBuilder::LOG_TABLES]);
				$fw_tbl->numberinput('rows', 'Rows (1 to ' . JobCommandBuilder::LOG_MAX_COUNT . ')', ['min' => 1, 'max' => JobCommandBuilder::LOG_MAX_COUNT]);
				$fw_tbl->submitbutton('btn_log_table', 'Read log table', ['class' => 'btn btn-sm btn-outline-secondary']);
				$fw_tbl->end_form();
				echo '</div>';
			}
			// Host configuration, from the compiled readable list: the node
			// returns a line whose key names a credential as the key alone,
			// and never reads a file that is itself a secret.
			if ($has_file_head) {
				echo '<div>';
				$fw_cfg = $page->getFormWriter('file_head_form', [
					'action' => $base_url . '&tab=overview',
					'values' => ['file' => 'fail2ban_joinery_sshd', 'lines' => '200'],
				]);
				$fw_cfg->begin_form();
				$fw_cfg->hiddeninput('action', '', ['id' => 'file_head_action', 'value' => 'file_head']);
				$fw_cfg->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'file_head_csrf', 'value' => SmAdminCsrf::token()]);
				$fw_cfg->dropinput('file', 'Configuration file', ['options' => JobCommandBuilder::FILE_HEAD_FILES]);
				$fw_cfg->numberinput('lines', 'Lines (1 to ' . JobCommandBuilder::FILE_HEAD_MAX_LINES . ')', ['min' => 1, 'max' => JobCommandBuilder::FILE_HEAD_MAX_LINES]);
				$fw_cfg->submitbutton('btn_file_head', 'Read configuration', ['class' => 'btn btn-sm btn-outline-secondary']);
				$fw_cfg->end_form();
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</details>';
	}

	// Asking the node about its own database, and running one installer.
	$has_schema_probe = JobCommandBuilder::has_primitive($node, 'schema_probe');
	$has_run_installer = JobCommandBuilder::has_primitive($node, 'run_installer');
	$has_page_probe = JobCommandBuilder::has_primitive($node, 'page_probe');
	$has_reclaim = JobCommandBuilder::has_primitive($node, 'reclaim_managed_file');
	if ($has_schema_probe || $has_run_installer || $has_page_probe || $has_reclaim) {
		echo '<details class="mt-2"><summary class="small text-muted" style="cursor:pointer;">Diagnose and repair</summary>';
		echo '<div class="d-flex flex-wrap gap-4 mt-2">';
		if ($has_schema_probe) {
			echo '<div>';
			$fw_sp = $page->getFormWriter('schema_probe_form', ['action' => $base_url . '&tab=overview']);
			$fw_sp->begin_form();
			$fw_sp->hiddeninput('action', '', ['id' => 'schema_probe_action', 'value' => 'schema_probe']);
			$fw_sp->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'schema_probe_csrf', 'value' => SmAdminCsrf::token()]);
			$fw_sp->textinput('table', 'Table', ['placeholder' => 'usr_users', 'maxlength' => 63,
				'helptext' => 'Whether it exists, its columns and indexes, and its row count. No row is read.']);
			$fw_sp->submitbutton('btn_schema_probe', 'Describe table', ['class' => 'btn btn-sm btn-outline-secondary']);
			$fw_sp->end_form();
			echo '</div>';
		}
		if ($has_page_probe) {
			echo '<div>';
			$fw_pp = $page->getFormWriter('page_probe_form', ['action' => $base_url . '&tab=overview', 'values' => ['viewer' => 'admin']]);
			$fw_pp->begin_form();
			$fw_pp->hiddeninput('action', '', ['id' => 'page_probe_action', 'value' => 'page_probe']);
			$fw_pp->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'page_probe_csrf', 'value' => SmAdminCsrf::token()]);
			$fw_pp->textinput('page', 'Page', ['placeholder' => '/admin/admin_users', 'maxlength' => 201,
				'helptext' => 'One of the site\'s own pages. The node renders it as a throwaway viewer and reports status, timing, queries, warnings and structure — never its text.']);
			$fw_pp->dropinput('viewer', 'Viewer', ['options' => JobCommandBuilder::PAGE_PROBE_VIEWERS]);
			$fw_pp->submitbutton('btn_page_probe', 'Probe page', ['class' => 'btn btn-sm btn-outline-secondary']);
			$fw_pp->end_form();
			echo '</div>';
		}
		if ($has_reclaim) {
			echo '<div>';
			$reclaimable = [];
			foreach (JobCommandBuilder::RECLAIM_FILES as $key => $owner) {
				if (!$node->hosts_site() && !in_array($owner, ['host_housekeeping.sh'], true)) { continue; }
				$reclaimable[$key] = JobCommandBuilder::FILE_HEAD_FILES[$key] ?? $key;
			}
			$fw_rc = $page->getFormWriter('reclaim_form', ['action' => $base_url . '&tab=overview']);
			$fw_rc->begin_form();
			$fw_rc->hiddeninput('action', '', ['id' => 'reclaim_action', 'value' => 'reclaim_managed_file']);
			$fw_rc->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'reclaim_csrf', 'value' => SmAdminCsrf::token()]);
			$fw_rc->dropinput('file', 'Reset a host file', ['options' => $reclaimable,
				'helptext' => 'Moves the file aside to a dated copy on the node and runs the installer that owns it. Read it first (Logs, Configuration file): a hand edit may be deliberate.']);
			$fw_rc->submitbutton('btn_reclaim', 'Reset to the platform\'s version', ['class' => 'btn btn-sm btn-outline-secondary']);
			$fw_rc->end_form();
			echo '</div>';
		}
		if ($has_run_installer) {
			echo '<div>';
			$installers = JobCommandBuilder::RUN_INSTALLER_CORE;
			if (!$node->hosts_site()) {
				$installers = array_intersect_key($installers, array_flip(['host_housekeeping.sh', 'install_host_converger.sh']));
			}
			$fw_ri = $page->getFormWriter('run_installer_form', ['action' => $base_url . '&tab=overview']);
			$fw_ri->begin_form();
			$fw_ri->hiddeninput('action', '', ['id' => 'run_installer_action', 'value' => 'run_installer']);
			$fw_ri->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'run_installer_csrf', 'value' => SmAdminCsrf::token()]);
			$fw_ri->dropinput('name', 'Installer', ['options' => $installers,
				'helptext' => 'Runs as root through the host runner. Idempotent: the host timer runs every one daily.']);
			$fw_ri->submitbutton('btn_run_installer', 'Run installer', ['class' => 'btn btn-sm btn-outline-secondary']);
			$fw_ri->end_form();
			echo '</div>';
		}
		echo '</div></details>';
	}

	echo '</div>'; // end the node at a glance

	// Install state banner (takes precedence over regular status)
	$install_state = $node->get('mgn_install_state');
	if ($install_state === 'installing') {
		echo '<div class="alert alert-info"><div><strong>Install in progress.</strong> The install job is running against this node. ';
		$install_job = ManagementJob::latestForNode($node->key, 'install_node');
		if ($install_job) {
			echo '<a href="/admin/server_manager/job_detail?job_id=' . $install_job->key . '">View job #' . $install_job->key . '</a>';
		}
		echo '</div></div>';
	} elseif ($install_state === 'install_failed') {
		echo '<div class="alert alert-danger"><div><strong>Install failed.</strong> The last install attempt did not complete.';
		$install_job = ManagementJob::latestForNode($node->key, 'install_node');
		if ($install_job) {
			echo ' <a href="/admin/server_manager/job_detail?job_id=' . $install_job->key . '" class="alert-link">View job #' . $install_job->key . ' output</a>.';
		}
		echo '<div class="mt-2"><form method="post" class="svm-inline-form" id="retry_install_form">';
		echo '<input type="hidden" name="action" value="retry_install">';
		echo SmAdminCsrf::field();
		echo '<button type="button" class="btn btn-sm btn-warning" onclick="JoineryModal.confirm(\'Before retrying: SSH to the target and remove any partial install (e.g. rm -rf /var/www/html/SITENAME, drop the DB). install.sh will refuse if the site directory already exists. Continue?\', function(){ document.getElementById(\'retry_install_form\').submit(); })">Retry Install</button></form></div>';
		echo '</div></div>';
	} elseif ($install_state === 'copy') {
		echo '<div class="alert alert-info"><div><strong>' . htmlspecialchars($node->install_state_label()) . '.</strong> '
			. 'This server holds a copy of another node\'s site. It is quiet: it serves no visitors, sends nothing and runs no scheduled task, '
			. 'and no backup, upgrade or uptime check runs against it. It is refreshed and discarded from the '
			. '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$node->get('mgn_copy_of_node_id')
			. '&tab=copy" class="alert-link">Copy tab of the site it copies</a>.</div></div>';
	} elseif ($install_state === 'switching') {
		echo '<div class="alert alert-warning"><div><strong>' . htmlspecialchars($node->install_state_label()) . '.</strong> '
			. 'This site is frozen while it moves to its copy. Visitors see a maintenance page until the switch-over finishes or is undone.</div></div>';
	} elseif ($install_state === 'retired') {
		echo '<div class="alert alert-secondary"><div><strong>' . htmlspecialchars($node->install_state_label()) . '.</strong> '
			. 'This is the old server of a site that has moved. It is kept, quiet, for the way back. Removing it from the dashboard '
			. 'does not delete the server; delete that at its provider.</div></div>';
	} elseif ($install_state === 'held') {
		// Stopped on purpose on its host (multi_tenant_docker_hosts WP7). Who,
		// when and why come from the job that asked; it is started again from
		// the host's own page, whose agent holds it.
		$held_host = null;
		if ((int)$node->get('mgn_mgh_managed_host_id')) {
			try {
				$held_host = (new ManagedHost((int)$node->get('mgn_mgh_managed_host_id'), TRUE))->host_node();
			} catch (Exception $e) {
				$held_host = null;
			}
		}
		$held_words = $held_host ? ManagementJob::hold_words((int)$held_host->key, trim((string)$node->get('mgn_container_name'))) : '';
		echo '<div class="alert alert-secondary"><div><strong>' . htmlspecialchars($node->install_state_label()) . '.</strong> '
			. 'This site\'s container is stopped on its server, on purpose, and stays stopped through reboots until it is started again. '
			. 'Its data is kept. Nothing watches, backs up or upgrades it meanwhile, and no incident is raised for it.'
			. ($held_words !== '' ? ' ' . htmlspecialchars($held_words) : '')
			. ($held_host
				? ' Start it from <a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$held_host->key . '" class="alert-link">'
					. htmlspecialchars((string)$held_host->get('mgn_name')) . '</a>, under Site containers.'
				: '')
			. '</div></div>';
	}


	// ── What needs a person, right under the header ──
	$monitor_health = NodeMonitorHealth::evaluate($node);
	if ($monitor_health['is_problem']) {
		echo '<div class="alert alert-warning" role="alert"><div>';
		echo '<strong>' . htmlspecialchars($monitor_health['label']) . ':</strong> ';
		echo htmlspecialchars($monitor_health['detail']);
		echo ' <a href="' . $base_url . '&tab=overview&edit=1#connectionSettings">Fix in settings</a>';
		echo '</div></div>';
	}
	// The same rule as the header line: incidents a person still owes something.
	$node_incidents = IncidentRecord::needs_you_counts((int)$node->key);
	if ($node_incidents['needs_you'] > 0) {
		$n = $node_incidents['needs_you'];
		echo '<div class="alert ' . ($node_incidents['critical'] > 0 ? 'alert-danger' : 'alert-warning') . '"><div><strong>'
			. $n . ' incident' . ($n === 1 ? ' needs' : 's need') . ' you'
			. ($node_incidents['active'] > 0 ? ', ' . $node_incidents['active'] . ' still happening' : '') . '.</strong> '
			. '<a href="#node-incidents" class="alert-link">See Incidents below</a>.</div></div>';
	}

	// ── Health at a glance ──
	// Bars for everything that is a share of a capacity, coloured by one rule
	// (green under 75%, amber 75-90%, red over 90%; the marks on each bar sit at
	// 75% and 90%), and one plain sentence per service. Figures come from the
	// host report where the node sent one (bytes, the node's own free space),
	// else from the status check.
	$gauge_class = function ($pct) {
		return $pct > 90 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-success');
	};
	$gauge = function ($label, $pct, $class, $big, $line, $extra = '') {
		echo '<div><div class="border rounded p-3 h-100">';
		echo '<div class="d-flex justify-content-between align-items-baseline gap-2">'
			. '<span class="text-muted small text-uppercase">' . $label . '</span>'
			. '<span class="fs-5 fw-semibold">' . $big . '</span></div>';
		if ($pct !== null) {
			echo '<div class="svm-gauge mt-2" title="Green under 75%, amber 75 to 90%, red over 90%">'
				. '<div class="svm-gauge-fill ' . $class . '" style="--svm-pct:' . max(0, min(100, (int)$pct)) . '%"></div></div>';
		}
		echo '<div class="text-muted small mt-2">' . $line . '</div>' . $extra;
		echo '</div></div>';
	};
	$size = function ($bytes) { return htmlspecialchars(JobResultProcessor::format_size((int)$bytes)); };
	$have = function ($g) { return is_array($g) && is_int($g['used_bytes'] ?? null) && is_int($g['total_bytes'] ?? null); };
	// A site container's own figures from its server's host report (host_report
	// 1.7): memory in use against its limit and its peak since it started, CPU
	// and bytes sent since the report before, the disk its volumes hold, and how
	// many times the kernel killed a process in it for memory, amber above zero.
	$site_figures = function ($c) use ($size, $hr_when) {
		if (!is_array($c) || !isset($c['memory'])) { return ''; }
		$m = $c['memory'];
		$parts = [];
		if (is_int($m['used_bytes'])) {
			$limit = $m['limit_bytes'] === 'none' ? 'no limit' : (is_int($m['limit_bytes']) ? $size($m['limit_bytes']) . ' limit' : 'limit unknown');
			$parts[] = 'Memory ' . $size($m['used_bytes']) . ' (' . $limit . ')';
		}
		if (is_int($m['peak_bytes'])) {
			$parts[] = 'peak ' . $size($m['peak_bytes']) . (is_int($c['started_at']) ? ' since it started ' . htmlspecialchars($hr_when($c['started_at'])) : '');
		}
		$since = $c['since_last'] ?? null;
		if (is_array($since)) {
			$mins = max(1, (int)round($since['seconds'] / 60));
			$parts[] = 'CPU ' . round($since['cpu_millicores'] / 10, 1) . '% of a core and ' . $size($since['net_tx_bytes'])
				. ' sent over the last ' . ($mins >= 120 ? round($mins / 60) . ' hours' : $mins . ' min');
		} else {
			$parts[] = 'CPU and traffic arrive with the next report';
		}
		if (is_int($c['disk_bytes'])) {
			$parts[] = 'Disk ' . $size($c['disk_bytes']);
		}
		if (is_int($c['pids']['current'] ?? null)) {
			$parts[] = (int)$c['pids']['current'] . ' processes' . (is_int($c['pids']['limit']) ? ' of ' . (int)$c['pids']['limit'] : '');
		}
		$html = '<div class="small text-muted">' . implode(' · ', $parts) . '</div>';
		if (is_int($m['oom_kills']) && $m['oom_kills'] > 0) {
			$html .= '<div class="mt-1"><span class="badge bg-warning" title="Times the kernel killed a process in this site for running out of memory, since it started">'
				. (int)$m['oom_kills'] . ' killed for memory</span></div>';
		}
		// Its outbound limits (host_report 1.11): connections opened past the
		// rate or the cap, and UDP, dropped. Real use never meets them, so any
		// drop is worth a look: it is how scanning and flooding show.
		$dropped = $since['outbound_dropped'] ?? null;
		if (is_int($dropped) && $dropped > 0) {
			$html .= '<div class="mt-1"><span class="badge bg-warning" title="Packets this site sent past its outbound limits (new connections a second, connections open at once, any UDP), dropped on its server. Real use never meets them.">'
				. $dropped . ' outbound packets dropped since the last report</span></div>';
		} elseif (($c['outbound_dropped'] ?? null) === 'none' && ($c['state'] ?? '') === 'running') {
			$html .= '<div class="small text-muted mt-1">Not under the outbound limits</div>';
		}
		return $html;
	};

	if ($status_data || $hr) {
		$page->begin_box(['title' => 'Health']);
		echo '<div class="svm-grid">';

		// Disk: free space is the node's own avail, not total minus used (the
		// root reserve is exactly what is not there when a disk fills).
		if ($hr && $have($hr['disk']) && $hr['disk']['total_bytes'] > 0) {
			$d = $hr['disk'];
			$pct = (int)round($d['used_bytes'] * 100 / $d['total_bytes']);
			$free = is_int($d['avail_bytes']) ? $d['avail_bytes'] : $d['total_bytes'] - $d['used_bytes'];
			$class = ($free < $d['total_bytes'] * 0.10) ? 'bg-danger' : $gauge_class($pct);
			$extra = '';
			if (is_int($d['inodes_used_pct'])) {
				$extra .= '<div class="small mt-2 ' . ($d['inodes_used_pct'] >= 90 ? 'text-danger' : 'text-muted') . '">Inodes: '
					. (int)$d['inodes_used_pct'] . '% used</div>';
			}
			if (JobCommandBuilder::has_primitive($node, 'disk_usage')) {
				$extra .= '<div class="mt-2"><button type="submit" form="nodeActionDiskUsage" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075"'
					. ' title="The biggest directories in the site tree and the usual machine directories, sizes only">What is using it?</button></div>';
			}
			$gauge('Disk', $pct, $class, $pct . '%', $size($d['used_bytes']) . ' of ' . $size($d['total_bytes']) . ' used · <strong>' . $size($free) . ' free</strong>', $extra);
		} elseif (isset($status_data['disk_usage_percent'])) {
			$pct = (int)$status_data['disk_usage_percent'];
			$line = !empty($status_data['disk_total'])
				? htmlspecialchars($status_data['disk_used'] . ' of ' . $status_data['disk_total'] . ' used · ' . ($status_data['disk_available'] ?? '?') . ' free') : '';
			$gauge('Disk', $pct, $gauge_class($pct), $pct . '%', $line);
		}

		// Memory
		if ($hr && $have($hr['memory']) && $hr['memory']['total_bytes'] > 0) {
			$m = $hr['memory'];
			$pct = (int)round($m['used_bytes'] * 100 / $m['total_bytes']);
			$gauge('Memory', $pct, $gauge_class($pct), $pct . '%', $size($m['used_bytes']) . ' of ' . $size($m['total_bytes']) . ' used');
		} elseif (isset($status_data['memory_used_mb'], $status_data['memory_total_mb']) && $status_data['memory_total_mb'] > 0) {
			$pct = (int)round($status_data['memory_used_mb'] * 100 / $status_data['memory_total_mb']);
			$gauge('Memory', $pct, $gauge_class($pct), $pct . '%', (int)$status_data['memory_used_mb'] . ' of ' . (int)$status_data['memory_total_mb'] . ' MB used');
		}

		// Swap
		$sw_used = null; $sw_total = null;
		if ($hr && $have($hr['swap'])) {
			$sw_used = $hr['swap']['used_bytes']; $sw_total = $hr['swap']['total_bytes'];
		} elseif (isset($status_data['swap_total_mb'])) {
			$sw_used = (int)($status_data['swap_used_mb'] ?? 0) * 1048576; $sw_total = (int)$status_data['swap_total_mb'] * 1048576;
		}
		if ($sw_total !== null) {
			if ($sw_total > 0) {
				$pct = (int)round($sw_used * 100 / $sw_total);
				$gauge('Swap', $pct, $gauge_class($pct), $pct . '%', $size($sw_used) . ' of ' . $size($sw_total) . ' used');
			} else {
				$gauge('Swap', null, '', '<span class="text-muted fs-6">none</span>', 'This machine has no swap.');
			}
		}

		// Load, read against the processors that share it.
		if (isset($status_data['load_1m'])) {
			$l1 = (float)$status_data['load_1m'];
			$loads = htmlspecialchars(($status_data['load_1m'] ?? '-') . ' · ' . ($status_data['load_5m'] ?? '-') . ' · ' . ($status_data['load_15m'] ?? '-'))
				. ' <span class="text-muted">(1, 5, 15 min)</span>';
			$cpus = ($hr && is_int($hr['cpus'] ?? null) && $hr['cpus'] > 0) ? $hr['cpus'] : null;
			if ($cpus) {
				$pct = (int)round($l1 * 100 / $cpus);
				$gauge('Load', $pct, $gauge_class($pct), $pct . '%', $loads . '<br>on ' . $cpus . ' processor' . ($cpus === 1 ? '' : 's'));
			} else {
				$gauge('Load', null, '', htmlspecialchars((string)$status_data['load_1m']), $loads
					. '<br>Its processor count arrives with the node\'s next release, and with it a bar.');
			}
		}
		// A site on a shared server: its own line from the server's host report,
		// since a container's own figures cannot see its limit's kills or its disk.
		$cname = trim((string)$node->get('mgn_container_name'));
		$on_host = null;
		$host_node = null;
		if ($cname !== '' && (int)$node->get('mgn_mgh_managed_host_id')) {
			try {
				$host_node = (new ManagedHost((int)$node->get('mgn_mgh_managed_host_id'), TRUE))->host_node();
			} catch (Exception $e) {
				$host_node = null;
			}
			if ($host_node && (int)$host_node->key !== (int)$node->key) {
				$host_hr = json_decode((string)$host_node->get('mgn_last_host_report'), true);
				$host_hr = is_array($host_hr) ? JobResultProcessor::sanitise_host_report($host_hr) : null;
				foreach ((is_array($host_hr['containers'] ?? null) ? $host_hr['containers'] : []) as $c) {
					if ($c['name'] === $cname) { $on_host = $c; break; }
				}
			}
		}
		if ($on_host && isset($on_host['memory'])) {
			$m = $on_host['memory'];
			$pct = (is_int($m['used_bytes']) && is_int($m['limit_bytes']) && $m['limit_bytes'] > 0)
				? (int)round($m['used_bytes'] * 100 / $m['limit_bytes']) : null;
			$big = is_int($m['used_bytes']) ? $size($m['used_bytes']) : 'unknown';
			$gauge('On its server', $pct, $pct === null ? '' : $gauge_class($pct), $big, $site_figures($on_host)
				. '<div class="small text-muted mt-1">From <a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$host_node->key . '">'
				. htmlspecialchars((string)$host_node->get('mgn_name')) . '</a>\'s report.</div>');
		}
		// The machine's outbound transfer this month, as its provider counts it
		// (MachineTransferWatch, once a day). Shared by every site on the server.
		$mtr = (int)$node->get('mgn_mtr_machine_transfer_id') ? new MachineTransfer((int)$node->get('mgn_mtr_machine_transfer_id'), TRUE) : null;
		if ($mtr && $mtr->key) {
			$quota = (float)$mtr->get('mtr_quota_gb');
			$current = (string)$mtr->get('mtr_period') === gmdate('Y-m');
			$used_gb = $current ? $mtr->used_gb() : 0.0;
			$pct = ($current && $quota > 0) ? (int)round($used_gb * 100 / $quota) : null;
			$sharing = (int)(new MultiManagedNode(array('mgn_mtr_machine_transfer_id' => (int)$mtr->key, 'deleted' => false)))->count_all();
			$line = $current
				? 'of ' . number_format($quota) . ' GB this month, as ' . htmlspecialchars(ucfirst((string)$mtr->get('mtr_provider')))
					. ' counts it, on <span title="' . htmlspecialchars($mtr->get('mtr_provider') . ' ' . $mtr->get('mtr_instance_id')) . '">'
					. htmlspecialchars((string)$mtr->get('mtr_label')) . '</span>'
					. ($sharing > 1 ? ', shared by the ' . $sharing . ' nodes on it' : '')
				: 'Not read yet this month.';
			$line .= '<br>Read ' . htmlspecialchars(LibraryFunctions::time_ago((string)$mtr->get('mtr_read_time'), $session->get_timezone()))
				. '. Same-data-center IPv6 is free and not counted.';
			$extra = trim((string)$mtr->get('mtr_error')) !== ''
				? '<div class="small text-danger mt-1">The last read failed: ' . htmlspecialchars((string)$mtr->get('mtr_error')) . '</div>' : '';
			$big = $current ? htmlspecialchars(number_format($used_gb, $used_gb >= 100 ? 0 : 1)) . ' GB' : '—';
			$gauge('Transfer', $pct, $pct === null ? '' : $gauge_class($pct), $big, $line, $extra);
		}
		echo '</div>';

		echo '<div class="svm-grid mt-3">';

		// Services: running or not, one word each.
		$svc_names = array('apache2' => 'Web server', 'php-fpm' => 'PHP', 'postgresql' => 'Database',
			'cron' => 'Scheduled tasks', 'fail2ban' => 'fail2ban');
		// Running or not, as systemd says. Whether a service also answers is the
		// node's own business: its service_health recipe restarts one that does
		// not and opens a case if that fails, so it reaches this page as a case.
		$svc_status = function ($state) {
			if ($state === 'active')                          { return array('Running', 'success'); }
			if ($state === 'failed' || $state === 'inactive') { return array('Not running', 'danger'); }
			if ($state === 'absent')                          { return array('Not on this machine', 'secondary'); }
			return array('Unknown', 'secondary');
		};
		$units_known = $hr && count(array_filter($hr['expected_units'], function ($st) { return $st !== 'unknown'; })) > 0;
		// A quiet site (a dormant copy, a frozen source) runs none of its
		// scheduled tasks, and the last-run time in its database is whatever
		// was there before it went quiet: on a copy, its source's, from before
		// its backup. Neither may read as this machine's tasks running.
		$site_quiet = in_array(trim((string)$node->get('mgn_install_state')), array('copy', 'switching'), true);
		$tasks_held = '<div class="small text-muted">site tasks held while the site is quiet</div>';
		// When the site's tasks last ran, said only as far as it was measured:
		// the time comes from the last status check, so it is told against
		// that check ("ran 1 minute before the last status check"), never
		// against now, where it would age by the hour between checks. More
		// than twenty minutes before the check is late.
		$tasks_ran = function () use ($status_data, $last_check) {
			// The reading's own measurement time where the status carries one.
			$meta = JobResultProcessor::status_meta($status_data);
			$measured = (string)($meta['cron_last_run']['m'] ?? $last_check);
			if (empty($status_data['cron_last_run']) || $measured === '') { return array('', true); }
			$ran = strtotime($status_data['cron_last_run'] . ' UTC');
			$at = strtotime($measured . ' UTC');
			if (!$ran || !$at) { return array('', true); }
			$gap = max(0, $at - $ran);
			if ($gap < 60) {
				$words = 'less than a minute';
			} elseif ($gap < 3600) {
				$n = intdiv($gap, 60); $words = $n . ' minute' . ($n === 1 ? '' : 's');
			} elseif ($gap < 86400) {
				$n = intdiv($gap, 3600); $words = $n . ' hour' . ($n === 1 ? '' : 's');
			} else {
				$n = intdiv($gap, 86400); $words = $n . ' day' . ($n === 1 ? '' : 's');
			}
			return array('site tasks ran ' . $words . ' before the last status check', $gap < 1200);
		};
		if ($hr && !$units_known && trim((string)$node->get('mgn_container_name')) !== '') {
			// A site in a container: its agent sees the container, not the
			// host's services, which the host's own agent reports.
			echo '<div class="svm-span-2"><div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase mb-2">Services</div>';
			echo '<div>This site runs in a container. The machine\'s services are reported by its host\'s own agent';
			$svc_host = null;
			if ((int)$node->get('mgn_mgh_managed_host_id') > 0 && class_exists('ManagedHost')) {
				$mh = new ManagedHost((int)$node->get('mgn_mgh_managed_host_id'), TRUE);
				$svc_host = $mh->key ? $mh->host_node() : null;
			}
			echo $svc_host
				? ': <a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$svc_host->key . '">' . htmlspecialchars((string)$svc_host->get('mgn_name')) . ' (node #' . (int)$svc_host->key . ')</a>.</div>'
				: '.</div>';
			if (!empty($status_data['postgres_status'])) {
				$pg_ok = $status_data['postgres_status'] === 'accepting connections';
				echo '<div class="mt-2">Database <span class="badge bg-' . ($pg_ok ? 'success' : 'danger') . '">' . ($pg_ok ? 'Running' : 'Not running') . '</span></div>';
			}
			if ($site_quiet) {
				echo $tasks_held;
			} else {
				list($ran_words, $ran_ok) = $tasks_ran();
				if ($ran_words !== '') {
					echo '<div class="mt-1 small ' . ($ran_ok ? 'text-muted' : 'text-warning') . '">' . htmlspecialchars(ucfirst($ran_words)) . '</div>';
				}
			}
			echo '</div></div>';
		} elseif ($hr) {
			$can_restart = JobCommandBuilder::has_primitive($node, 'restart_unit');
			echo '<div class="svm-span-2"><div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase mb-2">Services</div>';
			echo '<table class="table table-sm mb-0 align-middle"><tbody>';
			foreach ($hr['expected_units'] as $unit => $state) {
				list($text, $cls) = $svc_status($state);
				$note = '';
				if ($unit === 'cron' && $site_quiet) {
					$note = $tasks_held;
				} elseif ($unit === 'cron') {
					list($ran_words, $ran_ok) = $tasks_ran();
					if ($ran_words !== '') {
						$note = '<div class="small ' . ($ran_ok ? 'text-muted' : 'text-warning') . '">' . htmlspecialchars($ran_words) . '</div>';
					}
				}
				if ($unit === 'postgresql' && !empty($status_data['current_db'])) {
					$note = '<div class="small text-muted">database <code>' . htmlspecialchars($status_data['current_db']) . '</code></div>';
				}
				echo '<tr><td>' . htmlspecialchars($svc_names[$unit] ?? $unit) . ' <span class="text-muted small">' . $hr_str($unit) . '</span></td>';
				echo '<td><span class="badge bg-' . $cls . '">' . htmlspecialchars($text) . '</span>' . $note . '</td><td class="text-end">';
				if ($can_restart && !in_array($state, array('absent', 'unknown'), true) && array_key_exists($unit, JobCommandBuilder::RESTART_UNIT_UNITS)) {
					$form_id = 'nodeActionRestartUnit_' . $unit;
					$confirm = 'Restart ' . JobCommandBuilder::RESTART_UNIT_UNITS[$unit] . ' on this machine now? '
						. 'Connections it holds are dropped; nothing stored is lost.';
					echo '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075"'
						. ' onclick="' . $hr_str('JoineryModal.confirm(' . json_encode($confirm) . ', function(){ document.getElementById('
							. json_encode($form_id) . ').submit(); })') . '">Restart</button>';
					echo '<form id="' . $hr_str($form_id) . '" method="post" action="'
						. htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8') . '" hidden>'
						. '<input type="hidden" name="action" value="restart_unit">'
						. '<input type="hidden" name="unit" value="' . $hr_str($unit) . '">'
						. SmAdminCsrf::field() . '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			// A Docker host's site containers, each with a Restart.
			$containers = $hr['containers'] ?? null;
			if (is_array($containers) && $containers) {
				$can_restart_c = JobCommandBuilder::has_primitive($node, 'restart_container');
				$can_hold_c = JobCommandBuilder::has_primitive($node, 'hold_container');
				echo '<div class="text-muted small text-uppercase mt-3 mb-1">Site containers</div>';
				echo '<table class="table table-sm mb-0 align-middle"><tbody>';
				// A small posted form for one container, behind the system modal.
				$container_form = function ($form_id, $fields) use ($hr_str, $base_url) {
					$out = '<form id="' . $hr_str($form_id) . '" method="post" action="'
						. htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8') . '" hidden>';
					foreach ($fields as $k => $v) {
						$out .= '<input type="hidden" name="' . $hr_str($k) . '" value="' . $hr_str($v) . '">';
					}
					return $out . SmAdminCsrf::field() . '</form>';
				};
				foreach ($containers as $c) {
					$c_held = !empty($c['held']);
					$held_words = '';
					if ($c_held) {
						// Stopped on purpose, and kept stopped: no restart by Docker
						// or container_health, and nothing on this plane watches it.
						$ctext = 'Held stopped'; $ccls = 'secondary';
						$held_words = ManagementJob::hold_words((int)$node->key, (string)$c['name']);
						$held_words = '<div class="small text-muted">'
							. htmlspecialchars($held_words !== '' ? $held_words : 'Held on the server itself; no hold from here is on record.') . '</div>';
					} elseif ($c['state'] !== 'running') {
						$ctext = 'Not running'; $ccls = 'danger';
					} else {
						$ctext = 'Running'; $ccls = 'success';
					}
					echo '<tr><td>' . $hr_str($c['name']) . $site_figures($c) . '</td><td><span class="badge bg-' . $ccls . '">' . $hr_str($ctext) . '</span>' . $held_words . '</td><td class="text-end">';
					if ($can_hold_c && $c_held) {
						$form_id = 'nodeActionStartContainer_' . $c['name'];
						$confirm = 'Start ' . $c['name'] . ' again? It is a working site from then on: Docker restarts it, and it is watched, backed up and upgraded like any other.';
						echo '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075"'
							. ' onclick="' . $hr_str('JoineryModal.confirm(' . json_encode($confirm) . ', function(){ document.getElementById('
								. json_encode($form_id) . ').submit(); })') . '">Start</button>';
						echo $container_form($form_id, ['action' => 'hold_container', 'op' => 'start', 'name' => $c['name']]);
					} elseif ($can_hold_c && $c['state'] === 'running') {
						$form_id = 'nodeActionHoldContainer_' . $c['name'];
						$ask = 'Hold ' . $c['name'] . ' stopped? The site goes down and stays down, through reboots, until it is started here; '
							. 'its data and volumes are kept. Nothing watches, backs up or upgrades it meanwhile. Why is it held? (shown on its page)';
						echo '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075 me-1"'
							. ' onclick="' . $hr_str('JoineryModal.prompt(' . json_encode($ask) . ', function(why){ var f = document.getElementById('
								. json_encode($form_id) . '); f.elements.reason.value = why; f.submit(); }, {required: true, confirmLabel: ' . json_encode('Hold stopped') . ', confirmStyle: ' . json_encode('danger') . '})') . '">Hold stopped</button>';
						echo $container_form($form_id, ['action' => 'hold_container', 'op' => 'stop', 'name' => $c['name'], 'reason' => '']);
					}
					if ($can_restart_c && !$c_held) {
						$form_id = 'nodeActionRestartContainer_' . $c['name'];
						$confirm = 'Restart the container ' . $c['name'] . '? The site is down while it restarts; its data and volumes are kept.';
						echo '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075"'
							. ' onclick="' . $hr_str('JoineryModal.confirm(' . json_encode($confirm) . ', function(){ document.getElementById('
								. json_encode($form_id) . ').submit(); })') . '">Restart</button>';
						echo '<form id="' . $hr_str($form_id) . '" method="post" action="'
							. htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8') . '" hidden>'
							. '<input type="hidden" name="action" value="restart_container">'
							. '<input type="hidden" name="name" value="' . $hr_str($c['name']) . '">'
							. SmAdminCsrf::field() . '</form>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div></div>';
		} elseif (!empty($status_data['postgres_status']) || !empty($status_data['cron_last_run'])) {
			// No host report: what the status check says about the two it sees.
			echo '<div class="svm-span-2"><div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase mb-2">Services</div>';
			if (!empty($status_data['postgres_status'])) {
				$pg_ok = $status_data['postgres_status'] === 'accepting connections';
				echo '<div>Database <span class="badge bg-' . ($pg_ok ? 'success' : 'danger') . '">' . ($pg_ok ? 'Running' : 'Not running') . '</span></div>';
			}
			if ($site_quiet) {
				echo '<div class="mt-1">Scheduled tasks</div>' . $tasks_held;
			} else {
				list($ran_words, $ran_ok) = $tasks_ran();
				if ($ran_words !== '') {
					echo '<div class="mt-1">Scheduled tasks <span class="badge bg-' . ($ran_ok ? 'success' : 'warning') . '">'
						. ($ran_ok ? 'Running' : 'Not running') . '</span></div>'
						. '<div class="small text-muted">' . htmlspecialchars($ran_words) . '</div>';
				}
			}
			echo '<div class="small text-muted mt-2">' . (JobCommandBuilder::has_primitive($node, 'host_report')
				? 'The rest arrives with the node\'s first host report.'
				: htmlspecialchars(AgentVocabulary::needs_newer_agent_text($node, ['host_report']))) . '</div>';
			echo '</div></div>';
		}

		// Certificates: what each of the site's names serves, read on the machine
		// (the node's certificate_expiry recipe renews one under 14 days).
		$cert_rows = array();
		if ($hr && is_array($hr['served_certificates'] ?? null)) {
			foreach ($hr['served_certificates'] as $c) {
				$cert_rows[] = array($c['domain'], (int)$c['days_left']);
			}
		}
		$cert_expiry = $node->get('mgn_cert_expiry_ts');
		if (!$cert_rows && $cert_expiry) {
			$cert_rows[] = array(parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST) ?: (string)$node->get('mgn_host'),
				(int)floor((strtotime($cert_expiry . ' UTC') - time()) / 86400));
		}
		if (!$cert_rows && !empty($status_data['ssl_expiry_ts'])) {
			$cert_rows[] = array(parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST) ?: 'this site',
				(int)(($status_data['ssl_expiry_ts'] - time()) / 86400));
		}
		$ssl_state = $node->get('mgn_ssl_state');
		if ($cert_rows || $ssl_state !== null) {
			echo '<div><div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase mb-2">Certificates</div>';
			foreach ($cert_rows as $row) {
				$days = $row[1];
				$cls = $days < 14 ? 'danger' : ($days <= 30 ? 'warning' : 'success');
				echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1"><span class="small">' . htmlspecialchars($row[0]) . '</span>'
					. '<span class="badge bg-' . $cls . '" style="white-space:nowrap">' . ($days < 0 ? 'expired' : $days . ' days') . '</span></div>';
			}
			if (!$cert_rows) {
				$ssl_words = array('pending' => array('Waiting for DNS and certbot', 'warning'), 'failed' => array('Failed: see SSL Setup below', 'danger'),
					'active' => array('Active', 'success'));
				$sw = $ssl_words[$ssl_state] ?? array('None configured', 'secondary');
				echo '<span class="badge bg-' . $sw[1] . '">' . htmlspecialchars($sw[0]) . '</span>';
			}
			echo '<div class="small text-muted mt-2">Green over 30 days, amber 14 to 30, red under 14 (the node renews at 14).</div>';
			echo '</div></div>';
		}

		// The machine: what wants a person's eye, and when it last updated itself.
		// A container site's agent cannot see its host; the host's node says it.
		if ($hr && ($units_known || trim((string)$node->get('mgn_container_name')) === '')) {
			echo '<div><div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase mb-2">Machine</div>';
			if ($hr['reboot_required'] === true) {
				// A report from before host_report 1.8 says no time, and reads as overdue.
				$reboot_since = $hr['reboot_required_since'] ?? 'unknown';
				$reboot_overdue = !is_int($reboot_since) || $reboot_since < time() - 86400;
				echo '<div><span class="badge bg-' . ($reboot_overdue ? 'warning' : 'secondary') . '">Reboot required</span>'
					. (is_int($reboot_since) ? ' <span class="small text-muted">asked for ' . $hr_str($hr_when($reboot_since)) . '</span>' : '')
					. '</div>';
			} elseif ($hr['reboot_required'] === false) {
				echo '<div><span class="badge bg-success">No reboot pending</span></div>';
			}
			if ($hr['failed_units'] === 'unknown') {
				echo '<div class="mt-2 text-muted small">Failed units: unknown</div>';
			} elseif (count($hr['failed_units']) === 0) {
				echo '<div class="mt-2"><span class="badge bg-success">No failed units</span></div>';
			} else {
				// A failed unit the plane can name and could not ask about was
				// the whole reason unit_journal exists. The buttons are offered
				// for a unit on the compiled list; anything else is named
				// without one, because the node would refuse it.
				$can_ask = JobCommandBuilder::has_primitive($node, 'unit_journal')
					&& JobCommandBuilder::log_access_refusal($node) === null;
				// Clear needs no log access: it reads nothing.
				$can_clear = JobCommandBuilder::has_primitive($node, 'reset_failed_unit');
				echo '<div class="mt-2 small text-uppercase text-danger">Failed units</div><ul class="list-unstyled mb-0 text-danger">';
				foreach ($hr['failed_units'] as $unit) {
					echo '<li>' . $hr_str($unit);
					$bare = preg_replace('/\.service$/', '', (string)$unit);
					if ($can_ask && array_key_exists($bare, JobCommandBuilder::UNIT_JOURNAL_UNITS)) {
						echo ' <button type="submit" form="nodeActionUnitJournal_' . $hr_str($bare)
							. '" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075"'
							. ' title="Read this unit\'s state, its exit status and the last 100 lines of its journal, redacted on the node">Why?</button>';
						echo '<form id="nodeActionUnitJournal_' . $hr_str($bare) . '" method="post" action="'
							. htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8') . '" hidden>'
							. '<input type="hidden" name="action" value="unit_journal">'
							. '<input type="hidden" name="unit" value="' . $hr_str($bare) . '">'
							. '<input type="hidden" name="lines" value="100">'
							. SmAdminCsrf::field() . '</form>';
					}
					if ($can_clear && array_key_exists($bare, JobCommandBuilder::UNIT_JOURNAL_UNITS)) {
						$form_id = 'nodeActionResetUnit_' . $bare;
						$confirm = 'Clear the failed record for ' . $bare . '? This clears the record only: it does not start, '
							. 'stop or fix the unit. If the unit is still broken it fails again the next time it runs, '
							. 'and this page names it again.';
						echo ' <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075"'
							. ' title="Clear systemd\'s record that this unit failed"'
							. ' onclick="' . $hr_str('JoineryModal.confirm(' . json_encode($confirm) . ', function(){ document.getElementById('
								. json_encode($form_id) . ').submit(); })') . '">Clear</button>';
						echo '<form id="' . $hr_str($form_id) . '" method="post" action="'
							. htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8') . '" hidden>'
							. '<input type="hidden" name="action" value="reset_failed_unit">'
							. '<input type="hidden" name="unit" value="' . $hr_str($bare) . '">'
							. SmAdminCsrf::field() . '</form>';
					}
					echo '</li>';
				}
				echo '</ul>';
				if (count($hr['failed_units']) >= JobResultProcessor::HOST_REPORT_MAX_LIST) {
					echo '<small class="text-muted">first ' . (int)JobResultProcessor::HOST_REPORT_MAX_LIST . ' only</small>';
				}
			}
			// The three kernel events that explain a write that failed: counts only.
			if (is_array($hr['kernel_events_24h'])) {
				$bits = array();
				foreach (array('oom' => 'out of memory', 'enospc' => 'disk full', 'io_error' => 'I/O errors') as $k => $word) {
					if (is_int($hr['kernel_events_24h'][$k]) && $hr['kernel_events_24h'][$k] > 0) { $bits[] = $hr['kernel_events_24h'][$k] . ' ' . $word; }
				}
				echo $bits
					? '<div class="mt-2 text-danger small">Kernel, last 24h: ' . $hr_str(implode(', ', $bits)) . '</div>'
					: '<div class="mt-2 text-muted small">Kernel, last 24h: nothing to report</div>';
			}
			// What the machine's sites may open toward the outside, and how fast they send (host_report
			// 1.11). A container site's own report says none: its host's says.
			$ol = $hr['outbound_limits'] ?? null;
			if (is_array($ol) && $ol['state'] !== 'none') {
				$ol_words = [
					'on'      => ['Outbound limits on', 'success', 'A rate on new connections, a cap on those open at once, no UDP, and a speed ceiling, for each site'],
					'off'     => ['Outbound limits off', 'secondary', 'Turned off on this machine (joinery-limits on turns them on)'],
					'refused' => ['Outbound limits not in force', 'danger', ''],
					'absent'  => ['No outbound limits', 'secondary', 'This machine was installed before them'],
					'unknown' => ['Outbound limits unknown', 'secondary', ''],
				];
				$ol_reasons = [
					'resolver_not_loopback' => 'The machine\'s resolver is not a loopback address, so dropping UDP would break its sites\' name lookups. Point /etc/resolv.conf at the local resolver.',
					'nft_missing'           => 'nftables is not installed.',
					'nft_refused'           => 'nftables refused the rules: see journalctl -u joinery-limits.',
				];
				$w = $ol_words[$ol['state']];
				$ol_line = ($ol['state'] === 'refused') ? ($ol_reasons[$ol['reason']] ?? 'Reason: ' . $ol['reason']) : $w[2];
				echo '<div class="mt-2"><span class="badge bg-' . $w[1] . '">' . $hr_str($w[0]) . '</span></div>';
				if ($ol_line !== '') {
					echo '<div class="small text-muted">' . $hr_str($ol_line) . '</div>';
				}
				if ($ol['state'] === 'on' && ($ol['reason'] ?? null) === 'ceiling_failed') {
					echo '<div class="small text-warning">The speed ceiling is not in force for every site: tc refused part of it. '
						. 'The connection limits are in force; see journalctl -u joinery-limits on the machine.</div>';
				} elseif ($ol['state'] === 'on' && is_string($ol['reason'] ?? null)) {
					echo '<div class="small text-warning">The last change to them was refused ('
						. $hr_str($ol_reasons[$ol['reason']] ?? $ol['reason'])
						. '). The limits before it are in force; see journalctl -u joinery-limits on the machine.</div>';
				}
				$web_dropped = $ol['dropped_since_last'] ?? null;
				if (is_int($web_dropped) && $web_dropped > 0) {
					echo '<div class="mt-1"><span class="badge bg-warning" title="Packets the web server\'s user sent past the outbound limits, dropped. Real use never meets them.">'
						. $web_dropped . ' outbound packets dropped since the last report</span></div>';
				}
			}
			echo '<div class="small text-muted mt-1">Security updates last ran ' . $hr_str($hr_when($hr['unattended_upgrades_last_run'])) . '</div>';
			if (is_array($hr['os'])) {
				$ru = $hr['os']['release_upgrade'];
				if ($ru['offered'] !== 'unknown') {
					$checked = is_int($ru['checked_at']) ? $ru['checked_at'] : null;
					$stale = ($checked === null || $checked < time() - 7 * 86400);
					echo '<div class="small ' . ($ru['offered'] !== 'none' ? 'text-info' : 'text-muted') . '">Release upgrade: '
						. $hr_str($ru['offered'] === 'none' ? 'none offered' : $ru['offered'] . ' offered')
						. ' <span class="' . ($stale ? 'text-warning' : 'text-muted') . '">(checked ' . $hr_str($hr_when($checked))
						. ($stale ? '; Ubuntu re-checks only when someone logs in' : '') . ')</span></div>';
				}
			}
			echo '</div></div>';
		}
		if ($status_data) {
		// Service, for a machine this plane reaches by probing it. The DNS boxes
		// and the mail relay carry no agent and host no site, so what their own
		// health document says about them is the only account of them there is.
		if (isset($status_data['status']) || isset($status_data['port_reachable'])) {
			echo '<div>';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Service</div>';
			if (isset($status_data['port_reachable'])) {
				echo '<div class="mt-1"><span class="badge bg-success">Answering on port '
					. (int)$status_data['port_reachable'] . '</span></div>';
			} else {
				$svc_ok = ($status_data['status'] === 'ok');
				echo '<div class="mt-1"><span class="badge bg-' . ($svc_ok ? 'success' : 'warning') . '">'
					. htmlspecialchars((string)$status_data['status']) . '</span></div>';
			}
			$svc_bits = array();
			if (isset($status_data['source_ok'])) {
				$svc_bits[] = $status_data['source_ok'] ? 'site reachable' : 'site unreachable';
			}
			if (!empty($status_data['service_uptime_seconds'])) {
				$svc_bits[] = 'up ' . NodeMonitorHealth::humanize((int)$status_data['service_uptime_seconds']);
			}
			if (isset($status_data['probe_latency_ms'])) {
				$svc_bits[] = 'answered in ' . (int)$status_data['probe_latency_ms'] . 'ms';
			}
			if ($svc_bits) {
				echo '<div class="text-muted small mt-2">' . htmlspecialchars(implode(' · ', $svc_bits)) . '</div>';
			}
			echo '</div></div>';
		}

		// Sealed-secret health. Counts only ride up in the status blob — never a
		// value. A dead operator credential or a re-mint awaiting acknowledgement
		// is fixed ON the node (the management node holds none of the node's keys),
		// so this links out to the node's own secrets page rather than acting here.
		if (isset($status_data['sealed_secrets']) && is_array($status_data['sealed_secrets'])) {
			$ss = $status_data['sealed_secrets'];
			$ss_attention = (int)($ss['dead_operator'] ?? 0) + (int)($ss['dead_needs_ack'] ?? 0);
			echo '<div>';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Stored Secrets</div>';
			if ($ss_attention > 0) {
				echo '<div class="mt-1"><span class="badge bg-warning">' . (int)$ss_attention . ' unreadable</span></div>';
				$ss_bits = array();
				if (!empty($ss['dead_operator'])) $ss_bits[] = (int)$ss['dead_operator'] . ' need re-entry';
				if (!empty($ss['dead_needs_ack'])) $ss_bits[] = (int)$ss['dead_needs_ack'] . ' need a re-mint OK';
				echo '<div class="text-muted small mt-2">' . htmlspecialchars(implode(' · ', $ss_bits)) . '</div>';
				$ss_site = rtrim((string)$node->get('mgn_site_url'), '/');
				if ($ss_site !== '') {
					echo '<div class="small mt-2"><a href="' . htmlspecialchars($ss_site . '/admin/admin_sealed_secrets')
						. '" target="_blank" rel="noopener">Fix on the node &rarr;</a></div>';
				}
			} else {
				echo '<div class="mt-1"><span class="badge bg-success">All readable</span></div>';
			}
			echo '</div></div>';
		}

		// Plugin checks the node records for fleet reporting (plugin.json
		// fleet_report). One that does not pass fails the node's badge; the
		// reason is the node's own sentence, so it is escaped like everything
		// else the node says.
		if (isset($status_data['plugin_checks']['checks']) && is_array($status_data['plugin_checks']['checks'])) {
			$pc_failing = JobCommandBuilder::plugin_checks_failing($status_data);
			$pc_total = count($status_data['plugin_checks']['checks']);
			echo '<div>';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Plugin Checks</div>';
			if (count($pc_failing)) {
				echo '<div class="mt-1"><span class="badge bg-danger">' . count($pc_failing) . ' not passing</span></div>';
				foreach ($pc_failing as $pc) {
					echo '<div class="small mt-2"><strong>' . htmlspecialchars($pc['plugin'] . ': ' . ($pc['label'] !== '' ? $pc['label'] : $pc['key']))
						. '</strong> (' . htmlspecialchars($pc['state']) . ')';
					if ($pc['reason'] !== '') {
						echo '<div class="text-muted" style="overflow-wrap:anywhere">' . htmlspecialchars($pc['reason']) . '</div>';
					}
					echo '</div>';
				}
			} elseif ($pc_total > 0) {
				echo '<div class="mt-1"><span class="badge bg-success">All ' . (int)$pc_total . ' passing</span></div>';
			} else {
				echo '<div class="mt-1"><span class="badge bg-secondary">None declared</span></div>';
			}
			$pc_checked = (string)($status_data['plugin_checks']['checked'] ?? '');
			if ($pc_checked !== '' && strtotime($pc_checked . ' UTC')) {
				echo '<div class="text-muted small mt-2">Checked on the node ' . htmlspecialchars(LibraryFunctions::time_ago(
					substr($pc_checked, 0, 19), $session->get_timezone())) . '</div>';
			}
			echo '</div></div>';
		}
		}

		echo '</div>'; // end .row

		// When these figures were read, and the one rule every bar follows.
		$read_bits = array();
		if ($last_check) {
			$read_bits[] = 'status check ' . LibraryFunctions::time_ago($last_check, $session->get_timezone());
		}
		if ($hr && $host_report_time !== '') {
			$read_bits[] = 'host report ' . LibraryFunctions::time_ago($host_report_time, $session->get_timezone());
		}
		echo '<div class="small text-muted mt-3">Bars are green under 75%, amber from 75% to 90%, red over 90% (a disk under 10% free is red too); '
			. 'the marks on each bar sit at 75% and 90%.'
			. ($read_bits ? ' Read from the ' . htmlspecialchars(implode(' and the ', $read_bits)) . '.' : '') . '</div>';
		if (!empty($status_data['db_list']) && count($status_data['db_list']) > 1) {
			echo '<div class="text-muted small mt-1"><strong>All databases:</strong> ' . htmlspecialchars(implode(', ', $status_data['db_list'])) . '</div>';
		}
		$page->end_box();
	}

	// ── Agent ──
	// How this management node reaches the machine, which is a different
	// question from whether the machine is well: whether the agent checks in,
	// what it runs, and what it watches on its own clock (its recipes). Keys and
	// pairing are managed on the API Keys tab.
	$agent_key = trim((string)$node->get('mgn_agent_public_key'));
	$recipes = AgentChannelEndpoint::recipes_of($node);
	if ($agent_key !== '' || count($recipes) > 0) {
		$page->begin_box(['title' => 'Agent', 'altlinks' => ['Keys and pairing' => $base_url . '&tab=api_keys']]);
		echo '<div class="svm-grid">';

		// Checking in
		$last_poll = $node->get('mgn_agent_last_poll');
		$quiet_time = $node->get('mgn_agent_quiet_time');
		$switched_off = $quiet_time && (!$last_poll || $last_poll <= $quiet_time);
		$age = $last_poll ? time() - strtotime($last_poll . ' UTC') : null;
		if ($switched_off) {
			$conn = array('Switched off by its owner', 'secondary');
		} elseif ($age === null) {
			$conn = array('Has not checked in yet', 'warning');
		} elseif ($age < 900) {
			$conn = array('Connected', 'success');
		} elseif ($age < 7200) {
			$conn = array('Late to check in', 'warning');
		} else {
			$conn = array('Not checking in', 'danger');
		}
		echo '<div><div class="border rounded p-3 h-100">';
		echo '<div class="text-muted small text-uppercase mb-2">Connection</div>';
		echo '<div><span class="badge bg-' . $conn[1] . '">' . htmlspecialchars($conn[0]) . '</span></div>';
		echo '<div class="small text-muted mt-2">Last check-in: ' . ($last_poll
			? htmlspecialchars(LibraryFunctions::time_ago($last_poll, $session->get_timezone())) : 'never') . '</div>';
		if ($agent_key !== '') {
			$raw = base64_decode($agent_key, true);
			if ($raw !== false) {
				echo '<div class="small text-muted">Key <code>' . htmlspecialchars(AgentJoinRequest::display_fingerprint(AgentJoinRequest::fingerprint($raw))) . '</code></div>';
			}
		}
		$log_access = (string)$node->get('mgn_agent_log_access');
		if ($log_access !== '') {
			echo '<div class="small text-muted">Its owner ' . ($log_access === 'on' ? 'lets' : 'does not let') . ' this management node read its logs.</div>';
		}
		echo '</div></div>';

		// Version
		$av = AgentVocabulary::version($node);
		$newest = AgentVocabulary::newest();
		echo '<div><div class="border rounded p-3 h-100">';
		echo '<div class="text-muted small text-uppercase mb-2">Version</div>';
		echo '<div class="fs-5 fw-semibold">' . ($av !== '' ? htmlspecialchars($av) : '<span class="text-muted fs-6">not reported</span>');
		if (AgentVocabulary::below_floor($node)) {
			echo ' <span class="badge bg-danger">too old: update it</span>';
		} elseif ($av !== '' && $newest && version_compare($av, $newest, '<')) {
			echo ' <span class="badge bg-warning">' . htmlspecialchars($newest) . ' available</span>';
		} elseif ($av !== '' && $newest) {
			echo ' <span class="badge bg-success">current</span>';
		}
		echo '</div>';
		$trust = (string)$node->get('mgn_script_trust');
		if ($trust === 'unpublished_file') {
			echo '<div class="mt-2"><span class="badge bg-warning">A script was changed here after the last publish</span></div>';
			echo '<div class="small text-muted mt-1">The agent will not run it as root until the next publish re-signs this site\'s tree.</div>';
			if ($node->get('mgn_script_trust_reason')) {
				echo '<div class="small text-muted mt-1">' . htmlspecialchars((string)$node->get('mgn_script_trust_reason')) . '</div>';
			}
		} elseif ($trust !== '' && $trust !== 'ok') {
			echo '<div class="mt-2"><span class="badge bg-danger">' . ($trust === 'untrusted_file' ? 'A script on the node does not match its release' : 'Cannot verify its scripts') . '</span></div>';
			if ($node->get('mgn_script_trust_reason')) {
				echo '<div class="small text-muted mt-1">' . htmlspecialchars((string)$node->get('mgn_script_trust_reason')) . '</div>';
			}
		} elseif ($agent_key !== '') {
			echo '<div class="small text-muted mt-2">Its scripts verify against their signed release.</div>';
		}
		echo '</div></div>';

		// Recipes: what the agent checks and repairs on its own clock.
		$verdicts = AgentChannelEndpoint::recipe_verdicts_of($node);
		$recipe_words = array(
			'agent_supervision'  => 'The agent stays running',
			'certificate_expiry' => 'Certificates renew in time',
			'container_health'   => 'Site containers run and answer',
			'disk_headroom'      => 'The disk keeps free space',
			'fail2ban'           => 'fail2ban runs and bans',
			'service_health'     => 'Database, PHP and web server answer',
		);
		$mode_words = array('armed' => 'repairs', 'report-only' => 'reports only', 'not-applicable' => 'not here');
		echo '<div class="svm-span-2"><div class="border rounded p-3 h-100">';
		echo '<div class="text-muted small text-uppercase mb-2">What it watches</div>';
		if (count($recipes) === 0) {
			echo '<div class="small text-muted">Nothing reported. An agent from 1.27.0 checks on its own clock and reports here.</div>';
		} else {
			echo '<table class="table table-sm mb-0 align-middle"><tbody>';
			foreach ($recipes as $name => $mode) {
				$v = $verdicts[$name] ?? '';
				$vcls = $v === 'pass' ? 'success' : ($v === 'fail' ? 'danger' : 'secondary');
				$vtext = $v === 'pass' ? 'OK' : ($v === 'fail' ? 'Failing' : ($mode === 'not-applicable' ? 'n/a' : 'not yet'));
				echo '<tr><td>' . htmlspecialchars($recipe_words[$name] ?? $name) . ' <span class="small text-muted">'
					. htmlspecialchars($mode_words[$mode] ?? $mode) . '</span></td>'
					. '<td class="text-end"><span class="badge bg-' . $vcls . '">' . htmlspecialchars($vtext) . '</span></td></tr>';
			}
			echo '</tbody></table>';
			echo '<div class="small text-muted mt-2">Checked every ten minutes on the node. One that repairs acts after two failing checks in a row; '
				. 'one that keeps failing after three attempts opens a case.</div>';
		}
		echo '</div></div>';

		echo '</div>';
		$page->end_box();
	}

	// ── Security ──
	// Who is knocking, and how the door is set. A container site's agent sees
	// neither; its host's node does.
	if ($hr && !(trim((string)$node->get('mgn_container_name')) !== '' && $hr['fail2ban_jails'] === 'unknown')) {
		$page->begin_box(['title' => 'Security']);
		echo '<div class="svm-grid">';
		echo '<div class="svm-span-2"><div class="border rounded p-3 h-100">';
		echo '<div class="text-muted small text-uppercase mb-2">SSH</div>';
		echo '<div>Failed sign-ins, last 24 hours: <strong>' . $hr_str($hr['ssh_auth_failures_24h']) . '</strong></div>';
		$sshd = $hr['sshd'];
		$pw = $sshd['password_authentication'];
		$rl = $sshd['permit_root_login'];
		echo '<div class="mt-1">Passwords accepted: <span class="badge bg-' . ($pw === 'yes' ? 'warning' : ($pw === 'no' ? 'success' : 'secondary')) . '">' . $hr_str($pw) . '</span></div>';
		echo '<div class="mt-1">Root may sign in: <span class="badge bg-' . ($rl === 'yes' ? 'warning' : 'secondary') . '">' . $hr_str($rl) . '</span></div>';
		// The effective settings a lockout turns on (sshd -T, compiled keys
		// only). Shown only when this node's report carries them.
		$more = array();
		if (isset($sshd['pubkey_authentication'])) { $more[] = 'Keys accepted: ' . $sshd['pubkey_authentication']; }
		if (isset($sshd['kbd_interactive_authentication'])) { $more[] = 'Keyboard-interactive: ' . $sshd['kbd_interactive_authentication']; }
		if (isset($sshd['max_auth_tries'])) { $more[] = 'Tries per connection: ' . (is_scalar($sshd['max_auth_tries']) ? $sshd['max_auth_tries'] : 'unknown'); }
		foreach (array('ports' => 'Port', 'allow_users' => 'Allowed users', 'allow_groups' => 'Allowed groups') as $k => $label) {
			if (!isset($sshd[$k])) { continue; }
			$v = $sshd[$k];
			$more[] = $label . ': ' . (is_array($v) ? ($v ? implode(', ', $v) : 'any') : 'unknown');
		}
		if ($more) {
			echo '<div class="small text-muted mt-2">' . htmlspecialchars(implode(' · ', $more)) . '</div>';
		}
		echo '</div></div>';

		echo '<div class="svm-span-2"><div class="border rounded p-3 h-100">';
		echo '<div class="text-muted small text-uppercase mb-2">fail2ban</div>';
		if ($hr['fail2ban_jails'] === 'unknown') {
			echo '<div class="text-muted">unknown</div>';
		} elseif (count($hr['fail2ban_jails']) === 0) {
			echo '<span class="badge bg-warning">No jails</span>';
		} else {
			$banned = 0;
			foreach ($hr['fail2ban_jails'] as $jail) { $banned += is_int($jail['banned']) ? $jail['banned'] : 0; }
			echo '<div><strong>' . (int)$banned . '</strong> address' . ($banned === 1 ? '' : 'es') . ' banned now, in '
				. count($hr['fail2ban_jails']) . ' jail' . (count($hr['fail2ban_jails']) === 1 ? '' : 's') . '</div>';
			$parts = array();
			foreach ($hr['fail2ban_jails'] as $jail) { $parts[] = $jail['name'] . ' ' . (is_scalar($jail['banned']) ? $jail['banned'] : '?'); }
			echo '<div class="small text-muted mt-2">' . htmlspecialchars(implode(' · ', $parts)) . '</div>';
		}
		echo '</div></div>';
		echo '</div>';
		$page->end_box();
	} elseif (!$hr && JobCommandBuilder::has_agent_channel($node)) {
		$page->begin_box(['title' => 'Security']);
		echo '<p class="text-muted mb-0">This node has not sent a host report yet.';
		if (JobCommandBuilder::has_primitive($node, 'host_report')) {
			echo ' One is queued on the status cadence; Host Report above reads the machine now.';
		} else {
			echo ' ' . htmlspecialchars(AgentVocabulary::needs_newer_agent_text($node, ['host_report']));
		}
		echo '</p>';
		$page->end_box();
	}

	// ── Incidents card ──
	// This node's incidents: every one that needs a person, then the newest
	// of the rest. Each links to its own page, where it is triaged. Whatever a
	// node said is escaped by IncidentViews; nothing it said is a link.
	$node_incident_rows = IncidentViews::for_node((int)$node->key);
	if (JobCommandBuilder::has_agent_channel($node) || count($node_incident_rows) > 0) {
		echo '<div id="node-incidents"></div>';
		$page->begin_box(['title' => 'Incidents', 'altlinks' => ['All of this node\'s incidents' => IncidentViews::LIST_URL . '?view=all&node=' . (int)$node->key]]);
		if (count($node_incident_rows) === 0) {
			echo '<p class="text-muted mb-0">None. An incident opens when a recipe on the node gives up: three attempts in an hour '
				. 'and its check still fails. A check-only recipe opens one as soon as its check fails.</p>';
		} else {
			echo IncidentViews::table($node_incident_rows);
		}
		$page->end_box();
	}

	// ── SSL Setup card ──
	// Skipped when the wire probe (mgn_cert_expiry_ts) has already proven a
	// SAN-matching cert is being kept current by a renewer other than certbot
	// (e.g. Caddy on the DNS nodes) — the "TLS cert: expires..." line above
	// already covers that case, and this card's "Provision SSL" button runs
	// certbot's Apache plugin, which doesn't apply to those nodes at all.
	$ssl_card_state  = $node->get('mgn_ssl_state');
	$ssl_card_domain = parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST);
	$is_fqdn = $ssl_card_domain
		&& !filter_var($ssl_card_domain, FILTER_VALIDATE_IP)
		&& $ssl_card_domain !== 'localhost';

	if ($is_fqdn && $ssl_card_state !== 'active' && $node->is_operational() && !$node->get('mgn_cert_expiry_ts')) {
		$host_ip     = $node->get('mgn_host');
		require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
		try {
			$resolved_ips = DnsResolver::getA($ssl_card_domain);
		} catch (DnsLookupException $e) {
			$resolved_ips = [];
		}
		$dns_resolves    = !empty($resolved_ips);
		$dns_matches     = $dns_resolves && (!$host_ip || in_array($host_ip, $resolved_ips, true));
		$no_host_ip      = !$host_ip;
		$can_provision   = ($dns_matches || $no_host_ip) && $ssl_card_state !== 'pending';

		$pageoptions = ['title' => 'SSL Setup'];
		$page->begin_box($pageoptions);

		// Failed alert with link to the last certificate-chain job
		if ($ssl_card_state === 'failed') {
			$ssl_job = ProvisionPendingSsl::latest_chain_job($node);
			echo '<div class="alert alert-danger mb-3">A previous SSL provisioning attempt failed.';
			if ($ssl_job) {
				echo ' <a href="/admin/server_manager/job_detail?job_id=' . $ssl_job->key . '" class="alert-link">Review the job output →</a>';
			}
			echo '</div>';
		}

		if ($ssl_card_state === 'pending') {
			$ssl_job = ProvisionPendingSsl::latest_chain_job($node);
			$ssl_job_failed = $ssl_job && $ssl_job->get('mjb_status') === 'failed';

			// "Pending" covers two very different situations: a job in flight,
			// and a job that already failed (the hourly-backoff retry hasn't
			// fired yet). The failed one needs to say so and offer an
			// immediate retry — while it waits, the domain may already point
			// here with HTTP redirecting to an HTTPS that cannot answer.
			if ($ssl_job_failed) {
				echo '<div class="alert alert-danger mb-3">The last SSL provisioning attempt for <strong>' . htmlspecialchars($ssl_card_domain) . '</strong> failed. '
					. 'It retries automatically with hourly backoff. '
					. '<a href="/admin/server_manager/job_detail?job_id=' . $ssl_job->key . '" class="alert-link">Review the job output →</a></div>';
				echo '<form method="post" action="' . $base_url . '">';
				echo '<input type="hidden" name="action" value="provision_ssl">';
				echo SmAdminCsrf::field();
				echo '<button type="submit" class="btn btn-primary btn-sm">Retry SSL now</button>';
				echo '</form>';
			} else {
				echo '<p class="mb-3">SSL provisioning is in progress for <strong>' . htmlspecialchars($ssl_card_domain) . '</strong>.</p>';
				if ($ssl_job) {
					echo '<p><a href="/admin/server_manager/job_detail?job_id=' . $ssl_job->key . '" class="btn btn-sm btn-outline-secondary">View certificate job #' . $ssl_job->key . '</a></p>';
				}
			}
		} else {
			echo '<p class="mb-3">No SSL certificate is configured for <strong>' . htmlspecialchars($ssl_card_domain) . '</strong>.</p>';

			// DNS check table
			echo '<div class="border rounded p-3 mb-3">';
			echo '<div class="text-muted small text-uppercase mb-2">DNS Check</div>';
			echo '<table class="table table-sm mb-0">';
			echo '<tr><th class="svm-w100 fw-normal text-muted">Domain</th><td><code>' . htmlspecialchars($ssl_card_domain) . '</code></td></tr>';
			if ($host_ip) {
				echo '<tr><th class="fw-normal text-muted">Expected</th><td><code>' . htmlspecialchars($host_ip) . '</code></td></tr>';
			}
			if ($dns_resolves) {
				$dns_icon = $dns_matches ? '<span class="text-success">✓ DNS is ready</span>' : '<span class="text-danger">✗ Doesn\'t match node host</span>';
				echo '<tr><th class="fw-normal text-muted">Resolved</th><td><code>' . htmlspecialchars(implode(', ', $resolved_ips)) . '</code> ' . $dns_icon . '</td></tr>';
			} else {
				echo '<tr><th class="fw-normal text-muted">Resolved</th><td><span class="text-danger">✗ DNS not resolving</span></td></tr>';
			}
			echo '</table>';
			if ($no_host_ip) {
				echo '<p class="text-muted small mt-2 mb-0">Node host IP is not configured — cannot verify DNS. Provision SSL anyway at your own risk.</p>';
			} elseif (!$dns_matches) {
				$hint = $dns_resolves
					? 'DNS has not propagated yet.'
					: 'This domain is not resolving.';
				echo '<p class="text-muted small mt-2 mb-0">' . $hint . ' Point your domain\'s A record to <code>' . htmlspecialchars($host_ip) . '</code> and wait for it to resolve here before provisioning SSL.</p>';
			}
			echo '</div>';

			// Action buttons
			echo '<div class="d-flex gap-2 align-items-center">';
			if ($can_provision) {
				echo '<form method="post" action="' . $base_url . '">';
				echo '<input type="hidden" name="action" value="provision_ssl">';
				echo SmAdminCsrf::field();
				echo '<button type="submit" class="btn btn-primary btn-sm">Provision SSL</button>';
				echo '</form>';
			} else {
				echo '<button class="btn btn-primary btn-sm" disabled title="DNS must resolve to the node host before provisioning SSL">Provision SSL</button>';
			}
			echo '<a href="' . $base_url . '&tab=overview" class="btn btn-outline-secondary btn-sm">Re-check DNS</a>';
			echo '</div>';
			if ($can_provision) {
				echo '<p class="text-muted small mt-2 mb-0">The node\'s agent (its host\'s, for a container) issues the certificate and serves HTTPS for this domain. A Docker host also retries on its own timer until DNS points here.</p>';
			}
		}

		$page->end_box();
	}

	// ── Connection Info panel (read-only summary; the address and site are in the header) ──
	$pageoptions = ['title' => 'Connection Info'];
	$page->begin_box($pageoptions);
	echo '<table class="table table-sm mb-0 align-middle">';
	echo '<tbody>';

	$conn_row = function($label, $value) {
		echo '<tr>';
		echo '<th class="text-muted fw-normal svm-w200">' . $label . '</th>';
		echo '<td>' . $value . '</td>';
		echo '</tr>';
	};

	$conn_row('SSH', '<code>' . htmlspecialchars($node->get('mgn_ssh_user')) . '@' . htmlspecialchars($node->get('mgn_host')) . ':' . intval($node->get('mgn_ssh_port') ?: 22) . '</code>');

	if ($node->get('mgn_container_name')) {
		$container_value = '<code>' . htmlspecialchars($node->get('mgn_container_name')) . '</code>';
		if ($node->get('mgn_container_user')) {
			$container_value .= ' <span class="text-muted">as ' . htmlspecialchars($node->get('mgn_container_user')) . '</span>';
		}
		$conn_row('Docker container', $container_value);
	}
	if ($node->get('mgn_web_root')) {
		$conn_row('Web root', '<code>' . htmlspecialchars($node->get('mgn_web_root')) . '</code>');
	}
	$target_id = $node->get('mgn_bkt_backup_target_id');
	if ($target_id) {
		require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));
		try {
			$target = new BackupTarget($target_id, TRUE);
			$conn_row('Backup target',
				'<a href="/admin/server_manager/target_info?bkt_backup_target_id=' . $target->key . '">' . htmlspecialchars($target->get('bkt_name')) . '</a> <span class="text-muted">(' . htmlspecialchars($target->get('bkt_provider')) . ')</span>'
			);
		} catch (Exception $e) {}
	}
	if ($node->get('mgn_notes')) {
		$conn_row('Notes', nl2br(htmlspecialchars($node->get('mgn_notes'))));
	}

	echo '</tbody></table>';
	$page->end_box();

	// ── Move to customer's Linode (a Managed site's node) ──
	// The check reads the operator's Linode account several times, so it runs
	// from the page once the tab has drawn rather than holding the page up.
	$xfer_provision = CustomerCloudProvision::latest_for_node($node->key);
	$xfer_row = $xfer_provision ? InstanceTransfer::latest_for_provision((int)$xfer_provision->key) : null;
	if ($xfer_row !== null && $xfer_row->state() === InstanceTransfer::STATE_CANCELED && !$xfer_provision->is_operator_hosted()) {
		$xfer_row = null;
	}
	// Sold sites only: a relay shard and an operator-account site copy are on our account too.
	$xfer_sold = $xfer_provision && $xfer_provision->is_sold();
	if ($xfer_sold && ($xfer_provision->is_operator_hosted() || $xfer_row !== null)) {
		$page->begin_box(['title' => 'Move to customer\'s Linode']);
		if ($xfer_row !== null && $xfer_row->state() !== InstanceTransfer::STATE_CANCELED) {
			echo '<p class="mb-2"><strong>' . htmlspecialchars($xfer_row->state_label()) . '</strong> &middot; '
				. '<a href="/admin/server_manager/transfers?itx_instance_transfer_id=' . (int)$xfer_row->key . '">open in the transfer queue</a></p>';
		}
		if ($xfer_provision->is_operator_hosted()) {
			$xfer_startable = $xfer_row === null || in_array($xfer_row->state(),
				array(InstanceTransfer::STATE_REQUESTED, InstanceTransfer::STATE_CANCELED, InstanceTransfer::STATE_DONE), true);
			echo '<p class="text-muted small mb-2">Hands this server — running, with its disks and addresses — to the customer\'s own '
				. 'Linode account. Linode bills them from then on; their mail and backups carry on through us.</p>';
			echo '<ul id="xferCheck" class="list-unstyled small mb-2" data-provision="' . (int)$xfer_provision->key . '">'
				. '<li class="text-muted">Checking&hellip;</li></ul>';
			echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="xferRecheck">Re-check</button> ';
			if ($xfer_startable) {
				echo '<button type="button" class="btn btn-sm btn-primary" id="xferStart" disabled '
					. 'data-confirm="Start the move? The customer is emailed how to get ready and fetch their transfer code.">Start</button>';
			}
			echo '<p id="xferNotice" class="small mt-2 mb-0" role="status" aria-live="polite"></p>';
			?>
<script>
(function () {
	var list = document.getElementById('xferCheck');
	var start = document.getElementById('xferStart');
	var notice = document.getElementById('xferNotice');
	var provision = parseInt(list.dataset.provision, 10);
	var marks = { pass: '✓', warning: '!', blocker: '✗' };
	var colours = { pass: 'text-success', warning: 'text-warning', blocker: 'text-danger' };
	function show(check) {
		list.textContent = '';
		(check.items || []).forEach(function (item) {
			if (item.result === 'pass') { return; }
			var li = document.createElement('li');
			li.className = colours[item.result] || '';
			li.textContent = marks[item.result] + ' ' + item.label + (item.detail ? ' — ' + item.detail : '') + (item.fix ? ' ' + item.fix : '');
			list.appendChild(li);
		});
		var summary = document.createElement('li');
		summary.className = check.blockers ? 'text-danger' : 'text-success';
		summary.textContent = check.blockers ? check.blockers + ' thing(s) to fix before it can start.'
			: 'Ready to hand over' + (check.warnings ? ' (' + check.warnings + ' warning(s) above).' : '.');
		list.insertBefore(summary, list.firstChild);
		if (start) { start.disabled = check.blockers > 0; }
	}
	function run(what) {
		notice.textContent = '';
		return joineryApi.post('server_manager/transfer_operator', { 'do': what, provision_id: provision });
	}
	function check() {
		list.innerHTML = '<li class="text-muted">Checking…</li>';
		run('check').then(function (data) { show(data.check); }).catch(function (err) {
			list.innerHTML = '';
			notice.className = 'small mt-2 mb-0 text-danger';
			notice.textContent = err && err.message ? err.message : 'The check could not run.';
		});
	}
	document.getElementById('xferRecheck').addEventListener('click', check);
	if (start) {
		start.addEventListener('click', function () {
			if (!window.confirm(start.dataset.confirm)) { return; }
			start.disabled = true;
			run('start').then(function () { window.location.reload(); }).catch(function (err) {
				notice.className = 'small mt-2 mb-0 text-danger';
				notice.textContent = err && err.message ? err.message : 'It could not start.';
			});
		});
	}
	check();
})();
</script>
			<?php
		}
		$page->end_box();
	}

	// ── DNS publish box ──
	// Above Reverse DNS deliberately: a provider only accepts a PTR once the
	// forward record it names already resolves, so the forward record is the
	// step that comes first.
	if (!empty($dns_box)) {
		require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
		dns_publish_box_render($page, $dns_box);
	}

	// ── Reverse DNS panel (cloud-born nodes only) ──
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
	$rdns_provision = NodeReverseDns::provisionForNode($node);
	if ($rdns_provision) {
		$rdns_ip = (string)$rdns_provision->get('cvp_instance_ip');
		try {
			$rdns_ptrs = DnsResolver::getPtr($rdns_ip);
			$rdns_current = count($rdns_ptrs) ? implode(', ', $rdns_ptrs) : '';
		} catch (Exception $e) {
			$rdns_current = '';
		}
		$rdns_suggest = '';
		if ($node->get('mgn_site_url')) {
			$rdns_domain = parse_url($node->get('mgn_site_url'), PHP_URL_HOST);
			if ($rdns_domain) {
				$rdns_suggest = 'mail.' . preg_replace('/^www\./', '', $rdns_domain);
			}
		}

		$pageoptions = ['title' => 'Reverse DNS'];
		$page->begin_box($pageoptions);
		echo '<div class="mb-2"><span class="text-muted small">' . htmlspecialchars($rdns_ip) . ' currently answers: </span>';
		echo '<code>' . htmlspecialchars($rdns_current ?: 'no PTR record') . '</code></div>';
		if ($rdns_provision->is_transferred()) {
			echo '<p class="text-muted small mb-0">' . htmlspecialchars(NodeReverseDns::TRANSFERRED_MESSAGE) . '</p>';
			$page->end_box();
		} else {
			echo '<p class="text-muted small mb-2">Sets the PTR through the cloud account that provisioned this node. The hostname\'s A record must already point at ' . htmlspecialchars($rdns_ip) . '.</p>';

			$fw_rdns = $page->getFormWriter('rdns_form', [
				'action' => $base_url . '&tab=overview',
				'values' => ['rdns_hostname' => $rdns_suggest],
			]);
			$fw_rdns->begin_form();
			$fw_rdns->hiddeninput('action', '', ['id' => 'rdns_action', 'value' => 'set_reverse_dns']);
			$fw_rdns->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
			$fw_rdns->textinput('rdns_hostname', 'Hostname', [
				'placeholder' => 'mail.example.com',
			]);
			$fw_rdns->submitbutton('btn_rdns_set', 'Set Reverse DNS', ['class' => 'btn btn-sm btn-primary']);
			$fw_rdns->end_form();
			$page->end_box();
		}
	}

	// Recent jobs for this node
	$overview_jobs = new MultiManagementJob(['deleted' => false, 'node_id' => $node->key], ['mjb_management_job_id' => 'DESC'], 10);
	$overview_jobs->load();

	$pageoptions = ['title' => 'Recent Jobs', 'altlinks' => ['All Jobs' => $base_url . '&tab=jobs']];
	$page->begin_box($pageoptions);

	echo '<table class="table table-striped table-sm">';
	echo '<thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Created</th><th>Duration</th></tr></thead>';
	echo '<tbody>';
	$job_count = 0;
	foreach ($overview_jobs as $oj) {
		$job_count++;
		$oj_sc = match($oj->get('mjb_status')) {
			'completed' => 'success', 'failed' => 'danger', 'running' => 'primary',
			'cancelled' => 'secondary', default => 'warning',
		};
		$oj_dur = '';
		if ($oj->get('mjb_started_time') && $oj->get('mjb_completed_time')) {
			$d = strtotime($oj->get('mjb_completed_time')) - strtotime($oj->get('mjb_started_time'));
			$oj_dur = $d < 60 ? "{$d}s" : round($d / 60, 1) . 'm';
		} elseif ($oj->get('mjb_started_time')) {
			$d = time() - strtotime($oj->get('mjb_started_time'));
			$oj_dur = ($d < 60 ? "{$d}s" : round($d / 60, 1) . 'm') . '...';
		}
		echo '<tr>';
		echo '<td><a href="/admin/server_manager/job_detail?job_id=' . $oj->key . '">#' . $oj->key . '</a></td>';
		echo '<td>' . htmlspecialchars(str_replace('_', ' ', $oj->get('mjb_job_type'))) . '</td>';
		echo '<td><span class="badge bg-' . $oj_sc . '">' . htmlspecialchars($oj->get('mjb_status')) . '</span></td>';
		echo '<td>' . htmlspecialchars(LibraryFunctions::time_ago($oj->get('mjb_create_time'), $session->get_timezone())) . '</td>';
		echo '<td>' . $oj_dur . '</td>';
		echo '</tr>';
	}
	if ($job_count === 0) {
		echo '<tr><td colspan="5" class="text-muted text-center">No jobs yet</td></tr>';
	}
	echo '</tbody></table>';

	$page->end_box();

	// Connection settings — open when arriving from the Actions menu (?edit=1), otherwise collapsed
	$edit_open = !empty($_GET['edit']);
	echo '<div id="connectionSettings"' . ($edit_open ? '' : ' hidden') . '>';

	$default_ssh_key = '/home/user1/.ssh/id_ed25519';

	$pageoptions = ['title' => 'Connection Settings'];
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('node_form', [
		'model' => $node,
		'edit_primary_key_value' => $node->key,
	]);

	echo $formwriter->begin_form();
	echo '<input type="hidden" name="action" value="save_node">';
	echo SmAdminCsrf::field();

	$formwriter->textinput('mgn_name', 'Display Name *', [
		'placeholder' => 'e.g., Empowered Health Production',
		'validation' => ['required' => true, 'maxlength' => 100],
	]);

	$formwriter->textinput('mgn_slug', 'Slug *', [
		'placeholder' => 'e.g., empoweredhealthtn',
		'helptext' => 'Unique short identifier (lowercase, hyphens OK)',
		'validation' => ['required' => true, 'maxlength' => 50],
	]);

	$formwriter->textinput('mgn_host', 'SSH Host *', [
		'placeholder' => 'e.g., 23.239.11.53',
		'validation' => ['required' => true, 'maxlength' => 255],
	]);

	$formwriter->textinput('mgn_ssh_user', 'SSH User', [
		'placeholder' => 'root',
		'validation' => ['maxlength' => 50],
	]);

	$formwriter->textinput('mgn_ssh_key_path', 'SSH Key Path *', [
		'placeholder' => $default_ssh_key,
		'validation' => ['required' => true, 'maxlength' => 500],
	]);

	$formwriter->numberinput('mgn_ssh_port', 'SSH Port', [
		'placeholder' => '22',
		'min' => 1, 'max' => 65535,
	]);

	echo '<h6 class="text-muted mt-4 mb-3">Docker Settings <small>(leave blank for bare-metal servers)</small></h6>';

	$formwriter->textinput('mgn_container_name', 'Docker Container Name', [
		'placeholder' => 'e.g., empoweredhealthtn',
		'validation' => ['maxlength' => 100],
	]);

	$formwriter->textinput('mgn_container_user', 'Container User', [
		'placeholder' => 'e.g., www-data',
		'validation' => ['maxlength' => 50],
	]);

	echo '<h6 class="text-muted mt-4 mb-3">Joinery Paths</h6>';

	$formwriter->textinput('mgn_web_root', 'Web Root Path *', [
		'placeholder' => '/var/www/html/site/public_html',
		'validation' => ['required' => true, 'maxlength' => 500],
	]);

	$formwriter->textinput('mgn_site_url', 'Site URL', [
		'placeholder' => 'e.g., https://empoweredhealthtn.com',
		'validation' => ['maxlength' => 500],
	]);

	echo '<h6 class="text-muted mt-4 mb-3">Backup Settings</h6>';

	// Target dropdown (manual since FormWriter doesn't have a model-aware FK dropdown)
	require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));
	$all_targets = new MultiBackupTarget(['deleted' => false, 'enabled' => true], ['bkt_name' => 'ASC']);
	$all_targets->load();
	$current_target_id = $node->get('mgn_bkt_backup_target_id');

	echo '<div class="mb-3">';
	echo '<label class="form-label">Backup Target</label>';
	echo '<select name="mgn_bkt_backup_target_id" class="form-select">';
	echo '<option value="">Local only (no cloud upload)</option>';
	foreach ($all_targets as $d) {
		$sel = ($d->key == $current_target_id) ? ' selected' : '';
		echo '<option value="' . $d->key . '"' . $sel . '>' . htmlspecialchars($d->get('bkt_name')) . ' (' . $d->get('bkt_provider') . ')</option>';
	}
	echo '</select>';
	echo '<small class="text-muted">Where to upload backups after creation. <a href="/admin/server_manager/targets">Manage targets</a></small>';
	echo '</div>';

	$formwriter->checkboxinput('mgn_delete_local_after_upload', 'Delete local backup after upload', [
		'checked' => $node->get('mgn_delete_local_after_upload'),
		'helptext' => 'Removes the local copy on this node after a successful cloud upload. Saves disk but leaves only the cloud copy.',
	]);

	$formwriter->checkboxinput('mgn_skip_joinery_checks', 'Skip Joinery-specific checks (for non-Joinery servers)', [
		'checked' => $node->get('mgn_skip_joinery_checks'),
		'helptext' => 'Hides Joinery-only tabs (Backups, Database, Updates). Use for servers not running the Joinery platform.',
	]);

	$formwriter->checkboxinput('mgn_enabled', 'Enabled', [
		'checked' => $node->get('mgn_enabled'),
	]);

	echo '<h6 class="text-muted mt-4 mb-3">Uptime Monitoring</h6>';

	$formwriter->checkboxinput('mgn_uptime_enabled', 'Monitor uptime', [
		'checked' => $node->get('mgn_uptime_enabled'),
		'helptext' => 'When checked, the site is polled on every cron tick (~15 min). Down/recovered transitions trigger an email alert.',
	]);

	$uptime_check_type = $node->get('mgn_uptime_check_type') ?: 'http_status';
	$formwriter->dropinput('mgn_uptime_check_type', 'Check type', [
		'options' => [
			'api'         => 'API probe (authenticated /api/v1/management/stats)',
			'http_status' => 'HTTP status (plain GET, any 2xx/3xx is up)',
			'tcp_port'    => 'TCP port (connection accepted means up)',
		],
		'value'    => $uptime_check_type,
		'helptext' => 'API probe gives richer info but requires API keys — without them the check cannot conclude and the node is reported as misconfigured. TCP port suits services with no web endpoint, such as a mail relay. When "Skip Joinery-specific checks" is on, an API probe falls back to HTTP status; an explicitly chosen HTTP or TCP check is left alone.',
		'visibility_rules' => [
			'mgn_uptime_tcp_port' => ['tcp_port'],
		],
	]);

	$formwriter->numberinput('mgn_uptime_tcp_port', 'TCP port', [
		'value'    => (int)$node->get('mgn_uptime_tcp_port') ?: '',
		'min'      => 1,
		'max'      => 65535,
		'helptext' => 'Port to connect to on this node\'s host address. 25 for an inbound mail relay.',
	]);

	$formwriter->numberinput('mgn_uptime_interval_seconds', 'Check interval (seconds)', [
		'value'    => (int)$node->get('mgn_uptime_interval_seconds') ?: 300,
		'min'      => 0,
		'helptext' => 'How often this node is probed, independent of how often cron runs. 0 probes on every cron pass.',
	]);

	$formwriter->textbox('mgn_notes', 'Notes', ['rows' => 3]);

	$formwriter->submitbutton('btn_submit', 'Save Changes');
	echo $formwriter->end_form();

	$page->end_box();
	echo '</div>'; // end connectionSettings

