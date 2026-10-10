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
 * @version 1.7 - Publish Release on this management node itself is not offered while its repositories have uncommitted work
 * @version 1.6 - Publish Release on This Node offers Deploy to all managed nodes, as the Upgrades page does
 * @version 1.5 - Upgrade All Sites on This Host is offered only when more than one site shares the host
 * @version 1.4 - the fact arrives at poll (agent 1.37.0), so the unreported sentence says the next poll settles it
 * @version 1.3 - Publish Release is offered only to a management node (the node's own report that Server
 *                Manager is active); an agent that has not reported either way gets one sentence
 * @version 1.2 - a node that hosts no site gets one sentence in place of the version box and both
 *                apply buttons: there is no release to apply, and its agent updates itself
 * @version 1.1 - Publish release on this node: for a node whose agent carries the publish_upgrade
 *                primitive, the version it runs and release notes, dispatched as the node's own
 *                agent's job (specs/publish_as_node_action.md)
 * @version 1.0
 */

	// A machine with no site: nothing here applies to it. Its agent keeps
	// itself current from this management node, and the scripts it runs
	// arrive in the support bundle on the same clock.
	if (!$node->hosts_site()) {
		$page->begin_box(['title' => 'Updates']);
		echo '<p class="mb-0">This node hosts no Joinery site, so there is no release to apply here. '
			. 'Its agent (' . htmlspecialchars((string)($node->get('mgn_agent_version') ?: 'version unknown'))
			. ') updates itself from this management node, and the scripts it runs arrive in the '
			. 'support bundle the same way.</p>';
		$page->end_box();
		return;
	}

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
<?php
	// Sites that share this machine: the same placement record, or the same
	// address (the action refuses the latter until they are grouped). One site
	// alone has no "all" to upgrade, so the button is not offered.
	$host_sites = [$node->key => true];
	$host_id = (int)$node->get('mgn_mgh_managed_host_id');
	$sharing = $host_id
		? new MultiManagedNode(['host_id' => $host_id, 'enabled' => true, 'deleted' => false])
		: new MultiManagedNode(['host' => (string)$node->get('mgn_host'), 'enabled' => true, 'deleted' => false]);
	foreach ($sharing as $other) {
		if ($other->hosts_site()) $host_sites[$other->key] = true;
	}
	if ($host_id) {
		foreach (new MultiManagedNode(['host' => (string)$node->get('mgn_host'), 'enabled' => true, 'deleted' => false]) as $other) {
			if ($other->hosts_site()) $host_sites[$other->key] = true;
		}
	}
	if (count($host_sites) > 1):
?>
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
<?php endif; ?>
<?php
	$page->end_box();

	// A management node builds and signs its own release, as its own agent.
	// This is how a management node that is itself a node of this one (a relay
	// serving releases to its fleet) gets published: from here, by the plane
	// that manages it, with no shell on either machine. A plain site never
	// publishes, so it sees nothing here; the node itself says which it is
	// (server_manager on its poll, server_manager_active in a check_status report).
	if (JobCommandBuilder::can_publish_release($node)) {
		$running = (string)$node->get('mgn_joinery_version');
		$pub_major = $pub_minor = $pub_patch = 0;
		if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $running, $pm)) {
			$pub_major = (int)$pm[1]; $pub_minor = (int)$pm[2]; $pub_patch = (int)$pm[3];
		}
		$publish_blocked = $node->is_self() ? AgentDistPublisher::uncommittedAdvice()['lines'] : array();
		$page->begin_box(['title' => 'Publish Release on This Node']);
		if ($publish_blocked) {
			echo '<div class="jy-callout jy-callout-warning">A release is built from committed work, so there is nothing to publish until these are committed and pushed:<ul class="mb-0"><li>'
				. implode('</li><li>', array_map('htmlspecialchars', $publish_blocked)) . '</li></ul>Reload this page afterwards.</div>';
			$page->end_box();
		} else {
		echo '<p class="text-muted">Builds and signs release archives from the tree this node runs, as a job of '
			. 'its own agent. The version defaults to what the node is running, which is what a site that received '
			. 'its code republishes; the node refuses a number it may not mint.</p>';
		$pub_form = $page->getFormWriter('publish_on_node_form');
		$pub_form->begin_form();
		$pub_form->hiddeninput('action', '', ['value' => 'publish_upgrade']);
		$pub_form->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$pub_form->numberinput('version_major', 'Major', ['required' => true, 'value' => $pub_major, 'min' => 0]);
		$pub_form->numberinput('version_minor', 'Minor', ['required' => true, 'value' => $pub_minor, 'min' => 0]);
		$pub_form->numberinput('version_patch', 'Patch', ['required' => true, 'value' => $pub_patch, 'min' => 0]);
		$pub_form->textarea('release_notes', 'Release notes', ['required' => true, 'rows' => 3,
			'placeholder' => 'What this release carries...']);
		// Only the management node that serves the fleet's release can roll it out.
		if ($node->is_self()) {
			$deploy_plan = StagedRolloutRunner::deploy_all_plan();
			$deploy_names = array_map(function ($n) { return (string)$n->get('mgn_name'); }, $deploy_plan['nodes']);
			$deploy_help = $deploy_names
				? 'Once the publish completes, the release is rolled out to ' . implode(', ', $deploy_names)
					. ', one node at a time in that order, stopping at the first node whose upgrade fails.'
				: 'No managed node can take a rollout right now.';
			foreach ($deploy_plan['left_out'] as $lo) {
				$deploy_help .= ' Left out: ' . $lo['name'] . ' (' . $lo['reason'] . ').';
			}
			$pub_form->checkboxinput('deploy_all', 'Deploy to all managed nodes', [
				'checked'  => false,
				'helptext' => $deploy_help,
			]);
		}
		$pub_form->submitbutton('btn_publish_on_node', 'Publish on ' . htmlspecialchars($node_name));
		$pub_form->end_form();
		$page->end_box();
		}
	} elseif (JobCommandBuilder::has_primitive($node, 'publish_upgrade') && !$node->reports_management_status()) {
		// The agent could run a publish but has not yet said whether this is
		// a management node — an agent that predates the fact. Say so rather
		// than silently hiding an action the operator may be looking for.
		$page->begin_box(['title' => 'Publish Release on This Node']);
		echo '<p class="mb-0 text-muted">Publishing is offered once this node reports whether the Server Manager '
			. 'plugin is active there, which its agent does on every poll from 1.37.0 (this one is '
			. htmlspecialchars((string)($node->get('mgn_agent_version') ?: 'not reporting a version'))
			. '). Its next poll settles it.</p>';
		$page->end_box();
	}

