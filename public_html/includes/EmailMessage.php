<?php
require_once('PathHelper.php');
require_once('EmailTemplateRenderer.php');

/**
 * EmailMessage - one outgoing message, composed fluently and sent by EmailSender.
 *
 * @version 1.2 - attachmentContent(): one attachment's bytes and name, read at send time; an entry that
 *   cannot be read throws (an empty one is sent as zero bytes), so every API provider fails the send instead of dropping the file
 *   (specs/email_provider_sdk_removal.md B6)
 * @version 1.1 - toTransfer() / fromTransfer(): the message as plain data, so a sandboxed plugin's fence can hand
 *   it to the site (specs/sandboxed_plugin_email.md); an attachment travels as its bytes, never as a path.
 *   header() refuses a name that is not a header token, a value with CR, LF or NUL, and the headers the
 *   message's own fields write (From, To, Bcc, Subject...), which would go round the identity guard and the
 *   test-mode trap; a subject or display name keeps to one line; validate() checks Reply-To, Cc, Bcc and the
 *   Message-ID too (reviewer2 P2); an attachment's name and Content-ID keep to one line and its type starts
 *   with a type/subtype token (P9)
 */
class EmailMessage {
    private $from;
    private $fromName;
    private $replyTo;
    private $recipients = [];
    private $ccRecipients = [];
    private $bccRecipients = [];
    private $subject;
    private $htmlBody;
    private $textBody;
    private $attachments = [];
    private $headers = [];
    private $metadata = [];
    private $messageId;

    /**
     * Headers header() refuses: the message's own fields write them, and
     * the site's guards read those fields (the protected-identity guard reads
     * getFrom(), test mode rewrites the recipient lists), so one set as a
     * header would go round them.
     */
    const RESERVED_HEADERS = array('from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'return-path', 'subject',
        'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'content-disposition',
        'content-id', 'dkim-signature', 'received');

    /** A header name: an RFC 5322 field name. */
    const HEADER_NAME_PATTERN = '~^[\x21-\x39\x3B-\x7E]{1,64}$~D';

    /**
     * An attachment's Content-Type: one line that starts with a type/subtype
     * token (parameters after it, such as "; method=REQUEST", are kept), or
     * application/octet-stream.
     */
    private static function partType($type) {
        $type = trim((string)self::oneLine($type));
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*(\s*;.*)?$~D', $type)
            ? $type : 'application/octet-stream';
    }

    /** Text for a header line (a subject, a display name): one line, no NUL. */
    private static function oneLine($value) {
        return $value === null ? null : str_replace(array("\r\n", "\r", "\n", "\0"), ' ', (string)$value);
    }
    
    /**
     * Static constructor for common use case
     */
    public static function create($to, $subject, $body) {
        $message = new self();
        $message->to($to);
        $message->subject($subject);
        
        // Auto-detect HTML vs plain text
        if (strip_tags($body) !== $body) {
            $message->html($body);
        } else {
            $message->text($body);
        }
        
        return $message;
    }
    
    /**
     * Create from template
     * @param string|array|null $footer footer template name, ['body' => text], or null for default_footer
     * @throws Exception if template is missing or malformed
     */
    public static function fromTemplate($templateName, $values = [], $footer = null) {
        try {
            // Create template directly with constructor
            $template = new EmailTemplateRenderer($templateName, null, $footer);
            $template->fill_template($values);
        } catch (EmailTemplateError $e) {
            throw new Exception('Template \'' . $templateName . '\' error: ' . $e->getMessage());
        }
        
        $message = new self();
        
        // Only set values if they exist (template might not have subject)
        if ($template->getSubject()) {
            $message->subject($template->getSubject());
        }
        
        if ($template->getHtml()) {
            $message->html($template->getHtml());
        }
        
        if ($template->getText()) {
            $message->text($template->getText());
        }
        
        // If template produced no content, throw error
        if (!$template->hasContent()) {
            throw new Exception('Template \'' . $templateName . '\' produced no content after processing');
        }
        
        return $message;
    }
    
    /**
     * Fluent interface for building emails
     */
    public function from($email, $name = null) {
        $this->from = $email;
        $this->fromName = self::oneLine($name);
        return $this;
    }
    
    public function replyTo($email) {
        $this->replyTo = $email;
        return $this;
    }
    
    public function to($email, $name = null) {
        if (is_array($email)) {
            // Support array of recipients
            foreach ($email as $e => $n) {
                if (is_numeric($e)) {
                    // Indexed array
                    $this->recipients[] = ['email' => $n, 'name' => null];
                } else {
                    // Associative array
                    $this->recipients[] = ['email' => $e, 'name' => self::oneLine($n)];
                }
            }
        } else {
            $this->recipients[] = ['email' => $email, 'name' => self::oneLine($name)];
        }
        return $this;
    }
    
