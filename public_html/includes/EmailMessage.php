<?php
require_once('PathHelper.php');
require_once('EmailTemplate.php');

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
     * @throws Exception if template is missing or malformed
     */
    public static function fromTemplate($templateName, $values = []) {
        try {
            // Create template directly with constructor
            $template = new EmailTemplate($templateName);
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
        $this->fromName = $name;
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
                    $this->recipients[] = ['email' => $e, 'name' => $n];
                }
            }
        } else {
            $this->recipients[] = ['email' => $email, 'name' => $name];
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
        $this->ccRecipients[] = ['email' => $email, 'name' => $name];
        return $this;
    }
    
    public function bcc($email, $name = null) {
        $this->bccRecipients[] = ['email' => $email, 'name' => $name];
        return $this;
    }
    
    public function subject($subject) {
        $this->subject = $subject;
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
            'name' => $fileName ?: 'attachment',
            'type' => $contentType ?: 'application/octet-stream',
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
            'name'   => $fileName ?: 'attachment',
            'type'   => $contentType ?: 'application/octet-stream',
            'cid'    => $cid,
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

    public function header($name, $value) {
        $this->headers[$name] = $value;
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
}