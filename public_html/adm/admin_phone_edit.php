<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_phone_edit_logic.php'));

	$page_vars = process_logic(admin_phone_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'users',
		'page_title' => 'Phone Edit',
		'readable_title' => 'Phone Edit',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	//PhoneNumber::ValidateJS();
?>

			<section class="contact-page-area section-gap">
				<div class="container">
<?php if (isset($phn_phone_number_id)) { ?>
		   <h3>Edit Phone Number</h3>
<?php } else { ?>
		   <h3>Add Phone Number</h3>
<?php }

	$formwriter = $page->getFormWriter('form1');
	echo $formwriter->begin_form("", "post", "/admin/admin_phone_edit");

	PhoneNumber::PlainForm($formwriter, $phone_number);
	echo $formwriter->hiddeninput('usr_user_id', $user_id);
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

	echo $formwriter->end_form();

	$page->endtable();
?>

    </div>
</section>

<?php
	$page->admin_footer();
?>
