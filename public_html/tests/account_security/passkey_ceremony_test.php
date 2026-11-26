<?php
/** @joinery-test
 * name: passkey_ceremony
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Signed passkey ceremonies end to end through PasskeyService, with a software
 * authenticator written here: it makes ES256 and RSA keys, builds `none`
 * attestation objects in CBOR and signs assertions exactly as a browser and an
 * authenticator would. This is WP1 of specs/passkey_library_removal.md, and it
 * stands on its own: before it, nothing in the suite signed a ceremony.
 *
 * What is pinned:
 *  - registration, sign-in, step-up and PRF derivation succeed for a genuine
 *    response, for ES256 and RS256, and for a stored record with the optional
 *    members absent (the shape of older rows);
 *  - the refusal list: everything in it is refused today and must stay refused
 *    whatever library (or none) does the checking;
 *  - who may answer: the four checks in our own code that stand in for an
 *    allow list;
 *  - the option JSON each get*Options() method sends, against golden fixtures
 *    in tests/account_security/fixtures/passkey_options/ (rewrite them with
 *    PASSKEY_WRITE_FIXTURES=1 only for a deliberate change);
 *  - the stated exceptions: the cases in the spec's 'What changes result'
 *    table, each recorded with TODAY's result. When the new code decides
 *    (the spec's WP4), each flips, and its expected value here flips with it.
 *
 * Run: php tests/run.php db --filter=passkey_ceremony
 *
 * @version 1.1
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
// PasskeyService declares classes against WebAuthn library interfaces at file
// level, so the composer autoloader must be in place before the include.
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
require_once(PathHelper::getIncludePath('logic/password_reset_2fa_passkey_verify_logic.php'));

if (session_id() === '') @session_start();
if (session_id() === '') {
	harness_skip('passkey ceremony suite', 'no session could be started on the CLI');
	harness_finish();
}
harness_set_setting_mem('passkeys_enabled', '1');

$db = DbConnector::get_instance()->get_db_link();
$session_ids = array();
harness_defer(function () use (&$session_ids, $db) {
	$session_ids[] = session_id();
	foreach (array_unique($session_ids) as $sid) {
		$db->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?')->execute(array($sid));
	}
});

// ── encoding helpers ─────────────────────────────────────────────────────────

function pkt_b64u(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function pkt_b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** A CBOR head: major type and argument, shortest form. */
function cbor_head(int $major, int $n): string {
	$m = $major << 5;
	if ($n < 24) return chr($m | $n);
	if ($n < 0x100) return chr($m | 24) . chr($n);
	if ($n < 0x10000) return chr($m | 25) . pack('n', $n);
	return chr($m | 26) . pack('N', $n);
}
function cbor_int(int $i): string { return $i >= 0 ? cbor_head(0, $i) : cbor_head(1, -1 - $i); }
function cbor_bytes(string $b): string { return cbor_head(2, strlen($b)) . $b; }
function cbor_text(string $t): string { return cbor_head(3, strlen($t)) . $t; }
/** A map from [key, value] pairs of already-encoded CBOR, in the order given (so duplicates can be built). */
function cbor_map(array $pairs): string {
	$out = cbor_head(5, count($pairs));
	foreach ($pairs as $p) { $out .= $p[0] . $p[1]; }
	return $out;
}

// ── the software authenticator ───────────────────────────────────────────────

/**
 * One credential on a software authenticator. 'alg' is -7 (ES256) or -257
 * (RS256); the COSE key can be overridden to register a key we cannot sign
 * with (an RSA key with a forged exponent, say).
 */
class SoftKey {
	public $alg;
	public $key;
	public $id;
	public $counter = 0;
	public $cose_override = null;

	public function __construct(int $alg = -7, int $rsa_bits = 2048) {
		$this->alg = $alg;
		$this->key = $alg === -7
			? openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'))
			: openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $rsa_bits));
		$this->id = random_bytes(16);
	}

	public function cose(): string {
		if ($this->cose_override !== null) {
			return $this->cose_override;
		}
		$d = openssl_pkey_get_details($this->key);
		if ($this->alg === -7) {
			return cbor_map(array(
				array(cbor_int(1), cbor_int(2)), array(cbor_int(3), cbor_int(-7)), array(cbor_int(-1), cbor_int(1)),
				array(cbor_int(-2), cbor_bytes(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT))),
				array(cbor_int(-3), cbor_bytes(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))),
			));
		}
		return self::rsa_cose($d['rsa']['n'], $d['rsa']['e']);
	}

	public static function rsa_cose(string $n, string $e): string {
		return cbor_map(array(
			array(cbor_int(1), cbor_int(3)), array(cbor_int(3), cbor_int(-257)),
			array(cbor_int(-1), cbor_bytes($n)), array(cbor_int(-2), cbor_bytes($e)),
		));
	}

	public function sign(string $data): string {
		$sig = '';
		openssl_sign($data, $sig, $this->key, OPENSSL_ALGO_SHA256);
		return $sig;
	}
}

const FLAG_UP = 0x01;
const FLAG_UV = 0x04;
const FLAG_BE = 0x08;
const FLAG_BS = 0x10;
const FLAG_AT = 0x40;
const FLAG_ED = 0x80;

/** authData: rpIdHash, flags, counter, then attested data and an extension map when given. */
function pkt_auth_data(string $rp_id, int $flags, int $counter, ?string $attested = null, ?string $ext = null): string {
	return hash('sha256', $rp_id, true) . chr($flags) . pack('N', $counter) . ($attested ?? '') . ($ext ?? '');
}