    /**
     * Narrow the To list to $emails, keeping each entry's display name.
     *
     * Written for a path that delivers SOME recipients by another route and
     * must then send to only the rest: Joinery Direct addresses one person at a
     * time, so a message to three people may go direct to two and take the
     * ordinary transport for the third. Dropping the already-delivered
     * addresses here is what stops that third send from being a duplicate for
     * the other two.
     */
    public function keepOnlyRecipients(array $emails) {
        $keep = array_map('strtolower', array_map('strval', $emails));
        $this->recipients = array_values(array_filter($this->recipients, function ($r) use ($keep) {
            return in_array(strtolower((string)$r['email']), $keep, true);
        }));
        return $this;
    }

    /**
     * Replace every recipient — To, Cc and Bcc — with one address.
     *
     * The test-mode redirect (email_test_mode): the message must reach only
     * the trap, so Cc and Bcc are cleared rather than kept, and the original
     * To list is returned so the caller can name it in the subject and the
     * debug log. Original Cc/Bcc are folded into that list too — a redirect
     * that reported only the To line would hide who else was written to.
     *
     * @return string[] every address the message was going to reach
     */
    public function redirectAllRecipientsTo($email) {
        $originals = array();
        foreach (array($this->recipients, $this->ccRecipients, $this->bccRecipients) as $list) {
            foreach ($list as $r) {
                $address = trim((string)$r['email']);
                if ($address !== '') {
                    $originals[] = $address;
                }
            }
        }
        $this->recipients = [['email' => $email, 'name' => null]];
        $this->ccRecipients = [];
        $this->bccRecipients = [];
        return array_values(array_unique($originals));
    }

    public function cc($email, $name = null) {
        $this->ccRecipients[] = ['email' => $email, 'name' => self::oneLine($name)];
        return $this;
    }
    
    public function bcc($email, $name = null) {
        $this->bccRecipients[] = ['email' => $email, 'name' => self::oneLine($name)];
        return $this;
    }
    
    public function subject($subject) {
        $this->subject = self::oneLine($subject);
        return $this;
    }
    
    public function html($html) {
        $this->htmlBody = $html;
        
        // Auto-generate text version if not set
        if (empty($this->textBody)) {
            $this->textBody = $this->htmlToText($html);
        }
        
        return $this;
    }
    
    public function text($text) {
        $this->textBody = $text;
        return $this;
    }
    
    public function attach($filePath, $fileName = null) {
        if (!file_exists($filePath)) {
            throw new Exception("Attachment file not found: $filePath");
        }
        
        $this->attachments[] = [
            'path' => $filePath,
            'name' => $fileName ?: basename($filePath)
        ];
        
        return $this;
    }
    
    /**
     * Attach in-memory bytes (no file on disk required). The counterpart to
     * attach() for content the caller already holds — re-attached fetched/parsed
     * originals (reply/forward), generated reports, etc. Mapped to PHPMailer's
     * addStringAttachment() in SmtpMailer and to Mailgun's fileContent.
     */
    public function attachData($data, $fileName, $contentType = 'application/octet-stream') {
        $this->attachments[] = [
            'data' => $data,
            'name' => self::oneLine($fileName ?: 'attachment'),
            'type' => self::partType($contentType),
        ];

        return $this;
    }

    /**
     * Attach in-memory bytes as an INLINE (embedded) part — the image renders in
     * the HTML body via a cid: reference rather than being listed as a downloadable
     * file. Use for a logo, a forwarded inline picture, a chart in a report: the
     * body references cid:$cid and this part supplies the bytes for it.
     *
     * $cid is a BARE Content-ID token (no angle brackets, e.g. "logo123"); the body
     * writes cid:logo123. Each transport maps this to its native inline mechanism
     * (PHPMailer addStringEmbeddedImage; SendGrid/Postmark/Mailjet Content-ID;
     * Mailgun inline-with-filename). Transports whose API cannot carry a Content-ID
     * (Resend, Brevo) degrade to a regular attachment and log a marker.
     *
     * Inline entries carry the same data/name/type as attachData() plus 'cid' and
     * 'inline' => true, so a transport that ignores inline still sends the bytes.
     */
    public function attachInlineData($data, $cid, $fileName, $contentType = 'application/octet-stream') {
        $this->attachments[] = [
            'data'   => $data,
            'name'   => self::oneLine($fileName ?: 'attachment'),
            'type'   => self::partType($contentType),
            'cid'    => self::oneLine($cid),
            'inline' => true,
        ];

        return $this;
    }

