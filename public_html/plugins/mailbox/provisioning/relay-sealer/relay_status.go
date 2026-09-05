package main

// The health ping: the only window into a relay (no shell, no door), so it
// carries everything a person would have learned from one, under one rule that
// already governed joinery-ping: service state is not tenant data, and anything
// per-tenant is reported only when the relay has exactly one tenant. On a shard
// those keys are absent, never zero.
//
// Two halves. Root's collector (relay_apply.go) writes the privileged facts —
// unit state, firewall, journal excerpt, Postfix counts, reboot_required — to a
// status file on a timer. The listener merges that file with what it measures
// itself: spool, auth counters, Direct, clock, listeners, machine. It also keeps
// the keys the plane read from joinery-ping (services as a flat map, milters,
// contract, provisioned, slug, sole, queue), so RelayVersion and the health
// battery keep reading the fields they read today.

import (
	"bufio"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// buildPing assembles the ping for one authenticated caller.
func (s *relayServer) buildPing(tenant string) map[string]any {
	priv := s.readPrivileged()
	tenants, _ := listTenantSlugs(s.paths.tenantsDir())
	oneTenant := len(tenants) == 1
	now := time.Now().UTC()

	ping := map[string]any{}

	// --- compat keys the plane reads today ----------------------------------
	flatServices := map[string]string{}
	detail := map[string]any{}
	for unit, st := range priv.Services {
		flatServices[unit] = st.Active
		detail[unit] = st
	}
	// joinery_direct kept as the name of the unit that serves Direct, which is
	// this process. If we are answering, the listener is up.
	flatServices["joinery_direct"] = "active"
	flatServices["joinery-relay-serve"] = "active"
	if priv.CollectedUTC == "" {
		// No collector output yet: the plane must see "unknown", not a blank
		// that reads as healthy. Every unit the collector would report is named.
		for _, unit := range relayUnits {
			if _, ok := flatServices[unit]; !ok {
				flatServices[unit] = "unknown"
			}
		}
		flatServices["joinery-relay-serve"] = "active"
	}
	// The milter keys are always present: before the collector's first pass
	// they read false, which the plane grades as "not wired" — a relay that
	// cannot yet vouch for its scanner must not look like one that can.
	milters := map[string]bool{"opendkim": false, "opendmarc": false, "rspamd": false}
	for k, v := range priv.Milters {
		milters[k] = v
	}
	ping["status"] = "ok"
	ping["services"] = flatServices
	ping["service_detail"] = detail
	ping["milters"] = milters
	ping["contract"] = priv.ContractOK
	ping["provisioned"] = readTrimmed(filepath.Join(s.paths.home, "version"))
	ping["slug"] = tenant
	ping["sole"] = oneTenant
	if oneTenant && priv.Postfix.QueueDepth != nil {
		ping["queue"] = *priv.Postfix.QueueDepth
	}

	// --- build ---------------------------------------------------------------
	var uname syscall.Utsname
	kernel := ""
	if syscall.Uname(&uname) == nil {
		kernel = utsString(uname.Release[:])
	}
	ping["build"] = map[string]any{
		"relay_version": readTrimmed(filepath.Join(s.paths.home, "version")),
		"bundle_sha256": readTrimmed(filepath.Join(s.paths.home, "bundle_sha256")),
		"built_at":      readTrimmed(filepath.Join(s.paths.home, "built_at")),
		"image":         osRelease(),
		"arch":          runtime.GOARCH,
		"kernel":        kernel,
	}

	// --- identity ------------------------------------------------------------
	ping["identity"] = map[string]any{
		"identity_fingerprint": s.identity.fingerprint,
		"mail_hostname":        s.hostname,
		"authserv_id":          readTrimmed(filepath.Join(s.paths.home, "authserv_id")),
		"tenant_count":         len(tenants),
	}

	// --- listeners -----------------------------------------------------------
	ping["listeners"] = map[string]any{
		"25":  map[string]any{"bound": portListening(25), "accepted_1h": priv.Postfix.Connections1h},
		"443": map[string]any{"bound": portListening(443), "accepted_1h": s.countSince(s.conns443, time.Hour)},
	}

	// --- tls -----------------------------------------------------------------
	tlsGroup := map[string]any{"acme_certificate": false}
	if notAfter, ok := acmeNotAfter(filepath.Join(s.certCache, s.hostname)); ok {
		tlsGroup["acme_certificate"] = true
		tlsGroup["not_after"] = notAfter.UTC().Format(time.RFC3339)
	}
	s.mu.Lock()
	if !s.lastACMEAttempt.IsZero() {
		tlsGroup["last_issuance_attempt"] = s.lastACMEAttempt.UTC().Format(time.RFC3339)
		if s.lastACMEError != "" {
			tlsGroup["last_issuance_error"] = s.lastACMEError
		}
	}
	directErr, directErrAt := s.lastDirectError, s.lastDirectErrorAt
	s.mu.Unlock()
	ping["tls"] = tlsGroup
	// Compat: joinery-ping's direct.certificate.
	directGroup := map[string]any{
		"certificate":   tlsGroup["acme_certificate"],
		"serving":       true,
		"deliveries_1h": s.countSince(s.directDeliveries, time.Hour),
		"egress_1h":     s.countSince(s.egressCalls, time.Hour),
	}
	if directErr != "" {
		directGroup["last_error"] = directErr
		directGroup["last_error_at"] = directErrAt.UTC().Format(time.RFC3339)
	}
	ping["direct"] = directGroup

	// --- clock ---------------------------------------------------------------
	clock := map[string]any{
		"now":             now.Format("2006-01-02 15:04:05"),
		"timesync_active": priv.Timesync.Active,
	}
	if priv.Timesync.SkewSeconds != nil {
		clock["skew_seconds"] = *priv.Timesync.SkewSeconds
	}
	if priv.CollectedUTC != "" {
		clock["privileged_collected_utc"] = priv.CollectedUTC
	}
	ping["clock"] = clock

	// --- machine -------------------------------------------------------------
	ping["machine"] = machineFacts(s.paths.spoolRoot, priv.RebootRequired)

	// --- firewall ------------------------------------------------------------
	fw := priv.Firewall
	if fw == nil {
		fw = []string{}
	}
	ping["firewall"] = fw

	// --- postfix -------------------------------------------------------------
	pf := map[string]any{"connections_1h": priv.Postfix.Connections1h}
	if priv.Postfix.LastAcceptTime != "" {
		pf["last_accept_time"] = priv.Postfix.LastAcceptTime
	}
	if oneTenant {
		if priv.Postfix.Accepted != nil {
			pf["accepted_1h"] = *priv.Postfix.Accepted
			pf["rejected_1h"] = *priv.Postfix.Rejected
			pf["deferred_1h"] = *priv.Postfix.Deferred
			pf["bounced_1h"] = *priv.Postfix.Bounced
		}
		if priv.Postfix.QueueDepth != nil {
			pf["queue_depth"] = *priv.Postfix.QueueDepth
		}
	}
	ping["postfix"] = pf

	// --- spool (one tenant only) ------------------------------------------------
	if oneTenant && tenant != relayOperatorTenant {
		ping["spool"] = spoolFacts(s.paths.spoolDir(tenant))
	}

	// --- auth ----------------------------------------------------------------
	failures := s.auth.failureCounts()
	if oneTenant {
		ping["auth"] = map[string]any{"failures_1h_by_tenant": failures}
	} else {
		totals := map[string]int{}
		for _, kinds := range failures {
			for kind, n := range kinds {
				totals[kind] += n
			}
		}
		ping["auth"] = map[string]any{"failures_1h": totals}
	}

	// --- log (one tenant only) --------------------------------------------------
	if oneTenant && priv.Log != nil {
		ping["log"] = priv.Log
	}

	return ping
}

// readPrivileged loads the collector's file. A missing or unreadable file
// yields an empty status, which the ping reports as unknowns — never as green.
func (s *relayServer) readPrivileged() privilegedStatus {
	var st privilegedStatus
	raw, err := os.ReadFile(s.paths.privilegedStatus())
	if err != nil {
		return st
	}
	_ = json.Unmarshal(raw, &st)
	if st.Services == nil {
		st.Services = map[string]serviceStatus{}
	}
	if st.Milters == nil {
		st.Milters = map[string]bool{}
	}
	return st
}

// spoolFacts measures one tenant's spool: entries by kind, oldest entry age,
// bytes held. Measured rather than tracked, like every other spool number here.
func spoolFacts(dir string) map[string]any {
	facts := map[string]any{"seal": 0, "direct": 0, "bytes": int64(0)}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return facts
	}
	var oldest time.Time
	var bytes int64
	seals, directs := 0, 0
	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		name := e.Name()
		kind := ""
		switch {
		case strings.HasSuffix(name, ".seal"):
			kind = "seal"
			seals++
		case strings.HasSuffix(name, ".direct"):
			kind = "direct"
			directs++
		case strings.HasSuffix(name, ".meta"):
		default:
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		bytes += info.Size()
		if kind != "" && (oldest.IsZero() || info.ModTime().Before(oldest)) {
			oldest = info.ModTime()
		}
	}
	facts["seal"] = seals
	facts["direct"] = directs
	facts["bytes"] = bytes
	if !oldest.IsZero() {
		facts["oldest_age_seconds"] = int64(time.Since(oldest).Seconds())
	}
	return facts
}

