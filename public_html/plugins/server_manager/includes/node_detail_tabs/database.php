<?php
/**
 * node_detail — Database tab partial.
 *
 * Included by views/admin/node_detail.php in the shell's scope; the shell
 * owns node loading, the tab whitelist, and the permission gate. Lives under
 * includes/ (not views/) so it is not reachable as a standalone URL.
 *
 * In scope: $node, $page, $session, $base_url, $node_name, $page_regex,
 * $skip_joinery, $tab.
 *
 * @version 1.0
 */

	// Load cached status data (for Internal Copy db_list)
	$status_data = $node->get('mgn_last_status_data');
	if (is_string($status_data)) $status_data = json_decode($status_data, true);

	// Load other nodes for the cross-node copy source dropdown
	$other_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
	$other_nodes->load();

	$pageoptions = ['title' => 'Copy Database to ' . $node_name];
	$page->begin_box($pageoptions);

	// Build source node options (exclude current node)
	$copy_source_options = ['' => 'Select source...'];
	foreach ($other_nodes as $n) {
		if ($n->key != $node->key) {
			$copy_source_options[$n->key] = $n->get('mgn_name') . ' (' . $n->get('mgn_slug') . ')';
		}
	}
	$fw_copy = $page->getFormWriter('copy_db_form');
	$fw_copy->begin_form();
	$fw_copy->hiddeninput('action', '', ['id' => 'copy_db_action', 'value' => 'copy_database']);
	$fw_copy->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
	$fw_copy->dropinput('source_node_id', 'Source Site', [
		'required'     => true,
		'options'      => $copy_source_options,
		'empty_option' => false,
		'helptext'     => 'Copies into: ' . htmlspecialchars($node_name) . ' (' . htmlspecialchars($node->get('mgn_slug')) . ')',
	]);
	$fw_copy->submitbutton('btn_copy_db', 'Copy Database', [
		'class'   => 'btn btn-danger',
		'onclick' => 'event.preventDefault(); JoineryModal.confirm(\'Are you sure? This will overwrite the database on ' . addslashes($node_name) . '.\', function(){ document.getElementById(\'copy_db_form\').submit(); })',
	]);
	$fw_copy->end_form();
	$page->end_box();

	$pageoptions = ['title' => 'Internal Copy'];
	$page->begin_box($pageoptions);
	$db_list = $status_data['db_list'] ?? [];
	$current_db = $status_data['current_db'] ?? null;
	$skip_dbs = [$current_db, 'postgres'];
	$other_dbs = array_values(array_filter($db_list, fn($d) => !in_array($d, $skip_dbs, true)));
?>
	<?php if (empty($db_list)): ?>
		<p class="text-muted">Run <strong>Check Status</strong> from the Overview tab to discover databases on this server.</p>
	<?php elseif (empty($other_dbs)): ?>
		<p class="text-muted">No other databases found on this server<?php echo $current_db ? " (current: <strong>" . htmlspecialchars($current_db) . "</strong>)" : ''; ?>.</p>
	<?php else:
		$internal_db_options = ['' => 'Select source...'];
		foreach ($other_dbs as $db) {
			$internal_db_options[$db] = $db;
		}
		$fw_icopy = $page->getFormWriter('internal_copy_form');
		$fw_icopy->begin_form();
		$fw_icopy->hiddeninput('action', '', ['id' => 'icopy_db_action', 'value' => 'copy_database_local']);
		$fw_icopy->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$fw_icopy->dropinput('source_db_name', 'Source Database', [
			'required'     => true,
			'options'      => $internal_db_options,
			'empty_option' => false,
			'helptext'     => 'Copies into: ' . htmlspecialchars($current_db ?: $node_name),
		]);
		$fw_icopy->submitbutton('btn_icopy_db', 'Copy Database', [
			'class'   => 'btn btn-danger',
			'onclick' => 'event.preventDefault(); JoineryModal.confirm(\'Are you sure? This will overwrite the database on ' . addslashes($node_name) . '.\', function(){ document.getElementById(\'internal_copy_form\').submit(); })',
		]);
		$fw_icopy->end_form();
	endif; ?>
<?php
	$page->end_box();

	// Recent database ops for this node
	$db_jobs = new MultiManagementJob(['deleted' => false, 'node_id' => $node->key], ['mjb_id' => 'DESC'], 20);
	$db_jobs->load();

	$pageoptions = ['title' => 'Recent Database Operations'];
	$page->begin_box($pageoptions);
?>
	<table class="table table-striped table-sm">
		<thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Created</th><th>Duration</th></tr></thead>
		<tbody>
			<?php
			$count = 0;
			foreach ($db_jobs as $job):
				if (!in_array($job->get('mjb_job_type'), ManagementJob::databaseOpTypes(), true)) continue;
				$count++;
				if ($count > 10) break;
				$sc = match($job->get('mjb_status')) {
					'completed' => 'success', 'failed' => 'danger', 'running' => 'primary', default => 'warning',
				};
				$dur = '';
				if ($job->get('mjb_started_time') && $job->get('mjb_completed_time')) {
					$d = strtotime($job->get('mjb_completed_time')) - strtotime($job->get('mjb_started_time'));
					$dur = $d < 60 ? "{$d}s" : round($d / 60, 1) . 'm';
				}
			?>
				<tr>
					<td><a href="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>">#<?php echo $job->key; ?></a></td>
					<td><?php echo htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type'))); ?></td>
					<td><span class="badge bg-<?php echo $sc; ?>"><?php echo htmlspecialchars($job->get('mjb_status')); ?></span></td>
					<td><?php echo $job->get_local('mjb_create_time', 'M j, g:i A'); ?></td>
					<td><?php echo $dur; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ($count === 0): ?>
				<tr><td colspan="5" class="text-muted text-center">No database operations yet</td></tr>
			<?php endif; ?>
		</tbody>
	</table>
<?php
	$page->end_box();
?>

<?php
