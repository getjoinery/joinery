<?php
/**
 * SmtpConfig - an explicit, immutable description of how to open an SMTP
 * transport: where to connect (host/port/encryption) and how to authenticate
 * (none / password / XOAUTH2). It is the one place that knows how to derive SMTP
 * connection details from a credential source, so SmtpMailer has a single
 * construction model and every SMTP send is "configure a mailer from an
 * SmtpConfig, then send."
 *
 * Three factories cover the three credential sources:
 *   - fromSettings()          global smtp_* settings (the historical behavior).
 *   - fromConnectedAccount()  an already-connected mailbox (PRESETS coordinates +
 *                             the account's stored OAuth token or app password).
 *   - fromForwardingSettings() inbound forwarding's dedicated relay
 *                             (mailbox_forwarding_smtp_*, else base smtp_*).
 *
 * `encryption` is one of 'ssl' (implicit TLS / SMTPS), 'tls' (STARTTLS), 'none',
 * or null. Null means "auto-detect from port" — the back-compatible behavior of
 * the old SmtpMailer constructor (465→SSL, 587/2525→STARTTLS, else none).
 *
 * @version 1.0
 */

class SmtpConfig {

    const AUTH_NONE     = 'none';
    const AUTH_PASSWORD = 'password';
    const AUTH_XOAUTH2  = 'xoauth2';

    /** @var string */
    public $host = '';

    /** @var int */
    public $port = 25;

    /** @var string|null 'ssl' | 'tls' | 'none' | null (null = auto-detect from port) */
    public $encryption = null;

    /** @var string self::AUTH_* */
    public $authMode = self::AUTH_NONE;

    /** @var string Username for password auth and the SASL identity for XOAUTH2. */
    public $username = '';

    /** @var string Password for AUTH_PASSWORD. */
    public $password = '';

    /** @var OAuthTokenProvider|null Token provider for AUTH_XOAUTH2. */
    public $oauthProvider = null;

    /** @var string SMTP HELO/EHLO hostname (global path only). */
    public $helo = '';

    /** @var string Hostname used in generated headers (global path only). */
    public $hostname = '';

    /** @var string Envelope/bounce sender override (global path only). */
    public $sender = '';

    /**
     * Reproduce the historical global-settings SMTP behavior. Encryption stays
     * null so SmtpMailer auto-detects it from the port exactly as before.
     */
    public static function fromSettings(): self {
        $s = Globalvars::get_instance();
        $c = new self();
        $c->host     = $s->get_setting('smtp_host') ?: '';
        $c->port     = intval($s->get_setting('smtp_port') ?: 25);
        $c->helo     = $s->get_setting('smtp_helo') ?: '';
        $c->hostname = $s->get_setting('smtp_hostname') ?: '';
        $c->sender   = $s->get_setting('smtp_sender') ?: '';

        if ($s->get_setting('smtp_auth')) {
            $c->authMode = self::AUTH_PASSWORD;
            $c->username = $s->get_setting('smtp_username') ?: '';
            $c->password = $s->get_setting('smtp_password') ?: '';
        }
        return $c;
    }

    /**
     * Inbound forwarding's dedicated SMTP relay: start from the base global
     * config, then apply the mailbox_forwarding_smtp_* overrides when set.
     * Replaces InboundEmailRouter::createMailer()'s manual override block.
     */
    public static function fromForwardingSettings(): self {
        $s = Globalvars::get_instance();
        $c = self::fromSettings();

        $fwd_host = $s->get_setting('mailbox_forwarding_smtp_host');
        if ($fwd_host) {
            $c->host = $fwd_host;
        }
        $fwd_port = $s->get_setting('mailbox_forwarding_smtp_port');
        if ($fwd_port) {
            $c->port = intval($fwd_port);
            // Let SmtpMailer re-detect encryption for the overridden port.
            $c->encryption = null;
        }
        $fwd_user = $s->get_setting('mailbox_forwarding_smtp_username');
        if ($fwd_user) {
            $c->authMode = self::AUTH_PASSWORD;
            $c->username = $fwd_user;
        }
        $fwd_pass = $s->get_setting('mailbox_forwarding_smtp_password');
        if ($fwd_pass) {
            $c->password = $fwd_pass;
        }
        return $c;
    }

    /**
     * The hardened ingest relay as an outbound smarthost
     * (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 7). On a
     * relay-fronted deployment, compose sends must leave THROUGH the relay over
     * the tunnel — otherwise every sent message's Received: chain leaks the main
     * box's IP. The relay accepts submission from the tunnel (permit_mynetworks on
     * the WireGuard subnet) with no auth and no TLS (the transport is already
     * confidential and authenticated by WireGuard). DKIM signing stays in-app
     * (SmtpProvider runs the signer); the relay only transports.
     */
    public static function fromRelaySmarthost($relay): self {
        $c = new self();
        $c->host       = trim((string)$relay->get('mrl_host'));
        $c->port       = 25;
        $c->encryption = 'none';
        $c->authMode   = self::AUTH_NONE;
        return $c;
    }

    /**
     * Build a transport that authenticates as an already-connected mailbox. Host/
     * port/encryption come from the account's PRESETS SMTP coordinates; the
     * credential comes from the account itself — XOAUTH2 (via the shared OAuth
     * grant) for oauth2 providers, the stored app password otherwise. No host,
     * port, or secret is re-typed. This is the only connected-account-specific
     * mechanic; everything downstream is the shared SMTP path.
     *
     * @throws SmtpConfigException when the account has no SMTP coordinates
     *         (e.g. a generic IMAP account with no SMTP host configured).
     */
    public static function fromConnectedAccount(InboundImapAccount $account): self {
        $preset = $account->getPreset();

        $host = $preset['smtp_host'] ?? null;
        if (!$host) {
            throw new SmtpConfigException(
                'No SMTP host is known for this connected account; a relay-class provider is required to send for it.');
        }

        $c = new self();
        $c->host       = $host;
        $c->port       = intval($preset['smtp_port'] ?? 587);
        $c->encryption = $preset['smtp_encryption'] ?? 'tls';
        $c->username   = (string)$account->get('iia_username');

        if ($account->isOAuth()) {
            require_once(PathHelper::getIncludePath('includes/XOAuth2TokenProvider.php'));
            $c->authMode = self::AUTH_XOAUTH2;
            $c->oauthProvider = new XOAuth2TokenProvider($account, $c->username);
        } else {
            $c->authMode = self::AUTH_PASSWORD;
            $c->password = (string)$account->getPassword();
        }
        return $c;
    }
}

class SmtpConfigException extends Exception {}
