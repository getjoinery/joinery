# Stripe API Key Configuration and Validation Fix

## Executive Summary
The current implementation has confusing variable names and incorrect labels that don't match the database field purposes. This creates significant confusion and potential security risks. This spec provides a complete fix to standardize Stripe key usage across the entire codebase.

## Database Field Definitions (PERMANENT - These are the source of truth)
- `stripe_api_key` = Stores the **PUBLISHABLE** key (pk_live_ or pk_test_) for client-side use
- `stripe_api_pkey` = Stores the **PRIVATE/SECRET** key (sk_live_ or sk_test_) for server-side use

## Current Problems
1. Admin labels show opposite examples (sk_ for api_key, pk_ for api_pkey)
2. Variable names in code are backwards (`$this->api_secret_key` holds publishable key)
3. No validation to prevent putting wrong key types in wrong fields
4. Method `get_stripe_private_key()` returns the publishable key
5. Webhook and other files use wrong keys for their operations

## File-by-File Implementation Guide

### File: `/adm/admin_settings.php`

#### **Fix Form Labels (Lines 834-835, 939-940)**
```php
// BEFORE (WRONG labels - backwards examples):
echo $formwriter->textinput("Stripe API Key (Example: sk_live_xxxx)", 'stripe_api_key', '', 20, $settings->get_setting('stripe_api_key'), "" , 255, "");
echo $formwriter->textinput("Stripe API Private Key (Example: pk_live_xxxx)", 'stripe_api_pkey', '', 20, $settings->get_setting('stripe_api_pkey'), "" , 255, "");

echo $formwriter->textinput("Test Stripe API Key (Example: sk_test_xxxx)", 'stripe_api_key_test', '', 20, $settings->get_setting('stripe_api_key_test'), "" , 255, "");
echo $formwriter->textinput("Test Stripe Private Key (Example: pk_test_xxxx)", 'stripe_api_pkey_test', '', 20, $settings->get_setting('stripe_api_pkey_test'), "" , 255, "");

// AFTER (CORRECT labels matching database field purpose):
echo $formwriter->textinput("Stripe Publishable Key (Example: pk_live_xxxx)", 'stripe_api_key', '', 20, $settings->get_setting('stripe_api_key'), "" , 255, "");
echo $formwriter->textinput("Stripe Secret/Private Key (Example: sk_live_xxxx)", 'stripe_api_pkey', '', 20, $settings->get_setting('stripe_api_pkey'), "" , 255, "");

echo $formwriter->textinput("Test Stripe Publishable Key (Example: pk_test_xxxx)", 'stripe_api_key_test', '', 20, $settings->get_setting('stripe_api_key_test'), "" , 255, "");
echo $formwriter->textinput("Test Stripe Secret/Private Key (Example: sk_test_xxxx)", 'stripe_api_pkey_test', '', 20, $settings->get_setting('stripe_api_pkey_test'), "" , 255, "");
```

#### **Add jQuery Validation Rules**
```php
// ADD JavaScript validation using existing jQuery validation approach:
echo $formwriter->set_validate($validation_rules);

// Where $validation_rules includes:
$validation_rules['stripe_api_key']['pattern']['value'] = '^pk_(live|test)_[a-zA-Z0-9]{24,}$';
$validation_rules['stripe_api_key']['pattern']['message'] = 'Publishable key must start with pk_live_ or pk_test_';

$validation_rules['stripe_api_pkey']['pattern']['value'] = '^sk_(live|test)_[a-zA-Z0-9]{24,}$';
$validation_rules['stripe_api_pkey']['pattern']['message'] = 'Secret key must start with sk_live_ or sk_test_';

$validation_rules['stripe_api_key_test']['pattern']['value'] = '^pk_test_[a-zA-Z0-9]{24,}$';
$validation_rules['stripe_api_key_test']['pattern']['message'] = 'Test publishable key must start with pk_test_';

$validation_rules['stripe_api_pkey_test']['pattern']['value'] = '^sk_test_[a-zA-Z0-9]{24,}$';
$validation_rules['stripe_api_pkey_test']['pattern']['message'] = 'Test secret key must start with sk_test_';
```

