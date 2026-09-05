package main

// `relay-sealer birth-report` — the one thing a relay says to its plane on its
// own initiative: "I exist, this is my identity, here is proof I hold it".
//
// Run by the first-boot script as root once the build is done. It reads the
// identity the listener uses, measures the two facts the plane wants to know
// (Postfix up, 443 bound), signs the report with the identity key, and either
// writes it to a file or posts it to the plane with the one-time run token.
// The token rides in a header and is read from a file, never from the command
// line, so it never appears in a process listing or the console log.
//
// The plane does not believe the report on the token alone: it checks that the
// report came FROM the address the provider gave and then dials that address
// with the reported fingerprint pinned before it trusts the pin. So nothing
// here needs to be secret except the token, and the token dies with the boot.

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

func runBirthReport() int {
	fs := flag.NewFlagSet("birth-report", flag.ContinueOnError)
	home := fs.String("home", envOr("JOINERY_RELAY_HOME", "/opt/joinery-relay"), "relay home")
	runID := fs.String("run-id", "", "the plane's run id (required)")
	publicIP := fs.String("public-ip", "", "this relay's public IPv4 (default: detected)")
	out := fs.String("out", "", "write the signed report here")
	plane := fs.String("post", "", "plane URL to post to, e.g. https://example.com")
	tokenFile := fs.String("token-file", "", "file holding the one-time run token (with --post)")
	if err := fs.Parse(os.Args[2:]); err != nil {
		return 2
	}
	if strings.TrimSpace(*runID) == "" {
		fmt.Fprintln(os.Stderr, "relay-sealer birth-report: --run-id is required")
		return 2
	}
	if *out == "" && *plane == "" {
		fmt.Fprintln(os.Stderr, "relay-sealer birth-report: give --out, --post, or both")
		return 2
	}

	paths := relayPaths{home: *home}
	identity, err := loadOrCreateIdentity(paths.identityDir())
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer birth-report: %v\n", err)
		return 1
	}

	ip := strings.TrimSpace(*publicIP)
	if ip == "" {
		ip = detectPublicIPv4()
	}
	report := relayBirthReport{
		RunID:               strings.TrimSpace(*runID),
		PublicIP:            ip,
		IdentityPublicKey:   identity.publicKeyB64(),
		IdentityFingerprint: identity.fingerprint,
		RelayVersion:        readTrimmed(filepath.Join(*home, "version")),
		Postfix:             postfixState(),
		Listener443:         listenerState(443),
	}
	message, err := relayBirthSigningBytes(report)
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer birth-report: %v\n", err)
		return 1
	}
	signed := relaySignedBirthReport{Report: report, Signature: identity.sign(message)}
	body, err := json.Marshal(signed)
	if err != nil {
		return 1
	}

	if *out != "" {
		if err := writeFileAtomic(*out, append(body, '\n'), 0o644); err != nil {
			fmt.Fprintf(os.Stderr, "relay-sealer birth-report: write %s: %v\n", *out, err)
			return 1
		}
		fmt.Printf("BIRTH_REPORT_WRITTEN %s\n", *out)
	}
	if *plane == "" {
		return 0
	}

	token := ""
	if *tokenFile != "" {
		raw, err := os.ReadFile(*tokenFile)
		if err != nil {
			fmt.Fprintf(os.Stderr, "relay-sealer birth-report: token file: %v\n", err)
			return 1
		}
		token = strings.TrimSpace(string(raw))
	}
	if token == "" {
		fmt.Fprintln(os.Stderr, "relay-sealer birth-report: --post needs --token-file with a non-empty token")
		return 1
	}
	url := strings.TrimRight(strings.TrimSpace(*plane), "/") + "/api/v1/relay/born"
	req, err := http.NewRequest(http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return 1
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set(relayRunTokenHeader, token)
	req.Header.Set("User-Agent", "Joinery/Relay (birth)")
	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer birth-report: post: %v\n", err)
		return 1
	}
	defer resp.Body.Close()
	answer, _ := io.ReadAll(io.LimitReader(resp.Body, 64*1024))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		fmt.Fprintf(os.Stderr, "relay-sealer birth-report: plane answered HTTP %d: %s\n",
			resp.StatusCode, strings.TrimSpace(string(answer)))
		return 1
	}
	fmt.Printf("BIRTH_REPORT_ACCEPTED %s\n", strings.TrimSpace(string(answer)))
	return 0
}

// detectPublicIPv4 is the source address the kernel would use to reach the
// internet — the interface address, not a lookup at a third party. The plane
// compares it against what the provider said and against the connection's
// source, so a wrong guess here is refused, not believed.
func detectPublicIPv4() string {
	conn, err := net.Dial("udp4", "1.1.1.1:53")
	if err != nil {
		return ""
	}
	defer conn.Close()
	if addr, ok := conn.LocalAddr().(*net.UDPAddr); ok {
		return addr.IP.String()
	}
	return ""
}

func postfixState() string {
	st := systemdUnitStatus("postfix")
	if st.Active == "active" {
		return "ok"
	}
	if st.Active == "" {
		return "unknown"
	}
	return st.Active
}

func listenerState(port int) string {
	if portListening(port) {
		return "ok"
	}
	return "unbound"
}
