<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('account_edit_logic.php', 'logic'));

	$page_vars = process_logic(account_edit_logic($_GET, $_POST));

	$page = new MemberPage();
	$hoptions=array(
		'title'=>'Account Edit',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Account Edit' => '',
		),
	);
	$page->member_header($hoptions);

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'userbox') {
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Edit Account</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile/profile">My Profile</a></li>
                    <li class="breadcrumb-item active">Edit Account</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 720px; margin: 0 auto;">

            <?php echo PublicPage::tab_menu($page_vars['tab_menus'], 'Edit Account'); ?>

            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2rem; margin-top: 1.5rem;">

                <?php
                // Photo grid
                require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));
                PhotoHelper::render_photo_card('grid', 'user', $page_vars['user']->key, $page_vars['user_photos'], [
                    'set_primary_url' => '/profile/account_edit',
                    'card_title' => 'My Photos',
                    'primary_file_id' => $page_vars['user']->get('usr_pic_picture_id'),
                ]);

                $formwriter = $page->getFormWriter('form1', [
                    'model' => $page_vars['user'],
                    'action' => '/profile/account_edit'
                ]);
                $formwriter->begin_form();

                $formwriter->textinput('usr_first_name', 'First Name', [
                    'maxlength' => 255
                ]);
                $formwriter->textinput('usr_last_name', 'Last Name', [
                    'maxlength' => 255
                ]);

                $nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
                if($nickname_display){
                    $formwriter->textinput('usr_nickname', $nickname_display, [
                        'maxlength' => 255
                    ]);
                }

                $optionvals = Address::get_timezone_drop_array();
                $formwriter->dropinput('usr_timezone', 'Your Time Zone', [
                    'options' => $optionvals
                ]);

                $formwriter->submitbutton('btn_submit', 'Submit');

                $formwriter->end_form();
                ?>

            </div>
        </div>
    </div>
</section>

</div>
<?php
// Photo grid scripts
PhotoHelper::render_photo_scripts('grid', 'user', $page_vars['user']->key, [
    'set_primary_url' => '/profile/account_edit',
    'confirm_delete_msg' => 'Remove this photo?',
]);

$page->member_footer($foptions=array('track'=>TRUE));
?>
