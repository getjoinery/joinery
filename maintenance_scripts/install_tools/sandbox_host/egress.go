package main

// The guard for door 5: a module's HTTPS request goes only to a public
// address. The same rules as the platform's SafeHttpClient and
// UrlSafetyValidator, and the relay's Direct egress (relay-sealer
// direct_egress.go), which this mirrors: the address checked is the address
// dialled (no DNS rebinding), redirects are never followed, and private,
// loopback, link-local, multicast, CGNAT, documentation and reserved ranges
// are refused, IPv4 and IPv6 alike.

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"net"
	"net/http"
	"time"
)

var errNotPublic = errors.New("resolves to a non-public address")

// guardLookup resolves a host; tests replace it.
var guardLookup = func(ctx context.Context, host string) ([]net.IP, error) {
	return net.DefaultResolver.LookupIP(ctx, "ip", host)
}

// Test seams, never set in the program: reach a local stand-in server
// (allow private addresses, dial another port, trust its certificate).
var (
	guardAllowsPrivate = false
	guardDialPort      = ""
	guardRootCAs       *x509.CertPool
)

func guardedClient() *http.Client {
	return &http.Client{
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		Transport: &http.Transport{
			DialContext:           pinnedDial,
			TLSClientConfig:       &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: guardRootCAs},
			TLSHandshakeTimeout:   5 * time.Second,
			ResponseHeaderTimeout: 10 * time.Second,
			ExpectContinueTimeout: time.Second,
			DisableKeepAlives:     true,
			Proxy:                 nil,
		},
	}
}

// pinnedDial resolves the host, refuses if any address is not public, and
// dials an address it checked.
func pinnedDial(ctx context.Context, network, addr string) (net.Conn, error) {
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return nil, err
	}
	var ips []net.IP
	if ip := net.ParseIP(host); ip != nil {
		ips = []net.IP{ip}
	} else {
		ips, err = guardLookup(ctx, host)
		if err != nil || len(ips) == 0 {
			return nil, errors.New("does not resolve")
		}
	}
	for _, ip := range ips {
		if !guardAllowsPrivate && !isPublicIP(ip) {
			return nil, errNotPublic
		}
	}
	if guardDialPort != "" {
		port = guardDialPort
	}
	d := &net.Dialer{Timeout: 5 * time.Second}
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

// isPublicIP mirrors the platform's table of addresses never reached.
func isPublicIP(ip net.IP) bool {
	if ip == nil || ip.IsLoopback() || ip.IsUnspecified() ||
		ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() ||
		ip.IsInterfaceLocalMulticast() || ip.IsMulticast() || ip.IsPrivate() {
		return false
	}
	if v4 := ip.To4(); v4 != nil {
		switch {
		case v4[0] == 0: // 0.0.0.0/8, routes to loopback on Linux
			return false
		case v4[0] == 100 && v4[1] >= 64 && v4[1] <= 127: // CGNAT 100.64/10
			return false
		case v4[0] == 192 && v4[1] == 0 && v4[2] == 0: // IETF protocol assignments 192.0.0/24
			return false
		case v4[0] == 192 && v4[1] == 0 && v4[2] == 2: // TEST-NET-1
			return false
		case v4[0] == 198 && v4[1] == 51 && v4[2] == 100: // TEST-NET-2
			return false
		case v4[0] == 203 && v4[1] == 0 && v4[2] == 113: // TEST-NET-3
			return false
		case v4[0] == 198 && (v4[1] == 18 || v4[1] == 19): // benchmarking 198.18/15
			return false
		case v4[0] >= 240: // reserved, and broadcast
			return false
		}
		return true
	}
	if len(ip) == net.IPv6len {
		// ::a.b.c.d (IPv4-compatible, deprecated): twelve zero bytes.
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
		// 64:ff9b::/96 (NAT64) carries an IPv4 address that may be internal.
		if ip[0] == 0x00 && ip[1] == 0x64 && ip[2] == 0xff && ip[3] == 0x9b {
			return false
		}
		// 2001:db8::/32 documentation.
		if ip[0] == 0x20 && ip[1] == 0x01 && ip[2] == 0x0d && ip[3] == 0xb8 {
			return false
		}
	}
	return true
}
