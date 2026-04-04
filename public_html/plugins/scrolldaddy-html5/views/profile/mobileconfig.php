<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('mobileconfig_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(mobileconfig_logic($_GET, $_POST));
$device = $page_vars['device'];
$doh_url = $page_vars['doh_url'];
$dns_host = $page_vars['dns_host'];
$resolver_uid = $page_vars['resolver_uid'];

// Derive deterministic UUIDs from resolver_uid (32 hex chars -> UUID format)
$inner_uuid = substr($resolver_uid, 0, 8) . '-'
            . substr($resolver_uid, 8, 4) . '-'
            . substr($resolver_uid, 12, 4) . '-'
            . substr($resolver_uid, 16, 4) . '-'
            . substr($resolver_uid, 20, 12);

$reversed = strrev($resolver_uid);
$outer_uuid = substr($reversed, 0, 8) . '-'
            . substr($reversed, 8, 4) . '-'
            . substr($reversed, 12, 4) . '-'
            . substr($reversed, 16, 4) . '-'
            . substr($reversed, 20, 12);

$device_name = htmlspecialchars($device->get_readable_name());

header('Content-Type: application/x-apple-aspen-config');
header('Content-Disposition: attachment; filename="scrolldaddy-' . preg_replace('/[^a-z0-9]/i', '-', $device->get_readable_name()) . '.mobileconfig"');

echo '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>PayloadContent</key>
	<array>
		<dict>
			<key>DNSSettings</key>
			<dict>
				<key>DNSProtocol</key>
				<string>HTTPS</string>
				<key>ServerURL</key>
				<string>' . htmlspecialchars($doh_url) . '</string>
				<key>ServerName</key>
				<string>' . htmlspecialchars($dns_host) . '</string>
			</dict>
			<key>PayloadDescription</key>
			<string>Configures DNS filtering for ' . $device_name . '</string>
			<key>PayloadDisplayName</key>
			<string>ScrollDaddy DNS</string>
			<key>PayloadIdentifier</key>
			<string>app.scrolldaddy.dns.' . htmlspecialchars($resolver_uid) . '</string>
			<key>PayloadType</key>
			<string>com.apple.dnsSettings.managed</string>
			<key>PayloadUUID</key>
			<string>' . $inner_uuid . '</string>
			<key>PayloadVersion</key>
			<integer>1</integer>
		</dict>
	</array>
	<key>PayloadDescription</key>
	<string>ScrollDaddy DNS filtering for ' . $device_name . '</string>
	<key>PayloadDisplayName</key>
	<string>ScrollDaddy</string>
	<key>PayloadIdentifier</key>
	<string>app.scrolldaddy.dns-config.' . htmlspecialchars($resolver_uid) . '</string>
	<key>PayloadRemovalDisallowed</key>
	<false/>
	<key>PayloadType</key>
	<string>Configuration</string>
	<key>PayloadUUID</key>
	<string>' . $outer_uuid . '</string>
	<key>PayloadVersion</key>
	<integer>1</integer>
</dict>
</plist>';
?>
