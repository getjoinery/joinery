<?php
/** @joinery-test
 * name: oauth_provider_identity
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The OAuth identity contract (specs/mailbox_connect_flow.md § C).
 *
 * Asking someone to type the address they are about to sign in AS invites a
 * mismatch that surfaces much later as an opaque IMAP authentication failure —
 * the address is used verbatim as the SASL username. A provider that can simply
 * report which account consented declares how to ask, and OAuth2Client makes one
 * bearer GET against that declaration.
 *
 * The parsing is where a provider's payload shape is known, so it is what this
 * pins: Google's userinfo `email`, Microsoft Graph's `mail` with
 * `userPrincipalName` behind it (a mailbox-less account reports only the second,
 * and the sign-in name is a better answer than nothing), and the ordinary case —
 * a provider reached for a capability rather than a person, which reports none.
 *
 * Run: php tests/run.php safe --filter=oauth_provider_identity
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));

try {

	section('google reports the address that consented');

	check(GoogleOAuthProvider::getIdentityEndpoint() === 'https://www.googleapis.com/oauth2/v3/userinfo',
		'google declares the userinfo endpoint');
	check(in_array('email', GoogleOAuthProvider::identityScopes(), true)
		&& in_array('openid', GoogleOAuthProvider::identityScopes(), true),
		'and the scopes that endpoint needs');
	check(GoogleOAuthProvider::identityFromProfile(array(
		'sub' => '110', 'email' => 'jem@example.com', 'email_verified' => true,
	)) === 'jem@example.com', 'the email field is read from a userinfo payload');
	check(GoogleOAuthProvider::identityFromProfile(array('sub' => '110')) === null,
		'a payload with no email reports none rather than something empty');

	section('microsoft prefers the mailbox address, then the sign-in name');

	check(MicrosoftOAuthProvider::getIdentityEndpoint() === 'https://graph.microsoft.com/v1.0/me',
		'microsoft declares the graph /me endpoint');
	check(MicrosoftOAuthProvider::identityScopes() === array('User.Read'),
		'and the one scope it needs');
	check(MicrosoftOAuthProvider::identityFromProfile(array(
		'mail' => 'jem@contoso.com', 'userPrincipalName' => 'jem_contoso.com#EXT#@x.onmicrosoft.com',
	)) === 'jem@contoso.com', 'mail wins when the account has a mailbox');
	check(MicrosoftOAuthProvider::identityFromProfile(array(
		'mail' => null, 'userPrincipalName' => 'jem@contoso.com',
	)) === 'jem@contoso.com', 'the sign-in name answers when there is no mailbox address');
	check(MicrosoftOAuthProvider::identityFromProfile(array('displayName' => 'Jem')) === null,
		'neither field means no identity, not a guess');

	section('a provider reached for a capability reports no identity');

	// The ordinary case, and the reason the defaults live in a trait: a DNS
	// provider's grant is about a zone, not a person, so "who signed in" has no
	// answer and must not be invented.
	foreach (array('DigitalOceanOAuthProvider', 'DnsimpleOAuthProvider', 'LinodeOAuthProvider') as $class) {
		check($class::getIdentityEndpoint() === null, $class . ' declares no identity endpoint');
		check($class::identityScopes() === array(), $class . ' asks for no identity scopes');
		check($class::identityFromProfile(array('email' => 'someone@example.com')) === null,
			$class . ' reads no address even from a payload that has one');
	}

	section('every registered provider satisfies the contract');

	// Discovery is by interface, so a provider that shipped without the identity
	// methods would be a fatal at load — this asserts the softer property that
	// each one ANSWERS, which is what callers depend on.
	foreach (OAuth2ProviderRegistry::all() as $key => $class) {
		$endpoint = $class::getIdentityEndpoint();
		check($endpoint === null || (is_string($endpoint) && strncmp($endpoint, 'https://', 8) === 0),
			$key . ': the identity endpoint is null or an https URL', (string)$endpoint);
		check(is_array($class::identityScopes()), $key . ': identityScopes() returns an array');
	}

} catch (\Throwable $e) {
	check(false, 'uncaught ' . get_class($e), $e->getMessage());
}

harness_finish();
