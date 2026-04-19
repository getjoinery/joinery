<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('address_edit_logic.php', 'logic'));

	$page_vars = process_logic(address_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Edit Address',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div style="max-width: 720px; margin: 0 auto;">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Edit Address</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">My Profile</a></li>
                            <li class="active">Edit Address</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::tab_menu($tab_menus, 'Edit Address'); ?>

            <div class="jy-panel" style="margin-top: var(--jy-space-4);">
                <?php
                $formwriter = $page->getFormWriter('form1', [
                    'model' => $address,
                    'edit_primary_key_value' => $address->key
                ]);

                $formwriter->begin_form();

                foreach($display_messages AS $display_message) {
                    if($display_message->identifier == 'addressbox') {
                        echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
                    }
                }

                Address::renderFormFields($formwriter, [
                    'required' => true,
                    'include_country' => true,
                    'include_user_id' => false,
                    'model' => $address
                ]);

                echo '<a href="/profile/account_edit">Cancel</a> ';
                $formwriter->submitbutton('btn_submit', 'Submit');

                $formwriter->end_form();
                ?>
            </div>

        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
