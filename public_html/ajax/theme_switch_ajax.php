<?php
	// Set JSON header early
	header('Content-Type: application/json');
	
	try {
		require_once(__DIR__ . '/../includes/PathHelper.php');
		require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
		require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
		require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
		require_once(PathHelper::getIncludePath('includes/ThemeHelper.php'));
		require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
		require_once(PathHelper::getIncludePath('data/settings_class.php'));
		require_once(PathHelper::getIncludePath('data/themes_class.php'));
		require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));
	} catch (Exception $e) {
		echo json_encode(array('success' => false, 'message' => 'Failed to load dependencies: ' . $e->getMessage()));
		exit;
	}

	// Check permissions without redirecting
	$session = SessionControl::get_instance();
	if (!$session->get_user_id() || $session->get_permission() != 10) {
		echo json_encode(array('success' => false, 'message' => 'Permission denied'));
		exit;
	}

	// Get the theme parameter
	$theme = isset($_POST['theme']) ? $_POST['theme'] : '';
	
	if (empty($theme)) {
		echo json_encode(array('success' => false, 'message' => 'No theme specified'));
		exit;
	}

	// Validate theme name (security check first)
	if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
		echo json_encode(array('success' => false, 'message' => 'Invalid theme name'));
		exit;
	}

	// Validate theme exists - directory themes only
	$valid_theme = false;

	if (ThemeHelper::themeExists($theme)) {
		// It's a valid directory theme
		$valid_theme = true;
	}

	if (!$valid_theme) {
		echo json_encode(array('success' => false, 'message' => 'Theme not found'));
		exit;
	}

	try {
		$theme_manager = ThemeManager::getInstance();
		$theme_manager->activate($theme);
		echo json_encode(array('success' => true, 'message' => 'Theme switched successfully'));
	} catch (Exception $e) {
		echo json_encode(array('success' => false, 'message' => 'Failed to switch theme: ' . $e->getMessage()));
	}
?>