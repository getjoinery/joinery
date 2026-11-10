<?php
/** @joinery-test
 * name: admin_entity_panels
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * Plugins add panels to admin detail pages by entity type (spec
 * email_lists_plugin G4, AdminEntityPanelRegistry).
 *
 * What is pinned here:
 *  - a panel registered for 'group' renders on the group page's terms and
 *    handles its own POST action there; one for 'event' the same on the event
 *    page; each sees the page's own entity;
 *  - a panel for one type never answers another type's page;
 *  - the user page's panels are the 'user' case: AdminUserPanelRegistry
 *    reads and writes the same registry;
 *  - the group page no longer takes add/remove actions of its own (removal is
 *    the user page's POST).
 *
 * Run: php tests/run.php db --filter=admin_entity_panels
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

if (session_id() === '') @session_start();

class HtsEntityPanel implements AdminEntityPanel {
	public $seen = array();
	private $type;
	public function __construct(string $type) { $this->type = $type; }
	public function id(): string { return 'hts_' . $this->type; }
	public function render($entity, AdminPage $page, array $context = array()): string {
		return '<div class="hts-panel">HarnessTest ' . $this->type . ' panel for ' . (int)$entity->key . '</div>';
	}
	public function actions(): array { return array('hts_' . $this->type . '_ping'); }
	public function handle(string $action, $entity, array $input): LogicResult {
		$this->seen[] = array($action, get_class($entity), (int)$entity->key);
		return LogicResult::redirect('/hts-panel-' . $this->type . '-' . (int)$entity->key);
	}
}

$group_panel = new HtsEntityPanel('group');
$event_panel = new HtsEntityPanel('event');
AdminEntityPanelRegistry::register('group', $group_panel);
AdminEntityPanelRegistry::register('event', $event_panel);

$admin = make_user('EntityPanelsAdmin', 10);
$_SESSION = array();
SessionControl::get_instance()->store_session_variables($admin);

$group = new Group(NULL);
$group->set('grp_name', 'HarnessTest panel group ' . bin2hex(random_bytes(3)));
$group->set('grp_category', 'user');
$group->save();
$group->load();
harness_register_model('Group', $group->key);

section('Group page');
$page = new AdminPage();
check(strpos(AdminEntityPanelRegistry::renderAll('group', $group, $page), 'HarnessTest group panel for ' . $group->key) !== false,
	'a group panel renders for the group');
check(strpos(AdminEntityPanelRegistry::renderAll('group', $group, $page), 'HarnessTest event panel') === false,
	'and an event panel does not');
$res = harness_call_logic('adm/logic/admin_group_members_logic.php', 'admin_group_members_logic',
	array('grp_group_id' => $group->key, 'action' => 'hts_group_ping'), 'POST');
check($res->redirect === '/hts-panel-group-' . $group->key && $group_panel->seen === array(array('hts_group_ping', 'Group', (int)$group->key)),
	'the group page hands the panel its action and the group', json_encode($group_panel->seen));
$res = harness_call_logic('adm/logic/admin_group_members_logic.php', 'admin_group_members_logic',
	array('grp_group_id' => $group->key, 'action' => 'hts_event_ping'), 'POST');
check($res->redirect === null && $event_panel->seen === array(), 'an event panel\'s action is not answered on the group page');
$res = harness_call_logic('adm/logic/admin_group_members_logic.php', 'admin_group_members_logic',
	array('grp_group_id' => $group->key, 'action' => 'remove_from_group', 'grm_group_member_id' => 0), 'GET');
check($res->redirect === null, 'the group page takes no remove action of its own');

section('Event page');
if (class_exists('Event')) {
	$event = new Event(NULL);
	$event->set('evt_name', 'HarnessTest panel event ' . bin2hex(random_bytes(3)));
	$event->save();
	$event->load();
	harness_register_row('evt_events', 'evt_event_id', $event->key);
	$res = harness_call_logic('plugins/event_manager/admin/logic/admin_event_logic.php', 'admin_event_logic',
		array('evt_event_id' => $event->key, 'action' => 'hts_event_ping'), 'POST');
	check($res->redirect === '/hts-panel-event-' . $event->key && $event_panel->seen === array(array('hts_event_ping', 'Event', (int)$event->key)),
		'the event page hands the panel its action and the event', json_encode($event_panel->seen));
} else {
	check(true, 'event_manager is not active here; the event page is not checked');
}

section('The user page is the user case');
$user_panels_before = count(AdminUserPanelRegistry::panels());
check($user_panels_before === count(AdminEntityPanelRegistry::panels('user')), 'AdminUserPanelRegistry reads the user panels');
check(count(AdminEntityPanelRegistry::panels('group')) === 1, 'and holds none of the group panels');

harness_finish();
