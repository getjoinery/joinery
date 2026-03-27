<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/MemberPage.php'));
	require_once(PathHelper::getThemeFilePath('phone_numbers_edit_logic.php', 'logic'));

	$page_vars = process_logic(phone_numbers_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new MemberPage();
	$hoptions=array(
		'title'=>'Edit Phone Number',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Edit Phone Number' => '',
		),
	);
	$page->member_header($hoptions);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Add/Edit Phone Number</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile/profile">My Profile</a></li>
                    <li class="breadcrumb-item active">Edit Phone Number</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 720px; margin: 0 auto;">

            <?php echo PublicPage::tab_menu($tab_menus, 'Edit Phone Number'); ?>

            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2rem; margin-top: 1.5rem;">
                <?php
                $formwriter = $page->getFormWriter('form1', [
                    'model' => $phone_number,
                    'edit_primary_key_value' => $phone_number->key
                ]);

                $formwriter->begin_form();

                foreach($display_messages AS $display_message) {
                    if($display_message->identifier == 'phonebox') {
                        echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
                    }
                }

                PhoneNumber::renderFormFields($formwriter, [
                    'required' => true,
                    'include_user_id' => false,
                    'model' => $phone_number
                ]);

                echo '<a href="/profile/account_edit">Cancel</a> ';
                $formwriter->submitbutton('btn_submit', 'Submit');

                $formwriter->end_form();

                $page->endtable();
                ?>
            </div>

        </div>
    </div>
</section>

<?php
$page->member_footer($foptions=array('track'=>TRUE));
?>
