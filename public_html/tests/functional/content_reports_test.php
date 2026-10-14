<?php
/** @joinery-test
 * name: content_reports
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Members report other people's content; admins settle the reports in one
 * queue. What is pinned here, against a test-only handler:
 *
 *  - a handler registers under its type, and an unknown type has none;
 *  - a report makes one row; the same person reporting again makes no second
 *    row and no second alert;
 *  - content the handler's mayReport() turns down, an unknown type and an
 *    unknown reason are refused with a message;
 *  - acting on a report runs the handler's action and settles every open
 *    report on that content; a settled report cannot be acted on again;
 *  - dismissing settles them without touching the content;
 *  - the queue page's POST settles a report and redirects;
 *  - the per-member rate limit refuses the report past the limit;
 *  - content.reported reaches every admin through the `admins` audience and
 *    nobody else.
 *
 * Run: php tests/run.php test-db --filter=content_reports
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();
harness_test_mode();

harness_set_setting_mem('defaultemail', 'content-reports-test@dev.getjoinery.com');
harness_set_setting_mem('defaultemailname', 'Content reports test');

const CR_LOGIC = 'logic/content_report_logic.php';
const CR_ADMIN_LOGIC = 'adm/logic/admin_content_reports_logic.php';

/** A handler over an in-memory set of things, so nothing outside the table is touched. */
class HarnessContentReportHandler implements ContentReportHandler {
	/** @var array<int, array{title:string, hidden:bool, author:int}> */
	public $items = array();
	/** @var int[] ids no one may report */
	public $unreportable = array();
	public $acted = array();

	public function entityType(): string { return 'harness_thing'; }
	public function label(): string { return 'Harness thing'; }
	public function mayReport(int $entity_id, int $user_id): bool {
		return isset($this->items[$entity_id]) && !$this->items[$entity_id]['hidden']
			&& !in_array($entity_id, $this->unreportable, true);
	}
	public function preview(int $entity_id): ?array {
		if (!isset($this->items[$entity_id])) return null;
		return array('title' => $this->items[$entity_id]['title'], 'excerpt' => 'Some text',
			'author_user_id' => $this->items[$entity_id]['author'], 'url' => '/harness/thing/' . $entity_id);
	}
	public function actions(int $entity_id): array {
		return array('hide' => 'Hide thing', 'explode' => 'Refuse');
	}
	public function act(int $entity_id, string $action, int $admin_user_id): string {
		if ($action === 'explode') {
			throw new RuntimeException('The feature refused: not today.');
		}
		$this->items[$entity_id]['hidden'] = true;
		$this->acted[] = array($entity_id, $action, $admin_user_id);
		return 'Hid the thing';
	}
}

function cr_act_as($user) {
	$_SESSION['loggedin']    = 1;
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['permission']  = (int)$user->get('usr_permission');
}

function cr_report(array $data) {
	return harness_call_logic(CR_LOGIC, 'content_report_logic', $data);
}

$db = DbConnector::get_instance()->get_db_link();

$make_user = function ($label, $permission) {
	$u = new User(NULL);
	$u->set('usr_first_name', 'HarnessTest');
	$u->set('usr_last_name', 'Reports' . ucfirst($label));
	$u->set('usr_email', harness_fixture_email('cr_' . $label . '_' . LibraryFunctions::random_string(4)));
	$u->set('usr_password', User::GeneratePassword('TestPassword_reports'));
	$u->set('usr_permission', $permission);
	$u->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
	$u->save();
	$u->load();
	harness_register_user($u);
	return $u;
};

$made = array();
harness_defer(function () use (&$made, $db) {
	foreach ($made as $uid) {
		$db->prepare('DELETE FROM rpt_content_reports WHERE rpt_usr_user_id = ?')->execute(array((int)$uid));
		$db->prepare("DELETE FROM rql_request_logs WHERE rql_usr_user_id = ? AND rql_feature = 'content_report'")->execute(array((int)$uid));
		$db->prepare('DELETE FROM ntf_notifications WHERE ntf_usr_user_id = ?')->execute(array((int)$uid));
	}
	$db->prepare("DELETE FROM rpt_content_reports WHERE rpt_entity_type = 'harness_thing'")->execute();
});

