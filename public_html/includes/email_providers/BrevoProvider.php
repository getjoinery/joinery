<?php
/**
 * BrevoProvider - Brevo (formerly Sendinblue) email service provider
 *
 * Implements EmailServiceProvider using getbrevo/brevo-php v2.x.
 * Batch sending uses messageVersions[] for separate envelopes (up to 1000 per call).
 */

require_once(PathHelper::getComposerAutoloadPath());

use Brevo\Client\Configuration as BrevoConfiguration;
use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Api\AccountApi;
use Brevo\Client\Model\SendSmtpEmail;
use Brevo\Client\ApiException as BrevoApiException;
use GuzzleHttp\Client as GuzzleClient;

class BrevoProvider implements EmailServiceProvider {

    public static function getKey(): string {
        return 'brevo';
    }

    public static function getLabel(): string {
        return 'Brevo';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'brevo_api_key',
                'label' => 'Brevo API Key',
                'type' => 'password',
                'helptext' => 'v3 API key from Brevo dashboard → SMTP & API → API Keys. Starts with "xkeysib-".',
            ],
            [
                'key' => 'brevo_sandbox_mode',
                'label' => 'Sandbox Mode (no real delivery)',
                'type' => 'dropdown',
                'options' => [0 => 'Off', 1 => 'On'],
            ],
            [
                'key' => 'brevo_verified_domain',
                'label' => 'Verified Sender Domain',
                'type' => 'text',
                'helptext' => 'For display only — Brevo validates the From at send time. Senders must be verified per-address or per-domain in Brevo.',
            ],
        ];
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('brevo_api_key'))) {
            $errors[] = 'Brevo API key not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Live API validation via GET /v3/account.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $key = $settings->get_setting('brevo_api_key');
        $configured_domain = $settings->get_setting('brevo_verified_domain');

        if (empty($key)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter API key to validate connection',
            ];
        }

        try {
            $config = BrevoConfiguration::getDefaultConfiguration()->setApiKey('api-key', $key);
            $account_api = new AccountApi(new GuzzleClient(), $config);
            $account = $account_api->getAccount();

            $details = [];
            if (method_exists($account, 'getEmail')) {
                $details['Account Email'] = $account->getEmail();
            }
            if (method_exists($account, 'getCompanyName')) {
                $name = $account->getCompanyName();
                if (!empty($name)) {
                    $details['Company Name'] = $name;
                }
            }
            if (method_exists($account, 'getPlan')) {
                $plan = $account->getPlan();
                if (is_array($plan) && !empty($plan[0])) {
                    $first = $plan[0];
                    if (method_exists($first, 'getType')) {
                        $details['Plan'] = $first->getType();
                    }
                    if (method_exists($first, 'getCredits')) {
                        $details['Credits'] = $first->getCredits();
                    }
                }
            }
            if (!empty($configured_domain)) {
                $details['Verified Domain'] = $configured_domain;
            }

            return [
                'success' => true,
                'label' => 'API Key Valid',
                'details' => $details,
                'error' => null,
            ];
        } catch (BrevoApiException $e) {
            $code = $e->getCode();
            if ($code === 401) {
                return [
                    'success' => false,
                    'label' => 'API Key Rejected',
                    'details' => [],
                    'error' => 'Invalid API key. Must be a v3 key starting with "xkeysib-" (not an SMTP relay password).',
                ];
            }
            if ($code === 403) {
                return [
                    'success' => false,
                    'label' => 'Access Denied',
                    'details' => [],
                    'error' => 'API key lacks transactional-email scope.',
                ];
            }
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => 'Brevo error ' . $code . ': ' . $e->getMessage(),
            ];
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

        try {
            $api = $this->buildApi($settings->get_setting('brevo_api_key'));
            $email = $this->buildBaseEmail($message, $settings);

            $email->setTo($this->mapRecipients($message->getRecipients()));
            if ($cc = $this->mapRecipients($message->getCc())) {
                $email->setCc($cc);
            }
            if ($bcc = $this->mapRecipients($message->getBcc())) {
                $email->setBcc($bcc);
            }

            $api->sendTransacEmail($email);
            return true;
        } catch (\Exception $e) {
            error_log('[BrevoProvider] Send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();
        $failed = [];

        try {
            $api = $this->buildApi($settings->get_setting('brevo_api_key'));
            $chunks = array_chunk($recipients, 1000);

            foreach ($chunks as $chunk) {
                try {
                    $email = $this->buildBaseEmail($message, $settings);
                    // Brevo SDK requires `to` even when using messageVersions — set to first recipient.
                    $email->setTo([['email' => $chunk[0]]]);

                    $versions = [];
                    foreach ($chunk as $email_addr) {
                        $versions[] = ['to' => [['email' => $email_addr]]];
                    }
                    $email->setMessageVersions($versions);

                    $api->sendTransacEmail($email);
                } catch (\Exception $e) {
                    error_log('[BrevoProvider] Batch chunk failed: ' . $e->getMessage());
                    $failed = array_merge($failed, $chunk);
                }
            }
        } catch (\Exception $e) {
            error_log('[BrevoProvider] Batch setup failed: ' . $e->getMessage());
            $failed = $recipients;
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /**
     * Build a SendSmtpEmail with subject/body/from/replyTo/headers — no recipients.
     */
    private function buildBaseEmail(EmailMessage $message, Globalvars $settings): SendSmtpEmail {
        $email = new SendSmtpEmail();

        $sender = ['email' => $message->getFrom()];
        if ($message->getFromName()) {
            $sender['name'] = $message->getFromName();
        }
        $email->setSender($sender);

        $email->setSubject($message->getSubject());

        if ($message->getHtmlBody()) {
            $email->setHtmlContent($message->getHtmlBody());
        }
        if ($message->getTextBody()) {
            $email->setTextContent($message->getTextBody());
        }

        if ($replyTo = $message->getReplyTo()) {
            $email->setReplyTo(['email' => $replyTo]);
        }

        $headers = $message->getHeaders();
        if ($settings->get_setting('brevo_sandbox_mode') == '1') {
            $headers['X-Sib-Sandbox'] = 'drop';
        }
        if (!empty($headers)) {
            $email->setHeaders($headers);
        }

        // Attachments
        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            if (!empty($a['path']) && is_readable($a['path'])) {
                $attachments[] = [
                    'name' => $a['name'] ?: basename($a['path']),
                    'content' => base64_encode(file_get_contents($a['path'])),
                ];
            }
        }
        if (!empty($attachments)) {
            $email->setAttachment($attachments);
        }

        return $email;
    }

    private function mapRecipients(array $list): array {
        $out = [];
        foreach ($list as $r) {
            if (!empty($r['email'])) {
                $entry = ['email' => $r['email']];
                if (!empty($r['name'])) {
                    $entry['name'] = $r['name'];
                }
                $out[] = $entry;
            }
        }
        return $out;
    }

    private function buildApi(string $api_key): TransactionalEmailsApi {
        $config = BrevoConfiguration::getDefaultConfiguration()->setApiKey('api-key', $api_key);
        return new TransactionalEmailsApi(new GuzzleClient(), $config);
    }
}
