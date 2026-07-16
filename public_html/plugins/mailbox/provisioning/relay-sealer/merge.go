package main

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// The shard's MAP MERGE UNIT (specs/mailbox_relay_shared_fleet.md § Map sync:
// fragment push and shard-side merge). Invoked as `relay-sealer merge-maps` —
// via sudo by a tenant's forced-command shell after a fragment push, or by the
// provisioning job. A triggered script, never a resident daemon.
//
// Each tenant pushes ONE fragment (routing data only — its recipients, domains,
// forwarding domains, and per-tenant identity/keys) into its own drop area.
// The merge:
//
//  1. Validates every fragment against the tenant's ROOT-OWNED domain
//     allowlist (tenants/<slug>/allowed_domains — written by the fleet service
//     on TXT-challenge success, or "*" on a self-hosted fleet of one). A
//     fragment naming any domain outside its allowlist is REJECTED WHOLE and
//     reported; nothing from it is installed. This is where the domain-claim
//     boundary is mechanically enforced — on every sync, not once at
//     enrollment.
//  2. Keeps the tenant's LAST ACCEPTED fragment when a push is rejected, so a
//     bad push never erases working routing (mail keeps flowing on the last
//     good map while the tenant reads the verdict and fixes the push).
//  3. Derives ALL Postfix map lines shard-side from the validated routing data
//     — a tenant cannot push raw access-map lines that bypass validation.
//  4. Installs atomically, runs postmap + postfix reload only when the merged
//     output actually changed, and writes a per-tenant verdict the tenant
//     shell returns in-band.
//
// Shard-side limits (tenants/<slug>/limits.json, root-owned) are stamped into
// the merged tenant block here — the pushed fragment can never set its own
// forward or spool caps.

// mapFragment is the document a tenant's RelayMapSync pushes: its own routing
// data plus its per-tenant identity. Postfix map lines are NOT part of the
// fragment — they are derived here after validation.
type mapFragment struct {
	FragmentFormat     int                     `json:"fragment_format"`
	Tenant             string                  `json:"tenant"`
	Version            int64                   `json:"version"`
	SRSSecret          string                  `json:"srs_secret"`
	ForwardFromName    string                  `json:"forward_from_name"`
	ForwardShowVia     bool                    `json:"forward_show_via"`
	TransportPublicKey string                  `json:"transport_public_key"`
	ForwardingDomains  []string                `json:"forwarding_domains"`
	Recipients         map[string]routingEntry `json:"recipients"`
	Domains            map[string]domainEntry  `json:"domains"`
}

// tenantLimits mirrors tenants/<slug>/limits.json (shard policy, root-owned).
type tenantLimits struct {
	ForwardHourlyLimit int `json:"forward_hourly_limit"`
	SpoolMaxMiB        int `json:"spool_max_mib"`
	SpoolMaxEntries    int `json:"spool_max_entries"`
}

// mergeVerdict is written to tenants/<slug>/merge_result.json after every
// merge; the tenant shell cats it back so the pushing side gets the outcome
// in-band. Status: "ok" (fragment merged), "rejected" (push failed validation;
// Reason says why and whether a previously accepted fragment is still
// serving), "empty" (tenant has no fragment yet).
type mergeVerdict struct {
	Status          string `json:"status"`
	Reason          string `json:"reason,omitempty"`
	FragmentVersion int64  `json:"fragment_version,omitempty"`
	Installed       bool   `json:"installed"`
	Changed         bool   `json:"changed"`
	MergedUTC       string `json:"merged_utc"`
}