#### **Add Client-Side Key Swap Detection**
```javascript
// ADD custom JavaScript validation to detect swapped keys:
<script>
$(document).ready(function() {
    // Custom validation for key swapping detection
    $('#stripe_api_key, #stripe_api_key_test').on('blur', function() {
        var value = $(this).val().trim();
        if (value && value.startsWith('sk_')) {
            alert('ERROR: You entered a SECRET key in the publishable key field!\n\nPublishable keys start with pk_\nSecret keys start with sk_\n\nPlease check that your keys are not swapped.');
            $(this).focus();
        }
    });
    
    $('#stripe_api_pkey, #stripe_api_pkey_test').on('blur', function() {
        var value = $(this).val().trim();
        if (value && value.startsWith('pk_')) {
            alert('ERROR: You entered a PUBLISHABLE key in the secret key field!\n\nSecret keys start with sk_\nPublishable keys start with pk_\n\nPlease check that your keys are not swapped.');
            $(this).focus();
        }
    });
});
</script>
```

#### **Fix API Validation Usage - Use StripeHelper Instead of Direct Client Creation**
```php
// BEFORE (WRONG - creating StripeClient directly):
PathHelper::requireOnce('/includes/StripeHelper.php');
$stripe_helper = new StripeHelper();

if ($stripe_helper->is_initialized()) {
    // Use account retrieve as minimal API call
    $stripe_client = new \Stripe\StripeClient([
        'api_key' => $stripe_api_key,  // This uses publishable key - WRONG!
        'stripe_version' => '2022-11-15'
    ]);
    
    $account = $stripe_client->accounts->retrieve();
    // ... rest of validation code
}

// AFTER (CORRECT - using StripeHelper's client):
PathHelper::requireOnce('/includes/StripeHelper.php');
$stripe_helper = new StripeHelper();

if ($stripe_helper->is_initialized()) {
    try {
        // Use StripeHelper's validated client - no direct initialization needed
        $account = $stripe_helper->get_stripe_client()->accounts->retrieve();
        echo '<p style="color: green;"><strong>✓ Live API Connection:</strong> Successfully connected to Stripe</p>';
        echo '<p><strong>Account ID:</strong> ' . htmlspecialchars($account->id) . '</p>';
        echo '<p><strong>Account Type:</strong> ' . htmlspecialchars($account->type) . '</p>';
        echo '<p><strong>Country:</strong> ' . htmlspecialchars($account->country) . '</p>';
    } catch (Exception $e) {
        echo '<p style="color: red;"><strong>✗ Live API Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
} else {
    echo '<p style="color: orange;"><strong>⚠ Configuration:</strong> Stripe keys not configured</p>';
}
```

### File: `/includes/StripeHelper.php`

#### **Fix Constructor - Use Secret Key for StripeClient (Line 46)**
```php
// BEFORE (WRONG - using publishable key for server-side):
$this->stripe = new \Stripe\StripeClient([
    'api_key' => $this->api_key,  // This is publishable key - WRONG!
    'stripe_version' => '2022-11-15'
]);

// AFTER (CORRECT - using validated secret key):
$this->stripe = new \Stripe\StripeClient([
    'api_key' => $this->get_stripe_private_key(),  // Uses validated secret key
    'stripe_version' => '2022-11-15'
]);
```

#### **Add Public Client Getter Method**
```php
/**
 * Get the validated Stripe client for direct API calls
 * @return \Stripe\StripeClient Validated Stripe client using secret key
 * @throws StripeHelperException if not initialized
 */
public function get_stripe_client() {
    if (!$this->stripe) {
        throw new StripeHelperException("StripeHelper not initialized - check API keys configuration");
    }
    return $this->stripe;
}
```

