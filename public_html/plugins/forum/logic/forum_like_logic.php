<?php
/**
 * API action: forum/forum_like — like or unlike a post.
 *
 * POST /api/v1/action/forum/forum_like   post_id
 *
 * Any member who can read the post, but not on their own post. Answers whether
 * it is now liked and the post's count.
 *
 * @version 1.0.0
 */

function forum_like_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		list($post) = Forum::postFor($input['post_id'] ?? 0, $user_id);
		if ((int)$post->get('fpo_usr_user_id') === $user_id) {
			throw new ForumRefusal('You cannot like your own post.');
		}
		if (!$post->get('fpo_is_approved')) {
			throw new ForumRefusal('That post is waiting for approval.');
		}
		$result = Reaction::toggle($user_id, ForumPost::ENTITY_TYPE, (int)$post->key, 'like');
		return array(
			'liked' => ($result['action'] ?? '') === 'reacted',
			'count' => (int)Reaction::get_count(ForumPost::ENTITY_TYPE, (int)$post->key),
		);
	});
}

function forum_like_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Like or unlike a forum post.',
		'mutates'     => true,
		'input'       => array(
			'post_id' => array('type' => 'int', 'required' => true, 'label' => 'Post'),
		),
		'ai_agent'    => 'confirm',
	));
}
