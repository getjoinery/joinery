<?php
/**
 * validate_server_file — admin-only file-existence check for settings fields.
 *
 * Backs the FormWriter `remote` validator on three admin settings paths. Field
 * whitelist is strict by design: this is an admin-only file-existence oracle, so
 * it never accepts an arbitrary path key. Returns {valid: bool} (true = the path
 * exists and is readable, or is empty).
 *
 * @version 1.0.0
 */

function validate_server_file_logic(array $input): LogicResult {
	$field   = isset($input['field']) ? (string) $input['field'] : '';
	$allowed = ['apache_error_log', 'preview_image', 'logo_link'];
	if (!in_array($field, $allowed, true)) {
		return LogicResult::render(['valid' => false]);
	}

	$value = isset($input['value']) ? (string) $input['value'] : '';
	if ($value === '') {
		return LogicResult::render(['valid' => true]); // empty is valid
	}

	$file_path = $value;
	if ($field === 'logo_link') {
		// logo_link is a site-relative URL: must start with '/', then map to the
		// filesystem under the site root.
		if ($file_path[0] !== '/') {
			return LogicResult::render(['valid' => false]);
		}
		$file_path = PathHelper::getRootDir() . $file_path;
	}

	$valid = (file_exists($file_path) && is_readable($file_path));
	return LogicResult::render(['valid' => $valid]);
}

function validate_server_file_logic_descriptor(): array {
	return [
		'description' => 'Admin-only existence check for a whitelisted server file path (settings fields).',
		'mutates'     => false,
		'auth'        => [
			'capability'          => 'read',
			'min_user_permission' => 5,
		],
		'input'       => [
			'field' => ['type' => 'string', 'required' => true, 'enum' => ['apache_error_log', 'preview_image', 'logo_link'], 'label' => 'Settings field'],
			'value' => ['type' => 'string', 'required' => false, 'label' => 'Path value'],
		],
	];
}
?>
