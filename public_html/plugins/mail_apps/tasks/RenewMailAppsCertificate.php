<?php
/**
 * RenewMailAppsCertificate - Scheduled Task (daily).
 *
 * Through a relay, the mail gateway's certificate is for the relay's name and
 * is issued through the relay (MailAppGateway::obtainRelayCertificate), so
 * certbot on this server never renews it. This does, inside 30 days of expiry
 * (MailAppGateway::RELAY_CERT_RENEW_DAYS); the helper restarts the gateway onto
 * the new one. The relay issues at most one every twelve hours for its name, so a
 * failed attempt is tried again by the next day's run.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class RenewMailAppsCertificate implements ScheduledTaskInterface {

	public function run(array $config) {
		$result = MailAppGateway::renewRelayCertificate();
		return array('status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']);
	}
}
?>
