package main

// Caller identity on the relay API: signed requests, no shared secret.
//
// Every /relay/ request carries a signed envelope (relay_protocol.go). The
// relay resolves the tenant's public key from its registry — one root-owned
// file per tenant, written at birth from the user-data or later by an operator
// through the tenant routes — verifies the signature, refuses a timestamp
// outside the freshness window or a replayed nonce, and then scopes every path
// to that tenant's own directory. The operator's key lives in its own file and
// answers only to the reserved tenant name "operator".
//
// No token is ever minted, transmitted or stored. The registry holds public
// keys; a compromised relay learns nothing that opens a box.
//
// This is a PUBLIC listener, so everything a caller can make the relay remember
// is bounded: the nonce cache is per tenant and capped, the pre-authentication
// rate is per connecting peer, and signature verification is the only work an
// unauthenticated request can drive.

import (
	"encoding/base64"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

const (
	// Signed-request replay protection. The same numbers as Direct's, for the
	// same reason: the nonce TTL outlives the freshness window so the two compose
	// with no gap.
	relayMaxAgeSeconds    = directMaxAgeSeconds
	relayMaxFutureSeconds = directMaxFutureSeconds
	relayNonceTTL         = directNonceTTLSeconds * time.Second

	// Per-tenant request rate, and the nonce cache sized to it: a tenant that
	// stays inside the rate can never have a live nonce evicted, and one that
	// exceeds it is refused before it can grow the cache.
	relayTenantRateLimit  = 3000
	relayTenantRateWindow = 5 * time.Minute
	relayNonceCacheMax    = relayTenantRateLimit * 2

	// Pre-authentication bound per connecting peer. Verification is cheap and
	// touches no network, so this is generous; it exists to bound CPU spent on
	// garbage, not to shape legitimate traffic.
	relayPeerRateLimit  = 6000
	relayPeerRateWindow = time.Minute
)

// authFailure names why a request was refused, for the ping's auth counters.
type authFailure string

const (
	authFailSignature authFailure = "signature"
	authFailReplay    authFailure = "replay"
	authFailStale     authFailure = "stale"
	authFailMalformed authFailure = "malformed"
	authFailUnknown   authFailure = "unknown_tenant"
	authFailRate      authFailure = "rate"
)

// relayAuth verifies signed requests against the tenant registry.
type relayAuth struct {
	relayHome string

	mu     sync.Mutex
	nonces map[string]map[string]time.Time // tenant → nonce → expiry
	// request timestamps per tenant and per peer, for the two limiters.
	tenantHits map[string][]time.Time
	peerHits   map[string][]time.Time
	// failures in the last hour, by tenant slug (the slug an envelope CLAIMED —
	// an unknown or forged one lands under its claimed name, which is what an
	// operator diagnosing a stale key needs to see).
	failures map[string][]authEvent
}

type authEvent struct {
	at   time.Time
	kind authFailure
}

func newRelayAuth(relayHome string) *relayAuth {
	return &relayAuth{
		relayHome:  relayHome,
		nonces:     map[string]map[string]time.Time{},
		tenantHits: map[string][]time.Time{},
		peerHits:   map[string][]time.Time{},
		failures:   map[string][]authEvent{},
	}
}

// publicKeyFor resolves the registry key for a tenant, or "" when the tenant
// is not registered here. A slug that does not match slugRe is never looked up,
// so an envelope cannot name a path.
func (a *relayAuth) publicKeyFor(tenant string) string {
	var path string
	switch {
	case tenant == relayOperatorTenant:
		path = filepath.Join(a.relayHome, "operator_public_key")
	case slugRe.MatchString(tenant):
		path = filepath.Join(a.relayHome, "tenants", tenant, "public_key")
	default:
		return ""
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	key := strings.TrimSpace(string(raw))
	if !validEd25519PublicKey(key) {
		return ""
	}
	return key
}

// tenantRegistered reports whether a tenant directory exists in the registry.
func (a *relayAuth) tenantRegistered(tenant string) bool {
	if !slugRe.MatchString(tenant) {
		return false
	}
	info, err := os.Stat(filepath.Join(a.relayHome, "tenants", tenant))
	return err == nil && info.IsDir()
}

// verify authenticates one request. On success it returns the tenant the
// signature established (the reserved "operator" for the operator key). On
// failure it returns the HTTP status to answer with and a reason; the reason is
// deliberately short and never echoes the request.
//
// body is the whole request body, already read: the signature covers its hash,
// so a route can only trust bytes it has in hand.
func (a *relayAuth) verify(r *http.Request, body []byte) (tenant string, status int, reason string) {
	peer := peerAddress(r)
	if !a.withinRate(a.peerHits, peer, relayPeerRateLimit, relayPeerRateWindow) {
		return "", http.StatusTooManyRequests, "too many requests from this peer"
	}

	signed, err := decodeRelayAuthHeader(r.Header.Get(relayAuthHeader))
	if err != nil {
		a.record("", authFailMalformed)
		return "", http.StatusUnauthorized, "missing or malformed signed envelope"
	}
	env := signed.Envelope
	claimed := env.Tenant

	if env.ProtocolVersion != relayProtocolVersion {
		a.record(claimed, authFailMalformed)
		return "", http.StatusUnauthorized, "unsupported relay protocol version"
	}
	// The envelope must describe THIS request. A signature over a different
	// method, path, query or body is a signature over something else.
	if !strings.EqualFold(strings.TrimSpace(env.Method), r.Method) ||
		env.RequestURI != r.URL.RequestURI() ||
		!strings.EqualFold(strings.TrimSpace(env.BodySHA256), relayBodyHash(body)) {
		a.record(claimed, authFailMalformed)
		return "", http.StatusUnauthorized, "signed envelope does not describe this request"
	}
	if !validRelayNonce(env.Nonce) {
		a.record(claimed, authFailMalformed)
		return "", http.StatusUnauthorized, "malformed nonce"
	}

	publicKey := a.publicKeyFor(claimed)
	if publicKey == "" {
		a.record(claimed, authFailUnknown)
		return "", http.StatusUnauthorized, "unknown tenant"
	}
	if !a.withinRate(a.tenantHits, claimed, relayTenantRateLimit, relayTenantRateWindow) {
		a.record(claimed, authFailRate)
		return "", http.StatusTooManyRequests, "too many requests for this tenant"
	}

	message, err := relayRequestSigningBytes(env)
	if err != nil || !verifyInstanceSignature(message, signed.Signature, publicKey) {
		a.record(claimed, authFailSignature)
		return "", http.StatusUnauthorized, "invalid signature"
	}
	if why := relayFreshnessError(env.Timestamp); why != "" {
		a.record(claimed, authFailStale)
		return "", http.StatusUnauthorized, why
	}
	if !a.claimNonce(claimed, env.Nonce) {
		a.record(claimed, authFailReplay)
		return "", http.StatusUnauthorized, "replayed nonce"
	}
	return claimed, 0, ""
}

// relayFreshnessError is Direct's window applied to a relay envelope.
func relayFreshnessError(timestamp string) string {
	t, err := time.Parse("2006-01-02 15:04:05", strings.TrimSpace(timestamp))
	if err != nil {
		return "unparseable timestamp"
	}
	age := time.Since(t.UTC())
	if age > relayMaxAgeSeconds*time.Second {
		return "stale timestamp"
	}
	if age < -relayMaxFutureSeconds*time.Second {
		return "timestamp in the future"
	}
	return ""
}

// validRelayNonce: 16 bytes, base64. Length-checked after decoding so an
// unpadded or URL-alphabet encoding of the same bytes is accepted alike.
func validRelayNonce(nonce string) bool {
	nonce = strings.TrimSpace(nonce)
	if nonce == "" || len(nonce) > 32 {
		return false
	}
	for _, enc := range []*base64.Encoding{
		base64.StdEncoding, base64.RawStdEncoding,
		base64.URLEncoding, base64.RawURLEncoding,
	} {
		if raw, err := enc.DecodeString(nonce); err == nil && len(raw) == 16 {
			return true
		}
	}
	return false
}

// claimNonce records a nonce for a tenant. False means it was seen already.
// The per-tenant cache is capped; at the cap the oldest entries go first, and
// a tenant inside its rate limit can never reach the cap within a nonce's TTL.
func (a *relayAuth) claimNonce(tenant, nonce string) bool {
	now := time.Now()
	a.mu.Lock()
	defer a.mu.Unlock()
	seen := a.nonces[tenant]
	if seen == nil {
		seen = map[string]time.Time{}
		a.nonces[tenant] = seen
	}
	if expiry, ok := seen[nonce]; ok && now.Before(expiry) {
		return false
	}
	if len(seen) >= relayNonceCacheMax {
		a.evictNonces(seen, now)
	}
	seen[nonce] = now.Add(relayNonceTTL)
	return true
}

// evictNonces drops expired nonces, then — if still at the cap — the oldest
// live ones until a quarter of the cache is free. Caller holds the lock.
func (a *relayAuth) evictNonces(seen map[string]time.Time, now time.Time) {
	for n, expiry := range seen {
		if now.After(expiry) {
			delete(seen, n)
		}
	}
	for len(seen) >= relayNonceCacheMax*3/4 {
		oldestNonce, oldest := "", time.Time{}
		for n, expiry := range seen {
			if oldestNonce == "" || expiry.Before(oldest) {
				oldestNonce, oldest = n, expiry
			}
		}
		if oldestNonce == "" {
			return
		}
		delete(seen, oldestNonce)
	}
}

func (a *relayAuth) withinRate(bucket map[string][]time.Time, key string, limit int, window time.Duration) bool {
	now := time.Now()
	cutoff := now.Add(-window)
	a.mu.Lock()
	defer a.mu.Unlock()
	hits := bucket[key]
	kept := hits[:0]
	for _, t := range hits {
		if t.After(cutoff) {
			kept = append(kept, t)
		}
	}
	if len(kept) >= limit {
		bucket[key] = kept
		return false
	}
	bucket[key] = append(kept, now)
	return true
}

// record notes an authentication failure for the ping's auth group.
func (a *relayAuth) record(tenant string, kind authFailure) {
	if tenant == "" || (!slugRe.MatchString(tenant) && tenant != relayOperatorTenant) {
		tenant = "-"
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	events := append(a.failures[tenant], authEvent{at: time.Now(), kind: kind})
	if len(events) > 10000 {
		events = events[len(events)-10000:]
	}
	a.failures[tenant] = events
}

// failureCounts returns the last hour's failures, by tenant then kind.
func (a *relayAuth) failureCounts() map[string]map[string]int {
	cutoff := time.Now().Add(-time.Hour)
	out := map[string]map[string]int{}
	a.mu.Lock()
	defer a.mu.Unlock()
	for tenant, events := range a.failures {
		for _, e := range events {
			if e.at.Before(cutoff) {
				continue
			}
			if out[tenant] == nil {
				out[tenant] = map[string]int{}
			}
			out[tenant][string(e.kind)]++
		}
	}
	return out
}

// sweep drops aged state so a long-lived process does not grow without bound.
func (a *relayAuth) sweep() {
	now := time.Now()
	a.mu.Lock()
	defer a.mu.Unlock()
	for tenant, seen := range a.nonces {
		for n, expiry := range seen {
			if now.After(expiry) {
				delete(seen, n)
			}
		}
		if len(seen) == 0 {
			delete(a.nonces, tenant)
		}
	}
	for _, bucket := range []map[string][]time.Time{a.tenantHits, a.peerHits} {
		for key, hits := range bucket {
			if len(hits) == 0 || now.Sub(hits[len(hits)-1]) > time.Hour {
				delete(bucket, key)
			}
		}
	}
	cutoff := now.Add(-time.Hour)
	for tenant, events := range a.failures {
		kept := events[:0]
		for _, e := range events {
			if e.at.After(cutoff) {
				kept = append(kept, e)
			}
		}
		if len(kept) == 0 {
			delete(a.failures, tenant)
		} else {
			a.failures[tenant] = kept
		}
	}
}
