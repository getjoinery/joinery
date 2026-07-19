<?php
/**
 * PostmarkProvider - Postmark email service provider
 *
 * Implements EmailServiceProvider using the Postmark PHP SDK (v4.x).
 * Native batch sending up to 500 messages per call, with per-recipient failure tracking.
 */

require_once(PathHelper::getComposerAutoloadPath());

use Postmark\PostmarkClient;
use Postmark\Models\PostmarkException;

class PostmarkProvider implements EmailServiceProvider {

    public static function getKey(): string {
        return 'postmark';
    }

    public static function getLabel(): string {
        return 'Postmark';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:spf.mtasv.net';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'postmark_server_token',
                'label' => 'Postmark Server Token',
                'type' => 'password',
                'helptext' => 'Must be a Server token (per-Server, found under Servers → [Server] → API Tokens). Not an Account token.',
            ],
            [
                'key' => 'postmark_message_stream',
                'label' => 'Message Stream',
                'type' => 'text',
                'helptext' => 'Default transactional stream is "outbound". For broadcast/marketing mail, use the broadcast stream ID configured on the Server.',
            ],
            [
                'key' => 'postmark_track_opens',
                'label' => 'Track Opens',
                'type' => 'dropdown',
                'options' => [0 => 'Off', 1 => 'On'],
            ],
            [
                'key' => 'postmark_track_links',
                'label' => 'Track Links',
                'type' => 'dropdown',
                'options' => [
                    'None' => 'None',
                    'HtmlAndText' => 'HTML and Text',
                    'HtmlOnly' => 'HTML only',
                    'TextOnly' => 'Text only',
                ],
            ],
            [
                'key' => 'postmark_verified_domain',
                'label' => 'Verified Sender Domain',
                'type' => 'text',
                'helptext' => 'For display only — Postmark validates the From at send time against your Sender Signatures / verified domains.',
            ],
        ];
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('postmark_server_token'))) {
            $errors[] = 'Postmark Server Token not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Live API validation via getServer() — returns the Server's name and config.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $token = $settings->get_setting('postmark_server_token');
        $stream = $settings->get_setting('postmark_message_stream') ?: 'outbound';
        $configured_domain = $settings->get_setting('postmark_verified_domain');

        if (empty($token)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter Server Token to validate connection',
            ];
        }

        try {
            $client = new PostmarkClient($token);
            $server = $client->getServer();

            $details = [
                'Server Name' => $server->name ?? '(unknown)',
                'Message Stream' => $stream,
                'Track Opens' => $settings->get_setting('postmark_track_opens') == '1' ? 'On' : 'Off',
                'Track Links' => $settings->get_setting('postmark_track_links') ?: 'None',
            ];
            if (!empty($configured_domain)) {
                $details['Verified Domain'] = $configured_domain;
            }

            return [
                'success' => true,
                'label' => 'API Key Valid',
                'details' => $details,
                'error' => null,
            ];
        } catch (PostmarkException $e) {
            $code = $e->postmarkApiErrorCode ?? null;
            if ($e->httpStatusCode === 401 || $code === 10) {
                return [
                    'success' => false,
                    'label' => 'API Key Rejected',
                    'details' => [],
                    'error' => 'Invalid Server Token. Must be a Server token (per-Server), not an Account token.',
                ];
            }
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => 'Postmark error ' . $code . ': ' . $e->getMessage(),
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
        $token = $settings->get_setting('postmark_server_token');

        try {
            $client = new PostmarkClient($token);
            $stream = $settings->get_setting('postmark_message_stream') ?: 'outbound';
            $trackOpens = $settings->get_setting('postmark_track_opens') == '1';
            $trackLinks = $settings->get_setting('postmark_track_links') ?: 'None';

            $from = $message->getFromName()
                ? $message->getFromName() . ' <' . $message->getFrom() . '>'
                : $message->getFrom();

            $to = $this->joinRecipients($message->getRecipients());
            $cc = $this->joinRecipients($message->getCc());
            $bcc = $this->joinRecipients($message->getBcc());

            $client->sendEmail(
                $from,
                $to,
                $message->getSubject(),
                $message->getHtmlBody() ?: null,
                $message->getTextBody() ?: null,
                null,                                   // tag
                $trackOpens,
                $message->getReplyTo() ?: null,
                $cc ?: null,
                $bcc ?: null,
                $message->getHeaders() ?: null,
                $this->buildAttachments($message->getAttachments()),
                $trackLinks,
                null,                                   // metadata
                $stream
            );

            return true;
        } catch (\Exception $e) {
            error_log('[PostmarkProvider] Send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();
        $token = $settings->get_setting('postmark_server_token');
        $stream = $settings->get_setting('postmark_message_stream') ?: 'outbound';
        $trackOpens = $settings->get_setting('postmark_track_opens') == '1';
        $trackLinks = $settings->get_setting('postmark_track_links') ?: 'None';

        $from = $message->getFromName()
            ? $message->getFromName() . ' <' . $message->getFrom() . '>'
            : $message->getFrom();

        $failed = [];

        try {
            $client = new PostmarkClient($token);
            $chunks = array_chunk($recipients, 500);

            foreach ($chunks as $chunk) {
                $batch = [];
                foreach ($chunk as $email) {
                    $entry = [
                        'From' => $from,
                        'To' => $email,
                        'Subject' => $message->getSubject(),
                        'MessageStream' => $stream,
                        'TrackOpens' => $trackOpens,
                        'TrackLinks' => $trackLinks,
                    ];
                    if ($message->getHtmlBody()) {
                        $entry['HtmlBody'] = $message->getHtmlBody();
                    }
                    if ($message->getTextBody()) {
                        $entry['TextBody'] = $message->getTextBody();
                    }
                    if ($message->getReplyTo()) {
                        $entry['ReplyTo'] = $message->getReplyTo();
                    }
                    $batch[] = $entry;
                }

                try {
                    $responses = $client->sendEmailBatch($batch);
                    // Each response entry has ErrorCode (0 = success) and To
                    foreach ($responses as $idx => $r) {
                        $errorCode = is_object($r) ? ($r->errorcode ?? $r->ErrorCode ?? 0) : ($r['ErrorCode'] ?? 0);
                        if (intval($errorCode) !== 0) {
                            $failed[] = $chunk[$idx];
                        }
                    }
                } catch (\Exception $e) {
                    error_log('[PostmarkProvider] Batch chunk failed: ' . $e->getMessage());
                    $failed = array_merge($failed, $chunk);
                }
            }
        } catch (\Exception $e) {
            error_log('[PostmarkProvider] Batch setup failed: ' . $e->getMessage());
            $failed = $recipients;
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /**
     * Convert EmailMessage recipient arrays into comma-separated string format.
     */
    private function joinRecipients(array $list): string {
        $parts = [];
        foreach ($list as $r) {
            if (!empty($r['email'])) {
                $parts[] = !empty($r['name'])
                    ? $r['name'] . ' <' . $r['email'] . '>'
                    : $r['email'];
            }
        }
        return implode(',', $parts);
    }

    /**
     * Convert EmailMessage attachments into Postmark attachment shape.
     */
    private function buildAttachments(array $attachments): ?array {
        if (empty($attachments)) {
            return null;
        }
        $out = [];
        foreach ($attachments as $a) {
            if (!empty($a['path']) && is_readable($a['path'])) {
                $out[] = [
                    'Name' => $a['name'] ?: basename($a['path']),
                    'Content' => base64_encode(file_get_contents($a['path'])),
                    'ContentType' => mime_content_type($a['path']) ?: 'application/octet-stream',
                ];
            } elseif (isset($a['data'])) {
                $row = [
                    'Name' => $a['name'] ?: 'attachment',
                    'Content' => base64_encode($a['data']),
                    'ContentType' => $a['type'] ?: 'application/octet-stream',
                ];
                if (!empty($a['cid'])) {
                    // Inline (embedded) image: Postmark marks a part inline by giving
                    // it a cid:-prefixed ContentID, which the body references as cid:<id>.
                    $row['ContentID'] = 'cid:' . $a['cid'];
                }
                $out[] = $row;
            }
        }
        return $out ?: null;
    }
}
