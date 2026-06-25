<?php
/**
 * Conversations inbox — list of conversations
 *
 * @version 2.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('conversations_logic.php', 'logic'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page_vars = process_logic(conversations_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
	'title' => $page_vars['title'],
]);

$session = SessionControl::get_instance();
$conversations = $page_vars['conversations'];
$other_users = $page_vars['other_users'];
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <h1>Messages</h1>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li><a href="/profile">My Profile</a></li>
                        <li class="active">Messages</li>
                    </ol>
                </nav>
            </div>
        </div>

<div class="msg-inbox">
	<div class="msg-inbox-card">
		<div class="msg-inbox-header">
			<div class="msg-inbox-title">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
				Messages
			</div>
			<a href="/profile/conversation?new=1&to=0" class="msg-compose-btn is-hidden" id="new-message-btn">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
				Compose
			</a>
		</div>

		<?php if ($conversations->count() === 0): ?>
			<div class="msg-inbox-empty">
				<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="jy-faded"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
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

    </div>
</section>
</div>
<?php
$page->public_footer();
?>
