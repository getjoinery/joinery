<?php
/**
 * API action: forum/forum_mark_read — how far a member has read a topic.
 *
 * POST /api/v1/action/forum/forum_mark_read   topic_id, post_id
 *
 * A topic page calls this once it has shown its posts, with the last one on
 * the page. The marker only moves forward, and only to a post in that topic
 * the member can see.
 *
 * @version 1.0.0
 */

function forum_mark_read_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		list($topic) = Forum::topicFor($input['topic_id'] ?? 0, $user_id);
		$post_id = (int)($input['post_id'] ?? 0);
		if ($post_id > 0 && ForumPost::check_if_exists($post_id)) {
			$post = new ForumPost($post_id, TRUE);
			if ($post->topicId() === (int)$topic->key && Forum::mayReadPost($post, $user_id)) {
				ForumTopicRead::advance($user_id, (int)$topic->key, $post_id);
			}
		}
		return array('ok' => true);
	});
}

function forum_mark_read_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Record how far the member has read a forum topic.',
		'mutates'     => true,
		'input'       => array(
			'topic_id' => array('type' => 'int', 'required' => true, 'label' => 'Topic'),
			'post_id'  => array('type' => 'int', 'required' => true, 'label' => 'Last post seen'),
		),
	));
}
