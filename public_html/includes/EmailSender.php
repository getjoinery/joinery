<?php
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/EmailTemplateRenderer.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/MailIdentityGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException (the locked-state signal)

require_once(PathHelper::getIncludePath('data/mail_send_outcomes_class.php'));

/**
 * EmailSender - the one outbound mail pipeline: egress assertions, dry-run and
 * test-mode guards, Joinery Direct first, then the injected transport or the
 * configured service with its fallback.
 *
 * @version 2.1 - canSendMail(): a sender address that may send and a configured provider, the one question the
 *                sign-in and password reset pages ask before offering a mailed reset
 * @version 2.0 - the outbox (specs/email_outbox.md): every recorded email has its row in equ_queued_emails,
 *   written before it is sent and settled after; deliver() holds Direct and the transports; queue(),
 *   sendTemplateOnce(), forgetOnce(), onceKeys() and the $capture test hook; a failed email is retried with
 *   or without a fallback (B3), whole (B6); sendBatch() keeps one row per recipient; the debug email log is gone
 * @version 1.9 - queueForRetry() catches any Throwable, so a refused retry row is logged, never thrown at the sender
 * @version 1.8 - sendBatch() honours email_dry_run, as send() does (it sent a test's batch to the trap)
 * @version 1.7 - EmailSender::$remote: inside a sandboxed plugin's fence, send(), sendBatch() and template
 *   rendering are carried to the site, which runs them with this same class (specs/sandboxed_plugin_email.md)
 * @version 1.6 - every send that leaves is counted per From-domain in MailSendOutcome: signed, unsigned or
 *   handed on, from the transport's note; a dry run is not counted, a test-mode send is counted as one
 * @version 1.5 - usesIncludedMail(): whether the site sends through the included Joinery email
 * @version 1.4 - quickSend() puts an HTML body in the blank inner template inside the default
 *   outer template; it had rendered the outer template as the inner one, which dropped the body
 * @version 1.3 - the retry queue and the refused-send event save as the server's own writes (no person owns them);
 *   a retry row with no display name is queued under the address, not refused
 * @version 1.2 - a refused protected-domain send logs its addresses, never its subject
 * @version 1.1 - lastSendReport(): which transport took the message, the carrier's
 *   receipt, which recipients Direct delivered, the error on refusal
 *   (specs/mailbox_message_timeline.md A3)
 */
/**
 * Where mail goes when this process cannot send it: a sandboxed plugin's
 * process, inside the fence, which has no database, settings or network. Each
 * call is carried to the site, which runs it with the real EmailSender and
 * EmailTemplateRenderer (utils/sandbox_runtime_mail.php). Never set on the
 * site: EmailSender::$remote is null there and nothing below changes.
 */
interface EmailRemote {
    /** send() on the site. @return array{sent: bool, report: array} */
    public function send(EmailMessage $message, bool $queue_on_failure): array;
    /** sendBatch() on the site. @return array{success: bool, failed_recipients: string[]} */
    public function sendBatch(EmailMessage $message, array $recipients): array;
    /** A template filled on the site. @return array{subject: ?string, html: ?string, text: ?string, has_content: bool, set_values: mixed} */
    public function render(string $inner, ?string $outer, $footer, array $values): array;
}

class EmailSender {

    /**
     * Set only inside a sandboxed plugin's fence (EmailRemote). Read it
     * through remote(), which also finds the fence's however this file was
     * loaded (by name, or by a require of its path).
     *
     * @var EmailRemote|null
     */
    public static $remote = null;

    /**
     * The fence's EmailRemote, or null on the site. The fence's runtime
     * (utils/sandbox_runtime.php, never loaded by the site) defines
     * joinery_fence_mail_remote(); it is asked here, at the moment of use,
     * so no load order can leave a send to run in the fence itself.
     */
    public static function remote(): ?EmailRemote {
        if (self::$remote === null && function_exists('joinery_fence_mail_remote')) {
            self::$remote = joinery_fence_mail_remote();
        }
        return self::$remote;
    }

    /**
     * Assertions a call site can make when sending from a process that has
     * opened sealed content (specs/implemented/sealed_content_egress.md, Layer 2). Without
     * one of these, such a send is refused — mail is an unencrypted channel and
     * the vault's whole promise is that its content does not travel on one.
     *
     * Each is a different claim, and they are not interchangeable:
     *
     *   CONTENT_FREE         the message body was built from counts, ids, links
     *                        and fixed prose — nothing that came out of the
     *                        vault. The sealed-run notification emails are the
     *                        pattern: they say a run finished and where to read
     *                        it, never what it read.
     *   USER_COMPOSE         the message IS the user's own content and they are
     *                        sending it themselves, in their own session, from
     *                        their own mailbox. Pressing send is the consent.
     *   ACKNOWLEDGED_FORWARD a filter forwards this mailbox's mail off-platform
     *                        and the owner acknowledged that egress in writing
     *                        (resolved decision 7). The acknowledgment row is
     *                        the consent, and raising the domain's security
     *                        level revokes it.
     */
    const EGRESS_CONTENT_FREE = 'content-free';
    const EGRESS_USER_COMPOSE = 'user-compose';
    const EGRESS_ACKNOWLEDGED_FORWARD = 'acknowledged-forward';

    /**
     * Whether this site sends through the included Joinery email: the
     * getjoinery services account, or a Managed site's operator-minted SMTP
     * credential (HostedMailSettingsMap::apply() records it as
     * included_mail_identity). Included email has a small monthly allowance
     * meant for everyday mail; a feature that would send in bulk asks this
     * first (spec email_lists_plugin §5).
     *
     * An owner who points the SMTP settings at their own account changes the
     * host or username, so the match ends with nothing to clear.
     */
    public static function usesIncludedMail(): bool {
        $settings = Globalvars::get_instance();
        $service = trim((string)$settings->get_setting('email_service', true, true));
        if ($service === ServicesClient::MAIL_SERVICE_KEY) {
            return true;
        }
        if ($service !== 'smtp') {
            return false;
        }
        $identity = trim((string)$settings->get_setting('included_mail_identity', true, true));
        if ($identity === '') {
            return false;
        }
        $current = strtolower(trim((string)$settings->get_setting('smtp_host', true, true)))
            . '|' . trim((string)$settings->get_setting('smtp_username', true, true));
        return hash_equals($identity, $current);
    }

    private $settings;
    private $defaultFrom;
    private $defaultFromName;
    private $defaultReplyTo;

    /**
     * What the most recent send() did, for the caller that owns the message.
     * send() returns a bool because every transactional caller wants exactly
     * that; the mailbox compose path also wants to know WHICH transport took the
     * message, what the carrier answered, and which recipients Joinery Direct
     * delivered before any carrier was involved — so it can keep the send's
     * record (specs/mailbox_message_timeline.md A2/A3). Reset at the top of
     * every send(); read with lastSendReport().
     *
     * suppressed names a send dry run or an empty test trap stopped
     * ('dry_run', 'test_trap_empty'), null otherwise.
     *
     * @var array{direct_delivered: string[], transport: ?string, receipt: ?array, error: ?string, suppressed: ?string}
     */
    private $last_send_report = array('direct_delivered' => array(), 'transport' => null, 'receipt' => null, 'error' => null,
        'suppressed' => null);

    /** @var array|null Cached provider registry: key => class name */
    private static $providers = null;

    public function __construct() {
        $this->settings = Globalvars::get_instance();
        $this->defaultFrom = $this->settings->get_setting('defaultemail');
        $this->defaultFromName = $this->settings->get_setting('defaultemailname');
        $this->defaultReplyTo = $this->settings->get_setting('defaultreplyto');
    }

    // ── Provider Discovery ──────────────────────────────────────────────

