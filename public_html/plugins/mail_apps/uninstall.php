<?php
/**
 * Uninstall hook for mail_apps (PluginManager::uninstall() runs it before the
 * plugin's tables are dropped).
 *
 * Takes the mail gateway off this machine: the root helper's remove verb
 * forgets this site and, once no site on the machine has the plugin, removes
 * the unit, the binary, the joinery-mailgw user, the renewal hook, the sudoers
 * rule and the helper itself (install_mail_gateway.sh 1.4). A site container
 * has none of it: it asks its Docker host to stop serving it. A failure keeps the plugin installed, so the uninstall can be
 * run again rather than leave a root service behind with nothing to manage it.
 *
 * The plugin is already inactive here, so its classes do not resolve by name:
 * MailAppGateway is loaded by path.
 *
 * @version 1.1.0 - a site in a container asks its host to stop serving it
 * @version 1.0.0
 */

function mail_apps_uninstall() {
	require_once(PathHelper::getIncludePath('plugins/mail_apps/includes/MailAppGateway.php'));
	if (MailAppGateway::inContainer()) {
		// The host serves a container site only while it asks: ask it to stop.
		$off = MailAppGateway::turnOff();
		if (!$off['ok']) {
			error_log('mail_apps uninstall: the host was not asked to stop serving this site: ' . $off['message']);
		}
		return true;
	}
	if (!MailAppGateway::helperInstalled()) {
		return true;
	}
	$run = MailAppGateway::run('remove');
	if (!$run['ok']) {
		error_log('mail_apps uninstall: the gateway was not removed from this machine: ' . $run['message']
			. ' Run as root: sudo /usr/local/sbin/joinery-mail-gateway remove ' . MailAppGateway::siteName());
		return false;
	}
	return true;
}
?>
