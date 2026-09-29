package main

// GET /relay/seal-target?recipient=<addr>: the relay's signed word on which
// key it seals a recipient's mail to (specs/client_custody_mail.md § R10).
//
// A Fortress mailbox's mail is sealed here to a key only its owner's browsers
// hold. The owner's browser cannot take the server's word for which key that
// is — a hacked server could put its own key in the map it pushes — so it asks
// the relay, through the server, and checks the answer against the relay
// identity it pinned: the statement is signed with the relay's identity key,
// which the server never holds. The server can withhold the answer; it cannot
// forge one.
//
// The statement is canonical JSON in declaration order (sealTargetStatement);
// the signature covers "joinery-relay:seal-target:v1\n" + statement, the
// bytes the browser verifies. It answers from the live routing entry, and only
// for a recipient of the requesting tenant: anyone else's address answers the
// same 404 as an address that does not exist.

import (
	"encoding/json"
	"net/http"
	"strings"
	"time"
)

const relaySealTargetSigningPrefix = "joinery-relay:seal-target:v1\n"

// sealTargetStatement is what the relay signs. FIELD ORDER IS THE CONTRACT.
type sealTargetStatement struct {
	Recipient              string `json:"recipient"`
	PublicKey              string `json:"public_key"`
	KeyKind                string `json:"key_kind"`
	KeyScope               string `json:"key_scope"`
	KeyGeneration          int    `json:"key_generation"`
	MapVersion             int64  `json:"map_version"`
	RelayIdentityPublicKey string `json:"relay_identity_public_key"`
	SignedAt               string `json:"signed_at"`
}

// buildSealTarget resolves a recipient for a tenant and signs the statement.
// ok=false: no such recipient for this tenant, or it stores nothing.
func buildSealTarget(m *routingMap, tenant, recipient string, id *relayIdentity, now time.Time) (string, string, bool) {
	recipient = strings.ToLower(strings.TrimSpace(recipient))
	entry, matched := m.resolve(recipient)
	if !matched || entry.Tenant != tenant || entry.PublicKey == "" ||
		(entry.Mode != modeStore && entry.Mode != modeForwardAndStore) {
		return "", "", false
	}
	st := sealTargetStatement{
		Recipient:              recipient,
		PublicKey:              entry.PublicKey,
		KeyKind:                orDefault(entry.KeyKind, keyKindTransport),
		KeyScope:               entry.KeyScope,
		KeyGeneration:          orInt(entry.KeyGeneration, 1),
		MapVersion:             m.Version,
		RelayIdentityPublicKey: id.publicKeyB64(),
		SignedAt:               now.UTC().Format(time.RFC3339),
	}
	raw, err := json.Marshal(st)
	if err != nil {
		return "", "", false
	}
	statement := string(raw)
	return statement, id.sign([]byte(relaySealTargetSigningPrefix + statement)), true
}

// sealTarget answers {statement, signature}.
func (s *relayServer) sealTarget(w http.ResponseWriter, r *http.Request, tenant string) {
	m, err := loadRoutingMap(s.routingPath)
	if err != nil {
		refuse(w, http.StatusServiceUnavailable, "no routing map yet")
		return
	}
	statement, signature, ok := buildSealTarget(m, tenant, r.URL.Query().Get("recipient"), s.identity, time.Now())
	if !ok {
		refuse(w, http.StatusNotFound, "no such recipient")
		return
	}
	answerJSON(w, http.StatusOK, map[string]any{"statement": statement, "signature": signature})
}
