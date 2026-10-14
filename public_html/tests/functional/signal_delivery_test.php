<?php
/** @joinery-test
 * name: signal_delivery
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * Outgoing webhooks: a signal is queued for each receiver that wants it, and
 * the drain sends it signed, retries it, and gives it up.
 *
 * What is pinned here:
 *  - the signature is the Standard Webhooks one (its published test vector),
 *    so a receiver can verify with that project's libraries;
 *  - SignalBus delivers to the queue: a dispatch writes one row per active
 *    receiver that wants the signal, all with the same message id, and none
 *    for a paused receiver or one that wants other signals;
 *  - a signal raised while sealed content is in play is written as withheld,
 *    with no payload;
 *  - the drain sends a signed {type, timestamp, data} body, marks a 2xx
 *    delivered, schedules a retry on failure, and gives up after the last
 *    attempt; a given-up row can be sent again;
 *  - a receiver that cannot be reached is not tried again in the same run,
 *    and one whose secret cannot be opened is never sent unsigned;
 *  - a URL that is not https is refused when saved and when sent;
 *  - deleting a receiver deletes its deliveries.
 *
 * The HTTP client is a stand-in: SafeHttpClient refuses every address a
 * local test server could listen on, which is the point of it.
 *
 * Run: php tests/run.php test-db --filter=signal_delivery
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

/** Records every request; answers with a status chosen per URL, or throws. */
class SignalDeliveryTestClient {
	public $calls = array();
	public $status = array();
	public $throw = array();

	public function post(string $url, string $body, array $headers = array()): SafeHttpResponse {
		$this->calls[] = array('url' => $url, 'body' => $body, 'headers' => $headers);
		if (isset($this->throw[$url])) {
			throw new SafeHttpException($this->throw[$url]);
		}
		return new SafeHttpResponse($this->status[$url] ?? 200, array(), 'ok', $url);
	}

	public function callsTo(string $url): array {
		return array_values(array_filter($this->calls, function ($c) use ($url) { return $c['url'] === $url; }));
	}
}

$db = DbConnector::get_instance()->get_db_link();
$token = LibraryFunctions::random_string(6);

$make_receiver = function (string $label, array $signals, bool $active = true) use ($token): SignalReceiver {
	$r = new SignalReceiver(NULL);
	$r->set('sgr_kind', SignalReceiver::KIND_WEBHOOK);
	$r->set('sgr_name', 'HarnessTest ' . $label . ' ' . $token);
	$r->set('sgr_url', 'https://hooks.example.com/' . $label . '-' . $token);
	$r->set('sgr_signals', $signals);
	$r->set('sgr_is_active', $active);
	$r->prepare();
	$r->new_signing_secret();
	$r->save();
	$r->load();
	harness_register_row('sgr_signal_receivers', 'sgr_signal_receiver_id', (int)$r->key);
	return $r;
};

$rows_for = function (SignalReceiver $r) use ($db): array {
	$q = $db->prepare('SELECT * FROM sgd_signal_deliveries WHERE sgd_sgr_signal_receiver_id = ? ORDER BY sgd_signal_delivery_id');
	$q->execute(array((int)$r->key));
	return $q->fetchAll(PDO::FETCH_ASSOC);
};

