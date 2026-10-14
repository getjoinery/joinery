<?php
/**
 * API action: forum/forum_watch_set — what a member hears from a topic or a
 * category.
 *
 * POST /api/v1/action/forum/forum_watch_set
 *   target: topic | category, id, level: watching | muted | none
 *
 * Watching a topic: every reply. Muted: nothing from it, mentions included.
 * Watching a category: every new topic in it. None: back to the default.
 *
 * @version 1.0.0
 */

function forum_watch_set_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		$target = ForumAction::oneOf($input['target'] ?? '', array(ForumWatch::TARGET_TOPIC, ForumWatch::TARGET_CATEGORY), 'target');
		$level = ForumAction::oneOf($input['level'] ?? '', array(ForumWatch::LEVEL_WATCHING, ForumWatch::LEVEL_MUTED, 'none'), 'level');
		if ($target === ForumWatch::TARGET_TOPIC) {
			list($topic) = Forum::topicFor($input['id'] ?? 0, $user_id);
			$id = (int)$topic->key;
		} else {
			$category = Forum::categoryFor($input['id'] ?? 0, $user_id);
			$id = (int)$category->key;
			if ($level === ForumWatch::LEVEL_MUTED) {
				throw new ForumRefusal('A category can be watched or not; mute a topic instead.');
			}
		}
		if ($level === 'none') {
			ForumWatch::clear($user_id, $target, $id);
		} else {
			ForumWatch::setLevel($user_id, $target, $id, $level);
		}
		return array('level' => ForumWatch::levelFor($user_id, $target, $id) ?? 'none');
	});
}

function forum_watch_set_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Watch, mute or stop watching a forum topic or category.',
		'mutates'     => true,
		'input'       => array(
			'target' => array('type' => 'string', 'required' => true, 'enum' => array('topic', 'category'), 'label' => 'Topic or category'),
			'id'     => array('type' => 'int', 'required' => true, 'label' => 'Which'),
			'level'  => array('type' => 'string', 'required' => true, 'enum' => array('watching', 'muted', 'none'), 'label' => 'Level'),
		),
		'ai_agent'    => 'confirm',
	));
}
