<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('devices_link_logic.php', 'logic'));

	$page_vars = process_logic(devices_link_logic(array_merge($_GET, $_POST, $params ?? [])));

	$page = new PublicPage();
	$page->needs_vault_client();
	$page->public_header([
		'title' => 'Link a device',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Link a device</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li><a href="/profile/security">Devices</a></li>
                            <li class="active">Link</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start(); ?>

            <div class="jy-panel jy-form-actions">
                <div id="dlkAlert" hidden></div>

                <!-- What is asking, filled in once the code resolves. -->
                <dl id="dlkDetails" class="jy-mt-2" hidden>
                    <dt>Device</dt><dd id="dlkName"></dd>
                    <dt>Type</dt><dd id="dlkPlatform"></dd>
                    <dt>Requested from</dt><dd id="dlkIp"></dd>
                </dl>

                <?php
                $formwriter = $page->getFormWriter('form1', [
                    'action' => '/profile/devices/link',
                ]);
                $formwriter->begin_form();
                devices_link_logic_form($formwriter, $page_vars, array_merge($_GET, $_POST));
                echo '<button type="button" id="dlkDeny" class="btn btn-secondary">Not me — refuse</button>';
                $formwriter->end_form();
                ?>
            </div>

            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<script>window.DEVICE_LINK_CFG = <?php echo json_encode([
    'code'            => $page_vars['code'] ?? '',
    'hasVault'        => (bool)($page_vars['has_vault'] ?? false),
]); ?>;</script>
<script defer src="/assets/js/device-link.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/device-link.js')) ?: '1'; ?>"></script>
<?php
$page->public_footer(['track' => TRUE]);
?>
