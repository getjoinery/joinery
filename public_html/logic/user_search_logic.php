<?php
/**
 * user_search — typeahead user lookup for admin select fields.
 *
 * Read-only, staff-only (floor 5). Feeds FormWriter's AJAX select autocomplete
 * (data.items = [{id, text}]). Search branches: an '@' searches by email; a
 * multi-word term searches full name; a single word searches first/last/nickname
 * and, when numeric, the user id.
 */

function user_search_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$numperpage   = 50;
	$aoffset      = isset($input['aoffset']) ? (int) $input['aoffset'] : 0;
	$asort        = !empty($input['asort']) ? $input['asort'] : 'last_name';
	$asdirection  = !empty($input['asdirection']) ? $input['asdirection'] : 'ASC';
	$searchterm   = isset($input['q']) ? (string) $input['q'] : '';
	$includenone  = !empty($input['includenone']);
	$searchdeleted = !empty($input['searchdeleted']);

	$search_criteria = array();

	if (strstr($searchterm, '@')) {
		$search_criteria['email_like'] = $searchterm;
	} elseif ($searchterm != '') {
		$fsearch = trim(preg_replace('/\s+/', ' ', $searchterm));
		$fsearch = str_replace(' ', ' | ', $fsearch);

		if (strstr($searchterm, ' ')) {
			$search_criteria['name_like'] = $fsearch;
		} else {
			$search_criteria['first_name_like'] = $fsearch;
			$search_criteria['last_name_like']  = $fsearch;
			$search_criteria['nickname_like']   = $fsearch;
		}

		if (is_numeric($searchterm) && (int) $searchterm > 0 && (int) $searchterm < 2147483647) {
			$search_criteria['user_id'] = (int) $searchterm;
		}
	}

	if ($searchdeleted) {
		$search_criteria['deleted'] = false;
	}

	$users = new MultiUser(
		$search_criteria,
		array($asort => $asdirection),
		$numperpage,
		$aoffset,
		'OR'
	);
	$users->load();

	$items = array();
	if ($includenone) {
		$items[] = ['id' => 0, 'text' => 'None'];
	}
	foreach ($users as $user) {
		$items[] = [
			'id'   => $user->key,
			'text' => $user->display_name() . ' - ' . $user->get('usr_email'),
		];
	}

	return LogicResult::render(['items' => $items]);
}

function user_search_logic_descriptor(): array {
	return [
		'description' => 'Typeahead user search for admin select fields (returns {id, text} rows).',
		'mutates'     => false,
		'auth'        => [
			'capability'          => 'read',
			'min_user_permission' => 5,
		],
		'input'       => [
			'q'            => ['type' => 'string', 'required' => false, 'label' => 'Search term'],
			'includenone'  => ['type' => 'bool',   'required' => false, 'label' => 'Prepend a None row'],
			'searchdeleted'=> ['type' => 'bool',   'required' => false, 'label' => 'Exclude deleted users'],
			'aoffset'      => ['type' => 'int',    'required' => false, 'label' => 'Result offset'],
			'asort'        => ['type' => 'string', 'required' => false, 'label' => 'Sort column'],
			'asdirection'  => ['type' => 'string', 'required' => false, 'label' => 'Sort direction'],
		],
	];
}
?>