function pkt_attested(SoftKey $k, ?string $cred_id = null): string {
	$cred_id = $cred_id ?? $k->id;
	return str_repeat("\0", 16) . pack('n', strlen($cred_id)) . $cred_id . $k->cose();
}

function pkt_client_data(string $type, string $challenge_b64u, string $origin, array $extra = array()): string {
	return json_encode(array_merge(array('type' => $type, 'challenge' => $challenge_b64u, 'origin' => $origin, 'crossOrigin' => false), $extra), JSON_UNESCAPED_SLASHES);
}

/**
 * A registration response for $options. $mut may change any part before it is
 * assembled: keys 'flags', 'client_extra', 'client_json', 'fmt', 'att_stmt',
 * 'att_object', 'auth_data', 'cred_id_in_auth', 'id', 'rawId', 'type',
 * 'raw_id_encoding' ('b64u' | 'std').
 */
function pkt_register_response(SoftKey $k, array $options, string $origin, string $rp_id, array $mut = array()): string {
	$flags = $mut['flags'] ?? (FLAG_UP | FLAG_UV | FLAG_AT);
	$auth = $mut['auth_data'] ?? pkt_auth_data($rp_id, $flags, $k->counter, pkt_attested($k, $mut['cred_id_in_auth'] ?? null));
	$att = $mut['att_object'] ?? cbor_map(array(
		array(cbor_text('fmt'), cbor_text($mut['fmt'] ?? 'none')),
		array(cbor_text('attStmt'), $mut['att_stmt'] ?? cbor_map(array())),
		array(cbor_text('authData'), cbor_bytes($auth)),
	));
	$cdj = $mut['client_json'] ?? pkt_client_data('webauthn.create', $options['challenge'], $origin, $mut['client_extra'] ?? array());
	$raw_id = $mut['rawId'] ?? pkt_b64u($k->id);
	$att_enc = pkt_b64u($att);
	if (($mut['raw_id_encoding'] ?? 'b64u') === 'std') {
		$raw_id = base64_encode(pkt_b64u_dec($raw_id));
		$att_enc = base64_encode($att);
	}
	return json_encode(array(
		'id' => $mut['id'] ?? pkt_b64u($k->id),
		'rawId' => $raw_id,
		'type' => $mut['type'] ?? 'public-key',
		'response' => array('clientDataJSON' => pkt_b64u($cdj), 'attestationObject' => $att_enc, 'transports' => array('internal')),
		'clientExtensionResults' => array('credProps' => array('rk' => true), 'prf' => array('enabled' => true)),
		'authenticatorAttachment' => 'platform',
	), JSON_UNESCAPED_SLASHES);
}

/**
 * An assertion response for $options. $mut keys: 'flags', 'client_extra',
 * 'client_json', 'auth_data', 'auth_suffix', 'signature' (raw bytes),
 * 'signature_field' (a JSON value placed as is), 'sign_over', 'user_handle'
 * (false = absent), 'id', 'rawId', 'type', 'prf' (bytes for prf.results.first),
 * 'drop', 'counter', 'raw_sig' (ES256 as r||s), 'signature_std' (a real
 * signature re-made until its standard base64 holds + or /, sent that way).
 */
function pkt_assert_response(SoftKey $k, array $options, string $origin, string $rp_id, ?string $user_handle, array $mut = array()): string {
	$k->counter = $mut['counter'] ?? ($k->counter + 1);
	$flags = $mut['flags'] ?? (FLAG_UP | FLAG_UV);
	$auth = $mut['auth_data'] ?? (pkt_auth_data($rp_id, $flags, $k->counter) . ($mut['auth_suffix'] ?? ''));
	$cdj = $mut['client_json'] ?? pkt_client_data('webauthn.get', $options['challenge'], $origin, $mut['client_extra'] ?? array());
	$sig = $mut['signature'] ?? $k->sign($mut['sign_over'] ?? ($auth . hash('sha256', $cdj, true)));
	if (!empty($mut['raw_sig'])) {
		$sig = pkt_der_to_raw($sig);
	}
	if (!empty($mut['signature_std'])) {
		// ECDSA signatures differ every time: re-sign until the standard
		// encoding needs + or /, so only the encoding is wrong.
		while (strpbrk(base64_encode($sig), '+/') === false) {
			$sig = $k->sign($auth . hash('sha256', $cdj, true));
		}
		$mut['signature_field'] = base64_encode($sig);
	}
	$response = array(
		'clientDataJSON' => pkt_b64u($cdj),
		'authenticatorData' => pkt_b64u($auth),
		'signature' => array_key_exists('signature_field', $mut) ? $mut['signature_field'] : pkt_b64u($sig),
	);
	$uh = array_key_exists('user_handle', $mut) ? $mut['user_handle'] : $user_handle;
	if ($uh !== false) {
		$response['userHandle'] = $uh;
	}
	foreach ((array)($mut['drop'] ?? array()) as $member) {
		unset($response[$member]);
	}
	$id = $mut['id'] ?? pkt_b64u($k->id);
	$out = array(
		'id' => $id,
		'rawId' => $mut['rawId'] ?? pkt_b64u($k->id),
		'type' => $mut['type'] ?? 'public-key',
		'response' => $response,
		'clientExtensionResults' => isset($mut['prf']) ? array('prf' => array('results' => array('first' => pkt_b64u($mut['prf'])))) : new stdClass(),
	);
	return json_encode($out, JSON_UNESCAPED_SLASHES);
}

