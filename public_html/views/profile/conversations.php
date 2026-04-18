<?php
/**
 * Conversations inbox — list of conversations
 *
 * @version 2.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('conversations_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));

$page_vars = process_logic(conversations_logic($_GET, $_POST));

$page = new MemberPage();
$page->member_header([
	'title' => $page_vars['title'],
]);

$session = SessionControl::get_instance();
$conversations = $page_vars['conversations'];
$other_users = $page_vars['other_users'];
?>
<div class="jy-ui">

<div class="msg-inbox">
	<div class="msg-inbox-card">
		<div class="msg-inbox-header">
			<div class="msg-inbox-title">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
				Messages
			</div>
			<a href="/profile/conversation?new=1&to=0" class="msg-compose-btn" id="new-message-btn" style="display:none;">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
				Compose
			</a>
		</div>

		<?php if ($conversations->count() === 0): ?>
			<div class="msg-inbox-empty">
				<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.3;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
				<p>No conversations yet.</p>
			</div>
		<?php else: ?>
			<div class="msg-inbox-list">
			<?php
			foreach ($conversations as $cnv):
				$other_user = isset($other_users[$cnv->key]) ? $other_users[$cnv->key] : null;
				$display_name = $other_user ? htmlspecialchars($other_user->display_name(), ENT_QUOTES, 'UTF-8') : 'Unknown User';

				$latest_body = isset($cnv->latest_message_body) ? $cnv->latest_message_body : '';
				$latest_time = isset($cnv->latest_message_time) ? $cnv->latest_message_time : $cnv->get('cnv_create_time');
				$last_read = isset($cnv->cnp_last_read_time) ? $cnv->cnp_last_read_time : null;

				$preview = htmlspecialchars(substr(strip_tags($latest_body), 0, 100), ENT_QUOTES, 'UTF-8');

				$is_unread = ($last_read === null && $latest_time) || ($last_read && $latest_time && $latest_time > $last_read);
				$unread_class = $is_unread ? ' msg-row-unread' : '';

				// Relative time
				$time_display = '';
				if ($latest_time) {
					$msg_ts = strtotime($latest_time);
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
						$time_display = LibraryFunctions::convert_time($latest_time, 'UTC', $session->get_timezone(), 'M j');
					}
				}

				$is_muted = isset($cnv->cnp_is_muted) && $cnv->cnp_is_muted;
				$muted_class = $is_muted ? ' msg-row-muted' : '';
			?>
				<a href="/profile/conversation?id=<?php echo (int)$cnv->key; ?>" class="msg-row<?php echo $unread_class . $muted_class; ?>">
					<?php if ($is_unread): ?><div class="msg-unread-dot"></div><?php endif; ?>
					<div class="msg-row-avatar">
						<svg width="40" height="40" viewBox="0 0 40 40" fill="none" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="#e0e0e0"/><circle cx="20" cy="15" r="7" fill="#bbb"/><path d="M6 36c0-7 6-11 14-11s14 4 14 11" fill="#bbb"/></svg>
					</div>
					<div class="msg-row-content">
						<span class="msg-row-name"><?php echo $display_name; ?></span>
						<?php if ($preview): ?>
							<span class="msg-row-preview"><?php echo $preview; ?></span>
						<?php endif; ?>
					</div>
					<div class="msg-row-meta">
						<span class="msg-row-time"><?php echo htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8'); ?></span>
						<?php if ($is_muted): ?>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9da9bb" stroke-width="2" title="Muted"><path d="M11 5L6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
						<?php endif; ?>
					</div>
				</a>
			<?php endforeach; ?>
			</div>

			<?php
			$pager = $page_vars['pager'];
			if ($pager && $pager->total_pages() > 1):
				$current = $pager->current_page();
				$total = $pager->total_pages();
			?>
			<div class="msg-inbox-pager">
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
.msg-inbox-card {
	background: #fff;
	border-radius: 0.5rem;
	border: 1px solid #e3e6ed;
	overflow: hidden;
}
.msg-inbox-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.875rem 1.25rem;
	border-bottom: 1px solid #e3e6ed;
	background: #f9fafd;
}
.msg-inbox-title {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-weight: 600;
	font-size: 0.9375rem;
	color: #344050;
}
.msg-compose-btn {
	display: inline-flex;
	align-items: center;
	gap: 0.375rem;
	background: var(--primary, #2c7be5);
	color: #fff;
	border: none;
	border-radius: 0.25rem;
	padding: 0.3rem 0.75rem;
	font-size: 0.8125rem;
	font-weight: 500;
	text-decoration: none;
	cursor: pointer;
	transition: opacity 0.15s;
}
.msg-compose-btn:hover { opacity: 0.85; color: #fff; }

/* Empty state */
.msg-inbox-empty {
	text-align: center;
	padding: 3rem 1rem;
	color: #9da9bb;
}
.msg-inbox-empty p { margin: 0.75rem 0 0; }

/* Message rows */
.msg-row {
	display: flex;
	align-items: center;
	padding: 0.875rem 1.25rem;
	border-bottom: 1px solid #f0f2f5;
	text-decoration: none;
	color: inherit;
	position: relative;
	transition: background 0.15s;
}
.msg-row:last-child { border-bottom: none; }
.msg-row:hover { background: #f9fafd; }
.msg-row-unread { background: #f0f7ff; }
.msg-row-unread:hover { background: #e6f0fc; }
.msg-row-unread .msg-row-name { font-weight: 700; }
.msg-row-unread .msg-row-preview { color: #4a5568; }
.msg-row-muted { opacity: 0.6; }

/* Unread dot */
.msg-unread-dot {
	position: absolute;
	left: 0.375rem;
	top: 50%;
	transform: translateY(-50%);
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: var(--primary, #2c7be5);
}

/* Avatar */
.msg-row-avatar {
	flex-shrink: 0;
	margin-right: 0.875rem;
}

/* Content */
.msg-row-content {
	flex: 1;
	min-width: 0;
}
.msg-row-name {
	display: block;
	font-weight: 600;
	font-size: 0.875rem;
	color: #344050;
	line-height: 1.4;
}
.msg-row-preview {
	display: block;
	font-size: 0.8125rem;
	color: #748194;
	margin-top: 0.125rem;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* Meta column */
.msg-row-meta {
	flex-shrink: 0;
	margin-left: 1rem;
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 0.25rem;
}
.msg-row-time {
	font-size: 0.75rem;
	color: #9da9bb;
	white-space: nowrap;
}

/* Pager */
.msg-inbox-pager {
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
.msg-inbox-pager a {
	color: var(--primary, #2c7be5);
	text-decoration: none;
	font-weight: 500;
}
.msg-inbox-pager a:hover { text-decoration: underline; }
</style>

</div>
<?php
$page->member_footer();
?>
