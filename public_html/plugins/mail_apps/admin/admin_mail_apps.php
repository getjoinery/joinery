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
 * @version 1.4.0 - a site in a container uses the same switch (a request to its Docker host) and shows the host's answer
 * @version 1.3.0 - the switch is MailAppGateway::allow()/disallow(), recording the route; a Switch offer when the rule
 *                picks another name or route, a warning while a relay hides an address programs still reach directly,
 *                and the notice of a change KeepMailAppsReachable made without the admin
 * @version 1.2.0 - through a relay: allow obtains the certificate through the relay and sends the relay this
 *                server's address (a fragment push); disallow takes the address back
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
	if ($action === 'allow' || $action === 'disallow' || $action === 'switch') {
		// Switch is Allow again: on by the route and name the rule picks now,
		// recorded over the old ones (the helper replaces the site's entry; in a
		// container, the request to the host does).
		$run = $action === 'disallow' ? MailAppGateway::disallow() : MailAppGateway::allow();
		$flash($run['message'], !$run['ok'] ? 'Nothing changed'
			: ($action === 'disallow' ? 'Mail apps off' : ($action === 'switch' ? 'Switched' : 'Mail apps on')));
		header('Location: ' . $self);
		exit;
	}
	if ($action === 'dismiss_route_notice') {
		MailAppGateway::dismissRouteNotice();
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
	$state['Where it runs'] = 'On this site\'s server (its Docker host), which applies a change within a minute';
	$answer = MailAppGateway::hostAnswer();
	$when = ($answer !== null && $answer['time'] !== null)
		? ' (' . $esc(LibraryFunctions::convert_time(gmdate('Y-m-d H:i:s', $answer['time']), 'UTC', $tz, 'M j, g:i a')) . ')' : '';
	$state['The server\'s answer'] = $answer === null ? ($enabled ? 'None yet' : 'None')
		: ($answer['state'] === 'on' ? 'Serving <code>' . $esc((string)$answer['name']) . '</code>' . $when
			: ($answer['state'] === 'off' ? 'Not serving this site' . $when
				: '<strong>Could not serve it:</strong> ' . $esc((string)$answer['error']) . $when));
} elseif (!MailAppGateway::helperInstalled()) {
	$state['Gateway installed'] = 'Not yet — the host installer puts it in place within minutes of the plugin being switched on';
}

$page->tableheader(array('', ''), array('title' => 'Status'));
foreach ($state as $label => $value) {
	$page->disprow(array($esc($label), $value));
}
$page->endtable();

$notice = MailAppGateway::routeNotice();
if ($notice !== null) {
	echo '<p><strong>Changed without you on '
		. $esc(LibraryFunctions::convert_time($notice['time'], 'UTC', $tz, 'M j, Y g:i a')) . ':</strong> '
		. $esc($notice['text']) . ' '
		. AdminPage::action_button('Got it', $self, array('hidden' => array('action' => 'dismiss_route_notice'))) . '</p>';
}
$route_state = MailAppGateway::routeState();
if ($route_state !== null && $route_state['state'] === 'offer') {
	$to = $route_state['name'];
	$why = $route_state['preferred'] !== $route_state['route']
		? ($route_state['preferred'] === MailAppGateway::ROUTE_RELAY
			? 'Mail now arrives through a relay of this site\'s own, which can pass mail programs through and keep this '
				. 'server\'s address hidden. Until you switch, <code>' . $esc($route_state['served']) . '</code> keeps the '
				. 'address public (the Mailbox setup page\'s hidden-address check fails on it too).'
			: 'Mail no longer arrives through the relay programs connect through.')
		: 'The mail host name has changed.';
	echo '<p><strong>Mail programs could connect a better way.</strong> ' . $why . ' Programs connect to <code>'
		. $esc($route_state['served']) . '</code> now; after a switch they connect to <code>' . $esc((string)$to)
		. '</code>. Each member must then change the server name in their mail program. Nothing changes until you switch. '
		. AdminPage::action_button('Switch to ' . $to, $self, array('hidden' => array('action' => 'switch'),
			'confirm' => 'Switch mail programs to ' . $to . '? Every member\'s mail program stops connecting until they '
				. 'change its server name to ' . $to . '.')) . '</p>';
} elseif ($route_state !== null && $route_state['state'] === 'exposed') {
	echo '<p><strong>This server\'s address is public.</strong> Mail now arrives through a relay, but programs still '
		. 'connect straight to <code>' . $esc($route_state['served']) . '</code>, which points at this server. The relay '
		. 'cannot pass them through: ' . $esc((string)$route_state['reason'])
		. ' Stop allowing mail apps to keep the address hidden.</p>';
} elseif ($route_state !== null && $route_state['state'] === 'gone') {
	echo '<p><strong>The relay programs connect through is gone.</strong> Within the hour mail apps are turned on by '
		. 'the way that works now, or off when nothing can carry them, and this page will say which.</p>';
}
if ($why_not !== null && !$enabled) {
	echo '<p><strong>Not available here yet:</strong> ' . $esc($why_not) . '</p>';
}
if ($enabled || $why_not === null) {
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
