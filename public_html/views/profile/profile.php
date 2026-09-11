<?php
/**
 * Member Dashboard — the /profile landing page.
 *
 * @version 3.0
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));

$page_vars = process_logic(profile_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
	'title' => 'Dashboard',
]);

$settings = $page_vars['settings'];
$session = $page_vars['session'];
$user = $page_vars['user'];
$now = time();

function dashboard_relative_time($utc_time_string, $session) {
	if (!$utc_time_string) return '';
	$local_time = LibraryFunctions::convert_time($utc_time_string, 'UTC', $session->get_timezone(), 'Y-m-d H:i:s');
	$ts = strtotime($local_time);
	$now = time();
	$diff = $now - $ts;
	if ($diff < 60) return 'Just now';
	if ($diff < 3600) return floor($diff / 60) . 'm ago';
	if ($diff < 86400) return floor($diff / 3600) . 'h ago';
	if ($diff < 172800) return 'Yesterday';
	if ($diff < 604800) return floor($diff / 86400) . ' days ago';
	$year = date('Y', $ts);
	if ($year != date('Y')) return date('M j, Y', $ts);
	return date('M j', $ts);
}

function dashboard_notification_icon_svg($type) {
	$icons = [
		'message'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
		'like'         => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
		'event'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
		'order'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
		'subscription' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
		'comment'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>',
		'group'        => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/><path d="M21 20c0-3-1.8-4.4-4-5"/></svg>',
		'account'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>',
	];
	$default = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
	return $icons[$type] ?? $default;
}

/**
 * Render one plugin-contributed dashboard section (recent orders, subscriptions,
 * upcoming events, ...) as a list card. `meta` may carry safe HTML; every other
 * field is escaped.
 */
