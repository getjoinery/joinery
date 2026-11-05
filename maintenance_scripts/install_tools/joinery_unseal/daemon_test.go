package main

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeClock is the store's now, moved by hand.
type fakeClock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *fakeClock) now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.t
}

func (c *fakeClock) add(d time.Duration) {
	c.mu.Lock()
	c.t = c.t.Add(d)
	c.mu.Unlock()
}

func newTestDaemon() (*daemon, *fakeClock) {
	st := newStore(100, 7*24*time.Hour)
	clk := &fakeClock{t: time.Now()}
	st.now = clk.now
	return &daemon{store: st, started: time.Now()}, clk
}

type result struct {
	fields map[string]any
	parts  [][]byte
	err    *opError
}

func call(d *daemon, req request, parts ...[]byte) result {
	fields, out, err := d.handle(&req, parts)
	return result{fields, out, err}
}

func mustOK(t *testing.T, r result, what string) result {
	t.Helper()
	if r.err != nil {
		t.Fatalf("%s: %v", what, r.err)
	}
	return r
}

func wantErr(t *testing.T, r result, code, what string) {
	t.Helper()
	if r.err == nil || r.err.code != code {
		t.Fatalf("%s: want %s, got %v", what, code, r.err)
	}
}

// aWrapping is what the database holds for a member: their seed wrapped under
// an unlocker's KEK, as PoolVaultKey::open() writes it.
func aWrapping(t *testing.T) (seed, kek, ad, wrapped []byte) {
	seed, _ = randomSeed()
	kek, _ = randomSeed()
	ad = []byte("vault:user:7:passkey:3")
	form := secretForm(seed)
	wrapped, err := aeadWrap(form, kek, ad)
	if err != nil {
		t.Fatal(err)
	}
	return
}

func openReq(user int64) request {
	return request{Op: "open", User: user, Scope: "vault", Idle: 900, Absolute: 3600}
}

func TestOpenMintsAndWraps(t *testing.T) {
	d, _ := newTestDaemon()
	kek1, kek2 := bytes.Repeat([]byte{1}, 32), bytes.Repeat([]byte{2}, 32)
	req := openReq(7)
	req.Wrap = 2
	r := mustOK(t, call(d, req, kek1, []byte("ad1"), kek2, []byte("ad2")), "mint")
	if len(r.parts) != 3 || len(r.parts[0]) != xwingPublicKeyBytes || r.fields["version"] != keyVersion {
		t.Fatalf("mint reply: %d parts, version %v", len(r.parts), r.fields["version"])
	}
	// Each wrapping opens to a seed whose public key is the one handed back.
	for i, kek := range [][]byte{kek1, kek2} {
		form, err := aeadUnwrap(r.parts[1+i], kek, []byte("ad"+string(rune('1'+i))))
		if err != nil {
			t.Fatalf("wrapping %d does not open: %v", i, err)
		}
		seed, err := seedFromSecretForm(form)
		if err != nil {
			t.Fatal(err)
		}
		pk, _ := xwingPublicKey(seed)
		if !bytes.Equal(pk, r.parts[0]) {
			t.Fatal("a wrapping holds another key")
		}
		if !strings.HasPrefix(string(r.parts[1+i]), "v1.aead.") {
			t.Fatal("not the v1.aead. form")
		}
	}
	// A short KEK in the wrap list is refused, and no window is left behind.
	req.Wrap = 1
	wantErr(t, call(d, req, []byte("short"), []byte("ad")), "bad_request", "short KEK")
	if d.store.count() != 1 {
		t.Fatalf("windows: %d", d.store.count())
	}
}

