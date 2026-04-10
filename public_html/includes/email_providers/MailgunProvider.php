<?php
/**
 * MailgunProvider - Mailgun email service provider
 *
 * Implements EmailServiceProvider using the Mailgun PHP SDK (v3.x).
 * Supports batch sending in groups of 500 using Mailgun recipient-variables.
 */

require_once(PathHelper::getComposerAutoloadPath());

use Mailgun\Mailgun;

class MailgunProvider implements EmailServiceProvider {

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
}