try {
	$member  = $make_user('member', 0);
	$member2 = $make_user('member2', 0);
	$admin   = $make_user('admin', 5);
	$admin2  = $make_user('admin2', 5);
	foreach (array($member, $member2, $admin, $admin2) as $u) { $made[] = (int)$u->key; }

	$marker = 'HarnessTest report ' . LibraryFunctions::random_string(6);
	$handler = new HarnessContentReportHandler();
	$handler->items = array(
		101 => array('title' => $marker . ' one', 'hidden' => false, 'author' => (int)$member2->key),
		102 => array('title' => $marker . ' two', 'hidden' => false, 'author' => (int)$member2->key),
		103 => array('title' => $marker . ' three', 'hidden' => false, 'author' => (int)$member2->key),
	);
	$handler->unreportable = array(103);

	// ------------------------------------------------------------------
	section('Registry');

	ContentReportRegistry::register($handler);
	check(ContentReportRegistry::get('harness_thing') === $handler, 'a handler is found under its type');
	check(ContentReportRegistry::get('harness_nothing') === null, 'an unknown type has no handler');
	check(isset(ContentReportRegistry::all()['harness_thing']), 'all() lists it');

	$count_rows = function ($entity_id) use ($db) {
		$q = $db->prepare("SELECT COUNT(*) FROM rpt_content_reports WHERE rpt_entity_type = 'harness_thing' AND rpt_entity_id = ?");
		$q->execute(array((int)$entity_id));
		return (int)$q->fetchColumn();
	};
	$alerts_for = function (User $u, $like) use ($db) {
		$q = $db->prepare('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_usr_user_id = ? AND ntf_body LIKE ?');
		$q->execute(array((int)$u->key, '%' . $like . '%'));
		return (int)$q->fetchColumn();
	};

	// ------------------------------------------------------------------
	section('Filing');

	cr_act_as($member);
	$r = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 101, 'reason' => 'spam', 'note' => 'Same link everywhere'));
	check($r->error === null && !empty($r->data['report_id']), 'a member reports a thing', (string)$r->error);
	check($count_rows(101) === 1, 'one row');
	$first_id = (int)($r->data['report_id'] ?? 0);

	$r = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 101, 'reason' => 'abuse'));
	check($r->error === null && (int)($r->data['report_id'] ?? 0) === $first_id,
		'reporting it again answers with the same report', (string)$r->error);
	check($count_rows(101) === 1, 'and makes no second row', $count_rows(101) . ' rows');

	$r = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 103, 'reason' => 'spam'));
	check($r->error !== null && $count_rows(103) === 0, 'content the handler turns down is refused', (string)$r->error);
	$r = cr_report(array('entity_type' => 'harness_nothing', 'entity_id' => 1, 'reason' => 'spam'));
	check($r->error !== null, 'an unknown type is refused', (string)$r->error);
	$r = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 102, 'reason' => 'boring'));
	check($r->error !== null && $count_rows(102) === 0, 'an unknown reason is refused', (string)$r->error);

	// ------------------------------------------------------------------
	section('Admins are told, once');

	$admin_ids = Notify::audience_ids('admins');
	check(in_array((int)$admin->key, $admin_ids, true) && in_array((int)$admin2->key, $admin_ids, true)
		&& !in_array((int)$member->key, $admin_ids, true), 'the admins audience is every admin and no member');
	check($alerts_for($admin, $marker . ' one') === 1 && $alerts_for($admin2, $marker . ' one') === 1,
		'each admin got one alert for the report (the repeat sent none)',
		$alerts_for($admin, $marker . ' one') . ' / ' . $alerts_for($admin2, $marker . ' one'));
	check($alerts_for($member, $marker . ' one') === 0 && $alerts_for($member2, $marker . ' one') === 0,
		'members got none');

	// ------------------------------------------------------------------
	section('Acting settles every open report on the content');

	cr_act_as($member2);
	$r = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 101, 'reason' => 'off_topic'));
	check($r->error === null && $count_rows(101) === 2, 'a second member reports the same thing: a second row', (string)$r->error);

	$report = new ContentReport($first_id, TRUE);
	$message = $report->act('hide', (int)$admin->key);
	check($message === 'Hid the thing' && $handler->items[101]['hidden'] === true
		&& $handler->acted === array(array(101, 'hide', (int)$admin->key)), 'the handler ran its action', $message);
	$q = $db->prepare("SELECT rpt_status, rpt_usr_user_id_handled_by, rpt_outcome FROM rpt_content_reports
		WHERE rpt_entity_type = 'harness_thing' AND rpt_entity_id = 101");
	$q->execute();
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	$all_actioned = count($rows) === 2;
	foreach ($rows as $row) {
		$all_actioned = $all_actioned && $row['rpt_status'] === 'actioned'
			&& (int)$row['rpt_usr_user_id_handled_by'] === (int)$admin->key && $row['rpt_outcome'] === 'Hid the thing';
	}
	check($all_actioned, 'both reports are actioned, by that admin, with the outcome', json_encode($rows));

	$refused = false;
	try { $report->act('hide', (int)$admin->key); } catch (ContentReportException $e) { $refused = true; }
	check($refused && count($handler->acted) === 1, 'a settled report cannot be acted on again');

	$refused = false;
	cr_act_as($member);
	$r = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 102, 'reason' => 'spam'));
	$open = new ContentReport((int)$r->data['report_id'], TRUE);
	try { $open->act('delete_everything', (int)$admin->key); } catch (ContentReportException $e) { $refused = true; }
	check($refused && $open->is_open(), 'an action the handler does not offer is refused and settles nothing');

	$message = '';
	try { $open->act('explode', (int)$admin->key); } catch (ContentReportException $e) { $message = $e->getMessage(); }
	check($message === 'The feature refused: not today.' && $open->is_open(),
		"a handler's own exception reaches the queue as its message, and the report stays open", $message);

	// ------------------------------------------------------------------
	section('The queue page dismisses');

	cr_act_as($admin);
	$_SERVER['REQUEST_URI'] = '/admin/admin_content_reports';   // set_return() reads it
	$result = harness_call_logic(CR_ADMIN_LOGIC, 'admin_content_reports_logic',
		array('action' => 'dismiss', 'rpt_content_report_id' => (int)$open->key));
	$open->load();
	check(!empty($result->redirect) && $open->get('rpt_status') === 'dismissed'
		&& (int)$open->get('rpt_usr_user_id_handled_by') === (int)$admin->key,
		'Dismiss on the queue page settles the report and redirects', (string)$open->get('rpt_status'));
	check($handler->items[102]['hidden'] === false, 'and leaves the content as it is');

	$result = harness_call_logic(CR_ADMIN_LOGIC, 'admin_content_reports_logic', array('filter' => 'dismissed'), 'GET');
	$listed = array();
	foreach ($result->data['rows'] ?? array() as $row) { $listed[] = (int)$row['report']->key; }
	check(in_array((int)$open->key, $listed, true), 'the dismissed filter lists it');

	// ------------------------------------------------------------------
	section('Rate limit');

	harness_set_setting_mem('content_report_rate_limit_requests', '2');
	harness_set_setting_mem('content_report_rate_limit_window', '3600');
	$limited = $make_user('limited', 0);
	$made[] = (int)$limited->key;
	cr_act_as($limited);
	$handler->items[101]['hidden'] = false;
	$a = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 101, 'reason' => 'spam'));
	$b = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 102, 'reason' => 'spam'));
	$c = cr_report(array('entity_type' => 'harness_thing', 'entity_id' => 102, 'reason' => 'spam'));
	check($a->error === null && $b->error === null, 'reports under the limit go through', $a->error . ' / ' . $b->error);
	check($c->error !== null, 'the one past the limit is refused', (string)$c->error);
} catch (\Throwable $e) {
	check(false, 'suite ran without an exception', get_class($e) . ': ' . $e->getMessage()
		. ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
