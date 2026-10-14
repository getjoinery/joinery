<?php
/**
 * API action: forum/forum_post_create — reply in a topic.
 *
 * POST /api/v1/action/forum/forum_post_create
 *   topic_id, body (markdown), reply_to_id (the post answered, optional),
 *   attachments (file ids from forum_upload)
 *
 * Answers the reply's address (the page it lands on, with its anchor). A held
 * reply (held: true) is seen by its author and the moderators only.
 *
 * @version 1.0.0
 */

function forum_post_create_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		list($topic, $category) = Forum::topicFor($input['topic_id'] ?? 0, $user_id);
		$reply_to = isset($input['reply_to_id']) && (int)$input['reply_to_id'] > 0 ? (int)$input['reply_to_id'] : null;
		$result = ForumPosting::reply($topic, $category, $user_id, (string)($input['body'] ?? ''), $reply_to,
			ForumAction::ids($input['attachments'] ?? array()));
		$topic->load();
		return array(
			'post_id' => (int)$result['post']->key,
			'url'     => $topic->url(ForumPages::pageOf($topic, (int)$result['post']->key, $user_id, Forum::mayModerate($category, $user_id)),
				(int)$result['post']->key),
			'held'    => $result['held'],
		);
	});
}

function forum_post_create_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Reply in a forum topic.',
		'mutates'     => true,
		'input'       => array(
			'topic_id'    => array('type' => 'int', 'required' => true, 'label' => 'Topic'),
			'body'        => array('type' => 'text', 'required' => true, 'label' => 'Reply'),
			'reply_to_id' => array('type' => 'int', 'required' => false, 'label' => 'In reply to'),
			'attachments' => array('type' => 'array', 'required' => false, 'items' => array('type' => 'int'), 'max_items' => 10, 'label' => 'Attached files'),
		),
		'ai_agent'    => 'confirm',
	));
}
