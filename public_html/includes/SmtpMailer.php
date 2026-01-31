<?php

require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getComposerAutoloadPath());

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class SmtpMailer extends PHPMailer {
    // Only encoding is truly universal
    const SMTP_ENCODING = 'quoted-printable';
    
    function __construct() {
        $settings = Globalvars::get_instance();
        
        // Configure SMTP
        $this->isSMTP();
        
        // Get all configurable settings (no hardcoded defaults)
        $this->Host = $settings->get_setting('smtp_host') ?: '';
        $this->Port = intval($settings->get_setting('smtp_port') ?: 25);
        
        // Set encoding (only truly universal value)
        $this->Encoding = self::SMTP_ENCODING;
        
        // Get domain-specific settings
        $this->Helo = $settings->get_setting('smtp_helo') ?: '';
        $this->Hostname = $settings->get_setting('smtp_hostname') ?: '';
        $this->Sender = $settings->get_setting('smtp_sender') ?: '';
        
        // Auto-detect encryption based on port
        switch($this->Port) {
            case 465:
                $this->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
                break;
            case 587:
            case 2525:
                $this->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
                break;
            case 25:
            default:
                // Port 25 typically no encryption (but can support STARTTLS)
                $this->SMTPSecure = '';
                break;
        }
        
        // Support for authenticated SMTP
        if ($settings->get_setting('smtp_auth')) {
            $this->SMTPAuth = true;
            $this->Username = $settings->get_setting('smtp_username') ?: '';
            $this->Password = $settings->get_setting('smtp_password') ?: '';
        }
    }
}

// Maintain backward compatibility with old class name
class_alias('SmtpMailer', 'systemmailer');

?>