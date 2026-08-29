<?php
/**
 * node_detail — Updates tab partial.
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

	// Get local version
	$settings = Globalvars::get_instance();
	$local_version = $settings->get_setting('system_version') ?: '-';

	$node_version = $node->get('mgn_joinery_version') ?: 'Unknown';
	$up_to_date = ($node_version === $local_version);

	$pageoptions = ['title' => 'Version Status'];
	$page->begin_box($pageoptions);
?>
	<div class="row mb-3">
		<div class="col-md-4">
			<strong>Current Version:</strong> <?php echo htmlspecialchars($node_version); ?>
		</div>
		<div class="col-md-4">
			<strong>Management Node Version:</strong> <?php echo htmlspecialchars($local_version); ?>
		</div>
		<div class="col-md-4">
			<?php if ($up_to_date): ?>
				<span class="badge bg-success">Up to date</span>
			<?php else: ?>
				<span class="badge bg-warning">Update available</span>
			<?php endif; ?>
		</div>
	</div>
	<div class="mt-2">
		<form method="post" class="svm-inline-form" id="apply_update_form">
			<input type="hidden" name="action" value="apply_update">
			<?php echo SmAdminCsrf::field(); ?>
			<button type="button" class="btn btn-sm btn-outline-primary" onclick="JoineryModal.confirm('Apply update to ' + smNodeName + '?', function(){ document.getElementById('apply_update_form').submit(); })">Apply Update</button>
		</form>
	</div>
	<hr>
	<div class="mt-3">
		<form method="post" class="svm-inline-form" id="upgrade_all_form">
			<input type="hidden" name="action" value="apply_update_all_on_host">
			<?php echo SmAdminCsrf::field(); ?>
			<button type="button" class="btn btn-sm btn-warning" onclick="JoineryModal.confirm('Queue an upgrade job for every enabled site on host <?php echo htmlspecialchars($node->get('mgn_host')); ?>?', function(){ document.getElementById('upgrade_all_form').submit(); })">Upgrade All Sites on This Host</button>
		</form>
		<p class="text-muted small mt-2 mb-0">
			Queues one independent upgrade job per enabled, non-deleted site that shares this host
			(<code><?php echo htmlspecialchars($node->get('mgn_host')); ?></code>).
			Jobs run as the agent picks them up; one site failing does not affect the others.
			Disable a site first to skip it.
		</p>
	</div>
<?php
	$page->end_box();

