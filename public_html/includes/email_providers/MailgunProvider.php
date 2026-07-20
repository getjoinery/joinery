<?php
/**
 * MailgunProvider - Mailgun email service provider
 *
 * Implements EmailServiceProvider using the Mailgun PHP SDK (v3.x).
 * Supports batch sending in groups of 500 using Mailgun recipient-variables.
 * Also implements ApiSubmissionRelay (messages.mime) so inbound forwarding and
 * the hidden-origin compose path can relay raw MIME through the same
 * mailgun_api_key, with no separate SMTP credential — over an HTTP API, so the
 * submitting box's IP never enters the delivered Received: chain. Raw relays
 * submit through the envelope sender's own Mailgun sending domain when the
 * account has it active, so Mailgun's DKIM signature aligns with the From
 * domain (DMARC); otherwise the configured mailgun_domain carries the send.
 *
 * Implements DkimRecordSource: the domains API reports the DKIM records a
 * sending domain must publish, which drives the mailbox Setup tab's DKIM row.
 *
 * @version 1.5
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));

use Mailgun\Mailgun;

class MailgunProvider implements EmailServiceProvider, InboundEmailProvider, ApiSubmissionRelay, DkimRecordSource {

    /** @var array<string,string> Per-request cache: sending domain => account state ('' = not in account / lookup failed). */
    private static $sending_domain_state = [];

    public static function getKey(): string {
        return 'mailgun';
    }

    /** The configured Mailgun SDK client (honors the EU API link when set). */
    private static function client(): Mailgun {
        $settings = Globalvars::get_instance();
        $eu_link = $settings->get_setting('mailgun_eu_api_link');
        return $eu_link
            ? Mailgun::create($settings->get_setting('mailgun_api_key'), $eu_link)
            : Mailgun::create($settings->get_setting('mailgun_api_key'));
    }

    public static function getLabel(): string {
        return 'Mailgun';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:mailgun.org';
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
            $text = $message->getTextBody();
            if ($text !== null && $text !== '') {
                $email_to_send['text'] = $text;
            }
        } else {
            $email_to_send['text'] = $message->getTextBody();
        }

        // Cc / Bcc, custom headers (Message-Id / In-Reply-To / References for
        // threaded reply-forward), reply-to, and attachments — so a relay send
        // carries the same envelope as the SMTP path, never silently dropping them.
        $this->applyExtras($email_to_send, $message);

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

    /**
     * Stamp Cc/Bcc, reply-to, custom headers, and attachments onto a Mailgun
     * array-format send payload. Custom headers become Mailgun "h:" params (so
     * Message-Id / In-Reply-To / References ride along for threaded mail);
     * attachments map to Mailgun's attachment file specs (filePath for on-disk,
     * fileContent for in-memory bytes from attachData()).
     */
    private function applyExtras(array &$payload, EmailMessage $message): void {
        $cc = $message->getCc();
        if (!empty($cc)) {
            $payload['cc'] = implode(',', array_map(array($this, 'formatAddress'), $cc));
        }
        $bcc = $message->getBcc();
        if (!empty($bcc)) {
            $payload['bcc'] = implode(',', array_map(array($this, 'formatAddress'), $bcc));
        }
        if ($message->getReplyTo()) {
            $payload['h:Reply-To'] = $message->getReplyTo();
        }
        if ($message->getMessageId()) {
            $payload['h:Message-Id'] = $message->getMessageId();
        }
        foreach ($message->getHeaders() as $name => $value) {
            $payload['h:' . $name] = $value;
        }

        $files = array();
        $inline = array();
        foreach ($message->getAttachments() as $attachment) {
            if (isset($attachment['data']) && !empty($attachment['cid'])) {
                // Inline (embedded) image: Mailgun references an inline part by its
                // filename, so the on-wire filename IS the cid the body points at
                // (cid:<cid>). Sent in the separate 'inline' field.
                $inline[] = array('fileContent' => $attachment['data'], 'filename' => $attachment['cid']);
            } elseif (isset($attachment['data'])) {
                $files[] = array('fileContent' => $attachment['data'], 'filename' => $attachment['name']);
            } elseif (isset($attachment['path'])) {
                $files[] = array('filePath' => $attachment['path'], 'filename' => $attachment['name']);
            }
        }
        if (!empty($files)) {
            $payload['attachment'] = $files;
        }
        if (!empty($inline)) {
            $payload['inline'] = $inline;
        }
    }

    /** Render one EmailMessage recipient ['email','name'] as "Name <email>". */
    private function formatAddress(array $addr): string {
        $email = $addr['email'] ?? '';
        $name = trim((string)($addr['name'] ?? ''));
        return $name !== '' ? ($name . ' <' . $email . '>') : $email;
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

    // ── DkimRecordSource ────────────────────────────────────────────────

    /**
     * The DKIM records Mailgun requires for a sending domain: the sending DNS
     * records from the domains API whose name carries a _domainkey selector.
     * A 404 means the domain is not registered in the account.
     */
    public static function getDkimStatus(string $domain): array {
        try {
            $show = self::client()->domains()->show($domain);
        } catch (\Mailgun\Exception\HttpClientException $e) {
            return ($e->getCode() === 404)
                ? ['status' => 'not_registered', 'records' => []]
                : ['status' => 'unreachable', 'records' => []];
        } catch (\Throwable $e) {
            error_log('[MailgunProvider] getDkimStatus(' . $domain . ') failed: ' . $e->getMessage());
            return ['status' => 'unreachable', 'records' => []];
        }

        $records = [];
        foreach ($show->getOutboundDNSRecords() as $rec) {
            $name = (string)$rec->getName();
            if (stripos($name, '_domainkey') === false) {
                continue;
            }
            $records[] = [
                'type'  => strtoupper((string)$rec->getType()),
                'name'  => $name,
                'value' => (string)$rec->getValue(),
            ];
        }
        return ['status' => 'ok', 'records' => $records];
    }

    // ── Submission-domain alignment ─────────────────────────────────────

    /**
     * Which account domain a raw relay should submit through for a given
     * envelope sender. The API path domain is Mailgun's signing identity, so
     * submitting through the sender's own domain (when the account has it
     * active) makes the DKIM signature align with the From domain for DMARC.
     * Anything else — no domain, not in the account, not active, lookup
     * failure — falls back to the configured mailgun_domain, so a send never
     * breaks because the domains API hiccuped.
     */
    public static function apiDomainForSender(string $envelope_sender): string {
        $configured = (string)Globalvars::get_instance()->get_setting('mailgun_domain');
        $at = strrpos($envelope_sender, '@');
        $domain = ($at !== false) ? strtolower(rtrim(substr($envelope_sender, $at + 1), '.')) : '';
        if ($domain === '' || strcasecmp($domain, $configured) === 0) {
            return $configured;
        }
        if (!array_key_exists($domain, self::$sending_domain_state)) {
            $state = '';
            try {
                $d = self::client()->domains()->show($domain)->getDomain();
                $state = ($d && method_exists($d, 'getState')) ? strtolower((string)$d->getState()) : '';
            } catch (\Throwable $e) {
                // Not in the account, or the API did not answer — fall back.
            }
            self::$sending_domain_state[$domain] = $state;
        }
        return self::pickApiDomain($domain, self::$sending_domain_state[$domain], $configured);
    }

    /** Pure pick: an active account domain wins; anything else falls back. */
    public static function pickApiDomain(string $sender_domain, string $account_state, string $configured): string {
        return ($sender_domain !== '' && $account_state === 'active') ? $sender_domain : $configured;
    }

    // ── RawMessageRelay ─────────────────────────────────────────────────

    /**
     * Relay raw MIME via Mailgun's MIME endpoint (SDK sendMime), reusing the
     * same mailgun_api_key the send() path uses. Mailgun owns bounce handling
     * and its own SPF/DKIM align with the sending domain, so the SRS envelope
     * sender is best-effort here — the From-header rewrite the router already
     * performed is what carries deliverability. See specs and email_system.md.
     *
     * sendMime delivers to all recipients in one call, so the result is
     * all-or-nothing: every destination maps to the same success/failure,
     * matching the per-destination shape forwardEmail() expects.
     */
    public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
        $mg = self::client();

        // Submit through the envelope sender's own sending domain when the
        // account has it active — the API path domain is Mailgun's signing
        // identity, and this is what makes DKIM align with the From domain.
        $domain = self::apiDomainForSender($envelope_sender);

        // Best-effort envelope sender: Mailgun honors o:sender on the MIME
        // endpoint where it can; it otherwise owns the return-path.
        $params = [];
        if ($envelope_sender !== '') {
            $params['sender'] = $envelope_sender;
        }

        $ok = true;
        try {
            $mg->messages()->sendMime($domain, $destinations, $raw_mime, $params);
        } catch (\Exception $e) {
            error_log('[MailgunProvider] relayRawMessage failed: ' . $e->getMessage());
            $ok = false;
        }

        $results = [];
        foreach ($destinations as $destination) {
            $results[$destination] = $ok;
        }
        return $results;
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

        $out = [
            'raw_mime' => (string)$raw_mime,
            'recipient' => (string)$recipient,
        ];

        $auth = self::extractAuth((string)$raw_mime, $post);
        if ($auth !== null) {
            $out['auth'] = $auth;
        }

        $spam = self::extractSpam((string)$raw_mime);
        if ($spam !== null) {
            $out['spam'] = $spam;
        }

        return $out;
    }

    /**
     * Mailgun's own content-spam signal (specs/inbound_email_content_spam_filtering.md).
     * When the domain's spam filter is on, Mailgun stamps X-Mailgun-Sflag (its binary
     * decision: Yes/No) and X-Mailgun-Sscore (a numeric score). The flag is the
     * verdict; the score is recorded for transparency only. Returns
     * ['result'=>spam|ham, 'score'=>?float, 'source'=>'mailgun'] or null when absent.
     */
    private static function extractSpam(string $raw_mime): ?array {
        $flag  = self::extractMimeHeader($raw_mime, 'X-Mailgun-Sflag');
        $score = self::extractMimeHeader($raw_mime, 'X-Mailgun-Sscore');
        if ($flag === null && $score === null) {
            return null;
        }

        $out = ['source' => 'mailgun'];
        $out['result'] = ($flag !== null && strtolower(trim($flag)) === 'yes') ? 'spam' : 'ham';
        if ($score !== null && is_numeric(trim($score))) {
            $out['score'] = (float)trim($score);
        }
        return $out;
    }

    /**
     * Build the verdict array from Mailgun's X-Mailgun-* headers (present in the
     * stored MIME). The signature was already verified by the caller, so these
     * headers are trusted. Returns null only when neither verdict header is
     * present (then the router falls back to 'unverified' — never a fabricated
     * pass).
     *
     * Mailgun reports no DMARC verdict, so dmarc is always null (recorded 'none').
     */
    private static function extractAuth(string $raw_mime, array $post): ?array {
        $spf_raw  = self::extractMimeHeader($raw_mime, 'X-Mailgun-Spf');
        $dkim_raw = self::extractMimeHeader($raw_mime, 'X-Mailgun-Dkim-Check-Result');

        if ($spf_raw === null && $dkim_raw === null) {
            return null;
        }

        $spf_map = [
            'pass'     => 'pass',
            'neutral'  => 'neutral',
            'fail'     => 'fail',
            'softfail' => 'softfail',
            'none'     => 'none',
        ];
        $dkim_map = [
            'pass' => 'pass',
            'fail' => 'fail',
        ];

        $spf  = ($spf_raw  !== null) ? ($spf_map[strtolower(trim($spf_raw))]   ?? null) : null;
        $dkim = ($dkim_raw !== null) ? ($dkim_map[strtolower(trim($dkim_raw))] ?? null) : null;

        $auth = [
            'spf'    => $spf,
            'dkim'   => $dkim,
            'dmarc'  => null,
            'source' => 'mailgun',
        ];

        // Best-effort signing domains (omitted when not readily available).
        $sender = (string)($post['sender'] ?? '');
        if ($sender !== '' && strpos($sender, '@') !== false) {
            $auth['spf_domain'] = strtolower(trim(substr(strrchr($sender, '@'), 1)));
        }
        $dkim_sig = self::extractMimeHeader($raw_mime, 'DKIM-Signature');
        if ($dkim_sig !== null && preg_match('/\bd=\s*([^;\s]+)/i', $dkim_sig, $m)) {
            $auth['dkim_domain'] = strtolower(rtrim(trim($m[1]), '.'));
        }

        return $auth;
    }

    /**
     * Read the first occurrence of a header from raw MIME, unfolding RFC 5322
     * continuation lines. Case-insensitive; returns null when absent. Scans only
     * the header block (up to the first blank line).
     */
    private static function extractMimeHeader(string $raw_mime, string $name): ?string {
        $normalized = str_replace("\r\n", "\n", $raw_mime);
        $split = strpos($normalized, "\n\n");
        $block = ($split !== false) ? substr($normalized, 0, $split) : $normalized;

        $name = strtolower($name);
        $value = null;
        $collecting = false;

        foreach (explode("\n", $block) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                if ($collecting) {
                    $value .= ' ' . trim($line);
                }
                continue;
            }
            if ($collecting) {
                // We already captured our header and hit the next one — done.
                break;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            if (strtolower(trim(substr($line, 0, $colon))) === $name) {
                $value = trim(substr($line, $colon + 1));
                $collecting = true;
            }
        }

        return $value;
    }

    private static function makeResult($id, $scope, $layer, $label, $severity, $status, $summary, $detail = '', $fix = null, $recheckable = true): array {
        return [
            'id' => $id, 'scope' => $scope, 'layer' => $layer, 'label' => $label,
            'severity' => $severity, 'status' => $status, 'summary' => $summary,
            'detail' => $detail, 'fix' => $fix, 'recheckable' => $recheckable,
        ];
    }
}
