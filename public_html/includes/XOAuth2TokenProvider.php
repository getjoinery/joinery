<?php
/**
 * XOAuth2TokenProvider - supplies a fresh XOAUTH2 SASL string to PHPMailer for
 * SMTP authentication, sourcing the access token from OAuth2 Core.
 *
 * PHPMailer ships its own OAuth class, but it depends on league/oauth2-client,
 * which the platform does not install — the whole OAuth grant lives in OAuth2Core
 * (OAuth2Client) instead. This thin provider bridges the two: it implements
 * PHPMailer's OAuthTokenProvider interface and, on each getOauth64() call, asks
 * OAuth2Client::ensureFresh() for a live access token (refreshing and persisting
 * it back on the connected account when needed), then formats the standard
 * XOAUTH2 string. This is the single XOAUTH2 primitive — it benefits any OAuth
 * SMTP use, not just connected accounts.
 *
 * The same shared OAuth grant powers inbound IMAP polling, so a refresh failure
 * here flags iia_needs_reauth exactly as the inbound path does — one Reconnect
 * fixes both directions.
 *
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Exception.php'));

use PHPMailer\PHPMailer\OAuthTokenProvider;

class XOAuth2TokenProvider implements OAuthTokenProvider {

    /** @var InboundImapAccount The connected account holding the shared OAuth grant. */
    private $account;

    /** @var string The authenticated mailbox address (SASL `user=`). */
    private $userEmail;

    public function __construct(InboundImapAccount $account, string $userEmail) {
        $this->account = $account;
        $this->userEmail = $userEmail;
    }

    /**
     * Return the base64-encoded XOAUTH2 SASL initial-client-response:
     *   base64("user=<email>^Aauth=Bearer <access_token>^A^A")
     * Ensures the access token is fresh first, persisting a refreshed token back
     * onto the account so inbound polling reuses it.
     */
    public function getOauth64() {
        $accessToken = $this->freshAccessToken();
        return base64_encode(
            'user=' . $this->userEmail .
            "\001auth=Bearer " . $accessToken .
            "\001\001"
        );
    }

    /**
     * Resolve a live access token from the account's stored grant, refreshing
     * via OAuth2 Core when expired. Mirrors ImapIngestor::freshAccessToken so the
     * two directions share one refresh+persist+reauth-flag behavior.
     *
     * @throws OAuth2Exception when the account is not connected or refresh fails.
     */
    private function freshAccessToken(): string {
        $token = $this->account->getOAuthToken();
        if ($token === null) {
            throw new OAuth2Exception('This account is not connected (no OAuth token).');
        }

        $providerKey = $this->account->getOAuthProviderKey();
        $providerClass = $providerKey ? OAuth2ProviderRegistry::get($providerKey) : null;
        if ($providerClass === null) {
            throw new OAuth2Exception('OAuth provider "' . (string)$providerKey . '" is not available.');
        }

        try {
            $fresh = (new OAuth2Client())->ensureFresh($providerClass, $token);
        } catch (OAuth2Exception $e) {
            // The refresh token is no longer usable (revoked/expired). Flag the
            // account so the UI offers Reconnect for both inbound and outbound.
            $this->account->markNeedsReauth();
            throw $e;
        }

        if ($fresh->getAccessToken() !== $token->getAccessToken()) {
            $this->account->setOAuthToken($fresh);
            $this->account->save();
        }

        return $fresh->getAccessToken();
    }
}
