<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password_edit_logic.php', 'logic'));

	$page_vars = process_logic(password_edit_logic(array_merge($_GET, $_POST, $params ?? [])));

	$page = new PublicPage();
	$page->public_header([
		'title' => $page_vars['page_title'],
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1><?php echo htmlspecialchars($page_vars['page_title']); ?></h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li class="active"><?php echo htmlspecialchars($page_vars['page_title']); ?></li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start(); ?>

            <div class="jy-panel jy-form-actions">
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

                // Shared form definition — also serves GET /api/v1/form/password_edit
                password_edit_logic_form($formwriter, $page_vars['user'], array_merge($_GET, $_POST));

                echo '<a href="/profile/account_edit">Cancel</a>';

                $formwriter->end_form();
                ?>
            </div>

            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
