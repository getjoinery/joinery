<?php
/**
 * A sandboxed plugin's page anyone can open, signed in or not
 * (spec sandboxed_plugin_public_pages), at /{plugin}/{slug}
 * (SandboxPublicPages::forPath(), routed by RouteHelper when nothing else
 * answers the address).
 *
 * The page is drawn as a member page is: from its manifest sections, by the
 * plugin's own view, or framed. A signed-in visitor is its viewer, as on a
 * member page. With no one signed in the viewer is null, and the call is a
 * visitor's (SandboxHost's anonymous pool; SandboxRelay reads only), except
 * for a form POST that passed the site's spam checks, which runs before the
 * plugin is called (SandboxPublicPages::spamCheck()).
 *
 * No API descriptor, on purpose: an action would take a form's values with
 * no form drawn and no spam check run (P9).
 *
 * @version 1.1 - the cache judges the request's own query (reviewer2 R3); POST forms counted by the cleaner (R1)
 * @version 1.0
 */

function sandbox_public_page_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
	// Which plugin and page come from the address alone.
	$found = SandboxPublicPages::forPath($path);
	if ($found === null) {
		LibraryFunctions::display_404_page();
	}
	$plugin = $found['plugin'];
	$page = $found['page'];
	$manifest = SandboxPlugins::manifest($plugin);
	$here = SandboxPublicPages::url($plugin, $page['slug']);
	$signed_in = $session->is_logged_in();
	$viewer = $signed_in ? new User($session->get_user_id(), TRUE) : null;
	$posted = LibraryFunctions::isFormSubmission();
	$base = array(
		'session' => $session, 'plugin' => $plugin, 'manifest' => $manifest, 'page' => $page,
		'here' => $here, 'page_title' => $page['title'], 'signed_in' => $signed_in,
	);

	// A visitor's POST: the site's spam checks first, as a native public
	// form's logic runs them; the plugin is not called for one that fails.
	$access = null;
	if (!$signed_in) {
		$access = array('checked_post' => false);
		if ($posted) {
			$why = SandboxPublicPages::spamCheck($_POST);
			if ($why === 'honeypot') {
				LibraryFunctions::display_404_page();
			}
			if ($why !== '') {
				StaticPageCache::skipThisRequest();
				$session->save_message(new DisplayMessage(htmlspecialchars($why), '', NULL,
					DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
				return LogicResult::redirect($here);
			}
			$access['checked_post'] = true;
		}
	}

	// A framed page: the outer page holds the frame and core's bridge.
	if (SandboxPages::isFramed($page)) {
		SandboxPublicPages::mayCache($plugin, $page, $_GET, false, false);
		return LogicResult::render($base + array('framed' => true));
	}

	// Drawn by the plugin's own view, cleaned by core.
	if (SandboxPages::isPluginDrawn($page)) {
		list($query, $fields) = SandboxPages::requestValues($_GET, $posted ? $_POST : array());
		$drawn = SandboxPages::drawPluginPage($plugin, 'public', $page, $here, $query, $fields,
			$posted ? 'POST' : 'GET', $viewer, array(), $access);
		if (!empty($drawn['redirect'])) {
			return LogicResult::redirect($drawn['redirect']);
		}
		SandboxPublicPages::mayCache($plugin, $page, $_GET, !empty($drawn['ok']), (int)($drawn['post_forms'] ?? 0) > 0);
		return LogicResult::render($base + array('drawn' => $drawn, 'page_title' => $drawn['title'] ?? $page['title']));
	}

	// Drawn from its manifest: a form's submission is the plugin's to answer.
	if ($posted && isset($input['section'])) {
		$section = $page['sections'][(int)$input['section']] ?? null;
		if (!is_array($section) || $section['type'] !== 'form') {
			return LogicResult::error('That form is not on this page.');
		}
		$result = SandboxHost::ui($plugin, $section['handler'], SandboxPages::formValues($section, $input), $viewer,
			array('source' => 'form', 'kind' => 'public', 'page' => (string)$page['slug'], 'access' => $access));
		SandboxPages::reportUiResult($session, $result, false);
		return LogicResult::redirect($here);
	}

	SandboxPublicPages::mayCache($plugin, $page, $_GET, true, SandboxPublicPages::hasFormSection($page));
	return LogicResult::render($base);
}