#### **Add Private Getter Methods with Validation**
```php
/**
 * PRIVATE: Get validated publishable key for client-side use
 * @return string Validated publishable key (pk_)
 * @throws StripeHelperException if key is invalid or wrong type
 */
private function get_stripe_public_key() {
    if (empty($this->api_key)) {
        throw new StripeHelperException("Stripe publishable key is not configured");
    }
    
    $prefix = $this->test_mode ? 'test' : 'live';
    
    // Validate it's a publishable key
    if (strpos($this->api_key, "pk_{$prefix}_") !== 0) {
        throw new StripeHelperException(
            "Invalid Stripe publishable key. Expected key starting with pk_{$prefix}_ but got: " . 
            substr($this->api_key, 0, 10) . "..."
        );
    }
    
    // Extra safety: ensure it's not a secret key
    if (strpos($this->api_key, 'sk_') === 0) {
        throw new StripeHelperException(
            "CRITICAL: Secret key detected in publishable key field! Keys are swapped. " .
            "The stripe_api_key field contains an sk_ key but should contain a pk_ key."
        );
    }
    
    return $this->api_key;
}

/**
 * PRIVATE: Get validated secret key for server-side use
 * @return string Validated secret key (sk_)
 * @throws StripeHelperException if key is invalid or wrong type
 */
private function get_stripe_private_key() {
    if (empty($this->api_secret_key)) {
        throw new StripeHelperException("Stripe secret key is not configured");
    }
    
    $prefix = $this->test_mode ? 'test' : 'live';
    
    // Validate it's a secret key
    if (strpos($this->api_secret_key, "sk_{$prefix}_") !== 0) {
        throw new StripeHelperException(
            "Invalid Stripe secret key. Expected key starting with sk_{$prefix}_ but got: " . 
            substr($this->api_secret_key, 0, 10) . "..."
        );
    }
    
    // Extra safety: ensure it's not a publishable key
    if (strpos($this->api_secret_key, 'pk_') === 0) {
        throw new StripeHelperException(
            "CRITICAL: Publishable key detected in secret key field! Keys are swapped. " .
            "The stripe_api_pkey field contains a pk_ key but should contain an sk_ key."
        );
    }
    
    return $this->api_secret_key;
}

/**
 * PRIVATE: Get validated webhook endpoint secret
 * @return string Validated webhook endpoint secret (whsec_)
 * @throws StripeHelperException if secret is invalid or not configured
 */
private function get_webhook_endpoint_secret() {
    $settings = Globalvars::get_instance();
    $endpoint_secret = $settings->get_setting('stripe_endpoint_secret');
    
    if (empty($endpoint_secret)) {
        throw new StripeHelperException("Stripe webhook endpoint secret is not configured");
    }
    
    // Validate endpoint secret format (should start with whsec_)
    if (strpos($endpoint_secret, 'whsec_') !== 0) {
        throw new StripeHelperException(
            "Invalid Stripe webhook endpoint secret format. Expected key starting with whsec_ but got: " .
            substr($endpoint_secret, 0, 10) . "..."
        );
    }
    
    return $endpoint_secret;
}
```

#### **Remove Redundant Key Loading in output_stripe_regular_form() (Lines 94-101)**
```php
// BEFORE (redundant key loading):
if($_SESSION['test_mode'] || $settings->get_setting('debug')){
    $api_key = $settings->get_setting('stripe_api_key_test');
    $api_secret_key = $settings->get_setting('stripe_api_pkey_test');
}
else{
    $api_key = $settings->get_setting('stripe_api_key');
    $api_secret_key = $settings->get_setting('stripe_api_pkey');		
}

// AFTER (remove entirely - use getter methods instead):
// Delete lines 94-101 completely
```

#### **Fix Client-Side JavaScript Key Usage (Line 108)**
```php
// BEFORE (WRONG - using $api_secret_key which confusingly holds publishable key):
$output .= '<script language="javascript"> var stripe = Stripe(\''.$api_secret_key.'\');';

// AFTER (CORRECT - using validated publishable key getter):
$output .= '<script language="javascript"> var stripe = Stripe(\''.$this->get_stripe_public_key().'\');';
```

#### **Fix Stripe Checkout JavaScript Key Usage (Line 194)**
```php
// BEFORE (WRONG - using private key method for client-side):
var stripe = Stripe(\''. $this->get_stripe_private_key().'\');

// AFTER (CORRECT - using validated publishable key):
var stripe = Stripe(\''. $this->get_stripe_public_key() .'\');
```

