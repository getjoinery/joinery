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
}