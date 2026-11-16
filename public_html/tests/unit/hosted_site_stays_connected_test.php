<?php
/** @joinery-test
 * name: hosted_site_stays_connected
 * tier: db
 * env: any
 * needs: []
 * covers: [includes/HostedPlanNotice.php, adm/logic/admin_management_node_logic.php, adm/admin_management_node.php]
 */
/**
 * A site the operator hosts (Managed or Starter) may not disconnect from its
 * management node: its email settings, backups and updates run through that
 * connection, and a hosted site that left would go on being billed for
 * hosting it no longer gets. A self-hosted site, including one renting our
 * email and backups ('services'), stays free to leave.
 *
 * The leave path itself (which writes agent_leave_request) is never run here.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));

section('Which sites count as hosted');
foreach (array('trial', 'subscribed', 'grace', 'shutdown') as $state) {
	harness_set_setting_mem('hosted_plan_state', $state);
	check(HostedPlanNotice::hostedHere(), "a site at '$state' is one we host");
}
foreach (array('services', '') as $state) {
	harness_set_setting_mem('hosted_plan_state', $state);
	check(!HostedPlanNotice::hostedHere(), "a site at '" . ($state === '' ? '(none)' : $state) . "' is its owner's to disconnect");
}

section('A hosted site\'s Disconnect is refused');
harness_set_setting_mem('hosted_plan_state', 'subscribed');
$before = (string)Globalvars::get_instance()->get_setting('agent_leave_request', false, true);
$result = admin_management_node_logic(array('action' => 'disconnect'));
check($result->redirect !== null && strpos($result->redirect, 'error=') !== false,
	'the action answers with the reason, not a disconnect', (string)$result->redirect);
check((string)Globalvars::get_instance()->get_setting('agent_leave_request', false, true) === $before,
	'no leave request was written');

section('The page offers no Disconnect to a hosted site');
$view = (string)file_get_contents(PathHelper::getIncludePath('adm/admin_management_node.php'));
$hosted_branch = strpos($view, "elseif (!empty(\$page_vars['hosted_here']))");
$button = strpos($view, 'name="action" value="disconnect"');
check($hosted_branch !== false && $button !== false && $hosted_branch < $button,
	'the hosted explanation is decided before the Disconnect form is drawn');

harness_finish();
