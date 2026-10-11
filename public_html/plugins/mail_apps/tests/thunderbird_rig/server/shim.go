package main

// The shim sits between Thunderbird's TCP connection and go-imap. It:
//   - logs every line both ways (literals logged by size, client literals
//     logged in full up to 8 KB so APPENDed headers are visible);
//   - adds extra capabilities go-imap's server lacks (ID, CONDSTORE,
//     COMPRESS=DEFLATE) and answers / emulates them;
//   - masks LOGIN / AUTHENTICATE credentials in the log.
//
// CONDSTORE emulation is deliberately crude: one global HIGHESTMODSEQ that
// rises on every change; every SELECT gets "* OK [HIGHESTMODSEQ n]";
// FETCH ... (CHANGEDSINCE n) has the modifier stripped, so the answer is a
// superset (every message's flags). That is enough to see what Thunderbird
// sends, not to test a correct CONDSTORE server.

import (
	"bufio"
	"compress/flate"
	"fmt"
	"io"
	"net"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
)

type pipeListener struct{ ch chan net.Conn }

func newPipeListener() *pipeListener { return &pipeListener{ch: make(chan net.Conn)} }
func (l *pipeListener) Accept() (net.Conn, error) {
	c, ok := <-l.ch
	if !ok {
		return nil, io.EOF
	}
	return c, nil
}
func (l *pipeListener) Close() error   { return nil }
func (l *pipeListener) Addr() net.Addr { return &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1)} }

var connSeq int64

func serveShim(ln net.Listener, inner *pipeListener, lg *logFile, extraCaps []string) {
	for {
		c, err := ln.Accept()
		if err != nil {
			lg.Printf("accept: %v", err)
			return
		}
		id := atomic.AddInt64(&connSeq, 1)
		toServer, serverSide := net.Pipe()
		inner.ch <- serverSide
		sh := &shim{id: id, client: c, server: toServer, lg: lg, extra: extraCaps, cw: c}
		lg.Printf("[c%d] connect from %s", id, c.RemoteAddr())
		go sh.clientToServer()
		go sh.serverToClient()
	}
}

type shim struct {
	id     int64
	client net.Conn
	server net.Conn
	lg     *logFile
	extra  []string

	wmu sync.Mutex
	cw  io.Writer     // writer to the client (plain or deflate)
	fw  *flate.Writer // non-nil once COMPRESS is active

	smu           sync.Mutex
	condSelectTag string // tag of the SELECT/EXAMINE in progress
	condEnabled   bool
}

func (s *shim) logf(dir, format string, args ...interface{}) {
	s.lg.Printf("[c%d] %s %s", s.id, dir, fmt.Sprintf(format, args...))
}

func (s *shim) writeClient(b []byte) error {
	s.wmu.Lock()
	defer s.wmu.Unlock()
	if _, err := s.cw.Write(b); err != nil {
		return err
	}
	if s.fw != nil {
		return s.fw.Flush()
	}
	return nil
}

var literalRe = regexp.MustCompile(`\{(\d+)\+?\}\r\n$`)
var changedSinceRe = regexp.MustCompile(`(?i)\s*\(CHANGEDSINCE \d+\)`)
var unchangedSinceRe = regexp.MustCompile(`(?i)\s*\(UNCHANGEDSINCE \d+\)`)
var condSelectRe = regexp.MustCompile(`(?i)^(\S+ (?:SELECT|EXAMINE) .*?)\s*\(CONDSTORE\)\r\n$`)

func show(line string) string { return strings.TrimRight(line, "\r\n") }

func maskCreds(line string) string {
	f := strings.Fields(line)
	if len(f) >= 4 && strings.EqualFold(f[1], "LOGIN") {
		return f[0] + " LOGIN " + f[2] + " ********"
	}
	if len(f) >= 4 && strings.EqualFold(f[1], "AUTHENTICATE") {
		return f[0] + " AUTHENTICATE " + f[2] + " ********"
	}
	return show(line)
}

