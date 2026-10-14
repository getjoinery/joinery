<?php
/**
 * A sandboxed plugin's page for a signed-in member, drawn from its manifest
 * (door 4, specs/plugin_tiers.md WP4): text and forms.
 *
 * GET draws the page. A form submission (POST with `section`, the form's
 * index on the page) is the plugin's to answer through the plugin host, with
 * the member as the user it is told about; the answer is shown on the page
 * and, for an API caller, returned as `result` {ok, status, lines, errors}.
 * The page posts its fields as sbx_{field}; an API caller may send them as
 * `values` {field: value} instead. Only the fields the form declares are
 * sent to the plugin.
 *
 * Also the API action `sandbox_page` (the apps submit a plugin's member forms
 * through it), for a person's credential only.
 *
 * @version 1.1 - only a member page an admin approved
 * @version 1.0
 */

function sandbox_page_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$plugin = (string)($input['plugin'] ?? '');
	if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin) || !SandboxPlugins::isActive($plugin)) {
		return LogicResult::error('That page does not exist.');
	}
	$manifest = SandboxPlugins::manifest($plugin);
	// Only a page an admin approved (a member page is a grant: SandboxConsent).
	$approved_pages = SandboxConsent::effective($plugin)['member_pages'];
	$page = null;
	foreach ($manifest->profilePages() as $candidate) {
		if ($candidate['slug'] === (string)($input['page'] ?? '') && isset($approved_pages[$candidate['slug']])) {
			$page = $candidate;
		}
	}
	if ($page === null) {
		return LogicResult::error('That page does not exist.');
	}
	$here = SandboxPages::profilePageUrl($plugin, $page['slug']);

	if (LibraryFunctions::isFormSubmission() && isset($input['section'])) {
		$section = $page['sections'][(int)$input['section']] ?? null;
		if (!is_array($section) || $section['type'] !== 'form') {
			return LogicResult::error('That form is not on this page.');
		}
		if (is_array($input['values'] ?? null)) {
			foreach ($section['fields'] ?? array() as $field) {
				if (array_key_exists($field['name'], $input['values'])) {
					$input['sbx_' . $field['name']] = $input['values'][$field['name']];
				}
			}
		}
		$user = new User($session->get_user_id(), TRUE);
		$result = SandboxHost::ui($plugin, $section['handler'], SandboxPages::formValues($section, $input), $user);
		SandboxPages::reportUiResult($session, $result, false);
		return LogicResult::redirect($here, array('result' => SandboxPages::uiOutcome($result)));
	}

	return LogicResult::render(array(
		'session'    => $session,
		'plugin'     => $plugin,
		'manifest'   => $manifest,
		'page'       => $page,
		'here'       => $here,
		'page_title' => $page['title'],
	));
}

function sandbox_page_logic_descriptor(): array {
	return [
		'description'      => 'Submit a form on a sandboxed plugin\'s member page; the plugin answers.',
		'requires_session' => true,
		// A person's credential only (a browser or an app session): a
		// machine key, a sandboxed plugin's among them, would make another
		// plugin's handler think its approving admin had asked.
		'auth'             => ['requires_person_credential' => true, 'capability' => 'write'],
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'plugin'  => ['type' => 'string', 'required' => true, 'max_length' => 20, 'label' => 'Plugin'],
			'page'    => ['type' => 'string', 'required' => true, 'max_length' => 30, 'label' => 'Page'],
			'section' => ['type' => 'int', 'required' => true, 'min' => 0, 'label' => 'Form (its place on the page)'],
			'values'  => ['type' => 'object', 'required' => false, 'label' => 'Field values, by field name'],
		],
	];
}
