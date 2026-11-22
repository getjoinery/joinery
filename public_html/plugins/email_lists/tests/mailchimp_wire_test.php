<?php
/** @joinery-test
 * name: mailchimp_wire
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * MailChimpProvider against the Marketing API, over a mocked transport
 * (specs/email_provider_sdk_removal.md, WP7 and B5). No network is touched.
 *
 * Pins what the request looks like (the data-center host taken from the
 * key, the apikey Authorization header, the member path by MD5 of the
 * address) and the three behaviors B5 changed: an unsubscribe answered 404
 * is a success, a refused getLists() throws instead of answering empty, and
 * a 429 on subscribe() is a retryable failure.
 *
 * Run: php tests/run.php --filter=mailchimp_wire
 *
 * @version 1.1 - a data center with a trailing newline is refused (B17); a transport failure is
 *   retryable (T1); MailingListSync retries the same page and fails the list after (B11)
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());
foreach (array('MailingListProvider', 'MailingListProviderException', 'AbstractMailingListProvider', 'MailChimpProvider') as $f) {
	require_once(PathHelper::getIncludePath('plugins/email_lists/includes/mailing_list_providers/' . $f . '.php'));
}

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$GLOBALS['mc_history'] = array();

function mc_mock(array $queue): void {
	$GLOBALS['mc_history'] = array();
	$stack = HandlerStack::create(new MockHandler($queue));
	$stack->push(Middleware::history($GLOBALS['mc_history']));
	ProviderHttp::useTestHandler($stack);
}

function mc_json(int $status, array $body): Response {
	return new Response($status, array('Content-Type' => 'application/json'), json_encode($body));
}

function mc_req(int $i = 0) { return $GLOBALS['mc_history'][$i]['request'] ?? null; }

ProviderHttp::useTestSleep(function ($s) {});
harness_defer(function () { ProviderHttp::useTestHandler(null); ProviderHttp::useTestSleep(null); });

harness_set_setting_mem('mailchimp_api_key', 'abc123def456-us6');
harness_set_setting_mem('mailchimp_default_merge_fields', '{"MMERGE3":"site"}');
$mc = new MailChimpProvider();

section('The data center and the credential');

check(MailChimpProvider::apiBase('abc123-us6') === 'https://us6.api.mailchimp.com/3.0', 'the data center is the key\'s second dash piece');
$threw = false;
try { MailChimpProvider::apiBase('abc123'); } catch (MailingListProviderException $e) { $threw = true; }
check($threw, 'a key with no data center is refused');
$threw = false;
try { MailChimpProvider::apiBase('abc-evil.example.com/x'); } catch (MailingListProviderException $e) { $threw = true; }
check($threw, 'a data center that is not letters then digits never reaches a host name');
$threw = false;
try { MailChimpProvider::apiBase("abc-us6\n-x"); } catch (MailingListProviderException $e) { $threw = true; }
check($threw, 'a data center with a trailing newline is refused');

mc_mock(array(mc_json(200, array('lists' => array(array('id' => 'L1', 'name' => 'News', 'stats' => array('member_count' => 4))), 'total_items' => 1))));
$lists = $mc->getLists();
$req = mc_req();
check($req && $req->getUri()->getHost() === 'us6.api.mailchimp.com', 'requests go to the key\'s data center');
check($req && $req->getUri()->getPath() === '/3.0/lists' && strpos($req->getUri()->getQuery(), 'count=1000') !== false,
	'getLists asks /lists?count=1000');
check($req && $req->getHeaderLine('Authorization') === 'apikey abc123def456-us6', 'the key travels as Authorization: apikey');
check($lists === array(array('id' => 'L1', 'name' => 'News', 'member_count' => 4)), 'lists are read from the answer');

section('Subscribe');

mc_mock(array(mc_json(200, array('id' => 'hash1', 'status' => 'subscribed'))));
$id = $mc->subscribe('L1', ' Person@Example.com ', 'Ann', 'Bee');
$req = mc_req();
check($id === 'hash1', 'subscribe returns the member id');
check($req && $req->getMethod() === 'PUT' && $req->getUri()->getPath() === '/3.0/lists/L1/members/' . md5('person@example.com'),
	'subscribe is a PUT on the member path, by MD5 of the lowercased address');
$body = $req ? json_decode((string)$req->getBody(), true) : array();
check(($body['email_address'] ?? '') === 'person@example.com' && ($body['status'] ?? '') === 'subscribed',
	'the body carries the address and status');
check(($body['merge_fields'] ?? array()) === array('FNAME' => 'Ann', 'LNAME' => 'Bee', 'MMERGE3' => 'site'),
	'names and the default merge fields are sent');

mc_mock(array(mc_json(429, array('title' => 'Too Many Requests', 'detail' => 'slow down'))));
$e429 = null;
try { $mc->subscribe('L1', 'a@example.com', '', ''); } catch (MailingListProviderException $e) { $e429 = $e; }
check($e429 !== null && $e429->isRetryable(), 'a 429 on subscribe is a retryable failure');
check($e429 && strpos($e429->getMessage(), '429') !== false && strpos($e429->getMessage(), 'slow down') !== false,
	'the failure names the status and Mailchimp\'s words', $e429 ? $e429->getMessage() : '');

mc_mock(array(mc_json(400, array('title' => 'Member Exists', 'detail' => 'bad'))));
$e400 = null;
try { $mc->subscribe('L1', 'a@example.com', '', ''); } catch (MailingListProviderException $e) { $e400 = $e; }
check($e400 !== null && !$e400->isRetryable(), 'a 400 on subscribe is a permanent failure');

mc_mock(array(mc_json(503, array('title' => 'Down'))));
$e503 = null;
try { $mc->subscribe('L1', 'a@example.com', '', ''); } catch (MailingListProviderException $e) { $e503 = $e; }
check($e503 !== null && $e503->isRetryable(), 'a 5xx on subscribe is retryable');

section('Unsubscribe');

mc_mock(array(mc_json(200, array('id' => 'h', 'status' => 'unsubscribed'))));
check($mc->unsubscribe('L1', 'a@example.com') === true, 'unsubscribe returns true on 2xx');
$req = mc_req();
check($req && $req->getMethod() === 'PATCH' && json_decode((string)$req->getBody(), true) === array('status' => 'unsubscribed'),
	'unsubscribe PATCHes status unsubscribed');

mc_mock(array(mc_json(404, array('title' => 'Resource Not Found'))));
check($mc->unsubscribe('L1', 'never@example.com') === true, 'an address never on the list (404) is already unsubscribed');

mc_mock(array(mc_json(401, array('title' => 'API Key Invalid'))));
$threw = false;
try { $mc->unsubscribe('L1', 'a@example.com'); } catch (MailingListProviderException $e) { $threw = true; }
check($threw, 'any other failure throws, instead of reporting success');

section('Reads throw on a failure instead of answering empty');

mc_mock(array(mc_json(401, array('title' => 'API Key Invalid', 'detail' => 'Your API key may be invalid'))));
$threw = false;
try { $mc->getLists(); } catch (MailingListProviderException $e) { $threw = true; }
check($threw, 'a 401 on getLists throws');

mc_mock(array(mc_json(500, array('title' => 'Internal'))));
$threw = false;
try { $mc->getSubscribers('L1'); } catch (MailingListProviderException $e) { $threw = $e->isRetryable(); }
check($threw, 'a 500 on getSubscribers throws a retryable failure');

mc_mock(array(mc_json(200, array('members' => array(
	array('email_address' => 'a@example.com', 'status' => 'cleaned', 'last_changed' => 'x'))))));
$page = $mc->getSubscribers('L1', '20', 50);
$q = mc_req() ? mc_req()->getUri()->getQuery() : '';
check(strpos($q, 'count=50') !== false && strpos($q, 'offset=20') !== false, 'subscribers page by count and offset', $q);
check(($page['subscribers'][0]['status'] ?? '') === 'bounced' && $page['next_cursor'] === null,
	'members map to the canonical statuses; a short page ends the walk');

section('Validation');

mc_mock(array(mc_json(401, array('title' => 'API Key Invalid'))));
$v = $mc->validateApiConnection();
check($v['success'] === false && $v['label'] === 'API Key Rejected', 'a 401 reads as API Key Rejected', json_encode($v));

mc_mock(array(mc_json(200, array('lists' => array(array('name' => 'News', 'stats' => array('member_count' => 2))), 'total_items' => 1))));
$v = $mc->validateApiConnection();
check($v['success'] === true && ($v['details']['News'] ?? '') === '2 members', 'a working key lists its lists', json_encode($v));
check(mc_req() && strpos(mc_req()->getUri()->getQuery(), 'count=10') !== false, 'validation asks /lists?count=10');

section('A failure with no response');

$cx = function () {
	return new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', 'https://us6.api.mailchimp.com/3.0/lists'), null, array('errno' => 7));
};
mc_mock(array($cx(), $cx(), $cx(), $cx()));
$caught = null;
try { $mc->getLists(); } catch (MailingListProviderException $e) { $caught = $e; }
check($caught !== null && $caught->isRetryable(), 'a transport failure throws a retryable MailingListProviderException',
	$caught ? $caught->getMessage() : 'nothing thrown');

section('MailingListSync: a retryable error retries the same page');

require_once(PathHelper::getIncludePath('plugins/email_lists/includes/MailingListSync.php'));

/** A provider whose getSubscribers answers from a script: an exception or a page. */
class McScriptedProvider {
	public $script; public $asked = array();
	public function __construct(array $script) { $this->script = $script; }
	public function getSubscribers($list, $cursor, $n) {
		$this->asked[] = $cursor;
		$next = array_shift($this->script);
		if ($next instanceof Throwable) { throw $next; }
		return $next;
	}
}
$waits = array();
MailingListSync::useTestSleep(function ($s) use (&$waits) { $waits[] = $s; });
harness_defer(function () { MailingListSync::useTestSleep(null); });
$retryable = new MailingListProviderException('MailChimp answered 503', true);
$page = function (array $emails, $next) {
	return array('subscribers' => array_map(function ($e) { return array('email' => $e); }, $emails), 'next_cursor' => $next);
};