    /**
     * Pin the outgoing Message-ID (RFC angle-bracketed form, e.g. <id@domain>).
     * When set, transports stamp this exact value instead of auto-generating one,
     * so a stored copy can be reconciled to the sent message by Message-ID.
     */
    public function messageId($id) {
        $this->messageId = $id;
        return $this;
    }

    /**
     * @throws InvalidArgumentException for a name that is not a header token, one the message's own
     *   fields write (RESERVED_HEADERS), or a value with CR, LF or NUL
     */
    public function header($name, $value) {
        $name = (string)$name;
        if (!preg_match(self::HEADER_NAME_PATTERN, $name)) {
            throw new InvalidArgumentException('A header name must be a plain token.');
        }
        if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
            throw new InvalidArgumentException('The ' . $name . ' header is the message\'s own; set it with its method.');
        }
        if (!is_scalar($value) || preg_match('~[\r\n\x00]~', (string)$value)) {
            throw new InvalidArgumentException('The value of the ' . $name . ' header must be one line of text.');
        }
        $this->headers[$name] = (string)$value;
        return $this;
    }
    
    public function metadata($key, $value = null) {
        if (is_array($key)) {
            $this->metadata = array_merge($this->metadata, $key);
        } else {
            $this->metadata[$key] = $value;
        }
        return $this;
    }
    
    /**
     * Convert HTML to text
     */
    private function htmlToText($html) {
        // Remove HTML comments
        $text = preg_replace('/<!--.*?-->/s', '', $html);
        
        // Replace breaks and paragraphs with newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        
        // Remove remaining tags
        $text = strip_tags($text);
        
        // Convert entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s+/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        return trim($text);
    }
    
    /**
     * Validate message before sending
     */
    public function validate() {
        $errors = [];
        
        if (empty($this->recipients)) {
            $errors[] = 'No recipients specified';
        }
        
        if (empty($this->subject)) {
            $errors[] = 'No subject specified';
        }
        
        if (empty($this->htmlBody) && empty($this->textBody)) {
            $errors[] = 'No message body specified';
        }
        
        if (!empty($this->from) && !filter_var($this->from, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid from email address';
        }
        
        foreach ($this->recipients as $recipient) {
            if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid recipient email: {$recipient['email']}";
            }
        }
        foreach (array_merge($this->ccRecipients, $this->bccRecipients) as $recipient) {
            if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid Cc or Bcc email: {$recipient['email']}";
            }
        }
        if (!empty($this->replyTo) && !filter_var($this->replyTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid reply-to email address';
        }
        if ($this->messageId !== null && preg_match('~[\r\n\x00]~', (string)$this->messageId)) {
            $errors[] = 'Invalid Message-ID';
        }
        
        return $errors;
    }
    
    /**
     * Get data for sending
     */
    public function getFrom() { return $this->from; }
    public function getFromName() { return $this->fromName; }
    public function getReplyTo() { return $this->replyTo; }
    public function getRecipients() { return $this->recipients; }
    public function getCc() { return $this->ccRecipients; }
    public function getBcc() { return $this->bccRecipients; }
    public function getSubject() { return $this->subject; }
    public function getHtmlBody() { return $this->htmlBody; }
    public function getTextBody() { return $this->textBody; }
    public function getAttachments() { return $this->attachments; }
    public function getHeaders() { return $this->headers; }
    public function getMetadata() { return $this->metadata; }
    public function getMessageId() { return $this->messageId; }

    /**
     * One attachment's bytes and name, read now, for a provider building its
     * request. An entry that cannot be read (a path that cannot be read, or
     * neither a path nor data) throws: the send fails with that reason rather
     * than going out without the file. An empty file, or data that is an empty
     * string, is an attachment of zero bytes and is sent as one.
     *
     * @return array{bytes: string, name: string, type: string}
     * @throws RuntimeException
     */
    public static function attachmentContent(array $attachment): array {
        $type = (string)($attachment['type'] ?? '');
        if (!empty($attachment['path'])) {
            $bytes = is_readable($attachment['path']) ? @file_get_contents($attachment['path']) : false;
            if (!is_string($bytes)) {
                throw new RuntimeException('Attachment file could not be read: ' . basename((string)$attachment['path']));
            }
            $name = (string)($attachment['name'] ?? '') ?: basename((string)$attachment['path']);
            return array('bytes' => $bytes, 'name' => $name,
                'type' => $type !== '' ? $type : self::typeFromName($name));
        }
        if (isset($attachment['data'])) {
            return array('bytes' => (string)$attachment['data'],
                'name' => (string)($attachment['name'] ?? '') ?: 'attachment',
                'type' => $type !== '' ? $type : 'application/octet-stream');
        }
        throw new RuntimeException('Attachment has neither a file nor data.');
    }

    /**
     * The message as plain data (JSON-safe), for another process to send.
     * A file attached by path is read here, in the process that attached it,
     * and travels as its bytes: the receiving side never opens a path it was
     * handed (fromTransfer()).
     *
     * @throws Exception when an attached file cannot be read
     */
    public function toTransfer(): array {
        $attachments = array();
        foreach ($this->attachments as $a) {
            if (isset($a['path'])) {
                $bytes = @file_get_contents($a['path']);
                if (!is_string($bytes)) {
                    throw new Exception('Attachment file could not be read: ' . $a['path']);
                }
                // No type, as attach() gives none: the receiving side takes it
                // from the name, as PHPMailer does for a path (fromTransfer()).
                $a = array('data' => $bytes, 'name' => $a['name'], 'type' => '');
            }
            $a['data'] = base64_encode((string)$a['data']);
            $attachments[] = $a;
        }
        return array(
            'from' => $this->from, 'from_name' => $this->fromName, 'reply_to' => $this->replyTo,
            'to' => $this->recipients, 'cc' => $this->ccRecipients, 'bcc' => $this->bccRecipients,
            'subject' => $this->subject, 'html' => $this->htmlBody, 'text' => $this->textBody,
            'attachments' => $attachments, 'headers' => $this->headers, 'metadata' => $this->metadata,
            'message_id' => $this->messageId,
        );
    }

    /** A file's type from its name, as PHPMailer gives one to a file attached by path. */
    private static function typeFromName(string $name): string {
        $autoload = PathHelper::getComposerAutoloadPath();
        if (is_file($autoload)) {
            require_once($autoload);
        }
        if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return \PHPMailer\PHPMailer\PHPMailer::filenameToType($name);
        }
        return 'application/octet-stream';
    }

    /**
     * A message from toTransfer()'s data. Every field is held to its shape;
     * an attachment is bytes only, so nothing here reads a file.
     *
     * @throws InvalidArgumentException naming the field that is not as toTransfer() writes it
     */
    public static function fromTransfer(array $d): self {
        $m = new self();
        $text = function ($value, string $field) {
            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException($field . ' must be text.');
            }
            return $value;
        };
        $people = function ($list, string $field) use ($text) {
            if ($list === null) {
                return array();
            }
            if (!is_array($list) || !array_is_list($list)) {
                throw new InvalidArgumentException($field . ' must be a list.');
            }
            $out = array();
            foreach ($list as $r) {
                if (!is_array($r) || !is_string($r['email'] ?? null)) {
                    throw new InvalidArgumentException('Each of ' . $field . ' needs an email address.');
                }
                $out[] = array('email' => $r['email'], 'name' => self::oneLine($text($r['name'] ?? null, $field . ' name')));
            }
            return $out;
        };
        $m->from = $text($d['from'] ?? null, 'from');
        $m->fromName = self::oneLine($text($d['from_name'] ?? null, 'from_name'));
        $m->replyTo = $text($d['reply_to'] ?? null, 'reply_to');
        $m->recipients = $people($d['to'] ?? null, 'to');
        $m->ccRecipients = $people($d['cc'] ?? null, 'cc');
        $m->bccRecipients = $people($d['bcc'] ?? null, 'bcc');
        $m->subject = self::oneLine($text($d['subject'] ?? null, 'subject'));
        $m->htmlBody = $text($d['html'] ?? null, 'html');
        $m->textBody = $text($d['text'] ?? null, 'text');
        $m->messageId = $text($d['message_id'] ?? null, 'message_id');
        foreach (array('headers', 'metadata') as $field) {
            $value = $d[$field] ?? array();
            if (!is_array($value)) {
                throw new InvalidArgumentException($field . ' must be an object.');
            }
        }
        foreach ((array)($d['headers'] ?? array()) as $name => $value) {
            $m->header((string)$name, $value);
        }
        $m->metadata = (array)($d['metadata'] ?? array());
        $list = $d['attachments'] ?? array();
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidArgumentException('attachments must be a list.');
        }
        foreach ($list as $a) {
            $bytes = is_array($a) && is_string($a['data'] ?? null) ? base64_decode($a['data'], true) : false;
            if ($bytes === false || isset($a['path'])) {
                throw new InvalidArgumentException('An attachment must be its bytes.');
            }
            $name = (string)$text($a['name'] ?? 'attachment', 'attachment name');
            $type = (string)$text($a['type'] ?? '', 'attachment type');
            $type = $type !== '' ? $type : self::typeFromName($name);
            if (!empty($a['inline'])) {
                $m->attachInlineData($bytes, (string)$text($a['cid'] ?? '', 'attachment cid'), $name, $type);
            } else {
                $m->attachData($bytes, $name, $type);
            }
        }
        return $m;
    }
}