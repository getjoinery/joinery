package main

// The root side of the relay API's privilege split.
//
// relay-serve runs as the unprivileged relay user and never gains root. But a
// merge writes /etc/postfix/joinery-*, runs postmap and postfix reload, and a
// tenant change writes the root-owned registry. So the listener does not do
// those things: it FILES A REQUEST into a drop directory only it can write, and
// a root-owned systemd path unit fires `relay-sealer apply-requests`, which
// validates the file, applies it, and writes a verdict the listener can read.
// Nothing on this machine takes an instruction from the network as root; root
// reacts to a file whose contents it validates.
//
// The same binary's tenant-add / tenant-set-domains / tenant-remove subcommands
// are the CLI face of the same functions, for the build (tenant `main` from the
// user-data) and for a hand run. One implementation of the registry layout.
//
// And `relay-sealer collect-status` is the root half of the health ping: a
// systemd timer runs it every thirty seconds and it gathers what an
// unprivileged process cannot read — unit state, the firewall, the journal
// excerpt, Postfix counts — into a status file the listener merges with what it
// measures itself. Root reacts to a timer, never to a request.

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const (
	requestTypeFragment         = "fragment"
	requestTypeTenantAdd        = "tenant_add"
	requestTypeTenantSetDomains = "tenant_set_domains"
	requestTypeTenantRemove     = "tenant_remove"

	// A request file may carry a fragment, so its cap is the fragment's plus room
	// for the wrapper.
	maxRequestBytes = maxFragmentBytes + 64*1024

	// How long the listener waits for root's verdict before answering "timeout".
	// A merge with postmap and a reload takes about a second; a path unit wakes
	// within milliseconds. Thirty seconds is "something is wrong", not "slow".
	verdictWait = 30 * time.Second
	// Verdicts the listener never collected (a client that hung up) are pruned
	// by the applier after this long.
	verdictRetention = 10 * time.Minute

	privilegedStatusFile = "privileged.json"
)

var requestIDRe = regexp.MustCompile(`^[a-f0-9]{16,64}$`)

// relayRequest is what the listener files. `By` is the caller the signature
// established; the applier trusts the file only because the drop directory is
// writable by the listener alone, and still re-validates every field.
type relayRequest struct {
	ID        string          `json:"id"`
	Type      string          `json:"type"`
	Tenant    string          `json:"tenant"`
	By        string          `json:"by"`
	Fragment  json.RawMessage `json:"fragment,omitempty"`
	PublicKey string          `json:"public_key,omitempty"`
	Domains   []string        `json:"domains,omitempty"`
	Limits    *tenantLimits   `json:"limits,omitempty"`
	FiledUTC  string          `json:"filed_utc"`
}

// relayVerdict is root's answer, returned to the caller in the response body.
type relayVerdict struct {
	ID         string        `json:"id"`
	Status     string        `json:"status"` // ok | rejected | error | timeout
	Reason     string        `json:"reason,omitempty"`
	Merge      *mergeVerdict `json:"merge,omitempty"`
	AppliedUTC string        `json:"applied_utc,omitempty"`
}

// relayPaths is the directory layout under the relay home, shared by the
// listener and the applier so neither hard-codes a path the other does not.
type relayPaths struct {
	home      string
	spoolRoot string
}

func relayPathsFromEnv() relayPaths {
	return relayPaths{
		home:      envOr("JOINERY_RELAY_HOME", "/opt/joinery-relay"),
		spoolRoot: envOr("JOINERY_RELAY_SPOOL_ROOT", "/var/spool/joinery-relay"),
	}
}

func (p relayPaths) requestsDir() string       { return filepath.Join(p.home, "requests") }
func (p relayPaths) verdictsDir() string       { return filepath.Join(p.home, "verdicts") }
func (p relayPaths) statusDir() string         { return filepath.Join(p.home, "status") }
func (p relayPaths) identityDir() string       { return filepath.Join(p.home, "identity") }
func (p relayPaths) tenantsDir() string        { return filepath.Join(p.home, "tenants") }
func (p relayPaths) tenantDir(s string) string { return filepath.Join(p.home, "tenants", s) }
func (p relayPaths) fragmentDir(s string) string {
	return filepath.Join(p.home, "home", s, "fragments")
}
func (p relayPaths) spoolDir(s string) string { return filepath.Join(p.spoolRoot, s) }
func (p relayPaths) requestFile(id string) string {
	return filepath.Join(p.requestsDir(), id+".json")
}
func (p relayPaths) verdictFile(id string) string {
	return filepath.Join(p.verdictsDir(), id+".json")
}
func (p relayPaths) privilegedStatus() string {
	return filepath.Join(p.statusDir(), privilegedStatusFile)
}

