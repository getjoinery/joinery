<?php
/**
 * API action: forum/forum_search — search the forum.
 *
 * POST /api/v1/action/forum/forum_search
 *   q, category_id (optional), sort: best | newest, page
 *
 * Only what the searcher may read. Signed-out visitors may search too (the
 * public categories), as the search page itself allows.
 *
 * @version 1.0.0
 */

function forum_search_logic(array $input): LogicResult {
	try {
		Forum::requireActive();
	} catch (ForumRefusal $e) {
		return LogicResult::error($e->getMessage());
	}
	$sort = ($input['sort'] ?? '') === 'newest' ? 'newest' : 'best';
	$category_id = isset($input['category_id']) && (int)$input['category_id'] > 0 ? (int)$input['category_id'] : null;
	return LogicResult::render(ForumSearch::search((string)($input['q'] ?? ''), Forum::viewerId(), $category_id, $sort,
		max(1, (int)($input['page'] ?? 1))));
}

function forum_search_logic_descriptor(): array {
	return array(
		'description'      => 'Search the forum posts the caller may read.',
		'mutates'          => false,
		'requires_session' => true,
		'requires_setting' => 'forum_active',
		'input'            => array(
			'q'           => array('type' => 'string', 'required' => true, 'max_length' => 200, 'label' => 'Search for'),
			'category_id' => array('type' => 'int', 'required' => false, 'label' => 'Category'),
			'sort'        => array('type' => 'string', 'required' => false, 'enum' => array('best', 'newest'), 'label' => 'Order'),
			'page'        => array('type' => 'int', 'required' => false, 'label' => 'Page'),
		),
		'auth'             => array('capability' => 'write', 'min_user_permission' => 0, 'allow_guest' => true),
		'ai_agent'         => 'auto',
	);
}