/** An ECDSA DER signature as raw r || s. */
function pkt_der_to_raw(string $der): string {
	$pos = 2;
	$out = '';
	for ($i = 0; $i < 2; $i++) {
		$n = ord($der[$pos + 1]);
		$out .= str_pad(ltrim(substr($der, $pos + 2, $n), "\0"), 32, "\0", STR_PAD_LEFT);
		$pos += 2 + $n;
	}
	return $out;
}

// ── fixtures: users, service, ceremony runners ───────────────────────────────

$origin = rtrim(LibraryFunctions::get_absolute_url(), '/');
$rp_id = (string)parse_url($origin, PHP_URL_HOST);
$session = SessionControl::get_instance();

$owner = make_user('PkCerOwner');
$other = make_user('PkCerOther');

$sign_in_as = function (User $u) use ($session, &$session_ids) {
	// A check that signs out ends the PHP session; every ceremony needs one.
	if (session_id() === '') @session_start();
	$session_ids[] = session_id();
	$session->store_session_variables($u);
	$session_ids[] = session_id();
};

/** Does $fn throw? Returns the message, or null when it returned. */
function pkt_throws(callable $fn): ?string {
	try {
		$fn();
		return null;
	} catch (\Throwable $e) {
		return get_class($e) . ': ' . $e->getMessage();
	}
}

/** Register $k for the signed-in $u; returns the saved Passkey (or throws). */
$register = function (User $u, SoftKey $k, array $mut = array()) use ($origin, $rp_id, $sign_in_as) {
	$sign_in_as($u);
	$svc = new PasskeyService();
	$options = $svc->getRegistrationOptions($u);
	list($passkey) = $svc->verifyRegistration(pkt_register_response($k, $options, $origin, $rp_id, $mut), 'test ' . $k->alg);
	harness_register_row('pkc_passkey_credentials', 'pkc_passkey_credential_id', (int)$passkey->key);
	return $passkey;
};

/** The registration options for $u, and the response built for them, for a check that wants both. */
$register_response = function (User $u, SoftKey $k, array $mut = array()) use ($origin, $rp_id, $sign_in_as) {
	$sign_in_as($u);
	$svc = new PasskeyService();
	$options = $svc->getRegistrationOptions($u);
	return array($svc, pkt_register_response($k, $options, $origin, $rp_id, $mut));
};

$handle_of = function (User $u) { return pkt_b64u((string)$u->key); };

$sign_in = function (SoftKey $k, User $u, array $mut = array()) use ($origin, $rp_id, $handle_of) {
	$svc = new PasskeyService();
	$options = $svc->getAuthenticationOptions();
	return $svc->verifyAuthentication(pkt_assert_response($k, $options, $origin, $rp_id, $handle_of($u), $mut));
};

$step_up = function (SoftKey $k, User $as, User $handle_user, array $mut = array()) use ($origin, $rp_id, $handle_of) {
	$svc = new PasskeyService();
	$options = $svc->getStepUpOptions($as);
	return $svc->verifyStepUp(pkt_assert_response($k, $options, $origin, $rp_id, $handle_of($handle_user), $mut), $as);
};

$derive = function (SoftKey $k, User $for, User $handle_user, array $mut = array()) use ($origin, $rp_id, $handle_of) {
	$svc = new PasskeyService();
	$options = $svc->getDerivationOptions($for, 'vault-kek');
	$mut += array('prf' => random_bytes(32));
	return $svc->verifyDerivation(pkt_assert_response($k, $options, $origin, $rp_id, $handle_of($handle_user), $mut), 'vault-kek');
};

// ─────────────────────────────────────────────────────────────────────────────
section('Genuine ceremonies succeed');

$es = new SoftKey(-7);
$es_pk = null;
$err = pkt_throws(function () use (&$es_pk, $register, $owner, $es) { $es_pk = $register($owner, $es); });
check($err === null && $es_pk && $es_pk->key, 'an ES256 passkey registers', (string)$err);

$src = $es_pk ? json_decode((string)$es_pk->get('pkc_source_json'), true) : array();
check(($src['credentialPublicKey'] ?? '') !== '' && ($src['userHandle'] ?? '') === $handle_of($owner) && ($src['attestationType'] ?? '') === 'none'
	&& ($src['trustPath'] ?? null) === array() && ($src['publicKeyCredentialId'] ?? '') === pkt_b64u($es->id),
	'its stored record has the shape the spec names', json_encode(array_keys($src)));
check(strpos((string)($src['publicKeyCredentialId'] ?? ''), '=') === false && strpos((string)($src['credentialPublicKey'] ?? ''), '=') === false,
	'and its binary members are base64url without padding');

$err = pkt_throws(function () use ($sign_in, $es, $owner) {
	$u = $sign_in($es, $owner);
	if ((int)$u->key !== (int)$owner->key) throw new Exception('signed in as user ' . $u->key);
});
check($err === null, 'it signs in as its owner', (string)$err);

$err = pkt_throws(function () use ($step_up, $es, $owner, $es_pk) {
	if ($step_up($es, $owner, $owner) !== $es_pk->get('pkc_credential_id')) throw new Exception('wrong credential id returned');
});
check($err === null, 'it steps up, and step-up names the credential that answered', (string)$err);

$prf = random_bytes(32);
$err = pkt_throws(function () use ($derive, $es, $owner, $prf) {
	list($u, $pk, $out) = $derive($es, $owner, $owner, array('prf' => $prf));
	if ((int)$u->key !== (int)$owner->key || $out !== $prf) throw new Exception('wrong user or PRF output');
});
check($err === null, 'it derives a PRF secret, returned unchanged', (string)$err);

