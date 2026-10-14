<?php
/**
 * SandboxPages — what a sandboxed plugin's admin and profile pages share.
 *
 * Both are drawn from the manifest (door 4): adm/admin_sandbox_page.php for
 * admin pages, views/profile/sandbox_page.php for a member's. This class
 * reads a submitted form by its declared fields, shows what the plugin
 * answered and reads a table's rows. A member's page's logic is
 * logic/sandbox_page_logic.php, which is also the API action the apps call.
 *
 * @version 1.1 - uiOutcome() as plain text; reportUiResult() escapes for the renderer it reaches
 * @version 1.0
 */

class SandboxPages {

	/**
	 * A form section's submitted values, by the fields its manifest declares and
	 * nothing else: a boolean is true or false, a select one of its options, the
	 * rest text of at most 10000 characters.
	 *
	 * @return array<string,mixed>
	 */
	public static function formValues(array $section, array $input): array {
		$values = array();
		foreach ($section['fields'] ?? array() as $field) {
			$name = $field['name'];
			$raw = $input['sbx_' . $name] ?? null;
			$type = $field['type'] ?? 'text';
			if ($type === 'boolean') {
				$values[$name] = !empty($raw);
			} elseif ($type === 'select') {
				$values[$name] = is_scalar($raw) && array_key_exists((string)$raw, $field['options']) ? (string)$raw : '';
			} else {
				$values[$name] = is_scalar($raw) && !is_bool($raw) ? mb_substr(trim((string)$raw), 0, 10000) : '';
			}
		}
		return $values;
	}

	/**
	 * What a UI call came to, as plain text. A module answers {message,
	 * errors, status}; anything else it returns is shown as nothing more than
	 * done or not done.
	 *
	 * @return array{ok:bool, status:string, lines:string[], errors:array<string,string>}
	 */
	public static function uiOutcome(array $result): array {
		$ok = $result['status'] === SandboxLog::STATUS_OK;
		$output = is_array($result['output'] ?? null) ? $result['output'] : array();
		$errors = array();
		foreach (is_array($output['errors'] ?? null) ? $output['errors'] : array() as $field => $message) {
			if (is_scalar($message) && count($errors) < 20) {
				$errors[mb_substr((string)$field, 0, 60)] = mb_substr((string)$message, 0, 500);
			}
		}
		if ($ok && ($output['status'] ?? 'ok') === 'error') {
			$ok = false;
		}
		if ($ok && empty($errors)) {
			$text = is_string($output['message'] ?? null) && $output['message'] !== '' ? $output['message'] : 'Done.';
			return array('ok' => true, 'status' => $result['status'], 'lines' => array(mb_substr($text, 0, 1000)), 'errors' => array());
		}
		$lines = array();
		if ($result['status'] !== SandboxLog::STATUS_OK) {
			$lines[] = mb_substr((string)$result['message'], 0, 500);
		} elseif (is_string($output['message'] ?? null) && $output['message'] !== '') {
			$lines[] = mb_substr($output['message'], 0, 500);
		}
		foreach ($errors as $field => $message) {
			$lines[] = $field . ': ' . $message;
		}
		if (empty($lines)) {
			$lines[] = 'The plugin could not do that.';
		}
		return array('ok' => false, 'status' => $result['status'], 'lines' => $lines, 'errors' => $errors);
	}

	/**
	 * Show what a UI call came to, one message per line. The admin renderer
	 * escapes a message; the member renderer prints it as HTML, so for a
	 * member each line is escaped here.
	 */
	public static function reportUiResult(SessionControl $session, array $result, bool $admin): void {
		$outcome = self::uiOutcome($result);
		$type = $outcome['ok'] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_WARNING;
		foreach ($outcome['lines'] as $line) {
			$session->save_message(new DisplayMessage($admin ? $line : htmlspecialchars($line), '', NULL,
				$type, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
	}

	/**
	 * A table section's rows, newest first unless it says otherwise.
	 *
	 * @return array{rows: SystemMultiBase, columns: array<string,string>, prefix: string}
	 */
	public static function tableRows(string $plugin, SandboxManifest $manifest, array $section): array {
		$model_name = $section['model'];
		$class = SandboxManifest::classFor($plugin, $model_name);
		$prefix = SandboxManifest::prefixFor($plugin, $model_name);
		$fields = array();
		foreach ($manifest->models() as $model) {
			if ($model['name'] === $model_name) {
				foreach ($model['fields'] as $field) {
					$fields[$field['name']] = $field;
				}
			}
		}
		$columns = array();
		foreach ($section['columns'] as $column) {
			$columns[$column] = $fields[$column]['label'] ?? ucfirst(str_replace('_', ' ', $column));
		}
		$order = (string)($section['order'] ?? '-create_time');
		$direction = substr($order, 0, 1) === '-' ? 'DESC' : 'ASC';
		$order_column = $prefix . '_' . ltrim($order, '-');
		$multi = 'Multi' . $class;
		$rows = new $multi(array('deleted' => false), array($order_column => $direction), (int)($section['limit'] ?? 50));
		return array('rows' => $rows, 'columns' => $columns, 'prefix' => $prefix, 'fields' => $fields);
	}

	public static function profilePageUrl(string $plugin, string $slug): string {
		return '/profile/sandbox_page?plugin=' . rawurlencode($plugin) . '&page=' . rawurlencode($slug);
	}

	/** Draw a form section's fields, named sbx_{field}. */
	public static function renderFormFields($formwriter, array $section): void {
		foreach ($section['fields'] ?? array() as $field) {
			$name = 'sbx_' . $field['name'];
			$options = array();
			if (!empty($field['help'])) {
				$options['helptext'] = $field['help'];
			}
			if (!empty($field['required'])) {
				$options['validation'] = array('required' => true);
			}
			switch ($field['type'] ?? 'text') {
				case 'textarea': $formwriter->textbox($name, $field['label'], $options); break;
				case 'number':   $formwriter->numberinput($name, $field['label'], $options); break;
				case 'boolean':  $formwriter->checkboxinput($name, $field['label'], $options); break;
				case 'date':     $formwriter->dateinput($name, $field['label'], $options); break;
				case 'select':   $formwriter->dropinput($name, $field['label'], $options + array('options' => $field['options'])); break;
				default:         $formwriter->textinput($name, $field['label'], $options);
			}
		}
	}
}