func TestOpenWithUnlockerAndUnwrap(t *testing.T) {
	d, _ := newTestDaemon()
	seed, kek, ad, wrapped := aWrapping(t)
	req := openReq(7)
	req.Unlock = true
	r := mustOK(t, call(d, req, wrapped, kek, ad), "unlock")
	pk, _ := xwingPublicKey(seed)
	if !bytes.Equal(r.parts[0], pk) {
		t.Fatal("the public key handed back is not the wrapping's")
	}
	h := r.fields["handle"].(string)

	dek1, dek2 := bytes.Repeat([]byte{9}, 32), []byte("a held Direct part, bigger than a key")
	s1, _ := sealV2(dek1, pk, scopeDEK)
	s2, _ := sealV2(dek2, pk, scopeDEK)
	u := mustOK(t, call(d, request{Op: "unwrap", Handle: h, Activity: true}, s1, s2), "unwrap")
	if len(u.parts) != 2 || !bytes.Equal(u.parts[0], dek1) || !bytes.Equal(u.parts[1], dek2) {
		t.Fatal("unwrap did not hand back the plaintexts in order")
	}
	// All or nothing: one blob sealed elsewhere fails the whole list.
	other, _ := randomSeed()
	opk, _ := xwingPublicKey(other)
	s3, _ := sealV2(dek1, opk, scopeDEK)
	wantErr(t, call(d, request{Op: "unwrap", Handle: h}, s1, s3), "unseal_failed", "a foreign blob")
	// The chain's scope never opens here (B2).
	chain, _ := sealV2(dek1, pk, scopeChain)
	wantErr(t, call(d, request{Op: "unwrap", Handle: h}, chain), "unseal_failed", "a chain KEK through unwrap")

	// A wrong KEK, a wrong AD, and a spliced row each fail as an unlock.
	wantErr(t, call(d, req, wrapped, bytes.Repeat([]byte{3}, 32), ad), "unlock_failed", "wrong KEK")
	wantErr(t, call(d, req, wrapped, kek, []byte("vault:user:8:passkey:3")), "unlock_failed", "wrong AD")
	wantErr(t, call(d, req, []byte("v1.aead.x.y"), kek, ad), "unlock_failed", "garbage wrapping")
	// A wrapping that holds something other than a v2 secret is refused too.
	notKey, _ := aeadWrap([]byte("dGhpcyBpcyBub3QgYSBrZXk"), kek, ad)
	wantErr(t, call(d, req, notKey, kek, ad), "unlock_failed", "an untagged secret")
}

func TestRevealOnlyWithAnUnlocker(t *testing.T) {
	d, _ := newTestDaemon()
	seed, kek, ad, wrapped := aWrapping(t)
	pk, _ := xwingPublicKey(seed)
	chainKEK := bytes.Repeat([]byte{5}, 32)
	newScope, _ := sealV2(chainKEK, pk, scopeChain)
	oldScope, _ := sealV2(chainKEK, pk, scopeDEK)
	garbage := []byte("not sealed")

	req := openReq(7)
	req.Reveal = 1
	wantErr(t, call(d, req, newScope), "bad_request", "a reveal list on a mint")

	req.Unlock = true
	req.Reveal = 3
	r := mustOK(t, call(d, req, wrapped, kek, ad, newScope, oldScope, garbage), "reveal")
	scopes := r.fields["revealed"].([]string)
	if len(r.parts) != 4 || scopes[0] != scopeChain || scopes[1] != scopeDEK || scopes[2] != "" {
		t.Fatalf("reveal scopes %v", scopes)
	}
	if !bytes.Equal(r.parts[1], chainKEK) || !bytes.Equal(r.parts[2], chainKEK) || len(r.parts[3]) != 0 {
		t.Fatal("reveal did not hand back the chain KEKs")
	}
}

