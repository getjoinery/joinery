package main

// ping and --selftest: the two answers a supervisor and an installer ask for.
//
// ping asks a running daemon for its status over the socket, as the pool
// user would, and exits 0 only on an answer: the container keepalive's probe
// and the installer's smoke test. --selftest runs the daemon's crypto in this
// process against itself (mint, public key, seal and open under each scope,
// wrap and unwrap), touching no socket: proof the binary on this machine
// computes, before anything relies on it.

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"time"
)

func runPing(args []string) int {
	fs := flag.NewFlagSet("ping", flag.ContinueOnError)
	socket := fs.String("socket", "/run/joinery-unseal/unseal.sock", "the socket path")
	timeout := fs.Int("timeout", 3, "seconds before giving up")
	if err := fs.Parse(args); err != nil || fs.NArg() != 0 {
		return 2
	}
	fields, err := roundTrip(*socket, time.Duration(*timeout)*time.Second, map[string]any{"op": "status"})
	if err != nil {
		fmt.Fprintf(os.Stderr, "joinery-unseal ping: %v\n", err)
		return 1
	}
	if ok, _ := fields["ok"].(bool); !ok {
		fmt.Fprintf(os.Stderr, "joinery-unseal ping: %v\n", fields["error"])
		return 1
	}
	fmt.Printf("ok daemon=%v windows=%v held=%v memory_locked=%v uptime=%v\n",
		fields["daemon"], fields["windows"], fields["held"], fields["memory_locked"], fields["uptime"])
	return 0
}

// roundTrip sends one bodiless request and reads its reply header.
func roundTrip(socket string, timeout time.Duration, req map[string]any) (map[string]any, error) {
	c, err := net.DialTimeout("unix", socket, timeout)
	if err != nil {
		return nil, err
	}
	defer c.Close()
	c.SetDeadline(time.Now().Add(timeout))
	if err := writeFrame(c, req, nil); err != nil {
		return nil, err
	}
	hlen, err := readU32(c)
	if err != nil {
		return nil, err
	}
	if hlen == 0 || hlen > maxHeaderBytes {
		return nil, errors.New("the reply is out of frame")
	}
	hdr := make([]byte, hlen)
	if _, err := io.ReadFull(c, hdr); err != nil {
		return nil, err
	}
	var blen [4]byte
	if _, err := io.ReadFull(c, blen[:]); err != nil {
		return nil, err
	}
	if _, err := io.CopyN(io.Discard, c, int64(binary.BigEndian.Uint32(blen[:]))); err != nil {
		return nil, err
	}
	var fields map[string]any
	if err := json.Unmarshal(hdr, &fields); err != nil {
		return nil, err
	}
	return fields, nil
}

func runSelftest() int {
	if err := selftest(); err != nil {
		fmt.Fprintf(os.Stderr, "joinery-unseal selftest: FAILED: %v\n", err)
		return 1
	}
	fmt.Println("joinery-unseal selftest: ok")
	return 0
}

func selftest() error {
	seed, err := randomSeed()
	if err != nil {
		return err
	}
	defer zero(seed)
	pk, err := xwingPublicKey(seed)
	if err != nil || len(pk) != xwingPublicKeyBytes {
		return fmt.Errorf("public key: %v", err)
	}
	dek := []byte("0123456789abcdef0123456789abcdef")
	sealed, err := sealV2(dek, pk, scopeDEK)
	if err != nil {
		return fmt.Errorf("seal: %v", err)
	}
	if got, err := openV2(sealed, seed, scopeDEK); err != nil || !bytes.Equal(got, dek) {
		return fmt.Errorf("open under its scope: %v", err)
	}
	if _, err := openV2(sealed, seed, scopeChain); err == nil {
		return errors.New("a content seal opened under the chain scope")
	}
	chain, err := sealV2(dek, pk, scopeChain)
	if err != nil {
		return err
	}
	if _, err := openV2(chain, seed, scopeDEK); err == nil {
		return errors.New("a chain seal opened under the content scope")
	}
	other, _ := randomSeed()
	defer zero(other)
	if _, err := openV2(sealed, other, scopeDEK); err == nil {
		return errors.New("another key opened the seal")
	}
	kek, _ := randomSeed()
	defer zero(kek)
	form := secretForm(seed)
	defer zero(form)
	wrapped, err := aeadWrap(form, kek, []byte("selftest:ad"))
	if err != nil {
		return err
	}
	back, err := aeadUnwrap(wrapped, kek, []byte("selftest:ad"))
	if err != nil {
		return fmt.Errorf("unwrap: %v", err)
	}
	defer zero(back)
	again, err := seedFromSecretForm(back)
	if err != nil || !bytes.Equal(again, seed) {
		return errors.New("the wrapped secret did not come back")
	}
	zero(again)
	if _, err := aeadUnwrap(wrapped, kek, []byte("selftest:other")); err == nil {
		return errors.New("a wrapping opened under another AD")
	}
	return nil
}
