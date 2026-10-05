package main

// GET /health: the relay's own word on whether it is receiving mail.
//
// No machine of ours dials port 25 (specs/relay_receive_only_forwarding.md),
// so the management node cannot learn whether a relay answers mail by
// connecting to it. The relay connects to its own Postfix over the loopback,
// where its firewall does not apply, and says what it found on the 443 listener
// the management node already reaches. The plane reads this exactly as it
// reads the DNS servers' /health (NodeHealthProbe::http): 200 is up, 503 is
// down, and the machine keys are folded onto the node.
//
// Open to all, like the DNS servers' /health. It says nothing a stranger cannot
// learn by connecting to port 25, plus the machine's disk and memory. The
// answer is cached, so asking for it often costs Postfix one connection every
// healthCacheFor, not one per request.
//
// What it cannot see: a provider firewall in front of the machine that drops
// inbound 25. The loopback never crosses it, and no machine of ours may.

import (
	"bufio"
	"fmt"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const (
	healthPath = "/health"
	// Where the self-test finds Postfix. 127.0.0.1 is in the relay's
	// mynetworks, so the connection meets no client restriction.
	defaultSMTPProbeAddr = "127.0.0.1:25"
	smtpProbeTimeout     = 5 * time.Second
	healthCacheFor       = 30 * time.Second
)

// serveHealth answers GET /health.
func (s *relayServer) serveHealth(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet && r.Method != http.MethodHead {
		http.NotFound(w, r)
		return
	}
	smtpErr := s.smtpHealth()

	payload := map[string]any{"status": "ok", "smtp": "answering"}
	status := http.StatusOK
	if smtpErr != nil {
		payload["status"] = "degraded"
		payload["smtp"] = smtpErr.Error()
		status = http.StatusServiceUnavailable
	}
	if up, ok := processUptimeSeconds(); ok {
		payload["uptime_seconds"] = up
	}
	for k, v := range healthMachineFacts("/") {
		payload[k] = v
	}
	answerJSON(w, status, payload)
}

// smtpHealth is the cached self-test: nil when Postfix greeted us.
func (s *relayServer) smtpHealth() error {
	s.mu.Lock()
	if !s.smtpCheckedAt.IsZero() && time.Since(s.smtpCheckedAt) < healthCacheFor {
		err := s.smtpErr
		s.mu.Unlock()
		return err
	}
	addr := s.smtpProbeAddr
	s.mu.Unlock()
	if addr == "" {
		addr = defaultSMTPProbeAddr
	}

	err := probeSMTP(addr, smtpProbeTimeout)

	s.mu.Lock()
	s.smtpCheckedAt = time.Now()
	s.smtpErr = err
	s.mu.Unlock()
	return err
}

// probeSMTP connects to Postfix, reads its whole greeting, and says QUIT. A
// listener that accepts but never greets, or greets with anything but 220
// (a 421 while Postfix is shutting down, a 554 refusal), is not receiving mail.
func probeSMTP(addr string, timeout time.Duration) error {
	conn, err := net.DialTimeout("tcp", addr, timeout)
	if err != nil {
		return fmt.Errorf("Postfix does not accept connections on port 25: %v", err)
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(timeout))

	reader := bufio.NewReader(conn)
	for {
		line, err := reader.ReadString('\n')
		if err != nil {
			return fmt.Errorf("Postfix accepted a connection on port 25 but did not greet: %v", err)
		}
		line = strings.TrimRight(line, "\r\n")
		if len(line) < 4 || line[:3] != "220" {
			if len(line) > 120 {
				line = line[:120]
			}
			return fmt.Errorf("Postfix greeted with %q instead of 220", line)
		}
		// A multi-line greeting continues with "220-"; the last line has a space.
		if line[3] == ' ' {
			break
		}
	}
	_, _ = conn.Write([]byte("QUIT\r\n"))
	return nil
}

// processUptimeSeconds is how long this listener has run, as the DNS servers
// report theirs; the plane keeps it apart from the machine's uptime.
func processUptimeSeconds() (int64, bool) {
	raw, err := os.ReadFile("/proc/self/stat")
	if err != nil {
		return 0, false
	}
	// Field 22 (starttime, in clock ticks since boot) follows the ")" that ends
	// the command name, which may itself contain spaces.
	text := string(raw)
	end := strings.LastIndexByte(text, ')')
	if end < 0 {
		return 0, false
	}
	fields := strings.Fields(text[end+1:])
	if len(fields) < 20 {
		return 0, false
	}
	startTicks, err := strconv.ParseInt(fields[19], 10, 64)
	if err != nil {
		return 0, false
	}
	upRaw, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return 0, false
	}
	upFields := strings.Fields(string(upRaw))
	if len(upFields) == 0 {
		return 0, false
	}
	machineUp, err := strconv.ParseFloat(upFields[0], 64)
	if err != nil {
		return 0, false
	}
	const clockTicks = 100 // USER_HZ on every Linux the relay runs on
	up := int64(machineUp) - startTicks/clockTicks
	if up < 0 {
		return 0, false
	}
	return up, true
}

