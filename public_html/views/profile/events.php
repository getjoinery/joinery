<?php
/**
 * Full event history sub-page.
 *
 * @version 1.0
 */

require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('events_profile_logic.php', 'logic'));

$page_vars = process_logic(events_profile_logic($_GET, $_POST));

$page = new MemberPage();
$hoptions = array(
	'title' => 'My Events',
	'readable_title' => 'My Events',
	'breadcrumbs' => array(
		'Dashboard' => '/profile',
		'Events' => '',
	),
);
$page->member_header($hoptions);

$status_filter = $page_vars['status_filter'];
$session = $page_vars['session'];
?>

<!-- Status filter tabs -->
<ul class="nav-tabs" style="margin-bottom:1.5rem;">
	<li><a class="nav-link<?php echo $status_filter == 'all' ? ' active' : ''; ?>" href="/profile/events">All</a></li>
	<li><a class="nav-link<?php echo $status_filter == 'active' ? ' active' : ''; ?>" href="/profile/events?status=active">Active</a></li>
	<li><a class="nav-link<?php echo $status_filter == 'completed' ? ' active' : ''; ?>" href="/profile/events?status=completed">Completed</a></li>
	<li><a class="nav-link<?php echo $status_filter == 'expired' ? ' active' : ''; ?>" href="/profile/events?status=expired">Expired</a></li>
	<li><a class="nav-link<?php echo $status_filter == 'canceled' ? ' active' : ''; ?>" href="/profile/events?status=canceled">Canceled</a></li>
</ul>

<div class="card mb-3">
	<div class="card-header bg-body-tertiary">
		<h6 class="mb-0">Events &amp; Courses</h6>
	</div>
	<div class="card-body">
		<?php if (empty($page_vars['event_registrations'])): ?>
			<p style="color:var(--muted);margin:0;">
				<?php echo $status_filter == 'all' ? 'You have no event registrations.' : 'No ' . htmlspecialchars($status_filter) . ' events found.'; ?>
			</p>
		<?php else: ?>
			<?php foreach ($page_vars['event_registrations'] as $i => $event): ?>
			<div style="<?php echo $i > 0 ? 'border-top:1px solid var(--border-color);' : ''; ?>padding:0.875rem 0;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
				<div style="flex:1;min-width:0;">
					<h6 style="margin:0 0 0.25rem;">
						<a href="<?php echo htmlspecialchars($event['event_link']); ?>"><?php echo htmlspecialchars($event['event_name']); ?></a>
					</h6>
					<?php if ($event['event_time']): ?>
					<p style="margin:0;font-size:0.8125rem;color:var(--muted);"><?php echo $event['event_time']; ?></p>
					<?php endif; ?>
				</div>
				<div style="flex-shrink:0;display:flex;align-items:center;gap:0.5rem;">
					<?php
					$badge_bg = '#d4edda'; $badge_color = '#155724';
					if ($event['event_status'] == 'Expired' || $event['event_status'] == 'Canceled' || $event['event_status'] == 'Completed') {
						$badge_bg = '#f8f9fa'; $badge_color = '#6c757d';
					}
					$badge_text = $event['event_status'];
					if ($event['event_status'] == 'Active' && $event['event_expires']) {
						$badge_text = 'Expires ' . htmlspecialchars($event['event_expires']);
					}
					?>
					<span class="badge" style="background:<?php echo $badge_bg; ?>;color:<?php echo $badge_color; ?>;"><?php echo $badge_text; ?></span>
				</div>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php if ($page_vars['num_events'] > 0): ?>
	<div class="card-footer bg-body-tertiary" style="font-size:0.8125rem;color:var(--muted);display:flex;justify-content:space-between;align-items:center;">
		<span><?php echo $page_vars['num_events']; ?> event<?php echo $page_vars['num_events'] != 1 ? 's' : ''; ?></span>
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

<?php
$page->member_footer(array('track' => TRUE));
?>
