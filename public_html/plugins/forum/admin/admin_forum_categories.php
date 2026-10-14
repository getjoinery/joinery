<?php
/**
 * Forum categories admin — every category with who may read and post in it,
 * its topic count and its place in the list. Create and edit on the category
 * page; delete and undelete here, as POST buttons.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/forum/admin/logic/admin_forum_categories_logic.php'));

$page_vars = process_logic(admin_forum_categories_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'forum-admin-categories',
	'breadcrumbs' => array('Forum' => '', 'Categories' => ''),
	'session'     => $session,
));

if ($flash !== '') {
	echo '<div class="alert alert-success">' . htmlspecialchars($flash) . '</div>';
}

echo '<p>';
echo '<a class="btn btn-sm btn-primary" href="/plugins/forum/admin/admin_forum_category">New category</a> ';
echo '<a class="btn btn-sm ' . (!$show_deleted ? 'btn-secondary' : 'btn-outline') . '" href="/plugins/forum/admin/admin_forum_categories">Categories</a> ';
echo '<a class="btn btn-sm ' . ($show_deleted ? 'btn-secondary' : 'btn-outline') . '" href="/plugins/forum/admin/admin_forum_categories?deleted=1">Deleted</a> ';
echo '<a class="btn btn-sm btn-outline" href="/forum">Open the forum</a> ';
echo '<a class="btn btn-sm btn-outline" href="/admin/admin_settings_plugins?plugin=forum">Forum settings</a>';
echo '</p>';

$rules = array('public' => 'Anyone, signed out too', 'members' => 'Any member', 'tier' => 'Subscribers', 'group' => 'A group', 'staff' => 'Staff only');
$page->tableheader(array('Category', 'Who can read', 'Who can post', 'Topics', 'Order', 'Actions'), array('title' => 'Forum categories'));
foreach ($rows as $row) {
	$category = $row['category'];
	$id = (int)$category->key;
	$hidden = array('fcg_forum_category_id' => $id);
	if ($show_deleted) {
		$actions = AdminPage::action_button('Undelete', '/plugins/forum/admin/admin_forum_categories', array('hidden' => $hidden + array('action' => 'undelete')));
	} else {
		$actions = '<a class="btn btn-sm btn-secondary" href="/plugins/forum/admin/admin_forum_category?fcg_forum_category_id=' . $id . '">Edit</a> '
			. AdminPage::action_button('Delete', '/plugins/forum/admin/admin_forum_categories', array(
				'hidden'  => $hidden + array('action' => 'delete'),
				'confirm' => 'Delete "' . $category->get('fcg_name') . '"? Its topics disappear for everyone with it. It can be undeleted.',
			));
	}
	$name = htmlspecialchars((string)$category->get('fcg_name'));
	$page->disprow(array(
		$show_deleted ? $name : '<a href="' . htmlspecialchars($category->url()) . '">' . $name . '</a><br><small class="text-muted">/forum/' . htmlspecialchars((string)$category->get('fcg_slug')) . '</small>',
		htmlspecialchars($rules[$category->get('fcg_read_rule')] ?? (string)$category->get('fcg_read_rule')),
		htmlspecialchars($rules[$category->get('fcg_post_rule')] ?? (string)$category->get('fcg_post_rule')),
		(int)$row['topics'],
		(int)$category->get('fcg_sort_order'),
		$actions,
	));
}
$page->endtable();
if (!$rows) {
	echo '<p>' . ($show_deleted ? 'No deleted categories.' : 'No categories yet. A forum needs at least one before members can post.') . '</p>';
}
$page->admin_footer();
