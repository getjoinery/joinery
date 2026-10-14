<?php
/**
 * Forum topics admin logic: every topic, newest activity first, with the
 * removed ones a moderator can restore or remove for good. POST actions.
 *
 * @version 1.0.0
 */

function admin_forum_topics_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(ForumPost::$write_permission);
	$user_id = (int)$session->get_user_id();

	if (LibraryFunctions::isFormSubmission() && !empty($_POST['action']) && !empty($_POST['ftp_forum_topic_id'])) {
		$id = (int)$_POST['ftp_forum_topic_id'];
		$flash = '';
		if ($id > 0 && ForumTopic::check_if_exists($id)) {
			$topic = new ForumTopic($id, TRUE);
			$title = (string)$topic->get('ftp_title');
			try {
				switch ($_POST['action']) {
					case 'restore':
						ForumPosting::restoreTopic($topic, $user_id);
						$flash = 'Restored "' . $title . '".';
						break;
					case 'purge':
						if ($topic->get('ftp_delete_time')) {
							$category = Forum::liveCategory($topic->categoryId());
							$topic->permanent_delete();
							if ($category) {
								ForumCache::categoryChanged($category);
							}
							$flash = 'Removed "' . $title . '" and every post in it for good.';
						}
						break;
				}
			} catch (ForumRefusal $e) {
				$flash = $e->getMessage();
			}
		}
		return LogicResult::redirect('/plugins/forum/admin/admin_forum_topics?show=deleted&flash=' . urlencode($flash));
	}

	$show = ($input['show'] ?? '') === 'deleted' ? 'deleted' : 'live';
	$per_page = 50;
	$offset = max(0, (int)($input['offset'] ?? 0));
	$options = array('deleted' => $show === 'deleted');
	if (!empty($input['ftp_forum_topic_id'])) {
		$options['ftp_forum_topic_id'] = (int)$input['ftp_forum_topic_id'];
	}
	$total = (new MultiForumTopic($options))->count_all();
	$topics = array();
	foreach (new MultiForumTopic($options, array('ftp_last_post_time' => 'DESC'), $per_page, $offset) as $topic) {
		$topics[] = $topic;
	}
	return LogicResult::render(array(
		'session'  => $session,
		'topics'   => $topics,
		'show'     => $show,
		'total'    => $total,
		'per_page' => $per_page,
		'flash'    => (string)($input['flash'] ?? ''),
	));
}
