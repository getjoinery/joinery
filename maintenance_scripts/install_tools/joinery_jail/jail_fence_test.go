package main

// The fence applied for real, by an ordinary user: Landlock and the seccomp
// allowlist need no privilege, so every row of the tables in
// specs/sandboxed_php_plugins.md design 2 that does not depend on the user
// switch runs here, on every `go test`, with real PHP making raw system calls
// through FFI. The installed setuid launcher is checked from the outside by
// tests/security/parser_jail_gate.sh.

import (
	"bufio"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

// fenceEnv carries the run's options to the re-executed test binary.
const fenceEnv = "JJ_TEST_FENCE"
const supervisorEnv = "JJ_TEST_SUPERVISOR"

type fenceOpts struct {
	Reads   []string `json:"reads"`
	ReadPHP []string `json:"read_php"`
	Scratch string   `json:"scratch"`
	AS      uint64   `json:"as"`
	Argv    []string `json:"argv"`
}

// TestMain doubles as the fenced child and as a stand-in supervisor when the
// test re-executes its own binary.
func TestMain(m *testing.M) {
	if os.Getenv(supervisorEnv) != "" {
		standInSupervisor()
		return
	}
	if raw := os.Getenv(fenceEnv); raw != "" {
		fencedChild(raw)
		return
	}
	os.Exit(m.Run())
}

// fencedChild is stage 2 without the user switch: the same ruleset, the same
// death signal, the same filter, the same cap and exec.
func fencedChild(raw string) {
	var f fenceOpts
	if err := json.Unmarshal([]byte(raw), &f); err != nil {
		fail(exitUsage, "bad test options: %v", err)
	}
	supervisor := os.Getppid()
	o := options{reads: f.Reads, readPHP: f.ReadPHP, argv: f.Argv, rlimitAS: f.AS}
	if f.Scratch != "" {
		o.scratch = 1 << 20
	}
	realCmd, interp, ok := resolveCommand(f.Argv[0])
	if !ok {
		fail(exitUsage, "not a runnable program: %s", f.Argv[0])
	}
	rs, env, err := buildRuleset(o, realCmd, interp, f.Scratch, os.Getuid())
	if err != nil {
		fail(exitUsage, "%v", err)
	}
	argv0, _ := syscall.BytePtrFromString(realCmd)
	argvp, _ := syscall.SlicePtrFromStrings(f.Argv)
	envp, _ := syscall.SlicePtrFromStrings(env)
	tieToSupervisor(supervisor)
	closeFence(rs)
	capAndExec(o.rlimitAS, realCmd, argv0, argvp, envp)
}

// standInSupervisor starts one fenced child, prints its pid, and waits to be
// killed: what a supervisor killed from outside looks like to its child.
func standInSupervisor() {
	cmd := exec.Command(os.Args[0])
	cmd.Env = []string{fenceEnv + "=" + os.Getenv(supervisorEnv)}
	if err := cmd.Start(); err != nil {
		fail(1, "%v", err)
	}
	os.Stdout.WriteString(strconv.Itoa(cmd.Process.Pid) + "\n")
	time.Sleep(60 * time.Second)
}

func needFence(t *testing.T) string {
	t.Helper()
	if landlockABI() < minLandlockABI {
		t.Skipf("Landlock ABI %d here; the fence needs %d", landlockABI(), minLandlockABI)
	}
	php, err := exec.LookPath("php")
	if err != nil {
		t.Skip("no php")
	}
	return php
}

func runFenced(t *testing.T, f fenceOpts) (string, error) {
	t.Helper()
	raw, _ := json.Marshal(f)
	cmd := exec.Command(os.Args[0])
	cmd.Env = []string{fenceEnv + "=" + string(raw)}
	out, err := cmd.CombinedOutput()
	return string(out), err
}

func writeFile(t *testing.T, p, body string, mode os.FileMode) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(p), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(body), mode); err != nil {
		t.Fatal(err)
	}
}