func (s *shim) clientToServer() {
	defer s.server.Close()
	defer s.client.Close()
	var r *bufio.Reader = bufio.NewReader(s.client)
	cont := false     // inside a command split by literals
	authNext := false // next line is a SASL response
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			s.logf("--", "client closed (%v)", err)
			return
		}
		if authNext {
			s.logf("C:", "<SASL response masked>")
			authNext = false
			if _, err := io.WriteString(s.server, line); err != nil {
				return
			}
			continue
		}
		if !cont {
			f := strings.Fields(line)
			cmd := ""
			if len(f) >= 2 {
				cmd = strings.ToUpper(f[1])
				if cmd == "UID" && len(f) >= 3 {
					cmd += " " + strings.ToUpper(f[2])
				}
			}
			switch {
			case cmd == "ID" && *flagID:
				s.logf("C:", "%s", show(line))
				resp := fmt.Sprintf("* ID (\"name\" \"joinery-rig\" \"version\" \"0\")\r\n%s OK ID completed\r\n", f[0])
				s.logf("S:", "%s", show(resp))
				s.writeClient([]byte(resp))
				continue
			case cmd == "COMPRESS" && *flagCompress:
				s.logf("C:", "%s", show(line))
				resp := f[0] + " OK DEFLATE active\r\n"
				s.logf("S:", "%s", show(resp))
				s.wmu.Lock()
				io.WriteString(s.cw, resp)
				fw, _ := flate.NewWriter(s.client, flate.DefaultCompression)
				s.fw, s.cw = fw, fw
				s.wmu.Unlock()
				r = bufio.NewReader(flate.NewReader(r))
				s.logf("--", "COMPRESS=DEFLATE now active both ways")
				continue
			case cmd == "AUTHENTICATE" && len(f) == 3:
				authNext = true // SASL without initial response
			}
			if *flagCondstore {
				orig := line
				if m := condSelectRe.FindStringSubmatch(line); m != nil {
					line = m[1] + "\r\n"
				}
				// RFC 7162 3.1.2.1: a CONDSTORE server reports HIGHESTMODSEQ on
				// every SELECT/EXAMINE, asked for or not. Thunderbird only starts
				// using CONDSTORE after it has seen one.
				if cmd == "SELECT" || cmd == "EXAMINE" {
					s.smu.Lock()
					s.condSelectTag = f[0]
					s.smu.Unlock()
				}
				if cmd == "ENABLE" && strings.Contains(strings.ToUpper(line), "CONDSTORE") {
					s.smu.Lock()
					s.condEnabled = true
					s.smu.Unlock()
				}
				if strings.HasSuffix(cmd, "FETCH") {
					line = changedSinceRe.ReplaceAllString(line, "")
					line = strings.Replace(line, " MODSEQ", "", 1)
				}
				if strings.HasSuffix(cmd, "STORE") {
					line = unchangedSinceRe.ReplaceAllString(line, "")
				}
				if line != orig {
					s.logf("C:", "%s", maskCreds(orig))
					s.logf("--", "(condstore emulation forwarded as: %s)", show(line))
				} else {
					s.logf("C:", "%s", maskCreds(line))
				}
			} else {
				s.logf("C:", "%s", maskCreds(line))
			}
		} else {
			s.logf("C:", "%s", show(line))
		}
		if _, err := io.WriteString(s.server, line); err != nil {
			return
		}
		cont = false
		if m := literalRe.FindStringSubmatch(line); m != nil {
			n, _ := strconv.Atoi(m[1])
			buf := make([]byte, n)
			if _, err := io.ReadFull(r, buf); err != nil {
				return
			}
			shown := string(buf)
			if len(shown) > 8192 {
				shown = shown[:8192] + fmt.Sprintf("\n...[%d more bytes]", n-8192)
			}
			s.logf("C:", "<literal %d bytes>\n%s\n<end literal>", n, shown)
			if _, err := s.server.Write(buf); err != nil {
				return
			}
			cont = true
		}
	}
}

func (s *shim) serverToClient() {
	defer s.client.Close()
	r := bufio.NewReader(s.server)
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			s.logf("--", "server closed (%v)", err)
			return
		}
		up := strings.ToUpper(line)
		if len(s.extra) > 0 && (strings.HasPrefix(up, "* CAPABILITY ") || strings.Contains(up, "[CAPABILITY ")) {
			line = strings.Replace(line, "IMAP4rev1", "IMAP4rev1 "+strings.Join(s.extra, " "), 1)
		}
		if *flagCondstore {
			s.smu.Lock()
			if strings.HasPrefix(up, "* ENABLED") && s.condEnabled {
				line = strings.TrimRight(line, "\r\n") + " CONDSTORE\r\n"
			}
			if s.condSelectTag != "" && strings.HasPrefix(line, s.condSelectTag+" OK") {
				inj := fmt.Sprintf("* OK [HIGHESTMODSEQ %d] Highest\r\n", rig.highestModSeq())
				s.logf("S:", "%s   (injected by condstore emulation)", show(inj))
				s.writeClient([]byte(inj))
				s.condSelectTag = ""
			}
			s.smu.Unlock()
		}
		s.logf("S:", "%s", show(line))
		if err := s.writeClient([]byte(line)); err != nil {
			return
		}
		for {
			m := literalRe.FindStringSubmatch(line)
			if m == nil {
				break
			}
			n, _ := strconv.Atoi(m[1])
			buf := make([]byte, n)
			if _, err := io.ReadFull(r, buf); err != nil {
				return
			}
			preview := string(buf)
			if len(preview) > 300 {
				preview = preview[:300] + "..."
			}
			s.logf("S:", "<literal %d bytes> %q", n, preview)
			if err := s.writeClient(buf); err != nil {
				return
			}
			line, err = r.ReadString('\n')
			if err != nil {
				return
			}
			s.logf("S:", "%s", show(line))
			if err := s.writeClient([]byte(line)); err != nil {
				return
			}
		}
	}
}
