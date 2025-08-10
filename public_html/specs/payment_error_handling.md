# Payment Gateway Error Handling Analysis

## Current Issues

The current payment gateway error handling implementations lose valuable context and fail to provide meaningful feedback to users and administrators.

### Stripe Integration Problems

Current implementation in `StripeHelper.php`:

```php
public function processPayment($amount, $token) {
    try {
        $charge = \Stripe\Charge::create([
            'amount' => $amount,
            'currency' => 'usd',
            'source' => $token
        ]);
    } catch (\Stripe\Exception\CardException $e) {
        // Stripe-specific error lost in translation
        throw new SystemDisplayableError("Payment failed");
        // Lost: decline code, failure reason, risk assessment
    } catch (\Stripe\Exception\ApiException $e) {
        // Network errors conflated with card errors  
        throw new SystemDisplayableError("Payment failed");
    }
}
```

**Problems:**
1. **Loss of error context**: Decline codes, failure reasons, and risk assessments are discarded
2. **No distinction between error types**: Card errors vs network errors vs API errors all become generic "Payment failed"
3. **Poor user experience**: Users get no guidance on how to resolve payment issues
4. **Limited debugging**: No context preserved for troubleshooting payment failures

### PayPal Integration Problems

Current implementation in `PaypalHelper.php`:

```php
public function executePayment($paymentId, $payerId) {
    try {
        $result = $this->paypal->executePayment($paymentId, $payerId);
    } catch (PayPalConnectionException $e) {
        // Logs full request/response including sensitive data
        error_log("PayPal error: " . $e->getData());
        throw new SystemDisplayableError("Payment processing error");
    }
}
```

**Problems:**
1. **PCI compliance issues**: Full request/response data (potentially including sensitive info) logged
2. **Generic error messages**: All PayPal errors become "Payment processing error"
3. **No retry guidance**: Users don't know if they should retry or use different payment method

## Proposed Improvements

### 1. Create Payment-Specific Exceptions

```php
class PaymentException extends BaseException {
    protected string $provider;
    protected string $errorCode;
    protected array $context;
    
    public function __construct(
        string $message,
        string $provider,
        string $errorCode = '',
        array $context = []
    ) {
        parent::__construct($message);
        $this->provider = $provider;
        $this->errorCode = $errorCode;
        $this->context = $context;
    }
}

class CardDeclinedException extends PaymentException {
    protected string $userMessage = 'Your card was declined. Please try a different payment method.';
}

class PaymentNetworkException extends PaymentException {
    protected string $userMessage = 'Connection error occurred. Please try again.';
}

class PaymentValidationException extends PaymentException {
    protected string $userMessage = 'Please check your payment details and try again.';
}
```

### 2. Enhanced Stripe Error Handler

```php
class StripeErrorHandler {
    public function handleStripeException(\Stripe\Exception\ApiException $e): PaymentException {
        $context = [
            'stripe_error_type' => $e->getError()->type ?? 'unknown',
            'stripe_error_code' => $e->getError()->code ?? 'unknown',
            'stripe_request_id' => $e->getRequestId(),
            'http_status' => $e->getHttpStatus()
        ];
        
        // Don't log sensitive card data
        if ($e instanceof \Stripe\Exception\CardException) {
            return new CardDeclinedException(
                $e->getMessage(),
                'stripe',
                $e->getError()->code,
                $context
            );
        }
        
        if ($e instanceof \Stripe\Exception\RateLimitException) {
            return new PaymentNetworkException(
                'Rate limit exceeded',
                'stripe',
                'rate_limit',
                $context
            );
        }
        
        if ($e instanceof \Stripe\Exception\ApiConnectionException) {
            return new PaymentNetworkException(
                'Network connection error',
                'stripe',
                'connection',
                $context
            );
        }
        
        // Generic payment error for other cases
        return new PaymentException(
            $e->getMessage(),
            'stripe',
            'unknown',
            $context
        );
    }
    
    public function getUserMessage(\Stripe\Exception\CardException $e): string {
        return match($e->getError()->code) {
            'card_declined' => 'Your card was declined. Please contact your bank or try a different card.',
            'insufficient_funds' => 'Insufficient funds. Please try a different payment method.',
            'expired_card' => 'Your card has expired. Please update your payment method.',
            'incorrect_cvc' => 'The security code is incorrect. Please check and try again.',
            'processing_error' => 'A processing error occurred. Please try again.',
            default => 'Card error. Please check your payment details and try again.'
        };
    }
}
```

