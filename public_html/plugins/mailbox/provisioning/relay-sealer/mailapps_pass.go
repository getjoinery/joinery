package main

// The mail apps pass-through (specs/mail_apps_plugin.md § Where programs
// connect): Thunderbird and other mail programs reach a relay-fronted
// deployment's mail gateway through the relay, on 993 (IMAP) and 465
// (submission), without the relay ever terminating their TLS. The relay reads
// the ClientHello's server name without consuming it, dials the deployment's
// server on the same port, writes a PROXY protocol v1 line naming the program's
// address, replays the hello and copies bytes both ways. It never sees an app
// password or a message.
//
// Only on a relay with ONE tenant (a self-hosted relay), and only for this
// relay's own mail hostname: that is the name the tenant's mail programs use,
// and the name the relay already answers on 443. A shared shard passes nothing.
// The server's addresses arrive in the tenant's signed fragment
// (mail_apps_origins); none means the pass-through is off.

import (
	"bytes"
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	// Connections one program address (an IPv6 /64) may hold at once. A mail
	// program opens about five per account.
	mailAppsPerSource = 20
	// Connections the pass-through holds at once, in all.
	mailAppsTotal = 1000
	// How long a program has to send its hello, and the relay to reach the server.
	mailAppsHelloTimeout = 10 * time.Second
	mailAppsDialTimeout  = 10 * time.Second
	// A fragment names at most this many server addresses.
	mailAppsMaxOrigins = 4
)

// mailAppsPass holds the pass-through's state: its caps, its counters for the
// ping, and the seams the tests use.
type mailAppsPass struct {
	s *relayServer

	mu            sync.Mutex
	active        int
	perSource     map[string]int
	spliced       []time.Time
	refused       []time.Time
	lastRefusal   string
	lastRefusalAt time.Time
	bound         map[string]bool

	// dial reaches the server; a field so the tests can point it at a fake.
	dial func(ctx context.Context, network, addr string) (net.Conn, error)
	// routing caches the parsed map by modification time and size.
	routingStamp string
	routingMap   *routingMap
}

func newMailAppsPass(s *relayServer) *mailAppsPass {
	d := &net.Dialer{Timeout: mailAppsDialTimeout, KeepAlive: 30 * time.Second}
	return &mailAppsPass{s: s, perSource: map[string]int{}, bound: map[string]bool{}, dial: d.DialContext}
}

// listen opens one pass-through port and serves it until the listener closes.
// role is "993" or "465" (how the ping names it); targetPort is the server's
// port it dials. A port that cannot be bound is reported in the ping, not
// fatal: the relay's mail and 443 go on.
func (p *mailAppsPass) listen(addr, role string, targetPort int) {
	ln, err := net.Listen("tcp", addr)
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer: mail apps pass-through cannot listen on %s: %v\n", addr, err)
		return
	}
	p.mu.Lock()
	p.bound[role] = true
	p.mu.Unlock()
	fmt.Fprintf(os.Stderr, "relay-sealer: mail apps pass-through listening on %s for %s\n", addr, p.s.hostname)
	p.serve(ln, targetPort)
}

func (p *mailAppsPass) serve(ln net.Listener, targetPort int) {
	for {
		c, err := ln.Accept()
		if err != nil {
			var ne net.Error
			if errors.As(err, &ne) && ne.Timeout() {
				time.Sleep(50 * time.Millisecond)
				continue
			}
			return
		}
		go p.handle(c, targetPort)
	}
}

// sourceKey is what the per-source cap counts: the IPv4 address, or the IPv6 /64.
func sourceKey(addr net.Addr) string {
	host, _, err := net.SplitHostPort(addr.String())
	if err != nil {
		return addr.String()
	}
	ip := net.ParseIP(host)
	if ip == nil {
		return host
	}
	if v4 := ip.To4(); v4 != nil {
		return v4.String()
	}
	return ip.Mask(net.CIDRMask(64, 128)).String() + "/64"
}

func (p *mailAppsPass) admit(key string) bool {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.active >= mailAppsTotal || p.perSource[key] >= mailAppsPerSource {
		return false
	}
	p.active++
	p.perSource[key]++
	return true
}

func (p *mailAppsPass) release(key string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.active--
	if p.perSource[key]--; p.perSource[key] <= 0 {
		delete(p.perSource, key)
	}
}

