<?php
/** @joinery-admin class=config */
/**
 * Admin: one support thread — what the site sent, every turn with its tool
 * trace and tokens, and the operator's reply (specs/support_chatbot.md, The
 * admin page).
 *
 * A reply is a message from a person: the site's panel shows it with a "from
 * a person" mark, the thread moves to answered by a person, and a connected
 * site's account gets one email saying there is a reply (never its words).
 * Take this thread keeps the model out of it until it is let go. Answer once
 * opens the FAQ form with this thread beside it.
 *
 * The site's facts and words are untrusted text, escaped here.
 *
 * @version 0.1.0
 */
$session = SessionControl::get_instance();

$id = (int)($_GET['sth_support_thread_id'] ?? $_POST['sth_support_thread_id'] ?? 0);
$thread = new SupportThread($id, TRUE);
if (!$thread->key || $thread->get('sth_delete_time')) {
	header('Location: /plugins/support_desk/admin/admin_support_threads');
	exit;
}
$self = '/plugins/support_desk/admin/admin_support_thread?sth_support_thread_id=' . (int)$thread->key;
$uid = (int)$session->get_user_id();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	try {
		if ($action === 'reply') {
			SupportAdmin::reply($thread, $uid, (string)($_POST['reply'] ?? ''));
			$session->save_message(new DisplayMessage('Your reply is in the thread.', 'Sent', NULL, DisplayMessage::MESSAGE_ANNOUNCEMENT));
		} elseif ($action === 'take') {
			SupportAdmin::pin($thread, $uid);
		} elseif ($action === 'let_go') {
			SupportAdmin::pin($thread, null);
		} elseif ($action === 'close') {
			SupportAdmin::close($thread);
		} elseif ($action === 'reopen') {
			SupportAdmin::reopen($thread);
		}
	} catch (SupportThreadException $e) {
		$session->save_message(new DisplayMessage($e->getMessage(), 'Not sent', NULL, DisplayMessage::MESSAGE_WARNING));
	}
	header('Location: ' . $self);
	exit;
}

$tz = $session->get_timezone();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'support-desk',
	'page_title'     => 'Support thread #' . (int)$thread->key,
	'readable_title' => 'Support thread #' . (int)$thread->key,
	'breadcrumbs'    => array('Support Desk' => '/plugins/support_desk/admin/admin_support_threads', 'Thread #' . (int)$thread->key => ''),
	'session'        => $session,
));

$facts = array();
$facts['State'] = $thread->stateLabel();
if ((int)$thread->get('sth_pinned_user_id')) {
	$p = new User((int)$thread->get('sth_pinned_user_id'), TRUE);
	$facts['Taken by'] = $p->key ? $p->display_name() : '—';
}
if ((string)$thread->get('sth_surface') === SupportThread::SURFACE_WIDGET) {
	$facts['From'] = 'A visitor on the public pages';
} else {
	$facts['Site'] = (string)$thread->get('sth_host');
	$facts['Release'] = (string)$thread->get('sth_version');
	$facts['Origin'] = (string)$thread->get('sth_verdict');
	$node = $thread->node();
	$facts['Joined here'] = $node ? $node->get('mgn_name') . ' (node #' . (int)$node->key . ')' : 'no';
	if ($thread->isConnected()) {
		$acct = new User((int)$thread->get('sth_account_user_id'), TRUE);
		$facts['Account'] = $acct->key ? $acct->display_name() . ' <' . $acct->login_label() . '>' : 'connected';
	} else {
		$facts['Account'] = 'not connected';
	}
	$facts['Site facts sent'] = (bool)$thread->get('sth_consent_bundle') ? 'yes' : 'no';
	$facts['Live reads allowed'] = (bool)$thread->get('sth_consent_live_reads') ? 'yes' : 'no';
	$facts['Reads today'] = (string)$thread->nodeReadsToday();
	$facts['Asked from'] = (string)$thread->get('sth_route');
}
$facts['Tokens'] = number_format((int)$thread->get('sth_input_tokens')) . ' in / ' . number_format((int)$thread->get('sth_output_tokens')) . ' out';
$facts['Opened'] = LibraryFunctions::convert_time($thread->get('sth_create_time'), 'UTC', $tz, 'M j, Y g:i A');

echo '<h3>' . $h($thread->get('sth_title')) . '</h3>';
echo '<table class="table table-sm" style="max-width:52rem">';
foreach ($facts as $k => $v) {
	echo '<tr><th style="width:12rem">' . $h($k) . '</th><td>' . $h($v) . '</td></tr>';
}
echo '</table>';

echo '<p>';
if ((int)$thread->get('sth_pinned_user_id')) {
	echo AdminPage::action_button('Let it go', $self, array('hidden' => array('action' => 'let_go'))) . ' ';
} else {
	echo AdminPage::action_button('Take this thread', $self, array('hidden' => array('action' => 'take'))) . ' ';
}
if ((string)$thread->get('sth_state') === SupportThread::STATE_CLOSED) {
	echo AdminPage::action_button('Reopen', $self, array('hidden' => array('action' => 'reopen'))) . ' ';
} else {
	echo AdminPage::action_button('Close', $self, array('hidden' => array('action' => 'close'))) . ' ';
}
echo '<a class="btn btn-sm btn-outline-secondary" href="/plugins/support_desk/admin/admin_support_faq_edit?sth_support_thread_id=' . (int)$thread->key . '">Answer once</a>';
echo '</p>';

$bundle = $thread->bundle();
if ($bundle) {
	echo '<details style="margin:0.5rem 0 1rem"><summary>What the site sent about itself (untrusted)</summary><pre style="white-space:pre-wrap;max-height:28rem;overflow:auto">'
		. $h(json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
}

echo ChatTranscript::assets(false);
echo '<div style="max-width:52rem">' . SupportAdmin::transcriptHtml($thread, $tz) . '</div>';

if ((string)$thread->get('sth_surface') === SupportThread::SURFACE_WIDGET) {
	echo '<h4 style="margin-top:1.5rem">Teach the desk</h4>';
	echo '<p>A visitor on the public pages leaves no address and the widget shows only answers to their own questions, so a reply would reach nobody. '
		. 'When the answer here was missing or wrong, <a href="/plugins/support_desk/admin/admin_support_faq_edit?sth_support_thread_id=' . (int)$thread->key
		. '">write it once as a FAQ entry</a>: the next person who asks gets it.</p>';
} else {
	echo '<h4 style="margin-top:1.5rem">Reply as a person</h4>';
	echo '<p>The site\'s Help panel shows your reply marked as from a person. '
		. ($thread->isConnected() ? 'The site\'s account gets one email saying there is a reply; the email carries none of its words.' : 'This site is not connected to an account, so it learns of the reply from the mark on its Help button.')
		. ' The next question from the site goes back to the model unless you take the thread.</p>';
	$fw = $page->getFormWriter('support_reply', array('action' => $self));
	$fw->begin_form();
	$fw->hiddeninput('action', '', array('value' => 'reply'));
	$fw->hiddeninput('sth_support_thread_id', '', array('value' => (int)$thread->key));
	$fw->textarea('reply', 'Reply', array('rows' => 6, 'required' => true));
	$fw->submitbutton('btn_reply', 'Send reply');
	$fw->end_form();
}

$page->admin_footer();
