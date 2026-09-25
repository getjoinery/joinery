<?php
/** @joinery-test
 * name: agent_join_request_keep
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Rerunning `agent_control.php --join` keeps an unanswered request (B19).
 *
 * The agent treats a newer requested_time as a new ask: it withdraws, drops
 * its staged keypair and asks again with a new key. `install.sh site` passes
 * --join on every run, so rebuilding a site before its join was approved
 * orphaned the waiting request (1788, then 1789). The CLI now keeps a request
 * for the same URL that is still recorded; the agent clears it on approval and
 * on rejection, so a recorded one is one nobody has answered.
 *
 * Run: php tests/run.php --only=tests/unit/agent_join_request_keep_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));

$first = admin_management_node_cli_join_request('', 'https://manage.example.com/', '2026-09-25 10:00:00');
$req = json_decode((string)$first, true);

section('The first ask is recorded');
check(is_array($req) && $req['url'] === 'https://manage.example.com' && $req['requested_time'] === '2026-09-25 10:00:00',
	'no request yet: a request for the URL, at this time', (string)$first);

section('The same URL again keeps the request, and so the key');
check(admin_management_node_cli_join_request($first, 'https://manage.example.com', '2026-09-25 10:05:00') === null,
	'the same URL twice keeps requested_time');
check(admin_management_node_cli_join_request($first, ' https://manage.example.com/ ', '2026-09-25 10:05:00') === null,
	'a trailing slash or whitespace is the same URL');

section('Anything else is a fresh ask');
$other = json_decode((string)admin_management_node_cli_join_request($first, 'https://other.example.com', '2026-09-25 10:05:00'), true);
check(is_array($other) && $other['url'] === 'https://other.example.com' && $other['requested_time'] === '2026-09-25 10:05:00',
	'a different URL writes a new request');
// The agent clears the request on a rejection (and on approval).
$cleared = json_decode((string)admin_management_node_cli_join_request('', 'https://manage.example.com', '2026-09-25 10:05:00'), true);
check(is_array($cleared) && $cleared['requested_time'] === '2026-09-25 10:05:00',
	'a cleared request (after a rejection) writes a new one');
check(admin_management_node_cli_join_request('{not json', 'https://manage.example.com', 'T') !== null
	&& admin_management_node_cli_join_request('{"url":"https://manage.example.com"}', 'https://manage.example.com', 'T') !== null,
	'an unreadable or timeless request is replaced');

section('Who uses the rule');
$cli = file_get_contents(PathHelper::getIncludePath('utils/agent_control.php'));
check(strpos($cli, 'admin_management_node_cli_join_request(') !== false, 'agent_control.php --join goes through it');
$logic = file_get_contents(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));
$connect = substr($logic, strpos($logic, "\$input['action'] === 'connect'"), 800);
check(strpos($connect, 'admin_management_node_cli_join_request') === false && strpos($connect, "'requested_time' => gmdate(") !== false,
	'the admin page\'s Connect stays a fresh ask');

harness_finish();
