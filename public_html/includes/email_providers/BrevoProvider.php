<?php
/**
 * BrevoProvider - Brevo (formerly Sendinblue) email service provider
 *
 * Implements EmailServiceProvider using getbrevo/brevo-php v5.x.
 * Batch sending uses messageVersions[] for separate envelopes (up to 1000 per call).
 */

require_once(PathHelper::getComposerAutoloadPath());

use Brevo\Brevo as BrevoClient;
use Brevo\Exceptions\BrevoApiException;
use Brevo\TransactionalEmails\Requests\SendTransacEmailRequest;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestAttachmentItem;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestBccItem;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestCcItem;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestMessageVersionsItem;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestMessageVersionsItemToItem;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestReplyTo;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestSender;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestToItem;

class BrevoProvider implements EmailServiceProvider {

    public static function getKey(): string {
        return 'brevo';
    }

    public static function getLabel(): string {
        return 'Brevo';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:spf.brevo.com';
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
            $account = (new BrevoClient($key))->account->getAccount();

            $details = [];
            if ($account !== NULL) {
                if (!empty($account->email)) {
                    $details['Account Email'] = $account->email;
                }
                if (!empty($account->companyName)) {
                    $details['Company Name'] = $account->companyName;
                }
                // plan is a list; the first entry is the active one, as in the API docs.
                $plan = $account->plan[0] ?? NULL;
                if ($plan !== NULL) {
                    $details['Plan'] = $plan->type;
                    $details['Credits'] = $plan->credits;
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
            $values = $this->buildBaseEmail($message, $settings);

            $values['to'] = $this->mapRecipients($message->getRecipients(), SendTransacEmailRequestToItem::class);
            if ($cc = $this->mapRecipients($message->getCc(), SendTransacEmailRequestCcItem::class)) {
                $values['cc'] = $cc;
            }
            if ($bcc = $this->mapRecipients($message->getBcc(), SendTransacEmailRequestBccItem::class)) {
                $values['bcc'] = $bcc;
            }

            $this->buildApi($settings->get_setting('brevo_api_key'))
                 ->transactionalEmails
                 ->sendTransacEmail(new SendTransacEmailRequest($values));
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
                    $values = $this->buildBaseEmail($message, $settings);
                    // Brevo requires `to` even when using messageVersions — set to first recipient.
                    $values['to'] = [new SendTransacEmailRequestToItem(['email' => $chunk[0]])];

                    $versions = [];
                    foreach ($chunk as $email_addr) {
                        $versions[] = new SendTransacEmailRequestMessageVersionsItem([
                            'to' => [new SendTransacEmailRequestMessageVersionsItemToItem(['email' => $email_addr])],
                        ]);
                    }
                    $values['messageVersions'] = $versions;

                    $api->transactionalEmails->sendTransacEmail(new SendTransacEmailRequest($values));
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
     * Build the subject/body/from/replyTo/headers half of a send — no recipients.
     *
     * Returns the constructor array rather than a request object: `to` and the
     * batch-only `messageVersions` are filled in by the caller, and the v5
     * request is populated once at construction rather than through setters.
     *
     * @return array<string, mixed>
     */
    private function buildBaseEmail(EmailMessage $message, Globalvars $settings): array {
        $values = [];

        $sender = ['email' => $message->getFrom()];
        if ($message->getFromName()) {
            $sender['name'] = $message->getFromName();
        }
        $values['sender'] = new SendTransacEmailRequestSender($sender);

        $values['subject'] = $message->getSubject();

        if ($message->getHtmlBody()) {
            $values['htmlContent'] = $message->getHtmlBody();
        }
        if ($message->getTextBody()) {
            $values['textContent'] = $message->getTextBody();
        }

        if ($replyTo = $message->getReplyTo()) {
            $values['replyTo'] = new SendTransacEmailRequestReplyTo(['email' => $replyTo]);
        }

        $headers = $message->getHeaders();
        if ($settings->get_setting('brevo_sandbox_mode') == '1') {
            $headers['X-Sib-Sandbox'] = 'drop';
        }
        if (!empty($headers)) {
            $values['headers'] = $headers;
        }

        // Attachments
        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            if (!empty($a['path']) && is_readable($a['path'])) {
                $attachments[] = new SendTransacEmailRequestAttachmentItem([
                    'name' => $a['name'] ?: basename($a['path']),
                    'content' => base64_encode(file_get_contents($a['path'])),
                ]);
            } elseif (isset($a['data'])) {
                if (!empty($a['cid'])) {
                    // The Brevo API has no Content-ID field, so an inline part cannot
                    // embed — degrade it to a regular downloadable attachment. Declared
                    // provider limitation, logged once so the fallback is visible.
                    error_log('[BrevoProvider] Inline attachment degraded to regular attachment (no Content-ID support): cid=' . $a['cid']);
                }
                $attachments[] = new SendTransacEmailRequestAttachmentItem([
                    'name' => $a['name'] ?: 'attachment',
                    'content' => base64_encode($a['data']),
                ]);
            }
        }
        if (!empty($attachments)) {
            $values['attachment'] = $attachments;
        }

        return $values;
    }

    /**
     * @param class-string $item_class Recipient value object for the field being filled.
     * @return array<object>
     */
    private function mapRecipients(array $list, string $item_class): array {
        $out = [];
        foreach ($list as $r) {
            if (!empty($r['email'])) {
                $entry = ['email' => $r['email']];
                if (!empty($r['name'])) {
                    $entry['name'] = $r['name'];
                }
                $out[] = new $item_class($entry);
            }
        }
        return $out;
    }

    private function buildApi(string $api_key): BrevoClient {
        return new BrevoClient($api_key);
    }
}
