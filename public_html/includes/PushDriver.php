<?php
/**
 * PushDriver — one transport that alerts a member outside the site.
 *
 * Each destination kind (PushDestination::kinds()) has one driver, and
 * PushSender picks it by the destination's kind. A driver registers a
 * destination (checks what a client sent and says what to store), sends to a
 * batch of them, and hears when one is retired. PushSender owns everything
 * around that: who gets what, the content rule, and the one cleanup path.
 *
 * See docs/push_notifications.md.
 *
 * @version 1.0.0
 */
interface PushDriver {

	/** Send outcomes: delivered; the destination is gone for good; it failed this time. */
	const RESULT_SENT   = 'sent';
	const RESULT_GONE   = 'gone';
	const RESULT_FAILED = 'failed';

	/** The destination kind this driver serves ('web', 'ios', 'android'). */
	public function kind(): string;

	/**
	 * Whether a message's readable text may travel through this transport. A
	 * driver that says no is always handed the generic text (PushSender::render).
	 */
	public function carriesText(): bool;

	/** Whether this driver can send on this site right now (its keys exist, and so on). */
	public function available(): bool;

	/**
	 * Check what a client sent to register a destination.
	 *
	 * @param array $input  the client's registration fields
	 * @return array  ['endpoint' => string, 'details' => array]
	 * @throws InvalidArgumentException naming what is wrong
	 */
	public function register(array $input): array;

	/**
	 * Send one message to each destination.
	 *
	 * @param array $deliveries  list of ['destination' => PushDestination, 'message' => array]
	 *                           where message is PushSender::render()'s output
	 * @return array  destination id => ['result' => RESULT_*, 'reason' => string]
	 */
	public function send(array $deliveries): array;

	/** A destination of this kind was retired (PushSender::retire). */
	public function retired(PushDestination $destination, string $reason): void;
}
