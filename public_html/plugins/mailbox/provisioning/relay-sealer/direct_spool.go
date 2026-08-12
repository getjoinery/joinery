package main

// How a verified Direct delivery reaches the origin box.
//
// It rides the spool the tenant already pulls. Mail arrives as a `.seal` blob
// plus a cleartext `.meta` sidecar; a Direct delivery arrives as a `.direct`
// container plus the same kind of sidecar, marked with its kind so the pull
// consumer hands it to the Direct framework instead of the mail router. Reusing
// the spool means no new transport, no new credential and no new listener on
// the box — the delivery travels the WireGuard channel that already exists.
//
// The container is a self-describing envelope plus the parts EXACTLY as they
// arrived: sealed by the SENDER to the recipient's vault key, so the relay
// forwards a blob it cannot read. That is the whole point of sender-side
// sealing — the relay stops being a component that must be trusted with content
// and becomes a pure address-hiding forwarder.

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// directSpoolPart is one delivered part inside the container.
type directSpoolPart struct {
	Sequence    int    `json:"sequence"`
	Role        string `json:"role"`
	ContentType string `json:"content_type"`
	Filename    string `json:"filename"`
	ContentID   string `json:"content_id"`
	IsInline    bool   `json:"is_inline"`
	Bytes       int64  `json:"bytes"`
	Hash        string `json:"hash"`
	// Base64 of the delivered bytes. The container is JSON so the box can read
	// it with no new parser; the inflation is confined to this hop and never
	// touches the wire, where parts transfer as raw bytes.
	Content string `json:"content"`
}

// directSpoolEnvelope is the whole container.
type directSpoolEnvelope struct {
	SpoolID         string `json:"spool_id"`
	Kind            string `json:"kind"`
	ProtocolVersion int    `json:"protocol_version"`
	Sender          string `json:"sender"`
	// The domain the relay verified the signature against. Retained for diagnostics
	// only — the box does NOT trust it: it re-derives the sender domain from the
	// signed envelope and re-verifies against that domain's own key (see below).
	VerifiedSenderDomain string            `json:"verified_sender_domain"`
	Recipient            string            `json:"recipient"`
	Nonce                string            `json:"nonce"`
	Sealed               bool              `json:"sealed"`
	KeyGeneration        int               `json:"key_generation"`
	Parts                []directSpoolPart `json:"parts"`
	MapVersion           int64             `json:"map_version"`
	ReceivedUTC          string            `json:"received_utc"`

	// The sender's OWN proof, forwarded verbatim so the origin box can independently
	// re-authenticate the delivery. The relay verified these at its edge but is
	// untrusted with content, so its verdict is not load-bearing downstream: the box
	// looks up the sender domain's DNS-published key and re-checks both signatures
	// itself. KeyID + Timestamp + SignedManifest are exactly the fields the preflight
	// signature covers; TransferSignature covers the nonce and the ordered part hashes.
	KeyID              string                `json:"key_id"`
	Timestamp          string                `json:"timestamp"`
	SignedManifest     []directManifestEntry `json:"signed_manifest"`
	PreflightSignature string                `json:"preflight_signature"`
	TransferSignature  string                `json:"transfer_signature"`
}

// directSpoolMeta is the cleartext sidecar, in the same shape mail's sidecar
// uses so the pull consumer can read one listing. It carries operational
// metadata ONLY — never a subject or a body, neither of which the relay has or
// could have.
type directSpoolMeta struct {
	SpoolID     string `json:"spool_id"`
	Kind        string `json:"kind"`
	Artifact    string `json:"artifact"`
	Recipient   string `json:"recipient"`
	Sender      string `json:"envelope_sender"`
	Size        int    `json:"size"`
	KeyKind     string `json:"key_kind"`
	MapVersion  int64  `json:"map_version"`
	ReceivedUTC string `json:"received_utc"`
}