// ---------------------------------------------------------------------------
// apply-requests
// ---------------------------------------------------------------------------

func runApplyRequests() int {
	p := relayPathsFromEnv()
	entries, err := os.ReadDir(p.requestsDir())
	if err != nil {
		if os.IsNotExist(err) {
			return 0
		}
		fmt.Fprintf(os.Stderr, "apply-requests: %v\n", err)
		return 1
	}
	names := make([]string, 0, len(entries))
	for _, e := range entries {
		names = append(names, e.Name())
	}
	// Oldest first, so a fragment pushed twice lands in push order.
	sort.Strings(names)

	expectedUID := requestOwnerUID()
	failed := 0
	for _, name := range names {
		if !strings.HasSuffix(name, ".json") {
			continue
		}
		id := strings.TrimSuffix(name, ".json")
		path := filepath.Join(p.requestsDir(), name)
		if !requestIDRe.MatchString(id) {
			// Not something the listener wrote. Remove it: nothing else may
			// put files here, and leaving one would fire the path unit for ever.
			_ = os.Remove(path)
			continue
		}
		verdict := applyRequestFile(p, path, id, expectedUID)
		if verdict.Status == "error" {
			failed++
		}
		writeVerdictFile(p, verdict)
		_ = os.Remove(path)
	}
	pruneVerdicts(p)
	if failed > 0 {
		return 1
	}
	return 0
}

