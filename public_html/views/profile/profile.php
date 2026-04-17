<?php
/**
 * Member Dashboard — the /profile landing page.
 *
 * @version 2.0
 */

require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));

$page_vars = process_logic(profile_logic($_GET, $_POST));

$page = new MemberPage();
$hoptions = array(
	'title' => 'Dashboard',
	'readable_title' => 'Dashboard',
	'breadcrumbs' => array(
		'Home' => '/',
		'Dashboard' => '',
	),
);
$page->member_header($hoptions);

$settings = $page_vars['settings'];
$session = $page_vars['session'];
$user = $page_vars['user'];
$now = time();

// Helper: relative time display
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

// Notification type icons (inline SVGs since getIconSvg is protected)
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
?>

<?php
// =====================================================================
// FLASH MESSAGES
// =====================================================================
foreach ($page_vars['display_messages'] as $display_message) {
	if ($display_message->identifier == 'profilebox') {
		echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
	}
}

// =====================================================================
// ACTION ITEMS BANNER
// =====================================================================
$has_actions = !empty($page_vars['pending_surveys']) || $page_vars['unread_messages'] > 0 || $page_vars['unread_notifications'] > 0;
if ($has_actions):
?>
<div class="alert alert-info" style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
	<svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
	<div class="alert-body" style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;flex:1;">
		<?php if (!empty($page_vars['pending_surveys'])): ?>
		<a href="/survey?survey_id=<?php echo intval($page_vars['pending_surveys'][0]['survey_id']); ?>&amp;event_id=<?php echo intval($page_vars['pending_surveys'][0]['event_id']); ?>" style="text-decoration:none;color:inherit;white-space:nowrap;">
			<strong><?php echo count($page_vars['pending_surveys']); ?></strong> survey<?php echo count($page_vars['pending_surveys']) != 1 ? 's' : ''; ?> awaiting feedback
		</a>
		<?php endif; ?>
		<?php if ($page_vars['unread_messages'] > 0): ?>
		<a href="/profile/conversations" style="text-decoration:none;color:inherit;white-space:nowrap;">
			<strong><?php echo $page_vars['unread_messages']; ?></strong> unread message<?php echo $page_vars['unread_messages'] != 1 ? 's' : ''; ?>
		</a>
		<?php endif; ?>
		<?php if ($page_vars['unread_notifications'] > 0): ?>
		<a href="/notifications" style="text-decoration:none;color:inherit;white-space:nowrap;">
			<strong><?php echo $page_vars['unread_notifications']; ?></strong> new notification<?php echo $page_vars['unread_notifications'] != 1 ? 's' : ''; ?>
		</a>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<?php
// =====================================================================
// QUICK STATS ROW
// =====================================================================
?>
<div class="stats-grid" style="margin-bottom:1.5rem;">
	<a href="/profile/events" class="card" style="text-decoration:none;color:inherit;">
		<div class="card-body" style="text-align:center;padding:1rem;">
			<div style="font-size:1.75rem;font-weight:700;color:var(--primary);"><?php echo (int)$page_vars['active_event_count']; ?></div>
			<div style="font-size:0.8125rem;color:var(--muted);">Upcoming Events</div>
		</div>
	</a>
	<?php if ($settings->get_setting('messaging_active')): ?>
	<a href="/profile/conversations" class="card" style="text-decoration:none;color:inherit;">
		<div class="card-body" style="text-align:center;padding:1rem;">
			<div style="font-size:1.75rem;font-weight:700;color:<?php echo $page_vars['unread_messages'] > 0 ? 'var(--danger)' : 'var(--primary)'; ?>;"><?php echo (int)$page_vars['unread_messages']; ?></div>
			<div style="font-size:0.8125rem;color:var(--muted);">Unread Messages</div>
		</div>
	</a>
	<?php endif; ?>
	<a href="/notifications" class="card" style="text-decoration:none;color:inherit;">
		<div class="card-body" style="text-align:center;padding:1rem;">
			<div style="font-size:1.75rem;font-weight:700;color:<?php echo $page_vars['unread_notifications'] > 0 ? 'var(--warning)' : 'var(--primary)'; ?>;"><?php echo (int)$page_vars['unread_notifications']; ?></div>
			<div style="font-size:0.8125rem;color:var(--muted);">Notifications</div>
		</div>
	</a>
	<?php if ($settings->get_setting('products_active') && $settings->get_setting('subscriptions_active')): ?>
	<a href="/profile/subscriptions" class="card" style="text-decoration:none;color:inherit;">
		<div class="card-body" style="text-align:center;padding:1rem;">
			<div style="font-size:1.75rem;font-weight:700;color:var(--primary);"><?php echo (int)$page_vars['active_subscription_count']; ?></div>
			<div style="font-size:0.8125rem;color:var(--muted);">Active Subscriptions</div>
		</div>
	</a>
	<?php endif; ?>
