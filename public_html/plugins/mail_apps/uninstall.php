<?php
/**
 * Uninstall hook for mail_apps (PluginManager::uninstall() runs it before the
 * plugin's tables are dropped).
 *
 * Takes the mail gateway off this machine: the root helper's remove verb
 * forgets this site and, once no site on the machine has the plugin, removes
 * the unit, the binary, the joinery-mailgw user, the renewal hook, the sudoers
 * rule and the helper itself (install_mail_gateway.sh 1.4). A site container
 * has none of it. A failure keeps the plugin installed, so the uninstall can be
 * run again rather than leave a root service behind with nothing to manage it.
 *
 * The plugin is already inactive here, so its classes do not resolve by name:
 * MailAppGateway is loaded by path.
 *
 * @version 1.0.0
 */

function mail_apps_uninstall() {
	require_once(PathHelper::getIncludePath('plugins/mail_apps/includes/MailAppGateway.php'));
	if (MailAppGateway::inContainer() || !MailAppGateway::helperInstalled()) {
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
