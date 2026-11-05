package main

import (
	"bytes"
	"encoding/binary"
	"io"
	"strconv"
	"strings"
	"testing"
	"time"
)

var fuzzHandle = strings.Repeat("c", 64)

// FuzzServeStream: whatever arrives on the socket, the daemon answers or
// ends the connection; it never panics (serveStream runs here without the
// connection's recover, so a panic fails the fuzz rather than being caught).
// The seeds are a well-formed frame of every operation, a held window for
// them to name, and a few malformed shapes.
var fuzzSeed = bytes.Repeat([]byte{4}, 32)

// fuzzSeeds is a well-formed frame of every operation, two that name the
// fuzz window and open real sealed bytes through it, and malformed shapes.
func fuzzSeeds() (seeds [][]byte) {
	frame := func(hdr string, parts ...[]byte) []byte {
		var b bytes.Buffer
		binary.Write(&b, binary.BigEndian, uint32(len(hdr)))
		b.WriteString(hdr)
		total := 0
		for _, p := range parts {
			total += len(p)
		}
		binary.Write(&b, binary.BigEndian, uint32(total))
		for _, p := range parts {
			b.Write(p)
		}
		return b.Bytes()
	}
	kek := bytes.Repeat([]byte{1}, 32)
	// A live window under a fixed handle, so handle-naming inputs reach the
	// crypto: real sealed blobs and a real chain entry for the fuzzer to bend.
	seed := fuzzSeed
	pk, _ := xwingPublicKey(seed)
	sealed, _ := sealV2([]byte("a content key, 32 bytes long ..."), pk, scopeDEK)
	retired := bytes.Repeat([]byte{6}, 32)
	entryWrapped, _ := aeadWrap(secretForm(retired), kek, []byte("vault:user:previous:3"))
	sealedKEK, _ := sealV2(kek, pk, scopeChain)
	num := func(n int) string { return strconv.Itoa(n) }
	seeds = append(seeds, frame(`{"op":"status"}`))
	seeds = append(seeds, frame(`{"op":"status","user":7}`))
	seeds = append(seeds, frame(`{"op":"open","user":7,"scope":"vault","idle":60,"wrap":1,"parts":[32,2]}`, kek, []byte("ad")))
	seeds = append(seeds, frame(`{"op":"open","user":7,"scope":"vault","idle":60,"unlock":true,"reveal":1,"parts":[10,32,2,5]}`,
		[]byte("v1.aead.a.b"), kek, []byte("ad"), []byte("xxxxx")))
	seeds = append(seeds, frame(`{"op":"unwrap","handle":"`+string(bytes.Repeat([]byte("a"), 64))+`","activity":true,"parts":[3]}`, []byte("abc")))
	seeds = append(seeds, frame(`{"op":"open_previous","handle":"`+string(bytes.Repeat([]byte("b"), 64))+`","parts":[1,1,1]}`, []byte("a"), []byte("b"), []byte("c")))
	seeds = append(seeds, frame(`{"op":"unwrap","handle":"`+fuzzHandle+`","activity":true,"parts":[`+num(len(sealed))+`,`+num(len(sealed))+`]}`, sealed, sealed))
	seeds = append(seeds, frame(`{"op":"open_previous","handle":"`+fuzzHandle+`","parts":[`+num(len(sealedKEK))+`,`+num(len(entryWrapped))+`,21]}`,
		sealedKEK, entryWrapped, []byte("vault:user:previous:3")))
	seeds = append(seeds, frame(`{"op":"close","handle":"`+fuzzHandle+`"}`))
	seeds = append(seeds, frame(`{"op":"close","user":7}`))
	seeds = append(seeds, frame(`{"op":"close","handle":"zz"}`))
	seeds = append(seeds, frame(`{"op":"status"} {"op":"status"}`))
	seeds = append(seeds, frame(`{"op":"unwrap","parts":[-1]}`))
	seeds = append(seeds, frame(`{"op":"unwrap","parts":[99999999999]}`))
	seeds = append(seeds, frame(`[1,2,3]`))
	seeds = append(seeds, frame(`{"op":7}`))
	seeds = append(seeds, []byte{0, 0, 0, 0})
	seeds = append(seeds, []byte{0xff, 0xff, 0xff, 0xff})
	seeds = append(seeds, append(frame(`{"op":"status"}`), frame(`{"op":"open","user":1,"scope":"s","idle":1}`)...))

	return seeds
}

// fuzzServer is a daemon holding one live window under fuzzHandle.
func fuzzServer() *server {
	st := newStore(16, 7*24*time.Hour)
	d := &daemon{store: st, started: time.Now()}
	pk, _ := xwingPublicKey(fuzzSeed)
	now := time.Now()
	st.windows[fuzzHandle] = &window{handle: fuzzHandle, user: 7, scope: "vault",
		seed: append([]byte(nil), fuzzSeed...), public: pk, opened: now, last: now, idle: time.Hour, absolute: time.Hour}
	return newServer(serverConfig{maxConns: 1, maxFrameBytes: 1 << 16, memoryBudget: 1 << 17,
		idleTimeout: time.Second, frameTimeout: time.Second}, d)
}

// The seeds that name the fuzz window must succeed, or the fuzz never reaches
// the X-Wing, GCM and chain-entry opens it exists to bend.
func TestFuzzSeedsReachTheCrypto(t *testing.T) {
	seeds := fuzzSeeds()
	for _, i := range []int{6, 7} {
		var out bytes.Buffer
		fuzzServer().serveStream(bytes.NewReader(seeds[i]), &out)
		if !bytes.Contains(out.Bytes(), []byte(`"ok":true`)) {
			t.Fatalf("seed %d did not open through the fuzz window: %q", i, out.String())
		}
	}
}

func FuzzServeStream(f *testing.F) {
	for _, s := range fuzzSeeds() {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, in []byte) {
		srv := fuzzServer()
		srv.serveStream(bytes.NewReader(in), io.Discard)
		if srv.inflight.Load() != 0 {
			t.Fatalf("the in-flight budget leaked %d bytes", srv.inflight.Load())
		}
	})
}