    /**
     * Scan includes/email_providers/ for classes implementing EmailServiceProvider.
     * Results are cached for the lifetime of the request.
     */
    private static function discoverProviders(): array {
        if (self::$providers !== null) {
            return self::$providers;
        }

        self::$providers = [];
        $provider_dir = PathHelper::getIncludePath('includes/email_providers/');

        foreach (glob($provider_dir . '*Provider.php') as $file) {
            require_once($file);
            $class = basename($file, '.php');
            if (class_exists($class) && in_array('EmailServiceProvider', class_implements($class))) {
                $key = $class::getKey();
                self::$providers[$key] = $class;
            }
        }

        return self::$providers;
    }

    /**
     * Get the class name for a provider by key.
     */
    private static function getProviderClass(string $key): ?string {
        $providers = self::discoverProviders();
        return $providers[$key] ?? null;
    }

    /**
     * Instantiate a provider by key.
     */
    private static function getProvider(string $key): ?EmailServiceProvider {
        $class = self::getProviderClass($key);
        if (!$class) {
            return null;
        }
        return new $class();
    }

    /**
     * A configured provider instance by key, or null for an unknown key. For
     * callers that ask a provider a question outside a send — the message
     * timeline asking a DeliveryEventSource what became of a message.
     */
    public static function providerFor(string $key): ?EmailServiceProvider {
        return self::getProvider($key);
    }

    /**
     * Return all discovered providers as ['key' => 'Label'] for dropdowns.
     */
    public static function getAvailableServices(): array {
        $services = [];
        foreach (self::discoverProviders() as $key => $class) {
            $services[$key] = $class::getLabel();
        }
        return $services;
    }

    /**
     * The outbound provider this site is set to use, as a bare key, or '' when
     * nobody has chosen one.
     *
     * An empty answer is a real answer here and is deliberately not filled in
     * with a guess. Substituting a provider nobody selected means a site that
     * has never been configured fails at that provider's API with an
     * authentication error, which describes a credential problem rather than
     * the actual problem: no email service is set up. Every caller below
     * handles '' explicitly and says so.
     */
    public static function activeServiceKey(): string {
        $settings = Globalvars::get_instance();
        return trim((string)$settings->get_setting('email_service'));
    }

    /**
     * @var callable|null fn(EmailMessage): array{remaining:string[],delivered:string[]}
     *
     * The Joinery Direct attempt, registered by the mailbox plugin at bootstrap
     * so core send code never names a plugin symbol — the same discipline
     * MailIdentityGuard and File::resolve_decrypt_hook() set. With nothing
     * registered, send() behaves exactly as it did before Direct existed.
     */
    private static $direct_attempt = null;

    /**
     * Register the Direct-first attempt. It returns which recipients Direct
     * delivered and which remain for the ordinary transport, and it must never
     * throw: a failure of the fast lane is a downgrade, never a lost message.
     */
    public static function registerDirectAttempt(callable $fn): void {
        self::$direct_attempt = $fn;
    }

    /**
     * The platform's active outbound provider instance, or null when none is
     * configured or the configured key resolves to no provider. Exposed so the
     * hidden-origin compose path (RawRelayComposeTransport) can reach the same
     * provider a normal send() would use and submit an app-signed raw message
     * through its API.
     */
    public static function getActiveProvider(): ?EmailServiceProvider {
        $service = self::activeServiceKey();
        if ($service === '') {
            return null;
        }
        return self::getProvider($service);
    }

    /**
     * The settings a provider is configured with, as declared in settings.json
     * under the group email_provider_<key>.
     *
     * A provider does not describe its own fields: they are declared once, in
     * the manifest, so the settings page, the writer and the validator all read
     * the same rules. An unknown key, or one with nothing declared, has no
     * settings.
     */
    public static function getProviderSettings(string $key): array {
        $providers = self::discoverProviders();
        if (!isset($providers[$key])) {
            return [];
        }
        require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
        return SettingsDeclarations::forGroup('email_provider_' . $key, 'core');
    }

    /**
     * Return the discovered providers registry (for admin page iteration).
     * Returns ['key' => 'ClassName', ...]
     */
    public static function getDiscoveredProviders(): array {
        return self::discoverProviders();
    }

    /**
     * Which single-key providers could have issued $key, most likely first:
     * those whose declared key shape matches, and — when none does — every
     * provider that opted in (SingleKeyProvider), so a caller can try them
     * live in order. Providers that need more than one key are never listed.
     *
     * @return array<string,string> provider key => class, in trial order
     */
    public static function providersForApiKey(string $key): array {
        $key = trim($key);
        $single = array();
        foreach (self::discoverProviders() as $provider_key => $class) {
            if (in_array('SingleKeyProvider', class_implements($class) ?: array(), true)) {
                $single[$provider_key] = $class;
            }
        }
        $matched = array();
        foreach ($single as $provider_key => $class) {
            if ($key !== '' && preg_match($class::apiKeyPattern(), $key) === 1) {
                $matched[$provider_key] = $class;
            }
        }
        return $matched ?: $single;
    }

    /**
     * Reset the cached provider list (useful for testing).
     */
    public static function resetProviderCache(): void {
        self::$providers = null;
    }

    // ── Eligibility ─────────────────────────────────────────────────────

    /**
     * Whether this site can send its own mail at all: a sender address send()
     * accepts and a provider that is configured. A fresh install answers no
     * until its email step is done. Pages that offer something only a mail can
     * deliver (a password reset link) ask this first.
     */
    public static function canSendMail(): bool {
        return self::transactionalSendBlocker() === null && self::detectServiceType() !== 'none';
    }

    /**
     * Why $from cannot carry ambient/transactional mail right now, or null
     * when it can. $from defaults to the defaultemail setting.
     *
     * The predicates are the same calls the runtime guard in send() makes —
     * a config-time verdict that could drift from send-time behavior would be
     * worse than none. Pure read: no DNS, no provider API, cheap enough for
     * every settings-page render. (Whether the mail *authenticates* once sent
     * is the Setup tab's job; this answers only "will send() refuse it".)
     *
     * Every UI that enables a transactional email feature calls this during
     * its configuration flow and renders the result.
     *
     * @return string|null Human-readable blocker, or null = eligible.
     */
    public static function transactionalSendBlocker(?string $from = null): ?string {
        if ($from === null) {
            $from = (string)Globalvars::get_instance()->get_setting('defaultemail');
        }
        $from = trim($from);
        if ($from === '') {
            return 'No system sender address is configured.';
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $from . ' is not a valid email address.';
        }
        $domain = MailIdentityGuard::domainOf($from);
        if (MailIdentityGuard::isProtectedDomain($domain)) {
            return 'Automated mail from ' . $domain . ' is refused: it is a protected identity, '
                . 'and only the session-gated mailbox compose path may send as it.';
        }
        return null;
    }

    /**
     * Record a refused ambient send in the main event log, deduped to once
     * per From-address per UTC day — evl_event_logs carries no retention
     * policy, and a caller without a dedup ledger of its own could otherwise
     * refuse once per cron minute forever. The day's first row is the record;
     * the red Setup card, not the log, is the live signal.
     *
     * Never throws: the refusal exception behind it is the real event, and a
     * logging hiccup must not mask it.
     *
     * Addresses only. Only a protected domain is refused here, and on one whose
     * mail is sealed end to end (specs/client_custody_mail.md § R6) the subject
     * is the message's own words, which the event log would keep in the clear.
     */
    private static function logRefusedSend(string $from, string $to, string $reason): void {
        try {
            require_once(PathHelper::getIncludePath('data/event_logs_class.php'));
            $today = new MultiEventLog([
                'event' => 'email_send_refused',
                'created_since' => gmdate('Y-m-d 00:00:00'),
            ]);
            $today->load();
            $prefix = 'from=' . $from . ' ';
            foreach ($today as $row) {
                if (strpos((string)$row->get('evl_note'), $prefix) === 0) {
                    return; // already recorded today
                }
            }
            $log = new EventLog(NULL);
            $log->set('evl_event', 'email_send_refused');
            $log->set('evl_was_success', false);
            $log->set('evl_note', $prefix . 'to=' . $to . ' — ' . $reason);
            // An observation of a refused send: the server's own record.
            SystemBase::server_initiated_write(function () use ($log) { $log->save(); });
        } catch (Throwable $e) {
            error_log('email_send_refused: could not write the event-log record — ' . $e->getMessage());
        }
    }

