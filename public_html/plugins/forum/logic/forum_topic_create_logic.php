<?php
/**
 * API action: forum/forum_topic_create — start a topic in a category.
 *
 * POST /api/v1/action/forum/forum_topic_create
 *   category_id, title, body (markdown), attachments (file ids from forum_upload)
 *
 * Answers the topic's address. A new member's first posts may be held for
 * approval (held: true); the author sees a held topic, nobody else does yet.
 *
 * @version 1.0.0
 */

function forum_topic_create_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		$category = Forum::categoryFor($input['category_id'] ?? 0, $user_id);
		$result = ForumPosting::createTopic($category, $user_id, (string)($input['title'] ?? ''),
			(string)($input['body'] ?? ''), ForumAction::ids($input['attachments'] ?? array()));
		return array(
			'topic_id' => (int)$result['topic']->key,
			'url'      => $result['topic']->url(),
			'held'     => $result['held'],
		);
	});
}

function forum_topic_create_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Start a forum topic: a title and an opening post.',
		'mutates'     => true,
		'input'       => array(
			'category_id' => array('type' => 'int', 'required' => true, 'label' => 'Category'),
			'title'       => array('type' => 'string', 'required' => true, 'max_length' => 200, 'label' => 'Title'),
			'body'        => array('type' => 'text', 'required' => true, 'label' => 'Opening post'),
			'attachments' => array('type' => 'array', 'required' => false, 'items' => array('type' => 'int'), 'max_items' => 10, 'label' => 'Attached files'),
		),
		'ai_agent'    => 'confirm',
	));
}
