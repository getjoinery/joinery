<?php
/** @joinery-test
 * name: agent_case_intake
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The case on the plane (specs/agent_tier1_recipes.md, "The case", settled Q3,
 * "A case is untrusted input to the plane").
 *
 * A case is the one thing a node pushes at this plane on its own initiative,
 * and a compromised node writes what it likes into every field of it. What is
 * worth testing is therefore not that a case is stored — it is that:
 *
 *   - every field is capped and rebuilt from a closed set of keys before it
 *     is believed (long strings, HTML, shell metacharacters, control bytes
 *     come out bounded and inert; nothing is interpreted);
 *   - a forged id cannot reopen, replay or overwrite: a known id is appended
 *     to, a closed id never reopens, an older id than the open one is refused;
 *   - a node holds at most one open case per source, and the storm the spec
 *     names — one fault opening a case every tick — cannot happen through
 *     this door; nor can more than MAX_OPEN_CASES_PER_NODE open cases;
 *   - the plane records the close the node reports and never writes one of
 *     its own, except the one truthful case: the node moved on to a newer
 *     case, which it can only do after closing the old one;
 *   - the card, the notices and the mail render every field escaped, and
 *     nothing in a case is ever a link.
 *
 * Throwaway node and case rows are permanently removed in cleanup.
 *
 * Run: php plugins/server_manager/tests/agent_case_intake_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

// The card's note form is a FormWriter form, which starts a session for its
// own CSRF token; start it here, before the first line of output.
if (session_status() === PHP_SESSION_NONE) {
	@session_start();
}

// ---------------------------------------------------------------------------
section('The table is here and a case round-trips');

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'Case intake test');
$node->set('mgn_slug', 'harnesstest-case-' . bin2hex(random_bytes(3)));
$node->set('mgn_host', '192.0.2.41');
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_agent_public_key', base64_encode(str_repeat("\x02", 32)));
$node->set('mgn_agent_version', '1.28.0');
$node->set('mgn_agent_primitives', 'check_status,host_converge,host_report');
$node->set('mgn_agent_recipes', 'fail2ban:report-only');
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_id', $node->key);
$node_id = (int)$node->key;

// Every case row this node gets is swept with it: cases cascade from the
// node, and the node is registered above. Register each anyway, so a failed
// cascade never leaves debris.
function case_rows_for(int $node_id): array {
	static $registered = [];
	$out = [];
	foreach (new MultiIncidentRecord(['node_id' => $node_id], ['inc_id' => 'ASC']) as $row) {
		if (!isset($registered[(int)$row->key])) {
			$registered[(int)$row->key] = true;
			harness_register_row('inc_incident_records', 'inc_id', $row->key);
		}
		$out[] = $row;
	}
	return $out;
}

function a_case(array $over = []): array {
	return array_merge([
		'id' => 4, 'source' => 'recipe:fail2ban', 'recipe' => 'fail2ban', 'status' => 'open',
		'opened' => '2026-09-14T12:10:00Z', 'reason' => '3 attempts in the last hour and the check still fails (fail2ban is inactive)',
		'notes' => 0,
		'body' => [
			'mode' => 'report-only',
			'attempts' => [
				['id' => 1, 'started' => '2026-09-14T11:20:00Z', 'word' => 'host_converge', 'mode' => 'report-only',
				 'outcome' => 'report-only', 'ended' => '2026-09-14T11:20:00Z', 'detail' => 'would have run host_converge'],
			],
			'host_report' => ['expected_units' => ['fail2ban' => 'inactive'], 'failed_units' => ['fail2ban.service'],
				'fail2ban_jails' => [], 'generated_at' => 1789400000],
			'vocabulary' => 'check_status,host_converge,host_report',
			'recipes' => 'fail2ban:report-only',
		],
	], $over);
}

$table_ok = '';
try {
	$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case()]);
} catch (Throwable $e) {
	$table_ok = get_class($e) . ': ' . $e->getMessage();
	$outcomes = [];
}
check($table_ok === '', 'inc_incident_records exists on this plane',
	$table_ok . ' — if the table is missing, run update_database');
check(($outcomes['recipe:fail2ban'] ?? '') === 'stored', 'A new case is stored', json_encode($outcomes));
$rows = case_rows_for($node_id);
check(count($rows) === 1 && $rows[0]->is_open() && (int)$rows[0]->get('inc_node_case_id') === 4
	&& (string)$rows[0]->get('inc_opened_time') === '2026-09-14 12:10:00',
	'The stored case carries the node-minted id, its status and its opening time');
$body = $rows[0]->body();
check(is_array($body) && $body['mode'] === 'report-only' && count($body['attempts']) === 1
	&& $body['attempts'][0]['word'] === 'host_converge' && $body['attempts'][0]['outcome'] === 'report-only'
	&& $body['host_report']['expected_units']['fail2ban'] === 'inactive' && $body['host_report']['failed_units'] === ['fail2ban.service']
	&& $body['vocabulary'] === 'check_status,host_converge,host_report',
	'The body round-trips through the same sanitiser the Host card uses', json_encode($body));

// ---------------------------------------------------------------------------
section('A known id is appended to, and the node closes it');

$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case(['notes' => 3,
	'last_note' => 'fail2ban is inactive', 'last_note_time' => '2026-09-14T12:40:00Z', 'body' => null])]);
check(($outcomes['recipe:fail2ban'] ?? '') === 'appended', 'Notes on a known id are appended', json_encode($outcomes));
$rows = case_rows_for($node_id);
check(count($rows) === 1 && (int)$rows[0]->get('inc_note_count') === 3
	&& (string)$rows[0]->get('inc_last_note') === 'fail2ban is inactive' && $rows[0]->body() !== null,
	'The note count and newest note move; the body already stored stays');

$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case(['notes' => 3, 'body' => null])]);
check(($outcomes['recipe:fail2ban'] ?? '') === 'unchanged', 'The same summary again changes nothing', json_encode($outcomes));

$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case(['status' => 'closed', 'notes' => 3,
	'closed' => '2026-09-14T13:00:00Z', 'close_reason' => 'the check passes: fail2ban is active with 1 jail(s): sshd', 'body' => null])]);
check(($outcomes['recipe:fail2ban'] ?? '') === 'closed', 'The close the node reports is recorded', json_encode($outcomes));
$rows = case_rows_for($node_id);
check(count($rows) === 1 && !$rows[0]->is_open() && (string)$rows[0]->get('inc_closed_time') === '2026-09-14 13:00:00'
	&& strpos((string)$rows[0]->get('inc_close_reason'), 'passes') !== false,
	'The closed case carries the node\'s close time and reason');
check(IncidentRecord::open_for($node_id, 'recipe:fail2ban') === null, 'No open case remains for the source');

$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case(['status' => 'open', 'body' => null])]);
check(strpos((string)($outcomes['recipe:fail2ban'] ?? ''), 'refused') === 0
	&& strpos((string)$outcomes['recipe:fail2ban'], 'reopen') !== false,
	'A closed id never reopens: a forged "open" for it is refused', json_encode($outcomes));

// ---------------------------------------------------------------------------
section('One open case per source, and the storm cannot come through this door');

$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case(['id' => 7, 'opened' => '2026-09-14T15:00:00Z'])]);
check(($outcomes['recipe:fail2ban'] ?? '') === 'stored', 'The next escalation is a new case with a higher id');

// The storm: the same open case reported on twenty polls in a row is one row.
for ($i = 1; $i <= 20; $i++) {
	AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case(['id' => 7, 'opened' => '2026-09-14T15:00:00Z',
		'notes' => $i, 'last_note' => 'still down', 'last_note_time' => '2026-09-14T15:10:00Z', 'body' => null])]);
}
$rows = case_rows_for($node_id);
$open = array_values(array_filter($rows, function ($r) { return $r->is_open(); }));
check(count($rows) === 2 && count($open) === 1 && (int)$open[0]->get('inc_note_count') === 20,
	'Twenty polls carrying the same open case are one row with twenty notes, not twenty cases',
	count($rows) . ' rows, ' . count($open) . ' open');

$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case(['id' => 5, 'opened' => '2026-09-14T14:00:00Z'])]);
check(strpos((string)($outcomes['recipe:fail2ban'] ?? ''), 'refused') === 0
	&& strpos((string)$outcomes['recipe:fail2ban'], 'not newer') !== false,
	'A forged id older than the open case is refused, and opens nothing', json_encode($outcomes));
check(IncidentRecord::open_count($node_id) === 1, 'Still one open case');

// The node moved on: #9 arrives open while #7 is open here. The node can only
// have opened #9 after closing #7, so #7 is closed here with a note that
// says the close was not heard — in the plane's words, never the node's.
$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:fail2ban' => a_case(['id' => 9, 'opened' => '2026-09-14T18:00:00Z'])]);
check(($outcomes['recipe:fail2ban'] ?? '') === 'stored', 'A newer open case is stored', json_encode($outcomes));
$seven = IncidentRecord::find($node_id, 'recipe:fail2ban', 7);
check($seven !== null && !$seven->is_open() && strpos((string)$seven->get('inc_close_reason'), 'not heard') !== false,
	'The older open case is closed with a note saying the node\'s close was not heard');
check(IncidentRecord::open_count($node_id) === 1 && IncidentRecord::open_for($node_id, 'recipe:fail2ban')->get('inc_node_case_id') == 9,
	'One open case per source, and it is the newest');
case_rows_for($node_id);

// ---------------------------------------------------------------------------
section('A hostile case is capped, escaped and refused where the spec says');

$recipes = ['fail2ban' => 'report-only'];
$hostile_text = '<script>alert(1)</script>`rm -rf /`$(reboot); ' . str_repeat('x', 5000) . "\x00\x07\x1b[31m";
$hostile = a_case([
	'reason' => $hostile_text, 'last_note' => $hostile_text, 'notes' => 2, 'last_note_time' => 'yesterday',
	'opened' => '2026-09-14T12:10:00Z; DROP TABLE inc_incident_records',
	'body' => [
		'mode' => 'armed; sudo',
		'attempts' => array_fill(0, 50, ['id' => -1, 'word' => '../../bin/sh', 'outcome' => 'pwned', 'detail' => $hostile_text])
			+ [50 => ['id' => 1, 'word' => 'host_converge', 'outcome' => 'failed', 'detail' => str_repeat('d', 100000)]],
		'host_report' => ['failed_units' => array_fill(0, 500, '<b>x</b>'), 'sshd' => ['permit_root_login' => '<yes>'],
			'extra_key' => 'ignored', 'expected_units' => ['fail2ban' => 'pwned']],
		'vocabulary' => 'host_report,<img src=x>,' . str_repeat('a', 5000),
		'recipes' => 'fail2ban:armed; rm -rf',
		'shell' => 'rm -rf /',
	],
]);
$clean = AgentChannelEndpoint::normalised_case('recipe:fail2ban', $hostile, $recipes);
check(is_array($clean), 'A hostile but well-formed case is accepted, capped', is_string($clean) ? $clean : '');
if (is_array($clean)) {
	check(mb_strlen($clean['reason']) <= AgentChannelEndpoint::MAX_CASE_TEXT + 1
		&& mb_strlen($clean['last_note']) <= AgentChannelEndpoint::MAX_CASE_TEXT + 1,
		'Free text is capped at MAX_CASE_TEXT', mb_strlen($clean['reason']) . ' chars');
	check(strpos($clean['reason'], "\x00") === false && strpos($clean['reason'], "\x1b") === false && strpos($clean['reason'], "\x07") === false,
		'Control bytes are stripped');
	check(strpos($clean['reason'], '<script>') !== false,
		'Markup is stored as the text it is (the cap is not the escape; the render escapes)');
	check($clean['opened'] === null && $clean['last_note_time'] === null,
		'A time that is not RFC 3339 UTC is null, never interpreted');
	$b = $clean['body'];
	check(array_keys($b) === ['mode', 'attempts', 'host_report', 'vocabulary', 'recipes'],
		'The body is rebuilt from a closed set of keys; a key the node invents does not exist here', json_encode(array_keys($b)));
	check($b['mode'] === 'unknown', 'A mode outside the closed set reads as unknown');
	check(count($b['attempts']) === 0,
		'Attempts naming a word that could not be a primitive are dropped, and the list is capped at ' . AgentChannelEndpoint::MAX_CASE_ATTEMPTS,
		count($b['attempts']) . ' kept');
	check($b['host_report']['expected_units']['fail2ban'] === 'unknown'
		&& count($b['host_report']['failed_units']) <= JobResultProcessor::HOST_REPORT_MAX_LIST
		&& !isset($b['host_report']['extra_key']) && $b['host_report']['sshd']['permit_root_login'] === 'yes',
		'The host report goes through sanitise_host_report: closed keys, closed states, capped lists');
	check($b['vocabulary'] === 'host_report', 'The vocabulary is normalised like the claim\'s');
	check($b['recipes'] === '', 'A recipe list outside name:mode form is empty, not partly believed');
}

$good_attempts = a_case(['body' => ['attempts' => array_fill(0, 30, ['id' => 1, 'word' => 'host_converge', 'outcome' => 'failed',
	'detail' => str_repeat('d', 100000)])]]);
$clean = AgentChannelEndpoint::normalised_case('recipe:fail2ban', $good_attempts, $recipes);
check(is_array($clean) && count($clean['body']['attempts']) === AgentChannelEndpoint::MAX_CASE_ATTEMPTS
	&& mb_strlen($clean['body']['attempts'][0]['detail']) <= AgentChannelEndpoint::MAX_CASE_ATTEMPT_DETAIL + 1,
	'Thirty attempts with transcript-sized details become ' . AgentChannelEndpoint::MAX_CASE_ATTEMPTS . ' with bounded details');

$refusals = [
	'a source not in kind:name form'            => ['recipe:../etc', a_case(['source' => 'recipe:../etc'])],
	'a source with shell characters'            => ['recipe:fail2ban;id', a_case(['source' => 'recipe:fail2ban;id'])],
	'a recipe the node does not report'         => ['recipe:sudoers', a_case(['source' => 'recipe:sudoers', 'recipe' => 'sudoers'])],
	'a kind this plane does not know'           => ['attacker:fail2ban', a_case(['source' => 'attacker:fail2ban'])],
	'a case filed under another source'         => ['recipe:fail2ban', a_case(['source' => 'classifier:unexplained_root'])],
	'a recipe field that contradicts the source' => ['recipe:fail2ban', a_case(['recipe' => 'other'])],
	'an id that is a string'                    => ['recipe:fail2ban', a_case(['id' => '4; DROP'])],
	'an id of zero'                             => ['recipe:fail2ban', a_case(['id' => 0])],
	'an id past int4'                           => ['recipe:fail2ban', a_case(['id' => 2147483648])],
	'a status outside open and closed'          => ['recipe:fail2ban', a_case(['status' => 'resolved'])],
	'a note count that is not a number'         => ['recipe:fail2ban', a_case(['notes' => 'many'])],
	'a body that is a list'                     => ['recipe:fail2ban', a_case(['body' => [1, 2, 3]])],
	'a case that is a string'                   => ['recipe:fail2ban', 'rm -rf /'],
];
foreach ($refusals as $label => [$source, $entry]) {
	$why = AgentChannelEndpoint::normalised_case($source, $entry, $recipes);
	check(is_string($why), 'Refused: ' . $label, is_array($why) ? 'accepted' : '');
}
$classifier = a_case(['source' => 'classifier:unexplained_root', 'recipe' => '', 'body' => null]);
unset($classifier['recipe']);
$clean = AgentChannelEndpoint::normalised_case('classifier:unexplained_root', $classifier, $recipes);
check(is_array($clean) && $clean['recipe'] === '',
	'The unexplained-root classifier\'s source is left open for it: accepted with no recipe', is_string($clean) ? $clean : '');

// A fourth open case, and a fifth: the per-node ceiling.
$node->set('mgn_agent_recipes', 'a01:armed,a02:armed,a03:armed,a04:armed,a05:armed,a06:armed,a07:armed,a08:armed,a09:armed,a10:armed,fail2ban:report-only');
$node->save();
$flood = [];
for ($i = 1; $i <= AgentChannelEndpoint::MAX_CASES_PER_CLAIM + 2; $i++) {
	$name = sprintf('a%02d', $i);
	$flood['recipe:' . $name] = a_case(['id' => 100 + $i, 'source' => 'recipe:' . $name, 'recipe' => $name, 'body' => null]);
}
$outcomes = AgentChannelEndpoint::intake_cases($node, $flood);
$stored = count(array_filter($outcomes, function ($o) { return $o === 'stored'; }));
$refused = count(array_filter($outcomes, function ($o) { return strpos($o, 'refused') === 0; }));
check($stored === AgentChannelEndpoint::MAX_CASES_PER_CLAIM && $refused === 2,
	'A claim carrying more than ' . AgentChannelEndpoint::MAX_CASES_PER_CLAIM . ' cases has the rest refused', json_encode($outcomes));
case_rows_for($node_id);
// Fill the node to its ceiling, then one more.
for ($i = AgentChannelEndpoint::MAX_CASES_PER_CLAIM + 1; IncidentRecord::open_count($node_id) < AgentChannelEndpoint::MAX_OPEN_CASES_PER_NODE; $i++) {
	$name = sprintf('a%02d', $i);
	AgentChannelEndpoint::intake_cases($node, ['recipe:' . $name => a_case(['id' => 100 + $i, 'source' => 'recipe:' . $name, 'recipe' => $name, 'body' => null])]);
}
check(IncidentRecord::open_count($node_id) === AgentChannelEndpoint::MAX_OPEN_CASES_PER_NODE, 'Setup: the node is at its open-case ceiling');
$outcomes = AgentChannelEndpoint::intake_cases($node, ['recipe:a10' => a_case(['id' => 999, 'source' => 'recipe:a10', 'recipe' => 'a10', 'body' => null])]);
check(strpos((string)($outcomes['recipe:a10'] ?? ''), 'refused') === 0 && IncidentRecord::open_count($node_id) === AgentChannelEndpoint::MAX_OPEN_CASES_PER_NODE,
	'An open case past the per-node ceiling is refused', json_encode($outcomes));
case_rows_for($node_id);

// ---------------------------------------------------------------------------
section('The claim declares the field, bounded');

$spec = AgentChannelEndpoint::claim_request_spec();
$claim = ['node_id' => 7, 'agent_version' => '1.28.0', 'primitives' => 'host_report', 'recipes' => 'fail2ban:report-only',
	'cases' => ['recipe:fail2ban' => a_case()]];
check(AgentChannelEndpoint::validation_error($claim, $spec) === null, 'A claim carrying a case is accepted',
	(string)AgentChannelEndpoint::validation_error($claim, $spec));
check(AgentChannelEndpoint::validation_error(['node_id' => 7, 'cases' => []], $spec) === null, 'An empty cases object is accepted');
check(AgentChannelEndpoint::validation_error(['node_id' => 7, 'cases' => [a_case()]], $spec) !== null, 'A cases LIST is not the object the field declares');
check(AgentChannelEndpoint::validation_error(['node_id' => 7, 'cases' => 'recipe:fail2ban'], $spec) !== null, 'A cases string is refused');
$huge = ['node_id' => 7, 'cases' => ['recipe:fail2ban' => a_case(['reason' => str_repeat('r', AgentChannelEndpoint::MAX_CASES_BYTES)])]];
check(stripos((string)AgentChannelEndpoint::validation_error($huge, $spec), 'limit') !== false,
	'The cases field is bounded as JSON at MAX_CASES_BYTES', (string)AgentChannelEndpoint::validation_error($huge, $spec));
check(AgentChannelEndpoint::MAX_CASES_BYTES * 2 + AgentChannelEndpoint::MAX_VOCABULARY_BYTES * 2 < AgentChannelEndpoint::MAX_REQUEST_BODY,
	'Two full cases fields and the usual extras fit the request body with room');

// ---------------------------------------------------------------------------
section('The card, the notices and the mail render every field escaped');

$rows = case_rows_for($node_id);
$shown = null;
foreach ($rows as $r) { if ((int)$r->get('inc_node_case_id') === 9) { $shown = $r; } }
check($shown !== null, 'Setup: case #9 is here');
if ($shown !== null) {
	$shown->set('inc_reason', '<script>alert("x")</script> & "quotes"');
	$shown->set('inc_note_count', 2);
	$shown->set('inc_last_note', '<img src=x onerror=alert(1)>');
	$shown->set('inc_human_note', '<b>note</b>');
	$body = $shown->body();
	$body['attempts'][0]['detail'] = '<i>detail</i> http://evil.example/';
	$shown->set('inc_body', $body);
	$shown->save();
	$html = IncidentCaseCard::render_case($shown, '/admin/server_manager/node_detail?mgn_id=' . $node_id, SmAdminCsrf::token());
	check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'The reason is escaped on the card');
	check(strpos($html, '<img src=x') === false && strpos($html, '&lt;img') !== false, 'The newest note is escaped on the card');
	check(strpos($html, '<b>note</b>') === false && strpos($html, '&lt;b&gt;note') !== false, 'The human note is escaped on the card');
	check(strpos($html, '<i>detail</i>') === false && strpos($html, '&lt;i&gt;detail') !== false, 'An attempt detail is escaped on the card');
	check(!preg_match('#href="[^"]*evil\.example#', $html), 'Nothing in a case becomes a link');
	check(substr_count(strtolower($html), 'method="post"') >= 2 && substr_count($html, 'name="' . SmAdminCsrf::FIELD . '"') >= 2,
		'The note and mark-read controls are POST forms carrying the CSRF token');

	$notice = FleetAttentionNotice::open_cases_for([$shown], [$node_id => '<b>' . $node->get('mgn_name') . '</b>']);
	check(strpos($notice, '<script>') === false && strpos($notice, '<b>') === false && strpos($notice, '&lt;b&gt;') !== false,
		'The open-case notice escapes the case and the node name');
	check(preg_match('#href="/admin/server_manager/node_detail\?mgn_id=' . $node_id . '&amp;tab=overview"#', $notice) === 1,
		'The notice links to the node page by id, never to anything the case said');

	$units = FleetAttentionNotice::failed_units_for([$node_id => ['name' => '<b>' . $node->get('mgn_name') . '</b>', 'units' => ['<i>x</i>.service', 'fail2ban.service']]]);
	check(strpos($units, '<b>') === false && strpos($units, '<i>') === false && strpos($units, '&lt;i&gt;x&lt;/i&gt;.service') !== false,
		'The failed-unit notice escapes the unit names and the node name');
	check(preg_match('#href="/admin/server_manager/node_detail\?mgn_id=' . $node_id . '&amp;tab=overview"#', $units) === 1,
		'The failed-unit notice links to the node page by id');
	check(FleetAttentionNotice::failed_units_for([]) === '' && FleetAttentionNotice::open_cases_for([], []) === '',
		'Both fleet notices are silent with nothing to say');

	$mail = RecipeCaseNotice::mail_body([
		'id' => 9, 'source' => 'recipe:fail2ban', 'recipe' => 'fail2ban', 'status' => 'open',
		'opened' => '2026-09-14T18:00:00Z', 'reason' => '<script>x</script> http://evil.example/ `id`', 'notes' => 2,
		'last_note' => '<b>still</b>', 'delivery' => 'local',
		'body' => ['mode' => 'report-only', 'attempts' => [['started' => '2026-09-14T17:00:00Z', 'word' => 'host_converge', 'outcome' => 'report-only', 'detail' => '<i>d</i>']]],
	], 'dev.example');
	check(strip_tags($mail) === $mail, 'The mail body is plain text with no markup in it (the sender would otherwise switch to HTML)');
	check(strpos($mail, 'http://') === false && strpos($mail, 'https://') === false, 'The mail carries no link at all');
	check(strpos($mail, "as reported by the agent's ledger") !== false, 'The mail says the record is as reported by the agent\'s ledger');
}

harness_finish();