func (p *mailAppsPass) refuse(c net.Conn, why string) {
	p.mu.Lock()
	p.refused = append(p.refused, time.Now())
	p.lastRefusal, p.lastRefusalAt = why, time.Now()
	p.mu.Unlock()
	_ = c.Close()
}

func (p *mailAppsPass) handle(c net.Conn, targetPort int) {
	key := sourceKey(c.RemoteAddr())
	if !p.admit(key) {
		p.refuse(c, "connection cap reached")
		return
	}
	defer p.release(key)

	_ = c.SetReadDeadline(time.Now().Add(mailAppsHelloTimeout))
	name, hello, err := peekClientHello(c)
	if err != nil {
		p.refuse(c, "no TLS hello")
		return
	}
	_ = c.SetReadDeadline(time.Time{})
	name = strings.ToLower(strings.TrimSuffix(name, "."))
	if name != p.s.hostname {
		p.refuse(c, "a name this relay does not pass")
		return
	}
	origins, why := p.origins()
	if len(origins) == 0 {
		p.refuse(c, why)
		return
	}

	var server net.Conn
	for _, ip := range origins {
		ctx, cancel := context.WithTimeout(context.Background(), mailAppsDialTimeout)
		server, err = p.dial(ctx, "tcp", net.JoinHostPort(ip, strconv.Itoa(targetPort)))
		cancel()
		if err == nil {
			break
		}
	}
	if server == nil {
		p.refuse(c, "the server did not answer on "+strconv.Itoa(targetPort))
		return
	}
	defer server.Close()

	if _, err := io.WriteString(server, proxyLine(c.RemoteAddr(), c.LocalAddr())); err != nil {
		p.refuse(c, "the server closed the connection")
		return
	}
	if _, err := server.Write(hello); err != nil {
		p.refuse(c, "the server closed the connection")
		return
	}
	p.mu.Lock()
	p.spliced = append(p.spliced, time.Now())
	p.mu.Unlock()
	splice(c, server)
}

// soleTenant is the relay's one registered tenant, or why there is not one.
// Counted from the registrations (tenants/), not the merged map, which lists
// only tenants that have pushed a fragment: a shard whose second tenant has
// not pushed yet is still shared.
func (p *mailAppsPass) soleTenant() (string, string) {
	slugs, err := listTenantSlugs(p.s.paths.tenantsDir())
	if err != nil || len(slugs) == 0 {
		return "", "no tenant"
	}
	if len(slugs) != 1 {
		return "", "a relay with more than one tenant passes nothing"
	}
	return slugs[0], ""
}

// origins are the server addresses the one tenant's fragment names, or why
// there are none.
func (p *mailAppsPass) origins() ([]string, string) {
	slug, why := p.soleTenant()
	if slug == "" {
		return nil, why
	}
	m, err := p.routing()
	if err != nil {
		return nil, "no routing map yet"
	}
	if tc, ok := m.Tenants[slug]; ok && len(tc.MailAppsOrigins) > 0 {
		return tc.MailAppsOrigins, ""
	}
	return nil, "mail apps are off for this relay's deployment"
}

func (p *mailAppsPass) routing() (*routingMap, error) {
	st, err := os.Stat(p.s.routingPath)
	if err != nil {
		return nil, err
	}
	stamp := st.ModTime().String() + "/" + strconv.FormatInt(st.Size(), 10)
	p.mu.Lock()
	if stamp == p.routingStamp && p.routingMap != nil {
		m := p.routingMap
		p.mu.Unlock()
		return m, nil
	}
	p.mu.Unlock()
	m, err := loadRoutingMap(p.s.routingPath)
	if err != nil {
		return nil, err
	}
	p.mu.Lock()
	p.routingStamp, p.routingMap = stamp, m
	p.mu.Unlock()
	return m, nil
}

// proxyLine is the PROXY protocol v1 header: the program's address and port,
// then the relay's. Mixed families, which v1 cannot express, are UNKNOWN, and
// the gateway then counts the connection as the relay's.
func proxyLine(client, local net.Addr) string {
	ch, cp, err1 := net.SplitHostPort(client.String())
	lh, lp, err2 := net.SplitHostPort(local.String())
	cip, lip := net.ParseIP(ch), net.ParseIP(lh)
	if err1 != nil || err2 != nil || cip == nil || lip == nil {
		return "PROXY UNKNOWN\r\n"
	}
	switch {
	case cip.To4() != nil && lip.To4() != nil:
		return fmt.Sprintf("PROXY TCP4 %s %s %s %s\r\n", cip.To4(), lip.To4(), cp, lp)
	case cip.To4() == nil && lip.To4() == nil:
		return fmt.Sprintf("PROXY TCP6 %s %s %s %s\r\n", cip, lip, cp, lp)
	}
	return "PROXY UNKNOWN\r\n"
}

