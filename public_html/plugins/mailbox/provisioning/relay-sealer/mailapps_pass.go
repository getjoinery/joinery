package main

// The mail apps pass-through (specs/mail_apps_plugin.md § Where programs
// connect): Thunderbird and other mail programs reach a relay-fronted
// deployment's mail gateway through the relay, on 993 (IMAP) and 465
// (submission), without the relay ever terminating their TLS. The relay reads
// the ClientHello's server name without consuming it, dials the deployment's
// server on the same port, writes a PROXY protocol v1 line naming the program's
// address, replays the hello and copies bytes both ways. It never sees an app
// password or a message. Submission is dialed on the server's 10465, not 465:
// cloud providers (Linode among them) block outbound 25, 465 and 587, so the
// relay could not reach the server's 465 at all.
//
// Which deployment a program reaches is decided by the name it asked for:
//   - a self-hosted relay (no operator key, one tenant): its own mail hostname,
//     the name its tenant's programs use and the relay answers on 443;
//   - a shared shard (it has an operator key): each tenant's slot name, which
//     only the operator assigns (the signed tenant-add's mx_hostname, kept in
//     the tenant's root-owned registry entry). The shard's own name passes
//     nothing, so no tenant can be reached, or certified, under another's name.
// The server's addresses arrive in the tenant's signed fragment
// (mail_apps_origins); none means the pass-through is off for that tenant.

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
	mailAppsHelloTimeout = 5 * time.Second
	mailAppsDialTimeout  = 10 * time.Second
	// A fragment names at most this many server addresses.
	mailAppsMaxOrigins = 4
	// The server's port submission is dialed on: the gateway's second
	// submission listener (-relay-smtp-listen), open to the relay only. A mirror
	// of RelaySubmissionPort (mail-gateway main.go) and RELAY_SUBMISSION_PORT
	// (install_mail_gateway.sh).
	mailAppsSubmissionTarget = 10465
	// How long the minute's check of the server's ports waits for each.
	mailAppsCheckTimeout = 5 * time.Second
)

// mailAppsPerTenant: connections one deployment's programs may hold at once
// on a shard (a var so a test can lower it).
var mailAppsPerTenant = 300

// mailAppsPass holds the pass-through's state: its caps, its counters for the
// ping, and the seams the tests use.
type mailAppsPass struct {
	s *relayServer

	mu        sync.Mutex
	active    int
	perSource map[string]int
	perTenant map[string]int
	// refusals before a tenant is known (no hello, a name no tenant has, the
	// total and per-source caps): reported to a self-hosted relay's one
	// tenant, never to a shard's tenants, whose own refusals are in stats.
	refused       []time.Time
	lastRefusal   string
	lastRefusalAt time.Time
	stats         map[string]*mailAppsTenantStats
	bound         map[string]bool
	// targets: the server port each listening role dials.
	targets  map[string]int
	checking bool
	// totalCap: mailAppsTotal, or on a shard enough for every tenant's own cap,
	// so one tenant's programs cannot fill the pool the others share (set at
	// each check, as tenants come and go).
	totalCap int

	// dial reaches the server; a field so the tests can point it at a fake.
	dial func(ctx context.Context, network, addr string) (net.Conn, error)
	// routing caches the parsed map by modification time and size.
	routingStamp string
	routingMap   *routingMap
}

// mailAppsTenantStats: one tenant's counters for its ping, and whether its
// server answered on each onward port at the last check.
type mailAppsTenantStats struct {
	spliced       []time.Time
	refused       []time.Time
	lastRefusal   string
	lastRefusalAt time.Time
	reaches       map[string]bool
	checkedAt     time.Time
}

func newMailAppsPass(s *relayServer) *mailAppsPass {
	d := &net.Dialer{Timeout: mailAppsDialTimeout, KeepAlive: 30 * time.Second}
	return &mailAppsPass{s: s, perSource: map[string]int{}, perTenant: map[string]int{}, bound: map[string]bool{},
		targets: map[string]int{}, stats: map[string]*mailAppsTenantStats{}, totalCap: mailAppsTotal, dial: d.DialContext}
}

