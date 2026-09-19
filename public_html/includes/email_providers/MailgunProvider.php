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
 * Implements SendingDomainRegistrar: the same API can create a sending domain,
 * which is what makes the machine sender ceremony's register step a button.
 *
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

use Mailgun\Mailgun;

class MailgunProvider implements EmailServiceProvider, InboundEmailProvider, ApiSubmissionRelay, DkimRecordSource, SendingDomainRegistrar, SingleKeyProvider, SendReceiptSource, DeliveryEventSource {

    /** @var ?array the send response of the last accepted send() — see lastSendReceipt() */
    private $last_receipt = null;

    /** @var array<string,string> Per-request cache: sending domain => account state ('' = not in account / lookup failed). */
    private static $sending_domain_state = [];
    /** @var array<string,string> Per-request cache of domain => why the state lookup failed ('' = it did not). */
    private static $sending_domain_error = [];

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
        $this->last_receipt = null;

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
                $response = $mg->messages()->send($domain, $email_to_send);
                // A compose is one chunk; on a batch the receipt is the last chunk's.
                $this->last_receipt = array(
                    'id'       => method_exists($response, 'getId') ? trim((string)$response->getId(), '<> ') : null,
                    'response' => method_exists($response, 'getMessage') ? (string)$response->getMessage() : null,
                );
            } catch (\Exception $e) {
                error_log("[MailgunProvider] Send failed: " . $e->getMessage());
                $all_sent = false;
            }
        }

        return $all_sent;
    }

    public function lastSendReceipt(): ?array {
        return $this->last_receipt;
    }

    // ── DeliveryEventSource ─────────────────────────────────────────────

    /**
     * Mailgun's Events API, filtered to one Message-ID. A compose send() submits
     * through the configured mailgun_domain, a raw relay through the sender's
     * own active domain (apiDomainForSender), so both are asked, configured
     * first; the first domain with events answers. A domain the key may not read
     * (Mailgun scopes keys per domain) is skipped, not fatal — the lookup is
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

        $mg = self::client();
        $events = array();
        $asked = 0;
        foreach ($domains as $domain) {
            try {
                $page = $mg->events()->get($domain, array('message-id' => $message_id, 'limit' => 100));
            } catch (\Throwable $e) {
                error_log('[MailgunProvider] events lookup failed for ' . $domain . ': ' . $e->getMessage());
                continue;
            }
            $asked++;
            foreach ($page->getItems() as $item) {
                $events[] = self::describeEvent($item);
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

    /** One Mailgun event as the timeline's neutral shape. */
    private static function describeEvent($item): array {
        $event = strtolower((string)$item->getEvent());
        $delivery = $item->getDeliveryStatus();
        $detail = '';
        if (!empty($delivery['message'])) {
            $detail = (string)$delivery['message'];
        } elseif (!empty($delivery['description'])) {
            $detail = (string)$delivery['description'];
        } elseif ($item->getReason() !== '') {
            $detail = (string)$item->getReason();
        }
        if (!empty($delivery['code']) && $detail !== '' && strpos($detail, (string)$delivery['code']) !== 0) {
            $detail = $delivery['code'] . ' ' . $detail;
        }
        // Mailgun distinguishes a bounce it will retry from one it will not.
        if ($event === 'failed') {
            $event = (strtolower((string)$item->getSeverity()) === 'temporary') ? 'deferred' : 'failed';
        } elseif ($event === 'rejected') {
            $event = 'failed';
        }
        return array(
            'time'      => gmdate('Y-m-d H:i:s', $item->getTimestamp()),
            'event'     => $event,
            'recipient' => (string)$item->getRecipient(),
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

    // ── SendingDomainRegistrar ──────────────────────────────────────────

    /**
     * Create $domain as a sending domain in the account. Idempotent — an
     * existing registration is 'ok'. DKIM authority is forced to $domain
     * itself so the keys are issued for the subdomain rather than inherited
     * from its parent; inherited authority breaks strict DMARC alignment.
     */
    public static function createSendingDomain(string $domain): array {
        try {
            self::client()->domains()->show($domain);
            return ['status' => 'ok']; // already registered
        } catch (\Mailgun\Exception\HttpClientException $e) {
            if ($e->getCode() !== 404) {
                return ['status' => 'unreachable', 'error' => $e->getMessage()];
            }
            // 404 — not registered yet; fall through to create.
        } catch (\Throwable $e) {
            return ['status' => 'unreachable', 'error' => $e->getMessage()];
        }
        try {
            self::client()->domains()->create($domain, null, null, null, true /* forceDkimAuthority */);
            unset(self::$sending_domain_state[$domain]); // state cache is now stale
            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            error_log('[MailgunProvider] createSendingDomain(' . $domain . ') failed: ' . $e->getMessage());
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Ask Mailgun to re-check a sending domain's DNS right now and report the
     * fresh state (same vocabulary as getSendingDomainState()). Without this,
     * a just-published record sits 'unverified' until Mailgun's own re-check
     * schedule comes around. Never throws.
     */
    public static function verifySendingDomain(string $domain): string {
        try {
            self::client()->domains()->verify($domain);
        } catch (\Mailgun\Exception\HttpClientException $e) {
            if ($e->getCode() === 404) {
                self::$sending_domain_state[$domain] = 'not_registered';
                return 'not_registered';
            }
        } catch (\Throwable $e) {
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
                $d = self::client()->domains()->show($domain)->getDomain();
                $state = ($d && method_exists($d, 'getState')) ? strtolower((string)$d->getState()) : '';
            } catch (\Mailgun\Exception\HttpClientException $e) {
                if ($e->getCode() === 404) {
                    $state = 'not_registered';
                } else {
                    $error = 'Mailgun did not answer (' . $e->getCode() . '): ' . $e->getMessage();
                }
            } catch (\Throwable $e) {
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