// splice copies both ways until both directions end, passing a half-close on.
func splice(a, b net.Conn) {
	var wg sync.WaitGroup
	copyHalf := func(dst, src net.Conn) {
		defer wg.Done()
		_, _ = io.Copy(dst, src)
		if cw, ok := dst.(interface{ CloseWrite() error }); ok {
			_ = cw.CloseWrite()
		} else {
			_ = dst.Close()
		}
	}
	wg.Add(2)
	go copyHalf(a, b)
	go copyHalf(b, a)
	wg.Wait()
}

// peekClientHello reads a TLS ClientHello through crypto/tls itself (so a hello
// split across records parses as it would for a real server) and returns the
// server name and every byte read, to be replayed to the server. Nothing is
// ever written to the program.
func peekClientHello(c net.Conn) (string, []byte, error) {
	var buf bytes.Buffer
	var name string
	seen := false
	errSeen := errors.New("hello read")
	srv := tls.Server(readOnlyConn{Conn: c, r: io.TeeReader(c, &buf)}, &tls.Config{
		GetConfigForClient: func(h *tls.ClientHelloInfo) (*tls.Config, error) {
			name, seen = h.ServerName, true
			return nil, errSeen
		},
	})
	_ = srv.Handshake()
	if !seen {
		return "", nil, errors.New("not a TLS ClientHello")
	}
	return name, buf.Bytes(), nil
}

// readOnlyConn feeds crypto/tls the program's bytes and swallows what it would
// answer (its alert), so the program sees nothing from the relay.
type readOnlyConn struct {
	net.Conn
	r io.Reader
}

func (c readOnlyConn) Read(p []byte) (int, error)  { return c.r.Read(p) }
func (c readOnlyConn) Write(p []byte) (int, error) { return len(p), nil }

// sweep drops counter entries older than an hour.
func (p *mailAppsPass) sweep() {
	cutoff := time.Now().Add(-time.Hour)
	trim := func(in []time.Time) []time.Time {
		out := in[:0]
		for _, t := range in {
			if t.After(cutoff) {
				out = append(out, t)
			}
		}
		return out
	}
	p.mu.Lock()
	p.spliced = trim(p.spliced)
	p.refused = trim(p.refused)
	p.mu.Unlock()
}

// status is the ping's mail_apps group (relay_status.go).
func (p *mailAppsPass) status() map[string]interface{} {
	origins, why := p.origins()
	cutoff := time.Now().Add(-time.Hour)
	count := func(in []time.Time) int {
		n := 0
		for _, t := range in {
			if t.After(cutoff) {
				n++
			}
		}
		return n
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	out := map[string]interface{}{
		"listening":  map[string]bool{"993": p.bound["993"], "465": p.bound["465"]},
		"origins":    len(origins),
		"active":     p.active,
		"spliced_1h": count(p.spliced),
		"refused_1h": count(p.refused),
	}
	if len(origins) == 0 {
		out["off_because"] = why
	}
	if p.lastRefusal != "" {
		out["last_refusal"] = p.lastRefusal
		out["last_refusal_utc"] = p.lastRefusalAt.UTC().Format(time.RFC3339)
	}
	for k, v := range p.s.mailAppsCertStatus() {
		out[k] = v
	}
	return out
}

// publicAddress: a server address the pass-through may dial. Never loopback,
// private, link-local or unspecified, so a fragment cannot point the relay at
// itself or at a network behind it.
func publicAddress(s string) bool {
	ip := net.ParseIP(strings.TrimSpace(s))
	return ip != nil && ip.IsGlobalUnicast() && !ip.IsPrivate() && !ip.IsLoopback() &&
		!ip.IsLinkLocalUnicast() && !ip.IsUnspecified()
}

// normalizeOrigins writes each address the one way (validated before merge).
func normalizeOrigins(in []string) []string {
	var out []string
	for _, o := range in {
		if ip := net.ParseIP(strings.TrimSpace(o)); ip != nil {
			out = append(out, ip.String())
		}
	}
	return out
}
