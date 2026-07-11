<?php
/**
 * image_list — paginated uploaded-image feed for the FormWriter image selector.
 *
 * Read-only, staff-only (floor 5). Returns data.{images, total, hasMore}; each
 * image row carries id/url/thumbnail/title/filename.
 */

function image_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('data/files_class.php'));

	$search = isset($input['q']) ? trim((string) $input['q']) : '';
	$offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
	$limit  = isset($input['limit']) ? min(100, max(1, (int) $input['limit'])) : 20;

	$options = [
		'picture' => true,
		'deleted' => false,
	];
	if ($search !== '') {
		$options['filename_like'] = $search;
	}

	$files = new MultiFile($options, ['fil_file_id' => 'DESC'], $limit, $offset);
	$total = $files->count_all();
	$files->load();

	$images = [];
	foreach ($files as $file) {
		$images[] = [
			'id'        => $file->key,
			'url'       => $file->get_url('original'),
			'thumbnail' => $file->get_url('avatar'),
			'title'     => $file->get('fil_title') ?: $file->get('fil_name'),
			'filename'  => $file->get('fil_name'),
		];
	}

	return LogicResult::render([
		'images'  => $images,
		'total'   => $total,
		'hasMore' => ($offset + $limit) < $total,
	]);
}

function image_list_logic_descriptor(): array {
	return [
		'description' => 'Paginated list of uploaded images for the image selector field.',
		'mutates'     => false,
		'auth'        => [
			'capability'          => 'read',
			'min_user_permission' => 5,
		],
		'input'       => [
			'q'      => ['type' => 'string', 'required' => false, 'label' => 'Filename search'],
			'offset' => ['type' => 'int',    'required' => false, 'min' => 0, 'label' => 'Result offset'],
			'limit'  => ['type' => 'int',    'required' => false, 'min' => 1, 'max' => 100, 'label' => 'Page size'],
		],
	];
}
?>
