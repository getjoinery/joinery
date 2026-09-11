<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('account_edit_logic.php', 'logic'));

	$page_vars = process_logic(account_edit_logic(array_merge($_GET, $_POST, $params ?? [])));

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Account Edit',
	]);

	echo $page->render_messages('userbox');
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Edit Account</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li class="active">Edit Account</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start(); ?>

            <div class="jy-panel jy-form-actions">

                <?php
                require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));
                PhotoHelper::render_photo_card('grid', 'user', $page_vars['user']->key, $page_vars['user_photos'], [
                    'set_primary_url' => '/profile/account_edit',
                    'card_title' => 'My Photos',
                    'primary_file_id' => $page_vars['user']->get('usr_pic_picture_id'),
                ]);

                $formwriter = $page->getFormWriter('form1', [
                    'action' => '/profile/account_edit'
                ]);
                $formwriter->begin_form();

                // Shared form definition — also serves GET /api/v1/form/account_edit
                account_edit_logic_form($formwriter, $page_vars['user'], array_merge($_GET, $_POST));

                $formwriter->end_form();
                ?>

            </div>
            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
PhotoHelper::render_photo_scripts('grid', 'user', $page_vars['user']->key, [
    'set_primary_url' => '/profile/account_edit',
    'confirm_delete_msg' => 'Remove this photo?',
]);

$page->public_footer(['track' => TRUE]);
?>
