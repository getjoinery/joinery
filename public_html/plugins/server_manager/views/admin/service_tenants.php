<?php
/**
 * Server Manager - Service Tenants
 * URL: /admin/server_manager/service_tenants
 *
 * Every self-hosted site renting this plane's outbound mail or backup storage,
 * one row per service, with the grant (a date) and release acts.
 *
 * @version 1.0 - specs/services_phase2_platform.md §10 item 5
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/admin_service_tenants_logic.php'));

$page_vars = process_logic(admin_service_tenants_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$state_labels = array(
	'unpaid'       => 'Unpaid — no date',
	'provisioning' => 'Provisioning',
	'active'       => 'Active',
	'suspended'    => 'Suspended',
	'released'     => 'Released',
);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'server-manager',
	'page_title'     => 'Service Tenants',
	'readable_title' => 'Service Tenants',
	'breadcrumbs'    => array(
		'Server Manager'  => '/admin/server_manager',
		'Service Tenants' => '',
	),
	'session'        => $session,
));

$page->begin_box(array());
?>

<p>Self-hosted sites using this plane's outbound email and backup storage. A site connects from its own
setup wizard, which creates its rows here <em>unpaid</em>; nothing works until you grant a paid-through
date. The reconcile compares the date every pass: <?php echo (int)$grace_days; ?> days of grace after it
passes, then the service stops and backup storage is kept <?php echo ServiceTenant::RETENTION_DAYS; ?> days
before it is pruned. A new date before then reactivates in place. Managed sites do not appear here.</p>

<?php if (!$mail_ready || $shelf_target === null): ?>
<div class="alert alert-warning">
	<?php if (!$mail_ready): ?>
		<strong>Outbound mail is not configured</strong> — no SMTP2GO master key is set on the
		<a href="/admin/server_manager/provisioning_setup">Provisioning Setup</a> page, so a mail enrol is refused.
	<?php endif; ?>
	<?php if ($shelf_target === null): ?>
		<strong>No backup storage target</strong> — set <em>Backup storage target</em> in the Server Manager settings
		(or keep exactly one enabled backup target), or a backup storage enrol is refused.
	<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($total > $limit): ?>
	<p class="text-muted">Showing <?php echo (int)$limit; ?> of <?php echo (int)$total; ?> rows.</p>
<?php endif; ?>

<?php if (count($tenants) === 0): ?>
	<p class="text-muted">No site has connected yet.</p>
<?php else: ?>
<table class="table table-sm">
	<thead><tr>
		<th>Site</th><th>Account</th><th>Service</th><th>State</th><th>Figure</th>
		<th>Paid through</th><th>Ladder</th><th>Grant</th><th></th>
	</tr></thead>
	<tbody>
	<?php foreach ($tenants as $t):
		$row = $t['row']; $s = $t['status']; $id = (int)$row->key; ?>
		<tr>
			<td><strong><?php echo htmlspecialchars($row->get('svt_host')); ?></strong><br>
				<small class="text-muted"><?php echo htmlspecialchars($row->get('svt_slug')); ?>
				&middot; key #<?php echo (int)$row->get('svt_apk_api_key_id'); ?></small></td>
			<td><?php echo htmlspecialchars($t['account']); ?></td>
			<td><?php echo htmlspecialchars($s['service']); ?>
				<?php if ($s['service'] === 'mail' && $s['domain'] !== ''): ?><br>
					<small class="text-muted"><?php echo htmlspecialchars($s['domain'] . ' · ' . $s['domain_state']); ?></small>
				<?php endif; ?></td>
			<td><?php echo htmlspecialchars($state_labels[$s['state']] ?? $s['state']); ?>
				<?php if ($s['notice'] !== ''): ?><br><small class="text-muted"><?php echo htmlspecialchars($s['notice']); ?></small><?php endif; ?></td>
			<td><?php echo htmlspecialchars($s['used_label'] . ' of ' . $s['allowance_label']); ?>
				<?php if ($s['percent'] >= JoineryServices::WARN_PERCENT): ?><br><small class="text-warning"><?php echo (int)$s['percent']; ?>%</small><?php endif; ?></td>
			<td><?php echo $s['paid_until'] ? htmlspecialchars($row->get_local('svt_paid_until', 'M j, Y')) : '—'; ?></td>
			<td><small>
				<?php if ($row->get('svt_lapse_time')): ?>lapsed <?php echo htmlspecialchars($row->get_local('svt_lapse_time', 'M j')); ?>,
					grace ends <?php echo htmlspecialchars($t['grace_ends'] ? LibraryFunctions::convert_time($t['grace_ends'], 'UTC', $session->get_timezone(), 'M j') : '—'); ?><br><?php endif; ?>
				<?php if ($row->get('svt_revoked_time')): ?>stopped <?php echo htmlspecialchars($row->get_local('svt_revoked_time', 'M j, Y')); ?><br><?php endif; ?>
				<?php if ($row->get('svt_prune_after_time')): ?>prune after <?php echo htmlspecialchars($row->get_local('svt_prune_after_time', 'M j, Y')); ?><br><?php endif; ?>
				<?php if ($row->get('svt_pruned_time')): ?>pruned <?php echo htmlspecialchars($row->get_local('svt_pruned_time', 'M j, Y')); ?><br><?php endif; ?>
				<?php if ($row->get('svt_checked_time')): ?>checked <?php echo htmlspecialchars($row->get_local('svt_checked_time', 'M j H:i')); ?><?php endif; ?>
			</small></td>
			<td>
				<?php
				$fw = $page->getFormWriter('grant_' . $id, array('action' => $page_url, 'method' => 'POST'));
				$fw->begin_form();
				$fw->hiddeninput('action', '', array('value' => 'grant'));
				$fw->hiddeninput('svt_service_tenant_id', '', array('value' => $id));
				$fw->dateinput('paid_until', '', array('value' => $s['paid_until'] ? substr((string)$s['paid_until'], 0, 10) : ''));
				$fw->submitbutton('btn_grant_' . $id, 'Grant', array('class' => 'btn btn-sm btn-primary'));
				$fw->end_form();
				?>
			</td>
			<td>
				<?php if ($s['state'] !== 'released' && $s['state'] !== 'unpaid'): ?>
					<?php echo AdminPage::action_button('Release', $page_url, array(
						'hidden'  => array('action' => 'release', 'svt_service_tenant_id' => $id),
						'confirm' => 'Release ' . $row->get('svt_host') . '\'s ' . ($s['service'] === 'shelf' ? 'backup storage' : $s['service']) . '? Mail closes now; backup storage is kept '
							. ServiceTenant::RETENTION_DAYS . ' days and then pruned.')); ?>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<?php
$page->end_box();
$page->admin_footer();
?>