    // ── Sending ─────────────────────────────────────────────────────────

    /**
     * The test hook: null in production. A test sets a callable, and deliver()
     * calls it with ($template, $to, $vars, $message) instead of any
     * transport, taking its return as the result. $to is the first To as the
     * caller addressed it (before the test-mode trap); $message is as sent.
     * The outbox row is still written, so a test checks both. On a task
     * retry $vars is null and $to is the row's.
     *
     * @var callable|null
     */
    public static $capture = null;

    /**
     * Main sending method with clean interface
     *
     * Every email the platform sends on its own behalf is recorded in the
     * outbox (equ_queued_emails, specs/email_outbox.md): its row is written
     * before it is sent, without its body, and its outcome after. Body and the
     * rest of the message are written only when the email must outlive this
     * process: a failed send waiting for its retry, a queued email, a
     * send-once email. Mail sent through an injected transport (a person's
     * own mailbox) and messages marked EmailMessage::unrecorded() write no row.
     *
     * @param EmailMessage $message The email to send
     * @param bool $queue_on_failure If true, a failed first try is kept for the
     *        retry task. A message rebuilt from its row (the task) is always kept
     *        for its next retry, up to the limit.
     * @param EmailServiceProvider|null $transport Optional pre-configured transport. When supplied
     *        the message is sent through it directly — used for "send AS this mailbox" (the system
     *        connected-account provider and the per-mailbox reply/forward transport). The global
     *        email_service selection AND its fallback are skipped (you cannot fall back a
     *        send-as-this-identity to a different identity). Such a send is never recorded
     *        and never queued. This is the single pipeline — no send path bypasses it.
     * @param string $egress_assertion One of the EGRESS_* constants, required to send at all from
     *        a process that has opened sealed content (the hot-turn rule — see
     *        includes/SealedEgressGuard.php). Not a convenience flag: each value is a claim a
     *        reviewer can check against the code that builds the message. Empty means no claim,
     *        which is right for the overwhelming majority of sends.
     * @return bool True if sent successfully. For a send-once message (once()):
     *        true when this call took the key (the email went, or waits for its
     *        retry), false when the key was already taken and nothing was sent.
     */
    public function send(EmailMessage $message, $queue_on_failure = true, ?EmailServiceProvider $transport = null,
            $egress_assertion = '') {
        if (self::remote() !== null) {
            // The site sends it, with its own defaults, guards and transport.
            // A transport object and an egress assertion are this process's
            // claims, and the site makes none on a plugin's behalf.
            if ($transport !== null) {
                throw new Exception('A sandboxed plugin sends through the site\'s own mail service; it cannot pass a transport.');
            }
            $answer = self::$remote->send($message, (bool)$queue_on_failure);
            $this->last_send_report = $answer['report'] + $this->last_send_report;
            return (bool)$answer['sent'];
        }
        return $this->dispatch($message, (bool)$queue_on_failure, $transport, (string)$egress_assertion, false);
    }

    /**
     * Put $message on the outbox for the SendQueuedEmails task to send, with
     * its body and the rest of the message. Nothing is sent now. Returns true
     * when it is queued (or suppressed by dry run or an empty test trap), false
     * for a send-once key already taken, or when this process has opened
     * sealed content (the row would hold content in the clear; logged).
     *
     * @throws Exception for a message that cannot be stored (over
     *   QueuedEmail::EXTRAS_MAX_BYTES, an unreadable attachment), since nothing was sent
     */
    public function queue(EmailMessage $message): bool {
        if (self::remote() !== null) {
            throw new Exception('A sandboxed plugin sends with send(); the site has no queue for it.');
        }
        return $this->dispatch($message, true, null, '', true);
    }

    /**
     * The one path for send() and queue(). See send().
     */
    private function dispatch(EmailMessage $message, bool $queue_on_failure, ?EmailServiceProvider $transport,
            string $egress_assertion, bool $queue_only): bool {
        // Mail is an unencrypted channel, so a process holding sealed plaintext
        // may only send a message whose call site makes one of the assertions
        // above. Refusing here rather than at the transport is also what keeps
        // sealed content out of the outbox: a message that is never sent is
        // never recorded.
        require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
        SealedEgressGuard::assertSendAllowed($egress_assertion, (string)$message->getSubject());
        $this->last_send_report = array('direct_delivered' => array(), 'transport' => null, 'receipt' => null,
            'error' => null, 'suppressed' => null);
        $this->addressed_to = $message->getRecipients()[0]['email'] ?? null;
        $this->redirected = false;

        $row_id = $message->getRowId();
        if ($row_id === null) {
            return $this->dispatchNew($message, $queue_on_failure, $transport, $egress_assertion, $queue_only);
        }
        // A message the task rebuilt from its row: everything below runs again
        // (defaults, guards, dry run, test mode, Direct), and the outcome goes
        // to that row. A throw leaves it at Error sending, counting the try.
        try {
            return $this->dispatchRow($message, $row_id);
        } catch (Throwable $e) {
            QueuedEmail::settle($row_id, QueuedEmail::ERROR_SENDING, array('error' => $e->getMessage(), 'count_try' => true));
            throw $e;
        }
    }

    /**
     * Defaults, the protected-identity guard and validation, which every send
     * passes before anything is recorded or sent.
     */
    private function prepare(EmailMessage $message, ?EmailServiceProvider $transport): void {
        // Set defaults if not specified
        if (!$message->getFrom()) {
            $message->from($this->defaultFrom, $this->defaultFromName);
        }

        // Default Reply-To applies to ambient sends only: an injected transport is
        // send-as-a-mailbox (a human identity whose replies belong to that mailbox),
        // never the site's system mail.
        if ($transport === null && $this->defaultReplyTo && !$message->getReplyTo()) {
            $message->replyTo($this->defaultReplyTo);
        }

        // Protected-identity ambient-send guard (specs/mailbox_outbound_send_protection.md,
        // closure 4 / Phase 3). A protected From-domain may leave ONLY through the
        // session-gated mailbox compose path, which reaches send() with an injected
        // $transport. An ambient/transactional call (no transport) with a protected
        // From-domain is refused outright — a locked box's transactional code must
        // never emit protected-domain mail. This also backstops the SRS bounce
        // generator, which Phase 5 already moves off the protected domain.
        if ($transport === null
            && MailIdentityGuard::isProtectedDomain(MailIdentityGuard::domainOf((string)$message->getFrom()))) {
            $recipients = array_column($message->getRecipients() ?: array(), 'email');
            self::logRefusedSend((string)$message->getFrom(), implode(', ', $recipients),
                'protected identity domain outside the session-gated mailbox compose path');
            throw new Exception('Refusing to send from a protected identity domain outside the '
                . 'session-gated mailbox compose path.');
        }

        // Validate
        $errors = $message->validate();
        if (!empty($errors)) {
            throw new Exception('Invalid email message: ' . implode(', ', $errors));
        }
    }

