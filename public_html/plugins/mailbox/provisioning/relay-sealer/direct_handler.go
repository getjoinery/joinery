package main

// The Joinery Direct endpoint, served by the relay on behalf of its tenants.
//
// At Fortress the relay IS the endpoint, in both directions: publishing an SRV
// record pointing at the origin box would advertise in public DNS precisely the
// address the relay exists to conceal. That splits the gate along the line it
// was already split across two moments — THE RELAY AUTHENTICATES, THE BOX
// AUTHORIZES. Signature verification is stateless crypto needing no vault, so
// the relay does it at the edge and drops forged senders before they ever reach
// the box; the contact gate needs the sealed contact list, so it runs on the box
// at unlock.
//
// Everything the relay refuses is refused the way the box would refuse it, and
// for the same reason: a receiving endpoint is only as oracle-free as its
// leakiest half. So there are exactly two gate answers, request-level refusals
// live in their own indistinguishable bucket, an address that does not exist is
// answered with a decoy key rather than a denial, and nothing is ever bounced.
//
// The relay stays KIND-BLIND. It compares the envelope's kind against the
// tenant's served-kind list — an opaque string it never interprets — so a new
// kind, core or plugin, reaches the fleet as a relay-map update and never as a
// relay release.

import (
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"
)

const (
	// A preflight body is envelope + manifest; nothing about it is large.
	maxPreflightBody = 1 << 20
	// The floor for a per-part read when a tenant has published no cap.
	defaultMaxPartBytes = 100 << 20
)

type directHandler struct {
	routingPath  string
	defaultSpool string
	state        *directState
	capabilities *capabilityCache
}

func newDirectHandler(routingPath, defaultSpool, nonceDir string) *directHandler {
	return &directHandler{
		routingPath:  routingPath,
		defaultSpool: defaultSpool,
		state:        newDirectState(nonceDir, 15*time.Minute),
		capabilities: newCapabilityCache(),
	}
}

// ServeHTTP is the whole public surface: one path, one method, three steps.
func (h *directHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != directEndpointPath {
		// Everything else on this listener is a plain not-found. The relay is
		// not a web server and must not look like one.
		http.NotFound(w, r)
		return
	}
	if r.Method != http.MethodPost {
		answerJSON(w, http.StatusMethodNotAllowed, map[string]any{"error": "Method Not Allowed"})
		return
	}

	switch r.URL.Query().Get("step") {
	case "preflight":
		h.handlePreflight(w, r)
	case "part":
		h.handlePart(w, r)
	case "commit":
		h.handleCommit(w, r)
	default:
		answerJSON(w, http.StatusBadRequest, map[string]any{"error": "Unknown step"})
	}
}

