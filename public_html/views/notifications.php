<?php
/**
 * Notifications list page
 *
 * @version 2.1
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('notifications_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(notifications_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
	'title' => $page_vars['title'],
]);

$session = SessionControl::get_instance();

// Notification type icon SVGs (matches dashboard)
function notification_icon_svg($type) {
	$icons = [
		'message'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
		'like'         => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
		'event'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
		'order'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
		'subscription' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
		'comment'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>',
		'group'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/><path d="M21 20c0-3-1.8-4.4-4-5"/></svg>',
		'account'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>',
	];
	$default = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
	return $icons[$type] ?? $default;
}
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-narrow-lg">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Notifications</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">My Profile</a></li>
                            <li class="active">Notifications</li>
                        </ol>
                    </nav>
                </div>
            </div>

<div class="ntf-inbox">
	<div class="ntf-inbox-card">
		<div class="ntf-inbox-header">
			<div class="ntf-inbox-title">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
				Notifications
				<?php if ($page_vars['numrecords'] > 0): ?>
					<span class="ntf-inbox-count"><?php echo (int)$page_vars['numrecords']; ?></span>
				<?php endif; ?>
			</div>
			<?php if ($page_vars['numrecords'] > 0): ?>
				<button type="button" id="mark-all-read-btn" class="ntf-mark-all-btn">Mark all as read</button>
			<?php endif; ?>
		</div>

		<?php if ($page_vars['notifications']->count() === 0): ?>
			<div class="ntf-inbox-empty">
				<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="jy-notif-empty-icon"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
				<p>No notifications yet.</p>
			</div>
		<?php else: ?>
			<div class="ntf-inbox-list">
			<?php
			foreach ($page_vars['notifications'] as $ntf):
				$is_read = $ntf->get('ntf_is_read');
				$link = $ntf->get('ntf_link');
				$type = $ntf->get('ntf_type');
				$title = htmlspecialchars($ntf->get('ntf_title'), ENT_QUOTES, 'UTF-8');
				$body = htmlspecialchars($ntf->get('ntf_body') ?: '', ENT_QUOTES, 'UTF-8');

				// Relative time display
				$raw_time = $ntf->get('ntf_create_time');
				$msg_ts = strtotime($raw_time);
				$diff = time() - $msg_ts;
				if ($diff < 60) {
					$time_display = 'Just now';
				} elseif ($diff < 3600) {
					$time_display = floor($diff / 60) . ' min ago';
				} elseif ($diff < 86400) {
					$time_display = floor($diff / 3600) . 'h ago';
				} elseif ($diff < 172800) {
					$time_display = 'Yesterday';
				} else {
					$time_display = LibraryFunctions::convert_time($raw_time, 'UTC', $session->get_timezone(), 'M j');
				}

				$unread_class = $is_read ? '' : ' ntf-row-unread';
				$icon_svg = notification_icon_svg($type);
			?>
				<div class="ntf-row<?php echo $unread_class; ?>" data-id="<?php echo (int)$ntf->key; ?>">
					<?php if (!$is_read): ?><div class="ntf-unread-dot"></div><?php endif; ?>
					<div class="ntf-row-icon"><?php echo $icon_svg; ?></div>
					<div class="ntf-row-content">
						<?php if ($link): ?>
							<a href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>" class="ntf-row-link">
								<span class="ntf-row-title"><?php echo $title; ?></span>
							</a>
						<?php else: ?>
							<span class="ntf-row-title"><?php echo $title; ?></span>
						<?php endif; ?>
						<?php if ($body): ?>
							<span class="ntf-row-body"><?php echo $body; ?></span>
						<?php endif; ?>
					</div>
					<div class="ntf-row-meta">
						<span class="ntf-row-time"><?php echo htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8'); ?></span>
						<?php if (!$is_read): ?>
							<button type="button" class="ntf-row-mark-btn mark-read-btn" data-id="<?php echo (int)$ntf->key; ?>" title="Mark as read">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>

			<?php
			$pager = $page_vars['pager'];
			if ($pager && $pager->total_pages() > 1):
				$current = $pager->current_page();
				$total = $pager->total_pages();
			?>
			<div class="ntf-inbox-pager">
				<?php if ($pager->is_valid_page('-1')): ?>
					<a href="<?php echo htmlspecialchars($pager->get_url('-1'), ENT_QUOTES, 'UTF-8'); ?>">&laquo; Newer</a>
				<?php endif; ?>
				<span>Page <?php echo $current; ?> of <?php echo $total; ?></span>
				<?php if ($pager->is_valid_page('+1')): ?>
					<a href="<?php echo htmlspecialchars($pager->get_url('+1'), ENT_QUOTES, 'UTF-8'); ?>">Older &raquo;</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	// Mark single notification as read
	document.querySelectorAll('.mark-read-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var id = this.getAttribute('data-id');
			var row = document.querySelector('.ntf-row[data-id="' + id + '"]');
			joineryApi.post('notification_mark_read', { notification_id: id }).then(function() {
				row.classList.remove('ntf-row-unread');
				var dot = row.querySelector('.ntf-unread-dot');
				if (dot) dot.remove();
				var markBtn = row.querySelector('.ntf-row-mark-btn');
				if (markBtn) markBtn.remove();
			}).catch(function() {});
		});
	});

	// Mark all as read
	var markAllBtn = document.getElementById('mark-all-read-btn');
	if (markAllBtn) {
		markAllBtn.addEventListener('click', function() {
			joineryApi.post('notification_mark_all_read').then(function() {
				document.querySelectorAll('.ntf-row-unread').forEach(function(el) {
					el.classList.remove('ntf-row-unread');
				});
				document.querySelectorAll('.ntf-unread-dot').forEach(function(el) {
					el.remove();
				});
				document.querySelectorAll('.ntf-row-mark-btn').forEach(function(el) {
					el.remove();
				});
			}).catch(function() {});
		});
	}
});
</script>

        </div>
    </div>
</section>
</div>
<?php
$page->public_footer();
?>
