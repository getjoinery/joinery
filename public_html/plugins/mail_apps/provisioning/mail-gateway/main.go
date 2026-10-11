// mail-gateway lets Thunderbird and any other IMAP/SMTP mail program read and
// send Joinery mail. It holds the programs' long connections (IMAP on 993,
// SMTP submission on 465, implicit TLS only) and turns every command into a
// call to the site's mail_apps/mail_* API actions over loopback. It keeps no
// mail and no credentials of its own: every call carries the app password the
// program presented, and the site decides everything
// (specs/mail_apps_plugin.md § Architecture).
package main

import (
	"flag"
	"fmt"
	"log"
	"net"
	"os"
	"os/signal"
	"runtime/debug"
	"syscall"
	"time"
)

// Version is stamped at build time (-ldflags "-X main.Version=...").
var Version = "1.0.0"

func main() {
	configDir := flag.String("config-dir", "/etc/joinery/mail-gateway", "directory holding sites/<site>.json and sites/<site>.enabled")
	imapListen := flag.String("imap-listen", ":993", "IMAP listen address (implicit TLS); empty disables IMAP")
	smtpListen := flag.String("smtp-listen", ":465", "SMTP submission listen address (implicit TLS); empty disables submission")
	smtpDomain := flag.String("smtp-domain", "", "name in the SMTP greeting (default: the only site's hostname, else this host's name)")
	maxPerIP := flag.Int("max-conns-per-ip", 100, "concurrent connections allowed from one client address (IPv6: per /64)")
	maxPerCred := flag.Int("max-conns-per-credential", 20, "concurrent signed-in connections allowed for one app password")
	maxConns := flag.Int("max-conns", 2000, "concurrent connections allowed in total (each costs ~40 KiB besides the budgets)")
	inflightBytes := flag.Int64("inflight-bytes", 144<<20, "bytes of upstream answers and submitted messages held at once, across all connections")
	cacheBytes := flag.Int64("body-cache-bytes", 48<<20, "bytes of recently fetched messages kept for re-reads, across all connections (one connection keeps a quarter, at least 32 MiB)")
	credHeldBytes := flag.Int64("credential-held-bytes", 48<<20, "bytes of fetched messages one app password's connections may hold waiting to be written, together (at least 32 MiB)")
	folderBytes := flag.Int64("folder-list-bytes", 96<<20, "bytes of open folders' message lists (32 bytes a message), across all connections")
	memLimit := flag.Int64("memory-limit", 400<<20, "Go soft memory limit in bytes (GOMEMLIMIT); keep under the unit's MemoryMax. 0 leaves it alone")
	showVersion := flag.Bool("version", false, "print the version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println("joinery-mail-gateway " + Version)
		return
	}

	log.SetFlags(log.LstdFlags | log.LUTC)
	inflight = NewBudget(*inflightBytes)
	bodies.setCap(*cacheBytes)
	folderLists = NewBudget(*folderBytes)
	credentialHeld = *credHeldBytes
	if credentialHeld < 32<<20 {
		credentialHeld = 32 << 20
	}
	if *memLimit > 0 {
		debug.SetMemoryLimit(*memLimit)
	}
	logf("starting joinery-mail-gateway %s (config %s)", Version, *configDir)

	reg := NewRegistry(*configDir)
	if err := reg.Reload(); err != nil {
		logf("config: %v", err)
	}
	if reg.Count() == 0 {
		logf("config: no enabled site yet; connections will be refused until one is enabled and SIGHUP is sent")
	}

	limiter := NewConnLimiter(*maxPerIP, *maxConns)
	gw := NewGateway(reg, limiter, *maxPerCred)
	logf("limits: %d connections (%d per address, %d per credential); in-flight %d MiB, message cache %d MiB, folder lists %d MiB, memory limit %d MiB",
		*maxConns, *maxPerIP, *maxPerCred, *inflightBytes>>20, *cacheBytes>>20, *folderBytes>>20, *memLimit>>20)

	domain := *smtpDomain
	if domain == "" {
		domain = reg.DefaultDomain()
	}

	errc := make(chan error, 4)
	var closers []func()
	if *imapListen != "" {
		ln, err := listenDual(*imapListen)
		if err != nil {
			log.Fatalf("imap listen %s: %v", *imapListen, err)
		}
		srv := gw.NewIMAPServer()
		tlsLn := gw.TLSListener(ln, "imap")
		closers = append(closers, func() { srv.Close() })
		logf("imap listening on %s", ln.Addr())
		go func() { errc <- fmt.Errorf("imap: %w", srv.Serve(tlsLn)) }()
	}
	if *smtpListen != "" {
		ln, err := listenDual(*smtpListen)
		if err != nil {
			log.Fatalf("smtp listen %s: %v", *smtpListen, err)
		}
		srv := gw.NewSMTPServer(domain)
		tlsLn := gw.TLSListener(ln, "smtp")
		closers = append(closers, func() { srv.Close() })
		logf("smtp submission listening on %s", ln.Addr())
		go func() { errc <- fmt.Errorf("smtp: %w", srv.Serve(tlsLn)) }()
	}
	if len(closers) == 0 {
		log.Fatalf("nothing to listen on: both -imap-listen and -smtp-listen are empty")
	}

	sigs := make(chan os.Signal, 4)
	signal.Notify(sigs, syscall.SIGHUP, syscall.SIGINT, syscall.SIGTERM)
	for {
		select {
		case sig := <-sigs:
			if sig == syscall.SIGHUP {
				if err := gw.Reload(); err != nil {
					logf("reload: %v (kept the previous configuration)", err)
				} else {
					logf("reload: %d site(s) enabled", reg.Count())
				}
				continue
			}
			logf("signal %v: shutting down", sig)
			for _, c := range closers {
				c()
			}
			time.Sleep(200 * time.Millisecond)
			return
		case err := <-errc:
			log.Fatalf("listener stopped: %v", err)
		}
	}
}

// listenDual listens on TCP for both IPv4 and IPv6 ("tcp" on a wildcard
// address is dual-stack on Linux unless net.ipv6.bindv6only is set, in which
// case a second IPv4 listener is opened beside it).
func listenDual(addr string) (net.Listener, error) {
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return nil, err
	}
	if host != "" {
		return net.Listen("tcp", addr)
	}
	ln6, err6 := net.Listen("tcp6", net.JoinHostPort("::", port))
	if err6 != nil {
		// No IPv6 on this host: IPv4 alone.
		return net.Listen("tcp4", net.JoinHostPort("0.0.0.0", port))
	}
	if v6only() {
		ln4, err := net.Listen("tcp4", net.JoinHostPort("0.0.0.0", port))
		if err != nil {
			ln6.Close()
			return nil, err
		}
		return newMultiListener(ln6, ln4), nil
	}
	return ln6, nil
}

func v6only() bool {
	b, err := os.ReadFile("/proc/sys/net/ipv6/bindv6only")
	return err == nil && len(b) > 0 && b[0] == '1'
}

func logf(format string, args ...any) {
	log.Printf(format, args...)
}
