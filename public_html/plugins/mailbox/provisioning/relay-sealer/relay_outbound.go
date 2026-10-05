package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
)

// The relay only receives (specs/relay_receive_only_forwarding.md). A site on
// the current release sends no forward instructions: every recipient it names
// is spooled, and the site forwards at pull through its own email service. So
// a relay sends nothing, and closes outbound port 25: its firewall drops it,
// and Postfix's default transport is an error that backstops anything
// injected locally (a bounce included: it becomes a double bounce delivered
// to the local postmaster, never mail to the outside).
//
// A site still on an older release keeps sending forward instructions, and its
// relay keeps forwarding for it until it upgrades — otherwise its forwards
// would stop in between. So the posture follows the merged map: open while any
// tenant's fragment carries a forward, closed once none does. The merge sets it
// every time it runs.

// outboundClosedTransport is the default transport of a relay that sends nothing.
const outboundClosedTransport = "error:5.7.1 this relay delivers no outbound mail"

// carriesForwarding reports whether any tenant's routing still asks the relay
// to forward (a fragment from a site older than the receive-only release).
func (m *routingMap) carriesForwarding() bool {
	for _, e := range m.Recipients {
		if e.Mode == modeForward || e.Mode == modeForwardAndStore {
			return true
		}
	}
	for _, d := range m.Domains {
		if d.CatchAllMode == modeForward && d.CatchAllAddress != "" {
			return true
		}
	}
	return false
}

// applyOutboundPosture opens or closes outbound port 25 and reports what it
// changed. Every command has fixed arguments; nothing a tenant sends reaches a
// command line.
func applyOutboundPosture(open bool) error {
	var problems []string

	wantTransport := outboundClosedTransport
	if open {
		wantTransport = "smtp"
	}
	current := strings.TrimSpace(commandOutput("postconf", "-h", "default_transport"))
	if current != wantTransport {
		if out, err := exec.Command("postconf", "-e", "default_transport="+wantTransport).CombinedOutput(); err != nil {
			problems = append(problems, fmt.Sprintf("postconf default_transport: %v: %s", err, strings.TrimSpace(string(out))))
		} else if out, err := exec.Command("postfix", "reload").CombinedOutput(); err != nil {
			problems = append(problems, fmt.Sprintf("postfix reload: %v: %s", err, strings.TrimSpace(string(out))))
		}
	}

	if _, err := exec.LookPath("ufw"); err == nil {
		denied := ufwDeniesOutbound25(commandOutput("ufw", "status"))
		if !open && !denied {
			if out, err := exec.Command("ufw", "deny", "out", "25/tcp").CombinedOutput(); err != nil {
				problems = append(problems, fmt.Sprintf("ufw deny out 25: %v: %s", err, strings.TrimSpace(string(out))))
			}
		}
		if open && denied {
			if out, err := exec.Command("ufw", "delete", "deny", "out", "25/tcp").CombinedOutput(); err != nil {
				problems = append(problems, fmt.Sprintf("ufw delete deny out 25: %v: %s", err, strings.TrimSpace(string(out))))
			}
		}
	}

	if len(problems) > 0 {
		return fmt.Errorf("%s", strings.Join(problems, "; "))
	}
	return nil
}

// ufwDeniesOutbound25 reads `ufw status` for the outbound port-25 deny rule.
func ufwDeniesOutbound25(status string) bool {
	for _, line := range strings.Split(status, "\n") {
		f := strings.Fields(line)
		if len(f) >= 3 && f[0] == "25/tcp" && f[1] == "DENY" && f[2] == "OUT" {
			return true
		}
	}
	return false
}

// --- Deferring a tenant that has stopped pulling (B8) ----------------------
//
// A tenant over its spool quota cannot take more mail. Accepting it and
// temp-failing in the sealer would leave it in the relay's queue, which at
// queue expiry bounces it from the relay to a sender that is usually forged.
// Instead the relay refuses that tenant's addresses at RCPT with a 4xx while it
// is over, so the queue stays on the sending server, which retries and, if it
// gives up, tells its own sender. collect-status rewrites the map every thirty
// seconds; Postfix notices a changed table on its own.

// deferredReply is the RCPT answer for a tenant whose spool is full.
const deferredReply = "452 4.2.2 Mailbox full, try again later"

// deferredMapLines lists every domain of every tenant over its spool quota, in
// a stable order. defaultSpool is where a tenant with no spool dir of its own
// spools.
func deferredMapLines(m *routingMap, defaultSpool string) string {
	over := map[string]bool{}
	for slug, tc := range m.Tenants {
		dir := tc.SpoolDir
		if dir == "" {
			dir = defaultSpool
		}
		if full, _ := spoolQuotaExceeded(dir, tc); full {
			over[slug] = true
		}
	}
	if len(over) == 0 {
		return ""
	}
	domains := map[string]bool{}
	for dom, d := range m.Domains {
		if over[d.Tenant] {
			domains[strings.ToLower(dom)] = true
		}
	}
	for addr, e := range m.Recipients {
		if over[e.Tenant] {
			if dom := domainOf(addr); dom != "" {
				domains[dom] = true
			}
		}
	}
	for slug := range over {
		for _, fd := range m.Tenants[slug].ForwardingDomains {
			domains[strings.ToLower(fd)] = true
		}
	}
	names := make([]string, 0, len(domains))
	for d := range domains {
		names = append(names, d)
	}
	sort.Strings(names)
	var b strings.Builder
	for _, d := range names {
		b.WriteString(d + " " + deferredReply + "\n")
	}
	return b.String()
}

// refreshDeferredMap rewrites /etc/postfix/joinery-deferred from the current
// routing map and spool sizes, and postmaps it when it changed.
func refreshDeferredMap(postfixDir, routingPath, defaultSpool string) error {
	m, err := loadRoutingMap(routingPath)
	if err != nil {
		return err
	}
	path := filepath.Join(postfixDir, "joinery-deferred")
	content := deferredMapLines(m, defaultSpool)
	existing, readErr := os.ReadFile(path)
	if readErr == nil && string(existing) == content {
		if _, err := os.Stat(path + ".db"); err == nil {
			return nil
		}
	}
	if err := writeFileAtomic(path, []byte(content), 0o644); err != nil {
		return err
	}
	if out, err := exec.Command("postmap", path).CombinedOutput(); err != nil {
		return fmt.Errorf("postmap %s: %v: %s", path, err, strings.TrimSpace(string(out)))
	}
	return nil
}
