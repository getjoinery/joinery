<?php
/**
 * Logic for a sandboxed plugin's admin page, rendered from its manifest
 * (door 4, plugin_tiers.md WP4). The page is the manifest's
 * declaration; nothing the plugin ships is run here. Its sections:
 *
 *   text      words from the manifest
 *   table     rows of one of the plugin's own models, read here as staff
 *   form      fields from the manifest; a submission is the plugin's to
 *             answer, through the host, waiting at most a few seconds
 *   settings  the plugin's declared settings, saved by the one settings writer
 *
 * @version 1.0
 */

function admin_sandbox_page_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_return();

	$plugin = (string)($input['plugin'] ?? '');
	if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin) || !SandboxPlugins::isActive($plugin)) {
		return LogicResult::error('That plugin is not an active sandboxed plugin.');
	}
	$manifest = SandboxPlugins::manifest($plugin);
	$pages = $manifest->adminPages();
	if (empty($pages)) {
		return LogicResult::error('That plugin has no admin pages.');
	}
	$slug = (string)($input['page'] ?? $pages[0]['slug']);
	$page = null;
	foreach ($pages as $candidate) {
		if ($candidate['slug'] === $slug) {
			$page = $candidate;
		}
	}
	if ($page === null) {
		return LogicResult::error('That page does not exist.');
	}
	$here = SandboxPluginManager::adminPageUrl($plugin, $slug);

	if (LibraryFunctions::isFormSubmission() && isset($input['action'])) {
		if ($input['action'] === 'form') {
			$index = (int)($input['section'] ?? -1);
			$section = $page['sections'][$index] ?? null;
			if (!is_array($section) || $section['type'] !== 'form') {
				return LogicResult::error('That form is not on this page.');
			}
			$values = SandboxPages::formValues($section, $input);
			$user = new User($session->get_user_id(), TRUE);
			$result = SandboxHost::ui($plugin, $section['handler'], $values, $user);
			SandboxPages::reportUiResult($session, $result, true);
			return LogicResult::redirect($here);
		}
		if ($input['action'] === 'settings') {
			$write = SettingsWriter::write($input, array('page' => 'admin_sandbox_page', 'source' => $plugin));
			SettingsWriter::reportTo($write, '~/admin/admin_sandbox_page~');
			if (empty($write['errors'])) {
				$session->save_message(new DisplayMessage('Settings saved.', '', NULL,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			}
			return LogicResult::redirect($here);
		}
	}

	// What each section shows.
	$sections = array();
	foreach ($page['sections'] as $i => $section) {
		$data = array('index' => $i, 'section' => $section);
		if ($section['type'] === 'table') {
			$data += SandboxPages::tableRows($plugin, $manifest, $section);
		}
		$sections[] = $data;
	}

	return LogicResult::render(array(
		'session'  => $session,
		'settings' => Globalvars::get_instance(),
		'plugin'   => $plugin,
		'manifest' => $manifest,
		'page'     => $page,
		'pages'    => $pages,
		'sections' => $sections,
		'here'     => $here,
	));
}