func (h *directHandler) handlePreflight(w http.ResponseWriter, r *http.Request) {
	var req directPreflight
	if err := json.NewDecoder(io.LimitReader(r.Body, maxPreflightBody)).Decode(&req); err != nil {
		refuse(w, http.StatusBadRequest, "Malformed preflight")
		return
	}

	env := req.Envelope
	// 1. Protocol version, before anything reads a field whose meaning it defines.
	if env.ProtocolVersion != directProtocolVersion {
		refuse(w, http.StatusBadRequest, "Unsupported protocol version")
		return
	}
	env.Sender = asciiLower(strings.TrimSpace(env.Sender))
	env.Recipient = asciiLower(strings.TrimSpace(env.Recipient))
	senderDomain := domainOfAddress(env.Sender)
	if senderDomain == "" || env.Recipient == "" || env.Nonce == "" {
		refuse(w, http.StatusBadRequest, "Malformed envelope")
		return
	}

	m, err := loadRoutingMap(h.routingPath)
	if err != nil {
		// No map = no safe decision. Temp-fail rather than guess.
		refuse(w, http.StatusServiceUnavailable, "Routing unavailable")
		return
	}

	// Which domain the recipient names — a local computation, no lookup yet.
	// WHETHER this relay fronts it is deliberately NOT decided here: answering
	// that before the signature would let an unauthenticated peer enumerate the
	// tenants this relay fronts, which is the very address concealment a Fortress
	// relay exists to provide. The check moves below, after authentication.
	recipientDomain := domainOfAddress(env.Recipient)

	// 3. Peer-keyed limit FIRST: the sender domain is attacker-chosen and
	//    resolving it is outbound DNS driven by unauthenticated input.
	peer := peerAddress(r)
	if !h.state.withinPeerRate(peer, 60, time.Minute) {
		refuse(w, http.StatusTooManyRequests, "Too many lookups from this peer")
		return
	}

	// 4. Verify the instance signature against the SENDER domain's published key.
	//    Stateless — no vault — which is what lets the relay do it at the edge.
	publicKey := h.capabilities.publicKeyFor(r.Context(), senderDomain, env.KeyID)
	if publicKey == "" {
		refuse(w, http.StatusForbidden, "No capability record or key id for the sending domain")
		return
	}
	signed, err := preflightSigningBytes(env, req.Manifest)
	if err != nil || !verifyInstanceSignature(signed, req.Signature, publicKey) {
		refuse(w, http.StatusForbidden, "Invalid instance signature")
		return
	}

	// Which tenant owns the recipient's domain, and does it serve Direct at all?
	// "We do not front this domain" is a fact about the deployment, so it is
	// request-level — but only NOW, once the peer is a signature-authenticated
	// Joinery instance. A legitimate sender delivering here learns the relay
	// fronts the domain anyway; an unauthenticated prober never gets this far, so
	// it cannot enumerate the fronted tenants a 404-before-auth would have leaked.
	tenantSlug, tc, ok := m.tenantForDomain(recipientDomain)
	if !ok || !tc.DirectEnabled {
		refuse(w, http.StatusNotFound, "Recipient domain is not served here")
		return
	}

	// 5. Per-instance rate limit, on the identity the signature established.
	window := time.Duration(orInt(tc.DirectPreflightWindow, 120)) * time.Second
	if !h.state.withinInstanceRate(senderDomain, orInt(tc.DirectPreflightLimit, 120), window) {
		refuse(w, http.StatusTooManyRequests, "Too many preflights from this instance")
		return
	}

	// 6. Freshness and replay, both inside what the signature covers.
	if why := freshnessError(env.Timestamp); why != "" {
		refuse(w, http.StatusBadRequest, why)
		return
	}
	if !h.state.claimNonce(env.Nonce) {
		refuse(w, http.StatusConflict, "Replayed nonce")
		return
	}

	// 7. Manifest bounds. Instance configuration, applied identically to every
	//    recipient and every kind, so refusing on them discloses nothing.
	declared, why := manifestBoundsError(req.Manifest, tc)
	if why != "" {
		refuse(w, http.StatusRequestEntityTooLarge, why)
		return
	}

	// 8. Kind dispatch. An unserved kind is refused at the EDGE, without
	//    touching the origin box — the relay compares an opaque string.
	kind := kindOrDefault(strings.ToLower(strings.TrimSpace(env.Kind)))
	if !tc.servesKind(kind) {
		refuse(w, http.StatusNotFound, "Kind not served here")
		return
	}

	// 9. Spool caps, in bytes. Accept-before-judgment means a flood's only real
	//    spend is disk held until the recipient unlocks, so that is what gets
	//    bounded — and a decoy delivery is charged too, so a full spool refuses
	//    identically whether the address exists or not.
	spoolDir := tc.SpoolDir
	if spoolDir == "" {
		spoolDir = h.defaultSpool
	}
	if why := directSpoolCapRefusal(spoolDir, env.Recipient, recipientDomain, declared, tc); why != "" {
		refuse(w, http.StatusInsufficientStorage, why)
		return
	}

	// 10. The key answer. A real recipient's vault key when the map has one; a
	//     keyless accept when the recipient is real but holds no vault key; a
	//     decoy only when the address does not exist. The gate itself is NOT run
	//     here and cannot be: the contact list is sealed and lives on the box.
	entry, matched := m.resolve(env.Recipient)
	key, generation, isDecoy := "", 0, false
	switch {
	case matched && entry.KeyKind == keyKindUser && entry.PublicKey != "":
		key = entry.PublicKey
		generation = orInt(entry.KeyGeneration, 1)
	case matched && entry.PublicKey != "":
		// A tenant whose mail seals to the ambient transport key rather than a
		// user vault. Still a real recipient, still sealable.
		key = entry.PublicKey
		generation = orInt(entry.KeyGeneration, 1)
	case matched:
		// A REAL recipient with no discoverable vault key — a group alias with no
		// single vault, or a vaultless owner (the box's own resolver reaches the
		// same case in A2). Accept KEYLESS: the parts cross unsealed and the box
		// gates at commit against its plaintext book. Handing a decoy here — the
		// old default — would seal real mail to a key nobody holds and destroy it.
		// The residual is a field-presence existence tell, the identical tradeoff
		// the box accepts for the identical reason; the PHP receiver and this relay
		// must not diverge on it, or the same address answers two different ways.
		key, generation, isDecoy = "", 0, false
	default:
		key = decoyPublicKey(tc.DirectDecoySecret, env.Recipient)
		generation = decoyGeneration
		isDecoy = true
	}

	ttl := orInt(tc.DirectSessionTTLSeconds, 900)
	h.state.openSession(&directSession{
		Nonce:         env.Nonce,
		Kind:          kind,
		Sender:        env.Sender,
		SenderDomain:  senderDomain,
		SenderKeyID:   env.KeyID,
		Recipient:     env.Recipient,
		Tenant:        tenantSlug,
		Manifest:      canonicalManifest(req.Manifest),
		DeclaredBytes: declared,
		KeyGeneration: generation,
		IsDecoy:       isDecoy,
		// Kept for the box to re-verify — the relay does not vouch, it forwards.
		Timestamp:          env.Timestamp,
		PreflightSignature: req.Signature,
	}, time.Duration(ttl)*time.Second)

	answer := map[string]any{"answer": directAnswerAccept, "session_ttl": ttl}
	if key != "" {
		answer["key"] = key
		answer["key_generation"] = generation
	}
	answerJSON(w, http.StatusOK, answer)
}