$p = new McScriptedProvider(array($retryable, $page(array('a@x'), 'c2'), $retryable, $page(array('b@x'), null)));
$seen = array(); $lines = array();
$ok = MailingListSync::eachSubscriber($p, 'L1', true, $lines, function ($s) use (&$seen) { $seen[] = $s['email']; });
check($ok && $seen === array('a@x', 'b@x'), 'a retryable error on page one and later retries that page, and the list completes', json_encode($seen));
check($p->asked === array(null, null, 'c2', 'c2'), 'each retry asks the same page again', json_encode($p->asked));
check($waits === array(30, 30), 'the command line backs off before each retry');

$p = new McScriptedProvider(array($retryable, $retryable, $retryable, $retryable, $page(array('a@x'), null)));
$lines = array();
$ok = MailingListSync::eachSubscriber($p, 'L1', true, $lines, function ($s) {});
check($ok === false && count($p->asked) === 4, 'a list still failing after MAX_RETRIES is reported as not finished');

$waits = array();
$p = new McScriptedProvider(array($retryable, $page(array('a@x'), null)));
$lines = array();
$ok = MailingListSync::eachSubscriber($p, 'L1', false, $lines, function ($s) {});
check($ok === false && $waits === array() && count($p->asked) === 1,
	'a web request never waits: it gives up on the list at the first retryable error');

$p = new McScriptedProvider(array(new MailingListProviderException('MailChimp answered 401', false)));
$lines = array();
check(MailingListSync::eachSubscriber($p, 'L1', true, $lines, function ($s) {}) === false && count($p->asked) === 1,
	'a non-retryable error is not retried');

$sync_src = file_get_contents(PathHelper::getIncludePath('plugins/email_lists/includes/MailingListSync.php'));
check(strpos($sync_src, "\$event_log->set('evl_was_success', \$all_ok ? 1 : 0);") !== false,
	'a run with an unfinished list records evl_was_success = 0');

$src = file_get_contents(PathHelper::getIncludePath('plugins/email_lists/includes/mailing_list_providers/MailChimpProvider.php'));
check(strpos($src, 'MailchimpAPI') === false, 'the provider no longer uses the SDK');

harness_finish();
