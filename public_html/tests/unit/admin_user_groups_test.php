<?php
/** @joinery-test
 * name: admin_user_groups
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * The Groups card on the admin user page (specs/post_release_fleet_defects.md B4.2).
 *
 * The view used to list the user's groups and then re-query membership per
 * row; the second query could disagree with the first (a row removed in
 * between), and the page warned and emitted a Remove button that removed
 * nothing. The logic now reads the membership rows once
 * (Group::get_member_ids_for_member) and the view renders Remove only for a
 * group with a row behind it. Pinned here by rendering the card's loop, as the
 * view has it, over fixture rows: a user in two groups renders two Remove
 * buttons with ids; a user in none renders no button and no warning.
 *
 * Run: php tests/unit/admin_user_groups_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$suffix = substr(md5((string)microtime(true)), 0, 6);
$user = make_user_row('grp' . $suffix);
$loner = make_user_row('grpnone' . $suffix);

$groups_made = array();
foreach (array('Alpha', 'Beta') as $n) {
	$g = new Group(null);
	$g->set('grp_name', 'Harness ' . $n . ' ' . $suffix);
	$g->set('grp_category', 'user');
	$g->save();
	harness_register_model('Group', (int)$g->key);
	$m = new GroupMember(null);
	$m->set('grm_grp_group_id', $g->key);
	$m->set('grm_foreign_key_id', $user->key);
	$m->save();
	harness_register_model('GroupMember', (int)$m->key);
	$groups_made[(int)$g->key] = (int)$m->key;
}

section('The membership rows are read once, group id => member id');

$map = Group::get_member_ids_for_member($user->key);
check($map === $groups_made || (count($map) === 2 && array_diff_assoc($groups_made, $map) === array()),
	'two groups, two member ids', json_encode($map));
check(Group::get_member_ids_for_member($loner->key) === array(), 'a user in no group has an empty map');
check(Group::get_member_ids_for_member(0) === array(), 'no id, no query');

// The loop as the view has it, taken from the file so the test cannot drift
// from what renders.
$view = (string)file_get_contents(PathHelper::getIncludePath('adm/admin_user.php'));
$start = strpos($view, "foreach(\$groups as \$group): ?>");
$end = strpos($view, "<?php endforeach; ?>", $start);
check($start !== false && $end !== false, 'the Groups loop is findable in the view');
$loop = '<?php ' . substr($view, $start, $end - $start) . '<?php endforeach; ?>';

$render = function ($the_user) use ($loop) {
	$groups = Group::get_groups_for_member($the_user->key, 'user', false, 'objects');
	$group_member_ids = Group::get_member_ids_for_member($the_user->key);
	$user = $the_user;
	$warnings = array();
	set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
	ob_start();
	eval('?>' . $loop);
	$html = ob_get_clean();
	restore_error_handler();
	return array($html, $warnings);
};

section('A user in two groups renders two Remove buttons with ids');

list($html, $warnings) = $render($user);
check($warnings === array(), 'no warning', implode('; ', $warnings));
check(substr_count($html, 'Remove') >= 2, 'two Remove buttons', $html);
foreach ($groups_made as $gid => $mid) {
	check(preg_match('/name="grm_group_member_id"[^>]*value="' . $mid . '"/', $html) === 1
		|| preg_match('/value="' . $mid . '"[^>]*name="grm_group_member_id"/', $html) === 1,
		'a button carries member id ' . $mid);
}

section('A group whose membership row is gone renders no button');

$gone = array_key_first($groups_made);
$m = new GroupMember($groups_made[$gone], TRUE);
$m->permanent_delete();
$groups_still = Group::get_groups_for_member($user->key, 'user', false, 'objects');
check(count($groups_still) === 1, 'the group list follows the rows (the join is the list)');
list($html, $warnings) = $render($user);
check($warnings === array(), 'no warning', implode('; ', $warnings));
check(substr_count($html, 'grm_group_member_id') === 1, 'one Remove button for the one row left', $html);

section('A user in no group renders no button and no warning');

list($html, $warnings) = $render($loner);
check($warnings === array(), 'no warning', implode('; ', $warnings));
check(strpos($html, 'grm_group_member_id') === false, 'no Remove button');

harness_finish();
