<?php
/**
 * Logic for a sandboxed plugin's admin page, rendered from its manifest
 * (door 4, plugin_tiers.md WP4). The page is the manifest's
 * declaration; nothing the plugin ships is run here. Its sections:
 *
 *   text      words from the manifest
 *   table     rows of one of the plugin's own models, read here as staff
 *   form      fields from the manifest; a submission is the plugin's to
 *             answer, through SandboxHost, waiting at most a few seconds
 *   settings  the plugin's declared settings, saved by the one settings writer
 *
 * @version 1.5 - a drawn page's post carries its uploads (spec sandboxed_plugin_uploads)
 * @version 1.4 - a framed page (design 9): the outer page, once its frame is approved
 * @version 1.3 - a plugin holding all-rows grants on personal models shows its pages only to a viewer who may read
 *                each of them (design 11)
 * @version 1.2 - plugin and page from the address only (reviewer2 H1)
 * @version 1.1 - a page with no sections is drawn by the plugin's own view, cleaned by core (drawn)
 * @version 1.0
 */

function admin_sandbox_page_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_return();

	// Which plugin and page come from the address, never from a posted body:
	// a plugin's form posts here and could otherwise name another plugin's.
	if (isset($_GET['plugin'])) {
		$input['plugin'] = $_GET['plugin'];
		$input['page'] = $_GET['page'] ?? null;
	}
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

	// A plugin that may read every row of a personal model shows them to
	// whoever opens its admin pages, so the viewer must be able to read that
	// model themselves (design 11): a low-level admin, or a session taken from
	// one, reads nothing through the plugin that core would refuse them.
	$viewer_level = (int)$session->get_permission();
	foreach (array_keys(SandboxConsent::effective($plugin)['records']) as $model) {
		if (class_exists((string)$model) && is_subclass_of((string)$model, 'SystemBase') && !$model::$api_public_read
				&& !Access::allows($model::access_level('read'), $viewer_level)) {
			return LogicResult::error('This plugin can read every ' . $model . ' record, which your account may not read, so its pages are not shown to you.');
		}
	}

	// A framed page: the outer page holds the frame; the framed route serves
	// the plugin's document (design 9). A framed admin page is a grant.
	if (SandboxPages::isFramed($page)) {
		if (!isset(SandboxConsent::effective($plugin)['framed_admin_pages'][$page['slug']])) {
			return LogicResult::error('This framed page has not been approved.');
		}
		return LogicResult::render(array(
			'session' => $session, 'settings' => Globalvars::get_instance(), 'plugin' => $plugin, 'manifest' => $manifest,
			'page' => $page, 'pages' => $pages, 'sections' => array(), 'framed' => true, 'here' => $here,
		));
	}

	// A page with no sections is drawn by the plugin's own view, GET or POST,
	// and every form on it posts back here (design 6).
	if (SandboxPages::isPluginDrawn($page)) {
		list($query, $fields) = SandboxPages::requestValues($_GET, LibraryFunctions::isFormSubmission() ? $_POST : array());
		$drawn = SandboxPages::drawPluginPage($plugin, 'admin', $page, $here, $query, $fields,
			LibraryFunctions::isFormSubmission() ? 'POST' : 'GET', new User($session->get_user_id(), TRUE),
			LibraryFunctions::isFormSubmission() ? $_FILES : array());
		if (!empty($drawn['redirect'])) {
			return LogicResult::redirect($drawn['redirect']);
		}
		return LogicResult::render(array(
			'session'  => $session,
			'settings' => Globalvars::get_instance(),
			'plugin'   => $plugin,
			'manifest' => $manifest,
			'page'     => $page,
			'pages'    => $pages,
			'sections' => array(),
			'drawn'    => $drawn,
			'here'     => $here,
		));
	}

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
