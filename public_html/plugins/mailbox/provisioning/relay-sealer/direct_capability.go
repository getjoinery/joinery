package main

// Resolving the SENDER's capability record, from the relay.
//
// Verifying a preflight needs the sending domain's Ed25519 key, and that domain
// is chosen by whoever is calling — so a naive "fresh lookup per preflight"
// would turn the relay into an outbound-DNS engine driven by attacker input,
// BEFORE the request is authenticated and therefore before any per-instance
// limit could apply. Three bounds close it, the same three the box uses:
// cache the record, negative-cache the failures, and rate-limit resolution by
// connecting peer.
//
// This is DNS through the system resolver rather than an HTTP fetch, so it is
// not an SSRF surface; the concern is purely the VOLUME of attacker-driven
// lookups.

import (
	"context"
	"net"
	"strings"
	"sync"
	"time"
)

const (
	// A sane floor and ceiling rather than the raw record TTL: a 30-second TTL
	// would defeat the bound this cache exists to provide, and a week-long one
	// would make a rotation invisible. A key id not in the cache forces one
	// refresh anyway, so a rotation is picked up long before this expires.
	capabilityPositiveTTL = 30 * time.Minute
	capabilityNegativeTTL = 15 * time.Minute

	srvPrefix = "_joinery._tcp."
	keyPrefix = "_joinery-key."
)

type capabilityRecord struct {
	Host string
	Port int
	// Key id => base64 Ed25519 public key. Several is the normal state during a
	// rotation, when the old key stays published while senders may still quote it.
	Keys      map[string]string
	expiresAt time.Time
	present   bool
}

type capabilityCache struct {
	mu      sync.Mutex
	entries map[string]*capabilityRecord
	// resolver is swappable so the tests never touch real DNS.
	resolver capabilityResolver
}

// capabilityResolver is the seam the tests replace.
type capabilityResolver interface {
	LookupSRV(ctx context.Context, name string) (string, int, error)
	LookupTXT(ctx context.Context, name string) ([]string, error)
}

type systemResolver struct{}

func (systemResolver) LookupSRV(ctx context.Context, name string) (string, int, error) {
	// net.LookupSRV with an empty service and proto queries the name verbatim,
	// which is what a fully-qualified _joinery._tcp.<domain> needs.
	_, addrs, err := net.DefaultResolver.LookupSRV(ctx, "", "", name)
	if err != nil || len(addrs) == 0 {
		return "", 0, err
	}
	best := addrs[0]
	for _, a := range addrs[1:] {
		if a.Priority < best.Priority || (a.Priority == best.Priority && a.Weight > best.Weight) {
			best = a
		}
	}
	return strings.TrimSuffix(best.Target, "."), int(best.Port), nil
}

func (systemResolver) LookupTXT(ctx context.Context, name string) ([]string, error) {
	return net.DefaultResolver.LookupTXT(ctx, name)
}

func newCapabilityCache() *capabilityCache {
	return &capabilityCache{entries: map[string]*capabilityRecord{}, resolver: systemResolver{}}
}

// lookup returns what a domain publishes, or nil when it publishes nothing
// usable. Never returns an error: "cannot resolve" and "publishes nothing" are
// the same answer to every caller.
func (c *capabilityCache) lookup(ctx context.Context, domain string) *capabilityRecord {
	domain = strings.ToLower(strings.TrimSpace(domain))
	if domain == "" {
		return nil
	}

	c.mu.Lock()
	entry, ok := c.entries[domain]
	if ok && time.Now().Before(entry.expiresAt) {
		c.mu.Unlock()
		if entry.present {
			return entry
		}
		return nil
	}
	c.mu.Unlock()

	resolved := c.resolve(ctx, domain)

	c.mu.Lock()
	if resolved == nil {
		c.entries[domain] = &capabilityRecord{present: false, expiresAt: time.Now().Add(capabilityNegativeTTL)}
	} else {
		resolved.present = true
		resolved.expiresAt = time.Now().Add(capabilityPositiveTTL)
		c.entries[domain] = resolved
	}
	// Bounded so an attacker naming endless domains cannot grow this without
	// limit. Dropping the whole map rather than evicting one entry keeps it
	// simple; a real federation holds far fewer peers than the cap.
	if len(c.entries) > 4096 {
		c.entries = map[string]*capabilityRecord{}
	}
	c.mu.Unlock()

	return resolved
}

// publicKeyFor returns the key a domain publishes under one key id.
//
// A key id not in the cached record triggers at most ONE refresh, so a rotation
// is picked up promptly without letting an attacker force unbounded lookups by
// naming random key ids.
func (c *capabilityCache) publicKeyFor(ctx context.Context, domain, keyID string) string {
	record := c.lookup(ctx, domain)
	if record == nil {
		return ""
	}
	if key, ok := record.Keys[keyID]; ok {
		return key
	}

	domain = strings.ToLower(strings.TrimSpace(domain))
	c.mu.Lock()
	delete(c.entries, domain)
	c.mu.Unlock()

	record = c.lookup(ctx, domain)
	if record == nil {
		return ""
	}
	return record.Keys[keyID]
}

func (c *capabilityCache) resolve(ctx context.Context, domain string) *capabilityRecord {
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	host, port, err := c.resolver.LookupSRV(ctx, srvPrefix+domain)
	if err != nil || host == "" || host == "." {
		return nil
	}
	if port <= 0 || port > 65535 {
		port = 443
	}

	txt, err := c.resolver.LookupTXT(ctx, keyPrefix+domain)
	if err != nil {
		return nil
	}
	keys := parseKeyRecords(txt)
	if len(keys) == 0 {
		// A host with no key is not a usable capability: the signature is
		// mandatory at both ends, so there would be nothing to verify against.
		return nil
	}
	return &capabilityRecord{Host: host, Port: port, Keys: keys}
}

// parseKeyRecords turns `v=joinery1; k=<id>; p=<base64>` strings into id => key.
func parseKeyRecords(values []string) map[string]string {
	keys := map[string]string{}
	for _, value := range values {
		fields := map[string]string{}
		for _, chunk := range strings.Split(value, ";") {
			pair := strings.SplitN(strings.TrimSpace(chunk), "=", 2)
			if len(pair) == 2 {
				fields[strings.ToLower(strings.TrimSpace(pair[0]))] = strings.TrimSpace(pair[1])
			}
		}
		if fields["v"] != "joinery1" {
			continue
		}
		id, public := fields["k"], fields["p"]
		if id == "" || public == "" {
			continue
		}
		if !validEd25519PublicKey(public) {
			continue // a malformed key is no key
		}
		keys[id] = public
	}
	return keys
}