    /**
     * Dry run and the test-mode trap. Returns the suppression ('dry_run',
     * 'test_trap_empty') when the message must not be sent at all, or null
     * once the message is ready to go (redirected to the trap in test mode).
     */
    private function suppressOrRedirect(EmailMessage $message, bool $rebuilt): ?string {
        // Dry run: the message is fully built and validated, but no transport is
        // touched and nothing is queued. Placed after validation so a dry run still
        // surfaces a malformed message, and above the transport branches so it
        // covers the injected-transport path as well as the global-service one.
        $addresses = implode(', ', array_column($message->getRecipients() ?: array(), 'email'));
        if ((string)Globalvars::get_instance()->get_setting('email_dry_run') === '1') {
            error_log('[EmailSender] dry run: not sent to ' . $addresses);
            $this->last_send_report['suppressed'] = 'dry_run';
            return 'dry_run';
        }

        // Test mode: every message is delivered to the one configured trap
        // address instead of its real recipients. This is what makes a staging
        // clone — or a test run — safe to let send: the send path runs for
        // real, end to end, but nothing reaches a real person's inbox. It sits
        // above the Joinery Direct branch deliberately: Direct delivers
        // straight into the REAL recipient's instance, which is exactly the
        // thing a redirect exists to prevent.
        $trap = self::testModeTrap();
        $this->test_mode_send = ($trap !== null && $trap !== '');
        if ($trap !== null) {
            if ($trap === '') {
                // Redirect-on with nowhere to redirect to: suppressing is the
                // safe direction — a fallback to the real recipient would be
                // the one behavior the operator switched this on to stop.
                error_log('[EmailSender] test mode is on but email_test_recipient is empty: not sent to ' . $addresses);
                $this->last_send_report['suppressed'] = 'test_trap_empty';
                return 'test_trap_empty';
            }
            // A row written in test mode is already the trap's, its subject
            // already naming who it was for.
            $to = $message->getRecipients();
            $already = $rebuilt && count($to) === 1 && strcasecmp((string)$to[0]['email'], $trap) === 0
                && !$message->getCc() && !$message->getBcc();
            if (!$already) {
                $this->redirected = true;
                $originals = $message->redirectAllRecipientsTo($trap);
                $message->subject(self::testModeSubject($originals, (string)$message->getSubject()));
            }
        }
        return null;
    }

    /** A first send (or a queue()): its row, its delivery, its outcome. */
    private function dispatchNew(EmailMessage $message, bool $queue_on_failure, ?EmailServiceProvider $transport,
            string $egress_assertion, bool $queue_only): bool {
        // The caller's own Reply-To, before the site's default is stamped: a
        // retry gets the default stamped again.
        $own_reply_to = $message->getReplyTo();
        $this->prepare($message, $transport);

        $once = $message->getOnceKey() !== null;
        $recorded = $transport === null && !$message->isUnrecorded();
        $hot = SealedEgressGuard::isHot();
        // A hot process may write a row only as a declared content-free write,
        // and never its content (R43).
        $content_free = $hot && trim($egress_assertion) === self::EGRESS_CONTENT_FREE;

        if ($once) {
            if ($transport !== null || $message->isUnrecorded()) {
                throw new Exception('A send-once email is recorded; it cannot be unrecorded or go by an injected transport.');
            }
            if ($message->getAbout() === null) {
                throw new Exception('A send-once email names the record it is about (EmailMessage::about()).');
            }
            if (count($message->getRecipients()) !== 1 || $message->getCc() || $message->getBcc()) {
                throw new Exception('A send-once email has one To address and no Cc or Bcc.');
            }
            if (DbConnector::get_instance()->get_db_link()->inTransaction()) {
                throw new Exception('A send-once email is sent outside any transaction: its row is the permission to send.');
            }
            if ($hot && !$content_free) {
                throw new Exception('A send-once email from a process that has opened sealed content must be content-free.');
            }
        }
        if ($queue_only && $hot) {
            error_log('[EmailSender] not queued: this process has opened sealed content, and a queued email '
                . 'stores its body in the clear');
            return false;
        }

        if ($this->suppressOrRedirect($message, false) !== null) {
            return true; // nothing sent and nothing recorded
        }

        if ($queue_only) {
            // Ready for the task, with everything it needs to send it.
            $content = $this->buildContent($message, $own_reply_to, null); // throws: nothing was sent
            if ($once) {
                return QueuedEmail::claim_once($message, QueuedEmail::READY_TO_SEND, $content['body'], $content['extras']) !== null;
            }
            QueuedEmail::open_row($message, QueuedEmail::READY_TO_SEND, $content['body'], $content['extras']);
            return true;
        }

        $row_id = null;
        $row_has_content = false;
        if ($once) {
            // The row is the permission: it holds the content, since a send-once
            // email that dies with this process must still be retried.
            $content = null;
            if (!$content_free) {
                try {
                    $content = $this->buildContent($message, $own_reply_to, null);
                } catch (Throwable $e) {
                    error_log('[EmailSender] a send-once email\'s retry cannot be stored: ' . $e->getMessage());
                }
            }
            $row_id = QueuedEmail::claim_once($message, QueuedEmail::LOCKED, $content['body'] ?? null,
                $content['extras'] ?? null, $content_free); // throws on a database error
            if ($row_id === null) {
                return false; // already told
            }
            $row_has_content = $content !== null;
        } elseif ($recorded && (!$hot || $content_free)) {
            try {
                $row_id = QueuedEmail::open_row($message, QueuedEmail::LOCKED, null, null, null, null, $content_free);
            } catch (Throwable $e) {
                // Recording never stops an ordinary email (R-C).
                error_log('[EmailOutbox] could not record an email; sending it anyway: ' . $e->getMessage());
            }
        }

        try {
            $result = $this->deliver($message, $transport);
        } catch (VaultLockedException $e) {
            throw $e; // the locked-state signal of an injected transport: never recorded
        } catch (Throwable $e) {
            $this->last_send_report['error'] = $e->getMessage();
            $this->finish($message, $row_id, false, $queue_on_failure && !$content_free, $row_has_content,
                $own_reply_to, $content_free, $recorded);
            throw $e;
        }
        $this->finish($message, $row_id, $result, $queue_on_failure && !$content_free, $row_has_content,
            $own_reply_to, $content_free, $recorded);
        return $once ? true : $result;
    }

    /** A message rebuilt from row $row_id, already Locked by QueuedEmail::claim(). */
    private function dispatchRow(EmailMessage $message, int $row_id): bool {
        $this->prepare($message, null);
        $suppressed = $this->suppressOrRedirect($message, true);
        if ($suppressed !== null) {
            QueuedEmail::settle($row_id, QueuedEmail::DELETED, array('service' => $suppressed));
            return true;
        }
        if ($this->redirected) {
            // Written with test mode off, sent with it on: the row says it went
            // to the trap, never that a real person was emailed.
            QueuedEmail::readdress($row_id, $message);
        }
        $result = $this->deliver($message, null);
        if ($result) {
            QueuedEmail::settle($row_id, QueuedEmail::SENT, array('service' => $this->servicePath()));
            return true;
        }
        $this->settleFailure($row_id, $message, true, true, null);
        return false;
    }

    /**
     * Write a first send's outcome. With a row: Sent; or, failed, Error
     * sending with what a retry needs ($retryable), else Permanent failure.
     * With none: an unrecorded message's failure goes to queueForRetry(); a
     * recorded one whose row could not be written is logged.
     */
    private function finish(EmailMessage $message, ?int $row_id, bool $ok, bool $retryable, bool $row_has_content,
            ?string $own_reply_to, bool $content_free, bool $recorded): void {
        if ($row_id !== null) {
            if ($ok) {
                QueuedEmail::settle($row_id, QueuedEmail::SENT, array('service' => $this->servicePath()), $content_free);
                return;
            }
            $this->settleFailure($row_id, $message, $retryable, $row_has_content, $own_reply_to, $content_free);
            return;
        }
        if ($ok) {
            return;
        }
        $why = (string)($this->last_send_report['error'] ?? 'the send failed');
        if ($recorded) {
            error_log('[EmailSender] an email with no outbox row was not sent (' . $why . '); it will not be retried');
        } elseif ($message->isUnrecorded() && $retryable && !SealedEgressGuard::isHot()) {
            $this->queueForRetry($message, $own_reply_to, $why);
        } elseif ($message->isUnrecorded()) {
            error_log('[EmailSender] an unrecorded email was not sent (' . $why . ')');
        }
    }

