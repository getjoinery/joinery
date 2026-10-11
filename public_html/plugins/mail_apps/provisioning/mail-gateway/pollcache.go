package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"strconv"
	"sync"
	"time"
)

// The shared poll (specs/mail_apps_plugin.md § Complexity and risk, "Load"):
// Thunderbird opens up to five connections per account, and each one that
// waits for mail would otherwise poll the site on its own. Polls and change
// listings are shared per (credential, mailbox, folder): a result younger
// than the TTL is reused by every connection of that credential, and calls
// already in flight are joined rather than repeated.

var (
	pollTTL      = 25 * time.Second // reuse a poll result this long
	idleInterval = 30 * time.Second // how often an IDLE connection checks
	noopInterval = 5 * time.Second  // the least time between two checks of one connection
)

// credKey identifies a credential without holding it: a hash of the site,
// username and secret. Two connections that typed the same password share.
func credKey(site string, cr Creds) string {
	h := sha256.New()
	h.Write([]byte(site))
	h.Write([]byte{0})
	h.Write([]byte(cr.Username))
	h.Write([]byte{0})
	h.Write([]byte(cr.Secret))
	return hex.EncodeToString(h.Sum(nil)[:16])
}

type cacheEntry struct {
	at      time.Time
	val     any
	err     error
	cost    int64 // bytes this entry holds in cacheBudget while kept
	done    chan struct{}
	pending bool
}

// cacheBudget is what kept poll answers may hold, all connections together.
// A change listing is kept only in its compact form, only when it is small
// (maxCachedChanges), and only while this budget has room; otherwise it is
// handed to the connections waiting for it and not kept.
var (
	cacheBudget      = NewBudget(8 << 20)
	maxCachedChanges = 2000
)

// SharedCache memoizes upstream answers by key for a short time.
type SharedCache struct {
	mu      sync.Mutex
	entries map[string]*cacheEntry
	ttl     func() time.Duration
}

func NewSharedCache() *SharedCache {
	ttl := pollTTL // read once: the sweeper must not race a later change
	c := &SharedCache{entries: map[string]*cacheEntry{}, ttl: func() time.Duration { return ttl }}
	go c.sweep()
	return c
}

func (c *SharedCache) sweep() {
	for {
		time.Sleep(5 * time.Second)
		c.mu.Lock()
		for k, e := range c.entries {
			if !e.pending && time.Since(e.at) > c.ttl() {
				c.dropLocked(k, e)
			}
		}
		c.mu.Unlock()
	}
}

func (c *SharedCache) dropLocked(k string, e *cacheEntry) {
	if c.entries[k] == e {
		delete(c.entries, k)
	}
	if e.cost > 0 {
		cacheBudget.Release(e.cost)
		e.cost = 0
	}
}

// Get answers the cached value for key when fresh (and fresh is allowed),
// otherwise runs fn once for all concurrent callers. fn answers the value and
// what keeping it would cost (0: free to keep, <0: do not keep). Errors are
// not kept. ctx bounds only this caller's wait: when it ends, this caller
// returns at once and the call carries on for the others (an IDLE ended by
// DONE need not wait for the site).
func (c *SharedCache) Get(ctx context.Context, key string, allowCached bool, fn func() (any, int64, error)) (any, error) {
	c.mu.Lock()
	if e, ok := c.entries[key]; ok {
		if e.pending {
			c.mu.Unlock()
			return wait(ctx, e)
		}
		if allowCached && e.err == nil && time.Since(e.at) < c.ttl() {
			c.mu.Unlock()
			return e.val, nil
		}
		c.dropLocked(key, e)
	}
	e := &cacheEntry{pending: true, done: make(chan struct{})}
	c.entries[key] = e
	c.mu.Unlock()

	go c.fill(key, e, fn)
	return wait(ctx, e)
}

func wait(ctx context.Context, e *cacheEntry) (any, error) {
	select {
	case <-e.done:
		return e.val, e.err
	case <-ctx.Done():
		return nil, ctx.Err()
	}
}

// fill runs fn and completes the entry whatever happens — a panic in fn
// included, which is logged rather than left to block every later caller.
func (c *SharedCache) fill(key string, e *cacheEntry, fn func() (any, int64, error)) {
	var (
		val  any
		cost int64
		err  error
	)
	defer func() {
		if r := recover(); r != nil {
			logf("poll cache: panic filling an entry: %v", r)
			val, cost, err = nil, -1, fmt.Errorf("internal error")
		}
		c.mu.Lock()
		e.val, e.err, e.at, e.pending = val, err, time.Now(), false
		keep := err == nil && cost >= 0 && c.entries[key] == e
		if keep && cost > 0 {
			keep = cacheBudget.TryAcquire(cost)
			if keep {
				e.cost = cost
			}
		}
		if !keep && c.entries[key] == e {
			delete(c.entries, key)
		}
		c.mu.Unlock()
		close(e.done)
	}()
	val, cost, err = fn()
}

// Pending reports whether a call for key is in progress.
func (c *SharedCache) Pending(key string) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	e, ok := c.entries[key]
	return ok && e.pending
}

// Invalidate drops every entry whose key starts with prefix (a folder's poll
// and its change listings, after this gateway changed that folder).
func (c *SharedCache) Invalidate(prefix string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	for k, e := range c.entries {
		if !e.pending && len(k) >= len(prefix) && k[:len(prefix)] == prefix {
			c.dropLocked(k, e)
		}
	}
}

func folderCacheKey(cred, mailbox, folder string) string {
	return cred + "\x00" + mailbox + "\x00" + folder + "\x00"
}

func changesKey(prefix string) string { return prefix + "changes" }

func sinceKey(prefix string, since uint64) string {
	return prefix + "since:" + strconv.FormatUint(since, 10)
}
