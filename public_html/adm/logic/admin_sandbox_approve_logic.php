<?php
/**
 * Logic for the page where an admin reviews what a sandboxed plugin asks for
 * and approves it (plugin_tiers.md Q2, Q11). This is consent, not a
 * warning: the plugin can do only what is listed, and only once approved.
 *
 * GET shows the plugin, what it asks for in plain words (grouped by feature),
 * what is new since the last approval, and any reason this site cannot run
 * it. POST action=approve records the approval (SandboxConsent::approve) and
 * activates the plugin, or, when it is already active, brings what it owns up
 * to the new approval (SandboxPluginManager::reconcile).
 *
 * @version 1.1 - the approval is of what was shown (reviewDigest), in one transaction with the activation
 * @version 1.0
 */

function admin_sandbox_approve_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_return();

	$plugin = (string)($input['plugin'] ?? '');
	if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin) || !SandboxPlugins::isSandboxed($plugin)) {
		return LogicResult::error('That is not a sandboxed plugin.');
	}
	$row = Plugin::get_by_plugin_name($plugin);
	if (!$row || $row->is_uninstalled()) {
		return LogicResult::error('Install the plugin before approving it.');
	}
	try {
		$manifest = SandboxPlugins::manifest($plugin);
	} catch (SandboxManifestException $e) {
		return LogicResult::error('Its manifest is not valid: ' . $e->getMessage());
	}
	$here = SandboxPluginManager::approvalUrl($plugin);
	$problems = SandboxPluginManager::validate($plugin)['errors'];

	if (LibraryFunctions::isFormSubmission() && ($input['action'] ?? '') === 'approve') {
		Access::demand(10);   // activates the plugin's code
		if ($problems) {
			return LogicResult::error('This site cannot run it: ' . implode(' ', $problems));
		}
		$user = new User($session->get_user_id(), TRUE);
		try {
			// The approval and what it sets going stand or fall together: an
			// activation that fails leaves no approval behind.
			$message = DbConnector::transaction(function () use ($plugin, $user, $input, $row, $manifest) {
				SandboxConsent::approve($plugin, $user, (string)($input['reviewed'] ?? ''));
				if ($row->is_active()) {
					SandboxPluginManager::reconcile($plugin);
					return $manifest->title() . ' is approved for what it now asks.';
				}
				(new PluginManager())->activate($plugin);
				return $manifest->title() . ' is approved and active.';
			});
			// The member menu follows what was approved.
			(new PluginManager())->syncMenus($plugin, SandboxPluginManager::menus($plugin));
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage('It could not be approved: ' . $e->getMessage(), '', NULL,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect($here);
		}
		$session->save_message(new DisplayMessage($message, '', NULL,
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect('/admin/admin_plugins');
	}

	$install = SandboxInstall::forPlugin($plugin);
	$approved = $install && $install->get('sbi_consent_time');
	$asked = SandboxConsent::grantsOf($manifest);
	$new = $approved ? SandboxConsent::additions($asked, $install->consent()) : array();

	$own = array();
	foreach ($manifest->models() as $model) {
		$own[] = ($model['label'] ?? $model['name']);
	}
	$admin_pages = array();
	foreach ($manifest->adminPages() as $page) {
		$admin_pages[] = $page['title'];
	}

	return LogicResult::render(array(
		'session'        => $session,
		'plugin'         => $plugin,
		'manifest'       => $manifest,
		'row'            => $row,
		'here'           => $here,
		'problems'       => $problems,
		'asked'          => SandboxConsent::describe($asked),
		'new'            => SandboxConsent::describe($new),
		'approved'       => (bool)$approved,
		'approved_by'    => $approved ? SandboxConsent::approver($install) : null,
		'approved_time'  => $approved ? $install->get('sbi_consent_time') : null,
		'needs_approval' => SandboxConsent::needsApproval($plugin),
		'own_models'     => $own,
		'admin_pages'    => $admin_pages,
		'settings'       => $manifest->settings(),
		'reviewed'       => SandboxConsent::reviewDigest($manifest),
	));
}
