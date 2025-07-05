<?php
header('Content-Type: text/html; charset=UTF-8');

class EmailAuthChecker {
    private $domain;
    private $results = [];
    
    public function __construct($domain) {
        $this->domain = strtolower(trim($domain));
        
        // Set time limit based on scan mode
        if (!empty($domain)) {
            $isComprehensive = isset($_GET['complete']) && $_GET['complete'] == '1';
            set_time_limit($isComprehensive ? 120 : 30); // 2 minutes for comprehensive, 30 seconds for quick
        }
    }
    
    public function checkAll() {
        $this->results['domain'] = $this->domain;
        $this->results['spf'] = $this->checkSPF();
        $this->results['dkim'] = $this->checkDKIM();
        $this->results['dmarc'] = $this->checkDMARC();
        return $this->results;
    }
    
    private function checkSPF() {
        $spf = [
            'record' => '',
            'valid' => false,
            'mechanisms' => [],
            'issues' => [],
            'recommendations' => []
        ];
        
        // Get TXT records for the domain
        $txtRecords = dns_get_record($this->domain, DNS_TXT);
        
        if (!$txtRecords) {
            $spf['issues'][] = 'No TXT records found for domain';
            $spf['recommendations'][] = 'Add an SPF record to specify which servers are authorized to send email for your domain';
            return $spf;
        }
        
        // Find SPF record
        $spfRecord = null;
        $spfCount = 0;
        
        foreach ($txtRecords as $record) {
            if (isset($record['txt']) && strpos($record['txt'], 'v=spf1') === 0) {
                $spfRecord = $record['txt'];
                $spfCount++;
            }
        }
        
        if ($spfCount > 1) {
            $spf['issues'][] = 'Multiple SPF records found - only one SPF record is allowed per domain';
        }
        
        if (!$spfRecord) {
            $spf['issues'][] = 'No SPF record found';
            $spf['recommendations'][] = 'Add an SPF record starting with "v=spf1" to your DNS TXT records';
            return $spf;
        }
        
        $spf['record'] = $spfRecord;
        $spf['valid'] = true;
        
        // Parse SPF mechanisms
        $mechanisms = explode(' ', $spfRecord);
        array_shift($mechanisms); // Remove v=spf1
        
        $lookupCount = 0;
        $includedServices = [];
        
        foreach ($mechanisms as $mechanism) {
            $mechanism = trim($mechanism);
            if (empty($mechanism)) continue;
            
            $spf['mechanisms'][] = $mechanism;
            
            // Count DNS lookups
            if (preg_match('/^(include:|a:|mx:|exists:|redirect=)/', $mechanism)) {
                $lookupCount++;
            }
            
            // Identify email services from include mechanisms
            if (preg_match('/^include:(.+)/', $mechanism, $matches)) {
                $domain = $matches[1];
                $service = $this->identifyEmailService($domain);
                if ($service) {
                    $includedServices[] = $service;
                }
            }
            
            // Check for common issues
            if ($mechanism === 'a') {
                $spf['issues'][] = 'Using bare "a" mechanism - this allows any server with an A record for your domain to send email';
                $spf['recommendations'][] = 'Consider replacing "a" with specific IP addresses using "ip4:" or "ip6:" mechanisms';
            }
            
            if ($mechanism === 'mx') {
                $spf['issues'][] = 'Using bare "mx" mechanism - this allows ALL servers in your MX records to send email, including backup/receiving-only servers';
                $spf['recommendations'][] = 'Consider replacing "mx" with specific servers using "ip4:", "ip6:", or "a:mailserver.domain.com" mechanisms';
            }
            
            if (strpos($mechanism, '+all') !== false) {
                $spf['issues'][] = 'Using "+all" mechanism - this allows ANY server on the internet to send email for your domain (extremely insecure)';
                $spf['recommendations'][] = 'Change "+all" to "~all" (soft fail - mark as suspicious) or "-all" (hard fail - reject email)';
            }
            
            if (strpos($mechanism, '?all') !== false) {
                $spf['issues'][] = 'Using "?all" mechanism - this provides no protection (neutral result)';
                $spf['recommendations'][] = 'Change "?all" to "~all" (soft fail) or "-all" (hard fail) for better security';
            }
            
            // Check for overly broad IP ranges
            if (preg_match('/ip4:(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $mechanism, $matches)) {
                $cidr = intval($matches[2]);
                if ($cidr < 24) {
                    $hosts = pow(2, 32 - $cidr);
                    $spf['issues'][] = "IP4 range /$cidr includes $hosts potential hosts - consider using smaller, more specific ranges";
                }
            }
            
            // Check for deprecated mechanisms
            if (strpos($mechanism, 'ptr') !== false) {
                $spf['issues'][] = 'Using "ptr" mechanism - this is deprecated and not recommended due to performance and reliability issues';
                $spf['recommendations'][] = 'Replace "ptr" mechanism with "ip4:", "ip6:", or "a:" mechanisms';
            }
        }
        
        if ($lookupCount > 10) {
            $spf['issues'][] = "Exceeds DNS lookup limit: $lookupCount lookups (SPF standard allows maximum of 10)";
            $spf['recommendations'][] = 'Reduce DNS lookups by: 1) Replacing "include:" with direct "ip4:/ip6:" mechanisms, 2) Combining multiple includes into fewer domains, 3) Using IP addresses instead of hostnames';
        } elseif ($lookupCount > 8) {
            $spf['issues'][] = "High DNS lookup count: $lookupCount lookups (approaching the limit of 10)";
            $spf['recommendations'][] = 'Consider reducing DNS lookups to stay well under the 10-lookup limit for better reliability';
        }
        
        // Check for proper ending
        $lastMechanism = end($mechanisms);
        if (!in_array($lastMechanism, ['~all', '-all', '+all', '?all'])) {
            $spf['issues'][] = 'SPF record missing "all" mechanism - emails from unlisted servers will have undefined behavior';
            $spf['recommendations'][] = 'Add "~all" (soft fail - recommended for most domains) or "-all" (hard fail - strict policy) at the end of your SPF record';
        }
        
        // Provide explanation of "all" mechanisms
        if (in_array('-all', $mechanisms)) {
            $spf['recommendations'][] = 'Using "-all" (hard fail) - very strict policy that will reject all unauthorized email';
        } elseif (in_array('~all', $mechanisms)) {
            $spf['recommendations'][] = 'Using "~all" (soft fail) - good balance of security and deliverability';
        }
        
        if (empty($spf['issues'])) {
            $spf['recommendations'][] = 'SPF record configuration looks good!';
        }
        
        // Add identified services info
        if (!empty($includedServices)) {
            $spf['services'] = $includedServices;
        }
        
        return $spf;
    }
    
    private function identifyEmailService($domain) {
        $domain = strtolower($domain);
        
        $services = [
            '_spf.google.com' => 'Google Workspace (Gmail)',
            'include.outlook.com' => 'Microsoft 365 (Outlook)',
            '_spf.protonmail.ch' => 'ProtonMail',
            'mailgun.org' => 'Mailgun',
            'sendgrid.net' => 'SendGrid',
            '_spf.elasticemail.com' => 'Elastic Email',
            'spf.mandrillapp.com' => 'Mandrill (Mailchimp)',
            '_spf.salesforce.com' => 'Salesforce',
            'amazonses.com' => 'Amazon SES',
            'servers.mcsv.net' => 'Mailchimp',
            '_spf.constantcontact.com' => 'Constant Contact',
            'spf.protection.outlook.com' => 'Microsoft Exchange Online',
            '_spf.mailjet.com' => 'Mailjet',
            'spf.sendinblue.com' => 'Sendinblue (Brevo)',
            '_spf.zoho.com' => 'Zoho Mail'
        ];
        
        foreach ($services as $spfDomain => $serviceName) {
            if (strpos($domain, $spfDomain) !== false) {
                return $serviceName;
            }
        }
        
        // Check for common patterns
        if (strpos($domain, 'google') !== false) return 'Google Services';
        if (strpos($domain, 'outlook') !== false || strpos($domain, 'office365') !== false) return 'Microsoft Services';
        if (strpos($domain, 'mailgun') !== false) return 'Mailgun';
        if (strpos($domain, 'sendgrid') !== false) return 'SendGrid';
        if (strpos($domain, 'amazon') !== false) return 'Amazon SES';
        
        return null;
    }
    
    private function checkDKIM() {
        $dkim = [
            'selectors_found' => [],
            'selectors_checked' => [],
            'discovery_methods' => [],
            'scan_mode' => isset($_GET['complete']) && $_GET['complete'] == '1' ? 'comprehensive' : 'quick',
            'issues' => [],
            'recommendations' => []
        ];
        
        // DKIM selector discovery - quick or comprehensive based on GET parameter
        $this->discoverDKIMSelectors($dkim);
        
        if (empty($dkim['selectors_found'])) {
            $scanType = $dkim['scan_mode'] === 'comprehensive' ? 'comprehensive scan' : 'quick scan';
            $dkim['issues'][] = "No DKIM records found after $scanType";
            $dkim['recommendations'][] = 'Set up DKIM signing for your email server and publish the public key in DNS';
            $dkim['recommendations'][] = 'DKIM records are published at selector._domainkey.yourdomain.com';
            if ($dkim['scan_mode'] === 'quick') {
                $dkim['recommendations'][] = 'Try the comprehensive scan to check more potential selectors';
            }
        } else {
            $validCount = count(array_filter($dkim['selectors_found'], function($s) { return $s['valid']; }));
            $dkim['recommendations'][] = "Found $validCount valid DKIM selector(s) - ensure your email server is configured to sign outgoing emails";
        }
        
        return $dkim;
    }
    
    private function discoverDKIMSelectors(&$dkim) {
        $foundSelectors = [];
        $isComprehensive = $dkim['scan_mode'] === 'comprehensive';
        
        // Method 1: Common selectors wordlist (always run)
        $dkim['discovery_methods'][] = $isComprehensive ? 'Comprehensive selectors wordlist' : 'Top 10 common selectors';
        $commonSelectors = $isComprehensive ? $this->getComprehensiveSelectorList() : $this->getTopCommonSelectors();
        
        foreach ($commonSelectors as $selector) {
            if ($this->checkSingleDKIMSelector($selector, $dkim)) {
                $foundSelectors[] = $selector;
            }
        }
        
        // Only run additional methods in comprehensive mode
        if ($isComprehensive) {
            // Method 2: Pattern-based discovery from found selectors
            if (!empty($foundSelectors)) {
                $dkim['discovery_methods'][] = 'Pattern-based discovery';
                $this->discoverSelectorPatterns($foundSelectors, $dkim);
            }
            
            // Method 3: Date-based selectors (many orgs rotate keys)
            $dkim['discovery_methods'][] = 'Date-based selector discovery';
            $this->discoverDateBasedSelectors($dkim);
            
            // Method 4: Provider-specific pattern discovery
            $dkim['discovery_methods'][] = 'Provider-specific patterns';
            $this->discoverProviderPatterns($dkim);
            
            // Method 5: Try zone walking (limited effectiveness)
            $dkim['discovery_methods'][] = 'DNS enumeration attempts';
            $this->attemptDNSEnumeration($dkim);
        }
    }
    
    private function getTopCommonSelectors() {
        // Top 10 most commonly used DKIM selectors - carefully chosen for maximum coverage
        return [
            'default',      // Most common generic selector
            'selector1',    // Microsoft Office 365, others
            'selector2',    // Microsoft Office 365 backup
            'google',       // Google Workspace
            'protonmail',   // ProtonMail primary
            'mx',          // Mailgun and others
            'mail',        // Generic mail servers
            'dkim',        // Generic DKIM
            'key1',        // Common key naming
            's1'           // Short selector naming
        ];
    }
    
    private function getComprehensiveSelectorList() {
        return [
            // Generic common selectors
            'default', 'selector', 'selector1', 'selector2', 'selector3', 'selector4', 'selector5',
            'dkim', 'mail', 'email', 'smtp', 'key', 'key1', 'key2', 'key3', 'sig', 'signature',
            's1', 's2', 's3', 's4', 's5', 'k1', 'k2', 'k3', 'mx', 'mx1', 'mx2',
            
            // Email providers
            'google', 'gmail', 'googlemail', 'outlook', 'hotmail', 'live', 'office365', 'o365',
            'yahoo', 'aol', 'protonmail', 'protonmail2', 'protonmail3', 'pm', 'tutanota',
            
            // Email services
            'mailgun', 'mg', 'sendgrid', 'sg', 'amazonses', 'ses', 'mandrill', 'mailchimp',
            'constantcontact', 'mailjet', 'sendinblue', 'sparkpost', 'postmark', 'elastic',
            'sendmail', 'postfix', 'exim', 'qmail', 'exchange', 'zimbra',
            
            // Hosting providers
            'cpanel', 'plesk', 'directadmin', 'godaddy', 'namecheap', 'bluehost', 'hostgator',
            'siteground', 'dreamhost', 'aws', 'azure', 'gcp', 'cloudflare', 'fastmail',
            
            // Years and months (key rotation patterns)
            '2024', '2023', '2022', '2021', '2020', '2025',
            'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
            'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
            
            // Common organizational patterns
            'prod', 'production', 'staging', 'test', 'dev', 'development', 'live',
            'primary', 'secondary', 'backup', 'main', 'alt', 'alternate',
            'internal', 'external', 'public', 'private', 'secure',
            
            // Single letters and numbers (often used)
            'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
            '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12',
            
            // Subdomain patterns
            'www', 'mail', 'email', 'mx', 'smtp', 'imap', 'pop', 'pop3', 'webmail', 'autodiscover'
        ];
    }
    
    private function checkSingleDKIMSelector($selector, &$dkim) {
        $dkim['selectors_checked'][] = $selector;
        $dkimDomain = $selector . '._domainkey.' . $this->domain;
        
        // Small delay only for comprehensive scans to be respectful to DNS servers
        if ($dkim['scan_mode'] === 'comprehensive') {
            usleep(50000); // 50ms delay
        }
        
        // Check for TXT records first
        $txtRecords = @dns_get_record($dkimDomain, DNS_TXT);
        
        if ($txtRecords) {
            foreach ($txtRecords as $record) {
                if (isset($record['txt']) && $this->isDKIMRecord($record['txt'])) {
                    $dkim['selectors_found'][$selector] = [
                        'record' => $record['txt'],
                        'valid' => true,
                        'type' => 'TXT',
                        'details' => $this->parseDKIMRecord($record['txt'])
                    ];
                    return true;
                }
            }
        }
        
        // Check for CNAME records
        $cnameRecords = @dns_get_record($dkimDomain, DNS_CNAME);
        
        if ($cnameRecords) {
            foreach ($cnameRecords as $record) {
                if (isset($record['target'])) {
                    $targetTxtRecords = @dns_get_record($record['target'], DNS_TXT);
                    
                    if ($targetTxtRecords) {
                        foreach ($targetTxtRecords as $txtRecord) {
                            if (isset($txtRecord['txt']) && $this->isDKIMRecord($txtRecord['txt'])) {
                                $dkim['selectors_found'][$selector] = [
                                    'record' => $txtRecord['txt'],
                                    'valid' => true,
                                    'type' => 'CNAME',
                                    'target' => $record['target'],
                                    'details' => $this->parseDKIMRecord($txtRecord['txt'])
                                ];
                                return true;
                            }
                        }
                    }
                    
                    // CNAME exists but no valid DKIM at target
                    $dkim['selectors_found'][$selector] = [
                        'record' => 'CNAME to ' . $record['target'],
                        'valid' => false,
                        'type' => 'CNAME',
                        'target' => $record['target'],
                        'details' => ['status' => 'CNAME found but no valid DKIM record at target']
                    ];
                    return true;
                }
            }
        }
        
        return false;
    }
    
    private function isDKIMRecord($record) {
        // More comprehensive DKIM record detection
        $record = strtolower($record);
        
        // Check for explicit DKIM version
        if (strpos($record, 'v=dkim1') !== false) {
            return true;
        }
        
        // Check for key type indicators
        if (strpos($record, 'k=rsa') !== false || strpos($record, 'k=ed25519') !== false) {
            return true;
        }
        
        // Check for public key presence (p= tag with substantial content)
        if (preg_match('/p=([a-zA-Z0-9+\/=]{100,})/', $record)) {
            return true;
        }
        
        // Check for hash algorithms (h= tag)
        if (strpos($record, 'h=sha256') !== false || strpos($record, 'h=sha1') !== false) {
            return true;
        }
        
        // Check for service type (s= tag)
        if (strpos($record, 's=email') !== false || strpos($record, 's=*') !== false) {
            return true;
        }
        
        // Look for Base64-encoded public key patterns
        if (preg_match('/[a-zA-Z0-9+\/]{200,}={0,2}/', $record)) {
            return true;
        }
        
        return false;
    }
    
    private function discoverSelectorPatterns($foundSelectors, &$dkim) {
        foreach ($foundSelectors as $selector) {
            // Try numbered variations
            if (preg_match('/^(.+?)(\d+)$/', $selector, $matches)) {
                $base = $matches[1];
                $num = intval($matches[2]);
                
                // Try next/previous numbers
                for ($i = 1; $i <= 10; $i++) {
                    $newSelector = $base . ($num + $i);
                    if (!in_array($newSelector, $dkim['selectors_checked'])) {
                        $this->checkSingleDKIMSelector($newSelector, $dkim);
                    }
                    
                    if ($num - $i > 0) {
                        $newSelector = $base . ($num - $i);
                        if (!in_array($newSelector, $dkim['selectors_checked'])) {
                            $this->checkSingleDKIMSelector($newSelector, $dkim);
                        }
                    }
                }
            }
            
            // Try lettered variations
            if (preg_match('/^(.+?)([a-z])$/', $selector, $matches)) {
                $base = $matches[1];
                $letter = $matches[2];
                
                for ($c = ord('a'); $c <= ord('z'); $c++) {
                    $newSelector = $base . chr($c);
                    if ($newSelector !== $selector && !in_array($newSelector, $dkim['selectors_checked'])) {
                        $this->checkSingleDKIMSelector($newSelector, $dkim);
                    }
                }
            }
        }
    }
    
    private function discoverDateBasedSelectors(&$dkim) {
        $currentYear = date('Y');
        $currentMonth = date('m');
        
        // Try year-based selectors
        for ($year = $currentYear - 2; $year <= $currentYear + 1; $year++) {
            $selectors = [
                $year, 'dkim' . $year, 'key' . $year, 'selector' . $year,
                's' . $year, 'k' . $year
            ];
            
            foreach ($selectors as $selector) {
                if (!in_array($selector, $dkim['selectors_checked'])) {
                    $this->checkSingleDKIMSelector($selector, $dkim);
                }
            }
        }
        
        // Try month-based selectors
        $months = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];
        foreach ($months as $month) {
            $selectors = [
                $currentYear . $month, 'dkim' . $currentYear . $month,
                $month . $currentYear, 'm' . $month
            ];
            
            foreach ($selectors as $selector) {
                if (!in_array($selector, $dkim['selectors_checked'])) {
                    $this->checkSingleDKIMSelector($selector, $dkim);
                }
            }
        }
    }
    
