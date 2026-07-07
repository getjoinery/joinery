// Command relay-sealer is the Postfix pipe transport on the hardened ingest
// relay. It replaces utils/inbound_email_handler.php on the MX path: instead of
// parsing and storing mail (which needs a database and a large code surface),
// it seals each accepted message to the recipient's public key at the moment of
// acceptance and spools ciphertext for the main Joinery box to pull over
// WireGuard.
//
// Invocation (Postfix master.cf pipe, flags=DRh — no 'u': the local part's
// case must survive for SRS bounce validation):
//
//	relay-sealer ${recipient} ${sender}
//
// Raw RFC822 arrives on stdin; the envelope recipient is argv[1] and the
// envelope sender argv[2]. It never buffers plaintext to disk — the message is
// held in memory, sealed, and only the ciphertext (+ a cleartext operational
// metadata sidecar) is written, via write-tempfile → fsync → atomic rename.
// The process returns its Postfix exit code only AFTER the fsync.
//
// Exit codes follow Postfix pipe conventions:
//
//	0  = delivered / accepted (sealed + spooled, or forwarded, or silently discarded)
//	67 = unknown user (permanent rejection)
//	75 = temporary failure (Postfix will retry) — used whenever mail would
//	     otherwise be lost (missing key, spool write failure)
package main

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"
	"time"
)

const (
	exitOK        = 0
	exitUnknown   = 67
	exitTempFail  = 75
	maxMessageMiB = 25 // mirror InboundEmailRouter's 25 MiB size cap
)

func main() {
	os.Exit(run())
}

func run() int {
	// Local-part case is preserved (master.cf uses flags=DRh, not DRhu): SRS
	// bounce addresses encode a case-sensitive hash, so lowercasing here would
	// make every bounce fail validation on the main box. Routing-map lookups
	// lowercase internally (resolve/domainOf).
	recipient := ""
	if len(os.Args) > 1 {
		recipient = strings.TrimSpace(os.Args[1])
	}
	sender := ""
	if len(os.Args) > 2 {
		sender = strings.TrimSpace(os.Args[2])
	}
	if recipient == "" {
		fmt.Fprintln(os.Stderr, "relay-sealer: no envelope recipient (argv[1])")
		return exitUnknown
	}

	routingPath := envOr("JOINERY_RELAY_ROUTING", "/opt/joinery-relay/routing.json")
	spoolDir := envOr("JOINERY_RELAY_SPOOL", "/var/spool/joinery-relay")

	raw, err := io.ReadAll(io.LimitReader(os.Stdin, int64(maxMessageMiB)*1024*1024+1))
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer: read stdin: %v\n", err)
		return exitTempFail
	}
	if len(raw) == 0 {
		fmt.Fprintln(os.Stderr, "relay-sealer: empty message")
		return exitTempFail
	}
	if len(raw) > maxMessageMiB*1024*1024 {
		// Over the cap: accept and drop, matching the app's "return 0, do not
		// bounce" behaviour for oversize mail.
		fmt.Fprintln(os.Stderr, "relay-sealer: message over size cap, discarding")
		return exitOK
	}

	m, err := loadRoutingMap(routingPath)
	if err != nil {
		// No map = cannot make a safe routing decision. Temp-fail so the
		// sender's MTA retries rather than losing mail.
		fmt.Fprintf(os.Stderr, "relay-sealer: %v\n", err)
		return exitTempFail
	}

	entry, matched := m.resolve(recipient)
	if !matched {
		if m.rejectUnmatched(recipient) {
			return exitUnknown
		}
		return exitOK // domain accepts-and-discards unmatched mail
	}

	stores := entry.Mode == modeStore || entry.Mode == modeForwardAndStore
	forwards := entry.Mode == modeForward || entry.Mode == modeForwardAndStore

	if stores {
		if code := sealAndSpool(raw, recipient, sender, entry, m, spoolDir); code != exitOK {
			return code
		}
	}

	if forwards {
		if len(entry.Destinations) == 0 {
			fmt.Fprintln(os.Stderr, "relay-sealer: forward mode with no destinations")
			// Nothing to forward to; if we also stored, the mail is safe.
			if stores {
				return exitOK
			}
			return exitUnknown
		}
		if err := forwardMessage(raw, recipient, entry, m); err != nil {
			fmt.Fprintf(os.Stderr, "relay-sealer: %v\n", err)
			// Forwarding failed. If we also sealed a copy, the mail is not lost;
			// accept. Otherwise temp-fail so the sender retries.
			if stores {
				return exitOK
			}
			return exitTempFail
		}
	}

	return exitOK
}

// sealAndSpool seals the raw message to the recipient's public key and commits
// the .seal + .meta pair durably. Any failure returns a temp-fail so Postfix
// retries — a message that cannot be sealed must never be silently lost.
func sealAndSpool(raw []byte, recipient, sender string, entry routingEntry, m *routingMap, spoolDir string) int {
	if entry.PublicKey == "" {
		fmt.Fprintln(os.Stderr, "relay-sealer: store mode but no public key for recipient")
		return exitTempFail
	}

	sealed, err := sealToPublicKey(raw, entry.PublicKey)
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer: seal failed: %v\n", err)
		return exitTempFail
	}

	spoolID := newSpoolID()
	headers, authResults := extractMeta(raw)
	meta := spoolMeta{
		SpoolID:               spoolID,
		Recipient:             recipient,
		EnvelopeSender:        sender,
		MessageID:             headers["message-id"],
		InReplyTo:             headers["in-reply-to"],
		References:            headers["references"],
		Date:                  headers["date"],
		Size:                  len(raw),
		AuthenticationResults: authResults,
		KeyKind:               orDefault(entry.KeyKind, keyKindTransport),
		PublicKey:             entry.PublicKey,
		MapVersion:            m.Version,
		ReceivedUTC:           time.Now().UTC().Format(time.RFC3339),
	}
	metaBytes, err := meta.marshal()
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer: marshal meta: %v\n", err)
		return exitTempFail
	}

	if err := writeSpoolEntry(spoolDir, spoolID, metaBytes, sealed); err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer: spool: %v\n", err)
		return exitTempFail
	}
	return exitOK
}

// newSpoolID is a lexically-sortable, collision-resistant spool id: a
// zero-padded unix-nano prefix (so a directory listing is roughly arrival
// order) plus random hex.
func newSpoolID() string {
	var b [8]byte
	_, _ = rand.Read(b[:])
	return strconv.FormatInt(time.Now().UnixNano(), 10) + "-" + hex.EncodeToString(b[:])
}

func envOr(name, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(name)); v != "" {
		return v
	}
	return fallback
}

func orDefault(v, fallback string) string {
	if v == "" {
		return fallback
	}
	return v
}
