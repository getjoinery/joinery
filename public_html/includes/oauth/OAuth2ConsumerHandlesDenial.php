<?php
/**
 * OAuth2ConsumerHandlesDenial - a consumer that wants the denied / errored
 * consent, not only the granted token.
 *
 * The callback's default for a provider error is to return to the flow's
 * returnUrl with ?oauth=cancelled, which is right for a consumer with nothing
 * to add. A consumer that can translate the error into what the operator
 * should do next implements this and returns the page to land on; it may say
 * so with a DisplayMessage on the way. A null return falls back to the default.
 *
 * @version 1.0
 */
interface OAuth2ConsumerHandlesDenial {
    /**
     * @param string $error    the provider's error code, e.g. 'access_denied'
     * @param string $provider the provider key of the flow, e.g. 'google'
     * @param array  $payload  the opaque data the initiating page passed to beginConsent()
     * @return string|null a same-site path to redirect to, or null for the default
     */
    public function onConsentDenied(string $error, string $provider, array $payload): ?string;
}
