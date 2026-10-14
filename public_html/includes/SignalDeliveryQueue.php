<?php
/**
 * SignalDeliveryQueue — gets signals out of the request that raised them.
 *
 * Two halves, one table (sgd_signal_deliveries):
 *
 *   handle_signal()  A SignalBus subscriber on "*". For every active receiver
 *                    that wants the signal it writes one delivery row holding
 *                    the payload. That insert is the only work done in the
 *                    producing request, which may be a checkout.
 *
 *   drain()          Run by the SignalDeliveryDrain task every minute. Sends
 *                    what is due to webhook receivers as a signed POST through
 *                    SafeHttpClient, and on failure schedules the next attempt
 *                    from SignalDelivery::RETRY_DELAYS until the row is given up.
 *
 * Messages follow the Standard Webhooks format (standardwebhooks.com), so a
 * receiver can verify them with any of that project's libraries: headers
 * webhook-id, webhook-timestamp and webhook-signature ("v1," + base64 of
 * HMAC-SHA256 over "{id}.{timestamp}.{body}", keyed with the bytes of the
 * "whsec_" secret), and a body of {"type", "timestamp", "data"}.
 *
 * See docs/webhooks.md.
 *
 * @version 1.0
 */

class SignalDeliveryQueue {

	/** Stop starting new attempts after this long, so one run stays inside its minute. */
	const DRAIN_BUDGET_SECONDS = 45;

	/** Rows looked at per run; the rest wait for the next. */
	const DRAIN_BATCH = 500;

	/** Per-request cache: receiver id => signal names it wants. */
	private static $receivers_cache = null;

	/**
	 * SignalBus subscriber. Never sends anything: it writes rows and returns.
	 */
	public static function handle_signal($signal, array $payload) {
		$matched = array();
		foreach (self::active_receivers() as $receiver_id => $signals) {
			if (in_array(SignalReceiver::ALL_SIGNALS, $signals, true) || in_array($signal, $signals, true)) {
				$matched[] = $receiver_id;
			}
		}
		if (empty($matched)) {
			return;
		}

		// A request that has opened sealed content may not send anything long
		// out of the box, and a payload raised there could carry what it read.
		// The row is still written, empty, so the receiver's page shows that a
		// signal was held back rather than losing it without a trace.
		if (SealedEgressGuard::egressGated()) {
			$json = null;
			$status = SignalDelivery::STATUS_WITHHELD;
		} else {
			$json = self::encode_payload($signal, $payload);
			$status = SignalDelivery::STATUS_PENDING;
		}

		$message_id = SignalDelivery::new_message_id();
		foreach ($matched as $receiver_id) {
			self::insert_row($receiver_id, (string)$signal, $message_id, $json, $status);
		}
	}

	/**
	 * Send what is due to webhook receivers.
	 *
	 * @param object|null $client anything with post($url, $body, $headers)
	 *        returning a SafeHttpResponse; tests pass a stand-in. Null builds
	 *        the real guarded client.
	 * @return array counts: attempted, delivered, retrying, given_up, left
	 */
	public static function drain($client = null, int $budget_seconds = self::DRAIN_BUDGET_SECONDS): array {
		$client = $client ?: self::http_client();
		$started = microtime(true);
		$counts = array('attempted' => 0, 'delivered' => 0, 'retrying' => 0, 'given_up' => 0, 'left' => 0);

		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT d.sgd_signal_delivery_id
			   FROM sgd_signal_deliveries d
			   JOIN sgr_signal_receivers r ON r.sgr_signal_receiver_id = d.sgd_sgr_signal_receiver_id
			  WHERE d.sgd_status = 'pending'
			    AND d.sgd_next_attempt_time <= now()
			    AND r.sgr_kind = :kind
			    AND r.sgr_is_active = TRUE
			  ORDER BY d.sgd_signal_delivery_id
			  LIMIT " . (int)self::DRAIN_BATCH);
		$q->execute(array(':kind' => SignalReceiver::KIND_WEBHOOK));
		$ids = $q->fetchAll(PDO::FETCH_COLUMN);

		$receivers = array();
		// A receiver that could not be reached this run is not tried again
		// until the next, so one dead endpoint cannot spend the whole budget.
		$unreachable = array();

		foreach ($ids as $i => $id) {
			if (microtime(true) - $started > $budget_seconds) {
				$counts['left'] += count($ids) - $i;
				break;
			}
			$delivery = new SignalDelivery((int)$id, TRUE);
			if ($delivery->get('sgd_status') !== SignalDelivery::STATUS_PENDING) {
				continue;
			}
			$receiver_id = (int)$delivery->get('sgd_sgr_signal_receiver_id');
			if (isset($unreachable[$receiver_id])) {
				$counts['left']++;
				continue;
			}
			if (!isset($receivers[$receiver_id])) {
				$receivers[$receiver_id] = new SignalReceiver($receiver_id, TRUE);
			}

			$outcome = self::attempt($delivery, $receivers[$receiver_id], $client);
			$counts['attempted']++;
			$counts[$outcome['result']]++;
			if (!empty($outcome['unreachable'])) {
				$unreachable[$receiver_id] = true;
			}
		}

		return $counts;
	}

