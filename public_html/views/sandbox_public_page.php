<?php
/**
 * A sandboxed plugin's page anyone can open, signed in or not, at
 * /{plugin}/{slug} (spec sandboxed_plugin_public_pages). See
 * logic/sandbox_public_page_logic.php. RouteHelper reaches this view only
 * for an address SandboxPublicPages::forPath() names; opened at its own name
 * it is a 404.
 *
 * @version 1.0
 */

	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	require_once(PathHelper::getIncludePath('logic/sandbox_public_page_logic.php'));
	$page_vars = process_logic(sandbox_public_page_logic(array_merge($_GET, $_POST, $params ?? [])));
	$sbx_page = $page_vars['page'];

	$page = new PublicPage();
	if (!empty($page_vars['framed'])) {
		// The frame may be navigated nowhere but the framed route.
		$page->restrict_frames_to(SandboxPages::frameSource());
	}
	$page->public_header([
		'title' => $page_vars['page_title'],
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1><?php echo htmlspecialchars($page_vars['page_title']); ?></h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li class="active"><?php echo htmlspecialchars($page_vars['page_title']); ?></li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo $page->render_messages(); ?>

            <?php if (!empty($page_vars['framed'])): ?>
            <div class="jy-panel"><?php echo SandboxPages::frameMarkup($page_vars['manifest'], 'public', $sbx_page); ?></div>
            <?php endif; ?>

            <?php if (isset($page_vars['drawn'])): $drawn = $page_vars['drawn']; ?>
            <div class="jy-panel">
                <?php echo SandboxPages::provenance($page_vars['manifest']); ?>
                <?php if ($drawn['ok']): ?>
                <?php echo SandboxPages::bodyOpen() . $drawn['html'] . '</div>'; ?>
                <?php else: ?>
                <p><?php echo htmlspecialchars((string)$drawn['message']); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (isset($sbx_page['sections'])): ?>
            <div class="jy-panel"><?php echo SandboxPages::provenance($page_vars['manifest']); ?></div>
            <?php endif; ?>
            <?php foreach (($sbx_page['sections'] ?? array()) as $i => $section): ?>
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
                    if (empty($page_vars['signed_in'])) {
                        // A visitor's form carries the site's spam checks, as a native public form does.
                        $formwriter->antispam_question_input();
                        $formwriter->honeypot_hidden_input();
                        $formwriter->captcha_hidden_input();
                    }
                    $formwriter->submitbutton('btn_sbx_' . $i, $section['submit_label'] ?? 'Submit');
                    $formwriter->end_form();
                }
                ?>
            </div>
            <?php endforeach; ?>

    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
