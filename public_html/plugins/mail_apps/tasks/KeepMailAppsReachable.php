<?php
/**
 * KeepMailAppsReachable - Scheduled Task (hourly).
 *
 * Keeps mail programs able to connect while mail apps are on
 * (MailAppGateway::keepReachable(), specs/mail_apps_plugin.md § Which route a
 * deployment uses):
 *  - through a relay whose address changed (re-provisioned, or moved to
 *    another shard), the firewall and the gateway's trusted list follow it;
 *  - through a relay that is gone, mail apps are turned on by whatever the
 *    rule picks now, or off, and the admin page says so;
 *  - a certificate obtained through the relay is renewed inside 30 days of
 *    expiry (certbot never renews it). The relay issues at most one every
 *    twelve hours for its name, so a failed attempt is tried again later.
 * A working route is never changed: a better one is the admin's Switch.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class KeepMailAppsReachable implements ScheduledTaskInterface {

	public function run(array $config) {
		$result = MailAppGateway::keepReachable();
		return array('status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']);
	}
}
?>
