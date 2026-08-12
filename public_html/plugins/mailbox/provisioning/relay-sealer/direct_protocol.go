package main

// Joinery Direct's wire vocabulary, and the two byte-forms an instance
// signature covers.
//
// THIS FILE IS AN INTEROP CONTRACT. Every construction here has an exact
// counterpart in includes/joinery_direct/DirectProtocol.php, and a signature is
// only worth anything if both ends agree byte for byte on what was covered. The
// two are pinned against each other by direct_wire_gate.sh, which has PHP emit
// the signing bytes and Go emit them and diffs the result — because a drift
// here would not throw anywhere, it would simply make every delivery from a
// Joinery box fail signature verification at the relay, and read as "the peer
// is unreachable".
//
// Two things get signed:
//
//   - the PREFLIGHT: envelope + the manifest of sizes, types and roles. This
//     authenticates and dates the exchange, before any content exists.
//   - the CONTENT TRANSFER: the per-part hashes of the SEALED bytes, bound to
//     the preflight nonce, so content cannot be spliced onto another exchange.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strings"

	"golang.org/x/crypto/blake2b"
)

const (
	// directProtocolVersion is the version of the shared layer this relay
	// implements. An envelope naming anything else is refused at request level,
	// exactly as an unknown kind is, so a partly-upgraded federation degrades to
	// the sender's fallback rather than breaking.
	directProtocolVersion = 1

	directEndpointPath = "/.well-known/joinery-direct"

	directAnswerAccept   = "accept"
	directAnswerDeclined = "declined"

	roleBodyText   = "body_text"
	roleBodyHTML   = "body_html"
	roleAttachment = "attachment"

	// Freshness window on the signed timestamp, and the clock-skew margin.
	directMaxAgeSeconds    = 300
	directMaxFutureSeconds = 60

	// How long a nonce is remembered. Deliberately longer than the freshness
	// window, so a replay old enough to have aged out of the cache is already
	// too stale to pass the freshness check and the two compose with no gap.
	directNonceTTLSeconds = 600
)

// directEnvelope is the signed envelope. Field ORDER IS THE CONTRACT — the
// canonical JSON is emitted in declaration order and PHP builds the same map in
// the same order.
type directEnvelope struct {
	ProtocolVersion int    `json:"protocol_version"`
	Kind            string `json:"kind"`
	Sender          string `json:"sender"`
	Recipient       string `json:"recipient"`
	KeyID           string `json:"key_id"`
	Nonce           string `json:"nonce"`
	Timestamp       string `json:"timestamp"`
}

// directManifestEntry is one declared part. Also order-sensitive.
type directManifestEntry struct {
	Role        string `json:"role"`
	ContentType string `json:"content_type"`
	Filename    string `json:"filename"`
	ContentID   string `json:"content_id"`
	IsInline    bool   `json:"is_inline"`
	Size        int64  `json:"size"`
}

// directPreflight is the whole preflight request body.
type directPreflight struct {
	Envelope  directEnvelope        `json:"envelope"`
	Manifest  []directManifestEntry `json:"manifest"`
	Signature string                `json:"signature"`
}

// directCommit is the content-transfer commit body.
type directCommit struct {
	Nonce         string   `json:"nonce"`
	Hashes        []string `json:"hashes"`
	Sealed        bool     `json:"sealed"`
	KeyGeneration int      `json:"key_generation"`
	Signature     string   `json:"signature"`
}

// canonicalPreflight mirrors PHP's ordered map exactly. A struct is used rather
// than a map because Go sorts map keys and PHP does not — the two would agree
// only by accident.
type canonicalPreflight struct {
	V         int                   `json:"v"`
	Kind      string                `json:"kind"`
	Sender    string                `json:"sender"`
	Recipient string                `json:"recipient"`
	KeyID     string                `json:"key_id"`
	Nonce     string                `json:"nonce"`
	Timestamp string                `json:"timestamp"`
	Manifest  []directManifestEntry `json:"manifest"`
}

type canonicalTransfer struct {
	Nonce  string   `json:"nonce"`
	Hashes []string `json:"hashes"`
}

// canonicalJSON encodes without HTML escaping and without the trailing newline
// Go's Encoder appends. PHP is called with JSON_UNESCAPED_SLASHES |
// JSON_UNESCAPED_UNICODE, which is the same output for this data.
func canonicalJSON(v any) ([]byte, error) {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return nil, err
	}
	return bytes.TrimRight(buf.Bytes(), "\n"), nil
}