    /**
     * A failed send's row: Error sending with the error and, unless the row
     * already holds it, the content a retry needs; Permanent failure when the
     * caller allows no retry or the content cannot be stored (R38). Direct's
     * partial delivery leaves the still-owed addresses in the extras.
     */
    private function settleFailure(int $row_id, EmailMessage $message, bool $retryable, bool $row_has_content,
            ?string $own_reply_to, bool $content_free = false): void {
        $error = (string)($this->last_send_report['error'] ?? '') ?: 'The send failed.';
        if ($message->getRowId() !== null) {
            // A retry: its content is on the row already. When Joinery Direct
            // took some of its recipients this time, the row is told who is
            // still owed, so the next retry never sends them a second copy.
            $fields = array('error' => $error, 'count_try' => true);
            if (!empty($this->last_send_report['direct_delivered'])) {
                try {
                    $stored = (new QueuedEmail($row_id, TRUE))->get_json_decoded('equ_message_extras');
                    $content = $this->buildContent($message, is_array($stored) ? ($stored['reply_to'] ?? null) : null,
                        array_column($message->getRecipients(), 'email'));
                    $fields['body'] = $content['body'];
                    $fields['extras'] = $content['extras'];
                } catch (Throwable $e) {
                    QueuedEmail::settle($row_id, QueuedEmail::PERMANENT_FAILURE, array('error' => $error
                        . ' Not retried: the addresses still owed could not be stored (' . $e->getMessage() . ').'));
                    return;
                }
            }
            QueuedEmail::settle($row_id, QueuedEmail::ERROR_SENDING, $fields);
            return;
        }
        if (!$retryable) {
            QueuedEmail::settle($row_id, QueuedEmail::PERMANENT_FAILURE, array('error' => $error,
                'service' => $this->servicePath()), $content_free);
            return;
        }
        if ($row_has_content && empty($this->last_send_report['direct_delivered'])) {
            QueuedEmail::settle($row_id, QueuedEmail::ERROR_SENDING, array('error' => $error, 'service' => $this->servicePath()));
            return;
        }
        $owed = empty($this->last_send_report['direct_delivered'])
            ? null : array_column($message->getRecipients(), 'email');
        try {
            $content = $this->buildContent($message, $own_reply_to, $owed);
        } catch (Throwable $e) {
            QueuedEmail::settle($row_id, QueuedEmail::PERMANENT_FAILURE, array('error' => $error
                . ' Not retried: ' . $e->getMessage(), 'service' => $this->servicePath()));
            return;
        }
        QueuedEmail::settle($row_id, QueuedEmail::ERROR_SENDING, array('error' => $error, 'service' => $this->servicePath(),
            'body' => $content['body'], 'extras' => $content['extras']));
    }

    /** Which way the last send left: a provider key, joinery_direct, or both. */
    private function servicePath(): ?string {
        $transport = $this->last_send_report['transport'] ?? null;
        if (!empty($this->last_send_report['direct_delivered'])) {
            return $transport ? 'joinery_direct+' . $transport : 'joinery_direct';
        }
        return $transport;
    }

    /**
     * The body and stored message (equ_message_extras) of $message as it
     * stands now (after the test-mode redirect), for a retry: from
     * EmailMessage::toTransfer(), keeping what the columns do not hold.
     * $owed names the addresses still to send to after a partial delivery.
     *
     * @return array{body: string, extras: string}
     * @throws Exception when it cannot be stored: an attached file that cannot
     *   be read, a value JSON or jsonb refuses, or over QueuedEmail::EXTRAS_MAX_BYTES
     */
    public function buildContent(EmailMessage $message, ?string $own_reply_to, ?array $owed): array {
        $t = $message->toTransfer();
        $html = (string)($t['html'] ?? '');
        $extras = array(
            'text' => $html !== '' ? $t['text'] : null,
            'reply_to' => $own_reply_to,
            'headers' => $t['headers'] ?: new stdClass(),
            'metadata' => $t['metadata'] ?: new stdClass(),
            'message_id' => $t['message_id'],
            'attachments' => $t['attachments'],
        );
        if ($html === '') {
            $extras['plain'] = true;
        }
        if ($owed !== null) {
            $extras['owed'] = array_values($owed);
        }
        $json = json_encode($extras, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strpos($json, '\\u0000') !== false) {
            throw new Exception('the message holds a NUL character, which the outbox cannot store');
        }
        if (strlen($json) > QueuedEmail::EXTRAS_MAX_BYTES) {
            throw new Exception('the message is over ' . (QueuedEmail::EXTRAS_MAX_BYTES >> 20) . ' MiB');
        }
        $body = $html !== '' ? $html : (string)$t['text'];
        if (strpos($body, "\0") !== false) {
            throw new Exception('the message holds a NUL character, which the outbox cannot store');
        }
        return array('body' => $body, 'extras' => $json);
    }

    /**
     * Send $message now: the test capture, Joinery Direct, then the injected
     * transport or the configured service and its fallback. Writes no row.
     */
    private function deliver(EmailMessage $message, ?EmailServiceProvider $transport): bool {
        if (self::$capture !== null) {
            $to = $this->addressed_to ?? ($message->getRecipients()[0]['email'] ?? null);
            $this->last_send_report['transport'] = 'capture';
            $result = call_user_func(self::$capture, $message->getTemplateName(), $to, $message->getTemplateVars(), $message) !== false;
            $this->last_send_report['error'] = $result ? null : 'The test capture refused the message.';
            return $result;
        }

        // Joinery Direct, before any transport is chosen: when both ends are
        // Joinery instances and the recipient has already affirmed this sender,
        // the message goes straight there over a signed, sealed channel and
        // never touches SMTP or a paid sender.
        //
        // It applies to every send, injected transport or configured provider
        // alike, and the fallback has to be the path the message would
        // otherwise have taken. Recipients Direct delivered are dropped from the
        // message so the send below is never a duplicate for them; if it
        // delivered them all, there is nothing left to send.
        if (self::$direct_attempt !== null) {
            try {
                $direct = call_user_func(self::$direct_attempt, $message);
                if (!empty($direct['delivered'])) {
                    $this->last_send_report['direct_delivered'] = array_values($direct['delivered']);
                    $this->countSend($message, MailSendOutcome::PATH_DIRECT, 'joinery_direct');
                    if (empty($direct['remaining'])) {
                        return true;
                    }
                    $message->keepOnlyRecipients($direct['remaining']);
                }
            } catch (\Throwable $e) {
                // A broken fast lane must never cost a message. Log and continue
                // down the path this send would have taken anyway.
                error_log('EmailSender: Joinery Direct attempt failed, falling back: ' . $e->getMessage());
            }
        }

        // Injected transport: send through it directly, no provider fallback.
        // Its failure reaches the caller through lastSendReport(); it is never
        // queued (the mailbox keeps its own record).
        if ($transport !== null) {
            $service = method_exists($transport, 'getKey') ? $transport::getKey() : get_class($transport);
            $this->last_send_report['transport'] = $service;
            try {
                MailSendOutcome::resetNote();
                $result = $transport->send($message);
                if ($result) {
                    $this->countSend($message, null, $service);
                }
                $this->recordSendOutcome($transport, $result,
                    $result ? null : 'The ' . $service . ' transport refused the message.');
            } catch (VaultLockedException $e) {
                // A protected-identity send whose vault window is closed: this is
                // the locked-state signal, not a transport failure. Let it
                // propagate so the compose path prompts a one-tap unlock instead
                // of silently failing the send.
                throw $e;
            } catch (\Exception $e) {
                $this->last_send_report['error'] = $e->getMessage();
                $result = false;
            }
            return $result;
        }

        // Use service selection with fallback
        $settings = Globalvars::get_instance();
        $service = self::activeServiceKey();
        $fallback = trim((string)$settings->get_setting('email_fallback_service'));

        if ($service === '' && $fallback === '') {
            // Nothing to try. Reported as a failure, never as some provider's
            // authentication error: the email takes its retries and then ends
            // at Permanent failure, which the Outbox page shows.
            $this->last_send_report['error'] = 'No email service is configured.';
            error_log('[EmailSender] no email service is configured; set one at /admin/admin_settings_email');
            return false;
        }

        if ($service === '') {
            // Only a fallback is named. Use it rather than refusing: the
            // operator has clearly configured something.
            $service = $fallback;
            $fallback = '';
        }

        $result = $this->sendWithService($service, $message);

        if (!$result && $fallback && $fallback !== $service) {
            $first_error = $this->last_send_report['error'];
            $result = $this->sendWithService($fallback, $message);
            if (!$result) {
                $this->last_send_report['error'] = 'Both ' . $service . ' and ' . $fallback . ' failed: '
                    . $first_error . ' / ' . $this->last_send_report['error'];
            }
        }
        if (!$result) {
            error_log('[EmailSender] send failed via ' . ($this->last_send_report['transport'] ?? $service) . ': '
                . $this->last_send_report['error']);
        }

        return $result;
    }

