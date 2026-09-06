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
 * The channel is the relay's own signed API: `POST /egress` on its public
 * listener, signed with the relay client identity and pinned to the relay's
 * identity (RelayClient). Nothing here adds a credential the relay could use to
 * act as this deployment.
 *
 * @version 1.2 - the ssh era is over: the tunnel path is gone
 * @version 1.1 - the relay API path (specs/relay_without_a_shell.md)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayClient.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class DirectRelayEgress {

	const EGRESS_PATH = '/egress';

	/** Headers the relay reads and answers with. */
	const TARGET_HEADER = 'X-Joinery-Direct-Target';
	const STATUS_HEADER = 'X-Joinery-Direct-Status';

	/** @var MailboxRelay */
	private $relay;
	/** @var int */
	private $timeout;

	private function __construct(MailboxRelay $relay, int $timeout) {
		$this->relay = $relay;
		$this->timeout = $timeout;
	}

	/**
	 * The egress client for this deployment, or null when sending should go out
	 * from the box directly.
	 *
	 * Null is the ordinary answer: only a relay-fronted deployment routes Direct
	 * this way, and only when the relay is enabled and carries an identity pin.
	 * A deployment with no relay has no origin to hide.
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
		if (!$relay->usesRelayApi() || trim((string)$relay->get('mrl_public_ip')) === '') {
			return null;
		}
		return new self($relay, $timeout);
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
}
