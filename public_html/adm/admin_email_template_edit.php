<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/content_versions_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if (isset($_REQUEST['emt_email_template_id'])) {
		$email_template = new EmailTemplateStore($_REQUEST['emt_email_template_id'], TRUE);
	} else {
		$email_template = new EmailTemplateStore(NULL);
	}

	if($_POST){

		$editable_fields = array('emt_body', 'emt_name', 'emt_type');

		foreach($editable_fields as $field) {
			$email_template->set($field, $_POST[$field]);
		}
		
		$email_template->prepare();
		$email_template->save();
		$email_template->load();
		
		LibraryFunctions::redirect('/admin/admin_email_template?emt_email_template_id='. $email_template->key);
		return;
	}

	$title = $email_template->get('emt_name');
	$content = $email_template->get('emt_body');
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($_GET['cnv_content_version_id']){
		$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'email-templates',
		'breadcrumbs' => array(
			'Email Templates'=>'/admin/admin_email_templates', 
			'Edit Email Template' => '',
		),
		'session' => $session,
	)
	);	

	$pageoptions['title'] = "Edit Email Template";
	$page->begin_box($pageoptions);

	echo '<div class="row">
    <div class="col-md-8">
      <div class="p-3">';

	// Editing an existing email
	$formwriter = $page->getFormWriter('form1');
	
	$validation_rules = array();
	$validation_rules['emt_body']['required']['value'] = 'true';
	$validation_rules['emt_subject']['required']['value'] = 'true';
	$validation_rules['emt_subject']['maxlength']['value'] = 255;
	if($_SESSION['permission'] == 10){
		$validation_rules['emt_name']['required']['value'] = 'true';
	}
	$validation_rules['emt_body']['minlength']['value'] = 3;
	echo $formwriter->set_validate($validation_rules);	

	echo $formwriter->begin_form('form', 'POST', '/admin/admin_email_template_edit');

	if($email_template->key){
		echo $formwriter->hiddeninput('emt_email_template_id', $email_template->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Template Name', 'emt_name', NULL, 100, $email_template->get('emt_name'), '', 255, '');	

	echo $formwriter->textinput('Subject Line', 'emt_subject', NULL, 100, $email_template->get('emt_subject'), 'Email subject line (required)', 255, '');

	$optionvals = array("Outer"=>EmailTemplateStore::TEMPLATE_TYPE_OUTER, "Inner"=>EmailTemplateStore::TEMPLATE_TYPE_INNER, "Footer"=>EmailTemplateStore::TEMPLATE_TYPE_FOOTER);
	echo $formwriter->dropinput("Template Type", "emt_type", "ctrlHolder", $optionvals, $email_template->get('emt_type'), '', FALSE);

	echo $formwriter->textbox('Template body', 'emt_body', 'ctrlHolder', 20, 80, $email_template->get('emt_body'), '', 'no');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_EMAIL_TEMPLATE, 'foreign_key_id' => $email_template->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array($session, FALSE);

	if(count($optionvals)){
		$formwriter = $page->getFormWriter('form_load_version');
		echo $formwriter->begin_form('form_load_version', 'GET', '/admin/admin_post_edit');
		echo $formwriter->hiddeninput('emt_email_template_id', $email_template->key);
		echo $formwriter->dropinput("Load another version", "cnv_content_version_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
		echo $formwriter->new_form_button('Load');	
		echo $formwriter->end_form();
	}
	else{
		echo 'No saved versions.';
	}

	echo '	</div>
	</div>
</div>	';

?>
<script>
$(document).ready(function() {
    // Add character counter for subject field
    var subjectField = $('#emt_subject');
    if (subjectField.length) {
        // Add counter display after the subject field
        subjectField.after('<div class="field-help"><span id="subject-char-count">0/255</span> characters</div>');
        
        // Update counter on input
        subjectField.on('input', function() {
            var length = $(this).val().length;
            $('#subject-char-count').text(length + '/255');
            
            if (length > 255) {
                $(this).addClass('error');
                $('#subject-char-count').addClass('error');
            } else {
                $(this).removeClass('error');
                $('#subject-char-count').removeClass('error');
            }
        });
        
        // Initialize counter
        subjectField.trigger('input');
    }
});
</script>
<?php

	$page->admin_footer();

?>
