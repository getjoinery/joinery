package main

import (
	"net"
	"testing"
	"time"
)

// A wildcard listen address must take both IPv4 and IPv6 connections: the
// relay reaches the gateway on the server's IPv4, mail programs may arrive on
// either, and the helper and the admin page check 127.0.0.1.
func TestListenDualTakesIPv4AndIPv6(t *testing.T) {
	ln, err := listenDual(":0")
	if err != nil {
		t.Fatalf("listenDual: %v", err)
	}
	defer ln.Close()
	_, port, _ := net.SplitHostPort(ln.Addr().String())
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			c.Close()
		}
	}()
	for _, host := range []string{"127.0.0.1", "::1"} {
		c, err := net.DialTimeout("tcp", net.JoinHostPort(host, port), 2*time.Second)
		if err != nil {
			if host == "::1" {
				if probe, perr := net.Listen("tcp6", "[::1]:0"); perr != nil {
					t.Logf("no IPv6 loopback here (%v); IPv6 not checked", perr)
					continue
				} else {
					probe.Close()
				}
			}
			t.Fatalf("connect to %s on the wildcard listener: %v", host, err)
		}
		c.Close()
	}
}

// Submission answers on both its own port and the relay's: a relay cannot
// dial 465 onward (cloud providers block outbound mail ports), so it reaches
// submission on the second address, which must be the same server.
func TestListenSubmissionServesBothPorts(t *testing.T) {
	ln, err := listenSubmission("127.0.0.1:0", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listenSubmission: %v", err)
	}
	defer ln.Close()
	m, ok := ln.(*multiListener)
	if !ok || len(m.lns) != 2 {
		t.Fatalf("want one listener over two ports, got %T", ln)
	}
	accepted := make(chan struct{}, 2)
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			c.Close()
			accepted <- struct{}{}
		}
	}()
	for _, sub := range m.lns {
		c, err := net.DialTimeout("tcp", sub.Addr().String(), 2*time.Second)
		if err != nil {
			t.Fatalf("connect to %s: %v", sub.Addr(), err)
		}
		c.Close()
		select {
		case <-accepted:
		case <-time.After(2 * time.Second):
			t.Fatalf("%s was not accepted by the submission listener", sub.Addr())
		}
	}
	if _, err := listenSubmission("127.0.0.1:0", ""); err != nil {
		t.Fatalf("an empty relay address must leave submission on its own port: %v", err)
	}
	if RelaySubmissionPort == "465" || RelaySubmissionPort == "587" || RelaySubmissionPort == "25" {
		t.Fatalf("the relay's submission port must not be one cloud providers block outbound: %s", RelaySubmissionPort)
	}
}
