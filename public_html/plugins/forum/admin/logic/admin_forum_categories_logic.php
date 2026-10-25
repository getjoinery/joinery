<?php
/**
 * Forum categories admin logic: the list, and delete / undelete. Every action
 * is a POST and returns before anything renders.
 *
 * @version 1.0.0
 */

function admin_forum_categories_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();

	if (LibraryFunctions::isFormSubmission() && !empty($_POST['action']) && !empty($_POST['fcg_forum_category_id'])) {
		$id = (int)$_POST['fcg_forum_category_id'];
		$flash = '';
		if ($id > 0 && ForumCategory::check_if_exists($id)) {
			$category = new ForumCategory($id, TRUE);
			$name = (string)$category->get('fcg_name');
			switch ($_POST['action']) {
				case 'delete':
					$category->soft_delete();
					$flash = 'Deleted "' . $name . '" and hid its topics. It can be undeleted.';
					break;
				case 'undelete':
					$category->undelete();
					$flash = 'Undeleted "' . $name . '" with its topics.';
					break;
			}
			ForumCache::forumChanged();
		}
		return LogicResult::redirect('/plugins/forum/admin/admin_forum_categories?flash=' . urlencode($flash));
	}

	$show_deleted = !empty($input['deleted']);
	$rows = array();
	foreach (new MultiForumCategory(array('deleted' => $show_deleted), array('fcg_sort_order' => 'ASC', 'fcg_name' => 'ASC')) as $category) {
		$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM ftp_forum_topics WHERE ftp_fcg_forum_category_id = ? AND ftp_delete_time IS NULL');
		$q->execute(array((int)$category->key));
		$rows[] = array('category' => $category, 'topics' => (int)$q->fetchColumn());
	}
	return LogicResult::render(array(
		'session'      => $session,
		'rows'         => $rows,
		'show_deleted' => $show_deleted,
		'flash'        => (string)($input['flash'] ?? ''),
	));
}
