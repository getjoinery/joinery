<?php
/**
 * OAuth2Consumer - How a feature receives its token.
 *
 * A consumer is a purpose key plus a token-granted handler. The generic
 * callback dispatches on the flow's `purpose` through OAuth2ConsumerRegistry,
 * so adding a new feature that needs OAuth is "a new OAuth2Consumer" and
 * nothing in the core grant engine changes. Consumers are discovered by
 * interface across core includes/oauth/consumers/ and active-plugin
 * includes/oauth_consumers/.
 *
 * @version 1.0
 */
interface OAuth2Consumer {
    /** The purpose this consumer handles, e.g. 'inbound_imap', 'social_login'. */
    public static function getPurpose(): string;

    /**
     * Persist the token for this purpose's payload and return the SUCCESS
     * redirect URL (a same-site path). Only called when a token was actually
     * granted — the deny/error path never reaches here and redirects to the
     * flow's returnUrl instead. $payload is the opaque data the initiating page
     * passed to beginConsent() (e.g. ['account_id' => N]).
     */
    public function onTokenGranted(OAuth2Token $token, array $payload): string;
}
