package main

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
)

// Delivery modes mirror InboundEmailAlias::MODE_* and the catch-all modes on
// InboundEmailDomain. The relay only needs to know: does this recipient's mail
// get sealed-and-spooled, forwarded, or both.
const (
	modeStore           = "store"
	modeForward         = "forward"
	modeForwardAndStore = "forward_and_store"
)

// keyKind tells the pull consumer whether the blob was sealed to a single
// user's vault key (Fortress — open only in-session, store pending-parse) or to
// the ambient transport key Joinery holds (Standard/Private — open at pull and
// run today's ingest). The sealer copies it verbatim into the .meta sidecar.
const (
	keyKindUser      = "user"
	keyKindTransport = "transport"
)

// routingEntry is the per-recipient (or synthesized catch-all) delivery record.
type routingEntry struct {
	PublicKey        string   `json:"public_key"`
	KeyKind          string   `json:"key_kind"`
	Mode             string   `json:"mode"`
	Destinations     []string `json:"destinations"`
	ForwardingDomain string   `json:"forwarding_domain"`
	// The site's verified From address to rewrite forwarded mail's From header to
	// (deliverability — the original sender's domain DMARC must not judge us). Same
	// address InboundEmailRouter::buildForwardMessage rewrites to (defaultemail).
	ForwardFrom string `json:"forward_from"`
}

// domainEntry captures the domain-level catch-all posture, used when no exact
// recipient match exists (mirrors InboundEmailRouter's catch-all branch).
type domainEntry struct {
	CatchAllMode     string `json:"catch_all_mode"` // store | forward | none
	CatchAllAddress  string `json:"catch_all_address"`
	RejectUnmatched  bool   `json:"reject_unmatched"`
	PublicKey        string `json:"public_key"`
	KeyKind          string `json:"key_kind"`
	ForwardingDomain string `json:"forwarding_domain"`
	ForwardFrom      string `json:"forward_from"`
}

// routingMap is the DB-free routing table synced from the main Joinery box.
// It is the sealer's ONLY source of recipient public keys and routing — the
// relay holds no database connection.
type routingMap struct {
	Version   int64                   `json:"version"`
	Generated string                  `json:"generated_utc"`
	SRSSecret string                  `json:"srs_secret"`
	// Display-name construction for the rewritten forward From header, mirroring
	// InboundEmailRouter::forwardedFromDisplay (defaultemailname + the
	// mailbox_from_show_via flag).
	ForwardFromName string `json:"forward_from_name"`
	ForwardShowVia  bool   `json:"forward_show_via"`
	// The ambient transport public key (Standard/Private + SRS-bounce sealing) and
	// the set of forwarding domains, so an SRS bounce returning to a forwarding
	// domain is accepted, sealed to transport, and spooled for the pull consumer to
	// decode into an NDR (specs/mailbox_relay_fix_pack.md § Fix 6).
	TransportPublicKey string                  `json:"transport_public_key"`
	ForwardingDomains  []string                `json:"forwarding_domains"`
	Recipients         map[string]routingEntry `json:"recipients"`
	Domains            map[string]domainEntry  `json:"domains"`
}

func loadRoutingMap(path string) (*routingMap, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read routing map %s: %w", path, err)
	}
	var m routingMap
	if err := json.Unmarshal(data, &m); err != nil {
		return nil, fmt.Errorf("parse routing map %s: %w", path, err)
	}
	return &m, nil
}

// resolve returns the delivery record for a recipient, applying the same
// precedence InboundEmailRouter uses: exact alias first, then the domain
// catch-all. It returns (entry, matched=true) when the message should be
// handled, or matched=false when there is nothing to deliver to (the caller
// decides reject vs. silent discard from RejectUnmatched).
func (m *routingMap) resolve(recipient string) (routingEntry, bool) {
	recipient = strings.ToLower(strings.TrimSpace(recipient))
	if entry, ok := m.Recipients[recipient]; ok {
		if entry.ForwardingDomain == "" {
			entry.ForwardingDomain = domainOf(recipient)
		}
		return entry, true
	}

	dom := domainOf(recipient)

	// SRS bounce returning to a forwarding domain: store it (sealed to the
	// transport key) so the pull consumer can decode the delivery-failure notice.
	// It matches no alias and its domain may be a forwarding subdomain not in the
	// domains map, so it is handled before the domain branch.
	if isSRSLocalPart(recipient) && m.isForwardingDomain(dom) {
		return routingEntry{
			PublicKey:        m.TransportPublicKey,
			KeyKind:          keyKindTransport,
			Mode:             modeStore,
			ForwardingDomain: dom,
		}, true
	}

	de, ok := m.Domains[dom]
	if !ok {
		return routingEntry{}, false
	}

	switch de.CatchAllMode {
	case modeStore:
		return routingEntry{
			PublicKey:        de.PublicKey,
			KeyKind:          de.KeyKind,
			Mode:             modeStore,
			ForwardingDomain: fallbackDomain(de.ForwardingDomain, dom),
		}, true
	case modeForward:
		if de.CatchAllAddress == "" {
			return routingEntry{}, false
		}
		return routingEntry{
			Mode:             modeForward,
			Destinations:     []string{de.CatchAllAddress},
			ForwardingDomain: fallbackDomain(de.ForwardingDomain, dom),
			ForwardFrom:      de.ForwardFrom,
		}, true
	default:
		return routingEntry{}, false
	}
}

// rejectUnmatched reports whether the recipient's domain bounces unmatched mail
// (drives the exit code when resolve() finds nothing).
func (m *routingMap) rejectUnmatched(recipient string) bool {
	if de, ok := m.Domains[domainOf(recipient)]; ok {
		return de.RejectUnmatched
	}
	return true
}

// isSRSLocalPart reports whether an address's local part is an SRS-rewritten
// bounce address, matching the Postfix regexp accept map. SRS0 only —
// SRSRewriter on the main box generates and decodes nothing else.
func isSRSLocalPart(address string) bool {
	at := strings.Index(address, "@")
	local := address
	if at >= 0 {
		local = address[:at]
	}
	return strings.HasPrefix(strings.ToUpper(local), "SRS0=")
}

func (m *routingMap) isForwardingDomain(dom string) bool {
	for _, fd := range m.ForwardingDomains {
		if strings.EqualFold(fd, dom) {
			return true
		}
	}
	return false
}

func domainOf(address string) string {
	at := strings.LastIndex(address, "@")
	if at < 0 || at+1 >= len(address) {
		return ""
	}
	return strings.ToLower(address[at+1:])
}

func fallbackDomain(value, fallback string) string {
	if value == "" {
		return fallback
	}
	return value
}
