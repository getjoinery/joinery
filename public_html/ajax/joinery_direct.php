<?php
/**
 * The Joinery Direct endpoint — `/.well-known/joinery-direct`.
 *
 * This is a federation protocol endpoint, not a browser AJAX endpoint: its
 * callers are other Joinery instances, authenticated by an Ed25519 instance
 * signature rather than by a session. It is built in the shape of the existing
 * inbound email webhook — a route and a handler, not a long-running service —
 * which is what lets Direct work on any deployment that already terminates TLS
 * for its own web traffic: no second TLS stack, no additional certificate, no
 * firewall change, and no port to bind. Shared 443 is the floor that works
 * everywhere, not the ceiling; because the port is ADVERTISED in SRV rather than
 * assumed, a deployment that can bind one may run a dedicated listener later and
 * senders follow the record with no coordination.
 *
 * Three steps arrive here:
 *
 *   ?step=preflight  JSON envelope + manifest + signature, no content
 *   ?step=part       one part's raw bytes, so no single request carries the
 *                    whole message and `post_max_size` never bounds a delivery
 *   ?step=commit     the ordered sealed-byte hashes and the signature over them
 *
 * Every refusal that is not one of the gate's two answers is an HTTP status, and
 * they are deliberately uninformative to the sender: a refusal from the
 * recipient, a WAF, a proxy and a dead host all mean the same thing to a
 * correctly written client, which is to fall back.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectReceiver.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));

/** One JSON answer and nothing else — no session, no cookies, no HTML. */
function joinery_direct_answer(int $status, array $body): void {
	http_response_code($status);
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode($body);
	exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	joinery_direct_answer(405, array('error' => 'Method Not Allowed'));
}

if (!DirectSettings::enabled()) {
	// Indistinguishable from a deployment that never heard of Direct.
	joinery_direct_answer(404, array('error' => 'Not found'));
}

$step = isset($_GET['step']) ? strtolower(trim((string)$_GET['step'])) : '';
$receiver = new DirectReceiver();

try {
	if ($step === 'preflight') {
		$payload = json_decode((string)file_get_contents('php://input'), true);
		if (!is_array($payload)) {
			joinery_direct_answer(400, array('error' => 'Malformed preflight'));
		}
		$answer = $receiver->preflight(
			is_array($payload['envelope'] ?? null) ? $payload['envelope'] : array(),
			is_array($payload['manifest'] ?? null) ? $payload['manifest'] : array(),
			array('signature' => (string)($payload['signature'] ?? ''))
		);

		if ($answer['answer'] === 'refused') {
			// The STATUS is request-level and may be signalled (see DirectProtocol:
			// a refusal is an HTTP status, never a gate answer, so it never discloses
			// a recipient's existence or contacts). The internal reason string must
			// NOT reach the wire: to a correct client every refusal means one thing —
			// fall back — and a Joinery-specific reason would only fingerprint the box
			// and name the exact stage it tripped. It is logged; the body stays as
			// generic as an unaware deployment's 404.
			$status = (int)($answer['status'] ?? 400);
			if (!empty($answer['error'])) {
				error_log('joinery_direct preflight refused (' . $status . '): ' . (string)$answer['error']);
			}
			joinery_direct_answer($status, array('error' => 'Refused'));
		}
		unset($answer['status'], $answer['error']);
		joinery_direct_answer(200, $answer);
	}

	if ($step === 'part') {
		$nonce = (string)($_GET['nonce'] ?? '');
		$index = (int)($_GET['index'] ?? -1);
		$bytes = (string)file_get_contents('php://input');
		if ($index < 0 || !$receiver->acceptPart($nonce, $index, $bytes)) {
			joinery_direct_answer(409, array('error' => 'Part refused'));
		}
		joinery_direct_answer(200, array('ok' => true));
	}

	if ($step === 'commit') {
		$payload = json_decode((string)file_get_contents('php://input'), true);
		if (!is_array($payload)) {
			joinery_direct_answer(400, array('error' => 'Malformed commit'));
		}
		$committed = $receiver->commit(
			(string)($payload['nonce'] ?? ''),
			is_array($payload['hashes'] ?? null) ? $payload['hashes'] : array(),
			!empty($payload['sealed']),
			(int)($payload['key_generation'] ?? 0),
			array('signature' => (string)($payload['signature'] ?? ''))
		);
		if (!$committed) {
			joinery_direct_answer(409, array('error' => 'Commit refused'));
		}
		joinery_direct_answer(200, array('ok' => true));
	}
} catch (\Throwable $e) {
	// Never let an internal failure become a distinguishable answer: it is a
	// temporary failure, the same thing an overloaded box says.
	error_log('joinery_direct endpoint: ' . $e->getMessage());
	joinery_direct_answer(503, array('error' => 'Temporary failure'));
}

joinery_direct_answer(400, array('error' => 'Unknown step'));