$rsa = new SoftKey(-257, 2048);
$rsa_pk = null;
$err = pkt_throws(function () use (&$rsa_pk, $register, $owner, $rsa) { $rsa_pk = $register($owner, $rsa); });
check($err === null, 'an RS256 (2048-bit) passkey registers', (string)$err);
$err = pkt_throws(function () use ($sign_in, $rsa, $owner) { $sign_in($rsa, $owner); });
check($err === null, 'and signs in', (string)$err);

// An older row: backupEligible, backupStatus, uvInitialized and otherUI absent.
$old = new SoftKey(-7);
$old_pk = $register($owner, $old);
$old_src = json_decode((string)$old_pk->get('pkc_source_json'), true);
foreach (array('backupEligible', 'backupStatus', 'uvInitialized', 'otherUI') as $member) {
	unset($old_src[$member]);
}
$old_pk->set('pkc_source_json', json_encode($old_src));
$old_pk->save();
$err = pkt_throws(function () use ($sign_in, $old, $owner) { $sign_in($old, $owner); });
check($err === null, 'a stored record without the optional members still signs in', (string)$err);

$err = pkt_throws(function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('counter' => 0)); });
check($err === null, 'a sign-count that goes backwards is logged, not refused');

$err = pkt_throws(function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('flags' => FLAG_UP)); });
check($err === null, 'sign-in without user verification is accepted (sign-in asks for preferred)', (string)$err);

$err = pkt_throws(function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('user_handle' => '')); });
check($err === null, 'an empty user handle is skipped, not refused', (string)$err);
$err = pkt_throws(function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('user_handle' => false)); });
check($err === null, 'and so is an absent one', (string)$err);
$err = pkt_throws(function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('user_handle' => null)); });
check($err === null, 'and a null one', (string)$err);

// ─────────────────────────────────────────────────────────────────────────────
section('Refused today, and must stay refused');

/*
 * Each refusal must be refused for the reason its label names, not merely
 * refused: a typo in a closure, or an unrelated library error, would otherwise
 * pass. These are the reasons today's library (and our own code) gives. If the
 * spec's WP2-WP5 ever resume, these fragments become a class-of-reason tag
 * carried by the new code's single exception type.
 * PASSKEY_PRINT_REASONS=1 prints the reason behind every refusal.
 */
$reasons = array(
	'id and rawId that differ' => 'Invalid ID',
	'type other than public-key' => 'Shall be "public-key"',
	'a missing signature' => 'Unable to create the response object',
	'a missing authenticatorData' => 'Missing authenticatorData',
	'a signature that is not a string' => '"signature". Shall be a string',
	'a real signature sent in standard base64 (+ and /)' => 'Shall be base64url encoded',
	'client data type webauthn.create on sign-in' => 'is not "webauthn.get"',
	'the wrong challenge' => 'Invalid challenge',
	'an empty challenge' => '"challenge". Shall not be empty',
	'another origin' => 'Invalid origin',
	'an http origin for an https site' => 'Invalid origin',
	'crossOrigin that is not a boolean' => '$crossOrigin of type bool',
	'topOrigin present' => 'cross-origin response was received',
	'a replayed response' => 'has expired or is invalid',
	'a challenge from another ceremony (a step-up challenge used to sign in)' => 'has expired or is invalid',
	'the wrong RP ID hash' => 'rpId hash mismatch',
	'user presence missing' => 'User was not present',
	'user verification missing on step-up' => 'User authentication required',
	'user verification missing on derivation' => 'User authentication required',
	'BS set without BE at sign-in' => 'backup is not eligible',
	'BE changed from the stored value at sign-in' => 'backup eligibility flag does not match',
	'authData shorter than 37 bytes' => 'Out of range',
	'leftover bytes after authData' => 'Presence of extra bytes',
	'ED set with no extension map' => 'Out of range',
	'a bad signature' => 'Invalid signature',
	'a signature by another key' => 'Invalid signature',
	'a non-empty user handle that is not the owner' => 'Invalid user handle',
	'an unknown credential' => 'not recognized',
	'registration with fmt packed' => 'format "packed" is not supported',
	'registration with fmt none and a non-empty attStmt' => 'Invalid attestation object',
	'registration without the AT flag (and no attested data)' => 'Not attested credential data',
	'registration without user presence' => 'User was not present',
	'registration with BS set without BE' => 'backup is not eligible',
	'registration with client data type webauthn.get' => 'is not "webauthn.create"',
	'registration with the wrong challenge' => 'Invalid challenge',
	'registration from another origin' => 'Invalid origin',
	'registration of a credential already registered' => 'may already be registered',
	'registration of an algorithm not offered (EdDSA)' => 'Invalid algorithm',
	'registration while signed out' => 'must be signed in',
	'a truncated attestation object' => 'Out of range',
	'a declared length longer than the input' => 'Out of range',
	'a duplicate key (two authData)' => 'is defined more than once',
	'a truncated COSE key inside authData' => 'Out of range',
	'authData that is not a byte string' => 'Out of range',
	'an attestation object that is not CBOR at all' => 'Out of range',
	'step-up answered by another user\'s passkey' => 'does not belong to your account',
	'derivation answered by another user\'s passkey' => 'has expired or is invalid',
	'a soft-deleted passkey' => 'not recognized',
	'an RSA key with exponent 1 never signs in' => 'Invalid signature',
);
$refused = function (string $label, callable $fn) use ($reasons) {
	$err = pkt_throws($fn);
	if (getenv('PASSKEY_PRINT_REASONS') === '1') { echo "REASON\t" . $label . "\t" . $err . "\n"; }
	$want = $reasons[$label] ?? null;
	check($want !== null && $err !== null && strpos($err, $want) !== false,
		$label . ' (refused: ' . ($want ?? 'NO EXPECTED REASON LISTED') . ')', $err === null ? 'accepted' : $err);
};

