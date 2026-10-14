<?php
/**
 * API action: forum/forum_post_edit — change a post's text (and, for an opening
 * post, its topic's title).
 *
 * POST /api/v1/action/forum/forum_post_edit
 *   post_id, body (markdown), title (opening post only, optional)
 *
 * Its author, or a moderator. The post shows "edited"; moderators can read the
 * old text in the change history.
 *
 * @version 1.0.0
 */

function forum_post_edit_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		list($post, $topic, $category) = Forum::postFor($input['post_id'] ?? 0, $user_id);
		$title = isset($input['title']) && $input['title'] !== '' ? (string)$input['title'] : null;
		$post = ForumPosting::editPost($post, $topic, $category, $user_id, (string)($input['body'] ?? ''), $title);
		$topic->load();
		return array(
			'post_id' => (int)$post->key,
			'html'    => (string)$post->get('fpo_body_html'),
			'title'   => (string)$topic->get('ftp_title'),
			'url'     => $topic->url(ForumPages::pageOf($topic, (int)$post->key, $user_id, Forum::mayModerate($category, $user_id)), (int)$post->key),
		);
	});
}

function forum_post_edit_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Edit a forum post (its author, or a moderator).',
		'mutates'     => true,
		'input'       => array(
			'post_id' => array('type' => 'int', 'required' => true, 'label' => 'Post'),
			'body'    => array('type' => 'text', 'required' => true, 'label' => 'Text'),
			'title'   => array('type' => 'string', 'required' => false, 'max_length' => 200, 'label' => 'Topic title'),
		),
		'ai_agent'    => 'confirm',
	));
}
