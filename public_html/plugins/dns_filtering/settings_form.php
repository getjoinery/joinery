<?php
// ScrollDaddy plugin settings
// This file is included within admin_settings.php context
// $formwriter, $settings, and $session are already available

// IMPORTANT: All settings MUST be prefixed with your plugin name
// to avoid conflicts with other plugins and core settings.
// Pattern: {plugin_name}_{setting_name}

echo '<p>Configure your ScrollDaddy DNS service settings below.</p>';
$formwriter->textinput('dns_filtering_dns_host', 'ScrollDaddy DNS Host', [
    'value' => $settings->get_setting('dns_filtering_dns_host'),
    'placeholder' => 'e.g. dns.example.com'
]);
$formwriter->textinput('dns_filtering_dns_internal_url', 'ScrollDaddy Internal URL', [
    'value' => $settings->get_setting('dns_filtering_dns_internal_url'),
    'placeholder' => 'e.g. http://127.0.0.1:8053'
]);
$formwriter->passwordinput('dns_filtering_dns_api_key', 'ScrollDaddy DNS API Key', [
    'value' => $settings->get_setting('dns_filtering_dns_api_key'),
    'placeholder' => 'API key for DNS server management endpoints',
]);
$formwriter->textinput('dns_filtering_dns_server_ip', 'Server Public IP', [
    'value' => $settings->get_setting('dns_filtering_dns_server_ip'),
    'placeholder' => 'e.g. 45.56.103.84 (shown in Windows/router setup instructions)',
]);

echo '<h4>Secondary DNS Server (Optional)</h4>';
echo '<p>Configure a secondary DNS server for redundancy. Leave blank for single-server mode.</p>';
$formwriter->textinput('dns_filtering_dns_secondary_internal_url', 'Secondary Internal URL', [
    'value' => $settings->get_setting('dns_filtering_dns_secondary_internal_url'),
    'placeholder' => 'e.g. http://10.0.0.2:8053'
]);
$formwriter->passwordinput('dns_filtering_dns_secondary_api_key', 'Secondary DNS API Key', [
    'value' => $settings->get_setting('dns_filtering_dns_secondary_api_key'),
    'placeholder' => 'Leave blank to use primary API key',
]);
$formwriter->textinput('dns_filtering_dns_secondary_server_ip', 'Secondary Server Public IP', [
    'value' => $settings->get_setting('dns_filtering_dns_secondary_server_ip'),
    'placeholder' => 'e.g. 172.16.0.2 (shown in setup instructions when configured)',
]);
?>
