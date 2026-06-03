<?php
/**
 * OAuth2Token - Immutable value object for an OAuth2 token set.
 *
 * Holds the access token, the (optional) refresh token, an absolute UTC expiry
 * computed from the grant's expires_in, and the granted scope/token type. A
 * refresh response often omits the refresh token, in which case the caller
 * keeps the prior one — withRefreshedAccess() encodes exactly that.
 *
 * Times are stored as UTC 'Y-m-d H:i:s' strings to match the rest of the
 * platform; comparison is string comparison against gmdate().
 *
 * @version 1.0
 */
class OAuth2Token {

    /** @var string */
    private $access_token;
    /** @var string|null */
    private $refresh_token;
    /** @var string|null UTC 'Y-m-d H:i:s', or null if the provider gave no expiry. */
    private $expires_at;
    /** @var string */
    private $scope;
    /** @var string */
    private $token_type;

    public function __construct(
        string $access_token,
        ?string $refresh_token,
        ?string $expires_at,
        string $scope = '',
        string $token_type = 'Bearer'
    ) {
        $this->access_token = $access_token;
        $this->refresh_token = ($refresh_token === '' ? null : $refresh_token);
        $this->expires_at = ($expires_at === '' ? null : $expires_at);
        $this->scope = $scope;
        $this->token_type = $token_type;
    }

    /**
     * Build from a decoded token-endpoint response. `expires_in` (seconds from
     * now) is converted to an absolute UTC timestamp.
     */
    public static function fromResponse(array $r): OAuth2Token {
        $expires_at = null;
        if (isset($r['expires_in']) && is_numeric($r['expires_in'])) {
            $expires_at = gmdate('Y-m-d H:i:s', time() + (int)$r['expires_in']);
        }
        return new self(
            (string)($r['access_token'] ?? ''),
            isset($r['refresh_token']) ? (string)$r['refresh_token'] : null,
            $expires_at,
            (string)($r['scope'] ?? ''),
            (string)($r['token_type'] ?? 'Bearer')
        );
    }

    public function getAccessToken(): string { return $this->access_token; }
    public function getRefreshToken(): ?string { return $this->refresh_token; }
    public function getExpiresAt(): ?string { return $this->expires_at; }
    public function getScope(): string { return $this->scope; }
    public function getTokenType(): string { return $this->token_type; }

    /**
     * True if the access token is expired or within $skew seconds of expiring.
     * A token with no known expiry is treated as not expired (provider gave no
     * lifetime; let the API surface a 401 instead).
     */
    public function isExpired(int $skew = 60): bool {
        if ($this->expires_at === null) {
            return false;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() + $skew);
        return $this->expires_at <= $cutoff;
    }

    /**
     * Return a copy carrying a refreshed access token. If the refresh response
     * omitted a refresh token, the prior one is preserved.
     */
    public function withRefreshedAccess(OAuth2Token $refreshed): OAuth2Token {
        return new self(
            $refreshed->access_token,
            $refreshed->refresh_token ?? $this->refresh_token,
            $refreshed->expires_at,
            $refreshed->scope !== '' ? $refreshed->scope : $this->scope,
            $refreshed->token_type
        );
    }
}
