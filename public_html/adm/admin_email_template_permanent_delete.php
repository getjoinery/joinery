<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_email_template_permanent_delete_logic.php'));

$page_vars = process_logic(admin_email_template_permanent_delete_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'email-templates',
	'page_title' => 'EmailTemplate',
	'readable_title' => 'Delete EmailTemplate',
	'breadcrumbs' => array(
		'EmailTemplates'=>'/admin/admin_email_templates',
		'Delete ' . $email_template->get('emt_name') => '',
	),
	'session' => $session,
)
);

$pageoptions['title'] = 'Delete Email Template '.$email_template->get('emt_name');
$page->begin_box($pageoptions);

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form("form", "post", "/admin/admin_email_template_permanent_delete");

echo '<fieldset><h4>Confirm Delete</h4>';
	echo '<div class="fields full">';
	echo '<p>WARNING:  This will permanently delete this email_template ('.$email_template->get('emt_name') . ').</p>';

echo $formwriter->hiddeninput("confirm", 1);
echo $formwriter->hiddeninput("emt_email_template_id", $emt_email_template_id);

		echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

	echo '</div>';
echo '</fieldset>';
echo $formwriter->end_form();

$page->end_box();

$page->admin_footer();
?>
