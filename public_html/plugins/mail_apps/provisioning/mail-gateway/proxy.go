package main

// Connections passed through a mail relay (specs/mail_apps_plugin.md § Where
// programs connect). A relay-fronted deployment's mail programs reach the
// relay's name; the relay splices their TLS, unopened, to this gateway and
// first writes a PROXY protocol v1 line naming the program's address. The line
// is believed only from an address listed in <config-dir>/proxy_from (the
// relay's), so the per-address caps, the sign-in throttle and the address the
// site is told all name the program. A site marked via_relay is served only to
// such a connection, or to loopback (the host report, the admin page's round
// trip): anyone reaching this server's 993 directly gets no handshake, so a
// scan cannot find a gateway holding the relay's certificate.

import (
	"bufio"
	"errors"
	"io"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

const (
	proxyHeaderTimeout = 5 * time.Second
	proxyLineMax       = 107 // the v1 maximum, CRLF included
)

// loadProxyFrom reads <dir>/proxy_from: one address a line; '#' comments and
// blank lines ignored. A missing file trusts nobody.
func loadProxyFrom(dir string) (map[string]bool, []string) {
	out := map[string]bool{}
	var problems []string
	data, err := os.ReadFile(filepath.Join(dir, "proxy_from"))
	if err != nil {
		return out, nil
	}
	for _, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		a, err := netip.ParseAddr(line)
		if err != nil {
			problems = append(problems, "proxy_from: "+line+" is not an address")
			continue
		}
		out[a.Unmap().String()] = true
	}
	return out, problems
}

// TrustsProxy: whether a connection from ip carries a PROXY line.
func (r *Registry) TrustsProxy(ip string) bool {
	a, err := netip.ParseAddr(ip)
	if err != nil {
		return false
	}
	return r.cur.Load().proxyFrom[a.Unmap().String()]
}

// proxiedConn is a relay's connection once its PROXY line is read: reads go
// on through the buffer (the program's TLS hello may already be in it), and
// RemoteAddr is the program's.
type proxiedConn struct {
	net.Conn
	r      *bufio.Reader
	remote net.Addr
}

func (c *proxiedConn) Read(p []byte) (int, error) { return c.r.Read(p) }
func (c *proxiedConn) RemoteAddr() net.Addr       { return c.remote }

// readProxyHeader reads the v1 line from a relay's connection. UNKNOWN keeps
// the relay's own address; anything malformed is refused.
// errRelayCheck: a relay connected and closed without sending a byte. The
// relay checks once a minute that it reaches this server on each port
// (relay-sealer checkServer); that is not a refused program, so it is not
// logged.
var errRelayCheck = errors.New("closed before sending anything (the relay's reachability check)")

func readProxyHeader(c net.Conn) (*proxiedConn, error) {
	_ = c.SetReadDeadline(time.Now().Add(proxyHeaderTimeout))
	defer c.SetReadDeadline(time.Time{})
	br := bufio.NewReaderSize(c, 512)
	var line []byte
	for len(line) < proxyLineMax {
		b, err := br.ReadByte()
		if err != nil {
			if len(line) == 0 && errors.Is(err, io.EOF) {
				return nil, errRelayCheck
			}
			return nil, err
		}
		line = append(line, b)
		if b == '\n' {
			break
		}
	}
	text := string(line)
	if !strings.HasSuffix(text, "\r\n") {
		return nil, errors.New("no PROXY line")
	}
	f := strings.Fields(strings.TrimSuffix(text, "\r\n"))
	if len(f) >= 2 && f[0] == "PROXY" && f[1] == "UNKNOWN" {
		return &proxiedConn{Conn: c, r: br, remote: c.RemoteAddr()}, nil
	}
	if len(f) != 6 || f[0] != "PROXY" || (f[1] != "TCP4" && f[1] != "TCP6") {
		return nil, errors.New("not a PROXY v1 line")
	}
	src, err := netip.ParseAddr(f[2])
	if err != nil || (f[1] == "TCP4") != src.Is4() {
		return nil, errors.New("PROXY source is not an address of its family")
	}
	port, err := strconv.Atoi(f[4])
	if err != nil || port < 0 || port > 65535 {
		return nil, errors.New("PROXY source port")
	}
	return &proxiedConn{Conn: c, r: br, remote: &net.TCPAddr{IP: src.AsSlice(), Port: port}}, nil
}

// mayServeRelayedSite: a via_relay site answers a connection that came through
// a listed relay, or one from this machine.
func mayServeRelayedSite(c net.Conn) bool {
	if lc, ok := c.(*limitedConn); ok && lc.relayed {
		return true
	}
	a, err := netip.ParseAddr(remoteIP(c.RemoteAddr()))
	return err == nil && a.Unmap().IsLoopback()
}
