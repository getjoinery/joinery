<?php
/**
 * /support_desk/ask — ask the support desk with no site yet (the public
 * widget, specs/support_chatbot.md, Pre-install). Answers come from the
 * documentation of the newest release this node holds and its public pages.
 * Off unless support_desk_widget_enabled.
 *
 * @version 0.1.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

if (!SupportWidget::enabled()) {
	LibraryFunctions::display_404_page();
	return;
}

$page = new PublicPage();
$page->public_header(array(
	'title'       => 'Ask about Joinery',
	'description' => 'Ask how to install and run Joinery; answers come from the documentation.',
));
$fw = $page->getFormWriter('support_widget');
?>
<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container" style="max-width:46rem">
		<div class="jy-page-header"><div class="jy-page-header-bar"><h1>Ask about Joinery</h1></div></div>
		<p>Ask how to install Joinery, what it does, or how something works. An assistant answers from the documentation and names the page it used.
			It cannot see any site. Your questions are kept so the documentation can improve; do not include passwords or anything private.</p>
		<div class="joai-chat-transcript jy-support-transcript" id="sdk-widget-transcript" aria-live="polite"></div>
		<div class="jy-support-composer" id="sdk-widget-composer">
			<textarea class="jy-support-input" id="sdk-widget-input" rows="3" maxlength="8000" placeholder="Ask a question…"></textarea>
			<div id="sdk-widget-captcha"><?php $fw->captcha_hidden_input(); ?></div>
			<div class="jy-support-notice is-warn" id="sdk-widget-error" hidden></div>
			<button type="button" class="jy-support-btn is-primary" id="sdk-widget-send">Ask</button>
		</div>
	</div>
</section>
</div>
<?php echo ChatTranscript::assets(); ?>
<link rel="stylesheet" href="/assets/css/support_panel.css?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/css/support_panel.css')) ?: '1'; ?>">
<script src="/plugins/support_desk/assets/js/support_widget.js?v=<?php echo @filemtime(PathHelper::getIncludePath('plugins/support_desk/assets/js/support_widget.js')) ?: '1'; ?>"></script>
<?php
$page->public_footer(array('track' => TRUE));
