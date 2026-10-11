package main

import (
	"crypto/tls"
	"errors"
	"net"
	"net/netip"
	"sync"
	"time"
)

// autologout is how long a connection may sit without sending anything
// (RFC 3501 § 5.4: at least 30 minutes; IDLE clients re-issue within 29).
var autologout = 30 * time.Minute

// sourceKey is the unit limits count by: the address for IPv4, its /64 for
// IPv6 (one household or one host's whole allocation), matching the site's
// app_access_auth bucket.
func sourceKey(ip string) string {
	addr, err := netip.ParseAddr(ip)
	if err != nil {
		return ip
	}
	addr = addr.Unmap()
	if addr.Is4() {
		return addr.String()
	}
	p, err := addr.Prefix(64)
	if err != nil {
		return addr.String()
	}
	return p.String()
}

// remoteIP is the client's address as text, IPv4-mapped IPv6 unwrapped.
func remoteIP(a net.Addr) string {
	if ta, ok := a.(*net.TCPAddr); ok {
		if addr, ok := netip.AddrFromSlice(ta.IP); ok {
			return addr.Unmap().String()
		}
		return ta.IP.String()
	}
	host, _, err := net.SplitHostPort(a.String())
	if err != nil {
		return a.String()
	}
	return host
}

// ConnLimiter bounds concurrent connections per source and in total.
type ConnLimiter struct {
	mu       sync.Mutex
	perIP    int
	total    int
	bySource map[string]int
	count    int
}

func NewConnLimiter(perIP, total int) *ConnLimiter {
	return &ConnLimiter{perIP: perIP, total: total, bySource: map[string]int{}}
}

func (l *ConnLimiter) acquire(key string) bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.count >= l.total || l.bySource[key] >= l.perIP {
		return false
	}
	l.count++
	l.bySource[key]++
	return true
}

func (l *ConnLimiter) release(key string) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.count--
	if l.bySource[key]--; l.bySource[key] <= 0 {
		delete(l.bySource, key)
	}
}

// Count is the number of open connections.
func (l *ConnLimiter) Count() int {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.count
}

// limitedConn is the raw TCP connection under TLS: it releases its limiter
// slot once, and caps every read deadline at the autologout.
type limitedConn struct {
	net.Conn
	once    sync.Once
	release func()
}

func (c *limitedConn) Close() error {
	err := c.Conn.Close()
	c.once.Do(c.release)
	return err
}

func (c *limitedConn) SetReadDeadline(t time.Time) error {
	if max := time.Now().Add(autologout); t.IsZero() || t.After(max) {
		t = max
	}
	return c.Conn.SetReadDeadline(t)
}

func (c *limitedConn) SetDeadline(t time.Time) error {
	if err := c.SetReadDeadline(t); err != nil {
		return err
	}
	return c.Conn.SetWriteDeadline(t)
}

// tlsListener accepts raw connections, applies the limits, and hands out
// server-side *tls.Conn (so the IMAP and SMTP libraries see TLS and allow
// sign-in). The handshake itself happens on the connection's own goroutine.
type tlsListener struct {
	net.Listener
	cfg     *tls.Config
	limiter *ConnLimiter
	proto   string
}

func (l *tlsListener) Accept() (net.Conn, error) {
	for {
		c, err := l.Listener.Accept()
		if err != nil {
			return nil, err
		}
		ip := remoteIP(c.RemoteAddr())
		key := sourceKey(ip)
		if !l.limiter.acquire(key) {
			logf("%s: refused connection from %s: too many connections", l.proto, ip)
			c.Close()
			continue
		}
		lc := &limitedConn{Conn: c, release: func() { l.limiter.release(key) }}
		return tls.Server(lc, l.cfg), nil
	}
}

// handshake completes a server connection's TLS handshake within a deadline,
// so the site can be chosen by SNI before the greeting is written.
func handshake(c net.Conn) (*tls.Conn, error) {
	tc, ok := c.(*tls.Conn)
	if !ok {
		return nil, errors.New("not a TLS connection")
	}
	tc.SetDeadline(time.Now().Add(20 * time.Second))
	err := tc.Handshake()
	tc.SetDeadline(time.Time{})
	return tc, err
}

// AuthThrottle slows a source down after failed sign-ins: each failure in the
// last 15 minutes adds a second before the answer (up to 10), and past a
// ceiling the source is refused without asking the site at all. The site has
// its own limits (app_access_auth); this keeps a guesser from costing it work.
type AuthThrottle struct {
	mu       sync.Mutex
	failures map[string][]time.Time
	window   time.Duration
	ceiling  int
}

func NewAuthThrottle() *AuthThrottle {
	return &AuthThrottle{failures: map[string][]time.Time{}, window: 15 * time.Minute, ceiling: 60}
}

func (t *AuthThrottle) recent(key string, now time.Time) []time.Time {
	list := t.failures[key]
	i := 0
	for i < len(list) && now.Sub(list[i]) > t.window {
		i++
	}
	list = list[i:]
	if len(list) == 0 {
		delete(t.failures, key)
	} else {
		t.failures[key] = list
	}
	return list
}

// Blocked reports whether the source is past the ceiling.
func (t *AuthThrottle) Blocked(ip string) bool {
	t.mu.Lock()
	defer t.mu.Unlock()
	return len(t.recent(sourceKey(ip), time.Now())) >= t.ceiling
}

// Failed records a failure and answers how long to wait before replying.
func (t *AuthThrottle) Failed(ip string) time.Duration {
	t.mu.Lock()
	defer t.mu.Unlock()
	key := sourceKey(ip)
	now := time.Now()
	list := append(t.recent(key, now), now)
	t.failures[key] = list
	if len(t.failures) > 100000 { // bound memory under a wide spray
		for k := range t.failures {
			delete(t.failures, k)
			if len(t.failures) <= 50000 {
				break
			}
		}
	}
	d := time.Duration(len(list)) * time.Second
	if d > 10*time.Second {
		d = 10 * time.Second
	}
	return d
}

// multiListener merges two listeners (IPv6 and IPv4 when the host keeps them
// apart).
type multiListener struct {
	lns   []net.Listener
	conns chan acceptResult
	once  sync.Once
	done  chan struct{}
}

type acceptResult struct {
	c   net.Conn
	err error
}

func newMultiListener(lns ...net.Listener) *multiListener {
	m := &multiListener{lns: lns, conns: make(chan acceptResult), done: make(chan struct{})}
	for _, ln := range lns {
		go func(ln net.Listener) {
			for {
				c, err := ln.Accept()
				select {
				case m.conns <- acceptResult{c, err}:
				case <-m.done:
					if c != nil {
						c.Close()
					}
					return
				}
				if err != nil {
					return
				}
			}
		}(ln)
	}
	return m
}

func (m *multiListener) Accept() (net.Conn, error) {
	select {
	case r := <-m.conns:
		return r.c, r.err
	case <-m.done:
		return nil, net.ErrClosed
	}
}

func (m *multiListener) Close() error {
	m.once.Do(func() { close(m.done) })
	var first error
	for _, ln := range m.lns {
		if err := ln.Close(); err != nil && first == nil {
			first = err
		}
	}
	return first
}

func (m *multiListener) Addr() net.Addr { return m.lns[0].Addr() }
