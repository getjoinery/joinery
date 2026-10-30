<?php
/**
 * node_detail — Diagnostics tab partial.
 *
 * The questions a person asks of a node when something is wrong: how its
 * install went, whether the site or the path to it is slow, its logs, and
 * the diagnose-and-repair forms. Each answer is a job; its result is on the
 * job page.
 *
 * Included by views/admin/node_detail.php in the shell's scope; the shell
 * owns node loading, the tab whitelist, and the permission gate.
 *
 * In scope: $node, $page, $session, $base_url, $may_change.
 *
 * @version 1.1 - Logs: the host converger's journal (unit_journal joinery-host-converger, agent 1.97.0), the
 *                record of which installer failed a converge on a host with a data root
 * @version 1.0 - Reports, the machine's questions, Logs, Diagnose and repair
 */
?>
<form id="nodeActionInstallReport" method="post" action="<?php echo $base_url; ?>" hidden>
<input type="hidden" name="action" value="install_report">
<?php echo SmAdminCsrf::field(); ?>
</form>
<form id="nodeActionEdgeOrigin" method="post" action="<?php echo $base_url; ?>" hidden>
<input type="hidden" name="action" value="edge_vs_origin">
<?php echo SmAdminCsrf::field(); ?>
</form>
<?php foreach (['nodeActionDiskUsage' => 'disk_usage', 'nodeActionListeningSockets' => 'listening_sockets',
	'nodeActionContainerInventory' => 'container_inventory', 'nodeActionSpamLearning' => 'spam_learning_status',
	'nodeActionSiteStateReport' => 'site_state_report', 'nodeActionNetHealth' => 'net_health',
	'nodeActionWebWorkers' => 'web_workers'] as $form_id => $form_action) { ?>
<form id="<?php echo $form_id; ?>" method="post" action="<?php echo $base_url; ?>" hidden>
	<input type="hidden" name="action" value="<?php echo $form_action; ?>">
	<?php echo SmAdminCsrf::field(); ?>
</form>
<?php } ?>
<?php
$shown = 0;
$tools_buttons = array();
// The install log has only ever been readable by a shell on the box. A
// node whose agent can read it offers the report; one that cannot
// shows nothing rather than a button that would refuse.
if (JobCommandBuilder::has_primitive($node, 'install_report')) {
	$tools_buttons['install'] = '<button type="submit" form="nodeActionInstallReport" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075" title="How the first-boot install went: DNS, certificate, and the tail of the install log">Install Report</button>';
}
// Is the site slow, or the path to it? The same requests timed through the
// site's public name and straight at the machine, side by side, from here
// (EdgeOriginProbe). Takes up to a minute; no agent involved.
if (EdgeOriginProbe::site($node) && EdgeOriginProbe::origins($node)) {
	// Disabled on the first click: a run takes up to a minute, and a second
	// click would start a second run beside it.
	$tools_buttons['edge'] = '<button type="submit" form="nodeActionEdgeOrigin" onclick="var b=this;setTimeout(function(){b.disabled=true;b.textContent=\'Comparing…\';},0);" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075" title="Time the same requests through the site\'s public name (its edge, such as Cloudflare) and straight at this machine, side by side, and say which one stalls. Takes up to a minute.">Compare edge and direct</button>';
}

if ($tools_buttons) {
	$page->begin_box(['title' => 'Reports']);
	echo '<div class="d-flex flex-wrap gap-1">' . implode(' ', $tools_buttons) . '</div>';
	$tools_notes = array();
	if (isset($tools_buttons['install'])) {
		$tools_notes[] = 'Install Report: how the first-boot install went (DNS, certificate, the end of the install log).';
	}
	if (isset($tools_buttons['edge'])) {
		$tools_notes[] = 'Compare edge and direct: the same requests through the site\'s public name and straight at this machine, to tell a slow site from a slow path to it.';
	}
	echo '<div class="small text-muted mt-2">' . implode(' ', $tools_notes) . '</div>';
	$page->end_box();
	$shown++;
}