// requestOwnerUID is the uid a request file must be owned by: the relay user's.
// JOINERY_RELAY_REQUEST_UID overrides it for tests, which run as whoever runs
// them. -1 means the user does not exist here, and then every request is
// refused — a relay with no relay user is not a relay.
func requestOwnerUID() int {
	if v := os.Getenv("JOINERY_RELAY_REQUEST_UID"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	u, err := user.Lookup(envOr("JOINERY_RELAY_USER", "joinery-relay"))
	if err != nil {
		return -1
	}
	uid, err := strconv.Atoi(u.Uid)
	if err != nil {
		return -1
	}
	return uid
}

// applyRequestFile reads one request defensively — a regular file, owned by
// the listener, under the cap, valid JSON, its id matching its name — and
// dispatches it.
func applyRequestFile(p relayPaths, path, id string, expectedUID int) relayVerdict {
	v := relayVerdict{ID: id, Status: "error", AppliedUTC: time.Now().UTC().Format(time.RFC3339)}
	info, err := os.Lstat(path)
	if err != nil {
		v.Reason = "request unreadable"
		return v
	}
	if !info.Mode().IsRegular() {
		v.Reason = "request is not a regular file"
		return v
	}
	if st, ok := info.Sys().(*syscall.Stat_t); ok {
		if expectedUID < 0 || int(st.Uid) != expectedUID {
			v.Reason = "request not filed by the relay listener"
			return v
		}
	}
	if info.Size() > maxRequestBytes {
		v.Reason = "request exceeds the size cap"
		return v
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		v.Reason = "request unreadable"
		return v
	}
	var req relayRequest
	if err := json.Unmarshal(raw, &req); err != nil || req.ID != id {
		v.Reason = "request is not valid"
		return v
	}
	return applyRequest(p, req)
}

// applyRequest performs one validated request. It is also what the tenant
// subcommands call, so the CLI and the API share one implementation.
func applyRequest(p relayPaths, req relayRequest) relayVerdict {
	v := relayVerdict{ID: req.ID, AppliedUTC: time.Now().UTC().Format(time.RFC3339)}
	reject := func(why string) relayVerdict {
		v.Status = "rejected"
		v.Reason = why
		return v
	}
	if !slugRe.MatchString(req.Tenant) {
		return reject("invalid tenant slug")
	}

	switch req.Type {
	case requestTypeFragment:
		// A fragment is a tenant's own act, on its own registry entry.
		if req.By != req.Tenant {
			return reject("a fragment may only be pushed by its own tenant")
		}
		if !tenantExists(p, req.Tenant) {
			return reject("tenant is not registered on this relay")
		}
		if len(req.Fragment) == 0 || len(req.Fragment) > maxFragmentBytes {
			return reject("fragment missing or over the size cap")
		}
		if err := os.MkdirAll(p.fragmentDir(req.Tenant), 0o700); err != nil {
			return errorVerdict(v, "cannot create the fragment directory")
		}
		if err := writeFileAtomic(filepath.Join(p.fragmentDir(req.Tenant), "fragment.json"), req.Fragment, 0o600); err != nil {
			return errorVerdict(v, "cannot write the fragment")
		}
		return mergeAndVerdict(p, v, req.Tenant)

	case requestTypeTenantAdd:
		if req.By != relayOperatorTenant {
			return reject("tenant changes are the operator's act")
		}
		if why := registerTenant(p, req.Tenant, req.PublicKey, req.Domains, req.Limits); why != "" {
			return reject(why)
		}
		return mergeAndVerdict(p, v, req.Tenant)

	case requestTypeTenantSetDomains:
		if req.By != relayOperatorTenant {
			return reject("tenant changes are the operator's act")
		}
		if !tenantExists(p, req.Tenant) {
			return reject("tenant is not registered on this relay")
		}
		if why := writeAllowlist(p, req.Tenant, req.Domains); why != "" {
			return reject(why)
		}
		return mergeAndVerdict(p, v, req.Tenant)

	case requestTypeTenantRemove:
		if req.By != relayOperatorTenant {
			return reject("tenant changes are the operator's act")
		}
		if !tenantExists(p, req.Tenant) {
			return reject("tenant is not registered on this relay")
		}
		if n := spoolEntryCount(p.spoolDir(req.Tenant)); n > 0 {
			// An undrained spool is accepted mail that exists nowhere else.
			return reject(fmt.Sprintf("spool still holds %d undrained entries", n))
		}
		for _, dir := range []string{p.tenantDir(req.Tenant), filepath.Join(p.home, "home", req.Tenant), p.spoolDir(req.Tenant)} {
			if err := os.RemoveAll(dir); err != nil {
				return errorVerdict(v, "cannot remove "+filepath.Base(dir))
			}
		}
		// After a removal the merge verdict is somebody else's; the act itself
		// is the answer.
		if code := runMerge(); code != 0 {
			return errorVerdict(v, "the map merge failed after the removal")
		}
		v.Status = "ok"
		return v
	}
	return reject("unknown request type")
}

func errorVerdict(v relayVerdict, why string) relayVerdict {
	v.Status = "error"
	v.Reason = why
	return v
}

// mergeAndVerdict runs the merge and lifts this tenant's merge verdict into
// the request verdict. The merge writes a verdict for every tenant on each run,
// so the one read here is this run's.
func mergeAndVerdict(p relayPaths, v relayVerdict, slug string) relayVerdict {
	code := runMerge()
	raw, err := os.ReadFile(filepath.Join(p.tenantDir(slug), "merge_result.json"))
	if err != nil {
		return errorVerdict(v, "the merge produced no verdict")
	}
	var mv mergeVerdict
	if err := json.Unmarshal(raw, &mv); err != nil {
		return errorVerdict(v, "the merge verdict is unreadable")
	}
	v.Merge = &mv
	switch {
	case code != 0 || !mv.Installed:
		v.Status = "error"
		v.Reason = "the merge could not install the maps"
	case mv.Status == "rejected":
		v.Status = "rejected"
		v.Reason = mv.Reason
	default:
		v.Status = "ok"
	}
	return v
}

func tenantExists(p relayPaths, slug string) bool {
	if !slugRe.MatchString(slug) {
		return false
	}
	info, err := os.Stat(p.tenantDir(slug))
	return err == nil && info.IsDir()
}

// registerTenant creates or updates a tenant's registry entry: its public key,
// domain allowlist, shard-policy limits, fragment drop and spool directory.
// Returns a rejection reason, or "" on success.
func registerTenant(p relayPaths, slug, publicKey string, domains []string, limits *tenantLimits) string {
	if !slugRe.MatchString(slug) || slug == relayOperatorTenant {
		return "invalid tenant slug"
	}
	publicKey = strings.TrimSpace(publicKey)
	if !validEd25519PublicKey(publicKey) {
		return "public_key is not a valid Ed25519 key"
	}
	if limits == nil {
		limits = &tenantLimits{}
	}
	if limits.ForwardHourlyLimit < 0 || limits.SpoolMaxMiB < 0 || limits.SpoolMaxEntries < 0 {
		return "limits must not be negative"
	}
	if why := validateAllowlist(domains); why != "" {
		return why
	}

	tdir := p.tenantDir(slug)
	if err := os.MkdirAll(tdir, 0o755); err != nil {
		return "cannot create the tenant registry entry"
	}
	if err := writeFileAtomic(filepath.Join(tdir, "public_key"), []byte(publicKey+"\n"), 0o644); err != nil {
		return "cannot write the tenant public key"
	}
	if why := writeAllowlist(p, slug, domains); why != "" {
		return why
	}
	limitsJSON, _ := json.Marshal(limits)
	if err := writeFileAtomic(filepath.Join(tdir, "limits.json"), append(limitsJSON, '\n'), 0o644); err != nil {
		return "cannot write the tenant limits"
	}
	if err := os.MkdirAll(p.fragmentDir(slug), 0o700); err != nil {
		return "cannot create the fragment directory"
	}
	// The spool: written by the sealer pipe and read, listed and pruned by the
	// listener — both the relay user. No group, no setgid: tenant isolation is
	// the listener scoping every path to the authenticated tenant's directory.
	spool := p.spoolDir(slug)
	if err := os.MkdirAll(filepath.Join(spool, "tmp"), 0o700); err != nil {
		return "cannot create the spool directory"
	}
	chownToRelayUser(spool)
	chownToRelayUser(filepath.Join(spool, "tmp"))
	return ""
}

// validateAllowlist: "*" alone, or any number of well-formed domains. Empty is
// valid (a fleet tenant has no domains until its first verification).
func validateAllowlist(domains []string) string {
	for _, d := range domains {
		d = strings.ToLower(strings.TrimSpace(d))
		if d == "*" {
			if len(domains) != 1 {
				return "'*' must be the only allowlist entry"
			}
			continue
		}
		if !domainRe.MatchString(d) {
			return fmt.Sprintf("%q is not a valid domain", d)
		}
	}
	return ""
}

func writeAllowlist(p relayPaths, slug string, domains []string) string {
	if why := validateAllowlist(domains); why != "" {
		return why
	}
	var b strings.Builder
	for _, d := range normalizeDomainList(domains) {
		b.WriteString(d)
		b.WriteByte('\n')
	}
	if err := writeFileAtomic(filepath.Join(p.tenantDir(slug), "allowed_domains"), []byte(b.String()), 0o644); err != nil {
		return "cannot write the domain allowlist"
	}
	return ""
}

// spoolEntryCount counts committed artifacts (.seal and .direct) in a spool.
func spoolEntryCount(dir string) int {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return 0
	}
	n := 0
	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		if strings.HasSuffix(e.Name(), ".seal") || strings.HasSuffix(e.Name(), ".direct") {
			n++
		}
	}
	return n
}

