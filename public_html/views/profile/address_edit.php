<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('address_edit_logic.php', 'logic'));

	$page_vars = process_logic(address_edit_logic(array_merge($_GET, $_POST, $params ?? [])));
	extract($page_vars);

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Edit Address',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Edit Address</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li class="active">Edit Address</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start(); ?>

            <div class="jy-panel jy-form-actions">
                <?php
                $formwriter = $page->getFormWriter('form1', [
                    'model' => $address,
                    'edit_primary_key_value' => $address->key
                ]);

                $formwriter->begin_form();

                echo $page->render_messages('addressbox');

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

            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
