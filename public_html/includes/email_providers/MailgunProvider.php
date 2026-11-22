<?php
/**
 * MailgunProvider - Mailgun email service provider
 *
 * Implements EmailServiceProvider over Mailgun's v3 HTTP API, through
 * ProviderHttp: HTTP basic auth (user 'api', password the key), against
 * https://api.mailgun.net or the mailgun_eu_api_link when set. Mailgun
 * answers success with 200, 201 or 202.
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
 * Implements SendingDomainRegistrar: the same API can create a sending domain,
 * which is what makes the machine sender ceremony's register step a button.
 *
 * @version 1.13 - recipient-variables that cannot be encoded fail the chunk rather than going out empty
 *   (B13); every file part carries its own Content-Disposition, so a cid or name is sent whole and
 *   quoted, never basename()'d (B18); the pre-send domain lookup is asked once, no retries (B21); a
 *   batch leaves out the pinned Message-Id, which one recipient's copy alone can carry (B15)
 * @version 1.12 - requests go through ProviderHttp, no Mailgun SDK (specs/email_provider_sdk_removal.md WP4);
 *   one field builder serves send() and sendBatch(), so a batch carries Reply-To, custom headers,
 *   attachments and the text part (B2); a raw relay no longer sends a 'sender' parameter messages.mime
 *   does not have, the envelope sender is Mailgun's (B4); an attachment that yields no bytes fails the
 *   send (B6); a new sending domain is created with 2048-bit DKIM and https tracking links; operator
 *   errors are in our own words; a domain whose events cannot be read is not counted as asked
 * @version 1.11 - send() and sendBatch() go through the From domain when the account has it active, so
 *   the signature matches the From address (B169); dkimSigningDomain() says which domain signs
 * @version 1.10 - getSendingDomainError(): why the last sending-domain lookup failed
 * @version 1.9 - SendReceiptSource (the send response's id) and DeliveryEventSource (the
 *   Events API by Message-ID: accepted, delivered, deferred, failed, with the receiving
 *   server's own words) — specs/mailbox_message_timeline.md A3/A4
 * @version 1.8 - SingleKeyProvider: declares the shape of its API keys
 * @version 1.7
 * @changelog 1.7 - verifySendingDomain(): asks Mailgun to re-check a sending
 *   domain's DNS now and reports the fresh state, so a wizard's Check button
 *   is not at the mercy of Mailgun's own re-check schedule.
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));

class MailgunProvider implements EmailServiceProvider, InboundEmailProvider, ApiSubmissionRelay, DkimRecordSource, SendingDomainRegistrar, SingleKeyProvider, SendReceiptSource, DeliveryEventSource {

    const DEFAULT_API_BASE = 'https://api.mailgun.net';

    /** Recipients per send call (Mailgun's limit). */
    const CHUNK = 500;

    /** @var ?array the send response of the last accepted send() — see lastSendReceipt() */
    private $last_receipt = null;

    /** @var array<string,string> Per-request cache: sending domain => account state ('' = not in account / lookup failed). */
    private static $sending_domain_state = [];
    /** @var array<string,string> Per-request cache of domain => why the state lookup failed ('' = it did not). */
    private static $sending_domain_error = [];

    public static function getKey(): string {
        return 'mailgun';
    }

    public static function getLabel(): string {
        return 'Mailgun';
    }

    /** The older key- form and the current 32-8-8 hex form. */
    public static function apiKeyPattern(): string {
        return '/^(key-[0-9a-f]{32}|[0-9a-f]{32}-[0-9a-f]{8}-[0-9a-f]{8})$/i';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:mailgun.org';
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

    // ── Transport ───────────────────────────────────────────────────────

    /**
     * One API call. An HTTP error status comes back for the caller to read;
     * only a failure with no response throws (ProviderHttpException).
     * $retry_5xx is for reads: a 5xx on a send may mean it was accepted.
     */
    private static function call(string $method, string $path, array $options = [], bool $retry_5xx = false, int $retries = 2): ProviderHttpResponse {
        $settings = Globalvars::get_instance();
        $base = trim((string)$settings->get_setting('mailgun_eu_api_link'));
        if ($base === '') {
            $base = self::DEFAULT_API_BASE;
        }
        $options['auth'] = ['api', (string)$settings->get_setting('mailgun_api_key')];
        $options['retries'] = $retries;
        $options['retry_5xx'] = $retry_5xx;
        return ProviderHttp::request($method, rtrim($base, '/') . $path, $options);
    }

    /** Mailgun counts 200, 201 and 202 as success. */
    private static function succeeded(ProviderHttpResponse $resp): bool {
        return in_array($resp->status, [200, 201, 202], true);
    }

    /**
     * What a failed answer means, in words an operator can act on. $about_domain
     * says the request named a domain, so a 404 means the account lacks it.
     */
    private static function describeError(ProviderHttpResponse $resp, bool $about_domain = false): string {
        if ($resp->status === 401) {
            return 'Mailgun rejected the API key.';
        }
        if ($resp->status === 404 && $about_domain) {
            return 'This domain is not in the Mailgun account.';
        }
        $json = $resp->json();
        $message = is_array($json) && isset($json['message']) && is_string($json['message'])
            ? $json['message'] : trim(substr(strip_tags($resp->body), 0, 200));
        return 'Mailgun answered ' . $resp->status . ($message !== '' ? ': ' . $message : '');
    }

    /**
     * GET /v3/domains/{domain}. $retries is 0 for the lookup a send makes
     * before it starts: that answer only picks a domain, and a send must not
     * wait out backoff on a domains API that is down.
     */
    private static function showDomain(string $domain, int $retries = 2): ProviderHttpResponse {
        return self::call('GET', '/v3/domains/' . rawurlencode($domain), [], true, $retries);
    }

    /** A domain show answer's state, lowercased, or ''. */
    private static function stateOf(ProviderHttpResponse $resp): string {
        $json = $resp->json();
        return strtolower((string)($json['domain']['state'] ?? ''));
    }

    // ── Validation ──────────────────────────────────────────────────────

    /**
     * Optional: Live API validation for admin settings panel.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('mailgun_api_key');
        $domain = (string)$settings->get_setting('mailgun_domain');

        if (empty($api_key) || $domain === '') {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter API key and domain to validate connection',
            ];
        }

        try {
            $resp = self::showDomain($domain);
        } catch (ProviderHttpException $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => 'Mailgun did not answer: ' . $e->getMessage(),
            ];
        }

        if (self::succeeded($resp)) {
            $details = ['Domain' => $domain];
            $d = $resp->json()['domain'] ?? null;
            if (is_array($d)) {
                if (isset($d['name'])) {
                    $details['Name'] = (string)$d['name'];
                }
                if (isset($d['state'])) {
                    $details['Status'] = (string)$d['state'];
                }
            }
            return [
                'success' => true,
                'label' => 'API Key Valid',
                'details' => $details,
                'error' => null,
            ];
        }

        $error = self::describeError($resp, true);
        $details = ['Configured Domain' => $domain];

        // A key that works but a domain the account lacks: suggest a near match.
        if ($resp->status !== 401) {
            $suggested = null;
            try {
                $list = self::call('GET', '/v3/domains', ['query' => ['limit' => 100, 'skip' => 0]], true);
                if (self::succeeded($list)) {
                    $entered_lower = strtolower($domain);
                    foreach ((array)($list->json()['items'] ?? []) as $item) {
                        $acct_name = strtolower((string)($item['name'] ?? ''));
                        if ($acct_name !== '' && (stripos($entered_lower, $acct_name) !== false || stripos($acct_name, $entered_lower) !== false)) {
                            $suggested = (string)$item['name'];
                            break;
                        }
                    }
                }
            } catch (ProviderHttpException $e) {
                // Couldn't list domains, skip suggestion
            }
            if ($suggested) {
                $details['Suggested Domain'] = $suggested;
                $error = 'Domain not found. Did you mean: ' . $suggested;
            }
        }

        return [
            'success' => false,
            'label' => 'Mailgun Validation Failed',
            'details' => $details,
            'error' => $error,
        ];
    }

    // ── Sending ─────────────────────────────────────────────────────────

    public function send(EmailMessage $message): bool {
        $this->last_receipt = null;
        try {
            $fields = $this->buildFields($message);
        } catch (RuntimeException $e) {
            error_log('[MailgunProvider] Send failed: ' . $e->getMessage());
            return false;
        }

        // The From domain when the account has it active, so the signature
        // matches the From address for DMARC (B169); else the configured domain.
        $domain = self::apiDomainForSender((string)$message->getFrom());

        $all_sent = true;
        foreach (array_chunk($message->getRecipients(), self::CHUNK, true) as $group) {
            $to = [];
            $vars = [];
            foreach ($group as $recipient) {
                $to[] = $this->formatAddress($recipient);
                $vars[$recipient['email']] = ['name' => $recipient['name'] ?? ''];
            }
            $response = $this->postMessage($domain, $fields, $to, $vars);
            if ($response === null) {
                $all_sent = false;
                continue;
            }
            // A compose is one chunk; on a batch the receipt is the last chunk's.
            $json = $response->json() ?? [];
            $this->last_receipt = array(
                'id'       => isset($json['id']) ? trim((string)$json['id'], '<> ') : null,
                'response' => isset($json['message']) ? (string)$json['message'] : null,
            );
        }

        return $all_sent;
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        try {
            // No pinned Message-Id: each recipient gets their own message,
            // and one ID shared by all of them would make them one message.
            $fields = $this->buildFields($message, false);
        } catch (RuntimeException $e) {
            error_log('[MailgunProvider] Batch send failed: ' . $e->getMessage());
            return ['success' => false, 'failed_recipients' => array_values($recipients)];
        }

        // The From domain when the account has it active, so the signature
        // matches the From address for DMARC (B169); else the configured domain.
        $domain = self::apiDomainForSender((string)$message->getFrom());

        $failed_recipients = [];
        foreach (array_chunk(array_values($recipients), self::CHUNK) as $group) {
            $vars = [];
            foreach ($group as $email) {
                $vars[$email] = ['name' => $email];
            }
            if ($this->postMessage($domain, $fields, $group, $vars) === null) {
                // The entire chunk fails together.
                $failed_recipients = array_merge($failed_recipients, $group);
            }
        }

        return [
            'success' => empty($failed_recipients),
            'failed_recipients' => $failed_recipients,
        ];
    }

    public function lastSendReceipt(): ?array {
        return $this->last_receipt;
    }

    /**
     * One POST /v3/{domain}/messages for one chunk of recipients. The chunk's
     * To line and recipient-variables join the shared fields. Returns the
     * response when Mailgun accepted it, null (logged) when not.
     */
    private function postMessage(string $domain, array $fields, array $to, array $vars): ?ProviderHttpResponse {
        // recipient-variables is what makes Mailgun send each address its own
        // copy. A chunk never goes without it: a name that is not valid UTF-8
        // is substituted, and anything else that cannot be encoded fails the
        // chunk rather than sending every recipient one shared To line.
        $encoded = json_encode($vars, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            error_log('[MailgunProvider] Send failed: recipient-variables could not be encoded: ' . json_last_error_msg());
            return null;
        }
        $fields[] = ['name' => 'to', 'contents' => implode(',', $to)];
        $fields[] = ['name' => 'recipient-variables', 'contents' => $encoded];
        try {
            $resp = self::call('POST', '/v3/' . rawurlencode($domain) . '/messages', ['multipart' => $fields]);
        } catch (ProviderHttpException $e) {
            error_log('[MailgunProvider] Send failed: ' . $e->getMessage());
            return null;
        }
        if (!self::succeeded($resp)) {
            error_log('[MailgunProvider] Send failed: ' . self::describeError($resp, true));
            return null;
        }
        return $resp;
    }

    /**
     * Every multipart field of a send but the recipients: from, subject, the
     * html and text parts, Cc/Bcc, Reply-To, the pinned Message-Id, custom
     * headers as h: fields (In-Reply-To / References ride along for threaded
     * mail), and the files. send() and sendBatch() both use it, so a batch
     * carries everything a single send does.
     *
     * Each attachment is an 'attachment' file part; an inline part is an
     * 'inline' file part whose filename IS the cid the body points at
     * (cid:<cid>), which is how Mailgun references it. Every file part has a
     * filename: Guzzle makes a file part only when it has one.
     *
     * @throws RuntimeException when an attachment yields no bytes
     */
    private function buildFields(EmailMessage $message, bool $with_message_id = true): array {
        $fields = [];
        $add = function (string $name, $value) use (&$fields) {
            $fields[] = ['name' => $name, 'contents' => (string)$value];
        };

        $add('from', $this->formatAddress(['email' => (string)$message->getFrom(), 'name' => (string)$message->getFromName()]));
        $add('subject', (string)$message->getSubject());
        if ($message->getHtmlBody()) {
            $add('html', $message->getHtmlBody());
            $text = $message->getTextBody();
            if ($text !== null && $text !== '') {
                $add('text', $text);
            }
        } else {
            $add('text', (string)$message->getTextBody());
        }

        $cc = $message->getCc();
        if (!empty($cc)) {
            $add('cc', implode(',', array_map(array($this, 'formatAddress'), $cc)));
        }
        $bcc = $message->getBcc();
        if (!empty($bcc)) {
            $add('bcc', implode(',', array_map(array($this, 'formatAddress'), $bcc)));
        }
        if ($message->getReplyTo()) {
            $add('h:Reply-To', $message->getReplyTo());
        }
        if ($with_message_id && $message->getMessageId()) {
            $add('h:Message-Id', $message->getMessageId());
        }
        foreach ($message->getHeaders() as $name => $value) {
            $add('h:' . $name, $value);
        }

        foreach ($message->getAttachments() as $attachment) {
            $content = EmailMessage::attachmentContent($attachment);
            $inline = !empty($attachment['cid']);
            $part = $inline ? 'inline' : 'attachment';
            $filename = $inline ? (string)$attachment['cid'] : $content['name'];
            $fields[] = [
                'name'     => $part,
                'contents' => $content['bytes'],
                'filename' => $filename,
                'headers'  => [
                    'Content-Type'        => $content['type'],
                    'Content-Disposition' => self::fileDisposition($part, $filename),
                ],
            ];
        }
        return $fields;
    }

    /**
     * A file part's Content-Disposition, written here rather than by Guzzle:
     * Guzzle's own runs basename() on the filename, which cuts a cid holding
     * a slash, and does not escape a quote. The filename is quoted per RFC
     * 7578 (backslash and quote escaped, line breaks dropped).
     */
    private static function fileDisposition(string $part, string $filename): string {
        $filename = str_replace(["\r", "\n"], '', $filename);
        $filename = addcslashes($filename, '"\\');
        return 'form-data; name="' . $part . '"; filename="' . $filename . '"';
    }

    /** Render one EmailMessage recipient ['email','name'] as "Name <email>". */
    private function formatAddress(array $addr): string {
        $email = $addr['email'] ?? '';
        $name = trim((string)($addr['name'] ?? ''));
        return $name !== '' ? ($name . ' <' . $email . '>') : $email;
    }

    // ── DeliveryEventSource ─────────────────────────────────────────────

    /**
     * Mailgun's Events API, filtered to one Message-ID. A compose send() submits
     * through the configured mailgun_domain, a raw relay through the sender's
     * own active domain (apiDomainForSender), so both are asked, configured
     * first; the first domain with events answers. A domain the key may not read
     * (Mailgun scopes keys per domain) is skipped, not fatal: only a 2xx counts
     * as asked, so a 401 is "not asked", never "asked, no events". The lookup is
     * null only when no domain could be asked at all.
     */
    public function deliveryEvents(string $message_id_header, string $from_domain): ?array {
        $settings = Globalvars::get_instance();
        if ((string)$settings->get_setting('mailgun_api_key') === '') {
            return null;
        }
        $message_id = trim($message_id_header, '<> ');
        if ($message_id === '') {
            return null;
        }

        $configured = (string)$settings->get_setting('mailgun_domain');
        $relay_domain = self::apiDomainForSender('timeline@' . $from_domain);
        $domains = array_values(array_unique(array_filter(array($configured, $relay_domain))));

        $events = array();
        $asked = 0;
        foreach ($domains as $domain) {
            try {
                $resp = self::call('GET', '/v3/' . rawurlencode($domain) . '/events',
                    ['query' => ['message-id' => $message_id, 'limit' => 100]], true);
            } catch (ProviderHttpException $e) {
                error_log('[MailgunProvider] events lookup failed for ' . $domain . ': ' . $e->getMessage());
                continue;
            }
            if (!self::succeeded($resp)) {
                error_log('[MailgunProvider] events lookup failed for ' . $domain . ': ' . self::describeError($resp, true));
                continue;
            }
            $asked++;
            foreach ((array)($resp->json()['items'] ?? array()) as $item) {
                if (is_array($item)) {
                    $events[] = self::describeEvent($item);
                }
            }
            if ($events) {
                break;
            }
        }
        if ($asked === 0) {
            return null;
        }

        usort($events, function ($a, $b) { return strcmp($a['time'], $b['time']); });
        return array('status' => self::worstStatus($events), 'events' => $events);
    }

    /** One Mailgun event (the API's JSON item) as the timeline's neutral shape. */
    private static function describeEvent(array $item): array {
        $event = strtolower((string)($item['event'] ?? ''));
        $delivery = is_array($item['delivery-status'] ?? null) ? $item['delivery-status'] : array();
        $reason = (string)($item['reason'] ?? '');
        $detail = '';
        if (!empty($delivery['message'])) {
            $detail = (string)$delivery['message'];
        } elseif (!empty($delivery['description'])) {
            $detail = (string)$delivery['description'];
        } elseif ($reason !== '') {
            $detail = $reason;
        }
        if (!empty($delivery['code']) && $detail !== '' && strpos($detail, (string)$delivery['code']) !== 0) {
            $detail = $delivery['code'] . ' ' . $detail;
        }
        // Mailgun distinguishes a bounce it will retry from one it will not.
        if ($event === 'failed') {
            $event = (strtolower((string)($item['severity'] ?? '')) === 'temporary') ? 'deferred' : 'failed';
        } elseif ($event === 'rejected') {
            $event = 'failed';
        }
        return array(
            // Mailgun's timestamp is a float (seconds with a fraction).
            'time'      => gmdate('Y-m-d H:i:s', (int)($item['timestamp'] ?? 0)),
            'event'     => $event,
            'recipient' => (string)($item['recipient'] ?? ''),
            'detail'    => trim($detail),
        );
    }

    /**
     * The status a set of events adds up to. Each recipient's LATEST event is
     * where that recipient stands (a deferral followed by a delivery is a
     * delivery); across recipients the worst stands for the message:
     * failed > deferred > delivered > accepted > unknown. Events must be in
     * time order.
     */
    public static function worstStatus(array $events): string {
        $rank = array(
            self::DELIVERY_UNKNOWN => 0, self::DELIVERY_ACCEPTED => 1, self::DELIVERY_DELIVERED => 2,
            self::DELIVERY_DEFERRED => 3, self::DELIVERY_FAILED => 4,
        );
        $latest = array();
        foreach ($events as $e) {
            $latest[strtolower((string)$e['recipient'])] = isset($rank[$e['event']]) ? $e['event'] : self::DELIVERY_UNKNOWN;
        }
        $status = self::DELIVERY_UNKNOWN;
        foreach ($latest as $candidate) {
            if ($rank[$candidate] > $rank[$status]) {
                $status = $candidate;
            }
        }
        return $status;
    }


    // ── DkimRecordSource ────────────────────────────────────────────────

    /**
     * The DKIM records Mailgun requires for a sending domain: the sending DNS
     * records from the domains API whose name carries a _domainkey selector.
     * A 404 means the domain is not registered in the account.
     */
    public static function getDkimStatus(string $domain): array {
        try {
            $resp = self::showDomain($domain);
        } catch (ProviderHttpException $e) {
            error_log('[MailgunProvider] getDkimStatus(' . $domain . ') failed: ' . $e->getMessage());
            return ['status' => 'unreachable', 'records' => []];
        }
        if ($resp->status === 404) {
            return ['status' => 'not_registered', 'records' => []];
        }
        if (!self::succeeded($resp)) {
            return ['status' => 'unreachable', 'records' => []];
        }

        $records = [];
        foreach ((array)($resp->json()['sending_dns_records'] ?? []) as $rec) {
            $name = (string)($rec['name'] ?? '');
            if (stripos($name, '_domainkey') === false) {
                continue;
            }
            $records[] = [
                'type'  => strtoupper((string)($rec['record_type'] ?? '')),
                'name'  => $name,
                'value' => (string)($rec['value'] ?? ''),
            ];
        }
        return ['status' => 'ok', 'records' => $records];
    }

    // ── SendingDomainRegistrar ──────────────────────────────────────────

    /**
     * Create $domain as a sending domain in the account. Idempotent — an
     * existing registration is 'ok'. DKIM authority is forced to $domain
     * itself so the keys are issued for the subdomain rather than inherited
     * from its parent; inherited authority breaks strict DMARC alignment.
     * The key is 2048-bit, as the platform's own DKIM keys are, and tracking
     * links use https.
     */
    public static function createSendingDomain(string $domain): array {
        try {
            $show = self::showDomain($domain);
        } catch (ProviderHttpException $e) {
            return ['status' => 'unreachable', 'error' => 'Mailgun did not answer: ' . $e->getMessage()];
        }
        if (self::succeeded($show)) {
            return ['status' => 'ok']; // already registered
        }
        if ($show->status !== 404) {
            return ['status' => 'unreachable', 'error' => self::describeError($show, true)];
        }
        // 404 — not registered yet; create it.
        try {
            $resp = self::call('POST', '/v3/domains', ['form' => [
                'name'                 => $domain,
                'force_dkim_authority' => 'true',
                'dkim_key_size'        => '2048',
                'web_scheme'           => 'https',
            ]]);
        } catch (ProviderHttpException $e) {
            error_log('[MailgunProvider] createSendingDomain(' . $domain . ') failed: ' . $e->getMessage());
            return ['status' => 'error', 'error' => 'Mailgun did not answer: ' . $e->getMessage()];
        }
        if (!self::succeeded($resp)) {
            $error = self::describeError($resp);
            error_log('[MailgunProvider] createSendingDomain(' . $domain . ') failed: ' . $error);
            return ['status' => 'error', 'error' => $error];
        }
        unset(self::$sending_domain_state[$domain]); // state cache is now stale
        return ['status' => 'ok'];
    }

    /**
     * Ask Mailgun to re-check a sending domain's DNS right now and report the
     * fresh state (same vocabulary as getSendingDomainState()). Without this,
     * a just-published record sits 'unverified' until Mailgun's own re-check
     * schedule comes around. Never throws.
     */
    public static function verifySendingDomain(string $domain): string {
        try {
            $resp = self::call('PUT', '/v3/domains/' . rawurlencode($domain) . '/verify');
            if ($resp->status === 404) {
                self::$sending_domain_state[$domain] = 'not_registered';
                return 'not_registered';
            }
            if (!self::succeeded($resp)) {
                error_log('[MailgunProvider] verifySendingDomain(' . $domain . ') failed: ' . self::describeError($resp, true));
            }
        } catch (ProviderHttpException $e) {
            error_log('[MailgunProvider] verifySendingDomain(' . $domain . ') failed: ' . $e->getMessage());
        }
        unset(self::$sending_domain_state[$domain]);
        return self::getSendingDomainState($domain);
    }

    /**
     * The account's reported state for a sending domain ('active',
     * 'unverified', 'disabled', ...), 'not_registered' when the account does
     * not have it, or '' when the API did not answer. Shares the per-request
     * cache alignment lookups use.
     */
    public static function getSendingDomainState(string $domain): string {
        if (!array_key_exists($domain, self::$sending_domain_state)) {
            $state = '';
            $error = '';
            try {
                $resp = self::showDomain($domain);
                if (self::succeeded($resp)) {
                    $state = self::stateOf($resp);
                } elseif ($resp->status === 404) {
                    $state = 'not_registered';
                } else {
                    $error = self::describeError($resp, true);
                }
            } catch (ProviderHttpException $e) {
                // API did not answer — leave ''.
                $error = 'Mailgun did not answer: ' . $e->getMessage();
            }
            self::$sending_domain_state[$domain] = $state;
            self::$sending_domain_error[$domain] = $error;
        }
        return self::$sending_domain_state[$domain];
    }

    /**
     * Why getSendingDomainState($domain) answered '', or '' when the last
     * lookup succeeded. Runs the lookup if nothing has asked yet this request.
     */
    public static function getSendingDomainError(string $domain): string {
        self::getSendingDomainState($domain);
        return self::$sending_domain_error[$domain] ?? '';
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
                $resp = self::showDomain($domain, 0);
                $state = self::succeeded($resp) ? self::stateOf($resp) : '';
            } catch (ProviderHttpException $e) {
                // The API did not answer — fall back.
            }
            self::$sending_domain_state[$domain] = $state;
        }
        return self::pickApiDomain($domain, self::$sending_domain_state[$domain], $configured);
    }

    /**
     * The domain Mailgun signs a send from $from_domain as: the domain the
     * send goes through (apiDomainForSender), whose DKIM authority is itself.
     */
    public static function dkimSigningDomain(string $from_domain): ?string {
        $domain = strtolower(self::apiDomainForSender('x@' . $from_domain));
        return $domain !== '' ? $domain : null;
    }

    /** Pure pick: an active account domain wins; anything else falls back. */
    public static function pickApiDomain(string $sender_domain, string $account_state, string $configured): string {
        return ($sender_domain !== '' && $account_state === 'active') ? $sender_domain : $configured;
    }

    // ── RawMessageRelay ─────────────────────────────────────────────────

    /**
     * Relay raw MIME via Mailgun's messages.mime endpoint, reusing the same
     * mailgun_api_key the send() path uses: one 'to' field per destination and
     * the message as a file part named 'message'. The message always travels
     * as content, never as a path to read.
     *
     * messages.mime has no envelope-sender parameter: Mailgun sets the
     * return-path itself and owns bounce handling, and its SPF/DKIM align with
     * the sending domain. $envelope_sender therefore only chooses the domain
     * the relay submits through (apiDomainForSender); the From-header rewrite
     * the router already performed is what carries deliverability.
     *
     * One call delivers to every destination, so the result is all-or-nothing:
     * every destination maps to the same success/failure, matching the
     * per-destination shape forwardEmail() expects.
     */
    public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
        // Submit through the envelope sender's own sending domain when the
        // account has it active — the API path domain is Mailgun's signing
        // identity, and this is what makes DKIM align with the From domain.
        $domain = self::apiDomainForSender($envelope_sender);

        $fields = [];
        foreach ($destinations as $destination) {
            $fields[] = ['name' => 'to', 'contents' => (string)$destination];
        }
        $fields[] = ['name' => 'message', 'contents' => $raw_mime, 'filename' => 'message',
            'headers' => ['Content-Type' => 'message/rfc822']];

        $ok = false;
        try {
            $resp = self::call('POST', '/v3/' . rawurlencode($domain) . '/messages.mime', ['multipart' => $fields]);
            $ok = self::succeeded($resp);
            if (!$ok) {
                error_log('[MailgunProvider] relayRawMessage failed: ' . self::describeError($resp, true));
            }
        } catch (ProviderHttpException $e) {
            error_log('[MailgunProvider] relayRawMessage failed: ' . $e->getMessage());
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
