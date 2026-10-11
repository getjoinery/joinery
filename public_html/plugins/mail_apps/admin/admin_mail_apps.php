<?php
/**
 * Admin: Mail Apps — let members use Thunderbird and other mail programs.
 *
 * The Allow mail apps switch turns the mail gateway on for this site (ports
 * 993 and 465, TLS only) through the narrow root helper, and records the
 * setting members' Mail apps page reads. Health: whether the gateway answers
 * on both ports, whether its certificate covers this site's name, and — on
 * request — a round trip with a throwaway app password (sign in, open INBOX,
 * sign out, revoke).
 *
 * @version 1.1.0 - names the mail host name programs connect to; says why mail apps cannot be turned on
 * @version 1.0.0
 */
$session = SessionControl::get_instance();
$session->check_permission(9);
$session->set_return();
$self = '/plugins/mail_apps/admin/admin_mail_apps';

$flash = function (string $text, string $title) use ($session) {
	$session->save_message(new DisplayMessage($text, $title, '/.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'mailappsbox', TRUE));
};

$round_trip = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	$action = $_POST['action'] ?? '';
	if ($action === 'allow' || $action === 'disallow') {
		$why_not = MailAppGateway::cannotTurnOn();
		if ($action === 'allow' && $why_not !== null) {
			$flash($why_not, 'Nothing changed');
		} elseif (MailAppGateway::inContainer()) {
			$flash($why_not, 'Nothing changed');
		} else {
			$run = MailAppGateway::run($action === 'allow' ? 'on' : 'off');
			if ($run['ok']) {
				Setting::put('mail_apps_enabled', $action === 'allow');
				Setting::put('mail_apps_served_name', $action === 'allow' ? (string)MailAppGateway::mailHostname() : '');
				if ($action === 'disallow') {
					// Credentials of a switched-off purpose are refused from now
					// on (AppAccess::isAvailable()); their borrowed windows end now.
					ApiKey::LockBorrowedWindowsForPurpose('mail');
				}
				$flash($action === 'allow'
					? 'Mail apps are on: the mail gateway answers on ports 993 and 465 for ' . MailAppGateway::servedName() . '.'
					: 'Mail apps are off: the gateway no longer answers for this site, and members\' app passwords stop working at once.',
					$action === 'allow' ? 'Mail apps on' : 'Mail apps off');
			} else {
				$flash($run['message'], 'Nothing changed');
			}
		}
		header('Location: ' . $self);
		exit;
	}
	if ($action === 'round_trip') {
		$round_trip = MailAppGateway::roundTrip((int)$session->get_user_id());
	}
}

$enabled = (bool)Globalvars::get_instance()->get_setting('mail_apps_enabled');
$imap = MailAppGateway::listening(MailAppGateway::IMAP_PORT);
$smtp = MailAppGateway::listening(MailAppGateway::SUBMISSION_PORT);
$cert = $imap ? MailAppGateway::certificate(MailAppGateway::IMAP_PORT) : null;
$tz = $session->get_timezone();
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5); };

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'mail-apps',
	'page_title'     => 'Mail Apps',
	'readable_title' => 'Mail Apps',
	'breadcrumbs'    => array('Mail Apps' => ''),
	'session'        => $session,
));

echo '<p>Members can read and send their mail from Thunderbird, Apple Mail or any IMAP and SMTP program, signing in '
	. 'with an app password they make on their <a href="/profile/mail_apps">Mail apps</a> page. A small program on this '
	. 'server, the mail gateway, holds those programs\' connections on ports 993 and 465 (TLS only) and reaches mail only '
	. 'through this site, as the member. Private mailboxes work while the member\'s vault is unlocked on the website; '
	. 'Fortress mailboxes are not offered.</p>';

$why_not = MailAppGateway::cannotTurnOn();
$state = array(
	'Allowed'          => $enabled ? 'Yes' : 'No',
	'Programs connect to' => ($enabled || MailAppGateway::mailHostname() !== null)
		? '<code>' . $esc(MailAppGateway::servedName()) . '</code>'
		: 'No mail host name is set',
	'IMAP (993)'       => $imap ? 'Answering' : 'Not answering',
	'Submission (465)' => $smtp ? 'Answering' : 'Not answering',
);
if ($cert !== null) {
	$state['Certificate'] = ($cert['error'] !== null && !$cert['covers']) ? $esc($cert['error'])
		: (($cert['covers'] ? 'Covers this name' : '<strong>Does not cover this name</strong>')
			. ($cert['error'] !== null ? ' — <strong>' . $esc($cert['error']) . '</strong>' : '')
			. ($cert['expires'] ? ', valid until ' . $esc(LibraryFunctions::convert_time(gmdate('Y-m-d H:i:s', $cert['expires']), 'UTC', $tz, 'M j, Y')) : ''));
}
if (MailAppGateway::inContainer()) {
	$state['Where it runs'] = 'On this site\'s host, run by the host\'s management node';
} elseif (!MailAppGateway::helperInstalled()) {
	$state['Gateway installed'] = 'Not yet — the host installer puts it in place within minutes of the plugin being switched on';
}

$page->tableheader(array('', ''), array('title' => 'Status'));
foreach ($state as $label => $value) {
	$page->disprow(array($esc($label), $value));
}
$page->endtable();

$drift = MailAppGateway::nameDrift();
if ($drift !== null) {
	echo '<p><strong>The mail host name has changed.</strong> The gateway serves <code>' . $esc($drift['served'])
		. '</code>, and programs are still told to connect there; the setting now says '
		. ($drift['setting'] !== null ? '<code>' . $esc($drift['setting']) . '</code>' : 'nothing')
		. '. Stop allowing mail apps and allow them again to move to the new name.</p>';
}
if ($why_not !== null && !$enabled) {
	echo '<p><strong>Not available here yet:</strong> ' . $esc($why_not) . '</p>';
}
if (!MailAppGateway::inContainer() && ($enabled || $why_not === null)) {
	echo '<p>';
	echo $enabled
		? AdminPage::action_button('Stop allowing mail apps', $self, array('hidden' => array('action' => 'disallow'),
			'confirm' => 'Stop allowing mail apps? Every member\'s mail program stops getting mail at once.'))
		: AdminPage::action_button('Allow mail apps', $self, array('hidden' => array('action' => 'allow')));
	if ($enabled && $imap) {
		echo ' ' . AdminPage::action_button('Run a round trip', $self, array('hidden' => array('action' => 'round_trip')));
	}
	echo '</p>';
}

if ($round_trip !== null) {
	echo '<h3>Round trip</h3><ul>';
	foreach ($round_trip['steps'] as $step) {
		echo '<li>' . $esc($step) . '</li>';
	}
	echo '</ul>';
	echo $round_trip['ok'] ? '<p><strong>Passed.</strong></p>' : '<p><strong>Failed:</strong> ' . $esc($round_trip['error']) . '</p>';
}

$page->admin_footer();
?>
