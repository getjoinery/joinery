<?php
/**
 * MailgunProvider - Mailgun email service provider
 *
 * Implements EmailServiceProvider using the Mailgun PHP SDK (v3.x).
 * Supports batch sending in groups of 500 using Mailgun recipient-variables.
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));

use Mailgun\Mailgun;

class MailgunProvider implements EmailServiceProvider, InboundEmailProvider {

    public static function getKey(): string {
        return 'mailgun';
    }

    public static function getLabel(): string {
        return 'Mailgun';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'mailgun_api_key',
                'label' => 'Mailgun API Key (Example: key-6eac34eed3afb3df055f81aa20d878e4)',
                'type' => 'text',
            ],
            [
                'key' => 'mailgun_domain',
                'label' => 'Mailgun Domain (Example: mg.domain.net)',
                'type' => 'text',
            ],
            [
                'key' => 'mailgun_eu_api_link',
                'label' => 'Mailgun EU API Link (Example: https://api.eu.mailgun.net)',
                'type' => 'text',
                'helptext' => 'Only needed for EU region accounts',
            ],
        ];
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('mailgun_api_key'))) {
            $errors[] = 'Mailgun API key not configured';
        }
        if (empty($settings->get_setting('mailgun_domain'))) {
            $errors[] = 'Mailgun domain not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Optional: Live API validation for admin settings panel.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('mailgun_api_key');
        $domain = $settings->get_setting('mailgun_domain');
        $eu_link = $settings->get_setting('mailgun_eu_api_link');

        if (empty($api_key) || empty($domain)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter API key and domain to validate connection',
            ];
        }

        try {
            if ($eu_link) {
                $mg = Mailgun::create($api_key, $eu_link);
            } else {
                $mg = Mailgun::create($api_key);
            }

            try {
                $domain_info = $mg->domains()->show($domain);
                $details = ['Domain' => $domain];

                if ($domain_info && method_exists($domain_info, 'getDomain')) {
                    $d = $domain_info->getDomain();
                    if ($d) {
                        if (method_exists($d, 'getName')) {
                            $details['Name'] = $d->getName();
                        }
                        if (method_exists($d, 'getState')) {
                            $details['Status'] = $d->getState();
                        }
                    }
                }

                return [
                    'success' => true,
                    'label' => 'API Key Valid',
                    'details' => $details,
                    'error' => null,
                ];
            } catch (\Exception $domain_ex) {
                $error_msg = $domain_ex->getMessage();

                // Try to find a similar domain to suggest
                $suggested = null;
                try {
                    $all_domains = $mg->domains()->index();
                    $entered_lower = strtolower($domain);
                    foreach ($all_domains->getDomains() as $acct_domain) {
                        $acct_name = strtolower($acct_domain->getName());
                        if (stripos($entered_lower, $acct_name) !== false || stripos($acct_name, $entered_lower) !== false) {
                            $suggested = $acct_domain->getName();
                            break;
                        }
                    }
                } catch (\Exception $list_ex) {
                    // Couldn't list domains, skip suggestion
                }

                $details = ['Configured Domain' => $domain];
                $error = $error_msg;

                if ($suggested) {
                    $details['Suggested Domain'] = $suggested;
                    $error = 'Domain not found. Did you mean: ' . $suggested;
                }

                return [
                    'success' => false,
                    'label' => 'Mailgun Validation Failed',
                    'details' => $details,
                    'error' => $error,
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function send(EmailMessage $message): bool {
        $settings = Globalvars::get_instance();

        if ($settings->get_setting('mailgun_eu_api_link')) {
            $mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
        } else {
            $mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
        }

        $domain = $settings->get_setting('mailgun_domain');

        $email_to_send = [
            'from' => $message->getFromName() . '<' . $message->getFrom() . '>',
            'subject' => $message->getSubject(),
        ];

        if ($message->getHtmlBody()) {
            $email_to_send['html'] = $message->getHtmlBody();
        } else {
            $email_to_send['text'] = $message->getTextBody();
        }

        $recipients = $message->getRecipients();
        $sending_groups = array_chunk($recipients, 500, true);
        $all_sent = true;

        foreach ($sending_groups as $sending_group) {
            $mailgun_recipients = [];
            $recipient_variables = [];

            foreach ($sending_group as $recipient) {
                $mailgun_recipients[] = $recipient['name'] . '<' . $recipient['email'] . '>';
                $recipient_variables[$recipient['email']] = ['name' => $recipient['name']];
            }

            $email_to_send['to'] = implode(',', $mailgun_recipients);
            $email_to_send['recipient-variables'] = json_encode($recipient_variables);

            try {
                $mg->messages()->send($domain, $email_to_send);
            } catch (\Exception $e) {
                error_log("[MailgunProvider] Send failed: " . $e->getMessage());
                $all_sent = false;
            }
        }

        return $all_sent;
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();

        if ($settings->get_setting('mailgun_eu_api_link')) {
            $mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
        } else {
            $mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
        }

        $domain = $settings->get_setting('mailgun_domain');

        $email_to_send = [
            'from' => $message->getFromName() . '<' . $message->getFrom() . '>',
            'subject' => $message->getSubject(),
        ];

        if ($message->getHtmlBody()) {
            $email_to_send['html'] = $message->getHtmlBody();
        } else {
            $email_to_send['text'] = $message->getTextBody();
        }

        // Chunk recipients into groups of 500 (Mailgun limit)
        $sending_groups = array_chunk($recipients, 500);
        $failed_recipients = [];

        foreach ($sending_groups as $group) {
            $mailgun_recipients = [];
            $recipient_variables = [];

            foreach ($group as $email) {
                $mailgun_recipients[] = $email;
                $recipient_variables[$email] = ['name' => $email];
            }

            $email_to_send['to'] = implode(',', $mailgun_recipients);
            $email_to_send['recipient-variables'] = json_encode($recipient_variables);

            try {
                $mg->messages()->send($domain, $email_to_send);
            } catch (\Exception $e) {
                error_log("[MailgunProvider] Batch chunk failed: " . $e->getMessage());
                // Track which recipients failed (entire chunk fails together)
                $failed_recipients = array_merge($failed_recipients, $group);
            }
        }

        return [
            'success' => empty($failed_recipients),
            'failed_recipients' => $failed_recipients,
        ];
    }

    // ── InboundEmailProvider ────────────────────────────────────────────

    public static function getInboundSettingsFields(): array {
        return [
            [
                'key' => 'mailgun_webhook_signing_key',
                'label' => 'Mailgun Webhook Signing Key',
                'type' => 'password',
                'helptext' => 'HTTP Webhook Signing Key from your Mailgun dashboard (separate from the API key).',
            ],
        ];
    }

    public static function isWebhook(): bool {
        return true;
    }

    public static function getSetupChecks(?string $domain = null): array {
        $settings = Globalvars::get_instance();
        $results = [];

        $signing_key = (string)$settings->get_setting('mailgun_webhook_signing_key');
        if ($signing_key === '') {
            $signing_key = (string)$settings->get_setting('mailgun_api_key');
        }
        if ($signing_key !== '') {
            $results[] = self::makeResult('mailgun.signing_key_set', '', 'plugin', 'Mailgun webhook signing key', 'required', 'pass',
                'A webhook signing key is configured.');
        } else {
            $results[] = self::makeResult('mailgun.signing_key_set', '', 'plugin', 'Mailgun webhook signing key', 'required', 'fail',
                'No webhook signing key is configured — inbound webhooks will be rejected.',
                '',
                ['text' => 'Copy the HTTP Webhook Signing Key from the Mailgun dashboard into the inbound provider settings.']);
        }

        if ($domain) {
            $records = self::getDnsRecords($domain);
            foreach ($records as $rec) {
                $results[] = self::makeResult(
                    'mailgun.dns.' . strtolower($rec['type']),
                    $domain,
                    'domain',
                    'Mailgun ' . $rec['type'] . ' record',
                    'recommended',
                    'unknown',
                    'Publish: ' . $rec['name'] . ' ' . $rec['type'] . ' ' . $rec['value'],
                    $rec['note'] ?? '',
                    ['text' => 'Publish this record at your DNS provider.',
                     'dns_record' => ['type' => $rec['type'], 'name' => $rec['name'], 'value' => $rec['value']]]
                );
            }
        }

        return $results;
    }

    public static function getDnsRecords(string $domain): array {
        $settings = Globalvars::get_instance();
        $eu = trim((string)$settings->get_setting('mailgun_eu_api_link')) !== '';

        $mx1 = $eu ? 'mxa.eu.mailgun.org' : 'mxa.mailgun.org';
        $mx2 = $eu ? 'mxb.eu.mailgun.org' : 'mxb.mailgun.org';
        $spf_include = $eu ? 'eu.mailgun.org' : 'mailgun.org';

        return [
            ['type' => 'MX', 'name' => $domain, 'value' => '10 ' . $mx1,
             'note' => 'Primary Mailgun inbound MX.'],
            ['type' => 'MX', 'name' => $domain, 'value' => '10 ' . $mx2,
             'note' => 'Secondary Mailgun inbound MX.'],
            ['type' => 'TXT', 'name' => $domain, 'value' => 'v=spf1 include:' . $spf_include . ' -all',
             'note' => 'SPF — authorizes Mailgun to send for ' . $domain . '.'],
            ['type' => 'TXT', 'name' => 'krs._domainkey.' . $domain, 'value' => '(get from Mailgun dashboard)',
             'note' => 'DKIM — Mailgun publishes the public key value in your account; the selector matches.'],
            ['type' => 'TXT', 'name' => '_dmarc.' . $domain,
             'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@' . $domain,
             'note' => 'DMARC — recommended once SPF and DKIM are in place.'],
        ];
    }

    /**
     * Verify Mailgun's HMAC signature and pull raw MIME ('body-mime') + recipient.
     * Returns null on signature failure or missing fields.
     */
    public function handleInbound(array $post, string $raw_body): ?array {
        $settings = Globalvars::get_instance();
        $signing_key = (string)$settings->get_setting('mailgun_webhook_signing_key');
        if ($signing_key === '') {
            $signing_key = (string)$settings->get_setting('mailgun_api_key');
        }

        $timestamp = $post['timestamp'] ?? '';
        $token = $post['token'] ?? '';
        $signature = $post['signature'] ?? '';

        if ($timestamp === '' || $token === '' || $signature === '' || $signing_key === '') {
            error_log('[MailgunProvider] inbound rejected — missing signature parameters');
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $signing_key);
        if (!hash_equals($expected, $signature)) {
            error_log('[MailgunProvider] inbound rejected — invalid HMAC signature');
            return null;
        }

        $raw_mime = $post['body-mime'] ?? '';
        $recipient = $post['recipient'] ?? '';

        if ($raw_mime === '' || $recipient === '') {
            error_log('[MailgunProvider] inbound rejected — missing body-mime or recipient');
            return null;
        }

        return [
            'raw_mime' => (string)$raw_mime,
            'recipient' => (string)$recipient,
        ];
    }

    private static function makeResult($id, $scope, $layer, $label, $severity, $status, $summary, $detail = '', $fix = null, $recheckable = true): array {
        return [
            'id' => $id, 'scope' => $scope, 'layer' => $layer, 'label' => $label,
            'severity' => $severity, 'status' => $status, 'summary' => $summary,
            'detail' => $detail, 'fix' => $fix, 'recheckable' => $recheckable,
        ];
    }
}
