<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('contact_preferences_logic.php', 'logic'));

	$page_vars = process_logic(contact_preferences_logic($_GET, $_POST));
	$messages = $page_vars['messages'];

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Contact Preferences',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div style="max-width: 720px; margin: 0 auto;">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Contact Preferences</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">My Profile</a></li>
                            <li class="active">Contact Preferences</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::tab_menu($page_vars['tab_menus'], 'Change Contact Preferences'); ?>

            <div class="jy-panel" style="margin-top: var(--jy-space-4);">

                <p>If you want to stop receiving event or course emails, <a href="/profile">withdraw from the event</a>.</p>

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
</div>
<?php
$page->public_footer();
?>