// The machine's questions, each a button with what it answers beside it.
$diag_hr = json_decode((string)$node->get('mgn_last_host_report'), true);
$diag_hr = is_array($diag_hr) ? JobResultProcessor::sanitise_host_report($diag_hr) : null;
$diag_status = json_decode((string)$node->get('mgn_last_status_data'), true);
$diag_on_container = trim((string)$node->get('mgn_container_name')) !== '';
$machine_qs = array();
if (JobCommandBuilder::has_primitive($node, 'disk_usage')) {
	$machine_qs[] = array('nodeActionDiskUsage', 'What is using the disk?', 'The biggest directories in the site tree and the usual machine directories, sizes only.');
}
if (JobCommandBuilder::has_primitive($node, 'listening_sockets')) {
	$machine_qs[] = array('nodeActionListeningSockets', 'Listening ports', 'The addresses and ports the machine listens on, with the program and unit holding each.');
}
// Network health is the host's: a site container's own network is not
// where its edge connects.
if (JobCommandBuilder::has_primitive($node, 'net_health') && !$diag_on_container) {
	$machine_qs[] = array('nodeActionNetHealth', 'Network', 'Whether the machine is dropping packets or connections: kernel counters read twice ten seconds apart, the connection table, the web listeners\' queues. Takes about fifteen seconds.');
}
if (JobCommandBuilder::has_primitive($node, 'web_workers')) {
	$machine_qs[] = array('nodeActionWebWorkers', 'Web workers', 'Whether Apache and PHP have free workers now, and how often PHP ran out in the last day.');
}
if (JobCommandBuilder::has_primitive($node, 'site_state_report')) {
	$machine_qs[] = array('nodeActionSiteStateReport', 'Site state records', 'What the machine records about its sites being quiet or copies: each record\'s name, size and time.');
}
if (JobCommandBuilder::has_primitive($node, 'container_inventory') && is_array($diag_hr['containers'] ?? null)) {
	$machine_qs[] = array('nodeActionContainerInventory', 'All containers', 'Every container on this machine: whether install.sh made it, its image, mounts and run spec.');
}
// Whether members' spam corrections reach the filter: counts only. Asked of
// a site whose Mailbox plugin is on.
$diag_mailbox = false;
foreach ((is_array($diag_status['plugins'] ?? null) ? $diag_status['plugins'] : array()) as $pl) {
	if (is_array($pl) && ($pl['name'] ?? '') === 'mailbox' && ($pl['active'] ?? null) === true) { $diag_mailbox = true; }
}
if ($diag_mailbox && JobCommandBuilder::has_primitive($node, 'spam_learning_status')) {
	$machine_qs[] = array('nodeActionSpamLearning', 'Spam learning', 'Whether what members mark as spam or not spam reaches the spam filter: counts only.');
}
if ($machine_qs) {
	$page->begin_box(['title' => 'The machine']);
	echo '<table class="table table-sm mb-0 align-middle"><tbody>';
	foreach ($machine_qs as list($form_id, $label, $what)) {
		echo '<tr><td style="white-space:nowrap"><button type="submit" form="' . $form_id . '" class="btn btn-sm btn-outline-secondary py-0 px-2 svm-fs-075">'
			. htmlspecialchars($label) . '</button></td><td class="small text-muted">' . htmlspecialchars($what) . '</td></tr>';
	}
	echo '</tbody></table>';
	$page->end_box();
	$shown++;
}