// writeDirectSpoolEntry commits the container and its sidecar durably.
//
// Ordering mirrors the mail path: the .meta is committed FIRST and the
// artifact second, because the pull consumer treats the artifact as the commit
// marker — so by the time a .direct is visible its .meta is guaranteed present.
func writeDirectSpoolEntry(spoolDir string, sess *directSession, parts [][]byte,
	sealed bool, keyGeneration int, mapVersion int64, transferSignature string) error {

	spoolID := newSpoolID()
	container := directSpoolEnvelope{
		SpoolID:              spoolID,
		Kind:                 sess.Kind,
		ProtocolVersion:      directProtocolVersion,
		Sender:               sess.Sender,
		VerifiedSenderDomain: sess.SenderDomain,
		Recipient:            sess.Recipient,
		Nonce:                sess.Nonce,
		Sealed:               sealed,
		KeyGeneration:        keyGeneration,
		MapVersion:           mapVersion,
		ReceivedUTC:          time.Now().UTC().Format(time.RFC3339),
		// The sender's proof, forwarded for the box to re-verify.
		KeyID:              sess.SenderKeyID,
		Timestamp:          sess.Timestamp,
		SignedManifest:     sess.Manifest,
		PreflightSignature: sess.PreflightSignature,
		TransferSignature:  transferSignature,
	}
	total := 0
	for i, entry := range sess.Manifest {
		raw := parts[i]
		total += len(raw)
		container.Parts = append(container.Parts, directSpoolPart{
			Sequence:    i,
			Role:        entry.Role,
			ContentType: entry.ContentType,
			Filename:    entry.Filename,
			ContentID:   entry.ContentID,
			IsInline:    entry.IsInline,
			Bytes:       int64(len(raw)),
			Hash:        hashBytes(raw),
			Content:     base64.StdEncoding.EncodeToString(raw),
		})
	}

	body, err := json.Marshal(container)
	if err != nil {
		return fmt.Errorf("marshal direct container: %w", err)
	}

	keyKind := keyKindTransport
	if sealed {
		keyKind = keyKindUser
	}
	meta, err := json.Marshal(directSpoolMeta{
		SpoolID:     spoolID,
		Kind:        sess.Kind,
		Artifact:    "direct",
		Recipient:   sess.Recipient,
		Sender:      sess.Sender,
		Size:        total,
		KeyKind:     keyKind,
		MapVersion:  mapVersion,
		ReceivedUTC: container.ReceivedUTC,
	})
	if err != nil {
		return fmt.Errorf("marshal direct meta: %w", err)
	}

	tmpDir := filepath.Join(spoolDir, "tmp")
	if err := os.MkdirAll(tmpDir, 0o700); err != nil {
		return fmt.Errorf("create spool tmp dir: %w", err)
	}
	metaFinal := filepath.Join(spoolDir, spoolID+".meta")
	dataFinal := filepath.Join(spoolDir, spoolID+".direct")

	if err := writeDurable(tmpDir, metaFinal, meta); err != nil {
		return fmt.Errorf("write .meta: %w", err)
	}
	if err := writeDurable(tmpDir, dataFinal, body); err != nil {
		_ = os.Remove(metaFinal)
		return fmt.Errorf("write .direct: %w", err)
	}
	return nil
}

// directSpoolCapRefusal bounds what all senders together can park before a
// human sees it — in BYTES, because counts alone do not bound storage.
//
// Two layers: a per-domain cap on the whole spool, and a per-address cap
// beneath it so one flooded recipient cannot consume the domain's budget. Both
// are absolute recipient-side bounds, so no number of cheap sending domains
// raises the ceiling the way Sybil multiplies a per-instance rate limit. A cap
// refusal costs a legitimate sender only the downgrade — for mail, SMTP, which
// under Fortress is the edge-sealing ingest relay, so the message still
// arrives.
func directSpoolCapRefusal(spoolDir, recipient, domain string, declared int64, tc tenantConfig) string {
	domainCap := tc.DirectSpoolDomainCap
	addressCap := tc.DirectSpoolAddressCap
	if domainCap <= 0 && addressCap <= 0 {
		return ""
	}

	byDomain, byAddress := directSpoolBytes(spoolDir, recipient, domain)
	if domainCap > 0 && byDomain+declared > domainCap {
		return "Direct spool is full for this domain"
	}
	if addressCap > 0 && byAddress+declared > addressCap {
		return "Direct spool is full for this address"
	}
	return ""
}

// directSpoolBytes sums what is already held, for the domain and for one
// address within it. The spool IS the storage being capped, so it is measured
// rather than tracked — a counter that drifted from the disk would cap the
// wrong thing.
func directSpoolBytes(spoolDir, recipient, domain string) (int64, int64) {
	entries, err := os.ReadDir(spoolDir)
	if err != nil {
		return 0, 0
	}
	recipient = strings.ToLower(recipient)
	domain = strings.ToLower(domain)

	var byDomain, byAddress int64
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".meta") {
			continue
		}
		raw, err := os.ReadFile(filepath.Join(spoolDir, e.Name()))
		if err != nil {
			continue
		}
		var meta directSpoolMeta
		if json.Unmarshal(raw, &meta) != nil || meta.Artifact != "direct" {
			continue
		}
		addr := strings.ToLower(meta.Recipient)
		if domainOfAddress(addr) != domain {
			continue
		}
		byDomain += int64(meta.Size)
		if addr == recipient {
			byAddress += int64(meta.Size)
		}
	}
	return byDomain, byAddress
}
