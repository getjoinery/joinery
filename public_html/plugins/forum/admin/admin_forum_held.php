<?php
/**
 * Posts awaiting approval — the posts held while their authors are new.
 * Nobody but the author and the moderators sees them until they are approved.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/forum/admin/logic/admin_forum_held_logic.php'));

$page_vars = process_logic(admin_forum_held_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'forum-admin-held',
	'breadcrumbs' => array('Forum' => '/plugins/forum/admin/admin_forum_categories', 'Posts awaiting approval' => ''),
	'session'     => $session,
));

if ($flash !== '') {
	echo '<div class="alert alert-success">' . htmlspecialchars($flash) . '</div>';
}
echo '<p>' . ($hold > 0
	? 'A member\'s first ' . (int)$hold . ' ' . ($hold === 1 ? 'post waits' : 'posts wait') . ' here before anyone else can see them.'
	: 'New members\' posts are not being held (the "Hold a new member\'s first posts" setting is 0).')
	. ' <a href="/admin/admin_settings_plugins?plugin=forum">Change this</a>.</p>';

$pager = new Pager(array('numrecords' => $total, 'numperpage' => $per_page));
$page->tableheader(array('Post', 'Author', 'Category', 'Written', 'Actions'), array('title' => 'Waiting for approval'), $pager);
$tz = $session->get_timezone();
foreach ($rows as $row) {
	$post = $row['post'];
	$id = (int)$post->key;
	$hidden = array('fpo_forum_post_id' => $id);
	$what = $post->get('fpo_is_first') ? 'New topic: ' : 'Reply in: ';
	$page->disprow(array(
		'<strong>' . htmlspecialchars($what . $row['topic']->get('ftp_title')) . '</strong><br>'
			. htmlspecialchars(Forum::plainExcerpt((string)$post->get('fpo_body'), 300))
			. '<br><a href="' . htmlspecialchars(ForumPages::postUrl($row['topic'], $post)) . '">See it in the topic</a>',
		htmlspecialchars(Forum::person($post->get('fpo_usr_user_id'))['name']),
		htmlspecialchars((string)$row['category']->get('fcg_name')),
		htmlspecialchars((string)LibraryFunctions::convert_time($post->get('fpo_create_time'), 'UTC', $tz, 'M j, Y g:i A')),
		AdminPage::action_button('Approve', '/plugins/forum/admin/admin_forum_held', array('hidden' => $hidden + array('action' => 'approve'), 'class' => 'btn btn-primary btn-sm'))
			. ' ' . AdminPage::action_button('Turn down', '/plugins/forum/admin/admin_forum_held', array(
				'hidden'  => $hidden + array('action' => 'reject'),
				'confirm' => 'Turn this post down? It will be removed' . ($post->get('fpo_is_first') ? ', with its topic' : '') . '.',
			)),
	));
}
$page->endtable($pager);
if (!$rows) {
	echo '<p>Nothing is waiting.</p>';
}
$page->admin_footer();
