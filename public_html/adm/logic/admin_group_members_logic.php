<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/group_members_class.php'));

/**
 * The group's members page. Removing a member is the user page's
 * remove_from_group action; panels plugins register for 'group'
 * (AdminEntityPanelRegistry) handle their own POSTs here.
 *
 * @version 1.1
 */
function admin_group_members_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_return();

	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', 0, 0, '');
	$group = new Group($grp_group_id, TRUE);
	$group->assert_found();

	// Panels plugins add to this page handle their own POST actions.
	if (LibraryFunctions::isFormSubmission()) {
		$panel_result = AdminEntityPanelRegistry::handlePost('group', $group, $input);
		if ($panel_result !== null) {
			return $panel_result;
		}
	}

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'group_member_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$group_members = new MultiGroupMember(
		array('group_id' => $group->key),  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'AND'  //AND OR OR
	);
	$numrecords = $group_members->count_all();
	$group_members->load();

	$page_vars = array(
		'session' => $session,
		'group' => $group,
		'group_members' => $group_members,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
	);

	return LogicResult::render($page_vars);
}
