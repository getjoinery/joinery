<?php
/**
 * Notifications list page
 *
 * @version 2.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('notifications_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));

$page_vars = process_logic(notifications_logic($_GET, $_POST));

$page = new MemberPage();
$page->member_header([
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
				<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.3;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
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

<style>
/* Inbox card */
.ntf-inbox-card {
	background: #fff;
	border-radius: 0.5rem;
	border: 1px solid #e3e6ed;
	overflow: hidden;
}
.ntf-inbox-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.875rem 1.25rem;
	border-bottom: 1px solid #e3e6ed;
	background: #f9fafd;
}
.ntf-inbox-title {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-weight: 600;
	font-size: 0.9375rem;
	color: #344050;
}
.ntf-inbox-count {
	background: var(--primary, #2c7be5);
	color: #fff;
	font-size: 0.75rem;
	font-weight: 600;
	padding: 0.125rem 0.5rem;
	border-radius: 999px;
	line-height: 1.4;
}
.ntf-mark-all-btn {
	background: none;
	border: 1px solid #d8dbe0;
	border-radius: 0.25rem;
	padding: 0.3rem 0.75rem;
	font-size: 0.8125rem;
	color: #5e6e82;
	cursor: pointer;
	transition: all 0.15s;
}
.ntf-mark-all-btn:hover {
	background: #f0f2f5;
	color: #344050;
	border-color: #c0c4cc;
}

/* Empty state */
.ntf-inbox-empty {
	text-align: center;
	padding: 3rem 1rem;
	color: #9da9bb;
}
.ntf-inbox-empty p { margin: 0.75rem 0 0; }

/* Notification rows */
.ntf-inbox-list { }
.ntf-row {
	display: flex;
	align-items: flex-start;
	padding: 0.75rem 1.25rem;
	border-bottom: 1px solid #f0f2f5;
	position: relative;
	transition: background 0.15s;
}
.ntf-row:last-child { border-bottom: none; }
.ntf-row:hover { background: #f9fafd; }
.ntf-row-unread { background: #f0f7ff; }
.ntf-row-unread:hover { background: #e6f0fc; }
.ntf-row-unread .ntf-row-title { font-weight: 600; }

/* Unread dot */
.ntf-unread-dot {
	position: absolute;
	left: 0.375rem;
	top: 50%;
	transform: translateY(-50%);
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: var(--primary, #2c7be5);
}

/* Icon */
.ntf-row-icon {
	flex-shrink: 0;
	width: 36px;
	height: 36px;
	border-radius: 50%;
	background: #edf2f9;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-right: 0.875rem;
	color: #5e6e82;
}
.ntf-row-unread .ntf-row-icon {
	background: #dbe8f9;
	color: var(--primary, #2c7be5);
}

/* Content */
.ntf-row-content {
	flex: 1;
	min-width: 0;
}
.ntf-row-link {
	text-decoration: none;
	color: inherit;
}
.ntf-row-link:hover .ntf-row-title { color: var(--primary, #2c7be5); }
.ntf-row-title {
	display: block;
	font-size: 0.875rem;
	color: #344050;
	line-height: 1.4;
}
.ntf-row-body {
	display: block;
	font-size: 0.8125rem;
	color: #748194;
	margin-top: 0.125rem;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* Meta column */
.ntf-row-meta {
	flex-shrink: 0;
	margin-left: 1rem;
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 0.375rem;
}
.ntf-row-time {
	font-size: 0.75rem;
	color: #9da9bb;
	white-space: nowrap;
}
.ntf-row-mark-btn {
	background: none;
	border: 1px solid #d8dbe0;
	border-radius: 50%;
	width: 26px;
	height: 26px;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: #9da9bb;
	padding: 0;
	transition: all 0.15s;
}
.ntf-row-mark-btn:hover {
	background: #e8f4e8;
	border-color: #6bc16b;
	color: #3a8a3a;
}

/* Pager */
.ntf-inbox-pager {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 1rem;
	padding: 0.875rem 1.25rem;
	border-top: 1px solid #e3e6ed;
	background: #f9fafd;
	font-size: 0.8125rem;
	color: #748194;
}
.ntf-inbox-pager a {
	color: var(--primary, #2c7be5);
	text-decoration: none;
	font-weight: 500;
}
.ntf-inbox-pager a:hover { text-decoration: underline; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
	// Mark single notification as read
	document.querySelectorAll('.mark-read-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var id = this.getAttribute('data-id');
			var row = document.querySelector('.ntf-row[data-id="' + id + '"]');
			var formData = new FormData();
			formData.append('action', 'mark_read');
			formData.append('notification_id', id);
			fetch('/ajax/notifications_ajax', {
				method: 'POST',
				body: formData
			}).then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					row.classList.remove('ntf-row-unread');
					var dot = row.querySelector('.ntf-unread-dot');
					if (dot) dot.remove();
					var markBtn = row.querySelector('.ntf-row-mark-btn');
					if (markBtn) markBtn.remove();
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
					document.querySelectorAll('.ntf-row-unread').forEach(function(el) {
						el.classList.remove('ntf-row-unread');
					});
					document.querySelectorAll('.ntf-unread-dot').forEach(function(el) {
						el.remove();
					});
					document.querySelectorAll('.ntf-row-mark-btn').forEach(function(el) {
						el.remove();
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
