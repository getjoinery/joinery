// sandbox-host runs a site's sandboxed plugins (specs/plugin_tiers.md WP7).
//
// A sandboxed plugin is a WebAssembly module and a manifest. Core never runs a
// module itself: for each call (a form on the plugin's page, a signal, a
// scheduled run) it sends this host one request over a local Unix socket and
// waits for the answer, bounded. The request carries everything the call may
// use, so the host keeps no configuration, no queue and no clock, and reads no
// database:
//
//	POST /call   {call_id, plugin, module, export, input, limits, settings, outbound_hosts, api}
//	          -> {ok, output, error, timed_out, duration_ms, calls}
//	GET /health  -> {ok, version, calls_running}
//
// Each call gets a fresh instance of the module, run by wazero's interpreter
// (never its compiler, so the process can keep MemoryDenyWriteExecute) under
// the call's memory cap and deadline, and thrown away afterwards. The module
// reaches nothing but the three doors this host gives it (doors.go): the
// site's API with the plugin's own credential, HTTPS to the hosts an admin
// approved through a guard that refuses internal addresses (egress.go), and
// a log line. See docs/sandboxed_plugins.md.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"sync/atomic"
	"syscall"
	"time"
)

const version = "1.0.0"

// The most of a request this host reads: an envelope is a call's input and
// its settings, never a file.
const maxRequestBytes = 2 << 20

func main() {
	socket := flag.String("socket", "", "Unix socket to listen on, e.g. {site root}/run/sandbox-host/host.sock")
	pluginsDir := flag.String("plugins-dir", "", "the site's plugins directory; a module outside it is refused")
	maxCalls := flag.Int("max-calls", 4, "calls run at once; one more is refused as busy")
	showVersion := flag.Bool("version", false, "print the version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println(version)
		return
	}
	if *socket == "" || *pluginsDir == "" {
		fmt.Fprintln(os.Stderr, "sandbox-host: -socket and -plugins-dir are required")
		os.Exit(2)
	}
	plugins, err := filepath.Abs(*pluginsDir)
	if err != nil {
		log.Fatalf("sandbox-host: %v", err)
	}

	host := newHost(plugins, *maxCalls)
	listener, err := listen(*socket)
	if err != nil {
		log.Fatalf("sandbox-host: %v", err)
	}

	server := &http.Server{
		Handler:           host.handler(),
		ReadHeaderTimeout: 5 * time.Second,
	}
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGTERM, syscall.SIGINT)
	go func() {
		<-stop
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		_ = server.Shutdown(ctx)
	}()

	log.Printf("sandbox-host %s listening on %s, modules from %s, %d calls at once", version, *socket, plugins, *maxCalls)
	if err := server.Serve(listener); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatalf("sandbox-host: %v", err)
	}
	host.close()
}

// listen takes the socket path, removing a stale socket a previous run left.
// The socket is the only way in: its directory decides who may call (the
// installer makes it the host's own, readable by the web server's group).
func listen(path string) (net.Listener, error) {
	if fi, err := os.Lstat(path); err == nil {
		if fi.Mode()&os.ModeSocket == 0 {
			return nil, fmt.Errorf("%s exists and is not a socket", path)
		}
		_ = os.Remove(path)
	}
	old := syscall.Umask(0o007)
	listener, err := net.Listen("unix", path)
	syscall.Umask(old)
	if err != nil {
		return nil, err
	}
	return listener, os.Chmod(path, 0o660)
}

// handler serves /call and /health.
func (h *host) handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, map[string]any{"ok": true, "version": version, "calls_running": atomic.LoadInt64(&h.running)})
	})
	mux.HandleFunc("/call", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "POST only", http.StatusMethodNotAllowed)
			return
		}
		body, err := io.ReadAll(io.LimitReader(r.Body, maxRequestBytes+1))
		if err != nil || len(body) > maxRequestBytes {
			writeJSON(w, refusal("The request could not be read, or was too large."))
			return
		}
		var env envelope
		if err := json.Unmarshal(body, &env); err != nil {
			writeJSON(w, refusal("The request is not a call."))
			return
		}
		writeJSON(w, h.call(r.Context(), env))
	})
	return mux
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}
