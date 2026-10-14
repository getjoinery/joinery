<?php
/**
 * API action: forum/forum_upload — store a file to attach to the post being
 * written.
 *
 * POST /api/v1/action/forum/forum_upload as multipart form data with one
 * `file` field, plus category_id (the category the post is going into).
 *
 * The file is the uploader's own until the post is saved with its id in
 * `attachments`; then it becomes exactly as visible as the post. Unclaimed
 * uploads are removed after a day.
 *
 * @version 1.0.0
 */

function forum_upload_logic(array $input): LogicResult {
	return ForumAction::run(function (int $user_id) use ($input) {
		$category = Forum::categoryFor($input['category_id'] ?? 0, $user_id);
		if (!SpaceAccess::mayPost($category, $user_id)) {
			throw new ForumRefusal('You cannot post in this category.');
		}
		if (empty($_FILES['file'])) {
			throw new ForumRefusal('No file was chosen.');
		}
		$file = ForumUploads::store($_FILES['file'], $user_id);
		return array(
			'file_id'  => (int)$file->key,
			'name'     => (string)$file->get('fil_title') ?: (string)$file->get('fil_name'),
			'is_image' => strpos((string)$file->get('fil_type'), 'image/') === 0,
		);
	});
}

function forum_upload_logic_descriptor(): array {
	return ForumAction::memberDescriptor(array(
		'description' => 'Store a file (multipart `file`) to attach to a forum post being written.',
		'mutates'     => true,
		'input'       => array(
			'category_id' => array('type' => 'int', 'required' => true, 'label' => 'Category'),
		),
	));
}
