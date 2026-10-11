package main

import (
	"container/list"
	"context"
	"crypto/tls"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
	"unsafe"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"
)

func TestBudget(t *testing.T) {
	b := NewBudget(100)
	if err := b.Acquire(context.Background(), 101); err == nil {
		t.Fatalf("more than the whole budget must fail at once")
	}
	if err := b.Acquire(context.Background(), 60); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()
	if err := b.Acquire(ctx, 60); err == nil {
		t.Fatalf("over budget must wait and then fail")
	}
	go func() { time.Sleep(30 * time.Millisecond); b.Release(60) }()
	if err := b.Acquire(context.Background(), 60); err != nil {
		t.Fatalf("a release must wake a waiter: %v", err)
	}
	if b.Used() != 60 {
		t.Errorf("used %d", b.Used())
	}
}

func TestBodyStoreIsGatewayWide(t *testing.T) {
	store := newBodyStore(128 << 20) // share: 32 MiB per connection
	shared := make([]byte, 40<<20)   // one backing array; only lengths count
	a := &bodyCache{store: store, items: map[uint32]*list.Element{}}
	b := &bodyCache{store: store, items: map[uint32]*list.Element{}}
	mb := func() []byte { return shared[:20<<20] }
	a.put(1, mb())
	if a.get(1) == nil {
		t.Fatalf("not kept")
	}
	// One connection keeps at most its share: its own oldest goes.
	a.put(2, mb())
	if a.get(1) != nil || a.get(2) == nil {
		t.Errorf("per-connection share not held")
	}
	// Too big for a share: not kept at all.
	a.put(3, shared[:33<<20])
	if a.get(3) != nil {
		t.Errorf("a message over the share was kept")
	}
	// Across connections the whole store is bounded; oldest go first.
	caches := []*bodyCache{a, b}
	for i := 0; i < 6; i++ {
		c := &bodyCache{store: store, items: map[uint32]*list.Element{}}
		caches = append(caches, c)
		c.put(uint32(10+i), mb())
	}
	if store.Total() > 128<<20 {
		t.Errorf("store holds %d bytes over its %d cap", store.Total(), 128<<20)
	}
	if a.get(2) != nil {
		t.Errorf("the oldest entry survived eviction")
	}
	for _, c := range caches {
		c.clear()
	}
	if store.Total() != 0 {
		t.Errorf("after clear: %d", store.Total())
	}
}

func TestInflightBudgetRefusesWhenSpent(t *testing.T) {
	h := startHarness(t)
	big := testMessage("Big", "bob@example.org", strings.Repeat("z", 1200<<10))
	uid := h.site.Add("inbox", big, nil, false)
	c := dialIMAP(t, h, nil)
	if err := c.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Select("INBOX", nil).Wait(); err != nil {
		t.Fatal(err)
	}
	saved, savedWait := inflight, inflightWait
	inflight, inflightWait = NewBudget(3<<20), 100*time.Millisecond
	defer func() { inflight, inflightWait = saved, savedWait }()

	_, err := c.Fetch(imap.UIDSetNum(imap.UID(uid)), &imap.FetchOptions{BodySection: []*imap.FetchItemBodySection{{Peek: true}}}).Collect()
	if imapCode(err) != imap.ResponseCodeLimit {
		t.Fatalf("over the in-flight budget: %v", err)
	}
	if inflight.Used() != 0 {
		t.Errorf("a refused call left %d bytes reserved", inflight.Used())
	}

	// With room, the same fetch works and gives everything back.
	inflight = NewBudget(160 << 20)
	msgs, err := c.Fetch(imap.UIDSetNum(imap.UID(uid)), &imap.FetchOptions{BodySection: []*imap.FetchItemBodySection{{Peek: true}}}).Collect()
	if err != nil || len(msgs) != 1 || len(msgs[0].BodySection[0].Bytes) != len(big) {
		t.Fatalf("fetch with room: %v", err)
	}
	if !waitFor(func() bool { return inflight.Used() == 0 }) {
		t.Errorf("%d bytes still reserved after the fetch", inflight.Used())
	}
}

func TestPerCredentialCap(t *testing.T) {
	h := startHarness(t)
	h.gw.credLimit = NewKeyedLimiter(2)
	var clients []*imapclient.Client
	for i := 0; i < 2; i++ {
		c := dialIMAP(t, h, nil)
		if err := c.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
			t.Fatalf("login %d: %v", i, err)
		}
		clients = append(clients, c)
	}
	third := dialIMAP(t, h, nil)
	if err := third.Login(h.site.mailbox, h.site.password).Wait(); imapCode(err) != imap.ResponseCodeLimit {
		t.Fatalf("third connection of one credential: %v", err)
	}
	clients[0].Logout().Wait()
	clients[0].Close()
	ok := waitFor(func() bool {
		c := dialIMAP(t, h, nil)
		defer c.Close()
		return c.Login(h.site.mailbox, h.site.password).Wait() == nil
	})
	if !ok {
		t.Errorf("a closed connection did not give its place back")
	}
}

