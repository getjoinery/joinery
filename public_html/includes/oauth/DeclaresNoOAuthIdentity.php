<?php
/**
 * DeclaresNoOAuthIdentity - "this provider cannot tell us who signed in".
 *
 * Most OAuth providers on this platform are reached for a capability, not for a
 * person: a DNS provider is asked to publish records, and the account behind the
 * grant is nobody's identity. Those providers implement the identity contract by
 * saying they have none — one `use` line, no methods.
 *
 * A provider that CAN report an identity (Google, Microsoft) overrides
 * getIdentityEndpoint() and identityFromProfile(), and adds whatever extra
 * scopes that costs in identityScopes(). Nothing else changes: the request is
 * made by OAuth2Client, which knows only "GET this URL with the bearer token",
 * so the grant engine stays provider-agnostic exactly as it is for tokens.
 *
 * @version 1.0
 */

trait DeclaresNoOAuthIdentity {

    /** Extra scopes an identity lookup needs. None, when there is no lookup. */
    public static function identityScopes(): array {
        return [];
    }

    /** The profile endpoint, or NULL when this provider reports no identity. */
    public static function getIdentityEndpoint(): ?string {
        return null;
    }

    /** The email address in a decoded profile payload, or NULL. */
    public static function identityFromProfile(array $profile): ?string {
        return null;
    }
}