func TestOpenPreviousHoldsTheRetiredKey(t *testing.T) {
	d, clk := newTestDaemon()
	seed, kek, ad, wrapped := aWrapping(t)
	pk, _ := xwingPublicKey(seed)
	req := openReq(7)
	req.Unlock = true
	h := mustOK(t, call(d, req, wrapped, kek, ad), "unlock").fields["handle"].(string)

	// A chain entry: the retired seed wrapped under a random KEK, the KEK
	// sealed to the current key (VaultPreviousKeys + sealChainKek()).
	retired, _ := randomSeed()
	rpk, _ := xwingPublicKey(retired)
	chainKEK, _ := randomSeed()
	entryAD := []byte("vault:user:previous:3")
	entryWrapped, _ := aeadWrap(secretForm(retired), chainKEK, entryAD)
	sealedKEK, _ := sealV2(chainKEK, pk, scopeChain)

	r := mustOK(t, call(d, request{Op: "open_previous", Handle: h}, sealedKEK, entryWrapped, entryAD), "open_previous")
	child := r.fields["handle"].(string)
	if !bytes.Equal(r.parts[0], rpk) || child == h {
		t.Fatal("the child is not the retired key")
	}
	// The same entry again is the same child, not a second one.
	again := mustOK(t, call(d, request{Op: "open_previous", Handle: h}, sealedKEK, entryWrapped, entryAD), "again")
	if again.fields["handle"] != child {
		t.Fatal("a second open_previous made a second child")
	}
	// A chain from before the scope split (sealed under dek) opens too.
	legacy, _ := sealV2(chainKEK, pk, scopeDEK)
	mustOK(t, call(d, request{Op: "open_previous", Handle: h}, legacy, entryWrapped, entryAD), "legacy chain")

	old, _ := sealV2([]byte("old content key"), rpk, scopeDEK)
	u := mustOK(t, call(d, request{Op: "unwrap", Handle: child, Activity: true}, old), "unwrap via child")
	if string(u.parts[0]) != "old content key" {
		t.Fatal("the child did not open content on the retired key")
	}
	// A child has no chain of its own.
	wantErr(t, call(d, request{Op: "open_previous", Handle: child}, sealedKEK, entryWrapped, entryAD), "bad_request", "a child's child")
	// A wrong AD on the entry is an unlock failure.
	wantErr(t, call(d, request{Op: "open_previous", Handle: h}, sealedKEK, entryWrapped, []byte("vault:user:previous:4")), "unlock_failed", "wrong entry AD")

	// The child lives on its parent's clocks: activity through the child keeps
	// the parent alive, and the parent's end is the child's.
	clk.add(800 * time.Second)
	mustOK(t, call(d, request{Op: "unwrap", Handle: child, Activity: true}, old), "child activity")
	clk.add(800 * time.Second)
	mustOK(t, call(d, request{Op: "unwrap", Handle: h}, ), "parent still open after the child's activity")
	// Closing the parent closes the child.
	c := mustOK(t, call(d, request{Op: "close", Handle: h}), "close")
	if c.fields["closed"] != 2 {
		t.Fatalf("closed %v, want parent and child", c.fields["closed"])
	}
	wantErr(t, call(d, request{Op: "unwrap", Handle: child}, old), "unknown_handle", "the child after its parent closed")
}

func TestClocks(t *testing.T) {
	d, clk := newTestDaemon()
	pk := func(r result) []byte { return r.parts[0] }
	req := openReq(7) // idle 900, absolute 3600
	r := mustOK(t, call(d, req), "open")
	h := r.fields["handle"].(string)
	s, _ := sealV2([]byte("k"), pk(r), scopeDEK)

	// An unwrap not flagged as activity extends nothing.
	clk.add(600 * time.Second)
	mustOK(t, call(d, request{Op: "unwrap", Handle: h}, s), "background unwrap")
	clk.add(400 * time.Second)
	wantErr(t, call(d, request{Op: "unwrap", Handle: h}, s), "unknown_handle", "idle clock")

	// Activity extends the idle clock, never the absolute one.
	r = mustOK(t, call(d, req), "open")
	h = r.fields["handle"].(string)
	s, _ = sealV2([]byte("k"), pk(r), scopeDEK)
	for i := 0; i < 4; i++ {
		clk.add(800 * time.Second)
		mustOK(t, call(d, request{Op: "unwrap", Handle: h, Activity: true}, s), "active")
	}
	clk.add(400 * time.Second) // 3600 since open
	wantErr(t, call(d, request{Op: "unwrap", Handle: h, Activity: true}, s), "unknown_handle", "absolute clock")

	// The sweeper ends a window nobody asks about.
	mustOK(t, call(d, req), "open")
	clk.add(901 * time.Second)
	if n := d.store.sweep(); n != 1 || d.store.count() != 0 {
		t.Fatalf("sweep ended %d", n)
	}

	// The seven-day ceiling: refused above, the ceiling when no cap is named.
	req.Absolute = 7*24*3600 + 1
	wantErr(t, call(d, req), "bad_request", "an absolute cap past the ceiling")
	req.Absolute = 0
	req.Idle = 7 * 24 * 3600
	r = mustOK(t, call(d, req), "no cap named")
	h = r.fields["handle"].(string)
	clk.add(7*24*time.Hour - time.Second)
	mustOK(t, call(d, request{Op: "status", User: 7}), "status")
	if !d.store.hasUser(7) {
		t.Fatal("the window ended before the ceiling")
	}
	clk.add(time.Second)
	if d.store.hasUser(7) {
		t.Fatal("a window with no cap outlived the seven-day ceiling")
	}
}

