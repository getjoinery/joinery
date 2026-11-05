// joinery-unseal holds members' vault keys outside the PHP pool
// (specs/unseal_daemon.md). PHP opens a window by presenting an unlocker and
// gets back a handle; the handle opens per-item keys while the window lives,
// and nothing on the socket returns the vault secret except wrapped, in the
// request that presented an unlocker for it.
//
//	joinery-unseal --socket PATH --allow-uid UID [options]   serve
//	joinery-unseal ping --socket PATH [--timeout SECONDS]    is it answering?
//	joinery-unseal --selftest                                 the crypto, in-process
//	joinery-unseal --version
//
// Serving: no network, no database, no configuration beyond argv, and no file
// opened after startup but the hold marker read once at startup. Signals are
// root's controls (the sentinel's): SIGUSR1 drops every window and holds,
// refusing every open; SIGUSR2 lifts the hold. SIGTERM drops every window and
// exits.
package main

import (
	"flag"
	"fmt"
	"log"
	"net"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"
)

func main() {
	log.SetFlags(0)
	log.SetPrefix("joinery-unseal: ")
	args := os.Args[1:]
	if len(args) > 0 {
		switch args[0] {
		case "ping":
			os.Exit(runPing(args[1:]))
		case "--selftest", "selftest":
			os.Exit(runSelftest())
		case "--version", "version":
			fmt.Println(daemonVersion)
			os.Exit(0)
		}
	}
	os.Exit(runServe(args))
}

func runServe(args []string) int {
	fs := flag.NewFlagSet("joinery-unseal", flag.ContinueOnError)
	socket := fs.String("socket", "", "the socket path (required)")
	allowUID := fs.Int("allow-uid", -1, "the one uid served: the PHP pool user (required)")
	holdFile := fs.String("hold-file", "/run/joinery-unseal/hold", "start held when this file exists")
	maxWindow := fs.Int64("max-window-seconds", 7*24*3600, "the ceiling on any window's absolute cap")
	maxWindows := fs.Int("max-windows", 10000, "the most windows held at once")
	maxFrame := fs.Int64("max-frame-bytes", 101<<20, "the largest request body (a held Direct part, whole)")
	budget := fs.Int64("memory-budget", 256<<20, "request body bytes in flight across all connections")
	maxConns := fs.Int("max-conns", 64, "connections served at once")
	unlocked := fs.Bool("unlocked-memory", false, "serve without locked memory when the limit is bounded (test suites only)")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	if *socket == "" || *allowUID < 0 || *maxWindow <= 0 || *maxWindows <= 0 || *maxFrame <= 0 ||
		*budget < *maxFrame || *maxConns <= 0 || fs.NArg() != 0 {
		fs.Usage()
		return 2
	}
	if os.Geteuid() == 0 {
		log.Print("refusing to serve as root: the keys belong in their own uid")
		return 1
	}

	if err := setNotDumpable(); err != nil {
		log.Printf("cannot make the process non-dumpable: %v", err)
		return 1
	}
	locked := true
	if err := lockMemory(); err != nil {
		if !*unlocked {
			log.Printf("cannot lock memory: %v", err)
			return 1
		}
		locked = false
		log.Printf("serving WITHOUT locked memory (--unlocked-memory): %v", err)
	}

	st := newStore(*maxWindows, time.Duration(*maxWindow)*time.Second)
	if _, err := os.Stat(*holdFile); err == nil {
		st.setHeld(true)
		log.Printf("starting held: %s exists", *holdFile)
	}
	d := &daemon{store: st, started: time.Now(), memoryLocked: locked}

	l, own, err := listen(*socket)
	if err != nil {
		log.Printf("cannot listen on %s: %v", *socket, err)
		return 1
	}
	srv := newServer(serverConfig{
		socket: *socket, allowUID: *allowUID, maxConns: *maxConns, maxFrameBytes: *maxFrame,
		memoryBudget: *budget, idleTimeout: 60 * time.Second, frameTimeout: 30 * time.Second,
	}, d)

	sigs := make(chan os.Signal, 4)
	signal.Notify(sigs, syscall.SIGUSR1, syscall.SIGUSR2, syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP)
	go func() {
		for sig := range sigs {
			switch sig {
			case syscall.SIGUSR1:
				n := st.dropAndHold()
				log.Printf("SIGUSR1: dropped %d windows and holding; every open is refused", n)
			case syscall.SIGUSR2:
				st.setHeld(false)
				log.Print("SIGUSR2: hold lifted")
			case syscall.SIGHUP:
				// Nothing to reload: there is no configuration beyond argv.
			default:
				n := st.dropAndHold()
				log.Printf("%v: dropped %d windows, exiting", sig, n)
				l.Close()
				return
			}
		}
	}()

	notify := newNotifier()
	go func() {
		watchdog := notify.watchdogInterval()
		tick := time.NewTicker(time.Second)
		defer tick.Stop()
		lastFed := time.Now()
		for range tick.C {
			st.sweep() // takes the store's lock: a fed watchdog means the store is not wedged
			if watchdog > 0 && time.Since(lastFed) >= watchdog {
				notify.send("WATCHDOG=1")
				lastFed = time.Now()
			}
		}
	}()

	log.Printf("%s serving %s for uid %d (memory locked: %v, held: %v, last peer pid reported on any recovered fault)",
		daemonVersion, *socket, *allowUID, locked, st.isHeld())
	notify.send("READY=1")
	err = srv.serve(l)
	own.release()
	if err != nil {
		log.Printf("serve: %v", err)
		return 1
	}
	return 0
}

// notifier speaks systemd's sd_notify, when the unit set NOTIFY_SOCKET.
type notifier struct {
	addr string
}

func newNotifier() *notifier {
	return &notifier{addr: os.Getenv("NOTIFY_SOCKET")}
}

func (n *notifier) send(state string) {
	if n.addr == "" {
		return
	}
	name := n.addr
	if name[0] == '@' {
		name = "\x00" + name[1:]
	}
	c, err := net.DialUnix("unixgram", nil, &net.UnixAddr{Name: name, Net: "unixgram"})
	if err != nil {
		return
	}
	c.Write([]byte(state))
	c.Close()
}

// watchdogInterval is half of WATCHDOG_USEC when the unit asked for one.
func (n *notifier) watchdogInterval() time.Duration {
	usec, err := strconv.ParseInt(os.Getenv("WATCHDOG_USEC"), 10, 64)
	if err != nil || usec <= 0 || n.addr == "" {
		return 0
	}
	return time.Duration(usec) * time.Microsecond / 2
}