func (h *directHandler) handlePart(w http.ResponseWriter, r *http.Request) {
	nonce := r.URL.Query().Get("nonce")
	index, err := strconv.Atoi(r.URL.Query().Get("index"))
	if err != nil || index < 0 {
		refuse(w, http.StatusConflict, "Part refused")
		return
	}
	sess := h.state.liveSession(nonce)
	if sess == nil || index >= len(sess.Manifest) {
		refuse(w, http.StatusConflict, "Part refused")
		return
	}

	// The admitted manifest is the transfer-time contract. It declares PLAINTEXT
	// sizes — it was written before the recipient's key existed — so when a key
	// was offered the ceiling is the sealed size of that declaration. Without
	// that allowance every honest sealed delivery would be aborted for arriving
	// exactly as it was asked to.
	ceiling := sess.Manifest[index].Size
	if sess.KeyGeneration > 0 {
		ceiling = sealedSizeCeiling(ceiling)
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, ceiling+1))
	if err != nil || int64(len(body)) > ceiling {
		h.state.burn(nonce)
		refuse(w, http.StatusConflict, "Part refused")
		return
	}

	sess.mu.Lock()
	sess.parts[index] = body
	sess.mu.Unlock()
	answerJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (h *directHandler) handleCommit(w http.ResponseWriter, r *http.Request) {
	var req directCommit
	if err := json.NewDecoder(io.LimitReader(r.Body, maxPreflightBody)).Decode(&req); err != nil {
		refuse(w, http.StatusBadRequest, "Malformed commit")
		return
	}

	// Redeeming is the claim: a captured transfer replayed against a consumed,
	// expired or unknown session is refused here, which is what closes
	// content-transfer replay the way the nonce cache closes preflight replay.
	sess := h.state.redeem(req.Nonce)
	if sess == nil {
		refuse(w, http.StatusConflict, "Commit refused")
		return
	}

	publicKey := h.capabilities.publicKeyFor(r.Context(), sess.SenderDomain, sess.SenderKeyID)
	signed, err := transferSigningBytes(req.Nonce, req.Hashes)
	if publicKey == "" || err != nil || !verifyInstanceSignature(signed, req.Signature, publicKey) {
		refuse(w, http.StatusConflict, "Commit refused")
		return
	}
	if len(req.Hashes) != len(sess.Manifest) {
		refuse(w, http.StatusConflict, "Commit refused")
		return
	}

	// Every part, every hash. A mismatch rejects the ENTIRE delivery: the parts
	// arrive under an anonymous seal anyone holding the recipient's public key
	// could construct, so without this an in-path element could substitute
	// wholesale and the box would decrypt attacker-chosen bytes cleanly and then
	// stamp them verified. Compared for ALL parts rather than short-circuiting,
	// so the receiver never behaves differently for one part than another.
	sess.mu.Lock()
	ok := true
	ordered := make([][]byte, len(sess.Manifest))
	for i := range sess.Manifest {
		part, present := sess.parts[i]
		if !present || hashBytes(part) != req.Hashes[i] {
			ok = false
			continue
		}
		ordered[i] = part
	}
	sess.mu.Unlock()
	if !ok {
		refuse(w, http.StatusConflict, "Commit refused")
		return
	}

	// A decoy delivery is discarded here: nobody could ever open it, and the
	// bytes it declared were already charged against the address cap, which is
	// what makes a full spool refuse identically for a real and a made-up
	// address. The sender is told nothing — it was answered accept, and it was
	// delivered, into nowhere.
	if sess.IsDecoy {
		answerJSON(w, http.StatusOK, map[string]any{"ok": true})
		return
	}

	m, err := loadRoutingMap(h.routingPath)
	if err != nil {
		refuse(w, http.StatusServiceUnavailable, "Routing unavailable")
		return
	}
	tc, ok := m.Tenants[sess.Tenant]
	if !ok {
		refuse(w, http.StatusServiceUnavailable, "Routing unavailable")
		return
	}
	spoolDir := tc.SpoolDir
	if spoolDir == "" {
		spoolDir = h.defaultSpool
	}

	if err := writeDirectSpoolEntry(spoolDir, sess, ordered, req.Sealed, req.KeyGeneration, m.Version, req.Signature); err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer: direct spool write failed: %v\n", err)
		refuse(w, http.StatusServiceUnavailable, "Temporary failure")
		return
	}
	answerJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// ---------------------------------------------------------------------------