func TestCloseAndStatus(t *testing.T) {
	d, _ := newTestDaemon()
	a := mustOK(t, call(d, openReq(7)), "a").fields["handle"].(string)
	mustOK(t, call(d, openReq(7)), "b")
	mustOK(t, call(d, openReq(8)), "c")
	s := mustOK(t, call(d, request{Op: "status"}), "status")
	if s.fields["windows"] != 3 || s.fields["daemon"] != daemonVersion || s.fields["held"] != false {
		t.Fatalf("status %v", s.fields)
	}
	if _, listed := s.fields["users"]; listed {
		t.Fatal("status lists who is open")
	}
	if mustOK(t, call(d, request{Op: "status", User: 7}), "user").fields["open"] != true {
		t.Fatal("user 7 reads closed")
	}
	c := mustOK(t, call(d, request{Op: "close", User: 7}), "close by user")
	if c.fields["closed"] != 2 {
		t.Fatalf("closed %v", c.fields["closed"])
	}
	wantErr(t, call(d, request{Op: "unwrap", Handle: a}, []byte("x")), "unknown_handle", "a handle after close")
	if mustOK(t, call(d, request{Op: "status", User: 7}), "user").fields["open"] != false {
		t.Fatal("user 7 still reads open")
	}
	if mustOK(t, call(d, request{Op: "status", User: 8}), "user").fields["open"] != true {
		t.Fatal("closing user 7 closed user 8")
	}
	// Closing an unknown handle is not an error: it is already closed.
	if mustOK(t, call(d, request{Op: "close", Handle: strings.Repeat("a", 64)}), "close").fields["closed"] != 0 {
		t.Fatal("closed something that was not there")
	}
	wantErr(t, call(d, request{Op: "close", Handle: a, User: 7}), "bad_request", "close names both")
	wantErr(t, call(d, request{Op: "close"}), "bad_request", "close names neither")
}

func TestDropAndHold(t *testing.T) {
	d, _ := newTestDaemon()
	h := mustOK(t, call(d, openReq(7)), "open").fields["handle"].(string)
	if n := d.store.dropAndHold(); n != 1 {
		t.Fatalf("dropped %d", n)
	}
	wantErr(t, call(d, request{Op: "unwrap", Handle: h}, []byte("x")), "unknown_handle", "a handle after the drop")
	wantErr(t, call(d, openReq(7)), "held", "an open while held")
	if mustOK(t, call(d, request{Op: "status"}), "status").fields["held"] != true {
		t.Fatal("status does not say held")
	}
	d.store.setHeld(false)
	mustOK(t, call(d, openReq(7)), "an open after the hold lifts")
}

func TestCapacityAndShapes(t *testing.T) {
	d, _ := newTestDaemon()
	d.store.maxWindows = 2
	mustOK(t, call(d, openReq(1)), "1")
	mustOK(t, call(d, openReq(2)), "2")
	wantErr(t, call(d, openReq(3)), "at_capacity", "past the window cap")

	d, _ = newTestDaemon()
	for _, bad := range []request{
		{Op: "open", User: 0, Scope: "vault", Idle: 1},
		{Op: "open", User: 1, Scope: "", Idle: 1},
		{Op: "open", User: 1, Scope: "has space", Idle: 1},
		{Op: "open", User: 1, Scope: "vault", Idle: 0},
		{Op: "open", User: 1, Scope: "vault", Idle: 1, Absolute: -1},
		{Op: "open", User: 1, Scope: "vault", Idle: 1, Handle: strings.Repeat("a", 64)},
		{Op: "open", User: 1, Scope: "vault", Idle: 1, Wrap: 1},
		{Op: "unwrap", Handle: "short"},
		{Op: "unwrap", Handle: strings.Repeat("A", 64)},
		{Op: "status", Scope: "x"},
		{Op: "open_previous", Handle: strings.Repeat("a", 64)},
	} {
		if r := call(d, bad); r.err == nil || r.err.code != "bad_request" {
			t.Fatalf("%+v: want bad_request, got %v", bad, r.err)
		}
	}
	wantErr(t, call(d, request{Op: "wrap"}), "unknown_op", "the operation B1 removed")
}

