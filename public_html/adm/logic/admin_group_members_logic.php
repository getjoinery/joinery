<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/group_members_class.php'));

function admin_group_members_logic($get_vars, $post_vars) {
	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	if($post_vars['action'] == 'add_to_group'){
		//ADD THE USER TO A GROUP
		$group = new Group($post_vars['grp_group_id'], TRUE);
		$group->add_member($user->key);
		return LogicResult::redirect("/admin/admin_group_members?grp_group_id=".$group->key);
	}
	else if($post_vars['action'] == 'remove_from_group'){
		$groupmember = new GroupMember($post_vars['grm_group_member_id'], TRUE);
		$groupmember->remove();
		return LogicResult::redirect("/admin/admin_group_members?grp_group_id=".$groupmember->get('grm_grp_group_id'));
	}

	$grp_group_id = LibraryFunctions::fetch_variable('grp_group_id', 0, 0, '');
	$group = new Group($grp_group_id, TRUE);

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
