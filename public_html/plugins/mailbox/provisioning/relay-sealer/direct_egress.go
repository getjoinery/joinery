package main

// The Direct EGRESS proxy: a tenant's box signs a fully-formed request and the
// relay makes it, so the recipient sees the relay's address and never the
// box's. The relay never holds the instance signing key and never signs: it
// transports an app-signed request it cannot alter, exactly the division
// OutboundTransport already enforces for mail. Moving the signing key here would
// be a new custody model this design deliberately avoids.
//
// Served by relay-serve on the public listener as POST /egress, behind the
// tenant's signed envelope (relay_serve.go); the target rules below are what
// keep a public proxy from being an open one.

import (
	"context"
	"crypto/tls"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

const (
	egressPath = "/egress"
	// The header the box names its target in. A header rather than a body field
	// so the body stays the raw part bytes — re-encoding a 40MB attachment to
	// put it inside JSON would undo exactly what per-part transfer bought.
	egressTargetHeader = "X-Joinery-Direct-Target"
	// The upstream's status, echoed so the box can tell a refusal from a
	// transport failure without the relay interpreting either.
	egressStatusHeader = "X-Joinery-Direct-Status"

	egressMaxBody = 128 << 20
)

// handleEgress proxies one already-signed request out to another instance.
//
// The relay validates the DESTINATION and forwards bytes; it does not read,
// alter or sign anything. The target is validated the same way the box's own
// SSRF guard validates it, because the box is trusting this hop to be no more
// permissive than the one it would have made itself: a hostile recipient domain
// must not be able to aim a relay at an internal address any more than it could
// aim a box.
func handleEgress(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost || r.URL.Path != egressPath {
		http.NotFound(w, r)
		return
	}

	target := strings.TrimSpace(r.Header.Get(egressTargetHeader))
	if target == "" {
		http.Error(w, "no target", http.StatusBadRequest)
		return
	}
	parsed, err := url.Parse(target)
	if err != nil || parsed.Scheme != "https" || parsed.Host == "" {
		http.Error(w, "target must be an https URL", http.StatusBadRequest)
		return
	}
	if err := checkEgressTarget(parsed); err != nil {
		http.Error(w, err.Error(), http.StatusForbidden)
		return
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, egressMaxBody))
	if err != nil {
		http.Error(w, "unreadable body", http.StatusBadRequest)
		return
	}

	req, err := http.NewRequestWithContext(r.Context(), http.MethodPost, parsed.String(), strings.NewReader(string(body)))
	if err != nil {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	if ct := r.Header.Get("Content-Type"); ct != "" {
		req.Header.Set("Content-Type", ct)
	}
	req.Header.Set("User-Agent", "Joinery/Direct (relay)")

	client := &http.Client{
		Timeout: 60 * time.Second,
		// Redirects are never followed: the first hop passed the destination
		// check and a Location header is how that check gets escaped.
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		},
		// Pin the connection to an address validated at dial time. checkEgressTarget
		// resolves and validates once, but the default transport re-resolves the
		// hostname when it connects — so a hostile recipient domain could answer the
		// check with a public IP and the connection with an internal one (DNS
		// rebinding). pinnedDial closes that window by validating the very address it
		// dials; the hostname is kept only for TLS SNI and certificate verification.
		Transport: &http.Transport{
			DialContext:           pinnedDial,
			TLSClientConfig:       &tls.Config{MinVersion: tls.VersionTLS12},
			ExpectContinueTimeout: time.Second,
		},
	}
	resp, err := client.Do(req)
	if err != nil {
		// Every failure looks the same to the caller, which is what the box's
		// fallback wants: a refusal, a WAF, a proxy and a dead host all mean
		// "take the other path".
		http.Error(w, "upstream unreachable", http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()

	w.Header().Set(egressStatusHeader, strconv.Itoa(resp.StatusCode))
	if ct := resp.Header.Get("Content-Type"); ct != "" {
		w.Header().Set("Content-Type", ct)
	}
	w.WriteHeader(http.StatusOK)
	_, _ = io.Copy(w, io.LimitReader(resp.Body, 1<<20))
}

// checkEgressTarget refuses a destination the box's own guard would refuse:
// private, loopback, link-local and reserved addresses, and any privileged port
// other than 443.
func checkEgressTarget(u *url.URL) error {
	host := u.Hostname()
	port := u.Port()
	if port == "" {
		port = "443"
	}
	p, err := strconv.Atoi(port)
	if err != nil || (p != 443 && p < 1024) || p > 65535 {
		return errors.New("port not allowed")
	}

	ips, err := net.LookupIP(host)
	if err != nil || len(ips) == 0 {
		return errors.New("target does not resolve")
	}
	for _, ip := range ips {
		if !isPublicIP(ip) {
			return errors.New("target resolves to a non-public address")
		}
	}
	return nil
}

// pinnedDial resolves the target host, refuses if ANY resolved address is
// non-public, and connects to a validated address — so the address that was
// checked is the address that is used, closing the DNS-rebinding window the
// default transport would leave open.
func pinnedDial(ctx context.Context, network, addr string) (net.Conn, error) {
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return nil, err
	}
	ips, err := net.DefaultResolver.LookupIP(ctx, "ip", host)
	if err != nil || len(ips) == 0 {
		return nil, errors.New("target does not resolve")
	}
	for _, ip := range ips {
		if !isPublicIP(ip) {
			return nil, errors.New("target resolves to a non-public address")
		}
	}
	d := &net.Dialer{Timeout: 10 * time.Second}
	var lastErr error
	for _, ip := range ips {
		conn, derr := d.DialContext(ctx, network, net.JoinHostPort(ip.String(), port))
		if derr == nil {
			return conn, nil
		}
		lastErr = derr
	}
	return nil, lastErr
}

// isPublicIP mirrors the CIDR table the platform's UrlSafetyValidator uses. The
// two must agree: a relay more permissive than the box would be a way around
// the box's guard.
func isPublicIP(ip net.IP) bool {
	if ip == nil || ip.IsLoopback() || ip.IsUnspecified() ||
		ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() ||
		ip.IsInterfaceLocalMulticast() || ip.IsMulticast() || ip.IsPrivate() {
		return false
	}
	if v4 := ip.To4(); v4 != nil {
		switch {
		case v4[0] == 0: // 0.0.0.0/8 — routes to loopback on Linux
			return false
		case v4[0] == 100 && v4[1] >= 64 && v4[1] <= 127: // CGNAT 100.64/10
			return false
		case v4[0] == 192 && v4[1] == 0 && v4[2] == 2: // TEST-NET-1 192.0.2/24
			return false
		case v4[0] == 198 && v4[1] == 51 && v4[2] == 100: // TEST-NET-2 198.51.100/24
			return false
		case v4[0] == 203 && v4[1] == 0 && v4[2] == 113: // TEST-NET-3 203.0.113/24
			return false
		case v4[0] == 198 && (v4[1] == 18 || v4[1] == 19): // benchmarking 198.18/15
			return false
		case v4[0] >= 240: // reserved / future
			return false
		}
		return true
	}
	// An IPv4-compatible IPv6 address (::a.b.c.d — twelve zero bytes then a v4
	// address) is a deprecated guard-bypass shape; ::1 and :: are already handled
	// above, so any remaining all-zero-prefix address is one of these. Refuse it.
	if len(ip) == net.IPv6len {
		zeros := true
		for i := 0; i < 12; i++ {
			if ip[i] != 0 {
				zeros = false
				break
			}
		}
		if zeros {
			return false
		}
	}
	return true
}
