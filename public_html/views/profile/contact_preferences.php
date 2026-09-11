<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('contact_preferences_logic.php', 'logic'));

	$page_vars = process_logic(contact_preferences_logic(array_merge($_GET, $_POST, $params ?? [])));
	$messages = $page_vars['messages'];

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Contact Preferences',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Contact Preferences</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li class="active">Contact Preferences</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start(); ?>

            <div class="jy-panel jy-form-actions">

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
                    // Shared form definition — also serves GET /api/v1/form/contact_preferences
                    contact_preferences_logic_form($formwriter, $page_vars['user'], array_merge($_GET, $_POST));
                    echo '<a href="/profile/account_edit">Cancel</a>';
                }
                $formwriter->end_form();
                ?>

            </div>

            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer();
?>
