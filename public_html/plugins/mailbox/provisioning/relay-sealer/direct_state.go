package main

// The short-lived state a Direct delivery needs between its requests, plus the
// two limiters that bound how much work an unauthenticated caller can drive.
//
// All of it is in memory, and that is a deliberate choice with one exception.
// The relay is a single process fronting mail it does not store; sessions live
// at most fifteen minutes and a restart losing them costs a sender one retry.
// REPLAY state is the exception: a nonce cache that vanished on restart would
// let a captured preflight be replayed in the window between the restart and
// the envelope going stale, so nonces are also written to disk as empty files
// and pruned by age. Cheap, crash-safe, and it holds nothing but opaque nonces.

import (
	"os"
	"path/filepath"
	"sync"
	"time"
)

// directSession is what an accept opens: the admitted manifest, the verified
// sending identity, and the key generation answered. The content transfer
// redeems it — once.
type directSession struct {
	Nonce         string
	Kind          string
	Sender        string
	SenderDomain  string
	SenderKeyID   string
	Recipient     string
	Tenant        string
	Manifest      []directManifestEntry
	DeclaredBytes int64
	KeyGeneration int
	IsDecoy       bool
	CreatedAt     time.Time
	ExpiresAt     time.Time

	// The sender's own proof, kept so the ORIGIN BOX can re-verify it — the relay
	// authenticates at its edge but is untrusted with content, so its verdict is
	// not taken on trust downstream. Timestamp and PreflightSignature are captured
	// at preflight; the transfer signature arrives at commit.
	Timestamp          string
	PreflightSignature string

	// Parts arrive one request each and accumulate here until the commit
	// verifies them. Held in memory: the relay never writes a part to disk
	// until the whole delivery is verified, so a torn transfer leaves nothing.
	mu    sync.Mutex
	parts map[int][]byte
}

type directState struct {
	mu       sync.Mutex
	sessions map[string]*directSession
	nonces   map[string]time.Time
	// preflights per verified sending domain, for the per-instance limit.
	instanceHits map[string][]time.Time
	// lookups per connecting peer, for the pre-authentication limit.
	peerHits map[string][]time.Time

	nonceDir string
	ttl      time.Duration
}

func newDirectState(nonceDir string, ttl time.Duration) *directState {
	s := &directState{
		sessions:     map[string]*directSession{},
		nonces:       map[string]time.Time{},
		instanceHits: map[string][]time.Time{},
		peerHits:     map[string][]time.Time{},
		nonceDir:     nonceDir,
		ttl:          ttl,
	}
	s.loadNonces()
	return s
}

// claimNonce records a nonce as seen. False means it was already recorded — the
// caller refuses at request level, which discloses nothing about the recipient
// because only a replayer, who already holds the captured message, can trigger it.
func (s *directState) claimNonce(nonce string) bool {
	if nonce == "" || len(nonce) > 64 || !isHexToken(nonce) {
		return false
	}
	now := time.Now()
	s.mu.Lock()
	defer s.mu.Unlock()
	if seen, ok := s.nonces[nonce]; ok && now.Before(seen) {
		return false
	}
	expiry := now.Add(directNonceTTLSeconds * time.Second)
	s.nonces[nonce] = expiry
	s.persistNonce(nonce)
	return true
}

// openSession stores an accepted delivery's session.
//
// The TTL is a PARAMETER rather than shared state on the receiver: it comes
// from the tenant's relay-map block, so it varies per request, and assigning it
// to a field here would be a write to shared memory from every concurrent
// handler — a data race that would show up as sessions expiring at the wrong
// tenant's interval long before anyone suspected the cause.
func (s *directState) openSession(sess *directSession, ttl time.Duration) {
	if ttl <= 0 {
		ttl = s.ttl
	}
	sess.parts = map[int][]byte{}
	sess.CreatedAt = time.Now()
	sess.ExpiresAt = sess.CreatedAt.Add(ttl)
	s.mu.Lock()
	s.sessions[sess.Nonce] = sess
	s.mu.Unlock()
}

// liveSession returns the open, unexpired session for a nonce, or nil.
func (s *directState) liveSession(nonce string) *directSession {
	s.mu.Lock()
	defer s.mu.Unlock()
	sess, ok := s.sessions[nonce]
	if !ok || time.Now().After(sess.ExpiresAt) {
		return nil
	}
	return sess
}

