<?php
// Inbound Email plugin settings — included from /admin/admin_settings
// $formwriter, $settings, and $session are already available.
?>

<p>Configuration for self-hosted inbound email. Inbound mail is received by a
co-located Postfix instance and piped to the plugin; the forwarding feature
delivers it onward through the SMTP relay below.</p>

<h4>General</h4>

<?php
$yes_no = [1 => 'Yes', 0 => 'No'];

$formwriter->dropinput('inbound_email_enabled', 'Inbound email enabled', [
    'options'  => $yes_no,
    'value'    => $settings->get_setting('inbound_email_enabled'),
    'helptext' => 'Master switch. Disabling this stops all inbound processing without removing mailbox configuration.',
]);

$formwriter->dropinput('inbound_email_from_show_via', 'Add "via <site name>" to forwarded From line', [
    'options'  => $yes_no,
    'value'    => $settings->get_setting('inbound_email_from_show_via'),
    'helptext' => 'When on, a forwarded message shows the sender as "Original Name via '
        . htmlspecialchars($settings->get_setting('defaultemailname') ?: 'Inbound Email') . '". '
        . 'Turn off to show just the original sender name. Either way the From address is the site '
        . 'address — that part is required for deliverability.',
]);
?>

<h4>Outbound SMTP Relay</h4>

<?php
$formwriter->textinput('inbound_email_forwarding_smtp_host', 'SMTP Host', [
    'value'       => $settings->get_setting('inbound_email_forwarding_smtp_host'),
    'placeholder' => 'smtp.example.com',
    'helptext'    => 'Hostname of the outbound SMTP server used to forward messages.',
]);

$formwriter->textinput('inbound_email_forwarding_smtp_port', 'SMTP Port', [
    'value'       => $settings->get_setting('inbound_email_forwarding_smtp_port'),
    'placeholder' => '587',
    'helptext'    => 'Typically 587 (STARTTLS) or 465 (SSL).',
]);

$formwriter->textinput('inbound_email_forwarding_smtp_username', 'SMTP Username', [
    'value'       => $settings->get_setting('inbound_email_forwarding_smtp_username'),
    'placeholder' => 'relay@example.com',
]);

$formwriter->passwordinput('inbound_email_forwarding_smtp_password', 'SMTP Password', [
    'value'   => $settings->get_setting('inbound_email_forwarding_smtp_password'),
]);
?>

<h4>SRS (Sender Rewriting Scheme)</h4>

<?php
$formwriter->dropinput('inbound_email_srs_enabled', 'SRS enabled', [
    'options'  => $yes_no,
    'value'    => $settings->get_setting('inbound_email_srs_enabled'),
    'helptext' => 'Rewrites the envelope sender so SPF checks pass at the final destination.',
]);

$formwriter->passwordinput('inbound_email_srs_secret', 'SRS Secret', [
    'value'   => $settings->get_setting('inbound_email_srs_secret'),
    'helptext' => 'Random secret used to sign and verify SRS-rewritten addresses. Required when SRS is enabled.',
]);
?>

<h4>Rate Limiting</h4>

<?php
$formwriter->textinput('inbound_email_forwarding_rate_limit_per_alias', 'Max forwards per alias per window', [
    'value'   => $settings->get_setting('inbound_email_forwarding_rate_limit_per_alias'),
    'helptext' => 'Emails above this limit from a single alias within the window are dropped.',
]);

$formwriter->textinput('inbound_email_forwarding_rate_limit_per_domain', 'Max forwards per domain per window', [
    'value'   => $settings->get_setting('inbound_email_forwarding_rate_limit_per_domain'),
    'helptext' => 'Combined limit across all aliases sharing a domain.',
]);

$formwriter->textinput('inbound_email_forwarding_rate_limit_window', 'Rate limit window (seconds)', [
    'value'   => $settings->get_setting('inbound_email_forwarding_rate_limit_window'),
    'helptext' => 'Rolling window length in seconds (default 3600 = 1 hour).',
]);
?>

<h4>Local Mailbox (stored messages)</h4>

<?php
$formwriter->textinput('inbound_email_mailbox_retention_days', 'Mailbox retention (days)', [
    'value'   => $settings->get_setting('inbound_email_mailbox_retention_days'),
    'helptext' => 'Stored inbound messages older than this are purged by the PurgeOldMailboxMessages scheduled task. Default 14.',
]);

$formwriter->textinput('inbound_email_mailbox_max_per_window', 'Mailbox store volume cap (per domain, per window)', [
    'value'   => $settings->get_setting('inbound_email_mailbox_max_per_window'),
    'helptext' => 'Max messages stored per domain inside the forwarding rate-limit window. Stores above this are dropped with status "store_capped". 0 disables the cap.',
]);
?>

<h4>Inbound Provider</h4>

<?php
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundProviderRegistry.php'));
$provider_options = [];
foreach (InboundProviderRegistry::all() as $key => $class) {
    $provider_options[$key] = $class::getLabel();
}
$formwriter->dropinput('inbound_email_provider', 'Active inbound provider', [
    'options'  => $provider_options,
    'value'    => $settings->get_setting('inbound_email_provider'),
    'helptext' => 'Selects how the platform receives inbound mail. Postfix (self-hosted) handles MX locally; webhook providers (Mailgun, etc.) deliver via HTTP POST.',
]);

// Render the active provider's inbound-only settings inline so the admin
// configures them in context.
$active_key = trim((string)$settings->get_setting('inbound_email_provider'));
if ($active_key === '') { $active_key = 'postfix'; }
$active_class = InboundProviderRegistry::get($active_key);
if ($active_class !== null) {
    $inbound_fields = $active_class::getInboundSettingsFields();
    foreach ($inbound_fields as $field) {
        $key = $field['key'] ?? '';
        if ($key === '') continue;
        $label = $field['label'] ?? $key;
        $type = $field['type'] ?? 'text';
        $opts = [
            'value'    => $settings->get_setting($key),
            'helptext' => $field['helptext'] ?? '',
        ];
        if ($type === 'password') {
            $formwriter->passwordinput($key, $label, $opts);
        } else {
            $formwriter->textinput($key, $label, $opts);
        }
    }
}
?>

<h4>Housekeeping</h4>

<?php
$formwriter->textinput('inbound_email_forwarding_max_destinations', 'Max destinations per alias', [
    'value'   => $settings->get_setting('inbound_email_forwarding_max_destinations'),
    'helptext' => 'Hard limit on how many forwarding addresses one alias can have.',
]);

$formwriter->textinput('inbound_email_log_retention_days', 'Log retention (days)', [
    'value'   => $settings->get_setting('inbound_email_log_retention_days'),
    'helptext' => 'Inbound email log entries older than this are purged by the scheduled task.',
]);
?>
