package main

// `relay-sealer direct-serve` — the two listeners that make a Fortress tenant
// reachable on Joinery Direct without ever exposing its origin box.
//
//	PUBLIC (:443, TLS)     inbound deliveries from other Joinery instances
//	TUNNEL (WireGuard)     outbound egress for this relay's own tenants
//
// **Why TLS is terminated here rather than by a web server.** The relay runs
// Postfix, milters, a Go binary and WireGuard, and nothing else — no PHP, no
// database, no web stack. Adding nginx plus certbot to obtain one certificate
// would roughly double the software on a machine whose smallness IS the
// security property. `autocert` obtains and renews the certificate in-process
// over TLS-ALPN-01 on the same port it already has to listen on, so the relay
// gains a certificate and no new package, daemon, config file or cron entry.
//
// **Why egress exists at all.** The recipient must see the RELAY's address, not
// the box's — that is the whole point of a hidden origin. So the box signs a
// fully-formed request and the relay makes it. The relay never holds the
// instance signing key and never signs: it transports an app-signed request it
// cannot alter, exactly the division OutboundTransport already enforces for
// mail. Moving the signing key here would be a new custody model this design
// deliberately avoids.
//
// The egress listener binds to the WireGuard address ONLY. The tunnel is the
// authentication: reaching it at all requires a WireGuard peer key the relay
// issued, which is the same boundary the spool pull already trusts.

import (
	"context"
	"crypto/tls"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	"golang.org/x/crypto/acme/autocert"
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

// runDirectServe is the `direct-serve` entry point.
func runDirectServe() int {
	fs := flag.NewFlagSet("direct-serve", flag.ContinueOnError)
	hostname := fs.String("hostname", "", "public hostname this relay answers Direct on (required)")
	routing := fs.String("routing", envOr("JOINERY_RELAY_ROUTING", "/opt/joinery-relay/routing.json"), "routing map path")
	spool := fs.String("spool", envOr("JOINERY_RELAY_SPOOL", "/var/spool/joinery-relay"), "default spool directory")
	certDir := fs.String("cert-cache", "/opt/joinery-relay/acme", "ACME certificate cache directory")
	stateDir := fs.String("state", "/opt/joinery-relay/direct", "Direct state directory (replay nonces)")
	tunnelAddr := fs.String("tunnel", "10.99.0.1", "WireGuard address to serve tenant egress on")
	tunnelPort := fs.Int("tunnel-port", 8442, "tenant egress port on the tunnel address")
	httpsAddr := fs.String("listen", ":443", "public HTTPS listen address")
	if err := fs.Parse(os.Args[2:]); err != nil {
		return 2
	}
	if strings.TrimSpace(*hostname) == "" {
		fmt.Fprintln(os.Stderr, "relay-sealer direct-serve: --hostname is required")
		return 2
	}

	handler := newDirectHandler(*routing, *spool, *stateDir+"/nonces")

	// Housekeeping: expired sessions, aged-out nonces and stale rate-limit
	// history. A long-lived process must not grow without bound.
	stop := make(chan struct{})
	go func() {
		t := time.NewTicker(time.Minute)
		defer t.Stop()
		for {
			select {
			case <-t.C:
				handler.state.sweep()
			case <-stop:
				return
			}
		}
	}()

	manager := &autocert.Manager{
		Prompt:     autocert.AcceptTOS,
		HostPolicy: autocert.HostWhitelist(strings.ToLower(strings.TrimSpace(*hostname))),
		Cache:      autocert.DirCache(*certDir),
	}

	public := &http.Server{
		Addr:    *httpsAddr,
		Handler: handler,
		TLSConfig: &tls.Config{
			GetCertificate: manager.GetCertificate,
			NextProtos:     []string{"h2", "http/1.1", "acme-tls/1"},
			MinVersion:     tls.VersionTLS12,
		},
		ReadHeaderTimeout: 15 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	egress := &http.Server{
		Addr:              net.JoinHostPort(*tunnelAddr, strconv.Itoa(*tunnelPort)),
		Handler:           http.HandlerFunc(handleEgress),
		ReadHeaderTimeout: 15 * time.Second,
	}

	errs := make(chan error, 2)
	go func() {
		fmt.Fprintf(os.Stderr, "relay-sealer: Direct listening on %s for %s\n", *httpsAddr, *hostname)
		if err := public.ListenAndServeTLS("", ""); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errs <- fmt.Errorf("public listener: %w", err)
		}
	}()
	// The egress proxy authenticates callers ONLY by the address it binds — reaching
	// the WireGuard address requires a peer key the relay issued. So the bind address
	// must be a private/tunnel address: binding it to a public or empty address would
	// turn the proxy into an open, world-reachable relay. Refuse rather than serve it,
	// without touching the public listener inbound delivery depends on.
	tunnelIP := net.ParseIP(strings.TrimSpace(*tunnelAddr))
	if tunnelIP == nil || isPublicIP(tunnelIP) {
		fmt.Fprintf(os.Stderr, "relay-sealer: refusing to serve egress on non-private tunnel address %q\n", *tunnelAddr)
	} else {
		go func() {
			fmt.Fprintf(os.Stderr, "relay-sealer: Direct egress listening on %s\n", egress.Addr)
			if err := egress.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
				// The tunnel address does not exist until WireGuard is up. That is a
				// real failure worth reporting, but it must not take the PUBLIC
				// listener down with it — inbound delivery does not depend on it.
				fmt.Fprintf(os.Stderr, "relay-sealer: egress listener unavailable: %v\n", err)
			}
		}()
	}

	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)
	select {
	case err := <-errs:
		fmt.Fprintf(os.Stderr, "relay-sealer: %v\n", err)
		close(stop)
		return 1
	case <-signals:
		close(stop)
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = public.Shutdown(ctx)
		_ = egress.Shutdown(ctx)
		return 0
	}
}

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
