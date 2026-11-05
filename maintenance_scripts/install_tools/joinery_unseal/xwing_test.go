package main

import (
	"bytes"
	"crypto/mlkem"
	"crypto/mlkem/mlkemtest"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"testing"
)

// The shared vector file (specs/post_quantum_sealing.md R9): the draft's
// X-Wing known answers and the v2 seals includes/SealedBox.php made. xwing.go
// is the relay sealer's file, byte for byte; TestXWingIsTheSealers holds it so.
type xwingVectors struct {
	XWing []struct {
		Seed, PK, ESeed, CT, SS string
	} `json:"xwing"`
	Seal []struct {
		Scope        string `json:"scope"`
		SeedHex      string `json:"seed_hex"`
		PublicB64    string `json:"public_b64"`
		ESeedHex     string `json:"eseed_hex"`
		IVHex        string `json:"iv_hex"`
		PlaintextHex string `json:"plaintext_hex"`
		SealedB64    string `json:"sealed_b64"`
	} `json:"seal"`
}

const treeRoot = "../../../public_html"

func unhex(t *testing.T, s string) []byte {
	t.Helper()
	b, err := hex.DecodeString(s)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func fixedMLKEM(m []byte) mlkemEncapsulate {
	return func(ek *mlkem.EncapsulationKey768) ([]byte, []byte, error) {
		return mlkemtest.Encapsulate768(ek, m)
	}
}

func TestXWingVectors(t *testing.T) {
	raw, err := os.ReadFile(treeRoot + "/tests/vault/fixtures/xwing_vector.json")
	if err != nil {
		t.Fatalf("vector file: %v", err)
	}
	var v xwingVectors
	if err := json.Unmarshal(raw, &v); err != nil {
		t.Fatal(err)
	}
	if len(v.XWing) == 0 || len(v.Seal) == 0 {
		t.Fatal("the vector file holds no vectors")
	}
	for i, x := range v.XWing {
		seed, eseed := unhex(t, x.Seed), unhex(t, x.ESeed)
		pk, err := xwingPublicKey(seed)
		if err != nil || hex.EncodeToString(pk) != x.PK {
			t.Fatalf("vector %d: public key differs (%v)", i+1, err)
		}
		ss, ct, err := xwingEncapsulateWith(pk, eseed[32:], fixedMLKEM(eseed[:32]))
		if err != nil || hex.EncodeToString(ct) != x.CT || hex.EncodeToString(ss) != x.SS {
			t.Fatalf("vector %d: encapsulation differs (%v)", i+1, err)
		}
		got, err := xwingDecapsulate(seed, unhex(t, x.CT))
		if err != nil || hex.EncodeToString(got) != x.SS {
			t.Fatalf("vector %d: decapsulation differs (%v)", i+1, err)
		}
	}
	for _, s := range v.Seal {
		seed := unhex(t, s.SeedHex)
		sealed, _ := base64.StdEncoding.DecodeString(s.SealedB64)
		pt, err := openV2(sealed, seed, s.Scope)
		if err != nil || !bytes.Equal(pt, unhex(t, s.PlaintextHex)) {
			t.Fatalf("seal %q: SealedBox's seal does not open here (%v)", s.Scope, err)
		}
	}
}

func TestXWingIsTheSealers(t *testing.T) {
	ours, err1 := os.ReadFile("xwing.go")
	theirs, err2 := os.ReadFile(treeRoot + "/plugins/mailbox/provisioning/relay-sealer/xwing.go")
	if err2 != nil && os.IsNotExist(err2) {
		t.Skip("no relay sealer source on this box")
	}
	if err1 != nil || err2 != nil {
		t.Fatal(err1, err2)
	}
	if sha256.Sum256(ours) != sha256.Sum256(theirs) {
		t.Fatal("xwing.go has drifted from the relay sealer's: copy it again, one X-Wing for the platform")
	}
}
