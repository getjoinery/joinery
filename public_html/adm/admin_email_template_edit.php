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

	// FormWriter V2 with model and edit_primary_key_value
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $email_template,
		'edit_primary_key_value' => $email_template->key
	]);

	$formwriter->begin_form();

	$formwriter->textinput('emt_name', 'Template Name', [
		'validation' => ['required' => true, 'maxlength' => 255]
	]);

	$formwriter->textinput('emt_subject', 'Subject Line', [
		'validation' => ['required' => true, 'maxlength' => 255],
		'help_text' => 'Email subject line (required)'
	]);

	$optionvals = array(EmailTemplateStore::TEMPLATE_TYPE_OUTER=>"Outer", EmailTemplateStore::TEMPLATE_TYPE_INNER=>"Inner", EmailTemplateStore::TEMPLATE_TYPE_FOOTER=>"Footer");
	$formwriter->dropinput('emt_type', 'Template Type', [
		'options' => $optionvals
	]);

	$formwriter->textbox('emt_body', 'Template body', [
		'rows' => 20,
		'cols' => 80,
		'htmlmode' => 'no',
		'validation' => ['required' => true, 'minlength' => 3]
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

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
		$formwriter = $page->getFormWriter('form_load_version', 'v2');
		$formwriter->begin_form('form_load_version', 'GET', '/admin/admin_email_template_edit');
		$formwriter->hiddeninput('emt_email_template_id', '', ['value' => $email_template->key]);
		$formwriter->dropinput('cnv_content_version_id', 'Load another version', [
			'options' => $optionvals,
			'empty_option' => '-- Select --'
		]);
		$formwriter->submitbutton('btn_load', 'Load');
		$formwriter->end_form();
	}
	else{
		echo 'No saved versions.';
	}

	echo '	</div>
	</div>
</div>	';

?>
<script src="/assets/js/form-visibility-helper.js"></script>

<script>
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var subjectField = document.getElementById('emt_subject');
        if (!subjectField) return;

        // Add counter display after the subject field
        var counterHtml = '<div class="field-help"><span id="subject-char-count">0/255</span> characters</div>';
        subjectField.insertAdjacentHTML('afterend', counterHtml);

        var counterElement = document.getElementById('subject-char-count');

        // Update counter on input
        subjectField.addEventListener('input', function() {
            var length = this.value.length;
            counterElement.textContent = length + '/255';

            if (length > 255) {
                this.classList.add('error');
                counterElement.classList.add('error');
            } else {
                this.classList.remove('error');
                counterElement.classList.remove('error');
            }
        });

        // Initialize counter
        subjectField.dispatchEvent(new Event('input'));
    });
})();
</script>
<?php

	$page->admin_footer();

?>
