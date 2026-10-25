<?php
/**
 * Held posts logic: posts waiting for a moderator — a new member's first
 * posts, while forum_hold_first_posts is above 0. Approve releases a post and
 * sends the alerts it was holding; turn down removes it. Both are POSTs.
 *
 * @version 1.0.0
 */

function admin_forum_held_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();

	if (LibraryFunctions::isFormSubmission() && !empty($_POST['action']) && !empty($_POST['fpo_forum_post_id'])) {
		$id = (int)$_POST['fpo_forum_post_id'];
		$flash = '';
		if ($id > 0 && ForumPost::check_if_exists($id)) {
			$post = new ForumPost($id, TRUE);
			try {
				if ($_POST['action'] === 'approve') {
					ForumPosting::approvePost($post, $user_id);
					$flash = 'Approved. The post is visible and its notifications went out.';
				} elseif ($_POST['action'] === 'reject') {
					ForumPosting::rejectPost($post, $user_id);
					$flash = 'Turned down. The post was removed.';
				}
			} catch (ForumRefusal $e) {
				$flash = $e->getMessage();
			}
		}
		return LogicResult::redirect('/plugins/forum/admin/admin_forum_held?flash=' . urlencode($flash));
	}

	$per_page = 50;
	$offset = max(0, (int)($input['offset'] ?? 0));
	$options = array('fpo_is_approved' => false, 'deleted' => false, 'in_live_topic' => true);
	$total = (new MultiForumPost($options))->count_all();
	$rows = array();
	foreach (new MultiForumPost($options, array('fpo_forum_post_id' => 'ASC'), $per_page, $offset) as $post) {
		if (!ForumTopic::check_if_exists($post->topicId())) {
			continue;
		}
		$topic = $post->topic();
		if ($topic->get('ftp_delete_time')) {
			continue;
		}
		$rows[] = array('post' => $post, 'topic' => $topic, 'category' => $topic->category());
	}
	return LogicResult::render(array(
		'session' => $session,
		'rows'     => $rows,
		'total'    => $total,
		'per_page' => $per_page,
		'hold'    => Forum::settingInt('forum_hold_first_posts', 2, 0),
		'flash'   => (string)($input['flash'] ?? ''),
	));
}
