<?php
/**
 * API action: forum/forum_post_moderate — approve or turn down a held post.
 *
 * POST /api/v1/action/forum/forum_post_moderate   post_id, op: approve | reject
 *
 * Moderators only. Approving releases the post (and its topic, if it opened
 * one) and sends the alerts it was holding; turning it down removes it.
 *
 * @version 1.0.0
 */

function forum_post_moderate_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		$op = ForumAction::oneOf($input['op'] ?? '', array('approve', 'reject'), 'operation');
		list($post) = Forum::postFor($input['post_id'] ?? 0, $user_id);
		if ($op === 'approve') {
			ForumPosting::approvePost($post, $user_id);
		} else {
			ForumPosting::rejectPost($post, $user_id);
		}
		return array('op' => $op, 'post_id' => (int)$post->key);
	});
}

function forum_post_moderate_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Approve or turn down a forum post held for approval (moderators).',
		'mutates'     => true,
		'input'       => array(
			'post_id' => array('type' => 'int', 'required' => true, 'label' => 'Post'),
			'op'      => array('type' => 'string', 'required' => true, 'enum' => array('approve', 'reject'), 'label' => 'Operation'),
		),
		'ai_agent'    => 'confirm',
	));
}
