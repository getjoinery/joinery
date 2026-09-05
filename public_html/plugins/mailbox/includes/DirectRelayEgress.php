<?php
/**
 * DirectRelayEgress - sending Joinery Direct from behind a relay.
 *
 * A Fortress deployment hides its box's address. If the box opened the
 * connection itself, the recipient's instance would see that address in its own
 * access log — which is precisely what the relay exists to prevent. So the box
 * builds and SIGNS the whole request, and the relay makes it: the recipient sees
 * the relay's address and never the box's.
 *
 * **The box signs; the relay only transports.** The instance signing key never
 * leaves the box, exactly as `OutboundTransport` already keeps DKIM signing on
 * the box while the relay carries the message. Moving the key to the relay would
 * be a new custody model — a relay that could sign as its tenant is a much
 * stronger position than a relay that can only forward — and this design
 * deliberately refuses it.
 *
 * The channel is whatever the relay's pull uses. A relay with an identity pin
 * takes `POST /egress` on its public listener, signed with the relay client
 * identity and pinned to the relay's key (RelayClient); a tunnel relay takes it
 * on its WireGuard address, where reaching the address is the authentication.
 * Neither adds a credential the relay could use to act as this deployment.
 *
 * @version 1.1 - the relay API path (specs/relay_without_a_shell.md)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayClient.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class DirectRelayEgress {

	/** The relay's egress listener, on the tunnel address only. */
	const EGRESS_PORT = 8442;
	const EGRESS_PATH = '/egress';

	/** Headers the relay reads and answers with. */
	const TARGET_HEADER = 'X-Joinery-Direct-Target';
	const STATUS_HEADER = 'X-Joinery-Direct-Status';

	/** @var string The relay's tunnel address ('' on a relay reached over its API). */
	private $tunnel;
	/** @var MailboxRelay|null the relay, when it is reached over its API */
	private $relay;
	/** @var int */
	private $timeout;

	private function __construct(string $tunnel, ?MailboxRelay $relay, int $timeout) {
		$this->tunnel = $tunnel;
		$this->relay = $relay;
		$this->timeout = $timeout;
	}

	/**
	 * The egress client for this deployment, or null when sending should go out
	 * from the box directly.
	 *
	 * Null is the ordinary answer: only a relay-fronted deployment routes Direct
	 * this way, and only when the relay is actually enabled and reachable on the
	 * tunnel. A deployment with no relay has no origin to hide.
	 */
	public static function forDeployment(int $timeout = 30): ?DirectRelayEgress {
		try {
			$relay = MailboxRelay::active();
		} catch (\Throwable $e) {
			return null;
		}
		if ($relay === null || !$relay->get('mrl_is_enabled')) {
			return null;
		}
		if ($relay->usesRelayApi()) {
			if (trim((string)$relay->get('mrl_public_ip')) === '') {
				return null;
			}
			return new self('', $relay, $timeout);
		}
		$tunnel = trim((string)$relay->get('mrl_wg_ip'));
		if ($tunnel === '') {
			return null;
		}
		return new self($tunnel, null, $timeout);
	}

	/**
	 * Send one already-signed Direct request through the relay.
	 *
	 * The body is passed through as raw bytes rather than wrapped in JSON: a
	 * part transfer IS the attachment, and re-encoding it here would reintroduce
	 * exactly the base64 inflation that transferring parts separately exists to
	 * avoid.
	 *
	 * @param string $url          the recipient instance's endpoint, with its query
	 * @param string $body         the request body, verbatim
	 * @param string $content_type
	 * @return array{status:int,body:string}|null null when the relay could not be reached
	 */
	public function post(string $url, string $body, string $content_type): ?array {
		if ($this->relay !== null) {
			try {
				return $this->relay->withApi(function (RelayClient $c) use ($url, $body, $content_type) {
					return $c->egress($url, $body, $content_type);
				});
			} catch (\Throwable $e) {
				// Unreachable, refused, or the signature was refused: one answer to
				// the caller, which takes the other path.
				return null;
			}
		}
		$endpoint = 'http://' . $this->tunnel . ':' . self::EGRESS_PORT . self::EGRESS_PATH;

		$ch = curl_init();
		if ($ch === false) {
			return null;
		}
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $endpoint,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HTTPHEADER     => array(
				self::TARGET_HEADER . ': ' . $url,
				'Content-Type: ' . $content_type,
			),
		));
		$raw = curl_exec($ch);
		$header_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$errno = curl_errno($ch);
		curl_close($ch);

		if ($raw === false || $errno !== 0) {
			// The tunnel is down, or the relay is not answering. Indistinguishable
			// from any other Direct failure to the caller, which is what its
			// fallback wants.
			return null;
		}

		$headers = substr((string)$raw, 0, $header_size);
		$answer  = substr((string)$raw, $header_size);

		// The relay echoes the UPSTREAM's status rather than interpreting it, so
		// a `declined` from the recipient stays a declined and does not read as a
		// transport failure.
		$status = 0;
		if (preg_match('/^' . preg_quote(self::STATUS_HEADER, '/') . ':\s*(\d+)/mi', $headers, $m)) {
			$status = (int)$m[1];
		}
		if ($status === 0) {
			return null;
		}
		return array('status' => $status, 'body' => $answer);
	}
}
