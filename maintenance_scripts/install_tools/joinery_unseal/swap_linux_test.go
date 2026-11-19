package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// swapCase is one case of testdata/swap_rule_cases.json, the corpus every
// copy of the swap rule walks.
type swapCase struct {
	Name      string            `json:"name"`
	Swaps     [][2]string       `json:"swaps"`
	Block     map[string]string `json:"block"`
	BlockDirs []string          `json:"block_dirs"`
	Files     []string          `json:"files"`
	RAMOnly   bool              `json:"ram_only"`
}

func loadSwapCases(t *testing.T) []swapCase {
	t.Helper()
	body, err := os.ReadFile("testdata/swap_rule_cases.json")
	if err != nil {
		t.Fatal(err)
	}
	var corpus struct {
		Cases []swapCase `json:"cases"`
	}
	if err := json.Unmarshal(body, &corpus); err != nil {
		t.Fatal(err)
	}
	if len(corpus.Cases) < 10 {
		t.Fatalf("the corpus has %d cases", len(corpus.Cases))
	}
	return corpus.Cases
}

// buildSwapFixture lays one case out under a fresh directory and returns its
// /proc/swaps and /sys/block.
func buildSwapFixture(t *testing.T, c swapCase) (string, string) {
	t.Helper()
	dir := t.TempDir()
	at := func(s string) string { return strings.ReplaceAll(s, "@DIR@", dir) }
	sysBlock := filepath.Join(dir, "block")
	os.MkdirAll(sysBlock, 0o755)
	for rel, body := range c.Block {
		p := filepath.Join(sysBlock, rel)
		os.MkdirAll(filepath.Dir(p), 0o755)
		os.WriteFile(p, []byte(body), 0o644)
	}
	for _, d := range c.BlockDirs {
		os.MkdirAll(filepath.Join(sysBlock, d), 0o755)
	}
	for _, f := range c.Files {
		os.MkdirAll(filepath.Dir(at(f)), 0o755)
		os.WriteFile(at(f), nil, 0o644)
	}
	swaps := "Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n"
	for _, row := range c.Swaps {
		swaps += at(row[0]) + "                              " + row[1] + "\t2097148\t\t0\t\t-2\n"
	}
	procSwaps := filepath.Join(dir, "swaps")
	os.WriteFile(procSwaps, []byte(swaps), 0o644)
	return procSwaps, sysBlock
}

func TestSwapRuleCorpus(t *testing.T) {
	for _, c := range loadSwapCases(t) {
		procSwaps, sysBlock := buildSwapFixture(t, c)
		ok, why := swapRAMOnly(procSwaps, sysBlock)
		if ok != c.RAMOnly {
			t.Errorf("%s: got ram_only=%v (%s), want %v", c.Name, ok, why, c.RAMOnly)
		}
		if !ok && why == "" {
			t.Errorf("%s: a disk verdict must say why", c.Name)
		}
	}
	if ok, _ := swapRAMOnly(filepath.Join(t.TempDir(), "absent"), t.TempDir()); ok {
		t.Error("an unreadable /proc/swaps must count as a disk")
	}
}

func TestSwapcheckExit(t *testing.T) {
	cases := loadSwapCases(t)
	procSwaps, sysBlock := buildSwapFixture(t, cases[0]) // no swap
	if rc := runSwapcheck([]string{procSwaps, sysBlock}); rc != 0 {
		t.Errorf("no swap: exit %d, want 0", rc)
	}
	procSwaps, sysBlock = buildSwapFixture(t, swapCase{Swaps: [][2]string{{"/swapfile", "file"}}})
	if rc := runSwapcheck([]string{procSwaps, sysBlock}); rc != exitDiskSwap {
		t.Errorf("a swap file: exit %d, want %d", rc, exitDiskSwap)
	}
	if rc := runSwapcheck([]string{"one"}); rc != 2 {
		t.Errorf("a bad argument count: exit %d, want 2", rc)
	}
}
