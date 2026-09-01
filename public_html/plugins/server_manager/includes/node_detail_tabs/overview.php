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
<form id="run_plugin_installers_form" method="post" action="<?php echo $base_url; ?>" hidden>
	<input type="hidden" name="action" value="run_plugin_installers">
	<?php echo SmAdminCsrf::field(); ?>
</form>
<?php
	// Install state banner (takes precedence over regular status)
	$install_state = $node->get('mgn_install_state');
	if ($install_state === 'installing') {
		echo '<div class="alert alert-info"><strong>Install in progress.</strong> The install job is running against this node. ';
		$install_job = ManagementJob::latestForNode($node->key, 'install_node');
		if ($install_job) {
			echo '<a href="/admin/server_manager/job_detail?job_id=' . $install_job->key . '">View job #' . $install_job->key . '</a>';
		}
		echo '</div>';
	} elseif ($install_state === 'install_failed') {
		echo '<div class="alert alert-danger"><strong>Install failed.</strong> The last install attempt did not complete.';
		$install_job = ManagementJob::latestForNode($node->key, 'install_node');
		if ($install_job) {
			echo ' <a href="/admin/server_manager/job_detail?job_id=' . $install_job->key . '" class="alert-link">View job #' . $install_job->key . ' output</a>.';
		}
		echo '<div class="mt-2"><form method="post" class="svm-inline-form" id="retry_install_form">';
		echo '<input type="hidden" name="action" value="retry_install">';
		echo SmAdminCsrf::field();
		echo '<button type="button" class="btn btn-sm btn-warning" onclick="JoineryModal.confirm(\'Before retrying: SSH to the target and remove any partial install (e.g. rm -rf /var/www/html/SITENAME, drop the DB). install.sh will refuse if the site directory already exists. Continue?\', function(){ document.getElementById(\'retry_install_form\').submit(); })">Retry Install</button></form></div>';
		echo '</div>';
	}

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

	echo '<div class="mb-3">';
	echo '<div class="d-flex justify-content-between align-items-center py-2 px-3 mb-2">';
	echo '<div class="d-flex align-items-center">';
	echo '<span class="badge bg-' . $status_color . ' me-2">&bull;</span>';
	echo '<strong>' . $node_name . '</strong>';
	if ($node->get('mgn_site_url')) {
		echo '<a href="' . htmlspecialchars($node->get('mgn_site_url')) . '" target="_blank" class="ms-2 small">' . htmlspecialchars($node->get('mgn_site_url')) . '</a>';
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
			<?php if (JobCommandBuilder::has_ssh($node) && $node->get('mgn_web_root')): ?>
				<li><a class="dropdown-item" href="#" onclick="JoineryModal.confirm('Run every active plugin\'s host installer on this node (root, idempotent)? Needed after activating a plugin that configures system services, e.g. the mail stack.', function(){ document.getElementById('run_plugin_installers_form').submit(); }); return false;">Run Plugin Installers</a></li>
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
			<li><hr class="dropdown-divider"></li>
			<?php if (!$is_removed): ?>
				<li>
					<form method="post" action="<?php echo $base_url; ?>" id="delete_node_form" style="margin:0;">
						<input type="hidden" name="action" value="delete_node">
						<?php echo SmAdminCsrf::field(); ?>
						<button type="button" class="dropdown-item text-danger" onclick="JoineryModal.confirm('Remove this site from the dashboard? The site keeps running on its host — only the tracking record is removed.', function(){ document.getElementById('delete_node_form').submit(); })">Remove from Dashboard</button>
					</form>
				</li>
			<?php endif; ?>
			<?php if ($decommission_site !== null && (!$is_removed || $site_ever_confirmed)): ?>
				<li>
					<form method="post" action="<?php echo $base_url; ?>" id="decommission_node_form" style="margin:0;">
						<input type="hidden" name="action" value="decommission_node">
						<input type="hidden" name="confirm_site_name" value="<?php echo htmlspecialchars($decommission_site); ?>">
						<?php echo SmAdminCsrf::field(); ?>
						<button type="button" class="dropdown-item text-danger" onclick="JoineryModal.confirmTyped(<?php echo htmlspecialchars(json_encode($is_removed ? 'Permanently delete the site on the host? If it is still running there, this destroys the container, its database, and every uploaded file. Offsite backups are kept. This cannot be undone.' : 'Permanently delete this site? This destroys the container, its database, and every uploaded file on the host. Offsite backups are kept. This cannot be undone.'), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($decommission_site), ENT_QUOTES); ?>, function(){ document.getElementById('decommission_node_form').submit(); })">Permanently Delete Site&hellip;</button>
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

	echo '<div class="d-flex align-items-center gap-2 ps-3 mt-1">';
	if ($last_check) {
		// "Measured", not "checked": the word has to survive the distinction the
		// value now respects, or the honest number reads as the old claim.
		echo '<small class="text-muted">Figures measured: '
			. LibraryFunctions::convert_time($last_check, 'UTC', $session->get_timezone(), 'M j, g:i A')
			. '</small>';
		if ($figures_stale && $status_data) {
			echo '<small class="text-warning">Too old to judge health by &mdash; run a status check.</small>';
		}
	} elseif (!$status_data) {
		echo '<small class="text-muted">No status check has been run yet.</small>';
	}
	echo '<button type="submit" form="nodeActionCheckStatus" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075">Check Status</button>';
	echo '</div>';

	// Uptime monitoring status
	$uptime_enabled = $node->get('mgn_uptime_enabled');
	$uptime_status  = $node->get('mgn_uptime_last_status');
	$uptime_down    = $node->get('mgn_uptime_down_since');
	$monitor_health = NodeMonitorHealth::evaluate($node);
	echo '<div class="mt-1 ps-3"><small>';
	if (!$uptime_enabled) {
		echo '<span class="text-muted">Uptime monitoring: disabled</span>';
	} elseif ($uptime_status === 'down') {
		$down_display = $uptime_down
			? LibraryFunctions::convert_time($uptime_down, 'UTC', $session->get_timezone(), 'M j, g:i A')
			: 'unknown';
		echo '<span class="text-danger"><strong>Uptime: Down since ' . htmlspecialchars($down_display) . '</strong></span>';
	} elseif ($uptime_status === 'up') {
		echo '<span class="text-success">Uptime: Up</span>';
	} else {
		echo '<span class="text-muted">Uptime: not yet checked</span>';
	}
	echo '</small></div>';

	// Monitoring-health banner. A node whose checks cannot conclude reports no
	// up/down at all, so without this it reads as merely unchecked — which is
	// how a broken check hides indefinitely behind a healthy-looking node.
	if ($monitor_health['is_problem']) {
		echo '<div class="alert alert-warning mt-2 mb-0" role="alert">';
		echo '<strong>' . htmlspecialchars($monitor_health['label']) . ':</strong> ';
		echo htmlspecialchars($monitor_health['detail']);
		echo ' <a href="' . $base_url . '&tab=overview#node-settings">Fix in settings</a>';
		echo '</div>';
	}

	// TLS certificate expiry — populated by the uptime tick for self-renewed,
	// directly-exposed nodes (e.g. the Caddy DNS servers the SSL tile can't see).
	$cert_expiry = $node->get('mgn_cert_expiry_ts');
	if ($cert_expiry) {
		$cert_warn_days = (int)Globalvars::get_instance()->get_setting('server_manager_cert_expiry_warn_days');
		if ($cert_warn_days <= 0) { $cert_warn_days = 21; }
		$cert_expiry_ts   = strtotime($cert_expiry . ' UTC');
		$cert_days_left   = (int)floor(($cert_expiry_ts - time()) / 86400);
		$cert_expiry_disp = LibraryFunctions::convert_time($cert_expiry, 'UTC', $session->get_timezone(), 'M j, Y');
		echo '<div class="mt-1 ps-3"><small>';
		if ($cert_days_left < $cert_warn_days) {
			echo '<span class="text-danger"><strong>TLS cert: expires ' . htmlspecialchars($cert_expiry_disp) . ' (' . $cert_days_left . ' days)</strong></span>';
		} else {
			echo '<span class="text-muted">TLS cert: expires ' . htmlspecialchars($cert_expiry_disp) . ' (' . $cert_days_left . ' days)</span>';
		}
		echo '</small></div>';
	}

	echo '</div>';

	// ── System Health panel ──
	if ($status_data) {
		$pageoptions = ['title' => 'System Health'];
		$page->begin_box($pageoptions);

		$cp_version = LibraryFunctions::get_joinery_version();
		$node_version = $node->get('mgn_joinery_version');
		$version_cmp = ($cp_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $node_version ?? ''))
			? version_compare($node_version, $cp_version) : null;

		// Stat tile grid — each tile: label on top, large value, optional subline/progress bar.
		echo '<div class="row g-3">';

		// Disk
		if (isset($status_data['disk_usage_percent'])) {
			$pct = intval($status_data['disk_usage_percent']);
			$bar = $pct > 90 ? 'bg-danger' : ($pct > 80 ? 'bg-warning' : 'bg-success');
			$sub = '';
			if (!empty($status_data['disk_total'])) {
				$sub = htmlspecialchars($status_data['disk_used'] . ' / ' . $status_data['disk_total'] . ' used · ' . ($status_data['disk_available'] ?? '?') . ' free');
			}
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Disk</div>';
			echo '<div class="fs-3 fw-semibold mt-1">' . $pct . '<span class="fs-5 text-muted">%</span></div>';
			echo '<div class="progress mt-2 svm-progress-thin"><div class="progress-bar ' . $bar . ' svm-progress-bar" style="--svm-pct:' . $pct . '%"></div></div>';
			if ($sub) echo '<div class="text-muted small mt-2">' . $sub . '</div>';
			echo '</div></div>';
		}

		// Memory
		if (isset($status_data['memory_used_mb'], $status_data['memory_total_mb']) && $status_data['memory_total_mb'] > 0) {
			$used = (int)$status_data['memory_used_mb'];
			$total = (int)$status_data['memory_total_mb'];
			$pct = (int)round($used * 100 / $total);
			$bar = $pct > 90 ? 'bg-danger' : ($pct > 80 ? 'bg-warning' : 'bg-success');
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Memory</div>';
			echo '<div class="fs-3 fw-semibold mt-1">' . $pct . '<span class="fs-5 text-muted">%</span></div>';
			echo '<div class="progress mt-2 svm-progress-thin"><div class="progress-bar ' . $bar . ' svm-progress-bar" style="--svm-pct:' . $pct . '%"></div></div>';
			echo '<div class="text-muted small mt-2">' . $used . ' / ' . $total . ' MB</div>';
			echo '</div></div>';
		}

		// Load average
		if (isset($status_data['load_1m'])) {
			$l1 = $status_data['load_1m'] ?? '-';
			$l5 = $status_data['load_5m'] ?? '-';
			$l15 = $status_data['load_15m'] ?? '-';
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Load Average</div>';
			echo '<div class="fs-3 fw-semibold mt-1">' . htmlspecialchars((string)$l1) . '</div>';
			echo '<div class="text-muted small mt-2">' . htmlspecialchars("{$l5} (5m) · {$l15} (15m)") . '</div>';
			echo '</div></div>';
		}

		// Uptime
		if (!empty($status_data['uptime'])) {
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Uptime</div>';
			echo '<div class="fs-5 fw-semibold mt-1">' . htmlspecialchars($status_data['uptime']) . '</div>';
			echo '</div></div>';
		}

		// Service, for a machine this plane reaches by probing it. The DNS boxes
		// and the mail relay carry no agent and host no site, so what their own
		// health document says about them is the only account of them there is.
		if (isset($status_data['status']) || isset($status_data['port_reachable'])) {
			echo '<div class="col-md-6 col-xl-4">';
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
			if (isset($status_data['db_connected'])) {
				$svc_bits[] = $status_data['db_connected'] ? 'database connected' : 'database unreachable';
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

		// PostgreSQL
		if (!empty($status_data['postgres_status'])) {
			$pg_class = $status_data['postgres_status'] === 'accepting connections' ? 'success' : 'danger';
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">PostgreSQL</div>';
			echo '<div class="mt-1"><span class="badge bg-' . $pg_class . '">' . htmlspecialchars($status_data['postgres_status']) . '</span></div>';
			if (!empty($status_data['current_db'])) {
				echo '<div class="text-muted small mt-2">Current DB: <code>' . htmlspecialchars($status_data['current_db']) . '</code></div>';
			}
			echo '</div></div>';
		}

		// Cron health
		if (!empty($status_data['cron_last_run'])) {
			$cron_ts = strtotime($status_data['cron_last_run']);
			$cron_ok = $cron_ts && (time() - $cron_ts) < 1200;
			$cron_badge = $cron_ok ? 'success' : 'warning';
			$cron_label = $cron_ok ? 'Active' : 'Stale';
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Cron</div>';
			echo '<div class="mt-1"><span class="badge bg-' . $cron_badge . '">' . $cron_label . '</span></div>';
			echo '<div class="text-muted small mt-2">Last run: ' . htmlspecialchars(LibraryFunctions::time_ago_or_time($status_data['cron_last_run'], 'UTC', $session->get_timezone(), 'M j, g:i A')) . '</div>';
			echo '</div></div>';
		}

		// Sealed-secret health. Counts only ride up in the status blob — never a
		// value. A dead operator credential or a re-mint awaiting acknowledgement
		// is fixed ON the node (the management node holds none of the node's keys),
		// so this links out to the node's own secrets page rather than acting here.
		if (isset($status_data['sealed_secrets']) && is_array($status_data['sealed_secrets'])) {
			$ss = $status_data['sealed_secrets'];
			$ss_attention = (int)($ss['dead_operator'] ?? 0) + (int)($ss['dead_needs_ack'] ?? 0);
			echo '<div class="col-md-6 col-xl-4">';
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

		// Joinery version
		if ($node_version) {
			$badge = '';
			$subline = '';
			if ($version_cmp === -1) {
				$badge = ' <span class="badge bg-warning ms-1">upgrade available</span>';
				$subline = 'Management node: ' . htmlspecialchars($cp_version);
			} elseif ($version_cmp === 1) {
				$badge = ' <span class="badge bg-danger ms-1">ahead of management node</span>';
				$subline = 'Management node: ' . htmlspecialchars($cp_version);
			} elseif ($version_cmp === 0) {
				$badge = ' <span class="badge bg-success ms-1">up to date</span>';
			}
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Joinery Version</div>';
			echo '<div class="fs-5 fw-semibold mt-1">' . htmlspecialchars($node_version) . $badge . '</div>';
			if ($subline) echo '<div class="text-muted small mt-2">' . $subline . '</div>';
			echo '</div></div>';
		}

		// SSL
		$ssl_tile_state = $node->get('mgn_ssl_state');
		if ($ssl_tile_state !== null || (is_array($status_data) && array_key_exists('ssl_state', $status_data))) {
			switch ($ssl_tile_state) {
				case 'active':
					$ssl_badge = 'success'; $ssl_label = 'active'; break;
				case 'pending':
					$ssl_badge = 'warning'; $ssl_label = 'pending'; break;
				case 'failed':
					$ssl_badge = 'danger';  $ssl_label = 'failed';  break;
				default:
					$ssl_badge = 'secondary'; $ssl_label = 'not configured';
			}
			$ssl_sub = '';
			if ($ssl_tile_state === 'active') {
				$ssl_method = $status_data['ssl_detection_method'] ?? null;
				// Explicit booleans from updated detection code; fall back to inferring from method for legacy data
				$le_val    = $status_data['ssl_le_cert']    ?? ($ssl_method === 'letsencrypt' ? true : null);
				$probe_val = $status_data['ssl_https_probe'] ?? ($ssl_method === 'https_probe' ? true : null);
				$ok   = '<span class="text-success">✓</span>';
				$fail = '<span class="text-danger">✗</span>';
				$dash = '<span class="text-muted">—</span>';
				// When no method confirmed SSL (e.g. edge/CDN like Cloudflare), show — rather
				// than ✗ — "undetectable by this method" is not the same as "SSL broken"
				if ($ssl_method === null) {
					if ($le_val    === false) $le_val    = null;
					if ($probe_val === false) $probe_val = null;
				}
				$le_icon    = $le_val    === true ? $ok : ($le_val    === false ? $fail : $dash);
				$probe_icon = $probe_val === true ? $ok : ($probe_val === false ? $fail : $dash);
				$ssl_sub = '<div class="mt-2 small">'
					. '<div class="d-flex justify-content-between gap-3"><span class="text-muted">Let\'s Encrypt cert</span>' . $le_icon . '</div>'
					. '<div class="d-flex justify-content-between gap-3 mt-1"><span class="text-muted">HTTPS probe</span>' . $probe_icon . '</div>';
				if (!empty($status_data['ssl_expiry_ts'])) {
					$days_left  = (int)(($status_data['ssl_expiry_ts'] - time()) / 86400);
					$expiry_str = date('M j, Y', $status_data['ssl_expiry_ts']);
					$ssl_sub .= '<div class="mt-1">' . ($days_left < 30
						? '<span class="badge bg-warning">Expires ' . htmlspecialchars($expiry_str) . '</span>'
						: '<span class="text-muted">Expires ' . htmlspecialchars($expiry_str) . '</span>') . '</div>';
				}
				$ssl_sub .= '</div>';
			} elseif ($ssl_tile_state === 'pending') {
				$ssl_sub = '<span class="text-muted small">Waiting for DNS / certbot</span>';
			} elseif ($ssl_tile_state === 'failed') {
				$ssl_sub = '<span class="text-muted small">See SSL Setup below</span>';
			}
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">SSL</div>';
			echo '<div class="mt-1"><span class="badge bg-' . $ssl_badge . '">' . $ssl_label . '</span></div>';
			if ($ssl_sub) echo $ssl_sub;
			echo '</div></div>';
		}

		echo '</div>'; // end .row

		// Secondary info that doesn't warrant its own tile.
		if (!empty($status_data['db_list']) && count($status_data['db_list']) > 1) {
			echo '<div class="text-muted small mt-3"><strong>All databases:</strong> ' . htmlspecialchars(implode(', ', $status_data['db_list'])) . '</div>';
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

	if ($is_fqdn && $ssl_card_state !== 'active' && $install_state !== 'installing' && !$node->get('mgn_cert_expiry_ts')) {
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

		// Failed alert with link to last provision_ssl job
		if ($ssl_card_state === 'failed') {
			$ssl_job = ManagementJob::latestForNode($node->key, 'provision_ssl');
			echo '<div class="alert alert-danger mb-3">A previous SSL provisioning attempt failed.';
			if ($ssl_job) {
				echo ' <a href="/admin/server_manager/job_detail?job_id=' . $ssl_job->key . '" class="alert-link">Review the job output →</a>';
			}
			echo '</div>';
		}

		if ($ssl_card_state === 'pending') {
			$ssl_job = ManagementJob::latestForNode($node->key, 'provision_ssl');
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
					echo '<p><a href="/admin/server_manager/job_detail?job_id=' . $ssl_job->key . '" class="btn btn-sm btn-outline-secondary">View provision SSL job #' . $ssl_job->key . '</a></p>';
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
				echo '<p class="text-muted small mt-2 mb-0">Certbot will run on the node\'s host and configure Apache to serve HTTPS for this domain.</p>';
			}
		}

		$page->end_box();
	}

	// ── Connection Info panel (read-only summary) ──
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

	$conn_row('Host', '<code>' . htmlspecialchars($node->get('mgn_host')) . '</code>');
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
	if ($node->get('mgn_site_url')) {
		$site_url = htmlspecialchars($node->get('mgn_site_url'));
		$conn_row('Site URL', '<a href="' . $site_url . '" target="_blank" rel="noopener">' . $site_url . ' ↗</a>');
	}

	$target_id = $node->get('mgn_bkt_backup_target_id');
	if ($target_id) {
		require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
		try {
			$target = new BackupTarget($target_id, TRUE);
			$conn_row('Backup target',
				'<a href="/admin/server_manager/target_info?bkt_id=' . $target->key . '">' . htmlspecialchars($target->get('bkt_name')) . '</a> <span class="text-muted">(' . htmlspecialchars($target->get('bkt_provider')) . ')</span>'
			);
		} catch (Exception $e) {}
	}
	if ($node->get('mgn_notes')) {
		$conn_row('Notes', nl2br(htmlspecialchars($node->get('mgn_notes'))));
	}

	echo '</tbody></table>';
	$page->end_box();

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

	// Recent jobs for this node
	$overview_jobs = new MultiManagementJob(['deleted' => false, 'node_id' => $node->key], ['mjb_id' => 'DESC'], 10);
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
		echo '<td>' . $oj->get_local('mjb_create_time', 'M j, g:i A') . '</td>';
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

	$default_ssh_key = '/home/user1/.ssh/id_ed25519_claude';

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
	require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
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