func chownToRelayUser(path string) {
	u, err := user.Lookup(envOr("JOINERY_RELAY_USER", "joinery-relay"))
	if err != nil {
		return
	}
	uid, err1 := strconv.Atoi(u.Uid)
	gid, err2 := strconv.Atoi(u.Gid)
	if err1 != nil || err2 != nil {
		return
	}
	_ = os.Chown(path, uid, gid)
}

func writeVerdictFile(p relayPaths, v relayVerdict) {
	if err := os.MkdirAll(p.verdictsDir(), 0o750); err != nil {
		fmt.Fprintf(os.Stderr, "apply-requests: verdicts dir: %v\n", err)
		return
	}
	data, err := json.Marshal(v)
	if err != nil {
		return
	}
	path := p.verdictFile(v.ID)
	if err := writeFileAtomic(path, append(data, '\n'), 0o640); err != nil {
		fmt.Fprintf(os.Stderr, "apply-requests: write verdict: %v\n", err)
		return
	}
	// Readable by the listener (relay group), never by anyone else.
	chownToGroup(path, envOr("JOINERY_RELAY_USER", "joinery-relay"))
}

func pruneVerdicts(p relayPaths) {
	entries, err := os.ReadDir(p.verdictsDir())
	if err != nil {
		return
	}
	cutoff := time.Now().Add(-verdictRetention)
	for _, e := range entries {
		info, err := e.Info()
		if err == nil && info.ModTime().Before(cutoff) {
			_ = os.Remove(filepath.Join(p.verdictsDir(), e.Name()))
		}
	}
}

// ---------------------------------------------------------------------------
// tenant-add / tenant-set-domains / tenant-remove (CLI, root)
// ---------------------------------------------------------------------------

