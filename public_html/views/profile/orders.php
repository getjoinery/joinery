<?php
/**
 * Full order history sub-page.
 *
 * @version 1.0
 */

require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('orders_profile_logic.php', 'logic'));

$page_vars = process_logic(orders_profile_logic($_GET, $_POST));

$page = new MemberPage();
$hoptions = array(
	'title' => 'My Orders',
	'readable_title' => 'My Orders',
	'breadcrumbs' => array(
		'Dashboard' => '/profile',
		'Orders' => '',
	),
);
$page->member_header($hoptions);

$session = $page_vars['session'];
?>
<div class="jy-ui">

<div class="card mb-3">
	<div class="card-header bg-body-tertiary">
		<h6 class="mb-0">Order History</h6>
	</div>
	<div class="card-body">
		<?php if (empty($page_vars['orders']) || $page_vars['numorders'] == 0): ?>
			<p style="color:var(--muted);margin:0;">No orders found.</p>
		<?php else: ?>
			<?php $i = 0; foreach ($page_vars['orders'] as $order): ?>
			<div style="<?php echo $i > 0 ? 'border-top:1px solid var(--border-color);' : ''; ?>padding:0.875rem 0;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
				<div>
					<p style="margin:0;font-weight:600;">Order #<?php echo htmlspecialchars($order->key); ?></p>
					<p style="margin:0;font-size:0.8125rem;color:var(--muted);">$<?php echo htmlspecialchars($order->get('ord_total_cost')); ?></p>
				</div>
				<div style="text-align:right;">
					<p style="margin:0;font-size:0.8125rem;color:var(--muted);">
						<?php echo LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $session->get_timezone(), 'M j, Y'); ?>
					</p>
				</div>
			</div>
			<?php $i++; endforeach; ?>
		<?php endif; ?>
	</div>
	<?php if ($page_vars['numorders'] > 0): ?>
	<div class="card-footer bg-body-tertiary" style="font-size:0.8125rem;color:var(--muted);display:flex;justify-content:space-between;align-items:center;">
		<span><?php echo $page_vars['numorders']; ?> order<?php echo $page_vars['numorders'] != 1 ? 's' : ''; ?></span>
		<?php
		$pager = $page_vars['pager'];
		if ($pager->num_records() > $pager->num_per_page()):
		?>
		<div class="pagination">
			<?php if ($pager->is_valid_page('-1')): ?>
				<a href="<?php echo htmlspecialchars($pager->get_url('-1')); ?>" class="page-link">&laquo;</a>
			<?php else: ?>
				<span class="page-link disabled">&laquo;</span>
			<?php endif; ?>
			<?php for ($p = 1; $p <= $pager->total_pages(); $p++): ?>
				<?php if ($p == $pager->current_page()): ?>
					<span class="page-link active"><?php echo $p; ?></span>
				<?php else: ?>
					<a href="<?php echo htmlspecialchars($pager->get_url($p)); ?>" class="page-link"><?php echo $p; ?></a>
				<?php endif; ?>
			<?php endfor; ?>
			<?php if ($pager->is_valid_page('+1')): ?>
				<a href="<?php echo htmlspecialchars($pager->get_url('+1')); ?>" class="page-link">&raquo;</a>
			<?php else: ?>
				<span class="page-link disabled">&raquo;</span>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>
</div>

</div>
<?php
$page->member_footer(array('track' => TRUE));
?>