// redeem consumes a session, atomically. Unknown, expired and already-redeemed
// are one answer, because they are one refusal on the wire.
func (s *directState) redeem(nonce string) *directSession {
	s.mu.Lock()
	defer s.mu.Unlock()
	sess, ok := s.sessions[nonce]
	if !ok || time.Now().After(sess.ExpiresAt) {
		delete(s.sessions, nonce)
		return nil
	}
	delete(s.sessions, nonce)
	return sess
}

// burn discards a session and its partial parts — a terminal failure consumes
// it exactly as completion does.
func (s *directState) burn(nonce string) {
	s.mu.Lock()
	delete(s.sessions, nonce)
	s.mu.Unlock()
}

// withinInstanceRate counts recent preflights from one VERIFIED sending
// instance, so one instance cannot flood this relay no matter which of a
// tenant's addresses it aims at. Not a blocked-sender lookup: an individual
// sender is never dropped early.
func (s *directState) withinInstanceRate(domain string, limit int, window time.Duration) bool {
	if limit <= 0 {
		return true
	}
	return s.withinRate(s.instanceHits, domain, limit, window)
}

// withinPeerRate bounds resolver work per connecting peer, BEFORE the request
// is authenticated — which is the only limit that can apply at that point.
func (s *directState) withinPeerRate(peer string, limit int, window time.Duration) bool {
	if limit <= 0 {
		return true
	}
	return s.withinRate(s.peerHits, peer, limit, window)
}

func (s *directState) withinRate(bucket map[string][]time.Time, key string, limit int, window time.Duration) bool {
	now := time.Now()
	cutoff := now.Add(-window)
	s.mu.Lock()
	defer s.mu.Unlock()
	hits := bucket[key]
	kept := hits[:0]
	for _, t := range hits {
		if t.After(cutoff) {
			kept = append(kept, t)
		}
	}
	if len(kept) >= limit {
		bucket[key] = kept
		return false
	}
	bucket[key] = append(kept, now)
	return true
}

// sweep drops expired sessions, nonces and rate-limit history. Called on a
// ticker by the server so a long-lived process does not grow without bound.
func (s *directState) sweep() {
	now := time.Now()
	s.mu.Lock()
	for nonce, sess := range s.sessions {
		if now.After(sess.ExpiresAt) {
			delete(s.sessions, nonce)
		}
	}
	for nonce, expiry := range s.nonces {
		if now.After(expiry) {
			delete(s.nonces, nonce)
		}
	}
	for key, hits := range s.instanceHits {
		if len(hits) == 0 || now.Sub(hits[len(hits)-1]) > time.Hour {
			delete(s.instanceHits, key)
		}
	}
	for key, hits := range s.peerHits {
		if len(hits) == 0 || now.Sub(hits[len(hits)-1]) > time.Hour {
			delete(s.peerHits, key)
		}
	}
	dir := s.nonceDir
	s.mu.Unlock()

	if dir == "" {
		return
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return
	}
	for _, e := range entries {
		info, err := e.Info()
		if err != nil {
			continue
		}
		if now.Sub(info.ModTime()) > directNonceTTLSeconds*time.Second {
			_ = os.Remove(filepath.Join(dir, e.Name()))
		}
	}
}

// persistNonce records a nonce on disk so a restart cannot reopen the replay
// window. Caller holds the lock. Best-effort: a nonce that cannot be written is
// still held in memory, and the freshness check bounds the exposure to the
// remainder of a five-minute window.
func (s *directState) persistNonce(nonce string) {
	if s.nonceDir == "" {
		return
	}
	if err := os.MkdirAll(s.nonceDir, 0o700); err != nil {
		return
	}
	_ = os.WriteFile(filepath.Join(s.nonceDir, nonce), nil, 0o600)
}

// loadNonces re-reads the on-disk nonces at startup, so a restart does not
// reopen a replay window that was already closed.
func (s *directState) loadNonces() {
	if s.nonceDir == "" {
		return
	}
	entries, err := os.ReadDir(s.nonceDir)
	if err != nil {
		return
	}
	now := time.Now()
	for _, e := range entries {
		info, err := e.Info()
		if err != nil {
			continue
		}
		age := now.Sub(info.ModTime())
		if age > directNonceTTLSeconds*time.Second {
			_ = os.Remove(filepath.Join(s.nonceDir, e.Name()))
			continue
		}
		s.nonces[e.Name()] = info.ModTime().Add(directNonceTTLSeconds * time.Second)
	}
}

func isHexToken(s string) bool {
	if s == "" {
		return false
	}
	for _, c := range s {
		if (c < '0' || c > '9') && (c < 'a' || c > 'f') && (c < 'A' || c > 'F') {
			return false
		}
	}
	return true
}
