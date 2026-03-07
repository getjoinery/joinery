<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('/includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new AdminPage();
$settings = Globalvars::get_instance();

// Process form submission
$domain = $_GET['domain'] ?? '';
$is_comprehensive = isset($_GET['complete']) && ($_GET['complete'] == '1' || $_GET['complete'] === '');

// Set time limit based on scan mode
if (!empty($domain)) {
    set_time_limit($is_comprehensive ? 120 : 30);
}

// Admin header
$page->admin_header([
    'title' => 'Email Authentication Checker',
    'menu-id' => 'email-tools',
    'readable_title' => 'Email Authentication Checker'
]);

?>

<div class="row">
    <div class="col-12">
        
        <!-- Search Form -->
        <h5 class="mb-3">Check Domain Authentication</h5>
        
        <?php
        $formwriter = $page->getFormWriter('domain_check_form', ['action' => '/utils/email_setup_check', 'method' => 'GET']);
        $formwriter->begin_form();

        echo '<div class="row g-3 mb-4">';
        echo '<div class="col-md-6">';
        echo $formwriter->textinput('domain', 'Domain to Check', ['value' => $domain, 'placeholder' => 'example.com', 'maxlength' => 255]);
        echo '</div>';

        echo '<div class="col-md-6">';
        echo $formwriter->checkboxinput('complete', 'Comprehensive DKIM scan (slower, checks 400+ selectors)', ['value' => '1', 'checked' => $is_comprehensive]);
        echo '</div>';

        echo '<div class="col-12">';
        echo $formwriter->submitbutton('btn_submit', '<i class="fas fa-search"></i> Check Domain', ['class' => 'btn btn-primary']);
        echo '</div>';
        echo '</div>';

        echo $formwriter->end_form();
        ?>

        <script>
        // Form submission with loading state
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('domain_check_form');
            const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
            const checkbox = form ? form.querySelector('input[name="complete"]') : null;
            
            if (form && submitBtn) {
                form.addEventListener('submit', function(e) {
                    // Determine scan type
                    const isComprehensive = checkbox && checkbox.checked;
                    const scanType = isComprehensive ? 'Comprehensive' : 'Quick';
                    const estimatedTime = isComprehensive ? '30-60 seconds' : '5-10 seconds';
                    
                    // Disable the submit button
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
                    
                    // Show loading status
                    const loadingHtml = `
                        <div id="loading-status" class="alert alert-primary mt-3">
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm me-3" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <div>
                                    <strong>Running ${scanType} Domain Authentication Check...</strong><br>
                                    <small class="text-muted">Estimated time: ${estimatedTime}. Please wait while we analyze DNS records.</small>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Insert loading status after the form
                    form.insertAdjacentHTML('afterend', loadingHtml);
                    
                    // Scroll to loading indicator
                    document.getElementById('loading-status').scrollIntoView({ behavior: 'smooth' });
                });
            }
        });
        </script>

        <?php if (!empty($domain)): ?>
            <?php
            // Basic domain validation
            if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                echo '<div class="alert alert-danger">Invalid domain format</div>';
            } else {
                // Perform the checks
                $checker = new EmailAuthChecker($domain, $is_comprehensive);
                $results = $checker->checkAll();
                
                // Display results
                ?>
                
                <!-- Results Header -->
                <div class="alert alert-info">
                    <h5 class="alert-heading mb-2">Authentication Report for: <?php echo htmlspecialchars($domain); ?> 
                        <span class="badge <?php echo $is_comprehensive ? 'bg-primary' : 'bg-secondary'; ?>">
                            <?php echo $is_comprehensive ? 'Comprehensive Scan' : 'Quick Scan'; ?>
                        </span>
                    </h5>
                    <p class="mb-0">Generated at: <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>
                
                <!-- SPF Results -->
                <div class="mt-5 mb-4">
                    <h4 class="mb-3"><i class="fas fa-shield-alt"></i> SPF (Sender Policy Framework)</h4>
                        <?php if ($results['spf']['valid']): ?>
                            <div class="alert alert-success">
                                <strong>SPF Record Found:</strong>
                                <pre class="mb-0 mt-2"><?php echo htmlspecialchars($results['spf']['record']); ?></pre>
                            </div>
                            
                            <?php if (!empty($results['spf']['mechanisms'])): ?>
                                <h6>Mechanisms:</h6>
                                <div class="row g-2 mb-3">
                                    <?php foreach ($results['spf']['mechanisms'] as $mechanism): ?>
                                        <div class="col-auto">
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($mechanism); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($results['spf']['services'])): ?>
                                <h6>Detected Email Services:</h6>
                                <ul class="list-unstyled">
                                    <?php foreach ($results['spf']['services'] as $service): ?>
                                        <li><i class="fas fa-server text-primary"></i> <?php echo htmlspecialchars($service); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                No SPF record found for this domain.
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['spf']['issues'])): ?>
                            <div class="alert alert-warning">
                                <h6 class="alert-heading">Issues Found:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($results['spf']['issues'] as $issue): ?>
                                        <li><?php echo htmlspecialchars($issue); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['spf']['recommendations'])): ?>
                            <div class="alert alert-info">
                                <h6 class="alert-heading">Recommendations:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($results['spf']['recommendations'] as $rec): ?>
                                        <li><?php echo htmlspecialchars($rec); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <!-- SPF Status -->
                        <div class="text-end mt-3">
                            <?php if ($results['spf']['valid']): ?>
                                <span class="badge bg-success fs-6 px-3 py-2"><i class="fas fa-check-circle"></i> SPF VALID</span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-6 px-3 py-2"><i class="fas fa-times-circle"></i> SPF NOT FOUND</span>
                            <?php endif; ?>
                        </div>
                </div>
                
                <!-- DKIM Results -->
                <div class="mt-5 mb-4">
                    <h4 class="mb-3"><i class="fas fa-key"></i> DKIM (DomainKeys Identified Mail)</h4>
                        <!-- Scan Info -->
                        <div class="alert <?php echo $is_comprehensive ? 'alert-success' : 'alert-info'; ?>">
                            <?php if ($is_comprehensive): ?>
                                <i class="fas fa-search-plus"></i> <strong>Comprehensive Scan:</strong> 
                                Checked <?php echo count($results['dkim']['selectors_checked']); ?> selectors using multiple discovery methods
                            <?php else: ?>
                                <i class="fas fa-bolt"></i> <strong>Quick Scan:</strong> 
                                Checked top <?php echo count($results['dkim']['selectors_checked']); ?> common selectors. 
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['complete' => '1'])); ?>" class="alert-link">
                                    Run comprehensive scan
                                </a> to check 400+ selectors.
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($results['dkim']['discovery_methods'])): ?>
                            <h6>Discovery Methods Used:</h6>
                            <ul>
                                <?php foreach ($results['dkim']['discovery_methods'] as $method): ?>
                                    <li><?php echo htmlspecialchars($method); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['dkim']['selectors_found'])): ?>
                            <h6>DKIM Selectors Found:</h6>
                            <?php foreach ($results['dkim']['selectors_found'] as $selector => $data): ?>
                                <div class="card mb-3">
                                    <div class="card-header <?php echo $data['valid'] ? 'bg-light' : 'bg-warning'; ?>">
                                        <strong>Selector: <?php echo htmlspecialchars($selector); ?></strong>
                                        <?php if ($data['valid']): ?>
                                            <span class="badge bg-success float-end">Valid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning float-end">Invalid</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <?php if (isset($data['subdomain'])): ?>
                                            <p><strong>Location:</strong> Subdomain <?php echo htmlspecialchars($data['subdomain']); ?></p>
                                        <?php endif; ?>
                                        
                                        <?php if ($data['type'] === 'CNAME'): ?>
                                            <p><strong>Type:</strong> CNAME → <?php echo htmlspecialchars($data['target']); ?></p>
                                        <?php else: ?>
                                            <p><strong>Type:</strong> Direct TXT record</p>
                                        <?php endif; ?>
                                        
                                        <pre class="bg-light p-2"><?php echo htmlspecialchars($data['record']); ?></pre>
                                        
                                        <?php if (!empty($data['details'])): ?>
                                            <table class="table table-sm">
                                                <?php foreach ($data['details'] as $key => $value): ?>
                                                    <tr>
                                                        <th style="width: 150px;"><?php echo htmlspecialchars($key); ?>:</th>
                                                        <td><?php echo htmlspecialchars($value); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                No DKIM records found after scanning <?php echo count($results['dkim']['selectors_checked']); ?> potential selectors.
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['dkim']['issues'])): ?>
                            <div class="alert alert-warning">
                                <h6 class="alert-heading">Issues Found:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($results['dkim']['issues'] as $issue): ?>
                                        <li><?php echo htmlspecialchars($issue); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['dkim']['recommendations'])): ?>
                            <div class="alert alert-info">
                                <h6 class="alert-heading">Recommendations:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($results['dkim']['recommendations'] as $rec): ?>
                                        <li><?php echo htmlspecialchars($rec); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <!-- DKIM Status -->
                        <div class="text-end mt-3">
                            <?php 
                            $validDkimCount = count(array_filter($results['dkim']['selectors_found'], function($s) { return $s['valid']; }));
                            if ($validDkimCount > 0): ?>
                                <span class="badge bg-success fs-6 px-3 py-2"><i class="fas fa-check-circle"></i> DKIM VALID (<?php echo $validDkimCount; ?> selectors)</span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-6 px-3 py-2"><i class="fas fa-times-circle"></i> DKIM NOT FOUND</span>
                            <?php endif; ?>
                        </div>
                </div>
                
                <!-- DMARC Results -->
                <div class="mt-5 mb-4">
                    <h4 class="mb-3"><i class="fas fa-envelope-open-text"></i> DMARC (Domain-based Message Authentication)</h4>
                        <?php if ($results['dmarc']['valid']): ?>
                            <div class="alert alert-success">
                                <strong>DMARC Record Found:</strong>
                                <pre class="mb-0 mt-2"><?php echo htmlspecialchars($results['dmarc']['record']); ?></pre>
                            </div>
                            
                            <h6>Policy Details:</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 200px;">Policy:</th>
                                    <td>
                                        <?php 
                                        $policyBadge = 'secondary';
                                        if ($results['dmarc']['policy'] === 'reject') $policyBadge = 'danger';
                                        elseif ($results['dmarc']['policy'] === 'quarantine') $policyBadge = 'warning';
                                        elseif ($results['dmarc']['policy'] === 'none') $policyBadge = 'info';
                                        ?>
                                        <span class="badge bg-<?php echo $policyBadge; ?>">
                                            <?php echo htmlspecialchars($results['dmarc']['policy']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php if (!empty($results['dmarc']['subdomain_policy'])): ?>
                                <tr>
                                    <th>Subdomain Policy:</th>
                                    <td><?php echo htmlspecialchars($results['dmarc']['subdomain_policy']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>Percentage:</th>
                                    <td><?php echo $results['dmarc']['percentage']; ?>%</td>
                                </tr>
                                <?php if (!empty($results['dmarc']['alignment'])): ?>
                                    <?php foreach ($results['dmarc']['alignment'] as $type => $mode): ?>
                                    <tr>
                                        <th><?php echo ucfirst($type); ?> Alignment:</th>
                                        <td><?php echo htmlspecialchars($mode); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if (!empty($results['dmarc']['reporting'])): ?>
                                    <?php foreach ($results['dmarc']['reporting'] as $type => $email): ?>
                                    <tr>
                                        <th><?php echo ucfirst($type); ?> Reports:</th>
                                        <td><?php echo htmlspecialchars($email); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                No DMARC record found for this domain.
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['dmarc']['issues'])): ?>
                            <div class="alert alert-warning">
                                <h6 class="alert-heading">Issues Found:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($results['dmarc']['issues'] as $issue): ?>
                                        <li><?php echo htmlspecialchars($issue); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($results['dmarc']['recommendations'])): ?>
                            <div class="alert alert-info">
                                <h6 class="alert-heading">Recommendations:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($results['dmarc']['recommendations'] as $rec): ?>
                                        <li><?php echo htmlspecialchars($rec); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <!-- DMARC Status -->
                        <div class="text-end mt-3">
                            <?php if ($results['dmarc']['valid']): ?>
                                <span class="badge bg-success fs-6 px-3 py-2"><i class="fas fa-check-circle"></i> DMARC VALID</span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-6 px-3 py-2"><i class="fas fa-times-circle"></i> DMARC NOT FOUND</span>
                            <?php endif; ?>
                        </div>
                </div>
                
                <!-- Summary -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="p-3">
                                    <h2 class="<?php echo $results['spf']['valid'] ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $results['spf']['valid'] ? '✓' : '✗'; ?>
                                    </h2>
                                    <h5>SPF</h5>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3">
                                    <h2 class="<?php echo $validDkimCount > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $validDkimCount > 0 ? "✓ ($validDkimCount)" : '✗'; ?>
                                    </h2>
                                    <h5>DKIM</h5>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3">
                                    <h2 class="<?php echo $results['dmarc']['valid'] ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $results['dmarc']['valid'] ? '✓' : '✗'; ?>
                                    </h2>
                                    <h5>DMARC</h5>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6>Overall Recommendations:</h6>
                        <ul>
                            <li>Ensure all three protocols (SPF, DKIM, DMARC) are properly configured</li>
                            <li>Start with DMARC policy "p=none" to monitor, then move to "p=quarantine" or "p=reject"</li>
                            <li>Set up DMARC reporting to monitor email authentication results</li>
                            <li>Regularly review and update your email authentication configuration</li>
                        </ul>
                    </div>
                </div>
                
                <?php
            }
            ?>
        <?php endif; ?>
        
    </div>
</div>

<?php
// Admin footer
$page->admin_footer();

// EmailAuthChecker class definition
class EmailAuthChecker {
    private $domain;
    private $results = [];
    private $is_comprehensive;
    
    public function __construct($domain, $is_comprehensive = false) {
        $this->domain = strtolower(trim($domain));
        $this->is_comprehensive = $is_comprehensive;
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
            'scan_mode' => $this->is_comprehensive ? 'comprehensive' : 'quick',
            'issues' => [],
            'recommendations' => []
        ];
        
        // DKIM selector discovery
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
        
        // Method 1: Common selectors wordlist
        $dkim['discovery_methods'][] = $this->is_comprehensive ? 'Comprehensive selectors wordlist' : 'Top 10 common selectors';
        $commonSelectors = $this->is_comprehensive ? $this->getComprehensiveSelectorList() : $this->getTopCommonSelectors();
        
        foreach ($commonSelectors as $selector) {
            if ($this->checkSingleDKIMSelector($selector, $dkim)) {
                $foundSelectors[] = $selector;
            }
        }
        
        // Only run additional methods in comprehensive mode
        if ($this->is_comprehensive) {
            // Method 2: Pattern-based discovery from found selectors
            if (!empty($foundSelectors)) {
                $dkim['discovery_methods'][] = 'Pattern-based discovery';
                $this->discoverSelectorPatterns($foundSelectors, $dkim);
            }
            
            // Method 3: Date-based selectors
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
}
?>