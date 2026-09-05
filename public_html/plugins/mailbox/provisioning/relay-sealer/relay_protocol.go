package main

// The relay API's wire vocabulary: the signed request envelope every /relay/
// route carries, and the signed birth report a relay posts to its plane.
//
// THIS FILE IS AN INTEROP CONTRACT. Every construction here has an exact
// counterpart in plugins/mailbox/includes/RelayProtocol.php, and the two are
// pinned against each other by direct_wire_gate.sh in the same way Direct's
// preflight is: PHP emits the signing bytes for a fixed fixture, Go emits them,
// and the two are diffed. A drift here would not throw anywhere — every pull
// from a Joinery box would simply fail signature verification at the relay and
// read as "the relay is down". That is the failure this relay has had before,
// which is why the bytes are pinned by their own fixture rather than by hope.
//
// The discipline is Direct's, the envelope is not: a relay request is signed by
// a TENANT key the relay holds in its registry (or the operator key), covers
// the method, the request URI with its query string, and a hash of the body, and
// is bound to a nonce and a timestamp so it can be neither replayed nor kept.

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"
)

const (
	// relayProtocolVersion is the version of the relay envelope this binary
	// speaks. An envelope naming another is refused before anything else is read.
	relayProtocolVersion = 1

	// The header a signed request rides in. Its value is standard base64 of the
	// JSON object {"envelope": {...}, "signature": "<base64>"} — base64 so the
	// header holds one token with no quoting rules to get wrong on either side.
	relayAuthHeader = "X-Joinery-Relay-Auth"

	// The header the one-time run token rides in, on the two plane-side calls a
	// relay makes at birth (bundle fetch and birth report). Never on a /relay/
	// route: the relay verifies signatures, it never holds a token.
	relayRunTokenHeader = "X-Joinery-Relay-Run-Token"

	// The reserved tenant name whose key is the operator's, not a tenant's. A
	// registry slug can never collide with it: slugs match slugRe, and this does
	// not need to — it is compared exactly and resolved from its own file.
	relayOperatorTenant = "operator"

	// Domain separators. The same Ed25519 key may sign relay requests today and
	// something else tomorrow; a prefix keeps a signature from ever being valid
	// as both. Direct's envelopes carry the same shape of prefix.
	relayRequestSigningPrefix = "joinery-relay:request:v1\n"
	relayBornSigningPrefix    = "joinery-relay:born:v1\n"
)

// relayEnvelope is what a request signature covers. FIELD ORDER IS THE
// CONTRACT: the canonical JSON is emitted in declaration order and PHP builds
// the same map in the same order.
type relayEnvelope struct {
	ProtocolVersion int    `json:"protocol_version"`
	Tenant          string `json:"tenant"`
	Method          string `json:"method"`
	// The path AND query string exactly as sent, so paging parameters and the
	// spool id in a fetch are covered by the signature.
	RequestURI string `json:"request_uri"`
	// Lowercase hex SHA-256 of the request body; of the empty string for a
	// bodyless request.
	BodySHA256 string `json:"body_sha256"`
	// 16 random bytes, standard base64.
	Nonce string `json:"nonce"`
	// YYYY-MM-DD HH:MM:SS in UTC — the format Direct's envelopes already use, so
	// one clock rule serves both.
	Timestamp string `json:"timestamp"`
}

// relaySignedRequest is the decoded header value.
type relaySignedRequest struct {
	Envelope  relayEnvelope `json:"envelope"`
	Signature string        `json:"signature"`
}

// relayBirthReport is what a relay tells its plane once, at birth, signed by
// the identity key it just generated. Also order-sensitive.
type relayBirthReport struct {
	RunID               string `json:"run_id"`
	PublicIP            string `json:"public_ip"`
	IdentityPublicKey   string `json:"identity_public_key"`
	IdentityFingerprint string `json:"identity_fingerprint"`
	RelayVersion        string `json:"relay_version"`
	// "ok", or a short word naming what is wrong ("inactive", "unbound").
	Postfix     string `json:"postfix"`
	Listener443 string `json:"listener_443"`
}

// relaySignedBirthReport is the whole POST body of /api/v1/relay/born.
type relaySignedBirthReport struct {
	Report    relayBirthReport `json:"report"`
	Signature string           `json:"signature"`
}

// relayRequestSigningBytes is the exact byte string a request signature covers.
func relayRequestSigningBytes(env relayEnvelope) ([]byte, error) {
	c := relayEnvelope{
		ProtocolVersion: env.ProtocolVersion,
		Tenant:          env.Tenant,
		Method:          strings.ToUpper(strings.TrimSpace(env.Method)),
		RequestURI:      env.RequestURI,
		BodySHA256:      strings.ToLower(strings.TrimSpace(env.BodySHA256)),
		Nonce:           env.Nonce,
		Timestamp:       env.Timestamp,
	}
	body, err := canonicalJSON(c)
	if err != nil {
		return nil, err
	}
	return append([]byte(relayRequestSigningPrefix), body...), nil
}

// relayBirthSigningBytes is the exact byte string a birth report's signature
// covers.
func relayBirthSigningBytes(report relayBirthReport) ([]byte, error) {
	body, err := canonicalJSON(report)
	if err != nil {
		return nil, err
	}
	return append([]byte(relayBornSigningPrefix), body...), nil
}

// relayBodyHash is the body_sha256 value for some request bytes.
func relayBodyHash(body []byte) string {
	sum := sha256.Sum256(body)
	return hex.EncodeToString(sum[:])
}

// encodeRelayAuthHeader builds the header value a client sends.
func encodeRelayAuthHeader(env relayEnvelope, signature []byte) (string, error) {
	raw, err := json.Marshal(relaySignedRequest{
		Envelope:  env,
		Signature: base64.StdEncoding.EncodeToString(signature),
	})
	if err != nil {
		return "", err
	}
	return base64.StdEncoding.EncodeToString(raw), nil
}

// decodeRelayAuthHeader parses a header value. Either base64 alphabet, padded
// or not, is accepted for the outer wrapping, as it is for keys and signatures.
func decodeRelayAuthHeader(value string) (*relaySignedRequest, error) {
	value = strings.TrimSpace(value)
	if value == "" {
		return nil, fmt.Errorf("no signed envelope")
	}
	if len(value) > 4096 {
		return nil, fmt.Errorf("signed envelope too long")
	}
	var raw []byte
	for _, enc := range []*base64.Encoding{
		base64.StdEncoding, base64.RawStdEncoding,
		base64.URLEncoding, base64.RawURLEncoding,
	} {
		if decoded, err := enc.DecodeString(value); err == nil {
			raw = decoded
			break
		}
	}
	if raw == nil {
		return nil, fmt.Errorf("signed envelope is not base64")
	}
	var req relaySignedRequest
	if err := json.Unmarshal(raw, &req); err != nil {
		return nil, fmt.Errorf("signed envelope is not valid JSON")
	}
	return &req, nil
}