// healthMachineFacts reports disk and memory under the key names and formats
// the management node folds (NodeHealthProbe::MACHINE_KEYS). THE NAMES AND
// FORMATS ARE A CONTRACT shared with the DNS servers'
// internal/machine/facts.go and the agent's observe_check_status.go: a name
// that drifts does not produce a wrong figure, it produces one that silently
// stops arriving. Keys are absent, never zero, when a reading fails.
func healthMachineFacts(path string) map[string]any {
	out := map[string]any{}

	var fs syscall.Statfs_t
	if err := syscall.Statfs(path, &fs); err == nil {
		blockSize := uint64(fs.Bsize)
		total := fs.Blocks * blockSize
		// Available to an unprivileged writer, as df's Avail; used is derived
		// from it so that used + available == total.
		available := fs.Bavail * blockSize
		if total > 0 {
			used := total - available
			out["disk_usage_percent"] = int(((used * 100) + total/2) / total)
			out["disk_total"] = formatSize(total)
			out["disk_used"] = formatSize(used)
			out["disk_available"] = formatSize(available)
		}
	}

	raw, err := os.ReadFile("/proc/meminfo")
	if err != nil {
		return out
	}
	var totalKB, availKB, swapTotalKB, swapFreeKB int64
	for _, line := range strings.Split(string(raw), "\n") {
		k, v, found := strings.Cut(line, ":")
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
			totalKB = n
		case "MemAvailable":
			availKB = n
		case "SwapTotal":
			swapTotalKB = n
		case "SwapFree":
			swapFreeKB = n
		}
	}
	if totalKB <= 0 {
		return out
	}
	totalMB := (totalKB + 512) / 1024
	freeMB := (availKB + 512) / 1024
	out["memory_total_mb"] = totalMB
	out["memory_free_mb"] = freeMB
	out["memory_used_mb"] = max(totalMB-freeMB, 0)
	// Swap is reported even when there is none: no swap is a fact, not a
	// reading that failed to arrive.
	swapTotalMB := (swapTotalKB + 512) / 1024
	swapFreeMB := (swapFreeKB + 512) / 1024
	out["swap_total_mb"] = swapTotalMB
	out["swap_used_mb"] = max(swapTotalMB-swapFreeMB, 0)
	return out
}

// formatSize renders bytes as df -h does ("78.2G"), the format the plane shows.
func formatSize(bytes uint64) string {
	const unit = 1024
	if bytes < unit {
		return fmt.Sprintf("%dB", bytes)
	}
	div, exp := uint64(unit), 0
	for n := bytes / unit; n >= unit && exp < 4; n /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f%c", float64(bytes)/float64(div), "KMGTP"[exp])
}
