<?php
/**
 * OAuth2Client - The OAuth2 authorization-code grant engine.
 *
 * Implemented directly on Guzzle. Provider- and consumer-agnostic: it knows how
 * to build a consent URL, exchange a code for tokens, refresh a token, and keep
 * one fresh — nothing about IMAP, social login, or any feature. The whole grant
 * is two standards-compliant token-endpoint POSTs plus consent-URL assembly, so
 * no OAuth library is used; everything routes through this class so one could be
 * wrapped in later without touching providers or consumers.
 *
 * A provider is referenced by its class-string (the value
 * OAuth2ProviderRegistry returns) because OAuth2Provider is an all-static
 * interface; static methods are called on it directly.
 *
 * @version 1.0
 */
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Exception.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Token.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2State.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class OAuth2Client {

    /** @var Client */
    private $http;

    public function __construct(?Client $http = null) {
        $this->http = $http ?: new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * The single, canonical redirect URI for every provider/consumer. Derived
     * from the configured host (webDir + protocol_mode), not raw HTTP_HOST, so
     * it is stable and byte-for-byte matches what providers have registered.
     */
    public static function redirectUri(): string {
        return LibraryFunctions::get_absolute_url('/oauth_callback');
    }

    /**
     * Begin a consent flow. Issues single-use session state and returns the
     * provider consent URL to redirect the browser to. $returnUrl is the
     * cancel/error destination (a same-site path) — where the callback sends the
     * user if they deny consent or the provider errors. The success destination
     * is the consumer's job (onTokenGranted return), not $returnUrl.
     */
    public function beginConsent(
        string $providerKey,
        array $scopes,
        string $purpose,
        array $payload,
        string $returnUrl
    ): string {
        $providerClass = OAuth2ProviderRegistry::get($providerKey);
        if ($providerClass === null) {
            throw new OAuth2Exception('Unknown OAuth2 provider "' . $providerKey . '".');
        }
        if (!$providerClass::isConfigured()) {
            throw new OAuth2Exception('OAuth2 provider "' . $providerKey . '" is not configured.');
        }

        $state = OAuth2State::issue($providerKey, $purpose, $scopes, $payload, $returnUrl);

        $params = array_merge([
            'response_type' => 'code',
            'client_id'     => $providerClass::getClientId(),
            'redirect_uri'  => self::redirectUri(),
            'scope'         => implode(' ', $scopes),
            'state'         => $state,
        ], $providerClass::extraAuthorizeParams($scopes));

        return $providerClass::getAuthorizeEndpoint() . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for a token set.
     * @param string $providerClass A class implementing OAuth2Provider.
     */
    public function exchangeCode(string $providerClass, string $code, string $redirectUri): OAuth2Token {
        return $this->tokenRequest($providerClass, [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Redeem a refresh token for a fresh access token.
     * @param string $providerClass A class implementing OAuth2Provider.
     */
    public function refresh(string $providerClass, string $refreshToken): OAuth2Token {
        return $this->tokenRequest($providerClass, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Return $t unchanged if its access token is still valid, otherwise refresh
     * and return the new token (the caller persists it). Throws OAuth2Exception
     * on refresh failure so a consumer can record status instead of crashing.
     * @param string $providerClass A class implementing OAuth2Provider.
     */
    public function ensureFresh(string $providerClass, OAuth2Token $t): OAuth2Token {
        if (!$t->isExpired()) {
            return $t;
        }
        $refreshToken = $t->getRefreshToken();
        if ($refreshToken === null) {
            throw new OAuth2Exception('Cannot refresh access token: no refresh token on record.');
        }
        $refreshed = $this->refresh($providerClass, $refreshToken);
        return $t->withRefreshedAccess($refreshed);
    }

    /**
     * POST to the provider token endpoint and parse the token set. Adds the app
     * credentials. Never includes credentials or tokens in thrown messages.
     */
    private function tokenRequest(string $providerClass, array $params): OAuth2Token {
        $params['client_id'] = $providerClass::getClientId();
        $params['client_secret'] = $providerClass::getClientSecret();

        try {
            $response = $this->http->request('POST', $providerClass::getTokenEndpoint(), [
                'form_params' => $params,
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => true,
            ]);
        } catch (RequestException $e) {
            throw new OAuth2Exception('OAuth2 token request failed: ' . self::describeError($e));
        } catch (Throwable $e) {
            throw new OAuth2Exception('OAuth2 token request failed: ' . $e->getMessage());
        }

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['access_token']) || $data['access_token'] === '') {
            throw new OAuth2Exception('OAuth2 token response did not contain an access_token.');
        }

        return OAuth2Token::fromResponse($data);
    }

    /**
     * Build a safe error string from a Guzzle exception: HTTP status plus the
     * standard OAuth `error`/`error_description` fields (which carry no
     * secrets). Falls back to the status code alone.
     */
    private static function describeError(RequestException $e): string {
        $response = $e->getResponse();
        if ($response === null) {
            return 'no response from provider';
        }
        $status = $response->getStatusCode();
        $decoded = json_decode((string)$response->getBody(), true);
        if (is_array($decoded) && isset($decoded['error'])) {
            $detail = (string)$decoded['error'];
            if (isset($decoded['error_description'])) {
                $detail .= ' (' . (string)$decoded['error_description'] . ')';
            }
            return 'HTTP ' . $status . ' ' . $detail;
        }
        return 'HTTP ' . $status;
    }
}
