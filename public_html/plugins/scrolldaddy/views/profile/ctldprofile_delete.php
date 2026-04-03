<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('ctldprofile_delete_logic.php', 'logic', 'system', null, 'controld'));

$page_vars = process_logic(ctldprofile_delete_logic($_GET, $_POST));
	$profile = $page_vars['profile'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Delete Profile', 
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Delete Profile' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Delete Profile', $hoptions);
	
	$formwriter = $page->getFormWriter('delete_form', ['action' => '/profile/ctldprofile_delete', 'method' => 'POST']);

	echo $formwriter->begin_form();
	
		?>
                        <div class="job-content">
                            <div class="job-post_date">
								<h3><?php echo $name; ?></h3>
                                <!--<span class="date"><i class="fa-regular fa-trash"></i></span>-->
                                <!--<div class="icon"><a href="/profile/ctlddevice_delete?device_id=<?php echo $device->key; ?>"><i class="fa-regular fa-trash"></i> Delete</a></div>-->
                            </div>
                        </div>
	<?php

	$formwriter->hiddeninput('profile_id', '', ['value' => $profile->key]);
	
	echo '<p>You are about to delete this scheduled profile. After your scheduled profile is deleted, your default profile will always be active.</p>';

	echo $formwriter->checkboxinput('confirm', 'Confirm deletion', ['value' => 1]);

	echo $formwriter->submitbutton('btn_submit', 'Delete', ['class' => 'btn btn-primary']);
	echo $formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