    private function discoverProviderPatterns(&$dkim) {
        // Check if we can identify the email provider from MX records
        $mxRecords = @dns_get_record($this->domain, DNS_MX);
        $providers = [];
        
        if ($mxRecords) {
            foreach ($mxRecords as $mx) {
                $target = strtolower($mx['target']);
                if (strpos($target, 'google') !== false || strpos($target, 'gmail') !== false) {
                    $providers[] = 'google';
                } elseif (strpos($target, 'outlook') !== false || strpos($target, 'office365') !== false) {
                    $providers[] = 'microsoft';
                } elseif (strpos($target, 'protonmail') !== false) {
                    $providers[] = 'protonmail';
                } elseif (strpos($target, 'mailgun') !== false) {
                    $providers[] = 'mailgun';
                } elseif (strpos($target, 'sendgrid') !== false) {
                    $providers[] = 'sendgrid';
                }
            }
        }
        
        // Try provider-specific patterns
        foreach ($providers as $provider) {
            switch ($provider) {
                case 'google':
                    $patterns = ['google', 'gmail', '20161025', '20120113', 'beta', 'alpha'];
                    break;
                case 'microsoft':
                    $patterns = ['selector1', 'selector2', 'selector3', 'o365', 'outlook'];
                    break;
                case 'protonmail':
                    $patterns = ['protonmail', 'protonmail2', 'protonmail3', 'pm'];
                    break;
                case 'mailgun':
                    $patterns = ['mx', 'mg', 'mailgun', 'pic', 'mta'];
                    break;
                case 'sendgrid':
                    $patterns = ['smtpapi', 'sg', 'm1', 'm2'];
                    break;
                default:
                    $patterns = [];
            }
            
            foreach ($patterns as $pattern) {
                if (!in_array($pattern, $dkim['selectors_checked'])) {
                    $this->checkSingleDKIMSelector($pattern, $dkim);
                }
            }
        }
    }
    
