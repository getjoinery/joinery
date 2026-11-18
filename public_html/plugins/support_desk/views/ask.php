<?php
/**
 * /support_desk/ask — ask the support desk with no site yet (the public
 * widget, specs/support_chatbot.md, Pre-install). Answers come from the
 * documentation of the newest release this node holds and its public pages.
 * Off unless support_desk_widget_enabled.
 *
 * A signed-in member sees their conversations, opens one (?thread=N, the link
 * a person's reply is emailed with), and can hand one to a person; a guest
 * is shown the address to write to (specs/hosting_after_purchase.md H1).
 *
 * @version 0.3.0 - a signed-in member is told the assistant knows their sites; ?from= carries the page they came from
 * @version 0.2.0 - a member's conversations, Talk to a person, and the address to write to
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
$member = SupportWidget::member();
$contact = SupportWidget::contact_email();
$session = SessionControl::get_instance();
$open_thread = null;
if ($member > 0 && (int)($_GET['thread'] ?? 0) > 0) {
	try {
		$open_thread = SupportWidget::open((int)$_GET['thread']);
		SupportWidget::mark_read($open_thread);
	} catch (SupportRefusal $e) {
		$open_thread = null;
	}
}
// Signed in after asking: the conversation this browser asked in comes back,
// so it can be handed to a person (B36).
if ($open_thread === null && $member > 0) {
	$held_ids = array_keys((array)($_SESSION[SupportWidget::SESSION_KEY] ?? array()));
	$last = (int)end($held_ids);
	if ($last > 0) {
		try {
			$open_thread = SupportWidget::open($last);
		} catch (SupportRefusal $e) {
			$open_thread = null;
		}
	}
}
$mine = SupportWidget::mine();
// The page a signed-in member came from (a link that carried it), and whether
// this node has anything on record about them, which the assistant is told.
$from = ($member > 0 && SupportTurn::route((string)($_GET['from'] ?? '')) !== '') ? (string)$_GET['from'] : '';
$knows_member = $member > 0 && count(SupportWidget::memberFacts($member, $from)) > 0;
?>
<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container" style="max-width:46rem">
		<div class="jy-page-header"><div class="jy-page-header-bar"><h1>Ask about Joinery</h1></div></div>
		<p>Ask how to install Joinery, what it does, or how something works. An assistant answers from the documentation and names the page it used.
			<?php if ($knows_member): ?>It knows which sites you have with us and what state each is in, but it cannot look inside them.<?php
			else: ?>It cannot see any site.<?php endif; ?> Your questions are kept so the documentation can improve; do not include passwords or anything private.</p>
		<p class="jy-support-contact">
			<?php if ($member > 0): ?>Want a person? Ask your question here, then press <strong>Talk to a person</strong>; the reply comes back here and by email.<?php
			else: ?>Want a person? <a href="/login?return=<?php echo urlencode('/support_desk/ask'); ?>">Sign in</a> to hand a question to one<?php endif; ?>
			<?php if ($contact !== ''): ?> Or write to <a href="mailto:<?php echo htmlspecialchars($contact); ?>"><?php echo htmlspecialchars($contact); ?></a>.<?php endif; ?>
		</p>
		<?php if (count($mine) > 0): ?>
		<details class="jy-support-mine"<?php echo $open_thread === null ? '' : ' open'; ?>>
			<summary>Your conversations</summary>
			<ul>
			<?php foreach ($mine as $t): ?>
				<li><a href="/support_desk/ask?thread=<?php echo (int)$t->key; ?>"><?php echo htmlspecialchars((string)$t->get('sth_title')); ?></a>
					<span class="muted text-sm"><?php echo htmlspecialchars(LibraryFunctions::convert_time((string)$t->get('sth_last_activity_time'), 'UTC', $session->get_timezone(), 'M j'));
					echo (string)$t->get('sth_state') === SupportThread::STATE_WAITING ? ' · waiting for a person' : '';
					echo $t->get('sth_unread_reply') ? ' · new reply' : ''; ?></span></li>
			<?php endforeach; ?>
			</ul>
		</details>
		<?php endif; ?>
		<div class="joai-chat-transcript jy-support-transcript" id="sdk-widget-transcript" aria-live="polite"
			data-thread-id="<?php echo $open_thread ? (int)$open_thread->key : 0; ?>" data-route="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>"><?php
			echo $open_thread ? SupportWidget::transcriptHtml($open_thread, $session->get_timezone()) : ''; ?></div>
		<div class="jy-support-composer" id="sdk-widget-composer">
			<textarea class="jy-support-input" id="sdk-widget-input" rows="3" maxlength="8000" placeholder="Ask a question…"></textarea>
			<div id="sdk-widget-captcha"><?php $fw->captcha_hidden_input(); ?></div>
			<div class="jy-support-notice is-warn" id="sdk-widget-error" hidden></div>
			<button type="button" class="jy-support-btn is-primary" id="sdk-widget-send">Ask</button>
			<?php if ($member > 0): ?>
			<?php $waiting = $open_thread && (string)$open_thread->get('sth_state') === SupportThread::STATE_WAITING; ?>
			<button type="button" class="jy-support-btn" id="sdk-widget-person"<?php echo ($open_thread && !$waiting) ? '' : ' hidden'; ?>>Talk to a person</button>
			<?php endif; ?>
			<div class="jy-support-notice" id="sdk-widget-handed"<?php echo !empty($waiting) ? '' : ' hidden'; ?>>A person here has it. They reply in this conversation, and we email you when they do.</div>
		</div>
	</div>
</section>
</div>
<?php echo ChatTranscript::assets(); ?>
<link rel="stylesheet" href="/assets/css/support_panel.css?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/css/support_panel.css')) ?: '1'; ?>">
<script src="/plugins/support_desk/assets/js/support_widget.js?v=<?php echo @filemtime(PathHelper::getIncludePath('plugins/support_desk/assets/js/support_widget.js')) ?: '1'; ?>"></script>
<?php
$page->public_footer(array('track' => TRUE));
