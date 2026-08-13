<?php
/**
 * node_detail — Jobs tab partial.
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

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);
	// Whitelist sort column + direction — order_by interpolates the column raw (S-6).
	$sort_whitelist = ['mjb_id', 'mjb_job_type', 'mjb_status', 'mjb_create_time',
		'mjb_started_time', 'mjb_completed_time'];
	$sort = LibraryFunctions::fetch_variable_local($_GET, 'sort', 'mjb_id');
	if (!in_array($sort, $sort_whitelist, true)) { $sort = 'mjb_id'; }
	$sdirection = strtoupper(LibraryFunctions::fetch_variable_local($_GET, 'sdirection', 'DESC'));
	if ($sdirection !== 'ASC' && $sdirection !== 'DESC') { $sdirection = 'DESC'; }

	$search_criteria = ['deleted' => false, 'node_id' => $node->key];
	if (isset($_GET['status']) && $_GET['status']) {
		$search_criteria['status'] = $_GET['status'];
	}
	if (isset($_GET['job_type']) && $_GET['job_type']) {
		$search_criteria['job_type'] = $_GET['job_type'];
	}

	$jobs = new MultiManagementJob($search_criteria, [$sort => $sdirection], $numperpage, $offset);
	$numrecords = $jobs->count_all();
	$jobs->load();
?>
	<div class="card mb-3">
		<div class="card-body">
			<?php
			$status_options = ['' => 'All'];
			foreach (['pending', 'running', 'completed', 'failed', 'cancelled'] as $s) {
				$status_options[$s] = ucfirst($s);
			}
			$type_options = ['' => 'All'];
			foreach (ManagementJob::filterTypes() as $t) {
				$type_options[$t] = str_replace('_', ' ', $t);
			}
			$fw_filter = $page->getFormWriter('jobs_filter_form', ['method' => 'GET']);
			$fw_filter->begin_form();
			$fw_filter->hiddeninput('mgn_id', '', ['value' => $node->key]);
			$fw_filter->hiddeninput('tab', '', ['value' => 'jobs']);
			$fw_filter->dropinput('status', 'Status', [
				'options'      => $status_options,
				'value'        => $_GET['status'] ?? '',
				'empty_option' => false,
			]);
			$fw_filter->dropinput('job_type', 'Type', [
				'options'      => $type_options,
				'value'        => $_GET['job_type'] ?? '',
				'empty_option' => false,
			]);
			$fw_filter->submitbutton('btn_filter', 'Filter', ['class' => 'btn btn-sm btn-primary']);
			$fw_filter->end_form();
			?>
			<a href="<?php echo $base_url; ?>&tab=jobs" class="btn btn-sm btn-outline-secondary">Clear</a>
			<a href="/admin/server_manager/jobs" class="btn btn-sm btn-outline-secondary ms-2">View All Jobs</a>
		</div>
	</div>
<?php
	$headers = ['ID', 'Type', 'Status', 'Progress', 'Created', 'Duration'];
	$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);
	$table_options = [
		'title' => 'Jobs',
		'sortoptions' => [
			'ID' => 'mjb_id',
			'Type' => 'mjb_job_type',
			'Status' => 'mjb_status',
		],
	];
	$page->tableheader($headers, $table_options, $pager);

	foreach ($jobs as $job) {
		$status_class = match($job->get('mjb_status')) {
			'completed' => 'success', 'failed' => 'danger', 'running' => 'primary',
			'cancelled' => 'secondary', default => 'warning',
		};
		$progress = $job->get('mjb_current_step') . '/' . $job->get('mjb_total_steps');
		$duration = '';
		if ($job->get('mjb_started_time') && $job->get('mjb_completed_time')) {
			$diff = strtotime($job->get('mjb_completed_time')) - strtotime($job->get('mjb_started_time'));
			$duration = $diff < 60 ? "{$diff}s" : round($diff / 60, 1) . 'm';
		} elseif ($job->get('mjb_started_time')) {
			$diff = time() - strtotime($job->get('mjb_started_time'));
			$duration = ($diff < 60 ? "{$diff}s" : round($diff / 60, 1) . 'm') . '...';
		}

		$rowvalues = [];
		$rowvalues[] = '<a href="/admin/server_manager/job_detail?job_id=' . $job->key . '">#' . $job->key . '</a>';
		$rowvalues[] = htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type')));
		$rowvalues[] = '<span class="badge bg-' . $status_class . '">' . htmlspecialchars($job->get('mjb_status')) . '</span>';
		$rowvalues[] = $progress;
		$rowvalues[] = $job->get_local('mjb_create_time', 'M j, g:i A');
		$rowvalues[] = $duration;

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);

