<?php

//ITEMS.  DEFAULT IS TO USE THE /ITEMS/ SUBDIRECTORY
//TODO: USER CHOOSES URL NAMESPACE




//PROFILE CTLD DEVICE ROUTES - Now handled by sassa theme
if($params[0] == 'profile' && $params[1] == 'device_edit'){	
	$base_file = PathHelper::getIncludePath('theme/sassa/views/profile/ctlddevice_edit.php');
	if(file_exists($base_file)){
		$is_valid_page = true;
		require_once($base_file); 
		exit();		
	}
}

if($params[0] == 'profile' && $params[1] == 'filters_edit'){	
	$base_file = PathHelper::getIncludePath('theme/sassa/views/profile/ctldfilters_edit.php');
	if(file_exists($base_file)){
		$is_valid_page = true;
		require_once($base_file); 
		exit();		
	}
}

// Add other profile routes for ControlD - Now handled by sassa theme
if($params[0] == 'profile' && $params[1] == 'devices'){	
	$base_file = PathHelper::getIncludePath('theme/sassa/views/profile/devices.php');
	if(file_exists($base_file)){
		$is_valid_page = true;
		require_once($base_file); 
		exit();		
	}
}

if($params[0] == 'profile' && $params[1] == 'rules'){	
	$base_file = PathHelper::getIncludePath('theme/sassa/views/profile/rules.php');
	if(file_exists($base_file)){
		$is_valid_page = true;
		require_once($base_file); 
		exit();		
	}
}

if($params[0] == 'profile' && $params[1] == 'ctld_activation'){	
	$base_file = PathHelper::getIncludePath('theme/sassa/views/profile/ctld_activation.php');
	if(file_exists($base_file)){
		$is_valid_page = true;
		require_once($base_file); 
		exit();		
	}
}

// ROOT VIEWS (if needed for ControlD-specific pages) - Now handled by sassa theme
if($params[0] == 'pricing' && Plugin::is_plugin_active('controld')){	
	$base_file = PathHelper::getIncludePath('theme/sassa/views/pricing.php');
	if(file_exists($base_file)){
		$is_valid_page = true;
		require_once($base_file); 
		exit();		
	}
}

// EXISTING CREATE ACCOUNT ROUTE
if($params[0] == 'create_account'){
	$base_file = PathHelper::getIncludePath('plugins/controld/views/create_account.php');
	require_once($base_file); 
	exit();	
}

// ADMIN ROUTES (keep existing)
if($params[0] == 'plugins' && $params[1] == 'controld' && $params[2] == 'admin'){	
	$base_file = ensure_extension(PathHelper::getIncludePath('plugins/controld/admin/'.$params[3]),'php');
	if(file_exists($base_file)){
		$is_valid_page = true;
		require_once($base_file); 
		exit();		
	}
}



?>