var (
	slugRe   = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,27}$`)
	domainRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)
)

const maxFragmentBytes = 32 * 1024 * 1024

func runMerge() int {
	relayHome := envOr("JOINERY_RELAY_HOME", "/opt/joinery-relay")
	postfixDir := envOr("JOINERY_RELAY_POSTFIX_DIR", "/etc/postfix")
	spoolRoot := envOr("JOINERY_RELAY_SPOOL_ROOT", "/var/spool/joinery-relay")
	noReload := os.Getenv("JOINERY_RELAY_MERGE_NO_RELOAD") == "1"

	tenantsDir := filepath.Join(relayHome, "tenants")
	slugs, err := listTenantSlugs(tenantsDir)
	if err != nil {
		fmt.Fprintf(os.Stderr, "merge-maps: %v\n", err)
		return 1
	}

	now := time.Now().UTC().Format(time.RFC3339)
	merged := &routingMap{
		Format:     2,
		Tenants:    map[string]tenantConfig{},
		Recipients: map[string]routingEntry{},
		Domains:    map[string]domainEntry{},
	}
	verdicts := map[string]*mergeVerdict{}
	claimed := map[string]string{} // domain → owning slug, cross-tenant duplicate guard
	rejectedCount := 0

	for _, slug := range slugs {
		v := &mergeVerdict{Status: "empty", MergedUTC: now}
		verdicts[slug] = v

		tdir := filepath.Join(tenantsDir, slug)
		allow := readAllowlist(filepath.Join(tdir, "allowed_domains"))
		limits := readLimits(filepath.Join(tdir, "limits.json"))
		pushedPath := filepath.Join(relayHome, "home", slug, "fragments", "fragment.json")
		acceptedPath := filepath.Join(tdir, "fragment.accepted.json")

		frag := selectFragment(slug, pushedPath, acceptedPath, allow, claimed, v)
		if frag == nil {
			if v.Status == "rejected" {
				rejectedCount++
			}
			continue
		}
		if v.Status == "rejected" {
			rejectedCount++
		}

		// Register this tenant's domain claims so a later fragment naming the
		// same domain is rejected deterministically (slug order).
		for dom := range frag.Domains {
			claimed[dom] = slug
		}
		for _, fd := range frag.ForwardingDomains {
			claimed[strings.ToLower(fd)] = slug
		}

		merged.Tenants[slug] = tenantConfig{
			SRSSecret:          frag.SRSSecret,
			ForwardFromName:    frag.ForwardFromName,
			ForwardShowVia:     frag.ForwardShowVia,
			TransportPublicKey: frag.TransportPublicKey,
			SpoolDir:           filepath.Join(spoolRoot, slug),
			ForwardingDomains:  normalizeDomainList(frag.ForwardingDomains),
			ForwardHourlyLimit: limits.ForwardHourlyLimit,
			SpoolMaxMiB:        limits.SpoolMaxMiB,
			SpoolMaxEntries:    limits.SpoolMaxEntries,
			FragmentVersion:    frag.Version,
		}
		for addr, e := range frag.Recipients {
			e.Tenant = slug
			merged.Recipients[strings.ToLower(addr)] = e
		}
		for dom, d := range frag.Domains {
			d.Tenant = slug
			merged.Domains[strings.ToLower(dom)] = d
		}
		if frag.Version > merged.Version {
			merged.Version = frag.Version
		}
	}

	relayDomains, recipients, transport, srs := derivePostfixMaps(merged)
	routingJSON, err := json.MarshalIndent(merged, "", "  ")
	if err != nil {
		fmt.Fprintf(os.Stderr, "merge-maps: marshal routing map: %v\n", err)
		return 1
	}

	outputs := map[string]string{
		filepath.Join(postfixDir, "joinery-relay-domains"): relayDomains,
		filepath.Join(postfixDir, "joinery-recipients"):    recipients,
		filepath.Join(postfixDir, "joinery-transport"):     transport,
		filepath.Join(postfixDir, "joinery-srs"):           srs,
		filepath.Join(relayHome, "routing.json"):           string(routingJSON) + "\n",
	}

	changed := false
	for path, content := range outputs {
		existing, err := os.ReadFile(path)
		if err != nil || string(existing) != content {
			changed = true
			break
		}
	}

	installed := true
	if changed {
		for path, content := range outputs {
			mode := os.FileMode(0o644)
			if strings.HasSuffix(path, "routing.json") {
				mode = 0o640
			}
			if err := writeFileAtomic(path, []byte(content), mode); err != nil {
				fmt.Fprintf(os.Stderr, "merge-maps: write %s: %v\n", path, err)
				installed = false
			}
		}
		// routing.json carries SRS secrets + seal targets: root-owned, readable
		// by the unprivileged sealer user only.
		chownToGroup(filepath.Join(relayHome, "routing.json"), "joinery-relay")

		if installed && !noReload {
			for _, name := range []string{"joinery-relay-domains", "joinery-recipients", "joinery-transport"} {
				if out, err := exec.Command("postmap", filepath.Join(postfixDir, name)).CombinedOutput(); err != nil {
					fmt.Fprintf(os.Stderr, "merge-maps: postmap %s: %v: %s\n", name, err, strings.TrimSpace(string(out)))
					installed = false
				}
			}
			if installed {
				if out, err := exec.Command("postfix", "reload").CombinedOutput(); err != nil {
					fmt.Fprintf(os.Stderr, "merge-maps: postfix reload: %v: %s\n", err, strings.TrimSpace(string(out)))
					installed = false
				}
			}
		}
	}

	for slug, v := range verdicts {
		v.Installed = installed
		v.Changed = changed
		writeVerdict(filepath.Join(tenantsDir, slug, "merge_result.json"), v)
	}

	if !installed {
		fmt.Printf("MERGE_FAILED tenants=%d rejected=%d\n", len(slugs), rejectedCount)
		return 1
	}
	fmt.Printf("MERGE_OK changed=%t tenants=%d rejected=%d\n", changed, len(slugs), rejectedCount)
	return 0
}

// selectFragment resolves which fragment (if any) serves a tenant this merge:
// a freshly pushed fragment that validates is accepted (and persisted as the
// new last-accepted copy); a rejected push falls back to the last accepted
// fragment, which is itself re-validated against the CURRENT allowlist so a
// revoked domain claim takes effect on the next merge, not never.
func selectFragment(slug, pushedPath, acceptedPath string, allow []string, claimed map[string]string, v *mergeVerdict) *mapFragment {
	if frag, err := loadFragment(pushedPath); err == nil {
		if verr := validateFragment(frag, slug, allow, claimed); verr == nil {
			if werr := copyAccepted(pushedPath, acceptedPath); werr != nil {
				fmt.Fprintf(os.Stderr, "merge-maps: persist accepted fragment for %s: %v\n", slug, werr)
			}
			v.Status = "ok"
			v.FragmentVersion = frag.Version
			return frag
		} else {
			v.Status = "rejected"
			v.Reason = verr.Error()
		}
	} else if !os.IsNotExist(err) {
		v.Status = "rejected"
		v.Reason = err.Error()
	}

	frag, err := loadFragment(acceptedPath)
	if err != nil {
		if v.Status == "rejected" {
			v.Reason += "; no previously accepted fragment — tenant contributes nothing"
		}
		return nil
	}
	if verr := validateFragment(frag, slug, allow, claimed); verr != nil {
		if v.Status != "rejected" {
			v.Status = "rejected"
			v.Reason = "previously accepted fragment no longer valid: " + verr.Error()
		} else {
			v.Reason += "; previously accepted fragment also invalid: " + verr.Error()
		}
		return nil
	}
	if v.Status == "rejected" {
		v.Reason += "; keeping last accepted fragment (v" + strconv.FormatInt(frag.Version, 10) + ")"
	} else {
		v.Status = "ok"
	}
	v.FragmentVersion = frag.Version
	return frag
}

// loadFragment reads a fragment defensively: it must be a regular file (never
// a symlink — the drop area is tenant-writable and this runs as root) under
// the size cap, and parse as JSON. Error text never echoes file content.
func loadFragment(path string) (*mapFragment, error) {
	info, err := os.Lstat(path)
	if err != nil {
		return nil, err
	}
	if !info.Mode().IsRegular() {
		return nil, fmt.Errorf("fragment is not a regular file")
	}
	if info.Size() > maxFragmentBytes {
		return nil, fmt.Errorf("fragment exceeds %d MiB", maxFragmentBytes/1024/1024)
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("fragment unreadable: %v", err)
	}
	var frag mapFragment
	if err := json.Unmarshal(data, &frag); err != nil {
		return nil, fmt.Errorf("fragment is not valid JSON")
	}
	if frag.Recipients == nil {
		frag.Recipients = map[string]routingEntry{}
	}
	if frag.Domains == nil {
		frag.Domains = map[string]domainEntry{}
	}
	return &frag, nil
}

// validateFragment enforces the domain-claim boundary: every domain the
// fragment names — hosted domains, forwarding domains, and each recipient's
// domain — must sit inside the tenant's allowlist, and must not already be
// claimed by another tenant on this shard. Any violation rejects the fragment
// WHOLE; nothing from it is installed.
func validateFragment(frag *mapFragment, slug string, allow []string, claimed map[string]string) error {
	if frag.FragmentFormat != 1 {
		return fmt.Errorf("unsupported fragment_format %d", frag.FragmentFormat)
	}
	if frag.Tenant != slug {
		return fmt.Errorf("fragment tenant %q does not match account %q", frag.Tenant, slug)
	}
	if len(frag.Domains) > 10000 || len(frag.Recipients) > 200000 {
		return fmt.Errorf("fragment exceeds sanity limits")
	}
	if frag.TransportPublicKey != "" {
		if _, err := decodePublicKey(frag.TransportPublicKey); err != nil {
			return fmt.Errorf("transport_public_key invalid: %v", err)
		}
	}
	checkDomain := func(dom, what string) error {
		dom = strings.ToLower(strings.TrimSpace(dom))
		if !domainRe.MatchString(dom) {
			return fmt.Errorf("%s %q is not a valid domain", what, dom)
		}
		if !allowlisted(dom, allow) {
			return fmt.Errorf("%s %q is not in this tenant's domain allowlist", what, dom)
		}
		if owner, taken := claimed[dom]; taken && owner != slug {
			return fmt.Errorf("%s %q is already claimed by another tenant on this relay", what, dom)
		}
		return nil
	}
	for dom := range frag.Domains {
		if err := checkDomain(dom, "domain"); err != nil {
			return err
		}
	}
	for _, fd := range frag.ForwardingDomains {
		if err := checkDomain(fd, "forwarding domain"); err != nil {
			return err
		}
	}
	for addr, e := range frag.Recipients {
		dom := domainOf(addr)
		if dom == "" {
			return fmt.Errorf("recipient %q has no domain part", addr)
		}
		if err := checkDomain(dom, "recipient domain"); err != nil {
			return err
		}
		if e.PublicKey != "" {
			if _, err := decodePublicKey(e.PublicKey); err != nil {
				return fmt.Errorf("recipient %q seal key invalid: %v", addr, err)
			}
		}
	}
	return nil
}

// allowlisted: "*" allows everything (the self-hosted fleet-of-one default —
// no other tenant exists to claim against); otherwise exact match or a
// subdomain of an allowed domain (forwarding subdomains ride their parent's
// claim).
func allowlisted(dom string, allow []string) bool {
	for _, a := range allow {
		if a == "*" || dom == a || strings.HasSuffix(dom, "."+a) {
			return true
		}
	}
	return false
}

// derivePostfixMaps ports RelayMapExporter's Postfix artifact derivation to
// the shard side, from validated routing data only. Deterministic (sorted,
// deduped) so an unchanged merge hashes identically and skips the reload.
func derivePostfixMaps(m *routingMap) (relayDomains, recipients, transport, srs string) {
	var rdLines, raLines, trLines, srsLines []string

	for dom, d := range m.Domains {
		rdLines = append(rdLines, dom+"\tOK")
		trLines = append(trLines, dom+"\tjoinery:")
		acceptAll := d.CatchAllMode != "none" && d.CatchAllMode != "" || !d.RejectUnmatched
		if acceptAll {
			raLines = append(raLines, dom+"\tOK")
		} else {
			raLines = append(raLines, dom+"\tREJECT")
		}
	}
	for addr := range m.Recipients {
		raLines = append(raLines, addr+"\tOK")
	}
	for _, tc := range m.Tenants {
		for _, fd := range tc.ForwardingDomains {
			fd = strings.ToLower(fd)
			rdLines = append(rdLines, fd+"\tOK")
			trLines = append(trLines, fd+"\tjoinery:")
			srsLines = append(srsLines, "/^SRS0=[^@]*@"+regexpQuoteDomain(fd)+"$/ OK")
		}
	}

	return joinSortedUnique(rdLines), joinSortedUnique(raLines),
		joinSortedUnique(trLines), joinSortedUnique(srsLines)
}

// regexpQuoteDomain escapes a validated domain for the Postfix regexp map —
// only '.' is special within the domain character set.
func regexpQuoteDomain(dom string) string {
	return strings.ReplaceAll(dom, ".", "\\.")
}

func joinSortedUnique(lines []string) string {
	if len(lines) == 0 {
		return ""
	}
	sort.Strings(lines)
	out := lines[:0]
	prev := ""
	for i, l := range lines {
		if i == 0 || l != prev {
			out = append(out, l)
		}
		prev = l
	}
	return strings.Join(out, "\n") + "\n"
}

func listTenantSlugs(tenantsDir string) ([]string, error) {
	entries, err := os.ReadDir(tenantsDir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, nil // fresh shard, no tenants yet
		}
		return nil, fmt.Errorf("read tenants dir %s: %w", tenantsDir, err)
	}
	var slugs []string
	for _, e := range entries {
		if e.IsDir() && slugRe.MatchString(e.Name()) {
			slugs = append(slugs, e.Name())
		}
	}
	sort.Strings(slugs)
	return slugs, nil
}

func readAllowlist(path string) []string {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil // missing allowlist = nothing allowed
	}
	var out []string
	for _, line := range strings.Split(string(data), "\n") {
		line = strings.ToLower(strings.TrimSpace(line))
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		out = append(out, line)
	}
	return out
}

func readLimits(path string) tenantLimits {
	var l tenantLimits
	if data, err := os.ReadFile(path); err == nil {
		_ = json.Unmarshal(data, &l)
	}
	return l
}

func normalizeDomainList(list []string) []string {
	var out []string
	seen := map[string]bool{}
	for _, d := range list {
		d = strings.ToLower(strings.TrimSpace(d))
		if d != "" && !seen[d] {
			seen[d] = true
			out = append(out, d)
		}
	}
	sort.Strings(out)
	return out
}

func copyAccepted(src, dst string) error {
	data, err := os.ReadFile(src)
	if err != nil {
		return err
	}
	return writeFileAtomic(dst, data, 0o600)
}

func writeVerdict(path string, v *mergeVerdict) {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return
	}
	if err := writeFileAtomic(path, append(data, '\n'), 0o644); err != nil {
		fmt.Fprintf(os.Stderr, "merge-maps: write verdict %s: %v\n", path, err)
	}
}

func writeFileAtomic(path string, data []byte, mode os.FileMode) error {
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, mode); err != nil {
		return err
	}
	if err := os.Chmod(tmp, mode); err != nil {
		os.Remove(tmp)
		return err
	}
	return os.Rename(tmp, path)
}

func chownToGroup(path, group string) {
	g, err := user.LookupGroup(group)
	if err != nil {
		return
	}
	gid, err := strconv.Atoi(g.Gid)
	if err != nil {
		return
	}
	_ = os.Chown(path, 0, gid)
}
