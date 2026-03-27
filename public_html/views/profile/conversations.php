<?php
/**
 * Conversations inbox — list of conversations
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('conversations_logic.php', 'logic'));
require_once(PathHelper::getIncludePath('includes/MemberPage.php'));

$page_vars = process_logic(conversations_logic($_GET, $_POST));

$page = new MemberPage();
$page->member_header([
	'title' => $page_vars['title'],
]);

$session = SessionControl::get_instance();
$conversations = $page_vars['conversations'];
$other_users = $page_vars['other_users'];
?>

<div class="conversations-page">
	<div class="conversations-toolbar" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
		<h3 style="margin:0;">Messages</h3>
		<a href="/profile/conversation?new=1&to=0" class="btn btn-sm btn-primary" id="new-message-btn" style="display:none;">New Message</a>
	</div>

	<?php if ($conversations->count() === 0): ?>
		<p style="text-align:center;padding:2rem 0;color:#888;">No conversations yet.</p>
	<?php else: ?>
		<div class="conversations-list">
		<?php
		foreach ($conversations as $cnv):
			$other_user = isset($other_users[$cnv->key]) ? $other_users[$cnv->key] : null;
			$display_name = $other_user ? htmlspecialchars($other_user->display_name(), ENT_QUOTES, 'UTF-8') : 'Unknown User';

			// Latest message data from lateral join
			$latest_body = isset($cnv->latest_message_body) ? $cnv->latest_message_body : '';
			$latest_time = isset($cnv->latest_message_time) ? $cnv->latest_message_time : $cnv->get('cnv_create_time');
			$last_read = isset($cnv->cnp_last_read_time) ? $cnv->cnp_last_read_time : null;

			$preview = htmlspecialchars(substr(strip_tags($latest_body), 0, 80), ENT_QUOTES, 'UTF-8');

			// Determine if unread
			$is_unread = ($last_read === null && $latest_time) || ($last_read && $latest_time && $latest_time > $last_read);
			$unread_class = $is_unread ? ' conversation-unread' : '';

			// Format time
			$time_display = '';
			if ($latest_time) {
				$now = time();
				$msg_time = strtotime($latest_time);
				$diff = $now - $msg_time;
				if ($diff < 60) {
					$time_display = 'Just now';
				} elseif ($diff < 3600) {
					$mins = floor($diff / 60);
					$time_display = $mins . ' min ago';
				} elseif ($diff < 86400) {
					$hours = floor($diff / 3600);
					$time_display = $hours . 'h ago';
				} elseif ($diff < 172800) {
					$time_display = 'Yesterday';
				} else {
					$time_display = LibraryFunctions::convert_time($latest_time, 'UTC', $session->get_timezone(), 'M j');
				}
			}

			$is_muted = isset($cnv->cnp_is_muted) && $cnv->cnp_is_muted;
			$muted_class = $is_muted ? ' conversation-muted' : '';
		?>
			<a href="/profile/conversation?id=<?php echo (int)$cnv->key; ?>" class="conversation-item<?php echo $unread_class . $muted_class; ?>" style="text-decoration:none;color:inherit;">
				<div class="conversation-avatar">
					<svg width="40" height="40" viewBox="0 0 40 40" fill="none" aria-hidden="true"><circle cx="20" cy="20" r="20" fill="#e0e0e0"/><circle cx="20" cy="15" r="7" fill="#bbb"/><path d="M6 36c0-7 6-11 14-11s14 4 14 11" fill="#bbb"/></svg>
				</div>
				<div class="conversation-content">
					<div style="display:flex;justify-content:space-between;align-items:baseline;">
						<span class="conversation-name"><?php echo $display_name; ?></span>
						<span class="conversation-time"><?php echo htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8'); ?></span>
					</div>
					<?php if ($preview): ?>
						<p class="conversation-preview"><?php echo $preview; ?></p>
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
		<div class="conversations-pager" style="display:flex;justify-content:center;gap:1rem;padding:1.5rem 0;">
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
.conversations-list { display: flex; flex-direction: column; }
.conversation-item { display: flex; padding: 1rem; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.15s; }
.conversation-item:hover { background: #f8f8f8; }
.conversation-item.conversation-unread { background: #f0f7ff; }
.conversation-item.conversation-unread:hover { background: #e4effc; }
.conversation-item.conversation-unread .conversation-name { font-weight: 700; }
.conversation-avatar { flex-shrink: 0; margin-right: 1rem; }
.conversation-content { flex: 1; min-width: 0; }
.conversation-name { font-weight: 600; }
.conversation-preview { color: #555; font-size: 0.9rem; margin: 0.25rem 0 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conversation-time { font-size: 0.8rem; color: #999; flex-shrink: 0; }
.conversation-muted { opacity: 0.6; }
</style>

<?php
$page->member_footer();
?>
