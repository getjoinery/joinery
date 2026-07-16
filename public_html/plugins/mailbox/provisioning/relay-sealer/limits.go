package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Per-tenant shard-policy enforcement (specs/mailbox_relay_shared_fleet.md
// § Multi-tenancy on a shard). Limits arrive in the tenant's routing block —
// stamped there by the merge from the ROOT-OWNED tenants/<slug>/limits.json,
// never from the tenant's pushed fragment — so a tenant cannot raise its own
// caps. A zero limit means unlimited (the self-hosted fleet-of-one default).

// spoolQuotaExceeded reports whether a tenant's spool directory is over its
// entry-count or size cap. The scan is cheap in steady state (the spool drains
// on a short pull cadence, so it is near-empty); a tenant that stops pulling
// hits its cap and new mail defers at the sender instead of filling the disk.
func spoolQuotaExceeded(spoolDir string, tc tenantConfig) (bool, string) {
	if tc.SpoolMaxEntries <= 0 && tc.SpoolMaxMiB <= 0 {
		return false, ""
	}
	entries, err := os.ReadDir(spoolDir)
	if err != nil {
		// A missing/unreadable spool dir fails later at the durable write with
		// a temp-fail of its own; the quota check never blocks on it.
		return false, ""
	}
	count := 0
	var bytes int64
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".seal") {
			continue
		}
		count++
		if info, err := e.Info(); err == nil {
			bytes += info.Size()
		}
	}
	if tc.SpoolMaxEntries > 0 && count >= tc.SpoolMaxEntries {
		return true, "entries"
	}
	if tc.SpoolMaxMiB > 0 && bytes >= int64(tc.SpoolMaxMiB)*1024*1024 {
		return true, "size"
	}
	return false, ""
}

// forwardBucket is the hourly forward counter, persisted in the tenant's spool
// tmp/ working dir (sealer-writable, excluded from the pull, and the ack verb
// cannot touch it — ack ids reject path separators).
type forwardBucket struct {
	Hour  string `json:"hour"` // UTC YYYYMMDDHH
	Count int    `json:"count"`
}

// forwardAllowed consumes one token from the tenant's hourly forward bucket.
// Best-effort persistence: concurrent pipe deliveries may undercount slightly
// (the pipe runs a handful of processes), which errs on the permissive side —
// the limit is flood protection, not billing.
func forwardAllowed(spoolDir string, tc tenantConfig) bool {
	if tc.ForwardHourlyLimit <= 0 {
		return true
	}
	path := filepath.Join(spoolDir, "tmp", "forward_bucket.json")
	hour := time.Now().UTC().Format("2006010215")

	var b forwardBucket
	if data, err := os.ReadFile(path); err == nil {
		_ = json.Unmarshal(data, &b)
	}
	if b.Hour != hour {
		b = forwardBucket{Hour: hour}
	}
	if b.Count >= tc.ForwardHourlyLimit {
		return false
	}
	b.Count++
	if data, err := json.Marshal(b); err == nil {
		if err := os.MkdirAll(filepath.Dir(path), 0o700); err == nil {
			tmp := path + ".tmp"
			if os.WriteFile(tmp, data, 0o600) == nil {
				_ = os.Rename(tmp, path)
			}
		}
	}
	return true
}