// freshnessError returns "" when the signed timestamp is inside the window.
func freshnessError(timestamp string) string {
	t, err := time.Parse("2006-01-02 15:04:05", strings.TrimSpace(timestamp))
	if err != nil {
		return "Unparseable timestamp"
	}
	age := time.Since(t.UTC())
	if age > directMaxAgeSeconds*time.Second {
		return "Stale envelope"
	}
	if age < -directMaxFutureSeconds*time.Second {
		return "Envelope timestamped in the future"
	}
	return ""
}

// manifestBoundsError checks the declared manifest against the tenant's caps
// and returns the declared total.
func manifestBoundsError(manifest []directManifestEntry, tc tenantConfig) (int64, string) {
	if len(manifest) == 0 {
		return 0, "Empty manifest"
	}
	if max := orInt(tc.DirectMaxParts, 64); len(manifest) > max {
		return 0, "Too many parts"
	}
	perPart := orInt64(tc.DirectMaxPartBytes, defaultMaxPartBytes)
	total := int64(0)
	for _, e := range manifest {
		if e.Size < 0 || e.Size > perPart {
			return 0, "Part exceeds the per-part byte cap"
		}
		total += e.Size
	}
	if max := orInt64(tc.DirectMaxTotalBytes, 250<<20); total > max {
		return 0, "Message exceeds the total byte cap"
	}
	return total, ""
}

// peerAddress is the connecting IP, which is the only identity available before
// the signature is verified.
func peerAddress(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}

// refuse is the request-level bucket: an HTTP status, never one of the gate's
// two answers, and deliberately uninformative — a refusal from the recipient, a
// WAF, a proxy and a dead host all mean the same thing to a correct client.
func refuse(w http.ResponseWriter, status int, reason string) {
	answerJSON(w, status, map[string]any{"error": reason})
}

func answerJSON(w http.ResponseWriter, status int, body map[string]any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func orInt(v, fallback int) int {
	if v <= 0 {
		return fallback
	}
	return v
}

func orInt64(v, fallback int64) int64 {
	if v <= 0 {
		return fallback
	}
	return v
}