func TestReloadDropsDisabledSiteOnly(t *testing.T) {
	h := startHarness(t)
	two := fmt.Sprintf(`{"site":"two","hostname":"two.example.test","upstream":%q,"cert_file":%q,"key_file":%q}`, h.upURL, h.certPath, h.keyPath)
	os.WriteFile(filepath.Join(h.dir, "sites", "two.json"), []byte(two), 0o644)
	os.WriteFile(filepath.Join(h.dir, "sites", "two.enabled"), nil, 0o644)
	if err := h.gw.Reload(); err != nil {
		t.Fatal(err)
	}
	dial := func(name string) (*imapclient.Client, error) {
		return imapclient.DialTLS(h.imapAddr, &imapclient.Options{TLSConfig: &tls.Config{InsecureSkipVerify: true, ServerName: name}})
	}
	cOne, err := dial(testHost)
	if err != nil {
		t.Fatal(err)
	}
	defer cOne.Close()
	cTwo, err := dial("two.example.test")
	if err != nil {
		t.Fatal(err)
	}
	defer cTwo.Close()
	for _, c := range []*imapclient.Client{cOne, cTwo} {
		if err := c.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
			t.Fatal(err)
		}
	}

	// "off" for the first site: its marker goes, then SIGHUP (Reload).
	os.Remove(filepath.Join(h.dir, "sites", "example.enabled"))
	if err := h.gw.Reload(); err != nil {
		t.Fatal(err)
	}
	select {
	case <-cOne.Closed():
	case <-time.After(3 * time.Second):
		t.Fatalf("the disabled site's connection stayed open")
	}
	if err := cTwo.Noop().Wait(); err != nil {
		t.Fatalf("the other site's connection was disturbed: %v", err)
	}
	if c, err := dial(testHost); err == nil {
		c.Close()
		t.Errorf("the disabled site's name still completes a handshake")
	}
	c, err := dial("two.example.test")
	if err != nil {
		t.Fatalf("the other site stopped accepting: %v", err)
	}
	c.Close()
}

func TestFolderListBudget(t *testing.T) {
	if unsafe.Sizeof(message{}) > 48 {
		t.Fatalf("a message costs %d bytes; the aim is at most 48", unsafe.Sizeof(message{}))
	}
	h := startHarness(t)
	for i := 0; i < 6; i++ {
		h.site.Add("inbox", testMessage(fmt.Sprintf("M%d", i), "bob@example.org", "x"), nil, false)
	}
	saved, savedWait := folderLists, folderWait
	// Two lists of 6 fit (384 bytes) and a third does not; A growing to 13
	// (416) fits only once B has let go.
	folderLists, folderWait = NewBudget(500), 100*time.Millisecond
	defer func() { folderLists, folderWait = saved, savedWait }()
	budget := folderLists

	login := func() *imapclient.Client {
		c := dialIMAP(t, h, nil)
		if err := c.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
			t.Fatal(err)
		}
		return c
	}
	a, b, c := login(), login(), login()

	// Fill: two folders open hold 2 x 6 x 32 bytes.
	if _, err := a.Select("INBOX", nil).Wait(); err != nil {
		t.Fatalf("A: %v", err)
	}
	if _, err := b.Select("INBOX", nil).Wait(); err != nil {
		t.Fatalf("B: %v", err)
	}
	if got := budget.Used(); got != 2*6*messageBytes {
		t.Errorf("reserved %d, want %d", got, 2*6*messageBytes)
	}
	// Refuse: a third does not fit.
	_, err := c.Select("INBOX", nil).Wait()
	if imapCode(err) != imap.ResponseCodeLimit || !strings.Contains(err.Error(), "The mail gateway is busy; try again shortly.") {
		t.Fatalf("over the folder-list budget: %v", err)
	}

	// New mail needs the list to grow; with no room it waits, and nothing is lost.
	h.site.Add("inbox", testMessage("New1", "bob@example.org", "x"), nil, false)
	h.site.Add("inbox", testMessage("New2", "bob@example.org", "x"), nil, false)
	time.Sleep(60 * time.Millisecond)
	if err := a.Noop().Wait(); err != nil {
		t.Fatal(err)
	}
	if n := a.Mailbox().NumMessages; n != 6 {
		t.Errorf("grew past the budget: %d messages", n)
	}

	// Release: B lets go, and A's next poll brings the new mail in.
	if err := b.Unselect().Wait(); err != nil {
		t.Fatal(err)
	}
	if got := budget.Used(); got != 6*messageBytes {
		t.Errorf("after UNSELECT reserved %d, want %d", got, 6*messageBytes)
	}
	time.Sleep(60 * time.Millisecond)
	if err := a.Noop().Wait(); err != nil {
		t.Fatal(err)
	}
	if !waitFor(func() bool { return a.Mailbox().NumMessages == 8 }) {
		t.Errorf("new mail did not arrive once there was room: %d", a.Mailbox().NumMessages)
	}

	// A re-SELECT gives back the old list before taking the new one (416 +
	// 256 would not fit); closing gives back the rest.
	if _, err := a.Select("INBOX", nil).Wait(); err != nil {
		t.Fatalf("A re-SELECT: %v", err)
	}
	if got := budget.Used(); got != 8*messageBytes {
		t.Errorf("after re-SELECT reserved %d, want %d", got, 8*messageBytes)
	}
	for _, cl := range []*imapclient.Client{a, b, c} {
		cl.Logout().Wait()
		cl.Close()
	}
	if !waitFor(func() bool { return budget.Used() == 0 }) {
		t.Errorf("%d bytes still reserved after every connection closed", budget.Used())
	}
}
