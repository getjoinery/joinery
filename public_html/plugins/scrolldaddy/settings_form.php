<?php
// ScrollDaddy plugin settings
// This file is included within admin_settings.php context
// $formwriter, $settings, and $session are already available

// IMPORTANT: All settings MUST be prefixed with your plugin name
// to avoid conflicts with other plugins and core settings.
// Pattern: {plugin_name}_{setting_name}

echo '<p>Configure your ScrollDaddy DNS service settings below.</p>';
$formwriter->textinput('scrolldaddy_dns_host', 'ScrollDaddy DNS Host', [
    'value' => $settings->get_setting('scrolldaddy_dns_host'),
    'placeholder' => 'e.g. dns.example.com'
]);
$formwriter->textinput('scrolldaddy_dns_internal_url', 'ScrollDaddy Internal URL', [
    'value' => $settings->get_setting('scrolldaddy_dns_internal_url'),
    'placeholder' => 'e.g. http://127.0.0.1:8053'
]);
$formwriter->passwordinput('scrolldaddy_dns_api_key', 'ScrollDaddy DNS API Key', [
    'value' => $settings->get_setting('scrolldaddy_dns_api_key'),
    'placeholder' => 'API key for DNS server management endpoints',
]);
?>