// preflightSigningBytes is the exact byte string an instance signature over a
// preflight covers.
func preflightSigningBytes(env directEnvelope, manifest []directManifestEntry) ([]byte, error) {
	c := canonicalPreflight{
		V:         env.ProtocolVersion,
		Kind:      kindOrDefault(env.Kind),
		Sender:    asciiLower(env.Sender),
		Recipient: asciiLower(env.Recipient),
		KeyID:     env.KeyID,
		Nonce:     env.Nonce,
		Timestamp: env.Timestamp,
		Manifest:  canonicalManifest(manifest),
	}
	body, err := canonicalJSON(c)
	if err != nil {
		return nil, err
	}
	prefix := fmt.Sprintf("joinery-direct:preflight:v%d\n", c.V)
	return append([]byte(prefix), body...), nil
}

// transferSigningBytes is the exact byte string the commit signature covers:
// the ordered per-part hashes of the sealed bytes, bound to the preflight nonce.
func transferSigningBytes(nonce string, hashes []string) ([]byte, error) {
	if hashes == nil {
		hashes = []string{}
	}
	body, err := canonicalJSON(canonicalTransfer{Nonce: nonce, Hashes: hashes})
	if err != nil {
		return nil, err
	}
	prefix := fmt.Sprintf("joinery-direct:transfer:v%d\n", directProtocolVersion)
	return append([]byte(prefix), body...), nil
}

// canonicalManifest normalizes a manifest to the shape that is signed. A NIL
// slice becomes an empty one: PHP's array() encodes as [] and Go's nil encodes
// as null, which would be a silent signature mismatch on an empty manifest.
func canonicalManifest(in []directManifestEntry) []directManifestEntry {
	out := make([]directManifestEntry, 0, len(in))
	for _, e := range in {
		if e.ContentType == "" {
			e.ContentType = "application/octet-stream"
		}
		out = append(out, e)
	}
	return out
}

// hashBytes is BLAKE2b-256 of some bytes, lowercase hex — the hash the transfer
// signature covers. It is taken over the SEALED bytes, so a receiver can verify
// without unsealing and a locked box rejects a substituted part at receive
// rather than discovering it at unlock.
func hashBytes(b []byte) string {
	sum := blake2b.Sum256(b)
	return fmt.Sprintf("%x", sum)
}

// sealedSizeCeiling is the largest a part of plaintextBytes can be once sealed.
//
// The manifest declares PLAINTEXT sizes — it is written before the recipient's
// key exists — so a receiver that offered a key has to allow for the growth or
// it would abort every honest sealed delivery. A Direct part is sealed RAW
// (SealedBox::sealBinary): the growth is only crypto_box_seal's ephemeral public
// key and MAC — no base64, no prefix, because a bulk part carries no DEK text
// wrapping. Must match DirectProtocol::sealedSizeCeiling byte for byte.
func sealedSizeCeiling(plaintextBytes int64) int64 {
	if plaintextBytes < 0 {
		plaintextBytes = 0
	}
	return plaintextBytes + 48 // crypto_box_seal overhead (32-byte ephemeral key + 16-byte MAC)
}

// domainOfAddress is the lowercased domain part of an address, or "".
func domainOfAddress(addr string) string {
	at := strings.LastIndex(addr, "@")
	if at < 0 {
		return ""
	}
	return asciiLower(strings.TrimSpace(addr[at+1:]))
}

// kindOrDefault returns the kind, or "mail" when the envelope names none — a
// blank value counts as none, exactly as DirectProtocol::kindOrDefault, so the
// relay and the box sign AND dispatch an absent-or-empty kind the same way
// instead of one serving as mail what the other refuses.
func kindOrDefault(kind string) string {
	if kind == "" {
		return "mail"
	}
	return kind
}

// asciiLower lowercases ONLY A–Z, matching PHP's strtolower, which is byte-wise
// and ASCII-only. Go's strings.ToLower is full-Unicode: using it on an address or
// domain would produce different signing bytes (and a different decoy, and a
// different capability-lookup key) than the PHP end for anything carrying an
// uppercase non-ASCII letter — a silent, permanent verification failure. Address
// case-folding across the two implementations must be this one, byte-for-byte.
func asciiLower(s string) string {
	var b []byte
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c >= 'A' && c <= 'Z' {
			if b == nil {
				b = []byte(s)
			}
			b[i] = c + 32
		}
	}
	if b == nil {
		return s
	}
	return string(b)
}