// machineFacts: uptime, load, memory and disk, from /proc and statfs — all
// readable by an unprivileged process.
func machineFacts(spoolRoot string, rebootRequired bool) map[string]any {
	m := map[string]any{"reboot_required": rebootRequired}
	if raw, err := os.ReadFile("/proc/uptime"); err == nil {
		if f := strings.Fields(string(raw)); len(f) > 0 {
			if up, err := strconv.ParseFloat(f[0], 64); err == nil {
				m["uptime_seconds"] = int64(up)
			}
		}
	}
	if raw, err := os.ReadFile("/proc/loadavg"); err == nil {
		if f := strings.Fields(string(raw)); len(f) > 0 {
			if load, err := strconv.ParseFloat(f[0], 64); err == nil {
				m["load_1m"] = load
			}
		}
	}
	if total, avail, ok := memInfo(); ok && total > 0 {
		m["mem_used_pct"] = int((total - avail) * 100 / total)
	}
	disk := map[string]any{}
	if pct, ok := diskUsedPct("/"); ok {
		disk["/"] = pct
	}
	if pct, ok := diskUsedPct(spoolRoot); ok {
		disk[spoolRoot] = pct
	}
	m["disk_used_pct"] = disk
	return m
}

func memInfo() (total, avail int64, ok bool) {
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return 0, 0, false
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		k, v, found := strings.Cut(sc.Text(), ":")
		if !found {
			continue
		}
		fields := strings.Fields(v)
		if len(fields) == 0 {
			continue
		}
		n, err := strconv.ParseInt(fields[0], 10, 64)
		if err != nil {
			continue
		}
		switch k {
		case "MemTotal":
			total = n
		case "MemAvailable":
			avail = n
		}
	}
	return total, avail, total > 0
}

