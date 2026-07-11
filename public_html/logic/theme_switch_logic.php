<?php
/**
 * theme_switch — activate a theme (superadmin preview bar).
 *
 * Superadmin only (floor 10), mutating. Theme name is format-validated and
 * confirmed to exist before activation.
 *
 * @version 1.0.0
 */

function theme_switch_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));

	$theme = isset($input['theme']) ? (string) $input['theme'] : '';
	if ($theme === '') {
		return LogicResult::error('No theme specified');
	}
	if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
		return LogicResult::error('Invalid theme name');
	}
	if (!ThemeHelper::themeExists($theme)) {
		return LogicResult::error('Theme not found');
	}

	try {
		ThemeManager::getInstance()->activate($theme);
	} catch (Exception $e) {
		return LogicResult::error('Failed to switch theme: ' . $e->getMessage());
	}

	return LogicResult::render(['switched' => true, 'theme' => $theme]);
}

function theme_switch_logic_descriptor(): array {
	return [
		'description' => 'Activate a theme (superadmin only).',
		'mutates'     => true,
		'auth'        => [
			'min_user_permission' => 10,
		],
		'input'       => [
			'theme' => ['type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'Theme name'],
		],
	];
}
?>
