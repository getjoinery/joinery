<?php
/**
 * Server Manager - Server Transfers
 * URL: /admin/server_manager/transfers
 *
 * Managed sites moving to their customers' own Linode accounts, one row per
 * transfer (specs/managed_to_self_hosted_transfer.md §6): where each stands,
 * the code's expiry, what Linode last said, the last email, the last check and
 * any to-do. The buttons call the transfer_operator API action. The code itself
 * is never shown here: only the customer's own signed-in page shows it.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/admin_transfers_logic.php'));

$page_vars = process_logic(admin_transfers_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page_url = '/admin/server_manager/transfers';
$tz = $session->get_timezone();
$when = function ($utc, $format = 'M j, H:i') use ($tz) {
	$utc = trim((string)$utc);
	return $utc === '' ? '' : LibraryFunctions::convert_time($utc, 'UTC', $tz, $format);
};

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'server-manager',
	'page_title'     => 'Server Transfers',
	'readable_title' => 'Server Transfers',
	'breadcrumbs'    => array(
		'Server Manager'   => '/admin/server_manager',
		'Server Transfers' => '',
	),
	'session'        => $session,
));

$page->begin_box(array());
?>

<p>Managed sites moving to their customers' own Linode accounts. The server moves running, with its disks and
addresses, through Linode's Service Transfer: we issue a code, the customer redeems it in their own Cloud Manager,
and Linode moves it within about three hours. Once it is done, hosting is cancelled and the site's mail and backups
carry on as a Services subscription. Start a move from a site's node page, or here once a customer asks.</p>

<p>
	<?php foreach (array('open' => 'Open', 'done' => 'Done', 'all' => 'All') as $key => $label): ?>
		<?php if ($key === $filter && !$only): ?><strong><?php echo $label; ?></strong>
		<?php else: ?><a href="<?php echo $page_url . '?filter=' . $key; ?>"><?php echo $label; ?></a><?php endif; ?>
		&nbsp;
	<?php endforeach; ?>
</p>

<p id="transferNotice" class="alert" role="status" aria-live="polite" hidden></p>

<?php if (count($transfers) === 0): ?>
	<p class="text-muted">No transfers <?php echo $filter === 'all' ? 'yet' : 'here'; ?>.</p>
<?php else: ?>
<table class="table table-sm">
	<thead><tr>
		<th>Site</th><th>State</th><th>Code expires</th><th>Linode said</th><th>Last email</th><th>Check</th><th></th>
	</tr></thead>
	<tbody>
	<?php foreach ($transfers as $t):
		$row = $t['row']; $id = (int)$row->key; $state = $row->state(); $check = $t['check']; ?>
		<tr>
			<td><strong><?php echo htmlspecialchars($t['domain']); ?></strong><br>
				<small class="text-muted"><?php echo htmlspecialchars($t['customer']); ?>
				<?php if ($t['node_id']): ?>&middot; <a href="/admin/server_manager/node_detail?mgn_managed_node_id=<?php echo $t['node_id']; ?>">node #<?php echo $t['node_id']; ?></a><?php endif; ?>
				&middot; instance <?php echo htmlspecialchars((string)$row->get('itx_instance_id')); ?>
				&middot; asked by <?php echo htmlspecialchars((string)$row->get('itx_requested_by')); ?></small></td>
			<td><?php echo htmlspecialchars($row->state_label()); ?>
				<?php if ($row->get('itx_deletion_withdrawn')): ?><br><small class="text-muted">was shut down; deletion withdrawn</small><?php endif; ?>
				<?php if ($state === 'finishing' && $row->get('itx_finish_step')): ?><br><small class="text-muted">finished step: <?php echo htmlspecialchars((string)$row->get('itx_finish_step')); ?></small><?php endif; ?>
				<?php if ($row->get('itx_error')): ?><br><small class="text-danger"><?php echo htmlspecialchars((string)$row->get('itx_error')); ?></small><?php endif; ?>
				<?php if ($row->get('itx_operator_todo')): ?><br><small class="text-warning"><strong>To do:</strong> <?php echo nl2br(htmlspecialchars((string)$row->get('itx_operator_todo'))); ?></small><?php endif; ?></td>
			<td><?php echo $state === 'code_issued' ? htmlspecialchars($when($row->get('itx_token_expiry'))) : '—'; ?></td>
			<td><?php echo htmlspecialchars((string)$row->get('itx_linode_status') ?: '—'); ?>
				<?php if ($row->get('itx_linode_checked_time')): ?><br><small class="text-muted"><?php echo htmlspecialchars($when($row->get('itx_linode_checked_time'))); ?></small><?php endif; ?></td>
			<td><?php echo $t['email_kind'] !== '' ? htmlspecialchars(str_replace('_', ' ', $t['email_kind'])) . '<br><small class="text-muted">' . htmlspecialchars($when($t['email_time'])) . '</small>' : '—'; ?></td>
			<td><?php if ($check): ?>
				<?php echo (int)$check['blockers']; ?> blocker(s), <?php echo (int)$check['warnings']; ?> warning(s)
				<br><small class="text-muted"><?php echo htmlspecialchars($when($check['checked_time'] ?? '')); ?></small>
				<?php if ((int)$check['blockers'] > 0): ?><br><small class="text-danger"><?php echo htmlspecialchars(InstanceTransfers::blocker_summary($check)); ?></small><?php endif; ?>
				<?php else: ?>—<?php endif; ?></td>
			<td class="text-nowrap">
				<?php if ($row->is_open() && $t['provision_id']): ?>
					<button type="button" class="btn btn-sm btn-outline-secondary" data-transfer-do="check" data-provision="<?php echo $t['provision_id']; ?>">Re-check</button>
				<?php endif; ?>
				<?php if ($state === 'requested'): ?>
					<button type="button" class="btn btn-sm btn-primary" data-transfer-do="start" data-provision="<?php echo $t['provision_id']; ?>">Start</button>
				<?php endif; ?>
				<?php if (in_array($state, array('requested', 'invited', 'failed'), true)): ?>
					<button type="button" class="btn btn-sm btn-outline-primary" data-transfer-do="issue_code" data-transfer="<?php echo $id; ?>"
						data-confirm="Issue the transfer code now? The customer sees it on their sites page; it works for 24 hours.">Issue code now</button>
				<?php endif; ?>
				<?php if (in_array($state, array('invited', 'code_issued', 'failed', 'done'), true)): ?>
					<button type="button" class="btn btn-sm btn-outline-secondary" data-transfer-do="resend" data-transfer="<?php echo $id; ?>">Resend email</button>
				<?php endif; ?>
				<?php if ($row->cancelable()): ?>
					<button type="button" class="btn btn-sm btn-outline-danger" data-transfer-do="cancel" data-transfer="<?php echo $id; ?>"
						data-confirm="Cancel this move? A code that is out is withdrawn at Linode.">Cancel</button>
				<?php endif; ?>
				<?php if ($row->get('itx_operator_todo')): ?>
					<button type="button" class="btn btn-sm btn-outline-success" data-transfer-do="todo_done" data-transfer="<?php echo $id; ?>"
						data-confirm="Mark the to-do as done?">To-do done</button>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<script>
document.querySelectorAll('[data-transfer-do]').forEach(function (button) {
	button.addEventListener('click', function () {
		if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) { return; }
		var notice = document.getElementById('transferNotice');
		var params = { 'do': button.getAttribute('data-transfer-do') };
		if (button.dataset.provision) { params.provision_id = parseInt(button.dataset.provision, 10); }
		if (button.dataset.transfer) { params.transfer_id = parseInt(button.dataset.transfer, 10); }
		button.disabled = true;
		joineryApi.post('server_manager/transfer_operator', params).then(function () {
			window.location.reload();
		}).catch(function (err) {
			button.disabled = false;
			notice.hidden = false;
			notice.className = 'alert alert-danger';
			notice.textContent = (err && err.message) ? err.message : 'That did not work.';
		});
	});
});
</script>

<?php
$page->end_box();
$page->admin_footer();
?>
