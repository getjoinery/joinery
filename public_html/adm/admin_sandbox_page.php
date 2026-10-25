<?php
/** @joinery-admin class=config */
/**
 * Admin: a sandboxed plugin's page, drawn from its manifest.
 * See adm/logic/admin_sandbox_page_logic.php.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_sandbox_page_logic.php'));

$page_vars = process_logic(admin_sandbox_page_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];
$tz = $session->get_timezone();
$plugin = $page_vars['plugin'];
$manifest = $page_vars['manifest'];
$sbx_page = $page_vars['page'];
$here = $page_vars['here'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'sbx-' . $plugin . '-' . $sbx_page['slug'],
	'page_title'     => $manifest->title() . ': ' . $sbx_page['title'],
	'readable_title' => $sbx_page['title'],
	// One crumb when the page is titled like its plugin (the keys would meet).
	'breadcrumbs'    => $manifest->title() === $sbx_page['title']
		? array($sbx_page['title'] => '')
		: array(
			$manifest->title() => SandboxPluginManager::adminPageUrl($plugin, $manifest->adminPages()[0]['slug']),
			$sbx_page['title'] => '',
		),
	'session'        => $session,
));

/** One stored value, as text for a table cell. */
$cell = function ($value, ?array $field) use ($tz) {
	$type = $field['type'] ?? null;
	if ($value === null || $value === '') {
		return '';
	}
	if ($type === 'boolean') {
		return $value && $value !== 'f' ? 'Yes' : 'No';
	}
	if ($type === 'json') {
		$decoded = is_string($value) ? json_decode($value, true) : $value;
		return '<code>' . htmlspecialchars(mb_substr(json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 300)) . '</code>';
	}
	if ($type === 'datetime' || $type === null) {
		if (is_string($value) && preg_match('~^\d{4}-\d\d-\d\d \d\d:\d\d~', $value)) {
			return htmlspecialchars(LibraryFunctions::convert_time($value, 'UTC', $tz, 'M j, Y g:i A'));
		}
	}
	return nl2br(htmlspecialchars(mb_substr((string)$value, 0, 500)));
};

foreach ($page_vars['sections'] as $data) {
	$section = $data['section'];
	$i = $data['index'];
	$title = $section['title'] ?? '';
	switch ($section['type']) {
		case 'text':
			$page->begin_box(array('title' => $title !== '' ? $title : $sbx_page['title']));
			echo '<p>' . nl2br(htmlspecialchars($section['text'])) . '</p>';
			$page->end_box();
			break;

		case 'table':
			// tableheader() prints its headers as HTML; these are the
			// plugin author's words.
			$page->tableheader(array_map('htmlspecialchars', array_values($data['columns'])), array('title' => $title));
			foreach ($data['rows'] as $row) {
				$values = array();
				foreach (array_keys($data['columns']) as $column) {
					$values[] = $cell($row->get($data['prefix'] . '_' . $column), $data['fields'][$column] ?? null);
				}
				$page->disprow($values);
			}
			$page->endtable();
			break;

		case 'form':
			$page->begin_box(array('title' => $title !== '' ? $title : 'Run'));
			$formwriter = $page->getFormWriter('sbxform' . $i, array('action' => $here));
			$formwriter->begin_form();
			$formwriter->hiddeninput('action', array('value' => 'form'));
			$formwriter->hiddeninput('section', array('value' => (string)$i));
			SandboxPages::renderFormFields($formwriter, $section);
			$formwriter->submitbutton('btn_sbx_' . $i, $section['submit_label'] ?? 'Submit');
			$formwriter->end_form();
			$page->end_box();
			break;

		case 'settings':
			$page->begin_box(array('title' => $title !== '' ? $title : 'Settings'));
			$formwriter = $page->getFormWriter('sbxsettings', array('action' => $here));
			$formwriter->begin_form();
			$formwriter->hiddeninput('action', array('value' => 'settings'));
			SettingsFieldRenderer::renderSource($formwriter, $plugin);
			$formwriter->submitbutton('btn_sbx_settings', 'Save settings');
			$formwriter->end_form();
			$page->end_box();
			break;
	}
}

$page->admin_footer();
?>
