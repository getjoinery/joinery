<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password_edit_logic.php', 'logic'));

	$page_vars = process_logic(password_edit_logic($_GET, $_POST));

	$page = new PublicPage();
	$hoptions=array(
		'title'=>$page_vars['page_title'],
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			$page_vars['page_title'] => '',
		),
	);
	$page->public_header($hoptions);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1><?php echo htmlspecialchars($page_vars['page_title']); ?></h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile/profile">My Profile</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($page_vars['page_title']); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 720px; margin: 0 auto;">

            <?php echo PublicPage::tab_menu($page_vars['tab_menus'], 'Change Password'); ?>

            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2rem; margin-top: 1.5rem;">
                <?php
                $formwriter = $page->getFormWriter('form1', [
                    'action' => '/profile/password_edit'
                ]);

                $formwriter->begin_form();

                foreach($page_vars['display_messages'] AS $display_message) {
                    if($display_message->identifier == 'addressbox') {
                        echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
                    }
                }

                if ($page_vars['has_old_password']) {
                    $formwriter->passwordinput('usr_old_password', 'Old Password');
                }
                $formwriter->passwordinput('usr_password', 'New Password', [
                    'description' => 'Must be at least 5 characters.'
                ]);
                $formwriter->passwordinput('usr_password_again', 'Retype New Password');
                echo '<a href="/profile/account_edit">Cancel</a> ';
                $formwriter->submitbutton('btn_submit', 'Submit');

                $formwriter->end_form();
                ?>
            </div>

        </div>
    </div>
</section>

<?php
$page->public_footer($foptions=array('track'=>TRUE));
?>