func diskUsedPct(path string) (int, bool) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil || st.Blocks == 0 {
		return 0, false
	}
	used := st.Blocks - st.Bfree
	return int(used * 100 / st.Blocks), true
}

// portListening reports whether anything on this machine listens on a TCP
// port, from /proc/net/tcp and tcp6 (state 0A = LISTEN).
func portListening(port int) bool {
	for _, file := range []string{"/proc/net/tcp", "/proc/net/tcp6"} {
		raw, err := os.ReadFile(file)
		if err != nil {
			continue
		}
		if procNetListsPort(string(raw), port) {
			return true
		}
	}
	return false
}

// procNetListsPort scans one /proc/net/tcp{,6} table for a LISTEN socket on
// port. The local port is zero-padded hex ("0019" for 25), so it is parsed as
// a number rather than compared as text.
func procNetListsPort(table string, port int) bool {
	sc := bufio.NewScanner(strings.NewReader(table))
	sc.Scan() // header
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 4 || fields[3] != "0A" {
			continue
		}
		_, localPort, ok := strings.Cut(fields[1], ":")
		if !ok {
			continue
		}
		n, err := strconv.ParseInt(localPort, 16, 32)
		if err == nil && int(n) == port {
			return true
		}
	}
	return false
}

// acmeNotAfter reads the leaf certificate's expiry from autocert's cache file
// for the hostname (key PEM followed by the chain PEM).
func acmeNotAfter(path string) (time.Time, bool) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return time.Time{}, false
	}
	for {
		var block *pem.Block
		block, raw = pem.Decode(raw)
		if block == nil {
			return time.Time{}, false
		}
		if block.Type != "CERTIFICATE" {
			continue
		}
		cert, err := x509.ParseCertificate(block.Bytes)
		if err != nil {
			return time.Time{}, false
		}
		return cert.NotAfter, true
	}
}

func osRelease() string {
	raw, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(string(raw), "\n") {
		if v, ok := strings.CutPrefix(line, "PRETTY_NAME="); ok {
			return strings.Trim(v, "\"")
		}
	}
	return ""
}

func utsString(b []int8) string {
	var out []byte
	for _, c := range b {
		if c == 0 {
			break
		}
		out = append(out, byte(c))
	}
	return string(out)
}

func readTrimmed(path string) string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(raw))
}
