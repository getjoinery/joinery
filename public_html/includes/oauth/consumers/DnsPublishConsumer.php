<?php
/**
 * DnsPublishConsumer - performs one DNS publish with a grant that never
 * outlives the request that received it.
 *
 * The publish box starts a consent flow with purpose 'dns_publish', carrying the
 * plan and the operator's decisions in the flow payload (all public data — a
 * record set, never a secret). The shared /oauth_callback exchanges the code and
 * dispatches here, and this consumer does the whole write inside that one
 * request: build the driver from the access token, resolve the zone, reconcile,
 * apply per record, record ownership.
 *
 * **Nothing is persisted.** No token, no refresh token, no account id. That is
 * the point of doing the write here rather than storing a grant and writing
 * later: the credential exists only as a local variable, and when this method
 * returns it is gone. Providers that do issue refresh tokens (Google, Azure,
 * DigitalOcean, DNSimple) are treated exactly like Linode, which issues none.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Consumer.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));

class DnsPublishConsumer implements OAuth2Consumer {

	public static function getPurpose(): string {
		return DnsPublishBox::OAUTH_PURPOSE;
	}

	public function onTokenGranted(OAuth2Token $token, array $payload): string {
		$session = SessionControl::get_instance();
		$return_url = (string)($payload['return_url'] ?? '/');

		// The grant proves control of a DNS account, not of this site. Writing
		// someone's DNS is an admin act whatever the provider just said.
		if ((int)$session->get_permission() < 5) {
			return $return_url;
		}

		$driver_class = DnsDriverRegistry::get((string)($payload['driver'] ?? ''));
		if ($driver_class === null) {
			$this->flash($session, 'That DNS provider is no longer available on this deployment.', 'DNS publish');
			return $return_url;
		}

		$plan = DnsRecordPlan::fromArray((array)($payload['plan'] ?? array()));
		if ($plan->isEmpty()) {
			return $return_url;
		}

		$credential = array(
			'access_token' => $token->getAccessToken(),
			'account_id'   => (string)($payload['account_id'] ?? ''),
		);

		$publish = DnsPublishBox::publish(
			$driver_class,
			$credential,
			$plan,
			(array)($payload['decisions'] ?? array()),
			DnsReconciler::APPLY_CONFIRMED
		);

		// The only copies of anything write-capable, dropped before the redirect.
		unset($credential);

		if (!empty($publish['accounts'])) {
			// A grant reaching several accounts is resolved by asking, once, at
			// the moment the ambiguity appears — never by remembering a choice.
			DnsPublishBox::parkAccounts($publish['accounts']);
		}

		$this->flash($session, DnsPublishBox::summarizeResults($publish),
			'DNS publish via ' . $driver_class::getLabel());

		return DnsPublishBox::urlWith($return_url, array('dns_show' => '1',
			'dns_provider' => $driver_class::getKey()));
	}

	private function flash($session, string $message, string $title): void {
		$session->save_message(new DisplayMessage(
			$message, $title, '~.*~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
}
