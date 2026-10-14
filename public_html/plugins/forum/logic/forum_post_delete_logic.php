<?php
/**
 * API action: forum/forum_post_delete — remove a post. Removing an opening post
 * removes its topic.
 *
 * POST /api/v1/action/forum/forum_post_delete   post_id
 *
 * Its author, or a moderator. An author cannot remove a topic others have
 * replied to (a moderator can). Answers where the page should go next.
 *
 * @version 1.0.0
 */

function forum_post_delete_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		list($post, $topic, $category) = Forum::postFor($input['post_id'] ?? 0, $user_id);
		$what = ForumPosting::deletePost($post, $topic, $category, $user_id);
		return array(
			'removed' => $what,
			'url'     => $what === 'topic' ? $category->url() : $topic->url(),
		);
	});
}

function forum_post_delete_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Remove a forum post (its author, or a moderator).',
		'mutates'     => true,
		'input'       => array(
			'post_id' => array('type' => 'int', 'required' => true, 'label' => 'Post'),
		),
		'ai_agent'    => 'confirm',
	));
}