</div>

<?php
// =====================================================================
// TWO-COLUMN LAYOUT: Main + Sidebar
// =====================================================================
?>
<div class="row" style="gap:1.5rem 0;">
	<!-- Main content column -->
	<div class="col-md-8" style="padding-right:0.75rem;">

		<?php
		// =============================================================
		// UPCOMING EVENTS
		// =============================================================
		?>
		<div class="card mb-3">
			<div class="card-header bg-body-tertiary" style="display:flex;justify-content:space-between;align-items:center;">
				<h6 class="mb-0">Upcoming Events</h6>
				<a href="/profile/events" style="font-size:0.8125rem;">View all</a>
			</div>
			<div class="card-body">
				<?php if (empty($page_vars['event_registrations'])): ?>
					<p style="color:var(--muted);margin:0;">No upcoming events.</p>
				<?php else: ?>
					<?php foreach ($page_vars['event_registrations'] as $i => $event): ?>
					<div style="<?php echo $i > 0 ? 'border-top:1px solid var(--border-color);' : ''; ?>padding:<?php echo $i > 0 ? '0.75rem' : '0'; ?> 0 0.75rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
						<div style="flex:1;min-width:0;">
							<a href="<?php echo htmlspecialchars($event['event_link']); ?>" style="font-weight:600;"><?php echo htmlspecialchars($event['event_name']); ?></a>
							<?php if ($event['event_time']): ?>
							<div style="font-size:0.8125rem;color:var(--muted);margin-top:0.125rem;"><?php echo $event['event_time']; ?></div>
							<?php endif; ?>
						</div>
						<div style="flex-shrink:0;">
							<?php if ($event['event_expires']): ?>
								<span class="badge" style="background:var(--success-light, #d4edda);color:var(--success, #155724);">Expires <?php echo htmlspecialchars($event['event_expires']); ?></span>
							<?php else: ?>
								<span class="badge" style="background:var(--success-light, #d4edda);color:var(--success, #155724);">Active</span>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<?php
		// =============================================================
		// RECENT NOTIFICATIONS
		// =============================================================
		?>
		<div class="card mb-3">
			<div class="card-header bg-body-tertiary" style="display:flex;justify-content:space-between;align-items:center;">
				<h6 class="mb-0">Recent Notifications</h6>
				<a href="/notifications" style="font-size:0.8125rem;">View all</a>
			</div>
			<div class="card-body">
				<?php if (count($page_vars['recent_notifications']) == 0): ?>
					<p style="color:var(--muted);margin:0;">No notifications yet.</p>
				<?php else: ?>
					<?php $ni = 0; foreach ($page_vars['recent_notifications'] as $ntf): ?>
					<?php
					$is_unread = !$ntf->get('ntf_is_read');
					$ntf_link = $ntf->get('ntf_link');
					$ntf_type = $ntf->get('ntf_type');
					$icon_svg = dashboard_notification_icon_svg($ntf_type);
					?>
					<div style="<?php echo $ni > 0 ? 'border-top:1px solid var(--border-color);' : ''; ?>padding:<?php echo $ni > 0 ? '0.625rem' : '0'; ?> 0 0.625rem;display:flex;align-items:flex-start;gap:0.75rem;<?php echo $is_unread ? 'border-left:3px solid var(--primary);padding-left:0.75rem;margin-left:-0.75rem;' : ''; ?>">
						<div style="flex-shrink:0;color:var(--muted);margin-top:0.125rem;"><?php echo $icon_svg; ?></div>
						<div style="flex:1;min-width:0;">
							<?php if ($ntf_link): ?>
								<a href="<?php echo htmlspecialchars($ntf_link); ?>" style="font-weight:<?php echo $is_unread ? '600' : '400'; ?>;font-size:0.875rem;"><?php echo htmlspecialchars($ntf->get('ntf_title')); ?></a>
							<?php else: ?>
								<span style="font-weight:<?php echo $is_unread ? '600' : '400'; ?>;font-size:0.875rem;"><?php echo htmlspecialchars($ntf->get('ntf_title')); ?></span>
							<?php endif; ?>
							<?php if ($ntf->get('ntf_body')): ?>
							<div style="font-size:0.8125rem;color:var(--muted);margin-top:0.125rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(mb_substr($ntf->get('ntf_body'), 0, 100)); ?></div>
							<?php endif; ?>
						</div>
						<div style="flex-shrink:0;font-size:0.75rem;color:var(--muted);white-space:nowrap;"><?php echo dashboard_relative_time($ntf->get('ntf_create_time'), $session); ?></div>
					</div>
					<?php $ni++; endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<?php
		// =============================================================
		// RECENT MESSAGES
		// =============================================================
		if ($settings->get_setting('messaging_active') && $page_vars['recent_conversations']):
		?>
		<div class="card mb-3">
			<div class="card-header bg-body-tertiary" style="display:flex;justify-content:space-between;align-items:center;">
				<h6 class="mb-0">Recent Messages</h6>
				<a href="/profile/conversations" style="font-size:0.8125rem;">View all</a>
			</div>
			<div class="card-body">
				<?php if (count($page_vars['recent_conversations']) == 0): ?>
					<p style="color:var(--muted);margin:0;">No messages yet.</p>
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
					<div style="<?php echo $mi > 0 ? 'border-top:1px solid var(--border-color);' : ''; ?>padding:<?php echo $mi > 0 ? '0.625rem' : '0'; ?> 0 0.625rem;display:flex;align-items:flex-start;gap:0.75rem;<?php echo $is_unread ? 'border-left:3px solid var(--primary);padding-left:0.75rem;margin-left:-0.75rem;' : ''; ?>">
						<div style="flex-shrink:0;color:var(--muted);margin-top:0.125rem;">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
						</div>
						<div style="flex:1;min-width:0;">
							<div style="display:flex;justify-content:space-between;align-items:baseline;">
								<a href="/profile/conversation?id=<?php echo (int)$cnv->key; ?>" style="font-weight:<?php echo $is_unread ? '600' : '400'; ?>;font-size:0.875rem;"><?php echo htmlspecialchars($other_name); ?></a>
								<span style="font-size:0.75rem;color:var(--muted);white-space:nowrap;margin-left:0.5rem;"><?php echo dashboard_relative_time($latest_time, $session); ?></span>
							</div>
							<?php if ($preview): ?>
							<div style="font-size:0.8125rem;color:var(--muted);margin-top:0.125rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo $preview; ?></div>
							<?php endif; ?>
						</div>
					</div>
					<?php $mi++; endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php
		// =============================================================
		// RECENT ORDERS
		// =============================================================
		if ($settings->get_setting('products_active')):
		?>
		<div class="card mb-3">
			<div class="card-header bg-body-tertiary" style="display:flex;justify-content:space-between;align-items:center;">
				<h6 class="mb-0">Recent Orders</h6>
				<a href="/profile/orders" style="font-size:0.8125rem;">View all</a>
			</div>
			<div class="card-body">
				<?php if (!$page_vars['orders'] || $page_vars['numorders'] == 0): ?>
					<p style="color:var(--muted);margin:0;">No orders yet.</p>
				<?php else: ?>
					<?php $oi = 0; foreach ($page_vars['orders'] as $order): ?>
					<div style="<?php echo $oi > 0 ? 'border-top:1px solid var(--border-color);' : ''; ?>padding:<?php echo $oi > 0 ? '0.625rem' : '0'; ?> 0 0.625rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;">
						<p style="margin:0;font-weight:600;">Order #<?php echo htmlspecialchars($order->key); ?> &mdash; $<?php echo htmlspecialchars($order->get('ord_total_cost')); ?></p>
						<p style="margin:0;font-size:0.8125rem;color:var(--muted);"><?php echo LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $session->get_timezone(), 'M j, Y'); ?></p>
					</div>
					<?php $oi++; endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

	</div>

	<!-- Sidebar column -->
	<div class="col-md-4" style="padding-left:0.75rem;">

		<?php
		// =============================================================
		// USER CARD
		// =============================================================
		?>
		<div class="card mb-3">
			<div class="card-body" style="text-align:center;padding:1.5rem;">
				<div style="width:72px;height:72px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 1rem;overflow:hidden;">
					<?php
					$pic = $user->get_picture_link('avatar');
					if ($pic):
					?>
					<img src="<?php echo htmlspecialchars($pic); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
					<?php else: ?>
					<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
					<?php endif; ?>
				</div>
				<h5 style="margin:0 0 0.25rem;"><?php echo htmlspecialchars($user->display_name()); ?></h5>
				<p style="margin:0 0 0.25rem;font-size:0.875rem;color:var(--muted);"><?php echo htmlspecialchars($user->get('usr_email')); ?></p>
				<?php if ($page_vars['address']->get_address_string(', ')): ?>
				<p style="margin:0 0 1rem;font-size:0.875rem;color:var(--muted);"><?php echo htmlspecialchars($page_vars['address']->get_address_string(', ')); ?></p>
				<?php else: ?>
				<div style="margin-bottom:1rem;"></div>
				<?php endif; ?>
				<a href="/profile/account_edit" class="btn btn-primary" style="width:100%;">Edit Account</a>
			</div>
		</div>

		<?php
		// =============================================================
		// SUBSCRIPTION SUMMARY (sidebar)
		// =============================================================
		if ($settings->get_setting('products_active') && $settings->get_setting('subscriptions_active') && $page_vars['active_subscription_count'] > 0 && $page_vars['subscriptions']):
		?>
		<div class="card mb-3">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0">Subscriptions</h6>
			</div>
			<div class="card-body">
				<?php foreach ($page_vars['subscriptions'] as $subscription): ?>
				<?php
				if ($subscription->get('odi_subscription_cancelled_time')) {
					$sub_status = 'Canceled';
					$sub_badge_style = 'background:var(--lighter);color:var(--muted);';
				} else {
					$sub_status = $subscription->get('odi_subscription_status') ?: 'Active';
					$sub_badge_style = 'background:var(--success-light, #d4edda);color:var(--success, #155724);';
				}
				?>
				<div style="display:flex;justify-content:space-between;align-items:center;padding:0.375rem 0;">
					<span style="font-weight:600;font-size:0.875rem;">$<?php echo htmlspecialchars($subscription->get('odi_price')); ?>/mo</span>
					<span class="badge" style="<?php echo $sub_badge_style; ?>"><?php echo htmlspecialchars($sub_status); ?></span>
				</div>
				<?php endforeach; ?>
				<a href="/profile/subscriptions" style="display:block;font-size:0.8125rem;margin-top:0.5rem;">Manage subscriptions</a>
			</div>
		</div>
		<?php endif; ?>

		<?php
		// =============================================================
		// MAILING LISTS
		// =============================================================
		?>
		<div class="card mb-3">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0">Mailing Lists</h6>
			</div>
			<div class="card-body" style="font-size:0.875rem;">
				<?php if (empty($page_vars['user_subscribed_list'])): ?>
					<p style="margin:0;color:var(--muted);">Not subscribed to any lists.</p>
				<?php else: ?>
					<p style="margin:0;color:var(--muted);"><?php echo htmlspecialchars(implode(', ', $page_vars['user_subscribed_list'])); ?></p>
				<?php endif; ?>
			</div>
		</div>

	</div>
</div>

<?php
$page->member_footer(array('track' => TRUE));
?>
