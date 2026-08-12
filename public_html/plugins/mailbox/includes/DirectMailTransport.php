<?php
/**
 * DirectMailTransport - mail's adapter onto Joinery Direct.
 *
 * This is the ONLY place the SMTP fallback exists. `JoineryDirect::send` returns
 * a typed result and never a behavior; what a result MEANS is the calling kind's
 * policy, and mail's policy is "anything short of delivered goes the way it went
 * before this feature existed":
 *
 *   no_capability  (the recipient is not a Joinery instance at all)  → SMTP
 *   declined       (not a contact)                                   → SMTP
 *   failed         (connection, timeout, verification)               → SMTP
 *
 * Direct Mail is therefore strictly additive. A message that cannot or should
 * not go direct goes exactly where it goes today, and no other kind's `declined`
 * or `failed` ever produces an SMTP send.
 *
 * Worst case is still the old email system — and under Fortress that is the MOST
 * sealed path, because SMTP there is the edge-sealing ingest relay. Falling back
 * never drops to a less-protected path; at worst it drops to a more-sealed one.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/JoineryDirect.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailDirectHandler.php'));

class DirectMailTransport {

	/**
	 * Try Direct for each of a message's recipients.
	 *
	 * Returns the addresses that were NOT delivered directly, so the caller
	 * continues its normal send for exactly those and never double-sends. An
	 * empty return means every recipient took the direct path.
	 *
	 * The message object is not mutated: deciding which recipients remain is the
	 * caller's job, because only the caller knows how its transport addresses
	 * them.
	 *
	 * @return array{remaining:string[],delivered:string[]}
	 */
	public static function attempt(EmailMessage $message): array {
		// Dedupe first, case-insensitively, keeping first-seen order. A message
		// listing the same address twice must reach it ONCE: without this, a
		// duplicate is either sent Direct twice, or sent Direct on one pass and
		// left in `remaining` for SMTP on another — a double delivery across the
		// two paths the split is meant to make invisible.
		$recipients = array();
		$seen = array();
		foreach (array_column((array)$message->getRecipients(), 'email') as $addr) {
			$key = strtolower(trim((string)$addr));
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$recipients[] = $addr;
		}
		$result = array('remaining' => $recipients, 'delivered' => array());

		if (!DirectSettings::enabled() || empty($recipients)) {
			return $result;
		}
		$sender = (string)$message->getFrom();
		$sender_domain = DirectProtocol::domainOf($sender);
		if ($sender_domain === '' || !DirectSigningIdentity::hasIdentity($sender_domain)) {
			// No signing identity means this deployment cannot speak Direct AS
			// this identity yet. One is minted when the domain's capability
			// record is planned, which is the deliberate act; a send does not
			// mint one, because a key nobody has published signs nothing anyone
			// can verify.
			return $result;
		}
		// Direct addresses one recipient at a time — consent is per-person, and
		// the receiver answers live for each. A cc/bcc list is not a group the
		// channel can address, so a message carrying one stays whole on SMTP
		// rather than being split across two paths with different header sets.
		if (!empty($message->getCc()) || !empty($message->getBcc())) {
			return $result;
		}

		try {
			$parts = MailDirectHandler::buildParts($message);
		} catch (\Throwable $e) {
			error_log('DirectMailTransport: could not build parts: ' . $e->getMessage());
			return $result;
		}

		$remaining = array();
		foreach ($recipients as $address) {
			$sent = JoineryDirect::send((string)$address, DirectProtocol::KIND_MAIL, $parts,
				array('sender' => $sender));
			if ($sent->delivered()) {
				$result['delivered'][] = $address;
				RequestLogger::log(DirectProtocol::LOG_FEATURE, 'delivered', true);
				continue;
			}
			// Every downgrade is counted, so an operator whose box has silently
			// stopped speaking Direct — a drifted clock, an expired record — can
			// see it rather than only noticing that nothing is marked verified.
			RequestLogger::log(DirectProtocol::LOG_FEATURE, 'downgrade:' . $sent->status, true);
			$remaining[] = $address;
		}
		$result['remaining'] = $remaining;
		return $result;
	}
}
