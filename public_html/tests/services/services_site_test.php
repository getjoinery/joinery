<?php
/** @joinery-test
 * name: services_site
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * The site side of Joinery-run services (specs/services_phase2_platform.md
 * §4, §9 — build item 6, without the managed target):
 *
 *   - The Connect flow: the button mints a single-use state and builds the
 *     operator's authorise URL; the landing refuses a spent or foreign state,
 *     a declined approval and a malformed key, and on success seals the pair
 *     and reads the account from the operator.
 *   - The getjoinery email provider: "register" is the enrol call; not
 *     entitled says the operator's sentence and writes nothing; entitled
 *     writes the nine send settings through HostedMailSettingsMap and keeps
 *     the records; the sending-domain state follows the operator's answer.
 *   - The daily poll writes the five banner settings under the services
 *     state, and HostedPlanNotice renders them with the first door at 80%.
 *   - The switch-over: once another provider is proven, the getjoinery mail
 *     service is released and its dead credential cleared.
 *
 * The operator is a php -S on a loopback port answering canned JSON per
 * action and logging what it was asked. Writes go to the test database.
 *
 * Run: php tests/run.php --only=tests/services/services_site_test.php
 *
 * @version 1.1 - disconnect releases both services
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();
// A live session before any \$_SESSION write: FormWriter and OAuth2State start
// one when none is active, which would replace what the test put there.
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('tasks/ServicesStatusPoll.php'));

// ── The operator, faked ─────────────────────────────────────────────────────
function svc_operator_start(): ?array {
	$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if (!$sock) { return null; }
	$name = stream_socket_get_name($sock, false);
	fclose($sock);
	$port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
	$dir = sys_get_temp_dir() . '/svcop_' . getmypid() . '_' . $port;
	@mkdir($dir, 0777, true);
	$router = $dir . '/router.php';
	file_put_contents($router, '<?php
$dir = __DIR__;
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (!preg_match("#^/api/v1/action/server_manager/([a-z_]+)$#", $path, $m)) { http_response_code(404); echo "{}"; exit; }
$action = $m[1];
$headers = function_exists("getallheaders") ? getallheaders() : array();
$log = array("action" => $action, "body" => json_decode(file_get_contents("php://input"), true),
	"public_key" => (string)($headers["public-key"] ?? $headers["Public-Key"] ?? ""),
	"secret_key" => (string)($headers["secret-key"] ?? $headers["Secret-Key"] ?? ""));
file_put_contents($dir . "/requests.log", json_encode($log) . "\n", FILE_APPEND);
$file = $dir . "/" . $action . ".json";
if (!is_file($file)) { http_response_code(404); echo json_encode(array("error" => "no canned answer for " . $action)); exit; }
$canned = json_decode(file_get_contents($file), true);
http_response_code((int)($canned["http"] ?? 200));
header("Content-Type: application/json");
echo json_encode($canned["reply"]);
');
	$descriptors = array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w'));
	$proc = proc_open(array('php', '-S', '127.0.0.1:' . $port, $router), $descriptors, $pipes);
	if (!is_resource($proc)) { return null; }
	for ($i = 0; $i < 100; $i++) {
		$s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
		if ($s) { fclose($s); return array('proc' => $proc, 'port' => $port, 'dir' => $dir); }
		usleep(50000);
	}
	proc_terminate($proc);
	return null;
}
function svc_operator_answer(array $op, string $action, array $reply, int $http = 200): void {
	file_put_contents($op['dir'] . '/' . $action . '.json', json_encode(array('http' => $http, 'reply' => $reply)));
}
function svc_operator_requests(array $op): array {
	$out = array();
	foreach (file($op['dir'] . '/requests.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
		$out[] = json_decode($line, true);
	}
	@unlink($op['dir'] . '/requests.log');
	return $out;
}

$op = svc_operator_start();
if ($op === null) {
	section('fixture');
	harness_skip('no loopback operator could start');
	harness_finish();
	exit;
}
harness_defer(function () use ($op) {
	if (is_resource($op['proc'])) { proc_terminate($op['proc']); proc_close($op['proc']); }
	foreach (glob($op['dir'] . '/*') ?: array() as $f) { @unlink($f); }
	@rmdir($op['dir']);
});
$operator_url = 'http://127.0.0.1:' . $op['port'];

// The site: its host is what webDir says. Settings are written to the test
// database; the ones this touches are restored to blank at the end.
$site_host = 'site-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';
harness_set_setting_mem('webDir', $site_host);
$touched = array('services_url', 'services_api_public_key', 'services_api_secret_key', 'services_account',
	'services_mail_state', 'email_service', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
	'smtp_sender', 'smtp_helo', 'smtp_hostname', 'smtp_auth', 'hosted_plan_state', 'hosted_plan_until_time',
	'hosted_plan_notice', 'hosted_plan_allowances', 'hosted_plan_manage_url', 'email_test_send_last_success');
$before = array();
foreach ($touched as $name) { $before[$name] = (string)get_setting_raw($name); }
harness_defer(function () use ($touched, $before) {
	foreach ($touched as $name) {
		Setting::put($name, $before[$name]);
		Globalvars::get_instance()->forget_setting($name);
	}
});
$put = function (string $name, string $value) {
	Setting::put($name, $value);
	Globalvars::get_instance()->forget_setting($name);
};
$put('services_url', $operator_url);
$put('services_api_public_key', '');
$put('services_api_secret_key', '');
$put('services_account', '');
$put('services_mail_state', '');
$put('email_service', '');
$put('hosted_plan_state', '');
foreach (array('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_sender', 'smtp_helo', 'smtp_hostname', 'smtp_auth') as $name) {
	$put($name, '');
}

// ── Connect ─────────────────────────────────────────────────────────────────
section('connect: the button mints a state and builds the authorise URL');
$saved_session = $_SESSION ?? array();
$_SESSION = array('loggedin' => 1, 'usr_user_id' => 1, 'permission' => 10);
check(!ServicesClient::connected(), 'a fresh site is not connected');
check(ServicesClient::host() === $site_host, 'the site names itself by webDir');
$url = ServicesClient::connectUrl('mail_send');
$parsed = parse_url($url);
parse_str((string)($parsed['query'] ?? ''), $q);
check(strpos($url, $operator_url . '/services/authorize?') === 0, 'the authorise URL is on the operator');
check(($q['site'] ?? '') === $site_host && ($q['return'] ?? '') === 'https://' . $site_host . '/services_connected',
	'it carries the site and its own return address', json_encode($q));
check(strlen((string)($q['state'] ?? '')) === 64, 'and a 64-character state', (string)($q['state'] ?? ''));
$state = (string)$q['state'];

section('connect: the landing refuses what it should');
$r = ServicesClient::finishConnect(array('state' => 'not-a-state', 'public_key' => 'public_abc12345', 'secret_key' => 'secret_abc12345'));
check(!$r['ok'] && strpos($r['message'], 'expired or was already used') !== false, 'an unknown state is refused');
$r = ServicesClient::finishConnect(array('state' => $state, 'error' => 'declined'));
check(!$r['ok'] && strpos($r['message'], 'not approved') !== false && $r['step'] === 'mail_send', 'a declined approval is refused, and the state is spent');
$r = ServicesClient::finishConnect(array('state' => $state, 'public_key' => 'public_abc12345', 'secret_key' => 'secret_abc12345'));
check(!$r['ok'], 'the same state does not land twice');
$state2 = (string)parse_url(ServicesClient::connectUrl('backups'), PHP_URL_QUERY);
parse_str($state2, $q2);
$r = ServicesClient::finishConnect(array('state' => $q2['state'], 'public_key' => 'nope', 'secret_key' => 'secret_abc12345'));
check(!$r['ok'] && strpos($r['message'], 'shape') !== false && $r['step'] === 'backups', 'a malformed key is refused; the step it came from is kept');
check(!ServicesClient::connected(), 'nothing was stored by any refusal');

section('connect: a good landing seals the pair and reads the account');
svc_operator_answer($op, 'services_status', array('status' => 'success', 'data' => array(
	'connected' => true, 'account' => 'owner@example.com', 'manage_url' => 'https://op.example/profile/server_manager/services', 'services' => array())));
$state3 = (string)parse_url(ServicesClient::connectUrl('mail_send'), PHP_URL_QUERY);
parse_str($state3, $q3);
$r = ServicesClient::finishConnect(array('state' => $q3['state'], 'public_key' => 'public_sitekey123', 'secret_key' => 'secret_sitekey123'));
check($r['ok'] && strpos($r['message'], 'owner@example.com') !== false, 'the landing succeeds and names the account', $r['message']);
check(ServicesClient::connected() && ServicesClient::account() === 'owner@example.com', 'the site is connected');
$stored = (string)get_setting_raw('services_api_secret_key');
check($stored !== 'secret_sitekey123' && (SecretBox::looksEncrypted($stored) || $stored === ''), 'the secret is sealed at rest', substr($stored, 0, 12));
$reqs = svc_operator_requests($op);
check(count($reqs) === 1 && $reqs[0]['action'] === 'services_status' && $reqs[0]['public_key'] === 'public_sitekey123'
	&& $reqs[0]['secret_key'] === 'secret_sitekey123' && ($reqs[0]['body']['host'] ?? '') === $site_host,
	'the status call went over the new key pair with the host', json_encode($reqs));

section('connect: the landing page logic');
$_SERVER['HTTP_HOST'] = $site_host;
$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
$state4 = (string)parse_url(ServicesClient::connectUrl('mail_send'), PHP_URL_QUERY);
parse_str($state4, $q4);
$res = harness_call_logic('logic/services_connected_logic.php', 'services_connected_logic',
	array('state' => $q4['state'], 'public_key' => 'public_sitekey124', 'secret_key' => 'secret_sitekey124'), 'GET');
check($res->redirect === '/setup?step=mail_send' && !empty($_SESSION['setup_services_connect_result']['ok']),
	'the landing redirects to the wizard step with its message', (string)$res->redirect);
$_SERVER['HTTP_HOST'] = 'other.example.com';
$res = harness_call_logic('logic/services_connected_logic.php', 'services_connected_logic', array('state' => 'x'), 'GET');
check(strpos((string)($res->data['error'] ?? ''), 'not addressed to this site') !== false, 'a landing on another host is refused');
$_SERVER['HTTP_HOST'] = $site_host;
$_SERVER['HTTP_SEC_FETCH_DEST'] = 'empty';
$res = harness_call_logic('logic/services_connected_logic.php', 'services_connected_logic', array('state' => 'x'), 'GET');
check(strpos((string)($res->data['error'] ?? ''), 'opened as a page') !== false, 'a non-navigation fetch is refused');
$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
$res = harness_call_logic('logic/services_connected_logic.php', 'services_connected_logic', array('state' => 'x'), 'POST');
check(strpos((string)($res->data['error'] ?? ''), 'link, not a form') !== false, 'a POST landing is refused');
$_SESSION['permission'] = 5;
$res = harness_call_logic('logic/services_connected_logic.php', 'services_connected_logic', array('state' => 'x'), 'GET');
check(strpos((string)($res->data['error'] ?? ''), 'owner') !== false, 'only the owner connects');
$_SESSION['permission'] = 10;
unset($_SESSION['setup_services_connect_result']);

// ── The provider ────────────────────────────────────────────────────────────
section('provider: not entitled says the operator\'s sentence and writes nothing');
$plan = EmailSender::getDiscoveredProviders();
check(isset($plan['joinery_services']) && $plan['joinery_services'] === 'JoineryServicesProvider', 'the getjoinery provider is discovered');
check(in_array('SendingDomainRegistrar', class_implements('JoineryServicesProvider'), true)
	&& in_array('DkimRecordSource', class_implements('JoineryServicesProvider'), true), 'it registers a sending domain and reports records');
svc_operator_answer($op, 'services_enroll', array('status' => 'success', 'data' => array(
	'service' => 'mail', 'entitled' => false, 'state' => 'unpaid', 'paid_until' => null,
	'notice' => '', 'manage_url' => 'https://op.example/profile/server_manager/services')));
$reg = JoineryServicesProvider::createSendingDomain($site_host);
check($reg['status'] === 'error' && strpos($reg['error'], 'not entitled') !== false && strpos($reg['error'], 'https://op.example') !== false,
	'not entitled: an error with the sentence and the manage link', json_encode($reg));
check((string)get_setting_raw('email_service') === '' && (string)get_setting_raw('smtp_host') === '', 'nothing written');
check(JoineryServicesProvider::getSendingDomainState($site_host) === 'not_registered', 'the domain is not registered');
svc_operator_requests($op);
$v = JoineryServicesProvider::validateConfiguration();
check(!$v['valid'] && strpos($v['errors'][0], 'not set up') !== false, 'the provider says it is not set up');

section('provider: entitled writes the nine settings and keeps the records');
$records = array(
	array('type' => 'CNAME', 'name' => 's2g._domainkey.mail.' . $site_host, 'value' => 'dkim.smtp2go.net', 'purpose' => 'DKIM'),
	array('type' => 'CNAME', 'name' => 'em.mail.' . $site_host, 'value' => 'return.smtp2go.net', 'purpose' => 'Return-Path'),
);
svc_operator_answer($op, 'services_enroll', array('status' => 'success', 'data' => array(
	'service' => 'mail', 'entitled' => true, 'state' => 'active', 'paid_until' => '2030-01-01 23:59:59', 'notice' => '',
	'manage_url' => 'https://op.example/profile/server_manager/services',
	'domain' => 'mail.' . $site_host, 'domain_state' => 'domain_added', 'records' => $records,
	'mail' => array('service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 587, 'username' => 't9-abc123',
		'password' => 'Pw ' . str_repeat('x', 25), 'sender' => 'bounces@mail.' . $site_host,
		'helo' => 'mail.' . $site_host, 'hostname' => 'mail.' . $site_host,
		'domain' => 'mail.' . $site_host, 'domain_state' => 'domain_added', 'records' => $records))));
$reg = JoineryServicesProvider::createSendingDomain($site_host);
check($reg['status'] === 'ok', 'entitled: registered', json_encode($reg));
$reqs = svc_operator_requests($op);
check(count($reqs) === 1 && ($reqs[0]['body']['service'] ?? '') === 'mail' && ($reqs[0]['body']['host'] ?? '') === $site_host, 'one enrol call, for mail, naming the host');
check((string)get_setting_raw('email_service') === 'joinery_services', 'email_service is joinery_services');
check((string)get_setting_raw('smtp_host') === 'mail.smtp2go.com' && (string)get_setting_raw('smtp_port') === '587'
	&& (string)get_setting_raw('smtp_username') === 't9-abc123' && (string)get_setting_raw('smtp_password') === 'Pw ' . str_repeat('x', 25)
	&& (string)get_setting_raw('smtp_sender') === 'bounces@mail.' . $site_host && (string)get_setting_raw('smtp_helo') === 'mail.' . $site_host
	&& (string)get_setting_raw('smtp_hostname') === 'mail.' . $site_host && (string)get_setting_raw('smtp_auth') === '1',
	'the SMTP values land through the shared map, the password untrimmed');
check(ServicesClient::mailEnrolled(), 'the site sends through getjoinery');
$ms = ServicesClient::mailState();
check($ms['domain'] === 'mail.' . $site_host && $ms['domain_state'] === 'domain_added' && count($ms['records']) === 2
	&& $ms['paid_until'] === '2030-01-01 23:59:59', 'the operator\'s answer is kept, without the password', json_encode($ms));
check(strpos((string)get_setting_raw('services_mail_state'), 'Pw ') === false, 'no password in the recorded state');
check(JoineryServicesProvider::validateConfiguration()['valid'], 'the provider is configured');
check(JoineryServicesProvider::getSendingDomainState($site_host) === 'unverified', 'the sender domain is unverified while the records pend');
$dk = JoineryServicesProvider::getDkimStatus($site_host);
check($dk['status'] === 'ok' && count($dk['records']) === 2 && $dk['records'][1]['purpose'] === 'Return-Path', 'the records come back for the publish box');
check(EmailSender::activeServiceKey() === 'joinery_services', 'EmailSender sees the active service');

svc_operator_answer($op, 'services_status', array('status' => 'success', 'data' => array(
	'connected' => true, 'account' => 'owner@example.com', 'manage_url' => 'https://op.example/profile/server_manager/services',
	'services' => array('mail' => array('service' => 'mail', 'state' => 'active', 'paid_until' => '2030-01-01 23:59:59', 'entitled' => true,
		'figure' => 812, 'allowance' => 1000, 'unit' => 'sends', 'label' => 'Email sent this month', 'used_label' => '812',
		'allowance_label' => '1,000', 'percent' => 81, 'notice' => '', 'action_label' => 'Use your own email account',
		'action_url' => 'https://smtp2go.example/ref', 'manage_url' => 'https://op.example/profile/server_manager/services',
		'domain' => 'mail.' . $site_host, 'domain_state' => 'domain_verified', 'records' => $records)))));
check(JoineryServicesProvider::verifySendingDomain($site_host) === 'active', 'Refresh asks the operator and sees the domain verified');
svc_operator_requests($op);

// ── The banner ──────────────────────────────────────────────────────────────
section('the daily poll writes the banner under the services state');
$task = new ServicesStatusPoll();
$out = $task->run(array());
check($out['status'] === 'success' && strpos($out['message'], '1 service') !== false, 'the poll ran', json_encode($out));
check((string)get_setting_raw('hosted_plan_state') === 'services', 'hosted_plan_state = services');
check((string)get_setting_raw('hosted_plan_until_time') === '2030-01-01 23:59:59', 'the paid-through date is the banner date');
$rows = json_decode((string)get_setting_raw('hosted_plan_allowances'), true);
check(is_array($rows) && count($rows) === 1 && $rows[0]['label'] === 'Email sent this month' && $rows[0]['percent'] === 81
	&& $rows[0]['action_url'] === 'https://smtp2go.example/ref', 'the allowance row carries the first door');
check((string)get_setting_raw('hosted_plan_manage_url') === 'https://op.example/profile/server_manager/services', 'the manage link is the account\'s page');
check(HostedPlanNotice::applies(), 'HostedPlanNotice renders the services state');
$html = HostedPlanNotice::render();
check(strpos($html, 'getjoinery') !== false && strpos($html, 'Email sent this month') !== false
	&& strpos($html, 'https://smtp2go.example/ref') !== false && strpos($html, 'Your sites on getjoinery') !== false,
	'the banner names the services, the allowance and the door at 81%');
check(strpos($html, 'subscription') === false && strpos($html, 'card') === false, 'no billing sentence');
$_SERVER['REQUEST_METHOD'] = 'GET';
$cleared = ServicesStatusPoll::writeBanner(array('services' => array('mail' => array('state' => 'released'))));
check($cleared === 0 && (string)get_setting_raw('hosted_plan_state') === '' && !HostedPlanNotice::applies(), 'a site with no live service clears the banner');

// ── The switch-over ─────────────────────────────────────────────────────────
section('the switch-over: proving another provider releases getjoinery mail');
require_once(PathHelper::getThemeFilePath('setup_logic.php', 'logic'));
check(_setup_services_release_mail_if_switched() === null, 'while the site still sends through getjoinery nothing is released');
svc_operator_requests($op);
$put('email_service', 'mailgun');
svc_operator_answer($op, 'services_release', array('status' => 'success', 'data' => array(
	'service' => 'mail', 'state' => 'released', 'released' => true, 'domain' => 'mail.' . $site_host, 'records' => $records,
	'notice' => 'Outbound mail through getjoinery is closed.')));
$rel = _setup_services_release_mail_if_switched();
check(is_array($rel) && $rel['error'] === '' && count($rel['records']) === 2 && $rel['domain'] === 'mail.' . $site_host,
	'the release happens and lists the records to remove', json_encode($rel));
$reqs = svc_operator_requests($op);
check(count($reqs) === 1 && $reqs[0]['action'] === 'services_release' && ($reqs[0]['body']['service'] ?? '') === 'mail', 'one release call for mail');
check((string)get_setting_raw('smtp_password') === '' && (string)get_setting_raw('smtp_username') === '' && (string)get_setting_raw('smtp_host') === ''
	&& (string)get_setting_raw('email_service') === 'mailgun', 'the dead credential is cleared; the new provider stays');
check(_setup_services_release_mail_if_switched() === null, 'a second proof releases nothing (the state is released)');

section('disconnect releases both services, and a service never held is no refusal');
svc_operator_requests($op);
svc_operator_answer($op, 'services_release', array('status' => 'error', 'error' => 'This site holds no mail service to release.'), 400);
check(_setup_services_disconnect() === array(), 'a plane that holds neither service: nothing released, nothing raised');
$reqs = svc_operator_requests($op);
$asked = array_map(function ($r) { return (string)($r['body']['service'] ?? ''); }, $reqs);
check($asked === array('mail', 'shelf'), 'both services are asked for', json_encode($asked));
svc_operator_answer($op, 'services_release', array('status' => 'success', 'data' => array('service' => 'shelf', 'state' => 'released', 'released' => true)));
check(_setup_services_disconnect() === array('mail', 'shelf'), 'what the plane releases is answered');
svc_operator_requests($op);

section('the map is one list');
check(count(HostedMailSettingsMap::MAP) === 8 && HostedMailSettingsMap::MAP['email_service'] === 'service'
	&& HostedMailSettingsMap::MAP['smtp_password'] === 'password', 'eight values, nine settings with smtp_auth');
$script = file_get_contents(PathHelper::getIncludePath('utils/hosted_mail_settings.php'));
check(strpos($script, 'HostedMailSettingsMap::apply') !== false && strpos($script, "'smtp_username' =>") === false,
	'the node-side script writes through the map and carries no second list');

$_SESSION = $saved_session;
harness_finish();