// The response envelope.
$refused('id and rawId that differ', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('id' => pkt_b64u(random_bytes(16)))); });
$refused('type other than public-key', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('type' => 'password')); });
$refused('a missing signature', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('drop' => array('signature'))); });
$refused('a missing authenticatorData', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('drop' => array('authenticatorData'))); });
$refused('a signature that is not a string', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('signature_field' => 12345)); });
$refused('a real signature sent in standard base64 (+ and /)', function () use ($sign_in, $es, $owner) {
	$sign_in($es, $owner, array('signature_std' => true));
});

// Client data.
$refused('client data type webauthn.create on sign-in', function () use ($sign_in, $es, $owner) {
	$sign_in($es, $owner, array('client_extra' => array('type' => 'webauthn.create')));
});
$refused('the wrong challenge', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('client_extra' => array('challenge' => pkt_b64u(random_bytes(32))))); });
$refused('an empty challenge', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('client_extra' => array('challenge' => ''))); });
$refused('another origin', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('client_extra' => array('origin' => 'https://evil.example'))); });
$refused('an http origin for an https site', function () use ($sign_in, $es, $owner, $origin) {
	$sign_in($es, $owner, array('client_extra' => array('origin' => preg_replace('#^https:#', 'http:', $origin))));
});
$refused('crossOrigin that is not a boolean', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('client_extra' => array('crossOrigin' => 'no'))); });
$refused('topOrigin present', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('client_extra' => array('topOrigin' => 'https://evil.example', 'crossOrigin' => true))); });
$replay_svc = new PasskeyService();
$replay_options = $replay_svc->getAuthenticationOptions();
$replay = pkt_assert_response($es, $replay_options, $origin, $rp_id, $handle_of($owner));
$err = pkt_throws(function () use ($replay_svc, $replay) { $replay_svc->verifyAuthentication($replay); });
check($err === null, 'a response is accepted the first time', (string)$err);
$refused('a replayed response', function () use ($replay_svc, $replay) { $replay_svc->verifyAuthentication($replay); });
$refused('a challenge from another ceremony (a step-up challenge used to sign in)', function () use ($es, $owner, $origin, $rp_id, $handle_of) {
	$svc = new PasskeyService();
	$options = $svc->getStepUpOptions($owner);
	$svc->verifyAuthentication(pkt_assert_response($es, $options, $origin, $rp_id, $handle_of($owner)));
});

// authData.
$refused('the wrong RP ID hash', function () use ($es, $owner, $origin, $handle_of) {
	$svc = new PasskeyService();
	$options = $svc->getAuthenticationOptions();
	$svc->verifyAuthentication(pkt_assert_response($es, $options, $origin, 'evil.example', $handle_of($owner)));
});
$refused('user presence missing', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('flags' => FLAG_UV)); });
$refused('user verification missing on step-up', function () use ($step_up, $es, $owner) { $step_up($es, $owner, $owner, array('flags' => FLAG_UP)); });
$refused('user verification missing on derivation', function () use ($derive, $es, $owner) { $derive($es, $owner, $owner, array('flags' => FLAG_UP)); });
$refused('BS set without BE at sign-in', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('flags' => FLAG_UP | FLAG_UV | FLAG_BS)); });
$refused('BE changed from the stored value at sign-in', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('flags' => FLAG_UP | FLAG_UV | FLAG_BE)); });
$refused('authData shorter than 37 bytes', function () use ($sign_in, $es, $owner, $rp_id) { $sign_in($es, $owner, array('auth_data' => substr(pkt_auth_data($rp_id, FLAG_UP | FLAG_UV, 9), 0, 36))); });
$refused('leftover bytes after authData', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('auth_suffix' => "\x00\x01")); });
$refused('ED set with no extension map', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('flags' => FLAG_UP | FLAG_UV | FLAG_ED)); });

// Signature and identity.
$refused('a bad signature', function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('sign_over' => 'something else')); });
$refused('a signature by another key', function () use ($sign_in, $es, $owner) {
	$sign_in($es, $owner, array('signature' => (new SoftKey(-7))->sign('x')));
});
$refused('a non-empty user handle that is not the owner', function () use ($sign_in, $es, $owner, $other, $handle_of) {
	$sign_in($es, $owner, array('user_handle' => $handle_of($other)));
});
$refused('an unknown credential', function () use ($sign_in, $owner) { $sign_in(new SoftKey(-7), $owner); });

