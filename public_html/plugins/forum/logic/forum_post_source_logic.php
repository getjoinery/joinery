<?php
/**
 * API action: forum/forum_post_source — a post's markdown, for editing it or
 * quoting it in a reply.
 *
 * POST /api/v1/action/forum/forum_post_source   post_id
 *
 * Anyone who can read the post. The topic title comes too when the post opens
 * its topic.
 *
 * @version 1.0.0
 */

function forum_post_source_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		list($post, $topic) = Forum::postFor($input['post_id'] ?? 0, $user_id);
		return array(
			'post_id'  => (int)$post->key,
			'body'     => (string)$post->get('fpo_body'),
			'is_first' => (bool)$post->get('fpo_is_first'),
			'title'    => $post->get('fpo_is_first') ? (string)$topic->get('ftp_title') : null,
			'author'   => Forum::person($post->get('fpo_usr_user_id'))['name'],
		);
	});
}

function forum_post_source_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Read a forum post\'s markdown source, to edit or quote it.',
		'mutates'     => false,
		'input'       => array(
			'post_id' => array('type' => 'int', 'required' => true, 'label' => 'Post'),
		),
		'ai_agent'    => 'auto',
	));
}
