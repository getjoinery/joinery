<?php
/**
 * A sandboxed plugin's page for a signed-in member, drawn from its manifest:
 * text and forms (door 4). See logic/sandbox_page_logic.php.
 *
 * @version 1.1 - its logic is logic/sandbox_page_logic.php (also the API action sandbox_page)
 * @version 1.0
 */

	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	require_once(PathHelper::getIncludePath('logic/sandbox_page_logic.php'));
	$page_vars = process_logic(sandbox_page_logic(array_merge($_GET, $_POST, $params ?? [])));
	$sbx_page = $page_vars['page'];

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
            <?php echo $page->render_messages(); ?>

            <?php foreach ($sbx_page['sections'] as $i => $section): ?>
            <div class="jy-panel jy-form-actions">
                <?php if (!empty($section['title'])): ?>
                    <h2><?php echo htmlspecialchars($section['title']); ?></h2>
                <?php endif; ?>
                <?php
                if ($section['type'] === 'text') {
                    echo '<p>' . nl2br(htmlspecialchars($section['text'])) . '</p>';
                } elseif ($section['type'] === 'form') {
                    $formwriter = $page->getFormWriter('sbxform' . $i, ['action' => $page_vars['here']]);
                    $formwriter->begin_form();
                    $formwriter->hiddeninput('action', ['value' => 'form']);
                    $formwriter->hiddeninput('section', ['value' => (string)$i]);
                    SandboxPages::renderFormFields($formwriter, $section);
                    $formwriter->submitbutton('btn_sbx_' . $i, $section['submit_label'] ?? 'Submit');
                    $formwriter->end_form();
                }
                ?>
            </div>
            <?php endforeach; ?>

            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