// Registration.
$refused('registration with fmt packed', function () use ($register, $owner) { $register($owner, new SoftKey(-7), array('fmt' => 'packed')); });
$refused('registration with fmt none and a non-empty attStmt', function () use ($register, $owner) {
	$register($owner, new SoftKey(-7), array('att_stmt' => cbor_map(array(array(cbor_text('alg'), cbor_int(-7))))));
});
$refused('registration without the AT flag (and no attested data)', function () use ($register, $owner, $rp_id) {
	$register($owner, new SoftKey(-7), array('auth_data' => pkt_auth_data($rp_id, FLAG_UP | FLAG_UV, 0)));
});
$refused('registration without user presence', function () use ($register, $owner) { $register($owner, new SoftKey(-7), array('flags' => FLAG_UV | FLAG_AT)); });
$refused('registration with BS set without BE', function () use ($register, $owner) { $register($owner, new SoftKey(-7), array('flags' => FLAG_UP | FLAG_UV | FLAG_AT | FLAG_BS)); });
$refused('registration with client data type webauthn.get', function () use ($register, $owner) { $register($owner, new SoftKey(-7), array('client_extra' => array('type' => 'webauthn.get'))); });
$refused('registration with the wrong challenge', function () use ($register, $owner) { $register($owner, new SoftKey(-7), array('client_extra' => array('challenge' => pkt_b64u(random_bytes(32))))); });
$refused('registration from another origin', function () use ($register, $owner) { $register($owner, new SoftKey(-7), array('client_extra' => array('origin' => 'https://evil.example'))); });
$refused('registration of a credential already registered', function () use ($register, $owner, $es) { $register($owner, $es); });
$refused('registration of an algorithm not offered (EdDSA)', function () use ($register, $owner) {
	$k = new SoftKey(-7);
	$k->cose_override = cbor_map(array(array(cbor_int(1), cbor_int(1)), array(cbor_int(3), cbor_int(-8)), array(cbor_int(-1), cbor_int(6)), array(cbor_int(-2), cbor_bytes(random_bytes(32)))));
	$register($owner, $k);
});
$refused('registration while signed out', function () use ($owner, $origin, $rp_id, $sign_in_as, $session) {
	$sign_in_as($owner);
	$svc = new PasskeyService();
	$options = $svc->getRegistrationOptions($owner);
	// Signed out, with the same browser session (a logout here would end the
	// CLI session, which this process cannot start again).
	$saved = $_SESSION;
	$_SESSION['loggedin'] = false;
	try {
		$svc->verifyRegistration(pkt_register_response(new SoftKey(-7), $options, $origin, $rp_id), 'x');
	} finally {
		$_SESSION = $saved;
	}
});

// Malformed CBOR in the attestation object.
// authData for the SAME key the response is sent for: a different key's ID inside
// authData would be its own stated exception (rawId mismatch), and would refuse
// every case below for that reason once the new code decides.
$good_auth = function (SoftKey $k) use ($rp_id) { return pkt_auth_data($rp_id, FLAG_UP | FLAG_UV | FLAG_AT, 0, pkt_attested($k)); };
$att_of = function (string $auth, array $extra_pairs = array()) {
	return cbor_map(array_merge(array(
		array(cbor_text('fmt'), cbor_text('none')),
		array(cbor_text('attStmt'), cbor_map(array())),
		array(cbor_text('authData'), cbor_bytes($auth)),
	), $extra_pairs));
};
$refused('a truncated attestation object', function () use ($register, $owner, $good_auth, $att_of) {
	$k = new SoftKey(-7);
	$att = $att_of($good_auth($k));
	$register($owner, $k, array('att_object' => substr($att, 0, strlen($att) - 10)));
});
$refused('a declared length longer than the input', function () use ($register, $owner, $good_auth) {
	$k = new SoftKey(-7);
	$auth = $good_auth($k);
	$att = cbor_map(array(array(cbor_text('fmt'), cbor_text('none')), array(cbor_text('attStmt'), cbor_map(array())),
		array(cbor_text('authData'), cbor_head(2, strlen($auth) + 5000) . $auth)));
	$register($owner, $k, array('att_object' => $att));
});
$refused('a duplicate key (two authData)', function () use ($register, $owner, $good_auth) {
	$k = new SoftKey(-7);
	$att = cbor_map(array(array(cbor_text('fmt'), cbor_text('none')), array(cbor_text('attStmt'), cbor_map(array())),
		array(cbor_text('authData'), cbor_bytes($good_auth($k))), array(cbor_text('authData'), cbor_bytes($good_auth($k)))));
	$register($owner, $k, array('att_object' => $att));
});
$refused('a truncated COSE key inside authData', function () use ($register, $owner, $rp_id) {
	$k = new SoftKey(-7);
	$k->cose_override = substr($k->cose(), 0, 40);
	$register($owner, $k);
});
$refused('authData that is not a byte string', function () use ($register, $owner) {
	$att = cbor_map(array(array(cbor_text('fmt'), cbor_text('none')), array(cbor_text('attStmt'), cbor_map(array())), array(cbor_text('authData'), cbor_int(5))));
	$register($owner, new SoftKey(-7), array('att_object' => $att));
});
$refused('an attestation object that is not CBOR at all', function () use ($register, $owner) { $register($owner, new SoftKey(-7), array('att_object' => '{"fmt":"none"}')); });

// ─────────────────────────────────────────────────────────────────────────────
section('Who may answer: the checks in our own code');

$other_key = new SoftKey(-7);
$register($other, $other_key);
$sign_in_as($owner);

$refused('step-up answered by another user\'s passkey', function () use ($step_up, $other_key, $owner, $other) { $step_up($other_key, $owner, $other); });
$refused('derivation answered by another user\'s passkey', function () use ($derive, $other_key, $owner, $other) { $derive($other_key, $owner, $other); });

$gone = new SoftKey(-7);
$gone_pk = $register($owner, $gone);
$gone_pk->set('pkc_delete_time', gmdate('Y-m-d H:i:s'));
$gone_pk->save();
$refused('a soft-deleted passkey', function () use ($sign_in, $gone, $owner) { $sign_in($gone, $owner); });

