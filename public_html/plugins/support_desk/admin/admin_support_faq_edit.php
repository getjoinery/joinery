<?php
/** @joinery-admin class=content */
/**
 * Admin: one FAQ entry of this support desk — new, from a thread (Answer
 * once), or an edit (specs/support_chatbot.md, FAQs).
 *
 * Answer once opens empty fields beside the thread it came from: the operator
 * writes the general question and its answer, not the conversation. Saving
 * puts the entry in the index (the model reads it in every thread from then
 * on) and sends the answer to that thread as a reply from a person. An edit
 * re-indexes; Delete takes it out of the index.
 *
 * @version 0.1.0
 */
$session = SessionControl::get_instance();

$entry_id = (int)($_GET['sfq_faq_entry_id'] ?? $_POST['sfq_faq_entry_id'] ?? 0);
$thread_id = (int)($_GET['sth_support_thread_id'] ?? $_POST['sth_support_thread_id'] ?? 0);
$entry = null;
if ($entry_id > 0) {
	$entry = new SupportFaqEntry($entry_id, TRUE);
	if (!$entry->key || $entry->get('sfq_delete_time')) {
		header('Location: /plugins/support_desk/admin/admin_support_threads?view=faq');
		exit;
	}
	$thread_id = (int)$entry->get('sfq_sth_support_thread_id');
}
$thread = null;
if ($thread_id > 0) {
	$thread = new SupportThread($thread_id, TRUE);
	if (!$thread->key) {
		$thread = null;
	} else {
		// The page shows the thread's transcript, which is the AI conversation's data.
		Access::demand(AiConversationMessage::access_level('read'), $session);
	}
}
$list = '/plugins/support_desk/admin/admin_support_threads?view=faq';
$self = '/plugins/support_desk/admin/admin_support_faq_edit';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	if (($_POST['action'] ?? '') === 'delete' && $entry) {
		$entry->soft_delete();
		header('Location: ' . $list);
		exit;
	}
	try {
		$saved = SupportAdmin::saveFaq($entry, $_POST, $entry ? null : $thread, (int)$session->get_user_id());
		$session->save_message(new DisplayMessage(
			($entry ? 'Saved; the index reads it as it is now.' : 'Saved and indexed.') . ((!$entry && $thread && $thread->get('sth_surface') === SupportThread::SURFACE_SITE) ? ' The answer went to thread #' . (int)$thread->key . ' as your reply.' : ''),
			'FAQ entry', NULL, DisplayMessage::MESSAGE_ANNOUNCEMENT));
		header('Location: ' . $list);
		exit;
	} catch (SupportFaqEntryException $e) {
		$error = $e->getMessage();
	}
}

$page = new AdminPage();
$title = $entry ? 'FAQ entry' : ($thread ? 'Answer once' : 'New FAQ entry');
$page->admin_header(array(
	'menu-id'        => 'support-desk',
	'page_title'     => $title,
	'readable_title' => $title,
	'breadcrumbs'    => array('Support Desk' => '/plugins/support_desk/admin/admin_support_threads', 'FAQ entries' => $list, $title => ''),
	'session'        => $session,
));
if ($error !== '') {
	echo '<div class="alert alert-warning">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
}

echo '<div style="display:flex;gap:2rem;flex-wrap:wrap;align-items:flex-start">';
echo '<div style="flex:1 1 28rem;min-width:20rem">';
echo '<p>Write the question the way the next person will ask it, and the answer the way you would want the model to give it. '
	. 'The model reads this entry, never the thread it came from.'
	. ($thread && !$entry && $thread->get('sth_surface') === SupportThread::SURFACE_SITE ? ' Saving also sends the answer to thread #' . (int)$thread->key . ' as your reply.' : '') . '</p>';
$fw = $page->getFormWriter('support_faq', array('action' => $self));
$fw->begin_form();
if ($entry) {
	$fw->hiddeninput('sfq_faq_entry_id', '', array('value' => (int)$entry->key));
}
if ($thread && !$entry) {
	$fw->hiddeninput('sth_support_thread_id', '', array('value' => (int)$thread->key));
}
$fw->textinput('sfq_question', 'Question', array('required' => true, 'maxlength' => SupportFaqEntry::QUESTION_MAX,
	'value' => $entry ? (string)$entry->get('sfq_question') : (string)($_POST['sfq_question'] ?? '')));
$fw->textarea('sfq_answer', 'Answer (Markdown)', array('rows' => 12, 'required' => true,
	'value' => $entry ? (string)$entry->get('sfq_answer') : (string)($_POST['sfq_answer'] ?? '')));
$fw->textinput('sfq_topic', 'Topic', array('helptext' => 'Optional: install, upgrade, mail, backups, …',
	'value' => $entry ? (string)$entry->get('sfq_topic') : (string)($_POST['sfq_topic'] ?? '')));
$fw->submitbutton('btn_save', ($thread && !$entry && $thread->get('sth_surface') === SupportThread::SURFACE_SITE) ? 'Save and answer the thread' : 'Save');
$fw->end_form();
if ($entry) {
	echo '<p style="margin-top:1rem">' . AdminPage::action_button('Delete', $self, array(
		'hidden' => array('action' => 'delete', 'sfq_faq_entry_id' => (int)$entry->key),
		'confirm' => 'Delete this entry? The model stops reading it.')) . '</p>';
}
echo '</div>';

if ($thread) {
	echo '<div style="flex:1 1 28rem;min-width:20rem"><h4>Thread #' . (int)$thread->key . '</h4>'
		. SupportAdmin::transcriptHtml($thread, $session->get_timezone()) . '</div>';
}
echo '</div>';

$page->admin_footer();