// tenantStats is the tenant's record, made on first use. Call with p.mu held.
func (p *mailAppsPass) tenantStats(slug string) *mailAppsTenantStats {
	st := p.stats[slug]
	if st == nil {
		st = &mailAppsTenantStats{reaches: map[string]bool{}}
		p.stats[slug] = st
	}
	return st
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
	p.targets[role] = targetPort
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
	if p.active >= p.totalCap || p.perSource[key] >= mailAppsPerSource {
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

// refuseFor is a refusal once the tenant is known: counted as the tenant's.
func (p *mailAppsPass) refuseFor(c net.Conn, slug, why string) {
	p.mu.Lock()
	st := p.tenantStats(slug)
	st.refused = append(st.refused, time.Now())
	st.lastRefusal, st.lastRefusalAt = why, time.Now()
	p.mu.Unlock()
	_ = c.Close()
}

func (p *mailAppsPass) admitTenant(slug string) bool {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.perTenant[slug] >= mailAppsPerTenant {
		return false
	}
	p.perTenant[slug]++
	return true
}

func (p *mailAppsPass) releaseTenant(slug string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.perTenant[slug]--; p.perTenant[slug] <= 0 {
		delete(p.perTenant, slug)
	}
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
	slug, why := p.route(name)
	if slug == "" {
		p.refuse(c, why)
		return
	}
	// The per-tenant cap shares a shard among its tenants; a self-hosted
	// relay's one tenant has the whole pass-through.
	if p.shard() {
		if !p.admitTenant(slug) {
			p.refuseFor(c, slug, "this deployment's connection cap reached")
			return
		}
		defer p.releaseTenant(slug)
	}
	origins, why := p.originsOf(slug)
	if len(origins) == 0 {
		p.refuseFor(c, slug, why)
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
		p.refuseFor(c, slug, "the server did not answer on "+strconv.Itoa(targetPort))
		return
	}
	defer server.Close()

	if _, err := io.WriteString(server, proxyLine(c.RemoteAddr(), c.LocalAddr())); err != nil {
		p.refuseFor(c, slug, "the server closed the connection")
		return
	}
	if _, err := server.Write(hello); err != nil {
		p.refuseFor(c, slug, "the server closed the connection")
		return
	}
	p.mu.Lock()
	st := p.tenantStats(slug)
	st.spliced = append(st.spliced, time.Now())
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

// shard: a relay with an operator key is a shared shard; a self-hosted relay
// has its tenant's key only.
func (p *mailAppsPass) shard() bool {
	return p.s.auth != nil && p.s.auth.publicKeyFor(relayOperatorTenant) != ""
}

// route is the tenant a program asking for name reaches, or why none.
func (p *mailAppsPass) route(name string) (string, string) {
	if p.shard() {
		if name == "" {
			return "", "a program must name the slot it connects to"
		}
		if name == p.s.hostname {
			return "", "the shard's own name passes nothing (each deployment has its slot's name)"
		}
		slugs, _ := listTenantSlugs(p.s.paths.tenantsDir())
		for _, slug := range slugs {
			// A tenant the operator has assigned no name is reached by none.
			if n := tenantMailName(p.s.paths, slug); n != "" && n == name {
				return slug, ""
			}
		}
		return "", "a name this relay does not pass"
	}
	if name != p.s.hostname {
		return "", "a name this relay does not pass"
	}
	return p.soleTenant()
}

// mailName is the name the tenant's programs use here, and the only one it is
// certified under, or why it has none: on a shard the slot name the operator
// assigned; on a self-hosted relay the relay's own name, for its one tenant.
func (p *mailAppsPass) mailName(slug string) (string, string) {
	if p.shard() {
		name := tenantMailName(p.s.paths, slug)
		if name == "" {
			return "", "the operator has assigned this deployment no mail name on this shard"
		}
		if name == p.s.hostname {
			return "", "the shard's own name is no deployment's"
		}
		return name, ""
	}
	sole, why := p.soleTenant()
	if sole == "" {
		return "", why
	}
	if sole != slug {
		return "", "only a relay's one tenant is passed mail programs under its name"
	}
	return p.s.hostname, ""
}

// originsOf are the server addresses the tenant's fragment names, or why
// there are none.
func (p *mailAppsPass) originsOf(slug string) ([]string, string) {
	m, err := p.routing()
	if err != nil {
		return nil, "no routing map yet"
	}
	if tc, ok := m.Tenants[slug]; ok && len(tc.MailAppsOrigins) > 0 {
		return tc.MailAppsOrigins, ""
	}
	return nil, "mail apps are off for this deployment"
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
	p.refused = trim(p.refused)
	for _, st := range p.stats {
		st.spliced = trim(st.spliced)
		st.refused = trim(st.refused)
	}
	p.mu.Unlock()
}

// checkServer dials the server on each port the pass-through dials onward,
// so the ping says a path is blocked before a program finds it: a provider's
// outbound block, or a firewall that has not opened a port to this relay,
// shows here as reaches_server false. One connection, closed at once; the
// gateway logs it and serves nothing. Run every minute while mail apps are
// on; a check still running is not started again.
func (p *mailAppsPass) checkServer() {
	p.mu.Lock()
	if p.checking {
		p.mu.Unlock()
		return
	}
	p.checking = true
	targets := map[string]int{}
	for role, port := range p.targets {
		targets[role] = port
	}
	p.mu.Unlock()

	results := map[string]map[string]bool{}
	slugs, _ := listTenantSlugs(p.s.paths.tenantsDir())
	total := mailAppsTotal
	if p.shard() && len(slugs)*mailAppsPerTenant > total {
		total = len(slugs) * mailAppsPerTenant
	}
	for _, slug := range slugs {
		if name, _ := p.mailName(slug); name == "" {
			continue
		}
		origins, _ := p.originsOf(slug)
		if len(origins) == 0 {
			continue
		}
		reaches := map[string]bool{}
		for role, port := range targets {
			reaches[role] = false
			for _, ip := range origins {
				ctx, cancel := context.WithTimeout(context.Background(), mailAppsCheckTimeout)
				c, err := p.dial(ctx, "tcp", net.JoinHostPort(ip, strconv.Itoa(port)))
				cancel()
				if err == nil {
					_ = c.Close()
					reaches[role] = true
					break
				}
			}
		}
		results[slug] = reaches
	}
	now := time.Now()
	p.mu.Lock()
	for slug, st := range p.stats {
		if _, ok := results[slug]; !ok {
			st.reaches, st.checkedAt = map[string]bool{}, time.Time{}
		}
	}
	for slug, reaches := range results {
		st := p.tenantStats(slug)
		st.reaches, st.checkedAt = reaches, now
	}
	p.totalCap = total
	p.checking = false
	p.mu.Unlock()
}

// status is the ping's mail_apps group for one tenant (relay_status.go): its
// own name, origins, connections and checks. A self-hosted relay's one tenant
// also sees the refusals made before a tenant was known; a shard's tenants
// never see another's. The operator's ping on a shard gets the whole
// pass-through instead (shardStatus).
func (p *mailAppsPass) status(slug string) map[string]interface{} {
	if slug == relayOperatorTenant && p.shard() {
		return p.shardStatus()
	}
	name, why := p.mailName(slug)
	var origins []string
	if name != "" {
		origins, why = p.originsOf(slug)
	}
	shard := p.shard()
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
	st := p.tenantStats(slug)
	// A shard counts each tenant's own; a self-hosted relay's one tenant has
	// every connection.
	active := p.active
	if shard {
		active = p.perTenant[slug]
	}
	refused := count(st.refused)
	last, lastAt := st.lastRefusal, st.lastRefusalAt
	if !shard {
		refused += count(p.refused)
		if p.lastRefusalAt.After(lastAt) {
			last, lastAt = p.lastRefusal, p.lastRefusalAt
		}
	}
	out := map[string]interface{}{
		"listening":  map[string]bool{"993": p.bound["993"], "465": p.bound["465"]},
		"origins":    len(origins),
		"active":     active,
		"spliced_1h": count(st.spliced),
		"refused_1h": refused,
	}
	if name != "" {
		out["mail_name"] = name
	}
	if len(origins) == 0 {
		out["off_because"] = why
	}
	if !st.checkedAt.IsZero() {
		reaches := map[string]interface{}{}
		for role, ok := range st.reaches {
			reaches[role] = map[string]interface{}{"port": p.targets[role], "answers": ok}
		}
		out["reaches_server"] = reaches
		out["reaches_server_utc"] = st.checkedAt.UTC().Format(time.RFC3339)
	}
	if last != "" {
		out["last_refusal"] = last
		out["last_refusal_utc"] = lastAt.UTC().Format(time.RFC3339)
	}
	for k, v := range p.s.mailAppsCertStatus(slug) {
		out[k] = v
	}
	return out
}

// shardStatus is the operator's mail_apps group on a shard: the pass-through
// as a whole, which no tenant sees. Each tenant's name, origins count and
// last hour by slug; the refusals made before a tenant was known (no hello, a
// name no tenant has, the total and per-source caps), which reach no tenant's
// ping; and the connections open against the total cap.
func (p *mailAppsPass) shardStatus() map[string]interface{} {
	type slot struct {
		name    string
		origins int
	}
	slots := map[string]slot{}
	slugs, _ := listTenantSlugs(p.s.paths.tenantsDir())
	for _, slug := range slugs {
		name, _ := p.mailName(slug)
		n := 0
		if name != "" {
			origins, _ := p.originsOf(slug)
			n = len(origins)
		}
		slots[slug] = slot{name: name, origins: n}
	}
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
	tenants := map[string]interface{}{}
	named, on, spliced, refused := 0, 0, 0, 0
	for slug, sl := range slots {
		row := map[string]interface{}{"origins": sl.origins, "active": p.perTenant[slug], "spliced_1h": 0, "refused_1h": 0}
		if sl.name != "" {
			row["mail_name"] = sl.name
			named++
		}
		if sl.origins > 0 {
			on++
		}
		if st := p.stats[slug]; st != nil {
			row["spliced_1h"], row["refused_1h"] = count(st.spliced), count(st.refused)
			spliced += count(st.spliced)
			refused += count(st.refused)
		}
		tenants[slug] = row
	}
	out := map[string]interface{}{
		"listening":          map[string]bool{"993": p.bound["993"], "465": p.bound["465"]},
		"active":             p.active,
		"total_cap":          p.totalCap,
		"tenants_named":      named,
		"tenants_on":         on,
		"spliced_1h":         spliced,
		"refused_1h":         refused + count(p.refused),
		"refused_unknown_1h": count(p.refused),
		"tenants":            tenants,
	}
	if p.lastRefusal != "" {
		out["last_refusal"] = p.lastRefusal
		out["last_refusal_utc"] = p.lastRefusalAt.UTC().Format(time.RFC3339)
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