func runTenantCommand(command string, args []string) int {
	fs := flag.NewFlagSet(command, flag.ContinueOnError)
	slug := fs.String("slug", "", "tenant slug")
	publicKey := fs.String("public-key", "", "tenant Ed25519 public key, base64 (tenant-add)")
	domains := fs.String("domains", "*", "comma-separated allowlist, or * (tenant-add, tenant-set-domains)")
	forwardLimit := fs.Int("forward-limit", 0, "forward_hourly_limit (tenant-add)")
	spoolMaxMiB := fs.Int("spool-max-mib", 0, "spool_max_mib (tenant-add)")
	spoolMaxEntries := fs.Int("spool-max-entries", 0, "spool_max_entries (tenant-add)")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	req := relayRequest{
		ID:       newRequestID(),
		Tenant:   strings.TrimSpace(*slug),
		By:       relayOperatorTenant,
		FiledUTC: time.Now().UTC().Format(time.RFC3339),
	}
	switch command {
	case "tenant-add":
		req.Type = requestTypeTenantAdd
		req.PublicKey = *publicKey
		req.Domains = splitDomains(*domains)
		req.Limits = &tenantLimits{ForwardHourlyLimit: *forwardLimit, SpoolMaxMiB: *spoolMaxMiB, SpoolMaxEntries: *spoolMaxEntries}
	case "tenant-set-domains":
		req.Type = requestTypeTenantSetDomains
		req.Domains = splitDomains(*domains)
	case "tenant-remove":
		req.Type = requestTypeTenantRemove
	default:
		return 2
	}
	v := applyRequest(relayPathsFromEnv(), req)
	out, _ := json.Marshal(v)
	fmt.Println(string(out))
	if v.Status != "ok" {
		return 1
	}
	return 0
}

func splitDomains(csv string) []string {
	var out []string
	for _, d := range strings.Split(csv, ",") {
		d = strings.TrimSpace(d)
		if d != "" {
			out = append(out, d)
		}
	}
	return out
}

func newRequestID() string {
	var b [16]byte
	_, _ = readRandom(b[:])
	return hex.EncodeToString(b[:])
}

// ---------------------------------------------------------------------------
// collect-status (root, every thirty seconds)
// ---------------------------------------------------------------------------

// privilegedStatus is what root gathers for the ping. Everything here is
// service state, not tenant data — with the one gated exception: Postfix
// message counts and the queue depth are emitted only when the relay has
// exactly one tenant, so the numbers are wholly the asker's. On a shard the
// keys are absent, never zero.
type privilegedStatus struct {
	CollectedUTC   string                   `json:"collected_utc"`
	Services       map[string]serviceStatus `json:"services"`
	Firewall       []string                 `json:"firewall"`
	Log            []string                 `json:"log,omitempty"`
	Milters        map[string]bool          `json:"milters"`
	ContractOK     bool                     `json:"contract_ok"`
	RebootRequired bool                     `json:"reboot_required"`
	Timesync       timesyncStatus           `json:"timesync"`
	Postfix        postfixStatus            `json:"postfix"`
	TenantCount    int                      `json:"tenant_count"`
}

type serviceStatus struct {
	Active     string `json:"active"`
	Since      string `json:"since,omitempty"`
	Restarts24 int    `json:"restarts_24h"`
	LastExit   string `json:"last_exit,omitempty"`
}

type timesyncStatus struct {
	Active      bool     `json:"active"`
	SkewSeconds *float64 `json:"skew_seconds,omitempty"`
}

type postfixStatus struct {
	LastAcceptTime string `json:"last_accept_time,omitempty"`
	Connections1h  int    `json:"connections_1h"`
	// Gated: present only on a one-tenant relay.
	Accepted   *int `json:"accepted,omitempty"`
	Rejected   *int `json:"rejected,omitempty"`
	Deferred   *int `json:"deferred,omitempty"`
	Bounced    *int `json:"bounced,omitempty"`
	QueueDepth *int `json:"queue_depth,omitempty"`
}

// The units the ping reports on. joinery-relay-apply is the path unit's
// service and joinery-relay-collect the timer's; their state says whether root
// is reacting to files and timers at all.
var relayUnits = []string{
	"postfix", "joinery-relay-serve", "joinery-relay-apply.path", "joinery-relay-collect.timer",
	"rspamd", "opendkim", "opendmarc",
}

