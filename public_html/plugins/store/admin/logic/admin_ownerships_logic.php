<?php
/**
 * Ownership list — who owns what, and the actions that change it.
 *
 * @version 1.1.0
 */
function admin_ownerships_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$self = '/plugins/store/admin/admin_ownerships';

	// Actions are POSTs, never links.
	if (LibraryFunctions::isFormSubmission() && !empty($input['action'])) {

		if ($input['action'] == 'revoke' || $input['action'] == 'unrevoke') {
			$ownership = new Ownership($input['own_ownership_id'], TRUE);
			if (!$ownership->key) {
				return LogicResult::error('That ownership no longer exists.');
			}
			$ownership->assert_can_write($session);
			$ownership->set('own_revoked_time', $input['action'] == 'revoke' ? gmdate('Y-m-d H:i:s') : NULL);
			$ownership->save();
			return LogicResult::redirect($self);
		}

		if ($input['action'] == 'grant') {
			$grant_user_id = (int)($input['grant_usr_user_id'] ?? 0);
			$grant_tag = trim((string)($input['grant_tag'] ?? ''));
			if (!$grant_user_id) {
				return LogicResult::error('Choose the person this ownership is for.');
			}
			if ($grant_tag === '') {
				return LogicResult::error('Name the ownership tag to grant.');
			}
			$grant_user = new User($grant_user_id, TRUE);
			if (!$grant_user->key) {
				return LogicResult::error('That user no longer exists.');
			}
			// A second active row for the same person would make revocation
			// lie: revoking one row would leave the other quietly conferring
			// ownership. One person, one live row per tag.
			if (Ownership::user_owns($grant_user->key, $grant_tag)) {
				return LogicResult::error($grant_user->display_name()
					. ' already owns this — there is nothing to grant.');
			}
			// A manual grant carries no order and no key string — comps and
			// support cases. Any key the operator's fulfillment needs stays
			// their script's business.
			$ownership = new Ownership(NULL);
			$ownership->set('own_usr_user_id', $grant_user->key);
			$ownership->set('own_tag', $grant_tag);
			$ownership->save();
			return LogicResult::redirect($self);
		}
	}

	$numperpage = 50;
	$offset = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($input, 'sort', 'own_ownership_id');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'DESC');

	$filter_tag = trim((string)LibraryFunctions::fetch_variable_local($input, 'tag', ''));
	$filter_user_id = (int)LibraryFunctions::fetch_variable_local($input, 'u', 0);
	$filter_status = LibraryFunctions::fetch_variable_local($input, 'status', '');

	$search_criteria = array();
	if ($filter_tag !== '') {
		$search_criteria['tag'] = $filter_tag;
	}
	if ($filter_user_id) {
		$search_criteria['user_id'] = $filter_user_id;
	}
	if ($filter_status === 'active') {
		$search_criteria['revoked'] = FALSE;
	}
	else if ($filter_status === 'revoked') {
		$search_criteria['revoked'] = TRUE;
	}

	$ownerships = new MultiOwnership(
		$search_criteria,
		array($sort => $sdirection),
		$numperpage,
		$offset
	);
	$numrecords = $ownerships->count_all();
	$ownerships->load();

	return LogicResult::render(array(
		'session' => $session,
		'ownerships' => $ownerships,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'filter_tag' => $filter_tag,
		'filter_user_id' => $filter_user_id,
		'filter_status' => $filter_status,
		'tags_in_use' => MultiOwnership::tags_in_use(),
	));
}
?>
