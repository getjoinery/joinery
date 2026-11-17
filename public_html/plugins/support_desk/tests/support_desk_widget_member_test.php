<?php
/** @joinery-test
 * name: support_desk_widget_member
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/support_desk/includes/SupportWidget.php, plugins/support_desk/includes/SupportIntake.php, plugins/support_desk/logic/widget_escalate_logic.php]
 */
/**
 * A signed-in member's widget conversation is theirs
 * (specs/hosting_after_purchase.md H1): it opens wherever they are signed in,
 * it is listed for them, and they can hand it to a person; another member
 * cannot open it, and a guest is told where to write instead.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('SupportWidget') || SupportDesk::systemUserId() <= 0) {
	section('Support desk widget for members');
	check(true, 'skipped: the support desk is not active or not set up on this site');
	harness_finish();
	return;
}

$member = make_user('WidgetMember');
$other = make_user('WidgetOther');
harness_set_setting_mem('support_desk_notify_email', '');
harness_set_setting_mem('support_desk_contact_email', 'help@example.com');

list($thread) = SupportIntake::createThread(SupportThread::SURFACE_WIDGET, array(
	'ip' => '198.51.100.9', 'title' => 'How do I add my domain?', 'visitor' => 'test', 'account_user_id' => (int)$member->key,
));
harness_defer(function () use ($thread) {
	$t = new SupportThread((int)$thread->key, TRUE);
	if ($t->key) { $t->permanent_delete(); }
});
$_SESSION[SupportWidget::SESSION_KEY] = array();

section('The member\'s thread is theirs');
check((int)$thread->get('sth_account_user_id') === (int)$member->key, 'the thread names the member who asked');
$_SESSION['usr_user_id'] = (int)$member->key;
$_SESSION['loggedin'] = true;
check(SupportWidget::member() === (int)$member->key, 'the signed-in member is read from the session');
check((int)SupportWidget::open((int)$thread->key)->key === (int)$thread->key, 'it opens with no token in this browser');
$listed = array_map(function ($t) { return (int)$t->key; }, SupportWidget::mine());
check(in_array((int)$thread->key, $listed, true), 'and is listed among their conversations');

section('They can hand it to a person');
$out = SupportWidget::escalate((int)$thread->key);
$fresh = new SupportThread((int)$thread->key, TRUE);
check($out['state'] === SupportThread::STATE_WAITING && (string)$fresh->get('sth_state') === SupportThread::STATE_WAITING,
	'the thread waits for a person');

section('Nobody else can');
$_SESSION['usr_user_id'] = (int)$other->key;
$refused = null;
try { SupportWidget::open((int)$thread->key); } catch (SupportRefusal $e) { $refused = $e; }
check($refused !== null, 'another member cannot open it');
unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);
$refused = null;
try { SupportWidget::escalate((int)$thread->key); } catch (SupportRefusal $e) { $refused = $e; }
check($refused !== null && strpos($refused->getMessage(), 'help@example.com') !== false,
	'a guest is told where to write instead', $refused ? $refused->getMessage() : '');

section('A question asked before signing in can be handed on once signed in (B36)');
list($guest_thread, $guest_token) = SupportIntake::createThread(SupportThread::SURFACE_WIDGET, array(
	'ip' => '198.51.100.9', 'title' => 'Asked as a guest', 'visitor' => 'test',
));
harness_defer(function () use ($guest_thread) {
	$t = new SupportThread((int)$guest_thread->key, TRUE);
	if ($t->key) { $t->permanent_delete(); }
});
$_SESSION[SupportWidget::SESSION_KEY] = array((int)$guest_thread->key => $guest_token);
$_SESSION['usr_user_id'] = (int)$member->key;
$_SESSION['loggedin'] = true;
SupportWidget::escalate((int)$guest_thread->key);
$g = new SupportThread((int)$guest_thread->key, TRUE);
check((int)$g->get('sth_account_user_id') === (int)$member->key && (string)$g->get('sth_state') === SupportThread::STATE_WAITING,
	'the browser\'s own conversation becomes the member\'s and waits for a person');

section('Opening a reply clears its mark (B35)');
SupportThread::updateColumns((int)$thread->key, array('sth_unread_reply' => true));
SupportWidget::mark_read(new SupportThread((int)$thread->key, TRUE));
check(!(new SupportThread((int)$thread->key, TRUE))->get('sth_unread_reply'), 'the member reading it clears new reply');
unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);

harness_finish();