#### **Add Process Webhook Method**
```php
/**
 * Process webhook - handles ALL Stripe setup and validation internally
 * @return object Stripe event object
 * @throws StripeHelperException for configuration errors
 * @throws \UnexpectedValueException for invalid payload
 * @throws \Stripe\Exception\SignatureVerificationException for invalid signature
 */
public function process_webhook() {
    // Get payload and signature from HTTP request
    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    
    if (empty($payload)) {
        throw new \UnexpectedValueException("Empty webhook payload");
    }
    
    if (empty($sig_header)) {
        throw new \Stripe\Exception\SignatureVerificationException("Missing Stripe signature header");
    }
    
    // Get validated endpoint secret and process webhook
    $endpoint_secret = $this->get_webhook_endpoint_secret();
    return $this->stripe->webhooks->constructEvent($payload, $sig_header, $endpoint_secret);
}
```

### File: `/ajax/stripe_webhook.php`

#### **Complete Refactor for Stripe Encapsulation**
```php
// BEFORE (manual Stripe setup with wrong keys):
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
$settings = Globalvars::get_instance();
$composer_dir = $settings->get_setting('composerAutoLoad');	
require_once $composer_dir.'autoload.php';
PathHelper::requireOnce('data/events_class.php');
PathHelper::requireOnce('data/orders_class.php');

$settings = Globalvars::get_instance();
\Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));  // WRONG KEY!

$endpoint_secret = $settings->get_setting('stripe_endpoint_secret');
if(!$endpoint_secret){
	throw new SystemDisplayablePermanentError("Stripe endpoint secret is not present.");
	exit();			
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
  $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} 
catch(\UnexpectedValueException $e) {
  http_response_code(400);
  echo 'Invalid payload';
  exit();
} 
catch(\Stripe\Error\SignatureVerification $e) {
  http_response_code(400);
  echo 'Invalid signature';
  exit();
}

// Handle the checkout.session.completed event
if ($event->type == 'checkout.session.completed') {
	$sessionobject = $event->data->object;
	$order = new Order(NULL);
	// ... rest of webhook processing
}

// AFTER (complete Stripe encapsulation):
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/StripeHelper.php');
PathHelper::requireOnce('data/events_class.php');
PathHelper::requireOnce('data/orders_class.php');

try {
    // StripeHelper handles ALL Stripe setup internally
    $stripe_helper = new StripeHelper();
    $event = $stripe_helper->process_webhook();
    
} catch(StripeHelperException $e) {
    // Stripe configuration errors
    error_log("Stripe webhook configuration error: " . $e->getMessage());
    http_response_code(500);
    echo 'Stripe configuration error';
    exit();
} catch(\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    echo 'Invalid payload';
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    echo 'Invalid signature';
    exit();
} catch(\Exception $e) {
    // Any other unexpected errors
    error_log("Stripe webhook unexpected error: " . $e->getMessage());
    http_response_code(500);
    echo 'Webhook processing error';
    exit();
}

// Handle the checkout.session.completed event
if ($event->type == 'checkout.session.completed') {
	$sessionobject = $event->data->object;
	$order = new Order(NULL);
	// ... rest of webhook processing
}
```

### File: `/utils/scratch.php`

#### **Delete Problematic Lines (Lines 243, 551)**
```php
// DELETE these lines entirely (scratch file with copy/paste code that won't run):
// Line 243: \Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));
// Line 551: \Stripe\Stripe::setApiKey($settings->get_setting('stripe_api_key'));
```


## Summary of Key Pattern Changes
1. **Server-side API calls:** Always use `stripe_api_pkey` (secret key)
2. **Client-side JavaScript:** Always use `stripe_api_key` (publishable key) 
3. **Admin labels:** Match the actual key type stored in each field
4. **Validation:** Ensure correct key prefixes for each field

## Files That Need Changes
1. `/adm/admin_settings.php` - Labels, validation, API testing fixes
2. `/includes/StripeHelper.php` - Add private getter methods with validation
3. `/ajax/stripe_webhook.php` - Complete refactor to use StripeHelper
4. `/utils/scratch.php` - Delete problematic Stripe key usage lines
5. `/tests/functional/products/ProductTester.php` - No changes needed (already uses StripeHelper correctly)

## Priority
**URGENT** - This fix should be implemented immediately as it addresses:
- Potential security vulnerabilities from key exposure
- System reliability issues from wrong key usage
- Developer confusion from misleading naming
- Lack of validation allowing incorrect configuration