// Journals in the ping's log excerpt. Postfix is excluded on purpose: its log
// names correspondents, which is tenant data.
var relayLogUnits = []string{"joinery-relay-serve", "joinery-relay-apply", "joinery-relay-collect"}

func runCollectStatus() int {
	p := relayPathsFromEnv()
	postfixDir := envOr("JOINERY_RELAY_POSTFIX_DIR", "/etc/postfix")
	tenants, _ := listTenantSlugs(p.tenantsDir())
	oneTenant := len(tenants) == 1

	st := privilegedStatus{
		CollectedUTC: time.Now().UTC().Format(time.RFC3339),
		Services:     map[string]serviceStatus{},
		Milters:      map[string]bool{},
		TenantCount:  len(tenants),
	}
	for _, unit := range relayUnits {
		st.Services[unit] = systemdUnitStatus(unit)
	}
	st.Firewall = firewallRules()
	if oneTenant {
		st.Log = journalTail(relayLogUnits, 50)
	}
	milters := commandOutput("postconf", "-h", "smtpd_milters")
	st.Milters["opendkim"] = strings.Contains(milters, ":8891")
	st.Milters["opendmarc"] = strings.Contains(milters, ":8893")
	st.Milters["rspamd"] = strings.Contains(milters, ":11332")
	st.ContractOK = contractIntact(p.home)
	_, err := os.Stat("/var/run/reboot-required")
	st.RebootRequired = err == nil
	st.Timesync = timesync()
	st.Postfix = postfixCounts(oneTenant, postfixDir)

	if err := os.MkdirAll(p.statusDir(), 0o750); err != nil {
		fmt.Fprintf(os.Stderr, "collect-status: %v\n", err)
		return 1
	}
	data, err := json.Marshal(st)
	if err != nil {
		return 1
	}
	path := p.privilegedStatus()
	if err := writeFileAtomic(path, append(data, '\n'), 0o640); err != nil {
		fmt.Fprintf(os.Stderr, "collect-status: write: %v\n", err)
		return 1
	}
	chownToGroup(path, envOr("JOINERY_RELAY_USER", "joinery-relay"))
	chownToGroup(p.statusDir(), envOr("JOINERY_RELAY_USER", "joinery-relay"))
	return 0
}

// systemdUnitStatus reads one unit's state with fixed arguments — nothing a
// tenant could influence reaches a command line.
func systemdUnitStatus(unit string) serviceStatus {
	out := commandOutput("systemctl", "show", unit,
		"-p", "ActiveState", "-p", "ActiveEnterTimestamp", "-p", "NRestarts", "-p", "ExecMainStatus", "-p", "Result")
	s := serviceStatus{Active: "unknown"}
	for _, line := range strings.Split(out, "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), "=")
		if !ok {
			continue
		}
		switch k {
		case "ActiveState":
			if v != "" {
				s.Active = v
			}
		case "ActiveEnterTimestamp":
			s.Since = v
		case "NRestarts":
			s.Restarts24, _ = strconv.Atoi(v)
		case "ExecMainStatus":
			if v != "" && v != "0" {
				s.LastExit = "exit " + v
			}
		case "Result":
			if v != "" && v != "success" {
				if s.LastExit != "" {
					s.LastExit += ", "
				}
				s.LastExit += v
			}
		}
	}
	return s
}

// firewallRules is `ufw status` as a list of rule lines, so a drift from "25
// and 443" is visible on the Setup tab without anyone reading the box.
func firewallRules() []string {
	out := commandOutput("ufw", "status")
	var rules []string
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "To ") || strings.HasPrefix(line, "--") {
			continue
		}
		rules = append(rules, strings.Join(strings.Fields(line), " "))
	}
	if rules == nil {
		rules = []string{}
	}
	return rules
}

func journalTail(units []string, lines int) []string {
	args := []string{"--no-pager", "-o", "short-iso", "-n", strconv.Itoa(lines)}
	for _, u := range units {
		args = append(args, "-u", u)
	}
	out := commandOutput("journalctl", args...)
	var result []string
	for _, line := range strings.Split(out, "\n") {
		if strings.TrimSpace(line) != "" {
			result = append(result, line)
		}
	}
	if result == nil {
		result = []string{}
	}
	return result
}