// No request shape returns the secret in any form but a wrapping from open.
func TestNoShapeRevealsTheSecret(t *testing.T) {
	d, _ := newTestDaemon()
	seed, kek, ad, wrapped := aWrapping(t)
	req := openReq(7)
	req.Unlock = true
	r := mustOK(t, call(d, req, wrapped, kek, ad), "unlock")
	h := r.fields["handle"].(string)
	form := string(secretForm(seed))
	needles := [][]byte{seed, []byte(form), []byte(b64url(seed))}
	check := func(what string, res result) {
		t.Helper()
		hdr, _ := json.Marshal(res.fields)
		for _, p := range append(res.parts, hdr) {
			for _, n := range needles {
				if bytes.Contains(p, n) {
					t.Fatalf("%s returned the secret", what)
				}
			}
		}
	}
	check("open", r)
	check("unwrap", call(d, request{Op: "unwrap", Handle: h}))
	check("status", call(d, request{Op: "status"}))
	check("status user", call(d, request{Op: "status", User: 7}))
	for _, op := range []string{"wrap", "export", "reveal", "get", "dump", "seed", "secret"} {
		check(op, call(d, request{Op: op, Handle: h}))
	}
	check("close", call(d, request{Op: "close", Handle: h}))
}

// The wire, end to end, on a real socket: the uid check, framing, reuse of
// one connection for several trips, and a bad frame ending one connection
// and nothing else.
func TestSocket(t *testing.T) {
	dir := t.TempDir()
	sock := filepath.Join(dir, "u.sock")
	d, _ := newTestDaemon()
	l, own, err := listen(sock, 0o660)
	if err != nil {
		t.Fatal(err)
	}
	defer own.release()
	fi, _ := os.Stat(sock)
	if fi.Mode().Perm() != 0o660 {
		t.Fatalf("socket mode %v", fi.Mode().Perm())
	}
	cfg := serverConfig{socket: sock, allowUID: os.Getuid(), maxConns: 4, maxFrameBytes: 1 << 20,
		memoryBudget: 2 << 20, idleTimeout: 5 * time.Second, frameTimeout: 5 * time.Second}
	srv := newServer(cfg, d)
	go srv.serve(l)
	defer l.Close()

	c, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	send := func(c net.Conn, fields map[string]any, parts ...[]byte) (map[string]any, [][]byte) {
		t.Helper()
		if err := writeFrame(c, fields, parts); err != nil {
			t.Fatal(err)
		}
		hdr, body, _, err := readFrame(c, 1<<30, func(int64) bool { return true }, func(int64) {})
		if err != nil {
			t.Fatalf("reading the reply: %v", err)
		}
		var out map[string]any
		json.Unmarshal(hdr, &out)
		var split [][]byte
		var at int64
		if lens, ok := out["parts"].([]any); ok {
			for _, n := range lens {
				ln := int64(n.(float64))
				split = append(split, body[at:at+ln])
				at += ln
			}
		}
		return out, split
	}
	out, parts := send(c, map[string]any{"op": "open", "user": 7, "scope": "vault", "idle": 60})
	if out["ok"] != true || len(parts) != 1 {
		t.Fatalf("open over the socket: %v", out)
	}
	s, _ := sealV2([]byte("over the wire"), parts[0], scopeDEK)
	out, parts = send(c, map[string]any{"op": "unwrap", "handle": out["handle"], "activity": true}, s)
	if out["ok"] != true || string(parts[0]) != "over the wire" {
		t.Fatalf("unwrap over the socket: %v", out)
	}
	out, _ = send(c, map[string]any{"op": "open", "user": 7, "scope": "vault", "idle": 60, "surprise": 1})
	if out["error"] != "bad_request" {
		t.Fatalf("an unknown field: %v", out)
	}

	// Over the frame cap: a reply, then the connection ends; the daemon does not.
	big, _ := net.Dial("unix", sock)
	var hdr [4]byte
	h := []byte(`{"op":"status"}`)
	binary.BigEndian.PutUint32(hdr[:], uint32(len(h)))
	big.Write(hdr[:])
	big.Write(h)
	binary.BigEndian.PutUint32(hdr[:], 2<<20)
	big.Write(hdr[:])
	rh, _, _, _ := readFrame(big, 1<<20, func(int64) bool { return true }, func(int64) {})
	if !strings.Contains(string(rh), "too_large") {
		t.Fatalf("over the cap: %s", rh)
	}
	if _, err := big.Read(make([]byte, 1)); err != io.EOF {
		t.Fatalf("the connection stayed open past an oversized frame: %v", err)
	}
	big.Close()

	// A header length of zero is out of frame: the connection ends.
	z, _ := net.Dial("unix", sock)
	z.Write([]byte{0, 0, 0, 0})
	z.SetReadDeadline(time.Now().Add(2 * time.Second))
	if _, err := z.Read(make([]byte, 1)); err != io.EOF {
		t.Fatalf("a zero header did not end the connection: %v", err)
	}
	z.Close()

	// And the first connection still works.
	if out, _ = send(c, map[string]any{"op": "status"}); out["ok"] != true || out["windows"] != float64(1) {
		t.Fatalf("status after the bad frames: %v", out)
	}

	// Another uid is refused: the server serves only cfg.allowUID.
	sock2 := filepath.Join(dir, "other.sock")
	l2, own2, err := listen(sock2, 0o660)
	if err != nil {
		t.Fatal(err)
	}
	defer own2.release()
	defer l2.Close()
	cfg2 := cfg
	cfg2.allowUID = os.Getuid() + 1
	go newServer(cfg2, d).serve(l2)
	o, _ := net.Dial("unix", sock2)
	writeFrame(o, map[string]any{"op": "status"}, nil)
	o.SetReadDeadline(time.Now().Add(2 * time.Second))
	if n, err := o.Read(make([]byte, 1)); n != 0 || err == nil || os.IsTimeout(err) {
		t.Fatalf("a connection from another uid was answered: %d %v", n, err) // EOF or a reset: closed unanswered
	}
	o.Close()
}