// The vault-holder password reset: its step-up must be a DIFFERENT passkey.
// The refusal writes a failed attempt to rql_request_logs, which also counts
// toward the reset's rate limit (10 per 15 minutes per network, and every CLI
// run is one network): remove what this check wrote.
$rql_before = (int)$db->query('SELECT COALESCE(MAX(rql_request_log_id), 0) FROM rql_request_logs')->fetchColumn();
harness_defer(function () use ($db, $rql_before) {
	$db->prepare("DELETE FROM rql_request_logs WHERE rql_request_log_id > ? AND rql_feature = 'password_reset' AND rql_action = 'passkey_reset_2fa_passkey' AND rql_ip_address = '0.0.0.0'")
		->execute(array($rql_before));
});
$_SESSION['pwreset_pk_user_id'] = (int)$owner->key;
$_SESSION['pwreset_pk_used_cred'] = (string)$es_pk->get('pkc_credential_id');
$_SESSION['pwreset_pk_expires'] = time() + 600;
$svc = new PasskeyService();
$options = $svc->getStepUpOptions($owner, array((string)$es_pk->get('pkc_credential_id')));
check(!in_array(pkt_b64u($es->id), array_column($options['allowCredentials'] ?? array(), 'id'), true), 'the reset step-up does not offer the passkey that started the reset');
$r = password_reset_2fa_passkey_verify_logic(array('credential' => json_decode(pkt_assert_response($es, $options, $origin, $rp_id, $handle_of($owner)), true)));
check($r->error !== null && stripos((string)$r->error, 'different passkey') !== false, 'and refuses it if it answers anyway', json_encode(array($r->error, $r->data)));
unset($_SESSION['pwreset_pk_user_id'], $_SESSION['pwreset_pk_used_cred'], $_SESSION['pwreset_pk_expires']);

// ─────────────────────────────────────────────────────────────────────────────
section('Origin forms');

foreach (array('an explicit :443 in webDir' => $rp_id . ':443', 'a path in webDir' => $rp_id . '/sub') as $label => $web_dir) {
	harness_set_setting_mem('webDir', $web_dir);
	$err = pkt_throws(function () use ($sign_in, $es, $owner) { $sign_in($es, $owner); });
	check($err === null, 'sign-in from https://' . $rp_id . ' passes with ' . $label, (string)$err);
}
harness_set_setting_mem('webDir', null);
$sign_in_as($owner);

// ─────────────────────────────────────────────────────────────────────────────
section('Stated exceptions: today\'s result (flips at the spec\'s WP4)');

// Each entry: label, TODAY's result (true = accepted), and the ceremony.
$exceptions = array(
	array('registration whose authData credential ID differs from rawId', true, function () use ($register, $owner) {
		$register($owner, new SoftKey(-7), array('cred_id_in_auth' => random_bytes(16)));
	}),
	array('standard base64 in rawId and attestationObject', true, function () use ($register, $owner) {
		$k = new SoftKey(-7);
		$k->id = str_repeat("\xfb\xff", 8);   // encodes with + and /
		$register($owner, $k, array('raw_id_encoding' => 'std'));
	}),
	array('= padding on id', false, function () use ($sign_in, $es, $owner) {
		$sign_in($es, $owner, array('id' => strtr(base64_encode($es->id), '+/', '-_')));
	}),
	array('= padding on clientDataJSON', false, function () use ($es, $owner, $origin, $rp_id, $handle_of) {
		$svc = new PasskeyService();
		$options = $svc->getAuthenticationOptions();
		$resp = json_decode(pkt_assert_response($es, $options, $origin, $rp_id, $handle_of($owner)), true);
		$cdj = pkt_b64u_dec($resp['response']['clientDataJSON']);
		// Pad it to a length that needs '=' (append spaces inside the JSON is not
		// allowed, so add a member until it does).
		$data = json_decode($cdj, true);
		$data['x'] = '';
		while (strlen(json_encode($data, JSON_UNESCAPED_SLASHES)) % 3 === 0) { $data['x'] .= 'a'; }
		$cdj = json_encode($data, JSON_UNESCAPED_SLASHES);
		$auth = pkt_b64u_dec($resp['response']['authenticatorData']);
		$resp['response']['clientDataJSON'] = strtr(base64_encode($cdj), '+/', '-_');
		$resp['response']['signature'] = pkt_b64u($es->sign($auth . hash('sha256', $cdj, true)));
		$svc->verifyAuthentication(json_encode($resp, JSON_UNESCAPED_SLASHES));
	}),
	array('an indefinite-length CBOR map in the attestation object', true, function () use ($register, $owner, $good_auth) {
		$k = new SoftKey(-7);
		$att = "\xbf" . cbor_text('fmt') . cbor_text('none') . cbor_text('attStmt') . cbor_map(array()) . cbor_text('authData') . cbor_bytes($good_auth($k)) . "\xff";
		$register($owner, $k, array('att_object' => $att));
	}),
	array('crossOrigin true with no topOrigin', true, function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('client_extra' => array('crossOrigin' => true))); }),
	array('ES256 signature as raw r||s', true, function () use ($sign_in, $es, $owner) { $sign_in($es, $owner, array('raw_sig' => true)); }),
	array('AT flag set on an assertion (attested data appended)', true, function () use ($sign_in, $es, $owner) {
		$sign_in($es, $owner, array('flags' => FLAG_UP | FLAG_UV | FLAG_AT, 'auth_suffix' => pkt_attested($es)));
	}),
	array('RSA key with exponent 1 registers', true, function () use ($register, $owner) {
		$k = new SoftKey(-257);
		$k->cose_override = SoftKey::rsa_cose(openssl_pkey_get_details($k->key)['rsa']['n'], "\x01");
		$register($owner, $k);
	}),
	array('RSA key with exponent 3 registers', true, function () use ($register, $owner) {
		$k = new SoftKey(-257);
		$k->cose_override = SoftKey::rsa_cose(openssl_pkey_get_details($k->key)['rsa']['n'], "\x03");
		$register($owner, $k);
	}),
	array('RSA key over 4096 bits registers', true, function () use ($register, $owner) {
		$k = new SoftKey(-257);
		$k->cose_override = SoftKey::rsa_cose("\xc1" . random_bytes(519), "\x01\x00\x01");
		$register($owner, $k);
	}),
	array('a malformed tokenBinding in client data', false, function () use ($sign_in, $es, $owner) {
		$sign_in($es, $owner, array('client_extra' => array('tokenBinding' => array('status' => 'bogus'))));
	}),
);
foreach ($exceptions as $e) {
	list($label, $accepted_today, $fn) = $e;
	$err = pkt_throws($fn);
	check(($err === null) === $accepted_today, $label . ': ' . ($accepted_today ? 'accepted' : 'refused') . ' today',
		$err === null ? 'accepted' : $err);
}