// contractIntact compares the rspamd header contract's digest against what the
// build recorded, exactly as joinery-ping did.
func contractIntact(relayHome string) bool {
	want, err := os.ReadFile(filepath.Join(relayHome, "contract.sha256"))
	if err != nil {
		return false
	}
	h := sha256.New()
	for _, f := range []string{"/etc/rspamd/local.d/milter_headers.conf", "/etc/rspamd/local.d/actions.conf"} {
		data, err := os.ReadFile(f)
		if err != nil {
			return false
		}
		h.Write(data)
	}
	return strings.TrimSpace(string(want)) == hex.EncodeToString(h.Sum(nil))
}

func timesync() timesyncStatus {
	ts := timesyncStatus{}
	show := commandOutput("timedatectl", "show", "-p", "NTPSynchronized", "--value")
	ts.Active = strings.TrimSpace(show) == "yes"
	status := commandOutput("timedatectl", "timesync-status")
	for _, line := range strings.Split(status, "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), ":")
		if !ok || strings.TrimSpace(k) != "Offset" {
			continue
		}
		if skew, ok := parseOffsetSeconds(strings.TrimSpace(v)); ok {
			ts.SkewSeconds = &skew
		}
	}
	return ts
}

// parseOffsetSeconds reads timesyncd's "+1.234ms" / "-12us" / "+2.5s" offset.
func parseOffsetSeconds(v string) (float64, bool) {
	v = strings.TrimSpace(v)
	if v == "" {
		return 0, false
	}
	d, err := time.ParseDuration(strings.ReplaceAll(v, "us", "µs"))
	if err != nil {
		return 0, false
	}
	return d.Seconds(), true
}

// postfixCounts reads the last hour of Postfix's journal with fixed syslog
// identifiers. Connection and last-accept facts are service state; message
// counts and the queue depth are the one-tenant exception.
func postfixCounts(oneTenant bool, postfixDir string) postfixStatus {
	ps := postfixStatus{}
	out := commandOutput("journalctl", "--no-pager", "-o", "short-iso", "--since", "-1 hour",
		"-t", "postfix/smtpd", "-t", "postfix/smtp", "-t", "postfix/pipe", "-t", "postfix/bounce", "-t", "postfix/qmgr")
	accepted, rejected, deferred, bounced := 0, 0, 0, 0
	for _, line := range strings.Split(out, "\n") {
		switch {
		case strings.Contains(line, "postfix/smtpd") && strings.Contains(line, ": connect from "):
			ps.Connections1h++
		case strings.Contains(line, "postfix/smtpd") && strings.Contains(line, ": client="):
			accepted++
			if f := strings.Fields(line); len(f) > 0 {
				ps.LastAcceptTime = f[0]
			}
		case strings.Contains(line, "NOQUEUE: reject:"):
			rejected++
		case strings.Contains(line, "status=deferred"):
			deferred++
		case strings.Contains(line, "status=bounced"):
			bounced++
		}
	}
	if oneTenant {
		ps.Accepted, ps.Rejected, ps.Deferred, ps.Bounced = &accepted, &rejected, &deferred, &bounced
		if depth, ok := postfixQueueDepth(); ok {
			ps.QueueDepth = &depth
		}
	}
	return ps
}

func postfixQueueDepth() (int, bool) {
	out := commandOutput("postqueue", "-p")
	if strings.Contains(out, "Mail queue is empty") {
		return 0, true
	}
	for _, line := range strings.Split(out, "\n") {
		if strings.HasPrefix(line, "-- ") && strings.Contains(line, "Request") {
			f := strings.Fields(line)
			if len(f) >= 2 {
				if n, err := strconv.Atoi(f[len(f)-2]); err == nil {
					return n, true
				}
			}
		}
	}
	return 0, false
}

// commandOutput runs a fixed command line with a bounded output and a timeout.
// "" on any failure: the ping reports what it could not learn as absent.
func commandOutput(name string, args ...string) string {
	path, err := exec.LookPath(name)
	if err != nil {
		return ""
	}
	cmd := exec.Command(path, args...)
	cmd.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL=C"}
	done := make(chan struct{})
	var out []byte
	var runErr error
	go func() {
		out, runErr = cmd.Output()
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(10 * time.Second):
		_ = cmd.Process.Kill()
		<-done
		return ""
	}
	if runErr != nil && len(out) == 0 {
		var exitErr *exec.ExitError
		if !errors.As(runErr, &exitErr) {
			return ""
		}
	}
	if len(out) > 4<<20 {
		out = out[len(out)-4<<20:]
	}
	return string(out)
}
