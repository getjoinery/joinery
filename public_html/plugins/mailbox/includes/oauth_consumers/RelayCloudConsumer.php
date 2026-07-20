<?php
/**
 * RelayCloudConsumer - receives the OAuth2 grant for a relay cloud act
 * (specs/mailbox_relay_cloud_provisioning.md).
 *
 * The one-click branch of the just-in-time credential step: when a Linode
 * OAuth client is configured, the Setup tab's Relay section offers "Approve
 * at Linode" (action relay_cloud_connect -> OAuth2Client::beginConsent(...,
 * 'relay_cloud', ['run_id' => N], ...)) instead of the token-paste floor.
 * The shared /oauth_callback exchanges the code and dispatches here.
 *
 * Grant-per-act custody: no account link is created and no refresh token is
 * kept — the access token is SecretBox-sealed onto the run row, used for this
 * one act, and erased at the run's terminal state. A later act (retry,
 * destroy) starts a fresh consent.
 *
 * Discovered by interface from this plugin's includes/oauth_consumers/.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Consumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));

class RelayCloudConsumer implements OAuth2Consumer {

	const RETURN_URL = '/plugins/mailbox/admin/admin_mailbox_setup';

	public static function getPurpose(): string {
		return 'relay_cloud';
	}

	public function onTokenGranted(OAuth2Token $token, array $payload): string {
		$run_id = intval($payload['run_id'] ?? 0);
		if ($run_id <= 0) {
			return self::RETURN_URL;
		}
		try {
			$run = new RelayCloudProvision($run_id, TRUE);
		} catch (\Throwable $e) {
			return self::RETURN_URL;
		}
		if ((string)$run->get('rcp_status') !== 'awaiting_grant') {
			return self::RETURN_URL; // stale callback — never rewind a live run
		}
		$run->sealToken($token->getAccessToken());
		$run->set('rcp_status', 'ready');
		$run->set('rcp_error', null);
		$run->save();
		return self::RETURN_URL;
	}
}
?>
