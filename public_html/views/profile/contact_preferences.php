<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('contact_preferences_logic.php', 'logic'));

	$page_vars = process_logic(contact_preferences_logic($_GET, $_POST));
	$messages = $page_vars['messages'];

	$page = new MemberPage();
	$hoptions=array(
		'title'=>'Contact Preferences',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Contact Preferences' => '',
		),
	);
	$page->member_header($hoptions);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Contact Preferences</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile/profile">My Profile</a></li>
                    <li class="breadcrumb-item active">Contact Preferences</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 720px; margin: 0 auto;">

            <?php echo PublicPage::tab_menu($page_vars['tab_menus'], 'Change Contact Preferences'); ?>

            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2rem; margin-top: 1.5rem;">

                <p style="margin-bottom: 1.5rem;">If you want to stop receiving event or course emails, <a href="/profile">withdraw from the event</a>.</p>

                <?php
                foreach ($messages as $message){
                    echo PublicPage::alert($message['message_title'], $message['message'], $message['message_type']);
                }

                $formwriter = $page->getFormWriter('form1', [
                    'action' => '/profile/contact_preferences'
                ]);
                $formwriter->begin_form();

                if(empty($page_vars['optionvals'])){
                    echo '<p>You are currently not subscribed to any newsletters.</p>';
                }
                else{
                    $formwriter->checkboxList('new_list_subscribes', 'Check the box to subscribe:', [
                        'options' => $page_vars['optionvals'],
                        'checked' => $page_vars['checkedvals'],
                        'disabled' => $page_vars['disabledvals'],
                        'readonly' => $page_vars['readonlyvals']
                    ]);

                    $formwriter->hiddeninput('zone', '', ['value' => 'optional']);
                    echo '<a href="/profile/account_edit">Cancel</a> ';
                    $formwriter->submitbutton('btn_submit', 'Submit');
                }
                $formwriter->end_form();
                ?>

            </div>

        </div>
    </div>
</section>

<?php
$page->member_footer($foptions=array());
?>