// RSA 1024: registers and signs in today (a PHP warning only); refused at WP4.
$weak = new SoftKey(-257, 1024);
$err = pkt_throws(function () use ($register, $owner, $weak, $sign_in) {
	$register($owner, $weak);
	@$sign_in($weak, $owner);
});
check($err === null, 'RSA 1024-bit key: registers and signs in today', (string)$err);

// RSA exponent 1: registered above, but can never sign in (cose-lib refuses at verify).
$e1 = new SoftKey(-257);
$e1->cose_override = SoftKey::rsa_cose(openssl_pkey_get_details($e1->key)['rsa']['n'], "\x01");
$register($owner, $e1);
$refused('an RSA key with exponent 1 never signs in', function () use ($sign_in, $e1, $owner) { $sign_in($e1, $owner); });

// ─────────────────────────────────────────────────────────────────────────────
section('The option JSON matches its golden fixtures');

$fixture_dir = __DIR__ . '/fixtures/passkey_options';
$write = getenv('PASSKEY_WRITE_FIXTURES') === '1';

/** Replace what varies per run (challenge, ids, names) with placeholders, keeping every key and its type. */
function pkt_normalize($v, string $path = '') {
	if (is_array($v)) {
		$out = array();
		foreach ($v as $k => $item) {
			$out[$k] = pkt_normalize($item, $path . '/' . $k);
		}
		return $out;
	}
	if (is_string($v) && preg_match('#/(challenge|id|rpId|name|displayName)$#', $path) && !preg_match('#/pubKeyCredParams/#', $path)) {
		return '<' . basename($path) . '>';
	}
	return $v;
}

$sign_in_as($owner);
$svc = new PasskeyService();
$golden = array(
	'registration' => $svc->getRegistrationOptions($owner),
	'authentication' => $svc->getAuthenticationOptions(),
	'authentication_by_email' => $svc->getAuthenticationOptions((string)$owner->get('usr_email')),
	'stepup' => $svc->getStepUpOptions($owner),
	'derivation' => $svc->getDerivationOptions($owner, 'vault-kek'),
	'derivation_two_contexts' => $svc->getDerivationOptions($owner, 'vault-kek', null, '', 'vault-root-kek'),
	'diagnostic' => $svc->getDiagnosticOptions($owner, array('uv' => 'preferred', 'prf' => true)),
);
// What the placeholders hide, checked directly. (Note: the options are decoded
// to PHP arrays, so a fixture cannot tell {} from []: registration.json's
// "prf": [] is what the service really returns today, not a fixture bug.)
$live_ids = array();
foreach (new MultiPasskey(array('user_id' => $owner->key, 'deleted' => false)) as $pk) {
	$live_ids[] = (string)$pk->get('pkc_credential_id');
}
sort($live_ids);
$b64u_ok = function ($s) { return is_string($s) && preg_match('/^[A-Za-z0-9_-]+$/', $s) === 1; };
foreach ($golden as $name => $options) {
	$rp = $options['rp']['id'] ?? ($options['rpId'] ?? null);
	check($rp === $rp_id, $name . ': the RP ID is the site host', (string)$rp);
	check($b64u_ok($options['challenge'] ?? null) && strlen(pkt_b64u_dec($options['challenge'])) === 32,
		$name . ': the challenge is 32 bytes, unpadded base64url', (string)($options['challenge'] ?? ''));
	if (isset($options['user'])) {
		check(($options['user']['id'] ?? '') === $handle_of($owner), $name . ': user.id is base64url of the user key');
	}
	$list = $options['allowCredentials'] ?? ($options['excludeCredentials'] ?? null);
	if ($list !== null && $name !== 'authentication') {
		$ids = array_column($list, 'id');
		sort($ids);
		check($ids === $live_ids, $name . ': the credential list is exactly the user\'s live passkeys',
			count($ids) . ' listed, ' . count($live_ids) . ' live');
	}
}
check(($golden['authentication']['allowCredentials'] ?? array()) === array(), 'usernameless sign-in offers no credential list');

foreach ($golden as $name => $options) {
	// The exclude and allow lists depend on how many passkeys this run made;
	// keep one entry so the shape is pinned and the count is not.
	foreach (array('excludeCredentials', 'allowCredentials') as $list) {
		if (!empty($options[$list])) {
			$options[$list] = array($options[$list][0]);
		}
	}
	$shape = json_encode(pkt_normalize($options), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	$file = $fixture_dir . '/' . $name . '.json';
	if ($write) {
		if (!is_dir($fixture_dir)) mkdir($fixture_dir, 0755, true);
		file_put_contents($file, $shape);
	}
	check(is_file($file) && file_get_contents($file) === $shape, $name . ' options match ' . basename($file),
		is_file($file) ? "got:\n" . $shape : 'fixture missing (PASSKEY_WRITE_FIXTURES=1 writes it)');
}

harness_finish();