function dashboard_render_section($section) {
	// An empty section with no empty_message renders no card at all (its stat
	// still feeds the grid); a section with an empty_message shows that message.
	if (empty($section->items) && ($section->empty_message ?? null) === null) {
		return '';
	}
	ob_start();
	?>
	<div class="card">
		<div class="card-header d-flex justify-content-between align-items-center">
			<h6 class="jy-tight"><?php echo htmlspecialchars($section->title); ?></h6>
			<?php if ($section->view_all_url): ?>
			<a href="<?php echo htmlspecialchars($section->view_all_url); ?>" class="text-sm">View all</a>
			<?php endif; ?>
		</div>
		<div class="card-body">
			<?php if (empty($section->items)): ?>
				<p class="muted jy-tight"><?php echo htmlspecialchars($section->empty_message); ?></p>
			<?php else: ?>
				<?php foreach ($section->items as $i => $item): ?>
				<div class="jy-profile-evrow<?php echo $i > 0 ? ' is-divided' : ''; ?>">
					<div class="jy-flex1min">
						<?php if ($item->url): ?>
						<a href="<?php echo htmlspecialchars($item->url); ?>" class="jy-fw-600"><?php echo htmlspecialchars($item->title); ?></a>
						<?php else: ?>
						<span class="jy-fw-600"><?php echo htmlspecialchars($item->title); ?></span>
						<?php endif; ?>
						<?php if ($item->subtitle): ?>
						<div class="muted text-sm jy-mt-1"><?php echo htmlspecialchars($item->subtitle); ?></div>
						<?php endif; ?>
						<?php if ($item->meta): ?>
						<div class="muted text-sm jy-mt-1"><?php echo $item->meta; ?></div>
						<?php endif; ?>
					</div>
					<?php if ($item->badge): ?>
					<div class="jy-noshrink">
						<span class="badge badge-success"><?php echo htmlspecialchars($item->badge); ?></span>
					</div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <h1>Dashboard</h1>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li class="active">Dashboard</li>
                    </ol>
                </nav>
            </div>
        </div>

        <?php
        echo $page->render_messages('profilebox');
        ?>

        <?php
        $has_actions = !empty($page_vars['pending_surveys']) || $page_vars['unread_messages'] > 0 || $page_vars['unread_notifications'] > 0;
        if ($has_actions):
        ?>
        <div class="alert alert-info jy-profile-actions">
            <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div class="alert-body jy-profile-actions-body">
                <?php if (!empty($page_vars['pending_surveys'])): ?>
                <a href="/survey?survey_id=<?php echo intval($page_vars['pending_surveys'][0]['survey_id']); ?>&amp;event_id=<?php echo intval($page_vars['pending_surveys'][0]['event_id']); ?>" class="jy-profile-actionlink">
                    <strong><?php echo count($page_vars['pending_surveys']); ?></strong> survey<?php echo count($page_vars['pending_surveys']) != 1 ? 's' : ''; ?> awaiting feedback
                </a>
                <?php endif; ?>
                <?php if ($page_vars['unread_messages'] > 0): ?>
                <a href="/profile/conversations" class="jy-profile-actionlink">
                    <strong><?php echo $page_vars['unread_messages']; ?></strong> unread message<?php echo $page_vars['unread_messages'] != 1 ? 's' : ''; ?>
                </a>
                <?php endif; ?>
                <?php if ($page_vars['unread_notifications'] > 0): ?>
                <a href="/notifications" class="jy-profile-actionlink">
                    <strong><?php echo $page_vars['unread_notifications']; ?></strong> new notification<?php echo $page_vars['unread_notifications'] != 1 ? 's' : ''; ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="stats-grid jy-mb-5">
            <?php foreach ($page_vars['dashboard_stats'] as $stat): ?>
            <a href="<?php echo htmlspecialchars($stat->link ?: '#'); ?>" class="card jy-profile-statcard">
                <div class="card-body jy-profile-statbody">
                    <div class="jy-profile-statnum"><?php echo (int)$stat->count; ?></div>
                    <div class="muted text-sm"><?php echo htmlspecialchars($stat->label); ?></div>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if ($settings->get_setting('messaging_active')): ?>
            <a href="/profile/conversations" class="card jy-profile-statcard">
                <div class="card-body jy-profile-statbody">
                    <div class="jy-profile-statnum<?php echo $page_vars['unread_messages'] > 0 ? ' is-alert' : ''; ?>"><?php echo (int)$page_vars['unread_messages']; ?></div>
                    <div class="muted text-sm">Unread Messages</div>
                </div>
            </a>
            <?php endif; ?>
            <a href="/notifications" class="card jy-profile-statcard">
                <div class="card-body jy-profile-statbody">
                    <div class="jy-profile-statnum<?php echo $page_vars['unread_notifications'] > 0 ? ' is-warn' : ''; ?>"><?php echo (int)$page_vars['unread_notifications']; ?></div>
                    <div class="muted text-sm">Notifications</div>
                </div>
            </a>
        </div>

        <div class="jy-profile-cols">
            <!-- Main content column -->
            <div class="jy-profile-main">

                <?php foreach ($page_vars['dashboard_sections'] as $section): ?>
                    <?php echo dashboard_render_section($section); ?>
                <?php endforeach; ?>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="jy-tight">Recent Notifications</h6>
                        <a href="/notifications" class="text-sm">View all</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($page_vars['recent_notifications']) == 0): ?>
                            <p class="muted jy-tight">No notifications yet.</p>
                        <?php else: ?>
                            <?php $ni = 0; foreach ($page_vars['recent_notifications'] as $ntf): ?>
                            <?php
                            $is_unread = !$ntf->get('ntf_is_read');
                            $ntf_link = $ntf->get('ntf_link');
                            $ntf_type = $ntf->get('ntf_type');
                            $icon_svg = dashboard_notification_icon_svg($ntf_type);
                            ?>
                            <div class="jy-profile-feedrow<?php echo $ni > 0 ? ' is-divided' : ''; ?><?php echo $is_unread ? ' is-unread' : ''; ?>">
                                <div class="muted jy-profile-feedicon"><?php echo $icon_svg; ?></div>
                                <div class="jy-flex1min">
                                    <?php if ($ntf_link): ?>
                                        <a href="<?php echo htmlspecialchars($ntf_link); ?>" class="text-sm jy-profile-feedtitle<?php echo $is_unread ? ' is-unread' : ''; ?>"><?php echo htmlspecialchars($ntf->get('ntf_title')); ?></a>
                                    <?php else: ?>
                                        <span class="text-sm jy-profile-feedtitle<?php echo $is_unread ? ' is-unread' : ''; ?>"><?php echo htmlspecialchars($ntf->get('ntf_title')); ?></span>
                                    <?php endif; ?>
                                    <?php if ($ntf->get('ntf_body')): ?>
                                    <div class="muted text-sm jy-profile-feedpreview"><?php echo htmlspecialchars(mb_substr($ntf->get('ntf_body'), 0, 100)); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="muted text-sm jy-profile-feedtime"><?php echo dashboard_relative_time($ntf->get('ntf_create_time'), $session); ?></div>
                            </div>
                            <?php $ni++; endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($settings->get_setting('messaging_active') && $page_vars['recent_conversations']): ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="jy-tight">Recent Messages</h6>
                        <a href="/profile/conversations" class="text-sm">View all</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($page_vars['recent_conversations']) == 0): ?>
                            <p class="muted jy-tight">No messages yet.</p>
                        <?php else: ?>
                            <?php $mi = 0; foreach ($page_vars['recent_conversations'] as $cnv): ?>
                            <?php
                            $other_name = $page_vars['conversation_other_users'][$cnv->key] ?? 'Unknown';
                            $latest_body = isset($cnv->latest_message_body) ? $cnv->latest_message_body : '';
                            $latest_time = isset($cnv->latest_message_time) ? $cnv->latest_message_time : '';
                            $last_read = isset($cnv->cnp_last_read_time) ? $cnv->cnp_last_read_time : null;
                            $is_unread = $latest_time && (!$last_read || $latest_time > $last_read);
                            $preview = htmlspecialchars(mb_substr(strip_tags($latest_body), 0, 80));
                            ?>
                            <div class="jy-profile-feedrow<?php echo $mi > 0 ? ' is-divided' : ''; ?><?php echo $is_unread ? ' is-unread' : ''; ?>">
                                <div class="muted jy-profile-feedicon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                                </div>
                                <div class="jy-flex1min">
                                    <div class="jy-profile-convhead">
                                        <a href="/profile/conversation?id=<?php echo (int)$cnv->key; ?>" class="text-sm jy-profile-feedtitle<?php echo $is_unread ? ' is-unread' : ''; ?>"><?php echo htmlspecialchars($other_name); ?></a>
                                        <span class="muted text-sm jy-profile-convtime"><?php echo dashboard_relative_time($latest_time, $session); ?></span>
                                    </div>
                                    <?php if ($preview): ?>
                                    <div class="muted text-sm jy-profile-feedpreview"><?php echo $preview; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php $mi++; endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Sidebar column -->
            <div class="jy-profile-aside">

                <div class="card">
                    <div class="card-body jy-profile-userbody">
                        <div class="jy-profile-avatar">
                            <?php
                            $pic = $user->get_picture_link('avatar');
                            if ($pic):
                            ?>
                            <img src="<?php echo htmlspecialchars($pic); ?>" alt="" class="jy-profile-avatarimg">
                            <?php else: ?>
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                            <?php endif; ?>
                        </div>
                        <h5 class="jy-profile-username"><?php echo htmlspecialchars($user->display_name()); ?></h5>
                        <p class="muted text-sm jy-profile-usermeta"><?php echo htmlspecialchars($user->get('usr_email')); ?></p>
                        <?php if ($page_vars['address']->get_address_string(', ')): ?>
                        <p class="muted text-sm jy-profile-useraddr"><?php echo htmlspecialchars($page_vars['address']->get_address_string(', ')); ?></p>
                        <?php else: ?>
                        <div class="jy-mb-4"></div>
                        <?php endif; ?>
                        <a href="/profile/settings" class="btn btn-primary btn-block">Settings</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h6 class="jy-tight">Mailing Lists</h6>
                    </div>
                    <div class="card-body text-sm">
                        <?php if (empty($page_vars['user_subscribed_list'])): ?>
                            <p class="muted jy-tight">Not subscribed to any lists.</p>
                        <?php else: ?>
                            <p class="muted jy-tight"><?php echo htmlspecialchars(implode(', ', $page_vars['user_subscribed_list'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
