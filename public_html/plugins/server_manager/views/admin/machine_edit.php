<?php
/**
 * Server Manager - a machine written down by hand
 * URL: /admin/server_manager/machine_edit[?fhm_fleet_hand_machine_id=N][?ref=oca:3:12345&name=label][?ref=node:15&name=label]
 *
 * A machine we pay for that this management node cannot read for itself (a
 * cloud account it holds no key for), or a note on a listed server no node
 * manages, or on a node with no agent (whose own report can never say what it
 * runs): what it is, who owns it, what it serves, when to retire it
 * (specs/fleet_security_patching.md WP8, FleetHandMachine). Saving it confirms
 * it as true now; Confirm does that alone.
 *
 * @version 1.1 - a note on a node with no agent (ref node:N)
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

$id = (int)($_GET['fhm_fleet_hand_machine_id'] ?? 0);
$machine = $id > 0 ? new FleetHandMachine($id, TRUE) : new FleetHandMachine(NULL);
$is_edit = $id > 0 && $machine->key && !$machine->get('fhm_delete_time');
$self_url = '/admin/server_manager/machine_edit' . ($is_edit ? '?fhm_fleet_hand_machine_id=' . (int)$machine->key : '');
$list_url = '/admin/server_manager/machines';
$page_regex = '/\/admin\/server_manager/';
$error = null;

$done = function ($text) use ($session, $page_regex, $list_url) {
	$session->save_message(new DisplayMessage($text, 'Machines', $page_regex,
		DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
	header('Location: ' . $list_url);
	exit;
};

// The two actions, before the save path, each returning from it.
if ($_POST && ($_POST['action'] ?? '') === 'remove_machine' && $is_edit) {
	$machine->soft_delete();
	$done('Removed ' . $machine->get('fhm_name') . ' from the list.');
} elseif ($_POST && ($_POST['action'] ?? '') === 'confirm_machine' && $is_edit) {
	$machine->confirm($session->get_user_id());
	$machine->save();
	$done('Confirmed ' . $machine->get('fhm_name') . ' as written.');
} elseif ($_POST && isset($_POST['fhm_name'])) {
	foreach (array('fhm_name', 'fhm_address', 'fhm_where', 'fhm_instance_ref', 'fhm_purpose', 'fhm_owner', 'fhm_os',
			'fhm_serves', 'fhm_retire_by', 'fhm_notes') as $field) {
		if (!isset($_POST[$field])) { continue; }
		$value = trim((string)$_POST[$field]);
		$machine->set($field, $value === '' ? null : $value);
	}
	try {
		if (trim((string)$machine->get('fhm_name')) === '') {
			throw new FleetHandMachineException('Give it a name.');
		}
		$retire = (string)$machine->get('fhm_retire_by');
		if ($retire !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $retire)) {
			throw new FleetHandMachineException('Write the retire-by date as YYYY-MM-DD.');
		}
		// Written now, so true now.
		$machine->confirm($session->get_user_id());
		$machine->prepare();
		$machine->save();
		$done(($is_edit ? 'Saved ' : 'Added ') . $machine->get('fhm_name') . '.');
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
} elseif (!$is_edit) {
	// A note on a listed server or a node, from its row: the machine named already.
	$ref = (string)($_GET['ref'] ?? '');
	if (preg_match('/^([a-z]+:[0-9]+:[A-Za-z0-9-]{1,64}|node:[0-9]+)$/', $ref)) {
		$machine->set('fhm_instance_ref', $ref);
		$machine->set('fhm_name', substr((string)($_GET['name'] ?? ''), 0, 120));
		$machine->set('fhm_address', substr((string)($_GET['address'] ?? ''), 0, 64));
	}
}

$title = $is_edit ? 'Edit a machine' : 'Write down a machine';
$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'server-manager',
	'page_title'     => $title,
	'readable_title' => $title,
	'breadcrumbs'    => array('Server Manager' => '/admin/server_manager', 'Machines' => $list_url, $title => ''),
	'session'        => $session,
));
if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}
$page->begin_box(array('title' => $title));
echo '<p class="text-muted">For a machine we pay for that no key here can read, or to say what a listed server no node manages is for. '
	. 'Nothing checks what is written here, so the list asks for it to be confirmed every ' . FleetHandMachine::CONFIRM_DAYS . ' days.</p>';

$formwriter = $page->getFormWriter('machine_form', array('model' => $machine, 'action' => $self_url));
echo $formwriter->begin_form();
$formwriter->textinput('fhm_name', 'Name', array('maxlength' => 120, 'required' => true));
$formwriter->textinput('fhm_address', 'Public address', array('maxlength' => 64, 'helptext' => 'Its IPv4 or IPv6 address.'));
$formwriter->textinput('fhm_where', 'Where it runs', array('maxlength' => 160, 'helptext' => 'The provider and account, for example: Linode, the owner\'s own account.'));
if ((string)$machine->get('fhm_instance_ref') !== '') {
	$formwriter->hiddeninput('fhm_instance_ref', '', array('value' => (string)$machine->get('fhm_instance_ref')));
	$on = (string)$machine->get('fhm_instance_ref');
	echo '<p class="small text-muted">' . (strpos($on, 'node:') === 0
		? 'A note on managed node ' . (int)substr($on, 5) . ', which has no agent to report what it runs.'
		: 'A note on listed server ' . htmlspecialchars($on) . '.') . '</p>';
}
$formwriter->textinput('fhm_purpose', 'What it is for', array('maxlength' => 255));
$formwriter->textinput('fhm_owner', 'Whose it is', array('maxlength' => 120));
$formwriter->textinput('fhm_os', 'Operating system', array('maxlength' => 64, 'helptext' => 'For example: Ubuntu 20.04. A release past standard support turns its row red.'));
$formwriter->textinput('fhm_serves', 'What it serves', array('maxlength' => 255, 'helptext' => 'The domains on it. None written, and no retire-by date, asks whether it should go.'));
$formwriter->dateinput('fhm_retire_by', 'Retire by', array('helptext' => 'Its row turns red on this day.'));
$formwriter->textbox('fhm_notes', 'Notes', array('rows' => 3));
$formwriter->submitbutton('btn_submit', $is_edit ? 'Save' : 'Add');
echo $formwriter->end_form();
$page->end_box();

if ($is_edit) {
	$page->begin_box(array('title' => 'Still right?'));
	$confirmed = (string)$machine->get('fhm_confirmed_time');
	echo '<p class="text-muted">' . ($confirmed !== '' ? 'Last confirmed ' . htmlspecialchars(LibraryFunctions::time_ago($confirmed, $session->get_timezone())) . '.' : 'Never confirmed.') . '</p>';
	echo AdminPage::action_button('Confirm it is as written', $self_url, array(
		'hidden' => array('action' => 'confirm_machine'),
		'class'  => 'btn btn-outline-secondary btn-sm',
	));
	echo ' ';
	echo AdminPage::action_button('Remove from the list', $self_url, array(
		'hidden'  => array('action' => 'remove_machine'),
		'confirm' => 'Remove this machine from the list? Do this once it is gone.',
		'class'   => 'btn btn-outline-danger btn-sm',
	));
	$page->end_box();
}
$page->admin_footer();
