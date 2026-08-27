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
 * @version 1.1 - the copy-database forms are gone (A3); what remains is the record of database
 *                operations, which restores still produce
 * @version 1.0
 */

	// Copying a database node-to-node is retired (decision A3). It was a bespoke
	// operation that made one node trust another and moved a dump between them
	// under commands this plane composed; it is now expressed as what it always
	// was — a backup on the source, then a restore on the target, through the
	// backup target, with the restore human-present because restores are
	// destructive. Nothing is lost but the shortcut.

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
