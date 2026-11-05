package main

// The five operations (specs/unseal_daemon.md § The operations).
//
//	open           user, scope, idle, absolute; unlock (a wrapped secret, its
//	               KEK and AD) or nothing (mint); wrap (KEK, AD pairs to wrap
//	               the secret under); reveal (sealed chain KEKs, unlock only)
//	               -> handle, version; parts [public, wrappings..., revealed...]
//	unwrap         handle, activity; parts [sealed...] -> parts [plaintext...]
//	open_previous  handle; parts [sealed chain KEK, wrapped retired secret, AD]
//	               -> a child handle, version; parts [public]
//	close          handle, or user -> closed, unwraps
//	status         nothing -> daemon, windows, uptime, held, memory_locked;
//	               or user -> open
//
// Two rules hold every secret in: the vault secret leaves only wrapped, only
// in the open that presented an unlocker for it or minted it (B1); a chain
// KEK leaves only through that same open's reveal list, and unwrap refuses
// the chain's scope (B2), so a handle opens content keys and nothing else.

import (
	"regexp"
	"time"
)

const daemonVersion = "1.1.0"

const (
	maxWrap   = 64
	maxReveal = 1024
)

var scopePattern = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,64}$`)

type daemon struct {
	store        *store
	started      time.Time
	memoryLocked bool
}

// handle runs one parsed request. The body's parts are the caller's to zero.
func (d *daemon) handle(req *request, parts [][]byte) (map[string]any, [][]byte, *opError) {
	switch req.Op {
	case "open":
		return d.open(req, parts)
	case "unwrap":
		return d.unwrap(req, parts)
	case "open_previous":
		return d.openPrevious(req, parts)
	case "close":
		return d.close(req, parts)
	case "status":
		return d.status(req, parts)
	}
	return nil, nil, errUnknownOp
}

func validHandle(h string) bool {
	if len(h) != 64 {
		return false
	}
	for i := 0; i < len(h); i++ {
		c := h[i]
		if !(c >= '0' && c <= '9' || c >= 'a' && c <= 'f') {
			return false
		}
	}
	return true
}

func (d *daemon) open(req *request, parts [][]byte) (map[string]any, [][]byte, *opError) {
	if req.Handle != "" || req.Activity || req.User <= 0 || !scopePattern.MatchString(req.Scope) {
		return nil, nil, errBadRequest
	}
	maxAbs := int64(d.store.maxAbsolute / time.Second)
	if req.Idle <= 0 || req.Idle > maxAbs || req.Absolute < 0 || req.Absolute > maxAbs {
		return nil, nil, errBadRequest
	}
	absolute := req.Absolute
	if absolute == 0 {
		absolute = maxAbs // no consumer registered a cap: the ceiling, never immortal
	}
	if req.Wrap < 0 || req.Wrap > maxWrap || req.Reveal < 0 || req.Reveal > maxReveal {
		return nil, nil, errBadRequest
	}
	if req.Reveal > 0 && !req.Unlock {
		return nil, nil, errBadRequest // a chain KEK leaves only where an unlocker was presented
	}
	want := 2*req.Wrap + req.Reveal
	if req.Unlock {
		want += 3
	}
	if len(parts) != want {
		return nil, nil, errBadRequest
	}
	if d.store.isHeld() {
		return nil, nil, errHeld
	}

	var seed []byte
	if req.Unlock {
		form, err := aeadUnwrap(parts[0], parts[1], parts[2])
		if err != nil {
			return nil, nil, errUnlockFailed
		}
		seed, err = seedFromSecretForm(form)
		zero(form)
		if err != nil {
			return nil, nil, errUnlockFailed
		}
		parts = parts[3:]
	} else {
		var err error
		if seed, err = randomSeed(); err != nil {
			return nil, nil, errInternal
		}
	}
	keep := false
	defer func() {
		if !keep {
			zero(seed)
		}
	}()

	public, err := xwingPublicKey(seed)
	if err != nil {
		return nil, nil, errInternal
	}
	out := [][]byte{append([]byte(nil), public...)} // the reply's parts are zeroed once sent; the window keeps its own

	form := secretForm(seed)
	for i := 0; i < req.Wrap; i++ {
		kek, ad := parts[2*i], parts[2*i+1]
		if len(kek) != kekBytes {
			zero(form)
			zeroAll(out[1:])
			return nil, nil, errBadRequest
		}
		wrapped, err := aeadWrap(form, kek, ad)
		if err != nil {
			zero(form)
			zeroAll(out[1:])
			return nil, nil, errInternal
		}
		out = append(out, wrapped)
	}
	zero(form)
	parts = parts[2*req.Wrap:]

	// A chain KEK opens under its own scope; a chain written before that
	// scope existed opens under the content one, and says so, so PHP can
	// re-seal it under its own.
	revealed := make([]string, 0, req.Reveal)
	for _, sealed := range parts {
		kek, scope := openChainKEK(sealed, seed)
		revealed = append(revealed, scope)
		if kek == nil {
			kek = []byte{}
		}
		out = append(out, kek)
	}

	h, oerr := d.store.add(req.User, req.Scope, seed, public, time.Duration(req.Idle)*time.Second,
		time.Duration(absolute)*time.Second)
	keep = true // the store owns the seed now, and zeroed it on a refusal
	if oerr != nil {
		zeroAll(out[1:])
		return nil, nil, oerr
	}
	reply := map[string]any{"ok": true, "handle": h, "version": keyVersion}
	if req.Reveal > 0 {
		reply["revealed"] = revealed
	}
	return reply, out, nil
}

// openChainKEK opens a sealed chain KEK with a seed: the chain's own scope
// first, then the content scope a chain from before the split was sealed
// under. ("", nil) when neither opens it.
func openChainKEK(sealed, seed []byte) ([]byte, string) {
	if kek, err := openV2(sealed, seed, scopeChain); err == nil {
		return kek, scopeChain
	}
	if kek, err := openV2(sealed, seed, scopeDEK); err == nil {
		return kek, scopeDEK
	}
	return nil, ""
}

func (d *daemon) unwrap(req *request, parts [][]byte) (map[string]any, [][]byte, *opError) {
	if !validHandle(req.Handle) || req.User != 0 || req.Scope != "" || req.Idle != 0 || req.Absolute != 0 ||
		req.Unlock || req.Wrap != 0 || req.Reveal != 0 {
		return nil, nil, errBadRequest
	}
	if req.Activity && len(parts) == 0 {
		return nil, nil, errBadRequest // activity is a content decrypt; an empty list decrypts nothing
	}
	seed, _, oerr := d.store.use(req.Handle)
	if oerr != nil {
		return nil, nil, oerr
	}
	defer zero(seed)
	out := make([][]byte, 0, len(parts))
	for _, sealed := range parts {
		// The content scope only. A chain KEK is sealed under its own scope
		// and never comes out here (B2).
		plain, err := openV2(sealed, seed, scopeDEK)
		if err != nil {
			zeroAll(out)
			return nil, nil, errUnsealFailed
		}
		out = append(out, plain)
	}
	d.store.touch(req.Handle, len(out), req.Activity)
	return map[string]any{"ok": true}, out, nil
}

func (d *daemon) openPrevious(req *request, parts [][]byte) (map[string]any, [][]byte, *opError) {
	if !validHandle(req.Handle) || req.User != 0 || req.Scope != "" || req.Idle != 0 || req.Absolute != 0 ||
		req.Unlock || req.Wrap != 0 || req.Reveal != 0 || req.Activity || len(parts) != 3 {
		return nil, nil, errBadRequest
	}
	seed, isChild, oerr := d.store.use(req.Handle)
	if oerr != nil {
		return nil, nil, oerr
	}
	defer zero(seed)
	if isChild {
		return nil, nil, errBadRequest // a chain is sealed to the current key, never to a retired one
	}
	kek, _ := openChainKEK(parts[0], seed)
	if kek == nil {
		return nil, nil, errUnsealFailed
	}
	form, err := aeadUnwrap(parts[1], kek, parts[2])
	zero(kek)
	if err != nil {
		return nil, nil, errUnlockFailed
	}
	retired, err := seedFromSecretForm(form)
	zero(form)
	if err != nil {
		return nil, nil, errUnlockFailed
	}
	public, err := xwingPublicKey(retired)
	if err != nil {
		zero(retired)
		return nil, nil, errInternal
	}
	h, oerr := d.store.addChild(req.Handle, retired, public)
	if oerr != nil {
		return nil, nil, oerr
	}
	return map[string]any{"ok": true, "handle": h, "version": keyVersion}, [][]byte{append([]byte(nil), public...)}, nil
}

func (d *daemon) close(req *request, parts [][]byte) (map[string]any, [][]byte, *opError) {
	if len(parts) != 0 || req.Scope != "" || req.Idle != 0 || req.Absolute != 0 || req.Unlock ||
		req.Wrap != 0 || req.Reveal != 0 || req.Activity {
		return nil, nil, errBadRequest
	}
	var n int
	var count uint64
	switch {
	case req.Handle != "" && req.User == 0 && validHandle(req.Handle):
		n, count = d.store.closeHandle(req.Handle)
	case req.Handle == "" && req.User > 0:
		n, count = d.store.closeUser(req.User)
	default:
		return nil, nil, errBadRequest
	}
	return map[string]any{"ok": true, "closed": n, "unwraps": count}, nil, nil
}

func (d *daemon) status(req *request, parts [][]byte) (map[string]any, [][]byte, *opError) {
	if len(parts) != 0 || req.Handle != "" || req.Scope != "" || req.Idle != 0 || req.Absolute != 0 ||
		req.Unlock || req.Wrap != 0 || req.Reveal != 0 || req.Activity || req.User < 0 {
		return nil, nil, errBadRequest
	}
	if req.User > 0 {
		return map[string]any{"ok": true, "open": d.store.hasUser(req.User)}, nil, nil
	}
	return map[string]any{
		"ok":            true,
		"daemon":        daemonVersion,
		"windows":       d.store.count(),
		"uptime":        int64(time.Since(d.started) / time.Second),
		"held":          d.store.isHeld(),
		"memory_locked": d.memoryLocked,
	}, nil, nil
}