	/**
	 * Send the receiver a test message now and report what happened. The
	 * message is an ordinary delivery row, so a failure is retried like any
	 * other and shows in the receiver's delivery list.
	 *
	 * @return array the attempt outcome (see attempt())
	 */
	public static function send_test(SignalReceiver $receiver, $client = null): array {
		$json = self::encode_payload(SignalDelivery::TEST_SIGNAL, array(
			'receiver_id'   => (int)$receiver->key,
			'receiver_name' => (string)$receiver->get('sgr_name'),
		));
		$id = self::insert_row((int)$receiver->key, SignalDelivery::TEST_SIGNAL,
			SignalDelivery::new_message_id(), $json, SignalDelivery::STATUS_PENDING);
		$delivery = new SignalDelivery($id, TRUE);
		return self::attempt($delivery, $receiver, $client ?: self::http_client());
	}

	/**
	 * One attempt at one delivery, recorded on its row.
	 *
	 * @return array result (delivered|retrying|given_up), message, unreachable
	 */
	public static function attempt(SignalDelivery $delivery, SignalReceiver $receiver, $client): array {
		$code = null;
		$error = null;
		$unreachable = false;

		$secret = $receiver->signing_secret();
		$url_problem = SignalReceiver::url_problem((string)$receiver->get('sgr_url'));
		if ($url_problem !== null) {
			$error = $url_problem;
			$unreachable = true;
		} elseif ($secret === null) {
			$error = 'The signing secret can no longer be read. Make a new one on this webhook\'s page.';
			$unreachable = true;
		} else {
			$body = self::message_body($delivery);
			$timestamp = time();
			$message_id = (string)$delivery->get('sgd_message_id');
			$headers = array(
				'Content-Type'      => 'application/json',
				'webhook-id'        => $message_id,
				'webhook-timestamp' => (string)$timestamp,
				'webhook-signature' => self::sign($secret, $message_id, $timestamp, $body),
			);
			try {
				$response = $client->post((string)$receiver->get('sgr_url'), $body, $headers);
				$code = (int)$response->status;
				if (!$response->isSuccess()) {
					$error = 'Answered HTTP ' . $code . self::body_excerpt((string)$response->body);
				}
			} catch (UnsafeUrlException $e) {
				$error = 'Refused to send there: ' . $e->getMessage();
				$unreachable = true;
			} catch (Throwable $e) {
				$error = 'Could not reach it: ' . $e->getMessage();
				$unreachable = true;
			}
		}

		$attempts = (int)$delivery->get('sgd_attempts') + 1;
		$columns = array(
			'sgd_attempts'           => $attempts,
			'sgd_last_attempt_time'  => gmdate('Y-m-d H:i:s'),
			'sgd_last_response_code' => $code,
			'sgd_last_error'         => $error === null ? null : mb_substr($error, 0, 500),
		);

		if ($error === null) {
			$columns['sgd_status'] = SignalDelivery::STATUS_DELIVERED;
			$columns['sgd_delivered_time'] = gmdate('Y-m-d H:i:s');
			$columns['sgd_next_attempt_time'] = null;
			$result = 'delivered';
			$message = 'Delivered (HTTP ' . $code . ').';
		} else {
			$delay = SignalDelivery::retry_delay($attempts);
			if ($delay === null) {
				$columns['sgd_status'] = SignalDelivery::STATUS_FAILED;
				$columns['sgd_next_attempt_time'] = null;
				$result = 'given_up';
				$message = $error . ' Gave up after ' . $attempts . ' attempts.';
			} else {
				$columns['sgd_next_attempt_time'] = gmdate('Y-m-d H:i:s', time() + $delay);
				$result = 'retrying';
				$message = $error . ' It will be tried again.';
			}
		}

		SignalDelivery::updateColumns((int)$delivery->key, $columns);
		return array('result' => $result, 'message' => $message, 'unreachable' => $unreachable);
	}

