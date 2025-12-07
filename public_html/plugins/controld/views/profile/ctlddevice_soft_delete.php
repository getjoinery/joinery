<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('ctlddevice_soft_delete_logic.php', 'logic', 'system', null, 'controld'));

$page_vars = process_logic(ctlddevice_soft_delete_logic($_GET, $_POST));
	$device = $page_vars['device'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();
	$name = $device->get_readable_name();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Device Add/Edit', 
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Delete Device' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Delete Device', $hoptions);

	$formwriter = $page->getFormWriter();

	//$validation_rules = array();
	//$validation_rules['confirm']['required']['value'] = 'true';

	//echo $formwriter->set_validate($validation_rules);	

	echo $formwriter->begin_form('contact-form style2', 'POST', '/profile/ctlddevice_soft_delete', true);
	
		?>
                        <div class="job-content">
                            <div class="job-post_date">
								<h3><?php echo $name; ?></h3>
                                <!--<span class="date"><i class="fa-regular fa-trash"></i></span>-->
                                <!--<div class="icon"><a href="/profile/ctlddevice_delete?device_id=<?php echo $device->key; ?>"><i class="fa-regular fa-trash"></i> Delete</a></div>-->
                            </div>
                        </div>
	<?php

	$formwriter->hiddeninput('device_id', '', ['value' => $device->key]);
	
	echo '<p>You are about to delete this device. </p>';

	echo $formwriter->submitbutton('submit', 'Confirm Delete', ['class' => 'btn btn-primary']);
	echo $formwriter->end_form(true);	

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
