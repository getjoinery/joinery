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

// legacyTenantSlug names the synthesized tenant block when the map predates the
// tenancy-native format (no "tenants" object). Its spool dir is empty, which
// callers resolve to the flat JOINERY_RELAY_SPOOL default.
const legacyTenantSlug = "default"

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
	// The owning tenant's slug — selects the tenantConfig block (spool dir, SRS
	// secret, forward identity, limits) this entry delivers under.
	Tenant string `json:"tenant"`
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
	Tenant           string `json:"tenant"`
}

// tenantConfig is one tenant's delivery context on this relay: where its
// sealed mail spools, the identity its forwards carry, the key its SRS bounces
// seal to, and the shard-side limits (which come from the root-owned
// limits.json at merge time, never from the tenant's pushed fragment).
type tenantConfig struct {
	SRSSecret          string   `json:"srs_secret"`
	ForwardFromName    string   `json:"forward_from_name"`
	ForwardShowVia     bool     `json:"forward_show_via"`
	TransportPublicKey string   `json:"transport_public_key"`
	SpoolDir           string   `json:"spool_dir"`
	ForwardingDomains  []string `json:"forwarding_domains"`
	// Shard policy limits. Zero means "no limit" (self-hosted default).
	ForwardHourlyLimit int `json:"forward_hourly_limit"`
	SpoolMaxMiB        int `json:"spool_max_mib"`
	SpoolMaxEntries    int `json:"spool_max_entries"`
	// The tenant's fragment version at merge time (display/debug only).
	FragmentVersion int64 `json:"fragment_version"`
}

// routingMap is the DB-free routing table merged shard-side from the tenants'
// pushed fragments. It is the sealer's ONLY source of recipient public keys and
// routing — the relay holds no database connection. The map is tenancy-native:
// every recipient/domain entry names its tenant, and per-tenant context lives
// in Tenants. A map produced before the tenancy-native format (top-level
// srs_secret etc., no "tenants") is normalized into a single synthesized
// tenant on load, so an in-flight upgrade never breaks routing.
type routingMap struct {
	Format    int    `json:"format"`
	Version   int64  `json:"version"`
	Generated string `json:"generated_utc"`

	Tenants    map[string]tenantConfig `json:"tenants"`
	Recipients map[string]routingEntry `json:"recipients"`
	Domains    map[string]domainEntry  `json:"domains"`

	// Legacy single-tenant fields (pre-tenancy map format). Read only by
	// normalize(); never written by the merge.
	SRSSecret          string   `json:"srs_secret"`
	ForwardFromName    string   `json:"forward_from_name"`
	ForwardShowVia     bool     `json:"forward_show_via"`
	TransportPublicKey string   `json:"transport_public_key"`
	ForwardingDomains  []string `json:"forwarding_domains"`
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
	m.normalize()
	return &m, nil
}

// normalize lifts a legacy (pre-tenancy) map into the tenancy-native shape:
// one synthesized tenant carrying the old top-level globals, stamped onto
// every entry. After normalize() every code path can assume Tenants is
// populated and every entry names a tenant that exists.
func (m *routingMap) normalize() {
	if m.Recipients == nil {
		m.Recipients = map[string]routingEntry{}
	}
	if m.Domains == nil {
		m.Domains = map[string]domainEntry{}
	}
	if len(m.Tenants) > 0 {
		return
	}
	m.Tenants = map[string]tenantConfig{
		legacyTenantSlug: {
			SRSSecret:          m.SRSSecret,
			ForwardFromName:    m.ForwardFromName,
			ForwardShowVia:     m.ForwardShowVia,
			TransportPublicKey: m.TransportPublicKey,
			SpoolDir:           "", // resolved to the flat JOINERY_RELAY_SPOOL default
			ForwardingDomains:  m.ForwardingDomains,
		},
	}
	for addr, e := range m.Recipients {
		e.Tenant = legacyTenantSlug
		m.Recipients[addr] = e
	}
	for dom, d := range m.Domains {
		d.Tenant = legacyTenantSlug
		m.Domains[dom] = d
	}
}

// tenantFor returns the tenantConfig an entry delivers under. ok=false means
// the map is inconsistent (an entry names a tenant with no block) — callers
// must temp-fail rather than guess a spool dir or seal key.
func (m *routingMap) tenantFor(entry routingEntry) (tenantConfig, bool) {
	tc, ok := m.Tenants[entry.Tenant]
	return tc, ok
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
	// owning tenant's transport key) so the pull consumer can decode the
	// delivery-failure notice. It matches no alias and its domain may be a
	// forwarding subdomain not in the domains map, so it is handled before the
	// domain branch.
	if isSRSLocalPart(recipient) {
		if slug, ok := m.tenantOfForwardingDomain(dom); ok {
			return routingEntry{
				PublicKey:        m.Tenants[slug].TransportPublicKey,
				KeyKind:          keyKindTransport,
				Mode:             modeStore,
				ForwardingDomain: dom,
				Tenant:           slug,
			}, true
		}
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
			Tenant:           de.Tenant,
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
			Tenant:           de.Tenant,
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

// tenantOfForwardingDomain finds which tenant owns a forwarding domain, so an
// SRS bounce seals to that tenant's transport key and spools in that tenant's
// directory.
func (m *routingMap) tenantOfForwardingDomain(dom string) (string, bool) {
	for slug, tc := range m.Tenants {
		for _, fd := range tc.ForwardingDomains {
			if strings.EqualFold(fd, dom) {
				return slug, true
			}
		}
	}
	return "", false
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