	/**
	 * The Standard Webhooks signature. A "whsec_" secret is keyed with the
	 * bytes its base64 part decodes to, as that project's libraries do.
	 */
	public static function sign(string $secret, string $message_id, int $timestamp, string $body): string {
		$key = $secret;
		if (strpos($secret, SignalReceiver::SECRET_PREFIX) === 0) {
			$decoded = base64_decode(substr($secret, strlen(SignalReceiver::SECRET_PREFIX)), true);
			if ($decoded !== false) {
				$key = $decoded;
			}
		}
		$mac = hash_hmac('sha256', $message_id . '.' . $timestamp . '.' . $body, $key, true);
		return 'v1,' . base64_encode($mac);
	}

	/** The POST body: the signal name, when it happened, and its payload. */
	public static function message_body(SignalDelivery $delivery): string {
		$payload = $delivery->get_json_decoded('sgd_payload');
		$created = strtotime((string)$delivery->get('sgd_create_time') . ' UTC');
		return json_encode(array(
			'type'      => (string)$delivery->get('sgd_signal'),
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $created ?: time()),
			'data'      => is_array($payload) && !empty($payload) ? $payload : new stdClass(),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/** Forget the cached receiver list; call after receivers change in this request. */
	public static function reset_cache(): void {
		self::$receivers_cache = null;
	}

	/** @return array<int,string[]> active receiver id => the signal names it wants */
	private static function active_receivers(): array {
		if (self::$receivers_cache !== null) {
			return self::$receivers_cache;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->query('SELECT sgr_signal_receiver_id, sgr_signals FROM sgr_signal_receivers WHERE sgr_is_active = TRUE');
		$receivers = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$signals = json_decode((string)$row['sgr_signals'], true);
			$receivers[(int)$row['sgr_signal_receiver_id']] = is_array($signals) ? $signals : array();
		}
		self::$receivers_cache = $receivers;
		return $receivers;
	}

	private static function insert_row(int $receiver_id, string $signal, string $message_id, ?string $json, string $status): int {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			'INSERT INTO sgd_signal_deliveries
				(sgd_sgr_signal_receiver_id, sgd_signal, sgd_message_id, sgd_payload, sgd_status,
				 sgd_attempts, sgd_next_attempt_time, sgd_create_time)
			 VALUES (:receiver, :signal, :message_id, CAST(:payload AS jsonb), :status, 0, :next, now())
			 RETURNING sgd_signal_delivery_id');
		$q->execute(array(
			':receiver'   => $receiver_id,
			':signal'     => $signal,
			':message_id' => $message_id,
			':payload'    => $json,
			':status'     => $status,
			':next'       => $status === SignalDelivery::STATUS_PENDING ? gmdate('Y-m-d H:i:s') : null,
		));
		return (int)$q->fetchColumn();
	}

	private static function encode_payload(string $signal, array $payload): string {
		$json = json_encode($payload ?: new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			// The bus contract is JSON-safe scalars; a producer that broke it
			// still gets its message out, with the offending values nulled.
			error_log('[SignalDeliveryQueue] payload for ' . $signal . ' is not JSON-safe: ' . json_last_error_msg());
			$json = json_encode($payload ?: new stdClass(),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
		}
		return (string)$json;
	}

	private static function http_client(): SafeHttpClient {
		return new SafeHttpClient(array(
			// 443, or any unprivileged port for a self-hosted receiver; never
			// the SSH/SMTP/DNS-class ports below 1024.
			'allowed_ports'      => array('allow' => array(443), 'min' => 1024),
			'allow_redirects'    => false,
			'connect_timeout'    => 5,
			'timeout'            => 10,
			'max_response_bytes' => 65536,
			'user_agent'         => 'Joinery-Webhooks/1.0',
		));
	}

	/** A short hint at what the receiver said: an HTML error page's title, or the start of the body. */
	private static function body_excerpt(string $body): string {
		if (preg_match('~<title[^>]*>(.*?)</title>~is', $body, $m)) {
			$body = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
		} else {
			$body = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $body);
		}
		$text = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
		if ($text === '') {
			return '.';
		}
		return ': ' . mb_substr($text, 0, 200);
	}
}