    private function attemptDNSEnumeration(&$dkim) {
        // Try to find _domainkey subdomains through various methods
        // This is limited but worth trying
        
        // Method 1: Try common subdomain prefixes with _domainkey
        $subdomains = ['mail', 'smtp', 'mx', 'email', 'webmail', 'imap', 'pop'];
        
        foreach ($subdomains as $sub) {
            $testDomain = '_domainkey.' . $sub . '.' . $this->domain;
            $records = @dns_get_record($testDomain, DNS_ANY);
            
            if ($records) {
                // Found something at subdomain level
                $commonSels = ['default', 'selector1', 'mx', 'key1'];
                foreach ($commonSels as $sel) {
                    $fullDomain = $sel . '.' . $testDomain;
                    if (!in_array($sel . '@' . $sub, $dkim['selectors_checked'])) {
                        $dkim['selectors_checked'][] = $sel . '@' . $sub;
                        // Check this subdomain selector
                        $txtRecords = @dns_get_record($fullDomain, DNS_TXT);
                        if ($txtRecords) {
                            foreach ($txtRecords as $record) {
                                if (isset($record['txt']) && $this->isDKIMRecord($record['txt'])) {
                                    $dkim['selectors_found'][$sel . '@' . $sub] = [
                                        'record' => $record['txt'],
                                        'valid' => true,
                                        'type' => 'TXT',
                                        'subdomain' => $sub,
                                        'details' => $this->parseDKIMRecord($record['txt'])
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    private function parseDKIMRecord($record) {
        $details = [];
        
        // Split by semicolon but handle quoted values
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $record);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (strpos($part, '=') !== false) {
                list($key, $value) = explode('=', $part, 2);
                $key = trim($key);
                $value = trim($value, ' "');
                
                switch ($key) {
                    case 'v':
                        $details['version'] = $value;
                        break;
                    case 'k':
                        $details['key_type'] = $value;
                        break;
                    case 'h':
                        $details['hash_algorithms'] = $value;
                        break;
                    case 'p':
                        if (empty($value)) {
                            $details['public_key'] = 'REVOKED (empty p= tag)';
                        } else {
                            $keyLength = strlen(base64_decode($value)) * 8;
                            $details['public_key'] = substr($value, 0, 50) . '... (' . $keyLength . ' bits)';
                        }
                        break;
                    case 's':
                        $details['service_type'] = $value;
                        break;
                    case 't':
                        $flags = [];
                        if (strpos($value, 'y') !== false) $flags[] = 'testing';
                        if (strpos($value, 's') !== false) $flags[] = 'strict';
                        $details['flags'] = empty($flags) ? $value : implode(', ', $flags);
                        break;
                    case 'n':
                        $details['notes'] = $value;
                        break;
                    case 'g':
                        $details['granularity'] = $value;
                        break;
                }
            }
        }
        
        return $details;
    }
    
    private function checkDMARC() {
        $dmarc = [
            'record' => '',
            'valid' => false,
            'policy' => '',
            'subdomain_policy' => '',
            'percentage' => 100,
            'alignment' => [],
            'reporting' => [],
            'issues' => [],
            'recommendations' => []
        ];
        
        $dmarcDomain = '_dmarc.' . $this->domain;
        $txtRecords = @dns_get_record($dmarcDomain, DNS_TXT);
        
        if (!$txtRecords) {
            $dmarc['issues'][] = 'No DMARC record found';
            $dmarc['recommendations'][] = 'Add a DMARC record at _dmarc.' . $this->domain;
            $dmarc['recommendations'][] = 'Start with a policy of "p=none" to monitor email authentication';
            return $dmarc;
        }
        
        $dmarcRecord = null;
        $dmarcCount = 0;
        
        foreach ($txtRecords as $record) {
            if (isset($record['txt']) && strpos($record['txt'], 'v=DMARC1') === 0) {
                $dmarcRecord = $record['txt'];
                $dmarcCount++;
            }
        }
        
        if ($dmarcCount > 1) {
            $dmarc['issues'][] = 'Multiple DMARC records found - only one is allowed';
        }
        
        if (!$dmarcRecord) {
            $dmarc['issues'][] = 'No valid DMARC record found (must start with v=DMARC1)';
            $dmarc['recommendations'][] = 'Add a DMARC record starting with "v=DMARC1"';
            return $dmarc;
        }
        
        $dmarc['record'] = $dmarcRecord;
        $dmarc['valid'] = true;
        
        // Parse DMARC record
        $tags = explode(';', $dmarcRecord);
        
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (strpos($tag, '=') !== false) {
                list($key, $value) = explode('=', $tag, 2);
                $key = trim($key);
                $value = trim($value);
                
                switch ($key) {
                    case 'p':
                        $dmarc['policy'] = $value;
                        break;
                    case 'sp':
                        $dmarc['subdomain_policy'] = $value;
                        break;
                    case 'pct':
                        $dmarc['percentage'] = intval($value);
                        break;
                    case 'aspf':
                        $dmarc['alignment']['spf'] = $value;
                        break;
                    case 'adkim':
                        $dmarc['alignment']['dkim'] = $value;
                        break;
                    case 'rua':
                        $dmarc['reporting']['aggregate'] = $value;
                        break;
                    case 'ruf':
                        $dmarc['reporting']['forensic'] = $value;
                        break;
                }
            }
        }
        
        // Validate policy
        if (!in_array($dmarc['policy'], ['none', 'quarantine', 'reject'])) {
            $dmarc['issues'][] = 'Invalid DMARC policy - must be none, quarantine, or reject';
        }
        
        // Check for recommendations
        if ($dmarc['policy'] === 'none') {
            $dmarc['recommendations'][] = 'Consider moving to a stricter policy (quarantine or reject) after monitoring';
        }
        
        if (empty($dmarc['reporting']['aggregate'])) {
            $dmarc['recommendations'][] = 'Add "rua" tag to receive aggregate reports';
        }
        
        if ($dmarc['percentage'] < 100) {
            $dmarc['recommendations'][] = 'Percentage is set to ' . $dmarc['percentage'] . '% - consider increasing to 100% when ready';
        }
        
        if (empty($dmarc['issues'])) {
            $dmarc['recommendations'][] = 'DMARC record configuration looks good!';
        }
        
        return $dmarc;
    }
    
    public function generateReport() {
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Authentication Report for ' . htmlspecialchars($this->domain) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .domain { color: #2c5aa0; font-size: 24px; font-weight: bold; }
        .section { margin-bottom: 30px; border: 1px solid #ddd; border-radius: 5px; overflow: hidden; }
        .section-header { background: #2c5aa0; color: white; padding: 15px; font-size: 18px; font-weight: bold; }
        .section-content { padding: 20px; }
        .status-good { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .status-warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .status-error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .record { background: #f8f9fa; padding: 10px; border-left: 4px solid #007bff; margin: 10px 0; font-family: monospace; word-break: break-all; }
        .mechanisms { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 10px 0; }
        .mechanism { background: #e9ecef; padding: 8px; border-radius: 4px; font-family: monospace; }
        ul { margin: 10px 0; padding-left: 20px; }
        li { margin: 5px 0; }
        .form-section { background: #e3f2fd; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .form-section input { padding: 10px; font-size: 16px; border: 1px solid #ddd; border-radius: 4px; width: 300px; margin-right: 10px; }
        .form-section input[type="checkbox"] { width: auto; margin: 0 5px 0 10px; transform: scale(1.2); }
        .form-section label { font-size: 14px; color: #555; }
        .form-section button { padding: 10px 20px; font-size: 16px; background: #2c5aa0; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .form-section button:hover { background: #1e3f73; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Email Authentication Checker</h1>';
        
        if (!empty($this->domain)) {
            $html .= '<div class="domain">Report for: ' . htmlspecialchars($this->domain) . '</div>';
        }
        
        $html .= '</div>
        
        <div class="form-section">
            <form method="GET">
                <input type="text" name="domain" placeholder="Enter domain (e.g., example.com)" value="' . htmlspecialchars($_GET['domain'] ?? '') . '">
                <label style="margin: 0 10px;">
                    <input type="checkbox" name="complete" value="1" ' . (($_GET['complete'] ?? '') == '1' ? 'checked' : '') . '> 
                    Comprehensive DKIM scan (slower, checks 400+ selectors)
                </label>
                <button type="submit">Check Domain</button>
            </form>
            <p style="margin-top: 10px; font-size: 14px; color: #666;">
                <strong>Quick scan</strong> checks the 10 most common DKIM selectors (~5 seconds)<br>
                <strong>Comprehensive scan</strong> checks 400+ selectors using multiple discovery methods (~30-60 seconds)
            </p>
        </div>';
        
        if (!empty($this->results)) {
            // SPF Section
            $html .= '<div class="section">
                <div class="section-header">SPF (Sender Policy Framework)</div>
                <div class="section-content">';
            
            if ($this->results['spf']['valid']) {
                $html .= '<div class="status-good">✓ SPF record found</div>';
                $html .= '<div class="record">' . htmlspecialchars($this->results['spf']['record']) . '</div>';
                
                if (!empty($this->results['spf']['mechanisms'])) {
                    $html .= '<h4>Mechanisms:</h4><div class="mechanisms">';
                    foreach ($this->results['spf']['mechanisms'] as $mechanism) {
                        $html .= '<div class="mechanism">' . htmlspecialchars($mechanism) . '</div>';
                    }
                    $html .= '</div>';
                }
            } else {
                $html .= '<div class="status-error">✗ No valid SPF record found</div>';
            }
            
            if (!empty($this->results['spf']['issues'])) {
                $html .= '<h4>Issues:</h4><ul>';
                foreach ($this->results['spf']['issues'] as $issue) {
                    $html .= '<li class="status-warning">' . htmlspecialchars($issue) . '</li>';
                }
                $html .= '</ul>';
            }
            
            if (!empty($this->results['spf']['recommendations'])) {
                $html .= '<h4>Recommendations:</h4><ul>';
                foreach ($this->results['spf']['recommendations'] as $rec) {
                    $html .= '<li>' . htmlspecialchars($rec) . '</li>';
                }
                $html .= '</ul>';
            }
            
            $html .= '</div></div>';
            
            // DKIM Section
            $scanMode = $this->results['dkim']['scan_mode'] === 'comprehensive' ? 'Comprehensive Discovery' : 'Quick Scan';
            $html .= '<div class="section">
                <div class="section-header">DKIM (DomainKeys Identified Mail) - ' . $scanMode . '</div>
                <div class="section-content">';
            
            // Show scan mode info
            if ($this->results['dkim']['scan_mode'] === 'comprehensive') {
                $html .= '<div class="status-good">🔍 Comprehensive scan: Checked ' . count($this->results['dkim']['selectors_checked']) . ' selectors using multiple discovery methods</div>';
            } else {
                $html .= '<div style="background: #e3f2fd; padding: 10px; border-radius: 4px; margin: 10px 0;">
                    ⚡ Quick scan: Checked top ' . count($this->results['dkim']['selectors_checked']) . ' common selectors. 
                    <a href="?' . http_build_query(array_merge($_GET, ['complete' => '1'])) . '" style="color: #2c5aa0; text-decoration: underline;">Run comprehensive scan</a> to check 400+ selectors.
                </div>';
            }
            
            // Show discovery methods used
            if (!empty($this->results['dkim']['discovery_methods'])) {
                $html .= '<h4>Discovery Methods Used:</h4>';
                $html .= '<ul>';
                foreach ($this->results['dkim']['discovery_methods'] as $method) {
                    $html .= '<li>' . htmlspecialchars($method) . '</li>';
                }
                $html .= '</ul>';
            }
            
            if (!empty($this->results['dkim']['selectors_found'])) {
                $validCount = count(array_filter($this->results['dkim']['selectors_found'], function($s) { return $s['valid']; }));
                $html .= '<div class="status-good">✓ Found ' . count($this->results['dkim']['selectors_found']) . ' DKIM selector(s), ' . $validCount . ' valid</div>';
                
                foreach ($this->results['dkim']['selectors_found'] as $selector => $data) {
                    $html .= '<h4>Selector: ' . htmlspecialchars($selector) . '</h4>';
                    
                    if (isset($data['subdomain'])) {
                        $html .= '<p><strong>Location:</strong> Subdomain ' . htmlspecialchars($data['subdomain']) . '</p>';
                    }
                    
                    if ($data['type'] === 'CNAME') {
                        $html .= '<p><strong>Type:</strong> CNAME pointing to ' . htmlspecialchars($data['target']) . '</p>';
                        if ($data['valid']) {
                            $html .= '<div class="status-good">✓ Valid DKIM record found at target</div>';
                        } else {
                            $html .= '<div class="status-warning">⚠ CNAME found but no valid DKIM record at target</div>';
                        }
                    } else {
                        $html .= '<p><strong>Type:</strong> Direct TXT record</p>';
                    }
                    
                    $html .= '<div class="record">' . htmlspecialchars($data['record']) . '</div>';
                    
                    if (!empty($data['details'])) {
                        $html .= '<ul>';
                        foreach ($data['details'] as $key => $value) {
                            $html .= '<li><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</li>';
                        }
                        $html .= '</ul>';
                    }
                }
            } else {
                $html .= '<div class="status-error">✗ No DKIM records found after comprehensive scan</div>';
                $html .= '<p>The scanner checked ' . count($this->results['dkim']['selectors_checked']) . ' potential selectors using multiple discovery methods.</p>';
            }
            
            if (!empty($this->results['dkim']['issues'])) {
                $html .= '<h4>Issues:</h4><ul>';
                foreach ($this->results['dkim']['issues'] as $issue) {
                    $html .= '<li class="status-warning">' . htmlspecialchars($issue) . '</li>';
                }
                $html .= '</ul>';
            }
            
            if (!empty($this->results['dkim']['recommendations'])) {
                $html .= '<h4>Recommendations:</h4><ul>';
                foreach ($this->results['dkim']['recommendations'] as $rec) {
                    $html .= '<li>' . htmlspecialchars($rec) . '</li>';
                }
                $html .= '</ul>';
            }
            
            $html .= '</div></div>';
            
            // DMARC Section
            $html .= '<div class="section">
                <div class="section-header">DMARC (Domain-based Message Authentication, Reporting & Conformance)</div>
                <div class="section-content">';
            
            if ($this->results['dmarc']['valid']) {
                $html .= '<div class="status-good">✓ DMARC record found</div>';
                $html .= '<div class="record">' . htmlspecialchars($this->results['dmarc']['record']) . '</div>';
                
                $html .= '<h4>Policy Details:</h4><ul>';
                $html .= '<li><strong>Policy:</strong> ' . htmlspecialchars($this->results['dmarc']['policy']) . '</li>';
                if (!empty($this->results['dmarc']['subdomain_policy'])) {
                    $html .= '<li><strong>Subdomain Policy:</strong> ' . htmlspecialchars($this->results['dmarc']['subdomain_policy']) . '</li>';
                }
                $html .= '<li><strong>Percentage:</strong> ' . $this->results['dmarc']['percentage'] . '%</li>';
                
                if (!empty($this->results['dmarc']['alignment'])) {
                    foreach ($this->results['dmarc']['alignment'] as $type => $mode) {
                        $html .= '<li><strong>' . ucfirst($type) . ' Alignment:</strong> ' . htmlspecialchars($mode) . '</li>';
                    }
                }
                
                if (!empty($this->results['dmarc']['reporting'])) {
                    foreach ($this->results['dmarc']['reporting'] as $type => $email) {
                        $html .= '<li><strong>' . ucfirst($type) . ' Reports:</strong> ' . htmlspecialchars($email) . '</li>';
                    }
                }
                $html .= '</ul>';
            } else {
                $html .= '<div class="status-error">✗ No valid DMARC record found</div>';
            }
            
            if (!empty($this->results['dmarc']['issues'])) {
                $html .= '<h4>Issues:</h4><ul>';
                foreach ($this->results['dmarc']['issues'] as $issue) {
                    $html .= '<li class="status-warning">' . htmlspecialchars($issue) . '</li>';
                }
                $html .= '</ul>';
            }
            
            if (!empty($this->results['dmarc']['recommendations'])) {
                $html .= '<h4>Recommendations:</h4><ul>';
                foreach ($this->results['dmarc']['recommendations'] as $rec) {
                    $html .= '<li>' . htmlspecialchars($rec) . '</li>';
                }
                $html .= '</ul>';
            }
            
            $html .= '</div></div>';
            
            // Summary Section
            $html .= '<div class="section">
                <div class="section-header">Summary</div>
                <div class="section-content">';
            
            $spfStatus = $this->results['spf']['valid'] ? '✓' : '✗';
            $validDkimCount = 0;
            foreach ($this->results['dkim']['selectors_found'] as $data) {
                if ($data['valid']) $validDkimCount++;
            }
            $dkimStatus = $validDkimCount > 0 ? "✓ ($validDkimCount selectors)" : '✗';
            $dmarcStatus = $this->results['dmarc']['valid'] ? '✓' : '✗';
            
            $html .= '<ul>';
            $html .= '<li><strong>SPF:</strong> ' . $spfStatus . '</li>';
            $html .= '<li><strong>DKIM:</strong> ' . $dkimStatus . '</li>';
            $html .= '<li><strong>DMARC:</strong> ' . $dmarcStatus . '</li>';
            $html .= '</ul>';
            
            $html .= '<h4>Overall Recommendations:</h4>';
            $html .= '<ul>';
            $html .= '<li>Ensure all three protocols (SPF, DKIM, DMARC) are properly configured</li>';
            $html .= '<li>Start with DMARC policy "p=none" to monitor, then move to "p=quarantine" or "p=reject"</li>';
            $html .= '<li>Set up DMARC reporting to monitor email authentication results</li>';
            $html .= '<li>Regularly review and update your email authentication configuration</li>';
            $html .= '</ul>';
            
            $html .= '</div></div>';
        }
        
        $html .= '</div></body></html>';
        
        return $html;
    }
}

// Main execution
$domain = $_GET['domain'] ?? '';

if (!empty($domain)) {
    // Basic domain validation
    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        echo '<div style="color: red; padding: 20px;">Invalid domain format</div>';
        exit;
    }
    
    $checker = new EmailAuthChecker($domain);
    $checker->checkAll();
    echo $checker->generateReport();
} else {
    // Show form only
    $checker = new EmailAuthChecker('');
    echo $checker->generateReport();
}
?>