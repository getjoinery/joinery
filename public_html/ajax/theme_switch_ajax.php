<?php
	// Set JSON header early
	header('Content-Type: application/json');
	
	try {
		require_once(__DIR__ . '/../includes/PathHelper.php');
		PathHelper::requireOnce('includes/SessionControl.php');
		PathHelper::requireOnce('includes/Globalvars.php');
		PathHelper::requireOnce('includes/LibraryFunctions.php');
		PathHelper::requireOnce('includes/DbConnector.php');
		PathHelper::requireOnce('includes/ThemeHelper.php');
		PathHelper::requireOnce('includes/PluginHelper.php');
		PathHelper::requireOnce('data/settings_class.php');
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

	// Validate theme exists - try directory theme first, then plugin
	$valid_theme = false;

	if (ThemeHelper::themeExists($theme)) {
		// It's a valid directory theme
		$valid_theme = true;
	} elseif (PluginHelper::isPluginActive($theme)) {
		// It's an active plugin that can act as theme
		$valid_theme = true;
	} 

	if (!$valid_theme) {
		echo json_encode(array('success' => false, 'message' => 'Theme not found'));
		exit;
	}

	try {
		// Use MultiSetting to find the setting
		$search_criteria = array('stg_name' => 'theme_template');
		$settings_list = new MultiSetting($search_criteria);
		$settings_list->load();
		
		$found = false;
		foreach($settings_list as $setting) {
			if($setting->get('stg_name') == 'theme_template'){
				// Update existing setting
				$setting->set('stg_value', $theme);
				$setting->set('stg_update_time', 'NOW()'); 
				$setting->set('stg_usr_user_id', $session->get_user_id());
				$setting->prepare();
				$setting->save();
				$found = true;
				break;
			}
		}
		
		if ($found) {
			echo json_encode(array('success' => true, 'message' => 'Theme switched successfully'));
		} else {
			echo json_encode(array('success' => false, 'message' => 'Theme template setting not found'));
		}
	} catch (Exception $e) {
		echo json_encode(array('success' => false, 'message' => 'Failed to save theme setting: ' . $e->getMessage()));
	}
?>