func TestFenceRefusesEveryRow(t *testing.T) {
	php := needFence(t)
	dir := t.TempDir()
	secret := filepath.Join(dir, "outside", "secret.php")
	owned := filepath.Join(dir, "outside", "owned.txt")
	writeFile(t, secret, "<?php $db_password = 'x';", 0600)
	writeFile(t, owned, "mine", 0600)
	readdir := filepath.Join(dir, "granted")
	writeFile(t, filepath.Join(readdir, "ok.txt"), "ok", 0644)
	if err := os.Symlink(secret, filepath.Join(readdir, "link_to_secret")); err != nil {
		t.Fatal(err)
	}
	phpdir := filepath.Join(dir, "includes")
	writeFile(t, filepath.Join(phpdir, "code.php"), "<?php", 0644)
	writeFile(t, filepath.Join(phpdir, "key.txt"), "a key beside the code", 0644)

	b := make([]byte, 6)
	_, _ = rand.Read(b)
	scratch := scratchParent + "/" + scratchPrefix + "test" + hex.EncodeToString(b)
	if err := os.Mkdir(scratch, 0700); err != nil {
		t.Fatal(err)
	}
	defer os.RemoveAll(scratch)

	helper := exec.Command("sleep", "60")
	if err := helper.Start(); err != nil {
		t.Fatal(err)
	}
	defer helper.Process.Kill()

	probe, _ := filepath.Abs("testdata/fence_probe.php")
	cfg, _ := json.Marshal(map[string]interface{}{
		"secret": secret, "owned": owned, "readdir": readdir, "phpdir": phpdir,
		"scratch": scratch, "helper": helper.Process.Pid,
	})
	out, err := runFenced(t, fenceOpts{
		Reads:   []string{probe, readdir},
		ReadPHP: []string{phpdir},
		Scratch: scratch,
		Argv: []string{php, "-n", "-d", "extension=ffi", "-d", "ffi.enable=1", "-d", "extension=mbstring",
			"-d", "extension=dom", "-d", "display_errors=stderr", probe, string(cfg)},
	})
	if err != nil {
		t.Fatalf("fenced probe failed: %v\n%s", err, out)
	}

	reachable := map[string]bool{
		"read a granted file":                              true,
		"read a .php file under a php grant":               true,
		"list a php grant folder (names only)":             true,
		"write the run scratch folder":                     true,
		"read own limits (prlimit pid 0)":                  true,
		"signal itself (signal 0)":                         true,
		"fcntl F_GETFL (allowed)":                          true,
		"stat the secret file (accepted: names and sizes)": true,
		"php://temp in memory":                             true,
		"time zones":                                       true,
		"mbstring and dom":                                 true,
		"done":                                             true,
	}
	seen := 0
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		parts := strings.SplitN(sc.Text(), "\t", 2)
		if len(parts) != 2 {
			continue
		}
		seen++
		name, got := parts[0], parts[1]
		want := "refused"
		if reachable[name] {
			want = "reachable"
		}
		if !strings.HasPrefix(got, want) {
			t.Errorf("%-55s got %s, want %s", name, got, want)
		}
	}
	if os.Getenv("JJ_SHOW") != "" {
		t.Log("\n" + out)
	}
	if seen < 40 || !strings.Contains(out, "done\treachable") {
		t.Fatalf("the probe did not run to the end (%d rows):\n%s", seen, out)
	}
}

// A grant that is a link, or passes through one, is refused before anything
// runs: a plugin's src/ swapped for a link to config/ would otherwise be
// granted.
func TestGrantThroughALinkIsRefused(t *testing.T) {
	needFence(t)
	dir := t.TempDir()
	real := filepath.Join(dir, "config")
	writeFile(t, filepath.Join(real, "Globalvars_site.php"), "<?php", 0600)
	if err := os.Symlink(real, filepath.Join(dir, "src")); err != nil {
		t.Fatal(err)
	}
	linkedParent := filepath.Join(dir, "plugins")
	if err := os.Symlink(dir, linkedParent); err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{filepath.Join(dir, "src"), filepath.Join(linkedParent, "config"), real + "/", "relative/src", "/"} {
		o := options{reads: []string{p}}
		if _, _, err := buildRuleset(o, "/bin/true", "", "", os.Getuid()); err == nil {
			t.Errorf("grant %s must be refused", p)
		}
		o = options{readPHP: []string{p}}
		if _, _, err := buildRuleset(o, "/bin/true", "", "", os.Getuid()); err == nil {
			t.Errorf("php grant %s must be refused", p)
		}
	}
	if _, _, err := buildRuleset(options{reads: []string{real}}, "/bin/true", "", "", os.Getuid()); err != nil {
		t.Errorf("a plain folder must be grantable: %v", err)
	}
}

// A killed supervisor takes the fenced run with it.
func TestKilledSupervisorKillsTheRun(t *testing.T) {
	php := needFence(t)
	raw, _ := json.Marshal(fenceOpts{Argv: []string{php, "-n", "-r", "sleep(30);"}})
	sup := exec.Command(os.Args[0])
	sup.Env = []string{supervisorEnv + "=" + string(raw)}
	stdout, _ := sup.StdoutPipe()
	if err := sup.Start(); err != nil {
		t.Fatal(err)
	}
	line, err := bufio.NewReader(stdout).ReadString('\n')
	if err != nil {
		t.Fatal(err)
	}
	child, _ := strconv.Atoi(strings.TrimSpace(line))
	time.Sleep(500 * time.Millisecond) // let the child reach PHP
	if syscall.Kill(child, 0) != nil {
		t.Fatal("the fenced run never started")
	}
	_ = sup.Process.Kill()
	_, _ = sup.Process.Wait()
	for i := 0; i < 40; i++ {
		if b, err := os.ReadFile("/proc/" + strconv.Itoa(child) + "/stat"); err != nil || strings.Contains(string(b), ") Z ") {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	_ = syscall.Kill(child, syscall.SIGKILL)
	t.Fatal("the fenced run outlived its supervisor")
}

// The address-space cap is set by the launcher itself, just before the exec.
func TestAddressSpaceCap(t *testing.T) {
	php := needFence(t)
	out, err := runFenced(t, fenceOpts{AS: 256 << 20, Argv: []string{php, "-n", "-d", "memory_limit=-1", "-r", `$a = str_repeat("x", 400 * 1024 * 1024); echo strlen($a);`}})
	if err == nil {
		t.Fatalf("a 400 MB string under a 256 MB cap must fail, got %q", out)
	}
	out, err = runFenced(t, fenceOpts{AS: 256 << 20, Argv: []string{php, "-n", "-r", `echo strlen(str_repeat("x", 1 << 20));`}})
	if err != nil || strings.TrimSpace(out) != "1048576" {
		t.Fatalf("a small run under the cap must work: %v %q", err, out)
	}
}
