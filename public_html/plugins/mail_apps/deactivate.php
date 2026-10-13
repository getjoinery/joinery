<?php
/**
 * Deactivation hook for mail_apps (PluginManager::deactivate() runs it while
 * the plugin's classes still resolve).
 *
 * Deactivating the plugin turns the site's mail apps off: the gateway stops
 * serving this site and closes the ports when no site is left, the switch
 * records off, and every borrowed window a mail credential holds ends now.
 * The credentials themselves are refused from this moment anyway — the `mail`
 * purpose is no longer declared (AppAccess) — and the tables are kept.
 *
 * @version 1.3.0 - a site in a container asks its host to stop serving it (the off request)
 * @version 1.2.0 - forgets the recorded route (mail_apps_route) with the name
 * @version 1.1.0 - a relay-fronted site's relay is sent the change, so it stops passing mail programs through
 * @version 1.0.0
 */

function mail_apps_deactivate() {
	try {
		if (Globalvars::get_instance()->get_setting('mail_apps_enabled')
				&& (MailAppGateway::inContainer() || MailAppGateway::helperInstalled())) {
			$run = MailAppGateway::turnOff();
			if (!$run['ok']) {
				error_log('mail_apps deactivate: the gateway could not be turned off: ' . $run['message']);
			}
		}
		Setting::put('mail_apps_enabled', false);
		Setting::put('mail_apps_served_name', '');
		Setting::put('mail_apps_route', '');
		// A relay passing mail programs through learns from the next fragment
		// that this server takes none any more (RelayMapExporter reads the two
		// settings just cleared).
		if (class_exists('RelayMapSync')) {
			RelayMapSync::onChange();
		}
	} catch (\Throwable $e) {
		error_log('mail_apps deactivate: ' . $e->getMessage());
	}
	try {
		ApiKey::LockBorrowedWindowsForPurpose('mail');
	} catch (\Throwable $e) {
		error_log('mail_apps deactivate: borrowed windows were not all ended: ' . $e->getMessage());
	}
}
?>
