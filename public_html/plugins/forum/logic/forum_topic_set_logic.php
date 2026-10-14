<?php
/**
 * API action: forum/forum_topic_set — moderate a topic.
 *
 * POST /api/v1/action/forum/forum_topic_set
 *   topic_id, op: pin | unpin | lock | unlock | move (with category_id) | delete | restore
 *
 * Moderators only (the moderator level is ForumPost::$write_permission).
 *
 * @version 1.0.0
 */

function forum_topic_set_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		$op = ForumAction::oneOf($input['op'] ?? '', array('pin', 'unpin', 'lock', 'unlock', 'move', 'delete', 'restore'), 'operation');
		$topic_id = (int)($input['topic_id'] ?? 0);
		if ($op === 'restore') {
			if ($topic_id <= 0 || !ForumTopic::check_if_exists($topic_id)) {
				throw new ForumRefusal('That topic is not available.');
			}
			$topic = new ForumTopic($topic_id, TRUE);
			ForumPosting::restoreTopic($topic, $user_id);
			return array('url' => $topic->url());
		}
		list($topic, $category) = Forum::topicFor($topic_id, $user_id);
		if (!Forum::mayModerate($category, $user_id)) {
			throw new ForumRefusal('Only a moderator can do that.');
		}
		switch ($op) {
			case 'pin':    ForumPosting::setTopic($topic, $category, $user_id, array('is_pinned' => true)); break;
			case 'unpin':  ForumPosting::setTopic($topic, $category, $user_id, array('is_pinned' => false)); break;
			case 'lock':   ForumPosting::setTopic($topic, $category, $user_id, array('is_locked' => true)); break;
			case 'unlock': ForumPosting::setTopic($topic, $category, $user_id, array('is_locked' => false)); break;
			case 'move':   ForumPosting::setTopic($topic, $category, $user_id, array('category_id' => (int)($input['category_id'] ?? 0))); break;
			case 'delete':
				ForumPosting::deleteTopic($topic, $category, $user_id);
				return array('url' => $category->url());
		}
		$topic->load();
		return array(
			'url'       => $topic->url(),
			'is_pinned' => (bool)$topic->get('ftp_is_pinned'),
			'is_locked' => (bool)$topic->get('ftp_is_locked'),
		);
	});
}

function forum_topic_set_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Pin, lock, move, remove or restore a forum topic (moderators).',
		'mutates'     => true,
		'input'       => array(
			'topic_id'    => array('type' => 'int', 'required' => true, 'label' => 'Topic'),
			'op'          => array('type' => 'string', 'required' => true, 'label' => 'Operation',
				'enum' => array('pin', 'unpin', 'lock', 'unlock', 'move', 'delete', 'restore')),
			'category_id' => array('type' => 'int', 'required' => false, 'label' => 'Move to category'),
		),
		'ai_agent'    => 'confirm',
	));
}