    /**
     * An account with no email has nobody to send to: a send to a blank
     * recipient is "nothing sent" (false, one log line), never the exception a
     * malformed address still is. Every receipt, notice and welcome addressed
     * to an account's email reaches here as a blank for a username-only account.
     */
    private static function noAddress($to, string $what): bool {
        if (is_array($to)) {
            // EmailMessage::to(): a list of addresses, or address => name.
            $blank = true;
            foreach ($to as $k => $v) {
                if (trim((string)(is_numeric($k) ? $v : $k)) !== '') {
                    $blank = false;
                    break;
                }
            }
        } else {
            $blank = $to === null || trim((string)$to) === '';
        }
        if ($blank) {
            error_log('[EMAIL_NO_ADDRESS] ' . $what . ': the recipient has no email address; nothing sent');
        }
        return $blank;
    }

    /**
     * Quick send static method for simple cases
     * Uses the default outer template for consistent styling
     */
    public static function quickSend($to, $subject, $body, $from = null) {
        if (self::noAddress($to, 'quickSend "' . $subject . '"')) {
            return false;
        }
        $settings = Globalvars::get_instance();

        // Check if body appears to be plain text or HTML
        $isHtml = strip_tags($body) !== $body;

        if ($isHtml) {
            // Get default template setting with proper error handling
            $defaultTemplate = $settings->get_setting('default_email_template');

            if (!$defaultTemplate) {
                throw new Exception('Default email template not configured. Please set default_email_template setting or use plain text emails.');
            }

            try {
                // default_email_template names an outer template; the body goes
                // in the blank inner one, with no list footer under it.
                $template = new EmailTemplateRenderer('blank_template', $defaultTemplate, 'blank_footer');
                $message = $template->createMessage([
                    'subject' => $subject,
                    'body' => $body,
                ]);

                // Override subject since we want the one passed in, not from template
                $message->subject($subject);
            } catch (EmailTemplateError $e) {
                throw new Exception('Default email template \'' . $defaultTemplate . '\' is malformed or missing: ' . $e->getMessage());
            }
        } else {
            // For plain text, just send as-is
            $message = EmailMessage::create($to, $subject, $body);
        }

        $message->to($to);

        if ($from) {
            $message->from($from);
        }

        $sender = new self();
        return $sender->send($message);
    }

    /**
     * Send using a template
     */
    public static function sendTemplate($templateName, $to, $values = [], $subject = null) {
        if (self::noAddress($to, 'template ' . $templateName)) {
            return false;
        }
        $message = EmailMessage::fromTemplate($templateName, $values);
        $message->to($to);

        if ($subject) {
            $message->subject($subject);
        }

        $sender = new self();
        return $sender->send($message);
    }

    /**
     * Send template $template to $to once per $key on $record: a key whose
     * email went, is queued or waits for its retry is not sent again, and the
     * template is not even rendered. Returns true when this call took the key
     * (the email went, or its row is queued or waiting for its retry), which
     * the feature treats as told; false when the key was already taken.
     * $queue puts it on the queue instead of sending it now.
     *
     * @throws Exception on a database error or a blank address (false would mean "already told"),
     *   and for a send the outbox refuses (inside a transaction, a second To)
     */
    public static function sendTemplateOnce(SystemBase $record, string $key, string $template, $to, array $vars = array(),
            bool $queue = false, string $egress_assertion = ''): bool {
        if (self::noAddress($to, 'template ' . $template)) {
            // Not false: a caller reads false as "already told".
            throw new InvalidArgumentException('The recipient has no email address; nothing sent.');
        }
        // Read the key first, so a key already taken renders nothing (R61);
        // the claim's INSERT ... ON CONFLICT still decides a race.
        if (self::onceTaken($record, $key)) {
            return false;
        }
        $message = EmailMessage::fromTemplate($template, $vars);
        $message->to($to)->about($record)->once($key);
        $sender = new self();
        return $queue ? $sender->queue($message) : $sender->send($message, true, null, $egress_assertion);
    }

