<?php
/**
 * Notifications list page
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('notifications_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));

$page_vars = process_logic(notifications_logic($_GET, $_POST));

$page = new MemberPage();
$page->member_header([
	'title' => $page_vars['title'],
]);

?>

<div class="notifications-page">
	<?php if ($page_vars['numrecords'] > 0): ?>
	<div class="notifications-toolbar" style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
		<button type="button" id="mark-all-read-btn" class="btn btn-sm btn-outline">Mark all as read</button>
	</div>
	<?php endif; ?>

	<?php if ($page_vars['notifications']->count() === 0): ?>
		<p style="text-align:center;padding:2rem 0;color:#888;">No notifications yet.</p>
	<?php else: ?>
		<div class="notifications-list">
		<?php
		$session = SessionControl::get_instance();
		foreach ($page_vars['notifications'] as $ntf):
			$is_read = $ntf->get('ntf_is_read');
			$link = $ntf->get('ntf_link');
			$type = $ntf->get('ntf_type');
			$title = htmlspecialchars($ntf->get('ntf_title'), ENT_QUOTES, 'UTF-8');
			$body = htmlspecialchars($ntf->get('ntf_body') ?: '', ENT_QUOTES, 'UTF-8');
			$time = LibraryFunctions::convert_time(
				$ntf->get('ntf_create_time'), 'UTC',
				$session->get_timezone(), 'M j, Y g:i A'
			);
			$unread_class = $is_read ? '' : ' notification-unread';
		?>
			<div class="notification-item<?php echo $unread_class; ?>" data-id="<?php echo (int)$ntf->key; ?>">
				<div class="notification-content">
					<?php if ($link): ?>
						<a href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>" class="notification-link">
							<strong class="notification-title"><?php echo $title; ?></strong>
						</a>
					<?php else: ?>
						<strong class="notification-title"><?php echo $title; ?></strong>
					<?php endif; ?>
					<?php if ($body): ?>
						<p class="notification-body"><?php echo $body; ?></p>
					<?php endif; ?>
					<span class="notification-time"><?php echo htmlspecialchars($time, ENT_QUOTES, 'UTF-8'); ?></span>
					<span class="notification-type"><?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?></span>
				</div>
				<?php if (!$is_read): ?>
				<div class="notification-actions">
					<button type="button" class="btn btn-sm btn-outline mark-read-btn" data-id="<?php echo (int)$ntf->key; ?>">Mark read</button>
				</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		</div>

		<?php
		$pager = $page_vars['pager'];
		if ($pager && $pager->total_pages() > 1):
			$current = $pager->current_page();
			$total = $pager->total_pages();
		?>
		<div class="notifications-pager" style="display:flex;justify-content:center;gap:1rem;padding:1.5rem 0;">
			<?php if ($pager->is_valid_page('-1')): ?>
				<a href="<?php echo htmlspecialchars($pager->get_url('-1'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline">&laquo; Newer</a>
			<?php endif; ?>
			<span style="line-height:2;">Page <?php echo $current; ?> of <?php echo $total; ?></span>
			<?php if ($pager->is_valid_page('+1')): ?>
				<a href="<?php echo htmlspecialchars($pager->get_url('+1'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline">Older &raquo;</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

<style>
.notifications-list {
	display: flex;
	flex-direction: column;
	gap: 0;
}
.notification-item {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 1rem;
	border-bottom: 1px solid #eee;
}
.notification-item.notification-unread {
	background: #f0f7ff;
}
.notification-content {
	flex: 1;
}
.notification-title {
	display: block;
	margin-bottom: 0.25rem;
}
.notification-link {
	text-decoration: none;
	color: inherit;
}
.notification-link:hover .notification-title {
	text-decoration: underline;
}
.notification-body {
	margin: 0.25rem 0;
	color: #555;
	font-size: 0.9rem;
}
.notification-time {
	font-size: 0.8rem;
	color: #999;
}
.notification-type {
	font-size: 0.75rem;
	color: #999;
	margin-left: 0.75rem;
	text-transform: capitalize;
}
.notification-actions {
	margin-left: 1rem;
	flex-shrink: 0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
	// Mark single notification as read
	document.querySelectorAll('.mark-read-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var id = this.getAttribute('data-id');
			var item = this.closest('.notification-item');
			var formData = new FormData();
			formData.append('action', 'mark_read');
			formData.append('notification_id', id);
			fetch('/ajax/notifications_ajax', {
				method: 'POST',
				body: formData
			}).then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					item.classList.remove('notification-unread');
					var actions = item.querySelector('.notification-actions');
					if (actions) actions.remove();
				}
			});
		});
	});

	// Mark all as read
	var markAllBtn = document.getElementById('mark-all-read-btn');
	if (markAllBtn) {
		markAllBtn.addEventListener('click', function() {
			var formData = new FormData();
			formData.append('action', 'mark_all_read');
			fetch('/ajax/notifications_ajax', {
				method: 'POST',
				body: formData
			}).then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					document.querySelectorAll('.notification-unread').forEach(function(el) {
						el.classList.remove('notification-unread');
					});
					document.querySelectorAll('.mark-read-btn').forEach(function(el) {
						el.closest('.notification-actions').remove();
					});
				}
			});
		});
	}
});
</script>

<?php
$page->member_footer();
?>
