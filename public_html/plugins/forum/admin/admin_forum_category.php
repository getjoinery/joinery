<?php
/** @joinery-admin class=content */
/**
 * Forum category edit — name, address, description, order, and who may read
 * and who may post. The tier and group choices apply when a rule asks for them.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/forum/admin/logic/admin_forum_category_logic.php'));

$page_vars = process_logic(admin_forum_category_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$is_new = !$category->key;
$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'forum-admin-categories',
	'breadcrumbs' => array('Forum' => '/plugins/forum/admin/admin_forum_categories', ($is_new ? 'New category' : 'Edit category') => ''),
	'session'     => $session,
));

if ($error !== '') {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

$page->begin_box(array('title' => $is_new ? 'New category' : 'Edit ' . $category->get('fcg_name')));
$fw = $page->getFormWriter('form1', array('model' => $category));
$fw->begin_form();
$fw->textinput('fcg_name', 'Name', array('validation' => array('required' => true, 'maxlength' => 100)));
$fw->textinput('fcg_slug', 'Address', array(
	'helptext' => 'The category\'s web address: /forum/{this}. Lowercase letters, numbers and hyphens. Left empty, it is made from the name.',
));
$fw->textarea('fcg_description', 'Description', array('rows' => 3, 'helptext' => 'One or two sentences shown under the name.'));
$fw->dropinput('fcg_read_rule', 'Who can read', array(
	'options' => array(
		'public'  => 'Anyone, including signed-out visitors (search engines can index it)',
		'members' => 'Any signed-in member',
		'tier'    => 'Members on a subscription tier',
		'group'   => 'Members of a group',
		'staff'   => 'Staff only (moderators)',
	),
	'value' => (string)($category->get('fcg_read_rule') ?: 'members'),
));
$fw->dropinput('fcg_post_rule', 'Who can post', array(
	'options' => array(
		'members' => 'Any signed-in member who can read it',
		'tier'    => 'Members on a subscription tier',
		'group'   => 'Members of a group',
		'staff'   => 'Staff only (an announcements category)',
	),
	'value' => (string)($category->get('fcg_post_rule') ?: 'members'),
));
$fw->dropinput('fcg_tier_min_level', 'Subscription tier', array(
	'options'  => $tiers,
	'value'    => $category->get('fcg_tier_min_level') ? (string)(int)$category->get('fcg_tier_min_level') : '',
	'helptext' => 'Used when a rule above is "Members on a subscription tier".',
));
$fw->dropinput('fcg_grp_group_id', 'Group', array(
	'options'  => $groups,
	'value'    => $category->get('fcg_grp_group_id') ? (string)(int)$category->get('fcg_grp_group_id') : '',
	'helptext' => 'Used when a rule above is "Members of a group".',
));
$fw->textinput('fcg_sort_order', 'Order', array(
	'value'    => (string)(int)$category->get('fcg_sort_order'),
	'helptext' => 'Lower numbers come first on the forum home.',
));
$fw->submitbutton('btn_submit', $is_new ? 'Create category' : 'Save category', array('class' => 'btn btn-primary'));
$fw->end_form();
$page->end_box();
$page->admin_footer();
