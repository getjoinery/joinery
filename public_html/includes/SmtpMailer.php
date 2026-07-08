<?php

require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/SmtpConfig.php'));

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * SmtpMailer - PHPMailer configured for SMTP from an SmtpConfig.
 *
 * One construction model: the constructor takes an optional SmtpConfig and
 * defaults to SmtpConfig::fromSettings(), so the historical no-arg
 * `new SmtpMailer()` keeps reading global smtp_* with password auth unchanged.
 * Pass a config to authenticate as a connected account (XOAUTH2 or app password)
 * or through the inbound-forwarding relay — the only thing that varies is the
 * SmtpConfig.
 *
 * applyMessage() is the single EmailMessage→PHPMailer mapping; every structured
 * SMTP send (global, connected-account, per-mailbox) is "new SmtpMailer($config),
 * applyMessage($m), send()".
 *
 * @version 2.2
 */
class SmtpMailer extends PHPMailer {
    // Only encoding is truly universal
    const SMTP_ENCODING = 'quoted-printable';

    function __construct(?SmtpConfig $config = null) {
        $config = $config ?: SmtpConfig::fromSettings();

        // Configure SMTP
        $this->isSMTP();

        // Set encoding (only truly universal value)
        $this->Encoding = self::SMTP_ENCODING;

        $this->Host = $config->host ?: '';
        $this->Port = intval($config->port ?: 25);

        // Domain-specific settings (global path supplies these; the per-account
        // and forwarding paths leave them empty, which is correct).
        $this->Helo = $config->helo ?: '';
        $this->Hostname = $config->hostname ?: '';
        $this->Sender = $config->sender ?: '';

        // Encryption: an explicit value wins; null means auto-detect from port
        // (the historical SmtpMailer behavior).
        if ($config->encryption === null) {
            $this->SMTPSecure = self::encryptionForPort($this->Port);
        } else {
            $this->SMTPSecure = self::mapEncryption($config->encryption);
        }

        // Explicit 'none' means send in the clear — disable PHPMailer's opportunistic
        // auto-STARTTLS. Otherwise, when the server advertises STARTTLS (e.g. the relay
        // smarthost over the already-encrypted WireGuard tunnel, whose Postfix offers a
        // self-signed cert), PHPMailer would upgrade and fail the handshake. The null
        // (auto-detect) path keeps opportunistic TLS.
        if ($config->encryption === 'none') {
            $this->SMTPAutoTLS = false;
        }

        // Authentication
        switch ($config->authMode) {
            case SmtpConfig::AUTH_PASSWORD:
                $this->SMTPAuth = true;
                $this->Username = $config->username ?: '';
                $this->Password = $config->password ?: '';
                break;

            case SmtpConfig::AUTH_XOAUTH2:
                $this->SMTPAuth = true;
                $this->AuthType = 'XOAUTH2';
                $this->Username = $config->username ?: '';
                if ($config->oauthProvider) {
                    $this->setOAuth($config->oauthProvider);
                }
                break;

            case SmtpConfig::AUTH_NONE:
            default:
                // Unauthenticated relay (e.g. local Postfix on port 25).
                break;
        }
    }

    /** Map an SmtpConfig encryption keyword to the PHPMailer SMTPSecure constant. */
    private static function mapEncryption(string $encryption): string {
        switch ($encryption) {
            case 'ssl':
                return PHPMailer::ENCRYPTION_SMTPS;   // implicit TLS / SMTPS
            case 'tls':
                return PHPMailer::ENCRYPTION_STARTTLS; // STARTTLS
            case 'none':
            default:
                return '';
        }
    }

    /** Historical port→encryption auto-detection used when encryption is null. */
    private static function encryptionForPort(int $port): string {
        switch ($port) {
            case 465:
                return PHPMailer::ENCRYPTION_SMTPS; // SSL
            case 587:
            case 2525:
                return PHPMailer::ENCRYPTION_STARTTLS; // TLS
            case 25:
            default:
                // Port 25 typically no encryption (but can support STARTTLS)
                return '';
        }
    }

    /**
     * The single EmailMessage→PHPMailer mapping. Stamps From, recipients, cc/bcc,
     * reply-to, custom headers, and attachments onto this mailer. Shared by the
     * global, connected-account, and per-mailbox SMTP send paths so the mapping
     * lives in exactly one place.
     */
    function applyMessage(EmailMessage $message): void {
        $this->setFrom($message->getFrom(), $message->getFromName());
        $this->Subject = $message->getSubject();

        // A caller-pinned Message-ID wins over PHPMailer's auto-generated one so a
        // later-observed copy reconciles by Message-ID (reply/forward threading).
        if ($message->getMessageId()) {
            $this->MessageID = $message->getMessageId();
        }

        // HTML message: HTML body + plain-text alternative. Text-only message:
        // send as plain text (an empty Body under isHTML(true) is rejected by
        // PHPMailer as "Message body empty"), so plain-text mail — e.g. SMS-gateway
        // codes — sends correctly over the SMTP/connected-account path too.
        $html = $message->getHtmlBody();
        $text = $message->getTextBody();
        if ($html !== null && $html !== '') {
            $this->isHTML(true);
            $this->Body = $html;
            if ($text !== null && $text !== '') {
                $this->AltBody = $text;
            }
        } else {
            $this->isHTML(false);
            $this->Body = (string)$text;
        }

        foreach ($message->getRecipients() as $recipient) {
            $this->addAddress($recipient['email'], $recipient['name']);
        }
        foreach ($message->getCc() as $cc) {
            $this->addCC($cc['email'], $cc['name']);
        }
        foreach ($message->getBcc() as $bcc) {
            $this->addBCC($bcc['email'], $bcc['name']);
        }
        if ($replyTo = $message->getReplyTo()) {
            $this->addReplyTo($replyTo);
        }
        foreach ($message->getHeaders() as $name => $value) {
            $this->addCustomHeader($name, $value);
        }
        foreach ($message->getAttachments() as $attachment) {
            if (!empty($attachment['cid'])) {
                // Inline (embedded) image: render in the body via its cid: reference.
                // PHPMailer takes an arbitrary Content-ID, so the bare token maps exactly.
                $this->addStringEmbeddedImage(
                    $attachment['data'],
                    $attachment['cid'],
                    $attachment['name'],
                    PHPMailer::ENCODING_BASE64,
                    $attachment['type'] ?? 'application/octet-stream'
                );
            } elseif (isset($attachment['data'])) {
                $this->addStringAttachment(
                    $attachment['data'],
                    $attachment['name'],
                    PHPMailer::ENCODING_BASE64,
                    $attachment['type'] ?? 'application/octet-stream'
                );
            } else {
                $this->addAttachment($attachment['path'], $attachment['name']);
            }
        }
    }
}

// Maintain backward compatibility with old class name
class_alias('SmtpMailer', 'systemmailer');

?>