// Rows due now, so the drain sees them without waiting out a retry delay.
$make_due = function (SignalReceiver $r) use ($db): void {
	$db->prepare("UPDATE sgd_signal_deliveries SET sgd_next_attempt_time = now() - interval '1 second'
		WHERE sgd_sgr_signal_receiver_id = ? AND sgd_status = 'pending'")->execute(array((int)$r->key));
};

// Only this run's receivers: pause every other active webhook in the copy so
// the drain cannot reach a stray row (the stand-in client would "deliver" it).
$others = $db->query("SELECT sgr_signal_receiver_id FROM sgr_signal_receivers WHERE sgr_is_active = TRUE")->fetchAll(PDO::FETCH_COLUMN);
if ($others) {
	$db->exec('UPDATE sgr_signal_receivers SET sgr_is_active = FALSE WHERE sgr_signal_receiver_id IN (' . implode(',', array_map('intval', $others)) . ')');
	harness_defer(function () use ($db, $others) {
		$db->exec('UPDATE sgr_signal_receivers SET sgr_is_active = TRUE WHERE sgr_signal_receiver_id IN (' . implode(',', array_map('intval', $others)) . ')');
	});
}

try {
	section('Signature');
	// The Standard Webhooks project's published vector.
	$sig = SignalDeliveryQueue::sign('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
		'msg_p5jXN8AQM9LWM0D4loKWxJek', 1614265330, '{"test": 2432232314}');
	check($sig === 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', 'signature matches the Standard Webhooks test vector', $sig);

	section('Saving a receiver');
	foreach (array('http://hooks.example.com/x' => 'https://', 'https://user:pw@hooks.example.com/x' => 'credentials', '' => 'needs a URL') as $url => $expect) {
		$r = new SignalReceiver(NULL);
		$r->set('sgr_name', 'HarnessTest refused ' . $token);
		$r->set('sgr_url', $url);
		$r->set('sgr_signals', array('*'));
		$refused = '';
		try {
			$r->prepare();
		} catch (DisplayableErrorMessage $e) {
			$refused = $e->getMessage();
		}
		check(stripos($refused, $expect) !== false, "'" . $url . "' is refused", $refused);
	}
	$r = new SignalReceiver(NULL);
	$r->set('sgr_name', 'HarnessTest no signals ' . $token);
	$r->set('sgr_url', 'https://hooks.example.com/none');
	$r->set('sgr_signals', array());
	$refused = '';
	try { $r->prepare(); } catch (DisplayableErrorMessage $e) { $refused = $e->getMessage(); }
	check($refused !== '', 'a receiver with no signals is refused', $refused);

	$a = $make_receiver('a', array('account.signup'));
	$b = $make_receiver('b', array('*'));
	$c = $make_receiver('c', array('*'), false);
	check(SecretBox::looksEncrypted((string)$a->get('sgr_signing_secret')), 'the signing secret is stored sealed');
	check(strpos((string)$a->signing_secret(), SignalReceiver::SECRET_PREFIX) === 0, 'the secret opens to a whsec_ value');

	section('Queueing from the bus');
	SignalDeliveryQueue::reset_cache();
	$payload = array('user_id' => 991, 'email' => 'someone@example.com', 'display_name' => 'Some One', 'source_user_id' => 991);
	SignalBus::dispatch('account.signup', $payload);
	$ra = $rows_for($a);
	$rb = $rows_for($b);
	check(count($ra) === 1 && count($rb) === 1, 'one row for each active receiver that wants the signal', count($ra) . '/' . count($rb));
	check(count($rows_for($c)) === 0, 'no row for a paused receiver');
	check($ra[0]['sgd_message_id'] === $rb[0]['sgd_message_id'], 'both rows carry the same message id');
	check(json_decode($ra[0]['sgd_payload'], true) == $payload, 'the payload is stored as dispatched');
	check($ra[0]['sgd_status'] === 'pending' && $ra[0]['sgd_next_attempt_time'] !== null, 'the row is pending and due');

	SignalBus::dispatch('comment.posted', array('comment_id' => 5, 'post_id' => 6));
	check(count($rows_for($a)) === 1, 'a receiver of other signals gets no row');
	check(count($rows_for($b)) === 2, 'a receiver of every signal gets one');

	SealedEgressGuard::restrictEgress('signal_delivery_test');
	SignalBus::dispatch('comment.posted', array('comment_id' => 7, 'post_id' => 8));
	SealedEgressGuard::reset();
	$rb = $rows_for($b);
	$withheld = end($rb);
	check($withheld['sgd_status'] === 'withheld' && $withheld['sgd_payload'] === null,
		'a signal raised with sealed content in play is withheld, with no payload', $withheld['sgd_status']);

	section('Draining');
	$client = new SignalDeliveryTestClient();
	$client->status[(string)$b->get('sgr_url')] = 500;
	$counts = SignalDeliveryQueue::drain($client);
	check($counts['delivered'] === 1 && $counts['retrying'] === 2, 'one delivered, two failed and retrying', json_encode($counts));

	$ra = $rows_for($a);
	check($ra[0]['sgd_status'] === 'delivered' && (int)$ra[0]['sgd_attempts'] === 1 && (int)$ra[0]['sgd_last_response_code'] === 200,
		'the 2xx row is delivered after one attempt');

	$sent = $client->callsTo((string)$a->get('sgr_url'));
	check(count($sent) === 1, 'one request went to receiver a');
	$h = $sent[0]['headers'];
	check($h['webhook-id'] === $ra[0]['sgd_message_id'], 'webhook-id is the message id');
	check(abs((int)$h['webhook-timestamp'] - time()) < 60, 'webhook-timestamp is the send time');
	// Recomputed here from the documented rule, not through sign().
	$key = base64_decode(substr((string)$a->signing_secret(), strlen('whsec_')));
	$expected = 'v1,' . base64_encode(hash_hmac('sha256', $h['webhook-id'] . '.' . $h['webhook-timestamp'] . '.' . $sent[0]['body'], $key, true));
	check(hash_equals($expected, $h['webhook-signature']), 'webhook-signature verifies with the receiver\'s secret');
	$body = json_decode($sent[0]['body'], true);
	check($body['type'] === 'account.signup' && $body['data'] == $payload && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $body['timestamp']),
		'the body is {type, timestamp, data}', $sent[0]['body']);

	$rb = $rows_for($b);
	$failed = $rb[0];
	check($failed['sgd_status'] === 'pending' && (int)$failed['sgd_attempts'] === 1 && (int)$failed['sgd_last_response_code'] === 500,
		'the failed row is still pending after one attempt');
	check(strpos((string)$failed['sgd_last_error'], 'HTTP 500') !== false, 'its error names the status', (string)$failed['sgd_last_error']);
	$wait = strtotime($failed['sgd_next_attempt_time'] . ' UTC') - time();
	check($wait > 30 && $wait <= 60, 'its next attempt is about a minute away', (string)$wait);
	check(count($client->callsTo((string)$b->get('sgr_url'))) === 2, 'the withheld row was never sent');

	// The last attempt: given up.
	$db->prepare('UPDATE sgd_signal_deliveries SET sgd_attempts = ? WHERE sgd_signal_delivery_id = ?')
		->execute(array(SignalDelivery::max_attempts() - 1, (int)$failed['sgd_signal_delivery_id']));
	$make_due($b);
	SignalDeliveryQueue::drain($client);
	$row = new SignalDelivery((int)$failed['sgd_signal_delivery_id'], TRUE);
	check($row->get('sgd_status') === SignalDelivery::STATUS_FAILED && (int)$row->get('sgd_attempts') === SignalDelivery::max_attempts()
		&& $row->get('sgd_next_attempt_time') === null, 'after the last attempt the row is given up');

	$row->retry_now();
	$row->load();
	check($row->get('sgd_status') === SignalDelivery::STATUS_PENDING && (int)$row->get('sgd_attempts') === 0, 'a given-up row can be sent again');

	section('A receiver that cannot be reached');
	$client = new SignalDeliveryTestClient();
	$client->throw[(string)$b->get('sgr_url')] = 'connection timed out';
	$make_due($b);
	$counts = SignalDeliveryQueue::drain($client);
	check(count($client->calls) === 1, 'it is tried once in a run, not once per row', (string)count($client->calls));
	check($counts['left'] >= 1, 'its other rows wait for the next run', json_encode($counts));

	section('A secret that cannot be opened');
	SignalReceiver::updateColumns((int)$b->key, array('sgr_signing_secret' => 'v1.sodium.AAAA.BBBB'));
	$b->load();
	$client = new SignalDeliveryTestClient();
	$make_due($b);
	SignalDeliveryQueue::drain($client);
	check(count($client->calls) === 0, 'nothing is sent unsigned');
	$rb = $rows_for($b);
	$errors = array_filter(array_column($rb, 'sgd_last_error'), function ($e) { return strpos((string)$e, 'signing secret') !== false; });
	check(count($errors) >= 1, 'the row says the secret cannot be read');

	section('A URL that is not https');
	$b->new_signing_secret();
	$b->save();
	SignalReceiver::updateColumns((int)$b->key, array('sgr_url' => 'http://hooks.example.com/plain-' . $token));
	$b->load();
	$client = new SignalDeliveryTestClient();
	$make_due($b);
	SignalDeliveryQueue::drain($client);
	check(count($client->calls) === 0, 'nothing is sent over plain http');

	section('Deleting a receiver');
	$b_id = (int)$b->key;
	$b->permanent_delete();
	$left = $db->prepare('SELECT COUNT(*) FROM sgd_signal_deliveries WHERE sgd_sgr_signal_receiver_id = ?');
	$left->execute(array($b_id));
	check((int)$left->fetchColumn() === 0, 'its deliveries are deleted with it');

} catch (Throwable $e) {
	check(false, 'suite ran to completion', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

SignalDeliveryQueue::reset_cache();
harness_finish();