### 3. Enhanced PayPal Error Handler

```php
class PayPalErrorHandler {
    public function handlePayPalException(\PayPal\Exception\PayPalConnectionException $e): PaymentException {
        // Parse PayPal error response safely
        $errorData = json_decode($e->getData(), true);
        
        $context = [
            'paypal_error_name' => $errorData['name'] ?? 'unknown',
            'paypal_debug_id' => $errorData['debug_id'] ?? null,
            'http_status' => $e->getCode()
        ];
        
        // Don't log sensitive data
        $safeMessage = $this->sanitizePayPalMessage($errorData['message'] ?? $e->getMessage());
        
        return match($errorData['name'] ?? 'unknown') {
            'PAYMENT_ALREADY_DONE' => new PaymentValidationException(
                'Payment already processed',
                'paypal',
                'already_done',
                $context
            ),
            'PAYMENT_NOT_APPROVED_FOR_EXECUTION' => new PaymentValidationException(
                'Payment not approved',
                'paypal',
                'not_approved',
                $context
            ),
            'INSTRUMENT_DECLINED' => new CardDeclinedException(
                'Payment method declined',
                'paypal',
                'declined',
                $context
            ),
            default => new PaymentException(
                $safeMessage,
                'paypal',
                $errorData['name'] ?? 'unknown',
                $context
            )
        };
    }
    
    private function sanitizePayPalMessage(string $message): string {
        // Remove potentially sensitive information from PayPal error messages
        $message = preg_replace('/\b\d{13,19}\b/', '[CARD_NUMBER]', $message);
        $message = preg_replace('/\b\d{3,4}\b/', '[CVC]', $message);
        return $message;
    }
}
```

### 4. Payment Error Logging

```php
class PaymentErrorLogger {
    public function logPaymentError(PaymentException $e, array $context = []): void {
        // Create sanitized log entry
        $logData = [
            'provider' => $e->getProvider(),
            'error_code' => $e->getErrorCode(),
            'user_message' => $e->getUserMessage(),
            'timestamp' => time(),
            'user_id' => $context['user_id'] ?? null,
            'order_id' => $context['order_id'] ?? null,
            'amount' => $context['amount'] ?? null,
            'currency' => $context['currency'] ?? null,
            // Never log card numbers, CVCs, etc.
        ];
        
        // Log to specialized payment error table
        $this->database->insert('payment_errors', $logData);
        
        // Send alerts for critical payment errors
        if ($this->isCriticalError($e)) {
            $this->alertManager->sendPaymentAlert($e, $logData);
        }
    }
    
    private function isCriticalError(PaymentException $e): bool {
        // Define what constitutes a critical payment error
        return $e instanceof PaymentNetworkException || 
               $e->getErrorCode() === 'api_error';
    }
}
```

## Benefits of Enhanced Payment Error Handling

1. **Better user experience**: Users get specific, actionable error messages
2. **Improved debugging**: Payment failures include provider-specific context
3. **PCI compliance**: Sensitive payment data is never logged
4. **Provider-specific handling**: Different logic for Stripe vs PayPal vs other providers
5. **Monitoring and alerts**: Critical payment errors trigger immediate notifications
6. **Analytics**: Better data for understanding payment failure patterns

## Implementation Plan

### Phase 1: Create Base Payment Exception Classes
- Define PaymentException hierarchy
- Create provider-specific error handlers
- Implement sanitized logging

### Phase 2: Update Stripe Integration
- Replace generic SystemDisplayableError with specific payment exceptions
- Add Stripe error code mapping
- Test all Stripe error scenarios

### Phase 3: Update PayPal Integration  
- Implement PayPal error handler
- Add PCI-compliant logging
- Test PayPal error scenarios

### Phase 4: Add Monitoring and Alerts
- Create payment error dashboard
- Set up alerts for critical payment failures
- Add analytics for payment success/failure rates

This approach maintains PCI compliance while providing much better error handling and user experience for payment-related issues.