<?php
/** @joinery-admin class=content */
/**
 * Forum topics admin — every topic, and the removed ones a moderator can
 * restore or remove for good. Day-to-day moderation (pin, lock, move, remove)
 * happens on the topic page itself, under Moderate.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/forum/admin/logic/admin_forum_topics_logic.php'));

$page_vars = process_logic(admin_forum_topics_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'forum-admin-topics',
	'breadcrumbs' => array('Forum' => '/plugins/forum/admin/admin_forum_categories', 'Topics' => ''),
	'session'     => $session,
));
if ($flash !== '') {
	echo '<div class="alert alert-success">' . htmlspecialchars($flash) . '</div>';
}
echo '<p>';
echo '<a class="btn btn-sm ' . ($show === 'live' ? 'btn-secondary' : 'btn-outline') . '" href="/plugins/forum/admin/admin_forum_topics">Topics</a> ';
echo '<a class="btn btn-sm ' . ($show === 'deleted' ? 'btn-secondary' : 'btn-outline') . '" href="/plugins/forum/admin/admin_forum_topics?show=deleted">Removed</a>';
echo '</p>';

$pager = new Pager(array('numrecords' => $total, 'numperpage' => $per_page));
$page->tableheader(array('Topic', 'Category', 'Started by', 'Replies', 'Last activity', 'Actions'), array('title' => $show === 'deleted' ? 'Removed topics' : 'Topics'), $pager);
$tz = $session->get_timezone();
foreach ($topics as $topic) {
	$id = (int)$topic->key;
	$category = ForumCategory::check_if_exists($topic->categoryId()) ? new ForumCategory($topic->categoryId(), TRUE) : null;
	$flags = array();
	if ($topic->get('ftp_is_pinned')) $flags[] = 'pinned';
	if ($topic->get('ftp_is_locked')) $flags[] = 'locked';
	if (!$topic->get('ftp_is_approved')) $flags[] = 'awaiting approval';
	$title = htmlspecialchars((string)$topic->get('ftp_title')) . ($flags ? ' <small class="text-muted">(' . implode(', ', $flags) . ')</small>' : '');
	$hidden = array('ftp_forum_topic_id' => $id);
	$actions = '';
	if ($show === 'deleted') {
		$actions = AdminPage::action_button('Restore', '/plugins/forum/admin/admin_forum_topics', array('hidden' => $hidden + array('action' => 'restore')))
			. ' ' . AdminPage::action_button('Remove for good', '/plugins/forum/admin/admin_forum_topics', array(
				'hidden'        => $hidden + array('action' => 'purge'),
				'confirm'       => 'Remove "' . $topic->get('ftp_title') . '" and every post, like and attachment in it for good? This cannot be undone.',
				'confirm_typed' => 'remove',
				'class'         => 'btn btn-danger btn-sm',
			));
	}
	$page->disprow(array(
		$show === 'deleted' ? $title : '<a href="' . htmlspecialchars($topic->url()) . '">' . $title . '</a>',
		$category ? htmlspecialchars((string)$category->get('fcg_name')) : '—',
		htmlspecialchars(Forum::person($topic->get('ftp_usr_user_id'))['name']),
		(int)$topic->get('ftp_reply_count'),
		htmlspecialchars((string)LibraryFunctions::convert_time($topic->get('ftp_last_post_time'), 'UTC', $tz, 'M j, Y g:i A')),
		$actions,
	));
}
$page->endtable($pager);
if (!$topics) {
	echo '<p>' . ($show === 'deleted' ? 'No removed topics.' : 'No topics yet.') . '</p>';
}
$page->admin_footer();