    /** Whether $key on $record is taken: a row neither failed for good nor withdrawn by a suppression. */
    private static function onceTaken(SystemBase $record, string $key): bool {
        $q = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM equ_queued_emails'
            . ' WHERE equ_entity_type = ? AND equ_entity_id = ? AND equ_once_key = ?' . self::liveKeyCondition());
        $q->execute(array($record::$tablename, (int)$record->key, $key));
        return (bool)$q->fetchColumn();
    }

    /** The SQL that leaves out a key row that may be revived (failed for good, or suppressed). */
    private static function liveKeyCondition(): string {
        return ' AND equ_status <> ' . QueuedEmail::PERMANENT_FAILURE
            . ' AND NOT (equ_status = ' . QueuedEmail::DELETED . " AND coalesce(equ_service, '') IN ('"
            . implode("', '", QueuedEmail::SUPPRESSED_SERVICES) . "'))";
    }

    /**
     * Drop one exact send-once key from $record's rows, so the email can be
     * sent again. The rows stay as history, their key cleared; one still
     * Ready, Locked or Error sending, or at Permanent failure, is taken to
     * Deleted with its content cleared, so a second copy cannot go while the
     * first retries and the failed-email alarm does not hold a passed state. Returns
     * whether a row held the key.
     */
    public static function forgetOnce(SystemBase $record, string $key): bool {
        if ($record->key === null) {
            return false;
        }
        return (bool)QueuedEmail::outbox_write(function ($db) use ($record, $key) {
            // A row still to send, or one that failed for good, is withdrawn
            // with its content: the state it told of has passed, so it is
            // neither sent nor held as a failure (reviewer2 N2).
            $open = '(' . QueuedEmail::READY_TO_SEND . ', ' . QueuedEmail::LOCKED . ', ' . QueuedEmail::ERROR_SENDING . ', '
                . QueuedEmail::PERMANENT_FAILURE . ')';
            $q = $db->prepare('UPDATE equ_queued_emails SET equ_once_key = NULL,'
                . ' equ_status = CASE WHEN equ_status IN ' . $open . ' THEN ' . QueuedEmail::DELETED . ' ELSE equ_status END,'
                . ' equ_body = CASE WHEN equ_status IN ' . $open . ' THEN NULL ELSE equ_body END,'
                . ' equ_message_extras = CASE WHEN equ_status IN ' . $open . ' THEN NULL ELSE equ_message_extras END,'
                . ' equ_given_up_time = NULL'
                . ' WHERE equ_entity_type = ? AND equ_entity_id = ? AND equ_once_key = ?');
            $q->execute(array($record::$tablename, (int)$record->key, $key));
            return $q->rowCount() > 0;
        });
    }

    /**
     * $record's live send-once keys starting with $prefix (rows failed for
     * good or withdrawn by a suppression are left out: their keys may send
     * again). $with_failed adds the keys whose rows failed for good, for a
     * feature forgetting the keys of states that have passed.
     *
     * @return string[]
     */
    public static function onceKeys(SystemBase $record, string $prefix = '', bool $with_failed = false): array {
        if ($record->key === null) {
            return array();
        }
        $q = DbConnector::get_instance()->get_db_link()->prepare('SELECT equ_once_key FROM equ_queued_emails'
            . ' WHERE equ_entity_type = ? AND equ_entity_id = ? AND equ_once_key IS NOT NULL'
            . " AND left(equ_once_key, ?) = ?" . ($with_failed ? ' AND NOT (equ_status = ' . QueuedEmail::DELETED . " AND coalesce(equ_service, '') IN ('"
            . implode("', '", QueuedEmail::SUPPRESSED_SERVICES) . "'))" : self::liveKeyCondition()) . ' ORDER BY equ_once_key');
        $q->execute(array($record::$tablename, (int)$record->key, strlen($prefix), $prefix));
        return array_values(array_unique($q->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Batch send to multiple recipients.
     *
     * Uses the provider's native batch API when available (e.g., Mailgun's
     * recipient-variables). Falls back failed recipients to the fallback provider.
     * Each recipient has its own outbox row, written just before the provider
     * call and settled after it: Sent, or that recipient's own Error sending
     * row with what its retry needs (an unrecorded message's leftovers go to
     * queueForRetry() instead).
     *
     * @return array ['success' => bool, 'failed_recipients' => string[]]
     */
    public function sendBatch(EmailMessage $message, array $recipients) {
        if (self::remote() !== null) {
            return self::$remote->sendBatch($message, array_values(array_map('strval', $recipients)));
        }
        // No content-free escape here: a batch is one body to many people, which
        // is never the shape a sealed-content pointer takes. See send().
        require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
        SealedEgressGuard::assertSendAllowed('', (string)$message->getSubject());
        if ($message->getOnceKey() !== null) {
            throw new Exception('A send-once email has one recipient; it cannot be batch-sent.');
        }

        $own_reply_to = $message->getReplyTo();

        // Set defaults if not specified
        if (!$message->getFrom()) {
            $message->from($this->defaultFrom, $this->defaultFromName);
        }

        if ($this->defaultReplyTo && !$message->getReplyTo()) {
            $message->replyTo($this->defaultReplyTo);
        }

        // Batch sends are always ambient (no per-send session transport), so a
        // protected identity domain must never ride them (see send()'s guard).
        if (MailIdentityGuard::isProtectedDomain(MailIdentityGuard::domainOf((string)$message->getFrom()))) {
            throw new Exception('Refusing to batch-send from a protected identity domain.');
        }

        // Dry run covers batches too, as send()'s does: built and checked, never sent.
        if ((string)Globalvars::get_instance()->get_setting('email_dry_run') === '1') {
            error_log('[EmailSender] dry run: a batch of ' . count($recipients) . ' was not sent');
            return ['success' => true, 'failed_recipients' => []];
        }

        // Test mode covers batches too — one send to the trap stands in for
        // the whole list, with the size and first addresses named in the
        // subject so the trap mailbox still says what would have gone out.
        $trap = self::testModeTrap();
        $this->test_mode_send = ($trap !== null && $trap !== '');
        if ($trap !== null) {
            if ($trap === '') {
                error_log('[EmailSender] test mode is on but email_test_recipient is empty: a batch of '
                    . count($recipients) . ' was not sent');
                return ['success' => true, 'failed_recipients' => []];
            }
            $originals = array_values(array_map('strval', $recipients));
            $recipients = [$trap];
            $message->redirectAllRecipientsTo($trap);
            $message->subject(self::testModeSubject($originals, (string)$message->getSubject()));
        }

        $settings = Globalvars::get_instance();
        $service = self::activeServiceKey();
        $fallback = trim((string)$settings->get_setting('email_fallback_service'));

        if ($service === '' && $fallback !== '') {
            $service = $fallback;
            $fallback = '';
        }

        if ($service === '') {
            error_log('[EmailSender] no email service is configured: a batch of ' . count($recipients) . ' was not sent');
            return ['success' => false, 'failed_recipients' => $recipients];
        }

        $provider = self::getProvider($service);
        if (!$provider) {
            error_log('[EmailSender] unknown email service for a batch: ' . $service);
            return ['success' => false, 'failed_recipients' => $recipients];
        }

        // One row per recipient, just before the provider call.
        $rows = array();
        if (!$message->isUnrecorded()) {
            foreach ($recipients as $email) {
                try {
                    $rows[strtolower((string)$email)] = QueuedEmail::open_row($message->forRecipient((string)$email),
                        QueuedEmail::LOCKED);
                } catch (Throwable $e) {
                    error_log('[EmailOutbox] could not record a batch recipient; sending anyway: ' . $e->getMessage());
                }
            }
        }
        $content = function ($email) use ($message, $own_reply_to) {
            try {
                return $this->buildContent($message->forRecipient($email), $own_reply_to, null);
            } catch (Throwable $e) {
                error_log('[EmailSender] a batch recipient\'s retry cannot be stored: ' . $e->getMessage());
                return null;
            }
        };
        $subset = function (array $emails) use ($rows) {
            $emails = array_map('strtolower', array_map('strval', $emails));
            return array_intersect_key($rows, array_flip($emails));
        };

        // Try primary provider's native batch
        MailSendOutcome::resetNote();
        try {
            $result = $provider->sendBatch($message, $recipients);
        } catch (Throwable $e) {
            // Every recipient failed: each row to Error sending with its content
            // (not left Locked for the stale rule to end), then the throw goes on.
            QueuedEmail::settle_batch($rows, array_keys($rows), $service, $content);
            $this->queueBatchLeftovers($message, $recipients, $own_reply_to);
            throw $e;
        }
        $this->countSend($message, null, $service,
            count($recipients) - count($result['failed_recipients'] ?? array()));

        $failed = $result['success'] ? array() : array_values($result['failed_recipients'] ?? array());
        $fallback_provider = (!empty($failed) && $fallback && $fallback !== $service) ? self::getProvider($fallback) : null;

        if ($fallback_provider === null) {
            QueuedEmail::settle_batch($rows, $failed, $service, $content);
            $this->queueBatchLeftovers($message, $failed, $own_reply_to);
            return $result['success'] ? $result : ['success' => false, 'failed_recipients' => $failed];
        }

        // Settle the delivered ones; try the rest on the fallback.
        QueuedEmail::settle_batch(array_diff_key($rows, $subset($failed)), array(), $service, $content);
        MailSendOutcome::resetNote();
        try {
            $fallback_result = $fallback_provider->sendBatch($message, $failed);
        } catch (Throwable $e) {
            QueuedEmail::settle_batch($subset($failed), $failed, $fallback, $content);
            $this->queueBatchLeftovers($message, $failed, $own_reply_to);
            throw $e;
        }
        $this->countSend($message, null, $fallback,
            count($failed) - count($fallback_result['failed_recipients'] ?? array()));
        $still_failed = $fallback_result['success'] ? array() : array_values($fallback_result['failed_recipients'] ?? array());
        if ($still_failed) {
            error_log('[EmailSender] a batch failed via ' . $service . ' and ' . $fallback . ' for '
                . count($still_failed) . ' recipient(s)');
        }
        QueuedEmail::settle_batch($subset($failed), $still_failed, $fallback, $content);
        $this->queueBatchLeftovers($message, $still_failed, $own_reply_to);
        return ['success' => empty($still_failed), 'failed_recipients' => $still_failed];
    }

    /** An unrecorded batch's leftovers, each queued for its own retry. */
    private function queueBatchLeftovers(EmailMessage $message, array $failed, ?string $own_reply_to): void {
        if (!$message->isUnrecorded()) {
            return;
        }
        foreach ($failed as $email) {
            $this->queueForRetry($message->forRecipient((string)$email), $own_reply_to, 'The batch send failed for this recipient.');
        }
    }

    // ── Internal ────────────────────────────────────────────────────────

    /**
     * The test-mode trap address: null when test mode is off, the configured
     * address when on, '' when on but unconfigured (callers suppress).
     *
     * Public because the redirect has to be applied wherever a recipient is
     * DECIDED, not only where the message leaves: a queued row is sent later by
     * whichever process drains the queue, under that process's settings.
     * QueuedEmail applies the same trap at save time so the row itself is safe.
     */
    public static function testModeTrap(): ?string {
        $settings = Globalvars::get_instance();
        if ((string)$settings->get_setting('email_test_mode') !== '1') {
            return null;
        }
        return trim((string)$settings->get_setting('email_test_recipient'));
    }

    /** "[for a@b, c@d +3 more] Original subject" — the trap mailbox stays legible. */
    public static function testModeSubject(array $originals, string $subject): string {
        $shown = array_slice($originals, 0, 2);
        $extra = count($originals) - count($shown);
        return '[for ' . implode(', ', $shown)
            . ($extra > 0 ? ' +' . $extra . ' more' : '') . '] ' . $subject;
    }

    /**
     * Send with a specific provider by key.
     */
    private function sendWithService($service, EmailMessage $message) {
        $provider = self::getProvider($service);
        if (!$provider) {
            error_log("[EmailSender] unknown email service: $service");
            $this->last_send_report['error'] = "Unknown email service: $service";
            return false;
        }

        // Pre-flight: don't attempt a doomed network call when the selected
        // provider has no credentials configured. Surface a friendly, actionable
        // reason so a misconfiguration is obvious in the logs instead of looking
        // like a transient send failure.
        $config = $provider::validateConfiguration();
        if (empty($config['valid'])) {
            $reason = !empty($config['errors']) ? implode('; ', $config['errors']) : 'missing required settings';
            $friendly = "Email service '$service' is selected but not configured: $reason. Skipping — configure it at /admin/admin_settings_email or select a different service.";
            error_log("[EmailSender] $friendly");
            $this->last_send_report['error'] = "Email service '$service' is not configured: $reason";
            return false;
        }

        // The report names the service that is being tried; on a fallback the
        // second call overwrites the first, so it ends naming the one that took
        // the message (or the last one that refused it).
        $this->last_send_report['transport'] = $service;
        try {
            MailSendOutcome::resetNote();
            $result = $provider->send($message);
            if ($result) {
                $this->countSend($message, null, $service);
            }
            $this->recordSendOutcome($provider, $result,
                $result ? null : "The $service service refused the message.");
            return $result;
        } catch (\Exception $e) {
            $this->last_send_report['error'] = $e->getMessage();
            return false;
        }
    }

    /** Whether the send under way was redirected to the test trap just now. */
    private $redirected = false;

    /** The first To of the send under way as its caller addressed it, for $capture. */
    private $addressed_to = null;

    /** Whether the send under way is a test-mode send (redirected to the trap). */
    private $test_mode_send = false;

    /**
     * Count $messages messages that just left, per From-domain, in
     * MailSendOutcome: from the transport's note (signed, unsigned, or handed
     * on), or with no note as handed on to $service. $path names the path when
     * no transport is involved (Joinery Direct). Never throws: counting must
     * never cost a send.
     */
    private function countSend(EmailMessage $message, ?string $path, string $service, int $messages = 1): void {
        if ($messages < 1) {
            return;
        }
        try {
            $domain = MailIdentityGuard::domainOf((string)$message->getFrom());
            if ($path !== null) {
                MailSendOutcome::resetNote();
                MailSendOutcome::record($domain, $path, MailSendOutcome::OUTCOME_HANDED_ON, '', '', '', $service,
                    $messages, $this->test_mode_send);
                return;
            }
            MailSendOutcome::recordAfterSend($domain, $service, $messages, $this->test_mode_send);
        } catch (\Throwable $e) {
            error_log('[EmailSender] could not count a send: ' . $e->getMessage());
        }
    }

    /**
     * Fill the send report from a transport that has just answered: the
     * carrier's receipt when the transport can repeat it, the error otherwise.
     * A receipt is only asked for on success — a refused send has none.
     */
    private function recordSendOutcome($transport, bool $ok, ?string $error): void {
        if ($ok) {
            $this->last_send_report['error'] = null;
            if ($transport instanceof SendReceiptSource) {
                try {
                    $this->last_send_report['receipt'] = $transport->lastSendReceipt();
                } catch (\Throwable $e) {
                    // A receipt is a nicety; never let reading one turn a sent
                    // message into a reported failure.
                    error_log('[EmailSender] could not read the send receipt: ' . $e->getMessage());
                }
            }
        } else {
            $this->last_send_report['error'] = $error;
        }
    }

    /**
     * What the most recent send() did — see $last_send_report. Valid until the
     * next send() on this instance.
     *
     * @return array{direct_delivered: string[], transport: ?string, receipt: ?array, error: ?string}
     */
    public function lastSendReport(): array {
        return $this->last_send_report;
    }

    /**
     * Validate email service configuration
     */
    public function validateServiceConfiguration($service = null) {
        $service = $service ?: self::activeServiceKey();

        if ($service === '') {
            return [
                'valid' => false,
                'service' => '',
                'errors' => ['No email service is configured. Choose one under Settings → Email.'],
            ];
        }

        $provider_class = self::getProviderClass($service);
        if (!$provider_class) {
            return [
                'valid' => false,
                'service' => $service,
                'errors' => ["Unknown email service: $service"],
            ];
        }

        $result = $provider_class::validateConfiguration();
        $result['service'] = $service;
        return $result;
    }

    /**
     * Static convenience method for service validation
     */
    public static function validateService($service = null) {
        $sender = new self();
        return $sender->validateServiceConfiguration($service);
    }

    /**
     * Get which service would be used to send (for testing)
     */
    public function getServiceType() {
        $service = self::activeServiceKey();
        if ($service === '') {
            return 'none';
        }
        $provider_class = self::getProviderClass($service);

        if ($provider_class) {
            $validation = $provider_class::validateConfiguration();
            if ($validation['valid']) {
                return $service;
            }
        }

        return 'none';
    }

    /**
     * Static convenience method for service type detection
     */
    public static function detectServiceType() {
        $sender = new self();
        return $sender->getServiceType();
    }

    /**
     * Keep a failed unrecorded email for the SendQueuedEmails task: one row
     * per email, at Error sending, with its recipients and everything its
     * retry needs, marked service 'unrecorded'. The write that finishes it,
     * Sent or Permanent failure, deletes it. A recorded email is retried from
     * its own outbox row and never comes here.
     */
    private function queueForRetry(EmailMessage $message, ?string $own_reply_to, string $error) {
        // A process holding sealed plaintext may not write content anywhere
        // that cannot protect it: a hot send is attempted once.
        require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
        if (SealedEgressGuard::isHot()) {
            error_log('[EmailSender] an unrecorded email was not queued for its retry: this process has opened sealed content');
            return;
        }
        try {
            $content = $this->buildContent($message, $own_reply_to, null);
            QueuedEmail::open_row($message, QueuedEmail::ERROR_SENDING, $content['body'], $content['extras'], 'unrecorded', $error);
        } catch (Throwable $e) {
            error_log('[EmailSender] an unrecorded email could not be queued for its retry: ' . $e->getMessage());
        }
    }
}