func TestSelftest(t *testing.T) {
	if err := selftest(); err != nil {
		t.Fatal(err)
	}
}

// Only an unwrap that opened something counts, or extends the window: an
// empty activity unwrap is refused, and a failed list extends nothing.
func TestOnlyASuccessfulOpenExtends(t *testing.T) {
	d, clk := newTestDaemon()
	r := mustOK(t, call(d, openReq(7)), "open") // idle 900
	h := r.fields["handle"].(string)
	good, _ := sealV2([]byte("k"), r.parts[0], scopeDEK)
	other, _ := randomSeed()
	opk, _ := xwingPublicKey(other)
	foreign, _ := sealV2([]byte("k"), opk, scopeDEK)

	wantErr(t, call(d, request{Op: "unwrap", Handle: h, Activity: true}), "bad_request", "an empty activity unwrap")
	clk.add(800 * time.Second)
	wantErr(t, call(d, request{Op: "unwrap", Handle: h, Activity: true}, foreign), "unseal_failed", "a failed list")
	clk.add(200 * time.Second)
	wantErr(t, call(d, request{Op: "unwrap", Handle: h, Activity: true}, good), "unknown_handle",
		"a failed list kept the window alive")

	r = mustOK(t, call(d, openReq(7)), "open")
	h = r.fields["handle"].(string)
	good, _ = sealV2([]byte("k"), r.parts[0], scopeDEK)
	mustOK(t, call(d, request{Op: "unwrap", Handle: h, Activity: true}, good, good), "two")
	call(d, request{Op: "unwrap", Handle: h, Activity: true}, good, foreign)
	if c := mustOK(t, call(d, request{Op: "close", Handle: h}), "close"); c.fields["unwraps"] != uint64(2) {
		t.Fatalf("unwraps %v: a failed list was counted", c.fields["unwraps"])
	}
}

// Two daemons never share a socket path, and one never unlinks another's.
func TestOneDaemonPerSocket(t *testing.T) {
	dir := t.TempDir()
	sock := filepath.Join(dir, "u.sock")
	l, own, err := listen(sock, 0o660)
	if err != nil {
		t.Fatal(err)
	}
	if _, _, err := listen(sock, 0o660); err == nil {
		t.Fatal("a second daemon took a socket the first holds")
	}
	// The first exits: its socket goes, and the next one starts cleanly.
	l.Close()
	own.release()
	if _, err := os.Lstat(sock); !os.IsNotExist(err) {
		t.Fatal("the socket outlived its daemon")
	}
	l2, own2, err := listen(sock, 0o660)
	if err != nil {
		t.Fatalf("a daemon could not start after the first exited: %v", err)
	}
	// A crash leaves a stale socket and no lock: the next start replaces it.
	l2.Close()
	own2.lock.Close()
	if _, err := os.Lstat(sock); err != nil {
		t.Fatal("expected the stale socket to remain after a crash")
	}
	l3, own3, err := listen(sock, 0o660)
	if err != nil {
		t.Fatalf("a stale socket blocked the next start: %v", err)
	}
	// A socket at the path that is not this process's is never unlinked.
	l3.Close()
	os.Remove(sock)
	l4, err := net.ListenUnix("unix", &net.UnixAddr{Name: sock, Net: "unix"})
	if err != nil {
		t.Fatal(err)
	}
	defer l4.Close()
	own3.release()
	if _, err := os.Lstat(sock); err != nil {
		t.Fatal("release() unlinked a socket another process made")
	}
}