// The site's own logs, read on the node and redacted there, for a node
// whose agent ships the two log words. The owner's switch on the node
// decides; the node reports it at every poll, so a node that has said
// "off" gets the reason here instead of a job that would be refused.
$has_site_log  = JobCommandBuilder::has_primitive($node, 'site_log');
$has_log_table = JobCommandBuilder::has_primitive($node, 'log_table_tail');
$has_file_head = JobCommandBuilder::has_primitive($node, 'file_head');
$has_log_count = JobCommandBuilder::has_primitive($node, 'site_log_count');
if ($has_site_log || $has_log_table || $has_file_head || $has_log_count) {
	$log_refusal = JobCommandBuilder::log_access_refusal($node);
	$page->begin_box(['title' => 'Logs']);
	if ($log_refusal !== null) {
		echo '<div class="small text-muted">' . htmlspecialchars($log_refusal) . '</div>';
	} else {
		echo '<div class="small text-muted mb-2">The last lines of one of the site\'s log files, or the newest rows of one of its log tables. '
			. 'The node masks credentials and personal data before anything is sent; the result is on the job page.</div>';
		echo '<div class="d-flex flex-wrap gap-4">';
		if ($has_site_log) {
			echo '<div>';
			$fw_log = $page->getFormWriter('site_log_form', [
				'action' => $base_url . '&tab=diagnostics',
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
		// A question about a window (did this appear since the upgrade?) is
		// answered by counting, not by the last lines: on a busy site those
		// stop short of the window, and the count says when it did.
		if ($has_log_count) {
			echo '<div>';
			$fw_cnt = $page->getFormWriter('site_log_count_form', [
				'action' => $base_url . '&tab=diagnostics',
				'values' => ['since' => gmdate('Y-m-d H:i', time() - 3600)],
			]);
			$fw_cnt->begin_form();
			$fw_cnt->hiddeninput('action', '', ['id' => 'site_log_count_action', 'value' => 'site_log_count']);
			$fw_cnt->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'site_log_count_csrf', 'value' => SmAdminCsrf::token()]);
			$fw_cnt->textinput('since', 'Problem lines in the error log since (UTC, YYYY-MM-DD HH:MM)');
			$fw_cnt->submitbutton('btn_site_log_count', 'Count problem lines', ['class' => 'btn btn-sm btn-outline-secondary']);
			$fw_cnt->end_form();
			echo '</div>';
		}
		if ($has_log_table) {
			echo '<div>';
			$fw_tbl = $page->getFormWriter('log_table_form', [
				'action' => $base_url . '&tab=diagnostics',
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
		// The converger's journal: on a host with a data root it is the only
		// record of which installer failed a run, and the converger exits 0
		// either way, so it never appears among the failed units.
		if (JobCommandBuilder::has_primitive($node, 'unit_journal')
				&& array_key_exists('joinery-host-converger', JobCommandBuilder::unit_journal_units_for($node))) {
			echo '<div>';
			$fw_hc = $page->getFormWriter('converger_journal_form', [
				'action' => $base_url . '&tab=diagnostics',
				'values' => ['lines' => '200'],
			]);
			$fw_hc->begin_form();
			$fw_hc->hiddeninput('action', '', ['id' => 'converger_journal_action', 'value' => 'unit_journal']);
			$fw_hc->hiddeninput('unit', '', ['id' => 'converger_journal_unit', 'value' => 'joinery-host-converger']);
			$fw_hc->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'converger_journal_csrf', 'value' => SmAdminCsrf::token()]);
			$fw_hc->numberinput('lines', 'Host converger: lines of its journal (1 to ' . JobCommandBuilder::LOG_MAX_COUNT . ')',
				['min' => 1, 'max' => JobCommandBuilder::LOG_MAX_COUNT,
				 'helptext' => 'Which installer failed the last runs, and why: each run says which installers ran and which did not.']);
			$fw_hc->submitbutton('btn_converger_journal', 'Read converger journal', ['class' => 'btn btn-sm btn-outline-secondary']);
			$fw_hc->end_form();
			echo '</div>';
		}
		// Host configuration, from the compiled readable list: the node
		// returns a line whose key names a credential as the key alone,
		// and never reads a file that is itself a secret.
		if ($has_file_head) {
			echo '<div>';
			$fw_cfg = $page->getFormWriter('file_head_form', [
				'action' => $base_url . '&tab=diagnostics',
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
	$page->end_box();
	$shown++;
}

// Asking the node about its own database, and running one installer.
$has_schema_probe = JobCommandBuilder::has_primitive($node, 'schema_probe');
$has_run_installer = $may_change && JobCommandBuilder::has_primitive($node, 'run_installer');
$has_page_probe = JobCommandBuilder::has_primitive($node, 'page_probe');
$has_reclaim = $may_change && JobCommandBuilder::has_primitive($node, 'reclaim_managed_file');
if ($has_schema_probe || $has_run_installer || $has_page_probe || $has_reclaim) {
	$page->begin_box(['title' => 'Diagnose and repair']);
	echo '<div class="d-flex flex-wrap gap-4">';
	if ($has_schema_probe) {
		echo '<div>';
		$fw_sp = $page->getFormWriter('schema_probe_form', ['action' => $base_url . '&tab=diagnostics']);
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
		$fw_pp = $page->getFormWriter('page_probe_form', ['action' => $base_url . '&tab=diagnostics', 'values' => ['viewer' => 'admin']]);
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
		$fw_rc = $page->getFormWriter('reclaim_form', ['action' => $base_url . '&tab=diagnostics']);
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
		$fw_ri = $page->getFormWriter('run_installer_form', ['action' => $base_url . '&tab=diagnostics']);
		$fw_ri->begin_form();
		$fw_ri->hiddeninput('action', '', ['id' => 'run_installer_action', 'value' => 'run_installer']);
		$fw_ri->hiddeninput(SmAdminCsrf::FIELD, '', ['id' => 'run_installer_csrf', 'value' => SmAdminCsrf::token()]);
		$fw_ri->dropinput('name', 'Installer', ['options' => $installers,
			'helptext' => 'Runs as root through the host runner. Idempotent: the host timer runs every one daily.']);
		$fw_ri->submitbutton('btn_run_installer', 'Run installer', ['class' => 'btn btn-sm btn-outline-secondary']);
		$fw_ri->end_form();
		echo '</div>';
	}
	echo '</div>';
	$page->end_box();
	$shown++;
}

if ($shown === 0) {
	echo '<p class="text-muted">Nothing to ask this node here: its agent offers none of these questions.</p>';
}
