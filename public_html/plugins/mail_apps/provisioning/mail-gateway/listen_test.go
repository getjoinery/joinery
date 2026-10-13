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
